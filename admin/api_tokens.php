<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$member = hub_get_api_member($db, (int)($_GET['member_id'] ?? $_POST['member_id'] ?? 0));
if (!$member) {
    http_response_code(404);
    exit('找不到會員');
}

$message = '';
$error = '';
$plainToken = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $validUntil = trim((string)($_POST['valid_until'] ?? ''));
            if ($validUntil === '') {
                $defaultDays = (int)hub_get_storage_setting($db, 'AIHUB_TOKEN_DEFAULT_VALID_DAYS');
                if ($defaultDays > 0) {
                    $validUntil = date('Y-m-d H:i:s', time() + ($defaultDays * 86400));
                }
            }
            $created = hub_create_api_token($db, (int)$member['id'], (string)($_POST['token_name'] ?? ''), (string)($_POST['valid_from'] ?? ''), $validUntil);
            $plainToken = (string)$created['plain_token'];
            $message = 'Token 只顯示一次，請立即複製。';
        } elseif ($action === 'enable' || $action === 'disable') {
            hub_set_api_token_enabled($db, (int)($_POST['token_id'] ?? 0), $action === 'enable');
            $message = 'Token 狀態已更新。';
        } elseif ($action === 'revoke') {
            hub_revoke_api_token($db, (int)($_POST['token_id'] ?? 0));
            $message = 'Token 已撤銷。';
        } elseif ($action === 'delete') {
            hub_delete_api_token($db, (int)($_POST['token_id'] ?? 0));
            $message = 'Token 已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tokens = hub_list_api_tokens($db, (int)$member['id']);

hub_admin_header('API Token', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?><?php if ($plainToken !== ''): ?><pre><?= hub_h($plainToken) ?></pre><?php endif; ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>API Token</h1>
    <p><strong><?= hub_h($member['name']) ?></strong> <a class="button" href="api_members.php">返回會員列表</a></p>
</section>
<section class="panel">
    <h2>建立 Token</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
        <input type="hidden" name="action" value="create">
        <label>Token 名稱</label>
        <input name="token_name" required>
        <label>有效起始時間</label>
        <input name="valid_from" type="datetime-local">
        <label>有效截止時間</label>
        <input name="valid_until" type="datetime-local">
        <p><button class="primary" type="submit">建立 Token</button></p>
    </form>
</section>
<section class="panel">
    <h2>Token 列表</h2>
    <table>
        <tr><th>ID</th><th>名稱</th><th>前綴</th><th>啟用</th><th>有效期間</th><th>最後使用</th><th>操作</th></tr>
        <?php foreach ($tokens as $token): ?>
            <?php $revoked = !empty($token['revoked_at']); ?>
            <tr>
                <td>#<?= (int)$token['id'] ?></td>
                <td><?= hub_h($token['token_name']) ?></td>
                <td><code><?= hub_h(hub_mask_api_token($token)) ?></code></td>
                <td class="<?= (int)$token['enabled'] === 1 && !$revoked ? 'ok' : 'bad' ?>"><?= $revoked ? '已撤銷' : ((int)$token['enabled'] === 1 ? '是' : '否') ?></td>
                <td><?= hub_h((string)$token['valid_from']) ?> ~ <?= hub_h((string)$token['valid_until']) ?></td>
                <td><?= hub_h((string)$token['last_used_at']) ?> <?= hub_h((string)$token['last_used_ip']) ?></td>
                <td class="actions">
                    <a class="button" href="api_token_permissions.php?token_id=<?= (int)$token['id'] ?>">Mode 權限</a>
                    <a class="button" href="api_token_whitelist.php?token_id=<?= (int)$token['id'] ?>">IP</a>
                    <a class="button" href="api_usage.php?token_id=<?= (int)$token['id'] ?>">用量</a>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="member_id" value="<?= (int)$member['id'] ?>">
                        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                        <?php if ($revoked): ?>
                            <span class="muted">不可重新啟用</span>
                        <?php else: ?>
                            <?php if ((int)$token['enabled'] === 1): ?>
                                <button name="action" value="disable" type="submit">停用</button>
                            <?php else: ?>
                                <button class="primary" name="action" value="enable" type="submit">啟用</button>
                            <?php endif; ?>
                            <button class="danger" name="action" value="revoke" type="submit">撤銷</button>
                        <?php endif; ?>
                        <button class="danger" name="action" value="delete" type="submit" onclick="return confirm('確定要永久刪除此 Token？');">刪除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
