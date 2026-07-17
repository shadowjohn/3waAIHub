<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_runtime.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);

$filters = [
    'pack_id' => trim((string)($_GET['pack_id'] ?? '')),
    'task' => trim((string)($_GET['task'] ?? '')),
    'state' => trim((string)($_GET['state'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
];
$where = [];
$params = [];
foreach (['pack_id', 'task', 'state'] as $key) {
    if ($filters[$key] !== '' && preg_match('/^[A-Za-z0-9_.:-]+$/', $filters[$key])) {
        $where[] = $key . ' = :' . $key;
        $params[':' . $key] = $filters[$key];
    }
}
if ($filters['q'] !== '') {
    $where[] = 'run_id LIKE :q';
    $params[':q'] = '%' . $filters['q'] . '%';
}
$sql = 'SELECT * FROM runtime_runs' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY started_at DESC, id DESC LIMIT 100';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$runs = $stmt->fetchAll();

hub_admin_header('執行歷程', $user);
?>
<section class="panel">
    <h1>Runtime 執行歷程</h1>
    <p class="muted">Local Job / aihub-run 的執行歷程。這裡只讀歷史，不提供重跑、刪除或取消。</p>
    <form method="get" class="hub-card-grid">
        <div><label>Pack</label><input name="pack_id" value="<?= hub_h($filters['pack_id']) ?>" placeholder="yolo"></div>
        <div><label>Job</label><input name="task" value="<?= hub_h($filters['task']) ?>" placeholder="yolo_predict"></div>
        <div><label>狀態</label><input name="state" value="<?= hub_h($filters['state']) ?>" placeholder="succeeded"></div>
        <div><label>Run ID</label><input name="q" value="<?= hub_h($filters['q']) ?>" placeholder="run_"></div>
        <div><label>&nbsp;</label><button class="primary" type="submit">查詢</button></div>
    </form>
</section>

<section class="panel">
    <h2>執行歷程</h2>
    <?php if ($runs === []): ?>
        <div class="hub-empty-state">目前沒有 Runtime 執行紀錄。</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Run ID</th><th>Pack</th><th>Job</th><th>狀態</th><th>開始時間</th><th>耗時</th><th>RAM 峰值</th><th>VRAM 峰值</th><th>結束碼</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $run): ?>
                <tr>
                    <td><code><?= hub_h((string)$run['run_id']) ?></code></td>
                    <td><code><?= hub_h((string)$run['pack_id']) ?></code></td>
                    <td><code><?= hub_h((string)$run['task']) ?></code></td>
                    <td><?= hub_runtime_state_badge((string)$run['state']) ?></td>
                    <td><?= hub_h((string)$run['started_at']) ?></td>
                    <td><?= hub_h(hub_runtime_format_ms($run['duration_ms'] ?? null)) ?></td>
                    <td><?= hub_h(hub_model_format_bytes(is_numeric($run['memory_peak_bytes']) ? (float)$run['memory_peak_bytes'] : null)) ?></td>
                    <td><?= hub_h(hub_model_format_bytes(is_numeric($run['vram_peak_bytes']) ? (float)$run['vram_peak_bytes'] : null)) ?></td>
                    <td><?= $run['exit_code'] === null ? '' : (int)$run['exit_code'] ?></td>
                    <td><a class="button" href="runtime_run.php?id=<?= urlencode((string)$run['run_id']) ?>">查看詳情</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
