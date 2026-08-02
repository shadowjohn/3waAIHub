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

function hub_test_admin_job_status_request(array $get): array
{
    $script = "define('HUB_TESTING', true);"
        . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];"
        . "\$_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.80'];"
        . '$_GET = ' . var_export($get, true) . ';'
        . 'require ' . var_export(HUB_ROOT . '/admin/job_status.php', true) . ';';

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

hub_test('Marketplace limits install settings to declared scalar install options', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $page = hub_test_admin_market_request(['view' => 'market', 'category' => 'experimental']);

    hub_test_assert($page['exit_code'] === 0, 'Marketplace audio render failed: ' . $page['output']);
    hub_test_assert(str_contains($page['stdout'], 'name="install_setting[VOXCPM2_EXECUTION_MODE]"'), 'Marketplace must render the declared VoxCPM2 install selector');
    hub_test_assert(!str_contains($page['stdout'], 'name="install_setting[VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB]"'), 'Marketplace must not render non-install VoxCPM2 settings');
    hub_test_assert(!str_contains($page['stdout'], 'name="install_setting[VOXCPM2_INTERNAL_JOB_TOKEN]"'), 'Marketplace must not render generated VoxCPM2 secrets');

    $post = [
        'csrf_token' => 'test',
        'pack_id' => 'tts-voxcpm2',
        'service_key' => 'voxcpm2-market-resident',
        'name' => 'VoxCPM2 Marketplace Resident',
        'mode' => 'voxcpm2_market_resident',
        'port_mode' => 'auto',
        'environment' => 'production',
        'install_setting' => [
            'VOXCPM2_EXECUTION_MODE' => 'resident',
            'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB' => '4096',
            'VOXCPM2_INTERNAL_JOB_TOKEN' => str_repeat('b', 64),
        ],
    ];
    $install = hub_test_admin_market_request(['view' => 'market'], $post);
    $service = hub_get_service_by_key($db, 'voxcpm2-market-resident');
    $overrides = $service ? json_decode((string)$service['environment_json'], true) : null;

    hub_test_assert($install['exit_code'] === 0 && $service !== null, 'Marketplace must install VoxCPM2 with an install option: ' . $install['output']);
    hub_test_assert($overrides === ['VOXCPM2_EXECUTION_MODE' => 'resident'], 'Marketplace must pass only declared install options to the environment');

    $invalid = hub_test_admin_market_request(['view' => 'market'], array_replace_recursive($post, [
        'service_key' => 'voxcpm2-market-invalid',
        'mode' => 'voxcpm2_market_invalid',
        'install_setting' => ['VOXCPM2_EXECUTION_MODE' => 'not-a-mode'],
    ]));
    hub_test_assert(str_contains($invalid['stdout'], 'VOXCPM2_EXECUTION_MODE must be one of the allowed options.'), 'Marketplace must validate declared install settings against their schema');

    $nonScalar = hub_test_admin_market_request(['view' => 'market'], array_replace_recursive($post, [
        'service_key' => 'voxcpm2-market-nonscalar',
        'mode' => 'voxcpm2_market_nonscalar',
        'install_setting' => ['VOXCPM2_EXECUTION_MODE' => ['resident']],
    ]));
    $nonScalarService = hub_get_service_by_key($db, 'voxcpm2-market-nonscalar');
    hub_test_assert($nonScalar['exit_code'] === 0 && $nonScalarService !== null, 'Marketplace must ignore non-scalar install setting input');
    hub_test_assert((string)$nonScalarService['environment_json'] === '[]', 'non-scalar install setting input must not become an environment override');
});

hub_test('canonical installed services keeps operations links polling and collapsed details', function (): void {
    hub_test_reset_db();
    $result = hub_test_admin_market_request(['view' => 'services']);

    hub_test_assert($result['exit_code'] === 0, 'canonical services render failed: ' . $result['output']);
    $html = $result['stdout'];
    foreach ([
        'data-market-view="services"',
        'service-action-form',
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

hub_test('canonical installed services only show state-safe start stop and remove actions', function (): void {
    $serviceCard = static function (string $html, int $serviceId): string {
        $matched = preg_match(
            '~<article\\b(?=[^>]*\\bdata-service-row-id="' . $serviceId . '"[^>]*).*?</article>~s',
            $html,
            $match
        );
        hub_test_assert($matched === 1, 'service card must render for service ' . $serviceId);

        return $match[0];
    };

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];

    hub_update_service_status($db, $serviceId, 'stopped');
    $stopped = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert($stopped['exit_code'] === 0, 'stopped services render failed: ' . $stopped['output']);
    $stoppedCard = $serviceCard($stopped['stdout'], $serviceId);
    hub_test_assert(str_contains($stoppedCard, 'value="start"'), 'stopped service must show start');
    hub_test_assert(!str_contains($stoppedCard, 'value="stop"'), 'stopped service must hide stop');
    hub_test_assert(
        str_contains($stoppedCard, 'class="danger" name="action" value="remove"'),
        'stopped idle service must show the danger removal action'
    );

    hub_update_service_status($db, $serviceId, 'running');
    $running = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert($running['exit_code'] === 0, 'running services render failed: ' . $running['output']);
    $runningCard = $serviceCard($running['stdout'], $serviceId);
    hub_test_assert(!str_contains($runningCard, 'value="start"'), 'running service must hide start');
    hub_test_assert(str_contains($runningCard, 'value="stop"'), 'running service must show stop');
    hub_test_assert(!str_contains($runningCard, 'value="remove"'), 'running service must hide removal');

    hub_update_service_status($db, $serviceId, 'stopped');
    hub_enqueue_command_job($db, 'service_start', $serviceId, [], null, '127.0.0.1');
    for ($i = 0; $i < 50; $i++) {
        $jobId = hub_enqueue_command_job($db, 'env_probe', null, [], null, '127.0.0.1');
        $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    }
    $busy = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert($busy['exit_code'] === 0, 'busy services render failed: ' . $busy['output']);
    hub_test_assert(
        !str_contains($serviceCard($busy['stdout'], $serviceId), 'value="remove"'),
        'service with an active background command must hide removal'
    );
});

hub_test('canonical service POST only queues the mapped command job', function (): void {
    foreach ([
        'build' => 'service_build',
        'start' => 'service_start',
        'stop' => 'service_stop',
        'restart' => 'service_restart',
        'rebuild' => 'service_rebuild',
        'remove' => 'service_remove',
        'refresh' => 'service_health_check',
    ] as $action => $queueAction) {
        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        hub_test_assert($service !== null, 'hello service missing');
        $status = (string)$service['status'];
        if ($action === 'remove') {
            hub_update_service_status($db, (int)$service['id'], 'stopped');
        }
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
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn() === 1, 'service POST must create one mapped job');
    }
});

hub_test('canonical service removal returns a localized busy conflict without queueing', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    hub_update_service_status($db, $serviceId, 'stopped');
    hub_enqueue_command_job($db, 'service_start', $serviceId, [], null, '127.0.0.1');

    $result = hub_test_admin_market_request(['view' => 'services'], [
        'csrf_token' => 'test',
        'service_id' => (string)$serviceId,
        'action' => 'remove',
    ], true, true);
    $payload = json_decode($result['stdout'], true);

    hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'busy removal must return JSON');
    hub_test_assert($result['http_status'] === 409, 'busy removal must return HTTP 409');
    hub_test_assert(
        ($payload['ok'] ?? true) === false
            && ($payload['error'] ?? '') === '服務尚未停止或仍有背景工作，暫時無法移除。',
        'busy removal must return the safe localized error'
    );
    hub_test_assert(!str_contains($result['stdout'], 'Cannot enqueue'), 'busy removal must not expose queue exception text');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn() === 1, 'busy removal must not add a command job');
});

hub_test('canonical service removal rejects a running service before queue admission', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    hub_update_service_status($db, $serviceId, 'running');

    $result = hub_test_admin_market_request(['view' => 'services'], [
        'csrf_token' => 'test',
        'service_id' => (string)$serviceId,
        'action' => 'remove',
    ], true, true);
    $payload = json_decode($result['stdout'], true);

    hub_test_assert($result['exit_code'] === 0 && is_array($payload), 'running removal must return JSON');
    hub_test_assert($result['http_status'] === 409, 'running removal must return HTTP 409');
    hub_test_assert(
        ($payload['ok'] ?? true) === false
            && ($payload['error'] ?? '') === '服務尚未停止或仍有背景工作，暫時無法移除。',
        'running removal must return the safe localized error'
    );
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM command_jobs')->fetchColumn() === 0, 'running removal must not add a command job');
});

hub_test('canonical service removal client and legacy whitelist contracts are explicit', function (): void {
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    $services = (string)file_get_contents(HUB_ROOT . '/admin/services.php');
    $servicesJs = (string)file_get_contents(HUB_ROOT . '/assets/js/services.js');

    foreach ([
        "'remove' => 'service_remove'",
        "'action_service_remove' => __('移除服務')",
        "'remove_confirm' => __('確定移除此服務嗎？服務設定將刪除，模型與既有產物會保留。')",
        'hub_service_removal_block_reason($db, $service)',
    ] as $needle) {
        hub_test_assert(str_contains($marketplace, $needle), 'canonical removal contract missing ' . $needle);
    }
    foreach ([
        "service_remove: t('action_service_remove', '移除服務')",
        "action === 'remove' && !window.confirm(t('remove_confirm'",
        "job.action === 'service_remove'",
        'scheduleReload($box);',
    ] as $needle) {
        hub_test_assert(str_contains($servicesJs, $needle), 'service removal client contract missing ' . $needle);
    }
    $pdoExceptionCatch = strpos($marketplace, 'catch (PDOException)');
    $runtimeExceptionCatch = strpos($marketplace, 'catch (RuntimeException $e)');
    hub_test_assert(
        $pdoExceptionCatch !== false && $runtimeExceptionCatch !== false && $pdoExceptionCatch < $runtimeExceptionCatch,
        'PDOException must be handled before RuntimeException so database failures never become conflicts'
    );
    foreach ([$marketplace, $services] as $page) {
        hub_test_assert(!str_contains($page, 'service_whitelist.php?service_id='), 'retired whitelist link must stay off service pages');
        hub_test_assert(!str_contains($page, '舊版 IP 白名單'), 'retired whitelist label must stay off service pages');
    }
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

hub_test('command job payload carries service flags without a global summary snapshot', function (): void {
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
    hub_test_assert(!array_key_exists('summary', $payload), 'per-job payload must not carry a stale global summary snapshot');
});

hub_test('authoritative service summary helper and endpoint track current DB state', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $serviceId = (int)$service['id'];
    $db->prepare(
        "UPDATE services
         SET enabled = 0, status = 'stopped', runtime_status = 'stopped'
         WHERE id = :id"
    )->execute([':id' => $serviceId]);
    $jobId = hub_enqueue_command_job($db, 'service_start', $serviceId, ['reason' => 'summary-refresh-test'], null, '127.0.0.1');

    $queued = hub_command_service_summary($db);
    hub_test_assert($queued === [
        'total' => 1,
        'running' => 0,
        'stopped' => 1,
        'disabled' => 1,
        'active_jobs' => 1,
        'failed_jobs' => 0,
    ], 'queued authoritative summary mismatch');

    $db->prepare("UPDATE command_jobs SET status = 'running' WHERE id = :id")->execute([':id' => $jobId]);
    $running = hub_command_service_summary($db);
    hub_test_assert($running['active_jobs'] === 1 && $running['failed_jobs'] === 0, 'running authoritative summary mismatch');

    $db->prepare(
        "UPDATE services SET enabled = 1, status = 'running', runtime_status = 'running' WHERE id = :id"
    )->execute([':id' => $serviceId]);
    $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    $success = hub_command_service_summary($db);
    hub_test_assert(
        $success['running'] === 1
            && $success['stopped'] === 0
            && $success['disabled'] === 0
            && $success['active_jobs'] === 0,
        'successful authoritative summary mismatch'
    );

    $db->prepare("UPDATE command_jobs SET status = 'failed' WHERE id = :id")->execute([':id' => $jobId]);
    $failed = hub_command_service_summary($db);
    hub_test_assert($failed['active_jobs'] === 0 && $failed['failed_jobs'] === 1, 'failed authoritative summary mismatch');

    $result = hub_test_admin_job_status_request(['summary' => '1']);
    $response = json_decode($result['stdout'], true);
    hub_test_assert($result['exit_code'] === 0 && is_array($response), 'service summary endpoint must return JSON');
    hub_test_assert(($response['ok'] ?? false) === true, 'service summary endpoint must report success');
    hub_test_assert(($response['summary'] ?? null) === $failed, 'service summary endpoint must return current DB state');
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

hub_test('canonical installed services keeps the latest failure detail collapsed', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $jobId = hub_enqueue_command_job(
        $db,
        'service_rebuild',
        (int)$service['id'],
        ['reason' => 'collapsed-failure-test'],
        null,
        '127.0.0.1'
    );
    $db->prepare(
        "UPDATE command_jobs
         SET status = 'failed', error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    )->execute([
        ':error_message' => 'sensitive docker failure detail',
        ':finished_at' => hub_now(),
        ':updated_at' => hub_now(),
        ':id' => $jobId,
    ]);

    $result = hub_test_admin_market_request(['view' => 'services']);
    hub_test_assert($result['exit_code'] === 0, 'canonical services failure render failed: ' . $result['output']);
    hub_test_assert(
        str_contains($result['stdout'], '<details class="service-required-error"')
            && str_contains($result['stdout'], '<summary>最近失敗工作</summary>')
            && str_contains($result['stdout'], 'sensitive docker failure detail'),
        'latest failure must remain available inside a collapsed detail'
    );
    hub_test_assert(
        preg_match('/<details class="service-required-error"[^>]*\bopen\b/i', $result['stdout']) !== 1,
        'latest failure detail must be collapsed by default'
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

hub_test('Marketplace shows GPT-SoVITS promotion level and fixed assets', function (): void {
    $db = hub_test_reset_db();
    $gptSoVits = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($gptSoVits !== null && $gptSoVits['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    hub_test_assert(($gptSoVits['manifest']['runtime_level'] ?? '') === 'L4-local-model', 'GPT-SoVITS must publish its verified L4 level');
    hub_test_assert(hub_admin_market_runtime_label('L3-adapter') === 'L3 服務介接', 'L3 adapter label mismatch');
    hub_test_assert(hub_admin_market_runtime_badge_class('L3-adapter') === 'pack-badge pack-badge-warn', 'L3 adapter badge mismatch');
    hub_test_assert(hub_admin_market_runtime_label('L4-local-model') === 'L4 本機模型', 'generic L4 local model label mismatch');
    hub_test_assert(hub_admin_market_runtime_badge_class('L4-local-model') === 'pack-badge pack-badge-purple', 'generic L4 local model badge mismatch');
    foreach (['packs', 'marketplace'] as $surface) {
        $label = hub_admin_market_model_label($db, $gptSoVits['manifest'], $surface);
        hub_test_assert($label['label'] === '缺少模型', $surface . ' GPT-SoVITS missing fixed model label mismatch');
    }
});
