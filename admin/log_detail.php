<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_login($db);
$log = hub_get_api_access_log($db, (int)($_GET['id'] ?? 0));
if (!$log) {
    http_response_code(404);
    exit('Log not found');
}

$service = $log['service_id'] ? hub_get_service($db, (int)$log['service_id']) : null;
$rules = $service ? hub_list_service_ip_rules($db, (int)$service['id']) : [];
$sameIp = hub_list_api_access_logs($db, ['client_ip_b64' => aihub_b64url_encode((string)$log['client_ip'])], 20, 0);
$sameMode = $log['mode'] ? hub_list_api_access_logs($db, ['mode' => (string)$log['mode']], 20, 0) : [];

hub_admin_header('Log Detail', $user);
?>
<section class="panel">
    <h1>API Trace Detail</h1>
    <p><a class="button" href="log_explorer.php">回 Log Explorer</a></p>
    <table>
        <?php foreach ([
            'request_id', 'created_at', 'client_ip', 'mode', 'service_id', 'service_name', 'service_key',
            'method', 'request_uri', 'status_code', 'ok', 'error_code', 'reason', 'elapsed_ms', 'user_agent',
            'service_status',
        ] as $key): ?>
            <tr>
                <th><?= hub_h($key) ?></th>
                <td><?= hub_h((string)($log[$key] ?? '')) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php if ($rules): ?>
<section class="panel">
    <h2>Service Whitelist Rules</h2>
    <table>
        <tr><th>Rule</th><th>Type</th><th>Label</th><th>Enabled</th></tr>
        <?php foreach ($rules as $rule): ?>
            <tr>
                <td><code><?= hub_h($rule['ip_rule']) ?></code></td>
                <td><?= hub_h($rule['rule_type']) ?></td>
                <td><?= hub_h($rule['label']) ?></td>
                <td class="<?= (int)$rule['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$rule['enabled'] === 1 ? '是' : '否' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php endif; ?>
<section class="panel">
    <h2>Recent Same IP</h2>
    <p><a class="button" href="log_explorer.php?<?= hub_h(hub_ip_filter_query('client_ip_b64', (string)$log['client_ip'])) ?>">用此 IP 篩選</a></p>
    <?= hub_log_detail_table($sameIp) ?>
</section>
<section class="panel">
    <h2>Recent Same Mode</h2>
    <?= hub_log_detail_table($sameMode) ?>
</section>
<?php hub_admin_footer(); ?>
<?php
function hub_log_detail_table(array $logs): string
{
    ob_start();
    ?>
    <table>
        <tr><th>Time</th><th>IP</th><th>Mode</th><th>Status</th><th>OK</th><th>Error</th><th>Request ID</th></tr>
        <?php foreach ($logs as $row): ?>
            <tr>
                <td><?= hub_h($row['created_at']) ?></td>
                <td><code><?= hub_h($row['client_ip']) ?></code></td>
                <td><code><?= hub_h($row['mode']) ?></code></td>
                <td><?= (int)$row['status_code'] ?></td>
                <td class="<?= (int)$row['ok'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$row['ok'] === 1 ? 'OK' : 'FAIL' ?></td>
                <td><code><?= hub_h($row['error_code']) ?></code></td>
                <td><a href="log_detail.php?id=<?= (int)$row['id'] ?>"><code><?= hub_h($row['request_id']) ?></code></a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php
    return (string)ob_get_clean();
}
