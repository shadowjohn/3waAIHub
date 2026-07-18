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
