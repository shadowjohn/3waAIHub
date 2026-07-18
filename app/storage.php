<?php
declare(strict_types=1);

function hub_default_storage_settings(): array
{
    return [
        'AIHUB_SITE_TITLE' => '3waAIHub Local',
        'AIHUB_SITE_SUBTITLE' => 'Local AI Service Hub',
        'AIHUB_DATA_DIR' => HUB_DATA_DIR,
        'AIHUB_MODELS_DIR' => '/DATA/models',
        'AIHUB_MODEL_IMPORT_ROOTS' => "/DATA/NaturelID\n/DATA/NatureWeb",
        'AIHUB_CACHE_DIR' => HUB_DATA_DIR . '/cache',
        'AIHUB_UPLOADS_DIR' => HUB_DATA_DIR . '/uploads',
        'AIHUB_RESULTS_DIR' => HUB_DATA_DIR . '/results',
        'AIHUB_LOGS_DIR' => HUB_LOG_DIR,
        'AIHUB_DOCKER_PORT_START' => '18100',
        'AIHUB_DOCKER_PORT_END' => '18999',
        'AIHUB_DB_MAX_SIZE_MB' => '1024',
        'AIHUB_LOG_RETENTION_DAYS' => '14',
        'AIHUB_METRIC_RETENTION_DAYS' => '14',
        'AIHUB_TASK_RETENTION_DAYS' => '30',
        'AIHUB_MAX_TASK_LOG_ROWS' => '1000',
        'AIHUB_MAX_RESULT_JSON_BYTES' => '262144',
        'AIHUB_DOCPARSER_CACHE_TTL_DAYS' => '7',
        'AIHUB_DOCPARSER_CACHE_VERSION' => 'docparser-v0.1',
        'AIHUB_DEFAULT_ALLOW_EXTERNAL_API' => '0',
        'AIHUB_API_ACCESS_LOG_RETENTION_DAYS' => '30',
        'AIHUB_API_LOG_SUCCESS_SAMPLE_RATE' => '1',
        'AIHUB_AUTO_BUILD_MISSING_IMAGE' => '1',
        'AIHUB_REQUIRE_API_TOKEN' => '1',
        'AIHUB_LOCALHOST_BYPASS_TOKEN' => '1',
        'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST' => '1',
        'AIHUB_TOKEN_DEFAULT_VALID_DAYS' => '0',
        'AIHUB_PUBLIC_API_DOCS' => '1',
        'AIHUB_PUBLIC_API_MANIFEST' => '1',
        'AIHUB_PUBLIC_API_LOCAL_ONLY' => '0',
        'AIHUB_LOGIN_MAX_FAILED_ATTEMPTS' => '3',
        'AIHUB_LOGIN_LOCK_MINUTES' => '5',
        'AIHUB_LOGIN_FAIL_WINDOW_MINUTES' => '10',
        'PHOTO_TTL_DAYS' => '7',
        'PHOTO_MAX_UPLOAD_MB' => '10',
        'PHOTO_MAX_WIDTH' => '8192',
        'PHOTO_MAX_HEIGHT' => '8192',
        'PHOTO_MAX_PIXELS' => '25000000',
        'PHOTO_MAX_TOKENS' => '2048',
        'PHOTO_REAL_INFERENCE' => '0',
        'PHOTO_VISION_SERVICE_KEY' => 'gemma4-main',
    ];
}

function hub_site_title(?PDO $db = null): string
{
    try {
        $value = trim(hub_get_storage_setting($db ?? hub_db(), 'AIHUB_SITE_TITLE'));
    } catch (Throwable) {
        $value = '';
    }

    return $value !== '' ? $value : '3waAIHub Local';
}

function hub_site_subtitle(?PDO $db = null): string
{
    try {
        $value = trim(hub_get_storage_setting($db ?? hub_db(), 'AIHUB_SITE_SUBTITLE'));
    } catch (Throwable) {
        $value = '';
    }

    return $value !== '' ? $value : 'Local AI Service Hub';
}

function hub_storage_settings_warnings(array $storage): array
{
    $warnings = [];
    $modelsDir = rtrim((string)($storage['AIHUB_MODELS_DIR'] ?? ''), '/');
    $projectDataDir = rtrim(HUB_DATA_DIR, '/');

    if ($modelsDir === $projectDataDir || str_starts_with($modelsDir, $projectDataDir . '/')) {
        $warnings[] = 'AIHUB_MODELS_DIR is inside project data dir. Recommended: /DATA/models';
    }

    return $warnings;
}

function hub_ensure_default_storage_settings(PDO $db): void
{
    $stmt = $db->prepare('INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)');
    foreach (hub_default_storage_settings() as $key => $value) {
        $stmt->execute([
            ':key' => $key,
            ':value' => (string)$value,
            ':updated_at' => hub_now(),
        ]);
    }
    hub_migrate_public_api_open_access_defaults($db);
}

function hub_migrate_public_api_open_access_defaults(PDO $db): void
{
    $marker = 'AIHUB_PUBLIC_API_OPEN_ACCESS_MIGRATED';
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $marker]);
    if ($stmt->fetchColumn() !== false) {
        return;
    }

    $docs = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS');
    $manifest = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST');
    $localOnly = hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY');
    if ($docs === '0' && $manifest === '1' && $localOnly === '1') {
        hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '1');
        hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '0');
    }
    hub_set_storage_setting($db, $marker, '1');
}

function hub_get_storage_setting(PDO $db, string $key): string
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute([':key' => $key]);
    $value = $stmt->fetchColumn();
    if ($value !== false) {
        return (string)$value;
    }

    return (string)(hub_default_storage_settings()[$key] ?? '');
}

function hub_set_storage_setting(PDO $db, string $key, string $value): void
{
    $stmt = $db->prepare(
        'INSERT INTO settings (key, value, updated_at)
         VALUES (:key, :value, :updated_at)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
        ':updated_at' => hub_now(),
    ]);
}

function hub_get_storage_paths(PDO $db): array
{
    hub_ensure_default_storage_settings($db);
    $paths = [];
    foreach (array_keys(hub_default_storage_settings()) as $key) {
        $paths[$key] = hub_get_storage_setting($db, $key);
    }

    return $paths;
}

function hub_is_safe_absolute_path(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || !str_starts_with($path, '/')) {
        return false;
    }

    $path = rtrim($path, '/') ?: '/';
    return !in_array($path, ['/', '/etc', '/bin', '/usr', '/var/lib/docker'], true);
}

function hub_is_safe_models_root(string $path): bool
{
    if (!hub_is_safe_absolute_path($path)) {
        return false;
    }

    $path = rtrim(preg_replace('#/+#', '/', $path) ?? $path, '/') ?: '/';
    foreach (['/etc', '/var/lib/docker', '/DATA/docker', HUB_ROOT, HUB_DATA_DIR] as $blocked) {
        $blocked = rtrim($blocked, '/');
        if ($path === $blocked || str_starts_with($path, $blocked . '/')) {
            return false;
        }
    }

    return true;
}

function hub_get_disk_usage_for_path(string $path): array
{
    $probe = is_dir($path) ? $path : dirname($path);
    if (!is_dir($probe)) {
        return ['exists' => false, 'readable' => false, 'writable' => false, 'total_bytes' => null, 'free_bytes' => null];
    }

    return [
        'exists' => is_dir($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
        'total_bytes' => disk_total_space($probe),
        'free_bytes' => disk_free_space($probe),
    ];
}

function hub_docker_root_warning(string $dockerRootDir, int|float|null $rootFreeBytes): string
{
    if ($dockerRootDir === '/var/lib/docker' && $rootFreeBytes !== null && $rootFreeBytes < 100 * 1024 * 1024 * 1024) {
        return 'WARNING: Docker data-root is on the root filesystem. Large AI images and model volumes may fill the root disk. Consider moving Docker data-root to /DATA/docker.';
    }

    return '';
}

function hub_validate_storage_input(array $input): array
{
    $errors = [];
    if (!hub_is_safe_models_root(trim((string)($input['AIHUB_MODELS_DIR'] ?? '')))) {
        $errors[] = 'AIHUB_MODELS_DIR 必須是安全的 models root absolute path。';
    }
    foreach (['AIHUB_CACHE_DIR', 'AIHUB_UPLOADS_DIR', 'AIHUB_RESULTS_DIR', 'AIHUB_LOGS_DIR'] as $key) {
        if (!hub_is_safe_absolute_path(trim((string)($input[$key] ?? '')))) {
            $errors[] = $key . ' 必須是安全的 absolute path。';
        }
    }

    $start = (int)($input['AIHUB_DOCKER_PORT_START'] ?? 0);
    $end = (int)($input['AIHUB_DOCKER_PORT_END'] ?? 0);
    if ($start < 1024 || $end > 65535 || $start >= $end) {
        $errors[] = 'Docker local port start/end 不合法。';
    }
    if (isset($input['AIHUB_AUTO_BUILD_MISSING_IMAGE']) && !in_array((string)$input['AIHUB_AUTO_BUILD_MISSING_IMAGE'], ['0', '1'], true)) {
        $errors[] = 'AIHUB_AUTO_BUILD_MISSING_IMAGE 必須是 0 或 1。';
    }
    foreach (['AIHUB_REQUIRE_API_TOKEN', 'AIHUB_LOCALHOST_BYPASS_TOKEN', 'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST', 'AIHUB_PUBLIC_API_DOCS', 'AIHUB_PUBLIC_API_MANIFEST', 'AIHUB_PUBLIC_API_LOCAL_ONLY'] as $key) {
        if (isset($input[$key]) && !in_array((string)$input[$key], ['0', '1'], true)) {
            $errors[] = $key . ' 必須是 0 或 1。';
        }
    }
    if (isset($input['AIHUB_TOKEN_DEFAULT_VALID_DAYS'])) {
        $days = trim((string)$input['AIHUB_TOKEN_DEFAULT_VALID_DAYS']);
        if ($days === '' || !ctype_digit($days) || (int)$days > 3650) {
            $errors[] = 'AIHUB_TOKEN_DEFAULT_VALID_DAYS 必須是 0 到 3650 的整數。';
        }
    }

    return $errors;
}
