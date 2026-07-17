<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$usageRows = hub_list_customer_usage($db, (int)$user['id']);

hub_admin_header(__('用量統計'), $user);
?>
<section class="panel">
    <h1><?= hub_h(__('用量統計')) ?></h1>
    <p class="muted"><?= hub_h(__('只顯示你自己的 Token API 用量。')) ?></p>
</section>

<section class="panel">
    <?php if ($usageRows === []): ?>
        <div class="hub-empty-state"><?= hub_h(__('目前尚無用量紀錄。')) ?></div>
    <?php else: ?>
        <table>
            <thead><tr><th><?= hub_h(__('日期')) ?></th><th>Token</th><th>mode</th><th><?= hub_h(__('請求')) ?></th><th><?= hub_h(__('成功')) ?></th><th><?= hub_h(__('失敗')) ?></th><th><?= hub_h(__('總耗時 ms')) ?></th><th><?= hub_h(__('上傳容量')) ?></th><th><?= hub_h(__('回應容量')) ?></th></tr></thead>
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
                    <td><?= hub_h(hub_model_format_bytes((int)$row['total_upload_bytes'])) ?></td>
                    <td><?= hub_h(hub_model_format_bytes((int)$row['total_response_bytes'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
