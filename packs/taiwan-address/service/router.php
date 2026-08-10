<?php
declare(strict_types=1);

const TWADDR_OPERATIONS = [
    'cities' => [],
    'autocomplete' => ['q', 'address', 'city', 'limit', 'lon', 'lat'],
    'searchAddress' => ['q', 'address', 'city', 'limit'],
    'searchAlias' => ['q', 'address', 'limit'],
    'searchAll' => ['q', 'address', 'city', 'limit', 'lon', 'lat'],
    'getAddress_XY' => ['q', 'address'],
    'nearestAddress' => ['lon', 'lat', 'radius', 'limit'],
    'bboxAddress' => ['minLon', 'minLat', 'maxLon', 'maxLat', 'limit'],
    'searchPoi' => ['q', 'address', 'city', 'limit', 'lon', 'lat'],
    'searchOpenData' => ['q', 'address', 'domain', 'limit', 'lon', 'lat', 'autocomplete'],
    'searchTourism' => ['q', 'address', 'limit', 'lon', 'lat'],
    'searchTransport' => ['q', 'address', 'limit', 'lon', 'lat'],
    'searchBusiness' => ['q', 'address', 'limit', 'lon', 'lat'],
    'searchHealthcare' => ['q', 'address', 'limit', 'lon', 'lat'],
    'searchFacility' => ['q', 'address', 'limit', 'lon', 'lat'],
];

function twaddr_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function twaddr_upstream_url(): ?string
{
    $url = trim((string)getenv('TWADDR_UPSTREAM_URL'));
    $parts = $url === '' ? false : parse_url($url);
    if ($parts === false || !is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !isset($parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
        return null;
    }

    return twaddr_request_url($url, []);
}

function twaddr_request_url(string $baseUrl, array $params): ?string
{
    $parts = parse_url($baseUrl);
    if ($parts === false || !is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !isset($parts['host']) || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
        return null;
    }

    $host = (string)$parts['host'];
    if ($host === '' || preg_match('/[\x00-\x20\/\\?#@]/', $host) === 1) {
        return null;
    }
    $path = (string)($parts['path'] ?? '');
    if ($path !== '' && (!str_starts_with($path, '/') || str_contains($path, '..'))) {
        return null;
    }
    $port = isset($parts['port']) ? (int)$parts['port'] : 0;
    if ($port < 0 || $port > 65535) {
        return null;
    }

    $url = strtolower((string)$parts['scheme']) . '://' . $host . ($port > 0 ? ':' . $port : '') . $path;
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $query === '' ? $url : $url . '?' . $query;
}

function twaddr_input(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return $_POST;
    }
    try {
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        twaddr_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        twaddr_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }

    return $decoded;
}

function twaddr_params(array $input, string $operation): array
{
    if (!isset(TWADDR_OPERATIONS[$operation])) {
        twaddr_json(400, ['ok' => false, 'error' => 'operation_not_allowed']);
    }

    $params = ['mode' => $operation];
    foreach (TWADDR_OPERATIONS[$operation] as $name) {
        if (!array_key_exists($name, $input)) {
            continue;
        }
        $value = $input[$name];
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value)) {
            twaddr_json(400, ['ok' => false, 'error' => 'invalid_request', 'field' => $name]);
        }
        $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
        if (strlen($value) > 512) {
            twaddr_json(400, ['ok' => false, 'error' => 'invalid_request', 'field' => $name]);
        }
        $params[$name] = $value;
    }

    return $params;
}

function twaddr_fetch(string $url, int $timeoutSec): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSec,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);
    set_error_handler(static fn (): bool => true);
    try {
        $body = file_get_contents($url, false, $context);
    } finally {
        restore_error_handler();
    }
    if ($body === false) {
        twaddr_json(503, ['ok' => false, 'error' => 'upstream_unavailable']);
    }
    $status = 502;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $match) === 1) {
            $status = (int)$match[1];
        }
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        twaddr_json(502, ['ok' => false, 'error' => 'upstream_invalid_response']);
    }
    if (!is_array($payload)) {
        twaddr_json(502, ['ok' => false, 'error' => 'upstream_invalid_response']);
    }

    return [$status, $payload];
}

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$upstream = twaddr_upstream_url();
if ($upstream === null) {
    twaddr_json(503, ['ok' => false, 'error' => 'upstream_not_configured']);
}
$timeout = min(30, max(1, (int)(getenv('TWADDR_TIMEOUT_SEC') ?: 10)));
if ($path === '/health') {
    [$status, $payload] = twaddr_fetch(twaddr_request_url($upstream, ['mode' => 'health']) ?? $upstream, $timeout);
    twaddr_json($status, $payload);
}
if ($path !== '/lookup') {
    twaddr_json(404, ['ok' => false, 'error' => 'not_found']);
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    twaddr_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$input = twaddr_input();
$operation = (string)($input['operation'] ?? '');
[$status, $payload] = twaddr_fetch(twaddr_request_url($upstream, twaddr_params($input, $operation)) ?? $upstream, $timeout);
twaddr_json($status, $payload);
