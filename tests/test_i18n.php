<?php
declare(strict_types=1);

function hub_test_admin_i18n_post(array $input): string
{
    $post = array_merge([
        'csrf_token' => 'test',
        'form_type' => 'i18n',
        'tab' => 'i18n',
        'action' => 'save',
    ], $input);
    $script = 'require ' . var_export(HUB_ROOT . '/app/bootstrap.php', true) . ';'
        . '$_SESSION = ["user_id" => 1, "username" => "admin", "csrf_token" => "test"];'
        . '$_SERVER["REQUEST_METHOD"] = "POST";'
        . '$_POST = ' . var_export($post, true) . ';'
        . 'ob_start(); require ' . var_export(HUB_ROOT . '/admin/settings.php', true) . '; echo ob_get_clean();';
    $result = hub_run_command([PHP_BINARY, '-r', $script], 30);
    hub_test_assert($result['exit_code'] === 0, 'settings i18n request failed: ' . $result['output']);

    return (string)$result['stdout'];
}

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

hub_test('seed keys require a semantic dotted ASCII namespace', function (): void {
    foreach (['pack.demo.description', 'ui.admin-title.copy_2', 'pack.3.description'] as $key) {
        hub_test_assert(hub_i18n_is_seed_key($key), 'valid seed key rejected: ' . $key);
    }
    foreach (['.', 'foo', 'foo..bar', '3.14', 'foo.---.bar', 'foo.*.bar'] as $key) {
        hub_test_assert(!hub_i18n_is_seed_key($key), 'malformed seed key accepted: ' . $key);
    }
});

hub_test('missing keyed translations return fallback without natural translation lookup', function (): void {
    $db = hub_test_reset_db();
    $db->prepare('INSERT INTO i18n (title, lang, trans) VALUES (:title, :lang, :trans)')
        ->execute([':title' => 'Manifest fallback', ':lang' => 'en', ':trans' => 'Translated fallback']);
    $before = (int)$db->query("SELECT COUNT(*) FROM i18n WHERE title = 'Manifest fallback'")->fetchColumn();

    hub_test_assert(
        hub_i18n_seeded('pack.missing.description', 'Manifest fallback', 'en', $db) === 'Manifest fallback',
        'missing keyed translation must return the original fallback'
    );
    $after = (int)$db->query("SELECT COUNT(*) FROM i18n WHERE title = 'Manifest fallback'")->fetchColumn();
    hub_test_assert($after === $before, 'missing keyed translation must not add a natural fallback row');
});

hub_test('Google translation failure opens a request-local circuit breaker', function (): void {
    hub_i18n_google_circuit_state(false);
    $calls = 0;
    $failed = hub_i18n_translate_google(
        '控制台',
        'en',
        'zh_TW',
        static function (string $url) use (&$calls): array {
            $calls++;
            return ['status' => 503, 'body' => ''];
        }
    );
    hub_test_assert($failed === '', 'failed translation must return an empty result');
    hub_test_assert(hub_i18n_google_circuit_state(), 'failed translation must open the request-local circuit');

    $blocked = hub_i18n_translate_google(
        '安裝套件',
        'en',
        'zh_TW',
        static function (string $url) use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '[[["Install Packs"]]]'];
        }
    );
    hub_test_assert($blocked === '', 'open translation circuit must return immediately');
    hub_test_assert($calls === 1, 'open translation circuit must not call the transport again');

    hub_i18n_google_circuit_state(false);
    $translated = hub_i18n_translate_google(
        '控制台',
        'en',
        'zh_TW',
        static fn (string $url): array => ['status' => 200, 'body' => '[[["Dashboard"]]]']
    );
    hub_test_assert($translated === 'Dashboard', 'translation must work again after the request-local circuit is reset');
    hub_i18n_google_circuit_state(false);
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

hub_test('i18n seed round-trip keeps keyed Chinese rows and rejects malformed input', function (): void {
    $db = hub_test_reset_db();
    $seed = sys_get_temp_dir() . '/3waaihub_i18n_keyed_seed_' . getmypid() . '.json';
    file_put_contents($seed, json_encode([
        ['title' => 'pack.demo.description', 'lang' => 'zh-TW', 'trans' => '中文用途'],
        ['title' => 'ui.banner.title', 'lang' => 'zh_TW', 'trans' => '橫幅標題'],
        ['title' => '控制台', 'lang' => 'en', 'trans' => 'Dashboard'],
        ['title' => '自然語句', 'lang' => 'zh_TW', 'trans' => '不應匯入'],
        ['title' => 'foo..bar', 'lang' => 'zh_TW', 'trans' => '不應匯入'],
        ['title' => '3.14', 'lang' => 'zh_TW', 'trans' => '不應匯入'],
        ['title' => 'pack.unknown.description', 'lang' => 'xx', 'trans' => '不應匯入'],
        ['title' => 'pack.missing.description', 'trans' => '不應匯入'],
        'not-an-array',
    ], JSON_UNESCAPED_UNICODE));

    hub_test_assert(hub_i18n_import_seed($db, $seed) === 3, 'seed import must accept only valid rows');
    hub_test_assert(hub_i18n_seeded('pack.demo.description', 'Fallback', 'zh_TW', $db) === '中文用途', 'keyed Chinese seed missing');
    hub_test_assert(hub_i18n_seeded('', 'Fallback', 'zh_TW', $db) === 'Fallback', 'empty key should keep natural-language fallback');
    hub_test_assert(hub_i18n_import_seed($db, $seed) === 0, 'keyed seed import must remain idempotent');

    $db->prepare('INSERT INTO i18n (title, lang, trans) VALUES (:title, :lang, :trans)')
        ->execute([':title' => '直接寫入的自然語句', ':lang' => 'zh_TW', ':trans' => '不應匯出']);
    $export = hub_i18n_export_seed($db);
    $titles = array_column($export, 'title');
    hub_test_assert(in_array('pack.demo.description', $titles, true), 'keyed zh_TW row missing from export');
    hub_test_assert(in_array('ui.banner.title', $titles, true), 'non-Pack keyed zh_TW row missing from export');
    hub_test_assert(!in_array('直接寫入的自然語句', $titles, true), 'natural zh_TW row must not export');

    $roundTrip = sys_get_temp_dir() . '/3waaihub_i18n_roundtrip_' . getmypid() . '.json';
    file_put_contents($roundTrip, json_encode($export, JSON_UNESCAPED_UNICODE));
    $roundTripDb = new PDO('sqlite::memory:');
    $roundTripDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    hub_migrate($roundTripDb);
    hub_test_assert(hub_i18n_import_seed($roundTripDb, $roundTrip) === 3, 'exported seed must import without losing keyed zh_TW rows');
});

hub_test('admin i18n validation allows only keyed zh_TW titles', function (): void {
    $db = hub_test_reset_db();

    $html = hub_test_admin_i18n_post(['title' => '自然語句', 'lang' => 'zh_TW', 'trans' => '不應新增']);
    hub_test_assert(str_contains($html, '正體中文翻譯標題必須是合法的命名空間 key。'), 'admin zh_TW validation error missing');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM i18n WHERE trans = '不應新增'")->fetchColumn() === 0, 'admin accepted natural zh_TW title');

    hub_test_admin_i18n_post(['title' => 'ui.admin.notice', 'lang' => 'zh_TW', 'trans' => '管理通知']);
    $id = (int)$db->query("SELECT id FROM i18n WHERE title = 'ui.admin.notice' AND lang = 'zh_TW'")->fetchColumn();
    hub_test_assert($id > 0, 'admin rejected valid keyed zh_TW title');

    hub_test_admin_i18n_post(['id' => $id, 'title' => 'foo..bar', 'lang' => 'zh_TW', 'trans' => '不應修改']);
    $savedTitle = (string)$db->query('SELECT title FROM i18n WHERE id = ' . $id)->fetchColumn();
    hub_test_assert($savedTitle === 'ui.admin.notice', 'admin update accepted malformed zh_TW key');

    hub_test_admin_i18n_post(['title' => '自然語句', 'lang' => 'en', 'trans' => 'Natural phrase']);
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM i18n WHERE title = '自然語句' AND lang = 'en'")->fetchColumn() === 1, 'admin rejected natural title for non-zh_TW locale');
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

hub_test('8/7 admin redesign routes visible labels through i18n without changing technical contracts', function (): void {
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    foreach (['控制台', '安裝套件', '客戶管理', 'API 金鑰', 'Cluster 管理', '測試中心', '記錄中心', '系統環境', '系統設定'] as $label) {
        hub_test_assert(
            str_contains($layout, "__('{$label}')"),
            'admin navigation label must call __(): ' . $label
        );
    }

    $marketModel = (string)file_get_contents(HUB_ROOT . '/app/admin_market.php');
    $marketSource = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    foreach (['全部', '參考樣板', '視覺影像', '語言文字', '音訊語音', '工具', '實驗中'] as $label) {
        hub_test_assert(str_contains($marketModel, "'{$label}'"), 'Market category missing: ' . $label);
    }
    hub_test_assert(
        str_contains($marketSource, 'hub_h(__((string)$categoryLabel))'),
        'Market category labels must pass through __()'
    );
    foreach (['套件市集', '已安裝服務', '安裝套件工作區'] as $label) {
        hub_test_assert(str_contains($marketSource, "__('{$label}')"), 'Market workspace label must call __(): ' . $label);
    }

    $recordModel = (string)file_get_contents(HUB_ROOT . '/app/admin_records.php');
    $recordSource = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    foreach (['執行歷程', 'API 記錄', '背景工作', '服務記錄', '系統記錄'] as $label) {
        hub_test_assert(str_contains($recordModel, "'{$label}'"), 'Record Center tab missing: ' . $label);
    }
    hub_test_assert(
        str_contains($recordSource, 'hub_h(__($label))'),
        'Record Center tab labels must pass through __()'
    );

    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    foreach ([
        '單機站台',
        '子入口節點',
        '統一入口',
        '聚合站台',
        '資料已過期',
        '站台離線',
        '狀態未知',
    ] as $label) {
        hub_test_assert(str_contains($dashboard, "__('{$label}')"), 'Dashboard state label must call __(): ' . $label);
    }

    $environment = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');
    foreach (['目前版本', '工作樹狀態', '最後檢查時間', '項次', '節點類型', 'Pack數', '狀況'] as $label) {
        hub_test_assert(str_contains($environment, "__('{$label}')"), 'release label must call __(): ' . $label);
    }

    $settings = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach (['上傳 Logo', '上傳並套用', '恢復預設'] as $label) {
        hub_test_assert(str_contains($settings, "__('{$label}')"), 'branding action must call __(): ' . $label);
    }

    foreach (['排隊中', '啟動中', '執行中', '異常'] as $label) {
        hub_test_assert(str_contains($marketSource, "__('{$label}')"), 'service state must call __(): ' . $label);
    }

    foreach ([
        'admin/packs.php',
        'admin/models.php',
        'admin/services.php',
        'admin/api_usage.php',
        'admin/runtime_runs.php',
    ] as $legacyPage) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $legacyPage);
        hub_test_assert(str_contains($source, "__('Legacy debug 頁面')"), $legacyPage . ' notice must call __()');
        hub_test_assert(
            str_contains($source, "__('此頁已退出主選單，正式操作請使用"),
            $legacyPage . ' retirement notice must call __()'
        );
    }

    foreach (['pack_id', 'mode', 'runtime_level', 'target_level', 'endpoint', 'execution_type', 'service_key'] as $technical) {
        hub_test_assert(str_contains($marketSource, $technical), 'technical contract label changed: ' . $technical);
    }

    $seedRows = json_decode((string)file_get_contents(HUB_ROOT . '/i18n/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $englishTitles = [];
    foreach ($seedRows as $row) {
        if (is_array($row) && ($row['lang'] ?? '') === 'en') {
            $englishTitles[(string)($row['title'] ?? '')] = true;
        }
    }
    foreach ([
        'Cluster 管理',
        '測試中心',
        '套件市集',
        '已安裝服務',
        '參考樣板',
        '服務記錄',
        '系統記錄',
        '單機站台',
        '子入口節點',
        '統一入口',
        '聚合站台',
        '資料已過期',
        '上傳 Logo',
        '目前版本',
        'Legacy debug 頁面',
    ] as $title) {
        hub_test_assert(isset($englishTitles[$title]), 'English redesign seed missing: ' . $title);
    }

    foreach ([
        'L5 可驗收',
        'L4b 真實推論',
        'L4a 模型檢查',
        'L3 儲存掛載',
        'L2 依賴檢查',
        'Runtime 未分級',
        '尚未宣告 L5 contract',
        '正常',
        '通過',
        'Ollama 模型拉取',
        '收集服務記錄',
        '環境檢測',
        '權限修正',
        'Docker 清理檢查',
        'Docker builder 清理',
        '基本設定',
        '介面顯示',
        '多國語系',
        '儲存與模型',
        'API 與安全',
        'Docker 與背景工作',
        '維護與保留',
        '帳號密碼',
    ] as $dynamicTitle) {
        hub_test_assert(isset($englishTitles[$dynamicTitle]), 'English dynamic seed missing: ' . $dynamicTitle);
    }

    foreach ([
        'admin/_layout.php',
        'admin/index.php',
        'admin/marketplace.php',
        'admin/log_explorer.php',
        'app/admin_market.php',
        'app/admin_records.php',
    ] as $relativePath) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $relativePath);
        preg_match_all('/__\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*\)/us', $source, $matches);
        foreach ($matches[2] as $literal) {
            $title = trim(stripcslashes((string)$literal));
            hub_test_assert(
                $title === '' || isset($englishTitles[$title]),
                $relativePath . ' English seed missing: ' . $title
            );
        }
    }
});

hub_test('dashboard and playground script translations use HTML-safe JSON', function (): void {
    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    hub_test_assert(
        str_contains($dashboard, 'hub_json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)'),
        'dashboard chart data must use the HTML-safe JSON encoder'
    );

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach ([
        "hub_json_encode(__('顯示 token'), JSON_UNESCAPED_UNICODE)",
        "hub_json_encode(__('隱藏 token'), JSON_UNESCAPED_UNICODE)",
        "hub_json_encode(__('請手動複製。'), JSON_UNESCAPED_UNICODE)",
        "hub_json_encode(__('已複製。'), JSON_UNESCAPED_UNICODE)",
    ] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'playground script translation must use the HTML-safe JSON encoder: ' . $needle);
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
