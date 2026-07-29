<?php
declare(strict_types=1);

require_once HUB_ROOT . '/app/admin_records.php';

hub_test('Record Center exposes the agreed five tabs in order', function (): void {
    hub_test_assert(hub_admin_record_tabs() === [
        'runs' => '執行歷程',
        'api' => 'API 記錄',
        'jobs' => '背景工作',
        'service' => '服務記錄',
        'system' => '系統記錄',
    ], 'Record Center tab contract mismatch');
    hub_test_assert(hub_admin_record_tab('unknown') === 'runs', 'unknown record tab must use first canonical tab');
    hub_test_assert(hub_admin_record_tab(['api']) === 'runs', 'non-scalar record tab must use first canonical tab');
});

hub_test('Record Center runtime filters and query stay validated', function (): void {
    $db = hub_test_reset_db();
    $now = hub_now();
    $insert = $db->prepare(
        'INSERT INTO runtime_runs (run_id, pack_id, task, state, started_at, created_at)
         VALUES (:run_id, :pack_id, :task, :state, :started_at, :created_at)'
    );
    $insert->execute([
        ':run_id' => 'run_records_yolo',
        ':pack_id' => 'yolo',
        ':task' => 'yolo_predict',
        ':state' => 'succeeded',
        ':started_at' => $now,
        ':created_at' => $now,
    ]);
    $insert->execute([
        ':run_id' => 'run_records_other',
        ':pack_id' => 'hello',
        ':task' => 'hello',
        ':state' => 'failed',
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    $filters = hub_admin_record_runtime_filters([
        'pack_id' => 'yolo',
        'task' => 'yolo_predict',
        'state' => 'succeeded',
        'q' => 'records_yolo',
    ]);
    $runs = hub_admin_record_runtime_runs($db, $filters, 100);
    hub_test_assert(count($runs) === 1 && $runs[0]['run_id'] === 'run_records_yolo', 'validated runtime filters should match one run');

    $invalid = hub_admin_record_runtime_filters([
        'pack_id' => ['yolo'],
        'task' => 'x;DROP TABLE runtime_runs',
        'state' => '../failed',
        'q' => ["%' OR 1=1 --"],
    ]);
    hub_test_assert($invalid === ['pack_id' => '', 'task' => '', 'state' => '', 'q' => ''], 'runtime filters must reject unsafe and non-scalar values');
    hub_test_assert(count(hub_admin_record_runtime_runs($db, $invalid, 0)) === 1, 'runtime query limit must have a safe lower bound');
    $injection = hub_admin_record_runtime_filters(['q' => "%' OR 1=1 --"]);
    hub_test_assert(hub_admin_record_runtime_runs($db, $injection, 100) === [], 'runtime keyword must remain parameterized');
});

hub_test('Record Center preserves job filters and bounded tails', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], 1, '127.0.0.1');
    $db->prepare(
        "UPDATE command_jobs
         SET stage = 'starting', current_message = 'record-center-needle'
         WHERE id = :id"
    )->execute([':id' => $jobId]);

    $filters = hub_admin_record_job_filters([
        'status' => 'queued',
        'action' => 'service_start',
        'service_id' => (string)$service['id'],
        'keyword' => 'record-center-needle',
        'time_from' => '2000-01-01 00:00:00',
        'time_to' => '2999-12-31 23:59:59',
    ]);
    $jobs = hub_admin_record_command_jobs($db, $filters, 200);
    hub_test_assert(count($jobs) === 1 && (int)$jobs[0]['id'] === $jobId, 'job filters should match the queued job');

    $invalid = hub_admin_record_job_filters(['status' => ['queued'], 'action' => ['service_start'], 'service_id' => ['1'], 'keyword' => ['needle']]);
    hub_test_assert($invalid['status'] === '' && $invalid['action'] === '' && $invalid['service_id'] === 0 && $invalid['keyword'] === '', 'job filters must reject non-scalar values');

    $path = HUB_DATA_DIR . '/logs/admin-records-tail.log';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, str_repeat('x', 7000));
    hub_test_assert(strlen(hub_admin_record_tail_file($path, 999999)) === 6000, 'job tails must remain capped at 6000 bytes');
    hub_test_assert(hub_admin_record_tail_file('/etc/passwd') === '', 'job tails must stay inside HUB_DATA_DIR');
});

hub_test('Record Center reads service and system records from existing tables', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $db->prepare(
        'INSERT INTO service_logs (service_id, action, output, exit_code, created_at)
         VALUES (:service_id, :action, :output, :exit_code, :created_at)'
    )->execute([
        ':service_id' => (int)$service['id'],
        ':action' => 'health',
        ':output' => 'service-record-needle',
        ':exit_code' => 0,
        ':created_at' => hub_now(),
    ]);
    $db->prepare(
        'INSERT INTO audit_logs (username, action, details, created_at)
         VALUES (:username, :action, :details, :created_at)'
    )->execute([
        ':username' => 'admin',
        ':action' => 'record_center_test',
        ':details' => 'system-record-needle',
        ':created_at' => hub_now(),
    ]);

    $serviceRows = hub_admin_record_service_logs($db, (int)$service['id'], 9999);
    $systemRows = hub_admin_record_system_logs($db, 9999);
    hub_test_assert(count($serviceRows) === 1 && $serviceRows[0]['output'] === 'service-record-needle', 'service tab must read service_logs');
    hub_test_assert(count($systemRows) === 1 && $systemRows[0]['details'] === 'system-record-needle', 'system tab must read audit_logs');
});

hub_test('Record Center source keeps canonical links and responsive tables', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    foreach (['runtime_run.php?id=', 'log_detail.php?id=', 'name="tab" value="api"', 'name="tab" value="jobs"', 'name="tab" value="runs"', 'name="tab" value="service"'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'Record Center source missing ' . $needle);
    }
    hub_test_assert(substr_count($page, 'class="record-table-wrap"') >= 5, 'all five dense record tables need responsive overflow wrappers');
    hub_test_assert(!str_contains($page, 'http://') && !str_contains($page, 'https://'), 'Record Center must not load external assets');
    hub_test_assert((fileperms(HUB_ROOT . '/admin/log_explorer.php') & 0777) === 0755, 'Record Center entrypoint must remain executable by the web runtime');
});
