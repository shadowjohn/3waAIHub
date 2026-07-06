<?php
declare(strict_types=1);

hub_test('catalog and required packs are readable', function (): void {
    hub_test_assert(HUB_DB_PATH === getenv('AIHUB_TEST_DB'), 'test DB override is not active');

    $catalog = hub_load_pack_catalog();
    hub_test_assert(($catalog['schema_version'] ?? '') === '0.1', 'catalog schema_version mismatch');
    hub_test_assert(is_array($catalog['packs'] ?? null), 'catalog packs must be an array');

    $packs = hub_list_packs();
    $ids = array_column($packs, 'id');
    foreach (['hello', 'ocr-ppocrv5', 'translate-gemma12b', 'yolo', 'sam3'] as $id) {
        hub_test_assert(in_array($id, $ids, true), 'missing pack: ' . $id);
        $pack = hub_get_pack($id);
        hub_test_assert($pack !== null && $pack['status'] === 'ok', 'pack invalid: ' . $id);
        foreach (['schema_version', 'id', 'name', 'version', 'category', 'type', 'execution_type', 'default_mode', 'runtime_level', 'runtime_ready', 'runtime', 'gateway', 'hardware', 'queue', 'storage', 'env', 'preflight'] as $field) {
            hub_test_assert(array_key_exists($field, $pack['manifest']), 'missing field ' . $field . ' in ' . $id);
        }
    }

    $ocr = hub_get_pack('ocr-ppocrv5')['manifest'];
    hub_test_assert($ocr['runtime_level'] === 'L5-benchmark-ready', 'OCR runtime level mismatch');
    hub_test_assert($ocr['runtime_ready'] === true, 'OCR runtime ready mismatch');
    hub_test_assert(($ocr['target_level'] ?? '') === 'L5-benchmark-ready', 'OCR target level mismatch');
    hub_test_assert($ocr['hardware']['gpu_supported'] === true, 'OCR must advertise GPU support');
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
    hub_test_assert($translate['runtime_level'] === 'L4a-model-present-smoke', 'Translate runtime level mismatch');
    hub_test_assert($translate['runtime_ready'] === true, 'Translate runtime ready mismatch');
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
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_level'] === 'L1-ultralytics-sam3', 'SAM3 runtime level mismatch');
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_ready'] === true, 'SAM3 runtime ready mismatch');

    $translateSchema = hub_get_pack_settings_schema('translate-gemma12b');
    hub_test_assert(($translateSchema['OLLAMA_MODEL']['model_selector']['type'] ?? '') === 'ollama_tag', 'OLLAMA_MODEL selector missing');
});
