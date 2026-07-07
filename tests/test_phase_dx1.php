<?php
declare(strict_types=1);

hub_test('PhaseDX-1 playground contract is present and renders', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($page, 'hub_require_login'), 'playground must keep admin auth');

    foreach (['API 測試場', '選擇服務', '執行測試', '回應結果', '複製 curl', '複製 PHP', '複製 JS fetch'] as $label) {
        hub_test_assert(str_contains($page, $label), 'playground missing label ' . $label);
    }
    foreach (['mode', 'endpoint', 'request_id', 'runtime_level', 'error_code', 'execution_type'] as $technical) {
        hub_test_assert(str_contains($page, $technical), 'technical value should stay English ' . $technical);
    }
    foreach (['api.php?mode=hello', 'api.php?mode=translate', 'api.php?mode=ocr', 'api.php?mode=yolo', 'api.php?mode=sam3'] as $example) {
        hub_test_assert(str_contains($page, $example), 'example missing ' . $example);
    }
    hub_test_assert(str_contains($page, 'Content-Type: application/json'), 'translate example must include JSON content type');
    hub_test_assert(str_contains($page, 'multipart/form-data'), 'image examples must mention multipart/form-data');
    hub_test_assert(str_contains($page, 'Authorization: Bearer <TOKEN>'), 'examples must use token placeholder');
    hub_test_assert(!str_contains($page, '3wa_live_'), 'playground must not embed real token');

    $db = hub_test_reset_db();
    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-main',
        'name' => 'OCR Main',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18180,
    ]);
    hub_set_service_enabled($db, 'hello', true);
    hub_set_service_enabled($db, 'ocr', true);

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/playground.php';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = ['mode' => 'ocr'];
    ob_start();
    require HUB_ROOT . '/admin/playground.php';
    $html = (string)ob_get_clean();

    foreach (['hello', 'ocr', 'OCR Main', 'name="image"', 'real_inference', 'api.php?mode=ocr', '<TOKEN>'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered playground missing ' . $needle);
    }
});
