<?php
declare(strict_types=1);

function hub_test_cancel_insert_run(PDO $db, string $runId, string $state = 'queued'): array
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

    $row = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($runId))->fetch();
    return is_array($row) ? $row : [];
}

function hub_test_cancel_row(PDO $db, string $runId): array
{
    $row = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($runId))->fetch();
    return is_array($row) ? $row : [];
}

function hub_test_cancel_set_timeout(PDO $db, int $runId, string $at): void
{
    $db->prepare('UPDATE runtime_runs SET timeout_at = :timeout_at WHERE id = :id')
        ->execute([':timeout_at' => $at, ':id' => $runId]);
}

hub_test('PhaseRuntime-2C1 cancel request is idempotent for claimed and running runs', function (): void {
    $db = hub_test_reset_db();
    hub_test_cancel_insert_run($db, 'run_cancel_claimed');
    hub_test_cancel_insert_run($db, 'run_cancel_running');

    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    $running = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null && $running !== null, 'fixtures must claim');
    hub_test_assert(hub_runtime_mark_running($db, (int)$running['id'], (string)$running['lease_token']), 'running fixture must mark running');

    hub_test_assert(hub_runtime_request_cancel($db, (int)$claimed['id'], 'operator stop'), 'claimed cancel request must pass');
    hub_test_assert(hub_runtime_is_cancel_requested($db, (int)$claimed['id']), 'claimed cancel flag must be visible');
    $first = hub_test_cancel_row($db, 'run_cancel_claimed');
    hub_test_assert(hub_runtime_request_cancel($db, (int)$claimed['id'], 'overwrite attempt'), 'duplicate cancel request must be idempotent');
    $second = hub_test_cancel_row($db, 'run_cancel_claimed');
    hub_test_assert((string)$first['cancel_requested_at'] === (string)$second['cancel_requested_at'], 'duplicate cancel must keep first timestamp');
    hub_test_assert((string)$second['cancel_reason'] === 'operator stop', 'duplicate cancel must keep first reason');

    hub_test_assert(hub_runtime_request_cancel($db, (int)$running['id'], 'operator stop'), 'running cancel request must pass');
    $done = hub_test_cancel_insert_run($db, 'run_cancel_done', 'succeeded');
    hub_test_assert(!hub_runtime_request_cancel($db, (int)$done['id'], 'too late'), 'final state must reject cancel request');
});

hub_test('PhaseRuntime-2C1 mark cancelled requires request and owning lease', function (): void {
    $db = hub_test_reset_db();
    hub_test_cancel_insert_run($db, 'run_mark_cancelled');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'fixture must claim');
    $id = (int)$claimed['id'];
    $token = (string)$claimed['lease_token'];

    hub_test_assert(!hub_runtime_mark_cancelled($db, $id, $token), 'unrequested cancel must not finalize');
    hub_test_assert(hub_runtime_request_cancel($db, $id, 'stop'), 'cancel request must pass');
    hub_test_assert(!hub_runtime_mark_cancelled($db, $id, 'bad-token'), 'wrong lease token must reject mark_cancelled');
    hub_test_assert(hub_runtime_mark_cancelled($db, $id, $token), 'owning lease token must mark cancelled');

    $row = hub_test_cancel_row($db, 'run_mark_cancelled');
    hub_test_assert((string)$row['state'] === 'cancelled', 'cancelled state mismatch');
    hub_test_assert((string)$row['cancelled_at'] !== '' && (string)$row['finished_at'] !== '', 'cancelled timestamps missing');
    hub_test_assert($row['lease_expires_at'] === null, 'cancelled state must clear active lease expiry');
    hub_test_assert(!hub_runtime_heartbeat($db, $id, $token, 60), 'cancelled state must reject heartbeat');
    hub_test_assert(!hub_runtime_finish($db, $id, $token, 'failed'), 'cancelled state must reject finish');
});

hub_test('PhaseRuntime-2C1 cancel request and succeeded finish race is atomic', function (): void {
    $db = hub_test_reset_db();
    hub_test_cancel_insert_run($db, 'run_finish_wins');
    $finishFirst = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($finishFirst !== null, 'finish-first fixture must claim');
    hub_test_assert(hub_runtime_finish($db, (int)$finishFirst['id'], (string)$finishFirst['lease_token'], 'succeeded'), 'finish before cancel must pass');
    hub_test_assert(!hub_runtime_request_cancel($db, (int)$finishFirst['id'], 'too late'), 'cancel after success must fail');

    hub_test_cancel_insert_run($db, 'run_cancel_wins');
    $cancelFirst = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($cancelFirst !== null, 'cancel-first fixture must claim');
    hub_test_assert(hub_runtime_request_cancel($db, (int)$cancelFirst['id'], 'stop'), 'cancel before finish must pass');
    hub_test_assert(!hub_runtime_finish($db, (int)$cancelFirst['id'], (string)$cancelFirst['lease_token'], 'succeeded'), 'succeeded finish after cancel request must fail');
    hub_test_assert(hub_runtime_mark_cancelled($db, (int)$cancelFirst['id'], (string)$cancelFirst['lease_token']), 'cancel winner must finalize cancelled');
});

hub_test('PhaseRuntime-2C1 timeout requires owning lease and expired deadline', function (): void {
    $db = hub_test_reset_db();
    hub_test_cancel_insert_run($db, 'run_timeout');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'fixture must claim');
    $id = (int)$claimed['id'];
    $token = (string)$claimed['lease_token'];

    hub_test_cancel_set_timeout($db, $id, date('Y-m-d H:i:s', time() + 60));
    hub_test_assert(!hub_runtime_mark_timed_out($db, $id, $token), 'future timeout must not finalize');
    hub_test_cancel_set_timeout($db, $id, date('Y-m-d H:i:s', time() - 60));
    hub_test_assert(!hub_runtime_mark_timed_out($db, $id, 'bad-token'), 'wrong lease token must reject timeout');
    hub_test_assert(hub_runtime_mark_timed_out($db, $id, $token), 'owning lease with expired timeout must pass');

    $row = hub_test_cancel_row($db, 'run_timeout');
    hub_test_assert((string)$row['state'] === 'timed_out', 'timed_out state mismatch');
    hub_test_assert((string)$row['finished_at'] !== '', 'timed_out must set finished_at');
    hub_test_assert($row['lease_expires_at'] === null, 'timed_out state must clear active lease expiry');
    hub_test_assert(!hub_runtime_heartbeat($db, $id, $token, 60), 'timed_out state must reject heartbeat');
    hub_test_assert(!hub_runtime_finish($db, $id, $token, 'failed'), 'timed_out state must reject finish');
});

hub_test('PhaseRuntime-2C1 cancel request wins over timeout finalization', function (): void {
    $db = hub_test_reset_db();
    hub_test_cancel_insert_run($db, 'run_cancel_timeout_priority');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'fixture must claim');
    $id = (int)$claimed['id'];
    $token = (string)$claimed['lease_token'];

    hub_test_cancel_set_timeout($db, $id, date('Y-m-d H:i:s', time() - 60));
    hub_test_assert(hub_runtime_request_cancel($db, $id, 'operator stop'), 'cancel request must pass');
    hub_test_assert(!hub_runtime_mark_timed_out($db, $id, $token), 'cancelled intent must block timeout finalization');
    hub_test_assert(hub_runtime_mark_cancelled($db, $id, $token), 'cancelled intent must finalize cancelled');
});
