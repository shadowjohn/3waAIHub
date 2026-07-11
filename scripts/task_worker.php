<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$db = hub_db();
hub_migrate($db);

$processed = 0;
while ($processed < $limit) {
    $task = hub_claim_next_task($db);
    if (!$task) {
        break;
    }

    try {
        hub_run_task($db, $task);
    } catch (Throwable $e) {
        hub_add_task_log($db, (int)$task['id'], 'error', $e->getMessage());
        hub_finish_task_failed($db, $task, $e->getMessage());
    }

    $latest = hub_get_task($db, (int)$task['id']);
    echo 'task ' . $task['id'] . ' ' . $task['task_type'] . ' status=' . ($latest['status'] ?? 'missing') . PHP_EOL;
    $processed++;
}

function hub_run_task(PDO $db, array $task): void
{
    if ($task['task_type'] === 'structure_parse') {
        hub_run_structure_parse_task($db, $task);
        return;
    }

    if ($task['task_type'] !== 'demo_task') {
        throw new RuntimeException('Unknown task type.');
    }

    hub_add_task_log($db, (int)$task['id'], 'info', 'demo_task started');
    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'demo_task',
        'message' => 'demo task completed',
        'input' => $task['input'],
    ]);
    hub_add_task_log($db, (int)$task['id'], 'info', 'demo_task finished');
}

function hub_run_structure_parse_task(PDO $db, array $task): void
{
    $taskId = (int)$task['id'];
    $input = $task['input'] ?? [];
    $mode = preg_match('/^[a-zA-Z0-9_-]+$/', (string)($input['mode'] ?? 'structure')) ? (string)($input['mode'] ?? 'structure') : 'structure';
    $service = hub_get_service_by_mode($db, $mode);
    if (!$service) {
        throw new RuntimeException('structure service is not installed.');
    }
    if ((string)($service['pack_id'] ?? '') !== 'structure-ppstructurev3') {
        throw new RuntimeException('structure_parse requires a PP-StructureV3 service.');
    }
    if ((int)($service['enabled'] ?? 0) !== 1) {
        throw new RuntimeException('structure service is disabled.');
    }

    $inputFile = hub_structure_task_input_file($input);
    $outputFormat = in_array((string)($input['output_format'] ?? 'both'), ['markdown', 'json', 'both'], true)
        ? (string)$input['output_format']
        : 'both';

    hub_add_task_log($db, $taskId, 'info', 'structure_parse started mode=' . $mode . ' file=' . basename($inputFile));
    hub_update_task_progress($db, $taskId, 10);

    $response = hub_structure_call_service($service, $inputFile, $outputFormat);
    hub_update_task_progress($db, $taskId, 80);
    $artifacts = hub_store_structure_task_artifacts($db, $taskId, $response['payload']);

    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'structure_parse',
        'mode' => $mode,
        'http_status' => $response['status'],
        'output_format' => $outputFormat,
        'artifact_summary' => $artifacts,
        'summary' => [
            'runtime_level' => $response['payload']['runtime_level'] ?? null,
            'mock' => $response['payload']['mock'] ?? null,
            'result_count' => $response['payload']['result_count'] ?? null,
            'elapsed_ms' => $response['payload']['elapsed_ms'] ?? null,
        ],
    ]);
    hub_add_task_log($db, $taskId, 'info', 'structure_parse finished artifacts=' . count($artifacts));
}

function hub_structure_task_input_file(array $input): string
{
    $path = (string)($input['input_file'] ?? '');
    $realPath = realpath($path);
    $uploadsRoot = realpath(HUB_DATA_DIR . '/uploads');
    if ($path === '' || $realPath === false || $uploadsRoot === false || !is_file($realPath) || !str_starts_with($realPath, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('structure task input file is missing or unsafe.');
    }

    return $realPath;
}

function hub_structure_call_service(array $service, string $inputFile, string $outputFormat): array
{
    $ch = curl_init((string)$service['internal_url']);
    if ($ch === false) {
        throw new RuntimeException('curl unavailable.');
    }

    $mime = mime_content_type($inputFile) ?: 'application/octet-stream';
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, hub_service_gateway_timeout_sec($service)),
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($inputFile, $mime, basename($inputFile)),
            'output_format' => $outputFormat,
            'real_inference' => '1',
        ],
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('structure service request failed: ' . $error);
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
    curl_close($ch);
    $body = substr((string)$raw, (int)$headerSize);
    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        throw new RuntimeException('structure service returned invalid JSON.');
    }
    if ($status < 200 || $status >= 300 || empty($payload['ok'])) {
        throw new RuntimeException('structure service failed: ' . (string)($payload['error'] ?? 'unknown_error'));
    }

    return ['status' => $status, 'payload' => $payload];
}
