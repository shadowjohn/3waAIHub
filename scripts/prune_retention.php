<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$db = hub_db();
$missing = hub_retention_schema_missing($db);
if ($missing !== []) {
    fwrite(STDERR, 'retention_schema_upgrade_required: ' . implode(', ', $missing) . '. Run php scripts/init_db.php.' . PHP_EOL);
    exit(78);
}

echo json_encode(hub_prune_retention($db), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
