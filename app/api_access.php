<?php
declare(strict_types=1);

function hub_get_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : '127.0.0.1';
}

function aihub_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function aihub_b64url_decode(string $value): ?string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,512}$/', $value)) {
        return null;
    }

    $pad = strlen($value) % 4;
    if ($pad > 0) {
        $value .= str_repeat('=', 4 - $pad);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function hub_decode_ip_get_filter(string $encoded, bool $allowCidr = false): ?string
{
    $decoded = aihub_b64url_decode($encoded);
    if ($decoded === null) {
        return null;
    }
    $decoded = trim($decoded);
    if ($allowCidr) {
        return hub_validate_ip_rule($decoded) !== null ? $decoded : null;
    }

    return filter_var($decoded, FILTER_VALIDATE_IP) ? $decoded : null;
}

function hub_ip_filter_query(string $key, string $ip): string
{
    return $key . '=' . rawurlencode(aihub_b64url_encode($ip));
}

function hub_is_localhost_ip(string $ip): bool
{
    return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
}

function hub_validate_ip_rule(string $rule): ?string
{
    $rule = trim($rule);
    if ($rule === '') {
        return null;
    }
    if (!str_contains($rule, '/')) {
        return filter_var($rule, FILTER_VALIDATE_IP) ? 'ip' : null;
    }

    [$ip, $prefix] = explode('/', $rule, 2);
    if (!filter_var($ip, FILTER_VALIDATE_IP) || !ctype_digit($prefix)) {
        return null;
    }

    $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;
    $prefixInt = (int)$prefix;
    return $prefixInt >= 0 && $prefixInt <= $max ? 'cidr' : null;
}

function hub_ip_matches_rule(string $clientIp, string $rule): bool
{
    $clientIp = trim($clientIp);
    $rule = trim($rule);
    if (!filter_var($clientIp, FILTER_VALIDATE_IP) || hub_validate_ip_rule($rule) === null) {
        return false;
    }
    if (!str_contains($rule, '/')) {
        return $clientIp === $rule;
    }

    [$network, $prefix] = explode('/', $rule, 2);
    $clientBin = inet_pton($clientIp);
    $networkBin = inet_pton($network);
    if ($clientBin === false || $networkBin === false || strlen($clientBin) !== strlen($networkBin)) {
        return false;
    }

    $prefixInt = (int)$prefix;
    $bytes = intdiv($prefixInt, 8);
    $bits = $prefixInt % 8;
    if ($bytes > 0 && substr($clientBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $bits)) & 0xff;
    return (ord($clientBin[$bytes]) & $mask) === (ord($networkBin[$bytes]) & $mask);
}

function hub_add_service_ip_rule(PDO $db, int $serviceId, string $rule, string $label = '', ?int $createdBy = null): int
{
    $rule = trim($rule);
    $type = hub_validate_ip_rule($rule);
    if ($type === null) {
        throw new InvalidArgumentException('IP rule 格式不合法。');
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO service_ip_whitelists (service_id, ip_rule, rule_type, label, enabled, created_by, created_at, updated_at)
         VALUES (:service_id, :ip_rule, :rule_type, :label, 1, :created_by, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':service_id' => $serviceId,
        ':ip_rule' => $rule,
        ':rule_type' => $type,
        ':label' => trim($label),
        ':created_by' => $createdBy,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_list_service_ip_rules(PDO $db, int $serviceId): array
{
    $stmt = $db->prepare('SELECT * FROM service_ip_whitelists WHERE service_id = :service_id ORDER BY enabled DESC, id ASC');
    $stmt->execute([':service_id' => $serviceId]);

    return $stmt->fetchAll();
}

function hub_set_service_ip_rule_enabled(PDO $db, int $ruleId, int $serviceId, bool $enabled): void
{
    $stmt = $db->prepare('UPDATE service_ip_whitelists SET enabled = :enabled, updated_at = :updated_at WHERE id = :id AND service_id = :service_id');
    $stmt->execute([
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => hub_now(),
        ':id' => $ruleId,
        ':service_id' => $serviceId,
    ]);
}

function hub_delete_service_ip_rule(PDO $db, int $ruleId, int $serviceId): void
{
    $stmt = $db->prepare('DELETE FROM service_ip_whitelists WHERE id = :id AND service_id = :service_id');
    $stmt->execute([':id' => $ruleId, ':service_id' => $serviceId]);
}

function hub_service_ip_allowed(PDO $db, array $service, string $clientIp): bool
{
    if (hub_is_localhost_ip($clientIp)) {
        return true;
    }

    $rules = hub_enabled_service_ip_rules($db, (int)$service['id']);
    foreach ($rules as $rule) {
        if (hub_ip_matches_rule($clientIp, (string)$rule['ip_rule'])) {
            return true;
        }
    }

    if ($rules === []) {
        return hub_get_storage_setting($db, 'AIHUB_DEFAULT_ALLOW_EXTERNAL_API') === '1';
    }

    return false;
}

function hub_enabled_service_ip_rules(PDO $db, int $serviceId): array
{
    $stmt = $db->prepare('SELECT * FROM service_ip_whitelists WHERE service_id = :service_id AND enabled = 1 ORDER BY id ASC');
    $stmt->execute([':service_id' => $serviceId]);

    return $stmt->fetchAll();
}

function hub_new_request_id(): string
{
    return 'req_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
}

function hub_log_api_access(PDO $db, ?array $service, string $mode, int $status, bool $ok, ?string $errorCode, ?string $reason, int $elapsedMs, ?string $requestId = null, array $authContext = [], int $uploadBytes = 0, int $responseBytes = 0): void
{
    try {
        $stmt = $db->prepare(
            'INSERT INTO api_access_logs
                (request_id, service_id, member_id, token_id, mode, client_ip, method, request_uri, status_code, ok, error_code, reason, user_agent, elapsed_ms, upload_bytes, response_bytes, created_at)
             VALUES
                (:request_id, :service_id, :member_id, :token_id, :mode, :client_ip, :method, :request_uri, :status_code, :ok, :error_code, :reason, :user_agent, :elapsed_ms, :upload_bytes, :response_bytes, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':service_id' => $service ? (int)$service['id'] : null,
            ':member_id' => isset($authContext['member_id']) ? (int)$authContext['member_id'] : null,
            ':token_id' => isset($authContext['token_id']) ? (int)$authContext['token_id'] : null,
            ':mode' => $mode,
            ':client_ip' => substr(hub_get_client_ip(), 0, 128),
            ':method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), 0, 16),
            ':request_uri' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 1024),
            ':status_code' => $status,
            ':ok' => $ok ? 1 : 0,
            ':error_code' => $errorCode,
            ':reason' => $reason === null ? null : substr($reason, 0, 1024),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
            ':elapsed_ms' => $elapsedMs,
            ':upload_bytes' => $uploadBytes,
            ':response_bytes' => $responseBytes,
            ':created_at' => hub_now(),
        ]);
        hub_record_api_token_usage($db, $authContext, $mode, $ok, $elapsedMs, $uploadBytes, $responseBytes);
    } catch (Throwable) {
        // ponytail: API logging must never break the API response.
    }
}

function hub_list_api_access_logs(PDO $db, array $filters = [], int $limit = 100, int $offset = 0): array
{
    [$where, $params] = hub_api_access_log_where($filters);
    $stmt = $db->prepare(
        'SELECT l.*, s.name AS service_name, m.name AS member_name, t.token_name, t.token_prefix
         FROM api_access_logs l
         LEFT JOIN services s ON s.id = l.service_id
         LEFT JOIN api_members m ON m.id = l.member_id
         LEFT JOIN api_tokens t ON t.id = l.token_id
         ' . $where . '
         ORDER BY l.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_get_api_access_log(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT l.*, s.name AS service_name, s.service_key, s.status AS service_status, s.enabled AS service_enabled,
                m.name AS member_name, t.token_name, t.token_prefix
         FROM api_access_logs l
         LEFT JOIN services s ON s.id = l.service_id
         LEFT JOIN api_members m ON m.id = l.member_id
         LEFT JOIN api_tokens t ON t.id = l.token_id
         WHERE l.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $log = $stmt->fetch();

    return $log ?: null;
}

function hub_top_failed_api_ips(PDO $db, int $limit = 20): array
{
    $stmt = $db->prepare(
        'SELECT client_ip, COUNT(*) AS failed_count, MAX(created_at) AS last_seen_at,
                (SELECT error_code FROM api_access_logs x WHERE x.client_ip = api_access_logs.client_ip AND x.ok = 0 ORDER BY x.id DESC LIMIT 1) AS last_error_code,
                (SELECT reason FROM api_access_logs x WHERE x.client_ip = api_access_logs.client_ip AND x.ok = 0 ORDER BY x.id DESC LIMIT 1) AS last_reason
         FROM api_access_logs
         WHERE ok = 0
         GROUP BY client_ip
         ORDER BY failed_count DESC, last_seen_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_api_access_log_where(array $filters): array
{
    $where = [];
    $params = [];
    $clientIp = hub_decode_ip_get_filter((string)($filters['client_ip_b64'] ?? ''), false);
    if ($clientIp !== null) {
        $where[] = 'l.client_ip = :client_ip';
        $params[':client_ip'] = $clientIp;
    }
    foreach (['mode', 'error_code', 'method', 'request_id'] as $key) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '') {
            $where[] = 'l.' . $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }
    }
    foreach (['time_from' => '>=', 'time_to' => '<='] as $key => $op) {
        $value = trim((string)($filters[$key] ?? ''));
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?$/', $value)) {
            $where[] = 'l.created_at ' . $op . ' :' . $key;
            $params[':' . $key] = str_replace('T', ' ', $value);
        }
    }
    if (($filters['ok'] ?? '') !== '') {
        $where[] = 'l.ok = :ok';
        $params[':ok'] = (int)$filters['ok'];
    }
    if (($filters['status_code'] ?? '') !== '' && ctype_digit((string)$filters['status_code'])) {
        $where[] = 'l.status_code = :status_code';
        $params[':status_code'] = (int)$filters['status_code'];
    }
    if ((int)($filters['service_id'] ?? 0) > 0) {
        $where[] = 'l.service_id = :service_id';
        $params[':service_id'] = (int)$filters['service_id'];
    }
    if ((int)($filters['member_id'] ?? 0) > 0) {
        $where[] = 'l.member_id = :member_id';
        $params[':member_id'] = (int)$filters['member_id'];
    }
    if ((int)($filters['token_id'] ?? 0) > 0) {
        $where[] = 'l.token_id = :token_id';
        $params[':token_id'] = (int)$filters['token_id'];
    }
    $keyword = trim((string)($filters['keyword'] ?? ''));
    if ($keyword !== '') {
        $where[] = '(l.request_uri LIKE :keyword OR l.reason LIKE :keyword OR l.user_agent LIKE :keyword)';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
}

function hub_api_access_count(PDO $db, array $filters = []): int
{
    [$where, $params] = hub_api_access_log_where($filters);
    $stmt = $db->prepare('SELECT COUNT(*) FROM api_access_logs l ' . $where);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function hub_api_trace_stats(PDO $db, string $kind, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $since = date('Y-m-d H:i:s', time() - 86400);
    $sql = match ($kind) {
        'failed_ips' => 'SELECT client_ip AS label, COUNT(*) AS count, MAX(created_at) AS last_seen_at FROM api_access_logs WHERE ok = 0 AND created_at >= :since GROUP BY client_ip ORDER BY count DESC, last_seen_at DESC LIMIT :limit',
        'error_codes' => 'SELECT COALESCE(error_code, "") AS label, COUNT(*) AS count, MAX(created_at) AS last_seen_at FROM api_access_logs WHERE ok = 0 AND created_at >= :since GROUP BY error_code ORDER BY count DESC LIMIT :limit',
        'unknown_modes' => "SELECT mode AS label, COUNT(*) AS count, MAX(created_at) AS last_seen_at FROM api_access_logs WHERE error_code = 'unknown_mode' AND created_at >= :since GROUP BY mode ORDER BY count DESC LIMIT :limit",
        'denied_ips' => "SELECT client_ip AS label, COUNT(*) AS count, MAX(created_at) AS last_seen_at FROM api_access_logs WHERE error_code = 'ip_not_allowed' AND created_at >= :since GROUP BY client_ip ORDER BY count DESC, last_seen_at DESC LIMIT :limit",
        default => throw new InvalidArgumentException('Unknown stats kind.'),
    };
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':since', $since);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
