<?php
declare(strict_types=1);

function hub_create_api_member(PDO $db, string $name, string $contactName = '', string $contactEmail = '', string $note = ''): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Member name is required.');
    }
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO api_members (name, contact_name, contact_email, note, enabled, created_at, updated_at)
         VALUES (:name, :contact_name, :contact_email, :note, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':name' => $name,
        ':contact_name' => trim($contactName),
        ':contact_email' => trim($contactEmail),
        ':note' => trim($note),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_update_api_member(PDO $db, int $memberId, string $name, string $contactName, string $contactEmail, string $note, bool $enabled): void
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('Member name is required.');
    }
    $stmt = $db->prepare(
        'UPDATE api_members
         SET name = :name, contact_name = :contact_name, contact_email = :contact_email,
             note = :note, enabled = :enabled, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':name' => $name,
        ':contact_name' => trim($contactName),
        ':contact_email' => trim($contactEmail),
        ':note' => trim($note),
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => hub_now(),
        ':id' => $memberId,
    ]);
}

function hub_get_api_member(PDO $db, int $memberId): ?array
{
    $stmt = $db->prepare('SELECT * FROM api_members WHERE id = :id');
    $stmt->execute([':id' => $memberId]);
    $member = $stmt->fetch();

    return $member ?: null;
}

function hub_list_api_members(PDO $db): array
{
    return $db->query(
        'SELECT m.*,
                (SELECT COUNT(*) FROM api_tokens t WHERE t.member_id = m.id) AS token_count,
                (SELECT MAX(last_used_at) FROM api_tokens t WHERE t.member_id = m.id) AS last_used_at,
                COALESCE((SELECT SUM(request_count) FROM api_token_usage_daily u WHERE u.member_id = m.id AND u.usage_date = date("now", "localtime")), 0) AS today_requests
         FROM api_members m
         ORDER BY m.id DESC'
    )->fetchAll();
}

function hub_create_api_token(PDO $db, int $memberId, string $tokenName, ?string $validFrom, ?string $validUntil, bool $enabled = true): array
{
    if (!hub_get_api_member($db, $memberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $plainToken = '3wa_live_' . bin2hex(random_bytes(24));
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO api_tokens
            (member_id, token_name, token_prefix, token_hash, enabled, valid_from, valid_until, created_at, updated_at)
         VALUES
            (:member_id, :token_name, :token_prefix, :token_hash, :enabled, :valid_from, :valid_until, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':member_id' => $memberId,
        ':token_name' => trim($tokenName) !== '' ? trim($tokenName) : 'API Token',
        ':token_prefix' => hub_api_token_prefix($plainToken),
        ':token_hash' => hub_hash_api_token($plainToken),
        ':enabled' => $enabled ? 1 : 0,
        ':valid_from' => hub_normalize_token_datetime($validFrom),
        ':valid_until' => hub_normalize_token_datetime($validUntil),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return ['token_id' => (int)$db->lastInsertId(), 'plain_token' => $plainToken];
}

function hub_hash_api_token(string $plainToken): string
{
    return hash('sha256', $plainToken);
}

function hub_api_token_prefix(string $plainToken): string
{
    return substr($plainToken, 0, 16);
}

function hub_mask_api_token(array $token): string
{
    return (string)$token['token_prefix'] . '...';
}

function hub_normalize_token_datetime(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        throw new InvalidArgumentException('Invalid datetime.');
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function hub_get_api_token(PDO $db, int $tokenId): ?array
{
    $stmt = $db->prepare(
        'SELECT t.*, m.name AS member_name, m.enabled AS member_enabled
         FROM api_tokens t
         JOIN api_members m ON m.id = t.member_id
         WHERE t.id = :id'
    );
    $stmt->execute([':id' => $tokenId]);
    $token = $stmt->fetch();

    return $token ?: null;
}

function hub_list_api_tokens(PDO $db, int $memberId): array
{
    $stmt = $db->prepare('SELECT * FROM api_tokens WHERE member_id = :member_id ORDER BY id DESC');
    $stmt->execute([':member_id' => $memberId]);

    return $stmt->fetchAll();
}

function hub_list_all_api_tokens(PDO $db): array
{
    return $db->query(
        'SELECT t.*, m.name AS member_name
         FROM api_tokens t
         JOIN api_members m ON m.id = t.member_id
         ORDER BY t.id DESC'
    )->fetchAll();
}

function hub_revoke_api_token(PDO $db, int $tokenId): void
{
    $stmt = $db->prepare('UPDATE api_tokens SET enabled = 0, revoked_at = :revoked_at, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':revoked_at' => hub_now(), ':updated_at' => hub_now(), ':id' => $tokenId]);
}

function hub_set_api_token_enabled(PDO $db, int $tokenId, bool $enabled): void
{
    $sql = 'UPDATE api_tokens SET enabled = :enabled, updated_at = :updated_at WHERE id = :id';
    if ($enabled) {
        $sql .= ' AND revoked_at IS NULL';
    }
    $stmt = $db->prepare($sql);
    $stmt->execute([':enabled' => $enabled ? 1 : 0, ':updated_at' => hub_now(), ':id' => $tokenId]);
}

function hub_add_api_token_mode_permission(PDO $db, int $tokenId, string $mode, ?int $serviceId = null): int
{
    $mode = trim($mode);
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        throw new InvalidArgumentException('Invalid mode.');
    }
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO api_token_service_permissions (token_id, service_id, mode, enabled, created_at, updated_at)
         VALUES (:token_id, :service_id, :mode, 1, :created_at, :updated_at)
         ON CONFLICT(token_id, mode) DO UPDATE SET service_id = excluded.service_id, enabled = 1, updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':token_id' => $tokenId,
        ':service_id' => $serviceId,
        ':mode' => $mode,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_set_api_token_mode_permissions(PDO $db, int $tokenId, array $modes): void
{
    $db->prepare('UPDATE api_token_service_permissions SET enabled = 0, updated_at = :updated_at WHERE token_id = :token_id')
        ->execute([':updated_at' => hub_now(), ':token_id' => $tokenId]);
    foreach (array_values(array_unique(array_map('strval', $modes))) as $mode) {
        $service = hub_get_service_by_mode($db, (string)$mode);
        hub_add_api_token_mode_permission($db, $tokenId, (string)$mode, $service ? (int)$service['id'] : null);
    }
}

function hub_api_token_mode_allowed(PDO $db, int $tokenId, string $mode): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM api_token_service_permissions WHERE token_id = :token_id AND mode = :mode AND enabled = 1');
    $stmt->execute([':token_id' => $tokenId, ':mode' => $mode]);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_list_api_token_permissions(PDO $db, int $tokenId): array
{
    $stmt = $db->prepare('SELECT * FROM api_token_service_permissions WHERE token_id = :token_id AND enabled = 1 ORDER BY mode');
    $stmt->execute([':token_id' => $tokenId]);

    return $stmt->fetchAll();
}

function hub_add_api_token_ip_rule(PDO $db, int $tokenId, string $rule, string $label = ''): int
{
    $rule = trim($rule);
    $type = hub_validate_ip_rule($rule);
    if ($type === null) {
        throw new InvalidArgumentException('IP rule 格式不合法。');
    }
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO api_token_ip_whitelists (token_id, ip_rule, rule_type, label, enabled, created_at, updated_at)
         VALUES (:token_id, :ip_rule, :rule_type, :label, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':token_id' => $tokenId,
        ':ip_rule' => $rule,
        ':rule_type' => $type,
        ':label' => trim($label),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_list_api_token_ip_rules(PDO $db, int $tokenId): array
{
    $stmt = $db->prepare('SELECT * FROM api_token_ip_whitelists WHERE token_id = :token_id ORDER BY enabled DESC, id ASC');
    $stmt->execute([':token_id' => $tokenId]);

    return $stmt->fetchAll();
}

function hub_set_api_token_ip_rule_enabled(PDO $db, int $ruleId, int $tokenId, bool $enabled): void
{
    $stmt = $db->prepare('UPDATE api_token_ip_whitelists SET enabled = :enabled, updated_at = :updated_at WHERE id = :id AND token_id = :token_id');
    $stmt->execute([':enabled' => $enabled ? 1 : 0, ':updated_at' => hub_now(), ':id' => $ruleId, ':token_id' => $tokenId]);
}

function hub_delete_api_token_ip_rule(PDO $db, int $ruleId, int $tokenId): void
{
    $stmt = $db->prepare('DELETE FROM api_token_ip_whitelists WHERE id = :id AND token_id = :token_id');
    $stmt->execute([':id' => $ruleId, ':token_id' => $tokenId]);
}

function hub_api_token_ip_allowed(PDO $db, int $tokenId, string $clientIp): bool
{
    $rules = hub_enabled_api_token_ip_rules($db, $tokenId);
    if ($rules === []) {
        return true;
    }
    foreach ($rules as $rule) {
        if (hub_ip_matches_rule($clientIp, (string)$rule['ip_rule'])) {
            return true;
        }
    }

    return false;
}

function hub_enabled_api_token_ip_rules(PDO $db, int $tokenId): array
{
    $stmt = $db->prepare('SELECT * FROM api_token_ip_whitelists WHERE token_id = :token_id AND enabled = 1 ORDER BY id ASC');
    $stmt->execute([':token_id' => $tokenId]);

    return $stmt->fetchAll();
}

function hub_bearer_token_from_request(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
        return $matches[1];
    }

    return '';
}

function hub_gateway_authenticate_api_token(PDO $db, string $mode, string $clientIp): array
{
    $plainToken = hub_bearer_token_from_request();
    if ($plainToken === '' && hub_is_localhost_ip($clientIp) && hub_get_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN') === '1') {
        return ['ok' => true, 'context' => []];
    }
    if ($plainToken === '' && hub_get_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN') !== '1') {
        return ['ok' => true, 'context' => []];
    }

    if ($plainToken === '') {
        return ['ok' => false, 'response' => hub_gateway_error(401, 'missing_token', 'API token is required'), 'context' => []];
    }

    $stmt = $db->prepare(
        'SELECT t.*, m.enabled AS member_enabled
         FROM api_tokens t
         JOIN api_members m ON m.id = t.member_id
         WHERE t.token_hash = :token_hash
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => hub_hash_api_token($plainToken)]);
    $token = $stmt->fetch();
    if (!$token) {
        return ['ok' => false, 'response' => hub_gateway_error(401, 'invalid_token', 'API token is invalid'), 'context' => []];
    }

    $context = ['member_id' => (int)$token['member_id'], 'token_id' => (int)$token['id']];
    if ((int)$token['enabled'] !== 1 || (int)$token['member_enabled'] !== 1 || !empty($token['revoked_at'])) {
        return ['ok' => false, 'response' => hub_gateway_error(403, 'token_disabled', 'API token is disabled'), 'context' => $context];
    }
    $now = hub_now();
    if (!empty($token['valid_from']) && (string)$token['valid_from'] > $now) {
        return ['ok' => false, 'response' => hub_gateway_error(403, 'token_not_yet_valid', 'API token is not valid yet'), 'context' => $context];
    }
    if (!empty($token['valid_until']) && (string)$token['valid_until'] < $now) {
        return ['ok' => false, 'response' => hub_gateway_error(403, 'token_expired', 'API token is expired'), 'context' => $context];
    }
    if (!hub_api_token_ip_allowed($db, (int)$token['id'], $clientIp)) {
        return ['ok' => false, 'response' => hub_gateway_error(403, 'token_ip_not_allowed', 'client IP is not allowed for this token'), 'context' => $context];
    }
    if (!hub_api_token_mode_allowed($db, (int)$token['id'], $mode)) {
        return ['ok' => false, 'response' => hub_gateway_error(403, 'token_mode_not_allowed', 'token cannot access this mode'), 'context' => $context];
    }

    $stmt = $db->prepare('UPDATE api_tokens SET last_used_at = :last_used_at, last_used_ip = :last_used_ip, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([':last_used_at' => $now, ':last_used_ip' => substr($clientIp, 0, 128), ':updated_at' => $now, ':id' => (int)$token['id']]);

    return ['ok' => true, 'context' => $context];
}

function hub_record_api_token_usage(PDO $db, array $context, string $mode, bool $ok, int $elapsedMs, int $uploadBytes, int $responseBytes): void
{
    if (empty($context['token_id']) || empty($context['member_id'])) {
        return;
    }
    $today = date('Y-m-d');
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO api_token_usage_daily
            (token_id, member_id, mode, usage_date, request_count, success_count, failed_count, total_elapsed_ms, total_upload_bytes, total_response_bytes, created_at, updated_at)
         VALUES
            (:token_id, :member_id, :mode, :usage_date, 1, :success_count, :failed_count, :elapsed_ms, :upload_bytes, :response_bytes, :created_at, :updated_at)
         ON CONFLICT(token_id, mode, usage_date) DO UPDATE SET
            request_count = request_count + 1,
            success_count = success_count + excluded.success_count,
            failed_count = failed_count + excluded.failed_count,
            total_elapsed_ms = total_elapsed_ms + excluded.total_elapsed_ms,
            total_upload_bytes = total_upload_bytes + excluded.total_upload_bytes,
            total_response_bytes = total_response_bytes + excluded.total_response_bytes,
            updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':token_id' => (int)$context['token_id'],
        ':member_id' => (int)$context['member_id'],
        ':mode' => $mode,
        ':usage_date' => $today,
        ':success_count' => $ok ? 1 : 0,
        ':failed_count' => $ok ? 0 : 1,
        ':elapsed_ms' => $elapsedMs,
        ':upload_bytes' => max(0, $uploadBytes),
        ':response_bytes' => max(0, $responseBytes),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function hub_list_api_usage_daily(PDO $db, array $filters = []): array
{
    $where = [];
    $params = [];
    foreach (['member_id', 'token_id'] as $key) {
        if ((int)($filters[$key] ?? 0) > 0) {
            $where[] = 'u.' . $key . ' = :' . $key;
            $params[':' . $key] = (int)$filters[$key];
        }
    }
    if (trim((string)($filters['mode'] ?? '')) !== '') {
        $where[] = 'u.mode = :mode';
        $params[':mode'] = trim((string)$filters['mode']);
    }
    $sql = 'SELECT u.*, m.name AS member_name, t.token_name, t.token_prefix
            FROM api_token_usage_daily u
            JOIN api_members m ON m.id = u.member_id
            JOIN api_tokens t ON t.id = u.token_id '
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY u.usage_date DESC, u.id DESC LIMIT 500';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return $stmt->fetchAll();
}
