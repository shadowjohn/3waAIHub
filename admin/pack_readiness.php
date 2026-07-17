<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$packId = trim((string)($_GET['pack_id'] ?? ''));
$readiness = null;
$error = '';

try {
    if ($packId === '') {
        throw new RuntimeException('缺少 pack_id。');
    }
    $readiness = hub_pack_l5_readiness($db, $packId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

hub_admin_header('Pack 準備狀態', $user);
?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<?php if ($readiness): ?>
    <?php $manifest = $readiness['pack']['manifest']; ?>
    <section class="panel">
        <h1><?= hub_h((string)($manifest['name'] ?? $packId)) ?></h1>
        <p class="muted">L5 準備狀態：<?= (int)$readiness['pass_count'] ?> / <?= (int)$readiness['total_count'] ?></p>
        <table>
            <tr><th>Pack</th><td><code><?= hub_h($packId) ?></code></td></tr>
            <tr><th>Runtime 層級</th><td><code><?= hub_h($readiness['runtime_level']) ?></code></td></tr>
            <tr><th>目標層級</th><td><code><?= hub_h($readiness['target_level']) ?></code></td></tr>
        </table>
    </section>
    <section class="panel">
        <h2>L5 檢查清單</h2>
        <table>
            <tr><th>檢查項目</th><th>狀態</th></tr>
            <?php foreach ($readiness['checks'] as $key => $ok): ?>
                <tr>
                    <td><code><?= hub_h((string)$key) ?></code></td>
                    <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '通過' : '待處理' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
