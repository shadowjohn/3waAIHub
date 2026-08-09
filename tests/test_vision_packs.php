<?php
declare(strict_types=1);

hub_test('YOLO and SAM3 packs have runnable adapter files', function (): void {
    foreach (['yolo' => '/detect/image', 'sam3' => '/segment/image'] as $packId => $path) {
        $base = HUB_ROOT . '/packs/' . $packId . '/service';
        foreach (['Dockerfile', 'requirements.txt', 'app.py'] as $file) {
            hub_test_assert(is_file($base . '/' . $file), $packId . ' service missing ' . $file);
        }
        $app = (string)file_get_contents($base . '/app.py');
        hub_test_assert(str_contains($app, '@app.post("' . $path), $packId . ' adapter endpoint mismatch');
        if ($packId === 'yolo') {
            $requirements = (string)file_get_contents($base . '/requirements.txt');
            $dockerfile = (string)file_get_contents($base . '/Dockerfile');
            hub_test_assert(is_file($base . '/smoke.py'), 'yolo service missing smoke.py');
            hub_test_assert(str_contains($requirements, 'ultralytics'), 'yolo requirements must include ultralytics');
            hub_test_assert(str_contains($requirements, 'pi-heif'), 'yolo requirements must include pi-heif for Ultralytics image loading');
            hub_test_assert(str_contains($dockerfile, 'python3 /app/smoke.py') || str_contains($dockerfile, 'python3 smoke.py'), 'yolo Dockerfile must run smoke.py at build time');
            hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'yolo app must expose L5 runtime_level');
            hub_test_assert(str_contains($app, '"storage"'), 'yolo health must report storage');
            hub_test_assert(str_contains($app, 'YOLO_REAL_INFERENCE'), 'yolo app must keep real inference toggle');
            hub_test_assert(str_contains($app, 'YOLO('), 'yolo app must initialize model for real inference');
            hub_test_assert(str_contains($app, 'predict'), 'yolo app must run predict for real inference');
            hub_test_assert(str_contains($app, '"detections"'), 'yolo detect endpoint must return detections');

            $smoke = (string)file_get_contents($base . '/smoke.py');
            hub_test_assert(str_contains($smoke, 'ultralytics'), 'yolo smoke.py must import ultralytics');
            hub_test_assert(str_contains($smoke, 'pi_heif'), 'yolo smoke.py must import pi_heif');
            hub_test_assert(str_contains($smoke, 'fastapi'), 'yolo smoke.py must import fastapi');
            foreach (['YOLO(', 'predict', 'download'] as $needle) {
                hub_test_assert(!str_contains($smoke, $needle), 'yolo smoke.py must not initialize model or detect: ' . $needle);
            }

            foreach (['storage_smoke.py', 'model_smoke.py', 'inference_smoke.py'] as $file) {
                hub_test_assert(is_file($base . '/' . $file), 'yolo service missing ' . $file);
            }
            $storageSmoke = (string)file_get_contents($base . '/storage_smoke.py');
            foreach (['/models/yolo', '/cache/yolo', '/cache/yolo/ultralytics', '/data/service'] as $needle) {
                hub_test_assert(str_contains($storageSmoke, $needle), 'yolo storage_smoke.py missing ' . $needle);
            }
            foreach (['YOLO(', 'predict', 'download', 'inference'] as $needle) {
                hub_test_assert(!str_contains($storageSmoke, $needle), 'yolo storage_smoke.py must not initialize model or detect: ' . $needle);
            }
            $modelSmoke = (string)file_get_contents($base . '/model_smoke.py');
            foreach (['YOLO(', 'YOLO_MODEL', 'HOME', 'XDG_CACHE_HOME', 'ULTRALYTICS_SETTINGS_DIR', '/models/yolo', '/cache/yolo'] as $needle) {
                hub_test_assert(str_contains($modelSmoke, $needle), 'yolo model_smoke.py missing ' . $needle);
            }
            foreach (['.predict(', 'real_inference', '/detect/image'] as $needle) {
                hub_test_assert(!str_contains($modelSmoke, $needle), 'yolo model_smoke.py must not run detection: ' . $needle);
            }
            hub_test_assert(str_contains($dockerfile, '/tmp/ultralytics'), 'yolo Dockerfile build smoke must use temp Ultralytics dir');
            hub_test_assert(!str_contains($dockerfile, 'chmod -R 777 /cache/yolo'), 'yolo Dockerfile must not chmod runtime cache path');
            hub_test_assert(!str_contains($dockerfile, 'RUN python3 model_smoke.py'), 'yolo Dockerfile must not run model_smoke.py at build time');
        } else {
            $manifest = hub_get_pack('sam3')['manifest'];
            $requirements = (string)file_get_contents($base . '/requirements.txt');
            $dockerfile = (string)file_get_contents($base . '/Dockerfile');
            hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'sam3 runtime_level must be L5-benchmark-ready');
            hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'sam3 target_level must be L5-benchmark-ready');
            hub_test_assert(($manifest['category'] ?? '') === 'vision', 'sam3 category must be vision');
            hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/segment/image', 'sam3 gateway endpoint mismatch');
            foreach (['model_not_present', 'model_load_failed', 'runtime_dependency_missing', 'bad_image', 'invalid_prompt', 'invalid_output_format', 'invalid_guidance_mask', 'guidance_mask_size_mismatch', 'no_positive_guidance', 'polygon_extract_failed', 'rle_encode_failed', 'gpu_unavailable', 'inference_failed', 'inference_timeout'] as $errorCode) {
                hub_test_assert(in_array($errorCode, $manifest['error_codes'] ?? [], true), 'sam3 error_codes missing ' . $errorCode);
            }
            hub_test_assert(is_file($base . '/smoke.py'), 'sam3 service missing smoke.py');
            hub_test_assert(is_file($base . '/storage_smoke.py'), 'sam3 service missing storage_smoke.py');
            hub_test_assert(is_file($base . '/model_smoke.py'), 'sam3 service missing model_smoke.py');
            hub_test_assert(is_file($base . '/inference_smoke.py'), 'sam3 service missing inference_smoke.py');
            hub_test_assert(is_file($base . '/geometry.py'), 'sam3 service missing geometry.py');
            hub_test_assert(is_file($base . '/geometry_smoke.py'), 'sam3 service missing geometry_smoke.py');
            hub_test_assert(is_file($base . '/sam31.py'), 'sam3 service missing official SAM 3.1 adapter');
            hub_test_assert(is_file($base . '/test_sam31.py'), 'sam3 service missing adapter test');
            foreach (['fastapi', 'python-multipart', 'pillow', 'numpy', 'requests', 'huggingface-hub', 'opencv-python-headless'] as $needle) {
                hub_test_assert(str_contains($requirements, $needle), 'sam3 requirements missing ' . $needle);
            }
            hub_test_assert(str_contains($requirements, 'facebookresearch/sam3.git@96914d2425f90a64f45ca977c2b5165418099543'), 'sam3 runtime must pin official SAM source');
            hub_test_assert(!str_contains($requirements, 'ultralytics'), 'sam3 runtime must not include Ultralytics');
            hub_test_assert(str_contains($dockerfile, 'python3 /app/smoke.py'), 'sam3 Dockerfile must run smoke.py at build time');
            hub_test_assert(str_contains($dockerfile, 'inference_smoke.py'), 'sam3 Dockerfile must copy inference_smoke.py');
            hub_test_assert(str_contains($dockerfile, 'sam31.py'), 'sam3 Dockerfile must copy the official adapter');
            hub_test_assert(str_contains($dockerfile, '/tmp/home'), 'sam3 Dockerfile build smoke must use temp HOME');
            hub_test_assert(str_contains($dockerfile, 'nvidia/cuda:12.8'), 'sam3 Dockerfile must use CUDA 12.8');
            hub_test_assert(str_contains($dockerfile, 'python3.12'), 'sam3 Dockerfile must use Python 3.12');
            hub_test_assert(str_contains($dockerfile, 'ffmpeg'), 'sam3 Dockerfile must include FFmpeg');
            hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'sam3 app must expose L5 runtime_level');
            hub_test_assert(str_contains($app, '"storage"'), 'sam3 health must report storage');
            hub_test_assert(str_contains($app, '"model": model'), 'sam3 health must report model status');
            hub_test_assert(str_contains($app, '"runtime": runtime'), 'sam3 health must report runtime status');
            hub_test_assert(str_contains($app, 'dependency_available'), 'sam3 health must report dependency availability');
            hub_test_assert(str_contains($app, 'model_not_present'), 'sam3 health must warn when model is missing');
            hub_test_assert(!str_contains($app, 'runtime_not_ready'), 'sam3 L5 real inference must not return runtime_not_ready');
            foreach (['run_sam3', 'resident_sam3_loader', 'segment_single_image', '_MODEL_CACHE', '_SAM_LOCK'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 L5 app missing real inference path: ' . $needle);
            }
            foreach (['ultralytics', 'SAM3SemanticPredictor', 'SAM(', '.predict('] as $needle) {
                hub_test_assert(!str_contains($app, $needle), 'sam3 app must use only the official SAM 3.1 adapter: ' . $needle);
            }
            foreach (['output_format', 'invalid_output_format', 'polygon_from_mask', 'polygons_from_mask', 'rle_from_mask'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 L5.1 app missing geometry output path: ' . $needle);
            }
            foreach (['image/png', 'merged_mask_png', 'labels must be 0 or 1', 'at least one positive label', 'labels = [1] * len(normalized)', 'mock_points'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 practical mask output missing ' . $needle);
            }
            foreach (['guidance_mask', 'parse_guidance_mask', 'invalid_guidance_mask', 'guidance_mask_size_mismatch', 'no_positive_guidance', 'transparent pixels are neutral'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 guidance mask support missing ' . $needle);
            }
            foreach (['"confidence"', '"label_name"', '"polygons"'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 mask contract missing ' . $needle);
            }
            $fieldsByName = [];
            foreach ($manifest['l5_contract']['input']['fields'] ?? [] as $field) {
                $fieldsByName[(string)($field['name'] ?? '')] = $field;
            }
            $outputEnum = $fieldsByName['output_format']['enum'] ?? [];
            hub_test_assert(in_array('png', $outputEnum, true), 'sam3 output_format enum must include png');
            hub_test_assert(isset($fieldsByName['guidance_mask']), 'sam3 contract must include guidance_mask field');
            foreach (['PROMPT_TYPES', 'guidance_mask', 'parse_text_prompt', 'text_prompt'] as $needle) {
                hub_test_assert(str_contains($app, $needle), 'sam3 semantic prompt path missing ' . $needle);
            }
            foreach (['YOLO(', 'download'] as $needle) {
                hub_test_assert(!str_contains($app, $needle), 'sam3 app must not use YOLO/download: ' . $needle);
            }

            $smoke = (string)file_get_contents($base . '/smoke.py');
            foreach (['fastapi', 'PIL', 'numpy', 'requests', 'huggingface_hub', 'sam3', 'cv2'] as $needle) {
                hub_test_assert(str_contains($smoke, $needle), 'sam3 smoke.py missing ' . $needle);
            }
            foreach (['SAM(', 'YOLO(', 'predict', 'download', 'ultralytics'] as $needle) {
                hub_test_assert(!str_contains($smoke, $needle), 'sam3 smoke.py must not initialize model or infer: ' . $needle);
            }

            $storageSmoke = (string)file_get_contents($base . '/storage_smoke.py');
            foreach (['/models/sam3', '/cache/sam3', '/cache/sam3/xdg', '/cache/sam3/home', '/data/service'] as $needle) {
                hub_test_assert(str_contains($storageSmoke, $needle), 'sam3 storage_smoke.py missing ' . $needle);
            }
            foreach (['SAM(', 'YOLO(', 'predict', 'download', 'inference'] as $needle) {
                hub_test_assert(!str_contains($storageSmoke, $needle), 'sam3 storage_smoke.py must not initialize model or infer: ' . $needle);
            }

            $modelSmoke = (string)file_get_contents($base . '/model_smoke.py');
            foreach (['CHECKPOINT_NAME', 'MANIFEST_NAME', 'sam3.1_multiplex.pt', 'upstream_commit', 'repository'] as $needle) {
                hub_test_assert(str_contains($modelSmoke, $needle), 'sam3 model_smoke.py missing ' . $needle);
            }

            $adapter = (string)file_get_contents($base . '/sam31.py');
            foreach (['build_sam3_multiplex_video_predictor', 'handle_request', 'start_session', 'add_prompt', 'close_session', 'rel_coordinates', 'release_predictor'] as $needle) {
                hub_test_assert(str_contains($adapter, $needle), 'sam3 official adapter missing ' . $needle);
            }
            foreach (['ultralytics', 'SAM(', '.predict('] as $needle) {
                hub_test_assert(!str_contains($adapter, $needle), 'sam3 official adapter must not use legacy inference: ' . $needle);
            }
            foreach (['torch', 'SAM(', 'predict', 'download', 'inference', 'SAM3_CHECKPOINT', 'model_dir'] as $needle) {
                hub_test_assert(!str_contains($modelSmoke, $needle), 'sam3 model_smoke.py must not import or infer: ' . $needle);
            }

            $inferenceSmoke = (string)file_get_contents($base . '/inference_smoke.py');
            foreach (['/segment/image', 'real_inference', 'mock', 'masks'] as $needle) {
                hub_test_assert(str_contains($inferenceSmoke, $needle), 'sam3 inference_smoke.py missing ' . $needle);
            }

            $geometryOutput = [];
            $geometryExit = 0;
            if (hub_platform_id() === 'windows') {
                hub_test_skip('sam3 geometry_smoke.py requires Linux runtime on Windows control-plane host');
            }
            exec('cd ' . escapeshellarg($base) . ' && python3 geometry_smoke.py', $geometryOutput, $geometryExit);
            hub_test_assert($geometryExit === 0, 'sam3 geometry_smoke.py must pass');
        }
    }
});

hub_test('YOLO and SAM3 service instances generate GPU model mounts', function (): void {
    $db = hub_test_reset_db();

    $yolo = hub_install_pack($db, 'yolo', [
        'service_key' => 'yolo-test-main',
        'mode' => 'yolo_test',
        'name' => 'YOLO Test Main',
        'port_mode' => 'manual',
        'local_port' => 18160,
    ]);
    $sam3 = hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-test-main',
        'mode' => 'sam3_test',
        'name' => 'SAM3 Test Main',
        'port_mode' => 'manual',
        'local_port' => 18161,
    ]);

    foreach ([$yolo, $sam3] as $installed) {
        $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
        $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/runtime-settings.conf');
        hub_test_assert(str_contains($compose, '/models'), $installed['service']['service_key'] . ' compose must mount models');
        if ($installed['service']['pack_id'] === 'yolo') {
            hub_test_assert(!str_contains($compose, 'gpus: all'), 'yolo L2 compose must keep GPU optional');
            hub_test_assert(str_contains($env, 'YOLO_USE_GPU=0'), 'yolo env must default to CPU-safe mode');
            hub_test_assert(str_contains($env, 'YOLO_MODEL=yolo11n.pt'), 'yolo env must include model setting');
            foreach ([
                'YOLO_MODEL_DIR=/models/yolo',
                'YOLO_CACHE_DIR=/cache/yolo',
                'YOLO_SERVICE_DATA_DIR=/data/service',
                'XDG_CACHE_HOME=/cache/yolo/xdg',
                'HOME=/cache/yolo/home',
                'ULTRALYTICS_SETTINGS_DIR=/cache/yolo/ultralytics',
                'YOLO_REAL_INFERENCE=0',
            ] as $needle) {
                hub_test_assert(str_contains($env, $needle), 'yolo env missing ' . $needle);
            }
            hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/yolo:/models/yolo'), 'yolo compose must mount model storage');
            hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/yolo:/cache/yolo'), 'yolo compose must mount cache storage');
            hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'yolo compose must mount service data');
        } else {
            hub_test_assert(str_contains($compose, 'gpus: all'), $installed['service']['service_key'] . ' compose must request GPU');
            foreach ([
                'SAM3_MODEL_DIR=/models/sam3',
                'SAM3_CACHE_DIR=/cache/sam3',
                'SAM3_SERVICE_DATA_DIR=/data/service',
                'SAM3_REAL_INFERENCE=0',
                'SAM3_MAX_UPLOAD_MB=512',
                'SAM3_DEVICE=cuda',
                'SAM3_EXECUTION_MODE=resident',
                'SAM3_RESIDENT_MIN_FREE_VRAM_MB=4096',
                'SAM3_INTERNAL_JOB_TOKEN=',
                'HF_HOME=/cache/sam3/huggingface',
                'TORCH_HOME=/cache/sam3/torch',
                'HF_HUB_OFFLINE=1',
                'TRANSFORMERS_OFFLINE=1',
                'XDG_CACHE_HOME=/cache/sam3/xdg',
                'HOME=/cache/sam3/home',
                'PYTHONUNBUFFERED=1',
            ] as $needle) {
                hub_test_assert(str_contains($env, $needle), 'sam3 env missing ' . $needle);
            }
            hub_test_assert(!str_contains($env, 'SAM3_CHECKPOINT='), 'sam3 env must not expose arbitrary checkpoint selection');
            hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/sam3:/models/sam3:ro'), 'sam3 compose must mount model storage read-only');
            hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/sam3:/cache/sam3'), 'sam3 compose must mount cache storage');
            hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'sam3 compose must mount service data');
        }
    }
});

hub_test('SAM3 fixed model contract and gateway mode support L5 real smoke contract', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-main',
        'mode' => 'sam3',
        'name' => 'SAM3 Main',
        'port_mode' => 'manual',
        'local_port' => 18162,
    ]);
    $schema = hub_get_pack_settings_schema('sam3');
    hub_test_assert(!isset($schema['SAM3_CHECKPOINT']), 'SAM3 must not permit arbitrary checkpoints');
    hub_test_assert(($schema['SAM3_INTERNAL_JOB_TOKEN']['type'] ?? '') === 'secret', 'SAM3 internal token setting missing');
    hub_test_assert(($schema['SAM3_EXECUTION_MODE']['default'] ?? '') === 'resident', 'SAM3 resident mode setting missing');

    hub_set_service_enabled($db, 'sam3', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['CONTENT_LENGTH'] = '10';
    $response = hub_gateway_dispatch($db, 'sam3', static function (array $service, int $timeoutSec): array {
        hub_test_assert($service['mode'] === 'sam3', 'SAM3 gateway service mismatch');
        hub_test_assert($timeoutSec === 180, 'SAM3 timeout mismatch');

        return hub_gateway_json(200, [
            'ok' => true,
            'mock' => true,
            'runtime_level' => 'L5-benchmark-ready',
            'masks' => [],
            'boxes' => [],
        ]);
    });
    hub_test_assert($response['status'] === 200, 'SAM3 gateway mock should pass');

    $_POST = ['real_inference' => '1'];
    $realResponse = hub_gateway_dispatch($db, 'sam3', static fn (): array => hub_gateway_json(200, [
        'ok' => true,
        'mock' => false,
        'runtime_level' => 'L5-benchmark-ready',
        'masks' => [],
        'elapsed_ms' => 1,
    ]));
    hub_test_assert($realResponse['status'] === 200, 'SAM3 gateway real smoke should pass');
    $_POST = [];
});

hub_test('SAM3 model smoke accepts only the provisioned manifest', function (): void {
    if (hub_platform_id() === 'windows') {
        hub_test_skip('SAM3 model_smoke.py requires Linux runtime on Windows control-plane host');
    }
    $root = sys_get_temp_dir() . '/3waaihub_sam3_model_smoke_' . getmypid();
    $modelDir = $root . '/sam3';
    if (!is_dir($modelDir) && !mkdir($modelDir, 0775, true) && !is_dir($modelDir)) {
        throw new RuntimeException('Cannot create SAM3 test model dir.');
    }
    $checkpoint = $modelDir . '/sam3.1_multiplex.pt';
    file_put_contents($checkpoint, 'checkpoint');
    file_put_contents($modelDir . '/sam3.1-manifest.json', json_encode([
        'upstream_commit' => '96914d2425f90a64f45ca977c2b5165418099543',
        'repository' => 'facebook/sam3.1',
        'files' => ['sam3.1_multiplex.pt' => hash_file('sha256', $checkpoint)],
    ], JSON_THROW_ON_ERROR));

    $serviceDir = HUB_ROOT . '/packs/sam3/service';
    $output = [];
    $exit = 0;
    exec('cd ' . escapeshellarg($serviceDir) . ' && SAM3_MODEL_DIR=' . escapeshellarg($modelDir) . ' python3 model_smoke.py', $output, $exit);
    $payload = json_decode(implode("\n", $output), true);
    hub_test_assert($exit === 0, 'SAM3 model_smoke must accept the provisioned checkpoint');
    hub_test_assert(is_array($payload) && ($payload['present'] ?? false) === true, 'SAM3 model_smoke must report model presence');
    hub_test_assert(($payload['checkpoint'] ?? '') === 'sam3.1_multiplex.pt', 'SAM3 model smoke must report only the checkpoint name');
    hub_test_assert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), $modelDir), 'SAM3 model smoke must not expose a local model path');

    file_put_contents($modelDir . '/sam3.1-manifest.json', '{"files":{}}');
    $badOutput = [];
    $badExit = 0;
    exec('cd ' . escapeshellarg($serviceDir) . ' && SAM3_MODEL_DIR=' . escapeshellarg($modelDir) . ' python3 model_smoke.py', $badOutput, $badExit);
    $badPayload = json_decode(implode("\n", $badOutput), true);
    hub_test_assert($badExit === 2, 'SAM3 model_smoke must reject a changed manifest');
    hub_test_assert(is_array($badPayload) && ($badPayload['error'] ?? '') === 'model_manifest_invalid', 'SAM3 model smoke must return a safe manifest error');

    unlink($modelDir . '/sam3.1-manifest.json');
    unlink($checkpoint);
    rmdir($modelDir);
    rmdir($root);
});
