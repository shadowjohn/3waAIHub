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

hub_test('L5 VoxCPM2 contract benchmark records mock and real cases', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-main',
        'name' => 'VoxCPM2 Main',
        'mode' => 'tts',
        'port_mode' => 'manual',
        'local_port' => 18108,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('tts-voxcpm2')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'tts_mock_wav') !== null, 'tts_mock_wav case missing');
    $realCase = hub_l5_benchmark_case($contract, 'tts_real_wav');
    hub_test_assert($realCase !== null, 'tts_real_wav case missing');
    hub_test_assert(!empty($realCase['real_inference']), 'TTS real benchmark must be marked real_inference');

    $readiness = hub_pack_l5_readiness($db, 'tts-voxcpm2');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'TTS readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'TTS readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['l4b_real_inference_complete'] === true, 'TTS readiness must see L5 runtime level');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'TTS real benchmark must start pending');

    $mock = hub_run_benchmark_case($db, 'tts_mock_wav', 'tts-voxcpm2');
    hub_test_assert($mock['status'] === 'pass', 'tts_mock_wav did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'TTS mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'TTS mock benchmark must stay mock');

    $service = hub_get_service_by_key($db, 'voxcpm2-main');
    hub_save_benchmark_run($db, 'tts_real_wav', (int)$service['id'], 'tts', 'pass', 123, [
        'success' => true,
        'mock' => false,
        'artifact_url' => '/artifacts/tts_test.wav',
        'sample_rate' => 48000,
        'duration_ms' => 1000,
    ], null);
    $readiness = hub_pack_l5_readiness($db, 'tts-voxcpm2');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'TTS real benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'TTS readiness must show promoted L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'TTS readiness must be fully green after real benchmark pass');
});

hub_test('L5 BioCLIP contract benchmark records mock and real cases', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'bioclip', [
        'service_key' => 'bioclip-main',
        'name' => 'BioCLIP Main',
        'mode' => 'bioclip',
        'port_mode' => 'manual',
        'local_port' => 18111,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('bioclip')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'bioclip_mock_image') !== null, 'bioclip_mock_image case missing');
    $realCase = hub_l5_benchmark_case($contract, 'bioclip_real_image');
    hub_test_assert($realCase !== null, 'bioclip_real_image case missing');
    hub_test_assert(!empty($realCase['real_inference']), 'BioCLIP real benchmark must be marked real_inference');

    $readiness = hub_pack_l5_readiness($db, 'bioclip');
    hub_test_assert($readiness['checks']['has_l5_contract'] === true, 'BioCLIP readiness must see l5_contract');
    hub_test_assert($readiness['checks']['has_benchmark_cases'] === true, 'BioCLIP readiness must see benchmark cases');
    hub_test_assert($readiness['checks']['l4b_real_inference_complete'] === true, 'BioCLIP readiness must see L5 runtime level');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === false, 'BioCLIP real benchmark must start pending');

    $mock = hub_run_benchmark_case($db, 'bioclip_mock_image', 'bioclip');
    hub_test_assert($mock['status'] === 'pass', 'bioclip_mock_image did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'BioCLIP mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'BioCLIP mock benchmark must stay mock');

    $service = hub_get_service_by_key($db, 'bioclip-main');
    hub_save_benchmark_run($db, 'bioclip_real_image', (int)$service['id'], 'bioclip', 'pass', 123, [
        'ok' => true,
        'mock' => false,
        'labels' => [['label' => 'mammal', 'score' => 0.9]],
        'elapsed_ms' => 1,
    ], null);
    $readiness = hub_pack_l5_readiness($db, 'bioclip');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'BioCLIP real benchmark pass must update readiness');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'BioCLIP readiness must show promoted L5');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'BioCLIP readiness must be fully green after real benchmark pass');
});

hub_test('L5 Gemma4 photo contract benchmark records mock without GPU', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'llm-gemma4-12b', [
        'service_key' => 'gemma4-main',
        'name' => 'Gemma4 Main',
        'mode' => 'chat',
        'port_mode' => 'manual',
        'local_port' => 18110,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('llm-gemma4-12b')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'gemma4_mock_photo') !== null, 'gemma4_mock_photo case missing');
    foreach (['gemma4_real_photo_general', 'gemma4_real_photo_ui'] as $caseId) {
        $case = hub_l5_benchmark_case($contract, $caseId);
        hub_test_assert($case !== null, $caseId . ' case missing');
        hub_test_assert(!empty($case['real_inference']), $caseId . ' must be marked real_inference');
        hub_test_assert(trim((string)($case['fixture'] ?? '')) !== '', $caseId . ' must declare fixture');
    }

    $mock = hub_run_benchmark_case($db, 'gemma4_mock_photo', 'llm-gemma4-12b');
    hub_test_assert($mock['status'] === 'pass', 'gemma4_mock_photo did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'Gemma4 photo mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'Gemma4 photo mock benchmark must stay mock');

    $service = hub_get_service_by_key($db, 'gemma4-main');
    hub_save_benchmark_run($db, 'gemma4_real_photo_general', (int)$service['id'], 'photo', 'pass', 123, [
        'ok' => true,
        'mock' => false,
        'answer' => '一張測試圖片',
        'caption' => '測試圖片',
        'tags' => ['test'],
    ], null);
    $readiness = hub_pack_l5_readiness($db, 'llm-gemma4-12b');
    hub_test_assert($readiness['checks']['real_inference_benchmark_passed'] === true, 'Gemma4 real photo pass must update readiness');
});

hub_test('L5 DocParser contract benchmark submits async PDF tasks', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-main',
        'name' => 'DocParser Main',
        'mode' => 'docparser',
        'port_mode' => 'auto',
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $contract = hub_pack_l5_contract(hub_get_pack('docparser')['manifest']);
    hub_test_assert(hub_l5_benchmark_case($contract, 'docparser_submit_pdf') !== null, 'docparser_submit_pdf case missing');
    hub_test_assert(hub_l5_benchmark_case($contract, 'docparser_submit_10page_pdf') !== null, 'docparser_submit_10page_pdf case missing');

    $result = hub_run_benchmark_case($db, 'docparser_submit_pdf', 'docparser');
    hub_test_assert($result['status'] === 'pass', 'docparser_submit_pdf did not pass');
    hub_test_assert(($result['result']['expected_keys_pass'] ?? false) === true, 'DocParser submit expected keys check failed');
    hub_test_assert(($result['result']['runtime_level'] ?? '') === 'L5-benchmark-ready', 'DocParser runtime level missing from benchmark');

    $task = $db->query("SELECT * FROM tasks WHERE task_type = 'docparser_parse' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    hub_test_assert(is_array($task), 'DocParser benchmark must enqueue a task');
    hub_test_assert(($task['status'] ?? '') === 'cancelled', 'DocParser submit benchmark must cancel its queued task after contract check');
    hub_test_assert(($task['queue_name'] ?? '') === 'ocr', 'DocParser benchmark task queue mismatch');

    $readiness = hub_pack_l5_readiness($db, 'docparser');
    hub_test_assert($readiness['runtime_level'] === 'L5-benchmark-ready', 'DocParser readiness must show promoted L5');
    hub_test_assert($readiness['checks']['latest_benchmark_pass'] === true, 'DocParser readiness must see latest benchmark pass');
    hub_test_assert($readiness['pass_count'] === $readiness['total_count'], 'DocParser readiness must be fully green after submit benchmark pass');
});
