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
        $result = hub_install_pack($db, (string)($_POST['pack_id'] ?? ''), [
            'service_key' => trim((string)($_POST['service_key'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'mode' => trim((string)($_POST['mode'] ?? '')),
            'port_mode' => (string)($_POST['port_mode'] ?? 'auto'),
            'local_port' => trim((string)($_POST['local_port'] ?? '')),
            'environment' => (string)($_POST['environment'] ?? 'production'),
            'hot_reload' => !empty($_POST['hot_reload']),
        ]);
        $message = '已安裝 Service Instance：' . $result['service']['service_key'];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$counts = [];
foreach ($db->query('SELECT pack_id, COUNT(*) AS count FROM services WHERE pack_id IS NOT NULL GROUP BY pack_id')->fetchAll() as $row) {
    $counts[(string)$row['pack_id']] = (int)$row['count'];
}
$packs = hub_list_catalog_packs();
$preflightLabels = [
    'docker' => 'Docker',
    'docker_compose' => 'Docker Compose',
    'nvidia_smi' => 'GPU',
    'docker_gpus' => 'NVIDIA Container',
    'vram' => 'VRAM',
    'compute_capability' => 'Compute Capability',
    'storage' => 'Storage',
];

hub_admin_header('HubPack 套件', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>HubPack 套件</h1>
    <p class="muted">Local HubPack Catalog，只掃描本機 packs/catalog.json，不做遠端下載。</p>
</section>
<section class="panel">
    <h2>Local HubPack Catalog</h2>
    <table>
        <tr>
            <th>Pack</th>
            <th>規格</th>
            <th>需求</th>
            <th>已安裝</th>
            <th>Install as Service</th>
        </tr>
        <?php foreach ($packs as $pack): ?>
            <?php
            $manifest = $pack['manifest'];
            $packId = (string)$pack['id'];
            $defaultKey = (string)($manifest['install']['default_service_key'] ?? ($packId . '-main'));
            $defaultMode = (string)($manifest['default_mode'] ?? '');
            $defaultPort = (string)($manifest['service']['default_local_port'] ?? '');
            $gpuRequired = !empty($manifest['hardware']['gpu_required']);
            $preflight = hub_pack_preflight($db, $manifest);
            ?>
            <tr>
                <td>
                    <strong><?= hub_h($packId) ?></strong><br>
                    <?= hub_h((string)($manifest['name'] ?? '')) ?><br>
                    <span class="muted"><?= hub_h((string)($manifest['description'] ?? '')) ?></span>
                    <?php if ($pack['errors']): ?>
                        <pre class="inline-pre"><?= hub_h(implode("\n", $pack['errors'])) ?></pre>
                    <?php endif; ?>
                </td>
                <td>
                    version: <?= hub_h((string)($manifest['version'] ?? '')) ?><br>
                    category: <?= hub_h((string)($manifest['category'] ?? '')) ?><br>
                    type: <?= hub_h((string)($manifest['type'] ?? '')) ?><br>
                    execution: <?= hub_h((string)($manifest['execution_type'] ?? '')) ?><br>
                    runtime: <code><?= hub_h((string)($manifest['runtime_level'] ?? '')) ?></code>
                    <span class="<?= !empty($manifest['runtime_ready']) ? 'ok' : 'bad' ?>"><?= !empty($manifest['runtime_ready']) ? 'ready' : 'not ready' ?></span><br>
                    default mode: <code><?= hub_h($defaultMode) ?></code>
                </td>
                <td>
                    GPU: <?= $gpuRequired ? '需要' : '不需要' ?><br>
                    queue: <?= hub_h((string)($manifest['queue']['default_queue'] ?? '')) ?>
                    <?php if ($preflight['summary']['total'] > 0): ?>
                        <p><strong>Preflight</strong>
                            <span class="<?= hub_status_class($preflight['summary']['status']) ?>">
                                <?= hub_status_label($preflight['summary']['status']) ?>
                            </span>
                        </p>
                        <?php if ($preflight['snapshot_at'] === ''): ?>
                            <p class="muted"><code>php scripts/collect_host_metrics.php --force</code></p>
                        <?php endif; ?>
                        <?php foreach ($preflight['checks'] as $check): ?>
                            <div>
                                <?= hub_h($preflightLabels[$check['key']] ?? $check['key']) ?>:
                                <span class="<?= hub_status_class($check['status']) ?>"><?= hub_status_label($check['status']) ?></span>
                                <span class="muted"><?= hub_h($check['detail']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
                <td><?= (int)($counts[$packId] ?? 0) ?></td>
                <td>
                    <?php if ($pack['status'] === 'ok'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                            <input type="hidden" name="pack_id" value="<?= hub_h($packId) ?>">
                            <label>service_key</label>
                            <input name="service_key" value="<?= hub_h($defaultKey) ?>" required>
                            <label>display name</label>
                            <input name="name" value="<?= hub_h((string)($manifest['name'] ?? '')) ?>" required>
                            <label>mode</label>
                            <input name="mode" value="<?= hub_h($defaultMode) ?>" required>
                            <label>local port mode</label>
                            <select name="port_mode">
                                <option value="auto">auto</option>
                                <option value="manual">manual</option>
                            </select>
                            <label>local port</label>
                            <input name="local_port" value="<?= hub_h($defaultPort) ?>">
                            <label>environment</label>
                            <select name="environment">
                                <option value="production">production</option>
                                <option value="development">development</option>
                            </select>
                            <label><input name="hot_reload" type="checkbox" value="1"> hot_reload</label>
                            <p><button class="primary" type="submit">Install</button></p>
                        </form>
                    <?php else: ?>
                        <span class="bad">pack 驗證失敗</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
