<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$dryRun = in_array('--dry-run', $argv, true);
$limit = 100;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int)substr($arg, 8);
    }
}

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

echo json_encode(hub_photo_prune_expired($db, $dryRun, $limit), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
