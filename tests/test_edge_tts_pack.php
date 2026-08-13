<?php
declare(strict_types=1);

require_once HUB_ROOT . '/scripts/edge_tts_acceptance.php';

function hub_test_edge_tts_payload(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    hub_test_assert(is_array($payload), 'Edge TTS gateway response must be JSON');

    return $payload;
}

function hub_test_edge_tts_request(PDO $db, string $token, array $post = [], string $method = 'POST', array $query = [], ?string $requestUri = null): array
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.71';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $requestUri ?? '/3waAIHub/api.php?mode=edge_tts' . ($query === [] ? '' : '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_SERVER['HTTP_HOST'] = 'hub.test';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/api.php';
    $_SERVER['CONTENT_LENGTH'] = (string)strlen(http_build_query($post));
    $_POST = $post;
    $_GET = ['mode' => 'edge_tts'] + $query;
    $_FILES = [];

    return hub_gateway_dispatch($db, 'edge_tts');
}

function hub_test_edge_tts_isolate(callable $fn): void
{
    $server = $_SERVER;
    $get = $_GET;
    $post = $_POST;
    $files = $_FILES;
    try {
        $fn();
    } finally {
        $_SERVER = $server;
        $_GET = $get;
        $_POST = $post;
        $_FILES = $files;
    }
}

function hub_test_edge_tts_acceptance_env(): array
{
    return [
        'AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL' => 'https://hub.example/3waAIHub/api.php',
        'AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN' => 'edge-tts-unit-token-secret',
    ];
}

function hub_test_edge_tts_acceptance_cluster_env(): array
{
    return [
        'AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL' => 'https://hub.example/3waAIHub/cluster_api.php',
        'AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN' => 'edge-tts-unit-token-secret',
    ];
}

function hub_test_edge_tts_acceptance_artifacts(): array
{
    return [
        'generated_audio' => ['id' => 901, 'mime_type' => 'audio/mpeg', 'body' => "ID3\x04\x00\x00acceptance"],
        'synthesis_metadata' => ['id' => 902, 'mime_type' => 'application/json', 'body' => json_encode([
            'provider' => 'bounded', 'client_version' => '1', 'voice' => 'zh-TW-HsiaoChenNeural',
            'rate' => '+0%', 'volume' => '+0%', 'pitch' => '+0Hz', 'format' => 'mp3',
            'audio_bytes' => 16, 'elapsed_seconds' => 1, 'warnings' => [],
        ], JSON_THROW_ON_ERROR)],
        'subtitle_vtt' => ['id' => 903, 'mime_type' => 'text/vtt', 'body' => "WEBVTT\n\n00:00:00.000 --> 00:00:01.000\nAcceptance\n"],
        'subtitle_srt' => ['id' => 904, 'mime_type' => 'application/x-subrip', 'body' => "1\n00:00:00,000 --> 00:00:01,000\nAcceptance\n"],
        'speech_timeline' => ['id' => 905, 'mime_type' => 'application/json', 'body' => json_encode([
            'version' => 1, 'unit' => 'ms', 'duration_ms' => 1000,
            'sentences' => [['start_ms' => 0, 'end_ms' => 1000]],
            'words' => [['start_ms' => 0, 'end_ms' => 1000]],
        ], JSON_THROW_ON_ERROR)],
    ];
}

function hub_test_edge_tts_acceptance_token(PDO $db): int
{
    $memberId = hub_create_api_member($db, 'Edge TTS Acceptance Test');
    $now = hub_now();
    $db->prepare(
        'INSERT INTO api_tokens (member_id, token_name, token_prefix, token_hash, enabled, created_at, updated_at)
         VALUES (:member_id, :token_name, :token_prefix, :token_hash, 1, :now, :now)'
    )->execute([
        ':member_id' => $memberId,
        ':token_name' => 'Edge TTS acceptance test',
        ':token_prefix' => 'edge-tts-unit-t',
        ':token_hash' => hub_hash_api_token('edge-tts-unit-token-secret'),
        ':now' => $now,
    ]);
    return (int)$db->lastInsertId();
}

function hub_test_edge_tts_acceptance_insert_local_runtime(PDO $db, int $ownerTokenId, int $taskId = 4242, array $identity = []): void
{
    $identity += [
        'requested_mode' => 'edge_tts',
        'pack_id' => 'edge-tts',
        'pack_version' => '0.3.0',
        'job' => 'synthesize',
    ];
    $now = hub_now();
    $db->prepare(
        'INSERT INTO tasks (id, task_type, queue_name, priority, input_json, status, progress, created_at, updated_at, owner_token_id, requested_mode, pack_id, pack_version, job, accelerator)
         VALUES (:id, :task_type, :queue_name, 0, :input_json, :status, 100, :now, :now, :owner_token_id, :mode, :pack_id, :pack_version, :job, :accelerator)'
    )->execute([
        ':id' => $taskId,
        ':task_type' => 'pack_job',
        ':queue_name' => 'cpu',
        ':input_json' => '{}',
        ':status' => 'success',
        ':now' => $now,
        ':owner_token_id' => $ownerTokenId,
        ':mode' => $identity['requested_mode'],
        ':pack_id' => $identity['pack_id'],
        ':pack_version' => $identity['pack_version'],
        ':job' => $identity['job'],
        ':accelerator' => 'cpu',
    ]);
    $db->prepare(
        'INSERT INTO runtime_runs (run_id, task_id, pack_id, task, pack_version, state, started_at, finished_at, created_at, gpu_indexes, owned_gpu_pids_json)
         VALUES (:run_id, :task_id, :pack_id, :task, :pack_version, :state, :now, :now, :now, NULL, :owned)'
    )->execute([
        ':run_id' => 'edge-tts-acceptance-runtime',
        ':task_id' => $taskId,
        ':pack_id' => $identity['pack_id'],
        ':task' => $identity['job'],
        ':pack_version' => $identity['pack_version'],
        ':state' => 'succeeded',
        ':now' => $now,
        ':owned' => '[]',
    ]);
}

function hub_test_edge_tts_acceptance_node_token(PDO $db): int
{
    $memberId = hub_create_api_member($db, 'Edge TTS acceptance Cluster node');
    $token = hub_create_api_token($db, $memberId, 'edge tts acceptance node', null, null);
    $tokenId = (int)$token['token_id'];
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '1');
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID', (string)$tokenId);

    return $tokenId;
}

function hub_test_edge_tts_acceptance_cluster_route(PDO $db, int $tokenId, int $remoteTaskId = 4242): string
{
    $now = hub_now();
    $stationKey = 'edge_tts_acceptance_self';
    $db->prepare(
        'INSERT INTO cluster_stations
            (station_key, display_name, public_base_url, priority, enabled, token_ciphertext, token_iv, token_tag, created_at, updated_at)
         VALUES
            (:station_key, :display_name, :public_base_url, 0, 1, :token_ciphertext, :token_iv, :token_tag, :now, :now)'
    )->execute([
        ':station_key' => $stationKey,
        ':display_name' => 'Edge TTS acceptance self',
        ':public_base_url' => 'https://hub.example/3waAIHub',
        ':token_ciphertext' => 'test',
        ':token_iv' => 'test',
        ':token_tag' => 'test',
        ':now' => $now,
    ]);
    $stationId = (int)$db->lastInsertId();
    $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . $tokenId)->fetchColumn();
    $routeId = 'route_' . str_repeat('b', 32);
    $db->prepare(
        "INSERT INTO cluster_routes
            (route_id, station_id, member_id, token_id, mode, remote_task_id, is_async, state, remote_status, created_at, updated_at, completed_at)
         VALUES
            (:route_id, :station_id, :member_id, :token_id, 'edge_tts', :remote_task_id, 1, 'succeeded', '200', :now, :now, :now)"
    )->execute([
        ':route_id' => $routeId,
        ':station_id' => $stationId,
        ':member_id' => $memberId,
        ':token_id' => $tokenId,
        ':remote_task_id' => (string)$remoteTaskId,
        ':now' => $now,
    ]);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', $stationKey);

    return $routeId;
}

function hub_test_edge_tts_acceptance_http(array &$requests, string $failure = ''): callable
{
    $artifacts = hub_test_edge_tts_acceptance_artifacts();
    $step = 0;
    return static function (array $request) use (&$requests, &$step, $failure, $artifacts): array {
        $requests[] = $request;
        $headers = $request['headers'] ?? [];
        hub_test_assert(($request['follow_redirects'] ?? true) === false
            && ($headers['Authorization'] ?? null) === 'Bearer edge-tts-unit-token-secret',
            'Edge TTS acceptance requests must use a bearer header and refuse redirects');

        $current = $step++;
        if ($current === 0) {
            return $failure === 'list_demo'
                ? ['status' => 500, 'headers' => [], 'body' => '{}']
                : ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => true, 'voices' => [[
                    'id' => 'zh-TW-HsiaoChenNeural',
                    'demo_url' => '?mode=edge_tts&voice=zh-TW-HsiaoChenNeural',
                ]]], JSON_THROW_ON_ERROR)];
        }
        if ($current === 1) {
            if ($failure === 'oversize_demo' && (int)($request['max_body_bytes'] ?? 0) > 0) {
                return ['status' => 200, 'headers' => ['Content-Type' => 'audio/mpeg'], 'body' => str_repeat('d', (int)$request['max_body_bytes'] + 1)];
            }
            return $failure === 'list_demo'
                ? ['status' => 200, 'headers' => ['Content-Type' => 'text/plain'], 'body' => 'not audio']
                : ['status' => 200, 'headers' => ['Content-Type' => 'audio/mpeg'], 'body' => "ID3\x04\x00\x00demo"];
        }
        if ($current === 2) {
            return $failure === 'submission'
                ? ['status' => 400, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}']
                : ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => true, 'task_id' => 4242, 'status' => 'queued'], JSON_THROW_ON_ERROR)];
        }
        if ($current === 3) {
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true, 'task_id' => 4242, 'status' => $failure === 'task' ? 'failed' : 'queued',
            ], JSON_THROW_ON_ERROR)];
        }
        if ($current === 4) {
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true, 'task_id' => 4242, 'status' => 'success',
            ], JSON_THROW_ON_ERROR)];
        }
        if ($current === 5) {
            $result = [];
            foreach ($artifacts as $type => $artifact) {
                $result[] = [
                    'id' => $artifact['id'],
                    'type' => $type,
                    'mime_type' => $artifact['mime_type'],
                    'size_bytes' => strlen((string)$artifact['body']),
                    'sha256' => hash('sha256', (string)$artifact['body']),
                ];
            }
            if ($failure === 'artifact') {
                $result[0]['mime_type'] = 'application/octet-stream';
            }
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true, 'task_id' => 4242, 'result' => ['artifacts' => $result],
            ], JSON_THROW_ON_ERROR)];
        }
        $artifactOffset = $current - 6;
        $artifactValues = array_values($artifacts);
        if ($artifactOffset >= 0 && $artifactOffset < count($artifactValues)) {
            $artifact = $artifactValues[$artifactOffset];
            if ($failure === 'oversize_artifact' && (int)($request['max_body_bytes'] ?? 0) > 0) {
                return ['status' => 200, 'headers' => ['Content-Type' => $artifact['mime_type']], 'body' => str_repeat('a', (int)$request['max_body_bytes'] + 1)];
            }
            return ['status' => 200, 'headers' => ['Content-Type' => $artifact['mime_type']], 'body' => $artifact['body']];
        }
        return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"ok":true}'];
    };
}

function hub_test_edge_tts_acceptance_cluster_http(array &$requests, string $routeId, bool $badLink = false): callable
{
    $artifacts = hub_test_edge_tts_acceptance_artifacts();
    $step = 0;
    return static function (array $request) use (&$requests, &$step, $routeId, $badLink, $artifacts): array {
        $requests[] = $request;
        $headers = $request['headers'] ?? [];
        hub_test_assert(($request['follow_redirects'] ?? true) === false
            && ($headers['Authorization'] ?? null) === 'Bearer edge-tts-unit-token-secret',
            'Cluster acceptance requests must use the supplied bearer token without redirects');
        $current = $step++;
        if ($current === 0) {
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => true, 'voices' => [[
                'id' => 'zh-TW-HsiaoChenNeural',
                'demo_url' => '?mode=edge_tts&voice=zh-TW-HsiaoChenNeural',
            ]]], JSON_THROW_ON_ERROR)];
        }
        if ($current === 1) {
            return ['status' => 200, 'headers' => ['Content-Type' => 'audio/mpeg'], 'body' => "ID3\x04\x00\x00demo"];
        }
        if ($current === 2) {
            $base = $badLink ? 'https://untrusted.example/cluster_api.php' : 'cluster_api.php';
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true,
                'task_id' => $routeId,
                'status_url' => $base . '?mode=cluster_task_status&task_id=' . $routeId,
                'result_url' => 'cluster_api.php?mode=cluster_task_result&task_id=' . $routeId,
                'artifact_url_template' => 'cluster_api.php?mode=cluster_artifact&task_id=' . $routeId . '&artifact_id={artifact_id}',
                'ack_url_template' => 'cluster_api.php?mode=cluster_task_artifacts_ack&task_id=' . $routeId . '&artifact_id={artifact_id}',
            ], JSON_THROW_ON_ERROR)];
        }
        if ($current === 3) {
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true, 'task_id' => $routeId, 'status' => 'success',
            ], JSON_THROW_ON_ERROR)];
        }
        if ($current === 4) {
            $result = [];
            foreach ($artifacts as $type => $artifact) {
                $result[] = [
                    'id' => $artifact['id'],
                    'type' => $type,
                    'mime_type' => $artifact['mime_type'],
                    'size_bytes' => strlen((string)$artifact['body']),
                    'sha256' => hash('sha256', (string)$artifact['body']),
                ];
            }
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode([
                'ok' => true, 'task_id' => $routeId, 'result' => ['artifacts' => $result],
            ], JSON_THROW_ON_ERROR)];
        }
        $artifactOffset = $current - 5;
        $artifactValues = array_values($artifacts);
        if ($artifactOffset >= 0 && $artifactOffset < count($artifactValues)) {
            $artifact = $artifactValues[$artifactOffset];
            hub_test_assert(($request['method'] ?? '') === 'GET'
                && str_contains((string)($request['url'] ?? ''), 'mode=cluster_artifact&task_id=' . $routeId . '&artifact_id=' . $artifact['id']),
                'Cluster acceptance must retrieve each artifact through the returned opaque route URL');
            return ['status' => 200, 'headers' => ['Content-Type' => $artifact['mime_type']], 'body' => $artifact['body']];
        }
        $ackOffset = $current - 5 - count($artifactValues);
        if ($ackOffset >= 0 && $ackOffset < count($artifactValues)) {
            $artifact = $artifactValues[$ackOffset];
            hub_test_assert(($request['method'] ?? '') === 'POST' && ($request['body'] ?? '') === ''
                && str_contains((string)($request['url'] ?? ''), 'mode=cluster_task_artifacts_ack&task_id=' . $routeId . '&artifact_id=' . $artifact['id']),
                'Cluster acceptance must acknowledge each artifact through the returned opaque route URL');
            return ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['ok' => true, 'task_id' => $routeId], JSON_THROW_ON_ERROR)];
        }
        throw new RuntimeException('unexpected Cluster acceptance request');
    };
}

function hub_test_edge_tts_acceptance_command(array &$commands): callable
{
    return static function (array $command, int $timeout) use (&$commands): array {
        $commands[] = $command;
        hub_test_assert(($command[0] ?? null) === 'ffprobe' && $timeout > 0,
            'Edge TTS acceptance must verify downloaded audio with ffprobe');
        return ['exit_code' => 0, 'stdout' => '{"format":{"duration":"1.0"}}', 'stderr' => ''];
    };
}

function hub_test_edge_tts_acceptance_main_output(PDO $db, callable $http, callable $command, array $env): array
{
    ob_start();
    $exit = hub_edge_tts_acceptance_main(['edge_tts_acceptance.php'], $db, $http, $command, $env);
    $output = (string)ob_get_clean();
    $decoded = json_decode($output, true);
    hub_test_assert(is_array($decoded), 'Edge TTS acceptance CLI output must be JSON');
    return ['exit' => $exit, 'output' => $decoded, 'raw' => $output];
}

hub_test('Edge TTS acceptance verifier is require-able and rejects unsafe configuration without leaks', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $httpCalls = 0;
    $http = static function () use (&$httpCalls): array {
        $httpCalls++;
        return [];
    };
    $command = static fn (): array => ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
    $missing = hub_test_edge_tts_acceptance_main_output($db, $http, $command, []);
    $invalid = hub_test_edge_tts_acceptance_main_output($db, $http, $command, [
        'AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL' => 'https://token@hub.example/api.php?bad=1',
        'AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN' => 'edge-tts-unit-token-secret',
    ]);
    $saved = (string)$db->query("SELECT result_json || ' ' || error_message FROM benchmark_runs ORDER BY id DESC LIMIT 1")->fetchColumn();
    hub_test_assert($missing['exit'] === 1 && $invalid['exit'] === 1
        && ($missing['output']['error'] ?? null) === 'edge_tts_acceptance_config_invalid'
        && ($invalid['output']['error'] ?? null) === 'edge_tts_acceptance_config_invalid'
        && $httpCalls === 0
        && !str_contains($missing['raw'] . $invalid['raw'] . $saved, 'edge-tts-unit-token-secret')
        && !str_contains($missing['raw'] . $invalid['raw'] . $saved, 'hub.example'),
        'Edge TTS acceptance config failures must be bounded, redacted, recorded, and offline');
});

hub_test('Edge TTS acceptance completes only through ordered public routes and saves a redacted CPU result', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    hub_test_edge_tts_acceptance_insert_local_runtime($db, hub_test_edge_tts_acceptance_token($db));
    $beforeTemp = glob(sys_get_temp_dir() . '/edge_tts_acceptance_*') ?: [];
    $requests = [];
    $commands = [];
    $run = hub_test_edge_tts_acceptance_main_output(
        $db,
        hub_test_edge_tts_acceptance_http($requests),
        hub_test_edge_tts_acceptance_command($commands),
        hub_test_edge_tts_acceptance_env(),
    );
    $afterTemp = glob(sys_get_temp_dir() . '/edge_tts_acceptance_*') ?: [];
    $stored = (string)$db->query("SELECT result_json FROM benchmark_runs WHERE benchmark_key = 'edge_tts_async_complete' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $urls = array_column($requests, 'url');
    $body = implode('', array_map(static fn (array $request): string => (string)($request['body'] ?? ''), $requests));
    $forbidden = [
        'edge-tts-unit-token-secret', 'https://hub.example/3waAIHub/api.php', '4242', '901',
        hash('sha256', (string)hub_test_edge_tts_acceptance_artifacts()['generated_audio']['body']),
        'This is a short Edge TTS acceptance check.',
    ];
    $redacted = $run['raw'] . $stored;
    hub_test_assert($run['exit'] === 0 && ($run['output']['ok'] ?? false) === true
        && count($requests) === 16 && count($commands) === 2 && $beforeTemp === $afterTemp
        && $urls[0] === 'https://hub.example/3waAIHub/api.php?mode=edge_tts'
        && $urls[1] === 'https://hub.example/3waAIHub/api.php?mode=edge_tts&voice=zh-TW-HsiaoChenNeural'
        && str_contains($body, 'include_subtitles=1')
        && (($run['output']['result']['cpu_queue'] ?? false) === true)
        && (($run['output']['result']['gpu_lease_absent'] ?? false) === true)
        && (($run['output']['result']['owned_runtime_pids_absent'] ?? false) === true)
        && array_filter($forbidden, static fn (string $value): bool => str_contains($redacted, $value)) === [],
        'Edge TTS acceptance must use only the ordered public API, validate CPU-only runtime facts, clean temporary files, and store redacted evidence');
});

hub_test('Edge TTS acceptance follows only validated opaque Cluster links and acknowledges artifacts', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $tokenId = hub_test_edge_tts_acceptance_token($db);
    $nodeTokenId = hub_test_edge_tts_acceptance_node_token($db);
    hub_test_edge_tts_acceptance_insert_local_runtime($db, $nodeTokenId);
    $routeId = hub_test_edge_tts_acceptance_cluster_route($db, $tokenId);
    $requests = [];
    $commands = [];
    $run = hub_test_edge_tts_acceptance_main_output(
        $db,
        hub_test_edge_tts_acceptance_cluster_http($requests, $routeId),
        hub_test_edge_tts_acceptance_command($commands),
        hub_test_edge_tts_acceptance_cluster_env(),
    );
    $urls = array_column($requests, 'url');
    hub_test_assert($run['exit'] === 0 && ($run['output']['ok'] ?? false) === true
        && count($requests) === 15 && count($commands) === 2
        && $urls[3] === 'https://hub.example/3waAIHub/cluster_api.php?mode=cluster_task_status&task_id=' . $routeId
        && $urls[4] === 'https://hub.example/3waAIHub/cluster_api.php?mode=cluster_task_result&task_id=' . $routeId
        && (($run['output']['result']['cpu_queue'] ?? false) === true),
        'Cluster acceptance must retain its opaque route ID, follow only supplied same-origin links, validate artifacts, ACK each one, and bind local CPU evidence');
});

hub_test('Edge TTS acceptance rejects untrusted Cluster links and cannot attest a routed local collision', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $tokenId = hub_test_edge_tts_acceptance_token($db);
    hub_test_edge_tts_acceptance_insert_local_runtime($db, $tokenId);
    $routeId = hub_test_edge_tts_acceptance_cluster_route($db, $tokenId);
    $requests = [];
    $commands = [];
    $badLink = hub_test_edge_tts_acceptance_main_output(
        $db,
        hub_test_edge_tts_acceptance_cluster_http($requests, $routeId, true),
        hub_test_edge_tts_acceptance_command($commands),
        hub_test_edge_tts_acceptance_cluster_env(),
    );
    hub_test_assert($badLink['exit'] === 1 && ($badLink['output']['error'] ?? null) === 'edge_tts_acceptance_submission_failed' && count($requests) === 3,
        'an off-origin Cluster follow-up URL must fail before polling or artifact access');

    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $tokenId = hub_test_edge_tts_acceptance_token($db);
    hub_test_edge_tts_acceptance_node_token($db);
    $differentOwner = hub_create_api_token($db, hub_create_api_member($db, 'Edge TTS acceptance wrong node'), 'wrong edge tts node', null, null);
    hub_test_edge_tts_acceptance_insert_local_runtime($db, (int)$differentOwner['token_id']);
    $routeId = hub_test_edge_tts_acceptance_cluster_route($db, $tokenId);
    $requests = [];
    $collision = hub_test_edge_tts_acceptance_main_output(
        $db,
        hub_test_edge_tts_acceptance_cluster_http($requests, $routeId),
        hub_test_edge_tts_acceptance_command($commands),
        hub_test_edge_tts_acceptance_cluster_env(),
    );
    hub_test_assert($collision['exit'] === 1 && ($collision['output']['error'] ?? null) === 'edge_tts_acceptance_task_failed',
        'a valid Edge TTS local task owned by a token other than the active self-station token must never attest Cluster success');
});

hub_test('Edge TTS acceptance maps every workflow failure to its bounded public code', function (): void {
    $expected = [
        'list_demo' => 'edge_tts_acceptance_list_demo_failed',
        'submission' => 'edge_tts_acceptance_submission_failed',
        'task' => 'edge_tts_acceptance_task_failed',
        'artifact' => 'edge_tts_acceptance_artifact_invalid',
    ];
    foreach ($expected as $failure => $code) {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        hub_test_edge_tts_acceptance_insert_local_runtime($db, hub_test_edge_tts_acceptance_token($db));
        $requests = [];
        $commands = [];
        $run = hub_test_edge_tts_acceptance_main_output(
            $db,
            hub_test_edge_tts_acceptance_http($requests, $failure),
            hub_test_edge_tts_acceptance_command($commands),
            hub_test_edge_tts_acceptance_env(),
        );
        hub_test_assert($run['exit'] === 1 && ($run['output']['error'] ?? null) === $code,
            'Edge TTS acceptance must map ' . $failure . ' to its bounded failure class');
    }
});

hub_test('Edge TTS acceptance rejects a colliding local CPU task from another Pack', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    hub_test_edge_tts_acceptance_insert_local_runtime($db, hub_test_edge_tts_acceptance_token($db), 4242, [
        'requested_mode' => 'other_cpu_mode',
        'pack_id' => 'other-pack',
        'pack_version' => '1.0.0',
        'job' => 'other_job',
    ]);
    $requests = [];
    $commands = [];
    $run = hub_test_edge_tts_acceptance_main_output(
        $db,
        hub_test_edge_tts_acceptance_http($requests),
        hub_test_edge_tts_acceptance_command($commands),
        hub_test_edge_tts_acceptance_env(),
    );
    hub_test_assert($run['exit'] === 1 && ($run['output']['error'] ?? null) === 'edge_tts_acceptance_task_failed',
        'a Cluster task ID collision with a local non-Edge CPU task must never attest success');
});

hub_test('Edge TTS acceptance bounds demo and artifact response bodies through its HTTP seam', function (): void {
    $expected = [
        'oversize_demo' => 'edge_tts_acceptance_list_demo_failed',
        'oversize_artifact' => 'edge_tts_acceptance_artifact_invalid',
    ];
    foreach ($expected as $failure => $code) {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        hub_test_edge_tts_acceptance_insert_local_runtime($db, hub_test_edge_tts_acceptance_token($db));
        $requests = [];
        $commands = [];
        $run = hub_test_edge_tts_acceptance_main_output(
            $db,
            hub_test_edge_tts_acceptance_http($requests, $failure),
            hub_test_edge_tts_acceptance_command($commands),
            hub_test_edge_tts_acceptance_env(),
        );
        hub_test_assert($run['exit'] === 1 && ($run['output']['error'] ?? null) === $code
            && array_filter($requests, static fn (array $request): bool => (int)($request['max_body_bytes'] ?? 0) > 0) !== [],
            'Edge TTS acceptance must cap ' . $failure . ' bodies before accepting the response');
    }
});

hub_test('Edge TTS Pack publishes the ready CPU-only async runner contract', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('edge-tts');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'Edge TTS Pack must validate with its runner build context');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');

    hub_test_assert(($manifest['id'] ?? null) === 'edge-tts'
        && ($manifest['version'] ?? null) === '0.3.0'
        && ($manifest['category'] ?? null) === 'audio'
        && ($manifest['runtime_level'] ?? null) === 'L5-benchmark-ready'
        && ($manifest['target_level'] ?? null) === 'L5-benchmark-ready'
        && ($manifest['runtime_ready'] ?? null) === true
        && ($manifest['default_mode'] ?? null) === 'edge_tts'
        && ($manifest['experimental'] ?? null) === true
        && ($manifest['runtime'] ?? null) === ['kind' => 'internal_task', 'windows_wsl_job' => true]
        && !empty($manifest['platform_targets']['linux-docker']['supported'])
        && !empty($manifest['platform_targets']['windows-wsl2-linux-docker']['supported'])
        && ($manifest['gateway'] ?? null) === [
            'invoke_path' => 'task_submit:pack_job',
            'methods' => ['GET', 'POST'],
            'timeout_sec' => 180,
            'max_upload_mb' => 1,
            'require_service_enabled' => true,
        ]
        && ($manifest['runner_build'] ?? null) === [
            'context' => 'service',
            'dockerfile' => 'Dockerfile',
            'image' => '3waaihub/edge-tts:0.3.0',
        ], 'Edge TTS must publish its controlled Task 2 runner build metadata');
    $acceptanceScript = HUB_ROOT . '/scripts/edge_tts_acceptance.php';
    $contract = hub_pack_l5_contract($manifest);
    $acceptanceCase = hub_l5_benchmark_case($contract, 'edge_tts_async_complete');
    hub_test_assert(is_file($acceptanceScript) && (fileperms($acceptanceScript) & 0777) === 0755
        && array_keys($contract) === ['endpoint', 'method', 'content_type', 'task_type', 'input', 'output', 'errors', 'limits', 'benchmark']
        && ($contract['endpoint'] ?? null) === 'api.php?mode=edge_tts'
        && ($contract['method'] ?? null) === 'POST'
        && ($contract['content_type'] ?? null) === 'application/x-www-form-urlencoded'
        && ($acceptanceCase ?? null) === [
            'id' => 'edge_tts_async_complete',
            'name' => 'Edge TTS real async public-API acceptance',
            'type' => 'external_acceptance',
            'mode' => 'edge_tts',
            'method' => 'POST',
            'real_inference' => true,
        ], 'Edge TTS must publish one strict L5 public-API acceptance contract and executable verifier');
    foreach (['Dockerfile', 'edge-tts-entrypoint.sh', 'synthesize.py', 'generate_demos.py', 'voice_catalog.json', 'test_egress_firewall.sh', 'test_synthesize.py', 'test_generate_demos.py'] as $file) {
        $path = HUB_ROOT . '/packs/edge-tts/service/' . $file;
        hub_test_assert(is_file($path), 'Edge TTS runner asset must be present: ' . $file);
    }
    foreach (['edge-tts-entrypoint.sh', 'synthesize.py', 'generate_demos.py', 'test_egress_firewall.sh', 'test_synthesize.py', 'test_generate_demos.py'] as $file) {
        $path = HUB_ROOT . '/packs/edge-tts/service/' . $file;
        hub_test_assert((fileperms($path) & 0777) === 0755, 'Edge TTS runnable asset must use mode 0755: ' . $file);
    }
    hub_test_assert(hub_pack_container_runner_build_contract($manifest, HUB_ROOT . '/packs/edge-tts') === [
        'image' => '3waaihub/edge-tts:0.3.0',
        'context' => HUB_ROOT . '/packs/edge-tts/service',
        'dockerfile' => HUB_ROOT . '/packs/edge-tts/service/Dockerfile',
    ], 'Edge TTS runner build must use the fixed service-directory context');
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/edge-tts/service/Dockerfile');
    foreach (['FROM python:3.13-slim-bookworm', 'edge-tts==7.2.6', 'COPY edge-tts-entrypoint.sh synthesize.py generate_demos.py voice_catalog.json', 'test_egress_firewall.sh test_synthesize.py test_generate_demos.py ./', 'python3 -m unittest -v test_synthesize.py test_generate_demos.py'] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'Edge TTS Dockerfile must pin and offline-test its runner: ' . $needle);
    }
    hub_test_assert(!str_contains($dockerfile, 'mawk'), 'Edge TTS Dockerfile must not install unused mawk');
    $catalogue = json_decode((string)file_get_contents(HUB_ROOT . '/packs/edge-tts/service/voice_catalog.json'), true);
    $expectedCatalogue = [
        ['id' => 'zh-TW-HsiaoChenNeural', 'display_name' => '小晴', 'locale' => 'zh-TW', 'gender' => 'female', 'memo' => '清亮，適合主持與旁白。', 'demo_text' => '大家好，我是小晴。我的聲音比較清亮，適合當主持人、旁白，或是帶一點神祕感的開場。', 'demo_file' => '01_tw_xiaoqing_hsiaochen.mp3'],
        ['id' => 'zh-TW-HsiaoYuNeural', 'display_name' => '阿岑', 'locale' => 'zh-TW', 'gender' => 'female', 'memo' => '柔和，適合聊天與故事角色。', 'demo_text' => '嗨，我是阿岑。我的聲線比較柔一點，適合聊天、故事角色，也適合做睡前或懸疑類型的對話。', 'demo_file' => '02_tw_acen_hsiaoyu.mp3'],
        ['id' => 'zh-TW-YunJheNeural', 'display_name' => '阿哲', 'locale' => 'zh-TW', 'gender' => 'male', 'memo' => '穩定，適合解說與來賓。', 'demo_text' => '你好，我是阿哲。我的聲音比較穩，適合解說、訪談來賓，或是在雙人對話裡補充觀點。', 'demo_file' => '03_tw_azhe_yunjhe.mp3'],
        ['id' => 'zh-CN-XiaoxiaoNeural', 'display_name' => '曉曉', 'locale' => 'zh-CN', 'gender' => 'female', 'memo' => '明亮，適合正式女聲旁白。', 'demo_text' => '大家好，我是晓晓。我的声音比较明亮，适合课程旁白、新闻开场，或是清楚稳定的说明内容。', 'demo_file' => '04_cn_xiaoxiao.mp3'],
        ['id' => 'zh-CN-XiaoyiNeural', 'display_name' => '小藝', 'locale' => 'zh-CN', 'gender' => 'female', 'memo' => '輕柔，適合故事與生活感對談。', 'demo_text' => '你好，我是小艺。我的声音比较轻柔，适合故事叙述、生活对谈，也适合比较温暖的角色。', 'demo_file' => '05_cn_xiaoyi.mp3'],
        ['id' => 'zh-CN-YunjianNeural', 'display_name' => '雲健', 'locale' => 'zh-CN', 'gender' => 'male', 'memo' => '厚實，適合劇情男聲。', 'demo_text' => '大家好，我是云健。我的声音比较厚实，适合剧情角色、历史题材，或是需要力量感的段落。', 'demo_file' => '06_cn_yunjian.mp3'],
        ['id' => 'zh-CN-YunxiNeural', 'display_name' => '雲希', 'locale' => 'zh-CN', 'gender' => 'male', 'memo' => '年輕，適合輕鬆聊天。', 'demo_text' => '嗨，我是云希。我的声音比较年轻，适合轻松聊天、节目助理，或是活泼一点的男声角色。', 'demo_file' => '07_cn_yunxi.mp3'],
        ['id' => 'zh-CN-YunxiaNeural', 'display_name' => '雲夏', 'locale' => 'zh-CN', 'gender' => 'male', 'memo' => '少年感，適合年輕角色。', 'demo_text' => '你好，我是云夏。我的声音带一点少年感，适合年轻角色、轻小说旁白，或比较有好奇心的对话。', 'demo_file' => '08_cn_yunxia.mp3'],
        ['id' => 'zh-CN-YunyangNeural', 'display_name' => '雲揚', 'locale' => 'zh-CN', 'gender' => 'male', 'memo' => '播報感，適合公告與資訊整理。', 'demo_text' => '大家好，我是云扬。我的声音比较像播报员，适合新闻摘要、公告说明，或结构清楚的资讯整理。', 'demo_file' => '09_cn_yunyang.mp3'],
        ['id' => 'zh-CN-liaoning-XiaobeiNeural', 'display_name' => '小北', 'locale' => 'zh-CN-liaoning', 'gender' => 'female', 'memo' => '東北口音，適合特色角色。', 'demo_text' => '大家好，我是小北。我的声音带一点东北普通话味道，适合有地域特色、比较鲜明的角色。', 'demo_file' => '10_cn_liaoning_xiaobei.mp3'],
        ['id' => 'zh-CN-shaanxi-XiaoniNeural', 'display_name' => '小妮', 'locale' => 'zh-CN-shaanxi', 'gender' => 'female', 'memo' => '陝西口音，適合地方感角色。', 'demo_text' => '你好，我是小妮。我的声音带一点陕西普通话特色，适合地方故事、人物访谈，或有口音记忆点的角色。', 'demo_file' => '11_cn_shaanxi_xiaoni.mp3'],
        ['id' => 'zh-HK-HiuGaaiNeural', 'display_name' => '嘉嘉', 'locale' => 'zh-HK', 'gender' => 'female', 'memo' => '粵語女聲，爽朗清楚。', 'demo_text' => '大家好，我係嘉嘉。呢把聲比較爽朗，適合廣東話開場、生活節目，同埋輕鬆嘅對談。', 'demo_file' => '12_hk_hiugaai.mp3'],
        ['id' => 'zh-HK-HiuMaanNeural', 'display_name' => '漫漫', 'locale' => 'zh-HK', 'gender' => 'female', 'memo' => '粵語女聲，柔和自然。', 'demo_text' => '你好，我係漫漫。呢把聲比較柔和，適合講故事、訪問嘉賓，或者做一啲比較細膩嘅旁白。', 'demo_file' => '13_hk_hiumaan.mp3'],
        ['id' => 'zh-HK-WanLungNeural', 'display_name' => '阿龍', 'locale' => 'zh-HK', 'gender' => 'male', 'memo' => '粵語男聲，穩重有厚度。', 'demo_text' => '大家好，我係阿龍。呢把聲比較穩重，適合新聞解說、故事男聲，或者節目入面嘅專家角色。', 'demo_file' => '14_hk_wanlung.mp3'],
    ];
    hub_test_assert($catalogue === $expectedCatalogue, 'Edge TTS must ship the exact approved fourteen-profile static catalogue');
    hub_test_assert(count(array_unique(array_column($catalogue, 'id'))) === 14
        && count(array_unique(array_column($catalogue, 'demo_file'))) === 14
        && !array_filter(array_column($catalogue, 'id'), static fn (string $id): bool => str_starts_with($id, 'en-'))
        && !array_diff(array_column($catalogue, 'gender'), ['male', 'female']),
        'Edge TTS static catalogue must contain unique Chinese-only IDs and lowercase genders');
    hub_test_assert(($job['request_schema']['voice']['enum'] ?? null) === array_column($catalogue, 'id'),
        'Edge TTS synthesis voices must exactly match the static demo catalogue');
    hub_test_assert(($manifest['hardware'] ?? null) === [
        'gpu_required' => false,
        'gpu_supported' => false,
        'min_vram_mb' => 0,
    ] && ($manifest['queue'] ?? null) === [
        'supported' => true,
        'default_queue' => 'cpu',
        'max_concurrency' => 1,
    ] && ($manifest['storage'] ?? null) === ['mounts' => []]
        && ($manifest['env'] ?? null) === []
        && ($manifest['preflight'] ?? null) === ['checks' => ['docker']]
        && ($manifest['install'] ?? null) === [
            'default_service_key' => 'edge-tts-main',
            'compose_project' => '3waaihub_edge_tts',
        ], 'Edge TTS must use the fixed CPU operational contract');
    hub_test_assert(is_array($job)
        && ($job['input_fields'] ?? null) === ['text', 'voice', 'rate', 'volume', 'pitch', 'include_subtitles']
        && ($job['source_artifact_types'] ?? null) === []
        && ($job['source_required'] ?? null) === false
        && ($job['request_schema'] ?? null) === [
            'text' => ['type' => 'string', 'required' => true, 'max_length' => 4096],
            'voice' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['zh-TW-HsiaoChenNeural', 'zh-TW-HsiaoYuNeural', 'zh-TW-YunJheNeural', 'zh-CN-XiaoxiaoNeural', 'zh-CN-XiaoyiNeural', 'zh-CN-YunjianNeural', 'zh-CN-YunxiNeural', 'zh-CN-YunxiaNeural', 'zh-CN-YunyangNeural', 'zh-CN-liaoning-XiaobeiNeural', 'zh-CN-shaanxi-XiaoniNeural', 'zh-HK-HiuGaaiNeural', 'zh-HK-HiuMaanNeural', 'zh-HK-WanLungNeural'],
                'max_length' => 1024,
                'default' => 'zh-TW-HsiaoChenNeural',
            ],
            'rate' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['-50%', '-25%', '+0%', '+25%', '+50%'],
                'max_length' => 1024,
                'default' => '+0%',
            ],
            'volume' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['-50%', '-25%', '+0%', '+25%', '+50%'],
                'max_length' => 1024,
                'default' => '+0%',
            ],
            'pitch' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['-50Hz', '-25Hz', '+0Hz', '+25Hz', '+50Hz'],
                'max_length' => 1024,
                'default' => '+0Hz',
            ],
            'include_subtitles' => [
                'type' => 'boolean',
                'required' => false,
                'default' => false,
            ],
        ]
        && ($job['runner'] ?? null) === [
            'image' => '3waaihub/edge-tts:0.3.0',
            'entrypoint' => ['/app/edge-tts-entrypoint.sh', '/app/synthesize.py'],
            'args' => [],
            'output_dir' => 'output',
            'accelerator' => 'cpu',
            'required_vram_mb' => 0,
            'timeout_seconds' => 150,
            'network_profile' => 'public_egress',
            'executor' => 'container',
        ], 'Edge TTS must expose only the pinned CPU runner and typed synthesis controls');
    $invalid = $manifest;
    $invalid['gateway']['require_service_enabled'] = 'true';
    hub_test_assert(hub_validate_pack_manifest($invalid, HUB_ROOT . '/packs/edge-tts') !== [],
        'Edge TTS service-enable admission flag must be boolean');

    $catalog = hub_load_pack_catalog()['packs'];
    $entry = null;
    foreach ($catalog as $candidate) {
        if (($candidate['id'] ?? null) === 'edge-tts') {
            $entry = $candidate;
            break;
        }
    }
    hub_test_assert($entry === [
        'id' => 'edge-tts',
        'name' => 'Edge TTS External Service',
        'version' => '0.2.0',
        'category' => 'audio',
        'description' => 'Experimental CPU-only text-to-speech adapter for Microsoft Edge\'s online speech service.',
        'path' => 'packs/edge-tts',
        'featured' => true,
    ], 'Edge TTS must have the approved featured catalog entry');
});

hub_test('Edge TTS firewall setup is executed against command mocks', function (): void {
    if (hub_platform_id() !== 'linux' || !function_exists('proc_open')) {
        hub_test_skip('Edge TTS mocked firewall test requires Linux and proc_open');
    }
    $result = hub_run_command(['bash', HUB_ROOT . '/packs/edge-tts/service/test_egress_firewall.sh'], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0 && ($result['stdout'] ?? '') === 'test_egress_firewall: ok',
        'Edge TTS firewall test must execute provider-only TCP 443, DNS removal, terminal DROP, and forced-failure sentinel checks: ' . ($result['output'] ?? ''));
});

hub_test('Edge TTS ready route still requires the administrator enable gate', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);

    hub_test_assert(($installed['edge_tts_demos'] ?? null) === ['succeeded' => 0, 'failed' => 0]
        && (hub_pack_job_async_routes()['edge_tts'] ?? null) === [
        'pack_id' => 'edge-tts',
        'job' => 'synthesize',
        'accelerator' => 'cpu',
    ], 'offline Edge TTS tests must return only zero demo counters and keep the fixed CPU async route');
    hub_test_assert(in_array('edge_tts', hub_playground_supported_modes(), true), 'Edge TTS must be selectable in the customer playground');

    $job = hub_pack_async_job_contract(hub_get_pack('edge-tts')['manifest'], 'synthesize');
    hub_test_assert(is_array($job) && hub_pack_job_normalize_request_input(['text' => 'Taiwan Edge TTS'], $job) === [
        'text' => 'Taiwan Edge TTS',
        'voice' => 'zh-TW-HsiaoChenNeural',
        'rate' => '+0%',
        'volume' => '+0%',
        'pitch' => '+0Hz',
        'include_subtitles' => false,
    ], 'Edge TTS must persist the manifest defaults with the supplied text');
    hub_test_assert(hub_pack_job_normalize_request_input(['text' => 'Taiwan Edge TTS', 'include_subtitles' => 'true'], $job) === [
        'text' => 'Taiwan Edge TTS',
        'include_subtitles' => true,
        'voice' => 'zh-TW-HsiaoChenNeural',
        'rate' => '+0%',
        'volume' => '+0%',
        'pitch' => '+0Hz',
    ], 'Edge TTS must normalize the declared true subtitle request to a boolean');
    foreach ([
        [],
        ['text' => 'x', 'voice' => 'unknown'],
        ['text' => 'x', 'rate' => '0%'],
        ['text' => 'x', 'volume' => '+75%'],
        ['text' => 'x', 'pitch' => '+10Hz'],
        ['text' => 'x', 'include_subtitles' => 'yes'],
        ['text' => 'x', 'source_artifact_id' => 1],
        ['text' => 'x', 'callback_url' => 'https://example.test/callback'],
    ] as $input) {
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input($input, $job)), 'Edge TTS must reject invalid undeclared local input');
    }
    try {
        hub_resolve_pack_job_async_route($db, 'edge_tts');
        throw new RuntimeException('Edge TTS route must not resolve before an administrator enables it');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'pack_service_disabled', 'Edge TTS route must report its disabled service gate');
    }
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 0, 'unready Edge TTS must not create a task');
});

hub_test('Edge TTS queues only for an authorized token after administrator enablement', function (): void {
    hub_test_edge_tts_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Edge TTS Owner');
        $token = hub_create_api_token($db, $memberId, 'Edge TTS token', null, null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $denied = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Denied']);
        hub_test_assert($denied['status'] === 403 && (hub_test_edge_tts_payload($denied)['error'] ?? null) === 'token_mode_not_allowed', 'Edge TTS must require its token mode permission');

        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'edge_tts', null);
        $disabled = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Queued']);
        hub_test_assert($disabled['status'] === 503 && (hub_test_edge_tts_payload($disabled)['error'] ?? null) === 'pack_service_disabled'
            && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 0,
            'a permitted Edge TTS token must not queue a task before an administrator enables the service');

        hub_install_pack($db, 'edge-tts', [
            'service_key' => 'edge-tts-other',
            'mode' => 'edge_tts_other',
            'idempotent' => true,
        ]);
        hub_set_service_enabled($db, 'edge_tts_other', true);
        $version = (string)(hub_get_pack('edge-tts')['manifest']['version'] ?? '');
        hub_test_assert(!hub_pack_job_async_route_service_enabled($db, 'edge-tts', $version, 'edge_tts'),
            'an enabled different mode must not unlock the disabled Edge TTS route');

        hub_set_service_enabled($db, 'edge_tts', true);
        hub_test_assert(hub_pack_job_async_route_service_enabled($db, 'edge-tts', $version, 'edge_tts'),
            'the enabled Edge TTS service must satisfy its explicit service gate');

        $manifestPath = HUB_ROOT . '/packs/edge-tts/pack.json';
        $manifestBefore = (string)file_get_contents($manifestPath);
        $queued = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Taiwan Edge TTS', 'include_subtitles' => 'true']);
        $payload = hub_test_edge_tts_payload($queued);
        $task = hub_get_task($db, (int)($payload['task_id'] ?? 0));
        hub_test_assert($queued['status'] === 200 && ($payload['ok'] ?? false) === true && ($payload['status'] ?? '') === 'queued'
            && is_array($task) && ($task['requested_mode'] ?? '') === 'edge_tts'
            && ($task['queue_name'] ?? '') === 'cpu' && ($task['accelerator'] ?? '') === 'cpu'
            && json_decode((string)($task['input_json'] ?? ''), true) === [
                'text' => 'Taiwan Edge TTS',
                'include_subtitles' => true,
                'voice' => 'zh-TW-HsiaoChenNeural',
                'rate' => '+0%',
                'volume' => '+0%',
                'pitch' => '+0Hz',
            ] && (string)file_get_contents($manifestPath) === $manifestBefore,
            'an enabled Edge TTS service must queue only the normalized request without mutating its tracked manifest');

        $defaultQueued = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Taiwan Edge TTS defaults']);
        $defaultPayload = hub_test_edge_tts_payload($defaultQueued);
        $defaultTask = hub_get_task($db, (int)($defaultPayload['task_id'] ?? 0));
        hub_test_assert($defaultQueued['status'] === 200 && ($defaultPayload['ok'] ?? false) === true && ($defaultPayload['status'] ?? '') === 'queued'
            && is_array($defaultTask)
            && json_decode((string)($defaultTask['input_json'] ?? ''), true) === [
                'text' => 'Taiwan Edge TTS defaults',
                'voice' => 'zh-TW-HsiaoChenNeural',
                'rate' => '+0%',
                'volume' => '+0%',
                'pitch' => '+0Hz',
                'include_subtitles' => false,
            ], 'an omitted subtitle request must queue its false manifest default');
    });
});

hub_test('Edge TTS public API appears after its ready service is enabled', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    hub_set_service_enabled($db, 'edge_tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $services = hub_public_api_services($db, static fn (): bool => true);
    $edgeTts = null;
    foreach ($services as $service) {
        if (($service['mode'] ?? null) === 'edge_tts') {
            $edgeTts = $service;
            break;
        }
    }

    $subtitleField = null;
    foreach ((array)($edgeTts['input_fields'] ?? []) as $field) {
        if (($field['name'] ?? null) === 'include_subtitles') {
            $subtitleField = $field;
            break;
        }
    }
    $operations = [
        ['method' => 'GET', 'query' => [], 'response' => 'verified voice catalogue JSON'],
        ['method' => 'GET', 'query' => ['voice' => '<voice-id>'], 'response' => 'audio/mpeg; Cache-Control: private, no-store'],
        ['method' => 'POST', 'response' => 'asynchronous synthesis task'],
    ];
    $html = hub_public_api_docs_html($db, null, static fn (): bool => true);
    hub_test_assert(is_array($edgeTts) && ($edgeTts['mode'] ?? null) === 'edge_tts'
        && $subtitleField === [
            'name' => 'include_subtitles',
            'type' => 'boolean',
            'required' => false,
            'default' => false,
        ] && ($edgeTts['operations'] ?? null) === $operations
        && str_contains($html, 'Additional operations')
        && str_contains($html, 'verified voice catalogue JSON'),
        'ready enabled Edge TTS must publish and display its verified-demo operations alongside synthesis');
});

hub_test('Edge TTS install builds and verifies its controlled runner image', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    $built = false;
    $installed = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$commands, &$built): array {
            $commands[] = $command;
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                return $built ? ['exit_code' => 0, 'stdout' => 'sha256:edge-tts', 'stderr' => ''] : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'missing'];
            }
            if (($command[1] ?? '') === 'build') {
                $built = true;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected Edge TTS runner image command');
        },
    ]);
    hub_test_assert($commands === [
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/edge-tts:0.3.0'],
        ['docker', 'build', '--tag', '3waaihub/edge-tts:0.3.0', '--file', HUB_ROOT . '/packs/edge-tts/service/Dockerfile', HUB_ROOT . '/packs/edge-tts/service'],
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/edge-tts:0.3.0'],
    ] && ($installed['service']['install_status'] ?? '') === 'installed', 'Edge TTS runner must build from its Pack-controlled context and be verified before installation');
});

function hub_test_edge_tts_build_runner(array &$commands): callable
{
    $built = false;

    return static function (array $command, int $timeoutSeconds) use (&$commands, &$built): array {
        $commands[] = $command;
        if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
            return $built ? ['exit_code' => 0, 'stdout' => 'sha256:edge-tts', 'stderr' => ''] : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'missing'];
        }
        if (($command[1] ?? '') === 'build') {
            $built = true;
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        }

        throw new RuntimeException('unexpected Edge TTS runner image command');
    };
}

function hub_test_edge_tts_demo_catalogue(): array
{
    $catalogue = json_decode((string)file_get_contents(HUB_ROOT . '/packs/edge-tts/service/voice_catalog.json'), true);
    hub_test_assert(is_array($catalogue), 'Edge TTS test catalogue must decode');

    return $catalogue;
}

function hub_test_edge_tts_write_demo_output(string $dir, array $voices, string $kind = 'valid'): void
{
    $available = [];
    foreach ($voices as $voice) {
        $file = (string)$voice['demo_file'];
        $path = $dir . '/' . $file;
        $contents = 'demo:' . (string)$voice['id'];
        if ($kind === 'symlink') {
            $target = $dir . '/symlink-target.mp3';
            file_put_contents($target, $contents);
            symlink($target, $path);
        } else {
            file_put_contents($path, $contents);
        }
        $available[] = [
            'id' => (string)$voice['id'],
            'file' => $file,
            'bytes' => strlen($contents),
            'sha256' => $kind === 'hash_mismatch' ? str_repeat('0', 64) : hash('sha256', $contents),
        ];
    }
    if ($kind === 'malformed') {
        file_put_contents($dir . '/available.json', '{"version":1,"voices":[]}');
        return;
    }
    file_put_contents($dir . '/available.json', json_encode(['version' => 1, 'voices' => $available], JSON_THROW_ON_ERROR));
}

function hub_test_edge_tts_publish_demo_fixture(array $voices): void
{
    $current = hub_edge_tts_demo_root('edge-tts-main');
    if (!is_dir($current) && !mkdir($current, 0755, true) && !is_dir($current)) {
        throw new RuntimeException('unable to create Edge TTS demo fixture');
    }
    hub_test_edge_tts_write_demo_output($current, $voices);
}

function hub_test_edge_tts_demo_token(PDO $db): array
{
    $memberId = hub_create_api_member($db, 'Edge TTS Demo Reader');
    $token = hub_create_api_token($db, $memberId, 'edge tts demo token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'edge_tts', null);

    return $token;
}

hub_test('Edge TTS GET lists and streams only static verified demos', function (): void {
    hub_test_edge_tts_isolate(static function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        $voices = array_slice(hub_test_edge_tts_demo_catalogue(), 0, 2);
        hub_test_edge_tts_publish_demo_fixture($voices);
        hub_set_service_enabled($db, 'edge_tts', true);
        hub_update_service_status($db, (int)$installed['service']['id'], 'running');
        $token = hub_test_edge_tts_demo_token($db);

        $listed = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET');
        $listPayload = hub_test_edge_tts_payload($listed);
        $expected = array_map(static fn (array $voice): array => [
            'id' => $voice['id'],
            'display_name' => $voice['display_name'],
            'locale' => $voice['locale'],
            'gender' => $voice['gender'],
            'memo' => $voice['memo'],
            'demo_text' => $voice['demo_text'],
            'demo_url' => '?mode=edge_tts&voice=' . rawurlencode((string)$voice['id']),
        ], $voices);
        $stream = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voices[0]['id']]);

        hub_test_assert($listed['status'] === 200 && $listPayload === ['ok' => true, 'voices' => $expected]
            && !str_contains((string)$listed['body'], HUB_DATA_DIR)
            && $stream['status'] === 200 && ($stream['stream_path'] ?? '') === hub_edge_tts_demo_root('edge-tts-main') . '/' . $voices[0]['demo_file']
            && array_slice($stream['headers'] ?? [], 0, 5) === [
                'Content-Type: audio/mpeg',
                'Content-Length: ' . strlen('demo:' . $voices[0]['id']),
                'Content-Disposition: inline; filename="edge-tts-demo.mp3"',
                'Cache-Control: private, no-store',
                'X-Content-Type-Options: nosniff',
            ] && str_starts_with((string)(($stream['headers'] ?? [])[5] ?? ''), 'X-3waAIHub-Request-Id: ')
            && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 0,
            'Edge TTS GET must expose only verified static metadata and a safe inline MP3 stream without queuing work');
    });
});

hub_test('Edge TTS GET enforces auth, readiness, strict queries, and demo integrity', function (): void {
    hub_test_edge_tts_isolate(static function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        $voices = array_slice(hub_test_edge_tts_demo_catalogue(), 0, 2);
        hub_test_edge_tts_publish_demo_fixture($voices);
        $memberId = hub_create_api_member($db, 'Edge TTS Demo Controls');
        $token = hub_create_api_token($db, $memberId, 'edge tts demo controls', null, null);

        $denied = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET');
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'edge_tts', null);
        $disabled = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET');
        hub_set_service_enabled($db, 'edge_tts', true);
        $stopped = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET');
        hub_update_service_status($db, (int)$installed['service']['id'], 'running');
        $invalid = [
            hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['other' => 'x']),
            hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => ['duplicate']]),
            hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => "bad\x00voice"]),
            hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => str_repeat('v', 1025)]),
        ];
        $unknown = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => 'zh-TW-UnknownNeural']);
        $missing = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)hub_test_edge_tts_demo_catalogue()[2]['id']]);
        $duplicate = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voices[0]['id']], '/3waAIHub/api.php?mode=edge_tts&voice=zh-TW-HsiaoChenNeural&voice=zh-TW-HsiaoChenNeural');
        $collisions = array_map(
            static fn (string $query): array => hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voices[0]['id']], '/3waAIHub/api.php?mode=edge_tts&' . $query),
            ['voice[]=x&voice=zh-TW-HsiaoChenNeural', 'voice%00x=x&voice=zh-TW-HsiaoChenNeural']
        );
        $aliases = array_map(
            static fn (string $query): array => hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voices[0]['id']], '/3waAIHub/api.php?mode=edge_tts&' . $query),
            ['voice[]=zh-TW-HsiaoChenNeural', 'voice[0]=zh-TW-HsiaoChenNeural', 'voice%00x=zh-TW-HsiaoChenNeural']
        );
        $method = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'DELETE');
        $path = hub_edge_tts_demo_root('edge-tts-main') . '/' . $voices[0]['demo_file'];
        file_put_contents($path, 'tampered');
        $tampered = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voices[0]['id']]);

        hub_test_assert($denied['status'] === 403 && (hub_test_edge_tts_payload($denied)['error'] ?? null) === 'token_mode_not_allowed'
            && $disabled['status'] === 503 && (hub_test_edge_tts_payload($disabled)['error'] ?? null) === 'pack_service_disabled'
            && $stopped['status'] === 503 && (hub_test_edge_tts_payload($stopped)['error'] ?? null) === 'runtime_not_ready'
            && $method['status'] === 405 && (hub_test_edge_tts_payload($method)['error'] ?? null) === 'method_not_allowed'
            && array_filter($invalid, static fn (array $response): bool => $response['status'] !== 400 || (hub_test_edge_tts_payload($response)['error'] ?? null) !== 'invalid_request') === []
            && $duplicate['status'] === 400 && (hub_test_edge_tts_payload($duplicate)['error'] ?? null) === 'invalid_request'
            && array_filter($collisions, static fn (array $response): bool => $response['status'] !== 400 || (hub_test_edge_tts_payload($response)['error'] ?? null) !== 'invalid_request') === []
            && array_filter($aliases, static fn (array $response): bool => $response['status'] !== 400 || (hub_test_edge_tts_payload($response)['error'] ?? null) !== 'invalid_request') === []
            && $unknown['status'] === 404 && $missing['status'] === 404 && $tampered['status'] === 404
            && (hub_test_edge_tts_payload($unknown)['error'] ?? null) === 'demo_not_available'
            && (hub_test_edge_tts_payload($missing)['error'] ?? null) === 'demo_not_available'
            && (hub_test_edge_tts_payload($tampered)['error'] ?? null) === 'demo_not_available'
            && !str_contains((string)$tampered['body'], HUB_DATA_DIR)
            && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 0,
            'Edge TTS GET must fail closed for unauthorized, unavailable, malformed, and tampered demo requests without paths or tasks');
    });
});

hub_test('Edge TTS GET rejects symlinked demos while POST remains asynchronous synthesis', function (): void {
    hub_test_edge_tts_isolate(static function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
        $voice = hub_test_edge_tts_demo_catalogue()[0];
        hub_test_edge_tts_publish_demo_fixture([$voice]);
        hub_set_service_enabled($db, 'edge_tts', true);
        hub_update_service_status($db, (int)$installed['service']['id'], 'running');
        $token = hub_test_edge_tts_demo_token($db);
        $path = hub_edge_tts_demo_root('edge-tts-main') . '/' . $voice['demo_file'];
        $target = hub_edge_tts_demo_root('edge-tts-main') . '/fixture-target.mp3';
        rename($path, $target);
        symlink($target, $path);
        $symlink = hub_test_edge_tts_request($db, (string)$token['plain_token'], [], 'GET', ['voice' => (string)$voice['id']]);

        unlink($path);
        rename($target, $path);
        $post = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Queue after demo verification']);
        hub_test_assert($symlink['status'] === 404 && (hub_test_edge_tts_payload($symlink)['error'] ?? null) === 'demo_not_available'
            && $post['status'] === 200 && (hub_test_edge_tts_payload($post)['status'] ?? null) === 'queued'
            && (int)$db->query("SELECT COUNT(*) FROM tasks WHERE requested_mode = 'edge_tts'")->fetchColumn() === 1,
            'symlinked demos must never stream, while Edge TTS POST keeps its existing task submission path');
    });
});

function hub_test_edge_tts_demo_runner(array &$commands, callable $writer, int $exitCode = 0, bool $incompleteCleanup = false): callable
{
    return static function (array $command, int $timeoutSeconds) use (&$commands, $writer, $exitCode, $incompleteCleanup): array {
        $commands[] = $command;
        if (($command[0] ?? '') === 'docker' && ($command[1] ?? '') === 'run') {
            $mount = (string)($command[array_search('--mount', $command, true) + 1] ?? '');
            preg_match('/^type=bind,src=(.+),dst=\/workspace\/output$/', $mount, $matches);
            hub_test_assert(isset($matches[1]) && is_dir($matches[1]), 'Edge TTS demo runner must receive its staging output bind mount');
            $writer($matches[1]);
            return ['exit_code' => $exitCode, 'stdout' => '', 'stderr' => ''];
        }
        if (($command[0] ?? '') === 'docker' && ($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
            if ($incompleteCleanup) {
                return ['exit_code' => 0, 'stdout' => '{"Running":false,"Pid":0}', 'stderr' => ''];
            }
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such container'];
        }
        if ($incompleteCleanup && ($command[1] ?? '') === 'stop') {
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        }
        if ($incompleteCleanup && ($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'rm') {
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
        }

        throw new RuntimeException('unexpected Edge TTS demo command');
    };
}

hub_test('Edge TTS installation atomically publishes verified demo output with a fixed Docker command', function (): void {
    $db = hub_test_reset_db();
    $buildCommands = [];
    $demoCommands = [];
    $voices = hub_test_edge_tts_demo_catalogue();
    $installed = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'runner_build_runner' => hub_test_edge_tts_build_runner($buildCommands),
        'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($demoCommands, static function (string $dir) use ($voices): void {
            hub_test_edge_tts_write_demo_output($dir, $voices);
        }),
    ]);
    $run = array_values(array_filter($demoCommands, static fn (array $command): bool => ($command[1] ?? '') === 'run'))[0] ?? [];
    $mount = (string)($run[array_search('--mount', $run, true) + 1] ?? '');
    hub_test_assert($installed['edge_tts_demos'] === ['succeeded' => 14, 'failed' => 0]
        && $buildCommands !== []
        && $run === [
            'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
            '--mount', $mount, '--name', $run[10] ?? '', '--entrypoint', '/app/edge-tts-entrypoint.sh',
            '3waaihub/edge-tts:0.3.0', '/app/generate_demos.py',
        ]
        && preg_match('#^type=bind,src=' . preg_quote(HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/', '#') . '[A-Za-z0-9_.-]+,dst=/workspace/output$#', $mount) === 1
        && !in_array('--env', $run, true) && !in_array('--gpus', $run, true) && !str_contains($mount, 'input')
        && is_file(HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current/01_tw_xiaoqing_hsiaochen.mp3')
        && hub_artifact_safe_path(HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current/01_tw_xiaoqing_hsiaochen.mp3') !== null,
        'Edge TTS install must run only its fixed bridge/NET_ADMIN output-only generator and atomically publish all verified demos');
});

hub_test('Edge TTS installation accepts partial verified demos and rejects invalid staged output without service promotion', function (): void {
    foreach (['hash_mismatch', 'malformed', 'symlink'] as $kind) {
        $db = hub_test_reset_db();
        $current = HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current';
        mkdir($current, 0775, true);
        file_put_contents($current . '/prior.mp3', 'prior');
        $voices = hub_test_edge_tts_demo_catalogue();
        $unused = [];
        try {
            hub_install_pack($db, 'edge-tts', [
                'idempotent' => true,
                'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($unused, static function (string $dir) use ($voices, $kind): void {
                    hub_test_edge_tts_write_demo_output($dir, [$voices[0]], $kind);
                }),
            ]);
            throw new RuntimeException('invalid Edge TTS demo output must abort install');
        } catch (RuntimeException $e) {
            hub_test_assert($e->getMessage() === 'edge_tts_demo_initialization_failed', 'invalid Edge TTS demo output must expose only the stable error');
        }
        hub_test_assert(is_file($current . '/prior.mp3')
            && (int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'edge-tts-main'")->fetchColumn() === 0,
            'failed Edge TTS demo initialization must preserve current and not create a service row');
    }

    $db = hub_test_reset_db();
    $voices = hub_test_edge_tts_demo_catalogue();
    $unused = [];
    $installed = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($unused, static function (string $dir) use ($voices): void {
            hub_test_edge_tts_write_demo_output($dir, array_slice($voices, 0, 2));
        }),
    ]);
    hub_test_assert($installed['edge_tts_demos'] === ['succeeded' => 2, 'failed' => 12]
        && count(hub_edge_tts_verified_voices('edge-tts-main')) === 2,
        'partial Edge TTS demo output must publish only its verified catalogue voices');
});

hub_test('Edge TTS demo initialization failure and non-Edge installs do not invoke the generator', function (): void {
    $db = hub_test_reset_db();
    $current = HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current';
    mkdir($current, 0775, true);
    file_put_contents($current . '/prior.mp3', 'prior');
    $unused = [];
    try {
        hub_install_pack($db, 'edge-tts', [
            'idempotent' => true,
            'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($unused, static function (string $dir): void {}, 1),
        ]);
        throw new RuntimeException('failed Edge TTS generator must abort install');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'edge_tts_demo_initialization_failed', 'failed Edge TTS generator must expose only the stable error');
    }
    hub_test_assert(is_file($current . '/prior.mp3')
        && (int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'edge-tts-main'")->fetchColumn() === 0,
        'all failed Edge TTS generation must preserve current and not promote a service');

    $called = false;
    hub_install_pack($db, 'audio-cleanup', [
        'idempotent' => true,
        'edge_tts_demo_runner' => static function () use (&$called): never {
            $called = true;
            throw new RuntimeException('non-Edge pack must not invoke Edge TTS generator');
        },
    ]);
    hub_test_assert(!$called, 'non-Edge installs must not invoke the Edge TTS generator seam');
});

hub_test('Edge TTS install can defer command-only demo generation', function (): void {
    $db = hub_test_reset_db();
    $called = false;
    $installed = hub_install_pack($db, 'edge-tts', [
        'idempotent' => true,
        'provision_runner' => false,
        'initialize_edge_tts_demos' => false,
        'edge_tts_demo_runner' => static function (array $command, int $timeout) use (&$called): array {
            $called = true;
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'must not run'];
        },
    ]);

    hub_test_assert(hub_pack_install_commands_allowed('cli')
        && !hub_pack_install_commands_allowed('fpm-fcgi')
        && $called === false
        && !isset($installed['edge_tts_demos'])
        && ($installed['service']['service_key'] ?? null) === 'edge-tts-main',
        'web installs must defer Edge TTS command execution to the CLI worker');
});

hub_test('Edge TTS failed idempotent reinstall preserves its existing row version and demos', function (): void {
    $db = hub_test_reset_db();
    $initial = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $db->exec("UPDATE services SET pack_version = 'prior-edge-version' WHERE id = " . (int)$initial['service']['id']);
    $before = hub_get_service_by_key($db, 'edge-tts-main');
    $current = HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current';
    mkdir($current, 0755, true);
    file_put_contents($current . '/prior.mp3', 'prior demos');
    $unused = [];

    try {
        hub_install_pack($db, 'edge-tts', [
            'idempotent' => true,
            'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($unused, static function (string $dir): void {}, 1),
        ]);
        throw new RuntimeException('failed idempotent Edge TTS reinstall must abort');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'edge_tts_demo_initialization_failed', 'failed idempotent Edge TTS reinstall must expose only the stable error');
    }

    hub_test_assert($before === hub_get_service_by_key($db, 'edge-tts-main')
        && (int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'edge-tts-main'")->fetchColumn() === 1
        && file_get_contents($current . '/prior.mp3') === 'prior demos',
        'failed idempotent Edge TTS reinstall must leave its service row, prior version, and published demos unchanged');
});

hub_test('Edge TTS incomplete cleanup preserves prior demos and service state', function (): void {
    $db = hub_test_reset_db();
    $initial = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);
    $db->exec("UPDATE services SET pack_version = 'prior-edge-version' WHERE id = " . (int)$initial['service']['id']);
    $before = hub_get_service_by_key($db, 'edge-tts-main');
    $current = HUB_DATA_DIR . '/results/edge-tts-demos/edge-tts-main/current';
    mkdir($current, 0755, true);
    file_put_contents($current . '/prior.mp3', 'prior demos');
    $voices = hub_test_edge_tts_demo_catalogue();
    $commands = [];

    try {
        hub_install_pack($db, 'edge-tts', [
            'idempotent' => true,
            'edge_tts_demo_runner' => hub_test_edge_tts_demo_runner($commands, static function (string $dir) use ($voices): void {
                hub_test_edge_tts_write_demo_output($dir, $voices);
            }, 0, true),
        ]);
        throw new RuntimeException('incomplete Edge TTS cleanup must abort install');
    } catch (RuntimeException $e) {
        hub_test_assert($e->getMessage() === 'edge_tts_demo_initialization_failed', 'incomplete Edge TTS cleanup must expose only the stable error');
    }

    hub_test_assert($before === hub_get_service_by_key($db, 'edge-tts-main')
        && file_get_contents($current . '/prior.mp3') === 'prior demos'
        && count(array_filter($commands, static fn (array $command): bool => ($command[1] ?? '') === 'run')) === 1,
        'unattested Edge TTS cleanup must not replace current or promote its existing service');
});

hub_test('Edge TTS artifact contract is exact', function (): void {
    $job = hub_pack_async_job_contract(hub_get_pack('edge-tts')['manifest'], 'synthesize');
    hub_test_assert(is_array($job) && ($job['artifact_contract'] ?? null) === [
        'artifacts' => [
            [
                'type' => 'generated_audio',
                'path' => 'generated_audio.mp3',
                'mime_types' => ['audio/mpeg'],
                'max_bytes' => 16777216,
                'audio' => [],
            ],
            [
                'type' => 'synthesis_metadata',
                'path' => 'synthesis_metadata.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 65536,
                'json' => [
                    'required_keys' => ['provider', 'client_version', 'voice', 'rate', 'volume', 'pitch', 'format', 'audio_bytes', 'elapsed_seconds', 'warnings'],
                ],
            ],
            [
                'type' => 'subtitle_vtt',
                'path' => 'subtitle.vtt',
                'mime_types' => ['text/plain', 'text/vtt'],
                'max_bytes' => 524288,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 524288, 'allow_empty' => false],
            ],
            [
                'type' => 'subtitle_srt',
                'path' => 'subtitle.srt',
                'mime_types' => ['text/plain', 'application/x-subrip', 'text/x-subrip', 'text/srt'],
                'max_bytes' => 524288,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 524288, 'allow_empty' => false],
            ],
            [
                'type' => 'speech_timeline',
                'path' => 'speech_timeline.json',
                'mime_types' => ['application/json'],
                'max_bytes' => 524288,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'json' => [
                    'required_keys' => ['version', 'unit', 'duration_ms', 'sentences', 'words'],
                ],
            ],
        ],
    ], 'Edge TTS must require the fixed MP3, metadata, and requested subtitle artifacts');
});

hub_test('Edge TTS documentation preserves the external CPU-only operator contract', function (): void {
    $root = (string)file_get_contents(HUB_ROOT . '/README.md');
    $packPath = HUB_ROOT . '/packs/edge-tts/README.md';
    $smokePath = HUB_ROOT . '/docs/operations/edge-tts-real-smoke.md';
    $designPath = HUB_ROOT . '/docs/superpowers/specs/2026-07-29-edge-tts-pack-design.md';
    hub_test_assert(is_file($packPath) && is_file($smokePath) && is_file($designPath), 'Edge TTS Pack, real-smoke, and design documentation must exist');
    $pack = (string)file_get_contents($packPath);
    $smoke = (string)file_get_contents($smokePath);
    $design = (string)file_get_contents($designPath);

    foreach (['edge_tts', 'speech.platform.bing.com:443', 'CPU', 'GPU', 'include_subtitles', 'subtitle.vtt', 'subtitle.srt', 'speech_timeline.json'] as $needle) {
        hub_test_assert(str_contains($root, $needle) && str_contains($pack, $needle), 'Edge TTS root and Pack documentation must state its bounded runtime contract: ' . $needle);
    }
    foreach (['include_subtitles', 'subtitle_vtt', 'subtitle.vtt', 'subtitle_srt', 'subtitle.srt', 'speech_timeline', 'speech_timeline.json', 'text/plain', 'text/vtt', 'application/x-subrip', 'text/x-subrip', 'text/srt'] as $needle) {
        hub_test_assert(str_contains($design, $needle), 'Edge TTS design must describe the active captions contract: ' . $needle);
    }
    hub_test_assert(str_contains($design, 'The shipped captions contract') && !str_contains($design, '## Phase B:'),
        'Edge TTS design must identify captions as shipped rather than a future phase');
    foreach (['admin/packs.php', 'task_status', 'task_result', 'generated_audio', 'task_artifacts_ack', 'ffprobe', 'SHA-256', 'gpu:0', 'AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Edge TTS real acceptance documentation must cover: ' . $needle);
    }
    foreach (['upstream_unavailable', 'edge_tts_timeout', 'edge_tts_failed', 'artifact_write_failed'] as $code) {
        hub_test_assert(str_contains($pack, $code), 'Edge TTS Pack documentation must describe bounded error code: ' . $code);
    }
    hub_test_assert(!str_contains($smoke, 'AIHUB_EDGE_TTS_TOKEN') && !str_contains($smoke, 'Bearer <TOKEN>'),
        'Edge TTS real acceptance documentation must use only the dedicated environment-held acceptance token');
});

hub_test('Edge TTS L5 documentation publishes its voice catalogue and intentional acceptance procedure', function (): void {
    $root = (string)file_get_contents(HUB_ROOT . '/README.md');
    $pack = (string)file_get_contents(HUB_ROOT . '/packs/edge-tts/README.md');
    $examples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    $smoke = (string)file_get_contents(HUB_ROOT . '/docs/operations/edge-tts-real-smoke.md');
    $catalogue = json_decode((string)file_get_contents(HUB_ROOT . '/packs/edge-tts/service/voice_catalog.json'), true);

    foreach ([$root, $pack, $examples] as $document) {
        foreach (['api.php?mode=edge_tts', 'include_subtitles', 'generated_audio', 'synthesis_metadata', 'subtitle_vtt', 'subtitle_srt', 'speech_timeline', 'task_status', 'task_result', 'task_artifacts_ack'] as $needle) {
            hub_test_assert(str_contains($document, $needle), 'Edge TTS public documentation must cover its async L5 artifact lifecycle: ' . $needle);
        }
        hub_test_assert(substr_count($document, 'Authorization: Bearer <TOKEN>') >= 2
            && str_contains($document, '?mode=edge_tts&voice=zh-TW-HsiaoChenNeural')
            && str_contains($document, 'Cache-Control: private, no-store')
            && str_contains($document, '相對') && str_contains($document, '任意 URL')
            && str_contains($document, '不是 Edge 上游的聲線風格或韻律控制'),
            'Edge TTS documentation must retrieve a relative demo URL with the same token and state its privacy and metadata limits');
    }
    hub_test_assert(str_contains($examples, '## Edge TTS 聲線清單與非同步合成'),
        'Edge TTS API examples must retain a Traditional Chinese L5 section title');
    foreach (['GET', 'demo_url', 'display_name', 'locale', 'gender', 'memo', 'demo_text', 'voice_catalog.json', 'edge_tts_async_complete', 'external_acceptance_requires_script'] as $needle) {
        hub_test_assert(str_contains($pack, $needle) || str_contains($examples, $needle) || str_contains($root, $needle), 'Edge TTS documentation must state the verified voice-list and L5 readiness contract: ' . $needle);
    }
    hub_test_assert(is_array($catalogue) && count($catalogue) === 14, 'Edge TTS documentation test must use the canonical fourteen-profile catalogue');
    foreach ($catalogue as $voice) {
        hub_test_assert(is_array($voice) && str_contains($pack, (string)$voice['id'])
            && str_contains($pack, (string)$voice['display_name']) && str_contains($pack, (string)$voice['memo']),
            'Edge TTS Pack documentation must make every canonical profile, label, and memo discoverable');
    }
    foreach (['AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL', 'AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN', 'php scripts/edge_tts_acceptance.php', 'cluster_api.php', 'non-redirect', 'CPU', 'GPU', '一般 `scripts/run_tests.php` 不會執行外部測試'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Edge TTS real acceptance procedure must state: ' . $needle);
    }
    foreach ([
        'generated_audio' => ['audio/mpeg'],
        'synthesis_metadata' => ['application/json'],
        'subtitle_vtt' => ['text/vtt', 'text/plain'],
        'subtitle_srt' => ['application/x-subrip', 'text/plain', 'text/x-subrip', 'text/srt'],
        'speech_timeline' => ['application/json'],
    ] as $artifactType => $mimeTypes) {
        hub_test_assert(str_contains($smoke, $artifactType), 'Edge TTS real acceptance procedure must name artifact type: ' . $artifactType);
        foreach ($mimeTypes as $mimeType) {
            hub_test_assert(str_contains($smoke, $mimeType), 'Edge TTS real acceptance procedure must document supported MIME type: ' . $mimeType);
        }
    }
    foreach (['HUB_DATA_DIR/results/edge-tts-demos/<service-key>/current', 'atomically publish', '所有生成皆失敗', 'install 會失敗'] as $needle) {
        hub_test_assert(str_contains($pack, $needle), 'Edge TTS Pack documentation must explain verified atomic demo publication: ' . $needle);
    }
    foreach (['admin/pack_readiness.php', 'admin/packs.php', 'L5 contract', 'benchmark', 'installed/enabled/running'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Edge TTS readiness documentation must separate stored checks from service state: ' . $needle);
    }
    foreach (['edge_tts_acceptance_config_invalid', 'edge_tts_acceptance_list_demo_failed', 'edge_tts_acceptance_submission_failed', 'edge_tts_acceptance_task_failed', 'edge_tts_acceptance_artifact_invalid'] as $code) {
        hub_test_assert(str_contains($smoke, $code), 'Edge TTS real acceptance procedure must list bounded failure code: ' . $code);
    }
    foreach (['scheduler-managed', 'command queue', 'task queue', 'scripts/command_worker.php', 'scripts/task_worker.php', '不要手動執行', '無關工作'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Edge TTS real acceptance procedure must preserve scheduler-only queue safety: ' . $needle);
    }
    foreach ([$pack, $smoke] as $document) {
        foreach (['container-local', 'fail-closed', 'NET_ADMIN', 'entrypoint', 'non-root', 'host firewall', 'Docker daemon', 'Docker network'] as $needle) {
            hub_test_assert(str_contains($document, $needle), 'Edge TTS security documentation must describe its container-local bootstrap-only egress boundary: ' . $needle);
        }
    }
});
