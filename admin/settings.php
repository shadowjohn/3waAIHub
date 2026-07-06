<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_login($db);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    if (($_POST['form_type'] ?? '') === 'storage') {
        $input = [
            'AIHUB_MODELS_DIR' => trim((string)($_POST['AIHUB_MODELS_DIR'] ?? '')),
            'AIHUB_CACHE_DIR' => trim((string)($_POST['AIHUB_CACHE_DIR'] ?? '')),
            'AIHUB_UPLOADS_DIR' => trim((string)($_POST['AIHUB_UPLOADS_DIR'] ?? '')),
            'AIHUB_RESULTS_DIR' => trim((string)($_POST['AIHUB_RESULTS_DIR'] ?? '')),
            'AIHUB_LOGS_DIR' => trim((string)($_POST['AIHUB_LOGS_DIR'] ?? '')),
            'AIHUB_DOCKER_PORT_START' => trim((string)($_POST['AIHUB_DOCKER_PORT_START'] ?? '')),
            'AIHUB_DOCKER_PORT_END' => trim((string)($_POST['AIHUB_DOCKER_PORT_END'] ?? '')),
            'AIHUB_AUTO_BUILD_MISSING_IMAGE' => trim((string)($_POST['AIHUB_AUTO_BUILD_MISSING_IMAGE'] ?? '1')),
            'AIHUB_REQUIRE_API_TOKEN' => trim((string)($_POST['AIHUB_REQUIRE_API_TOKEN'] ?? '1')),
            'AIHUB_LOCALHOST_BYPASS_TOKEN' => trim((string)($_POST['AIHUB_LOCALHOST_BYPASS_TOKEN'] ?? '1')),
            'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST' => trim((string)($_POST['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] ?? '1')),
            'AIHUB_TOKEN_DEFAULT_VALID_DAYS' => trim((string)($_POST['AIHUB_TOKEN_DEFAULT_VALID_DAYS'] ?? '0')),
        ];
        $errors = hub_validate_storage_input($input);
        if ($errors) {
            $error = implode("\n", $errors);
        } else {
            foreach ($input as $key => $value) {
                hub_set_storage_setting($db, $key, $value);
            }
            $message = 'Storage settings 已更新。';
        }
    } else {
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        if ($newPassword !== $confirmPassword) {
            $error = '兩次新密碼不一致。';
        } else {
            $error = hub_update_password($db, (int)$user['id'], (string)($_POST['current_password'] ?? ''), $newPassword) ?? '';
            if ($error === '') {
                $message = '密碼已更新。';
                $user = hub_require_login($db);
            }
        }
    }
}

$storage = hub_get_storage_paths($db);

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

hub_admin_header('設定', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>設定</h1>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <label>目前密碼</label>
        <input name="current_password" type="password" autocomplete="current-password" required>
        <label>新密碼</label>
        <input name="new_password" type="password" autocomplete="new-password" required>
        <label>確認新密碼</label>
        <input name="confirm_password" type="password" autocomplete="new-password" required>
        <p><button class="primary" type="submit">更新密碼</button></p>
    </form>
</section>
<section class="panel">
    <h2>Storage Settings</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_type" value="storage">
        <?php foreach (['AIHUB_MODELS_DIR' => 'Models Dir', 'AIHUB_CACHE_DIR' => 'Cache Dir', 'AIHUB_UPLOADS_DIR' => 'Uploads Dir', 'AIHUB_RESULTS_DIR' => 'Results Dir', 'AIHUB_LOGS_DIR' => 'Logs Dir'] as $key => $label): ?>
            <label><?= hub_h($label) ?></label>
            <input name="<?= hub_h($key) ?>" value="<?= hub_h($storage[$key]) ?>" required>
            <p class="muted"><?= hub_h(hub_settings_path_status($storage[$key])) ?></p>
        <?php endforeach; ?>
        <label>Docker local port start</label>
        <input name="AIHUB_DOCKER_PORT_START" value="<?= hub_h($storage['AIHUB_DOCKER_PORT_START']) ?>" required>
        <label>Docker local port end</label>
        <input name="AIHUB_DOCKER_PORT_END" value="<?= hub_h($storage['AIHUB_DOCKER_PORT_END']) ?>" required>
        <label>Start 時 image 不存在自動 Build</label>
        <select name="AIHUB_AUTO_BUILD_MISSING_IMAGE">
            <option value="1"<?= $storage['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $storage['AIHUB_AUTO_BUILD_MISSING_IMAGE'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <h2>API Token Policy</h2>
        <label>外部 API 必須使用 Bearer token</label>
        <select name="AIHUB_REQUIRE_API_TOKEN">
            <option value="1"<?= $storage['AIHUB_REQUIRE_API_TOKEN'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $storage['AIHUB_REQUIRE_API_TOKEN'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>localhost 允許略過 token</label>
        <select name="AIHUB_LOCALHOST_BYPASS_TOKEN">
            <option value="1"<?= $storage['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $storage['AIHUB_LOCALHOST_BYPASS_TOKEN'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>Token 驗證後仍套用舊 service IP whitelist</label>
        <select name="AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST">
            <option value="1"<?= $storage['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '1' ? ' selected' : '' ?>>是</option>
            <option value="0"<?= $storage['AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST'] === '0' ? ' selected' : '' ?>>否</option>
        </select>
        <label>Token 預設有效天數</label>
        <input name="AIHUB_TOKEN_DEFAULT_VALID_DAYS" value="<?= hub_h($storage['AIHUB_TOKEN_DEFAULT_VALID_DAYS']) ?>" required>
        <p class="muted">0 代表建立 token 時不自動設定 valid_until。</p>
        <p><button class="primary" type="submit">儲存 Storage Settings</button></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
