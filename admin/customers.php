<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        if ((string)($_POST['action'] ?? '') === 'delete') {
            $error = hub_delete_customer_user($db, (int)($_POST['user_id'] ?? 0)) ?? '';
            if ($error === '') {
                $message = '客戶帳號及其 API 憑證已刪除。';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$users = hub_list_users($db);

hub_admin_header('客戶管理', $user);
?>
<section class="panel">
    <div class="hub-section-title">
        <div>
            <h1>客戶管理</h1>
            <p class="muted">系統管理員建立 customer 帳號，並指派可用 API mode。預設 admin 為 protected system_admin，不提供刪除。</p>
        </div>
        <a class="button" href="customer_edit.php?action=create">建立客戶</a>
    </div>
</section>

<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>

<section class="panel">
    <table>
        <thead>
        <tr>
            <th>帳號</th>
            <th>顯示名稱</th>
            <th>Email</th>
            <th>公司 / 單位</th>
            <th>角色</th>
            <th>狀態</th>
            <th>API 會員</th>
            <th>Token</th>
            <th>最後登入</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $row): ?>
            <tr>
                <td><code><?= hub_h((string)$row['username']) ?></code><?= (int)$row['is_protected'] === 1 ? ' <span class="hub-badge hub-badge-ok">protected</span>' : '' ?></td>
                <td><?= hub_h((string)($row['display_name'] ?? '')) ?></td>
                <td><?= hub_h((string)($row['email'] ?? '')) ?></td>
                <td><?= hub_h((string)($row['company'] ?? '')) ?></td>
                <td><code><?= hub_h((string)$row['role']) ?></code></td>
                <td><?= (int)$row['is_enabled'] === 1 ? '<span class="ok">啟用</span>' : '<span class="bad">停用</span>' ?></td>
                <td><?= $row['api_member_id'] ? hub_h((string)$row['api_member_name']) : '<span class="muted">-</span>' ?></td>
                <td><?= (int)($row['token_count'] ?? 0) ?></td>
                <td><?= hub_h((string)($row['last_login_at'] ?? '-')) ?></td>
                <td class="actions">
                    <a class="button" href="customer_edit.php?id=<?= (int)$row['id'] ?>">編輯</a>
                    <?php if ((string)$row['role'] === 'customer'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$row['id'] ?>">
                            <button class="danger" name="action" value="delete" type="submit" onclick="return confirm('確定要刪除此客戶及其 API 憑證？');">刪除</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php hub_admin_footer(); ?>
