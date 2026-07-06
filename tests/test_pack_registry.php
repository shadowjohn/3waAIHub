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
    hub_test_assert($ocr['runtime_level'] === 'L4a-model-init-smoke', 'OCR runtime level mismatch');
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
    hub_test_assert(in_array('ocr_mock_image', array_column($contract['benchmark']['cases'] ?? [], 'id'), true), 'OCR benchmark case missing');

    hub_test_assert(hub_get_pack('translate-gemma12b')['manifest']['runtime_level'] === 'L1-ollama-adapter', 'Translate runtime level mismatch');
    hub_test_assert(hub_get_pack('translate-gemma12b')['manifest']['runtime_ready'] === true, 'Translate runtime ready mismatch');
    hub_test_assert(hub_get_pack('yolo')['manifest']['runtime_level'] === 'L1-ultralytics-yolo', 'YOLO runtime level mismatch');
    hub_test_assert(hub_get_pack('yolo')['manifest']['runtime_ready'] === true, 'YOLO runtime ready mismatch');
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_level'] === 'L1-ultralytics-sam3', 'SAM3 runtime level mismatch');
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_ready'] === true, 'SAM3 runtime ready mismatch');
});
