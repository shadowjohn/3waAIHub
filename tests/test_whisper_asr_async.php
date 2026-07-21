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
    hub_test_assert(($job['input_fields'] ?? []) === ['model', 'language', 'word_timestamps', 'diarization', 'min_speakers', 'max_speakers', 'output_srt', 'output_vtt'], 'Whisper input field allowlist mismatch');
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
            'id' => 'whisper_huggingface_cache',
            'storage' => 'cache',
            'host_subdir' => 'whisper/huggingface',
            'container_path' => '/cache/whisper/huggingface',
            'required_paths' => ['.aihub-offline-ready.json'],
        ],
    ], 'Whisper must declare only controlled Hub model/cache mount descriptors');
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

    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_asset_mounts($db, $runner)), 'missing offline assets must fail the controlled preflight');
    mkdir($models . '/whisper/asr/large-v3', 0775, true);
    foreach (['config.json', 'model.bin', 'tokenizer.json'] as $path) {
        file_put_contents($models . '/whisper/asr/large-v3/' . $path, '{}', LOCK_EX);
    }
    mkdir($cache . '/whisper/huggingface', 0775, true);
    file_put_contents($cache . '/whisper/huggingface/.aihub-offline-ready.json', '{"schema":"aihub-whisper-offline-cache/v1","alignment_languages":["en"],"pyannote_model":"pyannote/speaker-diarization-3.1"}', LOCK_EX);

    $assets = hub_pack_job_resolve_asset_mounts($db, $runner);
    hub_test_assert($assets === [
        ['id' => 'whisper_asr_large_v3', 'source' => $models . '/whisper/asr/large-v3', 'container_path' => '/models/whisper/asr/large-v3'],
        ['id' => 'whisper_huggingface_cache', 'source' => $cache . '/whisper/huggingface', 'container_path' => '/cache/whisper/huggingface'],
    ], 'asset resolver must derive fixed paths from Hub storage settings only');

    $workspace = sys_get_temp_dir() . '/3waaihub_whisper_mount_workspace_' . bin2hex(random_bytes(4));
    mkdir($workspace . '/input', 0775, true);
    mkdir($workspace . '/output', 0775, true);
    file_put_contents($workspace . '/input/source', 'audio', LOCK_EX);
    file_put_contents($workspace . '/input/request.json', '{}', LOCK_EX);
    file_put_contents($workspace . '/input/runner_config.json', '{}', LOCK_EX);
    $previousToken = getenv('AIHUB_SECRET_PYANNOTE_TOKEN');
    putenv('AIHUB_SECRET_PYANNOTE_TOKEN=test-env-only-token');
    try {
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
        hub_test_assert(in_array('type=bind,src=' . $models . '/whisper/asr/large-v3,dst=/models/whisper/asr/large-v3,readonly', $mounts, true)
            && in_array('type=bind,src=' . $cache . '/whisper/huggingface,dst=/cache/whisper/huggingface,readonly', $mounts, true), 'only resolved model/cache mounts may reach Docker, read-only');
        hub_test_assert(in_array('--env', $command, true) && in_array('AIHUB_SECRET_PYANNOTE_TOKEN', $command, true)
            && !str_contains(implode("\n", $command), 'test-env-only-token') && !str_contains(implode("\n", $command), 'AIHUB_SECRET_PYANNOTE_TOKEN='), 'Docker may forward only the secret name, never its value');
    } finally {
        putenv($previousToken === false ? 'AIHUB_SECRET_PYANNOTE_TOKEN' : 'AIHUB_SECRET_PYANNOTE_TOKEN=' . $previousToken);
        hub_test_audio_cleanup_remove($workspace);
        hub_test_audio_cleanup_remove($models);
        hub_test_audio_cleanup_remove($cache);
    }
});

hub_test('Whisper ASR fails missing assets before GPU reservation or executor invocation', function (): void {
    $db = hub_test_reset_db();
    $models = sys_get_temp_dir() . '/3waaihub_whisper_missing_models_' . bin2hex(random_bytes(4));
    $cache = sys_get_temp_dir() . '/3waaihub_whisper_missing_cache_' . bin2hex(random_bytes(4));
    mkdir($models, 0775, true);
    mkdir($cache, 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $models);
    hub_set_storage_setting($db, 'AIHUB_CACHE_DIR', $cache);
    hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
    $member = hub_create_api_member($db, 'Whisper Asset Preflight Owner');
    $token = hub_create_api_token($db, $member, 'whisper asset preflight token', null, null);
    $taskId = hub_enqueue_owned_pack_job($db, hub_resolve_audio_async_route($db, 'speech_transcribe'), [], $member, (int)$token['token_id'], '203.0.113.61');
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
        hub_test_assert(($result['error_code'] ?? '') === 'model_assets_unavailable' && $executorCalls === 0 && $gpuProbeCalls === 0, 'asset preflight must fail before GPU work or inference');
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
    ], $route);
    hub_test_assert($input === ['model' => 'large_v3', 'language' => 'zh', 'word_timestamps' => true, 'diarization' => true, 'min_speakers' => 2, 'max_speakers' => 3, 'output_srt' => false, 'output_vtt' => true], 'Whisper controls must normalize to fixed scalar types');
    foreach ([
        ['model' => 'large_v3', 'diarization' => false, 'min_speakers' => 2],
        ['model' => 'large_v3', 'diarization' => true, 'min_speakers' => 4, 'max_speakers' => 3],
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
    hub_test_assert(($task['input'] ?? null) === ['model' => 'large_v3', 'language' => 'auto', 'word_timestamps' => false, 'diarization' => false, 'output_srt' => false, 'output_vtt' => false], 'generic Pack enqueue must persist the normalized defaults');
});

hub_test('Whisper ASR runner loads optional models only when requested', function (): void {
    $runner = hub_test_whisper_async_runner();
    $script = <<<'PY'
import importlib.util
import json
import sys
import tempfile
from pathlib import Path

spec = importlib.util.spec_from_file_location("whisper_asr_job", sys.argv[1])
job = importlib.util.module_from_spec(spec)
spec.loader.exec_module(job)
loads = []
job.require_offline_assets = lambda request, language: None
job.require_cuda = lambda: None
job.load_asr = lambda model: loads.append(("asr", model)) or object()
job.transcribe = lambda model, source, language, words: ([{"start": 0.0, "end": 1.0, "text": "hello"}], "en")
job.load_alignment = lambda language: loads.append(("align", language)) or object()
job.align = lambda loader, segments, source, language: segments
job.load_diarization = lambda token: loads.append(("diarize", "token-present")) or object()
job.diarize = lambda loader, source, minimum, maximum: [{"start": 0.0, "end": 1.0, "speaker": "internal"}]

def run(request, token=None):
    with tempfile.TemporaryDirectory() as directory:
        workspace = Path(directory)
        input_dir = workspace / "input"
        output_dir = workspace / "output"
        input_dir.mkdir()
        (input_dir / "source").write_bytes(b"RIFFaudio")
        (input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")
        (input_dir / "runner_config.json").write_text(json.dumps({"model": {"model": "/models/whisper/asr/large-v3", "label": "large-v3"}}), encoding="utf-8")
        old = job.os.environ.get("AIHUB_SECRET_PYANNOTE_TOKEN")
        if token is None:
            job.os.environ.pop("AIHUB_SECRET_PYANNOTE_TOKEN", None)
        else:
            job.os.environ["AIHUB_SECRET_PYANNOTE_TOKEN"] = token
        try:
            job.run_job(workspace, input_dir, output_dir, input_dir / "runner_config.json")
            return {
                "transcript": json.loads((output_dir / "transcript.json").read_text(encoding="utf-8")),
                "files": {path.name for path in output_dir.iterdir()},
                "speaker": json.loads((output_dir / "speaker_timeline.json").read_text(encoding="utf-8"))["speakers"][0]["speaker"] if (output_dir / "speaker_timeline.json").is_file() else None,
                "report": (output_dir / "transcription_report.json").read_text(encoding="utf-8"),
            }
        finally:
            if old is None:
                job.os.environ.pop("AIHUB_SECRET_PYANNOTE_TOKEN", None)
            else:
                job.os.environ["AIHUB_SECRET_PYANNOTE_TOKEN"] = old

base = {"model": "large_v3", "language": "auto", "word_timestamps": False, "diarization": False, "output_srt": False, "output_vtt": False}
result = run(base)
assert result["transcript"]["text"] == "hello" and loads == [("asr", "/models/whisper/asr/large-v3")]
loads.clear()
run(base | {"word_timestamps": True})
assert loads == [("asr", "/models/whisper/asr/large-v3"), ("align", "en")]
loads.clear()
try:
    run(base | {"diarization": True})
except RuntimeError as error:
    assert str(error) == "diarization_token_missing"
else:
    raise AssertionError("missing diarization token must fail")
assert loads == [("asr", "/models/whisper/asr/large-v3")]
loads.clear()
result = run(base | {"diarization": True, "min_speakers": 1, "max_speakers": 2, "output_srt": True, "output_vtt": True}, "secret-not-output")
assert loads == [("asr", "/models/whisper/asr/large-v3"), ("diarize", "token-present")]
assert {"subtitle.srt", "subtitle.vtt", "speaker_timeline.json"} <= result["files"]
assert result["speaker"] == "speaker_01"
assert "secret-not-output" not in json.dumps(result["transcript"]) and "secret-not-output" not in result["report"]
PY;
    $result = hub_run_command(['python3', '-c', $script, $runner], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0, 'Whisper runner loading matrix failed: ' . ($result['stderr'] ?? ''));
    $source = (string)file_get_contents($runner);
    hub_test_assert(str_contains($source, 'local_files_only=True') && str_contains($source, 'HF_HUB_OFFLINE') && !str_contains($source, 'snapshot_download'), 'request execution must use local model/cache files without a download fallback');
});
