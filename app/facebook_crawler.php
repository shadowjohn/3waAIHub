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

function hub_facebook_crawl_target_url(string $url): string
{
    if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    $parts = parse_url($url);
    if (
        !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !is_string($parts['host'] ?? null)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || isset($parts['query'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    $host = strtolower(rtrim($parts['host'], '.'));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false
        || ($host !== 'facebook.com' && !str_ends_with($host, '.facebook.com'))) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    $path = (string)($parts['path'] ?? '');
    if ($path === '' || str_contains($path, '%') || str_contains($path, '//')) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    $segments = explode('/', trim($path, '/'));
    if ($segments === [''] || count($segments) > 2) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    foreach ($segments as $segment) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,99}\z/', $segment) !== 1) {
            throw new InvalidArgumentException('facebook_targets_invalid');
        }
    }
    $reserved = [
        'ajax', 'events', 'gaming', 'hashtag', 'help', 'home.php', 'login', 'marketplace',
        'messages', 'notifications', 'photo', 'photos', 'plugins', 'reel', 'reels', 'search',
        'share', 'stories', 'watch',
    ];
    $kind = strtolower($segments[0]);
    if (($kind === 'groups' && count($segments) !== 2)
        || ($kind !== 'groups' && (count($segments) !== 1 || in_array($kind, $reserved, true)))) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }

    return 'https://www.facebook.com/' . implode('/', $segments);
}

function hub_facebook_crawl_targets(mixed $targets): array
{
    if (!is_array($targets) || !array_is_list($targets) || count($targets) < 1 || count($targets) > 30) {
        throw new InvalidArgumentException('facebook_targets_invalid');
    }
    $normalized = [];
    $seen = [];
    foreach ($targets as $target) {
        if (!is_array($target) || array_is_list($target) || array_keys($target) !== ['url'] || !is_string($target['url'])) {
            throw new InvalidArgumentException('facebook_targets_invalid');
        }
        $url = hub_facebook_crawl_target_url($target['url']);
        $key = strtolower($url);
        if (isset($seen[$key])) {
            throw new InvalidArgumentException('facebook_targets_duplicate');
        }
        $seen[$key] = true;
        $normalized[] = ['url' => $url];
    }

    return $normalized;
}

function hub_facebook_crawl_profile(PDO $db, string $profileId, int $ownerMemberId): array
{
    $profile = hub_facebook_login_owned_row($db, $profileId, $ownerMemberId);
    if ($profile === null) {
        throw new RuntimeException('facebook_profile_not_found');
    }
    if (
        (string)$profile['state'] !== 'ready'
        || $profile['login_secret_hash'] !== null
        || $profile['login_container_name'] !== null
        || $profile['login_port'] !== null
        || $profile['login_expires_at'] !== null
        || !hub_facebook_login_state_secure($profile)
    ) {
        throw new RuntimeException('facebook_profile_unavailable');
    }

    return $profile;
}

function hub_facebook_crawl_submit(
    PDO $db,
    array $route,
    array $authContext,
    string $method,
    ?string $rawBody,
    string $contentType,
    string $clientIp
): array {
    if ($method !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'Facebook crawl submission requires POST');
    }
    $contentType = strtolower(trim(explode(';', $contentType)[0]));
    if ($contentType !== 'application/json') {
        return hub_gateway_error(415, 'content_type_invalid', 'Facebook crawl submission requires application/json');
    }
    $maxBytes = (int)($route['max_upload_bytes'] ?? 0);
    if ($maxBytes < 1) {
        return hub_gateway_error(413, 'payload_too_large', 'request body is larger than this service allows');
    }
    if ($rawBody === null) {
        $stream = fopen('php://input', 'rb');
        $rawBody = is_resource($stream) ? stream_get_contents($stream, $maxBytes + 1) : false;
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (!is_string($rawBody)) {
            $rawBody = '';
        }
    }
    if ($rawBody === '' || strlen($rawBody) > $maxBytes) {
        return hub_gateway_error(strlen($rawBody) > $maxBytes ? 413 : 400, strlen($rawBody) > $maxBytes ? 'payload_too_large' : 'invalid_request', 'Facebook crawl request is invalid');
    }
    try {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('facebook_request_invalid');
        }
        $allowed = ['profile_id', 'targets', 'limit_per_target'];
        if (array_diff(array_keys($payload), $allowed) !== [] || !array_key_exists('targets', $payload)) {
            throw new InvalidArgumentException('facebook_request_invalid');
        }
        $limit = $payload['limit_per_target'] ?? 10;
        if (!is_int($limit) || $limit < 10 || $limit > 30) {
            throw new InvalidArgumentException('facebook_limit_invalid');
        }
        $targets = hub_facebook_crawl_targets($payload['targets']);
        $ownerMemberId = (int)($authContext['member_id'] ?? 0);
        $tokenId = (int)($authContext['token_id'] ?? 0);
        if ($ownerMemberId < 1 || $tokenId < 1) {
            return hub_gateway_error(403, 'member_required', 'Facebook crawl submission requires an API member');
        }
        $input = [];
        if (array_key_exists('profile_id', $payload)) {
            if (!is_string($payload['profile_id'])) {
                throw new InvalidArgumentException('facebook_profile_invalid');
            }
            $profile = hub_facebook_crawl_profile($db, $payload['profile_id'], $ownerMemberId);
            $input['profile_id'] = (string)$profile['profile_id'];
        }
        $input['targets_json'] = json_encode($targets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $input['limit_per_target'] = $limit;
        $taskId = hub_enqueue_owned_pack_job($db, $route, $input, $ownerMemberId, $tokenId, $clientIp);

        return hub_gateway_json(200, hub_task_submit_response($taskId));
    } catch (JsonException | InvalidArgumentException) {
        return hub_gateway_error(400, 'invalid_request', 'Facebook crawl request does not match the public contract');
    } catch (RuntimeException $e) {
        return match ($e->getMessage()) {
            'facebook_profile_not_found' => hub_gateway_error(404, 'facebook_profile_not_found', 'Facebook profile was not found'),
            'facebook_profile_unavailable' => hub_gateway_error(409, 'facebook_profile_unavailable', 'Facebook profile is unavailable'),
            default => hub_gateway_error(409, 'facebook_crawl_unavailable', 'Facebook crawl is unavailable'),
        };
    }
}

function hub_facebook_task_profile_id(array $task): ?string
{
    if ((string)($task['task_type'] ?? '') !== 'pack_job'
        || (string)($task['pack_id'] ?? '') !== 'facebook-crawler'
        || (string)($task['job'] ?? '') !== 'crawl'
        || (string)($task['requested_mode'] ?? '') !== 'facebook_crawl') {
        return null;
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    if (!array_key_exists('profile_id', $input)) {
        return null;
    }
    $profileId = $input['profile_id'];
    if (!is_string($profileId) || preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        throw new RuntimeException('facebook_profile_unavailable');
    }

    return $profileId;
}

function hub_facebook_profile_acquire_for_task(PDO $db, array $task): array|false|null
{
    $profileId = hub_facebook_task_profile_id($task);
    if ($profileId === null) {
        return null;
    }
    $taskId = (int)($task['id'] ?? 0);
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    $lockToken = (string)($task['lock_token'] ?? '');
    if ($taskId < 1 || $ownerMemberId < 1 || preg_match('/\A[a-f0-9]{32}\z/', $lockToken) !== 1) {
        throw new RuntimeException('facebook_profile_unavailable');
    }
    if ($db->inTransaction()) {
        throw new LogicException('facebook_profile_lock_transaction_required');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $taskGuard = $db->prepare(
            "SELECT 1 FROM tasks
             WHERE id = :id AND owner_member_id = :owner_member_id AND task_type = 'pack_job'
               AND requested_mode = 'facebook_crawl' AND pack_id = 'facebook-crawler' AND job = 'crawl'
               AND status = 'running' AND lock_token = :lock_token"
        );
        $taskGuard->execute([
            ':id' => $taskId,
            ':owner_member_id' => $ownerMemberId,
            ':lock_token' => $lockToken,
        ]);
        if ($taskGuard->fetchColumn() === false) {
            $db->exec('COMMIT');
            throw new RuntimeException('facebook_profile_unavailable');
        }
        $stmt = $db->prepare(
            "SELECT * FROM facebook_crawler_profiles
             WHERE profile_id = :profile_id AND owner_member_id = :owner_member_id
               AND state = 'ready' AND deleted_at IS NULL
               AND login_secret_hash IS NULL AND login_container_name IS NULL
               AND login_port IS NULL AND login_expires_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([':profile_id' => $profileId, ':owner_member_id' => $ownerMemberId]);
        $profile = $stmt->fetch();
        if (!is_array($profile)) {
            $db->exec('COMMIT');
            throw new RuntimeException('facebook_profile_unavailable');
        }
        $activeTaskId = (int)($profile['active_task_id'] ?? 0);
        if ($activeTaskId === $taskId) {
            $db->exec('COMMIT');
            return $profile;
        }
        if ($activeTaskId > 0) {
            $holder = $db->prepare('SELECT status FROM tasks WHERE id = :id');
            $holder->execute([':id' => $activeTaskId]);
            $holderStatus = $holder->fetchColumn();
            if (is_string($holderStatus) && !in_array($holderStatus, ['success', 'failed', 'cancelled', 'timed_out', 'timeout'], true)) {
                $db->exec('COMMIT');
                return false;
            }
            $clear = $db->prepare(
                'UPDATE facebook_crawler_profiles SET active_task_id = NULL, updated_at = :now
                 WHERE id = :id AND active_task_id = :active_task_id'
            );
            $clear->execute([':now' => hub_now(), ':id' => (int)$profile['id'], ':active_task_id' => $activeTaskId]);
            if ($clear->rowCount() !== 1) {
                $db->exec('ROLLBACK');
                return false;
            }
        }
        $acquire = $db->prepare(
            "UPDATE facebook_crawler_profiles
             SET active_task_id = :task_id, updated_at = :now
             WHERE id = :id AND profile_id = :profile_id AND owner_member_id = :owner_member_id
               AND state = 'ready' AND deleted_at IS NULL AND active_task_id IS NULL
               AND login_secret_hash IS NULL AND login_container_name IS NULL
               AND login_port IS NULL AND login_expires_at IS NULL"
        );
        $acquire->execute([
            ':task_id' => $taskId,
            ':now' => hub_now(),
            ':id' => (int)$profile['id'],
            ':profile_id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($acquire->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return false;
        }
        $profile['active_task_id'] = $taskId;
        $db->exec('COMMIT');

        return $profile;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_facebook_profile_release_for_task(PDO $db, string $profileId, int $taskId): bool
{
    if ($taskId < 1 || preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        return false;
    }
    $stmt = $db->prepare(
        'UPDATE facebook_crawler_profiles
         SET active_task_id = NULL, updated_at = :now
         WHERE profile_id = :profile_id AND active_task_id = :task_id AND deleted_at IS NULL'
    );
    $stmt->execute([':now' => hub_now(), ':profile_id' => $profileId, ':task_id' => $taskId]);

    return $stmt->rowCount() === 1;
}

function hub_facebook_latest_terminal_run(PDO $db, int $ownerMemberId): ?array
{
    if ($ownerMemberId < 1) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT id FROM tasks
         WHERE owner_member_id = :owner_member_id
           AND requested_mode = 'facebook_crawl'
           AND pack_id = 'facebook-crawler' AND job = 'crawl'
           AND status IN ('success', 'failed', 'cancelled', 'timed_out', 'timeout')
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId]);
    $taskId = (int)$stmt->fetchColumn();

    return $taskId > 0 ? hub_get_task($db, $taskId) : null;
}

function hub_facebook_dataset_artifact_expired(array $artifact, ?int $now = null): bool
{
    if ((string)($artifact['state'] ?? '') !== 'available' || !empty($artifact['purged_at'])) {
        return true;
    }
    if (!empty($artifact['pinned_at']) || (int)($artifact['legal_hold'] ?? 0) === 1) {
        return false;
    }
    $expiresAt = trim((string)($artifact['expires_at'] ?? ''));
    if ($expiresAt === '') {
        return false;
    }
    $expires = strtotime($expiresAt);

    return $expires === false || $expires <= ($now ?? time());
}

function hub_facebook_dataset_artifact_for_task(PDO $db, int $ownerMemberId, int $taskId): ?array
{
    if ($ownerMemberId < 1 || $taskId < 1) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT a.*
         FROM tasks t
         JOIN task_artifacts a ON a.task_id = t.id
         WHERE t.id = :task_id AND t.owner_member_id = :owner_member_id
           AND t.requested_mode = 'facebook_crawl'
           AND t.pack_id = 'facebook-crawler' AND t.job = 'crawl'
           AND t.status = 'success' AND a.artifact_type = 'facebook_posts_jsonl'
         ORDER BY a.id DESC LIMIT 1"
    );
    $stmt->execute([':task_id' => $taskId, ':owner_member_id' => $ownerMemberId]);
    $artifact = $stmt->fetch();

    return is_array($artifact) ? $artifact : null;
}

function hub_facebook_latest_dataset_artifact(PDO $db, int $ownerMemberId): ?array
{
    if ($ownerMemberId < 1) {
        return null;
    }
    $now = hub_now();
    $stmt = $db->prepare(
        "SELECT a.*
         FROM tasks t
         JOIN task_artifacts a ON a.task_id = t.id
         WHERE t.owner_member_id = :owner_member_id
           AND t.requested_mode = 'facebook_crawl'
           AND t.pack_id = 'facebook-crawler' AND t.job = 'crawl'
           AND t.status = 'success' AND a.artifact_type = 'facebook_posts_jsonl'
           AND a.state = 'available' AND a.purged_at IS NULL
           AND (a.expires_at IS NULL OR datetime(a.expires_at) > datetime(:now) OR a.pinned_at IS NOT NULL OR a.legal_hold = 1)
         ORDER BY t.id DESC, a.id DESC LIMIT 1"
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId, ':now' => $now]);
    $artifact = $stmt->fetch();

    return is_array($artifact) ? $artifact : null;
}

function hub_facebook_latest_dataset_artifact_record(PDO $db, int $ownerMemberId): ?array
{
    if ($ownerMemberId < 1) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT a.*
         FROM tasks t
         JOIN task_artifacts a ON a.task_id = t.id
         WHERE t.owner_member_id = :owner_member_id
           AND t.requested_mode = 'facebook_crawl'
           AND t.pack_id = 'facebook-crawler' AND t.job = 'crawl'
           AND t.status = 'success' AND a.artifact_type = 'facebook_posts_jsonl'
         ORDER BY t.id DESC, a.id DESC LIMIT 1"
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId]);
    $artifact = $stmt->fetch();

    return is_array($artifact) ? $artifact : null;
}

function hub_facebook_dataset_page(string $path, int $offset, int $limit): array
{
    if ($offset < 0 || $limit < 1 || $limit > 500) {
        throw new InvalidArgumentException('dataset_query_invalid');
    }
    try {
        $file = new SplFileObject($path, 'rb');
    } catch (Throwable $e) {
        throw new RuntimeException('dataset_invalid', 0, $e);
    }
    $items = [];
    $index = 0;
    try {
        while (!$file->eof() && count($items) < $limit) {
            $line = $file->fgets();
            if ($line === '' || trim($line) === '') {
                continue;
            }
            if (strlen($line) > 1024 * 1024) {
                throw new RuntimeException('dataset_invalid');
            }
            $item = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($item) || array_is_list($item)) {
                throw new RuntimeException('dataset_invalid');
            }
            if ($index++ < $offset) {
                continue;
            }
            $items[] = $item;
        }
    } catch (JsonException $e) {
        throw new RuntimeException('dataset_invalid', 0, $e);
    }

    return [
        'items' => $items,
        'next_offset' => count($items) === $limit ? $offset + count($items) : null,
    ];
}

function hub_facebook_dataset_query_integer(mixed $value, int $minimum, int $maximum): ?int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $value) === 1) {
        $number = (int)$value;
    } else {
        return null;
    }

    return $number >= $minimum && $number <= $maximum ? $number : null;
}

function hub_facebook_dataset_query(string $mode, array $query): array
{
    if (array_key_exists('mode', $query)) {
        if (!is_string($query['mode']) || $query['mode'] !== $mode) {
            throw new InvalidArgumentException('dataset_query_invalid');
        }
        unset($query['mode']);
    }
    if ($mode === 'facebook_run_last') {
        if ($query !== []) {
            throw new InvalidArgumentException('dataset_query_invalid');
        }
        return [];
    }
    if (array_diff(array_keys($query), ['task_id', 'offset', 'limit']) !== []) {
        throw new InvalidArgumentException('dataset_query_invalid');
    }
    $taskId = null;
    if (array_key_exists('task_id', $query)) {
        $taskId = hub_facebook_dataset_query_integer($query['task_id'], 1, PHP_INT_MAX);
        if ($taskId === null) {
            throw new InvalidArgumentException('dataset_query_invalid');
        }
    }
    $offset = hub_facebook_dataset_query_integer($query['offset'] ?? 0, 0, 1000000000);
    $limit = hub_facebook_dataset_query_integer($query['limit'] ?? 100, 1, 500);
    if ($offset === null || $limit === null) {
        throw new InvalidArgumentException('dataset_query_invalid');
    }

    return ['task_id' => $taskId, 'offset' => $offset, 'limit' => $limit];
}

function hub_facebook_run_last_response(PDO $db, int $ownerMemberId): array
{
    $task = hub_facebook_latest_terminal_run($db, $ownerMemberId);
    if ($task === null) {
        return hub_gateway_error(404, 'facebook_run_not_found', 'Facebook crawl run was not found');
    }
    $taskId = (int)$task['id'];
    $artifact = hub_facebook_dataset_artifact_for_task($db, $ownerMemberId, $taskId);
    $datasetAvailable = is_array($artifact)
        && !hub_facebook_dataset_artifact_expired($artifact)
        && hub_artifact_safe_path((string)$artifact['path']) !== null;
    $base = hub_gateway_api_base_url();
    $response = [
        'ok' => true,
        'task_id' => $taskId,
        'status' => (string)$task['status'],
        'error_code' => $task['error_code'] ?? null,
        'created_at' => $task['created_at'] ?? null,
        'started_at' => $task['started_at'] ?? null,
        'finished_at' => $task['finished_at'] ?? null,
        'dataset_available' => $datasetAvailable,
        'status_url' => $base . '?mode=task_status&task_id=' . $taskId,
        'result_url' => $base . '?mode=task_result&task_id=' . $taskId,
        'log_url' => $base . '?mode=task_log&task_id=' . $taskId,
    ];
    if ($datasetAvailable) {
        $response['dataset_items_url'] = $base . '?mode=facebook_dataset_items&task_id=' . $taskId;
        $response['artifact_url'] = $base . '?mode=artifact&artifact_id=' . (int)$artifact['id'];
    }

    return hub_gateway_json(200, $response);
}

function hub_facebook_dataset_items_response(PDO $db, int $ownerMemberId, array $query): array
{
    if ($query['task_id'] === null) {
        $artifact = hub_facebook_latest_dataset_artifact($db, $ownerMemberId)
            ?? hub_facebook_latest_dataset_artifact_record($db, $ownerMemberId);
    } else {
        $artifact = hub_facebook_dataset_artifact_for_task($db, $ownerMemberId, (int)$query['task_id']);
    }
    if ($artifact === null) {
        return hub_gateway_error(404, 'dataset_not_found', 'Facebook dataset was not found');
    }
    if (hub_facebook_dataset_artifact_expired($artifact)) {
        return hub_gateway_error(410, 'dataset_expired', 'Facebook dataset has expired');
    }
    $artifactId = (int)$artifact['id'];
    $path = hub_artifact_safe_path((string)$artifact['path']);
    if ($path === null) {
        return hub_gateway_error(409, 'dataset_invalid', 'Facebook dataset is invalid');
    }
    $claim = hub_claim_task_artifact_download($db, $artifactId);
    if ($claim === null) {
        return hub_gateway_error(409, 'dataset_unavailable', 'Facebook dataset is currently unavailable');
    }
    try {
        $page = hub_facebook_dataset_page($path, (int)$query['offset'], (int)$query['limit']);
    } catch (Throwable) {
        return hub_gateway_error(409, 'dataset_invalid', 'Facebook dataset is invalid');
    } finally {
        hub_release_task_artifact_download($db, $artifactId, $claim);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$artifact['task_id'],
        'offset' => (int)$query['offset'],
        'limit' => (int)$query['limit'],
        'count' => count($page['items']),
        'next_offset' => $page['next_offset'],
        'items' => $page['items'],
    ]);
}

function hub_facebook_dataset_api_dispatch(PDO $db, string $mode, array $authContext, string $method, array $query): array
{
    if ($method !== 'GET') {
        return hub_gateway_error(405, 'method_not_allowed', 'Facebook dataset operations require GET');
    }
    $ownerMemberId = (int)($authContext['member_id'] ?? 0);
    if ($ownerMemberId < 1) {
        return hub_gateway_error(403, 'member_required', 'Facebook dataset operations require an API member');
    }
    try {
        $parsed = hub_facebook_dataset_query($mode, $query);
    } catch (InvalidArgumentException) {
        return hub_gateway_error(400, 'invalid_request', 'Facebook dataset query is invalid');
    }

    return match ($mode) {
        'facebook_run_last' => hub_facebook_run_last_response($db, $ownerMemberId),
        'facebook_dataset_items' => hub_facebook_dataset_items_response($db, $ownerMemberId, $parsed),
        default => hub_gateway_error(404, 'unknown_mode', 'mode is not registered'),
    };
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
