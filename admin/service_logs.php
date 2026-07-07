<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$service = hub_get_service($db, (int)($_GET['id'] ?? 0));
$message = '';
if (!$service) {
    http_response_code(404);
    exit('找不到服務');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    hub_check_csrf();
    $jobId = hub_enqueue_command_job(
        $db,
        'service_logs_collect',
        (int)$service['id'],
        ['reason' => 'admin_log_view'],
        (int)$user['id'],
        $_SERVER['REMOTE_ADDR'] ?? null
    );
    $message = '已排程讀取 Docker log #' . $jobId . '。';
}

$logs = hub_list_service_logs($db, (int)$service['id'], 100);
$jobs = hub_list_command_jobs($db, 10);

hub_admin_header('服務 Log', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<section class="panel">
    <h1><?= hub_h($service['name']) ?> Log</h1>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <button type="submit">排程讀取 Docker log</button>
        <a class="button" href="services.php">返回</a>
    </form>
</section>
<section class="panel">
    <h2>近期背景工作</h2>
    <table>
        <tr><th>ID</th><th>動作</th><th>狀態</th><th>Exit</th><th>建立時間</th></tr>
        <?php foreach ($jobs as $job): ?>
            <tr>
                <td>#<?= (int)$job['id'] ?></td>
                <td><code><?= hub_h($job['action']) ?></code></td>
                <td class="<?= hub_status_class($job['status']) ?>"><?= hub_h(hub_status_label($job['status'])) ?></td>
                <td><?= $job['exit_code'] === null ? '' : (int)$job['exit_code'] ?></td>
                <td><?= hub_h($job['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php foreach ($logs as $log): ?>
    <section class="panel">
        <strong><?= hub_h($log['created_at']) ?> / <?= hub_h($log['action']) ?> / exit <?= (int)$log['exit_code'] ?></strong>
        <pre><?= hub_h($log['output']) ?></pre>
    </section>
<?php endforeach; ?>
<?php if (!$logs): ?>
    <section class="panel muted">尚無 log。</section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
