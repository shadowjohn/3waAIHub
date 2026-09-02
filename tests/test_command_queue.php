<?php
declare(strict_types=1);

hub_test('command worker allowlist includes Docker builder prune only as explicit maintenance action', function (): void {
    hub_test_assert(hub_is_valid_job_action('docker_builder_prune'), 'docker_builder_prune must be allowlisted');
    hub_test_assert(!hub_is_valid_job_action('docker system prune -af'), 'raw Docker commands must stay rejected');
});

hub_test('command runner preserves observed exit code when proc_close returns unknown', function (): void {
    hub_test_assert(hub_process_exit_code(-1, 0) === 0, 'observed successful exit code must win over proc_close -1');
    hub_test_assert(hub_process_exit_code(-1, 7) === 7, 'observed non-zero exit code must win over proc_close -1');
    hub_test_assert(hub_process_exit_code(0, 7) === 0, 'proc_close exit code must win when it is known');
});

hub_test('command runner bypasses cmd.exe on Windows', function (): void {
    hub_test_assert(hub_process_execution_options('Windows') === ['bypass_shell' => true], 'Windows commands must bypass cmd.exe');
    hub_test_assert(hub_process_execution_options('Linux') === [], 'Linux argv execution must not add Windows-only options');
});

hub_test('command runner resolves the PHP executable to its trusted absolute path', function (): void {
    $command = hub_safe_argv(['php', '-v']);

    hub_test_assert($command[0] === PHP_BINARY, 'PHP command must not rely on PATH lookup');
    hub_test_assert(hub_command_path_is_absolute($command[0]), 'resolved PHP command must be absolute');
});

hub_test('command runner accepts the current PHP binary as trusted', function (): void {
    $command = hub_safe_argv([PHP_BINARY, '-v']);

    hub_test_assert($command[0] === PHP_BINARY, 'current PHP binary must remain an approved executable');
});

hub_test('Linux command runner permits only the fixed system Python 3 executable', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }

    hub_test_assert(
        hub_safe_argv(['python3', '/managed/test.py']) === ['/usr/bin/python3', '/managed/test.py'],
        'Python test runners must resolve to the fixed system Python 3 executable',
    );
    hub_test_assert(!hub_valid_argv(['python3', 123]), 'Python argv must remain a list of nonempty strings');
    hub_test_assert(!hub_valid_argv(['python', '-c', 'print(1)']), 'the ambiguous python executable must remain rejected');
    hub_test_assert(
        hub_test_throws(static fn (): array => hub_safe_argv(['/tmp/python3', '-c', 'print(1)'])),
        'an attacker-selected Python 3 path must remain rejected',
    );
    hub_test_assert(
        hub_test_throws(static fn (): array => hub_safe_argv(['perl', '-e', 'print 1'])),
        'unrelated interpreter binaries must remain rejected',
    );
});

hub_test('Windows NVIDIA command resolves either supported driver installation location', function (): void {
    $systemRoot = 'C:\\Windows';
    $programFiles = 'C:\\Program Files';
    $systemPath = $systemRoot . '\\System32\\nvidia-smi.exe';
    $legacyPath = $programFiles . '\\NVIDIA Corporation\\NVSMI\\nvidia-smi.exe';

    hub_test_assert(
        hub_windows_nvidia_smi_path($systemRoot, $programFiles, static fn (string $path): bool => $path === $systemPath) === $systemPath,
        'Windows NVIDIA command must use System32 when the current driver installs it there',
    );
    hub_test_assert(
        hub_windows_nvidia_smi_path($systemRoot, $programFiles, static fn (string $path): bool => $path === $legacyPath) === $legacyPath,
        'Windows NVIDIA command must retain the legacy NVSMI installation fallback',
    );
});

hub_test('Windows FFprobe command resolves to the managed ProgramData location', function (): void {
    $programData = 'C:\\ProgramData';
    $expected = 'C:\\ProgramData\\3waAIHub\\tools\\ffmpeg\\ffprobe.exe';

    hub_test_assert(
        hub_windows_ffprobe_path($programData) === $expected,
        'Windows FFprobe must use the fixed managed media-tool location',
    );
    hub_test_assert(
        hub_trusted_command_path('ffprobe', 'Windows') === hub_windows_ffprobe_path((string)getenv('ProgramData')),
        'Windows FFprobe must not fall back to PATH lookup',
    );
});

hub_test('command runner rejects an untrusted absolute executable path', function (): void {
    hub_test_assert(
        hub_test_throws(static fn (): array => hub_safe_argv(['C:\\untrusted\\docker', 'version'])),
        'command runner must not replace an attacker-selected executable path by basename',
    );
});

hub_test('command runner validates argv and Ollama model references before process launch', function (): void {
    hub_test_assert(hub_valid_argv(['docker', 'system', 'df']), 'fixed argv command must be accepted');
    hub_test_assert(!hub_valid_argv(['docker', "system\0df"]), 'NUL argv argument must be rejected');
    hub_test_assert(!hub_valid_argv(['docker', "system\r\ndf"]), 'control characters must be rejected from argv arguments');
    hub_test_assert(!hub_valid_argv(['cmd.exe', '/c', 'whoami']), 'unapproved executable must be rejected before process launch');
    hub_test_assert(!hub_valid_argv(['docker' => 'system']), 'non-list argv command must be rejected');
    hub_test_assert(
        hub_safe_argv(['docker', 'system', 'df']) === [hub_trusted_command_path('docker'), 'system', 'df'],
        'safe argv boundary must resolve the validated executable to its trusted path',
    );
    hub_test_assert(hub_test_throws(static fn () => hub_safe_argv(['cmd.exe', '/c', 'whoami'])), 'safe argv boundary must reject unapproved commands');
    hub_test_assert(hub_ollama_model_reference('translategemma:12b-it-q4_K_M') === 'translategemma:12b-it-q4_K_M', 'valid Ollama model reference changed');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_reference('--config=/tmp/evil')), 'Ollama option prefix must be rejected');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_reference('model name')), 'Ollama whitespace must be rejected');
});

hub_test('Ollama model pull logs are confined to the managed models log directory', function (): void {
    $path = hub_ollama_model_pull_log_path('translate-main', '20300102_030405');
    $logRoot = realpath(HUB_LOG_DIR . '/models');

    hub_test_assert($logRoot !== false, 'managed models log directory must resolve');
    hub_test_assert(hub_storage_paths_equal(dirname($path), $logRoot), 'Ollama pull log must stay beneath managed models log directory');
    hub_test_assert(basename($path) === 'ollama_pull_translate-main_20300102_030405.log', 'Ollama pull log filename changed');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_pull_log_path('../escape', '20300102_030405')), 'Ollama pull log accepted traversal service key');
    hub_test_assert(hub_test_throws(static fn (): string => hub_ollama_model_pull_log_path('translate-main', '../escape')), 'Ollama pull log accepted invalid timestamp');
});

hub_test('streamed command validation records its error in the managed stderr log', function (): void {
    $db = hub_test_reset_db();
    $jobId = hub_enqueue_command_job($db, 'docker_prune_check', null, [], null, '127.0.0.1');
    $job = hub_prepare_command_job_logs($db, hub_get_command_job($db, $jobId));
    $result = hub_run_command_streamed(
        ['cmd.exe', '/c', 'whoami'],
        5,
        [],
        (string)$job['stdout_path'],
        (string)$job['stderr_path'],
    );

    hub_test_assert((int)$result['exit_code'] === 127, 'invalid command must retain its existing exit contract');
    hub_test_assert(
        str_contains((string)file_get_contents((string)$job['stderr_path']), 'Invalid command.'),
        'invalid command must be recorded in the managed stderr log',
    );
});

hub_test('quiet streamed command reports ongoing liveness before output arrives', function (): void {
    $db = hub_test_reset_db();
    $jobId = hub_enqueue_command_job($db, 'docker_prune_check', null, [], null, '127.0.0.1');
    $job = hub_prepare_command_job_logs($db, hub_get_command_job($db, $jobId));
    $heartbeats = [];

    $result = hub_run_command_streamed(
        [PHP_BINARY, '-r', 'usleep(2200000);'],
        5,
        [],
        (string)$job['stdout_path'],
        (string)$job['stderr_path'],
        null,
        static function (int $elapsedSeconds) use (&$heartbeats): void {
            $heartbeats[] = $elapsedSeconds;
        },
        1,
    );

    hub_test_assert((int)$result['exit_code'] === 0, 'quiet streamed command must complete normally');
    hub_test_assert($heartbeats !== [] && $heartbeats[0] === 0, 'quiet streamed command must report initial liveness before output arrives');
    hub_test_assert(max($heartbeats) >= 1, 'quiet streamed command must report ongoing liveness while it is still running');
});

hub_test('resident reconciliation uses a prepared read before building runtime commands', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/pack_job_runner.php');
    $start = strpos($source, 'function hub_reconcile_resident_job_runs');
    $end = strpos($source, 'function hub_run_pack_job_task', $start === false ? 0 : $start);
    $body = $start === false || $end === false ? '' : substr($source, $start, $end - $start);

    hub_test_assert($body !== '', 'resident reconciliation implementation must remain discoverable');
    hub_test_assert(!str_contains($body, '$db->query('), 'resident reconciliation must not use query() as a database-to-command source');
    hub_test_assert(str_contains($body, '$statement = $db->prepare('), 'resident reconciliation must use a named prepared statement');
    hub_test_assert(str_contains($body, '$statement->execute();'), 'resident reconciliation prepared statement must execute before rows are read');
});

hub_test('service command contract derives runtime fields from the declared Pack', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'hello', ['idempotent' => true, 'provision_runner' => false])['service'];
    $contract = hub_service_command_contract($db, $service);

    hub_test_assert($contract['compose_file'] === hub_pack_compose_file($db, (string)$service['service_key']), 'runtime contract must derive the managed compose path');
    hub_test_assert($contract['compose_project'] === hub_compose_project_for_instance(hub_get_pack('hello')['manifest'], (string)$service['service_key']), 'runtime contract must derive the Pack compose project');

    $tampered = $service;
    $tampered['compose_file'] = 'C:/untrusted/docker-compose.yml';
    hub_test_assert(hub_test_throws(static fn (): array => hub_service_command_contract($db, $tampered)), 'runtime contract must reject a database compose path that is not declared by the Pack');
});

hub_test('Manual Vision runtime actions use isolated WSL one-shots and a credential-free resident API', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'vlm-manual-vision', ['idempotent' => true, 'provision_runner' => false])['service'];
    hub_update_service_settings($db, (int)$service['id'], [
        'MANUAL_VISION_MODEL_REVISION' => str_repeat('a', 40),
        'HF_TOKEN' => 'private-manual-vision-token',
    ]);
    $service = hub_get_service($db, (int)$service['id']);
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
        'pack_profiles' => ['vlm-manual-vision' => 'default'],
    ]]];
    hub_test_assert(is_array($service), 'Manual Vision service fixture missing');
    $residentSettings = hub_generate_service_runtime_settings_for_instance($db, $service);
    hub_test_assert(!str_contains($residentSettings, 'HF_TOKEN=') && !str_contains($residentSettings, 'private-manual-vision-token'), 'resident runtime settings must exclude provision-only tokens');
    foreach (['manual_vision_provision', 'manual_vision_acceptance'] as $action) {
        $jobId = hub_enqueue_command_job($db, $action, (int)$service['id'], [], null, '127.0.0.1');
        $job = hub_get_command_job($db, $jobId);
        hub_test_assert(($job['status'] ?? '') === 'queued' && ($job['action'] ?? '') === $action, 'Manual Vision one-shot must retain normal command-job bookkeeping');
    }
    hub_test_assert(hub_command_job_stale_after_seconds('manual_vision_provision') >= 3600 && hub_command_job_stale_after_seconds('manual_vision_acceptance') >= 1800, 'Manual Vision one-shots must retain long worker leases');

    $decode = static function (array $command): string {
        preg_match('/printf %s ([A-Za-z0-9+\/=]+)/', (string)end($command), $matches);
        return isset($matches[1]) ? (string)base64_decode($matches[1], true) : '';
    };

    $provision = hub_manual_vision_provisioning_plan($db, $service, $profile, 'windows');
    hub_test_assert(is_array($provision), 'Manual Vision provisioning plan must be available for a ready WSL runtime');
    $provisionScript = $decode($provision['command']);
    hub_test_assert(str_contains($provisionScript, 'docker run') && str_contains($provisionScript, '--user 0:0') && str_contains($provisionScript, 'provision.py'), 'Manual Vision provisioning must be an explicit root Docker one-shot');
    hub_test_assert(str_contains($provisionScript, '/DATA/models/manual-vision:/models/manual-vision') && !str_contains($provisionScript, '/models/manual-vision:ro'), 'Manual Vision provisioning must have the writable model mount');
    hub_test_assert(str_contains($provisionScript, 'HF_HUB_OFFLINE=0') && str_contains($provisionScript, 'HF_TOKEN'), 'Manual Vision provisioning must explicitly enable its token-backed download');
    hub_test_assert(!str_contains(implode(' ', $provision['command']), 'private-manual-vision-token'), 'Manual Vision provisioning command/log transport must redact the token');
    hub_test_assert(!str_contains($provisionScript, 'private-manual-vision-token') && !str_contains($provisionScript, 'token_payload'), 'Manual Vision WSL payload must not embed token bytes');
    hub_test_assert(!array_key_exists('token', $provision), 'Manual Vision provisioning plans must not return token bytes');
    hub_test_assert(!str_contains($provisionScript, 'docker compose'), 'Manual Vision provisioning must never invoke the resident compose service');
    hub_test_assert(str_contains($provisionScript, 'mkdir -p') && !str_contains($provisionScript, 'install -d') && !str_contains($provisionScript, 'chmod ') && !str_contains($provisionScript, 'chown '), 'Manual Vision WSL provision preflight must not mutate existing mount ownership or mode');
    $provisionEnvironment = hub_manual_vision_provision_environment('private-manual-vision-token', true, 'WSL_INTEROP/u');
    hub_test_assert(($provisionEnvironment['HF_TOKEN'] ?? '') === 'private-manual-vision-token' && ($provisionEnvironment['WSLENV'] ?? '') === 'WSL_INTEROP/u:HF_TOKEN/w', 'Manual Vision WSL provision must carry the token only through inherited HF_TOKEN and WSLENV');

    $acceptance = hub_manual_vision_acceptance_args($db, $service, $profile, 'windows');
    hub_test_assert(is_array($acceptance), 'Manual Vision acceptance plan must be available for a ready WSL runtime');
    $acceptanceScript = $decode($acceptance['command']);
    hub_test_assert(str_contains($acceptanceScript, 'acceptance.py') && str_contains($acceptanceScript, '--entrypoint /app/entrypoint.sh') && !str_contains($acceptanceScript, '--user 0:0'), 'Manual Vision acceptance must use the resident entrypoint to drop to its unprivileged user');
    hub_test_assert(str_contains($acceptanceScript, '/models/manual-vision:ro') && str_contains($acceptanceScript, '/cache/manual-vision') && str_contains($acceptanceScript, '/data/service'), 'Manual Vision acceptance mounts must keep models read-only and cache/data writable');
    hub_test_assert(str_contains($acceptanceScript, ':/demo:ro') && str_contains($acceptanceScript, 'HF_HUB_OFFLINE=1') && str_contains($acceptanceScript, '--network none --entrypoint /app/entrypoint.sh'), 'Manual Vision acceptance must mount the committed demo read-only and stay offline without network access');
    hub_test_assert(!str_contains($acceptanceScript, 'HF_TOKEN') && !str_contains($acceptanceScript, 'docker compose'), 'Manual Vision acceptance must not receive a token or invoke the resident compose service');
    hub_test_assert(!array_key_exists('token', $acceptance), 'Manual Vision acceptance plans must not read or return a token');
    hub_test_assert(str_contains($acceptanceScript, 'mkdir -p') && !str_contains($acceptanceScript, 'install -d') && !str_contains($acceptanceScript, 'chmod ') && !str_contains($acceptanceScript, 'chown ') && !str_contains($acceptanceScript, 'models_root='), 'Manual Vision WSL acceptance preflight must leave post-container mounts untouched');

    if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $mountRoot = sys_get_temp_dir() . '/3waaihub_manual_vision_mounts_' . bin2hex(random_bytes(8));
        $serviceRoot = $mountRoot . '/service';
        $mounts = [$mountRoot . '/models', $mountRoot . '/cache', $mountRoot . '/data'];
        mkdir($mountRoot, 0755);
        mkdir($serviceRoot, 0700);
        chown($serviceRoot, 10002);
        chgrp($serviceRoot, 10002);
        foreach ($mounts as $mount) {
            mkdir($mount, 0700);
            chown($mount, 10001);
            chgrp($mount, 10001);
            chmod($mount, 0700);
        }
        try {
            $quoted = implode(' ', array_map('escapeshellarg', $mounts));
            $residentRoot = escapeshellarg($serviceRoot);
            $replay = hub_run_command(['bash', '-lc', 'set -eu; setpriv --reuid=10002 --regid=10002 --clear-groups -- bash -c ' . escapeshellarg('mkdir -p ' . $residentRoot . ' ' . $quoted . '; : > ' . $residentRoot . '/settings')], 10);
            hub_test_assert($replay['exit_code'] === 0, 'ordinary WSL worker must retain its service root while safely reusing post-container Manual Vision mounts: ' . $replay['output']);
            foreach ($mounts as $mount) {
                hub_test_assert(fileowner($mount) === 10001 && filegroup($mount) === 10001 && (fileperms($mount) & 0777) === 0700, 're-running Manual Vision preflight must preserve restrictive post-container mount metadata for another worker UID');
            }
            hub_test_assert(is_array(hub_manual_vision_provisioning_plan($db, $service, $profile, 'windows')) && is_array(hub_manual_vision_acceptance_args($db, $service, $profile, 'windows')) && is_array(hub_manual_vision_wsl_service_compose_command($service, ['up', '-d'], $profile)), 'Manual Vision re-run, acceptance, and resident start plans must remain constructible after post-container mount ownership');
        } finally {
            unlink($serviceRoot . '/settings');
            chmod($serviceRoot, 0700);
            rmdir($serviceRoot);
            foreach (array_reverse($mounts) as $mount) {
                chmod($mount, 0700);
                rmdir($mount);
            }
            rmdir($mountRoot);
        }
    }

    $nativeProvision = hub_manual_vision_provisioning_plan($db, $service, null, 'linux');
    $nativeAcceptance = hub_manual_vision_acceptance_args($db, $service, null, 'linux');
    hub_test_assert(is_array($nativeProvision) && is_array($nativeAcceptance), 'Manual Vision must provide native Linux one-shot plans');
    hub_test_assert(str_contains(implode(' ', $nativeProvision['command']), '--user') && str_contains(implode(' ', $nativeProvision['command']), 'provision.py') && !str_contains(implode(' ', $nativeProvision['command']), '/demo'), 'native provision must bypass the entrypoint as root with no demo mount');
    $nativeNetwork = array_search('--network', $nativeAcceptance['command'], true);
    hub_test_assert(str_contains(implode(' ', $nativeAcceptance['command']), 'acceptance.py') && str_contains(implode(' ', $nativeAcceptance['command']), ':/models/manual-vision:ro') && str_contains(implode(' ', $nativeAcceptance['command']), ':/demo:ro') && $nativeNetwork !== false && ($nativeAcceptance['command'][$nativeNetwork + 1] ?? null) === 'none' && !str_contains(implode(' ', $nativeAcceptance['command']), 'HF_TOKEN'), 'native acceptance must stay offline, network-isolated, and mount models/demo read-only');

    $resident = hub_manual_vision_wsl_service_compose_command($service, ['up', '-d'], $profile);
    $residentScript = $decode($resident);
    preg_match("/compose_payload='([^']+)'/", $residentScript, $residentPayload);
    $residentCompose = isset($residentPayload[1]) ? (string)base64_decode($residentPayload[1], true) : '';
    hub_test_assert(str_contains($residentCompose, '/models/manual-vision:/models/manual-vision:ro'), 'Manual Vision resident API must have a read-only model mount');
    hub_test_assert(str_contains($residentCompose, 'HF_HUB_OFFLINE: "1"'), 'Manual Vision resident API must stay offline');
    hub_test_assert(!str_contains($residentScript . $residentCompose, 'HF_TOKEN') && !str_contains($residentScript . $residentCompose, 'provision.py') && !str_contains($residentScript . $residentCompose, 'acceptance.py'), 'normal Manual Vision start must not receive credentials or run lifecycle one-shots');
    hub_test_assert(str_contains($residentScript, 'mkdir -p "$service_root" "$models_root" "$cache_root" "$service_data"') && !str_contains($residentScript, 'install -d -m 0775 "$service_root" "$models_root" "$cache_root" "$service_data"'), 'Manual Vision WSL resident preflight must not mutate existing post-container mount modes');
    hub_test_assert(str_contains($residentCompose, 'context: "/DATA/3waAIHub-runtime/packs/vlm-manual-vision/service"') && str_contains($residentCompose, 'dockerfile: "Dockerfile"'), 'Manual Vision WSL compose build must resolve the actual service Dockerfile once');

    $wrongPack = hub_install_pack($db, 'hello', ['service_key' => 'manual-vision-wrong-pack', 'idempotent' => true, 'provision_runner' => false])['service'];
    hub_test_assert((hub_run_manual_vision_provision_job($db, $wrongPack, [])['stderr'] ?? '') === 'pack_not_supported', 'Manual Vision provision action must reject another Pack');
    hub_test_assert((hub_run_manual_vision_acceptance_job($db, $wrongPack, [])['stderr'] ?? '') === 'pack_not_supported', 'Manual Vision acceptance action must reject another Pack');
});

hub_test('command runner opt-in capture bounds Manual Vision output before redaction', function (): void {
    $token = 'private-manual-vision-token';
    $script = 'echo str_repeat("x", 4096) . ' . var_export($token, true) . ';';
    $captured = hub_run_command([PHP_BINARY, '-r', $script], 10, [], 256);
    hub_test_assert(strlen((string)$captured['stdout']) <= 256 && strlen((string)$captured['output']) <= 256, 'opt-in command capture must retain only a bounded output tail');
    hub_test_assert(str_contains((string)$captured['stdout'], 'output truncated'), 'bounded command capture must mark retained output');
    $redacted = hub_manual_vision_redact_result($captured, $token);
    hub_test_assert(!str_contains((string)$redacted['output'], $token) && str_contains((string)$redacted['output'], '[redacted]'), 'Manual Vision redaction must operate on the bounded captured tail before persistence');

    $boundaryToken = 'secret-boundary-' . str_repeat('Q', 64);
    $rawLimit = hub_manual_vision_provision_capture_limit($boundaryToken);
    hub_test_assert($rawLimit >= 12000 + strlen($boundaryToken) + 64, 'provision raw capture must reserve final output, full token, and truncation margin');
    $marker = "[output truncated; tail retained]\n";
    $boundary = intdiv(strlen($boundaryToken), 2);
    $suffix = str_repeat('z', $rawLimit - strlen($marker) - (strlen($boundaryToken) - $boundary) - 1);
    $crossingScript = 'echo str_repeat("x", 4096) . ' . var_export($boundaryToken, true) . ' . ' . var_export($suffix, true) . ';';
    $crossingCapture = hub_run_command([PHP_BINARY, '-r', $crossingScript], 10, [], $rawLimit);
    $trailingFragment = substr($boundaryToken, $boundary);
    hub_test_assert(str_contains((string)$crossingCapture['stdout'], $trailingFragment), 'raw capture fixture must split the token at its retained-tail boundary');
    $crossingRedacted = hub_manual_vision_redact_result($crossingCapture, $boundaryToken);
    foreach (['stdout', 'output'] as $key) {
        $persisted = (string)$crossingRedacted[$key];
        hub_test_assert(strlen($persisted) <= 12000 && !str_contains($persisted, $boundaryToken) && !str_contains($persisted, substr($boundaryToken, 0, $boundary)) && !str_contains($persisted, $trailingFragment), 'final persisted Manual Vision ' . $key . ' must retain neither a full token nor a truncation fragment');
    }
});

hub_test('Marketplace operator controls queue Manual Vision one-shots only for its service', function (): void {
    $marketplaceSource = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    hub_test_assert(str_contains($marketplaceSource, 'value="provision_manual_vision"') && str_contains($marketplaceSource, 'value="accept_manual_vision"'), 'Marketplace service UI must render both Manual Vision operator controls');
    $db = hub_test_reset_db();
    $manual = hub_install_pack($db, 'vlm-manual-vision', ['idempotent' => true, 'provision_runner' => false])['service'];
    $hello = hub_install_pack($db, 'hello', ['service_key' => 'manual-vision-ui-wrong-pack', 'mode' => 'manual_vision_ui_wrong_pack', 'idempotent' => true, 'provision_runner' => false])['service'];
    $request = static function (array $post): array {
        $script = "define('HUB_TESTING', true);"
            . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'manual-vision-ui'];"
            . "\$_SERVER = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];"
            . '$_POST = ' . var_export($post, true) . ';'
            . 'require ' . var_export(HUB_ROOT . '/admin/marketplace.php', true) . ';';
        $path = tempnam(sys_get_temp_dir(), '3waaihub_manual_vision_ui_');
        if ($path === false || file_put_contents($path, "<?php\n" . $script, LOCK_EX) === false) {
            throw new RuntimeException('Cannot create Manual Vision Marketplace fixture.');
        }
        try {
            return hub_run_command([PHP_BINARY, $path], 30, ['AIHUB_TEST_DB' => (string)getenv('AIHUB_TEST_DB'), 'AIHUB_TEST_DATA_DIR' => (string)getenv('AIHUB_TEST_DATA_DIR')]);
        } finally {
            unlink($path);
        }
    };
    $accepted = $request(['csrf_token' => 'manual-vision-ui', 'service_id' => (string)$manual['id'], 'action' => 'provision_manual_vision']);
    $payload = json_decode((string)$accepted['stdout'], true);
    hub_test_assert($accepted['exit_code'] === 0 && is_array($payload) && !empty($payload['ok']) && ($payload['job']['action'] ?? '') === 'manual_vision_provision', 'Marketplace must queue Manual Vision provision through the authenticated operator action');
    $rejected = $request(['csrf_token' => 'manual-vision-ui', 'service_id' => (string)$hello['id'], 'action' => 'accept_manual_vision']);
    $payload = json_decode((string)$rejected['stdout'], true);
    hub_test_assert($rejected['exit_code'] === 0 && is_array($payload) && empty($payload['ok']), 'Marketplace must reject Manual Vision controls for another Pack');
});

hub_test('command queue admits runtime actions only for runtime-ready Packs', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'vlm-manual-vision', ['idempotent' => true, 'provision_runner' => false])['service'];

    foreach (['service_start', 'service_restart', 'service_install', 'service_build', 'service_rebuild'] as $action) {
        $jobId = hub_enqueue_command_job($db, $action, (int)$service['id'], [], null, '127.0.0.1');
        hub_test_assert($jobId > 0, 'runtime-ready Manual Vision Pack must queue ' . $action);
    }

    $db->prepare('UPDATE services SET pack_id = :pack_id WHERE id = :id')
        ->execute([':pack_id' => 'manual-vision-missing-pack', ':id' => (int)$service['id']]);
    try {
        hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
        hub_test_assert(false, 'missing Pack must not queue a runtime action');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'pack_not_installed', 'missing Pack must use the unavailable Pack error');
    }

    $ready = hub_install_pack($db, 'hello', ['service_key' => 'manual-vision-queue-ready', 'idempotent' => true])['service'];
    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$ready['id'], [], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    hub_test_assert(($job['action'] ?? '') === 'service_start' && (int)($job['service_id'] ?? 0) === (int)$ready['id'], 'ready API Pack runtime action must queue');
});

hub_test('runtime command Pack admission distinguishes unavailable and unready Pack states', function (): void {
    foreach ([null, ['status' => 'error', 'manifest' => ['runtime_ready' => true]]] as $pack) {
        try {
            hub_command_require_ready_runtime_pack($pack);
            hub_test_assert(false, 'missing or invalid Pack must not pass runtime command admission');
        } catch (RuntimeException $e) {
            hub_test_assert($e->getMessage() === 'pack_not_installed', 'missing or invalid Pack must use the unavailable Pack error');
        }
    }

    try {
        hub_command_require_ready_runtime_pack(['status' => 'ok', 'manifest' => ['runtime_ready' => false]]);
        hub_test_assert(false, 'synthetic unready Pack must not pass runtime command admission');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'pack_runtime_not_ready', 'unready Pack must retain the runtime-ready error');
    }
    hub_command_require_ready_runtime_pack(hub_get_pack('hello'));
});

hub_test('command queue recovers stale running jobs without touching active long jobs', function (): void {
    $db = hub_test_reset_db();
    $now = '2030-01-01 12:00:00';
    $staleJobId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $freshJobId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $longJobId = hub_enqueue_command_job($db, 'ollama_model_pull', null, [], null, '127.0.0.1');
    $update = $db->prepare(
        'UPDATE command_jobs
         SET status = :status, lock_token = :lock_token, started_at = :started_at, updated_at = :updated_at
         WHERE id = :id'
    );
    foreach ([
        [$staleJobId, 'stale-lock', '2030-01-01 11:00:00'],
        [$freshJobId, 'fresh-lock', '2030-01-01 11:59:30'],
        [$longJobId, 'long-lock', '2030-01-01 10:00:00'],
    ] as [$jobId, $lockToken, $updatedAt]) {
        $update->execute([
            ':status' => 'running',
            ':lock_token' => $lockToken,
            ':started_at' => $updatedAt,
            ':updated_at' => $updatedAt,
            ':id' => $jobId,
        ]);
    }

    hub_test_assert(hub_recover_stale_command_jobs($db, $now) === 1, 'exactly one stale job must be recovered');

    $stale = hub_get_command_job($db, $staleJobId);
    hub_test_assert($stale['status'] === 'failed', 'stale job must become failed');
    hub_test_assert((int)$stale['exit_code'] === 1, 'stale job exit code mismatch');
    hub_test_assert($stale['error_code'] === 'worker_lost', 'stale job error code mismatch');
    hub_test_assert($stale['error_message'] === 'Command worker lease expired before completion.', 'stale job message mismatch');
    hub_test_assert($stale['finished_at'] === $now, 'stale job finished time mismatch');
    hub_test_assert($stale['lock_token'] === null, 'stale job lock must be released');

    foreach ([[$freshJobId, 'fresh-lock'], [$longJobId, 'long-lock']] as [$jobId, $lockToken]) {
        $job = hub_get_command_job($db, $jobId);
        hub_test_assert($job['status'] === 'running', 'active job must stay running');
        hub_test_assert($job['lock_token'] === $lockToken, 'active job lock must remain intact');
    }
});

hub_test('command queue selector claims an eligible job without consuming an ineligible one', function (): void {
    $db = hub_test_reset_db();
    $firstId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');
    $secondId = hub_enqueue_command_job($db, 'service_start', null, [], null, '127.0.0.1');

    $claimed = hub_claim_next_command_job(
        $db,
        static fn (array $candidate): bool => (int)$candidate['id'] === $secondId
    );

    hub_test_assert((int)($claimed['id'] ?? 0) === $secondId, 'selector must claim the first eligible queued job');
    hub_test_assert(hub_get_command_job($db, $firstId)['status'] === 'queued', 'ineligible job must remain queued for its worker');
    hub_test_assert(hub_get_command_job($db, $secondId)['status'] === 'running', 'eligible job must be claimed');
});

hub_test('command job logs reject stored paths outside the managed job log root', function (): void {
    $db = hub_test_reset_db();
    $jobId = hub_enqueue_command_job($db, 'docker_prune_check', null, [], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    hub_test_assert($job !== null, 'command job missing');

    $job['stdout_path'] = sys_get_temp_dir() . '/3waaihub_unmanaged_job_' . bin2hex(random_bytes(8)) . '.out.log';
    $job['stderr_path'] = sys_get_temp_dir() . '/3waaihub_unmanaged_job_' . bin2hex(random_bytes(8)) . '.err.log';
    hub_test_assert(
        hub_test_throws(static fn (): array => hub_prepare_command_job_logs($db, $job)),
        'stored command log paths outside the Hub job log root must be rejected',
    );
});

hub_test('self-check reads command stdout only through the managed job log boundary', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/scripts/self_check.php');

    hub_test_assert(str_contains($source, "hub_command_job_log_path((string)\$finished['stdout_path'], 'stdout', \$jobId)"), 'self-check must bind stdout reads to the command job log owner');
    hub_test_assert(!str_contains($source, "file_get_contents(\$finished['stdout_path'])"), 'self-check must not read the raw stored stdout path');
});

hub_test('managed file tails read only command logs and task result roots', function (): void {
    $resultDir = HUB_DATA_DIR . DIRECTORY_SEPARATOR . 'results' . DIRECTORY_SEPARATOR . 'tail-fixture';
    if (!is_dir($resultDir) && !mkdir($resultDir, 0700, true) && !is_dir($resultDir)) {
        throw new RuntimeException('Cannot create managed tail fixture.');
    }
    $managedPath = $resultDir . DIRECTORY_SEPARATOR . 'stdout.log';
    $outsidePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . '3waaihub_unmanaged_tail_' . bin2hex(random_bytes(8)) . '.log';
    file_put_contents($managedPath, 'managed-tail', LOCK_EX);
    file_put_contents($outsidePath, 'outside-tail', LOCK_EX);

    hub_test_assert(hub_tail_file($managedPath) === 'managed-tail', 'managed task result tail must remain readable');
    hub_test_assert(hub_tail_file($outsidePath) === '', 'tail helper must reject files outside managed roots');
});

hub_test('database maintenance CLI uses the shared safe JSON encoder', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/scripts/db_maintenance.php');

    hub_test_assert(substr_count($source, 'hub_json_encode(') === 3, 'database maintenance must encode every JSON report through the shared safe boundary');
    hub_test_assert(!str_contains($source, 'echo json_encode('), 'database maintenance must not bypass the shared safe JSON encoder');
});

hub_test('callback worker output uses only its declared delivery and state contracts', function (): void {
    $deliveryId = 'cb_' . str_repeat('a', 64);

    hub_test_assert(hub_callback_worker_output_line(['delivery_id' => $deliveryId, 'state' => 'delivered']) === 'callback ' . $deliveryId . ' state=delivered', 'callback worker must retain valid delivery output');
    hub_test_assert(hub_callback_worker_output_line(['delivery_id' => '<script>', 'state' => "retry\nforged"]) === 'callback invalid state=invalid', 'callback worker must not emit malformed stored values');
});

hub_test('command worker output uses only its declared job action contract', function (): void {
    hub_test_assert(hub_command_worker_output_line(['id' => 17, 'action' => 'service_start'], 0) === 'job 17 service_start exit=0', 'command worker must retain valid job output');
    hub_test_assert(hub_command_worker_output_line(['id' => '<script>', 'action' => "service_start\nforged"], 2) === 'job 0 invalid exit=2', 'command worker must not emit malformed stored values');
});

hub_test('task worker output uses only its declared task and status contracts', function (): void {
    hub_test_assert(hub_task_worker_output_line(['id' => 21, 'task_type' => 'pack_job'], ['status' => 'waiting_gpu']) === 'task 21 pack_job status=waiting_gpu', 'task worker must retain valid task output');
    hub_test_assert(hub_task_worker_output_line(['id' => '<script>', 'task_type' => "pack_job\nforged"], ['status' => "failed\nforged"]) === 'task 0 invalid status=invalid', 'task worker must not emit malformed stored values');
});

hub_test('runtime settings migration output uses only its declared service result contracts', function (): void {
    hub_test_assert(hub_runtime_settings_migration_output_line(['service_key' => 'hello-main', 'outcome' => 'rejected', 'reason' => 'runtime_settings_unsafe']) === 'service_key=hello-main outcome=rejected reason=runtime_settings_unsafe', 'runtime settings migration must retain valid output');
    hub_test_assert(hub_runtime_settings_migration_output_line(['service_key' => '<script>', 'outcome' => "pending\nforged", 'reason' => 'leak']) === 'service_key=invalid outcome=invalid reason=invalid', 'runtime settings migration must not emit malformed stored values');
});

hub_test('command job finish and status preserve unsupported target error code', function (): void {
    $stmt = null;
    try {
        $db = hub_test_reset_db();
        $jobId = hub_enqueue_command_job($db, 'docker_prune_check', null, [], null, '127.0.0.1');
        $job = hub_prepare_command_job_logs($db, hub_get_command_job($db, $jobId));
        hub_test_assert(hub_storage_paths_equal(dirname((string)$job['stdout_path']), HUB_JOB_LOG_DIR), 'unsupported job stdout log must use the managed job log root');
        hub_test_assert(hub_storage_paths_equal(dirname((string)$job['stderr_path']), HUB_JOB_LOG_DIR), 'unsupported job stderr log must use the managed job log root');
        hub_finish_command_job(
            $db,
            $job,
            'failed',
            78,
            '',
            'unsupported: linux-docker target is not available on Windows host',
            'linux-docker target is not available on Windows host',
            'platform_target_unsupported'
        );

        $payload = hub_command_job_status_payload($db, $jobId);
        hub_test_assert($payload['status'] === 'failed', 'unsupported job status must remain failed');
        hub_test_assert($payload['exit_code'] === 78, 'unsupported job exit code mismatch');
        hub_test_assert($payload['error_code'] === 'platform_target_unsupported', 'unsupported job error code must persist');
        hub_test_assert($payload['error_message'] === 'linux-docker target is not available on Windows host', 'unsupported DB message must not include prefix');
    } finally {
        $stmt = null;
    }
});

hub_test('Windows command worker rejects Linux Docker maintenance without invoking Docker', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only command worker integration.');
    }

    $workerRoot = sys_get_temp_dir() . '/3waaihub_worker_gate_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $workerDbPath = $workerRoot . '/worker.sqlite';
    $stmt = null;
    $workerDb = null;
    try {
        mkdir($workerRoot, 0775, true);
        $workerDb = new PDO('sqlite:' . $workerDbPath);
        $workerDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $workerDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        hub_migrate($workerDb);
        $now = hub_now();
        $stmt = $workerDb->prepare(
            'INSERT INTO services
                (name, mode, type, internal_url, health_url, compose_project, compose_file, enabled, status, runtime_status, created_at, updated_at)
             VALUES
                (:name, :mode, :type, :internal_url, :health_url, :compose_project, :compose_file, :enabled, :status, :runtime_status, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':name' => 'Docker Worker Fixture',
            ':mode' => 'docker_worker_fixture',
            ':type' => 'docker',
            ':internal_url' => 'http://127.0.0.1:18100',
            ':health_url' => 'http://127.0.0.1:18100/health',
            ':compose_project' => 'docker-worker-fixture',
            ':compose_file' => 'unused-docker-compose.yml',
            ':enabled' => 0,
            ':status' => 'stopped',
            ':runtime_status' => 'stopped',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $service = hub_get_service_by_mode($workerDb, 'docker_worker_fixture');
        $stmt->execute([
            ':name' => 'Internal Worker Fixture',
            ':mode' => 'internal_worker_fixture',
            ':type' => 'internal_task',
            ':internal_url' => 'internal-task:test',
            ':health_url' => 'internal-task:health',
            ':compose_project' => 'internal-worker-fixture',
            ':compose_file' => 'unused-internal-task-compose.yml',
            ':enabled' => 1,
            ':status' => 'running',
            ':runtime_status' => 'running',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $internalService = hub_get_service_by_mode($workerDb, 'internal_worker_fixture');
        $internalStateBefore = array_intersect_key($internalService, array_flip(['enabled', 'status', 'runtime_status']));
        $removableService = hub_install_pack($workerDb, 'whisper-asr', ['service_key' => 'worker-remove-asr'])['service'];
        $maintenanceJobId = hub_enqueue_command_job($workerDb, 'docker_prune_check', null, [], null, '127.0.0.1');
        $healthJobId = hub_enqueue_command_job($workerDb, 'service_health_check', (int)$service['id'], [], null, '127.0.0.1');
        $restartJobId = hub_enqueue_command_job($workerDb, 'service_restart', (int)$internalService['id'], [], null, '127.0.0.1');
        $logsJobId = hub_enqueue_command_job($workerDb, 'service_logs_collect', (int)$internalService['id'], [], null, '127.0.0.1');
        $removeJobId = hub_enqueue_command_job($workerDb, 'service_remove', (int)$removableService['id'], [], null, '127.0.0.1');
        $result = hub_run_command(
            [PHP_BINARY, HUB_ROOT . '/scripts/command_worker.php', '--limit=5'],
            30,
            ['AIHUB_TEST_DB' => $workerDbPath]
        );

        hub_test_assert($result['exit_code'] === 0, 'command worker process failed: ' . $result['output']);
        hub_test_assert(str_contains($result['stderr'], 'unsupported: linux-docker target is not available on Windows host'), 'command worker must expose the unsupported stderr contract');
        foreach ([$maintenanceJobId, $healthJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert($job['status'] === 'failed', 'unsupported command job must fail');
            hub_test_assert((int)$job['exit_code'] === 78, 'unsupported command job exit mismatch');
            hub_test_assert($job['error_code'] === 'platform_target_unsupported', 'unsupported command job error code mismatch');
            hub_test_assert($job['error_message'] === 'linux-docker target is not available on Windows host', 'unsupported command job DB message mismatch');
        }
        foreach ([$restartJobId, $logsJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert($job['status'] === 'success', 'internal-task command job must succeed');
            hub_test_assert((int)$job['exit_code'] === 0, 'internal-task command job exit mismatch');
            hub_test_assert($job['error_code'] === null, 'internal-task command job error code must remain null');
        }
        $internalStateAfter = hub_get_service($workerDb, (int)$internalService['id']);
        hub_test_assert(array_intersect_key($internalStateAfter, array_flip(['enabled', 'status', 'runtime_status'])) === $internalStateBefore, 'internal-task restart/logs must preserve service state');
        $removeJob = hub_get_command_job($workerDb, $removeJobId);
        hub_test_assert($removeJob['status'] === 'success' && (int)$removeJob['exit_code'] === 0, 'stopped unsupported service removal must bypass the Windows Docker gate');
        hub_test_assert(hub_get_service($workerDb, (int)$removableService['id']) === null, 'stopped unsupported service must be removed by the command worker');
        $internalLogs = $workerDb->query('SELECT action, output FROM service_logs WHERE service_id = ' . (int)$internalService['id'] . ' ORDER BY id')->fetchAll();
        hub_test_assert($internalLogs === [
            ['action' => 'restart', 'output' => 'internal_task restart no-op'],
            ['action' => 'docker_logs', 'output' => 'internal_task logs no-op'],
        ], 'internal-task restart/logs must record explicit no-op service logs');
        foreach ([$maintenanceJobId, $healthJobId, $restartJobId, $logsJobId, $removeJobId] as $jobId) {
            $job = hub_get_command_job($workerDb, $jobId);
            hub_test_assert(hub_storage_paths_equal(dirname((string)$job['stdout_path']), HUB_JOB_LOG_DIR), 'worker stdout log must stay inside the managed job log root');
            hub_test_assert(hub_storage_paths_equal(dirname((string)$job['stderr_path']), HUB_JOB_LOG_DIR), 'worker stderr log must stay inside the managed job log root');
        }
    } finally {
        $stmt = null;
        $workerDb = null;
        foreach ([$workerDbPath, $workerDbPath . '-wal', $workerDbPath . '-shm'] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_dir($workerRoot)) {
            @rmdir($workerRoot);
        }
    }
});

hub_test('process environment merges Windows keys case-insensitively', function (): void {
    $environment = hub_process_environment(
        ['PATH' => 'C:\\override', 'AIHUB_TEST' => 'task4'],
        ['Path' => 'C:\\base', 'SystemRoot' => 'C:\\Windows', 'TEMP' => 'C:\\Temp'],
        'Windows'
    );

    hub_test_assert(is_array($environment), 'environment overrides must produce an environment array');
    $pathKeys = array_values(array_filter(array_keys($environment), static fn (string $key): bool => strcasecmp($key, 'PATH') === 0));
    hub_test_assert($pathKeys === ['PATH'], 'Windows environment must contain exactly one PATH key using override spelling');
    hub_test_assert($environment['PATH'] === 'C:\\override', 'explicit PATH override must win');
    hub_test_assert($environment['SystemRoot'] === 'C:\\Windows', 'inherited SystemRoot must survive');
    hub_test_assert($environment['TEMP'] === 'C:\\Temp', 'inherited TEMP must survive');
    hub_test_assert($environment['AIHUB_TEST'] === 'task4', 'explicit environment value must survive');
    hub_test_assert(hub_process_environment([], ['Path' => 'C:\\base'], 'Windows') === null, 'no overrides must preserve proc_open inheritance');
});

hub_test('command runner passes inherited and explicit environment values to a PHP subprocess', function (): void {
    $sentinelName = 'AIHUB_PARENT_SENTINEL';
    $overrideName = 'AIHUB_CHILD_OVERRIDE';
    $originalSentinel = getenv($sentinelName);
    $originalOverride = getenv($overrideName);

    try {
        putenv($sentinelName . '=inherited-value');
        putenv($overrideName . '=parent-value');
        $code = 'echo getenv(' . var_export($sentinelName, true) . ') . "|" . getenv(' . var_export($overrideName, true) . ');';
        $result = hub_run_command([PHP_BINARY, '-r', $code], 10, [$overrideName => 'override-value']);

        hub_test_assert($result['exit_code'] === 0, 'PHP subprocess must exit successfully: ' . $result['output']);
        hub_test_assert($result['stdout'] === 'inherited-value|override-value', 'subprocess must receive inherited and overridden values');
    } finally {
        putenv($originalSentinel === false ? $sentinelName : $sentinelName . '=' . $originalSentinel);
        putenv($originalOverride === false ? $overrideName : $overrideName . '=' . $originalOverride);
    }
});

hub_test('cron loop runs both command and task workers', function (): void {
    $loop = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    hub_test_assert(str_contains($loop, 'scripts/command_worker.php'), 'cron loop must run command worker');
    hub_test_assert(str_contains($loop, 'scripts/task_worker.php'), 'cron loop must run task worker');
    hub_test_assert(str_contains($loop, 'scripts/collect_host_metrics.php'), 'cron loop must refresh host metrics snapshots');
    hub_test_assert(str_contains($loop, 'scripts/fix_permissions.sh'), 'cron loop must auto-repair runtime permissions when needed');
    hub_test_assert(str_contains($loop, '$RUNTIME_DATA_ROOT/3waaihub.sqlite-wal'), 'cron permission guard must include the external runtime SQLite WAL file');
    hub_test_assert(str_contains($loop, "stat -c '%G'"), 'cron permission guard must detect wrong runtime group');
    hub_test_assert(
        str_contains($loop, 'FACEBOOK_PROFILE_ROOT')
        && str_contains($loop, 'detect_runtime_user')
        && str_contains($loop, 'WEB_USER')
        && str_contains($loop, "stat -c '%U'")
        && str_contains($loop, '! -perm 0700')
        && str_contains($loop, '! -perm 0600')
        && str_contains($loop, '! -links 1'),
        'cron permission guard must detect an inaccessible or non-private Facebook profile tree'
    );
    hub_test_assert(str_contains($loop, 'TASK_WORKER_LIMIT'), 'cron loop must expose task worker limit');
    hub_test_assert(str_contains($loop, 'TASK_WORKER_TICKS="${TASK_WORKER_TICKS:-100}"') && str_contains($loop, 'TASK_WORKER_SLEEP="${TASK_WORKER_SLEEP:-0.5}"'), 'cron task loop must poll at the configured half-second cadence');
    hub_test_assert(str_contains($loop, 'task_worker_pid=$!') && str_contains($loop, 'wait "$task_worker_pid"'), 'cron loop must wait for its background task worker loop');
    hub_test_assert(
        strpos($loop, 'task_worker_pid=$!') < strpos($loop, 'php scripts/command_worker.php'),
        'task worker loop must start before command worker ticks so queued inference does not wait behind command work'
    );
});

hub_test('task claim filter leaves incompatible queued work for its matching worker', function (): void {
    $db = hub_test_reset_db();
    $skippedId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'skip'], null, '127.0.0.1');
    $claimedId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'claim'], null, '127.0.0.1');
    $task = hub_claim_next_task($db, ['demo_task'], static fn (array $candidate): bool => (int)$candidate['id'] === $claimedId);

    hub_test_assert((int)($task['id'] ?? 0) === $claimedId, 'filtered task worker must claim its compatible task');
    hub_test_assert((hub_get_task($db, $skippedId)['status'] ?? null) === 'queued', 'filtered task worker must not claim incompatible queued work');
});

hub_test('permission fixer repairs deployed source readability without touching runtime model', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/scripts/fix_permissions.sh');
    hub_test_assert(str_contains($script, "-path './.git'"), 'permission fixer must skip .git');
    hub_test_assert(str_contains($script, "-path './data'"), 'permission fixer must keep data runtime handling separate');
    hub_test_assert(str_contains($script, '-type d -exec chmod u+rwx,go+rx'), 'permission fixer must make source directories traversable by PHP-FPM');
    hub_test_assert(str_contains($script, '-type f -exec chmod u+rw,go+r'), 'permission fixer must make source files readable by PHP-FPM');
    hub_test_assert(str_contains($script, '-perm -0100 -exec chmod go+rx'), 'permission fixer must preserve executable scripts for non-owner runners');
});

hub_test('permission fixer preserves private Facebook profile modes and web ownership', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile permission repair requires Linux ownership semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_permission_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $profileRoot = $root . '/data/facebook-crawler/profiles';
    $profileDir = $profileRoot . '/fbp_' . str_repeat('a', 48);
    $statePath = $profileDir . '/storage_state.json';
    if (!mkdir($scriptDir, 0700, true) || !mkdir($profileDir, 0775, true)) {
        throw new RuntimeException('Cannot create permission repair fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($statePath, "{}\n");
    chmod($profileRoot, 0775);
    chmod($profileDir, 0775);
    chmod($statePath, 0600);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run permission repair fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $statePath);

        hub_test_assert($exitCode === 0, 'permission repair fixture failed: ' . trim($output));
        hub_test_assert((fileperms($profileRoot) & 0777) === 0700, 'Facebook profile root must remain 0700 after repair');
        hub_test_assert((fileperms($profileDir) & 0777) === 0700, 'Facebook profile directory must remain 0700 after repair');
        hub_test_assert((fileperms($statePath) & 0777) === 0600, 'Facebook storage state must remain 0600 after repair');
        hub_test_assert(fileowner($profileRoot) === posix_geteuid() && fileowner($statePath) === posix_geteuid(), 'private profile tree must remain owned by the runtime user');
        hub_test_assert(is_readable($statePath) && is_writable($statePath) && is_executable($profileDir), 'runtime owner must retain private profile access');

        $source = (string)file_get_contents($scriptDir . '/fix_permissions.sh');
        hub_test_assert(str_contains($source, 'WEB_USER="${WEB_USER:-}"'), 'root repair must support an explicit web owner');
        hub_test_assert(str_contains($source, 'detect_web_user'), 'root repair must conservatively detect the web owner');
        hub_test_assert(str_contains($source, 'Cannot determine a usable web runtime owner'), 'root repair must fail closed without a usable web owner');
        hub_test_assert(str_contains($source, "chown --"), 'root repair must terminate chown option parsing');
        hub_test_assert(str_contains($source, "chgrp --"), 'root repair must terminate chgrp option parsing');
        hub_test_assert(!str_contains($source, 'find "$FACEBOOK_PROFILE_ROOT" -type f -exec chmod'), 'permission repair must never mutate an existing browser-state inode');
    } finally {
        hub_test_remove_data_tree($root);
    }
});

hub_test('permission fixer rejects external Facebook state hardlinks without mutation', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile hardlink repair requires Linux inode semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_hardlink_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $profileDir = $root . '/data/facebook-crawler/profiles/fbp_' . str_repeat('b', 48);
    $statePath = $profileDir . '/storage_state.json';
    $outside = tempnam(sys_get_temp_dir(), 'facebook_state_outside_');
    if ($outside === false || !mkdir($scriptDir, 0700, true) || !mkdir($profileDir, 0700, true)) {
        throw new RuntimeException('Cannot create hardlink permission fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($outside, 'outside-private-state');
    chmod($outside, 0640);
    if (!link($outside, $statePath)) {
        throw new RuntimeException('Cannot create external Facebook state hardlink.');
    }
    $before = lstat($outside);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run hardlink permission fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $outside);
        $after = lstat($outside);

        hub_test_assert($exitCode !== 0, 'permission repair must fail on a multiply linked state file: ' . trim($output));
        hub_test_assert(is_array($before) && is_array($after), 'outside hardlink inode must remain available');
        foreach (['uid', 'gid', 'mode', 'nlink', 'size'] as $field) {
            hub_test_assert($after[$field] === $before[$field], 'outside hardlink ' . $field . ' must remain unchanged');
        }
        hub_test_assert(file_get_contents($outside) === 'outside-private-state', 'outside hardlink content must remain unchanged');
    } finally {
        hub_test_remove_data_tree($root);
        @unlink($outside);
    }
});

hub_test('permission fixer rejects a symlinked Facebook profile parent without external mutation', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip('Facebook profile symlink repair requires Linux link semantics.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_parent_link_fixture_' . bin2hex(random_bytes(16));
    $scriptDir = $root . '/scripts';
    $dataDir = $root . '/data';
    $outside = sys_get_temp_dir() . '/3waaihub_parent_link_outside_' . bin2hex(random_bytes(16));
    $marker = $outside . '/marker.txt';
    if (!mkdir($scriptDir, 0700, true) || !mkdir($dataDir, 0700, true) || !mkdir($outside, 0750, true)) {
        throw new RuntimeException('Cannot create parent symlink permission fixture.');
    }
    copy(HUB_ROOT . '/scripts/fix_permissions.sh', $scriptDir . '/fix_permissions.sh');
    file_put_contents($marker, 'outside-parent-state');
    chmod($marker, 0640);
    if (!symlink($outside, $dataDir . '/facebook-crawler')) {
        throw new RuntimeException('Cannot create Facebook profile parent symlink.');
    }
    $before = lstat($marker);

    try {
        $pipes = [];
        $process = proc_open(
            ['bash', $scriptDir . '/fix_permissions.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run parent symlink permission fixture.');
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        clearstatcache(true, $marker);
        $after = lstat($marker);

        hub_test_assert($exitCode !== 0, 'permission repair must fail on a symlinked profile parent: ' . trim($output));
        hub_test_assert(is_array($before) && $after === $before, 'external parent marker metadata must remain unchanged');
        hub_test_assert(file_get_contents($marker) === 'outside-parent-state', 'external parent marker content must remain unchanged');
    } finally {
        hub_test_remove_data_tree($root);
        hub_test_remove_data_tree($outside);
    }
});
