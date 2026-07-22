<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$db = hub_db();
$missing = hub_runtime_schema_missing($db);
if ($missing !== []) {
    fwrite(STDERR, 'schema_upgrade_required: ' . implode(', ', $missing) . '. Run php scripts/init_db.php.' . PHP_EOL);
    exit(1);
}

for ($processed = 0; $processed < $limit; $processed++) {
    $result = hub_callback_process_next($db);
    if ($result === null) {
        break;
    }
    echo 'callback ' . $result['delivery_id'] . ' state=' . $result['state'] . PHP_EOL;
}
