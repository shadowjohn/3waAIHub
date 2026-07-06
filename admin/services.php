<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $isAjax = hub_services_is_ajax();
    $service = hub_get_service($db, (int)($_POST['service_id'] ?? 0));
    $action = (string)($_POST['action'] ?? '');
    $actionMap = [
        'start' => 'service_start',
        'stop' => 'service_stop',
        'restart' => 'service_restart',
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
                    'service_name' => $service['name'],
                    'status' => $job['status'] ?? 'queued',
                    'status_label' => hub_status_label($job['status'] ?? 'queued'),
                    'status_class' => hub_status_class($job['status'] ?? 'queued'),
                    'created_at' => $job['created_at'] ?? hub_now(),
                ],
            ]);
        }
    }
}

$services = hub_list_services($db);
$jobs = hub_list_command_jobs($db, 20);
$queuedJobCount = count(array_filter($jobs, static fn (array $job): bool => $job['status'] === 'queued'));

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
    <h2>服務列表</h2>
    <table>
        <tr>
            <th>名稱</th>
            <th>Service Key</th>
            <th>Pack</th>
            <th>模式</th>
            <th>類型</th>
            <th>狀態</th>
            <th>啟用</th>
            <th>本機 Port</th>
            <th>熱更新</th>
            <th>API 入口</th>
            <th>操作</th>
        </tr>
        <?php foreach ($services as $service): ?>
            <tr>
                <td><?= hub_h($service['name']) ?></td>
                <td><code><?= hub_h((string)($service['service_key'] ?? '')) ?></code></td>
                <td><?= hub_h((string)($service['pack_id'] ?? '')) ?></td>
                <td><code><?= hub_h($service['mode']) ?></code></td>
                <td><?= hub_h($service['type']) ?></td>
                <td class="<?= hub_status_class($service['status']) ?>"><?= hub_h(hub_status_label($service['status'])) ?></td>
                <td><?= (int)$service['enabled'] === 1 ? '是' : '否' ?></td>
                <td><?= hub_h((string)$service['local_port']) ?> / <?= hub_h($service['port_mode']) ?></td>
                <td><?= (int)$service['hot_reload'] === 1 ? '開發模式' : '關閉' ?></td>
                <td><code><?= hub_h('../api.php?mode=' . $service['mode']) ?></code></td>
                <td class="actions">
                    <form class="service-action-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                        <button class="primary" name="action" value="start" type="submit">啟動</button>
                        <button class="danger" name="action" value="stop" type="submit">停用</button>
                        <button name="action" value="restart" type="submit">重啟</button>
                        <button name="action" value="refresh" type="submit">刷新</button>
                        <a class="button" href="service_logs.php?id=<?= (int)$service['id'] ?>">Log</a>
                        <a class="button" href="service_whitelist.php?service_id=<?= (int)$service['id'] ?>">Whitelist</a>
                        <a class="button" href="log_explorer.php?service_id=<?= (int)$service['id'] ?>">Access Logs</a>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<section class="panel">
    <h2>近期背景工作</h2>
    <table>
        <thead>
            <tr><th>ID</th><th>動作</th><th>服務</th><th>狀態</th><th>Exit</th><th>建立時間</th><th>錯誤</th></tr>
        </thead>
        <tbody id="command-job-rows">
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td>#<?= (int)$job['id'] ?></td>
                <td><code><?= hub_h($job['action']) ?></code></td>
                <td><?= hub_h($job['service_name'] ?? '') ?></td>
                <td class="<?= hub_status_class($job['status']) ?>"><?= hub_h(hub_status_label($job['status'])) ?></td>
                <td><?= $job['exit_code'] === null ? '' : (int)$job['exit_code'] ?></td>
                <td><?= hub_h($job['created_at']) ?></td>
                <td><?= hub_h($job['error_message']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<script src="../assets/js/jquery.min.js"></script>
<script src="../assets/js/services.js"></script>
<?php hub_admin_footer(); ?>
