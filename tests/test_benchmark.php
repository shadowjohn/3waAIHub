<?php
declare(strict_types=1);

hub_test('benchmark skeleton records pack catalog scan', function (): void {
    $db = hub_test_reset_db();
    $result = hub_run_benchmark_case($db, 'pack_catalog_scan');
    hub_test_assert($result['status'] === 'pass', 'pack_catalog_scan did not pass');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM benchmark_runs')->fetchColumn() === 1, 'benchmark run was not recorded');
});

hub_test('Edge TTS L5 external acceptance stays pending and the offline runner refuses it', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $contract = hub_pack_l5_contract(hub_get_pack('edge-tts')['manifest']);
    $case = hub_l5_benchmark_case($contract, 'edge_tts_async_complete');

    hub_test_assert(is_array($case)
        && ($case['type'] ?? null) === 'external_acceptance'
        && ($case['real_inference'] ?? null) === true,
        'Edge TTS must declare its real public-API external acceptance case');

    $initial = hub_pack_l5_readiness($db, 'edge-tts');
    $beforeEnabled = (int)($installed['service']['enabled'] ?? 0);
    $offline = hub_run_benchmark_case($db, 'edge_tts_async_complete', 'edge-tts');
    hub_test_assert($initial['checks']['has_l5_contract'] === true
        && $initial['checks']['has_benchmark_cases'] === true
        && $initial['checks']['real_inference_benchmark_passed'] === false
        && $offline['status'] === 'fail'
        && $offline['error_message'] === 'external_acceptance_requires_script'
        && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 0
        && (int)(hub_get_service_by_key($db, 'edge-tts-main')['enabled'] ?? -1) === $beforeEnabled,
        'offline Edge TTS benchmark must record only its bounded refusal without enabling a service or creating a task');

    hub_save_benchmark_run($db, 'edge_tts_async_complete', (int)$installed['service']['id'], 'edge_tts', 'pass', 1, ['ok' => true], null);
    $ready = hub_pack_l5_readiness($db, 'edge-tts');
    hub_test_assert($ready['checks']['real_inference_benchmark_passed'] === true,
        'the named Edge TTS external acceptance pass must promote L5 readiness');
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
    hub_save_benchmark_run($db, 'sam3_real_polygon_image', (int)$service['id'], 'sam3', 'pass', 123, ['ok' => true], null);
    hub_save_benchmark_run($db, 'sam3_real_png_mask', (int)$service['id'], 'sam3', 'pass', 123, ['ok' => true], null);
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

    $contract = hub_get_pack('llm-gemma4-12b')['manifest']['photo_contract'] ?? [];
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
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM benchmark_runs WHERE benchmark_key = 'gemma4_real_photo_general'")->fetchColumn() === 1, 'Gemma4 real photo benchmark run must be recorded');
});

hub_test('L5 Gemma4 audio contract benchmark records mock without GPU', function (): void {
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

    $contract = hub_get_pack('llm-gemma4-12b')['manifest']['audio_contract'] ?? [];
    hub_test_assert(hub_l5_benchmark_case($contract, 'gemma4_mock_audio') !== null, 'gemma4_mock_audio case missing');
    foreach (['gemma4_real_audio_transcribe_zh', 'gemma4_real_audio_understand'] as $caseId) {
        $case = hub_l5_benchmark_case($contract, $caseId);
        hub_test_assert($case !== null, $caseId . ' case missing');
        hub_test_assert(!empty($case['real_inference']), $caseId . ' must be marked real_inference');
        hub_test_assert(trim((string)($case['fixture'] ?? '')) !== '', $caseId . ' must declare fixture');
        hub_test_assert((string)($case['fixture_field'] ?? '') === 'audio', $caseId . ' must upload audio field');
    }

    $mock = hub_run_benchmark_case($db, 'gemma4_mock_audio', 'llm-gemma4-12b');
    hub_test_assert($mock['status'] === 'pass', 'gemma4_mock_audio did not pass');
    hub_test_assert(($mock['result']['expected_keys_pass'] ?? false) === true, 'Gemma4 audio mock expected keys check failed');
    hub_test_assert(($mock['result']['mock'] ?? null) === true, 'Gemma4 audio mock benchmark must stay mock');
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

hub_test('L5 binary benchmark validates PNG dimensions and declared response headers without JSON keys', function (): void {
    $fixture = HUB_ROOT . '/packs/image-birefnet/demo/smoke.png';
    $png = (string)file_get_contents($fixture);
    $size = getimagesizefromstring($png);
    hub_test_assert(is_array($size), 'BiRefNet binary benchmark fixture is not a PNG');
    $response = [
        'status' => 200,
        'headers' => [
            'Content-Type: image/png',
            'X-3waAIHub-Model: ZhengPeng7/BiRefNet@revision',
            'X-3waAIHub-Device: cuda',
            'X-3waAIHub-Elapsed-Ms: 12',
            'X-3waAIHub-Width: ' . $size[0],
            'X-3waAIHub-Height: ' . $size[1],
        ],
        'body' => $png,
    ];
    $legacyResult = hub_benchmark_binary_response_result([
        'expected_content_type' => 'image/png',
        'expected_png' => true,
        'expected_dimensions_from_fixture' => true,
        'expected_response_headers' => [
            'X-3waAIHub-Model',
            'X-3waAIHub-Device',
            'X-3waAIHub-Elapsed-Ms',
            'X-3waAIHub-Width',
            'X-3waAIHub-Height',
        ],
        'expected_keys' => ['must_not_be_checked_for_binary'],
    ], $response, $fixture);
    hub_test_assert($legacyResult === [
        'content_type' => 'image/png',
        'output_bytes' => strlen($png),
        'width' => $size[0],
        'height' => $size[1],
        'response_headers_pass' => true,
    ], 'legacy binary benchmark result must remain unchanged without golden fields');

    $result = hub_benchmark_binary_response_result([
        'expected_content_type' => 'image/png',
        'expected_png' => true,
        'expected_dimensions_from_fixture' => true,
        'expected_dimensions' => [(int)$size[0], (int)$size[1]],
        'expected_sha256' => hash('sha256', $png),
        'expected_response_headers' => [
            'X-3waAIHub-Model',
            'X-3waAIHub-Device',
            'X-3waAIHub-Elapsed-Ms',
            'X-3waAIHub-Width',
            'X-3waAIHub-Height',
        ],
        'expected_response_header_values' => [
            'X-3waAIHub-Model' => 'ZhengPeng7/BiRefNet@revision',
            'x-3waaihub-device' => 'cuda',
        ],
        'expected_keys' => ['must_not_be_checked_for_binary'],
    ], $response, $fixture);

    hub_test_assert($result === [
        'content_type' => 'image/png',
        'output_bytes' => strlen($png),
        'width' => $size[0],
        'height' => $size[1],
        'response_headers_pass' => true,
        'output_sha256' => hash('sha256', $png),
    ], 'binary benchmark result mismatch');

    $baseCase = [
        'expected_content_type' => 'image/png',
        'expected_png' => true,
        'expected_dimensions' => [(int)$size[0], (int)$size[1]],
        'expected_sha256' => hash('sha256', $png),
        'expected_response_headers' => [
            'X-3waAIHub-Model',
            'X-3waAIHub-Device',
            'X-3waAIHub-Elapsed-Ms',
            'X-3waAIHub-Width',
            'X-3waAIHub-Height',
        ],
        'expected_response_header_values' => [
            'X-3waAIHub-Model' => 'ZhengPeng7/BiRefNet@revision',
            'X-3waAIHub-Device' => 'cuda',
        ],
    ];
    $baseResponse = $response;
    foreach ([
        'wrong dimensions' => [
            array_replace($baseCase, ['expected_dimensions' => [(int)$size[0] + 1, (int)$size[1]]]),
            $baseResponse,
        ],
        'wrong digest' => [
            array_replace($baseCase, ['expected_sha256' => str_repeat('0', 64)]),
            $baseResponse,
        ],
        'wrong header value' => [
            array_replace($baseCase, ['expected_response_header_values' => ['X-3waAIHub-Device' => 'cpu']]),
            $baseResponse,
        ],
        'invalid dimensions' => [
            array_replace($baseCase, ['expected_dimensions' => [(int)$size[0], 0]]),
            $baseResponse,
        ],
        'non-array dimensions' => [
            array_replace($baseCase, ['expected_dimensions' => '2x2']),
            $baseResponse,
        ],
        'three dimensions' => [
            array_replace($baseCase, ['expected_dimensions' => [(int)$size[0], (int)$size[1], 1]]),
            $baseResponse,
        ],
        'float dimensions' => [
            array_replace($baseCase, ['expected_dimensions' => [(float)$size[0], (int)$size[1]]]),
            $baseResponse,
        ],
        'invalid digest' => [
            array_replace($baseCase, ['expected_sha256' => strtoupper(hash('sha256', $png))]),
            $baseResponse,
        ],
        'short lowercase digest' => [
            array_replace($baseCase, ['expected_sha256' => str_repeat('a', 63)]),
            $baseResponse,
        ],
        'non-array response header map' => [
            array_replace($baseCase, ['expected_response_header_values' => 'cuda']),
            $baseResponse,
        ],
        'invalid response header map' => [
            array_replace($baseCase, ['expected_response_header_values' => ['X-3waAIHub-Device' => 1]]),
            $baseResponse,
        ],
    ] as $label => [$case, $response]) {
        $caught = false;
        try {
            hub_benchmark_binary_response_result($case, $response, $fixture);
        } catch (RuntimeException $error) {
            hub_test_assert($error->getMessage() === 'benchmark contract check failed.', $label . ' must fail closed');
            $caught = true;
        }
        hub_test_assert($caught, $label . ' must fail the binary benchmark contract');
    }
});

hub_test('L5 readiness requires the latest pass for every real benchmark case', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'structure-ppstructurev3', [
        'service_key' => 'structure-readiness-main',
        'mode' => 'structure',
        'name' => 'Structure Readiness Main',
        'port_mode' => 'manual',
        'local_port' => 18109,
        'environment' => 'production',
        'idempotent' => true,
    ]);
    $serviceId = (int)$installed['service']['id'];

    hub_save_benchmark_run($db, 'structure_page_pdf', $serviceId, 'structure', 'pass', 1, ['ok' => true], null);
    hub_test_assert(hub_pack_l5_readiness($db, 'structure-ppstructurev3')['checks']['real_inference_benchmark_passed'] === false,
        'one real benchmark pass must not satisfy dual-case readiness');

    hub_save_benchmark_run($db, 'structure_10page_pdf', $serviceId, 'structure', 'pass', 1, ['ok' => true], null);
    hub_test_assert(hub_pack_l5_readiness($db, 'structure-ppstructurev3')['checks']['real_inference_benchmark_passed'] === true,
        'both latest real benchmark passes must satisfy readiness');

    hub_save_benchmark_run($db, 'structure_page_pdf', $serviceId, 'structure', 'fail', 1, ['ok' => false], 'failed');
    hub_test_assert(hub_pack_l5_readiness($db, 'structure-ppstructurev3')['checks']['real_inference_benchmark_passed'] === false,
        'a later real benchmark failure must revoke readiness');
});

hub_test('fixture benchmarks inject real_inference only when the active contract declares it', function (): void {
    $imageFields = hub_pack_l5_contract(hub_get_pack('image-tools')['manifest'])['input']['fields'] ?? [];
    $imageDeclaresRealInference = false;
    foreach ($imageFields as $field) {
        $imageDeclaresRealInference = $imageDeclaresRealInference
            || $field === 'real_inference'
            || (is_array($field) && ($field['name'] ?? null) === 'real_inference');
    }
    hub_test_assert($imageDeclaresRealInference === false, 'image-tools must not declare benchmark-only real_inference as a public input');

    $ocrFields = hub_pack_l5_contract(hub_get_pack('ocr-ppocrv5')['manifest'])['input']['fields'] ?? [];
    $ocrDeclaresRealInference = false;
    foreach ($ocrFields as $field) {
        $ocrDeclaresRealInference = $ocrDeclaresRealInference
            || $field === 'real_inference'
            || (is_array($field) && ($field['name'] ?? null) === 'real_inference');
    }
    hub_test_assert($ocrDeclaresRealInference === true, 'object input fields must continue to declare real_inference when public');

    $source = (string)file_get_contents(HUB_ROOT . '/app/benchmarks.php');
    hub_test_assert(str_contains($source, '!array_key_exists(\'real_inference\', $form) && $declaresRealInference'), 'fixture benchmark payloads must inject real_inference only when the active input contract declares it');
});
