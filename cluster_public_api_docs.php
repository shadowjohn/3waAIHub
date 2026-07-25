<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/public_api_docs.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

if (!hub_cluster_router_enabled($db)) {
    hub_send_gateway_response(hub_gateway_error(404, 'router_disabled', 'cluster router is disabled'));
}
if (!hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Router API unavailable</title></head><body><h1>Router API documentation is unavailable.</h1></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo hub_cluster_public_api_docs_html($db);
