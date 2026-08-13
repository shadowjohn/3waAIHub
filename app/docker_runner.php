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

/**
 * Windows 的 proc_open 若退回 cmd.exe，會重新解讀 command line。
 * argv 已分離時仍明確要求直接建立程序，避免平台預設值改變安全邊界。
 */
function hub_process_execution_options(?string $platform = null): array
{
    return strcasecmp($platform ?? PHP_OS_FAMILY, 'Windows') === 0
        ? ['bypass_shell' => true]
        : [];
}

/**
 * Docker CLI 的 model 參數不是 shell 字串；仍拒絕 option 前綴、空白與控制字元，
 * 避免模型名稱被解讀為另一個 CLI option 或跨越 argv 邊界。
 */
function hub_ollama_model_reference(string $model): string
{
    $model = trim($model);
    if (preg_match('~^[A-Za-z0-9][A-Za-z0-9._:/@+_-]{0,254}$~D', $model) !== 1) {
        throw new InvalidArgumentException('Invalid Ollama model reference.');
    }

    return $model;
}

/**
 * Ollama 拉取記錄不是由 service row 提供路徑；service key 僅能是 Pack runtime key，
 * 檔名與目錄固定在 Hub 管理的 logs/models，避免損壞的資料列改寫任意檔案。
 */
function hub_ollama_model_pull_log_path(string $serviceKey, string $timestamp): string
{
    $serviceKey = hub_pack_runtime_service_key($serviceKey);
    if (preg_match('/\A[0-9]{8}_[0-9]{6}\z/D', $timestamp) !== 1) {
        throw new InvalidArgumentException('Ollama pull log timestamp is invalid.');
    }

    hub_ensure_runtime_dirs();
    $modelsLogDir = HUB_LOG_DIR . '/models';
    if (!is_dir($modelsLogDir) && !mkdir($modelsLogDir, 0775, true) && !is_dir($modelsLogDir)) {
        throw new RuntimeException('Ollama pull log directory is unavailable.');
    }

    clearstatcache(true, $modelsLogDir);
    $resolvedLogDir = realpath($modelsLogDir);
    if ($resolvedLogDir === false || !is_dir($resolvedLogDir) || is_link($modelsLogDir)) {
        throw new RuntimeException('Ollama pull log directory is unsafe.');
    }

    $path = rtrim($resolvedLogDir, '/\\') . DIRECTORY_SEPARATOR
        . 'ollama_pull_' . $serviceKey . '_' . $timestamp . '.log';
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('Ollama pull log must be a regular file.');
    }

    return $path;
}

function hub_command_path_is_absolute(string $path, ?string $platform = null): bool
{
    if (strcasecmp($platform ?? PHP_OS_FAMILY, 'Windows') === 0) {
        return preg_match('/\A[A-Za-z]:[\\\\\/]/D', $path) === 1 || str_starts_with($path, '\\\\');
    }

    return str_starts_with($path, '/');
}

function hub_command_paths_equal(string $left, string $right, ?string $platform = null): bool
{
    $platform ??= PHP_OS_FAMILY;
    $left = rtrim(str_replace('\\', '/', $left), '/');
    $right = rtrim(str_replace('\\', '/', $right), '/');

    return strcasecmp($platform, 'Windows') === 0
        ? strcasecmp($left, $right) === 0
        : $left === $right;
}

/**
 * 命令執行一律指向平台固定位置，不從 PATH 解析 executable。
 * 呼叫端可提供的只是已驗證 argv；第一個元素只當成 allowlist key，不能指定路徑。
 */
function hub_windows_nvidia_smi_path(string $systemRoot, string $programFiles, ?callable $isFile = null): string
{
    $isFile ??= static fn (string $path): bool => is_file($path);
    $systemPath = $systemRoot . '\\System32\\nvidia-smi.exe';
    $legacyPath = $programFiles . '\\NVIDIA Corporation\\NVSMI\\nvidia-smi.exe';

    foreach ([$systemPath, $legacyPath] as $candidate) {
        if ($isFile($candidate)) {
            return $candidate;
        }
    }

    // 保留固定系統路徑，不退回可被服務環境污染的 PATH。
    return $systemPath;
}

function hub_trusted_command_path(string $executable, ?string $platform = null): string
{
    $platform ??= PHP_OS_FAMILY;
    $name = strtolower(basename(str_replace('\\', '/', trim($executable))));
    $phpBinaryName = strtolower(basename(str_replace('\\', '/', PHP_BINARY)));
    $systemRoot = rtrim((string)getenv('SystemRoot'), '\\/');
    $programFiles = rtrim((string)getenv('ProgramFiles'), '\\/');

    if (strcasecmp($platform, 'Windows') === 0) {
        $paths = [
            'php' => PHP_BINARY,
            'php.exe' => PHP_BINARY,
            $phpBinaryName => PHP_BINARY,
            'powershell.exe' => $systemRoot . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe',
            'wsl.exe' => $systemRoot . '\\System32\\wsl.exe',
            'curl' => $systemRoot . '\\System32\\curl.exe',
            'docker' => $programFiles . '\\Docker\\Docker\\resources\\bin\\docker.exe',
            'git' => $programFiles . '\\Git\\cmd\\git.exe',
            'nvidia-smi' => hub_windows_nvidia_smi_path($systemRoot, $programFiles),
        ];
    } else {
        $paths = [
            'php' => PHP_BINARY,
            'php.exe' => PHP_BINARY,
            $phpBinaryName => PHP_BINARY,
            'bash' => '/usr/bin/bash',
            'curl' => '/usr/bin/curl',
            'docker' => '/usr/bin/docker',
            'ffmpeg' => '/usr/bin/ffmpeg',
            'ffprobe' => '/usr/bin/ffprobe',
            'git' => '/usr/bin/git',
            'nvidia-smi' => '/usr/bin/nvidia-smi',
        ];
    }

    $path = $paths[$name] ?? '';
    if ($path === '' || !hub_command_path_is_absolute($path, $platform)) {
        throw new InvalidArgumentException('Untrusted command executable.');
    }

    return $path;
}

function hub_valid_argv(array $command): bool
{
    if ($command === [] || !array_is_list($command)) {
        return false;
    }

    $executable = strtolower(basename(str_replace('\\', '/', (string)($command[0] ?? ''))));
    $phpBinaryName = strtolower(basename(str_replace('\\', '/', PHP_BINARY)));
    if (!in_array($executable, [
        'bash',
        'curl',
        'docker',
        'ffmpeg',
        'ffprobe',
        'git',
        'nvidia-smi',
        'php',
        'php.exe',
        $phpBinaryName,
        'powershell.exe',
        'wsl.exe',
    ], true)) {
        return false;
    }

    foreach ($command as $argument) {
        if (!is_string($argument) || $argument === '' || strlen($argument) > 65535 || preg_match('/[\x00-\x1F\x7F]/', $argument) === 1) {
            return false;
        }
    }

    return true;
}

/**
 * proc_open 的唯一 argv 邊界：只回傳已驗證的 list argv，禁止呼叫端退回 shell 字串。
 */
function hub_safe_argv(array $command): array
{
    if (!hub_valid_argv($command)) {
        throw new InvalidArgumentException('Invalid command.');
    }

    $requestedExecutable = (string)$command[0];
    $trustedExecutable = hub_trusted_command_path($requestedExecutable);
    if (
        (str_contains($requestedExecutable, '/') || str_contains($requestedExecutable, '\\'))
        && !hub_command_paths_equal($requestedExecutable, $trustedExecutable)
    ) {
        throw new InvalidArgumentException('Untrusted command executable.');
    }

    $command[0] = $trustedExecutable;
    return $command;
}

function hub_run_command(array $command, int $timeoutSeconds = 60, array $env = []): array
{
    hub_cli_only();

    return hub_run_argv_command($command, $timeoutSeconds, $env);
}

/**
 * 執行已由呼叫端固定或驗證完成的 argv；不接受 shell command string。
 * Web 呼叫端不得將未驗證的 request 值直接放入 argv。
 */
function hub_run_argv_command(array $command, int $timeoutSeconds = 60, array $env = []): array
{
    try {
        $command = hub_safe_argv($command);
    } catch (InvalidArgumentException) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Invalid command.', 'output' => 'Invalid command.'];
    }

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = hub_process_environment($env);
    $process = @proc_open($command, $descriptor, $pipes, HUB_ROOT, $processEnv, hub_process_execution_options());
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

    try {
        $stdoutPath = hub_command_job_log_path($stdoutPath, 'stdout');
        $stderrPath = hub_command_job_log_path($stderrPath, 'stderr');
    } catch (RuntimeException) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Invalid command.', 'output' => 'Invalid command.'];
    }

    try {
        $command = hub_safe_argv($command);
    } catch (InvalidArgumentException) {
        file_put_contents($stderrPath, "Invalid command.\n", FILE_APPEND);
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Invalid command.', 'output' => 'Invalid command.'];
    }

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $processEnv = hub_process_environment($env);
    $process = @proc_open($command, $descriptor, $pipes, HUB_ROOT, $processEnv, hub_process_execution_options());
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

/**
 * 將資料庫 service row 收斂成 Pack 宣告的 runtime contract。
 * Docker/WSL 的 compose 檔與 project 不接受資料表任意值，避免設定遭竄改後變成程序引數。
 */
function hub_service_command_contract(PDO $db, array $service): array
{
    $serviceKey = (string)($service['service_key'] ?? '');
    $packId = (string)($service['pack_id'] ?? '');
    $port = filter_var($service['local_port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    $environment = (string)($service['environment'] ?? 'production');
    if (
        preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $serviceKey) !== 1
        || preg_match('/^[a-z0-9][a-z0-9_-]{0,127}$/', $packId) !== 1
        || $port === false
        || !in_array($environment, ['production', 'development'], true)
    ) {
        throw new RuntimeException('Invalid service runtime contract.');
    }

    $pack = hub_get_pack($packId);
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : null;
    if (!is_array($manifest) || ($manifest['id'] ?? null) !== $packId) {
        throw new RuntimeException('Service Pack runtime contract is unavailable.');
    }

    $expectedComposeFile = hub_pack_compose_file($db, $serviceKey);
    $expectedComposeProject = hub_compose_project_for_instance($manifest, $serviceKey);
    if (
        !hash_equals($expectedComposeFile, (string)($service['compose_file'] ?? ''))
        || !hash_equals($expectedComposeProject, (string)($service['compose_project'] ?? ''))
    ) {
        throw new RuntimeException('Service runtime contract does not match the declared Pack.');
    }

    $packVersion = (string)($manifest['version'] ?? '');
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $packVersion) !== 1) {
        throw new RuntimeException('Service Pack version is invalid.');
    }

    return [
        'pack_id' => $packId,
        'pack_version' => $packVersion,
        'service_key' => $serviceKey,
        'compose_file' => $expectedComposeFile,
        'compose_project' => $expectedComposeProject,
        'local_port' => $port,
        'hot_reload' => (int)($service['hot_reload'] ?? 0) === 1 ? 1 : 0,
        'environment' => $environment,
    ];
}

function hub_compose_command(array $service, array $args): array
{
    $settingsPath = hub_runtime_settings_path(dirname(hub_path((string)$service['compose_file'])));
    if (!is_file($settingsPath) || is_link($settingsPath)) {
        throw new RuntimeException('Service runtime settings file is unavailable.');
    }

    $composeProject = trim((string)($service['active_compose_project'] ?? ''));
    if ($composeProject === '') {
        $composeProject = (string)$service['compose_project'];
    }

    $command = [
        'docker',
        'compose',
        '--env-file',
        $settingsPath,
        '-p',
        $composeProject,
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
    $settingsPath = hub_runtime_settings_path(dirname(hub_path((string)$service['compose_file'])));
    if (is_file($settingsPath) && !is_link($settingsPath)) {
        foreach (file($settingsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches)) {
                $env[$matches[1]] = $matches[2];
            }
        }
    }
    $env['HELLO_LOCAL_PORT'] = (string)((int)($service['local_port'] ?? 18100) ?: 18100);

    return $env;
}

function hub_docker_command_environment(): array
{
    $home = HUB_DATA_DIR . '/docker-cli';
    if (!is_dir($home) && !mkdir($home, 0770, true) && !is_dir($home)) {
        throw new RuntimeException('Docker CLI home is unavailable.');
    }

    return ['HOME' => $home, 'DOCKER_CONFIG' => $home];
}

function hub_service_image_tag(array $service): string
{
    if ((string)($service['pack_id'] ?? '') === 'whisper-asr') {
        return '3waaihub/whisper-asr:' . (string)($service['pack_version'] ?? 'latest');
    }
    if ((string)($service['pack_id'] ?? '') === 'tts-gpt-sovits') {
        return '3waaihub/tts-gpt-sovits:' . (string)($service['pack_version'] ?? 'latest');
    }

    return hub_pack_image_tag((string)($service['service_key'] ?? $service['mode']), (string)($service['pack_version'] ?? 'latest'));
}

function hub_service_runtime_image_tag(array $service): string
{
    $whisper = hub_whisper_wsl_runtime_profile($service);
    return $whisper === null ? hub_service_image_tag($service) : (string)$whisper['image'];
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

function hub_service_requires_wsl_job_runtime(array $service, ?string $platform = null): bool
{
    if (hub_platform_id($platform) !== 'windows') {
        return false;
    }
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));

    return is_array($pack['manifest'] ?? null) && !empty($pack['manifest']['runtime']['windows_wsl_job']);
}

function hub_service_requires_wsl_task_worker(array $service, ?string $platform = null): bool
{
    if (hub_platform_id($platform) !== 'windows') {
        return false;
    }
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    $runtime = is_array($pack['manifest']['runtime'] ?? null) ? $pack['manifest']['runtime'] : [];

    return !empty($runtime['windows_wsl_job']) || !empty($runtime['windows_wsl_compose']);
}

function hub_service_runtime_unsupported_result(array $service, ?string $platform = null, ?array $profile = null): ?array
{
    $resolution = hub_service_runtime_resolution($service, $platform, $profile);
    if (!empty($resolution['supported'])) {
        if ($resolution['target'] === 'windows-wsl2-linux-docker' && hub_wsl_service_runtime($service, $platform, $profile) === null) {
            return hub_unsupported_runtime_result('windows-wsl2-linux-docker', 'WSL Runtime profile is invalid. Run install.ps1 -Mode WslRuntime -Check.');
        }
        return null;
    }

    return hub_unsupported_runtime_result((string)$resolution['target'], (string)($resolution['reason'] ?? 'Runtime target is not available.'));
}

function hub_wsl_service_runtime(array $service, ?string $platform = null, ?array $profile = null): ?array
{
    $resolution = hub_service_runtime_resolution($service, $platform, $profile);
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

function hub_wsl_job_runner_build_command(array $service, array $docker, ?array $profile = null): array
{
    $packId = (string)($service['pack_id'] ?? '');
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
    $pack = hub_get_pack($packId);
    $build = is_array($pack) ? hub_pack_container_runner_build_contract((array)$pack['manifest'], (string)$pack['dir']) : null;
    if ($runtime === null || $build === null || ($pack['manifest']['id'] ?? null) !== $packId
        || empty($pack['manifest']['runtime']['windows_wsl_job'])) {
        throw new RuntimeException('WSL Runtime is not ready for this Pack.');
    }

    $image = (string)$build['image'];
    $inspect = ['docker', 'image', 'inspect', '--format', '{{.Id}}', $image];
    $buildCommand = ['docker', 'build', '--tag', $image, '--file', (string)$build['dockerfile'], (string)$build['context']];
    if ($docker !== $inspect && $docker !== $buildCommand) {
        throw new InvalidArgumentException('Unexpected WSL Pack Docker build command.');
    }

    $serviceRoot = (string)$runtime['runtime_root'] . '/packs/' . $packId . '/service';
    $script = "set -eu\n"
        . 'service_root=' . hub_wsl_shell_literal($serviceRoot) . "\n"
        . 'if [ ! -f "$service_root/Dockerfile" ]; then echo "WSL Pack source unavailable: $service_root/Dockerfile. Run install.ps1 -Mode WslRuntime first." >&2; exit 2; fi' . "\n";
    if ($docker === $inspect) {
        $script .= 'exec docker image inspect --format ' . hub_wsl_shell_literal('{{.Id}}') . ' ' . hub_wsl_shell_literal($image) . "\n";
    } else {
        $script .= 'exec docker build --progress=quiet --tag ' . hub_wsl_shell_literal($image)
            . ' --file "$service_root/Dockerfile" "$service_root"' . "\n";
    }

    return hub_wsl_script_command($runtime, $script);
}

function hub_web_screenshot_wsl_runner_build_command(array $service, array $docker, ?array $profile = null): array
{
    if ((string)($service['pack_id'] ?? '') !== 'web-screenshot') {
        throw new InvalidArgumentException('Web Screenshot service is required.');
    }

    return hub_wsl_job_runner_build_command($service, $docker, $profile);
}

function hub_edge_tts_wsl_demo_command(array $service, array $docker, ?array $profile = null): array
{
    if ((string)($service['pack_id'] ?? '') !== 'edge-tts') {
        throw new InvalidArgumentException('Edge TTS service is required.');
    }
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
    $pack = hub_get_pack('edge-tts');
    $build = is_array($pack) ? hub_pack_container_runner_build_contract((array)$pack['manifest'], (string)$pack['dir']) : null;
    $serviceKey = (string)($service['service_key'] ?? '');
    if ($runtime === null || $build === null || ($pack['manifest']['runtime']['windows_wsl_job'] ?? false) !== true
        || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey) !== 1) {
        throw new RuntimeException('WSL Runtime is not ready for Edge TTS.');
    }

    $image = (string)$build['image'];
    $containerPrefix = 'edge-tts-demo-' . $serviceKey . '-';
    $isRun = ($docker[0] ?? null) === 'docker' && ($docker[1] ?? null) === 'run';
    $nameIndex = array_search('--name', $docker, true);
    $containerName = $isRun && is_int($nameIndex)
        ? (string)($docker[$nameIndex + 1] ?? '')
        : (string)end($docker);
    if (preg_match('/^' . preg_quote($containerPrefix, '/') . '[a-f0-9]{32}$/', $containerName) !== 1) {
        throw new InvalidArgumentException('Unexpected Edge TTS demo container.');
    }
    if ($isRun) {
        $mountIndex = array_search('--mount', $docker, true);
        $mount = is_int($mountIndex) ? (string)($docker[$mountIndex + 1] ?? '') : '';
        if (preg_match('#^type=bind,src=(.+),dst=/workspace/output$#', $mount, $matches) !== 1) {
            throw new InvalidArgumentException('Unexpected Edge TTS demo mount.');
        }
        $staging = $matches[1];
        $parent = dirname(hub_edge_tts_demo_root($serviceKey));
        if (!is_dir($staging) || is_link($staging) || !hub_storage_path_is_within($staging, $parent)
            || preg_match('/^\.staging-[a-f0-9]{32}$/', basename($staging)) !== 1) {
            throw new InvalidArgumentException('Edge TTS demo staging is unavailable.');
        }
        $expected = [
            'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
            '--mount', $mount, '--name', $containerName, '--entrypoint', '/app/edge-tts-entrypoint.sh',
            $image, '/app/generate_demos.py',
        ];
        if ($docker !== $expected) {
            throw new InvalidArgumentException('Unexpected Edge TTS demo Docker command.');
        }

        $runId = substr($containerName, strlen($containerPrefix));
        $demoRoot = (string)$runtime['runtime_root'] . '/demos/edge-tts/' . $serviceKey . '/' . $runId;
        $demoFiles = ['available.json'];
        foreach (hub_edge_tts_voice_catalog() as $voice) {
            $demoFiles[] = (string)$voice['demo_file'];
        }
        $copyCommands = '';
        foreach ($demoFiles as $demoFile) {
            $copyCommands .= 'copy_demo_file ' . hub_wsl_shell_literal($demoFile) . "\n";
        }

        $script = "set -eu\n"
            . 'windows_staging=' . hub_wsl_shell_literal($staging) . "\n"
            . 'host_staging="$(wslpath -a "$windows_staging")"' . "\n"
            . 'demo_root=' . hub_wsl_shell_literal($demoRoot) . "\n"
            . 'if [ ! -d "$host_staging" ] || [ -L "$host_staging" ]; then echo "Edge TTS demo staging is unavailable." >&2; exit 2; fi' . "\n"
            . 'cleanup() { docker container rm -f ' . hub_wsl_shell_literal($containerName) . ' >/dev/null 2>&1 || true; rm -rf "$demo_root"; }' . "\n"
            . 'trap cleanup EXIT' . "\n"
            . 'install -d -m 0700 "$demo_root/output"' . "\n"
            . 'docker run --pull=never --network bridge --cap-add NET_ADMIN --mount "type=bind,src=$demo_root/output,dst=/workspace/output"'
            . ' --name ' . hub_wsl_shell_literal($containerName)
            . ' --entrypoint ' . hub_wsl_shell_literal('/app/edge-tts-entrypoint.sh')
            . ' ' . hub_wsl_shell_literal($image)
            . ' ' . hub_wsl_shell_literal('/app/generate_demos.py') . "\n"
            . 'copy_demo_file() { source="$demo_root/output/$1"; [ ! -e "$source" ] && return 0; [ -f "$source" ] && [ ! -L "$source" ] || exit 2; cp -- "$source" "$host_staging/$1"; }' . "\n"
            . $copyCommands;

        return hub_wsl_script_command($runtime, $script);
    }

    $allowed = [
        ['docker', 'container', 'inspect', '--format', '{{json .State}}', $containerName],
        ['docker', 'stop', '-t', '10', $containerName],
        ['docker', 'container', 'rm', '-f', $containerName],
    ];
    if (!in_array($docker, $allowed, true)) {
        throw new InvalidArgumentException('Unexpected Edge TTS demo Docker command.');
    }

    return hub_wsl_script_command($runtime, 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $docker)));
}

function hub_service_runtime_inspection_command(array $service, array $command, ?string $platform = null, ?array $profile = null): ?array
{
    if (hub_service_uses_wsl_runtime($service, $platform, $profile)) {
        $runtime = hub_wsl_service_runtime($service, $platform, $profile);
        if ($runtime === null) {
            return null;
        }

        $script = 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $command));
        return hub_wsl_script_command($runtime, $script);
    }

    $resolution = hub_service_runtime_resolution($service, $platform, $profile);
    return !empty($resolution['supported']) && ($resolution['target'] ?? '') === 'linux-docker'
        ? $command
        : null;
}

function hub_whisper_wsl_runtime_profile(array $service, ?array $profile = null): ?array
{
    if ((string)($service['pack_id'] ?? '') !== 'whisper-asr') {
        return null;
    }
    $resolution = hub_service_runtime_resolution($service, 'windows', $profile);
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
    $pack = hub_get_pack('whisper-asr');
    if ($runtime === null || !is_array($pack['manifest'] ?? null) || !is_array($resolution['profile'] ?? null)) {
        return null;
    }
    $profiles = $pack['manifest']['wsl_runtime_profiles'] ?? null;
    $profileId = (string)($resolution['profile']['pack_profiles']['whisper-asr'] ?? 'default');
    $selected = is_array($profiles) ? ($profiles[$profileId] ?? null) : null;
    $dockerfile = is_array($selected) ? (string)($selected['dockerfile'] ?? '') : '';
    $image = is_array($selected) ? (string)($selected['image'] ?? '') : '';
    if (
        !is_array($selected)
        || ($selected['id'] ?? null) !== $profileId
        || preg_match('~^service/Dockerfile(?:\.[A-Za-z0-9._-]+)?$~', $dockerfile) !== 1
        || preg_match('~^[A-Za-z0-9][A-Za-z0-9._/@:-]{0,254}$~', $image) !== 1
    ) {
        return null;
    }
    $modelsRoot = trim((string)($resolution['profile']['models_root'] ?? ''));
    if ($modelsRoot === '') {
        $modelsRoot = dirname((string)$runtime['runtime_root']) . '/models';
    }
    try {
        $modelsRoot = hub_container_path($modelsRoot);
    } catch (InvalidArgumentException) {
        return null;
    }

    return $runtime + [
        'profile_id' => $profileId,
        'dockerfile' => $dockerfile,
        'image' => $image,
        'models_root' => $modelsRoot,
    ];
}

function hub_whisper_wsl_service_compose_command(array $service, array $args, ?array $profile = null): array
{
    $runtime = hub_whisper_wsl_runtime_profile($service, $profile);
    $pack = hub_get_pack('whisper-asr');
    if ($runtime === null || !is_array($pack['manifest'] ?? null)) {
        throw new RuntimeException('WSL Runtime is not ready for Whisper ASR.');
    }
    $serviceKey = (string)($service['service_key'] ?? '');
    $port = (int)($service['local_port'] ?? 0);
    if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey) !== 1 || $port < 1 || $port > 65535) {
        throw new RuntimeException('Invalid WSL Whisper service configuration.');
    }
    $environment = [];
    $sourceEnvironment = hub_compose_env($service);
    foreach ((array)($pack['manifest']['env'] ?? []) as $item) {
        $key = (string)($item['name'] ?? '');
        $value = (string)($sourceEnvironment[$key] ?? $item['default'] ?? '');
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1 || str_contains($value, "\0") || preg_match('/[\r\n]/', $value) === 1) {
            throw new RuntimeException('Invalid WSL Whisper service environment.');
        }
        $environment[$key] = $value;
    }
    if (($runtime['profile_id'] ?? '') === 'pascal-cu118' && ($environment['WHISPER_COMPUTE_TYPE'] ?? '') === 'auto') {
        $environment['WHISPER_COMPUTE_TYPE'] = 'int8_float32';
    }
    $environment[hub_pack_port_env($pack['manifest'])] = (string)$port;
    $runtimeRoot = (string)$runtime['runtime_root'];
    $packRoot = $runtimeRoot . '/packs/whisper-asr';
    $serviceRoot = $runtimeRoot . '/services/' . $serviceKey;
    $serviceData = $serviceRoot . '/data';
    $cacheRoot = $runtimeRoot . '/cache/whisper';
    $compose = "services:\n  adapter:\n    image: " . json_encode((string)$runtime['image'], JSON_UNESCAPED_SLASHES) . "\n    build:\n      context: " . json_encode($packRoot, JSON_UNESCAPED_SLASHES) . "\n      dockerfile: " . json_encode((string)$runtime['dockerfile'], JSON_UNESCAPED_SLASHES) . "\n    env_file:\n      - " . HUB_RUNTIME_SETTINGS_FILENAME . "\n    environment:\n      NVIDIA_VISIBLE_DEVICES: \"all\"\n      NVIDIA_DRIVER_CAPABILITIES: \"compute,utility\"\n    gpus: all\n    ports:\n      - \"127.0.0.1:" . $port . ":8000\"\n    volumes:\n      - " . json_encode((string)$runtime['models_root'] . '/whisper:/models/whisper', JSON_UNESCAPED_SLASHES) . "\n      - " . json_encode($cacheRoot . ':/cache/whisper', JSON_UNESCAPED_SLASHES) . "\n      - " . json_encode($serviceData . ':/data/service', JSON_UNESCAPED_SLASHES) . "\n    restart: unless-stopped\n";
    $env = '';
    foreach ($environment as $key => $value) {
        $env .= $key . '=' . $value . "\n";
    }
    $composeArgs = array_values($args);
    if (($composeArgs[0] ?? '') === 'build') {
        // Docker Desktop BuildKit executor 無法走此主機已驗證可用的 bridge egress；只讓 WSL Whisper image build 使用 Docker bridge builder。
        $dockerCommand = 'DOCKER_BUILDKIT=0 docker build --tag ' . hub_wsl_shell_literal((string)$runtime['image'])
            . ' --file ' . hub_wsl_shell_literal($packRoot . '/' . (string)$runtime['dockerfile'])
            . ' ' . hub_wsl_shell_literal($packRoot);
    } else {
        $dockerCommand = 'docker compose';
        if (($progressIndex = array_search('--progress=plain', $composeArgs, true)) !== false) {
            unset($composeArgs[$progressIndex]);
            $composeArgs = array_values($composeArgs);
            $dockerCommand .= ' --progress=plain';
        }
        $dockerCommand .= ' --env-file ' . hub_wsl_shell_literal($serviceRoot . '/' . HUB_RUNTIME_SETTINGS_FILENAME)
            . ' -p ' . hub_wsl_shell_literal((string)$service['compose_project']) . ' -f ' . hub_wsl_shell_literal($serviceRoot . '/docker-compose.yml');
        foreach ($composeArgs as $arg) {
            $dockerCommand .= ' ' . hub_wsl_shell_literal((string)$arg);
        }
    }
    $script = "set -eu\n"
        . 'pack_root=' . hub_wsl_shell_literal($packRoot) . "\n"
        . 'service_root=' . hub_wsl_shell_literal($serviceRoot) . "\n"
        . 'models_root=' . hub_wsl_shell_literal((string)$runtime['models_root']) . "\n"
        . 'cache_root=' . hub_wsl_shell_literal($cacheRoot) . "\n"
        . 'service_data=' . hub_wsl_shell_literal($serviceData) . "\n"
        . 'env_payload=' . hub_wsl_shell_literal(base64_encode($env)) . "\n"
        . 'env_sha256=' . hub_wsl_shell_literal(hash('sha256', $env)) . "\n"
        . 'compose_payload=' . hub_wsl_shell_literal(base64_encode($compose)) . "\n"
        . 'if [ ! -f "$pack_root/' . (string)$runtime['dockerfile'] . '" ]; then echo "WSL Whisper source unavailable. Run install.ps1 -Mode WslRuntime first." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0775 "$service_root" "$models_root/whisper" "$cache_root" "$service_data"' . "\n"
        . 'if ! command -v sha256sum >/dev/null 2>&1; then echo "WSL sha256sum is unavailable." >&2; exit 2; fi' . "\n"
        . 'settings_tmp="$service_root/.' . HUB_RUNTIME_SETTINGS_FILENAME . '.$$"' . "\n"
        . 'umask 077; printf %s "$env_payload" | base64 -d > "$settings_tmp"; chmod 0600 "$settings_tmp"' . "\n"
        . 'actual_sha256="$(sha256sum "$settings_tmp" | awk \'{print $1}\')"' . "\n"
        . 'if [ "$actual_sha256" != "$env_sha256" ]; then rm -f -- "$settings_tmp"; echo "Runtime settings SHA256 verification failed." >&2; exit 2; fi' . "\n"
        . 'mv -f -- "$settings_tmp" "$service_root/' . HUB_RUNTIME_SETTINGS_FILENAME . '"' . "\n"
        . 'if [ -e "$service_root/.env" ] || [ -L "$service_root/.env" ]; then if [ -L "$service_root/.env" ] || [ ! -f "$service_root/.env" ]; then echo "Unsafe legacy runtime env file." >&2; exit 2; fi; rm -- "$service_root/.env"; fi' . "\n"
        . 'printf %s "$compose_payload" | base64 -d > "$service_root/docker-compose.yml"' . "\n"
        . $dockerCommand . "\n";

    return hub_wsl_script_command($runtime, $script);
}

function hub_wsl_service_compose_command(array $service, array $args, ?array $profile = null): array
{
    if ((string)($service['pack_id'] ?? '') === 'whisper-asr') {
        return hub_whisper_wsl_service_compose_command($service, $args, $profile);
    }
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
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
    $compose = "services:\n  adapter:\n    image: " . json_encode(hub_service_image_tag($service), JSON_UNESCAPED_SLASHES) . "\n    build:\n      context: " . json_encode($packRoot . '/service', JSON_UNESCAPED_SLASHES) . "\n    env_file:\n      - " . HUB_RUNTIME_SETTINGS_FILENAME . "\n    ports:\n      - \"127.0.0.1:" . $port . ":8000\"\n    restart: unless-stopped\n";
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
    $dockerCommand .= ' --env-file ' . hub_wsl_shell_literal($serviceRoot . '/' . HUB_RUNTIME_SETTINGS_FILENAME)
        . ' -p ' . hub_wsl_shell_literal((string)$service['compose_project']) . ' -f ' . hub_wsl_shell_literal($serviceRoot . '/docker-compose.yml');
    foreach ($composeArgs as $arg) {
        $dockerCommand .= ' ' . hub_wsl_shell_literal((string)$arg);
    }
    $script = "set -eu\n"
        . 'pack_root=' . hub_wsl_shell_literal($packRoot) . "\n"
        . 'service_root=' . hub_wsl_shell_literal($serviceRoot) . "\n"
        . 'env_payload=' . hub_wsl_shell_literal(base64_encode($env)) . "\n"
        . 'env_sha256=' . hub_wsl_shell_literal(hash('sha256', $env)) . "\n"
        . 'compose_payload=' . hub_wsl_shell_literal(base64_encode($compose)) . "\n"
        . 'if [ ! -d "$pack_root/service" ]; then echo "WSL Pack source unavailable: $pack_root/service. Run install.ps1 -Mode WslRuntime first." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0775 "$service_root"' . "\n"
        . 'if ! command -v sha256sum >/dev/null 2>&1; then echo "WSL sha256sum is unavailable." >&2; exit 2; fi' . "\n"
        . 'settings_tmp="$service_root/.' . HUB_RUNTIME_SETTINGS_FILENAME . '.$$"' . "\n"
        . 'umask 077; printf %s "$env_payload" | base64 -d > "$settings_tmp"; chmod 0600 "$settings_tmp"' . "\n"
        . 'actual_sha256="$(sha256sum "$settings_tmp" | awk \'{print $1}\')"' . "\n"
        . 'if [ "$actual_sha256" != "$env_sha256" ]; then rm -f -- "$settings_tmp"; echo "Runtime settings SHA256 verification failed." >&2; exit 2; fi' . "\n"
        . 'mv -f -- "$settings_tmp" "$service_root/' . HUB_RUNTIME_SETTINGS_FILENAME . '"' . "\n"
        . 'if [ -e "$service_root/.env" ] || [ -L "$service_root/.env" ]; then if [ -L "$service_root/.env" ] || [ ! -f "$service_root/.env" ]; then echo "Unsafe legacy runtime env file." >&2; exit 2; fi; rm -- "$service_root/.env"; fi' . "\n"
        . 'printf %s "$compose_payload" | base64 -d > "$service_root/docker-compose.yml"' . "\n"
        . $dockerCommand . "\n";

    return hub_wsl_script_command($runtime, $script);
}

function hub_active_service_compose_project(array $service, ?callable $runner = null): ?string
{
    $configuredProject = trim((string)($service['compose_project'] ?? ''));
    $composeFile = hub_path((string)($service['compose_file'] ?? ''));
    if ($configuredProject === '' || !is_file($composeFile)) {
        return null;
    }

    $runner ??= static fn (array $command, int $timeoutSeconds, array $env): array => hub_run_linux_docker_command($command, $timeoutSeconds, $env);
    $filter = 'label=com.docker.compose.project.config_files=' . $composeFile;
    $containers = $runner([
        'docker',
        'ps',
        '-q',
        '--filter',
        $filter,
    ], 15, hub_docker_command_environment());
    if ((int)$containers['exit_code'] !== 0) {
        return null;
    }

    $containerIds = array_values(array_filter(preg_split('/\R/', trim((string)$containers['stdout'])) ?: []));
    if ($containerIds === []) {
        return null;
    }

    $projects = $runner(array_merge([
        'docker',
        'inspect',
        '-f',
        '{{ index .Config.Labels "com.docker.compose.project" }}',
    ], $containerIds), 15, hub_docker_command_environment());
    if ((int)$projects['exit_code'] !== 0) {
        return null;
    }

    $activeProjects = [];
    foreach (preg_split('/\R/', trim((string)$projects['stdout'])) ?: [] as $project) {
        $project = trim($project);
        if ($project !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $project) === 1) {
            $activeProjects[$project] = true;
        }
    }
    if ($activeProjects === []) {
        return null;
    }
    if (isset($activeProjects[$configuredProject])) {
        return $configuredProject;
    }

    // 舊版安裝可能曾使用不同 project 名稱；僅在唯一候選時安全接管。
    return count($activeProjects) === 1 ? (string)array_key_first($activeProjects) : null;
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
    $script = 'docker image inspect ' . hub_wsl_shell_literal(hub_service_runtime_image_tag($service)) . ' >/dev/null';
    return hub_run_command(hub_wsl_script_command($runtime, $script), 30)['exit_code'] === 0;
}

function hub_run_service_compose_command(PDO $db, ?array $job, array $service, array $args, int $timeoutSeconds, string $stage, int $minProgress, int $maxProgress): array
{
    $service = hub_service_command_contract($db, $service);
    $usesWsl = hub_service_uses_wsl_runtime($service);
    $command = $usesWsl ? hub_wsl_service_compose_command($service, $args) : hub_compose_command($service, $args);
    return hub_run_service_command($db, $job, $command, $timeoutSeconds, hub_docker_command_environment(), $stage, $minProgress, $maxProgress, !$usesWsl);
}

function hub_docker_image_exists(string $image): bool
{
    return hub_run_command(['docker', 'image', 'inspect', $image], 30, hub_docker_command_environment())['exit_code'] === 0;
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
    if (!hub_service_is_internal_task($service) || hub_service_requires_wsl_job_runtime($service)) {
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

function hub_service_build_timeout_sec(array $service): int
{
    return in_array((string)($service['pack_id'] ?? ''), ['image-tools', 'tts-voxcpm2'], true) ? 1800 : 900;
}

function hub_build_service(PDO $db, array $service, ?array $job = null): array
{
    if (!hub_service_is_internal_task($service) || hub_service_requires_wsl_job_runtime($service)) {
        $unsupported = hub_service_runtime_unsupported_result($service);
        if ($unsupported !== null) {
            return $unsupported;
        }
    }

    hub_job_progress($db, $job, 'prepare_service_dir', 5, 'Preparing service runtime.');
    $service = hub_refresh_service_runtime_files($db, $service, false);
    if (hub_service_is_internal_task($service)) {
        $message = hub_service_requires_wsl_job_runtime($service) ? 'WSL runner image verified.' : 'internal_task build no-op';
        $result = hub_internal_task_result($message);
        hub_add_service_log($db, (int)$service['id'], 'build', $result['output'], 0);
        hub_job_progress($db, $job, 'docker_build', 70, $message);
        return $result;
    }
    hub_job_progress($db, $job, 'docker_build', 20, 'Building image: ' . hub_service_runtime_image_tag($service));
    $result = hub_run_service_compose_command($db, $job, $service, ['build', '--progress=plain'], hub_service_build_timeout_sec($service), 'docker_build', 20, 70);
    $summary = $result['exit_code'] === 0
        ? 'Image build completed: ' . hub_service_runtime_image_tag($service)
        : substr(hub_command_error_summary($result), 0, 1000);
    hub_add_service_log($db, (int)$service['id'], 'build', $summary, (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        $db->prepare('UPDATE services SET restart_required = 1, updated_at = :updated_at WHERE id = :id')->execute([
            ':updated_at' => hub_now(),
            ':id' => (int)$service['id'],
        ]);
        hub_job_progress($db, $job, 'docker_build', 70, 'Image build completed.');
    }

    return $result;
}

function hub_refresh_service_runtime_files(PDO $db, array $service, bool $initializeEdgeTtsDemos = true): array
{
    if (empty($service['pack_id']) || empty($service['service_key'])) {
        return $service;
    }

    $env = json_decode((string)($service['environment_json'] ?? ''), true);
    $options = [
        'service_key' => (string)$service['service_key'],
        'name' => (string)$service['name'],
        'mode' => (string)$service['mode'],
        'port_mode' => (string)$service['port_mode'],
        'local_port' => (int)$service['local_port'],
        'environment' => (string)$service['environment'],
        'hot_reload' => (int)$service['hot_reload'] === 1,
        'env' => is_array($env) ? $env : [],
        'idempotent' => true,
    ];
    if (hub_service_uses_wsl_runtime($service)) {
        // WSL Compose 會在 ext4 runtime 建 image，不能先走 Windows 的 direct linux-docker provision。
        $options['provision_runner'] = false;
    }
    if (hub_service_requires_wsl_job_runtime($service)) {
        $options['runner_build_runner'] = static function (array $docker, int $timeoutSeconds) use ($service): array {
            return hub_run_command(hub_wsl_job_runner_build_command($service, $docker), $timeoutSeconds);
        };
    }
    if (hub_service_requires_wsl_job_runtime($service) && (string)($service['pack_id'] ?? '') === 'edge-tts') {
        $options['edge_tts_demo_runner'] = static function (array $docker, int $timeoutSeconds) use ($service): array {
            return hub_run_command(hub_edge_tts_wsl_demo_command($service, $docker), $timeoutSeconds);
        };
        $options['initialize_edge_tts_demos'] = $initializeEdgeTtsDemos;
    }
    hub_install_pack($db, (string)$service['pack_id'], $options);

    return hub_get_service($db, (int)$service['id']) ?: $service;
}

function hub_stop_service(PDO $db, array $service, ?array $job = null): array
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

    $runtimeState = (string)($service['runtime_status'] ?? $service['status'] ?? '');
    if (!hub_service_uses_wsl_runtime($service) && $runtimeState !== 'stopped') {
        $activeProject = hub_active_service_compose_project($service);
        if ($activeProject !== null) {
            $service['active_compose_project'] = $activeProject;
        }
    }

    hub_job_progress($db, $job, 'docker_down', 10, 'Stopping container.');
    $result = hub_run_service_compose_command($db, $job, $service, ['down', '--timeout', '5'], 10, 'docker_down', 10, 80);
    hub_add_service_log($db, (int)$service['id'], 'stop', $result['output'], (int)$result['exit_code']);
    if ($result['exit_code'] === 0) {
        hub_set_service_enabled($db, $service['mode'], false);
        hub_update_service_status($db, (int)$service['id'], 'stopped');
    } else {
        hub_update_service_status($db, (int)$service['id'], 'error');
    }

    return $result;
}

function hub_service_removal_block_reason(PDO $db, array $service, ?int $excludingJobId = null): ?string
{
    if ((string)($service['runtime_status'] ?? $service['status'] ?? '') !== 'stopped') {
        return 'service_not_stopped';
    }

    return hub_service_has_active_command_job($db, (int)$service['id'], $excludingJobId) ? 'service_job_active' : null;
}

function hub_service_generated_runtime_files(PDO $db, array $service): ?array
{
    $serviceKey = (string)($service['service_key'] ?? '');
    if ($serviceKey === '') {
        return null;
    }

    $composeFile = (string)($service['compose_file'] ?? '');
    if ($composeFile !== hub_pack_compose_file($db, $serviceKey)) {
        return null;
    }

    $composePath = hub_path($composeFile);
    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    $runtimeBase = hub_pack_runtime_base_dir($db);
    $realRuntimeBase = realpath($runtimeBase);
    $realRuntimeDir = realpath($runtimeDir);
    if (
        dirname($composePath) !== $runtimeDir
        || $realRuntimeBase === false
        || $realRuntimeDir === false
        || !is_dir($realRuntimeBase)
        || !is_dir($realRuntimeDir)
        || is_link($runtimeDir)
        || hub_storage_paths_equal($realRuntimeDir, $realRuntimeBase)
        || !hub_storage_path_is_within($realRuntimeDir, $realRuntimeBase)
    ) {
        return null;
    }

    $settingsPath = hub_runtime_settings_path($runtimeDir);
    foreach ([$composePath, $settingsPath] as $path) {
        clearstatcache(true, $path);
        $realPath = realpath($path);
        if (
            is_link($path)
            || !is_file($path)
            || $realPath === false
            || !hub_storage_paths_equal(dirname($realPath), $realRuntimeDir)
        ) {
            return null;
        }
    }

    return [$composePath, $settingsPath];
}

function hub_service_generated_runtime_cleanup_files(PDO $db, string $serviceKey): ?array
{
    try {
        $serviceKey = hub_pack_runtime_service_key($serviceKey);
        $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    } catch (InvalidArgumentException|RuntimeException) {
        return null;
    }

    $runtimeBase = hub_pack_runtime_base_dir($db);
    $realRuntimeBase = realpath($runtimeBase);
    $realRuntimeDir = realpath($runtimeDir);
    if (
        $realRuntimeBase === false
        || $realRuntimeDir === false
        || !is_dir($realRuntimeBase)
        || !is_dir($realRuntimeDir)
        || is_link($runtimeDir)
        || hub_storage_paths_equal($realRuntimeDir, $realRuntimeBase)
        || !hub_storage_path_is_within($realRuntimeDir, $realRuntimeBase)
    ) {
        return null;
    }

    $composePath = hub_path(hub_pack_compose_file($db, $serviceKey));
    if (dirname($composePath) !== $runtimeDir) {
        return null;
    }
    $files = [$composePath, hub_runtime_settings_path($runtimeDir)];
    $legacyPath = hub_legacy_runtime_env_path($runtimeDir);
    if (is_link($legacyPath) || file_exists($legacyPath)) {
        $files[] = $legacyPath;
    }
    $verifiedFiles = [];
    foreach ($files as $path) {
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            continue;
        }
        $realPath = realpath($path);
        if (
            is_link($path)
            || !is_file($path)
            || $realPath === false
            || !hub_storage_paths_equal(dirname($realPath), $realRuntimeDir)
        ) {
            return null;
        }
        // 僅將實體解析後仍位於本 service runtime 的普通檔案交給 cleanup。
        $verifiedFiles[] = $realPath;
    }

    return $verifiedFiles;
}

function hub_service_removal_snapshot_matches(array $job, array $service): bool
{
    $args = json_decode((string)($job['args_json'] ?? '{}'), true);
    $expectedUpdatedAt = is_array($args) ? (string)($args['service_updated_at'] ?? '') : '';

    return $expectedUpdatedAt !== '' && hash_equals($expectedUpdatedAt, (string)($service['updated_at'] ?? ''));
}

function hub_command_job_mark_runtime_cleanup_pending(PDO $db, array $job, string $serviceKey): bool
{
    $args = json_decode((string)($job['args_json'] ?? '{}'), true);
    if (!is_array($args)) {
        $args = [];
    }
    $args['runtime_cleanup_pending'] = ['service_key' => $serviceKey];
    $update = $db->prepare('UPDATE command_jobs SET args_json = :args_json, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':args_json' => hub_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ]);

    return $update->rowCount() === 1;
}

function hub_command_job_clear_runtime_cleanup_pending(PDO $db, array $job): void
{
    $args = json_decode((string)($job['args_json'] ?? '{}'), true);
    if (!is_array($args) || !isset($args['runtime_cleanup_pending'])) {
        return;
    }
    unset($args['runtime_cleanup_pending']);
    $update = $db->prepare('UPDATE command_jobs SET args_json = :args_json, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':args_json' => hub_json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ]);
}

function hub_command_job_defer_runtime_cleanup(PDO $db, array $job): void
{
    $update = $db->prepare('UPDATE command_jobs SET updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':updated_at' => hub_now(),
        ':id' => (int)$job['id'],
    ]);
}

function hub_cleanup_removed_service_runtime_files(PDO $db, string $serviceKey): bool
{
    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    try {
        return hub_with_pack_runtime_lock($runtimeDir, static function () use ($db, $serviceKey): bool {
            if (hub_get_service_by_key($db, $serviceKey) !== null) {
                return false;
            }
            $files = hub_service_generated_runtime_cleanup_files($db, $serviceKey);
            if ($files === null) {
                return false;
            }

            $complete = true;
            foreach ($files as $path) {
                clearstatcache(true, $path);
                if (is_file($path) && !@unlink($path)) {
                    $complete = false;
                }
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) {
                    $complete = false;
                }
            }

            return $complete;
        });
    } catch (Throwable) {
        return false;
    }
}

function hub_retry_pending_service_runtime_cleanup(PDO $db, int $limit = 20): void
{
    $jobs = $db->prepare(
        "SELECT * FROM command_jobs
         WHERE action = 'service_remove' AND status = 'success'
           AND args_json LIKE '%\"runtime_cleanup_pending\"%'
         ORDER BY updated_at ASC, id ASC
         LIMIT :limit"
    );
    $jobs->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
    $jobs->execute();

    foreach ($jobs->fetchAll() as $job) {
        try {
            $args = json_decode((string)($job['args_json'] ?? '{}'), true);
            $pending = is_array($args) ? ($args['runtime_cleanup_pending'] ?? null) : null;
            $serviceKey = is_array($pending) ? (string)($pending['service_key'] ?? '') : '';
            if ($serviceKey !== '' && hub_get_service_by_key($db, $serviceKey) !== null) {
                hub_command_job_clear_runtime_cleanup_pending($db, $job);
                continue;
            }
            if (hub_cleanup_removed_service_runtime_files($db, $serviceKey)) {
                hub_command_job_clear_runtime_cleanup_pending($db, $job);
            } elseif ($serviceKey !== '' && hub_get_service_by_key($db, $serviceKey) !== null) {
                hub_command_job_clear_runtime_cleanup_pending($db, $job);
            } else {
                hub_command_job_defer_runtime_cleanup($db, $job);
            }
        } catch (Throwable) {
        }
    }
}

function hub_remove_service(PDO $db, array $service, array $job): array
{
    $jobId = isset($job['id']) ? (int)$job['id'] : null;
    $blockReason = hub_service_removal_block_reason($db, $service, $jobId);
    if ($blockReason !== null) {
        hub_job_progress($db, $job, 'validate_removal', 5, 'Service removal blocked: ' . $blockReason);
        return ['exit_code' => 2, 'stdout' => '', 'stderr' => $blockReason, 'output' => $blockReason, 'error_code' => $blockReason];
    }
    $serviceKey = (string)$service['service_key'];
    if (!hub_service_removal_snapshot_matches($job, $service)) {
        return [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'Service changed since removal was requested.',
            'output' => 'Service changed since removal was requested.',
            'error_code' => 'service_changed',
        ];
    }
    if (hub_service_generated_runtime_files($db, $service) === null) {
        return [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'Service runtime files are not managed.',
            'output' => 'Service runtime files are not managed.',
            'error_code' => 'service_runtime_unmanaged',
        ];
    }

    $runtimeDir = hub_pack_runtime_dir($db, $serviceKey);
    clearstatcache(true, $runtimeDir);
    if (!is_dir($runtimeDir) || is_link($runtimeDir) || !is_writable($runtimeDir)) {
        return [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'Service runtime cleanup is unavailable.',
            'output' => 'Service runtime cleanup is unavailable.',
            'error_code' => 'service_runtime_cleanup_unavailable',
        ];
    }
    try {
        $removal = hub_with_pack_runtime_lock($runtimeDir, static function () use ($db, $service, $job, $serviceKey): array {
            $current = hub_get_service($db, (int)$service['id']);
            if ($current === null) {
                return ['result' => ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Service not found.', 'output' => 'Service not found.']];
            }
            if (!hub_service_removal_snapshot_matches($job, $current)) {
                return ['result' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Service changed since removal was requested.', 'output' => 'Service changed since removal was requested.', 'error_code' => 'service_changed']];
            }
            $runtimeFiles = hub_service_generated_runtime_files($db, $current);
            if ($runtimeFiles === null) {
                return ['result' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Service runtime files are not managed.', 'output' => 'Service runtime files are not managed.', 'error_code' => 'service_runtime_unmanaged']];
            }
            $runtimeDir = dirname($runtimeFiles[0]);
            clearstatcache(true, $runtimeDir);
            if (!is_writable($runtimeDir)) {
                return ['result' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Service runtime cleanup is unavailable.', 'output' => 'Service runtime cleanup is unavailable.', 'error_code' => 'service_runtime_cleanup_unavailable']];
            }

            hub_job_progress($db, $job, 'validate_removal', 5, 'Validating generated runtime files.');
            if (hub_service_runtime_unsupported_result($current) === null) {
                $stop = hub_stop_service($db, $current, $job);
                if ((int)$stop['exit_code'] !== 0) {
                    return ['result' => $stop];
                }
            }
            if (hub_service_generated_runtime_files($db, $current) === null) {
                return ['result' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Service runtime files are not managed.', 'output' => 'Service runtime files are not managed.', 'error_code' => 'service_runtime_unmanaged']];
            }
            if (!hub_command_job_mark_runtime_cleanup_pending($db, $job, $serviceKey)) {
                return ['result' => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Cannot prepare generated runtime cleanup.', 'output' => 'Cannot prepare generated runtime cleanup.', 'error_code' => 'service_runtime_cleanup_prepare_failed']];
            }

            hub_job_progress($db, $job, 'remove_service', 85, 'Removing service registration.');
            $delete = $db->prepare('DELETE FROM services WHERE id = :id');
            $delete->execute([':id' => (int)$current['id']]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Service registration was not removed.');
            }

            return ['removed' => true];
        });
    } catch (Throwable) {
        return [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'Cannot remove service registration.',
            'output' => 'Cannot remove service registration.',
            'error_code' => 'service_remove_failed',
        ];
    }
    if (($removal['removed'] ?? false) !== true) {
        return $removal['result'] ?? [
            'exit_code' => 2,
            'stdout' => '',
            'stderr' => 'Cannot remove service registration.',
            'output' => 'Cannot remove service registration.',
            'error_code' => 'service_remove_failed',
        ];
    }

    hub_job_progress($db, $job, 'remove_runtime_files', 95, 'Removing generated runtime files.');
    $cleanupJob = hub_get_command_job($db, (int)$job['id']);
    if ($cleanupJob === null || !hub_cleanup_removed_service_runtime_files($db, $serviceKey)) {
        try {
            hub_audit($db, 'command_worker', 'service_remove_runtime_cleanup_pending', 'job_id=' . (int)$job['id'] . ' service_key=' . $serviceKey);
        } catch (Throwable) {
        }
        return [
            'exit_code' => 0,
            'stdout' => 'Service removed.',
            'stderr' => 'Service removed; generated runtime cleanup will retry automatically.',
            'output' => 'Service removed. Warning: generated runtime cleanup will retry automatically.',
        ];
    }
    hub_command_job_clear_runtime_cleanup_pending($db, $cleanupJob);

    return ['exit_code' => 0, 'stdout' => 'Service removed.', 'stderr' => '', 'output' => 'Service removed.'];
}

function hub_restart_service(PDO $db, array $service, ?array $job = null): array
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

    $service = hub_refresh_service_runtime_files($db, $service);
    $requiresRecreate = (int)($service['restart_required'] ?? 0) === 1;
    if ($requiresRecreate && !hub_service_image_exists($service)) {
        if (hub_get_storage_setting($db, 'AIHUB_AUTO_BUILD_MISSING_IMAGE') !== '1') {
            return [
                'exit_code' => 4,
                'stdout' => '',
                'stderr' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service),
                'output' => 'Docker image missing. Please build first: ' . hub_service_image_tag($service),
            ];
        }
        $build = hub_build_service($db, $service, $job);
        if ((int)$build['exit_code'] !== 0) {
            return $build;
        }
        $service = hub_get_service($db, (int)$service['id']) ?: $service;
    }

    $args = $requiresRecreate ? ['up', '-d', '--force-recreate'] : ['restart', '--timeout', '5'];
    $stage = $requiresRecreate ? 'docker_recreate' : 'docker_restart';
    $result = hub_run_service_compose_command($db, $job, $service, $args, 20, $stage, 0, 0);
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
