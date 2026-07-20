<?php
declare(strict_types=1);

function hub_pack_job_worker_task_types(): array
{
    return ['demo_task', 'structure_parse', 'docparser_parse', 'docparser_repair_translation', 'pack_job'];
}

function hub_pack_job_adapter_worker_id(array $options): string
{
    $workerId = trim((string)($options['worker_id'] ?? ('task-worker-' . gethostname())));
    if ($workerId === '') {
        throw new InvalidArgumentException('worker_id_required');
    }

    return substr($workerId, 0, 128);
}

function hub_pack_job_claim_runtime(PDO $db, array $task, string $workerId, int $leaseSeconds): ?array
{
    $taskId = (int)($task['id'] ?? 0);
    $taskLock = (string)($task['lock_token'] ?? '');
    if ($taskId <= 0 || $taskLock === '') {
        return null;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $guard = $db->prepare("SELECT 1 FROM tasks WHERE id = :id AND task_type = 'pack_job' AND status = 'running' AND lock_token = :lock_token");
        $guard->execute([':id' => $taskId, ':lock_token' => $taskLock]);
        if ($guard->fetchColumn() === false) {
            $db->exec('COMMIT');
            return null;
        }
        $find = $db->prepare('SELECT * FROM runtime_runs WHERE task_id = :task_id ORDER BY id ASC LIMIT 1');
        $find->execute([':task_id' => $taskId]);
        $run = $find->fetch();
        if (!$run) {
            $now = hub_now();
            $db->prepare(
                'INSERT INTO runtime_runs
                    (run_id, task_id, attempt_no, pack_id, task, pack_version, runner_version, caller, workspace, state, started_at, created_at)
                 VALUES
                    (:run_id, :task_id, 0, :pack_id, :task, :pack_version, :runner_version, :caller, :workspace, :state, :started_at, :created_at)'
            )->execute([
                ':run_id' => 'packjob-' . $taskId . '-' . bin2hex(random_bytes(12)),
                ':task_id' => $taskId,
                ':pack_id' => (string)$task['pack_id'],
                ':task' => (string)$task['job'],
                ':pack_version' => (string)$task['pack_version'],
                ':runner_version' => 'pack-job-adapter/0.1',
                ':caller' => $workerId,
                ':workspace' => hub_task_result_dir($taskId) . '/workspace',
                ':state' => 'queued',
                ':started_at' => $now,
                ':created_at' => $now,
            ]);
            $find->execute([':task_id' => $taskId]);
            $run = $find->fetch();
        }
        if (!is_array($run) || ($run['state'] ?? '') !== 'queued') {
            $db->exec('COMMIT');
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $now = hub_now();
        $claim = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'claimed', worker_id = :worker_id, lease_token = :lease_token, claimed_at = :now,
                 heartbeat_at = :now, lease_expires_at = :lease_expires_at, attempt_no = COALESCE(attempt_no, 0) + 1
             WHERE id = :id AND state = 'queued'"
        );
        $claim->execute([
            ':worker_id' => $workerId,
            ':lease_token' => $token,
            ':now' => $now,
            ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
            ':id' => (int)$run['id'],
        ]);
        if ($claim->rowCount() !== 1) {
            $db->exec('COMMIT');
            return null;
        }
        $run = hub_runtime_fetch_run($db, (int)$run['id']);
        $db->exec('COMMIT');

        return $run;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_wait_without_gpu(PDO $db, int $taskId, array $run, string $reason, int $backoffSeconds): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_wait_transaction_required');
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!hub_runtime_gpu_runtime_fence_in_transaction($db, $run, $taskId)) {
            $db->exec('ROLLBACK');
            return false;
        }
        $runStmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'waiting_gpu', lease_expires_at = NULL, heartbeat_at = :now, error_code = NULL
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND task_id = :task_id AND state IN ('claimed', 'running')"
        );
        $runStmt->execute([
            ':now' => $now,
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
            ':task_id' => $taskId,
        ]);
        $taskStmt = $db->prepare(
            "UPDATE tasks
             SET status = 'waiting_gpu', waiting_reason = :reason, next_attempt_at = :next_attempt_at,
                 lock_token = NULL, updated_at = :now
             WHERE id = :id AND task_type = 'pack_job' AND status = 'running'"
        );
        $taskStmt->execute([
            ':reason' => $reason,
            ':next_attempt_at' => hub_runtime_lease_until(max(1, $backoffSeconds)),
            ':now' => $now,
            ':id' => $taskId,
        ]);
        if ($runStmt->rowCount() !== 1 || $taskStmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_no_work_cleanup(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

function hub_pack_job_failure_code(Throwable $error, string $fallback = 'job_unavailable'): string
{
    $message = $error->getMessage();
    return in_array($message, ['pack_version_unavailable', 'job_unavailable', 'job_contract_unavailable'], true) ? $message : $fallback;
}

function hub_pack_job_adapter_failure(PDO $db, int $taskId, array $run, string $code, string $message, array $cleanup, ?array $gpuLease): array
{
    hub_commit_pack_job_failure($db, $taskId, $run, 'failed', $code, $message, $cleanup, $gpuLease);
    $task = hub_get_task($db, $taskId);

    return ['status' => (string)($task['status'] ?? 'failed'), 'error_code' => (string)($task['error_code'] ?? $code)];
}

function hub_pack_job_prepare_workspace(array $task, array $contract): string
{
    $taskId = (int)$task['id'];
    $taskRoot = hub_task_result_dir($taskId);
    if (is_link($taskRoot) || (!is_dir($taskRoot) && !mkdir($taskRoot, 0700, true))) {
        throw new RuntimeException('workspace_unavailable');
    }
    $taskRoot = realpath($taskRoot);
    if ($taskRoot === false) {
        throw new RuntimeException('workspace_unavailable');
    }
    $workspace = $taskRoot . '/workspace';
    if (is_link($workspace) || (!is_dir($workspace) && !mkdir($workspace, 0700, true))) {
        throw new RuntimeException('workspace_unavailable');
    }
    foreach (['input', 'output', 'logs'] as $name) {
        $dir = $workspace . '/' . $name;
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) {
            throw new RuntimeException('workspace_unavailable');
        }
    }
    $workspace = realpath($workspace);
    if ($workspace === false || !str_starts_with($workspace, $taskRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('workspace_unavailable');
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    $request = [];
    foreach ((array)($contract['input_fields'] ?? []) as $field) {
        if (array_key_exists($field, $input)) {
            $request[$field] = $input[$field];
        }
    }
    $source = null;
    if ((int)($task['source_artifact_id'] ?? 0) <= 0 && isset($input['source_upload_path'])) {
        $source = hub_managed_task_upload_path($taskId, (string)$input['source_upload_path']);
        if ($source === null) {
            throw new RuntimeException('source_upload_invalid');
        }
    }
    if ($source !== null && !copy($source, $workspace . '/input/source')) {
        throw new RuntimeException('source_copy_failed');
    }
    $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($workspace . '/input/request.json', $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('workspace_unavailable');
    }

    return $workspace;
}

function hub_pack_job_copy_source_artifact(PDO $db, array $task, string $workspace): void
{
    $artifactId = (int)($task['source_artifact_id'] ?? 0);
    if ($artifactId <= 0) {
        return;
    }
    $artifact = hub_get_task_artifact($db, $artifactId);
    $source = is_array($artifact) ? hub_artifact_safe_path((string)($artifact['path'] ?? '')) : null;
    if ($source === null || !copy($source, $workspace . '/input/source')) {
        throw new RuntimeException('source_artifact_invalid');
    }
}

function hub_pack_job_mark_running(PDO $db, array $run, array $runner): bool
{
    if (!hub_runtime_mark_running($db, (int)$run['id'], (string)$run['lease_token'])) {
        return false;
    }
    $timeout = date('Y-m-d H:i:s', time() + (int)$runner['timeout_seconds']);
    $stmt = $db->prepare(
        "UPDATE runtime_runs SET image_name = :image_name, timeout_at = :timeout_at
         WHERE id = :id AND lease_token = :lease_token AND state = 'running'"
    );
    $stmt->execute([
        ':image_name' => $runner['image'],
        ':timeout_at' => $timeout,
        ':id' => (int)$run['id'],
        ':lease_token' => (string)$run['lease_token'],
    ]);

    return $stmt->rowCount() === 1;
}

function hub_pack_job_runner_arguments(array $runner, array $task, array $run, string $workspace): array
{
    $replacements = [
        '{workspace}' => $workspace,
        '{input_dir}' => $workspace . '/input',
        '{output_dir}' => $workspace . '/output',
        '{run_id}' => (string)$run['run_id'],
        '{task_id}' => (string)$task['id'],
    ];
    $replace = static fn (string $value): string => strtr($value, $replacements);

    return [
        'image' => $runner['image'],
        'entrypoint' => array_map($replace, $runner['entrypoint']),
        'args' => array_map($replace, $runner['args']),
        'output_dir' => $workspace . '/output',
        'accelerator' => $runner['accelerator'],
        'required_vram_mb' => $runner['required_vram_mb'],
        'timeout_seconds' => $runner['timeout_seconds'],
    ];
}

function hub_pack_job_execution_details(array $details, array $fallback = []): array
{
    $reportedEvidence = (isset($details['container_id']) && trim((string)$details['container_id']) !== '')
        || array_key_exists('baseline_pids', $details)
        || array_key_exists('owned_pids', $details);
    $details += $fallback;
    $containerId = isset($details['container_id']) ? trim((string)$details['container_id']) : null;
    if ($containerId === '') {
        $containerId = null;
    }
    if ($containerId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/', $containerId) !== 1) {
        throw new RuntimeException('runtime_metadata_invalid');
    }

    return [
        'container_id' => $containerId,
        'baseline_pids' => hub_runtime_gpu_recovery_pids($details['baseline_pids'] ?? []),
        'owned_pids' => hub_runtime_gpu_recovery_pids($details['owned_pids'] ?? []),
        'has_process_evidence' => $reportedEvidence || !empty($fallback['has_process_evidence']),
    ];
}

function hub_pack_job_record_execution(PDO $db, array $task, array $run, ?array $gpuLease, array $details): bool
{
    if ($gpuLease !== null) {
        return hub_runtime_record_gpu_ownership($db, $run, $gpuLease, $details['container_id'], $details['baseline_pids'], $details['owned_pids']);
    }
    $stmt = $db->prepare(
        "UPDATE runtime_runs SET container_id = :container_id
         WHERE id = :id AND task_id = :task_id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
    );
    $stmt->execute([
        ':container_id' => $details['container_id'],
        ':id' => (int)$run['id'],
        ':task_id' => (int)$task['id'],
        ':lease_token' => (string)$run['lease_token'],
    ]);

    return $stmt->rowCount() === 1;
}

function hub_pack_job_tick(PDO $db, array $run, ?array $gpuLease, int $leaseSeconds): ?string
{
    $alive = $gpuLease === null
        ? hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], $leaseSeconds)
        : hub_runtime_gpu_heartbeat($db, $run, $gpuLease, $leaseSeconds);
    if (!$alive) {
        return 'fence_lost';
    }
    if (hub_runtime_is_cancel_requested($db, (int)$run['id'])) {
        return 'cancelled';
    }
    $current = hub_runtime_fetch_run($db, (int)$run['id']);
    if ($current === null || !hash_equals((string)$run['lease_token'], (string)$current['lease_token'])) {
        return 'fence_lost';
    }
    if (!empty($current['timeout_at']) && (string)$current['timeout_at'] <= hub_now()) {
        return 'timed_out';
    }

    return null;
}

function hub_pack_job_cleanup_from_result(array $result, array $details, ?callable $pidInspector, array $context): array
{
    $cleanup = is_array($result['cleanup'] ?? null) ? $result['cleanup'] : [];
    if (!$details['has_process_evidence'] && empty($result['completed_no_process_evidence'])) {
        return [];
    }
    if ($pidInspector !== null && $details['owned_pids'] !== []) {
        $pids = hub_runtime_gpu_recovery_pids($pidInspector($context));
        if (array_intersect($details['owned_pids'], $pids) !== []) {
            $cleanup['owned_gpu_pids_gone'] = false;
        }
    }

    return $cleanup;
}

function hub_pack_job_stop_result(array $options, array $context, string $reason, array $result): array
{
    if (!isset($options['stopper']) || !is_callable($options['stopper'])) {
        return $result;
    }
    $stopped = $options['stopper']($context, $reason, $result);
    if (!is_array($stopped)) {
        throw new RuntimeException('runtime_stop_invalid');
    }
    if (array_intersect(['runner_exited', 'container_removed', 'owned_gpu_pids_gone'], array_keys($stopped)) !== []) {
        $result['cleanup'] = $stopped;
        return $result;
    }

    return array_replace($result, $stopped);
}

function hub_run_pack_job_task(PDO $db, array $task, array $options = []): array
{
    if (($task['task_type'] ?? '') !== 'pack_job' || ($task['status'] ?? '') !== 'running') {
        throw new InvalidArgumentException('pack_job_task_required');
    }
    $taskId = (int)$task['id'];
    $leaseSeconds = max(5, (int)($options['lease_seconds'] ?? 60));
    $workerId = hub_pack_job_adapter_worker_id($options);
    $run = hub_pack_job_claim_runtime($db, $task, $workerId, $leaseSeconds);
    if ($run === null) {
        return ['status' => 'fence_lost'];
    }
    $gpuLease = null;
    $started = false;
    try {
        try {
            $contract = hub_resolve_stored_pack_job($db, $task);
        } catch (Throwable $e) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, hub_pack_job_failure_code($e), 'Stored Pack job is unavailable', hub_pack_job_no_work_cleanup(), null);
        }
        if (!isset($contract['runner'])) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'job_unavailable', 'Stored Pack job has no runner contract', hub_pack_job_no_work_cleanup(), null);
        }
        if (!isset($options['executor']) || !is_callable($options['executor'])) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'No controlled Pack job executor is configured', hub_pack_job_no_work_cleanup(), null);
        }
        $runner = $contract['runner'];
        if (hub_runtime_task_requires_gpu($task)) {
            $gpuLease = hub_runtime_gpu_acquire_for_task($db, $task, $run, $leaseSeconds);
            if ($gpuLease === null) {
                return ['status' => hub_pack_job_wait_without_gpu($db, $taskId, $run, 'gpu_unavailable', max(1, (int)($options['gpu_backoff_seconds'] ?? 30))) ? 'waiting_gpu' : 'fence_lost'];
            }
            $probe = $options['gpu_probe'] ?? static fn (): array => ['free_vram_mb' => 0, 'processes' => []];
            $preflight = hub_runtime_gpu_preflight($db, $taskId, $run, $gpuLease, (int)$runner['required_vram_mb'], $probe, max(1, (int)($options['gpu_backoff_seconds'] ?? 30)));
            if (empty($preflight['ok'])) {
                return ['status' => ($preflight['reason'] ?? '') === 'lost_gpu_lease' ? 'fence_lost' : 'waiting_gpu'];
            }
        }
        if (!hub_pack_job_mark_running($db, $run, $runner)) {
            return ['status' => 'fence_lost'];
        }
        $workspace = hub_pack_job_prepare_workspace($task, $contract);
        hub_pack_job_copy_source_artifact($db, $task, $workspace);
        $context = [
            'db' => $db,
            'task' => $task,
            'run' => $run,
            'workspace' => $workspace,
            'runner' => hub_pack_job_runner_arguments($runner, $task, $run, $workspace),
        ];
        $pidInspector = $gpuLease === null ? null : ($options['pid_inspector'] ?? static fn (): array => []);
        $baseline = $pidInspector === null ? [] : hub_runtime_gpu_recovery_pids($pidInspector($context));
        $details = hub_pack_job_execution_details(['baseline_pids' => $baseline]);
        // A baseline is needed for GPU recovery but is not proof that this executor owned no process.
        $details['has_process_evidence'] = false;
        if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            return ['status' => 'fence_lost'];
        }
        $fenceLost = false;
        $context['started'] = static function (array $startedDetails) use (&$details, &$fenceLost, $db, $task, $run, $gpuLease, $baseline): void {
            $details = hub_pack_job_execution_details($startedDetails, ['baseline_pids' => $baseline]);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                $fenceLost = true;
            }
        };
        $context['tick'] = static function () use ($db, $run, $gpuLease, $leaseSeconds): ?string {
            return hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds);
        };
        $started = true;
        $result = $options['executor']($context);
        if (!is_array($result)) {
            throw new RuntimeException('runtime_execution_invalid');
        }
        $details = hub_pack_job_execution_details($result, $details);
        if (!$fenceLost && !hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            $fenceLost = true;
        }
        $intent = hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds);
        if ($fenceLost || $intent === 'fence_lost') {
            return ['status' => 'fence_lost'];
        }
        if ($intent === 'cancelled' || $intent === 'timed_out') {
            $result = hub_pack_job_stop_result($options, $context, $intent, $result);
            $details = hub_pack_job_execution_details($result, $details);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                return ['status' => 'fence_lost'];
            }
            $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
            hub_commit_pack_job_failure($db, $taskId, $run, $intent, $intent, 'Pack job ' . $intent, $cleanup, $gpuLease);
            $latest = hub_get_task($db, $taskId);
            return ['status' => (string)($latest['status'] ?? 'failed'), 'error_code' => (string)($latest['error_code'] ?? $intent)];
        }
        $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            $code = (string)($result['error_code'] ?? 'runtime_exit_nonzero');
            if (preg_match('/^[a-z0-9_:-]{1,120}$/i', $code) !== 1) {
                $code = 'runtime_exit_nonzero';
            }
            return hub_pack_job_adapter_failure($db, $taskId, $run, $code, 'Pack job exited unsuccessfully', $cleanup, $gpuLease);
        }
        $final = hub_finalize_pack_job_success($db, $taskId, $run, $workspace, (array)($task['input'] ?? []), $contract['artifact_contract'], $cleanup, null, $gpuLease);
        $latest = hub_get_task($db, $taskId);
        return ['status' => (string)($latest['status'] ?? (($final['ok'] ?? false) ? 'success' : 'failed'))] + $final;
    } catch (Throwable $e) {
        if (hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds) === 'fence_lost') {
            return ['status' => 'fence_lost'];
        }
        return hub_pack_job_adapter_failure(
            $db,
            $taskId,
            $run,
            hub_pack_job_failure_code($e, 'runtime_execution_failed'),
            'Pack job adapter failed: ' . substr($e->getMessage(), 0, 512),
            $started ? [] : hub_pack_job_no_work_cleanup(),
            $gpuLease
        );
    }
}
