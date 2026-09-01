<?php
declare(strict_types=1);

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
        && ($manifest['version'] ?? '') === '0.1.0'
        && ($manifest['category'] ?? '') === 'audio'
        && ($manifest['type'] ?? '') === 'api_service'
        && ($manifest['execution_type'] ?? '') === 'async_task'
        && ($manifest['runtime_level'] ?? '') === 'L2-deps-import'
        && ($manifest['target_level'] ?? '') === 'L5-benchmark-ready'
        && ($manifest['runtime_ready'] ?? true) === false
        && ($manifest['default_mode'] ?? '') === 'voice_generate_breezy'
        && ($manifest['capability'] ?? '') === 'taiwan_mandarin_voice_clone',
        'BreezyVoice must publish the strict non-ready B1 identity'
    );

    hub_test_assert(
        array_keys($targets) === ['linux-docker', 'windows-wsl2-linux-docker']
        && ($targets['linux-docker']['supported'] ?? null) === true
        && ($targets['windows-wsl2-linux-docker']['supported'] ?? null) === true,
        'BreezyVoice must declare only Linux Docker and Windows WSL2 Linux Docker targets'
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
        'BreezyVoice must reserve one exclusive Pascal-compatible GPU job'
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
        ($manifest['wsl_runtime_profiles']['pascal-cu118'] ?? null) === [
            'id' => 'pascal-cu118',
            'dockerfile' => 'service/Dockerfile.pascal-cu118',
            'image' => '3waaihub/tts-breezyvoice:0.1.0-pascal-cu118',
            'min_compute_capability' => '6.1',
            'gpu_name_patterns' => ['GTX 1050', 'GTX 1060', 'GTX 1070', 'GTX 1080', 'GTX 1080 Ti'],
        ]
        && ($job['runner']['image'] ?? '') === '3waaihub/tts-breezyvoice:0.1.0-pascal-cu118'
        && ($job['runner']['entrypoint'] ?? null) === ['/app/voice_generate.sh']
        && ($job['runner']['args'] ?? null) === ['{workspace}', '{input_dir}', '{output_dir}', '{input_dir}/runner_config.json']
        && ($job['runner']['accelerator'] ?? '') === 'gpu'
        && ($job['runner']['required_vram_mb'] ?? 0) === 4096,
        'BreezyVoice must pin its Pascal isolated-GPU runner without a shell'
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
            'marker_json' => [
                'path' => 'model-manifest.json',
                'required_strings' => [
                    'model' => 'MediaTek-Research/BreezyVoice',
                    'model_revision' => str_repeat('a', 40),
                    'upstream_revision' => str_repeat('b', 40),
                ],
                'exact_keys' => ['model', 'model_revision', 'upstream_revision'],
            ],
        ]]
        && ($job['runner_config']['materializer'] ?? null) === 'breezyvoice_ultimate_v1'
        && is_array($breezyTrustedModel)
        && ($breezyTrustedModel['model_dir'] ?? null) === '/models/breezyvoice'
        && preg_match('/^[a-f0-9]{40}$/', (string)($breezyTrustedModel['model_revision'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{40}$/', (string)($breezyTrustedModel['upstream_revision'] ?? '')) === 1
        && !in_array((string)($breezyTrustedModel['model_revision'] ?? ''), ['', 'main'], true)
        && !in_array((string)($breezyTrustedModel['upstream_revision'] ?? ''), ['', 'main'], true),
        'BreezyVoice runner must mount only the immutable offline model manifest read-only'
    );

    $compose = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/docker-compose.yml');
    $settings = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/runtime-settings.example.conf');
    hub_test_assert(
        str_contains($compose, 'image: 3waaihub/tts-breezyvoice:0.1.0-pascal-cu118')
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
            . "BREEZYVOICE_MODEL_REVISION=main\n"
            . "BREEZYVOICE_UPSTREAM_REPOSITORY=https://github.com/mtkresearch/BreezyVoice.git\n"
            . "BREEZYVOICE_UPSTREAM_REVISION=\n"
            . "BREEZYVOICE_REAL_INFERENCE=0\n"
            . "BREEZYVOICE_DEVICE=cuda\n"
            . "BREEZYVOICE_SAMPLE_RATE=24000\n"
            . "BREEZYVOICE_MAX_INPUT_CHARS=2000\n"
            . "GPU_VISIBLE_DEVICES=all\n",
        'BreezyVoice example settings must keep the unpinned runtime non-ready'
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
        'model_revision' => str_repeat('a', 40),
        'upstream_revision' => str_repeat('b', 40),
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
        'sample_rate' => 24000,
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
            && $candidate['device'] === 'cuda' && $candidate['sample_rate'] === 24000
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

hub_test('BreezyVoice metadata-only services refuse lifecycle actions before runtime files or Docker commands', function (): void {
    $db = hub_test_reset_db();
    $readyService = hub_get_service_by_mode($db, 'hello');
    $service = hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true])['service'];
    $runtimeDir = hub_pack_runtime_dir($db, (string)$service['service_key']);
    $composePath = hub_path((string)$service['compose_file']);
    $settingsPath = hub_runtime_settings_path($runtimeDir);
    $composeMarker = "# BreezyVoice metadata-only lifecycle marker\n";
    $settingsMarker = "BREEZYVOICE_LIFECYCLE_MARKER=1\n";
    file_put_contents($composePath, $composeMarker, LOCK_EX);
    file_put_contents($settingsPath, $settingsMarker, LOCK_EX);

    $fixtureDir = sys_get_temp_dir() . '/breezyvoice-runtime-guard-' . bin2hex(random_bytes(8));
    $dockerBin = $fixtureDir . '/docker';
    $dockerLog = $fixtureDir . '/docker.log';
    if (!mkdir($fixtureDir, 0700, true)) {
        throw new RuntimeException('Cannot create BreezyVoice lifecycle command fixture.');
    }
    file_put_contents($dockerBin, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> \"\$MOCK_DOCKER_LOG\"\nexit 0\n", LOCK_EX);
    chmod($dockerBin, 0755);
    $previousDockerBin = getenv('AIHUB_TEST_DOCKER_BIN');
    $previousDockerLog = getenv('MOCK_DOCKER_LOG');

    try {
        putenv('AIHUB_TEST_DOCKER_BIN=' . $dockerBin);
        putenv('MOCK_DOCKER_LOG=' . $dockerLog);
        $build = hub_build_service($db, $service);
        $start = hub_start_service_with_job($db, $service, null);
        $restart = hub_restart_service($db, $service);
        $after = hub_get_service($db, (int)$service['id']) ?: [];

        hub_test_assert(
            (int)($build['exit_code'] ?? 0) !== 0
            && (int)($start['exit_code'] ?? 0) !== 0
            && (int)($restart['exit_code'] ?? 0) !== 0
            && ($build['error_code'] ?? '') === 'pack_runtime_not_ready'
            && ($start['error_code'] ?? '') === 'pack_runtime_not_ready'
            && ($restart['error_code'] ?? '') === 'pack_runtime_not_ready'
            && ($build['output'] ?? '') === ($start['output'] ?? '')
            && ($start['output'] ?? '') === ($restart['output'] ?? '')
            && (string)file_get_contents($composePath) === $composeMarker
            && (string)file_get_contents($settingsPath) === $settingsMarker
            && (int)($after['enabled'] ?? 1) === 0
            && ($after['status'] ?? '') === 'stopped'
            && $readyService !== null
            && hub_service_pack_runtime_not_ready_result($readyService) === null
            && !is_file($dockerLog),
            'metadata-only BreezyVoice lifecycle actions must stop before runtime refresh, enablement, restart, or Docker commands without changing ready Packs'
        );
    } finally {
        putenv($previousDockerBin === false ? 'AIHUB_TEST_DOCKER_BIN' : 'AIHUB_TEST_DOCKER_BIN=' . $previousDockerBin);
        putenv($previousDockerLog === false ? 'MOCK_DOCKER_LOG' : 'MOCK_DOCKER_LOG=' . $previousDockerLog);
        @unlink($dockerBin);
        @unlink($dockerLog);
        @rmdir($fixtureDir);
    }
});
