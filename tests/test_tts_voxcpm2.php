<?php
declare(strict_types=1);

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

hub_test('VoxCPM2 voice profile drafts confirm per owner and accept explicit tokens', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Voice Owner');
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
    $otherProfileId = hub_create_voice_profile($db, $otherMemberId, [
        'name' => 'Other private profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $otherProfile = hub_get_voice_profile_for_member($db, $otherProfileId, $otherMemberId);
    hub_test_assert($otherProfile !== null && $otherProfile['reference_audio_sha256'] === $profile['reference_audio_sha256'], 'private profiles must retain same SHA for matching audio');
    hub_test_assert(hub_get_voice_profile_for_member($db, $profileId, $otherMemberId) === null, 'same SHA must not make a private profile visible');
    hub_test_assert(str_starts_with(hub_voice_profile_container_path($profile), '/data/voice_profiles/'), 'voice profile must map to container path');
    hub_test_assert(($profile['prompt_text_confirmed_at'] ?? null) === null, 'draft transcript must start unconfirmed');
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text_confirmed_at'] ?? null) === null, 'later migrations must not confirm new drafts');

    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, '繁中測試');
    hub_test_assert($confirmed['prompt_text'] === '繁中測試', 'confirmation must retain edited transcript');
    hub_test_assert((string)$confirmed['prompt_text_confirmed_at'] !== '', 'confirmation timestamp must be written');
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text_confirmed_at'] ?? null) !== null, 'confirmed transcript must remain confirmed after later migrations');

    $token = hub_create_api_token($db, $memberId, 'TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
    $auth = hub_gateway_authenticate_api_token($db, 'tts', '203.0.113.10', (string)$token['plain_token']);
    hub_test_assert(!empty($auth['ok']) && (int)$auth['context']['member_id'] === $memberId, 'explicit TTS token must use its token member');
    hub_test_assert(!str_contains((string)json_encode($auth), (string)$token['plain_token']), 'auth context must not expose supplied token');
    $emptyToken = hub_gateway_authenticate_api_token($db, 'tts', '127.0.0.1', '');
    hub_test_assert(empty($emptyToken['ok']) && ($emptyToken['response']['status'] ?? 0) === 401, 'explicit empty token must not use localhost bypass');

    $audit = $db->query('SELECT action, details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . ' ORDER BY id ASC')->fetchAll();
    hub_test_assert(array_column($audit, 'action') === ['create', 'confirm_transcript'], 'voice profile create and confirmation must be audited');
    hub_test_assert(!str_contains((string)$audit[1]['details_json'], '繁中測試'), 'transcript contents must not be included in audit details');
    $auditDetails = json_decode((string)$audit[1]['details_json'], true);
    hub_test_assert(($auditDetails['text_chars'] ?? null) === (function_exists('mb_strlen') ? 4 : strlen('繁中測試')), 'confirmation audit must count Traditional Chinese characters correctly');
});

hub_test('VoxCPM2 migrates legacy transcripts once without overwriting confirmation', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Legacy Voice Owner');
    $db->exec('DROP TABLE voice_profile_audit_logs');
    $db->exec('DROP TABLE voice_profiles');
    $db->exec('CREATE TABLE voice_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_member_id INTEGER NOT NULL,
        reference_audio_sha256 TEXT NOT NULL,
        prompt_text TEXT NULL,
        prompt_text_confirmed_at TEXT NULL,
        deleted_at TEXT NULL,
        updated_at TEXT NOT NULL
    )');
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, prompt_text, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :prompt_text, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('a', 64),
            ':prompt_text' => '既有逐字稿',
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('DELETE FROM settings WHERE key = :key')
        ->execute([':key' => 'db_migration_voice_profiles_prompt_text_confirmed_at_v1']);
    $db->exec("CREATE TRIGGER voice_profile_confirmation_marker_failure
        BEFORE INSERT ON settings
        WHEN NEW.key = 'db_migration_voice_profiles_prompt_text_confirmed_at_v1'
        BEGIN
            SELECT RAISE(ABORT, 'marker_write_failed');
        END");
    hub_test_assert(hub_test_throws(static fn () => hub_migrate($db)), 'marker write failure must be surfaced');
    hub_test_assert((hub_get_voice_profile($db, 1)['prompt_text_confirmed_at'] ?? null) === null, 'marker write failure must roll back transcript confirmation');
    $db->exec('DROP TRIGGER voice_profile_confirmation_marker_failure');

    hub_migrate($db);
    $legacy = hub_get_voice_profile($db, 1);
    hub_test_assert(($legacy['prompt_text_confirmed_at'] ?? null) === '2000-01-01 00:00:00', 'legacy nonempty transcript must migrate as confirmed');
    hub_test_assert(hub_get_storage_setting($db, 'db_migration_voice_profiles_prompt_text_confirmed_at_v1') === '1', 'successful retry must mark transcript migration complete');
    $db->prepare('UPDATE voice_profiles SET prompt_text_confirmed_at = :confirmed_at WHERE id = 1')
        ->execute([':confirmed_at' => '2001-01-01 00:00:00']);
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, 1)['prompt_text_confirmed_at'] ?? null) === '2001-01-01 00:00:00', 'migration must not overwrite existing confirmation');
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
