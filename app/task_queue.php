<?php
declare(strict_types=1);

function hub_allowed_task_types(): array
{
    return ['demo_task'];
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

function hub_enqueue_task(PDO $db, string $taskType, string $queueName, int $priority, array $input, ?int $requestedBy, ?string $requestedIp): int
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
            (task_type, queue_name, priority, input_json, status, requested_by, requested_ip, created_at, updated_at)
         VALUES
            (:task_type, :queue_name, :priority, :input_json, :status, :requested_by, :requested_ip, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':task_type' => $taskType,
        ':queue_name' => $queueName,
        ':priority' => $priority,
        ':input_json' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => 'queued',
        ':requested_by' => $requestedBy,
        ':requested_ip' => $requestedIp,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
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
}

function hub_list_task_logs(PDO $db, int $taskId): array
{
    $stmt = $db->prepare('SELECT * FROM task_logs WHERE task_id = :task_id ORDER BY id ASC');
    $stmt->execute([':task_id' => $taskId]);

    return $stmt->fetchAll();
}

function hub_finish_task_success(PDO $db, array $task, array $result): void
{
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'success', progress = 100, result_json = :result_json, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    );
    $stmt->execute([
        ':result_json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => (int)$task['id'],
    ]);
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

    return $stmt->rowCount() === 1;
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
