<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$members = hub_list_api_members($db);

hub_admin_header('API 金鑰', $user);
?>
<section class="panel">
    <h1>API 金鑰</h1>
    <p><a class="button primary" href="api_member_edit.php">新增會員</a> <a class="button" href="api_usage.php">用量統計</a></p>
    <table>
        <tr><th>ID</th><th>會員</th><th>聯絡人</th><th>Email</th><th>啟用</th><th>Token 數</th><th>今日用量</th><th>最後使用</th><th>操作</th></tr>
        <?php foreach ($members as $member): ?>
            <tr>
                <td>#<?= (int)$member['id'] ?></td>
                <td><?= hub_h($member['name']) ?></td>
                <td><?= hub_h($member['contact_name']) ?></td>
                <td><?= hub_h($member['contact_email']) ?></td>
                <td class="<?= (int)$member['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$member['enabled'] === 1 ? '是' : '否' ?></td>
                <td><?= (int)$member['token_count'] ?></td>
                <td><?= (int)$member['today_requests'] ?></td>
                <td><?= hub_h((string)$member['last_used_at']) ?></td>
                <td>
                    <a class="button" href="api_member_edit.php?id=<?= (int)$member['id'] ?>">編輯</a>
                    <a class="button" href="api_tokens.php?member_id=<?= (int)$member['id'] ?>">Token</a>
                    <a class="button" href="api_usage.php?member_id=<?= (int)$member['id'] ?>">用量</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
