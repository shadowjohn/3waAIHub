<?php
declare(strict_types=1);

const HUB_TEST_BREEZY_MODEL_REVISION = 'e33b502e0ac21c16b0ee0d00df66ac3fa737393d';
const HUB_TEST_BREEZY_UPSTREAM_REVISION = 'd592c9d3e8927a0f53f68616387060dcd32a05ea';
const HUB_TEST_BREEZY_IMAGE = '3waaihub/tts-breezyvoice:0.1.1-cu128';
const HUB_TEST_BREEZY_PACK_PASCAL_IMAGE = '3waaihub/tts-breezyvoice:0.1.1-pascal-cu118';

hub_test('BreezyVoice Pack is an on-demand Taiwan Mandarin ultimate clone contract', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'BreezyVoice Pack must be valid');

    $manifest = $pack['manifest'];
    $targets = $manifest['platform_targets'] ?? [];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    $context = $job['voice_context'] ?? [];
    $artifacts = $job['artifact_contract']['artifacts'] ?? [];

    hub_test_assert(
        ($manifest['schema_version'] ?? '') === '0.1'
        && ($manifest['id'] ?? '') === 'tts-breezyvoice'
        && ($manifest['name'] ?? '') === 'BreezyVoice Taiwan Mandarin Clone'
        && ($manifest['version'] ?? '') === '0.1.1'
        && ($manifest['category'] ?? '') === 'audio'
        && ($manifest['type'] ?? '') === 'api_service'
        && ($manifest['execution_type'] ?? '') === 'async_task'
        && ($manifest['runtime_level'] ?? '') === 'L2-container-runner'
        && ($manifest['target_level'] ?? '') === 'L5-benchmark-ready'
        && ($manifest['runtime_ready'] ?? false) === true
        && ($manifest['default_mode'] ?? '') === 'voice_generate_breezy'
        && ($manifest['capability'] ?? '') === 'taiwan_mandarin_voice_clone',
        'BreezyVoice must publish the pinned Linux runtime identity'
    );

    hub_test_assert(
        array_keys($targets) === ['linux-docker', 'windows-wsl2-linux-docker']
        && ($targets['linux-docker']['supported'] ?? null) === true
        && ($targets['windows-wsl2-linux-docker']['supported'] ?? null) === true,
        'BreezyVoice must declare direct Linux and explicit Windows WSL targets'
    );
    hub_test_assert(
        ($manifest['lifecycle']['lifecycle'] ?? '') === 'on_demand'
        && ($manifest['lifecycle']['gpu_policy'] ?? '') === 'exclusive_gpu',
        'BreezyVoice must use an exclusive on-demand GPU lifecycle'
    );
    hub_test_assert(
        ($manifest['hardware'] ?? null) === [
            'gpu_required' => true,
            'gpu_supported' => true,
            'cpu_fallback' => false,
            'min_vram_mb' => 4096,
            'min_compute_capability' => '6.1',
        ]
        && ($manifest['lifecycle'] ?? null) === [
            'lifecycle' => 'on_demand',
            'gpu_policy' => 'exclusive_gpu',
            'idle_unload_seconds' => 0,
        ]
        && ($manifest['queue'] ?? null) === [
            'supported' => true,
            'default_queue' => 'gpu',
            'max_concurrency' => 1,
        ],
        'BreezyVoice must reserve one exclusive Blackwell-compatible GPU job'
    );
    hub_test_assert(
        ($manifest['tts_modes'] ?? null) === ['ultimate_clone']
        && ($job['input_fields'] ?? null) === ['text', 'mode', 'voice_profile_id', 'voice_profile_task_id', 'seed', 'seed_policy', 'pronunciation']
        && ($job['request_schema']['mode']['enum'] ?? null) === ['ultimate_clone']
        && $context === [
            'mode_input' => 'mode',
            'ultimate_value' => 'ultimate_clone',
            'profile_input' => 'voice_profile_id',
            'profile_task_input' => 'voice_profile_task_id',
            'container_path' => '/data/voice_profiles/reference.wav',
        ],
        'BreezyVoice must accept only a transcript-confirmed Ultimate Clone context'
    );
    hub_test_assert(
        array_column($artifacts, 'path') === ['generated_audio.wav', 'synthesis_metadata.json']
        && ($artifacts[0]['type'] ?? '') === 'generated_audio'
        && ($artifacts[0]['mime_types'] ?? null) === ['audio/wav', 'audio/x-wav']
        && ($artifacts[1]['type'] ?? '') === 'synthesis_metadata'
        && ($artifacts[1]['mime_types'] ?? null) === ['application/json']
        && ($artifacts[1]['json']['required_keys'] ?? null) === [
            'model', 'model_revision', 'upstream_revision', 'reference_audio_sha256', 'transcript_sha256',
            'seed', 'seed_applied', 'reproducibility', 'device', 'final_format', 'audio_sha256', 'audio_size_bytes',
        ],
        'BreezyVoice must emit its fixed audio and synthesis metadata artifacts'
    );

    hub_test_assert(
        ($manifest['wsl_runtime_profiles'] ?? null) === [
            'default' => [
                'id' => 'default',
                'dockerfile' => 'service/Dockerfile',
                'image' => HUB_TEST_BREEZY_IMAGE,
                'min_compute_capability' => '12.0',
                'gpu_name_patterns' => ['RTX 50'],
            ],
            'pascal-cu118' => [
                'id' => 'pascal-cu118',
                'dockerfile' => 'service/Dockerfile.pascal-cu118',
                'image' => HUB_TEST_BREEZY_PACK_PASCAL_IMAGE,
                'min_compute_capability' => '6.1',
                'gpu_name_patterns' => ['GTX 1050', 'GTX 1060', 'GTX 1070', 'GTX 1080', 'GTX 1080 Ti'],
            ],
        ]
        && ($manifest['runner_build'] ?? null) === [
            'context' => '.',
            'dockerfile' => 'service/Dockerfile',
            'image' => HUB_TEST_BREEZY_IMAGE,
        ]
        && ($job['runner']['image'] ?? '') === HUB_TEST_BREEZY_IMAGE
        && ($job['runner']['entrypoint'] ?? null) === ['/app/voice_generate.sh']
        && ($job['runner']['args'] ?? null) === ['{workspace}', '{input_dir}', '{output_dir}', '{input_dir}/runner_config.json']
        && ($job['runner']['accelerator'] ?? '') === 'gpu'
        && ($job['runner']['required_vram_mb'] ?? 0) === 4096
        && !array_key_exists('workspace_user', $job['runner']),
        'BreezyVoice must pin its CUDA 12 isolated-GPU runner without a shell'
    );
    $generatedCompose = hub_generate_pack_compose($pack, 'breezy-compose-test', 18101);
    hub_test_assert(
        str_contains($generatedCompose, 'image: ' . HUB_TEST_BREEZY_IMAGE)
        && str_contains($generatedCompose, 'context: ' . $pack['dir'] . "\n")
        && str_contains($generatedCompose, 'dockerfile: service/Dockerfile')
        && hub_service_image_tag([
            'pack_id' => 'tts-breezyvoice',
            'pack_version' => '0.1.1',
            'service_key' => 'breezy-compose-test',
        ]) === HUB_TEST_BREEZY_IMAGE,
        'BreezyVoice managed service must reuse its declared runner image and Pack-root build context'
    );
    $breezyAssetMount = $job['runner']['asset_mounts'] ?? null;
    $breezyTrustedModel = $job['runner_config']['aliases']['best_effort'] ?? null;
    hub_test_assert(
        $breezyAssetMount === [[
            'id' => 'breezyvoice_model',
            'storage' => 'models',
            'host_subdir' => 'breezyvoice',
            'container_path' => '/models/breezyvoice',
            'required_paths' => ['model-manifest.json'],
        ]]
        && ($job['runner_config']['materializer'] ?? null) === 'breezyvoice_ultimate_v1'
        && is_array($breezyTrustedModel)
        && ($breezyTrustedModel['model_dir'] ?? null) === '/models/breezyvoice'
        && ($breezyTrustedModel['model_revision'] ?? '') === HUB_TEST_BREEZY_MODEL_REVISION
        && ($breezyTrustedModel['upstream_revision'] ?? '') === HUB_TEST_BREEZY_UPSTREAM_REVISION,
        'BreezyVoice runner must mount only the immutable offline model manifest read-only'
    );

    $compose = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/docker-compose.yml');
    $settings = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/runtime-settings.example.conf');
    hub_test_assert(
        str_contains($compose, 'image: ' . HUB_TEST_BREEZY_IMAGE)
        && str_contains($compose, '127.0.0.1:18111:8000')
        && str_contains($compose, 'runtime-settings.conf')
        && str_contains($compose, '${AIHUB_MODELS_DIR:-/DATA/models}/breezyvoice:/models/breezyvoice')
        && str_contains($compose, '${AIHUB_CACHE_DIR:-/DATA/3waAIHub/data/cache}/breezyvoice:/cache/breezyvoice')
        && str_contains($compose, '${SERVICE_DATA_DIR:-/DATA/3waAIHub/data/services/tts-breezyvoice-main}:/data/service')
        && !str_contains($compose, '/mnt/d')
        && !str_contains($compose, '.env'),
        'BreezyVoice compose must remain loopback-only on WSL ext4 storage'
    );
    hub_test_assert(
        $settings === "BREEZYVOICE_EXECUTION_MODE=resident\n"
            . "BREEZYVOICE_RESIDENT_MIN_FREE_VRAM_MB=1024\n"
            . "BREEZYVOICE_INTERNAL_JOB_TOKEN=\n"
            . "BREEZYVOICE_MODEL_ID=MediaTek-Research/BreezyVoice\n"
            . "BREEZYVOICE_MODEL_REVISION=" . HUB_TEST_BREEZY_MODEL_REVISION . "\n"
            . "BREEZYVOICE_UPSTREAM_REPOSITORY=https://github.com/mtkresearch/BreezyVoice.git\n"
            . "BREEZYVOICE_UPSTREAM_REVISION=" . HUB_TEST_BREEZY_UPSTREAM_REVISION . "\n"
            . "BREEZYVOICE_REAL_INFERENCE=1\n"
            . "BREEZYVOICE_DEVICE=cuda\n"
            . "BREEZYVOICE_SAMPLE_RATE=22050\n"
            . "BREEZYVOICE_MAX_INPUT_CHARS=2000\n"
            . "GPU_VISIBLE_DEVICES=all\n",
        'BreezyVoice example settings must keep the exact runnable revisions'
    );
});

hub_test('BreezyVoice accepts only bounded literal pronunciation overrides', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    $route = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), 'synthesize');
    $route['pack_id'] = 'tts-breezyvoice';
    $valid = [
        'text' => 'AI 協助檢查濾心。',
        'mode' => 'ultimate_clone',
        'voice_profile_task_id' => '41',
        'pronunciation' => [
            'character_overrides' => [[
                'id' => 'character:axian:ai',
                'match' => 'AI',
                'kind' => 'spoken_form',
                'value' => '欸哀',
            ]],
            'request_overrides' => [[
                'id' => 'podcast:49:filter',
                'match' => '濾心',
                'kind' => 'bopomofo',
                'readings' => ['ㄌㄩ4', 'ㄒㄧㄣ1'],
            ]],
        ],
    ];
    $invalid = $valid;
    $invalid['pronunciation']['request_overrides'][0]['readings'] = ['ㄌㄩ4'];
    $unsafe = $valid;
    $unsafe['pronunciation']['character_overrides'][0]['value'] = '[:ㄞ1]';
    $invalidCode = static function (array $input) use ($route): ?string {
        try {
            hub_pack_job_task_input($input, $route);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        }

        return null;
    };
    $normalized = hub_pack_job_task_input($valid, $route);

    hub_test_assert(
        ($route['input_fields'] ?? null) === ['text', 'mode', 'voice_profile_id', 'voice_profile_task_id', 'seed', 'seed_policy', 'pronunciation']
        && ($route['request_schema']['pronunciation'] ?? null) === ['type' => 'object', 'required' => false, 'max_bytes' => 65536]
        && ($normalized['pronunciation'] ?? null) === $valid['pronunciation']
        && $invalidCode($invalid) === 'invalid_pronunciation_rules'
        && $invalidCode($unsafe) === 'invalid_pronunciation_rules',
        'BreezyVoice pronunciation overrides must remain bounded literal rules owned by the caller'
    );
});

hub_test('BreezyVoice materializes a closed trusted runner config from the confirmed ultimate snapshot', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    $contract = hub_pack_async_job_contract($manifest, 'synthesize');
    if (!is_array($contract)) {
        throw new RuntimeException('BreezyVoice job contract fixture is unavailable.');
    }
    $referenceSha256 = hash('sha256', 'breezy trusted reference');
    $transcriptSha256 = hash('sha256', 'breezy confirmed transcript');
    $input = [
        'text' => '請以確認的聲音朗讀這句話。',
        'mode' => 'ultimate_clone',
        'voice_profile_id' => 41,
        'seed' => 12345,
        'seed_policy' => 'best_effort',
        'model' => 'caller-must-not-control-model',
        'model_revision' => 'main',
        'model_dir' => '/private/caller-model',
        'voice_context' => [
            'mode' => 'ultimate_clone',
            'voice_profile_id' => 41,
            'reference_audio_sha256' => $referenceSha256,
            'prompt_text_sha256' => $transcriptSha256,
            'prompt_text_confirmed_at' => '2026-09-02 11:22:33',
            'container_path' => '/data/voice_profiles/reference.wav',
        ],
    ];
    $config = hub_pack_job_runner_config_for_task($contract, $input);
    $expected = [
        'schema_version' => 'breezyvoice_runner_config_v1',
        'model' => 'MediaTek-Research/BreezyVoice',
        'model_revision' => HUB_TEST_BREEZY_MODEL_REVISION,
        'upstream_revision' => HUB_TEST_BREEZY_UPSTREAM_REVISION,
        'model_dir' => '/models/breezyvoice',
        'voice_profile_id' => 41,
        'reference_audio_sha256' => $referenceSha256,
        'transcript_sha256' => $transcriptSha256,
        'prompt_text_confirmed_at' => '2026-09-02 11:22:33',
        'prompt_transcript_confirmed' => true,
        'seed' => 12345,
        'seed_applied' => false,
        'reproducibility' => 'best_effort',
        'device' => 'cuda',
        'sample_rate' => 22050,
        'channels' => 1,
        'sample_format' => 'pcm_s16le',
        'max_input_chars' => 2000,
    ];
    $localRunnerSchemaAccepts = static function (mixed $candidate): bool {
        if (!is_array($candidate) || array_keys($candidate) !== [
            'schema_version', 'model', 'model_revision', 'upstream_revision', 'model_dir', 'voice_profile_id',
            'reference_audio_sha256', 'transcript_sha256', 'prompt_text_confirmed_at', 'prompt_transcript_confirmed',
            'seed', 'seed_applied', 'reproducibility', 'device', 'sample_rate', 'channels', 'sample_format', 'max_input_chars',
        ]) {
            return false;
        }

        return $candidate['schema_version'] === 'breezyvoice_runner_config_v1'
            && $candidate['model'] === 'MediaTek-Research/BreezyVoice'
            && preg_match('/^[a-f0-9]{40}$/', $candidate['model_revision']) === 1
            && preg_match('/^[a-f0-9]{40}$/', $candidate['upstream_revision']) === 1
            && $candidate['model_dir'] === '/models/breezyvoice'
            && is_int($candidate['voice_profile_id']) && $candidate['voice_profile_id'] > 0
            && preg_match('/^[a-f0-9]{64}$/', $candidate['reference_audio_sha256']) === 1
            && preg_match('/^[a-f0-9]{64}$/', $candidate['transcript_sha256']) === 1
            && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $candidate['prompt_text_confirmed_at']) === 1
            && $candidate['prompt_transcript_confirmed'] === true
            && is_int($candidate['seed']) && $candidate['seed'] >= 0
            && $candidate['seed_applied'] === false && $candidate['reproducibility'] === 'best_effort'
            && $candidate['device'] === 'cuda' && $candidate['sample_rate'] === 22050
            && $candidate['channels'] === 1 && $candidate['sample_format'] === 'pcm_s16le'
            && $candidate['max_input_chars'] === 2000;
    };
    $badRevision = $contract;
    $badRevision['runner_config']['aliases']['best_effort']['model_revision'] = 'main';
    $blankRevision = $contract;
    $blankRevision['runner_config']['aliases']['best_effort']['upstream_revision'] = '';

    hub_test_assert(
        $config === $expected
        && $localRunnerSchemaAccepts($config)
        && !str_contains((string)json_encode($config, JSON_THROW_ON_ERROR), '/private/')
        && hub_test_throws(static fn (): ?array => hub_pack_job_runner_config_for_task($badRevision, $input))
        && hub_test_throws(static fn (): ?array => hub_pack_job_runner_config_for_task($blankRevision, $input)),
        'BreezyVoice must emit only trusted immutable model and confirmed-profile schema fields'
    );
});

hub_test('BreezyVoice ultimate-only context accepts only the Hub-resolved transcript snapshot', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    $definition = $job['voice_context'] ?? [];
    $input = ['mode' => 'ultimate_clone', 'voice_profile_id' => 41];
    $snapshot = [
        'mode' => 'ultimate_clone',
        'voice_profile_id' => 41,
        'reference_audio_sha256' => hash('sha256', 'BreezyVoice private reference'),
        'prompt_text_sha256' => hash('sha256', 'confirmed Taiwanese Mandarin transcript'),
        'prompt_text_confirmed_at' => '2026-09-02 10:30:00',
        'container_path' => '/data/voice_profiles/reference.wav',
    ];
    $invalid = static function (array $request, mixed $candidate) use ($definition): bool {
        try {
            hub_pack_job_voice_context_snapshot($definition, $request, $candidate);
        } catch (InvalidArgumentException $error) {
            return $error->getMessage() === 'invalid_request';
        }

        return false;
    };

    $missingConfirmation = $snapshot;
    unset($missingConfirmation['prompt_text_confirmed_at']);
    $wrongProfile = $snapshot;
    $wrongProfile['voice_profile_id'] = 42;
    $malformedConfirmation = $snapshot;
    $malformedConfirmation['prompt_text_confirmed_at'] = 'not-a-timestamp';

    hub_test_assert(
        hub_pack_job_voice_context_snapshot($definition, $input, $snapshot) === $snapshot
        && $invalid(['voice_profile_id' => 41], $snapshot)
        && $invalid($input + ['voice_profile_task_id' => 'profile-task-41'], $snapshot)
        && $invalid(['mode' => 'ultimate_clone', 'voice_profile_id' => 0], $snapshot)
        && $invalid($input, $missingConfirmation)
        && $invalid($input, $wrongProfile)
        && $invalid($input, $malformedConfirmation),
        'BreezyVoice must require the exact Hub-resolved Ultimate Clone profile and confirmed transcript snapshot'
    );

    $fullUltimate = [
        'mode_input' => 'mode',
        'design_value' => 'design',
        'clone_value' => 'clone',
        'ultimate_value' => 'ultimate_clone',
        'profile_input' => 'voice_profile_id',
        'profile_task_input' => 'voice_profile_task_id',
        'design_prompt_input' => 'voice_prompt',
        'container_path' => '/data/voice_profiles/reference.wav',
    ];
    $legacy = [
        'mode_input' => 'mode',
        'design_value' => 'design',
        'clone_value' => 'clone',
        'profile_input' => 'voice_profile_id',
        'container_path' => '/data/voice_profiles/reference.wav',
    ];
    $legacyFields = ['mode', 'voice_profile_id', 'voice_profile_task_id', 'voice_prompt'];
    $legacySchema = [
        'mode' => ['type' => 'string', 'required' => true, 'enum' => ['design', 'clone', 'ultimate_clone'], 'max_length' => 32],
        'voice_profile_id' => ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 2147483647],
        'voice_profile_task_id' => ['type' => 'string', 'required' => false, 'max_length' => 64],
        'voice_prompt' => ['type' => 'string', 'required' => false, 'max_length' => 1024],
    ];
    hub_test_assert(
        hub_pack_job_voice_context_snapshot($fullUltimate, $input, $snapshot) === $snapshot
        && hub_pack_async_job_voice_context_contract($legacy, $legacyFields, $legacySchema) === null
        && hub_pack_async_job_voice_context_contract($legacy, $legacyFields, $legacySchema, true) === $legacy,
        'the strict BreezyVoice shape must not narrow full-Ultimate or explicit legacy compatibility'
    );
});

hub_test('BreezyVoice runtime Pack installs a stopped managed service', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true, 'provision_runner' => false]);
    $service = $installed['service'];
    $runtimeDir = hub_pack_runtime_dir($db, (string)$service['service_key']);

    hub_test_assert(
        ($service['pack_id'] ?? '') === 'tts-breezyvoice'
        && ($service['pack_version'] ?? '') === '0.1.1'
        && (int)($service['enabled'] ?? 1) === 0
        && ($service['status'] ?? '') === 'stopped'
        && is_file(hub_runtime_settings_path($runtimeDir))
        && is_file(hub_path((string)$service['compose_file'])),
        'BreezyVoice runtime Pack must install its stopped managed service without requiring a Docker build in tests'
    );
});

hub_test('BreezyVoice defaults to a preloaded resident runtime', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
    $contract = hub_pack_async_job_contract($manifest, 'synthesize');
    $schema = hub_get_pack_settings_schema('tts-breezyvoice');
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true, 'provision_runner' => false]);
    $service = $installed['service'];
    $settings = hub_service_settings_values($db, $service);
    $db->prepare("UPDATE services SET enabled = 1, runtime_status = 'running' WHERE id = :id")
        ->execute([':id' => (int)$service['id']]);
    $residentPlan = hub_pack_job_resident_plan_for_service($db, hub_get_service($db, (int)$service['id']) ?: [], $contract ?? []);

    hub_test_assert(
        ($contract['resident'] ?? null) === [
            'protocol' => 'service_data_v1',
            'mode_setting' => 'BREEZYVOICE_EXECUTION_MODE',
            'mode_value' => 'resident',
            'min_free_vram_setting' => 'BREEZYVOICE_RESIDENT_MIN_FREE_VRAM_MB',
        ]
        && ($schema['BREEZYVOICE_EXECUTION_MODE'] ?? null) === [
            'key' => 'BREEZYVOICE_EXECUTION_MODE',
            'label' => '執行模式',
            'type' => 'select',
            'default' => 'resident',
            'options' => ['resident', 'isolated'],
            'option_labels' => ['resident' => '常駐模型（啟動時預載）', 'isolated' => '一次性容器'],
            'required' => true,
            'restart_required' => true,
            'install_option' => true,
            'secret' => false,
        ]
        && ($settings['BREEZYVOICE_EXECUTION_MODE'] ?? null) === 'resident'
        && is_string($settings['BREEZYVOICE_INTERNAL_JOB_TOKEN'] ?? null)
        && strlen((string)$settings['BREEZYVOICE_INTERNAL_JOB_TOKEN']) === 64
        && is_array($residentPlan) && !empty($residentPlan['eligible']),
        'BreezyVoice must default to its authenticated preloaded resident runtime'
    );
});

hub_test('BreezyVoice profile API accepts a confirmed WAV, queues Ultimate Clone, and deletes the temporary profile', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true, 'provision_runner' => false]);
        hub_set_service_enabled($db, 'voice_generate_breezy', true);
        $memberId = hub_create_api_member($db, 'Breezy Profile API Owner');
        $token = hub_create_api_token($db, $memberId, 'Breezy profile API token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate_breezy']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $referenceWav = tempnam(sys_get_temp_dir(), 'breezy-profile-');
        if ($referenceWav === false) {
            throw new RuntimeException('Cannot create BreezyVoice Profile WAV fixture.');
        }
        file_put_contents($referenceWav, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=breezy-profile';
            $prepared = hub_test_audio_request($db, 'voice_generate_breezy', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'One-time Breezy reference',
                'consent_type' => 'self_recorded',
                'prompt_text' => '這是已確認的台灣國語參考逐字稿。',
                'transcript_confirmed' => 'true',
                'language' => 'zh-TW',
                'expires_in_seconds' => '300',
            ], [], ['reference_wav' => [
                'name' => 'reference.wav',
                'type' => 'audio/wav',
                'tmp_name' => $referenceWav,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($referenceWav),
            ]]);
            $preparedPayload = hub_test_audio_payload($prepared);
            $profileTaskId = (int)($preparedPayload['task_id'] ?? 0);
            hub_test_assert($prepared['status'] === 200 && $profileTaskId > 0, 'BreezyVoice profile_prepare must enqueue the one-time managed reference WAV');

            $profileTask = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $profileTask ?? []);

            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
            $synthesis = hub_test_audio_request($db, 'voice_generate_breezy', (string)$token['plain_token'], [
                'text' => '請以這段已確認的聲音朗讀這句話。',
                'mode' => 'ultimate_clone',
                'voice_profile_task_id' => (string)$profileTaskId,
            ]);
            $synthesisPayload = hub_test_audio_payload($synthesis);
            $synthesisTask = hub_get_task($db, (int)($synthesisPayload['task_id'] ?? 0));
            hub_test_assert(
                $synthesis['status'] === 200
                && ($synthesisTask['requested_mode'] ?? '') === 'voice_generate_breezy'
                && ($synthesisTask['pack_id'] ?? '') === 'tts-breezyvoice'
                && ($synthesisTask['input']['mode'] ?? '') === 'ultimate_clone',
                'BreezyVoice must queue the managed profile as an Ultimate Clone Pack job'
            );
            $invalidPronunciation = hub_test_audio_request($db, 'voice_generate_breezy', (string)$token['plain_token'], [
                'text' => 'AI 協助檢查。',
                'mode' => 'ultimate_clone',
                'voice_profile_task_id' => (string)$profileTaskId,
                'pronunciation' => [
                    'request_overrides' => [[
                        'id' => 'invalid:marker',
                        'match' => 'AI',
                        'kind' => 'spoken_form',
                        'value' => '[:ㄞ1]',
                    ]],
                ],
            ]);
            $invalidPronunciationPayload = hub_test_audio_payload($invalidPronunciation);
            hub_test_assert(
                $invalidPronunciation['status'] === 400
                && ($invalidPronunciationPayload['error'] ?? '') === 'invalid_pronunciation_rules',
                'BreezyVoice must reject invalid pronunciation input before task submission'
            );
            hub_finish_task_success($db, $synthesisTask ?? [], []);

            $deleted = hub_test_audio_request($db, 'voice_generate_breezy', (string)$token['plain_token'], [
                'operation' => 'profile_delete',
                'voice_profile_task_id' => (string)$profileTaskId,
            ]);
            $deletedPayload = hub_test_audio_payload($deleted);
            hub_test_assert(
                $deleted['status'] === 200 && ($deletedPayload['profile_status'] ?? '') === 'deleted',
                'BreezyVoice must allow deletion of the completed one-time Profile'
            );
        } finally {
            if (is_file($referenceWav)) {
                unlink($referenceWav);
            }
        }
    });
});
