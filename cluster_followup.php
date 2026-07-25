<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

hub_send_gateway_response(hub_cluster_child_followup_dispatch($db));
