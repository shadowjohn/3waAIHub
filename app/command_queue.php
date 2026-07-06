<?php
declare(strict_types=1);

function hub_allowed_job_actions(): array
{
    return [
        'service_start',
        'service_stop',
        'service_restart',
        'service_build',
        'service_install',
        'service_rebuild',
        'service_logs_collect',
        'service_health_check',
        'benchmark_run',
        'env_probe',
        'permissions_fix',
        'docker_prune_check',
        'docker_builder_prune',
        'ollama_model_pull',
    ];
}

function hub_is_valid_job_action(string $action): bool
{
    return in_array($action, hub_allowed_job_actions(), true);
}

function hub_enqueue_command_job(PDO $db, string $action, ?int $serviceId, array $args, ?int $requestedBy, ?string $requestedIp): int
{
    if (!hub_is_valid_job_action($action)) {
        throw new InvalidArgumentException('Invalid command action.');
    }
    if ($serviceId !== null && !hub_get_service($db, $serviceId)) {
        throw new InvalidArgumentException('Service not found.');
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO command_jobs
            (action, service_id, args_json, status, progress, stage, current_message, requested_by, requested_ip, created_at, updated_at)
         VALUES
            (:action, :service_id, :args_json, :status, :progress, :stage, :current_message, :requested_by, :requested_ip, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':action' => $action,
        ':service_id' => $serviceId,
        ':args_json' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => 'queued',
        ':progress' => 0,
        ':stage' => 'queued',
        ':current_message' => 'Queued.',
        ':requested_by' => $requestedBy,
        ':requested_ip' => $requestedIp,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int)$db->lastInsertId();
}

function hub_get_command_job(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM command_jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function hub_list_command_jobs(PDO $db, int $limit = 20): array
{
    $stmt = $db->prepare(
        'SELECT cj.*, s.name AS service_name
         FROM command_jobs cj
         LEFT JOIN services s ON s.id = cj.service_id
         ORDER BY cj.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function hub_claim_next_command_job(PDO $db): ?array
{
    $db->beginTransaction();
    try {
        $job = $db->query(
            "SELECT * FROM command_jobs
             WHERE status = 'queued' AND lock_token IS NULL
             ORDER BY id
             LIMIT 1"
        )->fetch();
        if (!$job) {
            $db->commit();
            return null;
        }

        $token = bin2hex(random_bytes(16));
        $now = hub_now();
        $stmt = $db->prepare(
            "UPDATE command_jobs
             SET status = 'running', lock_token = :lock_token, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND status = 'queued' AND lock_token IS NULL"
        );
        $stmt->execute([
            ':lock_token' => $token,
            ':started_at' => $now,
            ':updated_at' => $now,
            ':id' => (int)$job['id'],
        ]);
        $db->commit();

        return hub_get_command_job($db, (int)$job['id']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function hub_finish_command_job(PDO $db, array $job, string $status, int $exitCode, string $stdout, string $stderr, ?string $errorMessage = null): void
{
    if (!in_array($status, ['success', 'failed', 'cancelled', 'timeout'], true)) {
        throw new InvalidArgumentException('Invalid job status.');
    }

    hub_ensure_runtime_dirs();
    $job = hub_prepare_command_job_logs($db, $job);
    $stdoutPath = (string)$job['stdout_path'];
    $stderrPath = (string)$job['stderr_path'];
    if ($stdout !== '' && (!is_file($stdoutPath) || filesize($stdoutPath) === 0)) {
        file_put_contents($stdoutPath, $stdout);
    } elseif (!is_file($stdoutPath)) {
        file_put_contents($stdoutPath, '');
    }
    if ($stderr !== '' && (!is_file($stderrPath) || filesize($stderrPath) === 0)) {
        file_put_contents($stderrPath, $stderr);
    } elseif (!is_file($stderrPath)) {
        file_put_contents($stderrPath, '');
    }

    $progressSql = $status === 'success'
        ? "progress = 100, stage = 'success', current_message = 'Completed.',"
        : 'current_message = COALESCE(:current_message, current_message),';

    $stmt = $db->prepare(
        'UPDATE command_jobs
         SET status = :status,
             ' . $progressSql . '
             finished_at = :finished_at,
             exit_code = :exit_code,
             stdout_path = :stdout_path,
             stderr_path = :stderr_path,
             error_message = :error_message,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $params = [
        ':status' => $status,
        ':finished_at' => hub_now(),
        ':exit_code' => $exitCode,
        ':stdout_path' => $stdoutPath,
        ':stderr_path' => $stderrPath,
        ':error_message' => $errorMessage,
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ];
    if ($status !== 'success') {
        $params[':current_message'] = $errorMessage === null ? null : substr($errorMessage, 0, 500);
    }
    $stmt->execute($params);
}

function hub_update_command_job_progress(PDO $db, int $jobId, string $stage, int $progress, string $message): void
{
    $progress = max(0, min(100, $progress));
    $stmt = $db->prepare(
        'UPDATE command_jobs
         SET progress = :progress, stage = :stage, current_message = :current_message, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':progress' => $progress,
        ':stage' => $stage,
        ':current_message' => substr($message, 0, 500),
        ':updated_at' => hub_now(),
        ':id' => $jobId,
    ]);
}

function hub_prepare_command_job_logs(PDO $db, array $job): array
{
    hub_ensure_runtime_dirs();
    if (!empty($job['stdout_path']) && !empty($job['stderr_path'])) {
        return $job;
    }

    $base = HUB_JOB_LOG_DIR . '/job_' . (int)$job['id'] . '_' . date('Ymd_His');
    $stdoutPath = $base . '.out.log';
    $stderrPath = $base . '.err.log';
    touch($stdoutPath);
    touch($stderrPath);

    $stmt = $db->prepare(
        'UPDATE command_jobs
         SET stdout_path = :stdout_path, stderr_path = :stderr_path, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':stdout_path' => $stdoutPath,
        ':stderr_path' => $stderrPath,
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ]);

    $job['stdout_path'] = $stdoutPath;
    $job['stderr_path'] = $stderrPath;
    return $job;
}

function hub_command_job_status_payload(PDO $db, int $jobId): ?array
{
    $job = hub_get_command_job($db, $jobId);
    if (!$job) {
        return null;
    }

    return [
        'id' => (int)$job['id'],
        'status' => (string)$job['status'],
        'progress' => (int)($job['progress'] ?? 0),
        'stage' => (string)($job['stage'] ?? ''),
        'current_message' => (string)($job['current_message'] ?? ''),
        'exit_code' => $job['exit_code'] === null ? null : (int)$job['exit_code'],
        'error_message' => (string)($job['error_message'] ?? ''),
        'stdout_tail' => hub_tail_file((string)($job['stdout_path'] ?? '')),
        'stderr_tail' => hub_tail_file((string)($job['stderr_path'] ?? '')),
    ];
}

function hub_tail_file(string $path, int $bytes = 6000): string
{
    if ($path === '' || !is_file($path)) {
        return '';
    }
    $size = filesize($path);
    if ($size === false) {
        return '';
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }
    fseek($handle, -min($bytes, $size), SEEK_END);
    $tail = stream_get_contents($handle);
    fclose($handle);

    return $tail === false ? '' : $tail;
}
