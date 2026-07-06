<?php
declare(strict_types=1);

function hub_run_command(array $command, int $timeoutSeconds = 60, array $env = []): array
{
    hub_cli_only();

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = $env ? array_merge($_ENV, $env) : null;
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
    do {
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
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
    $exitCode = proc_close($process);
    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));

    return ['exit_code' => $exitCode, 'stdout' => trim($stdout), 'stderr' => trim($stderr), 'output' => $output];
}

function hub_run_command_streamed(array $command, int $timeoutSeconds, array $env, string $stdoutPath, string $stderrPath, ?callable $onOutput = null): array
{
    hub_cli_only();

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = $env ? array_merge($_ENV, $env) : null;
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

    $exitCode = proc_close($process);
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

function hub_service_build_command(array $service): array
{
    return hub_compose_command($service, ['build', '--progress=plain']);
}

function hub_service_start_command(array $service): array
{
    return hub_compose_command($service, ['up', '-d']);
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

function hub_refresh_service_status(PDO $db, array $service): string
{
    $result = hub_run_command(hub_compose_command($service, ['ps']), 20, hub_compose_env($service));
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
    hub_job_progress($db, $job, 'prepare_service_dir', 5, 'Preparing service runtime.');
    $service = hub_refresh_service_runtime_files($db, $service);
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
    if (!hub_docker_image_exists(hub_service_image_tag($service))) {
        if (hub_get_storage_setting($db, 'AIHUB_AUTO_BUILD_MISSING_IMAGE') !== '1') {
            return ['exit_code' => 4, 'stdout' => '', 'stderr' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service), 'output' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service)];
        }
        $build = hub_build_service($db, $service, $job);
        if ((int)$build['exit_code'] !== 0) {
            return $build;
        }
    }

    hub_job_progress($db, $job, 'docker_up', 80, 'Starting container.');
    $result = hub_run_service_command($db, $job, hub_service_start_command($service), 900, hub_compose_env($service), 'docker_up', 80, 89);
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
    hub_job_progress($db, $job, 'prepare_service_dir', 5, 'Preparing service runtime.');
    $service = hub_refresh_service_runtime_files($db, $service);
    hub_job_progress($db, $job, 'docker_build', 20, 'Building image: ' . hub_service_image_tag($service));
    $result = hub_run_service_command($db, $job, hub_service_build_command($service), 900, hub_compose_env($service), 'docker_build', 20, 70);
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
    $result = hub_run_command(hub_compose_command($service, ['down']), 60, hub_compose_env($service));
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
    $result = hub_run_command(hub_compose_command($service, ['restart']), 120, hub_compose_env($service));
    hub_add_service_log($db, (int)$service['id'], 'restart', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_refresh_service_status($db, $service);
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
}

function hub_tail_service_logs(PDO $db, array $service): array
{
    $result = hub_run_command(hub_compose_command($service, ['logs', '--tail', '200']), 30, hub_compose_env($service));
    hub_add_service_log($db, (int)$service['id'], 'docker_logs', $result['output'], (int)$result['exit_code']);

    return $result;
}

function hub_run_service_command(PDO $db, ?array $job, array $command, int $timeoutSeconds, array $env, string $stage, int $minProgress, int $maxProgress): array
{
    if (!$job) {
        return hub_run_command($command, $timeoutSeconds, $env);
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
