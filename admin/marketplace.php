<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_marketplace_endpoint_label(array $manifest): string
{
    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);
    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

function hub_marketplace_gpu_label(array $manifest): array
{
    $hardware = is_array($manifest['hardware'] ?? null) ? $manifest['hardware'] : [];
    if (!empty($hardware['gpu_required'])) {
        return ['label' => '需要 GPU', 'class' => 'hub-badge hub-badge-warn'];
    }
    if (!empty($hardware['gpu_supported'])) {
        return ['label' => '可用 GPU', 'class' => 'hub-badge hub-badge-ok'];
    }

    return ['label' => '不使用 GPU', 'class' => 'hub-badge hub-badge-muted'];
}

function hub_marketplace_model_label(PDO $db, array $manifest): array
{
    $schema = is_array($manifest['settings_schema'] ?? null) ? $manifest['settings_schema'] : [];
    $required = false;
    foreach ($schema as $item) {
        if (!is_array($item) || !is_array($item['model_selector'] ?? null)) {
            continue;
        }
        $required = $required || !empty($item['required']);
        if (hub_model_selector_options($db, $item['model_selector']) !== []) {
            return ['label' => '模型已就緒', 'class' => 'hub-badge hub-badge-ok'];
        }
    }

    return $required
        ? ['label' => '缺少模型', 'class' => 'hub-badge hub-badge-bad']
        : ['label' => ($schema === [] ? '無模型需求' : '模型可選'), 'class' => 'hub-badge hub-badge-muted'];
}

function hub_marketplace_runtime_label(string $runtimeLevel): string
{
    $runtime = strtolower($runtimeLevel);
    if (str_contains($runtime, 'l5')) {
        return 'L5 可驗收';
    }
    if (str_contains($runtime, 'l4b')) {
        return 'L4b 真實推論';
    }
    if (str_contains($runtime, 'l4a')) {
        return 'L4a 模型檢查';
    }
    if (str_contains($runtime, 'l3')) {
        return 'L3 儲存掛載';
    }
    if (str_contains($runtime, 'l2')) {
        return 'L2 依賴檢查';
    }

    return 'Runtime 未分級';
}

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

$installed = [];
foreach ($db->query("SELECT pack_id, COUNT(*) AS count, GROUP_CONCAT(mode, ', ') AS modes FROM services WHERE pack_id IS NOT NULL GROUP BY pack_id")->fetchAll() as $row) {
    $installed[(string)$row['pack_id']] = ['count' => (int)$row['count'], 'modes' => (string)($row['modes'] ?? '')];
}
$packs = hub_list_packs();
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
    <p class="muted">Marketplace 是安裝入口；只讀本機 HubPack，不做遠端下載。技術值如 <code>pack_id</code>、<code>mode</code>、<code>runtime_level</code>、<code>endpoint</code> 保留英文。</p>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2>Local HubPack Marketplace</h2>
        <span class="muted">共 <?= count($packs) ?> 個 Pack</span>
    </div>
    <?php if ($packs === []): ?>
        <div class="hub-empty-state">目前沒有可安裝的 HubPack。</div>
    <?php else: ?>
        <div class="hub-card-grid">
            <?php foreach ($packs as $pack): ?>
                <?php
                $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
                $packId = (string)($pack['id'] ?? $manifest['id'] ?? '');
                $defaultKey = (string)($manifest['install']['default_service_key'] ?? ($packId . '-main'));
                $defaultMode = (string)($manifest['default_mode'] ?? '');
                $defaultPort = (string)($manifest['service']['default_local_port'] ?? '');
                $runtimeLevel = (string)($manifest['runtime_level'] ?? '');
                $targetLevel = (string)($manifest['target_level'] ?? '');
                $endpoint = hub_marketplace_endpoint_label($manifest);
                $gpu = hub_marketplace_gpu_label($manifest);
                $model = hub_marketplace_model_label($db, $manifest);
                $stats = $installed[$packId] ?? ['count' => 0, 'modes' => ''];
                $preflight = hub_pack_preflight($db, $manifest);
                ?>
                <article class="hub-card">
                    <h2><?= hub_h((string)($manifest['name'] ?? $packId)) ?></h2>
                    <p class="muted">pack_id: <code><?= hub_h($packId) ?></code></p>
                    <?php if ((string)($manifest['description'] ?? '') !== ''): ?>
                        <p><?= hub_h((string)$manifest['description']) ?></p>
                    <?php endif; ?>
                    <p>
                        <span class="hub-badge <?= !empty($manifest['runtime_ready']) ? 'hub-badge-ok' : 'hub-badge-bad' ?>"><?= hub_h(hub_marketplace_runtime_label($runtimeLevel)) ?></span>
                        <span class="<?= hub_h((string)$gpu['class']) ?>"><?= hub_h((string)$gpu['label']) ?></span>
                        <span class="<?= hub_h((string)$model['class']) ?>"><?= hub_h((string)$model['label']) ?></span>
                    </p>
                    <div class="hub-meta">
                        <div class="hub-meta-label">套件名稱</div>
                        <div class="hub-meta-value"><?= hub_h((string)($manifest['name'] ?? '')) ?></div>
                        <div class="hub-meta-label">套件 ID</div>
                        <div class="hub-meta-value"><code><?= hub_h($packId) ?></code></div>
                        <div class="hub-meta-label">版本</div>
                        <div class="hub-meta-value"><code><?= hub_h((string)($manifest['version'] ?? '')) ?></code></div>
                        <div class="hub-meta-label">類型</div>
                        <div class="hub-meta-value"><code><?= hub_h((string)($manifest['type'] ?? '')) ?></code></div>
                        <div class="hub-meta-label">執行層級</div>
                        <div class="hub-meta-value">runtime_level: <code><?= hub_h($runtimeLevel) ?></code></div>
                        <div class="hub-meta-label">目標層級</div>
                        <div class="hub-meta-value">target_level: <code><?= hub_h($targetLevel) ?></code></div>
                        <div class="hub-meta-label">預設 mode</div>
                        <div class="hub-meta-value">mode: <code><?= hub_h($defaultMode) ?></code></div>
                        <div class="hub-meta-label">API endpoint</div>
                        <div class="hub-meta-value">endpoint: <code><?= hub_h($endpoint) ?></code></div>
                        <div class="hub-meta-label">execution_type</div>
                        <div class="hub-meta-value"><code><?= hub_h((string)($manifest['execution_type'] ?? '')) ?></code></div>
                        <div class="hub-meta-label">GPU 需求</div>
                        <div class="hub-meta-value"><?= hub_h((string)$gpu['label']) ?></div>
                        <div class="hub-meta-label">模型需求</div>
                        <div class="hub-meta-value"><?= hub_h((string)$model['label']) ?></div>
                        <div class="hub-meta-label">已安裝服務數</div>
                        <div class="hub-meta-value"><?= (int)$stats['count'] ?><?php if ((string)$stats['modes'] !== ''): ?> / modes: <code><?= hub_h((string)$stats['modes']) ?></code><?php endif; ?></div>
                        <div class="hub-meta-label">安裝狀態</div>
                        <div class="hub-meta-value"><span class="<?= $pack['status'] === 'ok' ? 'ok' : 'bad' ?>"><?= $pack['status'] === 'ok' ? '可安裝' : 'pack 驗證失敗' ?></span></div>
                    </div>
                    <?php if ($preflight['summary']['total'] > 0): ?>
                        <p><strong>Preflight</strong>
                            <span class="<?= hub_status_class($preflight['summary']['status']) ?>"><?= hub_status_label($preflight['summary']['status']) ?></span>
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
                    <?php if ($pack['errors']): ?>
                        <pre class="inline-pre"><?= hub_h(implode("\n", $pack['errors'])) ?></pre>
                    <?php endif; ?>
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
                            <div class="hub-actions">
                                <button class="primary" type="submit">安裝為服務</button>
                                <a class="button" href="api_docs.php">查看 API 文件</a>
                                <a class="button" href="benchmarks.php">Benchmark 測試</a>
                                <a class="button" href="pack_readiness.php?pack_id=<?= urlencode($packId) ?>">準備狀態</a>
                                <a class="button" href="services.php">已安裝服務</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
