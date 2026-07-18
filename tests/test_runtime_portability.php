<?php
declare(strict_types=1);

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

hub_test('PhaseRuntime-0 portability docs and pack UI expose target source and reason', function (): void {
    $doc = (string)@file_get_contents(HUB_ROOT . '/docs/runtime_portability_guardrails.md');
    hub_test_assert(str_contains($doc, 'Portability Guardrails'), 'portability guardrails doc missing');
    hub_test_assert(str_contains($doc, '新增十個 Pack，不如先保證一個 Job 跑一千次都不會莫名其妙'), 'runtime principle missing');

    $packsPage = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    hub_test_assert(str_contains($packsPage, 'platform_targets'), 'packs UI must expose platform_targets');
    hub_test_assert(str_contains($packsPage, 'legacy inferred'), 'packs UI must expose legacy inferred source');
    hub_test_assert(str_contains($packsPage, 'unsupported reason'), 'packs UI must expose unsupported reason');
});
