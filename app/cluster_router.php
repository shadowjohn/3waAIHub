<?php
declare(strict_types=1);

require_once __DIR__ . '/public_api_docs.php';

function hub_cluster_secret_key_path(): string
{
    return HUB_DATA_DIR . '/cluster.key';
}

function hub_cluster_secret_key_from_hex(string $value): string
{
    if (preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
        throw new InvalidArgumentException('Cluster secret is invalid.');
    }

    $key = hex2bin($value);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Cluster secret is invalid.');
    }

    return $key;
}

function hub_cluster_secret_key(): string
{
    hub_ensure_runtime_dirs();
    $path = hub_cluster_secret_key_path();
    $handle = @fopen($path, 'rb');
    if ($handle !== false) {
        try {
            if (!flock($handle, LOCK_SH)) {
                throw new RuntimeException('Cluster secret is unavailable.');
            }
            $value = trim((string)stream_get_contents($handle));
            if ($value === '') {
                throw new RuntimeException('Cluster secret is unavailable.');
            }

            return hub_cluster_secret_key_from_hex($value);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    $handle = @fopen($path, 'x+');
    if ($handle === false) {
        throw new RuntimeException('Cluster secret is unavailable.');
    }

    $initialized = false;
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Cluster secret is unavailable.');
        }
        // Keep old installations working once, then persist the key with the Hub data.
        $value = trim((string)(getenv('AIHUB_CLUSTER_SECRET_KEY') ?: ''));
        if ($value === '') {
            try {
                $value = bin2hex(random_bytes(32));
            } catch (Throwable) {
                throw new RuntimeException('Cluster secret is unavailable.');
            }
        }
        hub_cluster_secret_key_from_hex($value);
        $payload = $value . PHP_EOL;
        if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
            throw new RuntimeException('Cluster secret is unavailable.');
        }
        @chmod($path, 0640);
        $initialized = true;

        return hub_cluster_secret_key_from_hex($value);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$initialized) {
            @unlink($path);
        }
    }
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
    // 內網 AI 節點是預設拓撲；實際允許範圍仍由 private literal IP 檢查限制。
    return getenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL') !== '0';
}

function hub_cluster_private_http_host_allowed(string $host): bool
{
    $host = trim($host, '[]');
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

/**
 * 配對請求只接受前面已驗證的 cluster_pair.php endpoint，並保留原始 endpoint 路徑。
 */
function hub_cluster_pairing_request_url(array $parts): string
{
    $requestUrl = strtolower((string)($parts['scheme'] ?? '')) . '://' . (string)($parts['host'] ?? '')
        . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . (string)($parts['path'] ?? '');
    $validated = hub_cluster_validate_station_base_url($requestUrl);
    $requestUrl = rtrim($validated, '/');
    if (!str_ends_with($requestUrl, '/cluster_pair.php')) {
        throw new InvalidArgumentException('pairing failed');
    }

    return $requestUrl;
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
    try {
        $requestUrl = hub_cluster_pairing_request_url($parts);
    } catch (Throwable) {
        throw new InvalidArgumentException('pairing failed');
    }
    $request = [
        'url' => $requestUrl,
        'method' => 'POST',
        'body' => '',
        'headers' => [
            'X-3waAIHub-Pair-Invite' => $matches[1],
            'X-3waAIHub-Router-Name' => $routerName,
            // IIS/HTTP.SYS rejects a bodyless POST without an explicit length.
            'Content-Length' => '0',
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
            CURLOPT_POSTFIELDS => (string)($request['body'] ?? ''),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($request['headers']), $request['headers']),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
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

function hub_cluster_register_self_station(PDO $db): array
{
    if (!hub_cluster_router_enabled($db) || !hub_cluster_node_enabled($db)) {
        throw new RuntimeException('local cluster node requires both roles');
    }
    $tokenId = hub_cluster_node_token_id($db);
    if ($tokenId < 1) {
        throw new RuntimeException('local cluster node is unavailable');
    }

    $routerName = trim(hub_site_title($db));
    $routerName = function_exists('mb_substr') ? mb_substr($routerName, 0, 120, 'UTF-8') : substr($routerName, 0, 120);

    $db->beginTransaction();
    try {
        $pairing = hub_cluster_node_pairing_descriptor($db);
        $pairing['station_token'] = hub_cluster_node_reveal_token($db);
        if (
            hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') !== ''
            && !hub_cluster_node_has_verified_router_peer($db, $tokenId, '127.0.0.1')
        ) {
            if (hub_enabled_api_token_ip_rules($db, $tokenId) !== []) {
                throw new RuntimeException('local cluster node is paired to another router');
            }
            $stmt = $db->prepare('SELECT * FROM cluster_stations WHERE station_key = :station_key');
            $stmt->execute([':station_key' => (string)$pairing['station_key']]);
            $legacyStation = $stmt->fetch();
            try {
                $legacyToken = $legacyStation === false ? null : hub_cluster_decrypt_station_token($legacyStation);
            } catch (Throwable) {
                $legacyToken = null;
            }
            if (!is_string($legacyToken) || !hash_equals((string)$pairing['station_token'], $legacyToken)) {
                throw new RuntimeException('local cluster node is paired to another router');
            }
        }

        $db->prepare('DELETE FROM api_token_ip_whitelists WHERE token_id = :token_id')->execute([':token_id' => $tokenId]);
        hub_add_api_token_ip_rule($db, $tokenId, '127.0.0.1', 'cluster router');
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', $routerName);
        hub_cluster_clear_pair_invitation($db);
        $stationId = hub_cluster_save_paired_station($db, $pairing);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', (string)$pairing['station_key']);
        $station = hub_cluster_get_station($db, $stationId);
        if ($station === null) {
            throw new RuntimeException('local cluster station registration failed');
        }
        $db->commit();

        return $station;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
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

function hub_cluster_delete_station(PDO $db, int $stationId): void
{
    if ($stationId < 1) {
        throw new InvalidArgumentException('station delete failed');
    }
    $stmt = $db->prepare('DELETE FROM cluster_stations WHERE id = :id');
    $stmt->execute([':id' => $stationId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('station delete failed');
    }
}

function hub_cluster_list_stations(PDO $db): array
{
    return $db->query(
        'SELECT id, station_key, display_name, public_base_url, internal_base_url, priority, enabled,
                manifest_json, manifest_fetched_at, status_json, status_fetched_at, last_error, created_at, updated_at,
                CASE WHEN token_ciphertext <> \'\' AND token_iv <> \'\' AND token_tag <> \'\' THEN 1 ELSE 0 END AS token_configured
         FROM cluster_stations
         ORDER BY priority DESC, id ASC'
    )->fetchAll();
}

function hub_cluster_station_dashboard_rows(PDO $db): array
{
    $activeRoutes = $db->query(
        'SELECT station_id, COUNT(*) AS active_route_count
         FROM cluster_routes
         WHERE completed_at IS NULL
         GROUP BY station_id'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $localRelease = hub_release_node_report($db);
    $rows = [];
    foreach (hub_cluster_list_stations($db) as $station) {
        $inventory = hub_cluster_station_inventory($station);
        $manifest = json_decode((string)($station['manifest_json'] ?? ''), true);
        $manifestSnapshot = is_array($manifest) && is_array($manifest['services'] ?? null)
            ? hub_cluster_compact_manifest_snapshot($manifest)
            : null;
        $services = is_array($manifestSnapshot['services'] ?? null) ? $manifestSnapshot['services'] : [];
        $manifestModes = is_array($manifest['modes'] ?? null) ? $manifest['modes'] : [];
        $statusModes = array_fill_keys($inventory['modes'], true);
        $modeNames = [];
        foreach (array_merge($manifestModes, $inventory['modes']) as $mode) {
            if (is_string($mode) && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) === 1) {
                $modeNames[$mode] = true;
            }
        }
        ksort($modeNames, SORT_STRING);
        $modeReadiness = [];
        foreach (array_keys($modeNames) as $mode) {
            $modeReadiness[] = ['mode' => $mode, 'ready' => isset($statusModes[$mode])];
        }
        $status = json_decode((string)($station['status_json'] ?? ''), true);
        $status = is_array($status) ? $status : [];
        $gpu = is_array($status['gpu'] ?? null) ? $status['gpu'] : [];
        $serviceGpu = is_array($status['service_gpu'] ?? null)
            ? hub_cluster_compact_service_gpu_snapshot($status['service_gpu'])
            : [];
        $serviceStatus = is_array($status['service_status'] ?? null)
            ? hub_cluster_compact_service_status_snapshot($status['service_status'])
            : [];
        $report = hub_cluster_compact_status_report_fields($status) ?? [];
        $compatibility = hub_release_station_report($station, $localRelease);
        $rows[] = [
            'id' => (int)$station['id'],
            'station_key' => (string)$station['station_key'],
            'display_name' => (string)$station['display_name'],
            'public_base_url' => (string)$station['public_base_url'],
            'internal_base_url' => (string)($station['internal_base_url'] ?? ''),
            'priority' => (int)$station['priority'],
            'enabled' => !empty($station['enabled']),
            'token_configured' => !empty($station['token_configured']),
            'manifest_fetched_at' => (string)($station['manifest_fetched_at'] ?? ''),
            'status_fetched_at' => (string)($station['status_fetched_at'] ?? ''),
            'fresh' => !empty($inventory['fresh']),
            'last_error' => (string)($inventory['last_error'] ?? ''),
            'connection_state' => hub_cluster_station_connection_state($station),
            'modes' => $inventory['modes'],
            'mode_readiness' => $modeReadiness,
            'gpu_free_vram_mb' => (int)($gpu['memory_free_mb'] ?? 0),
            'gpu_total_vram_mb' => (int)($gpu['memory_total_mb'] ?? 0),
            'gpu' => $gpu,
            'service_gpu' => $serviceGpu ?? [],
            'service_status' => $serviceStatus ?? [],
            'active_gpu_leases' => (int)$inventory['active_gpu_leases'],
            'queued_jobs' => (int)$inventory['queued_jobs'],
            'running_jobs' => (int)$inventory['running_jobs'],
            'active_route_count' => (int)($activeRoutes[(int)$station['id']] ?? 0),
            'release' => $report['release'] ?? [],
            'packs' => $report['packs'] ?? [],
            'runners' => $report['runners'] ?? [],
            'health' => $report['health'] ?? [],
            'cluster' => $report['cluster'] ?? [],
            'services' => $services,
            'service_count' => count($services),
            'release_compatible' => $compatibility['update_needed'] === null ? null : !$compatibility['update_needed'],
            'pack_compatible' => $compatibility['pack_compatible'],
        ];
    }

    return $rows;
}

function hub_cluster_recent_routes(PDO $db, array $filters = [], int $limit = 100): array
{
    [$where, $params] = hub_cluster_usage_filter_sql($filters, 'r', 'route_');
    $limit = max(1, min(500, $limit));
    $stmt = $db->prepare(
        'SELECT r.route_id, r.station_id, r.member_id, r.token_id, r.mode, r.state, r.remote_status,
                r.is_async, r.created_at, r.updated_at, r.completed_at,
                m.name AS member_name, t.token_name, t.token_prefix, s.display_name AS station_name,
                (SELECT COUNT(*) FROM cluster_route_accesses a WHERE a.route_id = r.route_id) AS accesses,
                (SELECT COALESCE(SUM(a.upload_bytes), 0) FROM cluster_route_accesses a WHERE a.route_id = r.route_id) AS upload_bytes,
                (SELECT COALESCE(SUM(a.response_bytes), 0) FROM cluster_route_accesses a WHERE a.route_id = r.route_id) AS response_bytes
         FROM cluster_routes r
         LEFT JOIN api_members m ON m.id = r.member_id
         LEFT JOIN api_tokens t ON t.id = r.token_id
         JOIN cluster_stations s ON s.id = r.station_id
         WHERE ' . $where . '
         ORDER BY r.created_at DESC, r.route_id DESC
         LIMIT :limit'
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_cluster_usage_summary(PDO $db, array $filters = []): array
{
    [$accessWhere, $accessParams] = hub_cluster_usage_filter_sql($filters, 'a', 'access_');
    $access = $db->prepare(
        'SELECT COUNT(*) AS accesses,
                COALESCE(SUM(CASE WHEN access_kind = \'submit\' THEN 1 ELSE 0 END), 0) AS work_requests,
                COALESCE(SUM(CASE WHEN ok = 1 THEN 1 ELSE 0 END), 0) AS success_count,
                COALESCE(SUM(CASE WHEN ok = 0 THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(upload_bytes), 0) AS upload_bytes,
                COALESCE(SUM(response_bytes), 0) AS response_bytes
         FROM cluster_route_accesses a
         WHERE ' . $accessWhere
    );
    $access->execute($accessParams);
    $totals = $access->fetch() ?: [];

    [$activeWhere, $activeParams] = hub_cluster_usage_filter_sql($filters, 'r', 'active_');
    $active = $db->prepare('SELECT COUNT(*) FROM cluster_routes r WHERE ' . $activeWhere . ' AND r.completed_at IS NULL');
    $active->execute($activeParams);

    [$startWhere, $startParams] = hub_cluster_usage_filter_sql($filters, 'r', 'start_');
    [$endWhere, $endParams] = hub_cluster_usage_filter_sql($filters, 'r', 'end_');
    $events = $db->prepare(
        'SELECT r.created_at AS event_at, 1 AS delta
         FROM cluster_routes r
         WHERE ' . $startWhere . '
         UNION ALL
         SELECT r.completed_at AS event_at, -1 AS delta
         FROM cluster_routes r
         WHERE ' . $endWhere . ' AND r.completed_at IS NOT NULL
         ORDER BY event_at ASC, delta ASC'
    );
    $events->execute(array_merge($startParams, $endParams));
    $concurrency = 0;
    $peakConcurrency = 0;
    foreach ($events->fetchAll() as $event) {
        $concurrency = max(0, $concurrency + (int)$event['delta']);
        $peakConcurrency = max($peakConcurrency, $concurrency);
    }

    return [
        'work_requests' => (int)($totals['work_requests'] ?? 0),
        'accesses' => (int)($totals['accesses'] ?? 0),
        'success_count' => (int)($totals['success_count'] ?? 0),
        'failed_count' => (int)($totals['failed_count'] ?? 0),
        'active_routes' => (int)$active->fetchColumn(),
        'peak_concurrency' => $peakConcurrency,
        'upload_bytes' => (int)($totals['upload_bytes'] ?? 0),
        'response_bytes' => (int)($totals['response_bytes'] ?? 0),
    ];
}

function hub_cluster_usage_rows(PDO $db, array $filters = []): array
{
    [$where, $params] = hub_cluster_usage_filter_sql($filters, 'a', 'usage_');
    $stmt = $db->prepare(
        'SELECT a.member_id, a.token_id, a.station_id, m.name AS member_name,
                t.token_name, t.token_prefix, s.display_name AS station_name,
                COUNT(*) AS accesses,
                COALESCE(SUM(CASE WHEN a.access_kind = \'submit\' THEN 1 ELSE 0 END), 0) AS work_requests,
                COALESCE(SUM(CASE WHEN a.ok = 1 THEN 1 ELSE 0 END), 0) AS success_count,
                COALESCE(SUM(CASE WHEN a.ok = 0 THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(a.upload_bytes), 0) AS upload_bytes,
                COALESCE(SUM(a.response_bytes), 0) AS response_bytes
         FROM cluster_route_accesses a
         LEFT JOIN api_members m ON m.id = a.member_id
         LEFT JOIN api_tokens t ON t.id = a.token_id
         LEFT JOIN cluster_stations s ON s.id = a.station_id
         WHERE ' . $where . '
         GROUP BY a.member_id, a.token_id, a.station_id
         ORDER BY accesses DESC, a.member_id ASC, a.token_id ASC, a.station_id ASC'
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function hub_cluster_usage_filter_sql(array $filters, string $alias, string $prefix): array
{
    $clauses = ['1 = 1'];
    $params = [];
    foreach (['member_id', 'token_id', 'station_id'] as $field) {
        if (!array_key_exists($field, $filters) || $filters[$field] === null || $filters[$field] === '') {
            continue;
        }
        $value = $filters[$field];
        if ((!is_int($value) && !is_string($value)) || preg_match('/\A[1-9]\d*\z/', (string)$value) !== 1) {
            throw new InvalidArgumentException('cluster usage filters are invalid');
        }
        $parameter = ':' . $prefix . $field;
        $clauses[] = $alias . '.' . $field . ' = ' . $parameter;
        $params[$parameter] = (int)$value;
    }
    if (array_key_exists('mode', $filters) && $filters['mode'] !== null && $filters['mode'] !== '') {
        if (!is_string($filters['mode']) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $filters['mode']) !== 1) {
            throw new InvalidArgumentException('cluster usage filters are invalid');
        }
        $parameter = ':' . $prefix . 'mode';
        $clauses[] = $alias . '.mode = ' . $parameter;
        $params[$parameter] = $filters['mode'];
    }

    return [implode(' AND ', $clauses), $params];
}

function hub_cluster_station_token(array $station): string
{
    return hub_cluster_decrypt_station_token($station);
}

function hub_cluster_mode_uses_gpu(string $mode): bool
{
    $definition = hub_pack_job_async_routes()[$mode] ?? null;

    // 未宣告的舊 mode 保留原本 GPU 優先行為，避免改變既有 GPU Pack 的路由語意。
    return !is_array($definition) || ($definition['accelerator'] ?? null) !== 'cpu';
}

function hub_cluster_select_station(string $mode, array $stations): ?array
{
    $eligible = array_values(array_filter($stations, static function (array $station) use ($mode): bool {
        return !empty($station['enabled'])
            && !empty($station['fresh'])
            && is_array($station['modes'] ?? null)
            && in_array($mode, $station['modes'], true)
            && (!hub_cluster_is_photo_mode($mode) || hub_cluster_photo_modes_are_paired($station['modes']));
    }));
    if ($eligible === []) {
        return null;
    }

    $usesGpu = hub_cluster_mode_uses_gpu($mode);
    $unpressured = array_values(array_filter($eligible, static function (array $station) use ($usesGpu): bool {
        if ((int)($station['queued_jobs'] ?? 0) !== 0) {
            return false;
        }

        return !$usesGpu || ((int)($station['gpu_free_vram_mb'] ?? 0) > 0
            && (int)($station['active_gpu_leases'] ?? 0) === 0);
    }));
    $candidates = $unpressured !== [] ? $unpressured : $eligible;
    usort($candidates, static function (array $left, array $right) use ($usesGpu): int {
        $comparisons = [
            [(int)($right['priority'] ?? 0), (int)($left['priority'] ?? 0)],
        ];
        if ($usesGpu) {
            $comparisons[] = [(int)($right['gpu_free_vram_mb'] ?? 0), (int)($left['gpu_free_vram_mb'] ?? 0)];
            $comparisons[] = [(int)($left['active_gpu_leases'] ?? 0), (int)($right['active_gpu_leases'] ?? 0)];
        }
        $comparisons[] = [(int)($left['queued_jobs'] ?? 0), (int)($right['queued_jobs'] ?? 0)];
        $comparisons[] = [(int)($left['id'] ?? 0), (int)($right['id'] ?? 0)];
        foreach ($comparisons as [$first, $second]) {
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

function hub_cluster_project_example_collections(array $service, bool $preserveVoiceWorkflowExamples = false): array
{
    $voiceWorkflowExamples = $preserveVoiceWorkflowExamples
        ? hub_public_api_voice_generate_examples(true)
        : null;
    $project = static function (mixed $value) use (&$project, $voiceWorkflowExamples): mixed {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/(?:^|_)examples\z/i', $key) === 1) {
                if (strcasecmp($key, 'workflow_examples') === 0
                    && $voiceWorkflowExamples !== null
                    && $item === $voiceWorkflowExamples) {
                    $value[$key] = $voiceWorkflowExamples;
                } else {
                    unset($value[$key]);
                }
                continue;
            }
            if (is_array($item)) {
                $value[$key] = $project($item);
            }
        }

        return $value;
    };

    return $project($service);
}

function hub_cluster_rewrite_contract_endpoint(
    array $service,
    string $stationApiBase,
    string $routerApiBase,
    bool $preserveVoiceWorkflowExamples = false
): array
{
    $service = hub_cluster_project_example_collections($service, $preserveVoiceWorkflowExamples);
    $stationApiBase = rtrim(trim($stationApiBase), '/');
    $routerApiBase = trim($routerApiBase);
    $stationParts = parse_url($stationApiBase);
    $stationApiPattern = null;
    if (is_array($stationParts) && isset($stationParts['scheme'], $stationParts['host'], $stationParts['path'])) {
        $host = trim((string)$stationParts['host'], '[]');
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $origin = (string)$stationParts['scheme'] . '://' . $authority . (isset($stationParts['port']) ? ':' . (int)$stationParts['port'] : '');
        $stationApiPattern = '~(?i:' . preg_quote($origin, '~') . ')' . preg_quote((string)$stationParts['path'], '~') . '~';
    }
    $followups = [
        'task_status' => 'cluster_task_status&task_id={task_id}',
        'task_result' => 'cluster_task_result&task_id={task_id}',
        'task_log' => 'cluster_task_log&task_id={task_id}',
        'task_cancel' => 'cluster_task_cancel&task_id={task_id}',
        'artifact' => 'cluster_artifact&task_id={task_id}&artifact_id={artifact_id}',
    ];
    $rewriteUrl = static function (string $value) use ($stationApiPattern, $routerApiBase, $followups): string {
        if ($stationApiPattern !== null) {
            $value = preg_replace_callback(
                $stationApiPattern,
                static fn (): string => $routerApiBase,
                $value
            ) ?? $value;
        }
        $value = (string)preg_replace('~(?<![A-Za-z0-9_])api\.php\?~', $routerApiBase . '?', $value);
        foreach ($followups as $nativeMode => $routerMode) {
            $pattern = '~([?&])(?:(?:task_id|artifact_id)=[^&\s\'\"]*&)*mode=' . preg_quote($nativeMode, '~') . '(?:&[^&\s\'\"]*)*~';
            $value = preg_replace_callback(
                $pattern,
                static fn (array $matches): string => $matches[1] . 'mode=' . $routerMode,
                $value
            ) ?? $value;
        }

        return $value;
    };
    $urlFields = [
        'endpoint' => true,
        'url' => true,
        'status_url' => true,
        'result_url' => true,
        'log_url' => true,
        'cancel_url' => true,
        'artifact_url' => true,
        'artifact_url_template' => true,
    ];
    $rewrite = static function (mixed $value, ?string $parent = null) use (&$rewrite, $rewriteUrl, $urlFields): mixed {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            if (is_string($item) && is_string($key)
                && (isset($urlFields[$key]) || in_array($parent, ['task_api', 'links'], true))) {
                $value[$key] = $rewriteUrl($item);
                continue;
            }
            if (is_array($item)) {
                $value[$key] = $rewrite($item, is_string($key) ? $key : $parent);
            }
        }

        return $value;
    };

    $service = $rewrite($service);
    $mode = trim((string)($service['mode'] ?? ''));
    if (preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) === 1) {
        $service['endpoint'] = $routerApiBase . '?mode=' . $mode;
        $service['url'] = $service['endpoint'];
        if (is_array($service['result_artifact_fields'] ?? null)
            && $service['result_artifact_fields'] !== []
        ) {
            if (hub_cluster_router_rich_artifact_mode($mode)) {
                $service['result_artifact_fields'] = ['id', 'type', 'mime_type', 'size_bytes', 'sha256'];
                $service['artifact_delivery_note'] = 'Choose id from result.artifacts[], expand the artifact_url_template returned by the submit response, and POST the same id through ack_url_template. Task and artifact access requires the submitting Bearer Token.';
            } else {
                $service['result_artifact_fields'] = ['id', 'size_bytes'];
                $service['artifact_delivery_note'] = 'Choose id from result.artifacts[] and expand the artifact_url_template returned by the submit response. Router results for this mode project only id and size_bytes. Task and artifact access requires the submitting Bearer Token.';
            }
        }
    }

    return $service;
}

function hub_cluster_voice_generate_relay_errors(): array
{
    return [
        'invalid_request' => ['public_code' => 'invalid_request', 'http_status' => 400, 'message' => 'request is invalid'],
        'voice_profile_wav_invalid' => ['public_code' => 'voice_profile_wav_invalid', 'http_status' => 400, 'message' => 'reference audio is invalid'],
        'voice_profile_transcript_invalid' => ['public_code' => 'voice_profile_transcript_invalid', 'http_status' => 400, 'message' => 'voice profile transcript is invalid'],
        'voice_profile_not_found' => ['public_code' => 'profile_task_not_found', 'http_status' => 404, 'message' => 'voice profile task was not found'],
        'voice_profile_transcript_unconfirmed' => ['public_code' => 'voice_profile_transcript_unconfirmed', 'http_status' => 409, 'message' => 'voice profile transcript is not confirmed'],
        'voice_profile_prepare_incomplete' => ['public_code' => 'voice_profile_prepare_incomplete', 'http_status' => 409, 'message' => 'voice profile preparation is incomplete'],
        'voice_profile_confirm_failed' => ['public_code' => 'voice_profile_confirm_failed', 'http_status' => 409, 'message' => 'voice profile confirmation failed'],
        'transcript_validation_failed' => ['public_code' => 'transcript_validation_failed', 'http_status' => 500, 'message' => 'voice transcript validation failed'],
        'voice_profile_unavailable' => ['public_code' => 'voice_profile_unavailable', 'http_status' => 410, 'message' => 'voice profile is unavailable'],
        'artifact_purged' => ['public_code' => 'artifact_purged', 'http_status' => 410, 'message' => 'artifact is no longer available'],
        'pack_runtime_not_ready' => ['public_code' => 'pack_runtime_not_ready', 'http_status' => 503, 'message' => 'voice generation runtime is not ready'],
        'voice_preset_required' => ['public_code' => 'voice_preset_required', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_not_found' => ['public_code' => 'voice_preset_not_found', 'http_status' => 404, 'message' => 'voice preset request is invalid'],
        'voice_preset_unavailable' => ['public_code' => 'voice_preset_unavailable', 'http_status' => 410, 'message' => 'voice preset request is invalid'],
        'voice_preset_scene_invalid' => ['public_code' => 'voice_preset_scene_invalid', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_candidate_count_invalid' => ['public_code' => 'voice_preset_candidate_count_invalid', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_candidate_count_unsupported' => ['public_code' => 'voice_preset_candidate_count_unsupported', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_forbidden_input' => ['public_code' => 'voice_preset_forbidden_input', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_invalid' => ['public_code' => 'voice_preset_invalid', 'http_status' => 400, 'message' => 'voice preset request is invalid'],
        'voice_preset_engine_incompatible' => ['public_code' => 'voice_preset_engine_incompatible', 'http_status' => 409, 'message' => 'voice preset request is invalid'],
        'generic_voice_invalid' => ['public_code' => 'generic_voice_invalid', 'http_status' => 400, 'message' => 'generic voice request is invalid'],
        'generic_voice_candidate_count_invalid' => ['public_code' => 'generic_voice_candidate_count_invalid', 'http_status' => 400, 'message' => 'generic voice request is invalid'],
        'generic_voice_forbidden_input' => ['public_code' => 'generic_voice_forbidden_input', 'http_status' => 400, 'message' => 'generic voice request is invalid'],
    ];
}

function hub_cluster_voice_preset_operation(?array $payload): ?string
{
    $operation = $payload['operation'] ?? null;

    return is_string($operation) && in_array($operation, [
        'voice_presets',
        'voice_preset_upsert',
        'voice_preset_anchor_upsert',
        'voice_preset_engine_bind',
        'voice_preset_delete',
        'preset_synthesize',
    ], true) ? $operation : null;
}

function hub_cluster_voice_preset_route_for_member(PDO $db, array $auth, string $presetId): ?array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    if ($memberId < 1 || hub_voice_preset_slug($presetId) === null) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT member_id, preset_id, station_id, preset_json FROM cluster_voice_preset_routes
         WHERE member_id = :member_id AND preset_id = :preset_id LIMIT 1'
    );
    $stmt->execute([':member_id' => $memberId, ':preset_id' => $presetId]);
    $route = $stmt->fetch();

    return $route === false ? null : $route;
}

function hub_cluster_voice_preset_list(PDO $db, array $auth): array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    if ($memberId < 1) {
        return ['ok' => true, 'voice_presets' => []];
    }
    $stmt = $db->prepare(
        'SELECT preset_json FROM cluster_voice_preset_routes
         WHERE member_id = :member_id ORDER BY preset_id ASC'
    );
    $stmt->execute([':member_id' => $memberId]);
    $presets = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $preset = hub_voice_preset_public_value(json_decode((string)$json, true));
        if ($preset === null) {
            throw new RuntimeException('cluster_voice_preset_invalid');
        }
        $presets[] = $preset;
    }

    return ['ok' => true, 'voice_presets' => $presets];
}

function hub_cluster_voice_preset_store(PDO $db, array $auth, int $stationId, mixed $value): array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    $preset = hub_voice_preset_public_value($value);
    if ($memberId < 1 || $stationId < 1 || $preset === null) {
        throw new RuntimeException('cluster_voice_preset_invalid');
    }
    $now = hub_now();
    $db->prepare(
        'INSERT INTO cluster_voice_preset_routes
            (member_id, preset_id, station_id, preset_json, created_at, updated_at)
         VALUES
            (:member_id, :preset_id, :station_id, :preset_json, :created_at, :updated_at)
         ON CONFLICT(member_id, preset_id) DO UPDATE SET
            station_id = excluded.station_id, preset_json = excluded.preset_json, updated_at = excluded.updated_at'
    )->execute([
        ':member_id' => $memberId,
        ':preset_id' => $preset['id'],
        ':station_id' => $stationId,
        ':preset_json' => json_encode($preset, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return $preset;
}

/**
 * 子節點可在回應中保留自己的私有執行資訊；Router 只接受並持久化固定的公開 preset 欄位。
 */
function hub_cluster_voice_preset_public_child_value(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $publicFields = ['id', 'label', 'gender', 'age_bucket', 'purposes', 'scenes', 'preset_revision'];
    $public = [];
    foreach ($publicFields as $field) {
        if (!array_key_exists($field, $value)) {
            return null;
        }
        $public[$field] = $value[$field];
    }

    return hub_voice_preset_public_value($public);
}

function hub_cluster_voice_preset_delete(PDO $db, array $auth, string $presetId): void
{
    $memberId = (int)($auth['member_id'] ?? 0);
    if ($memberId < 1 || hub_voice_preset_slug($presetId) === null) {
        throw new RuntimeException('cluster_voice_preset_invalid');
    }
    $stmt = $db->prepare('DELETE FROM cluster_voice_preset_routes WHERE member_id = :member_id AND preset_id = :preset_id');
    $stmt->execute([':member_id' => $memberId, ':preset_id' => $presetId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('cluster_voice_preset_missing');
    }
}

function hub_cluster_voice_generate_error_table(): array
{
    $errors = [];
    foreach (hub_cluster_voice_generate_relay_errors() as $rule) {
        $errors[(string)$rule['public_code']] = [
            'code' => (string)$rule['public_code'],
            'http_status' => (int)$rule['http_status'],
        ];
    }
    $errors['voice_profile_changed'] = ['code' => 'voice_profile_changed', 'task_status' => 'failed'];
    $errors['voice_profile_unavailable']['task_status'] = 'failed';
    $errors['station_unavailable'] = ['code' => 'station_unavailable', 'http_status' => 503];

    return array_values($errors);
}

function hub_cluster_voice_generate_relay_response(array $response, mixed $payload): ?array
{
    if (!is_array($payload)
        || ($payload['ok'] ?? null) !== false
        || !is_string($payload['error'] ?? null)
        || !is_string($payload['message'] ?? null)
    ) {
        return null;
    }
    $allowedKeys = ['ok', 'error', 'message', 'request_id'];
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, $allowedKeys, true)) {
            return null;
        }
    }
    if (array_key_exists('request_id', $payload)
        && (!is_string($payload['request_id']) || strlen($payload['request_id']) > 128)) {
        return null;
    }
    $rule = hub_cluster_voice_generate_relay_errors()[$payload['error']] ?? null;
    if ($rule === null || (int)($response['status'] ?? 0) !== (int)$rule['http_status']) {
        return null;
    }

    return hub_gateway_error(
        (int)$rule['http_status'],
        (string)$rule['public_code'],
        (string)$rule['message']
    );
}

function hub_cluster_pack_validation_error_response(array $response, mixed $payload): ?array
{
    if ((int)($response['status'] ?? 0) !== 400
        || !is_array($payload)
        || ($payload['ok'] ?? null) !== false
        || ($payload['error'] ?? null) !== 'invalid_request'
        || !is_string($payload['message'] ?? null)
        || !is_array($payload['field_errors'] ?? null)
        || count($payload['field_errors']) !== 1
    ) {
        return null;
    }
    foreach (array_keys($payload) as $key) {
        if (!is_string($key) || !in_array($key, ['ok', 'error', 'message', 'field_errors', 'request_id'], true)) {
            return null;
        }
    }
    if (isset($payload['request_id']) && (!is_string($payload['request_id']) || strlen($payload['request_id']) > 128)) {
        return null;
    }
    $field = array_key_first($payload['field_errors']);
    $reason = $field === null ? null : $payload['field_errors'][$field];
    if (!is_string($field) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $field) !== 1
        || !is_string($reason)
        || preg_match('/\A(?:is required|is invalid|requires [a-z][a-z0-9_]{0,63}(?:!?=(?:true|false|-?\d+|[a-zA-Z0-9_-]{1,64}))?|must be greater than(?: or equal to)? [a-z][a-z0-9_]{0,63})\z/D', $reason) !== 1
        || $payload['message'] !== $field . ' ' . $reason
    ) {
        return null;
    }

    return hub_gateway_json(400, [
        'ok' => false,
        'error' => 'invalid_request',
        'message' => $field . ' ' . $reason,
        'field_errors' => [$field => $reason],
    ]);
}

function hub_cluster_rewrite_voice_generate_contract(array $service, string $mode = 'voice_generate'): array
{
    if (!hub_is_voice_profile_mode($mode)) {
        throw new InvalidArgumentException('unsupported voice profile mode');
    }
    unset($service['examples']);
    $forbiddenIdentifierPattern = '/(?<![A-Za-z0-9_])voice_profile_id(?![A-Za-z0-9_])/i';
    $containsForbiddenIdentifier = static fn (mixed $value): bool => is_string($value)
        && preg_match($forbiddenIdentifierPattern, $value) === 1;
    $unsafeExample = static function (mixed $value) use (&$unsafeExample, $containsForbiddenIdentifier): bool {
        if (is_string($value)) {
            return $containsForbiddenIdentifier($value);
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($unsafeExample($item)) {
                    return true;
                }
            }
        }

        return false;
    };
    $exampleFields = ['example', 'examples', 'code', 'curl', 'php', 'js_fetch'];
    $project = static function (mixed $value) use (
        &$project,
        $containsForbiddenIdentifier,
        $forbiddenIdentifierPattern,
        $unsafeExample,
        $exampleFields
    ): array {
        if (is_array($value)) {
            if ($containsForbiddenIdentifier($value['name'] ?? null)) {
                return [false, null];
            }
            $list = array_is_list($value);
            $safe = [];
            foreach ($value as $key => $item) {
                if (!$list && $containsForbiddenIdentifier($key)) {
                    continue;
                }
                if (!$list && is_string($key)
                    && in_array(strtolower($key), $exampleFields, true)
                    && $unsafeExample($item)) {
                    continue;
                }
                [$keep, $item] = $project($item);
                if (!$keep) {
                    continue;
                }
                if ($list) {
                    $safe[] = $item;
                } else {
                    $safe[$key] = $item;
                }
            }
            if (isset($safe['name']) && is_string($safe['name'])
                && strcasecmp($safe['name'], 'voice_profile_task_id') === 0) {
                $safe['type'] = 'string';
                $safe['max_length'] = 64;
            }

            return [true, $safe];
        }
        if (is_string($value)) {
            if (strcasecmp($value, 'voice_profile_id') === 0) {
                return [false, null];
            }

            return [
                true,
                preg_replace($forbiddenIdentifierPattern, 'voice_profile_task_id', $value) ?? $value,
            ];
        }

        return [true, $value];
    };
    [, $service] = $project($service);
    $service['error_table'] = hub_cluster_voice_generate_error_table();
    $service['error_codes'] = array_column($service['error_table'], 'code');
    $service['workflow'] = [
        'client_state' => 'MyAI stores only voice_profile_task_id returned by profile_prepare.',
        'profile_affinity' => 'Profile followups and clone synthesis stay on the pinned station; there is no failover.',
        'preset_affinity' => 'Managed preset discovery uses Router-owned safe catalog metadata. A bound preset and its candidate synthesis stay on the pinned station; there is no failover.',
        'profile_ownership' => 'After profile_prepare succeeds, the Profile handle belongs to the API member and may be used by any currently valid Token for that member with ' . $mode . ' permission. Task and artifact followups remain bound to the submitting Token.',
        'operation_default' => 'Omitting operation means synthesize.',
        'spoken_text_boundary' => hub_public_api_voice_generate_spoken_text_boundary(),
        'profile_status_visibility' => 'For the authenticated Profile member, profile_status may include the unconfirmed ASR draft and transcript validation (raw/normalized); the confirmed transcript is omitted.',
        'transcript_validation' => 'profile_prepare accepts optional expected_text. Whisper raw text is preserved as transcript.raw; normalization uses OpenCC s2twp and CER is Unicode-character Levenshtein distance divided by normalized expected character count. profile_prepare never confirms a profile; call profile_confirm with the human-reviewed text.',
        'profile_confirmation_proof' => 'profile_confirm returns the caller voice_profile_task_id handle (opaque through Cluster) and lowercase SHA-256 prompt_text_sha256 computed from the authoritative stored exact UTF-8 bytes; confirmed prompt_text is omitted.',
        'steps' => [
            'profile_prepare',
            'cluster_task_status via returned status_url',
            'profile_status',
            'profile_confirm',
            'synthesize with mode=ultimate_clone',
            'cluster_task_result via returned result_url',
            'expand returned artifact_url_template with result.artifacts[].id, then ACK via ack_url_template when present',
            'profile_delete',
        ],
    ];
    $service['workflow_examples'] = hub_public_api_voice_generate_examples(true, $mode, $mode === 'voice_generate');

    return $service;
}

function hub_cluster_public_manifest(PDO $db): array
{
    $inventories = [];
    $contracts = [];
    $stations = [];
    foreach (hub_cluster_list_stations($db) as $station) {
        $inventory = hub_cluster_station_inventory($station);
        if (empty($inventory['enabled']) || empty($inventory['fresh'])) {
            continue;
        }
        $snapshot = json_decode((string)($station['manifest_json'] ?? ''), true);
        $services = is_array($snapshot['services'] ?? null) ? $snapshot['services'] : [];
        foreach ($services as $service) {
            $mode = is_array($service) ? trim((string)($service['mode'] ?? '')) : '';
            if (preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) !== 1) {
                continue;
            }
            $contracts[(int)$station['id']][$mode] = $service;
        }
        $inventory['modes'] = array_values(array_filter(
            $inventory['modes'],
            static fn (mixed $mode): bool => is_string($mode) && isset($contracts[(int)$station['id']][$mode])
        ));
        if ($inventory['modes'] === []) {
            continue;
        }
        $inventories[] = $inventory;
        $stations[(int)$station['id']] = $station;
    }

    $modes = [];
    foreach ($inventories as $inventory) {
        foreach ($inventory['modes'] as $mode) {
            $modes[$mode] = true;
        }
    }
    ksort($modes, SORT_STRING);
    $services = [];
    foreach (array_keys($modes) as $mode) {
        $selected = hub_cluster_select_station($mode, $inventories);
        $stationId = (int)($selected['id'] ?? 0);
        if ($selected === null || !isset($stations[$stationId], $contracts[$stationId][$mode])) {
            continue;
        }
        $station = $stations[$stationId];
        $service = $contracts[$stationId][$mode];
        if ($mode === 'manual_vision') {
            unset($service['gpu_required']);
        }
        if (hub_is_voice_profile_mode($mode)) {
            $service = hub_cluster_rewrite_voice_generate_contract($service, $mode);
        }
        $service = hub_cluster_project_example_collections($service, $mode === 'voice_generate');
        foreach (['public_base_url', 'internal_base_url'] as $field) {
            $base = trim((string)($station[$field] ?? ''));
            if ($base === '') {
                continue;
            }
            try {
                $service = hub_cluster_rewrite_contract_endpoint(
                    $service,
                    hub_cluster_validate_station_base_url($base) . 'api.php',
                    hub_cluster_router_api_base_url(),
                    $mode === 'voice_generate'
                );
            } catch (Throwable) {
                continue;
            }
        }
        if (hub_is_voice_profile_mode($mode)) {
            $service['workflow_examples'] = hub_public_api_voice_generate_examples(true, $mode, $mode === 'voice_generate');
        }
        if (is_string($service['url'] ?? null)
            && is_string($service['method'] ?? null)
            && is_string($service['content_type'] ?? null)
            && is_array($service['input_fields'] ?? null)
        ) {
            $service['examples'] = hub_public_api_examples($service);
        }
        $services[] = $service;
    }

    return [
        'base_endpoint' => hub_cluster_router_api_base_url(),
        'auth' => ['type' => 'bearer', 'header' => 'Authorization: Bearer <TOKEN>'],
        'generated_at' => hub_now(),
        'inventory_note' => 'Router inventory refresh may temporarily remove unavailable modes.',
        'production_audio_modes' => ['audio_cleanup', 'speech_transcribe', 'speech_transcribe_fast_zh', 'voice_generate'],
        'async_task_contract' => [
            'task_id' => 'Router task_id is an opaque string; store it exactly and never cast to integer.',
            'native_difference' => 'Native api.php task_id is numeric and belongs to a different namespace.',
            'flow' => ['submit', 'status', 'result', 'artifact', 'ACK'],
            'status_fields' => ['status', 'progress', 'message'],
        ],
        'services' => $services,
    ];
}

function hub_cluster_router_available_modes(PDO $db): array
{
    if (!hub_cluster_router_enabled($db)) {
        return [];
    }
    $modes = [];
    foreach (hub_cluster_public_manifest($db)['services'] as $service) {
        $mode = is_array($service) ? trim((string)($service['mode'] ?? '')) : '';
        if (preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $mode) === 1) {
            $modes[$mode] = true;
        }
    }
    ksort($modes, SORT_STRING);

    return array_keys($modes);
}

function hub_cluster_service_health_payload(PDO $db, array $requestedModes): array
{
    $contracts = hub_service_health_contracts();
    $available = array_fill_keys($requestedModes, false);
    $latestCheckedAt = 0;
    foreach (hub_cluster_list_stations($db) as $station) {
        $inventory = hub_cluster_station_inventory($station);
        if (empty($inventory['enabled']) || empty($inventory['fresh'])) {
            continue;
        }
        $checkedAt = strtotime((string)($station['status_fetched_at'] ?? ''));
        if ($checkedAt !== false) {
            $latestCheckedAt = max($latestCheckedAt, $checkedAt);
        }
        $modes = is_array($inventory['modes'] ?? null) ? $inventory['modes'] : [];
        foreach ($requestedModes as $mode) {
            if ($mode === 'photo') {
                $available[$mode] = $available[$mode] || hub_cluster_photo_modes_are_paired($modes);
                continue;
            }
            $available[$mode] = $available[$mode] || in_array($mode, $modes, true);
        }
    }
    $services = [];
    foreach ($requestedModes as $mode) {
        $ready = !empty($available[$mode]);
        $services[$mode] = [
            'ready' => $ready,
            'runtime_status' => $ready ? 'running' : 'stopped',
            'reason' => $ready ? '' : 'runtime_not_ready',
            'model' => $contracts[$mode]['model'],
        ];
    }

    return [
        'ok' => true,
        'checked_at' => date(DATE_ATOM, $latestCheckedAt > 0 ? $latestCheckedAt : time()),
        'services' => $services,
    ];
}

function hub_cluster_public_docs_example(string $value, string $routerUrl): string
{
    $routerUrl = trim($routerUrl);
    $value = str_replace('<ROUTER_BASE_URL>/cluster_api.php', $routerUrl, $value);

    return preg_replace_callback(
        '~(?<![A-Za-z0-9_./-])cluster_api\.php(?=\?)~',
        static fn (): string => $routerUrl,
        $value
    ) ?? $value;
}

function hub_cluster_public_api_docs_html(PDO $db): string
{
    $manifest = hub_cluster_public_manifest($db);
    $services = is_array($manifest['services'] ?? null) ? $manifest['services'] : [];
    $apiUrl = hub_public_api_base_url();
    $routerUrl = preg_replace('~api\.php\z~', 'cluster_api.php', $apiUrl) ?: 'cluster_api.php';
    $serviceHealth = hub_public_api_service_health_docs($apiUrl, $routerUrl);
    $example = static fn (string $value): string => hub_cluster_public_docs_example($value, $routerUrl);
    $json = static fn (mixed $value): string => (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ob_start();
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>3waAIHub Cluster API</title>
    <style>
        :root { color-scheme: light; --bg: #f6f7f9; --panel: #fff; --ink: #172033; --muted: #667085; --line: #d9dee7; --blue: #1769e0; --blue-soft: #eaf2ff; --green: #067647; --green-soft: #dcfae6; --code: #101828; --code-text: #f2f4f7; }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--ink); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; line-height: 1.55; margin: 0; }
        main { margin: 0 auto; max-width: 1120px; padding: 42px 20px 64px; }
        h1, h2, h3, p { margin-top: 0; }
        h1 { font-size: clamp(32px, 4vw, 52px); line-height: 1.1; margin-bottom: 12px; }
        h2 { font-size: 22px; line-height: 1.25; }
        h3 { font-size: 14px; margin-bottom: 8px; }
        .portal-hero { border-bottom: 1px solid var(--line); padding-bottom: 32px; }
        .eyebrow { color: var(--blue); font-size: 13px; font-weight: 700; margin-bottom: 10px; }
        .lede { color: var(--muted); font-size: 18px; max-width: 700px; }
        .endpoint-block { margin-top: 24px; max-width: 880px; }
        .endpoint-label, .auth-label { color: var(--muted); display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
        .endpoint-row, .code-heading { align-items: center; display: flex; gap: 8px; }
        .endpoint-row { background: var(--code); border-radius: 8px; padding: 10px 12px; }
        .endpoint-row code { color: var(--code-text); flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .auth-line { margin: 12px 0 0; }
        code { font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace; }
        .auth-line code, .meta-value code, .mode-directory code { background: var(--blue-soft); color: #124b9f; padding: 2px 5px; }
        .copy-button { background: var(--panel); border: 1px solid transparent; border-radius: 6px; color: var(--ink); cursor: pointer; flex: 0 0 auto; font: inherit; font-size: 13px; min-height: 34px; padding: 6px 10px; }
        .copy-button:hover, .copy-button:focus-visible { border-color: var(--blue); color: var(--blue); outline: none; }
        .catalog-summary { border-block: 1px solid var(--line); display: grid; gap: 16px; grid-template-columns: repeat(3, minmax(0, 1fr)); margin: 30px 0 20px; padding: 18px 0; }
        .summary-label { color: var(--muted); display: block; font-size: 13px; margin-bottom: 2px; }
        .summary-value { font-size: 20px; font-weight: 700; overflow-wrap: anywhere; }
        .live { color: var(--green); }
        .mode-directory { display: flex; flex-wrap: wrap; gap: 8px; margin: 18px 0 32px; }
        .mode-directory a { border: 1px solid var(--line); color: var(--ink); padding: 7px 10px; text-decoration: none; }
        .mode-directory a:hover, .mode-directory a:focus-visible { border-color: var(--blue); color: var(--blue); outline: none; }
        .mode-directory code { background: transparent; color: inherit; padding: 0; }
        .section-title { align-items: baseline; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 14px; }
        .section-title p { color: var(--muted); font-size: 14px; margin-bottom: 0; }
        .service-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .service-card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; min-width: 0; padding: 18px; scroll-margin-top: 16px; }
        .service-card header { border-bottom: 1px solid var(--line); margin-bottom: 16px; padding-bottom: 12px; }
        .service-card h2 { margin-bottom: 6px; overflow-wrap: anywhere; }
        .service-card header p { color: var(--muted); font-size: 14px; margin-bottom: 0; }
        .meta-grid { display: grid; gap: 10px 14px; grid-template-columns: max-content max-content; margin-bottom: 18px; }
        .meta-label { color: var(--muted); display: block; font-size: 12px; margin-bottom: 2px; }
        .meta-value { font-weight: 700; overflow-wrap: anywhere; }
        .contract-block { margin-top: 18px; }
        .code-heading { justify-content: space-between; }
        .code-heading h3 { margin-bottom: 8px; }
        pre { background: var(--code); color: var(--code-text); font: 13px/1.55 "SFMono-Regular", Consolas, "Liberation Mono", monospace; margin: 0; overflow: auto; padding: 12px; white-space: pre-wrap; word-break: break-word; }
        .empty-state { background: var(--panel); border: 1px dashed var(--line); color: var(--muted); padding: 24px; text-align: center; }
        .empty-state h2 { color: var(--ink); }
        @media (max-width: 680px) { main { padding: 30px 16px 44px; } .catalog-summary, .meta-grid { grid-template-columns: 1fr; } .endpoint-row { align-items: stretch; flex-direction: column; } .copy-button { width: 100%; } .section-title { align-items: flex-start; flex-direction: column; gap: 4px; } .service-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header class="portal-hero">
        <p class="eyebrow">3waAIHub / Unified entry</p>
        <h1>Cluster API</h1>
        <p class="lede">單一穩定入口，提供 Router 目前可用的 AI 服務。</p>
        <div class="endpoint-block">
            <span class="endpoint-label">Router endpoint</span>
            <div class="endpoint-row"><code><?= hub_h($routerUrl) ?></code><button class="copy-button" type="button" data-copy="<?= hub_h($routerUrl) ?>" aria-label="Copy Router endpoint" title="Copy Router endpoint">Copy</button></div>
        </div>
        <p class="auth-line"><span class="auth-label">Authentication</span><code>Authorization: Bearer &lt;TOKEN&gt;</code></p>
    </header>

    <section class="catalog-summary" aria-label="Live Router catalog">
        <div><span class="summary-label">Available modes</span><strong class="summary-value"><?= count($services) ?></strong></div>
        <div><span class="summary-label">Catalog status</span><strong class="summary-value live">Live catalog</strong></div>
        <div><span class="summary-label">Updated</span><strong class="summary-value"><?= hub_h((string)$manifest['generated_at']) ?></strong></div>
    </section>

    <section class="contract-block" aria-labelledby="production-audio-modes">
        <h2 id="production-audio-modes">Production audio modes</h2>
        <pre><?= hub_h($json($manifest['production_audio_modes'] ?? [])) ?></pre>
        <p>Live availability still comes from the fresh services listed below.</p>
    </section>

    <section class="contract-block" aria-labelledby="async-task-contract">
        <h2 id="async-task-contract">Async task, result, artifact, and ACK contract</h2>
        <pre><?= hub_h($json($manifest['async_task_contract'] ?? [])) ?></pre>
    </section>

    <section class="contract-block" aria-labelledby="service-health-contract">
        <h2 id="service-health-contract">Service health</h2>
        <p>Read the Router's cached node eligibility before sending BioCLIP or Gemma Photo work. The query never fans out to child stations.</p>
        <div class="contract-block"><h3>curl</h3><pre><?= hub_h((string)$serviceHealth['cluster_curl']) ?></pre></div>
        <div class="contract-block"><h3>Response example</h3><pre><?= hub_h((string)$serviceHealth['response']) ?></pre></div>
        <div class="contract-block"><h3>Authorization and unavailable reasons</h3><pre><?= hub_h('service_health + requested mode permissions; ' . implode(', ', (array)$serviceHealth['reasons'])) ?></pre></div>
    </section>

    <?php if ($services === []): ?>
        <section class="empty-state"><h2>No Router modes are currently available.</h2><p>Retry shortly or contact the Router administrator.</p></section>
    <?php else: ?>
        <nav class="mode-directory" aria-label="Available Router modes">
            <?php foreach ($services as $service): ?><a href="#mode-<?= hub_h((string)$service['mode']) ?>"><code><?= hub_h((string)$service['mode']) ?></code></a><?php endforeach; ?>
        </nav>
        <div class="section-title"><h2>Available modes</h2><p><?= hub_h((string)$manifest['inventory_note']) ?></p></div>
        <section class="service-grid">
            <?php foreach ($services as $service): ?>
                <?php
                $mode = (string)($service['mode'] ?? '');
                $examples = is_array($service['examples'] ?? null) ? $service['examples'] : [];
                $curl = $example((string)($examples['curl'] ?? ''));
                $php = $example((string)($examples['php'] ?? ''));
                $js = $example((string)($examples['js_fetch'] ?? ''));
                ?>
                <article class="service-card" id="mode-<?= hub_h($mode) ?>">
                    <header><h2><code><?= hub_h($mode) ?></code></h2><p><?= hub_h((string)($service['name'] ?? '')) ?><?= ($service['description'] ?? '') !== '' ? ' - ' . hub_h((string)$service['description']) : '' ?></p></header>
                    <div class="meta-grid">
                        <div><span class="meta-label">Method</span><span class="meta-value"><code><?= hub_h((string)($service['method'] ?? '')) ?></code></span></div>
                        <div><span class="meta-label">Content type</span><span class="meta-value"><code><?= hub_h((string)($service['content_type'] ?? '')) ?></code></span></div>
                    </div>
                    <div class="contract-block"><h3>Request fields</h3><pre><?= hub_h($json($service['input_fields'] ?? [])) ?></pre></div>
                    <div class="contract-block"><h3>Response keys</h3><pre><?= hub_h($json($service['output_keys'] ?? [])) ?></pre></div>
                    <?php if (($service['result_artifact_fields'] ?? []) !== []): ?>
                        <div class="contract-block"><h3>Artifact delivery</h3><pre><?= hub_h($json([
                            'result.artifacts[]' => $service['result_artifact_fields'],
                            'note' => $service['artifact_delivery_note'] ?? '',
                        ])) ?></pre></div>
                    <?php endif; ?>
                    <?php if (($service['operations'] ?? []) !== []): ?>
                        <div class="contract-block"><h3>Additional operations</h3><pre><?= hub_h($json($service['operations'])) ?></pre></div>
                    <?php endif; ?>
                    <?php if (($service['workflow'] ?? []) !== []): ?>
                        <div class="contract-block"><h3>Workflow</h3><pre><?= hub_h($json($service['workflow'])) ?></pre></div>
                    <?php endif; ?>
                    <?php if (($service['generic_voice_exploration'] ?? []) !== []): ?>
                        <div class="contract-block"><h3>Generic voice exploration</h3><pre><?= hub_h($json($service['generic_voice_exploration'])) ?></pre></div>
                    <?php endif; ?>
                    <div class="contract-block"><h3>Error codes</h3><pre><?= hub_h($json($service['error_codes'] ?? [])) ?></pre></div>
                    <?php if (($service['error_table'] ?? []) !== []): ?>
                        <div class="contract-block"><h3>Error status table</h3><pre><?= hub_h($json($service['error_table'])) ?></pre></div>
                    <?php endif; ?>
                    <div class="contract-block"><div class="code-heading"><h3>curl</h3><button class="copy-button" type="button" data-copy="<?= hub_h($curl) ?>" aria-label="Copy curl example" title="Copy curl example">Copy</button></div><pre><?= hub_h($curl) ?></pre></div>
                    <div class="contract-block"><div class="code-heading"><h3>PHP</h3><button class="copy-button" type="button" data-copy="<?= hub_h($php) ?>" aria-label="Copy PHP example" title="Copy PHP example">Copy</button></div><pre><?= hub_h($php) ?></pre></div>
                    <div class="contract-block"><div class="code-heading"><h3>JavaScript</h3><button class="copy-button" type="button" data-copy="<?= hub_h($js) ?>" aria-label="Copy JavaScript example" title="Copy JavaScript example">Copy</button></div><pre><?= hub_h($js) ?></pre></div>
                    <?php if (($service['workflow_examples'] ?? []) !== []): ?>
                        <?php foreach (['curl' => 'Workflow curl', 'php' => 'Workflow PHP', 'js_fetch' => 'Workflow JavaScript'] as $exampleKey => $label): ?>
                            <?php $workflowExample = $example((string)$service['workflow_examples'][$exampleKey]); ?>
                            <div class="contract-block"><div class="code-heading"><h3><?= hub_h($label) ?></h3><button class="copy-button" type="button" data-copy="<?= hub_h($workflowExample) ?>" aria-label="Copy <?= hub_h($label) ?> example" title="Copy <?= hub_h($label) ?> example">Copy</button></div><pre><?= hub_h($workflowExample) ?></pre></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copy || '');
            const label = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(() => { button.textContent = label; }, 1200);
        } catch (_) {}
    });
});
</script>
</body>
</html>
<?php
    return (string)ob_get_clean();
}

function hub_cluster_router_photo_followup_request(PDO $db, array $normalized, array $authContext): array
{
    if (($normalized['method'] ?? null) !== 'POST') {
        return ['response' => hub_gateway_error(405, 'method_not_allowed', 'photo requires POST')];
    }
    try {
        $payload = json_decode((string)($normalized['raw_body'] ?? ''), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'JSON body is required')];
    }
    if (!is_array($payload)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'JSON body is required')];
    }
    $imageId = trim((string)($payload['image_id'] ?? ''));
    if ($imageId === '') {
        return ['response' => hub_gateway_error(400, 'image_id_required', 'image_id is required')];
    }
    $asset = hub_cluster_photo_asset_for_auth($db, $imageId, $authContext);
    if ($asset === null) {
        return ['response' => hub_gateway_error(404, 'image_not_found', 'image was not found or is not available')];
    }
    $payload['image_id'] = (string)$asset['remote_image_id'];
    $normalized['raw_body'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return ['asset' => $asset, 'normalized' => $normalized];
}

function hub_cluster_router_photo_upload_payload(PDO $db, array $payload, int $stationId, array $authContext): array
{
    $remoteImageId = is_scalar($payload['image_id'] ?? null) ? trim((string)$payload['image_id']) : '';
    $expiresAt = is_scalar($payload['expires_at'] ?? null) ? trim((string)$payload['expires_at']) : '';
    $asset = hub_cluster_photo_asset_store($db, $stationId, $authContext, $remoteImageId, $expiresAt);
    $payload['image_id'] = $asset['image_id'];

    return $payload;
}

function hub_cluster_dispatch(PDO $db, string $mode, array $request = [], array $seams = []): array
{
    $requestId = hub_new_request_id();
    $started = microtime(true);
    $finish = static fn (array $response): array => hub_cluster_router_finish_response($response, $requestId);
    if (!hub_cluster_router_enabled($db)) {
        return $finish(hub_gateway_error(404, 'router_disabled', 'cluster router is disabled'));
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        return $finish(hub_gateway_error(400, 'bad_request', 'invalid mode'));
    }
    if ($mode === 'yolo_gpu_internal') {
        return $finish(hub_gateway_error(404, 'unknown_mode', 'mode is not registered'));
    }

    $clientIp = trim((string)($request['client_ip'] ?? hub_get_client_ip())) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $request) ? (string)$request['bearer_token'] : hub_bearer_token_from_request();
    if ($mode === 'service_health') {
        $requestMethod = strtoupper(trim((string)($request['method'] ?? 'GET'))) ?: 'GET';
        if ($requestMethod !== 'GET') {
            return $finish(hub_gateway_error(405, 'method_not_allowed', 'service health requires GET'));
        }
        $query = is_array($request['query'] ?? null) ? $request['query'] : [];
        $requestedModes = hub_service_health_requested_modes($query['services'] ?? null);
        if ($requestedModes === null) {
            return $finish(hub_gateway_error(400, 'bad_request', 'services must be a supported comma-separated list'));
        }
        $auth = hub_service_health_authenticate($db, $clientIp, $providedToken, $requestedModes);
        if (empty($auth['ok'])) {
            return $finish($auth['response']);
        }

        return $finish(hub_gateway_json(200, hub_cluster_service_health_payload($db, $requestedModes)));
    }
    $auth = hub_authenticate_api_token($db, $clientIp, $providedToken, $mode);
    if (empty($auth['ok'])) {
        return $finish($auth['response']);
    }

    $normalized = hub_cluster_router_normalize_request($mode, $request);
    if (isset($normalized['response'])) {
        return $finish($normalized['response']);
    }
    $normalized['client_ip'] = $clientIp;
    $normalized['bearer_token'] = $providedToken;

    $photoAsset = null;
    if ($mode === 'photo') {
        $photoRequest = hub_cluster_router_photo_followup_request($db, $normalized, (array)$auth['context']);
        if (isset($photoRequest['response'])) {
            return $finish($photoRequest['response']);
        }
        $photoAsset = $photoRequest['asset'];
        $normalized = $photoRequest['normalized'];
    }

    $profileRoute = null;
    $profilePayload = null;
    try {
        $profileReference = hub_cluster_voice_profile_reference($normalized);
    } catch (Throwable) {
        return $finish(hub_gateway_error(400, 'invalid_request', 'invalid request'));
    }
    if ($profileReference !== null) {
        $profileRoute = hub_cluster_get_route_for_customer($db, $profileReference, (array)$auth['context']);
        if ($profileRoute === null && hub_is_voice_profile_mode($mode)) {
            $profileRoute = hub_cluster_get_voice_profile_route_for_member($db, $profileReference, (array)$auth['context'], $mode);
        }
        $remoteTaskId = $profileRoute['remote_task_id'] ?? null;
        if ($profileRoute === null
            || !hub_is_voice_profile_mode($mode)
            || (string)($profileRoute['mode'] ?? '') !== $mode
            || (int)($profileRoute['station_id'] ?? 0) < 1
            || (!is_int($remoteTaskId) && !is_string($remoteTaskId))
            || preg_match('/\A[1-9][0-9]{0,17}\z/', (string)$remoteTaskId) !== 1
        ) {
            return $finish(hub_gateway_error(404, 'profile_task_not_found', 'voice profile task was not found'));
        }
        try {
            $normalized = hub_cluster_replace_voice_profile_reference($normalized, (string)$remoteTaskId);
        } catch (Throwable) {
            return $finish(hub_gateway_error(400, 'invalid_request', 'invalid request'));
        }
    }
    if (hub_is_voice_profile_mode($mode)) {
        try {
            $profilePayload = hub_cluster_router_voice_profile_payload($normalized);
            if (($normalized['method'] ?? '') === 'GET') {
                unset($profilePayload['mode']);
            }
        } catch (Throwable) {
            return $finish(hub_gateway_error(400, 'invalid_request', 'invalid request'));
        }
    }
    $presetOperation = hub_cluster_voice_preset_operation($profilePayload);
    if ($presetOperation === 'voice_presets') {
        if (($normalized['method'] ?? '') !== 'GET' || array_keys((array)$profilePayload) !== ['operation']) {
            return $finish(hub_gateway_error(400, 'voice_preset_invalid', 'voice preset request is invalid'));
        }
        try {
            return $finish(hub_gateway_json(200, hub_cluster_voice_preset_list($db, (array)$auth['context'])));
        } catch (Throwable) {
            return $finish(hub_gateway_error(502, 'router_response_invalid', 'cluster preset catalog is invalid'));
        }
    }
    $profileOperation = is_array($profilePayload) && is_string($profilePayload['operation'] ?? null)
        ? $profilePayload['operation']
        : null;
    $isImplicitProfileSynthesis = is_array($profilePayload)
        && !array_key_exists('operation', $profilePayload)
        && $profileRoute !== null
        && is_string($profilePayload['mode'] ?? null)
        && in_array($profilePayload['mode'], ['clone', 'ultimate_clone'], true);
    $profileResponseOperation = $isImplicitProfileSynthesis ? 'synthesize' : $profileOperation;
    $isProfileRequest = hub_is_voice_profile_mode($mode)
        && ($profileRoute !== null || in_array($profileOperation, ['profile_prepare', 'profile_status', 'profile_confirm', 'profile_delete', 'synthesize'], true));
    $profileSensitive = hub_is_voice_profile_mode($mode)
        && ($profileRoute !== null || $profileOperation === 'profile_prepare');
    $presetRoute = null;
    if (in_array($presetOperation, ['voice_preset_anchor_upsert', 'voice_preset_engine_bind', 'voice_preset_delete', 'preset_synthesize'], true)) {
        $presetId = hub_voice_preset_slug($profilePayload['voice_preset'] ?? null);
        if ($presetId === null) {
            return $finish(hub_gateway_error(400, 'voice_preset_required', 'voice preset request is invalid'));
        }
        $presetRoute = hub_cluster_voice_preset_route_for_member($db, (array)$auth['context'], $presetId);
        if ($presetRoute === null) {
            return $finish(hub_gateway_error(404, 'voice_preset_not_found', 'voice preset was not found'));
        }
        if ($profileRoute !== null && (int)$profileRoute['station_id'] !== (int)$presetRoute['station_id']) {
            return $finish(hub_gateway_error(409, 'voice_preset_station_mismatch', 'voice preset profile is on a different station'));
        }
    }
    if ($presetOperation === 'voice_preset_upsert' && $profileRoute === null) {
        return $finish(hub_gateway_error(400, 'voice_preset_invalid', 'voice preset request is invalid'));
    }
    $pinnedStation = $profileRoute !== null || $photoAsset !== null || $presetRoute !== null;

    $refreshDue = is_callable($seams['refresh_due'] ?? null)
        ? $seams['refresh_due']
        : static fn (): array => hub_cluster_refresh_due_stations($db);
    try {
        $inventory = $refreshDue();
    } catch (Throwable) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'cluster inventory is unavailable'));
    }
    if (!is_array($inventory)) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'cluster inventory is unavailable'));
    }
    if ($pinnedStation) {
        $pinnedStationId = $presetRoute !== null
            ? (int)$presetRoute['station_id']
            : ($profileRoute !== null ? (int)$profileRoute['station_id'] : (int)$photoAsset['station_id']);
        $selectedInventory = null;
        foreach ($inventory as $candidate) {
            if (is_array($candidate) && (int)($candidate['id'] ?? 0) === $pinnedStationId) {
                $selectedInventory = $candidate;
                break;
            }
        }
        if ($selectedInventory === null
            || empty($selectedInventory['enabled'])
            || empty($selectedInventory['fresh'])
            || !is_array($selectedInventory['modes'] ?? null)
            || !in_array($mode, $selectedInventory['modes'], true)
            || (hub_cluster_is_photo_mode($mode) && !hub_cluster_photo_modes_are_paired($selectedInventory['modes']))
        ) {
            return $finish(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
        }
    } else {
        $selectedInventory = hub_cluster_select_station($mode, $inventory);
    }
    if ($selectedInventory === null) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'no eligible cluster station is available'));
    }
    $stationId = (int)($selectedInventory['id'] ?? 0);
    $station = $stationId > 0 ? hub_cluster_get_station($db, $stationId) : null;
    if ($station === null || empty($station['enabled'])) {
        return $finish(!$pinnedStation
            ? hub_gateway_error(503, 'router_unavailable', 'no eligible cluster station is available')
            : hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
    }

    $selfStation = hub_cluster_router_station_is_self($db, $station);
    $stationToken = '';
    $stationUrl = '';
    try {
        $stationToken = hub_cluster_station_token($station);
        if (!$selfStation) {
            $stationUrl = hub_cluster_station_request_base_url($station) . 'api.php';
        }
    } catch (Throwable) {
        return $finish(hub_gateway_error(503, !$pinnedStation ? 'router_unavailable' : 'station_unavailable', 'selected cluster station is unavailable'));
    }
    $selfPeerIp = $selfStation ? hub_cluster_router_self_station_peer_ip($db, $station, $stationToken) : null;
    if ($selfStation && $selfPeerIp === null) {
        return $finish(hub_gateway_error(503, !$pinnedStation ? 'router_unavailable' : 'station_unavailable', 'selected cluster station is unavailable'));
    }

    $routeRole = hub_is_voice_profile_mode($mode) && $profileResponseOperation === 'profile_prepare'
        ? 'profile_prepare'
        : 'task';
    $routeId = hub_cluster_router_admit_route(
        $db,
        $station,
        (array)$auth['context'],
        $mode,
        !$selfStation,
        $profileSensitive,
        $routeRole
    );
    if ($routeId === null) {
        return $finish(hub_gateway_error(429, 'router_busy', 'cluster router is busy'));
    }

    $response = hub_gateway_error(502, 'router_proxy_failed', 'cluster station request failed');
    try {
        if ($selfStation) {
            $dispatcher = is_callable($seams['direct_dispatcher'] ?? null)
                ? $seams['direct_dispatcher']
                : static fn (PDO $db, string $mode, array $internalRequest): array => hub_gateway_dispatch($db, $mode, null, $internalRequest);
            $directRequest = $normalized;
            if ($profileRoute !== null && !is_array($directRequest['form'] ?? null)) {
                $directRequest['form'] = ['post' => $profilePayload, 'files' => []];
                $directRequest['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
            }
            $directRequest['bearer_token'] = $stationToken;
            $directRequest['client_ip'] = $selfPeerIp;
            $result = hub_cluster_router_dispatch_self($db, $mode, $directRequest, $dispatcher);
            if (!is_array($result)) {
                throw new RuntimeException('invalid direct response');
            }
            $response = $result;
        } else {
            $transport = is_callable($seams['transport'] ?? null) ? $seams['transport'] : 'hub_cluster_proxy_transport';
            $proxyRequest = [
                'url' => $stationUrl,
                'query' => $normalized['query'],
                'method' => $normalized['method'],
                'headers' => ['Authorization' => 'Bearer ' . $stationToken] + $normalized['headers'],
                'body' => $normalized['raw_body'],
                'form' => $normalized['form'] ?? null,
                'follow_redirects' => false,
                'response_limit_bytes' => hub_cluster_proxy_response_limit_bytes(),
                'timeout_sec' => hub_cluster_proxy_timeout_sec($mode),
            ];
            $response = hub_cluster_router_proxy_response($transport($proxyRequest), $stationToken);
        }
        $payload = hub_cluster_router_json_payload($response);
        if ($pinnedStation && !$selfStation && hub_cluster_router_is_local_proxy_error($response)) {
            $response = hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable');
        } elseif ((int)($response['status'] ?? 0) >= 400 && !hub_cluster_router_is_local_proxy_error($response)) {
            $response = hub_cluster_pack_validation_error_response($response, $payload)
                ?? (hub_is_voice_profile_mode($mode) ? hub_cluster_voice_generate_relay_response($response, $payload) : null);
            $response ??= hub_gateway_error(502, 'router_response_failed', 'cluster station response failed');
        } elseif ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300) {
            if ($mode === 'photo_upload') {
                try {
                    if (!is_array($payload)) {
                        throw new UnexpectedValueException('invalid photo upload response');
                    }
                    $payload = hub_cluster_router_photo_upload_payload($db, $payload, (int)$station['id'], (array)$auth['context']);
                    $response = hub_cluster_router_with_json_payload($response, $payload, true);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($mode === 'photo') {
                if (!is_array($payload) || $photoAsset === null) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                } else {
                    $payload['image_id'] = (string)$photoAsset['image_id'];
                    $response = hub_cluster_router_with_json_payload($response, $payload, true);
                }
            } elseif (in_array($presetOperation, ['voice_preset_upsert', 'voice_preset_anchor_upsert', 'voice_preset_engine_bind'], true)) {
                try {
                    if (!is_array($payload) || array_keys($payload) !== ['ok', 'preset'] || ($payload['ok'] ?? null) !== true) {
                        throw new UnexpectedValueException('invalid voice preset response');
                    }
                    $preset = hub_cluster_voice_preset_public_child_value($payload['preset'] ?? null);
                    if ($preset === null) {
                        throw new UnexpectedValueException('invalid voice preset response');
                    }
                    $preset = hub_cluster_voice_preset_store($db, (array)$auth['context'], (int)$station['id'], $preset);
                    if (!hash_equals((string)($profilePayload['voice_preset'] ?? ''), $preset['id'])) {
                        throw new UnexpectedValueException('voice preset response mismatch');
                    }
                    $response = hub_cluster_router_with_json_payload($response, ['ok' => true, 'preset' => $preset], true);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($presetOperation === 'voice_preset_delete') {
                try {
                    if (!is_array($payload)
                        || $payload !== ['ok' => true, 'voice_preset' => (string)($profilePayload['voice_preset'] ?? ''), 'status' => 'deleted']) {
                        throw new UnexpectedValueException('invalid voice preset delete response');
                    }
                    hub_cluster_voice_preset_delete($db, (array)$auth['context'], (string)$payload['voice_preset']);
                    $response = hub_cluster_router_with_json_payload($response, $payload, true);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($presetOperation === 'preset_synthesize') {
                try {
                    if (!is_array($payload)) {
                        throw new UnexpectedValueException('invalid voice preset task response');
                    }
                    hub_cluster_router_voice_profile_async_task_id($payload);
                    $payload = hub_cluster_rewrite_async_response($db, [
                        'route_id' => $routeId,
                        'station_id' => (int)$station['id'],
                    ], $payload, hub_cluster_router_api_base_url());
                    $response = hub_cluster_router_with_json_payload($response, $payload, false);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($isProfileRequest && !is_array($payload)) {
                $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
            } elseif ($isProfileRequest && in_array($profileResponseOperation, ['profile_status', 'profile_confirm', 'profile_delete'], true)) {
                try {
                    $payload = $profileResponseOperation === 'profile_confirm'
                        ? hub_cluster_router_public_voice_profile_confirmation_response(
                            $payload,
                            (string)($profileRoute['remote_task_id'] ?? ''),
                            (string)($profileRoute['route_id'] ?? ''),
                            is_string($profilePayload['prompt_text'] ?? null) ? $profilePayload['prompt_text'] : ''
                        )
                        : hub_cluster_router_public_voice_profile_response($payload, $profileResponseOperation === 'profile_status');
                    $response = hub_cluster_router_with_json_payload($response, $payload, $profileSensitive);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($isProfileRequest && in_array($profileResponseOperation, ['profile_prepare', 'synthesize'], true)) {
                try {
                    hub_cluster_router_voice_profile_async_task_id($payload);
                    $payload = hub_cluster_rewrite_async_response($db, [
                        'route_id' => $routeId,
                        'station_id' => (int)$station['id'],
                    ], $payload, hub_cluster_router_api_base_url());
                    $response = hub_cluster_router_with_json_payload($response, $payload, $profileSensitive);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif ($isProfileRequest) {
                $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
            } elseif ($mode === 'tts' && is_array($payload)) {
                try {
                    $payload = hub_cluster_rewrite_tts_response($db, ['route_id' => $routeId], $payload, hub_cluster_router_api_base_url());
                    $response = hub_cluster_router_with_json_payload($response, $payload);
                } catch (Throwable) {
                    $response = hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid');
                }
            } elseif (is_array($payload) && is_scalar($payload['task_id'] ?? null)) {
                $payload = hub_cluster_rewrite_async_response($db, [
                    'route_id' => $routeId,
                    'station_id' => (int)$station['id'],
                ], $payload, hub_cluster_router_api_base_url());
                $response = hub_cluster_router_with_json_payload($response, $payload, $profileSensitive);
            }
        }
    } catch (Throwable) {
        $response = $pinnedStation
            ? hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable')
            : hub_gateway_error(502, 'router_proxy_failed', 'cluster station request failed');
    } finally {
        hub_cluster_router_complete_route(
            $db,
            $routeId,
            $station,
            (array)$auth['context'],
            $mode,
            !$selfStation,
            $response,
            $requestId,
            (int)round((microtime(true) - $started) * 1000),
            (int)$normalized['request_bytes']
        );
    }

    return $finish($response);
}

function hub_cluster_router_normalize_request(string $mode, array $request): array
{
    $method = strtoupper(trim((string)($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET')));
    if (preg_match('/^[A-Z]{1,10}$/', $method) !== 1) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'invalid request method')];
    }
    $headers = hub_cluster_router_safe_request_headers($request);
    $contentLength = array_key_exists('content_length', $request) ? $request['content_length'] : ($_SERVER['CONTENT_LENGTH'] ?? '');
    $requestUri = (string)($request['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? '');
    if ($mode === 'edge_tts' && $method === 'GET' && hub_edge_tts_demo_request_has_duplicate_voice($requestUri)) {
        return ['response' => hub_gateway_error(400, 'invalid_request', 'invalid request')];
    }
    $inputQuery = array_key_exists('query', $request) ? $request['query'] : ($_GET ?? []);
    if (!is_array($inputQuery)) {
        return ['response' => hub_gateway_error(400, 'router_request_unsupported', 'request query is not supported')];
    }
    $query = [];
    foreach ($inputQuery as $key => $value) {
        if ($key === 'mode') {
            continue;
        }
        if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key) !== 1 || !is_scalar($value)) {
            return ['response' => hub_gateway_error(400, 'router_request_unsupported', 'request query is not supported')];
        }
        $value = (string)$value;
        if (strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return ['response' => hub_gateway_error(400, 'router_request_unsupported', 'request query is not supported')];
        }
        $query[$key] = $value;
    }
    $query['mode'] = $mode;
    if (preg_match('/^multipart\/form-data(?:;|$)/i', (string)($headers['Content-Type'] ?? '')) === 1) {
        if (hub_cluster_router_content_length_exceeds($contentLength, hub_cluster_proxy_request_limit_bytes())) {
            return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
        }
        $post = hub_cluster_router_normalize_scalar_fields(
            array_key_exists('post', $request) ? $request['post'] : ($_POST ?? []),
            $mode
        );
        $files = hub_cluster_router_normalize_uploaded_files(array_key_exists('files', $request) ? $request['files'] : ($_FILES ?? []));
        if ($post === null || $files === null) {
            return ['response' => hub_gateway_error(400, 'router_request_unsupported', 'multipart form is not supported')];
        }
        $requestBytes = hub_cluster_router_request_bytes($contentLength, $post, $files);
        if ($requestBytes > hub_cluster_proxy_request_limit_bytes()) {
            return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
        }
        unset($headers['Content-Type']);

        return [
            'method' => $method,
            'headers' => $headers,
            'raw_body' => '',
            'form' => ['post' => $post, 'files' => $files],
            'query' => $query,
            'request_uri' => $requestUri,
            'request_bytes' => $requestBytes,
        ];
    }
    $files = array_key_exists('files', $request) ? $request['files'] : ($_FILES ?? []);
    if (!is_array($files) || $files !== []) {
        return ['response' => hub_gateway_error(415, 'router_upload_unsupported', 'file uploads are not supported by the cluster router')];
    }
    $body = hub_cluster_router_read_request_body($request);
    if (isset($body['response'])) {
        return $body;
    }
    return [
        'method' => $method,
        'headers' => $headers,
        'raw_body' => $body['body'],
        'query' => $query,
        'request_uri' => $requestUri,
        'request_bytes' => strlen($body['body']),
    ];
}

function hub_cluster_router_normalized_content_type(array $normalized): string
{
    $value = (string)($normalized['headers']['Content-Type'] ?? '');

    return strtolower(trim(explode(';', $value, 2)[0]));
}

function hub_cluster_router_voice_profile_payload(array $normalized): array
{
    if (($normalized['method'] ?? null) === 'GET') {
        $payload = $normalized['query'] ?? null;
        if (!is_array($payload)) {
            throw new UnexpectedValueException('invalid voice profile query');
        }

        return $payload;
    }
    if (is_array($normalized['form'] ?? null) && is_array($normalized['form']['post'] ?? null)) {
        return $normalized['form']['post'];
    }
    $contentType = hub_cluster_router_normalized_content_type($normalized);
    if ($contentType === 'application/json') {
        $payload = json_decode((string)($normalized['raw_body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
    } elseif ($contentType === 'application/x-www-form-urlencoded') {
        parse_str((string)($normalized['raw_body'] ?? ''), $payload);
    } else {
        return [];
    }
    if (!is_array($payload)) {
        throw new UnexpectedValueException('invalid voice profile payload');
    }

    return $payload;
}

function hub_cluster_router_key_maps_to_voice_profile_reference(string $key): bool
{
    $parsed = [];
    parse_str(rawurlencode($key) . '=1', $parsed);

    return array_key_exists('voice_profile_task_id', $parsed);
}

function hub_cluster_router_urlencoded_profile_reference(string $body): ?array
{
    if (preg_match('/%(?![A-Fa-f0-9]{2})/', $body) === 1) {
        throw new UnexpectedValueException('invalid urlencoded body');
    }
    $matches = [];
    $offset = 0;
    foreach (explode('&', $body) as $segment) {
        $equals = strpos($segment, '=');
        $rawKey = $equals === false ? $segment : substr($segment, 0, $equals);
        $key = urldecode($rawKey);
        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new UnexpectedValueException('invalid urlencoded field');
        }
        if ($key !== 'voice_profile_task_id' && hub_cluster_router_key_maps_to_voice_profile_reference($key)) {
            throw new UnexpectedValueException('ambiguous voice profile reference');
        }
        if ($key === 'voice_profile_task_id') {
            $rawValue = $equals === false ? '' : substr($segment, $equals + 1);
            $value = urldecode($rawValue);
            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new UnexpectedValueException('invalid voice profile reference');
            }
            $matches[] = [
                'value' => $value,
                'offset' => $offset + ($equals === false ? strlen($segment) : $equals + 1),
                'length' => strlen($rawValue),
                'needs_equals' => $equals === false,
            ];
        }
        $offset += strlen($segment) + 1;
    }
    if (count($matches) > 1) {
        throw new UnexpectedValueException('duplicate voice profile reference');
    }

    return $matches[0] ?? null;
}

function hub_cluster_router_json_profile_reference(string $body): ?array
{
    try {
        json_decode($body, true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (Throwable $e) {
        throw new UnexpectedValueException('invalid JSON body', 0, $e);
    }

    $length = strlen($body);
    $whitespace = " \t\r\n";
    $skipWhitespace = static function (int $offset) use ($body, $length, $whitespace): int {
        while ($offset < $length && str_contains($whitespace, $body[$offset])) {
            $offset++;
        }
        return $offset;
    };
    $scanString = static function (int $offset) use ($body, $length): int {
        for ($offset++; $offset < $length; $offset++) {
            if ($body[$offset] === '\\') {
                $offset++;
                continue;
            }
            if ($body[$offset] === '"') {
                return $offset + 1;
            }
        }
        throw new UnexpectedValueException('invalid JSON string');
    };

    $offset = $skipWhitespace(0);
    if ($offset >= $length || $body[$offset] !== '{') {
        throw new UnexpectedValueException('JSON body must be an object');
    }
    $offset++;
    $matches = [];
    while (true) {
        $offset = $skipWhitespace($offset);
        if ($offset < $length && $body[$offset] === '}') {
            $offset++;
            break;
        }
        if ($offset >= $length || $body[$offset] !== '"') {
            throw new UnexpectedValueException('invalid JSON object');
        }
        $keyStart = $offset;
        $offset = $scanString($offset);
        $key = json_decode(substr($body, $keyStart, $offset - $keyStart), true, 2, JSON_THROW_ON_ERROR);
        if (!is_string($key) || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new UnexpectedValueException('invalid JSON field');
        }
        if ($key !== 'voice_profile_task_id' && hub_cluster_router_key_maps_to_voice_profile_reference($key)) {
            throw new UnexpectedValueException('ambiguous voice profile reference');
        }
        $offset = $skipWhitespace($offset);
        if ($offset >= $length || $body[$offset] !== ':') {
            throw new UnexpectedValueException('invalid JSON object');
        }
        $valueStart = $skipWhitespace($offset + 1);
        $offset = $valueStart;
        $depth = 0;
        while ($offset < $length) {
            $character = $body[$offset];
            if ($character === '"') {
                $offset = $scanString($offset);
                continue;
            }
            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                break;
            }
            $offset++;
        }
        $valueEnd = $offset;
        while ($valueEnd > $valueStart && str_contains($whitespace, $body[$valueEnd - 1])) {
            $valueEnd--;
        }
        if ($key === 'voice_profile_task_id') {
            $value = json_decode(substr($body, $valueStart, $valueEnd - $valueStart), true, 32, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (!is_scalar($value) || preg_match('/[\x00-\x1F\x7F]/', (string)$value) === 1) {
                throw new UnexpectedValueException('invalid voice profile reference');
            }
            $matches[] = [
                'value' => (string)$value,
                'offset' => $valueStart,
                'length' => $valueEnd - $valueStart,
            ];
        }
        if ($offset < $length && $body[$offset] === ',') {
            $offset++;
            continue;
        }
        if ($offset < $length && $body[$offset] === '}') {
            $offset++;
            break;
        }
        throw new UnexpectedValueException('invalid JSON object');
    }
    if ($skipWhitespace($offset) !== $length) {
        throw new UnexpectedValueException('invalid JSON body');
    }
    if (count($matches) > 1) {
        throw new UnexpectedValueException('duplicate voice profile reference');
    }

    return $matches[0] ?? null;
}

function hub_cluster_router_query_profile_reference(array $normalized): ?array
{
    $query = $normalized['query'] ?? [];
    if (!is_array($query)) {
        throw new UnexpectedValueException('invalid request query');
    }
    $value = null;
    foreach ($query as $key => $candidate) {
        if (!is_string($key) || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new UnexpectedValueException('invalid query field');
        }
        if ($key !== 'voice_profile_task_id' && hub_cluster_router_key_maps_to_voice_profile_reference($key)) {
            throw new UnexpectedValueException('ambiguous voice profile reference');
        }
        if ($key === 'voice_profile_task_id') {
            if (!is_scalar($candidate)) {
                throw new UnexpectedValueException('invalid voice profile reference');
            }
            $candidate = (string)$candidate;
            if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
                throw new UnexpectedValueException('invalid voice profile reference');
            }
            $value = $candidate;
        }
    }

    $requestUri = $normalized['request_uri'] ?? '';
    if (!is_string($requestUri)) {
        throw new UnexpectedValueException('invalid request URI');
    }
    $question = strpos($requestUri, '?');
    if ($question === false) {
        return $value === null ? null : ['value' => $value];
    }
    $queryStart = $question + 1;
    $fragment = strpos($requestUri, '#', $queryStart);
    $queryLength = ($fragment === false ? strlen($requestUri) : $fragment) - $queryStart;
    $rawReference = hub_cluster_router_urlencoded_profile_reference(substr($requestUri, $queryStart, $queryLength));
    if ($rawReference !== null && ($value === null || !hash_equals($value, (string)$rawReference['value']))) {
        throw new UnexpectedValueException('ambiguous voice profile reference');
    }
    if ($value === null) {
        return null;
    }

    return [
        'value' => $value,
        'uri_offset' => $rawReference === null ? null : $queryStart + (int)$rawReference['offset'],
        'uri_length' => $rawReference === null ? null : (int)$rawReference['length'],
        'uri_needs_equals' => (bool)($rawReference['needs_equals'] ?? false),
    ];
}

function hub_cluster_voice_profile_reference(array $normalized): ?string
{
    $references = [];
    $queryReference = hub_cluster_router_query_profile_reference($normalized);
    if ($queryReference !== null) {
        $references[] = $queryReference['value'];
    }
    if (($normalized['method'] ?? '') === 'GET') {
        return $references[0] ?? null;
    }

    if (array_key_exists('form', $normalized)) {
        $form = $normalized['form'];
        if (!is_array($form) || !is_array($form['post'] ?? null)) {
            throw new UnexpectedValueException('invalid multipart form');
        }
        $reference = null;
        foreach ($form['post'] as $key => $value) {
            if (!is_string($key)) {
                throw new UnexpectedValueException('invalid multipart field');
            }
            if ($key !== 'voice_profile_task_id' && hub_cluster_router_key_maps_to_voice_profile_reference($key)) {
                throw new UnexpectedValueException('ambiguous voice profile reference');
            }
            if ($key === 'voice_profile_task_id') {
                if (!is_scalar($value)) {
                    throw new UnexpectedValueException('invalid voice profile reference');
                }
                $value = (string)$value;
                if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                    throw new UnexpectedValueException('invalid voice profile reference');
                }
                $reference = $value;
            }
        }
        if ($reference !== null) {
            $references[] = $reference;
        }
    } else {
        $contentType = hub_cluster_router_normalized_content_type($normalized);
        if (in_array($contentType, ['application/x-www-form-urlencoded', 'application/json'], true)) {
            if (!is_string($normalized['raw_body'] ?? null)) {
                throw new UnexpectedValueException('invalid request body');
            }
            $reference = $contentType === 'application/json'
                ? hub_cluster_router_json_profile_reference($normalized['raw_body'])
                : hub_cluster_router_urlencoded_profile_reference($normalized['raw_body']);
            if ($reference !== null) {
                $references[] = $reference['value'];
            }
        }
    }
    if (count($references) > 1) {
        throw new UnexpectedValueException('duplicate voice profile reference');
    }

    return $references[0] ?? null;
}

function hub_cluster_replace_voice_profile_reference(array $normalized, string $remoteTaskId): array
{
    if (hub_cluster_voice_profile_reference($normalized) === null) {
        throw new UnexpectedValueException('voice profile reference is missing');
    }
    $queryReference = hub_cluster_router_query_profile_reference($normalized);
    if ($queryReference !== null) {
        $normalized['query']['voice_profile_task_id'] = $remoteTaskId;
        if ($queryReference['uri_offset'] !== null) {
            $replacement = ($queryReference['uri_needs_equals'] ? '=' : '') . rawurlencode($remoteTaskId);
            $normalized['request_uri'] = substr_replace(
                (string)$normalized['request_uri'],
                $replacement,
                (int)$queryReference['uri_offset'],
                (int)$queryReference['uri_length']
            );
        }

        return $normalized;
    }
    if (is_array($normalized['form'] ?? null)) {
        $normalized['form']['post']['voice_profile_task_id'] = $remoteTaskId;
        return $normalized;
    }

    $contentType = hub_cluster_router_normalized_content_type($normalized);
    $reference = $contentType === 'application/json'
        ? hub_cluster_router_json_profile_reference((string)$normalized['raw_body'])
        : hub_cluster_router_urlencoded_profile_reference((string)$normalized['raw_body']);
    if ($reference === null) {
        throw new UnexpectedValueException('voice profile reference is missing');
    }
    $replacement = $contentType === 'application/json'
        ? json_encode($remoteTaskId, JSON_THROW_ON_ERROR)
        : ($reference['needs_equals'] ?? false ? '=' : '') . rawurlencode($remoteTaskId);
    $normalized['raw_body'] = substr_replace(
        (string)$normalized['raw_body'],
        $replacement,
        (int)$reference['offset'],
        (int)$reference['length']
    );

    return $normalized;
}

function hub_cluster_router_multipart_scalar_limits(string $mode, array $source): ?array
{
    if (!hub_is_voice_profile_mode($mode)) {
        return null;
    }
    $operation = $source['operation'] ?? null;
    if ($operation !== null && !is_scalar($operation)) {
        return [];
    }
    $operation = $operation === null ? null : (string)$operation;
    if ($operation === 'profile_prepare') {
        return [
            'operation' => 15,
            'profile_name' => 120,
            'consent_type' => 19,
            'prompt_text' => 20000,
            'expected_text' => 20000,
            'transcript_confirmed' => 5,
            'language' => 64,
            'callback_target' => 32,
            'expires_in_seconds' => 5,
        ];
    }
    if ($operation !== null && $operation !== 'synthesize') {
        return [];
    }

    return [
        'operation' => 10,
        'text' => 4096,
        'mode' => 16,
        'voice_prompt' => 1024,
        'control' => 1024,
        'generation_profile' => 32,
        'legacy_speed' => 16,
        'legacy_emotion' => 16,
        'seed' => 10,
        'seed_policy' => 32,
        'model' => 32,
        'voice_profile_id' => 10,
        'voice_profile_task_id' => 64,
        'waveform_preview' => 5,
        'callback' => 5,
        'callback_target' => 32,
    ];
}

function hub_cluster_router_normalize_scalar_fields(mixed $source, string $mode = ''): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $limits = hub_cluster_router_multipart_scalar_limits($mode, $source);
    $fields = [];
    foreach ($source as $key => $value) {
        if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $key) !== 1 || !is_scalar($value)) {
            return null;
        }
        $value = (string)$value;
        $limit = $limits === null ? 1024 : ($limits[$key] ?? 0);
        if ($limit === 0 || strlen($value) > $limit || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }
        $fields[$key] = $value;
    }

    return $fields;
}

function hub_cluster_router_normalize_uploaded_files(mixed $source): ?array
{
    if (!is_array($source)) {
        return null;
    }
    $files = [];
    foreach ($source as $field => $file) {
        if (!is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $field) !== 1 || !is_array($file) || is_array($file['tmp_name'] ?? null)) {
            return null;
        }
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error)) {
            return null;
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $path = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($path) || !is_file($path)) {
            return null;
        }
        $size = filesize($path);
        if ($size === false || $size < 0) {
            return null;
        }
        $name = $file['name'] ?? $field;
        if (!is_string($name)) {
            return null;
        }
        $name = basename(str_replace('\\', '/', $name));
        if ($name === '' || strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return null;
        }
        $type = $file['type'] ?? '';
        $type = is_string($type) && preg_match('/\A[a-z0-9.+-]{1,127}\/[a-z0-9.+-]{1,127}\z/i', $type) === 1
            ? $type
            : 'application/octet-stream';
        $files[$field] = ['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => (int)$size];
    }

    return $files;
}

function hub_cluster_router_request_bytes(mixed $contentLength, array $post, array $files): int
{
    $fallback = array_sum(array_map(static fn (string $value): int => strlen($value), $post));
    foreach ($files as $file) {
        $fallback += (int)($file['size'] ?? 0);
    }
    if (is_int($contentLength) && $contentLength >= 0) {
        return max($contentLength, $fallback);
    }
    $contentLength = trim((string)$contentLength);

    return ctype_digit($contentLength) ? max((int)$contentLength, $fallback) : $fallback;
}

function hub_cluster_router_dispatch_self(PDO $db, string $mode, array $request, callable $dispatcher): array
{
    $form = is_array($request['form'] ?? null) ? $request['form'] : null;
    if ($form === null) {
        return $dispatcher($db, $mode, $request);
    }
    $oldGet = $_GET;
    $oldPost = $_POST;
    $oldFiles = $_FILES;
    $oldServer = $_SERVER;
    try {
        $_GET = (array)($request['query'] ?? []);
        // ponytail: request-scoped relay; stream only if a supported pack exceeds the Router ceiling.
        $_POST = (array)($form['post'] ?? []);
        $_FILES = (array)($form['files'] ?? []);
        $_SERVER['REQUEST_METHOD'] = (string)($request['method'] ?? 'POST');
        if (is_string($request['headers']['Content-Type'] ?? null) && $request['headers']['Content-Type'] !== '') {
            $_SERVER['CONTENT_TYPE'] = $request['headers']['Content-Type'];
        }
        $_SERVER['CONTENT_LENGTH'] = (string)($request['request_bytes'] ?? 0);
        unset($request['raw_body']);

        return $dispatcher($db, $mode, $request);
    } finally {
        $_GET = $oldGet;
        $_POST = $oldPost;
        $_FILES = $oldFiles;
        $_SERVER = $oldServer;
    }
}

function hub_cluster_router_requested_mode(mixed $value): ?string
{
    return is_string($value) && $value !== '' ? $value : null;
}

function hub_cluster_router_api_base_url(): string
{
    return 'cluster_api.php';
}

function hub_cluster_router_rich_artifact_mode(?string $mode): bool
{
    return $mode === 'edge_tts' || hub_is_audio_async_mode((string)$mode);
}

function hub_cluster_router_task_links(string $routeId, string $routerBase, ?string $routeMode = null): array
{
    $prefix = rtrim($routerBase, '?') . (str_contains($routerBase, '?') ? '&' : '?');
    $taskId = rawurlencode($routeId);

    $links = [
        'status_url' => $prefix . 'mode=cluster_task_status&task_id=' . $taskId,
        'result_url' => $prefix . 'mode=cluster_task_result&task_id=' . $taskId,
        'log_url' => $prefix . 'mode=cluster_task_log&task_id=' . $taskId,
        'cancel_url' => $prefix . 'mode=cluster_task_cancel&task_id=' . $taskId,
        'artifact_url_template' => $prefix . 'mode=cluster_artifact&task_id=' . $taskId . '&artifact_id={artifact_id}',
    ];
    if (hub_cluster_router_rich_artifact_mode($routeMode)) {
        $links['ack_url_template'] = $prefix . 'mode=cluster_task_artifacts_ack&task_id=' . $taskId . '&artifact_id={artifact_id}';
    }

    return $links;
}

function hub_cluster_tts_artifact_filename(mixed $file): ?string
{
    if (!is_string($file) || preg_match('/\Atts_[a-f0-9]{12}\.(?:wav|json)\z/', $file) !== 1) {
        return null;
    }

    return $file;
}

function hub_cluster_tts_artifact_file_from_url(mixed $value): ?string
{
    if (!is_string($value) || preg_match('~\A/artifacts/(tts_[a-f0-9]{12}\.(?:wav|json))\z~', $value, $matches) !== 1) {
        return null;
    }

    return hub_cluster_tts_artifact_filename($matches[1]);
}

function hub_cluster_router_tts_artifact_url(string $routeId, string $file, string $routerBase): string
{
    if (!hub_cluster_router_valid_route_id($routeId) || hub_cluster_tts_artifact_filename($file) === null) {
        throw new InvalidArgumentException('cluster TTS artifact is invalid');
    }
    $prefix = rtrim($routerBase, '?') . (str_contains($routerBase, '?') ? '&' : '?');

    return $prefix . 'mode=cluster_tts_artifact&route_id=' . rawurlencode($routeId) . '&file=' . rawurlencode($file);
}

function hub_cluster_rewrite_tts_response(PDO $db, array $route, array $payload, string $routerBase): array
{
    $routeId = (string)($route['route_id'] ?? '');
    $audio = hub_cluster_tts_artifact_file_from_url($payload['artifact_url'] ?? null);
    if (!hub_cluster_router_valid_route_id($routeId) || $audio === null || !str_ends_with($audio, '.wav')) {
        throw new UnexpectedValueException('invalid TTS artifact response');
    }
    $files = ['artifact_url' => $audio];
    if (array_key_exists('manifest', $payload)) {
        $manifest = hub_cluster_tts_artifact_file_from_url($payload['manifest']);
        $expectedManifest = preg_replace('/\.wav\z/', '.json', $audio);
        if ($manifest === null || $manifest !== $expectedManifest) {
            throw new UnexpectedValueException('invalid TTS manifest response');
        }
        $files['manifest'] = $manifest;
    }
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO cluster_route_artifacts (route_id, remote_artifact_id, created_at)
         VALUES (:route_id, :remote_artifact_id, :created_at)'
    );
    $now = hub_now();
    foreach ($files as $file) {
        $stmt->execute([
            ':route_id' => $routeId,
            ':remote_artifact_id' => $file,
            ':created_at' => $now,
        ]);
    }
    foreach ($files as $key => $file) {
        $payload[$key] = hub_cluster_router_tts_artifact_url($routeId, $file, $routerBase);
    }

    return $payload;
}

function hub_cluster_rewrite_async_response(PDO $db, array $route, array $payload, string $routerBase): array
{
    $routeId = (string)($route['route_id'] ?? '');
    $remoteTaskId = $payload['task_id'] ?? null;
    if (!hub_cluster_router_valid_route_id($routeId) || !is_scalar($remoteTaskId)) {
        throw new RuntimeException('cluster async response is invalid');
    }
    $remoteTaskId = (string)$remoteTaskId;
    $now = hub_now();
    $stmt = $db->prepare(
        "UPDATE cluster_routes
         SET remote_task_id = :remote_task_id, is_async = 1, state = 'active', remote_status = 'active', updated_at = :updated_at, completed_at = NULL
         WHERE route_id = :route_id"
    );
    $stmt->execute([
        ':remote_task_id' => $remoteTaskId,
        ':updated_at' => $now,
        ':route_id' => $routeId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('cluster route is unavailable');
    }

    $mode = $db->prepare('SELECT mode FROM cluster_routes WHERE route_id = :route_id LIMIT 1');
    $mode->execute([':route_id' => $routeId]);
    $route['mode'] = (string)$mode->fetchColumn();

    return hub_cluster_router_rewrite_task_payload($db, $route, $payload, $routerBase, $remoteTaskId);
}

function hub_cluster_router_rewrite_task_payload(PDO $db, array $route, array $payload, string $routerBase, string $remoteTaskId, string $kind = 'submit'): array
{
    $routeId = (string)($route['route_id'] ?? '');
    $status = hub_cluster_router_public_task_status($payload['status'] ?? null);
    $response = ['ok' => ($payload['ok'] ?? false) === true, 'task_id' => $routeId];
    if ($kind === 'result') {
        $response['result'] = hub_cluster_router_public_task_result(
            $payload,
            hub_cluster_router_rich_artifact_mode((string)($route['mode'] ?? ''))
        );
        if (is_array($response['result']['candidates'] ?? null)) {
            $template = hub_cluster_router_task_links($routeId, $routerBase, (string)($route['mode'] ?? ''))['artifact_url_template'];
            foreach ($response['result']['candidates'] as &$candidate) {
                $candidate['audio_url'] = str_replace('{artifact_id}', rawurlencode((string)$candidate['audio_artifact_id']), $template);
            }
            unset($candidate);
            $genericArtifacts = hub_cluster_router_generic_voice_candidate_artifact_index(
                $response['result']['candidates'],
                hub_cluster_router_result_artifacts($payload, true) ?? []
            );
            if ($genericArtifacts === null) {
                throw new UnexpectedValueException('invalid generic voice candidate artifacts');
            }
            if ($genericArtifacts !== []) {
                $response['cluster_artifact_index'] = $genericArtifacts;
            }
        }
    } elseif ($kind === 'log') {
        $logs = hub_cluster_router_public_task_logs($db, $route, $payload, $remoteTaskId);
        if ($logs === null) {
            throw new UnexpectedValueException('invalid native task logs');
        }
        $response['logs'] = $logs;
    } else {
        if ($status !== null) {
            $response['status'] = $status;
        }
        if ($kind === 'status') {
            $response['progress'] = is_int($payload['progress'] ?? null) && $payload['progress'] >= 0 && $payload['progress'] <= 100
                ? $payload['progress']
                : 0;
            $waiting = hub_cluster_router_public_waiting_fields($payload);
            $response['message'] = hub_task_status_message($waiting === [] ? ($status ?? 'queued') : 'waiting_gpu', $waiting['waiting_reason'] ?? null);
            $response += $waiting;
        }
        if (in_array($kind, ['status', 'cancel'], true) && is_bool($payload['cancel_requested'] ?? null)) {
            $response['cancel_requested'] = $payload['cancel_requested'];
        }
        if ($kind === 'submit' && ($payload['cached'] ?? false) === true) {
            $response['cached'] = true;
            if (is_int($payload['cache_age_seconds'] ?? null) && $payload['cache_age_seconds'] >= 0 && $payload['cache_age_seconds'] <= 31536000) {
                $response['cache_age_seconds'] = $payload['cache_age_seconds'];
            }
        }
    }

    return array_replace($response, hub_cluster_router_task_links($routeId, $routerBase, (string)($route['mode'] ?? '')));
}

function hub_cluster_router_public_waiting_fields(array $payload): array
{
    $reason = $payload['waiting_reason'] ?? null;
    if (($payload['status'] ?? null) !== 'waiting_gpu'
        || !is_string($reason)
        || !in_array($reason, [
            'gpu_unavailable', 'insufficient_vram', 'unmanaged_gpu_process',
            'resident_busy', 'resident_unknown', 'resident_service_unavailable',
        ], true)
    ) {
        return [];
    }
    foreach (['required_vram_mb', 'free_vram_mb'] as $field) {
        if (!array_key_exists($field, $payload)
            || ($payload[$field] !== null && (!is_int($payload[$field]) || $payload[$field] < 0 || $payload[$field] > 1_000_000_000))) {
            return [];
        }
    }
    $retry = $payload['retry_after_seconds'] ?? null;
    if (!is_int($retry) || $retry < 0 || $retry > 86400 || !is_array($payload['gpu_processes'] ?? null)) {
        return [];
    }
    $snapshot = hub_task_waiting_detail_snapshot([
        'required_vram_mb' => $payload['required_vram_mb'],
        'free_vram_mb' => $payload['free_vram_mb'],
        'gpu_processes' => $payload['gpu_processes'],
    ]);
    if (count($snapshot['gpu_processes']) !== count($payload['gpu_processes'])) {
        return [];
    }

    return [
        'waiting_reason' => $reason,
        'required_vram_mb' => $snapshot['required_vram_mb'],
        'free_vram_mb' => $snapshot['free_vram_mb'],
        'retry_after_seconds' => $retry,
        'gpu_processes' => $snapshot['gpu_processes'],
    ];
}

function hub_cluster_router_public_task_status(mixed $status): ?string
{
    if (!is_string($status)) {
        return null;
    }
    $status = strtolower($status);

    return in_array($status, ['staging', 'queued', 'waiting_gpu', 'running', 'success', 'succeeded', 'completed', 'failed', 'cancelled', 'canceled', 'timed_out', 'timeout'], true)
        ? match ($status) {
            'staging',
            'waiting_gpu' => 'queued',
            'completed', 'succeeded' => 'success',
            'timeout' => 'timed_out',
            default => $status,
        }
        : null;
}

function hub_cluster_router_result_artifacts(array $payload, bool $includeMetadata = false): ?array
{
    $artifacts = $payload['cluster_artifact_index'] ?? null;
    if (!is_array($artifacts) || !array_is_list($artifacts) || count($artifacts) > 128) {
        return null;
    }
    $safe = [];
    foreach ($artifacts as $artifact) {
        $id = is_array($artifact) ? hub_cluster_router_safe_artifact_id($artifact['id'] ?? null) : null;
        if ($id === null) {
            return null;
        }
        $entry = ['id' => $id['value']];
        if (is_int($artifact['size_bytes'] ?? null) && $artifact['size_bytes'] >= 0) {
            $entry['size_bytes'] = $artifact['size_bytes'];
        }
        if ($includeMetadata) {
            if (is_string($artifact['type'] ?? null) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $artifact['type']) === 1) {
                $entry['type'] = $artifact['type'];
            }
            if (is_string($artifact['mime_type'] ?? null) && preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z/i', $artifact['mime_type']) === 1) {
                $entry['mime_type'] = strtolower($artifact['mime_type']);
            }
            if (is_string($artifact['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) === 1) {
                $entry['sha256'] = $artifact['sha256'];
            }
        }
        $safe[$id['key']] = $entry;
    }

    return array_values($safe);
}

function hub_cluster_router_safe_artifact_id(mixed $id): ?array
{
    $value = is_int($id) ? (string)$id : (is_string($id) ? $id : '');
    if ($value === '' || !ctype_digit($value) || strlen($value) > 18 || trim($value, '0') === '') {
        return null;
    }

    return ['key' => $value, 'value' => is_int($id) ? $id : $value];
}

function hub_cluster_router_public_voice_preset_candidates(mixed $value, array $artifacts): ?array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 3) {
        return null;
    }
    $known = array_fill_keys(array_map(static fn (array $artifact): string => (string)$artifact['id'], $artifacts), true);
    $revision = null;
    foreach ($value as $index => $candidate) {
        $artifact = is_array($candidate) ? hub_cluster_router_safe_artifact_id($candidate['audio_artifact_id'] ?? null) : null;
        if (!is_array($candidate)
            || array_keys($candidate) !== ['candidate_id', 'audio_artifact_id', 'seed', 'preset_revision']
            || ($candidate['candidate_id'] ?? null) !== 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)
            || $artifact === null || !isset($known[$artifact['key']])
            || !is_int($candidate['seed'] ?? null) || $candidate['seed'] < 0 || $candidate['seed'] > 2147483647
            || !is_int($candidate['preset_revision'] ?? null) || $candidate['preset_revision'] < 1
            || ($revision !== null && $candidate['preset_revision'] !== $revision)) {
            return null;
        }
        $revision = $candidate['preset_revision'];
        $value[$index]['audio_artifact_id'] = $artifact['value'];
    }

    return $value;
}

function hub_cluster_router_public_generic_voice_candidates(mixed $value, array $artifacts): ?array
{
    if (!is_array($value) || !array_is_list($value) || count($value) < 1 || count($value) > 3) {
        return null;
    }
    $known = array_fill_keys(array_map(static fn (array $artifact): string => (string)$artifact['id'], $artifacts), true);
    $seen = [];
    foreach ($value as $index => $candidate) {
        $artifact = is_array($candidate) ? hub_cluster_router_safe_artifact_id($candidate['audio_artifact_id'] ?? null) : null;
        if (!is_array($candidate)
            || array_keys($candidate) !== ['candidate_id', 'audio_artifact_id', 'seed', 'voice_design_revision', 'style_status']
            || ($candidate['candidate_id'] ?? null) !== 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT)
            || $artifact === null || !isset($known[$artifact['key']]) || isset($seen[$artifact['key']])
            || !is_int($candidate['seed'] ?? null) || $candidate['seed'] < 0 || $candidate['seed'] > 2147483647
            || ($candidate['voice_design_revision'] ?? null) !== 1
            || ($candidate['style_status'] ?? null) !== 'unverified') {
            return null;
        }
        $seen[$artifact['key']] = true;
        $value[$index]['audio_artifact_id'] = $artifact['value'];
    }

    return $value;
}

function hub_cluster_router_generic_voice_candidate_artifact_index(array $candidates, array $artifacts): ?array
{
    if ($candidates === [] || !array_key_exists('voice_design_revision', $candidates[0])) {
        return [];
    }
    $byId = [];
    foreach ($artifacts as $artifact) {
        $id = is_array($artifact) ? hub_cluster_router_safe_artifact_id($artifact['id'] ?? null) : null;
        if ($id === null || isset($byId[$id['key']])) {
            return null;
        }
        $byId[$id['key']] = $artifact;
    }
    $safe = [];
    foreach ($candidates as $index => $candidate) {
        $id = is_array($candidate) ? hub_cluster_router_safe_artifact_id($candidate['audio_artifact_id'] ?? null) : null;
        $expectedType = $index === 0
            ? 'generated_audio'
            : 'voice_candidate_' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
        $artifact = $id === null ? null : ($byId[$id['key']] ?? null);
        if (!is_array($artifact)
            || array_keys($artifact) !== ['id', 'size_bytes', 'type', 'mime_type', 'sha256']
            || (string)$artifact['id'] !== $id['key']
            || ($artifact['type'] ?? null) !== $expectedType
            || !in_array($artifact['mime_type'] ?? null, ['audio/wav', 'audio/x-wav'], true)
            || !is_int($artifact['size_bytes'] ?? null) || $artifact['size_bytes'] < 1
            || !is_string($artifact['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $artifact['sha256']) !== 1
        ) {
            return null;
        }
        $safe[] = $artifact;
    }

    return $safe;
}

function hub_cluster_router_public_task_result(array $payload, bool $includeMetadata = false): array
{
    $artifacts = hub_cluster_router_result_artifacts($payload, $includeMetadata);
    if ($artifacts === null) {
        throw new UnexpectedValueException('invalid child artifact index');
    }
    $result = $payload['result'] ?? null;
    if (is_array($result) && array_key_exists('candidates', $result)) {
        $candidates = array_keys($result) === ['candidates']
            ? hub_cluster_router_public_voice_preset_candidates($result['candidates'], $artifacts)
            : null;
        $candidates ??= array_keys($result) === ['candidates']
            ? hub_cluster_router_public_generic_voice_candidates($result['candidates'], $artifacts)
            : null;
        if ($candidates === null) {
            throw new UnexpectedValueException('invalid voice candidate result');
        }

        return ['candidates' => $candidates];
    }
    if (is_array($result) && array_key_exists('kind', $result)) {
        $keys = ['kind', 'transcription_status', 'transcript_confirmed', 'text_chars', 'prompt_text_sha256'];
        if (($result['kind'] ?? null) !== 'voice_profile_prepare'
            || count($result) !== count($keys)
            || array_diff(array_keys($result), $keys) !== []
            || !is_string($result['transcription_status'] ?? null)
            || !in_array($result['transcription_status'], ['pending', 'ready', 'failed'], true)
            || !is_bool($result['transcript_confirmed'] ?? null)
            || !is_int($result['text_chars'] ?? null)
            || $result['text_chars'] < 0
            || $result['text_chars'] > 20000
            || !is_string($result['prompt_text_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $result['prompt_text_sha256']) !== 1
        ) {
            throw new UnexpectedValueException('invalid voice profile prepare result');
        }

        return $result;
    }
    if (is_array($result) && ($result['stored_as_artifact'] ?? false) === true) {
        $artifactId = hub_cluster_router_safe_artifact_id($result['artifact_id'] ?? null);
        $known = array_fill_keys(array_map(static fn (array $artifact): string => (string)$artifact['id'], $artifacts), true);
        if ($artifactId !== null && isset($known[$artifactId['key']])) {
            $summary = ['stored_as_artifact' => true, 'artifact_id' => $artifactId['value']];
            if (is_int($result['bytes'] ?? null) && $result['bytes'] >= 0) {
                $summary['bytes'] = $result['bytes'];
            }

            return $summary;
        }
    }

    return ['artifacts' => $artifacts];
}

function hub_cluster_router_voice_profile_async_task_id(array $payload): string
{
    $allowed = [
        'ok',
        'task_id',
        'status',
        'status_url',
        'result_url',
        'log_url',
        'cancel_url',
        'artifact_url_template',
    ];
    $taskId = $payload['task_id'] ?? null;
    if (($payload['ok'] ?? null) !== true
        || array_diff(array_keys($payload), $allowed) !== []
        || (!is_int($taskId) && !is_string($taskId))
        || preg_match('/\A[1-9][0-9]{0,17}\z/', (string)$taskId) !== 1
        || (array_key_exists('status', $payload) && hub_cluster_router_public_task_status($payload['status']) === null)
    ) {
        throw new UnexpectedValueException('invalid voice profile task response');
    }
    foreach (['status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'] as $key) {
        if (array_key_exists($key, $payload)
            && (!is_string($payload[$key])
                || $payload[$key] === ''
                || strlen($payload[$key]) > 8192
                || preg_match('/[\x00-\x1F\x7F]/', $payload[$key]) === 1)
        ) {
            throw new UnexpectedValueException('invalid voice profile task response');
        }
    }

    return (string)$taskId;
}

function hub_cluster_router_public_voice_profile_response(array $payload, bool $includeDraft): array
{
    $rules = [
        'task_status' => static fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-z_]{1,32}\z/', $value) === 1,
        'profile_status' => static fn (mixed $value): bool => is_string($value) && in_array($value, ['active', 'deleted', 'expired'], true),
        'transcription_status' => static fn (mixed $value): bool => is_string($value) && in_array($value, ['pending', 'ready', 'failed'], true),
        'transcription_error' => static fn (mixed $value): bool => $value === null || in_array($value, ['asr_failed', 'asr_unavailable', 'transcript_validation_failed'], true),
        'transcript_confirmed' => 'is_bool',
        'prompt_text_confirmed_at' => static fn (mixed $value): bool => $value === null || (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) === 1),
        'profile_name' => static fn (mixed $value): bool => is_string($value) && strlen($value) <= 120 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1,
        'language' => static fn (mixed $value): bool => $value === null || (is_string($value) && strlen($value) <= 64 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1),
        'consent_type' => static fn (mixed $value): bool => is_string($value) && preg_match('/\A[a-z_]{1,32}\z/', $value) === 1,
        'reference_audio_sha256' => static fn (mixed $value): bool => is_string($value) && ($value === '' || preg_match('/\A[a-f0-9]{64}\z/', $value) === 1),
        'created_at' => static fn (mixed $value): bool => is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) === 1,
        'updated_at' => static fn (mixed $value): bool => is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) === 1,
    ];
    $required = ['ok', ...array_keys($rules)];
    $optional = ['validation', 'transcript', 'expected_text'];
    $allowed = $includeDraft ? [...$required, 'prompt_text', ...$optional] : [...$required, 'validation'];
    if (($payload['ok'] ?? null) !== true
        || array_diff($required, array_keys($payload)) !== []
        || array_diff(array_keys($payload), $allowed) !== []
    ) {
        throw new UnexpectedValueException('invalid voice profile response');
    }
    if (($payload['profile_status'] ?? null) !== 'active') {
        if (!$rules['profile_status']($payload['profile_status'] ?? null)) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $payload = array_replace($payload, [
            'transcription_status' => 'failed',
            'transcription_error' => null,
            'transcript_confirmed' => false,
            'prompt_text_confirmed_at' => null,
            'profile_name' => ucfirst((string)$payload['profile_status']) . ' voice profile',
            'language' => null,
            'reference_audio_sha256' => '',
        ]);
        unset($payload['prompt_text']);
        foreach ($optional as $key) {
            unset($payload[$key]);
        }
    }
    $safe = ['ok' => true];
    foreach ($rules as $key => $valid) {
        if (!$valid($payload[$key])) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $safe[$key] = $payload[$key];
    }
    if ($safe['profile_status'] === 'active'
        && ($safe['reference_audio_sha256'] === ''
            || (($safe['transcription_status'] === 'failed') !== is_string($safe['transcription_error'])))
    ) {
        throw new UnexpectedValueException('invalid voice profile response');
    }
    if (array_key_exists('prompt_text', $payload)) {
        if (!$includeDraft
            || $safe['transcript_confirmed'] !== false
            || !is_string($payload['prompt_text'])
            || strlen($payload['prompt_text']) > 20000
        ) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $safe['prompt_text'] = $payload['prompt_text'];
    }
    if (array_key_exists('validation', $payload)) {
        $validation = $payload['validation'];
        $validationKeys = ['cer', 'status', 'needs_confirmation', 'normalizer'];
        if (is_array($validation) && ($validation['status'] ?? null) === 'error') {
            $validationKeys[] = 'error';
        }
        if (!is_array($validation)
            || array_diff($validationKeys, array_keys($validation)) !== []
            || array_diff(array_keys($validation), $validationKeys) !== []
            || ($validation['cer'] !== null && (!is_int($validation['cer']) && !is_float($validation['cer']) || $validation['cer'] < 0 || !is_finite((float)$validation['cer'])))
            || !is_string($validation['status'])
            || !in_array($validation['status'], ['clean', 'pass', 'review_required', 'unverified', 'error'], true)
            || !is_bool($validation['needs_confirmation'])
            || !is_string($validation['normalizer'])
            || strlen($validation['normalizer']) > 64
            || ($validation['status'] === 'error' && ($validation['error'] ?? null) !== 'transcript_validation_failed')
        ) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $safe['validation'] = [
            'cer' => $validation['cer'],
            'status' => $validation['status'],
            'needs_confirmation' => $validation['needs_confirmation'],
            'normalizer' => $validation['normalizer'],
        ];
        if ($validation['status'] === 'error') {
            $safe['validation']['error'] = $validation['error'];
        }
    }
    if (array_key_exists('transcript', $payload)) {
        $transcript = $payload['transcript'];
        if (!$includeDraft
            || !is_array($transcript)
            || array_diff(['raw', 'normalized'], array_keys($transcript)) !== []
            || array_diff(array_keys($transcript), ['raw', 'normalized']) !== []
            || !is_string($transcript['raw'])
            || !is_string($transcript['normalized'])
            || strlen($transcript['raw']) > 20000
            || strlen($transcript['normalized']) > 20000
        ) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $safe['transcript'] = ['raw' => $transcript['raw'], 'normalized' => $transcript['normalized']];
    }
    if (array_key_exists('expected_text', $payload)) {
        $expectedText = $payload['expected_text'];
        if (!$includeDraft || ($expectedText !== null && (
            !is_array($expectedText)
            || array_diff(['raw', 'normalized'], array_keys($expectedText)) !== []
            || array_diff(array_keys($expectedText), ['raw', 'normalized']) !== []
            || !is_string($expectedText['raw'])
            || !is_string($expectedText['normalized'])
            || strlen($expectedText['raw']) > 20000
            || strlen($expectedText['normalized']) > 20000
        ))) {
            throw new UnexpectedValueException('invalid voice profile response');
        }
        $safe['expected_text'] = $expectedText === null ? null : ['raw' => $expectedText['raw'], 'normalized' => $expectedText['normalized']];
    }

    return $safe;
}

function hub_cluster_router_public_voice_profile_confirmation_response(
    array $payload,
    string $remoteTaskId,
    string $routeId,
    string $promptText
): array {
    $childTaskId = $payload['voice_profile_task_id'] ?? null;
    $promptSha256 = $payload['prompt_text_sha256'] ?? null;
    if (!is_string($childTaskId)
        || preg_match('/\A[1-9][0-9]{0,17}\z/', $childTaskId) !== 1
        || preg_match('/\A[1-9][0-9]{0,17}\z/', $remoteTaskId) !== 1
        || !hash_equals($remoteTaskId, $childTaskId)
        || !is_string($promptSha256)
        || preg_match('/\A[a-f0-9]{64}\z/', $promptSha256) !== 1
        || !hash_equals(hash('sha256', $promptText), $promptSha256)
        || !hub_cluster_router_profile_sensitive_route_id($routeId)
    ) {
        throw new UnexpectedValueException('invalid voice profile confirmation response');
    }
    unset($payload['voice_profile_task_id'], $payload['prompt_text_sha256']);
    $safe = hub_cluster_router_public_voice_profile_response($payload, false);
    if (($safe['profile_status'] ?? null) !== 'active'
        || ($safe['transcript_confirmed'] ?? null) !== true
        || !is_string($safe['prompt_text_confirmed_at'] ?? null)
    ) {
        throw new UnexpectedValueException('invalid voice profile confirmation response');
    }
    $safe['voice_profile_task_id'] = $routeId;
    $safe['prompt_text_sha256'] = $promptSha256;

    return $safe;
}

function hub_cluster_router_public_task_logs(PDO $db, array $route, array $payload, string $remoteTaskId): ?array
{
    $logs = $payload['logs'] ?? null;
    if (!is_array($logs) || !array_is_list($logs)) {
        return null;
    }
    if (hub_cluster_router_profile_sensitive_route_id((string)($route['route_id'] ?? ''))) {
        return [];
    }
    $safe = [];
    foreach (array_slice($logs, 0, 100) as $log) {
        if (!is_array($log)
            || !is_string($log['level'] ?? null)
            || !in_array($log['level'], ['info', 'warning', 'error'], true)
            || !is_string($log['message'] ?? null)
            || !is_string($log['created_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $log['created_at']) !== 1
        ) {
            return null;
        }
        $message = hub_cluster_router_redact_log_message($db, $route, $log['message'], $remoteTaskId);
        $safe[] = [
            'level' => $log['level'],
            'message' => hub_cluster_router_bound_log_message($message),
            'created_at' => $log['created_at'],
        ];
    }

    return $safe;
}

function hub_cluster_router_redact_log_message(PDO $db, array $route, string $message, string $remoteTaskId): string
{
    $station = hub_cluster_get_station($db, (int)($route['station_id'] ?? 0));
    $message = hub_cluster_redact_log_references($message, is_array($station) ? hub_cluster_station_redaction_terms($station) : [], true);
    $message = preg_replace('~(?:[A-Za-z0-9._/-]*/)?data/logs/tasks/task_[^\s]+~i', '[redacted-log]', $message) ?? '';
    if ($remoteTaskId !== '') {
        $message = ctype_digit($remoteTaskId)
            ? str_replace($remoteTaskId, '[redacted-task]', $message)
            : (preg_replace('/(?<![A-Za-z0-9_-])' . preg_quote($remoteTaskId, '/') . '(?![A-Za-z0-9_-])/', '[redacted-task]', $message) ?? '');
    }

    return $message;
}

function hub_cluster_station_redaction_terms(array $station): array
{
    $terms = [];
    foreach (['public_base_url', 'internal_base_url'] as $field) {
        $baseUrl = trim((string)($station[$field] ?? ''));
        try {
            $baseUrl = hub_cluster_validate_station_base_url($baseUrl);
        } catch (Throwable) {
            continue;
        }
        $parts = parse_url($baseUrl);
        $host = is_array($parts) ? trim((string)($parts['host'] ?? ''), '[]') : '';
        if ($host === '') {
            continue;
        }
        $scheme = is_array($parts) ? strtolower((string)($parts['scheme'] ?? '')) : '';
        if (!in_array($scheme, ['http', 'https'], true)) {
            continue;
        }
        $actualPort = is_array($parts) && isset($parts['port']) ? (int)$parts['port'] : 0;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $terms[] = rtrim($baseUrl, '/');
        $terms = array_merge($terms, hub_cluster_authority_redaction_terms($host, $defaultPort, $actualPort, $scheme));
    }

    return hub_cluster_sort_redaction_terms($terms);
}

function hub_cluster_authority_redaction_terms(string $host, int $defaultPort, ?int $actualPort = null, ?string $scheme = null): array
{
    $host = trim($host, '[]');
    $isIpv6 = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
    if ($host === '' || (!$isIp && !hub_cluster_valid_redaction_hostname($host))) {
        return [];
    }
    $authority = $isIpv6 ? '[' . $host . ']' : $host;
    $terms = [$authority];
    if ($scheme !== null && in_array($scheme, ['http', 'https'], true)) {
        $terms[] = $scheme . '://' . $authority;
    }
    foreach (array_unique(array_filter([$actualPort, $defaultPort], static fn (?int $port): bool => is_int($port) && $port > 0 && $port <= 65535)) as $port) {
        $terms[] = $authority . ':' . $port;
    }
    if ($isIpv6) {
        $terms[] = $host;
    } elseif (!$isIp) {
        $host = rtrim($host, '.');
        if ($host !== '') {
            $terms[] = $host;
            $terms[] = $host . '.';
            foreach (array_unique(array_filter([$actualPort, $defaultPort], static fn (?int $port): bool => is_int($port) && $port > 0 && $port <= 65535)) as $port) {
                $terms[] = $host . ':' . $port;
                $terms[] = $host . '.:' . $port;
            }
        }
    }

    return $terms;
}

function hub_cluster_valid_redaction_hostname(string $host): bool
{
    $host = rtrim($host, '.');
    return $host !== '' && strlen($host) <= 253
        && preg_match('/\A(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\z/', $host) === 1;
}

function hub_cluster_sort_redaction_terms(array $terms): array
{
    $terms = array_values(array_unique(array_filter($terms, static fn (mixed $term): bool => is_string($term) && $term !== '')));
    usort($terms, static fn (string $left, string $right): int => (strlen($right) <=> strlen($left)) ?: strcmp($left, $right));

    return $terms;
}

function hub_cluster_child_local_authority_terms(?array $server = null, ?string $hostname = null, ?string $canonicalHost = null): array
{
    $server ??= $_SERVER;
    $https = !empty($server['HTTPS']) && $server['HTTPS'] !== 'off';
    $defaultPort = $https ? 443 : 80;
    $serverPort = hub_cluster_child_local_port($server['SERVER_PORT'] ?? null);
    $terms = [];
    $trusted = [];
    $hostname ??= gethostname() ?: '';
    $canonicalHost ??= trim((string)(getenv('AIHUB_CLUSTER_CANONICAL_HOST') ?: ''));
    foreach ([$server['SERVER_ADDR'] ?? null, $hostname, $canonicalHost] as $value) {
        $authority = hub_cluster_child_local_authority($value);
        if ($authority === null) {
            continue;
        }
        $key = hub_cluster_child_local_authority_key($authority['host']);
        if ($key === null) {
            continue;
        }
        $trusted[$key] = true;
        $terms = array_merge($terms, hub_cluster_authority_redaction_terms(
            $authority['host'],
            $defaultPort,
            $authority['port'] ?? $serverPort
        ));
    }
    $requestAuthority = hub_cluster_child_local_authority($server['HTTP_HOST'] ?? null);
    $requestKey = $requestAuthority === null ? null : hub_cluster_child_local_authority_key($requestAuthority['host']);
    if ($requestAuthority !== null && $requestKey !== null && isset($trusted[$requestKey])) {
        $terms = array_merge($terms, hub_cluster_authority_redaction_terms(
            $requestAuthority['host'],
            $defaultPort,
            $requestAuthority['port'] ?? $serverPort
        ));
    }

    return hub_cluster_sort_redaction_terms($terms);
}

function hub_cluster_child_local_authority_key(string $host): ?string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $packed = inet_pton($host);
        return is_string($packed) ? 'ip:' . bin2hex($packed) : null;
    }
    if (!hub_cluster_valid_redaction_hostname($host)) {
        return null;
    }

    return 'host:' . strtolower(rtrim($host, '.'));
}

function hub_cluster_child_local_authority(mixed $value): ?array
{
    if (!is_scalar($value)) {
        return null;
    }
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    if (filter_var($value, FILTER_VALIDATE_IP)) {
        return ['host' => $value, 'port' => null];
    }
    if (preg_match('/\A\[([0-9A-Fa-f:.]+)\](?::([1-9]\d{0,4}))?\z/', $value, $matches) === 1) {
        $port = isset($matches[2]) ? (int)$matches[2] : null;
        return filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && ($port === null || $port <= 65535)
            ? ['host' => $matches[1], 'port' => $port]
            : null;
    }
    if (preg_match('/\A([A-Za-z0-9][A-Za-z0-9.-]*)(?::([1-9]\d{0,4}))?\z/', $value, $matches) !== 1) {
        return null;
    }
    $port = isset($matches[2]) ? (int)$matches[2] : null;
    return hub_cluster_valid_redaction_hostname($matches[1]) && ($port === null || $port <= 65535)
        ? ['host' => $matches[1], 'port' => $port]
        : null;
}

function hub_cluster_child_local_port(mixed $value): ?int
{
    if (!is_scalar($value) || preg_match('/\A[1-9]\d{0,4}\z/', (string)$value) !== 1) {
        return null;
    }
    $port = (int)$value;

    return $port <= 65535 ? $port : null;
}

function hub_cluster_redact_log_references(string $message, array $terms = [], bool $redactGenericOrigins = false): string
{
    foreach ($terms as $term) {
        if (!is_string($term) || $term === '') {
            continue;
        }
        $quoted = preg_quote($term, '~');
        if (str_contains($term, '://')) {
            $message = preg_replace('~' . $quoted . '(?=[/\\s<>"\']|$)[^\\s<>"\']*~i', '[redacted-url]', $message) ?? '';
        } else {
            $message = preg_replace('~(?<![A-Za-z0-9._:\-\[\]])' . $quoted . '(?![A-Za-z0-9._:\-\[\]])~i', '[redacted-station]', $message) ?? '';
        }
    }
    $message = preg_replace('~https?://[^\s<>"\']+~i', '[redacted-url]', $message) ?? '';
    if ($redactGenericOrigins) {
        $bracketed = preg_replace_callback(
            '~(?<![A-Fa-f0-9:])\[([0-9A-Fa-f:.]+)\](?::\d{1,5})?(?![A-Fa-f0-9:])~',
            static fn (array $matches): string => filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[redacted-ipv6]' : $matches[0],
            $message
        );
        $message = is_string($bracketed) ? $bracketed : '';
        $redacted = preg_replace_callback(
            '~(?<![0-9A-Za-z:.])(?=[0-9A-Fa-f:.]*:)([0-9A-Fa-f:.]{2,})(?![0-9A-Za-z:.])~',
            static function (array $matches): string {
                $candidate = $matches[1];
                $core = rtrim($candidate, '.');
                $punctuation = substr($candidate, strlen($core));

                return filter_var($core, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    ? '[redacted-ipv6]' . $punctuation
                    : $candidate;
            },
            $message
        );
        $message = is_string($redacted) ? $redacted : '';
    }

    return $message;
}

function hub_cluster_router_bound_log_message(string $message): string
{
    if (strlen($message) <= 1024) {
        return $message;
    }
    $message = substr($message, 0, 1024);
    while ($message !== '' && preg_match('//u', $message) !== 1) {
        $message = substr($message, 0, -1);
    }

    return $message;
}

function hub_cluster_router_valid_route_id(string $routeId): bool
{
    return preg_match('/\Aroute_(?:[a-f0-9]{32}|[a-f0-9]{34})\z/', $routeId) === 1;
}

function hub_cluster_router_profile_sensitive_route_id(string $routeId): bool
{
    return preg_match('/\Aroute_[a-f0-9]{34}\z/', $routeId) === 1;
}

function hub_cluster_router_json_payload(array $response): ?array
{
    $body = $response['body'] ?? null;
    if (!is_string($body)) {
        return null;
    }
    try {
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($payload) ? $payload : null;
}

function hub_cluster_router_is_local_proxy_error(array $response): bool
{
    return !empty($response['cluster_router_local_error']);
}

function hub_cluster_router_with_json_payload(array $response, array $payload, bool $freshHeaders = false): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!$freshHeaders) {
        $response['body'] = $body;

        return $response;
    }

    return [
        'status' => (int)($response['status'] ?? 200),
        'headers' => [
            'Content-Type: application/json; charset=utf-8',
            'X-Content-Type-Options: nosniff',
        ],
        'body' => $body,
    ];
}

function hub_cluster_router_is_followup_mode(string $mode): bool
{
    return in_array($mode, ['cluster_task_status', 'cluster_task_result', 'cluster_task_log', 'cluster_task_cancel', 'cluster_artifact', 'cluster_task_artifacts_ack', 'cluster_tts_artifact'], true);
}

function hub_cluster_child_followup_dispatch(PDO $db, array $request = []): array
{
    $query = array_key_exists('query', $request) ? $request['query'] : $_GET;
    $mode = hub_cluster_router_requested_mode(is_array($query) ? ($query['mode'] ?? null) : null);
    if (!in_array($mode, ['task_status', 'task_result', 'task_log', 'task_cancel', 'artifact', 'task_artifacts_ack'], true)) {
        return hub_gateway_error(404, 'unknown_mode', 'mode is not registered');
    }
    $method = strtoupper(trim((string)($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';
    $requiredMethod = in_array($mode, ['task_cancel', 'task_artifacts_ack'], true) ? 'POST' : 'GET';
    if ($method !== $requiredMethod) {
        return hub_gateway_error(405, 'method_not_allowed', 'method is not allowed');
    }
    $clientIp = trim(is_scalar($request['client_ip'] ?? null) ? (string)$request['client_ip'] : hub_get_client_ip()) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $request)
        ? (is_string($request['bearer_token']) ? $request['bearer_token'] : '')
        : hub_bearer_token_from_request();
    $auth = hub_authenticate_api_token($db, $clientIp, $providedToken, 'cluster_status');
    $context = (array)($auth['context'] ?? []);
    $tokenId = (int)($context['token_id'] ?? 0);
    if (empty($auth['ok']) || !hub_cluster_node_enabled($db) || $tokenId !== hub_cluster_node_token_id($db) || !hub_cluster_node_has_verified_router_peer($db, $tokenId, $clientIp)) {
        return hub_gateway_error(403, 'cluster_followup_forbidden', 'cluster followup is not available');
    }
    $taskId = hub_cluster_child_followup_numeric_query_value($query, 'task_id');
    if ($taskId === null) {
        return hub_gateway_error(400, 'bad_request', 'task_id is required');
    }
    $artifactId = in_array($mode, ['artifact', 'task_artifacts_ack'], true) ? hub_cluster_child_followup_numeric_query_value($query, 'artifact_id') : null;
    if (in_array($mode, ['artifact', 'task_artifacts_ack'], true) && $artifactId === null) {
        return hub_gateway_error(400, 'bad_request', 'artifact_id is required');
    }

    return hub_gateway_cluster_child_followup($db, $mode, $taskId, (int)$context['member_id'], $tokenId, $artifactId);
}

function hub_cluster_child_followup_numeric_query_value(mixed $query, string $key): ?int
{
    if (!is_array($query) || !array_key_exists($key, $query) || !is_scalar($query[$key])) {
        return null;
    }
    $value = (string)$query[$key];
    if (preg_match('/\A[1-9]\d{0,17}\z/', $value) !== 1 || (int)$value < 1) {
        return null;
    }

    return (int)$value;
}

function hub_cluster_child_tts_artifact_path(array $service, string $file): ?string
{
    if ((string)($service['pack_id'] ?? '') !== 'tts-voxcpm2' || hub_cluster_tts_artifact_filename($file) === null) {
        return null;
    }
    $runtimeDir = dirname(hub_path((string)($service['compose_file'] ?? '')));
    $base = realpath($runtimeDir . '/artifacts');
    $path = $base === false ? false : realpath($base . '/' . $file);
    if ($base === false || $path === false || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }

    return $path;
}

function hub_cluster_child_tts_artifact_dispatch(PDO $db, array $request = []): array
{
    $query = array_key_exists('query', $request) ? $request['query'] : $_GET;
    $method = strtoupper(trim((string)($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';
    if ($method !== 'GET') {
        return hub_gateway_error(405, 'method_not_allowed', 'method is not allowed');
    }
    $clientIp = trim(is_scalar($request['client_ip'] ?? null) ? (string)$request['client_ip'] : hub_get_client_ip()) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $request)
        ? (is_string($request['bearer_token']) ? $request['bearer_token'] : '')
        : hub_bearer_token_from_request();
    $auth = hub_authenticate_api_token($db, $clientIp, $providedToken, 'cluster_status');
    $context = (array)($auth['context'] ?? []);
    $tokenId = (int)($context['token_id'] ?? 0);
    if (empty($auth['ok']) || !hub_cluster_node_enabled($db) || $tokenId !== hub_cluster_node_token_id($db)) {
        return hub_gateway_error(403, 'cluster_followup_forbidden', 'cluster followup is not available');
    }
    $file = is_array($query) && array_key_exists('file', $query) && is_scalar($query['file'])
        ? hub_cluster_tts_artifact_filename((string)$query['file'])
        : null;
    if ($file === null) {
        return hub_gateway_error(404, 'artifact_not_found', 'artifact was not found');
    }
    $service = hub_get_service_by_mode($db, 'tts');
    $path = $service === null ? null : hub_cluster_child_tts_artifact_path($service, $file);
    if ($path === null) {
        return hub_gateway_error(404, 'artifact_not_found', 'artifact was not found');
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false || $size < 0 || $size > hub_cluster_proxy_response_limit_bytes()) {
        return hub_gateway_error(404, 'artifact_not_found', 'artifact was not found');
    }
    $body = @file_get_contents($path);
    if (!is_string($body) || strlen($body) !== $size) {
        return hub_gateway_error(404, 'artifact_not_found', 'artifact was not found');
    }

    return [
        'status' => 200,
        'headers' => [
            'Content-Type: ' . (str_ends_with($file, '.wav') ? 'audio/wav' : 'application/json; charset=utf-8'),
            'X-Content-Type-Options: nosniff',
        ],
        'body' => $body,
    ];
}

function hub_cluster_child_project_task_logs(array $logs, int $taskId): array
{
    $safe = [];
    foreach (array_slice($logs, 0, 100) as $log) {
        if (!is_array($log)
            || !is_string($log['level'] ?? null)
            || !in_array($log['level'], ['info', 'warning', 'error'], true)
            || !is_string($log['message'] ?? null)
            || !is_string($log['created_at'] ?? null)
            || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $log['created_at']) !== 1
        ) {
            continue;
        }
        $message = preg_replace('~(?:[A-Za-z0-9._/-]*/)?data/logs/tasks/task_[^\s]+~i', '[redacted-log]', $log['message']) ?? '';
        $message = hub_cluster_redact_log_references($message, hub_cluster_child_local_authority_terms(), true);
        $message = preg_replace('~(?<![A-Za-z0-9.-])(?:[A-Za-z0-9.-]+|(?:\d{1,3}\.){3}\d{1,3}):\d{1,5}(?![A-Za-z0-9.-])~', '[redacted-host]', $message) ?? '';
        $message = str_replace((string)$taskId, '[redacted-task]', $message);
        $safe[] = [
            'level' => $log['level'],
            'message' => hub_cluster_router_bound_log_message($message),
            'created_at' => $log['created_at'],
        ];
    }

    return $safe;
}

function hub_cluster_get_route_for_customer(PDO $db, string $routeId, array $auth): ?array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    $tokenId = (int)($auth['token_id'] ?? 0);
    if (!hub_cluster_router_valid_route_id($routeId) || $memberId < 1 || $tokenId < 1) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT * FROM cluster_routes
         WHERE route_id = :route_id AND member_id = :member_id AND token_id = :token_id AND is_async = 1
         LIMIT 1'
    );
    $stmt->execute([':route_id' => $routeId, ':member_id' => $memberId, ':token_id' => $tokenId]);
    $route = $stmt->fetch();

    return $route === false ? null : $route;
}

function hub_cluster_get_voice_profile_route_for_member(PDO $db, string $routeId, array $auth, string $mode = 'voice_generate'): ?array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    if (!hub_cluster_router_profile_sensitive_route_id($routeId) || $memberId < 1 || !hub_is_voice_profile_mode($mode)) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT * FROM cluster_routes
         WHERE route_id = :route_id AND member_id = :member_id
           AND mode = :mode AND route_role = 'profile_prepare'
           AND is_async = 1 AND state = 'succeeded'
         LIMIT 1"
    );
    $stmt->execute([':route_id' => $routeId, ':member_id' => $memberId, ':mode' => $mode]);
    $route = $stmt->fetch();

    return $route === false ? null : $route;
}

function hub_cluster_get_tts_artifact_route_for_customer(PDO $db, string $routeId, array $auth): ?array
{
    $memberId = (int)($auth['member_id'] ?? 0);
    $tokenId = (int)($auth['token_id'] ?? 0);
    if (!hub_cluster_router_valid_route_id($routeId) || $memberId < 1 || $tokenId < 1) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT * FROM cluster_routes
         WHERE route_id = :route_id AND member_id = :member_id AND token_id = :token_id
           AND mode = 'tts' AND is_async = 0
         LIMIT 1"
    );
    $stmt->execute([':route_id' => $routeId, ':member_id' => $memberId, ':token_id' => $tokenId]);
    $route = $stmt->fetch();

    return $route === false ? null : $route;
}

function hub_cluster_dispatch_followup(PDO $db, string $routerMode, array $request = [], ?callable $requester = null): array
{
    $requestId = hub_new_request_id();
    $started = microtime(true);
    $finish = static fn (array $response): array => hub_cluster_router_finish_response($response, $requestId);
    if (!hub_cluster_router_is_followup_mode($routerMode)) {
        return $finish(hub_gateway_error(404, 'unknown_mode', 'mode is not registered'));
    }
    $method = strtoupper(trim((string)($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';
    if ($routerMode === 'cluster_task_artifacts_ack' && $method !== 'POST') {
        return $finish(hub_gateway_error(405, 'method_not_allowed', 'method is not allowed'));
    }
    $clientIp = trim(is_scalar($request['client_ip'] ?? null) ? (string)$request['client_ip'] : hub_get_client_ip()) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $request)
        ? (is_string($request['bearer_token']) ? $request['bearer_token'] : '')
        : hub_bearer_token_from_request();
    $auth = hub_authenticate_api_token($db, $clientIp, $providedToken);
    if (empty($auth['ok'])) {
        return $finish($auth['response']);
    }
    $ttsArtifact = $routerMode === 'cluster_tts_artifact'
        ? hub_cluster_tts_artifact_filename(hub_cluster_router_followup_query_value($request, 'file'))
        : null;
    $routeId = hub_cluster_router_followup_query_value($request, $routerMode === 'cluster_tts_artifact' ? 'route_id' : 'task_id');
    $route = $routeId === null
        ? null
        : ($routerMode === 'cluster_tts_artifact'
            ? hub_cluster_get_tts_artifact_route_for_customer($db, $routeId, (array)$auth['context'])
            : hub_cluster_get_route_for_customer($db, $routeId, (array)$auth['context']));
    if ($route === null) {
        return $finish(hub_gateway_error(404, 'route_not_found', 'cluster route was not found'));
    }
    if ($routerMode === 'cluster_task_artifacts_ack'
        && !hub_cluster_router_rich_artifact_mode((string)($route['mode'] ?? ''))
    ) {
        return $finish(hub_gateway_error(404, 'route_not_found', 'cluster route was not found'));
    }
    $complete = static function (array $response, ?string $terminalState = null, bool $direct = false) use ($db, $route, $auth, $routerMode, $requestId, $started, $finish): array {
        hub_cluster_router_complete_followup(
            $db,
            $route,
            (array)$auth['context'],
            $routerMode,
            $direct,
            $response,
            $requestId,
            (int)round((microtime(true) - $started) * 1000),
            $terminalState
        );

        return $finish($response);
    };
    $remoteArtifactId = null;
    if (in_array($routerMode, ['cluster_artifact', 'cluster_task_artifacts_ack'], true)) {
        $remoteArtifactId = hub_cluster_router_followup_query_value($request, 'artifact_id');
        if ($remoteArtifactId === null || !hub_cluster_router_route_has_artifact($db, (string)$route['route_id'], $remoteArtifactId)) {
            return $complete(hub_gateway_error(404, 'artifact_not_found', 'artifact was not found'));
        }
    } elseif ($routerMode === 'cluster_tts_artifact' && ($ttsArtifact === null || !hub_cluster_router_route_has_artifact($db, (string)$route['route_id'], $ttsArtifact))) {
        return $complete(hub_gateway_error(404, 'artifact_not_found', 'artifact was not found'));
    }
    $station = hub_cluster_get_station($db, (int)$route['station_id']);
    if ($station === null || empty($station['enabled'])) {
        return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
    }
    try {
        $stationToken = hub_cluster_station_token($station);
    } catch (Throwable) {
        return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'), null, hub_cluster_router_station_is_self($db, $station));
    }
    [$mode, $method] = match ($routerMode) {
        'cluster_task_status' => ['task_status', 'GET'],
        'cluster_task_result' => ['task_result', 'GET'],
        'cluster_task_log' => ['task_log', 'GET'],
        'cluster_task_cancel' => ['task_cancel', 'POST'],
        'cluster_artifact' => ['artifact', 'GET'],
        'cluster_task_artifacts_ack' => ['task_artifacts_ack', 'POST'],
        'cluster_tts_artifact' => ['tts_artifact', 'GET'],
    };
    $query = match ($routerMode) {
        'cluster_artifact', 'cluster_task_artifacts_ack' => ['mode' => $mode, 'task_id' => (string)$route['remote_task_id'], 'artifact_id' => $remoteArtifactId],
        'cluster_tts_artifact' => ['file' => $ttsArtifact],
        default => ['mode' => $mode, 'task_id' => (string)$route['remote_task_id']],
    };
    $profileSensitiveArtifact = $routerMode === 'cluster_artifact'
        && hub_cluster_router_profile_sensitive_route_id((string)($route['route_id'] ?? ''));
    $selfStation = hub_cluster_router_station_is_self($db, $station);
    if ($selfStation) {
        $selfPeerIp = hub_cluster_router_self_station_peer_ip($db, $station, $stationToken);
        if ($selfPeerIp === null) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'), null, true);
        }
        try {
            $response = $routerMode === 'cluster_tts_artifact'
                ? hub_cluster_child_tts_artifact_dispatch($db, [
                    'bearer_token' => $stationToken,
                    'client_ip' => $selfPeerIp,
                    'method' => $method,
                    'query' => $query,
                ])
                : hub_cluster_router_direct_followup($db, $mode, $method, $query, $stationToken, $selfPeerIp);
        } catch (Throwable) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'), null, true);
        }
    } else {
        try {
            $stationUrl = hub_cluster_station_request_base_url($station) . ($routerMode === 'cluster_tts_artifact' ? 'cluster_tts_artifact.php' : 'cluster_followup.php');
            $transport = $requester ?? 'hub_cluster_proxy_transport';
            $rawResponse = $transport([
                'url' => $stationUrl,
                'query' => $query,
                'method' => $method,
                'headers' => ['Authorization' => 'Bearer ' . $stationToken] + hub_cluster_router_safe_request_headers($request),
                'body' => '',
                'follow_redirects' => false,
                'response_limit_bytes' => hub_cluster_proxy_response_limit_bytes(),
            ]);
            if (!is_array($rawResponse) || array_key_exists('error', $rawResponse)) {
                return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
            }
            $response = hub_cluster_router_proxy_response($rawResponse, $stationToken, $profileSensitiveArtifact);
        } catch (Throwable) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
        }
    }
    $payload = hub_cluster_router_json_payload($response);
    if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
        if (hub_cluster_router_is_local_proxy_error($response)) {
            return $complete($response, null, $selfStation);
        }
        if ($profileSensitiveArtifact) {
            $relayed = hub_cluster_voice_generate_relay_response($response, $payload);
            if ($relayed !== null) {
                return $complete($relayed, null, $selfStation);
            }
        }
        return $complete(hub_gateway_error(502, 'router_response_failed', 'cluster station response failed'), null, $selfStation);
    }
    if (in_array($routerMode, ['cluster_artifact', 'cluster_tts_artifact'], true)) {
        if ($selfStation && $profileSensitiveArtifact) {
            $response = hub_cluster_router_rebuild_profile_artifact_response($response);
        }
        $response['preserve_body'] = true;
        return $complete($response, null, $selfStation);
    }
    if (!is_array($payload) || !hub_cluster_router_followup_task_matches($route, $payload)) {
        return $complete(hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid'), null, $selfStation);
    }
    $terminalState = hub_cluster_router_terminal_state($routerMode, $payload);
    if ($routerMode === 'cluster_task_result') {
        if (hub_cluster_router_result_artifacts($payload) === null) {
            return $complete(hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid'), null, $selfStation);
        }
        hub_cluster_sync_route_artifacts($db, $route, $payload);
    }
    try {
        $payload = hub_cluster_router_rewrite_task_payload(
            $db,
            $route,
            $payload,
            hub_cluster_router_api_base_url(),
            (string)$route['remote_task_id'],
            match ($routerMode) {
                'cluster_task_status' => 'status',
                'cluster_task_result' => 'result',
                'cluster_task_log' => 'log',
                'cluster_task_cancel' => 'cancel',
                default => 'artifact',
            }
        );
    } catch (Throwable) {
        return $complete(hub_gateway_error(502, 'router_response_invalid', 'cluster station response is invalid'), null, $selfStation);
    }
    $response = hub_cluster_router_with_json_payload(
        $response,
        $payload,
        hub_cluster_router_profile_sensitive_route_id((string)($route['route_id'] ?? ''))
    );

    return $complete($response, $terminalState, $selfStation);
}

function hub_cluster_router_followup_task_matches(array $route, array $payload): bool
{
    $expected = (string)($route['remote_task_id'] ?? '');
    $actual = $payload['task_id'] ?? null;
    if ($expected === '' || (!is_int($actual) && !is_string($actual))) {
        return false;
    }

    return hash_equals($expected, (string)$actual);
}

function hub_cluster_router_followup_query_value(array $request, string $key): ?string
{
    $query = array_key_exists('query', $request) ? $request['query'] : ($_GET ?? []);
    if (!is_array($query) || !array_key_exists($key, $query) || !is_scalar($query[$key])) {
        return null;
    }
    $value = (string)$query[$key];

    return $value !== '' && strlen($value) <= 1024 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 ? $value : null;
}

function hub_cluster_router_route_has_artifact(PDO $db, string $routeId, string $remoteArtifactId): bool
{
    $stmt = $db->prepare(
        'SELECT 1 FROM cluster_route_artifacts WHERE route_id = :route_id AND remote_artifact_id = :remote_artifact_id LIMIT 1'
    );
    $stmt->execute([':route_id' => $routeId, ':remote_artifact_id' => $remoteArtifactId]);

    return $stmt->fetchColumn() !== false;
}

function hub_cluster_sync_route_artifacts(PDO $db, array $route, array $payload): void
{
    $routeId = (string)($route['route_id'] ?? '');
    if (!hub_cluster_router_valid_route_id($routeId)) {
        return;
    }
    $artifacts = hub_cluster_router_result_artifacts($payload);
    if ($artifacts === null || $artifacts === []) {
        return;
    }
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO cluster_route_artifacts (route_id, remote_artifact_id, created_at)
         VALUES (:route_id, :remote_artifact_id, :created_at)'
    );
    $now = hub_now();
    foreach ($artifacts as $artifact) {
        $stmt->execute([':route_id' => $routeId, ':remote_artifact_id' => (string)$artifact['id'], ':created_at' => $now]);
    }
}

function hub_cluster_router_direct_followup(PDO $db, string $mode, string $method, array $query, string $stationToken, string $clientIp): array
{
    return hub_cluster_child_followup_dispatch($db, [
        'bearer_token' => $stationToken,
        'client_ip' => $clientIp,
        'method' => $method,
        'query' => $query,
    ]);
}

function hub_cluster_router_terminal_state(string $routerMode, ?array $payload): ?string
{
    $status = is_scalar($payload['status'] ?? null) ? strtolower((string)$payload['status']) : '';
    return match ($status) {
        'success', 'succeeded', 'completed' => 'succeeded',
        'failed', 'failure', 'error' => 'failed',
        'cancelled', 'canceled' => 'cancelled',
        'timed_out', 'timeout' => 'timed_out',
        default => $routerMode === 'cluster_task_result' && !empty($payload['ok']) ? 'succeeded' : null,
    };
}

function hub_cluster_router_complete_followup(
    PDO $db,
    array $route,
    array $authContext,
    string $routerMode,
    bool $direct,
    array $response,
    string $requestId,
    int $elapsedMs,
    ?string $terminalState
): void {
    try {
        $status = (int)($response['status'] ?? 502);
        [$errorCode] = $status >= 400 ? hub_gateway_response_error($response) : [null];
        if (!is_string($errorCode) || preg_match('/\A[a-z0-9_-]{1,64}\z/i', $errorCode) !== 1) {
            $errorCode = null;
        }
        $now = hub_now();
        if ($terminalState !== null) {
            $db->prepare(
                'UPDATE cluster_routes
                 SET state = :state, remote_status = :remote_status, updated_at = :updated_at, completed_at = :completed_at
                 WHERE route_id = :route_id'
            )->execute([
                ':state' => $terminalState,
                ':remote_status' => (string)$status,
                ':updated_at' => $now,
                ':completed_at' => $now,
                ':route_id' => (string)$route['route_id'],
            ]);
        }
        $db->prepare(
            'INSERT INTO cluster_route_accesses
                (route_id, station_id, member_id, token_id, mode, access_kind, request_id, status_code, ok, error_code, elapsed_ms, upload_bytes, response_bytes, created_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :access_kind, :request_id, :status_code, :ok, :error_code, :elapsed_ms, 0, :response_bytes, :created_at)'
        )->execute([
            ':route_id' => (string)$route['route_id'],
            ':station_id' => (int)$route['station_id'],
            ':member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
            ':token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
            ':mode' => $routerMode,
            ':access_kind' => $direct ? 'direct' : 'proxy',
            ':request_id' => $requestId,
            ':status_code' => $status,
            ':ok' => $status >= 200 && $status < 400 ? 1 : 0,
            ':error_code' => $errorCode,
            ':elapsed_ms' => max(0, $elapsedMs),
            ':response_bytes' => hub_gateway_response_output_bytes($response),
            ':created_at' => $now,
        ]);
    } catch (Throwable) {
        // Follow-up accounting must not replace the customer response.
    }
}

function hub_cluster_router_read_request_body(array $request): array
{
    $limit = hub_cluster_proxy_request_limit_bytes();
    $contentLength = array_key_exists('content_length', $request) ? $request['content_length'] : ($_SERVER['CONTENT_LENGTH'] ?? '');
    if (hub_cluster_router_content_length_exceeds($contentLength, $limit)) {
        return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
    }
    if (array_key_exists('raw_body', $request)) {
        if (!is_string($request['raw_body'])) {
            return ['response' => hub_gateway_error(400, 'bad_request', 'invalid request body')];
        }
        if (strlen($request['raw_body']) > $limit) {
            return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
        }

        return ['body' => $request['raw_body']];
    }
    $providedStream = array_key_exists('body_stream', $request);
    $stream = $providedStream ? $request['body_stream'] : @fopen('php://input', 'rb');
    if (!is_resource($stream)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'invalid request body')];
    }
    $body = '';
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, min(8192, $limit - strlen($body) + 1));
            if ($chunk === false || ($chunk === '' && !feof($stream))) {
                return ['response' => hub_gateway_error(400, 'bad_request', 'invalid request body')];
            }
            if (strlen($body) + strlen($chunk) > $limit) {
                return ['response' => hub_gateway_error(413, 'router_request_too_large', 'request body is too large for the cluster router')];
            }
            $body .= $chunk;
        }
    } finally {
        if (!$providedStream) {
            fclose($stream);
        }
    }

    return ['body' => $body];
}

function hub_cluster_router_content_length_exceeds(mixed $value, int $limit): bool
{
    if (is_int($value)) {
        return $value > $limit;
    }
    if (!is_string($value) || !ctype_digit(trim($value))) {
        return false;
    }
    $value = ltrim(trim($value), '0');
    if ($value === '') {
        return false;
    }
    $limit = (string)$limit;

    return strlen($value) > strlen($limit) || (strlen($value) === strlen($limit) && $value > $limit);
}

function hub_cluster_router_safe_request_headers(array $request): array
{
    $source = array_key_exists('headers', $request)
        ? $request['headers']
        : [
            'Content-Type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'Accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
        ];
    if (!is_array($source)) {
        return [];
    }
    $headers = [];
    foreach ($source as $name => $value) {
        if (!is_string($name) || !is_scalar($value)) {
            continue;
        }
        $canonical = match (strtolower(trim($name))) {
            'content-type' => 'Content-Type',
            'accept' => 'Accept',
            default => null,
        };
        $value = trim((string)$value);
        if ($canonical !== null && $value !== '' && strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            $headers[$canonical] = $value;
        }
    }

    return $headers;
}

function hub_cluster_router_station_is_self(PDO $db, array $station): bool
{
    $selfKey = trim(hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY'));

    return preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/i', $selfKey) === 1
        && hash_equals($selfKey, (string)($station['station_key'] ?? ''));
}

function hub_cluster_router_self_station_peer_ip(PDO $db, array $station, string $stationToken): ?string
{
    $tokenId = hub_cluster_node_token_id($db);
    if (!hub_cluster_router_station_is_self($db, $station) || $tokenId < 1) {
        return null;
    }
    try {
        if (!hash_equals(hub_cluster_node_reveal_token($db), $stationToken)) {
            return null;
        }
    } catch (Throwable) {
        return null;
    }
    $rules = hub_enabled_api_token_ip_rules($db, $tokenId);
    $peerIp = $rules[0]['ip_rule'] ?? null;
    if (!is_string($peerIp) || !hub_cluster_node_has_verified_router_peer($db, $tokenId, $peerIp)) {
        return null;
    }

    return $peerIp;
}

function hub_cluster_router_admit_route(
    PDO $db,
    array $station,
    array $authContext,
    string $mode,
    bool $proxying,
    bool $profileSensitive = false,
    string $routeRole = 'task'
): ?string
{
    $stationId = (int)($station['id'] ?? 0);
    $routeId = hub_cluster_router_route_id($profileSensitive);
    if ($stationId < 1 || $routeId === '' || !in_array($routeRole, ['task', 'profile_prepare'], true)) {
        return null;
    }
    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        // ponytail: local SQLite admission; 60-second proxy timeout plus 30-second grace, use shared coordination only when routers span databases.
        if ($proxying) {
            hub_cluster_router_reap_expired_proxy_routes($db, hub_now());
        }
        if ($proxying && (int)$db->query("SELECT COUNT(*) FROM cluster_routes WHERE state = 'proxying'")->fetchColumn() >= hub_cluster_proxy_transfer_limit()) {
            $db->exec('COMMIT');
            return null;
        }
        $now = hub_now();
        $db->prepare(
            'INSERT INTO cluster_routes
                (route_id, station_id, member_id, token_id, mode, route_role, is_async, state, created_at, updated_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :route_role, 0, :state, :created_at, :updated_at)'
        )->execute([
            ':route_id' => $routeId,
            ':station_id' => $stationId,
            ':member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
            ':token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
            ':mode' => $mode,
            ':route_role' => $routeRole,
            ':state' => $proxying ? 'proxying' : 'dispatching',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $db->exec('COMMIT');

        return $routeId;
    } catch (Throwable) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }

        return null;
    }
}

function hub_cluster_router_route_id(bool $profileSensitive = false): string
{
    try {
        return 'route_' . bin2hex(random_bytes($profileSensitive ? 17 : 16));
    } catch (Throwable) {
        return '';
    }
}

function hub_cluster_proxy_transfer_limit(): int
{
    $value = getenv('AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS');

    return is_string($value) && ctype_digit($value) && (int)$value >= 1 && (int)$value <= 1024 ? (int)$value : 8;
}

function hub_cluster_proxy_response_limit_bytes(): int
{
    $value = getenv('AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB');
    $megabytes = is_string($value) && ctype_digit($value) && (int)$value >= 1 && (int)$value <= 1024 ? (int)$value : 64;

    return $megabytes * 1024 * 1024;
}

function hub_cluster_proxy_request_limit_bytes(): int
{
    $value = getenv('AIHUB_CLUSTER_ROUTER_MAX_REQUEST_MB');
    $megabytes = is_string($value) && ctype_digit($value) && (int)$value >= 1 && (int)$value <= 1024 ? (int)$value : 64;

    return $megabytes * 1024 * 1024;
}

function hub_cluster_proxy_timeout_sec(?string $mode = null): int
{
    return match ($mode) {
        'photo' => 600,
        'asr', 'tts' => 210,
        default => 60,
    };
}

function hub_cluster_proxy_stale_after_seconds(?string $mode = null): int
{
    return hub_cluster_proxy_timeout_sec($mode ?? 'tts') + 30;
}

function hub_cluster_router_reap_expired_proxy_routes(PDO $db, string $now): void
{
    $photoCutoff = date('Y-m-d H:i:s', strtotime($now) - hub_cluster_proxy_stale_after_seconds('photo'));
    $audioCutoff = date('Y-m-d H:i:s', strtotime($now) - hub_cluster_proxy_stale_after_seconds('tts'));
    $defaultCutoff = date('Y-m-d H:i:s', strtotime($now) - (hub_cluster_proxy_timeout_sec() + 30));
    $db->prepare(
        "UPDATE cluster_routes
         SET state = 'failed', remote_status = 'router_timeout', updated_at = :updated_at, completed_at = :completed_at
         WHERE state = 'proxying' AND (
             (mode = 'photo' AND updated_at < :photo_cutoff)
             OR (mode IN ('asr', 'tts') AND updated_at < :audio_cutoff)
             OR (mode NOT IN ('photo', 'asr', 'tts') AND updated_at < :default_cutoff)
         )"
    )->execute([
        ':updated_at' => $now,
        ':completed_at' => $now,
        ':photo_cutoff' => $photoCutoff,
        ':audio_cutoff' => $audioCutoff,
        ':default_cutoff' => $defaultCutoff,
    ]);
}

function hub_cluster_router_complete_route(
    PDO $db,
    string $routeId,
    array $station,
    array $authContext,
    string $mode,
    bool $proxying,
    array $response,
    string $requestId,
    int $elapsedMs,
    int $uploadBytes
): void {
    try {
        $status = (int)($response['status'] ?? 502);
        [$errorCode] = $status >= 400 ? hub_gateway_response_error($response) : [null];
        if (!is_string($errorCode) || preg_match('/\A[a-z0-9_-]{1,64}\z/i', $errorCode) !== 1) {
            $errorCode = null;
        }
        $now = hub_now();
        $ok = $status >= 200 && $status < 400;
        $db->prepare(
            "UPDATE cluster_routes
             SET state = CASE WHEN is_async = 1 AND remote_task_id IS NOT NULL AND CAST(:ok AS INTEGER) = 1 THEN 'active' WHEN CAST(:ok AS INTEGER) = 1 THEN 'completed' ELSE 'failed' END,
                 remote_status = :remote_status, updated_at = :updated_at,
                 completed_at = CASE WHEN is_async = 1 AND remote_task_id IS NOT NULL AND CAST(:ok AS INTEGER) = 1 THEN NULL ELSE :completed_at END
             WHERE route_id = :route_id"
        )->execute([
            ':ok' => $ok ? 1 : 0,
            ':remote_status' => (string)$status,
            ':updated_at' => $now,
            ':completed_at' => $now,
            ':route_id' => $routeId,
        ]);
        $db->prepare(
            'INSERT INTO cluster_route_accesses
                (route_id, station_id, member_id, token_id, mode, access_kind, request_id, status_code, ok, error_code, elapsed_ms, upload_bytes, response_bytes, created_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :access_kind, :request_id, :status_code, :ok, :error_code, :elapsed_ms, :upload_bytes, :response_bytes, :created_at)'
        )->execute([
            ':route_id' => $routeId,
            ':station_id' => (int)$station['id'],
            ':member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
            ':token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
            ':mode' => $mode,
            ':access_kind' => 'submit',
            ':request_id' => $requestId,
            ':status_code' => $status,
            ':ok' => $status >= 200 && $status < 400 ? 1 : 0,
            ':error_code' => $errorCode,
            ':elapsed_ms' => max(0, $elapsedMs),
            ':upload_bytes' => max(0, $uploadBytes),
            ':response_bytes' => hub_gateway_response_output_bytes($response),
            ':created_at' => $now,
        ]);
    } catch (Throwable) {
        // Route accounting must not replace a completed customer response.
    }
}

function hub_cluster_router_proxy_response(mixed $response, string $stationToken, bool $profileSensitiveArtifact = false): array
{
    if (!is_array($response)) {
        return hub_cluster_router_local_proxy_error(502, 'router_proxy_failed', 'cluster station request failed');
    }
    if (($response['error'] ?? null) === 'timeout') {
        return hub_cluster_router_local_proxy_error(504, 'router_timeout', 'cluster station did not respond in time');
    }
    if (!empty($response['too_large'])) {
        return hub_cluster_router_local_proxy_error(502, 'router_response_too_large', 'cluster station response is too large');
    }
    $status = (int)($response['status'] ?? 0);
    $body = $response['body'] ?? null;
    if ($status < 100 || $status > 599 || !is_string($body)) {
        return hub_cluster_router_local_proxy_error(502, 'router_proxy_failed', 'cluster station request failed');
    }
    if (strlen($body) > hub_cluster_proxy_response_limit_bytes()) {
        return hub_cluster_router_local_proxy_error(502, 'router_response_too_large', 'cluster station response is too large');
    }
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    $rawHeaders = is_string($response['raw_headers'] ?? null)
        ? $response['raw_headers']
        : 'HTTP/1.1 ' . $status . "\r\n" . implode("\r\n", array_filter($headers, 'is_string'));
    $contentType = hub_cluster_router_response_content_type($rawHeaders, $headers);
    $safeHeaders = $profileSensitiveArtifact
        ? hub_cluster_router_profile_artifact_headers($rawHeaders, $headers, strlen($body))
        : hub_proxy_allowed_response_headers($rawHeaders, $contentType);
    if (str_contains($body, $stationToken) || array_filter($safeHeaders, static fn (string $header): bool => str_contains($header, $stationToken)) !== []) {
        return hub_cluster_router_local_proxy_error(502, 'router_proxy_failed', 'cluster station request failed');
    }

    return ['status' => $status, 'headers' => $safeHeaders, 'body' => $body];
}

function hub_cluster_router_rebuild_profile_artifact_response(array $response): array
{
    $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
    $status = (int)($response['status'] ?? 200);
    $rawHeaders = 'HTTP/1.1 ' . $status . "\r\n" . implode("\r\n", array_filter($headers, 'is_string'));
    $contentLength = is_int($response['stream_size'] ?? null) && $response['stream_size'] >= 0
        ? $response['stream_size']
        : strlen(is_string($response['body'] ?? null) ? $response['body'] : '');
    $response['headers'] = hub_cluster_router_profile_artifact_headers($rawHeaders, $headers, $contentLength);

    return $response;
}

function hub_cluster_router_profile_artifact_headers(string $rawHeaders, array $headers, int $contentLength): array
{
    $contentType = explode(';', hub_cluster_router_response_content_type($rawHeaders, $headers), 2)[0];
    $contentType = strtolower(trim($contentType));
    if (!in_array($contentType, [
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'application/json',
        'application/octet-stream',
    ], true)) {
        $contentType = 'application/octet-stream';
    }
    $safeHeaders = [
        'Content-Type: ' . $contentType,
        'Content-Length: ' . max(0, $contentLength),
    ];
    foreach ([hub_cluster_router_final_response_headers($rawHeaders), $headers] as $source) {
        foreach ($source as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            if (strtolower(trim($name)) !== 'content-disposition') {
                continue;
            }
            $disposition = strtolower(trim(explode(';', $value, 2)[0]));
            if (in_array($disposition, ['attachment', 'inline'], true)) {
                $safeHeaders[] = 'Content-Disposition: ' . $disposition;
            }
            break 2;
        }
    }
    $safeHeaders[] = 'X-Content-Type-Options: nosniff';

    return $safeHeaders;
}

function hub_cluster_router_local_proxy_error(int $status, string $code, string $message): array
{
    $response = hub_gateway_error($status, $code, $message);
    $response['cluster_router_local_error'] = true;
    return $response;
}

function hub_cluster_router_response_content_type(string $rawHeaders, array $headers): string
{
    foreach ([hub_cluster_router_final_response_headers($rawHeaders), $headers] as $source) {
        foreach ($source as $header) {
            if (!is_string($header) || !str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $value = trim($value);
            if (strtolower(trim($name)) === 'content-type' && strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
                return $value;
            }
        }
    }

    return '';
}

function hub_cluster_router_final_response_headers(string $rawHeaders): array
{
    $blocks = preg_split('/\n\n+/', trim(str_replace("\r\n", "\n", $rawHeaders))) ?: [];
    foreach (array_reverse($blocks) as $block) {
        if (preg_match('/^HTTP\/\S+\s+\d{3}(?:\s|$)/', $block) === 1) {
            return array_values(array_filter(preg_split('/\n/', $block) ?: [], static fn (string $line): bool => str_contains($line, ':')));
        }
    }

    return [];
}

function hub_cluster_proxy_transport(array $request): array
{
    if (!function_exists('curl_init')) {
        return ['error' => 'proxy'];
    }
    $url = (string)($request['url'] ?? '');
    $query = is_array($request['query'] ?? null) ? $request['query'] : [];
    if ($url === '' || (!str_ends_with($url, '/api.php') && !str_ends_with($url, '/cluster_followup.php') && !str_ends_with($url, '/cluster_tts_artifact.php'))) {
        return ['error' => 'proxy'];
    }
    $target = $url . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    $handle = curl_init($target);
    if ($handle === false) {
        return ['error' => 'proxy'];
    }
    $form = is_array($request['form'] ?? null) ? $request['form'] : null;
    $headers = [];
    foreach (['Authorization', 'Content-Type', 'Accept'] as $name) {
        if ($form !== null && $name === 'Content-Type') {
            continue;
        }
        $value = $request['headers'][$name] ?? null;
        if (is_string($value) && $value !== '' && strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            $headers[] = $name . ': ' . $value;
        }
    }
    $method = (string)($request['method'] ?? 'GET');
    $body = is_string($request['body'] ?? null) ? $request['body'] : '';
    $limit = (int)($request['response_limit_bytes'] ?? hub_cluster_proxy_response_limit_bytes());
    $limit = $limit > 0 ? $limit : hub_cluster_proxy_response_limit_bytes();
    $timeout = (int)($request['timeout_sec'] ?? hub_cluster_proxy_timeout_sec());
    $timeout = $timeout >= 1 && $timeout <= 300 ? $timeout : hub_cluster_proxy_timeout_sec();
    $rawHeaders = '';
    $responseBody = '';
    $tooLarge = false;
    $configured = curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $chunk) use (&$rawHeaders): int {
            if (strlen($rawHeaders) + strlen($chunk) > 32768) {
                return 0;
            }
            $rawHeaders .= $chunk;

            return strlen($chunk);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $limit): int {
            if (strlen($responseBody) + strlen($chunk) > $limit) {
                $tooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;

            return strlen($chunk);
        },
    ]);
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    }
    if ($configured && !in_array($method, ['GET', 'HEAD'], true)) {
        $configured = curl_setopt(
            $handle,
            CURLOPT_POSTFIELDS,
            $form === null ? $body : hub_proxy_post_fields((array)($form['post'] ?? []), (array)($form['files'] ?? []))
        );
    }
    $result = $configured ? curl_exec($handle) : false;
    $status = (int)(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0);
    $timedOut = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT;
    curl_close($handle);
    if ($tooLarge) {
        return ['too_large' => true];
    }
    if ($result === false) {
        return ['error' => $timedOut ? 'timeout' : 'proxy'];
    }

    return [
        'status' => $status,
        'headers' => hub_cluster_router_final_response_headers($rawHeaders),
        'raw_headers' => $rawHeaders,
        'body' => $responseBody,
    ];
}

function hub_cluster_router_finish_response(array $response, string $requestId): array
{
    foreach ($response['headers'] ?? [] as $header) {
        if (is_string($header) && str_starts_with(strtolower($header), 'x-3waaihub-request-id:')) {
            return $response;
        }
    }

    return hub_gateway_attach_request_id($response, $requestId);
}

function hub_cluster_node_configure(PDO $db, bool $enabled, array $selectedModes): array
{
    $selectedModes = hub_cluster_node_selected_published_modes($db, $selectedModes);
    $db->beginTransaction();
    try {
        $tokenId = hub_cluster_node_token_id($db);
        if (!$enabled) {
            if ($tokenId > 0) {
                hub_cluster_node_revoke_token($db, $tokenId);
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
                hub_cluster_node_revoke_token($db, $tokenId);
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
            hub_cluster_node_revoke_token($db, $previousTokenId);
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
        $db->prepare('DELETE FROM api_token_ip_whitelists WHERE token_id = :token_id')->execute([':token_id' => $tokenId]);
        hub_add_api_token_ip_rule($db, $tokenId, $clientIp, 'cluster router');
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', $routerName);
        hub_cluster_clear_pair_invitation($db);
        hub_cluster_node_sync_token_permissions($db, $tokenId);
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

    hub_set_api_token_mode_permissions($db, $tokenId, hub_cluster_node_expected_permissions($db));
}

function hub_cluster_node_reconcile_token_permissions(PDO $db, ?int $candidateTokenId = null): void
{
    $tokenId = hub_cluster_node_token_id($db);
    if (!hub_cluster_node_enabled($db) || $tokenId < 1 || ($candidateTokenId !== null && $candidateTokenId !== $tokenId)) {
        return;
    }
    $token = hub_get_api_token($db, $tokenId);
    if ($token === null || (int)$token['enabled'] !== 1 || !empty($token['revoked_at'])) {
        return;
    }
    $expected = hub_cluster_node_expected_permissions($db);
    $actual = array_map(static fn (array $permission): string => (string)$permission['mode'], hub_list_api_token_permissions($db, $tokenId));
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    if ($actual !== $expected) {
        hub_set_api_token_mode_permissions($db, $tokenId, $expected);
    }
}

function hub_cluster_node_expected_permissions(PDO $db): array
{
    return array_merge(['cluster_status'], hub_cluster_node_selected_published_modes($db));
}

function hub_cluster_node_token_is_current(PDO $db, int $tokenId): bool
{
    return $tokenId > 0 && hub_cluster_node_enabled($db) && $tokenId === hub_cluster_node_token_id($db);
}

function hub_cluster_node_revoke_token(PDO $db, int $tokenId): void
{
    hub_set_api_token_mode_permissions($db, $tokenId, []);
    hub_revoke_api_token($db, $tokenId);
}

function hub_cluster_node_has_verified_router_peer(PDO $db, int $tokenId, ?string $clientIp = null): bool
{
    $routerName = hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME');
    if ($routerName === '' || strlen($routerName) > 120 || preg_match('/[\x00-\x1F\x7F]/', $routerName) === 1) {
        return false;
    }
    $rules = hub_enabled_api_token_ip_rules($db, $tokenId);
    $peerIp = $rules[0]['ip_rule'] ?? null;

    return count($rules) === 1
        && ($rules[0]['label'] ?? null) === 'cluster router'
        && is_string($peerIp)
        && filter_var($peerIp, FILTER_VALIDATE_IP)
        && hub_api_token_ip_allowed($db, $tokenId, $peerIp)
        && ($clientIp === null || hash_equals($peerIp, $clientIp));
}

function hub_cluster_status_payload(PDO $db, ?array $release = null): array
{
    $now = hub_now();
    $latestMetrics = hub_latest_host_metric_snapshot($db);
    $gpu = is_array($latestMetrics['data']['gpu'] ?? null)
        ? $latestMetrics['data']['gpu']
        : ['available' => false, 'reason' => 'host_metrics_unavailable'];
    $serviceGpu = is_array($latestMetrics['data']['service_gpu'] ?? null)
        ? hub_cluster_compact_service_gpu_snapshot($latestMetrics['data']['service_gpu'])
        : [];
    $serviceStatusRows = [];
    foreach (hub_list_services($db) as $service) {
        if (!is_string($service['service_key'] ?? null) || !is_string($service['pack_id'] ?? null)) {
            continue;
        }
        $serviceStatusRows[] = [
            'service_key' => $service['service_key'],
            'pack_id' => $service['pack_id'],
            'mode' => (string)($service['mode'] ?? ''),
            'enabled' => (int)($service['enabled'] ?? 0) === 1,
            'install_status' => (string)($service['install_status'] ?? ''),
            'runtime_status' => (string)($service['runtime_status'] ?? ''),
        ];
    }
    $serviceStatus = hub_cluster_compact_service_status_snapshot($serviceStatusRows) ?? [];
    $lease = $db->prepare(
        "SELECT COUNT(*) FROM runtime_resource_leases
         WHERE state = 'leased' AND lease_expires_at IS NOT NULL AND lease_expires_at > :now"
    );
    $lease->execute([':now' => $now]);
    $queued = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'queued'")->fetchColumn();
    $running = $db->query("SELECT COUNT(*) FROM tasks WHERE status = 'running'")->fetchColumn();
    $release ??= hub_release_node_report($db);
    $childrenCount = (int)$db->query('SELECT COUNT(*) FROM cluster_stations')->fetchColumn();
    $publishedModes = hub_cluster_node_ready_published_modes($db);

    $payload = [
        'ok' => true,
        'snapshot_at' => $now,
        'display_name' => hub_site_title($db),
        'gpu' => $gpu,
        'active_gpu_leases' => (int)$lease->fetchColumn(),
        'queued_jobs' => (int)$queued,
        'running_jobs' => (int)$running,
        'modes' => $publishedModes,
        'service_gpu' => $serviceGpu ?? [],
        'service_status' => $serviceStatus,
    ];
    $report = hub_cluster_compact_status_report_fields([
        'release' => is_array($release['git'] ?? null) ? $release['git'] : [],
        'packs' => is_array($release['packs'] ?? null) ? $release['packs'] : [],
        'runners' => is_array($release['runners'] ?? null) ? $release['runners'] : [],
        'health' => is_array($release['health'] ?? null) ? $release['health'] : [],
        'cluster' => [
            'aggregate' => hub_cluster_router_enabled($db) && hub_cluster_node_enabled($db),
            'children_count' => $childrenCount,
            'published_mode_count' => count($publishedModes),
        ],
    ]);

    return $report === null ? $payload : array_merge($payload, $report);
}

function hub_cluster_station_is_fresh(array $station, ?int $now = null): bool
{
    $now ??= time();
    foreach (['manifest_fetched_at', 'status_fetched_at'] as $field) {
        $fetchedAt = strtotime((string)($station[$field] ?? ''));
        if ($fetchedAt === false || $fetchedAt > $now || ($now - $fetchedAt) > 150) {
            return false;
        }
    }

    return true;
}

function hub_cluster_station_connection_state(array $station, ?int $now = null): string
{
    $error = trim((string)($station['last_error'] ?? ''));

    return ($error === '' || $error === 'refreshing') && hub_cluster_station_is_fresh($station, $now)
        ? 'online'
        : 'offline';
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

function hub_cluster_refresh_worker_output_line(array $station): string
{
    $stationKey = (string)($station['station_key'] ?? '');
    $lastError = (string)($station['last_error'] ?? '');

    // 排程器 stdout 只保留子節點既定 ID 與收斂過的 refresh 錯誤碼，避免資料庫殘值污染工作日誌。
    if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/iD', $stationKey) !== 1) {
        $stationKey = 'invalid';
    }
    if (
        $lastError !== ''
        && !in_array($lastError, ['refreshing', 'manifest_invalid', 'station_auth_failed', 'manifest_fetch_failed', 'status_fetch_failed', 'status_invalid'], true)
        && preg_match('/\Astatus_http_(?:0|[1-5]\d{2})\z/D', $lastError) !== 1
    ) {
        $lastError = 'invalid';
    }

    return $stationKey . ' ' . (!empty($station['fresh']) ? '1' : '0') . ' ' . ($lastError !== '' ? $lastError : '-');
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
    $selectedModes = hub_cluster_photo_pair_modes($selectedModes);
    $available = array_fill_keys(hub_cluster_node_published_modes($db), true);

    return array_values(array_filter($selectedModes, static fn (string $mode): bool => isset($available[$mode])));
}

function hub_cluster_node_ready_published_modes(PDO $db, ?array $selectedModes = null, ?array $healthSnapshot = null): array
{
    $modes = hub_cluster_node_selected_published_modes($db, $selectedModes);
    $health = hub_service_health_public_payload($healthSnapshot ?? hub_service_health_read_snapshot() ?? [], ['bioclip', 'photo']);
    $ready = is_array($health['services'] ?? null) ? $health['services'] : [];
    if (in_array('bioclip', $modes, true) && (($ready['bioclip']['ready'] ?? null) !== true)) {
        $modes = array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'bioclip'));
    }
    if (array_intersect(hub_cluster_photo_modes(), $modes) !== [] && (($ready['photo']['ready'] ?? null) !== true)) {
        $modes = array_values(array_filter($modes, static fn (string $mode): bool => !hub_cluster_is_photo_mode($mode)));
    }

    return $modes;
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
        "SELECT mode, pack_id, status, runtime_status FROM services
         WHERE install_status = 'installed' AND enabled = 1
         ORDER BY mode ASC"
    )->fetchAll();

    $modes = hub_available_pack_job_async_modes($db);
    foreach ($rows as $service) {
        if (!is_array($service)) {
            continue;
        }
        $mode = trim((string)($service['mode'] ?? ''));
        if ($mode === '') {
            continue;
        }
        if ((string)($service['runtime_status'] ?? '') === 'running' || hub_cluster_node_service_is_cleanly_unloaded_on_demand($service)) {
            $modes[] = $mode;
        }
    }

    if (hub_cluster_node_photo_modes_available($db)) {
        $modes = array_merge($modes, hub_cluster_photo_modes());
    }

    $modes = hub_cluster_node_normalize_modes($modes);
    // ponytail: Node-pinned Facebook Router dispatch belongs to Phase B when a real caller needs it.
    return array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'facebook_crawl'));
}

function hub_cluster_node_photo_modes_available(PDO $db): bool
{
    $settings = hub_photo_settings($db);
    $service = hub_get_service_by_key($db, (string)$settings['vision_service_key']);

    return $service !== null
        && (int)$service['enabled'] === 1
        && (string)$service['install_status'] === 'installed'
        && (string)$service['runtime_status'] === 'running';
}

function hub_cluster_node_service_is_cleanly_unloaded_on_demand(array $service): bool
{
    if ((string)($service['status'] ?? '') !== 'stopped' || (string)($service['runtime_status'] ?? '') !== 'stopped') {
        return false;
    }
    $packId = trim((string)($service['pack_id'] ?? ''));
    $pack = $packId === '' ? null : hub_get_pack($packId);

    return is_array($pack) && (($pack['manifest']['lifecycle']['lifecycle'] ?? '') === 'on_demand');
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
    $tokenId = hub_cluster_node_token_id($db);
    if ($tokenId > 0) {
        $db->prepare("DELETE FROM api_token_ip_whitelists WHERE token_id = :token_id AND label = 'cluster router'")
            ->execute([':token_id' => $tokenId]);
        $token = hub_get_api_token($db, $tokenId);
        if (hub_cluster_node_enabled($db) && $token !== null && (int)$token['enabled'] === 1 && empty($token['revoked_at'])) {
            hub_cluster_node_sync_token_permissions($db, $tokenId);
        }
    }
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

function hub_cluster_pair_invitation_is_current(PDO $db, string $invite): bool
{
    if (preg_match('/\A[a-f0-9]{64}\z/', $invite) !== 1) {
        return false;
    }
    $expiresAt = strtotime(hub_cluster_pair_invitation_expires_at($db));
    $inviteHash = hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH');

    return $expiresAt !== false
        && $expiresAt > time()
        && preg_match('/\A[0-9a-f]{64}\z/', $inviteHash) === 1
        && hash_equals($inviteHash, hash('sha256', $invite));
}

function hub_cluster_node_pairing_descriptor(PDO $db): array
{
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/cluster_pair.php'));
    $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if (str_ends_with($path, '/admin')) {
        $path = substr($path, 0, -strlen('/admin'));
    }
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

    hub_sqlite_begin_immediate($db);
    try {
        $station = hub_cluster_get_station($db, $stationId);
        if ($station === null) {
            throw new RuntimeException('station refresh failed');
        }
        if (!$force && !hub_cluster_station_refresh_due($station)) {
            $db->exec('COMMIT');

            return hub_cluster_station_inventory($station);
        }
        $db->prepare('UPDATE cluster_stations SET last_error = :last_error, updated_at = :updated_at WHERE id = :id')
            ->execute([':last_error' => 'refreshing', ':updated_at' => hub_now(), ':id' => $stationId]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }

    $selfStation = hub_cluster_router_station_is_self($db, $station);
    if ($selfStation) {
        try {
            $manifest = hub_public_api_manifest($db);
        } catch (Throwable) {
            return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_invalid');
        }
    } else {
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
    }
    if ($manifest === null || !is_array($manifest['services'] ?? null)) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_invalid');
    }
    $manifestSnapshot = hub_cluster_compact_manifest_snapshot($manifest);
    if ($manifestSnapshot === null) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'manifest_invalid');
    }
    hub_cluster_store_station_manifest($db, $stationId, $manifestSnapshot);

    if ($selfStation) {
        $status = hub_cluster_status_payload($db);
        $statusReceivedAt = time();
    } else {
        try {
            $statusResponse = $fetcher([
                'url' => $baseUrl . 'cluster_status.php',
                'method' => 'GET',
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
        } catch (Throwable) {
            return hub_cluster_store_station_refresh_error($db, $stationId, 'status_fetch_failed');
        }
        $statusCode = is_array($statusResponse) ? (int)($statusResponse['status'] ?? 0) : 0;
        if ($statusCode !== 200) {
            $statusCode = $statusCode >= 100 && $statusCode <= 599 ? $statusCode : 0;

            return hub_cluster_store_station_refresh_error($db, $stationId, 'status_http_' . $statusCode);
        }
        $statusReceivedAt = time();
        $status = hub_cluster_refresh_json_payload($statusResponse);
    }
    $statusSnapshot = $status === null ? null : hub_cluster_compact_status_snapshot($status, $statusReceivedAt);
    if ($statusSnapshot === null) {
        return hub_cluster_store_station_refresh_error($db, $stationId, 'status_invalid');
    }
    hub_cluster_store_station_status($db, $stationId, $statusSnapshot);
    hub_cluster_store_station_gpu_metric_snapshot($db, $stationId, $statusSnapshot);

    $stored = hub_cluster_get_station($db, $stationId);
    if ($stored === null) {
        throw new RuntimeException('station refresh failed');
    }

    return hub_cluster_station_inventory($stored);
}

function hub_cluster_station_refresh_due(array $station, ?int $now = null): bool
{
    $now ??= time();
    if (trim((string)($station['last_error'] ?? '')) !== '') {
        $lastAttempt = strtotime((string)($station['updated_at'] ?? ''));
        return $lastAttempt === false || ($now - $lastAttempt) >= 10;
    }
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
    $services = [];
    foreach ($manifest['services'] as $service) {
        if (!is_array($service) || !is_string($service['mode'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $service['mode']) !== 1) {
            return null;
        }
        $mode = $service['mode'];
        $modes[$mode] = true;
        $services[$mode] = array_intersect_key($service, array_flip([
            'mode', 'pack_id', 'name', 'description', 'method', 'content_type', 'endpoint', 'url',
            'execution_type', 'runtime_level', 'gpu_required', 'task_type', 'input_fields', 'output_keys',
            'response_content_type', 'response_headers', 'error_codes', 'task_api', 'operations',
            'result_artifact_fields', 'artifact_delivery_note', 'workflow', 'error_table',
            'examples', 'workflow_examples',
        ]));
        if ($mode === 'manual_vision') {
            unset($services[$mode]['gpu_required']);
        }
    }

    return ['modes' => array_keys($modes), 'services' => array_values($services)];
}

function hub_cluster_compact_status_report_fields(array $status): ?array
{
    $fieldNames = ['release', 'packs', 'runners', 'health', 'cluster'];
    $present = array_filter($fieldNames, static fn (string $field): bool => array_key_exists($field, $status));
    if ($present === []) {
        return [];
    }
    foreach ($fieldNames as $field) {
        if (!is_array($status[$field] ?? null)) {
            return null;
        }
    }

    $release = $status['release'];
    $buildId = $release['build_id'] ?? null;
    $commit = $release['commit'] ?? null;
    $tag = $release['tag'] ?? null;
    if (
        !is_string($buildId)
        || preg_match('/\A\d{11}\z/', $buildId) !== 1
        || !is_string($commit)
        || ($commit !== '' && preg_match('/\A[0-9a-f]{7,40}\z/', $commit) !== 1)
        || !is_bool($release['dirty'] ?? null)
        || !is_string($tag)
        || ($tag !== '' && preg_match('/\A\d{11}\z/', $tag) !== 1)
    ) {
        return null;
    }

    $packs = [];
    foreach ($status['packs'] as $packId => $version) {
        if (!is_string($packId) && !is_int($packId)) {
            return null;
        }
        $packId = (string)$packId;
        if (
            preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $packId) !== 1
            || !is_string($version)
            || strlen($version) > 64
            || ($version !== '' && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._+-]{0,63}\z/', $version) !== 1)
        ) {
            return null;
        }
        $packs[$packId] = $version;
    }
    ksort($packs, SORT_STRING);

    $runners = [];
    foreach ($status['runners'] as $packId => $runner) {
        if (!is_string($packId) && !is_int($packId)) {
            return null;
        }
        $packId = (string)$packId;
        $digest = is_array($runner) ? ($runner['digest'] ?? null) : null;
        if (
            preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $packId) !== 1
            || !is_string($digest)
            || strlen($digest) > 256
            || ($digest !== '' && preg_match('/\A[a-z0-9][a-z0-9._-]{0,31}:[a-zA-Z0-9][a-zA-Z0-9._+-]{0,223}\z/', $digest) !== 1)
        ) {
            return null;
        }
        $runners[$packId] = ['digest' => $digest];
    }
    ksort($runners, SORT_STRING);

    $health = $status['health'];
    if (
        !is_string($health['status'] ?? null)
        || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/', $health['status']) !== 1
    ) {
        return null;
    }
    $compactHealth = ['status' => $health['status']];
    foreach (['installed_services', 'running_services', 'failed_services', 'queued_jobs', 'running_jobs'] as $field) {
        if (!is_int($health[$field] ?? null) || $health[$field] < 0) {
            return null;
        }
        $compactHealth[$field] = $health[$field];
    }

    $cluster = $status['cluster'];
    if (
        !is_bool($cluster['aggregate'] ?? null)
        || !is_int($cluster['children_count'] ?? null)
        || $cluster['children_count'] < 0
        || !is_int($cluster['published_mode_count'] ?? null)
        || $cluster['published_mode_count'] < 0
    ) {
        return null;
    }

    return [
        'release' => [
            'build_id' => $buildId,
            'commit' => $commit,
            'dirty' => $release['dirty'],
            'tag' => $tag,
        ],
        'packs' => $packs,
        'runners' => $runners,
        'health' => $compactHealth,
        'cluster' => [
            'aggregate' => $cluster['aggregate'],
            'children_count' => $cluster['children_count'],
            'published_mode_count' => $cluster['published_mode_count'],
        ],
    ];
}

function hub_cluster_compact_gpu_snapshot(array $gpu): array
{
    $compact = [];
    if (is_bool($gpu['available'] ?? null)) {
        $compact['available'] = $gpu['available'];
    }
    $stringPatterns = [
        'reason' => '/\A[a-z][a-z0-9_-]{0,63}\z/',
        'name' => '/\A[a-zA-Z0-9][a-zA-Z0-9 ._()+-]{0,95}\z/',
        'driver_version' => '/\A[0-9][0-9a-zA-Z._+-]{0,31}\z/',
        'cuda_version' => '/\A[0-9][0-9a-zA-Z._+-]{0,31}\z/',
    ];
    foreach ($stringPatterns as $field => $pattern) {
        $value = $gpu[$field] ?? null;
        if (is_string($value) && $value !== '' && preg_match($pattern, $value) === 1) {
            $compact[$field] = $value;
        }
    }
    $integerRanges = [
        'util_percent' => [0, 100],
        'memory_total_mb' => [0, 1_000_000_000],
        'memory_used_mb' => [0, 1_000_000_000],
        'memory_free_mb' => [0, 1_000_000_000],
        'temperature_c' => [-100, 1000],
    ];
    foreach ($integerRanges as $field => [$minimum, $maximum]) {
        $value = $gpu[$field] ?? null;
        if (is_int($value) && $value >= $minimum && $value <= $maximum) {
            $compact[$field] = $value;
        }
    }

    return $compact;
}

function hub_cluster_compact_service_gpu_snapshot(array $rows): ?array
{
    if (!array_is_list($rows) || count($rows) > 256) {
        return null;
    }

    $fields = ['service_key', 'mode', 'vram_used_mb', 'measured'];
    $serviceKeys = [];
    $modes = [];
    $compact = [];
    foreach ($rows as $row) {
        if (
            !is_array($row)
            || array_diff(array_keys($row), $fields) !== []
            || array_diff($fields, array_keys($row)) !== []
            || !is_string($row['service_key'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $row['service_key']) !== 1
            || !is_string($row['mode'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $row['mode']) !== 1
            || !is_int($row['vram_used_mb'] ?? null)
            || $row['vram_used_mb'] < 0
            || $row['vram_used_mb'] > 1_000_000_000
            || ($row['measured'] ?? null) !== true
            || isset($serviceKeys[$row['service_key']])
            || isset($modes[$row['mode']])
        ) {
            return null;
        }

        $serviceKeys[$row['service_key']] = true;
        $modes[$row['mode']] = true;
        $compact[] = [
            'service_key' => $row['service_key'],
            'mode' => $row['mode'],
            'vram_used_mb' => $row['vram_used_mb'],
            'measured' => true,
        ];
    }

    return $compact;
}

function hub_cluster_compact_service_status_snapshot(array $rows): ?array
{
    if (!array_is_list($rows) || count($rows) > 256) {
        return null;
    }

    $fields = ['service_key', 'pack_id', 'mode', 'enabled', 'install_status', 'runtime_status'];
    $serviceKeys = [];
    $modes = [];
    $compact = [];
    foreach ($rows as $row) {
        if (
            !is_array($row)
            || array_diff(array_keys($row), $fields) !== []
            || array_diff($fields, array_keys($row)) !== []
            || !is_string($row['service_key'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $row['service_key']) !== 1
            || !is_string($row['pack_id'] ?? null)
            || preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $row['pack_id']) !== 1
            || !is_string($row['mode'] ?? null)
            || preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $row['mode']) !== 1
            || !is_bool($row['enabled'] ?? null)
            || !in_array($row['install_status'] ?? null, ['installed', 'pending', 'failed'], true)
            || !in_array($row['runtime_status'] ?? null, ['running', 'stopped', 'pending', 'not_ready', 'failed', 'error'], true)
            || isset($serviceKeys[$row['service_key']])
            || isset($modes[$row['mode']])
        ) {
            return null;
        }

        $serviceKeys[$row['service_key']] = true;
        $modes[$row['mode']] = true;
        $compact[] = [
            'service_key' => $row['service_key'],
            'pack_id' => $row['pack_id'],
            'mode' => $row['mode'],
            'enabled' => $row['enabled'],
            'install_status' => $row['install_status'],
            'runtime_status' => $row['runtime_status'],
        ];
    }

    return $compact;
}

function hub_cluster_compact_status_snapshot(array $status, ?int $receivedAt = null): ?array
{
    if (
        ($status['ok'] ?? null) !== true
        || !is_string($status['snapshot_at'] ?? null)
        || !is_array($status['gpu'] ?? null)
        || !is_array($status['modes'] ?? null)
    ) {
        return null;
    }
    $snapshotAt = hub_cluster_verified_status_snapshot_at($status['snapshot_at'], $receivedAt);
    if ($snapshotAt === null) {
        return null;
    }
    $displayName = null;
    if (array_key_exists('display_name', $status)) {
        if (!is_string($status['display_name'])) {
            return null;
        }
        $displayName = trim($status['display_name']);
        $length = function_exists('mb_strlen') ? mb_strlen($displayName, 'UTF-8') : strlen($displayName);
        if ($displayName === '' || $length > 120) {
            return null;
        }
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
    $report = hub_cluster_compact_status_report_fields($status);
    if ($report === null) {
        return null;
    }
    $gpu = hub_cluster_compact_gpu_snapshot($status['gpu']);
    $serviceGpu = null;
    if (array_key_exists('service_gpu', $status)) {
        if (!is_array($status['service_gpu'])) {
            return null;
        }
        $serviceGpu = hub_cluster_compact_service_gpu_snapshot($status['service_gpu']);
        if ($serviceGpu === null) {
            return null;
        }
    }
    $serviceStatus = null;
    if (array_key_exists('service_status', $status)) {
        if (!is_array($status['service_status'])) {
            return null;
        }
        $serviceStatus = hub_cluster_compact_service_status_snapshot($status['service_status']);
        if ($serviceStatus === null) {
            return null;
        }
    }

    $snapshot = array_merge([
        'snapshot_at' => $snapshotAt,
        'gpu' => $gpu,
        'active_gpu_leases' => $status['active_gpu_leases'],
        'queued_jobs' => $status['queued_jobs'],
        'running_jobs' => $status['running_jobs'],
        'modes' => $modes,
    ], $report);
    if ($displayName !== null) {
        $snapshot['display_name'] = $displayName;
    }
    if ($serviceGpu !== null) {
        $snapshot['service_gpu'] = $serviceGpu;
    }
    if ($serviceStatus !== null) {
        $snapshot['service_status'] = $serviceStatus;
    }

    return $snapshot;
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
    $displayName = (string)($snapshot['display_name'] ?? '');
    $db->prepare(
        'UPDATE cluster_stations
         SET display_name = CASE WHEN :display_name <> \'\' THEN :display_name ELSE display_name END,
             status_json = :status_json, status_fetched_at = :fetched_at, last_error = :last_error, updated_at = :updated_at
         WHERE id = :id'
    )->execute([
        ':display_name' => $displayName,
        ':status_json' => json_encode($snapshot, JSON_THROW_ON_ERROR),
        ':fetched_at' => $snapshot['snapshot_at'],
        ':last_error' => '',
        ':updated_at' => hub_now(),
        ':id' => $stationId,
    ]);
}

function hub_cluster_store_station_gpu_metric_snapshot(PDO $db, int $stationId, array $statusSnapshot): void
{
    $compactGpu = hub_cluster_compact_gpu_snapshot((array)($statusSnapshot['gpu'] ?? []));
    $gpu = [];
    foreach (['available', 'util_percent', 'memory_used_mb', 'memory_total_mb', 'temperature_c'] as $field) {
        if (array_key_exists($field, $compactGpu)) {
            $gpu[$field] = $compactGpu[$field];
        }
    }
    $sampledAt = trim((string)($statusSnapshot['snapshot_at'] ?? ''));
    if ($sampledAt === '' || $gpu === []) {
        return;
    }
    $sampledAt = hub_cluster_verified_status_snapshot_at($sampledAt);
    if ($sampledAt === null) {
        return;
    }

    $db->prepare(
        'INSERT OR IGNORE INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json)
         VALUES (:station_id, :sampled_at, :gpu_json)'
    )->execute([
        ':station_id' => $stationId,
        ':sampled_at' => $sampledAt,
        ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
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

    return date('Y-m-d H:i:s', min($timestamp, $now));
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
    $manifest = json_decode((string)($station['manifest_json'] ?? ''), true);
    $manifestModeList = is_array($manifest['modes'] ?? null) ? $manifest['modes'] : [];
    if ($manifestModeList === [] && is_array($manifest['services'] ?? null)) {
        foreach ($manifest['services'] as $service) {
            if (is_array($service) && is_string($service['mode'] ?? null)) {
                $manifestModeList[] = $service['mode'];
            }
        }
    }
    $manifestModes = array_fill_keys($manifestModeList, true);
    $modes = array_values(array_filter(
        is_array($status['modes'] ?? null) ? $status['modes'] : [],
        static fn (mixed $mode): bool => is_string($mode) && isset($manifestModes[$mode])
    ));

    return [
        'id' => (int)($station['id'] ?? 0),
        'station_key' => (string)($station['station_key'] ?? ''),
        'priority' => (int)($station['priority'] ?? 0),
        'enabled' => !empty($station['enabled']),
        'fresh' => hub_cluster_station_is_fresh($station),
        'last_error' => (string)($station['last_error'] ?? ''),
        'modes' => $modes,
        'gpu_free_vram_mb' => (int)($status['gpu']['memory_free_mb'] ?? 0),
        'active_gpu_leases' => (int)($status['active_gpu_leases'] ?? 0),
        'queued_jobs' => (int)($status['queued_jobs'] ?? 0),
        'running_jobs' => (int)($status['running_jobs'] ?? 0),
    ];
}
