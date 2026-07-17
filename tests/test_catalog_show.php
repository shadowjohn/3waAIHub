<?php
declare(strict_types=1);

hub_test('PhaseShow-1 catalog show contract files and labels exist', function (): void {
    foreach ([
        'catalog_show/index.php',
        'catalog_show/api_proxy.php',
        'catalog_show/templates/layout.php',
        'catalog_show/assets/catalog.css',
        'catalog_show/assets/catalog.js',
        'app/catalog_show.php',
    ] as $file) {
        hub_test_assert(is_file(HUB_ROOT . '/' . $file), 'catalog_show missing ' . $file);
    }

    $index = (string)file_get_contents(HUB_ROOT . '/catalog_show/index.php');
    foreach (['3waAIHub 火力展示', 'api_proxy.php'] as $needle) {
        hub_test_assert(str_contains($index, $needle), 'catalog_show index missing ' . $needle);
    }
    $helpers = (string)file_get_contents(HUB_ROOT . '/app/catalog_show.php');
    foreach (['OCR', 'YOLO', 'SAM3', 'Gemma Chat', 'Photo Vision', 'DocParser'] as $needle) {
        hub_test_assert(str_contains($helpers, $needle), 'catalog_show items missing ' . $needle);
    }
    hub_test_assert(!str_contains($index, '3wa_live_'), 'catalog_show must not embed real token');
});

hub_test('PhaseShow-1 catalog show filters user modes by owned token permissions', function (): void {
    $db = hub_test_reset_db();
    require_once HUB_ROOT . '/app/catalog_show.php';

    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-catalog-show',
        'name' => 'OCR Catalog Show',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18310,
    ]);
    hub_install_pack($db, 'yolo', [
        'service_key' => 'yolo-catalog-show',
        'name' => 'YOLO Catalog Show',
        'mode' => 'yolo',
        'port_mode' => 'manual',
        'local_port' => 18311,
    ]);

    $customerId = hub_create_customer_user($db, [
        'username' => 'catalog_show_user',
        'password' => 'customer123',
        'modes' => ['ocr'],
    ]);
    $token = hub_create_customer_token($db, $customerId, 'Catalog OCR token');
    $customer = hub_get_user($db, $customerId);
    hub_test_assert($customer !== null, 'catalog customer missing');

    $modes = hub_catalog_show_user_modes($db, $customer);
    hub_test_assert($modes === ['ocr'], 'catalog_show must expose only owned token modes');

    $picked = hub_catalog_show_pick_user_token($db, $customer, 'ocr');
    hub_test_assert(is_array($picked) && (int)$picked['id'] === (int)$token['token_id'], 'catalog_show must pick owned token for allowed mode');
    hub_test_assert(hub_catalog_show_pick_user_token($db, $customer, 'yolo') === null, 'catalog_show must not pick token for disallowed mode');
});

hub_test('PhaseShow-1 catalog show proxy keeps gateway auth boundary', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/catalog_show/api_proxy.php');
    foreach (['hub_require_login', 'hub_catalog_show_session_token', 'Authorization: Bearer', 'hub_check_csrf'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'catalog_show proxy missing ' . $needle);
    }
    hub_test_assert(!str_contains($source, 'AIHUB_REQUIRE_API_TOKEN'), 'catalog_show proxy must not disable gateway token auth');
});
