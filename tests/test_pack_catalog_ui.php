<?php
declare(strict_types=1);

hub_test('admin pack catalog tabs render expected contract', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    $helper = (string)file_get_contents(HUB_ROOT . '/app/admin_market.php');

    hub_test_assert(str_contains($page, "require_once __DIR__ . '/../app/admin_market.php'"), 'packs page must load shared Market helper');
    foreach (['hub_admin_market_category', 'hub_admin_market_category_for_manifest', 'hub_admin_market_runtime_badge_class', 'hub_admin_market_model_label', 'hub_admin_market_categories'] as $fn) {
        hub_test_assert(str_contains($helper, 'function ' . $fn), 'shared Market helper missing ' . $fn);
    }
    foreach (['hub_admin_market_catalog', 'hub_admin_market_runtime_badge_class', 'hub_admin_market_model_label', 'hub_admin_market_categories'] as $fn) {
        hub_test_assert(str_contains($page, $fn), 'packs page must call ' . $fn);
    }
    hub_test_assert(str_contains($page, 'packs.php?tab='), 'packs tab link missing');

    foreach (['全部', '參考樣板', '視覺影像', '語言文字', '音訊語音', '工具', '實驗中'] as $label) {
        hub_test_assert(str_contains($helper, $label), 'localized tab label missing ' . $label);
    }

    foreach (['套件名稱', '套件 ID', '執行層級', 'L5 可驗收', '已安裝服務', 'modes:', '目前沒有音訊語音套件。'] as $needle) {
        hub_test_assert(str_contains($page . $helper, $needle), 'pack catalog contract missing ' . $needle);
    }

    foreach (['安裝為服務', '查看 API 文件', 'Benchmark 測試', '準備狀態', '已安裝服務'] as $action) {
        hub_test_assert(str_contains($page, $action), 'packs action missing ' . $action);
    }

    foreach (['pack_id', 'mode', 'runtime_level', 'endpoint'] as $technicalValue) {
        hub_test_assert(str_contains($page, $technicalValue), 'technical value label should stay English ' . $technicalValue);
    }

    hub_test_assert(!str_contains($page, 'function hub_pack_catalog_tab'), 'packs page must not duplicate category helpers');
    hub_test_assert(!str_contains($page, 'function hub_pack_runtime_label'), 'packs page must not duplicate runtime helpers');

    $db = hub_test_reset_db();
    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'all'];
    ob_start();
    require HUB_ROOT . '/admin/packs.php';
    $html = (string)ob_get_clean();

    foreach (['pack-card', 'hello-service', 'ocr-ppocrv5', 'yolo', 'sam3', 'translate-gemma12b', 'L5 可驗收', '已安裝服務'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered packs page missing ' . $needle);
    }

    hub_test_assert(hub_admin_market_category('not_a_tab') === 'all', 'unknown tab must fall back to all');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('hello')['manifest']) === 'reference', 'hello must be reference tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('ocr-ppocrv5')['manifest']) === 'vision', 'ocr must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('yolo')['manifest']) === 'vision', 'yolo must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('sam3')['manifest']) === 'vision', 'sam3 must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('translate-gemma12b')['manifest']) === 'language', 'translate must be language tab');
});
