<?php
declare(strict_types=1);

function hub_platform_id(?string $osFamily = null): string
{
    return match (strtolower(trim($osFamily ?? PHP_OS_FAMILY))) {
        'linux' => 'linux',
        'windows' => 'windows',
        'darwin' => 'darwin',
        default => 'unknown',
    };
}

function hub_is_host_absolute_path(string $path): bool
{
    $path = trim($path);
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }

    return str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
        || str_starts_with($path, '\\\\');
}

function hub_normalize_host_path(string $path): string
{
    return str_replace('\\', '/', trim($path));
}

function hub_container_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
        throw new InvalidArgumentException('Invalid container path.');
    }
    if (preg_match('/^[A-Za-z]:\//', $path) === 1 || !str_starts_with($path, '/')) {
        throw new InvalidArgumentException('Container path must be absolute POSIX path.');
    }

    $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
    foreach ($parts as $part) {
        if ($part === '.' || $part === '..') {
            throw new InvalidArgumentException('Container path traversal is not allowed.');
        }
    }

    return '/' . implode('/', $parts);
}

function hub_platform_target_supported(string $target, ?string $platform = null): array
{
    $platform = hub_platform_id($platform);
    if ($platform === 'linux' && $target === 'linux-docker') {
        return ['platform' => $platform, 'target' => $target, 'supported' => true, 'reason' => null];
    }
    if ($platform === 'windows' && $target === 'linux-docker') {
        return ['platform' => $platform, 'target' => $target, 'supported' => false, 'reason' => 'linux-docker target is not available on Windows host'];
    }

    return ['platform' => $platform, 'target' => $target, 'supported' => false, 'reason' => $target . ' target is not implemented on ' . $platform . ' host'];
}

function hub_normalize_platform_targets(array $manifest): array
{
    $targets = [];
    foreach (is_array($manifest['platform_targets'] ?? null) ? $manifest['platform_targets'] : [] as $target => $value) {
        if (is_bool($value)) {
            $targets[(string)$target] = ['supported' => $value, 'source' => 'declared', 'reason' => null];
            continue;
        }
        if (is_array($value)) {
            $targets[(string)$target] = [
                'supported' => (bool)($value['supported'] ?? false),
                'source' => in_array((string)($value['source'] ?? ''), ['declared', 'legacy_inferred'], true) ? (string)$value['source'] : 'declared',
                'reason' => isset($value['reason']) ? (string)$value['reason'] : null,
            ];
        }
    }

    if ($targets === [] && (string)($manifest['runtime']['kind'] ?? '') === 'docker') {
        $targets['linux-docker'] = ['supported' => true, 'source' => 'legacy_inferred', 'reason' => null];
    }

    return $targets;
}

function hub_normalize_pack_manifest(array $manifest): array
{
    $manifest['platform_targets'] = hub_normalize_platform_targets($manifest);

    return $manifest;
}

