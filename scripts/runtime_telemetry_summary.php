<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

if ($argc !== 2 || !str_starts_with($argv[1], '--since=')) {
    fwrite(STDERR, 'usage: php scripts/runtime_telemetry_summary.php --since="1 hour"' . PHP_EOL);
    exit(64);
}

try {
    $until = new DateTimeImmutable('now');
    $since = hub_runtime_telemetry_parse_since(substr($argv[1], 8), $until);
} catch (Throwable) {
    fwrite(STDERR, 'runtime_telemetry_since_invalid' . PHP_EOL);
    exit(64);
}

echo hub_runtime_telemetry_render_summary(hub_runtime_telemetry_summary($since, $until));
