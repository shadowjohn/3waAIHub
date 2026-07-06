<?php
declare(strict_types=1);

$options = getopt('', ['db:', 'keep-db', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/token_api_smoke.php [--db=/tmp/3waaihub_smoke.sqlite] [--keep-db]\n";
    exit(0);
}

$dbPath = trim((string)($options['db'] ?? ''));
$createdDb = false;
if ($dbPath === '') {
    $dbPath = tempnam(sys_get_temp_dir(), '3waaihub_token_smoke_');
    if ($dbPath === false) {
        fwrite(STDERR, "Cannot allocate temp DB.\n");
        exit(1);
    }
    @unlink($dbPath);
    $createdDb = true;
}
putenv('AIHUB_TEST_DB=' . $dbPath);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$servers = [];
$tempFiles = [];
$tempDirs = [];

try {
    hub_token_smoke_require_curl();

    $mockPort = hub_token_smoke_free_port();
    $appPort = hub_token_smoke_free_port();
    $mockDir = hub_token_smoke_make_mock_server();
    $tempDirs[] = $mockDir;

    $db = hub_db();
    hub_migrate($db);
    hub_seed_admin_user($db);
    hub_ensure_default_storage_settings($db);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', hub_token_smoke_models_dir());
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
    hub_set_storage_setting($db, 'AIHUB_DOCKER_PORT_START', '1024');
    hub_set_storage_setting($db, 'AIHUB_DOCKER_PORT_END', '65535');

    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-main',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => $mockPort,
        'idempotent' => true,
    ]);
    $service = $installed['service'];
    hub_set_service_enabled($db, 'ocr', true);
    hub_update_service_status($db, (int)$service['id'], 'running');
    $service = hub_get_service_by_mode($db, 'ocr');
    if (!$service) {
        throw new RuntimeException('ocr service not found after install');
    }

    $memberId = hub_create_api_member($db, 'PhaseS-4.1 Smoke ' . date('YmdHis'), 'Smoke', 'smoke@example.test', 'token API smoke');
    echo 'member created: #' . $memberId . PHP_EOL;

    $token = hub_create_api_token($db, $memberId, 'ocr smoke token', null, null);
    $tokenId = (int)$token['token_id'];
    $plainToken = (string)$token['plain_token'];
    echo 'token created: #' . $tokenId . ' ' . hub_api_token_prefix($plainToken) . '...' . PHP_EOL;

    hub_add_api_token_mode_permission($db, $tokenId, 'ocr', (int)$service['id']);
    echo 'mode permission set: ocr' . PHP_EOL;

    hub_add_api_token_ip_rule($db, $tokenId, '127.0.0.1', 'local smoke curl');
    echo 'token IP whitelist set: 127.0.0.1' . PHP_EOL;

    $servers[] = hub_token_smoke_start_server(
        escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $mockPort . ' ' . escapeshellarg($mockDir . '/router.php'),
        'ocr mock'
    );
    hub_token_smoke_wait_http('http://127.0.0.1:' . $mockPort . '/health', 'ocr mock');

    $servers[] = hub_token_smoke_start_server(
        'AIHUB_TEST_DB=' . escapeshellarg($dbPath) . ' ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $appPort . ' -t ' . escapeshellarg(HUB_ROOT),
        'api server'
    );
    hub_token_smoke_wait_http('http://127.0.0.1:' . $appPort . '/api.php?mode=missing', 'api server');

    $sample = tempnam(sys_get_temp_dir(), '3waaihub_ocr_sample_');
    if ($sample === false) {
        throw new RuntimeException('cannot allocate sample upload');
    }
    $tempFiles[] = $sample;
    file_put_contents($sample, "smoke image bytes\n");

    $httpCode = 0;
    $body = hub_token_smoke_curl_ocr($appPort, $plainToken, $sample, $httpCode);
    echo 'curl status: ' . $httpCode . PHP_EOL;
    if ($httpCode !== 200) {
        throw new RuntimeException('curl returned HTTP ' . $httpCode . ': ' . $body);
    }
    $payload = json_decode($body, true);
    if (!is_array($payload) || empty($payload['ok'])) {
        throw new RuntimeException('OCR response is not ok: ' . $body);
    }

    $logs = hub_list_api_access_logs($db, ['member_id' => $memberId, 'token_id' => $tokenId, 'mode' => 'ocr', 'ok' => '1'], 10, 0);
    if ($logs === [] || (string)$logs[0]['member_name'] === '' || (string)$logs[0]['token_prefix'] === '') {
        throw new RuntimeException('Log Explorer member/token query did not match');
    }
    echo 'Log Explorer query verified: #' . (int)$logs[0]['id'] . PHP_EOL;

    $usage = hub_list_api_usage_daily($db, ['member_id' => $memberId, 'token_id' => $tokenId, 'mode' => 'ocr']);
    if ($usage === [] || (int)$usage[0]['request_count'] < 1 || (int)$usage[0]['success_count'] < 1) {
        throw new RuntimeException('Usage aggregate did not update');
    }
    echo 'Usage aggregate verified: requests=' . (int)$usage[0]['request_count'] . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'token_api_smoke failed: ' . $e->getMessage() . PHP_EOL);
    hub_token_smoke_cleanup($servers, $tempFiles, $tempDirs, $createdDb && !isset($options['keep-db']) ? $dbPath : null);
    exit(1);
}

hub_token_smoke_cleanup($servers, $tempFiles, $tempDirs, $createdDb && !isset($options['keep-db']) ? $dbPath : null);
exit(0);

function hub_token_smoke_require_curl(): void
{
    exec('command -v curl 2>/dev/null', $output, $code);
    if ($code !== 0 || $output === []) {
        throw new RuntimeException('curl command is required');
    }
}

function hub_token_smoke_models_dir(): string
{
    $dir = getenv('AIHUB_TEST_MODELS_DIR') ?: sys_get_temp_dir() . '/3waaihub_token_smoke_models_' . getmypid();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create token smoke models directory: ' . $dir);
    }

    return $dir;
}

function hub_token_smoke_free_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('cannot allocate local port: ' . $errstr);
    }
    $name = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $pos = strrpos($name, ':');
    if ($pos === false) {
        throw new RuntimeException('cannot parse allocated port');
    }

    return (int)substr($name, $pos + 1);
}

function hub_token_smoke_make_mock_server(): string
{
    $dir = sys_get_temp_dir() . '/3waaihub_ocr_mock_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create mock server dir');
    }
    file_put_contents($dir . '/router.php', <<<'PHP'
<?php
$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('Content-Type: application/json; charset=utf-8');
if ($path === '/health') {
    echo json_encode(['ok' => true, 'ready' => true], JSON_UNESCAPED_SLASHES);
    return;
}
if ($path === '/ocr/image' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    echo json_encode(['ok' => true, 'text' => '3waAIHub OCR mock', 'blocks' => []], JSON_UNESCAPED_SLASHES);
    return;
}
http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'not_found'], JSON_UNESCAPED_SLASHES);
PHP);

    return $dir;
}

function hub_token_smoke_start_server(string $command, string $name): array
{
    $stdout = tempnam(sys_get_temp_dir(), '3waaihub_' . str_replace(' ', '_', $name) . '_out_');
    $stderr = tempnam(sys_get_temp_dir(), '3waaihub_' . str_replace(' ', '_', $name) . '_err_');
    if ($stdout === false || $stderr === false) {
        throw new RuntimeException('cannot allocate server logs');
    }
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', $stdout, 'a'],
        2 => ['file', $stderr, 'a'],
    ], $pipes, HUB_ROOT);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start ' . $name);
    }
    fclose($pipes[0]);

    return ['name' => $name, 'process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
}

function hub_token_smoke_wait_http(string $url, string $name): void
{
    $deadline = microtime(true) + 8;
    $context = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    do {
        $body = @file_get_contents($url, false, $context);
        if ($body !== false) {
            return;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException($name . ' did not become ready at ' . $url);
}

function hub_token_smoke_curl_ocr(int $appPort, string $plainToken, string $sample, int &$httpCode): string
{
    $cmd = 'curl -sS -w ' . escapeshellarg("\n%{http_code}")
        . ' -X POST ' . escapeshellarg('http://127.0.0.1:' . $appPort . '/api.php?mode=ocr')
        . ' -H ' . escapeshellarg('Authorization: Bearer ' . $plainToken)
        . ' -F ' . escapeshellarg('image=@' . $sample . ';filename=sample.png;type=image/png');
    exec($cmd . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0 || $output === []) {
        throw new RuntimeException('curl failed: ' . implode("\n", $output));
    }
    $httpCode = (int)array_pop($output);

    return implode("\n", $output);
}

function hub_token_smoke_cleanup(array $servers, array $files, array $dirs, ?string $dbPath): void
{
    foreach (array_reverse($servers) as $server) {
        if (!is_resource($server['process'])) {
            continue;
        }
        $status = proc_get_status($server['process']);
        if (!empty($status['running'])) {
            proc_terminate($server['process']);
            usleep(200000);
            $status = proc_get_status($server['process']);
            if (!empty($status['running'])) {
                proc_terminate($server['process'], 9);
            }
        }
        proc_close($server['process']);
        foreach (['stdout', 'stderr'] as $key) {
            if (is_file((string)$server[$key])) {
                @unlink((string)$server[$key]);
            }
        }
    }
    foreach ($files as $file) {
        if (is_file((string)$file)) {
            @unlink((string)$file);
        }
    }
    foreach ($dirs as $dir) {
        $router = rtrim((string)$dir, '/') . '/router.php';
        if (is_file($router)) {
            @unlink($router);
        }
        if (is_dir((string)$dir)) {
            @rmdir((string)$dir);
        }
    }
    if ($dbPath !== null) {
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
