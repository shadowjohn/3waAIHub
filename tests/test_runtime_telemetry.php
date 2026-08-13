<?php
declare(strict_types=1);

function hub_test_runtime_telemetry_events(): array
{
    $path = HUB_LOG_DIR . '/runtime-telemetry-' . date('Y-m-d') . '.ndjson';
    if (!file_exists($path)) {
        return [];
    }
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

hub_test('runtime telemetry suppresses expected default write warnings', function () use ($validRuntimeTelemetryEvent): void {
    $path = hub_runtime_telemetry_path(new DateTimeImmutable());
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Cannot reset runtime telemetry write fixture.');
    }
    if (is_dir($path) && !rmdir($path)) {
        throw new RuntimeException('Cannot reset runtime telemetry write fixture.');
    }
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create runtime telemetry write fixture.');
    }

    $warnings = 0;
    set_error_handler(static function (int $severity) use (&$warnings): bool {
        if (($severity & error_reporting()) !== 0) {
            $warnings++;
        }
        return true;
    });
    try {
        $result = hub_runtime_telemetry_emit($validRuntimeTelemetryEvent);
    } finally {
        restore_error_handler();
        rmdir($path);
    }

    hub_test_assert($result === false, 'default write failure must return false');
    hub_test_assert($warnings === 0, 'default write failure must not emit a PHP warning');
});

hub_test('runtime telemetry emits one heartbeat line with schema version', function () use ($validRuntimeTelemetryEvent): void {
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_telemetry_emit($validRuntimeTelemetryEvent), 'valid heartbeat must emit');

    $events = hub_test_runtime_telemetry_events();
    hub_test_assert(count($events) === $before + 1, 'heartbeat must append exactly one event');
    $event = $events[array_key_last($events)];
    hub_test_assert(($event['action'] ?? null) === 'heartbeat', 'heartbeat action mismatch');
    hub_test_assert(($event['variant'] ?? null) === 'cpu', 'heartbeat variant mismatch');
    hub_test_assert(($event['schema_version'] ?? null) === 1, 'schema version mismatch');
    hub_test_assert(is_string($event['observed_at'] ?? null), 'observed_at must be emitted');
});

hub_test('runtime telemetry keeps observed date and writer path aligned', function () use ($validRuntimeTelemetryEvent): void {
    $capturedPath = null;
    $capturedEvent = null;
    $result = hub_runtime_telemetry_emit(
        $validRuntimeTelemetryEvent,
        static function (string $path, string $line) use (&$capturedPath, &$capturedEvent): int {
            $capturedPath = $path;
            $capturedEvent = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            return strlen($line);
        }
    );

    hub_test_assert($result, 'valid telemetry must be written');
    hub_test_assert(is_string($capturedPath) && is_array($capturedEvent), 'writer must capture telemetry');
    preg_match('/runtime-telemetry-(\d{4}-\d{2}-\d{2})\.ndjson$/', $capturedPath, $pathMatch);
    hub_test_assert(($pathMatch[1] ?? null) === substr((string)($capturedEvent['observed_at'] ?? ''), 0, 10), 'observed date and writer path must match');
});

hub_test('runtime telemetry rejects partial writes and invalid timings', function () use ($validRuntimeTelemetryEvent): void {
    $partialWriter = static fn (string $path, string $line): int => strlen($line) - 1;
    hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $partialWriter), 'partial write must fail');

    foreach ([NAN, INF, true] as $timing) {
        $event = $validRuntimeTelemetryEvent;
        $event['total_ms'] = $timing;
        $writerCalls = 0;
        $writer = static function (string $path, string $line) use (&$writerCalls): int {
            $writerCalls++;
            return strlen($line);
        };
        hub_test_assert(!hub_runtime_telemetry_emit($event, $writer), 'invalid timing must be rejected');
        hub_test_assert($writerCalls === 0, 'invalid timing must not invoke the writer');
    }
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

hub_test('runtime telemetry rejects empty and impossible timestamps before writing', function () use ($validRuntimeTelemetryEvent): void {
    $writerCalls = 0;
    $writer = static function (string $path, string $line) use (&$writerCalls): int {
        $writerCalls++;
        return strlen($line);
    };

    foreach (['', '2026-02-30T12:00:00.000000+08:00'] as $timestamp) {
        $event = $validRuntimeTelemetryEvent;
        $event['tx_begin_at'] = $timestamp;
        hub_test_assert(!hub_runtime_telemetry_emit($event, $writer), 'invalid timestamp must be rejected');
    }

    hub_test_assert($writerCalls === 0, 'invalid timestamps must not invoke the writer');
});

hub_test('runtime telemetry validates elapsed milliseconds and paths', function (): void {
    hub_test_assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', hub_runtime_telemetry_timestamp()) === 1, 'timestamp format mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(2_000_000, 3_234_567) === 1.235, 'elapsed milliseconds mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(3, 2) === 0.0, 'elapsed milliseconds must be nonnegative');
    hub_test_assert(hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-14')) === HUB_LOG_DIR . '/runtime-telemetry-2026-08-14.ndjson', 'telemetry path mismatch');
});
