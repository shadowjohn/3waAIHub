<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$case = 'host_smoke';
$packId = null;
$serviceKey = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--case=')) {
        $case = substr($arg, 7);
    } elseif (str_starts_with($arg, '--pack=')) {
        $packId = substr($arg, 7);
    } elseif (str_starts_with($arg, '--service=')) {
        $serviceKey = substr($arg, 10);
    }
}

$db = hub_db();
hub_migrate($db);
hub_seed_admin_user($db);
hub_seed_hello_service($db);
hub_ensure_default_storage_settings($db);

$result = hub_run_benchmark_case($db, $case, $packId, $serviceKey);
echo json_encode([
    'ok' => $result['ok'],
    'case' => $result['case'],
    'elapsed_ms' => $result['elapsed_ms'],
    'status' => $result['status'],
    'error_message' => $result['error_message'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
