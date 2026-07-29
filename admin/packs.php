<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin_market.php';
require_once __DIR__ . '/_layout.php';

function hub_pack_local_job_keys(array $manifest): array
{
    $keys = [];
    foreach (is_array($manifest['local_jobs'] ?? null) ? $manifest['local_jobs'] : [] as $job) {
        if (is_array($job) && (string)($job['job_key'] ?? '') !== '') {
            $keys[] = (string)$job['job_key'];
        }
    }

    return $keys;
}

function hub_pack_platform_targets_label(array $manifest): string
{
    $targets = is_array($manifest['platform_targets'] ?? null) ? $manifest['platform_targets'] : [];
    if ($targets === []) {
        return 'platform_targets: none';
    }

    $rows = [];
    foreach ($targets as $target => $meta) {
        $meta = is_array($meta) ? $meta : [];
        $sourceKey = (string)($meta['source'] ?? 'declared');
        $source = ['legacy_inferred' => 'legacy inferred', 'declared' => 'declared'][$sourceKey] ?? $sourceKey;
        $packState = !empty($meta['supported']) ? 'supported' : 'unsupported';
        $hostState = hub_platform_target_supported((string)$target);
        $reason = (string)($meta['reason'] ?? $hostState['reason'] ?? '');
        $rows[] = (string)$target . ': ' . $packState . ' / source: ' . $source
            . ' / host: ' . (!empty($hostState['supported']) ? 'supported' : 'unsupported')
            . ($reason !== '' ? ' / unsupported reason: ' . $reason : '');
    }

    return implode("\n", $rows);
}

function hub_pack_empty_state(string $tab): string
{
    return [
        'audio' => '目前沒有音訊語音套件。',
        'tools' => '目前沒有工具類套件。',
        'experimental' => '目前沒有實驗中套件。',
        'reference' => '目前沒有參考樣板套件。',
        'vision' => '目前沒有視覺影像套件。',
        'language' => '目前沒有語言文字套件。',
    ][$tab] ?? '目前沒有 HubPack。';
}

function hub_packs_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** @deprecated Canonical UI: admin/marketplace.php */
$db = hub_db();
$user = hub_require_system_admin($db);
$message = '';
$error = '';

if (($_GET['ajax'] ?? '') === 'readiness') {
    $packId = (string)($_GET['pack_id'] ?? '');
    $pack = hub_get_pack($packId);
    if (!$pack || $pack['status'] !== 'ok') {
        hub_packs_json(404, ['ok' => false, 'error' => '找不到 HubPack。']);
    }
    $label = hub_admin_market_readiness_label($db, $packId, is_array($pack['manifest'] ?? null) ? $pack['manifest'] : []);
    hub_packs_json(200, ['ok' => true, 'pack_id' => $packId, 'readiness' => $label]);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $result = hub_install_pack($db, (string)($_POST['pack_id'] ?? ''));
        $message = '已安裝 HubPack：' . $result['service']['name'] . ' / ' . $result['service']['service_key'];
        $demos = $result['edge_tts_demos'] ?? null;
        if (is_array($demos) && isset($demos['succeeded'], $demos['failed'])) {
            $message .= '；語音示範成功 ' . (int)$demos['succeeded'] . ' 個，失敗 ' . (int)$demos['failed'] . ' 個。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$catalog = hub_admin_market_catalog($db, (string)($_GET['tab'] ?? 'all'));
$activeTab = $catalog['active_category'];
$categories = hub_admin_market_categories();
$tabs = array_keys($categories);
$installed = hub_admin_market_installed_stats($db);
$visiblePacks = $catalog['packs'];
$tabCounts = $catalog['counts'];

hub_admin_header('HubPack 套件', $user);
?>
<section class="notice legacy-debug" role="note">
    <strong><?= hub_h(__('Legacy debug 頁面')) ?></strong>
    <?= hub_h(__('此頁已退出主選單，正式操作請使用「安裝套件」。')) ?>
    <a href="marketplace.php"><?= hub_h(__('前往安裝套件')) ?></a>
</section>
<style>
    .pack-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .pack-tab { border: 1px solid var(--line); border-radius: 8px; color: var(--text); padding: 8px 11px; text-decoration: none; }
    .pack-tab.is-active { background: var(--blue); border-color: var(--blue); color: #fff; }
    .pack-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(310px, 1fr)); }
    .pack-card { border: 1px solid var(--line); border-radius: 8px; background: #fff; padding: 16px; }
    .pack-card h2 { margin: 0 0 6px; }
    .pack-card-header { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
    .pack-id { color: var(--muted); font-size: 13px; }
    .pack-badges { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0 12px; }
    .pack-badge { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 8px; }
    .pack-badge-ok { background: #dcfae6; color: #067647; }
    .pack-badge-blue { background: #e8f1ff; color: #175cd3; }
    .pack-badge-purple { background: #f4ebff; color: #6941c6; }
    .pack-badge-warn { background: #fff6d7; color: #854a0e; }
    .pack-badge-muted { background: #f2f4f7; color: #475467; }
    .pack-badge-bad { background: #fee4e2; color: #b42318; }
    .pack-fields { display: grid; grid-template-columns: minmax(108px, 0.42fr) 1fr; gap: 7px 12px; margin-top: 12px; }
    .pack-field-label { color: var(--muted); font-size: 13px; }
    .pack-field-value { min-width: 0; overflow-wrap: anywhere; }
    .pack-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
    .pack-actions form { margin: 0; }
    .pack-actions button[disabled], .pack-action-disabled { background: #f2f4f7; color: #98a2b3; cursor: not-allowed; }
    .pack-description { color: var(--muted); font-size: 14px; margin: 8px 0 0; }
    .pack-empty { border: 1px dashed var(--line); border-radius: 8px; color: var(--muted); padding: 20px; text-align: center; }
</style>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>HubPack 套件</h1>
    <p class="muted">本機 HubPack 依能力分頁整理；技術識別字如 <code>pack_id</code>、<code>mode</code>、<code>runtime_level</code>、<code>endpoint</code> 保留英文，避免和 API contract 對不起來。</p>
    <div class="pack-tabs" aria-label="HubPack 分類">
        <?php foreach ($tabs as $tab): ?>
            <a class="pack-tab<?= $activeTab === $tab ? ' is-active' : '' ?>" href="packs.php?tab=<?= hub_h($tab) ?>">
                <?= hub_h((string)$categories[$tab]) ?> <span class="muted">(<?= (int)($tabCounts[$tab] ?? 0) ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel">
    <h2><?= hub_h((string)$categories[$activeTab]) ?> HubPack</h2>
    <?php if ($visiblePacks === []): ?>
        <div class="pack-empty"><?= hub_h(hub_pack_empty_state($activeTab)) ?></div>
    <?php else: ?>
        <div class="pack-grid">
            <?php foreach ($visiblePacks as $pack): ?>
                <?php
                $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
                $packId = (string)($pack['id'] ?? $manifest['id'] ?? '');
                $runtimeLevel = (string)($manifest['runtime_level'] ?? '');
                $targetLevel = (string)($manifest['target_level'] ?? '');
                $stats = $installed[$packId] ?? ['count' => 0, 'modes' => '', 'first_service_id' => 0];
                $gpu = hub_admin_market_gpu_label($manifest);
                $model = hub_admin_market_model_label($db, $manifest);
                $isReference = (string)($manifest['role'] ?? '') === 'reference';
                $endpoint = hub_admin_market_endpoint_label($manifest);
                $firstServiceId = (int)($stats['first_service_id'] ?? 0);
                $runtimeModes = hub_admin_market_runtime_modes($manifest);
                $localJobs = hub_pack_local_job_keys($manifest);
                ?>
                <article class="pack-card">
                    <div class="pack-card-header">
                        <div>
                            <h2><?= hub_h((string)($manifest['name'] ?? $packId)) ?></h2>
                            <div class="pack-id">pack_id: <code><?= hub_h($packId) ?></code></div>
                        </div>
                        <span class="<?= hub_h(hub_admin_market_runtime_badge_class($runtimeLevel)) ?>"><?= hub_h(hub_admin_market_runtime_label($runtimeLevel)) ?></span>
                    </div>
                    <?php if ($isReference): ?>
                        <p class="pack-description"><strong>參考樣板</strong>：最小 L5 HubPack 樣板，用於驗證安裝、Gateway、Benchmark、準備狀態與 API 文件。</p>
                    <?php elseif ((string)($pack['description'] ?? '') !== ''): ?>
                        <p class="pack-description"><?= hub_h((string)$pack['description']) ?></p>
                    <?php endif; ?>
                    <div class="pack-badges">
                        <?php if ($isReference): ?><span class="pack-badge pack-badge-purple">參考樣板</span><?php endif; ?>
                        <span class="<?= hub_h((string)$gpu['class']) ?>"><?= hub_h((string)$gpu['label']) ?></span>
                        <span class="<?= hub_h((string)$model['class']) ?>"><?= hub_h((string)$model['label']) ?></span>
                        <span class="<?= !empty($manifest['runtime_ready']) ? 'pack-badge pack-badge-ok' : 'pack-badge pack-badge-bad' ?>"><?= !empty($manifest['runtime_ready']) ? 'Runtime 可用' : 'Runtime 未就緒' ?></span>
                        <?php foreach ($runtimeModes as $mode): ?>
                            <span class="pack-badge pack-badge-blue"><?= hub_h(ucfirst($mode)) ?></span>
                        <?php endforeach; ?>
                        <?php if ($localJobs !== []): ?><span class="pack-badge pack-badge-warn">Preview Adapter</span><?php endif; ?>
                    </div>
                    <div class="pack-fields">
                        <div class="pack-field-label">套件名稱</div>
                        <div class="pack-field-value"><?= hub_h((string)($manifest['name'] ?? '')) ?></div>
                        <div class="pack-field-label">套件 ID</div>
                        <div class="pack-field-value"><code><?= hub_h($packId) ?></code></div>
                        <div class="pack-field-label">版本</div>
                        <div class="pack-field-value"><code><?= hub_h((string)($manifest['version'] ?? '')) ?></code></div>
                        <div class="pack-field-label">分類</div>
                        <div class="pack-field-value"><?= hub_h((string)$categories[$pack['market_category']]) ?> / <code><?= hub_h((string)($manifest['category'] ?? '')) ?></code></div>
                        <div class="pack-field-label">類型</div>
                        <div class="pack-field-value"><code><?= hub_h((string)($manifest['type'] ?? '')) ?></code></div>
                        <div class="pack-field-label">角色</div>
                        <div class="pack-field-value"><?= $isReference ? '參考樣板 / ' : '' ?><code><?= hub_h((string)($manifest['role'] ?? '')) ?></code></div>
                        <div class="pack-field-label">執行層級</div>
                        <div class="pack-field-value">runtime_level: <code><?= hub_h($runtimeLevel) ?></code></div>
                        <div class="pack-field-label">目標層級</div>
                        <div class="pack-field-value">target_level: <code><?= hub_h($targetLevel) ?></code></div>
                        <div class="pack-field-label">預設模式</div>
                        <div class="pack-field-value">mode: <code><?= hub_h((string)($manifest['default_mode'] ?? '')) ?></code></div>
                        <div class="pack-field-label">API 端點</div>
                        <div class="pack-field-value">endpoint: <code><?= hub_h($endpoint) ?></code></div>
                        <div class="pack-field-label">執行類型</div>
                        <div class="pack-field-value"><code><?= hub_h((string)($manifest['execution_type'] ?? '')) ?></code></div>
                        <div class="pack-field-label">Runtime</div>
                        <div class="pack-field-value">Runtime：<?= hub_h(implode(' + ', array_map(static fn (string $mode): string => ucfirst($mode), $runtimeModes))) ?> <span class="muted">runtime_modes</span></div>
                        <div class="pack-field-label">Runtime Contract</div>
                        <div class="pack-field-value"><code><?= hub_h((string)($manifest['runtime_contract'] ?? '')) ?></code></div>
                        <div class="pack-field-label">Platform Targets</div>
                        <div class="pack-field-value"><span class="muted">platform_targets</span><pre class="inline-pre"><?= hub_h(hub_pack_platform_targets_label($manifest)) ?></pre></div>
                        <div class="pack-field-label">Local Jobs</div>
                        <div class="pack-field-value">
                            <?php if ($localJobs === []): ?>
                                <span class="muted">無</span> <span class="muted">local_jobs</span>
                            <?php else: ?>
                                <span class="muted">local_jobs</span>
                                <ul>
                                    <?php foreach ($localJobs as $jobKey): ?><li><code><?= hub_h($jobKey) ?></code></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <div class="pack-field-label">GPU 需求</div>
                        <div class="pack-field-value"><?= hub_h((string)$gpu['label']) ?></div>
                        <div class="pack-field-label">模型需求</div>
                        <div class="pack-field-value"><?= hub_h((string)$model['label']) ?></div>
                        <div class="pack-field-label">已安裝服務</div>
                        <div class="pack-field-value">Installed: <?= (int)$stats['count'] ?><?php if ((string)$stats['modes'] !== ''): ?> / modes: <code><?= hub_h((string)$stats['modes']) ?></code><?php endif; ?></div>
                        <div class="pack-field-label">L5 準備狀態</div>
                        <div class="pack-field-value" data-readiness-url="packs.php?ajax=readiness">
                            <span class="pack-readiness-value" data-pack-id="<?= hub_h($packId) ?>"><?= hub_h(hub_admin_market_readiness_label($db, $packId, $manifest)) ?></span>
                            <button class="pack-readiness-refresh" type="button" data-pack-id="<?= hub_h($packId) ?>">刷新</button>
                        </div>
                    </div>
                    <?php if (!empty($pack['errors'])): ?>
                        <pre class="inline-pre"><?= hub_h(implode("\n", $pack['errors'])) ?></pre>
                    <?php endif; ?>
                    <div class="pack-actions">
                        <?php if ($pack['status'] === 'ok' && (int)$stats['count'] === 0): ?>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                                <input type="hidden" name="pack_id" value="<?= hub_h($packId) ?>">
                                <button class="primary" type="submit">安裝為服務</button>
                            </form>
                        <?php else: ?>
                            <button type="button" disabled>安裝為服務</button>
                        <?php endif; ?>
                        <a class="button" href="api_docs.php">查看 API 文件</a>
                        <a class="button" href="benchmarks.php">Benchmark 測試</a>
                        <a class="button" href="pack_readiness.php?pack_id=<?= urlencode($packId) ?>">準備狀態</a>
                        <a class="button" href="services.php">已安裝服務</a>
                        <?php if ($firstServiceId > 0): ?>
                            <a class="button" href="service_settings.php?service_id=<?= (int)$firstServiceId ?>">設定</a>
                            <a class="button" href="service_logs.php?id=<?= (int)$firstServiceId ?>">記錄</a>
                            <a class="button" href="services.php">健康檢查</a>
                        <?php else: ?>
                            <span class="button pack-action-disabled">設定</span>
                            <span class="button pack-action-disabled">記錄</span>
                            <span class="button pack-action-disabled">健康檢查</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<script src="../assets/js/jquery.min.js"></script>
<script id="market-i18n" type="application/json"><?= hub_json_encode([
    'refreshing' => __('刷新中'),
    'refresh' => __('刷新'),
    'readiness_failed' => __('讀取失敗'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="../assets/js/packs.js"></script>
<?php hub_admin_footer(); ?>
