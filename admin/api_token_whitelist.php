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
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            hub_add_api_token_ip_rule($db, (int)$token['id'], (string)($_POST['ip_rule'] ?? ''), (string)($_POST['label'] ?? ''));
            $message = 'Token IP rule 已新增。';
        } elseif ($action === 'enable' || $action === 'disable') {
            hub_set_api_token_ip_rule_enabled($db, (int)($_POST['rule_id'] ?? 0), (int)$token['id'], $action === 'enable');
            $message = 'Token IP rule 已更新。';
        } elseif ($action === 'delete') {
            hub_delete_api_token_ip_rule($db, (int)($_POST['rule_id'] ?? 0), (int)$token['id']);
            $message = 'Token IP rule 已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$rules = hub_list_api_token_ip_rules($db, (int)$token['id']);

hub_admin_header('Token IP Whitelist', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>Token IP Whitelist</h1>
    <p><?= hub_h($token['member_name']) ?> / <?= hub_h($token['token_name']) ?> / <code><?= hub_h(hub_mask_api_token($token)) ?></code></p>
    <p class="muted">未設定 token IP rule 時允許任何來源 IP；有設定時必須符合其中一條。</p>
</section>
<section class="panel">
    <h2>新增 IP / CIDR</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
        <input type="hidden" name="action" value="add">
        <label>IP / CIDR</label>
        <input name="ip_rule" required>
        <label>Label</label>
        <input name="label">
        <p><button class="primary" type="submit">新增</button> <a class="button" href="api_tokens.php?member_id=<?= (int)$token['member_id'] ?>">返回 Tokens</a></p>
    </form>
</section>
<section class="panel">
    <h2>Rules</h2>
    <table>
        <tr><th>ID</th><th>Rule</th><th>Type</th><th>Label</th><th>Enabled</th><th>操作</th></tr>
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
