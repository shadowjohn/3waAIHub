<?php
declare(strict_types=1);

hub_test('PhaseUI-5 log explorer tabs and services job links contract', function (): void {
    $logPage = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    $servicesPage = (string)file_get_contents(HUB_ROOT . '/admin/services.php');

    foreach (['tab=api', 'tab=jobs', "'service' => '服務記錄'", "'system' => '系統記錄'", 'API 記錄', '背景工作', '服務記錄', '系統記錄'] as $needle) {
        hub_test_assert(str_contains($logPage, $needle), 'log explorer tabs missing ' . $needle);
    }
    foreach (['name="status"', 'name="action"', 'name="service_id"', 'name="keyword"', 'name="time_from"', 'name="time_to"'] as $needle) {
        hub_test_assert(str_contains($logPage, $needle), 'jobs filter missing ' . $needle);
    }
    foreach (['hub_command_status_label', 'hub_command_action_label', 'stdout_tail', 'stderr_tail'] as $needle) {
        hub_test_assert(str_contains($logPage, $needle), 'jobs helper/rendering missing ' . $needle);
    }

    hub_test_assert(!str_contains($servicesPage, '<h2>近期背景工作</h2>'), 'services page should not render full recent jobs history');
    hub_test_assert(str_contains($servicesPage, 'log_explorer.php?tab=jobs'), 'services page must link to background jobs tab');
    hub_test_assert(str_contains($servicesPage, '查看背景工作'), 'services page must show background jobs link');
    hub_test_assert(str_contains($servicesPage, '查看此服務工作'), 'service card must link to service-specific jobs');
    foreach (['service_key', 'pack_id', 'mode', 'runtime_level'] as $technical) {
        hub_test_assert(str_contains($servicesPage, $technical), 'technical value should stay English ' . $technical);
    }
});

hub_test('PhaseUI-5 background jobs tab renders command_jobs with filters', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'ui5-test'], 1, '127.0.0.1');

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/log_explorer.php';
    $_GET = [
        'tab' => 'jobs',
        'status' => 'queued',
        'action' => 'service_start',
        'service_id' => (string)$service['id'],
        'keyword' => 'Queued',
    ];

    ob_start();
    require HUB_ROOT . '/admin/log_explorer.php';
    $html = (string)ob_get_clean();

    foreach (['背景工作', '啟動服務', 'service_start', '排隊中', 'hello-service', 'hello-main', 'stdout_tail', 'stderr_tail'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'jobs tab render missing ' . $needle);
    }
    hub_test_assert(str_contains($html, 'tab=api'), 'jobs tab should keep API tab link');
    hub_test_assert(str_contains($html, 'tab=jobs'), 'jobs tab should keep jobs tab link');
    hub_test_assert(hub_log_explorer_tab('unknown') === 'api', 'unknown tab should fallback to api');
});
