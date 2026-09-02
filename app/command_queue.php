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
        'whisper_pascal_ckip_provision',
        'manual_vision_provision',
        'manual_vision_acceptance',
        'paligemma2_provision',
        'paligemma2_acceptance',
        'breezyvoice_provision',
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
        'whisper_pascal_ckip_provision' => '準備 CKIP 字幕資產',
        'manual_vision_provision' => '準備 Manual Vision 模型',
        'manual_vision_acceptance' => '執行 Manual Vision 驗收',
        'paligemma2_provision' => '準備 PaliGemma 2 模型',
        'paligemma2_acceptance' => '執行 PaliGemma 2 CUDA 驗收',
        'breezyvoice_provision' => '準備 BreezyVoice 模型',
        'service_install' => '安裝服務',
        'service_logs_collect' => '收集服務記錄',
        'env_probe' => '環境檢測',
        'permissions_fix' => '權限修正',
        'docker_prune_check' => 'Docker 清理檢查',
        'docker_builder_prune' => 'Docker builder 清理',
    ][$action] ?? $action;
}

function hub_command_action_requires_ready_runtime(string $action): bool
{
    return in_array($action, [
        'service_start',
        'service_restart',
        'service_build',
        'service_install',
        'service_rebuild',
    ], true);
}

function hub_command_require_ready_runtime_pack(?array $pack): void
{
    if ($pack === null || ($pack['status'] ?? '') !== 'ok') {
        throw new RuntimeException('pack_not_installed');
    }
    if (($pack['manifest']['runtime_ready'] ?? null) !== true) {
        throw new RuntimeException('pack_runtime_not_ready');
    }
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
        if (hub_command_action_requires_ready_runtime($action) && !hub_service_is_internal_task($service)) {
            hub_command_require_ready_runtime_pack(hub_get_pack((string)($service['pack_id'] ?? '')));
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

function hub_command_worker_output_line(array $job, int $exitCode): string
{
    $jobId = (int)($job['id'] ?? 0);
    $action = (string)($job['action'] ?? '');

    // Worker stdout 會進入排程與集中日誌；只輸出既有 allowlist action，毀損列仍保留可追查的固定標記。
    if ($jobId < 1) {
        $jobId = 0;
    }
    if (!hub_is_valid_job_action($action)) {
        $action = 'invalid';
    }

    return 'job ' . $jobId . ' ' . $action . ' exit=' . $exitCode;
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
        'service_start', 'service_install', 'service_build', 'service_rebuild', 'whisper_pascal_ckip_provision' => 2100,
        'manual_vision_provision' => 3900,
        'manual_vision_acceptance' => 2100,
        'paligemma2_provision' => 7500,
        'breezyvoice_provision' => 7500,
        'paligemma2_acceptance' => 900,
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
    $logPaths = hub_command_job_log_paths($job);
    $stdoutPath = $logPaths['stdout_path'];
    $stderrPath = $logPaths['stderr_path'];
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
    $hasStoredPaths = !empty($job['stdout_path']) && !empty($job['stderr_path']);
    $logPaths = hub_command_job_log_paths($job);
    $stdoutPath = $logPaths['stdout_path'];
    $stderrPath = $logPaths['stderr_path'];

    if ($hasStoredPaths) {
        $job['stdout_path'] = $stdoutPath;
        $job['stderr_path'] = $stderrPath;
        return $job;
    }

    foreach ([$stdoutPath, $stderrPath] as $path) {
        if (!is_file($path) && !@touch($path)) {
            throw new RuntimeException('Cannot create command job log.');
        }
    }

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

function hub_command_job_log_paths(array $job): array
{
    $jobId = (int)($job['id'] ?? 0);
    if ($jobId < 1) {
        throw new RuntimeException('Command job log owner is invalid.');
    }

    $hasStoredPaths = !empty($job['stdout_path']) && !empty($job['stderr_path']);
    if ($hasStoredPaths) {
        $stdoutPath = (string)$job['stdout_path'];
        $stderrPath = (string)$job['stderr_path'];
    } else {
        $base = rtrim(HUB_JOB_LOG_DIR, '/\\') . '/job_' . $jobId . '_' . date('Ymd_His');
        $stdoutPath = $base . '.out.log';
        $stderrPath = $base . '.err.log';
    }

    return [
        'stdout_path' => hub_command_job_log_path($stdoutPath, 'stdout', $jobId),
        'stderr_path' => hub_command_job_log_path($stderrPath, 'stderr', $jobId),
    ];
}

function hub_command_job_log_path(string $path, string $stream, ?int $expectedJobId = null): string
{
    if (!in_array($stream, ['stdout', 'stderr'], true)) {
        throw new InvalidArgumentException('Command log stream is invalid.');
    }

    hub_ensure_runtime_dirs();
    $logRoot = realpath(HUB_JOB_LOG_DIR);
    if ($logRoot === false || !is_dir($logRoot) || is_link(HUB_JOB_LOG_DIR)) {
        throw new RuntimeException('Command job log root is unavailable.');
    }

    $suffix = $stream === 'stdout' ? 'out' : 'err';
    $basename = basename($path);
    if (preg_match('/^job_([1-9][0-9]*)_[0-9]{8}_[0-9]{6}\.' . $suffix . '\.log$/D', $basename, $matches) !== 1) {
        throw new RuntimeException('Command job log filename is invalid.');
    }
    if ($expectedJobId !== null && (int)$matches[1] !== $expectedJobId) {
        throw new RuntimeException('Command job log belongs to another job.');
    }

    $parent = dirname($path);
    clearstatcache(true, $parent);
    $resolvedParent = realpath($parent);
    if (
        $resolvedParent === false
        || !is_dir($resolvedParent)
        || is_link($parent)
        || !hub_storage_paths_equal($resolvedParent, $logRoot)
    ) {
        throw new RuntimeException('Command job log path escapes the Hub log root.');
    }

    $safePath = rtrim($resolvedParent, '/\\') . DIRECTORY_SEPARATOR . $basename;
    clearstatcache(true, $safePath);
    if (is_link($safePath) || (file_exists($safePath) && !is_file($safePath))) {
        throw new RuntimeException('Command job log must be a regular file.');
    }

    return $safePath;
}

function hub_command_job_status_payload(PDO $db, int $jobId): ?array
{
    $job = hub_get_command_job($db, $jobId);
    if (!$job) {
        return null;
    }
    $service = (int)($job['service_id'] ?? 0) > 0 ? hub_get_service($db, (int)$job['service_id']) : null;

    try {
        $logPaths = hub_command_job_log_paths($job);
    } catch (Throwable) {
        $logPaths = ['stdout_path' => '', 'stderr_path' => ''];
    }

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
        'stdout_tail' => hub_tail_file($logPaths['stdout_path']),
        'stderr_tail' => hub_tail_file($logPaths['stderr_path']),
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

/**
 * Tail 輸出僅供 command job 與 task result 使用；資料庫中的路徑不得使管理頁
 * 任意讀取主機檔案。最終檔案與每個允許根目錄均以實體路徑重新比對。
 */
function hub_tail_file_safe_path(string $path): ?string
{
    if ($path === '' || is_link($path) || !is_file($path)) {
        return null;
    }

    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved)) {
        return null;
    }

    foreach ([HUB_JOB_LOG_DIR, HUB_DATA_DIR . '/results'] as $allowedRoot) {
        if (is_link($allowedRoot)) {
            continue;
        }
        $root = realpath($allowedRoot);
        if ($root !== false && is_dir($root) && hub_storage_path_is_within(dirname($resolved), $root)) {
            return $resolved;
        }
    }

    return null;
}

function hub_tail_file(string $path, int $bytes = 6000): string
{
    $path = hub_tail_file_safe_path($path);
    if ($path === null) {
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
