<?php
declare(strict_types=1);

function hub_test_whisper_async_runner(): string
{
    return HUB_ROOT . '/packs/whisper-asr/service/job.py';
}

hub_test('Whisper ASR declares the fixed GPU transcription Pack job', function (): void {
    $pack = hub_get_pack('whisper-asr');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'Whisper ASR Pack must validate');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'transcribe');
    hub_test_assert(is_array($job), 'Whisper ASR transcribe job contract missing');
    hub_test_assert(($job['input_fields'] ?? []) === ['model', 'language', 'word_timestamps', 'diarization', 'min_speakers', 'max_speakers', 'output_srt', 'output_vtt', 'subtitle_reflow'], 'Whisper input field allowlist mismatch');
    hub_test_assert(($job['request_schema']['subtitle_reflow'] ?? null) === [
        'type' => 'string',
        'required' => false,
        'enum' => ['none', 'legacy_adaptive_v1'],
        'default' => 'none',
        'max_length' => 32,
    ], 'Whisper subtitle reflow must use the fixed compatibility-mode contract');
    hub_test_assert(($job['request_schema']['word_timestamps']['requires_when'] ?? null) === ['equals' => true, 'field' => 'language', 'not_equals' => 'auto'], 'Word timestamps must require an explicit alignment language at admission');
    hub_test_assert(($job['source_artifact_types'] ?? []) === ['audio', 'cleaned_audio', 'vocals_audio'], 'Whisper must accept managed audio sources only');
    hub_test_assert(($job['runner']['accelerator'] ?? '') === 'gpu' && ($job['runner']['required_vram_mb'] ?? 0) === 10000 && ($job['runner']['executor'] ?? '') === 'container', 'Whisper transcription must use the generic GPU container runner');
    hub_test_assert(($job['runner']['asset_mounts'] ?? []) === [
        [
            'id' => 'whisper_asr_large_v3',
            'storage' => 'models',
            'host_subdir' => 'whisper/asr/large-v3',
            'container_path' => '/models/whisper/asr/large-v3',
            'required_paths' => ['config.json', 'model.bin', 'tokenizer.json'],
        ],
        [
            'id' => 'whisper_alignment_torch',
            'storage' => 'cache',
            'host_subdir' => 'whisper/torch',
            'container_path' => '/cache/whisper/torch',
            'required_paths' => ['.aihub-alignment-ready.json', 'wav2vec2_fairseq_base_ls960_asr_ls960.pth'],
            'when' => ['input' => 'word_timestamps', 'equals' => true],
            'marker_json' => [
                'path' => '.aihub-alignment-ready.json',
                'required_strings' => [
                    'schema' => 'aihub-whisper-alignment/v1',
                    'language' => 'en',
                    'model_name' => 'WAV2VEC2_ASR_BASE_960H',
                    'model_dir' => '/cache/whisper/torch',
                    'weight_path' => '/cache/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth',
                ],
                'exact_keys' => ['schema', 'language', 'model_name', 'model_dir', 'weight_path', 'alignment_languages'],
                'string_lists' => ['alignment_languages' => ['en']],
                'input_membership' => ['input' => 'language', 'list_field' => 'alignment_languages'],
            ],
        ],
        [
            'id' => 'whisper_ckip_bert_base',
            'storage' => 'cache',
            'host_subdir' => 'whisper/ckip/bert-base-chinese-ws',
            'container_path' => '/cache/whisper/ckip/bert-base-chinese-ws',
            'required_paths' => ['.aihub-ckip-ready.json', 'config.json', 'pytorch_model.bin', 'vocab.txt'],
            'when' => ['input' => 'subtitle_reflow', 'equals' => 'legacy_adaptive_v1'],
            'marker_json' => [
                'path' => '.aihub-ckip-ready.json',
                'required_strings' => [
                    'schema' => 'aihub-whisper-ckip/v1',
                    'model_name' => 'ckiplab/bert-base-chinese-ws',
                    'model_dir' => '/cache/whisper/ckip/bert-base-chinese-ws',
                ],
                'exact_keys' => ['schema', 'model_name', 'model_dir'],
            ],
        ],
        [
            'id' => 'whisper_pyannote_diarization',
            'storage' => 'cache',
            'host_subdir' => 'whisper/pyannote/speaker-diarization-3.1',
            'container_path' => '/cache/whisper/pyannote/speaker-diarization-3.1',
            'required_paths' => ['.aihub-pyannote-ready.json', 'config.yaml', 'models/pyannote_segmentation-3.0.bin', 'models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin'],
            'when' => ['input' => 'diarization', 'equals' => true],
            'marker_json' => [
                'path' => '.aihub-pyannote-ready.json',
                'required_strings' => [
                    'schema' => 'aihub-whisper-pyannote/v1',
                    'config_path' => '/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml',
                    'segmentation_path' => '/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_segmentation-3.0.bin',
                    'embedding_path' => '/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin',
                ],
                'exact_keys' => ['schema', 'config_path', 'segmentation_path', 'embedding_path'],
            ],
        ],
    ], 'Whisper must declare only controlled Hub model/cache mount descriptors');
    hub_test_assert(!array_key_exists('secret_env', $job['runner'] ?? []), 'Whisper task runtime must not receive a pyannote credential');
    hub_test_assert(($job['runner_config']['alias_input'] ?? '') === 'model' && array_keys($job['runner_config']['aliases'] ?? []) === ['large_v3'], 'Whisper model must be a fixed manifest allowlist');
    hub_test_assert(array_column($job['artifact_contract']['artifacts'] ?? [], 'type') === ['transcript_json', 'transcription_report', 'subtitle_srt', 'subtitle_vtt', 'speaker_timeline'], 'Whisper artifact contract mismatch');
    hub_test_assert(is_file(HUB_ROOT . '/packs/whisper-asr/jobs/speech_transcribe.sh')
        && is_file(HUB_ROOT . '/packs/whisper-asr/jobs/provision_offline_models.sh')
        && is_file(HUB_ROOT . '/packs/whisper-asr/service/provision_offline_assets.py')
        && is_file(hub_test_whisper_async_runner()), 'Whisper Pack runner assets missing');
});

hub_test('Whisper ASR resolves only ready Hub-owned asset descriptors as read-only Docker mounts', function (): void {
    $db = hub_test_reset_db();
    $models = sys_get_temp_dir() . '/3waaihub_whisper_assets_models_' . bin2hex(random_bytes(4));
    $cache = sys_get_temp_dir() . '/3waaihub_whisper_assets_cache_' . bin2hex(random_bytes(4));
    mkdir($models, 0775, true);
    mkdir($cache, 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $models);
    hub_set_storage_setting($db, 'AIHUB_CACHE_DIR', $cache);
    $runner = hub_pack_async_job_contract(hub_get_pack('whisper-asr')['manifest'], 'transcribe')['runner'];

    mkdir($models . '/whisper/asr/large-v3', 0775, true);
    foreach (['config.json', 'model.bin', 'tokenizer.json'] as $path) {
        file_put_contents($models . '/whisper/asr/large-v3/' . $path, '{}', LOCK_EX);
    }
    $asrAssets = [
        ['id' => 'whisper_asr_large_v3', 'source' => $models . '/whisper/asr/large-v3', 'container_path' => '/models/whisper/asr/large-v3'],
    ];
    hub_test_assert(hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'auto', 'word_timestamps' => false, 'diarization' => false, 'subtitle_reflow' => 'none']) === $asrAssets, 'basic ASR without subtitle reflow must preflight only the fixed CTranslate2 model');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'zh', 'word_timestamps' => false, 'diarization' => false, 'subtitle_reflow' => 'legacy_adaptive_v1'])), 'legacy subtitle reflow must preflight its missing CKIP assets before GPU work');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'auto', 'word_timestamps' => true, 'diarization' => false])), 'word timestamps must preflight the missing alignment cache before GPU work');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'auto', 'word_timestamps' => false, 'diarization' => true])), 'diarization must preflight its missing local model before GPU work');

    mkdir($cache . '/whisper/torch', 0775, true);
    file_put_contents($cache . '/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth', 'weights', LOCK_EX);
    file_put_contents($cache . '/whisper/torch/.aihub-alignment-ready.json', '{"schema":"aihub-whisper-alignment/v1","language":"en","model_name":"WAV2VEC2_ASR_BASE_960H","model_dir":"/cache/whisper/torch","weight_path":"/cache/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth","alignment_languages":["en"]}', LOCK_EX);
    $alignmentAssets = [
        ...$asrAssets,
        ['id' => 'whisper_alignment_torch', 'source' => $cache . '/whisper/torch', 'container_path' => '/cache/whisper/torch'],
    ];
    hub_test_assert(hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'en', 'word_timestamps' => true, 'diarization' => false]) === $alignmentAssets, 'word timestamps must mount only its exact local alignment cache');

    mkdir($cache . '/whisper/ckip/bert-base-chinese-ws', 0775, true);
    foreach (['config.json', 'pytorch_model.bin', 'vocab.txt'] as $path) {
        file_put_contents($cache . '/whisper/ckip/bert-base-chinese-ws/' . $path, 'model', LOCK_EX);
    }
    file_put_contents($cache . '/whisper/ckip/bert-base-chinese-ws/.aihub-ckip-ready.json', '{"schema":"aihub-whisper-ckip/v1","model_name":"ckiplab/bert-base-chinese-ws","model_dir":"/cache/whisper/ckip/bert-base-chinese-ws"}', LOCK_EX);
    $ckipAssets = [
        ...$asrAssets,
        ['id' => 'whisper_ckip_bert_base', 'source' => $cache . '/whisper/ckip/bert-base-chinese-ws', 'container_path' => '/cache/whisper/ckip/bert-base-chinese-ws'],
    ];
    hub_test_assert(hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'zh', 'word_timestamps' => false, 'diarization' => false, 'subtitle_reflow' => 'legacy_adaptive_v1']) === $ckipAssets, 'legacy subtitle reflow must mount only its exact ready CKIP model');
    hub_test_assert(hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'en', 'word_timestamps' => true, 'diarization' => false, 'subtitle_reflow' => 'legacy_adaptive_v1']) === [...$alignmentAssets, $ckipAssets[1]], 'legacy subtitle reflow with explicit words must mount CKIP and WhisperX alignment together');

    mkdir($cache . '/whisper/pyannote/speaker-diarization-3.1/models', 0775, true);
    foreach (['config.yaml', 'models/pyannote_segmentation-3.0.bin', 'models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin'] as $path) {
        file_put_contents($cache . '/whisper/pyannote/speaker-diarization-3.1/' . $path, 'model', LOCK_EX);
    }
    file_put_contents($cache . '/whisper/pyannote/speaker-diarization-3.1/.aihub-pyannote-ready.json', '{"schema":"aihub-whisper-pyannote/v1","config_path":"/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml","segmentation_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_segmentation-3.0.bin","embedding_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"}', LOCK_EX);
    $diarizationAssets = [
        ...$asrAssets,
        ['id' => 'whisper_pyannote_diarization', 'source' => $cache . '/whisper/pyannote/speaker-diarization-3.1', 'container_path' => '/cache/whisper/pyannote/speaker-diarization-3.1'],
    ];
    hub_test_assert(hub_pack_job_resolve_asset_mounts($db, $runner, ['language' => 'auto', 'word_timestamps' => false, 'diarization' => true]) === $diarizationAssets, 'diarization must mount only its fixed local pyannote model');

    $workspace = sys_get_temp_dir() . '/3waaihub_whisper_mount_workspace_' . bin2hex(random_bytes(4));
    mkdir($workspace . '/input', 0775, true);
    mkdir($workspace . '/output', 0775, true);
    file_put_contents($workspace . '/input/source', 'audio', LOCK_EX);
    file_put_contents($workspace . '/input/request.json', '{}', LOCK_EX);
    file_put_contents($workspace . '/input/runner_config.json', '{}', LOCK_EX);
    $previousToken = getenv('AIHUB_SECRET_PYANNOTE_TOKEN');
    putenv('AIHUB_SECRET_PYANNOTE_TOKEN=test-env-only-token');
    try {
        $allAssets = [...$alignmentAssets, $ckipAssets[1], $diarizationAssets[1]];
        foreach ([
            'basic' => [$asrAssets, [$asrAssets[0]['source']]],
            'alignment' => [$alignmentAssets, [$asrAssets[0]['source'], $alignmentAssets[1]['source']]],
            'legacy_reflow' => [$ckipAssets, [$asrAssets[0]['source'], $ckipAssets[1]['source']]],
            'diarization' => [$diarizationAssets, [$asrAssets[0]['source'], $diarizationAssets[1]['source']]],
            'both' => [$allAssets, [$asrAssets[0]['source'], $alignmentAssets[1]['source'], $ckipAssets[1]['source'], $diarizationAssets[1]['source']]],
        ] as [$assets, $expectedSources]) {
            $command = hub_pack_job_default_runner_command([
                'workspace' => $workspace,
                'run' => ['run_id' => 'whisper-assets-test'],
                'runner' => array_replace($runner, ['asset_mounts' => $assets]),
            ])['command'];
            $mounts = [];
            foreach ($command as $index => $argument) {
                if ($argument === '--mount') {
                    $mounts[] = (string)($command[$index + 1] ?? '');
                }
            }
            hub_test_assert(in_array('--network', $command, true) && ($command[array_search('--network', $command, true) + 1] ?? null) === 'none', 'production job containers must remain network-isolated');
            foreach ($allAssets as $asset) {
                $mount = 'type=bind,src=' . $asset['source'] . ',dst=' . $asset['container_path'] . ',readonly';
                hub_test_assert(in_array($asset['source'], $expectedSources, true) === in_array($mount, $mounts, true), 'asset mount activation must exactly follow normalized transcription controls');
            }
            hub_test_assert(!in_array('AIHUB_SECRET_PYANNOTE_TOKEN', $command, true)
                && !str_contains(implode("\n", $command), 'test-env-only-token') && !str_contains(implode("\n", $command), 'AIHUB_SECRET_PYANNOTE_TOKEN='), 'Whisper task Docker command must not receive the pyannote token name or value');
        }
    } finally {
        putenv($previousToken === false ? 'AIHUB_SECRET_PYANNOTE_TOKEN' : 'AIHUB_SECRET_PYANNOTE_TOKEN=' . $previousToken);
        hub_test_audio_cleanup_remove($workspace);
        hub_test_audio_cleanup_remove($models);
        hub_test_audio_cleanup_remove($cache);
    }
});

hub_test('Whisper ASR rejects malformed or stale optional asset markers before GPU work', function (): void {
    $db = hub_test_reset_db();
    $models = sys_get_temp_dir() . '/3waaihub_whisper_marker_models_' . bin2hex(random_bytes(4));
    $cache = sys_get_temp_dir() . '/3waaihub_whisper_marker_cache_' . bin2hex(random_bytes(4));
    mkdir($models . '/whisper/asr/large-v3', 0775, true);
    mkdir($cache . '/whisper/torch', 0775, true);
    mkdir($cache . '/whisper/pyannote/speaker-diarization-3.1/models', 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $models);
    hub_set_storage_setting($db, 'AIHUB_CACHE_DIR', $cache);
    foreach (['config.json', 'model.bin', 'tokenizer.json'] as $path) {
        file_put_contents($models . '/whisper/asr/large-v3/' . $path, 'model', LOCK_EX);
    }
    file_put_contents($cache . '/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth', 'weights', LOCK_EX);
    foreach (['config.yaml', 'models/pyannote_segmentation-3.0.bin', 'models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin'] as $path) {
        file_put_contents($cache . '/whisper/pyannote/speaker-diarization-3.1/' . $path, 'model', LOCK_EX);
    }
    $alignmentMarker = $cache . '/whisper/torch/.aihub-alignment-ready.json';
    $pyannoteMarker = $cache . '/whisper/pyannote/speaker-diarization-3.1/.aihub-pyannote-ready.json';
    $validAlignment = '{"schema":"aihub-whisper-alignment/v1","language":"en","model_name":"WAV2VEC2_ASR_BASE_960H","model_dir":"/cache/whisper/torch","weight_path":"/cache/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth","alignment_languages":["en"]}';
    $validPyannote = '{"schema":"aihub-whisper-pyannote/v1","config_path":"/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml","segmentation_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_segmentation-3.0.bin","embedding_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"}';
    file_put_contents($alignmentMarker, $validAlignment, LOCK_EX);
    file_put_contents($pyannoteMarker, $validPyannote, LOCK_EX);
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Whisper Marker Preflight Owner');
    $token = hub_create_api_token($db, $member, 'whisper marker preflight token', null, null);
    $taskIds = [];
    $assertPreflight = static function (array $input) use ($db, $member, $token, &$taskIds): void {
        $taskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), $input, $member, (int)$token['token_id'], '203.0.113.62');
        $taskIds[] = $taskId;
        $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $executorCalls = 0;
        $gpuProbeCalls = 0;
        $result = hub_run_pack_job_task($db, $task ?? [], [
            'worker_id' => 'whisper-marker-test-worker',
            'gpu_probe' => static function () use (&$gpuProbeCalls): array {
                $gpuProbeCalls++;
                return ['free_vram_mb' => 65536, 'processes' => []];
            },
            'executor' => static function () use (&$executorCalls): array {
                $executorCalls++;
                return [];
            },
        ]);
        hub_test_assert(($result['error_code'] ?? '') === 'model_assets_unavailable' && $executorCalls === 0 && $gpuProbeCalls === 0, 'invalid optional marker contents must fail before GPU work or inference');
    };
    try {
        file_put_contents($alignmentMarker, '{', LOCK_EX);
        $assertPreflight(['word_timestamps' => true, 'language' => 'en']);
        file_put_contents($alignmentMarker, str_replace('aihub-whisper-alignment/v1', 'stale-alignment', $validAlignment), LOCK_EX);
        $assertPreflight(['word_timestamps' => true, 'language' => 'en']);
        file_put_contents($alignmentMarker, $validAlignment, LOCK_EX);
        file_put_contents($pyannoteMarker, str_replace('aihub-whisper-pyannote/v1', 'stale-pyannote', $validPyannote), LOCK_EX);
        $assertPreflight(['diarization' => true]);
        file_put_contents($pyannoteMarker, $validPyannote, LOCK_EX);
        file_put_contents($alignmentMarker, substr($validAlignment, 0, -1) . ',"obsolete_marker_key":"stale"}', LOCK_EX);
        $assertPreflight(['word_timestamps' => true, 'language' => 'en']);
    } finally {
        foreach ($taskIds as $taskId) {
            hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        }
        hub_test_audio_cleanup_remove($models);
        hub_test_audio_cleanup_remove($cache);
    }
});

hub_test('Whisper ASR rejects unsupported or simulated auto alignment before GPU work', function (): void {
    $db = hub_test_reset_db();
    $models = sys_get_temp_dir() . '/3waaihub_whisper_language_models_' . bin2hex(random_bytes(4));
    $cache = sys_get_temp_dir() . '/3waaihub_whisper_language_cache_' . bin2hex(random_bytes(4));
    mkdir($models . '/whisper/asr/large-v3', 0775, true);
    mkdir($cache . '/whisper/torch', 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $models);
    hub_set_storage_setting($db, 'AIHUB_CACHE_DIR', $cache);
    foreach (['config.json', 'model.bin', 'tokenizer.json'] as $path) {
        file_put_contents($models . '/whisper/asr/large-v3/' . $path, 'model', LOCK_EX);
    }
    file_put_contents($cache . '/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth', 'weights', LOCK_EX);
    file_put_contents($cache . '/whisper/torch/.aihub-alignment-ready.json', '{"schema":"aihub-whisper-alignment/v1","language":"en","model_name":"WAV2VEC2_ASR_BASE_960H","model_dir":"/cache/whisper/torch","weight_path":"/cache/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth","alignment_languages":["en"]}', LOCK_EX);
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Whisper Language Preflight Owner');
    $token = hub_create_api_token($db, $member, 'whisper language preflight token', null, null);
    $taskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), ['word_timestamps' => true, 'language' => 'zh'], $member, (int)$token['token_id'], '203.0.113.63');
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    $autoTaskId = 0;
    $executorCalls = 0;
    $gpuProbeCalls = 0;
    try {
        $result = hub_run_pack_job_task($db, $task ?? [], [
            'worker_id' => 'whisper-language-test-worker',
            'gpu_probe' => static function () use (&$gpuProbeCalls): array {
                $gpuProbeCalls++;
                return ['free_vram_mb' => 65536, 'processes' => []];
            },
            'executor' => static function () use (&$executorCalls): array {
                $executorCalls++;
                return [];
            },
        ]);
        hub_test_assert(($result['error_code'] ?? '') === 'model_assets_unavailable' && $executorCalls === 0 && $gpuProbeCalls === 0, 'an explicit unsupported alignment language must fail before GPU work or inference');
        $autoTaskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), [], $member, (int)$token['token_id'], '203.0.113.64');
        $autoTask = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $autoTask['input']['word_timestamps'] = true;
        $executorCalls = 0;
        $gpuProbeCalls = 0;
        $autoResult = hub_run_pack_job_task($db, $autoTask ?? [], [
            'worker_id' => 'whisper-auto-language-test-worker',
            'gpu_probe' => static function () use (&$gpuProbeCalls): array {
                $gpuProbeCalls++;
                return ['free_vram_mb' => 65536, 'processes' => []];
            },
            'executor' => static function () use (&$executorCalls): array {
                $executorCalls++;
                return [];
            },
        ]);
        hub_test_assert(($autoResult['error_code'] ?? '') === 'model_assets_unavailable' && $executorCalls === 0 && $gpuProbeCalls === 0, 'a simulated auto-detected timestamp task must fail before GPU work or inference');
    } finally {
        hub_test_audio_cleanup_remove($models);
        hub_test_audio_cleanup_remove($cache);
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        if ($autoTaskId > 0) {
            hub_test_audio_cleanup_remove(hub_task_result_dir($autoTaskId));
        }
    }
});

hub_test('Whisper ASR fails missing requested diarization assets before GPU reservation or executor invocation', function (): void {
    $db = hub_test_reset_db();
    $models = sys_get_temp_dir() . '/3waaihub_whisper_missing_models_' . bin2hex(random_bytes(4));
    $cache = sys_get_temp_dir() . '/3waaihub_whisper_missing_cache_' . bin2hex(random_bytes(4));
    mkdir($models, 0775, true);
    mkdir($cache, 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $models);
    hub_set_storage_setting($db, 'AIHUB_CACHE_DIR', $cache);
    mkdir($models . '/whisper/asr/large-v3', 0775, true);
    foreach (['config.json', 'model.bin', 'tokenizer.json'] as $path) {
        file_put_contents($models . '/whisper/asr/large-v3/' . $path, 'model', LOCK_EX);
    }
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Whisper Asset Preflight Owner');
    $token = hub_create_api_token($db, $member, 'whisper asset preflight token', null, null);
    $taskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), ['diarization' => true], $member, (int)$token['token_id'], '203.0.113.61');
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    $executorCalls = 0;
    $gpuProbeCalls = 0;
    try {
        $result = hub_run_pack_job_task($db, $task ?? [], [
            'worker_id' => 'whisper-assets-test-worker',
            'gpu_probe' => static function () use (&$gpuProbeCalls): array {
                $gpuProbeCalls++;
                return ['free_vram_mb' => 65536, 'processes' => []];
            },
            'executor' => static function () use (&$executorCalls): array {
                $executorCalls++;
                return [];
            },
        ]);
        hub_test_assert(($result['error_code'] ?? '') === 'model_assets_unavailable' && $executorCalls === 0 && $gpuProbeCalls === 0, 'requested diarization preflight must fail before GPU work or inference');
    } finally {
        hub_test_audio_cleanup_remove($models);
        hub_test_audio_cleanup_remove($cache);
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('Whisper ASR normalizes only typed transcription controls', function (): void {
    $route = hub_pack_async_job_contract(hub_get_pack('whisper-asr')['manifest'], 'transcribe');
    $input = hub_pack_job_normalize_request_input([
        'model' => 'large_v3',
        'language' => 'zh',
        'word_timestamps' => '1',
        'diarization' => 'true',
        'min_speakers' => '2',
        'max_speakers' => '3',
        'output_srt' => '0',
        'output_vtt' => '1',
        'subtitle_reflow' => 'legacy_adaptive_v1',
    ], $route);
    hub_test_assert($input === ['model' => 'large_v3', 'language' => 'zh', 'word_timestamps' => true, 'diarization' => true, 'min_speakers' => 2, 'max_speakers' => 3, 'output_srt' => false, 'output_vtt' => true, 'subtitle_reflow' => 'legacy_adaptive_v1'], 'Whisper controls must normalize to fixed scalar types');
    $basicAuto = hub_pack_job_normalize_request_input(['language' => 'auto', 'word_timestamps' => false], $route);
    hub_test_assert(($basicAuto['language'] ?? null) === 'auto' && ($basicAuto['word_timestamps'] ?? null) === false, 'Basic ASR must keep auto language valid when word timestamps are disabled');
    foreach ([
        ['model' => 'large_v3', 'diarization' => false, 'min_speakers' => 2],
        ['model' => 'large_v3', 'diarization' => true, 'min_speakers' => 4, 'max_speakers' => 3],
        ['model' => 'large_v3', 'language' => 'auto', 'word_timestamps' => true],
        ['model' => 'large_v3', 'subtitle_reflow' => 'unsupported'],
        ['model' => 'host-path'],
    ] as $invalid) {
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input($invalid, $route)), 'Whisper invalid typed controls must be rejected');
    }
});

hub_test('Whisper ASR job enqueue stores defaulted transcription controls', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Whisper Defaults Owner');
    $token = hub_create_api_token($db, $member, 'whisper defaults token', null, null);
    $taskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), [], $member, (int)$token['token_id'], '203.0.113.51');
    $task = hub_get_task($db, $taskId);
    hub_test_assert(($task['input'] ?? null) === ['model' => 'large_v3', 'language' => 'auto', 'word_timestamps' => false, 'diarization' => false, 'output_srt' => false, 'output_vtt' => false, 'subtitle_reflow' => 'none'], 'generic Pack enqueue must persist the normalized defaults');
});

hub_test('Whisper ASR runner loads optional models only when requested', function (): void {
    $runner = hub_test_whisper_async_runner();
    $script = <<<'PY'
import importlib.util
import json
import sys
import tempfile
import types
from pathlib import Path

sys.path.insert(0, str(Path(sys.argv[1]).parent))
reflowed_segments = [
    {"start": 0.10, "end": 1.62, "text": "今天，我們測試 AI Hub。"},
    {"start": 1.90, "end": 2.20, "text": "完成。"},
]
subtitle_breaker = ["ckip"]
reflow_calls = []
subtitle_reflow = types.ModuleType("subtitle_reflow")

def reflow_legacy_segments(segments, *args, **kwargs):
    reflow_calls.append((json.loads(json.dumps(segments)), args, kwargs))
    return reflowed_segments, {"subtitle_breaker": subtitle_breaker[0]}

subtitle_reflow.reflow_legacy_segments = reflow_legacy_segments
sys.modules["subtitle_reflow"] = subtitle_reflow
spec = importlib.util.spec_from_file_location("whisper_asr_job", sys.argv[1])
job = importlib.util.module_from_spec(spec)
spec.loader.exec_module(job)
loads = []
transcriptions = []
job.require_asr_assets = lambda: None
job.require_alignment_assets = lambda language: None
job.require_diarization_assets = lambda: None
job.require_cuda = lambda: None
job.load_asr = lambda model: loads.append(("asr", model)) or object()

def transcribe(model, source, language, words):
    transcriptions.append((language, words))
    segment = {"start": 0.0, "end": 1.0, "text": "hello"}
    if words:
        segment["words"] = [{"start": 0.0, "end": 1.0, "word": "hello"}]
    return [segment], "en"

job.transcribe = transcribe
job.load_alignment = lambda language: loads.append(("align", language)) or object()

def align(loader, segments, source, language):
    return [segment | {"words": [word | {"word": "aligned"} for word in segment.get("words", [])]} for segment in segments]

job.align = align
job.load_diarization = lambda: loads.append(("diarize", "local-model")) or object()
job.diarize = lambda loader, source, minimum, maximum: [{"start": 0.0, "end": 1.0, "speaker": "internal"}]

def run(request):
    with tempfile.TemporaryDirectory() as directory:
        workspace = Path(directory)
        input_dir = workspace / "input"
        output_dir = workspace / "output"
        input_dir.mkdir()
        (input_dir / "source").write_bytes(b"RIFFaudio")
        (input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")
        (input_dir / "runner_config.json").write_text(json.dumps({"model": {"model": "/models/whisper/asr/large-v3", "label": "large-v3"}}), encoding="utf-8")
        job.run_job(workspace, input_dir, output_dir, input_dir / "runner_config.json")
        return {
            "transcript": json.loads((output_dir / "transcript.json").read_text(encoding="utf-8")),
            "files": {path.name for path in output_dir.iterdir()},
            "speaker": json.loads((output_dir / "speaker_timeline.json").read_text(encoding="utf-8"))["speakers"][0]["speaker"] if (output_dir / "speaker_timeline.json").is_file() else None,
            "srt": (output_dir / "subtitle.srt").read_text(encoding="utf-8") if (output_dir / "subtitle.srt").is_file() else None,
            "vtt": (output_dir / "subtitle.vtt").read_text(encoding="utf-8") if (output_dir / "subtitle.vtt").is_file() else None,
            "report": json.loads((output_dir / "transcription_report.json").read_text(encoding="utf-8")),
        }

base = {"model": "large_v3", "language": "auto", "word_timestamps": False, "diarization": False, "output_srt": False, "output_vtt": False, "subtitle_reflow": "none"}
expected_srt = "1\n00:00:00,100 --> 00:00:01,620\n今天，我們測試 AI Hub。\n\n2\n00:00:01,900 --> 00:00:02,200\n完成。\n"
expected_vtt = "WEBVTT\n\n00:00:00.100 --> 00:00:01.620\n今天，我們測試 AI Hub。\n\n00:00:01.900 --> 00:00:02.200\n完成。\n"
result = run(base)
assert result["transcript"]["text"] == "hello" and loads == [("asr", "/models/whisper/asr/large-v3")] and transcriptions == [(None, False)], "plain ASR must not request native words or optional models"
loads.clear()
transcriptions.clear()
result = run(base | {"language": "zh", "output_srt": True, "subtitle_reflow": "legacy_adaptive_v1"})
assert loads == [("asr", "/models/whisper/asr/large-v3")] and transcriptions == [("zh", True)], "legacy subtitle reflow must request native words without WhisperX alignment"
assert "words" not in result["transcript"]["segments"][0], "legacy subtitle reflow must not expose native words in transcript JSON"
assert result["srt"] == expected_srt and result["report"]["subtitle_breaker"] == "ckip" and len(reflow_calls) == 1, "legacy reflow must write CKIP-reflowed SRT cues and report the actual breaker"
assert reflow_calls[0][0] == [{"start": 0.0, "end": 1.0, "text": "hello", "words": [{"start": 0.0, "end": 1.0, "word": "hello"}]}], "legacy reflow must receive native words before transcript serialization"
loads.clear()
transcriptions.clear()
result = run(base | {"word_timestamps": True, "language": "en", "output_vtt": True})
assert loads == [("asr", "/models/whisper/asr/large-v3"), ("align", "en")] and transcriptions == [("en", True)], "explicit word timestamps must retain WhisperX alignment"
assert result["transcript"]["segments"][0]["words"] == [{"start": 0.0, "end": 1.0, "word": "aligned"}], "explicit word timestamps must retain aligned words in transcript JSON"
loads.clear()
transcriptions.clear()
reflow_calls.clear()
subtitle_breaker[0] = "jieba"
result = run(base | {"word_timestamps": True, "language": "en", "output_vtt": True, "subtitle_reflow": "legacy_adaptive_v1"})
assert loads == [("asr", "/models/whisper/asr/large-v3"), ("align", "en")] and transcriptions == [("en", True)], "legacy reflow with explicit words must still load WhisperX alignment"
assert reflow_calls[0][0] == [{"start": 0.0, "end": 1.0, "text": "hello", "words": [{"start": 0.0, "end": 1.0, "word": "aligned"}]}], "legacy reflow with explicit words must receive WhisperX-aligned words before transcript serialization"
assert result["transcript"]["segments"][0]["words"] == [{"start": 0.0, "end": 1.0, "word": "aligned"}], "legacy reflow with explicit words must retain aligned transcript words"
assert result["vtt"] == expected_vtt and result["report"]["subtitle_breaker"] == "jieba" and len(reflow_calls) == 1, "legacy reflow must write Jieba-reflowed VTT cues and report the actual breaker"
loads.clear()
transcriptions.clear()
result = run(base | {"diarization": True, "min_speakers": 1, "max_speakers": 2, "output_srt": True, "output_vtt": True})
assert loads == [("asr", "/models/whisper/asr/large-v3"), ("diarize", "local-model")] and transcriptions == [(None, False)]
assert {"subtitle.srt", "subtitle.vtt", "speaker_timeline.json"} <= result["files"]
assert result["speaker"] == "speaker_01"
PY;
    $result = hub_run_command(['python3', '-c', $script, $runner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper runner loading matrix failed: ' . ($result['stderr'] ?? ''));
    $source = (string)file_get_contents($runner);
    hub_test_assert(str_contains($source, 'local_files_only=True') && str_contains($source, 'HF_HUB_OFFLINE') && !str_contains($source, 'snapshot_download') && !str_contains($source, 'AIHUB_SECRET_PYANNOTE_TOKEN'), 'request execution must use local model/cache files without a download or runtime pyannote credential');
});

hub_test('Whisper ASR runner requires optional caches only for the requested feature', function (): void {
    $runner = hub_test_whisper_async_runner();
    $script = <<<'PY'
import importlib.util
import json
import sys
import tempfile
import types
from pathlib import Path

sys.path.insert(0, str(Path(sys.argv[1]).parent))
spec = importlib.util.spec_from_file_location("whisper_asr_job", sys.argv[1])
job = importlib.util.module_from_spec(spec)
spec.loader.exec_module(job)
loads = []

def align_loader(**kwargs):
    loads.append(("align", kwargs))
    return object(), {}

def diarization_loader(**kwargs):
    loads.append(("diarize", kwargs))
    return object()

previous = sys.modules.get("whisperx")
sys.modules["whisperx"] = types.SimpleNamespace(
    load_align_model=align_loader,
    DiarizationPipeline=diarization_loader,
    align=lambda segments, *args, **kwargs: {"segments": segments},
)
try:
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        job.ASR_MODEL_DIR = root / "models" / "large-v3"
        job.TORCH_CACHE_DIR = root / "cache" / "torch"
        job.ALIGNMENT_WEIGHT = job.TORCH_CACHE_DIR / "wav2vec2_fairseq_base_ls960_asr_ls960.pth"
        job.ALIGNMENT_MARKER = job.TORCH_CACHE_DIR / ".aihub-alignment-ready.json"
        job.PYANNOTE_CONFIG = root / "cache" / "pyannote" / "config.yaml"
        job.PYANNOTE_SEGMENTATION = job.PYANNOTE_CONFIG.parent / "models" / "pyannote_segmentation-3.0.bin"
        job.PYANNOTE_EMBEDDING = job.PYANNOTE_CONFIG.parent / "models" / "pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"
        job.PYANNOTE_MARKER = job.PYANNOTE_CONFIG.parent / ".aihub-pyannote-ready.json"
        job.ASR_MODEL_DIR.mkdir(parents=True)
        for name in ("config.json", "model.bin", "tokenizer.json"):
            (job.ASR_MODEL_DIR / name).write_text("model", encoding="utf-8")
        cuda_calls = []
        job.require_cuda = lambda: cuda_calls.append("cuda")
        job.load_asr = lambda model: loads.append(("asr", model)) or object()
        job.transcribe = lambda model, source, language, words: ([{"start": 0.0, "end": 1.0, "text": "hello"}], "en")
        job.diarize = lambda loader, source, minimum, maximum: [{"start": 0.0, "end": 1.0, "speaker": "private"}]
        runs = [0]

        def run(request):
            runs[0] += 1
            workspace = root / ("workspace_" + str(runs[0]))
            input_dir = workspace / "input"
            output_dir = workspace / "output"
            input_dir.mkdir(parents=True)
            (input_dir / "source").write_bytes(b"RIFFaudio")
            (input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")
            (input_dir / "runner_config.json").write_text(json.dumps({"model": {"model": str(job.ASR_MODEL_DIR), "label": "large-v3"}}), encoding="utf-8")
            job.run_job(workspace, input_dir, output_dir, input_dir / "runner_config.json")

        base = {"model": "large_v3", "language": "auto", "word_timestamps": False, "diarization": False, "output_srt": False, "output_vtt": False, "subtitle_reflow": "none"}
        run(base)
        assert loads == [("asr", str(job.ASR_MODEL_DIR))]
        loads.clear()
        cuda_calls.clear()
        try:
            run(base | {"word_timestamps": True})
        except RuntimeError as error:
            assert str(error) == "request_invalid"
        else:
            raise AssertionError("auto language must be rejected before GPU-dependent timestamp alignment")
        assert loads == [] and cuda_calls == []
        try:
            run(base | {"word_timestamps": True, "language": "en"})
        except RuntimeError as error:
            assert str(error) == "alignment_cache_unavailable"
        else:
            raise AssertionError("word timestamps must require the exact alignment cache")
        try:
            run(base | {"diarization": True})
        except RuntimeError as error:
            assert str(error) == "diarization_model_unavailable"
        else:
            raise AssertionError("diarization must require the local pyannote model")

        job.TORCH_CACHE_DIR.mkdir(parents=True)
        job.ALIGNMENT_WEIGHT.write_text("weights", encoding="utf-8")
        job.ALIGNMENT_MARKER.write_text('{"schema":"aihub-whisper-alignment/v1","language":"en","model_name":"WAV2VEC2_ASR_BASE_960H","model_dir":"/cache/whisper/torch","weight_path":"/cache/whisper/torch/wav2vec2_fairseq_base_ls960_asr_ls960.pth","alignment_languages":["en"]}', encoding="utf-8")
        loads.clear()
        run(base | {"word_timestamps": True, "language": "en"})
        assert loads[0] == ("asr", str(job.ASR_MODEL_DIR)) and loads[1][0] == "align"

        job.PYANNOTE_CONFIG.parent.joinpath("models").mkdir(parents=True)
        job.PYANNOTE_CONFIG.write_text("config", encoding="utf-8")
        job.PYANNOTE_SEGMENTATION.write_text("segmentation", encoding="utf-8")
        job.PYANNOTE_EMBEDDING.write_text("embedding", encoding="utf-8")
        job.PYANNOTE_MARKER.write_text('{"schema":"aihub-whisper-pyannote/v1","config_path":"/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml","segmentation_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_segmentation-3.0.bin","embedding_path":"/cache/whisper/pyannote/speaker-diarization-3.1/models/pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"}', encoding="utf-8")
        loads.clear()
        run(base | {"diarization": True})
        assert loads[0] == ("asr", str(job.ASR_MODEL_DIR)) and loads[1][0] == "diarize"
finally:
    if previous is None:
        sys.modules.pop("whisperx", None)
    else:
        sys.modules["whisperx"] = previous
PY;
    $result = hub_run_command(['python3', '-c', $script, $runner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper optional-asset preflight matrix failed: ' . ($result['stderr'] ?? ''));
});

hub_test('Whisper legacy adaptive reflow preserves native word boundaries and diagnoses CKIP fallback', function (): void {
    $runner = hub_test_whisper_async_runner();
    $script = <<<'PY'
import sys
from pathlib import Path

sys.path.insert(0, str(Path(sys.argv[1]).parent))
from subtitle_reflow import reflow_legacy_segments

segments = [{
    "start": -10.0,
    "end": 10.0,
    "text": "今天，我們測試 AI Hub。完成。",
    "words": [
        {"start": 0.10, "end": 0.40, "word": "今天"},
        {"start": 0.45, "end": 0.80, "word": "我們"},
        {"start": 0.82, "end": 1.10, "word": "測試"},
        {"start": 1.12, "end": 1.30, "word": "AI"},
        {"start": 1.32, "end": 1.62, "word": "Hub"},
        {"start": 1.90, "end": 2.20, "word": "完成"},
    ],
}]
tokens = ["今天", "，", "我們", "測試", "AI", "Hub", "。", "完成", "。"]
expected = [
    {"start": 0.10, "end": 1.62, "text": "今天，我們測試 AI Hub。"},
    {"start": 1.90, "end": 2.20, "text": "完成。"},
]

primary_calls = []
def ckip_segment(text):
    primary_calls.append(text)
    return tokens

def jieba_segment(text):
    raise AssertionError("Jieba must not run after CKIP succeeds")

reflowed, diagnostic = reflow_legacy_segments(segments, free_vram_mb=4096, ckip_segment=ckip_segment, jieba_segment=jieba_segment)
assert reflowed == expected
assert primary_calls == ["今天，我們測試 AI Hub。完成。"]
assert diagnostic == {"subtitle_breaker": "ckip", "ckip_error": None}

low_vram_calls = []
def ckip_must_not_run(text):
    raise AssertionError("CKIP must be skipped below 4 GiB free VRAM")

def low_vram_jieba(text):
    low_vram_calls.append(text)
    return tokens

low_vram, low_vram_diagnostic = reflow_legacy_segments(segments, free_vram_mb=4095, ckip_segment=ckip_must_not_run, jieba_segment=low_vram_jieba)
assert low_vram == expected
assert low_vram_calls == ["今天，我們測試 AI Hub。完成。"]
assert low_vram_diagnostic == {"subtitle_breaker": "jieba", "ckip_error": None}

fallback_calls = []
def broken_ckip(text):
    raise RuntimeError("ckip model unavailable")

def fallback_jieba(text):
    fallback_calls.append(text)
    return tokens

fallback, fallback_diagnostic = reflow_legacy_segments(segments, free_vram_mb=4096, ckip_segment=broken_ckip, jieba_segment=fallback_jieba)
assert fallback == expected
assert fallback_calls == ["今天，我們測試 AI Hub。完成。"]
assert fallback_diagnostic == {"subtitle_breaker": "jieba", "ckip_error": "ckip model unavailable"}
PY;
    $result = hub_run_command(['python3', '-c', $script, $runner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper legacy subtitle reflow fixture failed: ' . ($result['stderr'] ?? ''));
});

hub_test('Whisper ASR provisioning needs the pyannote token only for explicit diarization assets', function (): void {
    $provisioner = HUB_ROOT . '/packs/whisper-asr/service/provision_offline_assets.py';
    $script = <<<'PY'
import importlib.util
import json
import os
import sys
import tempfile
import types
from pathlib import Path

service_dir = Path(sys.argv[1]).parent
sys.path.insert(0, str(service_dir))
spec = importlib.util.spec_from_file_location("whisper_asr_provision", sys.argv[1])
provision = importlib.util.module_from_spec(spec)
spec.loader.exec_module(provision)

with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    provision.ASR_MODEL_DIR = root / "models" / "large-v3"
    provision.HUGGINGFACE_CACHE_DIR = root / "cache" / "huggingface"
    provision.TORCH_CACHE_DIR = root / "cache" / "torch"
    provision.ALIGNMENT_WEIGHT = provision.TORCH_CACHE_DIR / "wav2vec2_fairseq_base_ls960_asr_ls960.pth"
    provision.ALIGNMENT_MARKER = provision.TORCH_CACHE_DIR / ".aihub-alignment-ready.json"
    provision.PYANNOTE_MODEL_DIR = root / "cache" / "pyannote" / "speaker-diarization-3.1"
    provision.PYANNOTE_CONFIG = provision.PYANNOTE_MODEL_DIR / "config.yaml"
    provision.PYANNOTE_SEGMENTATION = provision.PYANNOTE_MODEL_DIR / "models" / "pyannote_segmentation-3.0.bin"
    provision.PYANNOTE_EMBEDDING = provision.PYANNOTE_MODEL_DIR / "models" / "pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"
    provision.PYANNOTE_MARKER = provision.PYANNOTE_MODEL_DIR / ".aihub-pyannote-ready.json"
    provision.CKIP_MODEL_DIR = root / "cache" / "ckip" / "bert-base-chinese-ws"
    provision.CKIP_MARKER = provision.CKIP_MODEL_DIR / ".aihub-ckip-ready.json"
    provision.CKIP_CACHE_ROOT = root / "cache"
    calls = []
    ckip_loads = []
    fail_ckip_snapshot = [False]

    def snapshot_download(repo_id, local_dir, allow_patterns=None, token=None):
        calls.append((repo_id, token))
        target = Path(local_dir)
        target.mkdir(parents=True, exist_ok=True)
        if repo_id == "ckiplab/bert-base-chinese-ws":
            if fail_ckip_snapshot[0]:
                assert not provision.CKIP_MARKER.exists(), "CKIP marker must be removed before snapshot mutation"
                (target / "config.json").write_text("incomplete", encoding="utf-8")
                raise RuntimeError("ckip_snapshot_failed")
            for name in ("config.json", "pytorch_model.bin", "vocab.txt"):
                (target / name).write_text("snapshot", encoding="utf-8")
        for name in allow_patterns or []:
            (target / name).write_text("snapshot", encoding="utf-8")
        return str(target)

    def align_loader(**kwargs):
        provision.ALIGNMENT_WEIGHT.parent.mkdir(parents=True, exist_ok=True)
        provision.ALIGNMENT_WEIGHT.write_text("weights", encoding="utf-8")
        return object(), {}

    previous_hub = sys.modules.get("huggingface_hub")
    previous_whisperx = sys.modules.get("whisperx")
    previous_ckip = sys.modules.get("ckip_transformers")
    previous_ckip_nlp = sys.modules.get("ckip_transformers.nlp")
    sys.modules["huggingface_hub"] = types.SimpleNamespace(snapshot_download=snapshot_download)
    sys.modules["whisperx"] = types.SimpleNamespace(load_align_model=align_loader, DiarizationPipeline=lambda **kwargs: object())
    ckip_module = types.ModuleType("ckip_transformers")
    ckip_nlp = types.ModuleType("ckip_transformers.nlp")
    ckip_nlp.CkipWordSegmenter = lambda **kwargs: ckip_loads.append(kwargs) or object()
    ckip_module.nlp = ckip_nlp
    sys.modules["ckip_transformers"] = ckip_module
    sys.modules["ckip_transformers.nlp"] = ckip_nlp
    original_argv = sys.argv
    original_token = os.environ.pop("AIHUB_SECRET_PYANNOTE_TOKEN", None)
    try:
        provision.storage_root = lambda name, expected: expected
        sys.argv = ["provision"]
        assert provision.main() == 0
        assert all(not repo.startswith(("pyannote/", "ckiplab/")) for repo, token in calls)
        assert provision.ALIGNMENT_MARKER.is_file() and not provision.PYANNOTE_MARKER.exists() and not provision.CKIP_MARKER.exists()
        sys.argv = ["provision", "--with-ckip"]
        assert provision.main() == 0
        assert ("ckiplab/bert-base-chinese-ws", None) in calls
        assert ckip_loads == [{"model_name": str(provision.CKIP_MODEL_DIR), "device": -1}]
        assert json.loads(provision.CKIP_MARKER.read_text(encoding="utf-8")) == provision.ckip_cache_manifest()
        fail_ckip_snapshot[0] = True
        try:
            provision.main()
        except RuntimeError as error:
            assert str(error) == "ckip_snapshot_failed"
        else:
            raise AssertionError("failed CKIP reprovisioning must fail")
        assert not provision.CKIP_MARKER.exists(), "failed CKIP reprovisioning must not leave a ready marker"
        sys.argv = ["provision", "--with-diarization"]
        try:
            provision.main()
        except RuntimeError as error:
            assert str(error) == "pyannote_token_missing"
        else:
            raise AssertionError("explicit diarization provisioning must require the pyannote token")
    finally:
        sys.argv = original_argv
        if original_token is not None:
            os.environ["AIHUB_SECRET_PYANNOTE_TOKEN"] = original_token
        if previous_hub is None:
            sys.modules.pop("huggingface_hub", None)
        else:
            sys.modules["huggingface_hub"] = previous_hub
        if previous_whisperx is None:
            sys.modules.pop("whisperx", None)
        else:
            sys.modules["whisperx"] = previous_whisperx
        if previous_ckip is None:
            sys.modules.pop("ckip_transformers", None)
        else:
            sys.modules["ckip_transformers"] = previous_ckip
        if previous_ckip_nlp is None:
            sys.modules.pop("ckip_transformers.nlp", None)
        else:
            sys.modules["ckip_transformers.nlp"] = previous_ckip_nlp
PY;
    $result = hub_run_command(['python3', '-c', $script, $provisioner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper provisioning option matrix failed: ' . ($result['stderr'] ?? ''));
});

hub_test('Whisper ASR provisioning and task loaders use the same fixed offline alignment and pyannote paths', function (): void {
    $runner = hub_test_whisper_async_runner();
    $provisioner = HUB_ROOT . '/packs/whisper-asr/service/provision_offline_assets.py';
    $script = <<<'PY'
import importlib.util
import os
import sys
import types
from pathlib import Path

service_dir = Path(sys.argv[1]).parent
sys.path.insert(0, str(service_dir))

def load(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

job = load("whisper_asr_job", sys.argv[1])
provision = load("whisper_asr_provision", sys.argv[2])
calls = []

def align_loader(**kwargs):
    calls.append(("align", kwargs))
    return object(), {}

def diarization_loader(**kwargs):
    calls.append(("diarization", kwargs))
    return object()

previous = sys.modules.get("whisperx")
sys.modules["whisperx"] = types.SimpleNamespace(
    load_align_model=align_loader,
    DiarizationPipeline=diarization_loader,
)
try:
    job.configure_offline_cache()
    job.load_alignment("en")
    job.load_diarization()
    provision.precache_alignment("en")
    provision.validate_local_diarization()
finally:
    if previous is None:
        sys.modules.pop("whisperx", None)
    else:
        sys.modules["whisperx"] = previous

expected_alignment = {
    "language_code": "en",
    "device": "cuda",
    "model_name": "WAV2VEC2_ASR_BASE_960H",
    "model_dir": "/cache/whisper/torch",
}
assert calls[0] == ("align", expected_alignment)
assert calls[1] == ("diarization", {
    "model_name": "/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml",
    "use_auth_token": None,
    "device": "cuda",
})
assert calls[2] == ("align", expected_alignment | {"device": "cpu"})
assert calls[3] == ("diarization", {
    "model_name": "/cache/whisper/pyannote/speaker-diarization-3.1/config.yaml",
    "use_auth_token": None,
    "device": "cpu",
})
assert os.environ["TORCH_HOME"] == "/cache/whisper/torch"
assert os.environ["HF_HOME"] == "/cache/whisper/huggingface"
assert os.environ["HF_HUB_OFFLINE"] == "1"
assert os.environ["TRANSFORMERS_OFFLINE"] == "1"
assert os.environ["PYANNOTE_METRICS_ENABLED"] == "0"
PY;
    $result = hub_run_command(['python3', '-c', $script, $runner, $provisioner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper offline loader path matrix failed: ' . ($result['stderr'] ?? ''));
    $provisionSource = (string)file_get_contents($provisioner);
    $shellSource = (string)file_get_contents(HUB_ROOT . '/packs/whisper-asr/jobs/provision_offline_models.sh');
    hub_test_assert(str_contains($provisionSource, 'AIHUB_SECRET_PYANNOTE_TOKEN') && str_contains($shellSource, '--env AIHUB_SECRET_PYANNOTE_TOKEN')
        && str_contains($shellSource, 'dst=/models') && str_contains($shellSource, 'dst=/cache'), 'only the trusted provisioning action may supply the pyannote credential to the controlled runtime paths');
});

hub_test('Whisper CKIP offline asset paths declare the exact ready marker', function (): void {
    $paths = HUB_ROOT . '/packs/whisper-asr/service/offline_paths.py';
    $script = <<<'PY'
import sys
from pathlib import Path

sys.path.insert(0, str(Path(sys.argv[1]).parent))
import offline_paths

assert offline_paths.CKIP_MODEL_REPOSITORY == "ckiplab/bert-base-chinese-ws"
assert offline_paths.CKIP_MODEL_DIR == Path("/cache/whisper/ckip/bert-base-chinese-ws")
assert offline_paths.CKIP_MARKER == offline_paths.CKIP_MODEL_DIR / ".aihub-ckip-ready.json"
assert offline_paths.ckip_cache_manifest() == {
    "schema": "aihub-whisper-ckip/v1",
    "repository": "ckiplab/bert-base-chinese-ws",
    "model_path": "/cache/whisper/ckip/bert-base-chinese-ws",
    "breaker": "ckip-transformers-0.3.4",
}
PY;
    $result = hub_run_command(['python3', '-c', $script, $paths], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper CKIP offline path contract failed: ' . ($result['stderr'] ?? ''));
});

hub_test('Whisper CKIP provisioning rejects symlinked cache paths and safe-writes markers', function (): void {
    $provisioner = HUB_ROOT . '/packs/whisper-asr/service/provision_offline_assets.py';
    $script = <<<'PY'
import importlib.util
import sys
import tempfile
from pathlib import Path

service_dir = Path(sys.argv[1]).parent
sys.path.insert(0, str(service_dir))
spec = importlib.util.spec_from_file_location("whisper_asr_provision", sys.argv[1])
provision = importlib.util.module_from_spec(spec)
spec.loader.exec_module(provision)

with tempfile.TemporaryDirectory() as directory:
    root = Path(directory)
    cache = root / "cache"
    cache.mkdir()
    provision.CKIP_CACHE_ROOT = cache
    provision.CKIP_MODEL_DIR = cache / "whisper" / "ckip" / "bert-base-chinese-ws"
    provision.CKIP_MARKER = provision.CKIP_MODEL_DIR / ".aihub-ckip-ready.json"
    provision.require_ckip_model_directory()
    protected = root / "protected"
    protected.write_bytes(b"protected")
    predictable_temp = provision.CKIP_MARKER.with_name(provision.CKIP_MARKER.name + ".tmp")
    predictable_temp.symlink_to(protected)
    provision.write_atomic(provision.CKIP_MARKER, b"ready")
    assert protected.read_bytes() == b"protected"
    assert provision.CKIP_MARKER.read_bytes() == b"ready"

    linked = cache / "whisper"
    provision.CKIP_MARKER.unlink()
    predictable_temp.unlink()
    provision.CKIP_MODEL_DIR.rmdir()
    provision.CKIP_MODEL_DIR.parent.rmdir()
    linked.rmdir()
    linked.symlink_to(root / "outside", target_is_directory=True)
    try:
        provision.require_ckip_model_directory()
    except RuntimeError as error:
        assert str(error) == "ckip_directory_invalid"
    else:
        raise AssertionError("symlinked CKIP cache ancestor must be rejected")
PY;
    $result = hub_run_command(['python3', '-c', $script, $provisioner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper CKIP symlink safety contract failed: ' . ($result['stderr'] ?? ''));
});
