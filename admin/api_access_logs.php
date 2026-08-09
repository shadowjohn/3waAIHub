<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/admin_records.php';

$db = hub_db();
hub_require_system_admin($db);

$query = hub_admin_record_api_redirect_query($_GET);

header('Location: ' . hub_admin_record_log_explorer_url($query));
exit;
