<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin_records.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_runtime.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    $tab = hub_admin_record_tab($_POST['tab'] ?? 'runs');
    $source = $_POST;
    $clientIp = hub_admin_record_input_string($_POST['client_ip'] ?? '');
    if ($clientIp !== '' && filter_var($clientIp, FILTER_VALIDATE_IP)) {
        $source['client_ip_b64'] = aihub_b64url_encode($clientIp);
    }
    $query = ['tab' => $tab];
    if ($tab === 'api') {
        foreach (hub_admin_record_api_filters($source) as $key => $value) {
            if ($value !== '' && $value !== 0) {
                $query[$key] = $value;
            }
        }
    }
    hub_redirect(hub_admin_record_log_explorer_url($query));
}

$activeTab = hub_admin_record_tab($_GET['tab'] ?? 'runs');
$services = hub_list_services($db);

$runtimeFilters = hub_admin_record_runtime_filters($_GET);
$runs = $activeTab === 'runs' ? hub_admin_record_runtime_runs($db, $runtimeFilters, 100) : [];

$apiFilters = hub_admin_record_api_filters($_GET);
$clientIp = hub_decode_ip_get_filter($apiFilters['client_ip_b64'], false);
$members = $activeTab === 'api' ? hub_list_api_members($db) : [];
$tokens = $activeTab === 'api' ? hub_list_all_api_tokens($db) : [];
$page = max(1, min(1000000, hub_admin_record_positive_int($_GET['page'] ?? 1)));
$limit = 200;
$total = $activeTab === 'api' ? hub_api_access_count($db, $apiFilters) : 0;
$logs = $activeTab === 'api' ? hub_list_api_access_logs($db, $apiFilters, $limit, ($page - 1) * $limit) : [];
$apiQuery = ['tab' => 'api'];
foreach ($apiFilters as $key => $value) {
    if ($value !== '' && $value !== 0) {
        $apiQuery[$key] = $value;
    }
}
$query = $apiQuery;
unset($query['page']);
$baseQuery = http_build_query($query);

$jobFilters = hub_admin_record_job_filters($_GET);
$jobLogs = $activeTab === 'jobs' ? hub_admin_record_command_jobs($db, $jobFilters, 200) : [];
$serviceId = hub_admin_record_positive_int($_GET['service_id'] ?? 0);
$serviceLogs = $activeTab === 'service' ? hub_admin_record_service_logs($db, $serviceId, 200) : [];
$systemLogs = $activeTab === 'system' ? hub_admin_record_system_logs($db, 200) : [];

hub_admin_header('記錄中心', $user);
?>
<style>
    .record-table-wrap { max-width: 100%; overflow-x: auto; }
    .record-table-wrap table { min-width: 760px; }
    .record-stats { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 220px), 1fr)); }
</style>
<section class="panel">
    <h1><?= hub_h(hub_i18n_text('記錄中心')) ?></h1>
    <p class="muted"><?= hub_h(hub_i18n_text('集中查執行歷程、API 記錄、背景工作、服務記錄與系統記錄。IP 篩選的 GET link 一律使用 base64url。')) ?></p>
    <nav class="tabs" aria-label="<?= hub_h(hub_i18n_text('記錄中心頁籤')) ?>">
        <?php foreach (hub_admin_record_tabs() as $tab => $label): ?>
            <a class="tab" aria-selected="<?= $activeTab === $tab ? 'true' : 'false' ?>" href="log_explorer.php?tab=<?= hub_h($tab) ?>"><?= hub_h(hub_i18n_text($label)) ?></a>
        <?php endforeach; ?>
    </nav>
</section>

<?php if ($activeTab === 'runs'): ?>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('Runtime 執行歷程')) ?></h2>
    <p class="muted"><?= hub_h(hub_i18n_text('Local Job / aihub-run 的執行歷程。這裡只讀歷史，不提供重跑、刪除或取消。')) ?></p>
    <form method="get">
        <input type="hidden" name="tab" value="runs">
        <label><?= hub_h(hub_i18n_text('Pack')) ?></label>
        <input name="pack_id" value="<?= hub_h($runtimeFilters['pack_id']) ?>" placeholder="yolo">
        <label><?= hub_h(hub_i18n_text('Job')) ?></label>
        <input name="task" value="<?= hub_h($runtimeFilters['task']) ?>" placeholder="yolo_predict">
        <label><?= hub_h(hub_i18n_text('狀態')) ?></label>
        <input name="state" value="<?= hub_h($runtimeFilters['state']) ?>" placeholder="succeeded">
        <label>Run ID</label>
        <input name="q" value="<?= hub_h($runtimeFilters['q']) ?>" placeholder="run_">
        <p><button class="primary" type="submit"><?= hub_h(hub_i18n_text('查詢')) ?></button> <a class="button" href="log_explorer.php?tab=runs"><?= hub_h(hub_i18n_text('清除')) ?></a></p>
    </form>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(hub_i18n_text('執行歷程')) ?></h2>
        <span class="muted"><?= count($runs) ?> <?= hub_h(hub_i18n_text('筆')) ?></span>
    </div>
    <?php if ($runs === []): ?>
        <div class="hub-empty-state"><?= hub_h(hub_i18n_text('目前沒有 Runtime 執行紀錄。')) ?></div>
    <?php else: ?>
        <div class="record-table-wrap">
            <table>
                <thead><tr>
                    <th>Run ID</th><th>Pack</th><th>Job</th><th><?= hub_h(hub_i18n_text('狀態')) ?></th><th><?= hub_h(hub_i18n_text('開始時間')) ?></th><th><?= hub_h(hub_i18n_text('耗時')) ?></th><th><?= hub_h(hub_i18n_text('RAM 峰值')) ?></th><th><?= hub_h(hub_i18n_text('VRAM 峰值')) ?></th><th><?= hub_h(hub_i18n_text('結束碼')) ?></th><th><?= hub_h(hub_i18n_text('操作')) ?></th>
                </tr></thead>
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
                        <td><a class="button" href="runtime_run.php?id=<?= urlencode((string)$run['run_id']) ?>"><?= hub_h(hub_i18n_text('查看詳情')) ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php elseif ($activeTab === 'api'): ?>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('API 記錄')) ?></h2>
    <p class="muted"><?= hub_h(hub_i18n_text('查 API 介接紀錄、來源 IP、mode、錯誤原因。')) ?></p>
    <form method="post">
        <input type="hidden" name="tab" value="api">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <label><?= hub_h(hub_i18n_text('開始時間')) ?></label>
        <input name="time_from" value="<?= hub_h($apiFilters['time_from']) ?>" placeholder="2026-07-06 00:00:00">
        <label><?= hub_h(hub_i18n_text('結束時間')) ?></label>
        <input name="time_to" value="<?= hub_h($apiFilters['time_to']) ?>" placeholder="2026-07-06 23:59:59">
        <label><?= hub_h(hub_i18n_text('客戶端 IP')) ?></label>
        <input name="client_ip" value="<?= hub_h($clientIp ?? '') ?>" placeholder="192.168.1.10 / 2001:db8::1">
        <label><?= hub_h(hub_i18n_text('服務')) ?></label>
        <select name="service_id">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach ($services as $service): ?>
                <option value="<?= (int)$service['id'] ?>"<?= $apiFilters['service_id'] === (int)$service['id'] ? ' selected' : '' ?>><?= hub_h($service['name']) ?> / <?= hub_h($service['mode']) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= hub_h(hub_i18n_text('API 會員')) ?></label>
        <select name="member_id">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int)$member['id'] ?>"<?= $apiFilters['member_id'] === (int)$member['id'] ? ' selected' : '' ?>><?= hub_h($member['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>API Token</label>
        <select name="token_id">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach ($tokens as $token): ?>
                <option value="<?= (int)$token['id'] ?>"<?= $apiFilters['token_id'] === (int)$token['id'] ? ' selected' : '' ?>><?= hub_h($token['member_name'] . ' / ' . $token['token_name'] . ' / ' . hub_mask_api_token($token)) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Mode</label>
        <input name="mode" value="<?= hub_h($apiFilters['mode']) ?>">
        <label><?= hub_h(hub_i18n_text('結果')) ?></label>
        <select name="ok">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <option value="1"<?= $apiFilters['ok'] === '1' ? ' selected' : '' ?>><?= hub_h(hub_i18n_text('成功')) ?></option>
            <option value="0"<?= $apiFilters['ok'] === '0' ? ' selected' : '' ?>><?= hub_h(hub_i18n_text('失敗')) ?></option>
        </select>
        <label>HTTP <?= hub_h(hub_i18n_text('狀態碼')) ?></label>
        <input name="status_code" value="<?= hub_h($apiFilters['status_code']) ?>">
        <label><?= hub_h(hub_i18n_text('錯誤碼')) ?></label>
        <input name="error_code" value="<?= hub_h($apiFilters['error_code']) ?>">
        <label>HTTP <?= hub_h(hub_i18n_text('方法')) ?></label>
        <input name="method" value="<?= hub_h($apiFilters['method']) ?>">
        <label>Request ID</label>
        <input name="request_id" value="<?= hub_h($apiFilters['request_id']) ?>">
        <label><?= hub_h(hub_i18n_text('關鍵字')) ?></label>
        <input name="keyword" value="<?= hub_h($apiFilters['keyword']) ?>" placeholder="request_uri / reason / user_agent">
        <p><button class="primary" type="submit"><?= hub_h(hub_i18n_text('查詢')) ?></button> <a class="button" href="log_explorer.php?tab=api"><?= hub_h(hub_i18n_text('清除')) ?></a></p>
    </form>
</section>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('最近 24 小時')) ?></h2>
    <div class="record-stats">
        <?php foreach (['failed_ips' => hub_i18n_text('失敗最多 IP'), 'error_codes' => hub_i18n_text('常見錯誤碼'), 'unknown_modes' => hub_i18n_text('未知 Mode'), 'denied_ips' => hub_i18n_text('被拒絕 IP')] as $kind => $title): ?>
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
    <h2><?= hub_h(hub_i18n_text('記錄列表')) ?></h2>
    <p class="muted"><?= hub_h(hub_i18n_text('共')) ?> <?= (int)$total ?> <?= hub_h(hub_i18n_text('筆，第')) ?> <?= (int)$page ?> <?= hub_h(hub_i18n_text('頁，每頁')) ?> <?= (int)$limit ?> <?= hub_h(hub_i18n_text('筆。')) ?></p>
    <div class="record-table-wrap">
        <table>
            <thead><tr>
                <th><?= hub_h(hub_i18n_text('時間')) ?></th><th>IP</th><th><?= hub_h(hub_i18n_text('會員')) ?></th><th>Token</th><th>Mode</th><th><?= hub_h(hub_i18n_text('服務')) ?></th><th>HTTP <?= hub_h(hub_i18n_text('方法')) ?></th><th>HTTP <?= hub_h(hub_i18n_text('狀態')) ?></th><th><?= hub_h(hub_i18n_text('結果')) ?></th><th><?= hub_h(hub_i18n_text('錯誤')) ?></th><th><?= hub_h(hub_i18n_text('原因')) ?></th><th><?= hub_h(hub_i18n_text('耗時')) ?> ms</th><th><?= hub_h(hub_i18n_text('容量')) ?></th><th>Request ID</th><th>UA</th>
            </tr></thead>
            <tbody>
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
                    <td class="<?= (int)$log['ok'] === 1 ? 'ok' : 'bad' ?>"><?= hub_h((int)$log['ok'] === 1 ? hub_i18n_text('成功') : hub_i18n_text('失敗')) ?></td>
                    <td><code><?= hub_h($log['error_code']) ?></code></td>
                    <td><?= hub_h(hub_admin_record_short_text((string)($log['reason'] ?? ''), 80)) ?></td>
                    <td><?= $log['elapsed_ms'] === null ? '' : (int)$log['elapsed_ms'] ?></td>
                    <td><?= (int)($log['upload_bytes'] ?? 0) ?> / <?= (int)($log['response_bytes'] ?? 0) ?></td>
                    <td><a href="log_detail.php?id=<?= (int)$log['id'] ?>"><code><?= hub_h($log['request_id']) ?></code></a></td>
                    <td><?= hub_h(hub_admin_record_short_text((string)($log['user_agent'] ?? ''), 80)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p>
        <?php if ($page > 1): ?>
            <a class="button" href="log_explorer.php?<?= hub_h($baseQuery . '&' . 'page=' . ($page - 1)) ?>"><?= hub_h(hub_i18n_text('上一頁')) ?></a>
        <?php endif; ?>
        <?php if (($page * $limit) < $total): ?>
            <a class="button" href="log_explorer.php?<?= hub_h($baseQuery . '&' . 'page=' . ($page + 1)) ?>"><?= hub_h(hub_i18n_text('下一頁')) ?></a>
        <?php endif; ?>
    </p>
</section>

<?php elseif ($activeTab === 'jobs'): ?>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('背景工作')) ?></h2>
    <p class="muted"><?= hub_h(hub_i18n_text('查 command_jobs 排程與執行結果。stdout_tail / stderr_tail 只顯示最後 6000 bytes。')) ?></p>
    <form method="get">
        <input type="hidden" name="tab" value="jobs">
        <label><?= hub_h(hub_i18n_text('狀態')) ?></label>
        <select name="status">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach (hub_admin_record_job_statuses() as $status): ?>
                <option value="<?= hub_h($status) ?>"<?= $jobFilters['status'] === $status ? ' selected' : '' ?>><?= hub_h(hub_i18n_text(hub_command_status_label($status))) ?> / <?= hub_h($status) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= hub_h(hub_i18n_text('動作')) ?></label>
        <select name="action">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach (hub_allowed_job_actions() as $action): ?>
                <option value="<?= hub_h($action) ?>"<?= $jobFilters['action'] === $action ? ' selected' : '' ?>><?= hub_h(hub_i18n_text(hub_command_action_label($action))) ?> / <?= hub_h($action) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= hub_h(hub_i18n_text('服務')) ?></label>
        <select name="service_id">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach ($services as $service): ?>
                <option value="<?= (int)$service['id'] ?>"<?= $jobFilters['service_id'] === (int)$service['id'] ? ' selected' : '' ?>><?= hub_h($service['name']) ?> / <?= hub_h((string)($service['service_key'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
        <label><?= hub_h(hub_i18n_text('開始時間')) ?></label>
        <input name="time_from" value="<?= hub_h($jobFilters['time_from']) ?>" placeholder="2026-07-06 00:00:00">
        <label><?= hub_h(hub_i18n_text('結束時間')) ?></label>
        <input name="time_to" value="<?= hub_h($jobFilters['time_to']) ?>" placeholder="2026-07-06 23:59:59">
        <label><?= hub_h(hub_i18n_text('關鍵字')) ?></label>
        <input name="keyword" value="<?= hub_h($jobFilters['keyword']) ?>" placeholder="action / stage / message / service_key">
        <p><button class="primary" type="submit"><?= hub_h(hub_i18n_text('查詢')) ?></button> <a class="button" href="log_explorer.php?tab=jobs"><?= hub_h(hub_i18n_text('清除')) ?></a></p>
    </form>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(hub_i18n_text('背景工作列表')) ?></h2>
        <span class="muted"><?= count($jobLogs) ?> <?= hub_h(hub_i18n_text('筆')) ?></span>
    </div>
    <div class="record-table-wrap">
        <table>
            <thead><tr>
                <th><?= hub_h(hub_i18n_text('工作 ID')) ?></th><th><?= hub_h(hub_i18n_text('建立時間')) ?></th><th><?= hub_h(hub_i18n_text('更新時間')) ?></th><th><?= hub_h(hub_i18n_text('動作')) ?></th><th><?= hub_h(hub_i18n_text('服務')) ?></th><th><?= hub_h(hub_i18n_text('狀態')) ?></th><th><?= hub_h(hub_i18n_text('進度')) ?></th><th><?= hub_h(hub_i18n_text('階段')) ?></th><th><?= hub_h(hub_i18n_text('結束碼')) ?></th><th><?= hub_h(hub_i18n_text('請求來源')) ?></th><th><?= hub_h(hub_i18n_text('錯誤訊息')) ?></th><th>stdout_tail / stderr_tail</th>
            </tr></thead>
            <tbody>
            <?php foreach ($jobLogs as $job): ?>
                <?php
                $stdoutTail = hub_admin_record_tail_file((string)($job['stdout_path'] ?? ''));
                $stderrTail = hub_admin_record_tail_file((string)($job['stderr_path'] ?? ''));
                ?>
                <tr>
                    <td>#<?= (int)$job['id'] ?></td>
                    <td><?= hub_h((string)$job['created_at']) ?></td>
                    <td><?= hub_h((string)$job['updated_at']) ?></td>
                    <td><?= hub_h(hub_i18n_text(hub_command_action_label((string)$job['action']))) ?><br><code><?= hub_h((string)$job['action']) ?></code></td>
                    <td><?= hub_h((string)($job['service_name'] ?? '')) ?><br><code><?= hub_h((string)($job['service_key'] ?? '')) ?></code></td>
                    <td class="<?= hub_h(hub_command_status_class((string)$job['status'])) ?>"><?= hub_h(hub_i18n_text(hub_command_status_label((string)$job['status']))) ?><br><code><?= hub_h((string)$job['status']) ?></code></td>
                    <td><div class="job-progress"><span style="width: <?= (int)($job['progress'] ?? 0) ?>%"></span></div><?= (int)($job['progress'] ?? 0) ?>%</td>
                    <td><code><?= hub_h((string)($job['stage'] ?? '')) ?></code><br><span class="muted"><?= hub_h(hub_admin_record_short_text((string)($job['current_message'] ?? ''), 100)) ?></span></td>
                    <td><?= $job['exit_code'] === null ? '' : (int)$job['exit_code'] ?></td>
                    <td>IP: <code><?= hub_h((string)($job['requested_ip'] ?? '')) ?></code><br><?= hub_h(hub_i18n_text('請求人')) ?>: <?= hub_h((string)($job['requested_by_username'] ?? $job['requested_by'] ?? '')) ?></td>
                    <td><?= hub_h(hub_admin_record_short_text((string)($job['error_message'] ?? ''), 140)) ?></td>
                    <td>
                        <details><summary>stdout_tail</summary><pre><?= hub_h($stdoutTail === '' ? '(empty)' : $stdoutTail) ?></pre></details>
                        <details><summary>stderr_tail</summary><pre><?= hub_h($stderrTail === '' ? '(empty)' : $stderrTail) ?></pre></details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($activeTab === 'service'): ?>
<section class="panel">
    <h2><?= hub_h(hub_i18n_text('服務記錄')) ?></h2>
    <form method="get">
        <input type="hidden" name="tab" value="service">
        <label><?= hub_h(hub_i18n_text('服務')) ?></label>
        <select name="service_id">
            <option value=""><?= hub_h(hub_i18n_text('全部')) ?></option>
            <?php foreach ($services as $service): ?>
                <option value="<?= (int)$service['id'] ?>"<?= $serviceId === (int)$service['id'] ? ' selected' : '' ?>><?= hub_h($service['name']) ?> / <?= hub_h((string)($service['service_key'] ?? '')) ?></option>
            <?php endforeach; ?>
        </select>
        <p><button class="primary" type="submit"><?= hub_h(hub_i18n_text('查詢')) ?></button> <a class="button" href="log_explorer.php?tab=service"><?= hub_h(hub_i18n_text('清除')) ?></a></p>
    </form>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(hub_i18n_text('服務記錄列表')) ?></h2>
        <span class="muted"><?= count($serviceLogs) ?> <?= hub_h(hub_i18n_text('筆')) ?></span>
    </div>
    <div class="record-table-wrap">
        <table>
            <thead><tr><th><?= hub_h(hub_i18n_text('時間')) ?></th><th><?= hub_h(hub_i18n_text('服務')) ?></th><th><?= hub_h(hub_i18n_text('動作')) ?></th><th><?= hub_h(hub_i18n_text('結束碼')) ?></th><th><?= hub_h(hub_i18n_text('輸出')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($serviceLogs as $log): ?>
                <tr>
                    <td><?= hub_h((string)$log['created_at']) ?></td>
                    <td><a href="service_logs.php?id=<?= (int)$log['service_id'] ?>"><?= hub_h((string)$log['service_name']) ?></a><br><code><?= hub_h((string)$log['service_key']) ?></code></td>
                    <td><code><?= hub_h((string)$log['action']) ?></code></td>
                    <td class="<?= (int)$log['exit_code'] === 0 ? 'ok' : 'bad' ?>"><?= (int)$log['exit_code'] ?></td>
                    <td><pre><?= hub_h(hub_admin_record_short_text((string)$log['output'], 500)) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php else: ?>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(hub_i18n_text('系統記錄')) ?></h2>
        <span class="muted"><?= count($systemLogs) ?> <?= hub_h(hub_i18n_text('筆')) ?></span>
    </div>
    <div class="record-table-wrap">
        <table>
            <thead><tr><th><?= hub_h(hub_i18n_text('時間')) ?></th><th><?= hub_h(hub_i18n_text('使用者')) ?></th><th><?= hub_h(hub_i18n_text('動作')) ?></th><th><?= hub_h(hub_i18n_text('內容')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($systemLogs as $log): ?>
                <tr>
                    <td><?= hub_h((string)$log['created_at']) ?></td>
                    <td><?= hub_h((string)$log['username']) ?></td>
                    <td><code><?= hub_h((string)$log['action']) ?></code></td>
                    <td><?= hub_h(hub_admin_record_short_text((string)$log['details'], 500)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php hub_admin_footer(); ?>
