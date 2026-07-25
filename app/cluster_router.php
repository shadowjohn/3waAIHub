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
    $rows = [];
    foreach (hub_cluster_list_stations($db) as $station) {
        $inventory = hub_cluster_station_inventory($station);
        $manifest = json_decode((string)($station['manifest_json'] ?? ''), true);
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
            'modes' => $inventory['modes'],
            'mode_readiness' => $modeReadiness,
            'gpu_free_vram_mb' => (int)$inventory['gpu_free_vram_mb'],
            'gpu_total_vram_mb' => (int)($status['gpu']['memory_total_mb'] ?? 0),
            'active_gpu_leases' => (int)$inventory['active_gpu_leases'],
            'queued_jobs' => (int)$inventory['queued_jobs'],
            'running_jobs' => (int)$inventory['running_jobs'],
            'active_route_count' => (int)($activeRoutes[(int)$station['id']] ?? 0),
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

function hub_cluster_rewrite_contract_endpoint(array $service, string $stationApiBase, string $routerApiBase): array
{
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
    $rewrite = static function (mixed $value) use (&$rewrite, $stationApiPattern, $routerApiBase, $followups): mixed {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $rewrite($item);
            }

            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
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

    return $rewrite($service);
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
        foreach (['public_base_url', 'internal_base_url'] as $field) {
            $base = trim((string)($station[$field] ?? ''));
            if ($base === '') {
                continue;
            }
            try {
                $service = hub_cluster_rewrite_contract_endpoint(
                    $service,
                    hub_cluster_validate_station_base_url($base) . 'api.php',
                    hub_cluster_router_api_base_url()
                );
            } catch (Throwable) {
                continue;
            }
        }
        $services[] = $service;
    }

    return [
        'base_endpoint' => hub_cluster_router_api_base_url(),
        'auth' => ['type' => 'bearer', 'header' => 'Authorization: Bearer <TOKEN>'],
        'generated_at' => hub_now(),
        'inventory_note' => 'Router inventory refresh may temporarily remove unavailable modes.',
        'services' => $services,
    ];
}

function hub_cluster_public_api_docs_html(PDO $db): string
{
    $manifest = hub_cluster_public_manifest($db);
    $json = static fn (mixed $value): string => (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ob_start();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>3waAIHub Router API</title>
    <style>
        body { color: #1d2430; font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; }
        main { max-width: 960px; margin: 24px auto; padding: 0 16px; }
        section { background: #fff; border: 1px solid #d9dee7; border-radius: 8px; margin: 14px 0; padding: 16px; }
        code, pre { overflow-wrap: anywhere; white-space: pre-wrap; }
        pre { background: #f6f7f9; border: 1px solid #d9dee7; padding: 12px; }
    </style>
</head>
<body>
<main>
    <h1>3waAIHub Router API</h1>
    <p>Base endpoint: <code><?= hub_h((string)$manifest['base_endpoint']) ?></code></p>
    <p><?= hub_h((string)$manifest['inventory_note']) ?></p>
    <?php foreach ($manifest['services'] as $service): ?>
        <section>
            <h2><code><?= hub_h((string)($service['mode'] ?? '')) ?></code></h2>
            <p>Method: <code><?= hub_h((string)($service['method'] ?? '')) ?></code></p>
            <p>Content type: <code><?= hub_h((string)($service['content_type'] ?? '')) ?></code></p>
            <p>Router endpoint: <code><?= hub_h((string)($service['endpoint'] ?? '')) ?></code></p>
            <h3>Fields</h3>
            <pre><?= hub_h($json($service['input_fields'] ?? [])) ?></pre>
            <h3>Output</h3>
            <pre><?= hub_h($json($service['output_keys'] ?? [])) ?></pre>
            <h3>Errors</h3>
            <pre><?= hub_h($json($service['error_codes'] ?? [])) ?></pre>
            <h3>curl</h3>
            <pre><?= hub_h((string)($service['examples']['curl'] ?? '')) ?></pre>
            <h3>PHP</h3>
            <pre><?= hub_h((string)($service['examples']['php'] ?? '')) ?></pre>
            <h3>JS</h3>
            <pre><?= hub_h((string)($service['examples']['js_fetch'] ?? '')) ?></pre>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
<?php
    return (string)ob_get_clean();
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
    $selectedInventory = hub_cluster_select_station($mode, $inventory);
    if ($selectedInventory === null) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'no eligible cluster station is available'));
    }
    $stationId = (int)($selectedInventory['id'] ?? 0);
    $station = $stationId > 0 ? hub_cluster_get_station($db, $stationId) : null;
    if ($station === null || empty($station['enabled'])) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'no eligible cluster station is available'));
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
        return $finish(hub_gateway_error(503, 'router_unavailable', 'selected cluster station is unavailable'));
    }
    $selfPeerIp = $selfStation ? hub_cluster_router_self_station_peer_ip($db, $station, $stationToken) : null;
    if ($selfStation && $selfPeerIp === null) {
        return $finish(hub_gateway_error(503, 'router_unavailable', 'selected cluster station is unavailable'));
    }

    $routeId = hub_cluster_router_admit_route($db, $station, (array)$auth['context'], $mode, !$selfStation);
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
            $directRequest['bearer_token'] = $stationToken;
            $directRequest['client_ip'] = $selfPeerIp;
            $result = $dispatcher($db, $mode, $directRequest);
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
                'follow_redirects' => false,
                'response_limit_bytes' => hub_cluster_proxy_response_limit_bytes(),
            ];
            $response = hub_cluster_router_proxy_response($transport($proxyRequest), $stationToken);
        }
        $payload = hub_cluster_router_json_payload($response);
        if ((int)($response['status'] ?? 0) >= 400 && !hub_cluster_router_is_local_proxy_error($response)) {
            $response = hub_gateway_error(502, 'router_response_failed', 'cluster station response failed');
        } elseif ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300 && is_array($payload) && is_scalar($payload['task_id'] ?? null)) {
            $payload = hub_cluster_rewrite_async_response($db, [
                'route_id' => $routeId,
                'station_id' => (int)$station['id'],
            ], $payload, hub_cluster_router_api_base_url());
            $response = hub_cluster_router_with_json_payload($response, $payload);
        }
    } catch (Throwable) {
        $response = hub_gateway_error(502, 'router_proxy_failed', 'cluster station request failed');
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
            strlen($normalized['raw_body'])
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
    if (preg_match('/^multipart\/form-data(?:;|$)/i', (string)($headers['Content-Type'] ?? '')) === 1) {
        return ['response' => hub_gateway_error(415, 'router_upload_unsupported', 'file uploads are not supported by the cluster router')];
    }
    $files = array_key_exists('files', $request) ? $request['files'] : ($_FILES ?? []);
    if (!is_array($files) || $files !== []) {
        return ['response' => hub_gateway_error(415, 'router_upload_unsupported', 'file uploads are not supported by the cluster router')];
    }
    $body = hub_cluster_router_read_request_body($request);
    if (isset($body['response'])) {
        return $body;
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

    return [
        'method' => $method,
        'headers' => $headers,
        'raw_body' => $body['body'],
        'query' => $query,
        'request_uri' => (string)($request['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? ''),
    ];
}

function hub_cluster_router_requested_mode(mixed $value): ?string
{
    return is_string($value) && $value !== '' ? $value : null;
}

function hub_cluster_router_api_base_url(): string
{
    return 'cluster_api.php';
}

function hub_cluster_router_task_links(string $routeId, string $routerBase): array
{
    $prefix = rtrim($routerBase, '?') . (str_contains($routerBase, '?') ? '&' : '?');
    $taskId = rawurlencode($routeId);

    return [
        'status_url' => $prefix . 'mode=cluster_task_status&task_id=' . $taskId,
        'result_url' => $prefix . 'mode=cluster_task_result&task_id=' . $taskId,
        'log_url' => $prefix . 'mode=cluster_task_log&task_id=' . $taskId,
        'cancel_url' => $prefix . 'mode=cluster_task_cancel&task_id=' . $taskId,
        'artifact_url_template' => $prefix . 'mode=cluster_artifact&task_id=' . $taskId . '&artifact_id={artifact_id}',
    ];
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

    return hub_cluster_router_rewrite_task_payload($db, $route, $payload, $routerBase, $remoteTaskId);
}

function hub_cluster_router_rewrite_task_payload(PDO $db, array $route, array $payload, string $routerBase, string $remoteTaskId, string $kind = 'submit'): array
{
    $routeId = (string)($route['route_id'] ?? '');
    $status = hub_cluster_router_public_task_status($payload['status'] ?? null);
    $response = ['ok' => ($payload['ok'] ?? false) === true, 'task_id' => $routeId];
    if ($kind === 'result') {
        $response['result'] = hub_cluster_router_public_task_result($payload);
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
        if ($kind === 'status' && is_int($payload['progress'] ?? null) && $payload['progress'] >= 0 && $payload['progress'] <= 100) {
            $response['progress'] = $payload['progress'];
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

    return array_replace($response, hub_cluster_router_task_links($routeId, $routerBase));
}

function hub_cluster_router_public_task_status(mixed $status): ?string
{
    if (!is_string($status)) {
        return null;
    }
    $status = strtolower($status);

    return in_array($status, ['queued', 'running', 'success', 'succeeded', 'completed', 'failed', 'cancelled', 'canceled', 'timed_out', 'timeout'], true)
        ? ($status === 'timeout' ? 'timed_out' : $status)
        : null;
}

function hub_cluster_router_result_artifacts(array $payload): ?array
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

function hub_cluster_router_public_task_result(array $payload): array
{
    $artifacts = hub_cluster_router_result_artifacts($payload);
    if ($artifacts === null) {
        throw new UnexpectedValueException('invalid child artifact index');
    }
    $result = $payload['result'] ?? null;
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

function hub_cluster_router_public_task_logs(PDO $db, array $route, array $payload, string $remoteTaskId): ?array
{
    $logs = $payload['logs'] ?? null;
    if (!is_array($logs) || !array_is_list($logs)) {
        return null;
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
    return preg_match('/\Aroute_[a-f0-9]{32}\z/', $routeId) === 1;
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

function hub_cluster_router_with_json_payload(array $response, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $response['body'] = $body;

    return $response;
}

function hub_cluster_router_is_followup_mode(string $mode): bool
{
    return in_array($mode, ['cluster_task_status', 'cluster_task_result', 'cluster_task_log', 'cluster_task_cancel', 'cluster_artifact'], true);
}

function hub_cluster_child_followup_dispatch(PDO $db, array $request = []): array
{
    $query = array_key_exists('query', $request) ? $request['query'] : $_GET;
    $mode = hub_cluster_router_requested_mode(is_array($query) ? ($query['mode'] ?? null) : null);
    if (!in_array($mode, ['task_status', 'task_result', 'task_log', 'task_cancel', 'artifact'], true)) {
        return hub_gateway_error(404, 'unknown_mode', 'mode is not registered');
    }
    $method = strtoupper(trim((string)($request['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';
    $requiredMethod = $mode === 'task_cancel' ? 'POST' : 'GET';
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
    $artifactId = $mode === 'artifact' ? hub_cluster_child_followup_numeric_query_value($query, 'artifact_id') : null;
    if ($mode === 'artifact' && $artifactId === null) {
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

function hub_cluster_dispatch_followup(PDO $db, string $routerMode, array $request = [], ?callable $requester = null): array
{
    $requestId = hub_new_request_id();
    $started = microtime(true);
    $finish = static fn (array $response): array => hub_cluster_router_finish_response($response, $requestId);
    if (!hub_cluster_router_is_followup_mode($routerMode)) {
        return $finish(hub_gateway_error(404, 'unknown_mode', 'mode is not registered'));
    }
    $clientIp = trim(is_scalar($request['client_ip'] ?? null) ? (string)$request['client_ip'] : hub_get_client_ip()) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $request)
        ? (is_string($request['bearer_token']) ? $request['bearer_token'] : '')
        : hub_bearer_token_from_request();
    $auth = hub_authenticate_api_token($db, $clientIp, $providedToken);
    if (empty($auth['ok'])) {
        return $finish($auth['response']);
    }
    $routeId = hub_cluster_router_followup_query_value($request, 'task_id');
    $route = $routeId === null ? null : hub_cluster_get_route_for_customer($db, $routeId, (array)$auth['context']);
    if ($route === null) {
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
    if ($routerMode === 'cluster_artifact') {
        $remoteArtifactId = hub_cluster_router_followup_query_value($request, 'artifact_id');
        if ($remoteArtifactId === null || !hub_cluster_router_route_has_artifact($db, (string)$route['route_id'], $remoteArtifactId)) {
            return $complete(hub_gateway_error(404, 'artifact_not_found', 'artifact was not found'));
        }
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
    };
    $query = $routerMode === 'cluster_artifact'
        ? ['mode' => $mode, 'task_id' => (string)$route['remote_task_id'], 'artifact_id' => $remoteArtifactId]
        : ['mode' => $mode, 'task_id' => (string)$route['remote_task_id']];
    $selfStation = hub_cluster_router_station_is_self($db, $station);
    if ($selfStation) {
        $selfPeerIp = hub_cluster_router_self_station_peer_ip($db, $station, $stationToken);
        if ($selfPeerIp === null) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'), null, true);
        }
        try {
            $response = hub_cluster_router_direct_followup($db, $mode, $method, $query, $stationToken, $selfPeerIp);
        } catch (Throwable) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'), null, true);
        }
    } else {
        try {
            $stationUrl = hub_cluster_station_request_base_url($station) . 'cluster_followup.php';
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
            $response = hub_cluster_router_proxy_response($rawResponse, $stationToken);
        } catch (Throwable) {
            return $complete(hub_gateway_error(503, 'station_unavailable', 'selected cluster station is unavailable'));
        }
    }
    $payload = hub_cluster_router_json_payload($response);
    if ((int)($response['status'] ?? 0) < 200 || (int)($response['status'] ?? 0) >= 300) {
        if (hub_cluster_router_is_local_proxy_error($response)) {
            return $complete($response, null, $selfStation);
        }
        return $complete(hub_gateway_error(502, 'router_response_failed', 'cluster station response failed'), null, $selfStation);
    }
    if ($routerMode === 'cluster_artifact') {
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
    $response = hub_cluster_router_with_json_payload($response, $payload);

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

function hub_cluster_router_admit_route(PDO $db, array $station, array $authContext, string $mode, bool $proxying): ?string
{
    $stationId = (int)($station['id'] ?? 0);
    $routeId = hub_cluster_router_route_id();
    if ($stationId < 1 || $routeId === '') {
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
                (route_id, station_id, member_id, token_id, mode, is_async, state, created_at, updated_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, 0, :state, :created_at, :updated_at)'
        )->execute([
            ':route_id' => $routeId,
            ':station_id' => $stationId,
            ':member_id' => !empty($authContext['member_id']) ? (int)$authContext['member_id'] : null,
            ':token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
            ':mode' => $mode,
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

function hub_cluster_router_route_id(): string
{
    try {
        return 'route_' . bin2hex(random_bytes(16));
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

function hub_cluster_proxy_timeout_sec(): int
{
    return 60;
}

function hub_cluster_proxy_stale_after_seconds(): int
{
    return hub_cluster_proxy_timeout_sec() + 30;
}

function hub_cluster_router_reap_expired_proxy_routes(PDO $db, string $now): void
{
    $cutoff = date('Y-m-d H:i:s', strtotime($now) - hub_cluster_proxy_stale_after_seconds());
    $db->prepare(
        "UPDATE cluster_routes
         SET state = 'failed', remote_status = 'router_timeout', updated_at = :updated_at, completed_at = :completed_at
         WHERE state = 'proxying' AND updated_at < :cutoff"
    )->execute([
        ':updated_at' => $now,
        ':completed_at' => $now,
        ':cutoff' => $cutoff,
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

function hub_cluster_router_proxy_response(mixed $response, string $stationToken): array
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
    $safeHeaders = hub_proxy_allowed_response_headers($rawHeaders, $contentType);
    if (str_contains($body, $stationToken) || array_filter($safeHeaders, static fn (string $header): bool => str_contains($header, $stationToken)) !== []) {
        return hub_cluster_router_local_proxy_error(502, 'router_proxy_failed', 'cluster station request failed');
    }

    return ['status' => $status, 'headers' => $safeHeaders, 'body' => $body];
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
    if ($url === '' || (!str_ends_with($url, '/api.php') && !str_ends_with($url, '/cluster_followup.php'))) {
        return ['error' => 'proxy'];
    }
    $target = $url . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    $handle = curl_init($target);
    if ($handle === false) {
        return ['error' => 'proxy'];
    }
    $headers = [];
    foreach (['Authorization', 'Content-Type', 'Accept'] as $name) {
        $value = $request['headers'][$name] ?? null;
        if (is_string($value) && $value !== '' && strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            $headers[] = $name . ': ' . $value;
        }
    }
    $method = (string)($request['method'] ?? 'GET');
    $body = is_string($request['body'] ?? null) ? $request['body'] : '';
    $limit = (int)($request['response_limit_bytes'] ?? hub_cluster_proxy_response_limit_bytes());
    $limit = $limit > 0 ? $limit : hub_cluster_proxy_response_limit_bytes();
    $rawHeaders = '';
    $responseBody = '';
    $tooLarge = false;
    $configured = curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => hub_cluster_proxy_timeout_sec(),
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
        $configured = curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
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
        if ($fetchedAt === false || $fetchedAt > $now || ($now - $fetchedAt) > 30) {
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
    $path = rtrim(dirname($script), '/');
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
    $statusReceivedAt = time();
    $status = hub_cluster_refresh_json_payload($statusResponse);
    $statusSnapshot = $status === null ? null : hub_cluster_compact_status_snapshot($status, $statusReceivedAt);
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
            'execution_type', 'runtime_level', 'task_type', 'input_fields', 'output_keys',
            'response_content_type', 'response_headers', 'error_codes', 'task_api', 'examples',
        ]));
    }

    return ['modes' => array_keys($modes), 'services' => array_values($services)];
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
