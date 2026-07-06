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

    $result = hub_run_benchmark_case($db, 'ocr_mock_image', 'ocr-ppocrv5');
    hub_test_assert($result['status'] === 'pass', 'ocr_mock_image did not pass');
    hub_test_assert(($result['result']['expected_keys_pass'] ?? false) === true, 'expected keys check failed');
    hub_test_assert(($result['result']['runtime_level'] ?? '') === 'L4a-model-init-smoke', 'runtime level missing from benchmark');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM benchmark_runs WHERE benchmark_key = 'ocr_mock_image'")->fetchColumn() === 1, 'OCR benchmark run was not recorded');
});
