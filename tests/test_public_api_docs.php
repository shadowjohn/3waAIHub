<?php
declare(strict_types=1);

function hub_test_make_documentable_pack(PDO $db, string $packId, array $state = []): array
{
    $pack = hub_get_pack($packId);
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'test Pack unavailable: ' . $packId);
    $manifest = $pack['manifest'];
    $installed = hub_install_pack($db, $packId, [
        'service_key' => (string)($state['service_key'] ?? $manifest['install']['default_service_key'] ?? ($packId . '-main')),
        'idempotent' => true,
    ]);
    $service = $installed['service'];
    $stmt = $db->prepare(
        'UPDATE services SET mode = :mode, health_url = :health_url, install_status = :install_status,
            enabled = :enabled, runtime_status = :runtime_status, status = :status WHERE id = :id'
    );
    $stmt->execute([
        ':mode' => (string)($state['mode'] ?? $service['mode']),
        ':health_url' => (string)($state['health_url'] ?? $service['health_url']),
        ':install_status' => (string)($state['install_status'] ?? 'installed'),
        ':enabled' => (int)($state['enabled'] ?? 1),
        ':runtime_status' => (string)($state['runtime_status'] ?? 'running'),
        ':status' => (string)($state['status'] ?? 'running'),
        ':id' => (int)$service['id'],
    ]);

    return hub_get_service($db, (int)$service['id']) ?: [];
}

function hub_test_public_api_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('cannot allocate public API test port: ' . $error);
    }
    $address = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $separator = strrpos($address, ':');
    if ($separator === false) {
        throw new RuntimeException('cannot parse public API test port');
    }

    return (int)substr($address, $separator + 1);
}

function hub_test_public_api_start_server(string $router, array $environment = []): array
{
    $port = hub_test_public_api_free_port();
    $stdout = tempnam(sys_get_temp_dir(), '3waaihub_public_api_out_');
    $stderr = tempnam(sys_get_temp_dir(), '3waaihub_public_api_err_');
    if ($stdout === false || $stderr === false) {
        if (is_string($stdout) && is_file($stdout)) {
            unlink($stdout);
        }
        if (is_string($stderr) && is_file($stderr)) {
            unlink($stderr);
        }
        throw new RuntimeException('cannot allocate public API server logs');
    }
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']],
        $pipes,
        HUB_ROOT,
        hub_process_environment($environment)
    );
    if (!is_resource($process)) {
        unlink($stdout);
        unlink($stderr);
        throw new RuntimeException('cannot start public API test server');
    }
    fclose($pipes[0]);
    $server = ['port' => $port, 'process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];

    $deadline = microtime(true) + 5.0;
    do {
        $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 0.1);
        if ($socket !== false) {
            fclose($socket);
            return $server;
        }
        if (empty(proc_get_status($process)['running'])) {
            $message = trim((string)file_get_contents($stderr));
            hub_test_public_api_stop_servers([$server]);
            throw new RuntimeException('public API test server exited: ' . $message);
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    hub_test_public_api_stop_servers([$server]);
    throw new RuntimeException('public API test server did not become ready');
}

function hub_test_public_api_stop_servers(array $servers): void
{
    foreach (array_reverse($servers) as $server) {
        if (is_resource($server['process'])) {
            $status = proc_get_status($server['process']);
            if (!empty($status['running'])) {
                proc_terminate($server['process']);
                usleep(100000);
                if (!empty(proc_get_status($server['process'])['running'])) {
                    proc_terminate($server['process'], 9);
                }
            }
            proc_close($server['process']);
        }
        foreach (['stdout', 'stderr'] as $key) {
            if (is_file((string)$server[$key])) {
                unlink((string)$server[$key]);
            }
        }
    }
}

hub_test('Public API production health batch requires completed direct loopback transfers', function (): void {
    if (hub_platform_id() !== 'linux' || !function_exists('curl_multi_init') || !function_exists('proc_open')) {
        hub_test_skip('real curl_multi loopback test requires Linux, cURL multi, and proc_open');
    }
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $servers = [];
    $routerDir = sys_get_temp_dir() . '/3waaihub_public_api_health_' . bin2hex(random_bytes(8));
    $proxyKeys = ['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY'];
    $originalEnvironment = [];
    foreach ($proxyKeys as $key) {
        $originalEnvironment[$key] = getenv($key);
        putenv($key);
    }

    try {
        if (!mkdir($routerDir, 0775, true) && !is_dir($routerDir)) {
            throw new RuntimeException('cannot create public API health server directory');
        }
        $router = $routerDir . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json');
if (getenv('AIHUB_PUBLIC_API_TEST_PROXY') === '1' || $path === '/healthy') {
    echo '{"ok":true}';
    return;
}
if ($path === '/oversized') {
    $body = str_repeat('x', 65537);
    header('Content-Length: ' . strlen($body));
    echo $body;
    return;
}
if ($path === '/stall') {
    $body = '{"ok":true}';
    header('Content-Length: ' . strlen($body));
    ob_implicit_flush(true);
    echo '{"ok":';
    flush();
    usleep(1500000);
    echo 'true}';
    return;
}
echo '{"ok":false}';
PHP);

        $servers[] = hub_test_public_api_start_server($router);
        $servers[] = hub_test_public_api_start_server($router);
        $servers[] = hub_test_public_api_start_server($router, ['AIHUB_PUBLIC_API_TEST_PROXY' => '1']);
        [$direct, $stalled, $proxy] = $servers;

        $db = hub_test_reset_db();
        $healthyRow = hub_test_make_documentable_pack($db, 'hello', [
            'mode' => 'multi_healthy',
            'health_url' => 'http://127.0.0.1:' . $direct['port'] . '/healthy',
        ]);
        hub_test_make_documentable_pack($db, 'image-birefnet', [
            'mode' => 'multi_stalled',
            'health_url' => 'http://127.0.0.1:' . $stalled['port'] . '/stall',
        ]);
        hub_test_make_documentable_pack($db, 'translate-gemma12b', [
            'mode' => 'multi_oversized',
            'health_url' => 'http://127.0.0.1:' . $direct['port'] . '/oversized',
        ]);
        $batchModes = array_column(hub_public_api_services($db), 'mode');

        $db->exec('UPDATE services SET enabled = 0');
        $db->prepare(
            "UPDATE services SET mode = 'proxy_guard', health_url = :health_url, enabled = 1,
                install_status = 'installed', runtime_status = 'running', status = 'running' WHERE id = :id"
        )->execute([
            ':health_url' => 'http://127.0.0.1:' . $direct['port'] . '/reject',
            ':id' => (int)$healthyRow['id'],
        ]);
        foreach (['http_proxy', 'HTTP_PROXY', 'all_proxy', 'ALL_PROXY'] as $key) {
            putenv($key . '=http://127.0.0.1:' . $proxy['port']);
        }
        putenv('no_proxy=');
        putenv('NO_PROXY=');
        $proxyModes = array_column(hub_public_api_services($db), 'mode');

        hub_test_assert(in_array('multi_healthy', $batchModes, true), 'completed local HTTP 200 health response rejected');
        hub_test_assert(!in_array('multi_stalled', $batchModes, true), 'timed-out partial health response accepted');
        hub_test_assert(!in_array('multi_oversized', $batchModes, true), 'oversized health response accepted');
        hub_test_assert(!in_array('proxy_guard', $proxyModes, true), 'loopback health request inherited a proxy');
    } finally {
        foreach ($originalEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        hub_test_public_api_stop_servers($servers);
        if (is_file($routerDir . '/router.php')) {
            unlink($routerDir . '/router.php');
        }
        if (is_dir($routerDir)) {
            rmdir($routerDir);
        }
    }
});

hub_test('Public API health batch bounds huge candidate sets', function (): void {
    if (hub_platform_id() !== 'linux' || !function_exists('curl_multi_init') || !function_exists('proc_open')) {
        hub_test_skip('real curl_multi stress test requires Linux, cURL multi, and proc_open');
    }
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $servers = [];
    $routerDir = sys_get_temp_dir() . '/3waaihub_public_api_stress_' . bin2hex(random_bytes(8));
    try {
        if (!mkdir($routerDir, 0775, true) && !is_dir($routerDir)) {
            throw new RuntimeException('cannot create public API stress server directory');
        }
        $router = $routerDir . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: application/json');
echo '{"ok":true}';
PHP);
        $servers[] = hub_test_public_api_start_server($router);
        $url = 'http://127.0.0.1:' . $servers[0]['port'] . '/health';
        $services = [];
        for ($id = 1; $id <= 10000; $id++) {
            $services[] = ['id' => $id, 'health_url' => $url];
        }

        $started = microtime(true);
        $healthy = hub_public_api_healthy_service_ids($services);
        $elapsed = microtime(true) - $started;

        hub_test_assert($elapsed < 1.5, '10,000-candidate health batch exceeded 1.5 seconds: ' . number_format($elapsed, 3));
        hub_test_assert(count($healthy) <= 128, 'health batch probed more than 128 HTTP services');
    } finally {
        hub_test_public_api_stop_servers($servers);
        if (is_file($routerDir . '/router.php')) {
            unlink($routerDir . '/router.php');
        }
        if (is_dir($routerDir)) {
            rmdir($routerDir);
        }
    }
});

hub_test('Public API health cap keeps later internal-task services healthy', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $db = hub_test_reset_db();
    $internal = hub_test_make_documentable_pack($db, 'docparser');
    hub_test_assert(
        hub_service_is_internal_task($internal) && (string)$internal['health_url'] === 'internal-task:health',
        'internal-task fixture is invalid'
    );
    $services = [];
    for ($id = 1000; $id < 1129; $id++) {
        $services[] = ['id' => $id, 'health_url' => 'http://127.0.0.1:1/health'];
    }
    $services[] = $internal;

    $healthy = hub_public_api_healthy_service_ids($services);

    hub_test_assert(isset($healthy[(int)$internal['id']]), 'HTTP probe cap hid a later internal-task service');
});

hub_test('Public API inventory requires installed enabled running and healthy services', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $db = hub_test_reset_db();

    hub_test_make_documentable_pack($db, 'hello', ['mode' => 'hello_live']);
    hub_test_make_documentable_pack($db, 'ocr-ppocrv5', ['enabled' => 0]);
    hub_test_make_documentable_pack($db, 'yolo', ['runtime_status' => 'stopped', 'status' => 'stopped']);
    hub_test_make_documentable_pack($db, 'translate-gemma12b', ['install_status' => 'pending']);
    hub_test_make_documentable_pack($db, 'sam3', ['health_url' => 'http://198.51.100.8/health']);
    hub_test_make_documentable_pack($db, 'image-birefnet');
    hub_test_make_documentable_pack($db, 'docparser');
    hub_test_make_documentable_pack($db, 'llm-gemma4-12b');
    hub_test_make_documentable_pack($db, 'yolo-serving');

    $probedModes = [];
    $probe = static function (array $service) use (&$probedModes): bool {
        $probedModes[] = (string)$service['mode'];
        return in_array((string)$service['mode'], ['hello_live', 'chat'], true);
    };
    $services = hub_public_api_services($db, $probe);
    $modes = array_column($services, 'mode');

    hub_test_assert(in_array('hello_live', $modes, true), 'service row mode must be documented');
    hub_test_assert(in_array('docparser', $modes, true), 'running internal task must be documented');
    hub_test_assert(in_array('photo_upload', $modes, true), 'healthy Gemma parent must expose photo APIs');
    hub_test_assert(!in_array('ocr', $modes, true), 'disabled service must be hidden');
    hub_test_assert(!in_array('yolo', $modes, true), 'stopped service must be hidden');
    hub_test_assert(!in_array('translate', $modes, true), 'not-installed service must be hidden');
    hub_test_assert(!in_array('sam3', $modes, true), 'non-loopback health URL must be hidden');
    hub_test_assert(!in_array('sam3', $probedModes, true), 'non-loopback health URL must be rejected before probing');
    hub_test_assert(!in_array('background_remove', $modes, true), 'failed health probe must hide service');
    hub_test_assert(in_array('hello_live', $modes, true), 'unhealthy service must not hide a healthy sibling');
    hub_test_assert(!in_array('yolo_model_register', $modes, true), 'unhealthy YOLO parent must hide derived APIs');

    $allHealthy = hub_public_api_services($db, static fn (array $service): bool => true);
    $allHealthyModes = array_column($allHealthy, 'mode');
    hub_test_assert(!in_array('sam3', $allHealthyModes, true), 'non-loopback URL must stay hidden when probe returns true');
    hub_test_assert(in_array('yolo_model_register', $allHealthyModes, true), 'healthy YOLO parent must expose derived APIs');

    $gemmaUnhealthy = hub_public_api_services(
        $db,
        static fn (array $service): bool => (string)$service['mode'] !== 'chat'
    );
    hub_test_assert(!in_array('photo_upload', array_column($gemmaUnhealthy, 'mode'), true), 'unhealthy Gemma parent must hide photo APIs');

    hub_test_assert(hub_public_api_health_url_allowed('http://127.0.0.1/health'), 'IPv4 loopback health URL rejected');
    hub_test_assert(hub_public_api_health_url_allowed('http://[::1]/health'), 'IPv6 loopback health URL rejected');
    hub_test_assert(hub_public_api_health_url_allowed('http://localhost/health'), 'localhost health URL rejected');
    hub_test_assert(!hub_public_api_health_url_allowed('https://localhost/health'), 'HTTPS health URL accepted');
    hub_test_assert(!hub_public_api_health_url_allowed('http://user@localhost/health'), 'health URL userinfo accepted');
    hub_test_assert(hub_public_api_health_response_ok(200, '{"ok":true}'), 'healthy JSON response rejected');
    hub_test_assert(hub_public_api_health_response_ok(204, ''), 'empty success response rejected');
    hub_test_assert(!hub_public_api_health_response_ok(503, '{"ok":true}'), 'HTTP failure accepted');
    hub_test_assert(!hub_public_api_health_response_ok(200, '{"ok":false}'), 'ok=false accepted');
    hub_test_assert(!hub_public_api_health_response_ok(200, '{"ready":false}'), 'ready=false accepted');

    $emptyDb = hub_test_reset_db();
    $emptyDb->exec('DELETE FROM services');
    $emptyManifest = hub_public_api_manifest($emptyDb, static fn (array $service): bool => true);
    hub_test_assert(($emptyManifest['services'] ?? null) === [], 'empty inventory must not fall back to repository Packs');
    $emptyHtml = hub_public_api_docs_html($emptyDb, null, static fn (array $service): bool => true);
    hub_test_assert(str_contains($emptyHtml, '目前沒有健康且可用的 API 服務。'), 'empty public docs must show the API empty state');
    hub_test_assert(!str_contains($emptyHtml, 'quality_report.missing_translation_blocks'), 'empty public docs must hide the DocParser repair hint');
    hub_test_assert(!str_contains($emptyHtml, 'href="#local-jobs"'), 'empty public docs must hide the YOLO Local Jobs tab');
    hub_test_assert(!str_contains($emptyHtml, '<section id="local-jobs"'), 'empty public docs must hide the YOLO Local Jobs section');
    hub_test_assert(!str_contains($emptyHtml, '<article class="card">'), 'empty public docs must not render service cards');
});

hub_test('Public API publishes installed stopped async Pack routes from canonical contracts', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $db = hub_test_reset_db();
    hub_test_make_documentable_pack($db, 'hello', ['mode' => 'hello_live']);
    $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-stopped-async-docs']);
    $db->prepare(
        "UPDATE services
         SET mode = 'tts', install_status = 'installed', enabled = 1,
             status = 'stopped', runtime_status = 'stopped'
         WHERE id = :id"
    )->execute([':id' => (int)$installed['service']['id']]);

    $available = hub_available_pack_job_async_modes($db);
    $services = array_column(
        hub_public_api_services($db, static fn (array $service): bool => (string)$service['mode'] === 'hello_live'),
        null,
        'mode'
    );
    $voice = $services['voice_generate'] ?? null;

    hub_test_assert($available === array_values(array_unique($available)) && $available === array_values(array_filter(
        $available,
        static fn (string $mode): bool => in_array($mode, array_keys(hub_pack_job_async_routes()), true)
    )), 'available async modes must be unique canonical route keys');
    $sorted = $available;
    sort($sorted, SORT_STRING);
    hub_test_assert($available === $sorted && in_array('voice_generate', $available, true), 'installed stopped VoxCPM2 must publish sorted voice_generate inventory');
    hub_test_assert(isset($services['hello_live']), 'healthy sync services must remain documented');
    hub_test_assert(is_array($voice) && ($voice['pack_id'] ?? '') === 'tts-voxcpm2', 'stopped sync TTS row must publish the canonical async voice_generate contract');
    hub_test_assert(array_column((array)$voice['operations'], 'operation') === [
        'profile_prepare', 'profile_status', 'profile_confirm', 'profile_delete', 'synthesize',
    ], 'voice_generate must document all profile and synthesis operations in stable order');
    $operations = array_column((array)$voice['operations'], null, 'operation');
    $synthesize = array_column((array)$voice['operations'], null, 'operation')['synthesize'] ?? [];
    hub_test_assert(($synthesize['modes'] ?? null) === ['design', 'clone', 'ultimate_clone'], 'voice_generate synthesis must document every supported mode');
    $nativeFields = array_column((array)$voice['input_fields'], 'name');
    hub_test_assert(in_array('voice_profile_id', $nativeFields, true) && in_array('voice_profile_task_id', $nativeFields, true), 'native voice_generate must expose its direct profile references');
    $profileStatus = array_column((array)$voice['operations'], null, 'operation')['profile_status'] ?? [];
    $conditionalOutputs = array_column((array)($profileStatus['conditional_output_fields'] ?? []), null, 'name');
    hub_test_assert(
        !in_array('prompt_text', (array)($profileStatus['output_keys'] ?? []), true)
        && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'authenticated Profile member')
        && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'transcript_confirmed=false')
        && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'omitted after confirmation'),
        'native profile_status must document owner-only unconfirmed ASR draft visibility'
    );
    $statusOutput = [
        'ok', 'task_status', 'profile_status', 'transcription_status', 'transcription_error',
        'transcript_confirmed', 'prompt_text_confirmed_at', 'profile_name', 'language',
        'consent_type', 'reference_audio_sha256', 'created_at', 'updated_at',
    ];
    hub_test_assert(($profileStatus['output_keys'] ?? null) === $statusOutput, 'profile_status must document its exact bounded transcription error field');
    hub_test_assert(($operations['profile_confirm']['output_keys'] ?? null) === $statusOutput, 'profile_confirm must document its actual safe status response');
    hub_test_assert(($operations['profile_delete']['output_keys'] ?? null) === $statusOutput, 'profile_delete must document its actual safe status response');

    $errors = array_column((array)($voice['error_table'] ?? []), null, 'code');
    foreach ([
        'invalid_request' => 400,
        'voice_profile_wav_invalid' => 400,
        'voice_profile_transcript_invalid' => 400,
        'voice_profile_forbidden' => 403,
        'voice_profile_transcript_unconfirmed' => 409,
        'voice_profile_unavailable' => 410,
        'voice_profile_not_found' => 404,
        'voice_profile_prepare_conflict' => 409,
        'voice_profile_callback_conflict' => 409,
        'voice_profile_prepare_incomplete' => 409,
        'voice_profile_confirm_failed' => 409,
        'voice_profile_prepare_failed' => 500,
        'voice_profile_delete_failed' => 500,
        'pack_runtime_not_ready' => 503,
    ] as $code => $status) {
        hub_test_assert(($errors[$code]['http_status'] ?? null) === $status, 'native voice_generate error status mismatch: ' . $code);
    }
    hub_test_assert(($errors['voice_profile_changed']['task_status'] ?? null) === 'failed', 'voice profile mutation must be documented as an asynchronous task failure');
    hub_test_assert(!isset($errors['profile_task_not_found'], $errors['station_unavailable']), 'native voice_generate must not claim Router-only errors');

    foreach ((array)$voice['workflow_examples'] as $kind => $example) {
        $example = (string)$example;
        foreach (['<TOKEN>', '<REFERENCE_WAV>', '<VOICE_PROFILE_TASK_ID>', '<CONFIRMED_TRANSCRIPT>', '<TASK_ID>', '<ARTIFACT_ID>'] as $placeholder) {
            hub_test_assert(str_contains($example, $placeholder), 'native ' . $kind . ' workflow example missing placeholder ' . $placeholder);
        }
        foreach (['voice_profile_id=', '3wa_live_', '/home/', '/data/', 'http://', 'https://'] as $forbidden) {
            hub_test_assert(!str_contains($example, $forbidden), 'native ' . $kind . ' workflow example leaked a concrete value: ' . $forbidden);
        }
        hub_test_assert(str_contains($example, 'profile_delete'), 'native ' . $kind . ' workflow example must explicitly delete the profile');
    }
});

hub_test('Available async Pack inventory rejects missing disabled stale and runtime-unready Packs', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $published = static function (PDO $db): bool {
        return in_array('voice_generate', hub_available_pack_job_async_modes($db), true)
            || in_array('voice_generate', hub_cluster_node_published_modes($db), true)
            || in_array('voice_generate', array_column(hub_public_api_services($db, static fn (array $service): bool => true), 'mode'), true);
    };

    $missing = hub_test_reset_db();
    hub_test_assert(!$published($missing), 'missing VoxCPM2 Pack must not publish voice_generate');

    $disabled = hub_test_reset_db();
    $service = hub_install_pack($disabled, 'tts-voxcpm2', ['service_key' => 'tts-disabled-async-docs'])['service'];
    $disabled->prepare("UPDATE services SET mode = 'tts', enabled = 0 WHERE id = :id")->execute([':id' => (int)$service['id']]);
    hub_test_assert(!$published($disabled), 'disabled VoxCPM2 Pack must not publish voice_generate');

    $stale = hub_test_reset_db();
    $service = hub_install_pack($stale, 'tts-voxcpm2', ['service_key' => 'tts-stale-async-docs'])['service'];
    $stale->prepare("UPDATE services SET mode = 'tts', enabled = 1, pack_version = 'stale-version' WHERE id = :id")
        ->execute([':id' => (int)$service['id']]);
    hub_test_assert(!$published($stale), 'invalid VoxCPM2 Pack version must not publish voice_generate');

    $runtime = hub_test_reset_db();
    $service = hub_install_pack($runtime, 'tts-voxcpm2', ['service_key' => 'tts-runtime-async-docs'])['service'];
    $runtime->prepare("UPDATE services SET mode = 'tts', enabled = 1 WHERE id = :id")->execute([':id' => (int)$service['id']]);
    $manifestPath = HUB_ROOT . '/packs/tts-voxcpm2/pack.json';
    $manifestHash = hash_file('sha256', $manifestPath);
    $packs = hub_list_packs();
    foreach ($packs as &$pack) {
        if (($pack['id'] ?? '') === 'tts-voxcpm2') {
            $pack['manifest']['runtime_ready'] = false;
        }
    }
    unset($pack);
    try {
        $available = hub_available_pack_job_async_modes_with_catalog($runtime, static fn (): array => $packs);
        hub_test_assert(!in_array('voice_generate', $available, true), 'runtime-unready VoxCPM2 Pack must not publish voice_generate');
        try {
            hub_available_pack_job_async_modes_with_catalog(
                $runtime,
                static fn (): array => $packs,
                static function (): array {
                    throw new LogicException('injected_resolver_failure');
                }
            );
            hub_test_assert(false, 'injected resolver failure must escape async inventory');
        } catch (LogicException $e) {
            hub_test_assert($e->getMessage() === 'injected_resolver_failure', 'unexpected injected resolver failure');
        }
    } finally {
        clearstatcache(true, $manifestPath);
        hub_test_assert(hash_file('sha256', $manifestPath) === $manifestHash, 'runtime-unready fixture must never edit the tracked Pack manifest');
    }
});

hub_test('Available async Pack inventory scans once per call and propagates infrastructure failures', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-async-batch-docs'])['service'];
    $db->prepare("UPDATE services SET mode = 'tts', enabled = 1 WHERE id = :id")->execute([':id' => (int)$service['id']]);
    $packs = hub_list_packs();
    $scans = 0;
    $loader = static function () use (&$scans, &$packs): array {
        $scans++;
        return $packs;
    };

    $first = hub_available_pack_job_async_modes_with_catalog($db, $loader);
    hub_test_assert($scans === 1 && in_array('voice_generate', $first, true), 'async inventory must scan the Pack catalog once per call');
    foreach ($packs as &$pack) {
        if (($pack['id'] ?? '') === 'tts-voxcpm2') {
            $pack['manifest']['runtime_ready'] = false;
        }
    }
    unset($pack);
    $second = hub_available_pack_job_async_modes_with_catalog($db, $loader);
    hub_test_assert($scans === 2 && !in_array('voice_generate', $second, true), 'per-call catalog reuse must not become a stale cross-mutation cache');

    $broken = hub_test_reset_db();
    $broken->exec('DROP TABLE services');
    try {
        hub_available_pack_job_async_modes($broken);
        hub_test_assert(false, 'systemic async inventory DB failure must propagate');
    } catch (PDOException) {
    }
});

hub_test('Public API services consume one async route detail batch without silent second-pass failures', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-public-batch-docs'])['service'];
    $db->prepare(
        "UPDATE services
         SET mode = 'tts', enabled = 1, install_status = 'installed',
             status = 'stopped', runtime_status = 'stopped'
         WHERE id = :id"
    )->execute([':id' => (int)$service['id']]);

    $packs = hub_list_packs();
    $scans = 0;
    $voiceResolutions = 0;
    $loader = static function () use (&$scans, $packs): array {
        $scans++;
        return $packs;
    };
    $resolver = static function (PDO $db, string $mode, ?array $pack) use (&$voiceResolutions): array {
        if ($mode === 'voice_generate' && ++$voiceResolutions > 1) {
            throw new LogicException('injected_second_pass_failure');
        }

        return hub_resolve_pack_job_async_route_from_pack($db, $mode, $pack);
    };

    $services = array_column(
        hub_public_api_services($db, static fn (array $service): bool => true, $loader, $resolver),
        null,
        'mode'
    );
    hub_test_assert(
        $scans === 1 && $voiceResolutions === 1 && isset($services['voice_generate']),
        'public services must consume one catalog scan and one resolved voice route detail'
    );

    try {
        hub_public_api_services(
            $db,
            static fn (array $service): bool => true,
            static fn (): array => $packs,
            static function (PDO $db, string $mode, ?array $pack): array {
                if ($mode === 'voice_generate') {
                    throw new LogicException('injected_async_batch_failure');
                }

                return hub_resolve_pack_job_async_route_from_pack($db, $mode, $pack);
            }
        );
        hub_test_assert(false, 'public services must propagate async batch infrastructure failures');
    } catch (LogicException $e) {
        hub_test_assert($e->getMessage() === 'injected_async_batch_failure', 'unexpected public async batch failure');
    }
});

hub_test('Public API inventory hides unconditionally reserved DB service modes', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $rendered = [];

    foreach (['task_status', 'yolo_gpu_internal'] as $mode) {
        $db = hub_test_reset_db();
        hub_test_make_documentable_pack($db, 'hello', ['mode' => $mode]);
        if (in_array($mode, array_column(hub_public_api_services($db, static fn (array $service): bool => true), 'mode'), true)) {
            $rendered[] = $mode;
        }
    }

    hub_test_assert($rendered === [], 'reserved gateway modes rendered Pack contracts: ' . implode(', ', $rendered));
});

hub_test('Public API audio async DB modes require their canonical owning packs', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $unexpected = [];
    $missing = [];

    foreach (hub_audio_async_routes() as $mode => $route) {
        $mismatchDb = hub_test_reset_db();
        hub_test_make_documentable_pack($mismatchDb, 'hello', ['mode' => $mode]);
        if (in_array($mode, array_column(hub_public_api_services($mismatchDb, static fn (array $service): bool => true), 'mode'), true)) {
            $unexpected[] = $mode;
        }

        $ownerDb = hub_test_reset_db();
        hub_test_make_documentable_pack($ownerDb, (string)$route['pack_id'], ['mode' => $mode]);
        if (!in_array($mode, array_column(hub_public_api_services($ownerDb, static fn (array $service): bool => true), 'mode'), true)) {
            $missing[] = $mode;
        }
    }

    hub_test_assert($unexpected === [], 'mismatched Packs rendered audio async contracts: ' . implode(', ', $unexpected));
    hub_test_assert($missing === [], 'canonical audio Packs lost async contracts: ' . implode(', ', $missing));
});

hub_test('Public API audio async contracts use normalized job routes', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $outputKeys = ['ok', 'task_id', 'status', 'status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'];
    $taskRefs = ['status', 'result', 'log', 'cancel', 'artifact'];

    foreach (hub_audio_async_routes() as $mode => $owner) {
        $db = hub_test_reset_db();
        hub_test_make_documentable_pack($db, (string)$owner['pack_id'], ['mode' => $mode]);
        $route = hub_resolve_audio_async_route($db, $mode);
        $services = array_column(hub_public_api_services($db, static fn (array $service): bool => true), null, 'mode');
        $service = $services[$mode] ?? null;

        hub_test_assert(is_array($service), 'audio async contract missing: ' . $mode);
        hub_test_assert(
            $service['method'] === 'POST'
            && $service['content_type'] === 'multipart/form-data'
            && $service['execution_type'] === 'async_task'
            && $service['task_type'] === 'pack_job',
            'audio async transport contract mismatch: ' . $mode
        );
        hub_test_assert($service['output_keys'] === $outputKeys, 'audio async output contract mismatch: ' . $mode);
        hub_test_assert(
            ($service['result_artifact_fields'] ?? null) === ['id', 'type', 'mime_type', 'size_bytes', 'sha256'],
            'audio async result.artifacts[] contract mismatch: ' . $mode
        );
        $deliveryNote = (string)($service['artifact_delivery_note'] ?? '');
        foreach (['result.artifacts[]', 'artifact_url_template', 'id'] as $needle) {
            hub_test_assert(str_contains($deliveryNote, $needle), 'audio async artifact delivery guidance missing ' . $needle . ': ' . $mode);
        }
        hub_test_assert(!str_contains($deliveryNote, 'ack_url_template'), 'native async artifact guidance must not advertise Router-only ACK: ' . $mode);
        foreach (['artifact_id', 'per-artifact artifact_url', ' bytes field'] as $obsolete) {
            hub_test_assert(!str_contains($deliveryNote, $obsolete), 'audio async artifact delivery guidance retains obsolete field ' . $obsolete . ': ' . $mode);
        }
        hub_test_assert(array_keys($service['task_api']) === $taskRefs, 'audio async task API refs mismatch: ' . $mode);
        foreach (['status', 'result', 'log', 'cancel'] as $taskMode) {
            hub_test_assert(str_contains((string)$service['task_api'][$taskMode], 'mode=task_' . $taskMode), 'audio async task ref mismatch: ' . $mode . '/' . $taskMode);
        }
        hub_test_assert(str_contains((string)$service['task_api']['artifact'], 'mode=artifact'), 'audio async artifact ref mismatch: ' . $mode);
        foreach (['payload_too_large', 'invalid_request', 'source_ambiguous', 'missing_token'] as $error) {
            hub_test_assert(in_array($error, $service['error_codes'], true), 'audio async error contract missing ' . $error . ': ' . $mode);
        }
        $html = hub_public_api_docs_html($db, null, static fn (array $service): bool => true);
        hub_test_assert(
            str_contains($html, 'result.artifacts[]')
            && str_contains($html, 'artifact_url_template')
            && !str_contains($html, 'ack_url_template'),
            'rendered async docs must publish canonical artifact delivery guidance: ' . $mode
        );

        $fields = array_column($service['input_fields'], null, 'name');
        foreach ($route['request_schema'] as $name => $definition) {
            hub_test_assert(isset($fields[$name]), 'audio async request field missing: ' . $mode . '/' . $name);
            foreach (['type', 'required', 'default', 'enum', 'max_length', 'min', 'max', 'requires', 'gte_field', 'requires_when'] as $constraint) {
                if (array_key_exists($constraint, $definition)) {
                    hub_test_assert(($fields[$name][$constraint] ?? null) === $definition[$constraint], 'audio async field constraint mismatch: ' . $mode . '/' . $name . '/' . $constraint);
                }
            }
        }

        if ($route['source_required']) {
            $oneOf = ['file', 'source_artifact_id'];
            hub_test_assert(
                ($fields['file']['type'] ?? '') === 'file'
                && ($fields['file']['required'] ?? true) === false
                && ($fields['file']['example_include'] ?? false) === true
                && ($fields['file']['example'] ?? '') === 'sample.wav'
                && ($fields['file']['max_bytes'] ?? 0) === $route['max_upload_bytes']
                && ($fields['file']['source_artifact_types'] ?? null) === $route['source_artifact_types']
                && ($fields['file']['one_of'] ?? null) === $oneOf
                && ($fields['file']['one_of_required'] ?? false) === true,
                'audio async upload field mismatch: ' . $mode
            );
            hub_test_assert(
                ($fields['source_artifact_id']['type'] ?? '') === 'integer'
                && ($fields['source_artifact_id']['required'] ?? true) === false
                && ($fields['source_artifact_id']['min'] ?? 0) === 1
                && ($fields['source_artifact_id']['one_of'] ?? null) === $oneOf
                && ($fields['source_artifact_id']['one_of_required'] ?? false) === true,
                'audio async source artifact alternative mismatch: ' . $mode
            );
            hub_test_assert(str_contains((string)$service['examples']['curl'], 'file=@sample.wav'), 'audio async curl upload missing: ' . $mode);
            hub_test_assert(str_contains((string)$service['examples']['php'], "new CURLFile('/path/to/sample.wav'"), 'audio async PHP upload missing: ' . $mode);
            hub_test_assert(str_contains((string)$service['examples']['js_fetch'], "formData.append('file', fileInput.files[0])"), 'audio async JS upload missing: ' . $mode);
            foreach ($service['examples'] as $exampleType => $example) {
                hub_test_assert(!str_contains((string)$example, 'source_artifact_id'), 'audio async default ' . $exampleType . ' example includes both source alternatives: ' . $mode);
            }
            $html = hub_public_api_docs_html($db, null, static fn (array $service): bool => true);
            hub_test_assert(
                str_contains($html, '&quot;one_of&quot;')
                && str_contains($html, '&quot;one_of_required&quot;: true'),
                'audio async one-of metadata missing from rendered HTML: ' . $mode
            );
        } else {
            hub_test_assert(!isset($fields['file']), 'source-free audio async route rendered an upload: ' . $mode);
            hub_test_assert(!isset($fields['source_artifact_id']), 'source-free audio async route rendered a source artifact alternative: ' . $mode);
            if ($mode === 'voice_generate') {
                hub_test_assert(
                    str_contains((string)$service['workflow_examples']['curl'], 'reference_wav=@<REFERENCE_WAV>')
                    && str_contains((string)$service['workflow_examples']['php'], "new CURLFile('<REFERENCE_WAV>')")
                    && !str_contains((string)$service['examples']['curl'], '=@')
                    && !str_contains((string)$service['examples']['php'], 'CURLFile'),
                    'voice profile preparation upload example missing'
                );
            } else {
                hub_test_assert(!str_contains((string)$service['examples']['curl'], '=@'), 'source-free audio async curl rendered an upload: ' . $mode);
                hub_test_assert(!str_contains((string)$service['examples']['php'], 'CURLFile'), 'source-free audio async PHP rendered an upload: ' . $mode);
            }
        }

        $exampleInput = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                continue;
            }
            if (array_key_exists('example', $field)) {
                $exampleInput[(string)$field['name']] = $field['example'];
            } elseif (array_key_exists('default', $field)) {
                $exampleInput[(string)$field['name']] = $field['default'];
            }
        }
        hub_test_assert(
            !hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input($exampleInput, $route)),
            'audio async documented example input is invalid: ' . $mode
        );

        if ($mode === 'audio_cleanup') {
            $operation = (string)($fields['operation']['example'] ?? $fields['operation']['default'] ?? '');
            hub_test_assert($operation !== '' && in_array($operation, $fields['operation']['enum'] ?? [], true), 'audio cleanup example operation is invalid');
            hub_test_assert(str_contains((string)$service['examples']['curl'], 'operation=' . $operation), 'audio cleanup curl operation missing');
        } elseif ($mode === 'speech_transcribe') {
            foreach (['model', 'language', 'word_timestamps', 'diarization'] as $field) {
                hub_test_assert(isset($fields[$field]), 'speech transcribe async field missing: ' . $field);
            }
            hub_test_assert(str_contains((string)$service['examples']['curl'], 'model=large_v3'), 'speech transcribe async model missing from curl');
        } else {
            $text = (string)($fields['text']['example'] ?? $fields['text']['default'] ?? '');
            $voicePrompt = (string)($fields['voice_prompt']['example'] ?? $fields['voice_prompt']['default'] ?? '');
            $voiceMode = (string)($fields['mode']['example'] ?? $fields['mode']['default'] ?? '');
            if ($mode === 'voice_generate') {
                hub_test_assert($text !== '' && $voiceMode === 'design' && $voicePrompt !== '', 'voice generate examples must use nonempty text/design fields');
                hub_test_assert(
                    str_contains((string)$service['examples']['curl'], 'text=' . $text)
                    && str_contains((string)$service['examples']['curl'], 'mode=design')
                    && str_contains((string)$service['examples']['curl'], 'voice_prompt=' . $voicePrompt),
                    'voice generate multipart example fields missing'
                );
            } else {
                hub_test_assert($text !== '' && $voiceMode === 'clone' && $voicePrompt === '', 'GPT-SoVITS examples must use managed clone fields only');
                hub_test_assert(
                    str_contains((string)$service['examples']['curl'], 'text=' . $text)
                    && str_contains((string)$service['examples']['curl'], 'mode=clone')
                    && !str_contains((string)$service['examples']['curl'], 'voice_prompt='),
                    'GPT-SoVITS multipart example fields must omit design prompt'
                );
            }
        }

        $json = json_encode($service, JSON_UNESCAPED_SLASHES);
        foreach (['pack_version', 'job_contract', '/models/', '/cache/', '/data/'] as $internal) {
            hub_test_assert(!str_contains((string)$json, $internal), 'audio async docs leaked internal route data: ' . $internal);
        }
    }
});

hub_test('voice workflow examples use submit links and execute optional Router ACK', function (): void {
    $native = hub_public_api_voice_generate_examples();
    $cluster = hub_public_api_voice_generate_examples(true);

    foreach ([$native, $cluster] as $examples) {
        $php = (string)$examples['php'];
        $js = (string)$examples['js_fetch'];
        hub_test_assert(
            str_contains($php, "\$synthesis['artifact_url_template']")
            && !str_contains($php, "\$result['artifact_url_template']"),
            'PHP workflow must take artifact_url_template from synthesis submit response'
        );
        hub_test_assert(
            str_contains($js, 'synthesis.artifact_url_template')
            && !str_contains($js, 'result.artifact_url_template'),
            'JavaScript workflow must take artifact_url_template from synthesis submit response'
        );
    }

    hub_test_assert(
        !str_contains((string)$native['php'], "\$result['ack_url_template']")
        && !str_contains((string)$native['js_fetch'], 'result.ack_url_template'),
        'native examples must not look for submit links in task_result'
    );
    $clusterCurl = (string)$cluster['curl'];
    hub_test_assert(
        str_contains($clusterCurl, 'ACK_URL_TEMPLATE')
        && str_contains($clusterCurl, '-X POST')
        && str_contains($clusterCurl, 'ack_url_template'),
        'Cluster curl workflow must POST the returned ACK template when present'
    );

    $links = hub_task_response_links(42);
    hub_test_assert(
        isset($links['artifact_url_template'])
        && !isset($links['ack_url_template']),
        'native submit helper must return artifact_url_template without Router ACK'
    );
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Native task result contract member');
    $token = hub_create_api_token($db, $memberId, 'Native task result contract token', null, null);
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', [
        'owner_member_id' => $memberId,
        'owner_token_id' => (int)$token['token_id'],
    ]);
    $db->prepare("UPDATE tasks SET status = 'success', result_json = :result WHERE id = :id")
        ->execute([':result' => '{"artifacts":[{"id":7}]}', ':id' => $taskId]);
    $query = $_GET;
    $_GET = ['task_id' => $taskId];
    try {
        $response = hub_api_task_result($db, ['member_id' => $memberId, 'token_id' => (int)$token['token_id']]);
    } finally {
        $_GET = $query;
    }
    $payload = json_decode((string)$response['body'], true, 32, JSON_THROW_ON_ERROR);
    hub_test_assert(
        array_keys($payload) === ['ok', 'task_id', 'result'],
        'native task_result behavior must remain limited to ok, task_id, and result'
    );
});

hub_test('voice workflow examples resolve returned links against the configured API', function (): void {
    $native = hub_public_api_voice_generate_examples();
    $cluster = hub_public_api_voice_generate_examples(true);

    foreach ([$native, $cluster] as $examples) {
        $curl = (string)$examples['curl'];
        $php = (string)$examples['php'];
        $js = (string)$examples['js_fetch'];

        foreach (['RESULT_URL_LINK', 'ARTIFACT_URL_TEMPLATE_LINK'] as $linkVariable) {
            hub_test_assert(
                str_contains($curl, $linkVariable)
                && str_contains($curl, 'resolve_url "${API}" "${' . $linkVariable . '}"'),
                'shell workflow must resolve returned ' . strtolower($linkVariable) . ' against API'
            );
        }
        hub_test_assert(
            str_contains($curl, 'if(preg_match("~\\Ahttps?://~i",$link)===1){echo $link;exit;}'),
            'shell URL resolver must preserve native absolute HTTP links'
        );

        foreach ([
            "\$statusUrl = \$resolveUrl(\$api, (string)\$prepared['status_url']);",
            "\$resultUrl = \$resolveUrl(\$api, (string)\$synthesis['result_url']);",
            "\$artifactUrlTemplate = \$resolveUrl(\$api, (string)\$synthesis['artifact_url_template']);",
        ] as $resolution) {
            hub_test_assert(str_contains($php, $resolution), 'PHP workflow missing returned-link resolution: ' . $resolution);
        }
        hub_test_assert(
            str_contains($php, "preg_match('~\\Ahttps?://~i', \$link) === 1")
            && str_contains($php, 'return $link;')
            && !str_contains($php, "\$request(\$synthesis['result_url'])"),
            'PHP URL resolver must preserve native absolute links and avoid direct relative requests'
        );

        foreach ([
            'const statusUrl = resolveUrl(prepared.status_url);',
            'const resultUrl = resolveUrl(synthesis.result_url);',
            'const artifactUrlTemplate = resolveUrl(synthesis.artifact_url_template);',
        ] as $resolution) {
            hub_test_assert(str_contains($js, $resolution), 'JavaScript workflow missing returned-link resolution: ' . $resolution);
        }
        hub_test_assert(
            str_contains($js, 'const resolveUrl = (link) => new URL(link, api).toString();')
            && !str_contains($js, 'await call(synthesis.result_url)'),
            'JavaScript workflow must resolve relative links while retaining standard absolute URL behavior'
        );
    }

    foreach ([
        'resolve_url "${API}" "${ACK_URL_TEMPLATE_LINK}"',
        "\$ackUrlTemplate = \$resolveUrl(\$api, (string)\$synthesis['ack_url_template']);",
        'const ackUrlTemplate = resolveUrl(synthesis.ack_url_template);',
    ] as $resolution) {
        hub_test_assert(
            str_contains(implode("\n", $cluster), $resolution),
            'Cluster workflow must resolve returned ACK template: ' . $resolution
        );
        hub_test_assert(
            !str_contains(implode("\n", $native), $resolution),
            'native workflow must remain valid without Router ACK resolution: ' . $resolution
        );
    }
});

hub_test('shell voice workflow follows returned profile prepare status URL', function (): void {
    foreach ([false, true] as $cluster) {
        $curl = (string)hub_public_api_voice_generate_examples($cluster)['curl'];
        $statusMode = $cluster ? 'cluster_task_status' : 'task_status';

        $preparedAt = strpos($curl, 'PREPARED="$(curl -sS');
        $statusLinkAt = strpos($curl, 'STATUS_URL_LINK="$(printf');
        $statusUrlAt = strpos($curl, 'STATUS_URL="$(resolve_url "${API}" "${STATUS_URL_LINK}")"');
        $statusRequestAt = $statusUrlAt === false ? false : strpos($curl, '"${STATUS_URL}"', $statusUrlAt + 1);

        hub_test_assert(
            $preparedAt !== false
            && str_contains($curl, 'VOICE_PROFILE_TASK_ID="$(printf')
            && str_contains($curl, '"${PREPARED}" | json_value task_id)" # <VOICE_PROFILE_TASK_ID>')
            && $statusLinkAt !== false
            && str_contains($curl, '"${PREPARED}" | json_value status_url)"')
            && $statusUrlAt !== false
            && $statusRequestAt !== false
            && $preparedAt < $statusLinkAt
            && $statusLinkAt < $statusUrlAt
            && $statusUrlAt < $statusRequestAt,
            ($cluster ? 'Cluster' : 'native') . ' shell workflow must capture and resolve returned profile_prepare status_url'
        );
        hub_test_assert(
            !str_contains($curl, '?mode=' . $statusMode . '&task_id='),
            ($cluster ? 'Cluster' : 'native') . ' shell workflow must not reconstruct the profile_prepare status URL'
        );
    }
});

hub_test('Public API audio async contracts expose optional callback controls', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';

    foreach (hub_audio_async_routes() as $mode => $owner) {
        $db = hub_test_reset_db();
        hub_test_make_documentable_pack($db, (string)$owner['pack_id'], ['mode' => $mode]);
        $services = array_column(hub_public_api_services($db, static fn (array $service): bool => true), null, 'mode');
        $service = $services[$mode] ?? null;
        $fields = is_array($service) ? array_column($service['input_fields'], null, 'name') : [];

        hub_test_assert(
            ($fields['callback']['type'] ?? '') === 'boolean'
            && ($fields['callback']['required'] ?? true) === false
            && ($fields['callback_target']['type'] ?? '') === 'string'
            && ($fields['callback_target']['required'] ?? true) === false,
            'audio async callback fields missing: ' . $mode
        );
        foreach ($service['examples'] as $exampleType => $example) {
            hub_test_assert(
                !str_contains((string)$example, 'callback'),
                'audio async default ' . $exampleType . ' example includes callbacks: ' . $mode
            );
        }
    }
});

hub_test('Public API hides stale audio async Pack versions', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $db = hub_test_reset_db();
    $row = hub_test_make_documentable_pack($db, 'audio-cleanup', ['mode' => 'audio_cleanup']);
    $db->prepare('UPDATE services SET pack_version = :pack_version WHERE id = :id')->execute([
        ':pack_version' => 'stale-version',
        ':id' => (int)$row['id'],
    ]);

    $modes = array_column(hub_public_api_services($db, static fn (array $service): bool => true), 'mode');

    hub_test_assert(!in_array('audio_cleanup', $modes, true), 'stale audio async Pack version remained documented');
});

hub_test('Public API Gemma derived contracts require gemma4-main', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $healthy = static fn (array $service): bool => true;
    $derivedModes = ['photo_upload', 'photo', 'audio_upload', 'audio'];

    $customDb = hub_test_reset_db();
    hub_test_make_documentable_pack($customDb, 'llm-gemma4-12b', ['service_key' => 'gemma-custom']);
    $customModes = array_column(hub_public_api_services($customDb, $healthy), 'mode');
    hub_test_assert(array_intersect($derivedModes, $customModes) === [], 'custom Gemma service advertised canonical derived routes');

    $canonicalDb = hub_test_reset_db();
    hub_test_make_documentable_pack($canonicalDb, 'llm-gemma4-12b');
    $canonicalModes = array_column(hub_public_api_services($canonicalDb, $healthy), 'mode');
    hub_test_assert(array_diff($derivedModes, $canonicalModes) === [], 'gemma4-main did not advertise every derived route');
});

hub_test('Public API YOLO derived contracts require yolo-cpu', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $healthy = static fn (array $service): bool => true;
    $derivedModes = ['yolo_model_register', 'yolo_model_status', 'yolo_model_assign_gpu', 'yolo_model_unassign_gpu'];

    $customDb = hub_test_reset_db();
    hub_test_make_documentable_pack($customDb, 'yolo-serving', ['service_key' => 'yolo-custom']);
    $customModes = array_column(hub_public_api_services($customDb, $healthy), 'mode');
    hub_test_assert(array_intersect($derivedModes, $customModes) === [], 'custom YOLO service advertised canonical derived routes');

    $canonicalDb = hub_test_reset_db();
    hub_test_make_documentable_pack($canonicalDb, 'yolo-serving');
    $canonicalModes = array_column(hub_public_api_services($canonicalDb, $healthy), 'mode');
    hub_test_assert(array_diff($derivedModes, $canonicalModes) === [], 'yolo-cpu did not advertise every derived route');
});

hub_test('Public API DB contract wins a derived mode collision', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $healthy = static fn (array $service): bool => true;

    $db = hub_test_reset_db();
    hub_test_make_documentable_pack($db, 'llm-gemma4-12b');
    hub_test_make_documentable_pack($db, 'hello', ['mode' => 'photo_upload']);
    $servicesByMode = array_column(hub_public_api_services($db, $healthy), null, 'mode');

    hub_test_assert(($servicesByMode['photo_upload']['pack_id'] ?? '') === 'hello', 'derived contract overwrote a real DB service mode');
});

hub_test('Public API unhealthy DB mode reserves its gateway collision', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $db = hub_test_reset_db();
    hub_test_make_documentable_pack($db, 'llm-gemma4-12b');
    hub_test_make_documentable_pack($db, 'hello', [
        'mode' => 'photo_upload',
        'enabled' => 0,
        'runtime_status' => 'stopped',
        'status' => 'stopped',
        'health_url' => 'http://127.0.0.1:1/health',
    ]);
    $modes = array_column(
        hub_public_api_services($db, static fn (array $service): bool => (string)$service['pack_id'] === 'llm-gemma4-12b'),
        'mode'
    );

    hub_test_assert(!in_array('photo_upload', $modes, true), 'derived contract ignored an unhealthy DB mode collision');
    hub_test_assert(in_array('photo', $modes, true), 'healthy canonical Gemma parent lost unrelated derived modes');
});

hub_test('Public API docs gate DocParser and YOLO sections independently', function (): void {
    require_once HUB_ROOT . '/app/public_api_docs.php';
    $healthy = static fn (array $service): bool => true;
    $render = static function (array $packIds) use ($healthy): string {
        $db = hub_test_reset_db();
        foreach ($packIds as $packId) {
            hub_test_make_documentable_pack($db, $packId);
        }

        return hub_public_api_docs_html($db, null, $healthy);
    };
    $docParserHint = 'quality_report.missing_translation_blocks';
    $localJobsTab = 'href="#local-jobs"';
    $localJobsSection = '<section id="local-jobs"';

    $ordinaryHtml = $render(['hello']);
    hub_test_assert(!str_contains($ordinaryHtml, $docParserHint), 'ordinary live service must not show the DocParser repair hint');
    hub_test_assert(!str_contains($ordinaryHtml, $localJobsTab) && !str_contains($ordinaryHtml, $localJobsSection), 'ordinary live service must not show YOLO Local Jobs');

    $docParserHtml = $render(['hello', 'docparser']);
    hub_test_assert(str_contains($docParserHtml, $docParserHint), 'live DocParser must show the repair hint');
    hub_test_assert(!str_contains($docParserHtml, $localJobsTab) && !str_contains($docParserHtml, $localJobsSection), 'live DocParser without YOLO must not show Local Jobs');

    $yoloHtml = $render(['hello', 'yolo']);
    hub_test_assert(!str_contains($yoloHtml, $docParserHint), 'live YOLO without DocParser must not show the repair hint');
    hub_test_assert(str_contains($yoloHtml, $localJobsTab) && str_contains($yoloHtml, $localJobsSection), 'live YOLO must show Local Jobs');
});

hub_test('Admin API docs render canonical mixed live contracts in one health batch', function (): void {
    if (hub_platform_id() !== 'linux' || !function_exists('curl_multi_init') || !function_exists('proc_open')) {
        hub_test_skip('authenticated admin render test requires Linux, cURL multi, and proc_open');
    }
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $servers = [];
    $routerDir = sys_get_temp_dir() . '/3waaihub_admin_api_docs_' . bin2hex(random_bytes(8));
    $countFile = $routerDir . '/count.log';
    $countEnvironment = 'AIHUB_ADMIN_API_DOCS_TEST_COUNT_FILE';
    $originalEnvironment = getenv($countEnvironment);
    $originalSession = $_SESSION ?? [];
    $originalServer = $_SERVER;
    $originalGet = $_GET;
    $bufferLevel = ob_get_level();

    try {
        if (!mkdir($routerDir, 0775, true) && !is_dir($routerDir)) {
            throw new RuntimeException('cannot create admin API docs test server directory');
        }
        file_put_contents($countFile, '');
        $router = $routerDir . '/router.php';
        file_put_contents($router, <<<'PHP'
<?php
$countFile = (string)getenv('AIHUB_ADMIN_API_DOCS_TEST_COUNT_FILE');
if ($countFile !== '') {
    file_put_contents($countFile, "1\n", FILE_APPEND | LOCK_EX);
}
header('Content-Type: application/json');
echo '{"ok":true}';
PHP);
        putenv($countEnvironment . '=' . $countFile);
        $servers[] = hub_test_public_api_start_server($router);
        $healthBase = 'http://127.0.0.1:' . $servers[0]['port'];

        $db = hub_test_reset_db();
        $serviceRows = [];
        foreach (['hello', 'translate-gemma12b', 'image-birefnet'] as $packId) {
            $serviceRows[] = hub_test_make_documentable_pack($db, $packId, ['health_url' => $healthBase . '/' . $packId]);
        }
        $serviceRows[] = hub_test_make_documentable_pack($db, 'docparser');
        $httpRows = array_filter($serviceRows, static fn (array $service): bool => !hub_service_is_internal_task($service));
        hub_test_assert(count($httpRows) === 3, 'admin API docs fixture must contain three HTTP service rows');

        $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_HOST'] = 'api-docs.test:9443';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/hub/admin/api_docs.php';
        $_GET = [];

        ob_start();
        require HUB_ROOT . '/admin/api_docs.php';
        $html = (string)ob_get_clean();

        $healthHits = count(file($countFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        hub_test_assert($healthHits === count($httpRows), 'admin API docs must run exactly one health batch for all HTTP service rows');
        $baseUrl = 'https://api-docs.test:9443/hub/api.php';
        foreach (['hello', 'translate', 'background_remove', 'docparser'] as $mode) {
            hub_test_assert(str_contains($html, 'api.php?mode=' . $mode), 'admin API docs missing canonical mode endpoint: ' . $mode);
            hub_test_assert(str_contains($html, $baseUrl . '?mode=' . $mode), 'admin API docs curl example must use the current host: ' . $mode);
        }
        foreach (['hello', 'translate-gemma12b', 'image-birefnet', 'docparser'] as $packId) {
            hub_test_assert(str_contains($html, $packId), 'admin API docs missing Pack: ' . $packId);
        }
        foreach (['Mode', 'Pack', 'endpoint', 'HTTP 方法', 'Request Content-Type', 'Response Content-Type', 'runtime_level', 'execution_type', '輸入欄位', '輸出 Keys', 'Response Headers', 'Task API', '錯誤碼'] as $field) {
            hub_test_assert(str_contains($html, $field), 'admin API docs missing rendered field: ' . $field);
        }
        foreach (['GET', 'POST', 'application/json', 'multipart/form-data', 'image/png', 'L5-benchmark-ready', 'sync_api', 'async_task'] as $value) {
            hub_test_assert(str_contains($html, $value), 'admin API docs missing contract value: ' . $value);
        }
        hub_test_assert(substr_count($html, '<th>task_type</th>') === 1 && str_contains($html, 'docparser_parse'), 'admin API docs must render only the DocParser task_type');
        hub_test_assert(str_contains($html, '&quot;name&quot;: &quot;image&quot;') && str_contains($html, '&quot;ok&quot;'), 'admin API docs missing canonical input/output contracts');
        hub_test_assert(str_contains($html, 'X-3waAIHub-Model') && str_contains($html, 'X-3waAIHub-Elapsed-Ms'), 'admin API docs missing binary response headers');
        hub_test_assert(str_contains($html, 'task_status') && str_contains($html, 'task_result'), 'admin API docs missing task API references');
        hub_test_assert(str_contains($html, 'unknown_mode') && str_contains($html, 'file_required'), 'admin API docs missing canonical errors');
        hub_test_assert(substr_count($html, '<h4>curl 範例</h4>') === 4, 'admin API docs must render one canonical curl example per service');
        $translateCurlStart = strpos($html, $baseUrl . '?mode=translate');
        $translateCurlEnd = $translateCurlStart === false ? false : strpos($html, '</pre>', $translateCurlStart);
        hub_test_assert($translateCurlStart !== false && $translateCurlEnd !== false, 'admin API docs missing the Translate curl example');
        $translateCurl = substr($html, $translateCurlStart, $translateCurlEnd - $translateCurlStart);
        hub_test_assert(
            str_contains($translateCurl, '-H &quot;Content-Type: application/json&quot;')
            && str_contains($translateCurl, '-d &#039;{')
            && str_contains($translateCurl, '&quot;text&quot;: &quot;That was a wonderful time.&quot;'),
            'admin API docs Translate curl must include the JSON header, body flag, and representative payload'
        );
        hub_test_assert(str_contains($html, 'Authorization: Bearer &lt;TOKEN&gt;') && str_contains($html, 'image=@sample.png') && str_contains($html, '--output result.png') && str_contains($html, 'file=@manual.pdf'), 'admin API docs missing canonical multipart, binary, or async curl details');
        hub_test_assert(str_contains($html, '</html>') && !str_contains($html, 'Fatal error'), 'admin API docs render must complete without fatal output');
    } finally {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        $_SESSION = $originalSession;
        $_SERVER = $originalServer;
        $_GET = $originalGet;
        if ($originalEnvironment === false) {
            putenv($countEnvironment);
        } else {
            putenv($countEnvironment . '=' . $originalEnvironment);
        }
        hub_test_public_api_stop_servers($servers);
        foreach ([$routerDir . '/router.php', $countFile] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($routerDir)) {
            rmdir($routerDir);
        }
    }
});

hub_test('PhaseDX-3 public API docs policy settings and manifest are safe', function (): void {
    $helperPath = HUB_ROOT . '/app/public_api_docs.php';
    hub_test_assert(is_file($helperPath), 'app/public_api_docs.php missing');
    require_once $helperPath;

    $db = hub_test_reset_db();
    foreach ([
        'hello',
        'ocr-ppocrv5',
        'yolo',
        'yolo-serving',
        'translate-gemma12b',
        'sam3',
        'llm-gemma4-12b',
        'image-birefnet',
        'docparser',
    ] as $packId) {
        hub_test_make_documentable_pack($db, $packId);
    }
    $healthy = static fn (array $service): bool => true;

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1', 'public docs default must be enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST') === '1', 'public manifest default must be enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '0', 'public API docs default must be open access');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST') === true, 'local manifest should be allowed by default');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'public docs should be allowed by default');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'public docs should allow external IP by default');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST') === true, 'manifest should allow external IP by default');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === false, 'local-only docs must block external IP when enabled');

    $manifest = hub_public_api_manifest($db, $healthy);
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    hub_test_assert(is_array($manifest['services'] ?? null), 'manifest services missing');
    $extensions = $manifest['input_field_extensions'] ?? [];
    hub_test_assert(($extensions['one_of']['type'] ?? '') === 'array<string>', 'manifest must describe one_of field groups');
    hub_test_assert(($extensions['one_of_required']['type'] ?? '') === 'boolean', 'manifest must describe one_of_required');
    hub_test_assert(($extensions['example_include']['type'] ?? '') === 'boolean', 'manifest must describe example_include');
    foreach (['hello', 'ocr', 'yolo', 'translate', 'sam3', 'yolo_model_register', 'yolo_model_status'] as $mode) {
        hub_test_assert(in_array($mode, array_column($manifest['services'], 'mode'), true), 'manifest missing mode ' . $mode);
    }
    $yoloRegister = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'yolo_model_register') {
            $yoloRegister = $service;
            break;
        }
    }
    hub_test_assert(is_array($yoloRegister), 'manifest missing yolo_model_register mode');
    hub_test_assert(str_contains((string)$yoloRegister['examples']['curl'], '<ALLOWLISTED_HOST_PATH>/best.pt'), 'yolo register example must use allowlist placeholder');
    hub_test_assert(!str_contains((string)$yoloRegister['examples']['curl'], '/DATA/'), 'yolo register example must not leak host root');
    $photoUpload = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'photo_upload') {
            $photoUpload = $service;
            break;
        }
    }
    hub_test_assert(is_array($photoUpload), 'manifest missing photo_upload mode');
    $curlExecutable = hub_platform_id() === 'windows' ? 'curl.exe' : 'curl';
    $continuation = hub_platform_id() === 'windows' ? "`" : "\\";
    hub_test_assert(str_starts_with((string)$photoUpload['examples']['curl'], $curlExecutable . ' -X POST'), 'public multipart curl example must use the platform executable');
    hub_test_assert(str_contains((string)$photoUpload['examples']['curl'], ' ' . $continuation . "\n"), 'public multipart curl example must use the platform continuation');
    hub_test_assert(str_contains((string)$photoUpload['examples']['php'], "new CURLFile('/path/to/example.jpg'"), 'photo_upload PHP example must include usable CURLFile');
    hub_test_assert(str_contains((string)$photoUpload['examples']['js_fetch'], 'const formData = new FormData()'), 'photo_upload JS example must define formData');
    hub_test_assert(str_contains((string)$photoUpload['examples']['js_fetch'], "formData.append('image', fileInput.files[0])"), 'photo_upload JS example must define formData image upload');
    hub_test_assert(!str_contains((string)$photoUpload['examples']['php'], 'CURLFile here'), 'photo_upload PHP example must not use placeholder CURLFile text');
    hub_test_assert(!str_contains((string)$photoUpload['examples']['js_fetch'], 'undefined formData'), 'photo_upload JS example must not reference undefined formData');
    $photo = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'photo') {
            $photo = $service;
            break;
        }
    }
    hub_test_assert(is_array($photo), 'manifest missing photo mode');
    hub_test_assert(str_starts_with((string)$photo['examples']['curl'], $curlExecutable . ' -X POST'), 'public JSON curl example must use the platform executable');
    hub_test_assert(str_contains((string)$photo['examples']['curl'], ' ' . $continuation . "\n"), 'public JSON curl example must use the platform continuation');
    hub_test_assert(
        preg_match("/-d '(.+)'$/s", (string)$photo['examples']['curl'], $jsonBody) === 1
            && is_array(json_decode($jsonBody[1], true)),
        'public JSON curl body must remain valid JSON'
    );
    $sam3 = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'sam3') {
            $sam3 = $service;
            break;
        }
    }
    hub_test_assert(is_array($sam3), 'manifest missing sam3 mode');
    $sam3Curl = (string)$sam3['examples']['curl'];
    hub_test_assert(
        str_contains($sam3Curl, "-F 'points_json={\"points\":[[320,240]],\"labels\":[1]}'"),
        'SAM3 curl must preserve exact points_json JSON inside a single-quoted argument'
    );
    hub_test_assert(str_contains((string)$sam3['examples']['curl'], "output_format=png"), 'SAM3 public docs must show png output format');
    hub_test_assert(str_contains((string)$sam3['examples']['curl'], "guidance_mask=@sample.png"), 'SAM3 public docs must show guidance_mask upload');
    if (hub_platform_id() === 'windows') {
        hub_test_assert(str_starts_with($sam3Curl, 'curl.exe -X POST'), 'Windows SAM3 curl must use curl.exe');
        hub_test_assert(str_contains($sam3Curl, " `\n"), 'Windows SAM3 curl must use backtick continuations');
    }
    $backgroundRemove = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'background_remove') {
            $backgroundRemove = $service;
            break;
        }
    }
    hub_test_assert(is_array($backgroundRemove), 'manifest missing background_remove mode');
    hub_test_assert(($backgroundRemove['response_content_type'] ?? '') === 'image/png', 'BiRefNet docs response MIME mismatch');
    hub_test_assert(($backgroundRemove['response_headers'] ?? []) === [
        'X-3waAIHub-Model',
        'X-3waAIHub-Device',
        'X-3waAIHub-Elapsed-Ms',
        'X-3waAIHub-Width',
        'X-3waAIHub-Height',
    ], 'BiRefNet docs response headers mismatch');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['curl'], '--output result.png'), 'BiRefNet curl example must save PNG');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['php'], "file_put_contents('result.png'"), 'BiRefNet PHP example must save PNG');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['php'], 'CURLINFO_HTTP_CODE'), 'BiRefNet PHP example must check status');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['php'], 'CURLINFO_CONTENT_TYPE'), 'BiRefNet PHP example must check MIME');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['js_fetch'], 'await res.blob()'), 'BiRefNet JS example must read a blob');
    hub_test_assert(str_contains((string)$backgroundRemove['examples']['js_fetch'], 'URL.createObjectURL'), 'BiRefNet JS example must create an object URL');
    hub_test_assert(!str_contains((string)$backgroundRemove['examples']['js_fetch'], 'res.json()'), 'BiRefNet JS success example must not decode PNG as JSON');
    foreach (['curl', 'php', 'js_fetch'] as $exampleType) {
        hub_test_assert(!str_contains((string)$backgroundRemove['examples'][$exampleType], 'background_image'), 'BiRefNet default ' . $exampleType . ' example must omit optional background image');
    }
    hub_test_assert(substr_count((string)$backgroundRemove['examples']['js_fetch'], 'const fileInput') === 1, 'BiRefNet JS example must declare one required file input');
    $photoFields = [];
    foreach ($photo['input_fields'] ?? [] as $field) {
        if (is_array($field)) {
            $photoFields[(string)($field['name'] ?? '')] = $field;
        }
    }
    hub_test_assert(($photoFields['real_inference']['default'] ?? null) === false, 'photo public docs real_inference default must be false');
    foreach (['mock', 'runtime_level', 'model'] as $key) {
        hub_test_assert(in_array($key, $photo['output_keys'] ?? [], true), 'photo public docs response contract missing ' . $key);
    }
    hub_test_assert(str_contains($json, '<TOKEN>'), 'manifest examples must use token placeholder');
    foreach (['local_port', 'docker-compose.generated.yml', '/DATA/models', 'data/logs', '3waaihub.sqlite', 'admin/', 'command_worker', '3wa_live_'] as $secret) {
        hub_test_assert(!str_contains($json, $secret), 'manifest must not leak ' . $secret);
    }

    $docsHtml = hub_public_api_docs_html($db, null, $healthy);
    hub_test_assert(str_contains($docsHtml, '3waAIHub API 介接文件'), 'public docs title missing');
    foreach (['API modes', 'Local Jobs', 'bin/aihub-run', 'yolo_train', 'request.json', 'progress.ndjson', 'result.json', 'Local Job Contract v0.1'] as $needle) {
        hub_test_assert(str_contains($docsHtml, $needle), 'public docs local job section missing ' . $needle);
    }
    hub_test_assert(str_contains($docsHtml, 'Authorization: Bearer &lt;TOKEN&gt;'), 'public docs token placeholder missing');
    hub_test_assert(str_contains($docsHtml, 'mode'), 'public docs must keep technical values');
    hub_test_assert(str_contains($docsHtml, 'docparser_parse'), 'public docs must document DocParser task type');
    hub_test_assert(str_contains($docsHtml, 'docparser_repair_translation'), 'public docs must document DocParser repair task type');
    hub_test_assert(str_contains($docsHtml, 'quality_report.missing_translation_blocks'), 'public docs must show the DocParser repair hint when DocParser is live');
    hub_test_assert(str_contains($docsHtml, '<section id="local-jobs"'), 'public docs must show the YOLO Local Jobs section when YOLO is live');
    hub_test_assert(str_contains($docsHtml, 'multipart/form-data'), 'public docs must document DocParser multipart upload');
    hub_test_assert(str_contains($docsHtml, 'file=@manual.pdf'), 'public docs must show DocParser PDF file upload');
    hub_test_assert(str_contains($docsHtml, 'mode=task_status&amp;task_id='), 'public docs must show task_status URL');
    hub_test_assert(str_contains($docsHtml, 'mode=task_result&amp;task_id='), 'public docs must show task_result URL');
    hub_test_assert(!str_contains($docsHtml, 'admin/'), 'public docs must not include admin links when not logged in');
    hub_test_assert(!str_contains($docsHtml, 'CURLFile here'), 'public docs multipart PHP example must not use placeholder CURLFile text');
    foreach (['local_port', 'docker-compose.generated.yml', '/DATA/models', 'data/logs', '3waaihub.sqlite', 'command_worker', '3wa_live_', '/DATA/jobs', 'Docker socket'] as $secret) {
        hub_test_assert(!str_contains($docsHtml, $secret), 'public docs must not leak ' . $secret);
    }
});

hub_test('Agent manifest smoke validates live-contract metadata without Pack inference', function (): void {
    $scriptPath = HUB_ROOT . '/scripts/agent_manifest_smoke.php';
    hub_test_assert(is_file($scriptPath), 'scripts/agent_manifest_smoke.php missing');
    require_once $scriptPath;
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $db = hub_test_reset_db();
    foreach ([
        'hello',
        'ocr-ppocrv5',
        'yolo',
        'yolo-serving',
        'translate-gemma12b',
        'sam3',
        'llm-gemma4-12b',
        'image-birefnet',
        'docparser',
    ] as $packId) {
        hub_test_make_documentable_pack($db, $packId);
    }
    hub_test_make_documentable_pack($db, 'whisper-asr', ['mode' => 'speech_transcribe']);
    hub_test_make_documentable_pack($db, 'tts-voxcpm2', [
        'mode' => 'tts',
        'runtime_status' => 'stopped',
        'status' => 'stopped',
    ]);
    $manifest = hub_public_api_manifest($db, static fn (array $service): bool => true);

    $errors = hub_agent_manifest_smoke_validate($manifest);
    hub_test_assert($errors === [], 'generated public manifest must pass agent smoke validation: ' . implode('; ', $errors));

    $invalid = $manifest;
    $invalid['services'][0]['endpoint'] = 'api.php?mode=wrong';
    hub_test_assert(hub_agent_manifest_smoke_validate($invalid) !== [], 'endpoint/mode drift must fail agent smoke validation');

    $sourceIndex = array_search('speech_transcribe', array_column($invalid['services'], 'mode'), true);
    hub_test_assert(is_int($sourceIndex), 'speech_transcribe fixture missing from agent smoke manifest');
    foreach ($invalid['services'][$sourceIndex]['input_fields'] as &$field) {
        if (is_array($field) && ($field['name'] ?? '') === 'file') {
            unset($field['example_include']);
        }
    }
    unset($field);
    $errors = hub_agent_manifest_smoke_validate($invalid);
    hub_test_assert(
        (bool)array_filter($errors, static fn (string $error): bool => str_contains($error, 'example_include')),
        'required one_of example must retain its example_include marker'
    );
});

hub_test('Admin API docs architecture keeps one shared canonical inventory', function (): void {
    $adminDocs = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(preg_match('/^\s*\$user\s*=\s*hub_require_system_admin\(\$db\);$/m', $adminDocs) === 1, 'admin API docs must require a system admin');
    hub_test_assert(str_contains($adminDocs, "require_once __DIR__ . '/../app/public_api_docs.php';"), 'admin API docs must load the shared public docs helpers');
    hub_test_assert(substr_count($adminDocs, 'hub_public_api_services($db)') === 1, 'admin API docs must load canonical live contracts exactly once');
    foreach ([
        'hub_list_services($db)',
        'hub_pack_api_contracts()',
        'function hub_api_docs_public_base_url',
        'function hub_api_docs_mode_url',
        'function hub_api_docs_multipart_curl_fields',
        '<h2>GET hello</h2>',
        '<h2>POST OCR</h2>',
        '<h2>POST Translate</h2>',
        '<h2>POST SAM3</h2>',
    ] as $removedAdminSource) {
        hub_test_assert(!str_contains($adminDocs, $removedAdminSource), 'admin API docs still contain duplicate source: ' . $removedAdminSource);
    }
});

hub_test('PhaseDX-3 public API docs files and settings UI contract are present', function (): void {
    hub_test_assert(is_file(HUB_ROOT . '/public_api_docs.php'), 'public_api_docs.php missing');
    hub_test_assert(is_file(HUB_ROOT . '/api_manifest.json.php'), 'api_manifest.json.php missing');

    $settingsPage = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach (['AIHUB_PUBLIC_API_DOCS', 'AIHUB_PUBLIC_API_MANIFEST', 'AIHUB_PUBLIC_API_LOCAL_ONLY', '未登入 API 文件', '未登入 Agent Manifest', '僅允許本機讀取'] as $needle) {
        hub_test_assert(str_contains($settingsPage, $needle), 'settings API tab missing ' . $needle);
    }
});

hub_test('Client quickstart documents mock defaults and response contract keys', function (): void {
    $quickstart = (string)file_get_contents(HUB_ROOT . '/docs/client_quickstart.md');

    hub_test_assert(str_contains($quickstart, '預設 `real_inference=false`'), 'client quickstart must document real_inference=false default');
    foreach (['`mock`', '`runtime_level`', '`model`'] as $key) {
        hub_test_assert(str_contains($quickstart, $key), 'client quickstart response contract missing ' . $key);
    }
});

hub_test('VoxCPM2 readmes document the safe native and Cluster profile lifecycle', function (): void {
    $paths = [HUB_ROOT . '/README.md', HUB_ROOT . '/packs/tts-voxcpm2/README.md'];
    foreach ($paths as $path) {
        hub_test_assert(is_file($path), 'VoxCPM2 documentation missing: ' . $path);
        $document = (string)file_get_contents($path);
        foreach ([
            'profile_prepare',
            'profile_status',
            'profile_confirm',
            'profile_delete',
            'synthesize',
            'design',
            'clone',
            'ultimate_clone',
            'voice_profile_task_id',
            'cluster_task_status',
            'cluster_artifact',
            'pinned station',
            'no failover',
            'MyAI',
            'reference_audio_sha256',
            'unconfirmed',
            'confirmed transcript remains hidden',
            'task/log/callback/synthesis',
            'Native Hub task IDs remain part of the native async contract.',
            'Cluster child task/profile IDs and paths',
            'currently valid Token',
            'voice_generate permission',
            'submitting Token',
            'artifact_url_template',
            'ack_url_template',
            'not public contract',
        ] as $needle) {
            hub_test_assert(str_contains($document, $needle), basename($path) . ' missing safe voice workflow detail: ' . $needle);
        }
        foreach (['Bearer 3wa_live_', 'voice_profile_id=', '/data/voice_profiles/'] as $forbidden) {
            hub_test_assert(!str_contains($document, $forbidden), basename($path) . ' contains obsolete or private voice guidance: ' . $forbidden);
        }
    }
    $root = (string)file_get_contents(HUB_ROOT . '/README.md');
    $pack = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/README.md');
    $design = (string)file_get_contents(HUB_ROOT . '/docs/superpowers/specs/2026-07-30-cluster-ultimate-clone-api-design.md');
    hub_test_assert(!str_contains($root, "第一版不做：\n\n- Ultimate Clone"), 'root README still says Ultimate Clone is unavailable');
    hub_test_assert(!str_contains($root, 'Public API 只能送 `voice_profile_id` 或 `reference_audio_id`'), 'root README still recommends obsolete public profile identifiers');
    hub_test_assert(
        str_contains($design, 'currently valid Token')
        && str_contains($design, '`voice_generate` permission')
        && str_contains($design, 'submitting Token')
        && !str_contains($design, 'requires the exact customer member and Token ownership')
        && !str_contains($design, 'exact customer-token ownership'),
        'Cluster design must separate member-owned successful Profiles from exact-Token followups'
    );
    hub_test_assert(
        !str_contains($root, 'child/local task/profile ID')
        && !str_contains($pack, 'child/local task or profile IDs'),
        'README privacy wording must not prohibit native Hub task IDs'
    );
    hub_test_assert(
        str_contains(
            $pack,
            '`profile_prepare` -> `cluster_task_status` -> `profile_status` ->' . PHP_EOL
            . '`profile_confirm` -> `ultimate_clone` -> `cluster_task_result` ->' . PHP_EOL
            . '`cluster_artifact` -> `profile_delete`'
        ),
        'Pack README Cluster flow must include cluster_task_result between synthesis and artifact retrieval'
    );
});

hub_test('PhaseDX-3.1 old public docs defaults migrate once only', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '0');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST', '1');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    $db->exec("DELETE FROM settings WHERE key = 'AIHUB_PUBLIC_API_OPEN_ACCESS_MIGRATED'");

    hub_ensure_default_storage_settings($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1', 'old public docs default must migrate to enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '0', 'old local-only default must migrate to disabled');

    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '0');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    hub_ensure_default_storage_settings($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '0', 'migration marker must preserve later admin docs setting');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1', 'migration marker must preserve later admin local-only setting');
});
