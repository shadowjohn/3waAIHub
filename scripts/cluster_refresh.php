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

foreach (hub_cluster_refresh_due_stations($db, $arguments === ['--force']) as $station) {
    echo (string)$station['station_key'] . ' ' . (!empty($station['fresh']) ? '1' : '0') . ' '
        . ((string)($station['last_error'] ?? '') ?: '-') . PHP_EOL;
}
