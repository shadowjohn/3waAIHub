<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();
umask(0002);

$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$db = hub_db();
$missing = hub_runtime_schema_missing($db);
if ($missing !== []) {
    fwrite(STDERR, 'schema_upgrade_required: ' . implode(', ', $missing) . '. Run php scripts/init_db.php.' . PHP_EOL);
    exit(1);
}

$processed = 0;
while ($processed < $limit) {
    hub_reconcile_expired_pack_job_runs($db);
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    if (!$task) {
        break;
    }

    try {
        hub_run_task($db, $task);
    } catch (HubTaskCancelled $e) {
        hub_add_task_log($db, (int)$task['id'], 'warning', $e->getMessage());
        hub_finish_task_cancelled($db, $task, $e->getMessage());
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
    if ($task['task_type'] === 'pack_job') {
        $outcome = hub_run_pack_job_task($db, $task);
        if (($outcome['status'] ?? '') === 'fence_lost') {
            hub_add_task_log($db, (int)$task['id'], 'warning', 'pack_job_fence_lost_recovery_pending');
        }
        return;
    }

    if ($task['task_type'] === 'docparser_repair_translation') {
        hub_run_docparser_repair_translation_task($db, $task);
        return;
    }

    if ($task['task_type'] === 'docparser_parse') {
        hub_run_docparser_parse_task($db, $task);
        return;
    }

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

function hub_run_docparser_parse_task(PDO $db, array $task): void
{
    $taskId = (int)$task['id'];
    hub_abort_if_task_cancel_requested($db, $taskId);
    $input = $task['input'] ?? [];
    $inputFile = hub_structure_task_input_file($input);
    $profile = (string)($input['profile'] ?? 'technical_manual');
    $targetLanguage = (string)($input['target_language'] ?? 'zh-TW');
    $structureMode = preg_match('/^[a-zA-Z0-9_-]+$/', (string)($input['structure_mode'] ?? 'structure'))
        ? (string)($input['structure_mode'] ?? 'structure')
        : 'structure';
    $structureService = hub_get_service_by_mode($db, $structureMode);
    if (!$structureService || (int)($structureService['enabled'] ?? 0) !== 1) {
        throw new RuntimeException('blocked_dependency: structure service is unavailable.');
    }

    hub_add_task_log($db, $taskId, 'info', 'docparser_parse started file=' . basename($inputFile));
    hub_update_task_progress($db, $taskId, 10);
    hub_abort_if_task_cancel_requested($db, $taskId);

    $structure = hub_structure_call_service($structureService, $inputFile, 'both');
    hub_update_task_progress($db, $taskId, 35);
    hub_abort_if_task_cancel_requested($db, $taskId);

    $docir = hub_docparser_build_docir(hub_docparser_structure_payload($structure['payload']), [
        'profile' => $profile,
        'target_language' => $targetLanguage,
    ]);
    $docir = hub_docparser_extract_figure_assets($inputFile, $docir, hub_task_result_dir($taskId) . '/docparser/assets/figures');
    hub_update_task_progress($db, $taskId, 55);
    hub_abort_if_task_cancel_requested($db, $taskId);

    $docir = hub_docparser_translate_blocks($db, $docir, $input, $taskId);
    hub_abort_if_task_cancel_requested($db, $taskId);
    $outputs = hub_docparser_render_outputs($docir, ['target_language' => $targetLanguage]);
    hub_abort_if_task_cancel_requested($db, $taskId);
    $fixture = json_decode((string)file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/' . $profile . '_v0.1.json'), true) ?: [];
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    hub_update_task_progress($db, $taskId, 85);
    hub_abort_if_task_cancel_requested($db, $taskId);

    $toc = json_decode($outputs['toc_json'], true) ?: [];
    $rag = json_decode($outputs['rag_chunks_json'], true) ?: [];
    $manifest = [
        'status' => $quality['status'],
        'profile' => $profile,
        'input_sha256' => hash_file('sha256', $inputFile),
        'structure_mode' => $structureMode,
        'target_language' => $targetLanguage,
        'created_at' => hub_now(),
    ];

    $artifacts = hub_store_docparser_task_artifacts($db, $taskId, [
        'manifest' => $manifest,
        'reader_html' => $outputs['reader_html'],
        'bilingual_html' => $outputs['bilingual_html'],
        'markdown' => $outputs['markdown'],
        'docir' => $docir,
        'toc' => $toc,
        'rag_chunks' => $rag,
        'quality_report' => $quality,
        'figures' => [],
    ]);

    if (($quality['status'] ?? 'needs_review') !== 'completed') {
        $message = 'docparser_quality_gate_failed';
        if (($quality['failures'] ?? []) !== []) {
            $message .= ': ' . implode(',', $quality['failures']);
        }
        hub_add_task_log($db, $taskId, 'warning', $message);
        throw new RuntimeException($message);
    }

    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'docparser_parse',
        'status' => $quality['status'],
        'artifact_summary' => $artifacts,
        'quality' => $quality,
    ]);
    hub_add_task_log($db, $taskId, 'info', 'docparser_parse finished status=' . $quality['status']);
}

function hub_run_docparser_repair_translation_task(PDO $db, array $task): void
{
    $taskId = (int)$task['id'];
    $input = $task['input'] ?? [];
    $sourceTaskId = (int)($input['source_task_id'] ?? 0);
    $blockIds = is_array($input['block_ids'] ?? null) ? array_values(array_map('strval', $input['block_ids'])) : [];
    if ($sourceTaskId <= 0 || $blockIds === []) {
        throw new RuntimeException('invalid_repair_input');
    }

    $sourceTask = hub_get_task($db, $sourceTaskId);
    if (!$sourceTask || (string)($sourceTask['task_type'] ?? '') !== 'docparser_parse') {
        throw new RuntimeException('source_task_not_found');
    }

    hub_add_task_log($db, $taskId, 'info', 'docparser_repair_translation started source_task_id=' . $sourceTaskId . ' blocks=' . implode(',', $blockIds));
    hub_add_task_log($db, $sourceTaskId, 'info', 'docparser_repair_translation queued repair_task_id=' . $taskId . ' blocks=' . implode(',', $blockIds));
    hub_update_task_progress($db, $taskId, 10);

    $docir = hub_docparser_load_registered_docir_artifact($db, $sourceTaskId);
    $sourceInput = is_array($sourceTask['input'] ?? null) ? $sourceTask['input'] : [];
    $repair = hub_docparser_repair_translation_docir($db, $docir, $sourceInput, $blockIds, $taskId);
    $docir = $repair['docir'];
    hub_update_task_progress($db, $taskId, 60);

    $targetLanguage = (string)($sourceInput['target_language'] ?? $docir['target_language'] ?? 'zh-TW');
    $outputs = hub_docparser_render_outputs($docir, ['target_language' => $targetLanguage]);
    $profile = (string)($sourceInput['profile'] ?? 'technical_manual');
    $fixturePath = HUB_ROOT . '/packs/docparser/acceptance/' . $profile . '_v0.1.json';
    $fixture = is_file($fixturePath) ? (json_decode((string)file_get_contents($fixturePath), true) ?: []) : [];
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    $toc = json_decode($outputs['toc_json'], true) ?: [];
    $rag = json_decode($outputs['rag_chunks_json'], true) ?: [];
    $manifest = [
        'status' => $quality['status'],
        'profile' => $profile,
        'input_sha256' => (string)($sourceInput['input_sha256'] ?? ''),
        'structure_mode' => (string)($sourceInput['structure_mode'] ?? 'structure'),
        'target_language' => $targetLanguage,
        'repaired_at' => hub_now(),
        'repair_task_id' => $taskId,
    ];

    $artifacts = hub_store_docparser_task_artifacts($db, $sourceTaskId, [
        'manifest' => $manifest,
        'reader_html' => $outputs['reader_html'],
        'bilingual_html' => $outputs['bilingual_html'],
        'markdown' => $outputs['markdown'],
        'docir' => $docir,
        'toc' => $toc,
        'rag_chunks' => $rag,
        'quality_report' => $quality,
        'figures' => [],
    ]);
    hub_update_task_progress($db, $taskId, 90);

    $sourceResult = [
        'ok' => ($quality['status'] ?? '') === 'completed',
        'task_type' => 'docparser_parse',
        'status' => $quality['status'],
        'artifact_summary' => $artifacts,
        'quality' => $quality,
        'repaired_by_task_id' => $taskId,
        'repaired_block_ids' => $repair['repaired_block_ids'],
        'skipped_block_ids' => $repair['skipped_block_ids'],
    ];
    $sourceError = null;
    $sourceStatus = 'success';
    if (($quality['status'] ?? 'needs_review') !== 'completed') {
        $sourceStatus = 'failed';
        $sourceError = 'docparser_quality_gate_failed';
        if (($quality['failures'] ?? []) !== []) {
            $sourceError .= ': ' . implode(',', $quality['failures']);
        }
    }
    hub_finish_task_terminal_result($db, $sourceTask, $sourceStatus, $sourceResult, $sourceError);

    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'docparser_repair_translation',
        'source_task_id' => $sourceTaskId,
        'repaired_block_ids' => $repair['repaired_block_ids'],
        'skipped_block_ids' => $repair['skipped_block_ids'],
        'quality_status' => (string)($quality['status'] ?? 'needs_review'),
        'artifact_summary' => $artifacts,
    ]);
    hub_add_task_log($db, $taskId, 'info', 'docparser_repair_translation finished status=' . ($quality['status'] ?? 'needs_review'));
    hub_add_task_log($db, $sourceTaskId, 'info', 'docparser_repair_translation applied repair_task_id=' . $taskId . ' status=' . ($quality['status'] ?? 'needs_review'));
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
        throw new RuntimeException('structure service failed: HTTP ' . $status . ' ' . hub_backend_error_summary($payload, 'unknown_error'));
    }

    return ['status' => $status, 'payload' => $payload];
}
