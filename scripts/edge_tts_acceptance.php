<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

const HUB_EDGE_TTS_ACCEPTANCE_POLL_TIMEOUT_SECONDS = 180;
const HUB_EDGE_TTS_ACCEPTANCE_POLL_INTERVAL_MICROSECONDS = 250000;
const HUB_EDGE_TTS_ACCEPTANCE_JSON_MAX_BYTES = 1048576;
const HUB_EDGE_TTS_ACCEPTANCE_DEMO_MAX_BYTES = 16777216;

final class HubEdgeTtsAcceptanceFailure extends RuntimeException
{
}

function hub_edge_tts_acceptance_fail(string $code): never
{
    throw new HubEdgeTtsAcceptanceFailure($code);
}

function hub_edge_tts_acceptance_config(?array $environment = null): array
{
    $read = static function (string $key) use ($environment): string {
        return trim((string)($environment === null ? (getenv($key) ?: '') : ($environment[$key] ?? '')));
    };
    $baseUrl = $read('AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL');
    $token = $read('AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN');
    $parts = $baseUrl === '' ? false : parse_url($baseUrl);
    if ($token === '' || !is_array($parts)
        || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || trim((string)($parts['host'] ?? '')) === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || !is_string($parts['path'] ?? null)
        || (!str_ends_with($parts['path'], '/api.php') && !str_ends_with($parts['path'], '/cluster_api.php'))
    ) {
        hub_edge_tts_acceptance_fail('edge_tts_acceptance_config_invalid');
    }

    return ['base_url' => $baseUrl, 'token' => $token, 'token_hash' => hub_hash_api_token($token)];
}

function hub_edge_tts_acceptance_main(
    array $argv,
    ?PDO $db = null,
    ?callable $http = null,
    ?callable $command = null,
    ?array $environment = null,
): int {
    $started = microtime(true);
    $service = null;
    try {
        $db ??= hub_db();
        hub_migrate($db);
        $service = hub_benchmark_service($db, 'edge-tts', null);
        if (count($argv) !== 1) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_config_invalid');
        }
        $config = hub_edge_tts_acceptance_config($environment);
        $http ??= 'hub_edge_tts_acceptance_http';
        $command ??= static fn (array $args, int $timeout): array => hub_run_command($args, $timeout);
        $result = hub_edge_tts_acceptance_run($db, $config, $http, $command);
        $result['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
        if (!is_array($service)) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
        }
        hub_save_benchmark_run($db, 'edge_tts_async_complete', (int)$service['id'], 'edge_tts', 'pass', (int)$result['elapsed_ms'], $result, null);
        $output = ['ok' => true, 'result' => $result];
        $exit = 0;
    } catch (Throwable $error) {
        $code = $error instanceof HubEdgeTtsAcceptanceFailure
            ? $error->getMessage()
            : 'edge_tts_acceptance_task_failed';
        if (!in_array($code, [
            'edge_tts_acceptance_config_invalid',
            'edge_tts_acceptance_list_demo_failed',
            'edge_tts_acceptance_submission_failed',
            'edge_tts_acceptance_task_failed',
            'edge_tts_acceptance_artifact_invalid',
        ], true)) {
            $code = 'edge_tts_acceptance_task_failed';
        }
        if ($db instanceof PDO && is_array($service)) {
            hub_save_benchmark_run($db, 'edge_tts_async_complete', (int)$service['id'], 'edge_tts', 'fail', (int)round((microtime(true) - $started) * 1000), ['ok' => false], $code);
        }
        $output = ['ok' => false, 'error' => $code];
        $exit = 1;
    }

    echo json_encode($output, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return $exit;
}

function hub_edge_tts_acceptance_run(PDO $db, array $config, callable $http, callable $command): array
{
    $temporary = [];
    try {
        try {
            $list = hub_edge_tts_acceptance_json($config, $http, 'edge_tts');
            $voice = $list['voices'][0] ?? null;
            if (($list['ok'] ?? false) !== true || !is_array($voice)) {
                hub_edge_tts_acceptance_fail('edge_tts_acceptance_list_demo_failed');
            }
            $voiceId = (string)($voice['id'] ?? '');
            $demoUrl = hub_edge_tts_acceptance_demo_url($config['base_url'], $voiceId, $voice['demo_url'] ?? null);
            $demo = hub_edge_tts_acceptance_request($config, $http, 'GET', $demoUrl, '', HUB_EDGE_TTS_ACCEPTANCE_DEMO_MAX_BYTES);
            if (!hub_edge_tts_acceptance_http_ok($demo) || !hub_edge_tts_acceptance_mime_matches($demo['headers'] ?? [], ['audio/mpeg']) || !is_string($demo['body'] ?? null) || $demo['body'] === '') {
                hub_edge_tts_acceptance_fail('edge_tts_acceptance_list_demo_failed');
            }
            $demoPath = hub_edge_tts_acceptance_temp_file($temporary);
            if (file_put_contents($demoPath, $demo['body']) === false || !hub_edge_tts_acceptance_ffprobe($demoPath, $command)) {
                hub_edge_tts_acceptance_fail('edge_tts_acceptance_list_demo_failed');
            }
        } catch (HubEdgeTtsAcceptanceFailure $error) {
            throw $error;
        } catch (Throwable) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_list_demo_failed');
        }

        try {
            $submitted = hub_edge_tts_acceptance_json($config, $http, 'edge_tts', 'POST', [
                'text' => 'This is a short Edge TTS acceptance check.',
                'voice' => $voiceId,
                'rate' => '+0%',
                'volume' => '+0%',
                'pitch' => '+0Hz',
                'include_subtitles' => '1',
            ]);
            $taskId = (int)($submitted['task_id'] ?? 0);
            if (($submitted['ok'] ?? false) !== true || $taskId < 1) {
                hub_edge_tts_acceptance_fail('edge_tts_acceptance_submission_failed');
            }
        } catch (HubEdgeTtsAcceptanceFailure $error) {
            throw $error;
        } catch (Throwable) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_submission_failed');
        }

        try {
            hub_edge_tts_acceptance_poll($config, $http, $taskId);
            $taskResult = hub_edge_tts_acceptance_json($config, $http, 'task_result', 'GET', ['task_id' => (string)$taskId]);
            if (($taskResult['ok'] ?? false) !== true || (int)($taskResult['task_id'] ?? 0) !== $taskId || !is_array($taskResult['result']['artifacts'] ?? null)) {
                hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
            }
        } catch (HubEdgeTtsAcceptanceFailure $error) {
            throw $error;
        } catch (Throwable) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
        }

        try {
            $artifacts = hub_edge_tts_acceptance_verify_artifacts($config, $http, $command, $taskId, $taskResult['result']['artifacts'], $temporary);
            foreach ($artifacts as $artifact) {
                $ack = hub_edge_tts_acceptance_json($config, $http, 'task_artifacts_ack', 'POST', [
                    'task_id' => (string)$taskId,
                    'artifact_id' => (string)$artifact['id'],
                ]);
                if (($ack['ok'] ?? false) !== true) {
                    hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
                }
            }
        } catch (HubEdgeTtsAcceptanceFailure $error) {
            throw $error;
        } catch (Throwable) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }

        try {
            $runtime = hub_edge_tts_acceptance_local_runtime($db, $taskId, $config);
        } catch (Throwable) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
        }

        return [
            'demo_verified' => true,
            'task_completed' => true,
            'artifact_count' => count($artifacts),
            'artifact_mime_types' => array_column($artifacts, 'mime_type', 'type'),
            'artifact_byte_lengths' => array_column($artifacts, 'size_bytes', 'type'),
        ] + $runtime;
    } finally {
        foreach ($temporary as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

function hub_edge_tts_acceptance_demo_url(string $baseUrl, string $voiceId, mixed $demoUrl): string
{
    if (preg_match('/^[A-Za-z0-9-]{1,128}$/', $voiceId) !== 1 || !is_string($demoUrl)
        || preg_match('/^\?mode=edge_tts&voice=([A-Za-z0-9%._-]+)$/', $demoUrl, $matches) !== 1
        || rawurldecode($matches[1]) !== $voiceId
    ) {
        hub_edge_tts_acceptance_fail('edge_tts_acceptance_list_demo_failed');
    }

    return $baseUrl . $demoUrl;
}

function hub_edge_tts_acceptance_poll(array $config, callable $http, int $taskId): void
{
    $deadline = microtime(true) + HUB_EDGE_TTS_ACCEPTANCE_POLL_TIMEOUT_SECONDS;
    do {
        $status = hub_edge_tts_acceptance_json($config, $http, 'task_status', 'GET', ['task_id' => (string)$taskId]);
        $state = strtolower(trim((string)($status['status'] ?? '')));
        if (in_array($state, ['success', 'completed'], true)) {
            return;
        }
        if (in_array($state, ['failed', 'cancelled', 'timed_out'], true)) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
        }
        usleep(HUB_EDGE_TTS_ACCEPTANCE_POLL_INTERVAL_MICROSECONDS);
    } while (microtime(true) < $deadline);

    hub_edge_tts_acceptance_fail('edge_tts_acceptance_task_failed');
}

function hub_edge_tts_acceptance_verify_artifacts(array $config, callable $http, callable $command, int $taskId, array $declared, array &$temporary): array
{
    $expected = [
        'generated_audio' => ['mime_types' => ['audio/mpeg'], 'max_bytes' => 16777216],
        'synthesis_metadata' => ['mime_types' => ['application/json'], 'max_bytes' => 65536],
        'subtitle_vtt' => ['mime_types' => ['text/plain', 'text/vtt'], 'max_bytes' => 524288],
        'subtitle_srt' => ['mime_types' => ['text/plain', 'application/x-subrip', 'text/x-subrip', 'text/srt'], 'max_bytes' => 524288],
        'speech_timeline' => ['mime_types' => ['application/json'], 'max_bytes' => 524288],
    ];
    $byType = [];
    foreach ($declared as $artifact) {
        $type = is_array($artifact) ? (string)($artifact['type'] ?? '') : '';
        if (!isset($expected[$type]) || isset($byType[$type])) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $byType[$type] = $artifact;
    }
    if (count($byType) !== count($expected) || array_diff_key($expected, $byType) !== []) {
        hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
    }

    $verified = [];
    foreach ($expected as $type => $definition) {
        $artifact = $byType[$type];
        $artifactId = (int)($artifact['id'] ?? $artifact['artifact_id'] ?? 0);
        $mime = strtolower(trim((string)($artifact['mime_type'] ?? '')));
        if ($artifactId < 1 || !in_array($mime, $definition['mime_types'], true)) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $response = hub_edge_tts_acceptance_request($config, $http, 'GET', hub_edge_tts_acceptance_url($config['base_url'], 'artifact', ['artifact_id' => (string)$artifactId]), '', (int)$definition['max_bytes']);
        if (!hub_edge_tts_acceptance_http_ok($response) || !is_string($response['body'] ?? null)
            || !hub_edge_tts_acceptance_mime_matches($response['headers'] ?? [], $definition['mime_types'])) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $path = hub_edge_tts_acceptance_temp_file($temporary);
        if (file_put_contents($path, $response['body']) === false) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $bytes = filesize($path);
        $hash = hash_file('sha256', $path);
        if ($bytes === false || $hash === false
            || (array_key_exists('size_bytes', $artifact) && (int)$artifact['size_bytes'] !== $bytes)
            || (array_key_exists('sha256', $artifact) && (!is_string($artifact['sha256']) || !hash_equals($artifact['sha256'], $hash)))) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $contents = (string)file_get_contents($path);
        if (($type === 'generated_audio' && !hub_edge_tts_acceptance_ffprobe($path, $command))
            || ($type === 'synthesis_metadata' && !hub_edge_tts_acceptance_metadata_valid($contents))
            || ($type === 'subtitle_vtt' && !hub_edge_tts_acceptance_subtitle_valid($contents, 'vtt'))
            || ($type === 'subtitle_srt' && !hub_edge_tts_acceptance_subtitle_valid($contents, 'srt'))
            || ($type === 'speech_timeline' && !hub_edge_tts_acceptance_timeline_valid($contents))) {
            hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
        }
        $verified[] = ['id' => $artifactId, 'type' => $type, 'mime_type' => $mime, 'size_bytes' => $bytes];
    }

    return $verified;
}

function hub_edge_tts_acceptance_local_runtime(PDO $db, int $taskId, array $config): array
{
    $token = $db->prepare('SELECT id FROM api_tokens WHERE token_hash = :token_hash LIMIT 1');
    $token->execute([':token_hash' => (string)($config['token_hash'] ?? '')]);
    $tokenId = (int)$token->fetchColumn();
    $pack = hub_get_pack('edge-tts');
    $packVersion = (string)($pack['manifest']['version'] ?? '');
    if ($tokenId < 1 || $packVersion === '') {
        throw new RuntimeException('local_task_identity_missing');
    }
    $task = $db->prepare('SELECT task_type, queue_name, accelerator, status, owner_token_id, requested_mode, pack_id, pack_version, job FROM tasks WHERE id = :task_id');
    $task->execute([':task_id' => $taskId]);
    $row = $task->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)
        || ($row['task_type'] ?? '') !== 'pack_job'
        || ($row['queue_name'] ?? '') !== 'cpu'
        || ($row['accelerator'] ?? '') !== 'cpu'
        || ($row['status'] ?? '') !== 'success'
        || (int)($row['owner_token_id'] ?? 0) !== $tokenId
        || ($row['requested_mode'] ?? '') !== 'edge_tts'
        || ($row['pack_id'] ?? '') !== 'edge-tts'
        || ($row['pack_version'] ?? '') !== $packVersion
        || ($row['job'] ?? '') !== 'synthesize'
    ) {
        throw new RuntimeException('local_runtime_invalid');
    }
    $runs = $db->prepare('SELECT run_id, pack_id, task, pack_version, state, gpu_indexes, owned_gpu_pids_json FROM runtime_runs WHERE task_id = :task_id');
    $runs->execute([':task_id' => $taskId]);
    $runs = $runs->fetchAll(PDO::FETCH_ASSOC);
    if ($runs === []) {
        throw new RuntimeException('local_runtime_missing');
    }
    foreach ($runs as $run) {
        if (($run['pack_id'] ?? '') !== 'edge-tts' || ($run['task'] ?? '') !== 'synthesize'
            || ($run['pack_version'] ?? '') !== $packVersion || ($run['state'] ?? '') !== 'succeeded') {
            throw new RuntimeException('local_runtime_invalid');
        }
        foreach (['gpu_indexes', 'owned_gpu_pids_json'] as $field) {
            $value = trim((string)($run[$field] ?? ''));
            if ($value !== '' && $value !== '[]') {
                throw new RuntimeException('local_gpu_evidence_present');
            }
        }
    }
    $lease = $db->prepare(
        "SELECT 1 FROM runtime_resource_leases lease
         JOIN runtime_runs run ON run.run_id = lease.runtime_run_id
         WHERE run.task_id = :task_id AND lease.resource_key = 'gpu:0' LIMIT 1"
    );
    $lease->execute([':task_id' => $taskId]);
    if ($lease->fetchColumn() !== false) {
        throw new RuntimeException('local_gpu_lease_present');
    }

    return ['cpu_queue' => true, 'gpu_lease_absent' => true, 'owned_runtime_pids_absent' => true];
}

function hub_edge_tts_acceptance_metadata_valid(string $contents): bool
{
    $metadata = json_decode($contents, true);
    if (!is_array($metadata)) {
        return false;
    }
    foreach (['provider', 'client_version', 'voice', 'rate', 'volume', 'pitch', 'format', 'audio_bytes', 'elapsed_seconds', 'warnings'] as $key) {
        if (!array_key_exists($key, $metadata)) {
            return false;
        }
    }
    return is_string($metadata['voice']) && is_array($metadata['warnings']);
}

function hub_edge_tts_acceptance_timeline_valid(string $contents): bool
{
    $timeline = json_decode($contents, true);
    return is_array($timeline)
        && array_key_exists('version', $timeline)
        && ($timeline['unit'] ?? null) === 'ms'
        && (int)($timeline['duration_ms'] ?? 0) > 0
        && is_array($timeline['sentences'] ?? null) && $timeline['sentences'] !== []
        && is_array($timeline['words'] ?? null) && $timeline['words'] !== [];
}

function hub_edge_tts_acceptance_subtitle_valid(string $contents, string $format): bool
{
    $lines = preg_split('/\R/', trim($contents)) ?: [];
    if ($format === 'vtt' && ($lines[0] ?? '') !== 'WEBVTT') {
        return false;
    }
    $timings = 0;
    $content = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line === 'WEBVTT' || ctype_digit($line)) {
            continue;
        }
        if (preg_match('/^(\d{2}:\d{2}:\d{2}[.,]\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}[.,]\d{3})$/', $line, $matches) === 1) {
            if (hub_edge_tts_acceptance_timestamp($matches[2]) <= hub_edge_tts_acceptance_timestamp($matches[1])) {
                return false;
            }
            $timings++;
            continue;
        }
        $content = true;
    }
    return $timings > 0 && $content;
}

function hub_edge_tts_acceptance_timestamp(string $value): int
{
    $parts = preg_split('/[:.,]/', $value) ?: [];
    return count($parts) === 4 ? (((int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2]) * 1000 + (int)$parts[3]) : -1;
}

function hub_edge_tts_acceptance_ffprobe(string $path, callable $command): bool
{
    $result = $command(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'json', $path], 20);
    $probe = json_decode((string)($result['stdout'] ?? ''), true);
    return (int)($result['exit_code'] ?? 1) === 0 && is_array($probe) && (float)($probe['format']['duration'] ?? 0) > 0;
}

function hub_edge_tts_acceptance_json(array $config, callable $http, string $mode, string $method = 'GET', array $fields = []): array
{
    $url = hub_edge_tts_acceptance_url($config['base_url'], $mode, $method === 'GET' ? $fields : []);
    $response = hub_edge_tts_acceptance_request($config, $http, $method, $url, $method === 'POST' ? http_build_query($fields, '', '&', PHP_QUERY_RFC3986) : '', HUB_EDGE_TTS_ACCEPTANCE_JSON_MAX_BYTES);
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (!hub_edge_tts_acceptance_http_ok($response) || !is_array($payload)) {
        throw new RuntimeException('public_api_failed');
    }
    return $payload;
}

function hub_edge_tts_acceptance_url(string $baseUrl, string $mode, array $fields = []): string
{
    return $baseUrl . '?' . http_build_query(['mode' => $mode] + $fields, '', '&', PHP_QUERY_RFC3986);
}

function hub_edge_tts_acceptance_request(array $config, callable $http, string $method, string $url, string $body = '', int $maxBodyBytes = HUB_EDGE_TTS_ACCEPTANCE_JSON_MAX_BYTES): array
{
    if ($maxBodyBytes < 1) {
        throw new RuntimeException('public_api_failed');
    }
    $headers = ['Authorization' => 'Bearer ' . $config['token'], 'Accept' => 'application/json'];
    if ($method === 'POST') {
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
    }
    $response = $http(['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'follow_redirects' => false, 'max_body_bytes' => $maxBodyBytes]);
    if (!is_array($response) || !empty($response['too_large']) || (is_string($response['body'] ?? null) && strlen($response['body']) > $maxBodyBytes)) {
        throw new RuntimeException('public_api_failed');
    }
    return $response;
}

function hub_edge_tts_acceptance_http(array $request): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }
    $handle = curl_init((string)$request['url']);
    if ($handle === false) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }
    $headers = [];
    foreach (($request['headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    $responseHeaders = [];
    $responseBody = '';
    $tooLarge = false;
    $maxBodyBytes = max(1, (int)($request['max_body_bytes'] ?? HUB_EDGE_TTS_ACCEPTANCE_JSON_MAX_BYTES));
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => (string)$request['method'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$responseHeaders, &$tooLarge, $maxBodyBytes): int {
            $line = trim($header);
            if ($line !== '') {
                $responseHeaders[] = $line;
                if (str_starts_with(strtolower($line), 'content-length:') && (int)trim(substr($line, strlen('content-length:'))) > $maxBodyBytes) {
                    $tooLarge = true;
                    return 0;
                }
            }
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maxBodyBytes): int {
            if (strlen($chunk) > $maxBodyBytes - strlen($responseBody)) {
                $tooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ]);
    if (($request['method'] ?? 'GET') === 'POST') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, (string)($request['body'] ?? ''));
    }
    curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'too_large' => $tooLarge];
}

function hub_edge_tts_acceptance_http_ok(array $response): bool
{
    return (int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300;
}

function hub_edge_tts_acceptance_mime_matches(array $headers, array $allowed): bool
{
    $contentType = '';
    foreach ($headers as $name => $value) {
        if (is_string($name) && strtolower($name) === 'content-type') {
            $contentType = (string)$value;
        } elseif (is_string($value) && str_starts_with(strtolower($value), 'content-type:')) {
            $contentType = trim(substr($value, strlen('content-type:')));
        }
    }
    return in_array(strtolower(trim(strtok($contentType, ';'))), $allowed, true);
}

function hub_edge_tts_acceptance_temp_file(array &$temporary): string
{
    $path = tempnam(sys_get_temp_dir(), 'edge_tts_acceptance_');
    if ($path === false || !chmod($path, 0600)) {
        hub_edge_tts_acceptance_fail('edge_tts_acceptance_artifact_invalid');
    }
    $temporary[] = $path;
    return $path;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    hub_cli_only();
    exit(hub_edge_tts_acceptance_main($argv));
}
