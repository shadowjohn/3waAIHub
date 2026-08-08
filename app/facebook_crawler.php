<?php
declare(strict_types=1);

function hub_facebook_profile_root(): string
{
    $root = HUB_DATA_DIR . '/facebook-crawler/profiles';
    $parent = dirname($root);
    if (
        is_link($parent)
        || (file_exists($parent) && !is_dir($parent))
        || is_link($root)
        || (!is_dir($root) && !mkdir($root, 0700, true))
    ) {
        throw new RuntimeException('profile_storage_unavailable');
    }
    @chmod($root, 0700);
    clearstatcache(true, $root);
    $dataRoot = realpath(HUB_DATA_DIR);
    $parentReal = realpath($parent);
    $rootReal = realpath($root);
    $stat = @lstat($root);
    if (
        $dataRoot === false
        || $parentReal === false
        || $rootReal === false
        || !is_array($stat)
        || (((int)$stat['mode'] & 0170000) !== 0040000)
        || (((int)$stat['mode'] & 0777) !== 0700)
        || dirname($rootReal) !== $parentReal
        || !str_starts_with($parentReal, rtrim($dataRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    return $root;
}

function hub_facebook_profile_id(): string
{
    return 'fbp_' . bin2hex(random_bytes(24));
}

function hub_facebook_profile_state_path(array $profile): string
{
    $profileId = (string)($profile['profile_id'] ?? '');
    if (preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    return hub_facebook_profile_root() . '/' . $profileId . '/storage_state.json';
}

function hub_facebook_profile_directory(string $profileId, bool $create = false): string
{
    $path = hub_facebook_profile_state_path(['profile_id' => $profileId]);
    $dir = dirname($path);
    if ($create) {
        if (file_exists($dir) || is_link($dir) || !mkdir($dir, 0700)) {
            throw new RuntimeException('profile_storage_unavailable');
        }
        @chmod($dir, 0700);
    }

    clearstatcache(true, $dir);
    $rootReal = realpath(hub_facebook_profile_root());
    $dirReal = realpath($dir);
    $stat = @lstat($dir);
    if (
        is_link($dir)
        || $rootReal === false
        || $dirReal === false
        || !is_array($stat)
        || (((int)$stat['mode'] & 0170000) !== 0040000)
        || (((int)$stat['mode'] & 0777) !== 0700)
        || dirname($dirReal) !== $rootReal
    ) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    return $dir;
}

function hub_facebook_profile_file_stats_match(mixed $openedStat, mixed $pathStat, bool $requireSingleLink = true): bool
{
    return is_array($openedStat)
        && is_array($pathStat)
        && (((int)($openedStat['mode'] ?? 0) & 0170000) === 0100000)
        && (((int)($pathStat['mode'] ?? 0) & 0170000) === 0100000)
        && (!$requireSingleLink || ((int)($openedStat['nlink'] ?? 0) === 1 && (int)($pathStat['nlink'] ?? 0) === 1))
        && (int)($openedStat['dev'] ?? -1) === (int)($pathStat['dev'] ?? -2)
        && (int)($openedStat['ino'] ?? -1) === (int)($pathStat['ino'] ?? -2);
}

function hub_facebook_profile_initialize_storage(string $profileId): void
{
    $dir = hub_facebook_profile_directory($profileId, true);
    $path = $dir . '/storage_state.json';
    $handle = @fopen($path, 'x+b');
    $created = false;
    try {
        $payload = "{}\n";
        if (
            !is_resource($handle)
            || !@chmod($path, 0600)
            || fwrite($handle, $payload) !== strlen($payload)
            || !fflush($handle)
            || (function_exists('fsync') && !fsync($handle))
        ) {
            throw new RuntimeException('profile_storage_unavailable');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $real = realpath($path);
        if (
            $real === false
            || dirname($real) !== realpath($dir)
            || !hub_facebook_profile_file_stats_match(fstat($handle), $stat)
            || !is_array($stat)
            || (((int)$stat['mode'] & 0777) !== 0600)
        ) {
            throw new RuntimeException('profile_storage_unavailable');
        }
        $created = true;
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (!$created) {
            @unlink($path);
            @rmdir($dir);
        }
    }
}

function hub_facebook_profile_public(array $profile): array
{
    return [
        'profile_id' => (string)$profile['profile_id'],
        'node_name' => (string)$profile['node_name'],
        'display_name' => (string)$profile['display_name'],
        'state' => (string)$profile['state'],
        'last_verified_at' => $profile['last_verified_at'] === null ? null : (string)$profile['last_verified_at'],
        'created_at' => (string)$profile['created_at'],
        'updated_at' => (string)$profile['updated_at'],
    ];
}

function hub_facebook_profile_for_member(PDO $db, string $profileId, int $ownerMemberId): ?array
{
    if ($ownerMemberId < 1 || preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT * FROM facebook_crawler_profiles
         WHERE profile_id = :profile_id
           AND owner_member_id = :owner_member_id
           AND deleted_at IS NULL
           AND state <> :deleting'
    );
    $stmt->execute([':profile_id' => $profileId, ':owner_member_id' => $ownerMemberId, ':deleting' => 'deleting']);
    $profile = $stmt->fetch();

    return $profile === false ? null : hub_facebook_profile_public($profile);
}

function hub_facebook_profiles_for_member(PDO $db, int $ownerMemberId): array
{
    if ($ownerMemberId < 1) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT * FROM facebook_crawler_profiles
         WHERE owner_member_id = :owner_member_id AND deleted_at IS NULL AND state <> :deleting
         ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId, ':deleting' => 'deleting']);

    return array_map('hub_facebook_profile_public', $stmt->fetchAll());
}

function hub_facebook_profile_create(PDO $db, int $ownerMemberId, string $displayName): array
{
    $displayName = trim($displayName);
    $displayNameLength = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
    if ($ownerMemberId < 1 || $displayName === '' || $displayNameLength > 120) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }

    $profileId = '';
    $storageCreated = false;
    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $member = $db->prepare('SELECT 1 FROM api_members WHERE id = :id');
        $member->execute([':id' => $ownerMemberId]);
        if ($member->fetchColumn() === false) {
            throw new InvalidArgumentException('facebook_profile_invalid');
        }
        $count = $db->prepare(
            'SELECT COUNT(*) FROM facebook_crawler_profiles
             WHERE owner_member_id = :owner_member_id AND deleted_at IS NULL AND state <> :deleting'
        );
        $count->execute([':owner_member_id' => $ownerMemberId, ':deleting' => 'deleting']);
        if ((int)$count->fetchColumn() >= 20) {
            throw new RuntimeException('facebook_profile_limit_reached');
        }

        $profileId = hub_facebook_profile_id();
        hub_facebook_profile_initialize_storage($profileId);
        $storageCreated = true;
        $now = hub_now();
        $nodeName = trim((string)(gethostname() ?: 'localhost')) ?: 'localhost';
        $stmt = $db->prepare(
            'INSERT INTO facebook_crawler_profiles
                (profile_id, owner_member_id, node_name, display_name, state, created_at, updated_at)
             VALUES
                (:profile_id, :owner_member_id, :node_name, :display_name, :state, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
            ':node_name' => $nodeName,
            ':display_name' => $displayName,
            ':state' => 'preparing',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $db->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        if ($storageCreated) {
            $path = hub_facebook_profile_state_path(['profile_id' => $profileId]);
            @unlink($path);
            @rmdir(dirname($path));
        }
        throw $e;
    }

    return hub_facebook_profile_for_member($db, $profileId, $ownerMemberId)
        ?? throw new RuntimeException('facebook_profile_create_failed');
}

function hub_facebook_profile_delete_storage(array $profile): void
{
    $profileId = (string)($profile['profile_id'] ?? '');
    $path = hub_facebook_profile_state_path($profile);
    $dir = dirname($path);
    clearstatcache(true, $dir);
    if (!file_exists($dir) && !is_link($dir) && (string)($profile['state'] ?? '') === 'deleting') {
        return;
    }

    $dir = hub_facebook_profile_directory($profileId);
    $entries = iterator_to_array(new FilesystemIterator(
        $dir,
        FilesystemIterator::SKIP_DOTS | FilesystemIterator::KEY_AS_FILENAME
    ));
    if (count($entries) !== 1 || !isset($entries['storage_state.json'])) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    clearstatcache(true, $path);
    $pathStat = @lstat($path);
    $pathReal = realpath($path);
    $dirReal = realpath($dir);
    if (
        is_link($path)
        || $pathReal === false
        || $dirReal === false
        || dirname($pathReal) !== $dirReal
        || !is_array($pathStat)
        || (((int)$pathStat['mode'] & 0777) !== 0600)
        || (int)($pathStat['nlink'] ?? 0) !== 1
        || (((int)$pathStat['mode'] & 0170000) !== 0100000)
    ) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    $handle = @fopen($path, 'r+b');
    try {
        clearstatcache(true, $path);
        if (
            !is_resource($handle)
            || !hub_facebook_profile_file_stats_match(fstat($handle), @lstat($path))
            || !ftruncate($handle, 0)
            || !fflush($handle)
            || (function_exists('fsync') && !fsync($handle))
        ) {
            throw new RuntimeException('profile_storage_unavailable');
        }
        clearstatcache(true, $path);
        if (!hub_facebook_profile_file_stats_match(fstat($handle), @lstat($path), false) || !@unlink($path)) {
            throw new RuntimeException('profile_storage_unavailable');
        }
        $after = fstat($handle);
        if (!is_array($after) || (int)($after['size'] ?? -1) !== 0) {
            throw new RuntimeException('profile_storage_unavailable');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
    if (!@rmdir($dir)) {
        throw new RuntimeException('profile_storage_unavailable');
    }
}

function hub_facebook_profile_delete(PDO $db, string $profileId, int $ownerMemberId): bool
{
    if ($ownerMemberId < 1 || preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        throw new InvalidArgumentException('facebook_profile_forbidden');
    }

    $profile = null;
    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $stmt = $db->prepare(
            'SELECT * FROM facebook_crawler_profiles
             WHERE profile_id = :profile_id AND owner_member_id = :owner_member_id AND deleted_at IS NULL'
        );
        $stmt->execute([':profile_id' => $profileId, ':owner_member_id' => $ownerMemberId]);
        $profile = $stmt->fetch();
        if ($profile === false) {
            throw new InvalidArgumentException('facebook_profile_forbidden');
        }
        if ((string)$profile['state'] !== 'deleting') {
            foreach (['login_secret_hash', 'login_container_name', 'login_port', 'login_expires_at'] as $field) {
                if (($profile[$field] ?? null) !== null) {
                    throw new RuntimeException('facebook_profile_login_active');
                }
            }
            if (($profile['active_task_id'] ?? null) !== null) {
                throw new RuntimeException('facebook_profile_busy');
            }

            $now = hub_now();
            $mark = $db->prepare(
                "UPDATE facebook_crawler_profiles
                 SET state = 'deleting', updated_at = :updated_at
                 WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL AND state <> 'deleting'"
            );
            $mark->execute([
                ':updated_at' => $now,
                ':id' => (int)$profile['id'],
                ':owner_member_id' => $ownerMemberId,
            ]);
            if ($mark->rowCount() !== 1) {
                throw new RuntimeException('facebook_profile_delete_conflict');
            }
            $profile['state'] = 'deleting';
            $profile['updated_at'] = $now;
        }
        $db->exec('COMMIT');
        $transactionStarted = false;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }

    hub_facebook_profile_delete_storage($profile);

    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $now = hub_now();
        $update = $db->prepare(
            "UPDATE facebook_crawler_profiles
             SET state = 'deleted', deleted_at = :deleted_at, updated_at = :updated_at
             WHERE id = :id
               AND owner_member_id = :owner_member_id
               AND deleted_at IS NULL
               AND state = 'deleting'"
        );
        $update->execute([
            ':deleted_at' => $now,
            ':updated_at' => $now,
            ':id' => (int)$profile['id'],
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('facebook_profile_delete_conflict');
        }
        $db->exec('COMMIT');
        $transactionStarted = false;

        return true;
    } catch (Throwable $e) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
}
