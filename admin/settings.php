<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_settings_tab(string $rawTab): string
{
    $tabs = ['basic', 'appearance', 'storage', 'api', 'docker', 'maintenance', 'account'];
    $activeTab = strtolower(trim($rawTab));
    if (!in_array($activeTab, $tabs, true)) {
        $activeTab = 'basic';
    }

    return $activeTab;
}

function hub_settings_tab_label(string $tab): string
{
    return [
        'basic' => '基本設定',
        'appearance' => '介面顯示',
        'storage' => '儲存與模型',
        'api' => 'API 與安全',
        'docker' => 'Docker 與背景工作',
        'maintenance' => '維護與保留',
        'account' => '帳號密碼',
    ][$tab] ?? '基本設定';
}

function hub_settings_tab_link(string $activeTab, string $tab): string
{
    $class = $activeTab === $tab ? 'settings-tab is-active' : 'settings-tab';
    return '<a class="' . hub_h($class) . '" href="' . hub_h(hub_settings_tab_url($tab)) . '">' . hub_h(hub_settings_tab_label($tab)) . '</a>';
}

function hub_settings_tab_url(string $tab): string
{
    return [
        'basic' => 'settings.php?tab=basic',
        'appearance' => 'settings.php?tab=appearance',
        'storage' => 'settings.php?tab=storage',
        'api' => 'settings.php?tab=api',
        'docker' => 'settings.php?tab=docker',
        'maintenance' => 'settings.php?tab=maintenance',
        'account' => 'settings.php?tab=account',
    ][$tab] ?? 'settings.php?tab=basic';
}

function hub_settings_format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

function hub_settings_path_status(string $path): string
{
    $usage = hub_get_disk_usage_for_path($path);
    if (!$usage['exists']) {
        return '目錄不存在，請用 CLI 建立並設定權限。';
    }

    $free = is_numeric($usage['free_bytes']) ? hub_settings_format_bytes((float)$usage['free_bytes']) : '未知';
    return '存在 / 可讀：' . ($usage['readable'] ? '是' : '否') . ' / 可寫：' . ($usage['writable'] ? '是' : '否') . ' / 可用：' . $free;
}

function hub_settings_validate_unsigned_ints(array $input, array $keys): array
{
    $errors = [];
    foreach ($keys as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '' || !ctype_digit($value)) {
            $errors[] = $key . ' 必須是 0 或正整數。';
        }
    }

    return $errors;
}

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$message = '';
$error = '';
$activeTab = hub_settings_tab((string)($_POST['tab'] ?? $_GET['tab'] ?? 'basic'));
$settings = hub_get_storage_paths($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $formType = (string)($_POST['form_type'] ?? '');
    if ($formType === 'appearance') {
        $title = trim((string)($_POST['AIHUB_SITE_TITLE'] ?? ''));
        $subtitle = trim((string)($_POST['AIHUB_SITE_SUBTITLE'] ?? ''));
        if ($title === '') {
            $error = 'AIHUB_SITE_TITLE 不可空白。';
        } else {
            hub_set_storage_setting($db, 'AIHUB_SITE_TITLE', substr($title, 0, 80));
            hub_set_storage_setting($db, 'AIHUB_SITE_SUBTITLE', substr($subtitle, 0, 120));
            $message = '介面顯示設定已更新。';
        }
    } elseif ($formType === 'storage') {
        $keys = ['AIHUB_MODELS_DIR', 'AIHUB_CACHE_DIR', 'AIHUB_UPLOADS_DIR', 'AIHUB_RESULTS_DIR', 'AIHUB_LOGS_DIR'];
        $input = $settings;
        foreach ($keys as $key) {
            $input[$key] = trim((string)($_POST[$key] ?? ''));
        }
        $errors = hub_validate_storage_input($input);
        if ($errors) {
            $error = implode("\n", $errors);
        } else {
            foreach ($keys as $key) {
                hub_set_storage_setting($db, $key, $input[$key]);
            }
            $message = '儲存與模型設定已更新。';
        }
    } elseif ($formType === 'api') {
        $keys = ['AIHUB_REQUIRE_API_TOKEN', 'AIHUB_LOCALHOST_BYPASS_TOKEN', 'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST', 'AIHUB_TOKEN_DEFAULT_VALID_DAYS', 'AIHUB_PUBLIC_API_DOCS', 'AIHUB_PUBLIC_API_MANIFEST', 'AIHUB_PUBLIC_API_LOCAL_ONLY'];
        $input = $settings;
        foreach ($keys as $key) {
            $input[$key] = trim((string)($_POST[$key] ?? ''));
        }
        $errors = hub_validate_storage_input($input);
        if ($errors) {
            $error = implode("\n", $errors);
        } else {
            foreach ($keys as $key) {
                hub_set_storage_setting($db, $key, $input[$key]);
            }
            $message = 'API 與安全設定已更新。';
        }
    } elseif ($formType === 'docker') {
        $keys = ['AIHUB_DOCKER_PORT_START', 'AIHUB_DOCKER_PORT_END', 'AIHUB_AUTO_BUILD_MISSING_IMAGE'];
        $input = $settings;
        foreach ($keys as $key) {
            $input[$key] = trim((string)($_POST[$key] ?? ''));
        }
        $errors = hub_validate_storage_input($input);
        if ($errors) {
            $error = implode("\n", $errors);
        } else {
            foreach ($keys as $key) {
                hub_set_storage_setting($db, $key, $input[$key]);
            }
            $message = 'Docker 與背景工作設定已更新。';
        }
    } elseif ($formType === 'maintenance') {
        $keys = ['AIHUB_DB_MAX_SIZE_MB', 'AIHUB_LOG_RETENTION_DAYS', 'AIHUB_METRIC_RETENTION_DAYS', 'AIHUB_TASK_RETENTION_DAYS', 'AIHUB_MAX_TASK_LOG_ROWS', 'AIHUB_MAX_RESULT_JSON_BYTES'];
        $input = [];
        foreach ($keys as $key) {
            $input[$key] = trim((string)($_POST[$key] ?? ''));
        }
        $errors = hub_settings_validate_unsigned_ints($input, $keys);
        if ($errors) {
            $error = implode("\n", $errors);
        } else {
            foreach ($keys as $key) {
                hub_set_storage_setting($db, $key, $input[$key]);
            }
            $message = '維護與保留設定已更新。';
        }
    } else {
        $activeTab = 'account';
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($newPassword !== $confirmPassword) {
            $error = '兩次新密碼不一致。';
        } else {
            $error = hub_update_password($db, (int)$user['id'], (string)($_POST['current_password'] ?? ''), $newPassword) ?? '';
            if ($error === '') {
                $message = '密碼已更新。';
                $user = hub_require_system_admin($db);
            }
        }
    }
    $settings = hub_get_storage_paths($db);
}

$storageWarnings = hub_storage_settings_warnings($settings);

hub_admin_header('系統設定', $user);
?>
<style>
    .settings-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
    .settings-tab { border: 1px solid var(--line); border-radius: 8px; color: var(--text); padding: 8px 11px; text-decoration: none; }
    .settings-tab.is-active { background: var(--blue); border-color: var(--blue); color: #fff; }
    .setting-card { border: 1px solid var(--line); border-radius: 8px; margin-top: 14px; padding: 14px; }
    .form-help { color: var(--muted); font-size: 13px; margin: 5px 0 12px; }
</style>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<?php foreach ($storageWarnings as $warning): ?><div class="notice"><?= hub_h($warning) ?></div><?php endforeach; ?>
<section class="panel">
    <h1>系統設定</h1>
    <div class="settings-tabs" aria-label="設定分頁">
        <?= hub_settings_tab_link($activeTab, 'basic') ?>
        <?= hub_settings_tab_link($activeTab, 'appearance') ?>
        <?= hub_settings_tab_link($activeTab, 'storage') ?>
        <?= hub_settings_tab_link($activeTab, 'api') ?>
        <?= hub_settings_tab_link($activeTab, 'docker') ?>
        <?= hub_settings_tab_link($activeTab, 'maintenance') ?>
        <?= hub_settings_tab_link($activeTab, 'account') ?>
    </div>
</section>

<?php if ($activeTab === 'basic'): ?>
<section class="panel">
    <h2>基本設定</h2>
    <table>
        <tr><th>站台標題</th><td><?= hub_h(hub_site_title($db)) ?></td></tr>
        <tr><th>站台副標</th><td><?= hub_h(hub_site_subtitle($db)) ?></td></tr>
        <tr><th>版本</th><td><code><?= hub_h(HUB_VERSION) ?></code> / <?= hub_h(HUB_RELEASE_LABEL) ?></td></tr>
        <tr><th>時區</th><td><code><?= hub_h(date_default_timezone_get()) ?></code></td></tr>
    </table>
    <p><a class="button" href="settings.php?tab=appearance">調整介面顯示</a></p>
</section>
<?php elseif ($activeTab === 'appearance'): ?>
<section class="panel">
    <h2>介面顯示</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="appearance">
        <input type="hidden" name="tab" value="appearance">
        <label>AIHUB_SITE_TITLE</label>
        <input name="AIHUB_SITE_TITLE" value="<?= hub_h($settings['AIHUB_SITE_TITLE']) ?>" required>
        <p class="form-help">顯示於 top bar、login page、dashboard 與 HTML title。</p>
        <label>AIHUB_SITE_SUBTITLE</label>
        <input name="AIHUB_SITE_SUBTITLE" value="<?= hub_h($settings['AIHUB_SITE_SUBTITLE']) ?>">
        <p><button class="primary" type="submit">儲存介面顯示</button></p>
    </form>
</section>
<?php elseif ($activeTab === 'storage'): ?>
<section class="panel">
    <h2>儲存與模型</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="storage">
        <input type="hidden" name="tab" value="storage">
        <?php foreach (['AIHUB_MODELS_DIR' => 'Models Dir', 'AIHUB_CACHE_DIR' => 'Cache Dir', 'AIHUB_UPLOADS_DIR' => 'Uploads Dir', 'AIHUB_RESULTS_DIR' => 'Results Dir', 'AIHUB_LOGS_DIR' => 'Logs Dir'] as $key => $label): ?>
            <label><?= hub_h($label) ?> / <code><?= hub_h($key) ?></code></label>
            <input name="<?= hub_h($key) ?>" value="<?= hub_h($settings[$key]) ?>" required>
            <p class="form-help"><?= hub_h(hub_settings_path_status($settings[$key])) ?></p>
        <?php endforeach; ?>
        <p><button class="primary" type="submit">儲存儲存設定</button></p>
    </form>
</section>
<?php elseif ($activeTab === 'api'): ?>
<section class="panel">
    <h2>API 與安全</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="api">
        <input type="hidden" name="tab" value="api">
        <label>外部 API 必須使用 Bearer token / <code>AIHUB_REQUIRE_API_TOKEN</code></label>
        <select name="AIHUB_REQUIRE_API_TOKEN">
            <option value="1"<?= $settings['AIHUB_REQUIRE_API_TOKEN'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $settings['AIHUB_REQUIRE_API_TOKEN'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>localhost 允許略過 token / <code>AIHUB_LOCALHOST_BYPASS_TOKEN</code></label>
        <select name="AIHUB_LOCALHOST_BYPASS_TOKEN">
            <option value="1"<?= $settings['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $settings['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>Token 驗證後仍套用舊 service IP whitelist / <code>AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST</code></label>
        <select name="AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST">
            <option value="1"<?= $settings['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $settings['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>Token 預設有效天數 / <code>AIHUB_TOKEN_DEFAULT_VALID_DAYS</code></label>
        <input name="AIHUB_TOKEN_DEFAULT_VALID_DAYS" value="<?= hub_h($settings['AIHUB_TOKEN_DEFAULT_VALID_DAYS']) ?>" required>
        <p class="form-help">0 代表建立 token 時不自動設定 valid_until。</p>
        <div class="setting-card">
            <h3>未登入介接文件</h3>
            <p class="form-help">公開 API 文件只包含介接 contract，不包含 token、管理連結、內部路徑或 runtime secrets。API 實際呼叫仍需 Bearer Token。</p>
            <label>未登入 API 文件 / <code>AIHUB_PUBLIC_API_DOCS</code></label>
            <select name="AIHUB_PUBLIC_API_DOCS">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_DOCS'] === '1' ? ' selected' : '' ?>>啟用</option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_DOCS'] === '0' ? ' selected' : '' ?>>停用</option>
            </select>
            <p class="form-help">控制根目錄 <code>public_api_docs.php</code> 是否允許未登入讀取。</p>
            <label>未登入 Agent Manifest / <code>AIHUB_PUBLIC_API_MANIFEST</code></label>
            <select name="AIHUB_PUBLIC_API_MANIFEST">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_MANIFEST'] === '1' ? ' selected' : '' ?>>啟用</option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_MANIFEST'] === '0' ? ' selected' : '' ?>>停用</option>
            </select>
            <p class="form-help">控制根目錄 <code>api_manifest.json.php</code> 是否允許 AI agent 讀取 machine-readable contract。</p>
            <label>僅允許本機讀取 / <code>AIHUB_PUBLIC_API_LOCAL_ONLY</code></label>
            <select name="AIHUB_PUBLIC_API_LOCAL_ONLY">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_LOCAL_ONLY'] === '1' ? ' selected' : '' ?>>是</option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_LOCAL_ONLY'] === '0' ? ' selected' : '' ?>>否</option>
            </select>
            <p class="form-help">啟用時僅允許 <code>127.0.0.1</code>、<code>::1</code> 或 localhost request 讀取公開文件與 manifest。</p>
        </div>
        <p><button class="primary" type="submit">儲存 API 與安全</button></p>
    </form>
</section>
<?php elseif ($activeTab === 'docker'): ?>
<section class="panel">
    <h2>Docker 與背景工作</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="docker">
        <input type="hidden" name="tab" value="docker">
        <label>Docker local port start / <code>AIHUB_DOCKER_PORT_START</code></label>
        <input name="AIHUB_DOCKER_PORT_START" value="<?= hub_h($settings['AIHUB_DOCKER_PORT_START']) ?>" required>
        <label>Docker local port end / <code>AIHUB_DOCKER_PORT_END</code></label>
        <input name="AIHUB_DOCKER_PORT_END" value="<?= hub_h($settings['AIHUB_DOCKER_PORT_END']) ?>" required>
        <label>Start 時 image 不存在自動 Build / <code>AIHUB_AUTO_BUILD_MISSING_IMAGE</code></label>
        <select name="AIHUB_AUTO_BUILD_MISSING_IMAGE">
            <option value="1"<?= $settings['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $settings['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <p class="form-help">背景工作仍由 CLI command worker 執行；Web UI 只排隊。</p>
        <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/command_worker.php') ?> --limit=5</pre>
        <p><button class="primary" type="submit">儲存 Docker 設定</button></p>
    </form>
</section>
<?php elseif ($activeTab === 'maintenance'): ?>
<section class="panel">
    <h2>維護與保留</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="maintenance">
        <input type="hidden" name="tab" value="maintenance">
        <?php foreach ([
            'AIHUB_DB_MAX_SIZE_MB' => 'SQLite DB 最大 MB',
            'AIHUB_LOG_RETENTION_DAYS' => 'Log 保留天數',
            'AIHUB_METRIC_RETENTION_DAYS' => 'Metrics 保留天數',
            'AIHUB_TASK_RETENTION_DAYS' => 'Task 保留天數',
            'AIHUB_MAX_TASK_LOG_ROWS' => 'Task log DB rows 上限',
            'AIHUB_MAX_RESULT_JSON_BYTES' => 'result_json 最大 bytes',
        ] as $key => $label): ?>
            <label><?= hub_h($label) ?> / <code><?= hub_h($key) ?></code></label>
            <input name="<?= hub_h($key) ?>" value="<?= hub_h($settings[$key]) ?>" required>
        <?php endforeach; ?>
        <p class="form-help">大量 log / result 仍應放在 data/ 檔案，SQLite 只存 metadata。</p>
        <p><button class="primary" type="submit">儲存維護設定</button></p>
    </form>
</section>
<?php else: ?>
<section class="panel">
    <h2>帳號密碼</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="password">
        <input type="hidden" name="tab" value="account">
        <label>目前密碼</label>
        <input name="current_password" type="password" autocomplete="current-password" required>
        <label>新密碼</label>
        <input name="new_password" type="password" autocomplete="new-password" required>
        <label>確認新密碼</label>
        <input name="confirm_password" type="password" autocomplete="new-password" required>
        <p><button class="primary" type="submit">更新密碼</button></p>
    </form>
</section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
