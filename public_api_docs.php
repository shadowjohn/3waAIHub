<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/public_api_docs.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

if (!hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-Hant"><head><meta charset="utf-8"><title>Public API docs forbidden</title></head><body>';
    echo '<h1>Public API docs are disabled or local-only.</h1>';
    echo '<p>請到後台「系統設定 / API 與安全」調整 <code>AIHUB_PUBLIC_API_DOCS</code> 與 <code>AIHUB_PUBLIC_API_LOCAL_ONLY</code>。</p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo hub_public_api_docs_html($db, hub_current_user($db));
