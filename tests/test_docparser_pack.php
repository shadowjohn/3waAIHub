<?php
declare(strict_types=1);

hub_test('DocParser pack exposes internal L4 orchestrator contract', function (): void {
    $pack = hub_get_pack('docparser');
    hub_test_assert($pack !== null, 'DocParser pack missing');
    hub_test_assert(($pack['status'] ?? '') === 'ok', 'DocParser pack must be valid');

    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L4-orchestrator-complete-delivery', 'DocParser runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'DocParser target level mismatch');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'async_task', 'DocParser must be async_task');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'docparser', 'DocParser default mode mismatch');
    hub_test_assert(($manifest['runtime']['kind'] ?? '') === 'internal_task', 'DocParser must be internal task runtime');

    foreach (['DOCPARSER_STRUCTURE_MODE', 'DOCPARSER_TRANSLATE_MODE', 'DOCPARSER_TARGET_LANGUAGE', 'DOCPARSER_TRANSLATION_REQUIRED', 'DOCPARSER_PROFILE'] as $key) {
        hub_test_assert(isset(hub_get_pack_settings_schema('docparser')[$key]), 'DocParser settings_schema missing ' . $key);
    }

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-test-main',
        'mode' => 'docparser_test',
        'name' => 'DocParser Test Main',
        'port_mode' => 'auto',
    ]);
    hub_test_assert(($installed['service']['internal_url'] ?? '') === 'internal-task:task_submit:docparser_parse', 'DocParser internal_url mismatch');
    hub_test_assert(($installed['service']['health_url'] ?? '') === 'internal-task:health', 'DocParser health_url mismatch');
    hub_test_assert($installed['service']['local_port'] === null || (string)$installed['service']['local_port'] === '', 'DocParser internal task must not reserve local_port');
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(str_contains($compose, 'internal_task runtime'), 'DocParser compose marker missing');
    hub_test_assert(!str_contains($compose, 'build:'), 'DocParser internal task must not generate Docker build');

    $fixturePath = HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json';
    hub_test_assert(is_file($fixturePath), 'DocParser acceptance fixture missing');
    $fixture = json_decode((string)file_get_contents($fixturePath), true);
    hub_test_assert(($fixture['profile'] ?? '') === 'technical_manual', 'DocParser fixture profile mismatch');
    hub_test_assert(in_array('FZR150', $fixture['protected_tokens'] ?? [], true), 'DocParser fixture protected token missing');
});

hub_test('DocParser internal_task lifecycle is docker-free and stateful', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-lifecycle-main',
        'mode' => 'docparser_lifecycle',
        'name' => 'DocParser Lifecycle Main',
        'port_mode' => 'auto',
    ]);
    $service = $installed['service'];
    hub_test_assert(hub_service_is_internal_task($service), 'DocParser service must be detected as internal_task');

    $build = hub_build_service($db, $service);
    hub_test_assert((int)$build['exit_code'] === 0, 'internal_task build must be no-op success');

    $start = hub_start_service($db, $service);
    hub_test_assert((int)$start['exit_code'] === 0, 'internal_task start must be no-op success');
    $service = hub_get_service($db, (int)$service['id']) ?: $service;
    hub_test_assert((int)$service['enabled'] === 1, 'internal_task start must enable service');
    hub_test_assert((string)$service['status'] === 'running', 'internal_task start must mark running');
    hub_test_assert(hub_refresh_service_status($db, $service) === 'running', 'internal_task refresh must map enabled service to running');

    $stop = hub_stop_service($db, $service);
    hub_test_assert((int)$stop['exit_code'] === 0, 'internal_task stop must be no-op success');
    $service = hub_get_service($db, (int)$service['id']) ?: $service;
    hub_test_assert((int)$service['enabled'] === 0, 'internal_task stop must disable service');
    hub_test_assert((string)$service['status'] === 'stopped', 'internal_task stop must mark stopped');
    hub_test_assert(hub_refresh_service_status($db, $service) === 'stopped', 'internal_task refresh must map disabled service to stopped');
});

hub_test('DocParser internal_task gateway routes allowlisted task submit contract', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-gateway-main',
        'mode' => 'docparser_gateway',
        'name' => 'DocParser Gateway Main',
        'port_mode' => 'auto',
    ]);
    $service = $installed['service'];
    $start = hub_start_service($db, $service);
    hub_test_assert((int)$start['exit_code'] === 0, 'internal_task start must succeed before gateway dispatch');

    $serverBackup = $_SERVER;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=docparser_gateway';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = ['profile' => 'technical_manual'];
        $_FILES = [];

        $response = hub_gateway_dispatch($db, 'docparser_gateway', static fn (): array => throw new RuntimeException('internal_task gateway must not proxy'));
        hub_test_assert($response['status'] === 400, 'allowlisted internal_task gateway must route into task_submit validation');
        hub_test_assert(str_contains($response['body'], 'file_required'), 'allowlisted internal_task gateway must require uploaded PDF');
    } finally {
        $_SERVER = $serverBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
    }
});

hub_test('DocParser PDF submit stores upload and enqueues ocr task with normalized input', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-submit-main',
        'mode' => 'docparser_submit',
        'name' => 'DocParser Submit Main',
        'port_mode' => 'auto',
    ]);
    $service = $installed['service'];
    $start = hub_start_service($db, $service);
    hub_test_assert((int)$start['exit_code'] === 0, 'internal_task start must succeed before PDF submit');

    $tmpFile = tempnam(sys_get_temp_dir(), 'docparser_pdf_');
    hub_test_assert($tmpFile !== false, 'temp PDF file must be created');
    file_put_contents($tmpFile, "%PDF-1.4\nDocParser test\n");

    $serverBackup = $_SERVER;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=docparser_submit';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = [
            'target_language' => 'zh-TW',
            'translation_required' => '1',
        ];
        $_FILES = [
            'file' => [
                'name' => 'manual.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = hub_gateway_dispatch($db, 'docparser_submit', static fn (): array => throw new RuntimeException('internal_task gateway must not proxy'));
        hub_test_assert($response['status'] === 200, 'PDF submit must return 200');

        $payload = json_decode((string)$response['body'], true);
        hub_test_assert(is_array($payload), 'PDF submit response must be JSON');
        hub_test_assert(($payload['ok'] ?? false) === true, 'PDF submit must return ok=true');

        $taskId = (int)($payload['task_id'] ?? 0);
        hub_test_assert($taskId > 0, 'PDF submit must return task id');

        $task = hub_get_task($db, $taskId);
        hub_test_assert($task !== null, 'queued PDF task missing');
        hub_test_assert(($task['task_type'] ?? '') === 'docparser_parse', 'queued task type mismatch');
        hub_test_assert(($task['queue_name'] ?? '') === 'ocr', 'DocParser default queue must be ocr');

        $input = $task['input'] ?? [];
        hub_test_assert(($input['profile'] ?? '') === 'technical_manual', 'profile must be technical_manual');
        hub_test_assert(($input['target_language'] ?? '') === 'zh-TW', 'target_language mismatch');
        hub_test_assert(($input['translation_required'] ?? '') === '1', 'translation_required mismatch');
        hub_test_assert(($input['input_file'] ?? '') === HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/input.pdf', 'input_file path mismatch');
        hub_test_assert(is_file((string)$input['input_file']), 'stored PDF upload missing');
        hub_test_assert((string)file_get_contents((string)$input['input_file']) === "%PDF-1.4\nDocParser test\n", 'stored PDF upload content mismatch');
    } finally {
        $_SERVER = $serverBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

hub_test('DocParser rejects non-PDF upload and does not enqueue task', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-submit-reject',
        'mode' => 'docparser_submit_reject',
        'name' => 'DocParser Submit Reject',
        'port_mode' => 'auto',
    ]);
    $service = $installed['service'];
    $start = hub_start_service($db, $service);
    hub_test_assert((int)$start['exit_code'] === 0, 'internal_task start must succeed before reject test');

    $tmpFile = tempnam(sys_get_temp_dir(), 'docparser_txt_');
    hub_test_assert($tmpFile !== false, 'temp non-PDF file must be created');
    file_put_contents($tmpFile, "not a pdf\n");

    $serverBackup = $_SERVER;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=docparser_submit_reject';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = [];
        $_FILES = [
            'file' => [
                'name' => 'manual.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = hub_gateway_dispatch($db, 'docparser_submit_reject', static fn (): array => throw new RuntimeException('internal_task gateway must not proxy'));
        hub_test_assert($response['status'] === 400, 'non-PDF submit must return 400');

        $payload = json_decode((string)$response['body'], true);
        hub_test_assert(is_array($payload), 'non-PDF response must be JSON');
        hub_test_assert(($payload['error'] ?? '') === 'unsupported_file_type', 'non-PDF submit must reject unsupported_file_type');

        $taskCount = (int)$db->query('SELECT COUNT(*) FROM tasks WHERE task_type = \'docparser_parse\'')->fetchColumn();
        hub_test_assert($taskCount === 0, 'non-PDF submit must not enqueue docparser task');
    } finally {
        $_SERVER = $serverBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

hub_test('DocParser rejects .pdf upload without PDF magic and does not enqueue task', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-submit-magic-reject',
        'mode' => 'docparser_submit_magic_reject',
        'name' => 'DocParser Submit Magic Reject',
        'port_mode' => 'auto',
    ]);
    $service = $installed['service'];
    $start = hub_start_service($db, $service);
    hub_test_assert((int)$start['exit_code'] === 0, 'internal_task start must succeed before PDF magic reject test');

    $tmpFile = tempnam(sys_get_temp_dir(), 'docparser_fake_pdf_');
    hub_test_assert($tmpFile !== false, 'temp fake PDF file must be created');
    file_put_contents($tmpFile, "not really a pdf\n");

    $serverBackup = $_SERVER;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=docparser_submit_magic_reject';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = [];
        $_FILES = [
            'file' => [
                'name' => 'manual.pdf',
                'type' => 'application/pdf',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpFile),
            ],
        ];

        $response = hub_gateway_dispatch($db, 'docparser_submit_magic_reject', static fn (): array => throw new RuntimeException('internal_task gateway must not proxy'));
        hub_test_assert($response['status'] === 400, '.pdf submit without PDF magic must return 400');

        $payload = json_decode((string)$response['body'], true);
        hub_test_assert(is_array($payload), 'fake PDF response must be JSON');
        hub_test_assert(($payload['error'] ?? '') === 'invalid_pdf_file', 'fake PDF submit must reject invalid_pdf_file');

        $taskCount = (int)$db->query('SELECT COUNT(*) FROM tasks WHERE task_type = \'docparser_parse\'')->fetchColumn();
        hub_test_assert($taskCount === 0, 'fake PDF submit must not enqueue docparser task');
    } finally {
        $_SERVER = $serverBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
        if (is_file($tmpFile)) {
            unlink($tmpFile);
        }
    }
});

hub_test('DocParser task type and gateway upload contract are present', function (): void {
    hub_test_assert(hub_is_valid_task_type('docparser_parse'), 'docparser_parse task type must be allowlisted');
    $gateway = (string)file_get_contents(HUB_ROOT . '/app/gateway.php');
    foreach (['hub_api_docparser_task_submit', "'docparser_parse'", "'profile'", "'translation_required'", "'structure_mode'", "'translate_mode'"] as $needle) {
        hub_test_assert(str_contains($gateway, $needle), 'DocParser gateway missing ' . $needle);
    }
});

hub_test('DocParser task artifacts store complete delivery outputs', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'profile' => 'technical_manual',
        'input_file' => '/tmp/input.pdf',
        'target_language' => 'zh-TW',
        'translation_required' => '1',
    ], null, '127.0.0.1');

    $summary = hub_store_docparser_task_artifacts($db, $taskId, [
        'manifest' => ['status' => 'completed'],
        'reader_html' => '<html><body><h1 id="p1-b1">一般資訊</h1></body></html>',
        'bilingual_html' => '<html><body>General Information / 一般資訊</body></html>',
        'markdown' => "# 一般資訊\n",
        'docir' => ['schema' => '3wa-docir-v0.1', 'pages' => [['page' => 1]], 'blocks' => []],
        'toc' => [['title' => 'General Information', 'anchor' => 'p1-b1']],
        'rag_chunks' => [['block_id' => 'p1-b1', 'page' => 1]],
        'quality_report' => ['status' => 'completed', 'metrics' => []],
        'figures' => [],
    ]);

    foreach (['manifest', 'reader_html', 'bilingual_html', 'markdown', 'docir', 'toc', 'rag_chunks', 'quality_report'] as $key) {
        hub_test_assert(($summary[$key]['artifact_id'] ?? 0) > 0, 'DocParser artifact missing ' . $key);
        hub_test_assert(is_file((string)$summary[$key]['path']), 'DocParser artifact file missing ' . $key);
    }
});

hub_test('DocParser worker translation helper posts JSON explicitly', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/docparser.php');
    hub_test_assert(str_contains($source, 'CURLOPT_POST => true'), 'DocParser translation helper must force POST');
    hub_test_assert(str_contains($source, "'Content-Type: application/json'"), 'DocParser translation helper must send JSON content type');
    hub_test_assert(str_contains($source, 'CURLOPT_POSTFIELDS => $payload'), 'DocParser translation helper must send JSON payload');
});

hub_test('DocParser splits oversized translation blocks before calling TranslateGemma', function (): void {
    $text = str_repeat("line one\n", 900) . str_repeat('A', 9000);
    $chunks = hub_docparser_split_translation_text($text, 4000);
    hub_test_assert(count($chunks) >= 4, 'oversized translation text must be split into multiple chunks');
    foreach ($chunks as $chunk) {
        hub_test_assert(strlen($chunk) <= 4000, 'translation chunk exceeds max length');
        hub_test_assert(trim($chunk) !== '', 'translation chunk must not be empty');
    }
});

hub_test('DocParser structure payload flattens multi-page document_json arrays', function (): void {
    $payload = hub_docparser_structure_payload([
        'markdown' => "# 備援內容\n",
        'document_json' => [
            [
                'blocks' => [
                    ['id' => 'p1-title', 'type' => 'title', 'text' => '第一頁標題', 'bbox' => [1, 2, 3, 4]],
                ],
            ],
            [
                'res' => [
                    ['id' => 'p2-body', 'type' => 'text', 'text' => '第二頁段落', 'bbox' => [5, 6, 7, 8]],
                ],
            ],
        ],
    ]);

    hub_test_assert(count($payload['blocks'] ?? []) === 2, 'DocParser multi-page document_json must extract two blocks');
    hub_test_assert(($payload['blocks'][0]['page'] ?? 0) === 1, 'DocParser first extracted block page mismatch');
    hub_test_assert(($payload['blocks'][0]['order'] ?? 0) === 1, 'DocParser first extracted block order mismatch');
    hub_test_assert(($payload['blocks'][0]['type'] ?? '') === 'heading', 'DocParser title block must map to heading');
    hub_test_assert(($payload['blocks'][1]['page'] ?? 0) === 2, 'DocParser second extracted block page mismatch');
    hub_test_assert(($payload['blocks'][1]['order'] ?? 0) === 1, 'DocParser second extracted block order mismatch');
    hub_test_assert(trim((string)($payload['blocks'][1]['text'] ?? '')) === '第二頁段落', 'DocParser second extracted block text mismatch');
});

hub_test('DocParser structure payload normalizes PP-StructureV3 page and block variants with reading order', function (): void {
    $payload = hub_docparser_structure_payload([
        'markdown' => "# 備援內容\n",
        'document_json' => [
            'document' => [
                [
                    'page_index' => 0,
                    'res' => [
                        [
                            'id' => 'raw-body',
                            'block_type' => 'text',
                            'res_text' => '第二段',
                            'bbox' => [10, 80, 200, 120],
                        ],
                        [
                            'id' => 'raw-head',
                            'label' => 'title',
                            'html' => 'Overview',
                            'poly' => [[10, 20], [200, 20], [200, 50], [10, 50]],
                        ],
                    ],
                ],
                [
                    'page_id' => 3,
                    'blocks' => [
                        [
                            'id' => 'raw-table',
                            'type' => 'table',
                            'content' => 'Spec table',
                            'bbox' => [10, 30, 240, 120],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    hub_test_assert(($payload['pages'][0]['page'] ?? 0) === 1, 'page_index must normalize to page 1');
    hub_test_assert(count($payload['blocks'] ?? []) === 3, 'normalization must keep three blocks');
    hub_test_assert(($payload['blocks'][0]['id'] ?? '') === 'raw-head', 'reading order must sort heading before later body block');
    hub_test_assert(($payload['blocks'][0]['type'] ?? '') === 'heading', 'label=title must normalize to heading');
    hub_test_assert(trim((string)($payload['blocks'][0]['text'] ?? '')) === 'Overview', 'html field must become text');
    hub_test_assert(($payload['blocks'][1]['id'] ?? '') === 'raw-body', 'second block should keep body after heading');
    hub_test_assert(trim((string)($payload['blocks'][1]['text'] ?? '')) === '第二段', 'res_text field must become text');
    hub_test_assert(($payload['blocks'][2]['page'] ?? 0) === 3, 'page_id must normalize to page number');
    hub_test_assert(($payload['blocks'][2]['type'] ?? '') === 'table', 'table block must stay table');
    hub_test_assert(($payload['blocks'][0]['bbox'] ?? []) === [10.0, 20.0, 200.0, 50.0], 'poly must normalize to bbox');
});

hub_test('DocParser structure payload reads real PP-StructureV3 parsing_res_list blocks', function (): void {
    $payload = hub_docparser_structure_payload([
        'document_json' => [
            'page_index' => 0,
            'page_count' => 1,
            'width' => 1191,
            'height' => 1684,
            'parsing_res_list' => [
                [
                    'block_label' => 'header',
                    'block_content' => '光陽機車HONDA',
                    'block_bbox' => [58, 70, 270, 138],
                    'block_id' => 0,
                    'block_order' => null,
                ],
                [
                    'block_label' => 'text',
                    'block_content' => '服務指引',
                    'block_bbox' => [309, 389, 935, 505],
                    'block_id' => 1,
                    'block_order' => 1,
                ],
                [
                    'block_label' => 'image',
                    'block_content' => "光陽工業股份有限公司\n",
                    'block_bbox' => [0, 729, 1181, 1679],
                    'block_id' => 3,
                    'block_order' => null,
                ],
            ],
        ],
    ]);

    hub_test_assert(($payload['source_kind'] ?? '') === 'ppstructure_document_json', 'real PP-StructureV3 JSON must not fall back to markdown');
    hub_test_assert(count($payload['pages'] ?? []) === 1, 'PP-StructureV3 page metadata missing');
    hub_test_assert(($payload['pages'][0]['width'] ?? 0) === 1191.0, 'PP-StructureV3 page width mismatch');
    hub_test_assert(count($payload['blocks'] ?? []) === 3, 'PP-StructureV3 parsing_res_list blocks missing');
    hub_test_assert(($payload['blocks'][0]['type'] ?? '') === 'heading', 'PP-StructureV3 header must map to heading');
    hub_test_assert(trim((string)($payload['blocks'][1]['text'] ?? '')) === '服務指引', 'PP-StructureV3 block_content must become text');
    hub_test_assert(($payload['blocks'][2]['type'] ?? '') === 'figure', 'PP-StructureV3 image block must map to figure');
    hub_test_assert(count($payload['figures'] ?? []) === 1, 'PP-StructureV3 image block must become figure metadata');
});

hub_test('DocParser builds DocIR renders outputs and catches fake translation', function (): void {
    $structure = [
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'heading', 'text' => 'General Information', 'bbox' => [10, 10, 300, 40]],
            ['id' => 'raw-2', 'page' => 1, 'order' => 2, 'type' => 'paragraph', 'text' => 'Inspection of Main Jet FZR150 #97.5 10 N·m', 'bbox' => [10, 50, 500, 90]],
        ],
        'figures' => [],
    ];

    $docir = hub_docparser_build_docir($structure, [
        'target_language' => 'zh-TW',
        'translation_required' => true,
    ]);
    hub_test_assert(($docir['schema'] ?? '') === '3wa-docir-v0.1', 'DocIR schema mismatch');
    hub_test_assert(count($docir['pages'] ?? []) === 1, 'DocIR page missing');
    hub_test_assert(count($docir['blocks'] ?? []) === 2, 'DocIR blocks missing');
    hub_test_assert(($docir['blocks'][0]['id'] ?? '') === 'p1-b1', 'DocIR block id mismatch');

    $docir['blocks'][1]['translation'] = [
        'language' => 'zh-TW',
        'text' => 'Inspection of Main Jet FZR150 #97.5 10 N·m',
        'source_block_id' => 'p1-b2',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    hub_test_assert(str_contains($outputs['reader_html'], 'General Information'), 'Reader HTML missing heading');
    hub_test_assert(str_contains($outputs['toc_json'], 'General Information'), 'TOC JSON missing heading');
    hub_test_assert(str_contains($outputs['rag_chunks_json'], 'p1-b2'), 'RAG chunks missing provenance');

    $fixture = json_decode((string) file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    hub_test_assert(($quality['status'] ?? '') !== 'completed', 'fake translation must not complete');
    hub_test_assert(($quality['metrics']['protected_token_preservation'] ?? 0) === 1.0, 'protected tokens should be preserved');
});

hub_test('DocParser render outputs include extracted figure asset links', function (): void {
    $docir = hub_docparser_build_docir([
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-figure', 'page' => 1, 'order' => 1, 'type' => 'figure', 'text' => 'RC 閥示意圖', 'bbox' => [10, 20, 300, 240]],
        ],
        'figures' => [],
    ], [
        'target_language' => 'zh-TW',
    ]);
    $docir['figures'] = [[
        'id' => 'fig-p1-1',
        'page' => 1,
        'block_id' => 'p1-b1',
        'asset_path' => 'assets/figures/fig-p1-1.png',
        'caption' => 'RC 閥示意圖',
    ]];
    $docir['blocks'][0]['asset_path'] = 'assets/figures/fig-p1-1.png';

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    hub_test_assert(str_contains($outputs['reader_html'], '../assets/figures/fig-p1-1.png'), 'reader html must link extracted figure asset');
    hub_test_assert(str_contains($outputs['bilingual_html'], '../assets/figures/fig-p1-1.png'), 'bilingual html must link extracted figure asset');
    hub_test_assert(str_contains($outputs['markdown'], '![RC 閥示意圖](../assets/figures/fig-p1-1.png)'), 'markdown must link extracted figure asset');
    hub_test_assert(hub_docparser_broken_asset_links($outputs['reader_html'], $outputs['bilingual_html']) === 0, 'quality checker must allow exported figure asset links');
});

hub_test('DocParser ignores misaligned translations for zh-TW markdown and quality coverage', function (): void {
    $structure = [
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'paragraph', 'text' => 'Torque spec', 'bbox' => [10, 10, 200, 40]],
            ['id' => 'raw-2', 'page' => 1, 'order' => 2, 'type' => 'paragraph', 'text' => 'Tighten to 10 N·m', 'bbox' => [10, 50, 240, 80]],
        ],
        'figures' => [],
    ];

    $docir = hub_docparser_build_docir($structure, [
        'target_language' => 'zh-TW',
        'translation_required' => true,
    ]);
    $docir['blocks'][0]['translation'] = [
        'language' => 'zh-TW',
        'text' => '扭力規格',
        'source_block_id' => 'p1-b2',
    ];
    $docir['blocks'][1]['translation'] = [
        'language' => 'zh-TW',
        'text' => '鎖到 10 N·m',
        'source_block_id' => 'p1-b2',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    hub_test_assert(!str_contains($outputs['markdown'], '扭力規格'), 'markdown must ignore misaligned translation');
    hub_test_assert(!str_contains($outputs['markdown'], 'Torque spec'), 'markdown must not fake zh-TW with source fallback');
    hub_test_assert(str_contains($outputs['reader_html'], 'Torque spec'), 'reader html may keep source text for traceability');
    hub_test_assert(str_contains($outputs['markdown'], '鎖到 10 N·m'), 'markdown should keep aligned translation');

    $fixture = json_decode((string) file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    hub_test_assert(($quality['status'] ?? '') !== 'completed', 'misaligned translation coverage must not complete quality gate');
    hub_test_assert(abs((float) ($quality['metrics']['translation_block_coverage'] ?? 0) - 0.5) < 0.00001, 'coverage must count only aligned translations');
});

hub_test('DocParser counts short English identity translations in quality gate', function (): void {
    $structure = [
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'paragraph', 'text' => 'ABS', 'bbox' => [10, 10, 80, 30]],
        ],
        'figures' => [],
    ];

    $docir = hub_docparser_build_docir($structure, [
        'target_language' => 'zh-TW',
        'translation_required' => true,
    ]);
    $docir['blocks'][0]['translation'] = [
        'language' => 'zh-TW',
        'text' => 'ABS',
        'source_block_id' => 'p1-b1',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    $fixture = json_decode((string) file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);

    hub_test_assert(abs((float) ($quality['metrics']['translation_identity_ratio'] ?? 0) - 1.0) < 0.00001, 'short English identity must count toward identity ratio');
    hub_test_assert(($quality['status'] ?? '') !== 'completed', 'short English identity must keep quality gate non-completed');
});

hub_test('DocParser does not penalize Chinese source text that remains unchanged for zh-TW output', function (): void {
    $docir = hub_docparser_build_docir([
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'paragraph', 'text' => '前言', 'bbox' => [10, 10, 80, 30]],
        ],
        'figures' => [],
    ], [
        'target_language' => 'zh-TW',
        'translation_required' => true,
    ]);
    $docir['blocks'][0]['translation'] = [
        'language' => 'zh-TW',
        'text' => '前言',
        'source_block_id' => 'p1-b1',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    $fixture = [
        'expected_page_count_min' => 1,
        'minimum_heading_count' => 0,
        'minimum_table_count' => 0,
        'expected_figure_count_min' => 0,
        'required_toc_titles' => [],
        'required_translations' => [],
        'protected_tokens' => [],
        'quality_thresholds' => [
            'page_record_coverage' => 1.0,
            'block_provenance_coverage' => 1.0,
            'required_artifact_integrity' => 1.0,
            'translation_block_coverage' => 1.0,
            'translation_identity_ratio_max' => 0.10,
            'protected_token_preservation' => 1.0,
        ],
    ];
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);

    hub_test_assert((float)($quality['metrics']['translation_identity_ratio'] ?? 1) === 0.0, 'Chinese identity text should not count as fake translation for zh-TW');
});

hub_test('DocParser quality gate keeps fallback markdown output out of completed status', function (): void {
    $docir = hub_docparser_build_docir(hub_docparser_structure_payload([
        'markdown' => "# General Information\nInspection\n",
    ]), [
        'target_language' => 'zh-TW',
    ]);
    $docir['blocks'][0]['translation'] = [
        'language' => 'zh-TW',
        'text' => '一般資訊與檢查',
        'source_block_id' => 'p1-b1',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    $fixture = json_decode((string) file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);

    hub_test_assert(($docir['source_kind'] ?? '') === 'fallback_markdown', 'fallback markdown must mark source_kind');
    hub_test_assert(($quality['status'] ?? '') !== 'completed', 'fallback markdown must not complete quality gate');
});

hub_test('DocParser quality gate enforces required toc translations and content counts from fixture', function (): void {
    $fixture = [
        'expected_page_count_min' => 2,
        'minimum_heading_count' => 1,
        'minimum_table_count' => 1,
        'expected_figure_count_min' => 1,
        'required_toc_titles' => ['General Information'],
        'required_translations' => ['Inspection' => '檢查'],
        'protected_tokens' => [],
        'quality_thresholds' => [
            'page_record_coverage' => 1.0,
            'block_provenance_coverage' => 1.0,
            'required_artifact_integrity' => 1.0,
            'translation_block_coverage' => 0.98,
            'translation_identity_ratio_max' => 0.10,
            'protected_token_preservation' => 1.0,
        ],
    ];
    $docir = hub_docparser_build_docir([
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'paragraph', 'text' => 'Inspection notes', 'bbox' => [10, 10, 200, 40]],
        ],
        'figures' => [],
    ], [
        'target_language' => 'zh-TW',
    ]);
    $docir['blocks'][0]['translation'] = [
        'language' => 'zh-TW',
        'text' => '檢查說明',
        'source_block_id' => 'p1-b1',
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);

    hub_test_assert(($quality['status'] ?? '') === 'needs_review', 'fixture count and TOC requirements must fail incomplete output');
    hub_test_assert(in_array('expected_page_count_min', $quality['failures'] ?? [], true), 'quality must fail expected_page_count_min');
    hub_test_assert(in_array('minimum_heading_count', $quality['failures'] ?? [], true), 'quality must fail minimum_heading_count');
    hub_test_assert(in_array('minimum_table_count', $quality['failures'] ?? [], true), 'quality must fail minimum_table_count');
    hub_test_assert(in_array('expected_figure_count_min', $quality['failures'] ?? [], true), 'quality must fail expected_figure_count_min');
    hub_test_assert(in_array('required_toc_titles', $quality['failures'] ?? [], true), 'quality must fail required_toc_titles');
});

hub_test('DocParser worker source fails quality-gated tasks instead of always succeeding', function (): void {
    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/task_worker.php');
    hub_test_assert(str_contains($worker, 'docparser_quality_gate_failed'), 'worker must emit docparser_quality_gate_failed for non-completed quality gate');
});

hub_test('DocParser acceptance script checks artifact content not only existence', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/scripts/docparser_acceptance.php');
    foreach (['hub_docparser_acceptance_result', 'broken_asset_links', 'translation_identity_ratio', 'protected_token_preservation', 'toc_broken_anchor_count'] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'DocParser acceptance script missing ' . $needle);
    }
});

hub_test('DocParser acceptance function evaluates registered artifact content at runtime', function (): void {
    require_once HUB_ROOT . '/scripts/docparser_acceptance.php';

    $db = hub_test_reset_db();
    $fixture = [
        'required_toc_titles' => ['General Information'],
        'required_translations' => ['General Information' => '一般資訊'],
        'protected_tokens' => ['FZR150'],
        'quality_thresholds' => [
            'page_record_coverage' => 1.0,
            'block_provenance_coverage' => 1.0,
            'broken_asset_links' => 0,
            'toc_broken_anchor_count' => 0,
            'required_artifact_integrity' => 1.0,
            'translation_block_coverage' => 1.0,
            'translation_identity_ratio_max' => 0.10,
            'protected_token_preservation' => 1.0,
        ],
    ];

    $passTaskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'profile' => 'technical_manual',
        'input_file' => '/tmp/input.pdf',
        'target_language' => 'zh-TW',
        'translation_required' => '1',
    ], null, '127.0.0.1');
    hub_store_docparser_task_artifacts($db, $passTaskId, [
        'manifest' => ['status' => 'completed'],
        'reader_html' => '<html><body><h1 id="p1-b1">一般資訊</h1></body></html>',
        'bilingual_html' => '<html lang="zh-Hant"><body>General Information / 一般資訊</body></html>',
        'markdown' => "# 一般資訊\n",
        'docir' => [
            'schema' => '3wa-docir-v0.1',
            'pages' => [['page' => 1]],
            'blocks' => [[
                'id' => 'p1-b1',
                'page' => 1,
                'order' => 1,
                'type' => 'heading',
                'source_text' => 'General Information FZR150',
                'translation' => ['language' => 'zh-TW', 'text' => '一般資訊 FZR150', 'source_block_id' => 'p1-b1'],
                'provenance' => ['source_block_id' => 'raw-1'],
            ]],
            'figures' => [],
        ],
        'toc' => [['title' => 'General Information', 'anchor' => 'p1-b1']],
        'rag_chunks' => [['block_id' => 'p1-b1', 'page' => 1, 'text' => '一般資訊 FZR150']],
        'quality_report' => [
            'status' => 'completed',
            'metrics' => [
                'broken_asset_links' => 0,
                'translation_identity_ratio' => 0.0,
                'protected_token_preservation' => 1.0,
                'required_artifact_integrity' => 1.0,
                'translation_block_coverage' => 1.0,
                'page_record_coverage' => 1.0,
                'block_provenance_coverage' => 1.0,
            ],
        ],
        'figures' => [],
    ]);

    $passResult = hub_docparser_acceptance_result($db, $passTaskId, $fixture);
    hub_test_assert(($passResult['ok'] ?? false) === true, 'acceptance must pass when artifact content matches fixture');

    $failTaskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'profile' => 'technical_manual',
        'input_file' => '/tmp/input.pdf',
        'target_language' => 'zh-TW',
        'translation_required' => '1',
    ], null, '127.0.0.1');
    hub_store_docparser_task_artifacts($db, $failTaskId, [
        'manifest' => ['status' => 'completed'],
        'reader_html' => '<html><body><h1 id="p1-b1">一般資訊</h1></body></html>',
        'bilingual_html' => '<html lang="zh-Hant"><body>General Information / 一般資訊</body></html>',
        'markdown' => "# 一般資訊\n",
        'docir' => [
            'schema' => '3wa-docir-v0.1',
            'pages' => [['page' => 1]],
            'blocks' => [[
                'id' => 'p1-b1',
                'page' => 1,
                'order' => 1,
                'type' => 'heading',
                'source_text' => 'General Information FZR150',
                'translation' => ['language' => 'zh-TW', 'text' => '一般資訊', 'source_block_id' => 'p1-b1'],
                'provenance' => ['source_block_id' => 'raw-1'],
            ]],
            'figures' => [],
        ],
        'toc' => [['title' => 'General Information', 'anchor' => 'missing-anchor']],
        'rag_chunks' => [['block_id' => 'p1-b1', 'page' => 1, 'text' => '一般資訊']],
        'quality_report' => [
            'status' => 'needs_review',
            'metrics' => [
                'broken_asset_links' => 0,
                'translation_identity_ratio' => 0.0,
                'protected_token_preservation' => 0.5,
                'required_artifact_integrity' => 1.0,
                'translation_block_coverage' => 1.0,
                'page_record_coverage' => 1.0,
                'block_provenance_coverage' => 1.0,
            ],
        ],
        'figures' => [],
    ]);

    $failResult = hub_docparser_acceptance_result($db, $failTaskId, $fixture);
    hub_test_assert(($failResult['ok'] ?? true) === false, 'acceptance must fail when artifact content breaks quality checks');
    hub_test_assert(in_array('toc_broken_anchor_count', $failResult['failures'] ?? [], true), 'acceptance must flag broken toc anchors');
    hub_test_assert(in_array('protected_token_preservation', $failResult['failures'] ?? [], true), 'acceptance must flag protected token preservation regressions');
});

hub_test('DocParser acceptance recomputes metrics when quality report lies', function (): void {
    require_once HUB_ROOT . '/scripts/docparser_acceptance.php';

    $db = hub_test_reset_db();
    $fixture = [
        'expected_page_count_min' => 1,
        'minimum_heading_count' => 1,
        'minimum_table_count' => 0,
        'expected_figure_count_min' => 0,
        'required_toc_titles' => ['General Information'],
        'required_translations' => ['General Information' => '一般資訊'],
        'protected_tokens' => ['FZR150'],
        'quality_thresholds' => [
            'page_record_coverage' => 1.0,
            'block_provenance_coverage' => 1.0,
            'broken_asset_links' => 0,
            'toc_broken_anchor_count' => 0,
            'required_artifact_integrity' => 1.0,
            'translation_block_coverage' => 1.0,
            'translation_identity_ratio_max' => 0.10,
            'protected_token_preservation' => 1.0,
        ],
    ];

    $taskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'profile' => 'technical_manual',
        'input_file' => '/tmp/input.pdf',
        'target_language' => 'zh-TW',
        'translation_required' => '1',
    ], null, '127.0.0.1');
    hub_store_docparser_task_artifacts($db, $taskId, [
        'manifest' => ['status' => 'completed'],
        'reader_html' => '<html><body><h1 id="p1-b1">General Information</h1><p id="p1-b2">FZR150 Inspection</p></body></html>',
        'bilingual_html' => '<html lang="zh-Hant"><body><section id="p1-b1"><h1>General Information</h1><p lang="zh-TW">General Information</p></section><section id="p1-b2"><p>FZR150 Inspection</p><p lang="zh-TW">FZR150 Inspection</p></section></body></html>',
        'markdown' => "# General Information\n\nFZR150 Inspection\n",
        'docir' => [
            'schema' => '3wa-docir-v0.1',
            'pages' => [['page' => 1]],
            'blocks' => [
                [
                    'id' => 'p1-b1',
                    'page' => 1,
                    'order' => 1,
                    'type' => 'heading',
                    'source_text' => 'General Information',
                    'translation' => ['language' => 'zh-TW', 'text' => 'General Information', 'source_block_id' => 'p1-b1'],
                    'provenance' => ['source_block_id' => 'raw-1'],
                ],
                [
                    'id' => 'p1-b2',
                    'page' => 1,
                    'order' => 2,
                    'type' => 'paragraph',
                    'source_text' => 'FZR150 Inspection',
                    'translation' => ['language' => 'zh-TW', 'text' => 'FZR150 Inspection', 'source_block_id' => 'p1-b2'],
                    'provenance' => ['source_block_id' => 'raw-2'],
                ],
            ],
            'figures' => [],
        ],
        'toc' => [['title' => 'General Information', 'anchor' => 'p1-b1']],
        'rag_chunks' => [['block_id' => 'p1-b1', 'page' => 1], ['block_id' => 'p1-b2', 'page' => 1]],
        'quality_report' => [
            'status' => 'completed',
            'metrics' => [
                'broken_asset_links' => 0,
                'translation_identity_ratio' => 0.0,
                'protected_token_preservation' => 1.0,
                'required_artifact_integrity' => 1.0,
                'translation_block_coverage' => 1.0,
                'page_record_coverage' => 1.0,
                'block_provenance_coverage' => 1.0,
            ],
        ],
        'figures' => [],
    ]);

    $result = hub_docparser_acceptance_result($db, $taskId, $fixture);
    hub_test_assert(($result['ok'] ?? true) === false, 'acceptance must fail when recomputed metrics disagree with a lying quality report');
    hub_test_assert(in_array('translation_identity_ratio', $result['failures'] ?? [], true), 'acceptance must recompute translation_identity_ratio');
    hub_test_assert(in_array('required_translations_present', $result['failures'] ?? [], true), 'acceptance must fail missing required translations from artifact content');
});
