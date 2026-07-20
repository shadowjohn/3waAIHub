<?php
declare(strict_types=1);

function hub_runtime_lease_until(int $leaseSeconds): string
{
    return date('Y-m-d H:i:s', time() + max(1, $leaseSeconds));
}

function hub_runtime_gpu_runtime_identity(array $run): array
{
    $runId = trim((string)($run['run_id'] ?? ''));
    $workerId = trim((string)($run['worker_id'] ?? ''));
    $leaseToken = trim((string)($run['lease_token'] ?? ''));
    if ($runId === '' || ctype_digit($runId) || $workerId === '' || $leaseToken === '') {
        throw new InvalidArgumentException('runtime_gpu_runtime_fence_invalid');
    }

    return ['run_id' => $runId, 'worker_id' => $workerId, 'lease_token' => $leaseToken];
}

function hub_runtime_gpu_lease_identity(array $lease): array
{
    $resourceKey = (string)($lease['resource_key'] ?? '');
    $runId = trim((string)($lease['runtime_run_id'] ?? ''));
    $workerId = trim((string)($lease['worker_id'] ?? ''));
    $leaseToken = trim((string)($lease['lease_token'] ?? ''));
    if ($resourceKey !== 'gpu:0' || $runId === '' || ctype_digit($runId) || $workerId === '' || $leaseToken === '') {
        throw new InvalidArgumentException('runtime_gpu_lease_fence_invalid');
    }

    return [
        'resource_key' => $resourceKey,
        'runtime_run_id' => $runId,
        'worker_id' => $workerId,
        'lease_token' => $leaseToken,
    ];
}

function hub_runtime_gpu_fence_matches_run(array $run, array $lease): bool
{
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $gpu = hub_runtime_gpu_lease_identity($lease);

    return $runtime['run_id'] === $gpu['runtime_run_id'] && $runtime['worker_id'] === $gpu['worker_id'];
}

function hub_runtime_gpu_fetch(PDO $db): ?array
{
    $row = $db->query("SELECT * FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetch();

    return is_array($row) ? $row : null;
}

function hub_runtime_gpu_acquire(PDO $db, array $run, int $leaseSeconds): ?array
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_acquire_transaction_required');
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $gpuToken = bin2hex(random_bytes(32));

    $db->exec('BEGIN IMMEDIATE');
    try {
        $runFence = $db->prepare(
            "SELECT 1 FROM runtime_runs
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running')"
        );
        $runFence->execute([
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
        ]);
        if ($runFence->fetchColumn() === false) {
            $db->exec('COMMIT');
            return null;
        }

        $stmt = $db->prepare(
            "UPDATE runtime_resource_leases
             SET runtime_run_id = :runtime_run_id, worker_id = :worker_id, lease_token = :lease_token,
                 state = 'leased', acquired_at = :now, heartbeat_at = :now, lease_expires_at = :lease_expires_at,
                 last_error = NULL, updated_at = :now
             WHERE resource_key = 'gpu:0' AND state = 'available'"
        );
        $stmt->execute([
            ':runtime_run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $gpuToken,
            ':now' => $now,
            ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
        ]);
        if ($stmt->rowCount() !== 1) {
            $db->exec('COMMIT');
            return null;
        }

        $lease = hub_runtime_gpu_fetch($db);
        $db->exec('COMMIT');
        return $lease;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_update_leased(PDO $db, array $lease, string $setSql, array $extra = []): bool
{
    $gpu = hub_runtime_gpu_lease_identity($lease);
    $stmt = $db->prepare(
        "UPDATE runtime_resource_leases SET {$setSql}
         WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
           AND lease_token = :lease_token AND state = 'leased'"
    );
    $stmt->execute($extra + [
        ':resource_key' => $gpu['resource_key'],
        ':runtime_run_id' => $gpu['runtime_run_id'],
        ':worker_id' => $gpu['worker_id'],
        ':lease_token' => $gpu['lease_token'],
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_gpu_release_in_transaction(PDO $db, array $lease): bool
{
    $now = hub_now();

    return hub_runtime_gpu_update_leased(
        $db,
        $lease,
        "runtime_run_id = NULL, worker_id = NULL, lease_token = NULL, state = 'available', acquired_at = NULL,
         heartbeat_at = NULL, lease_expires_at = NULL, last_error = NULL, updated_at = :now",
        [':now' => $now]
    );
}

function hub_runtime_gpu_release(PDO $db, array $lease): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_release_transaction_required');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $released = hub_runtime_gpu_release_in_transaction($db, $lease);
        if (!$released) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_heartbeat(PDO $db, array $run, array $lease, int $leaseSeconds): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_heartbeat_transaction_required');
    }
    if (!hub_runtime_gpu_fence_matches_run($run, $lease)) {
        return false;
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $expiresAt = hub_runtime_lease_until($leaseSeconds);

    $db->exec('BEGIN IMMEDIATE');
    try {
        $runtimeStmt = $db->prepare(
            "UPDATE runtime_runs SET heartbeat_at = :now, lease_expires_at = :lease_expires_at
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running')"
        );
        $runtimeStmt->execute([
            ':now' => $now,
            ':lease_expires_at' => $expiresAt,
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
        ]);
        $gpuHeartbeat = $runtimeStmt->rowCount() === 1 && hub_runtime_gpu_update_leased(
            $db,
            $lease,
            'heartbeat_at = :now, lease_expires_at = :lease_expires_at, updated_at = :now',
            [':now' => $now, ':lease_expires_at' => $expiresAt]
        );
        if (!$gpuHeartbeat) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_block(PDO $db, array $lease, string $error): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_block_transaction_required');
    }
    $error = substr(trim($error), 0, 512);
    $db->exec('BEGIN IMMEDIATE');
    try {
        $blocked = hub_runtime_gpu_update_leased(
            $db,
            $lease,
            "state = 'blocked', last_error = :last_error, updated_at = :now",
            [':last_error' => $error === '' ? 'blocked' : $error, ':now' => hub_now()]
        );
        if (!$blocked) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_expire(PDO $db, ?string $now = null): ?array
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_expire_transaction_required');
    }
    $now ??= hub_now();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $lease = $db->prepare(
            "SELECT * FROM runtime_resource_leases
             WHERE resource_key = 'gpu:0' AND state = 'leased' AND lease_expires_at IS NOT NULL AND lease_expires_at <= :now"
        );
        $lease->execute([':now' => $now]);
        $lease = $lease->fetch();
        if (!is_array($lease)) {
            $db->exec('COMMIT');
            return null;
        }
        $gpu = hub_runtime_gpu_lease_identity($lease);
        $stmt = $db->prepare(
            "UPDATE runtime_resource_leases
             SET state = 'recovery_required', last_error = 'lease_expired', updated_at = :now
             WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
               AND lease_token = :lease_token AND state = 'leased'"
        );
        $stmt->execute([
            ':now' => $now,
            ':resource_key' => $gpu['resource_key'],
            ':runtime_run_id' => $gpu['runtime_run_id'],
            ':worker_id' => $gpu['worker_id'],
            ':lease_token' => $gpu['lease_token'],
        ]);
        if ($stmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return null;
        }
        $lease['state'] = 'recovery_required';
        $lease['last_error'] = 'lease_expired';
        $lease['updated_at'] = $now;
        $db->exec('COMMIT');
        return $lease;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_recovery_update(PDO $db, array $lease, string $state, ?string $error): ?array
{
    $gpu = hub_runtime_gpu_lease_identity($lease);
    if (!in_array($state, ['available', 'blocked'], true)) {
        throw new InvalidArgumentException('runtime_gpu_recovery_state_invalid');
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $now = hub_now();
        $set = $state === 'available'
            ? "runtime_run_id = NULL, worker_id = NULL, lease_token = NULL, state = 'available', acquired_at = NULL,
               heartbeat_at = NULL, lease_expires_at = NULL, last_error = NULL, updated_at = :now"
            : "state = 'blocked', last_error = :last_error, updated_at = :now";
        $stmt = $db->prepare(
            "UPDATE runtime_resource_leases SET {$set}
             WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
               AND lease_token = :lease_token AND state = 'recovery_required'"
        );
        $params = [
            ':now' => $now,
            ':resource_key' => $gpu['resource_key'],
            ':runtime_run_id' => $gpu['runtime_run_id'],
            ':worker_id' => $gpu['worker_id'],
            ':lease_token' => $gpu['lease_token'],
        ];
        if ($state === 'blocked') {
            $params[':last_error'] = substr(trim((string)$error), 0, 512) ?: 'recovery_blocked';
        }
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return null;
        }
        $result = hub_runtime_gpu_fetch($db);
        $db->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_recovery_pids(mixed $pids): array
{
    if (!is_array($pids)) {
        return [];
    }
    $result = [];
    foreach ($pids as $pid) {
        if (filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $result[(int)$pid] = true;
        }
    }

    return array_keys($result);
}

function hub_runtime_gpu_recover(PDO $db, callable $inspector, ?callable $containerCleanup = null): ?array
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_recovery_transaction_required');
    }
    $lease = hub_runtime_gpu_fetch($db);
    if (!is_array($lease) || ($lease['state'] ?? '') !== 'recovery_required') {
        return null;
    }
    $gpu = hub_runtime_gpu_lease_identity($lease);
    $runStmt = $db->prepare('SELECT * FROM runtime_runs WHERE run_id = :run_id');
    $runStmt->execute([':run_id' => $gpu['runtime_run_id']]);
    $run = $runStmt->fetch();
    if (!is_array($run)) {
        return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'runtime_run_missing');
    }

    $evidence = $inspector($run, $lease);
    if (!is_array($evidence)) {
        return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'recovery_inspection_invalid');
    }
    if (!empty($evidence['container_exists']) || !empty($evidence['container_running'])) {
        if ($containerCleanup === null || !$containerCleanup($run, $lease, $evidence)) {
            return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'container_cleanup_failed');
        }
        $evidence = $inspector($run, $lease);
        if (!is_array($evidence)) {
            return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'recovery_inspection_invalid');
        }
    }
    if (!empty($evidence['container_exists']) || !empty($evidence['container_running'])) {
        return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'container_residue');
    }
    if (!empty($evidence['ambiguous'])) {
        return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'gpu_residue_ambiguous');
    }
    if (hub_runtime_gpu_recovery_pids($evidence['owned_gpu_pids'] ?? []) !== []) {
        return hub_runtime_gpu_recovery_update($db, $lease, 'blocked', 'owned_gpu_pid_residue');
    }

    return hub_runtime_gpu_recovery_update($db, $lease, 'available', null);
}

function hub_runtime_record_gpu_ownership(PDO $db, array $run, array $lease, ?string $containerId, array $baselinePids, array $ownedPids): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_record_transaction_required');
    }
    if (!hub_runtime_gpu_fence_matches_run($run, $lease)) {
        return false;
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $baseline = json_encode(hub_runtime_gpu_recovery_pids($baselinePids));
    $owned = json_encode(hub_runtime_gpu_recovery_pids($ownedPids));
    if ($baseline === false || $owned === false) {
        throw new InvalidArgumentException('runtime_gpu_pid_snapshot_invalid');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $runStmt = $db->prepare(
            "UPDATE runtime_runs
             SET container_id = :container_id, gpu_process_baseline_json = :baseline, owned_gpu_pids_json = :owned
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running')"
        );
        $runStmt->execute([
            ':container_id' => $containerId === null ? null : substr(trim($containerId), 0, 255),
            ':baseline' => $baseline,
            ':owned' => $owned,
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
        ]);
        $recorded = $runStmt->rowCount() === 1 && hub_runtime_gpu_update_leased(
            $db,
            $lease,
            'updated_at = :now',
            [':now' => hub_now()]
        );
        if (!$recorded) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_task_requires_gpu(array $task): bool
{
    return strtolower(trim((string)($task['accelerator'] ?? ''))) === 'gpu';
}

function hub_runtime_gpu_acquire_for_task(PDO $db, array $task, array $run, int $leaseSeconds): ?array
{
    return hub_runtime_task_requires_gpu($task) ? hub_runtime_gpu_acquire($db, $run, $leaseSeconds) : null;
}

function hub_runtime_gpu_probe(?callable $runner = null): array
{
    $runner ??= 'hub_run_command';
    $memory = $runner(['nvidia-smi', '--query-gpu=memory.free', '--format=csv,noheader,nounits'], 10);
    $processes = $runner(['nvidia-smi', '--query-compute-apps=pid', '--format=csv,noheader,nounits'], 10);
    if (($memory['exit_code'] ?? 1) !== 0 || ($processes['exit_code'] ?? 1) !== 0) {
        return ['free_vram_mb' => 0, 'processes' => [], 'probe_error' => 'gpu_probe_failed'];
    }
    $freeVram = 0;
    foreach (preg_split('/\R/', trim((string)($memory['stdout'] ?? ''))) ?: [] as $value) {
        if (is_numeric(trim($value))) {
            $freeVram += (int)trim($value);
        }
    }

    return [
        'free_vram_mb' => $freeVram,
        'processes' => hub_runtime_gpu_recovery_pids(preg_split('/\R/', trim((string)($processes['stdout'] ?? ''))) ?: []),
    ];
}

function hub_runtime_gpu_preflight_result(array $run, int $requiredVramMb, int $safetyMarginMb, array $probe): array
{
    $owned = json_decode((string)($run['owned_gpu_pids_json'] ?? ''), true);
    $owned = hub_runtime_gpu_recovery_pids($owned);
    $unmanaged = array_key_exists('unmanaged_pids', $probe)
        ? hub_runtime_gpu_recovery_pids($probe['unmanaged_pids'])
        : array_values(array_diff(hub_runtime_gpu_recovery_pids($probe['processes'] ?? []), $owned));
    if ($unmanaged !== []) {
        return ['ok' => false, 'reason' => 'unmanaged_gpu_process'];
    }
    $freeVram = (int)($probe['free_vram_mb'] ?? 0);
    if ($freeVram < max(0, $requiredVramMb) + max(0, $safetyMarginMb)) {
        return ['ok' => false, 'reason' => 'insufficient_vram'];
    }

    return ['ok' => true, 'reason' => null];
}

function hub_runtime_gpu_active(PDO $db, array $run, array $lease): bool
{
    if (!hub_runtime_gpu_fence_matches_run($run, $lease)) {
        return false;
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $gpu = hub_runtime_gpu_lease_identity($lease);
    $runStmt = $db->prepare(
        "SELECT 1 FROM runtime_runs
         WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
           AND state IN ('claimed', 'running')"
    );
    $runStmt->execute([
        ':run_id' => $runtime['run_id'],
        ':worker_id' => $runtime['worker_id'],
        ':lease_token' => $runtime['lease_token'],
    ]);
    if ($runStmt->fetchColumn() === false) {
        return false;
    }
    $leaseStmt = $db->prepare(
        "SELECT 1 FROM runtime_resource_leases
         WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
           AND lease_token = :lease_token AND state = 'leased'"
    );
    $leaseStmt->execute([
        ':resource_key' => $gpu['resource_key'],
        ':runtime_run_id' => $gpu['runtime_run_id'],
        ':worker_id' => $gpu['worker_id'],
        ':lease_token' => $gpu['lease_token'],
    ]);

    return $leaseStmt->fetchColumn() !== false;
}

function hub_runtime_gpu_start_allowed(PDO $db, array $run, array $lease, int $requiredVramMb, ?callable $probe = null, ?int $safetyMarginMb = null): bool
{
    if (!hub_runtime_gpu_active($db, $run, $lease)) {
        return false;
    }
    $probe ??= 'hub_runtime_gpu_probe';
    $margin = $safetyMarginMb ?? max(0, (int)hub_get_storage_setting($db, 'AIHUB_GPU_VRAM_SAFETY_MARGIN_MB'));

    return (bool)(hub_runtime_gpu_preflight_result($run, $requiredVramMb, $margin, $probe())['ok'] ?? false);
}

function hub_runtime_gpu_wait_for_capacity(PDO $db, int $taskId, array $run, array $lease, string $reason, int $backoffSeconds): array
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_wait_transaction_required');
    }
    if (!in_array($reason, ['insufficient_vram', 'unmanaged_gpu_process'], true) || !hub_runtime_gpu_fence_matches_run($run, $lease)) {
        return ['ok' => false, 'reason' => 'lost_gpu_lease'];
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $nextAttemptAt = hub_runtime_lease_until(max(1, $backoffSeconds));
    $db->exec('BEGIN IMMEDIATE');
    try {
        $runStmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'waiting_gpu', lease_expires_at = NULL, heartbeat_at = :now, error_code = NULL
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running')"
        );
        $runStmt->execute([
            ':now' => $now,
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
        ]);
        $taskStmt = $db->prepare(
            "UPDATE tasks
             SET status = 'waiting_gpu', waiting_reason = :reason, next_attempt_at = :next_attempt_at,
                 lock_token = NULL, updated_at = :now
             WHERE id = :id AND task_type = 'pack_job' AND status = 'running'"
        );
        $taskStmt->execute([
            ':reason' => $reason,
            ':next_attempt_at' => $nextAttemptAt,
            ':now' => $now,
            ':id' => $taskId,
        ]);
        $released = $runStmt->rowCount() === 1 && $taskStmt->rowCount() === 1 && hub_runtime_gpu_release_in_transaction($db, $lease);
        if (!$released) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'reason' => 'lost_gpu_lease'];
        }
        $db->exec('COMMIT');

        return ['ok' => false, 'reason' => $reason, 'next_attempt_at' => $nextAttemptAt];
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_runtime_gpu_preflight(PDO $db, int $taskId, array $run, array $lease, int $requiredVramMb, ?callable $probe = null, int $backoffSeconds = 30, ?int $safetyMarginMb = null): array
{
    if ($db->inTransaction()) {
        throw new LogicException('runtime_gpu_preflight_transaction_required');
    }
    if (!hub_runtime_gpu_active($db, $run, $lease)) {
        return ['ok' => false, 'reason' => 'lost_gpu_lease'];
    }
    $probe ??= 'hub_runtime_gpu_probe';
    $margin = $safetyMarginMb ?? max(0, (int)hub_get_storage_setting($db, 'AIHUB_GPU_VRAM_SAFETY_MARGIN_MB'));
    $result = hub_runtime_gpu_preflight_result($run, $requiredVramMb, $margin, $probe());
    if (!empty($result['ok'])) {
        return $result;
    }

    return hub_runtime_gpu_wait_for_capacity($db, $taskId, $run, $lease, (string)$result['reason'], $backoffSeconds);
}

function hub_runtime_fetch_run(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT * FROM runtime_runs WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function hub_runtime_claim_next(PDO $db, string $workerId, int $leaseSeconds): ?array
{
    $workerId = trim($workerId);
    if ($workerId === '') {
        throw new InvalidArgumentException('worker_id is required.');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $run = $db->query("SELECT * FROM runtime_runs WHERE state = 'queued' ORDER BY id ASC LIMIT 1")->fetch();
        if ($run === false) {
            $db->exec('COMMIT');
            return null;
        }

        $now = hub_now();
        $token = bin2hex(random_bytes(32));
        $stmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'claimed', worker_id = :worker_id, lease_token = :lease_token,
                 claimed_at = :now, heartbeat_at = :now, lease_expires_at = :lease_expires_at
             WHERE id = :id AND state = 'queued'"
        );
        $stmt->execute([
            ':worker_id' => $workerId,
            ':lease_token' => $token,
            ':now' => $now,
            ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
            ':id' => (int)$run['id'],
        ]);
        if ($stmt->rowCount() !== 1) {
            $db->exec('COMMIT');
            return null;
        }

        $claimed = hub_runtime_fetch_run($db, (int)$run['id']);
        $db->exec('COMMIT');
        return $claimed;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function hub_runtime_heartbeat(PDO $db, int $runId, string $leaseToken, int $leaseSeconds): bool
{
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET heartbeat_at = :now, lease_expires_at = :lease_expires_at
         WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
    );
    $stmt->execute([
        ':now' => hub_now(),
        ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_mark_running(PDO $db, int $runId, string $leaseToken): bool
{
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = 'running', started_at = :started_at
         WHERE id = :id AND lease_token = :lease_token AND state = 'claimed'"
    );
    $stmt->execute([
        ':started_at' => hub_now(),
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_finish(PDO $db, int $runId, string $leaseToken, string $state, array $result = []): bool
{
    if (!in_array($state, ['succeeded', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid runtime final state.');
    }

    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = :state, finished_at = :finished_at, error_code = :error_code, lease_expires_at = NULL
         WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')
           AND (:state != 'succeeded' OR cancel_requested_at IS NULL)"
    );
    $stmt->execute([
        ':state' => $state,
        ':finished_at' => hub_now(),
        ':error_code' => isset($result['error']) ? (string)$result['error'] : null,
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_request_cancel(PDO $db, int $runId, ?string $reason = null): bool
{
    $reason = $reason === null ? null : substr(trim($reason), 0, 512);
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET cancel_requested_at = :now, cancel_reason = :reason
         WHERE id = :id AND state IN ('claimed', 'running') AND cancel_requested_at IS NULL"
    );
    $stmt->execute([
        ':now' => hub_now(),
        ':reason' => $reason === '' ? null : $reason,
        ':id' => $runId,
    ]);
    if ($stmt->rowCount() === 1) {
        return true;
    }

    $row = hub_runtime_fetch_run($db, $runId);
    return $row !== null
        && in_array((string)$row['state'], ['claimed', 'running'], true)
        && !empty($row['cancel_requested_at']);
}

function hub_runtime_is_cancel_requested(PDO $db, int $runId): bool
{
    $stmt = $db->prepare('SELECT cancel_requested_at FROM runtime_runs WHERE id = :id');
    $stmt->execute([':id' => $runId]);
    $value = $stmt->fetchColumn();

    return is_string($value) && $value !== '';
}

function hub_runtime_mark_cancelled(PDO $db, int $runId, string $leaseToken): bool
{
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = 'cancelled', cancelled_at = :now, finished_at = :now, lease_expires_at = NULL
         WHERE id = :id
           AND lease_token = :lease_token
           AND state IN ('claimed', 'running')
           AND cancel_requested_at IS NOT NULL"
    );
    $stmt->execute([
        ':now' => hub_now(),
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_mark_timed_out(PDO $db, int $runId, string $leaseToken, ?string $now = null): bool
{
    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = 'timed_out', finished_at = :now, lease_expires_at = NULL
         WHERE id = :id
           AND lease_token = :lease_token
           AND state IN ('claimed', 'running')
           AND cancel_requested_at IS NULL
           AND timeout_at IS NOT NULL
           AND timeout_at <= :now"
    );
    $stmt->execute([
        ':now' => $now ?? hub_now(),
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_find_stale(PDO $db, ?string $now = null): array
{
    $stmt = $db->prepare(
        "SELECT * FROM runtime_runs
         WHERE state IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at <= :now
         ORDER BY lease_expires_at ASC, id ASC"
    );
    $stmt->execute([':now' => $now ?? hub_now()]);

    return $stmt->fetchAll();
}

function hub_runtime_takeover_stale(PDO $db, int $runId, string $workerId, int $leaseSeconds): ?array
{
    $workerId = trim($workerId);
    if ($workerId === '') {
        throw new InvalidArgumentException('worker_id is required.');
    }

    $db->exec('BEGIN IMMEDIATE');
    try {
        $run = hub_runtime_fetch_run($db, $runId);
        if ($run === null) {
            $db->exec('COMMIT');
            return null;
        }

        $now = hub_now();
        $token = bin2hex(random_bytes(32));
        $reason = json_encode([
            'previous_worker_id' => $run['worker_id'] ?? null,
            'recovery_worker_id' => $workerId,
            'reason' => 'lease_expired',
        ], JSON_UNESCAPED_SLASHES);

        $stmt = $db->prepare(
            "UPDATE runtime_runs
             SET worker_id = :worker_id, lease_token = :lease_token,
                 heartbeat_at = :now, lease_expires_at = :lease_expires_at,
                 recovery_count = COALESCE(recovery_count, 0) + 1,
                 last_recovered_at = :now, last_recovery_reason = :reason
             WHERE id = :id
               AND state IN ('claimed', 'running')
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at <= :now"
        );
        $stmt->execute([
            ':worker_id' => $workerId,
            ':lease_token' => $token,
            ':now' => $now,
            ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
            ':reason' => $reason,
            ':id' => $runId,
        ]);

        if ($stmt->rowCount() !== 1) {
            $db->exec('COMMIT');
            return null;
        }

        $recovered = hub_runtime_fetch_run($db, $runId);
        $db->exec('COMMIT');
        return $recovered;
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

function hub_runtime_recovery_decision(array $run, array $evidence): array
{
    $result = hub_runtime_decode_recovery_result($evidence['result_json'] ?? null);
    $resultStatus = strtolower((string)($result['status'] ?? $result['state'] ?? ''));
    $exitCode = array_key_exists('process_exit_code', $evidence) ? $evidence['process_exit_code'] : null;

    if (($evidence['runtime_alive'] ?? null) === true) {
        return hub_runtime_recovery_decision_row('running', null, 'runtime_alive');
    }

    if ($exitCode !== null && (int)$exitCode !== 0 && $resultStatus === 'succeeded') {
        return hub_runtime_recovery_decision_row('failed', 'runtime_state_conflict', 'runtime_state_conflict');
    }

    if ($resultStatus === 'failed') {
        $errorCode = (string)($result['error_code'] ?? $result['error'] ?? 'runtime_reported_failed');
        return hub_runtime_recovery_decision_row('failed', $errorCode, 'result_json_failed');
    }

    if ($exitCode !== null && (int)$exitCode === 0) {
        if ($resultStatus === 'succeeded' && ($evidence['required_artifacts_valid'] ?? false) === true) {
            return hub_runtime_recovery_decision_row('succeeded', null, 'output_contract_valid');
        }

        return hub_runtime_recovery_decision_row('failed', 'output_contract_invalid', 'output_contract_invalid');
    }

    if ($exitCode !== null && (int)$exitCode !== 0) {
        return hub_runtime_recovery_decision_row('failed', 'runtime_exit_nonzero', 'runtime_exit_nonzero');
    }

    if (($evidence['runtime_alive'] ?? null) === false) {
        return hub_runtime_recovery_decision_row('failed', 'runtime_lost', 'runtime_lost');
    }

    return hub_runtime_recovery_decision_row('failed', 'recovery_evidence_insufficient', 'recovery_evidence_insufficient');
}

function hub_runtime_apply_recovery(PDO $db, int $runId, string $leaseToken, array $decision): bool
{
    $state = (string)($decision['state'] ?? '');
    if (!in_array($state, ['running', 'succeeded', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid runtime recovery state.');
    }

    $now = hub_now();
    $reason = json_encode(['decision' => $decision], JSON_UNESCAPED_SLASHES);
    $errorCode = $state === 'failed' ? (string)($decision['error_code'] ?? 'recovery_failed') : null;

    if ($state === 'running') {
        $stmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'running', error_code = NULL,
                 last_recovered_at = :now, last_recovery_reason = :reason
             WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
        );
        $stmt->execute([
            ':now' => $now,
            ':reason' => $reason,
            ':id' => $runId,
            ':lease_token' => $leaseToken,
        ]);

        return $stmt->rowCount() === 1;
    }

    $stmt = $db->prepare(
        "UPDATE runtime_runs
         SET state = :state, finished_at = :now, error_code = :error_code,
             lease_expires_at = NULL, last_recovered_at = :now, last_recovery_reason = :reason
         WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
    );
    $stmt->execute([
        ':state' => $state,
        ':now' => $now,
        ':error_code' => $errorCode,
        ':reason' => $reason,
        ':id' => $runId,
        ':lease_token' => $leaseToken,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_runtime_decode_recovery_result(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function hub_runtime_recovery_decision_row(string $state, ?string $errorCode, string $reason): array
{
    return [
        'state' => $state,
        'error_code' => $errorCode,
        'reason' => $reason,
    ];
}
