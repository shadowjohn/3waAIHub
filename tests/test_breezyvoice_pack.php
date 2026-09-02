<?php
declare(strict_types=1);

const HUB_TEST_BREEZY_MODEL_REVISION = 'e33b502e0ac21c16b0ee0d00df66ac3fa737393d';
const HUB_TEST_BREEZY_UPSTREAM_REVISION = 'd592c9d3e8927a0f53f68616387060dcd32a05ea';
const HUB_TEST_BREEZY_IMAGE = '3waaihub/tts-breezyvoice:0.1.1-cu128';

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
        array_keys($targets) === ['linux-docker']
        && ($targets['linux-docker']['supported'] ?? null) === true,
        'BreezyVoice must declare only its validated Linux Docker target'
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
            'min_compute_capability' => '12.0',
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
        && ($job['input_fields'] ?? null) === ['text', 'mode', 'voice_profile_id', 'voice_profile_task_id', 'seed', 'seed_policy']
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
        !isset($manifest['wsl_runtime_profiles'])
        && ($manifest['runner_build'] ?? null) === [
            'context' => '.',
            'dockerfile' => 'service/Dockerfile',
            'image' => HUB_TEST_BREEZY_IMAGE,
        ]
        && ($job['runner']['image'] ?? '') === HUB_TEST_BREEZY_IMAGE
        && ($job['runner']['entrypoint'] ?? null) === ['/app/voice_generate.sh']
        && ($job['runner']['args'] ?? null) === ['{workspace}', '{input_dir}', '{output_dir}', '{input_dir}/runner_config.json']
        && ($job['runner']['accelerator'] ?? '') === 'gpu'
        && ($job['runner']['required_vram_mb'] ?? 0) === 4096,
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
        $settings === "BREEZYVOICE_MODEL_ID=MediaTek-Research/BreezyVoice\n"
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
