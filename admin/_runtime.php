<?php
declare(strict_types=1);

function hub_runtime_format_ms(null|int|string $ms): string
{
    if (!is_numeric($ms)) {
        return 'N/A';
    }
    $value = (int)$ms;
    if ($value < 1000) {
        return $value . ' ms';
    }
    if ($value < 60000) {
        return round($value / 1000, 1) . ' sec';
    }

    return round($value / 60000, 1) . ' min';
}

function hub_runtime_display_path(?string $path): string
{
    $path = trim((string)$path);
    if ($path === '') {
        return '';
    }
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $part): bool => $part !== ''));
    $tail = array_slice($parts, -4);

    return '.../' . implode('/', $tail);
}

function hub_runtime_tail(?string $path, ?string $workspace, int $bytes = 6000): string
{
    $path = (string)$path;
    $workspace = rtrim((string)$workspace, '/');
    if ($path === '' || $workspace === '' || !str_starts_with($path, $workspace . '/') || !is_file($path)) {
        return '';
    }

    return hub_tail_file($path, $bytes);
}

function hub_runtime_state_badge(string $state): string
{
    $class = in_array($state, ['success', 'running'], true) ? 'hub-badge-ok' : ($state === 'failed' ? 'hub-badge-bad' : 'hub-badge-muted');
    return '<span class="hub-badge ' . $class . '">' . hub_h(hub_status_label($state)) . '</span>';
}
