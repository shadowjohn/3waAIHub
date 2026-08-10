<?php
declare(strict_types=1);

const HUB_FACEBOOK_LOGIN_IMAGE = '3waaihub/facebook-crawler:0.1.0';
const HUB_FACEBOOK_LOGIN_MAX_SECONDS = 600;
const HUB_FACEBOOK_LOGIN_MAX_JSON = 16384;
const HUB_FACEBOOK_LOGIN_MAX_FRAME = 3145728;
const HUB_FACEBOOK_LOGIN_COMMAND_OUTPUT_MAX = 65536;

function hub_facebook_login_command_runner(array $command, int $timeoutSeconds = 30): array
{
    $timeoutSeconds = max(1, min(60, $timeoutSeconds));
    if ($command === [] || count($command) > 64) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Invalid command.', 'output' => 'Invalid command.'];
    }
    foreach ($command as $argument) {
        if (!is_string($argument) || $argument === '' || strlen($argument) > 65535 || str_contains($argument, "\0")) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Invalid command.', 'output' => 'Invalid command.'];
        }
    }

    $windowsOutputPaths = [];
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    if (PHP_OS_FAMILY === 'Windows') {
        $stdoutPath = @tempnam(sys_get_temp_dir(), '3waaihub_fb_stdout_');
        $stderrPath = @tempnam(sys_get_temp_dir(), '3waaihub_fb_stderr_');
        if ($stdoutPath === false || $stderrPath === false) {
            if (is_string($stdoutPath)) {
                @unlink($stdoutPath);
            }
            if (is_string($stderrPath)) {
                @unlink($stderrPath);
            }

            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Cannot allocate process output.', 'output' => 'Cannot allocate process output.'];
        }
        $windowsOutputPaths = ['stdout' => $stdoutPath, 'stderr' => $stderrPath];
        $descriptor = [1 => ['file', $stdoutPath, 'w'], 2 => ['file', $stderrPath, 'w']];
    }
    $process = @proc_open($command, $descriptor, $pipes, HUB_ROOT, null);
    if (!is_resource($process)) {
        foreach ($windowsOutputPaths as $path) {
            @unlink($path);
        }

        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Cannot start process.', 'output' => 'Cannot start process.'];
    }
    if ($windowsOutputPaths === []) {
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
    }

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $observedExitCode = null;
    $timedOut = false;
    $append = static function (string &$target, string $chunk): void {
        $remaining = HUB_FACEBOOK_LOGIN_COMMAND_OUTPUT_MAX - strlen($target);
        if ($remaining > 0) {
            $target .= substr($chunk, 0, $remaining);
        }
    };
    do {
        if ($windowsOutputPaths === []) {
            $append($stdout, (string)stream_get_contents($pipes[1]));
            $append($stderr, (string)stream_get_contents($pipes[2]));
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = hub_observed_process_exit_code($status) ?? $observedExitCode;
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            if (PHP_OS_FAMILY === 'Windows') {
                proc_terminate($process, 9);
            } else {
                proc_terminate($process);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                    usleep(100000);
                }
            }
            break;
        }
        usleep(50000);
    } while (true);

    if ($windowsOutputPaths === []) {
        $append($stdout, (string)stream_get_contents($pipes[1]));
        $append($stderr, (string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
    }
    $exitCode = hub_process_exit_code(proc_close($process), $observedExitCode);
    if ($windowsOutputPaths !== []) {
        foreach ($windowsOutputPaths as $stream => $path) {
            $captured = @file_get_contents($path);
            if (is_string($captured)) {
                if ($stream === 'stdout') {
                    $append($stdout, $captured);
                } else {
                    $append($stderr, $captured);
                }
            }
            @unlink($path);
        }
    }
    if ($timedOut) {
        $exitCode = 124;
        $append($stderr, ($stderr === '' ? '' : "\n") . 'Command timed out.');
    }
    $stdout = trim($stdout);
    $stderr = trim($stderr);

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'output' => trim($stdout . ($stderr === '' ? '' : "\n" . $stderr)),
    ];
}

function hub_facebook_login_container_name(string $profileId): string
{
    if (preg_match('/\Afbp_([a-f0-9]{48})\z/', $profileId, $matches) !== 1) {
        throw new InvalidArgumentException('facebook_profile_not_found');
    }

    return 'aihub-fb-login-' . substr($matches[1], 0, 16) . bin2hex(random_bytes(4));
}

function hub_facebook_login_container_valid(string $name): bool
{
    return preg_match('/\Aaihub-fb-login-[a-f0-9]{24}\z/', $name) === 1;
}

function hub_facebook_login_port(mixed $value): ?int
{
    $value = trim((string)$value);
    if (preg_match('/\A127\.0\.0\.1:([1-9][0-9]{0,4})\z/', $value, $matches) !== 1) {
        return null;
    }
    $port = (int)$matches[1];

    return $port <= 65535 ? $port : null;
}

function hub_facebook_login_owned_row(PDO $db, string $profileId, int $ownerMemberId): ?array
{
    if ($ownerMemberId < 1 || preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT * FROM facebook_crawler_profiles
         WHERE profile_id = :profile_id
           AND owner_member_id = :owner_member_id
           AND deleted_at IS NULL
           AND state <> 'deleting'
         LIMIT 1"
    );
    $stmt->execute([':profile_id' => $profileId, ':owner_member_id' => $ownerMemberId]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function hub_facebook_login_runtime(PDO $db, ?string $platform = null, ?array $runtimeProfile = null): array
{
    $platform = hub_platform_id($platform);
    if ($platform === 'linux') {
        return ['platform' => 'linux', 'wsl' => null];
    }
    if ($platform !== 'windows') {
        throw new RuntimeException('login_broker_unavailable');
    }
    $service = hub_get_service_by_mode($db, 'facebook_crawl');
    $wsl = is_array($service) ? hub_wsl_service_runtime($service, 'windows', $runtimeProfile) : null;
    if ($wsl === null) {
        throw new RuntimeException('login_broker_unavailable');
    }

    return ['platform' => 'windows', 'wsl' => $wsl];
}

function hub_facebook_login_run(array $runtime, array $command, callable $runner, int $timeout = 30): array
{
    if (($runtime['platform'] ?? '') === 'windows') {
        $script = 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $command));
        return $runner(hub_wsl_script_command((array)$runtime['wsl'], $script), $timeout);
    }

    return $runner($command, $timeout);
}

function hub_facebook_login_mount_path(array $runtime, string $profileDir, callable $runner): string
{
    if (($runtime['platform'] ?? '') !== 'windows') {
        return $profileDir;
    }
    $command = hub_wsl_script_command(
        (array)$runtime['wsl'],
        'wslpath -a -- ' . hub_wsl_shell_literal($profileDir)
    );
    $result = $runner($command, 10);
    $path = trim((string)($result['stdout'] ?? ''));
    if ((int)($result['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException('login_broker_unavailable');
    }
    try {
        return hub_container_path($path);
    } catch (InvalidArgumentException) {
        throw new RuntimeException('login_broker_unavailable');
    }
}

function hub_facebook_login_run_command(string $profileDir, string $containerName): array
{
    return [
        'docker', 'run', '-d', '--rm', '--pull=never',
        '--network', 'bridge',
        '--publish', '127.0.0.1::8765',
        '--cap-add', 'NET_ADMIN',
        '--mount', 'type=bind,src=' . $profileDir . ',dst=/profile',
        '--name', $containerName,
        '--entrypoint', '/app/crawl-entrypoint.sh',
        HUB_FACEBOOK_LOGIN_IMAGE, '/app/login_broker.py',
    ];
}

function hub_facebook_login_stop(PDO $db, string $containerName, callable $runner, ?string $platform = null, ?array $runtimeProfile = null): bool
{
    if (!hub_facebook_login_container_valid($containerName)) {
        return false;
    }
    try {
        $runtime = hub_facebook_login_runtime($db, $platform, $runtimeProfile);
        $result = hub_facebook_login_run($runtime, ['docker', 'stop', $containerName], $runner, 15);
        if ((int)($result['exit_code'] ?? 1) === 0) {
            return true;
        }
        $check = hub_facebook_login_run(
            $runtime,
            ['docker', 'ps', '-a', '--filter', 'name=^/' . $containerName . '$', '--format', '{{.Names}}'],
            $runner,
            10
        );

        return (int)($check['exit_code'] ?? 1) === 0 && trim((string)($check['stdout'] ?? '')) === '';
    } catch (Throwable) {
        return false;
    }
}

function hub_facebook_login_http_request(string $method, string $url, ?array $payload, int $maxBytes): array
{
    if (!function_exists('curl_init') || !in_array($method, ['GET', 'POST'], true)) {
        return ['status' => 0, 'content_type' => '', 'body' => ''];
    }
    $body = '';
    $handle = curl_init($url);
    $options = [
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($method === 'POST') {
        $encoded = hub_json_encode($payload ?? []);
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $encoded;
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json', 'Content-Length: ' . strlen($encoded)];
    }
    curl_setopt_array($handle, $options);
    $ok = curl_exec($handle);
    $status = $ok === false ? 0 : (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = $ok === false ? '' : strtolower(trim(explode(';', (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($handle);

    return ['status' => $status, 'content_type' => $contentType, 'body' => $ok === false ? '' : $body];
}

function hub_facebook_login_request(?callable $transport, string $method, int $port, string $path, ?array $payload, int $maxBytes): array
{
    if ($port < 1 || $port > 65535 || !in_array($path, ['/health', '/status', '/frame', '/input', '/credentials', '/close'], true)) {
        return ['status' => 0, 'content_type' => '', 'body' => ''];
    }
    $transport ??= 'hub_facebook_login_http_request';

    try {
        $response = $transport($method, 'http://127.0.0.1:' . $port . $path, $payload, $maxBytes);
    } catch (Throwable) {
        return ['status' => 0, 'content_type' => '', 'body' => ''];
    }

    return is_array($response) ? $response : ['status' => 0, 'content_type' => '', 'body' => ''];
}

function hub_facebook_login_json_response_valid(array $response, int $maxBytes = HUB_FACEBOOK_LOGIN_MAX_JSON): ?array
{
    $body = (string)($response['body'] ?? '');
    if (
        (int)($response['status'] ?? 0) !== 200
        || strlen($body) > $maxBytes
        || strtolower(trim((string)($response['content_type'] ?? 'application/json'))) !== 'application/json'
    ) {
        return null;
    }
    $payload = json_decode($body, true);

    return is_array($payload) ? $payload : null;
}

function hub_facebook_login_health(?callable $transport, int $port): bool
{
    $attempts = $transport === null ? 20 : 1;
    for ($attempt = 0; $attempt < $attempts; $attempt++) {
        $payload = hub_facebook_login_json_response_valid(
            hub_facebook_login_request($transport, 'GET', $port, '/health', null, 1024),
            1024
        );
        if ($payload === ['ok' => true]) {
            return true;
        }
        if ($transport === null) {
            usleep(250000);
        }
    }

    return false;
}

function hub_facebook_login_clear(PDO $db, array $profile, ?string $state = null, bool $verified = false): bool
{
    $id = (int)($profile['id'] ?? 0);
    $hash = (string)($profile['login_secret_hash'] ?? '');
    $container = (string)($profile['login_container_name'] ?? '');
    $port = (int)($profile['login_port'] ?? 0);
    $expiresAt = (string)($profile['login_expires_at'] ?? '');
    if (
        $id < 1
        || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1
        || !hub_facebook_login_container_valid($container)
        || $port < 1
        || $port > 65535
        || $expiresAt === ''
    ) {
        return false;
    }
    $sets = [
        'login_secret_hash = NULL',
        'login_container_name = NULL',
        'login_port = NULL',
        'login_expires_at = NULL',
        'updated_at = :updated_at',
    ];
    $params = [
        ':updated_at' => hub_now(),
        ':id' => $id,
        ':expected_hash' => $hash,
        ':expected_container' => $container,
        ':expected_port' => $port,
        ':expected_expires_at' => $expiresAt,
    ];
    if ($state !== null) {
        $sets[] = 'state = :state';
        $params[':state'] = $state;
    }
    if ($verified) {
        $sets[] = 'last_verified_at = :verified_at';
        $params[':verified_at'] = $params[':updated_at'];
    }
    $stmt = $db->prepare(
        'UPDATE facebook_crawler_profiles SET ' . implode(', ', $sets) . '
         WHERE id = :id
           AND login_secret_hash = :expected_hash
           AND login_container_name = :expected_container
           AND login_port = :expected_port
           AND login_expires_at = :expected_expires_at
           AND state = \'preparing\'
           AND deleted_at IS NULL'
    );
    $stmt->execute($params);

    return $stmt->rowCount() === 1;
}

function hub_facebook_login_open(
    PDO $db,
    array $profile,
    ?array $credentials,
    ?callable $runner = null,
    ?callable $transport = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): array {
    $runner ??= 'hub_facebook_login_command_runner';
    $profileId = (string)$profile['profile_id'];
    $containerName = hub_facebook_login_container_name($profileId);
    $profileDir = hub_facebook_profile_directory($profileId);
    $runtime = hub_facebook_login_runtime($db, $platform, $runtimeProfile);
    $mountPath = hub_facebook_login_mount_path($runtime, $profileDir, $runner);
    $started = false;
    try {
        $result = hub_facebook_login_run($runtime, hub_facebook_login_run_command($mountPath, $containerName), $runner, 30);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('login_broker_unavailable');
        }
        $started = true;
        $portResult = hub_facebook_login_run($runtime, ['docker', 'port', $containerName, '8765/tcp'], $runner, 10);
        $port = (int)($portResult['exit_code'] ?? 1) === 0
            ? hub_facebook_login_port((string)($portResult['stdout'] ?? ''))
            : null;
        if ($port === null || !hub_facebook_login_health($transport, $port)) {
            throw new RuntimeException('login_broker_unavailable');
        }
        if ($credentials !== null) {
            $credentialResponse = hub_facebook_login_json_response_valid(
                hub_facebook_login_request($transport, 'POST', $port, '/credentials', $credentials, HUB_FACEBOOK_LOGIN_MAX_JSON)
            );
            if ($credentialResponse === null || empty($credentialResponse['ok'])) {
                throw new RuntimeException('login_broker_unavailable');
            }
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = hub_now();
        $expiresAt = date('Y-m-d H:i:s', time() + HUB_FACEBOOK_LOGIN_MAX_SECONDS);
        $update = $db->prepare(
            "UPDATE facebook_crawler_profiles
             SET state = 'preparing', login_secret_hash = :hash, login_container_name = :container,
                 login_port = :port, login_expires_at = :expires_at, updated_at = :updated_at
             WHERE id = :id
               AND owner_member_id = :owner_member_id
               AND state IN ('preparing', 'ready')
               AND login_secret_hash IS NULL
               AND login_container_name IS NULL
               AND login_port IS NULL
               AND login_expires_at IS NULL
               AND active_task_id IS NULL
               AND deleted_at IS NULL"
        );
        $update->execute([
            ':hash' => hash('sha256', $secret),
            ':container' => $containerName,
            ':port' => $port,
            ':expires_at' => $expiresAt,
            ':updated_at' => $now,
            ':id' => (int)$profile['id'],
            ':owner_member_id' => (int)$profile['owner_member_id'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('login_broker_unavailable');
        }

        return [
            'ok' => true,
            'profile' => hub_facebook_profile_for_member($db, $profileId, (int)$profile['owner_member_id']),
            'login_url' => 'facebook_profile_login.php#session=' . $secret,
        ];
    } catch (Throwable) {
        if ($started) {
            hub_facebook_login_stop($db, $containerName, $runner, $platform, $runtimeProfile);
        }
        throw new RuntimeException('login_broker_unavailable');
    }
}

function hub_facebook_login_setup(array $input, bool $secureRequest, bool $reauth = false): array
{
    $allowed = $reauth
        ? ['profile_id', 'method', 'username', 'password']
        : ['display_name', 'method', 'username', 'password'];
    if (array_diff(array_keys($input), $allowed) !== []) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }
    $method = $input['method'] ?? null;
    if (!is_string($method) || !in_array($method, ['browser', 'password'], true)) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }
    $identityField = $reauth ? 'profile_id' : 'display_name';
    if (!is_string($input[$identityField] ?? null)) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }
    if ($method === 'browser') {
        if (array_key_exists('username', $input) || array_key_exists('password', $input)) {
            throw new InvalidArgumentException('facebook_profile_invalid');
        }
        return ['credentials' => null];
    }
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    if (
        !$secureRequest
        || !is_string($username)
        || !is_string($password)
        || $username === ''
        || $password === ''
        || strlen($username) > 256
        || strlen($password) > 512
    ) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }

    return ['credentials' => ['username' => $username, 'password' => $password]];
}

function hub_facebook_login_start(
    PDO $db,
    int $ownerMemberId,
    array $input,
    bool $secureRequest,
    ?callable $runner = null,
    ?callable $transport = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): array {
    $setup = hub_facebook_login_setup($input, $secureRequest);
    $displayName = $input['display_name'];
    $public = hub_facebook_profile_create($db, $ownerMemberId, $displayName);
    $profile = hub_facebook_login_owned_row($db, (string)$public['profile_id'], $ownerMemberId)
        ?? throw new RuntimeException('facebook_profile_not_found');

    return hub_facebook_login_open($db, $profile, $setup['credentials'], $runner, $transport, $platform, $runtimeProfile);
}

function hub_facebook_login_reauth(
    PDO $db,
    int $ownerMemberId,
    array $input,
    bool $secureRequest,
    ?callable $runner = null,
    ?callable $transport = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): array {
    $profileId = $input['profile_id'] ?? null;
    if (!is_string($profileId)) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }
    $profile = hub_facebook_login_owned_row($db, $profileId, $ownerMemberId);
    if ($profile === null) {
        throw new RuntimeException('facebook_profile_not_found');
    }
    if ($profile['active_task_id'] !== null) {
        throw new RuntimeException('facebook_profile_busy');
    }
    $setup = hub_facebook_login_setup($input, $secureRequest, true);
    if ($profile['login_container_name'] !== null) {
        if (!hub_facebook_login_container_valid((string)$profile['login_container_name'])) {
            throw new RuntimeException('facebook_profile_login_invalid');
        }
        if (!hub_facebook_login_stop($db, (string)$profile['login_container_name'], $runner ?? 'hub_facebook_login_command_runner', $platform, $runtimeProfile)
            || !hub_facebook_login_clear($db, $profile)) {
            throw new RuntimeException('login_broker_unavailable');
        }
    }

    return hub_facebook_login_open($db, $profile, $setup['credentials'], $runner, $transport, $platform, $runtimeProfile);
}

function hub_facebook_login_expire_owned(
    PDO $db,
    array $profile,
    ?callable $runner = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): void {
    if ($profile['login_expires_at'] === null || (string)$profile['login_expires_at'] > hub_now()) {
        return;
    }
    $runner ??= 'hub_facebook_login_command_runner';
    $container = (string)($profile['login_container_name'] ?? '');
    if (
        !hub_facebook_login_container_valid($container)
        || !hub_facebook_login_stop($db, $container, $runner, $platform, $runtimeProfile)
        || !hub_facebook_login_clear($db, $profile)
    ) {
        return;
    }
}

function hub_facebook_login_status(PDO $db, int $ownerMemberId, string $profileId, ?callable $runner = null, ?string $platform = null, ?array $runtimeProfile = null): array
{
    $profile = hub_facebook_login_owned_row($db, $profileId, $ownerMemberId);
    if ($profile === null) {
        throw new RuntimeException('facebook_profile_not_found');
    }
    hub_facebook_login_expire_owned($db, $profile, $runner, $platform, $runtimeProfile);
    $profile = hub_facebook_login_owned_row($db, $profileId, $ownerMemberId)
        ?? throw new RuntimeException('facebook_profile_not_found');

    return [
        'ok' => true,
        'profile' => hub_facebook_profile_for_member($db, $profileId, $ownerMemberId),
        'login_active' => $profile['login_secret_hash'] !== null,
    ];
}

function hub_facebook_login_delete(PDO $db, int $ownerMemberId, string $profileId, ?callable $runner = null, ?string $platform = null, ?array $runtimeProfile = null): array
{
    $profile = hub_facebook_login_owned_row($db, $profileId, $ownerMemberId);
    if ($profile === null) {
        throw new RuntimeException('facebook_profile_not_found');
    }
    if ($profile['login_container_name'] !== null) {
        $container = (string)$profile['login_container_name'];
        if (!hub_facebook_login_container_valid($container)) {
            throw new RuntimeException('facebook_profile_login_invalid');
        }
        if (!hub_facebook_login_stop($db, $container, $runner ?? 'hub_facebook_login_command_runner', $platform, $runtimeProfile)
            || !hub_facebook_login_clear($db, $profile)) {
            throw new RuntimeException('login_broker_unavailable');
        }
    }
    hub_facebook_profile_delete($db, $profileId, $ownerMemberId);

    return ['ok' => true, 'deleted' => true];
}

function hub_facebook_login_decode_body(?string $rawBody): array
{
    $rawBody ??= (string)file_get_contents('php://input');
    if ($rawBody === '' || strlen($rawBody) > HUB_FACEBOOK_LOGIN_MAX_JSON) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || array_is_list($payload)) {
        throw new InvalidArgumentException('facebook_profile_invalid');
    }

    return $payload;
}

function hub_facebook_login_api_dispatch(
    PDO $db,
    string $mode,
    array $authContext,
    string $method,
    ?string $rawBody,
    array $query,
    bool $secureRequest,
    ?callable $runner = null,
    ?callable $transport = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): array {
    $ownerMemberId = (int)($authContext['member_id'] ?? 0);
    if ($ownerMemberId < 1) {
        return hub_gateway_error(403, 'facebook_profile_forbidden', 'Facebook profile is unavailable');
    }
    try {
        if ($mode === 'facebook_profile_status') {
            if ($method !== 'GET') {
                return hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed');
            }
            if (!is_string($query['profile_id'] ?? null)) {
                throw new InvalidArgumentException('facebook_profile_invalid');
            }
            $result = hub_facebook_login_status($db, $ownerMemberId, $query['profile_id'], $runner, $platform, $runtimeProfile);
        } else {
            if ($method !== 'POST') {
                return hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed');
            }
            $payload = hub_facebook_login_decode_body($rawBody);
            if ($mode === 'facebook_profile_delete'
                && (array_keys($payload) !== ['profile_id'] || !is_string($payload['profile_id'] ?? null))) {
                throw new InvalidArgumentException('facebook_profile_invalid');
            }
            $result = match ($mode) {
                'facebook_profile_start' => hub_facebook_login_start($db, $ownerMemberId, $payload, $secureRequest, $runner, $transport, $platform, $runtimeProfile),
                'facebook_profile_reauth' => hub_facebook_login_reauth($db, $ownerMemberId, $payload, $secureRequest, $runner, $transport, $platform, $runtimeProfile),
                'facebook_profile_delete' => hub_facebook_login_delete($db, $ownerMemberId, $payload['profile_id'], $runner, $platform, $runtimeProfile),
                default => throw new InvalidArgumentException('facebook_profile_invalid'),
            };
        }
        return hub_gateway_json(200, $result);
    } catch (InvalidArgumentException $e) {
        return hub_gateway_error(400, 'facebook_profile_invalid', 'Facebook profile request is invalid');
    } catch (RuntimeException $e) {
        return match ($e->getMessage()) {
            'facebook_profile_not_found' => hub_gateway_error(404, 'facebook_profile_not_found', 'Facebook profile was not found'),
            'facebook_profile_login_invalid' => hub_gateway_error(409, 'facebook_profile_login_invalid', 'Facebook login session is invalid'),
            'login_broker_unavailable' => hub_gateway_error(503, 'login_broker_unavailable', 'Facebook login broker is unavailable'),
            default => hub_gateway_error(409, 'facebook_profile_unavailable', 'Facebook profile is unavailable'),
        };
    }
}

/**
 * 登入驗證只能讀取 opaque profile ID 對應的私有 state 檔；先驗證實體 profile 目錄，
 * 再使用固定檔名，避免登入流程把資料列內容當成可自由指定的檔案路徑。
 */
function hub_facebook_login_state_path(array $profile): string
{
    $profileId = (string)($profile['profile_id'] ?? '');
    $dir = hub_facebook_profile_directory($profileId);
    $rootReal = realpath(hub_facebook_profile_root());
    $dirReal = realpath($dir);
    if (
        $rootReal === false
        || $dirReal === false
        || is_link($dir)
        || dirname($dirReal) !== $rootReal
    ) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    $path = rtrim($dirReal, '/\\') . DIRECTORY_SEPARATOR . 'storage_state.json';
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('profile_storage_unavailable');
    }

    return $path;
}

function hub_facebook_login_state_secure(array $profile): bool
{
    try {
        $path = hub_facebook_login_state_path($profile);
        $dir = dirname($path);
    } catch (Throwable) {
        return false;
    }
    clearstatcache(true, $path);
    $pathStat = @lstat($path);
    $real = realpath($path);
    $handle = @fopen($path, 'rb');
    $stateJson = null;
    try {
        $openedStat = is_resource($handle) ? fstat($handle) : false;
        if (
            !is_resource($handle)
            || is_link($path)
            || !is_array($pathStat)
            || $real === false
            || dirname($real) !== realpath($dir)
            || (PHP_OS_FAMILY !== 'Windows' && (((int)$pathStat['mode'] & 0777) !== 0600))
            || (int)($openedStat['size'] ?? HUB_FACEBOOK_LOGIN_MAX_FRAME + 1) > HUB_FACEBOOK_LOGIN_MAX_FRAME
            || !hub_facebook_profile_file_stats_match($openedStat, $pathStat)
        ) {
            return false;
        }
        $stateJson = stream_get_contents($handle, HUB_FACEBOOK_LOGIN_MAX_FRAME + 1);
        clearstatcache(true, $path);
        if (
            !is_string($stateJson)
            || strlen($stateJson) > HUB_FACEBOOK_LOGIN_MAX_FRAME
            || !hub_facebook_profile_file_stats_match(fstat($handle), @lstat($path))
        ) {
            return false;
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
    }
    $state = json_decode($stateJson, true);
    if (!is_array($state) || !is_array($state['cookies'] ?? null)) {
        return false;
    }
    foreach ($state['cookies'] as $cookie) {
        if (
            is_array($cookie)
            && ($cookie['name'] ?? null) === 'c_user'
            && (string)($cookie['value'] ?? '') !== ''
            && hub_facebook_login_cookie_domain_valid((string)($cookie['domain'] ?? ''))
        ) {
            return true;
        }
    }

    return false;
}

function hub_facebook_login_cookie_domain_valid(string $domain): bool
{
    $domain = strtolower(ltrim($domain, '.'));

    return $domain === 'facebook.com' || str_ends_with($domain, '.facebook.com');
}

function hub_facebook_login_proof_row(PDO $db, string $proof): ?array
{
    if (preg_match('/\A[A-Za-z0-9_-]{43}\z/', $proof) !== 1) {
        return null;
    }
    $hash = hash('sha256', $proof);
    $stmt = $db->prepare(
        "SELECT * FROM facebook_crawler_profiles
         WHERE login_secret_hash = :hash
           AND login_expires_at >= :now
           AND state = 'preparing'
           AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':hash' => $hash, ':now' => hub_now()]);
    $profile = $stmt->fetch();
    if (!is_array($profile) || !is_string($profile['login_secret_hash']) || !hash_equals($profile['login_secret_hash'], $hash)) {
        return null;
    }
    if (!hub_facebook_login_container_valid((string)$profile['login_container_name'])) {
        throw new RuntimeException('facebook_profile_login_invalid');
    }
    $port = (int)($profile['login_port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('facebook_profile_login_invalid');
    }

    return $profile;
}

function hub_facebook_login_relay_dispatch(
    PDO $db,
    string $mode,
    string $method,
    ?string $rawBody,
    ?callable $runner = null,
    ?callable $transport = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): array {
    if ($method !== 'POST') {
        return ['response' => hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed'), 'auth_context' => []];
    }
    try {
        $payload = hub_facebook_login_decode_body($rawBody);
        $proof = is_string($payload['proof'] ?? null) ? $payload['proof'] : '';
        unset($payload['proof']);
        $profile = hub_facebook_login_proof_row($db, $proof);
        $proof = '';
        if ($profile === null) {
            return ['response' => hub_gateway_error(403, 'facebook_profile_login_forbidden', 'Facebook login session is unavailable'), 'auth_context' => []];
        }
        $authContext = ['member_id' => (int)$profile['owner_member_id']];
        $port = (int)$profile['login_port'];
        $path = match ($mode) {
            'facebook_profile_frame' => '/frame',
            'facebook_profile_input' => '/input',
            'facebook_profile_login_status' => '/status',
            'facebook_profile_close' => '/close',
            default => throw new InvalidArgumentException('facebook_profile_invalid'),
        };
        if ($mode === 'facebook_profile_frame') {
            if ($payload !== []) {
                throw new InvalidArgumentException('facebook_profile_invalid');
            }
            $broker = hub_facebook_login_request($transport, 'GET', $port, $path, null, HUB_FACEBOOK_LOGIN_MAX_FRAME);
            $body = (string)($broker['body'] ?? '');
            $response = (int)($broker['status'] ?? 0) === 200
                && strtolower((string)($broker['content_type'] ?? '')) === 'image/png'
                && strlen($body) <= HUB_FACEBOOK_LOGIN_MAX_FRAME
                && str_starts_with($body, "\x89PNG\r\n\x1a\n")
                ? ['status' => 200, 'headers' => ['Content-Type: image/png', 'Cache-Control: no-store', 'X-Content-Type-Options: nosniff'], 'body' => $body]
                : hub_gateway_error(502, 'login_broker_unavailable', 'Facebook login broker is unavailable');
            return ['response' => $response, 'auth_context' => $authContext];
        }

        $requestBody = $mode === 'facebook_profile_input' ? $payload : [];
        if ($mode !== 'facebook_profile_input' && $payload !== []) {
            throw new InvalidArgumentException('facebook_profile_invalid');
        }
        $brokerPayload = hub_facebook_login_json_response_valid(
            hub_facebook_login_request(
                $transport,
                in_array($path, ['/status'], true) ? 'GET' : 'POST',
                $port,
                $path,
                in_array($path, ['/status'], true) ? null : $requestBody,
                HUB_FACEBOOK_LOGIN_MAX_JSON
            )
        );
        if ($brokerPayload === null && $mode === 'facebook_profile_close') {
            if (hub_facebook_login_stop($db, (string)$profile['login_container_name'], $runner ?? 'hub_facebook_login_command_runner', $platform, $runtimeProfile)) {
                hub_facebook_login_clear($db, $profile);
            }
        }
        if ($brokerPayload === null) {
            return ['response' => hub_gateway_error(502, 'login_broker_unavailable', 'Facebook login broker is unavailable'), 'auth_context' => $authContext];
        }
        if ($mode === 'facebook_profile_close') {
            $loggedIn = ($brokerPayload['ok'] ?? null) === true
                && ($brokerPayload['logged_in'] ?? null) === true
                && ($brokerPayload['state'] ?? null) === 'logged_in'
                && hub_facebook_login_state_secure($profile);
            $stopped = hub_facebook_login_stop($db, (string)$profile['login_container_name'], $runner ?? 'hub_facebook_login_command_runner', $platform, $runtimeProfile);
            $cleared = $stopped && hub_facebook_login_clear($db, $profile, $loggedIn ? 'ready' : null, $loggedIn);
            if (!$cleared) {
                return [
                    'response' => hub_gateway_error(409, 'facebook_profile_login_conflict', 'Facebook login session changed'),
                    'auth_context' => $authContext,
                ];
            }
            $public = hub_facebook_profile_for_member($db, (string)$profile['profile_id'], (int)$profile['owner_member_id']);
            return [
                'response' => $loggedIn
                    ? hub_gateway_json(200, ['ok' => true, 'profile' => $public])
                    : hub_gateway_error(409, 'facebook_profile_login_incomplete', 'Facebook login is incomplete'),
                'auth_context' => $authContext,
            ];
        }

        return ['response' => hub_gateway_json(200, $brokerPayload), 'auth_context' => $authContext];
    } catch (InvalidArgumentException) {
        return ['response' => hub_gateway_error(400, 'facebook_profile_invalid', 'Facebook profile request is invalid'), 'auth_context' => []];
    } catch (RuntimeException) {
        return ['response' => hub_gateway_error(409, 'facebook_profile_login_invalid', 'Facebook login session is invalid'), 'auth_context' => []];
    }
}

function hub_facebook_login_cleanup_expired(
    PDO $db,
    int $limit = 10,
    ?callable $runner = null,
    ?string $platform = null,
    ?array $runtimeProfile = null
): int {
    $limit = max(1, min(10, $limit));
    $runner ??= 'hub_facebook_login_command_runner';
    $stmt = $db->prepare(
        'SELECT * FROM facebook_crawler_profiles
         WHERE login_expires_at IS NOT NULL AND login_expires_at <= :now AND deleted_at IS NULL
         ORDER BY login_expires_at, id
         LIMIT :limit'
    );
    $stmt->bindValue(':now', hub_now());
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $cleaned = 0;
    foreach ($stmt->fetchAll() as $profile) {
        $container = (string)($profile['login_container_name'] ?? '');
        if (
            !hub_facebook_login_container_valid($container)
            || !hub_facebook_login_stop($db, $container, $runner, $platform, $runtimeProfile)
            || !hub_facebook_login_clear($db, $profile)
        ) {
            continue;
        }
        $cleaned++;
    }

    return $cleaned;
}
