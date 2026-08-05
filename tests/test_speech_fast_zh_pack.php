<?php
declare(strict_types=1);

hub_test('Speech Fast ZH is an offline CPU audio Pack with Taiwanese draft text', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('speech-fast-zh');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'Speech Fast ZH Pack must validate');

    $manifest = $pack['manifest'];
    hub_test_assert(
        ($manifest['version'] ?? '') === '0.1.0'
        && ($manifest['type'] ?? '') === 'internal_task'
        && ($manifest['execution_type'] ?? '') === 'async_task'
        && ($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready'
        && ($manifest['target_level'] ?? '') === 'L5-benchmark-ready'
        && ($manifest['runtime_ready'] ?? false) === true
        && ($manifest['gateway']['require_service_enabled'] ?? false) === true,
        'Speech Fast ZH manifest runtime contract mismatch'
    );

    $job = hub_pack_async_job_contract($manifest, 'transcribe');
    hub_test_assert(is_array($job), 'Speech Fast ZH transcribe job contract missing');
    hub_test_assert(
        ($job['input_fields'] ?? []) === ['include_draft_subtitles']
        && ($job['source_artifact_types'] ?? []) === ['audio', 'cleaned_audio', 'vocals_audio']
        && (($job['request_schema']['include_draft_subtitles']['default'] ?? null) === false)
        && (($job['request_schema']['include_draft_subtitles']['example'] ?? null) === true),
        'Speech Fast ZH input contract mismatch'
    );
    hub_test_assert(
        ($job['runner']['accelerator'] ?? '') === 'cpu'
        && ($job['runner']['required_vram_mb'] ?? null) === 0
        && !array_key_exists('network_profile', (array)($job['runner'] ?? []))
        && (($job['runner']['asset_mounts'][0]['container_path'] ?? '') === '/models/paraformer')
        && (($job['runner']['asset_mounts'][0]['required_paths'] ?? []) === ['model.int8.onnx', 'tokens.txt', '.aihub-speech-fast-zh-ready.json']),
        'Speech Fast ZH must be an isolated CPU runner with managed Paraformer assets'
    );
    hub_test_assert(
        array_column((array)($job['artifact_contract']['artifacts'] ?? []), 'type') === [
            'transcript_json', 'transcription_report', 'draft_subtitle_srt', 'draft_segments',
        ],
        'Speech Fast ZH artifact contract mismatch'
    );
    hub_test_assert(
        (($job['artifact_contract']['artifacts'][0]['json']['required_keys'] ?? []) === [
            'raw_text', 'text', 'language', 'engine', 'provider', 'model', 'audio_seconds', 'elapsed_seconds', 'rtf',
        ]),
        'Speech Fast ZH transcript must preserve raw text and publish Taiwanese draft text'
    );
    $l5 = hub_pack_l5_contract($manifest);
    hub_test_assert(
        ($l5['task_type'] ?? '') === 'pack_job'
        && array_column((array)($l5['benchmark']['cases'] ?? []), 'id') === ['speech_fast_zh_submit_audio', 'speech_fast_zh_async_complete']
        && ($l5['output']['artifact_types'] ?? []) === ['transcript_json', 'transcription_report', 'draft_subtitle_srt', 'draft_segments'],
        'Speech Fast ZH L5 contract must publish its benchmark and artifact contract'
    );
    $acceptance = (string)file_get_contents(HUB_ROOT . '/scripts/audio_packs_acceptance.php');
    hub_test_assert(
        str_contains($acceptance, "'speech-fast-zh'")
        && str_contains($acceptance, "'speech_transcribe_fast_zh'")
        && str_contains($acceptance, "'speech_fast_zh_async_complete'")
        && str_contains($acceptance, "\$sourceField = \$pack === 'speech-fast-zh' ? 'file' : 'source';")
        && str_contains($acceptance, "'gpu_after' => \$config['requires_gpu'] ? hub_audio_acceptance_gpu_snapshot() : null"),
        'Speech Fast ZH real acceptance must reuse the audio acceptance client'
    );

    $installed = hub_install_pack($db, 'speech-fast-zh', ['idempotent' => true]);
    hub_set_service_enabled($db, 'speech_transcribe_fast_zh', true);
    $route = hub_resolve_audio_async_route($db, 'speech_transcribe_fast_zh');
    hub_test_assert(
        $route['requested_mode'] === 'speech_transcribe_fast_zh'
        && $route['pack_id'] === 'speech-fast-zh'
        && $route['pack_version'] === $installed['service']['pack_version']
        && $route['job'] === 'transcribe'
        && $route['accelerator'] === 'cpu',
        'Speech Fast ZH canonical audio route mismatch'
    );
});

hub_test('Speech Fast ZH exposes a cancellable L5 API submission benchmark', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'speech-fast-zh', ['idempotent' => true]);

    $benchmark = hub_run_benchmark_case($db, 'speech_fast_zh_submit_audio', 'speech-fast-zh');
    hub_test_assert(
        $benchmark['ok'] === true
        && ($benchmark['result']['mode'] ?? '') === 'speech_transcribe_fast_zh'
        && ($benchmark['result']['expected_keys_pass'] ?? false) === true
        && ($benchmark['result']['cancelled_submitted_task'] ?? false) === true
        && (int)$db->query("SELECT COUNT(*) FROM api_members WHERE name = 'Internal benchmark runner'")->fetchColumn() === 0,
        'Speech Fast ZH L5 submit benchmark must validate and cancel its queued demo task'
    );

    hub_save_benchmark_run($db, 'speech_fast_zh_async_complete', null, 'speech_transcribe_fast_zh', 'pass', 1, ['ok' => true], null);
    $readiness = hub_pack_l5_readiness($db, 'speech-fast-zh');
    hub_test_assert(
        ($readiness['checks']['latest_benchmark_pass'] ?? false) === true
        && ($readiness['checks']['real_inference_benchmark_passed'] ?? false) === true,
        'Speech Fast ZH L5 readiness must require the recorded real API acceptance'
    );

    $benchmarkPage = (string)file_get_contents(HUB_ROOT . '/admin/benchmarks.php');
    hub_test_assert(
        str_contains($benchmarkPage, '--pack=speech-fast-zh --case=speech_fast_zh_submit_audio')
        && str_contains($benchmarkPage, '--record-l5'),
        'Benchmark page must distinguish Fast Chinese ASR submission and real API acceptance'
    );
});
