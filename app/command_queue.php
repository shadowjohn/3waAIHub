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
        'service_remove',
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

function hub_command_status_label(string $status): string
{
    return [
        'running' => '執行中',
        'stopped' => '已停止',
        'success' => '成功',
        'failed' => '失敗',
        'queued' => '排隊中',
        'cancelled' => '已取消',
        'timeout' => '逾時',
        'error' => '錯誤',
        'ok' => '正常',
        'pass' => '通過',
        'fail' => '失敗',
    ][$status] ?? $status;
}

function hub_command_status_class(string $status): string
{
    return in_array($status, ['running', 'success', 'ok', 'pass'], true) ? 'ok' : 'bad';
}

function hub_command_action_label(string $action): string
{
    return [
        'service_start' => '啟動服務',
        'service_stop' => '停止服務',
        'service_restart' => '重啟服務',
        'service_build' => '建置服務',
        'service_rebuild' => '重新建置',
        'service_remove' => '移除服務',
        'service_health_check' => '健康檢查',
        'benchmark_run' => 'Benchmark 測試',
        'ollama_model_pull' => 'Ollama 模型拉取',
        'service_install' => '安裝服務',
        'service_logs_collect' => '收集服務記錄',
        'env_probe' => '環境檢測',
        'permissions_fix' => '權限修正',
        'docker_prune_check' => 'Docker 清理檢查',
        'docker_builder_prune' => 'Docker builder 清理',
    ][$action] ?? $action;
}

function hub_enqueue_command_job(PDO $db, string $action, ?int $serviceId, array $args, ?int $requestedBy, ?string $requestedIp): int
{
    if (!hub_is_valid_job_action($action)) {
        throw new InvalidArgumentException('Invalid command action.');
    }

    $now = hub_now();
    $insert = static function () use ($db, $action, $serviceId, &$args, $requestedBy, $requestedIp, $now): int {
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
    };
    if ($serviceId === null) {
        return $insert();
    }

    $started = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $started = true;
        $service = hub_get_service($db, $serviceId);
        if (!$service) {
            throw new InvalidArgumentException('Service not found.');
        }
        if ($action === 'service_remove') {
            $args['service_updated_at'] = (string)($service['updated_at'] ?? '');
            if (hub_service_has_active_command_job($db, $serviceId)) {
                throw new RuntimeException('Cannot enqueue service removal while another service command is active.');
            }
        } else {
            $removal = $db->prepare(
                "SELECT 1 FROM command_jobs
                 WHERE service_id = :service_id AND action = 'service_remove' AND status IN ('queued', 'running')
                 LIMIT 1"
            );
            $removal->execute([':service_id' => $serviceId]);
            if ($removal->fetchColumn() !== false) {
                throw new RuntimeException('Cannot enqueue a service command while removal is active.');
            }
        }

        $jobId = $insert();
        $db->exec('COMMIT');
        $started = false;
        return $jobId;
    } catch (Throwable $e) {
        if ($started) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
}

function hub_get_command_job(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM command_jobs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $job = $stmt->fetch();

    return $job ?: null;
}

function hub_service_has_active_command_job(PDO $db, int $serviceId, ?int $excludingJobId = null): bool
{
    $sql = "SELECT 1 FROM command_jobs
            WHERE service_id = :service_id
              AND status IN ('queued', 'running')";
    $params = [':service_id' => $serviceId];
    if ($excludingJobId !== null) {
        $sql .= ' AND id != :excluding_job_id';
        $params[':excluding_job_id'] = $excludingJobId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() !== false;
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

function hub_claim_next_command_job(PDO $db, ?callable $eligible = null): ?array
{
    $db->beginTransaction();
    try {
        // runtime worker 需要略過另一個 worker 已負責的 job，維持同一個原子 claim 流程。
        $jobs = $db->query(
            "SELECT * FROM command_jobs
              WHERE status = 'queued' AND lock_token IS NULL
              ORDER BY id"
        )->fetchAll();
        $job = null;
        foreach ($jobs as $candidate) {
            if ($eligible === null || $eligible($candidate)) {
                $job = $candidate;
                break;
            }
        }
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

function hub_command_job_stale_after_seconds(string $action): int
{
    return match ($action) {
        // 依現有 command timeout 加五分鐘緩衝，避免安靜執行的長任務被誤判。
        'ollama_model_pull' => 14700,
        'service_start', 'service_install', 'service_build', 'service_rebuild' => 2100,
        'docker_builder_prune' => 1200,
        default => 900,
    };
}

function hub_recover_stale_command_jobs(PDO $db, ?string $now = null): int
{
    $now ??= hub_now();
    $nowTimestamp = strtotime($now);
    if ($nowTimestamp === false) {
        throw new InvalidArgumentException('Invalid recovery timestamp.');
    }

    $jobs = $db->query(
        "SELECT id, action, updated_at
         FROM command_jobs
         WHERE status = 'running'"
    )->fetchAll();
    $recover = $db->prepare(
        "UPDATE command_jobs
         SET status = 'failed',
             finished_at = :finished_at,
             exit_code = 1,
             lock_token = NULL,
             error_message = :error_message,
             error_code = 'worker_lost',
             current_message = :current_message,
             updated_at = :updated_at
         WHERE id = :id AND status = 'running' AND updated_at = :previous_updated_at"
    );
    $recovered = 0;
    foreach ($jobs as $job) {
        $updatedAt = (string)($job['updated_at'] ?? '');
        $updatedTimestamp = strtotime($updatedAt);
        if ($updatedTimestamp === false || $nowTimestamp - $updatedTimestamp <= hub_command_job_stale_after_seconds((string)$job['action'])) {
            continue;
        }

        $message = 'Command worker lease expired before completion.';
        $recover->execute([
            ':finished_at' => $now,
            ':error_message' => $message,
            ':current_message' => $message,
            ':updated_at' => $now,
            ':id' => (int)$job['id'],
            ':previous_updated_at' => $updatedAt,
        ]);
        $recovered += $recover->rowCount();
    }

    return $recovered;
}

function hub_finish_command_job(PDO $db, array $job, string $status, int $exitCode, string $stdout, string $stderr, ?string $errorMessage = null, ?string $errorCode = null): void
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
             error_code = :error_code,
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
        ':error_code' => $errorCode,
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
    $service = (int)($job['service_id'] ?? 0) > 0 ? hub_get_service($db, (int)$job['service_id']) : null;

    return [
        'id' => (int)$job['id'],
        'action' => (string)$job['action'],
        'action_label' => hub_command_action_label((string)$job['action']),
        'service_id' => $service ? (int)$service['id'] : null,
        'service_name' => $service ? (string)$service['name'] : '',
        'status' => (string)$job['status'],
        'status_label' => hub_command_status_label((string)$job['status']),
        'status_class' => hub_command_status_class((string)$job['status']),
        'progress' => (int)($job['progress'] ?? 0),
        'stage' => (string)($job['stage'] ?? ''),
        'current_message' => (string)($job['current_message'] ?? ''),
        'exit_code' => $job['exit_code'] === null ? null : (int)$job['exit_code'],
        'error_message' => (string)($job['error_message'] ?? ''),
        'error_code' => isset($job['error_code']) ? (string)$job['error_code'] : null,
        'created_at' => (string)($job['created_at'] ?? ''),
        'updated_at' => (string)($job['updated_at'] ?? ''),
        'stdout_tail' => hub_tail_file((string)($job['stdout_path'] ?? '')),
        'stderr_tail' => hub_tail_file((string)($job['stderr_path'] ?? '')),
        'service' => $service ? [
            'id' => (int)$service['id'],
            'status' => (string)$service['status'],
            'status_label' => hub_command_status_label((string)$service['status']),
            'status_class' => hub_command_status_class((string)$service['status']),
            'runtime_status' => (string)($service['runtime_status'] ?? $service['status']),
            'enabled' => (int)($service['enabled'] ?? 0),
            'restart_required' => (int)($service['restart_required'] ?? 0),
        ] : null,
    ];
}

function hub_command_service_summary(PDO $db): array
{
    $services = hub_list_services($db);
    $jobs = hub_list_command_jobs($db, 50);

    return [
        'total' => count($services),
        'running' => count(array_filter(
            $services,
            static fn (array $item): bool => (string)($item['runtime_status'] ?? $item['status']) === 'running'
        )),
        'stopped' => count(array_filter(
            $services,
            static fn (array $item): bool => (string)($item['runtime_status'] ?? $item['status']) !== 'running'
        )),
        'disabled' => count(array_filter($services, static fn (array $item): bool => (int)$item['enabled'] !== 1)),
        'active_jobs' => count(array_filter(
            $jobs,
            static fn (array $item): bool => in_array((string)$item['status'], ['queued', 'running'], true)
        )),
        'failed_jobs' => count(array_filter($jobs, static fn (array $item): bool => (string)$item['status'] === 'failed')),
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
