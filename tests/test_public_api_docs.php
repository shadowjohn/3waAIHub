<?php
declare(strict_types=1);

hub_test('PhaseDX-3 public API docs policy settings and manifest are safe', function (): void {
    $helperPath = HUB_ROOT . '/app/public_api_docs.php';
    hub_test_assert(is_file($helperPath), 'app/public_api_docs.php missing');
    require_once $helperPath;

    $db = hub_test_reset_db();

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '0', 'public docs default must be disabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST') === '1', 'public manifest default must be enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1', 'public API docs default must be local-only');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST') === true, 'local manifest should be allowed by default');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === false, 'public docs should be disabled by default');

    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '1');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'enabled public docs should allow localhost');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === false, 'local-only docs must block external IP');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '0');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'public docs should allow external IP when local-only is disabled');

    $manifest = hub_public_api_manifest($db);
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    hub_test_assert(is_array($manifest['services'] ?? null), 'manifest services missing');
    foreach (['hello', 'ocr', 'yolo', 'translate', 'sam3'] as $mode) {
        hub_test_assert(in_array($mode, array_column($manifest['services'], 'mode'), true), 'manifest missing mode ' . $mode);
    }
    hub_test_assert(str_contains($json, '<TOKEN>'), 'manifest examples must use token placeholder');
    foreach (['local_port', 'docker-compose.generated.yml', '/DATA/models', '3waaihub.sqlite', 'admin/', '3wa_live_'] as $secret) {
        hub_test_assert(!str_contains($json, $secret), 'manifest must not leak ' . $secret);
    }

    $docsHtml = hub_public_api_docs_html($db);
    hub_test_assert(str_contains($docsHtml, '3waAIHub API 介接文件'), 'public docs title missing');
    hub_test_assert(str_contains($docsHtml, 'Authorization: Bearer &lt;TOKEN&gt;'), 'public docs token placeholder missing');
    hub_test_assert(str_contains($docsHtml, 'mode'), 'public docs must keep technical values');
    hub_test_assert(!str_contains($docsHtml, 'admin/'), 'public docs must not include admin links when not logged in');
});

hub_test('PhaseDX-3 public API docs files and settings UI contract are present', function (): void {
    hub_test_assert(is_file(HUB_ROOT . '/public_api_docs.php'), 'public_api_docs.php missing');
    hub_test_assert(is_file(HUB_ROOT . '/api_manifest.json.php'), 'api_manifest.json.php missing');

    $adminDocs = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(str_contains($adminDocs, 'hub_require_login'), 'admin/api_docs.php must still require login');

    $settingsPage = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach (['AIHUB_PUBLIC_API_DOCS', 'AIHUB_PUBLIC_API_MANIFEST', 'AIHUB_PUBLIC_API_LOCAL_ONLY', '未登入 API 文件', '未登入 Agent Manifest', '僅允許本機讀取'] as $needle) {
        hub_test_assert(str_contains($settingsPage, $needle), 'settings API tab missing ' . $needle);
    }
});
