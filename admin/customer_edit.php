<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_customer_edit_endpoint(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $gateway = is_array($pack['manifest']['gateway'] ?? null) ? $pack['manifest']['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);

    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$isCreate = (string)($_GET['action'] ?? '') === 'create';
$customerId = (int)($_GET['id'] ?? 0);
$message = '';
$error = '';
$plainToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $modes = array_values(array_filter(array_map('strval', $_POST['modes'] ?? []), static fn (string $mode): bool => $mode !== ''));
    try {
        if ((string)($_POST['form_action'] ?? '') === 'create') {
            $customerId = hub_create_customer_user($db, [
                'username' => (string)($_POST['username'] ?? ''),
                'password' => (string)($_POST['password'] ?? ''),
                'display_name' => (string)($_POST['display_name'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'company' => (string)($_POST['company'] ?? ''),
                'role' => (string)($_POST['role'] ?? 'customer'),
                'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
                'must_change_password' => !empty($_POST['must_change_password']) ? 1 : 0,
                'modes' => $modes,
            ]);
            if (!empty($_POST['create_token'])) {
                $token = hub_create_customer_token($db, $customerId, 'Initial customer token');
                $plainToken = (string)$token['plain_token'];
            }
            $isCreate = false;
            $message = '客戶帳號已建立。';
        } else {
            $customerId = (int)($_POST['id'] ?? 0);
            $error = hub_update_user_admin($db, $customerId, [
                'role' => (string)($_POST['role'] ?? 'customer'),
                'display_name' => (string)($_POST['display_name'] ?? ''),
                'email' => (string)($_POST['email'] ?? ''),
                'company' => (string)($_POST['company'] ?? ''),
                'is_enabled' => !empty($_POST['is_enabled']) ? 1 : 0,
                'new_password' => (string)($_POST['new_password'] ?? ''),
                'modes' => $modes,
            ]) ?? '';
            if ($error === '') {
                $message = '客戶帳號已更新。';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$customer = $isCreate ? null : hub_get_user($db, $customerId);
if (!$isCreate && !$customer) {
    http_response_code(404);
    exit('Not found');
}
$services = hub_list_services($db);
$allowedModes = $customer ? array_fill_keys(hub_user_allowed_modes($db, (int)$customer['id']), true) : [];

hub_admin_header($isCreate ? '建立客戶' : '編輯客戶', $user);
?>
<section class="panel">
    <h1><?= $isCreate ? '建立客戶' : '編輯客戶' ?></h1>
    <p class="muted">客戶登入帳號會連結一筆 api_members，用於 Token、IP 白名單與用量統計。</p>
    <?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="error"><?= nl2br(hub_h($error)) ?></div><?php endif; ?>
    <?php if ($plainToken !== ''): ?>
        <div class="notice">
            <strong>初始 Token 明文只顯示一次：</strong>
            <pre><?= hub_h($plainToken) ?></pre>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="form_action" value="<?= $isCreate ? 'create' : 'update' ?>">
        <?php if (!$isCreate): ?><input type="hidden" name="id" value="<?= (int)$customer['id'] ?>"><?php endif; ?>

        <?php if ($isCreate): ?>
            <label>登入帳號</label>
            <input name="username" required>
            <label>初始密碼</label>
            <input name="password" type="password" required>
            <label><input name="must_change_password" type="checkbox" value="1"> 首次登入要求變更密碼</label>
            <label><input name="create_token" type="checkbox" value="1"> 建立初始 API Token</label>
        <?php else: ?>
            <div class="hub-meta">
                <div class="hub-meta-label">username</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$customer['username']) ?></code></div>
                <div class="hub-meta-label">api_member</div>
                <div class="hub-meta-value"><?= $customer['api_member_id'] ? hub_h((string)$customer['api_member_name']) : '<span class="muted">未連結</span>' ?></div>
            </div>
            <label>重設密碼</label>
            <input name="new_password" type="password" placeholder="留空則不修改">
        <?php endif; ?>

        <label>顯示名稱</label>
        <input name="display_name" value="<?= hub_h((string)($customer['display_name'] ?? '')) ?>">
        <label>Email</label>
        <input name="email" type="email" value="<?= hub_h((string)($customer['email'] ?? '')) ?>">
        <label>公司 / 單位</label>
        <input name="company" value="<?= hub_h((string)($customer['company'] ?? '')) ?>">
        <label>角色</label>
        <select name="role">
            <?php $role = (string)($customer['role'] ?? 'customer'); ?>
            <option value="customer" <?= $role === 'customer' ? 'selected' : '' ?>>customer</option>
            <option value="system_admin" <?= $role === 'system_admin' ? 'selected' : '' ?>>system_admin</option>
        </select>
        <label><input name="is_enabled" type="checkbox" value="1" <?= (int)($customer['is_enabled'] ?? 1) === 1 ? 'checked' : '' ?>> 啟用帳號</label>

        <h2>可用服務 / mode</h2>
        <p class="muted">customer 建立 Token 時，只會取得這裡允許的 mode 權限。</p>
        <table>
            <thead><tr><th>允許</th><th>服務</th><th>mode</th><th>pack_id</th><th>endpoint</th><th>runtime_level</th></tr></thead>
            <tbody>
            <?php foreach ($services as $service): ?>
                <?php $mode = (string)$service['mode']; $pack = hub_get_pack((string)$service['pack_id']); ?>
                <tr>
                    <td><input name="modes[]" type="checkbox" value="<?= hub_h($mode) ?>" <?= isset($allowedModes[$mode]) ? 'checked' : '' ?>></td>
                    <td><?= hub_h((string)$service['name']) ?></td>
                    <td><code><?= hub_h($mode) ?></code></td>
                    <td><code><?= hub_h((string)$service['pack_id']) ?></code></td>
                    <td><code><?= hub_h(hub_customer_edit_endpoint($service)) ?></code></td>
                    <td><code><?= hub_h((string)($pack['manifest']['runtime_level'] ?? '')) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button class="primary" type="submit">儲存</button>
            <a class="button" href="customers.php">返回客戶管理</a>
        </p>
    </form>
</section>
<?php hub_admin_footer(); ?>
