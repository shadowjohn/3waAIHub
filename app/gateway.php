<?php
declare(strict_types=1);

function hub_gateway_dispatch(PDO $db, string $mode, ?callable $requester = null): array
{
    $started = microtime(true);
    $requestId = hub_new_request_id();
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(400, 'bad_request', 'invalid mode'), $started, $requestId);
    }
    if (hub_is_task_api_mode($mode)) {
        return hub_gateway_finish($db, null, $mode, hub_task_api_dispatch($db, $mode), $started, $requestId);
    }

    $service = hub_get_service_by_mode($db, $mode);
    if (!$service) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(404, 'unknown_mode', 'mode is not registered'), $started, $requestId);
    }
    if ((int)$service['enabled'] !== 1) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'service_disabled', 'service is disabled'), $started, $requestId);
    }
    if (in_array((string)$service['runtime_status'], ['pending', 'not_ready'], true) || (string)$service['install_status'] !== 'installed') {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'runtime_not_ready', 'service runtime is not ready'), $started, $requestId);
    }
    if (!hub_service_ip_allowed($db, $service, hub_get_client_ip())) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(403, 'ip_not_allowed', 'client IP is not allowed for this service'), $started, $requestId);
    }
    if (!hub_service_method_allowed($service, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed for this mode'), $started, $requestId);
    }

    $requester ??= static fn (): array => hub_proxy_request($service['internal_url']);

    return hub_gateway_finish($db, $service, $mode, $requester($service), $started, $requestId);
}

function hub_is_task_api_mode(string $mode): bool
{
    return in_array($mode, ['task_submit', 'task_status', 'task_result', 'task_log', 'task_cancel', 'artifact'], true);
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

function hub_proxy_request(string $url): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return hub_gateway_json(502, ['ok' => false, 'error' => 'curl unavailable']);
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $headers = [];
    $hasUploads = !empty($_FILES);
    if (!$hasUploads && !empty($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 60,
    ]);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasUploads ? hub_proxy_post_fields($_POST, $_FILES) : file_get_contents('php://input'));
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

function hub_gateway_finish(PDO $db, ?array $service, string $mode, array $response, float $started, string $requestId): array
{
    $status = (int)$response['status'];
    $response = hub_gateway_attach_request_id($response, $requestId);
    [$errorCode, $reason] = hub_gateway_response_error($response);
    hub_log_api_access(
        $db,
        $service,
        $mode,
        $status,
        $status >= 200 && $status < 400,
        $status >= 400 ? $errorCode : null,
        $status >= 400 ? $reason : null,
        (int)round((microtime(true) - $started) * 1000),
        $requestId
    );

    return $response;
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

function hub_send_gateway_response(array $response): never
{
    http_response_code((int)$response['status']);
    foreach ($response['headers'] as $header) {
        header($header);
    }
    echo $response['body'];
    exit;
}
