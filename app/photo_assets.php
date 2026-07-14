<?php
declare(strict_types=1);

function hub_photo_modes(): array
{
    return [
        'photo_upload' => 'Photo Upload',
        'photo' => 'Photo Vision',
    ];
}

function hub_photo_settings(PDO $db): array
{
    return [
        'ttl_days' => max(1, min(30, (int)hub_get_storage_setting($db, 'PHOTO_TTL_DAYS'))),
        'max_upload_mb' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_UPLOAD_MB')),
        'max_width' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_WIDTH')),
        'max_height' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_HEIGHT')),
        'max_pixels' => max(1, (int)hub_get_storage_setting($db, 'PHOTO_MAX_PIXELS')),
        'max_tokens' => max(32, min(2048, (int)hub_get_storage_setting($db, 'PHOTO_MAX_TOKENS'))),
        'real_inference' => hub_get_storage_setting($db, 'PHOTO_REAL_INFERENCE') === '1',
        'vision_service_key' => hub_get_storage_setting($db, 'PHOTO_VISION_SERVICE_KEY') ?: 'gemma4-main',
    ];
}

function hub_photo_generate_image_id(): string
{
    return 'img_' . rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

function hub_photo_upload_root(): string
{
    return HUB_DATA_DIR . '/uploads/photo';
}

function hub_photo_store_upload(PDO $db, array $file, array $authContext): array
{
    if (empty($authContext['member_id']) && empty($authContext['token_id'])) {
        throw new RuntimeException('photo_owner_unavailable');
    }
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('file_required');
    }

    $settings = hub_photo_settings($db);
    $tmpName = (string)$file['tmp_name'];
    $size = (int)($file['size'] ?? filesize($tmpName));
    if ($size < 1 || $size > $settings['max_upload_mb'] * 1024 * 1024) {
        throw new RuntimeException('payload_too_large');
    }

    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('unsupported_media_type');
    }

    $dimensions = @getimagesize($tmpName);
    if (!is_array($dimensions) || empty($dimensions[0]) || empty($dimensions[1])) {
        throw new RuntimeException('invalid_image');
    }
    $width = (int)$dimensions[0];
    $height = (int)$dimensions[1];
    if ($width > $settings['max_width'] || $height > $settings['max_height'] || ($width * $height) > $settings['max_pixels']) {
        throw new RuntimeException('image_dimensions_too_large');
    }

    $imageId = hub_photo_generate_image_id();
    $relDir = 'uploads/photo/' . $imageId;
    $dir = HUB_DATA_DIR . '/' . $relDir;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('storage_failed');
    }
    $path = $dir . '/original';
    $ok = is_uploaded_file($tmpName) ? move_uploaded_file($tmpName, $path) : copy($tmpName, $path);
    if (!$ok) {
        throw new RuntimeException('upload_failed');
    }

    $createdAt = hub_now();
    $expiresAt = date('Y-m-d H:i:s', strtotime($createdAt . ' +' . $settings['ttl_days'] . ' days'));
    $row = [
        'image_id' => $imageId,
        'owner_member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
        'owner_token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
        'mime' => $mime,
        'byte_size' => filesize($path) ?: $size,
        'width' => $width,
        'height' => $height,
        'sha256' => hash_file('sha256', $path),
        'storage_relpath' => $relDir . '/original',
        'expires_at' => $expiresAt,
        'created_at' => $createdAt,
    ];
    $stmt = $db->prepare(
        'INSERT INTO photo_assets
            (image_id, owner_member_id, owner_token_id, mime, byte_size, width, height, sha256, storage_relpath, expires_at, created_at)
         VALUES
            (:image_id, :owner_member_id, :owner_token_id, :mime, :byte_size, :width, :height, :sha256, :storage_relpath, :expires_at, :created_at)'
    );
    $stmt->execute($row);

    return $row + ['size' => $row['byte_size']];
}

function hub_photo_get_asset_for_auth(PDO $db, string $imageId, array $authContext): ?array
{
    if (!preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', $imageId)) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM photo_assets WHERE image_id = :image_id LIMIT 1');
    $stmt->execute([':image_id' => $imageId]);
    $asset = $stmt->fetch();
    if (!$asset || (string)$asset['expires_at'] < hub_now()) {
        return null;
    }

    $memberId = (int)($authContext['member_id'] ?? 0);
    $tokenId = (int)($authContext['token_id'] ?? 0);
    $ownerMemberId = (int)($asset['owner_member_id'] ?? 0);
    $ownerTokenId = (int)($asset['owner_token_id'] ?? 0);
    $allowed = ($memberId > 0 && $ownerMemberId > 0 && $memberId === $ownerMemberId)
        || ($ownerMemberId === 0 && $tokenId > 0 && $ownerTokenId > 0 && $tokenId === $ownerTokenId);
    if (!$allowed) {
        return null;
    }

    $db->prepare('UPDATE photo_assets SET last_accessed_at = :now WHERE id = :id')
        ->execute([':now' => hub_now(), ':id' => (int)$asset['id']]);

    return $asset;
}

function hub_photo_asset_host_path(array $asset): ?string
{
    $root = realpath(hub_photo_upload_root());
    $path = realpath(HUB_DATA_DIR . '/' . ltrim((string)($asset['storage_relpath'] ?? ''), '/'));
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function hub_photo_asset_container_path(array $asset): string
{
    return '/data/photo/' . (string)$asset['image_id'] . '/original';
}

function hub_photo_prune_expired(PDO $db, bool $dryRun, int $limit): array
{
    $limit = max(1, min(1000, $limit));
    $stmt = $db->prepare('SELECT * FROM photo_assets WHERE expires_at < :now ORDER BY expires_at ASC LIMIT ' . $limit);
    $stmt->execute([':now' => hub_now()]);
    $assets = $stmt->fetchAll();
    if ($dryRun) {
        return ['matched' => count($assets), 'deleted' => 0];
    }

    $deleted = 0;
    $delete = $db->prepare('DELETE FROM photo_assets WHERE id = :id');
    foreach ($assets as $asset) {
        $path = hub_photo_asset_host_path($asset);
        if ($path !== null) {
            unlink($path);
            @rmdir(dirname($path));
        }
        $delete->execute([':id' => (int)$asset['id']]);
        $deleted++;
    }

    return ['matched' => count($assets), 'deleted' => $deleted];
}
