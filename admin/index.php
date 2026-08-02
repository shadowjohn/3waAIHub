<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin_dashboard.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
if (!hub_is_system_admin($user)) {
    hub_redirect('my_services.php');
}

$siteTitle = hub_site_title($db);
$model = hub_admin_dashboard_model($db, $_GET);
$summary = $model['summary'];
$dashboardCssVersion = (string)(filemtime(HUB_ROOT . '/assets/css/admin-dashboard.css') ?: HUB_VERSION);
$dashboardJsVersion = (string)(filemtime(HUB_ROOT . '/assets/js/admin-dashboard.js') ?: HUB_VERSION);
$isStationDashboard = in_array($model['role'], ['router', 'aggregate'], true);
$hasSummary = !$isStationDashboard || $model['active_station'] !== null;

function hub_admin_dash_value(mixed $value, string $suffix = ''): string
{
    return is_numeric($value) ? number_format((float)$value, 1) . $suffix : 'N/A';
}

function hub_admin_dash_percent(mixed $used, mixed $total): float
{
    return is_numeric($used) && is_numeric($total) && (float)$total > 0
        ? round(max(0, min(100, (float)$used / (float)$total * 100)), 1)
        : 0.0;
}

function hub_admin_dash_history_summary(array $history, string $suffix): string
{
    if ($history === []) {
        return '';
    }
    $latest = $history[array_key_last($history)] ?? [];

    return sprintf(
        __('共 %1$d 筆資料；最新 %2$s：%3$s'),
        count($history),
        (string)($latest['label'] ?? ''),
        hub_admin_dash_value($latest['value'] ?? null, $suffix)
    );
}

function hub_admin_dash_compatibility(mixed $compatible): string
{
    return $compatible === null ? __('尚無資料') : ($compatible ? __('相容') : __('需要更新'));
}

$gpu = is_array($summary['gpu'] ?? null) ? $summary['gpu'] : [];
$host = is_array($summary['host'] ?? null) ? $summary['host'] : [];
$docker = is_array($summary['docker'] ?? null) ? $summary['docker'] : [];
$storage = is_array($summary['storage'] ?? null) ? $summary['storage'] : [];
$health = is_array($summary['health'] ?? null) ? $summary['health'] : [];
$cluster = is_array($summary['cluster'] ?? null) ? $summary['cluster'] : [];
$services = is_array($summary['services'] ?? null) ? $summary['services'] : [];
$runtime = is_array($summary['runtime'] ?? null) ? $summary['runtime'] : [];
$recentJobs = is_array($summary['recent_jobs'] ?? null) ? $summary['recent_jobs'] : [];
$serviceCounts = is_array($summary['service_counts'] ?? null)
    ? $summary['service_counts']
    : ['running' => 0, 'stopped' => 0, 'pending' => 0, 'error' => 0];
$healthStatus = is_string($health['status'] ?? null) ? $health['status'] : 'unknown';
$connectionState = (string)($summary['connection_state'] ?? 'offline');

$gpuTotal = is_numeric($gpu['memory_total_mb'] ?? null) ? (float)$gpu['memory_total_mb'] : null;
$gpuUsed = is_numeric($gpu['memory_used_mb'] ?? null)
    ? (float)$gpu['memory_used_mb']
    : ($gpuTotal !== null && is_numeric($gpu['memory_free_mb'] ?? null)
        ? max(0, $gpuTotal - (float)$gpu['memory_free_mb'])
        : null);
$vramPercent = hub_admin_dash_percent($gpuUsed, $gpuTotal);
$gpuExplicitlyUnavailable = ($gpu['available'] ?? null) === false;
$gpuAvailable = !$gpuExplicitlyUnavailable && $gpuTotal !== null && $gpuUsed !== null && $gpuTotal > 0;
$gpuUtil = !$gpuExplicitlyUnavailable && is_numeric($gpu['util_percent'] ?? null) ? (float)$gpu['util_percent'] : null;
$gpuTemperature = !$gpuExplicitlyUnavailable && is_numeric($gpu['temperature_c'] ?? null) ? (float)$gpu['temperature_c'] : null;
$gpuHistory = is_array($summary['gpu_history'] ?? null) ? $summary['gpu_history'] : [];
$gpuTemperatureHistory = is_array($gpuHistory['temperature'] ?? null) ? $gpuHistory['temperature'] : [];
$gpuVramHistory = is_array($gpuHistory['vram_used'] ?? null) ? $gpuHistory['vram_used'] : [];
$gpuTemperatureHistorySummary = hub_admin_dash_history_summary($gpuTemperatureHistory, '°C');
$gpuVramHistorySummary = hub_admin_dash_history_summary($gpuVramHistory, ' MB');
$snapshotAvailable = (string)($summary['snapshot_at'] ?? '') !== '';
$memoryPressure = (string)($host['memory_pressure'] ?? 'not_applicable');
$memoryApplicable = (($host['memory_status']['status'] ?? '') !== 'not_applicable')
    && is_numeric($host['ram_used_mb'] ?? null);
$rootDiskApplicable = (($host['disk_root']['status'] ?? '') !== 'not_applicable');
$dataDiskApplicable = (($host['disk_data']['status'] ?? '') !== 'not_applicable');
$linuxDiskApplicable = $rootDiskApplicable || $dataDiskApplicable;
$dockerRootApplicable = (($docker['root_status']['status'] ?? '') !== 'not_applicable');

$statusTone = 'ok';
$statusLabel = __('狀態正常');
$statusMessage = '';
if (!$hasSummary) {
    $statusTone = 'warn';
    $statusLabel = __('尚無站台');
} elseif (empty($summary['enabled'])) {
    $statusTone = 'danger';
    $statusLabel = __('站台離線');
    $statusMessage = __('選取的站台目前已停用。');
} elseif ($isStationDashboard && $connectionState === 'offline') {
    $statusTone = 'danger';
    $statusLabel = __('無法連線');
    $statusMessage = (string)($summary['error'] ?? '') !== '' && (string)($summary['error'] ?? '') !== 'refreshing'
        ? (string)$summary['error']
        : __('子節點快照已超過 150 秒。');
} elseif ((string)($summary['error'] ?? '') !== '' && (string)($summary['error'] ?? '') !== 'refreshing') {
    $statusTone = 'danger';
    $statusLabel = __('站台錯誤');
    $statusMessage = (string)$summary['error'];
} elseif (!$isStationDashboard && !$snapshotAvailable) {
    $statusTone = 'warn';
    $statusLabel = __('尚無監測資料');
    $statusMessage = __('主機監測快照尚未建立。');
} elseif (!$isStationDashboard && empty($summary['fresh'])) {
    $statusTone = 'warn';
    $statusLabel = __('資料已過期');
    $statusMessage = __('監測快照已超過 90 秒。');
} elseif (!$gpuAvailable) {
    $statusTone = 'warn';
    $statusLabel = __('GPU 不可用');
    $statusMessage = (string)($gpu['reason'] ?? __('目前沒有可用的 GPU 監測資料。'));
}

$diskBars = [];
foreach ([
    '/' => $host['disk_root']['used_percent'] ?? null,
    '/DATA' => $host['disk_data']['used_percent'] ?? null,
    'Models' => $storage['models_used_percent'] ?? null,
    'Docker' => $docker['root_used_percent'] ?? null,
] as $label => $value) {
    $applicable = match ($label) {
        '/' => $rootDiskApplicable,
        '/DATA' => $dataDiskApplicable,
        'Docker' => $dockerRootApplicable,
        default => true,
    };
    if ($applicable && is_numeric($value)) {
        $diskBars[] = ['label' => $label, 'value' => (float)$value];
    }
}
$ramParts = [];
foreach ([
    __('已用') => $host['ram_used_mb'] ?? null,
    __('Buff/Cache') => $host['ram_buff_cache_mb'] ?? null,
    __('可用') => $host['ram_available_mb'] ?? null,
] as $label => $value) {
    if ($memoryApplicable && is_numeric($value)) {
        $ramParts[] = ['label' => $label, 'value' => (float)$value];
    }
}
$chartData = [
    'ramApplicable' => $memoryApplicable,
    'vram' => $gpuAvailable ? [
        ['label' => __('已用'), 'value' => $gpuUsed],
        ['label' => __('可用'), 'value' => max(0, $gpuTotal - $gpuUsed)],
    ] : [],
    'gpuTemperatureHistory' => $gpuTemperatureHistory,
    'gpuVramHistory' => $gpuVramHistory,
    'ram' => $ramParts,
    'disk' => $diskBars,
    'services' => [
        ['label' => __('執行中'), 'value' => (int)($serviceCounts['running'] ?? 0)],
        ['label' => __('已停止'), 'value' => (int)($serviceCounts['stopped'] ?? 0)],
        ['label' => __('待處理'), 'value' => (int)($serviceCounts['pending'] ?? 0)],
        ['label' => __('錯誤'), 'value' => (int)($serviceCounts['error'] ?? 0)],
    ],
];
$roleLabel = match ($model['role']) {
    'aggregate' => __('聚合站台'),
    'router' => __('統一入口'),
    'child' => __('子入口節點'),
    default => __('單機站台'),
};

hub_admin_header('控制台', $user);
?>
<link rel="stylesheet" href="../assets/css/admin-dashboard.css?v=<?= rawurlencode($dashboardCssVersion) ?>">

<ol class="crumbs">
    <li><a href="index.php"><?= hub_h(__('首頁')) ?></a></li>
    <li aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg></li>
    <li aria-current="page"><?= hub_h(__('控制台')) ?></li>
</ol>

<div class="pagehead">
    <div>
        <h1 class="pagehead__title"><?= hub_h(__('總覽中控台')) ?></h1>
        <p class="pagehead__desc"><?= hub_h($siteTitle) ?> · <?= hub_h($roleLabel) ?> · <?= hub_h(__('系統資源、服務與工作佇列總覽')) ?></p>
    </div>
    <div class="pagehead__actions">
        <span class="pagehead__stamp"><?= hub_h(__('最後更新')) ?> <span class="num"><?= hub_h((string)($summary['snapshot_at'] ?? '—')) ?></span></span>
        <a class="btn btn--ghost btn--sm" href="index.php<?= $model['active_station_key'] !== '' ? '?station=' . rawurlencode($model['active_station_key']) : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 12a8.5 8.5 0 1 1-2.6-6.1"/><path d="M20.5 4.5V10H15"/></svg>
            <span><?= hub_h(__('重新整理')) ?></span>
        </a>
    </div>
</div>

<?php if ($isStationDashboard && $model['station_tabs'] !== []): ?>
    <nav class="station-tabs" aria-label="<?= hub_h(__('站台')) ?>">
        <?php foreach ($model['station_tabs'] as $station):
            $stationOnline = ($station['connection_state'] ?? 'offline') === 'online';
            $stationConnectionLabel = $stationOnline ? __('可連線') : __('無法連線');
            ?>
            <a class="station-tab<?= $model['active_station_key'] === $station['station_key'] ? ' is-active' : '' ?>"
               href="index.php?station=<?= rawurlencode($station['station_key']) ?>"
               aria-label="<?= hub_h((string)$station['label'] . '：' . $stationConnectionLabel) ?>">
                <span class="station-tab__status station-tab__status--<?= $stationOnline ? 'online' : 'offline' ?>" aria-hidden="true"></span>
                <span><?= hub_h($station['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
<?php endif; ?>

<?php if (!$hasSummary): ?>
    <section class="card emptystate" aria-labelledby="dashboard-empty-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3.5" width="18" height="6" rx="2"/><rect x="3" y="14.5" width="18" height="6" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/></svg>
        <h2 id="dashboard-empty-title"><?= hub_h(__('尚無已配對站台')) ?></h2>
        <p><?= hub_h(__('Router 已啟用，但目前沒有可顯示的站台。請先到 Cluster 管理完成配對或登錄本機節點。')) ?></p>
        <a class="btn btn--primary" href="cluster.php"><?= hub_h(__('前往 Cluster 管理')) ?></a>
    </section>
<?php else: ?>
    <div class="stack">
        <?php if (hub_platform_id() === 'windows'): ?>
            <p class="dashboard-platform-note">3waAIHub Core（Control Plane）<?= hub_h(__('運作中')) ?> · WSL Runtime（Preview）<?= hub_h(__('狀態請以系統環境頁為準')) ?></p>
        <?php endif; ?>
        <section class="card gpubar gpubar--<?= hub_h($statusTone) ?>" aria-labelledby="station-status-title">
            <span class="gpubar__ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><rect x="7" y="10" width="6" height="4" rx="1"/><path d="M17 10v4M7 3v3M12 3v3M17 3v3M7 18v3M12 18v3M17 18v3"/></svg>
            </span>
            <div class="gpubar__body">
                <div class="gpubar__top">
                    <h2 class="gpubar__title" id="station-status-title"><?= hub_h((string)$summary['title']) ?></h2>
                    <?php if ($isStationDashboard): ?><span class="station-connection station-connection--<?= $connectionState === 'online' ? 'online' : 'offline' ?>"><?= hub_h($connectionState === 'online' ? __('可連線') : __('無法連線')) ?></span><?php endif; ?>
                    <span class="badge badge--<?= $statusTone === 'ok' ? 'success' : ($statusTone === 'warn' ? 'warning' : 'danger') ?>">
                        <span class="badge__dot" aria-hidden="true"></span><?= hub_h($statusLabel) ?>
                    </span>
                    <?php if (!empty($cluster['aggregate'])): ?><span class="badge badge--info"><?= hub_h(__('聚合站台')) ?></span><?php endif; ?>
                </div>
                <?php if ($statusMessage !== ''): ?><p class="gpubar__msg"><?= hub_h($statusMessage) ?></p><?php endif; ?>
                <p class="gpubar__hint">
                    <?= hub_h(__('快照')) ?> <?= hub_h((string)($summary['snapshot_at'] ?: '—')) ?>
                    <?php if ((string)($gpu['name'] ?? '') !== ''): ?>
                        · GPU <?= hub_h((string)$gpu['name']) ?>
                    <?php endif; ?>
                    <?php if (!empty($cluster['aggregate'])): ?>
                        · <?= number_format((int)($cluster['children_count'] ?? 0)) ?> <?= hub_h(__('個子節點')) ?>
                    <?php endif; ?>
                </p>
            </div>
        </section>

        <h2 class="dashboard-section-title"><?= hub_h(__('總覽摘要')) ?></h2>
        <div class="grid grid--3">
            <section class="card metric">
                <div class="metric__head">
                    <div><h2 class="metric__label"><?= hub_h(__('VRAM 使用量')) ?></h2><p class="metric__sub"><?= hub_h(__('GPU 記憶體')) ?></p></div>
                    <span class="metric__value<?= $gpuAvailable ? '' : ' metric__value--na' ?>"><?= $gpuAvailable ? hub_h((string)$vramPercent . '%') : 'N/A' ?></span>
                </div>
                <p class="metric__note"><?= $gpuAvailable ? hub_h(hub_admin_dash_value($gpuUsed, ' MB')) . ' / ' . hub_h(hub_admin_dash_value($gpuTotal, ' MB')) : 'N/A' ?></p>
                <div class="meter<?= $gpuAvailable ? '' : ' meter--empty' ?>"><div class="meter__fill" style="width: <?= hub_h((string)$vramPercent) ?>%"></div></div>
                <?php if ($gpuAvailable): ?><div class="chart chart--compact"><canvas id="vramChart" aria-label="<?= hub_h(__('VRAM 使用分布圖')) ?>" role="img"></canvas></div><?php endif; ?>
            </section>
            <section class="card metric">
                <div class="metric__head">
                    <div><h2 class="metric__label"><?= hub_h(__('GPU 使用率')) ?></h2><p class="metric__sub"><?= hub_h(__('目前運算負載')) ?></p></div>
                    <span class="metric__value<?= $gpuUtil === null ? ' metric__value--na' : '' ?>"><?= $gpuUtil === null ? 'N/A' : hub_h((string)round($gpuUtil, 1) . '%') ?></span>
                </div>
                <p class="metric__note"><?= $gpuUtil === null ? 'N/A' : hub_h((string)round($gpuUtil, 1) . '%') ?></p>
                <div class="meter<?= $gpuUtil === null ? ' meter--empty' : '' ?>"><div class="meter__fill" style="width: <?= hub_h((string)max(0, min(100, $gpuUtil ?? 0))) ?>%"></div></div>
            </section>
            <section class="card metric">
                <div class="metric__head">
                    <div><h2 class="metric__label"><?= hub_h(__('溫度')) ?></h2><p class="metric__sub"><?= hub_h(__('GPU 溫度')) ?></p></div>
                    <span class="metric__value<?= $gpuTemperature === null ? ' metric__value--na' : '' ?>"><?= $gpuTemperature === null ? 'N/A' : hub_h((string)round($gpuTemperature, 1) . '°C') ?></span>
                </div>
                <p class="metric__note"><?= hub_h(__('溫度上限以 100°C 顯示')) ?></p>
                <div class="meter<?= $gpuTemperature === null ? ' meter--empty' : '' ?>"><div class="meter__fill" style="width: <?= hub_h((string)max(0, min(100, $gpuTemperature ?? 0))) ?>%"></div></div>
            </section>
        </div>

        <div class="grid grid--2">
            <section class="card card--fill">
                <div class="card__head"><h2 class="card__title"><?= hub_h(__('GPU 溫度（24 小時）')) ?></h2></div>
                <?php if ($gpuTemperatureHistory !== []): ?>
                    <div class="chart"><canvas id="gpuTemperatureChart" aria-label="<?= hub_h(__('GPU 溫度 24 小時趨勢圖')) ?>" aria-describedby="gpu-temperature-history-summary" role="img"></canvas></div>
                    <p class="chart__caption" id="gpu-temperature-history-summary"><?= hub_h($gpuTemperatureHistorySummary) ?></p>
                <?php else: ?>
                    <p class="metric__note"><?= hub_h(__('尚無 GPU 歷史資料。')) ?></p>
                <?php endif; ?>
            </section>
            <section class="card card--fill">
                <div class="card__head"><h2 class="card__title"><?= hub_h(__('GPU VRAM 使用量（24 小時）')) ?></h2></div>
                <?php if ($gpuVramHistory !== []): ?>
                    <div class="chart"><canvas id="gpuVramHistoryChart" aria-label="<?= hub_h(__('GPU VRAM 使用量 24 小時趨勢圖')) ?>" aria-describedby="gpu-vram-history-summary" role="img"></canvas></div>
                    <p class="chart__caption" id="gpu-vram-history-summary"><?= hub_h($gpuVramHistorySummary) ?></p>
                <?php else: ?>
                    <p class="metric__note"><?= hub_h(__('尚無 GPU 歷史資料。')) ?></p>
                <?php endif; ?>
            </section>
        </div>

        <div class="grid grid--4">
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('Pack 數')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['pack_count']) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('服務總數')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['service_count']) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('執行中')) ?></h2><p class="kpi__row"><span class="kpi__value kpi__value--success"><?= number_format((int)($serviceCounts['running'] ?? 0)) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('待處理項')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['queued_jobs']) ?></span></p></section>
        </div>

        <div class="grid grid--4">
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('GPU Lease')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['active_gpu_leases']) ?></span><span class="kpi__unit"><?= hub_h(__('作用中')) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('執行中工作')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['running_jobs']) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('已發佈 Mode')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['published_mode_count']) ?></span></p></section>
            <section class="card kpi"><h2 class="kpi__label"><?= hub_h(__('作用中 Router 路由')) ?></h2><p class="kpi__row"><span class="kpi__value"><?= number_format((int)$summary['active_route_count']) ?></span></p></section>
        </div>

        <?php if ($host !== []): ?>
            <div class="grid grid--2">
                <section class="card card--fill">
                    <div class="card__head"><h2 class="card__title"><?= hub_h(__('主機負載')) ?></h2><p class="card__desc num"><?= hub_h(hub_admin_dash_value($host['load_1'] ?? null)) ?> / <?= hub_h(hub_admin_dash_value($host['load_5'] ?? null)) ?> / <?= hub_h(hub_admin_dash_value($host['load_15'] ?? null)) ?></p></div>
                    <div class="tiles">
                        <div class="tile"><p class="tile__label"><?= hub_h(__('已用')) ?></p><p class="tile__value"><?= hub_h(hub_admin_dash_value($host['ram_used_mb'] ?? null, ' MB')) ?></p></div>
                        <div class="tile"><p class="tile__label">Buff/Cache</p><p class="tile__value"><?= hub_h(hub_admin_dash_value($host['ram_buff_cache_mb'] ?? null, ' MB')) ?></p></div>
                        <div class="tile"><p class="tile__label"><?= hub_h(__('可用')) ?></p><p class="tile__value"><?= hub_h(hub_admin_dash_value($host['ram_available_mb'] ?? null, ' MB')) ?></p></div>
                        <div class="tile"><p class="tile__label"><?= hub_h(__('Swap 已用')) ?></p><p class="tile__value"><?= hub_h(hub_admin_dash_value($host['swap_used_mb'] ?? null, ' MB')) ?></p></div>
                    </div>
                    <p class="metric__note"><?= hub_h(__('記憶體壓力')) ?>：<?= hub_h($memoryPressure === 'not_applicable' ? 'N/A' : $memoryPressure) ?></p>
                    <?php if ($ramParts !== []): ?><div class="chartwrap"><p class="chart__caption"><?= hub_h(__('RAM 使用分布')) ?></p><div class="chart chart--donut"><canvas id="ramChart" aria-label="<?= hub_h(__('RAM 使用分布環圈圖')) ?>" role="img"></canvas></div></div><?php endif; ?>
                </section>
                <section class="card card--fill">
                    <div class="card__head"><h2 class="card__title"><?= hub_h(__('磁碟 / 儲存')) ?></h2></div>
                    <div class="paths">
                        <p class="path"><span class="path__label"><?= hub_h(__('Docker 根目錄')) ?></span><span class="path__value"><?= hub_h((string)($docker['root_dir'] ?? 'N/A')) ?></span></p>
                        <p class="path"><span class="path__label"><?= hub_h(__('模型目錄')) ?></span><span class="path__value"><?= hub_h((string)($storage['models_dir'] ?? 'N/A')) ?></span></p>
                    </div>
                    <div class="paths">
                        <p class="path"><span class="path__label">/ <?= hub_h(__('可用空間')) ?></span><span class="path__value"><?= hub_h(hub_admin_dash_value($host['disk_root']['free_gb'] ?? null, ' GB')) ?></span></p>
                        <p class="path"><span class="path__label"><?= hub_h(__('Docker 根目錄可用')) ?></span><span class="path__value"><?= hub_h(hub_admin_dash_value($docker['root_free_gb'] ?? null, ' GB')) ?></span></p>
                        <p class="path"><span class="path__label"><?= hub_h(__('模型目錄可用')) ?></span><span class="path__value"><?= hub_h(hub_admin_dash_value($storage['models_free_gb'] ?? null, ' GB')) ?></span></p>
                    </div>
                    <?php if ($diskBars !== []): ?><div class="chartwrap"><p class="chart__caption"><?= hub_h(__('磁碟使用率')) ?></p><div class="chart"><canvas id="diskChart" aria-label="<?= hub_h(__('磁碟使用率長條圖')) ?>" role="img"></canvas></div></div><?php endif; ?>
                </section>
            </div>
        <?php elseif (!$snapshotAvailable && !$isStationDashboard): ?>
            <section class="card emptystate">
                <h2><?= hub_h(__('尚未收集 metrics')) ?></h2>
                <p><?= hub_h(__('主機監測快照尚未建立；Dashboard 不會在瀏覽請求中執行主機探測。')) ?></p>
            </section>
        <?php endif; ?>

        <div class="grid grid--2">
            <section class="card card--fill">
                <div class="card__head">
                    <h2 class="card__title"><?= hub_h(__('版本、Pack 與健康狀態')) ?></h2>
                    <span class="badge badge--<?= $healthStatus === 'ok' ? 'success' : 'warning' ?>"><?= hub_h(hub_release_status_label($healthStatus)) ?></span>
                </div>
                <div class="table-wrap">
                    <table class="dashboard-table">
                        <tbody>
                            <tr><th scope="row"><?= hub_h(__('Build ID')) ?></th><td><code><?= hub_h((string)($summary['release']['build_id'] ?? '—')) ?></code></td></tr>
                            <tr><th scope="row"><?= hub_h(__('Commit')) ?></th><td><code><?= hub_h((string)($summary['release']['commit'] ?? '—')) ?></code></td></tr>
                            <tr><th scope="row"><?= hub_h(__('Release 相容性')) ?></th><td><?= hub_h(hub_admin_dash_compatibility($summary['release_compatible'])) ?></td></tr>
                            <tr><th scope="row"><?= hub_h(__('Pack 相容性')) ?></th><td><?= hub_h(hub_admin_dash_compatibility($summary['pack_compatible'])) ?></td></tr>
                            <tr><th scope="row"><?= hub_h(__('健康服務')) ?></th><td><?php if ($healthStatus === 'unknown'): ?><?= hub_h(__('尚無資料')) ?><?php else: ?><?= number_format((int)($health['running_services'] ?? $serviceCounts['running'] ?? 0)) ?> / <?= number_format((int)($health['installed_services'] ?? $summary['service_count'])) ?><?php endif; ?></td></tr>
                            <?php if (!empty($cluster['aggregate'])): ?><tr><th scope="row"><?= hub_h(__('聚合子節點')) ?></th><td><?= number_format((int)($cluster['children_count'] ?? 0)) ?></td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card card--fill">
                <div class="card__head"><h2 class="card__title"><?= hub_h(__('服務狀態')) ?></h2><p class="card__desc"><?= number_format(count($services)) ?> <?= hub_h(__('個服務')) ?></p></div>
                <?php if ($services === []): ?>
                    <div class="emptystate emptystate--compact"><p><?= hub_h(__('目前沒有已安裝或已供應的服務。')) ?></p></div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="dashboard-table">
                            <thead><tr><th><?= hub_h(__('服務')) ?></th><th>Mode</th><th><?= hub_h(__('實際 VRAM')) ?></th><th><?= hub_h(__('狀態')) ?></th></tr></thead>
                            <tbody>
                            <?php foreach ($services as $service): ?>
                                <?php
                                $serviceState = hub_admin_dashboard_service_status_label($service);
                                if ($serviceState === '') {
                                    $serviceState = $healthStatus === 'unknown' ? __('狀態未知') : __('已供應');
                                }
                                ?>
                                <tr>
                                    <td><?= hub_h((string)($service['name'] ?? $service['pack_id'] ?? $service['mode'] ?? '—')) ?></td>
                                    <td><code><?= hub_h((string)($service['mode'] ?? '—')) ?></code></td>
                                    <td><?php if (!empty($service['gpu_vram_measured']) && is_int($service['gpu_vram_used_mb'] ?? null)): ?><?= number_format($service['gpu_vram_used_mb']) . ' MB' ?><?php elseif (($service['gpu_required'] ?? null) === false): ?><?= hub_h(__('CPU')) ?><?php else: ?><?= hub_h(__('尚未取得')) ?><?php endif; ?></td>
                                    <td><?= hub_h($serviceState) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="chartwrap"><div class="chart"><canvas id="serviceChart" aria-label="<?= hub_h(__('服務狀態分布長條圖')) ?>" role="img"></canvas></div></div>
                <?php endif; ?>
            </section>
        </div>

        <?php if (!$isStationDashboard): ?>
            <section class="card">
                <div class="card__head">
                    <h2 class="card__title"><?= hub_h(__('平台能力矩陣')) ?></h2>
                    <a class="btn btn--ghost btn--sm" href="log_explorer.php?tab=runs"><?= hub_h(__('執行歷程')) ?></a>
                </div>
                <div class="grid grid--4 dashboard-runtime-grid">
                    <div><span><?= hub_h(__('Runtime 24h 執行數')) ?></span><strong><?= number_format((int)($runtime['runs_24h'] ?? 0)) ?></strong></div>
                    <div><span><?= hub_h(__('執行中 Runtime')) ?></span><strong><?= number_format((int)($runtime['running'] ?? 0)) ?></strong></div>
                    <div><span><?= hub_h(__('24h 失敗 Runtime')) ?></span><strong><?= number_format((int)($runtime['failed_24h'] ?? 0)) ?></strong></div>
                    <div><span><?= hub_h(__('支援 Job 的 Pack')) ?></span><strong><?= number_format((int)($runtime['job_packs'] ?? 0)) ?></strong></div>
                </div>
                <div class="dashboard-links">
                    <span><?= hub_h(__('API 24h 呼叫數')) ?> <strong><?= number_format((int)($summary['api_calls_24h'] ?? 0)) ?></strong></span>
                    <span><?= hub_h(__('API 24h 失敗數')) ?> <strong><?= number_format((int)($summary['api_failed_24h'] ?? 0)) ?></strong></span>
                    <a href="log_explorer.php?tab=jobs"><?= hub_h(__('最近背景工作')) ?> <?= number_format(count($recentJobs)) ?></a>
                    <a href="marketplace.php?view=services"><?= hub_h(__('服務管理')) ?></a>
                    <a href="log_explorer.php?tab=runs"><?= hub_h(__('資源取樣')) ?></a>
                </div>
            </section>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- admin-dashboard.js checks metric.ramApplicable && ramChart before drawing host RAM. -->
<script src="../assets/js/vendor/chart.umd.js"></script>
<script id="dashboard-data" type="application/json"><?= hub_json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<script src="../assets/js/admin-dashboard.js?v=<?= rawurlencode($dashboardJsVersion) ?>"></script>
<?php hub_admin_footer(); ?>
