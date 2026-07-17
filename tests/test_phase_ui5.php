<?php
declare(strict_types=1);

hub_test('PhaseUI-6 dashboard control center polish contract is present and renders', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/index.php');

    foreach (['總覽中控台', '總覽摘要', '服務總數', '健康正常', '健康異常 / 未檢查', 'L5 Pack 數', 'API 24h 呼叫數', 'API 24h 失敗數', '背景工作執行中', '最近失敗工作', '最近背景工作', 'Pack 準備狀態', '模型儲存使用率', '介接公開狀態', '未登入 API 文件', 'Agent Manifest 文件', '僅本機'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard missing ' . $needle);
    }
    foreach (['服務管理', 'API 測試場', 'HubPack 套件', '模型倉庫', 'API 金鑰', '記錄中心', '公開 API 文件', 'Agent Manifest 文件'] as $label) {
        hub_test_assert(str_contains($page, $label), 'dashboard quick link missing ' . $label);
    }
    foreach (['services.php', 'playground.php', 'packs.php', 'models.php', 'api_members.php', 'log_explorer.php', '../public_api_docs.php', '../api_manifest.json.php'] as $href) {
        hub_test_assert(str_contains($page, $href), 'dashboard quick href missing ' . $href);
    }
    foreach (['GPU', 'RAM', '磁碟', '/ 可用空間', 'Docker 根目錄可用', '模型目錄可用', '服務執行中', '服務已停止', '服務已停用'] as $label) {
        hub_test_assert(str_contains($page, $label), 'dashboard status label missing ' . $label);
    }
    foreach (['dash-metric-card', 'metricBar', 'vramUsedLabel', 'VRAM 使用量', 'GPU 使用率', '溫度'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard metric chart contract missing ' . $needle);
    }

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_update_service_status($db, (int)$service['id'], 'running');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'ui5-test'], null, '127.0.0.1');
    $healthJobId = hub_enqueue_command_job($db, 'service_health_check', (int)$service['id'], ['reason' => 'ui6-test'], null, '127.0.0.1');
    $db->prepare("UPDATE command_jobs SET status = 'success', updated_at = :updated_at WHERE id = :id")
        ->execute([':updated_at' => hub_now(), ':id' => $healthJobId]);
    hub_log_api_access($db, $service, 'hello', 200, true, null, null, 12, 'req_ui5_ok', [], 0, 64);
    hub_log_api_access($db, $service, 'hello', 500, false, 'runtime_not_ready', 'runtime pending', 34, 'req_ui5_fail', [], 0, 64);
    hub_save_host_metric_snapshot($db, [
        'gpu' => ['available' => true, 'name' => 'Test GPU', 'util_percent' => 10, 'memory_total_mb' => 100, 'memory_used_mb' => 20, 'temperature_c' => 30],
        'host' => ['ram_used_percent' => 25, 'ram_used_mb' => 1000, 'ram_buff_cache_mb' => 500, 'ram_available_mb' => 2500, 'ram_available_percent' => 62.5, 'memory_pressure' => 'ok', 'vmstat_si' => 0, 'vmstat_so' => 0, 'load_1' => 0.1, 'load_5' => 0.2, 'load_15' => 0.3, 'disk_root' => ['free_gb' => 39, 'total_gb' => 98]],
        'docker' => ['root_dir' => '/DATA/docker', 'root_used_percent' => 20, 'root_free_gb' => 99],
        'storage' => ['models_dir' => hub_test_models_dir(), 'models_used_percent' => 1, 'models_free_gb' => 10, 'models_total_gb' => 11],
        'counts' => ['packs' => 1, 'services' => 1, 'running_services' => 1, 'stopped_services' => 0, 'not_ready_services' => 0, 'error_services' => 0],
    ]);

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/index.php';
    $html = (string)ob_get_clean();

    foreach (['總覽中控台', 'Test GPU', '健康正常', '健康異常 / 未檢查', 'API 24h 呼叫數', 'API 24h 失敗數', '/ 可用空間', 'Docker 根目錄可用', '模型目錄可用', 'req_ui5_fail', '最近背景工作', 'Pack 準備狀態', '模型儲存使用率', 'playground.php', 'log_explorer.php', '介接公開狀態', '../api_manifest.json.php', '20.00 MB / 100.00 MB', '30°C'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered dashboard missing ' . $needle);
    }
});
