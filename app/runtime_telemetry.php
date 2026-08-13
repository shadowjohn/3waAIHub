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

function hub_runtime_telemetry_emit(array $event, ?callable $writer = null): bool
{
    try {
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
            throw new InvalidArgumentException('Runtime telemetry fields are invalid.');
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
            throw new InvalidArgumentException('Runtime telemetry action or variant is invalid.');
        }

        if (!is_string($event['outcome']) || !in_array($event['outcome'], ['committed', 'rolled_back', 'fence_lost', 'lock_exhausted', 'failed'], true)) {
            throw new InvalidArgumentException('Runtime telemetry outcome is invalid.');
        }
        if (!is_string($event['tx_mode']) || !in_array($event['tx_mode'], ['immediate', 'deferred', 'autocommit'], true)) {
            throw new InvalidArgumentException('Runtime telemetry transaction mode is invalid.');
        }
        if (!is_string($event['lock_wait_kind']) || !in_array($event['lock_wait_kind'], ['begin_immediate', 'first_write_upper_bound', 'none'], true)) {
            throw new InvalidArgumentException('Runtime telemetry lock wait kind is invalid.');
        }

        foreach (['tx_begin_at', 'tx_commit_at'] as $field) {
            if ($event[$field] !== null) {
                if (!is_string($event[$field])) {
                    throw new InvalidArgumentException('Runtime telemetry timestamp is invalid.');
                }
                $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $event[$field]);
                $errors = DateTimeImmutable::getLastErrors();
                if (
                    $parsed === false
                    || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
                    || $parsed->format('Y-m-d\TH:i:s.uP') !== $event[$field]
                ) {
                    throw new InvalidArgumentException('Runtime telemetry timestamp is invalid.');
                }
            }
        }

        foreach (['pre_tx_ms', 'lock_wait_ms', 'tx_ms', 'post_tx_ms', 'total_ms'] as $field) {
            if ((!is_int($event[$field]) && !is_float($event[$field])) || !is_finite((float)$event[$field]) || $event[$field] < 0) {
                throw new InvalidArgumentException('Runtime telemetry timing is invalid.');
            }
        }
        foreach (['retry_count', 'skipped_ticks'] as $field) {
            if (!is_int($event[$field]) || $event[$field] < 0) {
                throw new InvalidArgumentException('Runtime telemetry counter is invalid.');
            }
        }

        $observedAt = new DateTimeImmutable();
        $event['schema_version'] = HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION;
        $event['observed_at'] = $observedAt->format('Y-m-d\TH:i:s.uP');
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $path = hub_runtime_telemetry_path($observedAt);
        $bytes = $writer === null
            ? @file_put_contents($path, $line, FILE_APPEND | LOCK_EX)
            : $writer($path, $line);

        return $bytes === strlen($line);
    } catch (Throwable) {
        error_log('[3waAIHub] runtime telemetry append failed');
        return false;
    }
}
