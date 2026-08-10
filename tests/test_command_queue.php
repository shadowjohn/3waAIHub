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

hub_test('command runner bypasses cmd.exe on Windows', function (): void {
    hub_test_assert(hub_process_execution_options('Windows') === ['bypass_shell' => true], 'Windows commands must bypass cmd.exe');
    hub_test_assert(hub_process_execution_options('Linux') === [], 'Linux argv execution must not add Windows-only options');
});

hub_test('command runner validates argv and Ollama model references before process launch', function (): void {
    hub_test_assert(hub_valid_argv(['docker', 'system', 'df']), 'fixed argv command must be accepted');
    hub_test_assert(!hub_valid_argv(['docker', "system\0df"]), 'NUL argv argument must be rejected');
    hub_test_assert(!hub_valid_argv(['docker', "system\r\ndf"]), 'control characters must be rejected from argv arguments');
    hub_test_assert(!hub_valid_argv(['cmd.exe', '/c', 'whoami']), 'unapproved executable must be rejected before process launch');
    hub_test_assert(!hub_valid_argv(['docker' => 'system']), 'non-list argv command must be rejected');
    hub_test_assert(hub_safe_argv(['docker', 'system', 'df']) === ['docker', 'system', 'df'], 'safe argv boundary must preserve validated argv');
    hub_test_assert(hub_test_throws(static fn () => hub_safe_argv(['cmd.exe', '/c', 'whoami'])), 'safe argv boundary must reject unapproved commands');
    hub_test_assert(hub_ollama_model_reference('translategemma:12b-it-q4_K_M') === 'translategemma:12b-it-q4_K_M', 'valid Ollama model reference changed');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_reference('--config=/tmp/evil')), 'Ollama option prefix must be rejected');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_reference('model name')), 'Ollama whitespace must be rejected');
});

hub_test('resident reconciliation uses a prepared read before building runtime commands', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/pack_job_runner.php');
    $start = strpos($source, 'function hub_reconcile_resident_job_runs');
    $end = strpos($source, 'function hub_run_pack_job_task', $start === false ? 0 : $start);
    $body = $start === false || $end === false ? '' : substr($source, $start, $end - $start);

    hub_test_assert($body !== '', 'resident reconciliation implementation must remain discoverable');
    hub_test_assert(!str_contains($body, '$db->query('), 'resident reconciliation must not use query() as a database-to-command source');
    hub_test_assert(str_contains($body, '$statement = $db->prepare('), 'resident reconciliation must use a named prepared statement');
    hub_test_assert(str_contains($body, '$statement->execute();'), 'resident reconciliation prepared statement must execute before rows are read');
});

hub_test('service command contract derives runtime fields from the declared Pack', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'hello', ['idempotent' => true, 'provision_runner' => false])['service'];
    $contract = hub_service_command_contract($db, $service);

    hub_test_assert($contract['compose_file'] === hub_pack_compose_file($db, (string)$service['service_key']), 'runtime contract must derive the managed compose path');
    hub_test_assert($contract['compose_project'] === hub_compose_project_for_instance(hub_get_pack('hello')['manifest'], (string)$service['service_key']), 'runtime contract must derive the Pack compose project');

    $tampered = $service;
    $tampered['compose_file'] = 'C:/untrusted/docker-compose.yml';
    hub_test_assert(hub_test_throws(static fn (): array => hub_service_command_contract($db, $tampered)), 'runtime contract must reject a database compose path that is not declared by the Pack');
});

hub_test('command queue recovers stale running jobs without touching active long jobs', function (): void {
    $db = hub_test_reset_db();
    $now = '2030-01-01 12:00:00';
    $staleJobId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $freshJobId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $longJobId = hub_enqueue_command_job($db, 'ollama_model_pull', null, [], null, '127.0.0.1');
    $update = $db->prepare(
        'UPDATE command_jobs
         SET status = :status, lock_token = :lock_token, started_at = :started_at, updated_at = :updated_at
         WHERE id = :id'
    );
    foreach ([
        [$staleJobId, 'stale-lock', '2030-01-01 11:00:00'],
        [$freshJobId, 'fresh-lock', '2030-01-01 11:59:30'],
        [$longJobId, 'long-lock', '2030-01-01 10:00:00'],
    ] as [$jobId, $lockToken, $updatedAt]) {
        $update->execute([
            ':status' => 'running',
            ':lock_token' => $lockToken,
            ':started_at' => $updatedAt,
            ':updated_at' => $updatedAt,
            ':id' => $jobId,
        ]);
    }

    hub_test_assert(hub_recover_stale_command_jobs($db, $now) === 1, 'exactly one stale job must be recovered');

    $stale = hub_get_command_job($db, $staleJobId);
    hub_test_assert($stale['status'] === 'failed', 'stale job must become failed');
    hub_test_assert((int)$stale['exit_code'] === 1, 'stale job exit code mismatch');
    hub_test_assert($stale['error_code'] === 'worker_lost', 'stale job error code mismatch');
    hub_test_assert($stale['error_message'] === 'Command worker lease expired before completion.', 'stale job message mismatch');
    hub_test_assert($stale['finished_at'] === $now, 'stale job finished time mismatch');
    hub_test_assert($stale['lock_token'] === null, 'stale job lock must be released');

    foreach ([[$freshJobId, 'fresh-lock'], [$longJobId, 'long-lock']] as [$jobId, $lockToken]) {
        $job = hub_get_command_job($db, $jobId);
        hub_test_assert($job['status'] === 'running', 'active job must stay running');
        hub_test_assert($job['lock_token'] === $lockToken, 'active job lock must remain intact');
    }
});

hub_test('command queue selector claims an eligible job without consuming an ineligible one', function (): void {
    $db = hub_test_reset_db();
    $firstId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $secondId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');

    $claimed = hub_claim_next_command_job(
        $db,
        static fn (array $candidate): bool => (int)$candidate['id'] === $secondId
    );

    hub_test_assert((int)($claimed['id'] ?? 0) === $secondId, 'selector must claim the first eligible queued job');
    hub_test_assert(hub_get_command_job($db, $firstId)['status'] === 'queued', 'ineligible job must remain queued for its worker');
    hub_test_assert(hub_get_command_job($db, $secondId)['status'] === 'running', 'eligible job must be claimed');
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
        $removableService = hub_install_pack($workerDb, 'whisper-asr', ['service_key' => 'worker-remove-asr'])['service'];
        $maintenanceJobId = hub_enqueue_command_job($workerDb, 'docker_prune_check', null, [], null, '127.0.0.1');
        $healthJobId = hub_enqueue_command_job($workerDb, 'service_health_check', (int)$service['id'], [], null, '127.0.0.1');
        $restartJobId = hub_enqueue_command_job($workerDb, 'service_restart', (int)$internalService['id'], [], null, '127.0.0.1');
        $logsJobId = hub_enqueue_command_job($workerDb, 'service_logs_collect', (int)$internalService['id'], [], null, '127.0.0.1');
        $removeJobId = hub_enqueue_command_job($workerDb, 'service_remove', (int)$removableService['id'], [], null, '127.0.0.1');
        foreach ([$maintenanceJobId, $healthJobId, $restartJobId, $logsJobId, $removeJobId] as $jobId) {
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
            [PHP_BINARY, HUB_ROOT . '/scripts/command_worker.php', '--limit=5'],
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
        $removeJob = hub_get_command_job($workerDb, $removeJobId);
        hub_test_assert($removeJob['status'] === 'success' && (int)$removeJob['exit_code'] === 0, 'stopped unsupported service removal must bypass the Windows Docker gate');
        hub_test_assert(hub_get_service($workerDb, (int)$removableService['id']) === null, 'stopped unsupported service must be removed by the command worker');
        $internalLogs = $workerDb->query('SELECT action, output FROM service_logs WHERE service_id = ' . (int)$internalService['id'] . ' ORDER BY id')->fetchAll();
        hub_test_assert($internalLogs === [
            ['action' => 'restart', 'output' => 'internal_task restart no-op'],
            ['action' => 'docker_logs', 'output' => 'internal_task logs no-op'],
        ], 'internal-task restart/logs must record explicit no-op service logs');
        foreach ([$maintenanceJobId, $healthJobId, $restartJobId, $logsJobId, $removeJobId] as $jobId) {
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
    hub_test_assert(
        str_contains($loop, 'FACEBOOK_PROFILE_ROOT')
        && str_contains($loop, 'detect_runtime_user')
        && str_contains($loop, 'WEB_USER')
        && str_contains($loop, "stat -c '%U'")
        && str_contains($loop, '! -perm 0700')
        && str_contains($loop, '! -perm 0600')
        && str_contains($loop, '! -links 1'),
        'cron permission guard must detect an inaccessible or non-private Facebook profile tree'
    );
    hub_test_assert(str_contains($loop, 'TASK_WORKER_LIMIT'), 'cron loop must expose task worker limit');
    hub_test_assert(str_contains($loop, 'TASK_WORKER_TICKS="${TASK_WORKER_TICKS:-100}"') && str_contains($loop, 'TASK_WORKER_SLEEP="${TASK_WORKER_SLEEP:-0.5}"'), 'cron task loop must poll at the configured half-second cadence');
    hub_test_assert(str_contains($loop, 'task_worker_pid=$!') && str_contains($loop, 'wait "$task_worker_pid"'), 'cron loop must wait for its background task worker loop');
    hub_test_assert(
        strpos($loop, 'task_worker_pid=$!') < strpos($loop, 'php scripts/command_worker.php'),
        'task worker loop must start before command worker ticks so queued inference does not wait behind command work'
    );
});

hub_test('task claim filter leaves incompatible queued work for its matching worker', function (): void {
    $db = hub_test_reset_db();
    $skippedId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'skip'], null, '127.0.0.1');
    $claimedId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'claim'], null, '127.0.0.1');
    $task = hub_claim_next_task($db, ['demo_task'], static fn (array $candidate): bool => (int)$candidate['id'] === $claimedId);

    hub_test_assert((int)($task['id'] ?? 0) === $claimedId, 'filtered task worker must claim its compatible task');
    hub_test_assert((hub_get_task($db, $skippedId)['status'] ?? null) === 'queued', 'filtered task worker must not claim incompatible queued work');
});

hub_test('permission fixer repairs deployed source readability without touching runtime model', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/scripts/fix_permissions.sh');
    hub_test_assert(str_contains($script, "-path './.git'"), 'permission fixer must skip .git');
    hub_test_assert(str_contains($script, "-path './data'"), 'permission fixer must keep data runtime handling separate');
    hub_test_assert(str_contains($script, '-type d -exec chmod u+rwx,go+rx'), 'permission fixer must make source directories traversable by PHP-FPM');
    hub_test_assert(str_contains($script, '-type f -exec chmod u+rw,go+r'), 'permission fixer must make source files readable by PHP-FPM');
    hub_test_assert(str_contains($script, '-perm -0100 -exec chmod go+rx'), 'permission fixer must preserve executable scripts for non-owner runners');
});

hub_test('permission fixer preserves private Facebook profile modes and web ownership', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile permission repair requires Linux ownership semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_permission_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $profileRoot = $root . '/data/facebook-crawler/profiles';
    $profileDir = $profileRoot . '/fbp_' . str_repeat('a', 48);
    $statePath = $profileDir . '/storage_state.json';
    if (!mkdir($scriptDir, 0700, true) || !mkdir($profileDir, 0775, true)) {
        throw new RuntimeException('Cannot create permission repair fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($statePath, "{}\n");
    chmod($profileRoot, 0775);
    chmod($profileDir, 0775);
    chmod($statePath, 0600);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run permission repair fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $statePath);

        hub_test_assert($exitCode === 0, 'permission repair fixture failed: ' . trim($output));
        hub_test_assert((fileperms($profileRoot) & 0777) === 0700, 'Facebook profile root must remain 0700 after repair');
        hub_test_assert((fileperms($profileDir) & 0777) === 0700, 'Facebook profile directory must remain 0700 after repair');
        hub_test_assert((fileperms($statePath) & 0777) === 0600, 'Facebook storage state must remain 0600 after repair');
        hub_test_assert(fileowner($profileRoot) === posix_geteuid() && fileowner($statePath) === posix_geteuid(), 'private profile tree must remain owned by the runtime user');
        hub_test_assert(is_readable($statePath) && is_writable($statePath) && is_executable($profileDir), 'runtime owner must retain private profile access');

        $source = (string)file_get_contents($scriptDir . '/fix_permissions.sh');
        hub_test_assert(str_contains($source, 'WEB_USER="${WEB_USER:-}"'), 'root repair must support an explicit web owner');
        hub_test_assert(str_contains($source, 'detect_web_user'), 'root repair must conservatively detect the web owner');
        hub_test_assert(str_contains($source, 'Cannot determine a usable web runtime owner'), 'root repair must fail closed without a usable web owner');
        hub_test_assert(str_contains($source, "chown --"), 'root repair must terminate chown option parsing');
        hub_test_assert(str_contains($source, "chgrp --"), 'root repair must terminate chgrp option parsing');
        hub_test_assert(!str_contains($source, 'find "$FACEBOOK_PROFILE_ROOT" -type f -exec chmod'), 'permission repair must never mutate an existing browser-state inode');
    } finally {
        hub_test_remove_data_tree($root);
    }
});

hub_test('permission fixer rejects external Facebook state hardlinks without mutation', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile hardlink repair requires Linux inode semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_hardlink_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $profileDir = $root . '/data/facebook-crawler/profiles/fbp_' . str_repeat('b', 48);
    $statePath = $profileDir . '/storage_state.json';
    $outside = tempnam(sys_get_temp_dir(), 'facebook_state_outside_');
    if ($outside === false || !mkdir($scriptDir, 0700, true) || !mkdir($profileDir, 0700, true)) {
        throw new RuntimeException('Cannot create hardlink permission fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($outside, 'outside-private-state');
    chmod($outside, 0640);
    if (!link($outside, $statePath)) {
        throw new RuntimeException('Cannot create external Facebook state hardlink.');
    }
    $before = lstat($outside);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run hardlink permission fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $outside);
        $after = lstat($outside);

        hub_test_assert($exitCode !== 0, 'permission repair must fail on a multiply linked state file: ' . trim($output));
        hub_test_assert(is_array($before) && is_array($after), 'outside hardlink inode must remain available');
        foreach (['uid', 'gid', 'mode', 'nlink', 'size'] as $field) {
            hub_test_assert($after[$field] === $before[$field], 'outside hardlink ' . $field . ' must remain unchanged');
        }
        hub_test_assert(file_get_contents($outside) === 'outside-private-state', 'outside hardlink content must remain unchanged');
    } finally {
        hub_test_remove_data_tree($root);
        @unlink($outside);
    }
});

hub_test('permission fixer rejects a symlinked Facebook profile parent without external mutation', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile symlink repair requires Linux link semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_parent_link_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $dataDir = $root . '/data';
    $outside = sys_get_temp_dir() . '/3waaihub_parent_link_outside_' . bin2hex(random_bytes(16));
    $marker = $outside . '/marker.txt';
    if (!mkdir($scriptDir, 0700, true) || !mkdir($dataDir, 0700, true) || !mkdir($outside, 0750, true)) {
        throw new RuntimeException('Cannot create parent symlink permission fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($marker, 'outside-parent-state');
    chmod($marker, 0640);
    if (!symlink($outside, $dataDir . '/facebook-crawler')) {
        throw new RuntimeException('Cannot create Facebook profile parent symlink.');
    }
    $before = lstat($marker);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run parent symlink permission fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $marker);
        $after = lstat($marker);

        hub_test_assert($exitCode !== 0, 'permission repair must fail on a symlinked profile parent: ' . trim($output));
        hub_test_assert(is_array($before) && $after === $before, 'external parent marker metadata must remain unchanged');
        hub_test_assert(file_get_contents($marker) === 'outside-parent-state', 'external parent marker content must remain unchanged');
    } finally {
        hub_test_remove_data_tree($root);
        hub_test_remove_data_tree($outside);
    }
});
