<?php
declare(strict_types=1);

function hub_gateway_dispatch(PDO $db, string $mode, ?callable $requester = null): array
{
    $started = microtime(true);
    $requestId = hub_new_request_id();
    $authContext = [];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(400, 'bad_request', 'invalid mode'), $started, $requestId);
    }
    $service = hub_get_service_by_mode($db, $mode);
    if (!$service && hub_is_task_api_mode($mode)) {
        $clientIp = hub_get_client_ip();
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext);
        }

        return hub_gateway_finish($db, null, $mode, hub_task_api_dispatch($db, $mode), $started, $requestId, $authContext);
    }
    if (!$service) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(404, 'unknown_mode', 'mode is not registered'), $started, $requestId);
    }
    $clientIp = hub_get_client_ip();
    $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp);
    $authContext = $auth['context'] ?? [];
    if (empty($auth['ok'])) {
        return hub_gateway_finish($db, $service, $mode, $auth['response'], $started, $requestId, $authContext);
    }
    if ((int)$service['enabled'] !== 1) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'service_disabled', 'service is disabled'), $started, $requestId, $authContext);
    }
    if (in_array((string)$service['runtime_status'], ['pending', 'not_ready'], true) || (string)$service['install_status'] !== 'installed') {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'runtime_not_ready', 'service runtime is not ready'), $started, $requestId, $authContext);
    }
    if (!hub_gateway_service_ip_allowed_after_auth($db, $service, $clientIp, $authContext)) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(403, 'ip_not_allowed', 'client IP is not allowed for this service'), $started, $requestId, $authContext);
    }
    if (!hub_service_method_allowed($service, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed for this mode'), $started, $requestId, $authContext);
    }
    if (!hub_service_upload_size_allowed($service, (string)($_SERVER['CONTENT_LENGTH'] ?? ''))) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(413, 'payload_too_large', 'request body is larger than this service allows'), $started, $requestId, $authContext);
    }

    $timeoutSec = hub_service_gateway_timeout_sec($service);
    $prepared = [];
    if ($requester === null) {
        $prepared = hub_gateway_prepare_service_request($db, $service, $authContext);
        if (isset($prepared['response'])) {
            return hub_gateway_finish($db, $service, $mode, $prepared['response'], $started, $requestId, $authContext);
        }
    }
    $requester ??= static fn (array $service, int $timeoutSec): array => hub_proxy_request(
        $service['internal_url'],
        $timeoutSec,
        is_string($prepared['body'] ?? null) ? (string)$prepared['body'] : null,
        is_string($prepared['content_type'] ?? null) ? (string)$prepared['content_type'] : null
    );

    return hub_gateway_finish($db, $service, $mode, $requester($service, $timeoutSec), $started, $requestId, $authContext);
}

function hub_gateway_prepare_service_request(PDO $db, array $service, array $authContext): array
{
    return match ((string)($service['pack_id'] ?? '')) {
        'tts-voxcpm2' => hub_prepare_tts_voxcpm2_payload($db, $service, $authContext, (string)file_get_contents('php://input')),
        default => [],
    };
}

function hub_prepare_tts_voxcpm2_payload(PDO $db, array $service, array $authContext, string $rawBody): array
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) !== 'POST' && trim($rawBody) === '') {
        return [];
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'JSON body is required')];
    }
    foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path'] as $blockedKey) {
        if (array_key_exists($blockedKey, $payload)) {
            return ['response' => hub_gateway_error(400, 'bad_request', 'server-side audio paths are not accepted')];
        }
    }

    $ttsMode = trim((string)($payload['mode'] ?? 'design')) ?: 'design';
    if ($ttsMode === 'ultimate_clone') {
        return ['response' => hub_gateway_error(501, 'ultimate_clone_not_ready', 'Ultimate clone will be added in a later phase')];
    }
    if (!in_array($ttsMode, ['design', 'clone'], true)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'mode must be design or clone')];
    }

    if ($ttsMode === 'clone') {
        if (empty($authContext['member_id'])) {
            return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice clone requires an owned voice profile')];
        }
        try {
            $profileId = hub_normalize_voice_profile_ref($payload['voice_profile_id'] ?? $payload['reference_audio_id'] ?? '');
            $profile = hub_get_voice_profile_for_member($db, $profileId, (int)$authContext['member_id']);
            if (!$profile) {
                return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice profile is not available for this member')];
            }
            $payload['reference_wav_path'] = hub_voice_profile_container_path($profile);
            $payload['voice_profile_id'] = (int)$profile['id'];
            $payload['reference_audio_sha256'] = (string)$profile['reference_audio_sha256'];
            unset($payload['reference_audio_id']);
            hub_record_voice_profile_audit(
                $db,
                (int)$profile['id'],
                (int)$profile['owner_member_id'],
                isset($authContext['token_id']) ? (int)$authContext['token_id'] : null,
                'use',
                'clone',
                [
                    'service_id' => (int)($service['id'] ?? 0),
                    'mode' => (string)($service['mode'] ?? 'tts'),
                    'text_chars' => function_exists('mb_strlen') ? mb_strlen((string)($payload['text'] ?? ''), 'UTF-8') : strlen((string)($payload['text'] ?? '')),
                ]
            );
        } catch (InvalidArgumentException) {
            return ['response' => hub_gateway_error(400, 'voice_profile_required', 'reference_audio_id or voice_profile_id is required')];
        } catch (Throwable) {
            return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice profile could not be used')];
        }
    }

    return [
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'content_type' => 'application/json',
    ];
}

function hub_gateway_service_ip_allowed_after_auth(PDO $db, array $service, string $clientIp, array $authContext): bool
{
    if (empty($authContext['token_id'])) {
        return hub_service_ip_allowed($db, $service, $clientIp);
    }
    if (hub_get_storage_setting($db, 'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST') !== '1') {
        return true;
    }

    return hub_enabled_service_ip_rules($db, (int)$service['id']) === [] || hub_service_ip_allowed($db, $service, $clientIp);
}

function hub_is_task_api_mode(string $mode): bool
{
    return array_key_exists($mode, hub_task_api_modes());
}

function hub_task_api_modes(): array
{
    return [
        'task_submit' => 'Task Submit',
        'task_status' => 'Task Status',
        'task_result' => 'Task Result',
        'task_log' => 'Task Log',
        'task_cancel' => 'Task Cancel',
        'artifact' => 'Task Artifact',
    ];
}

function hub_task_api_dispatch(PDO $db, string $mode): array
{
    return match ($mode) {
        'task_submit' => hub_api_task_submit($db),
        'task_status' => hub_api_task_status($db),
        'task_result' => hub_api_task_result($db),
        'task_log' => hub_api_task_log($db),
        'task_cancel' => hub_api_task_cancel($db),
        'artifact' => hub_api_artifact($db),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown mode']),
    };
}

function hub_api_task_submit(PDO $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_json(405, ['ok' => false, 'error' => 'task_submit requires POST']);
    }

    $taskType = trim((string)($_POST['task_type'] ?? ''));
    if (!hub_is_valid_task_type($taskType)) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unknown task_type']);
    }

    $queueName = trim((string)($_POST['queue'] ?? 'default'));
    if (!hub_is_valid_task_queue($queueName)) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unknown queue']);
    }

    $priority = max(0, min(100, (int)($_POST['priority'] ?? 0)));
    $input = $_POST;
    unset($input['task_type'], $input['queue'], $input['priority']);

    $taskId = hub_enqueue_task($db, $taskType, $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null);

    return hub_gateway_json(200, ['ok' => true, 'task_id' => $taskId, 'status' => 'queued']);
}

function hub_api_task_status(PDO $db): array
{
    $task = hub_api_load_task($db);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$task['id'],
        'task_type' => $task['task_type'],
        'queue' => $task['queue_name'],
        'priority' => (int)$task['priority'],
        'status' => $task['status'],
        'progress' => (int)$task['progress'],
        'error_message' => $task['error_message'],
        'created_at' => $task['created_at'],
        'started_at' => $task['started_at'],
        'finished_at' => $task['finished_at'],
    ]);
}

function hub_api_task_result(PDO $db): array
{
    $task = hub_api_load_task($db);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    if ($task['status'] !== 'success') {
        return hub_gateway_json(409, ['ok' => false, 'task_id' => (int)$task['id'], 'status' => $task['status']]);
    }

    return hub_gateway_json(200, ['ok' => true, 'task_id' => (int)$task['id'], 'result' => $task['result']]);
}

function hub_api_task_log(PDO $db): array
{
    $task = hub_api_load_task($db);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$task['id'],
        'logs' => hub_list_task_logs($db, (int)$task['id']),
    ]);
}

function hub_api_task_cancel(PDO $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_json(405, ['ok' => false, 'error' => 'task_cancel requires POST']);
    }

    $task = hub_api_load_task($db);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    if (!hub_cancel_task($db, (int)$task['id'])) {
        return hub_gateway_json(409, ['ok' => false, 'task_id' => (int)$task['id'], 'status' => $task['status'], 'error' => 'only queued tasks can be cancelled']);
    }

    return hub_gateway_json(200, ['ok' => true, 'task_id' => (int)$task['id'], 'status' => 'cancelled']);
}

function hub_api_artifact(PDO $db): array
{
    $artifactId = (int)($_GET['artifact_id'] ?? $_POST['artifact_id'] ?? 0);
    $artifact = $artifactId > 0 ? hub_get_task_artifact($db, $artifactId) : null;
    if (!$artifact) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'artifact not found']);
    }

    $path = hub_artifact_safe_path($artifact['path']);
    if ($path === null) {
        return hub_gateway_json(403, ['ok' => false, 'error' => 'artifact path rejected']);
    }

    return [
        'status' => 200,
        'headers' => [
            'Content-Type: ' . $artifact['mime_type'],
            'Content-Disposition: attachment; filename="' . basename($artifact['name']) . '"',
        ],
        'body' => (string)file_get_contents($path),
    ];
}

function hub_api_load_task(PDO $db): ?array
{
    $taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
    return $taskId > 0 ? hub_get_task($db, $taskId) : null;
}

function hub_proxy_request(string $url, int $timeoutSec = 60, ?string $bodyOverride = null, ?string $contentTypeOverride = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return hub_gateway_json(502, ['ok' => false, 'error' => 'curl unavailable']);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $headers = [];
    $hasUploads = !empty($_FILES);
    if (!$hasUploads && $contentTypeOverride !== null) {
        $headers[] = 'Content-Type: ' . $contentTypeOverride;
    } elseif (!$hasUploads && !empty($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
    ]);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasUploads ? hub_proxy_post_fields($_POST, $_FILES) : ($bodyOverride ?? file_get_contents('php://input')));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => hub_gateway_error(504, 'gateway_timeout', 'service gateway timeout'),
            CURLE_COULDNT_CONNECT => hub_gateway_error(503, 'service_unavailable', 'service is unavailable'),
            default => hub_gateway_error(502, 'proxy_error', 'service proxy error'),
        };
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => ['Content-Type: ' . $contentType], 'body' => $body];
}

function hub_proxy_post_fields(array $post, array $files): array
{
    $fields = $post;
    foreach ($files as $field => $file) {
        if (!is_array($file) || is_array($file['tmp_name'] ?? null)) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)$file['tmp_name'])) {
            continue;
        }
        $fields[$field] = new CURLFile(
            (string)$file['tmp_name'],
            (string)($file['type'] ?? 'application/octet-stream'),
            (string)($file['name'] ?? $field)
        );
    }

    return $fields;
}

function hub_gateway_json(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['Content-Type: application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_gateway_error(int $status, string $errorCode, string $message): array
{
    return hub_gateway_json($status, ['ok' => false, 'error' => $errorCode, 'message' => $message]);
}

function hub_gateway_finish(PDO $db, ?array $service, string $mode, array $response, float $started, string $requestId, array $authContext = []): array
{
    $status = (int)$response['status'];
    $response = hub_gateway_attach_request_id($response, $requestId);
    [$errorCode, $reason] = hub_gateway_response_error($response);
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    hub_log_api_access(
        $db,
        $service,
        $mode,
        $status,
        $status >= 200 && $status < 400,
        $status >= 400 ? $errorCode : null,
        $status >= 400 ? $reason : null,
        $elapsedMs,
        $requestId,
        $authContext,
        hub_gateway_upload_bytes(),
        strlen((string)($response['body'] ?? ''))
    );

    return $response;
}

function hub_gateway_upload_bytes(): int
{
    $contentLength = trim((string)($_SERVER['CONTENT_LENGTH'] ?? ''));
    if ($contentLength !== '' && ctype_digit($contentLength)) {
        return (int)$contentLength;
    }
    $bytes = 0;
    foreach ($_FILES ?? [] as $file) {
        if (is_array($file) && isset($file['size']) && is_numeric($file['size']) && !is_array($file['size'])) {
            $bytes += (int)$file['size'];
        }
    }

    return $bytes;
}

function hub_gateway_attach_request_id(array $response, string $requestId): array
{
    $response['headers'][] = 'X-3waAIHub-Request-Id: ' . $requestId;
    if ((int)$response['status'] < 400) {
        return $response;
    }

    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (is_array($payload) && !isset($payload['request_id'])) {
        $payload['request_id'] = $requestId;
        $response['body'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $response;
}

function hub_gateway_response_error(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (is_array($payload)) {
        return [
            is_string($payload['error'] ?? null) ? $payload['error'] : 'proxy_error',
            is_string($payload['message'] ?? null) ? $payload['message'] : null,
        ];
    }

    return ['proxy_error', null];
}

function hub_service_method_allowed(array $service, string $method): bool
{
    $methods = hub_service_gateway_methods($service);
    return $methods === [] || in_array(strtoupper($method), $methods, true);
}

function hub_service_gateway_methods(array $service): array
{
    $packId = (string)($service['pack_id'] ?? '');
    if ($packId === '') {
        return [];
    }
    $pack = hub_get_pack($packId);
    $methods = $pack['manifest']['gateway']['methods'] ?? [];
    if (!is_array($methods)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($method): string => strtoupper((string)$method), $methods)));
}

function hub_service_upload_size_allowed(array $service, string $contentLength): bool
{
    $maxUploadMb = hub_service_gateway_int($service, 'max_upload_mb', 0);
    if ($maxUploadMb <= 0 || trim($contentLength) === '') {
        return true;
    }

    return (float)$contentLength <= $maxUploadMb * 1024 * 1024;
}

function hub_service_gateway_timeout_sec(array $service): int
{
    return max(1, hub_service_gateway_int($service, 'timeout_sec', 60));
}

function hub_service_gateway_int(array $service, string $key, int $default): int
{
    $packId = (string)($service['pack_id'] ?? '');
    if ($packId === '') {
        return $default;
    }
    $pack = hub_get_pack($packId);
    $value = $pack['manifest']['gateway'][$key] ?? null;

    return is_numeric($value) ? (int)$value : $default;
}

function hub_send_gateway_response(array $response): never
{
    http_response_code((int)$response['status']);
    foreach ($response['headers'] as $header) {
        header($header);
    }
    echo $response['body'];
    exit;
}
