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
            'seed', 'seed_applied', 'reproducibility', 'device', 'final_format',
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
        && ($job['runner']['entrypoint'] ?? null) === ['/app/breezyvoice-synthesize']
        && ($job['runner']['args'] ?? null) === ['--workspace', '{workspace}', '--input', '{input_dir}', '--output', '{output_dir}', '--runner-config', '{input_dir}/runner_config.json']
        && ($job['runner']['accelerator'] ?? '') === 'gpu'
        && ($job['runner']['required_vram_mb'] ?? 0) === 4096,
        'BreezyVoice must pin its Pascal isolated-GPU runner without a shell'
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
