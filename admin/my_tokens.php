<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$message = '';
$error = '';
$plainToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $token = hub_create_customer_token(
                $db,
                (int)$user['id'],
                (string)($_POST['token_name'] ?? 'Customer API Token'),
                trim((string)($_POST['valid_until'] ?? '')) ?: null
            );
            $plainToken = (string)$token['plain_token'];
            $message = 'Token 已建立，明文只顯示一次。';
        } elseif ($action === 'revoke') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if (!hub_customer_owns_token($db, (int)$user['id'], $tokenId)) {
                throw new RuntimeException('不可操作別人的 Token。');
            }
            hub_revoke_api_token($db, $tokenId);
            $message = 'Token 已撤銷。';
        } elseif ($action === 'disable') {
            $tokenId = (int)($_POST['token_id'] ?? 0);
            if (!hub_customer_owns_token($db, (int)$user['id'], $tokenId)) {
                throw new RuntimeException('不可操作別人的 Token。');
            }
            hub_set_api_token_enabled($db, $tokenId, false);
            $message = 'Token 已停用。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$tokens = hub_list_customer_tokens($db, (int)$user['id']);

hub_admin_header('我的 Token', $user);
?>
<section class="panel">
    <h1>我的 Token</h1>
    <p class="muted">Token 明文只會在建立當下顯示一次。新 Token 會自動套用管理員授權給你的 mode。</p>
    <?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
    <?php if ($plainToken !== ''): ?>
        <div class="notice">
            <strong>新 Token：</strong>
            <pre><?= hub_h($plainToken) ?></pre>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>建立 Token</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="action" value="create">
        <label>Token 名稱</label>
        <input name="token_name" value="Customer API Token">
        <label>有效期限</label>
        <input name="valid_until" placeholder="YYYY-MM-DD HH:MM:SS，留空表示不設定">
        <p><button class="primary" type="submit">建立 Token</button></p>
    </form>
</section>

<section class="panel">
    <h2>Token 列表</h2>
    <table>
        <thead><tr><th>名稱</th><th>prefix</th><th>狀態</th><th>建立時間</th><th>有效期限</th><th>最後使用</th><th>mode 權限</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($tokens as $token): ?>
            <?php $permissions = hub_list_api_token_permissions($db, (int)$token['id']); ?>
            <tr>
                <td><?= hub_h((string)$token['token_name']) ?></td>
                <td><code><?= hub_h((string)$token['token_prefix']) ?>...</code></td>
                <td><?= (int)$token['enabled'] === 1 && empty($token['revoked_at']) ? '<span class="ok">啟用</span>' : '<span class="bad">停用 / 撤銷</span>' ?></td>
                <td><?= hub_h((string)$token['created_at']) ?></td>
                <td><?= hub_h((string)($token['valid_until'] ?? '-')) ?></td>
                <td><?= hub_h((string)($token['last_used_at'] ?? '-')) ?></td>
                <td><?= hub_h(implode(', ', array_map(static fn (array $row): string => (string)$row['mode'], $permissions))) ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                        <button name="action" value="disable" type="submit">停用</button>
                        <button class="danger" name="action" value="revoke" type="submit">撤銷</button>
                    </form>
                    <a class="button" href="my_ip_whitelist.php?token_id=<?= (int)$token['id'] ?>">IP 白名單</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php hub_admin_footer(); ?>
