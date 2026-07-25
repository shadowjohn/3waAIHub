<?php
declare(strict_types=1);

function hub_test_make_documentable_pack(PDO $db, string $packId, array $state = []): array
{
    $pack = hub_get_pack($packId);
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'test Pack unavailable: ' . $packId);
    $manifest = $pack['manifest'];
    $installed = hub_install_pack($db, $packId, [
        'service_key' => (string)($manifest['install']['default_service_key'] ?? ($packId . '-main')),
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
