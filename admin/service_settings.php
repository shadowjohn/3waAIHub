<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
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
        $action = (string)($_POST['action'] ?? 'save_settings');
        if ($action === 'ollama_model_pull' && (string)($service['pack_id'] ?? '') === 'translate-gemma12b') {
            $model = (string)($settings['OLLAMA_MODEL']['value'] ?? $schema['OLLAMA_MODEL']['default'] ?? '');
            $jobId = hub_enqueue_command_job(
                $db,
                'ollama_model_pull',
                (int)$service['id'],
                ['model' => $model],
                (int)$user['id'],
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            $message = '已排入 Ollama model pull 工作 #' . $jobId . '。';
        } else {
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
        }
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
                <?= hub_service_setting_field($db, $key, $item, (string)$row['value']) ?>
            <?php endforeach; ?>
            <p><button class="primary" type="submit">儲存設定並重新產生 .env</button> <a class="button" href="services.php">返回服務管理</a></p>
        </form>
    <?php endif; ?>
</section>
<?php if ((string)($service['pack_id'] ?? '') === 'translate-gemma12b'): ?>
<section class="panel">
    <h2>Ollama Model</h2>
    <p class="muted">Web UI 只排背景工作；實際 pull 由 command worker 執行。</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
        <input type="hidden" name="action" value="ollama_model_pull">
        <button type="submit">Pull model</button>
        <a class="button" href="services.php">查看工作進度</a>
    </form>
</section>
<?php endif; ?>
<section class="panel">
    <h2>Generated Files</h2>
    <table>
        <tr><th>.env</th><td><code><?= hub_h($envPath) ?></code></td></tr>
        <tr><th>Compose</th><td><code><?= hub_h(hub_path((string)$service['compose_file'])) ?></code></td></tr>
    </table>
</section>
<?php hub_admin_footer(); ?>
<?php
function hub_service_setting_field(PDO $db, string $key, array $item, string $value): string
{
    $label = (string)($item['label'] ?? $key);
    $type = (string)($item['type'] ?? 'text');
    $required = !empty($item['required']) ? ' required' : '';
    $help = trim((string)($item['help'] ?? ''));
    $selector = is_array($item['model_selector'] ?? null) ? $item['model_selector'] : null;
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
        <?php if ($selector): ?>
            <?php $listId = 'models-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $key); ?>
            <input name="<?= hub_h($key) ?>" list="<?= hub_h($listId) ?>" type="<?= hub_h($inputType) ?>" value="<?= !empty($item['secret']) ? '' : hub_h($value) ?>"<?= $required ?>>
            <datalist id="<?= hub_h($listId) ?>">
                <?php foreach (hub_model_selector_options($db, $selector) as $option): ?>
                    <option value="<?= hub_h((string)$option['value']) ?>"><?= hub_h((string)$option['label']) ?></option>
                <?php endforeach; ?>
            </datalist>
            <?php $status = hub_model_selector_status($db, $selector, $value); ?>
            <p class="muted">
                Model Root: <code><?= hub_h(hub_models_root($db)) ?></code><br>
                <?= hub_h((string)$status['label']) ?>:
                <span class="<?= $status['exists'] ? 'ok' : 'bad' ?>"><?= $status['exists'] ? 'exists' : 'missing' ?></span>
                <?php if (array_key_exists('model_present', $status)): ?>
                    <br>Model tag:
                    <span class="<?= !empty($status['model_present']) ? 'ok' : 'bad' ?>"><?= !empty($status['model_present']) ? 'present' : 'missing' ?></span>
                <?php endif; ?>
                <?= $status['size_bytes'] !== null ? ' / ' . hub_h(hub_model_format_bytes((int)$status['size_bytes'])) : '' ?>
            </p>
        <?php else: ?>
            <input name="<?= hub_h($key) ?>" type="<?= hub_h($inputType) ?>" value="<?= !empty($item['secret']) ? '' : hub_h($value) ?>"<?= $required ?>>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($help !== ''): ?><p class="muted"><?= hub_h($help) ?></p><?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
