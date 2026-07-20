<?php
declare(strict_types=1);

function hub_test_adapter_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            hub_test_adapter_remove($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_adapter_manifest(string $id, string $version, string $accelerator = 'cpu', bool $withRunner = true): array
{
    $job = [
        'job' => 'convert',
        'input' => ['fields' => [], 'source_artifact_types' => []],
        'output' => ['artifacts' => [[
            'type' => 'result',
            'path' => 'result.txt',
            'mime_types' => ['text/plain'],
            'max_bytes' => 4096,
            'text' => ['max_bytes' => 4096],
        ]]],
    ];
    if ($withRunner) {
        $job['runner'] = [
            'image' => 'registry.example/fixture-pack:1',
            'entrypoint' => ['/app/convert'],
            'args' => ['--workspace', '{workspace}', '--input', '{input_dir}', '--output', '{output_dir}'],
            'output_dir' => 'output',
            'accelerator' => $accelerator,
            'required_vram_mb' => $accelerator === 'gpu' ? 64 : 0,
            'timeout_seconds' => 30,
        ];
    }

    return [
        'schema_version' => '0.1',
        'id' => $id,
        'name' => 'Adapter Fixture',
        'version' => $version,
        'category' => 'test',
        'type' => 'internal_task',
        'execution_type' => 'async_task',
        'runtime_level' => 'test',
        'runtime_ready' => true,
        'default_mode' => 'adapter_fixture',
        'description' => 'Generic Pack job adapter fixture.',
        'runtime' => ['kind' => 'internal_task'],
        'gateway' => ['invoke_path' => '/internal', 'max_upload_mb' => 1],
        'hardware' => ['gpu_required' => false],
        'queue' => ['supported' => true, 'default_queue' => 'default', 'max_concurrency' => 1],
        'storage' => ['mounts' => []],
        'env' => [],
        'preflight' => ['checks' => []],
        'async_jobs' => [$job],
    ];
}

function hub_test_adapter_fixture(PDO $db, string $accelerator = 'cpu', bool $withRunner = true): array
{
    $id = 'adapter-fixture-' . bin2hex(random_bytes(4));
    $dir = HUB_ROOT . '/packs/' . $id;
    if (!mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create adapter Pack fixture.');
    }
    $manifest = hub_test_adapter_manifest($id, '1.0.0', $accelerator, $withRunner);
    file_put_contents($dir . '/pack.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    hub_install_pack($db, $id, ['service_key' => $id . '-main', 'mode' => 'adapter_fixture', 'idempotent' => true]);
    $snapshot = hub_pack_job_contract_snapshot(hub_pack_async_job_contract($manifest, 'convert') ?? []);

    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [
        'command' => 'never-from-client',
        'entrypoint' => '/tmp/never-from-client',
        'image' => 'never-from-client',
    ], null, '127.0.0.1', [
        'requested_mode' => 'fixture_mode',
        'pack_id' => $id,
        'pack_version' => '1.0.0',
        'job' => 'convert',
        'job_contract_json' => $snapshot['json'],
        'job_contract_digest' => $snapshot['digest'],
        'runtime_mode' => 'job',
        'accelerator' => $accelerator,
        'route_resolved_at' => hub_now(),
    ]);

    return ['id' => $id, 'dir' => $dir, 'task_id' => $taskId];
}

function hub_test_adapter_claim(PDO $db): array
{
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    if (!is_array($task)) {
        throw new RuntimeException('Expected shared worker to claim Pack job.');
    }

    return $task;
}

function hub_test_adapter_cleanup(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

hub_test('Pack job adapter uses the shared worker and only manifest runner controls', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    $called = 0;
    try {
        $task = hub_test_adapter_claim($db);
        $memberId = hub_create_api_member($db, 'Adapter Callback Owner');
        $targetId = hub_register_callback_target($db, $memberId, 'adapter-callback', 'https://8.8.8.8/callback');
        $db->prepare('UPDATE tasks SET owner_member_id = :owner_member_id, callback_target_id = :callback_target_id WHERE id = :id')
            ->execute([':owner_member_id' => $memberId, ':callback_target_id' => $targetId, ':id' => $fixture['task_id']]);
        $task = hub_get_task($db, $fixture['task_id']) ?? $task;
        $result = hub_run_pack_job_task($db, $task, [
            'worker_id' => 'adapter-test-worker',
            'executor' => static function (array $context) use (&$called): array {
                $called++;
                hub_test_assert(($context['runner']['image'] ?? '') === 'registry.example/fixture-pack:1', 'executor must receive manifest image only');
                hub_test_assert(!str_contains(json_encode($context['runner']), 'never-from-client'), 'client command controls must not reach executor');
                hub_test_assert(str_starts_with((string)$context['workspace'], hub_task_result_dir((int)$context['task']['id']) . '/workspace'), 'workspace must be canonical and Hub-managed');
                $context['started'](['container_id' => 'fixture-container']);
                file_put_contents($context['workspace'] . '/output/result.txt', "done\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'fixture-container', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        $latest = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT * FROM runtime_runs WHERE task_id = ' . $fixture['task_id'])->fetch();
        hub_test_assert($called === 1 && ($result['status'] ?? '') === 'success', 'one generic executor must run the Pack job');
        hub_test_assert(($latest['status'] ?? '') === 'success' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 1 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 1, 'validated generic output must terminalize publish and enqueue its callback');
        hub_test_assert(($run['container_id'] ?? '') === 'fixture-container', 'runtime run must record its managed container');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter stages only Hub-owned source files and fails safely without an executor', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    try {
        $sourceTaskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
        $sourceDir = hub_task_result_dir($sourceTaskId);
        if (!is_dir($sourceDir)) {
            mkdir($sourceDir, 0700, true);
        }
        $sourcePath = $sourceDir . '/source.bin';
        file_put_contents($sourcePath, 'hub-owned-source', LOCK_EX);
        $sourceArtifactId = hub_register_task_artifact($db, $sourceTaskId, 'source.bin', $sourcePath, 'application/octet-stream');
        $db->prepare('UPDATE tasks SET source_artifact_id = :source_artifact_id WHERE id = :id')->execute([':source_artifact_id' => $sourceArtifactId, ':id' => $fixture['task_id']]);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'executor' => static function (array $context): array {
                hub_test_assert((string)file_get_contents($context['workspace'] . '/input/source') === 'hub-owned-source', 'source must be copied from the registered Hub artifact');
                hub_test_assert(!file_exists($context['workspace'] . '/input/command'), 'client command must never become a workspace input file');
                file_put_contents($context['workspace'] . '/output/result.txt', "source\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'source-container', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'success', 'registered source artifact must be executable through the generic adapter');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task);
        $latest = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT container_id FROM runtime_runs WHERE task_id = ' . $fixture['task_id'])->fetch();
        hub_test_assert(($latest['error_code'] ?? '') === 'runner_unavailable' && ($run['container_id'] ?? null) === null, 'a Pack runner without a configured controlled executor must fail without a spawn');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter cooperatively stops cancelled and timed-out GPU jobs before terminal state', function (): void {
    foreach (['cancelled', 'timed_out'] as $intent) {
        $db = hub_test_reset_db();
        $fixture = hub_test_adapter_fixture($db, 'gpu');
        $stopped = [];
        try {
            $task = hub_test_adapter_claim($db);
            hub_run_pack_job_task($db, $task, [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
                'pid_inspector' => static fn (): array => [],
                'executor' => static function (array $context) use ($intent): array {
                    $context['started'](['container_id' => 'stop-' . $intent, 'baseline_pids' => [11], 'owned_pids' => [22]]);
                    if ($intent === 'cancelled') {
                        hub_test_assert(hub_cancel_task($context['db'], (int)$context['task']['id']), 'cancel request must reach the owned runtime run');
                    } else {
                        $context['db']->prepare('UPDATE runtime_runs SET timeout_at = :timeout_at WHERE id = :id')->execute([':timeout_at' => date('Y-m-d H:i:s', time() - 1), ':id' => $context['run']['id']]);
                    }
                    hub_test_assert($context['tick']() === $intent, 'worker tick must observe terminal intent while executor is running');
                    file_put_contents($context['workspace'] . '/output/result.txt', "must-not-publish\n", LOCK_EX);
                    return ['exit_code' => 0, 'container_id' => 'stop-' . $intent, 'baseline_pids' => [11], 'owned_pids' => [22]];
                },
                'stopper' => static function (array $context, string $reason, array $result) use (&$stopped): array {
                    $stopped[] = [$context['runner']['image'], $reason, $result['container_id'] ?? null];
                    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
                },
            ]);
            $latest = hub_get_task($db, $fixture['task_id']);
            hub_test_assert(($latest['status'] ?? '') === $intent && $stopped === [['registry.example/fixture-pack:1', $intent, 'stop-' . $intent]] && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'cancel/timeout must stop cleanup and release before any artifact success');
        } finally {
            hub_test_adapter_remove($fixture['dir']);
        }
    }
});

hub_test('Pack job adapter blocks cleanup lies after owned PID recheck and fences mid-run loss', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
            'pid_inspector' => static fn (): array => [22],
            'executor' => static function (array $context): array {
                $context['started'](['container_id' => 'pid-residue', 'owned_pids' => [22]]);
                file_put_contents($context['workspace'] . '/output/result.txt', "residue\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'pid-residue', 'owned_pids' => [22], 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        $latest = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($latest['error_code'] ?? '') === 'cleanup_failed' && $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'blocked', 'owned PID recheck must override a false cleanup attestation');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    try {
        $task = hub_test_adapter_claim($db);
        $outcome = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
            'executor' => static function (array $context): array {
                $context['started'](['container_id' => 'tick-fence']);
                $context['db']->prepare('UPDATE runtime_runs SET lease_token = :token WHERE id = :id')->execute([':token' => 'lost-mid-run', ':id' => $context['run']['id']]);
                hub_test_assert($context['tick']() === 'fence_lost', 'heartbeat tick must detect a lost runtime fence during execution');
                file_put_contents($context['workspace'] . '/output/result.txt', "lost-mid-run\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'tick-fence', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'fence_lost' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'mid-run fence loss must prevent publication and callbacks');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter requires explicit no-process evidence when executor reports no runtime metadata', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'executor' => static function (array $context): array {
                file_put_contents($context['workspace'] . '/output/result.txt', "unknown\n", LOCK_EX);
                return ['exit_code' => 0, 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['error_code'] ?? '') === 'cleanup_failed', 'an executor without container or PID data cannot claim a clean exit');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'executor' => static function (array $context): array {
                file_put_contents($context['workspace'] . '/output/result.txt', "known-empty\n", LOCK_EX);
                return ['exit_code' => 0, 'completed_no_process_evidence' => true, 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'success', 'a no-container runner can complete only with explicit no-process evidence');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter rejects unavailable executors before GPU leasing and uses an admission snapshot', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    try {
        $manifest = hub_test_adapter_manifest($fixture['id'], '1.0.0', 'gpu');
        $snapshot = hub_pack_job_contract_snapshot(hub_pack_async_job_contract($manifest, 'convert') ?? []);
        $db->prepare('UPDATE tasks SET job_contract_json = :job_contract_json, job_contract_digest = :job_contract_digest WHERE id = :id')
            ->execute([':job_contract_json' => $snapshot['json'], ':job_contract_digest' => $snapshot['digest'], ':id' => $fixture['task_id']]);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task);
        $latest = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state FROM runtime_runs WHERE task_id = ' . $fixture['task_id'])->fetch();
        hub_test_assert(($latest['error_code'] ?? '') === 'runner_unavailable' && ($run['state'] ?? '') === 'failed' && $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'missing executor must fail before GPU acquisition or waiting retry');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    $called = 0;
    try {
        $manifest = hub_test_adapter_manifest($fixture['id'], '1.0.0');
        $snapshot = hub_pack_job_contract_snapshot(hub_pack_async_job_contract($manifest, 'convert') ?? []);
        $db->prepare('UPDATE tasks SET job_contract_json = :job_contract_json, job_contract_digest = :job_contract_digest WHERE id = :id')
            ->execute([':job_contract_json' => $snapshot['json'], ':job_contract_digest' => $snapshot['digest'], ':id' => $fixture['task_id']]);
        $manifest['async_jobs'][0]['runner']['image'] = 'registry.example/changed:1';
        $manifest['async_jobs'][0]['runner']['args'][] = '--changed-live-runner';
        file_put_contents($fixture['dir'] . '/pack.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'executor' => static function (array $context) use (&$called): array {
                $called++;
                hub_test_assert(($context['runner']['image'] ?? '') === 'registry.example/fixture-pack:1' && !in_array('--changed-live-runner', $context['runner']['args'] ?? [], true), 'executor must receive the admission snapshot, not a changed live manifest');
                file_put_contents($context['workspace'] . '/output/result.txt', "pinned\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'pinned-contract', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert($called === 1 && (hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'success', 'same-version runner edits must not alter admitted work');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter rejects tampered and pre-snapshot contracts safely', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    $called = 0;
    try {
        $db->prepare('UPDATE tasks SET job_contract_json = :job_contract_json, job_contract_digest = :job_contract_digest WHERE id = :id')
            ->execute([':job_contract_json' => '{}', ':job_contract_digest' => str_repeat('a', 64), ':id' => $fixture['task_id']]);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, ['executor' => static function () use (&$called): array { $called++; return []; }]);
        hub_test_assert($called === 0 && (hub_get_task($db, $fixture['task_id'])['error_code'] ?? '') === 'job_contract_unavailable', 'a digest mismatch must fail before executor spawn');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    $called = 0;
    try {
        $db->prepare('UPDATE tasks SET job_contract_json = NULL, job_contract_digest = NULL WHERE id = :id')->execute([':id' => $fixture['task_id']]);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, ['executor' => static function () use (&$called): array { $called++; return []; }]);
        hub_test_assert($called === 0 && (hub_get_task($db, $fixture['task_id'])['error_code'] ?? '') === 'job_contract_unavailable', 'pre-snapshot Pack tasks must not fall back to the live manifest');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter pins the installed version and requires a manifest runner', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'cpu', false);
    $called = 0;
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, ['executor' => static function () use (&$called): array { $called++; return []; }]);
        $missingRunner = hub_get_task($db, $fixture['task_id']);
        hub_test_assert($called === 0 && ($missingRunner['error_code'] ?? '') === 'job_unavailable', 'runner-less async declarations must fail without spawning');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db);
    $called = 0;
    try {
        $manifest = hub_test_adapter_manifest($fixture['id'], '2.0.0');
        file_put_contents($fixture['dir'] . '/pack.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, ['executor' => static function () use (&$called): array { $called++; return []; }]);
        $changed = hub_get_task($db, $fixture['task_id']);
        hub_test_assert($called === 0 && ($changed['error_code'] ?? '') === 'pack_version_unavailable', 'adapter must never fall back to a changed Pack version');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter waits then reclaims GPU work while CPU skips GPU', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    $called = 0;
    try {
        $task = hub_test_adapter_claim($db);
        $waiting = hub_run_pack_job_task($db, $task, [
            'worker_id' => 'gpu-wait-worker',
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 0, 'processes' => []],
            'executor' => static function () use (&$called): array { $called++; return []; },
            'gpu_backoff_seconds' => 1,
        ]);
        $row = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($waiting['status'] ?? '') === 'waiting_gpu' && $called === 0 && ($row['status'] ?? '') === 'waiting_gpu', 'preflight wait must not start a container');
        $db->prepare('UPDATE tasks SET next_attempt_at = :now WHERE id = :id')->execute([':now' => hub_now(), ':id' => $fixture['task_id']]);
        $retry = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $retry, [
            'worker_id' => 'gpu-wait-worker',
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
            'executor' => static function (array $context) use (&$called): array {
                $called++;
                $context['started'](['container_id' => 'gpu-container', 'baseline_pids' => [11], 'owned_pids' => [22]]);
                file_put_contents($context['workspace'] . '/output/result.txt', "gpu\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'gpu-container', 'baseline_pids' => [11], 'owned_pids' => [22], 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        $run = $db->query('SELECT container_id, gpu_process_baseline_json, owned_gpu_pids_json FROM runtime_runs WHERE task_id = ' . $fixture['task_id'])->fetch();
        hub_test_assert($called === 1 && (($run['container_id'] ?? '') === 'gpu-container') && str_contains((string)$run['gpu_process_baseline_json'], '11') && str_contains((string)$run['owned_gpu_pids_json'], '22'), 'GPU retry must record managed ownership before success');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'cpu');
    $probeCalled = false;
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static function () use (&$probeCalled): array { $probeCalled = true; return []; },
            'executor' => static function (array $context): array {
                file_put_contents($context['workspace'] . '/output/result.txt', "cpu\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'cpu-container', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert(!$probeCalled && ($db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available'), 'CPU Pack jobs must not acquire or preflight the GPU');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});

hub_test('Pack job adapter blocks GPU on cleanup failure and never publishes after fence loss', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    try {
        $task = hub_test_adapter_claim($db);
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
            'executor' => static function (array $context): array {
                $context['started'](['container_id' => 'cleanup-fail', 'owned_pids' => [22]]);
                file_put_contents($context['workspace'] . '/output/result.txt', "not published\n", LOCK_EX);
                return ['exit_code' => 0, 'container_id' => 'cleanup-fail', 'owned_pids' => [22], 'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => false]];
            },
        ]);
        $latest = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($latest['error_code'] ?? '') === 'cleanup_failed' && $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'blocked', 'cleanup failure must block the GPU instead of claiming clean terminal state');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }

    $db = hub_test_reset_db();
    $fixture = hub_test_adapter_fixture($db, 'gpu');
    try {
        $task = hub_test_adapter_claim($db);
        $outcome = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 4096, 'processes' => []],
            'executor' => static function (array $context): array {
                $context['started'](['container_id' => 'lost-fence']);
                file_put_contents($context['workspace'] . '/output/result.txt', "lost\n", LOCK_EX);
                $context['db']->prepare('UPDATE runtime_runs SET lease_token = :token WHERE id = :id')->execute([':token' => 'lost', ':id' => $context['run']['id']]);
                return ['exit_code' => 0, 'container_id' => 'lost-fence', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'fence_lost' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'fence loss must prevent artifacts and callbacks');
    } finally {
        hub_test_adapter_remove($fixture['dir']);
    }
});
