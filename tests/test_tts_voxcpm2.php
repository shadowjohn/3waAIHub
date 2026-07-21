<?php
declare(strict_types=1);

function hub_test_voxcpm2_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            hub_test_voxcpm2_remove($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_voxcpm2_job_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_voxcpm2_job_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/input', 0700, true) || !mkdir($workspace . '/output', 0700, true) || !mkdir($workspace . '/checkpoints', 0700, true)) {
        throw new RuntimeException('Cannot create VoxCPM2 job workspace.');
    }

    return $workspace;
}

hub_test('VoxCPM2 experimental TTS pack manifest and service files exist', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'tts-voxcpm2 pack must be valid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['default_mode'] ?? '') === 'tts', 'VoxCPM2 default mode mismatch');
    hub_test_assert(($manifest['capability'] ?? '') === 'text_to_speech', 'VoxCPM2 capability mismatch');
    hub_test_assert(($manifest['model'] ?? '') === 'openbmb/VoxCPM2', 'VoxCPM2 model id mismatch');
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'VoxCPM2 runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'VoxCPM2 target level mismatch');
    hub_test_assert(!empty($manifest['experimental']), 'VoxCPM2 must be experimental');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'VoxCPM2 execution type mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/v1/tts', 'VoxCPM2 gateway endpoint mismatch');
    hub_test_assert(in_array('design', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support design mode');
    hub_test_assert(in_array('clone', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support controlled clone mode');
    hub_test_assert(!in_array('ultimate_clone', $manifest['tts_modes'] ?? [], true), 'Ultimate clone must stay out of v0.1 supported modes');
    hub_test_assert(($manifest['lifecycle']['lifecycle'] ?? '') === 'on_demand', 'VoxCPM2 lifecycle mismatch');
    hub_test_assert(($manifest['lifecycle']['gpu_policy'] ?? '') === 'exclusive_gpu', 'VoxCPM2 GPU policy mismatch');
    hub_test_assert((int)($manifest['lifecycle']['idle_unload_seconds'] ?? 0) === 900, 'VoxCPM2 idle unload mismatch');
    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(is_array($contract) && !empty($contract['benchmark']['supported']), 'VoxCPM2 L5 benchmark must be supported');
    foreach (['success', 'mock', 'real_inference_requested', 'runtime_level', 'artifact_url', 'sample_rate', 'duration_ms', 'model', 'seed', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'VoxCPM2 contract output missing ' . $key);
    }
    $cases = $contract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('tts_mock_wav', array_column($cases, 'id'), true), 'VoxCPM2 mock benchmark case missing');
    hub_test_assert(in_array('tts_real_wav', array_column($cases, 'id'), true), 'VoxCPM2 real benchmark case missing');
    foreach ($cases as $case) {
        if (($case['id'] ?? '') === 'tts_real_wav') {
            hub_test_assert(!empty($case['real_inference']), 'VoxCPM2 real benchmark must be marked real_inference');
        }
    }

    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py'] as $file) {
        hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/service/' . $file), 'VoxCPM2 service missing ' . $file);
    }
    hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json'), 'VoxCPM2 acceptance set missing');
});

hub_test('VoxCPM2 service app exposes TTS voice-design and controlled clone only', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    foreach (['@app.get("/health")', '@app.get("/v1/models")', '@app.post("/v1/voice-design")', '@app.post("/v1/tts")'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'VoxCPM2 app missing ' . $needle);
    }
    foreach (['/clone', 'prompt_wav_path', 'WebSocket'] as $needle) {
        hub_test_assert(!str_contains($app, $needle), 'VoxCPM2 app must not expose separate clone/streaming surface: ' . $needle);
    }
    foreach (['split_text', 'seed', 'artifact_url', 'sample_rate', 'duration_ms', 'manifest', 'reference_wav_path', 'clone'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'VoxCPM2 app missing TTS behavior ' . $needle);
    }
    hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'VoxCPM2 app must expose L5 runtime level');
});

hub_test('VoxCPM2 real inference requests cannot silently return mock audio', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/requirements.txt');
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile');

    hub_test_assert(str_contains($app, 'real_inference:'), 'TTS request schema must accept real_inference');
    hub_test_assert(str_contains($app, 'request.real_inference'), 'real_inference request flag must control mock fallback');
    hub_test_assert(str_contains($app, 'real_inference_requested'), 'manifest must record whether real inference was requested');
    hub_test_assert(str_contains($app, 'set_runtime_seed(seed)'), 'VoxCPM2 seed must be applied before generation');
    hub_test_assert(preg_match('/kwargs: dict\\[str, Any\\] = \\{(?P<kwargs>.*?)\\n    \\}/s', $app, $match) === 1, 'VoxCPM2 generate kwargs block must be present');
    hub_test_assert(!str_contains($match['kwargs'], '"seed": seed'), 'VoxCPM2 generate kwargs must not pass unsupported seed argument');
    hub_test_assert(str_contains($requirements, 'voxcpm==2.0.3'), 'VoxCPM2 runtime package must be pinned');
    hub_test_assert(str_contains($requirements, 'soundfile'), 'VoxCPM2 runtime must include soundfile');
    hub_test_assert(str_contains($dockerfile, 'libsndfile1'), 'VoxCPM2 image must include libsndfile runtime dependency');
    hub_test_assert(str_contains($dockerfile, 'gcc'), 'VoxCPM2 image must include a C compiler for Triton warmup');
});

hub_test('VoxCPM2 voice profile schema helper ownership and audit are available', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Voice Owner');
    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/owner_reference.wav';
    file_put_contents($wav, 'RIFFmock');

    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => '羽山哥技師聲線',
        'reference_audio_path' => $wav,
        'prompt_text' => '今天我們來說明 RC 閥的檢查方式。',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $profile = hub_get_voice_profile_for_member($db, $profileId, $memberId);
    hub_test_assert($profile !== null, 'owner must be able to load profile');
    hub_test_assert(hub_get_voice_profile_for_member($db, $profileId, $memberId + 99) === null, 'other member must not load private profile');
    hub_test_assert(str_starts_with(hub_voice_profile_container_path($profile), '/data/voice_profiles/'), 'voice profile must map to container path');

    $audit = $db->query('SELECT action FROM voice_profile_audit_logs ORDER BY id ASC')->fetchAll();
    hub_test_assert(count($audit) === 1 && $audit[0]['action'] === 'create', 'voice profile create must be audited');
});

hub_test('VoxCPM2 install generates GPU compose storage env and gateway contract', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-main',
        'mode' => 'tts',
        'name' => 'VoxCPM2 TTS Main',
        'port_mode' => 'manual',
        'local_port' => 18108,
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($compose, '127.0.0.1:${TTS_LOCAL_PORT:-18108}:8000'), 'VoxCPM2 compose port binding mismatch');
    hub_test_assert(str_contains($compose, 'gpus: all'), 'VoxCPM2 compose must request GPU');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/voxcpm2:/models/voxcpm2'), 'VoxCPM2 compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/voxcpm2:/cache/voxcpm2'), 'VoxCPM2 compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'VoxCPM2 compose must mount service data');
    hub_test_assert(str_contains($compose, '${AIHUB_UPLOADS_DIR}/voice_profiles:/data/voice_profiles:ro'), 'VoxCPM2 compose must mount managed voice profiles read-only');
    foreach ([
        'VOXCPM2_MODEL_DIR=/models/voxcpm2',
        'VOXCPM2_CACHE_DIR=/cache/voxcpm2',
        'VOXCPM2_SERVICE_DATA_DIR=/data/service',
        'VOXCPM2_MODEL_ID=openbmb/VoxCPM2',
        'VOXCPM2_SAMPLE_RATE=48000',
        'VOXCPM2_DEFAULT_SEED=42',
        'VOXCPM2_REAL_INFERENCE=0',
        'VOXCPM2_GPU_POLICY=exclusive_gpu',
        'VOXCPM2_IDLE_UNLOAD_SECONDS=900',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'VoxCPM2 env missing ' . $needle);
    }

    hub_set_service_enabled($db, 'tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $oldServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $response = hub_gateway_dispatch($db, 'tts', static function (array $service, int $timeoutSec): array {
            hub_test_assert($service['mode'] === 'tts', 'TTS gateway service mismatch');
            hub_test_assert($timeoutSec === 180, 'TTS timeout mismatch');

            return hub_gateway_json(200, [
                'success' => true,
                'artifact_url' => '/artifacts/mock.wav',
                'sample_rate' => 48000,
                'duration_ms' => 8640,
                'model' => 'VoxCPM2',
                'seed' => 42,
            ]);
        });
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200, 'TTS gateway mock should pass');
});

hub_test('VoxCPM2 gateway rewrites clone profile IDs without exposing host paths', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-main',
        'mode' => 'tts',
        'name' => 'VoxCPM2 TTS Main',
        'port_mode' => 'manual',
        'local_port' => 18108,
    ]);
    $memberId = hub_create_api_member($db, 'Clone Member');
    $token = hub_create_api_token($db, $memberId, 'TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts', (int)$installed['service']['id']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
    hub_set_service_enabled($db, 'tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');

    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/clone_reference.wav';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Clone profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'explicit_permission',
        'usage_scope' => 'private',
    ]);

    $payload = [
        'mode' => 'clone',
        'text' => 'RC 閥的調整方式如下。',
        'reference_audio_id' => 'voice_profile_' . $profileId,
        'control' => '沉穩、稍慢、像技師解說',
        'seed' => 42,
        'format' => 'wav',
    ];
    $prepared = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], [
        'member_id' => $memberId,
        'token_id' => (int)$token['token_id'],
    ], json_encode($payload, JSON_UNESCAPED_UNICODE));
    hub_test_assert(($prepared['error'] ?? null) === null, 'clone payload should prepare');
    $body = json_decode((string)$prepared['body'], true);
    hub_test_assert(($body['reference_wav_path'] ?? '') === '/data/voice_profiles/clone_reference.wav', 'clone must use mapped container path');
    hub_test_assert(!str_contains((string)$prepared['body'], HUB_ROOT), 'clone payload must not expose host path');
    hub_test_assert(!isset($body['reference_audio_id']), 'public reference ID must not be forwarded');

    $actions = $db->query('SELECT action FROM voice_profile_audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    hub_test_assert($actions === 'use', 'clone use must be audited');

    $blocked = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], [], json_encode($payload, JSON_UNESCAPED_UNICODE));
    hub_test_assert(($blocked['response']['status'] ?? 0) === 403, 'clone without token member must be rejected');
});

hub_test('VoxCPM2 appears in customer playground when user and token allow tts', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-auth-playground',
        'mode' => 'tts',
        'name' => 'VoxCPM2 Auth Playground',
        'port_mode' => 'manual',
        'local_port' => 18283,
    ]);
    $customerId = hub_create_customer_user($db, [
        'username' => 'tts_playground',
        'password' => 'customer123',
        'modes' => ['tts'],
    ]);
    $customer = hub_get_user($db, $customerId);
    $token = hub_create_api_token($db, (int)$customer['api_member_id'], 'Own TTS', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts', (int)$installed['service']['id']);

    hub_test_assert(in_array('tts', hub_playground_supported_modes(), true), 'playground supported modes must include tts');
    $modes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, $customer));
    hub_test_assert($modes === ['tts'], 'customer playground must show tts when user and own token allow it');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (['api.php?mode=tts', 'voice_prompt', 'reference_audio_id', 'voice_profile_1'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground TTS UI missing ' . $needle);
    }
    $ttsStart = strpos($source, "\$selectedMode === 'tts'):");
    hub_test_assert($ttsStart !== false, 'playground TTS branch missing');
    $ttsEnd = strpos($source, '<?php elseif', $ttsStart);
    hub_test_assert($ttsEnd !== false, 'playground TTS branch end missing');
    $ttsBranch = substr($source, $ttsStart, $ttsEnd - $ttsStart);
    hub_test_assert(str_contains($ttsBranch, 'name="real_inference" type="checkbox" value="1" checked'), 'playground TTS real inference must be checked by default');
});

hub_test('VoxCPM2 playground exposes generated WAV through authenticated audio player', function (): void {
    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($playground, 'playground_artifact.php'), 'playground must link TTS artifacts through admin artifact endpoint');
    hub_test_assert(str_contains($playground, '<audio controls'), 'playground must render an audio player for TTS artifacts');

    $artifactPage = HUB_ROOT . '/admin/playground_artifact.php';
    hub_test_assert(is_file($artifactPage), 'playground artifact endpoint missing');
    $source = (string)file_get_contents($artifactPage);
    foreach (['hub_require_login', 'audio/wav', 'hub_playground_artifact_path', 'basename'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground artifact endpoint missing ' . $needle);
    }
});

hub_test('VoxCPM2 acceptance set covers Traditional Chinese maintenance text', function (): void {
    $cases = json_decode((string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json'), true);
    hub_test_assert(is_array($cases) && count($cases) === 12, 'VoxCPM2 acceptance set must contain 12 cases');
    $joined = json_encode($cases, JSON_UNESCAPED_UNICODE);
    foreach (['8,500 rpm', '0.7 mm', '12 N·m', 'NSR', 'RC Valve', 'PGM-III', '6902', '91201-KV3-831', '臺', '閥', '機車', '汽缸'] as $needle) {
        hub_test_assert(str_contains((string)$joined, $needle), 'VoxCPM2 acceptance set missing ' . $needle);
    }
});

hub_test('VoxCPM2 long-form job is a fixed GPU container Pack contract with safe artifacts', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'VoxCPM2 Pack must validate before its long-form job is usable');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(is_array($job), 'VoxCPM2 synthesize job contract missing');
    hub_test_assert(($job['input_fields'] ?? []) === ['text', 'mode', 'voice_prompt', 'control', 'seed', 'seed_policy', 'model', 'voice_profile_id', 'waveform_preview'], 'long-form input must be a closed Pack allowlist');
    hub_test_assert(($job['source_required'] ?? true) === false && ($job['source_artifact_types'] ?? null) === [], 'long-form synthesis must receive text and managed voice context, never an external audio source');
    hub_test_assert(($job['runner'] ?? []) === [
        'image' => '3waaihub/tts-voxcpm2:0.1.0',
        'entrypoint' => ['/app/voice-generate'],
        'args' => ['--workspace', '{workspace}', '--input', '{input_dir}', '--output', '{output_dir}', '--runner-config', '{input_dir}/runner_config.json'],
        'output_dir' => 'output',
        'accelerator' => 'gpu',
        'required_vram_mb' => 16384,
        'timeout_seconds' => 7200,
        'executor' => 'container',
        'asset_mounts' => [[
            'id' => 'voxcpm2_model',
            'storage' => 'models',
            'host_subdir' => 'voxcpm2/model',
            'container_path' => '/models/voxcpm2/model',
            'required_paths' => ['config.json'],
        ]],
    ], 'long-form synthesis must use the generic GPU container runner with only its controlled model mount');
    hub_test_assert(($job['runner_config'] ?? []) === [
        'alias_input' => 'model',
        'model_allowlist' => 'voxcpm2',
        'aliases' => [
            'voxcpm2' => [
                'model' => '/models/voxcpm2/model',
                'label' => 'VoxCPM2',
                'version' => '2.0.3',
                'sample_rate' => 48000,
            ],
        ],
        'default_alias' => 'voxcpm2',
    ], 'model, version, and sample rate must be a frozen task snapshot');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? null) === true && ($manifest['hardware']['cpu_fallback'] ?? null) === false, 'long-form synthesis must not declare a CPU path');
    hub_test_assert(array_column($job['artifact_contract']['artifacts'] ?? [], 'type') === ['generated_audio', 'synthesis_metadata', 'waveform_preview'], 'long-form artifact contract mismatch');
    foreach (['jobs/voice_generate.sh', 'service/job.py', 'service/long_form.py', 'service/long_form_smoke.py'] as $asset) {
        hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/' . $asset), 'long-form job asset missing ' . $asset);
    }
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile');
    foreach (['long_form.py', 'job.py', 'voice_generate.sh', 'voice-generate'] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'controlled job image must install ' . $needle);
    }
});

hub_test('VoxCPM2 long-form fake runner is deterministic, resumable, and emits no public checkpoint', function (): void {
    $service = HUB_ROOT . '/packs/tts-voxcpm2/service';
    $workspace = hub_test_voxcpm2_job_workspace();
    $request = [
        'text' => 'Dr. Lin 說：「8,500 rpm 時，RC Valve 的間隙是 0.7 mm。」請確認 PGM-III 與 91201-KV3-831。',
        'mode' => 'design',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
        'seed' => 42,
        'seed_policy' => 'derived_per_chunk',
        'waveform_preview' => true,
    ];
    $pack = hub_get_pack('tts-voxcpm2');
    $contract = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), 'synthesize');
    $config = hub_pack_job_runner_config_for_task((array)$contract, $request);
    hub_test_assert($config === [
        'allowlist' => 'voxcpm2',
        'alias' => 'voxcpm2',
        'model' => [
        'model' => '/models/voxcpm2/model',
        'label' => 'VoxCPM2',
        'version' => '2.0.3',
        'sample_rate' => 48000,
        ],
    ], 'ordinary design synthesis without a model must use the fixed runner default');
    file_put_contents($workspace . '/input/request.json', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    file_put_contents($workspace . '/input/runner_config.json', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    file_put_contents($workspace . '/input/source', 'managed-source-not-a-path', LOCK_EX);
    try {
        $planScript = <<<'PY'
import importlib.util
import json
import sys

spec = importlib.util.spec_from_file_location("long_form", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
text = 'Dr. Lin 說：「8,500 rpm 時，RC Valve 的間隙是 0.7 mm。」請確認 PGM-III 與 91201-KV3-831。'
first = module.make_plan(text, 42, 'derived_per_chunk', 42)
second = module.make_plan(text, 42, 'derived_per_chunk', 42)
assert module.canonical_json(first) == module.canonical_json(second)
assert first['normalization'] == 'semantic-v1'
assert ''.join(chunk['text'] for chunk in first['chunks']) == first['normalized_input']
assert '8,500 rpm' in first['normalized_input'] and '0.7 mm' in first['normalized_input']
assert all('word_alignment' not in chunk for chunk in first['chunks'])
unit_chunks = module.split_semantic_v1('A' * 41 + 'N·m。tail', 42)
assert all(not (left.endswith('N') and right.startswith('·m')) for left, right in zip(unit_chunks, unit_chunks[1:]))
assert 'N·m' in ''.join(unit_chunks)
PY;
        $plan = hub_run_command(['python3', '-c', $planScript, $service . '/long_form.py'], 10);
        hub_test_assert(($plan['exit_code'] ?? 1) === 0, 'semantic-v1 plan must be byte-deterministic: ' . ($plan['stderr'] ?? ''));

        $command = ['env', 'VOXCPM2_JOB_FAKE_SYNTHESIS=1', 'python3', $service . '/job.py', '--workspace', $workspace, '--input', $workspace . '/input', '--output', $workspace . '/output', '--runner-config', $workspace . '/input/runner_config.json'];
        $first = hub_run_command($command, 30);
        hub_test_assert(($first['exit_code'] ?? 1) === 0, 'deterministic fake long-form synthesis must run without a model: ' . ($first['stderr'] ?? ''));
        $audio = $workspace . '/output/generated_audio.wav';
        $metadata = $workspace . '/output/synthesis_metadata.json';
        $waveform = $workspace . '/output/waveform_preview.json';
        $checkpoint = $workspace . '/checkpoints/plan/chunks.json';
        hub_test_assert(is_file($audio) && is_file($metadata) && is_file($waveform) && is_file($checkpoint), 'job must emit only declared artifacts plus a private checkpoint');
        $audioHash = hash_file('sha256', $audio);
        $metadataValue = json_decode((string)file_get_contents($metadata), true);
        hub_test_assert(is_array($metadataValue), 'synthesis metadata must be JSON');
        foreach (['normalized_input', 'plan', 'model', 'voice_context', 'chunks', 'final_format', 'loudness', 'timeline'] as $key) {
            hub_test_assert(array_key_exists($key, $metadataValue), 'synthesis metadata missing ' . $key);
        }
        hub_test_assert(($metadataValue['plan']['normalization'] ?? '') === 'semantic-v1', 'metadata must preserve the immutable semantic plan');
        hub_test_assert(($metadataValue['model']['model'] ?? '') === '/models/voxcpm2/model' && ($metadataValue['controls']['task_seed'] ?? null) === 42, 'runner defaults must be recorded in an ordinary design result');
        hub_test_assert(!str_contains((string)file_get_contents($metadata), $workspace), 'metadata must not disclose workspace paths');
        $second = hub_run_command($command, 30);
        hub_test_assert(($second['exit_code'] ?? 1) === 0 && $audioHash === hash_file('sha256', $audio), 'resume must reuse matching chunk checkpoints deterministically');
        hub_test_assert(!is_file($workspace . '/output/chunks.json') && !is_dir($workspace . '/output/checkpoints'), 'checkpoints must never be public artifacts');
    } finally {
        hub_test_voxcpm2_remove($workspace);
    }
});

hub_test('VoxCPM2 long-form admission freezes only manifest controls and rejects profile misuse', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    $route = hub_resolve_audio_async_route($db, 'voice_generate');
    $design = hub_pack_job_normalize_request_input([
        'text' => 'RC Valve 8,500 rpm',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
    ], $route);
    hub_test_assert($design === [
        'text' => 'RC Valve 8,500 rpm',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
    ], 'design tasks must persist only supplied manifest controls; the pinned runner supplies its fixed defaults');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'voice_profile_id' => 1], $route)), 'design must reject clone profile IDs at Pack admission');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'reference_wav_path' => '/host.wav'], $route)), 'async tasks must reject external reference paths');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'model' => 'anything-else'], $route)), 'async tasks must reject arbitrary model controls');
});

hub_test('VoxCPM2 install builds and verifies the controlled long-form runner image', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    $built = false;
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'idempotent' => true,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$commands, &$built): array {
            $commands[] = $command;
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                return $built ? ['exit_code' => 0, 'stdout' => 'sha256:voxcpm2', 'stderr' => ''] : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'missing'];
            }
            if (($command[1] ?? '') === 'build') {
                $built = true;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected VoxCPM2 runner image command');
        },
    ]);
    hub_test_assert($commands === [
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/tts-voxcpm2:0.1.0'],
        ['docker', 'build', '--tag', '3waaihub/tts-voxcpm2:0.1.0', '--file', HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile', HUB_ROOT . '/packs/tts-voxcpm2'],
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/tts-voxcpm2:0.1.0'],
    ] && ($installed['service']['install_status'] ?? '') === 'installed', 'runner image must be built only from the Pack-controlled context and verified before install');
});

hub_test('VoxCPM2 async clone resolves one owned profile into a path-free snapshot and controlled mount', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Async Clone Owner');
        $other = hub_create_api_member($db, 'Async Clone Other');
        $ownerToken = hub_create_api_token($db, $owner, 'async clone owner', null, null);
        $otherToken = hub_create_api_token($db, $other, 'async clone other', null, null);
        hub_test_audio_allow($db, [$ownerToken, $otherToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $profilePath = hub_voice_profile_storage_dir() . '/async_clone_reference.wav';
        file_put_contents($profilePath, 'RIFFmanaged-profile', LOCK_EX);
        $profileId = hub_create_voice_profile($db, $owner, [
            'name' => 'Async clone profile',
            'reference_audio_path' => $profilePath,
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
        ]);
        $input = [
            'text' => 'RC Valve 8,500 rpm',
            'mode' => 'clone',
            'voice_profile_id' => (string)$profileId,
            'control' => '清楚、稍慢',
        ];
        $accepted = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input);
        hub_test_assert($accepted['status'] === 200, 'owned clone profile must be admitted');
        $task = hub_get_task($db, (int)(hub_test_audio_payload($accepted)['task_id'] ?? 0));
        $snapshot = $task['input']['voice_context'] ?? null;
        hub_test_assert(is_array($snapshot) && $snapshot === [
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $profilePath),
            'container_path' => '/data/voice_profiles/reference.wav',
        ], 'task must persist only immutable profile identity/hash and its fixed container path');
        hub_test_assert(!str_contains((string)json_encode($task['input']), $profilePath), 'task input must never persist a host profile path');

        $route = hub_resolve_audio_async_route($db, 'voice_generate');
        $mount = hub_pack_job_resolve_voice_profile_mount($db, $task, hub_pack_job_contract_from_snapshot($task));
        hub_test_assert($mount === ['source' => realpath($profilePath), 'container_path' => '/data/voice_profiles/reference.wav'], 'generic runner must derive the sole read-only profile mount from the trusted snapshot');
        $runner = $route['runner'];
        $runner['asset_mounts'] = [];
        $command = hub_pack_job_default_runner_command([
            'workspace' => hub_test_voxcpm2_job_workspace(),
            'run' => ['run_id' => 'voice-profile-mount-test'],
            'runner' => array_replace($runner, ['voice_profile_mount' => $mount]),
        ])['command'];
        $profileMount = 'type=bind,src=' . realpath($profilePath) . ',dst=/data/voice_profiles/reference.wav,readonly';
        hub_test_assert(in_array($profileMount, $command, true) && !str_contains(implode("\n", $command), 'async_clone_reference.wav') === false, 'runner command must receive only the Hub-derived read-only reference mount');

        $legacyContract = json_decode((string)$task['job_contract_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($legacyContract['voice_context']['design_prompt_input']);
        $legacyJson = json_encode($legacyContract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $legacyTask = $task;
        $legacyTask['job_contract_json'] = $legacyJson;
        $legacyTask['job_contract_digest'] = hash('sha256', $legacyJson);
        $legacyResolved = hub_pack_job_contract_from_snapshot($legacyTask);
        hub_test_assert(array_keys($legacyResolved['voice_context'] ?? []) === ['mode_input', 'design_value', 'clone_value', 'profile_input', 'container_path']
            && hub_pack_job_voice_context_snapshot($legacyResolved['voice_context'], ['mode' => 'design'], null) === [], 'valid legacy design/clone contracts must canonicalize without adding a client path or prompt control');
        hub_test_assert(hub_pack_job_resolve_voice_profile_mount($db, $legacyTask, $legacyResolved) === $mount, 'valid legacy clone contracts must preserve the owned profile hash and controlled mount');
        $legacyPromptInput = $legacyTask['input'];
        $legacyPromptInput['voice_prompt'] = 'forbidden clone prompt';
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_voice_context_snapshot($legacyResolved['voice_context'], $legacyPromptInput, $snapshot)), 'legacy clone contracts must not bypass the prompt prohibition before GPU work');
        $db->prepare('UPDATE tasks SET job_contract_json = :json, job_contract_digest = :digest WHERE id = :id')->execute([
            ':json' => $legacyJson,
            ':digest' => $legacyTask['job_contract_digest'],
            ':id' => (int)$task['id'],
        ]);
        $task = $legacyTask;

        $beforeClonePrompt = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $clonePrompt = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input + ['voice_prompt' => 'must be rejected before GPU']);
        hub_test_assert($clonePrompt['status'] === 400 && (hub_test_audio_payload($clonePrompt)['error'] ?? '') === 'invalid_request'
            && (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $beforeClonePrompt, 'clone voice_prompt must be rejected at gateway admission before a task or GPU work exists');

        $modelDir = hub_test_models_dir() . '/voxcpm2/model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
            throw new RuntimeException('Cannot create VoxCPM2 model fixture.');
        }
        file_put_contents($modelDir . '/config.json', '{}', LOCK_EX);
        $claimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $dockerRun = [];
        hub_run_pack_job_task($db, $claimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'command_runner' => static function (array $command, int $timeout) use (&$dockerRun): array {
                if (($command[1] ?? '') === 'run') {
                    $dockerRun = $command;
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'synthetic runner failure'];
                }
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such container'];
                }
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);
        $checkpointMount = 'type=bind,src=' . hub_task_result_dir((int)$task['id']) . '/workspace/checkpoints,dst=/workspace/checkpoints';
        hub_test_assert(in_array($profileMount, $dockerRun, true) && in_array($checkpointMount, $dockerRun, true)
            && !in_array('type=bind,src=' . hub_task_result_dir((int)$task['id']) . '/workspace/input/source,dst=/workspace/input/source,readonly', $dockerRun, true), 'default executor must retain the clone mount and writable private checkpoints after execution starts');

        $crossMember = hub_test_audio_request($db, 'voice_generate', (string)$otherToken['plain_token'], $input);
        hub_test_assert($crossMember['status'] === 403 && (hub_test_audio_payload($crossMember)['error'] ?? '') === 'voice_profile_forbidden', 'another member must not clone an owned profile');
        $design = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_replace($input, ['mode' => 'design', 'voice_prompt' => 'voice']));
        hub_test_assert($design['status'] === 400 && (hub_test_audio_payload($design)['error'] ?? '') === 'invalid_request', 'design must reject a profile ID');
        $external = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input + ['reference_wav_path' => '/host/reference.wav']);
        hub_test_assert($external['status'] === 400 && (hub_test_audio_payload($external)['error'] ?? '') === 'forbidden_task_control', 'async clone must reject external reference paths');

        unlink($profilePath);
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_voice_profile_mount($db, $task, hub_pack_job_contract_from_snapshot($task))), 'profile file changes or removal must fail before GPU work');
        foreach ([$task] as $item) {
            if (is_array($item) && isset($item['id'])) {
                hub_test_voxcpm2_remove(hub_task_result_dir((int)$item['id']));
            }
        }
    });
});
