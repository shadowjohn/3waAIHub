<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_login($db);
$filters = [
    'member_id' => (int)($_GET['member_id'] ?? 0),
    'token_id' => (int)($_GET['token_id'] ?? 0),
    'mode' => trim((string)($_GET['mode'] ?? '')),
];
$rows = hub_list_api_usage_daily($db, $filters);
$members = hub_list_api_members($db);
$tokens = hub_list_all_api_tokens($db);

hub_admin_header('API Usage', $user);
?>
<section class="panel">
    <h1>API Usage</h1>
    <form method="get">
        <label>Member</label>
        <select name="member_id">
            <option value="">全部</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= (int)$member['id'] ?>"<?= (int)$filters['member_id'] === (int)$member['id'] ? ' selected' : '' ?>><?= hub_h($member['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Token</label>
        <select name="token_id">
            <option value="">全部</option>
            <?php foreach ($tokens as $token): ?>
                <option value="<?= (int)$token['id'] ?>"<?= (int)$filters['token_id'] === (int)$token['id'] ? ' selected' : '' ?>><?= hub_h($token['member_name'] . ' / ' . $token['token_name'] . ' / ' . $token['token_prefix']) ?></option>
            <?php endforeach; ?>
        </select>
        <label>Mode</label>
        <input name="mode" value="<?= hub_h($filters['mode']) ?>">
        <p><button class="primary" type="submit">查詢</button> <a class="button" href="api_usage.php">清除</a></p>
    </form>
</section>
<section class="panel">
    <h2>Daily Aggregate</h2>
    <table>
        <tr><th>Date</th><th>Member</th><th>Token</th><th>Mode</th><th>Requests</th><th>Success</th><th>Failed</th><th>Avg ms</th><th>Upload</th><th>Response</th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= hub_h($row['usage_date']) ?></td>
                <td><?= hub_h($row['member_name']) ?></td>
                <td><code><?= hub_h($row['token_prefix']) ?></code> <?= hub_h($row['token_name']) ?></td>
                <td><code><?= hub_h($row['mode']) ?></code></td>
                <td><?= (int)$row['request_count'] ?></td>
                <td class="ok"><?= (int)$row['success_count'] ?></td>
                <td class="bad"><?= (int)$row['failed_count'] ?></td>
                <td><?= (int)$row['request_count'] > 0 ? (int)round((int)$row['total_elapsed_ms'] / (int)$row['request_count']) : 0 ?></td>
                <td><?= (int)$row['total_upload_bytes'] ?></td>
                <td><?= (int)$row['total_response_bytes'] ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
