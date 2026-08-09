<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$serviceId = (int)($_GET['service_id'] ?? $_POST['service_id'] ?? 0);
try {
    $service = hub_sam3_source_service($db, $serviceId);
} catch (InvalidArgumentException) {
    http_response_code(404);
    exit('找不到 SAM3 服務');
}

$message = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $source = hub_sam3_create_source(
                $db,
                $serviceId,
                (string)($_POST['display_name'] ?? ''),
                (string)($_POST['source_url'] ?? ''),
                $_POST['clip_seconds'] ?? 15,
                (int)$user['id']
            );
            $message = '來源已新增：' . (string)$source['source_id'];
        } elseif (in_array($action, ['enable', 'disable'], true)) {
            hub_sam3_set_source_enabled($db, (string)($_POST['source_id'] ?? ''), $serviceId, $action === 'enable');
            $message = '來源狀態已更新。';
        } elseif ($action === 'delete') {
            hub_sam3_delete_source($db, (string)($_POST['source_id'] ?? ''), $serviceId);
            $message = '來源已刪除。';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$sources = hub_sam3_list_sources($db, $serviceId, true);
hub_admin_header('SAM3 影片來源', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1>SAM3 影片來源</h1>
    <p><strong><?= hub_h((string)$service['name']) ?></strong>：僅允許私有 IP 的 RTSP/RTSPS，或明確在此登錄的 HTTPS <code>.m3u8</code>。公開 API 只傳 <code>source_id</code>，不接受 URL。</p>
</section>
<section class="panel">
    <h2>新增來源</h2>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
        <input type="hidden" name="action" value="create">
        <label>名稱</label>
        <input name="display_name" maxlength="128" required>
        <label>RTSP / RTSPS / HTTPS HLS URL</label>
        <input name="source_url" type="url" maxlength="2048" placeholder="rtsp://192.168.1.20/live 或 https://cams.example/live.m3u8" required>
        <label>單次擷取秒數（1–60）</label>
        <input name="clip_seconds" type="number" min="1" max="60" value="15" required>
        <p><button class="primary" type="submit">新增來源</button></p>
    </form>
</section>
<section class="panel">
    <h2>已登錄來源</h2>
    <table>
        <tr><th>名稱</th><th>source_id</th><th>協定</th><th>受控 URL</th><th>秒數</th><th>狀態</th><th>操作</th></tr>
        <?php foreach ($sources as $source): ?>
            <tr>
                <td><?= hub_h((string)$source['display_name']) ?></td>
                <td><code><?= hub_h((string)$source['source_id']) ?></code></td>
                <td><?= hub_h((string)$source['protocol']) ?></td>
                <td><code><?= hub_h((string)$source['source_url']) ?></code></td>
                <td><?= (int)$source['clip_seconds'] ?></td>
                <td class="<?= (int)$source['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$source['enabled'] === 1 ? '啟用' : '停用' ?></td>
                <td class="actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
                        <input type="hidden" name="source_id" value="<?= hub_h((string)$source['source_id']) ?>">
                        <?php if ((int)$source['enabled'] === 1): ?>
                            <button name="action" value="disable" type="submit">停用</button>
                        <?php else: ?>
                            <button class="primary" name="action" value="enable" type="submit">啟用</button>
                        <?php endif; ?>
                        <button class="danger" name="action" value="delete" type="submit">刪除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
