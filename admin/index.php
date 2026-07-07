<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
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

function hub_dash_pending_items(array $metrics): array
{
    $counts = $metrics['counts'] ?? [];
    $items = [];
    if ((int)($counts['queued_tasks'] ?? 0) > 0) {
        $items[] = '待處理 tasks：' . (int)$counts['queued_tasks'];
    }
    if ((int)($counts['running_tasks'] ?? 0) > 0) {
        $items[] = '執行中 tasks：' . (int)$counts['running_tasks'];
    }
    if ((int)($counts['failed_tasks'] ?? 0) > 0) {
        $items[] = '失敗 tasks：' . (int)$counts['failed_tasks'];
    }
    if ((int)($counts['queued_command_jobs'] ?? 0) > 0) {
        $items[] = 'Command jobs 排隊中：' . (int)$counts['queued_command_jobs'];
    }
    if ((int)($counts['not_ready_services'] ?? 0) > 0) {
        $items[] = 'Runtime pending services：' . (int)$counts['not_ready_services'];
    }
    if (($metrics['docker']['warning'] ?? '') !== '') {
        $items[] = 'Docker root warning：' . $metrics['docker']['warning'];
    }
    if (($metrics['host']['memory_pressure'] ?? 'ok') !== 'ok') {
        $items[] = 'Memory pressure：MemAvailable=' . hub_dash_value($metrics['host']['ram_available_percent'] ?? null, '%')
            . ' vmstat si/so=' . hub_dash_value($metrics['host']['vmstat_si'] ?? null) . '/'
            . hub_dash_value($metrics['host']['vmstat_so'] ?? null);
    }

    return $items ?: ['目前沒有明顯待處理項。'];
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
    .dash-memory { display: grid; gap: 8px; grid-template-columns: repeat(4, 1fr); margin: 12px 0; }
    .dash-memory div { border: 1px solid var(--line); border-radius: 8px; padding: 10px; }
    .dash-memory strong { display: block; font-size: 13px; color: var(--muted); }
    .dash-list { margin: 0; padding-left: 20px; }
    @media (max-width: 900px) { .dash-card { grid-column: span 12; } .dash-memory { grid-template-columns: repeat(2, 1fr); } }
</style>
<section class="panel">
    <h1><?= hub_h($siteTitle) ?> 總覽中控台</h1>
    <p class="muted"><?= hub_h($siteSubtitle) ?>。Dashboard 只讀 SQLite 最新 metrics snapshot；主機探測請由 CLI / cron 執行。</p>
</section>

<section class="panel">
    <div class="hub-section-title">
        <h2>Dashboard summary cards</h2>
        <span class="muted">最近 24 小時與目前服務狀態</span>
    </div>
    <div class="hub-card-grid">
        <article class="hub-card"><h3>服務總數</h3><div class="dash-number"><?= (int)$control['services_total'] ?></div></article>
        <article class="hub-card"><h3>執行中</h3><div class="dash-number ok"><?= (int)$control['services_running'] ?></div><p class="muted">Services running</p></article>
        <article class="hub-card"><h3>健康正常</h3><div class="dash-number ok"><?= (int)$control['services_health_ok'] ?></div></article>
        <article class="hub-card"><h3>健康異常 / 未檢查</h3><div class="dash-number bad"><?= (int)$control['services_health_attention'] ?></div></article>
        <article class="hub-card"><h3>已停止</h3><div class="dash-number"><?= (int)$control['services_stopped'] ?></div><p class="muted">Services stopped</p></article>
        <article class="hub-card"><h3>已停用</h3><div class="dash-number bad"><?= (int)$control['services_disabled'] ?></div><p class="muted">Services disabled</p></article>
        <article class="hub-card"><h3>L5 Pack 數</h3><div class="dash-number"><?= (int)$control['l5_pack_count'] ?></div></article>
        <article class="hub-card"><h3>API 24h 呼叫數</h3><div class="dash-number"><?= (int)$control['api_calls_24h'] ?></div><p class="muted">API calls last 24h</p></article>
        <article class="hub-card"><h3>API 24h 失敗數</h3><div class="dash-number bad"><?= (int)$control['api_failed_24h'] ?></div><p class="muted">Failed API calls last 24h</p></article>
        <article class="hub-card"><h3>背景工作執行中</h3><div class="dash-number"><?= (int)$control['running_jobs'] ?></div></article>
        <article class="hub-card"><h3>最近失敗工作</h3><div class="dash-number bad"><?= (int)$control['failed_jobs'] ?></div></article>
        <article class="hub-card"><h3>Pack readiness</h3><div class="dash-number"><?= (int)$control['pack_readiness_ready'] ?>/<?= (int)$control['pack_readiness_total'] ?></div></article>
        <article class="hub-card"><h3>Model storage usage</h3><div class="dash-number"><?= hub_h((string)$control['models_used_percent']) ?>%</div><p class="muted">Free / Total <?= hub_h((string)$control['models_free']) ?> / <?= hub_h((string)$control['models_total']) ?></p></article>
    </div>
    <div class="hub-actions">
        <a class="button" href="services.php">服務管理</a>
        <a class="button" href="playground.php">API 測試場</a>
        <a class="button" href="packs.php">HubPack 套件</a>
        <a class="button" href="models.php">模型倉庫</a>
        <a class="button" href="api_members.php">API 金鑰</a>
        <a class="button" href="log_explorer.php">Log Explorer</a>
    </div>
</section>

<section class="dash-grid">
    <div class="dash-card dash-span-6">
        <h3>Recent command jobs</h3>
        <?php if ($control['recent_jobs'] === []): ?>
            <p class="muted">尚無背景工作。</p>
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
        <h3>最近失敗</h3>
        <?php if ($control['recent_failed_api'] === []): ?>
            <p class="muted">最近 24 小時沒有 API 失敗紀錄。</p>
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
        <h2>尚未收集 metrics</h2>
        <p>請先在主機執行：</p>
        <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/collect_host_metrics.php') ?></pre>
    </section>
<?php else: ?>
    <?php
    $gpu = $metrics['gpu'] ?? [];
    $host = $metrics['host'] ?? [];
    $docker = $metrics['docker'] ?? [];
    $storage = $metrics['storage'] ?? [];
    $counts = $metrics['counts'] ?? [];
    $gpuName = !empty($gpu['available']) ? (string)$gpu['name'] : 'GPU unavailable';
    $vramPercent = !empty($gpu['memory_total_mb']) ? round(((float)$gpu['memory_used_mb'] / (float)$gpu['memory_total_mb']) * 100, 1) : 0;
    $chartData = [
        'vramPercent' => $vramPercent,
        'gpuUtil' => hub_dash_percent($gpu['util_percent'] ?? 0),
        'gpuTemp' => hub_dash_percent($gpu['temperature_c'] ?? 0),
        'ramPercent' => hub_dash_percent($host['ram_used_percent'] ?? 0),
        'ramParts' => [
            ['name' => 'Used', 'value' => (float)($host['ram_used_mb'] ?? 0)],
            ['name' => 'Buff/Cache', 'value' => (float)($host['ram_buff_cache_mb'] ?? 0)],
            ['name' => 'Available', 'value' => (float)($host['ram_available_mb'] ?? 0)],
        ],
        'diskBars' => [
            ['name' => '/', 'value' => hub_dash_percent($host['disk_root']['used_percent'] ?? 0)],
            ['name' => '/DATA', 'value' => hub_dash_percent($host['disk_data']['used_percent'] ?? 0)],
            ['name' => 'Models', 'value' => hub_dash_percent($storage['models_used_percent'] ?? 0)],
            ['name' => 'Docker', 'value' => hub_dash_percent($docker['root_used_percent'] ?? 0)],
        ],
        'serviceBars' => [
            ['name' => 'Running', 'value' => (int)($counts['running_services'] ?? 0)],
            ['name' => 'Stopped', 'value' => (int)($counts['stopped_services'] ?? 0)],
            ['name' => 'Pending', 'value' => (int)($counts['not_ready_services'] ?? 0)],
            ['name' => 'Error', 'value' => (int)($counts['error_services'] ?? 0)],
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
                    VRAM <?= hub_h(hub_dash_value($gpu['memory_used_mb'] ?? null, ' MB')) ?> /
                    <?= hub_h(hub_dash_value($gpu['memory_total_mb'] ?? null, ' MB')) ?>　
                    Driver <?= hub_h((string)($gpu['driver_version'] ?? '')) ?>　
                    CUDA <?= hub_h((string)($gpu['cuda_version'] ?? '')) ?>
                </p>
            <?php endif; ?>
        </div>
        <div class="dash-card dash-span-4"><div id="vramChart" class="dash-chart"></div></div>
        <div class="dash-card dash-span-4"><div id="gpuChart" class="dash-chart"></div></div>
        <div class="dash-card dash-span-4"><div id="tempChart" class="dash-chart"></div></div>

        <div class="dash-card dash-span-3"><h3>Packs</h3><div class="dash-number"><?= (int)($counts['packs'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3>Services</h3><div class="dash-number"><?= (int)($counts['services'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3>Running</h3><div class="dash-number ok"><?= (int)($counts['running_services'] ?? 0) ?></div></div>
        <div class="dash-card dash-span-3"><h3>Pending</h3><div class="dash-number bad"><?= (int)($counts['not_ready_services'] ?? 0) ?></div></div>

        <div class="dash-card dash-span-6">
            <h3>Host</h3>
            <p class="muted">
                Load <?= hub_h(hub_dash_value($host['load_1'] ?? null)) ?> /
                <?= hub_h(hub_dash_value($host['load_5'] ?? null)) ?> /
                <?= hub_h(hub_dash_value($host['load_15'] ?? null)) ?>
            </p>
            <div class="dash-memory">
                <div><strong>Used</strong><?= hub_h(hub_dash_value($host['ram_used_mb'] ?? null, ' MB')) ?></div>
                <div><strong>BuffCache</strong><?= hub_h(hub_dash_value($host['ram_buff_cache_mb'] ?? null, ' MB')) ?></div>
                <div><strong>Available</strong><?= hub_h(hub_dash_value($host['ram_available_mb'] ?? null, ' MB')) ?> / <?= hub_h(hub_dash_value($host['ram_available_percent'] ?? null, '%')) ?></div>
                <div><strong>SwapUsed</strong><?= hub_h(hub_dash_value($host['swap_used_mb'] ?? null, ' MB')) ?></div>
            </div>
            <p class="muted">Memory pressure: <span class="<?= ($host['memory_pressure'] ?? 'ok') === 'ok' ? 'ok' : 'bad' ?>"><?= hub_h((string)($host['memory_pressure'] ?? 'ok')) ?></span>　vmstat si/so: <?= hub_h(hub_dash_value($host['vmstat_si'] ?? null)) ?> / <?= hub_h(hub_dash_value($host['vmstat_so'] ?? null)) ?></p>
            <div id="ramChart" class="dash-chart"></div>
        </div>
        <div class="dash-card dash-span-6">
            <h3>Disk / Storage</h3>
            <p class="muted">
                Docker Root: <?= hub_h((string)($docker['root_dir'] ?? 'N/A')) ?>　
                Models: <?= hub_h((string)($storage['models_dir'] ?? 'N/A')) ?>
            </p>
            <p class="muted">
                / disk free: <?= hub_h(hub_dash_gb_value($host['disk_root']['free_gb'] ?? null)) ?> /
                <?= hub_h(hub_dash_gb_value($host['disk_root']['total_gb'] ?? null)) ?>　
                Docker root free: <?= hub_h(hub_dash_gb_value($docker['root_free_gb'] ?? null)) ?>　
                Models Root free: <?= hub_h(hub_dash_gb_value($storage['models_free_gb'] ?? null)) ?> /
                <?= hub_h(hub_dash_gb_value($storage['models_total_gb'] ?? null)) ?>
            </p>
            <?php if (!empty($docker['reason'])): ?><p class="bad">Docker daemon 目前不可用，請查看環境診斷。</p><?php endif; ?>
            <div id="diskChart" class="dash-chart"></div>
        </div>

        <div class="dash-card dash-span-6"><div id="serviceChart" class="dash-chart"></div></div>
        <div class="dash-card dash-span-6">
            <h3>待處理項</h3>
            <ul class="dash-list">
                <?php foreach (hub_dash_pending_items($metrics) as $item): ?>
                    <li><?= hub_h($item) ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="muted">最新 snapshot：<?= hub_h((string)$snapshot['created_at']) ?></p>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5/dist/echarts.min.js"></script>
    <script>
    const metric = <?= json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    function gauge(id, title, value, max = 100) {
        if (!window.echarts) return;
        echarts.init(document.getElementById(id)).setOption({
            title: { text: title, left: 'center', top: 8, textStyle: { fontSize: 14 } },
            series: [{ type: 'gauge', min: 0, max, progress: { show: true }, detail: { formatter: '{value}%' }, data: [{ value }] }]
        });
    }
    gauge('vramChart', 'VRAM', metric.vramPercent);
    gauge('gpuChart', 'GPU Util', metric.gpuUtil);
    gauge('tempChart', 'Temp C', metric.gpuTemp, 100);
    if (window.echarts) {
        echarts.init(document.getElementById('ramChart')).setOption({
            title: { text: 'RAM by MemAvailable', left: 'center', top: 8, textStyle: { fontSize: 14 } },
            tooltip: { trigger: 'item' },
            series: [{ type: 'pie', radius: ['45%', '70%'], data: metric.ramParts }]
        });
        echarts.init(document.getElementById('diskChart')).setOption({
            title: { text: 'Disk Used %', left: 'center', top: 8, textStyle: { fontSize: 14 } },
            xAxis: { type: 'category', data: metric.diskBars.map(x => x.name) },
            yAxis: { type: 'value', max: 100 },
            series: [{ type: 'bar', data: metric.diskBars.map(x => x.value) }]
        });
        echarts.init(document.getElementById('serviceChart')).setOption({
            title: { text: 'Service Status', left: 'center', top: 8, textStyle: { fontSize: 14 } },
            xAxis: { type: 'category', data: metric.serviceBars.map(x => x.name) },
            yAxis: { type: 'value' },
            series: [{ type: 'bar', data: metric.serviceBars.map(x => x.value) }]
        });
    }
    </script>
<?php endif; ?>
<?php hub_admin_footer(); ?>
