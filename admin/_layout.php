<?php
declare(strict_types=1);

function hub_admin_header(string $title, array $user): void
{
    $siteTitle = hub_site_title();
    $siteSubtitle = hub_site_subtitle();
    $isAdmin = hub_is_system_admin($user);
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hub_h(__($title)) ?> - <?= hub_h($siteTitle) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        :root { color-scheme: light; --bg: #f6f7f9; --panel: #fff; --line: #d9dee7; --text: #1d2430; --muted: #667085; --blue: #1769e0; --red: #b42318; --green: #067647; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        header { background: #182230; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; gap: 18px; }
        .brand small { color: #98a2b3; display: block; font-size: 12px; font-weight: 500; margin-top: 2px; }
        nav a { color: #d0d5dd; margin-right: 16px; text-decoration: none; }
        nav a:hover { color: #fff; }
        .i18n-selector select { background: #101828; border-color: #344054; color: #fff; margin-right: 16px; padding: 5px 8px; width: auto; }
        main { max-width: 1120px; margin: 24px auto; padding: 0 16px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { border-bottom: 1px solid var(--line); padding: 10px; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 13px; }
        button, .button { border: 1px solid var(--line); background: #fff; border-radius: 6px; color: var(--text); cursor: pointer; display: inline-block; font: inherit; padding: 7px 11px; text-decoration: none; }
        button.primary { background: var(--blue); border-color: var(--blue); color: #fff; }
        button.danger { border-color: #fecdca; color: var(--red); }
        input, select, textarea { border: 1px solid var(--line); border-radius: 6px; box-sizing: border-box; font: inherit; padding: 8px 10px; width: 100%; }
        input[type="checkbox"] { width: auto; }
        label { display: block; font-weight: 600; margin: 12px 0 6px; }
        pre { background: #101828; color: #f2f4f7; overflow: auto; padding: 12px; border-radius: 6px; white-space: pre-wrap; }
        .inline-pre { margin: 0; }
        .muted { color: var(--muted); }
        .ok { color: var(--green); font-weight: 700; }
        .bad { color: var(--red); font-weight: 700; }
        .reason { color: var(--red); font-weight: 700; margin-top: 4px; }
        .actions form { display: inline; margin-right: 4px; }
        .notice { background: #fff8db; border: 1px solid #f3cc62; border-radius: 8px; margin-bottom: 16px; padding: 12px; }
        .error { background: #fff1f0; border: 1px solid #fecdca; border-radius: 8px; margin-bottom: 16px; padding: 12px; }
        .hub-card-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .hub-card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 16px; }
        .hub-card h2, .hub-card h3 { margin-top: 0; }
        .hub-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
        .hub-badge { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 700; padding: 3px 8px; }
        .hub-badge-ok { background: #dcfae6; color: #067647; }
        .hub-badge-warn { background: #fff6d7; color: #854a0e; }
        .hub-badge-bad { background: #fee4e2; color: #b42318; }
        .hub-badge-muted { background: #f2f4f7; color: #475467; }
        .hub-meta { display: grid; gap: 7px 12px; grid-template-columns: minmax(108px, 0.42fr) 1fr; margin-top: 12px; }
        .hub-meta-label { color: var(--muted); font-size: 13px; }
        .hub-meta-value { min-width: 0; overflow-wrap: anywhere; }
        .hub-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .hub-empty-state { border: 1px dashed var(--line); border-radius: 8px; color: var(--muted); padding: 18px; text-align: center; }
        .hub-section-title { align-items: baseline; display: flex; gap: 10px; justify-content: space-between; }
    </style>
</head>
<body>
<header>
    <div class="brand"><strong><?= hub_h($siteTitle) ?></strong><small><?= hub_h($siteSubtitle . ' / ' . HUB_VERSION . ' / ' . HUB_RELEASE_LABEL) ?></small></div>
    <nav>
        <?php if ($isAdmin): ?>
            <a href="index.php"><?= hub_h(__('控制台')) ?></a>
            <a href="marketplace.php"><?= hub_h(__('安裝套件')) ?></a>
            <a href="packs.php"><?= hub_h(__('HubPack 套件')) ?></a>
            <a href="models.php"><?= hub_h(__('模型倉庫')) ?></a>
            <a href="services.php"><?= hub_h(__('服務管理')) ?></a>
            <a href="runtime_runs.php"><?= hub_h(__('執行歷程')) ?></a>
            <a href="customers.php"><?= hub_h(__('客戶管理')) ?></a>
            <a href="api_members.php"><?= hub_h(__('API 金鑰')) ?></a>
            <a href="api_usage.php"><?= hub_h(__('API 記錄')) ?></a>
            <a href="playground.php"><?= hub_h(__('API 測試場')) ?></a>
            <a href="log_explorer.php"><?= hub_h(__('記錄中心')) ?></a>
            <a href="benchmarks.php"><?= hub_h(__('Benchmark 測試')) ?></a>
            <a href="api_docs.php"><?= hub_h(__('API 文件')) ?></a>
            <a href="environment.php"><?= hub_h(__('系統環境')) ?></a>
            <a href="settings.php"><?= hub_h(__('系統設定')) ?></a>
        <?php else: ?>
            <a href="my_services.php"><?= hub_h(__('我的服務')) ?></a>
            <a href="my_tokens.php"><?= hub_h(__('我的 Token')) ?></a>
            <a href="my_ip_whitelist.php"><?= hub_h(__('IP 白名單')) ?></a>
            <a href="my_usage.php" title="<?= hub_h(__('用量統計')) ?>"><?= hub_h(__('我的用量')) ?></a>
            <a href="my_profile.php"><?= hub_h(__('帳號資料')) ?></a>
            <a href="change_password.php"><?= hub_h(__('變更密碼')) ?></a>
            <a href="playground.php"><?= hub_h(__('API 測試場')) ?></a>
            <a href="../public_api_docs.php"><?= hub_h(__('API 文件')) ?></a>
        <?php endif; ?>
        <?= hub_i18n_language_selector() ?>
        <a href="logout.php"><?= hub_h(__('登出')) ?> <?= hub_h($user['username']) ?></a>
    </nav>
</header>
<main>
<?php if ((int)$user['must_change_password'] === 1): ?>
    <div class="notice"><?= hub_h(__('預設密碼仍在使用中，請到「設定」修改密碼。')) ?></div>
<?php endif; ?>
    <?php
}

function hub_admin_footer(): void
{
    ?>
</main>
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
