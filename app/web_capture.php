<?php
declare(strict_types=1);

function hub_web_capture_validate_input(array $input): array
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

    $host = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
    $port = $parts['port'] ?? null;
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')
        || ($port !== null && !in_array((int)$port, [80, 443], true))
        || hub_callback_resolve_public_ips($host) === []) {
        throw new InvalidArgumentException('invalid_request');
    }

    $authority = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    if ($port !== null) {
        $authority .= ':' . (int)$port;
    }
    $input['url'] = strtolower((string)$parts['scheme']) . '://' . $authority . (string)($parts['path'] ?? '')
        . (array_key_exists('query', $parts) ? '?' . (string)$parts['query'] : '')
        . (array_key_exists('fragment', $parts) ? '#' . (string)$parts['fragment'] : '');

    return $input;
}
