<?php
declare(strict_types=1);

function hub_cluster_photo_modes(): array
{
    return ['photo', 'photo_upload'];
}

function hub_cluster_is_photo_mode(string $mode): bool
{
    return in_array($mode, hub_cluster_photo_modes(), true);
}

function hub_cluster_photo_modes_are_paired(array $modes): bool
{
    $available = array_fill_keys($modes, true);

    return isset($available['photo'], $available['photo_upload']);
}

function hub_cluster_photo_pair_modes(array $modes): array
{
    if (array_intersect(hub_cluster_photo_modes(), $modes) === []) {
        return $modes;
    }
    foreach (hub_cluster_photo_modes() as $mode) {
        if (!in_array($mode, $modes, true)) {
            $modes[] = $mode;
        }
    }

    return $modes;
}

function hub_cluster_photo_asset_store(PDO $db, int $stationId, array $authContext, string $remoteImageId, string $expiresAt): array
{
    if ($stationId < 1 || preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', $remoteImageId) !== 1) {
        throw new InvalidArgumentException('invalid cluster photo asset');
    }
    $expiresAt = trim($expiresAt);
    $parsedExpiry = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAt);
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsedExpiry === false
        || (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
        || $parsedExpiry->format('Y-m-d H:i:s') !== $expiresAt
        || $expiresAt <= hub_now()
    ) {
        throw new InvalidArgumentException('invalid cluster photo expiry');
    }

    hub_cluster_photo_prune_expired($db);
    $imageId = hub_photo_generate_image_id();
    $now = hub_now();
    $statement = $db->prepare(
        'INSERT INTO cluster_photo_assets
            (image_id, station_id, remote_image_id, owner_member_id, owner_token_id, expires_at, created_at)
         VALUES
            (:image_id, :station_id, :remote_image_id, :owner_member_id, :owner_token_id, :expires_at, :created_at)'
    );
    $statement->execute([
        ':image_id' => $imageId,
        ':station_id' => $stationId,
        ':remote_image_id' => $remoteImageId,
        ':owner_member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
        ':owner_token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
        ':expires_at' => $expiresAt,
        ':created_at' => $now,
    ]);

    return [
        'image_id' => $imageId,
        'station_id' => $stationId,
        'remote_image_id' => $remoteImageId,
        'expires_at' => $expiresAt,
    ];
}

function hub_cluster_photo_asset_for_auth(PDO $db, string $imageId, array $authContext): ?array
{
    if (preg_match('/^img_[A-Za-z0-9_-]{20,64}$/', $imageId) !== 1) {
        return null;
    }
    hub_cluster_photo_prune_expired($db);
    $statement = $db->prepare('SELECT * FROM cluster_photo_assets WHERE image_id = :image_id LIMIT 1');
    $statement->execute([':image_id' => $imageId]);
    $asset = $statement->fetch();
    if (!is_array($asset)) {
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

    $db->prepare('UPDATE cluster_photo_assets SET last_accessed_at = :now WHERE id = :id')
        ->execute([':now' => hub_now(), ':id' => (int)$asset['id']]);

    return $asset;
}

function hub_cluster_photo_prune_expired(PDO $db, int $limit = 1000): int
{
    $limit = max(1, min(1000, $limit));
    $statement = $db->prepare(
        'DELETE FROM cluster_photo_assets
         WHERE id IN (
             SELECT id FROM cluster_photo_assets
             WHERE expires_at < :now
             ORDER BY expires_at ASC
             LIMIT ' . $limit . '
         )'
    );
    $statement->execute([':now' => hub_now()]);

    return $statement->rowCount();
}
