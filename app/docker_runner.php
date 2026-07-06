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

    $result = hub_run_command(hub_compose_command($service, ['up', '-d', '--build']), 180, hub_compose_env($service));
    hub_add_service_log($db, (int)$service['id'], 'start', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_set_service_enabled($db, $service['mode'], true);
        $service = hub_get_service($db, (int)$service['id']) ?: $service;
        hub_refresh_service_status($db, $service);
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
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
    hub_stop_service($db, $service);
    $service = hub_get_service($db, (int)$service['id']) ?: $service;

    return hub_start_service($db, $service);
}

function hub_tail_service_logs(PDO $db, array $service): array
{
    $result = hub_run_command(hub_compose_command($service, ['logs', '--tail', '200']), 30, hub_compose_env($service));
    hub_add_service_log($db, (int)$service['id'], 'docker_logs', $result['output'], (int)$result['exit_code']);

    return $result;
}
