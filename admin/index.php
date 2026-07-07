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
    <h1><?= hub_h($siteTitle) ?> 控制台</h1>
    <p class="muted"><?= hub_h($siteSubtitle) ?>。Dashboard 只讀 SQLite 最新 metrics snapshot；主機探測請由 CLI / cron 執行。</p>
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
