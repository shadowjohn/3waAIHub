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
