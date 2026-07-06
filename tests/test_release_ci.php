<?php
declare(strict_types=1);

hub_test('release banner docs ci and OCR GPU mock files exist', function (): void {
    hub_test_assert(defined('HUB_VERSION') && str_starts_with(HUB_VERSION, 'v0.2.'), 'HUB_VERSION missing');
    hub_test_assert(defined('HUB_RELEASE_LABEL') && str_contains(HUB_RELEASE_LABEL, 'Local Catalog'), 'HUB_RELEASE_LABEL missing');

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    hub_test_assert(str_contains($readme, 'v0.2.x'), 'README version banner missing');
    hub_test_assert(str_contains($readme, 'Local Catalog'), 'README release scope missing');

    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    hub_test_assert(str_contains($layout, 'HUB_VERSION'), 'admin banner must display HUB_VERSION');
    hub_test_assert(str_contains($layout, 'HUB_RELEASE_LABEL'), 'admin banner must display HUB_RELEASE_LABEL');

    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/requirements.txt');
    hub_test_assert(!str_contains($requirements, 'paddleocr'), 'OCR L1 mock must not install heavy PaddleOCR dependency');
    hub_test_assert(!str_contains($requirements, 'paddlepaddle-gpu'), 'OCR L1 mock must not install heavy PaddlePaddle GPU dependency');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/smoke.py'), 'OCR smoke.py missing');

    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04'), 'OCR GPU mock should use NVIDIA CUDA 12.9 runtime base image');
    hub_test_assert(str_contains($dockerfile, 'python3 -m pip check'), 'Dockerfile must validate Python dependency metadata at build time');
    hub_test_assert(!str_contains($dockerfile, 'RUN python3 smoke.py'), 'GPU import smoke must not run during Docker build');

    $workflow = HUB_ROOT . '/.github/workflows/ci.yml';
    hub_test_assert(is_file($workflow), 'GitHub Actions workflow missing');
    $ci = (string)file_get_contents($workflow);
    foreach (['php scripts/run_tests.php', 'php -d assert.exception=1 scripts/self_check.php', 'git diff --check', 'bash -n'] as $needle) {
        hub_test_assert(str_contains($ci, $needle), 'CI missing: ' . $needle);
    }
});
