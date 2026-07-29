<?php
declare(strict_types=1);

require_once HUB_ROOT . '/app/admin_market.php';

function hub_test_admin_market_request(array $get = [], array $post = [], bool $ajax = false, bool $captureStatus = false): array
{
    $server = [
        'REQUEST_METHOD' => $post === [] ? 'GET' : 'POST',
        'REMOTE_ADDR' => '203.0.113.80',
        'SCRIPT_NAME' => '/3waAIHub/admin/marketplace.php',
        'REQUEST_URI' => '/3waAIHub/admin/marketplace.php',
    ];
    if ($ajax) {
        $server['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    }
    $script = "define('HUB_TESTING', true);"
        . 'require ' . var_export(HUB_ROOT . '/app/bootstrap.php', true) . ';'
        . ($captureStatus
            ? "register_shutdown_function(static function (): void { fwrite(STDERR, '__HTTP_STATUS__=' . (string)http_response_code()); });"
            : '')
        . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];"
        . '$_SERVER = ' . var_export($server, true) . ';'
        . '$_GET = ' . var_export($get, true) . ';'
        . '$_POST = ' . var_export($post, true) . ';'
        . 'require ' . var_export(HUB_ROOT . '/admin/marketplace.php', true) . ';';

    $result = hub_run_command([PHP_BINARY, '-r', $script], 30, [
        'AIHUB_TEST_DB' => (string)getenv('AIHUB_TEST_DB'),
        'AIHUB_TEST_DATA_DIR' => (string)getenv('AIHUB_TEST_DATA_DIR'),
    ]);
    $result['http_status'] = preg_match('/__HTTP_STATUS__=([0-9]+)/', $result['stderr'], $match) === 1
        ? (int)$match[1]
        : null;

    return $result;
}

function hub_test_admin_services_request(): array
{
    $script = "define('HUB_TESTING', true);"
        . 'require ' . var_export(HUB_ROOT . '/app/bootstrap.php', true) . ';'
        . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];"
        . "\$_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.80'];"
        . 'require ' . var_export(HUB_ROOT . '/admin/services.php', true) . ';';

    return hub_run_command([PHP_BINARY, '-r', $script], 30, [
        'AIHUB_TEST_DB' => (string)getenv('AIHUB_TEST_DB'),
        'AIHUB_TEST_DATA_DIR' => (string)getenv('AIHUB_TEST_DATA_DIR'),
    ]);
}

hub_test('Market categories are exclusive and sum to all Packs', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $catalog = hub_admin_market_catalog($db, 'all');
    $sum = 0;
    foreach (['reference', 'vision', 'language', 'audio', 'tools', 'experimental'] as $key) {
        $sum += $catalog['counts'][$key];
    }
    hub_test_assert($sum === $catalog['counts']['all'], 'Market category counts overlap or omit a Pack');
    hub_test_assert(hub_admin_market_category('utility') === 'tools', 'legacy utility category must normalize to tools');
    hub_test_assert(hub_admin_market_category('unknown') === 'all', 'unknown category must normalize to all');

    $tools = hub_admin_market_catalog($db, 'utility');
    hub_test_assert($tools['active_category'] === 'tools', 'utility filter must activate tools');
    hub_test_assert(count($tools['packs']) === $tools['counts']['tools'], 'filtered rows must match computed tools count');
    foreach ($tools['packs'] as $pack) {
        hub_test_assert($pack['market_category'] === 'tools', 'tools filter returned another category');
    }

    $installed = hub_admin_market_installed_stats($db);
    hub_test_assert(($installed['hello']['count'] ?? 0) === 1, 'installed stats must count the seeded hello service');
    hub_test_assert(($installed['hello']['first_service_id'] ?? 0) > 0, 'installed stats must expose first service ID');
});

hub_test('Market manifest categories follow the canonical mapping', function (): void {
    hub_test_assert(hub_admin_market_category_for_manifest(['role' => 'reference', 'experimental' => true]) === 'reference', 'reference role must win');
    hub_test_assert(hub_admin_market_category_for_manifest(['experimental' => true, 'category' => 'vision']) === 'experimental', 'experimental flag must override category');

    foreach (['vision', 'ocr', 'segmentation', 'detection', 'object-detection'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'vision', $category . ' must map to vision');
    }
    foreach (['language', 'translation', 'translate', 'llm'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'language', $category . ' must map to language');
    }
    foreach (['utility', 'tool', 'tools', 'web'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'tools', $category . ' must map to tools');
    }

    hub_test_assert(hub_admin_market_category_for_manifest(['category' => 'audio']) === 'audio', 'audio must map to audio');
    hub_test_assert(hub_admin_market_category_for_manifest(['category' => 'document']) === 'experimental', 'unrecognized categories must map to experimental');
});

hub_test('Pack purpose uses a keyed Chinese seed and manifest fallback', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $pack = hub_get_pack('ocr-ppocrv5');
    $description = hub_admin_market_pack_description($db, $pack);
    hub_test_assert(str_contains($description, '圖片文字辨識'), 'OCR Chinese purpose copy missing');

    $unknown = ['id' => 'unseeded-pack', 'manifest' => ['description' => 'Manifest fallback']];
    hub_test_assert(hub_admin_market_pack_description($db, $unknown) === 'Manifest fallback', 'manifest description fallback mismatch');

    $malformed = ['id' => 'malformed-pack', 'manifest' => 'invalid', 'description' => 'Top-level fallback'];
    hub_test_assert(hub_admin_market_pack_description($db, $malformed) === 'Top-level fallback', 'top-level description fallback mismatch');
});

hub_test('canonical Market renders filtered category counts and collapsed technical details', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $catalog = hub_admin_market_catalog($db, 'vision');
    $result = hub_test_admin_market_request(['view' => 'market', 'category' => 'vision']);

    hub_test_assert($result['exit_code'] === 0, 'canonical Market render failed: ' . $result['output']);
    $html = $result['stdout'];
    foreach ([
        'data-market-view="market"',
        'marketplace.php?view=market&amp;category=vision',
        'data-market-category="vision"',
        'data-market-count="' . $catalog['counts']['vision'] . '"',
        'ocr-ppocrv5',
        '圖片文字辨識',
        '<details',
        'runtime_level',
        'target_level',
        'execution_type',
        'pack_id',
        'pack-readiness-refresh',
    ] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'canonical Market render missing ' . $needle);
    }
    foreach (array_keys(hub_admin_market_categories()) as $category) {
        hub_test_assert(str_contains($html, 'data-market-category="' . $category . '"'), 'category link missing ' . $category);
    }
    hub_test_assert(!str_contains($html, 'href="packs.php'), 'canonical Market must not link to legacy packs page');

    $fallback = hub_test_admin_market_request(['view' => 'unknown']);
    hub_test_assert(
        $fallback['exit_code'] === 0 && str_contains($fallback['stdout'], 'data-market-view="market"'),
        'unknown canonical view must render Market'
    );
});

hub_test('canonical installed services keeps operations links polling and collapsed details', function (): void {
    hub_test_reset_db();
    $result = hub_test_admin_market_request(['view' => 'services']);

    hub_test_assert($result['exit_code'] === 0, 'canonical services render failed: ' . $result['output']);
    $html = $result['stdout'];
    foreach ([
        'data-market-view="services"',
        'service-action-form',
        'value="start"',
        'value="stop"',
        'value="restart"',
        'value="build"',
        'value="rebuild"',
        'value="refresh"',
        '<details',
        'service_key',
        'service_settings.php?service_id=',
        'service_logs.php?id=',
        'benchmarks.php',
        'playground.php?mode=',
        'data-copy-target=',
        'service-job',
        'role="status" aria-live="polite" aria-atomic="true"',
        'data-service-actual-status=',
        'data-service-enabled=',
        'data-service-restart-required=',
        'data-service-status-summary',
        'data-service-enabled-badge',
        'data-service-restart-badge',
        'data-service-summary="running"',
        'data-service-summary="stopped"',
        'data-service-summary="disabled"',
        '../assets/js/services.js',
    ] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'canonical services render missing ' . $needle);
    }
});

hub_test('canonical service POST only queues the mapped command job', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $status = (string)$service['status'];

    foreach ([
        'build' => 'service_build',
        'start' => 'service_start',
        'stop' => 'service_stop',
        'restart' => 'service_restart',
        'rebuild' => 'service_rebuild',
        'refresh' => 'service_health_check',
    ] as $action => $queueAction) {
        $result = hub_test_admin_market_request(['view' => 'services'], [
            'csrf_token' => 'test',
            'service_id' => (string)$service['id'],
            'action' => $action,
        ], true);
        $payload = json_decode($result['stdout'], true);
        $job = is_array($payload) ? hub_get_command_job($db, (int)($payload['job']['id'] ?? 0)) : null;
        $serviceAfter = hub_get_service($db, (int)$service['id']);

        hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'service AJAX response must be JSON: ' . $result['output']);
        hub_test_assert(($payload['ok'] ?? false) === true, 'service AJAX response must report success for ' . $action);
        hub_test_assert(is_array($job)
            && ($job['action'] ?? '') === $queueAction
            && ($job['status'] ?? '') === 'queued'
            && (int)($job['service_id'] ?? 0) === (int)$service['id'], 'service POST queue mapping mismatch for ' . $action);
        hub_test_assert(($serviceAfter['status'] ?? null) === $status, 'web request must not execute ' . $action);
        foreach (['id', 'action', 'action_label', 'service_id', 'service_name', 'status', 'status_label', 'status_class', 'progress'] as $key) {
            hub_test_assert(array_key_exists($key, $payload['job'] ?? []), 'service AJAX job shape missing ' . $key);
        }
        foreach (['id', 'status', 'runtime_status', 'enabled', 'restart_required'] as $key) {
            hub_test_assert(array_key_exists($key, $payload['job']['service'] ?? []), 'service AJAX nested state missing ' . $key);
        }
    }
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn() === 6, 'service POST must create one job per action');
});

hub_test('canonical service POST rejects unknown actions non-integer services and bad CSRF', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');

    foreach ([
        ['csrf_token' => 'test', 'service_id' => (string)$service['id'], 'action' => 'destroy'],
        ['csrf_token' => 'test', 'service_id' => $service['id'] . 'oops', 'action' => 'start'],
        ['csrf_token' => 'wrong', 'service_id' => (string)$service['id'], 'action' => 'start'],
    ] as $post) {
        $before = (int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn();
        $result = hub_test_admin_market_request(['view' => 'services'], $post, true);
        $payload = json_decode($result['stdout'], true);
        $after = (int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn();
        hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'invalid service action must return JSON');
        hub_test_assert(($payload['ok'] ?? true) === false, 'invalid service action must report failure');
        hub_test_assert($after === $before, 'invalid service action must not create a command job');
    }
});

hub_test('canonical readiness endpoint returns JSON from marketplace', function (): void {
    hub_test_reset_db();
    $result = hub_test_admin_market_request(['ajax' => 'readiness', 'pack_id' => 'hello'], [], true, true);
    $payload = json_decode($result['stdout'], true);

    hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'readiness endpoint must return JSON');
    hub_test_assert($result['http_status'] === 200, 'valid readiness request must return HTTP 200');
    hub_test_assert(($payload['ok'] ?? false) === true && ($payload['pack_id'] ?? '') === 'hello', 'canonical readiness payload mismatch');
    hub_test_assert((string)($payload['readiness'] ?? '') !== '', 'canonical readiness label missing');
});

hub_test('canonical readiness rejects non-scalar malformed and oversized Pack IDs without warnings', function (): void {
    hub_test_reset_db();

    foreach ([
        ['ajax' => 'readiness', 'pack_id' => ['hello']],
        ['ajax' => 'readiness', 'pack_id' => '../hello'],
        ['ajax' => 'readiness', 'pack_id' => '-hello'],
        ['ajax' => 'readiness', 'pack_id' => str_repeat('a', 129)],
    ] as $query) {
        $result = hub_test_admin_market_request($query, [], true, true);
        $payload = json_decode($result['stdout'], true);

        hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'invalid readiness request must return canonical JSON');
        hub_test_assert($result['http_status'] === 400, 'invalid readiness Pack ID must return HTTP 400');
        hub_test_assert(($payload['ok'] ?? true) === false, 'invalid readiness Pack ID must report failure');
        hub_test_assert(!str_contains($result['stderr'], 'Warning'), 'invalid readiness Pack ID must not emit a PHP warning');
    }
});

hub_test('command job payload carries the actual service flags needed by polling', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $db->prepare(
        "UPDATE services
         SET enabled = 0, status = 'stopped', runtime_status = 'stopped', restart_required = 1
         WHERE id = :id"
    )->execute([':id' => (int)$service['id']]);
    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'poll-state-test'], null, '127.0.0.1');

    $payload = hub_command_job_status_payload($db, $jobId);

    hub_test_assert(($payload['service']['runtime_status'] ?? null) === 'stopped', 'job payload runtime state mismatch');
    hub_test_assert(($payload['service']['enabled'] ?? null) === 0, 'job payload enabled flag missing');
    hub_test_assert(($payload['service']['restart_required'] ?? null) === 1, 'job payload restart flag missing');
    foreach (['total', 'running', 'stopped', 'disabled', 'active_jobs', 'failed_jobs'] as $key) {
        hub_test_assert(array_key_exists($key, $payload['summary'] ?? []), 'job payload summary missing ' . $key);
    }
    hub_test_assert(($payload['summary']['total'] ?? null) === 1, 'service total summary mismatch');
    hub_test_assert(($payload['summary']['running'] ?? null) === 0, 'stopped service must not count as running');
    hub_test_assert(($payload['summary']['stopped'] ?? null) === 1, 'stopped service summary mismatch');
    hub_test_assert(($payload['summary']['disabled'] ?? null) === 1, 'disabled service summary mismatch');
    hub_test_assert(($payload['summary']['active_jobs'] ?? null) === 1, 'queued job must increment the active summary');
    hub_test_assert(($payload['summary']['failed_jobs'] ?? null) === 0, 'queued job must not increment the failed summary');

    $db->prepare("UPDATE command_jobs SET status = 'running' WHERE id = :id")->execute([':id' => $jobId]);
    $runningJobPayload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert(($runningJobPayload['summary']['active_jobs'] ?? null) === 1, 'running job must remain in the active summary');
    hub_test_assert(($runningJobPayload['summary']['failed_jobs'] ?? null) === 0, 'running job must not increment the failed summary');

    $db->prepare(
        "UPDATE services SET enabled = 1, status = 'running', runtime_status = 'running' WHERE id = :id"
    )->execute([':id' => (int)$service['id']]);
    $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    $successPayload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert(($successPayload['summary']['running'] ?? null) === 1, 'running service summary mismatch');
    hub_test_assert(($successPayload['summary']['stopped'] ?? null) === 0, 'running service must leave the stopped summary');
    hub_test_assert(($successPayload['summary']['disabled'] ?? null) === 0, 'enabled service must leave the disabled summary');
    hub_test_assert(($successPayload['summary']['active_jobs'] ?? null) === 0, 'successful job must leave the active summary');

    $db->prepare("UPDATE command_jobs SET status = 'failed' WHERE id = :id")->execute([':id' => $jobId]);
    $failedPayload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert(($failedPayload['summary']['active_jobs'] ?? null) === 0, 'failed job must leave the active summary');
    hub_test_assert(($failedPayload['summary']['failed_jobs'] ?? null) === 1, 'failed job must increment the failed summary');
});

hub_test('canonical service health requires a successful job and a running runtime', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    $jobId = hub_enqueue_command_job($db, 'service_health_check', $serviceId, ['reason' => 'health-render-test'], null, '127.0.0.1');
    $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    $db->prepare(
        "UPDATE services SET status = 'stopped', runtime_status = 'stopped' WHERE id = :id"
    )->execute([':id' => $serviceId]);

    $stopped = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert(
        str_contains($stopped['stdout'], 'data-service-health class="hub-badge hub-badge-bad"')
            && str_contains($stopped['stdout'], '健康異常'),
        'successful health job must remain unhealthy when the runtime is stopped'
    );

    $db->prepare(
        "UPDATE services SET status = 'running', runtime_status = 'running' WHERE id = :id"
    )->execute([':id' => $serviceId]);
    $running = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert(
        str_contains($running['stdout'], 'data-service-health class="hub-badge hub-badge-ok"')
            && str_contains($running['stdout'], '健康正常'),
        'successful health job with a running runtime must render healthy'
    );

    $db->prepare("UPDATE command_jobs SET status = 'running' WHERE id = :id")->execute([':id' => $jobId]);
    $checking = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert(
        str_contains($checking['stdout'], 'data-service-health class="hub-badge hub-badge-warn"')
            && str_contains($checking['stdout'], '健康檢查中'),
        'active health job must render as checking'
    );
});

hub_test('legacy service health requires a successful job and a running runtime', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    $jobId = hub_enqueue_command_job($db, 'service_health_check', $serviceId, ['reason' => 'legacy-health-render-test'], null, '127.0.0.1');
    $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    $db->prepare(
        "UPDATE services SET status = 'stopped', runtime_status = 'stopped' WHERE id = :id"
    )->execute([':id' => $serviceId]);

    $stopped = hub_test_admin_services_request();
    hub_test_assert($stopped['exit_code'] === 0, 'legacy services render failed: ' . $stopped['output']);
    hub_test_assert(
        str_contains($stopped['stdout'], 'data-service-health class="hub-badge hub-badge-bad"')
            && str_contains($stopped['stdout'], '健康異常'),
        'legacy successful health job must remain unhealthy when the runtime is stopped'
    );

    $db->prepare(
        "UPDATE services SET status = 'error', runtime_status = 'error' WHERE id = :id"
    )->execute([':id' => $serviceId]);
    $error = hub_test_admin_services_request();
    hub_test_assert(
        str_contains($error['stdout'], 'data-service-health class="hub-badge hub-badge-bad"')
            && str_contains($error['stdout'], '健康異常'),
        'legacy successful health job must remain unhealthy when the runtime is in error'
    );

    $db->prepare(
        "UPDATE services SET status = 'running', runtime_status = 'running' WHERE id = :id"
    )->execute([':id' => $serviceId]);
    $running = hub_test_admin_services_request();
    hub_test_assert(
        str_contains($running['stdout'], 'data-service-health class="hub-badge hub-badge-ok"')
            && str_contains($running['stdout'], '健康正常'),
        'legacy successful health job with a running runtime must render healthy'
    );
});

hub_test('model labels cover both Pack surfaces required optional and malformed selectors', function (): void {
    $db = hub_test_reset_db();
    $required = ['settings_schema' => [[
        'required' => true,
        'default' => 'missing-model',
        'model_selector' => ['type' => 'unknown'],
    ]]];
    $optional = ['settings_schema' => [[
        'required' => false,
        'model_selector' => ['type' => 'unknown'],
    ]]];
    $malformed = ['settings_schema' => [
        'invalid',
        ['required' => true, 'model_selector' => 'not-an-array'],
    ]];

    foreach (['packs', 'marketplace'] as $surface) {
        $requiredLabel = hub_admin_market_model_label($db, $required, $surface);
        $optionalLabel = hub_admin_market_model_label($db, $optional, $surface);
        $malformedLabel = hub_admin_market_model_label($db, $malformed, $surface);
        hub_test_assert($requiredLabel['label'] === '缺少模型', $surface . ' required model label mismatch');
        hub_test_assert($optionalLabel['label'] === '模型可選', $surface . ' optional model label mismatch');
        hub_test_assert(is_string($malformedLabel['label']) && $malformedLabel['label'] !== '', $surface . ' malformed selector must not throw');
    }
});
