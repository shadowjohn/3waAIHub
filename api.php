<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
$mode = (string)($_GET['mode'] ?? '');

hub_send_gateway_response(hub_gateway_dispatch($db, $mode));
