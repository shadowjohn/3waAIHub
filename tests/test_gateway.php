<?php
declare(strict_types=1);

hub_test('hello gateway and unknown mode keep expected contract', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);

    $hello = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, [
        'ok' => true,
        'service' => 'hello',
        'message' => '3waAIHub service is running',
    ]));
    hub_test_assert($hello['status'] === 200, 'hello did not return 200');
    hub_test_assert(str_contains($hello['body'], '"ok":true'), 'hello body missing ok');

    $unknown = hub_gateway_dispatch($db, 'not_exists');
    hub_test_assert($unknown['status'] === 404, 'unknown mode did not return 404');
});

hub_test('gateway applies manifest upload limit and timeout', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'translate-gemma12b', ['idempotent' => true]);
    hub_set_service_enabled($db, 'translate', true);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=translate';
    $_SERVER['CONTENT_LENGTH'] = (string)(3 * 1024 * 1024);

    $oversize = hub_gateway_dispatch($db, 'translate', static fn (): array => throw new RuntimeException('oversize request must not reach service'));
    hub_test_assert($oversize['status'] === 413, 'oversize request must return 413');
    hub_test_assert(str_contains($oversize['body'], 'payload_too_large'), 'oversize response must name payload_too_large');

    unset($_SERVER['CONTENT_LENGTH']);
    $response = hub_gateway_dispatch($db, 'translate', static function (array $service, int $timeoutSec): array {
        hub_test_assert($service['mode'] === 'translate', 'requester must receive service');
        hub_test_assert($timeoutSec === 180, 'translate gateway timeout must come from manifest');

        return hub_gateway_json(200, ['ok' => true]);
    });
    hub_test_assert($response['status'] === 200, 'translate request should pass after content length is acceptable');
});

hub_test('task_cancel API requests cooperative cancel for running DocParser tasks', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, ['input_file' => HUB_DATA_DIR . '/uploads/tasks/task_1/input.pdf'], null, '127.0.0.1');
    hub_claim_next_task($db);

    $serverBackup = $_SERVER;
    $getBackup = $_GET;
    $postBackup = $_POST;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['task_id' => (string)$taskId];
        $_POST = [];

        $cancel = hub_gateway_dispatch($db, 'task_cancel');
        $cancelPayload = json_decode((string)$cancel['body'], true);
        hub_test_assert($cancel['status'] === 200, 'running DocParser cancel must return 200');
        hub_test_assert(($cancelPayload['status'] ?? '') === 'running', 'running DocParser cancel must keep status running until checkpoint');
        hub_test_assert(($cancelPayload['cancel_requested'] ?? false) === true, 'running DocParser cancel must return cancel_requested=true');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $status = hub_gateway_dispatch($db, 'task_status');
        $statusPayload = json_decode((string)$status['body'], true);
        hub_test_assert(($statusPayload['cancel_requested'] ?? false) === true, 'task_status must expose cancel_requested');
    } finally {
        $_SERVER = $serverBackup;
        $_GET = $getBackup;
        $_POST = $postBackup;
    }
});

hub_test('legacy ASR and TTS sync requests require the bounded diagnostic path', function (): void {
    $db = hub_test_reset_db();
    foreach ([['whisper-asr', 'asr'], ['tts-voxcpm2', 'tts']] as [$packId, $mode]) {
        $installed = hub_install_pack($db, $packId, ['idempotent' => true]);
        $service = $installed['service'];
        hub_set_service_enabled($db, $mode, true);
        hub_update_service_status($db, (int)$service['id'], 'running');
    }

    $serverBackup = $_SERVER;
    $getBackup = $_GET;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    $longAudio = tempnam(sys_get_temp_dir(), 'hub-sync-audio-');
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=asr';
        $_SERVER['CONTENT_LENGTH'] = '248044';
        $_POST = [];
        file_put_contents($longAudio, 'RIFF' . pack('V', 248036) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', 248000) . str_repeat("\x80", 248000));
        $_FILES = ['audio' => ['name' => 'long.wav', 'type' => 'audio/wav', 'tmp_name' => $longAudio, 'error' => UPLOAD_ERR_OK, 'size' => 248044]];

        $beforeTasks = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $duration = hub_gateway_dispatch($db, 'asr', static fn (): array => throw new RuntimeException('overlong sync audio must not proxy'));
        $durationPayload = json_decode((string)$duration['body'], true);
        hub_test_assert($duration['status'] === 413 && ($durationPayload['error'] ?? '') === 'async_required' && str_contains((string)($durationPayload['message'] ?? ''), 'speech_transcribe'), 'overlong ASR must require speech_transcribe');

        $_FILES = [];
        $_POST = ['callback_target' => 'myai'];
        $_SERVER['CONTENT_LENGTH'] = '0';
        $callback = hub_gateway_dispatch($db, 'tts', static fn (): array => throw new RuntimeException('sync callback must not proxy'));
        hub_test_assert($callback['status'] === 400 && (json_decode((string)$callback['body'], true)['error'] ?? '') === 'async_required', 'sync callback must require voice_generate');

        $_POST = ['source_artifact_id' => '99'];
        $chained = hub_gateway_dispatch($db, 'tts', static fn (): array => throw new RuntimeException('sync artifact chaining must not proxy'));
        hub_test_assert($chained['status'] === 400 && (json_decode((string)$chained['body'], true)['error'] ?? '') === 'async_required', 'sync artifact chaining must require voice_generate');

        $_POST = [];
        $_SERVER['CONTENT_LENGTH'] = (string)(3 * 1024 * 1024);
        $oversized = hub_gateway_dispatch($db, 'tts', static fn (): array => throw new RuntimeException('oversized sync request must not proxy'));
        $oversizedPayload = json_decode((string)$oversized['body'], true);
        hub_test_assert($oversized['status'] === 413 && ($oversizedPayload['error'] ?? '') === 'async_required' && str_contains((string)($oversizedPayload['message'] ?? ''), 'voice_generate'), 'oversized TTS must require voice_generate');

        $asrService = hub_get_service_by_mode($db, 'asr');
        hub_update_service_settings($db, (int)$asrService['id'], ['WHISPER_REAL_INFERENCE' => '1']);
        $now = hub_now();
        $busyRun = ['run_id' => 'sync-busy-' . bin2hex(random_bytes(6)), 'worker_id' => 'sync-busy-worker', 'lease_token' => bin2hex(random_bytes(32))];
        $db->prepare(
            'INSERT INTO runtime_runs (run_id, pack_id, task, workspace, state, worker_id, lease_token, lease_expires_at, started_at, created_at)
             VALUES (:run_id, :pack_id, :task, :workspace, :state, :worker_id, :lease_token, :lease_expires_at, :started_at, :created_at)'
        )->execute([
            ':run_id' => $busyRun['run_id'], ':pack_id' => 'sync-test', ':task' => 'sync', ':workspace' => sys_get_temp_dir(), ':state' => 'claimed',
            ':worker_id' => $busyRun['worker_id'], ':lease_token' => $busyRun['lease_token'], ':lease_expires_at' => hub_runtime_lease_until(60), ':started_at' => $now, ':created_at' => $now,
        ]);
        hub_test_assert(hub_runtime_gpu_acquire($db, $busyRun, 60) !== null, 'busy fixture must reserve gpu:0');
        $_SERVER['CONTENT_LENGTH'] = '0';
        $busy = hub_gateway_dispatch($db, 'asr', static fn (): array => throw new RuntimeException('busy sync request must not proxy'));
        hub_test_assert($busy['status'] === 409 && (json_decode((string)$busy['body'], true)['error'] ?? '') === 'sync_busy', 'occupied gpu:0 must return sync_busy');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $beforeTasks, 'sync requests must never silently create tasks');
    } finally {
        if (is_string($longAudio) && is_file($longAudio)) {
            unlink($longAudio);
        }
        $_SERVER = $serverBackup;
        $_GET = $getBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
    }
});
