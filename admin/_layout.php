<?php
declare(strict_types=1);

function hub_admin_header(string $title, array $user): void
{
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hub_h($title) ?> - 3waAIHub</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        :root { color-scheme: light; --bg: #f6f7f9; --panel: #fff; --line: #d9dee7; --text: #1d2430; --muted: #667085; --blue: #1769e0; --red: #b42318; --green: #067647; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        header { background: #182230; color: #fff; padding: 14px 24px; display: flex; justify-content: space-between; align-items: center; gap: 18px; }
        .brand small { color: #98a2b3; display: block; font-size: 12px; font-weight: 500; margin-top: 2px; }
        nav a { color: #d0d5dd; margin-right: 16px; text-decoration: none; }
        nav a:hover { color: #fff; }
        main { max-width: 1120px; margin: 24px auto; padding: 0 16px; }
        .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; background: #fff; }
        th, td { border-bottom: 1px solid var(--line); padding: 10px; text-align: left; vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: 13px; }
        button, .button { border: 1px solid var(--line); background: #fff; border-radius: 6px; color: var(--text); cursor: pointer; display: inline-block; font: inherit; padding: 7px 11px; text-decoration: none; }
        button.primary { background: var(--blue); border-color: var(--blue); color: #fff; }
        button.danger { border-color: #fecdca; color: var(--red); }
        input, select { border: 1px solid var(--line); border-radius: 6px; box-sizing: border-box; font: inherit; padding: 8px 10px; width: 100%; }
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
    </style>
</head>
<body>
<header>
    <div class="brand"><strong>3waAIHub Local</strong><small><?= hub_h(HUB_VERSION . ' / ' . HUB_RELEASE_LABEL) ?></small></div>
    <nav>
        <a href="index.php">儀表板</a>
        <a href="marketplace.php">Marketplace</a>
        <a href="packs.php">HubPack</a>
        <a href="services.php">服務管理</a>
        <a href="api_members.php">API Members</a>
        <a href="api_usage.php">API Usage</a>
        <a href="log_explorer.php">Log Explorer</a>
        <a href="benchmarks.php">Benchmark</a>
        <a href="api_docs.php">API Docs</a>
        <a href="environment.php">環境診斷</a>
        <a href="settings.php">設定</a>
        <a href="logout.php">登出 <?= hub_h($user['username']) ?></a>
    </nav>
</header>
<main>
<?php if ((int)$user['must_change_password'] === 1): ?>
    <div class="notice">預設密碼仍在使用中，請到「設定」修改密碼。</div>
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
