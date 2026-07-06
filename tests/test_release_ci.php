<?php
declare(strict_types=1);

hub_test('release banner docs ci and OCR L4a model init smoke files exist', function (): void {
    hub_test_assert(defined('HUB_VERSION') && str_starts_with(HUB_VERSION, 'v0.2.'), 'HUB_VERSION missing');
    hub_test_assert(defined('HUB_RELEASE_LABEL') && str_contains(HUB_RELEASE_LABEL, 'Local Catalog'), 'HUB_RELEASE_LABEL missing');

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    hub_test_assert(str_contains($readme, 'v0.2.x'), 'README version banner missing');
    hub_test_assert(str_contains($readme, 'Local Catalog'), 'README release scope missing');

    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    hub_test_assert(str_contains($layout, 'HUB_VERSION'), 'admin banner must display HUB_VERSION');
    hub_test_assert(str_contains($layout, 'HUB_RELEASE_LABEL'), 'admin banner must display HUB_RELEASE_LABEL');

    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/requirements.txt');
    hub_test_assert(str_contains($requirements, 'paddleocr'), 'OCR L4a must keep PaddleOCR dependency');
    hub_test_assert(str_contains($requirements, 'paddlepaddle'), 'OCR L4a model init must install PaddlePaddle runtime dependency');
    hub_test_assert(!str_contains($requirements, 'paddlepaddle-gpu'), 'OCR L4a model init smoke must not install PaddlePaddle GPU dependency');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/smoke.py'), 'OCR smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/storage_smoke.py'), 'OCR storage_smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/model_smoke.py'), 'OCR model_smoke.py missing');

    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04'), 'OCR GPU mock should use NVIDIA CUDA 12.9 runtime base image');
    hub_test_assert(str_contains($dockerfile, 'python3 -m pip check'), 'Dockerfile must validate Python dependency metadata at build time');
    hub_test_assert(str_contains($dockerfile, 'RUN python3 smoke.py'), 'OCR L4a build must keep dependency smoke.py');
    hub_test_assert(str_contains($dockerfile, 'model_smoke.py'), 'Dockerfile must copy model_smoke.py');
    hub_test_assert(!str_contains($dockerfile, 'RUN python3 model_smoke.py'), 'model_smoke.py must not run during Docker build');

    $smoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/smoke.py');
    hub_test_assert(str_contains($smoke, 'paddleocr'), 'smoke.py must import paddleocr');
    foreach (['PaddleOCR(', '.ocr(', 'download', 'from paddleocr import PaddleOCR'] as $needle) {
        hub_test_assert(!str_contains($smoke, $needle), 'smoke.py must not initialize model or run inference: ' . $needle);
    }

    $app = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/app.py');
    hub_test_assert(str_contains($app, 'return "L4a-model-init-smoke"'), 'health must report L4a runtime level');
    hub_test_assert(str_contains($app, '"storage"'), 'health must report storage status');
    hub_test_assert(str_contains($app, '"runtime_level": runtime_level()'), 'OCR mock response must include runtime level');

    $storageSmoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/storage_smoke.py');
    foreach (['/models/paddleocr', '/cache/paddleocr', '/data/service'] as $needle) {
        hub_test_assert(str_contains($storageSmoke, $needle), 'storage_smoke.py missing ' . $needle);
    }
    foreach (['PaddleOCR(', '.ocr(', 'download', 'predict', 'inference', 'from paddleocr import PaddleOCR'] as $needle) {
        hub_test_assert(!str_contains($storageSmoke, $needle), 'storage_smoke.py must not initialize model or run inference: ' . $needle);
    }

    $modelSmoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/model_smoke.py');
    foreach (['PaddleOCR(', 'PADDLEOCR_HOME', 'XDG_CACHE_HOME', 'HOME', '/models/paddleocr', '/cache/paddleocr'] as $needle) {
        hub_test_assert(str_contains($modelSmoke, $needle), 'model_smoke.py missing ' . $needle);
    }
    foreach (['.ocr(', '.predict(', 'benchmark', 'pdf'] as $needle) {
        hub_test_assert(!str_contains($modelSmoke, $needle), 'model_smoke.py must not run OCR work: ' . $needle);
    }

    $workflow = HUB_ROOT . '/.github/workflows/ci.yml';
    hub_test_assert(is_file($workflow), 'GitHub Actions workflow missing');
    $ci = (string)file_get_contents($workflow);
    foreach (['php scripts/run_tests.php', 'php -d assert.exception=1 scripts/self_check.php', 'git diff --check', 'bash -n'] as $needle) {
        hub_test_assert(str_contains($ci, $needle), 'CI missing: ' . $needle);
    }
});
