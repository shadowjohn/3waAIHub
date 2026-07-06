<?php
declare(strict_types=1);

hub_test('YOLO and SAM3 packs have runnable Ultralytics adapter files', function (): void {
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
            hub_test_assert(str_contains($dockerfile, 'python3 /app/smoke.py') || str_contains($dockerfile, 'python3 smoke.py'), 'yolo Dockerfile must run smoke.py at build time');
            hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'yolo app must expose L5 runtime_level');
            hub_test_assert(str_contains($app, '"storage"'), 'yolo health must report storage');
            hub_test_assert(str_contains($app, 'YOLO_REAL_INFERENCE'), 'yolo app must keep real inference toggle');
            hub_test_assert(str_contains($app, 'YOLO('), 'yolo app must initialize model for real inference');
            hub_test_assert(str_contains($app, 'predict'), 'yolo app must run predict for real inference');
            hub_test_assert(str_contains($app, '"detections"'), 'yolo detect endpoint must return detections');

            $smoke = (string)file_get_contents($base . '/smoke.py');
            hub_test_assert(str_contains($smoke, 'ultralytics'), 'yolo smoke.py must import ultralytics');
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
            hub_test_assert(str_contains($app, 'ultralytics'), $packId . ' adapter must use Ultralytics');
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
        $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
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
            hub_test_assert(str_contains($env, 'ULTRALYTICS_DEVICE=0'), $installed['service']['service_key'] . ' env must default to GPU device 0');
        }
    }
});
