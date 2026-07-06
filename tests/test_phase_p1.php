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
    hub_test_assert(hub_compose_command($service, ['build', '--progress=plain']) === hub_service_build_command($service), 'build command must use plain progress');
    hub_test_assert(!in_array('--build', hub_service_start_command($service), true), 'start command must not rebuild');
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
