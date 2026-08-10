<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$arguments = array_slice($argv, 1);
if ($arguments !== [] && $arguments !== ['--force']) {
    fwrite(STDERR, "Usage: php scripts/cluster_refresh.php [--force]\n");
    exit(2);
}

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
try {
    hub_release_snapshot_local_git();
} catch (Throwable) {
    fwrite(STDERR, "release_snapshot_failed\n");
}

if (!hub_cluster_router_enabled($db)) {
    echo 'router_disabled ' . hub_i18n_text('統一入口未啟用') . PHP_EOL;
    exit(0);
}

foreach (hub_cluster_refresh_due_stations($db, $arguments === ['--force']) as $station) {
    echo hub_cluster_refresh_worker_output_line($station) . PHP_EOL;
}
