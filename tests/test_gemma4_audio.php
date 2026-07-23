<?php
declare(strict_types=1);

hub_test('Gemma4 audio direct vLLM smoke script and WAV fixture contract exist', function (): void {
    $root = HUB_ROOT . '/packs/llm-gemma4-12b';
    $script = $root . '/scripts/smoke_audio_vllm.py';
    $fixture = $root . '/demo/audio_zh_smoke.wav';

    hub_test_assert(is_file($script), 'Gemma4 audio vLLM smoke script is missing.');
    $source = (string)file_get_contents($script);
    foreach (['input_audio', 'wave.open', '--base-url', '--model', '--audio'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'Gemma4 audio smoke script missing ' . $needle);
    }

    hub_test_assert(is_file($fixture), 'Gemma4 audio WAV fixture is missing.');
    $info = trim((string)shell_exec('python3 - <<' . escapeshellarg('PY') . "\n"
        . 'import json, wave' . "\n"
        . 'path = ' . var_export($fixture, true) . "\n"
        . 'with wave.open(path, "rb") as wav:' . "\n"
        . '    frames = wav.getnframes()' . "\n"
        . '    rate = wav.getframerate()' . "\n"
        . '    print(json.dumps({"channels": wav.getnchannels(), "rate": rate, "duration": frames / rate, "sampwidth": wav.getsampwidth()}))' . "\n"
        . "PY"));
    $meta = json_decode($info, true);
    hub_test_assert(is_array($meta), 'Gemma4 audio WAV fixture metadata could not be read.');
    hub_test_assert((int)$meta['channels'] === 1, 'Gemma4 audio fixture must be mono.');
    hub_test_assert((int)$meta['rate'] === 16000, 'Gemma4 audio fixture must be 16 kHz.');
    hub_test_assert((float)$meta['duration'] > 0 && (float)$meta['duration'] <= 30.0, 'Gemma4 audio fixture must be <= 30 seconds.');
});

hub_test('Gemma4 audio adapter exposes one-shot WAV endpoint contract', function (): void {
    $root = HUB_ROOT . '/packs/llm-gemma4-12b';
    $requirements = (string)file_get_contents($root . '/service/requirements.txt');
    hub_test_assert(str_contains($requirements, 'python-multipart'), 'Gemma4 audio adapter needs multipart upload support.');

    $app = (string)file_get_contents($root . '/service/app.py');
    foreach ([
        '@app.post("/audio")',
        'wave.open',
        'input_audio',
        'file_required',
        'payload_too_large',
        'invalid_audio',
        'unsupported_audio_format',
        'audio_too_long',
        '"answer"',
        '"transcript"',
        '"summary"',
    ] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'Gemma4 audio adapter missing ' . $needle);
    }
    hub_test_assert(str_contains($app, '"role": "system"'), 'Gemma4 audio adapter must send system message.');
    hub_test_assert(str_contains($app, 'precise audio analysis assistant'), 'Gemma4 audio adapter system prompt missing.');
    hub_test_assert(str_contains($app, 'Chinese voice'), 'Gemma4 audio adapter must use English audio prompt hint.');
    hub_test_assert(strpos($app, '{"type": "text", "text": audio_prompt') < strpos($app, '"type": "input_audio"'), 'Gemma4 audio adapter must send text prompt before audio.');

    $smoke = (string)file_get_contents($root . '/scripts/smoke_audio_vllm.py');
    hub_test_assert(str_contains($smoke, '"role": "system"'), 'Gemma4 audio smoke script must send system message.');
    hub_test_assert(str_contains($smoke, 'Chinese voice. What did the speaker say?'), 'Gemma4 audio smoke script must use English audio prompt hint.');
    hub_test_assert(strpos($smoke, 'Chinese voice. What did the speaker say?') < strpos($smoke, '"type": "input_audio"'), 'Gemma4 audio smoke script must send text prompt before audio.');
});

hub_test('Gemma4 audio test assets use private temporary storage', function (): void {
    $root = hub_audio_upload_root();
    $tempRoot = realpath(sys_get_temp_dir());
    hub_test_assert($tempRoot !== false, 'test temp root missing');
    hub_test_assert(hub_audio_upload_root() !== HUB_DATA_DIR . '/uploads/audio', 'test audio assets must not use production uploads');
    hub_test_assert(str_starts_with($root, rtrim($tempRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR), 'test audio assets must use the system temp directory');
    hub_test_assert(preg_match('/^3waaihub_test_audio_assets_[a-f0-9]{32}$/', basename($root)) === 1, 'test audio assets must use a random directory name');
    hub_test_assert((fileperms($root) & 0777) === 0700, 'test audio assets must use private storage');

    $link = sys_get_temp_dir() . '/3waaihub_test_audio_assets_link_' . bin2hex(random_bytes(8));
    hub_test_assert(symlink($root, $link), 'cannot create test audio asset symlink');
    try {
        hub_test_assert(hub_test_throws(static fn (): string => hub_test_audio_asset_cleanup_dir($link)), 'test cleanup must refuse a symlinked audio asset directory');
    } finally {
        @unlink($link);
    }
});

hub_test('Gemma4 audio gateway mode validates upload and customer permission', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_install_pack($db, 'llm-gemma4-12b', [
        'service_key' => 'gemma4-main',
        'name' => 'Gemma 4 Main',
        'mode' => 'chat',
        'local_port' => 18110,
        'port_mode' => 'manual',
        'idempotent' => true,
    ]);
    $service = hub_get_service_by_key($db, 'gemma4-main');
    hub_test_assert($service !== null, 'gemma4-main service missing.');
    hub_update_service_status($db, (int)$service['id'], 'running');

    $memberId = hub_create_api_member($db, 'Audio API', '', 'audio@example.test', '');
    $token = hub_create_api_token($db, $memberId, 'Audio token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'audio', null);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.40', '');

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    try {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=audio';
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
        $_FILES = [];
        $_POST = [];

        $missing = hub_gateway_dispatch($db, 'audio');
        hub_test_assert((int)$missing['status'] === 400, 'audio gateway must validate missing upload.');
        hub_test_assert(str_contains((string)$missing['body'], 'file_required'), 'audio gateway must return file_required.');

        $_POST = ['audio_path' => '/tmp/client.wav'];
        $blocked = hub_gateway_dispatch($db, 'audio');
        hub_test_assert((int)$blocked['status'] === 400, 'audio gateway must reject client path fields.');
        hub_test_assert(str_contains((string)$blocked['body'], 'bad_request'), 'audio gateway path rejection must use bad_request.');
    } finally {
        $_SERVER = $oldServer;
        $_FILES = $oldFiles;
        $_POST = $oldPost;
    }

    hub_test_assert(hub_is_audio_api_mode('audio'), 'audio must be a protected internal Gateway mode.');
    hub_test_assert(in_array('audio', hub_playground_supported_modes(), true), 'playground supported modes must include audio.');

    $customerId = hub_create_customer_user($db, [
        'username' => 'audio_customer',
        'password' => 'password123',
        'display_name' => 'Audio Customer',
        'modes' => ['audio'],
    ]);
    $customerToken = hub_create_customer_token($db, $customerId, 'Audio customer token');
    $modes = array_column(hub_list_api_token_permissions($db, (int)$customerToken['token_id']), 'mode');
    hub_test_assert(in_array('audio', $modes, true), 'customer token must include audio mode.');
    hub_test_assert(in_array('audio_upload', $modes, true), 'customer token must include audio_upload helper mode.');
});

hub_test('Gemma4 audio asset upload stores short WAV with owner and TTL', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Audio Owner', '', 'audio-owner@example.test', '');
    $token = hub_create_api_token($db, $memberId, 'audio token', null, null);
    $fixture = HUB_ROOT . '/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav';

    $asset = hub_audio_store_upload($db, [
        'name' => 'client-name.wav',
        'type' => 'audio/x-wav',
        'tmp_name' => $fixture,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fixture),
    ], ['member_id' => $memberId, 'token_id' => (int)$token['token_id']]);

    hub_test_assert(preg_match('/^aud_[A-Za-z0-9_-]{20,64}$/', (string)$asset['audio_id']) === 1, 'audio_id format mismatch');
    hub_test_assert((string)$asset['mime'] === 'audio/wav', 'stored audio mime must be normalized');
    hub_test_assert((int)$asset['duration_ms'] === 6000, 'audio duration mismatch');
    hub_test_assert((int)$asset['sample_rate'] === 16000, 'audio sample rate mismatch');
    hub_test_assert((int)$asset['channels'] === 1, 'audio channels mismatch');
    $storedPath = hub_audio_asset_host_path($asset);
    hub_test_assert($storedPath !== null && is_file($storedPath), 'stored audio missing');
    hub_test_assert((string)$asset['storage_relpath'] === 'uploads/audio/' . $asset['audio_id'] . '/original.wav', 'audio storage path format mismatch');
    hub_test_assert(hub_audio_upload_root() !== HUB_DATA_DIR . '/uploads/audio', 'test audio assets must not use production uploads');
    hub_test_assert(str_starts_with((string)$storedPath, hub_audio_upload_root() . DIRECTORY_SEPARATOR), 'test audio asset must stay in isolated storage');
    hub_test_assert(!str_contains((string)$asset['storage_relpath'], 'client-name'), 'client filename must not be used as storage path');
    hub_test_assert(hub_audio_get_asset_for_auth($db, (string)$asset['audio_id'], ['member_id' => $memberId]) !== null, 'owner member must read audio asset');
});

hub_test('Gemma4 audio asset validation rejects unsafe uploads and enforces ownership', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Audio A', '', 'audio-a@example.test', '');
    $memberB = hub_create_api_member($db, 'Audio B', '', 'audio-b@example.test', '');
    $tokenA = hub_create_api_token($db, $memberA, 'A token', null, null);
    $tokenB = hub_create_api_token($db, $memberB, 'B token', null, null);
    $fake = tempnam(sys_get_temp_dir(), 'fake_audio_');
    file_put_contents($fake, 'not a wav');

    hub_test_assert(hub_test_throws(fn () => hub_audio_store_upload($db, [
        'name' => 'fake.wav',
        'type' => 'audio/wav',
        'tmp_name' => $fake,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fake),
    ], ['member_id' => $memberA, 'token_id' => (int)$tokenA['token_id']])), 'fake WAV must be rejected');

    $fixture = HUB_ROOT . '/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav';
    $asset = hub_audio_store_upload($db, [
        'name' => 'ok.wav',
        'type' => 'audio/wav',
        'tmp_name' => $fixture,
        'error' => UPLOAD_ERR_OK,
        'size' => filesize($fixture),
    ], ['member_id' => $memberA, 'token_id' => (int)$tokenA['token_id']]);

    hub_test_assert(hub_audio_get_asset_for_auth($db, (string)$asset['audio_id'], ['member_id' => $memberA]) !== null, 'owner member must read asset');
    hub_test_assert(hub_audio_get_asset_for_auth($db, (string)$asset['audio_id'], ['member_id' => $memberB, 'token_id' => (int)$tokenB['token_id']]) === null, 'other member must not read asset');
});

hub_test('Gemma4 audio upload helper and audio_id path are protected Gateway modes', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $memberId = hub_create_api_member($db, 'Audio Upload API', '', 'audio-upload@example.test', '');
    $token = hub_create_api_token($db, $memberId, 'Audio upload token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'audio_upload', null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'audio', null);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.41', '');
    $fixture = HUB_ROOT . '/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav';

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = ['audio' => ['name' => 'ok.wav', 'type' => 'audio/wav', 'tmp_name' => $fixture, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fixture)]];
    $_POST = [];
    $upload = hub_gateway_dispatch($db, 'audio_upload');
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;

    $payload = json_decode((string)$upload['body'], true);
    hub_test_assert((int)$upload['status'] === 200, 'audio_upload must pass');
    hub_test_assert(($payload['ok'] ?? false) === true, 'audio_upload ok missing');
    hub_test_assert(preg_match('/^aud_/', (string)($payload['audio_id'] ?? '')) === 1, 'audio_id missing');

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REMOTE_ADDR'] = '203.0.113.41';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    $_FILES = [];
    $_POST = ['audio_id' => (string)$payload['audio_id'], 'operation' => 'understand', 'text' => '這段音訊還有什麼細節？'];
    $ask = hub_gateway_dispatch($db, 'audio');
    $_SERVER = $oldServer; $_FILES = $oldFiles; $_POST = $oldPost;

    hub_test_assert((int)$ask['status'] === 503, 'audio_id path must reach service readiness check instead of file_required');
    hub_test_assert(str_contains((string)$ask['body'], 'model_not_ready'), 'audio_id path must report model_not_ready when service is unavailable');
});

hub_test('Gemma4 audio modes are selectable on admin token permission page', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/api_token_permissions.php');

    hub_test_assert(str_contains($source, 'hub_audio_modes()'), 'token permission page must load audio pseudo modes.');
    hub_test_assert(str_contains($source, 'Audio Mode'), 'token permission page must render audio mode section.');
    hub_test_assert(function_exists('hub_audio_modes'), 'audio mode label helper missing.');
    hub_test_assert(array_keys(hub_audio_modes()) === ['audio_upload', 'audio'], 'audio mode helper must expose upload and ask modes.');
});

hub_test('Gemma4 audio modes are selectable on customer edit page', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/customer_edit.php');

    hub_test_assert(str_contains($source, 'hub_audio_modes()'), 'customer edit page must load audio pseudo modes.');
    hub_test_assert(str_contains($source, 'internal audio API'), 'customer edit page must render audio pseudo mode endpoint.');
});

hub_test('Gemma4 audio gateway proxy success response is normalized', function (): void {
    $response = hub_audio_normalize_proxy_response(hub_gateway_json(200, [
        'ok' => true,
        'operation' => 'transcribe',
        'transcript' => '測試音訊文字',
        'warnings' => ['gemma4_audio_experimental', 'gemma4_audio_not_reliable_asr'],
    ]));
    $body = json_decode((string)$response['body'], true);

    foreach (['ok', 'mock', 'runtime_level', 'model', 'operation', 'answer', 'transcript', 'summary', 'tags', 'warnings', 'audio', 'usage', 'elapsed_ms'] as $key) {
        hub_test_assert(array_key_exists($key, $body), 'audio success response missing ' . $key);
    }
    hub_test_assert($body['ok'] === true, 'audio ok must stay true.');
    hub_test_assert($body['mock'] === false, 'audio mock must default false.');
    hub_test_assert($body['runtime_level'] === 'L5-benchmark-ready', 'audio runtime_level default mismatch.');
    hub_test_assert($body['model'] === 'gemma4-12b', 'audio model default mismatch.');
    hub_test_assert($body['operation'] === 'transcribe', 'audio operation must be preserved.');
    hub_test_assert($body['answer'] === '', 'audio answer must default empty.');
    hub_test_assert($body['summary'] === '', 'audio summary must default empty.');
    hub_test_assert($body['tags'] === [], 'audio tags must default empty array.');
    hub_test_assert($body['warnings'] === ['gemma4_audio_experimental', 'gemma4_audio_not_reliable_asr'], 'audio warnings must be preserved.');
    hub_test_assert($body['usage'] === ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0], 'audio usage default mismatch.');
});

hub_test('Gemma4 audio manifest playground docs and benchmark contract are declared', function (): void {
    $manifest = hub_get_pack('llm-gemma4-12b')['manifest'];
    foreach (['chat', 'reasoning', 'vision', 'audio_understanding', 'audio_transcription'] as $capability) {
        hub_test_assert(in_array($capability, $manifest['capabilities'] ?? [], true), 'Gemma4 capability missing ' . $capability);
    }

    $contract = $manifest['audio_contract'] ?? [];
    hub_test_assert(is_array($contract), 'audio_contract missing.');
    hub_test_assert(($contract['endpoint'] ?? '') === '/audio', 'audio contract endpoint mismatch.');
    hub_test_assert(($contract['content_type'] ?? '') === 'multipart/form-data', 'audio contract content type mismatch.');
    $inputFields = array_column($contract['input']['fields'] ?? [], 'name');
    foreach (['audio', 'operation', 'text', 'max_tokens', 'real_inference'] as $field) {
        hub_test_assert(in_array($field, $inputFields, true), 'audio contract missing input ' . $field);
    }
    foreach (['audio_path', 'host_path', 'container_path', 'audio_url'] as $field) {
        hub_test_assert(!in_array($field, $inputFields, true), 'audio contract leaks path field ' . $field);
    }
    foreach (['ok', 'mock', 'runtime_level', 'model', 'operation', 'answer', 'transcript', 'summary', 'tags', 'warnings', 'audio', 'usage', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'audio contract output missing ' . $key);
    }
    foreach (['file_required', 'payload_too_large', 'invalid_audio', 'unsupported_audio_format', 'audio_too_long', 'audio_failed'] as $errorCode) {
        hub_test_assert(in_array($errorCode, $contract['errors'] ?? [], true), 'audio contract errors missing ' . $errorCode);
    }
    $uploadContract = $manifest['audio_asset_contract'] ?? [];
    hub_test_assert(($uploadContract['upload_mode'] ?? '') === 'audio_upload', 'audio asset contract upload mode missing.');
    hub_test_assert(($uploadContract['ask_mode'] ?? '') === 'audio', 'audio asset contract ask mode missing.');
    hub_test_assert(in_array('audio_id', $uploadContract['output']['required_keys'] ?? [], true), 'audio asset upload contract must return audio_id.');
    foreach (['gemma4_mock_audio', 'gemma4_real_audio_transcribe_zh', 'gemma4_real_audio_understand'] as $caseId) {
        $case = hub_l5_benchmark_case($contract, $caseId);
        hub_test_assert($case !== null, 'audio benchmark missing ' . $caseId);
    }

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (["'audio' =>", 'operation', 'transcribe', 'summarize', 'mode=audio', 'mode=audio_upload', 'audio_id', 'audio/wav', '非正式 ASR'] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'audio playground missing ' . $needle);
    }
    foreach (['host_path', 'container_path', 'audio_url'] as $forbidden) {
        hub_test_assert(!str_contains($playground, 'name="' . $forbidden . '"'), 'audio playground must not expose ' . $forbidden);
    }

    $examples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    $quickstart = (string)file_get_contents(HUB_ROOT . '/docs/client_quickstart.md');
    foreach (['mode=audio_upload', 'mode=audio', 'audio=@sample.wav', 'audio_id', 'operation=understand', 'real_inference=1', '<TOKEN>', '非正式 ASR'] as $needle) {
        hub_test_assert(str_contains($examples, $needle), 'audio API examples missing ' . $needle);
        hub_test_assert(str_contains($quickstart, $needle), 'audio client quickstart missing ' . $needle);
    }
    $publicDocs = (string)file_get_contents(HUB_ROOT . '/app/public_api_docs.php');
    foreach (['audio_upload', 'audio_id', 'Upload a short WAV once'] as $needle) {
        hub_test_assert(str_contains($publicDocs, $needle), 'public docs audio asset contract missing ' . $needle);
    }
});
