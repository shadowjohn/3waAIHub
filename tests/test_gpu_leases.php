<?php
declare(strict_types=1);

function hub_test_gpu_lease_run(PDO $db, string $runId, string $workerId = 'gpu-worker'): array
{
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, workspace, state, worker_id, lease_token, lease_expires_at, task_id, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :workspace, :state, :worker_id, :lease_token, :lease_expires_at, :task_id, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'gpu-test',
        ':task' => 'test',
        ':workspace' => sys_get_temp_dir() . '/' . $runId,
        ':state' => 'claimed',
        ':worker_id' => $workerId,
        ':lease_token' => bin2hex(random_bytes(32)),
        ':lease_expires_at' => hub_runtime_lease_until(60),
        ':task_id' => null,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    return $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($runId))->fetch() ?: [];
}

function hub_test_gpu_recovery_evidence(bool $containerExists = false, bool $containerRunning = false, array $ownedPids = [], bool $ambiguous = false): array
{
    return [
        'container' => ['exists' => $containerExists, 'running' => $containerRunning],
        'owned_pids' => $ownedPids,
        'ambiguous' => $ambiguous,
    ];
}

function hub_test_gpu_lease_expire(PDO $db, array $lease): void
{
    $db->prepare(
        'UPDATE runtime_resource_leases SET lease_expires_at = :expires_at
         WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND lease_token = :lease_token'
    )->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() - 60),
        ':resource_key' => $lease['resource_key'],
        ':runtime_run_id' => $lease['runtime_run_id'],
        ':lease_token' => $lease['lease_token'],
    ]);
}

hub_test('GPU lease acquires once for gpu:0 and uses the runtime text run id', function (): void {
    $db = hub_test_reset_db();
    $first = hub_test_gpu_lease_run($db, 'gpu_race_a', 'worker-a');
    $second = hub_test_gpu_lease_run($db, 'gpu_race_b', 'worker-b');

    $firstLease = hub_runtime_gpu_acquire($db, $first, 60);
    $secondLease = hub_runtime_gpu_acquire($db, $second, 60);

    hub_test_assert(is_array($firstLease) && $secondLease === null, 'gpu:0 must lease to exactly one claimant');
    hub_test_assert(($firstLease['runtime_run_id'] ?? '') === 'gpu_race_a', 'resource lease must use immutable text run_id');
    hub_test_assert(($firstLease['lease_token'] ?? '') === ($first['lease_token'] ?? ''), 'GPU lease must share the runtime current fence token');
});

hub_test('GPU lease rejects non-owner heartbeat release and block operations', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_nonowner');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $other = $lease;
    $other['worker_id'] = 'other-worker';

    hub_test_assert(!hub_runtime_gpu_heartbeat($db, $run, $other, 60), 'non-owner heartbeat must fail');
    hub_test_assert(!hub_runtime_gpu_release($db, $run, $other), 'non-owner release must fail');
    hub_test_assert(!hub_runtime_gpu_block($db, $run, $other, 'test_block'), 'non-owner block must fail');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'non-owner actions must not change lease state');
});

hub_test('GPU and runtime heartbeats roll back together on an invalid GPU fence', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_heartbeat_atomic');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $db->prepare("UPDATE runtime_runs SET heartbeat_at = '2000-01-01 00:00:00' WHERE run_id = :run_id")->execute([':run_id' => $run['run_id']]);
    $bad = $lease;
    $bad['lease_token'] = 'lost-fence';

    hub_test_assert(!hub_runtime_gpu_heartbeat($db, $run, $bad, 60), 'invalid GPU heartbeat must fail');
    hub_test_assert((string)$db->query('SELECT heartbeat_at FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetchColumn() === '2000-01-01 00:00:00', 'runtime heartbeat must roll back with GPU heartbeat');
});

hub_test('GPU release rejects a runtime lease that was taken over', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_release_takeover', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$run['id'], 'worker-b', 60) !== null, 'fixture must take over runtime lease');

    hub_test_assert(!hub_runtime_gpu_release($db, $run, $lease), 'old runtime owner must not release GPU after takeover');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'stale release must leave GPU unchanged');
    hub_test_assert(!hub_runtime_gpu_block($db, $run, $lease, 'stale-worker'), 'old runtime owner must not block GPU after takeover');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'stale block must leave GPU unchanged');
});

hub_test('Expired GPU leases enter recovery_required and cannot be rented immediately', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_expired');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);

    $expired = hub_runtime_gpu_expire($db);
    hub_test_assert(is_array($expired) && ($expired['state'] ?? '') === 'recovery_required', 'expired lease must fence into recovery');
    hub_test_assert(hub_runtime_gpu_acquire($db, hub_test_gpu_lease_run($db, 'gpu_waiting'), 60) === null, 'recovery-required GPU must not be re-rented');
});

hub_test('GPU recovery reopens only clean residue and blocks stuck or ambiguous residue', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_clean_recovery');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'clean fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_runtime_gpu_expire($db);
    $clean = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence());
    hub_test_assert(($clean['state'] ?? '') === 'available', 'clean recovery must reopen GPU');

    $stuckRun = hub_test_gpu_lease_run($db, 'gpu_stuck_recovery');
    $stuckLease = hub_runtime_gpu_acquire($db, $stuckRun, 60);
    hub_test_assert(is_array($stuckLease), 'stuck fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $stuckLease);
    hub_runtime_gpu_expire($db);
    $stuck = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence(false, false, [1234]));
    hub_test_assert(($stuck['state'] ?? '') === 'blocked', 'owned PID residue must block GPU');

    $db->prepare("UPDATE runtime_resource_leases SET state = 'available', runtime_run_id = NULL, worker_id = NULL, lease_token = NULL WHERE resource_key = 'gpu:0'")->execute();
    $ambiguousRun = hub_test_gpu_lease_run($db, 'gpu_ambiguous_recovery');
    $ambiguousLease = hub_runtime_gpu_acquire($db, $ambiguousRun, 60);
    hub_test_assert(is_array($ambiguousLease), 'ambiguous fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $ambiguousLease);
    hub_runtime_gpu_expire($db);
    $ambiguous = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence(false, false, [], true));
    hub_test_assert(($ambiguous['state'] ?? '') === 'blocked', 'ambiguous residue must block GPU');
});

hub_test('GPU recovery blocks a lease whose runtime ownership was taken over without inspection', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_recovery_takeover', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$run['id'], 'worker-b', 60) !== null, 'fixture must take over runtime');
    $inspected = false;
    $recovered = hub_runtime_gpu_recover($db, static function () use (&$inspected): array {
        $inspected = true;
        return hub_test_gpu_recovery_evidence();
    });

    hub_test_assert(!$inspected, 'takeover mismatch must not inspect or clean another owner run');
    hub_test_assert(($recovered['state'] ?? '') === 'blocked' && ($recovered['last_error'] ?? '') === 'runtime_ownership_conflict', 'takeover mismatch must block GPU safely');
});

hub_test('GPU recovery reopens clean residue when both the runtime and GPU leases expired together', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_recovery_runtime_expired', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    $recovered = hub_runtime_gpu_recover($db, static function (): array {
        return hub_test_gpu_recovery_evidence();
    });

    hub_test_assert(($recovered['state'] ?? '') === 'available', 'matching expired runtime ownership with no residue must reopen GPU');
});

hub_test('GPU recovery blocks unavailable malformed or incomplete inspector evidence', function (): void {
    foreach ([
        [],
        ['container' => ['exists' => false, 'running' => false], 'owned_pids' => []],
        ['container' => ['exists' => 'no', 'running' => false], 'owned_pids' => [], 'ambiguous' => false],
        ['container' => ['exists' => false, 'running' => false], 'owned_pids' => ['not-a-pid'], 'ambiguous' => false],
    ] as $evidence) {
        $db = hub_test_reset_db();
        $run = hub_test_gpu_lease_run($db, 'gpu_bad_evidence_' . bin2hex(random_bytes(3)));
        $lease = hub_runtime_gpu_acquire($db, $run, 60);
        hub_test_assert(is_array($lease), 'fixture must acquire GPU');
        hub_test_gpu_lease_expire($db, $lease);
        hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');

        $result = hub_runtime_gpu_recover($db, static fn (): array => $evidence);
        hub_test_assert(($result['state'] ?? '') === 'blocked', 'malformed recovery evidence must block GPU');
    }
});

hub_test('GPU recovery blocks inspector and cleanup callback failures without further actions', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_inspector');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'throwing inspector fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'throwing inspector fixture must require recovery');
    $cleanupCalled = false;
    $inspectorResult = hub_runtime_gpu_recover(
        $db,
        static function (): array {
            throw new RuntimeException('inspector transport failed');
        },
        static function () use (&$cleanupCalled): bool {
            $cleanupCalled = true;
            return true;
        }
    );
    hub_test_assert(($inspectorResult['state'] ?? '') === 'blocked' && ($inspectorResult['last_error'] ?? '') === 'recovery_inspection_failed', 'throwing inspector must block the exact recovery lease');
    hub_test_assert(!$cleanupCalled, 'throwing inspector must not invoke cleanup afterward');

    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_cleanup');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'throwing cleanup fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'throwing cleanup fixture must require recovery');
    $inspections = 0;
    $cleanupResult = hub_runtime_gpu_recover(
        $db,
        static function () use (&$inspections): array {
            $inspections++;
            return hub_test_gpu_recovery_evidence(true);
        },
        static function (): bool {
            throw new RuntimeException('container removal failed');
        }
    );
    hub_test_assert(($cleanupResult['state'] ?? '') === 'blocked' && ($cleanupResult['last_error'] ?? '') === 'container_cleanup_failed', 'throwing cleanup must block the exact recovery lease');
    hub_test_assert($inspections === 1, 'throwing cleanup must not inspect or act again afterward');

    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_inspector_fence_lost');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'lost blocking fence fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'lost blocking fence fixture must require recovery');
    $error = null;
    try {
        hub_runtime_gpu_recover($db, static function () use ($db): array {
            $db->exec("UPDATE runtime_resource_leases SET lease_token = 'new-recovery-owner' WHERE resource_key = 'gpu:0'");
            throw new RuntimeException('inspector lost its exact recovery fence');
        });
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    hub_test_assert($error === 'inspector lost its exact recovery fence', 'callback error may escape only after its exact blocking fence is lost');
});

hub_test('GPU acquire rejects an expired runtime lease', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_acquire_expired');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);

    hub_test_assert(hub_runtime_gpu_acquire($db, $run, 60) === null, 'expired runtime owner must not acquire GPU');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'expired acquisition must leave GPU available');
});

hub_test('GPU preflight waits with a stable reason and releases the lease without probing hardware in tests', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-task-lock' WHERE id = :id")->execute([':id' => $taskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_low_vram');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id WHERE run_id = :run_id')->execute([':task_id' => $taskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');

    $result = hub_runtime_gpu_preflight($db, $taskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 900, 'processes' => []], 15, 256);
    $task = hub_get_task($db, $taskId);
    hub_test_assert(($result['reason'] ?? '') === 'insufficient_vram' && ($task['status'] ?? '') === 'waiting_gpu', 'low VRAM must wait with stable reason');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'preflight wait must release GPU');
    hub_test_assert(!empty($task['next_attempt_at']), 'preflight wait must schedule a backoff');

    $db->prepare("UPDATE tasks SET status = 'running', waiting_reason = NULL WHERE id = :id")->execute([':id' => $taskId]);
    $db->prepare("UPDATE runtime_runs SET state = 'claimed', lease_token = :token, worker_id = :worker, lease_expires_at = :lease_expires_at WHERE run_id = :run_id")->execute([
        ':token' => bin2hex(random_bytes(32)),
        ':worker' => 'gpu-worker',
        ':lease_expires_at' => hub_runtime_lease_until(60),
        ':run_id' => $run['run_id'],
    ]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    $result = hub_runtime_gpu_preflight($db, $taskId, $run, $lease ?: [], 1, static fn (): array => ['free_vram_mb' => 4096, 'processes' => [991]], 15, 256);
    hub_test_assert(($result['reason'] ?? '') === 'unmanaged_gpu_process', 'unmanaged GPU processes must wait without being killed');
});

hub_test('GPU preflight cannot move a different task into waiting_gpu', function (): void {
    $db = hub_test_reset_db();
    $ownerTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $otherTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-owner' WHERE id = :id")->execute([':id' => $ownerTaskId]);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-other' WHERE id = :id")->execute([':id' => $otherTaskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_cross_task');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id WHERE run_id = :run_id')->execute([':task_id' => $ownerTaskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');

    $result = hub_runtime_gpu_preflight($db, $otherTaskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 0, 'processes' => []], 15, 256);
    hub_test_assert(($result['reason'] ?? '') === 'lost_gpu_lease', 'cross-task preflight must lose its fence');
    hub_test_assert((hub_get_task($db, $otherTaskId)['status'] ?? '') === 'running', 'cross-task preflight must not change another task');
    hub_test_assert((string)$db->query('SELECT state FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetchColumn() === 'claimed', 'cross-task preflight must not change runtime state');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'cross-task preflight must not release GPU');
});

hub_test('Due waiting_gpu Pack task promotes once and is claimable without changing runtime attempt', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-retry-lock' WHERE id = :id")->execute([':id' => $taskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_waiting_retry');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id, attempt_no = 7 WHERE run_id = :run_id')->execute([':task_id' => $taskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_runtime_gpu_preflight($db, $taskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 0, 'processes' => []], 1, 256);
    $db->prepare('UPDATE tasks SET next_attempt_at = :past WHERE id = :id')->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $taskId]);

    hub_test_assert(hub_promote_due_waiting_gpu_task($db), 'one due waiting GPU task must promote');
    hub_test_assert(!hub_promote_due_waiting_gpu_task($db), 'promoted task must not promote twice');
    $task = hub_get_task($db, $taskId);
    $retryRun = $db->query('SELECT state, worker_id, lease_token, attempt_no FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    hub_test_assert(($task['status'] ?? '') === 'queued' && empty($task['lock_token']) && empty($task['next_attempt_at']), 'promotion must restore queued task without a lock');
    hub_test_assert(($retryRun['state'] ?? '') === 'queued' && $retryRun['worker_id'] === null && $retryRun['lease_token'] === null && (int)$retryRun['attempt_no'] === 7, 'promotion must clear runtime lease without changing attempt');
    $claimed = hub_claim_next_task($db, ['pack_job']);
    hub_test_assert((int)($claimed['id'] ?? 0) === $taskId, 'promoted task must be claimable once');

    $db->prepare("UPDATE tasks SET status = 'waiting_gpu', next_attempt_at = :past WHERE id = :id")->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $taskId]);
    $db->prepare("UPDATE runtime_runs SET state = 'waiting_gpu' WHERE run_id = :run_id")->execute([':run_id' => $run['run_id']]);
    $db->prepare("UPDATE runtime_resource_leases SET state = 'blocked' WHERE resource_key = 'gpu:0'")->execute();
    hub_test_assert(!hub_promote_due_waiting_gpu_task($db), 'blocked GPU must not promote waiting work');
    hub_test_assert((hub_get_task($db, $taskId)['status'] ?? '') === 'waiting_gpu', 'blocked GPU must leave waiting task unchanged');
});

hub_test('CPU ffmpeg tasks do not request a GPU lease', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'cpu_ffmpeg');
    hub_test_assert(!hub_runtime_task_requires_gpu(['runtime_mode' => 'ffmpeg', 'accelerator' => 'cpu']), 'CPU ffmpeg must not require GPU');
    hub_test_assert(hub_runtime_gpu_acquire_for_task($db, ['runtime_mode' => 'ffmpeg', 'accelerator' => 'cpu'], $run, 60) === null, 'CPU ffmpeg must not acquire GPU');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'CPU ffmpeg must leave GPU available');
});

hub_test('GPU Pack terminal completion releases only its exact GPU fence atomically', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'terminal fixture must acquire GPU');
    $bad = $lease;
    $bad['lease_token'] = 'lost-gpu-fence';

    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted(), $bad)), 'terminal fence must roll back when GPU lease was lost');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$run['id'])->fetchColumn() === 'running', 'lost GPU fence must roll back terminal task and run');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted(), $lease);
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'terminal completion must release exact GPU lease');
});

hub_test('GPU Pack stale ownership cannot publish artifacts before terminal fencing', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'success fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
    $bad = $lease;
    $bad['lease_token'] = 'lost-gpu-fence';

    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $run, $validated, hub_test_pack_job_cleanup_asserted(), $bad)), 'stale GPU ownership must reject Pack success');
    hub_test_assert(!is_dir(hub_task_result_dir($fixture['task_id']) . '/artifacts'), 'stale GPU ownership must not publish artifacts before terminal fencing');
});

hub_test('GPU Pack fence loss after artifact staging removes only its staged handoff without DB success', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'handoff fixture must acquire GPU');
    hub_test_assert(is_string(hub_pack_job_handoff_scope($run, $lease)), 'GPU handoff scope must bind the active runtime fence');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $result = hub_commit_pack_job_success(
        $db,
        $fixture['task_id'],
        $run,
        $validated,
        hub_test_pack_job_cleanup_asserted(),
        $lease,
        static function () use ($db): void {
            $db->exec("UPDATE runtime_resource_leases SET lease_token = 'stale-after-stage' WHERE resource_key = 'gpu:0'");
        }
    );
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert(($result['ok'] ?? true) === false && ($result['error_code'] ?? '') === 'gpu_ownership_conflict', 'post-stage GPU fence loss must not succeed');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'post-stage fence loss must not register artifacts');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'post-stage fence loss must not enqueue callbacks');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'post-stage fence loss must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack inner terminal fence loss removes its staged handoff', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'inner terminal fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $error = null;
    try {
        hub_commit_pack_job_success(
            $db,
            $fixture['task_id'],
            $run,
            $validated,
            hub_test_pack_job_cleanup_asserted(),
            $lease,
            null,
            static function () use ($db): void {
                $db->exec("UPDATE runtime_resource_leases SET lease_token = 'stale-inside-terminal' WHERE resource_key = 'gpu:0'");
            }
        );
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert($error === 'gpu_ownership_conflict', 'inner terminal fence loss must preserve its ownership error');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running', 'inner terminal fence loss must not terminalize its task');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'inner terminal fence loss must not register artifacts');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'inner terminal fence loss must not enqueue callbacks');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'inner terminal fence loss must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack staged handoff failure discards only its lease-scoped directory', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'handoff failure fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $result = hub_commit_pack_job_success(
        $db,
        $fixture['task_id'],
        $run,
        $validated,
        hub_test_pack_job_cleanup_asserted(),
        $lease,
        static function (): void {
            throw new RuntimeException('handoff hook failed');
        }
    );
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert(($result['ok'] ?? true) === false && ($result['error_code'] ?? '') === 'output_contract_invalid', 'handoff failure must preserve its terminal outcome');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'handoff failure must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack cleanup failure terminalizes with cleanup_failed and blocks the exact GPU lease', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'cleanup fixture must acquire GPU');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', [], $lease);
    $task = hub_get_task($db, $fixture['task_id']);
    $resource = $db->query("SELECT state, last_error FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetch();
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'cleanup failure must terminalize as cleanup_failed');
    hub_test_assert(($resource['state'] ?? '') === 'blocked' && ($resource['last_error'] ?? '') === 'cleanup_failed', 'cleanup failure must block GPU instead of releasing it');
});
