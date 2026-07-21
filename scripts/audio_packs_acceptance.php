<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function hub_audio_acceptance_main(array $argv): int
{
    $options = getopt('', [
        'base-url:', 'token:', 'pack:', 'fixture::', 'callback-target::', 'voice-profile-id::',
        'text::', 'timeout::', 'json', 'help',
    ]);
    if (isset($options['help'])) {
        echo "Usage: php scripts/audio_packs_acceptance.php --base-url=<api.php URL> --token=<token> --pack=audio-cleanup|whisper-asr|tts-voxcpm2|all [--fixture=<audio>] [--callback-target=<alias>] [--voice-profile-id=<managed numeric id>] [--text=<text>] [--timeout=<seconds>] [--json]\n";
        return 0;
    }

    $config = ['json' => isset($options['json'])];
    try {
        $config = hub_audio_acceptance_config($options);
        $readiness = hub_audio_acceptance_readiness();
        $runs = [];
        $cleanup = null;
        if (in_array('audio-cleanup', $config['packs'], true)) {
            $cleanup = hub_audio_acceptance_run($config, 'audio-cleanup', []);
            $runs[] = $cleanup;
        }
        if (in_array('whisper-asr', $config['packs'], true)) {
            $input = $cleanup === null ? [] : ['source_artifact_id' => (string)hub_audio_acceptance_artifact_id($cleanup['result'], 'vocals_audio')];
            $runs[] = hub_audio_acceptance_run($config, 'whisper-asr', $input);
        }
        if (in_array('tts-voxcpm2', $config['packs'], true)) {
            $runs[] = hub_audio_acceptance_run($config, 'tts-voxcpm2', ['mode' => 'design']);
            if ($config['voice_profile_id'] !== null) {
                $runs[] = hub_audio_acceptance_run($config, 'tts-voxcpm2', [
                    'mode' => 'clone',
                    'voice_profile_id' => (string)$config['voice_profile_id'],
                ]);
            }
        }
        $output = ['ok' => true, 'readiness' => $readiness, 'runs' => $runs];
    } catch (Throwable $error) {
        $output = ['ok' => false, 'error' => $error->getMessage()];
    }

    if (!empty($config['json'] ?? false)) {
        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        if (!empty($output['ok'])) {
            foreach ($output['runs'] as $run) {
                echo '[PASS] ' . $run['label'] . ' task=' . $run['task_id'] . ' duration=' . $run['elapsed_seconds'] . 's peak_gpu_used=' . $run['peak_gpu_used_mib'] . "MiB\n";
            }
        } else {
            fwrite(STDERR, '[FAIL] ' . $output['error'] . PHP_EOL);
        }
    }

    return !empty($output['ok']) ? 0 : 1;
}

function hub_audio_acceptance_config(array $options): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP curl extension is required');
    }
    $baseUrl = rtrim(trim((string)($options['base-url'] ?? getenv('AIHUB_ACCEPTANCE_BASE_URL') ?: '')), '/');
    $token = trim((string)($options['token'] ?? getenv('AIHUB_ACCEPTANCE_TOKEN') ?: ''));
    $pack = trim((string)($options['pack'] ?? ''));
    if ($baseUrl === '' || $token === '' || !in_array($pack, ['audio-cleanup', 'whisper-asr', 'tts-voxcpm2', 'all'], true)) {
        throw new InvalidArgumentException('base-url, token, and a valid pack are required; use --help');
    }
    if (!str_ends_with($baseUrl, 'api.php')) {
        $baseUrl .= '/api.php';
    }
    $packs = $pack === 'all' ? ['audio-cleanup', 'whisper-asr', 'tts-voxcpm2'] : [$pack];
    $fixture = trim((string)($options['fixture'] ?? ''));
    if ((in_array('audio-cleanup', $packs, true) || in_array('whisper-asr', $packs, true)) && (!is_file($fixture) || !is_readable($fixture))) {
        throw new InvalidArgumentException('a readable --fixture audio file is required for cleanup or ASR acceptance');
    }
    $callbackTarget = trim((string)($options['callback-target'] ?? ''));
    if ($callbackTarget !== '' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $callbackTarget) !== 1) {
        throw new InvalidArgumentException('callback-target must be a registered alias');
    }
    $voiceProfileId = trim((string)($options['voice-profile-id'] ?? ''));
    if ($voiceProfileId !== '' && (!ctype_digit($voiceProfileId) || (int)$voiceProfileId < 1)) {
        throw new InvalidArgumentException('voice-profile-id must be a managed numeric ID');
    }

    return [
        'base_url' => $baseUrl,
        'token' => $token,
        'packs' => $packs,
        'fixture' => $fixture,
        'callback_target' => $callbackTarget,
        'voice_profile_id' => $voiceProfileId === '' ? null : (int)$voiceProfileId,
        'text' => trim((string)($options['text'] ?? '請檢查 RC Valve 間隙，並以清楚自然的語氣說明。')),
        'timeout' => max(30, min(14400, (int)($options['timeout'] ?? 7200))),
        'json' => isset($options['json']),
    ];
}

function hub_audio_acceptance_readiness(): array
{
    $gpu = hub_audio_acceptance_gpu_snapshot();
    if (($gpu['total_mib'] ?? 0) < 16000) {
        throw new RuntimeException('NVIDIA GPU readiness requires at least 16 GiB VRAM');
    }
    $docker = hub_audio_acceptance_command(['docker', 'info', '--format', '{{.ServerVersion}}']);
    if ($docker['exit_code'] !== 0 || trim($docker['stdout']) === '') {
        throw new RuntimeException('Docker readiness failed: ' . trim($docker['stderr']));
    }
    if (!is_executable('/usr/bin/ffprobe') && hub_audio_acceptance_command(['sh', '-lc', 'command -v ffprobe'])['exit_code'] !== 0) {
        throw new RuntimeException('ffprobe is required for audio artifact verification');
    }
    return ['gpu' => $gpu, 'docker_version' => trim($docker['stdout'])];
}

function hub_audio_acceptance_run(array $config, string $pack, array $override): array
{
    $mode = match ($pack) {
        'audio-cleanup' => 'audio_cleanup',
        'whisper-asr' => 'speech_transcribe',
        'tts-voxcpm2' => 'voice_generate',
    };
    $fields = match ($pack) {
        'audio-cleanup' => ['operation' => 'separate', 'demucs_model' => 'balanced'],
        'whisper-asr' => ['model' => 'large_v3', 'language' => 'auto', 'diarization' => '0', 'output_srt' => '1', 'output_vtt' => '1'],
        'tts-voxcpm2' => ['text' => $config['text'], 'model' => 'voxcpm2', 'waveform_preview' => '1'],
    };
    $fields = array_replace($fields, $override);
    if ($pack === 'tts-voxcpm2') {
        if (($fields['mode'] ?? '') === 'clone') {
            unset($fields['voice_prompt']);
        } else {
            $fields['mode'] = 'design';
            $fields['voice_prompt'] = '沉穩、清楚的台灣技師';
            $fields['control'] = 'speed=normal; emotion=neutral';
        }
    }
    if ($config['callback_target'] !== '') {
        $fields['callback_target'] = $config['callback_target'];
    }
    $sourceFile = empty($override['source_artifact_id']) && $pack !== 'tts-voxcpm2' ? $config['fixture'] : '';
    $started = microtime(true);
    $submitted = hub_audio_acceptance_submit($config, $mode, $fields, $sourceFile);
    $taskId = (int)($submitted['task_id'] ?? 0);
    if ($taskId < 1) {
        throw new RuntimeException($pack . ' submission did not return task_id');
    }
    $poll = hub_audio_acceptance_poll($config, $taskId);
    if (!in_array($poll['status'], ['success', 'completed'], true)) {
        throw new RuntimeException($pack . ' task ' . $taskId . ' ended as ' . $poll['status'] . ': ' . $poll['error_message']);
    }
    $result = hub_audio_acceptance_json_request($config, 'task_result', ['task_id' => (string)$taskId]);
    $artifacts = hub_audio_acceptance_result_artifacts($result);
    $required = match ($pack) {
        'audio-cleanup' => ['vocals_audio', 'background_audio', 'cleanup_report'],
        'whisper-asr' => ['transcript_json', 'subtitle_srt', 'subtitle_vtt', 'transcription_report'],
        'tts-voxcpm2' => ['generated_audio', 'synthesis_metadata', 'waveform_preview'],
    };
    $downloaded = hub_audio_acceptance_verify_artifacts($config, $taskId, $artifacts, $required);
    foreach ($downloaded as $artifact) {
        hub_audio_acceptance_json_request($config, 'task_artifacts_ack', [
            'task_id' => (string)$taskId,
            'artifact_id' => (string)$artifact['id'],
        ], 'POST');
    }
    return [
        'label' => $pack . (($fields['mode'] ?? '') === 'clone' ? ':clone' : ''),
        'task_id' => $taskId,
        'status' => $poll['status'],
        'elapsed_seconds' => round(microtime(true) - $started, 3),
        'peak_gpu_used_mib' => $poll['peak_gpu_used_mib'],
        'gpu_after' => hub_audio_acceptance_gpu_snapshot(),
        'artifacts' => $downloaded,
        'result' => $result,
    ];
}

function hub_audio_acceptance_submit(array $config, string $mode, array $fields, string $sourceFile): array
{
    if ($sourceFile !== '') {
        $fields['source'] = new CURLFile($sourceFile, 'audio/wav', basename($sourceFile));
    }
    return hub_audio_acceptance_json_request($config, $mode, $fields, 'POST');
}

function hub_audio_acceptance_poll(array $config, int $taskId): array
{
    $deadline = time() + $config['timeout'];
    $peakUsed = 0;
    do {
        $snapshot = hub_audio_acceptance_gpu_snapshot();
        $peakUsed = max($peakUsed, (int)($snapshot['used_mib'] ?? 0));
        $status = hub_audio_acceptance_json_request($config, 'task_status', ['task_id' => (string)$taskId]);
        $state = strtolower(trim((string)($status['status'] ?? '')));
        if (in_array($state, ['success', 'completed', 'failed', 'cancelled', 'timed_out'], true)) {
            return ['status' => $state, 'error_message' => (string)($status['error_message'] ?? ''), 'peak_gpu_used_mib' => $peakUsed];
        }
        sleep(5);
    } while (time() < $deadline);

    throw new RuntimeException('task ' . $taskId . ' acceptance poll timed out');
}

function hub_audio_acceptance_json_request(array $config, string $mode, array $fields = [], string $method = 'GET'): array
{
    $url = $config['base_url'] . '?mode=' . rawurlencode($mode);
    if ($method === 'GET' && $fields !== []) {
        $url .= '&' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $config['token']],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => min(180, $config['timeout']),
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $fields);
    }
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    $payload = json_decode((string)$body, true);
    if ($body === false || $status < 200 || $status >= 300 || !is_array($payload) || ($payload['ok'] ?? true) === false) {
        throw new RuntimeException($error !== '' ? $error : ('Hub ' . $mode . ' request failed: ' . (string)($payload['message'] ?? $payload['error'] ?? $status)));
    }
    return $payload;
}

function hub_audio_acceptance_result_artifacts(array $result): array
{
    foreach ([$result['result']['artifacts'] ?? null, $result['artifacts'] ?? null, $result['task']['artifacts'] ?? null] as $items) {
        if (is_array($items) && $items !== []) {
            return $items;
        }
    }
    throw new RuntimeException('task_result did not include artifacts');
}

function hub_audio_acceptance_artifact_id(array $result, string $type): int
{
    foreach (hub_audio_acceptance_result_artifacts($result) as $artifact) {
        if (is_array($artifact) && ($artifact['type'] ?? '') === $type && (int)($artifact['id'] ?? $artifact['artifact_id'] ?? 0) > 0) {
            return (int)($artifact['id'] ?? $artifact['artifact_id']);
        }
    }
    throw new RuntimeException('required artifact missing: ' . $type);
}

function hub_audio_acceptance_verify_artifacts(array $config, int $taskId, array $artifacts, array $required): array
{
    $byType = [];
    foreach ($artifacts as $artifact) {
        if (is_array($artifact) && isset($artifact['type'])) {
            $byType[(string)$artifact['type']] = $artifact;
        }
    }
    foreach ($required as $type) {
        if (!isset($byType[$type])) {
            throw new RuntimeException('required artifact missing: ' . $type);
        }
    }
    $dir = sys_get_temp_dir() . '/3waaihub_audio_acceptance_' . $taskId . '_' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create acceptance artifact directory');
    }
    $verified = [];
    foreach ($byType as $type => $artifact) {
        $artifactId = (int)($artifact['id'] ?? $artifact['artifact_id'] ?? 0);
        $sha256 = strtolower(trim((string)($artifact['sha256'] ?? '')));
        $size = (int)($artifact['size_bytes'] ?? 0);
        if ($artifactId < 1 || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || $size < 1) {
            throw new RuntimeException('artifact integrity metadata invalid: ' . $type);
        }
        $path = $dir . '/' . preg_replace('/[^a-z0-9_-]/', '_', $type) . '.artifact';
        hub_audio_acceptance_download_artifact($config, $artifactId, $path);
        if (filesize($path) !== $size || !hash_equals($sha256, (string)hash_file('sha256', $path))) {
            throw new RuntimeException('artifact integrity verification failed: ' . $type);
        }
        $mime = strtolower(trim((string)($artifact['mime_type'] ?? '')));
        if (str_starts_with($mime, 'audio/')) {
            $probe = hub_audio_acceptance_command(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
            if ($probe['exit_code'] !== 0 || (float)trim($probe['stdout']) <= 0) {
                throw new RuntimeException('audio artifact failed ffprobe: ' . $type);
            }
        }
        if ($mime === 'application/json' && !is_array(json_decode((string)file_get_contents($path), true))) {
            throw new RuntimeException('JSON artifact is invalid: ' . $type);
        }
        $verified[] = ['id' => $artifactId, 'type' => $type, 'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $sha256];
    }
    return $verified;
}

function hub_audio_acceptance_download_artifact(array $config, int $artifactId, string $path): void
{
    $handle = curl_init($config['base_url'] . '?mode=artifact&artifact_id=' . $artifactId);
    if ($handle === false) {
        throw new RuntimeException('curl_init failed');
    }
    $stream = fopen($path, 'wb');
    if ($stream === false) {
        curl_close($handle);
        throw new RuntimeException('cannot write artifact');
    }
    curl_setopt_array($handle, [
        CURLOPT_FILE => $stream,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $config['token']],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => min(180, $config['timeout']),
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $ok = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    fclose($stream);
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException($error !== '' ? $error : 'artifact download failed: HTTP ' . $status);
    }
}

function hub_audio_acceptance_gpu_snapshot(): array
{
    $result = hub_audio_acceptance_command(['nvidia-smi', '--query-gpu=name,memory.total,memory.free', '--format=csv,noheader,nounits']);
    if ($result['exit_code'] !== 0 || trim($result['stdout']) === '') {
        throw new RuntimeException('NVIDIA readiness failed: ' . trim($result['stderr']));
    }
    $parts = array_map('trim', explode(',', trim(strtok($result['stdout'], "\n"))));
    if (count($parts) !== 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
        throw new RuntimeException('NVIDIA readiness output is invalid');
    }
    return ['name' => $parts[0], 'total_mib' => (int)$parts[1], 'free_mib' => (int)$parts[2], 'used_mib' => (int)$parts[1] - (int)$parts[2]];
}

function hub_audio_acceptance_command(array $command): array
{
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $output = [];
    $exitCode = 0;
    exec($escaped . ' 2>&1', $output, $exitCode);
    return ['exit_code' => $exitCode, 'stdout' => implode("\n", $output), 'stderr' => implode("\n", $output)];
}

exit(hub_audio_acceptance_main($argv));
