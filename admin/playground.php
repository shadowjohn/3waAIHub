<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function hub_playground_profiles(): array
{
    return [
        'hello' => ['label' => 'Hello', 'method' => 'GET', 'kind' => 'none'],
        'translate' => ['label' => 'Translate', 'method' => 'POST', 'kind' => 'json'],
        'ocr' => ['label' => 'OCR', 'method' => 'POST', 'kind' => 'image'],
        'yolo' => ['label' => 'YOLO', 'method' => 'POST', 'kind' => 'image'],
        'sam3' => ['label' => 'SAM3', 'method' => 'POST', 'kind' => 'sam3'],
        'tts' => ['label' => 'TTS', 'method' => 'POST', 'kind' => 'json'],
        'structure' => ['label' => 'Structure', 'method' => 'POST', 'kind' => 'document'],
    ];
}

function hub_playground_selected_service(array $services, string $mode): ?array
{
    foreach ($services as $service) {
        if ((string)$service['mode'] === $mode) {
            return $service;
        }
    }

    return $services[0] ?? null;
}

function hub_playground_endpoint(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $gateway = is_array($pack['manifest']['gateway'] ?? null) ? $pack['manifest']['gateway'] : [];
    $methods = array_map('strval', is_array($gateway['methods'] ?? null) ? $gateway['methods'] : []);
    return trim(($methods === [] ? '' : implode('/', $methods)) . ' ' . (string)($gateway['invoke_path'] ?? ''));
}

function hub_playground_runtime_level(array $service): string
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    return (string)($pack['manifest']['runtime_level'] ?? '');
}

function hub_playground_base_path(): string
{
    $adminDir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/admin/playground.php')), '/');
    return preg_replace('#/admin$#', '', $adminDir) ?: '';
}

function hub_playground_api_url(string $mode): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $basePath = hub_playground_base_path();

    return ($https ? 'https' : 'http') . '://' . $host . $basePath . '/api.php?mode=' . rawurlencode($mode);
}

function hub_playground_local_api_url(string $mode): string
{
    return 'http://127.0.0.1' . hub_playground_base_path() . '/api.php?mode=' . rawurlencode($mode);
}

function hub_playground_mask_token(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    return substr($token, 0, 12) . '...';
}

function hub_playground_request_payload(string $mode): array
{
    if ($mode === 'translate') {
        return [
            'source_lang' => trim((string)($_POST['source_lang'] ?? 'en')),
            'target_lang' => trim((string)($_POST['target_lang'] ?? 'zh-TW')),
            'text' => trim((string)($_POST['text'] ?? 'That was a wonderful time.')),
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'tts') {
        return [
            'mode' => trim((string)($_POST['tts_mode'] ?? 'design')) ?: 'design',
            'text' => trim((string)($_POST['text'] ?? 'RC 閥是用來控制二行程引擎排氣時機的重要機構。')),
            'voice_prompt' => trim((string)($_POST['voice_prompt'] ?? '沉穩的台灣男性技師，語速稍慢，清楚自然')),
            'reference_audio_id' => trim((string)($_POST['reference_audio_id'] ?? '')),
            'control' => trim((string)($_POST['control'] ?? '沉穩、稍慢、像技師解說')),
            'seed' => (int)($_POST['seed'] ?? 42),
            'format' => 'wav',
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'sam3') {
        return [
            'prompt_type' => trim((string)($_POST['prompt_type'] ?? 'auto')) ?: 'auto',
            'points_json' => trim((string)($_POST['points_json'] ?? '')),
            'output_format' => trim((string)($_POST['output_format'] ?? 'metadata')) ?: 'metadata',
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if (in_array($mode, ['ocr', 'yolo'], true)) {
        return ['real_inference' => !empty($_POST['real_inference']) ? 1 : 0];
    }
    if ($mode === 'structure') {
        return [
            'output_format' => trim((string)($_POST['output_format'] ?? 'both')) ?: 'both',
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }

    return [];
}

function hub_playground_basic_readiness(array $service): ?array
{
    if ((int)($service['enabled'] ?? 0) !== 1) {
        return [
            'error' => 'service_disabled',
            'message' => '服務已停用，請先啟用服務。',
        ];
    }
    if ((string)($service['status'] ?? '') !== 'running') {
        return [
            'error' => 'service_not_running',
            'message' => '服務尚未執行，請先啟動服務。',
        ];
    }

    return null;
}

function hub_playground_health_error(array $service): ?string
{
    $url = trim((string)($service['health_url'] ?? ''));
    if ($url === '' || !function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return 'health curl unavailable';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 3,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
    $error = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($raw !== false && $status >= 200 && $status < 400) {
        $payload = json_decode((string)$raw, true);
        if (is_array($payload) && ((isset($payload['ok']) && $payload['ok'] === false) || (isset($payload['ready']) && $payload['ready'] === false))) {
            return 'health payload not ready';
        }

        return null;
    }

    return trim($error . ' HTTP ' . $status);
}

function hub_playground_readiness_guard(array $service): ?array
{
    $basic = hub_playground_basic_readiness($service);
    if ($basic !== null) {
        return $basic;
    }

    $healthError = hub_playground_health_error($service);
    if ($healthError !== null) {
        return [
            'error' => 'service_health_failed',
            'message' => '服務容器正在執行，但服務健康檢查失敗，API 可能無法使用。',
            'detail' => $healthError,
        ];
    }

    return null;
}

function hub_playground_guard_result(array $guard): array
{
    return [
        'ok' => false,
        'status' => '-',
        'elapsed_ms' => 0,
        'request_id' => '',
        'error' => (string)$guard['error'],
        'message' => (string)$guard['message'],
        'pretty_body' => json_encode($guard, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_playground_error_message(int $status, string $curlError = '', string $gatewayError = ''): string
{
    if (stripos($curlError, 'timed out') !== false || $status === 504 || $gatewayError === 'gateway_timeout') {
        return 'Gateway 呼叫逾時。';
    }
    if (in_array($status, [401, 403], true) || in_array($gatewayError, ['missing_token', 'invalid_token', 'token_mode_denied', 'token_ip_denied'], true)) {
        return 'Token 無效或無權限。';
    }
    if ($curlError !== '' || in_array($gatewayError, ['service_unavailable', 'proxy_error'], true)) {
        return '後端服務無法連線。';
    }

    return 'Gateway 回傳錯誤。';
}

function hub_playground_execute(string $mode, string $token): array
{
    $profiles = hub_playground_profiles();
    $profile = $profiles[$mode] ?? null;
    if (!$profile) {
        return ['ok' => false, 'error' => 'unsupported_mode'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $url = hub_playground_local_api_url($mode);
    $started = microtime(true);
    $headers = ['Accept: application/json'];
    $token = trim($token);
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 180,
    ];

    if ($profile['method'] === 'POST') {
        $options[CURLOPT_POST] = true;
        $payload = hub_playground_request_payload($mode);
        if ($profile['kind'] === 'json') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $fieldName = $mode === 'structure' ? 'file' : 'image';
            $file = $_FILES[$fieldName] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                curl_close($ch);
                return ['ok' => false, 'error' => 'missing_file', 'message' => $mode === 'structure' ? '請選擇 PDF 或文件圖片。' : '請選擇圖片檔。'];
            }
            $payload[$fieldName] = new CURLFile(
                (string)$file['tmp_name'],
                (string)($file['type'] ?? 'application/octet-stream'),
                (string)($file['name'] ?? 'image')
            );
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
    }

    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'request_failed', 'message' => hub_playground_error_message(0, $error), 'detail' => $error, 'elapsed_ms' => $elapsedMs];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    curl_close($ch);
    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $requestId = '';
    foreach (preg_split('/\R/', $rawHeaders) ?: [] as $line) {
        if (stripos($line, 'X-3waAIHub-Request-Id:') === 0) {
            $requestId = trim(substr($line, strlen('X-3waAIHub-Request-Id:')));
        }
    }
    $payload = json_decode($body, true);
    if ($requestId === '' && is_array($payload) && is_string($payload['request_id'] ?? null)) {
        $requestId = $payload['request_id'];
    }
    $gatewayError = is_array($payload) ? (string)($payload['error'] ?? $payload['error_code'] ?? '') : '';
    $ok = $status >= 200 && $status < 400;

    return [
        'ok' => $ok,
        'status' => $status,
        'elapsed_ms' => $elapsedMs,
        'request_id' => $requestId,
        'error' => $ok ? '' : ($gatewayError ?: 'request_failed'),
        'message' => $ok ? '' : hub_playground_error_message((int)$status, '', $gatewayError),
        'body' => $body,
        'pretty_body' => hub_playground_pretty_json($body),
    ];
}

function hub_playground_pretty_json(string $body): string
{
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return $body;
    }

    return (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function hub_playground_tts_artifact_file(array $result): string
{
    $payload = json_decode((string)($result['body'] ?? ''), true);
    $artifactUrl = is_array($payload) ? (string)($payload['artifact_url'] ?? '') : '';
    if ($artifactUrl === '' || !str_starts_with($artifactUrl, '/artifacts/')) {
        return '';
    }
    $file = basename($artifactUrl);
    return preg_match('/^tts_[A-Za-z0-9_-]+\.wav$/', $file) === 1 ? $file : '';
}

function hub_playground_tts_audio_url(array $service, ?array $result): string
{
    if ($result === null || empty($result['ok'])) {
        return '';
    }
    $file = hub_playground_tts_artifact_file($result);
    if ($file === '') {
        return '';
    }

    return 'playground_artifact.php?' . http_build_query([
        'service_id' => (int)$service['id'],
        'file' => $file,
    ]);
}

function hub_playground_examples(string $mode): array
{
    $url = hub_playground_api_url($mode);
    $phpUrl = var_export($url, true);
    $jsUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($mode === 'hello') {
        $curl = 'curl -H "Authorization: Bearer <TOKEN>" "' . $url . '"';
        $php = <<<PHP
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const res = await fetch($jsUrl, {
  headers: { Authorization: 'Bearer <TOKEN>' }
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'translate') {
        $json = '{"source_lang":"en","target_lang":"zh-TW","text":"That was a wonderful time.","real_inference":0}';
        $curl = "curl -X POST \"$url\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'source_lang' => 'en',
    'target_lang' => 'zh-TW',
    'text' => 'That was a wonderful time.',
    'real_inference' => 0,
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <TOKEN>',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_UNICODE),
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: {
    Authorization: 'Bearer <TOKEN>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    source_lang: 'en',
    target_lang: 'zh-TW',
    text: 'That was a wonderful time.',
    real_inference: 0
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'tts') {
        $json = '{"mode":"design","text":"RC 閥是用來控制二行程引擎排氣時機的重要機構。","voice_prompt":"沉穩的台灣男性技師，語速稍慢，清楚自然","seed":42,"format":"wav"}';
        $curl = "curl -X POST \"$url\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'mode' => 'design',
    'text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
    'voice_prompt' => '沉穩的台灣男性技師，語速稍慢，清楚自然',
    'seed' => 42,
    'format' => 'wav',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <TOKEN>',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_UNICODE),
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: {
    Authorization: 'Bearer <TOKEN>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    mode: 'design',
    text: 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
    voice_prompt: '沉穩的台灣男性技師，語速稍慢，清楚自然',
    seed: 42,
    format: 'wav'
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }

    $field = $mode === 'structure' ? 'file' : 'image';
    $extra = $mode === 'sam3' ? " \\\n  -F prompt_type=auto \\\n  -F output_format=metadata" : '';
    $sampleFile = $mode === 'structure' ? 'sample.pdf' : 'sample.png';
    $outputFormat = $mode === 'structure' ? 'both' : 'metadata';
    $realInference = $mode === 'structure' ? '1' : '0';
    $phpExtra = $mode === 'sam3' ? "        'prompt_type' => 'auto',\n" : '';
    $jsExtra = $mode === 'sam3' ? "form.append('prompt_type', 'auto');\n" : '';
    $curl = "curl -X POST \"$url\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -H \"Content-Type: multipart/form-data\" \\\n  -F {$field}=@sample.png \\\n  -F real_inference={$realInference}{$extra}";
    if ($mode === 'structure') {
        $curl = "curl -X POST \"$url\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -H \"Content-Type: multipart/form-data\" \\\n  -F {$field}=@{$sampleFile} \\\n  -F output_format=both \\\n  -F real_inference=1";
    }
    $php = <<<PHP
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
    CURLOPT_POSTFIELDS => [
        '$field' => new CURLFile('/path/to/$sampleFile'),
        'real_inference' => '$realInference',
{$phpExtra}        'output_format' => '$outputFormat',
    ],
]);
echo curl_exec(\$ch);
PHP;
    $js = <<<JS
const form = new FormData();
form.append('$field', fileInput.files[0]);
form.append('real_inference', '$realInference');
{$jsExtra}form.append('output_format', '$outputFormat');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>' },
  body: form
});
console.log(await res.json());
JS;
    return ['curl' => $curl, 'php' => $php, 'js' => $js];
}

$db = hub_db();
$user = hub_require_login($db);
$isAdminUser = hub_is_system_admin($user);
$services = hub_playground_service_options($db, $user);
$selectedMode = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['mode'] ?? $_GET['mode'] ?? '')) ?: 'hello';
$selectedService = hub_playground_selected_service($services, $selectedMode);
if ($selectedService) {
    $selectedMode = (string)$selectedService['mode'];
}
$profiles = hub_playground_profiles();
$profile = $profiles[$selectedMode] ?? $profiles['hello'];
$result = null;
$token = '';
$readinessNotice = $selectedService ? hub_playground_basic_readiness($selectedService) : null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    hub_check_csrf();
    $token = trim((string)($_POST['bearer_token'] ?? ''));
    $guard = $selectedService ? hub_playground_readiness_guard($selectedService) : ['error' => 'service_not_found', 'message' => '找不到可測試的服務。'];
    $result = $guard === null ? hub_playground_execute($selectedMode, $token) : hub_playground_guard_result($guard);
}
$examples = hub_playground_examples($selectedMode);
$audioUrl = $selectedService && $selectedMode === 'tts' ? hub_playground_tts_audio_url($selectedService, $result) : '';
$authHeaderExample = 'Authorization: Bearer <TOKEN>';

hub_admin_header('API 測試場', $user);
?>
<section class="panel">
    <h1>API 測試場</h1>
    <p class="muted">後台 server side 呼叫本機 <code>api.php</code>。Bearer token 只用於本次測試，不保存；範例固定使用 <code>&lt;TOKEN&gt;</code>。</p>
    <p><strong>需要 Bearer Token</strong>。還沒有 token 時，請先 <a href="<?= $isAdminUser ? 'api_members.php' : 'my_tokens.php' ?>">前往 API 金鑰建立</a>。</p>
    <p class="muted">支援範例：<code>api.php?mode=hello</code>、<code>api.php?mode=translate</code>、<code>api.php?mode=ocr</code>、<code>api.php?mode=yolo</code>、<code>api.php?mode=sam3</code>、<code>api.php?mode=tts</code>、<code>api.php?mode=structure</code></p>
</section>

<div class="hub-card-grid">
    <section class="hub-card">
        <h2>選擇服務</h2>
        <?php if ($services === []): ?>
            <div class="hub-empty-state">目前沒有可測試的 service mode。</div>
        <?php else: ?>
            <form method="get">
                <label>mode</label>
                <select name="mode" onchange="this.form.submit()">
                    <?php foreach ($services as $service): ?>
                        <?php $mode = (string)$service['mode']; ?>
                        <option value="<?= hub_h($mode) ?>" <?= $mode === $selectedMode ? 'selected' : '' ?>>
                            <?= hub_h($mode) ?> / <?= hub_h((string)$service['name']) ?><?= (int)$service['enabled'] === 1 ? '' : ' / disabled' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
        <?php if ($selectedService): ?>
            <?php if ($readinessNotice !== null): ?>
                <div class="notice">
                    <?= hub_h((string)$readinessNotice['message']) ?>
                    <div class="hub-actions">
                        <a class="button" href="<?= $isAdminUser ? 'services.php' : 'my_services.php' ?>"><?= $isAdminUser ? '前往服務管理' : '查看我的服務' ?></a>
                    </div>
                    <p class="muted">
                        mode=<code><?= hub_h((string)$selectedService['mode']) ?></code>
                        service_key=<code><?= hub_h((string)($selectedService['service_key'] ?? '')) ?></code>
                        <?php if ($isAdminUser): ?>local_port=<code><?= hub_h((string)($selectedService['local_port'] ?? '')) ?></code><?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="hub-meta">
                <div class="hub-meta-label">service</div>
                <div class="hub-meta-value"><?= hub_h((string)$selectedService['name']) ?></div>
                <div class="hub-meta-label">pack_id</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$selectedService['pack_id']) ?></code></div>
                <div class="hub-meta-label">endpoint</div>
                <div class="hub-meta-value"><code><?= hub_h(hub_playground_endpoint($selectedService)) ?></code></div>
                <div class="hub-meta-label">execution_type</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$selectedService['execution_type']) ?></code></div>
                <div class="hub-meta-label">runtime_level</div>
                <div class="hub-meta-value"><code><?= hub_h(hub_playground_runtime_level($selectedService)) ?></code></div>
                <div class="hub-meta-label">enabled</div>
                <div class="hub-meta-value"><span class="<?= (int)$selectedService['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= (int)$selectedService['enabled'] === 1 ? 'yes' : 'no' ?></span></div>
                <div class="hub-meta-label">token required</div>
                <div class="hub-meta-value">需要 Bearer Token</div>
            </div>
            <div class="hub-actions">
                <a class="button" href="<?= $isAdminUser ? 'api_docs.php' : '../public_api_docs.php' ?>">API 文件</a>
                <?php if ($isAdminUser): ?>
                    <a class="button" href="benchmarks.php">Benchmark 測試</a>
                    <a class="button" href="pack_readiness.php?pack_id=<?= urlencode((string)$selectedService['pack_id']) ?>">準備狀態</a>
                    <a class="button" href="log_explorer.php?mode=<?= urlencode($selectedMode) ?>">API 記錄</a>
                <?php else: ?>
                    <a class="button" href="my_usage.php">用量統計</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="hub-card">
        <h2>Request</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="mode" value="<?= hub_h($selectedMode) ?>">
            <label>Bearer token</label>
            <input id="bearer-token-input" name="bearer_token" type="password" placeholder="<TOKEN>">
            <div class="hub-actions">
                <button type="button" data-token-toggle data-target="bearer-token-input">顯示 token</button>
                <button type="button" data-copy-target="copy-auth-header">複製 Authorization header</button>
            </div>
            <p class="muted">Authorization header：<code id="copy-auth-header"><?= hub_h($authHeaderExample) ?></code></p>
            <?php if ($token !== ''): ?><p class="muted">本次使用 token：<code><?= hub_h(hub_playground_mask_token($token)) ?></code></p><?php endif; ?>

            <?php if ($selectedMode === 'translate'): ?>
                <label>source_lang</label>
                <input name="source_lang" value="en">
                <label>target_lang</label>
                <input name="target_lang" value="zh-TW">
                <label>text</label>
                <textarea name="text" rows="5">That was a wonderful time.</textarea>
                <label><input name="real_inference" type="checkbox" value="1" checked> 真實推論</label>
            <?php elseif ($selectedMode === 'tts'): ?>
                <label>TTS mode</label>
                <select name="tts_mode">
                    <option value="design">design</option>
                    <option value="clone">clone</option>
                </select>
                <label>text</label>
                <textarea name="text" rows="5">RC 閥是用來控制二行程引擎排氣時機的重要機構。</textarea>
                <label>voice_prompt</label>
                <input name="voice_prompt" value="沉穩的台灣男性技師，語速稍慢，清楚自然">
                <label>reference_audio_id</label>
                <input name="reference_audio_id" placeholder="voice_profile_1">
                <p class="muted">clone mode 需填入自己擁有的 Voice Profile，例如 <code>voice_profile_1</code>。第一版不接受任意伺服器檔案路徑。</p>
                <label>control</label>
                <input name="control" value="沉穩、稍慢、像技師解說">
                <label>seed</label>
                <input name="seed" type="number" value="42">
                <label><input name="real_inference" type="checkbox" value="1" checked> 真實推論</label>
            <?php elseif (in_array($selectedMode, ['ocr', 'yolo'], true)): ?>
                <label>image</label>
                <input name="image" type="file" accept="image/*">
                <label><input name="real_inference" type="checkbox" value="1" checked> 真實推論</label>
            <?php elseif ($selectedMode === 'structure'): ?>
                <label>file</label>
                <input name="file" type="file" accept="application/pdf,image/*">
                <label>output_format</label>
                <select name="output_format">
                    <option value="both">both</option>
                    <option value="markdown">markdown</option>
                    <option value="json">json</option>
                </select>
                <label><input name="real_inference" type="checkbox" value="1" checked> 真實解析</label>
                <p class="muted">L4 支援真 PP-StructureV3 解析 PDF 或文件圖片；大型 PDF 建議走 task_submit 的 structure_parse 佇列。</p>
            <?php elseif ($selectedMode === 'sam3'): ?>
                <label>image</label>
                <input name="image" type="file" accept="image/*">
                <label>prompt_type</label>
                <input name="prompt_type" value="auto">
                <label>points_json</label>
                <textarea name="points_json" rows="3" placeholder='{"points":[[320,240]],"labels":[1]}'></textarea>
                <p class="muted">prompt_type=points 時填入，例如 <code>{"points":[[320,240]],"labels":[1]}</code></p>
                <label>output_format</label>
                <select name="output_format">
                    <option value="metadata">metadata</option>
                    <option value="polygon">polygon</option>
                    <option value="rle">rle</option>
                    <option value="both">both</option>
                </select>
                <label><input name="real_inference" type="checkbox" value="1" checked> 真實推論</label>
            <?php else: ?>
                <p class="muted">hello 使用 GET，不需要欄位。</p>
            <?php endif; ?>
            <div class="hub-actions"><button class="primary" type="submit">執行測試</button></div>
        </form>
    </section>
</div>

<section class="panel">
    <h2>回應結果</h2>
    <?php if ($result === null): ?>
        <div class="hub-empty-state">尚未執行測試。</div>
    <?php else: ?>
        <div class="hub-meta">
            <div class="hub-meta-label">HTTP status</div>
            <div class="hub-meta-value"><code><?= hub_h((string)($result['status'] ?? '-')) ?></code></div>
            <div class="hub-meta-label">elapsed_ms</div>
            <div class="hub-meta-value"><code><?= hub_h((string)($result['elapsed_ms'] ?? '-')) ?></code></div>
            <div class="hub-meta-label">request_id</div>
            <div class="hub-meta-value">
                <?php if ((string)($result['request_id'] ?? '') !== ''): ?>
                    <a href="log_explorer.php?request_id=<?= urlencode((string)$result['request_id']) ?>"><code><?= hub_h((string)$result['request_id']) ?></code></a>
                    <a class="button" href="log_explorer.php?request_id=<?= urlencode((string)$result['request_id']) ?>">查看 API 記錄</a>
                <?php else: ?>
                    -
                <?php endif; ?>
            </div>
            <?php if ((string)($result['error'] ?? '') !== ''): ?>
                <div class="hub-meta-label">error_code</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$result['error']) ?></code> <?= hub_h((string)($result['message'] ?? '')) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($audioUrl !== ''): ?>
            <div class="hub-card">
                <h3>語音預覽</h3>
                <audio controls src="<?= hub_h($audioUrl) ?>"></audio>
                <p><a class="button" href="<?= hub_h($audioUrl) ?>">下載 WAV</a></p>
            </div>
        <?php endif; ?>
        <pre><?= hub_h((string)($result['pretty_body'] ?? json_encode($result, JSON_UNESCAPED_UNICODE))) ?></pre>
    <?php endif; ?>
</section>

<section class="panel">
    <h2>介接範例</h2>
    <div class="hub-card-grid">
        <article class="hub-card">
            <h3>複製 curl</h3>
            <button type="button" data-copy-target="copy-curl">複製 curl</button>
            <pre id="copy-curl"><?= hub_h($examples['curl']) ?></pre>
        </article>
        <article class="hub-card">
            <h3>複製 PHP</h3>
            <button type="button" data-copy-target="copy-php">複製 PHP</button>
            <pre id="copy-php"><?= hub_h($examples['php']) ?></pre>
        </article>
        <article class="hub-card">
            <h3>複製 JS fetch</h3>
            <button type="button" data-copy-target="copy-js">複製 JS fetch</button>
            <pre id="copy-js"><?= hub_h($examples['js']) ?></pre>
        </article>
    </div>
    <p id="playground-copy-status" class="muted"></p>
</section>
<script>
document.querySelectorAll('[data-token-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target || '');
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? '顯示 token' : '隱藏 token';
    });
});
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget || '');
        const status = document.getElementById('playground-copy-status');
        if (!target || !navigator.clipboard) {
            if (status) status.textContent = '請手動複製。';
            return;
        }
        try {
            await navigator.clipboard.writeText(target.textContent || '');
            if (status) status.textContent = '已複製。';
        } catch (e) {
            if (status) status.textContent = '請手動複製。';
        }
    });
});
</script>
<?php hub_admin_footer(); ?>
