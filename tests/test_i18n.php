<?php
declare(strict_types=1);

hub_test('i18n sqlite table helper and language cookie contract work', function (): void {
    $db = hub_test_reset_db();

    $exists = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='i18n'")->fetchColumn();
    hub_test_assert($exists === 'i18n', 'i18n table missing');

    hub_test_assert(__('控制台', 'zh_TW') === '控制台', 'zh_TW should return source text without lookup');

    $db->prepare('INSERT INTO i18n (title, lang, trans) VALUES (:title, :lang, :trans)')
        ->execute([':title' => '控制台', ':lang' => 'en', ':trans' => 'Dashboard']);
    hub_test_assert(__('控制台', 'en') === 'Dashboard', 'i18n helper should read latest translation');
    hub_test_assert(__('控制台', 'bad_lang') === '控制台', 'invalid language should fall back to zh_TW');

    $_COOKIE['USER_LANG'] = 'ja';
    hub_test_assert(hub_i18n_current_lang() === 'ja', 'current lang should read USER_LANG cookie');
    $_COOKIE['USER_LANG'] = 'zh_TW';
});

hub_test('i18n seed imports without overwriting local translations', function (): void {
    $db = hub_test_reset_db();
    $seed = sys_get_temp_dir() . '/3waaihub_i18n_seed_' . getmypid() . '.json';
    file_put_contents($seed, json_encode([
        ['title' => '控制台', 'lang' => 'en', 'trans' => 'Dashboard'],
        ['title' => '服務管理', 'lang' => 'en', 'trans' => 'Services'],
    ], JSON_UNESCAPED_UNICODE));

    hub_test_assert(hub_i18n_import_seed($db, $seed) === 2, 'seed should import two rows');
    hub_test_assert(__('控制台', 'en') === 'Dashboard', 'seed translation missing');

    $db->prepare('UPDATE i18n SET trans = :trans WHERE title = :title AND lang = :lang')
        ->execute([':trans' => 'Local Dashboard', ':title' => '控制台', ':lang' => 'en']);
    hub_test_assert(hub_i18n_import_seed($db, $seed) === 0, 'seed import must not overwrite local rows');
    hub_test_assert(__('控制台', 'en') === 'Local Dashboard', 'local translation must win');

    $export = hub_i18n_export_seed($db);
    hub_test_assert(count($export) >= 2, 'export seed should include imported rows');
    hub_test_assert(is_file(HUB_ROOT . '/scripts/export_i18n_seed.php'), 'export_i18n_seed.php missing');
});

hub_test('admin i18n maintenance tab and language selectors are present', function (): void {
    foreach ([
        HUB_ROOT . '/admin/settings.php',
        HUB_ROOT . '/admin/i18n.php',
        HUB_ROOT . '/admin/_layout.php',
        HUB_ROOT . '/index.php',
    ] as $path) {
        hub_test_assert(is_file($path), basename($path) . ' missing');
    }

    $settingsPage = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach (['settings.php?tab=i18n', '多國語系', '新增翻譯', 'i18n', 'USER_LANG'] as $needle) {
        hub_test_assert(str_contains($settingsPage, $needle), 'settings i18n tab missing ' . $needle);
    }

    $legacyPage = (string)file_get_contents(HUB_ROOT . '/admin/i18n.php');
    hub_test_assert(str_contains($legacyPage, 'settings.php?tab=i18n'), 'legacy i18n page should redirect to settings tab');

    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    hub_test_assert(str_contains($layout, 'hub_i18n_language_selector'), 'admin layout missing language selector');
    hub_test_assert(!str_contains($layout, 'href="i18n.php"'), 'admin nav should not expose standalone i18n page');
    hub_test_assert(str_contains($layout, "__('控制台')"), 'admin nav labels must call __()');

    $home = (string)file_get_contents(HUB_ROOT . '/index.php');
    hub_test_assert(str_contains($home, 'hub_i18n_language_selector'), 'home page missing language selector');
    hub_test_assert(str_contains($home, "__('公開 API 文件')"), 'home page links must call __()');
});

hub_test('admin dashboard primary labels use i18n helper', function (): void {
    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    foreach ([
        "__('總覽中控台')",
        "__('總覽摘要')",
        "__('服務總數')",
        "__('API 24h 呼叫數')",
        "__('平台能力矩陣')",
        "__('最近背景工作')",
        "__('待處理項')",
        "__('服務管理')",
    ] as $needle) {
        hub_test_assert(str_contains($dashboard, $needle), 'dashboard label must call __(): ' . $needle);
    }
});

hub_test('admin models page primary labels use i18n helper', function (): void {
    $models = (string)file_get_contents(HUB_ROOT . '/admin/models.php');
    foreach ([
        "__('模型倉庫')",
        "__('模型根目錄概覽')",
        "__('磁碟空間')",
        "__('連結服務')",
        "__('常見模型子目錄')",
        "__('建立子目錄')",
        "__('模型檔案清單')",
    ] as $needle) {
        hub_test_assert(str_contains($models, $needle), 'models page label must call __(): ' . $needle);
    }
});

hub_test('admin settings page primary labels use i18n helper', function (): void {
    $settings = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach ([
        "__('系統設定')",
        "__('基本設定')",
        "__('介面顯示')",
        "__('多國語系')",
        "__('儲存與模型')",
        "__('API 與安全')",
        "__('Docker 與背景工作')",
        "__('維護與保留')",
        "__('帳號密碼')",
    ] as $needle) {
        hub_test_assert(str_contains($settings, $needle), 'settings page label must call __(): ' . $needle);
    }
});

hub_test('admin marketplace page primary labels use i18n helper', function (): void {
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    foreach ([
        "__('HubPack 套件')",
        "__('本機 HubPack 安裝目錄')",
        "__('套件名稱')",
        "__('安裝為服務')",
        "__('查看 API 文件')",
    ] as $needle) {
        hub_test_assert(str_contains($marketplace, $needle), 'marketplace page label must call __(): ' . $needle);
    }
});

hub_test('customer portal and playground primary labels use i18n helper', function (): void {
    $pages = [
        'admin/my_services.php' => ["__('我的服務')", "__('到 API 測試場')", "__('API 文件')"],
        'admin/my_tokens.php' => ["__('我的 Token')", "__('建立 Token')", "__('Token 列表')"],
        'admin/my_ip_whitelist.php' => ["__('IP 白名單')", "__('選擇 Token')", "__('目前規則')"],
        'admin/my_usage.php' => ["__('用量統計')", "__('目前尚無用量紀錄。')"],
        'admin/change_password.php' => ["__('變更密碼')", "__('目前密碼')", "__('更新密碼')"],
        'admin/playground.php' => ["__('API 測試場')", "__('選擇服務')", "__('請求')", "__('回應結果')", "__('介接範例')"],
    ];

    foreach ($pages as $relativePath => $needles) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $relativePath);
        foreach ($needles as $needle) {
            hub_test_assert(str_contains($source, $needle), $relativePath . ' label must call __(): ' . $needle);
        }
    }
});
