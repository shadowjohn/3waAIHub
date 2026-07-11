<?php
declare(strict_types=1);

hub_test('PP-StructureV3 pack exposes L5 document parser contract', function (): void {
    $pack = hub_get_pack('structure-ppstructurev3');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'PP-StructureV3 pack must be valid');

    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'PP-StructureV3 runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'PP-StructureV3 target level mismatch');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'structure', 'PP-StructureV3 default mode mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/v1/parse', 'PP-StructureV3 gateway endpoint mismatch');
    hub_test_assert((int)($manifest['gateway']['timeout_sec'] ?? 0) >= 1800, 'PP-StructureV3 gateway timeout must allow long PDF manuals');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'async_task', 'PP-StructureV3 should declare async_task execution');

    $base = HUB_ROOT . '/packs/structure-ppstructurev3/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'PP-StructureV3 service missing ' . $file);
    }

    $app = (string)file_get_contents($base . '/app.py');
    foreach (['@app.get("/health")', '@app.post("/v1/parse")', 'PPStructureV3', 'save_to_json', 'save_to_markdown', 'runtime_dependency_missing', 'L5-benchmark-ready'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'PP-StructureV3 app missing ' . $needle);
    }

    $requirements = (string)file_get_contents($base . '/requirements.txt');
    foreach (['paddleocr[doc-parser]==3.7.0', 'paddlepaddle_gpu-3.3.1-cp311-cp311-linux_x86_64.whl', 'numpy>=1.24,<2.4'] as $needle) {
        hub_test_assert(str_contains($requirements, $needle), 'PP-StructureV3 requirements must pin ' . $needle);
    }

    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(is_array($contract), 'PP-StructureV3 l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'limits', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $contract), 'PP-StructureV3 l5_contract missing ' . $field);
    }
    hub_test_assert(($contract['endpoint'] ?? '') === '/v1/parse', 'PP-StructureV3 contract endpoint mismatch');
    foreach (['ok', 'mock', 'runtime_level', 'output_format', 'result_count', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'PP-StructureV3 contract output missing ' . $key);
    }
    $cases = $contract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('structure_page_pdf', array_column($cases, 'id'), true), 'structure_page_pdf benchmark missing');
    hub_test_assert(in_array('structure_10page_pdf', array_column($cases, 'id'), true), 'structure_10page_pdf benchmark missing');
    foreach ($cases as $case) {
        if (in_array($case['id'] ?? '', ['structure_page_pdf', 'structure_10page_pdf'], true)) {
            hub_test_assert(!empty($case['real_inference']), 'Structure PDF benchmark must be real inference');
            hub_test_assert(($case['fixture_field'] ?? '') === 'file', 'Structure PDF benchmark must upload using file field');
        }
    }

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (["'structure' =>", 'api.php?mode=structure', 'accept="application/pdf,image/*"', '真 PP-StructureV3 解析'] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'Playground missing PP-StructureV3 entry: ' . $needle);
    }

    hub_test_assert(hub_is_valid_task_type('structure_parse'), 'structure_parse task type must be allowlisted');

    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/task_worker.php');
    hub_test_assert(str_contains($worker, "'structure-ppstructurev3'"), 'structure worker must restrict structure_parse to PP-StructureV3 service');

    $benchmark = (string)file_get_contents(HUB_ROOT . '/app/benchmarks.php');
    foreach (['fixture_field', 'expected_min_result_count', 'expected_markdown_non_empty'] as $needle) {
        hub_test_assert(str_contains($benchmark, $needle), 'benchmark runner missing Structure support ' . $needle);
    }
});

hub_test('PP-StructureV3 service instance generates storage env and compose', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'structure-ppstructurev3', [
        'service_key' => 'structure-test-main',
        'mode' => 'structure_test',
        'name' => 'Structure Test Main',
        'port_mode' => 'manual',
        'local_port' => 18180,
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(str_contains($compose, '127.0.0.1:${STRUCTURE_LOCAL_PORT:-18180}:8000'), 'PP-StructureV3 compose must bind localhost');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/ppstructurev3:/models/ppstructurev3'), 'PP-StructureV3 compose must mount models');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/ppstructurev3:/cache/ppstructurev3'), 'PP-StructureV3 compose must mount cache');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'PP-StructureV3 compose must mount service data');

    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    foreach ([
        'STRUCTURE_MODEL_DIR=/models/ppstructurev3',
        'STRUCTURE_CACHE_DIR=/cache/ppstructurev3',
        'STRUCTURE_SERVICE_DATA_DIR=/data/service',
        'STRUCTURE_REAL_INFERENCE=1',
        'STRUCTURE_DEVICE=cpu',
        'HOME=/models/ppstructurev3/home',
        'PYTHONUNBUFFERED=1',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'PP-StructureV3 env missing ' . $needle);
    }
});

hub_test('PP-StructureV3 task artifacts store markdown and JSON results', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'structure_parse', 'ocr', 0, [
        'mode' => 'structure',
        'input_file' => '/tmp/input.pdf',
        'output_format' => 'both',
    ], null, '127.0.0.1');

    $artifactSummary = hub_store_structure_task_artifacts($db, $taskId, [
        'ok' => true,
        'mock' => false,
        'runtime_level' => 'L4-real-inference',
        'markdown' => "# Parsed\n\nHello",
        'document_json' => ['title' => 'Parsed', 'blocks' => [['type' => 'text', 'text' => 'Hello']]],
    ]);

    hub_test_assert(($artifactSummary['markdown']['artifact_id'] ?? 0) > 0, 'markdown artifact id missing');
    hub_test_assert(($artifactSummary['json']['artifact_id'] ?? 0) > 0, 'json artifact id missing');

    $stmt = $db->prepare('SELECT name, mime_type, path FROM task_artifacts WHERE task_id = :task_id ORDER BY id ASC');
    $stmt->execute([':task_id' => $taskId]);
    $artifacts = $stmt->fetchAll();
    hub_test_assert(count($artifacts) === 2, 'structure task must register two artifacts');
    hub_test_assert((string)$artifacts[0]['name'] === 'structure_result.md', 'markdown artifact name mismatch');
    hub_test_assert((string)$artifacts[0]['mime_type'] === 'text/markdown', 'markdown artifact mime mismatch');
    hub_test_assert(is_file((string)$artifacts[0]['path']), 'markdown artifact file missing');
    hub_test_assert((string)$artifacts[1]['name'] === 'structure_result.json', 'json artifact name mismatch');
    hub_test_assert((string)$artifacts[1]['mime_type'] === 'application/json', 'json artifact mime mismatch');
    hub_test_assert(is_file((string)$artifacts[1]['path']), 'json artifact file missing');
});
