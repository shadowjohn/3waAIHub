<?php
declare(strict_types=1);

function hub_test_runtime_insert_run(PDO $db, string $runId, string $state = 'queued'): void
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
}

hub_test('PhaseRuntime-2A runtime claim is atomic and owned by lease token', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_insert_run($db, 'run_claim_001');

    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'first worker must claim queued run');
    hub_test_assert((string)$claimed['state'] === 'claimed', 'claimed run state mismatch');
    hub_test_assert((string)$claimed['worker_id'] === 'worker-a', 'worker_id mismatch');
    hub_test_assert(preg_match('/^[a-f0-9]{64}$/', (string)$claimed['lease_token']) === 1, 'lease token must be strong random hex');
    hub_test_assert((string)$claimed['claimed_at'] !== '' && (string)$claimed['heartbeat_at'] !== '' && (string)$claimed['lease_expires_at'] !== '', 'lease timestamps missing');

    $second = hub_runtime_claim_next($db, 'worker-b', 60);
    hub_test_assert($second === null, 'second worker must not claim same run');

    $row = $db->query("SELECT * FROM runtime_runs WHERE run_id = 'run_claim_001'")->fetch();
    hub_test_assert((string)$row['state'] === 'claimed', 'db state must stay claimed');
});

hub_test('PhaseRuntime-2A heartbeat running and finish require the owning lease token', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_insert_run($db, 'run_lease_001');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'run must be claimed');
    $id = (int)$claimed['id'];
    $token = (string)$claimed['lease_token'];

    hub_test_assert(!hub_runtime_heartbeat($db, $id, 'bad-token', 60), 'heartbeat with wrong token must fail');
    hub_test_assert(hub_runtime_heartbeat($db, $id, $token, 60), 'heartbeat with right token must pass');
    hub_test_assert(!hub_runtime_mark_running($db, $id, 'bad-token'), 'mark_running with wrong token must fail');
    hub_test_assert(hub_runtime_mark_running($db, $id, $token), 'mark_running with right token must pass');

    $running = $db->query('SELECT state FROM runtime_runs WHERE id = ' . $id)->fetchColumn();
    hub_test_assert((string)$running === 'running', 'state must be running');

    hub_test_assert(!hub_runtime_finish($db, $id, 'bad-token', 'succeeded'), 'finish with wrong token must fail');
    hub_test_assert(hub_runtime_finish($db, $id, $token, 'succeeded', ['ok' => true]), 'finish with right token must pass');
    hub_test_assert(!hub_runtime_heartbeat($db, $id, $token, 60), 'final state must reject heartbeat');
    hub_test_assert(!hub_runtime_mark_running($db, $id, $token), 'final state must reject mark_running');
    hub_test_assert(!hub_runtime_finish($db, $id, $token, 'failed'), 'final state must reject second finish');
});

hub_test('PhaseRuntime-2A stale detection only reports expired claimed or running runs', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_insert_run($db, 'run_stale_claimed');
    hub_test_runtime_insert_run($db, 'run_stale_running');
    hub_test_runtime_insert_run($db, 'run_stale_queued');
    hub_test_runtime_insert_run($db, 'run_stale_done', 'succeeded');

    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null && (string)$claimed['run_id'] === 'run_stale_claimed', 'first stale fixture claim mismatch');
    $running = hub_runtime_claim_next($db, 'worker-b', 60);
    hub_test_assert($running !== null && (string)$running['run_id'] === 'run_stale_running', 'second stale fixture claim mismatch');
    hub_test_assert(hub_runtime_mark_running($db, (int)$running['id'], (string)$running['lease_token']), 'running fixture must mark running');

    hub_test_assert(hub_runtime_find_stale($db, hub_now()) === [], 'unexpired leases must not be stale');

    $future = date('Y-m-d H:i:s', time() + 120);
    $stale = hub_runtime_find_stale($db, $future);
    $ids = array_map(static fn (array $row): string => (string)$row['run_id'], $stale);
    sort($ids);
    hub_test_assert($ids === ['run_stale_claimed', 'run_stale_running'], 'stale detection must only include expired claimed/running runs');
});

hub_test('PhaseRuntime-2A claimed run can finish failed before running', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_insert_run($db, 'run_prepare_failed');
    $claimed = hub_runtime_claim_next($db, 'worker-a', 60);
    hub_test_assert($claimed !== null, 'run must be claimed');
    hub_test_assert(hub_runtime_finish($db, (int)$claimed['id'], (string)$claimed['lease_token'], 'failed', ['error' => 'prepare_failed']), 'claimed run must finish failed');

    $row = $db->query("SELECT state, finished_at FROM runtime_runs WHERE run_id = 'run_prepare_failed'")->fetch();
    hub_test_assert((string)$row['state'] === 'failed', 'claimed direct failure state mismatch');
    hub_test_assert((string)$row['finished_at'] !== '', 'claimed direct failure must set finished_at');
});

hub_test('PhaseRuntime-2A migrates old runtime success state to succeeded', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_insert_run($db, 'run_old_success', 'success');
    hub_migrate($db);

    $state = $db->query("SELECT state FROM runtime_runs WHERE run_id = 'run_old_success'")->fetchColumn();
    hub_test_assert((string)$state === 'succeeded', 'old runtime success state must migrate to succeeded');
});
