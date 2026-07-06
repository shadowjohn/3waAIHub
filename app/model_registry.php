<?php
declare(strict_types=1);

function hub_models_root(PDO $db): string
{
    return rtrim(hub_get_storage_setting($db, 'AIHUB_MODELS_DIR'), '/') ?: '/';
}

function hub_model_asset_safe_path(string $relativePath): string
{
    $raw = str_replace('\\', '/', $relativePath);
    if ($raw === '' || str_contains($raw, "\0") || str_starts_with($raw, '/')) {
        throw new InvalidArgumentException('Invalid model asset path.');
    }
    $path = trim($raw, '/');
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new InvalidArgumentException('Invalid model asset path.');
        }
    }

    return $path;
}

function hub_model_asset_type(string $path): string
{
    if (is_link($path)) {
        return 'symlink';
    }
    if (is_dir($path)) {
        return 'directory';
    }

    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'pt', 'pth', 'onnx', 'safetensors', 'bin', 'gguf' => 'model_file',
        'pdiparams', 'pdmodel', 'yml', 'yaml', 'json' => 'framework_file',
        default => is_file($path) ? 'file' : 'missing',
    };
}

function hub_model_asset_size(string $path, int $maxFiles = 3000): ?int
{
    if (is_link($path) || !file_exists($path)) {
        return null;
    }
    if (is_file($path)) {
        $size = filesize($path);
        return $size === false ? null : $size;
    }
    if (!is_dir($path) || !is_readable($path)) {
        return null;
    }

    $total = 0;
    $seen = 0;
    $scan = static function (string $dir) use (&$scan, &$total, &$seen, $maxFiles): void {
        if ($seen >= $maxFiles || !is_readable($dir)) {
            return;
        }
        try {
            $items = new DirectoryIterator($dir);
        } catch (Throwable) {
            return;
        }
        foreach ($items as $item) {
            if ($item->isDot() || $item->isLink()) {
                continue;
            }
            if ($item->isFile()) {
                $total += max(0, $item->getSize());
                $seen++;
            } elseif ($item->isDir()) {
                $scan($item->getPathname());
            }
            if ($seen >= $maxFiles) {
                return;
            }
        }
    };
    $scan($path);

    return $total;
}

function hub_scan_model_assets(PDO $db, array $options = []): array
{
    $root = hub_models_root($db);
    $maxDepth = min(8, max(1, (int)($options['max_depth'] ?? 5)));
    $limit = min(1000, max(1, (int)($options['limit'] ?? 300)));
    $assets = [];
    $errors = [];

    if (!is_dir($root)) {
        return ['root' => $root, 'assets' => [], 'errors' => ['Models root does not exist.']];
    }

    $walk = static function (string $dir, string $prefix, int $depth) use (&$walk, &$assets, &$errors, $root, $maxDepth, $limit): void {
        if (count($assets) >= $limit || $depth > $maxDepth) {
            return;
        }
        if (!is_readable($dir)) {
            $errors[] = 'Unreadable: ' . ($prefix === '' ? '.' : $prefix);
            return;
        }

        try {
            $items = new DirectoryIterator($dir);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            return;
        }

        foreach ($items as $item) {
            if ($item->isDot() || count($assets) >= $limit) {
                continue;
            }
            $path = $item->getPathname();
            $relative = ltrim($prefix . '/' . $item->getFilename(), '/');
            $isLink = $item->isLink();
            $isDir = !$isLink && $item->isDir();
            $asset = [
                'relative_path' => $relative,
                'path' => $path,
                'type' => hub_model_asset_type($path),
                'size_bytes' => $item->isFile() ? $item->getSize() : null,
                'mtime' => $item->getMTime(),
                'is_file' => !$isLink && $item->isFile(),
                'is_dir' => $isDir,
                'is_symlink' => $isLink,
                'skipped' => $isLink || ($isDir && !is_readable($path)),
            ];
            if ($asset['skipped']) {
                $asset['skip_reason'] = $isLink ? 'symlink' : 'unreadable';
            }
            $assets[] = $asset;

            if ($isDir && is_readable($path)) {
                $walk($path, $relative, $depth + 1);
            }
        }
    };
    $walk($root, '', 1);
    $links = hub_model_service_link_map($db);
    foreach ($assets as &$asset) {
        $relative = (string)$asset['relative_path'];
        $linked = $links[$relative] ?? [];
        if (!empty($asset['is_dir'])) {
            foreach ($links as $path => $serviceKeys) {
                if (str_starts_with($path, $relative . '/')) {
                    $linked = array_merge($linked, $serviceKeys);
                }
            }
        }
        $asset['linked_services'] = array_values(array_unique($linked));
    }
    unset($asset);

    return ['root' => $root, 'assets' => $assets, 'errors' => $errors, 'limit' => $limit, 'max_depth' => $maxDepth];
}

function hub_model_service_link_map(PDO $db): array
{
    $links = [];
    foreach (hub_list_services($db) as $service) {
        $schema = hub_get_pack_settings_schema((string)($service['pack_id'] ?? ''));
        if ($schema === []) {
            continue;
        }
        $settings = hub_list_service_settings($db, (int)$service['id']);
        foreach ($schema as $key => $item) {
            $selector = is_array($item['model_selector'] ?? null) ? $item['model_selector'] : null;
            if (!$selector) {
                continue;
            }
            $rootSubdir = hub_model_asset_safe_path((string)($selector['root_subdir'] ?? ''));
            $type = (string)($selector['type'] ?? 'file');
            $value = (string)($settings[$key]['value'] ?? $item['default'] ?? '');
            try {
                $relative = $type === 'ollama_tag' ? $rootSubdir : $rootSubdir . '/' . hub_model_asset_safe_path($value);
            } catch (InvalidArgumentException) {
                continue;
            }
            $links[$relative][] = (string)$service['service_key'];
        }
    }

    return $links;
}

function hub_model_selector_options(PDO $db, array $selector): array
{
    $rootSubdir = hub_model_asset_safe_path((string)($selector['root_subdir'] ?? '.'));
    $type = (string)($selector['type'] ?? 'file');
    $extensions = array_map('strtolower', array_map('strval', (array)($selector['extensions'] ?? [])));
    $root = hub_models_root($db) . '/' . $rootSubdir;
    if (!is_dir($root) || !is_readable($root)) {
        return [];
    }

    $options = [];
    $scan = static function (string $dir, string $prefix, int $depth) use (&$scan, &$options, $rootSubdir, $type, $extensions): void {
        if ($depth > 4 || count($options) >= 200 || !is_readable($dir)) {
            return;
        }
        try {
            $items = new DirectoryIterator($dir);
        } catch (Throwable) {
            return;
        }
        foreach ($items as $item) {
            if ($item->isDot() || $item->isLink()) {
                continue;
            }
            $relative = ltrim($prefix . '/' . $item->getFilename(), '/');
            if ($type === 'file' && $item->isFile()) {
                $ext = '.' . strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
                if ($extensions === [] || in_array($ext, $extensions, true)) {
                    $options[] = [
                        'value' => $relative,
                        'label' => $rootSubdir . '/' . $relative,
                        'size_bytes' => $item->getSize(),
                    ];
                }
            } elseif ($type === 'directory' && $item->isDir()) {
                $options[] = [
                    'value' => $relative,
                    'label' => $rootSubdir . '/' . $relative,
                    'size_bytes' => null,
                ];
            }
            if ($item->isDir()) {
                $scan($item->getPathname(), $relative, $depth + 1);
            }
        }
    };
    $scan($root, '', 1);
    usort($options, static fn (array $a, array $b): int => strcmp((string)$a['label'], (string)$b['label']));

    return $options;
}

function hub_model_selector_status(PDO $db, array $selector, string $value): array
{
    $rootSubdir = hub_model_asset_safe_path((string)($selector['root_subdir'] ?? '.'));
    $type = (string)($selector['type'] ?? 'file');
    if ($type === 'ollama_tag') {
        $path = hub_models_root($db) . '/' . $rootSubdir;
        $manifestPath = hub_ollama_model_manifest_path($path, $value);
        return [
            'label' => $rootSubdir . ' / tag: ' . $value,
            'path' => $path,
            'exists' => is_dir($path),
            'model_present' => $manifestPath !== null && is_file($manifestPath),
            'model_manifest_path' => $manifestPath,
            'size_bytes' => hub_model_asset_size($path),
        ];
    }

    try {
        $relative = hub_model_asset_safe_path($value);
    } catch (InvalidArgumentException) {
        return ['label' => $rootSubdir . '/' . $value, 'path' => '', 'exists' => false, 'size_bytes' => null];
    }
    $path = hub_models_root($db) . '/' . $rootSubdir . '/' . $relative;
    return [
        'label' => $rootSubdir . '/' . $relative,
        'path' => $path,
        'exists' => $type === 'directory' ? is_dir($path) : is_file($path),
        'size_bytes' => hub_model_asset_size($path),
    ];
}

function hub_ollama_model_manifest_path(string $ollamaRoot, string $modelTag): ?string
{
    $modelTag = trim($modelTag);
    if ($modelTag === '' || str_contains($modelTag, "\0")) {
        return null;
    }
    [$name, $tag] = array_pad(explode(':', $modelTag, 2), 2, 'latest');
    $name = trim($name, '/');
    $tag = trim($tag, '/');
    if ($name === '' || $tag === '') {
        return null;
    }
    if (!str_contains($name, '/')) {
        $name = 'library/' . $name;
    }

    try {
        $safeName = hub_model_asset_safe_path($name);
        $safeTag = hub_model_asset_safe_path($tag);
    } catch (InvalidArgumentException) {
        return null;
    }

    return rtrim($ollamaRoot, '/') . '/models/manifests/registry.ollama.ai/' . $safeName . '/' . $safeTag;
}

function hub_model_format_bytes(int|float|null $bytes): string
{
    if ($bytes === null) {
        return '-';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}
