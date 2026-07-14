<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$defaultModes = 'hello,ocr,yolo,translate,sam3,chat';
$options = getopt('', ['base-url:', 'token:', 'modes::', 'image::', 'timeout::', 'real', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/api_smoke_client.php --base-url=https://host/3waAIHub/api.php --token=<TOKEN> [--modes=hello,ocr,yolo,translate,sam3,chat] [--image=sample.png] [--real]\n";
    exit(0);
}

$baseUrl = trim((string)($options['base-url'] ?? ''));
$token = trim((string)($options['token'] ?? ''));
if ($baseUrl === '' || $token === '') {
    fwrite(STDERR, "Missing --base-url or --token. Run with --help.\n");
    exit(2);
}
if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP curl extension is required.\n");
    exit(2);
}

$modes = array_values(array_filter(array_map('trim', explode(',', (string)($options['modes'] ?? $defaultModes)))));
$image = trim((string)($options['image'] ?? ''));
$timeout = max(3, (int)($options['timeout'] ?? 60));
$real = isset($options['real']);
$failed = 0;

foreach ($modes as $mode) {
    $result = hub_client_smoke_call($baseUrl, $token, $mode, $image, $real, $timeout);
    $label = $result['ok'] ? 'PASS' : 'FAIL';
    echo sprintf(
        "[%s] %s HTTP %s %sms %s\n",
        $label,
        $mode,
        (string)$result['status'],
        (string)$result['elapsed_ms'],
        (string)($result['request_id'] !== '' ? 'request_id=' . $result['request_id'] : '')
    );
    if (!$result['ok']) {
        $failed++;
        fwrite(STDERR, trim((string)$result['body']) . "\n");
    }
}

exit($failed === 0 ? 0 : 1);

function hub_client_smoke_call(string $baseUrl, string $token, string $mode, string $image, bool $real, int $timeout): array
{
    $url = hub_client_smoke_mode_url($baseUrl, $mode);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_TIMEOUT => $timeout,
    ];

    if ($mode === 'translate') {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode([
            'source_lang' => 'en',
            'target_lang' => 'zh-TW',
            'text' => 'That was a wonderful time.',
            'real_inference' => $real ? 1 : 0,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif ($mode === 'chat') {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode([
            'text' => '請用一句正體中文介紹 3waAIHub。',
            'system_prompt' => '你是 3waAIHub 本地 AI 助手，請簡潔回答。',
            'real_inference' => $real ? 1 : 0,
            'enable_thinking' => false,
            'max_tokens' => 128,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } elseif (in_array($mode, ['ocr', 'yolo', 'sam3'], true)) {
        $sample = $image !== '' ? $image : hub_client_smoke_default_image($mode);
        if (!is_file($sample)) {
            return ['ok' => false, 'status' => 0, 'elapsed_ms' => 0, 'request_id' => '', 'body' => 'Sample image not found: ' . $sample];
        }
        $fields = [
            'image' => new CURLFile($sample),
            'real_inference' => $real ? '1' : '0',
        ];
        if ($mode === 'sam3') {
            $fields['prompt_type'] = 'auto';
            $fields['output_format'] = 'metadata';
        }
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $fields;
    }

    $started = microtime(true);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'elapsed_ms' => 0, 'request_id' => '', 'body' => 'curl_init failed'];
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'elapsed_ms' => $elapsedMs, 'request_id' => '', 'body' => $error];
    }

    $status = (int)(curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0);
    $headerSize = (int)(curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0);
    curl_close($ch);
    $rawHeaders = substr((string)$raw, 0, $headerSize);
    $body = substr((string)$raw, $headerSize);
    $payload = json_decode($body, true);
    $requestId = hub_client_smoke_request_id($rawHeaders, is_array($payload) ? $payload : []);
    $ok = $status >= 200 && $status < 400 && is_array($payload) && ($payload['ok'] ?? false) !== false;

    return ['ok' => $ok, 'status' => $status, 'elapsed_ms' => $elapsedMs, 'request_id' => $requestId, 'body' => $body];
}

function hub_client_smoke_mode_url(string $baseUrl, string $mode): string
{
    $baseUrl = rtrim($baseUrl, '/');
    if (!str_ends_with($baseUrl, 'api.php')) {
        $baseUrl .= '/api.php';
    }

    return $baseUrl . '?mode=' . rawurlencode($mode);
}

function hub_client_smoke_default_image(string $mode): string
{
    $root = dirname(__DIR__);
    return match ($mode) {
        'yolo' => $root . '/packs/yolo/demo/camera_cat.png',
        'sam3' => $root . '/packs/sam3/demo/camera_cat.png',
        default => $root . '/packs/ocr-ppocrv5/demo/sample.png',
    };
}

function hub_client_smoke_request_id(string $headers, array $payload): string
{
    foreach (preg_split('/\R/', $headers) ?: [] as $line) {
        if (stripos($line, 'X-3waAIHub-Request-Id:') === 0) {
            return trim(substr($line, strlen('X-3waAIHub-Request-Id:')));
        }
    }

    return is_string($payload['request_id'] ?? null) ? $payload['request_id'] : '';
}
