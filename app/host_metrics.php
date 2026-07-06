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
    $compose = hub_run_command(['docker', 'compose', 'version'], 10);
    $nvidiaCtk = hub_run_command(['nvidia-ctk', '--version'], 10);
    if ($version['exit_code'] !== 0) {
        return [
            'available' => false,
            'daemon_reachable' => false,
            'compose_available' => $compose['exit_code'] === 0,
            'nvidia_container_toolkit' => $nvidiaCtk['exit_code'] === 0,
            'compose_reason' => $compose['exit_code'] === 0 ? '' : hub_command_error_summary($compose),
            'nvidia_container_toolkit_reason' => $nvidiaCtk['exit_code'] === 0 ? '' : hub_command_error_summary($nvidiaCtk),
            'nvidia_runtime_available' => false,
            'reason' => hub_command_error_summary($version),
        ];
    }

    $info = hub_run_command(['docker', 'info'], 15);
    $rootDir = $info['exit_code'] === 0 ? hub_parse_docker_root_dir($info['stdout']) : '';
    $disk = $rootDir !== '' ? hub_disk_metric($rootDir) : null;

    return [
        'available' => true,
        'daemon_reachable' => $info['exit_code'] === 0,
        'compose_available' => $compose['exit_code'] === 0,
        'nvidia_container_toolkit' => $nvidiaCtk['exit_code'] === 0,
        'nvidia_runtime_available' => $info['exit_code'] === 0 && stripos($info['stdout'], 'nvidia') !== false,
        'version' => $version['stdout'],
        'compose_version' => $compose['stdout'],
        'compose_reason' => $compose['exit_code'] === 0 ? '' : hub_command_error_summary($compose),
        'nvidia_container_toolkit_reason' => $nvidiaCtk['exit_code'] === 0 ? '' : hub_command_error_summary($nvidiaCtk),
        'nvidia_runtime_reason' => $info['exit_code'] !== 0 ? hub_command_error_summary($info) : (($info['stdout'] !== '' && stripos($info['stdout'], 'nvidia') === false) ? 'docker info 未列出 nvidia runtime。' : ''),
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

function hub_gpu_compute_capability_for_name(string $name): ?string
{
    $map = [
        'RTX 5090' => '12.0',
        'RTX 5080' => '12.0',
        'RTX 5070' => '12.0',
        'RTX 5060 Ti' => '12.0',
        'RTX 5060' => '12.0',
        'RTX 4090' => '8.9',
        'RTX 4080' => '8.9',
        'RTX 4070' => '8.9',
        'RTX 4060 Ti' => '8.9',
        'RTX 4060' => '8.9',
        'RTX 3090' => '8.6',
        'RTX 3080' => '8.6',
        'RTX 3070' => '8.6',
        'RTX 3060' => '8.6',
        'GTX 1080 Ti' => '6.1',
    ];

    foreach ($map as $needle => $capability) {
        if (stripos($name, $needle) !== false) {
            return $capability;
        }
    }

    return null;
}

function hub_station_hardware_profile(PDO $db): array
{
    $latest = hub_latest_host_metric_snapshot($db);
    $data = $latest['data'] ?? [];
    $gpu = is_array($data['gpu'] ?? null) ? $data['gpu'] : [];
    $docker = is_array($data['docker'] ?? null) ? $data['docker'] : [];
    $gpuName = (string)($gpu['name'] ?? '');

    return [
        'snapshot_at' => (string)($latest['created_at'] ?? ''),
        'gpu' => [
            'available' => !empty($gpu['available']),
            'name' => $gpuName,
            'vram_mb' => (int)($gpu['memory_total_mb'] ?? 0),
            'compute_capability' => $gpuName !== '' ? hub_gpu_compute_capability_for_name($gpuName) : null,
            'driver_version' => (string)($gpu['driver_version'] ?? ''),
            'cuda_version' => (string)($gpu['cuda_version'] ?? ''),
        ],
        'docker_gpu' => [
            'available' => !empty($docker['daemon_reachable']) && !empty($docker['nvidia_container_toolkit']) && !empty($docker['nvidia_runtime_available']) && !empty($gpu['available']),
            'docker_available' => !empty($docker['available']),
            'docker_compose_available' => !empty($docker['compose_available']),
            'daemon_reachable' => !empty($docker['daemon_reachable']),
            'nvidia_container_toolkit' => !empty($docker['nvidia_container_toolkit']),
            'nvidia_runtime_available' => !empty($docker['nvidia_runtime_available']),
            'compose_reason' => (string)($docker['compose_reason'] ?? ''),
            'nvidia_container_toolkit_reason' => (string)($docker['nvidia_container_toolkit_reason'] ?? ''),
            'nvidia_runtime_reason' => (string)($docker['nvidia_runtime_reason'] ?? ''),
            'reason' => (string)($docker['reason'] ?? ''),
        ],
    ];
}

function hub_host_metric_fix_suggestions(array $metrics, string $workerUser = ''): array
{
    if ($metrics === []) {
        return [];
    }

    $docker = is_array($metrics['docker'] ?? null) ? $metrics['docker'] : [];
    $gpu = is_array($metrics['gpu'] ?? null) ? $metrics['gpu'] : [];
    $workerUser = $workerUser !== '' ? $workerUser : (getenv('USER') ?: 'john');
    $safeUser = escapeshellarg($workerUser);
    $isWebUser = in_array($workerUser, ['www-data', 'apache', 'nginx'], true);
    $suggestions = [];

    if (($docker['daemon_reachable'] ?? null) === false && stripos((string)($docker['reason'] ?? ''), 'permission denied') !== false) {
        $suggestions[] = [
            'title' => '修正 Docker socket 權限',
            'body' => 'Marketplace preflight 無法連 Docker daemon。不要把 www-data 加進 docker 群組；建議用 root cron 或可信任本機帳號執行 command worker。',
            'commands' => $isWebUser
                ? "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./scripts/install_command_worker_cron.sh\n# 或使用專用帳號：\nid 3waaihub-worker >/dev/null 2>&1 || sudo useradd -m -s /bin/bash -G docker 3waaihub-worker\nsudo -iu 3waaihub-worker docker info\nsudo env WORKER_USER=3waaihub-worker ./scripts/install_command_worker_cron.sh\nphp scripts/collect_host_metrics.php --force"
                : "sudo usermod -aG docker {$safeUser}\n# 重新登入後驗證：\nsudo -iu {$safeUser} docker info\ncd " . escapeshellarg(HUB_ROOT) . "\nsudo env WORKER_USER={$safeUser} ./scripts/install_command_worker_cron.sh\nphp scripts/collect_host_metrics.php --force",
        ];
    }

    if (($docker['compose_available'] ?? false) === false) {
        $suggestions[] = [
            'title' => '安裝 Docker Compose plugin',
            'body' => 'Docker Compose plugin 不可用，HubPack generated compose 無法啟動。',
            'commands' => "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./install.sh --bootstrap-host --with-docker\n# 或只補 plugin：\nsudo apt-get update\nsudo apt-get install -y docker-compose-plugin\ndocker compose version\nphp scripts/collect_host_metrics.php --force",
        ];
    }

    if (!empty($gpu['available']) && (empty($docker['nvidia_container_toolkit']) || empty($docker['nvidia_runtime_available']))) {
        $suggestions[] = [
            'title' => '修正 Docker GPU runtime',
            'body' => 'GPU 與 nvidia-smi 可用，但 Docker 尚未確認可掛 GPU。這通常是 NVIDIA Container Toolkit 尚未安裝或 Docker runtime 未設定。',
            'commands' => "cd " . escapeshellarg(HUB_ROOT) . "\nsudo ./install.sh --bootstrap-host --with-nvidia\n# 驗證：\nsudo docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi\nphp scripts/collect_host_metrics.php --force",
        ];
    }

    return $suggestions;
}

function hub_pack_preflight(PDO $db, array $manifest): array
{
    $profile = hub_station_hardware_profile($db);
    $checks = (array)($manifest['preflight']['checks'] ?? []);
    $results = [];

    foreach ($checks as $check) {
        $key = (string)$check;
        $results[$key] = hub_pack_preflight_check($db, $manifest, $profile, $key);
    }

    $failed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'fail'));

    return [
        'snapshot_at' => $profile['snapshot_at'],
        'profile' => $profile,
        'checks' => $results,
        'summary' => [
            'status' => $failed === 0 ? 'pass' : 'fail',
            'failed' => $failed,
            'total' => count($results),
        ],
    ];
}

function hub_pack_preflight_check(PDO $db, array $manifest, array $profile, string $check): array
{
    $gpu = $profile['gpu'];
    $dockerGpu = $profile['docker_gpu'];
    $hardware = is_array($manifest['hardware'] ?? null) ? $manifest['hardware'] : [];

    if ($profile['snapshot_at'] === '' && in_array($check, ['docker', 'docker_compose', 'nvidia_smi', 'docker_gpus', 'vram', 'compute_capability'], true)) {
        return hub_preflight_row($check, 'fail', '尚未收集 metrics snapshot，請先執行 php scripts/collect_host_metrics.php --force');
    }

    return match ($check) {
        'docker' => hub_preflight_row($check, !empty($dockerGpu['daemon_reachable']) ? 'pass' : 'fail', !empty($dockerGpu['daemon_reachable']) ? 'Docker daemon 可連線' : (($dockerGpu['reason'] ?? '') ?: 'Docker daemon 不可連線')),
        'docker_compose' => hub_preflight_row($check, !empty($dockerGpu['docker_compose_available']) ? 'pass' : 'fail', !empty($dockerGpu['docker_compose_available']) ? 'Docker Compose 可用' : (($dockerGpu['compose_reason'] ?? '') ?: 'Docker Compose 不可用')),
        'nvidia_smi' => hub_preflight_row($check, !empty($gpu['available']) ? 'pass' : 'fail', !empty($gpu['available']) ? (string)$gpu['name'] : 'nvidia-smi 不可用'),
        'docker_gpus' => hub_preflight_row($check, !empty($dockerGpu['available']) ? 'pass' : 'fail', !empty($dockerGpu['available']) ? 'NVIDIA Container Toolkit / Docker runtime 可用' : (($dockerGpu['nvidia_runtime_reason'] ?? '') ?: ($dockerGpu['nvidia_container_toolkit_reason'] ?? '') ?: 'Docker GPU runtime 不可用或未驗證')),
        'vram' => hub_preflight_vram_row($gpu, (int)($hardware['min_vram_mb'] ?? 0)),
        'compute_capability' => hub_preflight_compute_row($gpu, (string)($hardware['min_compute_capability'] ?? '')),
        'storage' => hub_preflight_storage_row($db, $manifest),
        default => hub_preflight_row($check, 'fail', '未知 preflight check'),
    };
}

function hub_preflight_vram_row(array $gpu, int $minVramMb): array
{
    if ($minVramMb <= 0) {
        return hub_preflight_row('vram', 'pass', '未要求最低 VRAM');
    }

    $vram = (int)($gpu['vram_mb'] ?? 0);
    return hub_preflight_row('vram', $vram >= $minVramMb ? 'pass' : 'fail', $vram . 'MB / required ' . $minVramMb . 'MB');
}

function hub_preflight_compute_row(array $gpu, string $minCapability): array
{
    if ($minCapability === '') {
        return hub_preflight_row('compute_capability', 'pass', '未要求最低 compute capability');
    }

    $capability = (string)($gpu['compute_capability'] ?? '');
    if ($capability === '') {
        return hub_preflight_row('compute_capability', 'fail', '未知 GPU compute capability');
    }

    return hub_preflight_row('compute_capability', (float)$capability >= (float)$minCapability ? 'pass' : 'fail', $capability . ' / required ' . $minCapability);
}

function hub_preflight_storage_row(PDO $db, array $manifest): array
{
    $paths = hub_get_storage_paths($db);
    $byType = [
        'models' => $paths['AIHUB_MODELS_DIR'],
        'cache' => $paths['AIHUB_CACHE_DIR'],
        'uploads' => $paths['AIHUB_UPLOADS_DIR'],
        'results' => $paths['AIHUB_RESULTS_DIR'],
        'service' => HUB_SERVICE_DIR,
    ];

    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount)) {
            continue;
        }
        $path = $byType[(string)($mount['type'] ?? '')] ?? '';
        if ($path !== '' && (!is_dir($path) || !is_writable($path))) {
            return hub_preflight_row('storage', 'fail', $path . ' 不可寫');
        }
    }

    return hub_preflight_row('storage', 'pass', '必要 storage paths 可寫');
}

function hub_preflight_row(string $key, string $status, string $detail): array
{
    return ['key' => $key, 'status' => $status, 'detail' => $detail];
}
