<?php
declare(strict_types=1);

function hub_process_exit_code(int $closedExitCode, ?int $observedExitCode): int
{
    return $closedExitCode === -1 && $observedExitCode !== null ? $observedExitCode : $closedExitCode;
}

function hub_observed_process_exit_code(array $status): ?int
{
    $exitCode = $status['exitcode'] ?? -1;
    return !$status['running'] && is_int($exitCode) && $exitCode >= 0 ? $exitCode : null;
}

function hub_process_environment(array $overrides = [], ?array $baseEnvironment = null, ?string $platform = null): ?array
{
    if ($overrides === []) {
        return null;
    }

    $baseEnvironment ??= getenv();
    if (strcasecmp($platform ?? PHP_OS_FAMILY, 'Windows') !== 0) {
        return array_replace($baseEnvironment, $overrides);
    }

    $environment = [];
    $keys = [];
    foreach ([$baseEnvironment, $overrides] as $values) {
        foreach ($values as $key => $value) {
            $normalized = strtolower((string)$key);
            if (isset($keys[$normalized])) {
                unset($environment[$keys[$normalized]]);
            }
            $environment[$key] = $value;
            $keys[$normalized] = $key;
        }
    }

    return $environment;
}

function hub_run_command(array $command, int $timeoutSeconds = 60, array $env = []): array
{
    hub_cli_only();

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = hub_process_environment($env);
    $process = @proc_open($command, $descriptor, $pipes, HUB_ROOT, $processEnv);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Cannot start process.', 'output' => 'Cannot start process.'];
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $startedAt = time();
    $observedExitCode = null;
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = hub_observed_process_exit_code($status) ?? $observedExitCode;
            break;
        }
        if (time() - $startedAt > $timeoutSeconds) {
            proc_terminate($process);
            $stderr .= "\nCommand timed out.";
            break;
        }
        usleep(100000);
    } while (true);

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = hub_process_exit_code(proc_close($process), $observedExitCode);
    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

    return ['exit_code' => $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'output' => $output];
}

function hub_linux_docker_unsupported_result(?string $platform = null): ?array
{
    $resolution = hub_runtime_target_resolution('linux-docker', $platform);
    if ($resolution['supported']) {
        return null;
    }

    return hub_unsupported_runtime_result('linux-docker', (string)$resolution['reason']);
}

function hub_run_linux_docker_command(array $command, int $timeoutSeconds = 60, array $env = [], ?string $platform = null): array
{
    return hub_linux_docker_unsupported_result($platform) ?? hub_run_command($command, $timeoutSeconds, $env);
}

function hub_run_command_streamed(array $command, int $timeoutSeconds, array $env, string $stdoutPath, string $stderrPath, ?callable $onOutput = null): array
{
    hub_cli_only();

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = hub_process_environment($env);
    $process = @proc_open($command, $descriptor, $pipes, HUB_ROOT, $processEnv);
    if (!is_resource($process)) {
        file_put_contents($stderrPath, "Cannot start process.\n", FILE_APPEND);
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Cannot start process.', 'output' => 'Cannot start process.'];
    }

    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $startedAt = time();
    $observedExitCode = null;
    do {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $idx => $stream) {
            $chunk = stream_get_contents($pipes[$idx]);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            if ($stream === 'stdout') {
                $stdout = hub_output_tail($stdout . $chunk);
                file_put_contents($stdoutPath, $chunk, FILE_APPEND);
            } else {
                $stderr = hub_output_tail($stderr . $chunk);
                file_put_contents($stderrPath, $chunk, FILE_APPEND);
            }
            if ($onOutput) {
                $onOutput($stream, $chunk);
            }
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = hub_observed_process_exit_code($status) ?? $observedExitCode;
            break;
        }
        if (time() - $startedAt > $timeoutSeconds) {
            proc_terminate($process);
            $stderr .= "\nCommand timed out.";
            file_put_contents($stderrPath, "\nCommand timed out.", FILE_APPEND);
            break;
        }
        usleep(100000);
    } while (true);

    foreach ([1 => 'stdout', 2 => 'stderr'] as $idx => $stream) {
        $chunk = stream_get_contents($pipes[$idx]);
        if ($chunk !== false && $chunk !== '') {
            if ($stream === 'stdout') {
                $stdout = hub_output_tail($stdout . $chunk);
                file_put_contents($stdoutPath, $chunk, FILE_APPEND);
            } else {
                $stderr = hub_output_tail($stderr . $chunk);
                file_put_contents($stderrPath, $chunk, FILE_APPEND);
            }
            if ($onOutput) {
                $onOutput($stream, $chunk);
            }
        }
        fclose($pipes[$idx]);
    }

    $exitCode = hub_process_exit_code(proc_close($process), $observedExitCode);
    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

    return ['exit_code' => $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'output' => $output];
}

function hub_output_tail(string $text, int $bytes = 12000): string
{
    return strlen($text) > $bytes ? substr($text, -$bytes) : $text;
}

function hub_compose_command(array $service, array $args): array
{
    $command = [
        'docker',
        'compose',
        '-p',
        $service['compose_project'],
        '-f',
        hub_path($service['compose_file']),
    ];

    if ((int)($service['hot_reload'] ?? 0) === 1 && ($service['environment'] ?? 'production') === 'development') {
        $devCompose = dirname(hub_path($service['compose_file'])) . '/docker-compose.dev.yml';
        if (is_file($devCompose)) {
            $command[] = '-f';
            $command[] = $devCompose;
        }
    }

    return array_merge($command, $args);
}

function hub_compose_env(array $service): array
{
    $env = [];
    $envFile = dirname(hub_path($service['compose_file'])) . '/.env';
    if (is_file($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                $env[$matches[1]] = $matches[2];
            }
        }
    }
    $env['HELLO_LOCAL_PORT'] = (string)((int)($service['local_port'] ?? 18100) ?: 18100);

    return $env;
}

function hub_service_image_tag(array $service): string
{
    return hub_pack_image_tag((string)($service['service_key'] ?? $service['mode']), (string)($service['pack_version'] ?? 'latest'));
}

function hub_internal_task_result(string $message): array
{
    return ['exit_code' => 0, 'stdout' => $message, 'stderr' => '', 'output' => $message];
}

function hub_service_build_command(array $service): array
{
    return hub_compose_command($service, ['build', '--progress=plain']);
}

function hub_service_start_command(array $service): array
{
    return hub_compose_command($service, ['up', '-d']);
}

function hub_service_runtime_resolution(array $service, ?string $platform = null, ?array $profile = null): array
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    if (!$pack || !is_array($pack['manifest'] ?? null)) {
        return hub_runtime_target_resolution('linux-docker', $platform, $profile);
    }

    return hub_pack_runtime_target_resolution($pack['manifest'], $platform, $profile);
}

function hub_service_uses_wsl_runtime(array $service, ?string $platform = null, ?array $profile = null): bool
{
    $resolution = hub_service_runtime_resolution($service, $platform, $profile);
    return $resolution['target'] === 'windows-wsl2-linux-docker' && !empty($resolution['supported']);
}

function hub_service_runtime_unsupported_result(array $service): ?array
{
    $resolution = hub_service_runtime_resolution($service);
    if (!empty($resolution['supported'])) {
        if ($resolution['target'] === 'windows-wsl2-linux-docker' && hub_wsl_service_runtime($service) === null) {
            return hub_unsupported_runtime_result('windows-wsl2-linux-docker', 'WSL Runtime profile is invalid. Run install.ps1 -Mode WslRuntime -Check.');
        }
        return null;
    }

    return hub_unsupported_runtime_result((string)$resolution['target'], (string)($resolution['reason'] ?? 'Runtime target is not available.'));
}

function hub_wsl_service_runtime(array $service): ?array
{
    $resolution = hub_service_runtime_resolution($service);
    if ($resolution['target'] !== 'windows-wsl2-linux-docker' || empty($resolution['supported'])) {
        return null;
    }

    $profile = is_array($resolution['profile'] ?? null) ? $resolution['profile'] : [];
    $distro = trim((string)($profile['distro'] ?? ''));
    $runtimeRoot = trim((string)($profile['runtime_root'] ?? ''));
    if (preg_match('/^[A-Za-z0-9._-]+$/', $distro) !== 1 || $runtimeRoot === '') {
        return null;
    }

    try {
        $runtimeRoot = hub_container_path($runtimeRoot);
    } catch (InvalidArgumentException) {
        return null;
    }

    return ['distro' => $distro, 'runtime_root' => $runtimeRoot];
}

function hub_wsl_shell_literal(string $value): string
{
    return "'" . str_replace("'", "'\"'\"'", $value) . "'";
}

function hub_windows_powershell_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function hub_wsl_script_command(array $runtime, string $script): array
{
    $payload = base64_encode(str_replace("\r", '', $script));
    $wsl = trim((string)(getenv('AIHUB_WSL_EXECUTABLE') ?: 'C:\\Windows\\System32\\wsl.exe'));
    $bashCommand = 'printf %s ' . $payload . ' | base64 -d | bash';
    // PHP proc_open(array) can quote wsl.exe arguments incorrectly on Windows; PowerShell preserves native argv here.
    $command = '& ' . hub_windows_powershell_literal($wsl)
        . ' -d ' . hub_windows_powershell_literal((string)$runtime['distro'])
        . ' -- bash -lc ' . hub_windows_powershell_literal($bashCommand)
        . '; exit $LASTEXITCODE';
    return ['powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-Command', $command];
}

function hub_wsl_service_compose_command(array $service, array $args): array
{
    $runtime = hub_wsl_service_runtime($service);
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    if ($runtime === null || !$pack || !is_array($pack['manifest'] ?? null)) {
        throw new RuntimeException('WSL Runtime is not ready for this service.');
    }

    $packId = (string)($pack['manifest']['id'] ?? '');
    $serviceKey = (string)($service['service_key'] ?? '');
    $port = (int)($service['local_port'] ?? 0);
    if (
        preg_match('/^[a-z0-9][a-z0-9_-]*$/', $packId) !== 1
        || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey) !== 1
        || $port < 1 || $port > 65535
    ) {
        throw new RuntimeException('Invalid WSL service configuration.');
    }

    $environment = [];
    $sourceEnvironment = hub_compose_env($service);
    foreach ((array)($pack['manifest']['env'] ?? []) as $item) {
        $key = (string)($item['name'] ?? '');
        $value = (string)($sourceEnvironment[$key] ?? $item['default'] ?? '');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || str_contains($value, "\0") || preg_match('/[\r\n]/', $value) === 1) {
            throw new RuntimeException('Invalid WSL service environment.');
        }
        $environment[$key] = $value;
    }
    $environment[hub_pack_port_env($pack['manifest'])] = (string)$port;

    $runtimeRoot = (string)$runtime['runtime_root'];
    $packRoot = $runtimeRoot . '/packs/' . $packId;
    $serviceRoot = $runtimeRoot . '/services/' . $serviceKey;
    $compose = "services:\n  adapter:\n    image: " . json_encode(hub_service_image_tag($service), JSON_UNESCAPED_SLASHES) . "\n    build:\n      context: " . json_encode($packRoot . '/service', JSON_UNESCAPED_SLASHES) . "\n    env_file:\n      - .env\n    ports:\n      - \"127.0.0.1:" . $port . ":8000\"\n    restart: unless-stopped\n";
    $env = '';
    foreach ($environment as $key => $value) {
        $env .= $key . '=' . $value . "\n";
    }

    $composeArgs = array_values($args);
    $dockerCommand = 'docker compose';
    if (($progressIndex = array_search('--progress=plain', $composeArgs, true)) !== false) {
        unset($composeArgs[$progressIndex]);
        $composeArgs = array_values($composeArgs);
        $dockerCommand .= ' --progress=plain';
    }
    $dockerCommand .= ' -p ' . hub_wsl_shell_literal((string)$service['compose_project']) . ' -f ' . hub_wsl_shell_literal($serviceRoot . '/docker-compose.yml');
    foreach ($composeArgs as $arg) {
        $dockerCommand .= ' ' . hub_wsl_shell_literal((string)$arg);
    }
    $script = "set -eu\n"
        . 'pack_root=' . hub_wsl_shell_literal($packRoot) . "\n"
        . 'service_root=' . hub_wsl_shell_literal($serviceRoot) . "\n"
        . 'env_payload=' . hub_wsl_shell_literal(base64_encode($env)) . "\n"
        . 'compose_payload=' . hub_wsl_shell_literal(base64_encode($compose)) . "\n"
        . 'if [ ! -d "$pack_root/service" ]; then echo "WSL Pack source unavailable: $pack_root/service. Run install.ps1 -Mode WslRuntime first." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0775 "$service_root"' . "\n"
        . 'printf %s "$env_payload" | base64 -d > "$service_root/.env"' . "\n"
        . 'printf %s "$compose_payload" | base64 -d > "$service_root/docker-compose.yml"' . "\n"
        . $dockerCommand . "\n";

    return hub_wsl_script_command($runtime, $script);
}

function hub_service_image_exists(array $service): bool
{
    if (!hub_service_uses_wsl_runtime($service)) {
        return hub_docker_image_exists(hub_service_image_tag($service));
    }

    $runtime = hub_wsl_service_runtime($service);
    if ($runtime === null) {
        return false;
    }
    $script = 'docker image inspect ' . hub_wsl_shell_literal(hub_service_image_tag($service)) . ' >/dev/null';
    return hub_run_command(hub_wsl_script_command($runtime, $script), 30)['exit_code'] === 0;
}

function hub_run_service_compose_command(PDO $db, ?array $job, array $service, array $args, int $timeoutSeconds, string $stage, int $minProgress, int $maxProgress): array
{
    $usesWsl = hub_service_uses_wsl_runtime($service);
    $command = $usesWsl ? hub_wsl_service_compose_command($service, $args) : hub_compose_command($service, $args);
    return hub_run_service_command($db, $job, $command, $timeoutSeconds, hub_compose_env($service), $stage, $minProgress, $maxProgress, !$usesWsl);
}

function hub_docker_image_exists(string $image): bool
{
    return hub_run_command(['docker', 'image', 'inspect', $image], 30)['exit_code'] === 0;
}

function hub_compose_status_from_ps(string $output): string
{
    if (trim($output) === '') {
        return 'stopped';
    }
    if (stripos($output, 'running') !== false || stripos($output, 'Up ') !== false) {
        return 'running';
    }

    return 'stopped';
}

function hub_refresh_service_status(PDO $db, array $service): string|array
{
    if (hub_service_is_internal_task($service)) {
        $status = (int)($service['enabled'] ?? 0) === 1 ? 'running' : 'stopped';
        hub_update_service_status($db, (int)$service['id'], $status);
        return $status;
    }

    $unsupported = hub_service_runtime_unsupported_result($service);
    if ($unsupported !== null) {
        return $unsupported;
    }

    $result = hub_run_service_compose_command($db, null, $service, ['ps'], 20, 'status', 0, 0);
    if ($result['exit_code'] !== 0) {
        hub_add_service_log($db, (int)$service['id'], 'status', $result['output'], (int)$result['exit_code']);
        hub_update_service_status($db, (int)$service['id'], 'error');
        return 'error';
    }

    $status = hub_compose_status_from_ps($result['output']);
    hub_update_service_status($db, (int)$service['id'], $status);

    return $status;
}

function hub_start_service(PDO $db, array $service): array
{
    return hub_start_service_with_job($db, $service, null);
}

function hub_start_service_with_job(PDO $db, array $service, ?array $job): array
{
    if (!hub_service_is_internal_task($service)) {
        $unsupported = hub_service_runtime_unsupported_result($service);
        if ($unsupported !== null) {
            return $unsupported;
        }
    }

    hub_job_progress($db, $job, 'prepare_service_dir', 5, 'Preparing service runtime.');
    $service = hub_refresh_service_runtime_files($db, $service);
    if (hub_service_is_internal_task($service)) {
        $result = hub_internal_task_result('internal_task start no-op');
        hub_add_service_log($db, (int)$service['id'], 'start', $result['output'], 0);
        hub_set_service_enabled($db, $service['mode'], true);
        hub_update_service_status($db, (int)$service['id'], 'running');
        return $result;
    }
    hub_refresh_service_status($db, $service);
    $service = hub_get_service($db, (int)$service['id']) ?: $service;
    if (empty($service['local_port']) && ($service['port_mode'] ?? 'auto') === 'auto') {
        hub_update_service_port($db, (int)$service['id'], hub_allocate_local_port($db));
        $service = hub_get_service($db, (int)$service['id']) ?: $service;
    }

    $port = (int)($service['local_port'] ?? 0);
    if (!hub_validate_service_port($port, $db)) {
        $result = ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Invalid local port.', 'output' => 'Invalid local port.'];
        hub_add_service_log($db, (int)$service['id'], 'start', $result['output'], (int)$result['exit_code']);
        hub_update_service_status($db, (int)$service['id'], 'error');
        return $result;
    }
    if (($service['status'] ?? 'stopped') !== 'running' && hub_port_is_busy($port)) {
        $result = ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Local port is already in use: ' . $port, 'output' => 'Local port is already in use: ' . $port];
        hub_add_service_log($db, (int)$service['id'], 'start', $result['output'], (int)$result['exit_code']);
        hub_update_service_status($db, (int)$service['id'], 'error');
        return $result;
    }

    hub_job_progress($db, $job, 'check_image_cache', 10, 'Checking Docker image: ' . hub_service_image_tag($service));
    if (!hub_service_image_exists($service)) {
        if (hub_get_storage_setting($db, 'AIHUB_AUTO_BUILD_MISSING_IMAGE') !== '1') {
            return ['exit_code' => 4, 'stdout' => '', 'stderr' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service), 'output' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service)];
        }
        $build = hub_build_service($db, $service, $job);
        if ((int)$build['exit_code'] !== 0) {
            return $build;
        }
    }

    hub_job_progress($db, $job, 'docker_up', 80, 'Starting container.');
    $result = hub_run_service_compose_command($db, $job, $service, ['up', '-d'], 10, 'docker_up', 80, 89);
    hub_add_service_log($db, (int)$service['id'], 'start', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_set_service_enabled($db, $service['mode'], true);
        $service = hub_get_service($db, (int)$service['id']) ?: $service;
        hub_job_progress($db, $job, 'health_check', 90, 'Refreshing service status.');
        hub_refresh_service_status($db, $service);
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
}

function hub_build_service(PDO $db, array $service, ?array $job = null): array
{
    if (!hub_service_is_internal_task($service)) {
        $unsupported = hub_service_runtime_unsupported_result($service);
        if ($unsupported !== null) {
            return $unsupported;
        }
    }

    hub_job_progress($db, $job, 'prepare_service_dir', 5, 'Preparing service runtime.');
    $service = hub_refresh_service_runtime_files($db, $service);
    if (hub_service_is_internal_task($service)) {
        $result = hub_internal_task_result('internal_task build no-op');
        hub_add_service_log($db, (int)$service['id'], 'build', $result['output'], 0);
        hub_job_progress($db, $job, 'docker_build', 70, 'internal_task build no-op.');
        return $result;
    }
    hub_job_progress($db, $job, 'docker_build', 20, 'Building image: ' . hub_service_image_tag($service));
    $result = hub_run_service_compose_command($db, $job, $service, ['build', '--progress=plain'], 900, 'docker_build', 20, 70);
    $summary = $result['exit_code'] === 0
        ? 'Image build completed: ' . hub_service_image_tag($service)
        : substr(hub_command_error_summary($result), 0, 1000);
    hub_add_service_log($db, (int)$service['id'], 'build', $summary, (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_job_progress($db, $job, 'docker_build', 70, 'Image build completed.');
    }

    return $result;
}

function hub_refresh_service_runtime_files(PDO $db, array $service): array
{
    if (empty($service['pack_id']) || empty($service['service_key'])) {
        return $service;
    }

    $env = json_decode((string)($service['environment_json'] ?? ''), true);
    hub_install_pack($db, (string)$service['pack_id'], [
        'service_key' => (string)$service['service_key'],
        'name' => (string)$service['name'],
        'mode' => (string)$service['mode'],
        'port_mode' => (string)$service['port_mode'],
        'local_port' => (int)$service['local_port'],
        'environment' => (string)$service['environment'],
        'hot_reload' => (int)$service['hot_reload'] === 1,
        'env' => is_array($env) ? $env : [],
        'idempotent' => true,
    ]);

    return hub_get_service($db, (int)$service['id']) ?: $service;
}

function hub_stop_service(PDO $db, array $service): array
{
    if (hub_service_is_internal_task($service)) {
        $result = hub_internal_task_result('internal_task stop no-op');
        hub_add_service_log($db, (int)$service['id'], 'stop', $result['output'], 0);
        hub_set_service_enabled($db, $service['mode'], false);
        hub_update_service_status($db, (int)$service['id'], 'stopped');
        return $result;
    }

    $unsupported = hub_service_runtime_unsupported_result($service);
    if ($unsupported !== null) {
        return $unsupported;
    }

    $result = hub_run_service_compose_command($db, null, $service, ['down', '--timeout', '5'], 10, 'docker_down', 0, 0);
    hub_add_service_log($db, (int)$service['id'], 'stop', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_set_service_enabled($db, $service['mode'], false);
        hub_update_service_status($db, (int)$service['id'], 'stopped');
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
}

function hub_restart_service(PDO $db, array $service): array
{
    if (hub_service_is_internal_task($service)) {
        $result = hub_internal_task_result('internal_task restart no-op');
        hub_add_service_log($db, (int)$service['id'], 'restart', $result['output'], 0);
        return $result;
    }

    $unsupported = hub_service_runtime_unsupported_result($service);
    if ($unsupported !== null) {
        return $unsupported;
    }

    $requiresRecreate = (int)($service['restart_required'] ?? 0) === 1;
    $args = $requiresRecreate ? ['up', '-d', '--force-recreate'] : ['restart', '--timeout', '5'];
    $stage = $requiresRecreate ? 'docker_recreate' : 'docker_restart';
    $result = hub_run_service_compose_command($db, null, $service, $args, 20, $stage, 0, 0);
    hub_add_service_log($db, (int)$service['id'], 'restart', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        if ($requiresRecreate) {
            $db->prepare('UPDATE services SET restart_required = 0, updated_at = :updated_at WHERE id = :id')->execute([
                ':updated_at' => hub_now(),
                ':id' => (int)$service['id'],
            ]);
            $service = hub_get_service($db, (int)$service['id']) ?: $service;
        }
        hub_refresh_service_status($db, $service);
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
}

function hub_tail_service_logs(PDO $db, array $service): array
{
    if (hub_service_is_internal_task($service)) {
        $result = hub_internal_task_result('internal_task logs no-op');
        hub_add_service_log($db, (int)$service['id'], 'docker_logs', $result['output'], 0);
        return $result;
    }

    $unsupported = hub_service_runtime_unsupported_result($service);
    if ($unsupported !== null) {
        return $unsupported;
    }

    $result = hub_run_service_compose_command($db, null, $service, ['logs', '--tail', '200'], 30, 'docker_logs', 0, 0);
    hub_add_service_log($db, (int)$service['id'], 'docker_logs', $result['output'], (int)$result['exit_code']);

    return $result;
}

function hub_run_service_command(PDO $db, ?array $job, array $command, int $timeoutSeconds, array $env, string $stage, int $minProgress, int $maxProgress, bool $requiresLinuxDocker = true): array
{
    if (!$job) {
        return $requiresLinuxDocker
            ? hub_run_linux_docker_command($command, $timeoutSeconds, $env)
            : hub_run_command($command, $timeoutSeconds, $env);
    }

    $job = hub_prepare_command_job_logs($db, $job);
    $progress = $minProgress;
    $lastUpdate = 0;

    return hub_run_command_streamed(
        $command,
        $timeoutSeconds,
        $env,
        (string)$job['stdout_path'],
        (string)$job['stderr_path'],
        static function (string $stream, string $chunk) use ($db, $job, $stage, &$progress, &$lastUpdate, $maxProgress): void {
            $line = hub_last_output_line($chunk);
            if ($line === '') {
                return;
            }
            if (time() === $lastUpdate && $progress >= $maxProgress) {
                return;
            }
            $lastUpdate = time();
            $progress = min($maxProgress, $progress + 1);
            hub_update_command_job_progress($db, (int)$job['id'], $stage, $progress, $line);
        }
    );
}

function hub_last_output_line(string $chunk): string
{
    $lines = preg_split('/\r?\n/', trim($chunk));
    if (!$lines) {
        return '';
    }

    return substr(trim((string)end($lines)), 0, 500);
}

function hub_job_progress(PDO $db, ?array $job, string $stage, int $progress, string $message): void
{
    if ($job) {
        hub_update_command_job_progress($db, (int)$job['id'], $stage, $progress, $message);
    }
}
