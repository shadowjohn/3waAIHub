<?php
declare(strict_types=1);

function hub_admin_nav_group(string $script): string
{
    return match ($script) {
        'index.php' => 'dashboard',
        'marketplace.php', 'packs.php', 'models.php', 'services.php' => 'market',
        'customers.php', 'customer_edit.php' => 'customers',
        'api_members.php', 'api_member_edit.php', 'api_tokens.php',
        'api_token_permissions.php', 'api_token_whitelist.php' => 'keys',
        'cluster.php' => 'cluster',
        'playground.php', 'api_docs.php', 'benchmarks.php' => 'testing',
        'log_explorer.php', 'api_usage.php', 'runtime_runs.php', 'runtime_run.php',
        'service_logs.php', 'log_detail.php' => 'records',
        'environment.php' => 'environment',
        'settings.php', 'i18n.php' => 'settings',
        default => '',
    };
}

function hub_admin_top_navigation(): array
{
    return [
        ['key' => 'dashboard', 'label' => '控制台', 'href' => 'index.php'],
        ['key' => 'market', 'label' => '安裝套件', 'href' => 'marketplace.php'],
        ['key' => 'customers', 'label' => '客戶管理', 'href' => 'customers.php'],
        ['key' => 'keys', 'label' => 'API 金鑰', 'href' => 'api_members.php'],
        ['key' => 'cluster', 'label' => 'Cluster 管理', 'href' => 'cluster.php'],
        ['key' => 'testing', 'label' => '測試中心', 'children' => [
            ['label' => 'API 測試場', 'href' => 'playground.php'],
            ['label' => 'API 文件', 'href' => 'api_docs.php'],
            ['label' => 'Benchmark 測試', 'href' => 'benchmarks.php'],
        ]],
        ['key' => 'records', 'label' => '記錄中心', 'children' => [
            ['label' => '執行歷程', 'href' => 'log_explorer.php?tab=runs'],
            ['label' => 'API 記錄', 'href' => 'log_explorer.php?tab=api'],
            ['label' => '背景工作', 'href' => 'log_explorer.php?tab=jobs'],
            ['label' => '服務記錄', 'href' => 'log_explorer.php?tab=service'],
            ['label' => '系統記錄', 'href' => 'log_explorer.php?tab=system'],
        ]],
        ['key' => 'environment', 'label' => '系統環境', 'href' => 'environment.php'],
        ['key' => 'settings', 'label' => '系統設定', 'href' => 'settings.php'],
    ];
}

function hub_admin_header(string $title, array $user): void
{
    $siteTitle = hub_site_title();
    $siteSubtitle = hub_site_subtitle();
    $isAdmin = hub_is_system_admin($user);
    $showClusterDocs = !$isAdmin && hub_cluster_router_enabled(hub_db());
    $script = basename((string)(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: ''));
    $activeGroup = hub_admin_nav_group($script);
    $topLabels = [
        'dashboard' => hub_i18n_text('控制台'),
        'market' => hub_i18n_text('安裝套件'),
        'customers' => hub_i18n_text('客戶管理'),
        'keys' => hub_i18n_text('API 金鑰'),
        'cluster' => hub_i18n_text('Cluster 管理'),
        'testing' => hub_i18n_text('測試中心'),
        'records' => hub_i18n_text('記錄中心'),
        'environment' => hub_i18n_text('系統環境'),
        'settings' => hub_i18n_text('系統設定'),
    ];
    $navIcons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7.5" height="8.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.5"/><rect x="13.5" y="11" width="7.5" height="10" rx="1.5"/><rect x="3" y="14.5" width="7.5" height="6.5" rx="1.5"/></svg>',
        'market' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 8.5 12 13 3 8.5 12 4l9 4.5Z"/><path d="M3 8.5v7L12 20l9-4.5v-7"/><path d="M12 13v7"/></svg>',
        'customers' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"/><circle cx="9" cy="7" r="3.5"/><path d="M22 20v-1.5a4 4 0 0 0-3-3.85"/><path d="M16.5 3.8a3.5 3.5 0 0 1 0 6.4"/></svg>',
        'keys' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="4"/><path d="m10.5 12.5 8-8"/><path d="m15.5 7.5 2.5 2.5"/><path d="m18.5 4.5 2.5 2.5"/></svg>',
        'cluster' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3.5" width="18" height="6" rx="2"/><rect x="3" y="14.5" width="18" height="6" rx="2"/><path d="M7 6.5h.01M7 17.5h.01"/><path d="M12 9.5v5"/></svg>',
        'testing' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 3v6.2L4.6 18a2 2 0 0 0 1.7 3h11.4a2 2 0 0 0 1.7-3l-4.9-8.8V3"/><path d="M8 3h8"/><path d="M7.4 15h9.2"/></svg>',
        'records' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h9l4 4v14a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/><path d="M8.5 13h7M8.5 17h5"/></svg>',
        'environment' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="7.5" y="7.5" width="9" height="9" rx="1.5"/><path d="M10 2.5v4M14 2.5v4M10 17.5v4M14 17.5v4M2.5 10h4M2.5 14h4M17.5 10h4M17.5 14h4"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3.2"/><path d="M19 15.5a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-2.9 1.2v.1h-4v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1-2.8-2.8.1-.1a1.7 1.7 0 0 0-1.2-2.9h-.1v-4h.1a1.7 1.7 0 0 0 1.2-2.9l-.1-.1 2.8-2.8.1.1A1.7 1.7 0 0 0 9.6 3.6v-.1h4v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1 2.8 2.8-.1.1a1.7 1.7 0 0 0 1.2 2.9h.1v4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>',
    ];
    $customerNavigation = [
        ['label' => hub_i18n_text('我的服務'), 'href' => 'my_services.php'],
        ['label' => hub_i18n_text('我的 Token'), 'href' => 'my_tokens.php'],
        ['label' => hub_i18n_text('IP 白名單'), 'href' => 'my_ip_whitelist.php'],
        ['label' => hub_i18n_text('我的用量'), 'href' => 'my_usage.php', 'title' => hub_i18n_text('用量統計')],
        ['label' => hub_i18n_text('帳號資料'), 'href' => 'my_profile.php'],
        ['label' => hub_i18n_text('變更密碼'), 'href' => 'change_password.php'],
        ['label' => hub_i18n_text('API 測試場'), 'href' => 'playground.php'],
        ['label' => hub_i18n_text('API 文件'), 'href' => '../public_api_docs.php'],
    ];
    if ($showClusterDocs) {
        $customerNavigation[] = ['label' => 'Cluster API 文件', 'href' => '../cluster_public_api_docs.php'];
    }
    $username = (string)($user['username'] ?? '');
    $avatar = strtoupper(substr($username, 0, 2));
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= hub_h(hub_i18n_text($title)) ?> - <?= hub_h($siteTitle) ?></title>
    <link rel="icon" href="../branding_asset.php">
    <link rel="stylesheet" href="../assets/css/admin-base.css">
    <link rel="stylesheet" href="../assets/css/admin-shell.css">
</head>
<body class="app">
<a class="skip-link" href="#main-content" data-drawer-inert><?= hub_h(hub_i18n_text('跳至主要內容')) ?></a>
<header class="appbar">
    <div class="appbar__row">
        <a class="appbrand" href="<?= $isAdmin ? 'index.php' : 'my_services.php' ?>" data-drawer-inert>
            <img class="appbrand__logo" src="../branding_asset.php" width="40" height="40" alt="<?= hub_h($siteTitle . ' ' . hub_i18n_text('標誌')) ?>">
            <span class="appbrand__name"><?= hub_h($siteTitle) ?><small class="appbrand__sub"><?= hub_h($siteSubtitle) ?></small></span>
        </a>
        <nav class="mainnav" id="mainnav" aria-label="<?= hub_h(hub_i18n_text('主選單')) ?>">
            <button type="button" class="iconbtn navclose" id="nav-close" aria-label="<?= hub_h(hub_i18n_text('關閉主選單')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
            <ul class="mainnav__list">
        <?php if ($isAdmin): ?>
            <?php foreach (hub_admin_top_navigation() as $item):
                $isActive = $activeGroup === $item['key'];
                $label = $topLabels[$item['key']];
                $buttonId = 'nav-' . $item['key'] . '-button';
                $menuId = 'nav-' . $item['key'] . '-menu';
                ?>
                <li class="mainnav__item">
                    <?php if (isset($item['children'])): ?>
                        <button type="button" class="mainnav__link<?= $isActive ? ' is-active' : '' ?>" id="<?= hub_h($buttonId) ?>"
                                data-top-nav="<?= hub_h($item['label']) ?>" aria-expanded="false" aria-haspopup="true"
                                aria-controls="<?= hub_h($menuId) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <?= $navIcons[$item['key']] ?>
                            <span><?= hub_h($label) ?></span>
                            <svg class="mainnav__caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <ul class="submenu" id="<?= hub_h($menuId) ?>" hidden>
                            <?php foreach ($item['children'] as $child): ?>
                                <li><a class="submenu__link" href="<?= hub_h($child['href']) ?>"><?= hub_h(hub_i18n_text($child['label'])) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <a class="mainnav__link<?= $isActive ? ' is-active' : '' ?>" href="<?= hub_h($item['href']) ?>"
                           data-top-nav="<?= hub_h($item['label']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <?= $navIcons[$item['key']] ?>
                            <span><?= hub_h($label) ?></span>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach ($customerNavigation as $item):
                $isActive = basename((string)(parse_url($item['href'], PHP_URL_PATH) ?: '')) === $script;
                ?>
                <li class="mainnav__item">
                    <a class="mainnav__link<?= $isActive ? ' is-active' : '' ?>" href="<?= hub_h($item['href']) ?>"
                       data-top-nav="<?= hub_h($item['label']) ?>"<?= isset($item['title']) ? ' title="' . hub_h($item['title']) . '"' : '' ?><?= $isActive ? ' aria-current="page"' : '' ?>>
                        <span><?= hub_h($item['label']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
            </ul>
        </nav>
        <div class="appbar__actions" data-drawer-inert>
            <?= hub_i18n_language_selector() ?>
            <div class="usermenu">
                <button type="button" class="usermenu__btn" id="user-button" aria-expanded="false" aria-haspopup="true" aria-controls="user-menu">
                    <span class="usermenu__avatar" aria-hidden="true"><?= hub_h($avatar) ?></span>
                    <span class="usermenu__meta">
                        <span class="usermenu__name"><?= hub_h($username) ?></span>
                        <span class="usermenu__role"><?= hub_h($isAdmin ? hub_i18n_text('系統管理員') : hub_i18n_text('客戶')) ?></span>
                    </span>
                    <svg class="usermenu__caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <ul class="dropdown" id="user-menu" hidden>
                    <li><a class="dropdown__link" href="my_profile.php"><?= hub_h(hub_i18n_text('個人資料')) ?></a></li>
                    <li><a class="dropdown__link" href="change_password.php"><?= hub_h(hub_i18n_text('變更密碼')) ?></a></li>
                    <li><div class="dropdown__sep" role="separator"></div></li>
                    <li><a class="dropdown__link dropdown__link--danger" href="logout.php"><?= hub_h(hub_i18n_text('登出')) ?></a></li>
                </ul>
            </div>
            <button type="button" class="iconbtn navtoggle" id="nav-toggle" aria-expanded="false" aria-controls="mainnav" aria-label="<?= hub_h(hub_i18n_text('開啟主選單')) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>
    </div>
</header>
<main class="shell" id="main-content" data-drawer-inert>
<?php if ((int)$user['must_change_password'] === 1): ?>
    <div class="notice"><?= hub_h(hub_i18n_text('預設密碼仍在使用中，請到「設定」修改密碼。')) ?></div>
<?php endif; ?>
    <?php
}

function hub_admin_footer(): void
{
    ?>
</main>
<footer class="appfoot" data-drawer-inert>
    <span><?= hub_h(hub_site_subtitle()) ?></span>
    <span class="dot" aria-hidden="true">·</span>
    <span><?= hub_h(HUB_VERSION . ' / ' . HUB_RELEASE_LABEL) ?></span>
</footer>
<script src="../assets/js/admin-shell.js"></script>
</body>
</html>
    <?php
}

function hub_status_class(string $status): string
{
    return in_array($status, ['running', 'success', 'ok', 'pass'], true) ? 'ok' : 'bad';
}

function hub_status_label(string $status): string
{
    return [
        'running' => '執行中',
        'succeeded' => '成功',
        'stopped' => '已停止',
        'success' => '成功',
        'failed' => '失敗',
        'queued' => '排隊中',
        'running_job' => '執行中',
        'error' => '錯誤',
        'ok' => '正常',
        'pass' => '通過',
        'fail' => '失敗',
    ][$status] ?? $status;
}
