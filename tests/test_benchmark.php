<?php
declare(strict_types=1);

hub_test('benchmark skeleton records pack catalog scan', function (): void {
    $db = hub_test_reset_db();
    $result = hub_run_benchmark_case($db, 'pack_catalog_scan');
    hub_test_assert($result['status'] === 'pass', 'pack_catalog_scan did not pass');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM benchmark_runs')->fetchColumn() === 1, 'benchmark run was not recorded');
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
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM benchmark_runs WHERE benchmark_key = 'ocr_mock_image'")->fetchColumn() === 1, 'OCR benchmark run was not recorded');

    $service = hub_get_service_by_key($db, 'ocr-main');
    hub_save_benchmark_run($db, 'ocr_real_image', (int)$service['id'], 'ocr', 'pass', 123, ['ok' => true, 'real_inference' => true], null);
    $readiness = hub_pack_l5_readiness($db, 'ocr-ppocrv5');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'real inference benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'readiness must show OCR promoted to L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'readiness must be fully green after real benchmark pass');
});
