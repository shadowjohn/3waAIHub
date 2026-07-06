<?php
declare(strict_types=1);

function hub_save_env_snapshot(PDO $db, array $snapshot, string $status = 'ok', ?string $errorMessage = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO env_snapshots (snapshot_json, status, error_message, created_at)
         VALUES (:snapshot_json, :status, :error_message, :created_at)'
    );
    $stmt->execute([
        ':snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => $status,
        ':error_message' => $errorMessage,
        ':created_at' => hub_now(),
    ]);
}

function hub_latest_env_snapshot(PDO $db): ?array
{
    $snapshot = $db->query('SELECT * FROM env_snapshots ORDER BY id DESC LIMIT 1')->fetch();
    if (!$snapshot) {
        return null;
    }

    $snapshot['data'] = json_decode((string)$snapshot['snapshot_json'], true) ?: [];
    return $snapshot;
}

function hub_collect_env_snapshot(): array
{
    hub_cli_only();

    $dockerVersion = hub_run_command(['docker', '--version'], 10);
    $composeVersion = hub_run_command(['docker', 'compose', 'version'], 10);
    $dockerInfo = hub_run_command(['docker', 'info'], 15);
    $dockerDisk = hub_run_command(['docker', 'system', 'df'], 20);
    $dockerRootDir = $dockerInfo['exit_code'] === 0 ? hub_parse_docker_root_dir($dockerInfo['stdout']) : '';
    $dockerRootFree = $dockerRootDir !== '' ? @disk_free_space($dockerRootDir) : null;
    return [
        'host' => [
            'hostname' => gethostname() ?: '',
            'os_kernel' => php_uname(),
            'php_version' => PHP_VERSION,
            'app_path' => HUB_ROOT,
            'app_user' => get_current_user(),
            'server_user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : '',
            'sqlite_path' => HUB_DB_PATH,
            'sqlite_writable' => is_writable(HUB_DB_PATH) || (!file_exists(HUB_DB_PATH) && is_writable(dirname(HUB_DB_PATH))),
            'docker_group_warning' => 'Users in the docker group effectively have root-equivalent control of the host.',
            'current_user_in_docker_group' => hub_current_user_in_group('docker'),
        ],
        'docker' => [
            'docker_installed' => $dockerVersion['exit_code'] === 0,
            'docker_compose_installed' => $composeVersion['exit_code'] === 0,
            'docker_version' => $dockerVersion['stdout'],
            'compose_version' => $composeVersion['stdout'],
            'daemon_reachable' => $dockerInfo['exit_code'] === 0,
            'docker_root_dir' => $dockerRootDir,
            'docker_root_free_bytes' => $dockerRootFree,
            'docker_root_warning' => hub_docker_root_warning($dockerRootDir, $dockerRootFree),
            'docker_root_status' => $dockerRootDir === '/DATA/docker' ? 'PASS' : '',
            'docker_error' => $dockerVersion['exit_code'] === 0 ? null : hub_command_error_summary($dockerVersion),
            'compose_error' => $composeVersion['exit_code'] === 0 ? null : hub_command_error_summary($composeVersion),
            'daemon_error' => $dockerInfo['exit_code'] === 0 ? null : hub_command_error_summary($dockerInfo),
            'disk_usage' => $dockerDisk['stdout'],
        ],
        'gpu_cuda' => hub_collect_gpu_status(),
        'storage' => hub_collect_storage_status(),
        'memory' => hub_memory_status(),
        'disk' => [
            'project_total_bytes' => disk_total_space(HUB_ROOT),
            'project_free_bytes' => disk_free_space(HUB_ROOT),
            'project_used_bytes' => disk_total_space(HUB_ROOT) - disk_free_space(HUB_ROOT),
            'data_writable' => is_writable(HUB_DATA_DIR),
            'logs_writable' => is_writable(HUB_LOG_DIR),
            'packs_readable' => is_readable(HUB_ROOT . '/packs'),
        ],
        'command_worker' => hub_collect_command_worker_status(),
    ];
}

function hub_collect_storage_status(): array
{
    $db = hub_db();
    $paths = hub_get_storage_paths($db);
    $status = [];
    foreach (['AIHUB_MODELS_DIR', 'AIHUB_CACHE_DIR', 'AIHUB_UPLOADS_DIR', 'AIHUB_RESULTS_DIR', 'AIHUB_LOGS_DIR'] as $key) {
        $usage = hub_get_disk_usage_for_path($paths[$key]);
        $status[$key] = $paths[$key];
        $status[$key . '_exists'] = $usage['exists'];
        $status[$key . '_readable'] = $usage['readable'];
        $status[$key . '_writable'] = $usage['writable'];
        $status[$key . '_total_bytes'] = $usage['total_bytes'];
        $status[$key . '_free_bytes'] = $usage['free_bytes'];
    }
    $status['AIHUB_DOCKER_PORT_START'] = $paths['AIHUB_DOCKER_PORT_START'];
    $status['AIHUB_DOCKER_PORT_END'] = $paths['AIHUB_DOCKER_PORT_END'];

    return $status;
}

function hub_parse_docker_root_dir(string $dockerInfo): string
{
    foreach (preg_split('/\R/', $dockerInfo) ?: [] as $line) {
        if (preg_match('/^\s*Docker Root Dir:\s*(.+)\s*$/i', $line, $matches)) {
            return trim($matches[1]);
        }
    }

    return '';
}

function hub_collect_gpu_status(): array
{
    $nvidia = hub_run_command([
        'nvidia-smi',
        '--query-gpu=name,driver_version,memory.total,memory.used,memory.free,utilization.gpu,temperature.gpu',
        '--format=csv,noheader,nounits',
    ], 10);

    $gpu = [
        'nvidia_smi_available' => $nvidia['exit_code'] === 0,
        'nvidia_smi_exit_code' => $nvidia['exit_code'],
    ];
    if ($nvidia['exit_code'] !== 0) {
        $gpu['nvidia_smi_error'] = hub_command_error_summary($nvidia);
        return $gpu;
    }

    $gpu += hub_parse_nvidia_gpu_row(explode("\n", trim($nvidia['stdout']))[0] ?? '');
    $cuda = hub_run_command(['nvidia-smi'], 10);
    if ($cuda['exit_code'] === 0 && preg_match('/CUDA Version:\s*([0-9.]+)/', $cuda['stdout'], $matches)) {
        $gpu['cuda_version'] = $matches[1];
    } else {
        $gpu['cuda_version_reason'] = $cuda['output'] ?: 'CUDA version not reported by nvidia-smi.';
    }

    return $gpu;
}

function hub_command_error_summary(array $result): string
{
    return (string)(trim((string)($result['stderr'] ?? '')) ?: trim((string)($result['stdout'] ?? '')) ?: trim((string)($result['output'] ?? '')) ?: 'Command failed.');
}

function hub_parse_nvidia_gpu_row(string $row): array
{
    $parts = array_map('trim', explode(',', $row));

    return [
        'name' => $parts[0] ?? '',
        'driver_version' => $parts[1] ?? '',
        'vram_total_mb' => $parts[2] ?? '',
        'vram_used_mb' => $parts[3] ?? '',
        'vram_free_mb' => $parts[4] ?? '',
        'utilization_percent' => $parts[5] ?? '',
        'temperature_c' => $parts[6] ?? '',
    ];
}

function hub_memory_status(): array
{
    $info = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$info) {
        return ['available' => false];
    }

    $values = [];
    foreach ($info as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)/', $line, $matches)) {
            $values[$matches[1]] = (int)$matches[2] * 1024;
        }
    }

    return [
        'total_bytes' => $values['MemTotal'] ?? null,
        'free_bytes' => $values['MemFree'] ?? null,
        'available_bytes' => $values['MemAvailable'] ?? null,
        'used_bytes' => isset($values['MemTotal'], $values['MemAvailable']) ? $values['MemTotal'] - $values['MemAvailable'] : null,
    ];
}

function hub_current_user_in_group(string $groupName): bool
{
    if (!function_exists('posix_getgroups') || !function_exists('posix_getgrgid')) {
        return false;
    }

    foreach (posix_getgroups() as $gid) {
        $group = posix_getgrgid($gid);
        if (($group['name'] ?? '') === $groupName) {
            return true;
        }
    }

    return false;
}

function hub_collect_command_worker_status(): array
{
    $cronFile = '/etc/cron.d/3waaihub-command-worker';
    $cronContents = is_readable($cronFile) ? (string)file_get_contents($cronFile) : '';
    $cron = hub_parse_command_worker_cron($cronContents);
    $loopScript = HUB_ROOT . '/crontab/1min.sh';
    $logPath = HUB_LOG_DIR . '/command_worker_1min.log';

    return [
        'cron_installed' => $cron['installed'],
        'cron_file' => $cronFile,
        'cron_user' => $cron['user'],
        'cron_line' => $cron['line'],
        'loop_script_exists' => is_file($loopScript),
        'loop_script_executable' => is_executable($loopScript),
        'flock_available' => is_executable('/usr/bin/flock') || is_executable('/bin/flock'),
        'log_path' => $logPath,
        'log_exists' => is_file($logPath),
        'last_log_at' => is_file($logPath) ? date('Y-m-d H:i:s', (int)filemtime($logPath)) : '',
        'install_command' => 'sudo ' . HUB_ROOT . '/scripts/install_command_worker_cron.sh',
    ];
}

function hub_parse_command_worker_cron(string $cronContents): array
{
    foreach (preg_split('/\R/', $cronContents) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, 'crontab/1min.sh') && !str_contains($line, 'command_worker_loop.sh')) {
            continue;
        }

        $parts = preg_split('/\s+/', $line, 7) ?: [];
        return [
            'installed' => true,
            'user' => (string)($parts[5] ?? ''),
            'line' => $line,
        ];
    }

    return [
        'installed' => false,
        'user' => '',
        'line' => '',
    ];
}
