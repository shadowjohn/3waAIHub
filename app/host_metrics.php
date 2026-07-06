<?php
declare(strict_types=1);

function hub_save_host_metric_snapshot(PDO $db, array $snapshot): void
{
    $stmt = $db->prepare(
        'INSERT INTO host_metric_snapshots (snapshot_json, created_at)
         VALUES (:snapshot_json, :created_at)'
    );
    $stmt->execute([
        ':snapshot_json' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => hub_now(),
    ]);
}

function hub_latest_host_metric_snapshot(PDO $db): ?array
{
    $snapshot = $db->query('SELECT * FROM host_metric_snapshots ORDER BY id DESC LIMIT 1')->fetch();
    if (!$snapshot) {
        return null;
    }

    $snapshot['data'] = json_decode((string)$snapshot['snapshot_json'], true) ?: [];
    return $snapshot;
}

function hub_should_collect_host_metrics(PDO $db, int $minIntervalSeconds = 30, bool $force = false): bool
{
    if ($force) {
        return true;
    }

    $latest = hub_latest_host_metric_snapshot($db);
    if ($latest === null) {
        return true;
    }

    $createdAt = strtotime((string)$latest['created_at']);
    if ($createdAt === false) {
        return true;
    }

    return (time() - $createdAt) >= $minIntervalSeconds;
}

function hub_collect_host_metrics(PDO $db): array
{
    hub_cli_only();
    hub_ensure_default_storage_settings($db);

    return [
        'gpu' => hub_collect_gpu_metric(),
        'host' => hub_collect_host_metric($db),
        'docker' => hub_collect_docker_metric(),
        'storage' => hub_collect_storage_metric($db),
        'counts' => hub_collect_aihub_counts($db),
    ];
}

function hub_collect_gpu_metric(): array
{
    $raw = hub_collect_gpu_status();
    if (empty($raw['nvidia_smi_available'])) {
        return [
            'available' => false,
            'reason' => (string)($raw['nvidia_smi_error'] ?? 'nvidia-smi unavailable'),
        ];
    }

    return [
        'available' => true,
        'name' => (string)($raw['name'] ?? ''),
        'driver_version' => (string)($raw['driver_version'] ?? ''),
        'cuda_version' => (string)($raw['cuda_version'] ?? ''),
        'util_percent' => (int)($raw['utilization_percent'] ?? 0),
        'memory_total_mb' => (int)($raw['vram_total_mb'] ?? 0),
        'memory_used_mb' => (int)($raw['vram_used_mb'] ?? 0),
        'memory_free_mb' => (int)($raw['vram_free_mb'] ?? 0),
        'temperature_c' => (int)($raw['temperature_c'] ?? 0),
    ];
}

function hub_collect_host_metric(PDO $db): array
{
    $memory = hub_memory_status();
    $swapIo = hub_collect_vmstat_swap_io();
    $load = sys_getloadavg() ?: [0, 0, 0];
    $rootDisk = hub_disk_metric('/');
    $dataDisk = hub_disk_metric(is_dir('/DATA') ? '/DATA' : HUB_DATA_DIR);
    $availablePercent = hub_percent($memory['available_bytes'] ?? null, $memory['total_bytes'] ?? null);

    return [
        'load_1' => (float)$load[0],
        'load_5' => (float)$load[1],
        'load_15' => (float)$load[2],
        'ram_total_mb' => hub_bytes_to_mb($memory['total_bytes'] ?? null),
        'ram_used_mb' => hub_bytes_to_mb($memory['used_bytes'] ?? null),
        'ram_buff_cache_mb' => hub_bytes_to_mb($memory['buff_cache_bytes'] ?? null),
        'ram_available_mb' => hub_bytes_to_mb($memory['available_bytes'] ?? null),
        'ram_used_percent' => hub_percent($memory['used_bytes'] ?? null, $memory['total_bytes'] ?? null),
        'ram_available_percent' => $availablePercent,
        'swap_total_mb' => hub_bytes_to_mb($memory['swap_total_bytes'] ?? null),
        'swap_used_mb' => hub_bytes_to_mb($memory['swap_used_bytes'] ?? null),
        'swap_used_percent' => hub_percent($memory['swap_used_bytes'] ?? null, $memory['swap_total_bytes'] ?? null),
        'vmstat_si' => $swapIo['si'],
        'vmstat_so' => $swapIo['so'],
        'memory_pressure' => hub_memory_pressure_status($availablePercent, $swapIo['si'], $swapIo['so']),
        'disk_root' => $rootDisk,
        'disk_data' => $dataDisk,
        'models_dir' => hub_disk_metric(hub_get_storage_setting($db, 'AIHUB_MODELS_DIR')),
    ];
}

function hub_collect_vmstat_swap_io(): array
{
    $result = hub_run_command(['vmstat', '1', '2'], 5);
    if ($result['exit_code'] !== 0) {
        return ['si' => null, 'so' => null];
    }

    return hub_parse_vmstat_swap_io($result['stdout']);
}

function hub_parse_vmstat_swap_io(string $output): array
{
    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
    $last = end($lines);
    if (!$last || preg_match('/^[a-z]/i', $last)) {
        return ['si' => null, 'so' => null];
    }

    $parts = preg_split('/\s+/', $last) ?: [];
    return [
        'si' => isset($parts[6]) ? (int)$parts[6] : null,
        'so' => isset($parts[7]) ? (int)$parts[7] : null,
    ];
}

function hub_memory_pressure_status(?float $availablePercent, ?int $si, ?int $so): string
{
    if (($si !== null && $si > 0) || ($so !== null && $so > 0)) {
        return 'warning';
    }
    if ($availablePercent !== null && $availablePercent < 10) {
        return 'critical';
    }
    if ($availablePercent !== null && $availablePercent < 20) {
        return 'warning';
    }

    return 'ok';
}

function hub_collect_docker_metric(): array
{
    $version = hub_run_command(['docker', '--version'], 10);
    if ($version['exit_code'] !== 0) {
        return [
            'available' => false,
            'daemon_reachable' => false,
            'reason' => hub_command_error_summary($version),
        ];
    }

    $info = hub_run_command(['docker', 'info'], 15);
    $rootDir = $info['exit_code'] === 0 ? hub_parse_docker_root_dir($info['stdout']) : '';
    $disk = $rootDir !== '' ? hub_disk_metric($rootDir) : null;

    return [
        'available' => true,
        'daemon_reachable' => $info['exit_code'] === 0,
        'version' => $version['stdout'],
        'root_dir' => $rootDir,
        'root_free_gb' => $disk['free_gb'] ?? null,
        'root_used_percent' => $disk['used_percent'] ?? null,
        'warning' => hub_docker_root_warning($rootDir, isset($disk['free_gb']) ? $disk['free_gb'] * 1024 * 1024 * 1024 : null),
        'reason' => $info['exit_code'] === 0 ? '' : hub_command_error_summary($info),
    ];
}

function hub_collect_storage_metric(PDO $db): array
{
    $paths = hub_get_storage_paths($db);
    $models = hub_disk_metric($paths['AIHUB_MODELS_DIR']);

    return [
        'models_dir' => $paths['AIHUB_MODELS_DIR'],
        'models_total_gb' => $models['total_gb'],
        'models_used_gb' => $models['used_gb'],
        'models_free_gb' => $models['free_gb'],
        'models_used_percent' => $models['used_percent'],
    ];
}

function hub_collect_aihub_counts(PDO $db): array
{
    return [
        'packs' => count(array_filter(hub_list_packs(), static fn (array $pack): bool => ($pack['status'] ?? '') === 'ok')),
        'services' => (int)$db->query('SELECT COUNT(*) FROM services')->fetchColumn(),
        'running_services' => hub_count_where($db, 'services', "status = 'running'"),
        'stopped_services' => hub_count_where($db, 'services', "status = 'stopped'"),
        'error_services' => hub_count_where($db, 'services', "status = 'error'"),
        'not_ready_services' => hub_count_where($db, 'services', "install_status != 'installed' OR runtime_status IN ('pending', 'not_ready')"),
        'queued_tasks' => hub_table_exists($db, 'tasks') ? hub_count_where($db, 'tasks', "status = 'queued'") : 0,
        'running_tasks' => hub_table_exists($db, 'tasks') ? hub_count_where($db, 'tasks', "status = 'running'") : 0,
        'failed_tasks' => hub_table_exists($db, 'tasks') ? hub_count_where($db, 'tasks', "status = 'failed'") : 0,
        'queued_command_jobs' => hub_table_exists($db, 'command_jobs') ? hub_count_where($db, 'command_jobs', "status = 'queued'") : 0,
        'running_command_jobs' => hub_table_exists($db, 'command_jobs') ? hub_count_where($db, 'command_jobs', "status = 'running'") : 0,
        'failed_command_jobs' => hub_table_exists($db, 'command_jobs') ? hub_count_where($db, 'command_jobs', "status = 'failed'") : 0,
    ];
}

function hub_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name");
    $stmt->execute([':name' => $table]);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_count_where(PDO $db, string $table, string $where): int
{
    return (int)$db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn();
}

function hub_disk_metric(string $path): array
{
    $probe = is_dir($path) ? $path : dirname($path);
    if (!is_dir($probe)) {
        return ['path' => $path, 'available' => false, 'total_gb' => null, 'used_gb' => null, 'free_gb' => null, 'used_percent' => null];
    }

    $total = disk_total_space($probe);
    $free = disk_free_space($probe);
    $used = $total - $free;

    return [
        'path' => $path,
        'available' => true,
        'total_gb' => hub_bytes_to_gb($total),
        'used_gb' => hub_bytes_to_gb($used),
        'free_gb' => hub_bytes_to_gb($free),
        'used_percent' => hub_percent($used, $total),
    ];
}

function hub_bytes_to_mb(int|float|null $bytes): ?float
{
    return $bytes === null ? null : round($bytes / 1024 / 1024, 1);
}

function hub_bytes_to_gb(int|float|null $bytes): ?float
{
    return $bytes === null ? null : round($bytes / 1024 / 1024 / 1024, 1);
}

function hub_percent(int|float|null $used, int|float|null $total): ?float
{
    if ($used === null || $total === null || $total <= 0) {
        return null;
    }

    return round(($used / $total) * 100, 1);
}
