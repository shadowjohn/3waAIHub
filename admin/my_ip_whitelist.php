<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$message = '';
$error = '';
$tokens = hub_list_customer_tokens($db, (int)$user['id']);
$selectedTokenId = (int)($_POST['token_id'] ?? $_GET['token_id'] ?? ($tokens[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    try {
        if (!hub_customer_owns_token($db, (int)$user['id'], $selectedTokenId)) {
            throw new RuntimeException('不可操作別人的 Token。');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add') {
            hub_add_api_token_ip_rule($db, $selectedTokenId, (string)($_POST['ip_rule'] ?? ''), (string)($_POST['label'] ?? ''));
            $message = 'IP 白名單已新增。';
        } elseif ($action === 'delete') {
            hub_delete_api_token_ip_rule($db, (int)($_POST['rule_id'] ?? 0), $selectedTokenId);
            $message = 'IP 白名單已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$selectedToken = $selectedTokenId > 0 && hub_customer_owns_token($db, (int)$user['id'], $selectedTokenId)
    ? hub_get_api_token($db, $selectedTokenId)
    : null;
$rules = $selectedToken ? hub_list_api_token_ip_rules($db, $selectedTokenId) : [];

hub_admin_header('IP 白名單', $user);
?>
<section class="panel">
    <h1>IP 白名單</h1>
    <p class="muted">這裡管理的是自己的 Token IP 白名單。未設定白名單時，Token 不限制來源 IP。</p>
    <?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
</section>

<section class="panel">
    <form method="get">
        <label>選擇 Token</label>
        <select name="token_id" onchange="this.form.submit()">
            <?php foreach ($tokens as $token): ?>
                <option value="<?= (int)$token['id'] ?>" <?= (int)$token['id'] === $selectedTokenId ? 'selected' : '' ?>>
                    <?= hub_h((string)$token['token_name']) ?> / <?= hub_h((string)$token['token_prefix']) ?>...
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</section>

<?php if ($selectedToken): ?>
<section class="panel">
    <h2>新增 IP / CIDR</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="token_id" value="<?= (int)$selectedToken['id'] ?>">
        <label>IP / CIDR</label>
        <input name="ip_rule" placeholder="203.0.113.10 或 203.0.113.0/24">
        <label>備註</label>
        <input name="label">
        <p><button class="primary" type="submit">新增</button></p>
    </form>
</section>

<section class="panel">
    <h2>目前規則</h2>
    <table>
        <thead><tr><th>IP / CIDR</th><th>類型</th><th>備註</th><th>狀態</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $rule): ?>
            <tr>
                <td><code><?= hub_h((string)$rule['ip_rule']) ?></code></td>
                <td><?= hub_h((string)$rule['rule_type']) ?></td>
                <td><?= hub_h((string)($rule['label'] ?? '')) ?></td>
                <td><?= (int)$rule['enabled'] === 1 ? '<span class="ok">啟用</span>' : '<span class="bad">停用</span>' ?></td>
                <td>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="token_id" value="<?= (int)$selectedToken['id'] ?>">
                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                        <button class="danger" type="submit">刪除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php else: ?>
    <section class="panel"><div class="hub-empty-state">目前沒有可管理的 Token。</div></section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
