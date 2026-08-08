<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

const HUB_FACEBOOK_SMOKE_JSON_MAX_BYTES = 1048576;
const HUB_FACEBOOK_SMOKE_ARTIFACT_MAX_BYTES = 4194304;

function hub_facebook_smoke_usage(): string
{
    return <<<'TEXT'
Usage:
  php scripts/facebook_crawler_smoke.php \
    --api-base=https://host/3waAIHub/api.php \
    --token-file=/path/outside/webroot/facebook-crawler.token \
    [--profile-id=fbp_<opaque>] \
    --target=https://www.facebook.com/<approved-page> \
    [--target=https://www.facebook.com/groups/<approved-group>] \
    [--limit=10] [--timeout=1200]
TEXT;
}

function hub_facebook_smoke_fail(string $message): never
{
    throw new RuntimeException($message);
}

function hub_facebook_smoke_token(string $path): string
{
    $real = realpath($path);
    $root = realpath(HUB_ROOT);
    if ($real === false || $root === false || is_link($path) || !is_file($real)) {
        hub_facebook_smoke_fail('token_file_invalid');
    }
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalized = str_replace('\\', '/', $real);
    if ($normalized === $root || str_starts_with($normalized, $rootPrefix)) {
        hub_facebook_smoke_fail('token_file_must_be_outside_webroot');
    }
    $stat = @lstat($real);
    if (!is_array($stat) || (int)($stat['nlink'] ?? 0) !== 1) {
        hub_facebook_smoke_fail('token_file_invalid');
    }
    if (hub_platform_id() !== 'windows' && (((int)$stat['mode']) & 0077) !== 0) {
        hub_facebook_smoke_fail('token_file_permissions_too_open');
    }
    $token = trim((string)file_get_contents($real));
    if (strlen($token) < 16 || strlen($token) > 512 || preg_match('/[\x00-\x20\x7f]/', $token) === 1) {
        hub_facebook_smoke_fail('token_file_invalid');
    }

    return $token;
}

function hub_facebook_smoke_base_url(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !str_ends_with((string)($parts['path'] ?? ''), '/api.php')
    ) {
        hub_facebook_smoke_fail('api_base_invalid');
    }

    return $url;
}

function hub_facebook_smoke_url(string $baseUrl, string $mode, array $query = []): string
{
    return $baseUrl . '?' . http_build_query(['mode' => $mode] + $query, '', '&', PHP_QUERY_RFC3986);
}

function hub_facebook_smoke_request(string $url, string $token, string $method = 'GET', ?array $payload = null, int $maxBytes = HUB_FACEBOOK_SMOKE_JSON_MAX_BYTES): array
{
    if (!function_exists('curl_init')) {
        hub_facebook_smoke_fail('curl_unavailable');
    }
    $body = '';
    $overflow = false;
    $headers = [];
    $ch = curl_init($url);
    if ($ch === false) {
        hub_facebook_smoke_fail('curl_unavailable');
    }
    $requestHeaders = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
    if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
            $headers[] = trim($line);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$overflow, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $overflow = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($overflow) {
        hub_facebook_smoke_fail('response_too_large');
    }
    if ($ok === false) {
        hub_facebook_smoke_fail('request_failed:' . $error);
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

function hub_facebook_smoke_json(string $baseUrl, string $token, string $mode, string $method = 'GET', ?array $payload = null, array $query = []): array
{
    $response = hub_facebook_smoke_request(hub_facebook_smoke_url($baseUrl, $mode, $query), $token, $method, $payload);
    $decoded = json_decode((string)$response['body'], true);
    if ((int)$response['status'] < 200 || (int)$response['status'] >= 300 || !is_array($decoded) || empty($decoded['ok'])) {
        $code = is_array($decoded) ? (string)($decoded['error'] ?? 'invalid_response') : 'invalid_response';
        hub_facebook_smoke_fail($mode . '_failed:' . $code . ':http_' . (int)$response['status']);
    }

    return $decoded;
}

function hub_facebook_smoke_artifact(array $result): array
{
    foreach ((array)($result['result']['artifacts'] ?? []) as $artifact) {
        if (is_array($artifact) && ($artifact['type'] ?? '') === 'facebook_posts_jsonl') {
            $id = filter_var($artifact['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                return ['id' => (int)$id] + $artifact;
            }
        }
    }
    hub_facebook_smoke_fail('dataset_artifact_missing');
}

function hub_facebook_smoke_jsonl(string $body): int
{
    $count = 0;
    foreach (preg_split('/\R/u', $body) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $item = json_decode($line, true);
        if (!is_array($item)) {
            hub_facebook_smoke_fail('dataset_artifact_invalid');
        }
        $count++;
    }

    return $count;
}

function hub_facebook_smoke_main(array $argv): int
{
    $started = microtime(true);
    $options = getopt('', ['api-base:', 'token-file:', 'profile-id::', 'target:', 'limit::', 'timeout::', 'help']);
    if (isset($options['help'])) {
        echo hub_facebook_smoke_usage() . PHP_EOL;
        return 0;
    }
    try {
        $baseUrl = hub_facebook_smoke_base_url(trim((string)($options['api-base'] ?? '')));
        $token = hub_facebook_smoke_token(trim((string)($options['token-file'] ?? '')));
        $targets = $options['target'] ?? [];
        $targets = is_array($targets) ? $targets : [$targets];
        if ($targets === [] || count($targets) > 30) {
            hub_facebook_smoke_fail('target_count_invalid');
        }
        $targetPayload = array_map(
            static fn (mixed $target): array => ['url' => hub_facebook_crawl_target_url(trim((string)$target))],
            $targets
        );
        $limit = filter_var($options['limit'] ?? 10, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 30]]);
        $timeout = filter_var($options['timeout'] ?? 1200, FILTER_VALIDATE_INT, ['options' => ['min_range' => 30, 'max_range' => 3600]]);
        if ($limit === false || $timeout === false) {
            hub_facebook_smoke_fail('smoke_bounds_invalid');
        }
        $payload = ['targets' => $targetPayload, 'limit_per_target' => (int)$limit];
        $profileId = trim((string)($options['profile-id'] ?? ''));
        if ($profileId !== '') {
            if (preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
                hub_facebook_smoke_fail('profile_id_invalid');
            }
            $payload = ['profile_id' => $profileId] + $payload;
        }

        $submitted = hub_facebook_smoke_json($baseUrl, $token, 'facebook_crawl', 'POST', $payload);
        $taskId = filter_var($submitted['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($taskId === false) {
            hub_facebook_smoke_fail('task_id_invalid');
        }
        $deadline = microtime(true) + (int)$timeout;
        do {
            $status = hub_facebook_smoke_json($baseUrl, $token, 'task_status', 'GET', null, ['task_id' => (int)$taskId]);
            $state = (string)($status['status'] ?? '');
            if ($state === 'success') {
                break;
            }
            if (in_array($state, ['failed', 'cancelled'], true)) {
                hub_facebook_smoke_fail('task_terminal:' . $state . ':' . (string)($status['error_code'] ?? 'unknown'));
            }
            usleep(2000000);
        } while (microtime(true) < $deadline);
        if (($state ?? '') !== 'success') {
            hub_facebook_smoke_fail('task_timeout');
        }

        $result = hub_facebook_smoke_json($baseUrl, $token, 'task_result', 'GET', null, ['task_id' => (int)$taskId]);
        $artifact = hub_facebook_smoke_artifact($result);
        $dataset = hub_facebook_smoke_json($baseUrl, $token, 'facebook_dataset_items', 'GET', null, ['task_id' => (int)$taskId, 'offset' => 0, 'limit' => 10]);
        if (!is_array($dataset['items'] ?? null) || (int)($dataset['task_id'] ?? 0) !== (int)$taskId) {
            hub_facebook_smoke_fail('dataset_page_invalid');
        }
        $download = hub_facebook_smoke_request(
            hub_facebook_smoke_url($baseUrl, 'artifact', ['artifact_id' => (int)$artifact['id']]),
            $token,
            'GET',
            null,
            HUB_FACEBOOK_SMOKE_ARTIFACT_MAX_BYTES
        );
        if ((int)$download['status'] !== 200) {
            hub_facebook_smoke_fail('dataset_download_failed:http_' . (int)$download['status']);
        }
        $jsonlCount = hub_facebook_smoke_jsonl((string)$download['body']);
        $expectedSha256 = strtolower((string)($artifact['sha256'] ?? ''));
        $actualSha256 = hash('sha256', (string)$download['body']);
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedSha256) !== 1 || !hash_equals($expectedSha256, $actualSha256)) {
            hub_facebook_smoke_fail('dataset_artifact_sha256_mismatch');
        }
        if ($jsonlCount < 1 || count($dataset['items']) < 1) {
            hub_facebook_smoke_fail('dataset_has_no_posts');
        }
        echo hub_json_encode([
            'ok' => true,
            'task_id' => (int)$taskId,
            'target_count' => count($targetPayload),
            'preview_count' => count($dataset['items']),
            'jsonl_count' => $jsonlCount,
            'elapsed_ms' => (int)round((microtime(true) - $started) * 1000),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

        return 0;
    } catch (Throwable $error) {
        fwrite(STDERR, 'facebook_crawler_smoke failed: ' . $error->getMessage() . PHP_EOL);
        return 1;
    }
}

exit(hub_facebook_smoke_main($argv));
