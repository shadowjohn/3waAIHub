<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

if (!hub_cluster_node_enabled($db)) {
    http_response_code(404);
    exit;
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    hub_send_gateway_response(hub_gateway_error(405, 'method_not_allowed', 'Method not allowed'));
}

$auth = hub_authenticate_api_token($db, hub_get_client_ip(), hub_bearer_token_from_request(), 'cluster_status');
if (empty($auth['ok'])) {
    hub_send_gateway_response($auth['response']);
}
if ((int)($auth['context']['token_id'] ?? 0) !== hub_cluster_node_token_id($db)) {
    hub_send_gateway_response(hub_gateway_error(403, 'cluster_status_forbidden', 'Cluster status is unavailable'));
}

try {
    hub_cluster_node_sync_token_permissions($db, (int)$auth['context']['token_id']);
    hub_send_gateway_response(hub_gateway_json(200, hub_cluster_status_payload($db)));
} catch (Throwable) {
    hub_send_gateway_response(hub_gateway_error(503, 'cluster_status_unavailable', 'Cluster status is unavailable'));
}
