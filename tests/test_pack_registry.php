<?php
declare(strict_types=1);

hub_test('catalog and required packs are readable', function (): void {
    hub_test_assert(HUB_DB_PATH === getenv('AIHUB_TEST_DB'), 'test DB override is not active');

    $catalog = hub_load_pack_catalog();
    hub_test_assert(($catalog['schema_version'] ?? '') === '0.1', 'catalog schema_version mismatch');
    hub_test_assert(is_array($catalog['packs'] ?? null), 'catalog packs must be an array');

    $packs = hub_list_packs();
    $ids = array_column($packs, 'id');
    foreach (['hello', 'ocr-ppocrv5', 'translate-gemma12b', 'yolo', 'sam3', 'whisper-asr'] as $id) {
        hub_test_assert(in_array($id, $ids, true), 'missing pack: ' . $id);
        $pack = hub_get_pack($id);
        hub_test_assert($pack !== null && $pack['status'] === 'ok', 'pack invalid: ' . $id);
        foreach (['schema_version', 'id', 'name', 'version', 'category', 'type', 'execution_type', 'default_mode', 'runtime_level', 'runtime_ready', 'runtime', 'gateway', 'hardware', 'queue', 'storage', 'env', 'preflight'] as $field) {
            hub_test_assert(array_key_exists($field, $pack['manifest']), 'missing field ' . $field . ' in ' . $id);
        }
    }

    $hello = hub_get_pack('hello')['manifest'];
    hub_test_assert($hello['runtime_level'] === 'L5-benchmark-ready', 'Hello runtime level mismatch');
    hub_test_assert(($hello['target_level'] ?? '') === 'L5-benchmark-ready', 'Hello target level mismatch');
    hub_test_assert(($hello['role'] ?? '') === 'reference', 'Hello must be marked reference');
    $helloContract = $hello['l5_contract'] ?? [];
    hub_test_assert(is_array($helloContract), 'Hello l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'limits', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $helloContract), 'Hello l5_contract missing ' . $field);
    }
    hub_test_assert(($helloContract['endpoint'] ?? '') === '/', 'Hello contract endpoint mismatch');
    hub_test_assert(($helloContract['method'] ?? '') === 'GET', 'Hello contract method mismatch');
    foreach (['ok', 'service', 'message'] as $key) {
        hub_test_assert(in_array($key, $helloContract['output']['required_keys'] ?? [], true), 'Hello contract output missing ' . $key);
    }
    $helloBenchmarkCases = $helloContract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('hello_api', array_column($helloBenchmarkCases, 'id'), true), 'Hello benchmark case missing');

    $ocr = hub_get_pack('ocr-ppocrv5')['manifest'];
    hub_test_assert($ocr['runtime_level'] === 'L5-benchmark-ready', 'OCR runtime level mismatch');
    hub_test_assert($ocr['runtime_ready'] === true, 'OCR runtime ready mismatch');
    hub_test_assert(($ocr['target_level'] ?? '') === 'L5-benchmark-ready', 'OCR target level mismatch');
    hub_test_assert($ocr['hardware']['gpu_supported'] === true, 'OCR must advertise GPU support');
    $ocrSchema = hub_get_pack_settings_schema('ocr-ppocrv5');
    foreach (['OCR_USE_GPU', 'OCR_DEVICE', 'GPU_VISIBLE_DEVICES', 'OCR_GPU_FALLBACK_TO_CPU', 'OCR_GPU_REQUIRED'] as $key) {
        hub_test_assert(isset($ocrSchema[$key]), 'OCR settings_schema missing ' . $key);
    }
    hub_test_assert(($ocrSchema['OCR_DEVICE']['default'] ?? '') === 'auto', 'OCR_DEVICE default mismatch');
    hub_test_assert(in_array('gpu', $ocrSchema['OCR_DEVICE']['options'] ?? [], true), 'OCR_DEVICE must allow gpu');
    $ocrMounts = [];
    foreach ($ocr['storage']['mounts'] as $mount) {
        $ocrMounts[(string)$mount['type']] = (string)$mount['container_path'];
    }
    hub_test_assert(($ocrMounts['models'] ?? '') === '/models/paddleocr', 'OCR models mount mismatch');
    hub_test_assert(($ocrMounts['cache'] ?? '') === '/cache/paddleocr', 'OCR cache mount mismatch');
    hub_test_assert(($ocrMounts['service_data'] ?? '') === '/data/service', 'OCR service data mount mismatch');

    $contract = $ocr['l5_contract'] ?? [];
    hub_test_assert(is_array($contract), 'OCR l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'limits', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $contract), 'OCR l5_contract missing ' . $field);
    }
    hub_test_assert(($contract['endpoint'] ?? '') === '/ocr/image', 'OCR contract endpoint mismatch');
    hub_test_assert(($contract['method'] ?? '') === 'POST', 'OCR contract method mismatch');
    hub_test_assert(in_array('ok', $contract['output']['required_keys'] ?? [], true), 'OCR contract output missing ok');
    hub_test_assert(in_array('device', $contract['output']['required_keys'] ?? [], true), 'OCR contract output missing device');
    $benchmarkCases = $contract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('ocr_mock_image', array_column($benchmarkCases, 'id'), true), 'OCR mock benchmark case missing');
    hub_test_assert(in_array('ocr_real_image', array_column($benchmarkCases, 'id'), true), 'OCR real benchmark case missing');
    foreach ($benchmarkCases as $case) {
        if (($case['id'] ?? '') === 'ocr_real_image') {
            hub_test_assert(!empty($case['real_inference']), 'OCR real benchmark must be marked real_inference');
        }
    }
    $inputFields = $contract['input']['fields'] ?? [];
    hub_test_assert(in_array('real_inference', array_column($inputFields, 'name'), true), 'OCR contract must document real_inference form field');

    $translate = hub_get_pack('translate-gemma12b')['manifest'];
    hub_test_assert($translate['runtime_level'] === 'L5-benchmark-ready', 'Translate runtime level mismatch');
    hub_test_assert($translate['runtime_ready'] === true, 'Translate runtime ready mismatch');
    hub_test_assert(($translate['target_level'] ?? '') === 'L5-benchmark-ready', 'Translate target level mismatch');
    $translateContract = $translate['l5_contract'] ?? [];
    hub_test_assert(is_array($translateContract), 'Translate l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'limits', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $translateContract), 'Translate l5_contract missing ' . $field);
    }
    hub_test_assert(($translateContract['endpoint'] ?? '') === '/translate', 'Translate contract endpoint mismatch');
    hub_test_assert(($translateContract['content_type'] ?? '') === 'application/json', 'Translate contract content-type mismatch');
    foreach (['ok', 'mock', 'runtime_level', 'model', 'source_lang', 'target_lang', 'text', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $translateContract['output']['required_keys'] ?? [], true), 'Translate contract output missing ' . $key);
    }
    foreach (['input_too_long', 'ollama_timeout', 'model_not_present'] as $errorCode) {
        hub_test_assert(in_array($errorCode, $translateContract['errors'] ?? [], true), 'Translate contract errors missing ' . $errorCode);
    }
    $translateBenchmarkCases = $translateContract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('translate_mock_text', array_column($translateBenchmarkCases, 'id'), true), 'Translate mock benchmark case missing');
    hub_test_assert(in_array('translate_real_text', array_column($translateBenchmarkCases, 'id'), true), 'Translate real benchmark case missing');
    foreach ($translateBenchmarkCases as $case) {
        if (($case['id'] ?? '') === 'translate_real_text') {
            hub_test_assert(!empty($case['real_inference']), 'Translate real benchmark must be marked real_inference');
            hub_test_assert(!isset($case['expected_text']), 'Translate real benchmark must not assert exact output text');
            hub_test_assert(!empty($case['expected_cjk']), 'Translate real benchmark must validate CJK output');
        }
    }
    $translateMounts = [];
    foreach ($translate['storage']['mounts'] as $mount) {
        $translateMounts[(string)$mount['type']] = [
            'container_path' => (string)$mount['container_path'],
            'target_service' => (string)($mount['target_service'] ?? ''),
        ];
    }
    hub_test_assert(($translateMounts['models']['container_path'] ?? '') === '/root/.ollama', 'Translate Ollama models mount mismatch');
    hub_test_assert(($translateMounts['models']['target_service'] ?? '') === 'ollama', 'Translate Ollama models target service mismatch');
    hub_test_assert(($translateMounts['cache']['container_path'] ?? '') === '/cache/translate', 'Translate cache mount mismatch');
    hub_test_assert(($translateMounts['service_data']['container_path'] ?? '') === '/data/service', 'Translate service data mount mismatch');
    foreach (['OLLAMA_MODEL', 'KEEP_WARM', 'MAX_INPUT_CHARS', 'TEMPERATURE', 'OLLAMA_NUM_CTX', 'GPU_VISIBLE_DEVICES', 'TRANSLATE_REAL_INFERENCE', 'OLLAMA_KEEP_ALIVE'] as $key) {
        hub_test_assert(isset(hub_get_pack_settings_schema('translate-gemma12b')[$key]), 'Translate settings_schema missing ' . $key);
    }
    $yolo = hub_get_pack('yolo')['manifest'];
    hub_test_assert($yolo['runtime_level'] === 'L5-benchmark-ready', 'YOLO runtime level mismatch');
    hub_test_assert(($yolo['target_level'] ?? '') === 'L5-benchmark-ready', 'YOLO target level mismatch');
    hub_test_assert($yolo['runtime_ready'] === true, 'YOLO runtime ready mismatch');
    foreach (['YOLO_MODEL', 'YOLO_CONF', 'YOLO_IOU', 'YOLO_USE_GPU', 'KEEP_WARM', 'YOLO_REAL_INFERENCE'] as $key) {
        hub_test_assert(isset(hub_get_pack_settings_schema('yolo')[$key]), 'YOLO settings_schema missing ' . $key);
    }
    $yoloSchema = hub_get_pack_settings_schema('yolo');
    hub_test_assert(($yoloSchema['YOLO_MODEL']['model_selector']['root_subdir'] ?? '') === 'yolo', 'YOLO_MODEL selector missing');
    $yoloMounts = [];
    foreach ($yolo['storage']['mounts'] as $mount) {
        $yoloMounts[(string)$mount['type']] = (string)$mount['container_path'];
    }
    hub_test_assert(($yoloMounts['models'] ?? '') === '/models/yolo', 'YOLO models mount mismatch');
    hub_test_assert(($yoloMounts['cache'] ?? '') === '/cache/yolo', 'YOLO cache mount mismatch');
    hub_test_assert(($yoloMounts['service_data'] ?? '') === '/data/service', 'YOLO service data mount mismatch');

    $yoloContract = $yolo['l5_contract'] ?? [];
    hub_test_assert(is_array($yoloContract), 'YOLO l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'limits', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $yoloContract), 'YOLO l5_contract missing ' . $field);
    }
    hub_test_assert(($yoloContract['endpoint'] ?? '') === '/detect/image', 'YOLO contract endpoint mismatch');
    foreach (['ok', 'detections'] as $key) {
        hub_test_assert(in_array($key, $yoloContract['output']['required_keys'] ?? [], true), 'YOLO contract output missing ' . $key);
    }
    foreach (['class_id', 'label', 'confidence', 'bbox'] as $key) {
        hub_test_assert(in_array($key, $yoloContract['output']['detection_keys'] ?? [], true), 'YOLO contract detection missing ' . $key);
    }
    $yoloBenchmarkCases = $yoloContract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('yolo_mock_image', array_column($yoloBenchmarkCases, 'id'), true), 'YOLO mock benchmark case missing');
    hub_test_assert(in_array('yolo_real_image', array_column($yoloBenchmarkCases, 'id'), true), 'YOLO real benchmark case missing');
    foreach ($yoloBenchmarkCases as $case) {
        if (($case['id'] ?? '') === 'yolo_real_image') {
            hub_test_assert(!empty($case['real_inference']), 'YOLO real benchmark must be marked real_inference');
        }
    }
    $sam3 = hub_get_pack('sam3')['manifest'];
    hub_test_assert($sam3['runtime_level'] === 'L4b-real-inference-smoke', 'SAM3 runtime level mismatch');
    hub_test_assert(($sam3['target_level'] ?? '') === 'L5-benchmark-ready', 'SAM3 target level mismatch');
    hub_test_assert($sam3['runtime_ready'] === true, 'SAM3 runtime ready mismatch');
    hub_test_assert(($sam3['category'] ?? '') === 'vision', 'SAM3 category mismatch');
    $sam3Mounts = [];
    foreach ($sam3['storage']['mounts'] as $mount) {
        $sam3Mounts[(string)$mount['type']] = (string)$mount['container_path'];
    }
    hub_test_assert(($sam3Mounts['models'] ?? '') === '/models/sam3', 'SAM3 models mount mismatch');
    hub_test_assert(($sam3Mounts['cache'] ?? '') === '/cache/sam3', 'SAM3 cache mount mismatch');
    hub_test_assert(($sam3Mounts['service_data'] ?? '') === '/data/service', 'SAM3 service data mount mismatch');
    $sam3Schema = hub_get_pack_settings_schema('sam3');
    foreach (['SAM3_CHECKPOINT', 'SAM3_MODEL_ID', 'SAM3_DEVICE', 'SAM3_MAX_UPLOAD_MB', 'SAM3_REAL_INFERENCE'] as $key) {
        hub_test_assert(isset($sam3Schema[$key]), 'SAM3 settings_schema missing ' . $key);
    }
    hub_test_assert(($sam3Schema['SAM3_CHECKPOINT']['model_selector']['root_subdir'] ?? '') === 'sam3', 'SAM3_CHECKPOINT selector missing');
    foreach (['.pt', '.pth', '.safetensors', '.ckpt'] as $extension) {
        hub_test_assert(in_array($extension, $sam3Schema['SAM3_CHECKPOINT']['model_selector']['extensions'] ?? [], true), 'SAM3_CHECKPOINT selector missing ' . $extension);
    }

    $translateSchema = hub_get_pack_settings_schema('translate-gemma12b');
    hub_test_assert(($translateSchema['OLLAMA_MODEL']['model_selector']['type'] ?? '') === 'ollama_tag', 'OLLAMA_MODEL selector missing');

    $whisper = hub_get_pack('whisper-asr')['manifest'];
    hub_test_assert($whisper['runtime_level'] === 'L3-storage-mount', 'Whisper ASR runtime level mismatch');
    hub_test_assert(($whisper['target_level'] ?? '') === 'L5-benchmark-ready', 'Whisper ASR target level mismatch');
    hub_test_assert(($whisper['gateway']['invoke_path'] ?? '') === '/asr/audio', 'Whisper ASR gateway endpoint mismatch');
    $whisperSchema = hub_get_pack_settings_schema('whisper-asr');
    foreach (['WHISPER_MODEL', 'WHISPER_DEVICE', 'WHISPER_COMPUTE_TYPE', 'WHISPER_REAL_INFERENCE', 'WHISPER_MAX_UPLOAD_MB'] as $key) {
        hub_test_assert(isset($whisperSchema[$key]), 'Whisper ASR settings_schema missing ' . $key);
    }
});
