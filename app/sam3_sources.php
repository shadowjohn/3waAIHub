<?php
declare(strict_types=1);

function hub_sam3_normalize_hls_host(string $value): ?string
{
    $host = strtolower(rtrim(trim($value, " \t\r\n\0\x0B[]"), '.'));

    return $host !== ''
        && strlen($host) <= 253
        && filter_var($host, FILTER_VALIDATE_IP) === false
        && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1
        ? $host
        : null;
}

function hub_sam3_parse_hls_allowed_hosts(string $raw): array
{
    if (strlen($raw) > 16384 || preg_match('//u', $raw) !== 1) {
        throw new InvalidArgumentException('hls_allowlist_invalid');
    }
    $hosts = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $host = hub_sam3_normalize_hls_host($line);
        if ($host === null) {
            throw new InvalidArgumentException('hls_allowlist_invalid');
        }
        $hosts[$host] = true;
        if (count($hosts) > 128) {
            throw new InvalidArgumentException('hls_allowlist_invalid');
        }
    }

    return array_keys($hosts);
}

function hub_sam3_hls_allowed_hosts(PDO $db): array
{
    return hub_sam3_parse_hls_allowed_hosts(hub_get_storage_setting($db, 'AIHUB_SAM3_HLS_ALLOWED_HOSTS'));
}

function hub_sam3_save_hls_allowed_hosts(PDO $db, string $username, string $raw): array
{
    $hosts = hub_sam3_parse_hls_allowed_hosts($raw);
    hub_set_storage_setting($db, 'AIHUB_SAM3_HLS_ALLOWED_HOSTS', implode("\n", $hosts));
    hub_audit($db, $username, 'sam3_hls_allowlist_updated', 'total=' . count($hosts));

    return $hosts;
}

function hub_sam3_private_camera_ip(string $host): bool
{
    $ip = trim($host, '[]');
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        foreach ([['10.0.0.0', 8], ['172.16.0.0', 12], ['192.168.0.0', 16]] as [$network, $prefix]) {
            if (hub_callback_ip_in_cidr($ip, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
        && hub_callback_ip_in_cidr($ip, 'fc00::', 7);
}

function hub_sam3_normalize_source_url(string $value, array $hlsAllowlist): ?array
{
    if ($value === '' || trim($value) !== $value || strlen($value) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        return null;
    }
    $parts = parse_url($value);
    if (!is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || array_key_exists('user', $parts)
        || array_key_exists('pass', $parts)
        || array_key_exists('query', $parts)
        || array_key_exists('fragment', $parts)) {
        return null;
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    $port = $parts['port'] ?? null;
    $path = (string)($parts['path'] ?? '/');
    if ($host === '' || !str_starts_with($path, '/') || ($port !== null && ((int)$port < 1 || (int)$port > 65535))) {
        return null;
    }

    if (in_array($scheme, ['rtsp', 'rtsps'], true) && hub_sam3_private_camera_ip($host)) {
        $authority = str_contains($host, ':') ? '[' . $host . ']' : $host;

        return ['protocol' => $scheme, 'url' => $scheme . '://' . $authority . ($port === null ? '' : ':' . (int)$port) . $path];
    }

    $allowedHosts = [];
    foreach ($hlsAllowlist as $allowedHost) {
        if (is_string($allowedHost) && ($normalized = hub_sam3_normalize_hls_host($allowedHost)) !== null) {
            $allowedHosts[$normalized] = true;
        }
    }
    if ($scheme !== 'https' || !isset($allowedHosts[$host]) || ($port !== null && (int)$port !== 443) || !str_ends_with(strtolower($path), '.m3u8')) {
        return null;
    }

    return ['protocol' => 'hls', 'url' => 'https://' . $host . ($port === null ? '' : ':' . (int)$port) . $path];
}

function hub_sam3_source_name(string $value): string
{
    $value = trim($value);
    if ($value === '' || strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
        throw new InvalidArgumentException('source_name_invalid');
    }

    return $value;
}

function hub_sam3_source_clip_seconds(mixed $value): int
{
    $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 60]]);
    if ($seconds === false) {
        throw new InvalidArgumentException('source_clip_seconds_invalid');
    }

    return (int)$seconds;
}

function hub_sam3_source_service(PDO $db, int $serviceId): array
{
    $service = hub_get_service($db, $serviceId);
    if (!is_array($service) || (string)($service['pack_id'] ?? '') !== 'sam3' || (string)($service['mode'] ?? '') !== 'sam3') {
        throw new InvalidArgumentException('sam3_service_not_found');
    }

    return $service;
}

function hub_sam3_source_url_from_admin_input(PDO $db, string $sourceUrl): array
{
    return hub_sam3_normalize_source_url($sourceUrl, hub_sam3_hls_allowed_hosts($db))
        ?? throw new InvalidArgumentException('source_not_allowed');
}

function hub_sam3_create_source(PDO $db, int $serviceId, string $displayName, string $sourceUrl, mixed $clipSeconds, int $userId): array
{
    hub_sam3_source_service($db, $serviceId);
    $source = hub_sam3_source_url_from_admin_input($db, $sourceUrl);
    $now = hub_now();
    $sourceId = 'sam3src_' . bin2hex(random_bytes(16));
    $db->prepare(
        'INSERT INTO sam3_sources
            (source_id, service_id, display_name, protocol, source_url, clip_seconds, created_by, created_at, updated_at)
         VALUES
            (:source_id, :service_id, :display_name, :protocol, :source_url, :clip_seconds, :created_by, :created_at, :updated_at)'
    )->execute([
        ':source_id' => $sourceId,
        ':service_id' => $serviceId,
        ':display_name' => hub_sam3_source_name($displayName),
        ':protocol' => $source['protocol'],
        ':source_url' => $source['url'],
        ':clip_seconds' => hub_sam3_source_clip_seconds($clipSeconds),
        ':created_by' => $userId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return hub_sam3_get_source($db, $sourceId, $serviceId, true) ?? throw new RuntimeException('source_create_failed');
}

function hub_sam3_get_source(PDO $db, string $sourceId, int $serviceId, bool $includeUrl = false): ?array
{
    $stmt = $db->prepare('SELECT * FROM sam3_sources WHERE source_id = :source_id AND service_id = :service_id LIMIT 1');
    $stmt->execute([':source_id' => $sourceId, ':service_id' => $serviceId]);
    $source = $stmt->fetch();
    if (!is_array($source)) {
        return null;
    }

    if (!$includeUrl) {
        unset($source['source_url']);
    }

    return $source;
}

function hub_sam3_list_sources(PDO $db, int $serviceId, bool $includeUrl = false): array
{
    $stmt = $db->prepare('SELECT * FROM sam3_sources WHERE service_id = :service_id ORDER BY updated_at DESC, id DESC');
    $stmt->execute([':service_id' => $serviceId]);
    $sources = $stmt->fetchAll();
    foreach ($sources as &$source) {
        if (!$includeUrl) {
            unset($source['source_url']);
        }
    }
    unset($source);

    return $sources;
}

function hub_sam3_set_source_enabled(PDO $db, string $sourceId, int $serviceId, bool $enabled): void
{
    $stmt = $db->prepare(
        'UPDATE sam3_sources SET enabled = :enabled, updated_at = :updated_at
         WHERE source_id = :source_id AND service_id = :service_id'
    );
    $stmt->execute([
        ':enabled' => $enabled ? 1 : 0,
        ':updated_at' => hub_now(),
        ':source_id' => $sourceId,
        ':service_id' => $serviceId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('source_not_found');
    }
}

function hub_sam3_delete_source(PDO $db, string $sourceId, int $serviceId): void
{
    $stmt = $db->prepare('DELETE FROM sam3_sources WHERE source_id = :source_id AND service_id = :service_id');
    $stmt->execute([':source_id' => $sourceId, ':service_id' => $serviceId]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('source_not_found');
    }
}

function hub_sam3_source_for_task(PDO $db, string $sourceId, int $serviceId): ?array
{
    if (preg_match('/^sam3src_[a-f0-9]{32}$/', $sourceId) !== 1) {
        return null;
    }
    $source = hub_sam3_get_source($db, $sourceId, $serviceId, true);

    if (!is_array($source) || (int)($source['enabled'] ?? 0) !== 1) {
        return null;
    }
    if (($source['protocol'] ?? '') === 'hls') {
        $normalized = hub_sam3_normalize_source_url((string)$source['source_url'], hub_sam3_hls_allowed_hosts($db));
        $parts = parse_url((string)$source['source_url']);
        $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
        if ($normalized === null || $normalized['url'] !== $source['source_url'] || hub_callback_resolve_public_ips($host) === []) {
            return null;
        }
    }

    return $source;
}

function hub_sam3_note_source_capture(PDO $db, string $sourceId, int $serviceId, ?string $errorCode): void
{
    $db->prepare(
        'UPDATE sam3_sources
         SET last_error_code = :error_code, last_seen_at = :last_seen_at, updated_at = :updated_at
         WHERE source_id = :source_id AND service_id = :service_id'
    )->execute([
        ':error_code' => $errorCode,
        ':last_seen_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':source_id' => $sourceId,
        ':service_id' => $serviceId,
    ]);
}

function hub_sam3_capture_command(array $source, string $destination): array
{
    $sourceUrl = (string)($source['source_url'] ?? '');
    $sourceProtocol = (string)($source['protocol'] ?? '');
    $parts = parse_url($sourceUrl);
    $hlsHost = is_array($parts) ? hub_sam3_normalize_hls_host((string)($parts['host'] ?? '')) : null;
    $normalized = hub_sam3_normalize_source_url($sourceUrl, $hlsHost === null ? [] : [$hlsHost]);
    $seconds = hub_sam3_source_clip_seconds($source['clip_seconds'] ?? null);
    if ($sourceProtocol === 'hls'
        || $normalized === null
        || $normalized['protocol'] !== $sourceProtocol
        || $normalized['url'] !== $sourceUrl
        || !str_ends_with($destination, '.mp4')) {
        throw new RuntimeException('capture_failed');
    }

    $command = ['ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-rw_timeout', '15000000'];
    if (in_array($sourceProtocol, ['rtsp', 'rtsps'], true)) {
        $command[] = '-rtsp_transport';
        $command[] = 'tcp';
    }

    return [...$command, '-i', $sourceUrl, '-t', (string)$seconds, '-map', '0:v:0', '-an', '-c:v', 'copy', '-movflags', '+faststart', '-fs', '536870912', '-f', 'mp4', $destination];
}

function hub_sam3_run_capture_command(array $command): bool
{
    try {
        $command = hub_safe_argv($command);
    } catch (InvalidArgumentException) {
        return false;
    }

    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return false;
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            stream_set_blocking($pipe, false);
        }
    }

    $startedAt = microtime(true);
    $exitCode = null;
    do {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                stream_get_contents($pipe, 8192);
            }
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $exitCode = (int)$status['exitcode'];
            break;
        }
        if (microtime(true) - $startedAt >= 90) {
            proc_terminate($process);
            break;
        }
        usleep(100000);
    } while (true);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            stream_get_contents($pipe, 8192);
            fclose($pipe);
        }
    }

    $closed = proc_close($process);

    return ($exitCode ?? $closed) === 0;
}

function hub_sam3_capture_source_to_task(array $source, int $taskId): string
{
    if ($taskId < 1) {
        throw new RuntimeException('capture_failed');
    }
    $dir = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId;
    if (is_link($dir) || file_exists($dir) || (!is_dir($dir) && !mkdir($dir, 0775, true))) {
        throw new RuntimeException('capture_failed');
    }
    $destination = $dir . '/input.mp4';
    try {
        if (!hub_sam3_run_capture_command(hub_sam3_capture_command($source, $destination))) {
            throw new RuntimeException('capture_failed');
        }
        clearstatcache(true, $destination);
        $size = is_file($destination) && !is_link($destination) ? filesize($destination) : false;
        if ($size === false || $size < 1 || $size > 536870912) {
            throw new RuntimeException('capture_failed');
        }

        return $destination;
    } catch (Throwable $e) {
        if (is_file($destination) && !is_link($destination)) {
            unlink($destination);
        }
        if (is_dir($dir) && !is_link($dir) && (scandir($dir) === ['.', '..'])) {
            rmdir($dir);
        }
        throw new RuntimeException('capture_failed', previous: $e);
    }
}
