<?php
declare(strict_types=1);

function hub_test_runtime_telemetry_events(): array
{
    $path = HUB_LOG_DIR . '/runtime-telemetry-' . date('Y-m-d') . '.ndjson';
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open runtime telemetry log.');
    }

    $events = [];
    try {
        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
    } finally {
        fclose($handle);
    }

    return $events;
}

$validRuntimeTelemetryEvent = [
    'action' => 'heartbeat',
    'variant' => 'cpu',
    'outcome' => 'committed',
    'tx_mode' => 'autocommit',
    'tx_begin_at' => null,
    'tx_commit_at' => null,
    'pre_tx_ms' => 0.125,
    'lock_wait_ms' => 0,
    'lock_wait_kind' => 'none',
    'tx_ms' => 0,
    'post_tx_ms' => 0.25,
    'total_ms' => 0.375,
    'retry_count' => 0,
    'skipped_ticks' => 0,
];

hub_test('runtime telemetry emits one heartbeat line with schema version', function () use ($validRuntimeTelemetryEvent): void {
    hub_test_assert(hub_runtime_telemetry_emit($validRuntimeTelemetryEvent), 'valid heartbeat must emit');

    $events = hub_test_runtime_telemetry_events();
    hub_test_assert(count($events) === 1, 'heartbeat must append exactly one event');
    hub_test_assert(($events[0]['action'] ?? null) === 'heartbeat', 'heartbeat action mismatch');
    hub_test_assert(($events[0]['variant'] ?? null) === 'cpu', 'heartbeat variant mismatch');
    hub_test_assert(($events[0]['schema_version'] ?? null) === 1, 'schema version mismatch');
    hub_test_assert(is_string($events[0]['observed_at'] ?? null), 'observed_at must be emitted');
});

hub_test('runtime telemetry rejects unknown fields and absorbs writer failures', function () use ($validRuntimeTelemetryEvent): void {
    $unknownFieldEvent = $validRuntimeTelemetryEvent;
    $unknownFieldEvent['token'] = 'secret';
    hub_test_assert(!hub_runtime_telemetry_emit($unknownFieldEvent), 'unknown field must be rejected');

    $writerResult = null;
    $result = hub_runtime_telemetry_emit(
        $validRuntimeTelemetryEvent,
        static function (string $path, string $line) use (&$writerResult): int|false {
            $writerResult = [$path, $line];
            return false;
        }
    );
    hub_test_assert($result === false, 'failed writer must return false');
    hub_test_assert(is_array($writerResult) && $writerResult[0] === hub_runtime_telemetry_path(new DateTimeImmutable()), 'writer path mismatch');
});

hub_test('runtime telemetry validates elapsed milliseconds and paths', function (): void {
    hub_test_assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', hub_runtime_telemetry_timestamp()) === 1, 'timestamp format mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(2_000_000, 3_234_567) === 1.235, 'elapsed milliseconds mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(3, 2) === 0.0, 'elapsed milliseconds must be nonnegative');
    hub_test_assert(hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-14')) === HUB_LOG_DIR . '/runtime-telemetry-2026-08-14.ndjson', 'telemetry path mismatch');
});
