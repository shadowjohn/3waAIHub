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
    exit('找不到 Token');
}
$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            hub_add_api_token_ip_rule($db, (int)$token['id'], (string)($_POST['ip_rule'] ?? ''), (string)($_POST['label'] ?? ''));
            $message = 'Token IP 規則已新增。';
        } elseif ($action === 'enable' || $action === 'disable') {
            hub_set_api_token_ip_rule_enabled($db, (int)($_POST['rule_id'] ?? 0), (int)$token['id'], $action === 'enable');
            $message = 'Token IP 規則已更新。';
        } elseif ($action === 'delete') {
            hub_delete_api_token_ip_rule($db, (int)($_POST['rule_id'] ?? 0), (int)$token['id']);
            $message = 'Token IP 規則已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$rules = hub_list_api_token_ip_rules($db, (int)$token['id']);

hub_admin_header('Token IP 白名單', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>Token IP 白名單</h1>
    <p><?= hub_h($token['member_name']) ?> / <?= hub_h($token['token_name']) ?> / <code><?= hub_h(hub_mask_api_token($token)) ?></code></p>
    <p class="muted">未設定任何 Token IP 規則時允許任何來源 IP；有設定時必須符合其中一條。</p>
    <p class="muted">
        支援單一 IP 與 CIDR：<code>192.168.1.10</code>、<code>192.168.0.0/16</code>、<code>0.0.0.0/0</code>、<code>::/0</code>。
        不支援萬用字元：<code>192.168.*.*</code> 請改用 <code>192.168.0.0/16</code>；全部開放最簡單是不要設定任何規則。
    </p>
</section>
<section class="panel">
    <h2>新增 IP / CIDR</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
        <input type="hidden" name="action" value="add">
        <label>IP / CIDR</label>
        <input name="ip_rule" required placeholder="203.0.113.10 或 192.168.0.0/16 或 0.0.0.0/0">
        <label>標籤</label>
        <input name="label">
        <p><button class="primary" type="submit">新增</button> <a class="button" href="api_tokens.php?member_id=<?= (int)$token['member_id'] ?>">返回 Token 列表</a></p>
    </form>
</section>
<section class="panel">
    <h2>規則列表</h2>
    <table>
        <tr><th>ID</th><th>規則</th><th>類型</th><th>標籤</th><th>啟用</th><th>操作</th></tr>
        <?php foreach ($rules as $rule): ?>
            <tr>
                <td>#<?= (int)$rule['id'] ?></td>
                <td><code><?= hub_h($rule['ip_rule']) ?></code></td>
                <td><?= hub_h($rule['rule_type']) ?></td>
                <td><?= hub_h($rule['label']) ?></td>
                <td class="<?= (int)$rule['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$rule['enabled'] === 1 ? '是' : '否' ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                        <button name="action" value="<?= (int)$rule['enabled'] === 1 ? 'disable' : 'enable' ?>" type="submit"><?= (int)$rule['enabled'] === 1 ? '停用' : '啟用' ?></button>
                        <button class="danger" name="action" value="delete" type="submit">刪除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
