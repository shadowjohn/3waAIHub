<?php
declare(strict_types=1);

hub_test('PhaseUI-5 dashboard control center contract is present and renders', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/index.php');

    foreach (['總覽中控台', 'Dashboard summary cards', 'API calls last 24h', 'Failed API calls last 24h', 'Recent command jobs', 'Pack readiness', 'Model storage usage'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard missing ' . $needle);
    }
    foreach (['服務管理', 'API 測試場', '模型倉庫', 'API 金鑰'] as $label) {
        hub_test_assert(str_contains($page, $label), 'dashboard quick link missing ' . $label);
    }
    foreach (['services.php', 'playground.php', 'models.php', 'api_members.php'] as $href) {
        hub_test_assert(str_contains($page, $href), 'dashboard quick href missing ' . $href);
    }
    foreach (['GPU', 'RAM', 'Disk', 'Services running', 'Services stopped', 'Services disabled'] as $label) {
        hub_test_assert(str_contains($page, $label), 'dashboard status label missing ' . $label);
    }

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_update_service_status($db, (int)$service['id'], 'running');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'ui5-test'], null, '127.0.0.1');
    hub_log_api_access($db, $service, 'hello', 200, true, null, null, 12, 'req_ui5_ok', [], 0, 64);
    hub_log_api_access($db, $service, 'hello', 500, false, 'runtime_not_ready', 'runtime pending', 34, 'req_ui5_fail', [], 0, 64);
    hub_save_host_metric_snapshot($db, [
        'gpu' => ['available' => true, 'name' => 'Test GPU', 'util_percent' => 10, 'memory_total_mb' => 100, 'memory_used_mb' => 20, 'temperature_c' => 30],
        'host' => ['ram_used_percent' => 25, 'ram_used_mb' => 1000, 'ram_buff_cache_mb' => 500, 'ram_available_mb' => 2500, 'ram_available_percent' => 62.5, 'memory_pressure' => 'ok', 'vmstat_si' => 0, 'vmstat_so' => 0, 'load_1' => 0.1, 'load_5' => 0.2, 'load_15' => 0.3],
        'docker' => ['root_dir' => '/DATA/docker', 'root_used_percent' => 20],
        'storage' => ['models_dir' => hub_test_models_dir(), 'models_used_percent' => 1, 'models_free_gb' => 10, 'models_total_gb' => 11],
        'counts' => ['packs' => 1, 'services' => 1, 'running_services' => 1, 'stopped_services' => 0, 'not_ready_services' => 0, 'error_services' => 0],
    ]);

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/index.php';
    $html = (string)ob_get_clean();

    foreach (['總覽中控台', 'Test GPU', 'API calls last 24h', 'Failed API calls last 24h', 'req_ui5_fail', 'Recent command jobs', 'Pack readiness', 'Model storage usage', 'playground.php'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered dashboard missing ' . $needle);
    }
});
