<?php
declare(strict_types=1);

function hub_test_pack_job_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_pack_job_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/output', 0775, true)) {
        throw new RuntimeException('Cannot create Pack-job workspace fixture.');
    }

    return $workspace;
}

function hub_test_pack_job_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            hub_test_pack_job_rm($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_pack_job_clear_published_artifacts(PDO $db, int $taskId): void
{
    $task = hub_get_task($db, $taskId);
    if (($task['task_type'] ?? '') !== 'pack_job') {
        throw new RuntimeException('Pack-job artifact cleanup requires its fixture task.');
    }
    $resultsRoot = realpath(HUB_DATA_DIR . '/results');
    $taskResultDir = realpath(hub_task_result_dir($taskId));
    if ($resultsRoot === false || $taskResultDir === false || !str_starts_with($taskResultDir, $resultsRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Pack-job artifact cleanup target is outside Hub results.');
    }
    $artifactPath = $taskResultDir . '/artifacts';
    clearstatcache(true, $artifactPath);
    if (!file_exists($artifactPath)) {
        return;
    }
    if (is_link($artifactPath) || !is_dir($artifactPath) || realpath($artifactPath) !== $artifactPath) {
        throw new RuntimeException('Pack-job artifact cleanup target is invalid.');
    }
    hub_test_pack_job_rm($artifactPath);
}

function hub_test_pack_job_contract(): array
{
    return [
        'artifacts' => [
            [
                'type' => 'transcript_json',
                'path' => 'transcript.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 1048576,
                'json' => ['required_keys' => ['text']],
            ],
            [
                'type' => 'subtitle_text',
                'path' => 'subtitle.srt',
                'mime_types' => ['text/plain'],
                'max_bytes' => 128,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 128],
            ],
            [
                'type' => 'audio',
                'path' => 'audio.wav',
                'mime_types' => ['audio/wav', 'audio/x-wav'],
                'max_bytes' => 1048576,
                'audio' => [],
            ],
        ],
    ];
}

hub_test('ffprobe receives the artifact path as one argv argument', function (): void {
    $calls = [];
    $path = 'C:/3waAIHub test/input;not-a-shell.wav';
    $metadata = hub_pack_job_ffprobe($path, static function (array $command, int $timeoutSeconds) use (&$calls): array {
        $calls[] = ['command' => $command, 'timeout' => $timeoutSeconds];
        return [
            'exit_code' => 0,
            'stdout' => '{"format":{"duration":"1.5"},"streams":[{"codec_type":"audio","sample_rate":"16000","channels":1,"duration_ts":"24000","time_base":"1/16000"}]}',
            'stderr' => '',
        ];
    });

    hub_test_assert($calls === [[
        'command' => ['ffprobe', '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,sample_rate,channels,duration_ts,time_base', '-of', 'json', $path],
        'timeout' => 30,
    ]], 'ffprobe path must remain a single argv value');
    hub_test_assert($metadata === ['duration_seconds' => '1.5', 'sample_rate' => '16000', 'channels' => 1, 'frames' => '24000'], 'ffprobe argv runner output must preserve audio metadata');
});

hub_test('resident Pack output copy accepts only a direct managed output file', function (): void {
    $workspace = hub_test_pack_job_workspace();
    $stage = hub_test_pack_job_workspace();
    try {
        $path = hub_pack_job_resident_output_file($workspace, '.aihub-alignment-ready.json');
        $outputRoot = realpath($workspace . '/output');

        hub_test_assert($outputRoot !== false, 'resident output root must resolve');
        hub_test_assert(hub_storage_paths_equal(dirname($path), $outputRoot), 'resident output copy path must remain in managed output root');
        hub_test_assert(basename($path) === '.aihub-alignment-ready.json', 'resident output filename changed');
        hub_test_assert(hub_test_throws(static fn (): string => hub_pack_job_resident_output_file($workspace, '../escape.json')), 'resident output path accepted traversal');
        hub_test_assert(hub_test_throws(static fn (): string => hub_pack_job_resident_output_file($workspace, 'escape\\file.json')), 'resident output path accepted a Windows separator');

        hub_test_pack_job_write($stage . '/output/.aihub-alignment-ready.json', "{\"ready\":true}\n");
        hub_pack_job_resident_copy_output($stage, $workspace, [
            'artifacts' => [['path' => '.aihub-alignment-ready.json']],
        ]);
        hub_test_assert(file_get_contents($path) === "{\"ready\":true}\n", 'resident output copy must preserve managed artifact contents');
    } finally {
        hub_test_pack_job_rm($workspace);
        hub_test_pack_job_rm($stage);
    }
});

function hub_test_pack_job_write(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write Pack-job output fixture.');
    }
}

function hub_test_pack_job_wav(): string
{
    return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . 'data' . pack('V', 0);
}

function hub_test_pack_job_png(int $width, int $height): string
{
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $rows = str_repeat("\x00" . str_repeat("\x00", $width), $height);

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNC5', $width, $height, 8, 0, 0, 0, 0))
        . $chunk('IDAT', gzcompress($rows))
        . $chunk('IEND', '');
}

function hub_test_pack_job_capture_report(): string
{
    return json_encode([
        'requested_url' => 'https://example.com/',
        'final_url' => 'https://example.com/',
        'http_status' => 200,
        'viewport' => ['width' => 1, 'height' => 1],
        'image' => ['width' => 1, 'height' => 1, 'bytes' => 1],
        'delay_seconds' => 0,
        'timeout_seconds' => 60,
        'javascript_executed' => false,
        'crop' => null,
        'elapsed_seconds' => 0.1,
        'playwright_version' => '1.61.1',
        'warnings' => [],
    ], JSON_THROW_ON_ERROR);
}

function hub_test_pack_job_truncated_png(): string
{
    $ihdr = pack('NNC5', 1, 1, 8, 0, 0, 0, 0);

    return "\x89PNG\r\n\x1a\n" . pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
}

function hub_test_pack_job_image_contract(int $maxWidth = 2, int $maxHeight = 2, int $maxPixels = 4): array
{
    return [
        'artifacts' => [[
            'type' => 'screenshot',
            'path' => 'screenshot.png',
            'mime_types' => ['image/png'],
            'max_bytes' => 1048576,
            'image' => [
                'format' => 'png',
                'max_width' => $maxWidth,
                'max_height' => $maxHeight,
                'max_pixels' => $maxPixels,
            ],
        ]],
    ];
}

function hub_test_pack_job_audio_probe(string $path): array
{
    hub_test_assert(is_file($path), 'audio probe must receive Hub-resolved output path');

    return ['duration_seconds' => 1.25, 'sample_rate' => 48000, 'channels' => 2];
}

function hub_test_artifact_breezy_wav(): string
{
    $samples = pack('v*', 0, 600, 0xfda8, 0, 300, 0xfed4, 0);

    return 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt '
        . pack('VvvVVvv', 16, 1, 1, 22050, 44100, 2, 16)
        . 'data' . pack('V', strlen($samples)) . $samples;
}

function hub_test_artifact_breezy_fixture(PDO $db, bool $withPronunciation = false): array
{
    $service = hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true])['service'];
    hub_update_service_settings($db, (int)$service['id'], ['BREEZYVOICE_EXECUTION_MODE' => 'isolated']);
    $modelDir = hub_test_models_dir() . '/breezyvoice';
    if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
        throw new RuntimeException('Cannot create BreezyVoice artifact model fixture.');
    }
    file_put_contents($modelDir . '/model-manifest.json', json_encode([
        'model' => 'MediaTek-Research/BreezyVoice',
        'model_revision' => str_repeat('a', 40),
        'upstream_revision' => str_repeat('b', 40),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    $memberId = hub_create_api_member($db, 'Breezy artifact owner ' . bin2hex(random_bytes(4)));
    $reference = hub_voice_profile_storage_dir() . '/breezy-artifact-' . bin2hex(random_bytes(6)) . '.wav';
    if (file_put_contents($reference, hub_test_artifact_breezy_wav(), LOCK_EX) === false) {
        throw new RuntimeException('Cannot write BreezyVoice artifact reference fixture.');
    }
    $prompt = '這是 BreezyVoice artifact 合約測試逐字稿。';
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Breezy artifact fixture',
        'reference_audio_path' => $reference,
        'reference_audio_sha256' => hash_file('sha256', $reference),
        'reference_contract' => 'generic',
        'prompt_text' => $prompt,
        'language' => 'zh-TW',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $profile = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $prompt);
    $confirmedAt = (string)($profile['prompt_text_confirmed_at'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $confirmedAt) !== 1) {
        throw new RuntimeException('BreezyVoice artifact fixture transcript was not confirmed.');
    }
    $contract = hub_pack_async_job_contract((array)(hub_get_pack('tts-breezyvoice')['manifest'] ?? []), 'synthesize');
    if (!is_array($contract)) {
        throw new RuntimeException('BreezyVoice artifact contract is unavailable.');
    }
    $snapshot = hub_pack_job_contract_snapshot($contract);
    $route = array_replace($contract, [
        'requested_mode' => 'voice_generate_breezy',
        'pack_id' => 'tts-breezyvoice',
        'pack_version' => '0.1.1',
        'job' => 'synthesize',
        'job_contract_json' => $snapshot['json'],
        'job_contract_digest' => $snapshot['digest'],
        'voice_context' => $contract['voice_context'],
        'runtime_mode' => 'job',
        'accelerator' => 'gpu',
        'route_resolved_at' => hub_now(),
    ]);
    $input = [
        'text' => '請產生二十四 kHz 單聲道 PCM16 測試音訊。',
        'mode' => 'ultimate_clone',
        'voice_profile_id' => $profileId,
        'seed' => 8181,
        'seed_policy' => 'best_effort',
        'voice_context' => [
            'mode' => 'ultimate_clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $reference),
            'prompt_text_sha256' => hash('sha256', $prompt),
            'prompt_text_confirmed_at' => $confirmedAt,
            'container_path' => '/data/voice_profiles/reference.wav',
        ],
    ];
    if ($withPronunciation) {
        $input['pronunciation'] = [
            'character_overrides' => [[
                'id' => 'character:fixture:ai',
                'match' => 'AI',
                'kind' => 'spoken_form',
                'value' => '欸哀',
            ]],
            'request_overrides' => [],
        ];
    }
    $taskId = hub_enqueue_owned_pack_job($db, $route, $input, $memberId, null, '127.0.0.1');
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    if (!is_array($task) || (int)$task['id'] !== $taskId) {
        throw new RuntimeException('BreezyVoice artifact task was not claimed.');
    }

    return ['task' => $task, 'task_id' => $taskId];
}

function hub_test_artifact_breezy_metadata(array $config, string $audio, bool $mismatch, bool $reorderedFormat = false): array
{
    $model = is_array($config['model'] ?? null) ? $config['model'] : $config;
    $metadata = [
        'model' => is_string($config['model'] ?? null) ? $config['model'] : (string)($model['model'] ?? 'MediaTek-Research/BreezyVoice'),
        'model_revision' => (string)($config['model_revision'] ?? $model['model_revision'] ?? ''),
        'upstream_revision' => (string)($config['upstream_revision'] ?? $model['upstream_revision'] ?? ''),
        'reference_audio_sha256' => (string)($config['reference_audio_sha256'] ?? ''),
        'transcript_sha256' => (string)($config['transcript_sha256'] ?? ''),
        'seed' => $config['seed'] ?? 8181,
        'seed_applied' => $config['seed_applied'] ?? false,
        'reproducibility' => $config['reproducibility'] ?? 'best_effort',
        'device' => $config['device'] ?? 'cuda',
        'final_format' => ['mime_type' => 'audio/wav', 'sample_rate' => 22050, 'channels' => 1, 'sample_format' => 'pcm_s16le'],
        'audio_sha256' => $mismatch ? str_repeat('0', 64) : hash('sha256', $audio),
        'audio_size_bytes' => strlen($audio),
    ];
    if ($reorderedFormat) {
        $metadata['final_format'] = [
            'channels' => 1,
            'mime_type' => 'audio/wav',
            'sample_format' => 'pcm_s16le',
            'sample_rate' => 22050,
        ];
    }

    return $metadata;
}

function hub_test_artifact_breezy_pronunciation_metadata(array $metadata, bool $valid): array
{
    $metadata['pronunciation'] = [
        'rule_revision' => 1,
        'spoken_text' => '欸哀 測試。',
        'model_text' => '欸哀測試。',
        'applied_rule_ids' => ['character:fixture:ai'],
        'characters' => ['model' => 5, 'source' => 5, 'spoken' => 5],
    ];
    if (!$valid) {
        $metadata['pronunciation']['model_text'] = ['not a string'];
    }

    return $metadata;
}

hub_test('BreezyVoice artifact seam accepts reordered format metadata and rejects mismatched metadata', function (): void {
    foreach ([[false, false], [false, true], [true, false]] as [$mismatch, $reorderedFormat]) {
        $db = hub_test_reset_db();
        $fixture = hub_test_artifact_breezy_fixture($db);
        $audio = hub_test_artifact_breezy_wav();
        $outcome = hub_run_pack_job_task($db, $fixture['task'], [
            'worker_id' => 'breezy-artifact-worker',
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 8192, 'processes' => []],
            'pid_inspector' => static fn (): array => [],
            'audio_probe' => static fn (): array => ['duration_seconds' => 0.001, 'sample_rate' => 22050, 'channels' => 1, 'frames' => 7],
            'executor' => static function (array $context) use ($audio, $mismatch, $reorderedFormat): array {
                file_put_contents($context['workspace'] . '/output/generated_audio.wav', $audio, LOCK_EX);
                file_put_contents(
                    $context['workspace'] . '/output/synthesis_metadata.json',
                    json_encode(hub_test_artifact_breezy_metadata((array)$context['runner']['config'], $audio, $mismatch, $reorderedFormat), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                    LOCK_EX,
                );

                return ['exit_code' => 0, 'completed_no_process_evidence' => true, 'cleanup' => hub_pack_job_no_work_cleanup()];
            },
        ]);
        $task = hub_get_task($db, $fixture['task_id']) ?: [];
        $artifactStatement = $db->prepare('SELECT * FROM task_artifacts WHERE task_id = :task_id ORDER BY id ASC');
        $artifactStatement->execute([':task_id' => $fixture['task_id']]);
        $artifacts = $artifactStatement->fetchAll(PDO::FETCH_ASSOC);
        $audioArtifact = null;
        foreach ($artifacts as $artifact) {
            if (($artifact['artifact_type'] ?? '') === 'generated_audio') {
                $audioArtifact = $artifact;
                break;
            }
        }
        if (!$mismatch) {
            hub_test_assert(
                ($outcome['status'] ?? '') === 'success'
                && ($task['status'] ?? '') === 'success'
                && is_array($audioArtifact)
                && ($audioArtifact['sha256'] ?? '') === hash('sha256', $audio)
                && (int)($audioArtifact['size_bytes'] ?? -1) === strlen($audio),
                'BreezyVoice must register the exact generated 22.05 kHz mono PCM16 WAV bytes'
            );
            continue;
        }
        hub_test_assert(
            ($outcome['status'] ?? '') === 'failed'
            && ($task['status'] ?? '') === 'failed'
            && ($task['error_code'] ?? '') === 'artifact_contract_rejected'
            && $artifacts === [],
            'BreezyVoice metadata mismatch must reject the artifact contract without publishing success'
        );
    }
});

hub_test('BreezyVoice pronunciation metadata is required only for opted-in tasks', function (): void {
    foreach ([true, false] as $valid) {
        $db = hub_test_reset_db();
        $fixture = hub_test_artifact_breezy_fixture($db, true);
        $audio = hub_test_artifact_breezy_wav();
        $outcome = hub_run_pack_job_task($db, $fixture['task'], [
            'worker_id' => 'breezy-pronunciation-artifact-worker',
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 8192, 'processes' => []],
            'pid_inspector' => static fn (): array => [],
            'audio_probe' => static fn (): array => ['duration_seconds' => 0.001, 'sample_rate' => 22050, 'channels' => 1, 'frames' => 7],
            'executor' => static function (array $context) use ($audio, $valid): array {
                file_put_contents($context['workspace'] . '/output/generated_audio.wav', $audio, LOCK_EX);
                $metadata = hub_test_artifact_breezy_pronunciation_metadata(
                    hub_test_artifact_breezy_metadata((array)$context['runner']['config'], $audio, false),
                    $valid,
                );
                file_put_contents(
                    $context['workspace'] . '/output/synthesis_metadata.json',
                    json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                    LOCK_EX,
                );

                return ['exit_code' => 0, 'completed_no_process_evidence' => true, 'cleanup' => hub_pack_job_no_work_cleanup()];
            },
        ]);
        $task = hub_get_task($db, $fixture['task_id']) ?: [];
        hub_test_assert(
            $valid
                ? (($outcome['status'] ?? '') === 'success' && ($task['status'] ?? '') === 'success')
                : (($outcome['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'artifact_contract_rejected'),
            'BreezyVoice pronunciation metadata must be private, complete, and tied to an opted-in request'
        );
    }
});

function hub_test_pack_job_create_terminal_fixture(PDO $db, ?int $callbackTargetId = null): array
{
    $memberId = hub_create_api_member($db, 'Pack Job Terminal Owner ' . bin2hex(random_bytes(3)));
    $sourceTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
    ]);
    $sourcePath = hub_task_result_dir($sourceTaskId) . '/source.wav';
    if (!is_dir(dirname($sourcePath)) && !mkdir(dirname($sourcePath), 0775, true) && !is_dir(dirname($sourcePath))) {
        throw new RuntimeException('Cannot create source artifact fixture.');
    }
    hub_test_pack_job_write($sourcePath, hub_test_pack_job_wav());
    $sourceArtifactId = hub_register_task_artifact($db, $sourceTaskId, 'source.wav', $sourcePath, 'audio/wav');
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, ['include_subtitles' => true], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
        'source_artifact_id' => $sourceArtifactId,
        'source_task_id' => $sourceTaskId,
        'callback_target_id' => $callbackTargetId,
    ]);
    hub_hold_task_source_artifact($db, $sourceArtifactId, $taskId);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = :lock_token WHERE id = :id")
        ->execute([':lock_token' => 'task-lock-' . $taskId, ':id' => $taskId]);
    $workspace = hub_task_result_dir($taskId) . '/workspace';
    if (!is_dir($workspace . '/output') && !mkdir($workspace . '/output', 0775, true) && !is_dir($workspace . '/output')) {
        throw new RuntimeException('Cannot create trusted Pack-job workspace fixture.');
    }

    $leaseToken = bin2hex(random_bytes(32));
    $runId = 'pack_job_' . bin2hex(random_bytes(8));
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, workspace, state, worker_id, lease_token, lease_expires_at, task_id, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :workspace, :state, :worker_id, :lease_token, :lease_expires_at, :task_id, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'whisper-asr',
        ':task' => 'transcribe',
        ':workspace' => $workspace,
        ':state' => 'running',
        ':worker_id' => 'test-worker',
        ':lease_token' => $leaseToken,
        ':lease_expires_at' => hub_runtime_lease_until(60),
        ':task_id' => $taskId,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    return [
        'member_id' => $memberId,
        'task_id' => $taskId,
        'source_artifact_id' => $sourceArtifactId,
        'workspace' => $workspace,
        'run' => [
            'id' => (int)$db->lastInsertId(),
            'run_id' => $runId,
            'worker_id' => 'test-worker',
            'lease_token' => $leaseToken,
        ],
    ];
}

function hub_test_pack_job_cleanup_asserted(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

function hub_test_pack_job_contract_fails(callable $fn): bool
{
    try {
        $fn();
    } catch (HubPackOutputContractInvalid) {
        return true;
    } catch (Throwable) {
        return false;
    }

    return false;
}

function hub_test_pack_job_with_env(string $key, string $value, callable $fn): void
{
    $previous = getenv($key);
    putenv($key . '=' . $value);
    try {
        $fn();
    } finally {
        putenv($previous === false ? $key : $key . '=' . $previous);
    }
}

hub_test('Pack job artifact validation recomputes trusted metadata and respects conditional outputs', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}\n");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());

        $validated = hub_validate_pack_job_artifacts(
            $workspace,
            ['include_subtitles' => true],
            hub_test_pack_job_contract(),
            'hub_test_pack_job_audio_probe'
        );
        $byType = array_column($validated, null, 'artifact_type');
        hub_test_assert(count($validated) === 3, 'required and conditionally enabled outputs must validate');
        hub_test_assert(($byType['transcript_json']['sha256'] ?? '') === hash_file('sha256', $workspace . '/output/transcript.json'), 'Hub must recompute output SHA-256');
        hub_test_assert((int)($byType['transcript_json']['size_bytes'] ?? 0) === filesize($workspace . '/output/transcript.json'), 'Hub must recompute output size');
        hub_test_assert(($byType['audio']['metadata']['duration_seconds'] ?? null) === 1.25 && ($byType['audio']['metadata']['sample_rate'] ?? null) === 48000 && ($byType['audio']['metadata']['channels'] ?? null) === 2, 'audio probe data must be recorded as Hub metadata');

        unlink($workspace . '/output/subtitle.srt');
        $withoutSubtitle = hub_validate_pack_job_artifacts(
            $workspace,
            ['include_subtitles' => false],
            hub_test_pack_job_contract(),
            'hub_test_pack_job_audio_probe'
        );
        hub_test_assert(count($withoutSubtitle) === 2, 'conditional output must be absent when its input flag is false');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job text artifacts allow an empty dataset only when declared', function (): void {
    $workspace = hub_test_pack_job_workspace();
    $contract = ['artifacts' => [[
        'type' => 'empty_dataset',
        'path' => 'dataset.jsonl',
        'mime_types' => ['application/x-empty', 'application/x-ndjson'],
        'max_bytes' => 128,
        'text' => ['max_bytes' => 128, 'allow_empty' => true],
    ]]];
    try {
        hub_test_pack_job_write($workspace . '/output/dataset.jsonl', '');
        $validated = hub_validate_pack_job_artifacts($workspace, [], $contract);
        hub_test_assert(count($validated) === 1 && (int)$validated[0]['size_bytes'] === 0, 'declared empty dataset must retain trusted zero-byte metadata');

        unset($contract['artifacts'][0]['text']['allow_empty']);
        hub_test_assert(
            hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, [], $contract)),
            'ordinary text artifacts must remain non-empty'
        );
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job image contracts accept only bounded PNG definitions', function (): void {
    $valid = hub_test_pack_job_image_contract();
    $normalized = hub_pack_job_contract_artifacts($valid);
    hub_test_assert(($normalized[0]['image'] ?? null) === $valid['artifacts'][0]['image'], 'bounded PNG image contracts must be preserved');

    foreach ([
        ['format' => 'jpeg', 'max_width' => 2, 'max_height' => 2, 'max_pixels' => 4],
        ['format' => 'png', 'max_width' => 2, 'max_height' => 2, 'max_pixels' => 5],
        ['format' => 'png', 'max_width' => 0, 'max_height' => 2, 'max_pixels' => 1],
        ['format' => 'png', 'max_width' => 2, 'max_height' => 2, 'max_pixels' => 4, 'extra' => true],
    ] as $image) {
        $invalid = $valid;
        $invalid['artifacts'][0]['image'] = $image;
        hub_test_assert(hub_test_pack_job_contract_fails(static fn (): array => hub_pack_job_contract_artifacts($invalid)), 'malformed or non-PNG image contracts must fail closed');
    }
    $invalid = $valid;
    $invalid['artifacts'][0]['mime_types'] = ['image/jpeg'];
    hub_test_assert(hub_test_pack_job_contract_fails(static fn (): array => hub_pack_job_contract_artifacts($invalid)), 'PNG image contracts must not allow non-PNG MIME types');
});

hub_test('Pack job image validation records Hub-derived PNG dimensions', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/screenshot.png', hub_test_pack_job_png(1, 1));
        $validated = hub_validate_pack_job_artifacts($workspace, [], hub_test_pack_job_image_contract());
        hub_test_assert(($validated[0]['metadata'] ?? null) === ['width' => 1, 'height' => 1, 'format' => 'png'], 'valid PNG metadata must be recomputed by the Hub');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job image validation rejects fake, oversized, and symlinked PNG outputs', function (): void {
    hub_test_require_symlink_fixture('Pack job symlink fixtures are unavailable on this Windows host.');
    $cases = [
        'fake' => static function (string $path): void {
            hub_test_pack_job_write($path, 'runner says this is a PNG');
        },
        'truncated' => static function (string $path): void {
            hub_test_pack_job_write($path, hub_test_pack_job_truncated_png());
        },
        'dimensions' => static function (string $path): void {
            hub_test_pack_job_write($path, hub_test_pack_job_png(3, 1));
        },
        'pixels' => static function (string $path): void {
            hub_test_pack_job_write($path, hub_test_pack_job_png(2, 2));
        },
        'symlink' => static function (string $path): string {
            $outside = tempnam(sys_get_temp_dir(), '3waaihub_png_');
            if ($outside === false) {
                throw new RuntimeException('Cannot create PNG symlink fixture.');
            }
            hub_test_pack_job_write($outside, hub_test_pack_job_png(1, 1));
            if (!symlink($outside, $path)) {
                throw new RuntimeException('Cannot create PNG symlink fixture.');
            }

            return $outside;
        },
    ];
    foreach ($cases as $name => $write) {
        $workspace = hub_test_pack_job_workspace();
        $outside = null;
        try {
            $path = $workspace . '/output/screenshot.png';
            $outside = $write($path);
            $contract = $name === 'pixels' ? hub_test_pack_job_image_contract(2, 2, 3) : hub_test_pack_job_image_contract();
            hub_test_assert(hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, [], $contract)), 'invalid ' . $name . ' PNG output must fail the contract');
        } finally {
            hub_test_pack_job_rm($workspace);
            if (is_string($outside) && is_file($outside)) {
                unlink($outside);
            }
        }
    }
});

hub_test('Pack job image outputs enforce the web capture crop all-present condition', function (): void {
    $pack = hub_get_pack('web-screenshot');
    $contract = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), 'capture');
    hub_test_assert(is_array($contract), 'web capture contract must be available for image validation');
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/screenshot.png', hub_test_pack_job_png(1, 1));
        hub_test_pack_job_write($workspace . '/output/capture_report.json', hub_test_pack_job_capture_report());
        hub_test_pack_job_write($workspace . '/output/crop.png', hub_test_pack_job_png(1, 1));
        hub_test_assert(hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, [], $contract['artifact_contract'])), 'crop output must be absent without every crop input');

        unlink($workspace . '/output/crop.png');
        hub_test_assert(count(hub_validate_pack_job_artifacts($workspace, [], $contract['artifact_contract'])) === 2, 'screenshot and report outputs must validate without crop inputs');

        $cropInput = ['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1, 'crop_height' => 1];
        hub_test_assert(hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, $cropInput, $contract['artifact_contract'])), 'crop output must be present with every crop input');

        hub_test_pack_job_write($workspace . '/output/crop.png', hub_test_pack_job_png(1, 1));
        hub_test_assert(count(hub_validate_pack_job_artifacts($workspace, $cropInput, $contract['artifact_contract'])) === 3, 'screenshot, crop, and report outputs must validate with every crop input');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job invalid PNG terminalizes through the fenced failed callback path', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'invalid-png', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    try {
        hub_test_pack_job_write($fixture['workspace'] . '/output/screenshot.png', 'runner says this is a PNG');
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $fixture['workspace'], [], hub_test_pack_job_image_contract(), hub_test_pack_job_cleanup_asserted());
        $task = hub_get_task($db, $fixture['task_id']);
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'invalid PNG must fail the success terminal request');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && $delivery === 'task.failed', 'invalid PNG must not publish success artifacts or a completed callback');
    } finally {
        hub_test_pack_job_rm($fixture['workspace']);
    }
});

hub_test('Pack job artifact validation rejects escape symlink nonregular extra and invalid content outputs', function (): void {
    hub_test_require_symlink_fixture('Pack job symlink fixtures are unavailable on this Windows host.');
    $cases = [
        'traversal' => static function (string $workspace, array &$contract): void {
            $contract['artifacts'][0]['path'] = '../escape.json';
        },
        'symlink' => static function (string $workspace, array &$contract): void {
            $outside = tempnam(sys_get_temp_dir(), '3waaihub_outside_');
            if ($outside === false) {
                throw new RuntimeException('Cannot create symlink fixture.');
            }
            unlink($workspace . '/output/transcript.json');
            if (!symlink($outside, $workspace . '/output/transcript.json')) {
                throw new RuntimeException('Cannot create symlink fixture.');
            }
        },
        'nonregular' => static function (string $workspace, array &$contract): void {
            unlink($workspace . '/output/transcript.json');
            mkdir($workspace . '/output/transcript.json');
        },
        'extra' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/unrecognized.bin', 'unexpected');
        },
        'json' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/transcript.json', '[]');
        },
        'text' => static function (string $workspace, array &$contract): void {
            hub_test_pack_job_write($workspace . '/output/subtitle.srt', "\xff");
        },
    ];
    foreach ($cases as $name => $mutate) {
        $workspace = hub_test_pack_job_workspace();
        try {
            hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
            hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
            hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
            $contract = hub_test_pack_job_contract();
            $mutate($workspace, $contract);
            hub_test_assert(
                hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe')),
                'invalid ' . $name . ' output must fail the contract'
            );
        } finally {
            hub_test_pack_job_rm($workspace);
        }
    }

    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        hub_test_assert(
            hub_test_pack_job_contract_fails(static fn (): array => hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => false], hub_test_pack_job_contract(), static fn (): array => [])),
            'audio output must fail closed when ffprobe data is unavailable'
        );
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job success terminal commit atomically registers validated outputs state holds and callback outbox', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-complete', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());

        $task = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        $artifacts = $db->prepare('SELECT path, artifact_type, mime_type, size_bytes, sha256, metadata_json FROM task_artifacts WHERE task_id = :task_id ORDER BY id');
        $artifacts->execute([':task_id' => $fixture['task_id']]);
        $rows = $artifacts->fetchAll();
        $delivery = $db->query('SELECT event_type, payload_json FROM task_callback_deliveries')->fetch();
        $hold = $db->prepare('SELECT released_at FROM task_artifact_holds WHERE source_artifact_id = :source AND downstream_task_id = :task');
        $hold->execute([':source' => $fixture['source_artifact_id'], ':task' => $fixture['task_id']]);
        hub_test_assert(($task['status'] ?? '') === 'success' && ($run['state'] ?? '') === 'succeeded', 'success terminal commit must complete task and owned run');
        hub_test_assert(count($rows) === 3 && ($rows[2]['sha256'] ?? '') === hash_file('sha256', $workspace . '/output/audio.wav'), 'success terminal commit must register only Hub-validated metadata');
        $resultArtifacts = (array)(($task['result'] ?? [])['artifacts'] ?? []);
        hub_test_assert(count($resultArtifacts) === 3 && ($resultArtifacts[2]['sha256'] ?? '') === ($rows[2]['sha256'] ?? '') && ($resultArtifacts[2]['size_bytes'] ?? null) === (int)$rows[2]['size_bytes'], 'task result must expose artifact integrity metadata for polling clients');
        hub_test_assert(hub_artifact_safe_path((string)($rows[2]['path'] ?? '')) === ($rows[2]['path'] ?? ''), 'success artifacts must remain directly downloadable from managed results storage');
        hub_test_assert((json_decode((string)($rows[2]['metadata_json'] ?? ''), true)['sample_rate'] ?? null) === 48000, 'audio metadata must be stored with registered artifact');
        hub_test_assert(!empty(($hold->fetch() ?: [])['released_at']), 'success terminal commit must release source hold in its transaction');
        hub_test_assert(($delivery['event_type'] ?? '') === 'task.completed' && count(json_decode((string)($delivery['payload_json'] ?? ''), true)['artifacts'] ?? []) === 3, 'callback must be outbox-only and see committed artifact registry');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job terminal fence mismatch rolls back registrations callbacks and task state', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $badRun = $fixture['run'];
        $badRun['lease_token'] = 'stale-fence';
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $badRun, $validated, hub_test_pack_job_cleanup_asserted())), 'stale success fence must fail');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'fence mismatch must not register partial artifacts');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'fence mismatch must roll back task and run terminal state');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'fence mismatch must not expose an outbox callback');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('CPU Pack terminal fence loss discards only its staged handoff', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    $unrelated = str_repeat('a', 32);
    try {
        if (is_dir($artifactRoot)) {
            hub_test_pack_job_rm($artifactRoot);
        }
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_pack_job_published_artifact_dir($fixture['task_id'], $unrelated);
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success(
            $db,
            $fixture['task_id'],
            $fixture['run'],
            $validated,
            hub_test_pack_job_cleanup_asserted(),
            null,
            null,
            static function () use ($db, $fixture): void {
                $db->prepare('UPDATE runtime_runs SET lease_token = :lease_token WHERE id = :id')
                    ->execute([':lease_token' => 'cpu-terminal-fence-lost', ':id' => $fixture['run']['id']]);
            }
        )), 'CPU terminal fence loss must reject the success commit');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'CPU fence loss must not publish artifacts or callbacks');
        hub_test_assert(is_dir($artifactRoot . '/' . $unrelated) && count(glob($artifactRoot . '/*') ?: []) === 1, 'CPU fence loss must remove only its generated handoff and preserve unrelated handoffs');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('CPU Pack success rejects an expired runtime lease after artifact handoff', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'cpu-expired-fence', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $outcome = hub_commit_pack_job_success(
            $db,
            $fixture['task_id'],
            $fixture['run'],
            $validated,
            hub_test_pack_job_cleanup_asserted(),
            null,
            static function () use ($db, $fixture): void {
                $db->prepare('UPDATE runtime_runs SET lease_expires_at = :lease_expires_at WHERE id = :id')
                    ->execute([':lease_expires_at' => '2000-01-01 00:00:00', ':id' => $fixture['run']['id']]);
            }
        );
        hub_test_assert(($outcome['ok'] ?? true) === false, 'an expired CPU lease after handoff must reject success publication');
        hub_test_assert(hub_reconcile_expired_pack_job_runs($db) === 1, 'the shared stale-run recovery must reclaim the expired CPU success fence');
        $task = hub_get_task($db, $fixture['task_id']) ?? [];
        $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn();
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed' && ($run['state'] ?? '') === 'failed' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && $delivery === 'task.failed', 'expired CPU validation or handoff without start evidence must never publish success artifacts or callbacks');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('CPU Pack terminal update rejects a lease that expires after its active fence', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'cpu-terminal-expiry', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')
        ->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    $publishedHandoffDir = null;
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_assert(hub_test_throws(static function () use ($db, $fixture, $validated, &$publishedHandoffDir): void {
            hub_commit_pack_job_success(
                $db,
                $fixture['task_id'],
                $fixture['run'],
                $validated,
                hub_test_pack_job_cleanup_asserted(),
                null,
                null,
                static function (array $publishedArtifacts) use ($db, $fixture, &$publishedHandoffDir): void {
                    $publishedHandoffDir = dirname((string)($publishedArtifacts[0]['path'] ?? ''));
                    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")
                        ->execute([':id' => $fixture['run']['id']]);
                }
            );
        }), 'a lease expiring between the active check and terminal update must reject success');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && is_string($publishedHandoffDir) && !is_dir($publishedHandoffDir), 'terminal lease loss must roll back success state and discard its generated staged handoff');
        hub_test_assert(hub_reconcile_expired_pack_job_runs($db) === 1, 'terminal lease loss must be handed to stale-run reconciliation');
        $task = hub_get_task($db, $fixture['task_id']) ?? [];
        $run = hub_runtime_fetch_run($db, (int)$fixture['run']['id']) ?? [];
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries WHERE task_id = ' . $fixture['task_id'])->fetchColumn();
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'cleanup_failed' && $delivery === 'task.failed', 'reconciliation must terminalize the expired CPU fence without publishing success');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job terminal rejects an unlinked runtime run without partial commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare('UPDATE runtime_runs SET task_id = NULL WHERE id = :id')->execute([':id' => $fixture['run']['id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted())), 'unlinked runtime run must fail Pack terminal fencing');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'unlinked run fence must roll back task and run states');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'unlinked run fence must not expose artifacts or callback');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job success terminal rejects missing runtime context without mutations', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], null, $validated, hub_test_pack_job_cleanup_asserted())), 'success terminal must require a runtime fence');
        hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'missing success fence must preserve task and run states');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'missing success fence must not expose artifacts or callbacks');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job failure terminal rejects missing runtime context without mutations', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], null, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted())), 'failure terminal must require a runtime fence');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'missing failure fence must preserve task and run states');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'missing failure fence must not expose a callback');
});

hub_test('Pack job terminal rejects a workspace outside its trusted runtime workspace', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $foreignWorkspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($foreignWorkspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($foreignWorkspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($foreignWorkspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $foreignWorkspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'foreign workspace must be rejected as output contract invalid');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'foreign workspace must not commit artifacts or success');
    } finally {
        hub_test_pack_job_rm($foreignWorkspace);
    }
});

hub_test('Pack job terminal rejects output replacement after validation before commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $replacement = $workspace . '/replacement.json';
        $target = $workspace . '/output/transcript.json';
        hub_test_pack_job_write($replacement, "{\"text\":\"hello\"}");
        lstat($target); // Populate PHP's stat cache before an external replacement.
        $output = [];
        $exitCode = 1;
        exec('rm ' . escapeshellarg($target) . ' && ln ' . escapeshellarg($replacement) . ' ' . escapeshellarg($target), $output, $exitCode);
        if ($exitCode !== 0) {
            throw new RuntimeException('Cannot replace output with hardlink fixture.');
        }
        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'failed', 'output replacement must terminalize as output_contract_invalid');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'output replacement must not register stale metadata');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job finalize reports snapshot revalidation failure instead of success', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-revalidation', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $probe = static function (string $path) use ($workspace): array {
            hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"changed after validation\"}");
            return hub_test_pack_job_audio_probe($path);
        };
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), $probe);
        $task = hub_get_task($db, $fixture['task_id']);
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'finalize must report the revalidation failure to its caller');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && $delivery === 'task.failed', 'revalidation failure must keep the failed terminal state and outbox');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'revalidation failure must not register artifacts');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job finalize rejects a contract with no active outputs', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-no-outputs', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $contract = [
        'artifacts' => [
            [
                'type' => 'optional_json',
                'path' => 'optional.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 1024,
                'required' => false,
                'json' => ['required_keys' => ['text']],
            ],
            [
                'type' => 'conditional_json',
                'path' => 'conditional.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 1024,
                'when' => ['input' => 'include_conditional', 'equals' => true],
                'json' => ['required_keys' => ['text']],
            ],
        ],
    ];

    $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $fixture['workspace'], ['include_conditional' => false], $contract, hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
    $task = hub_get_task($db, $fixture['task_id']);
    $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'an empty active contract must not report a successful finalize');
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'output_contract_invalid', 'an empty active contract must terminalize task and run as output-contract failure');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0 && $delivery === 'task.failed', 'an empty active contract must register no success artifacts or success callback');
});

hub_test('Pack job handoff keeps registered artifacts immutable after runner workspace mutation', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"before handoff\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        $published = hub_handoff_pack_job_artifacts($db, $fixture['task_id'], $fixture['run'], $validated);
        $publishedTranscript = array_values(array_filter($published, static fn (array $artifact): bool => ($artifact['name'] ?? '') === 'transcript.json'))[0] ?? null;
        hub_test_assert(is_array($publishedTranscript) && !str_starts_with((string)$publishedTranscript['path'], $workspace . '/'), 'handoff must publish outside the runner workspace');
        $publishedPath = (string)($publishedTranscript['path'] ?? '');
        $taskResultDir = hub_task_result_dir($fixture['task_id']);
        $artifactRoot = $taskResultDir . '/artifacts';
        $handoffScope = (string)($publishedTranscript['published_handoff_scope'] ?? '');
        $handoffId = (string)($publishedTranscript['published_handoff_id'] ?? '');
        $handoffDir = $artifactRoot . ($handoffScope === '' ? '' : '/' . $handoffScope) . '/' . $handoffId;
        if (PHP_OS_FAMILY !== 'Windows') {
            hub_test_assert((fileperms($taskResultDir) & 07777) === 02710 && (fileperms($artifactRoot) & 07777) === 02750 && (fileperms($handoffDir) & 07777) === 02750 && (fileperms($publishedPath) & 0777) === 0640, 'published artifacts must preserve web-service-group inheritance without granting any access to other users');
            $worker = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
            $publishedStat = lstat($publishedPath);
            if (function_exists('posix_geteuid') && posix_geteuid() === 0 && is_array($worker)) {
                foreach ([$taskResultDir, $artifactRoot, $handoffDir] as $directory) {
                    $directoryStat = lstat($directory);
                    hub_test_assert(is_array($directoryStat) && (int)$directoryStat['gid'] === (int)$worker['gid'], 'root-published artifact directories must be grouped for www-data');
                }
                hub_test_assert(is_array($publishedStat) && (int)$publishedStat['uid'] === (int)$worker['uid'], 'root-published artifacts must be owned by www-data');
                hub_test_assert(is_array($publishedStat) && (int)$publishedStat['gid'] === (int)$worker['gid'], 'root-published artifacts must be grouped for www-data');
            }
        }

        rename($workspace . '/output/transcript.json', $workspace . '/output/transcript.runner-old.json');
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"runner changed it after handoff\"}");
        $outcome = hub_commit_published_pack_job_success($db, $fixture['task_id'], $fixture['run'], $published, hub_test_pack_job_cleanup_asserted());
        $row = $db->query("SELECT path, sha256 FROM task_artifacts WHERE task_id = " . (int)$fixture['task_id'] . " AND name = 'transcript.json'")->fetch();
        hub_test_assert(($outcome['ok'] ?? false) === true, 'published artifacts must remain committable after runner workspace mutation');
        hub_test_assert(($row['path'] ?? '') === ($publishedTranscript['path'] ?? '') && ($row['sha256'] ?? '') === hash_file('sha256', (string)($row['path'] ?? '')) && ($row['sha256'] ?? '') !== hash_file('sha256', $workspace . '/output/transcript.json'), 'registered SHA-256 must describe the final Hub-owned downloadable copy, not the mutated runner output');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job handoff failure terminalizes without a partial artifact registry', function (): void {
    hub_test_require_symlink_fixture('Pack job symlink fixtures are unavailable on this Windows host.');
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    try {
        hub_test_pack_job_rm($artifactRoot);
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        if (!symlink($workspace, $artifactRoot)) {
            throw new RuntimeException('Cannot create unsafe artifact-root fixture.');
        }
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'an unsafe publication directory must fail closed');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'output_contract_invalid', 'handoff setup failure must terminalize through the fenced failure path');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'handoff setup failure must not register a partial artifact set');
    } finally {
        if (is_link($artifactRoot)) {
            hub_test_remove_symlink($artifactRoot);
        }
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job terminal rejects an extra output added after validation before commit', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        hub_test_pack_job_write($workspace . '/output/late-extra.bin', 'late runner output');
        hub_commit_pack_job_success($db, $fixture['task_id'], $fixture['run'], $validated, hub_test_pack_job_cleanup_asserted());
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid', 'post-validation extra output must terminalize as output_contract_invalid');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'post-validation extra output must not register any artifact');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job invalid output and cleanup failure terminalize as failed through the outbox', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-failed', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', 'not-json');
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'invalid output must report the fixed contract error');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'invalid output must fail without partial artifact registration');
        hub_test_assert($delivery === 'task.failed', 'invalid output must enqueue failed callback without network delivery');
    } finally {
        hub_test_pack_job_rm($workspace);
    }

    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'cleanup_failed', 'container cleanup failed');
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'cleanup failure must remain terminal failure');
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'cancelled', 'cancelled', 'cancelled')), 'cancelled terminal state must require explicit cleanup assertion');
});

hub_test('Pack job incomplete cleanup attestation fails the requested success atomically', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-cleanup', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET callback_target_id = :target_id WHERE id = :id')->execute([':target_id' => $targetId, ':id' => $fixture['task_id']]);

    $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], '/not-used', ['include_subtitles' => true], hub_test_pack_job_contract(), ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => false], 'hub_test_pack_job_audio_probe');
    $task = hub_get_task($db, $fixture['task_id']);
    $run = $db->query('SELECT state, error_code FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'cleanup_failed', 'incomplete cleanup must reject requested success as cleanup_failed');
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed' && ($run['state'] ?? '') === 'failed' && ($run['error_code'] ?? '') === 'cleanup_failed', 'incomplete cleanup must terminalize task and fenced run as failed');
    hub_test_assert($delivery === 'task.failed' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'cleanup failure must use only the failed outbox without registering outputs');
});

hub_test('Pack job missing required output fails the contract without success registration', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    try {
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'missing required output must use the fixed output contract failure');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'missing required output must not register artifacts or success');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job output size cap rejects before parsing and terminalizes without artifacts', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $workspace = $fixture['workspace'];
    $contract = hub_test_pack_job_contract();
    $contract['artifacts'][0]['max_bytes'] = 16;
    $contract['artifacts'][1]['max_bytes'] = 128;
    $contract['artifacts'][2]['max_bytes'] = 1024;
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"this is larger than sixteen bytes\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        $reason = '';
        try {
            hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_size_invalid', 'oversized output must be rejected on its size before JSON parsing');

        $outcome = hub_finalize_pack_job_success($db, $fixture['task_id'], $fixture['run'], $workspace, ['include_subtitles' => true], $contract, hub_test_pack_job_cleanup_asserted(), 'hub_test_pack_job_audio_probe');
        $task = hub_get_task($db, $fixture['task_id']);
        hub_test_assert(($outcome['ok'] ?? true) === false && ($outcome['error_code'] ?? '') === 'output_contract_invalid', 'oversized output must report the fixed contract failure');
        hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'output_contract_invalid' && (int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $fixture['task_id'])->fetchColumn() === 0, 'oversized output must not partially register terminal artifacts');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job streamed SHA-256 rejects bytes that exceed its cap', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3waaihub_pack_hash_');
    if ($path === false) {
        throw new RuntimeException('Cannot create Pack-job hash fixture.');
    }
    try {
        hub_test_pack_job_write($path, str_repeat('x', 17));
        $reason = '';
        try {
            hub_pack_job_sha256_file($path, 16);
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_size_invalid', 'streamed hashing must stop once bytes exceed the previously accepted artifact cap');
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

hub_test('Pack job traversal rejects unexpected empty directories without collecting the tree', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        hub_test_pack_job_write($workspace . '/output/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/audio.wav', hub_test_pack_job_wav());
        mkdir($workspace . '/output/unexpected-empty');
        $reason = '';
        try {
            hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
        } catch (HubPackOutputContractInvalid $e) {
            $reason = $e->getMessage();
        }
        hub_test_assert($reason === 'artifact_set_invalid', 'an unexpected empty directory must be rejected during traversal');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal entry cap stops before building a large output map', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'one/transcript.json';
        $contract['artifacts'][1]['path'] = 'two/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'three/audio.wav';
        foreach (['one', 'two', 'three'] as $dir) {
            mkdir($workspace . '/output/' . $dir);
        }
        hub_test_pack_job_write($workspace . '/output/one/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/two/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/three/audio.wav', hub_test_pack_job_wav());
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_ENTRIES', '4', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_entry_limit', 'entry cap must stop traversal before all expected paths can be collected');
        });
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal enforces configured depth and aggregate-size caps', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'a/b/c/transcript.json';
        $contract['artifacts'][1]['path'] = 'a/b/c/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'a/b/c/audio.wav';
        mkdir($workspace . '/output/a/b/c', 0775, true);
        hub_test_pack_job_write($workspace . '/output/a/b/c/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/a/b/c/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/a/b/c/audio.wav', hub_test_pack_job_wav());
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_DEPTH', '2', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_depth_limit', 'depth cap must reject a nested runner tree before parsing outputs');
        });
        hub_test_pack_job_with_env('AIHUB_PACK_OUTPUT_HARD_MAX_TOTAL_BYTES', '20', static function () use ($workspace, $contract): void {
            $reason = '';
            try {
                hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
            } catch (HubPackOutputContractInvalid $e) {
                $reason = $e->getMessage();
            }
            hub_test_assert($reason === 'artifact_total_size_invalid', 'aggregate-size cap must reject before artifact hashing');
        });
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job traversal permits declared nested artifact parents', function (): void {
    $workspace = hub_test_pack_job_workspace();
    try {
        $contract = hub_test_pack_job_contract();
        $contract['artifacts'][0]['path'] = 'results/transcript.json';
        $contract['artifacts'][1]['path'] = 'results/subtitles/subtitle.srt';
        $contract['artifacts'][2]['path'] = 'results/audio/audio.wav';
        mkdir($workspace . '/output/results/subtitles', 0775, true);
        mkdir($workspace . '/output/results/audio', 0775, true);
        hub_test_pack_job_write($workspace . '/output/results/transcript.json', "{\"text\":\"hello\"}");
        hub_test_pack_job_write($workspace . '/output/results/subtitles/subtitle.srt', "subtitle\n");
        hub_test_pack_job_write($workspace . '/output/results/audio/audio.wav', hub_test_pack_job_wav());
        $validated = hub_validate_pack_job_artifacts($workspace, ['include_subtitles' => true], $contract, 'hub_test_pack_job_audio_probe');
        hub_test_assert(count($validated) === 3, 'declared nested artifact parent directories must remain valid');
    } finally {
        hub_test_pack_job_rm($workspace);
    }
});

hub_test('Pack job failed terminalization requires cleanup attestation before preserving its error', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'runner failed');
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'unattested ordinary failure must normalize to cleanup_failed');

    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted());
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'runtime_exit_nonzero', 'attested ordinary failure must preserve its error code');
});

hub_test('Pack job timeout fencing lets cancellation win and cancellation records its timestamp', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $past = date('Y-m-d H:i:s', time() - 60);
    $db->prepare('UPDATE runtime_runs SET timeout_at = :timeout_at, cancel_requested_at = :cancel_requested_at WHERE id = :id')->execute([
        ':timeout_at' => $past,
        ':cancel_requested_at' => $past,
        ':id' => $fixture['run']['id'],
    ]);
    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'timed_out', 'timed_out', 'timed out', hub_test_pack_job_cleanup_asserted())), 'timeout must not win when cancellation was requested');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetchColumn() === 'running', 'cancel-timeout fence race must roll back terminal states');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $fixture['run'], 'cancelled', 'cancelled', 'cancelled', hub_test_pack_job_cleanup_asserted());
    $run = $db->query('SELECT state, cancelled_at FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    hub_test_assert(($run['state'] ?? '') === 'cancelled' && !empty($run['cancelled_at']), 'cancelled Pack run must record canonical cancelled_at');
});

hub_test('Queued Pack job cancellation uses one terminal outbox transaction', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $targetId = hub_register_callback_target($db, $fixture['member_id'], 'pack-queued-cancel', 'https://8.8.8.8/callback');
    $db->prepare('UPDATE tasks SET status = :status, lock_token = NULL, callback_target_id = :target_id WHERE id = :id')->execute([
        ':status' => 'queued',
        ':target_id' => $targetId,
        ':id' => $fixture['task_id'],
    ]);
    $db->prepare('DELETE FROM runtime_runs WHERE id = :id')->execute([':id' => $fixture['run']['id']]);

    hub_test_assert(hub_cancel_task($db, $fixture['task_id']), 'queued Pack job must cancel through its terminal helper');
    hub_test_assert(!hub_cancel_task($db, $fixture['task_id']), 'terminal Pack job cancellation must not enqueue a duplicate callback');
    $task = hub_get_task($db, $fixture['task_id']);
    $hold = $db->prepare('SELECT released_at FROM task_artifact_holds WHERE source_artifact_id = :source AND downstream_task_id = :task');
    $hold->execute([':source' => $fixture['source_artifact_id'], ':task' => $fixture['task_id']]);
    $delivery = $db->query('SELECT event_type FROM task_callback_deliveries')->fetchColumn();
    hub_test_assert(($task['status'] ?? '') === 'cancelled' && !empty(($hold->fetch() ?: [])['released_at']), 'queued Pack cancellation must atomically terminalize and release its source hold');
    hub_test_assert($delivery === 'task.failed' && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 1, 'queued Pack cancellation must create exactly one failed outbox callback');
});

hub_test('Waiting GPU Pack job cancellation terminalizes its idle runtime run', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE tasks SET status = 'waiting_gpu', lock_token = NULL, waiting_reason = 'insufficient_vram', next_attempt_at = :next_attempt WHERE id = :id")
        ->execute([':next_attempt' => hub_now(), ':id' => $fixture['task_id']]);
    $db->prepare("UPDATE runtime_runs SET state = 'waiting_gpu', container_id = NULL, lease_expires_at = NULL WHERE id = :id")
        ->execute([':id' => $fixture['run']['id']]);

    hub_test_assert(hub_cancel_task($db, $fixture['task_id']), 'waiting GPU Pack job must be cancellable before it starts a runner');
    $task = hub_get_task($db, $fixture['task_id']);
    $run = $db->query('SELECT state, error_code, cancelled_at FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    hub_test_assert(($task['status'] ?? '') === 'cancelled' && ($task['waiting_reason'] ?? null) === null && ($task['next_attempt_at'] ?? null) === null, 'waiting GPU cancellation must clear retry state');
    hub_test_assert(($run['state'] ?? '') === 'cancelled' && ($run['error_code'] ?? '') === 'cancelled' && !empty($run['cancelled_at']), 'waiting GPU cancellation must terminalize its idle runtime run');
});
