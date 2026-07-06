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

    hub_test_assert(hub_get_pack('ocr-ppocrv5')['manifest']['runtime_level'] === 'L1-gpu-api-mock', 'OCR runtime level mismatch');
    hub_test_assert(hub_get_pack('ocr-ppocrv5')['manifest']['runtime_ready'] === true, 'OCR runtime ready mismatch');
    hub_test_assert(hub_get_pack('ocr-ppocrv5')['manifest']['hardware']['gpu_supported'] === true, 'OCR must advertise GPU support');
    hub_test_assert(hub_get_pack('translate-gemma12b')['manifest']['runtime_level'] === 'L1-ollama-adapter', 'Translate runtime level mismatch');
    hub_test_assert(hub_get_pack('translate-gemma12b')['manifest']['runtime_ready'] === true, 'Translate runtime ready mismatch');
    hub_test_assert(hub_get_pack('yolo')['manifest']['runtime_level'] === 'L1-ultralytics-yolo', 'YOLO runtime level mismatch');
    hub_test_assert(hub_get_pack('yolo')['manifest']['runtime_ready'] === true, 'YOLO runtime ready mismatch');
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_level'] === 'L1-ultralytics-sam3', 'SAM3 runtime level mismatch');
    hub_test_assert(hub_get_pack('sam3')['manifest']['runtime_ready'] === true, 'SAM3 runtime ready mismatch');
});
