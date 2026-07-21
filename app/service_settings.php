<?php
declare(strict_types=1);

function hub_get_pack_settings_schema(?string $packId): array
{
    $packId = trim((string)$packId);
    if ($packId === '') {
        return [];
    }
    $pack = hub_get_pack($packId);
    if (!$pack) {
        return [];
    }
    if (!is_array($pack['manifest']['settings_schema'] ?? null)) {
        return hub_normalize_legacy_env_schema(is_array($pack['manifest']['env'] ?? null) ? $pack['manifest']['env'] : []);
    }

    return hub_normalize_settings_schema($pack['manifest']['settings_schema']);
}

function hub_normalize_legacy_env_schema(array $env): array
{
    $schema = [];
    foreach ($env as $item) {
        if (!is_array($item) || empty($item['name'])) {
            continue;
        }
        $schema[] = [
            'key' => (string)$item['name'],
            'label' => (string)$item['name'],
            'type' => 'text',
            'default' => (string)($item['default'] ?? ''),
            'required' => !empty($item['required']),
            'restart_required' => true,
        ];
    }

    return hub_normalize_settings_schema($schema);
}

function hub_normalize_settings_schema(array $schema): array
{
    $allowedTypes = ['text', 'integer', 'number', 'boolean', 'select', 'path', 'secret'];
    $normalized = [];
    foreach ($schema as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = trim((string)($item['key'] ?? $item['name'] ?? ''));
        $type = trim((string)($item['type'] ?? 'text'));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || !in_array($type, $allowedTypes, true)) {
            continue;
        }
        $item['key'] = $key;
        $item['type'] = $type;
        $item['label'] = trim((string)($item['label'] ?? $key));
        $item['default'] = (string)($item['default'] ?? '');
        $item['required'] = !empty($item['required']);
        $item['restart_required'] = !empty($item['restart_required']);
        $item['secret'] = $type === 'secret' || !empty($item['secret']);
        $normalized[$key] = $item;
    }

    return $normalized;
}

function hub_ensure_service_settings(PDO $db, array $service): array
{
    $schema = hub_get_pack_settings_schema((string)($service['pack_id'] ?? ''));
    if ($schema === []) {
        return [];
    }
    $existing = hub_list_service_settings($db, (int)$service['id']);
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO service_settings
            (service_id, key, value, value_type, is_secret, restart_required, created_at, updated_at)
         VALUES
            (:service_id, :key, :value, :value_type, :is_secret, :restart_required, :created_at, :updated_at)'
    );
    foreach ($schema as $key => $item) {
        if (isset($existing[$key])) {
            continue;
        }
        $stmt->execute([
            ':service_id' => (int)$service['id'],
            ':key' => $key,
            ':value' => hub_service_setting_default($service, $key, $item),
            ':value_type' => (string)$item['type'],
            ':is_secret' => !empty($item['secret']) ? 1 : 0,
            ':restart_required' => !empty($item['restart_required']) ? 1 : 0,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    return hub_list_service_settings($db, (int)$service['id']);
}

function hub_service_setting_default(array $service, string $key, array $item): string
{
    $environmentOverride = hub_service_setting_environment_override($service, $key);
    if ($environmentOverride !== null) {
        return hub_validate_service_setting_value($item, $environmentOverride);
    }
    if ((string)($service['pack_id'] ?? '') === 'ocr-ppocrv5' && hub_service_key_requests_gpu((string)($service['service_key'] ?? ''))) {
        return match ($key) {
            'OCR_USE_GPU' => '1',
            'OCR_DEVICE' => 'gpu',
            'GPU_VISIBLE_DEVICES' => 'all',
            'OCR_GPU_FALLBACK_TO_CPU' => '1',
            default => (string)($item['default'] ?? ''),
        };
    }
    if ((string)($service['pack_id'] ?? '') === 'yolo-serving' && (string)($service['service_key'] ?? '') === 'yolo-gpu0') {
        return match ($key) {
            'YOLO_SERVING_DEVICE' => 'cuda:0',
            'YOLO_GPU_SLOTS' => '2',
            default => (string)($item['default'] ?? ''),
        };
    }

    return (string)($item['default'] ?? '');
}

function hub_service_setting_environment_override(array $service, string $key): ?string
{
    $environment = json_decode((string)($service['environment_json'] ?? ''), true);
    if (!is_array($environment) || !array_key_exists($key, $environment) || !is_scalar($environment[$key])) {
        return null;
    }
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    if (!$pack) {
        return (string)$environment[$key];
    }
    $defaults = hub_pack_env_values($pack['manifest']);
    if (
        hub_service_environment_is_legacy_full_snapshot($environment, $defaults)
        && array_key_exists($key, $defaults)
        && (string)$environment[$key] === (string)$defaults[$key]
    ) {
        return null;
    }

    return (string)$environment[$key];
}

function hub_service_environment_is_legacy_full_snapshot(array $environment, array $defaults): bool
{
    if (count($environment) !== count($defaults)) {
        return false;
    }
    foreach ($defaults as $key => $_value) {
        if (!array_key_exists($key, $environment)) {
            return false;
        }
    }

    return true;
}

function hub_list_service_settings(PDO $db, int $serviceId): array
{
    $stmt = $db->prepare('SELECT * FROM service_settings WHERE service_id = :service_id ORDER BY id ASC');
    $stmt->execute([':service_id' => $serviceId]);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(string)$row['key']] = $row;
    }

    return $rows;
}

function hub_validate_service_setting_value(array $schema, string $value): string
{
    $key = (string)($schema['key'] ?? 'setting');
    $type = (string)($schema['type'] ?? 'text');
    if (!empty($schema['required']) && $value === '') {
        throw new InvalidArgumentException($key . ' is required.');
    }
    if ($value === '' && empty($schema['required'])) {
        return '';
    }

    if ($type === 'integer') {
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException($key . ' must be an integer.');
        }
        hub_validate_service_setting_range($schema, (float)(int)$value, $key);
        return (string)(int)$value;
    }
    if ($type === 'number') {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($key . ' must be a number.');
        }
        hub_validate_service_setting_range($schema, (float)$value, $key);
        return (string)(float)$value;
    }
    if ($type === 'boolean') {
        if (!in_array($value, ['0', '1'], true)) {
            throw new InvalidArgumentException($key . ' must be 0 or 1.');
        }
        return $value;
    }
    if ($type === 'select') {
        $options = is_array($schema['options'] ?? null) ? array_map('strval', $schema['options']) : [];
        if (!in_array($value, $options, true)) {
            throw new InvalidArgumentException($key . ' must be one of the allowed options.');
        }
        return $value;
    }
    if ($type === 'path') {
        if (!hub_is_safe_absolute_path($value)) {
            throw new InvalidArgumentException($key . ' must be a safe absolute path.');
        }
        return rtrim($value, '/') ?: '/';
    }
    if ($type === 'text' || $type === 'secret') {
        $max = (int)($schema['max'] ?? 2048);
        if (strlen($value) > max(1, $max)) {
            throw new InvalidArgumentException($key . ' is too long.');
        }
        return $value;
    }

    throw new InvalidArgumentException($key . ' type is not supported.');
}

function hub_validate_service_setting_range(array $schema, float $value, string $key): void
{
    if (isset($schema['min']) && $value < (float)$schema['min']) {
        throw new InvalidArgumentException($key . ' is below minimum.');
    }
    if (isset($schema['max']) && $value > (float)$schema['max']) {
        throw new InvalidArgumentException($key . ' is above maximum.');
    }
}

function hub_update_service_settings(PDO $db, int $serviceId, array $values): array
{
    $service = hub_get_service($db, $serviceId);
    if (!$service) {
        throw new InvalidArgumentException('Service not found.');
    }
    $schema = hub_get_pack_settings_schema((string)$service['pack_id']);
    $settings = hub_ensure_service_settings($db, $service);
    $changed = false;
    $needsRestart = false;
    $now = hub_now();
    $stmt = $db->prepare('UPDATE service_settings SET value = :value, updated_at = :updated_at WHERE service_id = :service_id AND key = :key');

    foreach ($values as $key => $rawValue) {
        $key = (string)$key;
        if (!isset($schema[$key])) {
            throw new InvalidArgumentException('Setting key is not declared: ' . $key);
        }
        $item = $schema[$key];
        if (!empty($item['secret']) && (string)$rawValue === '') {
            continue;
        }
        $value = hub_validate_service_setting_value($item, (string)$rawValue);
        if (!isset($settings[$key]) || (string)$settings[$key]['value'] !== $value) {
            $stmt->execute([
                ':value' => $value,
                ':updated_at' => $now,
                ':service_id' => $serviceId,
                ':key' => $key,
            ]);
            $changed = true;
            $needsRestart = $needsRestart || !empty($item['restart_required']);
        }
    }

    if ($changed) {
        hub_write_service_env($db, $service);
        hub_write_service_compose($db, $service);
        $restartSql = $needsRestart ? 'restart_required = 1' : 'restart_required = restart_required';
        $db->prepare(
            'UPDATE services
             SET config_dirty = 0,
                 ' . $restartSql . ',
                 updated_at = :updated_at
             WHERE id = :id'
        )->execute([
            ':updated_at' => $now,
            ':id' => $serviceId,
        ]);
    }

    return ['changed' => $changed, 'restart_required' => $needsRestart];
}

function hub_service_settings_values(PDO $db, array $service): array
{
    $settings = hub_ensure_service_settings($db, $service);
    $values = [];
    foreach ($settings as $key => $row) {
        $values[$key] = (string)$row['value'];
    }

    return $values;
}

function hub_generate_service_env_for_instance(PDO $db, array $service): string
{
    $pack = hub_get_pack((string)$service['pack_id']);
    if (!$pack) {
        throw new RuntimeException('Pack is not available for service settings.');
    }
    $manifest = $pack['manifest'];
    $storage = hub_get_storage_paths($db);
    $runtimeDir = dirname(hub_path((string)$service['compose_file']));
    $portEnv = hub_pack_port_env($manifest);
    $values = array_merge([
        'AIHUB_MODELS_DIR' => $storage['AIHUB_MODELS_DIR'],
        'AIHUB_CACHE_DIR' => $storage['AIHUB_CACHE_DIR'],
        'AIHUB_UPLOADS_DIR' => $storage['AIHUB_UPLOADS_DIR'],
        'AIHUB_RESULTS_DIR' => $storage['AIHUB_RESULTS_DIR'],
        'AIHUB_LOGS_DIR' => $storage['AIHUB_LOGS_DIR'],
        'SERVICE_DATA_DIR' => $runtimeDir,
        'LOCAL_PORT' => (string)$service['local_port'],
        $portEnv => (string)$service['local_port'],
        'SERVICE_KEY' => (string)$service['service_key'],
        'MODE' => (string)$service['mode'],
    ], hub_pack_storage_runtime_env($manifest), hub_service_settings_values($db, $service));

    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = $key . '=' . $value;
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function hub_write_service_env(PDO $db, array $service): string
{
    $runtimeDir = dirname(hub_path((string)$service['compose_file']));
    if (!is_dir($runtimeDir) && !mkdir($runtimeDir, 0775, true) && !is_dir($runtimeDir)) {
        throw new RuntimeException('Cannot create service runtime directory.');
    }
    $envPath = $runtimeDir . '/.env';
    file_put_contents($envPath, hub_generate_service_env_for_instance($db, $service));
    chmod($envPath, 0664);

    return $envPath;
}

function hub_write_service_compose(PDO $db, array $service): string
{
    $pack = hub_get_pack((string)$service['pack_id']);
    if (!$pack) {
        throw new RuntimeException('Pack is not available for service settings.');
    }
    $manifest = $pack['manifest'];
    if (hub_pack_is_internal_task($manifest)) {
        return '';
    }

    $composePath = hub_path((string)$service['compose_file']);
    file_put_contents($composePath, hub_generate_pack_compose(
        $pack,
        (string)$service['service_key'],
        (int)$service['local_port'],
        hub_service_settings_values($db, $service),
    ));
    chmod($composePath, 0664);

    return $composePath;
}
