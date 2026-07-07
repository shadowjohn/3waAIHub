<?php
declare(strict_types=1);

hub_test('PhaseUI-4 services management card UI contract is present', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/services.php');
    $combined = $page . (string)file_get_contents(HUB_ROOT . '/app/command_queue.php');

    foreach (['全部服務', '執行中', '已停止', '已停用', '背景工作執行中', '最近失敗工作'] as $label) {
        hub_test_assert(str_contains($page, $label), 'summary card missing ' . $label);
    }
    foreach (['service-card', 'data-service-row-id', 'data-service-status', 'data-service-status-label', 'data-service-refresh-form', 'data-service-id'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'service card/ajax hook missing ' . $needle);
    }
    foreach (['啟動', '停止', '重啟', '建置', '重新建置', '刷新狀態', '設定', '服務記錄', 'API 記錄', 'Benchmark', '健康檢查'] as $label) {
        hub_test_assert(str_contains($page, $label), 'localized service action missing ' . $label);
    }
    hub_test_assert(str_contains($page, '舊版 IP 白名單'), 'legacy whitelist link missing');
    hub_test_assert(str_contains($page, '僅保留相容用途'), 'legacy whitelist warning missing');
    hub_test_assert(!str_contains($page, '>Whitelist<'), 'Whitelist must not be a primary action label');
    hub_test_assert(str_contains($page, 'playground.php?mode='), 'playground mode link missing');
    hub_test_assert(str_contains($page, 'api.php?mode='), 'API mode URL missing');
    foreach (['service_key', 'pack_id', 'mode', 'runtime_level', 'endpoint', 'execution_type'] as $technical) {
        hub_test_assert(str_contains($page, $technical), 'technical value should stay English ' . $technical);
    }
    foreach (['service_start', '啟動服務', 'service_build', '建置服務', 'ollama_model_pull', 'Ollama 模型拉取'] as $needle) {
        hub_test_assert(str_contains($combined, $needle), 'job action label missing ' . $needle);
    }

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'ui4-test'], null, '127.0.0.1');
    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/services.php';
    $html = (string)ob_get_clean();
    foreach (['全部服務', 'service-card', 'hello-main', 'playground.php?mode=hello', '舊版 IP 白名單', '啟動服務'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered services page missing ' . $needle);
    }
});
