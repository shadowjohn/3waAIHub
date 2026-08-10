<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

function hub_runtime_settings_migration_usage(): string
{
    return 'Usage: php scripts/migrate_runtime_settings.php [--check|--apply] [--service-key=<key>] [--json]' . PHP_EOL;
}

$mode = 'check';
$modeSpecified = false;
$json = false;
$serviceKey = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--check') {
        if ($modeSpecified) {
            fwrite(STDERR, hub_runtime_settings_migration_usage());
            exit(2);
        }
        $mode = 'check';
        $modeSpecified = true;
        continue;
    }
    if ($argument === '--apply') {
        if ($modeSpecified) {
            fwrite(STDERR, hub_runtime_settings_migration_usage());
            exit(2);
        }
        $mode = 'apply';
        $modeSpecified = true;
        continue;
    }
    if ($argument === '--json') {
        $json = true;
        continue;
    }
    if (str_starts_with($argument, '--service-key=') && $serviceKey === null) {
        $serviceKey = substr($argument, strlen('--service-key='));
        continue;
    }

    fwrite(STDERR, hub_runtime_settings_migration_usage());
    exit(2);
}

try {
    $db = hub_db();
    hub_migrate($db);
    hub_ensure_default_storage_settings($db);
    $result = hub_migrate_service_runtime_settings($db, $mode === 'apply', $serviceKey);
} catch (Throwable $e) {
    fwrite(STDERR, 'Runtime settings migration failed.' . PHP_EOL);
    exit(2);
}

$payload = ['mode' => $mode] + $result;
if ($json) {
    echo hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} else {
    echo 'mode=' . $mode
        . ' scanned=' . $result['scanned']
        . ' migrated=' . $result['migrated']
        . ' already_current=' . $result['already_current']
        . ' pending=' . $result['pending']
        . ' rejected=' . $result['rejected']
        . PHP_EOL;
    foreach ($result['services'] as $service) {
        echo hub_runtime_settings_migration_output_line($service) . PHP_EOL;
    }
}

exit($result['rejected'] > 0 ? 1 : 0);
