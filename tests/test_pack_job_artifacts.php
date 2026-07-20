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
                'max_bytes' => 1048576,
                'json' => ['required_keys' => ['text']],
            ],
            [
                'type' => 'subtitle_text',
                'path' => 'subtitle.srt',
                'mime_types' => ['text/plain'],
                'max_bytes' => 128,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 128],
            ],
            [
                'type' => 'audio',
                'path' => 'audio.wav',
                'mime_types' => ['audio/wav', 'audio/x-wav'],
                'max_bytes' => 1048576,
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
    $workspace = hub_task_result_dir($taskId) . '/workspace';
    if (!is_dir($workspace . '/output') && !mkdir($workspace . '/output', 0775, true) && !is_dir($workspace . '/output')) {
        throw new RuntimeException('Cannot create trusted Pack-job workspace fixture.');
    }

    $leaseToken = bin2hex(random_bytes(32));
    $runId = 'pack_job_' . bin2hex(random_bytes(8));
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, workspace, state, worker_id, lease_token, task_id, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :workspace, :state, :worker_id, :lease_token, :task_id, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'whisper-asr',
        ':task' => 'transcribe',
        ':workspace' => $workspace,
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
        'workspace' => $workspace,
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

function hub_test_pack_job_with_env(string $key, string $value, callable $fn): void
{
    $previous = getenv($key);
    putenv($key . '=' . $value);
    try {
        $fn();
    } finally {
        putenv($previous === false ? $key : $key . '=' . $previous);
    }
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
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());

        $task = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        $artifacts = $db->prepare('SELECT path, artifact_type, mime_type, size_bytes, sha256, metadata_json FROM task_artifacts WHERE task_id = :task_id ORDER BY id');
        $artifacts->execute([':task_id' => $fixture['task_id']]);
        $rows = $artifacts->fetchAll();
        $delivery = $db->query('SELECT event_type, payload_json FROM task_callback_deliveries')->fetch();
        $hold = $db->prepare('SELECT released_at FROM task_artifact_holds WHERE source_artifact_id = :source AND downstream_task_id = :task');
        $hold->execute([':source' => $fixture['source_artifact_id'], ':task' => $fixture['task_id']]);
        hub_test_assert(($task['status'] ?? '') === 'success' && ($run['state'] ?? '') === 'succeeded', 'success terminal commit must complete task and owned run');
        hub_test_assert(count($rows) === 3 && ($rows[2]['sha256'] ?? '') === hash_file('sha256', $workspace . '/output/audio.wav'), 'success terminal commit must register only Hub-validated metadata');
        hub_test_assert(hub_artifact_safe_path((string)($rows[2]['path'] ?? '')) === ($rows[2]['path'] ?? ''), 'success artifacts must remain directly downloadable from managed results storage');
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
    $workspace = $fixture['workspace'];
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

hub_test('Pack job terminal rejects an unlinked runtime run without partial commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare('UPDATE runtime_runs SET task_id = NULL WHERE id = :id')->execute([':id' => $fixture['run']['id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted())), 'unlinked runtime run must fail Pack terminal fencing');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'unlinked run fence must roll back task and run states');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'unlinked run fence must not expose artifacts or callback');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job success terminal rejects missing runtime context without mutations', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], null, $validated, hub_test_pack_job_cleanup_asserted())), 'success terminal must require a runtime fence');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'missing success fence must preserve task and run states');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'missing success fence must not expose artifacts or callbacks');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job failure terminal rejects missing runtime context without mutations', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], null, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted())), 'failure terminal must require a runtime fence');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'missing failure fence must preserve task and run states');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'missing failure fence must not expose a callback');
});

hub_test('Pack job terminal rejects a workspace outside its trusted runtime workspace', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $foreignWorkspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($foreignWorkspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($foreignWorkspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($foreignWorkspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $foreignWorkspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'foreign workspace must be rejected as output contract invalid');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'foreign workspace must not commit artifacts or success');
    } finally {
        hub_test_pack_job_rm($foreignWorkspace);
    }
});

hub_test('Pack job terminal rejects output replacement after validation before commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $replacement = $workspace . '/replacement.json';
        $target = $workspace . '/output/transcript.json';
        hub_test_pack_job_write($replacement, "{\"text\":\"hello\"}");
        lstat($target); // Populate PHP's stat cache before an external replacement.
        $output = [];
        $exitCode = 1;
        exec('rm ' . escapeshellarg($target) . ' && ln ' . escapeshellarg($replacement) . ' ' . escapeshellarg($target), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('Cannot replace output with hardlink fixture.');
        }
        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'failed', 'output replacement must terminalize as output_contract_invalid');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'output replacement must not register stale metadata');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job finalize reports snapshot revalidation failure instead of success', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-revalidation', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $probe = static function (string $path) use ($workspace): array {
            hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"changed after validation\"}");
            return hub_test_pack_job_audio_probe($path);
        };
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), $probe);
        $task = hub_get_task($db, $fixture['task_id']);
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'finalize must report the revalidation failure to its caller');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && $delivery === 'task.failed', 'revalidation failure must keep the failed terminal state and outbox');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'revalidation failure must not register artifacts');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job finalize rejects a contract with no active outputs', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-no-outputs', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $contract = [
        'artifacts' => [
            [
                'type' => 'optional_json',
                'path' => 'optional.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 1024,
                'required' => false,
                'json' => ['required_keys' => ['text']],
            ],
            [
                'type' => 'conditional_json',
                'path' => 'conditional.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 1024,
                'when' => ['input' => 'include_conditional', 'equals' => true],
                'json' => ['required_keys' => ['text']],
            ],
        ],
    ];

    $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $fixture['workspace'], ['include_conditional' => false], $contract, hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
    $task = hub_get_task($db, $fixture['task_id']);
    $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'an empty active contract must not report a successful finalize');
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'output_contract_invalid', 'an empty active contract must terminalize task and run as output-contract failure');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && $delivery === 'task.failed', 'an empty active contract must register no success artifacts or success callback');
});

hub_test('Pack job handoff keeps registered artifacts immutable after runner workspace mutation', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"before handoff\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $published = hub_handoff_pack_job_artifacts($db, $fixture['task_id'], $fixture['run'], $validated);
        $publishedTranscript = array_values(array_filter($published, static fn (array $artifact): bool => ($artifact['name'] ?? '') === 'transcript.json'))[0] ?? null;
        hub_test_assert(is_array($publishedTranscript) && !str_starts_with((string)$publishedTranscript['path'], $workspace . '/'), 'handoff must publish outside the runner workspace');

        rename($workspace . '/output/transcript.json', $workspace . '/output/transcript.runner-old.json');
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"runner changed it after handoff\"}");
        $outcome = hub_commit_published_pack_job_success($db, $fixture['task_id'], $fixture['run'], $published, hub_test_pack_job_cleanup_asserted());
        $row = $db->query("SELECT path, sha256 FROM task_artifacts WHERE task_id = " . (int)$fixture['task_id'] . " AND name = 'transcript.json'")->fetch();
        hub_test_assert(($outcome['ok'] ?? false) === true, 'published artifacts must remain committable after runner workspace mutation');
        hub_test_assert(($row['path'] ?? '') === ($publishedTranscript['path'] ?? '') && ($row['sha256'] ?? '') === hash_file('sha256', (string)($row['path'] ?? '')) && ($row['sha256'] ?? '') !== hash_file('sha256', $workspace . '/output/transcript.json'), 'registered SHA-256 must describe the final Hub-owned downloadable copy, not the mutated runner output');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job handoff failure terminalizes without a partial artifact registry', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    try {
        hub_test_pack_job_rm($artifactRoot);
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        if (!symlink($workspace, $artifactRoot)) {
            throw new RuntimeException('Cannot create unsafe artifact-root fixture.');
        }
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'an unsafe publication directory must fail closed');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'output_contract_invalid', 'handoff setup failure must terminalize through the fenced failure path');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'handoff setup failure must not register a partial artifact set');
    } finally {
        if (is_link($artifactRoot)) {
            unlink($artifactRoot);
        }
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job terminal rejects an extra output added after validation before commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_pack_job_write($workspace . '/output/late-extra.bin', 'late runner output');
        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid', 'post-validation extra output must terminalize as output_contract_invalid');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'post-validation extra output must not register any artifact');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job invalid output and cleanup failure terminalize as failed through the outbox', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-failed', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
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

hub_test('Pack job incomplete cleanup attestation fails the requested success atomically', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-cleanup', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);

    $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], '/not-used', ['include_subtitles' => true], hub_test_pack_job_contract(), ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => false], 'hub_test_pack_job_audio_probe');
    $task = hub_get_task($db, $fixture['task_id']);
    $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'cleanup_failed', 'incomplete cleanup must reject requested success as cleanup_failed');
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'cleanup_failed', 'incomplete cleanup must terminalize task and fenced run as failed');
    hub_test_assert($delivery === 'task.failed' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'cleanup failure must use only the failed outbox without registering outputs');
});

hub_test('Pack job missing required output fails the contract without success registration', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'missing required output must use the fixed output contract failure');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'missing required output must not register artifacts or success');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job output size cap rejects before parsing and terminalizes without artifacts', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    $contract = hub_test_pack_job_contract();
    $contract['artifacts'][0]['max_bytes'] = 16;
    $contract['artifacts'][1]['max_bytes'] = 128;
    $contract['artifacts'][2]['max_bytes'] = 1024;
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"this is larger than sixteen bytes\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $reason = '';
        try {
            hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_size_invalid', 'oversized output must be rejected on its size before JSON parsing');

        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], $contract, hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'oversized output must report the fixed contract failure');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'oversized output must not partially register terminal artifacts');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job streamed SHA-256 rejects bytes that exceed its cap', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3waaihub_pack_hash_');
    if ($path === false) {
        throw new RuntimeException('Cannot create Pack-job hash fixture.');
    }
    try {
        hub_test_pack_job_write($path, str_repeat('x', 17));
        $reason = '';
        try {
            hub_pack_job_sha256_file($path, 16);
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_size_invalid', 'streamed hashing must stop once bytes exceed the previously accepted artifact cap');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

hub_test('Pack job traversal rejects unexpected empty directories without collecting the tree', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        mkdir($workspace . '/output/unexpected-empty');
        $reason = '';
        try {
            hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_set_invalid', 'an unexpected empty directory must be rejected during traversal');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal entry cap stops before building a large output map', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'one/transcript.json';
        $contract['artifacts'][1]['path'] = 'two/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'three/audio.wav';
        foreach (['one', 'two', 'three'] as $dir) {
            mkdir($workspace . '/output/' . $dir);
        }
        hub_test_pack_job_write($workspace . '/output/one/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/two/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/three/audio.wav', hub_test_pack_job_wav());
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_ENTRIES', '4', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_entry_limit', 'entry cap must stop traversal before all expected paths can be collected');
        });
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal enforces configured depth and aggregate-size caps', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'a/b/c/transcript.json';
        $contract['artifacts'][1]['path'] = 'a/b/c/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'a/b/c/audio.wav';
        mkdir($workspace . '/output/a/b/c', 0775, true);
        hub_test_pack_job_write($workspace . '/output/a/b/c/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/a/b/c/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/a/b/c/audio.wav', hub_test_pack_job_wav());
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_DEPTH', '2', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_depth_limit', 'depth cap must reject a nested runner tree before parsing outputs');
        });
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_TOTAL_BYTES', '20', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_total_size_invalid', 'aggregate-size cap must reject before artifact hashing');
        });
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal permits declared nested artifact parents', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'results/transcript.json';
        $contract['artifacts'][1]['path'] = 'results/subtitles/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'results/audio/audio.wav';
        mkdir($workspace . '/output/results/subtitles', 0775, true);
        mkdir($workspace . '/output/results/audio', 0775, true);
        hub_test_pack_job_write($workspace . '/output/results/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/results/subtitles/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/results/audio/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
        hub_test_assert(count($validated) === 3, 'declared nested artifact parent directories must remain valid');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job failed terminalization requires cleanup attestation before preserving its error', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'runner failed');
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'unattested ordinary failure must normalize to cleanup_failed');

    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted());
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'runtime_exit_nonzero', 'attested ordinary failure must preserve its error code');
});

hub_test('Pack job timeout fencing lets cancellation win and cancellation records its timestamp', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $past = date('Y-m-d H:i:s', time() - 60);
    $db->prepare('UPDATE runtime_runs SET timeout_at = :timeout_at, cancel_requested_at = :cancel_requested_at WHERE id = :id')->execute([
        ':timeout_at' => $past,
        ':cancel_requested_at' => $past,
        ':id' => $fixture['run']['id'],
    ]);
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'timed_out', 'timed_out', 'timed out', hub_test_pack_job_cleanup_asserted())), 'timeout must not win when cancellation was requested');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'cancel-timeout fence race must roll back terminal states');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'cancelled', 'cancelled', 'cancelled', hub_test_pack_job_cleanup_asserted());
    $run = $db->query('SELECT state, cancelled_at FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    hub_test_assert(($run['state'] ?? '') === 'cancelled' && !empty($run['cancelled_at']), 'cancelled Pack run must record canonical cancelled_at');
});

hub_test('Queued Pack job cancellation uses one terminal outbox transaction', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-queued-cancel', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET status = :status, lock_token = NULL, callback_target_id = :target_id WHERE id = :id')->execute([
        ':status' => 'queued',
        ':target_id' => $targetId,
        ':id' => $fixture['task_id'],
    ]);
    $db->prepare('DELETE FROM runtime_runs WHERE id = :id')->execute([':id' => $fixture['run']['id']]);

    hub_test_assert(hub_cancel_task($db, $fixture['task_id']), 'queued Pack job must cancel through its terminal helper');
    hub_test_assert(!hub_cancel_task($db, $fixture['task_id']), 'terminal Pack job cancellation must not enqueue a duplicate callback');
    $task = hub_get_task($db, $fixture['task_id']);
    $hold = $db->prepare('SELECT released_at FROM task_artifact_holds WHERE source_artifact_id = :source AND downstream_task_id = :task');
    $hold->execute([':source' => $fixture['source_artifact_id'], ':task' => $fixture['task_id']]);
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($task['status'] ?? '') === 'cancelled' && !empty(($hold->fetch() ?: [])['released_at']), 'queued Pack cancellation must atomically terminalize and release its source hold');
    hub_test_assert($delivery === 'task.failed' && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 1, 'queued Pack cancellation must create exactly one failed outbox callback');
});
