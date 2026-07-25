<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

$mode = hub_cluster_router_requested_mode($_GET['mode'] ?? null);
if ($mode === null) {
    hub_send_gateway_response(hub_gateway_error(400, 'bad_request', 'invalid mode'));
}

if (hub_cluster_router_is_followup_mode($mode)) {
    hub_send_gateway_response(hub_cluster_dispatch_followup($db, $mode));
}

hub_send_gateway_response(hub_cluster_dispatch($db, $mode));
