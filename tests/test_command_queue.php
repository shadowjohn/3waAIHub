<?php
declare(strict_types=1);

hub_test('command worker allowlist includes Docker builder prune only as explicit maintenance action', function (): void {
    hub_test_assert(hub_is_valid_job_action('docker_builder_prune'), 'docker_builder_prune must be allowlisted');
    hub_test_assert(!hub_is_valid_job_action('docker system prune -af'), 'raw Docker commands must stay rejected');
});

hub_test('command runner preserves observed exit code when proc_close returns unknown', function (): void {
    hub_test_assert(hub_process_exit_code(-1, 0) === 0, 'observed successful exit code must win over proc_close -1');
    hub_test_assert(hub_process_exit_code(-1, 7) === 7, 'observed non-zero exit code must win over proc_close -1');
    hub_test_assert(hub_process_exit_code(0, 7) === 0, 'proc_close exit code must win when it is known');
});

hub_test('command job finish and status preserve unsupported target error code', function (): void {
    $logRoot = sys_get_temp_dir() . '/3waaihub_persistence_logs_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $stdoutPath = $logRoot . '/job.out.log';
    $stderrPath = $logRoot . '/job.err.log';
    $stmt = null;
    try {
        mkdir($logRoot, 0775, true);
        $db = hub_test_reset_db();
        $jobId = hub_enqueue_command_job($db, 'docker_prune_check', null, [], null, '127.0.0.1');
        $stmt = $db->prepare('UPDATE command_jobs SET stdout_path = :stdout_path, stderr_path = :stderr_path WHERE id = :id');
        $stmt->execute([
            ':stdout_path' => $stdoutPath,
            ':stderr_path' => $stderrPath,
            ':id' => $jobId,
        ]);
        $job = hub_get_command_job($db, $jobId);
        hub_test_assert($job['stdout_path'] === $stdoutPath, 'unsupported job stdout log must use the isolated test path');
        hub_test_assert($job['stderr_path'] === $stderrPath, 'unsupported job stderr log must use the isolated test path');
        hub_finish_command_job(
            $db,
            $job,
            'failed',
            78,
            '',
            'unsupported: linux-docker target is not available on Windows host',
            'linux-docker target is not available on Windows host',
            'platform_target_unsupported'
        );

        $payload = hub_command_job_status_payload($db, $jobId);
        hub_test_assert($payload['status'] === 'failed', 'unsupported job status must remain failed');
        hub_test_assert($payload['exit_code'] === 78, 'unsupported job exit code mismatch');
        hub_test_assert($payload['error_code'] === 'platform_target_unsupported', 'unsupported job error code must persist');
        hub_test_assert($payload['error_message'] === 'linux-docker target is not available on Windows host', 'unsupported DB message must not include prefix');
    } finally {
        $stmt = null;
        foreach ([$stdoutPath, $stderrPath] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($logRoot)) {
            @rmdir($logRoot);
        }
    }
});

hub_test('Windows command worker rejects Linux Docker maintenance without invoking Docker', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only command worker integration.');
    }

    $workerRoot = sys_get_temp_dir() . '/3waaihub_worker_gate_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $workerDbPath = $workerRoot . '/worker.sqlite';
    $logPaths = [];
    $stmt = null;
    $workerDb = null;
    try {
        mkdir($workerRoot, 0775, true);
        $workerDb = new PDO('sqlite:' . $workerDbPath);
        $workerDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $workerDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        hub_migrate($workerDb);
        $now = hub_now();
        $stmt = $workerDb->prepare(
            'INSERT INTO services
                (name, mode, type, internal_url, health_url, compose_project, compose_file, enabled, status, runtime_status, created_at, updated_at)
             VALUES
                (:name, :mode, :type, :internal_url, :health_url, :compose_project, :compose_file, :enabled, :status, :runtime_status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => 'Docker Worker Fixture',
            ':mode' => 'docker_worker_fixture',
            ':type' => 'docker',
            ':internal_url' => 'http://127.0.0.1:18100',
            ':health_url' => 'http://127.0.0.1:18100/health',
            ':compose_project' => 'docker-worker-fixture',
            ':compose_file' => 'unused-docker-compose.yml',
            ':enabled' => 0,
            ':status' => 'stopped',
            ':runtime_status' => 'stopped',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $service = hub_get_service_by_mode($workerDb, 'docker_worker_fixture');
        $stmt->execute([
            ':name' => 'Internal Worker Fixture',
            ':mode' => 'internal_worker_fixture',
            ':type' => 'internal_task',
            ':internal_url' => 'internal-task:test',
            ':health_url' => 'internal-task:health',
            ':compose_project' => 'internal-worker-fixture',
            ':compose_file' => 'unused-internal-task-compose.yml',
            ':enabled' => 1,
            ':status' => 'running',
            ':runtime_status' => 'running',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $internalService = hub_get_service_by_mode($workerDb, 'internal_worker_fixture');
        $internalStateBefore = array_intersect_key($internalService, array_flip(['enabled', 'status', 'runtime_status']));
        $maintenanceJobId = hub_enqueue_command_job($workerDb, 'docker_prune_check', null, [], null, '127.0.0.1');
        $healthJobId = hub_enqueue_command_job($workerDb, 'service_health_check', (int)$service['id'], [], null, '127.0.0.1');
        $restartJobId = hub_enqueue_command_job($workerDb, 'service_restart', (int)$internalService['id'], [], null, '127.0.0.1');
        $logsJobId = hub_enqueue_command_job($workerDb, 'service_logs_collect', (int)$internalService['id'], [], null, '127.0.0.1');
        foreach ([$maintenanceJobId, $healthJobId, $restartJobId, $logsJobId] as $jobId) {
            $stdoutPath = $workerRoot . '/job_' . $jobId . '.out.log';
            $stderrPath = $workerRoot . '/job_' . $jobId . '.err.log';
            file_put_contents($stdoutPath, '');
            file_put_contents($stderrPath, '');
            $workerDb->prepare('UPDATE command_jobs SET stdout_path = :stdout_path, stderr_path = :stderr_path WHERE id = :id')->execute([
                ':stdout_path' => $stdoutPath,
                ':stderr_path' => $stderrPath,
                ':id' => $jobId,
            ]);
            $logPaths[] = $stdoutPath;
            $logPaths[] = $stderrPath;
        }

        $result = hub_run_command(
            [PHP_BINARY, HUB_ROOT . '/scripts/command_worker.php', '--limit=4'],
            30,
            ['AIHUB_TEST_DB' => $workerDbPath]
        );

        hub_test_assert($result['exit_code'] === 0, 'command worker process failed: ' . $result['output']);
        hub_test_assert(str_contains($result['stderr'], 'unsupported: linux-docker target is not available on Windows host'), 'command worker must expose the unsupported stderr contract');
        foreach ([$maintenanceJobId, $healthJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert($job['status'] === 'failed', 'unsupported command job must fail');
            hub_test_assert((int)$job['exit_code'] === 78, 'unsupported command job exit mismatch');
            hub_test_assert($job['error_code'] === 'platform_target_unsupported', 'unsupported command job error code mismatch');
            hub_test_assert($job['error_message'] === 'linux-docker target is not available on Windows host', 'unsupported command job DB message mismatch');
        }
        foreach ([$restartJobId, $logsJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert($job['status'] === 'success', 'internal-task command job must succeed');
            hub_test_assert((int)$job['exit_code'] === 0, 'internal-task command job exit mismatch');
            hub_test_assert($job['error_code'] === null, 'internal-task command job error code must remain null');
        }
        $internalStateAfter = hub_get_service($workerDb, (int)$internalService['id']);
        hub_test_assert(array_intersect_key($internalStateAfter, array_flip(['enabled', 'status', 'runtime_status'])) === $internalStateBefore, 'internal-task restart/logs must preserve service state');
        $internalLogs = $workerDb->query('SELECT action, output FROM service_logs WHERE service_id = ' . (int)$internalService['id'] . ' ORDER BY id')->fetchAll();
        hub_test_assert($internalLogs === [
            ['action' => 'restart', 'output' => 'internal_task restart no-op'],
            ['action' => 'docker_logs', 'output' => 'internal_task logs no-op'],
        ], 'internal-task restart/logs must record explicit no-op service logs');
        foreach ([$maintenanceJobId, $healthJobId, $restartJobId, $logsJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert(str_starts_with(hub_normalize_host_path((string)$job['stdout_path']), hub_normalize_host_path($workerRoot) . '/'), 'worker stdout log must stay inside the isolated test root');
            hub_test_assert(str_starts_with(hub_normalize_host_path((string)$job['stderr_path']), hub_normalize_host_path($workerRoot) . '/'), 'worker stderr log must stay inside the isolated test root');
        }
    } finally {
        $stmt = null;
        $workerDb = null;
        foreach ($logPaths as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
        foreach ([$workerDbPath, $workerDbPath . '-wal', $workerDbPath . '-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($workerRoot)) {
            @rmdir($workerRoot);
        }
    }
});

hub_test('process environment merges Windows keys case-insensitively', function (): void {
    $environment = hub_process_environment(
        ['PATH' => 'C:\\override', 'AIHUB_TEST' => 'task4'],
        ['Path' => 'C:\\base', 'SystemRoot' => 'C:\\Windows', 'TEMP' => 'C:\\Temp'],
        'Windows'
    );

    hub_test_assert(is_array($environment), 'environment overrides must produce an environment array');
    $pathKeys = array_values(array_filter(array_keys($environment), static fn (string $key): bool => strcasecmp($key, 'PATH') === 0));
    hub_test_assert($pathKeys === ['PATH'], 'Windows environment must contain exactly one PATH key using override spelling');
    hub_test_assert($environment['PATH'] === 'C:\\override', 'explicit PATH override must win');
    hub_test_assert($environment['SystemRoot'] === 'C:\\Windows', 'inherited SystemRoot must survive');
    hub_test_assert($environment['TEMP'] === 'C:\\Temp', 'inherited TEMP must survive');
    hub_test_assert($environment['AIHUB_TEST'] === 'task4', 'explicit environment value must survive');
    hub_test_assert(hub_process_environment([], ['Path' => 'C:\\base'], 'Windows') === null, 'no overrides must preserve proc_open inheritance');
});

hub_test('command runner passes inherited and explicit environment values to a PHP subprocess', function (): void {
    $sentinelName = 'AIHUB_PARENT_SENTINEL';
    $overrideName = 'AIHUB_CHILD_OVERRIDE';
    $originalSentinel = getenv($sentinelName);
    $originalOverride = getenv($overrideName);

    try {
        putenv($sentinelName . '=inherited-value');
        putenv($overrideName . '=parent-value');
        $code = 'echo getenv(' . var_export($sentinelName, true) . ') . "|" . getenv(' . var_export($overrideName, true) . ');';
        $result = hub_run_command([PHP_BINARY, '-r', $code], 10, [$overrideName => 'override-value']);

        hub_test_assert($result['exit_code'] === 0, 'PHP subprocess must exit successfully: ' . $result['output']);
        hub_test_assert($result['stdout'] === 'inherited-value|override-value', 'subprocess must receive inherited and overridden values');
    } finally {
        putenv($originalSentinel === false ? $sentinelName : $sentinelName . '=' . $originalSentinel);
        putenv($originalOverride === false ? $overrideName : $overrideName . '=' . $originalOverride);
    }
});

hub_test('cron loop runs both command and task workers', function (): void {
    $loop = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    hub_test_assert(str_contains($loop, 'scripts/command_worker.php'), 'cron loop must run command worker');
    hub_test_assert(str_contains($loop, 'scripts/task_worker.php'), 'cron loop must run task worker');
    hub_test_assert(str_contains($loop, 'scripts/collect_host_metrics.php'), 'cron loop must refresh host metrics snapshots');
    hub_test_assert(str_contains($loop, 'scripts/fix_permissions.sh'), 'cron loop must auto-repair runtime permissions when needed');
    hub_test_assert(str_contains($loop, 'data/3waaihub.sqlite-wal'), 'cron permission guard must include SQLite WAL file');
    hub_test_assert(str_contains($loop, "stat -c '%G'"), 'cron permission guard must detect wrong runtime group');
    hub_test_assert(str_contains($loop, 'TASK_WORKER_LIMIT'), 'cron loop must expose task worker limit');
});

hub_test('permission fixer repairs deployed source readability without touching runtime model', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/scripts/fix_permissions.sh');
    hub_test_assert(str_contains($script, "-path './.git'"), 'permission fixer must skip .git');
    hub_test_assert(str_contains($script, "-path './data'"), 'permission fixer must keep data runtime handling separate');
    hub_test_assert(str_contains($script, '-type d -exec chmod u+rwx,go+rx'), 'permission fixer must make source directories traversable by PHP-FPM');
    hub_test_assert(str_contains($script, '-type f -exec chmod u+rw,go+r'), 'permission fixer must make source files readable by PHP-FPM');
    hub_test_assert(str_contains($script, '-perm -0100 -exec chmod go+rx'), 'permission fixer must preserve executable scripts for non-owner runners');
});
