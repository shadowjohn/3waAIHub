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
    if (
        $value === ''
        || $parts === false
        || !filter_var($value, FILTER_VALIDATE_URL)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || str_contains($value, '?')
    ) {
        throw new InvalidArgumentException('Station base URL is invalid.');
    }

    $path = (string)($parts['path'] ?? '/');
    $path = rtrim($path, '/') . '/';
    if ($path === '') {
        $path = '/';
    }

    return strtolower((string)$parts['scheme']) . '://' . (string)$parts['host']
        . (isset($parts['port']) ? ':' . (int)$parts['port'] : '') . $path;
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

    try {
        $invite = bin2hex(random_bytes(32));
    } catch (Throwable) {
        throw new RuntimeException('pairing failed');
    }
    $expiresAt = date('Y-m-d H:i:s', time() + 900);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH', hash('sha256', $invite));
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', $expiresAt);

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
        || !str_ends_with((string)($parts['path'] ?? ''), '/cluster_pair.php')
        || preg_match('/\Ainvite=([0-9a-fA-F]{64})\z/', $fragment, $matches) !== 1
    ) {
        throw new InvalidArgumentException('pairing failed');
    }

    $routerName = trim(hub_site_title($db));
    $routerName = function_exists('mb_substr') ? mb_substr($routerName, 0, 120, 'UTF-8') : substr($routerName, 0, 120);
    $request = [
        'url' => substr($pairingLink, 0, strrpos($pairingLink, '#')),
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
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_map(static fn (string $name, string $value): string => $name . ': ' . $value, array_keys($request['headers']), $request['headers']),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
        ]);
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
