<?php
declare(strict_types=1);

function hub_branding_limits(): array
{
    return [
        'max_bytes' => 2 * 1024 * 1024,
        'max_width' => 2048,
        'max_height' => 2048,
        'max_pixels' => 4_194_304,
    ];
}

function hub_branding_root(): string
{
    return HUB_DATA_DIR . '/uploads/branding';
}

function hub_branding_allowed_mimes(): array
{
    return ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];
}

function hub_branding_default_asset(): array
{
    return [
        'path' => HUB_WEB_ROOT . '/assets/images/logo.svg',
        'mime' => 'image/svg+xml',
        'managed' => false,
    ];
}

function hub_branding_real_root(bool $create = false): ?string
{
    $root = hub_branding_root();
    if (is_link($root)) {
        throw new RuntimeException('branding_storage_failed');
    }
    if (!is_dir($root)) {
        if (!$create) {
            return null;
        }
        if (!mkdir($root, 0775, true) && !is_dir($root)) {
            throw new RuntimeException('branding_storage_failed');
        }
    }

    $realRoot = realpath($root);
    $realDataRoot = realpath(HUB_DATA_DIR);
    if (
        $realRoot === false
        || $realDataRoot === false
        || is_link($root)
        || !hub_storage_paths_equal($root, $realRoot)
        || !hub_storage_path_is_within($realRoot, $realDataRoot)
    ) {
        throw new RuntimeException('branding_storage_failed');
    }

    return $realRoot;
}

function hub_branding_valid_basename(string $basename): bool
{
    return basename($basename) === $basename
        && preg_match('/^logo-[a-f0-9]{32}\.(?:png|webp|jpg)$/D', $basename) === 1;
}

function hub_branding_managed_path(string $basename): ?string
{
    if (!hub_branding_valid_basename($basename)) {
        return null;
    }

    try {
        $root = hub_branding_real_root();
    } catch (RuntimeException) {
        return null;
    }
    if ($root === null) {
        return null;
    }

    $path = $root . DIRECTORY_SEPARATOR . $basename;
    if (is_link($path) || !is_file($path)) {
        return null;
    }
    $realPath = realpath($path);
    if ($realPath === false || !hub_storage_paths_equal(dirname($realPath), $root)) {
        return null;
    }

    return $realPath;
}

function hub_branding_inspect_image(string $path): array
{
    $size = filesize($path);
    $limits = hub_branding_limits();
    if ($size === false || $size < 1) {
        throw new RuntimeException('branding_invalid_image');
    }
    if ($size > $limits['max_bytes']) {
        throw new RuntimeException('branding_payload_too_large');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $allowed = hub_branding_allowed_mimes();
    if (!is_string($mime) || !isset($allowed[$mime])) {
        throw new RuntimeException('branding_unsupported_media_type');
    }

    $dimensions = @getimagesize($path);
    if (
        !is_array($dimensions)
        || !isset($dimensions[0], $dimensions[1])
        || (int)$dimensions[0] <= 0
        || (int)$dimensions[1] <= 0
        || (string)($dimensions['mime'] ?? '') !== $mime
    ) {
        throw new RuntimeException('branding_invalid_image');
    }
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    if (
        $width > $limits['max_width']
        || $height > $limits['max_height']
        || $width * $height > $limits['max_pixels']
    ) {
        throw new RuntimeException('branding_dimensions_too_large');
    }

    return [
        'mime' => $mime,
        'extension' => $allowed[$mime],
        'size' => (int)$size,
        'width' => $width,
        'height' => $height,
    ];
}

function hub_branding_remove_managed(string $basename): void
{
    $path = hub_branding_managed_path($basename);
    if ($path !== null) {
        @unlink($path);
    }
}

function hub_branding_store_logo(PDO $db, array $upload): array
{
    if ((int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('branding_upload_failed');
    }
    $tmpName = is_string($upload['tmp_name'] ?? null) ? trim($upload['tmp_name']) : '';
    if ($tmpName === '' || is_link($tmpName) || !is_file($tmpName)) {
        throw new RuntimeException('branding_upload_invalid');
    }
    $testing = defined('HUB_TESTING') && HUB_TESTING === true;
    if (!$testing && !is_uploaded_file($tmpName)) {
        throw new RuntimeException('branding_upload_invalid');
    }

    $image = hub_branding_inspect_image($tmpName);
    $root = hub_branding_real_root(true);
    if ($root === null) {
        throw new RuntimeException('branding_storage_failed');
    }

    do {
        $basename = 'logo-' . bin2hex(random_bytes(16)) . '.' . $image['extension'];
        $path = $root . DIRECTORY_SEPARATOR . $basename;
    } while (file_exists($path) || is_link($path));

    $stored = $testing ? copy($tmpName, $path) : move_uploaded_file($tmpName, $path);
    if (!$stored) {
        throw new RuntimeException('branding_storage_failed');
    }
    @chmod($path, 0644);

    try {
        $managedPath = hub_branding_managed_path($basename);
        if ($managedPath === null) {
            throw new RuntimeException('branding_storage_failed');
        }
        $storedImage = hub_branding_inspect_image($managedPath);
        $previous = hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE');
        hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', $basename);
    } catch (Throwable $error) {
        hub_branding_remove_managed($basename);
        throw $error;
    }

    hub_branding_remove_managed($previous);

    return $storedImage + ['path' => $managedPath, 'managed' => true];
}

function hub_branding_restore_default(PDO $db): void
{
    $previous = hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE');
    hub_set_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE', '');
    hub_branding_remove_managed($previous);
}

function hub_branding_active_asset(PDO $db): array
{
    $basename = hub_get_storage_setting($db, 'AIHUB_BRANDING_LOGO_FILE');
    $path = hub_branding_managed_path($basename);
    if ($path === null) {
        return hub_branding_default_asset();
    }

    try {
        $image = hub_branding_inspect_image($path);
    } catch (RuntimeException) {
        return hub_branding_default_asset();
    }

    return [
        'path' => $path,
        'mime' => $image['mime'],
        'managed' => true,
    ];
}

function hub_branding_version(PDO $db): string
{
    $asset = hub_branding_active_asset($db);
    $hash = @hash_file('sha256', $asset['path']);

    return is_string($hash) ? substr($hash, 0, 16) : 'default';
}
