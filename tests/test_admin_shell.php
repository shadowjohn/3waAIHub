<?php
declare(strict_types=1);

require_once HUB_ROOT . '/admin/_layout.php';

hub_test('admin shell exposes exactly nine agreed destinations and no legacy navigation', function (): void {
    $db = hub_test_reset_db();
    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/index.php';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/admin/index.php';
    ob_start();
    hub_admin_header('控制台', $admin);
    hub_admin_footer();
    $html = (string)ob_get_clean();

    foreach (['控制台', '安裝套件', '客戶管理', 'API 金鑰', 'Cluster 管理', '測試中心', '記錄中心', '系統環境', '系統設定'] as $label) {
        hub_test_assert(substr_count($html, 'data-top-nav="' . $label . '"') === 1, 'top navigation mismatch: ' . $label);
    }
    foreach (['packs.php', 'models.php', 'services.php', 'api_usage.php', 'runtime_runs.php'] as $legacy) {
        hub_test_assert(!str_contains($html, 'href="' . $legacy), 'legacy URL leaked into navigation: ' . $legacy);
    }
    foreach (['admin-base.css', 'admin-shell.css', 'admin-shell.js', 'assets/images/logo.svg'] as $asset) {
        hub_test_assert(str_contains($html, $asset), 'shell asset missing: ' . $asset);
    }
});

hub_test('admin runtime pages have no CDN dependency', function (): void {
    foreach (['admin/_layout.php', 'login.php', 'admin/marketplace.php'] as $path) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $path);
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com'] as $remote) {
            hub_test_assert(!str_contains($source, $remote), $path . ' contains remote asset ' . $remote);
        }
    }
    foreach ([
        'assets/css/admin-base.css',
        'assets/css/admin-shell.css',
        'assets/css/admin-login.css',
        'assets/js/admin-shell.js',
        'assets/js/vendor/chart.umd.js',
        'assets/images/logo.svg',
        'assets/images/login-bg.svg',
    ] as $path) {
        hub_test_assert(is_file(HUB_ROOT . '/' . $path), 'vendored visual asset missing: ' . $path);
    }
});
