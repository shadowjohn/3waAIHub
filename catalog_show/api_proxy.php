<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = hub_db();
$user = hub_require_login($db);
hub_check_csrf();

header('Content-Type: application/json; charset=utf-8');

$mode = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['mode'] ?? ''));
$items = hub_catalog_show_items();
if ($mode === '' || !isset($items[$mode])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_mode'], JSON_UNESCAPED_UNICODE);
    exit;
}

$allowedModes = hub_catalog_show_user_modes($db, $user);
if (hub_is_customer($user) && !in_array($mode, $allowedModes, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'mode_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string)($_POST['bearer_token'] ?? ''));
if ($token === '') {
    $token = hub_catalog_show_session_token($db, $user, $mode);
}

$started = microtime(true);
$response = hub_catalog_show_call_gateway($mode, $token);
$response['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
$response['token_prefix'] = $token !== '' ? hub_api_token_prefix($token) : '';
$response['token'] = $token;

http_response_code((int)($response['status'] ?? 200));
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

function hub_catalog_show_call_gateway(string $mode, string $token): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 500, 'error' => 'curl_unavailable'];
    }

    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    if ($mode === 'photo') {
        $file = $_FILES['image'] ?? null;
        $imageId = trim((string)($_POST['image_id'] ?? ''));
        if ($imageId === '' && is_array($file) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $upload = hub_catalog_show_curl('photo_upload', $headers);
            curl_setopt_array($upload, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => [
                    'image' => new CURLFile((string)$file['tmp_name'], (string)($file['type'] ?? 'application/octet-stream'), (string)($file['name'] ?? 'image')),
                ],
            ]);
            $uploadResult = hub_catalog_show_finish_curl($upload);
            if (empty($uploadResult['ok'])) {
                return $uploadResult;
            }
            $imageId = (string)($uploadResult['json']['image_id'] ?? '');
        }
        if ($imageId === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'image_id_required', 'message' => '請上傳圖片或填入 image_id。'];
        }

        return hub_catalog_show_post_json($mode, $headers, [
            'image_id' => $imageId,
            'text' => trim((string)($_POST['text'] ?? '這張圖裡有什麼？')),
            'max_tokens' => max(1, (int)($_POST['max_tokens'] ?? 256)),
            'real_inference' => !empty($_POST['real_inference']),
        ]);
    }

    if (in_array($mode, ['chat'], true)) {
        return hub_catalog_show_post_json($mode, $headers, [
            'text' => trim((string)($_POST['text'] ?? '請用正體中文介紹 3waAIHub。')),
            'system_prompt' => '你是 3waAIHub 展示助理，請用正體中文簡潔回答。',
            'max_tokens' => max(1, (int)($_POST['max_tokens'] ?? 256)),
            'temperature' => 0.2,
            'real_inference' => !empty($_POST['real_inference']),
        ]);
    }

    if ($mode === 'docparser') {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'status' => 400, 'error' => 'file_required', 'message' => '請選擇 PDF。'];
        }
        $ch = hub_catalog_show_curl($mode, $headers);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile((string)$file['tmp_name'], (string)($file['type'] ?? 'application/pdf'), (string)($file['name'] ?? 'manual.pdf')),
                'target_language' => 'zh-TW',
                'translation_required' => '1',
            ],
        ]);

        return hub_catalog_show_finish_curl($ch);
    }

    if (in_array($mode, ['ocr', 'yolo', 'sam3'], true)) {
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'status' => 400, 'error' => 'image_required', 'message' => '請選擇圖片。'];
        }
        $fields = [
            'image' => new CURLFile((string)$file['tmp_name'], (string)($file['type'] ?? 'application/octet-stream'), (string)($file['name'] ?? 'image')),
            'real_inference' => !empty($_POST['real_inference']) ? '1' : '0',
        ];
        if ($mode === 'sam3') {
            $fields['prompt_type'] = trim((string)($_POST['prompt_type'] ?? 'text')) ?: 'text';
            $fields['text'] = trim((string)($_POST['text'] ?? 'mammal/insect/plant'));
            $fields['output_format'] = trim((string)($_POST['output_format'] ?? 'polygon')) ?: 'polygon';
        }
        $ch = hub_catalog_show_curl($mode, $headers);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);

        return hub_catalog_show_finish_curl($ch);
    }

    return ['ok' => false, 'status' => 400, 'error' => 'unsupported_mode'];
}

function hub_catalog_show_curl(string $mode, array $headers)
{
    $ch = curl_init(hub_catalog_show_local_api_url($mode));
    if ($ch === false) {
        throw new RuntimeException('curl init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 240,
    ]);

    return $ch;
}

function hub_catalog_show_post_json(string $mode, array $headers, array $payload): array
{
    $headers[] = 'Content-Type: application/json';
    $ch = hub_catalog_show_curl($mode, $headers);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return hub_catalog_show_finish_curl($ch);
}

function hub_catalog_show_finish_curl($ch): array
{
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return ['ok' => false, 'status' => 502, 'error' => 'request_failed', 'message' => $error];
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    curl_close($ch);
    $body = substr((string)$raw, (int)$headerSize);
    $json = json_decode($body, true);

    return [
        'ok' => $status >= 200 && $status < 400,
        'status' => (int)$status,
        'json' => is_array($json) ? $json : null,
        'body' => $body,
    ];
}
