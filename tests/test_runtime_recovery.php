<?php
declare(strict_types=1);

function hub_test_recovery_insert_run(PDO $db, string $runId, string $state = 'queued'): array
{
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, pack_version, runner_version, caller, workspace, state, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :pack_version, :runner_version, :caller, :workspace, :state, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'yolo',
        ':task' => 'yolo_predict',
        ':pack_version' => '0.1.0',
        ':runner_version' => 'test',
        ':caller' => 'test',
        ':workspace' => sys_get_temp_dir() . '/' . $runId,
        ':state' => $state,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    $row = $db->query("SELECT * FROM runtime_runs WHERE run_id = " . $db->quote($runId))->fetch();
    return is_array($row) ? $row : [];
}

function hub_test_recovery_expire(PDO $db, int $runId): void
{
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE id = :id')
        ->execute([':expired' => date('Y-m-d H:i:s', time() - 60), ':id' => $runId]);
}

hub_test('PhaseRuntime-2B stale claimed and running runs can be taken over atomically', function (): void {
    $db = hub_test_reset_db();
    hub_test_recovery_insert_run($db, 'run_recover_claimed');
    hub_test_recovery_insert_run($db, 'run_recover_running');

    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    $running = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null && $running !== null, 'fixtures must claim');
    hub_test_assert(hub_runtime_mark_running($db, (int)$running['id'], (string)$running['lease_token']), 'running fixture must mark running');
    hub_test_recovery_expire($db, (int)$claimed['id']);
    hub_test_recovery_expire($db, (int)$running['id']);

    $recoveredClaimed = hub_runtime_takeover_stale($db, (int)$claimed['id'], 'worker-b', 60);
    $recoveredRunning = hub_runtime_takeover_stale($db, (int)$running['id'], 'worker-b', 60);
    hub_test_assert($recoveredClaimed !== null && (string)$recoveredClaimed['worker_id'] === 'worker-b', 'claimed takeover failed');
    hub_test_assert($recoveredRunning !== null && (string)$recoveredRunning['worker_id'] === 'worker-b', 'running takeover failed');
    hub_test_assert((string)$recoveredClaimed['lease_token'] !== (string)$claimed['lease_token'], 'takeover must replace lease token');
    hub_test_assert((int)$recoveredClaimed['recovery_count'] === 1, 'recovery_count must increment');
    hub_test_assert(!hub_runtime_heartbeat($db, (int)$claimed['id'], (string)$claimed['lease_token'], 60), 'old lease token must be invalid after takeover');
});

hub_test('PhaseRuntime-2B takeover rejects unexpired final and already recovered runs', function (): void {
    $db = hub_test_reset_db();
    hub_test_recovery_insert_run($db, 'run_recover_fresh');
    $fresh = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($fresh !== null, 'fresh fixture must claim');
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$fresh['id'], 'worker-b', 60) === null, 'unexpired run must not be taken over');

    hub_test_recovery_expire($db, (int)$fresh['id']);
    $first = hub_runtime_takeover_stale($db, (int)$fresh['id'], 'worker-b', 60);
    $second = hub_runtime_takeover_stale($db, (int)$fresh['id'], 'worker-c', 60);
    hub_test_assert($first !== null && $second === null, 'only one recovery worker may take over');

    $done = hub_test_recovery_insert_run($db, 'run_recover_done', 'succeeded');
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$done['id'], 'worker-b', 60) === null, 'final state must not be recovered');
});

hub_test('PhaseRuntime-2B recovery decision is conservative and explainable', function (): void {
    $run = ['run_id' => 'run_decision'];
    $cases = [
        [
            ['runtime_alive' => true],
            ['state' => 'running', 'error_code' => null],
        ],
        [
            ['process_exit_code' => 0, 'result_json' => ['status' => 'succeeded'], 'required_artifacts_valid' => true],
            ['state' => 'succeeded', 'error_code' => null],
        ],
        [
            ['result_json' => ['status' => 'failed', 'error_code' => 'model_failed']],
            ['state' => 'failed', 'error_code' => 'model_failed'],
        ],
        [
            ['process_exit_code' => 7, 'result_json' => ['status' => 'succeeded']],
            ['state' => 'failed', 'error_code' => 'runtime_state_conflict'],
        ],
        [
            ['process_exit_code' => 7],
            ['state' => 'failed', 'error_code' => 'runtime_exit_nonzero'],
        ],
        [
            ['process_exit_code' => 0],
            ['state' => 'failed', 'error_code' => 'output_contract_invalid'],
        ],
        [
            ['runtime_alive' => false],
            ['state' => 'failed', 'error_code' => 'runtime_lost'],
        ],
        [
            [],
            ['state' => 'failed', 'error_code' => 'recovery_evidence_insufficient'],
        ],
    ];

    foreach ($cases as [$evidence, $expected]) {
        $decision = hub_runtime_recovery_decision($run, $evidence);
        hub_test_assert($decision['state'] === $expected['state'], 'decision state mismatch for ' . json_encode($evidence));
        hub_test_assert(($decision['error_code'] ?? null) === $expected['error_code'], 'decision error mismatch for ' . json_encode($evidence));
        hub_test_assert((string)($decision['reason'] ?? '') !== '', 'decision reason must be traceable');
    }
});

hub_test('PhaseRuntime-2B apply recovery requires ownership and records decision', function (): void {
    $db = hub_test_reset_db();
    hub_test_recovery_insert_run($db, 'run_apply_recovery');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'fixture must claim');
    hub_test_recovery_expire($db, (int)$claimed['id']);
    $recovered = hub_runtime_takeover_stale($db, (int)$claimed['id'], 'worker-b', 60);
    hub_test_assert($recovered !== null, 'fixture must recover');

    $decision = hub_runtime_recovery_decision($recovered, [
        'process_exit_code' => 0,
        'result_json' => ['status' => 'succeeded'],
        'required_artifacts_valid' => true,
    ]);
    hub_test_assert(!hub_runtime_apply_recovery($db, (int)$recovered['id'], (string)$claimed['lease_token'], $decision), 'old token must not apply recovery');
    hub_test_assert(hub_runtime_apply_recovery($db, (int)$recovered['id'], (string)$recovered['lease_token'], $decision), 'new token must apply recovery');

    $row = $db->query("SELECT state, error_code, last_recovery_reason FROM runtime_runs WHERE run_id = 'run_apply_recovery'")->fetch();
    hub_test_assert((string)$row['state'] === 'succeeded', 'apply recovery state mismatch');
    hub_test_assert($row['error_code'] === null, 'succeeded recovery must not keep error_code');
    hub_test_assert(str_contains((string)$row['last_recovery_reason'], 'succeeded'), 'recovery reason must include decision');
});

