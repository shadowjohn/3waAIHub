<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$member = ((int)($_GET['id'] ?? $_POST['id'] ?? 0)) > 0 ? hub_get_api_member($db, (int)($_GET['id'] ?? $_POST['id'])) : null;
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        if ($member) {
            hub_update_api_member($db, (int)$member['id'], (string)$_POST['name'], (string)($_POST['contact_name'] ?? ''), (string)($_POST['contact_email'] ?? ''), (string)($_POST['note'] ?? ''), !empty($_POST['enabled']));
            $message = '會員已更新。';
        } else {
            $id = hub_create_api_member($db, (string)$_POST['name'], (string)($_POST['contact_name'] ?? ''), (string)($_POST['contact_email'] ?? ''), (string)($_POST['note'] ?? ''));
            hub_redirect('api_tokens.php?member_id=' . $id);
        }
        $member = hub_get_api_member($db, (int)($_POST['id'] ?? 0));
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

hub_admin_header($member ? 'Edit API Member' : 'New API Member', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1><?= $member ? 'Edit API Member' : 'New API Member' ?></h1>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <?php if ($member): ?><input type="hidden" name="id" value="<?= (int)$member['id'] ?>"><?php endif; ?>
        <label>會員名稱</label>
        <input name="name" value="<?= hub_h((string)($member['name'] ?? '')) ?>" required>
        <label>聯絡人</label>
        <input name="contact_name" value="<?= hub_h((string)($member['contact_name'] ?? '')) ?>">
        <label>Email</label>
        <input name="contact_email" type="email" value="<?= hub_h((string)($member['contact_email'] ?? '')) ?>">
        <label>Note</label>
        <input name="note" value="<?= hub_h((string)($member['note'] ?? '')) ?>">
        <?php if ($member): ?>
            <label><input type="checkbox" name="enabled" value="1"<?= (int)$member['enabled'] === 1 ? ' checked' : '' ?>> Enabled</label>
        <?php endif; ?>
        <p><button class="primary" type="submit">儲存</button> <a class="button" href="api_members.php">返回</a></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
