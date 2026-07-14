<?php
declare(strict_types=1);

hub_test('Nemotron RAG adapter pack exposes text-only embed and rerank contract', function (): void {
    $pack = hub_get_pack('rag-nemotron');
    hub_test_assert($pack !== null, 'rag-nemotron pack missing');
    hub_test_assert($pack['status'] === 'ok', 'rag-nemotron pack invalid: ' . implode(', ', $pack['errors'] ?? []));

    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L3-adapter', 'Nemotron RAG starts as adapter level');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'Nemotron RAG target level mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/rag', 'Nemotron RAG gateway endpoint mismatch');
    hub_test_assert(in_array('embedding', $manifest['capabilities'] ?? [], true), 'Nemotron RAG embedding capability missing');
    hub_test_assert(in_array('reranking', $manifest['capabilities'] ?? [], true), 'Nemotron RAG reranking capability missing');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? true) === false, 'Nemotron RAG adapter must keep CPU fallback available');

    $schema = hub_get_pack_settings_schema('rag-nemotron');
    foreach (['NEMOTRON_EMBED_MODEL', 'NEMOTRON_RERANK_MODEL', 'NEMOTRON_EMBED_URL', 'NEMOTRON_RERANK_URL', 'NEMOTRON_API_KEY', 'NEMOTRON_REAL_INFERENCE'] as $key) {
        hub_test_assert(isset($schema[$key]), 'Nemotron RAG settings missing ' . $key);
    }
    hub_test_assert(($schema['NEMOTRON_EMBED_MODEL']['default'] ?? '') === 'nvidia/llama-nemotron-embed-300m-v2', 'Nemotron embed model default mismatch');
    hub_test_assert(($schema['NEMOTRON_RERANK_MODEL']['default'] ?? '') === 'nvidia/llama-nemotron-rerank-500m-v2', 'Nemotron rerank model default mismatch');

    $contract = $manifest['l5_contract'] ?? [];
    $inputFields = array_column($contract['input']['fields'] ?? [], 'name');
    foreach (['operation', 'texts', 'query', 'passages', 'top_k', 'real_inference'] as $field) {
        hub_test_assert(in_array($field, $inputFields, true), 'Nemotron RAG contract missing input ' . $field);
    }
    foreach (['ok', 'mock', 'runtime_level', 'operation', 'model', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'Nemotron RAG contract output missing ' . $key);
    }
    $caseIds = array_column($contract['benchmark']['cases'] ?? [], 'id');
    hub_test_assert(in_array('nemotron_rag_mock_rerank', $caseIds, true), 'Nemotron RAG rerank benchmark missing');
    hub_test_assert(in_array('nemotron_rag_mock_embed', $caseIds, true), 'Nemotron RAG embed benchmark missing');
    hub_test_assert(in_array('nemotron_rag_real_rerank', $caseIds, true), 'Nemotron RAG real rerank benchmark missing');
    hub_test_assert(in_array('nemotron_rag_real_embed', $caseIds, true), 'Nemotron RAG real embed benchmark missing');
    foreach ($contract['benchmark']['cases'] ?? [] as $case) {
        if (str_starts_with((string)($case['id'] ?? ''), 'nemotron_rag_real_')) {
            hub_test_assert(!empty($case['real_inference']), 'Nemotron RAG real benchmark must be marked real_inference');
            hub_test_assert(($case['expected_mock'] ?? null) === false, 'Nemotron RAG real benchmark must assert mock=false');
        }
    }
});

hub_test('Nemotron RAG adapter install generates compose env and mock benchmarks', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'rag-nemotron', [
        'service_key' => 'nemotron-rag',
        'name' => 'Nemotron RAG',
        'mode' => 'rag',
        'port_mode' => 'manual',
        'local_port' => 18111,
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    hub_test_assert($service['service_key'] === 'nemotron-rag', 'Nemotron RAG service key mismatch');
    hub_test_assert(str_ends_with((string)$service['internal_url'], '/rag'), 'Nemotron RAG internal URL mismatch');

    $compose = (string)file_get_contents(hub_path((string)$service['compose_file']));
    foreach (['3waaihub-nemotron-rag:0.1.0', '127.0.0.1:${NEMOTRON_RAG_LOCAL_PORT:-18111}:8000', '${AIHUB_MODELS_DIR}/nemotron:/models/nemotron'] as $needle) {
        hub_test_assert(str_contains($compose, $needle), 'Nemotron RAG compose missing ' . $needle);
    }
    hub_test_assert(str_contains($compose, 'gpus: all'), 'Nemotron RAG compose must request GPU by default');
    hub_test_assert(str_contains($compose, 'NVIDIA_VISIBLE_DEVICES'), 'Nemotron RAG compose must expose NVIDIA devices');

    $env = (string)file_get_contents(dirname(hub_path((string)$service['compose_file'])) . '/.env');
    foreach (['NEMOTRON_EMBED_MODEL=nvidia/llama-nemotron-embed-300m-v2', 'NEMOTRON_RERANK_MODEL=nvidia/llama-nemotron-rerank-500m-v2', 'NEMOTRON_REAL_INFERENCE=0', 'NEMOTRON_USE_GPU=1', 'NEMOTRON_DEVICE=auto', 'GPU_VISIBLE_DEVICES=all', 'NEMOTRON_GPU_FALLBACK_TO_CPU=1'] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'Nemotron RAG env missing ' . $needle);
    }

    $rerank = hub_run_benchmark_case($db, 'nemotron_rag_mock_rerank', 'rag-nemotron');
    hub_test_assert($rerank['status'] === 'pass', 'Nemotron RAG rerank mock benchmark failed');
    hub_test_assert(($rerank['result']['mock'] ?? null) === true, 'Nemotron RAG rerank benchmark must stay mock');

    $embed = hub_run_benchmark_case($db, 'nemotron_rag_mock_embed', 'rag-nemotron');
    hub_test_assert($embed['status'] === 'pass', 'Nemotron RAG embed mock benchmark failed');
    hub_test_assert(($embed['result']['result_count'] ?? 0) >= 1, 'Nemotron RAG embed benchmark result count missing');
});

hub_test('Nemotron RAG service app keeps real inference behind configured backend URLs', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/rag-nemotron/service/app.py');
    foreach (['@app.post("/rag")', '@app.post("/embed")', '@app.post("/rerank")', 'runtime_not_configured', 'NEMOTRON_EMBED_URL', 'NEMOTRON_RERANK_URL'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'Nemotron RAG app missing ' . $needle);
    }
    foreach (['sentence_transformers', 'AutoModel', 'from transformers', 'torch'] as $deferred) {
        hub_test_assert(!str_contains($app, $deferred), 'Nemotron RAG adapter must not bundle local model runtime yet: ' . $deferred);
    }
});
