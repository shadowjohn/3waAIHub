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
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    hub_send_gateway_response(hub_gateway_error(405, 'method_not_allowed', 'Method not allowed'));
}

$invite = (string)($_SERVER['HTTP_X_3WAAIHUB_PAIR_INVITE'] ?? '');
$routerName = trim((string)($_SERVER['HTTP_X_3WAAIHUB_ROUTER_NAME'] ?? ''));
if (strlen($routerName) < 1 || strlen($routerName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $routerName) === 1) {
    hub_send_gateway_response(hub_gateway_error(400, 'pairing_failed', 'Pairing failed'));
}

try {
    hub_send_gateway_response(hub_gateway_json(200, hub_cluster_accept_pair_invitation($db, $invite, hub_get_client_ip(), $routerName)));
} catch (Throwable) {
    hub_send_gateway_response(hub_gateway_error(403, 'pairing_failed', 'Pairing failed'));
}
