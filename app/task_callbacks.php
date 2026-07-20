<?php
declare(strict_types=1);

function hub_callback_alias_is_valid(string $alias): bool
{
    return preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $alias) === 1;
}

function hub_callback_ip_in_cidr(string $ip, string $network, int $prefix): bool
{
    $packedIp = inet_pton($ip);
    $packedNetwork = inet_pton($network);
    if ($packedIp === false || $packedNetwork === false || strlen($packedIp) !== strlen($packedNetwork)) {
        return false;
    }

    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if (substr($packedIp, 0, $fullBytes) !== substr($packedNetwork, 0, $fullBytes)) {
        return false;
    }
    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xff << (8 - $remainingBits)) & 0xff;

    return (ord($packedIp[$fullBytes]) & $mask) === (ord($packedNetwork[$fullBytes]) & $mask);
}

function hub_callback_ip_is_public(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false
        || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        foreach ([
            ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
            ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
            ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
            ['224.0.0.0', 4], ['240.0.0.0', 4],
        ] as [$network, $prefix]) {
            if (hub_callback_ip_in_cidr($ip, $network, $prefix)) {
                return false;
            }
        }

        return true;
    }

    if (!hub_callback_ip_in_cidr($ip, '2000::', 3)) {
        return false;
    }
    foreach ([
        ['::', 96], ['::ffff:0:0', 96], ['64:ff9b::', 96], ['64:ff9b:1::', 48],
        ['2001::', 23], ['2001:db8::', 32], ['2002::', 16], ['3fff::', 20],
    ] as [$network, $prefix]) {
        if (hub_callback_ip_in_cidr($ip, $network, $prefix)) {
            return false;
        }
    }

    return true;
}

function hub_callback_resolve_public_ips(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return hub_callback_ip_is_public($host) ? [$host] : [];
    }
    if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) !== 1) {
        return [];
    }

    $ips = gethostbynamel($host) ?: [];
    if (function_exists('dns_get_record')) {
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $ips[] = $record['ipv6'];
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if ($ips === []) {
        return [];
    }
    foreach ($ips as $ip) {
        if (!hub_callback_ip_is_public($ip)) {
            return [];
        }
    }

    return $ips;
}

function hub_callback_endpoint_info(string $url): array
{
    if ($url === '' || trim($url) !== $url || strlen($url) > 2048) {
        throw new InvalidArgumentException('callback_url_invalid');
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || !isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || (isset($parts['port']) && (int)$parts['port'] !== 443)) {
        throw new InvalidArgumentException('callback_url_invalid');
    }
    $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new InvalidArgumentException('callback_url_invalid');
    }
    $ips = hub_callback_resolve_public_ips($host);
    if ($ips === []) {
        throw new InvalidArgumentException('callback_url_invalid');
    }

    return ['url' => $url, 'host' => $host, 'ips' => $ips];
}

function hub_register_callback_target(PDO $db, int $ownerMemberId, string $alias, string $callbackUrl): int
{
    return hub_register_callback_target_from_trusted_config($db, $ownerMemberId, $alias, $callbackUrl, bin2hex(random_bytes(32)));
}

function hub_register_callback_target_from_trusted_config(PDO $db, int $ownerMemberId, string $alias, string $callbackUrl, string $signingSecret): int
{
    if (strlen($signingSecret) < 32 || strlen($signingSecret) > 512) {
        throw new InvalidArgumentException('callback_target_secret_invalid');
    }

    if ($ownerMemberId <= 0 || !hub_callback_alias_is_valid($alias)) {
        throw new InvalidArgumentException('callback_target_invalid');
    }
    hub_callback_endpoint_info($callbackUrl);
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO task_callback_targets (owner_member_id, target_alias, callback_url, signing_secret, enabled, created_at, updated_at)
         VALUES (:owner_member_id, :target_alias, :callback_url, :signing_secret, 1, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':owner_member_id' => $ownerMemberId,
        ':target_alias' => $alias,
        ':callback_url' => $callbackUrl,
        ':signing_secret' => $signingSecret,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_set_callback_target_enabled(PDO $db, int $ownerMemberId, string $alias, bool $enabled): void
{
    $stmt = $db->prepare(
        'UPDATE task_callback_targets
         SET enabled = :enabled, updated_at = :updated_at
         WHERE owner_member_id = :owner_member_id AND target_alias = :target_alias'
    );
    $stmt->execute([
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => hub_now(),
        ':owner_member_id' => $ownerMemberId,
        ':target_alias' => $alias,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('callback_target_not_found');
    }
}

function hub_callback_target_id_for_member(PDO $db, int $ownerMemberId, string $alias): int
{
    if ($ownerMemberId <= 0 || !hub_callback_alias_is_valid($alias)) {
        throw new InvalidArgumentException('callback_target_not_found');
    }
    $stmt = $db->prepare(
        'SELECT id, enabled FROM task_callback_targets
         WHERE owner_member_id = :owner_member_id AND target_alias = :target_alias'
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId, ':target_alias' => $alias]);
    $target = $stmt->fetch();
    if (!$target) {
        throw new InvalidArgumentException('callback_target_not_found');
    }
    if ((int)$target['enabled'] !== 1) {
        throw new InvalidArgumentException('callback_target_disabled');
    }

    return (int)$target['id'];
}

function hub_audio_callback_target_id(PDO $db, int $ownerMemberId, array $input): ?int
{
    $hasCallback = array_key_exists('callback', $input);
    $hasAlias = array_key_exists('callback_target', $input);
    if (!$hasCallback && !$hasAlias) {
        return null;
    }
    $enabled = true;
    if ($hasCallback) {
        $callback = $input['callback'];
        if ($callback === true || $callback === 1 || $callback === '1' || $callback === 'true') {
            $enabled = true;
        } elseif ($callback === false || $callback === 0 || $callback === '0' || $callback === 'false') {
            $enabled = false;
        } else {
            throw new InvalidArgumentException('forbidden_task_control');
        }
    }
    if (!$enabled) {
        return null;
    }
    if ($hasAlias && !is_string($input['callback_target'])) {
        throw new InvalidArgumentException('forbidden_task_control');
    }
    $alias = $hasAlias ? $input['callback_target'] : 'default';

    return hub_callback_target_id_for_member($db, $ownerMemberId, $alias);
}

function hub_task_callback_event_type(array $task): ?string
{
    return match ((string)($task['status'] ?? '')) {
        'success' => 'task.completed',
        'failed' => 'task.failed',
        default => null,
    };
}

function hub_callback_safe_label($value, string $fallback): string
{
    $value = is_string($value) ? $value : '';

    return preg_match('/^[a-z0-9_.-]{1,64}$/i', $value) === 1 ? $value : $fallback;
}

function hub_callback_safe_mime($value): string
{
    $value = is_string($value) ? $value : '';

    return preg_match('/^[a-z0-9.+-]{1,64}\/[a-z0-9.+-]{1,64}$/i', $value) === 1 ? $value : 'application/octet-stream';
}

function hub_callback_safe_sha256($value): ?string
{
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1 ? strtolower($value) : null;
}

function hub_callback_safe_error_code($value): ?string
{
    return is_string($value) && preg_match('/^[a-z0-9_:-]{1,120}$/i', $value) === 1 ? $value : null;
}

function hub_task_callback_payload(PDO $db, array $task): string
{
    $taskId = (int)$task['id'];
    $artifacts = $db->prepare(
        'SELECT id, artifact_type, mime_type, size_bytes, sha256, state
         FROM task_artifacts WHERE task_id = :task_id ORDER BY id ASC LIMIT 20'
    );
    $artifacts->execute([':task_id' => $taskId]);
    $items = [];
    foreach ($artifacts->fetchAll() as $artifact) {
        $items[] = [
            'id' => (int)$artifact['id'],
            'type' => hub_callback_safe_label($artifact['artifact_type'], 'artifact'),
            'mime' => hub_callback_safe_mime($artifact['mime_type']),
            'size' => max(0, (int)$artifact['size_bytes']),
            'sha256' => hub_callback_safe_sha256($artifact['sha256']),
            'state' => hub_callback_safe_label($artifact['state'], 'available'),
        ];
    }
    $payload = json_encode([
        'task' => [
            'id' => $taskId,
            'state' => (string)($task['status'] ?? ''),
            'completed_at' => $task['finished_at'] === null ? null : (string)$task['finished_at'],
            'error_code' => hub_callback_safe_error_code($task['error_code']),
        ],
        'artifacts' => $items,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('callback_payload_encode_failed');
    }

    return $payload;
}

function hub_enqueue_task_callback_delivery(PDO $db, int $taskId): ?string
{
    $task = hub_get_task($db, $taskId);
    $eventType = $task ? hub_task_callback_event_type($task) : null;
    $targetId = (int)($task['callback_target_id'] ?? 0);
    if ($eventType === null || $targetId <= 0) {
        return null;
    }
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    if ($ownerMemberId <= 0) {
        return null;
    }
    $target = $db->prepare(
        'SELECT 1 FROM task_callback_targets
         WHERE id = :id AND owner_member_id = :owner_member_id AND enabled = 1'
    );
    $target->execute([':id' => $targetId, ':owner_member_id' => $ownerMemberId]);
    if ($target->fetchColumn() === false) {
        return null;
    }
    $deliveryId = 'cb_' . hash('sha256', $targetId . ':' . $taskId . ':' . $eventType);
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO task_callback_deliveries
         (delivery_id, callback_target_id, task_id, event_type, payload_json, attempt_count, next_attempt_at, created_at, updated_at)
         SELECT :delivery_id, :callback_target_id, :task_id, :event_type, :payload_json, 0, :next_attempt_at, :created_at, :updated_at
         WHERE EXISTS (
             SELECT 1 FROM task_callback_targets
             WHERE id = :active_target_id AND owner_member_id = :active_owner_member_id AND enabled = 1
         )'
    );
    $stmt->execute([
        ':delivery_id' => $deliveryId,
        ':callback_target_id' => $targetId,
        ':active_target_id' => $targetId,
        ':active_owner_member_id' => $ownerMemberId,
        ':task_id' => $taskId,
        ':event_type' => $eventType,
        ':payload_json' => hub_task_callback_payload($db, $task),
        ':next_attempt_at' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    if ($stmt->rowCount() === 0) {
        $existing = $db->prepare('SELECT 1 FROM task_callback_deliveries WHERE delivery_id = :delivery_id');
        $existing->execute([':delivery_id' => $deliveryId]);
        if ($existing->fetchColumn() === false) {
            return null;
        }
    }

    return $deliveryId;
}

function hub_callback_time(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

function hub_callback_claim_due_delivery(PDO $db, int $timestamp, int $leaseSeconds = 120): ?array
{
    if ($db->inTransaction()) {
        throw new LogicException('callback_claim_requires_no_transaction');
    }
    $now = hub_callback_time($timestamp);
    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $stmt = $db->prepare(
            'SELECT d.*, t.callback_url, t.signing_secret, t.enabled AS target_enabled
             FROM task_callback_deliveries d
             JOIN task_callback_targets t ON t.id = d.callback_target_id
             WHERE d.event_type IN (\'task.completed\', \'task.failed\')
               AND d.delivered_at IS NULL AND d.attempt_count < 5
               AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= :now)
             ORDER BY d.next_attempt_at ASC, d.id ASC LIMIT 1'
        );
        $stmt->execute([':now' => $now]);
        $delivery = $stmt->fetch();
        if (!$delivery) {
            $db->exec('COMMIT');
            return null;
        }
        $attempt = (int)$delivery['attempt_count'] + 1;
        $reserve = $db->prepare(
            'UPDATE task_callback_deliveries
             SET attempt_count = :attempt_count, next_attempt_at = :next_attempt_at, updated_at = :updated_at
             WHERE id = :id AND delivered_at IS NULL AND attempt_count = :previous_attempt_count'
        );
        $reserve->execute([
            ':attempt_count' => $attempt,
            ':next_attempt_at' => hub_callback_time($timestamp + max(1, $leaseSeconds)),
            ':updated_at' => $now,
            ':id' => (int)$delivery['id'],
            ':previous_attempt_count' => (int)$delivery['attempt_count'],
        ]);
        if ($reserve->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return null;
        }
        $db->exec('COMMIT');
        $delivery['attempt_count'] = $attempt;

        return $delivery;
    } catch (Throwable $e) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
}

function hub_callback_retry_delay(int $attemptCount): ?int
{
    return [1 => 30, 2 => 120, 3 => 600, 4 => 3600][$attemptCount] ?? null;
}

function hub_callback_safe_error(?string $error, ?int $status): string
{
    $allowed = ['callback_target_disabled', 'callback_target_missing', 'callback_target_invalid', 'callback_network_error', 'callback_sender_error'];
    if (in_array($error, $allowed, true)) {
        return $error;
    }

    return $status === null ? 'callback_network_error' : 'callback_http_error';
}

function hub_callback_finalize_delivery(PDO $db, array $delivery, array $result, int $timestamp): bool
{
    $attemptCount = (int)($delivery['attempt_count'] ?? 0);
    $status = isset($result['status']) && is_int($result['status']) && $result['status'] >= 100 && $result['status'] <= 599
        ? $result['status']
        : null;
    $delivered = $status !== null && $status >= 200 && $status < 300;
    $now = hub_callback_time($timestamp);
    $final = !empty($result['final']);
    $nextAttemptAt = $delivered || $final || $attemptCount >= 5
        ? null
        : hub_callback_time($timestamp + (hub_callback_retry_delay($attemptCount) ?? 0));
    $storedAttemptCount = $final ? 5 : $attemptCount;
    $stmt = $db->prepare(
        'UPDATE task_callback_deliveries
         SET attempt_count = :stored_attempt_count, delivered_at = :delivered_at, last_http_status = :last_http_status, last_error = :last_error,
             next_attempt_at = :next_attempt_at, updated_at = :updated_at
         WHERE delivery_id = :delivery_id AND delivered_at IS NULL AND attempt_count = :attempt_count'
    );
    $stmt->execute([
        ':stored_attempt_count' => $storedAttemptCount,
        ':delivered_at' => $delivered ? $now : null,
        ':last_http_status' => $status,
        ':last_error' => $delivered ? null : hub_callback_safe_error(isset($result['error']) ? (string)$result['error'] : null, $status),
        ':next_attempt_at' => $nextAttemptAt,
        ':updated_at' => $now,
        ':delivery_id' => (string)$delivery['delivery_id'],
        ':attempt_count' => $attemptCount,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_callback_headers(array $delivery, int $timestamp): array
{
    $payload = (string)$delivery['payload_json'];

    return [
        'Content-Type' => 'application/json',
        'X-AIHub-Event' => (string)$delivery['event_type'],
        'X-AIHub-Delivery' => (string)$delivery['delivery_id'],
        'X-AIHub-Timestamp' => (string)$timestamp,
        'X-AIHub-Signature' => 'sha256=' . hash_hmac('sha256', $payload, (string)$delivery['signing_secret']),
    ];
}

function hub_callback_send_http(array $delivery, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['error' => 'callback_network_error'];
    }
    try {
        $endpoint = hub_callback_endpoint_info((string)$delivery['callback_url']);
    } catch (InvalidArgumentException) {
        return ['error' => 'callback_target_invalid', 'final' => true];
    }
    $headerLines = [];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    $options = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_POSTFIELDS => (string)$delivery['payload_json'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROXY => '',
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if (filter_var($endpoint['host'], FILTER_VALIDATE_IP) === false && defined('CURLOPT_RESOLVE')) {
        $ip = (string)$endpoint['ips'][0];
        $options[CURLOPT_RESOLVE] = [$endpoint['host'] . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)];
    }
    $handle = curl_init($endpoint['url']);
    if ($handle === false) {
        return ['error' => 'callback_network_error'];
    }
    curl_setopt_array($handle, $options);
    $ok = curl_exec($handle);
    $status = (int)(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0);
    curl_close($handle);

    return $ok === false ? ['error' => 'callback_network_error'] : ['status' => $status];
}

function hub_callback_process_next(PDO $db, ?callable $sender = null, ?int $timestamp = null): ?array
{
    $timestamp ??= time();
    $delivery = hub_callback_claim_due_delivery($db, $timestamp);
    if ($delivery === null) {
        return null;
    }
    $target = $db->prepare('SELECT callback_url, signing_secret, enabled FROM task_callback_targets WHERE id = :id');
    $target->execute([':id' => (int)$delivery['callback_target_id']]);
    $current = $target->fetch();
    if (!$current) {
        hub_callback_finalize_delivery($db, $delivery, ['error' => 'callback_target_missing', 'final' => true], $timestamp);
        return ['delivery_id' => $delivery['delivery_id'], 'state' => 'disabled'];
    }
    if ((int)$current['enabled'] !== 1) {
        hub_callback_finalize_delivery($db, $delivery, ['error' => 'callback_target_disabled', 'final' => true], $timestamp);
        return ['delivery_id' => $delivery['delivery_id'], 'state' => 'disabled'];
    }
    $delivery['callback_url'] = $current['callback_url'];
    $delivery['signing_secret'] = $current['signing_secret'];
    $headers = hub_callback_headers($delivery, $timestamp);
    try {
        $result = $sender ? $sender($delivery, $headers) : hub_callback_send_http($delivery, $headers);
        if (!is_array($result)) {
            $result = ['error' => 'callback_sender_error'];
        }
    } catch (Throwable) {
        $result = ['error' => 'callback_sender_error'];
    }
    $finalized = hub_callback_finalize_delivery($db, $delivery, $result, $timestamp);
    $status = isset($result['status']) && is_int($result['status']) ? $result['status'] : null;

    return [
        'delivery_id' => $delivery['delivery_id'],
        'state' => !$finalized ? 'lost' : (($status !== null && $status >= 200 && $status < 300) ? 'delivered' : 'retry'),
    ];
}
