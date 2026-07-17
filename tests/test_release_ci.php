<?php
declare(strict_types=1);

hub_test('release banner docs ci and OCR L5 benchmark ready files exist', function (): void {
    hub_test_assert(defined('HUB_VERSION') && str_starts_with(HUB_VERSION, 'v0.2.'), 'HUB_VERSION missing');
    hub_test_assert(defined('HUB_RELEASE_LABEL') && str_contains(HUB_RELEASE_LABEL, 'Local Catalog'), 'HUB_RELEASE_LABEL missing');

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    hub_test_assert(str_contains($readme, 'v0.2.x'), 'README version banner missing');
    hub_test_assert(str_contains($readme, 'Local Catalog'), 'README release scope missing');

    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    hub_test_assert(str_contains($layout, 'HUB_VERSION'), 'admin banner must display HUB_VERSION');
    hub_test_assert(str_contains($layout, 'HUB_RELEASE_LABEL'), 'admin banner must display HUB_RELEASE_LABEL');

    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/requirements.txt');
    hub_test_assert(str_contains($requirements, 'paddleocr'), 'OCR L4b must keep PaddleOCR dependency');
    hub_test_assert(str_contains($requirements, 'paddlepaddle'), 'OCR L4b real inference must install PaddlePaddle runtime dependency');
    hub_test_assert(str_contains($requirements, 'paddlepaddle-gpu'), 'OCR GPU service must install PaddlePaddle GPU runtime dependency');
    hub_test_assert(str_contains($requirements, 'cu129/paddlepaddle-gpu'), 'OCR GPU service must use CUDA 12.9 PaddlePaddle wheel');
    hub_test_assert(str_contains($requirements, 'opencc-python-reimplemented'), 'OCR migration must keep OpenCC Taiwan Traditional conversion dependency');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/smoke.py'), 'OCR smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/storage_smoke.py'), 'OCR storage_smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/model_smoke.py'), 'OCR model_smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/inference_smoke.py'), 'OCR inference_smoke.py missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/gpu_smoke.py'), 'OCR gpu_smoke.py missing');

    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'nvidia/cuda:12.9.0-cudnn-runtime-ubuntu22.04'), 'OCR GPU mock should use NVIDIA CUDA 12.9 runtime base image');
    hub_test_assert(str_contains($dockerfile, 'python3 -m pip check'), 'Dockerfile must validate Python dependency metadata at build time');
    hub_test_assert(str_contains($dockerfile, 'RUN python3 smoke.py'), 'OCR L4a build must keep dependency smoke.py');
    hub_test_assert(str_contains($dockerfile, 'gpu_smoke.py'), 'Dockerfile must copy gpu_smoke.py');
    hub_test_assert(!str_contains($dockerfile, 'RUN python3 gpu_smoke.py'), 'gpu_smoke.py must not run during Docker build');
    hub_test_assert(str_contains($dockerfile, 'model_smoke.py'), 'Dockerfile must copy model_smoke.py');
    hub_test_assert(str_contains($dockerfile, 'PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT=0'), 'Dockerfile must disable PaddleX MKLDNN CPU path');
    hub_test_assert(!str_contains($dockerfile, 'RUN python3 model_smoke.py'), 'model_smoke.py must not run during Docker build');

    $smoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/smoke.py');
    hub_test_assert(str_contains($smoke, 'paddleocr'), 'smoke.py must import paddleocr');
    foreach (['PaddleOCR(', '.ocr(', 'download', 'from paddleocr import PaddleOCR'] as $needle) {
        hub_test_assert(!str_contains($smoke, $needle), 'smoke.py must not initialize model or run inference: ' . $needle);
    }

    $app = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/app.py');
    hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'health must report L5 runtime level');
    hub_test_assert(str_contains($app, '"storage"'), 'health must report storage status');
    hub_test_assert(str_contains($app, '"gpu": gpu'), 'health must report GPU status');
    hub_test_assert(str_contains($app, '"runtime_level": runtime_level()'), 'OCR mock response must include runtime level');
    hub_test_assert(str_contains($app, '"device": device_status()'), 'OCR responses must include device status');
    hub_test_assert(str_contains($app, 'OCR_REAL_INFERENCE'), 'OCR app must keep mock fallback toggle');
    hub_test_assert(str_contains($app, 'PADDLE_PDX_ENABLE_MKLDNN_BYDEFAULT'), 'OCR app must disable PaddleX MKLDNN CPU path');
    foreach (['PADDLE_PDX_CACHE_HOME', 'OCR_VERSION', 'OCR_TEXT_DETECTION_MODEL_NAME', 'OCR_TEXT_RECOGNITION_MODEL_NAME', 'OCR_TEXT_DET_LIMIT_SIDE_LEN', 'OCR_TEXT_DET_LIMIT_TYPE', 'OpenCC', '"text_converter"'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'OCR app missing migration setting ' . $needle);
    }
    foreach (['image: UploadFile | None = File(None)', 'file: UploadFile | None = File(None)', '@app.post("/ocr/upload")'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'OCR app missing legacy upload compatibility ' . $needle);
    }

    $inferenceSmoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/inference_smoke.py');
    foreach (['OCR_REAL_INFERENCE', '/ocr/image', 'runtime_level', 'real_inference'] as $needle) {
        hub_test_assert(str_contains($inferenceSmoke, $needle), 'inference_smoke.py missing ' . $needle);
    }

    $gpuSmoke = (string)file_get_contents(HUB_ROOT . '/packs/ocr-ppocrv5/service/gpu_smoke.py');
    foreach (['paddle', 'paddlepaddle-gpu', 'is_compiled_with_cuda', 'OCR_GPU_REQUIRED'] as $needle) {
        hub_test_assert(str_contains($gpuSmoke, $needle), 'gpu_smoke.py missing ' . $needle);
    }

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
    hub_test_assert(str_contains($modelSmoke, 'f"{model_dir}/home"'), 'model_smoke.py must put PaddleX HOME under model storage');
    hub_test_assert(!str_contains($modelSmoke, 'f"{cache_dir}/home"'), 'model_smoke.py must not put PaddleX HOME under cache storage');
    foreach (['.ocr(', '.predict(', 'pdf'] as $needle) {
        hub_test_assert(!str_contains($modelSmoke, $needle), 'model_smoke.py must not run OCR work: ' . $needle);
    }

    $workflow = HUB_ROOT . '/.github/workflows/ci.yml';
    hub_test_assert(is_file($workflow), 'GitHub Actions workflow missing');
    $ci = (string)file_get_contents($workflow);
    foreach (['php scripts/run_tests.php', 'python3-numpy', 'zend.assertions=1', 'assert.exception=1', 'self_check.log', 'actions/upload-artifact@v4', 'git diff --check', 'bash -n'] as $needle) {
        hub_test_assert(str_contains($ci, $needle), 'CI missing: ' . $needle);
    }

    $windowsInstall = HUB_ROOT . '/install.ps1';
    hub_test_assert(is_file($windowsInstall), 'Windows install.ps1 missing');
    $ps1 = (string)file_get_contents($windowsInstall);
    foreach (['[switch]$Check', 'scripts/init_db.php', 'Windows Control Plane preview', 'php -S 127.0.0.1:8080'] as $needle) {
        hub_test_assert(str_contains($ps1, $needle), 'install.ps1 missing: ' . $needle);
    }
    foreach (['install NVIDIA', '--bootstrap-host', 'nvidia-smi'] as $needle) {
        hub_test_assert(!str_contains($ps1, $needle), 'install.ps1 must stay app-only preview: ' . $needle);
    }
});
