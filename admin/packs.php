<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $result = hub_install_pack($db, (string)($_POST['pack_id'] ?? ''));
        $message = '已安裝 HubPack：' . $result['service']['name'] . ' / ' . $result['service']['service_key'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$installed = [];
foreach ($db->query("SELECT pack_id, service_key FROM services WHERE pack_id IS NOT NULL")->fetchAll() as $service) {
    $installed[(string)$service['pack_id']] = (string)$service['service_key'];
}
$packs = hub_list_packs();

hub_admin_header('HubPack', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>HubPack</h1>
    <p class="muted">從 packs/*/pack.json 掃描可安裝的本機服務包。</p>
</section>
<section class="panel">
    <h2>可用 HubPack</h2>
    <table>
        <tr>
            <th>ID</th>
            <th>名稱</th>
            <th>版本</th>
            <th>類型</th>
            <th>執行型態</th>
            <th>Runtime</th>
            <th>GPU</th>
            <th>狀態</th>
            <th>操作</th>
        </tr>
        <?php foreach ($packs as $pack): ?>
            <?php
            $manifest = $pack['manifest'];
            $packId = (string)($pack['id'] ?? '');
            $installedKey = $installed[$packId] ?? '';
            ?>
            <tr>
                <td><code><?= hub_h($packId) ?></code></td>
                <td><?= hub_h((string)($manifest['name'] ?? '')) ?></td>
                <td><?= hub_h((string)($manifest['version'] ?? '')) ?></td>
                <td><?= hub_h((string)($manifest['type'] ?? '')) ?></td>
                <td><?= hub_h((string)($manifest['execution_type'] ?? '')) ?></td>
                <td>
                    <code><?= hub_h((string)($manifest['runtime_level'] ?? '')) ?></code><br>
                    <?php if (($manifest['role'] ?? '') === 'reference'): ?>
                        <span class="ok">Reference Pack</span><br>
                    <?php endif; ?>
                    <span class="<?= !empty($manifest['runtime_ready']) ? 'ok' : 'bad' ?>"><?= !empty($manifest['runtime_ready']) ? 'ready' : 'not ready' ?></span>
                </td>
                <td><?= !empty($manifest['hardware']['gpu_required']) ? '需要' : '不需要' ?></td>
                <td class="<?= $pack['status'] === 'ok' ? 'ok' : 'bad' ?>">
                    <?= $pack['status'] === 'ok' ? ($installedKey !== '' ? '已安裝：' . hub_h($installedKey) : '可安裝') : '錯誤' ?>
                    <?php if ($pack['errors']): ?>
                        <pre class="inline-pre"><?= hub_h(implode("\n", $pack['errors'])) ?></pre>
                    <?php endif; ?>
                </td>
                <td>
                    <a class="button" href="pack_readiness.php?pack_id=<?= urlencode($packId) ?>">Readiness</a>
                    <?php if ($pack['status'] === 'ok' && $installedKey === ''): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                            <input type="hidden" name="pack_id" value="<?= hub_h($packId) ?>">
                            <button class="primary" type="submit">安裝</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">-</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
