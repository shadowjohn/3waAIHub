<?php
declare(strict_types=1);

hub_test('service instance uniqueness checks reject collisions', function (): void {
    $db = hub_test_reset_db();

    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-main',
        'name' => 'OCR Test Main',
        'mode' => 'ocr_test',
        'port_mode' => 'manual',
        'local_port' => 18150,
        'environment' => 'production',
    ]);

    hub_test_assert(str_contains($installed['service']['compose_file'], 'data/test_services/'), 'test DB runtime files must not be written to production services dir');
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(!str_contains($compose, 'gpus: all'), 'OCR CPU compose must not force GPU access');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/paddleocr:/models/paddleocr'), 'OCR generated compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/paddleocr:/cache/paddleocr'), 'OCR generated compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'OCR generated compose must mount service data storage');

    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    foreach ([
        'OCR_MODEL_DIR=/models/paddleocr',
        'OCR_CACHE_DIR=/cache/paddleocr',
        'OCR_SERVICE_DATA_DIR=/data/service',
        'XDG_CACHE_HOME=/cache/paddleocr/xdg',
        'HOME=/models/paddleocr/home',
        'PADDLEOCR_HOME=/models/paddleocr',
        'PADDLE_PDX_CACHE_HOME=/models/paddleocr',
        'OCR_VERSION=PP-OCRv5',
        'OCR_TEXT_DETECTION_MODEL_NAME=PP-OCRv5_server_det',
        'OCR_TEXT_RECOGNITION_MODEL_NAME=PP-OCRv5_server_rec',
        'OCR_TEXT_DET_LIMIT_SIDE_LEN=960',
        'OCR_TEXT_DET_LIMIT_TYPE=max',
        'OCR_TEXT_CONVERTER=opencc-s2twp',
        'OCR_USE_GPU=0',
        'OCR_DEVICE=auto',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'OCR env missing ' . $needle);
    }

    $gpuInstalled = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-gpu',
        'name' => 'OCR Test GPU',
        'mode' => 'ocr_test_gpu',
        'port_mode' => 'manual',
        'local_port' => 18153,
        'environment' => 'production',
    ]);
    $gpuCompose = (string)file_get_contents(hub_path($gpuInstalled['service']['compose_file']));
    hub_test_assert(str_contains($gpuCompose, 'gpus: all'), 'OCR GPU compose must request GPU access');
    hub_test_assert(str_contains($gpuCompose, 'NVIDIA_VISIBLE_DEVICES'), 'OCR GPU compose must set NVIDIA_VISIBLE_DEVICES');
    $gpuEnv = (string)file_get_contents(dirname(hub_path($gpuInstalled['service']['compose_file'])) . '/.env');
    foreach (['OCR_USE_GPU=1', 'OCR_DEVICE=gpu', 'GPU_VISIBLE_DEVICES=all', 'OCR_GPU_FALLBACK_TO_CPU=1'] as $needle) {
        hub_test_assert(str_contains($gpuEnv, $needle), 'OCR GPU env missing ' . $needle);
    }

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-main',
        'name' => 'Duplicate Key',
        'mode' => 'ocr_test_key',
        'port_mode' => 'manual',
        'local_port' => 18151,
        'environment' => 'production',
    ])), 'duplicate service_key was accepted');

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-mode',
        'name' => 'Duplicate Mode',
        'mode' => 'ocr_test',
        'port_mode' => 'manual',
        'local_port' => 18152,
        'environment' => 'production',
    ])), 'duplicate mode was accepted');

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-port',
        'name' => 'Duplicate Port',
        'mode' => 'ocr_test_port',
        'port_mode' => 'manual',
        'local_port' => 18150,
        'environment' => 'production',
    ])), 'duplicate local_port was accepted');
});
