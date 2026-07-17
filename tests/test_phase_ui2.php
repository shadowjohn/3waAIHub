<?php
declare(strict_types=1);

hub_test('PhaseUI-2 site title defaults and helpers use settings table', function (): void {
    $db = hub_test_reset_db();

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_SITE_TITLE') === '3waAIHub Local', 'site title default mismatch');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_SITE_SUBTITLE') === 'Local AI Service Hub', 'site subtitle default mismatch');
    hub_test_assert(hub_site_title($db) === '3waAIHub Local', 'hub_site_title default mismatch');
    hub_test_assert(hub_site_subtitle($db) === 'Local AI Service Hub', 'hub_site_subtitle default mismatch');

    hub_set_storage_setting($db, 'AIHUB_SITE_TITLE', '羽山 AIHub');
    hub_set_storage_setting($db, 'AIHUB_SITE_SUBTITLE', '本機 AI 服務中樞');
    hub_test_assert(hub_site_title($db) === '羽山 AIHub', 'hub_site_title setting mismatch');
    hub_test_assert(hub_site_subtitle($db) === '本機 AI 服務中樞', 'hub_site_subtitle setting mismatch');
});

hub_test('PhaseUI-2 admin shell is localized and keeps technical labels', function (): void {
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');

    foreach (['控制台', '服務管理', 'HubPack 套件', '模型倉庫', 'API 金鑰', 'API 記錄', 'Benchmark 測試', '系統環境', '系統設定', '登出'] as $label) {
        hub_test_assert(str_contains($layout, $label), 'admin nav missing localized label ' . $label);
    }
    foreach (['hub_site_title', 'hub_site_subtitle', 'HUB_VERSION', 'HUB_RELEASE_LABEL'] as $needle) {
        hub_test_assert(str_contains($layout, $needle), 'admin layout missing ' . $needle);
    }

    $packsPage = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    foreach (['pack_id', 'service_key', 'mode', 'endpoint', 'runtime_level', 'execution_type'] as $technical) {
        hub_test_assert(str_contains($packsPage, $technical), 'technical value must remain English ' . $technical);
    }
});

hub_test('PhaseUI-2 settings tabs and brand fields render expected contract', function (): void {
    $settingsPage = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');

    foreach (['basic', 'appearance', 'i18n', 'storage', 'api', 'docker', 'maintenance', 'account'] as $tab) {
        hub_test_assert(str_contains($settingsPage, "settings.php?tab={$tab}"), 'settings tab link missing ' . $tab);
    }
    foreach (['基本設定', '介面顯示', '多國語系', '儲存與模型', 'API 與安全', 'Docker 與背景工作', '維護與保留', '帳號密碼'] as $label) {
        hub_test_assert(str_contains($settingsPage, $label), 'settings tab label missing ' . $label);
    }
    foreach (['AIHUB_SITE_TITLE', 'AIHUB_SITE_SUBTITLE', 'AIHUB_MODELS_DIR', 'AIHUB_REQUIRE_API_TOKEN', 'AIHUB_AUTO_BUILD_MISSING_IMAGE', 'AIHUB_DB_MAX_SIZE_MB'] as $key) {
        hub_test_assert(str_contains($settingsPage, $key), 'settings page missing key ' . $key);
    }
    hub_test_assert(str_contains($settingsPage, "['basic', 'appearance', 'i18n', 'storage', 'api', 'docker', 'maintenance', 'account']"), 'settings tab allowlist missing');
    hub_test_assert(str_contains($settingsPage, "\$activeTab = 'basic'"), 'unknown settings tab must fall back to basic');

    $loginPage = (string)file_get_contents(HUB_ROOT . '/login.php');
    $dashboardPage = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    hub_test_assert(str_contains($loginPage, 'hub_site_title'), 'login page must use configurable site title');
    hub_test_assert(str_contains($dashboardPage, 'hub_site_title'), 'dashboard must use configurable site title');
});
