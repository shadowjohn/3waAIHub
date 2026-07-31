<?php
declare(strict_types=1);

function hub_admin_dashboard_role(PDO $db): string
{
    $router = hub_cluster_router_enabled($db);
    $node = hub_cluster_node_enabled($db);
    if ($router && $node) {
        return 'aggregate';
    }
    if ($router) {
        return 'router';
    }
    if ($node) {
        return 'child';
    }

    return 'standalone';
}

function hub_admin_dashboard_count(PDO $db, string $table, string $where = '1 = 1'): int
{
    return hub_table_exists($db, $table)
        ? (int)$db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn()
        : 0;
}

function hub_admin_dashboard_service_counts(array $services): array
{
    $counts = ['running' => 0, 'stopped' => 0, 'pending' => 0, 'error' => 0];
    foreach ($services as $service) {
        $status = (string)($service['status'] ?? '');
        $runtime = (string)($service['runtime_status'] ?? '');
        $install = (string)($service['install_status'] ?? '');
        if ($install === 'failed' || in_array($status, ['error', 'failed'], true) || in_array($runtime, ['error', 'failed'], true)) {
            $counts['error']++;
        } elseif ($status === 'running' || $runtime === 'running') {
            $counts['running']++;
        } elseif ($install !== 'installed' || in_array($runtime, ['pending', 'not_ready'], true)) {
            $counts['pending']++;
        } else {
            $counts['stopped']++;
        }
    }

    return $counts;
}

function hub_admin_dashboard_local(PDO $db): array
{
    $snapshot = hub_latest_host_metric_snapshot($db);
    $services = hub_list_services($db);
    $release = hub_release_local_git_report();
    $packInventory = hub_release_pack_inventory();
    $now = $db->quote(hub_now());
    $since = $db->quote(date('Y-m-d H:i:s', time() - 86400));
    $packs = hub_list_packs();
    $jobPackCount = count(array_filter(
        $packs,
        static fn (array $pack): bool => (array)($pack['manifest']['local_jobs'] ?? []) !== []
    ));

    return [
        'site_title' => hub_site_title($db),
        'metrics_snapshot' => $snapshot,
        'services' => $services,
        'service_counts' => hub_admin_dashboard_service_counts($services),
        'pack_count' => count(array_filter(
            $packs,
            static fn (array $pack): bool => ($pack['status'] ?? '') === 'ok'
        )),
        'queued_jobs' => hub_admin_dashboard_count($db, 'tasks', "status = 'queued'"),
        'running_jobs' => hub_admin_dashboard_count($db, 'tasks', "status = 'running'"),
        'active_gpu_leases' => hub_admin_dashboard_count(
            $db,
            'runtime_resource_leases',
            "state = 'leased' AND lease_expires_at IS NOT NULL AND lease_expires_at > {$now}"
        ),
        'health' => hub_release_health_summary($db),
        'release' => $release,
        'pack_inventory' => $packInventory,
        'api_calls_24h' => hub_admin_dashboard_count($db, 'api_access_logs', "created_at >= {$since}"),
        'api_failed_24h' => hub_admin_dashboard_count($db, 'api_access_logs', "ok = 0 AND created_at >= {$since}"),
        'runtime' => [
            'runs_24h' => hub_admin_dashboard_count($db, 'runtime_runs', "started_at >= {$since}"),
            'running' => hub_admin_dashboard_count($db, 'runtime_runs', "state = 'running'"),
            'failed_24h' => hub_admin_dashboard_count($db, 'runtime_runs', "state = 'failed' AND started_at >= {$since}"),
            'job_packs' => $jobPackCount,
        ],
        'recent_jobs' => hub_list_command_jobs($db, 5),
    ];
}

function hub_admin_dashboard_local_summary(array $local): array
{
    $snapshot = is_array($local['metrics_snapshot'] ?? null) ? $local['metrics_snapshot'] : [];
    $metrics = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
    $gpu = is_array($metrics['gpu'] ?? null) ? $metrics['gpu'] : [];
    if (($gpu['available'] ?? null) === false) {
        unset($gpu['util_percent'], $gpu['temperature_c'], $gpu['memory_total_mb'], $gpu['memory_used_mb'], $gpu['memory_free_mb']);
    }
    $host = is_array($metrics['host'] ?? null) ? $metrics['host'] : [];
    $docker = is_array($metrics['docker'] ?? null) ? $metrics['docker'] : [];
    $storage = is_array($metrics['storage'] ?? null) ? $metrics['storage'] : [];
    $snapshotAt = strtotime((string)($snapshot['created_at'] ?? ''));
    $snapshotFresh = $snapshotAt !== false
        && $snapshotAt <= time() + 30
        && time() - $snapshotAt <= 90;

    return [
        'title' => (string)$local['site_title'],
        'snapshot_at' => (string)($snapshot['created_at'] ?? ''),
        'fresh' => $snapshotFresh,
        'enabled' => true,
        'error' => (string)($gpu['reason'] ?? ''),
        'gpu' => $gpu,
        'host' => $host,
        'docker' => $docker,
        'storage' => $storage,
        'pack_count' => (int)$local['pack_count'],
        'service_count' => count($local['services']),
        'service_counts' => $local['service_counts'],
        'active_gpu_leases' => (int)$local['active_gpu_leases'],
        'queued_jobs' => (int)$local['queued_jobs'],
        'running_jobs' => (int)$local['running_jobs'],
        'published_mode_count' => 0,
        'active_route_count' => 0,
        'services' => $local['services'],
        'release' => is_array($local['release'] ?? null) ? $local['release'] : [],
        'release_compatible' => true,
        'packs' => is_array($local['pack_inventory'] ?? null) ? $local['pack_inventory'] : [],
        'pack_compatible' => true,
        'health' => $local['health'],
        'cluster' => [],
        'api_calls_24h' => (int)$local['api_calls_24h'],
        'api_failed_24h' => (int)$local['api_failed_24h'],
        'runtime' => $local['runtime'],
        'recent_jobs' => $local['recent_jobs'],
    ];
}

function hub_admin_dashboard_local_gpu_history(PDO $db, string $since): array
{
    $stmt = $db->prepare(
        'SELECT snapshot_json, created_at
         FROM host_metric_snapshots
         WHERE created_at >= :since
         ORDER BY created_at ASC, id ASC'
    );
    $stmt->execute([':since' => $since]);
    $samples = [];
    foreach ($stmt->fetchAll() as $snapshot) {
        $data = json_decode((string)$snapshot['snapshot_json'], true);
        $samples[] = [
            'sampled_at' => (string)$snapshot['created_at'],
            'gpu' => is_array($data) && is_array($data['gpu'] ?? null) ? $data['gpu'] : [],
        ];
    }

    return hub_admin_dashboard_gpu_history_rows($samples);
}

function hub_admin_dashboard_station_gpu_history(PDO $db, int $stationId, string $since): array
{
    $stmt = $db->prepare(
        'SELECT gpu_json, sampled_at
         FROM cluster_gpu_metric_snapshots
         WHERE station_id = :station_id AND sampled_at >= :since
         ORDER BY sampled_at ASC, id ASC'
    );
    $stmt->execute([':station_id' => $stationId, ':since' => $since]);
    $samples = [];
    foreach ($stmt->fetchAll() as $snapshot) {
        $gpu = json_decode((string)$snapshot['gpu_json'], true);
        $samples[] = [
            'sampled_at' => (string)$snapshot['sampled_at'],
            'gpu' => is_array($gpu) ? $gpu : [],
        ];
    }

    return hub_admin_dashboard_gpu_history_rows($samples);
}

function hub_admin_dashboard_gpu_history_rows(iterable $samples): array
{
    $history = ['temperature' => [], 'vram_used' => []];
    $timezone = new DateTimeZone('Asia/Taipei');
    $now = time();
    $latestSamples = [];
    foreach ($samples as $sample) {
        if (!is_array($sample)) {
            continue;
        }
        $sampledAt = (string)($sample['sampled_at'] ?? '');
        $gpu = is_array($sample['gpu'] ?? null) ? $sample['gpu'] : [];
        $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $sampledAt, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $timestamp === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $timestamp->format('Y-m-d H:i:s') !== $sampledAt
            || $timestamp->getTimestamp() < $now - 86400
            || $timestamp->getTimestamp() > $now
        ) {
            continue;
        }
        $latestSamples[$timestamp->format('Y-m-d H:i:s')] = $gpu;
    }
    foreach ($latestSamples as $sampledAt => $gpu) {
        if (array_key_exists('available', $gpu) && $gpu['available'] !== true) {
            continue;
        }
        foreach (['temperature_c' => 'temperature', 'memory_used_mb' => 'vram_used'] as $field => $series) {
            $value = $gpu[$field] ?? null;
            if (!is_numeric($value) || !is_finite((float)$value)) {
                continue;
            }
            $history[$series][] = ['label' => $sampledAt, 'value' => (float)$value];
        }
    }

    return $history;
}

function hub_admin_dashboard_station_summary(array $station): array
{
    $gpu = is_array($station['gpu'] ?? null) ? $station['gpu'] : [];
    $totalVram = max(0, (int)(is_numeric($gpu['memory_total_mb'] ?? null)
        ? $gpu['memory_total_mb']
        : ($station['gpu_total_vram_mb'] ?? 0)));
    $freeVram = min($totalVram, max(0, (int)(is_numeric($gpu['memory_free_mb'] ?? null)
        ? $gpu['memory_free_mb']
        : ($station['gpu_free_vram_mb'] ?? 0))));
    $health = is_array($station['health'] ?? null) ? $station['health'] : [];
    $services = is_array($station['services'] ?? null) ? $station['services'] : [];
    $healthKnown = is_string($health['status'] ?? null)
        && in_array($health['status'], ['ok', 'degraded'], true);
    if (!$healthKnown) {
        $health = ['status' => 'unknown'];
    }
    $serviceCounts = [
        'running' => $healthKnown ? (int)($health['running_services'] ?? 0) : 0,
        'error' => $healthKnown ? (int)($health['failed_services'] ?? 0) : 0,
        'pending' => $healthKnown ? 0 : count($services),
        'stopped' => 0,
    ];
    if ($healthKnown) {
        $serviceCounts['stopped'] = max(
            0,
            count($services) - $serviceCounts['running'] - $serviceCounts['error']
        );
    }
    $summaryGpu = array_replace($gpu, [
        'available' => $totalVram > 0 && (!array_key_exists('available', $gpu) || $gpu['available'] === true),
        'memory_total_mb' => $totalVram,
        'memory_free_mb' => $freeVram,
        'memory_used_mb' => max(0, $totalVram - $freeVram),
    ]);
    if (($gpu['available'] ?? null) === false) {
        unset($summaryGpu['util_percent'], $summaryGpu['temperature_c'], $summaryGpu['memory_total_mb'], $summaryGpu['memory_used_mb'], $summaryGpu['memory_free_mb']);
    }

    return [
        'title' => (string)$station['display_name'],
        'snapshot_at' => (string)($station['status_fetched_at'] ?? ''),
        'fresh' => !empty($station['fresh']),
        'enabled' => !empty($station['enabled']),
        'error' => (string)($station['last_error'] ?? ''),
        'connection_state' => (string)($station['connection_state'] ?? 'offline'),
        'gpu' => $summaryGpu,
        'host' => [],
        'docker' => [],
        'storage' => [],
        'pack_count' => count(is_array($station['packs'] ?? null) ? $station['packs'] : []),
        'service_count' => (int)($station['service_count'] ?? count($services)),
        'service_counts' => $serviceCounts,
        'active_gpu_leases' => (int)($station['active_gpu_leases'] ?? 0),
        'queued_jobs' => (int)($station['queued_jobs'] ?? 0),
        'running_jobs' => (int)($station['running_jobs'] ?? 0),
        'published_mode_count' => count(is_array($station['modes'] ?? null) ? $station['modes'] : []),
        'active_route_count' => (int)($station['active_route_count'] ?? 0),
        'services' => $services,
        'release' => is_array($station['release'] ?? null) ? $station['release'] : [],
        'release_compatible' => $station['release_compatible'] ?? null,
        'packs' => is_array($station['packs'] ?? null) ? $station['packs'] : [],
        'pack_compatible' => $station['pack_compatible'] ?? null,
        'health' => $health,
        'cluster' => is_array($station['cluster'] ?? null) ? $station['cluster'] : [],
        'api_calls_24h' => 0,
        'api_failed_24h' => 0,
        'runtime' => ['runs_24h' => 0, 'running' => 0, 'failed_24h' => 0, 'job_packs' => 0],
        'recent_jobs' => [],
    ];
}

function hub_admin_dashboard_model(PDO $db, array $query): array
{
    $role = hub_admin_dashboard_role($db);
    $local = hub_admin_dashboard_local($db);
    $stationRows = in_array($role, ['router', 'aggregate'], true)
        ? hub_cluster_station_dashboard_rows($db)
        : [];
    $stationTabs = array_map(
        static fn (array $station): array => [
            'station_key' => (string)$station['station_key'],
            'label' => (string)$station['display_name'],
            'connection_state' => (string)($station['connection_state'] ?? 'offline'),
        ],
        $stationRows
    );

    $requested = is_string($query['station'] ?? null) ? $query['station'] : '';
    $activeStation = null;
    foreach ($stationRows as $station) {
        if ((string)$station['station_key'] === $requested) {
            $activeStation = $station;
            break;
        }
    }
    if ($activeStation === null && $stationRows !== []) {
        $activeStation = $stationRows[0];
    }

    $summary = $activeStation === null
        ? hub_admin_dashboard_local_summary($local)
        : hub_admin_dashboard_station_summary($activeStation);
    $historySince = date('Y-m-d H:i:s', time() - 86400);
    $summary['gpu_history'] = $activeStation === null
        ? hub_admin_dashboard_local_gpu_history($db, $historySince)
        : hub_admin_dashboard_station_gpu_history($db, (int)$activeStation['id'], $historySince);
    if ($activeStation === null) {
        $summary['published_mode_count'] = hub_cluster_node_enabled($db)
            ? count(hub_cluster_node_selected_published_modes($db))
            : 0;
    }

    return [
        'role' => $role,
        'aggregate' => $role === 'aggregate',
        'children_count' => count($stationRows),
        'published_mode_count' => hub_cluster_node_enabled($db)
            ? count(hub_cluster_node_selected_published_modes($db))
            : 0,
        'station_tabs' => $stationTabs,
        'active_station_key' => (string)($activeStation['station_key'] ?? ''),
        'active_station' => $activeStation,
        'local' => $local,
        'summary' => $summary,
    ];
}
