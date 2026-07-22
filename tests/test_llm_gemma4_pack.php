<?php
declare(strict_types=1);

hub_test('Gemma 4 LLM pack stays inside 3waAIHub sync API boundary', function (): void {
    $pack = hub_get_pack('llm-gemma4-12b');
    hub_test_assert($pack !== null, 'llm-gemma4-12b pack missing');
    hub_test_assert($pack['status'] === 'ok', 'llm-gemma4-12b pack invalid: ' . implode(', ', $pack['errors'] ?? []));

    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['schema_version'] ?? '') === '0.1', 'LLM schema version mismatch');
    hub_test_assert(($manifest['type'] ?? '') === 'api_service', 'LLM type mismatch');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'LLM execution_type mismatch');
    hub_test_assert(($manifest['runtime']['kind'] ?? '') === 'docker', 'LLM runtime kind mismatch');
    hub_test_assert(($manifest['runtime']['compose_file'] ?? '') === 'docker-compose.yml', 'LLM compose file mismatch');
    hub_test_assert(!isset($manifest['runtime']['image']), 'LLM pack must not expose direct runtime.image core shortcut');
    hub_test_assert(!isset($manifest['runtime']['command']), 'LLM pack must not expose vLLM command through core runtime');

    hub_test_assert(($manifest['gateway']['health_path'] ?? '') === '/health', 'LLM health path mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/chat', 'LLM invoke path must be Hub adapter /chat');
    foreach (['protocol', 'response_modes', 'streaming_supported'] as $field) {
        hub_test_assert(!array_key_exists($field, $manifest['gateway'] ?? []), 'LLM gateway must not add core field ' . $field);
    }

    foreach (['chat', 'reasoning', 'vision', 'audio_understanding', 'audio_transcription'] as $capability) {
        hub_test_assert(in_array($capability, $manifest['capabilities'] ?? [], true), 'LLM capability missing ' . $capability);
    }
    foreach (['streaming', 'tool_calling', 'structured_output'] as $deferred) {
        hub_test_assert(!in_array($deferred, $manifest['capabilities'] ?? [], true), 'LLM deferred capability leaked: ' . $deferred);
    }
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? false) === true, 'LLM GPU requirement missing');

    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(($contract['endpoint'] ?? '') === '/chat', 'LLM contract endpoint mismatch');
    hub_test_assert(($contract['content_type'] ?? '') === 'application/json', 'LLM contract content-type mismatch');
    $inputFields = array_column($contract['input']['fields'] ?? [], 'name');
    foreach (['text', 'system_prompt', 'temperature', 'max_tokens', 'enable_thinking', 'real_inference'] as $field) {
        hub_test_assert(in_array($field, $inputFields, true), 'LLM contract missing input ' . $field);
    }
    foreach (['messages', 'stream', 'tools', 'response_format'] as $field) {
        hub_test_assert(!in_array($field, $inputFields, true), 'LLM contract must not expose OpenAI field ' . $field);
    }
    foreach (['image_id', 'image_internal_path'] as $photoField) {
        hub_test_assert(!in_array($photoField, $inputFields, true), 'LLM chat contract leaked photo input ' . $photoField);
    }
    foreach (['ok', 'mock', 'runtime_level', 'model', 'text', 'usage', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'LLM contract output missing ' . $key);
    }
    foreach (['answer', 'caption', 'tags'] as $photoOnlyKey) {
        hub_test_assert(!in_array($photoOnlyKey, $contract['output']['required_keys'] ?? [], true), 'LLM chat contract leaked photo output ' . $photoOnlyKey);
    }
    foreach (['bad_request', 'input_too_long', 'vllm_unavailable', 'model_not_present', 'vllm_timeout', 'vllm_bad_response', 'chat_failed'] as $errorCode) {
        hub_test_assert(in_array($errorCode, $contract['errors'] ?? [], true), 'LLM contract errors missing ' . $errorCode);
    }

    $caseIds = array_column($contract['benchmark']['cases'] ?? [], 'id');
    hub_test_assert(in_array('gemma4_mock_chat', $caseIds, true), 'LLM mock benchmark missing');
    hub_test_assert(in_array('gemma4_real_chat', $caseIds, true), 'LLM real benchmark missing');
    foreach (['streaming_chat', 'vision_chat', 'tool_call', 'structured_json'] as $deferredCase) {
        hub_test_assert(!in_array($deferredCase, $caseIds, true), 'LLM deferred benchmark leaked: ' . $deferredCase);
    }

    hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/admin/playground.php'), "'chat' =>"), 'Playground must support chat mode');
    hub_test_assert(in_array('chat', hub_playground_supported_modes(), true), 'Customer playground allowlist must include chat mode');
});

hub_test('Gemma 4 LLM install generates vLLM sidecar plus Hub chat adapter compose', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'llm-gemma4-12b', [
        'service_key' => 'gemma4-main',
        'name' => 'Gemma 4 Main',
        'mode' => 'chat',
        'port_mode' => 'manual',
        'local_port' => 18110,
        'environment' => 'production',
        'idempotent' => true,
    ]);

    $service = hub_get_service_by_key($db, 'gemma4-main');
    hub_test_assert($service !== null, 'gemma4-main service missing');
    hub_test_assert(($service['execution_type'] ?? '') === 'sync_api', 'LLM service execution_type mismatch');
    hub_test_assert((int)($service['local_port'] ?? 0) === 18110, 'LLM service local port mismatch');
    hub_test_assert(str_ends_with((string)($service['internal_url'] ?? ''), '/chat'), 'LLM internal URL must call adapter /chat');

    $compose = (string)file_get_contents(hub_path((string)$service['compose_file']));
    foreach ([
        '  vllm:',
        'image: 3waaihub-gemma4-vllm:0.1.0',
        'context: ' . HUB_ROOT . '/packs/llm-gemma4-12b/vllm',
        '--limit-mm-per-prompt \'{"image":1,"audio":1}\'',
        '  chat-api:',
        'build:',
        'depends_on:',
        '127.0.0.1:${GEMMA4_LOCAL_PORT:-18110}:8000',
        '${AIHUB_MODELS_DIR}/huggingface:/root/.cache/huggingface',
        '${AIHUB_CACHE_DIR}/gemma4:/cache/gemma4',
        '${SERVICE_DATA_DIR}:/data/service',
        '${AIHUB_UPLOADS_DIR}/photo:/data/photo:ro',
    ] as $needle) {
        hub_test_assert(str_contains($compose, $needle), 'LLM generated compose missing ' . $needle);
    }
    hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/packs/llm-gemma4-12b/vllm/Dockerfile'), 'vllm[audio]'), 'Gemma4 vLLM Dockerfile must install audio extras');
    foreach (['--enable-auto-tool-choice', 'streaming_api'] as $forbidden) {
        hub_test_assert(!str_contains($compose, $forbidden), 'LLM generated compose leaked deferred feature ' . $forbidden);
    }

    $env = (string)file_get_contents(dirname(hub_path((string)$service['compose_file'])) . '/.env');
    foreach (['VLLM_BASE_URL=http://vllm:8000', 'GEMMA4_REAL_INFERENCE=0', 'MAX_INPUT_CHARS=12000'] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'LLM generated env missing ' . $needle);
    }
});

hub_test('Gemma 4 playground source uses Hub chat payload instead of OpenAI-compatible payload', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($page, "'chat' =>"), 'Playground chat profile missing');
    foreach (['system_prompt', 'enable_thinking', 'real_inference', 'mode=chat', 'non-streaming JSON'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'chat playground missing ' . $needle);
    }
    foreach (["'messages' =>", "'stream' => false", 'chat_template_kwargs', '/v1/chat/completions'] as $forbidden) {
        hub_test_assert(!str_contains($page, $forbidden), 'chat playground must not expose OpenAI payload piece ' . $forbidden);
    }
});

hub_test('Gemma 4 photo adapter stays inside Hub image_id contract', function (): void {
    $manifest = hub_get_pack('llm-gemma4-12b')['manifest'];
    $contract = $manifest['photo_contract'] ?? [];
    hub_test_assert(is_array($contract), 'photo_contract missing');
    $inputFields = array_column($contract['input']['fields'] ?? [], 'name');
    foreach (['image_id', 'text', 'max_tokens', 'real_inference'] as $field) {
        hub_test_assert(in_array($field, $inputFields, true), 'photo contract missing ' . $field);
    }
    foreach (['image_path', 'host_path', 'container_path', 'storage_relpath', 'image_url'] as $field) {
        hub_test_assert(!in_array($field, $inputFields, true), 'photo contract leaks path field ' . $field);
    }
    $app = (string)file_get_contents(HUB_ROOT . '/packs/llm-gemma4-12b/service/app.py');
    foreach (['@app.post("/photo")', 'image_internal_path', '/data/photo', 'vision_failed'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'Gemma photo adapter missing ' . $needle);
    }
    $photoCases = array_filter($contract['benchmark']['cases'] ?? [], fn (array $case): bool => ($case['mode'] ?? '') === 'photo');
    foreach ($photoCases as $case) {
        foreach (['answer', 'caption', 'tags'] as $key) {
            hub_test_assert(in_array($key, $case['expected_keys'] ?? [], true), 'photo benchmark missing expected key ' . $key);
        }
    }
});

hub_test('Gemma 4 photo playground and docs expose image_id workflow', function (): void {
    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (['mode=photo', 'photo_upload', 'image_id', '圖片問答', 'max_tokens'] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'photo playground missing ' . $needle);
    }
    foreach (['host_path', 'container_path', 'storage_relpath'] as $forbidden) {
        hub_test_assert(!str_contains($playground, 'name="' . $forbidden . '"'), 'playground must not expose ' . $forbidden);
    }
    $examples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    foreach (['mode=photo_upload', 'mode=photo', 'image_id', '<TOKEN>'] as $needle) {
        hub_test_assert(str_contains($examples, $needle), 'photo API examples missing ' . $needle);
    }
});
