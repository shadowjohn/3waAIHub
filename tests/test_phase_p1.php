<?php
declare(strict_types=1);

hub_test('PhaseP-1 command jobs keep progress metadata and status payload tails logs', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');

    $jobId = hub_enqueue_command_job($db, 'service_build', (int)$service['id'], ['reason' => 'test'], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    hub_test_assert((int)$job['progress'] === 0, 'queued job progress must default to 0');
    hub_test_assert($job['stage'] === 'queued', 'queued job stage must default to queued');

    hub_update_command_job_progress($db, $jobId, 'docker_build', 42, 'Installing Python requirements');
    $job = hub_get_command_job($db, $jobId);
    hub_prepare_command_job_logs($db, $job);
    $job = hub_get_command_job($db, $jobId);
    file_put_contents((string)$job['stdout_path'], "line 1\nline 2\n");
    file_put_contents((string)$job['stderr_path'], "warn 1\n");

    $payload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert($payload['status'] === 'queued', 'payload status mismatch');
    hub_test_assert($payload['progress'] === 42, 'payload progress mismatch');
    hub_test_assert($payload['stage'] === 'docker_build', 'payload stage mismatch');
    hub_test_assert($payload['current_message'] === 'Installing Python requirements', 'payload message mismatch');
    hub_test_assert(str_contains($payload['stdout_tail'], 'line 2'), 'stdout tail missing');
    hub_test_assert(str_contains($payload['stderr_tail'], 'warn 1'), 'stderr tail missing');
});

hub_test('PhaseP-1 generated compose has fixed image tag and start/build commands are split', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-main',
        'name' => 'PP-OCRv5 OCR Main',
        'mode' => 'ocr',
        'port_mode' => 'auto',
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    $compose = (string)file_get_contents(hub_path($service['compose_file']));

    hub_test_assert(str_contains($compose, 'image: 3waaihub-ocr-main:0.1.0'), 'generated compose must include fixed image tag');
    hub_test_assert(hub_service_image_tag($service) === '3waaihub-ocr-main:0.1.0', 'service image tag mismatch');
    $asr = hub_install_pack($db, 'whisper-asr', ['service_key' => 'asr-main'])['service'];
    hub_test_assert(hub_service_image_tag($asr) === '3waaihub/whisper-asr:0.1.1', 'Whisper image checks must match its generated compose image');
    hub_test_assert(hub_compose_command($service, ['build', '--progress=plain']) === hub_service_build_command($service), 'build command must use plain progress');
    hub_test_assert(!in_array('--build', hub_service_start_command($service), true), 'start command must not rebuild');
});

hub_test('PhaseP-1 Compose host commands do not inherit container-only HOME', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-host-env']);
    $service = $installed['service'];

    hub_test_assert((hub_compose_env($service)['HOME'] ?? null) === '/cache/voxcpm2/home', 'TTS container HOME must remain in its generated environment');
    $hostEnvironment = hub_docker_command_environment();
    hub_test_assert(($hostEnvironment['HOME'] ?? null) === HUB_DATA_DIR . '/docker-cli', 'Docker host command must use the Hub-controlled CLI home');
    hub_test_assert(($hostEnvironment['DOCKER_CONFIG'] ?? null) === HUB_DATA_DIR . '/docker-cli', 'Docker host command must keep config outside the container HOME');
});

hub_test('PhaseP-1 default setting auto-builds missing images', function (): void {
    $db = hub_test_reset_db();

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_AUTO_BUILD_MISSING_IMAGE') === '1', 'auto build missing image default must be enabled');
    hub_test_assert(hub_is_valid_job_action('service_build'), 'service_build must be allowlisted');
});

hub_test('PhaseP-1 hello compose keeps legacy service name to avoid orphan conflict', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_key($db, 'hello-main');
    hub_test_assert($service !== null, 'hello-main missing');
    $compose = (string)file_get_contents(hub_path($service['compose_file']));

    hub_test_assert(str_contains($compose, "\n  hello:\n"), 'hello-main compose service must remain hello');
});

hub_test('Windows service build and start reject Linux Docker before runtime and port side effects', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only service side-effect contract.');
    }

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-windows-gate',
        'name' => 'OCR Windows Gate',
        'mode' => 'ocr_windows_gate',
        'port_mode' => 'auto',
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    $db->exec('UPDATE services SET local_port = NULL WHERE id = ' . (int)$service['id']);
    $service = hub_get_service($db, (int)$service['id']);
    $composePath = hub_path((string)$service['compose_file']);
    $composeBefore = "# unsupported gate marker\n";
    file_put_contents($composePath, $composeBefore);
    $buildJobId = hub_enqueue_command_job($db, 'service_build', (int)$service['id'], [], null, '127.0.0.1');
    $startJobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');

    foreach ([
        hub_build_service($db, $service, hub_get_command_job($db, $buildJobId)),
        hub_start_service_with_job($db, $service, hub_get_command_job($db, $startJobId)),
    ] as $result) {
        hub_test_assert($result['exit_code'] === 78, 'Windows service action must return unsupported exit 78');
        hub_test_assert($result['error_code'] === 'platform_target_unsupported', 'Windows service action error code mismatch');
    }

    $after = hub_get_service($db, (int)$service['id']);
    hub_test_assert($after['local_port'] === null, 'unsupported service start must not allocate a port');
    hub_test_assert((string)file_get_contents($composePath) === $composeBefore, 'unsupported service action must not rewrite compose');
    hub_test_assert(hub_get_command_job($db, $buildJobId)['stage'] === 'queued', 'unsupported build must not claim preparation progress');
    hub_test_assert(hub_get_command_job($db, $startJobId)['stage'] === 'queued', 'unsupported start must not claim preparation progress');
});

hub_test('Windows direct service status stop restart and logs reject before Docker', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only direct service gate contract.');
    }

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    foreach ([
        hub_refresh_service_status($db, $service),
        hub_stop_service($db, $service),
        hub_restart_service($db, $service),
        hub_tail_service_logs($db, $service),
    ] as $result) {
        hub_test_assert(is_array($result), 'unsupported direct service action must return a result contract');
        hub_test_assert($result['exit_code'] === 78, 'unsupported direct service action exit mismatch');
        hub_test_assert($result['error_code'] === 'platform_target_unsupported', 'unsupported direct service action error code mismatch');
    }
});

hub_test('PhaseP-1 service removal action and active-job guard are explicit', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_test_assert(hub_is_valid_job_action('service_remove'), 'service_remove must be allowlisted');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === false, 'fresh service must be idle');
    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === true, 'queued command must make service busy');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id'], $jobId) === false, 'current removal job must be excludable from its own busy check');
});
