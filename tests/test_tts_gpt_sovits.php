<?php
declare(strict_types=1);

function hub_test_gpt_sovits_write_pcm_wav(string $path, int $seconds, int $rate = 16000, int $channels = 1): void
{
    $frames = str_repeat("\x00\x00", $seconds * $rate * $channels);
    $byteRate = $rate * $channels * 2;
    $blockAlign = $channels * 2;
    $wav = 'RIFF' . pack('V', 36 + strlen($frames)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, $channels, $rate, $byteRate, $blockAlign, 16)
        . 'data' . pack('V', strlen($frames)) . $frames;
    if (file_put_contents($path, $wav) === false) {
        throw new RuntimeException('Cannot create GPT-SoVITS WAV fixture.');
    }
}

function hub_test_gpt_sovits_create_profile(PDO $db, int $memberId): array
{
    $path = hub_voice_profile_storage_dir() . '/gpt_sovits_test_' . bin2hex(random_bytes(8)) . '.wav';
    hub_test_gpt_sovits_write_pcm_wav($path, 3);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Generic GPT-SoVITS profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
    ]);

    return ['id' => $profileId, 'path' => $path, 'sha256' => (string)hash_file('sha256', $path)];
}

hub_test('GPT-SoVITS is a separate governed audio mode', function (): void {
    hub_test_assert((hub_pack_job_async_routes()['voice_generate_gpt_sovits'] ?? null) === [
        'pack_id' => 'tts-gpt-sovits',
        'job' => 'synthesize',
        'accelerator' => 'gpu',
    ], 'GPT-SoVITS async route mismatch');

    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'GPT-SoVITS must expose its verified benchmark-ready level');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'GPT-SoVITS target level mismatch');
    hub_test_assert(($manifest['tts_modes'] ?? []) === ['clone', 'ultimate_clone'], 'GPT-SoVITS must expose clone modes only');
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(($job['runner']['required_vram_mb'] ?? 0) === 6144, 'GPT-SoVITS cold GPU budget mismatch');
    hub_test_assert(($job['resident']['protocol'] ?? '') === 'service_data_v1', 'GPT-SoVITS resident protocol mismatch');
    hub_test_assert(in_array('pretrained_models/chinese-roberta-wwm-ext-large/tokenizer.json', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline RoBERTa tokenizer');
    hub_test_assert(in_array('nltk_data/corpora/cmudict/cmudict', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline English pronunciation dictionary');
    hub_test_assert(in_array('g2pw/g2pW.onnx', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline G2PW model');
});

hub_test('GPT-SoVITS clone profile jobs use the existing signed snapshot contract', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $job = hub_pack_async_job_contract($pack['manifest'], 'synthesize');
    $context = $job['voice_context'] ?? [];

    hub_test_assert(($context['clone_value'] ?? '') === 'clone', 'GPT-SoVITS clone snapshot mismatch');
    hub_test_assert(($context['ultimate_value'] ?? '') === 'ultimate_clone', 'GPT-SoVITS ultimate clone snapshot mismatch');
    hub_test_assert(!array_key_exists('design_value', $context), 'GPT-SoVITS must not declare design mode');
    hub_test_assert(!array_key_exists('design_prompt_input', $context), 'GPT-SoVITS must not declare a design prompt');
});

hub_test('GPT-SoVITS publishes clone-only profile API documentation', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $route = hub_pack_async_job_contract($pack['manifest'], 'synthesize');
    $contract = hub_public_api_pack_job_async_contract($route + [
        'requested_mode' => 'voice_generate_gpt_sovits',
    ]);
    $operations = array_column((array)($contract['operations'] ?? []), null, 'operation');
    hub_test_assert(
        ($operations['synthesize']['modes'] ?? null) === ['clone', 'ultimate_clone'],
        'GPT-SoVITS public contract must expose clone modes only'
    );
    foreach ((array)($contract['workflow_examples'] ?? []) as $example) {
        hub_test_assert(
            str_contains((string)$example, 'mode=voice_generate_gpt_sovits')
            && !str_contains((string)$example, 'mode=design'),
            'GPT-SoVITS workflow example must use its own mode without design'
        );
    }
    hub_test_assert(hub_is_voice_profile_mode('voice_generate_gpt_sovits'), 'GPT-SoVITS must use the managed voice profile family');
});

hub_test('GPT-SoVITS profile preparation transcribes its derived reference WAV', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-gpt-sovits', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'GPT-SoVITS alignment owner');
        $token = hub_create_api_token($db, $memberId, 'GPT-SoVITS alignment token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate_gpt_sovits']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $rawPath = tempnam(sys_get_temp_dir(), 'gpt-sovits-raw-');
        if ($rawPath === false) {
            throw new RuntimeException('Cannot create GPT-SoVITS raw fixture.');
        }
        hub_test_gpt_sovits_write_pcm_wav($rawPath, 12);
        $rawSha = hash_file('sha256', $rawPath);

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=gpt-sovits-alignment';
            $response = hub_test_audio_request($db, 'voice_generate_gpt_sovits', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Aligned reference',
                'consent_type' => 'self_recorded',
            ], [], ['reference_wav' => [
                'name' => 'reference.wav',
                'type' => 'audio/wav',
                'tmp_name' => $rawPath,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($rawPath),
            ]]);
            $payload = hub_test_audio_payload($response);
            hub_test_assert($response['status'] === 200, 'GPT-SoVITS profile_prepare must enqueue a task');
            $task = hub_get_task($db, (int)($payload['task_id'] ?? 0));
            $profile = hub_get_voice_profile($db, (int)($task['input']['voice_profile_id'] ?? 0));
            hub_test_assert($task !== null && $profile !== null, 'GPT-SoVITS preparation must create its managed profile');
            $rawReferencePath = (string)$profile['reference_audio_path'];

            $asrUploadSha = '';
            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $claimed ?? [], static function (array $upload) use (&$asrUploadSha): array {
                $asrUploadSha = (string)hash_file('sha256', (string)$upload['tmp_name']);
                return ['ok' => true, 'text' => 'derived draft', 'language' => 'en'];
            });

            $profile = hub_get_voice_profile($db, (int)$profile['id']) ?? throw new RuntimeException('Prepared GPT-SoVITS profile is missing.');
            hub_test_assert(($profile['reference_contract'] ?? '') === 'gpt_sovits_v1', 'GPT-SoVITS must mark its derived reference contract');
            hub_test_assert($asrUploadSha === (string)$profile['reference_audio_sha256'] && $asrUploadSha !== $rawSha, 'GPT-SoVITS ASR must receive the derived reference WAV');
            hub_test_assert(hub_voice_profile_is_gpt_sovits_reference_wav((string)$profile['reference_audio_path']) && !file_exists($rawReferencePath), 'GPT-SoVITS must retain only its valid derived reference WAV');
        } finally {
            if (is_file($rawPath)) {
                unlink($rawPath);
            }
        }
    });
});

hub_test('GPT-SoVITS profile preparation rejects a client transcript', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-gpt-sovits', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'GPT-SoVITS transcript owner');
        $token = hub_create_api_token($db, $memberId, 'GPT-SoVITS transcript token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate_gpt_sovits']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $rawPath = tempnam(sys_get_temp_dir(), 'gpt-sovits-transcript-');
        if ($rawPath === false) {
            throw new RuntimeException('Cannot create GPT-SoVITS transcript fixture.');
        }
        hub_test_gpt_sovits_write_pcm_wav($rawPath, 3);

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=gpt-sovits-transcript';
            $response = hub_test_audio_request($db, 'voice_generate_gpt_sovits', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Client transcript',
                'consent_type' => 'self_recorded',
                'prompt_text' => 'This must come from ASR.',
            ], [], ['reference_wav' => [
                'name' => 'reference.wav',
                'type' => 'audio/wav',
                'tmp_name' => $rawPath,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($rawPath),
            ]]);
            $payload = hub_test_audio_payload($response);
            hub_test_assert($response['status'] === 400 && ($payload['error'] ?? '') === 'voice_profile_transcript_invalid', 'GPT-SoVITS must only confirm its derived ASR draft');
        } finally {
            if (is_file($rawPath)) {
                unlink($rawPath);
            }
        }
    });
});

hub_test('GPT-SoVITS admission requires an aligned profile contract', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-gpt-sovits', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'GPT-SoVITS admission owner');
        $token = hub_create_api_token($db, $memberId, 'GPT-SoVITS admission token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate_gpt_sovits']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $profile = hub_test_gpt_sovits_create_profile($db, $memberId);
        $before = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'pack_job'")->fetchColumn();

        $response = hub_test_audio_request($db, 'voice_generate_gpt_sovits', (string)$token['plain_token'], [
            'text' => '請說明 RC Valve。',
            'mode' => 'clone',
            'voice_profile_id' => (string)$profile['id'],
        ]);
        $payload = hub_test_audio_payload($response);
        hub_test_assert(
            $response['status'] === 409
            && ($payload['error'] ?? '') === 'voice_profile_reprepare_required'
            && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'pack_job'")->fetchColumn() === $before,
            'generic GPT-SoVITS profile must fail before queue admission'
        );
    });
});

hub_test('GPT-SoVITS mount revalidates its aligned profile contract', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-gpt-sovits', ['idempotent' => true]);
    $memberId = hub_create_api_member($db, 'GPT-SoVITS runner owner');
    $profile = hub_test_gpt_sovits_create_profile($db, $memberId);
    $route = hub_resolve_audio_async_route($db, 'voice_generate_gpt_sovits');
    $task = [
        'owner_member_id' => $memberId,
        'requested_mode' => 'voice_generate_gpt_sovits',
        'input' => [
            'mode' => 'clone',
            'voice_profile_id' => $profile['id'],
            'voice_context' => [
            'mode' => 'clone',
            'voice_profile_id' => $profile['id'],
            'reference_audio_sha256' => $profile['sha256'],
            'container_path' => '/data/voice_profiles/reference.wav',
            ],
        ],
    ];
    try {
        hub_pack_job_resolve_voice_profile_mount($db, $task, $route);
        $error = '';
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    hub_test_assert($error === 'voice_profile_reprepare_required', 'GPT-SoVITS runner must reject a generic profile before mounting it');
});

hub_test('GPT-SoVITS generated Compose builds from the Pack root', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');

    $compose = hub_generate_pack_compose($pack, 'gpt-sovits-build-root', 18109);
    hub_test_assert(str_contains($compose, 'context: ' . $pack['dir'] . "\n"), 'GPT-SoVITS build context must include service and jobs directories');
    hub_test_assert(str_contains($compose, 'dockerfile: service/Dockerfile'), 'GPT-SoVITS build must retain its service Dockerfile');
    hub_test_assert(str_contains($compose, 'image: 3waaihub/tts-gpt-sovits:0.1.0'), 'GPT-SoVITS service must reuse its runner image');
    hub_test_assert(hub_service_image_tag(['pack_id' => 'tts-gpt-sovits', 'pack_version' => '0.1.0', 'service_key' => 'gpt-sovits-build-root']) === '3waaihub/tts-gpt-sovits:0.1.0', 'GPT-SoVITS service image tag mismatch');
});

hub_test('GPT-SoVITS image exposes upstream top-level modules', function (): void {
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-gpt-sovits/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'PYTHONPATH=/opt/gpt-sovits:/opt/gpt-sovits/GPT_SoVITS:/opt/gpt-sovits/GPT_SoVITS/eres2net'), 'GPT-SoVITS must expose the upstream AR and ERes2Net module paths');
    hub_test_assert(str_contains($dockerfile, '/models/gpt_sovits/g2pw'), 'GPT-SoVITS must resolve the upstream G2PW path from the managed model mount');
});

hub_test('VoxCPM2 public route remains unchanged', function (): void {
    hub_test_assert((hub_pack_job_async_routes()['voice_generate']['pack_id'] ?? '') === 'tts-voxcpm2', 'VoxCPM2 route changed');
});
