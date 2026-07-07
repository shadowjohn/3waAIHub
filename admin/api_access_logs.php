<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = hub_db();
hub_require_system_admin($db);

$query = $_GET;
if (!empty($query['client_ip']) && filter_var((string)$query['client_ip'], FILTER_VALIDATE_IP)) {
    $query['client_ip_b64'] = aihub_b64url_encode((string)$query['client_ip']);
}
unset($query['client_ip']);

header('Location: log_explorer.php' . ($query ? '?' . http_build_query($query) : ''));
exit;
