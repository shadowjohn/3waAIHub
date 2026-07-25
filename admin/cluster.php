<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
hub_start_session();

$view = (string)($_GET['view'] ?? 'roles');
$view = $view === 'usage' ? 'usage' : 'roles';
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        if (!hub_is_system_admin($user)) {
            throw new RuntimeException('System admin required.');
        }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_roles') {
            $nodeEnabled = ($_POST['node_enabled'] ?? '') === '1';
            $routerEnabled = ($_POST['router_enabled'] ?? '') === '1';
            $configured = hub_cluster_node_configure($db, $nodeEnabled, hub_cluster_node_selected_published_modes($db));
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', $routerEnabled ? '1' : '0');
            if (isset($configured['invite']) && is_string($configured['invite'])) {
                $_SESSION['hub_cluster_pair_invite'] = $configured['invite'];
            }
            if (!$nodeEnabled) {
                unset($_SESSION['hub_cluster_pair_invite']);
            }
            $message = 'Cluster 角色已更新。';
        } elseif ($action === 'save_child_modes') {
            $modes = $_POST['modes'] ?? [];
            if (!is_array($modes) || !hub_cluster_node_enabled($db)) {
                throw new InvalidArgumentException('子入口節點未啟用。');
            }
            $configured = hub_cluster_node_configure($db, true, $modes);
            if (isset($configured['invite']) && is_string($configured['invite'])) {
                $_SESSION['hub_cluster_pair_invite'] = $configured['invite'];
            }
            $message = '可供應服務已更新。';
        } elseif ($action === 'regenerate_node_token') {
            $configured = hub_cluster_node_regenerate_token($db);
            $_SESSION['hub_cluster_pair_invite'] = (string)$configured['invite'];
            $message = '子節點 Token 已重新產生。';
        } elseif ($action === 'renew_invitation') {
            if (!hub_cluster_node_enabled($db)) {
                throw new InvalidArgumentException('子入口節點未啟用。');
            }
            $invitation = hub_cluster_create_pair_invitation($db);
            $_SESSION['hub_cluster_pair_invite'] = (string)$invitation['invite'];
            $message = '配對邀請已更新。';
        } elseif ($action === 'pair_child') {
            $pairingLink = $_POST['pairing_link'] ?? null;
            if (!is_string($pairingLink)) {
                throw new InvalidArgumentException('配對連結無效。');
            }
            $station = hub_cluster_import_pairing_link($db, $pairingLink);
            $message = '已新增子節點：' . (string)$station['display_name'];
        } elseif ($action === 'toggle_station' || $action === 'refresh_station') {
            $stationId = $_POST['station_id'] ?? null;
            if (!is_string($stationId) || preg_match('/\A[1-9]\d*\z/', $stationId) !== 1) {
                throw new InvalidArgumentException('子節點不存在。');
            }
            $station = hub_cluster_get_station($db, (int)$stationId);
            if ($station === null) {
                throw new InvalidArgumentException('子節點不存在。');
            }
            if ($action === 'toggle_station') {
                $enabled = ($_POST['enabled'] ?? '') === '1';
                $db->prepare('UPDATE cluster_stations SET enabled = :enabled, updated_at = :updated_at WHERE id = :id')
                    ->execute([':enabled' => $enabled ? 1 : 0, ':updated_at' => hub_now(), ':id' => (int)$station['id']]);
                $message = $enabled ? '子節點已啟用。' : '子節點已停用。';
            } else {
                $refreshed = hub_cluster_refresh_station_now($db, $station, true, null);
                if (!empty($refreshed['last_error']) || empty($refreshed['fresh'])) {
                    throw new RuntimeException('子節點庫存重新整理失敗。');
                }
                $message = '子節點庫存已重新整理。';
            }
        } else {
            throw new InvalidArgumentException('未知操作。');
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$nodeEnabled = hub_cluster_node_enabled($db);
$routerEnabled = hub_cluster_router_enabled($db);
$selectedModes = hub_cluster_node_selected_published_modes($db);
$publishedModes = hub_cluster_node_published_modes($db);
$routerName = hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME');
$pairingInvite = $_SESSION['hub_cluster_pair_invite'] ?? '';
$pairingInviteNeedsRenewal = false;
if (!is_string($pairingInvite) || !hub_cluster_pair_invitation_is_current($db, $pairingInvite)) {
    unset($_SESSION['hub_cluster_pair_invite']);
    $pairingInvite = '';
    $pairingInviteNeedsRenewal = $nodeEnabled && $routerName === '';
}
$pairingLink = '';
$nodeToken = '';
if ($nodeEnabled) {
    try {
        $nodeToken = hub_cluster_node_reveal_token($db);
    } catch (Throwable) {
        $nodeToken = '';
    }
    if ($routerName === '' && $pairingInvite !== '') {
        $descriptor = hub_cluster_node_pairing_descriptor($db);
        $pairingLink = rtrim((string)$descriptor['public_base_url'], '/') . '/cluster_pair.php#invite=' . rawurlencode($pairingInvite);
    }
}

$stationId = 0;
if (is_string($_GET['station_id'] ?? null) && preg_match('/\A[1-9]\d*\z/', $_GET['station_id']) === 1) {
    $stationId = (int)$_GET['station_id'];
}
$stationRows = $view === 'roles' && $routerEnabled ? hub_cluster_station_dashboard_rows($db) : [];
$stationDetail = null;
foreach ($stationRows as $stationRow) {
    if ((int)$stationRow['id'] === $stationId) {
        $stationDetail = $stationRow;
        break;
    }
}
$stationRoutes = $stationDetail === null ? [] : hub_cluster_recent_routes($db, ['station_id' => (int)$stationDetail['id']], 20);

$usageFilters = [
    'member_id' => is_string($_GET['member_id'] ?? null) ? trim($_GET['member_id']) : '',
    'token_id' => is_string($_GET['token_id'] ?? null) ? trim($_GET['token_id']) : '',
    'station_id' => is_string($_GET['station_id'] ?? null) ? trim($_GET['station_id']) : '',
    'mode' => is_string($_GET['mode'] ?? null) ? trim($_GET['mode']) : '',
];
$usageSummary = [];
$usageRows = [];
$usageMembers = [];
$usageTokens = [];
$usageStations = [];
$usageModes = [];
if ($view === 'usage') {
    try {
        $usageSummary = hub_cluster_usage_summary($db, $usageFilters);
        $usageRows = hub_cluster_usage_rows($db, $usageFilters);
        $usageMembers = hub_list_api_members($db);
        $usageTokens = hub_list_all_api_tokens($db);
        $usageStations = hub_cluster_station_dashboard_rows($db);
        $usageModes = $db->query("SELECT DISTINCT mode FROM cluster_route_accesses WHERE mode <> '' ORDER BY mode ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
        $usageSummary = ['work_requests' => 0, 'accesses' => 0, 'success_count' => 0, 'failed_count' => 0, 'active_routes' => 0, 'peak_concurrency' => 0, 'upload_bytes' => 0, 'response_bytes' => 0];
    }
}

hub_admin_header('Cluster', $user);
?>
<style>
    .cluster-filter-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
    .cluster-summary { margin: 16px 0; }
    .cluster-summary strong { display: block; font-size: 22px; margin-top: 4px; }
    .cluster-table-wrap { overflow-x: auto; }
    .cluster-num { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }
</style>
<div class="hub-tabs">
    <a class="button<?= $view === 'roles' ? ' primary' : '' ?>" href="cluster.php">角色與節點</a>
    <a class="button<?= $view === 'usage' ? ' primary' : '' ?>" href="cluster.php?view=usage">Cluster 用量</a>
</div>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>

<?php if ($view === 'usage'): ?>
    <section class="panel">
        <h1>Cluster 用量</h1>
        <form method="get">
            <input type="hidden" name="view" value="usage">
            <div class="cluster-filter-grid">
                <div><label>API 會員</label><select name="member_id"><option value="">全部</option><?php foreach ($usageMembers as $member): ?><option value="<?= (int)$member['id'] ?>"<?= $usageFilters['member_id'] === (string)$member['id'] ? ' selected' : '' ?>><?= hub_h((string)$member['name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Token</label><select name="token_id"><option value="">全部</option><?php foreach ($usageTokens as $token): ?><option value="<?= (int)$token['id'] ?>"<?= $usageFilters['token_id'] === (string)$token['id'] ? ' selected' : '' ?>><?= hub_h((string)$token['member_name'] . ' / ' . (string)$token['token_name'] . ' / ' . (string)$token['token_prefix']) ?></option><?php endforeach; ?></select></div>
                <div><label>子節點</label><select name="station_id"><option value="">全部</option><?php foreach ($usageStations as $station): ?><option value="<?= (int)$station['id'] ?>"<?= $usageFilters['station_id'] === (string)$station['id'] ? ' selected' : '' ?>><?= hub_h((string)$station['display_name']) ?></option><?php endforeach; ?></select></div>
                <div><label>Mode</label><select name="mode"><option value="">全部</option><?php foreach ($usageModes as $mode): ?><option value="<?= hub_h((string)$mode) ?>"<?= $usageFilters['mode'] === (string)$mode ? ' selected' : '' ?>><?= hub_h((string)$mode) ?></option><?php endforeach; ?></select></div>
            </div>
            <p><button class="primary" type="submit">查詢</button> <a class="button" href="cluster.php?view=usage">清除</a></p>
        </form>
    </section>
    <div class="hub-card-grid cluster-summary">
        <article class="hub-card"><span class="muted">工作請求</span><strong><?= number_format((int)($usageSummary['work_requests'] ?? 0)) ?></strong></article>
        <article class="hub-card"><span class="muted">存取</span><strong><?= number_format((int)($usageSummary['accesses'] ?? 0)) ?></strong></article>
        <article class="hub-card"><span class="muted">成功 / 失敗</span><strong><span class="ok"><?= number_format((int)($usageSummary['success_count'] ?? 0)) ?></span> / <span class="bad"><?= number_format((int)($usageSummary['failed_count'] ?? 0)) ?></span></strong></article>
        <article class="hub-card"><span class="muted">作用中路由</span><strong><?= number_format((int)($usageSummary['active_routes'] ?? 0)) ?></strong></article>
        <article class="hub-card"><span class="muted">峰值併發</span><strong><?= number_format((int)($usageSummary['peak_concurrency'] ?? 0)) ?></strong></article>
        <article class="hub-card"><span class="muted">上傳 / 回應容量</span><strong><?= hub_h(hub_model_format_bytes((int)($usageSummary['upload_bytes'] ?? 0))) ?> / <?= hub_h(hub_model_format_bytes((int)($usageSummary['response_bytes'] ?? 0))) ?></strong></article>
    </div>
    <section class="panel">
        <h2>帳號 / Token / 子節點</h2>
        <?php if ($usageRows === []): ?>
            <div class="hub-empty-state">目前沒有符合條件的 Cluster 用量紀錄。</div>
        <?php else: ?>
            <div class="cluster-table-wrap"><table><thead><tr><th>API 會員</th><th>Token</th><th>子節點</th><th class="cluster-num">工作</th><th class="cluster-num">存取</th><th class="cluster-num">成功</th><th class="cluster-num">失敗</th><th class="cluster-num">上傳</th><th class="cluster-num">回應</th></tr></thead><tbody><?php foreach ($usageRows as $row): ?><tr><td><?= hub_h((string)($row['member_name'] ?? '')) ?></td><td><code><?= hub_h((string)($row['token_prefix'] ?? '')) ?></code> <?= hub_h((string)($row['token_name'] ?? '')) ?></td><td><?= hub_h((string)($row['station_name'] ?? '')) ?></td><td class="cluster-num"><?= number_format((int)$row['work_requests']) ?></td><td class="cluster-num"><?= number_format((int)$row['accesses']) ?></td><td class="cluster-num ok"><?= number_format((int)$row['success_count']) ?></td><td class="cluster-num bad"><?= number_format((int)$row['failed_count']) ?></td><td class="cluster-num"><?= hub_h(hub_model_format_bytes((int)$row['upload_bytes'])) ?></td><td class="cluster-num"><?= hub_h(hub_model_format_bytes((int)$row['response_bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <h1>Cluster 角色</h1>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
            <label><input type="checkbox" name="node_enabled" value="1"<?= $nodeEnabled ? ' checked' : '' ?>> 子入口節點</label>
            <label><input type="checkbox" name="router_enabled" value="1"<?= $routerEnabled ? ' checked' : '' ?>> 統一入口</label>
            <p><button class="primary" name="action" value="save_roles" type="submit">儲存角色</button></p>
        </form>
    </section>

    <?php if ($nodeEnabled): ?>
        <section class="panel">
            <h2>子入口節點</h2>
            <label>子節點 Token</label>
            <input readonly value="<?= hub_h($nodeToken) ?>" onclick="this.select();">
            <?php if ($pairingInviteNeedsRenewal): ?><p class="notice">配對邀請已失效，請更新配對邀請。</p><?php endif; ?>
            <?php if ($pairingLink !== ''): ?><label>配對連結</label><input readonly value="<?= hub_h($pairingLink) ?>" onclick="this.select();"><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                <label>可供應服務</label>
                <?php if ($publishedModes === []): ?><p class="muted">目前沒有已安裝、啟用且執行中的服務。</p><?php endif; ?>
                <?php foreach ($publishedModes as $mode): ?><label><input type="checkbox" name="modes[]" value="<?= hub_h($mode) ?>"<?= in_array($mode, $selectedModes, true) ? ' checked' : '' ?>> <?= hub_h($mode) ?></label><?php endforeach; ?>
                <p><button class="primary" name="action" value="save_child_modes" type="submit">儲存服務</button></p>
            </form>
            <div class="hub-actions">
                <form method="post"><input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>"><button class="danger" name="action" value="regenerate_node_token" type="submit">重新產生 Token</button></form>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>"><button name="action" value="renew_invitation" type="submit">更新配對邀請</button></form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($routerEnabled): ?>
        <section class="panel">
            <h2>新增子節點</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                <label>子節點配對連結</label>
                <textarea name="pairing_link" required></textarea>
                <p><button class="primary" name="action" value="pair_child" type="submit">新增子節點</button></p>
            </form>
        </section>
        <?php if ($stationDetail !== null): ?>
            <section class="panel">
                <h2><?= hub_h((string)$stationDetail['display_name']) ?></h2>
                <div class="hub-meta">
                    <span class="hub-meta-label">Public Base</span><span class="hub-meta-value"><?= hub_h((string)$stationDetail['public_base_url']) ?></span>
                    <span class="hub-meta-label">Internal Base</span><span class="hub-meta-value"><?= hub_h((string)$stationDetail['internal_base_url']) ?></span>
                    <span class="hub-meta-label">優先度</span><span class="hub-meta-value"><?= (int)$stationDetail['priority'] ?></span>
                    <span class="hub-meta-label">Router Station Token</span><span class="hub-meta-value"><?= !empty($stationDetail['token_configured']) ? '已設定' : '未設定' ?></span>
                    <span class="hub-meta-label">Manifest / Status</span><span class="hub-meta-value"><?= hub_h((string)$stationDetail['manifest_fetched_at']) ?> / <?= hub_h((string)$stationDetail['status_fetched_at']) ?></span>
                    <span class="hub-meta-label">Refresh Error</span><span class="hub-meta-value"><?= hub_h((string)$stationDetail['last_error']) ?></span>
                </div>
                <h3>Mode Readiness</h3>
                <table><thead><tr><th>Mode</th><th>Ready</th></tr></thead><tbody><?php foreach ($stationDetail['mode_readiness'] as $mode): ?><tr><td><code><?= hub_h((string)$mode['mode']) ?></code></td><td class="<?= $mode['ready'] ? 'ok' : 'bad' ?>"><?= $mode['ready'] ? '是' : '否' ?></td></tr><?php endforeach; ?></tbody></table>
                <h3>Recent Routes</h3>
                <div class="cluster-table-wrap"><table><thead><tr><th>Route</th><th>Mode</th><th>狀態</th><th>API 會員</th><th>Token</th><th>建立時間</th><th>完成時間</th></tr></thead><tbody><?php foreach ($stationRoutes as $route): ?><tr><td><code><?= hub_h((string)$route['route_id']) ?></code></td><td><code><?= hub_h((string)$route['mode']) ?></code></td><td><?= hub_h((string)$route['state']) ?></td><td><?= hub_h((string)$route['member_name']) ?></td><td><code><?= hub_h((string)$route['token_prefix']) ?></code> <?= hub_h((string)$route['token_name']) ?></td><td><?= hub_h((string)$route['created_at']) ?></td><td><?= hub_h((string)$route['completed_at']) ?></td></tr><?php endforeach; ?></tbody></table></div>
            </section>
        <?php endif; ?>
        <section>
            <div class="hub-section-title"><h2>子節點</h2><span class="muted"><?= count($stationRows) ?> 個</span></div>
            <?php if ($stationRows === []): ?><div class="hub-empty-state">目前沒有已配對的子節點。</div><?php else: ?><div class="hub-card-grid"><?php foreach ($stationRows as $station): ?><article class="hub-card"><h2><?= hub_h((string)$station['display_name']) ?></h2><div class="hub-meta"><span class="hub-meta-label">啟用</span><span class="hub-meta-value <?= $station['enabled'] ? 'ok' : 'bad' ?>"><?= $station['enabled'] ? '是' : '否' ?></span><span class="hub-meta-label">新鮮度</span><span class="hub-meta-value <?= $station['fresh'] ? 'ok' : 'bad' ?>"><?= $station['fresh'] ? '正常' : '過期' ?></span><span class="hub-meta-label">VRAM</span><span class="hub-meta-value"><?= number_format((int)$station['gpu_free_vram_mb']) ?> / <?= number_format((int)$station['gpu_total_vram_mb']) ?> MB</span><span class="hub-meta-label">GPU Lease</span><span class="hub-meta-value"><?= number_format((int)$station['active_gpu_leases']) ?></span><span class="hub-meta-label">排隊 / 執行</span><span class="hub-meta-value"><?= number_format((int)$station['queued_jobs']) ?> / <?= number_format((int)$station['running_jobs']) ?></span><span class="hub-meta-label">已發佈 Mode</span><span class="hub-meta-value"><?= count($station['modes']) ?></span><span class="hub-meta-label">作用中 Router 路由</span><span class="hub-meta-value"><?= number_format((int)$station['active_route_count']) ?></span></div><div class="hub-actions"><a class="button" href="cluster.php?station_id=<?= (int)$station['id'] ?>">查看詳情</a><form method="post"><input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>"><input type="hidden" name="station_id" value="<?= (int)$station['id'] ?>"><input type="hidden" name="enabled" value="<?= $station['enabled'] ? '0' : '1' ?>"><button name="action" value="toggle_station" type="submit"><?= $station['enabled'] ? '停用' : '啟用' ?></button></form><form method="post"><input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>"><input type="hidden" name="station_id" value="<?= (int)$station['id'] ?>"><button name="action" value="refresh_station" type="submit">重新整理</button></form></div></article><?php endforeach; ?></div><?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
<?php hub_admin_footer(); ?>
