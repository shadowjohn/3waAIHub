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
    foreach (['admin-base.css', 'admin-shell.css', 'admin-shell.js', 'branding_asset.php'] as $asset) {
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

hub_test('admin compatibility controls yield to rendered shell components', function (): void {
    $base = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-base.css');
    foreach ([
        ':where(body.app) :where(button, .button)',
        ':where(body.app) :where(input, select, textarea)',
        ':where(body.app) :where(label)',
    ] as $selector) {
        hub_test_assert(str_contains($base, $selector), 'low-specificity compatibility selector missing: ' . $selector);
    }

    $db = hub_test_reset_db();
    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/index.php';
    ob_start();
    hub_admin_header('控制台', $admin);
    hub_admin_footer();
    $html = (string)ob_get_clean();
    hub_test_assert(str_contains($html, '<button type="button" class="mainnav__link'), 'rendered shell submenu control missing');
});

hub_test('admin drawer uses one 1400px keyboard-safe DOM contract', function (): void {
    $db = hub_test_reset_db();
    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/index.php';
    ob_start();
    hub_admin_header('控制台', $admin);
    hub_admin_footer();
    $html = (string)ob_get_clean();

    $navStart = strpos($html, '<nav class="mainnav"');
    $navClose = strpos($html, 'id="nav-close"');
    $navEnd = strpos($html, '</nav>', (int)$navStart);
    hub_test_assert($navStart !== false && $navClose !== false && $navEnd !== false && $navStart < $navClose && $navClose < $navEnd, 'drawer close button must render inside mainnav');
    hub_test_assert(substr_count($html, 'data-drawer-inert') === 5, 'non-drawer inert areas mismatch');

    $css = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-shell.css');
    foreach (['@media (min-width: 1400px)', '@media (max-width: 1399px)', '.navclose'] as $needle) {
        hub_test_assert(str_contains($css, $needle), 'drawer CSS contract missing: ' . $needle);
    }

    $js = (string)file_get_contents(HUB_ROOT . '/assets/js/admin-shell.js');
    foreach ([
        "window.matchMedia('(min-width: 1400px)')",
        "document.querySelectorAll('[data-drawer-inert]')",
        '.inert = inert',
        "event.key !== 'Tab'",
        "navClose.addEventListener('click'",
    ] as $needle) {
        hub_test_assert(str_contains($js, $needle), 'drawer keyboard contract missing: ' . $needle);
    }
});

hub_test('admin visual assets use zero tracking and no login glow decoration', function (): void {
    foreach (['admin-base.css', 'admin-shell.css', 'admin-login.css'] as $file) {
        $css = (string)file_get_contents(HUB_ROOT . '/assets/css/' . $file);
        preg_match_all('/letter-spacing\s*:\s*([^;}]+)/', $css, $matches);
        foreach ($matches[1] as $value) {
            hub_test_assert(trim($value) === '0', $file . ' contains nonzero letter-spacing: ' . trim($value));
        }
    }

    $login = (string)file_get_contents(HUB_ROOT . '/login.php');
    $loginCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-login.css');
    $background = (string)file_get_contents(HUB_ROOT . '/assets/images/login-bg.svg');
    hub_test_assert(!str_contains($login, 'bg__orb'), 'login orb markup remains');
    hub_test_assert(!str_contains($loginCss, '.bg__orb') && !str_contains($loginCss, 'radial-gradient'), 'login orb CSS remains');
    foreach (['radialGradient', '<ellipse', 'glowBlue', 'glowCyan', 'glowIndigo'] as $needle) {
        hub_test_assert(!str_contains($background, $needle), 'login background glow remains: ' . $needle);
    }

    $sources = (string)file_get_contents(HUB_ROOT . '/assets/fonts/SOURCES.md');
    hub_test_assert(str_contains($sources, '564ce565c371c5e5bbf286006565a7c9aa55a9f56e7ca58d56e05d649dd61a72'), 'Space Grotesk OFL hash mismatch');
});

hub_test('admin shell keeps the mobile brand from pushing controls off screen', function (): void {
    $css = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-shell.css');
    hub_test_assert(
        preg_match('/@media\s*\(max-width:\s*480px\)[^{]*\{[^}]*\.appbrand__name\s*\{\s*display:\s*none\s*;/s', $css) === 1,
        'shared shell must hide the long brand name on narrow screens'
    );
});
