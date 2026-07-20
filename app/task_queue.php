<?php
declare(strict_types=1);

class HubTaskCancelled extends RuntimeException
{
}

function hub_allowed_task_types(): array
{
    return ['demo_task', 'structure_parse', 'docparser_parse', 'docparser_repair_translation', 'pack_job'];
}

function hub_default_task_queues(): array
{
    return ['default', 'gpu', 'ocr', 'reconstruction', 'system'];
}

function hub_is_valid_task_type(string $taskType): bool
{
    return in_array($taskType, hub_allowed_task_types(), true);
}

function hub_is_valid_task_queue(string $queueName): bool
{
    return in_array($queueName, hub_default_task_queues(), true);
}

function hub_enqueue_task(PDO $db, string $taskType, string $queueName, int $priority, array $input, ?int $requestedBy, ?string $requestedIp, array $attributes = []): int
{
    if (!hub_is_valid_task_type($taskType)) {
        throw new InvalidArgumentException('Invalid task type.');
    }
    if (!hub_is_valid_task_queue($queueName)) {
        throw new InvalidArgumentException('Invalid task queue.');
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO tasks
            (task_type, queue_name, priority, input_json, status, requested_by, requested_ip,
             owner_member_id, owner_token_id, requested_mode, pack_id, pack_version, job, runtime_mode, accelerator,
             route_resolved_at, source_artifact_id, source_task_id, retry_of_task_id, created_at, updated_at)
         VALUES
            (:task_type, :queue_name, :priority, :input_json, :status, :requested_by, :requested_ip,
             :owner_member_id, :owner_token_id, :requested_mode, :pack_id, :pack_version, :job, :runtime_mode, :accelerator,
             :route_resolved_at, :source_artifact_id, :source_task_id, :retry_of_task_id, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':task_type' => $taskType,
        ':queue_name' => $queueName,
        ':priority' => $priority,
        ':input_json' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => 'queued',
        ':requested_by' => $requestedBy,
        ':requested_ip' => $requestedIp,
        ':owner_member_id' => $attributes['owner_member_id'] ?? null,
        ':owner_token_id' => $attributes['owner_token_id'] ?? null,
        ':requested_mode' => $attributes['requested_mode'] ?? null,
        ':pack_id' => $attributes['pack_id'] ?? null,
        ':pack_version' => $attributes['pack_version'] ?? null,
        ':job' => $attributes['job'] ?? null,
        ':runtime_mode' => $attributes['runtime_mode'] ?? null,
        ':accelerator' => $attributes['accelerator'] ?? null,
        ':route_resolved_at' => $attributes['route_resolved_at'] ?? null,
        ':source_artifact_id' => $attributes['source_artifact_id'] ?? null,
        ':source_task_id' => $attributes['source_task_id'] ?? null,
        ':retry_of_task_id' => $attributes['retry_of_task_id'] ?? null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_enqueue_owned_pack_job(PDO $db, array $route, array $input, int $ownerMemberId, ?int $ownerTokenId, ?string $requestedIp, array $lineage = []): int
{
    foreach (['requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at'] as $field) {
        if (empty($route[$field])) {
            throw new InvalidArgumentException('Invalid Pack job route.');
        }
    }

    return hub_enqueue_task($db, 'pack_job', 'gpu', 0, $input, null, $requestedIp, $route + [
        'owner_member_id' => $ownerMemberId,
        'owner_token_id' => $ownerTokenId,
        'source_artifact_id' => $lineage['source_artifact_id'] ?? null,
        'source_task_id' => $lineage['source_task_id'] ?? null,
        'retry_of_task_id' => $lineage['retry_of_task_id'] ?? null,
    ]);
}

function hub_validate_pack_job_source_artifact(PDO $db, int $artifactId, int $ownerMemberId, string $job): ?array
{
    $stmt = $db->prepare(
        'SELECT a.*, t.owner_member_id AS task_owner_member_id
         FROM task_artifacts a
         JOIN tasks t ON t.id = a.task_id
         WHERE a.id = :id'
    );
    $stmt->execute([':id' => $artifactId]);
    $artifact = $stmt->fetch();
    if (!$artifact || (int)($artifact['task_owner_member_id'] ?? 0) !== $ownerMemberId) {
        return null;
    }
    if (
        ($artifact['state'] ?? '') !== 'available'
        || !empty($artifact['purged_at'])
        || (!empty($artifact['expires_at']) && (string)$artifact['expires_at'] <= hub_now())
        || !in_array((string)($artifact['artifact_type'] ?? ''), hub_audio_job_input_artifact_types($job), true)
        || hub_artifact_safe_path((string)($artifact['path'] ?? '')) === null
    ) {
        throw new RuntimeException('source_artifact_invalid');
    }

    return $artifact;
}

function hub_managed_task_upload_path(int $taskId, string $path): ?string
{
    $realPath = realpath($path);
    $taskRoot = realpath(HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId);
    if ($realPath === false || $taskRoot === false || !is_file($realPath)) {
        return null;
    }

    return str_starts_with($realPath, $taskRoot . DIRECTORY_SEPARATOR) ? $realPath : null;
}

function hub_create_manual_retry(PDO $db, int $taskId, array $authContext = []): int
{
    $task = hub_get_task($db, $taskId);
    if (!$task || ($task['task_type'] ?? '') !== 'pack_job') {
        throw new InvalidArgumentException('task_not_retryable');
    }
    if (!in_array((string)($task['status'] ?? ''), ['success', 'failed', 'cancelled'], true)) {
        throw new RuntimeException('task_not_terminal');
    }
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    if ($ownerMemberId <= 0 || (!empty($authContext['member_id']) && $ownerMemberId !== (int)$authContext['member_id'])) {
        throw new InvalidArgumentException('task_not_found');
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    unset($input['cancel_requested'], $input['cancel_requested_at']);
    $sourceArtifactId = (int)($task['source_artifact_id'] ?? 0);
    $source = $sourceArtifactId > 0 ? hub_validate_pack_job_source_artifact($db, $sourceArtifactId, $ownerMemberId, (string)$task['job']) : null;
    if ($sourceArtifactId > 0 && $source === null) {
        throw new RuntimeException('source_artifact_invalid');
    }
    if ($sourceArtifactId <= 0 && hub_managed_task_upload_path($taskId, (string)($input['source_upload_path'] ?? '')) === null) {
        throw new RuntimeException('source_upload_invalid');
    }
    $route = array_intersect_key($task, array_flip(['requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at']));

    return hub_enqueue_owned_pack_job($db, $route, $input, $ownerMemberId, !empty($authContext['token_id']) ? (int)$authContext['token_id'] : (int)($task['owner_token_id'] ?? 0), $task['requested_ip'] ?? null, [
        'source_artifact_id' => $sourceArtifactId,
        'source_task_id' => (int)($source['task_id'] ?? 0),
        'retry_of_task_id' => $taskId,
    ]);
}

function hub_update_task_input(PDO $db, int $taskId, array $input): void
{
    $inputJson = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($inputJson === false) {
        throw new RuntimeException('Cannot encode task input.');
    }

    $stmt = $db->prepare('UPDATE tasks SET input_json = :input_json, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':input_json' => $inputJson,
        ':updated_at' => hub_now(),
        ':id' => $taskId,
    ]);
}

function hub_update_task_progress(PDO $db, int $taskId, int $progress): void
{
    $stmt = $db->prepare('UPDATE tasks SET progress = :progress, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':progress' => max(0, min(99, $progress)),
        ':updated_at' => hub_now(),
        ':id' => $taskId,
    ]);
}

function hub_get_task(PDO $db, int $taskId): ?array
{
    $stmt = $db->prepare('SELECT * FROM tasks WHERE id = :id');
    $stmt->execute([':id' => $taskId]);
    $task = $stmt->fetch();
    if (!$task) {
        return null;
    }

    $task['input'] = json_decode((string)($task['input_json'] ?? ''), true) ?: [];
    $task['result'] = json_decode((string)($task['result_json'] ?? ''), true) ?: null;
    return $task;
}

function hub_claim_next_task(PDO $db): ?array
{
    $db->beginTransaction();
    try {
        $task = $db->query(
            "SELECT * FROM tasks
             WHERE status = 'queued' AND lock_token IS NULL
             ORDER BY priority DESC, created_at ASC, id ASC
             LIMIT 1"
        )->fetch();
        if (!$task) {
            $db->commit();
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $now = hub_now();
        $stmt = $db->prepare(
            "UPDATE tasks
             SET status = 'running', lock_token = :lock_token, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND status = 'queued' AND lock_token IS NULL"
        );
        $stmt->execute([
            ':lock_token' => $token,
            ':started_at' => $now,
            ':updated_at' => $now,
            ':id' => (int)$task['id'],
        ]);
        $db->commit();

        return hub_get_task($db, (int)$task['id']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function hub_add_task_log(PDO $db, int $taskId, string $level, string $message): void
{
    $message = hub_prepare_task_log_message($taskId, $message);
    $stmt = $db->prepare(
        'INSERT INTO task_logs (task_id, level, message, created_at)
         VALUES (:task_id, :level, :message, :created_at)'
    );
    $stmt->execute([
        ':task_id' => $taskId,
        ':level' => $level,
        ':message' => $message,
        ':created_at' => hub_now(),
    ]);
    hub_prune_task_log_rows($db, $taskId);
}

function hub_list_task_logs(PDO $db, int $taskId): array
{
    $stmt = $db->prepare('SELECT * FROM task_logs WHERE task_id = :task_id ORDER BY id ASC');
    $stmt->execute([':task_id' => $taskId]);

    return $stmt->fetchAll();
}

function hub_finish_task_success(PDO $db, array $task, array $result): void
{
    hub_finish_task_terminal_result($db, $task, 'success', $result, null);
}

function hub_finish_task_terminal_result(PDO $db, array $task, string $status, array $result, ?string $errorMessage): void
{
    if (!in_array($status, ['success', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid terminal task status.');
    }
    $taskId = (int)$task['id'];
    $resultJson = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($resultJson === false) {
        throw new RuntimeException('Cannot encode task result.');
    }
    if (strlen($resultJson) > hub_max_result_json_bytes($db)) {
        $resultJson = json_encode(hub_store_task_result_artifact($db, $taskId, $resultJson), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($resultJson === false) {
            throw new RuntimeException('Cannot encode task artifact summary.');
        }
    }
    $now = hub_now();
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = :status, progress = 100, result_json = :result_json, error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':status' => $status,
        ':result_json' => $resultJson,
        ':error_message' => $errorMessage,
        ':finished_at' => $now,
        ':updated_at' => $now,
        ':id' => $taskId,
    ]);
}

function hub_max_result_json_bytes(PDO $db): int
{
    return max(1, (int)hub_get_storage_setting($db, 'AIHUB_MAX_RESULT_JSON_BYTES'));
}

function hub_max_task_log_rows(PDO $db): int
{
    return max(1, (int)hub_get_storage_setting($db, 'AIHUB_MAX_TASK_LOG_ROWS'));
}

function hub_store_task_result_artifact(PDO $db, int $taskId, string $resultJson): array
{
    $dir = hub_task_result_dir($taskId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create task result directory.');
    }

    $path = $dir . '/result_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json';
    if (file_put_contents($path, $resultJson, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write task result artifact.');
    }

    $artifactId = hub_register_task_artifact($db, $taskId, basename($path), $path, 'application/json');
    return [
        'stored_as_artifact' => true,
        'artifact_id' => $artifactId,
        'path' => $path,
        'bytes' => strlen($resultJson),
    ];
}

function hub_store_structure_task_artifacts(PDO $db, int $taskId, array $result): array
{
    $dir = hub_task_result_dir($taskId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create task result directory.');
    }

    $summary = [];
    if (array_key_exists('markdown', $result)) {
        $markdownPath = $dir . '/structure_result.md';
        if (file_put_contents($markdownPath, (string)$result['markdown'], LOCK_EX) === false) {
            throw new RuntimeException('Cannot write structure markdown artifact.');
        }
        $summary['markdown'] = [
            'artifact_id' => hub_register_task_artifact($db, $taskId, 'structure_result.md', $markdownPath, 'text/markdown'),
            'bytes' => filesize($markdownPath) ?: 0,
        ];
    }

    if (array_key_exists('document_json', $result)) {
        $json = json_encode($result['document_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Cannot encode structure JSON artifact.');
        }
        $jsonPath = $dir . '/structure_result.json';
        if (file_put_contents($jsonPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write structure JSON artifact.');
        }
        $summary['json'] = [
            'artifact_id' => hub_register_task_artifact($db, $taskId, 'structure_result.json', $jsonPath, 'application/json'),
            'bytes' => filesize($jsonPath) ?: 0,
        ];
    }

    return $summary;
}

function hub_store_docparser_task_artifacts(PDO $db, int $taskId, array $result): array
{
    $base = hub_task_result_dir($taskId) . '/docparser';
    foreach ([$base, $base . '/exports', $base . '/normalized', $base . '/assets/figures'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create DocParser artifact directory.');
        }
    }

    $encode = static function ($value, string $label): string {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Cannot encode DocParser artifact: ' . $label);
        }

        return $json . "\n";
    };

    $docir = is_array($result['docir'] ?? null) ? $result['docir'] : [];
    $figureAssets = [];
    foreach (($docir['figures'] ?? []) as $index => $figure) {
        if (!is_array($figure)) {
            continue;
        }
        $relative = (string)($figure['asset_path'] ?? '');
        if ($relative === '' || str_contains($relative, '..')) {
            continue;
        }
        $path = $base . '/' . ltrim($relative, '/');
        if (!is_file($path)) {
            continue;
        }
        $artifactId = hub_register_task_artifact($db, $taskId, 'docparser/' . ltrim($relative, '/'), $path, 'image/png');
        $docir['figures'][$index]['artifact_id'] = $artifactId;
        $figureAssets[] = [
            'figure_id' => (string)($figure['id'] ?? ''),
            'block_id' => (string)($figure['block_id'] ?? ''),
            'page' => (int)($figure['page'] ?? 0),
            'bbox' => is_array($figure['bbox'] ?? null) ? $figure['bbox'] : [],
            'caption' => (string)($figure['caption'] ?? ''),
            'asset_path' => $relative,
            'artifact_id' => $artifactId,
            'bytes' => filesize($path) ?: 0,
        ];
    }

    $files = [
        'manifest' => ['manifest.json', $encode($result['manifest'] ?? [], 'manifest'), 'application/json'],
        'reader_html' => ['exports/index.zh-TW.html', (string)($result['reader_html'] ?? ''), 'text/html'],
        'bilingual_html' => ['exports/index.bilingual.html', (string)($result['bilingual_html'] ?? ''), 'text/html'],
        'markdown' => ['exports/document.zh-TW.md', (string)($result['markdown'] ?? ''), 'text/markdown'],
        'docir' => ['normalized/docir-v0.1.json', $encode($docir, 'docir'), 'application/json'],
        'toc' => ['normalized/toc.json', $encode($result['toc'] ?? [], 'toc'), 'application/json'],
        'rag_chunks' => ['exports/rag_chunks.json', $encode($result['rag_chunks'] ?? [], 'rag_chunks'), 'application/json'],
        'quality_report' => ['exports/quality-report.json', $encode($result['quality_report'] ?? [], 'quality_report'), 'application/json'],
    ];

    $summary = [];
    foreach ($files as $key => [$relative, $content, $mime]) {
        $path = $base . '/' . $relative;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write DocParser artifact: ' . $relative);
        }
        $summary[$key] = [
            'artifact_id' => hub_register_task_artifact($db, $taskId, 'docparser/' . $relative, $path, $mime),
            'path' => $path,
            'bytes' => strlen($content),
        ];
    }

    if ($figureAssets !== []) {
        $summary['figure_assets'] = [
            'count' => count($figureAssets),
            'bytes' => array_sum(array_column($figureAssets, 'bytes')),
            'items' => $figureAssets,
        ];
    }

    return $summary;
}

function hub_prepare_task_log_message(int $taskId, string $message): string
{
    if (strlen($message) <= 4096) {
        return $message;
    }

    if (!is_dir(HUB_TASK_LOG_DIR) && !mkdir(HUB_TASK_LOG_DIR, 0775, true) && !is_dir(HUB_TASK_LOG_DIR)) {
        throw new RuntimeException('Cannot create task log directory.');
    }

    $relativePath = 'data/logs/tasks/task_' . $taskId . '.log';
    $path = HUB_ROOT . '/' . $relativePath;
    $line = '[' . hub_now() . '] ' . $message . PHP_EOL;
    if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot write task log file.');
    }

    $excerpt = preg_replace('/\s+/', ' ', substr($message, 0, 240)) ?: '';
    return 'log_path=' . $relativePath . ' excerpt=' . $excerpt;
}

function hub_prune_task_log_rows(PDO $db, int $taskId): void
{
    $limit = hub_max_task_log_rows($db);
    $stmt = $db->prepare(
        'DELETE FROM task_logs
         WHERE task_id = :task_id
           AND id NOT IN (
               SELECT id FROM task_logs
               WHERE task_id = :keep_task_id
               ORDER BY id DESC
               LIMIT :keep_limit
           )'
    );
    $stmt->bindValue(':task_id', $taskId, PDO::PARAM_INT);
    $stmt->bindValue(':keep_task_id', $taskId, PDO::PARAM_INT);
    $stmt->bindValue(':keep_limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
}

function hub_finish_task_failed(PDO $db, array $task, string $errorMessage): void
{
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'failed', error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':error_message' => $errorMessage,
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => (int)$task['id'],
    ]);
}

function hub_finish_task_cancelled(PDO $db, array $task, string $message = 'cancelled'): void
{
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'cancelled', error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':error_message' => $message,
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => (int)$task['id'],
    ]);
}

function hub_cancel_task(PDO $db, int $taskId): bool
{
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'cancelled', finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id AND status = 'queued'"
    );
    $stmt->execute([
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => $taskId,
    ]);

    if ($stmt->rowCount() === 1) {
        return true;
    }

    $task = hub_get_task($db, $taskId);
    if (!$task || ($task['status'] ?? '') !== 'running' || ($task['task_type'] ?? '') !== 'docparser_parse') {
        return false;
    }

    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    $input['cancel_requested'] = '1';
    $input['cancel_requested_at'] = hub_now();
    hub_update_task_input($db, $taskId, $input);
    hub_add_task_log($db, $taskId, 'warning', 'cancel_requested');

    return true;
}

function hub_task_cancel_requested(PDO $db, int $taskId): bool
{
    $task = hub_get_task($db, $taskId);

    return $task !== null
        && ($task['status'] ?? '') === 'running'
        && ($task['task_type'] ?? '') === 'docparser_parse'
        && (string)($task['input']['cancel_requested'] ?? '') === '1';
}

function hub_abort_if_task_cancel_requested(PDO $db, int $taskId): void
{
    if (hub_task_cancel_requested($db, $taskId)) {
        throw new HubTaskCancelled('cancel_requested');
    }
}

function hub_task_result_dir(int $taskId): string
{
    return HUB_DATA_DIR . '/results/task_' . $taskId;
}

function hub_register_task_artifact(PDO $db, int $taskId, string $name, string $path, string $mimeType): int
{
    $safePath = hub_artifact_safe_path($path);
    if ($safePath === null) {
        throw new InvalidArgumentException('Invalid artifact path.');
    }

    $stmt = $db->prepare(
        'INSERT INTO task_artifacts (task_id, name, path, mime_type, size_bytes, created_at)
         VALUES (:task_id, :name, :path, :mime_type, :size_bytes, :created_at)'
    );
    $stmt->execute([
        ':task_id' => $taskId,
        ':name' => $name,
        ':path' => $safePath,
        ':mime_type' => $mimeType,
        ':size_bytes' => is_file($safePath) ? filesize($safePath) : 0,
        ':created_at' => hub_now(),
    ]);

    return (int)$db->lastInsertId();
}

function hub_get_task_artifact(PDO $db, int $artifactId): ?array
{
    $stmt = $db->prepare('SELECT * FROM task_artifacts WHERE id = :id');
    $stmt->execute([':id' => $artifactId]);
    $artifact = $stmt->fetch();

    return $artifact ?: null;
}

function hub_artifact_safe_path(string $path): ?string
{
    $realPath = realpath($path);
    $resultsRoot = realpath(HUB_DATA_DIR . '/results');
    if ($realPath === false || $resultsRoot === false || !is_file($realPath)) {
        return null;
    }

    return str_starts_with($realPath, $resultsRoot . DIRECTORY_SEPARATOR) ? $realPath : null;
}
