<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_login($db);
$message = '';

function hub_services_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function hub_services_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function hub_services_runtime_level(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    return (string)($pack['manifest']['runtime_level'] ?? '');
}

function hub_services_endpoint_label(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $gateway = is_array($pack['manifest']['gateway'] ?? null) ? $pack['manifest']['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);
    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

function hub_services_api_url(array $service): string
{
    return '../api.php?mode=' . rawurlencode((string)$service['mode']);
}

function hub_services_container_state(array $service): array
{
    $status = (string)($service['status'] ?? '');
    if ($status === 'running') {
        return ['label' => '執行中', 'badge' => '容器執行中', 'class' => 'hub-badge-ok', 'text_class' => 'ok'];
    }
    if ($status === 'stopped') {
        return ['label' => '已停止', 'badge' => '容器已停止', 'class' => 'hub-badge-muted', 'text_class' => 'bad'];
    }

    return ['label' => '未知', 'badge' => '容器未知', 'class' => 'hub-badge-muted', 'text_class' => 'bad'];
}

function hub_services_health_state(?array $healthJob): array
{
    if (!$healthJob) {
        return ['label' => '未檢查', 'badge' => '健康未檢查', 'class' => 'hub-badge-muted', 'text_class' => ''];
    }
    $status = (string)$healthJob['status'];
    if ($status === 'success') {
        return ['label' => '正常', 'badge' => '健康正常', 'class' => 'hub-badge-ok', 'text_class' => 'ok'];
    }
    if (in_array($status, ['queued', 'running'], true)) {
        return ['label' => '檢查中', 'badge' => '健康檢查中', 'class' => 'hub-badge-warn', 'text_class' => ''];
    }

    return ['label' => '異常', 'badge' => '健康異常', 'class' => 'hub-badge-bad', 'text_class' => 'bad'];
}

function hub_services_config_state(array $service): array
{
    return (int)($service['restart_required'] ?? 0) === 1
        ? ['label' => '需重啟', 'class' => 'hub-badge-warn']
        : ['label' => '已套用', 'class' => 'hub-badge-ok'];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $isAjax = hub_services_is_ajax();
    $service = hub_get_service($db, (int)($_POST['service_id'] ?? 0));
    $action = (string)($_POST['action'] ?? '');
    $actionMap = [
        'build' => 'service_build',
        'start' => 'service_start',
        'stop' => 'service_stop',
        'restart' => 'service_restart',
        'rebuild' => 'service_rebuild',
        'refresh' => 'service_health_check',
    ];
    if (!$service || !isset($actionMap[$action])) {
        if ($isAjax) {
            hub_services_json(400, ['ok' => false, 'error' => '無效的服務操作。']);
        }
    } else {
        $jobId = hub_enqueue_command_job(
            $db,
            $actionMap[$action],
            (int)$service['id'],
            ['reason' => 'admin_click'],
            (int)$user['id'],
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        $message = '已排入背景工作 #' . $jobId . '，請等待 command worker 執行。';
        if ($isAjax) {
            $job = hub_get_command_job($db, $jobId);
            hub_services_json(200, [
                'ok' => true,
                'message' => $message,
                'job' => [
                    'id' => $jobId,
                    'action' => $actionMap[$action],
                    'action_label' => hub_command_action_label($actionMap[$action]),
                    'service_id' => (int)$service['id'],
                    'service_name' => $service['name'],
                    'status' => $job['status'] ?? 'queued',
                    'status_label' => hub_status_label($job['status'] ?? 'queued'),
                    'status_class' => hub_status_class($job['status'] ?? 'queued'),
                    'progress' => (int)($job['progress'] ?? 0),
                    'stage' => (string)($job['stage'] ?? ''),
                    'current_message' => (string)($job['current_message'] ?? ''),
                    'created_at' => $job['created_at'] ?? hub_now(),
                    'updated_at' => $job['updated_at'] ?? hub_now(),
                ],
            ]);
        }
    }
}

$services = hub_list_services($db);
$jobs = hub_list_command_jobs($db, 50);
$queuedJobCount = count(array_filter($jobs, static fn (array $job): bool => $job['status'] === 'queued'));
$summary = [
    'total' => count($services),
    'running' => count(array_filter($services, static fn (array $service): bool => (string)$service['status'] === 'running')),
    'stopped' => count(array_filter($services, static fn (array $service): bool => (string)$service['status'] !== 'running')),
    'disabled' => count(array_filter($services, static fn (array $service): bool => (int)$service['enabled'] !== 1)),
    'running_jobs' => count(array_filter($jobs, static fn (array $job): bool => in_array((string)$job['status'], ['queued', 'running'], true))),
    'failed_jobs' => count(array_filter($jobs, static fn (array $job): bool => (string)$job['status'] === 'failed')),
];
$activeJobsByService = [];
$lastJobsByService = [];
$lastHealthJobsByService = [];
foreach ($jobs as $job) {
    $serviceId = (int)($job['service_id'] ?? 0);
    if ($serviceId > 0 && !isset($lastJobsByService[$serviceId])) {
        $lastJobsByService[$serviceId] = $job;
    }
    if ($serviceId > 0 && (string)$job['action'] === 'service_health_check' && !isset($lastHealthJobsByService[$serviceId])) {
        $lastHealthJobsByService[$serviceId] = $job;
    }
    if ($serviceId > 0 && in_array((string)$job['status'], ['queued', 'running'], true) && !isset($activeJobsByService[$serviceId])) {
        $activeJobsByService[$serviceId] = $job;
    }
}

hub_admin_header('服務管理', $user);
?>
<div id="service-message" class="notice"<?= $message === '' ? ' style="display:none"' : '' ?>><?= hub_h($message) ?></div>
<section class="panel">
    <h1>服務管理</h1>
    <p class="muted">服務操作會先排入背景工作，由 command worker 實際執行 Docker 指令。</p>
    <?php if ($queuedJobCount > 0): ?>
        <div class="notice">
            目前有 <?= (int)$queuedJobCount ?> 筆背景工作排隊中。可先在主機執行：
            <pre class="inline-pre">sudo php <?= hub_h(HUB_ROOT . '/scripts/command_worker.php') ?> --limit=5</pre>
            <p class="muted">長期建議用具 Docker 權限的本機帳號常駐執行 worker，不要把 www-data 加進 docker 群組。</p>
        </div>
    <?php endif; ?>
</section>
<section class="panel">
    <div class="hub-card-grid">
        <article class="hub-card"><h2>全部服務</h2><strong><?= (int)$summary['total'] ?></strong></article>
        <article class="hub-card"><h2>執行中</h2><strong><?= (int)$summary['running'] ?></strong></article>
        <article class="hub-card"><h2>已停止</h2><strong><?= (int)$summary['stopped'] ?></strong></article>
        <article class="hub-card"><h2>已停用</h2><strong><?= (int)$summary['disabled'] ?></strong></article>
        <article class="hub-card"><h2>背景工作執行中</h2><strong><?= (int)$summary['running_jobs'] ?></strong></article>
        <article class="hub-card"><h2>最近失敗工作</h2><strong><?= (int)$summary['failed_jobs'] ?></strong></article>
    </div>
    <p><a class="button" href="log_explorer.php?tab=jobs">查看背景工作</a></p>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2>服務列表</h2>
        <span class="muted">service_key / pack_id / mode / runtime_level / endpoint / execution_type 保留英文。</span>
    </div>
    <div class="hub-card-grid">
        <?php foreach ($services as $service): ?>
            <?php
            $serviceId = (int)$service['id'];
            $activeJob = $activeJobsByService[$serviceId] ?? null;
            $lastJob = $lastJobsByService[$serviceId] ?? null;
            $lastHealthJob = $lastHealthJobsByService[$serviceId] ?? null;
            $apiUrl = hub_services_api_url($service);
            $runtimeLevel = hub_services_runtime_level($service);
            $endpoint = hub_services_endpoint_label($service);
            $containerState = hub_services_container_state($service);
            $healthState = hub_services_health_state($lastHealthJob);
            $configState = hub_services_config_state($service);
            ?>
            <article class="hub-card service-card" data-service-row-id="<?= $serviceId ?>">
                <div class="hub-section-title">
                    <div>
                        <h2><?= hub_h($service['name']) ?></h2>
                        <p class="muted">service_key: <code><?= hub_h((string)($service['service_key'] ?? '')) ?></code></p>
                    </div>
                    <span data-service-status class="<?= hub_h($containerState['text_class']) ?>">
                        容器：<span data-service-status-label><?= hub_h($containerState['label']) ?></span>
                    </span>
                </div>
                <p>
                    <span class="hub-badge <?= hub_h($containerState['class']) ?>">服務狀態：<?= hub_h($containerState['label']) ?></span>
                    <span class="hub-badge <?= (int)$service['enabled'] === 1 ? 'hub-badge-ok' : 'hub-badge-muted' ?>">啟用狀態：<?= (int)$service['enabled'] === 1 ? '已啟用' : '已停用' ?></span>
                    <span class="hub-badge <?= hub_h($containerState['class']) ?>">容器狀態：<?= hub_h($containerState['badge']) ?></span>
                    <span data-service-health class="hub-badge <?= hub_h($healthState['class']) ?>">健康狀態：<span data-service-health-label><?= hub_h($healthState['badge']) ?></span></span>
                    <span class="hub-badge <?= hub_h($configState['class']) ?>">設定狀態：<?= hub_h($configState['label']) ?></span>
                    <?php if ($activeJob): ?><span class="hub-badge hub-badge-warn">建置中</span><?php endif; ?>
                </p>
                <div class="hub-meta">
                    <div class="hub-meta-label">pack_id</div>
                    <div class="hub-meta-value"><code><?= hub_h((string)($service['pack_id'] ?? '')) ?></code></div>
                    <div class="hub-meta-label">mode</div>
                    <div class="hub-meta-value"><code><?= hub_h($service['mode']) ?></code></div>
                    <div class="hub-meta-label">runtime_level</div>
                    <div class="hub-meta-value"><code><?= hub_h($runtimeLevel) ?></code></div>
                    <div class="hub-meta-label">endpoint</div>
                    <div class="hub-meta-value"><code><?= hub_h($endpoint) ?></code></div>
                    <div class="hub-meta-label">execution_type</div>
                    <div class="hub-meta-value"><code><?= hub_h((string)($service['execution_type'] ?? '')) ?></code></div>
                    <div class="hub-meta-label">local port</div>
                    <div class="hub-meta-value"><code><?= hub_h((string)$service['local_port']) ?></code> / <?= hub_h($service['port_mode']) ?></div>
                    <div class="hub-meta-label">environment</div>
                    <div class="hub-meta-value"><code><?= hub_h((string)($service['environment'] ?? '')) ?></code></div>
                    <div class="hub-meta-label">hot reload</div>
                    <div class="hub-meta-value"><?= (int)$service['hot_reload'] === 1 ? '開啟' : '關閉' ?></div>
                    <div class="hub-meta-label">config</div>
                    <div class="hub-meta-value"><?= (int)($service['config_dirty'] ?? 0) === 1 ? '<span class="bad">config dirty</span>' : '<span class="ok">config clean</span>' ?></div>
                    <div class="hub-meta-label">API mode</div>
                    <div class="hub-meta-value"><code><?= hub_h($service['mode']) ?></code></div>
                    <div class="hub-meta-label">API 入口</div>
                    <div class="hub-meta-value"><code id="service-api-url-<?= $serviceId ?>"><?= hub_h($apiUrl) ?></code></div>
                    <div class="hub-meta-label">最後工作</div>
                    <div class="hub-meta-value" data-service-last-job>
                        <?php if ($lastJob): ?>
                            <?= hub_h(hub_command_action_label((string)$lastJob['action'])) ?>
                            <code><?= hub_h((string)$lastJob['action']) ?></code>
                            <span data-service-last-job-status class="<?= hub_h(hub_command_status_class((string)$lastJob['status'])) ?>"><?= hub_h(hub_command_status_label((string)$lastJob['status'])) ?></span>
                            <span class="muted"><?= hub_h((string)($lastJob['updated_at'] ?? $lastJob['created_at'] ?? '')) ?></span>
                        <?php else: ?>
                            <span class="muted">尚無背景工作</span>
                        <?php endif; ?>
                    </div>
                </div>
                <form class="service-action-form" method="post" data-service-refresh-form="<?= $serviceId ?>">
                    <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                    <input type="hidden" name="service_id" value="<?= $serviceId ?>">
                    <div class="hub-actions">
                        <button class="primary" name="action" value="start" type="submit">啟動</button>
                        <button class="danger" name="action" value="stop" type="submit">停止</button>
                        <button name="action" value="restart" type="submit">重啟</button>
                        <button name="action" value="build" type="submit">建置</button>
                        <button name="action" value="rebuild" type="submit">重新建置</button>
                        <button name="action" value="refresh" type="submit">刷新狀態 / 健康檢查</button>
                    </div>
                </form>
                <div class="hub-actions">
                    <a class="button" href="service_settings.php?service_id=<?= $serviceId ?>">設定</a>
                    <a class="button" href="service_logs.php?id=<?= $serviceId ?>">服務記錄</a>
                    <a class="button" href="log_explorer.php?service_id=<?= $serviceId ?>">API 記錄</a>
                    <a class="button" href="log_explorer.php?tab=jobs&amp;service_id=<?= $serviceId ?>">查看此服務工作</a>
                    <a class="button" href="benchmarks.php">Benchmark</a>
                    <a class="button" href="playground.php?mode=<?= urlencode((string)$service['mode']) ?>">到 API 測試場</a>
                    <button type="button" data-copy-target="service-api-url-<?= $serviceId ?>">複製 API URL</button>
                </div>
                <details>
                    <summary>進階操作</summary>
                    <p class="muted">舊版 IP 白名單已由 API 會員與 Token 權限取代，僅保留相容用途。</p>
                    <a class="button" href="service_whitelist.php?service_id=<?= $serviceId ?>">舊版 IP 白名單</a>
                </details>
                <div class="service-job" data-service-id="<?= $serviceId ?>" data-job-id="<?= $activeJob ? (int)$activeJob['id'] : '' ?>"<?= $activeJob ? '' : ' style="display:none"' ?>>
                    <div class="job-progress"><span style="width: <?= $activeJob ? (int)$activeJob['progress'] : 0 ?>%"></span></div>
                    <div class="muted job-meta">
                        #<span class="job-id"><?= $activeJob ? (int)$activeJob['id'] : '' ?></span>
                        <span class="job-progress-text"><?= $activeJob ? (int)$activeJob['progress'] : 0 ?></span>%
                        <code class="job-stage"><?= hub_h((string)($activeJob['stage'] ?? '')) ?></code>
                        <span class="job-message"><?= hub_h((string)($activeJob['current_message'] ?? '')) ?></span>
                    </div>
                    <pre class="job-tail"></pre>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<script src="../assets/js/jquery.min.js"></script>
<script>
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget || '');
        if (!target || !navigator.clipboard) {
            return;
        }
        await navigator.clipboard.writeText(target.textContent || '');
    });
});
</script>
<script src="../assets/js/services.js"></script>
<?php hub_admin_footer(); ?>
