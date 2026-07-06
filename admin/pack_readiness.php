<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$packId = trim((string)($_GET['pack_id'] ?? ''));
$readiness = null;
$error = '';

try {
    if ($packId === '') {
        throw new RuntimeException('pack_id is required.');
    }
    $readiness = hub_pack_l5_readiness($db, $packId);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

hub_admin_header('Pack Readiness', $user);
?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<?php if ($readiness): ?>
    <?php $manifest = $readiness['pack']['manifest']; ?>
    <section class="panel">
        <h1><?= hub_h((string)($manifest['name'] ?? $packId)) ?></h1>
        <p class="muted">L5 readiness: <?= (int)$readiness['pass_count'] ?> / <?= (int)$readiness['total_count'] ?></p>
        <table>
            <tr><th>Pack</th><td><code><?= hub_h($packId) ?></code></td></tr>
            <tr><th>Runtime Level</th><td><code><?= hub_h($readiness['runtime_level']) ?></code></td></tr>
            <tr><th>Target Level</th><td><code><?= hub_h($readiness['target_level']) ?></code></td></tr>
        </table>
    </section>
    <section class="panel">
        <h2>L5 Checklist</h2>
        <table>
            <tr><th>Check</th><th>Status</th></tr>
            <?php foreach ($readiness['checks'] as $key => $ok): ?>
                <tr>
                    <td><code><?= hub_h((string)$key) ?></code></td>
                    <td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'PASS' : 'PENDING' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
