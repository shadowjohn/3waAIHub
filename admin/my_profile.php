<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    hub_update_current_user_profile($db, (int)$user['id'], [
        'display_name' => (string)($_POST['display_name'] ?? ''),
        'email' => (string)($_POST['email'] ?? ''),
        'company' => (string)($_POST['company'] ?? ''),
    ]);
    $message = '帳號資料已更新。';
    $user = hub_current_user($db) ?: $user;
}

hub_admin_header('帳號資料', $user);
?>
<section class="panel">
    <h1>帳號資料</h1>
    <p class="muted">可維護顯示名稱、Email 與公司 / 單位；角色與啟用狀態由系統管理員管理。</p>
    <?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
</section>

<section class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <div class="hub-meta">
            <div class="hub-meta-label">username</div><div class="hub-meta-value"><code><?= hub_h((string)$user['username']) ?></code></div>
            <div class="hub-meta-label">role</div><div class="hub-meta-value"><code><?= hub_h((string)$user['role']) ?></code></div>
        </div>
        <label>顯示名稱</label>
        <input name="display_name" value="<?= hub_h((string)($user['display_name'] ?? '')) ?>">
        <label>Email</label>
        <input name="email" type="email" value="<?= hub_h((string)($user['email'] ?? '')) ?>">
        <label>公司 / 單位</label>
        <input name="company" value="<?= hub_h((string)($user['company'] ?? '')) ?>">
        <p><button class="primary" type="submit">儲存</button></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
