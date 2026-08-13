<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_playground_tts_artifacts.php';
require_once __DIR__ . '/_playground_voice_profiles.php';
require_once __DIR__ . '/_playground_facebook_crawler.php';

function hub_playground_profiles(): array
{
    return [
        'hello' => ['label' => 'Hello', 'method' => 'GET', 'kind' => 'none'],
        'translate' => ['label' => 'Translate', 'method' => 'POST', 'kind' => 'json'],
        'ocr' => ['label' => 'OCR', 'method' => 'POST', 'kind' => 'image'],
        'yolo' => ['label' => 'YOLO', 'method' => 'POST', 'kind' => 'image'],
        'sam3' => ['label' => 'SAM3', 'method' => 'POST', 'kind' => 'sam3'],
        'bioclip' => ['label' => 'BioCLIP', 'method' => 'POST', 'kind' => 'bioclip'],
        'tts' => ['label' => 'TTS', 'method' => 'POST', 'kind' => 'json'],
        'edge_tts' => ['label' => 'Edge TTS', 'method' => 'POST', 'kind' => 'form'],
        'structure' => ['label' => 'Structure', 'method' => 'POST', 'kind' => 'document'],
        'chat' => ['label' => 'Chat', 'method' => 'POST', 'kind' => 'json'],
        'photo' => ['label' => '圖片問答', 'method' => 'POST', 'kind' => 'photo'],
        'audio' => ['label' => '音訊理解', 'method' => 'POST', 'kind' => 'audio'],
        'speech_transcribe' => ['label' => 'Whisper 語音轉文字', 'method' => 'POST', 'kind' => 'speech_transcribe'],
        'speech_transcribe_fast_zh' => ['label' => '快速中文語音辨識', 'method' => 'POST', 'kind' => 'speech_transcribe_fast_zh'],
        'background_remove' => ['label' => 'BiRefNet 去背', 'method' => 'POST', 'kind' => 'background_remove'],
        'image-tools' => ['label' => 'Image Tools', 'method' => 'POST', 'kind' => 'image_tools'],
        'taiwan_address' => ['label' => '台灣地址洗滌／地理編碼', 'method' => 'POST', 'kind' => 'json'],
        'web_capture' => ['label' => 'Web Screenshot', 'method' => 'POST', 'kind' => 'json'],
        'facebook_crawl' => ['label' => 'Facebook Crawler', 'method' => 'POST', 'kind' => 'json'],
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
    return hub_local_gateway_url(hub_playground_base_path(), $mode);
}

function hub_playground_edge_tts_presets(): array
{
    return [
        'taiwan_narration' => [
            'label' => '台灣女聲旁白',
            'payload' => ['text' => '這是一段使用台灣女聲的 API 測試旁白。', 'voice' => 'zh-TW-HsiaoChenNeural', 'rate' => '+0%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => false],
        ],
        'slow_technical' => [
            'label' => '慢速技術解說',
            'payload' => ['text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。', 'voice' => 'zh-TW-YunJheNeural', 'rate' => '-25%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => true],
        ],
        'fast_cantonese' => [
            'label' => '快速粵語公告',
            'payload' => ['text' => '呢個係一段粵語 API 測試公告。', 'voice' => 'zh-HK-WanLungNeural', 'rate' => '+25%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => false],
        ],
    ];
}

function hub_playground_request_payload(string $mode): array
{
    if ($mode === 'facebook_crawl') {
        return hub_playground_facebook_request_payload($_POST);
    }
    if ($mode === 'edge_tts') {
        return [
            'text' => trim((string)($_POST['text'] ?? '這是一段使用台灣女聲的 API 測試旁白。')),
            'voice' => trim((string)($_POST['voice'] ?? 'zh-TW-HsiaoChenNeural')),
            'rate' => trim((string)($_POST['rate'] ?? '+0%')),
            'volume' => trim((string)($_POST['volume'] ?? '+0%')),
            'pitch' => trim((string)($_POST['pitch'] ?? '+0Hz')),
            'include_subtitles' => !empty($_POST['include_subtitles']),
        ];
    }
    if ($mode === 'translate') {
        return [
            'source_lang' => trim((string)($_POST['source_lang'] ?? 'en')),
            'target_lang' => trim((string)($_POST['target_lang'] ?? 'zh-TW')),
            'text' => trim((string)($_POST['text'] ?? 'That was a wonderful time.')),
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'web_capture') {
        $payload = [
            'url' => trim((string)($_POST['url'] ?? 'https://3wa.tw/')),
        ];
        foreach (['width', 'height', 'delay_seconds', 'timeout_seconds', 'crop_x', 'crop_y', 'crop_width', 'crop_height'] as $field) {
            $value = trim((string)($_POST[$field] ?? ''));
            if ($value !== '') {
                $payload[$field] = (int)$value;
            }
        }
        $javascript = trim((string)($_POST['javascript'] ?? ''));
        if ($javascript !== '') {
            $payload['javascript'] = $javascript;
        }

        return $payload;
    }
    if ($mode === 'tts') {
        return hub_playground_tts_request_payload();
    }
    if ($mode === 'chat') {
        return [
            'text' => trim((string)($_POST['text'] ?? '請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。')),
            'system_prompt' => trim((string)($_POST['system_prompt'] ?? '你是 3waAIHub 本地 AI 助手，請使用正體中文回答。')),
            'temperature' => (float)($_POST['temperature'] ?? 0.2),
            'max_tokens' => (int)($_POST['max_tokens'] ?? 256),
            'enable_thinking' => !empty($_POST['enable_thinking']),
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'taiwan_address') {
        return [
            'operation' => trim((string)($_POST['operation'] ?? 'getAddress_XY')) ?: 'getAddress_XY',
            'address' => trim((string)($_POST['address'] ?? '台中市南區新和街1號')),
            'limit' => (int)($_POST['limit'] ?? 10),
        ];
    }
    if ($mode === 'photo') {
        return [
            'image_id' => trim((string)($_POST['image_id'] ?? '')),
            'text' => trim((string)($_POST['text'] ?? '這張圖裡有什麼？')),
            'max_tokens' => (int)($_POST['max_tokens'] ?? 256),
            'real_inference' => !empty($_POST['real_inference']),
        ];
    }
    if ($mode === 'audio') {
        return [
            'audio_id' => trim((string)($_POST['audio_id'] ?? '')),
            'operation' => trim((string)($_POST['operation'] ?? 'understand')) ?: 'understand',
            'text' => trim((string)($_POST['text'] ?? '這段錄音的重點是什麼？')),
            'max_tokens' => (int)($_POST['max_tokens'] ?? 512),
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'speech_transcribe') {
        return [
            'language' => trim((string)($_POST['language'] ?? 'zh')) ?: 'zh',
            'word_timestamps' => !empty($_POST['word_timestamps']) ? '1' : '0',
            'output_srt' => !empty($_POST['output_srt']) ? '1' : '0',
            'output_vtt' => !empty($_POST['output_vtt']) ? '1' : '0',
        ];
    }
    if ($mode === 'speech_transcribe_fast_zh') {
        return ['include_draft_subtitles' => !empty($_POST['include_draft_subtitles']) ? '1' : '0'];
    }
    if ($mode === 'sam3') {
        return [
            'prompt_type' => trim((string)($_POST['prompt_type'] ?? 'auto')) ?: 'auto',
            'points_json' => trim((string)($_POST['points_json'] ?? '')),
            'text' => trim((string)($_POST['text'] ?? '')),
            'output_format' => trim((string)($_POST['output_format'] ?? 'metadata')) ?: 'metadata',
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'bioclip') {
        return [
            'candidate_labels' => trim((string)($_POST['candidate_labels'] ?? 'plant,insect,bird,mammal,cat,dog')),
            'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
        ];
    }
    if ($mode === 'background_remove') {
        $output = trim((string)($_POST['output'] ?? 'cutout')) ?: 'cutout';
        $background = trim((string)($_POST['background'] ?? 'transparent')) ?: 'transparent';
        $payload = [
            'output' => $output,
            'feather_px' => trim((string)($_POST['feather_px'] ?? '0')) ?: '0',
            'edge_offset_px' => trim((string)($_POST['edge_offset_px'] ?? '0')) ?: '0',
            'defringe' => !empty($_POST['defringe']) ? '1' : '0',
        ];
        if ($output === 'composite') {
            $payload['background'] = $background;
            if ($background === 'color') {
                $payload['background_color'] = trim((string)($_POST['background_color'] ?? '#ffffff')) ?: '#ffffff';
            }
        }

        return $payload;
    }
    if ($mode === 'image-tools') {
        $operation = (string)($_POST['operation'] ?? 'upscale');
        $model = (string)($_POST['model'] ?? 'realesrgan-x4plus');
        $backend = (string)($_POST['backend'] ?? 'auto');
        $operations = ['upscale', 'upscale_task', 'colorize'];
        $models = ['realesrgan-x4plus', 'realesrgan-x4plus-anime', 'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4'];
        $backends = ['auto', 'cuda', 'cpu'];
        $payload = [
            'operation' => in_array($operation, $operations, true) ? $operation : 'upscale',
            'backend' => in_array($backend, $backends, true) ? $backend : 'auto',
        ];
        if ($payload['operation'] !== 'colorize') {
            $payload['model'] = in_array($model, $models, true) ? $model : 'realesrgan-x4plus';
        }
        $base64 = $_POST['base64_string'] ?? '';
        if (is_string($base64) && $base64 !== '') {
            $payload['base64_string'] = $base64;
        }

        return $payload;
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
            'message' => hub_i18n_text('服務已停用，請先啟用服務。'),
        ];
    }
    if (hub_service_is_internal_task($service)) {
        return (string)($service['install_status'] ?? '') === 'installed'
            ? null
            : ['error' => 'service_not_installed', 'message' => hub_i18n_text('服務尚未安裝。')];
    }
    if ((string)($service['status'] ?? '') !== 'running') {
        return [
            'error' => 'service_not_running',
            'message' => hub_i18n_text('服務尚未執行，請先啟動服務。'),
        ];
    }

    return null;
}

function hub_playground_health_error(array $service): ?string
{
    if (hub_service_is_internal_task($service)) {
        return null;
    }
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
            'message' => hub_i18n_text('服務容器正在執行，但服務健康檢查失敗，API 可能無法使用。'),
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
        return hub_i18n_text('Gateway 呼叫逾時。');
    }
    if (in_array($status, [401, 403], true) || in_array($gatewayError, ['missing_token', 'invalid_token', 'token_mode_denied', 'token_ip_denied'], true)) {
        return hub_i18n_text('Token 無效或無權限。');
    }
    if ($curlError !== '' || in_array($gatewayError, ['service_unavailable', 'proxy_error'], true)) {
        return hub_i18n_text('後端服務無法連線。');
    }

    return hub_i18n_text('Gateway 回傳錯誤。');
}

/**
 * @param resource $ch
 */
function hub_playground_finish_curl($ch, float $started): array
{
    $raw = curl_exec($ch);
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => 'request_failed', 'message' => hub_playground_error_message(0, $error), 'detail' => $error, 'elapsed_ms' => $elapsedMs];
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    curl_close($ch);
    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $result = hub_playground_parse_response((int)$status, $rawHeaders, (string)$contentType, $body, $elapsedMs);
    if (empty($result['ok'])) {
        $result['message'] = hub_playground_error_message((int)$status, '', (string)($result['error'] ?? ''));
    }

    return $result;
}

function hub_playground_public_task_links(array $result): array
{
    if (empty($result['ok'])) {
        return $result;
    }
    $payload = json_decode((string)($result['body'] ?? ''), true);
    $taskId = $payload['task_id'] ?? null;
    if (!is_array($payload) || (!is_int($taskId) && !(is_string($taskId) && ctype_digit($taskId))) || (int)$taskId < 1) {
        return $result;
    }

    $taskId = (int)$taskId;
    foreach ([
        'status_url' => 'task_status',
        'result_url' => 'task_result',
        'log_url' => 'task_log',
        'cancel_url' => 'task_cancel',
        'artifact_url_template' => 'artifact',
    ] as $key => $mode) {
        $payload[$key] = hub_playground_api_url($mode) . '&' . ($key === 'artifact_url_template' ? 'artifact_id={artifact_id}' : 'task_id=' . $taskId);
    }
    $body = hub_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($body)) {
        $result['body'] = $body;
        $result['pretty_body'] = hub_playground_pretty_json($body);
    }

    return $result;
}

function hub_playground_execute(string $mode, string $token, ?array $requestPayload = null): array
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
    $headers = ['Accept: ' . (in_array($mode, ['background_remove', 'image-tools'], true) ? 'image/png, application/json' : 'application/json')];
    $token = trim($token);
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if ($mode === 'photo') {
        $payload = $requestPayload ?? hub_playground_request_payload($mode);
        $file = $_FILES['image'] ?? null;
        if (is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $upload = curl_init(hub_playground_local_api_url('photo_upload'));
            if ($upload === false) {
                return ['ok' => false, 'error' => 'curl_unavailable'];
            }
            curl_setopt_array($upload, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 180,
                CURLOPT_POSTFIELDS => [
                    'image' => new CURLFile((string)$file['tmp_name'], (string)($file['type'] ?? 'application/octet-stream'), (string)($file['name'] ?? 'image')),
                ],
            ]);
            $uploadResult = hub_playground_finish_curl($upload, $started);
            if (empty($uploadResult['ok'])) {
                return $uploadResult;
            }
            $uploadBody = json_decode((string)($uploadResult['body'] ?? ''), true);
            $payload['image_id'] = is_array($uploadBody) ? (string)($uploadBody['image_id'] ?? '') : '';
        }
        if ((string)$payload['image_id'] === '') {
            return ['ok' => false, 'error' => 'image_id_required', 'message' => hub_i18n_text('請上傳圖片或填入 image_id。'), 'pretty_body' => json_encode(['ok' => false, 'error' => 'image_id_required'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)];
        }

        $headers[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'curl_unavailable'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return hub_playground_public_task_links(hub_playground_finish_curl($ch, $started));
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
        $payload = $requestPayload ?? hub_playground_request_payload($mode);
        if ($mode === 'image-tools') {
            $url .= '&operation=' . rawurlencode((string)$payload['operation']);
        }
        if ($profile['kind'] === 'form') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        } elseif ($profile['kind'] === 'json') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $isAudioFile = in_array($mode, ['audio', 'speech_transcribe', 'speech_transcribe_fast_zh'], true);
            $fieldName = in_array($mode, ['structure', 'speech_transcribe_fast_zh'], true) ? 'file' : ($isAudioFile ? 'audio' : 'image');
            $file = $_FILES[$fieldName] ?? null;
            $hasFile = is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
            if (!$hasFile && !($mode === 'audio' && trim((string)($payload['audio_id'] ?? '')) !== '') && !($mode === 'image-tools' && isset($payload['base64_string']))) {
                curl_close($ch);
                return ['ok' => false, 'error' => 'missing_file', 'message' => $mode === 'structure' ? hub_i18n_text('請選擇 PDF 或文件圖片。') : ($isAudioFile ? hub_i18n_text('請選擇音訊檔。') : hub_i18n_text('請選擇圖片檔。'))];
            }
            if ($hasFile) {
                $payload[$fieldName] = new CURLFile(
                    (string)$file['tmp_name'],
                    (string)($file['type'] ?? ($isAudioFile ? 'audio/wav' : 'application/octet-stream')),
                    (string)($file['name'] ?? $fieldName)
                );
            }
            if ($mode === 'background_remove' && ($payload['background'] ?? '') === 'image') {
                $backgroundFile = $_FILES['background_image'] ?? null;
                if (is_array($backgroundFile) && (int)($backgroundFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $payload['background_image'] = new CURLFile(
                        (string)$backgroundFile['tmp_name'],
                        (string)($backgroundFile['type'] ?? 'application/octet-stream'),
                        (string)($backgroundFile['name'] ?? 'background_image')
                    );
                }
            }
            if ($mode === 'sam3') {
                $guidanceFile = $_FILES['guidance_mask'] ?? null;
                if (is_array($guidanceFile) && (int)($guidanceFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $payload['guidance_mask'] = new CURLFile(
                        (string)$guidanceFile['tmp_name'],
                        (string)($guidanceFile['type'] ?? 'image/png'),
                        (string)($guidanceFile['name'] ?? 'guidance_mask.png')
                    );
                }
            }
            $options[CURLOPT_POSTFIELDS] = $payload;
        }
    }

    curl_setopt_array($ch, $options);
    return hub_playground_public_task_links(hub_playground_finish_curl($ch, $started));
}

function hub_playground_pretty_json(string $body): string
{
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return $body;
    }

    return (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function hub_playground_examples(string $mode): array
{
    $url = hub_playground_api_url($mode);
    $phpUrl = var_export($url, true);
    $jsUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $curlExecutable = hub_platform_id() === 'windows' ? 'curl.exe' : 'curl';
    $curlContinuation = hub_platform_id() === 'windows' ? '`' : '\\';
    if ($mode === 'hello') {
        $curl = $curlExecutable . ' -H "Authorization: Bearer <TOKEN>" "' . $url . '"';
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
    if ($mode === 'facebook_crawl') {
        $json = '{"targets":[{"url":"https://www.facebook.com/wra.gov.tw"}],"limit_per_target":10}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'targets' => [['url' => 'https://www.facebook.com/wra.gov.tw']],
    'limit_per_target' => 10,
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <TOKEN>',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_SLASHES),
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
    targets: [{ url: 'https://www.facebook.com/wra.gov.tw' }],
    limit_per_target: 10
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'translate') {
        $json = '{"source_lang":"en","target_lang":"zh-TW","text":"That was a wonderful time.","real_inference":0}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
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
    if ($mode === 'web_capture') {
        $json = '{"url":"https://3wa.tw/","width":1280,"height":720,"delay_seconds":0,"timeout_seconds":60}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'url' => 'https://3wa.tw/',
    'width' => 1280,
    'height' => 720,
    'delay_seconds' => 0,
    'timeout_seconds' => 60,
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer <TOKEN>',
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_SLASHES),
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
    url: 'https://3wa.tw/',
    width: 1280,
    height: 720,
    delay_seconds: 0,
    timeout_seconds: 60
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'taiwan_address') {
        $json = '{"operation":"getAddress_XY","address":"台中市南區新和街1號","limit":10}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'operation' => 'getAddress_XY',
    'address' => '台中市南區新和街1號',
    'limit' => 10,
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
    operation: 'getAddress_XY',
    address: '台中市南區新和街1號',
    limit: 10
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'tts') {
        $json = '{"mode":"design","text":"RC 閥是用來控制二行程引擎排氣時機的重要機構。","voice_prompt":"沉穩的台灣男性技師，語速稍慢，清楚自然","seed":42,"format":"wav"}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
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
    if ($mode === 'edge_tts') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/x-www-form-urlencoded\" $curlContinuation\n  --data-urlencode 'text=RC 閥是用來控制二行程引擎排氣時機的重要機構。' $curlContinuation\n  --data-urlencode 'voice=zh-TW-YunJheNeural' $curlContinuation\n  --data-urlencode 'rate=-25%' $curlContinuation\n  --data-urlencode 'volume=+0%' $curlContinuation\n  --data-urlencode 'pitch=+0Hz' $curlContinuation\n  --data-urlencode 'include_subtitles=true'";
        $php = <<<PHP
\$payload = [
    'text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
    'voice' => 'zh-TW-YunJheNeural',
    'rate' => '-25%',
    'volume' => '+0%',
    'pitch' => '+0Hz',
    'include_subtitles' => 'true',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>', 'Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => http_build_query(\$payload, '', '&', PHP_QUERY_RFC3986),
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const payload = {
  text: 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
  voice: 'zh-TW-YunJheNeural', rate: '-25%', volume: '+0%', pitch: '+0Hz', include_subtitles: 'true'
};
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>', 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams(payload)
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'chat') {
        $json = '{"text":"請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。","system_prompt":"你是 3waAIHub 本地 AI 助手，請簡潔回答。","real_inference":1,"enable_thinking":false,"max_tokens":256}';
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
        $php = <<<PHP
\$payload = [
    'text' => '請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。',
    'system_prompt' => '你是 3waAIHub 本地 AI 助手，請簡潔回答。',
    'real_inference' => 1,
    'enable_thinking' => false,
    'max_tokens' => 256,
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
    text: '請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。',
    system_prompt: '你是 3waAIHub 本地 AI 助手，請簡潔回答。',
    real_inference: 1,
    enable_thinking: false,
    max_tokens: 256
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'photo') {
        $uploadUrl = hub_playground_api_url('photo_upload');
        $json = '{"image_id":"img_...","text":"這張圖裡有什麼？","max_tokens":256,"real_inference":true}';
        $curl = "$curlExecutable -X POST \"$uploadUrl\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -F \"image=@example.jpg\"\n\n$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/json\" $curlContinuation\n  -d '$json'";
        $php = <<<PHP
// 先用 photo_upload 取得 image_id，再用同一個 image_id 重複提問。
\$payload = [
    'image_id' => 'img_...',
    'text' => '這張圖裡有什麼？',
    'max_tokens' => 256,
    'real_inference' => true,
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
// 先用 photo_upload 取得 image_id，再用同一個 image_id 重複提問。
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: {
    Authorization: 'Bearer <TOKEN>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    image_id: 'img_...',
    text: '這張圖裡有什麼？',
    max_tokens: 256,
    real_inference: true
  })
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'audio') {
        $uploadUrl = hub_playground_api_url('audio_upload');
        $curl = "curl -X POST \"$uploadUrl\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -F \"audio=@sample.wav\"\n\ncurl -X POST \"$url\" \\\n  -H \"Authorization: Bearer <TOKEN>\" \\\n  -F \"audio_id=aud_...\" \\\n  -F \"operation=understand\" \\\n  -F \"text=這段錄音的重點是什麼？\" \\\n  -F \"max_tokens=512\" \\\n  -F \"real_inference=1\"";
        $php = <<<PHP
// 先用 mode=audio_upload 取得 audio_id，再用同一個 audio_id 重複追問。
\$fields = [
    'audio_id' => 'aud_...',
    'operation' => 'understand',
    'text' => '這段錄音的重點是什麼？',
    'max_tokens' => '512',
    'real_inference' => '1',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
    CURLOPT_POSTFIELDS => \$fields,
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
// 先用 mode=audio_upload 取得 audio_id，再用同一個 audio_id 重複追問。
const form = new FormData();
form.append('audio_id', 'aud_...');
form.append('operation', 'understand');
form.append('text', '這段錄音的重點是什麼？');
form.append('max_tokens', '512');
form.append('real_inference', '1');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>' },
  body: form
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'speech_transcribe') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n"
            . "  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n"
            . "  -F \"audio=@sample.wav\" $curlContinuation\n"
            . "  -F \"language=zh\" $curlContinuation\n"
            . "  -F \"word_timestamps=1\" $curlContinuation\n"
            . "  -F \"output_srt=1\" $curlContinuation\n"
            . "  -F \"output_vtt=1\"";
        $php = <<<PHP
\$fields = [
    'audio' => new CURLFile('/path/to/sample.wav'),
    'language' => 'zh',
    'word_timestamps' => '1',
    'output_srt' => '1',
    'output_vtt' => '1',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
    CURLOPT_POSTFIELDS => \$fields,
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const form = new FormData();
const audioInput = document.querySelector('input[name="audio"]');
form.append('audio', audioInput.files[0], 'sample.wav');
form.append('language', 'zh');
form.append('word_timestamps', '1');
form.append('output_srt', '1');
form.append('output_vtt', '1');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>' },
  body: form
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'speech_transcribe_fast_zh') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n"
            . "  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n"
            . "  -F \"file=@sample.wav\" $curlContinuation\n"
            . "  -F \"include_draft_subtitles=1\"";
        $php = <<<PHP
\$fields = [
    'file' => new CURLFile('/path/to/sample.wav'),
    'include_draft_subtitles' => '1',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
    CURLOPT_POSTFIELDS => \$fields,
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const form = new FormData();
const fileInput = document.querySelector('input[name="file"]');
form.append('file', fileInput.files[0], 'sample.wav');
form.append('include_draft_subtitles', '1');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>' },
  body: form
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'image-tools') {
        $imageToolsUrl = $url . '&operation=upscale';
        $curl = "$curlExecutable -X POST \"$imageToolsUrl\" $curlContinuation\n"
            . "  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n"
            . "  -H \"Accept: image/png\" $curlContinuation\n"
            . "  -F \"operation=upscale\" $curlContinuation\n"
            . "  -F \"image=@sample.png\" $curlContinuation\n"
            . "  -F \"model=realesrgan-x4plus\" $curlContinuation\n"
            . "  -F \"backend=auto\" $curlContinuation\n"
            . "  --output upscaled-image.png";
        $php = <<<PHP
\$fields = ['operation' => 'upscale', 'image' => new CURLFile('/path/to/sample.png'), 'model' => 'realesrgan-x4plus', 'backend' => 'auto'];
\$ch = curl_init('$imageToolsUrl');
curl_setopt_array(\$ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>', 'Accept: image/png'], CURLOPT_POSTFIELDS => \$fields]);
\$png = curl_exec(\$ch);
if (\$png === false || curl_getinfo(\$ch, CURLINFO_HTTP_CODE) !== 200) throw new RuntimeException('image-tools upscale failed');
file_put_contents('upscaled-image.png', \$png);
PHP;
        $js = <<<JS
const form = new FormData();
form.append('operation', 'upscale');
form.append('image', document.querySelector('input[name="image"]').files[0]);
form.append('model', 'realesrgan-x4plus');
form.append('backend', 'auto');
const res = await fetch('$imageToolsUrl', { method: 'POST', headers: { Authorization: 'Bearer <TOKEN>', Accept: 'image/png' }, body: form });
if (!res.ok) throw new Error(await res.text());
console.log(URL.createObjectURL(await res.blob()));
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'background_remove') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n"
            . "  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n"
            . "  -H \"Accept: image/png\" $curlContinuation\n"
            . "  -F \"image=@sample.png\" $curlContinuation\n"
            . "  -F \"output=cutout\" $curlContinuation\n"
            . "  -F \"feather_px=0\" $curlContinuation\n"
            . "  -F \"edge_offset_px=0\" $curlContinuation\n"
            . "  -F \"defringe=1\" $curlContinuation\n"
            . "  --output background-removed.png";
        $php = <<<PHP
\$fields = [
    'image' => new CURLFile('/path/to/sample.png'),
    'output' => 'cutout',
    'feather_px' => '0',
    'edge_offset_px' => '0',
    'defringe' => '1',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>', 'Accept: image/png'],
    CURLOPT_POSTFIELDS => \$fields,
]);
\$png = curl_exec(\$ch);
\$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
\$mime = curl_getinfo(\$ch, CURLINFO_CONTENT_TYPE);
if (\$png === false || \$status !== 200 || \$mime !== 'image/png') {
    throw new RuntimeException('background_remove failed');
}
file_put_contents('background-removed.png', \$png);
PHP;
        $js = <<<JS
const form = new FormData();
const sourceInput = document.querySelector('input[name="image"]');
form.append('image', sourceInput.files[0]);
form.append('output', 'cutout');
form.append('feather_px', '0');
form.append('edge_offset_px', '0');
form.append('defringe', '1');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>', Accept: 'image/png' },
  body: form
});
if (!res.ok) throw new Error(await res.text());
const blob = await res.blob();
const objectUrl = URL.createObjectURL(blob);
console.log(objectUrl);
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }
    if ($mode === 'bioclip') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n"
            . "  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n"
            . "  -F \"image=@sample.png\" $curlContinuation\n"
            . "  -F \"candidate_labels=plant,insect,bird,mammal,cat,dog\" $curlContinuation\n"
            . "  -F \"real_inference=1\"";
        $php = <<<PHP
\$fields = [
    'image' => new CURLFile('/path/to/sample.png'),
    'candidate_labels' => 'plant,insect,bird,mammal,cat,dog',
    'real_inference' => '1',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
    CURLOPT_POSTFIELDS => \$fields,
]);
echo curl_exec(\$ch);
PHP;
        $js = <<<JS
const form = new FormData();
form.append('image', fileInput.files[0]);
form.append('candidate_labels', 'plant,insect,bird,mammal,cat,dog');
form.append('real_inference', '1');
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>' },
  body: form
});
console.log(await res.json());
JS;
        return ['curl' => $curl, 'php' => $php, 'js' => $js];
    }

    $field = $mode === 'structure' ? 'file' : 'image';
    $extra = $mode === 'sam3' ? " $curlContinuation\n  -F prompt_type=auto $curlContinuation\n  -F output_format=metadata" : '';
    $sampleFile = $mode === 'structure' ? 'sample.pdf' : 'sample.png';
    $outputFormat = $mode === 'structure' ? 'both' : 'metadata';
    $realInference = $mode === 'structure' ? '1' : '0';
    $phpExtra = $mode === 'sam3' ? "        'prompt_type' => 'auto',\n" : '';
    $jsExtra = $mode === 'sam3' ? "form.append('prompt_type', 'auto');\n" : '';
    $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: multipart/form-data\" $curlContinuation\n  -F {$field}=@sample.png $curlContinuation\n  -F real_inference={$realInference}{$extra}";
    if ($mode === 'structure') {
        $curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: multipart/form-data\" $curlContinuation\n  -F {$field}=@{$sampleFile} $curlContinuation\n  -F output_format=both $curlContinuation\n  -F real_inference=1";
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
$action = '';
$voiceProfileDraftPrefill = '';
$readinessNotice = $selectedService ? hub_playground_basic_readiness($selectedService) : null;
$facebookActions = ['facebook_profile_list', 'facebook_profile_start', 'facebook_profile_status', 'facebook_profile_reauth', 'facebook_profile_delete', 'facebook_run_last', 'facebook_dataset_preview'];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (!empty($_POST['load_voice_profiles']) || !empty($_POST['load_voice_profile_draft']) || !empty($_POST['load_facebook_profiles']) || in_array((string)($_POST['action'] ?? ''), array_merge(['execute', 'voice_profile_upload', 'voice_profile_confirm', 'voice_profile_retry_asr'], $facebookActions), true))) {
    hub_check_csrf();
    $action = !empty($_POST['load_voice_profiles'])
        ? 'voice_profile_list'
        : (!empty($_POST['load_voice_profile_draft'])
            ? 'voice_profile_load_draft'
            : (!empty($_POST['load_facebook_profiles']) ? 'facebook_profile_list' : (string)$_POST['action']));
    if ($action === 'execute') {
        $token = trim((string)($_POST['bearer_token'] ?? ''));
        $guard = $selectedService ? hub_playground_readiness_guard($selectedService) : ['error' => 'service_not_found', 'message' => hub_i18n_text('找不到可測試的服務。')];
        $result = $guard === null ? ($selectedMode === 'tts' ? hub_playground_execute_tts($token) : hub_playground_execute($selectedMode, $token)) : hub_playground_guard_result($guard);
    } elseif ($selectedMode === 'facebook_crawl' && in_array($action, $facebookActions, true)) {
        $result = hub_playground_facebook_action($db, $action, (string)($_POST['bearer_token'] ?? ''), $_POST);
    } elseif ($action === 'voice_profile_load_draft') {
        $draft = hub_playground_voice_profile_draft_prefill($db, (string)($_POST['bearer_token'] ?? ''), (int)($_POST['voice_profile_id'] ?? 0));
        $result = $draft === null ? hub_playground_voice_profile_error_result() : hub_playground_voice_profile_draft_result();
        $voiceProfileDraftPrefill = $draft ?? '';
    } elseif ($action !== 'voice_profile_list') {
        $result = hub_playground_voice_profile_dispatch($db, $action, (string)($_POST['bearer_token'] ?? ''), $_POST, $_FILES);
    }
}
$examples = hub_playground_examples($selectedMode);
$edgeTtsPresets = hub_playground_edge_tts_presets();
$edgeTtsPresetKey = is_string($_GET['edge_tts_preset'] ?? null) ? (string)$_GET['edge_tts_preset'] : 'taiwan_narration';
$edgeTtsPreset = $edgeTtsPresets[$edgeTtsPresetKey] ?? $edgeTtsPresets['taiwan_narration'];
$edgeTtsValues = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? hub_playground_request_payload('edge_tts')
    : $edgeTtsPreset['payload'];
$ttsProfiles = [];
$ttsManagementProfiles = [];
$facebookProfiles = [];
$profileToken = trim((string)($_POST['bearer_token'] ?? ''));
if ($selectedMode === 'tts' && ($profileToken !== '' || $action === 'voice_profile_list')) {
    $ttsProfileOptions = hub_playground_tts_profile_options_result($db, $profileToken);
    if (!empty($ttsProfileOptions['ok'])) {
        $ttsProfiles = $ttsProfileOptions['execution_profiles'];
        $ttsManagementProfiles = $ttsProfileOptions['management_profiles'];
    } elseif ($action === 'voice_profile_list') {
        $result = $ttsProfileOptions;
    }
}
if ($selectedMode === 'facebook_crawl' && $profileToken !== '') {
    $facebookProfileOptions = hub_playground_facebook_profile_options_result($db, $profileToken);
    if (!empty($facebookProfileOptions['ok'])) {
        $facebookProfiles = is_array($facebookProfileOptions['profiles'] ?? null) ? $facebookProfileOptions['profiles'] : [];
    } elseif ($action === 'facebook_profile_list') {
        $result = $facebookProfileOptions;
    }
}
$selectedManagementProfileId = hub_playground_voice_profile_selected_id($_POST);
$audioUrls = $selectedService && $selectedMode === 'tts' && is_array($result) ? hub_playground_tts_audio_urls($selectedService, $result) : [];
$audioUrl = $selectedService && $selectedMode === 'tts' && $audioUrls === [] ? hub_playground_tts_audio_url($selectedService, $result) : '';
$authHeaderExample = 'Authorization: Bearer <TOKEN>';

hub_admin_header(hub_i18n_text('API 測試場'), $user);
?>
<style>
.birefnet-preview {
    display: grid;
    min-height: 18rem;
    place-items: center;
    overflow: hidden;
    border: 1px solid var(--border, #cbd5e1);
    background-color: #fff;
    background-image: conic-gradient(#e5e7eb 25%, #fff 0 50%, #e5e7eb 0 75%, #fff 0);
    background-size: 24px 24px;
}
.birefnet-preview img {
    display: block;
    max-width: 100%;
    max-height: 70vh;
    object-fit: contain;
}
</style>
<section class="panel">
    <h1><?= hub_h(hub_i18n_text('API 測試場')) ?></h1>
    <p class="muted"><?= hub_h(hub_i18n_text('後台 server side 呼叫本機')) ?> <code>api.php</code>。<?= hub_h(hub_i18n_text('Bearer token 只用於本次測試，不保存；範例固定使用')) ?> <code>&lt;TOKEN&gt;</code>。</p>
    <p><strong><?= hub_h(hub_i18n_text('需要 Bearer Token')) ?></strong>。<?= hub_h(hub_i18n_text('還沒有 token 時，請先')) ?> <a href="<?= $isAdminUser ? 'api_members.php' : 'my_tokens.php' ?>"><?= hub_h(hub_i18n_text('前往 API 金鑰建立')) ?></a>。</p>
    <p class="muted"><?= hub_h(hub_i18n_text('支援範例：')) ?><code>api.php?mode=hello</code>、<code>api.php?mode=translate</code>、<code>api.php?mode=ocr</code>、<code>api.php?mode=yolo</code>、<code>api.php?mode=sam3</code>、<code>api.php?mode=bioclip</code>、<code>api.php?mode=tts</code>、<code>api.php?mode=structure</code>、<code>api.php?mode=chat</code>、<code>api.php?mode=photo_upload</code>、<code>api.php?mode=photo</code>、<code>api.php?mode=audio</code>、<code>api.php?mode=speech_transcribe</code>、<code>api.php?mode=speech_transcribe_fast_zh</code>、<code>api.php?mode=background_remove</code>、<code>api.php?mode=image-tools</code>、<code>api.php?mode=taiwan_address</code>、<code>api.php?mode=web_capture</code>、<code>api.php?mode=facebook_crawl</code></p>
</section>

<div class="hub-card-grid">
    <section class="hub-card">
        <h2><?= hub_h(hub_i18n_text('選擇服務')) ?></h2>
        <?php if ($services === []): ?>
            <div class="hub-empty-state"><?= hub_h(hub_i18n_text('目前沒有可測試的 service mode。')) ?></div>
        <?php else: ?>
            <form method="get">
                <label>mode</label>
                <select name="mode" onchange="this.form.submit()">
                    <?php foreach ($services as $service): ?>
                        <?php $mode = (string)$service['mode']; ?>
                        <option value="<?= hub_h($mode) ?>" <?= $mode === $selectedMode ? 'selected' : '' ?>>
                            <?= hub_h($mode) ?> / <?= hub_h((string)$service['name']) ?><?= (int)$service['enabled'] === 1 ? '' : ' / ' . hub_h(hub_i18n_text('已停用')) ?>
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
                        <a class="button" href="<?= $isAdminUser ? 'services.php' : 'my_services.php' ?>"><?= hub_h($isAdminUser ? hub_i18n_text('前往服務管理') : hub_i18n_text('查看我的服務')) ?></a>
                    </div>
                    <p class="muted">
                        mode=<code><?= hub_h((string)$selectedService['mode']) ?></code>
                        service_key=<code><?= hub_h((string)($selectedService['service_key'] ?? '')) ?></code>
                        <?php if ($isAdminUser): ?>local_port=<code><?= hub_h((string)($selectedService['local_port'] ?? '')) ?></code><?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
            <div class="hub-meta">
                <div class="hub-meta-label"><?= hub_h(hub_i18n_text('服務')) ?></div>
                <div class="hub-meta-value"><?= hub_h((string)$selectedService['name']) ?></div>
                <div class="hub-meta-label">pack_id</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$selectedService['pack_id']) ?></code></div>
                <div class="hub-meta-label">endpoint</div>
                <div class="hub-meta-value"><code><?= hub_h(hub_playground_endpoint($selectedService)) ?></code></div>
                <div class="hub-meta-label">execution_type</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$selectedService['execution_type']) ?></code></div>
                <div class="hub-meta-label">runtime_level</div>
                <div class="hub-meta-value"><code><?= hub_h(hub_playground_runtime_level($selectedService)) ?></code></div>
                <div class="hub-meta-label"><?= hub_h(hub_i18n_text('啟用狀態')) ?></div>
                <div class="hub-meta-value"><span class="<?= (int)$selectedService['enabled'] === 1 ? 'ok' : 'bad' ?>"><?= hub_h((int)$selectedService['enabled'] === 1 ? hub_i18n_text('已啟用') : hub_i18n_text('已停用')) ?></span></div>
                <div class="hub-meta-label"><?= hub_h(hub_i18n_text('Token 需求')) ?></div>
                <div class="hub-meta-value"><?= hub_h(hub_i18n_text('需要 Bearer Token')) ?></div>
            </div>
            <div class="hub-actions">
                <a class="button" href="<?= $isAdminUser ? 'api_docs.php' : '../public_api_docs.php' ?>"><?= hub_h(hub_i18n_text('API 文件')) ?></a>
                <?php if ($isAdminUser): ?>
                    <a class="button" href="benchmarks.php"><?= hub_h(hub_i18n_text('Benchmark 測試')) ?></a>
                    <a class="button" href="pack_readiness.php?pack_id=<?= urlencode((string)$selectedService['pack_id']) ?>"><?= hub_h(hub_i18n_text('準備狀態')) ?></a>
                    <a class="button" href="log_explorer.php?mode=<?= urlencode($selectedMode) ?>"><?= hub_h(hub_i18n_text('API 記錄')) ?></a>
                <?php else: ?>
                    <a class="button" href="my_usage.php"><?= hub_h(hub_i18n_text('用量統計')) ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="hub-card">
        <h2><?= hub_h(hub_i18n_text('請求')) ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
            <input type="hidden" name="action" value="execute">
            <input type="hidden" name="mode" value="<?= hub_h($selectedMode) ?>">
            <label>Bearer Token</label>
            <input id="bearer-token-input" name="bearer_token" type="password" placeholder="<TOKEN>" autocomplete="off">
            <div class="hub-actions">
                <button type="button" data-token-toggle data-target="bearer-token-input"><?= hub_h(hub_i18n_text('顯示 token')) ?></button>
                <button type="button" data-copy-target="copy-auth-header"><?= hub_h(hub_i18n_text('複製 Authorization header')) ?></button>
            </div>
            <p class="muted">Authorization header：<code id="copy-auth-header"><?= hub_h($authHeaderExample) ?></code></p>
            <?php if ($selectedMode === 'facebook_crawl'): ?>
                <?= hub_playground_facebook_request_fields_html($facebookProfiles, $_POST) ?>
            <?php elseif ($selectedMode === 'translate'): ?>
                <label><?= hub_h(hub_i18n_text('來源語言')) ?> source_lang</label>
                <input name="source_lang" value="en">
                <label><?= hub_h(hub_i18n_text('目標語言')) ?> target_lang</label>
                <input name="target_lang" value="zh-TW">
                <label><?= hub_h(hub_i18n_text('文字')) ?></label>
                <textarea name="text" rows="5">That was a wonderful time.</textarea>
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實推論')) ?></label>
            <?php elseif ($selectedMode === 'web_capture'): ?>
                <label>URL</label>
                <input name="url" type="url" value="<?= hub_h((string)($_POST['url'] ?? 'https://3wa.tw/')) ?>" required>
                <label><?= hub_h(hub_i18n_text('畫面寬度')) ?> width</label>
                <input name="width" type="number" min="320" max="2560" value="<?= hub_h((string)($_POST['width'] ?? '1280')) ?>">
                <label><?= hub_h(hub_i18n_text('畫面高度')) ?> height</label>
                <input name="height" type="number" min="320" max="2160" value="<?= hub_h((string)($_POST['height'] ?? '720')) ?>">
                <label><?= hub_h(hub_i18n_text('等待秒數')) ?> delay_seconds</label>
                <input name="delay_seconds" type="number" min="0" max="60" value="<?= hub_h((string)($_POST['delay_seconds'] ?? '0')) ?>">
                <label><?= hub_h(hub_i18n_text('逾時秒數')) ?> timeout_seconds</label>
                <input name="timeout_seconds" type="number" min="10" max="120" value="<?= hub_h((string)($_POST['timeout_seconds'] ?? '60')) ?>">
                <label><?= hub_h(hub_i18n_text('頁面 JavaScript（選填）')) ?> javascript</label>
                <textarea name="javascript" rows="3" maxlength="16384"><?= hub_h((string)($_POST['javascript'] ?? '')) ?></textarea>
                <label><?= hub_h(hub_i18n_text('裁切起點 X／Y（選填，四個裁切欄位須一起填）')) ?></label>
                <input name="crop_x" type="number" min="0" max="2559" value="<?= hub_h((string)($_POST['crop_x'] ?? '')) ?>">
                <input name="crop_y" type="number" min="0" max="2159" value="<?= hub_h((string)($_POST['crop_y'] ?? '')) ?>">
                <label><?= hub_h(hub_i18n_text('裁切寬／高（選填）')) ?></label>
                <input name="crop_width" type="number" min="1" max="2560" value="<?= hub_h((string)($_POST['crop_width'] ?? '')) ?>">
                <input name="crop_height" type="number" min="1" max="2160" value="<?= hub_h((string)($_POST['crop_height'] ?? '')) ?>">
                <p class="muted"><?= hub_h(hub_i18n_text('只可擷取管理員 allowlist 中的公開主機；導頁僅限同一精確主機名。')) ?></p>
            <?php elseif ($selectedMode === 'taiwan_address'): ?>
                <?php $addressOperation = (string)($_POST['operation'] ?? 'getAddress_XY'); ?>
                <label><?= hub_h(hub_i18n_text('操作')) ?> operation</label>
                <select name="operation">
                    <?php foreach (['getAddress_XY', 'autocomplete', 'searchAll'] as $operation): ?>
                        <option value="<?= hub_h($operation) ?>" <?= $addressOperation === $operation ? 'selected' : '' ?>><?= hub_h($operation) ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?= hub_h(hub_i18n_text('地址／地標')) ?> address</label>
                <input name="address" value="<?= hub_h((string)($_POST['address'] ?? '台中市南區新和街1號')) ?>" required>
                <label><?= hub_h(hub_i18n_text('最多結果數')) ?> limit</label>
                <input name="limit" type="number" min="1" max="100" value="<?= hub_h((string)($_POST['limit'] ?? '10')) ?>">
                <p class="muted"><?= hub_h(hub_i18n_text('Adapter 只會轉送固定 operation 與欄位；不接受 caller 指定 upstream URL。')) ?></p>
            <?php elseif ($selectedMode === 'edge_tts'): ?>
                <p class="muted"><?= hub_h(hub_i18n_text('選擇範例後仍可調整所有參數。')) ?></p>
                <div class="hub-actions">
                    <?php foreach ($edgeTtsPresets as $presetKey => $preset): ?>
                        <a class="button" href="playground.php?mode=edge_tts&edge_tts_preset=<?= urlencode((string)$presetKey) ?>"><?= hub_h((string)$preset['label']) ?></a>
                    <?php endforeach; ?>
                </div>
                <label><?= hub_h(hub_i18n_text('文字')) ?> text</label>
                <textarea name="text" rows="5" maxlength="4096" required><?= hub_h((string)$edgeTtsValues['text']) ?></textarea>
                <label><?= hub_h(hub_i18n_text('聲線')) ?> voice</label>
                <select name="voice">
                    <?php foreach (hub_edge_tts_voice_catalog() as $voice): ?>
                        <?php $voiceId = (string)$voice['id']; ?>
                        <option value="<?= hub_h($voiceId) ?>" <?= $edgeTtsValues['voice'] === $voiceId ? 'selected' : '' ?>><?= hub_h((string)$voice['display_name']) ?> / <?= hub_h($voiceId) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php foreach (['rate' => ['-50%', '-25%', '+0%', '+25%', '+50%'], 'volume' => ['-50%', '-25%', '+0%', '+25%', '+50%'], 'pitch' => ['-50Hz', '-25Hz', '+0Hz', '+25Hz', '+50Hz']] as $field => $choices): ?>
                    <label><?= hub_h($field) ?></label>
                    <select name="<?= hub_h($field) ?>">
                        <?php foreach ($choices as $choice): ?>
                            <option value="<?= hub_h($choice) ?>" <?= $edgeTtsValues[$field] === $choice ? 'selected' : '' ?>><?= hub_h($choice) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endforeach; ?>
                <label><input name="include_subtitles" type="checkbox" value="1" <?= $edgeTtsValues['include_subtitles'] ? 'checked' : '' ?>> include_subtitles</label>
                <p class="muted"><?= hub_h(hub_i18n_text('開啟字幕會產生 VTT、SRT 與 speech timeline artifacts。')) ?></p>
            <?php elseif ($selectedMode === 'tts'): ?>
                <label>TTS <?= hub_h(hub_i18n_text('模式')) ?></label>
                <select name="tts_mode">
                    <?php $selectedTtsMode = trim((string)($_POST['tts_mode'] ?? 'design')); ?>
                    <option value="design" <?= $selectedTtsMode === 'design' ? 'selected' : '' ?>>design</option>
                    <option value="clone" <?= $selectedTtsMode === 'clone' ? 'selected' : '' ?>>clone</option>
                    <option value="ultimate_clone" <?= $selectedTtsMode === 'ultimate_clone' ? 'selected' : '' ?>>ultimate_clone</option>
                </select>
                <label><input name="compare_all" type="checkbox" value="1" <?= !empty($_POST['compare_all']) ? 'checked' : '' ?>> <?= hub_h(hub_i18n_text('依序比較 design、clone、ultimate_clone')) ?></label>
                <label><?= hub_h(hub_i18n_text('文字')) ?></label>
                <textarea name="text" rows="5">RC 閥是用來控制二行程引擎排氣時機的重要機構。</textarea>
                <label><?= hub_h(hub_i18n_text('聲音提示')) ?> voice_prompt</label>
                <input name="voice_prompt" value="沉穩的台灣男性技師，語速稍慢，清楚自然">
                <label>Voice Profile</label>
                <select name="voice_profile_id">
                    <option value=""><?= hub_h(hub_i18n_text('不使用 Voice Profile（design）')) ?></option>
                    <?php foreach ($ttsProfiles as $ttsProfile): ?>
                        <?php $ttsProfileId = (int)$ttsProfile['id']; ?>
                        <option value="<?= $ttsProfileId ?>" <?= (int)($_POST['voice_profile_id'] ?? 0) === $ttsProfileId ? 'selected' : '' ?>>
                            <?= hub_h((string)$ttsProfile['name']) ?> #<?= $ttsProfileId ?> / <?= hub_h((string)$ttsProfile['transcription_status']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="muted"><?= hub_h(hub_i18n_text('先輸入 Bearer Token，再載入目前 token 可用的 Voice Profile；clone 與 ultimate_clone 會由 Gateway 驗證存取權。')) ?></p>
                <label><?= hub_h(hub_i18n_text('控制描述')) ?> control</label>
                <input name="control" value="沉穩、稍慢、像技師解說">
                <label>seed</label>
                <input name="seed" type="number" value="42">
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實推論')) ?></label>
            <?php elseif ($selectedMode === 'image-tools'): ?>
                <?php
                $imageToolsOperation = in_array((string)($_POST['operation'] ?? ''), ['upscale', 'upscale_task', 'colorize'], true) ? (string)$_POST['operation'] : 'upscale';
                $imageToolsModel = in_array((string)($_POST['model'] ?? ''), ['realesrgan-x4plus', 'realesrgan-x4plus-anime', 'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4'], true) ? (string)$_POST['model'] : 'realesrgan-x4plus';
                $imageToolsBackend = in_array((string)($_POST['backend'] ?? ''), ['auto', 'cuda', 'cpu'], true) ? (string)$_POST['backend'] : 'auto';
                ?>
                <label><?= hub_h(hub_i18n_text('來源圖片')) ?></label>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp,image/bmp">
                <label><?= hub_h(hub_i18n_text('操作')) ?> operation</label>
                <select name="operation">
                    <option value="upscale" <?= $imageToolsOperation === 'upscale' ? 'selected' : '' ?>>upscale</option>
                    <option value="upscale_task" <?= $imageToolsOperation === 'upscale_task' ? 'selected' : '' ?>>upscale_task</option>
                    <option value="colorize" <?= $imageToolsOperation === 'colorize' ? 'selected' : '' ?>>colorize / 黑白變彩色（DDColor）</option>
                </select>
                <?php if ($imageToolsOperation !== 'colorize'): ?>
                    <label>model</label>
                    <select name="model">
                        <?php foreach (['realesrgan-x4plus', 'realesrgan-x4plus-anime', 'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4'] as $model): ?>
                            <option value="<?= hub_h($model) ?>" <?= $imageToolsModel === $model ? 'selected' : '' ?>><?= hub_h($model) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <p class="muted">DDColor ModelScope 固定模型；請確認原圖為黑白／灰階，輸出顏色為 AI 推測。</p>
                <?php endif; ?>
                <label>backend</label>
                <select name="backend">
                    <?php foreach (['auto', 'cuda', 'cpu'] as $backend): ?>
                        <option value="<?= hub_h($backend) ?>" <?= $imageToolsBackend === $backend ? 'selected' : '' ?>><?= hub_h($backend) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>base64_string</label>
                <textarea name="base64_string" rows="3" placeholder="Optional: raw Base64 or data:image/png;base64,..."></textarea>
                <p class="muted"><?= hub_h(hub_i18n_text('上傳圖片或填入 Base64，二者只能選一；upscale_task 會回傳既有 task 與 artifact 連結。')) ?></p>
            <?php elseif ($selectedMode === 'background_remove'): ?>
                <?php
                $birefnetOutput = in_array((string)($_POST['output'] ?? ''), ['cutout', 'mask', 'composite'], true) ? (string)$_POST['output'] : 'cutout';
                $birefnetBackground = in_array((string)($_POST['background'] ?? ''), ['transparent', 'white', 'color', 'image'], true) ? (string)$_POST['background'] : 'transparent';
                $birefnetColor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['background_color'] ?? '')) ? (string)$_POST['background_color'] : '#ffffff';
                ?>
                <label><?= hub_h(hub_i18n_text('來源圖片')) ?></label>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp" required>
                <label><?= hub_h(hub_i18n_text('輸出')) ?> output</label>
                <select name="output">
                    <option value="cutout" <?= $birefnetOutput === 'cutout' ? 'selected' : '' ?>>cutout</option>
                    <option value="mask" <?= $birefnetOutput === 'mask' ? 'selected' : '' ?>>mask</option>
                    <option value="composite" <?= $birefnetOutput === 'composite' ? 'selected' : '' ?>>composite</option>
                </select>
                <label><?= hub_h(hub_i18n_text('背景')) ?> background</label>
                <select name="background">
                    <option value="transparent" <?= $birefnetBackground === 'transparent' ? 'selected' : '' ?>>transparent</option>
                    <option value="white" <?= $birefnetBackground === 'white' ? 'selected' : '' ?>>white</option>
                    <option value="color" <?= $birefnetBackground === 'color' ? 'selected' : '' ?>>color</option>
                    <option value="image" <?= $birefnetBackground === 'image' ? 'selected' : '' ?>>image</option>
                </select>
                <label><?= hub_h(hub_i18n_text('背景色')) ?> background_color</label>
                <input name="background_color" type="color" value="<?= hub_h($birefnetColor) ?>">
                <label><?= hub_h(hub_i18n_text('背景圖片')) ?> background_image</label>
                <input name="background_image" type="file" accept="image/jpeg,image/png,image/webp">
                <label><?= hub_h(hub_i18n_text('邊緣羽化')) ?> feather_px</label>
                <input name="feather_px" type="number" min="0" max="20" step="0.5" value="<?= hub_h((string)($_POST['feather_px'] ?? '0')) ?>">
                <label><?= hub_h(hub_i18n_text('邊緣收縮／膨脹')) ?> edge_offset_px</label>
                <input name="edge_offset_px" type="number" min="-20" max="20" step="1" value="<?= hub_h((string)($_POST['edge_offset_px'] ?? '0')) ?>">
                <label><input name="defringe" type="checkbox" value="1" <?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !empty($_POST['defringe']) ? 'checked' : '' ?>> <?= hub_h(hub_i18n_text('移除邊緣雜色')) ?></label>
            <?php elseif ($selectedMode === 'bioclip'): ?>
                <label><?= hub_h(hub_i18n_text('圖片')) ?></label>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp">
                <label><?= hub_h(hub_i18n_text('候選標籤')) ?> candidate_labels</label>
                <textarea name="candidate_labels" rows="3">plant,insect,bird,mammal,cat,dog</textarea>
                <p class="muted"><?= hub_h(hub_i18n_text('可填逗號分隔標籤，或 JSON array；BioCLIP 會依候選標籤做 zero-shot 分類。')) ?></p>
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實物種辨識')) ?></label>
            <?php elseif (in_array($selectedMode, ['ocr', 'yolo'], true)): ?>
                <label><?= hub_h(hub_i18n_text('圖片')) ?></label>
                <input name="image" type="file" accept="image/*">
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實推論')) ?></label>
            <?php elseif ($selectedMode === 'structure'): ?>
                <label><?= hub_h(hub_i18n_text('檔案')) ?></label>
                <input name="file" type="file" accept="application/pdf,image/*">
                <label><?= hub_h(hub_i18n_text('輸出格式')) ?> output_format</label>
                <select name="output_format">
                    <option value="both">both</option>
                    <option value="markdown">markdown</option>
                    <option value="json">json</option>
                </select>
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實解析')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('L4 支援真 PP-StructureV3 解析 PDF 或文件圖片；大型 PDF 建議走 task_submit 的 structure_parse 佇列。')) ?></p>
            <?php elseif ($selectedMode === 'chat'): ?>
                <label><?= hub_h(hub_i18n_text('系統提示')) ?></label>
                <textarea name="system_prompt" rows="3">你是 3waAIHub 本地 AI 助手，請使用正體中文回答。</textarea>
                <label><?= hub_h(hub_i18n_text('使用者訊息')) ?></label>
                <textarea name="text" rows="5">請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。</textarea>
                <label><?= hub_h(hub_i18n_text('溫度')) ?> temperature</label>
                <input name="temperature" type="number" min="0" max="2" step="0.1" value="0.2">
                <label><?= hub_h(hub_i18n_text('最大輸出 token 數')) ?> max_tokens</label>
                <input name="max_tokens" type="number" min="1" max="4096" value="256">
                <label><input name="enable_thinking" type="checkbox" value="1"> <?= hub_h(hub_i18n_text('深度思考')) ?></label>
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實推論')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('第一刀 Playground 走 non-streaming JSON；SSE streaming passthrough 下一刀再接。')) ?></p>
            <?php elseif ($selectedMode === 'photo'): ?>
                <label><?= hub_h(hub_i18n_text('圖片')) ?></label>
                <input name="image" type="file" accept="image/jpeg,image/png,image/webp">
                <label><?= hub_h(hub_i18n_text('圖片 ID')) ?> image_id</label>
                <input name="image_id" value="<?= hub_h((string)($_POST['image_id'] ?? '')) ?>">
                <label><?= hub_h(hub_i18n_text('問題')) ?></label>
                <textarea name="text" rows="4">這張圖裡有什麼？</textarea>
                <label><?= hub_h(hub_i18n_text('最大輸出 token 數')) ?> max_tokens</label>
                <input name="max_tokens" type="number" min="32" max="2048" value="256">
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實圖片理解')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('先上傳圖片取得 image_id，再用 image_id 重複提問；不建立 server-side session。')) ?></p>
            <?php elseif ($selectedMode === 'audio'): ?>
                <label><?= hub_h(hub_i18n_text('音訊檔')) ?></label>
                <input name="audio" type="file" accept="audio/wav,.wav">
                <label><?= hub_h(hub_i18n_text('音訊 ID')) ?> audio_id</label>
                <input name="audio_id" value="<?= hub_h((string)($_POST['audio_id'] ?? '')) ?>">
                <label><?= hub_h(hub_i18n_text('操作')) ?> operation</label>
                <select name="operation">
                    <option value="understand">understand</option>
                    <option value="transcribe">transcribe</option>
                    <option value="summarize">summarize</option>
                </select>
                <label><?= hub_h(hub_i18n_text('提示文字')) ?></label>
                <textarea name="text" rows="4">這段錄音的重點是什麼？</textarea>
                <label><?= hub_h(hub_i18n_text('最大輸出 token 數')) ?> max_tokens</label>
                <input name="max_tokens" type="number" min="32" max="2048" value="512">
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實音訊理解')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('可直接上傳 WAV，或先用 mode=audio_upload 取得 audio_id 後重複追問；只支援 16kHz mono WAV、30 秒內、16MB 內。')) ?></p>
                <p class="muted"><?= hub_h(hub_i18n_text('Gemma4 Audio 目前是實驗性音訊理解，非正式 ASR；逐字稿或長音訊請改用 Whisper ASR。')) ?></p>
            <?php elseif ($selectedMode === 'speech_transcribe'): ?>
                <label><?= hub_h(hub_i18n_text('音訊檔')) ?></label>
                <input name="audio" type="file" accept="audio/*,.wav,.mp3,.m4a,.flac,.ogg" required>
                <label><?= hub_h(hub_i18n_text('語言')) ?> language</label>
                <select name="language">
                    <option value="zh">zh / 中文</option>
                    <option value="en">en / English</option>
                    <option value="auto">auto</option>
                </select>
                <label><input name="word_timestamps" type="checkbox" value="1" checked> word_timestamps / <?= hub_h(hub_i18n_text('逐字時間戳')) ?></label>
                <label><input name="output_srt" type="checkbox" value="1" checked> output_srt / SRT <?= hub_h(hub_i18n_text('字幕')) ?></label>
                <label><input name="output_vtt" type="checkbox" value="1" checked> output_vtt / VTT <?= hub_h(hub_i18n_text('字幕')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('送出後會回傳非同步 task ID；可由工作狀態與結果連結追蹤逐字稿及字幕 artifacts。')) ?></p>
            <?php elseif ($selectedMode === 'speech_transcribe_fast_zh'): ?>
                <label><?= hub_h(hub_i18n_text('音訊檔')) ?></label>
                <input name="file" type="file" accept="audio/*,.wav,.mp3,.m4a,.flac,.ogg" required>
                <label><input name="include_draft_subtitles" type="checkbox" value="1" <?= ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !empty($_POST['include_draft_subtitles']) ? 'checked' : '' ?>> include_draft_subtitles / <?= hub_h(hub_i18n_text('產生粗字幕')) ?></label>
                <p class="muted"><?= hub_h(hub_i18n_text('純 CPU 中文草稿辨識；模型沒有 token 時間戳時，會輸出整段粗字幕，並在 report 標示 token_timestamps_unavailable。')) ?></p>
            <?php elseif ($selectedMode === 'sam3'): ?>
                <label><?= hub_h(hub_i18n_text('圖片')) ?></label>
                <input name="image" type="file" accept="image/*">
                <label><?= hub_h(hub_i18n_text('手繪提示遮罩')) ?> guidance_mask</label>
                <input name="guidance_mask" type="file" accept="image/png">
                <p class="muted">prompt_type=guidance_mask <?= hub_h(hub_i18n_text('時上傳同尺寸 PNG；非透明像素代表要選取的目標，透明像素為中立。')) ?></p>
                <label><?= hub_h(hub_i18n_text('提示類型')) ?> prompt_type</label>
                <input name="prompt_type" value="auto">
                <label><?= hub_h(hub_i18n_text('點位 JSON')) ?> points_json</label>
                <textarea name="points_json" rows="3" placeholder='{"points":[[320,240]],"labels":[1]}'></textarea>
                <p class="muted">prompt_type=points <?= hub_h(hub_i18n_text('時填入，例如')) ?> <code>{"points":[[320,240]],"labels":[1]}</code>；labels: <code>1</code> <?= hub_h(hub_i18n_text('選取')) ?>、<code>0</code> <?= hub_h(hub_i18n_text('排除')) ?>，至少需要一個 <code>1</code>。</p>
                <label><?= hub_h(hub_i18n_text('語意文字')) ?></label>
                <input name="text" value="<?= hub_h((string)($_POST['text'] ?? 'mammal/insect/plant')) ?>">
                <p class="muted">prompt_type=text <?= hub_h(hub_i18n_text('時填入語意 prompt，例如')) ?> <code>mammal/insect/plant</code>。</p>
                <label><?= hub_h(hub_i18n_text('輸出格式')) ?> output_format</label>
                <select name="output_format">
                    <option value="metadata">metadata</option>
                    <option value="polygon">polygon</option>
                    <option value="rle">rle</option>
                    <option value="both">both</option>
                    <option value="png">png</option>
                </select>
                <label><input name="real_inference" type="checkbox" value="1" checked> <?= hub_h(hub_i18n_text('真實推論')) ?></label>
            <?php else: ?>
                <p class="muted">hello <?= hub_h(hub_i18n_text('使用 GET，不需要欄位。')) ?></p>
            <?php endif; ?>
            <div class="hub-actions">
                <?php if ($selectedMode === 'tts'): ?><button type="submit" name="load_voice_profiles" value="1"><?= hub_h(hub_i18n_text('載入可用 Voice Profile')) ?></button><?php endif; ?>
                <?php if ($selectedMode === 'facebook_crawl'): ?><button type="submit" name="load_facebook_profiles" value="1"><?= hub_h(hub_i18n_text('載入 Profile')) ?></button><?php endif; ?>
                <button class="primary" type="submit"><?= hub_h($selectedMode === 'facebook_crawl' ? hub_i18n_text('開始背景爬取') : hub_i18n_text('執行測試')) ?></button>
            </div>
        </form>
        <?php if ($selectedMode === 'tts'): ?>
            <hr>
            <h3>Voice Profile</h3>
            <p class="muted"><?= hub_h(hub_i18n_text('管理 Basic Clone 的參考 WAV；Bearer token 僅用於本次請求。')) ?></p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                <input type="hidden" name="action" value="voice_profile_upload">
                <input type="hidden" name="mode" value="tts">
                <label>Bearer Token</label>
                <input name="bearer_token" type="password" placeholder="<TOKEN>" autocomplete="off" required>
                <label><?= hub_h(hub_i18n_text('Voice Profile 名稱')) ?></label>
                <input name="voice_profile_name" required>
                <label><?= hub_h(hub_i18n_text('授權類型')) ?></label>
                <select name="consent_type">
                    <option value="self_recorded">self_recorded</option>
                    <option value="explicit_permission">explicit_permission</option>
                    <option value="licensed_voice">licensed_voice</option>
                </select>
                <label><?= hub_h(hub_i18n_text('參考 WAV')) ?></label>
                <input name="reference_wav" type="file" accept="audio/wav,.wav" required>
                <div class="hub-actions"><button class="primary" type="submit"><?= hub_h(hub_i18n_text('上傳並轉錄')) ?></button></div>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                <input type="hidden" name="action" value="voice_profile_confirm">
                <input type="hidden" name="mode" value="tts">
                <label>Bearer Token</label>
                <input name="bearer_token" type="password" placeholder="<TOKEN>" autocomplete="off" required>
                <label>Voice Profile</label>
                <select name="voice_profile_id" required>
                    <option value=""><?= hub_h(hub_i18n_text('請先載入可用 Voice Profile')) ?></option>
                    <?php foreach ($ttsManagementProfiles as $ttsProfile): ?>
                        <?php $ttsProfileId = (int)$ttsProfile['id']; ?>
                        <option value="<?= $ttsProfileId ?>" <?= $ttsProfileId === $selectedManagementProfileId ? 'selected' : '' ?>><?= hub_h((string)$ttsProfile['name']) ?> #<?= $ttsProfileId ?></option>
                    <?php endforeach; ?>
                </select>
                <label><?= hub_h(hub_i18n_text('確認後的轉錄文字')) ?></label>
                <textarea name="prompt_text" rows="3" required><?= hub_h($voiceProfileDraftPrefill) ?></textarea>
                <div class="hub-actions"><button type="submit" name="load_voice_profiles" value="1" formnovalidate><?= hub_h(hub_i18n_text('載入可用 Voice Profile')) ?></button><button type="submit" name="load_voice_profile_draft" value="1" formnovalidate><?= hub_h(hub_i18n_text('載入 ASR 草稿')) ?></button><button type="submit"><?= hub_h(hub_i18n_text('確認轉錄文字')) ?></button></div>
            </form>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                <input type="hidden" name="action" value="voice_profile_retry_asr">
                <input type="hidden" name="mode" value="tts">
                <label>Bearer Token</label>
                <input name="bearer_token" type="password" placeholder="<TOKEN>" autocomplete="off" required>
                <label>Voice Profile</label>
                <select name="voice_profile_id" required>
                    <option value=""><?= hub_h(hub_i18n_text('請先載入可用 Voice Profile')) ?></option>
                    <?php foreach ($ttsManagementProfiles as $ttsProfile): ?>
                        <option value="<?= (int)$ttsProfile['id'] ?>"><?= hub_h((string)$ttsProfile['name']) ?> #<?= (int)$ttsProfile['id'] ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="hub-actions"><button type="submit" name="load_voice_profiles" value="1" formnovalidate><?= hub_h(hub_i18n_text('載入可用 Voice Profile')) ?></button><button type="submit"><?= hub_h(hub_i18n_text('重新嘗試 ASR')) ?></button></div>
            </form>
        <?php elseif ($selectedMode === 'facebook_crawl'): ?>
            <?= hub_playground_facebook_management_html($facebookProfiles) ?>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <h2><?= hub_h(hub_i18n_text('回應結果')) ?></h2>
    <?php if ($result === null): ?>
        <div class="hub-empty-state"><?= hub_h(hub_i18n_text('尚未執行測試。')) ?></div>
    <?php elseif ($result !== null): ?>
        <div class="hub-meta">
            <div class="hub-meta-label">HTTP status</div>
            <div class="hub-meta-value"><code><?= hub_h((string)($result['status'] ?? '-')) ?></code></div>
            <div class="hub-meta-label">elapsed_ms</div>
            <div class="hub-meta-value"><code><?= hub_h((string)($result['elapsed_ms'] ?? '-')) ?></code></div>
            <div class="hub-meta-label">request_id</div>
            <div class="hub-meta-value">
                <?php if ((string)($result['request_id'] ?? '') !== ''): ?>
                    <a href="log_explorer.php?request_id=<?= urlencode((string)$result['request_id']) ?>"><code><?= hub_h((string)$result['request_id']) ?></code></a>
                    <a class="button" href="log_explorer.php?request_id=<?= urlencode((string)$result['request_id']) ?>"><?= hub_h(hub_i18n_text('查看 API 記錄')) ?></a>
                <?php else: ?>
                    -
                <?php endif; ?>
            </div>
            <?php if ((string)($result['error'] ?? '') !== ''): ?>
                <div class="hub-meta-label">error_code</div>
                <div class="hub-meta-value"><code><?= hub_h((string)$result['error']) ?></code> <?= hub_h((string)($result['message'] ?? '')) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($selectedMode === 'background_remove' && (string)($result['preview_data_uri'] ?? '') !== ''): ?>
            <?php $birefnetMetadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : []; ?>
            <div class="birefnet-preview">
                <img id="birefnet-preview-image" src="<?= hub_h((string)$result['preview_data_uri']) ?>" alt="<?= hub_h(hub_i18n_text('去背輸出預覽')) ?>">
            </div>
            <div class="hub-actions">
                <a id="birefnet-download" class="button" href="#" download="background-removed.png"><?= hub_h(hub_i18n_text('下載 PNG')) ?></a>
            </div>
            <div class="hub-meta">
                <div class="hub-meta-label">X-3waAIHub-Model</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($birefnetMetadata['model'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">device</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($birefnetMetadata['device'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">size</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($birefnetMetadata['width'] ?? '-')) ?> x <?= hub_h((string)($birefnetMetadata['height'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">inference elapsed_ms</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($birefnetMetadata['elapsed_ms'] ?? '-')) ?></code></div>
            </div>
        <?php endif; ?>
        <?php if ($selectedMode === 'image-tools' && (string)($result['preview_data_uri'] ?? '') !== ''): ?>
            <?php $imageToolsMetadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : []; ?>
            <div class="birefnet-preview">
                <img id="image-tools-preview-image" src="<?= hub_h((string)$result['preview_data_uri']) ?>" alt="<?= hub_h(hub_i18n_text('處理後圖片輸出預覽')) ?>">
            </div>
            <div class="hub-actions">
                <a id="image-tools-download" class="button" href="#" download="processed-image.png"><?= hub_h(hub_i18n_text('下載 PNG')) ?></a>
            </div>
            <div class="hub-meta">
                <div class="hub-meta-label">X-3waAIHub-Model</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($imageToolsMetadata['model'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">X-3waAIHub-Backend</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($imageToolsMetadata['backend'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">X-3waAIHub-Elapsed-Ms</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($imageToolsMetadata['elapsed_ms'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">X-3waAIHub-Width</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($imageToolsMetadata['width'] ?? '-')) ?></code></div>
                <div class="hub-meta-label">X-3waAIHub-Height</div>
                <div class="hub-meta-value"><code><?= hub_h((string)($imageToolsMetadata['height'] ?? '-')) ?></code></div>
            </div>
        <?php endif; ?>
        <?php if ($selectedMode === 'tts' && is_array($result['results'] ?? null)): ?>
            <?php foreach ($result['results'] as $ttsMode => $ttsResult): ?>
                <div class="hub-card">
                    <h3><?= hub_h((string)$ttsMode) ?></h3>
                    <p><code>HTTP <?= hub_h((string)($ttsResult['status'] ?? '-')) ?></code><?php if ((string)($ttsResult['error'] ?? '') !== ''): ?> <code><?= hub_h((string)$ttsResult['error']) ?></code><?php endif; ?></p>
                    <?php if (isset($audioUrls[$ttsMode])): ?>
                        <audio controls src="<?= hub_h($audioUrls[$ttsMode]) ?>"></audio>
                        <p><a class="button" href="<?= hub_h($audioUrls[$ttsMode]) ?>"><?= hub_h(hub_i18n_text('下載 WAV')) ?></a></p>
                    <?php endif; ?>
                    <pre><?= hub_h((string)($ttsResult['pretty_body'] ?? json_encode($ttsResult, JSON_UNESCAPED_UNICODE))) ?></pre>
                </div>
            <?php endforeach; ?>
        <?php elseif ($audioUrl !== ''): ?>
            <div class="hub-card">
                <h3><?= hub_h(hub_i18n_text('語音預覽')) ?></h3>
                <audio controls src="<?= hub_h($audioUrl) ?>"></audio>
                <p><a class="button" href="<?= hub_h($audioUrl) ?>"><?= hub_h(hub_i18n_text('下載 WAV')) ?></a></p>
            </div>
        <?php endif; ?>
        <?php if ($selectedMode === 'facebook_crawl'): ?>
            <?= hub_playground_facebook_result_html($result) ?>
        <?php endif; ?>
        <pre><?= hub_h((string)($result['pretty_body'] ?? json_encode($result, JSON_UNESCAPED_UNICODE))) ?></pre>
    <?php endif; ?>
</section>

<section class="panel">
    <h2><?= hub_h(hub_i18n_text('介接範例')) ?></h2>
    <div class="hub-card-grid">
        <article class="hub-card">
            <h3><?= hub_h(hub_i18n_text('複製 curl')) ?></h3>
            <button type="button" data-copy-target="copy-curl"><?= hub_h(hub_i18n_text('複製 curl')) ?></button>
            <pre id="copy-curl"><?= hub_h($examples['curl']) ?></pre>
        </article>
        <article class="hub-card">
            <h3><?= hub_h(hub_i18n_text('複製 PHP')) ?></h3>
            <button type="button" data-copy-target="copy-php"><?= hub_h(hub_i18n_text('複製 PHP')) ?></button>
            <pre id="copy-php"><?= hub_h($examples['php']) ?></pre>
        </article>
        <article class="hub-card">
            <h3><?= hub_h(hub_i18n_text('複製 JS fetch')) ?></h3>
            <button type="button" data-copy-target="copy-js"><?= hub_h(hub_i18n_text('複製 JS fetch')) ?></button>
            <pre id="copy-js"><?= hub_h($examples['js']) ?></pre>
        </article>
    </div>
    <p id="playground-copy-status" class="muted"></p>
</section>
<script>
const birefnetPreview = document.getElementById('birefnet-preview-image');
const birefnetDownload = document.getElementById('birefnet-download');
if (birefnetPreview && birefnetDownload) birefnetDownload.href = birefnetPreview.src;
const imageToolsPreview = document.getElementById('image-tools-preview-image');
const imageToolsDownload = document.getElementById('image-tools-download');
if (imageToolsPreview && imageToolsDownload) imageToolsDownload.href = imageToolsPreview.src;
document.querySelectorAll('[data-token-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.target || '');
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? <?= hub_json_encode(hub_i18n_text('顯示 token'), JSON_UNESCAPED_UNICODE) ?> : <?= hub_json_encode(hub_i18n_text('隱藏 token'), JSON_UNESCAPED_UNICODE) ?>;
    });
});
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.getElementById(button.dataset.copyTarget || '');
        const status = document.getElementById('playground-copy-status');
        if (!target || !navigator.clipboard) {
            if (status) status.textContent = <?= hub_json_encode(hub_i18n_text('請手動複製。'), JSON_UNESCAPED_UNICODE) ?>;
            return;
        }
        try {
            await navigator.clipboard.writeText(target.textContent || '');
            if (status) status.textContent = <?= hub_json_encode(hub_i18n_text('已複製。'), JSON_UNESCAPED_UNICODE) ?>;
        } catch (e) {
            if (status) status.textContent = <?= hub_json_encode(hub_i18n_text('請手動複製。'), JSON_UNESCAPED_UNICODE) ?>;
        }
    });
});
</script>
<?php hub_admin_footer(); ?>
