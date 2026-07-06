<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$services = hub_list_services($db);

hub_admin_header('儀表板', $user);
?>
<section class="panel">
    <h1>儀表板</h1>
    <p class="muted">最小閉環：安裝、登入、啟停 hello-service、透過 api.php?mode=hello 對外提供 API。</p>
</section>
<section class="panel">
    <h2>服務狀態</h2>
    <table>
        <tr><th>名稱</th><th>模式</th><th>狀態</th><th>內部 URL</th></tr>
        <?php foreach ($services as $service): ?>
            <tr>
                <td><?= hub_h($service['name']) ?></td>
                <td><code><?= hub_h($service['mode']) ?></code></td>
                <td class="<?= hub_status_class($service['status']) ?>"><?= hub_h(hub_status_label($service['status'])) ?></td>
                <td><?= hub_h($service['internal_url']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
