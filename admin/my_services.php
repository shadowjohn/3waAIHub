<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_my_services_endpoint(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $gateway = is_array($pack['manifest']['gateway'] ?? null) ? $pack['manifest']['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);

    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

function hub_my_services_upload_label(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $gateway = is_array($pack['manifest']['gateway'] ?? null) ? $pack['manifest']['gateway'] : [];

    return (int)($gateway['max_upload_mb'] ?? 0) > 0 ? __('需要 / 支援上傳') : __('不需要');
}

$db = hub_db();
hub_migrate($db);
$user = hub_require_customer_or_admin($db);
$services = hub_is_system_admin($user) ? hub_list_services($db) : hub_user_allowed_services($db, (int)$user['id']);
$tokens = hub_list_customer_tokens($db, (int)$user['id']);

hub_admin_header(__('我的服務'), $user);
?>
<section class="panel">
    <h1><?= hub_h(__('我的服務')) ?></h1>
    <p class="muted"><?= hub_h(__('這裡只顯示你被授權可使用的 API mode。內部主機與 runtime 細節不會顯示。')) ?></p>
</section>

<section class="hub-card-grid">
<?php if ($services === []): ?>
    <div class="hub-empty-state"><?= hub_h(__('目前尚未指派可用服務，請聯絡系統管理員。')) ?></div>
<?php endif; ?>
<?php foreach ($services as $service): ?>
    <?php
    $mode = (string)$service['mode'];
    $pack = hub_get_pack((string)$service['pack_id']);
    $hasTokenPermission = false;
    foreach ($tokens as $token) {
        if (hub_api_token_mode_allowed($db, (int)$token['id'], $mode)) {
            $hasTokenPermission = true;
            break;
        }
    }
    ?>
    <article class="hub-card">
        <h2><?= hub_h((string)$service['name']) ?></h2>
        <div class="hub-meta">
            <div class="hub-meta-label">mode</div><div class="hub-meta-value"><code><?= hub_h($mode) ?></code></div>
            <div class="hub-meta-label">pack_id</div><div class="hub-meta-value"><code><?= hub_h((string)$service['pack_id']) ?></code></div>
            <div class="hub-meta-label">runtime_level</div><div class="hub-meta-value"><code><?= hub_h((string)($pack['manifest']['runtime_level'] ?? '')) ?></code></div>
            <div class="hub-meta-label">endpoint</div><div class="hub-meta-value"><code><?= hub_h(hub_my_services_endpoint($service)) ?></code></div>
            <div class="hub-meta-label"><?= hub_h(__('檔案上傳')) ?></div><div class="hub-meta-value"><?= hub_h(hub_my_services_upload_label($service)) ?></div>
            <div class="hub-meta-label">real_inference</div><div class="hub-meta-value"><?= hub_h((string)($pack['manifest']['runtime_level'] ?? '') >= 'L4' ? __('支援或預留') : __('未提供')) ?></div>
            <div class="hub-meta-label"><?= hub_h(__('Token 權限')) ?></div><div class="hub-meta-value"><?= $hasTokenPermission ? '<span class="ok">' . hub_h(__('已有可用 Token')) . '</span>' : '<span class="bad">' . hub_h(__('尚無可用 Token')) . '</span>' ?></div>
        </div>
        <div class="hub-actions">
            <a class="button" href="playground.php?mode=<?= urlencode($mode) ?>"><?= hub_h(__('到 API 測試場')) ?></a>
            <a class="button" href="../public_api_docs.php"><?= hub_h(__('API 文件')) ?></a>
        </div>
    </article>
<?php endforeach; ?>
</section>
<?php hub_admin_footer(); ?>
