<?php
declare(strict_types=1);

function hub_cluster_secret_key(): string
{
    $value = trim((string)(getenv('AIHUB_CLUSTER_SECRET_KEY') ?: ''));
    if (preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
        throw new InvalidArgumentException('Cluster secret is invalid.');
    }

    $key = hex2bin($value);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Cluster secret is invalid.');
    }

    return $key;
}

function hub_cluster_encrypt_station_token(string $plainToken): array
{
    if (trim($plainToken) === '') {
        throw new InvalidArgumentException('Station token is required.');
    }

    try {
        $iv = random_bytes(12);
    } catch (Throwable) {
        throw new RuntimeException('Station token encryption failed.');
    }
    $tag = '';
    $ciphertext = openssl_encrypt($plainToken, 'aes-256-gcm', hub_cluster_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Station token encryption failed.');
    }

    return [
        'token_ciphertext' => base64_encode($ciphertext),
        'token_iv' => base64_encode($iv),
        'token_tag' => base64_encode($tag),
    ];
}

function hub_cluster_decrypt_station_token(array $station): string
{
    $ciphertext = base64_decode((string)($station['token_ciphertext'] ?? ''), true);
    $iv = base64_decode((string)($station['token_iv'] ?? ''), true);
    $tag = base64_decode((string)($station['token_tag'] ?? ''), true);
    if ($ciphertext === false || $ciphertext === '' || $iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16) {
        throw new RuntimeException('Station token decryption failed.');
    }

    $plainToken = openssl_decrypt($ciphertext, 'aes-256-gcm', hub_cluster_secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plainToken === false || $plainToken === '') {
        throw new RuntimeException('Station token decryption failed.');
    }

    return $plainToken;
}

function hub_cluster_validate_station_base_url(string $value): string
{
    $value = trim($value);
    $parts = parse_url($value);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (
        $value === ''
        || $parts === false
        || !filter_var($value, FILTER_VALIDATE_URL)
        || !in_array($scheme, ['http', 'https'], true)
        || trim((string)($parts['host'] ?? '')) === ''
        || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || str_contains($value, '?')
        || ($scheme === 'http' && (!hub_cluster_allow_http_internal() || !hub_cluster_private_http_host_allowed((string)$parts['host'])))
    ) {
        throw new InvalidArgumentException('Station base URL is invalid.');
    }

    $path = (string)($parts['path'] ?? '/');
    $path = rtrim($path, '/') . '/';
    if ($path === '') {
        $path = '/';
    }

    return $scheme . '://' . (string)$parts['host']
        . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . $path;
}

function hub_cluster_allow_http_internal(): bool
{
    return getenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL') === '1';
}

function hub_cluster_private_http_host_allowed(string $host): bool
{
    $host = trim($host, '[]');
    if ($host === 'localhost') {
        return true;
    }
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = array_map('intval', explode('.', $host));
        return $parts[0] === 10
            || ($parts[0] === 172 && $parts[1] >= 16 && $parts[1] <= 31)
            || ($parts[0] === 192 && $parts[1] === 168)
            || $parts[0] === 127;
    }

    return $host === '::1' || str_starts_with(strtolower($host), 'fc') || str_starts_with(strtolower($host), 'fd');
}

function hub_cluster_station_request_base_url(array $station): string
{
    $internal = trim((string)($station['internal_base_url'] ?? ''));
    return hub_cluster_validate_station_base_url($internal !== '' ? $internal : (string)($station['public_base_url'] ?? ''));
}

function hub_cluster_create_pair_invitation(PDO $db): array
{
    if (hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED') !== '1') {
        throw new RuntimeException('pairing failed');
    }
    if (hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') !== '') {
        throw new RuntimeException('pairing failed');
    }

    try {
        $invite = bin2hex(random_bytes(32));
    } catch (Throwable) {
        throw new RuntimeException('pairing failed');
    }
    $expiresAt = date('Y-m-d H:i:s', time() + 900);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH', hash('sha256', $invite));
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', $expiresAt);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT', '');

    return ['invite' => $invite, 'expires_at' => $expiresAt];
}

function hub_cluster_import_pairing_link(PDO $db, string $pairingLink, ?callable $requester = null): array
{
    $pairingLink = trim($pairingLink);
    $parts = parse_url($pairingLink);
    $fragment = $parts === false ? '' : (string)($parts['fragment'] ?? '');
    if (
        $parts === false
        || !filter_var($pairingLink, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string)($parts['host'] ?? '')) === ''
        || (isset($parts['port']) && ((int)$parts['port'] < 1 || (int)$parts['port'] > 65535))
        || isset($parts['user'])
        || isset($parts['pass'])
        || str_contains($pairingLink, '?')
        || !str_ends_with((string)($parts['path'] ?? ''), '/cluster_pair.php')
        || preg_match('/\Ainvite=([0-9a-fA-F]{64})\z/', $fragment, $matches) !== 1
    ) {
        throw new InvalidArgumentException('pairing failed');
    }

    $routerName = trim(hub_site_title($db));
    $routerName = function_exists('mb_substr') ? mb_substr($routerName, 0, 120, 'UTF-8') : substr($routerName, 0, 120);
    $requestUrl = strtolower((string)$parts['scheme']) . '://' . (string)$parts['host']
        . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . (string)$parts['path'];
    try {
        hub_cluster_validate_station_base_url($requestUrl);
    } catch (Throwable) {
        throw new InvalidArgumentException('pairing failed');
    }
    $request = [
        'url' => $requestUrl,
        'method' => 'POST',
        'headers' => [
            'X-3waAIHub-Pair-Invite' => $matches[1],
            'X-3waAIHub-Router-Name' => $routerName,
        ],
    ];
    $requester ??= static function (array $request): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('pairing failed');
        }
        $handle = curl_init($request['url']);
        if ($handle === false) {
            throw new RuntimeException('pairing failed');
        }
        if (!curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($request['headers']), $request['headers']),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ])) {
            curl_close($handle);
            throw new RuntimeException('pairing failed');
        }
        $body = curl_exec($handle);
        $status = (int)(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0);
        curl_close($handle);
        if ($body === false) {
            throw new RuntimeException('pairing failed');
        }

        return ['status' => $status, 'body' => $body];
    };

    try {
        $response = $requester($request);
    } catch (Throwable) {
        throw new RuntimeException('pairing failed');
    }
    if (!is_array($response) || (int)($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) {
        throw new RuntimeException('pairing failed');
    }
    try {
        $pairing = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        throw new RuntimeException('pairing response invalid');
    }
    if (!is_array($pairing) || !is_array($pairing['modes'] ?? null)) {
        throw new RuntimeException('pairing response invalid');
    }
    foreach (['station_key', 'display_name', 'public_base_url', 'station_token'] as $field) {
        if (!is_scalar($pairing[$field] ?? null) || trim((string)$pairing[$field]) === '') {
            throw new RuntimeException('pairing response invalid');
        }
    }

    try {
        $stationId = hub_cluster_save_paired_station($db, $pairing);
    } catch (Throwable) {
        throw new RuntimeException('pairing response invalid');
    }
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('pairing failed');
    }

    return $station;
}

function hub_cluster_save_paired_station(PDO $db, array $pairing): int
{
    $stationKeyValue = $pairing['station_key'] ?? null;
    $displayNameValue = $pairing['display_name'] ?? null;
    $publicBaseUrlValue = $pairing['public_base_url'] ?? null;
    $tokenValue = $pairing['station_token'] ?? null;
    if (!is_scalar($stationKeyValue) || !is_scalar($displayNameValue) || !is_scalar($publicBaseUrlValue) || !is_scalar($tokenValue)) {
        throw new InvalidArgumentException('Station pairing is invalid.');
    }
    $stationKey = trim((string)$stationKeyValue);
    $displayName = trim((string)$displayNameValue);
    $token = (string)$tokenValue;
    if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/i', $stationKey) !== 1 || $displayName === '' || trim($token) === '') {
        throw new InvalidArgumentException('Station pairing is invalid.');
    }
    $priority = $pairing['priority'] ?? 0;
    if (!is_int($priority) && (!is_string($priority) || preg_match('/\A-?\d+\z/', $priority) !== 1)) {
        throw new InvalidArgumentException('Station pairing is invalid.');
    }
    if (array_key_exists('enabled', $pairing) && !is_bool($pairing['enabled'])) {
        throw new InvalidArgumentException('Station pairing is invalid.');
    }

    $encrypted = hub_cluster_encrypt_station_token($token);
    $publicBaseUrl = hub_cluster_validate_station_base_url((string)$publicBaseUrlValue);
    if (array_key_exists('internal_base_url', $pairing) && !is_scalar($pairing['internal_base_url']) && $pairing['internal_base_url'] !== null) {
        throw new InvalidArgumentException('Station pairing is invalid.');
    }
    $internalValue = trim((string)($pairing['internal_base_url'] ?? ''));
    $internalBaseUrl = $internalValue === '' ? null : hub_cluster_validate_station_base_url($internalValue);
    $manifestJson = isset($pairing['modes'])
        ? json_encode(['modes' => $pairing['modes']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        : null;
    $now = hub_now();
    $db->prepare(
        'INSERT INTO cluster_stations (
            station_key, display_name, public_base_url, internal_base_url, priority, enabled,
            token_ciphertext, token_iv, token_tag, manifest_json, created_at, updated_at
        ) VALUES (
            :station_key, :display_name, :public_base_url, :internal_base_url, :priority, :enabled,
            :token_ciphertext, :token_iv, :token_tag, :manifest_json, :created_at, :updated_at
        ) ON CONFLICT(station_key) DO UPDATE SET
            display_name = excluded.display_name,
            public_base_url = excluded.public_base_url,
            internal_base_url = excluded.internal_base_url,
            priority = excluded.priority,
            enabled = excluded.enabled,
            token_ciphertext = excluded.token_ciphertext,
            token_iv = excluded.token_iv,
            token_tag = excluded.token_tag,
            manifest_json = excluded.manifest_json,
            updated_at = excluded.updated_at'
    )->execute([
        ':station_key' => $stationKey,
        ':display_name' => $displayName,
        ':public_base_url' => $publicBaseUrl,
        ':internal_base_url' => $internalBaseUrl,
        ':priority' => (int)$priority,
        ':enabled' => !array_key_exists('enabled', $pairing) || $pairing['enabled'] ? 1 : 0,
        ':token_ciphertext' => $encrypted['token_ciphertext'],
        ':token_iv' => $encrypted['token_iv'],
        ':token_tag' => $encrypted['token_tag'],
        ':manifest_json' => $manifestJson,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    $stmt = $db->prepare('SELECT id FROM cluster_stations WHERE station_key = :station_key');
    $stmt->execute([':station_key' => $stationKey]);
    $stationId = $stmt->fetchColumn();
    if ($stationId === false) {
        throw new RuntimeException('Station pairing failed.');
    }

    return (int)$stationId;
}

function hub_cluster_get_station(PDO $db, int $stationId): ?array
{
    $stmt = $db->prepare('SELECT * FROM cluster_stations WHERE id = :id');
    $stmt->execute([':id' => $stationId]);
    $station = $stmt->fetch();

    return $station === false ? null : $station;
}

function hub_cluster_list_stations(PDO $db): array
{
    return $db->query(
        'SELECT id, station_key, display_name, public_base_url, internal_base_url, priority, enabled,
                manifest_json, manifest_fetched_at, status_json, status_fetched_at, last_error, created_at, updated_at
         FROM cluster_stations
         ORDER BY priority DESC, id ASC'
    )->fetchAll();
}

function hub_cluster_station_token(array $station): string
{
    return hub_cluster_decrypt_station_token($station);
}

function hub_cluster_select_station(string $mode, array $stations): ?array
{
    $eligible = array_values(array_filter($stations, static function (array $station) use ($mode): bool {
        return !empty($station['enabled'])
            && !empty($station['fresh'])
            && is_array($station['modes'] ?? null)
            && in_array($mode, $station['modes'], true);
    }));
    if ($eligible === []) {
        return null;
    }

    $unpressured = array_values(array_filter($eligible, static fn (array $station): bool => (int)($station['gpu_free_vram_mb'] ?? 0) > 0
        && (int)($station['active_gpu_leases'] ?? 0) === 0
        && (int)($station['queued_jobs'] ?? 0) === 0));
    $candidates = $unpressured !== [] ? $unpressured : $eligible;
    usort($candidates, static function (array $left, array $right): int {
        foreach ([
            [(int)($right['priority'] ?? 0), (int)($left['priority'] ?? 0)],
            [(int)($right['gpu_free_vram_mb'] ?? 0), (int)($left['gpu_free_vram_mb'] ?? 0)],
            [(int)($left['active_gpu_leases'] ?? 0), (int)($right['active_gpu_leases'] ?? 0)],
            [(int)($left['queued_jobs'] ?? 0), (int)($right['queued_jobs'] ?? 0)],
            [(int)($left['id'] ?? 0), (int)($right['id'] ?? 0)],
        ] as [$first, $second]) {
            $comparison = $first <=> $second;
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    });

    return $candidates[0];
}

function hub_cluster_node_enabled(PDO $db): bool
{
    return hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED') === '1';
}

function hub_cluster_router_enabled(PDO $db): bool
{
    return hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED') === '1';
}

function hub_cluster_node_configure(PDO $db, bool $enabled, array $selectedModes): array
{
    $selectedModes = hub_cluster_node_selected_published_modes($db, $selectedModes);
    $db->beginTransaction();
    try {
        $tokenId = hub_cluster_node_token_id($db);
        if (!$enabled) {
            if ($tokenId > 0) {
                hub_revoke_api_token($db, $tokenId);
            }
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '0');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_MODE_JSON', '[]');
            hub_cluster_node_clear_pairing($db);
            hub_cluster_node_clear_token($db);
            $db->commit();

            return ['enabled' => false, 'modes' => []];
        }

        hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '1');
        hub_cluster_node_store_modes($db, $selectedModes);
        $token = $tokenId > 0 ? hub_get_api_token($db, $tokenId) : null;
        if ($token === null || (int)$token['enabled'] !== 1 || !empty($token['revoked_at'])) {
            if ($tokenId > 0) {
                hub_revoke_api_token($db, $tokenId);
            }
            hub_cluster_node_clear_pairing($db);
            $created = hub_cluster_node_create_token($db, $tokenId);
            hub_cluster_node_store_token($db, $created['plain_token'], (int)$created['token_id']);
            hub_cluster_node_sync_token_permissions($db, (int)$created['token_id']);
            $invitation = hub_cluster_create_pair_invitation($db);
            $db->commit();

            return ['enabled' => true, 'modes' => $selectedModes] + $invitation;
        }

        hub_cluster_node_sync_token_permissions($db, $tokenId);
        $db->commit();

        return ['enabled' => true, 'modes' => $selectedModes];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_cluster_node_reveal_token(PDO $db): string
{
    if (!hub_cluster_node_enabled($db) || hub_cluster_node_token_id($db) < 1) {
        throw new RuntimeException('cluster node token is unavailable');
    }

    return hub_cluster_decrypt_station_token([
        'token_ciphertext' => hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT'),
        'token_iv' => hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_IV'),
        'token_tag' => hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_TAG'),
    ]);
}

function hub_cluster_node_regenerate_token(PDO $db): array
{
    if (!hub_cluster_node_enabled($db)) {
        throw new RuntimeException('cluster node is disabled');
    }

    $db->beginTransaction();
    try {
        $previousTokenId = hub_cluster_node_token_id($db);
        if ($previousTokenId > 0) {
            hub_revoke_api_token($db, $previousTokenId);
        }
        hub_cluster_node_clear_pairing($db);
        hub_cluster_node_clear_token($db);
        $created = hub_cluster_node_create_token($db, $previousTokenId);
        hub_cluster_node_store_token($db, $created['plain_token'], (int)$created['token_id']);
        hub_cluster_node_sync_token_permissions($db, (int)$created['token_id']);
        $invitation = hub_cluster_create_pair_invitation($db);
        $db->commit();

        return ['enabled' => true, 'modes' => hub_cluster_node_selected_published_modes($db)] + $invitation;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_cluster_accept_pair_invitation(PDO $db, string $invite, string $clientIp, string $routerName): array
{
    $routerName = trim($routerName);
    if (
        preg_match('/\A[a-f0-9]{64}\z/', $invite) !== 1
        || !filter_var($clientIp, FILTER_VALIDATE_IP)
        || strlen($routerName) < 1
        || strlen($routerName) > 120
        || preg_match('/[\x00-\x1F\x7F]/', $routerName) === 1
    ) {
        throw new RuntimeException('pairing failed');
    }

    $db->beginTransaction();
    try {
        $tokenId = hub_cluster_node_token_id($db);
        $expiresAt = strtotime(hub_cluster_pair_invitation_expires_at($db));
        $inviteHash = hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH');
        if (
            !hub_cluster_node_enabled($db)
            || $tokenId < 1
            || hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') !== ''
            || $expiresAt === false
            || $expiresAt <= time()
            || preg_match('/\A[0-9a-f]{64}\z/', $inviteHash) !== 1
            || !hash_equals($inviteHash, hash('sha256', $invite))
        ) {
            throw new RuntimeException('pairing failed');
        }
        $token = hub_get_api_token($db, $tokenId);
        if ($token === null || (int)$token['enabled'] !== 1 || !empty($token['revoked_at'])) {
            throw new RuntimeException('pairing failed');
        }

        $plainToken = hub_cluster_node_reveal_token($db);
        hub_cluster_node_sync_token_permissions($db, $tokenId);
        $db->prepare('DELETE FROM api_token_ip_whitelists WHERE token_id = :token_id')->execute([':token_id' => $tokenId]);
        hub_add_api_token_ip_rule($db, $tokenId, $clientIp, 'cluster router');
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', $routerName);
        hub_cluster_clear_pair_invitation($db);
        $pairing = hub_cluster_node_pairing_descriptor($db);
        $pairing['station_token'] = $plainToken;
        $db->commit();

        return $pairing;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_cluster_node_sync_token_permissions(PDO $db, int $tokenId): void
{
    if (!hub_cluster_node_enabled($db) || $tokenId < 1 || $tokenId !== hub_cluster_node_token_id($db)) {
        throw new RuntimeException('cluster node token is unavailable');
    }
    $token = hub_get_api_token($db, $tokenId);
    if ($token === null || (int)$token['enabled'] !== 1 || !empty($token['revoked_at'])) {
        throw new RuntimeException('cluster node token is unavailable');
    }

    hub_set_api_token_mode_permissions($db, $tokenId, array_merge(['cluster_status'], hub_cluster_node_selected_published_modes($db)));
}

function hub_cluster_status_payload(PDO $db): array
{
    $now = hub_now();
    $lease = $db->prepare(
        "SELECT COUNT(*) FROM runtime_resource_leases
         WHERE state = 'leased' AND lease_expires_at IS NOT NULL AND lease_expires_at > :now"
    );
    $lease->execute([':now' => $now]);
    $queued = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'queued'")->fetchColumn();
    $running = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'running'")->fetchColumn();

    return [
        'ok' => true,
        'snapshot_at' => $now,
        'gpu' => hub_collect_gpu_metric(),
        'active_gpu_leases' => (int)$lease->fetchColumn(),
        'queued_jobs' => (int)$queued,
        'running_jobs' => (int)$running,
        'modes' => hub_cluster_node_selected_published_modes($db),
    ];
}

function hub_cluster_station_is_fresh(array $station, ?int $now = null): bool
{
    $now ??= time();
    foreach (['manifest_fetched_at', 'status_fetched_at'] as $field) {
        $fetchedAt = strtotime((string)($station[$field] ?? ''));
        if ($fetchedAt === false || $fetchedAt > $now + 5 || ($now - $fetchedAt) > 30) {
            return false;
        }
    }

    return true;
}

function hub_cluster_refresh_station(PDO $db, array $station, ?callable $fetcher = null): array
{
    return hub_cluster_refresh_station_now($db, $station, false, $fetcher);
}

function hub_cluster_refresh_due_stations(PDO $db, bool $force = false, ?callable $fetcher = null): array
{
    $stations = $db->query('SELECT * FROM cluster_stations ORDER BY priority DESC, id ASC')->fetchAll();
    $refreshed = [];
    foreach ($stations as $station) {
        $refreshed[] = hub_cluster_refresh_station_now($db, $station, $force, $fetcher);
    }

    return $refreshed;
}

function hub_cluster_node_token_id(PDO $db): int
{
    $value = hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
    return preg_match('/\A[1-9]\d*\z/', $value) === 1 ? (int)$value : 0;
}

function hub_cluster_node_selected_published_modes(PDO $db, ?array $selectedModes = null): array
{
    $selectedModes ??= hub_cluster_node_selected_modes($db);
    $selectedModes = hub_cluster_node_normalize_modes($selectedModes);
    $available = array_fill_keys(hub_cluster_node_published_modes($db), true);

    return array_values(array_filter($selectedModes, static fn (string $mode): bool => isset($available[$mode])));
}

function hub_cluster_node_selected_modes(PDO $db): array
{
    try {
        $modes = json_decode(hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_MODE_JSON'), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }

    return is_array($modes) ? $modes : [];
}

function hub_cluster_node_published_modes(PDO $db): array
{
    $rows = $db->query(
        "SELECT mode FROM services
         WHERE install_status = 'installed' AND enabled = 1 AND runtime_status = 'running'
         ORDER BY mode ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    return hub_cluster_node_normalize_modes($rows);
}

function hub_cluster_node_normalize_modes(array $modes): array
{
    $normalized = [];
    foreach ($modes as $mode) {
        if (!is_string($mode)) {
            throw new InvalidArgumentException('cluster modes are invalid');
        }
        $mode = trim($mode);
        if ($mode === '') {
            continue;
        }
        if (preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) !== 1) {
            throw new InvalidArgumentException('cluster modes are invalid');
        }
        $normalized[$mode] = true;
    }

    return array_keys($normalized);
}

function hub_cluster_node_store_modes(PDO $db, array $modes): void
{
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_MODE_JSON', json_encode($modes, JSON_THROW_ON_ERROR));
}

function hub_cluster_node_create_token(PDO $db, int $previousTokenId): array
{
    $previous = $previousTokenId > 0 ? hub_get_api_token($db, $previousTokenId) : null;
    $memberId = $previous === null ? hub_create_api_member($db, '3waAIHub Cluster Node') : (int)$previous['member_id'];

    return hub_create_api_token($db, $memberId, 'Cluster node station token', null, null);
}

function hub_cluster_node_store_token(PDO $db, string $plainToken, int $tokenId): void
{
    $encrypted = hub_cluster_encrypt_station_token($plainToken);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID', (string)$tokenId);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT', $encrypted['token_ciphertext']);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_IV', $encrypted['token_iv']);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_TAG', $encrypted['token_tag']);
}

function hub_cluster_node_clear_token(PDO $db): void
{
    foreach ([
        'AIHUB_CLUSTER_NODE_TOKEN_ID',
        'AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT',
        'AIHUB_CLUSTER_NODE_TOKEN_IV',
        'AIHUB_CLUSTER_NODE_TOKEN_TAG',
    ] as $key) {
        hub_set_storage_setting($db, $key, '');
    }
}

function hub_cluster_node_clear_pairing(PDO $db): void
{
    hub_cluster_clear_pair_invitation($db);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', '');
}

function hub_cluster_clear_pair_invitation(PDO $db): void
{
    foreach ([
        'AIHUB_CLUSTER_PAIR_INVITE_HASH',
        'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT',
        'AIHUB_CLUSTER_PAIR_EXPIRES_AT',
    ] as $key) {
        hub_set_storage_setting($db, $key, '');
    }
}

function hub_cluster_pair_invitation_expires_at(PDO $db): string
{
    $expiresAt = hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT');
    if ($expiresAt !== '') {
        return $expiresAt;
    }
    $legacyExpiresAt = hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT');
    $legacyTimestamp = strtotime($legacyExpiresAt);
    if ($legacyTimestamp !== false && $legacyTimestamp > time()) {
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', $legacyExpiresAt);
    }

    return $legacyExpiresAt;
}

function hub_cluster_node_pairing_descriptor(PDO $db): array
{
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/cluster_pair.php'));
    $path = rtrim(dirname($script), '/');
    $baseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
        . '://' . $host . ($path === '' || $path === '.' ? '/' : $path . '/');
    $publicBaseUrl = hub_cluster_validate_station_base_url($baseUrl);

    return [
        'station_key' => 'node_' . substr(hash('sha256', $publicBaseUrl), 0, 24),
        'display_name' => hub_site_title($db),
        'public_base_url' => $publicBaseUrl,
        'modes' => hub_cluster_node_selected_published_modes($db),
    ];
}

function hub_cluster_refresh_station_now(PDO $db, array $station, bool $force, ?callable $fetcher): array
{
    $stationId = (int)($station['id'] ?? 0);
    if ($stationId < 1) {
        throw new InvalidArgumentException('station refresh failed');
    }

    $db->beginTransaction();
    try {
        $station = hub_cluster_get_station($db, $stationId);
        if ($station === null) {
            throw new RuntimeException('station refresh failed');
        }
        if (!$force && !hub_cluster_station_refresh_due($station)) {
            $db->commit();

            return hub_cluster_station_inventory($station);
        }
        $db->prepare('UPDATE cluster_stations SET last_error = :last_error, updated_at = :updated_at WHERE id = :id')
            ->execute([':last_error' => 'refreshing', ':updated_at' => hub_now(), ':id' => $stationId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    try {
        $baseUrl = hub_cluster_station_request_base_url($station);
        $token = hub_cluster_station_token($station);
    } catch (Throwable) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'station_auth_failed');
    }
    $fetcher ??= 'hub_cluster_default_station_fetcher';
    try {
        $manifestResponse = $fetcher(['url' => $baseUrl . 'api_manifest.json.php', 'method' => 'GET', 'headers' => []]);
    } catch (Throwable) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_fetch_failed');
    }
    $manifest = hub_cluster_refresh_json_payload($manifestResponse);
    if ($manifest === null || !is_array($manifest['services'] ?? null)) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_invalid');
    }
    $manifestSnapshot = hub_cluster_compact_manifest_snapshot($manifest);
    if ($manifestSnapshot === null) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_invalid');
    }
    hub_cluster_store_station_manifest($db, $stationId, $manifestSnapshot);

    try {
        $statusResponse = $fetcher([
            'url' => $baseUrl . 'cluster_status.php',
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
    } catch (Throwable) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'status_fetch_failed');
    }
    $status = hub_cluster_refresh_json_payload($statusResponse);
    $statusSnapshot = $status === null ? null : hub_cluster_compact_status_snapshot($status);
    if ($statusSnapshot === null) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'status_invalid');
    }
    hub_cluster_store_station_status($db, $stationId, $statusSnapshot);

    $stored = hub_cluster_get_station($db, $stationId);
    if ($stored === null) {
        throw new RuntimeException('station refresh failed');
    }

    return hub_cluster_station_inventory($stored);
}

function hub_cluster_station_refresh_due(array $station, ?int $now = null): bool
{
    $now ??= time();
    $manifestAt = strtotime((string)($station['manifest_fetched_at'] ?? ''));
    $statusAt = strtotime((string)($station['status_fetched_at'] ?? ''));
    if ($manifestAt === false || $statusAt === false || $manifestAt > $now || $statusAt > $now) {
        return true;
    }

    return ($now - $manifestAt) >= 10 || ($now - $statusAt) >= 10;
}

function hub_cluster_default_station_fetcher(array $request): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('station refresh failed');
    }
    $handle = curl_init((string)($request['url'] ?? ''));
    if ($handle === false) {
        throw new RuntimeException('station refresh failed');
    }
    $body = '';
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    $configured = curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($headers), $headers),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 262144) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $result = $configured ? curl_exec($handle) : false;
    $status = (int)(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0);
    curl_close($handle);
    if ($result === false) {
        throw new RuntimeException('station refresh failed');
    }

    return ['status' => $status, 'body' => $body];
}

function hub_cluster_refresh_json_payload(mixed $response): ?array
{
    if (!is_array($response) || (int)($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) {
        return null;
    }
    try {
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($payload) ? $payload : null;
}

function hub_cluster_compact_manifest_snapshot(array $manifest): ?array
{
    $modes = [];
    foreach ($manifest['services'] as $service) {
        if (!is_array($service) || !is_string($service['mode'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $service['mode']) !== 1) {
            return null;
        }
        $modes[$service['mode']] = true;
    }

    return ['modes' => array_keys($modes)];
}

function hub_cluster_compact_status_snapshot(array $status): ?array
{
    if (
        ($status['ok'] ?? null) !== true
        || !is_string($status['snapshot_at'] ?? null)
        || !is_array($status['gpu'] ?? null)
        || !is_array($status['modes'] ?? null)
    ) {
        return null;
    }
    $snapshotAt = hub_cluster_verified_status_snapshot_at($status['snapshot_at']);
    if ($snapshotAt === null) {
        return null;
    }
    foreach (['active_gpu_leases', 'queued_jobs', 'running_jobs'] as $field) {
        if (!is_int($status[$field] ?? null) || $status[$field] < 0) {
            return null;
        }
    }
    try {
        $modes = hub_cluster_node_normalize_modes($status['modes']);
    } catch (Throwable) {
        return null;
    }
    $gpu = [];
    foreach (['available', 'reason', 'name', 'driver_version', 'cuda_version', 'util_percent', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb', 'temperature_c'] as $field) {
        if (is_bool($status['gpu'][$field] ?? null) || is_int($status['gpu'][$field] ?? null)) {
            $gpu[$field] = $status['gpu'][$field];
        } elseif (is_string($status['gpu'][$field] ?? null)) {
            $gpu[$field] = substr($status['gpu'][$field], 0, 128);
        }
    }

    return [
        'snapshot_at' => $snapshotAt,
        'gpu' => $gpu,
        'active_gpu_leases' => $status['active_gpu_leases'],
        'queued_jobs' => $status['queued_jobs'],
        'running_jobs' => $status['running_jobs'],
        'modes' => $modes,
    ];
}

function hub_cluster_store_station_manifest(PDO $db, int $stationId, array $snapshot): void
{
    $now = hub_now();
    $db->prepare(
        'UPDATE cluster_stations SET manifest_json = :manifest_json, manifest_fetched_at = :fetched_at, updated_at = :updated_at WHERE id = :id'
    )->execute([
        ':manifest_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ':fetched_at' => $now,
        ':updated_at' => $now,
        ':id' => $stationId,
    ]);
}

function hub_cluster_store_station_status(PDO $db, int $stationId, array $snapshot): void
{
    $db->prepare(
        'UPDATE cluster_stations
         SET status_json = :status_json, status_fetched_at = :fetched_at, last_error = :last_error, updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':status_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ':fetched_at' => $snapshot['snapshot_at'],
        ':last_error' => '',
        ':updated_at' => hub_now(),
        ':id' => $stationId,
    ]);
}

function hub_cluster_verified_status_snapshot_at(string $value, ?int $now = null): ?string
{
    $now ??= time();
    $timezone = new DateTimeZone(date_default_timezone_get());
    $snapshot = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $snapshot === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $snapshot->format('Y-m-d H:i:s') !== $value
    ) {
        return null;
    }
    $timestamp = $snapshot->getTimestamp();
    if ($timestamp < $now - 30 || $timestamp > $now + 5) {
        return null;
    }

    return $snapshot->format('Y-m-d H:i:s');
}

function hub_cluster_store_station_refresh_error(PDO $db, int $stationId, string $error): array
{
    $db->prepare('UPDATE cluster_stations SET last_error = :last_error, updated_at = :updated_at WHERE id = :id')
        ->execute([':last_error' => $error, ':updated_at' => hub_now(), ':id' => $stationId]);
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('station refresh failed');
    }

    return hub_cluster_station_inventory($station);
}

function hub_cluster_station_inventory(array $station): array
{
    $status = json_decode((string)($station['status_json'] ?? ''), true);
    $status = is_array($status) ? $status : [];

    return [
        'id' => (int)($station['id'] ?? 0),
        'station_key' => (string)($station['station_key'] ?? ''),
        'enabled' => !empty($station['enabled']),
        'fresh' => hub_cluster_station_is_fresh($station),
        'last_error' => (string)($station['last_error'] ?? ''),
        'modes' => is_array($status['modes'] ?? null) ? $status['modes'] : [],
        'gpu_free_vram_mb' => (int)($status['gpu']['memory_free_mb'] ?? 0),
        'active_gpu_leases' => (int)($status['active_gpu_leases'] ?? 0),
        'queued_jobs' => (int)($status['queued_jobs'] ?? 0),
        'running_jobs' => (int)($status['running_jobs'] ?? 0),
    ];
}
