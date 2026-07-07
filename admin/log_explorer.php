<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $query = ['tab' => hub_log_explorer_tab((string)($_POST['tab'] ?? 'api'))];
    foreach (['time_from', 'time_to', 'mode', 'service_id', 'member_id', 'token_id', 'ok', 'status_code', 'error_code', 'method', 'request_id', 'keyword'] as $key) {
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

$activeTab = hub_log_explorer_tab((string)($_GET['tab'] ?? 'api'));
$services = hub_list_services($db);
$members = hub_list_api_members($db);
$tokens = hub_list_all_api_tokens($db);
$clientIp = hub_decode_ip_get_filter((string)($_GET['client_ip_b64'] ?? ''), false);
$filters = [
    'time_from' => trim((string)($_GET['time_from'] ?? '')),
    'time_to' => trim((string)($_GET['time_to'] ?? '')),
    'client_ip_b64' => $clientIp === null ? '' : (string)($_GET['client_ip_b64'] ?? ''),
    'mode' => hub_log_token((string)($_GET['mode'] ?? '')),
    'service_id' => (int)($_GET['service_id'] ?? 0),
    'member_id' => (int)($_GET['member_id'] ?? 0),
    'token_id' => (int)($_GET['token_id'] ?? 0),
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
$jobFilters = hub_log_job_filters($_GET);
$jobLogs = $activeTab === 'jobs' ? hub_log_list_command_jobs($db, $jobFilters, 200) : [];

hub_admin_header('記錄中心', $user);
?>
<section class="panel">
    <h1>記錄中心</h1>
    <p class="muted">集中查 API 記錄、背景工作、服務記錄與系統記錄。IP filter 的 GET link 一律使用 base64url。</p>
    <nav class="hub-tabs" aria-label="Log Explorer tabs">
        <?php foreach (hub_log_explorer_tabs() as $tab => $label): ?>
            <a class="button<?= $activeTab === $tab ? ' primary' : '' ?>" href="log_explorer.php?tab=<?= hub_h($tab) ?>"><?= hub_h($label) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<?php if ($activeTab === 'api'): ?>
<section class="panel">
    <h2>API 記錄</h2>
    <p class="muted">查 API 介接紀錄、來源 IP、mode、錯誤原因。</p>
    <form method="post">
        <input type="hidden" name="tab" value="api">
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
        <label>API Member</label>
        <select name="member_id">
            <option value="">全部</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int)$member['id'] ?>"<?= (int)$filters['member_id'] === (int)$member['id'] ? ' selected' : '' ?>>
                    <?= hub_h($member['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>API Token</label>
        <select name="token_id">
            <option value="">全部</option>
            <?php foreach ($tokens as $token): ?>
                <option value="<?= (int)$token['id'] ?>"<?= (int)$filters['token_id'] === (int)$token['id'] ? ' selected' : '' ?>>
                    <?= hub_h($token['member_name'] . ' / ' . $token['token_name'] . ' / ' . hub_mask_api_token($token)) ?>
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
        <p><button class="primary" type="submit">查詢</button> <a class="button" href="log_explorer.php?tab=api">清除</a></p>
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
            <th>Time</th><th>IP</th><th>Member</th><th>Token</th><th>Mode</th><th>Service</th><th>Method</th><th>Status</th><th>OK</th><th>Error</th><th>Reason</th><th>ms</th><th>Bytes</th><th>Request ID</th><th>UA</th>
        </tr>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= hub_h($log['created_at']) ?></td>
                <td><a href="ip_profile.php?<?= hub_h(hub_ip_filter_query('ip_b64', $log['client_ip'])) ?>"><code><?= hub_h($log['client_ip']) ?></code></a></td>
                <td><?= hub_h((string)($log['member_name'] ?? '')) ?></td>
                <td><code><?= hub_h((string)($log['token_prefix'] ?? '')) ?></code> <?= hub_h((string)($log['token_name'] ?? '')) ?></td>
                <td><code><?= hub_h($log['mode']) ?></code></td>
                <td><?= hub_h($log['service_name'] ?? '') ?></td>
                <td><?= hub_h($log['method']) ?></td>
                <td><?= (int)$log['status_code'] ?></td>
                <td class="<?= (int)$log['ok'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$log['ok'] === 1 ? 'OK' : 'FAIL' ?></td>
                <td><code><?= hub_h($log['error_code']) ?></code></td>
                <td><?= hub_h(hub_short_text((string)($log['reason'] ?? ''), 80)) ?></td>
                <td><?= $log['elapsed_ms'] === null ? '' : (int)$log['elapsed_ms'] ?></td>
                <td><?= (int)($log['upload_bytes'] ?? 0) ?> / <?= (int)($log['response_bytes'] ?? 0) ?></td>
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
<?php elseif ($activeTab === 'jobs'): ?>
<section class="panel">
    <h2>背景工作</h2>
    <p class="muted">查 command_jobs 排程與執行結果。stdout_tail / stderr_tail 只顯示最後 6000 bytes。</p>
    <form method="get">
        <input type="hidden" name="tab" value="jobs">
        <label>Status</label>
        <select name="status">
            <option value="">全部</option>
            <?php foreach (hub_log_job_statuses() as $status): ?>
                <option value="<?= hub_h($status) ?>"<?= $jobFilters['status'] === $status ? ' selected' : '' ?>>
                    <?= hub_h(hub_command_status_label($status)) ?> / <?= hub_h($status) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Action</label>
        <select name="action">
            <option value="">全部</option>
            <?php foreach (hub_allowed_job_actions() as $action): ?>
                <option value="<?= hub_h($action) ?>"<?= $jobFilters['action'] === $action ? ' selected' : '' ?>>
                    <?= hub_h(hub_command_action_label($action)) ?> / <?= hub_h($action) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Service</label>
        <select name="service_id">
            <option value="">全部</option>
            <?php foreach ($services as $service): ?>
                <option value="<?= (int)$service['id'] ?>"<?= (int)$jobFilters['service_id'] === (int)$service['id'] ? ' selected' : '' ?>>
                    <?= hub_h($service['name']) ?> / <?= hub_h((string)($service['service_key'] ?? '')) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Time from</label>
        <input name="time_from" value="<?= hub_h($jobFilters['time_from']) ?>" placeholder="2026-07-06 00:00:00">
        <label>Time to</label>
        <input name="time_to" value="<?= hub_h($jobFilters['time_to']) ?>" placeholder="2026-07-06 23:59:59">
        <label>Keyword</label>
        <input name="keyword" value="<?= hub_h($jobFilters['keyword']) ?>" placeholder="action / stage / message / service_key">
        <p><button class="primary" type="submit">查詢</button> <a class="button" href="log_explorer.php?tab=jobs">清除</a></p>
    </form>
</section>
<section class="panel">
    <h2>command_jobs</h2>
    <p class="muted">共顯示 <?= count($jobLogs) ?> 筆，依建立時間新到舊排序。</p>
    <table>
        <tr>
            <th>工作 ID</th><th>建立時間</th><th>更新時間</th><th>action</th><th>服務</th><th>status</th><th>progress</th><th>stage</th><th>exit_code</th><th>requested</th><th>error_message</th><th>stdout_tail / stderr_tail</th>
        </tr>
        <?php foreach ($jobLogs as $job): ?>
            <?php
            $stdoutTail = hub_log_tail_file((string)($job['stdout_path'] ?? ''));
            $stderrTail = hub_log_tail_file((string)($job['stderr_path'] ?? ''));
            ?>
            <tr>
                <td>#<?= (int)$job['id'] ?></td>
                <td><?= hub_h((string)$job['created_at']) ?></td>
                <td><?= hub_h((string)$job['updated_at']) ?></td>
                <td><?= hub_h(hub_command_action_label((string)$job['action'])) ?><br><code><?= hub_h((string)$job['action']) ?></code></td>
                <td>
                    <?= hub_h((string)($job['service_name'] ?? '')) ?><br>
                    <code><?= hub_h((string)($job['service_key'] ?? '')) ?></code>
                </td>
                <td class="<?= hub_h(hub_command_status_class((string)$job['status'])) ?>">
                    <?= hub_h(hub_command_status_label((string)$job['status'])) ?><br><code><?= hub_h((string)$job['status']) ?></code>
                </td>
                <td>
                    <div class="job-progress"><span style="width: <?= (int)($job['progress'] ?? 0) ?>%"></span></div>
                    <?= (int)($job['progress'] ?? 0) ?>%
                </td>
                <td><code><?= hub_h((string)($job['stage'] ?? '')) ?></code><br><span class="muted"><?= hub_h(hub_short_text((string)($job['current_message'] ?? ''), 100)) ?></span></td>
                <td><?= $job['exit_code'] === null ? '' : (int)$job['exit_code'] ?></td>
                <td>
                    IP: <code><?= hub_h((string)($job['requested_ip'] ?? '')) ?></code><br>
                    By: <?= hub_h((string)($job['requested_by_username'] ?? $job['requested_by'] ?? '')) ?>
                </td>
                <td><?= hub_h(hub_short_text((string)($job['error_message'] ?? ''), 140)) ?></td>
                <td>
                    <details>
                        <summary>stdout_tail</summary>
                        <pre><?= hub_h($stdoutTail === '' ? '(empty)' : $stdoutTail) ?></pre>
                    </details>
                    <details>
                        <summary>stderr_tail</summary>
                        <pre><?= hub_h($stderrTail === '' ? '(empty)' : $stderrTail) ?></pre>
                    </details>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php elseif ($activeTab === 'service'): ?>
<section class="panel">
    <h2>服務記錄</h2>
    <div class="hub-empty-state">服務記錄會集中到這裡；第一版先保留入口，詳細 parser 後續再補。</div>
</section>
<?php else: ?>
<section class="panel">
    <h2>系統記錄</h2>
    <div class="hub-empty-state">系統記錄會集中到這裡；第一版先保留入口，避免服務管理頁承擔歷史查詢。</div>
</section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
<?php
function hub_log_explorer_tabs(): array
{
    return [
        'api' => 'API 記錄',
        'jobs' => '背景工作',
        'service' => '服務記錄',
        'system' => '系統記錄',
    ];
}

function hub_log_explorer_tab(string $value): string
{
    $value = trim($value);
    return array_key_exists($value, hub_log_explorer_tabs()) ? $value : 'api';
}

function hub_log_token(string $value): string
{
    $value = trim($value);
    return preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $value) ? $value : '';
}

function hub_short_text(string $value, int $limit): string
{
    return strlen($value) <= $limit ? $value : substr($value, 0, $limit - 3) . '...';
}

function hub_log_job_statuses(): array
{
    return ['queued', 'running', 'success', 'failed', 'cancelled', 'timeout'];
}

function hub_log_job_filters(array $source): array
{
    $status = trim((string)($source['status'] ?? ''));
    $action = trim((string)($source['action'] ?? ''));

    return [
        'status' => in_array($status, hub_log_job_statuses(), true) ? $status : '',
        'action' => hub_is_valid_job_action($action) ? $action : '',
        'service_id' => max(0, (int)($source['service_id'] ?? 0)),
        'keyword' => substr(trim((string)($source['keyword'] ?? '')), 0, 200),
        'time_from' => substr(trim((string)($source['time_from'] ?? '')), 0, 32),
        'time_to' => substr(trim((string)($source['time_to'] ?? '')), 0, 32),
    ];
}

function hub_log_list_command_jobs(PDO $db, array $filters, int $limit): array
{
    $where = [];
    $params = [];
    if ($filters['status'] !== '') {
        $where[] = 'cj.status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['action'] !== '') {
        $where[] = 'cj.action = :action';
        $params[':action'] = $filters['action'];
    }
    if ((int)$filters['service_id'] > 0) {
        $where[] = 'cj.service_id = :service_id';
        $params[':service_id'] = (int)$filters['service_id'];
    }
    if ($filters['time_from'] !== '') {
        $where[] = 'cj.created_at >= :time_from';
        $params[':time_from'] = $filters['time_from'];
    }
    if ($filters['time_to'] !== '') {
        $where[] = 'cj.created_at <= :time_to';
        $params[':time_to'] = $filters['time_to'];
    }
    if ($filters['keyword'] !== '') {
        $where[] = '(cj.action LIKE :keyword OR cj.stage LIKE :keyword OR cj.current_message LIKE :keyword OR cj.error_message LIKE :keyword OR s.name LIKE :keyword OR s.service_key LIKE :keyword)';
        $params[':keyword'] = '%' . $filters['keyword'] . '%';
    }

    $sql = 'SELECT cj.*, s.name AS service_name, s.service_key, u.username AS requested_by_username
            FROM command_jobs cj
            LEFT JOIN services s ON s.id = cj.service_id
            LEFT JOIN users u ON u.id = cj.requested_by';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY cj.id DESC LIMIT :limit';

    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_log_tail_file(string $path, int $limit = 6000): string
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $base = realpath(HUB_DATA_DIR);
    $real = realpath($path);
    if ($base === false || $real === false || ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR))) {
        return '';
    }

    $size = filesize($real);
    if ($size === false) {
        return '';
    }
    $handle = fopen($real, 'rb');
    if ($handle === false) {
        return '';
    }
    if ($size > $limit) {
        fseek($handle, -$limit, SEEK_END);
    }
    $content = stream_get_contents($handle);
    fclose($handle);

    return $content === false ? '' : $content;
}
