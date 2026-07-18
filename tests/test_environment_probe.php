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
    foreach (['cron_installed', 'cron_file', 'cron_user', 'cron_line', 'loop_script_exists', 'loop_script_executable', 'flock_available', 'install_command'] as $key) {
        hub_test_assert(($worker[$key] ?? null) === $notApplicable, 'Windows Linux worker field must be N/A: ' . $key);
    }
    $workerScript = HUB_ROOT . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'command_worker.php';
    hub_test_assert(($worker['manual_command'] ?? '') === 'php ' . hub_powershell_single_quoted_literal($workerScript) . ' --limit=5', 'Windows manual command worker command must use a safe PowerShell literal');

    $probeSource = (string)file_get_contents(HUB_ROOT . '/app/environment_probe.php');
    hub_test_assert(str_contains($probeSource, "'docker_group_warning' => \$isWindows ? hub_not_applicable_status()"), 'Windows Docker group warning must use the exact N/A shape');
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

hub_test('Windows environment UI renders N/A neutrally with unambiguous role labels', function (): void {
    foreach (['admin/environment.php', 'admin/index.php', 'admin/settings.php'] as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $file);
        hub_test_assert(str_contains($source, '3waAIHub Core（Control Plane）'), $file . ' missing Core product label');
        hub_test_assert(str_contains($source, 'WSL Runtime（Preview）'), $file . ' missing WSL product label');
        hub_test_assert(!str_contains($source, 'Windows Server Core'), $file . ' contains ambiguous Windows Server Core label');
    }

    $environment = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');
    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
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
    hub_test_assert(str_contains($dashboard, 'metric.ramApplicable && ramChart'), 'dashboard JS must not initialize an N/A RAM chart');
    hub_test_assert(str_contains($dashboard, 'metric.diskBars.length > 0 && diskChart'), 'dashboard JS must not initialize an empty disk chart');
    hub_test_assert(!str_contains($dashboard, "'ramPercent' => hub_dash_percent(\$host['ram_used_percent'] ?? 0)"), 'dashboard must not coerce N/A RAM to zero');
});
