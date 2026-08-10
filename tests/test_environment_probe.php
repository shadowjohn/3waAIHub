<?php
declare(strict_types=1);

hub_test('Windows Linux-only environment probes return exact neutral N/A before Linux access', function (): void {
    $notApplicable = [
        'available' => false,
        'status' => 'not_applicable',
        'reason' => 'not_available_on_windows',
    ];

    hub_test_assert(hub_not_applicable_status() === $notApplicable, 'shared N/A shape mismatch');
    hub_test_assert(
        hub_powershell_single_quoted_literal("D:\\AI Hub\\owner's\\command worker.php") === "'D:\\AI Hub\\owner''s\\command worker.php'",
        'PowerShell single-quoted literal escaping mismatch'
    );

    $memoryReads = 0;
    $memory = hub_memory_status('windows', static function () use (&$memoryReads): array {
        $memoryReads++;
        return [];
    });
    hub_test_assert($memory === $notApplicable, 'Windows /proc/meminfo must be N/A');
    hub_test_assert($memoryReads === 0, 'Windows memory probe must not read /proc/meminfo');

    $loadCalls = 0;
    $load = hub_collect_load_average('windows', static function () use (&$loadCalls): array {
        $loadCalls++;
        return [1.0, 1.0, 1.0];
    });
    hub_test_assert($load === $notApplicable, 'Windows load average must be N/A');
    hub_test_assert($loadCalls === 0, 'Windows load probe must not call sys_getloadavg');

    $vmstatCalls = 0;
    $swap = hub_collect_vmstat_swap_io('windows', static function () use (&$vmstatCalls): array {
        $vmstatCalls++;
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
    });
    hub_test_assert($swap === $notApplicable, 'Windows vmstat swap probe must be N/A');
    hub_test_assert($vmstatCalls === 0, 'Windows swap probe must not invoke vmstat');

    hub_test_assert(hub_current_user_in_group('docker', 'windows') === $notApplicable, 'Windows POSIX group probe must be N/A');

    $worker = hub_collect_command_worker_status('windows');
    foreach (['cron_installed', 'cron_file', 'cron_user', 'cron_line', 'loop_script_exists', 'loop_script_executable', 'flock_available', 'cluster_refresh_configured', 'install_command'] as $key) {
        hub_test_assert(($worker[$key] ?? null) === $notApplicable, 'Windows Linux worker field must be N/A: ' . $key);
    }
    hub_test_assert(str_ends_with((string)($worker['cluster_refresh_log_path'] ?? ''), 'cluster_refresh_1min.log'), 'Windows cluster refresh log path mismatch');
    $workerScript = HUB_ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'command_worker.php';
    hub_test_assert(($worker['manual_command'] ?? '') === 'php ' . hub_powershell_single_quoted_literal($workerScript) . ' --limit=5', 'Windows manual command worker command must use a safe PowerShell literal');

    $probeSource = (string)file_get_contents(HUB_ROOT . '/app/environment_probe.php');
    hub_test_assert(str_contains($probeSource, "'docker_group_warning' => \$isWindows ? hub_not_applicable_status()"), 'Windows Docker group warning must use the exact N/A shape');
});

hub_test('cluster refresh cron has one independently locked attempt per minute', function (): void {
    $loopPath = HUB_ROOT . '/crontab/1min.sh';
    $loop = (string)file_get_contents($loopPath);
    $refreshCall = 'php scripts/cluster_refresh.php';
    hub_test_assert(substr_count($loop, $refreshCall) === 1, 'one-minute cron must invoke cluster refresh exactly once');
    $refreshPosition = strpos($loop, $refreshCall);
    $commandLockPosition = strpos($loop, 'exec 9>');
    hub_test_assert($refreshPosition !== false && $commandLockPosition !== false && $refreshPosition < $commandLockPosition, 'cluster refresh must attempt before the long command-worker lock');
    hub_test_assert(str_contains($loop, 'CLUSTER_REFRESH_LOCK_FILE') && str_contains($loop, 'flock -n 7') && str_contains($loop, 'flock -u 7'), 'cluster refresh must use and release a dedicated nonblocking lock');
    hub_test_assert(str_contains($loop, 'CLUSTER_REFRESH_LOG_PATH') && str_contains($loop, 'tee -a "$CLUSTER_REFRESH_LOG_PATH"'), 'cluster refresh must write its dedicated log while preserving shared cron output');

    $periodicScripts = array_merge(
        glob(HUB_ROOT . '/crontab/*') ?: [],
        glob(HUB_ROOT . '/scripts/*cron*.sh') ?: []
    );
    foreach ($periodicScripts as $path) {
        if (realpath($path) !== realpath($loopPath)) {
            hub_test_assert(!str_contains((string)file_get_contents($path), $refreshCall), 'another periodic script invokes cluster refresh: ' . basename($path));
        }
    }

    $refresh = (string)file_get_contents(HUB_ROOT . '/scripts/cluster_refresh.php');
    $snapshotPosition = strpos($refresh, 'hub_release_snapshot_local_git();');
    $routerGuardPosition = strpos($refresh, 'if (!hub_cluster_router_enabled($db))');
    hub_test_assert($snapshotPosition !== false && $routerGuardPosition !== false && $snapshotPosition < $routerGuardPosition, 'local release snapshot must refresh before the router-disabled exit');
    $stationLoopPosition = strpos($refresh, 'foreach (hub_cluster_refresh_due_stations');
    hub_test_assert(
        str_contains($refresh, 'release_snapshot_failed')
        && str_contains($refresh, 'catch (Throwable)')
        && $stationLoopPosition !== false
        && $snapshotPosition < $stationLoopPosition,
        'release snapshot failure must stay compact and continue to station refresh'
    );

    $worker = hub_collect_command_worker_status('linux');
    foreach (['cluster_refresh_configured', 'cluster_refresh_log_path', 'last_cluster_refresh_log_at'] as $key) {
        hub_test_assert(array_key_exists($key, $worker), 'worker status missing ' . $key);
    }
    hub_test_assert(is_bool($worker['cluster_refresh_configured']), 'Linux cluster refresh configuration must be boolean');
    hub_test_assert(str_ends_with($worker['cluster_refresh_log_path'], '/cluster_refresh_1min.log'), 'cluster refresh log path mismatch');
    hub_test_assert($worker['cluster_refresh_log_path'] !== $worker['log_path'], 'cluster refresh needs a truthful dedicated timestamp source');
    $currentCron = [
        'installed' => true,
        'line' => '* * * * * root ' . HUB_ROOT . '/crontab/1min.sh',
    ];
    hub_test_assert(hub_command_worker_cluster_refresh_configured($currentCron, $loopPath, $loop), 'current checkout cron must be recognized');
    $legacyCron = ['installed' => true, 'line' => '* * * * * root ' . HUB_ROOT . '/scripts/command_worker_loop.sh'];
    hub_test_assert(!hub_command_worker_cluster_refresh_configured($legacyCron, $loopPath, $loop), 'legacy worker cron must not claim Cluster refresh');
    $otherCron = ['installed' => true, 'line' => '* * * * * root /other/3waAIHub/crontab/1min.sh'];
    hub_test_assert(!hub_command_worker_cluster_refresh_configured($otherCron, $loopPath, $loop), 'another checkout cron must not claim Cluster refresh');
    $dailyCron = ['installed' => true, 'line' => '0 0 * * * root ' . HUB_ROOT . '/crontab/1min.sh'];
    hub_test_assert(!hub_command_worker_cluster_refresh_configured($dailyCron, $loopPath, $loop), 'daily cron must not claim per-minute Cluster refresh');
    $backupCron = ['installed' => true, 'line' => '* * * * * root ' . HUB_ROOT . '/crontab/1min.sh.bak'];
    hub_test_assert(!hub_command_worker_cluster_refresh_configured($backupCron, $loopPath, $loop), 'backup loop path must not claim Cluster refresh');

    $installer = (string)file_get_contents(HUB_ROOT . '/scripts/install_command_worker_cron.sh');
    hub_test_assert(str_contains($installer, 'Cluster station refresh'), 'cron installer must report the shared Cluster refresh schedule');
    hub_test_assert(str_contains($installer, 'CLUSTER_REFRESH_LOG_PATH'), 'cron installer must prepare the Cluster refresh log');
    $selfCheck = (string)file_get_contents(HUB_ROOT . '/scripts/bootstrap_self_check.sh');
    hub_test_assert(str_contains($selfCheck, 'cluster refresh cadence'), 'bootstrap self-check must verify Cluster refresh cadence');
});

hub_test('web protection probe reports local server state without network access', function (): void {
    $commands = [];
    $runner = static function (array $command, int $timeoutSeconds) use (&$commands): array {
        $commands[] = $command;
        $active = $command === ['systemctl', 'is-active', 'apache2'];
        return ['exit_code' => $active ? 0 : 3, 'stdout' => $active ? 'active' : 'inactive', 'stderr' => '', 'output' => $active ? 'active' : 'inactive'];
    };
    $htaccess = (string)(@file_get_contents(HUB_ROOT . '/.htaccess') ?: '');
    $linux = hub_collect_web_protection_status('linux', $runner, $htaccess, '');

    hub_test_assert(($linux['apache_active'] ?? false) === true, 'Apache should be active');
    hub_test_assert(($linux['nginx_active'] ?? true) === false, 'nginx should be inactive');
    hub_test_assert(($linux['apache_rules_present'] ?? false) === true, 'Apache protection rules should be present');
    hub_test_assert($commands === [['systemctl', 'is-active', 'apache2'], ['systemctl', 'is-active', 'nginx']], 'web protection probe commands mismatch');

    $fallbackCommands = [];
    $httpdLinux = hub_collect_web_protection_status('linux', static function (array $command, int $timeoutSeconds) use (&$fallbackCommands): array {
        $fallbackCommands[] = $command;
        $active = $command === ['systemctl', 'is-active', 'httpd'];
        return ['exit_code' => $active ? 0 : 3, 'stdout' => $active ? 'active' : 'inactive', 'stderr' => '', 'output' => $active ? 'active' : 'inactive'];
    }, $htaccess, '');
    hub_test_assert(($httpdLinux['apache_active'] ?? false) === true, 'httpd fallback should mark Apache active');
    hub_test_assert(($httpdLinux['nginx_active'] ?? true) === false, 'nginx should remain inactive in the httpd fixture');
    hub_test_assert($fallbackCommands === [['systemctl', 'is-active', 'apache2'], ['systemctl', 'is-active', 'httpd'], ['systemctl', 'is-active', 'nginx']], 'httpd fallback probe commands mismatch');

    $nginxLinux = hub_collect_web_protection_status('linux', static function (array $command, int $timeoutSeconds): array {
        $active = $command === ['systemctl', 'is-active', 'nginx'];
        return ['exit_code' => $active ? 0 : 3, 'stdout' => $active ? 'active' : 'inactive', 'stderr' => '', 'output' => $active ? 'active' : 'inactive'];
    }, 'Options -Indexes', '');
    hub_test_assert(($nginxLinux['apache_active'] ?? true) === false, 'Apache should be inactive in the nginx fixture');
    hub_test_assert(($nginxLinux['nginx_active'] ?? false) === true, 'nginx should be active in the nginx fixture');
    hub_test_assert(($nginxLinux['apache_rules_present'] ?? true) === false, 'incomplete Apache rules must not be reported as present');

    $incompleteHtaccess = str_replace('|\\.bak$', '', $htaccess);
    $incompleteApache = hub_collect_web_protection_status('linux', static fn (): array => [
        'exit_code' => 0,
        'stdout' => 'active',
        'stderr' => '',
        'output' => 'active',
    ], $incompleteHtaccess, '');
    hub_test_assert(($incompleteApache['apache_rules_present'] ?? true) === false, 'Apache rules missing .bak protection must be incomplete');

    $darwinCalls = 0;
    $darwin = hub_collect_web_protection_status('darwin', static function (array $command, int $timeoutSeconds) use (&$darwinCalls): array {
        $darwinCalls++;
        return ['exit_code' => 0, 'stdout' => 'active', 'stderr' => '', 'output' => 'active'];
    }, $htaccess, '');
    hub_test_assert(($darwin['apache_active'] ?? null) === hub_not_applicable_status(), 'Darwin Apache status should be N/A');
    hub_test_assert(($darwin['nginx_active'] ?? null) === hub_not_applicable_status(), 'Darwin nginx status should be N/A');
    hub_test_assert(($darwin['apache_rules_present'] ?? null) === hub_not_applicable_status(), 'Darwin Apache rules should be N/A');
    hub_test_assert($darwinCalls === 0, 'Darwin web protection probe must not run commands');

    $windowsCalls = 0;
    $webConfig = (string)(@file_get_contents(HUB_ROOT . '/web.config') ?: '');
    $windows = hub_collect_web_protection_status('windows', static function (array $command, int $timeoutSeconds) use (&$windowsCalls): array {
        $windowsCalls++;
        return [];
    }, '', $webConfig);

    hub_test_assert(($windows['apache_active'] ?? null) === hub_not_applicable_status(), 'Windows Apache status should be N/A');
    hub_test_assert(($windows['nginx_active'] ?? null) === hub_not_applicable_status(), 'Windows nginx status should be N/A');
    hub_test_assert(($windows['iis_rules_present'] ?? false) === true, 'IIS protection rules should be present');
    hub_test_assert($windowsCalls === 0, 'Windows web protection probe must not run commands');

    $commentedWebConfig = '<configuration><!--' . str_replace(['<?xml version="1.0" encoding="UTF-8"?>', '<configuration>', '</configuration>'], '', $webConfig) . '--></configuration>';
    $commentedWindows = hub_collect_web_protection_status('windows', static fn (): array => [], '', $commentedWebConfig);
    hub_test_assert(($commentedWindows['iis_rules_present'] ?? true) === false, 'IIS rules inside XML comments must not be reported as present');

    $incompleteWebConfig = str_replace('<add segment="docs" />', '', $webConfig);
    $incompleteWindows = hub_collect_web_protection_status('windows', static fn (): array => [], '', $incompleteWebConfig);
    hub_test_assert(($incompleteWindows['iis_rules_present'] ?? true) === false, 'IIS rules missing docs protection must be incomplete');

    $invalidWindowsCalls = 0;
    $invalidWindows = hub_collect_web_protection_status('windows', static function (array $command, int $timeoutSeconds) use (&$invalidWindowsCalls): array {
        $invalidWindowsCalls++;
        return [];
    }, '', '<configuration />');
    hub_test_assert(($invalidWindows['iis_rules_present'] ?? true) === false, 'incomplete IIS rules must not be reported as present');
    hub_test_assert($invalidWindowsCalls === 0, 'Windows incomplete IIS probe must not run commands');
});

hub_test('Nginx protection advisory covers root mounts and location order', function (): void {
    $snippet = hub_web_protection_nginx_snippet();
    hub_test_assert(str_contains($snippet, 'Replace /3waAIHub with a non-root URL prefix, or remove that prefix entirely for a root mount (retain one leading slash).'), 'Nginx snippet missing root mount instruction');
    hub_test_assert(str_contains($snippet, 'Put these regex locations before generic PHP regex locations and do not place them under a conflicting ^~ prefix.'), 'Nginx snippet missing location order warning');
});

hub_test('Windows host metrics keep Linux storage and memory unknown while native GPU stays probeable', function (): void {
    $db = hub_test_reset_db();
    $notApplicable = hub_not_applicable_status();
    $host = hub_collect_host_metric($db, 'windows');

    foreach (['load_1', 'load_5', 'load_15', 'ram_total_mb', 'ram_used_mb', 'ram_buff_cache_mb', 'ram_available_mb', 'ram_used_percent', 'ram_available_percent', 'swap_total_mb', 'swap_used_mb', 'swap_used_percent', 'vmstat_si', 'vmstat_so'] as $key) {
        hub_test_assert(($host[$key] ?? null) === null, 'Windows Linux-derived metric must be null: ' . $key);
    }
    foreach (['load_status', 'memory_status', 'swap_io_status', 'disk_root', 'disk_data'] as $key) {
        hub_test_assert(($host[$key] ?? null) === $notApplicable, 'Windows metric N/A status mismatch: ' . $key);
    }
    hub_test_assert(($host['memory_pressure'] ?? null) === 'not_applicable', 'Windows memory pressure must be neutral');

    $calls = [];
    $gpu = hub_collect_gpu_status(static function (array $command) use (&$calls): array {
        $calls[] = $command;
        $stdout = count($calls) === 1
            ? "NVIDIA GeForce GTX 1080 Ti, 582.42, 11264, 1024, 10240, 3, 45\n"
            : 'NVIDIA-SMI 582.42 CUDA Version: 12.8';
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
    });
    hub_test_assert(($gpu['nvidia_smi_available'] ?? false) === true, 'native nvidia-smi must remain probeable on Windows');
    hub_test_assert(($gpu['name'] ?? '') === 'NVIDIA GeForce GTX 1080 Ti', 'native GPU name mismatch');
    hub_test_assert(($gpu['cuda_version'] ?? '') === '12.8', 'native CUDA version mismatch');
    hub_test_assert(count($calls) === 2 && ($calls[0][0] ?? '') === 'nvidia-smi', 'GPU probe must use command arrays');
});

hub_test('Windows Docker facts do not probe Linux Docker storage or emit Linux repair commands', function (): void {
    foreach (['/var/lib/docker', '/DATA/docker'] as $dockerRoot) {
        $docker = hub_collect_docker_metric('windows', static function (array $command) use ($dockerRoot): array {
            $stdout = $command === ['docker', 'info']
                ? "Server:\n Docker Root Dir: {$dockerRoot}\n Runtimes: io.containerd.runc.v2 nvidia runc\n"
                : 'available';
            return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
        });

        hub_test_assert(($docker['available'] ?? false) === true, 'Docker CLI fact must remain visible');
        hub_test_assert(($docker['daemon_reachable'] ?? false) === true, 'Docker daemon fact must remain visible');
        hub_test_assert(($docker['root_dir'] ?? '') === $dockerRoot, 'Docker root host fact mismatch');
        hub_test_assert(($docker['root_status'] ?? null) === hub_not_applicable_status(), 'Linux Docker root status must be N/A on Windows');
        hub_test_assert(($docker['root_free_gb'] ?? null) === null, 'Windows must not invent Linux Docker root free space');
        hub_test_assert(($docker['warning'] ?? '') === '', 'Windows must not emit Linux Docker root warnings');
    }

    $missingDocker = hub_collect_docker_metric('windows', static fn (): array => [
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => 'not found',
        'output' => 'not found',
    ]);
    hub_test_assert(($missingDocker['root_status'] ?? null) === hub_not_applicable_status(), 'missing Docker still leaves Linux root status N/A on Windows');

    $suggestions = hub_host_metric_fix_suggestions([
        'gpu' => ['available' => true],
        'docker' => [
            'daemon_reachable' => false,
            'reason' => 'permission denied at unix:///var/run/docker.sock',
            'compose_available' => false,
            'nvidia_container_toolkit' => false,
            'nvidia_runtime_available' => false,
        ],
    ], 'www-data', 'windows');
    hub_test_assert($suggestions === [], 'Windows Core must not emit Linux repair commands');
});

hub_test('service GPU metrics sum only compute PIDs owned by a running Linux service', function (): void {
    $service = [
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'compose_project' => '3waaihub_ocr_gpu',
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ];
    $commands = [];
    $rows = hub_collect_service_gpu_metrics([$service], static function (array $command, int $timeoutSeconds) use (&$commands): array {
        $commands[] = $command;
        $stdout = match ($command) {
            ['nvidia-smi', '--query-compute-apps=pid,used_memory', '--format=csv,noheader,nounits'] => "101, 1024\n202, 512\n303, 99\n",
            ['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=3waaihub_ocr_gpu'] => "abcdef123456\n",
            ['docker', 'top', 'abcdef123456', '-eo', 'pid'] => "PID\n101\n202\n",
            default => throw new RuntimeException('unexpected command: ' . json_encode($command)),
        };
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
    }, 'linux');

    hub_test_assert($rows === [[
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'vram_used_mb' => 1536,
        'measured' => true,
    ]], 'only GPU PIDs inside the registered service container may be summed');
    hub_test_assert(count($commands) === 3, 'Linux service GPU collection must inspect GPU, project containers, and container PIDs');
});

hub_test('service GPU metrics record zero only after successful unmatched PID inspection', function (): void {
    $service = [
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'compose_project' => '3waaihub_ocr_gpu',
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ];
    $rows = hub_collect_service_gpu_metrics([$service], static function (array $command, int $timeoutSeconds): array {
        $stdout = match ($command) {
            ['nvidia-smi', '--query-compute-apps=pid,used_memory', '--format=csv,noheader,nounits'] => "501, 444\n",
            ['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=3waaihub_ocr_gpu'] => "abcdef123456\n",
            ['docker', 'top', 'abcdef123456', '-eo', 'pid'] => "PID\n601\n",
            default => throw new RuntimeException('unexpected command: ' . json_encode($command)),
        };
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
    }, 'linux');

    hub_test_assert($rows === [[
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'vram_used_mb' => 0,
        'measured' => true,
    ]], 'a complete inspection with no owned GPU PID must retain measured zero');
});

hub_test('service GPU metrics omit unverifiable service measurements', function (): void {
    $service = [
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'compose_project' => '3waaihub_ocr_gpu',
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ];
    $gpuCommand = ['nvidia-smi', '--query-compute-apps=pid,used_memory', '--format=csv,noheader,nounits'];
    $dockerPs = ['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=3waaihub_ocr_gpu'];
    $dockerTop = ['docker', 'top', 'abcdef123456', '-eo', 'pid'];
    $cases = [
        ['name' => 'non-integer GPU PID', 'responses' => [json_encode($gpuCommand) => "oops, 512\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n101\n"]],
        ['name' => 'non-integer memory', 'responses' => [json_encode($gpuCommand) => "101, 512.5\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n101\n"]],
        ['name' => 'negative memory', 'responses' => [json_encode($gpuCommand) => "101, -1\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n101\n"]],
        ['name' => 'oversized memory', 'responses' => [json_encode($gpuCommand) => "101, 1000000001\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n101\n"]],
        ['name' => 'oversized GPU PID', 'responses' => [json_encode($gpuCommand) => "1000000001, 512\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n1000000001\n"]],
        ['name' => 'non-integer container PID', 'responses' => [json_encode($gpuCommand) => "101, 512\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\nnot-a-pid\n"]],
        ['name' => 'oversized container PID', 'responses' => [json_encode($gpuCommand) => "1000000001, 512\n", json_encode($dockerPs) => "abcdef123456\n", json_encode($dockerTop) => "PID\n1000000001\n"]],
        ['name' => 'no container', 'responses' => [json_encode($gpuCommand) => "101, 512\n", json_encode($dockerPs) => '']],
        ['name' => 'command failure', 'responses' => [json_encode($gpuCommand) => ['exit_code' => 1, 'stdout' => '', 'stderr' => 'failed', 'output' => 'failed']]],
    ];

    foreach ($cases as $case) {
        $name = $case['name'];
        $responses = $case['responses'];
        $rows = hub_collect_service_gpu_metrics([$service], static function (array $command, int $timeoutSeconds) use ($responses): array {
            $key = json_encode($command);
            $response = $responses[$key] ?? null;
            if (is_array($response) && array_key_exists('exit_code', $response)) {
                return $response;
            }
            if (!is_string($response)) {
                throw new RuntimeException('unexpected command: ' . $key);
            }
            return ['exit_code' => 0, 'stdout' => $response, 'stderr' => '', 'output' => $response];
        }, 'linux');
        hub_test_assert($rows === [], 'unverifiable service GPU measurement must be omitted: ' . $name);
    }
});

hub_test('service GPU metrics use the configured WSL runtime and never Windows host inspection', function (): void {
    $service = [
        'pack_id' => 'taiwan-address',
        'service_key' => 'taiwan-address-gpu',
        'mode' => 'taiwan_address_gpu',
        'compose_project' => '3waaihub_taiwan_address_gpu',
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ];
    $profile = ['runtime_targets' => [
        'windows-wsl2-linux-docker' => [
            'supported' => true,
            'distro' => 'Ubuntu-24.04',
            'runtime_root' => '/DATA/3waAIHub-runtime',
        ],
    ]];
    $commands = [];
    $rows = hub_collect_service_gpu_metrics([$service], static function (array $command, int $timeoutSeconds) use (&$commands): array {
        $commands[] = $command;
        hub_test_assert(($command[0] ?? '') === 'powershell.exe', 'WSL inspection must use PowerShell to invoke the configured distro');
        $powershell = (string)end($command);
        hub_test_assert(str_contains($powershell, "-d 'Ubuntu-24.04'"), 'WSL inspection must use the configured distro');
        hub_test_assert(preg_match('/printf %s ([A-Za-z0-9+\\/=]+) \\| base64 -d \\| bash/', $powershell, $matches) === 1, 'WSL inspection must use the shared script command wrapper');
        $script = base64_decode($matches[1], true);
        hub_test_assert($script !== false, 'WSL inspection script must be decodable');
        $stdout = match (true) {
            str_contains($script, "exec 'nvidia-smi'") => "101, 1536\n",
            str_contains($script, "exec 'docker' 'ps' '-q'") => "abcdef123456\n",
            str_contains($script, "exec 'docker' 'top' 'abcdef123456' '-eo' 'pid'") => "PID\n101\n",
            default => throw new RuntimeException('unexpected WSL script: ' . $script),
        };
        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
    }, 'windows', $profile);

    hub_test_assert($rows === [[
        'service_key' => 'taiwan-address-gpu',
        'mode' => 'taiwan_address_gpu',
        'vram_used_mb' => 1536,
        'measured' => true,
    ]], 'WSL service GPU metrics must use only the configured Linux runtime view');
    hub_test_assert(count($commands) === 3, 'WSL service must issue all inspections through the configured distro');

    $nativeWindowsCalls = 0;
    $nativeWindows = hub_collect_service_gpu_metrics([[
        'service_key' => 'native-windows',
        'mode' => 'native_windows',
        'compose_project' => '3waaihub_native_windows',
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ]], static function () use (&$nativeWindowsCalls): array {
        $nativeWindowsCalls++;
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
    }, 'windows', []);
    hub_test_assert($nativeWindows === [], 'unsupported native Windows service GPU inspection must remain unknown');
    hub_test_assert($nativeWindowsCalls === 0, 'unsupported native Windows service must not invoke host nvidia-smi or Docker');
});

hub_test('Windows environment UI renders N/A neutrally with unambiguous role labels', function (): void {
    foreach (['admin/environment.php', 'admin/index.php', 'admin/settings.php'] as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $file);
        hub_test_assert(str_contains($source, '3waAIHub Core（Control Plane）'), $file . ' missing Core product label');
        hub_test_assert(str_contains($source, 'WSL Runtime（Preview）'), $file . ' missing WSL product label');
        hub_test_assert(!str_contains($source, 'Windows Server Core'), $file . ' contains ambiguous Windows Server Core label');
    }

    $environment = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');
    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    $dashboardJs = (string)file_get_contents(HUB_ROOT . '/assets/js/admin-dashboard.js');
    hub_test_assert(str_contains($environment, "status'] ?? '') === 'not_applicable'"), 'environment UI must detect N/A shape');
    hub_test_assert(str_contains($environment, 'class="muted"'), 'environment N/A must use neutral styling');
    hub_test_assert(!str_contains($environment, '-Mode '), 'environment UI must not advertise uncommitted installer modes');
    hub_test_assert(!str_contains($environment, '-InstallRoot'), 'environment UI must not advertise uncommitted installer path parameters');
    hub_test_assert(str_contains($environment, '.\\\\install.ps1 -Check'), 'environment UI missing current installer check command');
    hub_test_assert(str_contains($environment, 'wsl.exe --status'), 'environment UI missing read-only WSL status command');
    hub_test_assert(str_contains($environment, 'wsl.exe --list --verbose'), 'environment UI missing read-only WSL distro command');
    hub_test_assert(str_contains($dashboard, "memory_pressure'] ?? 'not_applicable'"), 'dashboard must default memory pressure to N/A');
    hub_test_assert(str_contains($dashboard, '$memoryApplicable'), 'dashboard must gate RAM visualization by N/A status');
    hub_test_assert(str_contains($dashboard, '$linuxDiskApplicable'), 'dashboard must gate Linux filesystem labels by N/A status');
    hub_test_assert(str_contains($dashboard, '$dockerRootApplicable'), 'dashboard must gate Docker root visualization by N/A status');
    hub_test_assert(str_contains($dashboardJs, 'metric.ramApplicable && ramChart'), 'dashboard JS must not initialize an N/A RAM chart');
    hub_test_assert(str_contains($dashboardJs, 'metric.diskBars.length > 0 && diskChart'), 'dashboard JS must not initialize an empty disk chart');
    hub_test_assert(!str_contains($dashboard, "'ramPercent' => hub_dash_percent(\$host['ram_used_percent'] ?? 0)"), 'dashboard must not coerce N/A RAM to zero');
});

hub_test('release status stays read-only in Environment and Settings', function (): void {
    $environment = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');
    $settings = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');

    foreach ([$environment, $settings] as $source) {
        hub_test_assert(str_contains($source, 'hub_release_'), 'admin release view must use the shared helper');
        hub_test_assert(!str_contains($source, 'check_release_update.php'), 'web UI must not invoke the CLI update checker');
    }
    hub_test_assert(str_contains($environment, "hub_i18n_text('版本與節點相容性')"), 'Environment release heading must use i18n');
    hub_test_assert(substr_count($environment, 'class="table-wrap"') >= 2, 'Environment release tables need responsive wrappers');
    hub_test_assert(str_contains($environment, 'hub_release_status_label'), 'Environment station health must use localized labels');
    hub_test_assert(str_contains($settings, "hub_i18n_text('唯讀更新指引')"), 'Settings update heading must use i18n');

    $baseCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-base.css');
    hub_test_assert(str_contains($baseCss, 'body.app .table-wrap'), 'shared admin CSS must contain responsive table wrapping');
    $shellCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-shell.css');
    hub_test_assert(str_contains($shellCss, 'overflow-x: hidden'), 'closed mobile drawer must not widen the page');
});
