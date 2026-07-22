<?php
declare(strict_types=1);

$hubPlaygroundVoiceProfiles = HUB_ROOT . '/admin/_playground_voice_profiles.php';
if (is_file($hubPlaygroundVoiceProfiles)) {
    require_once $hubPlaygroundVoiceProfiles;
}
$hubPlaygroundTtsArtifacts = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
if (is_file($hubPlaygroundTtsArtifacts)) {
    require_once $hubPlaygroundTtsArtifacts;
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
    hub_test_assert(in_array('ultimate_clone', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support Ultimate Clone');
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

hub_test('VoxCPM2 service app exposes TTS voice-design and managed clone modes', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    foreach (['@app.get("/health")', '@app.get("/v1/models")', '@app.post("/v1/voice-design")', '@app.post("/v1/tts")'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'VoxCPM2 app missing ' . $needle);
    }
    foreach (['/clone', 'WebSocket'] as $needle) {
        hub_test_assert(!str_contains($app, $needle), 'VoxCPM2 app must not expose separate clone/streaming surface: ' . $needle);
    }
    foreach (['split_text', 'seed', 'artifact_url', 'sample_rate', 'duration_ms', 'manifest', 'reference_wav_path', 'prompt_wav_path', 'prompt_text', 'clone', 'ultimate_clone'] as $needle) {
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

hub_test('VoxCPM2 rolls back confirmation when its audit cannot be written', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirm Audit Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirm_audit_failure.wav';
    file_put_contents($path, 'RIFFconfirm');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirm audit failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $before = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing confirmation audit failure profile.');
    $db->exec("CREATE TRIGGER voice_profile_confirm_audit_failure
        BEFORE INSERT ON voice_profile_audit_logs
        WHEN NEW.voice_profile_id = " . $profileId . " AND NEW.action = 'confirm_transcript'
        BEGIN
            SELECT RAISE(ABORT, 'confirm_audit_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'must not confirm')), 'confirmation audit failure must surface');
        $after = hub_get_voice_profile($db, $profileId);
        $confirmCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'confirm_transcript'")->fetchColumn();
        hub_test_assert($after !== null && $after['prompt_text'] === $before['prompt_text'] && $after['prompt_text_confirmed_at'] === $before['prompt_text_confirmed_at'] && $after['transcription_status'] === $before['transcription_status'] && $after['transcription_error'] === $before['transcription_error'] && $after['transcription_started_at'] === $before['transcription_started_at'] && $after['transcription_lease_token'] === $before['transcription_lease_token'], 'confirmation audit failure must leave transcript, confirmation, status, and lease state unchanged');
        hub_test_assert($confirmCount === 0, 'confirmation audit failure must not leave a confirm audit');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_confirm_audit_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 stores validated WAV uploads as unconfirmed ASR drafts', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Uploaded Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Uploaded draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => '自動字幕', 'language' => 'zh-TW', 'device' => ['effective' => 'cuda']]
        );
    } finally {
        @unlink($tmpName);
    }

    $profile = $result['profile'];
    hub_test_assert($result['cache_hit'] === false, 'new WAV upload must not be a cache hit');
    hub_test_assert($profile['prompt_text'] === '自動字幕', 'successful ASR must save the draft text');
    hub_test_assert($profile['language'] === 'zh-TW', 'successful ASR must save the language');
    hub_test_assert($profile['prompt_text_confirmed_at'] === null, 'ASR text must remain unconfirmed');
    hub_test_assert($profile['transcription_status'] === 'ready' && $profile['transcription_error'] === null, 'successful ASR must mark the draft ready without an error');
    hub_test_assert(hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']) === $profile['reference_audio_path'], 'uploaded WAV must stay in managed storage');

    $indexSql = $db->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_voice_profiles_owner_sha_active'")->fetchColumn();
    hub_test_assert(str_contains((string)$indexSql, 'CREATE INDEX') && !str_contains((string)$indexSql, 'CREATE UNIQUE INDEX') && str_contains((string)$indexSql, 'WHERE deleted_at IS NULL'), 'owner SHA cache lookup needs a nonunique active-profile partial index');
    $audit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$profile['id'] . " AND action = 'transcribe'")->fetchColumn();
    hub_test_assert(json_decode((string)$audit, true) === ['status' => 'success', 'device' => ['effective' => 'cuda'], 'text_chars' => 4], 'transcribe audit must contain status, device, and character count only');
});

hub_test('VoxCPM2 keeps duplicate legacy owner SHA profiles during migration', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Legacy Duplicate Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/legacy_duplicate.wav';
    file_put_contents($path, 'RIFFlegacy');
    $firstId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy one',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $secondId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy two',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_migrate($db);
    $sha256 = hash_file('sha256', $path);
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM voice_profiles WHERE owner_member_id = ' . $memberId . ' AND deleted_at IS NULL')->fetchColumn() === 2, 'legacy duplicate active profiles must remain intact');
    hub_test_assert(hub_find_active_voice_profile_by_owner_sha($db, $memberId, (string)$sha256) !== null, 'legacy duplicate owner SHA must remain searchable');
    hub_test_assert($firstId !== $secondId, 'legacy duplicate fixtures must be distinct profiles');
});

hub_test('VoxCPM2 caches uploaded WAV bytes only for the same owner', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Cache Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Cache Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $moveCalls = 0;
    $asrCalls = 0;
    $move = static function (string $from, string $to) use (&$moveCalls): bool {
        $moveCalls++;
        return copy($from, $to);
    };
    $transcribe = static function () use (&$asrCalls): array {
        $asrCalls++;
        return ['ok' => true, 'text' => 'draft', 'language' => 'en', 'device' => []];
    };

    try {
        $first = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Cached draft', 'consent_type' => 'self_recorded'], $move, $transcribe);
        $again = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Ignored name', 'consent_type' => 'self_recorded'], $move, static fn (): array => throw new RuntimeException('cache hit must not transcribe'));
        $other = hub_create_uploaded_voice_profile($db, $otherMemberId, $upload, ['name' => 'Other draft', 'consent_type' => 'self_recorded'], $move, $transcribe);
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert($again['cache_hit'] === true && (int)$again['profile']['id'] === (int)$first['profile']['id'], 'same owner and bytes must reuse the active profile');
    hub_test_assert($moveCalls === 2 && $asrCalls === 2, 'same-owner ready cache hit must skip staging and ASR');
    hub_test_assert($other['cache_hit'] === false && (int)$other['profile']['owner_member_id'] === $otherMemberId, 'matching bytes must not cross profile ownership');
    hub_test_assert((int)$other['profile']['id'] !== (int)$first['profile']['id'], 'other owner must receive a separate private profile');
    $cacheAudit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$first['profile']['id'] . " AND action = 'cache_hit'")->fetchColumn();
    hub_test_assert(json_decode((string)$cacheAudit, true) === ['status' => 'reused'], 'active cache reuse must be audited without transcript details');
});

hub_test('VoxCPM2 validates profile input before moving an uploaded WAV', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Validation Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $moveCalls = 0;
    $move = static function (string $from, string $to) use (&$moveCalls): bool {
        $moveCalls++;
        return copy($from, $to);
    };

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => '', 'consent_type' => 'self_recorded', 'usage_scope' => 'private', 'visibility' => 'private'], $move)), 'blank profile name must be rejected');
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Invalid consent', 'consent_type' => 'unknown', 'usage_scope' => 'private', 'visibility' => 'private'], $move)), 'invalid profile consent must be rejected');
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert($moveCalls === 0, 'invalid profile input must not leave a managed WAV behind');
});

hub_test('VoxCPM2 reports a pending owner SHA upload without a cache hit', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Pending Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $path = hub_voice_profile_storage_dir() . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    $moveCalls = 0;
    file_put_contents($path, (string)file_get_contents($tmpName));
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Pending draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
        'transcription_status' => 'pending',
    ]);
    hub_test_assert((string)((hub_get_voice_profile($db, $profileId) ?? [])['transcription_started_at'] ?? '') !== '', 'new pending profile must start a transcription lease');

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Second pending upload', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$moveCalls): bool {
                $moveCalls++;
                return copy($from, $to);
            },
            static fn (): array => throw new RuntimeException('pending upload must not transcribe')
        );
        hub_test_assert((glob(hub_voice_profile_storage_dir() . '/voice_profile_stage_' . $memberId . '_*.wav') ?: []) === [], 'pending upload must delete only its staging WAV');
    } finally {
        @unlink($tmpName);
        hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        foreach (glob(hub_voice_profile_storage_dir() . '/voice_profile_stage_' . $memberId . '_*.wav') ?: [] as $staging) {
            @unlink($staging);
        }
    }

    hub_test_assert($result['cache_hit'] === false, 'pending upload must not report a completed cache hit');
    hub_test_assert(($result['transcription']['error'] ?? '') === 'transcription_pending', 'pending upload must return a safe pending status');
    hub_test_assert((int)$result['profile']['id'] === $profileId && $moveCalls === 0, 'pending upload must retain the active profile without staging its duplicate bytes');
});

hub_test('VoxCPM2 reclaims a stale pending owner SHA upload without moving bytes', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Stale Pending Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $path = hub_voice_profile_storage_dir() . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    file_put_contents($path, (string)file_get_contents($tmpName));
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale pending draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
        'transcription_status' => 'pending',
    ]);
    $db->prepare('UPDATE voice_profiles SET transcription_started_at = :started_at WHERE id = :id')
        ->execute([':started_at' => date('Y-m-d H:i:s', time() - 301), ':id' => $profileId]);
    $moveCalls = 0;

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Stale retry upload', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$moveCalls): bool {
                $moveCalls++;
                return copy($from, $to);
            },
            static fn (): array => ['ok' => true, 'text' => 'reclaimed draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($result['cache_hit'] === false && (int)$result['profile']['id'] === $profileId, 'stale pending upload must reclaim the existing profile');
        hub_test_assert($result['profile']['transcription_status'] === 'ready' && $moveCalls === 0, 'stale pending upload must retranscribe managed bytes without staging another file');
    } finally {
        @unlink($tmpName);
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 removes only stale unreferenced generated upload WAVs on a cache miss', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Staging Cleanup Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $cleanupTmpName = tempnam(sys_get_temp_dir(), 'voice-profile-cleanup-');
    if ($cleanupTmpName === false) {
        throw new RuntimeException('Cannot create cleanup WAV fixture.');
    }
    file_put_contents($cleanupTmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . "\0");
    $cleanupUpload = ['tmp_name' => $cleanupTmpName, 'size' => filesize($cleanupTmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $dir = hub_voice_profile_storage_dir();
    $path = $dir . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    $oldStaging = $dir . '/voice_profile_stage_99_' . str_repeat('a', 32) . '.wav';
    $freshStaging = $dir . '/voice_profile_stage_99_' . str_repeat('b', 32) . '.wav';
    $oldUnreferencedFinal = $dir . '/voice_profile_99_' . str_repeat('c', 32) . '.wav';
    $activeFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('d', 32) . '.wav';
    $deletedFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('e', 32) . '.wav';
    $freshUnreferencedFinal = $dir . '/voice_profile_99_' . str_repeat('f', 32) . '.wav';
    $oldLegacyFinal = $dir . '/voice_profile_99_' . str_repeat('a', 64) . '.wav';
    file_put_contents($path, (string)file_get_contents($tmpName));
    file_put_contents($oldStaging, 'old');
    file_put_contents($freshStaging, 'fresh');
    file_put_contents($oldUnreferencedFinal, 'old final');
    file_put_contents($activeFinal, 'active final');
    file_put_contents($deletedFinal, 'deleted final');
    file_put_contents($freshUnreferencedFinal, 'fresh final');
    file_put_contents($oldLegacyFinal, 'legacy final');
    $oldAt = time() - hub_voice_profile_transcription_lease_seconds($db) - 1;
    touch($oldStaging, $oldAt);
    touch($oldUnreferencedFinal, $oldAt);
    touch($activeFinal, $oldAt);
    touch($deletedFinal, $oldAt);
    touch($oldLegacyFinal, $oldAt);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Ready cleanup draft',
        'reference_audio_path' => $path,
        'prompt_text' => 'ready',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $activeProfileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Active final cleanup draft',
        'reference_audio_path' => $activeFinal,
        'prompt_text' => 'active',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $deletedProfileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Deleted final cleanup draft',
        'reference_audio_path' => $deletedFinal,
        'prompt_text' => 'deleted',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_soft_delete_voice_profile($db, $deletedProfileId, $memberId);
    $cleanupProfileId = 0;

    try {
        $cached = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Ready cache', 'consent_type' => 'self_recorded'],
            static fn (): bool => throw new RuntimeException('ready cache must not move')
        );
        hub_test_assert($cached['cache_hit'] === true, 'ready profile must remain a cache hit without cleanup');
        hub_test_assert(is_file($oldStaging) && is_file($oldUnreferencedFinal), 'ready cache hit must not run staging or final cleanup');
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $cleanupUpload,
            ['name' => 'Cleanup cache miss', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'cleanup draft', 'language' => 'en', 'device' => []]
        );
        $cleanupProfileId = (int)$result['profile']['id'];
        hub_test_assert($result['cache_hit'] === false, 'cleanup fixture upload must take the no-cache-miss path');
        hub_test_assert(!is_file($oldStaging) && is_file($freshStaging), 'upload entry must remove only stale random staging WAVs');
        hub_test_assert(!is_file($oldUnreferencedFinal), 'upload entry must remove an old unreferenced immutable final WAV');
        hub_test_assert(is_file($activeFinal) && is_file($deletedFinal), 'upload entry must retain old immutable final WAVs referenced by active or soft-deleted profiles');
        hub_test_assert(is_file($freshUnreferencedFinal), 'upload entry must retain a fresh unreferenced immutable final WAV');
        hub_test_assert(is_file($oldLegacyFinal), 'upload entry must not remove arbitrary legacy WAV filenames');
    } finally {
        @unlink($tmpName);
        @unlink($cleanupTmpName);
        @unlink($oldStaging);
        @unlink($freshStaging);
        @unlink($oldUnreferencedFinal);
        @unlink($deletedFinal);
        @unlink($freshUnreferencedFinal);
        @unlink($oldLegacyFinal);
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
        if (hub_get_voice_profile($db, $activeProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $activeProfileId, $memberId, true);
        }
        if ($cleanupProfileId > 0 && hub_get_voice_profile($db, $cleanupProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $cleanupProfileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 final cleanup waits for writer lock before checking final references', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Concurrent Cleanup Voice Owner');
    $concurrentDb = hub_db();
    $concurrentDb->exec('PRAGMA busy_timeout = 0');
    $dir = hub_voice_profile_storage_dir();
    $writerStaging = $dir . '/voice_profile_stage_' . $memberId . '_' . str_repeat('1', 32) . '.wav';
    $protectedFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('2', 32) . '.wav';
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-concurrent-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create concurrent WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . "\0");
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    file_put_contents($writerStaging, 'RIFFuncommitted');
    touch($writerStaging, time() - hub_voice_profile_transcription_lease_seconds($db) - 1);
    $writerTransactionStarted = false;
    $writerProfileId = 0;
    $uploadProfileId = 0;

    try {
        $db->exec('BEGIN IMMEDIATE');
        $writerTransactionStarted = true;
        hub_test_assert(rename($writerStaging, $protectedFinal), 'writer must rename its final WAV before creating the profile');
        $writerProfileId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Uncommitted writer draft',
            'reference_audio_path' => $protectedFinal,
            'prompt_text' => 'writer',
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
            'visibility' => 'private',
        ]);
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile(
            $concurrentDb,
            $memberId,
            $upload,
            ['name' => 'Concurrent cleanup miss', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'child', 'language' => 'en', 'device' => []]
        )), 'concurrent upload must wait for the writer before final cleanup');
        hub_test_assert(is_file($protectedFinal), 'cleanup must not run before the writer lock can expose its profile reference');

        $db->exec('COMMIT');
        $writerTransactionStarted = false;
        $result = hub_create_uploaded_voice_profile(
            $concurrentDb,
            $memberId,
            $upload,
            ['name' => 'Concurrent cleanup retry', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'child', 'language' => 'en', 'device' => []]
        );
        $uploadProfileId = (int)$result['profile']['id'];
        hub_test_assert(is_file($protectedFinal), 'cleanup after the writer lock must preserve the committed profile WAV');
    } finally {
        if ($writerTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        $concurrentDb = null;
        @unlink($tmpName);
        @unlink($writerStaging);
        if ($uploadProfileId > 0 && hub_get_voice_profile($db, $uploadProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $uploadProfileId, $memberId, true);
        }
        if ($writerProfileId > 0 && hub_get_voice_profile($db, $writerProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $writerProfileId, $memberId, true);
        } elseif (is_file($protectedFinal)) {
            unlink($protectedFinal);
        }
    }
});

hub_test('VoxCPM2 reuploads after a profile is soft-deleted', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Reupload Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $asrCalls = 0;
    $transcribe = static function () use (&$asrCalls): array {
        $asrCalls++;
        return ['ok' => true, 'text' => 'draft', 'language' => 'en', 'device' => []];
    };

    $firstPath = null;
    $secondPath = null;
    try {
        $first = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'First upload', 'consent_type' => 'self_recorded'], static fn (string $from, string $to): bool => copy($from, $to), $transcribe);
        $firstPath = (string)$first['profile']['reference_audio_path'];
        hub_soft_delete_voice_profile($db, (int)$first['profile']['id'], $memberId);
        $second = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Second upload', 'consent_type' => 'self_recorded'], static fn (string $from, string $to): bool => copy($from, $to), $transcribe);
        $secondPath = (string)$second['profile']['reference_audio_path'];
        hub_test_assert(is_file($firstPath) && unlink($firstPath), 'delayed old audio cleanup fixture must remove the old WAV');
        hub_test_assert($firstPath !== $secondPath && is_file($secondPath), 'delayed cleanup for a soft-deleted profile must not remove a matching reupload WAV');
    } finally {
        @unlink($tmpName);
        if (isset($second) && hub_get_voice_profile($db, (int)$second['profile']['id']) !== null) {
            hub_soft_delete_voice_profile($db, (int)$second['profile']['id'], $memberId, true);
        }
        if ($firstPath !== null && is_file($firstPath)) {
            unlink($firstPath);
        }
    }

    hub_test_assert($second['cache_hit'] === false && (int)$second['profile']['id'] !== (int)$first['profile']['id'], 'reupload after soft delete must create a new active profile');
    hub_test_assert($asrCalls === 2, 'reupload after soft delete must transcribe the new profile');
});

hub_test('VoxCPM2 removes staged and final WAVs when profile creation fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed Insert Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $dir = hub_voice_profile_storage_dir();
    $finalPattern = $dir . '/voice_profile_' . $memberId . '_*.wav';
    $stagingPattern = $dir . '/voice_profile_stage_' . $memberId . '_*.wav';
    $db->exec("CREATE TRIGGER voice_profile_insert_failure
        BEFORE INSERT ON voice_profiles
        BEGIN
            SELECT RAISE(ABORT, 'profile_insert_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Failed insert', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => throw new RuntimeException('ASR must not run')
        )), 'profile insert failure must surface');
        hub_test_assert((glob($finalPattern) ?: []) === [], 'failed profile insert must remove its final WAV before rollback');
        hub_test_assert((glob($stagingPattern) ?: []) === [], 'failed profile insert must remove this request staging WAV');
    } finally {
        $db->exec('DROP TRIGGER voice_profile_insert_failure');
        @unlink($tmpName);
        foreach (glob($finalPattern) ?: [] as $final) {
            @unlink($final);
        }
        foreach (glob($stagingPattern) ?: [] as $staging) {
            @unlink($staging);
        }
    }
});

hub_test('VoxCPM2 rejects WAV uploads without a RIFF WAVE header', function (): void {
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create invalid WAV fixture.');
    }
    file_put_contents($tmpName, 'RIFF0000NOTWAVE');

    try {
        try {
            hub_validate_voice_profile_wav(['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK]);
            throw new RuntimeException('invalid WAV header must be rejected');
        } catch (InvalidArgumentException) {
        }
    } finally {
        @unlink($tmpName);
    }
});

hub_test('VoxCPM2 retains a Basic Clone profile when ASR transcription fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed ASR Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Retryable draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR is unavailable']
        );
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert(($result['transcription']['error'] ?? '') === 'asr_unavailable', 'ASR failure must be returned to the caller');
    hub_test_assert(hub_get_voice_profile_for_member($db, (int)$result['profile']['id'], $memberId) !== null, 'ASR failure must preserve the Basic Clone profile');
    hub_test_assert($result['profile']['prompt_text'] === null && $result['profile']['prompt_text_confirmed_at'] === null, 'failed ASR must leave an unconfirmed empty draft');
    hub_test_assert($result['profile']['transcription_status'] === 'failed' && $result['profile']['transcription_error'] === 'asr_unavailable', 'failed ASR must persist only its safe error code');
    $failureAudit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$result['profile']['id'] . " AND action = 'transcribe'")->fetchColumn();
    hub_test_assert(json_decode((string)$failureAudit, true) === ['status' => 'failed', 'error' => 'asr_unavailable'], 'ASR failure must be audited without transcript details');
});

hub_test('VoxCPM2 retranscribes a failed matching upload without a new profile', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed Reupload Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $profileId = 0;
    $retryMoveCalls = 0;

    try {
        $failed = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Failed upload', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable']
        );
        $profileId = (int)$failed['profile']['id'];
        $retried = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Ignored failed retry name', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$retryMoveCalls): bool {
                $retryMoveCalls++;
                return copy($from, $to);
            },
            static fn (): array => ['ok' => true, 'text' => 'reupload draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($retried['cache_hit'] === false && (int)$retried['profile']['id'] === $profileId, 'failed matching upload must retry the same profile instead of creating a duplicate');
        hub_test_assert($retried['profile']['transcription_status'] === 'ready' && $retried['profile']['prompt_text'] === 'reupload draft', 'failed matching upload must return its ready retry result');
        hub_test_assert($retryMoveCalls === 0, 'failed matching upload must retry managed bytes without staging another file');
    } finally {
        @unlink($tmpName);
        if ($profileId > 0 && hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 retries a failed owned profile to ready', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Retry Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Retry Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $profileId = 0;

    try {
        $failed = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Retryable draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR is unavailable']
        );
        $profileId = (int)$failed['profile']['id'];
        hub_test_assert(hub_test_throws(static fn (): array => hub_retry_voice_profile_transcription($db, $profileId, $otherMemberId)), 'retry must reject a nonowner');
        $retried = hub_retry_voice_profile_transcription(
            $db,
            $profileId,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'retry draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($retried['profile']['transcription_status'] === 'ready', 'retry must mark the owned profile ready');
        hub_test_assert($retried['profile']['transcription_error'] === null && $retried['profile']['prompt_text'] === 'retry draft', 'retry must clear the safe error and save its new draft');
    } finally {
        @unlink($tmpName);
        if ($profileId > 0 && hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 completes a matching transcription lease normally', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Lease Completion Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/lease_completion.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Lease completion draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing lease profile.');

    try {
        hub_test_assert((string)($claim['transcription_lease_token'] ?? '') !== '', 'pending transcription must claim a lease token');
        $result = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'completed draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert(($result['transcription']['ok'] ?? false) === true, 'matching transcription lease must complete normally');
        hub_test_assert($result['profile']['transcription_status'] === 'ready' && $result['profile']['transcription_lease_token'] === null && $result['profile']['transcription_started_at'] === null, 'completed transcription must clear its lease state');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 rolls back a transcription result when its audit cannot be written', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Audit Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/audit_failure.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Audit failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing audit failure lease profile.');
    $db->exec("CREATE TRIGGER voice_profile_transcribe_audit_failure
        BEFORE INSERT ON voice_profile_audit_logs
        WHEN NEW.voice_profile_id = " . $profileId . " AND NEW.action = 'transcribe'
        BEGIN
            SELECT RAISE(ABORT, 'transcribe_audit_failed');
        END");

    try {
        $result = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'must roll back', 'language' => 'en', 'device' => []]
        );
        $after = hub_get_voice_profile($db, $profileId);
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();
        hub_test_assert(($result['transcription']['error'] ?? '') === 'transcription_save_failed', 'audit failure must return a recoverable transcription save error');
        hub_test_assert($after !== null && $after['transcription_status'] === 'pending' && $after['prompt_text'] === null && $after['transcription_lease_token'] === $claim['transcription_lease_token'] && $after['transcription_started_at'] === $claim['transcription_started_at'], 'audit failure must roll back the fenced transcription state');
        hub_test_assert($transcribeCount === 0, 'audit failure must not leave a transcribe audit or a completed transcription state');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_transcribe_audit_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 fences an old transcription lease after confirmation', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirmed Lease Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirmed_lease.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirmed lease draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldClaim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing old lease profile.');
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'confirmed transcript');

    try {
        $lost = hub_run_voice_profile_transcription(
            $db,
            $oldClaim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'old result', 'language' => 'en', 'device' => []]
        );
        $after = hub_get_voice_profile($db, $profileId);
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();
        hub_test_assert(($lost['transcription']['error'] ?? '') === 'transcription_lost_lease', 'superseded ASR completion must return a safe lost-lease result');
        hub_test_assert($after !== null && $after['transcription_status'] === 'ready' && $after['prompt_text'] === $confirmed['prompt_text'] && $after['prompt_text_confirmed_at'] === $confirmed['prompt_text_confirmed_at'] && $after['transcription_lease_token'] === null && $after['transcription_started_at'] === null, 'old ASR completion must not overwrite a confirmed transcript');
        hub_test_assert($transcribeCount === 0, 'lost ASR completion must not write a transcribe audit');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 soft deletion fences an in-flight transcription lease', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Deleted Lease Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/deleted_lease.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Deleted lease draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing deleted lease profile.');

    try {
        hub_soft_delete_voice_profile($db, $profileId, $memberId);
        $before = $db->query('SELECT transcription_status, transcription_error, transcription_started_at, transcription_lease_token, deleted_at FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        $lost = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'late deleted result', 'language' => 'en', 'device' => []]
        );
        $after = $db->query('SELECT transcription_status, transcription_error, transcription_started_at, transcription_lease_token, deleted_at FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();

        hub_test_assert(is_array($before) && ($before['transcription_status'] ?? null) === 'failed' && array_key_exists('transcription_started_at', $before) && $before['transcription_started_at'] === null && array_key_exists('transcription_lease_token', $before) && $before['transcription_lease_token'] === null && (string)($before['deleted_at'] ?? '') !== '', 'soft delete must invalidate a pending transcription lease');
        hub_test_assert(($lost['transcription']['error'] ?? '') === 'transcription_lost_lease', 'late completion after deletion must return lost lease');
        hub_test_assert($after === $before, 'late completion after deletion must not mutate the deleted profile');
        hub_test_assert($transcribeCount === 0, 'late completion after deletion must not add a transcribe audit');
    } finally {
        @unlink($path);
    }
});

hub_test('VoxCPM2 soft delete keeps audio when the database mutation fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Delete Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/delete_db_failure.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Delete failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $db->exec("CREATE TRIGGER voice_profile_delete_failure
        BEFORE UPDATE OF deleted_at ON voice_profiles
        WHEN NEW.deleted_at IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'delete_db_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static function () use ($db, $profileId, $memberId): void {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }), 'soft delete must surface a database failure');
        hub_test_assert(is_file($path), 'database failure must leave the managed audio intact');
        hub_test_assert(hub_get_voice_profile($db, $profileId) !== null, 'database failure must leave the voice profile active');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_delete_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        } else {
            @unlink($path);
        }
    }
});

hub_test('VoxCPM2 stale lease recovery fences old ready and failed results', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Stale Lease Voice Owner');
    $readyPath = hub_voice_profile_storage_dir() . '/stale_lease_ready.wav';
    $failedPath = hub_voice_profile_storage_dir() . '/stale_lease_failed.wav';
    file_put_contents($readyPath, 'RIFFlease');
    file_put_contents($failedPath, 'RIFFlease');
    $readyId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale ready lease draft',
        'reference_audio_path' => $readyPath,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $failedId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale failed lease draft',
        'reference_audio_path' => $failedPath,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldReadyClaim = hub_get_voice_profile($db, $readyId) ?? throw new RuntimeException('Missing stale ready lease.');
    $oldFailedClaim = hub_get_voice_profile($db, $failedId) ?? throw new RuntimeException('Missing stale failed lease.');
    $db->prepare('UPDATE voice_profiles SET transcription_started_at = :started_at WHERE id IN (:ready_id, :failed_id)')
        ->execute([':started_at' => date('Y-m-d H:i:s', time() - 301), ':ready_id' => $readyId, ':failed_id' => $failedId]);
    $newReadyToken = null;
    $newFailedToken = null;

    try {
        $newReady = hub_retry_voice_profile_transcription(
            $db,
            $readyId,
            $memberId,
            static function () use ($db, $readyId, &$newReadyToken): array {
                $newReadyToken = (string)((hub_get_voice_profile($db, $readyId) ?? [])['transcription_lease_token'] ?? '');
                return ['ok' => true, 'text' => 'new ready', 'language' => 'en', 'device' => []];
            }
        );
        $newFailed = hub_retry_voice_profile_transcription(
            $db,
            $failedId,
            $memberId,
            static function () use ($db, $failedId, &$newFailedToken): array {
                $newFailedToken = (string)((hub_get_voice_profile($db, $failedId) ?? [])['transcription_lease_token'] ?? '');
                return ['ok' => false, 'error' => 'asr_unavailable'];
            }
        );
        $oldReady = hub_run_voice_profile_transcription($db, $oldReadyClaim, $memberId, static fn (): array => ['ok' => false, 'error' => 'asr_unavailable']);
        $oldFailed = hub_run_voice_profile_transcription($db, $oldFailedClaim, $memberId, static fn (): array => ['ok' => true, 'text' => 'old ready', 'language' => 'en', 'device' => []]);
        $readyAfter = hub_get_voice_profile($db, $readyId);
        $failedAfter = hub_get_voice_profile($db, $failedId);
        $readyAuditCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $readyId . " AND action = 'transcribe'")->fetchColumn();
        $failedAuditCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $failedId . " AND action = 'transcribe'")->fetchColumn();

        hub_test_assert($newReadyToken !== '' && $newReadyToken !== (string)($oldReadyClaim['transcription_lease_token'] ?? ''), 'stale recovery must atomically replace the ready lease token');
        hub_test_assert($newFailedToken !== '' && $newFailedToken !== (string)($oldFailedClaim['transcription_lease_token'] ?? ''), 'stale recovery must atomically replace the failed lease token');
        hub_test_assert(($newReady['transcription']['ok'] ?? false) === true && ($newFailed['transcription']['error'] ?? '') === 'asr_unavailable', 'new lease completions must retain their normal results');
        hub_test_assert(($oldReady['transcription']['error'] ?? '') === 'transcription_lost_lease' && ($oldFailed['transcription']['error'] ?? '') === 'transcription_lost_lease', 'old lease completions must be fenced after recovery');
        hub_test_assert($readyAfter !== null && $readyAfter['transcription_status'] === 'ready' && $readyAfter['prompt_text'] === 'new ready', 'old failed lease must not overwrite newer ready state');
        hub_test_assert($failedAfter !== null && $failedAfter['transcription_status'] === 'failed' && $failedAfter['transcription_error'] === 'asr_unavailable' && $failedAfter['prompt_text'] === null, 'old successful lease must not overwrite newer failed state');
        hub_test_assert($readyAuditCount === 1 && $failedAuditCount === 1, 'lost lease completions must not add success or failure audits');
    } finally {
        if (hub_get_voice_profile($db, $readyId) !== null) {
            hub_soft_delete_voice_profile($db, $readyId, $memberId, true);
        }
        if (hub_get_voice_profile($db, $failedId) !== null) {
            hub_soft_delete_voice_profile($db, $failedId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 retry leaves a confirmed ready profile untouched', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirmed Retry Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirmed_retry.wav';
    file_put_contents($path, 'RIFFconfirmed');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirmed retry draft',
        'reference_audio_path' => $path,
        'prompt_text' => 'initial draft',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'confirmed transcript');

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_retry_voice_profile_transcription(
            $db,
            $profileId,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'must not replace', 'language' => 'en', 'device' => []]
        )), 'retry must reject a ready profile');
        $after = hub_get_voice_profile($db, $profileId);
        hub_test_assert($after !== null && $after['prompt_text'] === $confirmed['prompt_text'] && $after['prompt_text_confirmed_at'] === $confirmed['prompt_text_confirmed_at'], 'ready retry rejection must preserve confirmed transcript text and timestamp');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 test reset clears managed voice profile WAVs', function (): void {
    $dir = hub_voice_profile_storage_dir();
    $productionDir = HUB_DATA_DIR . '/uploads/voice_profiles';
    $finalPath = $dir . '/voice_profile_99_' . str_repeat('a', 64) . '.wav';
    $stagingPath = $dir . '/voice_profile_stage_99_' . str_repeat('b', 32) . '.wav';
    file_put_contents($finalPath, 'final');
    file_put_contents($stagingPath, 'staging');

    try {
        hub_test_reset_db();
        hub_test_assert($dir !== $productionDir, 'test voice profile storage must not use the production upload directory');
        hub_test_assert(preg_match('/^3waaihub_test_voice_profiles_[a-f0-9]{32}$/', basename($dir)) === 1, 'test voice profile storage must use a random directory name');
        if (DIRECTORY_SEPARATOR !== '\\') {
            hub_test_assert((fileperms($dir) & 0777) === 0700, 'test voice profile storage must be private');
        }
        hub_test_assert((glob($dir . '/*.wav') ?: []) === [], 'test reset must clear all managed voice profile WAVs');
    } finally {
        @unlink($finalPath);
        @unlink($stagingPath);
    }
});

hub_test('VoxCPM2 guarded final test storage teardown removes only its requested root', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_voice_profile_teardown_' . bin2hex(random_bytes(8));
    $sibling = $root . '_sibling';
    if (!mkdir($root, 0700) || !mkdir($sibling, 0700)) {
        throw new RuntimeException('Cannot create test teardown fixtures.');
    }
    $rootWav = $root . '/owned.wav';
    $siblingWav = $sibling . '/other.wav';
    file_put_contents($rootWav, 'RIFFowned');
    file_put_contents($siblingWav, 'RIFFother');

    try {
        hub_test_remove_voice_profile_storage_dir($root);
        hub_test_assert(!file_exists($root), 'guarded teardown must remove its requested isolated root');
        hub_test_assert(is_file($siblingWav) && file_get_contents($siblingWav) === 'RIFFother', 'guarded teardown must leave neighboring temporary paths untouched');
    } finally {
        @unlink($rootWav);
        @rmdir($root);
        @unlink($siblingWav);
        @rmdir($sibling);
    }
});

hub_test('VoxCPM2 test reset refuses a symlinked voice profile directory', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_voice_profile_symlink_' . bin2hex(random_bytes(8));
    $link = $root . '_link';
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create symlink target fixture.');
    }
    $targetWav = $root . '/target.wav';
    file_put_contents($targetWav, 'RIFFtarget');
    if (!@symlink($root, $link)) {
        @unlink($targetWav);
        @rmdir($root);
        hub_test_skip('Symlink fixture is unavailable on this host.');
    }

    try {
        hub_test_assert(hub_test_throws(static fn (): string => hub_test_voice_profile_cleanup_dir($link)), 'test reset must refuse a symlinked voice profile directory');
        hub_test_assert(is_file($targetWav) && file_get_contents($targetWav) === 'RIFFtarget', 'symlink cleanup refusal must not delete the target WAV');
    } finally {
        @unlink($link);
        @unlink($targetWav);
        @rmdir($root);
    }
});

hub_test('VoxCPM2 test reset preserves production voice profile WAVs', function (): void {
    $productionDir = HUB_DATA_DIR . '/uploads/voice_profiles';
    if (!is_dir($productionDir) && !mkdir($productionDir, 0775, true) && !is_dir($productionDir)) {
        throw new RuntimeException('Cannot create production voice profile fixture directory.');
    }
    $productionPath = $productionDir . '/non_test_voice_profile_reset_guard.wav';
    file_put_contents($productionPath, 'RIFFproduction');

    try {
        hub_test_reset_db();
        hub_test_assert(hub_voice_profile_storage_dir() !== $productionDir, 'test storage override must remain separate from production uploads');
        hub_test_assert(is_file($productionPath) && file_get_contents($productionPath) === 'RIFFproduction', 'test reset must never delete a production voice profile WAV');
    } finally {
        @unlink($productionPath);
    }
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
        transcription_error TEXT NULL,
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
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, transcription_error, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :transcription_error, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('b', 64),
            ':transcription_error' => 'asr_unavailable',
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('c', 64),
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('DELETE FROM settings WHERE key = :key')
        ->execute([':key' => 'db_migration_voice_profiles_prompt_text_confirmed_at_v1']);
    $db->prepare('DELETE FROM settings WHERE key = :key')
        ->execute([':key' => 'db_migration_voice_profiles_transcription_state_v1']);
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
    hub_test_assert(array_key_exists('transcription_lease_token', $legacy ?? []), 'legacy voice profiles must migrate the nullable transcription lease token');
    hub_test_assert(hub_get_storage_setting($db, 'db_migration_voice_profiles_prompt_text_confirmed_at_v1') === '1', 'successful retry must mark transcript migration complete');
    hub_test_assert(($legacy['transcription_status'] ?? null) === 'ready', 'legacy transcript must migrate to ready');
    hub_test_assert((hub_get_voice_profile($db, 2)['transcription_status'] ?? null) === 'failed', 'recorded legacy transcription failure must migrate to failed');
    hub_test_assert((hub_get_voice_profile($db, 3)['transcription_status'] ?? null) === 'pending', 'unknown legacy transcription state must migrate to pending');
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

hub_test('VoxCPM2 gateway injects only confirmed Ultimate Clone prompts', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-ultimate',
        'mode' => 'tts',
        'name' => 'VoxCPM2 Ultimate Clone',
        'enabled' => 1,
    ]);
    $memberId = hub_create_api_member($db, 'Ultimate Clone Owner');
    $token = hub_create_api_token($db, $memberId, 'Ultimate Clone token', null, null);
    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/ultimate_reference.wav';
    $promptText = '已確認的私密參考字幕';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Ultimate profile',
        'reference_audio_path' => $wav,
        'prompt_text' => $promptText,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $payload = [
        'mode' => 'ultimate_clone',
        'text' => '要說的內容。',
        'voice_profile_id' => $profileId,
        'format' => 'wav',
    ];
    $context = ['member_id' => $memberId, 'token_id' => (int)$token['token_id']];

    try {
        $unconfirmed = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $unconfirmedBody = json_decode((string)($unconfirmed['response']['body'] ?? ''), true);
        hub_test_assert(($unconfirmed['response']['status'] ?? 0) === 409 && ($unconfirmedBody['error'] ?? '') === 'voice_profile_transcript_unconfirmed', 'ultimate clone must reject an unconfirmed transcript');

        hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $promptText);
        $prepared = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $body = json_decode((string)($prepared['body'] ?? ''), true);
        hub_test_assert(($body['reference_wav_path'] ?? '') === '/data/voice_profiles/ultimate_reference.wav', 'ultimate clone must map the managed reference WAV');
        hub_test_assert(($body['prompt_wav_path'] ?? '') === ($body['reference_wav_path'] ?? ''), 'ultimate clone must use the same managed prompt WAV');
        hub_test_assert(($body['prompt_text'] ?? '') === $promptText, 'ultimate clone must inject the confirmed transcript');
        hub_test_assert(!str_contains((string)$prepared['body'], HUB_ROOT), 'ultimate clone must not expose host paths');

        foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path', 'prompt_text'] as $forgedKey) {
            $forged = $payload;
            $forged[$forgedKey] = $forgedKey === 'prompt_text' ? 'forged transcript' : '/tmp/forged.wav';
            $blocked = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($forged, JSON_UNESCAPED_UNICODE));
            hub_test_assert(($blocked['response']['status'] ?? 0) === 400, 'gateway must reject forged ' . $forgedKey);
        }

        $audit = $db->query('SELECT action, mode, details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'use' ORDER BY id DESC LIMIT 1")->fetch();
        hub_test_assert(($audit['mode'] ?? '') === 'ultimate_clone', 'ultimate clone use must be audited by mode');
        hub_test_assert(!str_contains((string)($audit['details_json'] ?? ''), $promptText), 'ultimate transcript must not enter audit metadata');
        $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
        hub_test_assert(preg_match('/def manifest_payload\(.*?\n\n/s', $app, $manifestMatch) === 1 && !str_contains($manifestMatch[0], 'prompt_text'), 'TTS artifact manifest must not contain the prompt transcript');
        hub_test_assert(str_contains($app, 'kwargs["prompt_wav_path"] = str(prompt)') && str_contains($app, 'kwargs["prompt_text"] = request.prompt_text.strip()'), 'Ultimate Clone must pass managed prompt inputs to VoxCPM2');
        hub_test_assert(str_contains($app, '@app.exception_handler(RequestValidationError)') && !str_contains($app, 'str(exc).splitlines()'), 'TTS errors must not echo the internal prompt transcript');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
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
    foreach (['api.php?mode=tts', 'voice_prompt', 'voice_profile_id', 'compare_all', 'ultimate_clone'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground TTS UI missing ' . $needle);
    }
    $ttsStart = strpos($source, "\$selectedMode === 'tts'):");
    hub_test_assert($ttsStart !== false, 'playground TTS branch missing');
    $ttsEnd = strpos($source, '<?php elseif', $ttsStart);
    hub_test_assert($ttsEnd !== false, 'playground TTS branch end missing');
    $ttsBranch = substr($source, $ttsStart, $ttsEnd - $ttsStart);
    hub_test_assert(str_contains($ttsBranch, 'name="real_inference" type="checkbox" value="1" checked'), 'playground TTS real inference must be checked by default');
});

hub_test('VoxCPM2 playground provides managed three-mode comparison controls', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');

    foreach (['value="ultimate_clone"', 'name="compare_all"', 'name="reference_wav"', 'name="prompt_text"', 'name="voice_profile_id"', '$audioUrls'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground TTS comparison UI missing ' . $needle);
    }
    hub_test_assert(!str_contains($source, 'name="reference_audio_id"'), 'playground must not expose a free-form reference audio ID');

    $examplesStart = strpos($source, 'function hub_playground_examples');
    $ttsExamplesStart = $examplesStart === false ? false : strpos($source, "if (\$mode === 'tts') {", $examplesStart);
    $ttsExamplesEnd = strpos($source, "if (\$mode === 'chat') {", $ttsExamplesStart);
    hub_test_assert($ttsExamplesStart !== false && $ttsExamplesEnd !== false, 'playground TTS example block missing');
    $ttsExamples = substr($source, $ttsExamplesStart, $ttsExamplesEnd - $ttsExamplesStart);
    foreach (['prompt_text', 'reference_wav_path', 'prompt_wav_path', 'reference_audio_path'] as $forbidden) {
        hub_test_assert(!str_contains($ttsExamples, $forbidden), 'browser TTS examples must not expose ' . $forbidden);
    }
    $helper = (string)file_get_contents(HUB_ROOT . '/admin/_playground_voice_profiles.php');
    hub_test_assert(str_contains($source, 'hub_playground_tts_audio_urls($selectedService, $result)') && str_contains($helper, 'hub_playground_tts_audio_url'), 'comparison audio must use the protected artifact URL helper');
});

hub_test('VoxCPM2 playground lists only active profiles accessible to the bearer token member', function (): void {
    hub_test_assert(function_exists('hub_playground_tts_active_profiles'), 'playground active profile selector helper missing');

    $db = hub_test_reset_db();
    $viewerId = hub_create_api_member($db, 'Playground profile viewer');
    $ownerId = hub_create_api_member($db, 'Playground profile owner');
    $token = hub_create_api_token($db, $viewerId, 'Playground profile viewer TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    $wav = hub_voice_profile_storage_dir() . '/playground_selector.wav';
    file_put_contents($wav, 'RIFFselector');
    $ownId = hub_create_voice_profile($db, $viewerId, [
        'name' => 'Viewer active',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $sharedId = hub_create_voice_profile($db, $ownerId, [
        'name' => 'Shared active',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'shared',
    ]);
    $privateId = hub_create_voice_profile($db, $ownerId, [
        'name' => 'Private foreign',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $deletedId = hub_create_voice_profile($db, $viewerId, [
        'name' => 'Viewer deleted',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_soft_delete_voice_profile($db, $deletedId, $viewerId);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.38';

    try {
        $profiles = hub_playground_tts_active_profiles($db, (string)$token['plain_token']);
        $managementProfiles = hub_playground_tts_owned_profiles($db, (string)$token['plain_token']);
    } finally {
        $_SERVER = $oldServer;
    }

    $ids = array_map(static fn (array $profile): int => (int)$profile['id'], $profiles);
    sort($ids);
    $expected = [$ownId, $sharedId];
    sort($expected);
    hub_test_assert($ids === $expected, 'profile selector must include only active owned or shared profiles');
    hub_test_assert(!in_array($privateId, $ids, true) && !in_array($deletedId, $ids, true), 'profile selector must not expose private foreign or deleted profiles');
    hub_test_assert(array_map(static fn (array $profile): int => (int)$profile['id'], $managementProfiles) === [$ownId], 'management selectors must exclude shared profiles because their mutations are owner-only');
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, 'foreach ($ttsProfiles as $ttsProfile)') && substr_count($source, 'foreach ($ttsManagementProfiles as $ttsProfile)') === 2, 'execution and management selectors must use their distinct profile lists');
});

hub_test('VoxCPM2 playground returns a safe error when loading profiles with an invalid token', function (): void {
    hub_test_assert(function_exists('hub_playground_tts_profile_options_result'), 'playground profile-load action helper missing');

    $db = hub_test_reset_db();
    $result = hub_playground_tts_profile_options_result($db, 'invalid-playground-token');
    hub_test_assert(empty($result['ok']) && ($result['error'] ?? '') === 'voice_profile_request_failed', 'invalid profile load must return a generic action error');
    foreach (['invalid-playground-token', HUB_ROOT, 'reference_audio_path'] as $secret) {
        hub_test_assert(!str_contains((string)($result['pretty_body'] ?? ''), $secret), 'profile-load error must not expose token or storage details');
    }

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, "\$action === 'voice_profile_list'") && str_contains($source, 'hub_playground_tts_profile_options_result'), 'profile-load action must render the safe helper result');
});

hub_test('VoxCPM2 playground compares all modes sequentially and keeps each result', function (): void {
    hub_test_assert(function_exists('hub_playground_execute_tts'), 'playground TTS comparison executor missing');
    hub_test_assert(function_exists('hub_playground_tts_audio_urls'), 'playground TTS audio URL mapper missing');

    $oldPost = $_POST;
    $_POST = [
        'tts_mode' => 'clone',
        'compare_all' => '1',
        'text' => '比較三個聲音模式。',
        'voice_prompt' => '清楚自然',
        'voice_profile_id' => '42',
        'control' => '稍慢',
        'seed' => '42',
        'real_inference' => '1',
    ];
    $order = [];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode, array $payload, string $token) use (&$order): array {
            $expected = ['design', 'clone', 'ultimate_clone'];
            hub_test_assert($ttsMode === $expected[count($order)], 'comparison must wait for the preceding mode before the next request');
            hub_test_assert($payload['mode'] === $ttsMode, 'each comparison request must contain its own mode');
            hub_test_assert(($payload['voice_profile_id'] ?? null) === 42, 'clone requests must use the selected managed profile ID');
            hub_test_assert(!array_key_exists('prompt_text', $payload), 'playground must not send profile transcripts to the gateway');
            hub_test_assert($token === 'test-token', 'comparison must keep the transient bearer token server-side');
            $order[] = $ttsMode;

            return [
                'ok' => true,
                'status' => 200,
                'elapsed_ms' => 1,
                'request_id' => $ttsMode,
                'error' => '',
                'message' => '',
                'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']),
                'pretty_body' => '{}',
            ];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert($order === ['design', 'clone', 'ultimate_clone'], 'comparison must invoke modes in the documented sequence');
    hub_test_assert(array_keys($result['results'] ?? []) === ['design', 'clone', 'ultimate_clone'], 'comparison must retain independent results for every mode');
    $audioUrls = hub_playground_tts_audio_urls(['id' => 9], $result, static function (array $service, ?array $ttsResult): string {
        $artifact = json_decode((string)($ttsResult['body'] ?? ''), true);
        return 'playground_artifact.php?service_id=' . (int)$service['id'] . '&file=' . basename((string)($artifact['artifact_url'] ?? ''));
    });
    hub_test_assert(array_keys($audioUrls) === ['design', 'clone', 'ultimate_clone'], 'comparison must retain independent audio URLs for every mode');
    foreach ($audioUrls as $ttsMode => $audioUrl) {
        hub_test_assert(str_contains($audioUrl, 'playground_artifact.php?') && str_contains($audioUrl, 'file=tts_' . $ttsMode . '.wav'), 'comparison audio URL must use the protected artifact endpoint');
    }
});

hub_test('VoxCPM2 playground maps single TTS artifacts through the protected endpoint', function (): void {
    $artifactHelpers = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
    hub_test_assert(is_file($artifactHelpers), 'side-effect-free TTS artifact helpers missing');
    hub_test_assert(function_exists('hub_playground_tts_audio_url'), 'protected TTS artifact URL helper missing');

    $service = ['id' => 9];
    $result = ['ok' => true, 'body' => json_encode(['artifact_url' => '/artifacts/tts_design.wav'])];
    hub_test_assert(hub_playground_tts_audio_url($service, $result) === 'playground_artifact.php?service_id=9&file=tts_design.wav', 'single TTS artifact must use the protected endpoint URL');

    foreach (['https://example.test/tts_design.wav', '/artifacts/tts_design.mp3'] as $unsafeArtifactUrl) {
        hub_test_assert(hub_playground_tts_audio_url($service, ['ok' => true, 'body' => json_encode(['artifact_url' => $unsafeArtifactUrl])]) === '', 'unsafe TTS artifact URL must be rejected');
    }
});

hub_test('VoxCPM2 playground keeps the protected single-result audio fallback', function (): void {
    $oldPost = $_POST;
    $_POST = ['tts_mode' => 'design'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']),
                'pretty_body' => '{}',
            ];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(!array_key_exists('results', $result), 'single TTS execution must retain its direct gateway result');
    hub_test_assert(hub_playground_tts_audio_urls(['id' => 9], $result, static function (): string {
        throw new RuntimeException('single TTS result must not use the comparison audio mapper');
    }) === [], 'single TTS execution must leave comparison audio URLs empty');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, 'hub_playground_tts_audio_url($selectedService, $result)'), 'single TTS result must use the protected artifact fallback');
    $singleAudioStart = strpos($source, "elseif (\$audioUrl !== '')");
    $singleAudioEnd = $singleAudioStart === false ? false : strpos($source, '<?php endif; ?>', $singleAudioStart);
    $singleAudio = $singleAudioStart === false || $singleAudioEnd === false ? '' : substr($source, $singleAudioStart, $singleAudioEnd - $singleAudioStart);
    hub_test_assert(str_contains($singleAudio, '<audio controls src="<?= hub_h($audioUrl) ?>"></audio>'), 'single TTS result must render its protected audio player');
});

hub_test('VoxCPM2 playground preserves an unconfirmed Ultimate Clone result', function (): void {
    $oldPost = $_POST;
    $_POST = ['compare_all' => '1', 'voice_profile_id' => '42'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            if ($ttsMode === 'ultimate_clone') {
                return ['ok' => false, 'status' => 409, 'error' => 'voice_profile_transcript_unconfirmed', 'message' => 'confirmed transcript required', 'pretty_body' => '{}'];
            }

            return ['ok' => true, 'status' => 200, 'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']), 'pretty_body' => '{}'];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(($result['results']['design']['ok'] ?? false) === true && ($result['results']['clone']['ok'] ?? false) === true, 'successful design and clone results must remain available');
    hub_test_assert(($result['results']['ultimate_clone']['status'] ?? 0) === 409 && ($result['results']['ultimate_clone']['error'] ?? '') === 'voice_profile_transcript_unconfirmed', 'unconfirmed Ultimate Clone must remain its own result');
});

hub_test('VoxCPM2 playground gives an all-failed comparison a concrete aggregate error', function (): void {
    $oldPost = $_POST;
    $_POST = ['compare_all' => '1', 'voice_profile_id' => '42'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            return ['ok' => false, 'status' => 409, 'error' => 'voice_profile_transcript_unconfirmed', 'message' => $ttsMode, 'pretty_body' => '{}'];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(empty($result['ok']) && ($result['status'] ?? null) === 500 && ($result['error'] ?? '') === 'tts_comparison_failed', 'all-failed comparison must not be reported as mixed');
    hub_test_assert(array_keys($result['results'] ?? []) === ['design', 'clone', 'ultimate_clone'], 'all-failed comparison must preserve each mode result');
});

hub_test('VoxCPM2 playground manages voice profiles with request-scoped TTS tokens', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(is_file($helperFile), 'playground voice-profile helper include missing');
    $helper = (string)file_get_contents($helperFile);

    foreach (['voice_profile_upload', 'voice_profile_confirm', 'voice_profile_retry_asr'] as $action) {
        hub_test_assert(str_contains($source, 'name="action" value="' . $action . '"'), 'playground must provide POST action ' . $action);
    }
    hub_test_assert(str_contains($source, "require_once __DIR__ . '/_playground_voice_profiles.php';"), 'playground must load the voice-profile helper');
    foreach ([
        "hub_gateway_authenticate_api_token(\$db, 'tts', hub_get_client_ip(), \$token)",
        'hub_create_uploaded_voice_profile',
        'hub_confirm_voice_profile_prompt',
        'hub_retry_voice_profile_transcription',
    ] as $needle) {
        hub_test_assert(str_contains($helper, $needle), 'playground voice-profile helper missing ' . $needle);
    }
    hub_test_assert(str_contains($source, 'hub_check_csrf()'), 'playground voice-profile flow must keep CSRF protection');

    $examplesStart = strpos($source, 'function hub_playground_examples');
    $ttsExamplesStart = $examplesStart === false ? false : strpos($source, "if (\$mode === 'tts') {", $examplesStart);
    $ttsExamplesEnd = strpos($source, "if (\$mode === 'chat') {", $ttsExamplesStart);
    hub_test_assert($examplesStart !== false && $ttsExamplesStart !== false && $ttsExamplesEnd !== false, 'playground TTS example block missing');
    $ttsExamples = substr($source, $ttsExamplesStart, $ttsExamplesEnd - $ttsExamplesStart);
    foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path'] as $forbidden) {
        hub_test_assert(!str_contains($ttsExamples, $forbidden), 'browser TTS payload must not contain ' . $forbidden);
    }
    hub_test_assert(!str_contains($source, 'mode=voice_profile_transcribe'), 'playground must not expose a public voice-profile transcription API mode');
});

hub_test('VoxCPM2 playground resolves voice-profile ownership from the TTS token', function (): void {
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(is_file($helperFile), 'playground TTS token helper include missing');
    require_once $helperFile;
    hub_test_assert(function_exists('hub_playground_tts_member_id'), 'playground TTS token helper missing');

    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Playground Voice Token Owner');
    $token = hub_create_api_token($db, $memberId, 'Playground TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    $unscoped = hub_create_api_token($db, $memberId, 'Playground unscoped token', null, null);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.34';

    try {
        hub_test_assert(
            hub_playground_tts_member_id($db, (string)$token['plain_token']) === $memberId,
            'playground must use the authenticated TTS token member as voice-profile owner'
        );
        hub_test_assert(
            hub_test_throws(static fn (): int => hub_playground_tts_member_id($db, (string)$unscoped['plain_token'])),
            'playground must reject a token without TTS access'
        );
    } finally {
        $_SERVER = $oldServer;
    }
});

hub_test('VoxCPM2 playground rejects foreign token voice-profile mutations with a redacted error', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(str_contains($source, "require_once __DIR__ . '/_playground_voice_profiles.php';"), 'playground controller must load its voice-profile dispatcher');
    hub_test_assert(is_file($helperFile), 'playground voice-profile dispatcher include missing');
    require_once $helperFile;

    $db = hub_test_reset_db();
    $ownerMemberId = hub_create_api_member($db, 'Playground Profile Owner');
    $foreignMemberId = hub_create_api_member($db, 'Playground Profile Foreign Member');
    $foreignToken = hub_create_api_token($db, $foreignMemberId, 'Playground foreign TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$foreignToken['token_id'], 'tts');
    $dir = hub_voice_profile_storage_dir();
    $wav = $dir . '/playground_foreign_owner.wav';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $ownerMemberId, [
        'name' => 'Owner-only profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.35';

    try {
        $tokenMemberId = hub_playground_tts_member_id($db, (string)$foreignToken['plain_token']);
        hub_test_assert($tokenMemberId === $foreignMemberId, 'playground must derive the mutation owner from the supplied token');
        $confirm = hub_playground_voice_profile_dispatch($db, 'voice_profile_confirm', (string)$foreignToken['plain_token'], [
            'voice_profile_id' => $profileId,
            'prompt_text' => 'foreign transcript',
        ], []);
        $retry = hub_playground_voice_profile_dispatch($db, 'voice_profile_retry_asr', (string)$foreignToken['plain_token'], [
            'voice_profile_id' => $profileId,
        ], []);
        hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text'] ?? null) === null, 'foreign token mutation must leave the owner profile unchanged');

        foreach ([$confirm, $retry] as $error) {
            $body = (string)($error['pretty_body'] ?? '');
            hub_test_assert(($error['error'] ?? '') === 'voice_profile_request_failed', 'playground controller error must stay generic');
            foreach ([$wav, 'foreign transcript', 'voice_profile_forbidden'] as $secret) {
                hub_test_assert(!str_contains($body, $secret), 'playground controller error must redact ' . $secret);
            }
        }
    } finally {
        $_SERVER = $oldServer;
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $ownerMemberId, true);
        }
    }
});

hub_test('VoxCPM2 playground exposes generated WAV through authenticated audio player', function (): void {
    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $artifactHelpers = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
    hub_test_assert(is_file($artifactHelpers), 'playground TTS artifact helper missing');
    hub_test_assert(str_contains($playground, "require_once __DIR__ . '/_playground_tts_artifacts.php';"), 'playground must load the TTS artifact helper');
    hub_test_assert(str_contains((string)file_get_contents($artifactHelpers), 'playground_artifact.php'), 'playground must link TTS artifacts through admin artifact endpoint');
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
