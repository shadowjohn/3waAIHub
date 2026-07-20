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
    $status = (string)($attributes['status'] ?? 'queued');
    if (!in_array($status, ['queued', 'staging'], true)) {
        throw new InvalidArgumentException('Invalid initial task status.');
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO tasks
            (task_type, queue_name, priority, input_json, status, requested_by, requested_ip,
             owner_member_id, owner_token_id, requested_mode, pack_id, pack_version, job, runtime_mode, accelerator,
             route_resolved_at, source_artifact_id, source_task_id, retry_of_task_id, callback_target_id, created_at, updated_at)
         VALUES
            (:task_type, :queue_name, :priority, :input_json, :status, :requested_by, :requested_ip,
             :owner_member_id, :owner_token_id, :requested_mode, :pack_id, :pack_version, :job, :runtime_mode, :accelerator,
             :route_resolved_at, :source_artifact_id, :source_task_id, :retry_of_task_id, :callback_target_id, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':task_type' => $taskType,
        ':queue_name' => $queueName,
        ':priority' => $priority,
        ':input_json' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => $status,
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
        ':callback_target_id' => $attributes['callback_target_id'] ?? null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_enqueue_owned_pack_job(PDO $db, array $route, array $input, int $ownerMemberId, ?int $ownerTokenId, ?string $requestedIp, array $lineage = [], string $status = 'queued'): int
{
    foreach (['requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at'] as $field) {
        if (empty($route[$field])) {
            throw new InvalidArgumentException('Invalid Pack job route.');
        }
    }

    $sourceArtifactId = (int)($lineage['source_artifact_id'] ?? 0);
    $startedTransaction = false;
    if ($sourceArtifactId > 0 && !$db->inTransaction()) {
        $db->beginTransaction();
        $startedTransaction = true;
    }
    try {
        $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, $input, null, $requestedIp, $route + [
            'owner_member_id' => $ownerMemberId,
            'owner_token_id' => $ownerTokenId,
            'source_artifact_id' => $sourceArtifactId > 0 ? $sourceArtifactId : null,
            'source_task_id' => $lineage['source_task_id'] ?? null,
            'retry_of_task_id' => $lineage['retry_of_task_id'] ?? null,
            'callback_target_id' => $lineage['callback_target_id'] ?? null,
            'status' => $status,
        ]);
        if ($sourceArtifactId > 0) {
            hub_hold_task_source_artifact($db, $sourceArtifactId, $taskId);
        }
        if ($startedTransaction) {
            $db->commit();
        }

        return $taskId;
    } catch (Throwable $e) {
        if ($startedTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_stage_owned_pack_job(PDO $db, array $route, array $input, int $ownerMemberId, ?int $ownerTokenId, ?string $requestedIp, array $lineage = []): int
{
    return hub_enqueue_owned_pack_job($db, $route, $input, $ownerMemberId, $ownerTokenId, $requestedIp, $lineage, 'staging');
}

function hub_publish_staged_pack_job(PDO $db, int $taskId): void
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "UPDATE tasks
             SET status = 'queued', updated_at = :updated_at
             WHERE id = :id AND task_type = 'pack_job' AND status = 'staging'"
        );
        $stmt->execute([':updated_at' => hub_now(), ':id' => $taskId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('task_not_staged');
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_hold_task_source_artifact(PDO $db, int $artifactId, int $downstreamTaskId): void
{
    $task = hub_get_task($db, $downstreamTaskId);
    if (!$task || (int)($task['source_artifact_id'] ?? 0) !== $artifactId) {
        throw new RuntimeException('source_artifact_hold_invalid');
    }
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO task_artifact_holds (source_artifact_id, downstream_task_id, held_at)
         VALUES (:source_artifact_id, :downstream_task_id, :held_at)'
    );
    $stmt->execute([
        ':source_artifact_id' => $artifactId,
        ':downstream_task_id' => $downstreamTaskId,
        ':held_at' => hub_now(),
    ]);
}

function hub_validate_pack_job_source_artifact(PDO $db, int $artifactId, int $ownerMemberId, array $route): ?array
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
        || !in_array((string)($artifact['artifact_type'] ?? ''), (array)($route['source_artifact_types'] ?? []), true)
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
    if (!in_array((string)($task['status'] ?? ''), ['success', 'failed', 'cancelled', 'timed_out'], true)) {
        throw new RuntimeException('task_not_terminal');
    }
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    if ($ownerMemberId <= 0 || (!empty($authContext['member_id']) && $ownerMemberId !== (int)$authContext['member_id'])) {
        throw new InvalidArgumentException('task_not_found');
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    unset($input['cancel_requested'], $input['cancel_requested_at']);
    $route = hub_revalidate_audio_async_route($db, $task);
    $sourceArtifactId = (int)($task['source_artifact_id'] ?? 0);
    $source = $sourceArtifactId > 0 ? hub_validate_pack_job_source_artifact($db, $sourceArtifactId, $ownerMemberId, $route) : null;
    if ($sourceArtifactId > 0 && $source === null) {
        throw new RuntimeException('source_artifact_invalid');
    }
    if ($sourceArtifactId <= 0 && hub_managed_task_upload_path($taskId, (string)($input['source_upload_path'] ?? '')) === null) {
        throw new RuntimeException('source_upload_invalid');
    }
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

function hub_claim_next_task(PDO $db, ?array $supportedTaskTypes = null): ?array
{
    $taskTypes = $supportedTaskTypes ?? hub_allowed_task_types();
    foreach ($taskTypes as $taskType) {
        if (!is_string($taskType) || !hub_is_valid_task_type($taskType)) {
            throw new InvalidArgumentException('Invalid supported task type.');
        }
    }
    $taskTypes = array_values(array_unique($taskTypes));
    if ($taskTypes === []) {
        return null;
    }

    $db->beginTransaction();
    try {
        $placeholders = implode(', ', array_fill(0, count($taskTypes), '?'));
        $stmt = $db->prepare(
            "SELECT * FROM tasks
             WHERE status = 'queued' AND lock_token IS NULL AND task_type IN ({$placeholders})
             ORDER BY priority DESC, created_at ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute($taskTypes);
        $task = $stmt->fetch();
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
    hub_release_task_artifact_holds($db, $taskId);
}

function hub_release_task_artifact_holds(PDO $db, int $taskId): void
{
    $stmt = $db->prepare(
        'UPDATE task_artifact_holds
         SET released_at = :released_at
         WHERE downstream_task_id = :downstream_task_id AND released_at IS NULL'
    );
    $stmt->execute([
        ':released_at' => hub_now(),
        ':downstream_task_id' => $taskId,
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
    hub_release_task_artifact_holds($db, (int)$task['id']);
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
    hub_release_task_artifact_holds($db, (int)$task['id']);
}

function hub_finish_task_timed_out(PDO $db, array $task, string $message = 'timed out'): void
{
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'timed_out', error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':error_message' => $message,
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => (int)$task['id'],
    ]);
    hub_release_task_artifact_holds($db, (int)$task['id']);
}

function hub_cancel_task(PDO $db, int $taskId): bool
{
    $task = hub_get_task($db, $taskId);
    if ($task && ($task['task_type'] ?? '') === 'pack_job' && ($task['status'] ?? '') === 'queued') {
        // A queued Pack job never started a runner, container, or GPU PID.
        $noWorkCleanup = ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
        if (!hub_pack_job_cleanup_attested($noWorkCleanup)) {
            throw new LogicException('pack_job_cleanup_incomplete');
        }
        if ($db->inTransaction()) {
            throw new LogicException('pack_job_terminal_transaction_required');
        }
        $db->beginTransaction();
        try {
            $now = hub_now();
            $stmt = $db->prepare(
                "UPDATE tasks
                 SET status = 'cancelled', progress = 100, error_code = 'cancelled', error_message = 'cancelled',
                     finished_at = :finished_at, updated_at = :updated_at
                 WHERE id = :id AND task_type = 'pack_job' AND status = 'queued' AND lock_token IS NULL"
            );
            $stmt->execute([':finished_at' => $now, ':updated_at' => $now, ':id' => $taskId]);
            if ($stmt->rowCount() !== 1) {
                $db->commit();
                return false;
            }
            hub_release_task_artifact_holds($db, $taskId);
            hub_enqueue_task_callback_delivery($db, $taskId);
            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

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
        hub_release_task_artifact_holds($db, $taskId);
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

final class HubPackOutputContractInvalid extends RuntimeException
{
}

function hub_pack_job_output_contract_invalid(string $reason): never
{
    throw new HubPackOutputContractInvalid($reason);
}

function hub_pack_job_artifact_relative_path(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 240 || str_contains($value, "\0")) {
        hub_pack_job_output_contract_invalid('artifact_path_invalid');
    }
    $value = str_replace('\\', '/', $value);
    if (str_starts_with($value, '/') || preg_match('#(^|/)\.{1,2}(/|$)#', $value)) {
        hub_pack_job_output_contract_invalid('artifact_path_invalid');
    }

    return $value;
}

function hub_pack_job_artifact_mime_types(mixed $values): array
{
    if (!is_array($values) || !array_is_list($values) || $values === []) {
        hub_pack_job_output_contract_invalid('artifact_mime_invalid');
    }
    $types = [];
    foreach ($values as $value) {
        if (!is_string($value) || preg_match('/^[a-z0-9.+-]{1,64}\/[a-z0-9.+-]{1,64}$/i', $value) !== 1) {
            hub_pack_job_output_contract_invalid('artifact_mime_invalid');
        }
        $types[strtolower($value)] = true;
    }

    return array_keys($types);
}

function hub_pack_job_contract_artifacts(array $jobContract): array
{
    $artifacts = $jobContract['artifacts'] ?? null;
    if (!is_array($artifacts) || !array_is_list($artifacts) || $artifacts === []) {
        hub_pack_job_output_contract_invalid('artifact_contract_invalid');
    }
    $types = [];
    $paths = [];
    foreach ($artifacts as $definition) {
        if (!is_array($definition) || array_diff(array_keys($definition), ['type', 'path', 'mime_types', 'required', 'when', 'json', 'text', 'audio']) !== []) {
            hub_pack_job_output_contract_invalid('artifact_contract_invalid');
        }
        $type = (string)($definition['type'] ?? '');
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $type) !== 1 || isset($types[$type])) {
            hub_pack_job_output_contract_invalid('artifact_type_invalid');
        }
        $path = hub_pack_job_artifact_relative_path($definition['path'] ?? null);
        if (isset($paths[$path])) {
            hub_pack_job_output_contract_invalid('artifact_path_duplicate');
        }
        if (isset($definition['required']) && !is_bool($definition['required'])) {
            hub_pack_job_output_contract_invalid('artifact_required_invalid');
        }
        if (isset($definition['when'])) {
            $when = $definition['when'];
            if (!is_array($when) || array_keys($when) !== ['input', 'equals'] || !is_string($when['input']) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $when['input']) !== 1 || is_array($when['equals']) || is_object($when['equals'])) {
                hub_pack_job_output_contract_invalid('artifact_condition_invalid');
            }
        }
        foreach (['json', 'text', 'audio'] as $validator) {
            if (isset($definition[$validator]) && !is_array($definition[$validator])) {
                hub_pack_job_output_contract_invalid('artifact_validator_invalid');
            }
        }
        if (isset($definition['json'])) {
            $keys = $definition['json']['required_keys'] ?? [];
            if (array_diff(array_keys($definition['json']), ['required_keys']) !== [] || !is_array($keys) || !array_is_list($keys)) {
                hub_pack_job_output_contract_invalid('artifact_json_contract_invalid');
            }
            foreach ($keys as $key) {
                if (!is_string($key) || preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/', $key) !== 1) {
                    hub_pack_job_output_contract_invalid('artifact_json_contract_invalid');
                }
            }
        }
        if (isset($definition['text'])) {
            $maxBytes = $definition['text']['max_bytes'] ?? 1048576;
            if (array_diff(array_keys($definition['text']), ['max_bytes']) !== [] || !is_int($maxBytes) || $maxBytes < 1 || $maxBytes > 10485760) {
                hub_pack_job_output_contract_invalid('artifact_text_contract_invalid');
            }
        }
        if (isset($definition['audio']) && $definition['audio'] !== []) {
            hub_pack_job_output_contract_invalid('artifact_audio_contract_invalid');
        }
        $definition['type'] = $type;
        $definition['path'] = $path;
        $definition['mime_types'] = hub_pack_job_artifact_mime_types($definition['mime_types'] ?? null);
        $types[$type] = true;
        $paths[$path] = true;
        $normalized[] = $definition;
    }

    return $normalized ?? [];
}

function hub_pack_job_artifact_is_expected(array $definition, array $input): bool
{
    if (isset($definition['when'])) {
        return ($definition['required'] ?? true) === true
            && array_key_exists($definition['when']['input'], $input)
            && $input[$definition['when']['input']] === $definition['when']['equals'];
    }

    return ($definition['required'] ?? true) === true;
}

function hub_pack_job_output_dir(string $workspace): string
{
    clearstatcache(true, $workspace);
    if ($workspace === '' || is_link($workspace) || !is_dir($workspace)) {
        hub_pack_job_output_contract_invalid('workspace_invalid');
    }
    $workspace = realpath($workspace);
    $output = $workspace === false ? false : $workspace . '/output';
    if (is_string($output)) {
        clearstatcache(true, $output);
    }
    if ($output === false || is_link($output) || !is_dir($output)) {
        hub_pack_job_output_contract_invalid('output_dir_invalid');
    }
    $output = realpath($output);
    if ($output === false || !str_starts_with($output, $workspace . DIRECTORY_SEPARATOR)) {
        hub_pack_job_output_contract_invalid('output_dir_invalid');
    }

    return $output;
}

function hub_pack_job_output_files(string $outputDir): array
{
    $files = [];
    try {
        clearstatcache(true, $outputDir);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($outputDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            clearstatcache(true, $path);
            $relative = substr($path, strlen($outputDir) + 1);
            if ($relative === false || $relative === '' || is_link($path)) {
                hub_pack_job_output_contract_invalid('artifact_symlink_invalid');
            }
            $stat = lstat($path);
            $kind = is_array($stat) ? ((int)$stat['mode'] & 0170000) : 0;
            if ($kind === 0040000) {
                continue;
            }
            if ($kind !== 0100000) {
                hub_pack_job_output_contract_invalid('artifact_nonregular');
            }
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $relative)] = $path;
        }
    } catch (UnexpectedValueException) {
        hub_pack_job_output_contract_invalid('output_dir_invalid');
    }

    return $files;
}

function hub_pack_job_detect_mime(string $path): string
{
    try {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    } catch (Throwable) {
        $mime = false;
    }

    return is_string($mime) && preg_match('/^[a-z0-9.+-]{1,64}\/[a-z0-9.+-]{1,64}$/i', $mime) === 1
        ? strtolower($mime)
        : 'application/octet-stream';
}

function hub_pack_job_validate_json_output(string $path, array $definition): void
{
    if (!isset($definition['json'])) {
        return;
    }
    try {
        $decoded = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        hub_pack_job_output_contract_invalid('artifact_json_invalid');
    }
    if (!is_array($decoded) || array_is_list($decoded)) {
        hub_pack_job_output_contract_invalid('artifact_json_invalid');
    }
    foreach ($definition['json']['required_keys'] ?? [] as $key) {
        if (!array_key_exists($key, $decoded)) {
            hub_pack_job_output_contract_invalid('artifact_json_invalid');
        }
    }
}

function hub_pack_job_validate_text_output(string $path, array $definition, int $size): void
{
    if (!isset($definition['text'])) {
        return;
    }
    $contents = file_get_contents($path);
    $maxBytes = (int)($definition['text']['max_bytes'] ?? 1048576);
    if (!is_string($contents) || $contents === '' || $size > $maxBytes || preg_match('//u', $contents) !== 1) {
        hub_pack_job_output_contract_invalid('artifact_text_invalid');
    }
}

function hub_pack_job_ffprobe(string $path): ?array
{
    if (!function_exists('exec')) {
        return null;
    }
    $output = [];
    $exitCode = 1;
    exec('ffprobe -v error -show_entries format=duration:stream=codec_type,sample_rate,channels -of json ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        return null;
    }
    try {
        $result = json_decode(implode("\n", $output), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    $stream = null;
    foreach ((array)($result['streams'] ?? []) as $candidate) {
        if (is_array($candidate) && ($candidate['codec_type'] ?? '') === 'audio') {
            $stream = $candidate;
            break;
        }
    }
    if ($stream === null) {
        return null;
    }

    return [
        'duration_seconds' => $result['format']['duration'] ?? null,
        'sample_rate' => $stream['sample_rate'] ?? null,
        'channels' => $stream['channels'] ?? null,
    ];
}

function hub_pack_job_validate_audio_output(string $path, array $definition, ?callable $audioProbe): array
{
    if (!isset($definition['audio'])) {
        return [];
    }
    $probe = $audioProbe ?? 'hub_pack_job_ffprobe';
    try {
        $result = $probe($path);
    } catch (Throwable) {
        $result = null;
    }
    $duration = is_array($result) ? $result['duration_seconds'] ?? null : null;
    $sampleRate = is_array($result) ? $result['sample_rate'] ?? null : null;
    $channels = is_array($result) ? $result['channels'] ?? null : null;
    if (!is_numeric($duration) || (float)$duration <= 0 || !is_numeric($sampleRate) || (int)$sampleRate <= 0 || !is_numeric($channels) || (int)$channels <= 0) {
        hub_pack_job_output_contract_invalid('artifact_audio_invalid');
    }

    return [
        'duration_seconds' => (float)$duration,
        'sample_rate' => (int)$sampleRate,
        'channels' => (int)$channels,
    ];
}

function hub_validate_pack_job_artifacts(string $workspace, array $taskInput, array $jobContract, ?callable $audioProbe = null): array
{
    $outputDir = hub_pack_job_output_dir($workspace);
    $expected = [];
    foreach (hub_pack_job_contract_artifacts($jobContract) as $definition) {
        if (hub_pack_job_artifact_is_expected($definition, $taskInput)) {
            $expected[$definition['path']] = $definition;
        }
    }
    $files = hub_pack_job_output_files($outputDir);
    if (array_diff_key($expected, $files) !== [] || array_diff_key($files, $expected) !== []) {
        hub_pack_job_output_contract_invalid('artifact_set_invalid');
    }

    $validated = [];
    foreach ($expected as $relative => $definition) {
        clearstatcache(true, $files[$relative]);
        $path = realpath($files[$relative]);
        if ($path === false || !str_starts_with($path, $outputDir . DIRECTORY_SEPARATOR) || is_link($path)) {
            hub_pack_job_output_contract_invalid('artifact_path_invalid');
        }
        $stat = lstat($path);
        if (!is_array($stat) || (((int)$stat['mode'] & 0170000) !== 0100000) || (int)($stat['nlink'] ?? 0) !== 1) {
            hub_pack_job_output_contract_invalid('artifact_nonregular');
        }
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $mime = hub_pack_job_detect_mime($path);
        if ($size === false || $size < 0 || $sha256 === false || !in_array($mime, $definition['mime_types'], true)) {
            hub_pack_job_output_contract_invalid('artifact_metadata_invalid');
        }
        hub_pack_job_validate_json_output($path, $definition);
        hub_pack_job_validate_text_output($path, $definition, (int)$size);
        $validated[] = [
            'name' => $relative,
            'artifact_type' => $definition['type'],
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int)$size,
            'sha256' => strtolower($sha256),
            'metadata' => hub_pack_job_validate_audio_output($path, $definition, $audioProbe),
            'device' => (int)$stat['dev'],
            'inode' => (int)$stat['ino'],
        ];
    }

    return $validated;
}

function hub_pack_job_cleanup_attested(array $cleanup): bool
{
    foreach (['runner_exited', 'container_removed', 'owned_gpu_pids_gone'] as $field) {
        if (($cleanup[$field] ?? false) !== true) {
            return false;
        }
    }

    return true;
}

function hub_pack_job_trusted_output_dir(PDO $db, int $taskId, ?array $run): string
{
    if ($run === null) {
        throw new InvalidArgumentException('runtime_fence_required');
    }
    $runId = (int)($run['id'] ?? 0);
    $leaseToken = (string)($run['lease_token'] ?? '');
    if ($runId <= 0 || $leaseToken === '') {
        throw new InvalidArgumentException('runtime_fence_invalid');
    }
    $stmt = $db->prepare(
        "SELECT workspace FROM runtime_runs
         WHERE id = :id AND lease_token = :lease_token AND task_id = :task_id AND state IN ('claimed', 'running')"
    );
    $stmt->execute([':id' => $runId, ':lease_token' => $leaseToken, ':task_id' => $taskId]);
    $workspace = $stmt->fetchColumn();
    if (!is_string($workspace) || $workspace === '') {
        throw new RuntimeException('runtime_ownership_conflict');
    }
    $taskResultDir = hub_task_result_dir($taskId);
    clearstatcache(true, $taskResultDir);
    clearstatcache(true, $workspace);
    $taskRoot = realpath($taskResultDir);
    $workspaceReal = is_link($workspace) ? false : realpath($workspace);
    if ($taskRoot === false || $workspaceReal === false || !str_starts_with($workspaceReal, $taskRoot . DIRECTORY_SEPARATOR)) {
        hub_pack_job_output_contract_invalid('workspace_invalid');
    }

    return hub_pack_job_output_dir($workspaceReal);
}

function hub_pack_job_require_submitted_output_dir(PDO $db, int $taskId, ?array $run, string $workspace): string
{
    $trustedOutputDir = hub_pack_job_trusted_output_dir($db, $taskId, $run);
    if (hub_pack_job_output_dir($workspace) !== $trustedOutputDir) {
        hub_pack_job_output_contract_invalid('workspace_invalid');
    }

    return $trustedOutputDir;
}

function hub_revalidate_pack_job_artifact_snapshot(PDO $db, int $taskId, ?array $run, array $validatedArtifacts): void
{
    $outputDir = hub_pack_job_trusted_output_dir($db, $taskId, $run);
    $files = hub_pack_job_output_files($outputDir);
    $expected = [];
    foreach ($validatedArtifacts as $artifact) {
        if (!is_string($artifact['name'] ?? null) || isset($expected[$artifact['name']])) {
            hub_pack_job_output_contract_invalid('artifact_changed');
        }
        $expected[$artifact['name']] = true;
    }
    if (array_diff_key($files, $expected) !== [] || array_diff_key($expected, $files) !== []) {
        hub_pack_job_output_contract_invalid('artifact_changed');
    }
    foreach ($validatedArtifacts as $artifact) {
        $name = is_string($artifact['name'] ?? null) ? $artifact['name'] : '';
        clearstatcache(true, $outputDir . '/' . $name);
        $path = $name === '' ? false : realpath($outputDir . '/' . $name);
        if ($path === false || $path !== ($artifact['path'] ?? null) || !str_starts_with($path, $outputDir . DIRECTORY_SEPARATOR) || is_link($path)) {
            hub_pack_job_output_contract_invalid('artifact_changed');
        }
        $stat = lstat($path);
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_array($stat) || (((int)$stat['mode'] & 0170000) !== 0100000) || (int)($stat['nlink'] ?? 0) !== 1
            || (int)$stat['dev'] !== (int)($artifact['device'] ?? -1) || (int)$stat['ino'] !== (int)($artifact['inode'] ?? -1)
            || $size === false || (int)$size !== (int)($artifact['size_bytes'] ?? -1) || $sha256 === false || !hash_equals((string)($artifact['sha256'] ?? ''), strtolower($sha256))
            || hub_pack_job_detect_mime($path) !== ($artifact['mime_type'] ?? null)) {
            hub_pack_job_output_contract_invalid('artifact_changed');
        }
    }
}

function hub_pack_job_terminal_fence(PDO $db, ?array $run, int $taskId, string $state, ?string $errorCode): void
{
    if ($run === null) {
        throw new InvalidArgumentException('runtime_fence_required');
    }
    $runId = (int)($run['id'] ?? 0);
    $leaseToken = (string)($run['lease_token'] ?? '');
    if ($runId <= 0 || $leaseToken === '') {
        throw new InvalidArgumentException('runtime_fence_invalid');
    }
    $now = hub_now();
    $extra = ' AND task_id = :task_id';
    if ($state === 'succeeded') {
        $extra .= ' AND cancel_requested_at IS NULL';
    }
    if ($state === 'cancelled') {
        $extra .= ' AND cancel_requested_at IS NOT NULL';
    }
    if ($state === 'timed_out') {
        $extra .= ' AND cancel_requested_at IS NULL AND timeout_at IS NOT NULL AND timeout_at <= :now';
    }
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = :state, finished_at = :finished_at, error_code = :error_code, lease_expires_at = NULL,
             cancelled_at = CASE WHEN :state = 'cancelled' THEN :finished_at ELSE cancelled_at END
         WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')
           {$extra}"
    );
    $params = [
        ':state' => $state,
        ':finished_at' => $now,
        ':error_code' => $errorCode,
        ':id' => $runId,
        ':lease_token' => $leaseToken,
        ':task_id' => $taskId,
    ];
    if ($state === 'timed_out') {
        $params[':now'] = $now;
    }
    $stmt->execute($params);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('runtime_ownership_conflict');
    }
}

function hub_register_validated_pack_job_artifact(PDO $db, int $taskId, array $artifact): int
{
    $metadata = json_encode($artifact['metadata'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($artifact['name'] ?? null) || !is_string($artifact['artifact_type'] ?? null) || !is_string($artifact['path'] ?? null)
        || !is_string($artifact['mime_type'] ?? null) || !is_string($artifact['sha256'] ?? null) || $metadata === false
        || !is_int($artifact['size_bytes'] ?? null) || $artifact['size_bytes'] < 0) {
        throw new InvalidArgumentException('validated_artifact_invalid');
    }
    $stmt = $db->prepare(
        'INSERT INTO task_artifacts
            (task_id, name, path, artifact_type, mime_type, size_bytes, sha256, metadata_json, created_at)
         VALUES
            (:task_id, :name, :path, :artifact_type, :mime_type, :size_bytes, :sha256, :metadata_json, :created_at)'
    );
    $stmt->execute([
        ':task_id' => $taskId,
        ':name' => $artifact['name'],
        ':path' => $artifact['path'],
        ':artifact_type' => $artifact['artifact_type'],
        ':mime_type' => $artifact['mime_type'],
        ':size_bytes' => $artifact['size_bytes'],
        ':sha256' => $artifact['sha256'],
        ':metadata_json' => $metadata,
        ':created_at' => hub_now(),
    ]);

    return (int)$db->lastInsertId();
}

function hub_pack_job_mark_task_terminal(PDO $db, int $taskId, string $status, ?string $errorCode, string $errorMessage, array $result = []): void
{
    $resultJson = $result === [] ? null : json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($resultJson === false) {
        throw new RuntimeException('task_result_encode_failed');
    }
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = :status, progress = 100, result_json = :result_json, error_code = :error_code,
             error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id AND task_type = 'pack_job' AND status = 'running'"
    );
    $now = hub_now();
    $stmt->execute([
        ':status' => $status,
        ':result_json' => $resultJson,
        ':error_code' => $errorCode,
        ':error_message' => $errorMessage === '' ? null : $errorMessage,
        ':finished_at' => $now,
        ':updated_at' => $now,
        ':id' => $taskId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('task_ownership_conflict');
    }
}

function hub_commit_pack_job_success(PDO $db, int $taskId, ?array $run, array $validatedArtifacts, array $cleanup): void
{
    // Task5 releases any GPU resource lease after this terminal transaction commits.
    if (!hub_pack_job_cleanup_attested($cleanup)) {
        hub_commit_pack_job_failure($db, $taskId, $run, 'failed', 'cleanup_failed', 'Pack cleanup was not attested', $cleanup);
        return;
    }
    try {
        hub_revalidate_pack_job_artifact_snapshot($db, $taskId, $run, $validatedArtifacts);
    } catch (HubPackOutputContractInvalid) {
        hub_commit_pack_job_failure($db, $taskId, $run, 'failed', 'output_contract_invalid', 'Pack output contract validation failed', $cleanup);
        return;
    }
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_terminal_transaction_required');
    }
    $db->beginTransaction();
    try {
        hub_pack_job_terminal_fence($db, $run, $taskId, 'succeeded', null);
        $resultArtifacts = [];
        foreach ($validatedArtifacts as $artifact) {
            $resultArtifacts[] = [
                'id' => hub_register_validated_pack_job_artifact($db, $taskId, $artifact),
                'type' => $artifact['artifact_type'] ?? null,
            ];
        }
        if ($resultArtifacts === []) {
            throw new InvalidArgumentException('validated_artifacts_required');
        }
        hub_pack_job_mark_task_terminal($db, $taskId, 'success', null, '', ['artifacts' => $resultArtifacts]);
        hub_release_task_artifact_holds($db, $taskId);
        hub_enqueue_task_callback_delivery($db, $taskId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_commit_pack_job_failure(PDO $db, int $taskId, ?array $run, string $status, string $errorCode, string $errorMessage, array $cleanup = []): void
{
    if (!in_array($status, ['failed', 'cancelled', 'timed_out'], true) || preg_match('/^[a-z0-9_:-]{1,120}$/i', $errorCode) !== 1) {
        throw new InvalidArgumentException('pack_job_terminal_invalid');
    }
    if (!hub_pack_job_cleanup_attested($cleanup)) {
        $status = 'failed';
        $errorCode = 'cleanup_failed';
        $errorMessage = 'Pack cleanup was not attested';
    }
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_terminal_transaction_required');
    }
    $db->beginTransaction();
    try {
        hub_pack_job_terminal_fence($db, $run, $taskId, $status === 'failed' ? 'failed' : $status, $errorCode);
        hub_pack_job_mark_task_terminal($db, $taskId, $status, $errorCode, substr($errorMessage, 0, 2048));
        hub_release_task_artifact_holds($db, $taskId);
        hub_enqueue_task_callback_delivery($db, $taskId);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_finalize_pack_job_success(PDO $db, int $taskId, ?array $run, string $workspace, array $taskInput, array $jobContract, array $cleanup, ?callable $audioProbe = null): array
{
    if (!hub_pack_job_cleanup_attested($cleanup)) {
        hub_commit_pack_job_failure($db, $taskId, $run, 'failed', 'cleanup_failed', 'Pack cleanup was not attested', $cleanup);

        return ['ok' => false, 'error_code' => 'cleanup_failed'];
    }
    try {
        hub_pack_job_require_submitted_output_dir($db, $taskId, $run, $workspace);
        $artifacts = hub_validate_pack_job_artifacts($workspace, $taskInput, $jobContract, $audioProbe);
    } catch (HubPackOutputContractInvalid) {
        hub_commit_pack_job_failure($db, $taskId, $run, 'failed', 'output_contract_invalid', 'Pack output contract validation failed', $cleanup);

        return ['ok' => false, 'error_code' => 'output_contract_invalid'];
    }
    hub_commit_pack_job_success($db, $taskId, $run, $artifacts, $cleanup);

    return ['ok' => true, 'artifacts' => count($artifacts)];
}
