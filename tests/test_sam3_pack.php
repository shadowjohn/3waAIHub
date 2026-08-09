<?php
declare(strict_types=1);

hub_test('SAM3 Pack declares the fixed SAM 3.1 runtime and job contracts', function (): void {
    $manifest = hub_get_pack('sam3')['manifest'];
    hub_test_assert(($manifest['version'] ?? '') === '0.2.0', 'SAM3 Pack must be version 0.2.0');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'sam3', 'SAM3 must retain its public mode');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/segment/image', 'SAM3 must retain its legacy image gateway');
    hub_test_assert(($manifest['gateway']['max_upload_mb'] ?? 0) === 512, 'SAM3 task uploads must allow the bounded 512 MB video source');

    $jobs = $manifest['async_jobs'] ?? [];
    hub_test_assert(array_column($jobs, 'job') === ['segment_image', 'track_video', 'monitor'], 'SAM3 must declare only its fixed jobs');
    foreach ($jobs as $job) {
        $assetMount = ($job['runner']['asset_mounts'][0] ?? null);
        hub_test_assert(is_array($assetMount), 'SAM3 job must declare a model mount');
        hub_test_assert(in_array('sam3.1_multiplex.pt', $assetMount['required_paths'] ?? [], true), 'SAM3.1 checkpoint must be required');
    }
    $imagePromptEnum = $jobs[0]['input']['request_schema']['prompt_type']['enum'] ?? [];
    hub_test_assert($imagePromptEnum === ['auto', 'points', 'boxes', 'text'], 'Async image jobs must not advertise a guidance upload they cannot receive');
});

hub_test('SAM3 service provisions an internal token and an offline model mount', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-test-main',
        'mode' => 'sam3_test',
        'name' => 'SAM3 Test Main',
        'port_mode' => 'manual',
        'local_port' => 18161,
    ]);

    $settings = hub_list_service_settings($db, (int)$installed['service']['id']);
    $token = $settings['SAM3_INTERNAL_JOB_TOKEN'] ?? [];
    hub_test_assert(($token['is_secret'] ?? 0) === 1, 'SAM3 internal job token must be secret');
    hub_test_assert(preg_match('/^[a-f0-9]{64}$/', (string)($token['value'] ?? '')) === 1, 'SAM3 internal job token must be generated');

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/sam3:/models/sam3:ro'), 'SAM3 model mount must be read-only');
    hub_test_assert(str_contains($env, 'HF_HUB_OFFLINE=1'), 'SAM3 runtime must disable model downloads');
    hub_test_assert(str_contains($env, 'SAM3_INTERNAL_JOB_TOKEN='), 'SAM3 runtime must receive its internal token');
    hub_test_assert(!str_contains($env, 'SAM3_CHECKPOINT='), 'SAM3 runtime must not accept an arbitrary checkpoint');
});

hub_test('SAM3 task operations retain the sam3 public mode', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-task-main',
        'mode' => 'sam3',
        'name' => 'SAM3 Task Main',
        'port_mode' => 'manual',
        'local_port' => 18162,
    ]);

    $image = hub_resolve_sam3_operation_route($db, 'image_task');
    $video = hub_resolve_sam3_operation_route($db, 'video_task');
    hub_test_assert(($image['requested_mode'] ?? '') === 'sam3' && ($image['pack_id'] ?? '') === 'sam3' && ($image['job'] ?? '') === 'segment_image', 'SAM3 image task must retain its public mode and fixed job');
    hub_test_assert(($video['requested_mode'] ?? '') === 'sam3' && ($video['pack_id'] ?? '') === 'sam3' && ($video['job'] ?? '') === 'track_video', 'SAM3 video task must retain its public mode and fixed job');
    hub_test_assert(hub_sam3_operation_from_request(['action_mode' => 'sam3_image']) === 'image', 'SAM3 action_mode alias must preserve image compatibility');
    hub_test_assert(hub_sam3_operation_from_request(['operation' => 'invalid']) === null, 'SAM3 must reject unknown operation names');
});

hub_test('SAM3 gateway dispatches task operations without a second public mode', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-gateway-main',
        'mode' => 'sam3',
        'name' => 'SAM3 Gateway Main',
        'port_mode' => 'manual',
        'local_port' => 18163,
    ]);
    $memberId = hub_create_api_member($db, 'SAM3 Task Owner');
    $token = hub_create_api_token($db, $memberId, 'sam3 task token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'sam3', null);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

    $upload = tempnam(sys_get_temp_dir(), '3waaihub_sam3_');
    if ($upload === false || file_put_contents($upload, 'image') === false) {
        throw new RuntimeException('Cannot create SAM3 upload fixture.');
    }
    $server = $_SERVER;
    $post = $_POST;
    $files = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.51';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api.php?mode=sam3';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . (string)$token['plain_token'];
        $_SERVER['CONTENT_LENGTH'] = '128';
        $_POST = ['operation' => 'image_task', 'prompt_type' => 'auto'];
        $_FILES = ['image' => ['name' => 'sample.png', 'type' => 'image/png', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK, 'size' => filesize($upload)]];
        $response = hub_gateway_dispatch($db, 'sam3');
        $payload = json_decode((string)$response['body'], true);
        hub_test_assert(($response['status'] ?? 0) === 200 && is_array($payload) && !empty($payload['task_id']), 'SAM3 image_task must enqueue through mode=sam3');
        $task = hub_get_task($db, (int)$payload['task_id']);
        hub_test_assert(($task['requested_mode'] ?? '') === 'sam3' && ($task['pack_id'] ?? '') === 'sam3' && ($task['job'] ?? '') === 'segment_image', 'SAM3 task must retain its fixed route snapshot');

        $_POST = ['operation' => 'video_task', 'source_id' => 'sam3src_' . str_repeat('a', 32), 'prompts_json' => '[]'];
        $_FILES = [];
        $sourceResponse = hub_gateway_dispatch($db, 'sam3');
        $sourcePayload = json_decode((string)$sourceResponse['body'], true);
        hub_test_assert(($sourceResponse['status'] ?? 0) === 404 && ($sourcePayload['error'] ?? '') === 'source_not_found', 'SAM3 video source must resolve only an administrator-registered source_id');
        hub_test_assert(!str_contains((string)$sourceResponse['body'], 'rtsp://') && !str_contains((string)$sourceResponse['body'], 'https://'), 'SAM3 source lookup must not disclose a stream URL');
    } finally {
        $_SERVER = $server;
        $_POST = $post;
        $_FILES = $files;
        if (is_file($upload)) {
            unlink($upload);
        }
    }
});

hub_test('SAM3 runtime pins official code and has a token-safe provisioner', function (): void {
    $base = HUB_ROOT . '/packs/sam3';
    $requirements = (string)file_get_contents($base . '/service/requirements.txt');
    $dockerfile = (string)file_get_contents($base . '/service/Dockerfile');
    $provisioner = (string)file_get_contents($base . '/scripts/provision_sam31.sh');

    hub_test_assert(is_file($base . '/service/jobs.py'), 'SAM3 Pack must include its task runner');

    hub_test_assert(str_contains($requirements, 'facebookresearch/sam3.git@96914d2425f90a64f45ca977c2b5165418099543'), 'SAM3 runtime must pin the official source');
    hub_test_assert(!str_contains($requirements, 'ultralytics'), 'SAM3 runtime must not retain Ultralytics');
    hub_test_assert(str_contains($dockerfile, 'nvidia/cuda:12.8'), 'SAM3 runtime must use CUDA 12.8');
    hub_test_assert(str_contains($dockerfile, 'python3.12'), 'SAM3 runtime must use Python 3.12');
    hub_test_assert(str_contains($dockerfile, 'ffmpeg'), 'SAM3 runtime must include FFmpeg');
    hub_test_assert(str_contains($dockerfile, 'jobs.py'), 'SAM3 Dockerfile must copy its task runner');
    $app = (string)file_get_contents($base . '/service/app.py');
    $jobs = (string)file_get_contents($base . '/service/jobs.py');
    foreach (['/internal/capacity', '/internal/jobs', '/internal/jobs/{run_id}', '/internal/jobs/{run_id}/cancel', 'SAM3_INTERNAL_JOB_TOKEN'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'SAM3 resident service protocol must expose ' . $needle);
    }
    hub_test_assert(str_contains($jobs, 'run_monitor_job') && str_contains($jobs, '"monitor"'), 'SAM3 task runner must implement its declared monitor job');
    hub_test_assert(str_contains($provisioner, 'HF_TOKEN'), 'SAM3 provisioner must require a token from its environment');
    hub_test_assert(!str_contains($provisioner, 'set -x'), 'SAM3 provisioner must not echo shell secrets');
    hub_test_assert(!str_contains($provisioner, 'echo "$HF_TOKEN"'), 'SAM3 provisioner must not print the token');
});
