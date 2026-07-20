<?php
declare(strict_types=1);

function hub_test_audio_payload(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    hub_test_assert(is_array($payload), 'audio gateway response must be JSON');

    return $payload;
}

function hub_test_audio_request(PDO $db, string $mode, string $token, array $post = [], array $get = [], array $files = [], string $method = 'POST'): array
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.51';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=' . $mode;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_SERVER['HTTP_HOST'] = 'hub.test';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/api.php';
    unset($_SERVER['CONTENT_LENGTH']);
    $_POST = $post;
    $_GET = $get;
    $_FILES = $files;

    return hub_gateway_dispatch($db, $mode);
}

function hub_test_audio_allow(PDO $db, array $tokens, array $modes): void
{
    foreach ($tokens as $token) {
        foreach ($modes as $mode) {
            hub_add_api_token_mode_permission($db, (int)$token['token_id'], $mode, null);
        }
    }
}

function hub_test_audio_isolate(callable $fn): void
{
    $server = $_SERVER;
    $get = $_GET;
    $post = $_POST;
    $files = $_FILES;
    try {
        $fn();
    } finally {
        $_SERVER = $server;
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
    }
}

function hub_test_audio_source_artifact(PDO $db, int $memberId, int $tokenId, string $artifactType = 'audio', string $state = 'available', ?string $expiresAt = null, ?string $purgedAt = null, ?string $path = null): array
{
    $sourceTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
        'owner_token_id' => $tokenId,
    ]);
    $path ??= hub_task_result_dir($sourceTaskId) . '/source.wav';
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create audio source artifact directory.');
    }
    if (file_put_contents($path, 'RIFFaudio', LOCK_EX) === false) {
        throw new RuntimeException('Cannot write audio source artifact.');
    }
    $artifactId = hub_register_task_artifact($db, $sourceTaskId, basename($path), $path, 'audio/wav');
    $db->prepare(
        'UPDATE task_artifacts
         SET artifact_type = :artifact_type, state = :state, expires_at = :expires_at, purged_at = :purged_at
         WHERE id = :id'
    )->execute([
        ':artifact_type' => $artifactType,
        ':state' => $state,
        ':expires_at' => $expiresAt,
        ':purged_at' => $purgedAt,
        ':id' => $artifactId,
    ]);

    return ['task_id' => $sourceTaskId, 'artifact_id' => $artifactId, 'path' => $path];
}

function hub_test_audio_hold_released(PDO $db, int $artifactId, int $taskId): bool
{
    $stmt = $db->prepare(
        'SELECT released_at FROM task_artifact_holds
         WHERE source_artifact_id = :source_artifact_id AND downstream_task_id = :downstream_task_id'
    );
    $stmt->execute([':source_artifact_id' => $artifactId, ':downstream_task_id' => $taskId]);

    return !empty(($stmt->fetch() ?: [])['released_at']);
}

hub_test('audio async routes are fixed and resolve installed Pack versions', function (): void {
    hub_test_audio_isolate(static function (): void {
    $db = hub_test_reset_db();
    $routes = hub_audio_async_routes();
    hub_test_assert($routes === [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'],
    ], 'audio route map must not be client-configurable');

    $whisper = hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $tts = hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    $asrRoute = hub_resolve_audio_async_route($db, 'speech_transcribe');
    $ttsRoute = hub_resolve_audio_async_route($db, 'voice_generate');
    foreach ([
        [$asrRoute, 'speech_transcribe', 'whisper-asr', (string)$whisper['service']['pack_version'], 'transcribe'],
        [$ttsRoute, 'voice_generate', 'tts-voxcpm2', (string)$tts['service']['pack_version'], 'synthesize'],
    ] as [$route, $mode, $packId, $packVersion, $job]) {
        hub_test_assert(($route['requested_mode'] ?? '') === $mode, 'requested public mode must persist');
        hub_test_assert(($route['pack_id'] ?? '') === $packId && ($route['pack_version'] ?? '') === $packVersion && ($route['job'] ?? '') === $job, 'installed Pack route snapshot mismatch');
        hub_test_assert(($route['runtime_mode'] ?? '') === 'job' && ($route['accelerator'] ?? '') === 'gpu' && !empty($route['route_resolved_at']), 'audio route runtime snapshot mismatch');
    }

    $memberId = hub_create_api_member($db, 'Audio Route Client');
    $token = hub_create_api_token($db, $memberId, 'audio route token', null, null);
    hub_test_audio_allow($db, [$token], ['audio_cleanup']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
    $missing = hub_test_audio_request($db, 'audio_cleanup', (string)$token['plain_token'], ['source_artifact_id' => '1']);
        hub_test_assert($missing['status'] === 503 && (hub_test_audio_payload($missing)['error'] ?? '') === 'pack_not_installed', 'uninstalled audio Pack must fail safely');
    });
});

hub_test('audio async admission rejects controls and persists the managed route snapshot', function (): void {
    hub_test_audio_isolate(static function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $memberId = hub_create_api_member($db, 'Audio Submit Owner');
    $token = hub_create_api_token($db, $memberId, 'audio submit token', null, null);
    hub_test_audio_allow($db, [$token], ['speech_transcribe']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

    $upload = tempnam(sys_get_temp_dir(), '3waaihub_audio_');
    if ($upload === false || file_put_contents($upload, 'RIFFaudio', LOCK_EX) === false) {
        throw new RuntimeException('Cannot create managed upload fixture.');
    }
    try {
        $file = [
            'name' => 'voice.wav',
            'type' => 'audio/wav',
            'tmp_name' => $upload,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($upload),
        ];
        $forbidden = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token'], ['pack_version' => 'other'], [], ['file' => $file]);
        hub_test_assert($forbidden['status'] === 400 && (hub_test_audio_payload($forbidden)['error'] ?? '') === 'forbidden_task_control', 'client Pack controls must be rejected, not ignored');

        $created = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token'], [], [], ['file' => $file]);
        $payload = hub_test_audio_payload($created);
        hub_test_assert($created['status'] === 200 && !empty($payload['task_id']), 'managed upload must create an audio task');
        $task = hub_get_task($db, (int)$payload['task_id']);
        hub_test_assert(($task['task_type'] ?? '') === 'pack_job' && ($task['queue_name'] ?? '') === 'gpu', 'audio work must use the one generic Pack task path');
        hub_test_assert((int)($task['owner_member_id'] ?? 0) === $memberId && (int)($task['owner_token_id'] ?? 0) === (int)$token['token_id'], 'audio task ownership must persist');
        hub_test_assert(($task['requested_mode'] ?? '') === 'speech_transcribe' && ($task['pack_id'] ?? '') === 'whisper-asr' && ($task['job'] ?? '') === 'transcribe' && ($task['runtime_mode'] ?? '') === 'job' && ($task['accelerator'] ?? '') === 'gpu', 'audio route fields must be immutable task columns');
        hub_test_assert(empty($task['source_artifact_id']) && empty($task['source_task_id']) && str_starts_with((string)($task['input']['source_upload_path'] ?? ''), HUB_DATA_DIR . '/uploads/tasks/task_' . (int)$task['id'] . '/'), 'upload source must be copied only into managed storage');

        $none = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token']);
        hub_test_assert($none['status'] === 400 && (hub_test_audio_payload($none)['error'] ?? '') === 'source_required', 'audio task requires one source');
    } finally {
        if (is_file($upload)) {
            unlink($upload);
        }
    }
    });
});

hub_test('public task_submit cannot bypass fixed audio admission controls', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Task Submit Client');
        $token = hub_create_api_token($db, $memberId, 'task submit token', null, null);
        hub_test_audio_allow($db, [$token], ['task_submit']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $packJob = hub_test_audio_request($db, 'task_submit', (string)$token['plain_token'], [
            'task_type' => 'pack_job',
            'pack_id' => 'whisper-asr',
            'command' => 'run anything',
        ]);
        hub_test_assert($packJob['status'] === 400 && (hub_test_audio_payload($packJob)['error'] ?? '') === 'forbidden_task_control', 'task_submit must not create public Pack jobs');

        $controls = hub_test_audio_request($db, 'task_submit', (string)$token['plain_token'], [
            'task_type' => 'demo_task',
            'workdir' => '/tmp',
            'secret' => 'not-a-secret-channel',
        ]);
        hub_test_assert($controls['status'] === 400 && (hub_test_audio_payload($controls)['error'] ?? '') === 'forbidden_task_control', 'task_submit must reject workdir and secret controls');
    });
});

hub_test('audio artifact chaining validates ownership state type and path', function (): void {
    hub_test_audio_isolate(static function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $memberA = hub_create_api_member($db, 'Audio Artifact Owner');
    $tokenA = hub_create_api_token($db, $memberA, 'audio artifact token A', null, null);
    $tokenA2 = hub_create_api_token($db, $memberA, 'audio artifact token A2', null, null);
    $memberB = hub_create_api_member($db, 'Audio Artifact Stranger');
    $tokenB = hub_create_api_token($db, $memberB, 'audio artifact token B', null, null);
    hub_test_audio_allow($db, [$tokenA, $tokenA2, $tokenB], ['speech_transcribe', 'task_status', 'task_result', 'task_log', 'task_cancel', 'task_retry', 'artifact']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

    $source = hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id']);
    $created = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], ['source_artifact_id' => (string)$source['artifact_id']]);
    $createdPayload = hub_test_audio_payload($created);
    $taskId = (int)($createdPayload['task_id'] ?? 0);
    $task = hub_get_task($db, $taskId);
    hub_test_assert($created['status'] === 200 && (int)($task['source_artifact_id'] ?? 0) === $source['artifact_id'] && (int)($task['source_task_id'] ?? 0) === $source['task_id'], 'valid source artifact must preserve lineage');
    $hold = $db->prepare(
        'SELECT * FROM task_artifact_holds
         WHERE source_artifact_id = :source_artifact_id AND downstream_task_id = :downstream_task_id AND released_at IS NULL'
    );
    $hold->execute([':source_artifact_id' => $source['artifact_id'], ':downstream_task_id' => $taskId]);
    hub_test_assert((bool)$hold->fetch(), 'downstream source artifact must receive a durable retention hold');

    $sameMember = hub_test_audio_request($db, 'task_status', (string)$tokenA2['plain_token'], [], ['task_id' => (string)$taskId], [], 'GET');
    hub_test_assert($sameMember['status'] === 200, 'different token for same member must read task');
    foreach (['task_status', 'task_result', 'task_log', 'task_cancel', 'task_retry'] as $mode) {
        $method = in_array($mode, ['task_cancel', 'task_retry'], true) ? 'POST' : 'GET';
        $response = hub_test_audio_request($db, $mode, (string)$tokenB['plain_token'], ['task_id' => (string)$taskId], ['task_id' => (string)$taskId], [], $method);
        hub_test_assert($response['status'] === 404, 'cross-member ' . $mode . ' must not reveal task');
    }
    $artifact = hub_test_audio_request($db, 'artifact', (string)$tokenB['plain_token'], [], ['artifact_id' => (string)$source['artifact_id']], [], 'GET');
    hub_test_assert($artifact['status'] === 404, 'cross-member artifact download must not reveal artifact');

    $invalid = [
        hub_test_audio_source_artifact($db, $memberB, (int)$tokenB['token_id']),
        hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id'], 'audio', 'purged'),
        hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id'], 'audio', 'available', '2000-01-01 00:00:00'),
        hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id'], 'audio', 'available', null, '2020-01-01 00:00:00'),
        hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id'], 'transcript_json'),
    ];
    foreach ($invalid as $index => $bad) {
        $response = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], ['source_artifact_id' => (string)$bad['artifact_id']]);
        hub_test_assert($response['status'] === ($index === 0 ? 404 : 409), 'invalid source artifact must be rejected');
    }
    $outside = tempnam(sys_get_temp_dir(), '3waaihub_outside_');
    if ($outside === false) {
        throw new RuntimeException('Cannot create outside artifact fixture.');
    }
    try {
        $unsafe = hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id']);
        $db->prepare('UPDATE task_artifacts SET path = :path WHERE id = :id')->execute([':path' => $outside, ':id' => $unsafe['artifact_id']]);
        $response = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], ['source_artifact_id' => (string)$unsafe['artifact_id']]);
        hub_test_assert($response['status'] === 409, 'source artifact outside results root must be rejected');
    } finally {
        if (is_file($outside)) {
            unlink($outside);
        }
    }
    });
});

hub_test('audio manual retry creates a linked task without mutating terminal history', function (): void {
    hub_test_audio_isolate(static function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $memberId = hub_create_api_member($db, 'Audio Retry Owner');
    $token = hub_create_api_token($db, $memberId, 'audio retry token', null, null);
    hub_test_audio_allow($db, [$token], ['speech_transcribe', 'task_retry']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

    $source = hub_test_audio_source_artifact($db, $memberId, (int)$token['token_id']);
    $created = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token'], ['source_artifact_id' => (string)$source['artifact_id']]);
    $taskId = (int)(hub_test_audio_payload($created)['task_id'] ?? 0);
    $original = hub_get_task($db, $taskId);
    hub_finish_task_success($db, $original ?? [], ['finished' => true]);
    $before = hub_get_task($db, $taskId);

    $retry = hub_test_audio_request($db, 'task_retry', (string)$token['plain_token'], ['task_id' => (string)$taskId]);
    $retryPayload = hub_test_audio_payload($retry);
    $replacement = hub_get_task($db, (int)($retryPayload['task_id'] ?? 0));
    $after = hub_get_task($db, $taskId);
    hub_test_assert($retry['status'] === 200 && (int)($replacement['retry_of_task_id'] ?? 0) === $taskId, 'manual retry must create a linked new task');
    hub_test_assert((int)($replacement['source_artifact_id'] ?? 0) === $source['artifact_id'] && (int)($replacement['source_task_id'] ?? 0) === $source['task_id'] && ($replacement['status'] ?? '') === 'queued', 'retry must retain a valid source lineage');
    hub_test_assert(($after['status'] ?? '') === 'success' && ($after['finished_at'] ?? '') === ($before['finished_at'] ?? ''), 'manual retry must not mutate terminal history');

    $upload = tempnam(sys_get_temp_dir(), '3waaihub_retry_');
    if ($upload === false || file_put_contents($upload, 'RIFFaudio', LOCK_EX) === false) {
        throw new RuntimeException('Cannot create retry upload fixture.');
    }
    try {
        $uploaded = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token'], [], [], [[
            'name' => 'retry.wav',
            'type' => 'audio/wav',
            'tmp_name' => $upload,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($upload),
        ]]);
        $uploadedTaskId = (int)(hub_test_audio_payload($uploaded)['task_id'] ?? 0);
        hub_finish_task_success($db, hub_get_task($db, $uploadedTaskId) ?? [], ['finished' => true]);
        $uploadedRetry = hub_test_audio_request($db, 'task_retry', (string)$token['plain_token'], ['task_id' => (string)$uploadedTaskId]);
        $uploadedReplacement = hub_get_task($db, (int)(hub_test_audio_payload($uploadedRetry)['task_id'] ?? 0));
    hub_test_assert($uploadedRetry['status'] === 200 && (int)($uploadedReplacement['retry_of_task_id'] ?? 0) === $uploadedTaskId && !empty($uploadedReplacement['input']['source_upload_path']), 'manual retry must retain a managed upload source');
    } finally {
        if (is_file($upload)) {
            unlink($upload);
        }
    }

    $db->prepare('UPDATE services SET pack_version = :pack_version WHERE pack_id = :pack_id')
        ->execute([':pack_version' => 'missing-version', ':pack_id' => 'whisper-asr']);
    $unavailable = hub_test_audio_request($db, 'task_retry', (string)$token['plain_token'], ['task_id' => (string)$taskId]);
    hub_test_assert($unavailable['status'] === 503 && (hub_test_audio_payload($unavailable)['error'] ?? '') === 'pack_version_unavailable', 'manual retry must reject an unavailable saved Pack route');
    hub_test_assert((hub_get_task($db, $taskId)['status'] ?? '') === 'success', 'route retry rejection must not mutate original terminal history');
    });
});

hub_test('reserved task modes win over service modes and keep member ownership', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $reserved = hub_install_pack($db, 'hello', [
            'service_key' => 'reserved-task-status',
            'mode' => 'task_status',
            'idempotent' => true,
        ]);
        hub_set_service_enabled($db, 'task_status', true);
        hub_update_service_status($db, (int)$reserved['service']['id'], 'running');
        $memberA = hub_create_api_member($db, 'Reserved Task Owner');
        $tokenA = hub_create_api_token($db, $memberA, 'reserved owner token', null, null);
        $memberB = hub_create_api_member($db, 'Reserved Task Stranger');
        $tokenB = hub_create_api_token($db, $memberB, 'reserved stranger token', null, null);
        hub_test_audio_allow($db, [$tokenA, $tokenB], ['task_status']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.51', [
            'owner_member_id' => $memberA,
            'owner_token_id' => (int)$tokenA['token_id'],
        ]);

        $response = hub_test_audio_request($db, 'task_status', (string)$tokenB['plain_token'], [], ['task_id' => (string)$taskId], [], 'GET');
        hub_test_assert($response['status'] === 404, 'reserved task_status must not be shadowed by an installed service or reveal another member task');
    });
});

hub_test('owned tasks stay private on localhost while legacy tasks remain trusted-local', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Local Privacy Owner');
        $token = hub_create_api_token($db, $memberId, 'local privacy token', null, null);
        $ownedTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.51', [
            'owner_member_id' => $memberId,
            'owner_token_id' => (int)$token['token_id'],
        ]);
        $legacyTaskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');

        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=task_status';
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $_POST = [];
        $_FILES = [];
        $_GET = ['task_id' => (string)$ownedTaskId];
        hub_test_assert(hub_gateway_dispatch($db, 'task_status')['status'] === 404, 'localhost must not read a member-owned task without its member token');

        $_GET = ['task_id' => (string)$legacyTaskId];
        hub_test_assert(hub_gateway_dispatch($db, 'task_status')['status'] === 200, 'trusted localhost must keep legacy null-owner task access');
    });
});

hub_test('external legacy task submit stores member ownership for status access', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Legacy Submit Owner');
        $token = hub_create_api_token($db, $memberId, 'legacy submit token', null, null);
        hub_test_audio_allow($db, [$token], ['task_submit', 'task_status']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $created = hub_test_audio_request($db, 'task_submit', (string)$token['plain_token'], ['task_type' => 'demo_task', 'name' => 'owned legacy submit']);
        $taskId = (int)(hub_test_audio_payload($created)['task_id'] ?? 0);
        $task = hub_get_task($db, $taskId);
        hub_test_assert((int)($task['owner_member_id'] ?? 0) === $memberId && (int)($task['owner_token_id'] ?? 0) === (int)$token['token_id'], 'token-authenticated legacy task must store owner columns');
        $status = hub_test_audio_request($db, 'task_status', (string)$token['plain_token'], [], ['task_id' => (string)$taskId], [], 'GET');
        hub_test_assert($status['status'] === 200, 'external submitter must be able to read its owned legacy task');
    });
});

hub_test('staged audio upload cannot be claimed before its managed source is published', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Staged Upload Owner');
        $token = hub_create_api_token($db, $memberId, 'staged upload token', null, null);
        $route = hub_resolve_audio_async_route($db, 'speech_transcribe');
        $taskId = hub_stage_owned_pack_job($db, $route, [], $memberId, (int)$token['token_id'], '203.0.113.51');
        hub_test_assert(hub_claim_next_task($db) === null, 'worker must not claim a staged upload task');

        $path = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/input.wav';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create staged upload path.');
        }
        file_put_contents($path, 'RIFFaudio', LOCK_EX);
        hub_update_task_input($db, $taskId, ['source_upload_path' => $path]);
        hub_publish_staged_pack_job($db, $taskId);
        $claimed = hub_claim_next_task($db);
        hub_test_assert((int)($claimed['id'] ?? 0) === $taskId && !empty($claimed['input']['source_upload_path']), 'published upload task must be claimable only with its managed source input');
    });
});

hub_test('audio routes require declared async jobs without changing legacy local jobs', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        $route = hub_resolve_audio_async_route($db, 'speech_transcribe');
        hub_test_assert(($route['input_fields'] ?? null) === [] && ($route['source_artifact_types'] ?? null) === ['audio'], 'async route must derive its input contract from the Pack declaration');
        hub_test_assert(!empty(hub_get_pack('yolo')['manifest']['local_jobs']), 'legacy local_jobs must remain readable without async-job validation');

        $manifestPath = HUB_ROOT . '/packs/whisper-asr/pack.json';
        $original = (string)file_get_contents($manifestPath);
        $manifest = json_decode($original, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Cannot decode Whisper manifest fixture.');
        }
        unset($manifest['async_jobs']);
        try {
            file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL, LOCK_EX);
            $error = '';
            try {
                hub_resolve_audio_async_route($db, 'speech_transcribe');
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
            hub_test_assert($error === 'pack_version_unavailable', 'undeclared async job must not route');
        } finally {
            file_put_contents($manifestPath, $original, LOCK_EX);
        }
    });
});

hub_test('source artifact holds release only when downstream tasks terminalize', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Hold Release Owner');
        $token = hub_create_api_token($db, $memberId, 'hold release token', null, null);
        $route = hub_resolve_audio_async_route($db, 'speech_transcribe');
        $terminalize = [
            'success' => static fn (PDO $db, array $task): bool => (hub_finish_task_success($db, $task, ['ok' => true]) === null),
            'failed' => static fn (PDO $db, array $task): bool => (hub_finish_task_failed($db, $task, 'failed') === null),
            'cancelled' => static fn (PDO $db, array $task): bool => (hub_finish_task_cancelled($db, $task, 'cancelled') === null),
            'timed_out' => static fn (PDO $db, array $task): bool => (hub_finish_task_timed_out($db, $task, 'timed out') === null),
            'queued_cancel' => static fn (PDO $db, array $task): bool => hub_cancel_task($db, (int)$task['id']),
        ];
        foreach ($terminalize as $name => $finish) {
            $source = hub_test_audio_source_artifact($db, $memberId, (int)$token['token_id']);
            $taskId = hub_enqueue_owned_pack_job($db, $route, [], $memberId, (int)$token['token_id'], '203.0.113.51', [
                'source_artifact_id' => $source['artifact_id'],
                'source_task_id' => $source['task_id'],
            ]);
            hub_test_assert(!hub_test_audio_hold_released($db, (int)$source['artifact_id'], $taskId), $name . ' hold must start active');
            hub_test_assert($finish($db, hub_get_task($db, $taskId) ?? []), $name . ' terminal helper failed');
            hub_test_assert(hub_test_audio_hold_released($db, (int)$source['artifact_id'], $taskId), $name . ' must release the source hold at terminal state');
        }
    });
});

hub_test('audio admission persists only declared scalar job inputs', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Audio Input Owner');
        $token = hub_create_api_token($db, $memberId, 'audio input token', null, null);
        hub_test_audio_allow($db, [$token], ['speech_transcribe', 'voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $source = hub_test_audio_source_artifact($db, $memberId, (int)$token['token_id']);

        foreach ([
            ['audio_path' => '/host/audio.wav'],
            ['params' => ['callback_url' => 'https://attacker.invalid/']],
            ['unknown_option' => 'nope'],
        ] as $payload) {
            $response = hub_test_audio_request($db, 'speech_transcribe', (string)$token['plain_token'], ['source_artifact_id' => (string)$source['artifact_id']] + $payload);
            hub_test_assert($response['status'] === 400 && (hub_test_audio_payload($response)['error'] ?? '') === 'forbidden_task_control', 'audio admission must reject unknown or nested controls');
        }

        $accepted = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'text' => 'allowed manifest field',
        ]);
        $task = hub_get_task($db, (int)(hub_test_audio_payload($accepted)['task_id'] ?? 0));
        hub_test_assert($accepted['status'] === 200 && ($task['input'] ?? null) === ['text' => 'allowed manifest field'], 'audio task input must contain only declared client fields');
    });
});
