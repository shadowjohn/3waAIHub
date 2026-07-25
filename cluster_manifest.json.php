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
if (!hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST')) {
    hub_send_gateway_response(hub_gateway_error(403, 'public_docs_forbidden', 'Public API manifest is disabled or local-only.'));
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(hub_cluster_public_manifest($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
