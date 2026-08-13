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

hub_test('runtime telemetry diagnoses writer failures without path warnings', function () use ($validRuntimeTelemetryEvent): void {
    $errorLogPath = tempnam(sys_get_temp_dir(), '3waaihub_telemetry_');
    if ($errorLogPath === false) {
        throw new RuntimeException('Cannot create runtime telemetry diagnostic fixture.');
    }

    $previousErrorLog = ini_get('error_log');
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (($severity & error_reporting()) !== 0) {
            $warnings[] = $message;
        }
        return true;
    });
    try {
        if (ini_set('error_log', $errorLogPath) === false) {
            throw new RuntimeException('Cannot redirect runtime telemetry diagnostics.');
        }
        $falseWriter = static fn (string $path, string $line): int|false => false;
        $partialWriter = static fn (string $path, string $line): int => strlen($line) - 1;
        hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $falseWriter), 'false writer must fail');
        hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $partialWriter), 'partial writer must fail');
        $diagnostics = (string)file_get_contents($errorLogPath);
    } finally {
        restore_error_handler();
        ini_set('error_log', $previousErrorLog);
        unlink($errorLogPath);
    }

    hub_test_assert($warnings === [], 'writer failures must not expose a filesystem warning');
    hub_test_assert(substr_count($diagnostics, '[3waAIHub] runtime telemetry append failed') === 2, 'writer failures must emit exactly two fixed diagnostics');
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

function hub_test_runtime_telemetry_fixture_line(array $event): string
{
    return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

hub_test('runtime telemetry parses relative summary ranges and nearest-rank quantiles', function (): void {
    $now = new DateTimeImmutable('2026-08-14T01:00:00.000000+08:00');
    $dstNow = new DateTimeImmutable('2026-03-08 03:30:00', new DateTimeZone('America/New_York'));

    hub_test_assert(
        hub_runtime_telemetry_parse_since('1 hour', $now)->format('c') === '2026-08-14T00:00:00+08:00',
        'one hour must parse relative to now'
    );
    hub_test_assert(hub_test_throws(static fn (): DateTimeImmutable => hub_runtime_telemetry_parse_since('all time', $now)), 'malformed relative range must be rejected');
    hub_test_assert(
        $dstNow->getTimestamp() - hub_runtime_telemetry_parse_since('1 hour', $dstNow)->getTimestamp() === 3600,
        'relative ranges must subtract elapsed seconds across DST'
    );
    hub_test_assert(hub_runtime_telemetry_quantile([1, 2, 3], 0.5) === 2.0, 'p50 must use nearest rank');
    hub_test_assert(hub_runtime_telemetry_quantile([10, 20], 0.95) === 20.0, 'p95 must use nearest rank');
});

hub_test('runtime telemetry summary rejects counter-overflow lines before aggregation', function () use ($validRuntimeTelemetryEvent): void {
    $event = static function (int $txMs, int $retries, int $skipped) use ($validRuntimeTelemetryEvent): array {
        return array_replace($validRuntimeTelemetryEvent, [
            'schema_version' => HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION,
            'observed_at' => '2026-08-14T00:00:00.000000+08:00',
            'tx_ms' => $txMs,
            'retry_count' => $retries,
            'skipped_ticks' => $skipped,
        ]);
    };
    $fixture = hub_test_runtime_telemetry_fixture_line($event(1, PHP_INT_MAX, PHP_INT_MAX))
        . hub_test_runtime_telemetry_fixture_line($event(2, 1, 1));
    $summary = hub_runtime_telemetry_summary(
        new DateTimeImmutable('2026-08-14T00:00:00.000000+08:00'),
        new DateTimeImmutable('2026-08-14T00:00:00.000000+08:00'),
        static function (string $path) use ($fixture) {
            $handle = fopen('php://temp', 'w+b');
            fwrite($handle, $fixture);
            rewind($handle);

            return $handle;
        }
    );

    hub_test_assert(($summary['invalid_lines'] ?? null) === 1, 'counter-overflow line must be invalid');
    hub_test_assert(($summary['groups']['heartbeat/cpu'] ?? null) === [
        'action' => 'heartbeat', 'variant' => 'cpu', 'count' => 1, 'p50_tx' => 1.0, 'p95_tx' => 1.0, 'p99_tx' => 1.0,
        'lock_count' => 0, 'retries' => PHP_INT_MAX, 'exhausted' => 0, 'skipped' => PHP_INT_MAX,
    ], 'counter-overflow line must not partially mutate group totals');
});

hub_test('runtime telemetry summarizes only direct daily candidates with independent groups', function () use ($validRuntimeTelemetryEvent): void {
    $fixtureDir = sys_get_temp_dir() . '/3waaihub_runtime_telemetry_' . bin2hex(random_bytes(8));
    if (!mkdir($fixtureDir, 0700)) {
        throw new RuntimeException('Cannot create runtime telemetry summary fixture.');
    }
    $since = new DateTimeImmutable('2026-08-13T23:00:00.000000+08:00');
    $until = new DateTimeImmutable('2026-08-14T01:00:00.000000+08:00');
    $paths = [
        hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-13')),
        hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-14')),
    ];
    $event = static function (string $observedAt, int $txMs, array $overrides = []) use ($validRuntimeTelemetryEvent): array {
        return array_replace($validRuntimeTelemetryEvent, [
            'schema_version' => HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION,
            'observed_at' => $observedAt,
            'tx_ms' => $txMs,
        ], $overrides);
    };
    $fixtures = [
        $paths[0] => hub_test_runtime_telemetry_fixture_line($event('2026-08-13T22:59:59.000000+08:00', 99))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-13T23:15:00.000000+08:00', 1))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-13T23:30:00.000000+08:00', 2)),
        $paths[1] => hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:15:00.000000+08:00', 3))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:20:00.000000+08:00', 10, [
                'action' => 'claim', 'variant' => 'runtime', 'lock_wait_ms' => 4, 'retry_count' => 1, 'skipped_ticks' => 2,
            ]))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:50:00.000000+08:00', 20, [
                'action' => 'claim', 'variant' => 'runtime', 'outcome' => 'lock_exhausted', 'retry_count' => 2, 'skipped_ticks' => 1,
            ]))
            . "{malformed\n",
    ];
    foreach ($fixtures as $path => $contents) {
        if (file_put_contents($fixtureDir . '/' . basename($path), $contents) === false) {
            throw new RuntimeException('Cannot write runtime telemetry summary fixture.');
        }
    }
    $requested = [];
    try {
        $summary = hub_runtime_telemetry_summary($since, $until, static function (string $path) use (&$requested, $fixtureDir) {
            $requested[] = $path;
            return fopen($fixtureDir . '/' . basename($path), 'rb');
        });
    } finally {
        foreach ($fixtures as $path => $_contents) {
            unlink($fixtureDir . '/' . basename($path));
        }
        rmdir($fixtureDir);
    }

    hub_test_assert($requested === $paths, 'summary must request only the two direct daily paths in order');
    hub_test_assert(($summary['invalid_lines'] ?? null) === 1, 'summary must count malformed lines');
    hub_test_assert(($summary['groups']['claim/runtime'] ?? null) === [
        'action' => 'claim', 'variant' => 'runtime', 'count' => 2, 'p50_tx' => 10.0, 'p95_tx' => 20.0, 'p99_tx' => 20.0,
        'lock_count' => 1, 'retries' => 3, 'exhausted' => 1, 'skipped' => 3,
    ], 'claim/runtime summary totals mismatch');
    hub_test_assert(($summary['groups']['heartbeat/cpu'] ?? null) === [
        'action' => 'heartbeat', 'variant' => 'cpu', 'count' => 3, 'p50_tx' => 2.0, 'p95_tx' => 3.0, 'p99_tx' => 3.0,
        'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0,
    ], 'heartbeat/cpu summary quantiles mismatch');
});

hub_test('runtime telemetry renders sorted tabular summaries including invalid lines', function (): void {
    $rendered = hub_runtime_telemetry_render_summary([
        'invalid_lines' => 1,
        'groups' => [
            ['action' => 'heartbeat', 'variant' => 'cpu', 'count' => 3, 'p50_tx' => 2.0, 'p95_tx' => 3.0, 'p99_tx' => 3.0, 'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0],
            ['action' => 'claim', 'variant' => 'runtime', 'count' => 2, 'p50_tx' => 10.0, 'p95_tx' => 20.0, 'p99_tx' => 20.0, 'lock_count' => 1, 'retries' => 3, 'exhausted' => 1, 'skipped' => 3],
        ],
    ]);

    hub_test_assert($rendered === "action\tvariant\tcount\tp50_tx\tp95_tx\tp99_tx\tlock>0\tretries\texhausted\tskipped\nclaim\truntime\t2\t10\t20\t20\t1\t3\t1\t3\nheartbeat\tcpu\t3\t2\t3\t3\t0\t0\t0\t0\ninvalid_lines=1\n", 'summary table must remain sorted and complete');
});

hub_test('runtime telemetry retention removes only expired regular dated files', function (): void {
    hub_test_require_symlink_fixture('Runtime telemetry retention requires symlink fixtures.');
    $files = [
        'runtime-telemetry-2026-08-07.ndjson',
        'runtime-telemetry-2026-08-08.ndjson',
        'runtime-telemetry-2026-08-14.ndjson',
        'runtime-telemetry-bad.ndjson',
        'runtime-telemetry-unrelated.log',
    ];
    $original = [];
    foreach ($files as $file) {
        $path = HUB_LOG_DIR . '/' . $file;
        $original[$path] = is_file($path) ? file_get_contents($path) : null;
        if (file_put_contents($path, 'runtime telemetry fixture') === false) {
            throw new RuntimeException('Cannot create runtime telemetry retention fixture.');
        }
    }
    $target = sys_get_temp_dir() . '/3waaihub_runtime_telemetry_link_' . bin2hex(random_bytes(8));
    $link = HUB_LOG_DIR . '/runtime-telemetry-2026-08-01.ndjson';
    if (file_put_contents($target, 'symlink target') === false || !symlink($target, $link)) {
        throw new RuntimeException('Cannot create runtime telemetry symlink fixture.');
    }
    try {
        $purged = hub_prune_runtime_telemetry(new DateTimeImmutable('2026-08-14T12:00:00.000000+08:00'));
        hub_test_assert($purged === 1, 'only the expired regular telemetry file must be purged');
        hub_test_assert(!file_exists(HUB_LOG_DIR . '/runtime-telemetry-2026-08-07.ndjson'), 'expired telemetry file must be removed');
        foreach (array_slice($files, 1) as $file) {
            hub_test_assert(is_file(HUB_LOG_DIR . '/' . $file), 'non-expired or unrelated file must be retained');
        }
        hub_test_assert(is_link($link) && file_exists($target), 'expired-looking telemetry symlink must be retained');
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        unlink($target);
        foreach ($original as $path => $contents) {
            if ($contents === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $contents);
            }
        }
    }
});

hub_test('runtime telemetry summary CLI accepts one strict since option', function (): void {
    $script = HUB_ROOT . '/scripts/runtime_telemetry_summary.php';
    $valid = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('--since=1 hour') . ' 2>&1', $valid, $validStatus);
    hub_test_assert($validStatus === 0 && ($valid[0] ?? null) === "action\tvariant\tcount\tp50_tx\tp95_tx\tp99_tx\tlock>0\tretries\texhausted\tskipped", 'valid CLI range must render the summary table');

    $invalid = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('--since=all time') . ' 2>&1', $invalid, $invalidStatus);
    hub_test_assert($invalidStatus === 64 && $invalid === ['runtime_telemetry_since_invalid'], 'invalid CLI range must use the fixed error');
});
