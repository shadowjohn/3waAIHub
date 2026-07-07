<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$service = hub_get_service($db, (int)($_GET['service_id'] ?? $_POST['service_id'] ?? 0));
if (!$service) {
    http_response_code(404);
    exit('Service not found');
}

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            hub_add_service_ip_rule($db, (int)$service['id'], (string)($_POST['ip_rule'] ?? ''), (string)($_POST['label'] ?? ''), (int)$user['id']);
            $message = '白名單規則已新增。';
        } elseif ($action === 'enable' || $action === 'disable') {
            hub_set_service_ip_rule_enabled($db, (int)($_POST['rule_id'] ?? 0), (int)$service['id'], $action === 'enable');
            $message = '白名單規則已更新。';
        } elseif ($action === 'delete') {
            hub_delete_service_ip_rule($db, (int)($_POST['rule_id'] ?? 0), (int)$service['id']);
            $message = '白名單規則已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rules = hub_list_service_ip_rules($db, (int)$service['id']);

hub_admin_header('Service Whitelist', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>Service IP Whitelist</h1>
    <p><strong><?= hub_h($service['name']) ?></strong> / <code><?= hub_h($service['service_key'] ?? '') ?></code> / mode <code><?= hub_h($service['mode']) ?></code></p>
    <p class="muted">localhost / 127.0.0.1 / ::1 永遠允許。外部 IP 預設拒絕，需明確加入白名單。</p>
</section>
<section class="panel">
    <h2>新增規則</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
        <input type="hidden" name="action" value="add">
        <label>IP / CIDR</label>
        <input name="ip_rule" placeholder="192.168.1.10 或 192.168.1.0/24 或 ::1" required>
        <label>Label</label>
        <input name="label" placeholder="客戶、內網、測試機">
        <p><button class="primary" type="submit">新增 Whitelist</button></p>
    </form>
</section>
<section class="panel">
    <h2>目前規則</h2>
    <table>
        <tr><th>ID</th><th>規則</th><th>類型</th><th>Label</th><th>狀態</th><th>建立時間</th><th>操作</th></tr>
        <?php foreach ($rules as $rule): ?>
            <tr>
                <td>#<?= (int)$rule['id'] ?></td>
                <td><code><?= hub_h($rule['ip_rule']) ?></code></td>
                <td><?= hub_h($rule['rule_type']) ?></td>
                <td><?= hub_h($rule['label']) ?></td>
                <td class="<?= (int)$rule['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$rule['enabled'] === 1 ? '啟用' : '停用' ?></td>
                <td><?= hub_h($rule['created_at']) ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                        <input type="hidden" name="rule_id" value="<?= (int)$rule['id'] ?>">
                        <?php if ((int)$rule['enabled'] === 1): ?>
                            <button name="action" value="disable" type="submit">停用</button>
                        <?php else: ?>
                            <button class="primary" name="action" value="enable" type="submit">啟用</button>
                        <?php endif; ?>
                        <button class="danger" name="action" value="delete" type="submit">刪除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
