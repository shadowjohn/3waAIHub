<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$filters = [
    'member_id' => (int)($_GET['member_id'] ?? 0),
    'token_id' => (int)($_GET['token_id'] ?? 0),
    'mode' => trim((string)($_GET['mode'] ?? '')),
];
$rows = hub_list_api_usage_daily($db, $filters);
$members = hub_list_api_members($db);
$tokens = hub_list_all_api_tokens($db);
$totals = [
    'requests' => 0,
    'success' => 0,
    'failed' => 0,
    'elapsed_ms' => 0,
    'upload_bytes' => 0,
    'response_bytes' => 0,
];
foreach ($rows as $row) {
    $totals['requests'] += (int)$row['request_count'];
    $totals['success'] += (int)$row['success_count'];
    $totals['failed'] += (int)$row['failed_count'];
    $totals['elapsed_ms'] += (int)$row['total_elapsed_ms'];
    $totals['upload_bytes'] += (int)$row['total_upload_bytes'];
    $totals['response_bytes'] += (int)$row['total_response_bytes'];
}
$avgElapsedMs = $totals['requests'] > 0 ? (int)round($totals['elapsed_ms'] / $totals['requests']) : 0;

hub_admin_header('API 用量統計', $user);
?>
<style>
    .usage-filter-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .usage-summary { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-top: 16px; }
    .usage-summary-card { border: 1px solid var(--line); border-radius: 8px; padding: 12px; }
    .usage-summary-card strong { display: block; font-size: 24px; line-height: 1.2; margin-top: 4px; }
    .usage-table-wrap { overflow-x: auto; }
    .usage-table th, .usage-table td { white-space: nowrap; }
    .usage-num { font-variant-numeric: tabular-nums; text-align: right; }
</style>
<section class="panel">
    <h1>API 用量統計</h1>
    <form method="get">
        <div class="usage-filter-grid">
            <div>
                <label>Member</label>
                <select name="member_id">
                    <option value="">全部</option>
                    <?php foreach ($members as $member): ?>
                        <option value="<?= (int)$member['id'] ?>"<?= (int)$filters['member_id'] === (int)$member['id'] ? ' selected' : '' ?>><?= hub_h($member['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Token</label>
                <select name="token_id">
                    <option value="">全部</option>
                    <?php foreach ($tokens as $token): ?>
                        <option value="<?= (int)$token['id'] ?>"<?= (int)$filters['token_id'] === (int)$token['id'] ? ' selected' : '' ?>><?= hub_h($token['member_name'] . ' / ' . $token['token_name'] . ' / ' . $token['token_prefix']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Mode</label>
                <input name="mode" value="<?= hub_h($filters['mode']) ?>">
            </div>
        </div>
        <p><button class="primary" type="submit">查詢</button> <a class="button" href="api_usage.php">清除</a></p>
    </form>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2>每日彙總</h2>
        <span class="muted"><?= count($rows) ?> 筆</span>
    </div>
    <div class="usage-summary">
        <div class="usage-summary-card"><span class="muted">請求總數</span><strong><?= number_format($totals['requests']) ?></strong></div>
        <div class="usage-summary-card"><span class="muted">成功 / 失敗</span><strong><span class="ok"><?= number_format($totals['success']) ?></span> / <span class="bad"><?= number_format($totals['failed']) ?></span></strong></div>
        <div class="usage-summary-card"><span class="muted">平均回應時間</span><strong><?= number_format($avgElapsedMs) ?> ms</strong></div>
        <div class="usage-summary-card"><span class="muted">上傳 / 回應容量</span><strong><?= hub_h(hub_model_format_bytes($totals['upload_bytes'])) ?> / <?= hub_h(hub_model_format_bytes($totals['response_bytes'])) ?></strong></div>
    </div>
    <?php if ($rows === []): ?>
        <div class="hub-empty-state" style="margin-top: 16px;">目前沒有符合條件的用量紀錄。</div>
    <?php else: ?>
        <div class="usage-table-wrap">
            <table class="usage-table">
                <thead>
                    <tr>
                        <th>日期</th>
                        <th>Member</th>
                        <th>Token</th>
                        <th>Mode</th>
                        <th class="usage-num">請求</th>
                        <th class="usage-num">成功</th>
                        <th class="usage-num">失敗</th>
                        <th class="usage-num">平均回應時間 (ms)</th>
                        <th class="usage-num">上傳容量</th>
                        <th class="usage-num">回應容量</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php $rowAvgMs = (int)$row['request_count'] > 0 ? (int)round((int)$row['total_elapsed_ms'] / (int)$row['request_count']) : 0; ?>
                    <tr>
                        <td><?= hub_h($row['usage_date']) ?></td>
                        <td><?= hub_h($row['member_name']) ?></td>
                        <td><code><?= hub_h($row['token_prefix']) ?></code> <?= hub_h($row['token_name']) ?></td>
                        <td><code><?= hub_h($row['mode']) ?></code></td>
                        <td class="usage-num"><?= number_format((int)$row['request_count']) ?></td>
                        <td class="usage-num ok"><?= number_format((int)$row['success_count']) ?></td>
                        <td class="usage-num bad"><?= number_format((int)$row['failed_count']) ?></td>
                        <td class="usage-num"><?= number_format($rowAvgMs) ?></td>
                        <td class="usage-num"><?= hub_h(hub_model_format_bytes((int)$row['total_upload_bytes'])) ?></td>
                        <td class="usage-num"><?= hub_h(hub_model_format_bytes((int)$row['total_response_bytes'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
