<?php
declare(strict_types=1);

hub_test('benchmark skeleton records pack catalog scan', function (): void {
    $db = hub_test_reset_db();
    $result = hub_run_benchmark_case($db, 'pack_catalog_scan');
    hub_test_assert($result['status'] === 'pass', 'pack_catalog_scan did not pass');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM benchmark_runs')->fetchColumn() === 1, 'benchmark run was not recorded');
});

hub_test('Hello L5 reference contract readiness and benchmark pass', function (): void {
    $db = hub_test_reset_db();
    $contract = hub_pack_l5_contract(hub_get_pack('hello')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'hello_api') !== null, 'hello_api l5 benchmark case missing');

    $result = hub_run_benchmark_case($db, 'hello_api', 'hello');
    hub_test_assert($result['status'] === 'pass', 'hello_api L5 benchmark did not pass');
    hub_test_assert(($result['result']['expected_keys_pass'] ?? false) === true, 'Hello expected keys check failed');

    $readiness = hub_pack_l5_readiness($db, 'hello');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'Hello readiness runtime mismatch');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'Hello readiness must be fully green');
});

hub_test('L5 OCR contract benchmark records expected key check', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-main',
        'name' => 'OCR Main',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18101,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $readiness = hub_pack_l5_readiness($db, 'ocr-ppocrv5');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['l4b_real_inference_complete'] === true, 'readiness must see L4b runtime level');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'real inference benchmark must start pending');

    $result = hub_run_benchmark_case($db, 'ocr_mock_image', 'ocr-ppocrv5');
    hub_test_assert($result['status'] === 'pass', 'ocr_mock_image did not pass');
    hub_test_assert(($result['result']['expected_keys_pass'] ?? false) === true, 'expected keys check failed');
    hub_test_assert(($result['result']['runtime_level'] ?? '') === 'L5-benchmark-ready', 'runtime level missing from benchmark');
    hub_test_assert(($result['result']['requested_device'] ?? '') === 'auto', 'requested device missing from benchmark');
    hub_test_assert(($result['result']['effective_device'] ?? '') === 'cpu', 'effective device missing from benchmark');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM benchmark_runs WHERE benchmark_key = 'ocr_mock_image'")->fetchColumn() === 1, 'OCR benchmark run was not recorded');

    $service = hub_get_service_by_key($db, 'ocr-main');
    hub_save_benchmark_run($db, 'ocr_real_image', (int)$service['id'], 'ocr', 'pass', 123, ['ok' => true, 'real_inference' => true], null);
    $readiness = hub_pack_l5_readiness($db, 'ocr-ppocrv5');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'real inference benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'readiness must show OCR promoted to L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'readiness must be fully green after real benchmark pass');
});

hub_test('L5 YOLO contract benchmark records mock and real cases', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'yolo', [
        'service_key' => 'yolo-main',
        'name' => 'YOLO Main',
        'mode' => 'yolo',
        'port_mode' => 'manual',
        'local_port' => 18105,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $readiness = hub_pack_l5_readiness($db, 'yolo');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'YOLO readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'YOLO readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['l4b_real_inference_complete'] === true, 'YOLO readiness must see L4b runtime level');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'YOLO real benchmark must start pending');

    $mock = hub_run_benchmark_case($db, 'yolo_mock_image', 'yolo');
    hub_test_assert($mock['status'] === 'pass', 'yolo_mock_image did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'YOLO mock expected keys check failed');

    $service = hub_get_service_by_key($db, 'yolo-main');
    hub_save_benchmark_run($db, 'yolo_real_image', (int)$service['id'], 'yolo', 'pass', 123, ['ok' => true, 'detections' => []], null);
    $readiness = hub_pack_l5_readiness($db, 'yolo');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'YOLO real benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'YOLO readiness must show promoted L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'YOLO readiness must be fully green after real benchmark pass');
});

hub_test('L5 TranslateGemma contract benchmark records mock and real cases', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'translate-gemma12b', [
        'service_key' => 'translate-main',
        'name' => 'TranslateGemma Main',
        'mode' => 'translate',
        'port_mode' => 'manual',
        'local_port' => 18102,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('translate-gemma12b')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'translate_mock_text') !== null, 'translate_mock_text case missing');
    $realCase = hub_l5_benchmark_case($contract, 'translate_real_text');
    hub_test_assert($realCase !== null, 'translate_real_text case missing');
    hub_test_assert(!isset($realCase['expected_text']), 'translate_real_text must not assert exact text');
    hub_test_assert(!empty($realCase['expected_cjk']), 'translate_real_text must validate CJK output');

    $readiness = hub_pack_l5_readiness($db, 'translate-gemma12b');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'Translate readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'Translate readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['l4b_real_inference_complete'] === true, 'Translate readiness must see L5 runtime level');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'Translate real benchmark must start pending');

    $mock = hub_run_benchmark_case($db, 'translate_mock_text', 'translate-gemma12b');
    hub_test_assert($mock['status'] === 'pass', 'translate_mock_text did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'Translate mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'Translate mock benchmark must stay mock');

    $service = hub_get_service_by_key($db, 'translate-main');
    hub_save_benchmark_run($db, 'translate_real_text', (int)$service['id'], 'translate', 'pass', 123, ['ok' => true, 'mock' => false, 'text' => '美好的時光'], null);
    $readiness = hub_pack_l5_readiness($db, 'translate-gemma12b');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'Translate real benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'Translate readiness must show promoted L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'Translate readiness must be fully green after real benchmark pass');
});

hub_test('L5 SAM3 contract benchmark records mock and real cases', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'sam3', [
        'service_key' => 'sam3-main',
        'name' => 'SAM3 Main',
        'mode' => 'sam3',
        'port_mode' => 'manual',
        'local_port' => 18106,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('sam3')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'sam3_mock_image') !== null, 'sam3_mock_image case missing');
    $realCase = hub_l5_benchmark_case($contract, 'sam3_real_image');
    hub_test_assert($realCase !== null, 'sam3_real_image case missing');
    hub_test_assert(!empty($realCase['real_inference']), 'SAM3 real benchmark must be marked real_inference');
    hub_test_assert(!isset($realCase['expected_min_masks']), 'SAM3 real benchmark must not assert mask count');

    $readiness = hub_pack_l5_readiness($db, 'sam3');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'SAM3 readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'SAM3 readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'SAM3 real benchmark must start pending');

    $mock = hub_run_benchmark_case($db, 'sam3_mock_image', 'sam3');
    hub_test_assert($mock['status'] === 'pass', 'sam3_mock_image did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'SAM3 mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'SAM3 mock benchmark must stay mock');

    $service = hub_get_service_by_key($db, 'sam3-main');
    hub_save_benchmark_run($db, 'sam3_real_image', (int)$service['id'], 'sam3', 'pass', 123, [
        'ok' => true,
        'mock' => false,
        'masks' => [],
        'elapsed_ms' => 1,
        'model' => ['checkpoint' => '/models/sam3/sam3.pt'],
    ], null);
    $readiness = hub_pack_l5_readiness($db, 'sam3');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'SAM3 real benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'SAM3 readiness must show promoted L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'SAM3 readiness must be fully green after real benchmark pass');
});
