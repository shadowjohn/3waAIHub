<?php
declare(strict_types=1);

function hub_test_pack_job_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_pack_job_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/output', 0775, true)) {
        throw new RuntimeException('Cannot create Pack-job workspace fixture.');
    }

    return $workspace;
}

function hub_test_pack_job_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            hub_test_pack_job_rm($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_pack_job_contract(): array
{
    return [
        'artifacts' => [
            [
                'type' => 'transcript_json',
                'path' => 'transcript.json',
                'mime_types' => ['application/json'],
                'json' => ['required_keys' => ['text']],
            ],
            [
                'type' => 'subtitle_text',
                'path' => 'subtitle.srt',
                'mime_types' => ['text/plain'],
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 128],
            ],
            [
                'type' => 'audio',
                'path' => 'audio.wav',
                'mime_types' => ['audio/wav', 'audio/x-wav'],
                'audio' => [],
            ],
        ],
    ];
}

function hub_test_pack_job_write(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write Pack-job output fixture.');
    }
}

function hub_test_pack_job_wav(): string
{
    return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . 'data' . pack('V', 0);
}

function hub_test_pack_job_audio_probe(string $path): array
{
    hub_test_assert(is_file($path), 'audio probe must receive Hub-resolved output path');

    return ['duration_seconds' => 1.25, 'sample_rate' => 48000, 'channels' => 2];
}

function hub_test_pack_job_create_terminal_fixture(PDO $db, ?int $callbackTargetId = null): array
{
    $memberId = hub_create_api_member($db, 'Pack Job Terminal Owner ' . bin2hex(random_bytes(3)));
    $sourceTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
    ]);
    $sourcePath = hub_task_result_dir($sourceTaskId) . '/source.wav';
    if (!is_dir(dirname($sourcePath)) && !mkdir(dirname($sourcePath), 0775, true) && !is_dir(dirname($sourcePath))) {
        throw new RuntimeException('Cannot create source artifact fixture.');
    }
    hub_test_pack_job_write($sourcePath, hub_test_pack_job_wav());
    $sourceArtifactId = hub_register_task_artifact($db, $sourceTaskId, 'source.wav', $sourcePath, 'audio/wav');
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, ['include_subtitles' => true], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
        'source_artifact_id' => $sourceArtifactId,
        'source_task_id' => $sourceTaskId,
        'callback_target_id' => $callbackTargetId,
    ]);
    hub_hold_task_source_artifact($db, $sourceArtifactId, $taskId);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = :lock_token WHERE id = :id")
        ->execute([':lock_token' => 'task-lock-' . $taskId, ':id' => $taskId]);

    $leaseToken = bin2hex(random_bytes(32));
    $runId = 'pack_job_' . bin2hex(random_bytes(8));
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, state, worker_id, lease_token, task_id, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :state, :worker_id, :lease_token, :task_id, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'whisper-asr',
        ':task' => 'transcribe',
        ':state' => 'running',
        ':worker_id' => 'test-worker',
        ':lease_token' => $leaseToken,
        ':task_id' => $taskId,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    return [
        'member_id' => $memberId,
        'task_id' => $taskId,
        'source_artifact_id' => $sourceArtifactId,
        'run' => [
            'id' => (int)$db->lastInsertId(),
            'lease_token' => $leaseToken,
        ],
    ];
}

function hub_test_pack_job_cleanup_asserted(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

function hub_test_pack_job_contract_fails(callable $fn): bool
{
    try {
        $fn();
    } catch (HubPackOutputContractInvalid) {
        return true;
    } catch (Throwable) {
        return false;
    }

    return false;
}

hub_test('Pack job artifact validation recomputes trusted metadata and respects conditional outputs', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}\n");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());

        $validated = hub_validate_pack_job_artifacts(
            $workspace,
            ['include_subtitles' => true],
            hub_test_pack_job_contract(),
            'hub_test_pack_job_audio_probe'
        );
        $byType = array_column($validated, null, 'artifact_type');
        hub_test_assert(count($validated) === 3, 'required and conditionally enabled outputs must validate');
        hub_test_assert(($byType['transcript_json']['sha256'] ?? '') === hash_file('sha256', $workspace . '/output/transcript.json'), 'Hub must recompute output SHA-256');
        hub_test_assert((int)($byType['transcript_json']['size_bytes'] ?? 0) === filesize($workspace . '/output/transcript.json'), 'Hub must recompute output size');
        hub_test_assert(($byType['audio']['metadata']['duration_seconds'] ?? null) === 1.25 && ($byType['audio']['metadata']['sample_rate'] ?? null) === 48000 && ($byType['audio']['metadata']['channels'] ?? null) === 2, 'audio probe data must be recorded as Hub metadata');

        unlink($workspace . '/output/subtitle.srt');
        $withoutSubtitle = hub_validate_pack_job_artifacts(
            $workspace,
            ['include_subtitles' => false],
            hub_test_pack_job_contract(),
            'hub_test_pack_job_audio_probe'
        );
        hub_test_assert(count($withoutSubtitle) === 2, 'conditional output must be absent when its input flag is false');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job artifact validation rejects escape symlink nonregular extra and invalid content outputs', function (): void {
    $cases = [
        'traversal' => static function (string $workspace, array &$contract): void {
            $contract['artifacts'][0]['path'] = '../escape.json';
        },
        'symlink' => static function (string $workspace, array &$contract): void {
            $outside = tempnam(sys_get_temp_dir(), '3waaihub_outside_');
            if ($outside === false) {
                throw new RuntimeException('Cannot create symlink fixture.');
            }
            unlink($workspace . '/output/transcript.json');
            if (!symlink($outside, $workspace . '/output/transcript.json')) {
                throw new RuntimeException('Cannot create symlink fixture.');
            }
        },
        'nonregular' => static function (string $workspace, array &$contract): void {
            unlink($workspace . '/output/transcript.json');
            mkdir($workspace . '/output/transcript.json');
        },
        'extra' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/unrecognized.bin', 'unexpected');
        },
        'json' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/transcript.json', '[]');
        },
        'text' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/subtitle.srt', "\xff");
        },
    ];
    foreach ($cases as $name => $mutate) {
        $workspace = hub_test_pack_job_workspace();
        try {
            hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
            hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
            hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
            $contract = hub_test_pack_job_contract();
            $mutate($workspace, $contract);
            hub_test_assert(
                hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe')),
                'invalid ' . $name . ' output must fail the contract'
            );
        } finally {
            hub_test_pack_job_rm($workspace);
        }
    }

    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        hub_test_assert(
            hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => false], hub_test_pack_job_contract(), static fn (): array => [])),
            'audio output must fail closed when ffprobe data is unavailable'
        );
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job success terminal commit atomically registers validated outputs state holds and callback outbox', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-complete', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());

        $task = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        $artifacts = $db->prepare('SELECT artifact_type, mime_type, size_bytes, sha256, metadata_json FROM task_artifacts WHERE task_id = :task_id ORDER BY id');
        $artifacts->execute([':task_id' => $fixture['task_id']]);
        $rows = $artifacts->fetchAll();
        $delivery = $db->query('SELECT event_type, payload_json FROM task_callback_deliveries')->fetch();
        $hold = $db->prepare('SELECT released_at FROM task_artifact_holds WHERE source_artifact_id = :source AND downstream_task_id = :task');
        $hold->execute([':source' => $fixture['source_artifact_id'], ':task' => $fixture['task_id']]);
        hub_test_assert(($task['status'] ?? '') === 'success' && ($run['state'] ?? '') === 'succeeded', 'success terminal commit must complete task and owned run');
        hub_test_assert(count($rows) === 3 && ($rows[2]['sha256'] ?? '') === hash_file('sha256', $workspace . '/output/audio.wav'), 'success terminal commit must register only Hub-validated metadata');
        hub_test_assert((json_decode((string)($rows[2]['metadata_json'] ?? ''), true)['sample_rate'] ?? null) === 48000, 'audio metadata must be stored with registered artifact');
        hub_test_assert(!empty(($hold->fetch() ?: [])['released_at']), 'success terminal commit must release source hold in its transaction');
        hub_test_assert(($delivery['event_type'] ?? '') === 'task.completed' && count(json_decode((string)($delivery['payload_json'] ?? ''), true)['artifacts'] ?? []) === 3, 'callback must be outbox-only and see committed artifact registry');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job terminal fence mismatch rolls back registrations callbacks and task state', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $badRun = $fixture['run'];
        $badRun['lease_token'] = 'stale-fence';
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $badRun, $validated, hub_test_pack_job_cleanup_asserted())), 'stale success fence must fail');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'fence mismatch must not register partial artifacts');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'fence mismatch must roll back task and run terminal state');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'fence mismatch must not expose an outbox callback');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job invalid output and cleanup failure terminalize as failed through the outbox', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-failed', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', 'not-json');
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'invalid output must report the fixed contract error');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'invalid output must fail without partial artifact registration');
        hub_test_assert($delivery === 'task.failed', 'invalid output must enqueue failed callback without network delivery');
    } finally {
        hub_test_pack_job_rm($workspace);
    }

    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'cleanup_failed', 'container cleanup failed');
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'cleanup failure must remain terminal failure');
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'cancelled', 'cancelled', 'cancelled')), 'cancelled terminal state must require explicit cleanup assertion');
});
