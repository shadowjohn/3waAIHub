<?php
declare(strict_types=1);

function hub_test_wsl_script_payload(array $command): string
{
    $script = (string)end($command);
    if (preg_match('/printf %s ([A-Za-z0-9+\\/=]+) \\| base64 -d \\| bash/', $script, $matches) !== 1) {
        throw new RuntimeException('WSL command payload is missing.');
    }
    $value = base64_decode($matches[1], true);
    if ($value === false) {
        throw new RuntimeException('WSL command payload is invalid.');
    }

    return $value;
}

function hub_test_wsl_compose_payload(string $script): string
{
    if (preg_match("/compose_payload='([A-Za-z0-9+\\/=]+)'/", $script, $matches) !== 1) {
        throw new RuntimeException('WSL compose payload is missing.');
    }
    $value = base64_decode($matches[1], true);
    if ($value === false) {
        throw new RuntimeException('WSL compose payload is invalid.');
    }

    return $value;
}

function hub_test_wsl_env_payload(string $script): string
{
    if (preg_match("/env_payload='([A-Za-z0-9+\\/=]+)'/", $script, $matches) !== 1) {
        throw new RuntimeException('WSL environment payload is missing.');
    }
    $value = base64_decode($matches[1], true);
    if ($value === false) {
        throw new RuntimeException('WSL environment payload is invalid.');
    }

    return $value;
}

function hub_test_host_root_child_needle(): string
{
    return rtrim(str_replace('\\', '/', HUB_ROOT), '/') . '/';
}

hub_test('Linux pulls repair unreadable release web source before PHP-FPM serves it', function (): void {
    $hook = (string)file_get_contents(HUB_ROOT . '/.githooks/post-merge');
    $cron = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    $installer = (string)file_get_contents(HUB_ROOT . '/install.sh');

    hub_test_assert(str_contains($hook, 'scripts/fix_permissions.sh --source-only') && str_contains($hook, 'uname -s'), 'post-merge hook must repair Linux web-source permissions');
    hub_test_assert(
        str_contains($cron, 'admin_root="admin"')
        && str_contains($cron, '[ -d public/admin ]')
        && str_contains($cron, 'find app "$admin_root" -type f ! -perm -004'),
        'cron fallback must detect unreadable source in both checkout and release layouts',
    );
    hub_test_assert(str_contains($installer, 'configure_git_hooks'), 'installer must enable versioned Git hooks');
});

hub_test('PhaseRuntime-0 platform and path helpers keep host and container paths separate', function (): void {
    hub_test_assert(hub_platform_id('Linux') === 'linux', 'Linux platform id mismatch');
    hub_test_assert(hub_platform_id('Windows') === 'windows', 'Windows platform id mismatch');
    hub_test_assert(hub_platform_id('Darwin') === 'darwin', 'Darwin platform id mismatch');
    hub_test_assert(hub_platform_id('Plan9') === 'unknown', 'unknown platform id mismatch');

    foreach (['/DATA/models/yolo', 'D:\\DATA\\3waAIHub', 'D:/DATA/3waAIHub', '\\\\server\\share\\models'] as $path) {
        hub_test_assert(hub_is_host_absolute_path($path), 'host absolute path not detected: ' . $path);
    }
    hub_test_assert(!hub_is_host_absolute_path('data/models'), 'relative path must not be absolute');
    hub_test_assert(hub_path('D:\\DATA\\3waAIHub') === 'D:/DATA/3waAIHub', 'Windows drive path must not be joined to HUB_ROOT');
    hub_test_assert(hub_path('\\\\server\\share\\models') === '//server/share/models', 'UNC path must not be joined to HUB_ROOT');

    hub_test_assert(hub_container_path('/models/yolo') === '/models/yolo', 'container path mismatch');
    hub_test_assert(hub_container_path('/output/artifacts') === '/output/artifacts', 'container artifact path mismatch');
    foreach (['D:\\DATA\\models', '\\\\server\\share\\models', '../models', '/models/../etc', 'models/yolo'] as $path) {
        hub_test_assert(hub_test_throws(static fn () => hub_container_path($path)), 'unsafe container path accepted: ' . $path);
    }
});

hub_test('PhaseRuntime-0 pack manifests normalize platform targets once with legacy inference', function (): void {
    $legacy = hub_normalize_pack_manifest([
        'id' => 'legacy-docker',
        'runtime' => ['kind' => 'docker'],
    ]);
    hub_test_assert(($legacy['platform_targets']['linux-docker']['supported'] ?? null) === true, 'legacy docker must infer linux-docker support');
    hub_test_assert(($legacy['platform_targets']['linux-docker']['source'] ?? '') === 'legacy_inferred', 'legacy docker source mismatch');

    $declared = hub_normalize_pack_manifest([
        'id' => 'declared-pack',
        'runtime' => ['kind' => 'docker'],
        'platform_targets' => [
            'linux-docker' => true,
            'remote-agent' => ['supported' => true, 'reason' => 'agent handles execution'],
        ],
    ]);
    hub_test_assert(($declared['platform_targets']['linux-docker']['source'] ?? '') === 'declared', 'declared target source mismatch');
    hub_test_assert(($declared['platform_targets']['remote-agent']['reason'] ?? '') === 'agent handles execution', 'declared reason mismatch');

    $unsupported = hub_platform_target_supported('linux-docker', 'windows');
    hub_test_assert($unsupported['supported'] === false, 'Windows host must not support linux-docker locally');
    hub_test_assert(str_contains((string)$unsupported['reason'], 'not available on Windows host'), 'unsupported reason must be explicit');
});

hub_test('Windows Linux Docker unsupported result keeps the stable machine and stderr contract', function (): void {
    hub_test_assert(defined('HUB_EXIT_UNSUPPORTED') && HUB_EXIT_UNSUPPORTED === 78, 'unsupported exit constant mismatch');
    hub_test_assert(defined('HUB_WINDOWS_LINUX_DOCKER_UNSUPPORTED'), 'unsupported message constant missing');

    $result = hub_unsupported_runtime_result('linux-docker', HUB_WINDOWS_LINUX_DOCKER_UNSUPPORTED);
    hub_test_assert(array_intersect_key($result, array_flip(['exit_code', 'error_code', 'target', 'message', 'retryable'])) === [
        'exit_code' => 78,
        'error_code' => 'platform_target_unsupported',
        'target' => 'linux-docker',
        'message' => 'linux-docker target is not available on Windows host',
        'retryable' => false,
    ], 'unsupported machine contract mismatch');
    hub_test_assert($result['stdout'] === '', 'unsupported stdout must be empty');
    hub_test_assert($result['stderr'] === 'unsupported: linux-docker target is not available on Windows host', 'unsupported stderr mismatch');
    hub_test_assert($result['output'] === $result['stderr'], 'unsupported compatibility output mismatch');
    hub_test_assert(!str_starts_with($result['message'], 'unsupported:'), 'machine message must not include the human prefix');
});

hub_test('runtime target resolution never aliases direct Windows Linux Docker through WSL metadata', function (): void {
    $profile = [
        'schema_version' => '0.1',
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => [
                'supported' => true,
                'support_level' => 'preview',
                'distro' => 'Ubuntu-24.04',
                'provides' => ['linux-docker'],
            ],
        ],
    ];

    $direct = hub_runtime_target_resolution('linux-docker', 'windows', $profile);
    hub_test_assert($direct['supported'] === false, 'direct Windows linux-docker must stay unsupported');
    hub_test_assert($direct['adapter'] === null, 'direct Windows linux-docker must not select a WSL adapter');
    hub_test_assert($direct['reason'] === HUB_WINDOWS_LINUX_DOCKER_UNSUPPORTED, 'direct Windows reason mismatch');

    $wsl = hub_runtime_target_resolution('windows-wsl2-linux-docker', 'windows', $profile);
    hub_test_assert($wsl['supported'] === true, 'exact WSL target readiness must be reported');
    hub_test_assert($wsl['adapter'] === 'windows-wsl2-linux-docker', 'exact WSL adapter metadata mismatch');
    hub_test_assert(($wsl['profile']['distro'] ?? '') === 'Ubuntu-24.04', 'exact WSL profile metadata missing');

    $linux = hub_runtime_target_resolution('linux-docker', 'linux', $profile);
    hub_test_assert($linux['supported'] === true, 'native Linux Docker target must stay supported');
    hub_test_assert($linux['adapter'] === 'native-linux-docker', 'native Linux adapter metadata mismatch');
    hub_test_assert(!function_exists('hub_wrap_wsl_command'), 'Windows-1 must not add WSL command wrapping');
});

hub_test('explicit Pack WSL metadata selects only the declared WSL target', function (): void {
    $profile = [
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => [
                'supported' => true,
                'distro' => 'Ubuntu-24.04',
                'runtime_root' => '/DATA/3waAIHub-runtime',
            ],
        ],
    ];
    $manifest = hub_normalize_pack_manifest([
        'runtime' => ['kind' => 'docker', 'windows_wsl_compose' => true],
        'platform_targets' => [
            'linux-docker' => true,
            'windows-wsl2-linux-docker' => true,
        ],
    ]);

    $wsl = hub_pack_runtime_target_resolution($manifest, 'windows', $profile);
    hub_test_assert($wsl['target'] === 'windows-wsl2-linux-docker' && $wsl['supported'] === true, 'declared Pack WSL target must resolve from readiness profile');

    $direct = hub_pack_runtime_target_resolution([
        'runtime' => ['kind' => 'docker'],
        'platform_targets' => ['linux-docker' => true],
    ], 'windows', $profile);
    hub_test_assert($direct['target'] === 'linux-docker' && $direct['supported'] === false, 'ordinary Docker Packs must stay direct-target blocked on Windows');
});

hub_test('Web Screenshot explicitly selects the WSL job target only when ready', function (): void {
    $profile = [
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => [
                'supported' => true,
                'distro' => 'Ubuntu-24.04',
                'runtime_root' => '/DATA/3waAIHub-runtime',
            ],
        ],
    ];
    $pack = hub_get_pack('web-screenshot');
    hub_test_assert(is_array($pack), 'Web Screenshot Pack must be available');
    $wsl = hub_pack_runtime_target_resolution($pack['manifest'], 'windows', $profile);
    hub_test_assert($wsl['target'] === 'windows-wsl2-linux-docker' && $wsl['supported'] === true, 'Web Screenshot must select its explicit WSL job target');

    $direct = hub_pack_runtime_target_resolution([
        'runtime' => ['kind' => 'internal_task'],
        'platform_targets' => ['linux-docker' => true, 'windows-wsl2-linux-docker' => true],
    ], 'windows', $profile);
    hub_test_assert($direct['target'] === 'linux-docker' && $direct['supported'] === false, 'an internal Pack without windows_wsl_job must remain blocked');
});

hub_test('Hello explicitly selects the WSL compose target only when ready', function (): void {
    $profile = [
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => [
                'supported' => true,
                'distro' => 'Ubuntu-24.04',
                'runtime_root' => '/DATA/3waAIHub-runtime',
            ],
        ],
    ];
    $pack = hub_get_pack('hello');
    hub_test_assert(is_array($pack), 'Hello Pack must be available');
    $resolution = hub_pack_runtime_target_resolution($pack['manifest'], 'windows', $profile);
    hub_test_assert($resolution['target'] === 'windows-wsl2-linux-docker' && $resolution['supported'] === true, 'Hello must use its declared WSL compose target on Windows');
});

hub_test('Edge TTS explicitly selects the WSL job target only when ready', function (): void {
    $profile = [
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => [
                'supported' => true,
                'distro' => 'Ubuntu-24.04',
                'runtime_root' => '/DATA/3waAIHub-runtime',
            ],
        ],
    ];
    $pack = hub_get_pack('edge-tts');
    hub_test_assert(is_array($pack), 'Edge TTS Pack must be available');
    $wsl = hub_pack_runtime_target_resolution($pack['manifest'], 'windows', $profile);
    hub_test_assert($wsl['target'] === 'windows-wsl2-linux-docker' && $wsl['supported'] === true, 'Edge TTS must select its explicit WSL job target');

    $notReady = hub_pack_runtime_target_resolution($pack['manifest'], 'windows', [
        'runtime_targets' => [
            'windows-wsl2-linux-docker' => ['supported' => false, 'reason' => 'WSL Runtime is not ready'],
        ],
    ]);
    hub_test_assert($notReady['target'] === 'windows-wsl2-linux-docker' && $notReady['supported'] === false, 'Edge TTS must report WSL readiness instead of falling through to Linux Docker');
});

hub_test('Edge TTS WSL provisioning uses synced source and preserves its demo egress boundary', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'provision_runner' => false,
        'initialize_edge_tts_demos' => false,
    ])['service'];
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
    ]]];
    $pack = hub_get_pack('edge-tts');
    $contract = is_array($pack) ? hub_pack_container_runner_build_contract($pack['manifest'], $pack['dir']) : null;
    hub_test_assert(is_array($contract)
        && hub_service_requires_wsl_job_runtime($service, 'windows')
        && !hub_service_requires_wsl_job_runtime($service, 'linux'), 'Edge TTS must require WSL only for Windows service lifecycle actions');

    $image = '3waaihub/edge-tts:0.3.0';
    $inspect = hub_wsl_job_runner_build_command($service, ['docker', 'image', 'inspect', '--format', '{{.Id}}', $image], $profile);
    $build = hub_wsl_job_runner_build_command($service, ['docker', 'build', '--tag', $image, '--file', $contract['dockerfile'], $contract['context']], $profile);
    $inspectPayload = hub_test_wsl_script_payload($inspect);
    $buildPayload = hub_test_wsl_script_payload($build);
    hub_test_assert(str_contains($inspectPayload, 'docker image inspect')
        && str_contains($buildPayload, 'docker build')
        && str_contains($buildPayload, "service_root='/DATA/3waAIHub-runtime/packs/edge-tts/service'")
        && str_contains($buildPayload, '--file "$service_root/Dockerfile" "$service_root"')
        && !str_contains($buildPayload, hub_test_host_root_child_needle()), 'Edge TTS WSL image provisioning must use synced WSL Pack source only');
    hub_test_assert(hub_test_throws(static fn (): array => hub_wsl_job_runner_build_command($service, ['docker', 'pull', $image], $profile)), 'Edge TTS WSL builder must reject undeclared Docker commands');

    $parent = dirname(hub_edge_tts_demo_root((string)$service['service_key']));
    if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
        throw new RuntimeException('Cannot create Edge TTS WSL demo staging fixture.');
    }
    $staging = $parent . '/.staging-' . bin2hex(random_bytes(16));
    if (!mkdir($staging, 0700)) {
        throw new RuntimeException('Cannot create Edge TTS WSL demo staging directory.');
    }
    $containerName = 'edge-tts-demo-' . (string)$service['service_key'] . '-' . bin2hex(random_bytes(16));
    try {
        $demo = hub_edge_tts_wsl_demo_command($service, [
            'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
            '--mount', 'type=bind,src=' . $staging . ',dst=/workspace/output',
            '--name', $containerName, '--entrypoint', '/app/edge-tts-entrypoint.sh',
            $image, '/app/generate_demos.py',
        ], $profile);
        $demoPayload = hub_test_wsl_script_payload($demo);
        $cleanupPayload = hub_test_wsl_script_payload(hub_edge_tts_wsl_demo_command(
            $service,
            ['docker', 'container', 'inspect', '--format', '{{json .State}}', $containerName],
            $profile
        ));
        hub_test_assert(str_contains($demoPayload, 'wslpath -a "$windows_staging"')
            && str_contains($demoPayload, '--network bridge')
            && str_contains($demoPayload, '--cap-add NET_ADMIN')
            && str_contains($demoPayload, "demo_root='/DATA/3waAIHub-runtime/demos/edge-tts/edge-tts-main/")
            && str_contains($demoPayload, 'type=bind,src=$demo_root/output,dst=/workspace/output')
            && str_contains($demoPayload, 'copy_demo_file')
            && str_contains($cleanupPayload, "'docker' 'container' 'inspect'")
            && !str_contains($demoPayload, '--gpus'), 'Edge TTS WSL demos must use ext4 output before copying only verified demo files to Windows staging');
    } finally {
        @rmdir($staging);
    }
});

hub_test('Edge TTS WSL task plan copies only declared artifacts and rejects an unready target early', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'provision_runner' => false,
        'initialize_edge_tts_demos' => false,
    ])['service'];
    $pack = hub_get_pack('edge-tts');
    $job = is_array($pack) ? hub_pack_async_job_contract($pack['manifest'], 'synthesize') : null;
    hub_test_assert(is_array($job), 'Edge TTS synthesize job contract is required');
    $workspace = HUB_DATA_DIR . '/results/edge-tts-wsl-plan-' . bin2hex(random_bytes(8));
    foreach (['input', 'output', 'checkpoints'] as $name) {
        if (!mkdir($workspace . '/' . $name, 0700, true)) {
            throw new RuntimeException('Cannot create Edge TTS WSL workspace fixture.');
        }
    }
    if (file_put_contents($workspace . '/input/request.json', "{\"text\":\"WSL Edge TTS\",\"include_subtitles\":true}\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write Edge TTS WSL request fixture.');
    }
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
    ]]];
    $context = [
        'task' => ['id' => 42, 'pack_id' => 'edge-tts', 'job' => 'synthesize', 'input' => ['include_subtitles' => true]],
        'run' => ['run_id' => 'packjob-42-edge0123456789'],
        'workspace' => $workspace,
        'runner' => hub_pack_job_runner_arguments($job['runner'], ['id' => 42], ['run_id' => 'packjob-42-edge0123456789'], $workspace),
    ];
    try {
        $plan = hub_edge_tts_wsl_execution_plan($service, $context, $profile);
        $payload = hub_test_wsl_script_payload($plan['command']);
        hub_test_assert(($plan['container_id'] ?? '') === 'aihub-pack-packjob-42-edge0123456789'
            && str_contains($payload, '/DATA/3waAIHub-runtime/jobs/edge-tts/packjob-42-edge0123456789')
            && str_contains($payload, 'generated_audio.mp3')
            && str_contains($payload, 'synthesis_metadata.json')
            && str_contains($payload, 'subtitle.vtt')
            && str_contains($payload, 'subtitle.srt')
            && str_contains($payload, 'speech_timeline.json')
            && str_contains($payload, "'--network' 'bridge'")
            && str_contains($payload, "'--cap-add' 'NET_ADMIN'")
            && !str_contains($payload, 'cp -a')
            && !str_contains($payload, '--gpus'), 'Edge TTS WSL task must copy only its declared public-egress artifacts');

        $called = false;
        $unready = ['runtime_targets' => ['windows-wsl2-linux-docker' => ['supported' => false]]];
        $result = hub_edge_tts_wsl_executor($service, $context, static function () use (&$called): array {
            $called = true;
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        }, $unready);
        hub_test_assert(($result['exit_code'] ?? null) === HUB_EXIT_UNSUPPORTED
            && ($result['error_code'] ?? '') === 'platform_target_unsupported'
            && !$called, 'an unready WSL target must reject Edge TTS before running Docker');
    } finally {
        hub_test_remove_data_tree($workspace);
    }
});

hub_test('runtime profile loader reads host-local readiness metadata', function (): void {
    $path = sys_get_temp_dir() . '/3waaihub_runtime_profile_' . getmypid() . '.json';
    try {
        file_put_contents($path, json_encode([
            'runtime_targets' => [
                'windows-wsl2-linux-docker' => ['supported' => true, 'distro' => 'Ubuntu-24.04'],
            ],
        ], JSON_UNESCAPED_SLASHES));
        $profile = hub_load_runtime_profile($path);
        hub_test_assert(($profile['runtime_targets']['windows-wsl2-linux-docker']['distro'] ?? '') === 'Ubuntu-24.04', 'runtime profile metadata mismatch');
        hub_test_assert(hub_runtime_profile_path() === HUB_DATA_DIR . '/runtime_profile.json', 'default runtime profile path mismatch');
    } finally {
        @unlink($path);
    }
});

hub_test('Windows WSL Compose Pack tasks stay with the interactive WSL worker', function (): void {
    $db = hub_test_reset_db();
    $whisper = hub_install_pack($db, 'whisper-asr', ['service_key' => 'wsl-task-worker-asr'])['service'];
    $edgeTts = hub_install_pack($db, 'edge-tts', ['service_key' => 'wsl-task-worker-tts'])['service'];

    hub_test_assert(
        hub_service_requires_wsl_task_worker($whisper, 'windows')
        && hub_service_requires_wsl_task_worker($edgeTts, 'windows')
        && !hub_service_requires_wsl_task_worker($whisper, 'linux'),
        'Windows WSL Compose and WSL job Pack tasks must not be claimed by the LocalSystem Core worker'
    );
});

hub_test('WSL Runtime Agent is a systemd service, not a minute-by-minute Windows worker', function (): void {
    $runner = (string)file_get_contents(HUB_ROOT . '/scripts/wsl/aihub-wsl-worker.sh');
    $unit = (string)file_get_contents(HUB_ROOT . '/deploy/systemd/aihub-wsl-worker.service');
    $installer = (string)file_get_contents(HUB_ROOT . '/scripts/windows/install-wsl-task-agent.ps1');

    hub_test_assert(
        str_contains($runner, '--runtime=wsl')
        && str_contains($runner, 'sleep 0.5')
        && str_contains($runner, 'command_worker.php'),
        'WSL agent must own the bounded WSL task and command polling loop'
    );
    hub_test_assert(
        str_contains($unit, 'Restart=on-failure')
        && str_contains($unit, 'WantedBy=multi-user.target')
        && str_contains($installer, 'systemctl enable --now aihub-wsl-worker.service')
        && str_contains($installer, 'LogonTrigger'),
        'WSL agent must be systemd-managed and start only once at Windows user logon'
    );
});

hub_test('Whisper WSL Pascal service uses the explicit CUDA 11.8 compose profile', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'whisper-asr', ['idempotent' => true, 'provision_runner' => false])['service'];
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
        'pack_profiles' => ['whisper-asr' => 'pascal-cu118'],
    ]]];
    $runtime = hub_whisper_wsl_runtime_profile($service, $profile);
    $script = hub_test_wsl_script_payload(hub_wsl_service_compose_command($service, ['build', '--progress=plain'], $profile));
    $compose = hub_test_wsl_compose_payload($script);
    hub_test_assert(($runtime['profile_id'] ?? '') === 'pascal-cu118'
        && str_contains($compose, 'service/Dockerfile.pascal-cu118')
        && str_contains($compose, '3waaihub/whisper-asr:0.1.2-pascal-cu118')
        && str_contains($compose, 'gpus: all')
        && str_contains(hub_test_wsl_env_payload($script), 'WHISPER_COMPUTE_TYPE=int8_float32')
        && str_contains($compose, '/DATA/3waAIHub-runtime/services/asr-main/data:/data/service')
        && str_contains($script, 'command -v sha256sum')
        && str_contains($script, 'settings_tmp="$service_root/.runtime-settings.conf.$$"')
        && str_contains($script, 'sha256sum "$settings_tmp"')
        && str_contains($script, 'chmod 0600 "$settings_tmp"')
        && str_contains($script, 'mv -f -- "$settings_tmp" "$service_root/runtime-settings.conf"')
        && str_contains($script, "DOCKER_BUILDKIT=0 docker build --tag '3waaihub/whisper-asr:0.1.2-pascal-cu118'")
        && str_contains($script, "--file '/DATA/3waAIHub-runtime/packs/whisper-asr/service/Dockerfile.pascal-cu118'")
        && !str_contains($script, hub_test_host_root_child_needle())
        && !str_contains($compose, hub_test_host_root_child_needle()), 'Whisper WSL compose must use only the Pascal image and ext4 runtime roots');
});

hub_test('generic WSL service runtime settings use an atomic SHA-256 verified write', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'hello', ['idempotent' => true, 'provision_runner' => false])['service'];
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
    ]]];
    $script = hub_test_wsl_script_payload(hub_wsl_service_compose_command($service, ['config'], $profile));
    $compose = hub_test_wsl_compose_payload($script);

    hub_test_assert(
        str_contains($compose, "env_file:\n      - runtime-settings.conf\n")
        && str_contains($script, 'env_sha256=')
        && str_contains($script, 'command -v sha256sum')
        && str_contains($script, 'settings_tmp="$service_root/.runtime-settings.conf.$$"')
        && str_contains($script, 'sha256sum "$settings_tmp"')
        && str_contains($script, 'chmod 0600 "$settings_tmp"')
        && str_contains($script, 'mv -f -- "$settings_tmp" "$service_root/runtime-settings.conf"')
        && str_contains($script, 'rm -- "$service_root/.env"'),
        'generic WSL runtime settings must be verified before the legacy file is retired',
    );
});

hub_test('guarded Linux Docker command rejects Windows before invoking the command', function (): void {
    $marker = sys_get_temp_dir() . '/3waaihub_unsupported_marker_' . getmypid();
    @unlink($marker);
    $result = hub_run_linux_docker_command([
        PHP_BINARY,
        '-r',
        'file_put_contents(' . var_export($marker, true) . ', "invoked");',
    ], 10, [], 'windows');

    hub_test_assert($result['exit_code'] === 78, 'guarded command exit mismatch');
    hub_test_assert($result['error_code'] === 'platform_target_unsupported', 'guarded command error code mismatch');
    hub_test_assert(!is_file($marker), 'guarded command must not be invoked on Windows');
    $linuxResult = hub_run_linux_docker_command([
        PHP_BINARY,
        '-r',
        'file_put_contents(' . var_export($marker, true) . ', "invoked");',
    ], 10, [], 'linux');
    hub_test_assert($linuxResult['exit_code'] === 0 && is_file($marker), 'native Linux guarded command must execute unchanged');
    @unlink($marker);
});

hub_test('resident VoxCPM2 request stays loopback-only and portable on Windows', function (): void {
    $requests = [];
    $plan = [
        'service' => ['local_port' => 18108],
        'settings' => ['VOXCPM2_INTERNAL_JOB_TOKEN' => 'test-internal-token'],
        'token_setting' => 'VOXCPM2_INTERNAL_JOB_TOKEN',
    ];
    $response = hub_pack_job_resident_request(
        $plan,
        'POST',
        '/internal/jobs',
        ['run_id' => 'resident-portability-fixture'],
        static function (string $method, string $url, array $headers, ?array $payload) use (&$requests): array {
            $requests[] = compact('method', 'url', 'headers', 'payload');
            return ['status' => 200, 'json' => ['run_id' => $payload['run_id'] ?? null]];
        },
    );
    hub_test_assert(($response['status'] ?? null) === 200 && count($requests) === 1, 'resident request must use the injected HTTP transport exactly once');
    hub_test_assert(
        ($requests[0]['url'] ?? '') === 'http://127.0.0.1:18108/internal/jobs'
        && ($requests[0]['method'] ?? '') === 'POST'
        && ($requests[0]['payload'] ?? []) === ['run_id' => 'resident-portability-fixture'],
        'resident execution must use only its loopback internal jobs endpoint'
    );
    hub_test_assert(in_array('X-AIHub-Internal-Token: test-internal-token', $requests[0]['headers'] ?? [], true), 'resident request must retain internal authentication');

    $marker = sys_get_temp_dir() . '/3waaihub_resident_windows_docker_' . getmypid();
    @unlink($marker);
    $blocked = hub_run_linux_docker_command([
        PHP_BINARY,
        '-r',
        'file_put_contents(' . var_export($marker, true) . ', "invoked");',
    ], 10, [], 'windows');
    hub_test_assert(($blocked['error_code'] ?? '') === 'platform_target_unsupported' && !is_file($marker), 'resident loopback transport must not need a Windows Docker CLI or direct Linux Docker runner');
    @unlink($marker);
});

hub_test('PhaseRuntime-0 portability docs and pack UI expose target source and reason', function (): void {
    $doc = (string)@file_get_contents(HUB_ROOT . '/docs/runtime_portability_guardrails.md');
    hub_test_assert(str_contains($doc, 'Portability Guardrails'), 'portability guardrails doc missing');
    hub_test_assert(str_contains($doc, '新增十個 Pack，不如先保證一個 Job 跑一千次都不會莫名其妙'), 'runtime principle missing');

    $packsPage = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    hub_test_assert(str_contains($packsPage, 'platform_targets'), 'packs UI must expose platform_targets');
    hub_test_assert(str_contains($packsPage, 'legacy inferred'), 'packs UI must expose legacy inferred source');
    hub_test_assert(str_contains($packsPage, 'unsupported reason'), 'packs UI must expose unsupported reason');
});

hub_test('test runner clears isolated task data with canonical host path comparison', function (): void {
    $taskDirectory = HUB_DATA_DIR . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tasks' . DIRECTORY_SEPARATOR . 'windows-canonical-fixture';
    if (!is_dir($taskDirectory) && !mkdir($taskDirectory, 0700, true) && !is_dir($taskDirectory)) {
        throw new RuntimeException('Cannot create isolated task fixture.');
    }
    file_put_contents($taskDirectory . DIRECTORY_SEPARATOR . 'marker.txt', 'fixture', LOCK_EX);

    hub_test_clear_data_root();

    hub_test_assert(!file_exists($taskDirectory), 'test runner must clear its own isolated task directory across Windows separator forms');
});
