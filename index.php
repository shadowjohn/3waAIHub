<?php
declare(strict_types=1);

$publicDocsLabel = '可讀';
$manifestLabel = '可讀';
try {
    require_once __DIR__ . '/app/bootstrap.php';
    $db = hub_db();
    hub_migrate($db);
    hub_ensure_default_storage_settings($db);
    $localOnly = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1';
    $publicDocsLabel = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1' ? ($localOnly ? '本機可讀' : '可讀') : '需設定開啟或僅本機可讀';
    $manifestLabel = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST') === '1' ? ($localOnly ? '本機可讀' : '可讀') : '需設定開啟';
} catch (Throwable) {
    // ponytail: 首頁不能因 SQLite 尚未初始化而掛掉；安裝後會顯示真實設定。
}
?><!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>3waAIHub</title>
    <style>
        :root {
            --bg: #020604;
            --green: #39ff88;
            --green-soft: #9affc4;
            --green-dim: #1c8f54;
            --line: rgba(57, 255, 136, .28);
            --panel: rgba(3, 18, 10, .86);
            --shadow: rgba(57, 255, 136, .18);
        }
        * { box-sizing: border-box; }
        html, body { min-height: 100%; }
        body {
            margin: 0;
            background:
                linear-gradient(rgba(57, 255, 136, .035) 1px, transparent 1px),
                radial-gradient(circle at 18% 20%, rgba(57, 255, 136, .12), transparent 28rem),
                radial-gradient(circle at 82% 72%, rgba(28, 143, 84, .16), transparent 24rem),
                var(--bg);
            background-size: 100% 4px, auto, auto, auto;
            color: var(--green-soft);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            letter-spacing: 0;
        }
        main {
            align-items: center;
            display: flex;
            min-height: 100vh;
            padding: 32px 18px;
        }
        .panel {
            border: 1px solid var(--line);
            background: var(--panel);
            box-shadow: 0 0 42px var(--shadow), inset 0 0 28px rgba(57, 255, 136, .05);
            margin: 0 auto;
            max-width: 920px;
            overflow: hidden;
            width: 100%;
        }
        .topbar {
            align-items: center;
            border-bottom: 1px solid var(--line);
            display: flex;
            gap: 10px;
            padding: 12px 16px;
        }
        .dot { border: 1px solid var(--green-dim); border-radius: 50%; height: 10px; width: 10px; }
        .title { color: var(--green); margin-left: auto; text-transform: uppercase; }
        .content { padding: clamp(24px, 6vw, 56px); }
        h1 {
            color: var(--green);
            font-size: clamp(42px, 9vw, 92px);
            line-height: 1;
            margin: 0 0 16px;
            text-shadow: 0 0 22px rgba(57, 255, 136, .45);
        }
        .slogan {
            color: var(--green-soft);
            font-size: clamp(17px, 3vw, 24px);
            margin: 0 0 32px;
        }
        .actions { align-items: center; display: flex; flex-wrap: wrap; gap: 14px; }
        .button {
            background: var(--green);
            border: 1px solid var(--green);
            color: #03110a;
            display: inline-block;
            font-weight: 800;
            padding: 13px 18px;
            text-decoration: none;
            text-transform: uppercase;
        }
        .button.secondary {
            background: transparent;
            color: var(--green-soft);
        }
        .button:focus, .button:hover {
            box-shadow: 0 0 24px rgba(57, 255, 136, .5);
            outline: 2px solid transparent;
        }
        .links { border-top: 1px solid var(--line); margin-top: 28px; padding-top: 20px; }
        .hint { color: var(--green-dim); font-size: 13px; margin: 12px 0 0; }
        .status { color: var(--green-soft); font-size: 12px; margin: 6px 0 0; }
        .cursor::after {
            animation: blink 1s steps(2, start) infinite;
            color: var(--green);
            content: "_";
        }
        @keyframes blink { 50% { opacity: 0; } }
        @media (max-width: 640px) {
            main { align-items: stretch; padding: 16px; }
            .button { text-align: center; width: 100%; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel" aria-label="3waAIHub">
        <div class="topbar">
            <span class="dot" aria-hidden="true"></span>
            <span class="dot" aria-hidden="true"></span>
            <span class="dot" aria-hidden="true"></span>
            <span class="title">3waAIHub</span>
        </div>
        <div class="content">
            <h1>3waAIHub</h1>
            <p class="slogan cursor">Install. Enable. Expose AI services.</p>
            <div class="actions">
                <a class="button" href="login.php">Enter Admin Console</a>
            </div>
            <div class="links">
                <div class="actions">
                    <a class="button secondary" href="public_api_docs.php">公開 API 文件</a>
                    <a class="button secondary" href="api_manifest.json.php">Agent Manifest</a>
                    <a class="button secondary" href="admin/">後台管理</a>
                </div>
                <p class="status">公開 API 文件：<?= hub_h($publicDocsLabel) ?> / Agent Manifest：<?= hub_h($manifestLabel) ?></p>
                <p class="hint">依系統設定，公開 API 文件可能僅允許本機讀取。</p>
            </div>
        </div>
    </section>
</main>
</body>
</html>
