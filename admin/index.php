<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$siteTitle = hub_site_title($db);
$siteSubtitle = hub_site_subtitle($db);
$snapshot = hub_latest_host_metric_snapshot($db);
$metrics = $snapshot['data'] ?? null;

function hub_dash_value(mixed $value, string $suffix = ''): string
{
    return $value === null || $value === '' ? 'N/A' : (string)$value . $suffix;
}

function hub_dash_percent(mixed $value): float
{
    return is_numeric($value) ? max(0, min(100, (float)$value)) : 0.0;
}

function hub_dash_gb_value(mixed $value): string
{
    return is_numeric($value) ? (string)round((float)$value, 1) . ' GB' : 'N/A';
}

function hub_dash_t(string $value): string
{
    return hub_h(__($value));
}

function hub_dash_pending_items(array $metrics): array
{
    $counts = $metrics['counts'] ?? [];
    $items = [];
    if ((int)($counts['queued_tasks'] ?? 0) > 0) {
        $items[] = __('待處理 tasks：') . (int)$counts['queued_tasks'];
    }
    if ((int)($counts['running_tasks'] ?? 0) > 0) {
        $items[] = __('執行中 tasks：') . (int)$counts['running_tasks'];
    }
    if ((int)($counts['failed_tasks'] ?? 0) > 0) {
        $items[] = __('失敗 tasks：') . (int)$counts['failed_tasks'];
    }
    if ((int)($counts['queued_command_jobs'] ?? 0) > 0) {
        $items[] = __('Command jobs 排隊中：') . (int)$counts['queued_command_jobs'];
    }
    if ((int)($counts['not_ready_services'] ?? 0) > 0) {
        $items[] = __('Runtime pending services：') . (int)$counts['not_ready_services'];
    }
    if (($metrics['docker']['warning'] ?? '') !== '') {
        $items[] = __('Docker root warning：') . $metrics['docker']['warning'];
    }
    if (($metrics['host']['memory_pressure'] ?? 'ok') !== 'ok') {
        $items[] = __('Memory pressure：MemAvailable=') . hub_dash_value($metrics['host']['ram_available_percent'] ?? null, '%')
            . ' vmstat si/so=' . hub_dash_value($metrics['host']['vmstat_si'] ?? null) . '/'
            . hub_dash_value($metrics['host']['vmstat_so'] ?? null);
    }

    return $items ?: [__('目前沒有明顯待處理項。')];
}

function hub_dash_scalar(PDO $db, string $sql, array $params = []): int
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function hub_dash_service_health_counts(PDO $db): array
{
    $services = hub_list_services($db);
    $latest = [];
    $stmt = $db->query("SELECT service_id, status FROM command_jobs WHERE action = 'service_health_check' AND service_id IS NOT NULL ORDER BY id DESC");
    foreach ($stmt->fetchAll() as $job) {
        $serviceId = (int)$job['service_id'];
        if ($serviceId > 0 && !isset($latest[$serviceId])) {
            $latest[$serviceId] = (string)$job['status'];
        }
    }

    $ok = 0;
    foreach ($services as $service) {
        if (($latest[(int)$service['id']] ?? '') === 'success') {
            $ok++;
        }
    }

    return ['ok' => $ok, 'attention' => max(0, count($services) - $ok)];
}

function hub_dash_enabled_label(string $value): string
{
    return $value === '1' ? __('啟用') : __('停用');
}

function hub_dash_control_center(PDO $db): array
{
    $since = date('Y-m-d H:i:s', time() - 86400);
    $modelUsage = hub_get_disk_usage_for_path(hub_models_root($db));
    $total = is_numeric($modelUsage['total_bytes']) ? (float)$modelUsage['total_bytes'] : null;
    $free = is_numeric($modelUsage['free_bytes']) ? (float)$modelUsage['free_bytes'] : null;
    $used = $total !== null && $free !== null ? max(0, $total - $free) : null;

    $l5PackCount = 0;
    $readinessReady = 0;
    $readinessTotal = 0;
    foreach (hub_list_packs() as $pack) {
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        if ((string)($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready') {
            $l5PackCount++;
        }
        if (!is_array($manifest['l5_contract'] ?? null)) {
            continue;
        }
        $readinessTotal++;
        try {
            $readiness = hub_pack_l5_readiness($db, (string)$pack['id']);
            if ((int)$readiness['total_count'] > 0 && (int)$readiness['pass_count'] === (int)$readiness['total_count']) {
                $readinessReady++;
            }
        } catch (Throwable) {
            continue;
        }
    }
    $health = hub_dash_service_health_counts($db);

    return [
        'services_total' => hub_dash_scalar($db, 'SELECT COUNT(*) FROM services'),
        'services_running' => hub_dash_scalar($db, "SELECT COUNT(*) FROM services WHERE status = 'running'"),
        'services_stopped' => hub_dash_scalar($db, "SELECT COUNT(*) FROM services WHERE status != 'running'"),
        'services_disabled' => hub_dash_scalar($db, 'SELECT COUNT(*) FROM services WHERE enabled != 1'),
        'services_health_ok' => $health['ok'],
        'services_health_attention' => $health['attention'],
        'l5_pack_count' => $l5PackCount,
        'api_calls_24h' => hub_dash_scalar($db, 'SELECT COUNT(*) FROM api_access_logs WHERE created_at >= :since', [':since' => $since]),
        'api_failed_24h' => hub_dash_scalar($db, 'SELECT COUNT(*) FROM api_access_logs WHERE ok = 0 AND created_at >= :since', [':since' => $since]),
        'running_jobs' => hub_dash_scalar($db, "SELECT COUNT(*) FROM command_jobs WHERE status IN ('queued', 'running')"),
        'failed_jobs' => hub_dash_scalar($db, "SELECT COUNT(*) FROM command_jobs WHERE status = 'failed'"),
        'recent_jobs' => hub_list_command_jobs($db, 5),
        'recent_failed_api' => hub_dash_recent_failed_api($db, $since),
        'pack_readiness_ready' => $readinessReady,
        'pack_readiness_total' => $readinessTotal,
        'models_total' => hub_model_format_bytes($total),
        'models_free' => hub_model_format_bytes($free),
        'models_used_percent' => $used !== null && $total !== null && $total > 0 ? round(($used / $total) * 100, 1) : 0,
        'public_api_docs' => hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS'),
        'public_api_manifest' => hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST'),
        'public_api_local_only' => hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY'),
        'runtime' => hub_dash_runtime_summary($db, $since),
    ];
}

function hub_dash_runtime_summary(PDO $db, string $since): array
{
    $jobPacks = 0;
    foreach (hub_list_packs() as $pack) {
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        if (($manifest['local_jobs'] ?? []) !== []) {
            $jobPacks++;
        }
    }
    if (!hub_table_exists($db, 'runtime_runs')) {
        return [
            'runs_24h' => 0,
            'running' => 0,
            'failed_24h' => 0,
            'job_packs' => $jobPacks,
            'memory_peak_bytes' => null,
            'vram_peak_bytes' => null,
        ];
    }

    return [
        'runs_24h' => hub_dash_scalar($db, 'SELECT COUNT(*) FROM runtime_runs WHERE started_at >= :since', [':since' => $since]),
        'running' => hub_dash_scalar($db, "SELECT COUNT(*) FROM runtime_runs WHERE state = 'running'"),
        'failed_24h' => hub_dash_scalar($db, "SELECT COUNT(*) FROM runtime_runs WHERE state = 'failed' AND started_at >= :since", [':since' => $since]),
        'job_packs' => $jobPacks,
        'memory_peak_bytes' => $db->query('SELECT MAX(memory_peak_bytes) FROM runtime_runs')->fetchColumn(),
        'vram_peak_bytes' => $db->query('SELECT MAX(vram_peak_bytes) FROM runtime_runs')->fetchColumn(),
    ];
}

function hub_dash_recent_failed_api(PDO $db, string $since): array
{
    $stmt = $db->prepare(
        'SELECT request_id, mode, status_code, error_code, created_at
         FROM api_access_logs
         WHERE ok = 0 AND created_at >= :since
         ORDER BY id DESC
         LIMIT 5'
    );
    $stmt->execute([':since' => $since]);
    return $stmt->fetchAll();
}

$control = hub_dash_control_center($db);
$runtime = $control['runtime'];

hub_admin_header('儀表板', $user);
?>
<style>
    .dash-grid { display: grid; gap: 16px; grid-template-columns: repeat(12, 1fr); }
    .dash-card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 16px; }
    .dash-card h3 { margin: 0 0 8px; }
    .dash-span-3 { grid-column: span 3; }
    .dash-span-4 { grid-column: span 4; }
    .dash-span-6 { grid-column: span 6; }
    .dash-span-12 { grid-column: span 12; }
    .dash-number { font-size: 28px; font-weight: 800; line-height: 1.1; }
    .dash-chart { height: 220px; }
    .dash-chart-compact { height: 128px; margin-top: auto; }
    .dash-metric-card { min-height: 230px; display: flex; flex-direction: column; gap: 12px; }
    .dash-metric-top { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; }
    .dash-metric-card h3 { margin-bottom: 4px; }
    .dash-metric-kind { color: var(--muted); font-size: 13px; }
    .dash-metric-value { font-size: 30px; line-height: 1; font-weight: 850; text-align: right; }
    .dash-metric-detail { color: var(--muted); font-size: 14px; }
    .dash-memory { display: grid; gap: 8px; grid-template-columns: repeat(4, 1fr); margin: 12px 0; }
    .dash-memory div { border: 1px solid var(--line); border-radius: 8px; padding: 10px; }
    .dash-memory strong { display: block; font-size: 13px; color: var(--muted); }
    .dash-list { margin: 0; padding-left: 20px; }
    @media (max-width: 900px) { .dash-card { grid-column: span 12; } .dash-memory { grid-template-columns: repeat(2, 1fr); } }
</style>
<section class="panel">
    <h1><?= hub_h($siteTitle) ?> <?= hub_h(__('總覽中控台')) ?></h1>
    <p class="muted"><?= hub_h($siteSubtitle) ?>。<?= hub_dash_t('儀表板只讀 SQLite 最新監測快照；主機探測請由 CLI / cron 執行。') ?></p>
</section>

<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(__('總覽摘要')) ?></h2>
        <span class="muted"><?= hub_dash_t('最近 24 小時與目前服務狀態') ?></span>
    </div>
    <div class="hub-card-grid">
        <article class="hub-card"><h3><?= hub_h(__('服務總數')) ?></h3><div class="dash-number"><?= (int)$control['services_total'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('執行中') ?></h3><div class="dash-number ok"><?= (int)$control['services_running'] ?></div><p class="muted"><?= hub_dash_t('服務執行中') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('健康正常') ?></h3><div class="dash-number ok"><?= (int)$control['services_health_ok'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('健康異常 / 未檢查') ?></h3><div class="dash-number bad"><?= (int)$control['services_health_attention'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('已停止') ?></h3><div class="dash-number"><?= (int)$control['services_stopped'] ?></div><p class="muted"><?= hub_dash_t('服務已停止') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('已停用') ?></h3><div class="dash-number bad"><?= (int)$control['services_disabled'] ?></div><p class="muted"><?= hub_dash_t('服務已停用') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('L5 Pack 數') ?></h3><div class="dash-number"><?= (int)$control['l5_pack_count'] ?></div></article>
        <article class="hub-card"><h3><?= hub_h(__('API 24h 呼叫數')) ?></h3><div class="dash-number"><?= (int)$control['api_calls_24h'] ?></div><p class="muted"><?= hub_dash_t('最近 24 小時 API 呼叫') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('API 24h 失敗數') ?></h3><div class="dash-number bad"><?= (int)$control['api_failed_24h'] ?></div><p class="muted"><?= hub_dash_t('最近 24 小時 API 失敗') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('背景工作執行中') ?></h3><div class="dash-number"><?= (int)$control['running_jobs'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('最近失敗工作') ?></h3><div class="dash-number bad"><?= (int)$control['failed_jobs'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('Pack 準備狀態') ?></h3><div class="dash-number"><?= (int)$control['pack_readiness_ready'] ?>/<?= (int)$control['pack_readiness_total'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('模型儲存使用率') ?></h3><div class="dash-number"><?= hub_h((string)$control['models_used_percent']) ?>%</div><p class="muted"><?= hub_dash_t('可用 / 總量') ?> <?= hub_h((string)$control['models_free']) ?> / <?= hub_h((string)$control['models_total']) ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('Runtime 24h 執行數') ?></h3><div class="dash-number"><?= (int)$runtime['runs_24h'] ?></div><p class="muted"><?= hub_dash_t('執行歷程') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('執行中 Runtime') ?></h3><div class="dash-number"><?= (int)$runtime['running'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('24h 失敗 Runtime') ?></h3><div class="dash-number bad"><?= (int)$runtime['failed_24h'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('支援 Job 的 Pack') ?></h3><div class="dash-number"><?= (int)$runtime['job_packs'] ?></div></article>
        <article class="hub-card"><h3><?= hub_dash_t('最高 RAM 使用') ?></h3><div class="dash-number"><?= hub_h(hub_model_format_bytes(is_numeric($runtime['memory_peak_bytes']) ? (float)$runtime['memory_peak_bytes'] : null)) ?></div><p class="muted"><?= hub_dash_t('資源取樣') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('最高 GPU VRAM 使用') ?></h3><div class="dash-number"><?= hub_h(hub_model_format_bytes(is_numeric($runtime['vram_peak_bytes']) ? (float)$runtime['vram_peak_bytes'] : null)) ?></div></article>
        <article class="hub-card">
            <h3><?= hub_dash_t('介接公開狀態') ?></h3>
            <p class="muted"><?= hub_dash_t('未登入 API 文件') ?>：<strong><?= hub_h(hub_dash_enabled_label((string)$control['public_api_docs'])) ?></strong></p>
            <p class="muted"><?= hub_dash_t('Agent Manifest 文件') ?>：<strong><?= hub_h(hub_dash_enabled_label((string)$control['public_api_manifest'])) ?></strong></p>
            <p class="muted"><?= hub_dash_t('僅本機') ?>：<strong><?= (string)$control['public_api_local_only'] === '1' ? hub_dash_t('是') : hub_dash_t('否') ?></strong></p>
        </article>
    </div>
    <div class="hub-actions">
        <a class="button" href="services.php"><?= hub_h(__('服務管理')) ?></a>
        <a class="button" href="runtime_runs.php"><?= hub_dash_t('執行歷程') ?></a>
        <a class="button" href="playground.php"><?= hub_dash_t('API 測試場') ?></a>
        <a class="button" href="api_docs.php"><?= hub_dash_t('後台 API 文件') ?></a>
        <a class="button" href="packs.php"><?= hub_dash_t('HubPack 套件') ?></a>
        <a class="button" href="models.php"><?= hub_dash_t('模型倉庫') ?></a>
        <a class="button" href="api_members.php"><?= hub_dash_t('API 金鑰') ?></a>
        <a class="button" href="log_explorer.php"><?= hub_dash_t('記錄中心') ?></a>
        <a class="button" href="../public_api_docs.php"><?= hub_dash_t('公開 API 文件') ?></a>
        <a class="button" href="../api_manifest.json.php"><?= hub_dash_t('Agent Manifest 文件') ?></a>
    </div>
</section>

<section class="panel">
    <h2><?= hub_h(__('平台能力矩陣')) ?></h2>
    <div class="hub-card-grid">
        <article class="hub-card"><h3><?= hub_dash_t('● Service Runtime（常駐服務）') ?></h3><p class="muted"><?= hub_dash_t('已完成：Docker 服務生命週期、健康檢查、Gateway。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('◐ Local Job Runtime（本機工作）') ?></h3><p class="muted"><?= hub_dash_t('薄版已完成：Local Job Contract、aihub-run、workspace。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('● API Gateway（API 閘道）') ?></h3><p class="muted"><?= hub_dash_t('已完成：mode route、Bearer Token、IP control、稽核記錄。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('◐ Run History（執行歷程）') ?></h3><p class="muted"><?= hub_dash_t('薄版已完成：runtime_runs、資源取樣、logs/result index。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('○ External Database Profile（外部資料庫設定）') ?></h3><p class="muted"><?= hub_dash_t('規劃中。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('○ Controlled Volume Binding（受控 Volume 掛載）') ?></h3><p class="muted"><?= hub_dash_t('規劃中。') ?></p></article>
        <article class="hub-card"><h3><?= hub_dash_t('○ Generic Service Publishing（通用服務發佈）') ?></h3><p class="muted"><?= hub_dash_t('規劃中。') ?></p></article>
    </div>
</section>

<section class="dash-grid">
    <div class="dash-card dash-span-6">
        <h3><?= hub_h(__('最近背景工作')) ?></h3>
        <?php if ($control['recent_jobs'] === []): ?>
            <p class="muted"><?= hub_dash_t('尚無背景工作。') ?></p>
        <?php else: ?>
            <ul class="dash-list">
                <?php foreach ($control['recent_jobs'] as $job): ?>
                    <li>
                        <?= hub_h(hub_command_action_label((string)$job['action'])) ?>
                        <code><?= hub_h((string)$job['action']) ?></code>
                        / <?= hub_h((string)($job['service_name'] ?? '')) ?>
                        / <span class="<?= hub_status_class((string)$job['status']) ?>"><?= hub_h(hub_status_label((string)$job['status'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <div class="dash-card dash-span-6">
        <h3><?= hub_dash_t('最近失敗') ?></h3>
        <?php if ($control['recent_failed_api'] === []): ?>
            <p class="muted"><?= hub_dash_t('最近 24 小時沒有 API 失敗紀錄。') ?></p>
        <?php else: ?>
            <ul class="dash-list">
                <?php foreach ($control['recent_failed_api'] as $log): ?>
                    <li>
                        <a href="log_explorer.php?tab=api&amp;request_id=<?= urlencode((string)$log['request_id']) ?>"><code><?= hub_h((string)$log['request_id']) ?></code></a>
                        / mode <code><?= hub_h((string)$log['mode']) ?></code>
                        / <?= (int)$log['status_code'] ?>
                        / <code><?= hub_h((string)$log['error_code']) ?></code>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>

<?php if (!$metrics): ?>
    <section class="panel">
        <h2><?= hub_dash_t('尚未收集 metrics') ?></h2>
        <p><?= hub_dash_t('請先在主機執行：') ?></p>
        <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/collect_host_metrics.php') ?></pre>
    </section>
<?php else: ?>
    <?php
    $gpu = $metrics['gpu'] ?? [];
    $host = $metrics['host'] ?? [];
    $docker = $metrics['docker'] ?? [];
    $storage = $metrics['storage'] ?? [];
    $counts = $metrics['counts'] ?? [];
    $gpuName = !empty($gpu['available']) ? (string)$gpu['name'] : __('GPU 不可用');
    $vramUsedMb = is_numeric($gpu['memory_used_mb'] ?? null) ? (float)$gpu['memory_used_mb'] : null;
    $vramTotalMb = is_numeric($gpu['memory_total_mb'] ?? null) ? (float)$gpu['memory_total_mb'] : null;
    $gpuUtilPercent = hub_dash_percent($gpu['util_percent'] ?? 0);
    $gpuTempC = is_numeric($gpu['temperature_c'] ?? null) ? (float)$gpu['temperature_c'] : null;
    $vramPercent = $vramUsedMb !== null && $vramTotalMb !== null && $vramTotalMb > 0 ? round(($vramUsedMb / $vramTotalMb) * 100, 1) : 0;
    $vramUsedLabel = hub_model_format_bytes($vramUsedMb !== null ? $vramUsedMb * 1024 * 1024 : null);
    $vramTotalLabel = hub_model_format_bytes($vramTotalMb !== null ? $vramTotalMb * 1024 * 1024 : null);
    $gpuUtilLabel = is_numeric($gpu['util_percent'] ?? null) ? (string)round($gpuUtilPercent, 1) . '%' : 'N/A';
    $gpuTempLabel = $gpuTempC !== null ? (string)round($gpuTempC, 1) . '°C' : 'N/A';
    $chartData = [
        'vramPercent' => $vramPercent,
        'vramUsedLabel' => $vramUsedLabel,
        'vramTotalLabel' => $vramTotalLabel,
        'gpuUtil' => $gpuUtilPercent,
        'gpuUtilLabel' => $gpuUtilLabel,
        'gpuTemp' => hub_dash_percent($gpuTempC ?? 0),
        'gpuTempLabel' => $gpuTempLabel,
        'ramPercent' => hub_dash_percent($host['ram_used_percent'] ?? 0),
        'ramParts' => [
            ['name' => __('已用'), 'value' => (float)($host['ram_used_mb'] ?? 0)],
            ['name' => __('Buff/Cache'), 'value' => (float)($host['ram_buff_cache_mb'] ?? 0)],
            ['name' => __('可用'), 'value' => (float)($host['ram_available_mb'] ?? 0)],
        ],
        'diskBars' => [
            ['name' => '/', 'value' => hub_dash_percent($host['disk_root']['used_percent'] ?? 0)],
            ['name' => '/DATA', 'value' => hub_dash_percent($host['disk_data']['used_percent'] ?? 0)],
            ['name' => 'Models', 'value' => hub_dash_percent($storage['models_used_percent'] ?? 0)],
            ['name' => 'Docker', 'value' => hub_dash_percent($docker['root_used_percent'] ?? 0)],
        ],
        'serviceBars' => [
            ['name' => __('執行中'), 'value' => (int)($counts['running_services'] ?? 0)],
            ['name' => __('已停止'), 'value' => (int)($counts['stopped_services'] ?? 0)],
            ['name' => __('待處理'), 'value' => (int)($counts['not_ready_services'] ?? 0)],
            ['name' => __('錯誤'), 'value' => (int)($counts['error_services'] ?? 0)],
        ],
        'labels' => [
            'vram' => __('VRAM 使用量'),
            'gpuUtil' => __('GPU 使用率'),
            'temp' => __('溫度'),
            'ramTitle' => __('RAM（以 MemAvailable 判斷）'),
            'diskTitle' => __('磁碟使用率'),
            'serviceTitle' => __('服務狀態'),
        ],
    ];
    ?>
    <section class="dash-grid">
        <div class="dash-card dash-span-12">
            <h3>GPU <?= hub_h($gpuName) ?></h3>
            <?php if (empty($gpu['available'])): ?>
                <p class="bad"><?= hub_h((string)($gpu['reason'] ?? 'GPU unavailable')) ?></p>
            <?php else: ?>
                <p class="muted">
                    VRAM <?= hub_h($vramUsedLabel) ?> /
                    <?= hub_h($vramTotalLabel) ?>　
                    Driver <?= hub_h((string)($gpu['driver_version'] ?? '')) ?>　
                    CUDA <?= hub_h((string)($gpu['cuda_version'] ?? '')) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="dash-card dash-span-4 dash-metric-card">
            <div class="dash-metric-top">
                <div>
                    <h3><?= hub_dash_t('VRAM 使用量') ?></h3>
                    <div class="dash-metric-kind"><?= hub_dash_t('GPU 記憶體') ?></div>
                </div>
                <div class="dash-metric-value"><?= hub_h((string)$vramPercent) ?>%</div>
            </div>
            <div class="dash-metric-detail"><?= hub_h($vramUsedLabel) ?> / <?= hub_h($vramTotalLabel) ?></div>
            <div id="vramChart" class="dash-chart-compact"></div>
        </div>
        <div class="dash-card dash-span-4 dash-metric-card">
            <div class="dash-metric-top">
                <div>
                    <h3><?= hub_dash_t('GPU 使用率') ?></h3>
                    <div class="dash-metric-kind"><?= hub_dash_t('GPU 使用率') ?></div>
                </div>
                <div class="dash-metric-value"><?= hub_h($gpuUtilLabel) ?></div>
            </div>
            <div class="dash-metric-detail"><?= hub_dash_t('目前運算負載') ?></div>
            <div id="gpuChart" class="dash-chart-compact"></div>
        </div>
        <div class="dash-card dash-span-4 dash-metric-card">
            <div class="dash-metric-top">
                <div>
                    <h3><?= hub_dash_t('溫度') ?></h3>
                    <div class="dash-metric-kind"><?= hub_dash_t('GPU 溫度') ?></div>
                </div>
                <div class="dash-metric-value"><?= hub_h($gpuTempLabel) ?></div>
            </div>
            <div class="dash-metric-detail"><?= hub_dash_t('溫度上限以 100°C 顯示') ?></div>
            <div id="tempChart" class="dash-chart-compact"></div>
        </div>

        <div class="dash-card dash-span-3"><h3><?= hub_dash_t('Pack 數') ?></h3><div class="dash-number"><?= (int)($counts['packs'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3><?= hub_dash_t('服務數') ?></h3><div class="dash-number"><?= (int)($counts['services'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3><?= hub_dash_t('執行中') ?></h3><div class="dash-number ok"><?= (int)($counts['running_services'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3><?= hub_dash_t('待處理') ?></h3><div class="dash-number bad"><?= (int)($counts['not_ready_services'] ?? 0) ?></div></div>

        <div class="dash-card dash-span-6">
            <h3><?= hub_dash_t('主機負載') ?></h3>
            <p class="muted">
                <?= hub_dash_t('負載') ?> <?= hub_h(hub_dash_value($host['load_1'] ?? null)) ?> /
                <?= hub_h(hub_dash_value($host['load_5'] ?? null)) ?> /
                <?= hub_h(hub_dash_value($host['load_15'] ?? null)) ?>
            </p>
            <div class="dash-memory">
                <div><strong><?= hub_dash_t('已用') ?></strong><?= hub_h(hub_dash_value($host['ram_used_mb'] ?? null, ' MB')) ?></div>
                <div><strong>BuffCache</strong><?= hub_h(hub_dash_value($host['ram_buff_cache_mb'] ?? null, ' MB')) ?></div>
                <div><strong><?= hub_dash_t('可用') ?></strong><?= hub_h(hub_dash_value($host['ram_available_mb'] ?? null, ' MB')) ?> / <?= hub_h(hub_dash_value($host['ram_available_percent'] ?? null, '%')) ?></div>
                <div><strong><?= hub_dash_t('Swap 已用') ?></strong><?= hub_h(hub_dash_value($host['swap_used_mb'] ?? null, ' MB')) ?></div>
            </div>
            <p class="muted"><?= hub_dash_t('記憶體壓力') ?>：<span class="<?= ($host['memory_pressure'] ?? 'ok') === 'ok' ? 'ok' : 'bad' ?>"><?= hub_h((string)($host['memory_pressure'] ?? 'ok')) ?></span>　vmstat si/so: <?= hub_h(hub_dash_value($host['vmstat_si'] ?? null)) ?> / <?= hub_h(hub_dash_value($host['vmstat_so'] ?? null)) ?></p>
            <div id="ramChart" class="dash-chart"></div>
        </div>
        <div class="dash-card dash-span-6">
            <h3><?= hub_dash_t('磁碟 / 儲存') ?></h3>
            <p class="muted">
                <?= hub_dash_t('Docker 根目錄') ?>：<?= hub_h((string)($docker['root_dir'] ?? 'N/A')) ?>　
                <?= hub_dash_t('模型目錄') ?>：<?= hub_h((string)($storage['models_dir'] ?? 'N/A')) ?>
            </p>
            <p class="muted">
                <?= hub_dash_t('/ 可用空間') ?>：<?= hub_h(hub_dash_gb_value($host['disk_root']['free_gb'] ?? null)) ?> /
                <?= hub_h(hub_dash_gb_value($host['disk_root']['total_gb'] ?? null)) ?>　
                <?= hub_dash_t('Docker 根目錄可用') ?>：<?= hub_h(hub_dash_gb_value($docker['root_free_gb'] ?? null)) ?>　
                <?= hub_dash_t('模型目錄可用') ?>：<?= hub_h(hub_dash_gb_value($storage['models_free_gb'] ?? null)) ?> /
                <?= hub_h(hub_dash_gb_value($storage['models_total_gb'] ?? null)) ?>
            </p>
            <?php if (!empty($docker['reason'])): ?><p class="bad"><?= hub_dash_t('Docker daemon 目前不可用，請查看環境診斷。') ?></p><?php endif; ?>
            <div id="diskChart" class="dash-chart"></div>
        </div>

        <div class="dash-card dash-span-6"><div id="serviceChart" class="dash-chart"></div></div>
        <div class="dash-card dash-span-6">
            <h3><?= hub_h(__('待處理項')) ?></h3>
            <ul class="dash-list">
                <?php foreach (hub_dash_pending_items($metrics) as $item): ?>
                    <li><?= hub_h($item) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted"><?= hub_dash_t('最新 snapshot') ?>：<?= hub_h((string)$snapshot['created_at']) ?></p>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script>
    const metric = <?= json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    function clampPercent(value) {
        const number = Number(value);
        if (!Number.isFinite(number)) return 0;
        return Math.max(0, Math.min(100, number));
    }
    function metricColor(value, warnAt, badAt, normal = '#2563eb') {
        const number = clampPercent(value);
        if (number >= badAt) return '#dc2626';
        if (number >= warnAt) return '#d97706';
        return normal;
    }
    function metricBar(id, title, value, detail, color, scaleLabel = null) {
        if (!window.echarts) return;
        const el = document.getElementById(id);
        if (!el) return;
        const percent = clampPercent(value);
        const valueLabel = scaleLabel || (percent + '%');
        echarts.init(el).setOption({
            grid: { left: 8, right: 8, top: 46, bottom: 38 },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'none' },
                formatter: () => title + '<br>' + detail + '<br>' + valueLabel
            },
            xAxis: { type: 'value', min: 0, max: 100, show: false },
            yAxis: { type: 'category', data: [''], show: false },
            graphic: [
                { type: 'text', left: 8, top: 8, style: { text: title, fill: '#475467', font: '600 13px sans-serif' } },
                { type: 'text', right: 8, top: 8, style: { text: valueLabel, fill: '#111827', font: '700 13px sans-serif' } },
                { type: 'text', left: 'center', bottom: 4, style: { text: detail, fill: '#667085', font: '13px sans-serif', textAlign: 'center' } }
            ],
            series: [
                { type: 'bar', data: [100], barWidth: 18, itemStyle: { color: '#edf2fb', borderRadius: 9 }, silent: true, animation: false },
                { type: 'bar', data: [percent], barWidth: 18, barGap: '-100%', itemStyle: { color, borderRadius: 9 } }
            ]
        });
    }
    metricBar('vramChart', metric.labels.vram, metric.vramPercent, metric.vramUsedLabel + ' / ' + metric.vramTotalLabel, metricColor(metric.vramPercent, 70, 85));
    metricBar('gpuChart', metric.labels.gpuUtil, metric.gpuUtil, metric.gpuUtilLabel, metricColor(metric.gpuUtil, 70, 92));
    metricBar('tempChart', metric.labels.temp, metric.gpuTemp, metric.gpuTempLabel, metricColor(metric.gpuTemp, 65, 80, '#16a34a'), metric.gpuTempLabel);
    if (window.echarts) {
        echarts.init(document.getElementById('ramChart')).setOption({
            title: { text: metric.labels.ramTitle, left: 'center', top: 8, textStyle: { fontSize: 14 } },
            tooltip: { trigger: 'item' },
            series: [{ type: 'pie', radius: ['45%', '70%'], data: metric.ramParts }]
        });
        echarts.init(document.getElementById('diskChart')).setOption({
            title: { text: metric.labels.diskTitle, left: 'center', top: 8, textStyle: { fontSize: 14 } },
            xAxis: { type: 'category', data: metric.diskBars.map(x => x.name) },
            yAxis: { type: 'value', max: 100 },
            series: [{ type: 'bar', data: metric.diskBars.map(x => x.value) }]
        });
        echarts.init(document.getElementById('serviceChart')).setOption({
            title: { text: metric.labels.serviceTitle, left: 'center', top: 8, textStyle: { fontSize: 14 } },
            xAxis: { type: 'category', data: metric.serviceBars.map(x => x.name) },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: metric.serviceBars.map(x => x.value) }]
        });
    }
    </script>
<?php endif; ?>
<?php hub_admin_footer(); ?>
