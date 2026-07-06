<?php
declare(strict_types=1);

hub_test('YOLO and SAM3 packs have runnable Ultralytics adapter files', function (): void {
    foreach (['yolo' => '/detect/image', 'sam3' => '/segment/image'] as $packId => $path) {
        $base = HUB_ROOT . '/packs/' . $packId . '/service';
        foreach (['Dockerfile', 'requirements.txt', 'app.py'] as $file) {
            hub_test_assert(is_file($base . '/' . $file), $packId . ' service missing ' . $file);
        }
        $app = (string)file_get_contents($base . '/app.py');
        hub_test_assert(str_contains($app, '@app.post("' . $path . '")'), $packId . ' adapter endpoint mismatch');
        if ($packId === 'yolo') {
            $requirements = (string)file_get_contents($base . '/requirements.txt');
            $dockerfile = (string)file_get_contents($base . '/Dockerfile');
            hub_test_assert(is_file($base . '/smoke.py'), 'yolo service missing smoke.py');
            hub_test_assert(str_contains($requirements, 'ultralytics'), 'yolo requirements must include ultralytics');
            hub_test_assert(str_contains($dockerfile, 'RUN python3 smoke.py'), 'yolo Dockerfile must run smoke.py at build time');
            hub_test_assert(str_contains($app, 'runtime_level()'), 'yolo app must expose runtime_level');
            hub_test_assert(str_contains($app, '"mock": True'), 'yolo detect endpoint must stay mock at L2');
            foreach (['YOLO(', 'predict', 'download'] as $needle) {
                hub_test_assert(!str_contains($app, $needle), 'yolo app must not initialize model or detect at L2: ' . $needle);
            }

            $smoke = (string)file_get_contents($base . '/smoke.py');
            hub_test_assert(str_contains($smoke, 'ultralytics'), 'yolo smoke.py must import ultralytics');
            hub_test_assert(str_contains($smoke, 'fastapi'), 'yolo smoke.py must import fastapi');
            foreach (['YOLO(', 'predict', 'download'] as $needle) {
                hub_test_assert(!str_contains($smoke, $needle), 'yolo smoke.py must not initialize model or detect: ' . $needle);
            }
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
        } else {
            hub_test_assert(str_contains($compose, 'gpus: all'), $installed['service']['service_key'] . ' compose must request GPU');
            hub_test_assert(str_contains($env, 'ULTRALYTICS_DEVICE=0'), $installed['service']['service_key'] . ' env must default to GPU device 0');
        }
    }
});
