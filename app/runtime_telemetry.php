<?php
declare(strict_types=1);

const HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION = 1;
const HUB_RUNTIME_TELEMETRY_RETENTION_DAYS = 7;

function hub_runtime_telemetry_timestamp(): string
{
    return (new DateTimeImmutable())->format('Y-m-d\TH:i:s.uP');
}

function hub_runtime_telemetry_elapsed_ms(int|float $start, int|float $end): float
{
    return round(max(0.0, $end - $start) / 1_000_000, 3);
}

function hub_runtime_telemetry_path(DateTimeInterface $date): string
{
    return HUB_LOG_DIR . '/runtime-telemetry-' . $date->format('Y-m-d') . '.ndjson';
}

function hub_runtime_telemetry_parse_timestamp(string $value): ?DateTimeImmutable
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $parsed !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d\TH:i:s.uP') === $value
        ? $parsed
        : null;
}

function hub_runtime_telemetry_event_is_valid(array $event): bool
{
    $fields = [
        'action', 'variant', 'outcome', 'tx_mode', 'tx_begin_at', 'tx_commit_at',
        'pre_tx_ms', 'lock_wait_ms', 'lock_wait_kind', 'tx_ms', 'post_tx_ms',
        'total_ms', 'retry_count', 'skipped_ticks',
    ];
    $eventFields = array_keys($event);
    if (
        count($eventFields) !== count($fields)
        || array_diff($eventFields, $fields) !== []
        || array_diff($fields, $eventFields) !== []
    ) {
        return false;
    }

    $action = $event['action'];
    $variant = $event['variant'];
    $variants = [
        'claim' => ['task', 'runtime', 'gpu'],
        'heartbeat' => ['cpu', 'gpu'],
        'finish' => ['success', 'failure'],
        'recovery' => ['runtime', 'gpu'],
    ];
    if (!is_string($action) || !isset($variants[$action]) || !is_string($variant) || !in_array($variant, $variants[$action], true)) {
        return false;
    }

    if (!is_string($event['outcome']) || !in_array($event['outcome'], ['committed', 'rolled_back', 'fence_lost', 'lock_exhausted', 'failed'], true)) {
        return false;
    }
    if (!is_string($event['tx_mode']) || !in_array($event['tx_mode'], ['immediate', 'deferred', 'autocommit'], true)) {
        return false;
    }
    if (!is_string($event['lock_wait_kind']) || !in_array($event['lock_wait_kind'], ['begin_immediate', 'first_write_upper_bound', 'none'], true)) {
        return false;
    }

    foreach (['tx_begin_at', 'tx_commit_at'] as $field) {
        if ($event[$field] !== null && (!is_string($event[$field]) || hub_runtime_telemetry_parse_timestamp($event[$field]) === null)) {
            return false;
        }
    }

    foreach (['pre_tx_ms', 'lock_wait_ms', 'tx_ms', 'post_tx_ms', 'total_ms'] as $field) {
        if ((!is_int($event[$field]) && !is_float($event[$field])) || !is_finite((float)$event[$field]) || $event[$field] < 0) {
            return false;
        }
    }
    foreach (['retry_count', 'skipped_ticks'] as $field) {
        if (!is_int($event[$field]) || $event[$field] < 0) {
            return false;
        }
    }

    return true;
}

function hub_runtime_telemetry_emit(array $event, ?callable $writer = null): bool
{
    try {
        if (!hub_runtime_telemetry_event_is_valid($event)) {
            throw new InvalidArgumentException('Runtime telemetry event is invalid.');
        }

        $observedAt = new DateTimeImmutable();
        $event['schema_version'] = HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION;
        $event['observed_at'] = $observedAt->format('Y-m-d\TH:i:s.uP');
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $path = hub_runtime_telemetry_path($observedAt);
        $bytes = $writer === null
            ? @file_put_contents($path, $line, FILE_APPEND | LOCK_EX)
            : $writer($path, $line);

        if ($bytes !== strlen($line)) {
            throw new RuntimeException();
        }

        return true;
    } catch (Throwable) {
        error_log('[3waAIHub] runtime telemetry append failed');
        return false;
    }
}

function hub_runtime_telemetry_parse_since(string $value, DateTimeImmutable $now): DateTimeImmutable
{
    if (preg_match('/\A([1-9][0-9]*) (minute|minutes|hour|hours|day|days)\z/D', $value, $match) !== 1) {
        throw new InvalidArgumentException('runtime_telemetry_since_invalid');
    }
    $seconds = match ($match[2]) {
        'minute', 'minutes' => 60,
        'hour', 'hours' => 3600,
        'day', 'days' => 86400,
    };
    $maximum = intdiv(HUB_RUNTIME_TELEMETRY_RETENTION_DAYS * 86400, $seconds);
    $amount = $match[1];
    if (strlen($amount) > strlen((string)$maximum) || (strlen($amount) === strlen((string)$maximum) && $amount > (string)$maximum)) {
        throw new InvalidArgumentException('runtime_telemetry_since_invalid');
    }

    return $now->modify('-' . ((int)$amount * $seconds) . ' seconds');
}

function hub_runtime_telemetry_quantile(array $values, float $quantile): float
{
    if ($values === [] || !is_finite($quantile) || $quantile <= 0 || $quantile > 1) {
        throw new InvalidArgumentException('Runtime telemetry quantile is invalid.');
    }
    foreach ($values as $value) {
        if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
            throw new InvalidArgumentException('Runtime telemetry quantile values are invalid.');
        }
    }
    sort($values, SORT_NUMERIC);

    return round((float)$values[(int)ceil($quantile * count($values)) - 1], 3);
}

function hub_runtime_telemetry_summary(DateTimeImmutable $since, DateTimeImmutable $until, ?callable $opener = null): array
{
    if ($since > $until) {
        throw new InvalidArgumentException('Runtime telemetry range is invalid.');
    }
    $opener ??= static function (string $path) {
        $stat = @lstat($path);

        return $stat !== false && (($stat['mode'] & 0170000) === 0100000) ? @fopen($path, 'rb') : false;
    };
    $invalidLines = 0;
    $groups = [];
    $day = $since->setTime(0, 0);
    $lastDay = $until->setTime(0, 0);
    while ($day <= $lastDay) {
        $handle = $opener(hub_runtime_telemetry_path($day));
        $day = $day->modify('+1 day');
        if (!is_resource($handle)) {
            continue;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                try {
                    $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($event) || ($event['schema_version'] ?? null) !== HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION || !is_string($event['observed_at'] ?? null)) {
                        throw new InvalidArgumentException();
                    }
                    $observedAt = hub_runtime_telemetry_parse_timestamp($event['observed_at']);
                    unset($event['schema_version'], $event['observed_at']);
                    if ($observedAt === null || !hub_runtime_telemetry_event_is_valid($event)) {
                        throw new InvalidArgumentException();
                    }
                } catch (Throwable) {
                    $invalidLines++;
                    continue;
                }
                if ($observedAt < $since || $observedAt > $until) {
                    continue;
                }
                $key = $event['action'] . "\0" . $event['variant'];
                $groups[$key] ??= [
                    'action' => $event['action'], 'variant' => $event['variant'], 'count' => 0, 'samples' => [],
                    'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0,
                ];
                $groups[$key]['count']++;
                $groups[$key]['samples'][] = $event['tx_ms'];
                $groups[$key]['lock_count'] += $event['lock_wait_ms'] > 0 ? 1 : 0;
                $groups[$key]['retries'] += $event['retry_count'];
                $groups[$key]['exhausted'] += $event['outcome'] === 'lock_exhausted' ? 1 : 0;
                $groups[$key]['skipped'] += $event['skipped_ticks'];
            }
        } finally {
            fclose($handle);
        }
    }
    ksort($groups, SORT_STRING);
    foreach ($groups as &$group) {
        $samples = $group['samples'];
        unset($group['samples']);
        $group['p50_tx'] = hub_runtime_telemetry_quantile($samples, 0.5);
        $group['p95_tx'] = hub_runtime_telemetry_quantile($samples, 0.95);
        $group['p99_tx'] = hub_runtime_telemetry_quantile($samples, 0.99);
        $group = [
            'action' => $group['action'], 'variant' => $group['variant'], 'count' => $group['count'],
            'p50_tx' => $group['p50_tx'], 'p95_tx' => $group['p95_tx'], 'p99_tx' => $group['p99_tx'],
            'lock_count' => $group['lock_count'], 'retries' => $group['retries'], 'exhausted' => $group['exhausted'], 'skipped' => $group['skipped'],
        ];
    }
    unset($group);

    return ['invalid_lines' => $invalidLines, 'groups' => array_values($groups)];
}

function hub_runtime_telemetry_render_summary(array $summary): string
{
    $groups = is_array($summary['groups'] ?? null) ? $summary['groups'] : [];
    usort($groups, static fn (array $left, array $right): int => ($left['action'] . "\0" . $left['variant']) <=> ($right['action'] . "\0" . $right['variant']));
    $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    $lines = ["action\tvariant\tcount\tp50_tx\tp95_tx\tp99_tx\tlock>0\tretries\texhausted\tskipped"];
    foreach ($groups as $group) {
        $lines[] = implode("\t", [
            $group['action'], $group['variant'], $group['count'], $format((float)$group['p50_tx']), $format((float)$group['p95_tx']), $format((float)$group['p99_tx']),
            $group['lock_count'], $group['retries'], $group['exhausted'], $group['skipped'],
        ]);
    }
    $lines[] = 'invalid_lines=' . (int)($summary['invalid_lines'] ?? 0);

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function hub_prune_runtime_telemetry(?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable();
    $logRoot = realpath(HUB_LOG_DIR);
    if ($logRoot === false || !is_dir($logRoot)) {
        return 0;
    }
    $entries = @scandir($logRoot);
    if ($entries === false) {
        return 0;
    }
    $cutoff = $now->setTime(0, 0)->modify('-' . (HUB_RUNTIME_TELEMETRY_RETENTION_DAYS - 1) . ' days');
    $purged = 0;
    foreach ($entries as $entry) {
        if (preg_match('/\Aruntime-telemetry-(\d{4}-\d{2}-\d{2})\.ndjson\z/D', $entry, $match) !== 1) {
            continue;
        }
        $path = HUB_LOG_DIR . '/' . $entry;
        $stat = @lstat($path);
        if (
            is_link($path)
            || $stat === false
            || (($stat['mode'] & 0170000) !== 0100000)
            || realpath(dirname($path)) !== $logRoot
        ) {
            continue;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], $now->getTimezone());
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $date->format('Y-m-d') !== $match[1]
            || $date >= $cutoff
        ) {
            continue;
        }
        if (@unlink($path)) {
            $purged++;
        }
    }

    return $purged;
}
