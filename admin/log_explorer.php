<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_login($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $query = [];
    foreach (['time_from', 'time_to', 'mode', 'service_id', 'ok', 'status_code', 'error_code', 'method', 'request_id', 'keyword'] as $key) {
        $value = trim((string)($_POST[$key] ?? ''));
        if ($value !== '') {
            $query[$key] = $value;
        }
    }
    $clientIp = trim((string)($_POST['client_ip'] ?? ''));
    if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $query['client_ip_b64'] = aihub_b64url_encode($clientIp);
    }
    hub_redirect('log_explorer.php' . ($query ? '?' . http_build_query($query) : ''));
}

$services = hub_list_services($db);
$clientIp = hub_decode_ip_get_filter((string)($_GET['client_ip_b64'] ?? ''), false);
$filters = [
    'time_from' => trim((string)($_GET['time_from'] ?? '')),
    'time_to' => trim((string)($_GET['time_to'] ?? '')),
    'client_ip_b64' => $clientIp === null ? '' : (string)($_GET['client_ip_b64'] ?? ''),
    'mode' => hub_log_token((string)($_GET['mode'] ?? '')),
    'service_id' => (int)($_GET['service_id'] ?? 0),
    'ok' => in_array((string)($_GET['ok'] ?? ''), ['0', '1'], true) ? (string)$_GET['ok'] : '',
    'status_code' => ctype_digit((string)($_GET['status_code'] ?? '')) ? (string)$_GET['status_code'] : '',
    'error_code' => hub_log_token((string)($_GET['error_code'] ?? '')),
    'method' => hub_log_token((string)($_GET['method'] ?? '')),
    'request_id' => hub_log_token((string)($_GET['request_id'] ?? '')),
    'keyword' => trim((string)($_GET['keyword'] ?? '')),
];
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 200;
$total = hub_api_access_count($db, $filters);
$logs = hub_list_api_access_logs($db, $filters, $limit, ($page - 1) * $limit);
$query = $_GET;
unset($query['page']);
$baseQuery = http_build_query($query);

hub_admin_header('Log Explorer', $user);
?>
<section class="panel">
    <h1>Log Explorer</h1>
    <p class="muted">查 API 介接紀錄、來源 IP、mode、錯誤原因。IP filter 的 GET link 一律使用 base64url。</p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <label>Time from</label>
        <input name="time_from" value="<?= hub_h($filters['time_from']) ?>" placeholder="2026-07-06 00:00:00">
        <label>Time to</label>
        <input name="time_to" value="<?= hub_h($filters['time_to']) ?>" placeholder="2026-07-06 23:59:59">
        <label>Client IP</label>
        <input name="client_ip" value="<?= hub_h($clientIp ?? '') ?>" placeholder="192.168.1.10 或 2001:db8::1">
        <label>Service</label>
        <select name="service_id">
            <option value="">全部</option>
            <?php foreach ($services as $service): ?>
                <option value="<?= (int)$service['id'] ?>"<?= (int)$filters['service_id'] === (int)$service['id'] ? ' selected' : '' ?>>
                    <?= hub_h($service['name']) ?> / <?= hub_h($service['mode']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Mode</label>
        <input name="mode" value="<?= hub_h($filters['mode']) ?>">
        <label>OK</label>
        <select name="ok">
            <option value="">全部</option>
            <option value="1"<?= $filters['ok'] === '1' ? ' selected' : '' ?>>成功</option>
            <option value="0"<?= $filters['ok'] === '0' ? ' selected' : '' ?>>失敗</option>
        </select>
        <label>Status Code</label>
        <input name="status_code" value="<?= hub_h($filters['status_code']) ?>">
        <label>Error Code</label>
        <input name="error_code" value="<?= hub_h($filters['error_code']) ?>">
        <label>Method</label>
        <input name="method" value="<?= hub_h($filters['method']) ?>">
        <label>Request ID</label>
        <input name="request_id" value="<?= hub_h($filters['request_id']) ?>">
        <label>Keyword</label>
        <input name="keyword" value="<?= hub_h($filters['keyword']) ?>" placeholder="request_uri / reason / user_agent">
        <p><button class="primary" type="submit">查詢</button> <a class="button" href="log_explorer.php">清除</a></p>
    </form>
</section>
<section class="panel">
    <h2>Last 24h</h2>
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px">
        <?php foreach (['failed_ips' => 'Top Failed IPs', 'error_codes' => 'Top Error Codes', 'unknown_modes' => 'Unknown Modes', 'denied_ips' => 'Denied IPs'] as $kind => $title): ?>
            <div>
                <h3><?= hub_h($title) ?></h3>
                <table>
                    <?php foreach (hub_api_trace_stats($db, $kind, 10) as $row): ?>
                        <tr>
                            <td>
                                <?php if (in_array($kind, ['failed_ips', 'denied_ips'], true)): ?>
                                    <a href="ip_profile.php?<?= hub_h(hub_ip_filter_query('ip_b64', (string)$row['label'])) ?>"><code><?= hub_h($row['label']) ?></code></a>
                                <?php else: ?>
                                    <code><?= hub_h($row['label']) ?></code>
                                <?php endif; ?>
                            </td>
                            <td class="bad"><?= (int)$row['count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel">
    <h2>Logs</h2>
    <p class="muted">共 <?= (int)$total ?> 筆，Page <?= (int)$page ?>，每頁 <?= (int)$limit ?> 筆。</p>
    <table>
        <tr>
            <th>Time</th><th>IP</th><th>Mode</th><th>Service</th><th>Method</th><th>Status</th><th>OK</th><th>Error</th><th>Reason</th><th>ms</th><th>Request ID</th><th>UA</th>
        </tr>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= hub_h($log['created_at']) ?></td>
                <td><a href="ip_profile.php?<?= hub_h(hub_ip_filter_query('ip_b64', $log['client_ip'])) ?>"><code><?= hub_h($log['client_ip']) ?></code></a></td>
                <td><code><?= hub_h($log['mode']) ?></code></td>
                <td><?= hub_h($log['service_name'] ?? '') ?></td>
                <td><?= hub_h($log['method']) ?></td>
                <td><?= (int)$log['status_code'] ?></td>
                <td class="<?= (int)$log['ok'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$log['ok'] === 1 ? 'OK' : 'FAIL' ?></td>
                <td><code><?= hub_h($log['error_code']) ?></code></td>
                <td><?= hub_h(hub_short_text((string)($log['reason'] ?? ''), 80)) ?></td>
                <td><?= $log['elapsed_ms'] === null ? '' : (int)$log['elapsed_ms'] ?></td>
                <td><a href="log_detail.php?id=<?= (int)$log['id'] ?>"><code><?= hub_h($log['request_id']) ?></code></a></td>
                <td><?= hub_h(hub_short_text((string)($log['user_agent'] ?? ''), 80)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p>
        <?php if ($page > 1): ?>
            <a class="button" href="log_explorer.php?<?= hub_h($baseQuery . ($baseQuery === '' ? '' : '&') . 'page=' . ($page - 1)) ?>">上一頁</a>
        <?php endif; ?>
        <?php if (($page * $limit) < $total): ?>
            <a class="button" href="log_explorer.php?<?= hub_h($baseQuery . ($baseQuery === '' ? '' : '&') . 'page=' . ($page + 1)) ?>">下一頁</a>
        <?php endif; ?>
    </p>
</section>
<?php hub_admin_footer(); ?>
<?php
function hub_log_token(string $value): string
{
    $value = trim($value);
    return preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $value) ? $value : '';
}

function hub_short_text(string $value, int $limit): string
{
    return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3) . '...';
}
