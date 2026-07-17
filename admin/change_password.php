<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if ($newPassword !== $confirmPassword) {
        $error = __('新密碼與確認密碼不一致。');
    } else {
        $error = hub_update_password($db, (int)$user['id'], (string)($_POST['current_password'] ?? ''), $newPassword) ?? '';
        if ($error === '') {
            $message = __('密碼已更新。');
            $user = hub_current_user($db) ?: $user;
        }
    }
}

hub_admin_header(__('變更密碼'), $user);
?>
<section class="panel">
    <h1><?= hub_h(__('變更密碼')) ?></h1>
    <?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
</section>

<section class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <label><?= hub_h(__('目前密碼')) ?></label>
        <input name="current_password" type="password" autocomplete="current-password" required>
        <label><?= hub_h(__('新密碼')) ?></label>
        <input name="new_password" type="password" autocomplete="new-password" required>
        <label><?= hub_h(__('確認新密碼')) ?></label>
        <input name="confirm_password" type="password" autocomplete="new-password" required>
        <p><button class="primary" type="submit"><?= hub_h(__('更新密碼')) ?></button></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
