<?php
declare(strict_types=1);

function hub_list_packs(): array
{
    $packs = [];
    $seen = [];
    foreach (hub_load_pack_catalog()['packs'] as $entry) {
        $pack = hub_read_pack_from_catalog_entry($entry);
        $packs[] = $pack;
        $seen[$pack['id']] = true;
    }

    foreach (glob(HUB_ROOT . '/packs/*/pack.json') ?: [] as $manifestPath) {
        $fallbackId = basename(dirname($manifestPath));
        if (isset($seen[$fallbackId])) {
            continue;
        }
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $errors = is_array($manifest) ? hub_validate_pack_manifest($manifest, dirname($manifestPath)) : ['pack.json is not valid JSON.'];
        $packs[] = hub_pack_record($fallbackId, dirname($manifestPath), $manifestPath, is_array($manifest) ? $manifest : [], $errors);
    }

    usort($packs, static fn (array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
    return $packs;
}

function hub_load_pack_catalog(): array
{
    $path = HUB_ROOT . '/packs/catalog.json';
    if (!is_file($path)) {
        return ['schema_version' => '0.1', 'packs' => []];
    }

    $catalog = json_decode((string)file_get_contents($path), true);
    if (!is_array($catalog)) {
        return ['schema_version' => '', 'packs' => []];
    }
    $catalog['packs'] = is_array($catalog['packs'] ?? null) ? $catalog['packs'] : [];

    return $catalog;
}

function hub_list_catalog_packs(): array
{
    $packs = [];
    foreach (hub_load_pack_catalog()['packs'] as $entry) {
        $packs[] = hub_read_pack_from_catalog_entry($entry);
    }

    return $packs;
}

function hub_read_pack_from_catalog_entry(array $entry): array
{
    $packDir = HUB_ROOT . '/' . trim((string)($entry['path'] ?? ''), '/');
    $manifestPath = $packDir . '/pack.json';
    $manifest = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : null;
    $errors = is_array($manifest) ? hub_validate_pack_manifest($manifest, $packDir) : ['pack.json not found or invalid JSON.'];
    if (is_array($manifest)) {
        $manifest['category'] = (string)($manifest['category'] ?? $entry['category'] ?? '');
        $manifest['description'] = (string)($manifest['description'] ?? $entry['description'] ?? '');
    }

    return hub_pack_record((string)($entry['id'] ?? ''), $packDir, $manifestPath, is_array($manifest) ? $manifest : [], $errors, $entry);
}

function hub_pack_record(string $fallbackId, string $packDir, string $manifestPath, array $manifest, array $errors, array $catalog = []): array
{
    return [
        'id' => (string)($manifest['id'] ?? $fallbackId),
        'category' => (string)($manifest['category'] ?? $catalog['category'] ?? ''),
        'description' => (string)($manifest['description'] ?? $catalog['description'] ?? ''),
        'catalog' => $catalog,
        'dir' => $packDir,
        'manifest_path' => $manifestPath,
        'manifest' => $manifest,
        'status' => $errors ? 'error' : 'ok',
        'errors' => $errors,
    ];
}

function hub_get_pack(string $packId): ?array
{
    foreach (hub_list_packs() as $pack) {
        if (($pack['id'] ?? '') === $packId) {
            return $pack;
        }
    }

    return null;
}

function hub_pack_is_internal_task(array $manifest): bool
{
    return (string)($manifest['runtime']['kind'] ?? '') === 'internal_task';
}

function hub_validate_pack_manifest(array $manifest, string $packDir): array
{
    $errors = [];
    foreach (['schema_version', 'id', 'name', 'version', 'category', 'type', 'execution_type', 'runtime_level', 'runtime_ready', 'default_mode', 'description', 'runtime', 'gateway', 'hardware', 'queue', 'storage', 'env', 'preflight'] as $field) {
        if (!array_key_exists($field, $manifest)) {
            $errors[] = 'Missing required field: ' . $field;
        }
    }
    if (($manifest['schema_version'] ?? '') !== '0.1') {
        $errors[] = 'Unsupported schema_version.';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string)($manifest['id'] ?? ''))) {
        $errors[] = 'Invalid id.';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_]*$/', (string)($manifest['default_mode'] ?? ''))) {
        $errors[] = 'Invalid default_mode.';
    }
    if (!in_array((string)($manifest['execution_type'] ?? ''), ['sync_api', 'async_task', 'long_job'], true)) {
        $errors[] = 'Invalid execution_type.';
    }
    if (!is_string($manifest['runtime_level'] ?? null) || trim((string)$manifest['runtime_level']) === '') {
        $errors[] = 'runtime_level must be a non-empty string.';
    }
    if (!is_bool($manifest['runtime_ready'] ?? null)) {
        $errors[] = 'runtime_ready must be boolean.';
    }

    $runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
    if (hub_pack_is_internal_task($manifest)) {
        if ((string)($manifest['execution_type'] ?? '') !== 'async_task') {
            $errors[] = 'internal_task runtime requires async_task execution_type.';
        }
    } else {
        if (!is_file($packDir . '/' . (string)($runtime['compose_file'] ?? ''))) {
            $errors[] = 'runtime.compose_file not found.';
        }
        if ((int)($runtime['default_internal_port'] ?? 0) <= 0) {
            $errors[] = 'runtime.default_internal_port is required.';
        }
    }

    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    if (($gateway['invoke_path'] ?? '') === '') {
        $errors[] = 'Missing required gateway field: invoke_path';
    }
    if (!hub_pack_is_internal_task($manifest) && ($gateway['health_path'] ?? '') === '') {
        $errors[] = 'Missing required gateway field: health_path';
    }

    $hardware = is_array($manifest['hardware'] ?? null) ? $manifest['hardware'] : [];
    if (!is_bool($hardware['gpu_required'] ?? null)) {
        $errors[] = 'hardware.gpu_required must be boolean.';
    }

    $preflight = is_array($manifest['preflight'] ?? null) ? $manifest['preflight'] : [];
    if (!is_array($preflight['checks'] ?? null)) {
        $errors[] = 'preflight.checks must be an array.';
    }

    $service = is_array($manifest['service'] ?? null) ? $manifest['service'] : [];
    if (isset($service['default_local_port']) && !hub_validate_service_port((int)$service['default_local_port'])) {
        $errors[] = 'service.default_local_port must be in configured Docker port range.';
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', (string)($service['local_port_env'] ?? hub_default_port_env((string)($manifest['id'] ?? 'PACK'))))) {
        $errors[] = 'service.local_port_env must be an env var name.';
    }

    return $errors;
}

function hub_install_pack(PDO $db, string $packId, array|string|null $options = null): array
{
    hub_ensure_default_storage_settings($db);
    $pack = hub_get_pack($packId);
    if (!$pack || $pack['status'] !== 'ok') {
        throw new RuntimeException('HubPack is not available or has validation errors.');
    }

    $legacyIdempotent = is_string($options);
    $options = is_string($options) ? ['service_key' => $options, 'idempotent' => true] : ($options ?? []);
    $manifest = $pack['manifest'];
    $serviceKey = trim((string)($options['service_key'] ?? $manifest['install']['default_service_key'] ?? ($manifest['id'] . '-main')));
    $mode = trim((string)($options['mode'] ?? $manifest['default_mode']));
    $name = trim((string)($options['name'] ?? $manifest['name']));
    $portMode = (string)($options['port_mode'] ?? 'auto');
    $environment = (string)($options['environment'] ?? 'production');
    $hotReload = !empty($options['hot_reload']) ? 1 : 0;
    $idempotent = !empty($options['idempotent']) || $legacyIdempotent;
    $envValues = hub_pack_env_values($manifest, is_array($options['env'] ?? null) ? $options['env'] : []);

    hub_validate_service_instance_input($serviceKey, $mode, $name, $portMode, $environment);
    $existingByKey = hub_get_service_by_key($db, $serviceKey);
    $existingByMode = hub_get_service_by_mode($db, $mode);
    if ($existingByKey && !$idempotent) {
        throw new RuntimeException('service_key already exists.');
    }
    if ($existingByMode && (!$idempotent || ($existingByKey && (int)$existingByMode['id'] !== (int)$existingByKey['id']))) {
        throw new RuntimeException('mode already exists.');
    }
    $existing = $idempotent ? ($existingByKey ?: $existingByMode) : null;

    $isInternalTask = hub_pack_is_internal_task($manifest);
    $localPort = $isInternalTask
        ? null
        : hub_resolve_install_port($db, $manifest, $portMode, $options['local_port'] ?? null, $existing ? (int)$existing['id'] : null);
    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
        throw new RuntimeException('Cannot create service runtime directory.');
    }

    $storage = hub_get_storage_paths($db);
    hub_ensure_pack_storage_dirs($manifest, $serviceKey, $storage, $runtimeDir);
    $composeFile = hub_pack_compose_file($db, $serviceKey);
    $envFile = $runtimeDir . '/.env';
    $portEnv = hub_pack_port_env($manifest);
    file_put_contents($envFile, hub_generate_service_env($manifest, $envValues, $portEnv, (int)($localPort ?? 0), $runtimeDir, $storage));
    file_put_contents(hub_path($composeFile), $isInternalTask ? hub_generate_internal_task_compose($manifest) : hub_generate_pack_compose($pack, $serviceKey, (int)$localPort));
    chmod($envFile, 0664);
    chmod(hub_path($composeFile), 0664);

    $now = hub_now();
    $composeProject = hub_compose_project_for_instance($manifest, $serviceKey);
    $values = [
        ':name' => $name,
        ':mode' => $mode,
        ':type' => (string)$manifest['type'],
        ':internal_url' => $isInternalTask ? 'internal-task:' . (string)$manifest['gateway']['invoke_path'] : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['invoke_path'],
        ':health_url' => $isInternalTask ? 'internal-task:health' : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['health_path'],
        ':compose_project' => $composeProject,
        ':compose_file' => $composeFile,
        ':local_port' => $localPort,
        ':port_mode' => $portMode,
        ':hot_reload' => $hotReload,
        ':environment' => $environment,
        ':execution_type' => (string)$manifest['execution_type'],
        ':environment_json' => json_encode($envValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':pack_id' => (string)$manifest['id'],
        ':pack_version' => (string)$manifest['version'],
        ':service_key' => $serviceKey,
        ':install_status' => 'installed',
        ':runtime_status' => (string)($existing['runtime_status'] ?? $existing['status'] ?? 'stopped'),
        ':status' => (string)($existing['status'] ?? 'stopped'),
        ':created_at' => $now,
        ':updated_at' => $now,
    ];

    if ($existing) {
        $values[':id'] = (int)$existing['id'];
        $stmt = $db->prepare(
            'UPDATE services SET
                name = :name, mode = :mode, type = :type, internal_url = :internal_url, health_url = :health_url,
                compose_project = :compose_project, compose_file = :compose_file, local_port = :local_port,
                port_mode = :port_mode, hot_reload = :hot_reload, environment = :environment,
                execution_type = :execution_type, environment_json = :environment_json, pack_id = :pack_id,
                pack_version = :pack_version, service_key = :service_key, install_status = :install_status,
                runtime_status = :runtime_status, updated_at = :updated_at
             WHERE id = :id'
        );
        unset($values[':status'], $values[':created_at']);
        $stmt->execute($values);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO services
                (name, mode, type, internal_url, health_url, compose_project, compose_file, local_port, port_mode, hot_reload, environment, execution_type, environment_json, pack_id, pack_version, service_key, install_status, runtime_status, enabled, status, created_at, updated_at)
             VALUES
                (:name, :mode, :type, :internal_url, :health_url, :compose_project, :compose_file, :local_port, :port_mode, :hot_reload, :environment, :execution_type, :environment_json, :pack_id, :pack_version, :service_key, :install_status, :runtime_status, 0, :status, :created_at, :updated_at)'
        );
        $stmt->execute($values);
    }

    $service = hub_get_service_by_key($db, $serviceKey);
    if ($service) {
        hub_ensure_service_settings($db, $service);
        hub_write_service_env($db, $service);
    }

    return [
        'pack' => $pack,
        'service' => $service,
    ];
}

function hub_validate_service_instance_input(string $serviceKey, string $mode, string $name, string $portMode, string $environment): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey)) {
        throw new RuntimeException('Invalid service_key.');
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_]*$/', $mode)) {
        throw new RuntimeException('Invalid mode.');
    }
    if ($name === '') {
        throw new RuntimeException('Display name is required.');
    }
    if (!in_array($portMode, ['auto', 'manual'], true)) {
        throw new RuntimeException('Invalid port_mode.');
    }
    if (!in_array($environment, ['production', 'development'], true)) {
        throw new RuntimeException('Invalid environment.');
    }
}

function hub_resolve_install_port(PDO $db, array $manifest, string $portMode, mixed $requestedPort, ?int $existingId): int
{
    if ($portMode === 'manual') {
        $port = (int)$requestedPort;
        if (!hub_validate_service_port($port, $db)) {
            throw new RuntimeException('Invalid local_port.');
        }
        if (hub_local_port_is_used($db, $port, $existingId)) {
            throw new RuntimeException('local_port already exists.');
        }
        return $port;
    }

    if ($existingId) {
        $stmt = $db->prepare('SELECT local_port FROM services WHERE id = :id');
        $stmt->execute([':id' => $existingId]);
        $existingPort = (int)$stmt->fetchColumn();
        if ($existingPort > 0) {
            return $existingPort;
        }
    }

    $defaultPort = (int)($manifest['service']['default_local_port'] ?? 0);
    if ($defaultPort > 0 && hub_port_is_usable_for_install($db, $defaultPort, $existingId)) {
        return $defaultPort;
    }

    return hub_allocate_local_port($db);
}

function hub_local_port_is_used(PDO $db, int $port, ?int $exceptServiceId = null): bool
{
    $sql = 'SELECT COUNT(*) FROM services WHERE local_port = :local_port';
    $params = [':local_port' => $port];
    if ($exceptServiceId !== null) {
        $sql .= ' AND id != :id';
        $params[':id'] = $exceptServiceId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_pack_env_values(array $manifest, array $overrides = []): array
{
    $values = [];
    foreach (($manifest['env'] ?? []) as $item) {
        if (is_array($item) && !empty($item['name'])) {
            $values[(string)$item['name']] = (string)($overrides[$item['name']] ?? $item['default'] ?? '');
        }
    }

    return $values;
}

function hub_generate_service_env(array $manifest, array $envValues, string $portEnv, int $localPort, string $runtimeDir, array $storage): string
{
    $values = array_merge([
        $portEnv => (string)$localPort,
        'SERVICE_DATA_DIR' => $runtimeDir,
        'AIHUB_MODELS_DIR' => $storage['AIHUB_MODELS_DIR'],
        'AIHUB_CACHE_DIR' => $storage['AIHUB_CACHE_DIR'],
        'AIHUB_UPLOADS_DIR' => $storage['AIHUB_UPLOADS_DIR'],
        'AIHUB_RESULTS_DIR' => $storage['AIHUB_RESULTS_DIR'],
        'AIHUB_LOGS_DIR' => $storage['AIHUB_LOGS_DIR'],
    ], hub_pack_storage_runtime_env($manifest), $envValues);

    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = $key . '=' . $value;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function hub_pack_storage_runtime_env(array $manifest): array
{
    $paths = [];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($mount['container_path'])) {
            continue;
        }
        $paths[(string)($mount['type'] ?? '')] = (string)$mount['container_path'];
    }

    $modelDir = $paths['models'] ?? '';
    $cacheDir = $paths['cache'] ?? '';
    $serviceDataDir = $paths['service_data'] ?? ($paths['service'] ?? '');
    if ($modelDir === '' || $cacheDir === '' || $serviceDataDir === '') {
        return [];
    }

    return match ((string)($manifest['id'] ?? '')) {
        'ocr-ppocrv5' => [
            'OCR_MODEL_DIR' => $modelDir,
            'OCR_CACHE_DIR' => $cacheDir,
            'OCR_SERVICE_DATA_DIR' => $serviceDataDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $modelDir . '/home',
            'PADDLEOCR_HOME' => $modelDir,
            'PADDLE_PDX_CACHE_HOME' => $modelDir,
            'PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK' => 'True',
        ],
        'yolo' => [
            'YOLO_MODEL_DIR' => $modelDir,
            'YOLO_CACHE_DIR' => $cacheDir,
            'YOLO_SERVICE_DATA_DIR' => $serviceDataDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'ULTRALYTICS_SETTINGS_DIR' => $cacheDir . '/ultralytics',
            'YOLO_CONFIG_DIR' => $cacheDir . '/ultralytics',
        ],
        'translate-gemma12b' => [
            'TRANSLATE_MODEL_DIR' => '/models/ollama',
            'TRANSLATE_CACHE_DIR' => $cacheDir,
            'TRANSLATE_SERVICE_DATA_DIR' => $serviceDataDir,
            'OLLAMA_BASE_URL' => 'http://ollama:11434',
        ],
        'sam3' => [
            'SAM3_MODEL_DIR' => $modelDir,
            'SAM3_CACHE_DIR' => $cacheDir,
            'SAM3_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'TORCH_HOME' => $modelDir . '/torch',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'whisper-asr' => [
            'WHISPER_MODEL_DIR' => $modelDir,
            'WHISPER_CACHE_DIR' => $cacheDir,
            'WHISPER_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'bioclip' => [
            'BIOCLIP_MODEL_DIR' => $modelDir,
            'BIOCLIP_CACHE_DIR' => $cacheDir,
            'BIOCLIP_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'tts-voxcpm2' => [
            'VOXCPM2_MODEL_DIR' => $modelDir,
            'VOXCPM2_CACHE_DIR' => $cacheDir,
            'VOXCPM2_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir . '/huggingface',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'llm-gemma4-12b' => [
            'GEMMA4_CACHE_DIR' => $cacheDir,
            'GEMMA4_SERVICE_DATA_DIR' => $serviceDataDir,
            'HF_HOME' => $modelDir,
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $cacheDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        'structure-ppstructurev3' => [
            'STRUCTURE_MODEL_DIR' => $modelDir,
            'STRUCTURE_CACHE_DIR' => $cacheDir,
            'STRUCTURE_SERVICE_DATA_DIR' => $serviceDataDir,
            'STRUCTURE_DEVICE' => 'cpu',
            'XDG_CACHE_HOME' => $cacheDir . '/xdg',
            'HOME' => $modelDir . '/home',
            'PYTHONUNBUFFERED' => '1',
        ],
        default => [],
    };
}

function hub_port_is_usable_for_install(PDO $db, int $port, ?int $exceptServiceId = null): bool
{
    if (!hub_validate_service_port($port, $db) || hub_local_port_is_used($db, $port, $exceptServiceId)) {
        return false;
    }
    if (hub_db_is_runtime_db($db) && hub_port_is_busy($port)) {
        return false;
    }

    return true;
}

function hub_db_file(PDO $db): string
{
    $rows = $db->query('PRAGMA database_list')->fetchAll();
    return (string)($rows[0]['file'] ?? '');
}

function hub_db_is_runtime_db(PDO $db): bool
{
    $path = hub_db_file($db);
    $runtimeDb = HUB_DATA_DIR . '/3waaihub.sqlite';

    return $path !== '' && realpath($path) === realpath($runtimeDb);
}

function hub_pack_runtime_base_dir(PDO $db): string
{
    if (hub_db_is_runtime_db($db)) {
        return HUB_SERVICE_DIR;
    }

    $dbFile = hub_db_file($db);
    $suffix = substr(sha1($dbFile !== '' ? $dbFile : spl_object_id($db)), 0, 12);

    return HUB_DATA_DIR . '/test_services/' . $suffix;
}

function hub_pack_runtime_dir(PDO $db, string $serviceKey): string
{
    return hub_pack_runtime_base_dir($db) . '/' . $serviceKey;
}

function hub_pack_compose_file(PDO $db, string $serviceKey): string
{
    if (hub_db_is_runtime_db($db)) {
        return 'data/services/' . $serviceKey . '/docker-compose.generated.yml';
    }

    return 'data/test_services/' . basename(hub_pack_runtime_base_dir($db)) . '/' . $serviceKey . '/docker-compose.generated.yml';
}

function hub_service_key_requests_gpu(string $serviceKey): bool
{
    return preg_match('/(^|[-_])gpu($|[-_])/', strtolower($serviceKey)) === 1;
}

function hub_pack_requests_gpu(array $manifest, string $serviceKey = ''): bool
{
    if (!empty($manifest['hardware']['gpu_required'])) {
        return true;
    }
    if (($manifest['id'] ?? '') === 'ocr-ppocrv5') {
        return hub_service_key_requests_gpu($serviceKey);
    }

    foreach (($manifest['env'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = strtoupper((string)($item['name'] ?? ''));
        if ($name !== 'USE_GPU' && !str_ends_with($name, '_USE_GPU')) {
            continue;
        }
        $default = strtolower(trim((string)($item['default'] ?? '0')));

        return !in_array($default, ['', '0', 'false', 'no', 'off'], true);
    }

    return false;
}

function hub_generate_pack_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    if (($manifest['id'] ?? '') === 'translate-gemma12b') {
        return hub_generate_translate_gemma_compose($pack, $serviceKey, $localPort);
    }
    if (($manifest['id'] ?? '') === 'llm-gemma4-12b') {
        return hub_generate_llm_gemma4_compose($pack, $serviceKey, $localPort);
    }

    $composeService = ($manifest['id'] ?? '') === 'hello' && $serviceKey === 'hello-main' ? 'hello' : $serviceKey;
    $containerName = ($manifest['id'] ?? '') === 'hello' && $serviceKey === 'hello-main' ? '3waaihub-hello' : '3waaihub-' . $serviceKey;
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $imageTag = hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));

    $compose = "services:\n"
        . "  {$composeService}:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . "    container_name: {$containerName}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . (int)$manifest['runtime']['default_internal_port'] . '"' . "\n"
        . "    restart: unless-stopped\n";

    if (hub_pack_requests_gpu($manifest, $serviceKey)) {
        $compose .= "    gpus: all\n"
            . "    environment:\n"
            . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"' . "\n"
            . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n";
    }

    $volumes = hub_generate_pack_storage_volumes($manifest, $serviceKey);
    if ($volumes) {
        $compose .= "    volumes:\n";
        foreach ($volumes as $volume) {
            $compose .= '      - "' . $volume . '"' . "\n";
        }
    }

    return $compose;
}

function hub_generate_translate_gemma_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $imageTag = hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));
    $internalPort = (int)($manifest['runtime']['default_internal_port'] ?? 8000);

    return "services:\n"
        . "  ollama:\n"
        . "    image: ollama/ollama:latest\n"
        . "    container_name: 3waaihub-{$serviceKey}-ollama\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    environment:\n"
        . '      OLLAMA_HOST: "0.0.0.0:11434"' . "\n"
        . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"' . "\n"
        . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n"
        . "    gpus: all\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_MODELS_DIR}/ollama:/root/.ollama"' . "\n"
        . "  translator-api:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . "    container_name: 3waaihub-{$serviceKey}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    depends_on:\n"
        . "      - ollama\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . $internalPort . '"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_CACHE_DIR}/translate:/cache/translate"' . "\n"
        . '      - "${SERVICE_DATA_DIR}:/data/service"' . "\n";
}

function hub_generate_llm_gemma4_compose(array $pack, string $serviceKey, int $localPort): string
{
    $manifest = $pack['manifest'];
    $portEnv = hub_pack_port_env($manifest);
    $buildContext = $pack['dir'] . '/service';
    $imageTag = hub_pack_image_tag($serviceKey, (string)($manifest['version'] ?? 'latest'));
    $internalPort = (int)($manifest['runtime']['default_internal_port'] ?? 8000);

    return "services:\n"
        . "  vllm:\n"
        . "    image: vllm/vllm-openai:latest\n"
        . "    container_name: 3waaihub-{$serviceKey}-vllm\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    entrypoint: [\"/bin/bash\", \"-lc\"]\n"
        . "    command:\n"
        . "      - >-\n"
        . '        exec vllm serve "${VLLM_MODEL}"' . "\n"
        . '        --served-model-name "${VLLM_SERVED_MODEL_NAME:-gemma4-12b}"' . "\n"
        . "        --host 0.0.0.0\n"
        . "        --port 8000\n"
        . '        --max-model-len "${VLLM_MAX_MODEL_LEN:-16384}"' . "\n"
        . '        --gpu-memory-utilization "${VLLM_GPU_MEMORY_UTILIZATION:-0.64}"' . "\n"
        . '        --max-num-seqs "${VLLM_MAX_NUM_SEQS:-1}"' . "\n"
        . "    gpus: all\n"
        . "    environment:\n"
        . '      NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"' . "\n"
        . '      NVIDIA_DRIVER_CAPABILITIES: "compute,utility"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_MODELS_DIR}/huggingface:/root/.cache/huggingface"' . "\n"
        . '      - "${AIHUB_CACHE_DIR}/gemma4:/cache/gemma4"' . "\n"
        . "  chat-api:\n"
        . "    image: {$imageTag}\n"
        . "    build:\n"
        . "      context: {$buildContext}\n"
        . "    container_name: 3waaihub-{$serviceKey}\n"
        . "    env_file:\n"
        . "      - .env\n"
        . "    depends_on:\n"
        . "      - vllm\n"
        . "    ports:\n"
        . '      - "127.0.0.1:${' . $portEnv . ':-' . $localPort . '}:' . $internalPort . '"' . "\n"
        . "    restart: unless-stopped\n"
        . "    volumes:\n"
        . '      - "${AIHUB_CACHE_DIR}/gemma4:/cache/gemma4"' . "\n"
        . '      - "${SERVICE_DATA_DIR}:/data/service"' . "\n"
        . '      - "${AIHUB_UPLOADS_DIR}/photo:/data/photo:ro"' . "\n";
}

function hub_generate_internal_task_compose(array $manifest): string
{
    return "# 3waAIHub internal_task runtime\n"
        . "# pack_id=" . (string)($manifest['id'] ?? '') . "\n"
        . "# no Docker service is required; task_worker.php executes this orchestrator.\n";
}

function hub_pack_image_tag(string $serviceKey, string $packVersion): string
{
    $tag = preg_replace('/[^A-Za-z0-9_.-]/', '-', $packVersion) ?: 'latest';
    return '3waaihub-' . $serviceKey . ':' . $tag;
}

function hub_ensure_pack_storage_dirs(array $manifest, string $serviceKey, array $storage, ?string $serviceDir = null): void
{
    $prefix = [
        'models' => $storage['AIHUB_MODELS_DIR'],
        'cache' => $storage['AIHUB_CACHE_DIR'],
        'uploads' => $storage['AIHUB_UPLOADS_DIR'],
        'results' => $storage['AIHUB_RESULTS_DIR'],
        'service' => $serviceDir ?? HUB_SERVICE_DIR . '/' . $serviceKey,
        'service_data' => $serviceDir ?? HUB_SERVICE_DIR . '/' . $serviceKey,
    ];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($prefix[$mount['type'] ?? ''])) {
            continue;
        }
        $hostSubdir = trim((string)($mount['host_subdir'] ?? ''), '/');
        $dir = $prefix[(string)$mount['type']] . ($hostSubdir !== '' ? '/' . $hostSubdir : '');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create pack storage directory: ' . $dir);
        }
    }
}

function hub_generate_pack_storage_volumes(array $manifest, string $serviceKey): array
{
    $prefix = [
        'models' => '${AIHUB_MODELS_DIR}',
        'cache' => '${AIHUB_CACHE_DIR}',
        'uploads' => '${AIHUB_UPLOADS_DIR}',
        'results' => '${AIHUB_RESULTS_DIR}',
        'service' => '${SERVICE_DATA_DIR}',
        'service_data' => '${SERVICE_DATA_DIR}',
    ];
    $volumes = [];
    foreach (($manifest['storage']['mounts'] ?? []) as $mount) {
        if (!is_array($mount) || empty($prefix[$mount['type'] ?? '']) || empty($mount['container_path'])) {
            continue;
        }
        $hostSubdir = trim((string)($mount['host_subdir'] ?? $serviceKey), '/');
        $host = $prefix[(string)$mount['type']] . ($hostSubdir !== '' ? '/' . $hostSubdir : '');
        $mode = !empty($mount['read_only']) ? ':ro' : '';
        $volumes[] = $host . ':' . (string)$mount['container_path'] . $mode;
    }

    return $volumes;
}

function hub_pack_port_env(array $manifest): string
{
    return (string)($manifest['service']['local_port_env'] ?? hub_default_port_env((string)$manifest['id']));
}

function hub_default_port_env(string $packId): string
{
    return strtoupper(str_replace('-', '_', $packId)) . '_LOCAL_PORT';
}

function hub_compose_project_for_instance(array $manifest, string $serviceKey): string
{
    if (($manifest['install']['default_service_key'] ?? '') === $serviceKey && !empty($manifest['install']['compose_project'])) {
        return (string)$manifest['install']['compose_project'];
    }

    return '3waaihub_' . str_replace('-', '_', $serviceKey);
}
