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

