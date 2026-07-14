<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$token = hub_get_api_token($db, (int)($_GET['token_id'] ?? $_POST['token_id'] ?? 0));
if (!$token) {
    http_response_code(404);
    exit('Token not found');
}
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    hub_set_api_token_mode_permissions($db, (int)$token['id'], is_array($_POST['modes'] ?? null) ? $_POST['modes'] : []);
    $message = 'Mode permissions 已更新。';
}
$enabledModes = array_column(hub_list_api_token_permissions($db, (int)$token['id']), 'mode');
$services = hub_list_services($db);
$taskModes = hub_task_api_modes();
$photoModes = hub_photo_modes();

hub_admin_header('Token Mode Permissions', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<section class="panel">
    <h1>Token Mode Permissions</h1>
    <p><?= hub_h($token['member_name']) ?> / <?= hub_h($token['token_name']) ?> / <code><?= hub_h(hub_mask_api_token($token)) ?></code></p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
        <?php foreach ($services as $service): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($service['mode']) ?>"<?= in_array($service['mode'], $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($service['mode']) ?></code> <?= hub_h($service['name']) ?></label>
        <?php endforeach; ?>
        <h2>System Task Modes</h2>
        <?php foreach ($taskModes as $mode => $label): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
        <?php endforeach; ?>
        <h2>Photo Vision Modes</h2>
        <?php foreach ($photoModes as $mode => $label): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
        <?php endforeach; ?>
        <p><button class="primary" type="submit">儲存</button> <a class="button" href="api_tokens.php?member_id=<?= (int)$token['member_id'] ?>">返回 Tokens</a></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
