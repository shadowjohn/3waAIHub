<?php
declare(strict_types=1);

hub_test('PhaseDX-3.1 home pages expose public API docs links and open access defaults', function (): void {
    $home = (string)file_get_contents(HUB_ROOT . '/index.php');
    foreach (['公開 API 文件', 'Agent Manifest', '後台管理', 'public_api_docs.php', 'api_manifest.json.php', 'admin/', '依系統設定，公開 API 文件可能僅允許本機讀取'] as $needle) {
        hub_test_assert(str_contains($home, $needle), 'root home missing ' . $needle);
    }

    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    foreach (['API 測試場', '後台 API 文件', '公開 API 文件', 'Agent Manifest', 'playground.php', 'api_docs.php', '../public_api_docs.php', '../api_manifest.json.php'] as $needle) {
        hub_test_assert(str_contains($dashboard, $needle), 'dashboard quick link missing ' . $needle);
    }

    $adminDocs = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(str_contains($adminDocs, 'hub_require_login'), 'admin/api_docs.php must still require login');

    $db = hub_test_reset_db();
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1', 'public docs default must remain enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST') === '1', 'public manifest default must remain enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '0', 'public docs local-only default must remain disabled');

    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);
    $missingToken = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($missingToken['status'] === 401, 'external api.php request without token must still be rejected');
});
