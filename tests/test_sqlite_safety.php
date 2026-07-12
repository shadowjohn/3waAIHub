<?php
declare(strict_types=1);

hub_test('sqlite connection applies write safety pragmas', function (): void {
    $db = hub_test_reset_db();

    hub_test_assert((int)$db->query('PRAGMA foreign_keys')->fetchColumn() === 1, 'foreign_keys must be enabled');
    hub_test_assert((int)$db->query('PRAGMA busy_timeout')->fetchColumn() === 5000, 'busy_timeout must be 5000');
    hub_test_assert(strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()) === 'wal', 'journal_mode must be WAL');
    hub_test_assert((int)$db->query('PRAGMA synchronous')->fetchColumn() === 1, 'synchronous must be NORMAL');
});

hub_test('sqlite safety storage defaults exist', function (): void {
    $db = hub_test_reset_db();

    hub_test_assert(hub_default_storage_settings()['AIHUB_MODELS_DIR'] === '/DATA/models', 'product model dir default mismatch');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_MODELS_DIR') === hub_test_models_dir(), 'test model dir override mismatch');

    $expected = [
        'AIHUB_CACHE_DIR' => HUB_DATA_DIR . '/cache',
        'AIHUB_UPLOADS_DIR' => HUB_DATA_DIR . '/uploads',
        'AIHUB_RESULTS_DIR' => HUB_DATA_DIR . '/results',
        'AIHUB_LOGS_DIR' => HUB_LOG_DIR,
        'AIHUB_DB_MAX_SIZE_MB' => '1024',
        'AIHUB_LOG_RETENTION_DAYS' => '14',
        'AIHUB_METRIC_RETENTION_DAYS' => '14',
        'AIHUB_TASK_RETENTION_DAYS' => '30',
        'AIHUB_MAX_TASK_LOG_ROWS' => '1000',
        'AIHUB_MAX_RESULT_JSON_BYTES' => '262144',
    ];
    foreach ($expected as $key => $value) {
        hub_test_assert(hub_get_storage_setting($db, $key) === $value, $key . ' default mismatch');
    }
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', HUB_DATA_DIR . '/models');
    hub_ensure_default_storage_settings($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_MODELS_DIR') === HUB_DATA_DIR . '/models', 'existing model dir setting must not be overwritten');
    hub_test_assert(hub_storage_settings_warnings(hub_get_storage_paths($db)) !== [], 'old in-repo model dir should warn');

    $envExample = (string)file_get_contents(HUB_ROOT . '/.env.example');
    hub_test_assert(str_contains($envExample, 'AIHUB_MODELS_DIR=/DATA/models'), '.env.example model dir default mismatch');
    $install = (string)file_get_contents(HUB_ROOT . '/install.sh');
    hub_test_assert(str_contains($install, '/DATA/models/paddleocr'), 'install.sh must create host model subdirs');
});

hub_test('large task result_json is stored as an artifact', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_MAX_RESULT_JSON_BYTES', '64');

    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'large-result'], null, '127.0.0.1');
    $task = hub_claim_next_task($db);
    hub_finish_task_success($db, $task, ['ok' => true, 'payload' => str_repeat('x', 256)]);

    $finished = hub_get_task($db, $taskId);
    hub_test_assert($finished !== null, 'finished task missing');
    hub_test_assert(($finished['result']['stored_as_artifact'] ?? false) === true, 'large result must be stored as artifact');
    hub_test_assert((int)($finished['result']['bytes'] ?? 0) > 64, 'artifact byte count missing');

    $stmt = $db->prepare('SELECT * FROM task_artifacts WHERE task_id = :task_id');
    $stmt->execute([':task_id' => $taskId]);
    $artifact = $stmt->fetch();
    hub_test_assert($artifact !== false, 'artifact row missing');
    hub_test_assert(is_file((string)$artifact['path']), 'artifact file missing');
});

hub_test('successful task completion clears stale error message', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'retry-success'], null, '127.0.0.1');
    $task = hub_claim_next_task($db);
    $db->prepare('UPDATE tasks SET error_message = :error_message WHERE id = :id')->execute([
        ':error_message' => 'old failure',
        ':id' => $taskId,
    ]);

    hub_finish_task_success($db, $task, ['ok' => true]);
    $finished = hub_get_task($db, $taskId);

    hub_test_assert((string)($finished['error_message'] ?? '') === '', 'successful task must clear stale error_message');
});

hub_test('task logs keep DB rows bounded and spill large messages to file', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_MAX_TASK_LOG_ROWS', '2');

    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'log-guard'], null, '127.0.0.1');
    hub_add_task_log($db, $taskId, 'info', 'small log 1');
    hub_add_task_log($db, $taskId, 'info', 'small log 2');
    hub_add_task_log($db, $taskId, 'info', str_repeat('L', 5000));

    $logs = hub_list_task_logs($db, $taskId);
    hub_test_assert(count($logs) === 2, 'task log rows must be bounded');
    $last = end($logs);
    hub_test_assert(str_contains((string)$last['message'], 'log_path='), 'large log should store path in DB');

    preg_match('/log_path=([^ ]+)/', (string)$last['message'], $matches);
    hub_test_assert(isset($matches[1]) && is_file(hub_path($matches[1])), 'large log file missing');
});

hub_test('running DocParser task can request cooperative cancel only', function (): void {
    $db = hub_test_reset_db();
    $docparserTaskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'input_file' => HUB_DATA_DIR . '/uploads/tasks/task_999/input.pdf',
    ], null, '127.0.0.1');
    $docparserTask = hub_claim_next_task($db);
    hub_test_assert((int)$docparserTask['id'] === $docparserTaskId, 'DocParser task must be claimed');

    hub_test_assert(hub_cancel_task($db, $docparserTaskId) === true, 'running DocParser task should accept cancel request');
    $cancelRequested = hub_get_task($db, $docparserTaskId);
    hub_test_assert(($cancelRequested['status'] ?? '') === 'running', 'running DocParser task should stay running until worker checkpoint');
    hub_test_assert(($cancelRequested['input']['cancel_requested'] ?? '') === '1', 'running DocParser task must store cancel_requested flag');
    hub_test_assert(hub_task_cancel_requested($db, $docparserTaskId) === true, 'cancel_requested helper must see running DocParser flag');

    hub_finish_task_cancelled($db, $cancelRequested, 'cancelled by test checkpoint');
    $cancelled = hub_get_task($db, $docparserTaskId);
    hub_test_assert(($cancelled['status'] ?? '') === 'cancelled', 'DocParser checkpoint should finish task as cancelled');
    hub_test_assert(str_contains((string)($cancelled['error_message'] ?? ''), 'cancelled by test checkpoint'), 'cancelled reason should be stored');

    $demoTaskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'running-demo'], null, '127.0.0.1');
    $demoTask = hub_claim_next_task($db);
    hub_test_assert((int)$demoTask['id'] === $demoTaskId, 'demo task must be claimed');
    hub_test_assert(hub_cancel_task($db, $demoTaskId) === false, 'running non-DocParser task must not accept cancel request');
});

hub_test('host metrics collection is throttled by latest snapshot age', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, ['host' => ['load_1' => 0.1]]);

    hub_test_assert(hub_should_collect_host_metrics($db, 30) === false, 'fresh host metrics snapshot should throttle collection');
    hub_test_assert(hub_should_collect_host_metrics($db, 30, true) === true, '--force should bypass throttle');
});
