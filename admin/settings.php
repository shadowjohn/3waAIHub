<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_settings_tab(string $rawTab): string
{
    $tabs = ['basic', 'appearance', 'i18n', 'storage', 'api', 'docker', 'maintenance', 'account'];
    $activeTab = strtolower(trim($rawTab));
    if (!in_array($activeTab, $tabs, true)) {
        $activeTab = 'basic';
    }

    return $activeTab;
}

function hub_settings_tab_label(string $tab): string
{
    return [
        'basic' => __('基本設定'),
        'appearance' => __('介面顯示'),
        'i18n' => __('多國語系'),
        'storage' => __('儲存與模型'),
        'api' => __('API 與安全'),
        'docker' => __('Docker 與背景工作'),
        'maintenance' => __('維護與保留'),
        'account' => __('帳號密碼'),
    ][$tab] ?? __('基本設定');
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
        'i18n' => 'settings.php?tab=i18n',
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

function hub_settings_t(string $value): string
{
    return hub_h(__($value));
}

function hub_settings_path_status(string $path): string
{
    $usage = hub_get_disk_usage_for_path($path);
    if (!$usage['exists']) {
        return __('目錄不存在，請用 CLI 建立並設定權限。');
    }

    $free = is_numeric($usage['free_bytes']) ? hub_settings_format_bytes((float)$usage['free_bytes']) : __('未知');
    return __('存在 / 可讀：') . ($usage['readable'] ? __('是') : __('否')) . __(' / 可寫：') . ($usage['writable'] ? __('是') : __('否')) . __(' / 可用：') . $free;
}

function hub_settings_validate_unsigned_ints(array $input, array $keys): array
{
    $errors = [];
    foreach ($keys as $key) {
        $value = trim((string)($input[$key] ?? ''));
        if ($value === '' || !ctype_digit($value)) {
            $errors[] = $key . __(' 必須是 0 或正整數。');
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
            $error = __('AIHUB_SITE_TITLE 不可空白。');
        } else {
            hub_set_storage_setting($db, 'AIHUB_SITE_TITLE', substr($title, 0, 80));
            hub_set_storage_setting($db, 'AIHUB_SITE_SUBTITLE', substr($subtitle, 0, 120));
            $message = __('介面顯示設定已更新。');
        }
    } elseif ($formType === 'i18n') {
        $activeTab = 'i18n';
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'delete') {
                $stmt = $db->prepare('DELETE FROM i18n WHERE id = :id');
                $stmt->execute([':id' => (int)($_POST['id'] ?? 0)]);
                $message = __('翻譯已刪除。');
            } else {
                $title = trim((string)($_POST['title'] ?? ''));
                $lang = hub_i18n_normalize_lang((string)($_POST['lang'] ?? ''));
                $trans = trim((string)($_POST['trans'] ?? ''));
                $id = (int)($_POST['id'] ?? 0);
                if ($title === '' || $lang === 'zh_TW' || $trans === '') {
                    throw new RuntimeException(__('請填寫標題、非正體中文語系與翻譯內容。'));
                }
                if ($id > 0) {
                    $stmt = $db->prepare('UPDATE i18n SET title = :title, lang = :lang, trans = :trans WHERE id = :id');
                    $stmt->execute([':title' => $title, ':lang' => $lang, ':trans' => $trans, ':id' => $id]);
                    $message = __('翻譯已更新。');
                } else {
                    $stmt = $db->prepare('INSERT INTO i18n (title, lang, trans) VALUES (:title, :lang, :trans)');
                    $stmt->execute([':title' => $title, ':lang' => $lang, ':trans' => $trans]);
                    $message = __('翻譯已新增。');
                }
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
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
            $message = __('儲存與模型設定已更新。');
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
            $message = __('API 與安全設定已更新。');
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
            $message = __('Docker 與背景工作設定已更新。');
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
            $message = __('維護與保留設定已更新。');
        }
    } else {
        $activeTab = 'account';
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($newPassword !== $confirmPassword) {
            $error = __('兩次新密碼不一致。');
        } else {
            $error = hub_update_password($db, (int)$user['id'], (string)($_POST['current_password'] ?? ''), $newPassword) ?? '';
            if ($error === '') {
                $message = __('密碼已更新。');
                $user = hub_require_system_admin($db);
            }
        }
    }
    $settings = hub_get_storage_paths($db);
}

$i18nEdit = null;
$i18nRows = [];
$i18nQ = trim((string)($_GET['q'] ?? ''));
$i18nLang = hub_i18n_normalize_lang((string)($_GET['lang'] ?? ''));
if ($activeTab === 'i18n') {
    $editId = (int)($_GET['edit_id'] ?? 0);
    if ($editId > 0) {
        $stmt = $db->prepare('SELECT * FROM i18n WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $i18nEdit = $stmt->fetch() ?: null;
    }

    $where = [];
    $params = [];
    if ($i18nQ !== '') {
        $where[] = '(title LIKE :q OR trans LIKE :q)';
        $params[':q'] = '%' . $i18nQ . '%';
    }
    if ($i18nLang !== 'zh_TW') {
        $where[] = 'lang = :lang';
        $params[':lang'] = $i18nLang;
    }
    $sql = 'SELECT * FROM i18n' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 200';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $i18nRows = $stmt->fetchAll();
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
    <h1><?= hub_h(__('系統設定')) ?></h1>
    <div class="settings-tabs" aria-label="設定分頁">
        <?= hub_settings_tab_link($activeTab, 'basic') ?>
        <?= hub_settings_tab_link($activeTab, 'appearance') ?>
        <?= hub_settings_tab_link($activeTab, 'i18n') ?>
        <?= hub_settings_tab_link($activeTab, 'storage') ?>
        <?= hub_settings_tab_link($activeTab, 'api') ?>
        <?= hub_settings_tab_link($activeTab, 'docker') ?>
        <?= hub_settings_tab_link($activeTab, 'maintenance') ?>
        <?= hub_settings_tab_link($activeTab, 'account') ?>
    </div>
</section>

<?php if ($activeTab === 'basic'): ?>
<section class="panel">
    <h2><?= hub_h(__('基本設定')) ?></h2>
    <table>
        <tr><th><?= hub_settings_t('站台標題') ?></th><td><?= hub_h(hub_site_title($db)) ?></td></tr>
        <tr><th><?= hub_settings_t('站台副標') ?></th><td><?= hub_h(hub_site_subtitle($db)) ?></td></tr>
        <tr><th><?= hub_settings_t('版本') ?></th><td><code><?= hub_h(HUB_VERSION) ?></code> / <?= hub_h(HUB_RELEASE_LABEL) ?></td></tr>
        <tr><th><?= hub_settings_t('時區') ?></th><td><code><?= hub_h(date_default_timezone_get()) ?></code></td></tr>
    </table>
    <p><a class="button" href="settings.php?tab=appearance"><?= hub_settings_t('調整介面顯示') ?></a></p>
</section>
<?php elseif ($activeTab === 'appearance'): ?>
<section class="panel">
    <h2><?= hub_h(__('介面顯示')) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="appearance">
        <input type="hidden" name="tab" value="appearance">
        <label>AIHUB_SITE_TITLE</label>
        <input name="AIHUB_SITE_TITLE" value="<?= hub_h($settings['AIHUB_SITE_TITLE']) ?>" required>
        <p class="form-help"><?= hub_settings_t('顯示於上方導覽列、登入頁、控制台與 HTML title。') ?></p>
        <label>AIHUB_SITE_SUBTITLE</label>
        <input name="AIHUB_SITE_SUBTITLE" value="<?= hub_h($settings['AIHUB_SITE_SUBTITLE']) ?>">
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存介面顯示') ?></button></p>
    </form>
</section>
<?php elseif ($activeTab === 'i18n'): ?>
<section class="panel">
    <h2><?= hub_h(__('多國語系')) ?></h2>
    <p class="muted"><?= hub_settings_t('前後台使用') ?> <code>USER_LANG</code> cookie <?= hub_settings_t('選擇語系；程式可用') ?> <code>__('原字串')</code> <?= hub_settings_t('讀取翻譯。正體中文會直接回原字串，不查表。') ?></p>
</section>

<section class="panel">
    <h2><?= $i18nEdit ? hub_settings_t('編輯翻譯') : hub_settings_t('新增翻譯') ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="i18n">
        <input type="hidden" name="tab" value="i18n">
        <input type="hidden" name="action" value="save">
        <?php if ($i18nEdit): ?><input type="hidden" name="id" value="<?= (int)$i18nEdit['id'] ?>"><?php endif; ?>
        <label><?= hub_settings_t('原字串') ?> title</label>
        <input name="title" value="<?= hub_h((string)($i18nEdit['title'] ?? '')) ?>" required>
        <label><?= hub_settings_t('語系') ?> lang</label>
        <select name="lang" required>
            <?php foreach (hub_i18n_languages() as $code => $label): ?>
                <?php if ($code === 'zh_TW') continue; ?>
                <option value="<?= hub_h($code) ?>"<?= (string)($i18nEdit['lang'] ?? '') === $code ? ' selected' : '' ?>><?= hub_h($label) ?> / <?= hub_h($code) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= hub_settings_t('翻譯') ?> trans</label>
        <textarea name="trans" rows="4" required><?= hub_h((string)($i18nEdit['trans'] ?? '')) ?></textarea>
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存翻譯') ?></button> <?php if ($i18nEdit): ?><a class="button" href="settings.php?tab=i18n"><?= hub_settings_t('取消編輯') ?></a><?php endif; ?></p>
    </form>
</section>

<section class="panel">
    <h2><?= hub_settings_t('翻譯查詢') ?></h2>
    <form method="get">
        <input type="hidden" name="tab" value="i18n">
        <label><?= hub_settings_t('關鍵字') ?></label>
        <input name="q" value="<?= hub_h($i18nQ) ?>">
        <label><?= hub_settings_t('語系') ?></label>
        <select name="lang">
            <option value="zh_TW"><?= hub_settings_t('全部') ?></option>
            <?php foreach (hub_i18n_languages() as $code => $label): ?>
                <?php if ($code === 'zh_TW') continue; ?>
                <option value="<?= hub_h($code) ?>"<?= $i18nLang === $code ? ' selected' : '' ?>><?= hub_h($label) ?> / <?= hub_h($code) ?></option>
            <?php endforeach; ?>
        </select>
        <p><button class="primary" type="submit"><?= hub_settings_t('查詢') ?></button> <a class="button" href="settings.php?tab=i18n"><?= hub_settings_t('清除') ?></a></p>
    </form>
    <table>
        <tr><th>ID</th><th><?= hub_settings_t('語系') ?></th><th><?= hub_settings_t('原字串') ?></th><th><?= hub_settings_t('翻譯') ?></th><th><?= hub_settings_t('操作') ?></th></tr>
        <?php foreach ($i18nRows as $row): ?>
            <tr>
                <td><?= (int)$row['id'] ?></td>
                <td><code><?= hub_h((string)$row['lang']) ?></code></td>
                <td><?= hub_h((string)$row['title']) ?></td>
                <td><?= hub_h((string)$row['trans']) ?></td>
                <td>
                    <a class="button" href="settings.php?tab=i18n&edit_id=<?= (int)$row['id'] ?>"><?= hub_settings_t('編輯') ?></a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="form_type" value="i18n">
                        <input type="hidden" name="tab" value="i18n">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button class="danger" type="submit"><?= hub_settings_t('刪除') ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php elseif ($activeTab === 'storage'): ?>
<section class="panel">
    <h2><?= hub_h(__('儲存與模型')) ?></h2>
    <?php if (hub_platform_id() === 'windows'): ?><p class="form-help">以下為 3waAIHub Core（Control Plane）Windows 路徑；WSL Runtime（Preview）的 Linux data root 由 runtime profile 獨立管理。</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="storage">
        <input type="hidden" name="tab" value="storage">
        <?php foreach (['AIHUB_MODELS_DIR' => '模型目錄', 'AIHUB_CACHE_DIR' => '快取目錄', 'AIHUB_UPLOADS_DIR' => '上傳目錄', 'AIHUB_RESULTS_DIR' => '結果目錄', 'AIHUB_LOGS_DIR' => '記錄目錄'] as $key => $label): ?>
            <label><?= hub_h(__($label)) ?> / <code><?= hub_h($key) ?></code></label>
            <input name="<?= hub_h($key) ?>" value="<?= hub_h($settings[$key]) ?>" required>
            <p class="form-help"><?= hub_h(hub_settings_path_status($settings[$key])) ?></p>
        <?php endforeach; ?>
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存設定') ?></button></p>
    </form>
</section>
<?php elseif ($activeTab === 'api'): ?>
<section class="panel">
    <h2><?= hub_h(__('API 與安全')) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="api">
        <input type="hidden" name="tab" value="api">
        <label><?= hub_settings_t('外部 API 必須使用 Bearer Token') ?> / <code>AIHUB_REQUIRE_API_TOKEN</code></label>
        <select name="AIHUB_REQUIRE_API_TOKEN">
            <option value="1"<?= $settings['AIHUB_REQUIRE_API_TOKEN'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('是') ?></option>
            <option value="0"<?= $settings['AIHUB_REQUIRE_API_TOKEN'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('否') ?></option>
        </select>
        <label>localhost <?= hub_settings_t('允許略過 Token') ?> / <code>AIHUB_LOCALHOST_BYPASS_TOKEN</code></label>
        <select name="AIHUB_LOCALHOST_BYPASS_TOKEN">
            <option value="1"<?= $settings['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('是') ?></option>
            <option value="0"<?= $settings['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('否') ?></option>
        </select>
        <label><?= hub_settings_t('Token 驗證後仍套用舊版服務 IP 白名單') ?> / <code>AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST</code></label>
        <select name="AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST">
            <option value="1"<?= $settings['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('是') ?></option>
            <option value="0"<?= $settings['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('否') ?></option>
        </select>
        <label><?= hub_settings_t('Token 預設有效天數') ?> / <code>AIHUB_TOKEN_DEFAULT_VALID_DAYS</code></label>
        <input name="AIHUB_TOKEN_DEFAULT_VALID_DAYS" value="<?= hub_h($settings['AIHUB_TOKEN_DEFAULT_VALID_DAYS']) ?>" required>
            <p class="form-help"><?= hub_settings_t('0 代表建立 token 時不自動設定') ?> <code>valid_until</code>。</p>
        <div class="setting-card">
            <h3><?= hub_settings_t('未登入介接文件') ?></h3>
            <p class="form-help"><?= hub_settings_t('公開 API 文件只包含介接 contract，不包含 token、管理連結、內部路徑或 runtime secrets。API 實際呼叫仍需 Bearer Token。') ?></p>
            <label><?= hub_settings_t('未登入 API 文件') ?> / <code>AIHUB_PUBLIC_API_DOCS</code></label>
            <select name="AIHUB_PUBLIC_API_DOCS">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_DOCS'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('啟用') ?></option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_DOCS'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('停用') ?></option>
            </select>
            <p class="form-help"><?= hub_settings_t('控制根目錄') ?> <code>public_api_docs.php</code> <?= hub_settings_t('是否允許未登入讀取。') ?></p>
            <label><?= hub_settings_t('未登入 Agent Manifest') ?> / <code>AIHUB_PUBLIC_API_MANIFEST</code></label>
            <select name="AIHUB_PUBLIC_API_MANIFEST">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_MANIFEST'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('啟用') ?></option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_MANIFEST'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('停用') ?></option>
            </select>
            <p class="form-help"><?= hub_settings_t('控制根目錄') ?> <code>api_manifest.json.php</code> <?= hub_settings_t('是否允許 AI agent 讀取機器可讀的 contract。') ?></p>
            <label><?= hub_settings_t('僅允許本機讀取') ?> / <code>AIHUB_PUBLIC_API_LOCAL_ONLY</code></label>
            <select name="AIHUB_PUBLIC_API_LOCAL_ONLY">
                <option value="1"<?= $settings['AIHUB_PUBLIC_API_LOCAL_ONLY'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('是') ?></option>
                <option value="0"<?= $settings['AIHUB_PUBLIC_API_LOCAL_ONLY'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('否') ?></option>
            </select>
            <p class="form-help"><?= hub_settings_t('啟用時僅允許') ?> <code>127.0.0.1</code>、<code>::1</code> <?= hub_settings_t('或 localhost request 讀取公開文件與 manifest。') ?></p>
        </div>
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存 API 與安全') ?></button></p>
    </form>
</section>
<?php elseif ($activeTab === 'docker'): ?>
<section class="panel">
    <h2><?= hub_h(__('Docker 與背景工作')) ?></h2>
    <?php if (hub_platform_id() === 'windows'): ?><p class="form-help"><span class="muted">N/A（不適用）</span>：3waAIHub Core（Control Plane）不直接執行 linux-docker；WSL Runtime（Preview）readiness 另行檢查。</p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="docker">
        <input type="hidden" name="tab" value="docker">
        <label>Docker <?= hub_settings_t('本機 port 起始值') ?> / <code>AIHUB_DOCKER_PORT_START</code></label>
        <input name="AIHUB_DOCKER_PORT_START" value="<?= hub_h($settings['AIHUB_DOCKER_PORT_START']) ?>" required>
        <label>Docker <?= hub_settings_t('本機 port 結束值') ?> / <code>AIHUB_DOCKER_PORT_END</code></label>
        <input name="AIHUB_DOCKER_PORT_END" value="<?= hub_h($settings['AIHUB_DOCKER_PORT_END']) ?>" required>
        <label><?= hub_settings_t('啟動時 image 不存在則自動 Build') ?> / <code>AIHUB_AUTO_BUILD_MISSING_IMAGE</code></label>
        <select name="AIHUB_AUTO_BUILD_MISSING_IMAGE">
            <option value="1"<?= $settings['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '1' ? ' selected' : '' ?>><?= hub_settings_t('是') ?></option>
            <option value="0"<?= $settings['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '0' ? ' selected' : '' ?>><?= hub_settings_t('否') ?></option>
        </select>
        <p class="form-help"><?= hub_settings_t('背景工作仍由 CLI command worker 執行；Web UI 只排隊。') ?></p>
        <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/command_worker.php') ?> --limit=5</pre>
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存 Docker 設定') ?></button></p>
    </form>
</section>
<?php elseif ($activeTab === 'maintenance'): ?>
<section class="panel">
    <h2><?= hub_h(__('維護與保留')) ?></h2>
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
            <label><?= hub_h(__($label)) ?> / <code><?= hub_h($key) ?></code></label>
            <input name="<?= hub_h($key) ?>" value="<?= hub_h($settings[$key]) ?>" required>
        <?php endforeach; ?>
        <p class="form-help"><?= hub_settings_t('大量 log / result 仍應放在 data/ 檔案，SQLite 只存 metadata。') ?></p>
        <p><button class="primary" type="submit"><?= hub_settings_t('儲存維護設定') ?></button></p>
    </form>
</section>
<?php else: ?>
<section class="panel">
    <h2><?= hub_h(__('帳號密碼')) ?></h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="password">
        <input type="hidden" name="tab" value="account">
        <label><?= hub_settings_t('目前密碼') ?></label>
        <input name="current_password" type="password" autocomplete="current-password" required>
        <label><?= hub_settings_t('新密碼') ?></label>
        <input name="new_password" type="password" autocomplete="new-password" required>
        <label><?= hub_settings_t('確認新密碼') ?></label>
        <input name="confirm_password" type="password" autocomplete="new-password" required>
        <p><button class="primary" type="submit"><?= hub_settings_t('更新密碼') ?></button></p>
    </form>
</section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
