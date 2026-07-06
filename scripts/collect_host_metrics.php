<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

$force = in_array('--force', $argv, true);
if (!hub_should_collect_host_metrics($db, 30, $force)) {
    echo 'host metrics snapshot skipped: latest snapshot is newer than 30 seconds. Use --force to override.' . PHP_EOL;
    exit(0);
}

$snapshot = hub_collect_host_metrics($db);
hub_save_host_metric_snapshot($db, $snapshot);

echo 'host metrics snapshot saved at ' . hub_now() . PHP_EOL;
