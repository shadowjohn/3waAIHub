<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$usageRows = hub_list_customer_usage($db, (int)$user['id']);

hub_admin_header('用量統計', $user);
?>
<section class="panel">
    <h1>用量統計</h1>
    <p class="muted">只顯示你自己的 Token API 用量。</p>
</section>

<section class="panel">
    <?php if ($usageRows === []): ?>
        <div class="hub-empty-state">目前尚無用量紀錄。</div>
    <?php else: ?>
        <table>
            <thead><tr><th>日期</th><th>Token</th><th>mode</th><th>請求</th><th>成功</th><th>失敗</th><th>總耗時 ms</th><th>上傳 bytes</th><th>回應 bytes</th></tr></thead>
            <tbody>
            <?php foreach ($usageRows as $row): ?>
                <tr>
                    <td><?= hub_h((string)$row['usage_date']) ?></td>
                    <td><?= hub_h((string)$row['token_name']) ?> / <code><?= hub_h((string)$row['token_prefix']) ?>...</code></td>
                    <td><code><?= hub_h((string)$row['mode']) ?></code></td>
                    <td><?= (int)$row['request_count'] ?></td>
                    <td><?= (int)$row['success_count'] ?></td>
                    <td><?= (int)$row['failed_count'] ?></td>
                    <td><?= (int)$row['total_elapsed_ms'] ?></td>
                    <td><?= (int)$row['total_upload_bytes'] ?></td>
                    <td><?= (int)$row['total_response_bytes'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
