<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$ip = hub_decode_ip_get_filter((string)($_GET['ip_b64'] ?? ''), false);
if ($ip === null) {
    http_response_code(400);
    exit('IP 篩選格式不正確');
}

$summary = hub_ip_profile_summary($db, $ip);
$modes = hub_ip_profile_rows($db, 'SELECT mode AS label, COUNT(*) AS count FROM api_access_logs WHERE client_ip = :ip GROUP BY mode ORDER BY count DESC LIMIT 20', $ip);
$errors = hub_ip_profile_rows($db, 'SELECT error_code AS label, COUNT(*) AS count FROM api_access_logs WHERE client_ip = :ip AND ok = 0 GROUP BY error_code ORDER BY count DESC LIMIT 20', $ip);
$recent = hub_list_api_access_logs($db, ['client_ip_b64' => aihub_b64url_encode($ip)], 100, 0);

hub_admin_header('IP 概況', $user);
?>
<section class="panel">
    <h1>IP 概況</h1>
    <p><code><?= hub_h($ip) ?></code></p>
    <p><a class="button" href="log_explorer.php?<?= hub_h(hub_ip_filter_query('client_ip_b64', $ip)) ?>">查看此 IP 記錄</a></p>
    <table>
        <tr><th>總數</th><td><?= (int)$summary['total_count'] ?></td></tr>
        <tr><th>成功</th><td class="ok"><?= (int)$summary['success_count'] ?></td></tr>
        <tr><th>失敗</th><td class="bad"><?= (int)$summary['failed_count'] ?></td></tr>
        <tr><th>首次出現</th><td><?= hub_h($summary['first_seen'] ?? '') ?></td></tr>
        <tr><th>最後出現</th><td><?= hub_h($summary['last_seen'] ?? '') ?></td></tr>
    </table>
</section>
<section class="panel">
    <h2>Mode / 錯誤碼</h2>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <table>
            <tr><th>Mode</th><th>次數</th></tr>
            <?php foreach ($modes as $row): ?><tr><td><code><?= hub_h($row['label']) ?></code></td><td><?= (int)$row['count'] ?></td></tr><?php endforeach; ?>
        </table>
        <table>
            <tr><th>錯誤</th><th>次數</th></tr>
            <?php foreach ($errors as $row): ?><tr><td><code><?= hub_h($row['label']) ?></code></td><td class="bad"><?= (int)$row['count'] ?></td></tr><?php endforeach; ?>
        </table>
    </div>
</section>
<section class="panel">
    <h2>最近請求</h2>
    <table>
        <tr><th>時間</th><th>Mode</th><th>HTTP 方法</th><th>HTTP 狀態</th><th>結果</th><th>錯誤</th><th>Request ID</th></tr>
        <?php foreach ($recent as $row): ?>
            <tr>
                <td><?= hub_h($row['created_at']) ?></td>
                <td><code><?= hub_h($row['mode']) ?></code></td>
                <td><?= hub_h($row['method']) ?></td>
                <td><?= (int)$row['status_code'] ?></td>
                <td class="<?= (int)$row['ok'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$row['ok'] === 1 ? '成功' : '失敗' ?></td>
                <td><code><?= hub_h($row['error_code']) ?></code></td>
                <td><a href="log_detail.php?id=<?= (int)$row['id'] ?>"><code><?= hub_h($row['request_id']) ?></code></a></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
<?php
function hub_ip_profile_summary(PDO $db, string $ip): array
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total_count,
                SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END) AS success_count,
                SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END) AS failed_count,
                MIN(created_at) AS first_seen,
                MAX(created_at) AS last_seen
         FROM api_access_logs
         WHERE client_ip = :ip'
    );
    $stmt->execute([':ip' => $ip]);

    return $stmt->fetch() ?: ['total_count' => 0, 'success_count' => 0, 'failed_count' => 0];
}

function hub_ip_profile_rows(PDO $db, string $sql, string $ip): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute([':ip' => $ip]);

    return $stmt->fetchAll();
}
