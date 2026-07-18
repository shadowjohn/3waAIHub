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

function hub_model_import_roots(PDO $db): array
{
    $raw = str_replace([",", "\r"], "\n", hub_get_storage_setting($db, 'AIHUB_MODEL_IMPORT_ROOTS'));
    $roots = [];
    foreach (explode("\n", $raw) as $root) {
        $root = trim($root);
        if ($root === '' || !hub_is_host_absolute_path($root)) {
            continue;
        }
        $real = realpath(hub_normalize_host_path($root));
        if ($real !== false && is_dir($real)) {
            $roots[] = rtrim(str_replace('\\', '/', $real), '/');
        }
    }

    return array_values(array_unique($roots));
}

function hub_yolo_slug(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        throw new InvalidArgumentException('Invalid YOLO registry slug.');
    }

    return substr($slug, 0, 120);
}

function hub_yolo_assert_detect_task(string $taskType): void
{
    if (trim($taskType) !== 'detect') {
        throw new RuntimeException('model_task_unsupported');
    }
}

function hub_yolo_safe_import_path(PDO $db, string $path): string
{
    $raw = hub_normalize_host_path($path);
    if (!hub_is_host_absolute_path($raw) || str_contains($raw, "\0")) {
        throw new RuntimeException('model_import_path_not_allowed');
    }
    foreach (explode('/', $raw) as $part) {
        if ($part === '..') {
            throw new RuntimeException('model_import_path_not_allowed');
        }
    }
    if (strtolower(pathinfo($raw, PATHINFO_EXTENSION)) !== 'pt') {
        throw new RuntimeException('model_task_unsupported');
    }
    $real = realpath($raw);
    if ($real === false || !is_file($real)) {
        throw new RuntimeException('model_artifact_missing');
    }
    $real = str_replace('\\', '/', $real);
    foreach (hub_model_import_roots($db) as $root) {
        if ($real === $root || str_starts_with($real, $root . '/')) {
            return $real;
        }
    }

    throw new RuntimeException('model_import_path_not_allowed');
}

function hub_yolo_model_version_host_path(PDO $db, array $version): string
{
    return rtrim(hub_models_root($db), '/') . '/' . hub_model_asset_safe_path((string)$version['artifact_path']);
}

function hub_yolo_model_version_container_path(array $version): string
{
    $relative = hub_model_asset_safe_path((string)$version['artifact_path']);
    $prefix = 'yolo/registry/';
    if (!str_starts_with($relative, $prefix)) {
        throw new RuntimeException('model_artifact_missing');
    }

    return hub_container_path('/models/registry/' . substr($relative, strlen($prefix)));
}

function hub_get_yolo_model_version(PDO $db, string $modelRef): ?array
{
    $stmt = $db->prepare('SELECT * FROM yolo_model_versions WHERE model_ref = :model_ref');
    $stmt->execute([':model_ref' => trim($modelRef)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function hub_yolo_register_model_version(PDO $db, array $input, ?callable $validator = null): array
{
    $sourceSystem = hub_yolo_slug((string)($input['source_system'] ?? ''));
    $externalKeyRaw = trim((string)($input['external_model_key'] ?? ''));
    $externalKey = hub_yolo_slug($externalKeyRaw);
    $taskType = trim((string)($input['task_type'] ?? 'detect')) ?: 'detect';
    hub_yolo_assert_detect_task($taskType);

    $artifact = is_array($input['artifact'] ?? null) ? $input['artifact'] : [];
    if (($artifact['type'] ?? '') !== 'host_path') {
        throw new RuntimeException('model_import_path_not_allowed');
    }
    $sourcePath = hub_yolo_safe_import_path($db, (string)($artifact['path'] ?? ''));
    $sha256 = strtolower(trim((string)($artifact['sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
        throw new RuntimeException('model_checksum_mismatch');
    }
    $actualSha = hash_file('sha256', $sourcePath);
    if ($actualSha === false || !hash_equals($sha256, strtolower($actualSha))) {
        throw new RuntimeException('model_checksum_mismatch');
    }

    $existing = hub_yolo_find_model_version_by_source_sha($db, $sourceSystem, $externalKey, $sha256);
    if ($existing) {
        return $existing;
    }

    $validation = $validator ? $validator($sourcePath) : [];
    $validatedTask = (string)($validation['task_type'] ?? $taskType);
    hub_yolo_assert_detect_task($validatedTask);
    $frameworkVersion = trim((string)($validation['framework_version'] ?? ''));
    $labels = $validation['labels'] ?? ($input['metadata']['labels'] ?? []);
    $labels = is_array($labels) ? array_values(array_map('strval', $labels)) : [];
    $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
    $imgsz = (int)($metadata['imgsz'] ?? 0);
    $classCount = (int)($metadata['class_count'] ?? count($labels));

    $version = hub_yolo_next_model_version($db, $sourceSystem, $externalKey);
    $relative = 'yolo/registry/' . $sourceSystem . '/' . $externalKey . '/v' . $version . '/model.pt';
    $target = rtrim(hub_models_root($db), '/') . '/' . $relative;
    $targetDir = dirname($target);
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('model_import_failed');
    }
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(6));
    if (!copy($sourcePath, $tmp)) {
        throw new RuntimeException('model_import_failed');
    }
    chmod($tmp, 0664);
    rename($tmp, $target);

    $now = hub_now();
    $modelRef = 'yolo:' . $sourceSystem . ':' . $externalKey . ':v' . $version;
    $stmt = $db->prepare(
        'INSERT INTO yolo_model_versions
            (model_ref, source_system, external_model_key, version, display_name, task_type, framework, framework_version,
             artifact_path, artifact_size_bytes, sha256, imgsz, class_count, labels_json, metadata_json, source_run_id,
             validation_status, created_at, updated_at)
         VALUES
            (:model_ref, :source_system, :external_model_key, :version, :display_name, :task_type, :framework, :framework_version,
             :artifact_path, :artifact_size_bytes, :sha256, :imgsz, :class_count, :labels_json, :metadata_json, :source_run_id,
             :validation_status, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':model_ref' => $modelRef,
        ':source_system' => $sourceSystem,
        ':external_model_key' => $externalKey,
        ':version' => $version,
        ':display_name' => trim((string)($input['display_name'] ?? '')) ?: $modelRef,
        ':task_type' => $taskType,
        ':framework' => 'ultralytics',
        ':framework_version' => $frameworkVersion !== '' ? $frameworkVersion : null,
        ':artifact_path' => $relative,
        ':artifact_size_bytes' => filesize($target) ?: 0,
        ':sha256' => $sha256,
        ':imgsz' => $imgsz > 0 ? $imgsz : null,
        ':class_count' => $classCount > 0 ? $classCount : null,
        ':labels_json' => json_encode($labels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':source_run_id' => trim((string)($input['source_run_id'] ?? '')) ?: null,
        ':validation_status' => 'registered',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return hub_get_yolo_model_version($db, $modelRef) ?: throw new RuntimeException('model_import_failed');
}

function hub_yolo_find_model_version_by_source_sha(PDO $db, string $sourceSystem, string $externalKey, string $sha256): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM yolo_model_versions
         WHERE source_system = :source_system AND external_model_key = :external_model_key AND sha256 = :sha256
         LIMIT 1'
    );
    $stmt->execute([':source_system' => $sourceSystem, ':external_model_key' => $externalKey, ':sha256' => $sha256]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function hub_yolo_next_model_version(PDO $db, string $sourceSystem, string $externalKey): int
{
    $stmt = $db->prepare(
        'SELECT COALESCE(MAX(version), 0) + 1 FROM yolo_model_versions
         WHERE source_system = :source_system AND external_model_key = :external_model_key'
    );
    $stmt->execute([':source_system' => $sourceSystem, ':external_model_key' => $externalKey]);

    return max(1, (int)$stmt->fetchColumn());
}
