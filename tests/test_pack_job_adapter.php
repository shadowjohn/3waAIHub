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

    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [
        'command' => 'never-from-client',
        'entrypoint' => '/tmp/never-from-client',
        'image' => 'never-from-client',
    ], null, '127.0.0.1', [
        'requested_mode' => 'fixture_mode',
        'pack_id' => $id,
        'pack_version' => '1.0.0',
        'job' => 'convert',
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
        hub_test_assert(($latest['status'] ?? '') === 'success' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 1, 'validated generic output must terminalize and publish');
        hub_test_assert(($run['container_id'] ?? '') === 'fixture-container', 'runtime run must record its managed container');
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
