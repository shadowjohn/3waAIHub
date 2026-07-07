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
    try {
        $subdir = hub_model_asset_safe_path((string)($_POST['subdir'] ?? ''));
        $target = hub_models_root($db) . '/' . $subdir;
        if (is_link($target)) {
            throw new RuntimeException('Refuse to create model subdir over a symlink.');
        }
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException('Cannot create model subdir.');
        }
        $message = 'Model subdir created: ' . $subdir;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$root = hub_models_root($db);
$usage = hub_get_disk_usage_for_path($root);
$scan = hub_scan_model_assets($db, ['max_depth' => 5, 'limit' => 300]);
$commonDirs = ['paddleocr', 'yolo', 'ollama', 'sam3', 'huggingface'];

hub_admin_header('Model Registry', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>Model Registry</h1>
    <table>
        <tr><th>Models Root</th><td><code><?= hub_h($root) ?></code></td></tr>
        <tr><th>Exists</th><td><?= $usage['exists'] ? 'yes' : 'no' ?></td></tr>
        <tr><th>Readable / Writable</th><td><?= $usage['readable'] ? 'readable' : 'not readable' ?> / <?= $usage['writable'] ? 'writable' : 'not writable' ?></td></tr>
        <tr><th>Free / Total</th><td><?= hub_h(hub_model_format_bytes(is_numeric($usage['free_bytes']) ? (float)$usage['free_bytes'] : null)) ?> / <?= hub_h(hub_model_format_bytes(is_numeric($usage['total_bytes']) ? (float)$usage['total_bytes'] : null)) ?></td></tr>
    </table>
    <p>
        <a class="button" href="models.php">Refresh</a>
        <a class="button" href="settings.php">Open Storage Settings</a>
    </p>
</section>
<section class="panel">
    <h2>Create Subdir</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <label>Relative subdir under Models Root</label>
        <input name="subdir" placeholder="huggingface/my-model">
        <p><button class="primary" type="submit">Create Subdir</button></p>
    </form>
</section>
<section class="panel">
    <h2>Common Model Roots</h2>
    <table>
        <tr><th>Subdir</th><th>Status</th><th>Size</th><th>Modified</th></tr>
        <?php foreach ($commonDirs as $dir): ?>
            <?php $path = $root . '/' . $dir; ?>
            <tr>
                <td><code><?= hub_h($dir) ?></code></td>
                <td class="<?= is_dir($path) ? 'ok' : 'bad' ?>"><?= is_dir($path) ? (is_readable($path) ? 'exists' : 'unreadable') : 'missing' ?></td>
                <td><?= hub_h(hub_model_format_bytes(hub_model_asset_size($path))) ?></td>
                <td><?= is_dir($path) ? hub_h(date('Y-m-d H:i:s', filemtime($path) ?: time())) : '' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<section class="panel">
    <h2>Model Inventory</h2>
    <?php foreach ($scan['errors'] as $scanError): ?><div class="notice"><?= hub_h($scanError) ?></div><?php endforeach; ?>
    <p class="muted">Limited to depth <?= (int)$scan['max_depth'] ?> and <?= (int)$scan['limit'] ?> entries. Symlinks are listed but skipped.</p>
    <table>
        <tr><th>Path</th><th>Type</th><th>Size</th><th>Modified</th><th>Linked Services</th><th>Status</th></tr>
        <?php foreach ($scan['assets'] as $asset): ?>
            <tr>
                <td><code><?= hub_h((string)$asset['relative_path']) ?></code></td>
                <td><?= hub_h((string)$asset['type']) ?></td>
                <td><?= hub_h(hub_model_format_bytes($asset['size_bytes'])) ?></td>
                <td><?= hub_h(date('Y-m-d H:i:s', (int)$asset['mtime'])) ?></td>
                <td><?= hub_h(implode(', ', (array)($asset['linked_services'] ?? []))) ?></td>
                <td class="<?= !empty($asset['skipped']) ? 'bad' : 'ok' ?>"><?= !empty($asset['skipped']) ? 'skipped: ' . hub_h((string)($asset['skip_reason'] ?? '')) : 'ok' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
