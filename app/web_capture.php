<?php
declare(strict_types=1);

function hub_web_capture_parse_allowed_hosts(string $raw): array
{
    if (strlen($raw) > 16384) {
        throw new InvalidArgumentException('web_capture_allowed_hosts_too_large');
    }
    if (preg_match('//u', $raw) !== 1) {
        throw new InvalidArgumentException('web_capture_allowed_hosts_invalid_encoding');
    }
    $hosts = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $index => $line) {
        if (trim($line) === '') {
            continue;
        }
        $host = hub_web_capture_normalize_allowed_host($line);
        if ($host === null) {
            throw new InvalidArgumentException('web_capture_allowed_hosts_invalid_line:' . ($index + 1));
        }
        $hosts[$host] = true;
        if (count($hosts) > 128) {
            throw new InvalidArgumentException('web_capture_allowed_hosts_too_many');
        }
    }

    return array_keys($hosts);
}

function hub_web_capture_allowed_host_is_valid(string $host): bool
{
    return str_contains($host, '.')
        && strlen($host) <= 253
        && $host !== 'localhost'
        && !str_ends_with($host, '.localhost')
        && filter_var($host, FILTER_VALIDATE_IP) === false
        && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1;
}

function hub_web_capture_normalize_allowed_host(string $value): ?string
{
    $host = strtolower(rtrim(trim($value, " \t\r\n\0\x0B[]"), '.'));

    return hub_web_capture_allowed_host_is_valid($host) ? $host : null;
}

function hub_web_capture_allowed_hosts(PDO $db): array
{
    return hub_web_capture_parse_allowed_hosts(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS'));
}

function hub_web_capture_save_allowed_hosts(PDO $db, string $username, string $raw): array
{
    $hosts = hub_web_capture_parse_allowed_hosts($raw);
    $previous = hub_web_capture_allowed_hosts($db);
    $text = implode("\n", $hosts);
    $added = count(array_diff($hosts, $previous));
    $removed = count(array_diff($previous, $hosts));
    $db->beginTransaction();
    try {
        hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', $text);
        hub_audit($db, $username, 'web_capture_allowlist_updated', "added={$added} removed={$removed} total=" . count($hosts));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    return $hosts;
}

function hub_web_capture_allowed_hosts_error_message(string $code): string
{
    if (preg_match('/^web_capture_allowed_hosts_invalid_line:(\d+)$/', $code, $matches) === 1) {
        return 'Web Screenshot 允許主機第 ' . $matches[1] . ' 行格式不正確。';
    }

    return match ($code) {
        'web_capture_allowed_hosts_too_large' => 'Web Screenshot 允許主機清單不可超過 16384 bytes。',
        'web_capture_allowed_hosts_invalid_encoding' => 'Web Screenshot 允許主機清單必須使用有效 UTF-8 編碼。',
        'web_capture_allowed_hosts_too_many' => 'Web Screenshot 允許主機最多 128 個。',
        default => 'Web Screenshot 允許主機清單格式不正確。',
    };
}

function hub_web_capture_validate_input(PDO $db, array $input, ?callable $resolvePublicIps = null): array
{
    $url = $input['url'] ?? null;
    if (!is_string($url) || $url === '' || trim($url) !== $url) {
        throw new InvalidArgumentException('invalid_request');
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !isset($parts['host'])
        || array_key_exists('user', $parts)
        || array_key_exists('pass', $parts)) {
        throw new InvalidArgumentException('invalid_request');
    }

    $rawHost = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    $port = $parts['port'] ?? null;
    $resolve = $resolvePublicIps ?? 'hub_callback_resolve_public_ips';
    if ($rawHost === '' || $rawHost === 'localhost' || str_ends_with($rawHost, '.localhost')
        || ($port !== null && !in_array((int)$port, [80, 443], true))
        || $resolve($rawHost) === []) {
        throw new InvalidArgumentException('invalid_request');
    }

    $host = hub_web_capture_normalize_allowed_host($rawHost);
    if ($host === null || !in_array($host, hub_web_capture_allowed_hosts($db), true)) {
        throw new InvalidArgumentException('url_not_allowed');
    }

    $authority = $host . ($port === null ? '' : ':' . (int)$port);
    $input['url'] = strtolower((string)$parts['scheme']) . '://' . $authority . (string)($parts['path'] ?? '')
        . (array_key_exists('query', $parts) ? '?' . (string)$parts['query'] : '')
        . (array_key_exists('fragment', $parts) ? '#' . (string)$parts['fragment'] : '');

    return $input;
}

function hub_web_capture_prepare_runner_request(PDO $db, array $request): array
{
    $parts = parse_url((string)($request['url'] ?? ''));
    $host = is_array($parts) && isset($parts['host'])
        ? hub_web_capture_normalize_allowed_host((string)$parts['host'])
        : null;
    $hosts = hub_web_capture_allowed_hosts($db);
    if ($host === null || !in_array($host, $hosts, true)) {
        throw new RuntimeException('url_not_allowed');
    }

    return $request + ['allowed_hosts' => $hosts];
}
