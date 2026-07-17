<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_require_system_admin($db);
hub_redirect('settings.php?tab=i18n');
