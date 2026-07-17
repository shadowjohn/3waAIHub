<?php
declare(strict_types=1);

function hub_runtime_lease_until(int $leaseSeconds): string
{
    return date('Y-m-d H:i:s', time() + max(1, $leaseSeconds));
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
            $db->commit();
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
            $db->commit();
            return null;
        }

        $claimed = hub_runtime_fetch_run($db, (int)$run['id']);
        $db->commit();
        return $claimed;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
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
         SET state = :state, finished_at = :finished_at, error_code = :error_code
         WHERE id = :id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
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
            $db->commit();
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
            $db->commit();
            return null;
        }

        $recovered = hub_runtime_fetch_run($db, $runId);
        $db->commit();
        return $recovered;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
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
             last_recovered_at = :now, last_recovery_reason = :reason
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
