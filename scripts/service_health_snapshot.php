<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

try {
    hub_service_health_write_snapshot($db);
    echo "service_health_snapshot=updated\n";
} catch (Throwable) {
    fwrite(STDERR, "service_health_snapshot=failed\n");
    exit(1);
}
