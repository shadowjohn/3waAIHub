<?php
declare(strict_types=1);

require_once HUB_ROOT . '/app/admin_dashboard.php';

function hub_test_admin_dashboard_with_cluster_secret(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
    try {
        $fn();
    } finally {
        $previous === false
            ? putenv('AIHUB_CLUSTER_SECRET_KEY')
            : putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
    }
}

function hub_test_admin_dashboard_station(PDO $db, array $overrides = []): array
{
    $stationId = hub_cluster_save_paired_station($db, array_replace([
        'station_key' => 'station_1080',
        'display_name' => '1080 影像站',
        'public_base_url' => 'https://station.example/aihub',
        'internal_base_url' => 'https://station.internal/aihub',
        'priority' => 7,
        'enabled' => true,
        'station_token' => '3wa_live_station_secret',
        'modes' => ['vision'],
    ], $overrides));
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('dashboard station fixture missing');
    }

    return $station;
}

hub_test('dashboard model separates child and router station behavior', function (): void {
    $db = hub_test_reset_db();
    hub_cluster_node_configure($db, true, []);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '0');
    $child = hub_admin_dashboard_model($db, []);
    hub_test_assert($child['role'] === 'child', 'child role mismatch');
    hub_test_assert($child['station_tabs'] === [], 'child dashboard must not expose station tabs');

    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    $router = hub_admin_dashboard_model($db, []);
    hub_test_assert($router['role'] === 'aggregate', 'both enabled roles must render aggregate');
    hub_test_assert($router['aggregate'] === true, 'aggregate flag mismatch');
});

hub_test('router dashboard tabs use station display names and query keys', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_admin_dashboard_station($db);
        hub_test_admin_dashboard_station($db, [
            'station_key' => 'station_tts',
            'display_name' => '語音服務站',
        ]);

        $model = hub_admin_dashboard_model($db, ['station' => 'station_1080']);
        hub_test_assert($model['active_station_key'] === 'station_1080', 'station query selection mismatch');
        hub_test_assert($model['station_tabs'][0]['label'] === '1080 影像站', 'station title mismatch');
        hub_test_assert($model['station_tabs'][0]['station_key'] === 'station_1080', 'station key mismatch');
        hub_test_assert(($model['station_tabs'][0]['connection_state'] ?? '') === 'offline', 'station tabs must expose the shared connection state');

        $fallback = hub_admin_dashboard_model($db, ['station' => 'GPU 1']);
        hub_test_assert($fallback['active_station_key'] === 'station_1080', 'invalid station query must select first configured station');
        hub_test_assert(!str_contains(json_encode($fallback['station_tabs'], JSON_THROW_ON_ERROR), 'GPU 1'), 'dashboard must not synthesize GPU station labels');
    });
});

hub_test('aggregate dashboard keeps the self station once and does not write configuration', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_cluster_node_configure($db, true, []);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_admin_dashboard_station($db, [
            'station_key' => 'local_station',
            'display_name' => '本機推論站',
        ]);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'local_station');
        $changesBefore = (int)$db->query('SELECT total_changes()')->fetchColumn();

        $model = hub_admin_dashboard_model($db, []);

        $changesAfter = (int)$db->query('SELECT total_changes()')->fetchColumn();
        hub_test_assert(count($model['station_tabs']) === 1, 'self station must not be duplicated');
        hub_test_assert($model['station_tabs'][0]['label'] === '本機推論站', 'self station must retain display name');
        hub_test_assert($changesAfter === $changesBefore, 'dashboard model must be read-only');
    });
});

hub_test('dashboard preserves unknown legacy health instead of reporting stopped services', function (): void {
    $summary = hub_admin_dashboard_station_summary([
        'display_name' => 'Legacy Node',
        'enabled' => true,
        'fresh' => true,
        'services' => [
            ['name' => 'OCR', 'mode' => 'ocr'],
            ['name' => 'TTS', 'mode' => 'tts'],
        ],
    ]);

    hub_test_assert($summary['health']['status'] === 'unknown', 'missing station health must remain unknown');
    hub_test_assert($summary['service_counts']['pending'] === 2, 'unknown legacy services must be pending');
    hub_test_assert($summary['service_counts']['stopped'] === 0, 'unknown legacy services must not be reported stopped');
});

hub_test('dashboard treats legacy child GPU totals as available unless explicitly unavailable', function (): void {
    $legacy = hub_admin_dashboard_station_summary([
        'display_name' => 'Legacy GPU Node',
        'gpu' => ['memory_total_mb' => 8192, 'memory_free_mb' => 4096],
    ]);
    $unavailable = hub_admin_dashboard_station_summary([
        'display_name' => 'Unavailable GPU Node',
        'gpu' => ['available' => false, 'memory_total_mb' => 8192, 'memory_free_mb' => 4096],
    ]);

    hub_test_assert($legacy['gpu']['available'] === true, 'legacy GPU totals without an availability flag must remain available');
    hub_test_assert($unavailable['gpu']['available'] === false, 'an explicit unavailable GPU flag must remain unavailable');
});

hub_test('local dashboard expires old metrics and exposes read-only release identity', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, ['gpu' => ['available' => false]]);
    $db->exec("UPDATE host_metric_snapshots SET created_at = '2020-01-01 00:00:00'");

    $model = hub_admin_dashboard_model($db, []);

    hub_test_assert($model['summary']['fresh'] === false, 'old local metrics must be stale');
    hub_test_assert(
        ($model['summary']['release']['build_id'] ?? '') === HUB_VERSION,
        'local dashboard must expose current read-only release identity'
    );
    hub_test_assert($model['summary']['pack_compatible'] === true, 'local Pack inventory is compatible with itself');
});

hub_test('local dashboard masks only explicitly unavailable GPU metrics', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, [
        'gpu' => ['available' => false, 'util_percent' => 73, 'temperature_c' => 66, 'memory_total_mb' => 16384, 'memory_used_mb' => 12288, 'memory_free_mb' => 4096],
    ]);

    $unavailable = hub_admin_dashboard_model($db, [])['summary']['gpu'];
    foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $unavailable), 'explicit unavailable local GPU metrics must not reach Dashboard cards: ' . $field);
    }

    hub_save_host_metric_snapshot($db, [
        'gpu' => ['util_percent' => 73, 'temperature_c' => 66, 'memory_total_mb' => 16384, 'memory_used_mb' => 12288, 'memory_free_mb' => 4096],
    ]);
    $legacy = hub_admin_dashboard_model($db, [])['summary']['gpu'];
    hub_test_assert($legacy['util_percent'] === 73 && $legacy['temperature_c'] === 66 && $legacy['memory_used_mb'] === 12288, 'legacy local GPU metrics without availability must remain visible');
});

hub_test('dashboard GPU history readers keep only recent local and child samples', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $recentAt = date('Y-m-d H:i:s', time() - 60);
        $expiredAt = date('Y-m-d H:i:s', time() - 86401);
        $hostInsert = $db->prepare(
            'INSERT INTO host_metric_snapshots (snapshot_json, created_at) VALUES (:snapshot_json, :created_at)'
        );
        foreach ([
            [$expiredAt, ['available' => true, 'temperature_c' => 40, 'memory_used_mb' => 100]],
            [$recentAt, ['available' => true, 'temperature_c' => 55, 'memory_used_mb' => 200]],
        ] as [$createdAt, $gpu]) {
            $hostInsert->execute([
                ':snapshot_json' => json_encode(['gpu' => $gpu], JSON_THROW_ON_ERROR),
                ':created_at' => $createdAt,
            ]);
        }
        $local = hub_admin_dashboard_local_gpu_history($db, $since);
        hub_test_assert($local['temperature'] === [['label' => $recentAt, 'value' => 55.0]], 'local GPU history must exclude expired temperature samples');
        hub_test_assert($local['vram_used'] === [['label' => $recentAt, 'value' => 200.0]], 'local GPU history must exclude expired VRAM samples');
        hub_test_assert(hub_admin_dashboard_model($db, [])['summary']['gpu_history'] === $local, 'local Dashboard must expose its GPU history');

        $station = hub_test_admin_dashboard_station($db);
        $childInsert = $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        );
        foreach ([
            [$expiredAt, ['available' => true, 'temperature_c' => 41, 'memory_used_mb' => 300]],
            [$recentAt, ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 400]],
        ] as [$sampledAt, $gpu]) {
            $childInsert->execute([
                ':station_id' => (int)$station['id'],
                ':sampled_at' => $sampledAt,
                ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
            ]);
        }
        $child = hub_admin_dashboard_station_gpu_history($db, (int)$station['id'], $since);
        hub_test_assert($child['temperature'] === [['label' => $recentAt, 'value' => 66.0]], 'child GPU history must exclude expired temperature samples');
        hub_test_assert($child['vram_used'] === [['label' => $recentAt, 'value' => 400.0]], 'child GPU history must exclude expired VRAM samples');
    });
});

hub_test('selected child dashboard retains current GPU metrics and history', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $snapshotAt = date('Y-m-d H:i:s', time() - 30);
        $gpu = [
            'available' => true,
            'memory_total_mb' => 16384,
            'memory_free_mb' => 4096,
            'memory_used_mb' => 12288,
            'util_percent' => 73,
            'temperature_c' => 66,
        ];
        $db->prepare(
            'UPDATE cluster_stations SET status_json = :status_json, status_fetched_at = :status_fetched_at WHERE id = :id'
        )->execute([
            ':status_json' => json_encode(['gpu' => $gpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        )->execute([
            ':station_id' => (int)$station['id'],
            ':sampled_at' => $snapshotAt,
            ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
        ]);

        $model = hub_admin_dashboard_model($db, ['station' => 'station_1080']);

        hub_test_assert(($model['summary']['gpu']['util_percent'] ?? null) === 73, 'child Dashboard must retain current compact GPU utilization');
        hub_test_assert(($model['summary']['gpu']['temperature_c'] ?? null) === 66, 'child Dashboard must retain current compact GPU temperature');
        hub_test_assert($model['summary']['gpu_history']['temperature'] === [['label' => $snapshotAt, 'value' => 66.0]], 'child Dashboard must expose selected station temperature history');
        hub_test_assert($model['summary']['gpu_history']['vram_used'] === [['label' => $snapshotAt, 'value' => 12288.0]], 'child Dashboard must expose selected station VRAM history');
    });
});

hub_test('child dashboard honors legacy and explicit GPU availability states', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $snapshotAt = date('Y-m-d H:i:s', time() - 30);
        $update = $db->prepare(
            'UPDATE cluster_stations SET status_json = :status_json, status_fetched_at = :status_fetched_at WHERE id = :id'
        );
        $legacyGpu = [
            'memory_total_mb' => 16384,
            'memory_free_mb' => 4096,
            'util_percent' => 73,
            'temperature_c' => 66,
        ];
        $update->execute([
            ':status_json' => json_encode(['gpu' => $legacyGpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $legacy = hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['gpu'];
        hub_test_assert($legacy['available'] === true, 'legacy child GPU with VRAM must remain available');
        hub_test_assert($legacy['util_percent'] === 73 && $legacy['temperature_c'] === 66, 'legacy child GPU metrics must remain visible');

        $unavailableGpu = array_replace($legacyGpu, ['available' => false]);
        $update->execute([
            ':status_json' => json_encode(['gpu' => $unavailableGpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $unavailable = hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['gpu'];
        hub_test_assert($unavailable['available'] === false, 'explicit unavailable child GPU state must remain unavailable');
        foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
            hub_test_assert(!array_key_exists($field, $unavailable), 'unavailable child GPU metrics must not reach Dashboard cards: ' . $field);
        }
    });
});

hub_test('child GPU history honors legacy and explicit availability states', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_admin_dashboard_station($db);
        $legacyAt = date('Y-m-d H:i:s', time() - 120);
        $unavailableAt = date('Y-m-d H:i:s', time() - 60);
        $insert = $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        );
        foreach ([
            [$legacyAt, ['temperature_c' => 66, 'memory_used_mb' => 400]],
            [$unavailableAt, ['available' => false, 'temperature_c' => 67, 'memory_used_mb' => 500]],
        ] as [$sampledAt, $gpu]) {
            $insert->execute([
                ':station_id' => (int)$station['id'],
                ':sampled_at' => $sampledAt,
                ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
            ]);
        }

        $history = hub_admin_dashboard_station_gpu_history($db, (int)$station['id'], date('Y-m-d H:i:s', time() - 86400));
        hub_test_assert(
            $history === [
                'temperature' => [['label' => $legacyAt, 'value' => 66.0]],
                'vram_used' => [['label' => $legacyAt, 'value' => 400.0]],
            ],
            'legacy child history must render while explicit false remains hidden'
        );
    });
});

hub_test('dashboard GPU history omits unavailable and missing metric values', function (): void {
    $recentAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => $recentAt, 'gpu' => ['available' => false, 'temperature_c' => 0, 'memory_used_mb' => 0]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true, 'temperature_c' => 'unknown', 'memory_used_mb' => null]],
    ]);

    hub_test_assert($history === ['temperature' => [], 'vram_used' => []], 'unavailable or missing GPU metrics must not create zero-valued history points');
});

hub_test('dashboard GPU history rejects malformed future and expired timestamps', function (): void {
    $recentAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => 'not-a-timestamp', 'gpu' => ['available' => true, 'temperature_c' => 40, 'memory_used_mb' => 100]],
        ['sampled_at' => date('Y-m-d H:i:s', time() + 60), 'gpu' => ['available' => true, 'temperature_c' => 41, 'memory_used_mb' => 101]],
        ['sampled_at' => date('Y-m-d H:i:s', time() - 86401), 'gpu' => ['available' => true, 'temperature_c' => 42, 'memory_used_mb' => 102]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 400]],
    ]);

    hub_test_assert(
        $history === [
            'temperature' => [['label' => $recentAt, 'value' => 66.0]],
            'vram_used' => [['label' => $recentAt, 'value' => 400.0]],
        ],
        'GPU history must accept only canonical timestamps inside the 24-hour window'
    );
});

hub_test('dashboard GPU history keeps the latest same-second sample aligned', function (): void {
    $sampledAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => $sampledAt, 'gpu' => ['available' => true, 'temperature_c' => 55, 'memory_used_mb' => 100]],
        ['sampled_at' => $sampledAt, 'gpu' => ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 200]],
    ]);

    hub_test_assert(
        $history === [
            'temperature' => [['label' => $sampledAt, 'value' => 66.0]],
            'vram_used' => [['label' => $sampledAt, 'value' => 200.0]],
        ],
        'same-second GPU history must retain the latest aligned sample only'
    );
});

hub_test('dashboard page uses accepted local assets and query-backed station tabs', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    $script = (string)file_get_contents(HUB_ROOT . '/assets/js/admin-dashboard.js');
    $dashboardCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-dashboard.css');
    $baseCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-base.css');

    foreach ([
        '../assets/css/admin-dashboard.css',
        '../assets/js/vendor/chart.umd.js',
        'id="dashboard-data"',
        '../assets/js/admin-dashboard.js',
        'class="station-tabs"',
        "rawurlencode(\$station['station_key'])",
        "hub_h(\$station['label'])",
    ] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard page missing contract: ' . $needle);
    }
    foreach (['cdn.jsdelivr', 'echarts', 'GPU1', 'GPU2', 'GPU3'] as $forbidden) {
        hub_test_assert(!str_contains($page, $forbidden), 'dashboard page contains forbidden source or station label: ' . $forbidden);
    }
    foreach (['fetch(', 'localStorage', 'sessionStorage'] as $forbidden) {
        hub_test_assert(!str_contains($script, $forbidden), 'dashboard script must use server JSON without persistent state: ' . $forbidden);
    }
    hub_test_assert(str_contains($script, 'maintainAspectRatio: false'), 'dashboard charts must preserve accepted container sizing');
    hub_test_assert(str_contains($script, 'ResizeObserver'), 'dashboard charts must respond to container resize');
    foreach (['station-tab__status', 'connection_state', '可連線', '無法連線'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard station connectivity markup missing ' . $needle);
    }
    foreach (['station-tab__status', 'station-tab__status--online'] as $needle) {
        hub_test_assert(str_contains($dashboardCss, $needle), 'dashboard station connectivity style missing ' . $needle);
    }
    foreach (['station-connection', 'station-connection--online'] as $needle) {
        hub_test_assert(str_contains($baseCss, $needle), 'shared station connectivity style missing ' . $needle);
    }
});
