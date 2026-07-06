<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_login($db);
$service = hub_get_service($db, (int)($_GET['service_id'] ?? $_POST['service_id'] ?? 0));
if (!$service) {
    http_response_code(404);
    exit('Service not found');
}

$message = '';
$error = '';
$schema = hub_get_pack_settings_schema((string)$service['pack_id']);
$settings = hub_ensure_service_settings($db, $service);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $values = [];
        foreach ($schema as $key => $item) {
            if (($item['type'] ?? 'text') === 'boolean') {
                $values[$key] = isset($_POST[$key]) ? '1' : '0';
            } else {
                $values[$key] = (string)($_POST[$key] ?? '');
            }
        }
        $result = hub_update_service_settings($db, (int)$service['id'], $values);
        $service = hub_get_service($db, (int)$service['id']) ?: $service;
        $settings = hub_ensure_service_settings($db, $service);
        $message = !empty($result['changed'])
            ? '設定已儲存，.env 已重新產生。' . (!empty($result['restart_required']) ? ' 此服務需要 Restart 才會套用新設定。' : '')
            : '設定未變更。';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$runtimeDir = dirname(hub_path((string)$service['compose_file']));
$envPath = $runtimeDir . '/.env';

hub_admin_header('Service Settings', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>Service Settings</h1>
    <table>
        <?php foreach ([
            'name', 'service_key', 'mode', 'pack_id', 'runtime_status', 'local_port',
            'config_dirty', 'restart_required',
        ] as $key): ?>
            <tr><th><?= hub_h($key) ?></th><td><?= hub_h((string)($service[$key] ?? '')) ?></td></tr>
        <?php endforeach; ?>
    </table>
</section>
<section class="panel">
    <h2>Runtime Settings</h2>
    <?php if ($schema === []): ?>
        <p class="muted">此 Pack 尚未宣告可調設定。</p>
    <?php else: ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
            <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
            <?php foreach ($schema as $key => $item): ?>
                <?php $row = $settings[$key] ?? ['value' => (string)($item['default'] ?? '')]; ?>
                <?= hub_service_setting_field($key, $item, (string)$row['value']) ?>
            <?php endforeach; ?>
            <p><button class="primary" type="submit">儲存設定並重新產生 .env</button> <a class="button" href="services.php">返回服務管理</a></p>
        </form>
    <?php endif; ?>
</section>
<section class="panel">
    <h2>Generated Files</h2>
    <table>
        <tr><th>.env</th><td><code><?= hub_h($envPath) ?></code></td></tr>
        <tr><th>Compose</th><td><code><?= hub_h(hub_path((string)$service['compose_file'])) ?></code></td></tr>
    </table>
</section>
<?php hub_admin_footer(); ?>
<?php
function hub_service_setting_field(string $key, array $item, string $value): string
{
    $label = (string)($item['label'] ?? $key);
    $type = (string)($item['type'] ?? 'text');
    $required = !empty($item['required']) ? ' required' : '';
    $help = trim((string)($item['help'] ?? ''));
    ob_start();
    ?>
    <label><?= hub_h($label) ?> <code><?= hub_h($key) ?></code><?= !empty($item['restart_required']) ? ' <span class="bad">Restart</span>' : '' ?></label>
    <?php if ($type === 'boolean'): ?>
        <label><input type="checkbox" name="<?= hub_h($key) ?>" value="1"<?= $value === '1' ? ' checked' : '' ?>> Enabled</label>
    <?php elseif ($type === 'select'): ?>
        <select name="<?= hub_h($key) ?>"<?= $required ?>>
            <?php foreach ((array)($item['options'] ?? []) as $option): ?>
                <option value="<?= hub_h((string)$option) ?>"<?= $value === (string)$option ? ' selected' : '' ?>><?= hub_h((string)$option) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <?php $inputType = in_array($type, ['integer', 'number'], true) ? 'number' : 'text'; ?>
        <input name="<?= hub_h($key) ?>" type="<?= hub_h($inputType) ?>" value="<?= !empty($item['secret']) ? '' : hub_h($value) ?>"<?= $required ?>>
    <?php endif; ?>
    <?php if ($help !== ''): ?><p class="muted"><?= hub_h($help) ?></p><?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
