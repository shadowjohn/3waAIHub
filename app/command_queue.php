<?php
declare(strict_types=1);

function hub_allowed_job_actions(): array
{
    return [
        'service_start',
        'service_stop',
        'service_restart',
        'service_install',
        'service_rebuild',
        'service_logs_collect',
        'service_health_check',
        'benchmark_run',
        'env_probe',
        'permissions_fix',
        'docker_prune_check',
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
            (action, service_id, args_json, status, requested_by, requested_ip, created_at, updated_at)
         VALUES
            (:action, :service_id, :args_json, :status, :requested_by, :requested_ip, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':action' => $action,
        ':service_id' => $serviceId,
        ':args_json' => json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => 'queued',
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
    $base = HUB_JOB_LOG_DIR . '/job_' . (int)$job['id'] . '_' . date('Ymd_His');
    $stdoutPath = $base . '.out.log';
    $stderrPath = $base . '.err.log';
    file_put_contents($stdoutPath, $stdout);
    file_put_contents($stderrPath, $stderr);

    $stmt = $db->prepare(
        'UPDATE command_jobs
         SET status = :status,
             finished_at = :finished_at,
             exit_code = :exit_code,
             stdout_path = :stdout_path,
             stderr_path = :stderr_path,
             error_message = :error_message,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':finished_at' => hub_now(),
        ':exit_code' => $exitCode,
        ':stdout_path' => $stdoutPath,
        ':stderr_path' => $stderrPath,
        ':error_message' => $errorMessage,
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ]);
}
