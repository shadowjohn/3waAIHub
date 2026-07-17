<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_runtime.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$runId = trim((string)($_GET['id'] ?? ''));
if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/', $runId)) {
    http_response_code(400);
    exit('Run ID 格式不正確');
}

$stmt = $db->prepare('SELECT * FROM runtime_runs WHERE run_id = :run_id');
$stmt->execute([':run_id' => $runId]);
$run = $stmt->fetch();
if (!$run) {
    http_response_code(404);
    exit('找不到執行紀錄');
}
$sampleStmt = $db->prepare('SELECT * FROM runtime_resource_samples WHERE run_id = :run_id ORDER BY sampled_at ASC LIMIT 500');
$sampleStmt->execute([':run_id' => $runId]);
$samples = $sampleStmt->fetchAll();
$workspace = (string)($run['workspace'] ?? '');
$statusText = hub_runtime_tail($workspace . '/status.json', $workspace);
$resultText = hub_runtime_tail((string)($run['result_json_path'] ?? ''), $workspace);
$stdoutText = hub_runtime_tail((string)($run['stdout_log_path'] ?? ''), $workspace);
$stderrText = hub_runtime_tail((string)($run['stderr_log_path'] ?? ''), $workspace);
$resourceText = hub_runtime_tail($workspace . '/runtime/resource.ndjson', $workspace);

hub_admin_header('Run 詳情', $user);
?>
<section class="panel">
    <h1>Run 詳情</h1>
    <div class="hub-meta">
        <div class="hub-meta-label">Run ID</div><div class="hub-meta-value"><code><?= hub_h((string)$run['run_id']) ?></code></div>
        <div class="hub-meta-label">Pack</div><div class="hub-meta-value"><code><?= hub_h((string)$run['pack_id']) ?></code></div>
        <div class="hub-meta-label">Job</div><div class="hub-meta-value"><code><?= hub_h((string)$run['task']) ?></code></div>
        <div class="hub-meta-label">狀態</div><div class="hub-meta-value"><?= hub_runtime_state_badge((string)$run['state']) ?></div>
        <div class="hub-meta-label">耗時</div><div class="hub-meta-value"><?= hub_h(hub_runtime_format_ms($run['duration_ms'] ?? null)) ?></div>
        <div class="hub-meta-label">結束碼</div><div class="hub-meta-value"><?= $run['exit_code'] === null ? '' : (int)$run['exit_code'] ?></div>
        <div class="hub-meta-label">呼叫來源</div><div class="hub-meta-value"><code><?= hub_h((string)($run['caller'] ?? '')) ?></code></div>
        <div class="hub-meta-label">Workspace</div><div class="hub-meta-value"><code><?= hub_h(hub_runtime_display_path((string)$run['workspace'])) ?></code></div>
    </div>
</section>

<section class="panel">
    <h2>資源使用</h2>
    <?php if ($samples === []): ?>
        <div class="hub-empty-state">沒有資源取樣紀錄。</div>
    <?php else: ?>
        <table>
            <thead><tr><th>取樣時間</th><th>CPU %</th><th>RAM</th><th>GPU</th></tr></thead>
            <tbody>
            <?php foreach ($samples as $sample): ?>
                <tr>
                    <td><?= hub_h((string)$sample['sampled_at']) ?></td>
                    <td><?= $sample['cpu_percent'] === null ? '' : hub_h((string)$sample['cpu_percent']) ?></td>
                    <td><?= hub_h(hub_model_format_bytes(is_numeric($sample['memory_bytes']) ? (float)$sample['memory_bytes'] : null)) ?></td>
                    <td><code><?= hub_h((string)($sample['gpu_json'] ?? 'null')) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>Contract 檔案</h2>
    <h3>status.json</h3><pre><?= hub_h($statusText) ?></pre>
    <h3>result.json</h3><pre><?= hub_h($resultText) ?></pre>
    <h3>resource.ndjson</h3><pre><?= hub_h($resourceText) ?></pre>
    <h3>stdout</h3><pre><?= hub_h($stdoutText) ?></pre>
    <h3>stderr</h3><pre><?= hub_h($stderrText) ?></pre>
</section>

<section class="panel">
    <h2>Workspace 與 Artifact</h2>
    <p class="muted">只顯示 workspace 尾端路徑，不輸出主機絕對路徑。</p>
    <p><code><?= hub_h(hub_runtime_display_path($workspace)) ?></code></p>
</section>
<?php hub_admin_footer(); ?>
