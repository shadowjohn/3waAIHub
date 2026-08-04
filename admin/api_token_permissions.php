<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
$user = hub_require_system_admin($db);
$token = hub_get_api_token($db, (int)($_GET['token_id'] ?? $_POST['token_id'] ?? 0));
if (!$token) {
    http_response_code(404);
    exit('找不到 Token');
}
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    hub_set_api_token_mode_permissions($db, (int)$token['id'], is_array($_POST['modes'] ?? null) ? $_POST['modes'] : []);
    $message = 'Mode 權限已更新。';
}
$enabledModes = array_column(hub_list_api_token_permissions($db, (int)$token['id']), 'mode');
$services = hub_list_services($db);
$taskModes = hub_task_api_modes();
$photoModes = hub_photo_modes();
$audioModes = hub_audio_modes();
$shownModes = array_fill_keys(array_merge(
    array_column($services, 'mode'),
    array_keys($taskModes),
    array_keys($photoModes),
    array_keys($audioModes),
), true);
$asyncPackModes = array_filter(
    hub_pack_job_async_routes(),
    static fn (array $route, string $mode): bool => !isset($shownModes[$mode]),
    ARRAY_FILTER_USE_BOTH,
);
$shownModes += array_fill_keys(array_keys($asyncPackModes), true);
$routerModes = array_values(array_filter(
    hub_cluster_router_available_modes($db),
    static fn (string $mode): bool => !isset($shownModes[$mode]),
));

hub_admin_header('Token Mode 權限', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<section class="panel">
    <h1>Token Mode 權限</h1>
    <p><?= hub_h($token['member_name']) ?> / <?= hub_h($token['token_name']) ?> / <code><?= hub_h(hub_mask_api_token($token)) ?></code></p>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
        <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
        <?php foreach ($services as $service): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($service['mode']) ?>"<?= in_array($service['mode'], $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($service['mode']) ?></code> <?= hub_h($service['name']) ?></label>
        <?php endforeach; ?>
        <h2>系統任務 Mode</h2>
        <?php foreach ($taskModes as $mode => $label): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
        <?php endforeach; ?>
        <h2>Photo Vision Mode（圖片理解）</h2>
        <?php foreach ($photoModes as $mode => $label): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
        <?php endforeach; ?>
        <h2>Audio Mode（音訊理解）</h2>
        <?php foreach ($audioModes as $mode => $label): ?>
            <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h($label) ?></label>
        <?php endforeach; ?>
        <?php if ($asyncPackModes !== []): ?>
            <h2>Pack 非同步任務 Mode</h2>
            <?php foreach ($asyncPackModes as $mode => $route): ?>
                <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code> <?= hub_h((string)$route['pack_id']) ?> / <?= hub_h((string)$route['job']) ?></label>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($routerModes !== []): ?>
            <h2>Cluster Router Mode</h2>
            <?php foreach ($routerModes as $mode): ?>
                <label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $enabledModes, true) ? ' checked' : '' ?>> <code><?= hub_h($mode) ?></code></label>
            <?php endforeach; ?>
        <?php endif; ?>
        <p><button class="primary" type="submit">儲存</button> <a class="button" href="api_tokens.php?member_id=<?= (int)$token['member_id'] ?>">返回 Token 列表</a></p>
    </form>
</section>
<?php hub_admin_footer(); ?>
