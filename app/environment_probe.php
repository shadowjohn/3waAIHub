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
            'docker_error' => $dockerVersion['exit_code'] === 0 ? null : hub_command_error_summary($dockerVersion),
            'compose_error' => $composeVersion['exit_code'] === 0 ? null : hub_command_error_summary($composeVersion),
            'daemon_error' => $dockerInfo['exit_code'] === 0 ? null : hub_command_error_summary($dockerInfo),
            'disk_usage' => $dockerDisk['stdout'],
        ],
        'gpu_cuda' => hub_collect_gpu_status(),
        'memory' => hub_memory_status(),
        'disk' => [
            'project_total_bytes' => disk_total_space(HUB_ROOT),
            'project_free_bytes' => disk_free_space(HUB_ROOT),
            'project_used_bytes' => disk_total_space(HUB_ROOT) - disk_free_space(HUB_ROOT),
            'data_writable' => is_writable(HUB_DATA_DIR),
            'logs_writable' => is_writable(HUB_LOG_DIR),
            'packs_readable' => is_readable(HUB_ROOT . '/packs'),
        ],
    ];
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
