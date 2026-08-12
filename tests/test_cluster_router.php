<?php
declare(strict_types=1);

$hubVoxCpm2ClusterAcceptance = HUB_ROOT . '/scripts/voxcpm2_cluster_acceptance.php';
if (is_file($hubVoxCpm2ClusterAcceptance)) {
    require_once $hubVoxCpm2ClusterAcceptance;
}

function hub_test_with_cluster_secret(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_SECRET_KEY');
        } else {
            putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
        }
    }
}

hub_test('cluster router creates and reuses a local secret without an environment variable', function (): void {
    $path = hub_cluster_secret_key_path();
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    @unlink($path);
    putenv('AIHUB_CLUSTER_SECRET_KEY');

    try {
        $first = hub_cluster_secret_key();
        $second = hub_cluster_secret_key();

        hub_test_assert(is_file($path), 'cluster secret must be persisted locally');
        hub_test_assert($first === $second && strlen($first) === 32, 'local cluster secret must be stable and 32 bytes');
        if (DIRECTORY_SEPARATOR === '/') {
            hub_test_assert((fileperms($path) & 0777) === 0640, 'local cluster secret must permit the Hub runtime group to read it');
        }
    } finally {
        @unlink($path);
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_SECRET_KEY');
        } else {
            putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
        }
    }
});

hub_test('cluster router migrates a legacy environment secret into local Hub data', function (): void {
    $path = hub_cluster_secret_key_path();
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    $legacy = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';
    @unlink($path);
    putenv('AIHUB_CLUSTER_SECRET_KEY=' . $legacy);

    try {
        $first = hub_cluster_secret_key();
        putenv('AIHUB_CLUSTER_SECRET_KEY');
        $second = hub_cluster_secret_key();

        hub_test_assert(is_file($path), 'legacy cluster secret must migrate into local Hub data');
        hub_test_assert($first === $second && bin2hex($first) === $legacy, 'migrated cluster secret must remain available without its environment variable');
    } finally {
        @unlink($path);
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_SECRET_KEY');
        } else {
            putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
        }
    }
});

hub_test('cluster router reads an existing local secret without write access', function (): void {
    $path = hub_cluster_secret_key_path();
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    $secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
    @unlink($path);
    file_put_contents($path, $secret . PHP_EOL);
    chmod($path, 0440);
    putenv('AIHUB_CLUSTER_SECRET_KEY');

    try {
        hub_test_assert(bin2hex(hub_cluster_secret_key()) === $secret, 'existing read-only cluster secret must remain usable');
    } finally {
        @chmod($path, 0640);
        @unlink($path);
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_SECRET_KEY');
        } else {
            putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
        }
    }
});

function hub_test_cluster_station_pairing(): array
{
    return [
        'station_key' => 'taipei_gpu_1',
        'display_name' => 'Taipei GPU 1',
        'public_base_url' => 'https://station.example/aihub',
        'internal_base_url' => 'https://station.internal:8080/aihub',
        'priority' => 7,
        'enabled' => true,
        'station_token' => '3wa_live_station_secret',
        'modes' => ['vision', 'tts'],
    ];
}

function hub_test_cluster_station_fixture(array $overrides = []): array
{
    return array_replace([
        'id' => 1,
        'priority' => 10,
        'enabled' => true,
        'fresh' => true,
        'modes' => ['vision'],
        'gpu_free_vram_mb' => 4096,
        'active_gpu_leases' => 0,
        'queued_jobs' => 0,
    ], $overrides);
}

function hub_test_cluster_router_customer_token(PDO $db, array $modes): array
{
    $memberId = hub_create_api_member($db, 'Cluster Router Customer');
    $token = hub_create_api_token($db, $memberId, 'cluster router token', null, null);
    foreach ($modes as $mode) {
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], $mode);
    }

    return $token;
}

function hub_test_cluster_router_station(PDO $db, array $overrides = []): array
{
    $pairing = array_replace(hub_test_cluster_station_pairing(), $overrides);
    $stationId = hub_cluster_save_paired_station($db, $pairing);
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('cluster router station missing');
    }

    return $station;
}

function hub_test_cluster_router_request(string $token, array $overrides = []): array
{
    return array_replace([
        'bearer_token' => $token,
        'client_ip' => '203.0.113.10',
        'method' => 'POST',
        'raw_body' => '{"text":"hello"}',
        'query' => [],
        'headers' => ['Content-Type' => 'application/json'],
        'files' => [],
        'request_uri' => '/cluster_api.php?mode=vision',
    ], $overrides);
}

function hub_test_cluster_router_async_route(PDO $db, array $stationOverrides = []): array
{
    $station = hub_test_cluster_router_station($db, $stationOverrides);
    $customer = hub_test_cluster_router_customer_token($db, []);
    $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
    $routeId = 'route_' . str_repeat('a', 32);
    $db->prepare(
        "INSERT INTO cluster_routes
            (route_id, station_id, member_id, token_id, mode, remote_task_id, is_async, state, created_at, updated_at)
         VALUES
            (:route_id, :station_id, :member_id, :token_id, 'vision', 'remote_task_42', 1, 'active', :created_at, :updated_at)"
    )->execute([
        ':route_id' => $routeId,
        ':station_id' => (int)$station['id'],
        ':member_id' => $memberId,
        ':token_id' => (int)$customer['token_id'],
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);

    return ['station' => $station, 'customer' => $customer, 'member_id' => $memberId, 'route_id' => $routeId];
}

function hub_test_cluster_voice_profile_route(PDO $db, array $stationOverrides = [], string $remoteTaskId = '42'): array
{
    $station = hub_test_cluster_router_station($db, array_replace([
        'modes' => ['voice_generate'],
    ], $stationOverrides));
    $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
    $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
    $routeId = hub_cluster_router_admit_route($db, $station, [
        'member_id' => $memberId,
        'token_id' => (int)$customer['token_id'],
    ], 'voice_generate', true, true, 'profile_prepare');
    if (!is_string($routeId)) {
        throw new RuntimeException('cluster voice profile route admission failed');
    }
    hub_cluster_rewrite_async_response($db, [
        'route_id' => $routeId,
        'station_id' => (int)$station['id'],
    ], ['ok' => true, 'task_id' => $remoteTaskId], 'cluster_api.php');

    return ['station' => $station, 'customer' => $customer, 'member_id' => $memberId, 'route_id' => $routeId];
}

function hub_test_cluster_voice_profile_status_payload(array $overrides = []): array
{
    return array_replace([
        'ok' => true,
        'task_status' => 'success',
        'profile_status' => 'active',
        'transcription_status' => 'ready',
        'transcription_error' => null,
        'transcript_confirmed' => true,
        'prompt_text_confirmed_at' => '2026-07-31 12:00:00',
        'profile_name' => 'Cluster profile',
        'language' => 'en',
        'consent_type' => 'self_recorded',
        'reference_audio_sha256' => str_repeat('a', 64),
        'created_at' => '2026-07-31 11:00:00',
        'updated_at' => '2026-07-31 12:00:00',
    ], $overrides);
}

function hub_test_cluster_voice_profile_confirmation_payload(string $remoteTaskId, string $promptText, array $overrides = []): array
{
    return array_replace(hub_test_cluster_voice_profile_status_payload(), [
        'voice_profile_task_id' => $remoteTaskId,
        'prompt_text_sha256' => hash('sha256', $promptText),
    ], $overrides);
}

function hub_test_cluster_voxcpm2_canonical_json(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }
        ksort($item, SORT_STRING);
        foreach ($item as $key => $nested) {
            $item[$key] = $normalize($nested);
        }

        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
    );
}

function hub_test_cluster_voxcpm2_runner_metadata(
    string $referenceSha256,
    string $promptSha256,
    string $targetText,
): array {
    $normalized = preg_replace('/(*UCP)\s+/u', ' ', trim($targetText));
    if (!is_string($normalized) || $normalized === '') {
        throw new RuntimeException('Invalid Router-backed VoxCPM2 target fixture.');
    }
    $chunkId = 'chunk-0001';
    $seedSha256 = hash('sha256', '42' . $chunkId);
    $seed = (int)(hexdec(substr($seedSha256, 8, 8)) % 2147483648);
    $planCore = [
        'normalization' => 'semantic-v1',
        'normalized_input' => $normalized,
        'max_chunk_chars' => 240,
        'task_seed' => 42,
        'seed_policy' => 'derived_per_chunk',
        'chunks' => [[
            'id' => $chunkId,
            'text' => $normalized,
            'text_sha256' => hash('sha256', $normalized),
            'seed' => $seed,
            'seed_sha256' => $seedSha256,
        ]],
    ];
    $voiceCore = [
        'mode' => 'ultimate_clone',
        'control' => '',
        'reference_audio_sha256' => $referenceSha256,
        'prompt_text_sha256' => $promptSha256,
        'container_path' => '/data/voice_profiles/reference.wav',
    ];

    return [
        'normalized_input' => $normalized,
        'plan' => $planCore + [
            'plan_sha256' => hash('sha256', hub_test_cluster_voxcpm2_canonical_json($planCore)),
        ],
        'model' => [
            'model' => '/models/voxcpm2/model',
            'label' => 'VoxCPM2',
            'version' => '2.0.3',
            'sample_rate' => 48000,
        ],
        'voice_context' => $voiceCore + [
            'sha256' => hash('sha256', hub_test_cluster_voxcpm2_canonical_json($voiceCore)),
        ],
        'controls' => [
            'mode' => 'ultimate_clone',
            'seed_policy' => 'derived_per_chunk',
            'task_seed' => 42,
        ],
        'chunks' => [[
            'id' => $chunkId,
            'seed' => $seed,
            'seed_sha256' => $seedSha256,
            'attempts' => 1,
            'duration_frames' => 12000,
            'duration_seconds' => 0.25,
            'peak_gain' => 1.0,
            'reused_checkpoint' => false,
            'action' => 'direct_concat',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 0,
        ]],
        'final_format' => [
            'mime_type' => 'audio/wav',
            'sample_rate' => 48000,
            'channels' => 1,
            'frames' => 12000,
        ],
        'loudness' => ['passes' => 1, 'target_lufs' => -16.0, 'gain' => 1.0],
        'timeline' => [[
            'chunk_id' => $chunkId,
            'start_frame' => 0,
            'end_frame' => 12000,
            'sample_rate' => 48000,
        ]],
        'device' => ['type' => 'cuda', 'real_inference' => true],
    ];
}

function hub_test_cluster_voice_profile_synthesis_without_operation(string $profileMode): void
{
    hub_test_with_cluster_secret(function () use ($profileMode): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_' . $profileMode,
            'station_token' => 'profile_mode_token',
        ], '97531');
        $request = $profileMode === 'clone'
            ? hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=profile-mode'],
                'raw_body' => '',
                'post' => ['mode' => 'clone', 'voice_profile_task_id' => $fixture['route_id'], 'text' => 'clone me'],
                'files' => [],
            ])
            : hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                'headers' => ['Content-Type' => 'application/json'],
                'raw_body' => '{"mode":"ultimate_clone","voice_profile_task_id":"' . $fixture['route_id'] . '","text":"ultimate me"}',
            ]);
        $childTaskId = $profileMode === 'clone' ? '86420' : '86421';
        $calls = 0;

        $response = hub_cluster_dispatch($db, 'voice_generate', $request, [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                'id' => (int)$fixture['station']['id'],
                'station_key' => 'profile_' . $profileMode,
                'modes' => ['voice_generate'],
            ])],
            'transport' => static function (array $request) use (&$calls, $profileMode, $childTaskId): array {
                $calls++;
                hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer profile_mode_token', 'omitted-operation synthesis must use the pinned profile station');
                if ($profileMode === 'clone') {
                    hub_test_assert(($request['form']['post'] ?? null) === [
                        'mode' => 'clone',
                        'voice_profile_task_id' => '97531',
                        'text' => 'clone me',
                    ], 'omitted-operation clone must relay only the numeric child profile task ID');
                } else {
                    hub_test_assert($request['body'] === '{"mode":"ultimate_clone","voice_profile_task_id":"97531","text":"ultimate me"}', 'omitted-operation ultimate clone must replace only the profile reference');
                }

                return hub_gateway_json(200, ['ok' => true, 'task_id' => $childTaskId]);
            },
        ]);
        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);

        hub_test_assert($response['status'] === 200
            && $calls === 1
            && hub_cluster_router_profile_sensitive_route_id((string)($payload['task_id'] ?? '')), 'valid omitted-operation ' . $profileMode . ' synthesis must return a profile-sensitive opaque async route');
        hub_test_assert(!str_contains($response['body'], $childTaskId)
            && !str_contains($response['body'], '97531'), 'omitted-operation synthesis response must not leak child task IDs');
    });
}

function hub_test_with_cluster_router_env(string $key, string $value, callable $fn): void
{
    $previous = getenv($key);
    putenv($key . '=' . $value);

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $previous);
        }
    }
}

function hub_test_cluster_publish_mode(PDO $db, string $mode, bool $running = true): void
{
    $existingMode = hub_get_service_by_mode($db, 'hello') === null ? $mode : 'hello';
    $db->prepare(
        'UPDATE services
         SET mode = :mode, install_status = :install_status, enabled = 1,
             runtime_status = :runtime_status, status = :status, updated_at = :updated_at
         WHERE mode = :existing_mode'
    )->execute([
        ':mode' => $mode,
        ':install_status' => 'installed',
        ':runtime_status' => $running ? 'running' : 'stopped',
        ':status' => $running ? 'running' : 'stopped',
        ':updated_at' => hub_now(),
        ':existing_mode' => $existingMode,
    ]);
}

function hub_test_with_cluster_http_internal(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL');
    putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL=1');

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL');
        } else {
            putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL=' . $previous);
        }
    }
}

function hub_test_with_cluster_pair_url(callable $fn): void
{
    $keys = ['HTTPS', 'HTTP_HOST', 'SCRIPT_NAME', 'SERVER_NAME', 'SERVER_PORT'];
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
    }
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'station.example';
    $_SERVER['SCRIPT_NAME'] = '/cluster_pair.php';

    try {
        $fn();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
}

hub_test('cluster pairing descriptor normalizes the application root path', function (): void {
    hub_test_with_cluster_pair_url(function (): void {
        $db = hub_test_reset_db();
        $descriptor = hub_cluster_node_pairing_descriptor($db);

        hub_test_assert(
            ($descriptor['public_base_url'] ?? '') === 'https://station.example/',
            'pairing descriptor must emit a validator-safe application root URL'
        );
    });
});

hub_test('cluster router gives real audio requests their service timeout plus cleanup headroom', function (): void {
    hub_test_assert(hub_cluster_proxy_timeout_sec('tts') === 210, 'TTS proxy timeout must cover 180 second inference plus cleanup');
    hub_test_assert(hub_cluster_proxy_timeout_sec('asr') === 210, 'ASR proxy timeout must cover cold model inference plus cleanup');
    hub_test_assert(hub_cluster_proxy_timeout_sec('hello') === 60, 'ordinary proxy timeout must stay bounded');
    hub_test_assert(hub_cluster_proxy_stale_after_seconds() === 240, 'route reaping must not preempt a live TTS proxy');
});

hub_test('cluster router keeps a cleanly unloaded on-demand TTS pack in its published inventory', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-on-demand-inventory']);
    $db->prepare(
        "UPDATE services
         SET enabled = 1, status = 'stopped', runtime_status = 'stopped'
         WHERE id = :id"
    )->execute([':id' => (int)$installed['service']['id']]);

    hub_test_assert(in_array('tts', hub_cluster_node_published_modes($db), true), 'a cleanly stopped on-demand TTS pack must stay available for Router wake-up');
    hub_cluster_node_configure($db, true, ['tts']);
    hub_test_assert(in_array('tts', hub_cluster_node_selected_published_modes($db), true), 'selected on-demand TTS must remain in the node manifest after idle unload');

    $db->prepare("UPDATE services SET status = 'failed', runtime_status = 'failed' WHERE id = :id")
        ->execute([':id' => (int)$installed['service']['id']]);
    hub_test_assert(!in_array('tts', hub_cluster_node_published_modes($db), true), 'failed on-demand TTS must not remain published');
});

hub_test('cluster child can select and grant an installed stopped async Pack mode', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-stopped-async-child']);
        $db->prepare(
            "UPDATE services
             SET mode = 'tts', install_status = 'installed', enabled = 1,
                 status = 'stopped', runtime_status = 'stopped'
             WHERE id = :id"
        )->execute([':id' => (int)$installed['service']['id']]);

        hub_test_assert(in_array('voice_generate', hub_cluster_node_published_modes($db), true), 'installed stopped async Pack mode must be available to child selection');
        $configured = hub_cluster_node_configure($db, true, ['voice_generate']);
        $permissions = array_column(hub_list_api_token_permissions($db, hub_cluster_node_token_id($db)), 'mode');
        sort($permissions, SORT_STRING);

        hub_test_assert(($configured['modes'] ?? null) === ['voice_generate'], 'selected async Pack mode must be published by the child');
        hub_test_assert($permissions === ['cluster_status', 'voice_generate'], 'node Token must receive only status and the selected async Pack mode');

        hub_cluster_node_configure($db, true, []);
        $permissions = array_column(hub_list_api_token_permissions($db, hub_cluster_node_token_id($db)), 'mode');
        hub_test_assert(($configured['modes'] ?? null) !== [] && $permissions === ['cluster_status'], 'installed async Pack mode must not be silently published when unselected');
    });
});

hub_test('cluster router pins sync TTS artifacts to the submitting token and rewrites both artifact links', function (): void {
    $db = hub_test_reset_db();
    $station = hub_test_cluster_router_station($db);
    $customer = hub_test_cluster_router_customer_token($db, ['tts']);
    $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
    $routeId = 'route_' . str_repeat('b', 32);
    $db->prepare(
        "INSERT INTO cluster_routes
            (route_id, station_id, member_id, token_id, mode, is_async, state, created_at, updated_at)
         VALUES
            (:route_id, :station_id, :member_id, :token_id, 'tts', 0, 'completed', :created_at, :updated_at)"
    )->execute([
        ':route_id' => $routeId,
        ':station_id' => (int)$station['id'],
        ':member_id' => $memberId,
        ':token_id' => (int)$customer['token_id'],
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);

    $rewritten = hub_cluster_rewrite_tts_response($db, ['route_id' => $routeId], [
        'success' => true,
        'artifact_url' => '/artifacts/tts_012345abcdef.wav',
        'manifest' => '/artifacts/tts_012345abcdef.json',
    ], 'cluster_api.php');

    $expected = 'cluster_api.php?mode=cluster_tts_artifact&route_id=' . $routeId . '&file=tts_012345abcdef.wav';
    hub_test_assert(($rewritten['artifact_url'] ?? '') === $expected, 'TTS audio URL must stay on the Router and carry the route mapping');
    hub_test_assert(($rewritten['manifest'] ?? '') === str_replace('.wav', '.json', $expected), 'TTS manifest URL must use the same constrained Router relay');
    $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $routeId . "' ORDER BY remote_artifact_id")->fetchAll(PDO::FETCH_COLUMN);
    hub_test_assert($mapped === ['tts_012345abcdef.json', 'tts_012345abcdef.wav'], 'Router must pin only the response artifact filenames');

    $auth = hub_authenticate_api_token($db, '203.0.113.10', (string)$customer['plain_token']);
    hub_test_assert(hub_cluster_get_tts_artifact_route_for_customer($db, $routeId, (array)$auth['context']) !== null, 'submitting token must retain its TTS artifact route');
    $other = hub_test_cluster_router_customer_token($db, ['tts']);
    $otherAuth = hub_authenticate_api_token($db, '203.0.113.10', (string)$other['plain_token']);
    hub_test_assert(hub_cluster_get_tts_artifact_route_for_customer($db, $routeId, (array)$otherAuth['context']) === null, 'other customer tokens must not read TTS artifact routes');
    hub_test_assert(hub_cluster_tts_artifact_filename('tts_012345abcdef.wav') === 'tts_012345abcdef.wav', 'TTS WAV artifact names must be accepted');
    hub_test_assert(hub_cluster_tts_artifact_filename('../tts_012345abcdef.wav') === null, 'TTS artifact traversal must be rejected');
});

hub_test('cluster router relays a pinned sync TTS artifact through the child control plane', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $customer = hub_test_cluster_router_customer_token($db, ['tts']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = 'route_' . str_repeat('c', 32);
        $file = 'tts_abcdef012345.wav';
        $db->prepare(
            "INSERT INTO cluster_routes
                (route_id, station_id, member_id, token_id, mode, is_async, state, created_at, updated_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, 'tts', 0, 'completed', :created_at, :updated_at)"
        )->execute([
            ':route_id' => $routeId,
            ':station_id' => (int)$station['id'],
            ':member_id' => $memberId,
            ':token_id' => (int)$customer['token_id'],
            ':created_at' => hub_now(),
            ':updated_at' => hub_now(),
        ]);
        $db->prepare(
            'INSERT INTO cluster_route_artifacts (route_id, remote_artifact_id, created_at) VALUES (:route_id, :file, :created_at)'
        )->execute([':route_id' => $routeId, ':file' => $file, ':created_at' => hub_now()]);
        $requests = [];

        $response = hub_cluster_dispatch_followup($db, 'cluster_tts_artifact', [
            'bearer_token' => (string)$customer['plain_token'],
            'client_ip' => '203.0.113.10',
            'method' => 'GET',
            'query' => ['route_id' => $routeId, 'file' => $file],
        ], static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return [
                'status' => 200,
                'headers' => ['Content-Type: audio/wav'],
                'body' => 'RIFFdemoWAVE',
            ];
        });

        hub_test_assert($response['status'] === 200 && ($response['body'] ?? '') === 'RIFFdemoWAVE', 'Router must return the child WAV bytes without exposing station URLs');
        hub_test_assert(count($requests) === 1 && ($requests[0]['url'] ?? '') === 'https://station.internal:8080/aihub/cluster_tts_artifact.php', 'TTS artifacts must use the constrained child relay endpoint');
        hub_test_assert(($requests[0]['query'] ?? null) === ['file' => $file], 'TTS artifact relay must send only the pinned filename');
    });
});

hub_test('cluster router migration creates all persistence tables', function (): void {
    $db = hub_test_reset_db();
    $tables = array_fill_keys(
        $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN),
        true
    );

    foreach (['cluster_stations', 'cluster_routes', 'cluster_route_accesses', 'cluster_route_artifacts'] as $table) {
        hub_test_assert(isset($tables[$table]), 'cluster router table missing: ' . $table);
    }
    $routeColumns = array_column($db->query('PRAGMA table_info(cluster_routes)')->fetchAll(), 'name');
    hub_test_assert(in_array('route_role', $routeColumns, true), 'cluster_routes.route_role must exist');
    $accessIndexes = array_column($db->query('PRAGMA index_list(cluster_route_accesses)')->fetchAll(), 'name');
    foreach (['idx_cluster_route_accesses_station_usage', 'idx_cluster_route_accesses_mode_usage'] as $index) {
        hub_test_assert(in_array($index, $accessIndexes, true), 'cluster usage index missing: ' . $index);
    }
});

hub_test('cluster router rejects NULL route IDs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());

        hub_test_assert(hub_test_throws(static function () use ($db, $stationId): void {
            $db->prepare(
                'INSERT INTO cluster_routes (route_id, station_id, mode, state, created_at, updated_at)
                 VALUES (:route_id, :station_id, :mode, :state, :created_at, :updated_at)'
            )->execute([
                ':route_id' => null,
                ':station_id' => $stationId,
                ':mode' => 'vision',
                ':state' => 'created',
                ':created_at' => hub_now(),
                ':updated_at' => hub_now(),
            ]);
        }), 'cluster route NULL ID must reject');
    });
});

hub_test('cluster router upgrades legacy nullable route IDs without losing valid routes or indexes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('DROP TABLE cluster_routes');
        $db->exec(<<<'SQL'
CREATE TABLE cluster_routes (
    route_id TEXT PRIMARY KEY,
    station_id INTEGER NOT NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NOT NULL,
    remote_task_id TEXT NULL,
    is_async INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL,
    remote_status TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);
SQL);
        $db->exec('CREATE INDEX idx_cluster_routes_legacy_remote_task ON cluster_routes(remote_task_id)');
        $db->prepare(
            'INSERT INTO cluster_routes (route_id, station_id, mode, remote_task_id, state, created_at, updated_at)
             VALUES (:route_id, :station_id, :mode, :remote_task_id, :state, :created_at, :updated_at)'
        )->execute([
            ':route_id' => 'route_legacy_1',
            ':station_id' => $stationId,
            ':mode' => 'vision',
            ':remote_task_id' => 'remote_legacy_1',
            ':state' => 'created',
            ':created_at' => hub_now(),
            ':updated_at' => hub_now(),
        ]);
        $db->exec('PRAGMA foreign_keys = ON');

        hub_migrate($db);
        hub_migrate($db);

        $columns = array_column($db->query('PRAGMA table_info(cluster_routes)')->fetchAll(), null, 'name');
        hub_test_assert((int)$columns['route_id']['notnull'] === 1, 'legacy cluster route ID must become NOT NULL');
        hub_test_assert((string)$db->query("SELECT remote_task_id FROM cluster_routes WHERE route_id = 'route_legacy_1'")->fetchColumn() === 'remote_legacy_1', 'legacy valid route must survive rebuild');
        hub_test_assert((string)$db->query("SELECT route_role FROM cluster_routes WHERE route_id = 'route_legacy_1'")->fetchColumn() === 'task', 'legacy routes must migrate to the non-profile task role');
        $indexes = array_column($db->query('PRAGMA index_list(cluster_routes)')->fetchAll(), 'name');
        hub_test_assert(in_array('idx_cluster_routes_legacy_remote_task', $indexes, true), 'legacy route index must survive rebuild');
        hub_test_assert(hub_test_throws(static function () use ($db, $stationId): void {
            $db->prepare(
                'INSERT INTO cluster_routes (route_id, station_id, mode, state, created_at, updated_at)
                 VALUES (NULL, :station_id, :mode, :state, :created_at, :updated_at)'
            )->execute([
                ':station_id' => $stationId,
                ':mode' => 'vision',
                ':state' => 'created',
                ':created_at' => hub_now(),
                ':updated_at' => hub_now(),
            ]);
        }), 'upgraded cluster route NULL ID must reject');
    });
});

hub_test('cluster router encrypts station tokens at rest and decrypts internal records', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $stored = $db->query('SELECT * FROM cluster_stations WHERE id = ' . (int)$stationId)->fetch();

        hub_test_assert($stored !== false, 'paired station row missing');
        hub_test_assert(!str_contains(implode(' ', array_map('strval', $stored)), '3wa_live_station_secret'), 'raw station token must not be stored');
        hub_test_assert(hub_cluster_station_token($stored) === '3wa_live_station_secret', 'internal station token must decrypt');

        $listed = hub_cluster_list_stations($db);
        hub_test_assert(count($listed) === 1, 'paired station must be listed');
        foreach (['token_ciphertext', 'token_iv', 'token_tag', 'station_token'] as $secretField) {
            hub_test_assert(!array_key_exists($secretField, $listed[0]), 'station list must hide ' . $secretField);
        }
    });
});

hub_test('cluster router deletes a station with only its dependent routes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db);
        $stationId = (int)$fixture['station']['id'];
        $otherStation = hub_test_cluster_router_station($db, [
            'station_key' => 'taipei_gpu_2',
            'public_base_url' => 'https://station-two.example/aihub',
            'station_token' => '3wa_live_station_secret_two',
        ]);

        hub_cluster_delete_station($db, $stationId);

        hub_test_assert(hub_cluster_get_station($db, $stationId) === null, 'deleted station must not remain');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM cluster_routes WHERE station_id = ' . $stationId)->fetchColumn() === 0, 'station routes must cascade on delete');
        hub_test_assert(hub_cluster_get_station($db, (int)$otherStation['id']) !== null, 'deleting one station must not affect another');
    });
});

hub_test('cluster router rejects an invalid secret and invalid station base URLs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        @unlink(hub_cluster_secret_key_path());
        putenv('AIHUB_CLUSTER_SECRET_KEY=not-a-valid-key');
        hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_secret_key()), 'invalid cluster secret must reject');
        hub_test_assert(!is_file(hub_cluster_secret_key_path()), 'invalid legacy secret must not leave an unusable local key file');

        putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
        hub_test_assert(
            hub_cluster_validate_station_base_url('https://station.example/aihub') === 'https://station.example/aihub/',
            'station base URL must normalize its path trailing slash'
        );
        hub_test_assert(
            hub_cluster_pairing_request_url(parse_url('https://station.example/aihub/cluster_pair.php')) === 'https://station.example/aihub/cluster_pair.php',
            'pairing request must retain its verified cluster_pair endpoint'
        );
        foreach ([
            'ftp://station.example',
            'https://user:pass@station.example',
            'https://station.example/path#fragment',
            'https://station.example/path?query=1',
            'https:///missing-host',
        ] as $value) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($value)), 'invalid station base URL must reject: ' . $value);
        }
    });
});

hub_test('cluster router permits private literal HTTP stations by default', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_assert(
            hub_cluster_validate_station_base_url('http://192.168.1.106/aihub') === 'http://192.168.1.106/aihub/',
            'private LAN HTTP must be allowed by default'
        );
        hub_test_assert(
            hub_cluster_validate_station_base_url('http://127.0.0.1/aihub') === 'http://127.0.0.1/aihub/',
            'literal loopback HTTP must be allowed by default'
        );
        foreach (['http://203.0.113.10/aihub', 'http://169.254.169.254/aihub', 'http://localhost/aihub', 'http://station.example/aihub'] as $url) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($url)), 'default internal HTTP policy must reject non-private targets: ' . $url);
        }

        $db = hub_test_reset_db();
        $station = hub_cluster_import_pairing_link(
            $db,
            'http://192.168.1.106/cluster_pair.php#invite=' . str_repeat('e', 64),
            static fn (): array => ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)]
        );
        hub_test_assert((int)($station['id'] ?? 0) > 0, 'private HTTP pairing import must use the same station URL validation');
    });
});

hub_test('cluster router rejects invalid explicit ports in station and pairing URLs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        foreach ([
            'https://station.example:0/aihub',
            'https://station.example:65536/aihub',
        ] as $url) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($url)), 'invalid station port must reject: ' . $url);
        }

        $db = hub_test_reset_db();
        $invite = str_repeat('d', 64);
        foreach ([0, 65536] as $port) {
            $requested = false;
            $rejected = hub_test_throws(function () use ($db, $port, $invite, &$requested): array {
                return hub_cluster_import_pairing_link($db, 'https://station.example:' . $port . '/cluster_pair.php#invite=' . $invite, static function () use (&$requested): array {
                    $requested = true;
                    return ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)];
                });
            });
            hub_test_assert($rejected && !$requested, 'invalid pairing port must reject before requesting: ' . $port);
        }
    });
});

hub_test('cluster router prefers an internal station base URL for requests', function (): void {
    hub_test_assert(
        hub_cluster_station_request_base_url([
            'public_base_url' => 'https://station.example/public/',
            'internal_base_url' => 'https://station.internal:8080/private/',
        ]) === 'https://station.internal:8080/private/',
        'internal station URL must be preferred for requests'
    );
    hub_test_assert(
        hub_cluster_station_request_base_url(['public_base_url' => 'https://station.example/public']) === 'https://station.example/public/',
        'public station URL must be used when internal URL is empty'
    );
});

hub_test('cluster router creates only hashed node pairing invitations', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '1');
    $before = time();
    $invitation = hub_cluster_create_pair_invitation($db);
    $after = time();

    hub_test_assert(preg_match('/^[a-f0-9]{64}$/', (string)($invitation['invite'] ?? '')) === 1, 'pair invitation must be 64 hex chars');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH') === hash('sha256', $invitation['invite']), 'only pair invitation hash must be stored');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH') !== $invitation['invite'], 'raw invitation must not be stored');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $invitation['expires_at'], 'pair invitation expiry must use the node setting');
    $expiresAt = strtotime((string)($invitation['expires_at'] ?? ''));
    hub_test_assert($expiresAt !== false && $expiresAt >= $before + 899 && $expiresAt <= $after + 901, 'pair invitation must expire in 15 minutes');

    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '0');
    hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_create_pair_invitation($db)), 'disabled node must not create pair invitation');
});

hub_test('cluster router pairing import sends invites only in headers and saves encrypted station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_SITE_TITLE', str_repeat('Router ', 30));
        $invite = str_repeat('a', 64);
        $seenRequest = [];
        $station = hub_cluster_import_pairing_link(
            $db,
            'https://station.example:8443/cluster_pair.php#invite=' . $invite,
            static function (array $request) use (&$seenRequest): array {
                $seenRequest = $request;
                return [
                    'status' => 200,
                    'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR),
                ];
            }
        );

        hub_test_assert(($seenRequest['url'] ?? '') === 'https://station.example:8443/cluster_pair.php', 'pair requester URL must omit invite fragment and retain port');
        hub_test_assert(!str_contains((string)($seenRequest['url'] ?? ''), $invite), 'pair requester URL must not expose invite');
        hub_test_assert(!str_contains((string)($seenRequest['url'] ?? ''), '?'), 'pair requester URL must not contain a query');
        hub_test_assert(($seenRequest['body'] ?? null) === '' && ($seenRequest['headers']['Content-Length'] ?? '') === '0', 'pair requester must send an explicit empty body for IIS compatibility');
        hub_test_assert(($seenRequest['headers']['X-3waAIHub-Pair-Invite'] ?? '') === $invite, 'pair requester must receive invite header');
        hub_test_assert(strlen((string)($seenRequest['headers']['X-3waAIHub-Router-Name'] ?? '')) <= 120, 'pair requester router name must be limited');
        hub_test_assert((int)($station['id'] ?? 0) > 0, 'pair import must return saved station');
        hub_test_assert(!array_key_exists('station_token', $station), 'pair import result must not expose station token');
        hub_test_assert(!str_contains(implode(' ', array_map('strval', $station)), '3wa_live_station_secret'), 'pair import result must not expose station token value');

        $raw = $db->query('SELECT token_ciphertext FROM cluster_stations WHERE id = ' . (int)$station['id'])->fetchColumn();
        hub_test_assert(!str_contains((string)$raw, '3wa_live_station_secret'), 'pair import must encrypt saved station token');
    });
});

hub_test('cluster router rejects malformed pairing links and invalid pairing responses', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        foreach ([
            'ftp://station.example/cluster_pair.php#invite=' . str_repeat('a', 64),
            'https://station.example/not_cluster_pair.php#invite=' . str_repeat('a', 64),
            'https://station.example/cluster_pair.php',
            'https://station.example/cluster_pair.php#invite=short',
        ] as $link) {
            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_import_pairing_link($db, $link)), 'malformed pairing link must reject');
        }
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_cluster_import_pairing_link(
                $db,
                'https://station.example/cluster_pair.php#invite=' . str_repeat('b', 64),
                static fn (): array => ['status' => 200, 'body' => '{}']
            )),
            'invalid pairing response must reject'
        );
    });
});

hub_test('cluster router rejects credential-bearing and query-bearing pairing links before requesting', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $invite = str_repeat('c', 64);
        foreach ([
            'https://router:secret@station.example/cluster_pair.php#invite=' . $invite,
            'https://station.example/cluster_pair.php?scope=local#invite=' . $invite,
            'https://station.example/cluster_pair.php?#invite=' . $invite,
        ] as $link) {
            $requested = false;
            $rejected = hub_test_throws(function () use ($db, $link, &$requested): array {
                return hub_cluster_import_pairing_link($db, $link, static function () use (&$requested): array {
                    $requested = true;
                    return ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)];
                });
            });
            hub_test_assert($rejected && !$requested, 'credential-bearing or query-bearing pairing link must reject before requesting');
        }
    });
});

hub_test('cluster router selects the highest-priority healthy station', function (): void {
    $selected = hub_cluster_select_station('vision', [
        hub_test_cluster_station_fixture(['id' => 2, 'priority' => 5]),
        hub_test_cluster_station_fixture(['id' => 1, 'priority' => 10]),
    ]);

    hub_test_assert((int)($selected['id'] ?? 0) === 1, 'highest-priority healthy station must win');
});

hub_test('cluster station inventory preserves configured routing priority', function (): void {
    $inventory = hub_cluster_station_inventory([
        'id' => 17,
        'station_key' => 'priority_station',
        'priority' => 42,
        'enabled' => 1,
        'status_json' => json_encode(['modes' => ['speech_transcribe']], JSON_THROW_ON_ERROR),
        'status_fetched_at' => hub_now(),
        'last_error' => '',
    ]);

    hub_test_assert(($inventory['priority'] ?? null) === 42, 'refreshed station inventory must retain the configured routing priority');
});

hub_test('cluster refresh worker output preserves only the declared station and error contracts', function (): void {
    hub_test_assert(
        hub_cluster_refresh_worker_output_line(['station_key' => 'taipei_gpu_1', 'fresh' => true, 'last_error' => 'status_http_403']) === 'taipei_gpu_1 1 status_http_403',
        'cluster refresh worker must retain valid station refresh output'
    );
    hub_test_assert(
        hub_cluster_refresh_worker_output_line(['station_key' => '<script>', 'fresh' => false, 'last_error' => "status_fetch_failed\nforged"]) === 'invalid 0 invalid',
        'cluster refresh worker must reject malformed stored values'
    );
});

hub_test('cluster router favors lower-priority unpressured stations over pressured preferred stations', function (): void {
    foreach ([
        ['gpu_free_vram_mb' => 0],
        ['active_gpu_leases' => 1],
        ['queued_jobs' => 1],
    ] as $pressure) {
        $selected = hub_cluster_select_station('vision', [
            hub_test_cluster_station_fixture(array_replace(['id' => 1, 'priority' => 10], $pressure)),
            hub_test_cluster_station_fixture(['id' => 2, 'priority' => 5]),
        ]);
        hub_test_assert((int)($selected['id'] ?? 0) === 2, 'unpressured station must outrank preferred pressured station');
    }
});

hub_test('cluster router falls back to priority ordering when every eligible station is pressured', function (): void {
    $selected = hub_cluster_select_station('vision', [
        hub_test_cluster_station_fixture(['id' => 3, 'priority' => 5, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
        hub_test_cluster_station_fixture(['id' => 2, 'priority' => 10, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
        hub_test_cluster_station_fixture(['id' => 1, 'priority' => 10, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
    ]);

    hub_test_assert((int)($selected['id'] ?? 0) === 1, 'all-pressured eligible stations must fall back to priority then ID ordering');
});

hub_test('cluster router returns null when no station is eligible', function (): void {
    foreach ([
        hub_test_cluster_station_fixture(['enabled' => false]),
        hub_test_cluster_station_fixture(['fresh' => false]),
        hub_test_cluster_station_fixture(['modes' => ['tts']]),
    ] as $station) {
        hub_test_assert(hub_cluster_select_station('vision', [$station]) === null, 'disabled stale or unsupported station must be ineligible');
    }
});

hub_test('unpaired cluster child limits its token to status and selected modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');

        $configured = hub_cluster_node_configure($db, true, ['ocr', 'unchecked']);
        $tokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        sort($permissions);

        hub_test_assert(!empty($configured['enabled']), 'node must be enabled');
        hub_test_assert($permissions === ['cluster_status', 'ocr'], 'unpaired node token must include only cluster status and selected published modes');
        $plainToken = hub_cluster_node_reveal_token($db);
        hub_test_assert($plainToken !== '', 'admin reveal helper must return the token');
        hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', $plainToken, 'task_status')['ok']), 'unpaired node token must not authenticate native task followups');
        hub_test_assert(array_intersect($permissions, ['task_retry', 'task_artifacts_ack', 'task_artifact_retention']) === [], 'node token must never gain retry, ACK, or retention permissions');
        foreach (['AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT', 'AIHUB_CLUSTER_NODE_TOKEN_IV', 'AIHUB_CLUSTER_NODE_TOKEN_TAG'] as $key) {
            hub_test_assert(!str_contains(hub_get_storage_setting($db, $key), '3wa_live_'), 'node token storage must be encrypted');
        }
    });
});

hub_test('cluster child pairing retains only status and selected service permissions', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'ocr');
            $configured = hub_cluster_node_configure($db, true, ['ocr']);
            $oldTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
            $paired = hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

            hub_test_assert((string)$paired['station_token'] === hub_cluster_node_reveal_token($db), 'pairing must return the existing station token');
            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router')), 'pair invitation must be one-time');
            $ipRules = hub_list_api_token_ip_rules($db, $oldTokenId);
            hub_test_assert(count($ipRules) === 1 && (string)$ipRules[0]['ip_rule'] === '203.0.113.44', 'paired token must bind to the caller IP');
            $pairedPermissions = array_column(hub_list_api_token_permissions($db, $oldTokenId), 'mode');
            sort($pairedPermissions);
            hub_test_assert($pairedPermissions === ['cluster_status', 'ocr'], 'paired node token must retain only cluster status and selected published modes');
            hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', (string)$paired['station_token'], 'task_status')['ok']), 'paired node token must not authenticate native task modes');

            hub_cluster_node_clear_pairing($db);
            $clearedPermissions = array_column(hub_list_api_token_permissions($db, $oldTokenId), 'mode');
            sort($clearedPermissions);
            hub_test_assert($clearedPermissions === ['cluster_status', 'ocr'], 'clearing a pairing must retain only base node permissions');
            $replacementInvite = hub_cluster_create_pair_invitation($db);
            hub_cluster_accept_pair_invitation($db, (string)$replacementInvite['invite'], '203.0.113.44', 'Primary Router');

            $regenerated = hub_cluster_node_regenerate_token($db);
            $newTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
            hub_test_assert($newTokenId !== $oldTokenId, 'regeneration must replace the station token');
            hub_test_assert((int)(hub_get_api_token($db, $oldTokenId)['enabled'] ?? 1) === 0, 'regeneration must revoke the old token');
            hub_test_assert(hub_list_api_token_permissions($db, $oldTokenId) === [], 'regeneration must remove the old token control-plane permissions');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') === '', 'regeneration must clear the paired router');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '', 'regeneration must clear the legacy invitation expiry');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $regenerated['expires_at'], 'regeneration must set the exact invitation expiry key');
            hub_test_assert((string)$regenerated['invite'] !== '', 'regeneration must issue a new invitation');
            $regeneratedPermissions = array_column(hub_list_api_token_permissions($db, $newTokenId), 'mode');
            sort($regeneratedPermissions);
            hub_test_assert($regeneratedPermissions === ['cluster_status', 'ocr'], 'regenerated unpaired node token must retain only base permissions');

            $regeneratedToken = hub_cluster_node_reveal_token($db);
            hub_cluster_node_configure($db, false, []);
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '' && hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === '', 'disabling must clear both invitation expiry keys');
            hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', $regeneratedToken, 'task_status')['ok']), 'disabling must keep control-plane followups unavailable');
            hub_test_assert(hub_list_api_token_permissions($db, $newTokenId) === [], 'disabling must remove the active child token permissions');
        });
    });
});

hub_test('cluster child accepts a current legacy invitation expiry then clears both keys', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'ocr');
            $configured = hub_cluster_node_configure($db, true, ['ocr']);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', '');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT', $configured['expires_at']);
            hub_test_assert(hub_cluster_pair_invitation_expires_at($db) === $configured['expires_at'], 'current legacy invitation expiry must migrate to the exact key');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $configured['expires_at'], 'legacy invitation expiry migration must persist the exact key');

            $paired = hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.45', 'Legacy Router');
            hub_test_assert((string)($paired['station_token'] ?? '') !== '', 'current legacy invitation expiry must remain pairable');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === '' && hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '', 'pair consumption must clear exact and legacy invitation expiry keys');
        });
    });
});

hub_test('cluster child followup requires the paired node token, source, and whitelist', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'vision');
            $configured = hub_cluster_node_configure($db, true, ['vision']);
            $nodeToken = hub_cluster_node_reveal_token($db);
            $nodeTokenId = hub_cluster_node_token_id($db);
            $nodeMemberId = (int)hub_get_api_token($db, $nodeTokenId)['member_id'];
            $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $nodeMemberId, 'owner_token_id' => $nodeTokenId]);
            $request = [
                'bearer_token' => $nodeToken,
                'client_ip' => '203.0.113.44',
                'method' => 'GET',
                'query' => ['mode' => 'task_status', 'task_id' => (string)$taskId],
            ];

            $native = hub_gateway_dispatch($db, 'task_status', null, $request);
            $unpaired = hub_cluster_child_followup_dispatch($db, $request);
            hub_test_assert($native['status'] === 403 && str_contains($native['body'], 'token_mode_not_allowed'), 'direct native task API must deny the node token');
            hub_test_assert($unpaired['status'] === 403, 'unpaired nodes must not use the child control plane');

            hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');
            $paired = hub_cluster_child_followup_dispatch($db, $request);
            $wrongSource = hub_cluster_child_followup_dispatch($db, array_replace($request, ['client_ip' => '203.0.113.45']));
            $otherToken = hub_test_cluster_router_customer_token($db, ['cluster_status']);
            $wrongToken = hub_cluster_child_followup_dispatch($db, array_replace($request, ['bearer_token' => (string)$otherToken['plain_token']]));
            $wrongMode = hub_cluster_child_followup_dispatch($db, array_replace_recursive($request, ['query' => ['mode' => 'task_retry']]));
            $wrongTask = hub_cluster_child_followup_dispatch($db, array_replace_recursive($request, ['query' => ['task_id' => 'not-a-task']]));

            $payload = json_decode($paired['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert($paired['status'] === 200 && ($payload['task_id'] ?? null) === $taskId, 'paired router peer must use the child control plane for its own task');
            hub_test_assert($wrongSource['status'] === 403 && $wrongToken['status'] === 403 && $wrongMode['status'] === 404 && $wrongTask['status'] === 400, 'child control plane must reject wrong source, token, operation, and task identifiers');

            hub_cluster_node_regenerate_token($db);
            $afterRegeneration = hub_cluster_child_followup_dispatch($db, $request);
            hub_test_assert($afterRegeneration['status'] === 403, 'regeneration must make the previous child control-plane credential unusable');
        });
    });
});

hub_test('cluster child TTS artifact relay accepts only the node token and a constrained local artifact name', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-artifact-child']);
        $service = $installed['service'];
        $db->prepare("UPDATE services SET enabled = 1, status = 'stopped', runtime_status = 'stopped' WHERE id = :id")
            ->execute([':id' => (int)$service['id']]);
        hub_cluster_node_configure($db, true, ['tts']);
        $artifactDir = dirname(hub_path((string)$service['compose_file'])) . '/artifacts';
        if (!is_dir($artifactDir) && !mkdir($artifactDir, 0770, true) && !is_dir($artifactDir)) {
            throw new RuntimeException('Cannot create isolated TTS artifact directory.');
        }
        $file = 'tts_123456abcdef.wav';
        file_put_contents($artifactDir . '/' . $file, 'RIFFdemoWAVE');
        $token = hub_cluster_node_reveal_token($db);

        $response = hub_cluster_child_tts_artifact_dispatch($db, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'GET',
            'query' => ['file' => $file],
        ]);
        $rejected = hub_cluster_child_tts_artifact_dispatch($db, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'GET',
            'query' => ['file' => '../' . $file],
        ]);

        hub_test_assert($response['status'] === 200 && ($response['body'] ?? '') === 'RIFFdemoWAVE', 'node token must retrieve only the generated local TTS artifact bytes');
        hub_test_assert($rejected['status'] === 404, 'TTS artifact relay must reject traversal-like filenames');
    });
});

hub_test('cluster node reconciliation removes legacy task permissions and direct task control stays denied', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        $token = hub_cluster_node_reveal_token($db);
        $tokenId = hub_cluster_node_token_id($db);
        foreach (['task_status', 'task_result', 'task_log', 'task_cancel', 'artifact'] as $mode) {
            hub_add_api_token_mode_permission($db, $tokenId, $mode);
        }

        hub_migrate($db);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($permissions === ['cluster_status', 'vision'], 'migration reconciliation must remove all legacy node task-control permissions');

        hub_add_api_token_mode_permission($db, $tokenId, 'task_result');
        hub_ensure_default_storage_settings($db);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($permissions === ['cluster_status', 'vision'], 'startup reconciliation must remove later stale node task-control permissions');

        hub_add_api_token_mode_permission($db, $tokenId, 'task_status');
        $response = hub_gateway_dispatch($db, 'task_status', null, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'GET',
            'query' => ['task_id' => '1'],
        ]);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($response['status'] === 403 && $permissions === ['cluster_status', 'vision'], 'node authentication must reconcile stale permissions before direct task control can run');
    });
});

hub_test('cluster node authentication removes unpublished selected modes before dispatch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        $token = hub_cluster_node_reveal_token($db);
        $tokenId = hub_cluster_node_token_id($db);
        hub_test_assert(!empty(hub_authenticate_api_token($db, '203.0.113.44', $token, 'vision')['ok']), 'published selected modes must authenticate for the node token');

        hub_test_cluster_publish_mode($db, 'vision', false);
        $auth = hub_authenticate_api_token($db, '203.0.113.44', $token, 'vision');
        $response = hub_gateway_dispatch($db, 'vision', null, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'POST',
            'raw_body' => '{}',
        ]);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert(empty($auth['ok']) && $response['status'] === 403 && $permissions === ['cluster_status'], 'node authentication must remove unavailable selected modes before dispatch');
    });
});

hub_test('cluster child result builds a bounded authoritative artifact index from task storage', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'vision');
            $configured = hub_cluster_node_configure($db, true, ['vision']);
            $token = hub_cluster_node_reveal_token($db);
            $tokenId = hub_cluster_node_token_id($db);
            $memberId = (int)hub_get_api_token($db, $tokenId)['member_id'];
            $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $memberId, 'owner_token_id' => $tokenId]);
            hub_finish_task_success($db, hub_get_task($db, $taskId), ['artifacts' => [['id' => 999]], 'metadata' => ['artifact_id' => 998]]);
            $artifact = $db->prepare(
                "INSERT INTO task_artifacts (task_id, name, path, mime_type, size_bytes, state, created_at)
                 VALUES (:task_id, :name, :path, 'application/octet-stream', :size_bytes, 'available', :created_at)"
            );
            for ($index = 1; $index <= 128; $index++) {
                $artifact->execute([
                    ':task_id' => $taskId,
                    ':name' => 'artifact_' . $index,
                    ':path' => '/not-served/' . $index,
                    ':size_bytes' => $index,
                    ':created_at' => hub_now(),
                ]);
            }
            hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

            $response = hub_cluster_child_followup_dispatch($db, [
                'bearer_token' => $token,
                'client_ip' => '203.0.113.44',
                'method' => 'GET',
                'query' => ['mode' => 'task_result', 'task_id' => (string)$taskId],
            ]);
            $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert($response['status'] === 200 && count($payload['cluster_artifact_index'] ?? []) === 128, 'child result must index every native task artifact up to its 128-item limit');
            hub_test_assert(($payload['cluster_artifact_index'][0]['id'] ?? null) === 1 && ($payload['cluster_artifact_index'][127]['id'] ?? null) === 128 && !str_contains($response['body'], '999') && !str_contains($response['body'], '998'), 'child result must ignore arbitrary stored result artifact fields');
        });
    });
});

hub_test('cluster child projects only the exact safe voice profile prepare result', function (): void {
    $result = [
        'prompt_text_sha256' => str_repeat('a', 64),
        'text_chars' => 123,
        'transcript_confirmed' => true,
        'transcription_status' => 'ready',
        'kind' => 'voice_profile_prepare',
    ];
    $task = ['task_type' => 'voice_profile_prepare', 'result' => $result];

    hub_test_assert(
        hub_gateway_cluster_child_result_summary($task, []) === $result,
        'native child task_result must retain the exact Task4 profile result regardless of key order'
    );
    foreach ([
        $result + ['task_id' => 42],
        $result + ['reference_audio_path' => '/private/reference.wav'],
        $result + ['prompt_text' => 'private transcript'],
        array_replace($result, ['kind' => 'pack_job']),
        array_replace($result, ['transcription_status' => 'private_state']),
        array_replace($result, ['transcript_confirmed' => 1]),
        array_replace($result, ['text_chars' => -1]),
        array_replace($result, ['text_chars' => 20001]),
        array_replace($result, ['prompt_text_sha256' => str_repeat('A', 64)]),
    ] as $invalidResult) {
        hub_test_assert(
            hub_gateway_cluster_child_result_summary(
                ['task_type' => 'voice_profile_prepare', 'result' => $invalidResult],
                []
            ) === [],
            'native child task_result must fail closed for extras, private fields, or invalid Task4 bounds'
        );
    }
    foreach ([
        ['transcription_status' => 'pending', 'transcript_confirmed' => false, 'text_chars' => 0],
        ['transcription_status' => 'failed', 'transcript_confirmed' => false, 'text_chars' => 20000],
    ] as $boundary) {
        $bounded = array_replace($result, $boundary);
        hub_test_assert(
            hub_gateway_cluster_child_result_summary(
                ['task_type' => 'voice_profile_prepare', 'result' => $bounded],
                []
            ) === $bounded,
            'native child task_result must preserve each Task4 state at its inclusive bounds'
        );
    }
    hub_test_assert(
        hub_gateway_cluster_child_result_summary(
            ['task_type' => 'pack_job', 'result' => $result],
            []
        ) === [],
        'profile result projection must apply only to native voice_profile_prepare tasks'
    );
});

hub_test('cluster child status stays lightweight and filters unavailable selected modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');
        hub_cluster_node_configure($db, true, ['ocr']);
        $db->exec("UPDATE runtime_resource_leases SET state = 'leased', lease_expires_at = '2999-01-01 00:00:00' WHERE resource_key = 'gpu:0'");
        $now = hub_now();
        $db->prepare('INSERT INTO tasks (task_type, status, created_at, updated_at) VALUES (:task_type, :status, :created_at, :updated_at)')
            ->execute([':task_type' => 'test', ':status' => 'queued', ':created_at' => $now, ':updated_at' => $now]);
        $db->prepare('INSERT INTO tasks (task_type, status, created_at, updated_at) VALUES (:task_type, :status, :created_at, :updated_at)')
            ->execute([':task_type' => 'test', ':status' => 'running', ':created_at' => $now, ':updated_at' => $now]);
        hub_save_host_metric_snapshot($db, [
            'gpu' => [
                'available' => true,
                'name' => 'Snapshot GPU',
                'memory_total_mb' => 8192,
                'memory_free_mb' => 4096,
            ],
            'service_gpu' => [[
                'service_key' => 'ocr-gpu',
                'mode' => 'ocr',
                'vram_used_mb' => 1536,
                'measured' => true,
            ]],
        ]);

        $payload = hub_cluster_status_payload($db);
        hub_test_assert(array_keys($payload) === [
            'ok', 'snapshot_at', 'display_name', 'gpu', 'active_gpu_leases', 'queued_jobs', 'running_jobs', 'modes',
            'service_gpu', 'service_status', 'release', 'packs', 'runners', 'health', 'cluster',
        ], 'status payload must keep its exact compact health shape');
        foreach (['release', 'packs', 'runners', 'health', 'cluster'] as $key) {
            hub_test_assert(array_key_exists($key, $payload), 'cluster status report missing ' . $key);
        }
        hub_test_assert(array_keys($payload['cluster']) === ['aggregate', 'children_count', 'published_mode_count'], 'aggregate report shape mismatch');
        hub_test_assert(($payload['gpu']['name'] ?? '') === 'Snapshot GPU' && ($payload['gpu']['memory_free_mb'] ?? 0) === 4096, 'status payload must use the latest stored host GPU metric without running a host command');
        hub_test_assert($payload['service_gpu'] === [[
            'service_key' => 'ocr-gpu',
            'mode' => 'ocr',
            'vram_used_mb' => 1536,
            'measured' => true,
        ]], 'status payload must relay only the latest compact measured service GPU snapshot');
        hub_test_assert($payload['service_status'] === [[
            'service_key' => 'hello-main',
            'pack_id' => 'hello',
            'mode' => 'ocr',
            'enabled' => true,
            'install_status' => 'installed',
            'runtime_status' => 'running',
        ]], 'status payload must relay compact local service runtime status without probing services');
        hub_test_assert($payload['modes'] === ['ocr'], 'status payload must include selected running modes only');
        hub_test_assert($payload['active_gpu_leases'] === 1 && $payload['queued_jobs'] === 1 && $payload['running_jobs'] === 1, 'status payload counters must reflect current work');

        $unknownRelease = $payload;
        $unknownRelease['release']['commit'] = '';
        $unknownRelease['release']['dirty'] = null;
        $fallback = hub_cluster_status_payload($db, [
            'git' => $unknownRelease['release'],
            'packs' => $payload['packs'],
            'runners' => $payload['runners'],
            'health' => $payload['health'],
        ]);
        hub_test_assert(array_keys($fallback) === [
            'ok', 'snapshot_at', 'display_name', 'gpu', 'active_gpu_leases', 'queued_jobs', 'running_jobs', 'modes',
            'service_gpu', 'service_status',
        ], 'unknown Git evidence must fall back to the legacy health payload');

        hub_test_cluster_publish_mode($db, 'ocr', false);
        hub_test_assert(hub_cluster_status_payload($db)['modes'] === [], 'status payload must omit stopped selected modes');
        hub_save_host_metric_snapshot($db, ['gpu' => ['available' => true]]);
        hub_test_assert(hub_cluster_status_payload($db)['service_gpu'] === [], 'status payload must default missing service GPU metrics to an empty compact list');
    });
});

hub_test('cluster station freshness tolerates one-minute cron jitter and exposes connection state', function (): void {
    $now = strtotime('2026-07-29 12:00:00');
    $station = [
        'manifest_fetched_at' => '2026-07-29 11:57:30',
        'status_fetched_at' => '2026-07-29 11:57:30',
        'last_error' => '',
    ];
    hub_test_assert(hub_cluster_station_is_fresh($station, $now), '150-second station snapshot must survive cron jitter');
    hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'online', 'fresh error-free station must be online');

    $station['status_fetched_at'] = '2026-07-29 11:57:29';
    hub_test_assert(!hub_cluster_station_is_fresh($station, $now), '151-second station snapshot must be stale');
    hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'offline', 'stale station must be offline');

    $station['status_fetched_at'] = '2026-07-29 11:59:55';
    $station['last_error'] = 'status_fetch_failed';
    hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'offline', 'failed refresh must be offline immediately');

    $station['last_error'] = 'refreshing';
    hub_test_assert(hub_cluster_station_connection_state($station, $now) === 'online', 'a fresh station being refreshed must not flash offline');
});

hub_test('cluster status snapshots retain only bounded release and aggregate health fields', function (): void {
    $now = time();
    $status = [
        'ok' => true,
        'snapshot_at' => date('Y-m-d H:i:s', $now),
        'display_name' => '臺北 GPU 站',
        'gpu' => [
            'available' => true,
            'name' => 'Snapshot GPU',
            'reason' => 'https://private.example/gpu-error',
            'driver_version' => '/private/driver',
            'cuda_version' => 'https://private.example/cuda',
        ],
        'active_gpu_leases' => 1,
        'queued_jobs' => 2,
        'running_jobs' => 3,
        'modes' => ['ocr'],
        'release' => [
            'build_id' => '20260729001',
            'commit' => 'abcdef123456',
            'dirty' => false,
            'tag' => '',
            'token' => 'release-secret',
            'url' => 'https://station.example/repository',
        ],
        'packs' => ['hello' => '0.1.0'],
        'runners' => [
            'hello' => [
                'pack_version' => '0.1.0',
                'runner_version' => '1.0.0',
                'image' => 'registry.example/private/hello',
                'digest' => 'sha256:' . str_repeat('a', 64),
                'observed_at' => '2026-07-29 12:00:00',
                'output' => 'private command output',
            ],
        ],
        'health' => [
            'status' => 'ok',
            'installed_services' => 1,
            'running_services' => 1,
            'failed_services' => 0,
            'queued_jobs' => 2,
            'running_jobs' => 3,
            'path' => '/private/runtime',
        ],
        'cluster' => [
            'aggregate' => true,
            'children_count' => 2,
            'published_mode_count' => 1,
            'token' => 'cluster-secret',
        ],
        'service_gpu' => [[
            'service_key' => 'ocr-gpu',
            'mode' => 'ocr',
            'vram_used_mb' => 1536,
            'measured' => true,
        ]],
        'service_status' => [[
            'service_key' => 'ocr-gpu',
            'pack_id' => 'ocr-ppocrv5',
            'mode' => 'ocr',
            'enabled' => true,
            'install_status' => 'installed',
            'runtime_status' => 'running',
        ]],
    ];

    $snapshot = hub_cluster_compact_status_snapshot($status, $now);
    hub_test_assert($snapshot !== null, 'valid compact release status must be accepted');
    hub_test_assert(($snapshot['display_name'] ?? '') === '臺北 GPU 站', 'compact status must retain a verified station display name');
    hub_test_assert(($snapshot['release'] ?? null) === [
        'build_id' => '20260729001',
        'commit' => 'abcdef123456',
        'dirty' => false,
        'tag' => '',
    ], 'release snapshot shape mismatch');
    hub_test_assert(($snapshot['packs'] ?? null) === ['hello' => '0.1.0'], 'Pack snapshot shape mismatch');
    hub_test_assert(($snapshot['runners'] ?? null) === [
        'hello' => ['digest' => 'sha256:' . str_repeat('a', 64)],
    ], 'runner snapshot must retain only its bounded digest');
    hub_test_assert(($snapshot['health'] ?? null) === [
        'status' => 'ok',
        'installed_services' => 1,
        'running_services' => 1,
        'failed_services' => 0,
        'queued_jobs' => 2,
        'running_jobs' => 3,
    ], 'health snapshot shape mismatch');
    hub_test_assert(($snapshot['cluster'] ?? null) === [
        'aggregate' => true,
        'children_count' => 2,
        'published_mode_count' => 1,
    ], 'cluster snapshot shape mismatch');
    hub_test_assert(($snapshot['gpu'] ?? null) === [
        'available' => true,
        'name' => 'Snapshot GPU',
    ], 'GPU snapshot must discard unsafe string fields');
    hub_test_assert(($snapshot['service_gpu'] ?? null) === [[
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr',
        'vram_used_mb' => 1536,
        'measured' => true,
    ]], 'service GPU snapshot must retain only the measured compact telemetry');
    hub_test_assert(
        array_keys($snapshot['service_gpu'][0]) === ['service_key', 'mode', 'vram_used_mb', 'measured'],
        'service GPU snapshot must not retain PID, container, or command output fields'
    );
    hub_test_assert(($snapshot['service_status'] ?? null) === [[
        'service_key' => 'ocr-gpu',
        'pack_id' => 'ocr-ppocrv5',
        'mode' => 'ocr',
        'enabled' => true,
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ]], 'service status snapshot must retain only compact runtime state');
    $encoded = json_encode($snapshot, JSON_THROW_ON_ERROR);
    foreach (['release-secret', 'station.example', 'registry.example', 'private command output', '/private/runtime', 'cluster-secret', 'private.example/gpu', '/private/driver'] as $forbidden) {
        hub_test_assert(!str_contains($encoded, $forbidden), 'compact status leaked forbidden nested data: ' . $forbidden);
    }

    $legacy = array_diff_key($status, array_flip(['release', 'packs', 'runners', 'health', 'cluster', 'display_name', 'service_gpu', 'service_status']));
    $legacySnapshot = hub_cluster_compact_status_snapshot($legacy, $now);
    hub_test_assert(
        $legacySnapshot !== null
        && !array_key_exists('service_gpu', $legacySnapshot)
        && !array_key_exists('service_status', $legacySnapshot),
        'legacy station status without service telemetry must remain readable during rolling updates'
    );

    $invalidStatuses = [];
    $invalid = $status;
    $invalid['release']['build_id'] = '2026072900';
    $invalidStatuses['short build ID'] = $invalid;
    $invalid = $status;
    $invalid['release']['commit'] = 'ABCDEF1';
    $invalidStatuses['uppercase commit'] = $invalid;
    $invalid = $status;
    $invalid['release']['dirty'] = 0;
    $invalidStatuses['non-boolean dirty state'] = $invalid;
    $invalid = $status;
    $invalid['packs']['hello'] = str_repeat('v', 65);
    $invalidStatuses['oversized Pack version'] = $invalid;
    $invalid = $status;
    $invalid['packs']['hello'] = 'https://private.example/version';
    $invalidStatuses['URL Pack version'] = $invalid;
    $invalid = $status;
    $invalid['packs']['hello'] = '/private/version';
    $invalidStatuses['path Pack version'] = $invalid;
    $invalid = $status;
    $invalid['runners']['hello']['digest'] = str_repeat('d', 257);
    $invalidStatuses['oversized runner digest'] = $invalid;
    $invalid = $status;
    $invalid['runners']['hello']['digest'] = 'https://private.example/digest';
    $invalidStatuses['URL runner digest'] = $invalid;
    $invalid = $status;
    $invalid['runners']['hello']['digest'] = '/private/digest';
    $invalidStatuses['path runner digest'] = $invalid;
    $invalid = $status;
    $invalid['cluster']['aggregate'] = 1;
    $invalidStatuses['non-boolean aggregate state'] = $invalid;
    $invalid = $status;
    $invalid['cluster']['children_count'] = -1;
    $invalidStatuses['negative child count'] = $invalid;
    $invalid = $status;
    $invalid['display_name'] = '   ';
    $invalidStatuses['blank station display name'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['service_key'] = 'OCR GPU';
    $invalidStatuses['malformed service GPU key'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['mode'] = 'ocr mode';
    $invalidStatuses['malformed service GPU mode'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['measured'] = false;
    $invalidStatuses['unmeasured service GPU row'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['measured'] = 1;
    $invalidStatuses['non-boolean service GPU measurement state'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['vram_used_mb'] = '1536';
    $invalidStatuses['non-integer service GPU memory'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['vram_used_mb'] = -1;
    $invalidStatuses['negative service GPU memory'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][0]['vram_used_mb'] = 1_000_000_001;
    $invalidStatuses['oversized service GPU memory'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][] = [
        'service_key' => 'ocr-gpu',
        'mode' => 'ocr-copy',
        'vram_used_mb' => 1,
        'measured' => true,
    ];
    $invalidStatuses['duplicate service GPU key'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'][] = [
        'service_key' => 'ocr-gpu-copy',
        'mode' => 'ocr',
        'vram_used_mb' => 1,
        'measured' => true,
    ];
    $invalidStatuses['duplicate service GPU mode'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'] = array_fill(0, 257, $status['service_gpu'][0]);
    $invalidStatuses['too many service GPU rows'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'] = ['service' => $status['service_gpu'][0]];
    $invalidStatuses['non-list service GPU rows'] = $invalid;
    $invalid = $status;
    $invalid['service_gpu'] = 'not-an-array';
    $invalidStatuses['non-array service GPU telemetry'] = $invalid;
    foreach (['pid' => 123, 'container_id' => 'private-container', 'output' => 'private output'] as $field => $value) {
        $invalid = $status;
        $invalid['service_gpu'][0][$field] = $value;
        $invalidStatuses['extra service GPU field ' . $field] = $invalid;
    }
    $invalid = $status;
    $invalid['service_status'][0]['enabled'] = 1;
    $invalidStatuses['non-boolean service enabled state'] = $invalid;
    $invalid = $status;
    $invalid['service_status'][0]['runtime_status'] = 'unknown';
    $invalidStatuses['unknown service runtime state'] = $invalid;
    $invalid = $status;
    $invalid['service_status'][0]['pack_id'] = '/private-pack';
    $invalidStatuses['unsafe service Pack ID'] = $invalid;
    $invalid = $status;
    $invalid['service_status'][] = [
        'service_key' => 'ocr-gpu-copy',
        'pack_id' => 'ocr-ppocrv5',
        'mode' => 'ocr',
        'enabled' => true,
        'install_status' => 'installed',
        'runtime_status' => 'running',
    ];
    $invalidStatuses['duplicate service status mode'] = $invalid;
    $invalid = $status;
    $invalid['service_status'][0]['output'] = 'private output';
    $invalidStatuses['extra service status field'] = $invalid;
    foreach ($invalidStatuses as $case => $invalidStatus) {
        hub_test_assert(hub_cluster_compact_status_snapshot($invalidStatus, $now) === null, 'compact status accepted ' . $case);
    }

    $numericPack = $status;
    $numericPack['packs'] = ['123' => '1.0.0'];
    $numericPack['runners'] = ['123' => ['digest' => 'sha256:' . str_repeat('c', 64)]];
    $numericSnapshot = hub_cluster_compact_status_snapshot($numericPack, $now);
    hub_test_assert(
        $numericSnapshot !== null
        && ($numericSnapshot['packs'][123] ?? '') === '1.0.0'
        && ($numericSnapshot['runners'][123]['digest'] ?? '') === 'sha256:' . str_repeat('c', 64),
        'numeric-only Pack IDs must preserve compact report data'
    );
});

hub_test('cluster inventory refresh fetches manifest then authenticated status without leaking station secrets', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        $requests = [];
        $snapshotAt = hub_now();
        $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use (&$requests, $snapshotAt): array {
            $requests[] = $request;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => $snapshotAt,
                'display_name' => '更名後子節點',
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['ocr'],
            ], JSON_THROW_ON_ERROR)];
        });

        hub_test_assert(count($requests) === 2 && str_ends_with((string)$requests[0]['url'], '/api_manifest.json.php') && str_ends_with((string)$requests[1]['url'], '/cluster_status.php'), 'refresh must fetch manifest before status');
        hub_test_assert(($requests[0]['headers'] ?? null) === [], 'manifest refresh must be authless');
        hub_test_assert(($requests[1]['headers'] ?? null) === ['Authorization' => 'Bearer 3wa_live_station_secret'], 'status refresh must use only the station token');
        hub_test_assert(!empty($refreshed['fresh']) && (string)($refreshed['last_error'] ?? '') === '', 'successful station refresh must be fresh');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'refreshed station result must not expose token');

        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null && hub_cluster_station_is_fresh($stored), 'freshness requires both stored snapshots');
        hub_test_assert($stored['status_fetched_at'] === $snapshotAt, 'status freshness must use the verified child snapshot time');
        hub_test_assert($stored['display_name'] === '更名後子節點', 'verified child status must synchronize the station display name');
        $skippedRequests = 0;
        hub_cluster_refresh_station($db, $stored, static function () use (&$skippedRequests): array {
            $skippedRequests++;
            return ['status' => 500, 'body' => ''];
        });
        hub_test_assert($skippedRequests === 0, 'station refresh must not repeat within ten seconds');
        $stored['manifest_fetched_at'] = date('Y-m-d H:i:s', time() - 151);
        hub_test_assert(!hub_cluster_station_is_fresh($stored), 'stale manifest must make a station unavailable');
    });
});

hub_test('cluster inventory refresh reports malformed responses without raw response or token leaks', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        $refreshed = hub_cluster_refresh_station($db, $station, static fn (): array => ['status' => 200, 'body' => '{not-json 3wa_live_station_secret}']);

        hub_test_assert((string)($refreshed['last_error'] ?? '') === 'manifest_invalid', 'malformed manifest must have a compact error');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'refresh errors must not leak raw response or token');
    });
});

hub_test('cluster inventory refresh preserves a compact status HTTP failure without response or token leaks', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');

        $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request): array {
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 403, 'body' => 'CLI only 3wa_live_station_secret'];
        });

        hub_test_assert((string)($refreshed['last_error'] ?? '') === 'status_http_403', 'status HTTP failure must retain only its status code');
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null && $stored['display_name'] === 'Taipei GPU 1', 'failed station refresh must retain the last verified display name');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'status HTTP failure must not leak raw response or token');
    });
});

hub_test('cluster inventory normalizes small future skew and rejects invalid status snapshots', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $now = time();
        hub_test_assert(
            hub_cluster_verified_status_snapshot_at(date('Y-m-d H:i:s', $now + 1), $now) === date('Y-m-d H:i:s', $now),
            'a small future snapshot must normalize to the router receipt time'
        );
        foreach ([date('Y-m-d H:i:s', $now - 31), 'not-a-timestamp', date('Y-m-d H:i:s', $now + 300)] as $snapshotAt) {
            $db = hub_test_reset_db();
            $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
            $station = hub_cluster_get_station($db, $stationId);
            hub_test_assert($station !== null, 'paired station missing');
            $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use ($snapshotAt): array {
                if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                    return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
                }

                return ['status' => 200, 'body' => json_encode([
                    'ok' => true,
                    'snapshot_at' => $snapshotAt,
                    'gpu' => ['available' => true],
                    'active_gpu_leases' => 0,
                    'queued_jobs' => 0,
                    'running_jobs' => 0,
                    'modes' => ['ocr'],
                ], JSON_THROW_ON_ERROR)];
            });
            hub_test_assert((string)($refreshed['last_error'] ?? '') === 'status_invalid' && empty($refreshed['fresh']), 'invalid remote snapshot time must not become fresh');
            unset($db, $station, $refreshed);
            gc_collect_cycles();
        }
    });
});

hub_test('cluster inventory backs off failed partial refreshes but retries stale successful snapshots', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        hub_cluster_refresh_station($db, $station, static function (array $request): array {
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => '{bad-status}'];
        });

        $requests = 0;
        $retry = static function (array $request) use (&$requests): array {
            $requests++;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => hub_now(),
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['ocr'],
            ], JSON_THROW_ON_ERROR)];
        };
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'partial station missing');
        $skipped = hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 0 && (string)$skipped['last_error'] === 'status_invalid', 'failed partial refresh must respect its ten-second attempt backoff');

        $db->prepare('UPDATE cluster_stations SET updated_at = :updated_at WHERE id = :id')
            ->execute([':updated_at' => date('Y-m-d H:i:s', time() - 11), ':id' => $stationId]);
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'backoff station missing');
        $requests = 0;
        $recovered = hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 2 && !empty($recovered['fresh']), 'partial refresh must retry once its failure backoff elapses');

        $db->prepare('UPDATE cluster_stations SET status_fetched_at = :status_fetched_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':status_fetched_at' => date('Y-m-d H:i:s', time() - 11), ':updated_at' => hub_now(), ':id' => $stationId]);
        $requests = 0;
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'stale station missing');
        hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 2, 'stale successful snapshot must refresh even when updated_at is current');
    });
});

hub_test('cluster router dispatch returns 404 while routing is disabled', function (): void {
    $db = hub_test_reset_db();
    $refreshes = 0;

    $response = hub_cluster_dispatch($db, 'vision', [], [
        'refresh_due' => static function () use (&$refreshes): array {
            $refreshes++;
            return [];
        },
    ]);

    hub_test_assert($response['status'] === 404 && str_contains($response['body'], 'router_disabled'), 'disabled router must return a safe 404');
    hub_test_assert($refreshes === 0, 'disabled router must not refresh stations');
});

hub_test('cluster router dispatch uses strict customer authentication and mode permissions', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '0');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
    $token = hub_test_cluster_router_customer_token($db, []);
    $refreshes = 0;
    $seams = [
        'refresh_due' => static function () use (&$refreshes): array {
            $refreshes++;
            return [];
        },
    ];

    $missing = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request('', ['client_ip' => '127.0.0.1']), $seams);
    $denied = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), $seams);

    hub_test_assert($missing['status'] === 401 && str_contains($missing['body'], 'missing_token'), 'router must not use legacy anonymous or localhost authentication');
    hub_test_assert($denied['status'] === 403 && str_contains($denied['body'], 'token_mode_not_allowed'), 'router must require the customer token mode permission');
    hub_test_assert($refreshes === 0, 'unauthenticated requests must not refresh stations');
});

hub_test('cluster router dispatch refreshes then selects only a fresh eligible station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $stale = hub_test_cluster_router_station($db, ['station_key' => 'stale_gpu', 'priority' => 99, 'station_token' => 'stale_station_token']);
        $fresh = hub_test_cluster_router_station($db, [
            'station_key' => 'fresh_gpu',
            'priority' => 1,
            'station_token' => 'fresh_station_token',
            'internal_base_url' => 'https://fresh.internal/aihub',
        ]);
        $refreshes = 0;
        $proxied = [];

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static function () use (&$refreshes, $stale, $fresh): array {
                $refreshes++;
                return [
                    hub_test_cluster_station_fixture(['id' => (int)$stale['id'], 'priority' => 99, 'fresh' => false]),
                    hub_test_cluster_station_fixture(['id' => (int)$fresh['id'], 'priority' => 1, 'station_key' => 'fresh_gpu']),
                ];
            },
            'transport' => static function (array $request) use (&$proxied): array {
                $proxied[] = $request;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ]);

        hub_test_assert($response['status'] === 200 && $refreshes === 1 && count($proxied) === 1, 'router must refresh before one eligible dispatch');
        hub_test_assert(($proxied[0]['headers']['Authorization'] ?? '') === 'Bearer fresh_station_token', 'router must select only the fresh eligible station');
        $route = $db->query('SELECT station_id, state FROM cluster_routes ORDER BY created_at DESC LIMIT 1')->fetch();
        hub_test_assert((int)($route['station_id'] ?? 0) === (int)$fresh['id'] && ($route['state'] ?? '') === 'completed', 'router must record the selected station without secrets');
    });
});

hub_test('cluster router dispatches a configured self station directly with its paired router IP', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        $station = hub_cluster_register_self_station($db);
        $rules = hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db));
        hub_test_assert(hub_cluster_router_station_is_self($db, $station), 'registered local station must be the Router self station');
        hub_test_assert(count($rules) === 1 && (string)$rules[0]['ip_rule'] === '127.0.0.1', 'local station Token must bind only to loopback');
        hub_test_assert(hub_cluster_node_has_verified_router_peer($db, hub_cluster_node_token_id($db), '127.0.0.1'), 'local station must have a verified loopback Router peer');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $direct = 0;
        $http = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => (string)$station['station_key']])],
            'direct_dispatcher' => static function (PDO $db, string $mode, array $request) use (&$direct): array {
                $direct++;
                hub_test_assert(($request['bearer_token'] ?? '') === hub_cluster_node_reveal_token($db), 'self dispatch must use the selected station token');
                hub_test_assert(($request['client_ip'] ?? '') === '127.0.0.1', 'self dispatch must use the paired loopback IP, never the customer IP');
                return hub_gateway_json(200, ['ok' => true, 'mode' => $mode]);
            },
            'transport' => static function () use (&$http): array {
                $http++;
                return hub_gateway_error(500, 'unexpected_http', 'unexpected HTTP');
            },
        ]);

        hub_test_assert($response['status'] === 200 && $direct === 1 && $http === 0, 'configured self station must dispatch once in-process without HTTP');
        });
    });
});

hub_test('cluster router refreshes its local station without a remote fetch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $station = hub_cluster_register_self_station($db);
            $fetches = 0;

            $refreshed = hub_cluster_refresh_station($db, $station, static function () use (&$fetches): array {
                $fetches++;
                throw new RuntimeException('self station must not fetch over HTTP');
            });

            hub_test_assert(!empty($refreshed['fresh']) && $fetches === 0, 'local station refresh must use in-process manifest and status data');
        });
    });
});

hub_test('cluster router recovers a matching legacy self station without IP rules', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $pairing = hub_cluster_node_pairing_descriptor($db);
            $station = hub_test_cluster_router_station($db, [
                'station_key' => (string)$pairing['station_key'],
                'display_name' => (string)$pairing['display_name'],
                'public_base_url' => (string)$pairing['public_base_url'],
                'internal_base_url' => null,
                'station_token' => hub_cluster_node_reveal_token($db),
                'modes' => $pairing['modes'],
            ]);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', '3waAIHub Local');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', '');

            $recovered = hub_cluster_register_self_station($db);
            $rules = hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db));

            hub_test_assert((int)$recovered['id'] === (int)$station['id'], 'legacy recovery must reuse the station matching the current descriptor');
            hub_test_assert(hub_cluster_router_station_is_self($db, $recovered), 'legacy recovery must mark the matching station as self');
            hub_test_assert(count($rules) === 1 && (string)$rules[0]['ip_rule'] === '127.0.0.1', 'legacy recovery must replace missing rules with loopback only');
        });
    });
});

hub_test('cluster router refuses a legacy self station with a foreign token', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $pairing = hub_cluster_node_pairing_descriptor($db);
            hub_test_cluster_router_station($db, [
                'station_key' => (string)$pairing['station_key'],
                'display_name' => (string)$pairing['display_name'],
                'public_base_url' => (string)$pairing['public_base_url'],
                'internal_base_url' => null,
                'station_token' => 'foreign_station_token',
                'modes' => $pairing['modes'],
            ]);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', 'External Router');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', '');

            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_register_self_station($db)), 'legacy recovery must require the current node token');
            hub_test_assert(hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db)) === [], 'rejected legacy recovery must not add a loopback rule');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY') === '', 'rejected legacy recovery must not mark a foreign station as self');
        });
    });
});

hub_test('cluster router refuses a stale matching self station when an external Router rule exists', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $pairing = hub_cluster_node_pairing_descriptor($db);
            hub_test_cluster_router_station($db, [
                'station_key' => (string)$pairing['station_key'],
                'display_name' => (string)$pairing['display_name'],
                'public_base_url' => (string)$pairing['public_base_url'],
                'internal_base_url' => null,
                'station_token' => hub_cluster_node_reveal_token($db),
                'modes' => $pairing['modes'],
            ]);
            $tokenId = hub_cluster_node_token_id($db);
            hub_add_api_token_ip_rule($db, $tokenId, '198.51.100.44', 'cluster router');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', 'External Router');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'previous_self_station');

            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_register_self_station($db)), 'legacy recovery must refuse any enabled external Router rule');
            $rules = hub_enabled_api_token_ip_rules($db, $tokenId);
            hub_test_assert(count($rules) === 1 && (string)$rules[0]['ip_rule'] === '198.51.100.44', 'rejected legacy recovery must preserve the external Router rule');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY') === 'previous_self_station', 'rejected legacy recovery must preserve the previous self station state');
        });
    });
});

hub_test('cluster router rolls back legacy self recovery after IP rule mutation fails', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $pairing = hub_cluster_node_pairing_descriptor($db);
            hub_test_cluster_router_station($db, [
                'station_key' => (string)$pairing['station_key'],
                'display_name' => (string)$pairing['display_name'],
                'public_base_url' => (string)$pairing['public_base_url'],
                'internal_base_url' => null,
                'station_token' => hub_cluster_node_reveal_token($db),
                'modes' => $pairing['modes'],
            ]);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', '3waAIHub Local');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', '');
            $db->exec(
                "CREATE TRIGGER fail_legacy_self_registration
                 BEFORE UPDATE OF value ON settings
                 WHEN OLD.key = 'AIHUB_CLUSTER_NODE_ROUTER_NAME'
                 BEGIN
                     SELECT RAISE(ABORT, 'forced registration failure');
                 END"
            );

            try {
                $failed = hub_test_throws(static fn (): array => hub_cluster_register_self_station($db));
                $rules = hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db));
                $routerName = hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME');
                $selfKey = hub_get_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY');
            } finally {
                $db->exec('DROP TRIGGER IF EXISTS fail_legacy_self_registration');
            }

            hub_test_assert($failed, 'injected failure after loopback insertion must abort registration');
            hub_test_assert($rules === [], 'failed registration must roll back the loopback IP rule');
            hub_test_assert($routerName === '3waAIHub Local' && $selfKey === '', 'failed registration must restore Router pairing settings');
        });
    });
});

hub_test('cluster router local registration refuses a child paired to another router', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            $configured = hub_cluster_node_configure($db, true, ['vision']);
            hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '198.51.100.44', 'External Router');

            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_register_self_station($db)), 'local registration must not overwrite an external Router pairing');
            $rules = hub_enabled_api_token_ip_rules($db, hub_cluster_node_token_id($db));
            hub_test_assert(count($rules) === 1 && (string)$rules[0]['ip_rule'] === '198.51.100.44', 'rejected local registration must preserve the external Router peer');
        });
    });
});

hub_test('cluster router remote dispatch uses station auth safe headers and no redirects', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_key' => 'remote_gpu', 'station_token' => 'remote_station_token']);
        $proxied = [];
        $request = hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => [
                'Authorization' => 'Bearer customer_token',
                'Cookie' => 'session=customer',
                'Proxy-Authorization' => 'Basic customer',
                'X-Forwarded-For' => '203.0.113.99',
                'Forwarded' => 'for=203.0.113.99',
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ],
            'query' => ['task_id' => '42'],
        ]);

        $response = hub_cluster_dispatch($db, 'vision', $request, [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'remote_gpu'])],
            'transport' => static function (array $request) use (&$proxied): array {
                $proxied[] = $request;
                return [
                    'status' => 200,
                    'headers' => ['Content-Type: application/json', 'Set-Cookie: station=secret'],
                    'body' => '{"ok":true}',
                ];
            },
        ]);

        hub_test_assert($response['status'] === 200 && count($proxied) === 1, 'remote station must receive one dispatch');
        hub_test_assert(($proxied[0]['url'] ?? '') === 'https://station.internal:8080/aihub/api.php', 'remote target must be the validated station api endpoint');
        hub_test_assert(($proxied[0]['query'] ?? []) === ['task_id' => '42', 'mode' => 'vision'], 'remote target must receive only the fixed API query contract');
        hub_test_assert(($proxied[0]['headers'] ?? []) === [
            'Authorization' => 'Bearer remote_station_token',
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        ], 'remote request must use station auth and the narrow safe header set only');
        hub_test_assert(($proxied[0]['follow_redirects'] ?? true) === false, 'remote dispatch must forbid redirects');
        hub_test_assert(!str_contains(implode("\n", $response['headers']), 'Set-Cookie'), 'remote response headers must remain filtered');
    });
});

hub_test('cluster router preserves Edge TTS GET list and demo requests for self and remote stations', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $voice = 'zh-TW-HsiaoChenNeural';
            $demoUrl = '?mode=edge_tts&voice=' . rawurlencode($voice);
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'edge_tts');
            hub_cluster_node_configure($db, true, ['edge_tts']);
            $self = hub_cluster_register_self_station($db);
            $customer = hub_test_cluster_router_customer_token($db, ['edge_tts']);
            $direct = [];
            $selfSeams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                    'id' => (int)$self['id'], 'station_key' => (string)$self['station_key'], 'modes' => ['edge_tts'],
                ])],
                'direct_dispatcher' => static function (PDO $db, string $mode, array $request) use (&$direct, $voice, $demoUrl): array {
                    $direct[] = $request;
                    if (($request['query']['voice'] ?? null) === $voice) {
                        return [
                            'status' => 200,
                            'headers' => ['Content-Type: audio/mpeg', 'Cache-Control: private, no-store'],
                            'body' => "\x49\x44\x33demo",
                        ];
                    }

                    return hub_gateway_json(200, ['ok' => true, 'voices' => [['id' => $voice, 'demo_url' => $demoUrl]]]);
                },
            ];
            $duplicateUris = [
                '/cluster_api.php?mode=edge_tts&voice=' . rawurlencode($voice) . '&voice=' . rawurlencode($voice),
                '/cluster_api.php?mode=edge_tts&voice[]=x&voice=' . rawurlencode($voice),
                '/cluster_api.php?mode=edge_tts&voice%00x=x&voice=' . rawurlencode($voice),
            ];
            $aliasUris = [
                '/cluster_api.php?mode=edge_tts&voice[]=' . rawurlencode($voice),
                '/cluster_api.php?mode=edge_tts&voice[0]=' . rawurlencode($voice),
                '/cluster_api.php?mode=edge_tts&voice%00x=' . rawurlencode($voice),
            ];
            $selfDuplicates = [];
            foreach ($duplicateUris as $duplicateUri) {
                $selfDuplicates[] = hub_cluster_dispatch($db, 'edge_tts', hub_test_cluster_router_request((string)$customer['plain_token'], [
                    'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => [], 'request_uri' => $duplicateUri,
                ]), $selfSeams);
            }
            $selfAliases = [];
            foreach ($aliasUris as $aliasUri) {
                $selfAliases[] = hub_cluster_dispatch($db, 'edge_tts', hub_test_cluster_router_request((string)$customer['plain_token'], [
                    'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => [], 'request_uri' => $aliasUri,
                ]), $selfSeams);
            }
            $directAfterDuplicate = $direct;
            $selfList = hub_cluster_dispatch($db, 'edge_tts', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'method' => 'GET', 'raw_body' => '', 'query' => [], 'headers' => [],
            ]), $selfSeams);
            $selfDemo = hub_cluster_dispatch($db, 'edge_tts', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => ['Accept' => 'audio/mpeg'],
            ]), $selfSeams);
            $selfListPayload = json_decode((string)$selfList['body'], true);

            hub_test_assert(array_filter($selfDuplicates, static fn (array $response): bool => ($response['status'] ?? 0) !== 400 || !str_contains((string)$response['body'], 'invalid_request')) === []
                && array_filter($selfAliases, static fn (array $response): bool => ($response['status'] ?? 0) !== 400 || !str_contains((string)$response['body'], 'invalid_request')) === []
                && $directAfterDuplicate === []
                && ($selfList['status'] ?? 0) === 200 && ($selfListPayload['voices'][0]['demo_url'] ?? null) === $demoUrl
                && ($selfDemo['body'] ?? '') === "\x49\x44\x33demo"
                && in_array('Cache-Control: private, no-store', $selfDemo['headers'] ?? [], true)
                && array_column($direct, 'method') === ['GET', 'GET']
                && ($direct[0]['query'] ?? []) === ['mode' => 'edge_tts']
                && ($direct[1]['query'] ?? []) === ['voice' => $voice, 'mode' => 'edge_tts'],
                'self routing must retain Edge TTS GET method, normalized query, relative demo URL, and binary response');

            $remoteDb = hub_test_reset_db();
            hub_set_storage_setting($remoteDb, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $remoteCustomer = hub_test_cluster_router_customer_token($remoteDb, ['edge_tts']);
            $remote = hub_test_cluster_router_station($remoteDb, ['station_key' => 'edge_tts_remote', 'station_token' => 'edge_tts_station_token', 'modes' => ['edge_tts']]);
            $proxied = [];
            $remoteSeams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                    'id' => (int)$remote['id'], 'station_key' => 'edge_tts_remote', 'modes' => ['edge_tts'],
                ])],
                'transport' => static function (array $request) use (&$proxied, $voice, $demoUrl): array {
                    $proxied[] = $request;
                    return match (count($proxied)) {
                        1 => ['status' => 200, 'headers' => ['Content-Type: application/json', 'Cache-Control: private, no-store'], 'body' => json_encode(['ok' => true, 'voices' => [['id' => $voice, 'demo_url' => $demoUrl]]], JSON_THROW_ON_ERROR)],
                        2 => ['status' => 200, 'headers' => ['Content-Type: audio/mpeg', 'Cache-Control: private, no-store', 'Content-Disposition: inline; filename="ignored.mp3"'], 'body' => "\x49\x44\x33remote"],
                        default => ['status' => 200, 'headers' => ['Content-Type: audio/mpeg', 'Cache-Control: public, max-age=31536000'], 'body' => "\x49\x44\x33unsafe"],
                    };
                },
            ];
            $remoteDuplicates = [];
            foreach ($duplicateUris as $duplicateUri) {
                $remoteDuplicates[] = hub_cluster_dispatch($remoteDb, 'edge_tts', hub_test_cluster_router_request((string)$remoteCustomer['plain_token'], [
                    'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => [], 'request_uri' => $duplicateUri,
                ]), $remoteSeams);
            }
            $remoteAliases = [];
            foreach ($aliasUris as $aliasUri) {
                $remoteAliases[] = hub_cluster_dispatch($remoteDb, 'edge_tts', hub_test_cluster_router_request((string)$remoteCustomer['plain_token'], [
                    'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => [], 'request_uri' => $aliasUri,
                ]), $remoteSeams);
            }
            $proxiedAfterDuplicate = $proxied;
            $remoteList = hub_cluster_dispatch($remoteDb, 'edge_tts', hub_test_cluster_router_request((string)$remoteCustomer['plain_token'], [
                'method' => 'GET', 'raw_body' => '', 'query' => [], 'headers' => [],
            ]), $remoteSeams);
            $remoteDemo = hub_cluster_dispatch($remoteDb, 'edge_tts', hub_test_cluster_router_request((string)$remoteCustomer['plain_token'], [
                'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => ['Accept' => 'audio/mpeg'],
            ]), $remoteSeams);
            $unsafeCache = hub_cluster_dispatch($remoteDb, 'edge_tts', hub_test_cluster_router_request((string)$remoteCustomer['plain_token'], [
                'method' => 'GET', 'raw_body' => '', 'query' => ['voice' => $voice], 'headers' => [],
            ]), $remoteSeams);
            $remoteListPayload = json_decode((string)$remoteList['body'], true);

            hub_test_assert(array_filter($remoteDuplicates, static fn (array $response): bool => ($response['status'] ?? 0) !== 400 || !str_contains((string)$response['body'], 'invalid_request')) === []
                && array_filter($remoteAliases, static fn (array $response): bool => ($response['status'] ?? 0) !== 400 || !str_contains((string)$response['body'], 'invalid_request')) === []
                && $proxiedAfterDuplicate === []
                && ($remoteList['status'] ?? 0) === 200 && ($remoteListPayload['voices'][0]['demo_url'] ?? null) === $demoUrl
                && ($remoteDemo['body'] ?? '') === "\x49\x44\x33remote"
                && in_array('Cache-Control: private, no-store', $remoteDemo['headers'] ?? [], true)
                && !str_contains(implode("\n", $remoteDemo['headers'] ?? []), 'Content-Disposition')
                && !str_contains(implode("\n", $unsafeCache['headers'] ?? []), 'Cache-Control:')
                && array_column($proxied, 'method') === ['GET', 'GET', 'GET']
                && ($proxied[0]['query'] ?? []) === ['mode' => 'edge_tts']
                && ($proxied[1]['query'] ?? []) === ['voice' => $voice, 'mode' => 'edge_tts'],
                'remote routing must retain Edge TTS GET data and only relay the exact private no-store cache policy');
        });
    });
});

hub_test('cluster router applies bounded native limits to voice multipart fields', function (): void {
    $normalize = static fn (array $post): array => hub_cluster_router_normalize_request('voice_generate', [
        'method' => 'POST',
        'headers' => ['Content-Type' => 'multipart/form-data; boundary=voice-limits'],
        'content_length' => '',
        'post' => $post,
        'files' => [],
        'query' => [],
    ]);
    $validPrompt = str_repeat('p', 1025);
    $validExpectedText = str_repeat('e', 20000);
    $profile = $normalize([
        'operation' => 'profile_prepare',
        'profile_name' => 'Bounded profile',
        'consent_type' => 'self_recorded',
        'prompt_text' => $validPrompt,
        'expected_text' => $validExpectedText,
    ]);
    $text = $normalize(['text' => str_repeat('t', 4096), 'voice_prompt' => str_repeat('v', 1024)]);
    $invalid = [
        $normalize([
            'operation' => 'profile_prepare',
            'profile_name' => 'Oversized profile',
            'consent_type' => 'self_recorded',
            'prompt_text' => str_repeat('p', 20001),
        ]),
        $normalize([
            'operation' => 'profile_prepare',
            'profile_name' => 'Oversized expected text',
            'consent_type' => 'self_recorded',
            'expected_text' => str_repeat('e', 20001),
        ]),
        $normalize(['text' => 'synthesize', 'voice_prompt' => str_repeat('v', 1025)]),
        $normalize(['text' => str_repeat('t', 4097)]),
        $normalize(['text' => 'synthesize', 'unexpected' => 'field']),
        $normalize(['text' => ['nested']]),
        $normalize(['text' => "control\ncharacter"]),
    ];

    hub_test_assert(
        !isset($profile['response'])
        && ($profile['form']['post']['prompt_text'] ?? null) === $validPrompt
        && ($profile['form']['post']['expected_text'] ?? null) === $validExpectedText
        && !isset($text['response']),
        'profile_prepare prompts and expected text through 20000 bytes and synthesis text through 4096 bytes must reach the native contract'
    );
    hub_test_assert(
        array_filter($invalid, static fn (array $result): bool => ($result['response']['status'] ?? 0) !== 400) === [],
        'voice multipart admission must reject contract overflow, ordinary field overflow, unexpected fields, arrays, and control characters'
    );
});

hub_test('cluster router relays validated multipart uploads and rejects malformed forms', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_key' => 'upload_gpu', 'station_token' => 'remote_station_token']);
        $fixture = HUB_ROOT . '/packs/yolo/demo/camera_cat.png';
        $bytes = (int)filesize($fixture);
        $requestBytes = $bytes + strlen('1') + strlen('0.25');
        hub_test_assert($bytes > 0, 'multipart fixture must be available');
        $transportCalls = 0;
        $proxied = [];
        $seams = [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'upload_gpu'])],
            'transport' => static function (array $request) use (&$transportCalls, &$proxied): array {
                $transportCalls++;
                $proxied[] = $request;
                return ['status' => 200, 'headers' => ['Content-Type: image/png'], 'body' => "\x89PNG\r\n\x1a\nrouter"];
            },
        ];

        $multipart = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=client-boundary', 'Accept' => 'image/png'],
            'content_length' => (string)$requestBytes,
            'raw_body' => '',
            'post' => ['real_inference' => '1', 'conf' => '0.25'],
            'files' => ['image' => [
                'name' => 'camera_cat.png',
                'type' => 'image/png',
                'tmp_name' => $fixture,
                'error' => UPLOAD_ERR_OK,
                'size' => $bytes,
            ]],
        ]), $seams);
        $nested = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=client-boundary'],
            'files' => ['image' => ['tmp_name' => [$fixture], 'error' => UPLOAD_ERR_OK]],
        ]), $seams);
        $missing = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=client-boundary'],
            'files' => ['image' => ['tmp_name' => '/missing/file.png', 'error' => UPLOAD_ERR_OK]],
        ]), $seams);
        $badName = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=client-boundary'],
            'files' => ['image' => ['name' => ['camera_cat.png'], 'tmp_name' => $fixture, 'error' => UPLOAD_ERR_OK]],
        ]), $seams);

        hub_test_assert($multipart['status'] === 200 && str_starts_with((string)$multipart['body'], "\x89PNG\r\n\x1a\n"), 'Router must preserve a binary multipart child response');
        hub_test_assert(($proxied[0]['headers'] ?? []) === ['Authorization' => 'Bearer remote_station_token', 'Accept' => 'image/png'], 'multipart relay must replace the client boundary and retain only safe headers');
        hub_test_assert(($proxied[0]['form']['post'] ?? []) === ['real_inference' => '1', 'conf' => '0.25'], 'multipart relay must preserve flat form values');
        hub_test_assert(is_file((string)($proxied[0]['form']['files']['image']['tmp_name'] ?? '')), 'multipart relay must pass a validated temporary upload');
        hub_test_assert($nested['status'] === 400 && $missing['status'] === 400 && $badName['status'] === 400 && str_contains((string)$nested['body'], 'router_request_unsupported') && str_contains((string)$missing['body'], 'router_request_unsupported') && str_contains((string)$badName['body'], 'router_request_unsupported'), 'malformed multipart forms must fail before routing');
        hub_test_assert($transportCalls === 1, 'invalid multipart forms must not begin a dispatch');
        hub_test_assert((int)$db->query('SELECT upload_bytes FROM cluster_route_accesses ORDER BY id DESC LIMIT 1')->fetchColumn() === $requestBytes, 'Router multipart accounting must record the inbound request bytes');
    });
});

hub_test('cluster router scopes multipart form globals for a self station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'vision');
            hub_cluster_node_configure($db, true, ['vision']);
            $station = hub_cluster_register_self_station($db);
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $fixture = HUB_ROOT . '/packs/yolo/demo/camera_cat.png';
            $bytes = (int)filesize($fixture);
            $captured = [];
            $originalPost = $_POST;
            $originalFiles = $_FILES;
            $_POST = ['sentinel' => 'before'];
            $_FILES = ['sentinel' => ['error' => UPLOAD_ERR_NO_FILE]];

            try {
                $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
                    'headers' => ['Content-Type' => 'multipart/form-data; boundary=self-boundary'],
                    'content_length' => (string)$bytes,
                    'raw_body' => '',
                    'post' => ['real_inference' => '1'],
                    'files' => ['image' => [
                        'name' => 'camera_cat.png',
                        'type' => 'image/png',
                        'tmp_name' => $fixture,
                        'error' => UPLOAD_ERR_OK,
                        'size' => $bytes,
                    ]],
                ]), [
                    'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => (string)$station['station_key']])],
                    'direct_dispatcher' => static function (PDO $db, string $mode, array $request) use (&$captured): array {
                        $captured = ['post' => $_POST, 'files' => $_FILES, 'request' => $request];
                        return hub_gateway_json(200, ['ok' => true, 'mode' => $mode]);
                    },
                ]);

                hub_test_assert($response['status'] === 200, 'self station must accept a validated multipart form');
                hub_test_assert(($captured['post'] ?? []) === ['real_inference' => '1'] && is_file((string)($captured['files']['image']['tmp_name'] ?? '')), 'self station must receive normalized multipart values');
                hub_test_assert(!array_key_exists('raw_body', $captured['request'] ?? []), 'self multipart dispatch must not pass an empty raw body to the local gateway');
                hub_test_assert($_POST === ['sentinel' => 'before'] && $_FILES === ['sentinel' => ['error' => UPLOAD_ERR_NO_FILE]], 'self multipart dispatch must restore request globals');
            } finally {
                $_POST = $originalPost;
                $_FILES = $originalFiles;
            }
        });
    });
});

hub_test('cluster router does not retry another station after remote dispatch failure', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $first = hub_test_cluster_router_station($db, ['station_key' => 'first_gpu', 'priority' => 9, 'station_token' => 'first_token']);
        $second = hub_test_cluster_router_station($db, ['station_key' => 'second_gpu', 'priority' => 1, 'station_token' => 'second_token']);
        $calls = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static fn (): array => [
                hub_test_cluster_station_fixture(['id' => (int)$first['id'], 'priority' => 9, 'station_key' => 'first_gpu']),
                hub_test_cluster_station_fixture(['id' => (int)$second['id'], 'priority' => 1, 'station_key' => 'second_gpu']),
            ],
            'transport' => static function () use (&$calls): array {
                $calls++;
                throw new RuntimeException('remote connection failed');
            },
        ]);

        hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_proxy_failed'), 'remote failures must return a generic router error');
        hub_test_assert($calls === 1, 'router must never retry a second station after dispatch begins');
    });
});

hub_test('cluster router atomically admits proxy capacity and releases it after completion', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'capacity_gpu']);
            $request = hub_test_cluster_router_request((string)$token['plain_token']);
            $baseSeams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'capacity_gpu'])],
            ];
            $nested = [];
            $outer = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                'transport' => static function () use (&$nested, $db, $request, $baseSeams): array {
                    $nested = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                        'transport' => static fn (): array => hub_gateway_json(200, ['ok' => true]),
                    ]);
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);
            $after = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                'transport' => static fn (): array => hub_gateway_json(200, ['ok' => true]),
            ]);

            hub_test_assert($outer['status'] === 200 && ($nested['status'] ?? 0) === 429 && str_contains((string)($nested['body'] ?? ''), 'router_busy'), 'active proxy admission must reject at capacity');
            hub_test_assert($after['status'] === 200, 'completed proxy admission must release capacity');
            hub_test_assert((int)$db->query("SELECT COUNT(*) FROM cluster_routes WHERE state = 'proxying'")->fetchColumn() === 0, 'proxy capacity rows must be terminal after dispatch');
        });
    });
});

hub_test('cluster router rejects oversized remote responses without forwarding a partial body', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'large_response_gpu']);

            $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'large_response_gpu'])],
                'transport' => static fn (): array => ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => str_repeat('x', 1024 * 1024 + 1)],
            ]);

            hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_response_too_large'), 'oversized proxy response must have a stable 502');
            hub_test_assert(!str_contains($response['body'], str_repeat('x', 64)), 'oversized proxy response must not leak a partial downstream body');
        });
    });
});

hub_test('cluster router preserves only safe downstream content headers from captured responses', function (): void {
    foreach (['application/json; charset=utf-8', 'image/png', 'audio/mpeg'] as $mime) {
        $response = hub_cluster_router_proxy_response([
            'status' => 200,
            'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nX-3waAIHub-Device: cuda\r\nSet-Cookie: station=session\r\nAuthorization: Bearer leaked\r\nConnection: close\r\nX-Forwarded-For: 203.0.113.1\r\n",
            'body' => 'safe-body',
        ], 'station_token');

        hub_test_assert($response['headers'][0] === 'Content-Type: ' . $mime, 'captured downstream MIME must be preserved');
        hub_test_assert(in_array('X-3waAIHub-Device: cuda', $response['headers'], true), 'allowlisted downstream API header must be preserved');
        hub_test_assert(!str_contains(implode("\n", $response['headers']), 'Cookie') && !str_contains(implode("\n", $response['headers']), 'Authorization') && !str_contains(implode("\n", $response['headers']), 'Forwarded'), 'unsafe downstream headers must be ignored');
    }

    $unsafe = hub_cluster_router_proxy_response([
        'status' => 200,
        'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: text/plain\x00bad\r\n",
        'body' => 'safe-body',
    ], 'station_token');
    hub_test_assert($unsafe['headers'][0] === 'Content-Type: application/octet-stream', 'invalid captured content types must fall back safely');
});

hub_test('cluster router reaps only expired proxy admissions before enforcing capacity', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'reaper_gpu']);
            $request = hub_test_cluster_router_request((string)$token['plain_token']);
            $seams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'reaper_gpu'])],
            ];
            $insert = static function (string $routeId, string $updatedAt) use ($db, $station): void {
                $db->prepare(
                    "INSERT INTO cluster_routes (route_id, station_id, mode, is_async, state, created_at, updated_at)
                     VALUES (:route_id, :station_id, 'vision', 0, 'proxying', :created_at, :updated_at)"
                )->execute([
                    ':route_id' => $routeId,
                    ':station_id' => (int)$station['id'],
                    ':created_at' => $updatedAt,
                    ':updated_at' => $updatedAt,
                ]);
            };
            $staleAt = date('Y-m-d H:i:s', time() - hub_cluster_proxy_stale_after_seconds() - 1);
            $insert('route_stale_proxy', $staleAt);
            $calls = 0;
            $reaped = hub_cluster_dispatch($db, 'vision', $request, $seams + [
                'transport' => static function () use (&$calls): array {
                    $calls++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);

            hub_test_assert($reaped['status'] === 200 && $calls === 1, 'expired proxy rows must not consume new admission capacity');
            hub_test_assert((string)$db->query("SELECT state FROM cluster_routes WHERE route_id = 'route_stale_proxy'")->fetchColumn() === 'failed', 'expired proxy row must be terminalized during admission');

            $insert('route_fresh_proxy', hub_now());
            $fresh = hub_cluster_dispatch($db, 'vision', $request, $seams + [
                'transport' => static function () use (&$calls): array {
                    $calls++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);
            hub_test_assert($fresh['status'] === 429 && $calls === 1, 'fresh proxy rows must retain capacity until completion or expiry');
        });
    });
});

hub_test('cluster router bounds declared and streamed request bodies', function (): void {
    hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_REQUEST_MB', '1', function (): void {
        $limit = hub_cluster_proxy_request_limit_bytes();
        $declared = hub_cluster_router_normalize_request('vision', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'files' => [],
            'content_length' => (string)($limit + 1),
            'raw_body' => '',
            'query' => [],
        ]);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('cannot create request test stream');
        }
        fwrite($stream, str_repeat('x', $limit + 1));
        rewind($stream);
        try {
            $streamed = hub_cluster_router_normalize_request('vision', [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'files' => [],
                'content_length' => '',
                'body_stream' => $stream,
                'query' => [],
            ]);
        } finally {
            fclose($stream);
        }

        hub_test_assert(($declared['response']['status'] ?? 0) === 413 && str_contains((string)($declared['response']['body'] ?? ''), 'router_request_too_large'), 'oversized declared request bodies must fail before reading');
        hub_test_assert(($streamed['response']['status'] ?? 0) === 413 && str_contains((string)($streamed['response']['body'] ?? ''), 'router_request_too_large'), 'oversized unknown-length request streams must stop at the cap');
    });
});

hub_test('cluster router endpoint mode helper rejects nested query values', function (): void {
    hub_test_assert(hub_cluster_router_requested_mode('vision') === 'vision', 'scalar mode must pass through unchanged');
    hub_test_assert(hub_cluster_router_requested_mode('') === null && hub_cluster_router_requested_mode(['vision']) === null, 'empty or nested mode query values must reject without casting');
});

hub_test('cluster router rewrites async task responses without child details', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'async_remote',
            'station_token' => 'async_station_token',
        ]);
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = hub_cluster_router_admit_route($db, $station, [
            'member_id' => $memberId,
            'token_id' => (int)$customer['token_id'],
        ], 'vision', true);
        hub_test_assert(is_string($routeId), 'async route admission must succeed');

        $payload = hub_cluster_rewrite_async_response($db, [
            'route_id' => $routeId,
            'station_id' => (int)$station['id'],
        ], [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'status_url' => 'https://station.internal:8080/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result_url' => 'https://station.internal:8080/aihub/api.php?mode=task_result&task_id=remote_task_42',
            'log_url' => 'https://station.internal:8080/aihub/api.php?mode=task_log&task_id=remote_task_42',
            'cancel_url' => 'https://station.internal:8080/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
            'artifact_url_template' => 'https://station.internal:8080/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ], 'https://router.example/cluster_api.php');

        $links = hub_cluster_router_task_links($routeId, 'https://router.example/cluster_api.php');
        hub_test_assert($payload['task_id'] === $routeId && array_intersect_assoc($links, $payload) === $links, 'async response must expose only opaque router task links');
        hub_test_assert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'remote_task_42') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'station.internal'), 'async response must not leak child task or station details');
        $route = $db->query("SELECT remote_task_id, is_async, state FROM cluster_routes WHERE route_id = '" . $routeId . "'")->fetch();
        hub_test_assert($route === ['remote_task_id' => 'remote_task_42', 'is_async' => 1, 'state' => 'active'], 'async route must persist the remote task as active');
    });
});

hub_test('cluster router maps pre-run task statuses to queued', function (): void {
    foreach (['staging', 'waiting_gpu'] as $status) {
        $payload = hub_cluster_router_rewrite_task_payload(
            hub_test_reset_db(),
            ['route_id' => 'router_task_123', 'mode' => 'vision'],
            ['ok' => true, 'task_id' => 'remote_task_42', 'status' => $status],
            'cluster_api.php',
            'remote_task_42',
            'status'
        );

        hub_test_assert(
            ($payload['status'] ?? null) === 'queued'
            && ($payload['progress'] ?? null) === 0
            && ($payload['message'] ?? null) === 'Queued.'
            && array_key_exists('status', $payload)
            && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), $status),
            $status . ' must project to a fixed public cluster status payload'
        );
    }
});

hub_test('cluster router relays bounded GPU wait diagnostics without child details', function (): void {
    $payload = hub_cluster_router_rewrite_task_payload(
        hub_test_reset_db(),
        ['route_id' => 'router_task_wait', 'mode' => 'speech_transcribe'],
        [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'status' => 'waiting_gpu',
            'waiting_reason' => 'unmanaged_gpu_process',
            'required_vram_mb' => 10000,
            'free_vram_mb' => 768,
            'retry_after_seconds' => 30,
            'gpu_processes' => [[
                'pid' => 731,
                'process_name' => 'ffmpeg',
                'used_vram_mb' => 512,
                'classification' => 'external',
            ]],
        ],
        'cluster_api.php',
        'remote_task_42',
        'status'
    );

    hub_test_assert(($payload['status'] ?? '') === 'queued'
        && ($payload['waiting_reason'] ?? '') === 'unmanaged_gpu_process'
        && ($payload['required_vram_mb'] ?? null) === 10000
        && ($payload['free_vram_mb'] ?? null) === 768
        && ($payload['retry_after_seconds'] ?? null) === 30
        && ($payload['gpu_processes'][0]['process_name'] ?? '') === 'ffmpeg'
        && ($payload['message'] ?? '') === 'Waiting for GPU memory used by another process.'
        && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'remote_task_42'),
        'Router must relay only the stable GPU scheduling snapshot');
});

hub_test('cluster router rewrites fake remote async dispatch responses', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_token' => 'initial_async_station_token']);

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id']])],
            'transport' => static function (array $request): array {
                hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer initial_async_station_token', 'initial async dispatch must use the selected station token');
                return hub_gateway_json(200, [
                    'ok' => true,
                    'task_id' => 'remote_task_42',
                    'status_url' => 'https://station.internal:8080/aihub/api.php?mode=task_status&task_id=remote_task_42',
                    'result_url' => 'https://station.internal:8080/aihub/api.php?mode=task_result&task_id=remote_task_42',
                    'log_url' => 'https://station.internal:8080/aihub/api.php?mode=task_log&task_id=remote_task_42',
                    'cancel_url' => 'https://station.internal:8080/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
                    'artifact_url_template' => 'https://station.internal:8080/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
                ]);
            },
        ]);

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $route = $db->query('SELECT route_id, remote_task_id, state FROM cluster_routes ORDER BY created_at DESC LIMIT 1')->fetch();
        hub_test_assert($response['status'] === 200 && $payload['task_id'] === $route['route_id'] && ($route['remote_task_id'] ?? '') === 'remote_task_42' && ($route['state'] ?? '') === 'active', 'initial remote async dispatch must persist and return an opaque active route');
        hub_test_assert(!str_contains($response['body'], 'remote_task_42') && !str_contains($response['body'], 'station.internal') && str_contains($payload['status_url'], 'cluster_api.php?mode=cluster_task_status&task_id='), 'initial remote async dispatch must expose only router links');
    });
});

hub_test('cluster router preserves allowlisted headers on non-profile rewritten JSON', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'non_profile_headers',
            'station_token' => 'non_profile_headers_token',
        ]);
        $childHeaders = [
            'Content-Type: application/json',
            'X-3waAIHub-Model: vision-model',
            'X-3waAIHub-Device: cuda',
            'Cache-Control: private, no-store',
        ];
        $initial = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                'id' => (int)$station['id'],
                'station_key' => 'non_profile_headers',
            ])],
            'transport' => static fn (): array => [
                'status' => 200,
                'headers' => $childHeaders,
                'body' => json_encode(['ok' => true, 'task_id' => 'remote_task_42', 'status' => 'queued'], JSON_THROW_ON_ERROR),
            ],
        ]);
        $routeId = (string)(json_decode($initial['body'], true, 64, JSON_THROW_ON_ERROR)['task_id'] ?? '');
        $followup = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$customer['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $routeId],
        ], static fn (): array => [
            'status' => 200,
            'headers' => $childHeaders,
            'body' => json_encode(['ok' => true, 'task_id' => 'remote_task_42', 'status' => 'running'], JSON_THROW_ON_ERROR),
        ]);

        foreach ([$initial, $followup] as $response) {
            $headers = $response['headers'] ?? [];
            hub_test_assert($response['status'] === 200
                && in_array('X-3waAIHub-Model: vision-model', $headers, true)
                && in_array('X-3waAIHub-Device: cuda', $headers, true)
                && in_array('Cache-Control: private, no-store', $headers, true), 'non-profile async and followup rewrites must preserve sanitized allowlisted child headers');
        }
    });
});

hub_test('cluster router preserves ordinary voice design headers and logs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'voice_design_headers',
            'station_token' => 'voice_design_headers_token',
            'modes' => ['voice_generate'],
        ]);
        $childHeaders = [
            'Content-Type: application/json',
            'X-3waAIHub-Model: voice-design-model',
            'X-3waAIHub-Device: cuda',
            'X-3waAIHub-Elapsed-Ms: 17',
            'Cache-Control: private, no-store',
        ];
        $submitted = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => '{"mode":"design","text":"ordinary design"}',
        ]), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                'id' => (int)$station['id'],
                'station_key' => 'voice_design_headers',
                'modes' => ['voice_generate'],
            ])],
            'transport' => static fn (): array => [
                'status' => 200,
                'headers' => $childHeaders,
                'body' => json_encode(['ok' => true, 'task_id' => '64201', 'status' => 'queued'], JSON_THROW_ON_ERROR),
            ],
        ]);
        $routeId = (string)(json_decode($submitted['body'], true, 64, JSON_THROW_ON_ERROR)['task_id'] ?? '');
        hub_test_assert(hub_cluster_router_valid_route_id($routeId)
            && !hub_cluster_router_profile_sensitive_route_id($routeId), 'ordinary voice design must keep the legacy non-sensitive opaque route form');
        $clone = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => '{"operation":"synthesize","mode":"clone","text":"profile-free clone"}',
        ]), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture([
                'id' => (int)$station['id'],
                'station_key' => 'voice_design_headers',
                'modes' => ['voice_generate'],
            ])],
            'transport' => static fn (): array => [
                'status' => 200,
                'headers' => $childHeaders,
                'body' => json_encode(['ok' => true, 'task_id' => '64202', 'status' => 'queued'], JSON_THROW_ON_ERROR),
            ],
        ]);
        $cloneRouteId = (string)(json_decode($clone['body'], true, 64, JSON_THROW_ON_ERROR)['task_id'] ?? '');
        hub_test_assert(hub_cluster_router_valid_route_id($cloneRouteId)
            && !hub_cluster_router_profile_sensitive_route_id($cloneRouteId), 'profile-free clone must remain a non-sensitive opaque route');
        $logs = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$customer['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $routeId],
        ], static fn (): array => [
            'status' => 200,
            'headers' => $childHeaders,
            'body' => json_encode([
                'ok' => true,
                'task_id' => '64201',
                'logs' => [[
                    'level' => 'info',
                    'message' => 'ordinary design synthesis queued',
                    'created_at' => '2026-07-31 12:00:00',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $logPayload = json_decode($logs['body'], true, 64, JSON_THROW_ON_ERROR);

        foreach ([$submitted, $clone, $logs] as $response) {
            $headers = $response['headers'] ?? [];
            hub_test_assert(in_array('X-3waAIHub-Model: voice-design-model', $headers, true)
                && in_array('X-3waAIHub-Device: cuda', $headers, true)
                && in_array('X-3waAIHub-Elapsed-Ms: 17', $headers, true)
                && in_array('Cache-Control: private, no-store', $headers, true), 'ordinary voice design rewrites must preserve sanitized allowlisted headers');
        }
        hub_test_assert(($logPayload['logs'][0]['message'] ?? null) === 'ordinary design synthesis queued', 'ordinary voice design routes must retain the non-profile safe log projection');
    });
});

hub_test('cluster router extracts and replaces one exact voice profile route without changing unrelated input', function (): void {
    $routeId = 'route_' . str_repeat('a', 32);
    $wav = ['name' => 'reference.wav', 'type' => 'audio/wav', 'tmp_name' => '/tmp/reference.wav', 'error' => UPLOAD_ERR_OK, 'size' => 44];
    $multipart = [
        'headers' => [],
        'raw_body' => '',
        'form' => [
            'post' => ['operation' => 'synthesize', 'voice_profile_task_id' => $routeId, 'text' => 'A + B'],
            'files' => ['reference_wav' => $wav],
        ],
    ];
    $urlencoded = [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'],
        'raw_body' => 'operation=profile_confirm&voice_profile_task_id=' . $routeId . '&prompt_text=A+%2B+B&callback=x%2Fy',
    ];
    $json = [
        'headers' => ['Content-Type' => 'application/json'],
        'raw_body' => '{"operation":"profile_status","voice_profile_task_id":"' . $routeId . '","note":"a\\/b","count":1}',
    ];
    $query = [
        'method' => 'GET',
        'headers' => [],
        'raw_body' => '',
        'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => $routeId, 'mode' => 'voice_generate'],
        'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice_profile_task_id=' . $routeId,
    ];

    hub_test_assert(hub_cluster_voice_profile_reference($multipart) === $routeId, 'multipart reference must be read from its normalized scalar form field');
    hub_test_assert(hub_cluster_voice_profile_reference($urlencoded) === $routeId, 'urlencoded reference must be decoded exactly once');
    hub_test_assert(hub_cluster_voice_profile_reference($json) === $routeId, 'JSON reference must be read only from the top-level object');
    hub_test_assert(hub_cluster_voice_profile_reference($query) === $routeId, 'query reference must be read from one exact scalar field');
    hub_test_assert(hub_cluster_voice_profile_reference([
        'headers' => ['Content-Type' => 'application/json'],
        'raw_body' => '{"nested":{"voice_profile_task_id":"' . $routeId . '"}}',
    ]) === null, 'nested JSON references must not pin a route');

    $multipartDownstream = hub_cluster_replace_voice_profile_reference($multipart, '73');
    $urlencodedDownstream = hub_cluster_replace_voice_profile_reference($urlencoded, '73');
    $jsonDownstream = hub_cluster_replace_voice_profile_reference($json, '73');
    $queryDownstream = hub_cluster_replace_voice_profile_reference($query, '73');
    hub_test_assert($multipart['form']['post']['voice_profile_task_id'] === $routeId, 'replacement must not mutate the public multipart request');
    hub_test_assert($multipartDownstream['form']['post'] === ['operation' => 'synthesize', 'voice_profile_task_id' => '73', 'text' => 'A + B'], 'multipart replacement must change only the trusted reference field');
    hub_test_assert($multipartDownstream['form']['files']['reference_wav'] === $wav, 'multipart replacement must preserve the normalized WAV upload');
    hub_test_assert($urlencodedDownstream['raw_body'] === 'operation=profile_confirm&voice_profile_task_id=73&prompt_text=A+%2B+B&callback=x%2Fy', 'urlencoded replacement must preserve every unrelated byte');
    hub_test_assert($jsonDownstream['raw_body'] === '{"operation":"profile_status","voice_profile_task_id":"73","note":"a\\/b","count":1}', 'JSON replacement must preserve every unrelated byte');
    hub_test_assert($queryDownstream['query'] === ['operation' => 'profile_status', 'voice_profile_task_id' => '73', 'mode' => 'voice_generate'], 'query replacement must change only the trusted normalized reference');
    hub_test_assert(hub_cluster_voice_profile_reference($multipartDownstream) === '73'
        && hub_cluster_voice_profile_reference($urlencodedDownstream) === '73'
        && hub_cluster_voice_profile_reference($jsonDownstream) === '73'
        && hub_cluster_voice_profile_reference($queryDownstream) === '73', 'each downstream copy must contain exactly one numeric child reference');

    foreach ([
        ['form' => ['post' => ['voice_profile_task_id' => [$routeId]], 'files' => []]],
        ['form' => ['post' => ['voice_profile_task_id[]' => $routeId], 'files' => []]],
        ['form' => ['post' => ['voice_profile_task_id' => $routeId, 'voice_profile_task_id[]' => $routeId], 'files' => []]],
        ['form' => ['post' => ['voice_profile_task_id' => $routeId, 'voice.profile.task.id' => '999'], 'files' => []]],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'voice_profile_task_id=' . $routeId . '&voice_profile_task_id=' . $routeId],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'voice_profile_task_id%5B%5D=' . $routeId],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'voice_profile_task_id=' . $routeId . '&voice.profile.task.id=999'],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'voice_profile_task_id=' . $routeId . '&voice+profile+task+id=999'],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'voice_profile_task_id=' . $routeId . '%0A'],
        ['headers' => ['Content-Type' => 'application/x-www-form-urlencoded'], 'raw_body' => 'note=%GG&voice_profile_task_id=' . $routeId],
        ['headers' => ['Content-Type' => 'application/json'], 'raw_body' => '{"voice_profile_task_id":"' . $routeId . '","voice_profile_task_id":"' . $routeId . '"}'],
        ['headers' => ['Content-Type' => 'application/json'], 'raw_body' => '{"voice_profile_task_id":"' . $routeId . '","voice.profile.task.id":"999"}'],
        ['headers' => ['Content-Type' => 'application/json'], 'raw_body' => '{"voice_profile_task_id":["' . $routeId . '"]}'],
        ['headers' => ['Content-Type' => 'application/json'], 'raw_body' => '{"voice_profile_task_id":"' . $routeId . '"'],
        [
            'method' => 'GET',
            'headers' => [],
            'raw_body' => '',
            'query' => ['voice_profile_task_id' => '999'],
            'request_uri' => '/cluster_api.php?voice.profile.task.id=999',
        ],
        [
            'method' => 'GET',
            'headers' => [],
            'raw_body' => '',
            'query' => ['voice_profile_task_id' => $routeId],
            'request_uri' => '/cluster_api.php?voice_profile_task_id=' . $routeId . '&voice_profile_task_id=999',
        ],
        [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'raw_body' => 'voice_profile_task_id=' . $routeId,
            'query' => ['voice_profile_task_id' => $routeId],
            'request_uri' => '/cluster_api.php?voice_profile_task_id=' . $routeId,
        ],
    ] as $ambiguous) {
        hub_test_assert(hub_test_throws(static fn (): ?string => hub_cluster_voice_profile_reference($ambiguous)), 'ambiguous or malformed profile references must be rejected');
    }
});

hub_test('cluster router rejects profile key aliases and unresolved numeric query references before transport', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_ambiguity',
            'station_token' => 'profile_ambiguity_token',
        ], '246813579');
        $refreshes = 0;
        $calls = 0;
        $seams = [
            'refresh_due' => static function () use (&$refreshes, $fixture): array {
                $refreshes++;
                return [hub_test_cluster_station_fixture([
                    'id' => (int)$fixture['station']['id'],
                    'station_key' => 'profile_ambiguity',
                    'modes' => ['voice_generate'],
                ])];
            },
            'transport' => static function () use (&$calls): array {
                $calls++;
                return hub_gateway_json(200, hub_test_cluster_voice_profile_status_payload());
            },
        ];
        $requests = [
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'] . '&voice.profile.task.id=999',
                ]),
                400,
                'invalid_request',
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'method' => 'GET',
                    'headers' => [],
                    'raw_body' => '',
                    'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => '999'],
                    'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice_profile_task_id=999',
                ]),
                404,
                'profile_task_not_found',
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'method' => 'GET',
                    'headers' => [],
                    'raw_body' => '',
                    'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => '999'],
                    'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice.profile.task.id=999',
                ]),
                400,
                'invalid_request',
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'method' => 'GET',
                    'headers' => [],
                    'raw_body' => '',
                    'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => '999'],
                    'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice_profile_task_id=' . $fixture['route_id'] . '&voice_profile_task_id=999',
                ]),
                400,
                'invalid_request',
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'query' => ['voice_profile_task_id' => $fixture['route_id']],
                    'request_uri' => '/cluster_api.php?mode=voice_generate&voice_profile_task_id=' . $fixture['route_id'],
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
                ]),
                400,
                'invalid_request',
            ],
        ];

        foreach ($requests as [$request, $status, $code]) {
            $response = hub_cluster_dispatch($db, 'voice_generate', $request, $seams);
            hub_test_assert($response['status'] === $status && str_contains($response['body'], $code), 'ambiguous or unresolved profile reference must fail with the exact pre-transport error');
        }
        hub_test_assert($refreshes === 0 && $calls === 0, 'ambiguous aliases and unresolved numeric query references must fail before inventory and transport');
    });
});

hub_test('cluster router rewrites clone synthesis without an operation field', function (): void {
    hub_test_cluster_voice_profile_synthesis_without_operation('clone');
});

hub_test('cluster router rewrites ultimate clone synthesis without an operation field', function (): void {
    hub_test_cluster_voice_profile_synthesis_without_operation('ultimate_clone');
});

hub_test('cluster router rebuilds rewritten profile JSON headers without child metadata', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'profile_headers',
            'station_token' => 'profile_headers_token',
            'modes' => ['voice_generate'],
        ]);
        $inventory = [hub_test_cluster_station_fixture([
            'id' => (int)$station['id'],
            'station_key' => 'profile_headers',
            'modes' => ['voice_generate'],
        ])];
        $maliciousHeaders = [
            'Content-Type: text/html',
            'X-3waAIHub-Model: voice_profile_id=991337',
            'X-3waAIHub-Device: /srv/private/profiles/reference.wav',
            'X-3waAIHub-Elapsed-Ms: owner transcript',
            'Cache-Control: private, no-store',
        ];
        $childTaskId = '75319';

        $prepared = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=profile-headers'],
            'raw_body' => '',
            'post' => ['operation' => 'profile_prepare', 'profile_name' => 'Header profile'],
            'files' => [],
        ]), [
            'refresh_due' => static fn (): array => $inventory,
            'transport' => static fn (): array => [
                'status' => 200,
                'headers' => $maliciousHeaders,
                'body' => json_encode(['ok' => true, 'task_id' => $childTaskId], JSON_THROW_ON_ERROR),
            ],
        ]);
        $profileRoute = (string)(json_decode($prepared['body'], true, 64, JSON_THROW_ON_ERROR)['task_id'] ?? '');
        hub_test_assert(hub_cluster_router_profile_sensitive_route_id($profileRoute), 'profile_prepare must persist its sensitivity in the opaque route');
        $status = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $profileRoute,
        ]), [
            'refresh_due' => static fn (): array => $inventory,
            'transport' => static fn (): array => [
                'status' => 200,
                'headers' => $maliciousHeaders,
                'body' => json_encode(hub_test_cluster_voice_profile_status_payload(), JSON_THROW_ON_ERROR),
            ],
        ]);

        foreach ([$prepared, $status] as $response) {
            $headers = $response['headers'] ?? [];
            hub_test_assert($response['status'] === 200
                && ($headers[0] ?? '') === 'Content-Type: application/json; charset=utf-8'
                && ($headers[1] ?? '') === 'X-Content-Type-Options: nosniff'
                && count($headers) === 3
                && preg_match('/\AX-3waAIHub-Request-Id: [A-Za-z0-9_-]{1,128}\z/', (string)($headers[2] ?? '')) === 1, 'rewritten profile JSON must use only the fixed safe header set and Router request ID');
            hub_test_assert(!str_contains(implode("\n", $headers), 'voice_profile_id')
                && !str_contains(implode("\n", $headers), '/srv/private')
                && !str_contains(implode("\n", $headers), 'owner transcript')
                && !str_contains(implode("\n", $headers), 'text/html'), 'rewritten profile JSON headers must not inherit child-controlled metadata');
        }
    });
});

hub_test('cluster router keeps remote profile operations and synthesis on the prepare station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $preparedStation = hub_test_cluster_router_station($db, [
            'station_key' => 'profile_origin',
            'station_token' => 'profile_origin_token',
            'internal_base_url' => 'https://profile-origin.internal/aihub',
            'priority' => 20,
            'modes' => ['voice_generate'],
        ]);
        $loadedStation = hub_test_cluster_router_station($db, [
            'station_key' => 'profile_loaded',
            'station_token' => 'profile_loaded_token',
            'internal_base_url' => 'https://profile-loaded.internal/aihub',
            'priority' => 1,
            'modes' => ['voice_generate'],
        ]);
        $originInventory = hub_test_cluster_station_fixture([
            'id' => (int)$preparedStation['id'],
            'station_key' => 'profile_origin',
            'priority' => 20,
            'modes' => ['voice_generate'],
            'gpu_free_vram_mb' => 1024,
        ]);
        $loadedInventory = hub_test_cluster_station_fixture([
            'id' => (int)$loadedStation['id'],
            'station_key' => 'profile_loaded',
            'priority' => 99,
            'modes' => ['voice_generate'],
            'gpu_free_vram_mb' => 65536,
        ]);
        $wavPath = tempnam(sys_get_temp_dir(), 'cluster-profile-');
        if ($wavPath === false) {
            throw new RuntimeException('Cannot create cluster profile WAV fixture.');
        }
        file_put_contents($wavPath, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $remotePrepareTaskId = '987654321012345678';
        $requests = [];

        try {
            $prepared = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=cluster-profile'],
                'raw_body' => '',
                'post' => [
                    'operation' => 'profile_prepare',
                    'profile_name' => 'Cluster profile',
                    'consent_type' => 'self_recorded',
                ],
                'files' => ['reference_wav' => [
                    'name' => 'reference.wav',
                    'type' => 'audio/wav',
                    'tmp_name' => $wavPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($wavPath),
                ]],
            ]), [
                'refresh_due' => static fn (): array => [$originInventory, array_replace($loadedInventory, ['priority' => 1])],
                'transport' => static function (array $request) use (&$requests, $wavPath, $remotePrepareTaskId): array {
                    $requests[] = $request;
                    hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer profile_origin_token', 'profile_prepare must use normal station selection');
                    hub_test_assert(($request['form']['post']['operation'] ?? '') === 'profile_prepare'
                        && ($request['form']['post']['profile_name'] ?? '') === 'Cluster profile'
                        && ($request['form']['files']['reference_wav']['tmp_name'] ?? '') === $wavPath, 'profile_prepare multipart fields and WAV must survive relay');
                    return hub_gateway_json(200, [
                        'ok' => true,
                        'task_id' => $remotePrepareTaskId,
                        'status_url' => 'https://profile-origin.internal/aihub/api.php?mode=task_status&task_id=' . $remotePrepareTaskId,
                    ]);
                },
            ]);
            $preparedPayload = json_decode($prepared['body'], true, 64, JSON_THROW_ON_ERROR);
            $profileRoute = (string)($preparedPayload['task_id'] ?? '');
            hub_test_assert($prepared['status'] === 200 && hub_cluster_router_valid_route_id($profileRoute), 'profile_prepare must return one opaque Router task handle');
            hub_test_assert(!str_contains($prepared['body'], $remotePrepareTaskId) && !str_contains($prepared['body'], 'profile-origin.internal'), 'profile_prepare response must hide child task and station details');

            $cases = [
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                        'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $profileRoute,
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['body'] === 'operation=profile_status&voice_profile_task_id=' . $remotePrepareTaskId, 'profile_status must receive only the numeric child task ID');
                    },
                    hub_test_cluster_voice_profile_status_payload([
                        'transcript_confirmed' => false,
                        'prompt_text_confirmed_at' => null,
                        'prompt_text' => 'owner draft',
                    ]),
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'application/json'],
                        'raw_body' => '{"operation":"profile_confirm","voice_profile_task_id":"' . $profileRoute . '","prompt_text":"owner draft"}',
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['body'] === '{"operation":"profile_confirm","voice_profile_task_id":"' . $remotePrepareTaskId . '","prompt_text":"owner draft"}', 'profile_confirm JSON must replace only the opaque route');
                    },
                    hub_test_cluster_voice_profile_confirmation_payload($remotePrepareTaskId, 'owner draft'),
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                        'raw_body' => 'operation=profile_delete&voice_profile_task_id=' . $profileRoute,
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['body'] === 'operation=profile_delete&voice_profile_task_id=' . $remotePrepareTaskId, 'profile_delete must receive only the numeric child task ID');
                    },
                    hub_test_cluster_voice_profile_status_payload([
                        'profile_status' => 'deleted',
                        'transcription_status' => 'failed',
                        'transcription_error' => null,
                        'reference_audio_sha256' => '',
                    ]),
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'method' => 'GET',
                        'headers' => [],
                        'raw_body' => '',
                        'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => $profileRoute],
                        'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice_profile_task_id=' . $profileRoute,
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['method'] === 'GET'
                            && $request['query'] === [
                                'operation' => 'profile_status',
                                'voice_profile_task_id' => $remotePrepareTaskId,
                                'mode' => 'voice_generate',
                            ], 'GET profile_status must resolve and replace its opaque query route');
                    },
                    hub_test_cluster_voice_profile_status_payload([
                        'transcript_confirmed' => false,
                        'prompt_text_confirmed_at' => null,
                        'prompt_text' => 'owner query draft',
                    ]),
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                        'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $profileRoute,
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['body'] === 'operation=profile_status&voice_profile_task_id=' . $remotePrepareTaskId, 'expired profile_status must receive only the numeric child task ID');
                    },
                    hub_test_cluster_voice_profile_status_payload([
                        'profile_status' => 'expired',
                        'transcription_status' => 'failed',
                        'transcription_error' => null,
                        'reference_audio_sha256' => '',
                    ]),
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'multipart/form-data; boundary=cluster-clone'],
                        'raw_body' => '',
                        'post' => ['operation' => 'synthesize', 'mode' => 'clone', 'voice_profile_task_id' => $profileRoute, 'text' => 'clone me'],
                        'files' => [],
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert(($request['form']['post'] ?? []) === [
                            'operation' => 'synthesize',
                            'mode' => 'clone',
                            'voice_profile_task_id' => $remotePrepareTaskId,
                            'text' => 'clone me',
                        ], 'clone multipart fields must keep their shape with only the child task substituted');
                    },
                    ['ok' => true, 'task_id' => '987654321012345677'],
                ],
                [
                    hub_test_cluster_router_request((string)$customer['plain_token'], [
                        'headers' => ['Content-Type' => 'application/json'],
                        'raw_body' => '{"operation":"synthesize","mode":"ultimate_clone","voice_profile_task_id":"' . $profileRoute . '","text":"ultimate me"}',
                    ]),
                    static function (array $request) use ($remotePrepareTaskId): void {
                        hub_test_assert($request['body'] === '{"operation":"synthesize","mode":"ultimate_clone","voice_profile_task_id":"' . $remotePrepareTaskId . '","text":"ultimate me"}', 'ultimate clone JSON must replace only the opaque route');
                    },
                    ['ok' => true, 'task_id' => '987654321012345676'],
                ],
            ];
            $responses = [];
            foreach ($cases as [$profileRequest, $assertRequest, $childPayload]) {
                $responses[] = hub_cluster_dispatch($db, 'voice_generate', $profileRequest, [
                    'refresh_due' => static fn (): array => [$loadedInventory, array_replace($originInventory, ['priority' => 1, 'gpu_free_vram_mb' => 128])],
                    'transport' => static function (array $request) use (&$requests, $assertRequest, $childPayload): array {
                        $requests[] = $request;
                        hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer profile_origin_token'
                            && $request['url'] === 'https://profile-origin.internal/aihub/api.php', 'task-addressed profile requests must ignore a better-loaded station');
                        $assertRequest($request);
                        return hub_gateway_json(200, $childPayload);
                    },
                ]);
            }

            hub_test_assert(count($requests) === 8, 'prepare plus seven pinned profile requests must dispatch exactly once each');
            hub_test_assert(str_contains($responses[0]['body'], 'owner draft'), 'profile_status may return the owner unconfirmed transcript draft');
            hub_test_assert(str_contains($responses[3]['body'], 'owner query draft'), 'GET profile_status may return the owner unconfirmed transcript draft');
            $confirmedPayload = json_decode($responses[1]['body'], true, 64, JSON_THROW_ON_ERROR);
            $deletedPayload = json_decode($responses[2]['body'], true, 64, JSON_THROW_ON_ERROR);
            $expiredPayload = json_decode($responses[4]['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert(
                ($confirmedPayload['voice_profile_task_id'] ?? null) === $profileRoute
                && ($confirmedPayload['prompt_text_sha256'] ?? null) === hash('sha256', 'owner draft')
                && !array_key_exists('prompt_text', $confirmedPayload),
                'profile_confirm must replace verified child proof with the opaque Router handle without returning transcript text'
            );
            hub_test_assert(
                ($deletedPayload['profile_status'] ?? null) === 'deleted'
                && ($deletedPayload['transcription_status'] ?? null) === 'failed'
                && array_key_exists('transcription_error', $deletedPayload)
                && $deletedPayload['transcription_error'] === null
                && ($deletedPayload['reference_audio_sha256'] ?? null) === '',
                'deleted Profile tombstones must remain relayable with a null transcription error'
            );
            hub_test_assert(
                ($expiredPayload['profile_status'] ?? null) === 'expired'
                && ($expiredPayload['transcription_status'] ?? null) === 'failed'
                && array_key_exists('transcription_error', $expiredPayload)
                && $expiredPayload['transcription_error'] === null
                && ($expiredPayload['reference_audio_sha256'] ?? null) === '',
                'expired Profile tombstones must remain relayable with a null transcription error'
            );
            foreach ($responses as $index => $response) {
                hub_test_assert($response['status'] === 200, 'all pinned profile operations must preserve successful child responses');
                foreach ([$remotePrepareTaskId, '987654321012345677', '987654321012345676', 'profile-origin.internal', 'profile_loaded_token', '/private/profile.wav', '"voice_profile_id"'] as $private) {
                    hub_test_assert(!str_contains($response['body'], $private), 'Router profile response leaked child detail: ' . $private);
                }
                if ($index !== 1) {
                    hub_test_assert(!str_contains($response['body'], '"voice_profile_task_id"'), 'only profile_confirm may return its opaque proof handle');
                }
            }
            $routes = $db->query("SELECT route_id, station_id, remote_task_id FROM cluster_routes WHERE mode = 'voice_generate' AND is_async = 1 ORDER BY created_at, route_id")->fetchAll();
            hub_test_assert(count($routes) === 3
                && array_unique(array_map(static fn (array $route): int => (int)$route['station_id'], $routes)) === [(int)$preparedStation['id']]
                && array_filter($routes, static fn (array $route): bool => !hub_cluster_router_profile_sensitive_route_id((string)$route['route_id'])) === [], 'prepare and profile-backed synthesized tasks must remain pinned and persist profile sensitivity');
            $accounting = $db->query("SELECT request_id, error_code FROM cluster_route_accesses WHERE mode = 'voice_generate'")->fetchAll();
            hub_test_assert(!str_contains(json_encode($accounting, JSON_THROW_ON_ERROR), $remotePrepareTaskId), 'cluster access accounting must not record child task IDs');
        } finally {
            @unlink($wavPath);
        }
    });
});

hub_test('cluster router rejects missing forged or malformed profile confirmation proof', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_confirmation_proof',
            'station_token' => 'profile_confirmation_proof_token',
        ], '42');
        $inventory = hub_test_cluster_station_fixture([
            'id' => (int)$fixture['station']['id'],
            'station_key' => 'profile_confirmation_proof',
            'modes' => ['voice_generate'],
        ]);
        $reviewed = str_repeat('界', 20000);
        $request = hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => $fixture['route_id'],
                'prompt_text' => $reviewed,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $valid = hub_test_cluster_voice_profile_confirmation_payload('42', $reviewed);
        $validationError = [
            'cer' => null,
            'status' => 'error',
            'needs_confirmation' => true,
            'normalizer' => 's2twp-strip-punctuation-v1',
            'error' => 'transcript_validation_failed',
        ];
        $missingTask = $valid;
        unset($missingTask['voice_profile_task_id']);
        $missingHash = $valid;
        unset($missingHash['prompt_text_sha256']);
        $cases = [
            $missingTask,
            $missingHash,
            array_replace($valid, ['voice_profile_task_id' => 42]),
            array_replace($valid, ['voice_profile_task_id' => '43']),
            array_replace($valid, ['voice_profile_task_id' => ['42']]),
            array_replace($valid, ['prompt_text_sha256' => hash('sha256', 'forged text')]),
            array_replace($valid, ['prompt_text_sha256' => strtoupper(hash('sha256', $reviewed))]),
            $valid + ['voice_profile_id' => 91],
            array_replace($valid, ['validation' => [
                'cer' => 0.0,
                'status' => 'clean',
                'needs_confirmation' => false,
                'normalizer' => 'opencc-s2twp-v1',
                'token' => 'nested-confirmation-secret',
                'prompt_text' => 'nested-private-prompt',
            ]]),
        ];
        $calls = 0;
        $accepted = hub_cluster_dispatch($db, 'voice_generate', $request, [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static function (array $request) use (&$calls, $valid, $reviewed): array {
                $calls++;
                $downstream = json_decode((string)($request['body'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                hub_test_assert(
                    ($downstream['voice_profile_task_id'] ?? null) === '42'
                    && ($downstream['prompt_text'] ?? null) === $reviewed,
                    'Router normalization must replace only the opaque handle and preserve all 20,000 Unicode prompt bytes'
                );
                return hub_gateway_json(200, $valid);
            },
        ]);
        $acceptedPayload = json_decode($accepted['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(
            $accepted['status'] === 200
            && ($acceptedPayload['voice_profile_task_id'] ?? null) === $fixture['route_id']
            && ($acceptedPayload['prompt_text_sha256'] ?? null) === hash('sha256', $reviewed)
            && !array_key_exists('prompt_text', $acceptedPayload),
            'valid exact-byte confirmation proof must return only its opaque route and authoritative hash'
        );
        $acceptedError = hub_cluster_dispatch($db, 'voice_generate', $request, [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static function (array $request) use (&$calls, $valid, $validationError, $reviewed): array {
                $calls++;
                $downstream = json_decode((string)($request['body'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                hub_test_assert(($downstream['prompt_text'] ?? null) === $reviewed, 'confirmation error proof must retain the exact reviewed bytes');
                return hub_gateway_json(200, array_replace($valid, ['validation' => $validationError]));
            },
        ]);
        $acceptedErrorPayload = json_decode($acceptedError['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(
            $acceptedError['status'] === 200
            && ($acceptedErrorPayload['validation'] ?? null) === $validationError
            && !array_key_exists('prompt_text', $acceptedErrorPayload),
            'profile_confirm must safely project the authoritative transcript validation error'
        );
        foreach ($cases as $childPayload) {
            $response = hub_cluster_dispatch($db, 'voice_generate', $request, [
                'refresh_due' => static fn (): array => [$inventory],
                'transport' => static function () use (&$calls, $childPayload): array {
                    $calls++;
                    return hub_gateway_json(200, $childPayload);
                },
            ]);
            hub_test_assert(
                $response['status'] === 502
                && str_contains($response['body'], 'router_response_invalid')
                && !str_contains($response['body'], '"voice_profile_task_id"')
                && !str_contains($response['body'], 'nested-confirmation-secret')
                && !str_contains($response['body'], 'nested-private-prompt')
                && !str_contains($response['body'], 'voice_profile_id'),
                'profile confirmation proof mismatch must fail closed without leaking child identity or prompt material'
            );
        }
        hub_test_assert($calls === count($cases) + 2, 'valid and invalid confirmation proofs must each dispatch exactly once to the pinned station');

        $tooLong = str_repeat('界', 20001);
        $tooLongRequest = hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode([
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => $fixture['route_id'],
                'prompt_text' => $tooLong,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
        $tooLongResponse = hub_cluster_dispatch($db, 'voice_generate', $tooLongRequest, [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static function (array $request) use ($tooLong): array {
                $downstream = json_decode((string)($request['body'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                hub_test_assert(($downstream['prompt_text'] ?? null) === $tooLong, 'Router must preserve the child-bound 20,001-character rejection input exactly');
                return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
            },
        ]);
        hub_test_assert(
            $tooLongResponse['status'] === 400
            && str_contains($tooLongResponse['body'], 'voice_profile_transcript_invalid'),
            'Router must safely relay the child boundary rejection for 20,001 Unicode characters'
        );
    });
});

hub_test('cluster router relays only the bounded native profile transcription error', function (): void {
    $expectedKeys = [
        'ok', 'task_status', 'profile_status', 'transcription_status', 'transcription_error',
        'transcript_confirmed', 'prompt_text_confirmed_at', 'profile_name', 'language',
        'consent_type', 'reference_audio_sha256', 'created_at', 'updated_at',
    ];
    foreach ([
        ['active', 'ready', null],
        ['active', 'failed', 'asr_failed'],
        ['active', 'failed', 'asr_unavailable'],
        ['deleted', 'failed', null],
        ['expired', 'failed', null],
    ] as [$profileStatus, $transcriptionStatus, $error]) {
        $payload = hub_test_cluster_voice_profile_status_payload([
            'profile_status' => $profileStatus,
            'transcription_status' => $transcriptionStatus,
            'transcription_error' => $error,
            'reference_audio_sha256' => $profileStatus === 'active' ? str_repeat('a', 64) : '',
        ]);
        $safe = hub_cluster_router_public_voice_profile_response($payload, true);
        hub_test_assert(
            array_keys($safe) === $expectedKeys && $safe['transcription_error'] === $error,
            'profile status must preserve the exact bounded transcription error contract'
        );
    }

    $missing = hub_test_cluster_voice_profile_status_payload();
    unset($missing['transcription_error']);
    foreach ([
        $missing,
        hub_test_cluster_voice_profile_status_payload(['transcription_error' => 'ASR failed at /private/profile.wav']),
        hub_test_cluster_voice_profile_status_payload(['transcription_error' => 'asr_failed']),
        hub_test_cluster_voice_profile_status_payload([
            'transcription_status' => 'failed',
            'transcription_error' => null,
        ]),
        hub_test_cluster_voice_profile_status_payload(['reference_audio_sha256' => '']),
    ] as $invalid) {
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_cluster_router_public_voice_profile_response($invalid, true)),
            'profile status must reject missing, raw, or state-inconsistent transcription errors'
        );
    }

    $draft = hub_test_cluster_voice_profile_status_payload([
        'transcript_confirmed' => false,
        'prompt_text_confirmed_at' => null,
        'prompt_text' => 'draft',
        'validation' => [
            'cer' => 0.0,
            'status' => 'clean',
            'needs_confirmation' => false,
            'normalizer' => 'opencc-s2twp-v1',
        ],
        'transcript' => ['raw' => 'draft', 'normalized' => 'draft'],
        'expected_text' => ['raw' => 'expected', 'normalized' => 'expected'],
    ]);
    $safeDraft = hub_cluster_router_public_voice_profile_response($draft, true);
    hub_test_assert(
        ($safeDraft['transcript'] ?? null) === ['raw' => 'draft', 'normalized' => 'draft']
        && ($safeDraft['expected_text'] ?? null) === ['raw' => 'expected', 'normalized' => 'expected'],
        'profile draft nested objects must project only their exact public fields'
    );
    $transcriptExtra = $draft;
    $transcriptExtra['transcript']['token'] = 'nested-transcript-secret';
    $expectedExtra = $draft;
    $expectedExtra['expected_text']['prompt_text'] = 'nested-expected-secret';
    foreach ([$transcriptExtra, $expectedExtra] as $invalid) {
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_cluster_router_public_voice_profile_response($invalid, true)),
            'profile draft nested objects must reject every undeclared field'
        );
    }

    $validationError = [
        'cer' => null,
        'status' => 'error',
        'needs_confirmation' => true,
        'normalizer' => 's2twp-strip-punctuation-v1',
        'error' => 'transcript_validation_failed',
    ];
    $validationErrorPayload = hub_test_cluster_voice_profile_status_payload([
        'transcription_status' => 'failed',
        'transcription_error' => 'transcript_validation_failed',
        'transcript_confirmed' => false,
        'prompt_text_confirmed_at' => null,
        'validation' => $validationError,
    ]);
    $safeValidationError = hub_cluster_router_public_voice_profile_response($validationErrorPayload, true);
    hub_test_assert(
        ($safeValidationError['validation'] ?? null) === $validationError,
        'profile_status must safely project the authoritative transcript validation error'
    );

    $errorMissingCode = $validationErrorPayload;
    unset($errorMissingCode['validation']['error']);
    $nonErrorWithCode = $validationErrorPayload;
    $nonErrorWithCode['validation']['status'] = 'clean';
    $unknownError = $validationErrorPayload;
    $unknownError['validation']['error'] = 'private_backend_failure';
    $errorExtra = $validationErrorPayload;
    $errorExtra['validation']['token'] = 'validation-private-token';
    foreach ([$errorMissingCode, $nonErrorWithCode, $unknownError, $errorExtra] as $invalid) {
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_cluster_router_public_voice_profile_response($invalid, true)),
            'validation error projection must reject missing, misplaced, unknown, or extra error fields'
        );
    }
});

hub_test('cluster router projects an unpruned expired Profile response as a safe tombstone', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'expired_profile_origin',
            'station_token' => 'expired_profile_origin_token',
        ]);
        $path = hub_voice_profile_storage_dir() . '/cluster_expired_profile_status.wav';
        file_put_contents($path, 'RIFFcluster-expired-status-secret');
        $profileId = hub_create_voice_profile($db, (int)$fixture['member_id'], [
            'name' => 'Cluster private expired profile',
            'reference_audio_path' => $path,
            'prompt_text' => 'cluster private expired draft',
            'language' => 'cluster-private-language',
            'consent_type' => 'explicit_permission',
            'expires_at' => '2000-01-01 00:00:00',
        ]);
        $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.10', [
            'owner_member_id' => (int)$fixture['member_id'],
            'owner_token_id' => (int)$fixture['customer']['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        $db->prepare('UPDATE voice_profiles
                      SET source_task_id = :task_id, transcription_status = :status, transcription_error = :error
                      WHERE id = :id')
            ->execute([
                ':task_id' => $taskId,
                ':status' => 'failed',
                ':error' => 'cluster private transcription detail',
                ':id' => $profileId,
            ]);
        $db->prepare('UPDATE cluster_routes SET remote_task_id = :task_id WHERE route_id = :route_id')
            ->execute([':task_id' => (string)$taskId, ':route_id' => $fixture['route_id']]);
        $inventory = hub_test_cluster_station_fixture([
            'id' => (int)$fixture['station']['id'],
            'station_key' => 'expired_profile_origin',
            'modes' => ['voice_generate'],
        ]);
        $request = hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
        ]);
        $calls = 0;
        $dispatch = static function () use ($db, $request, $inventory, $profileId, $taskId, &$calls): array {
            return hub_cluster_dispatch($db, 'voice_generate', $request, [
                'refresh_due' => static fn (): array => [$inventory],
                'transport' => static function (array $request) use ($db, $profileId, $taskId, &$calls): array {
                    $calls++;
                    hub_test_assert(
                        str_contains((string)($request['body'] ?? ''), 'voice_profile_task_id=' . $taskId),
                        'Cluster must address the persisted child profile task'
                    );
                    $profile = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $profileId)->fetch();
                    $task = hub_get_task($db, $taskId);
                    $confirmed = trim((string)($profile['prompt_text_confirmed_at'] ?? '')) !== '';
                    $payload = [
                        'ok' => true,
                        'task_status' => (string)($task['status'] ?? ''),
                        'profile_status' => 'expired',
                        'transcription_status' => (string)$profile['transcription_status'],
                        'transcription_error' => hub_voice_profile_transcription_error_code($profile['transcription_error'] ?? null),
                        'transcript_confirmed' => $confirmed,
                        'prompt_text_confirmed_at' => $confirmed ? (string)$profile['prompt_text_confirmed_at'] : null,
                        'profile_name' => (string)$profile['name'],
                        'language' => (string)$profile['language'],
                        'consent_type' => (string)$profile['consent_type'],
                        'reference_audio_sha256' => (string)$profile['reference_audio_sha256'],
                        'created_at' => (string)$profile['created_at'],
                        'updated_at' => (string)$profile['updated_at'],
                    ];
                    if (!$confirmed) {
                        $payload['prompt_text'] = (string)$profile['prompt_text'];
                    }
                    return hub_gateway_json(200, $payload);
                },
            ]);
        };
        $referenceSha256 = hash_file('sha256', $path);
        $assertTombstone = static function (array $response) use ($referenceSha256): void {
            $payload = json_decode((string)$response['body'], true, 64, JSON_THROW_ON_ERROR);
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            hub_test_assert(
                $response['status'] === 200
                && ($payload['profile_status'] ?? '') === 'expired'
                && ($payload['transcription_status'] ?? '') === 'failed'
                && array_key_exists('transcription_error', $payload)
                && $payload['transcription_error'] === null
                && ($payload['transcript_confirmed'] ?? null) === false
                && array_key_exists('prompt_text_confirmed_at', $payload)
                && $payload['prompt_text_confirmed_at'] === null
                && ($payload['profile_name'] ?? '') === 'Expired voice profile'
                && array_key_exists('language', $payload)
                && $payload['language'] === null
                && ($payload['reference_audio_sha256'] ?? null) === ''
                && !array_key_exists('prompt_text', $payload)
                && !str_contains($json, 'Cluster private expired profile')
                && !str_contains($json, 'cluster private expired draft')
                && !str_contains($json, 'cluster-private-language')
                && !str_contains($json, $referenceSha256)
                && !str_contains($json, 'asr_failed'),
                'Cluster expired profile_status must return only the safe tombstone projection'
            );
        };

        $assertTombstone($dispatch());
        $db->prepare('UPDATE voice_profiles SET prompt_text_confirmed_at = :confirmed_at WHERE id = :id')
            ->execute([':confirmed_at' => '1999-12-31 23:59:59', ':id' => $profileId]);
        $assertTombstone($dispatch());
        hub_test_assert($calls === 2, 'Cluster must query only the pinned expired Profile origin');

        $stored = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        hub_test_assert(
            is_array($stored)
            && empty($stored['deleted_at'])
            && ($stored['prompt_text'] ?? '') === 'cluster private expired draft',
            'Cluster status projection must not prune or mutate the expired Profile row'
        );
    });
});

hub_test('cluster router fails closed on malformed successful voice profile responses', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_malformed',
            'station_token' => 'profile_malformed_token',
        ], '135792468');
        $inventory = [hub_test_cluster_station_fixture([
            'id' => (int)$fixture['station']['id'],
            'station_key' => 'profile_malformed',
            'modes' => ['voice_generate'],
        ])];
        $privateValues = ['remote_task_42', '135792468', '24682468', '/private/profile.wav', 'private transcript'];
        $cases = [
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'headers' => ['Content-Type' => 'multipart/form-data; boundary=malformed-prepare'],
                    'raw_body' => '',
                    'post' => ['operation' => 'profile_prepare', 'profile_name' => 'Malformed child'],
                    'files' => [],
                ]),
                [
                    'ok' => true,
                    'task_id' => 'remote_task_42',
                    'voice_profile_id' => 91,
                    'reference_audio_path' => '/private/profile.wav',
                ],
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'headers' => ['Content-Type' => 'application/json'],
                    'raw_body' => '{"operation":"synthesize","mode":"clone","voice_profile_task_id":"' . $fixture['route_id'] . '","text":"hello"}',
                ]),
                [
                    'ok' => true,
                    'voice_profile_task_id' => '135792468',
                    'voice_profile_id' => 91,
                    'reference_audio_path' => '/private/profile.wav',
                    'prompt_text' => 'private transcript',
                ],
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
                ]),
                hub_test_cluster_voice_profile_status_payload([
                    'prompt_text' => 'private transcript',
                    'voice_profile_id' => 91,
                    'reference_audio_path' => '/private/profile.wav',
                ]),
            ],
            [
                hub_test_cluster_router_request((string)$fixture['customer']['plain_token'], [
                    'headers' => ['Content-Type' => 'application/json'],
                    'raw_body' => '{"operation":"synthesize","mode":"ultimate_clone","voice_profile_task_id":"' . $fixture['route_id'] . '","text":"hello"}',
                ]),
                [
                    'ok' => true,
                    'task_id' => '24682468',
                    'status' => 'queued',
                    'voice_profile_id' => 91,
                    'reference_audio_path' => '/private/profile.wav',
                    'prompt_text' => 'private transcript',
                ],
            ],
        ];
        $calls = 0;

        foreach ($cases as [$request, $childPayload]) {
            $response = hub_cluster_dispatch($db, 'voice_generate', $request, [
                'refresh_due' => static fn (): array => $inventory,
                'transport' => static function () use (&$calls, $childPayload): array {
                    $calls++;
                    return hub_gateway_json(200, $childPayload);
                },
            ]);
            hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_response_invalid'), 'malformed successful profile responses must fail closed');
            foreach ($privateValues as $private) {
                hub_test_assert(!str_contains($response['body'], $private), 'malformed profile response leaked private child value: ' . $private);
            }
        }
        hub_test_assert($calls === 4, 'each malformed response case must dispatch only to its selected or pinned station');
        $accounting = $db->query("SELECT request_id, error_code FROM cluster_route_accesses WHERE mode = 'voice_generate'")->fetchAll();
        $accountingJson = json_encode($accounting, JSON_THROW_ON_ERROR);
        foreach ($privateValues as $private) {
            hub_test_assert(!str_contains($accountingJson, $private), 'cluster access accounting must not record malformed child response detail: ' . $private);
        }
    });
});

hub_test('cluster voice profile handles survive same-member token rotation', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_rotation',
            'station_token' => 'profile_rotation_station_token',
            'internal_base_url' => 'https://profile-rotation.internal/aihub',
        ]);
        $replacement = hub_create_api_token($db, (int)$fixture['member_id'], 'replacement profile token', null, null);
        hub_add_api_token_mode_permission($db, (int)$replacement['token_id'], 'voice_generate');
        $unpermitted = hub_create_api_token($db, (int)$fixture['member_id'], 'unpermitted profile token', null, null);
        $foreign = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $db->prepare("UPDATE cluster_routes SET state = 'succeeded' WHERE route_id = :route_id")
            ->execute([':route_id' => $fixture['route_id']]);
        hub_test_assert(
            (string)$db->query("SELECT route_role FROM cluster_routes WHERE route_id = " . $db->quote((string)$fixture['route_id']))->fetchColumn() === 'profile_prepare',
            'profile_prepare admission must persist its narrow route role'
        );
        hub_revoke_api_token($db, (int)$fixture['customer']['token_id']);
        $inventory = hub_test_cluster_station_fixture([
            'id' => (int)$fixture['station']['id'],
            'station_key' => 'profile_rotation',
            'modes' => ['voice_generate'],
        ]);
        $requests = [];
        $cases = [
            [
                'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
                hub_test_cluster_voice_profile_status_payload(),
            ],
            [
                'operation=profile_confirm&voice_profile_task_id=' . $fixture['route_id'] . '&prompt_text=confirmed',
                hub_test_cluster_voice_profile_confirmation_payload('42', 'confirmed'),
            ],
            [
                'operation=synthesize&mode=clone&voice_profile_task_id=' . $fixture['route_id'] . '&text=clone',
                ['ok' => true, 'task_id' => '43'],
            ],
            [
                'mode=ultimate_clone&voice_profile_task_id=' . $fixture['route_id'] . '&text=ultimate',
                ['ok' => true, 'task_id' => '44'],
            ],
            [
                'operation=profile_delete&voice_profile_task_id=' . $fixture['route_id'],
                hub_test_cluster_voice_profile_status_payload([
                    'profile_status' => 'deleted',
                    'transcription_status' => 'failed',
                    'transcription_error' => null,
                    'reference_audio_sha256' => '',
                ]),
            ],
        ];

        foreach ($cases as [$body, $childPayload]) {
            $response = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request(
                (string)$replacement['plain_token'],
                [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'raw_body' => $body,
                    'request_uri' => '/cluster_api.php?mode=voice_generate',
                ]
            ), [
                'refresh_due' => static fn (): array => [$inventory],
                'transport' => static function (array $request) use (&$requests, $childPayload): array {
                    $requests[] = $request;
                    hub_test_assert(
                        ($request['headers']['Authorization'] ?? '') === 'Bearer profile_rotation_station_token'
                        && str_contains((string)$request['body'], 'voice_profile_task_id=42'),
                        'rotated profile requests must stay pinned and use the child profile task'
                    );

                    return hub_gateway_json(200, $childPayload);
                },
            ]);
            hub_test_assert($response['status'] === 200, 'a current permitted Token for the Profile member must resolve the handle');
        }
        hub_test_assert(count($requests) === count($cases), 'every permitted rotated-token Profile operation must dispatch once');

        $denied = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request(
            (string)$unpermitted['plain_token'],
            [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
            ]
        ), ['refresh_due' => static fn (): array => [$inventory]]);
        hub_test_assert(
            $denied['status'] === 403 && str_contains($denied['body'], 'token_mode_not_allowed'),
            'replacement Profile Tokens must retain voice_generate permission'
        );

        $foreignCalls = 0;
        $foreignDenied = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request(
            (string)$foreign['plain_token'],
            [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
            ]
        ), [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static function () use (&$foreignCalls): array {
                $foreignCalls++;
                return hub_gateway_json(200, []);
            },
        ]);
        hub_test_assert(
            $foreignDenied['status'] === 404
            && str_contains($foreignDenied['body'], 'profile_task_not_found')
            && $foreignCalls === 0,
            'foreign members must receive the same not-found response before dispatch'
        );
    });
});

hub_test('cluster member fallback accepts only succeeded profile prepare routes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_role_gate',
            'station_token' => 'profile_role_gate_token',
        ], '51');
        $replacement = hub_create_api_token($db, (int)$fixture['member_id'], 'replacement profile role token', null, null);
        hub_add_api_token_mode_permission($db, (int)$replacement['token_id'], 'voice_generate');
        $replacementAuth = [
            'member_id' => (int)$fixture['member_id'],
            'token_id' => (int)$replacement['token_id'],
        ];
        $originalAuth = [
            'member_id' => (int)$fixture['member_id'],
            'token_id' => (int)$fixture['customer']['token_id'],
        ];
        $makeRoute = static function (string $role, string $state, string $remoteTaskId) use ($db, $fixture, $originalAuth): string {
            $routeId = hub_cluster_router_admit_route(
                $db,
                $fixture['station'],
                $originalAuth,
                'voice_generate',
                true,
                true,
                $role
            );
            hub_test_assert(is_string($routeId), 'profile role fixture admission failed');
            hub_cluster_rewrite_async_response($db, [
                'route_id' => $routeId,
                'station_id' => (int)$fixture['station']['id'],
            ], ['ok' => true, 'task_id' => $remoteTaskId], 'cluster_api.php');
            $db->prepare('UPDATE cluster_routes SET state = :state WHERE route_id = :route_id')
                ->execute([':state' => $state, ':route_id' => $routeId]);

            return $routeId;
        };

        $db->prepare("UPDATE cluster_routes SET state = 'succeeded' WHERE route_id = :route_id")
            ->execute([':route_id' => $fixture['route_id']]);
        $pending = $makeRoute('profile_prepare', 'active', '52');
        $failed = $makeRoute('profile_prepare', 'failed', '53');
        $cancelled = $makeRoute('profile_prepare', 'cancelled', '54');
        $derived = $makeRoute('task', 'succeeded', '55');

        hub_test_assert(
            hub_cluster_get_voice_profile_route_for_member($db, (string)$fixture['route_id'], $replacementAuth) !== null,
            'rotated Token must resolve a succeeded profile_prepare route'
        );
        foreach ([$pending, $failed, $cancelled, $derived] as $routeId) {
            hub_test_assert(
                hub_cluster_get_voice_profile_route_for_member($db, $routeId, $replacementAuth) === null,
                'rotated Token must not resolve pending, terminal-failed, or derived synthesis routes'
            );
        }
        hub_test_assert(
            hub_cluster_get_route_for_customer($db, $pending, $originalAuth) !== null,
            'the submitting Token may still resolve its pending profile_prepare route exactly'
        );
    });
});

hub_test('cluster router keeps self-station profile routes local and substitutes the child ID in scoped globals', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'voice_generate');
            hub_cluster_node_configure($db, true, ['voice_generate']);
            $self = hub_cluster_register_self_station($db);
            $other = hub_test_cluster_router_station($db, [
                'station_key' => 'profile_remote_better',
                'station_token' => 'profile_remote_better_token',
                'modes' => ['voice_generate'],
            ]);
            $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
            $selfInventory = hub_test_cluster_station_fixture(['id' => (int)$self['id'], 'station_key' => (string)$self['station_key'], 'modes' => ['voice_generate']]);
            $otherInventory = hub_test_cluster_station_fixture(['id' => (int)$other['id'], 'station_key' => (string)$other['station_key'], 'priority' => 99, 'gpu_free_vram_mb' => 65536, 'modes' => ['voice_generate']]);
            $direct = 0;
            $http = 0;
            $oldGet = $_GET;

            $prepared = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'headers' => ['Content-Type' => 'multipart/form-data; boundary=self-profile'],
                'raw_body' => '',
                'post' => ['operation' => 'profile_prepare', 'profile_name' => 'Self profile', 'consent_type' => 'self_recorded'],
                'files' => [],
            ]), [
                'refresh_due' => static fn (): array => [$selfInventory],
                'direct_dispatcher' => static function () use (&$direct): array {
                    $direct++;
                    return hub_gateway_json(200, ['ok' => true, 'task_id' => '314159265358979323']);
                },
            ]);
            $profileRoute = (string)(json_decode($prepared['body'], true, 64, JSON_THROW_ON_ERROR)['task_id'] ?? '');
            hub_test_assert(hub_cluster_router_valid_route_id($profileRoute), 'self profile_prepare must return an opaque route');

            $status = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'headers' => ['Content-Type' => 'application/json'],
                'raw_body' => '{"operation":"profile_status","voice_profile_task_id":"' . $profileRoute . '"}',
            ]), [
                'refresh_due' => static fn (): array => [$otherInventory, array_replace($selfInventory, ['priority' => 1, 'gpu_free_vram_mb' => 1])],
                'direct_dispatcher' => static function (PDO $db, string $mode, array $request) use (&$direct): array {
                    $direct++;
                    hub_test_assert($_POST === ['operation' => 'profile_status', 'voice_profile_task_id' => '314159265358979323'], 'self JSON profile request must expose only the numeric child task ID to the local gateway');
                    hub_test_assert($_FILES === [] && !array_key_exists('raw_body', $request), 'self profile substitution must stay request-scoped');
                    return hub_gateway_json(200, hub_test_cluster_voice_profile_status_payload());
                },
                'transport' => static function () use (&$http): array {
                    $http++;
                    return hub_gateway_error(500, 'unexpected_http', 'unexpected HTTP');
                },
            ]);

            $queryStatus = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request((string)$customer['plain_token'], [
                'method' => 'GET',
                'headers' => [],
                'raw_body' => '',
                'query' => ['operation' => 'profile_status', 'voice_profile_task_id' => $profileRoute],
                'request_uri' => '/cluster_api.php?mode=voice_generate&operation=profile_status&voice_profile_task_id=' . $profileRoute,
            ]), [
                'refresh_due' => static fn (): array => [$otherInventory, array_replace($selfInventory, ['priority' => 1, 'gpu_free_vram_mb' => 1])],
                'direct_dispatcher' => static function () use (&$direct): array {
                    $direct++;
                    hub_test_assert($_GET === [
                        'operation' => 'profile_status',
                        'voice_profile_task_id' => '314159265358979323',
                        'mode' => 'voice_generate',
                    ], 'self GET profile request must expose only the numeric child task ID to the local gateway');
                    return hub_gateway_json(200, hub_test_cluster_voice_profile_status_payload());
                },
                'transport' => static function () use (&$http): array {
                    $http++;
                    return hub_gateway_error(500, 'unexpected_http', 'unexpected HTTP');
                },
            ]);

            hub_test_assert($status['status'] === 200 && $queryStatus['status'] === 200 && $direct === 3 && $http === 0, 'pinned self profile requests must stay in-process even when another station is preferred');
            hub_test_assert($_GET === $oldGet && $_POST === [] && $_FILES === [], 'self profile dispatch must restore request globals');
        });
    });
});

hub_test('cluster router maps pinned self-station dispatcher exceptions to station unavailable without failover', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            hub_test_cluster_publish_mode($db, 'voice_generate');
            hub_cluster_node_configure($db, true, ['voice_generate']);
            $self = hub_cluster_register_self_station($db);
            $other = hub_test_cluster_router_station($db, [
                'station_key' => 'profile_self_fallback',
                'station_token' => 'profile_self_fallback_token',
                'modes' => ['voice_generate'],
            ]);
            $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
            $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
            $routeId = hub_cluster_router_admit_route($db, $self, [
                'member_id' => $memberId,
                'token_id' => (int)$customer['token_id'],
            ], 'voice_generate', false, true, 'profile_prepare');
            if (!is_string($routeId)) {
                throw new RuntimeException('self profile route admission failed');
            }
            hub_cluster_rewrite_async_response($db, [
                'route_id' => $routeId,
                'station_id' => (int)$self['id'],
            ], ['ok' => true, 'task_id' => '42'], 'cluster_api.php');
            $direct = 0;
            $remote = 0;

            $response = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request(
                (string)$customer['plain_token'],
                [
                    'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                    'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $routeId,
                ]
            ), [
                'refresh_due' => static fn (): array => [
                    hub_test_cluster_station_fixture([
                        'id' => (int)$other['id'],
                        'station_key' => 'profile_self_fallback',
                        'priority' => 99,
                        'modes' => ['voice_generate'],
                    ]),
                    hub_test_cluster_station_fixture([
                        'id' => (int)$self['id'],
                        'station_key' => (string)$self['station_key'],
                        'modes' => ['voice_generate'],
                    ]),
                ],
                'direct_dispatcher' => static function () use (&$direct): array {
                    $direct++;
                    throw new RuntimeException('self station failed');
                },
                'transport' => static function () use (&$remote): array {
                    $remote++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);

            hub_test_assert(
                $response['status'] === 503
                && str_contains($response['body'], 'station_unavailable')
                && !str_contains($response['body'], 'router_proxy_failed')
                && $direct === 1
                && $remote === 0,
                'pinned self-station exceptions must return station_unavailable without trying another station'
            );
        });
    });
});

hub_test('cluster router rejects foreign or unavailable profile routes before pinned transport and never retries', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_pinned',
            'station_token' => 'profile_pinned_token',
            'internal_base_url' => 'https://profile-pinned.internal/aihub',
        ], '246813579');
        $otherStation = hub_test_cluster_router_station($db, [
            'station_key' => 'profile_other',
            'station_token' => 'profile_other_token',
            'modes' => ['voice_generate'],
        ]);
        $foreign = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $request = static fn (string $token): array => hub_test_cluster_router_request($token, [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
        ]);
        $refreshes = 0;
        $calls = 0;
        $foreignResponse = hub_cluster_dispatch($db, 'voice_generate', $request((string)$foreign['plain_token']), [
            'refresh_due' => static function () use (&$refreshes): array {
                $refreshes++;
                return [];
            },
            'transport' => static function () use (&$calls): array {
                $calls++;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ]);
        hub_test_assert($foreignResponse['status'] === 404
            && str_contains($foreignResponse['body'], 'profile_task_not_found')
            && $refreshes === 0
            && $calls === 0, 'foreign customer routes must fail with the profile-specific 404 before inventory or transport');

        $pinnedInventory = hub_test_cluster_station_fixture([
            'id' => (int)$fixture['station']['id'],
            'station_key' => 'profile_pinned',
            'modes' => ['voice_generate'],
        ]);
        foreach ([
            [],
            [array_replace($pinnedInventory, ['fresh' => false])],
            [array_replace($pinnedInventory, ['enabled' => false])],
        ] as $inventory) {
            $unavailable = hub_cluster_dispatch($db, 'voice_generate', $request((string)$fixture['customer']['plain_token']), [
                'refresh_due' => static fn (): array => $inventory,
                'transport' => static function () use (&$calls): array {
                    $calls++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);
            hub_test_assert($unavailable['status'] === 503 && str_contains($unavailable['body'], 'station_unavailable'), 'missing, stale, or disabled pinned inventory must return station_unavailable');
        }
        hub_test_assert($calls === 0, 'unavailable pinned stations must fail before transport');

        $calls = 0;
        $failed = hub_cluster_dispatch($db, 'voice_generate', $request((string)$fixture['customer']['plain_token']), [
            'refresh_due' => static fn (): array => [
                hub_test_cluster_station_fixture([
                    'id' => (int)$otherStation['id'],
                    'station_key' => 'profile_other',
                    'priority' => 99,
                    'gpu_free_vram_mb' => 65536,
                    'modes' => ['voice_generate'],
                ]),
                $pinnedInventory,
            ],
            'transport' => static function (array $request) use (&$calls): array {
                $calls++;
                hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer profile_pinned_token', 'pinned dispatch must address only the profile origin');
                throw new RuntimeException('pinned station failed');
            },
        ]);
        hub_test_assert(
            $failed['status'] === 503
            && str_contains($failed['body'], 'station_unavailable')
            && !str_contains($failed['body'], 'router_proxy_failed')
            && $calls === 1,
            'pinned profile transport failure must return station_unavailable without retry'
        );

        $db->prepare("UPDATE cluster_routes SET mode = 'vision' WHERE route_id = :route_id")->execute([':route_id' => $fixture['route_id']]);
        $wrongMode = hub_cluster_dispatch($db, 'voice_generate', $request((string)$fixture['customer']['plain_token']), [
            'refresh_due' => static function () use (&$refreshes): array {
                $refreshes++;
                return [];
            },
        ]);
        hub_test_assert($wrongMode['status'] === 404 && str_contains($wrongMode['body'], 'profile_task_not_found'), 'only voice_generate task routes may address a profile');

        $db->prepare("UPDATE cluster_routes SET mode = 'voice_generate' WHERE route_id = :route_id")->execute([':route_id' => $fixture['route_id']]);
        hub_add_api_token_mode_permission($db, (int)$fixture['customer']['token_id'], 'vision');
        $crossMode = hub_cluster_dispatch($db, 'vision', $request((string)$fixture['customer']['plain_token']), [
            'refresh_due' => static function () use (&$refreshes): array {
                $refreshes++;
                return [];
            },
        ]);
        hub_test_assert($crossMode['status'] === 404 && str_contains($crossMode['body'], 'profile_task_not_found'), 'voice profile task handles must not influence another Router mode');
    });
});

hub_test('cluster router maps pinned profile cURL failures to station unavailable without failover', function (): void {
    hub_test_with_cluster_secret(function (): void {
        if (!function_exists('curl_init')) {
            hub_test_skip('pinned profile transport test requires cURL');
        }

        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_curl_failure',
            'station_token' => 'profile_curl_failure_token',
            'internal_base_url' => 'http://127.0.0.1:1/aihub',
        ], '86420');
        $other = hub_test_cluster_router_station($db, [
            'station_key' => 'profile_curl_fallback',
            'station_token' => 'profile_curl_fallback_token',
            'modes' => ['voice_generate'],
        ]);
        $calls = 0;

        $response = hub_cluster_dispatch($db, 'voice_generate', hub_test_cluster_router_request(
            (string)$fixture['customer']['plain_token'],
            [
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'raw_body' => 'operation=profile_status&voice_profile_task_id=' . $fixture['route_id'],
            ]
        ), [
            'refresh_due' => static fn (): array => [
                hub_test_cluster_station_fixture([
                    'id' => (int)$other['id'],
                    'station_key' => 'profile_curl_fallback',
                    'priority' => 99,
                    'modes' => ['voice_generate'],
                ]),
                hub_test_cluster_station_fixture([
                    'id' => (int)$fixture['station']['id'],
                    'station_key' => 'profile_curl_failure',
                    'modes' => ['voice_generate'],
                ]),
            ],
            'transport' => static function (array $request) use (&$calls): array {
                $calls++;
                return hub_cluster_proxy_transport($request);
            },
        ]);

        hub_test_assert(
            $response['status'] === 503
            && str_contains($response['body'], 'station_unavailable')
            && !str_contains($response['body'], 'router_proxy_failed')
            && $calls === 1,
            'pinned Profile cURL failures must return station_unavailable without trying another station'
        );
    });
});

hub_test('cluster router exposes only the exact voice profile prepare task result', function (): void {
    $result = [
        'prompt_text_sha256' => str_repeat('a', 64),
        'text_chars' => 123,
        'transcript_confirmed' => false,
        'transcription_status' => 'ready',
        'kind' => 'voice_profile_prepare',
    ];
    $payload = ['cluster_artifact_index' => [], 'result' => $result];

    hub_test_assert(hub_cluster_router_public_task_result($payload) === $result, 'voice profile prepare result must expose only its bounded metadata regardless of key order');
    foreach ([
        $result + ['task_id' => 42],
        array_replace($result, ['kind' => 'pack_job']),
        array_replace($result, ['transcription_status' => 'private_state']),
        array_replace($result, ['transcript_confirmed' => 'false']),
        array_replace($result, ['text_chars' => 20001]),
        array_replace($result, ['prompt_text_sha256' => str_repeat('A', 64)]),
    ] as $invalidResult) {
        hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_router_public_task_result([
            'cluster_artifact_index' => [],
            'result' => $invalidResult,
        ])), 'arbitrary or unbounded child profile results must be rejected');
    }
});

hub_test('cluster router followups require the exact customer token before pinned dispatch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'followup_station_token']);
        $other = hub_test_cluster_router_customer_token($db, []);
        $sameMember = hub_create_api_token($db, (int)$fixture['member_id'], 'same member followup token', null, null);
        $requests = [];

        $denied = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$other['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return hub_gateway_json(200, ['ok' => true]);
        });

        hub_test_assert($denied['status'] === 404 && str_contains($denied['body'], 'route_not_found') && $requests === [], 'other customer tokens must fail before transport');

        foreach ([
            'cluster_task_status' => 'GET',
            'cluster_task_result' => 'GET',
            'cluster_task_log' => 'GET',
            'cluster_task_cancel' => 'POST',
            'cluster_artifact' => 'GET',
            'cluster_task_artifacts_ack' => 'POST',
        ] as $followupMode => $method) {
            $rotatedDenied = hub_cluster_dispatch_followup($db, $followupMode, [
                'method' => $method,
                'bearer_token' => (string)$sameMember['plain_token'],
                'client_ip' => '203.0.113.10',
                'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '1'],
            ], static function (array $request) use (&$requests): array {
                $requests[] = $request;
                return hub_gateway_json(200, ['ok' => true]);
            });
            hub_test_assert(
                $rotatedDenied['status'] === 404
                && str_contains($rotatedDenied['body'], 'route_not_found')
                && $requests === [],
                'ordinary task/result/log/cancel/artifact/ACK must remain bound to the submitting Token: ' . $followupMode
            );
        }

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'status' => 'success']);
        });

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert($response['status'] === 200 && $payload['task_id'] === $fixture['route_id'], 'followups must bypass normal pack permissions and hide the remote task ID');
        hub_test_assert(count($requests) === 1 && $requests[0]['url'] === 'https://station.internal:8080/aihub/cluster_followup.php' && $requests[0]['query'] === ['mode' => 'task_status', 'task_id' => 'remote_task_42'], 'followups must use the pinned child control-plane operation');
        hub_test_assert(($requests[0]['headers']['Authorization'] ?? '') === 'Bearer followup_station_token' && !str_contains(implode("\n", $requests[0]['headers']), (string)$fixture['customer']['plain_token']), 'followups must send only the selected station token');
        hub_test_assert((string)$db->query("SELECT state FROM cluster_routes WHERE route_id = '" . $fixture['route_id'] . "'")->fetchColumn() === 'succeeded', 'terminal status must update the pinned route');
        hub_test_assert((int)$db->query("SELECT COUNT(*) FROM cluster_route_accesses WHERE route_id = '" . $fixture['route_id'] . "'")->fetchColumn() === 1, 'each dispatched followup must record exactly one access');
    });
});

hub_test('cluster router result maps artifacts and proxies only mapped artifacts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'artifact_station_token']);
        $requests = 0;
        $result = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, [
                'ok' => true,
                'task_id' => 'remote_task_42',
                'result' => [
                    'artifacts' => [['id' => 999]],
                    'metadata' => ['artifact_id' => 'attacker-controlled'],
                ],
                'cluster_artifact_index' => [['id' => 10, 'size_bytes' => 7], ['id' => 11, 'size_bytes' => 3]],
            ]);
        });

        hub_test_assert($result['status'] === 200 && $requests === 1, 'result followup must dispatch once');
        $resultPayload = json_decode($result['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(!isset($resultPayload['result']['artifacts'][0]['type']) && !isset($resultPayload['result']['artifacts'][0]['mime_type']), 'public result artifacts must omit child-controlled strings');
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY remote_artifact_id")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mapped === ['10', '11'], 'only native result artifact entries may become downloadable');

        $artifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '10'],
        ], static function (array $request) use (&$requests): array {
            $requests++;
            hub_test_assert($request['query'] === ['mode' => 'artifact', 'task_id' => 'remote_task_42', 'artifact_id' => '10'], 'artifact proxy must use the mapped remote task and artifact IDs only');
            return [
                'status' => 200,
                'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\nX-3waAIHub-Device: cuda\r\nCache-Control: private, no-store\r\n",
                'body' => 'png-data',
            ];
        });

        $unknown = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => ['nested']],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, ['ok' => true]);
        });

        hub_test_assert($artifact['status'] === 200
            && $artifact['body'] === 'png-data'
            && ($artifact['headers'][0] ?? '') === 'Content-Type: image/png'
            && in_array('X-3waAIHub-Device: cuda', $artifact['headers'] ?? [], true)
            && in_array('Cache-Control: private, no-store', $artifact['headers'] ?? [], true),
            'ordinary known artifacts must preserve permitted proxy content types and allowlisted metadata');
        hub_test_assert($unknown['status'] === 404 && str_contains($unknown['body'], 'artifact_not_found') && $requests === 2, 'unknown or nested artifact IDs must reject before dispatch');
    });
});

hub_test('cluster router rebuilds profile-sensitive artifact headers without child metadata', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_artifact_station',
            'station_token' => 'profile_artifact_station_token',
        ], '75319');
        $result = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => '75319',
            'result' => [
                'kind' => 'voice_profile_prepare',
                'transcription_status' => 'ready',
                'transcript_confirmed' => false,
                'text_chars' => 24,
                'prompt_text_sha256' => str_repeat('a', 64),
            ],
            'cluster_artifact_index' => [
                ['id' => 17, 'size_bytes' => 7],
                ['id' => 18, 'size_bytes' => 7],
            ],
        ]));
        hub_test_assert($result['status'] === 200, 'profile result must authorize its child artifact through the opaque route');

        $artifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '17'],
        ], static function (array $request): array {
            hub_test_assert($request['query'] === [
                'mode' => 'artifact',
                'task_id' => '75319',
                'artifact_id' => '17',
            ], 'profile artifact proxy must use only the pinned numeric child task and mapped artifact IDs');
            return [
                'status' => 200,
                'raw_headers' => "HTTP/1.1 200 OK\r\n"
                    . "Content-Type: audio/wav\r\n"
                    . "Content-Length: 999\r\n"
                    . "Content-Disposition: attachment; filename=\"voice_profile_id_991337-private.wav\"\r\n"
                    . "X-3waAIHub-Model: voice_profile_id=991337 /srv/private/profiles/reference.wav\r\n"
                    . "X-3waAIHub-Device: cuda\r\n"
                    . "X-3waAIHub-Elapsed-Ms: 991337\r\n"
                    . "Cache-Control: private, no-store\r\n",
                'body' => 'wavdata',
            ];
        });
        $headers = $artifact['headers'] ?? [];
        $headerText = implode("\n", $headers);
        $maliciousMimeArtifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '18'],
        ], static fn (): array => [
            'status' => 200,
            'raw_headers' => "HTTP/1.1 200 OK\r\n"
                . "Content-Type: application/voice_profile_id_991337\r\n"
                . "Content-Disposition: inline\r\n",
            'body' => 'private',
        ]);
        $maliciousMimeHeaders = implode("\n", $maliciousMimeArtifact['headers'] ?? []);

        hub_test_assert($artifact['status'] === 200
            && $artifact['body'] === 'wavdata'
            && in_array('Content-Type: audio/wav', $headers, true)
            && in_array('Content-Length: 7', $headers, true)
            && in_array('Content-Disposition: attachment', $headers, true)
            && in_array('X-Content-Type-Options: nosniff', $headers, true),
            'profile-sensitive artifacts must preserve safe media, actual length, and disposition semantics');
        foreach ([
            'voice_profile_id',
            '/srv/private',
            'X-3waAIHub-Model',
            'X-3waAIHub-Device',
            'X-3waAIHub-Elapsed-Ms',
            'Cache-Control',
            'filename=',
        ] as $privateHeaderValue) {
            hub_test_assert(!str_contains($headerText, $privateHeaderValue),
                'profile-sensitive artifact leaked child-controlled header metadata: ' . $privateHeaderValue);
        }
        hub_test_assert($maliciousMimeArtifact['status'] === 200
            && $maliciousMimeArtifact['body'] === 'private'
            && str_contains($maliciousMimeHeaders, 'Content-Type: application/octet-stream')
            && str_contains($maliciousMimeHeaders, 'Content-Length: 7')
            && str_contains($maliciousMimeHeaders, 'Content-Disposition: inline')
            && !str_contains($maliciousMimeHeaders, 'voice_profile_id_991337'),
            'profile-sensitive artifacts must never reflect arbitrary syntactically valid child MIME types');
    });
});

hub_test('cluster router retains validated artifact metadata and proxies opaque acknowledgements', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'edge_tts_ack_station_token']);
        $db->prepare("UPDATE cluster_routes SET mode = 'edge_tts' WHERE route_id = :route_id")
            ->execute([':route_id' => $fixture['route_id']]);
        $result = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'cluster_artifact_index' => [[
                'id' => 10,
                'type' => 'generated_audio',
                'mime_type' => 'audio/mpeg',
                'size_bytes' => 7,
                'sha256' => str_repeat('a', 64),
            ]],
        ]));
        $payload = json_decode($result['body'], true, 64, JSON_THROW_ON_ERROR);
        $links = hub_cluster_router_task_links((string)$fixture['route_id'], 'cluster_api.php', 'edge_tts');
        hub_test_assert(($payload['result']['artifacts'] ?? null) === [[
            'id' => 10,
            'size_bytes' => 7,
            'type' => 'generated_audio',
            'mime_type' => 'audio/mpeg',
            'sha256' => str_repeat('a', 64),
        ]] && ($payload['ack_url_template'] ?? null) === $links['ack_url_template']
            && hub_cluster_router_is_followup_mode('cluster_task_artifacts_ack'),
            'the router must expose only validated artifact metadata plus its opaque ACK template');

        $requests = 0;
        $wrongMethod = hub_cluster_dispatch_followup($db, 'cluster_task_artifacts_ack', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'method' => 'GET',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '10'],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, ['ok' => true]);
        });
        hub_test_assert($wrongMethod['status'] === 405 && $requests === 0,
            'a Cluster artifact acknowledgement must reject GET before authentication or station proxying');

        $ack = hub_cluster_dispatch_followup($db, 'cluster_task_artifacts_ack', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'method' => 'POST',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '10'],
        ], static function (array $request) use (&$requests): array {
            $requests++;
            hub_test_assert($request['method'] === 'POST' && $request['query'] === [
                'mode' => 'task_artifacts_ack', 'task_id' => 'remote_task_42', 'artifact_id' => '10',
            ], 'the router must ACK only the mapped remote task artifact through the station token');
            return hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'artifact_id' => 10]);
        });
        $ackPayload = json_decode($ack['body'], true, 64, JSON_THROW_ON_ERROR);
        $unknown = hub_cluster_dispatch_followup($db, 'cluster_task_artifacts_ack', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'method' => 'POST',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '11'],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, ['ok' => true]);
        });
        hub_test_assert($ack['status'] === 200 && ($ackPayload['ok'] ?? false) === true
            && ($ackPayload['task_id'] ?? null) === $fixture['route_id']
            && $unknown['status'] === 404 && $requests === 1,
            'the router must hide remote ACK details and reject unmapped artifacts before dispatch');
    });
});

hub_test('VoxCPM2 child and Router artifact contract drives the acceptance CLI offline', function (): void {
    hub_test_with_cluster_secret(function (): void {
        if (!class_exists('CURLFile')) {
            hub_test_skip('offline VoxCPM2 Cluster acceptance fixture requires the PHP cURL extension');
        }
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'voxcpm2_acceptance_station',
            'station_token' => 'voxcpm2_acceptance_station_token',
        ], '42');
        $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '203.0.113.44', [
            'owner_member_id' => (int)$fixture['member_id'],
            'owner_token_id' => (int)$fixture['customer']['token_id'],
            'requested_mode' => 'voice_generate',
            'pack_id' => 'tts-voxcpm2',
            'pack_version' => '0.1.5',
            'job' => 'synthesize',
        ]);
        $resultDir = hub_task_result_dir($taskId) . '/published';
        if (!is_dir($resultDir) && !mkdir($resultDir, 0700, true) && !is_dir($resultDir)) {
            throw new RuntimeException('Cannot create offline Cluster artifact fixture.');
        }
        $audio = "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . "data" . pack('V', 0);
        $promptText = 'Confirmed private prompt text.';
        $targetText = 'Generate this private target text.';
        $referenceSha256 = hash('sha256', $audio);
        $promptSha256 = hash('sha256', $promptText);
        $profileNativeTaskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, [], null, '203.0.113.44', [
            'owner_member_id' => (int)$fixture['member_id'],
            'owner_token_id' => (int)$fixture['customer']['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        hub_finish_task_success($db, hub_get_task($db, $profileNativeTaskId) ?? [], [
            'kind' => 'voice_profile_prepare',
            'transcription_status' => 'ready',
            'transcript_confirmed' => true,
            'text_chars' => 30,
            'prompt_text_sha256' => $promptSha256,
        ]);
        $profileRouteId = hub_cluster_router_admit_route($db, $fixture['station'], [
            'member_id' => (int)$fixture['member_id'],
            'token_id' => (int)$fixture['customer']['token_id'],
        ], 'voice_generate', true, true);
        if (!is_string($profileRouteId)) {
            throw new RuntimeException('Cannot create offline Cluster profile route fixture.');
        }
        hub_cluster_rewrite_async_response($db, [
            'route_id' => $profileRouteId,
            'station_id' => (int)$fixture['station']['id'],
        ], ['ok' => true, 'task_id' => $profileNativeTaskId], 'cluster_api.php');
        $metadata = json_encode(
            hub_test_cluster_voxcpm2_runner_metadata($referenceSha256, $promptSha256, $targetText),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $audioPath = $resultDir . '/private_child_audio.wav';
        $metadataPath = $resultDir . '/private_child_metadata.json';
        file_put_contents($audioPath, $audio, LOCK_EX);
        file_put_contents($metadataPath, $metadata, LOCK_EX);
        $audioId = hub_register_validated_pack_job_artifact($db, $taskId, [
            'name' => 'private_child_audio.wav',
            'artifact_type' => 'generated_audio',
            'path' => $audioPath,
            'mime_type' => 'audio/wav',
            'size_bytes' => strlen($audio),
            'sha256' => hash('sha256', $audio),
        ]);
        $metadataId = hub_register_validated_pack_job_artifact($db, $taskId, [
            'name' => 'private_child_metadata.json',
            'artifact_type' => 'synthesis_metadata',
            'path' => $metadataPath,
            'mime_type' => 'application/json',
            'size_bytes' => strlen($metadata),
            'sha256' => hash('sha256', $metadata),
        ]);
        $publicMetadataArtifact = hub_get_task_artifact($db, $metadataId);
        hub_finish_task_success($db, hub_get_task($db, $taskId) ?? [], []);
        $db->prepare('UPDATE cluster_routes SET remote_task_id = :task_id WHERE route_id = :route_id')
            ->execute([':task_id' => (string)$taskId, ':route_id' => $fixture['route_id']]);
        $task = hub_get_task($db, $taskId);
        if ($task === null) {
            throw new RuntimeException('Offline VoxCPM2 child task fixture is unavailable.');
        }

        $reference = tempnam(sys_get_temp_dir(), 'voxcpm2-router-acceptance-');
        if ($reference === false) {
            throw new RuntimeException('Cannot create offline Cluster reference fixture.');
        }
        file_put_contents($reference, $audio, LOCK_EX);
        $childCalls = [];
        $profileResultChildCalls = 0;
        $requester = static function (array $request) use (
            $db,
            $taskId,
            $profileNativeTaskId,
            $fixture,
            &$childCalls,
            &$profileResultChildCalls,
        ): array {
            $query = (array)($request['query'] ?? []);
            $mode = (string)($query['mode'] ?? '');
            $artifactId = isset($query['artifact_id']) && ctype_digit((string)$query['artifact_id'])
                ? (int)$query['artifact_id']
                : null;
            $requestedTaskId = ctype_digit((string)($query['task_id'] ?? ''))
                ? (int)$query['task_id']
                : 0;
            hub_test_assert(
                in_array($requestedTaskId, [$taskId, $profileNativeTaskId], true),
                'Router must follow only the mapped native synthesis or profile task'
            );
            $childCalls[] = $mode;
            if ($mode === 'task_result' && $requestedTaskId === $profileNativeTaskId) {
                $profileResultChildCalls++;
            }
            hub_test_assert(
                ($request['headers']['Authorization'] ?? null) === 'Bearer voxcpm2_acceptance_station_token',
                'Router must use only its paired station credential for child follow-ups'
            );
            $response = hub_gateway_cluster_child_followup(
                $db,
                $mode,
                $requestedTaskId,
                (int)$fixture['member_id'],
                (int)$fixture['customer']['token_id'],
                $artifactId
            );
            if ($mode === 'artifact' && is_string($response['stream_path'] ?? null)) {
                $body = file_get_contents($response['stream_path']);
                if (!is_string($body)) {
                    throw new RuntimeException('Cannot read offline Cluster artifact.');
                }
                $response['body'] = $body;
                if (!empty($response['stream_artifact_id']) && is_string($response['stream_download_token'] ?? null)) {
                    hub_release_task_artifact_download(
                        $db,
                        (int)$response['stream_artifact_id'],
                        $response['stream_download_token']
                    );
                }
                unset(
                    $response['stream_path'],
                    $response['stream_size'],
                    $response['stream_artifact_id'],
                    $response['stream_download_token']
                );
            }

            return $response;
        };

        try {
            $childResult = hub_gateway_cluster_child_task_result($db, $task);
            $childPayload = json_decode($childResult['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert(
                ($childPayload['cluster_artifact_index'] ?? null) === [
                    [
                        'id' => $audioId,
                        'size_bytes' => strlen($audio),
                        'type' => 'generated_audio',
                        'mime_type' => 'audio/wav',
                        'sha256' => hash('sha256', $audio),
                    ],
                    [
                        'id' => $metadataId,
                        'size_bytes' => (int)($publicMetadataArtifact['size_bytes'] ?? -1),
                        'type' => 'synthesis_metadata',
                        'mime_type' => 'application/json',
                        'sha256' => (string)($publicMetadataArtifact['sha256'] ?? ''),
                    ],
                ],
                'canonical VoxCPM2 child results must expose only bounded authoritative artifact metadata'
            );

            $routerResult = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
                'bearer_token' => (string)$fixture['customer']['plain_token'],
                'client_ip' => '203.0.113.10',
                'method' => 'GET',
                'query' => ['task_id' => $fixture['route_id']],
            ], $requester);
            $routerPayload = json_decode($routerResult['body'], true, 64, JSON_THROW_ON_ERROR);
            $expectedLinks = hub_cluster_router_task_links(
                (string)$fixture['route_id'],
                'cluster_api.php',
                'voice_generate'
            );
            hub_test_assert(
                $routerResult['status'] === 200
                && ($routerPayload['result']['artifacts'] ?? null) === $childPayload['cluster_artifact_index']
                && ($routerPayload['ack_url_template'] ?? null) === ($expectedLinks['ack_url_template'] ?? null)
                && ($routerPayload['task_id'] ?? null) === $fixture['route_id']
                && !isset($routerPayload['cluster_artifact_index'])
                && !str_contains($routerResult['body'], $resultDir)
                && !str_contains($routerResult['body'], 'private_child_'),
                'Router must rewrite canonical VoxCPM2 artifacts and ACKs to the opaque route without child paths or metadata'
            );

            $profileDeletes = 0;
            $publicAcks = [];
            $json = static fn (array $payload, int $status = 200): array => [
                'status' => $status,
                'headers' => ['Content-Type: application/json; charset=utf-8'],
                'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ];
            $transport = static function (array $request) use (
                $db,
                $fixture,
                $profileRouteId,
                $requester,
                $json,
                $referenceSha256,
                $promptSha256,
                &$profileDeletes,
                &$publicAcks,
            ): array {
                $parts = parse_url((string)($request['url'] ?? ''));
                parse_str((string)($parts['query'] ?? ''), $query);
                $mode = (string)($query['mode'] ?? '');
                if ($mode === 'voice_generate') {
                    if (is_array($request['body'] ?? null)) {
                        return $json([
                            'ok' => true,
                            'task_id' => $profileRouteId,
                        ] + hub_cluster_router_task_links($profileRouteId, 'cluster_api.php', 'voice_generate'));
                    }
                    $body = json_decode((string)($request['body'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                    if (($body['operation'] ?? null) === 'profile_status') {
                        hub_test_assert(
                            ($body['voice_profile_task_id'] ?? null) === $profileRouteId,
                            'profile_status must retain the opaque Router profile task handle'
                        );
                        return $json([
                            'ok' => true,
                            'task_status' => 'success',
                            'profile_status' => 'active',
                            'transcription_status' => 'ready',
                            'transcript_confirmed' => true,
                            'prompt_text_confirmed_at' => '2026-07-31 12:00:00',
                            'profile_name' => 'VoxCPM2 Cluster Acceptance',
                            'language' => null,
                            'consent_type' => 'self_recorded',
                            'reference_audio_sha256' => $referenceSha256,
                            'created_at' => '2026-07-31 11:00:00',
                            'updated_at' => '2026-07-31 12:00:00',
                        ]);
                    }
                    if (($body['operation'] ?? null) === 'profile_delete') {
                        hub_test_assert(
                            ($body['voice_profile_task_id'] ?? null) === $profileRouteId,
                            'profile_delete must retain the opaque Router profile task handle'
                        );
                        $profileDeletes++;
                        return $json(['ok' => true, 'profile_status' => 'deleted']);
                    }
                    hub_test_assert(
                        !array_key_exists('operation', $body)
                        && ($body['mode'] ?? null) === 'ultimate_clone'
                        && ($body['voice_profile_task_id'] ?? null) === $profileRouteId,
                        'acceptance CLI must submit the omitted-operation ultimate clone contract'
                    );
                    return $json([
                        'ok' => true,
                        'task_id' => $fixture['route_id'],
                    ] + hub_cluster_router_task_links(
                        (string)$fixture['route_id'],
                        'cluster_api.php',
                        'voice_generate'
                    ));
                }
                $response = hub_cluster_dispatch_followup($db, $mode, [
                    'bearer_token' => (string)$fixture['customer']['plain_token'],
                    'client_ip' => '203.0.113.10',
                    'method' => (string)($request['method'] ?? 'GET'),
                    'query' => $query,
                ], $requester);
                if ($mode === 'cluster_task_artifacts_ack') {
                    $publicAcks[] = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
                }

                return $response;
            };
            $config = hub_voxcpm2_cluster_acceptance_config([
                'AIHUB_VOXCPM2_CLUSTER_BASE_URL' => 'https://router.example/3waAIHub',
                'AIHUB_VOXCPM2_CLUSTER_TOKEN' => (string)$fixture['customer']['plain_token'],
                'AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV' => $reference,
                'AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT' => $promptText,
                'AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT' => $targetText,
            ]);
            $accepted = hub_voxcpm2_cluster_acceptance_execute(
                $config,
                $transport,
                static fn (string $path): bool => is_file($path)
                    && substr((string)file_get_contents($path, false, null, 0, 12), 0, 4) === 'RIFF',
                static function (): void {
                    throw new RuntimeException('Terminal offline fixtures must not sleep.');
                }
            );
            $acknowledged = $db->query(
                'SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . $taskId . ' AND acknowledged_at IS NOT NULL'
            )->fetchColumn();
            hub_test_assert(
                $accepted['artifacts_acknowledged'] === true
                && $profileDeletes === 1
                && $acknowledged === 2
                && count($publicAcks) === 2
                && $profileResultChildCalls === 1
                && array_filter($publicAcks, static fn (array $ack): bool =>
                    ($ack['task_id'] ?? null) !== $fixture['route_id']
                    || isset($ack['artifact_id'])
                    || isset($ack['acknowledged_at'])
                ) === []
                && count(array_filter($childCalls, static fn (string $mode): bool => $mode === 'artifact')) === 2
                && count(array_filter($childCalls, static fn (string $mode): bool => $mode === 'task_artifacts_ack')) === 2,
                'actual Router shapes must complete CLI validation and hide every child ACK detail'
            );
        } finally {
            @unlink($reference);
            if (is_dir(hub_task_result_dir($taskId))) {
                hub_retention_remove_managed_path(hub_task_result_dir($taskId), HUB_DATA_DIR . '/results');
            }
        }
    });
});

hub_test('cluster router preserves and maps native oversized result artifacts only after task identity matches', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'summary_artifact_station_token']);
        $summary = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'stored_as_artifact' => true,
                'artifact_id' => 17,
                'path' => '/private/task/remote_task_42.json',
                'bytes' => 4096,
            ],
            'cluster_artifact_index' => [['id' => 17, 'size_bytes' => 4096]],
        ]));

        $summaryPayload = json_decode($summary['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert($summary['status'] === 200 && ($summaryPayload['result'] ?? null) === ['stored_as_artifact' => true, 'artifact_id' => 17, 'bytes' => 4096], 'native oversized result summaries must retain only safe artifact fields');
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "'")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mapped === ['17'], 'native oversized result artifact must be authorized for the exact route');

        $artifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '17'],
        ], static function (array $request): array {
            hub_test_assert($request['query'] === ['mode' => 'artifact', 'task_id' => 'remote_task_42', 'artifact_id' => '17'], 'oversized result artifact must use the pinned native task and artifact IDs');
            return ['status' => 200, 'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n", 'body' => '{}'];
        });
        hub_test_assert($artifact['status'] === 200, 'mapped oversized result artifact must proxy through the router');

        $mismatch = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'different_remote_task',
            'result' => ['stored_as_artifact' => true, 'artifact_id' => 99],
        ]));
        $mappedAfterMismatch = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY remote_artifact_id")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mismatch['status'] === 502 && str_contains($mismatch['body'], 'router_response_invalid') && $mappedAfterMismatch === ['17'], 'mismatched native task IDs must not expose or map artifacts');
    });
});

hub_test('cluster router maps only the authoritative child artifact index up to 128 entries', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'authoritative_index_station_token']);
        $index = [];
        for ($id = 1; $id <= 128; $id++) {
            $index[] = ['id' => $id, 'size_bytes' => $id];
        }
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'artifacts' => [['id' => 999]],
                'metadata' => ['artifact_id' => 998],
            ],
            'cluster_artifact_index' => $index,
        ]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY CAST(remote_artifact_id AS INTEGER)")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($response['status'] === 200 && count($mapped) === 128 && $mapped[0] === '1' && $mapped[127] === '128', 'all 128 authoritative task artifacts must map for the pinned route');
        hub_test_assert(($payload['result']['artifacts'][0]['id'] ?? null) === 1 && !isset($payload['cluster_artifact_index']) && !str_contains($response['body'], '999') && !str_contains($response['body'], '998'), 'client results must ignore arbitrary stored result artifact fields and hide the control-plane index');
    });
});

hub_test('cluster router projects bounded sanitized native task logs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'log_station_token']);
        $logs = [];
        for ($index = 0; $index < 105; $index++) {
            $logs[] = [
                'id' => $index + 1,
                'task_id' => 'remote_task_42',
                'level' => 'info',
                'message' => 'queued remote_task_42 at https://station.internal:8080/aihub/api.php?task_id=remote_task_42 ' . str_repeat('x', 1600),
                'created_at' => '2026-07-26 12:00:00',
                'unsafe' => 'discard me',
            ];
        }
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'logs' => $logs]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $firstLog = $payload['logs'][0] ?? [];
        hub_test_assert($response['status'] === 200 && count($payload['logs'] ?? []) === 100 && array_keys($firstLog) === ['level', 'message', 'created_at'], 'native logs must retain only capped safe fields');
        hub_test_assert(str_starts_with((string)($firstLog['message'] ?? ''), 'queued ') && strlen((string)($firstLog['message'] ?? '')) <= 1024, 'safe log messages must arrive with a bounded length');
        hub_test_assert(!str_contains($response['body'], 'remote_task_42') && !str_contains($response['body'], 'station.internal') && !str_contains($response['body'], 'discard me'), 'log projection must redact remote task IDs, station links, and unsafe fields');

        $invalid = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'logs' => 'not-a-native-log-list']));
        hub_test_assert($invalid['status'] === 502 && str_contains($invalid['body'], 'router_response_invalid'), 'invalid native log shapes must not masquerade as empty logs');
    });
});

hub_test('cluster router projects routed voice profile task logs to an empty safe view', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_voice_profile_route($db, [
            'station_key' => 'profile_log_station',
            'station_token' => 'profile_log_station_token',
        ], '75319');
        $privateValues = [
            'voice_profile_id=991337',
            '/srv/private/profiles/member-12/reference.wav',
            'Never reveal this owner transcript',
        ];
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => [
            'status' => 200,
            'headers' => [
                'Content-Type: text/html',
                'X-3waAIHub-Model: voice_profile_id=991337',
                'X-3waAIHub-Device: /srv/private/profiles/member-12/reference.wav',
            ],
            'body' => json_encode([
                'ok' => true,
                'task_id' => '75319',
                'logs' => [[
                    'level' => 'info',
                    'message' => implode(' ', $privateValues),
                    'created_at' => '2026-07-31 12:00:00',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $headers = implode("\n", $response['headers'] ?? []);

        hub_test_assert($response['status'] === 200 && ($payload['logs'] ?? null) === [], 'routed voice profile task logs must consistently project to an empty list');
        hub_test_assert(str_contains($headers, 'Content-Type: application/json; charset=utf-8')
            && !str_contains($headers, 'text/html'), 'routed voice profile task logs must use fresh JSON headers');
        foreach ($privateValues as $private) {
            hub_test_assert(!str_contains($response['body'], $private)
                && !str_contains($headers, $private), 'routed voice profile logs leaked private child data: ' . $private);
        }
    });
});

hub_test('cluster router redacts configured station origins including bare hosts and IPv6 authorities', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, [
            'public_base_url' => 'https://station.internal:8080/aihub',
            'internal_base_url' => 'https://[fd00:beef::1]:8080/aihub',
        ]);
        $message = 'bare station.internal dotted station.internal. default station.internal:443 full https://station.internal:8080/aihub/api.php ipv6 [fd00:beef::1]:8080 ipv6default [fd00:beef::1]:443 raw fd00:beef::1 full6 https://[fd00:beef::1]:8080/aihub/api.php';
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'logs' => [[
                'level' => 'info',
                'message' => $message,
                'created_at' => '2026-07-26 12:00:00',
            ]],
        ]));

        foreach (['https://station.internal:8080/aihub/api.php', 'station.internal', 'station.internal:443', 'station.internal.', '[fd00:beef::1]:8080', '[fd00:beef::1]:443', 'fd00:beef::1'] as $origin) {
            hub_test_assert(!str_contains($response['body'], $origin), 'public log projection must redact configured station authority form: ' . $origin);
        }
    });
});

hub_test('cluster station redaction terms include scheme defaults and sort longest first', function (): void {
    hub_test_with_cluster_http_internal(function (): void {
        $terms = hub_cluster_station_redaction_terms([
            'public_base_url' => 'https://station.internal/aihub',
            'internal_base_url' => 'http://192.168.1.25/aihub',
        ]);
        $lengths = array_map('strlen', $terms);
        $descending = $lengths;
        rsort($descending, SORT_NUMERIC);
        hub_test_assert(in_array('station.internal:443', $terms, true) && in_array('192.168.1.25:80', $terms, true) && $lengths === $descending, 'validated station bases must derive scheme-default authorities in longest-first order');
    });
});

hub_test('cluster router result projection discards configured station origins', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, [
            'public_base_url' => 'https://station.internal:8080/aihub',
            'internal_base_url' => 'https://[fd00:beef::1]:8080/aihub',
        ]);
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'message' => 'https://station.internal:8080/aihub station.internal:443 station.internal. [fd00:beef::1]:8080 [fd00:beef::1]:443 fd00:beef::1',
                'metadata' => ['origin' => 'station.internal'],
            ],
            'cluster_artifact_index' => [],
        ]));

        foreach (['station.internal', 'station.internal:443', 'station.internal.', '[fd00:beef::1]:8080', '[fd00:beef::1]:443', 'fd00:beef::1'] as $origin) {
            hub_test_assert($response['status'] === 200 && !str_contains($response['body'], $origin), 'public result projection must discard configured station authority form: ' . $origin);
        }
    });
});

hub_test('cluster child followup redacts native spool paths and bare station hosts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            hub_test_with_cluster_router_env('AIHUB_CLUSTER_CANONICAL_HOST', 'station.internal', function (): void {
                $db = hub_test_reset_db();
                hub_test_cluster_publish_mode($db, 'vision');
                $configured = hub_cluster_node_configure($db, true, ['vision']);
                $token = hub_cluster_node_reveal_token($db);
                $memberId = (int)hub_get_api_token($db, hub_cluster_node_token_id($db))['member_id'];
                for ($index = 0; $index < 42; $index++) {
                    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $memberId, 'owner_token_id' => hub_cluster_node_token_id($db)]);
                }
                hub_test_assert($taskId === 42, 'test task must exercise the native task_42 spool path');
                $_SERVER['HTTP_HOST'] = 'station.internal:8080';
                $_SERVER['SERVER_NAME'] = 'station.internal';
                $_SERVER['SERVER_PORT'] = '8080';
                hub_add_task_log($db, $taskId, 'info', 'station.internal station.internal. station.internal:8080 config.json task.log release.v1 [fd00:beef::1]:443 fd00:beef::1. ::ffff:192.168.1.25. [face] [cab] remote task 42 ' . str_repeat('x', 4097));
                hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

                $response = hub_cluster_child_followup_dispatch($db, [
                    'bearer_token' => $token,
                    'client_ip' => '203.0.113.44',
                    'method' => 'GET',
                    'query' => ['mode' => 'task_log', 'task_id' => '42'],
                ]);
                $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
                hub_test_assert($response['status'] === 200 && !empty($payload['logs']), 'paired child control plane must return projected native logs');
                $projectedLogs = implode("\n", array_map(
                    static fn (array $log): string => (string)($log['message'] ?? ''),
                    $payload['logs']
                ));
                hub_test_assert(!str_contains($projectedLogs, '42') && !str_contains($projectedLogs, 'task_42.log') && !str_contains($projectedLogs, 'station.internal') && !str_contains($projectedLogs, '[fd00:beef::1]:443') && !str_contains($projectedLogs, 'fd00:beef::1') && !str_contains($projectedLogs, '::ffff:192.168.1.25') && !str_contains($projectedLogs, '192.168.1.25') && str_contains($projectedLogs, '[redacted-ipv6].') && str_contains($projectedLogs, '[face]') && str_contains($projectedLogs, '[cab]') && str_contains($projectedLogs, 'config.json') && str_contains($projectedLogs, 'task.log') && str_contains($projectedLogs, 'release.v1'), 'child logs must redact known local authorities and IPv6 without changing filenames, versions, or ordinary bracket text');
            });
        });
    });
});

hub_test('cluster child log terms redact only known local authorities', function (): void {
    $terms = hub_cluster_child_local_authority_terms([
        'HTTPS' => 'on',
        'HTTP_HOST' => 'config.json',
        'SERVER_NAME' => 'config.json',
        'SERVER_ADDR' => '192.0.2.10',
        'SERVER_PORT' => '443',
    ], 'node.example');
    $message = hub_cluster_redact_log_references('node.example station.internal config.json task.log release.v1', $terms, true);

    hub_test_assert(!str_contains($message, 'node.example') && str_contains($message, 'station.internal') && str_contains($message, 'config.json') && str_contains($message, 'task.log') && str_contains($message, 'release.v1'), 'host-derived values must not enter child authority terms unless they match a trusted local identity');
});

hub_test('cluster router emits relative links and allowlists initial async fields', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = hub_cluster_router_admit_route($db, $station, ['member_id' => $memberId, 'token_id' => (int)$customer['token_id']], 'vision', true);
        hub_test_assert(is_string($routeId), 'async route admission must succeed');
        $previous = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['SCRIPT_NAME'] = '/cluster_api.php';
        try {
            $routerBase = hub_cluster_router_api_base_url();
            $payload = hub_cluster_rewrite_async_response($db, ['route_id' => $routeId, 'station_id' => (int)$station['id']], [
                'ok' => true,
                'task_id' => '1',
                'status' => 'queued',
                'cached' => true,
                'cache_age_seconds' => 12,
                'cache_hit_task_id' => '1',
                'message' => 'task 1 at https://station.internal:8080/aihub',
            ], $routerBase);
        } finally {
            $_SERVER = $previous;
        }

        hub_test_assert($routerBase === 'cluster_api.php', 'router links must not derive a public base from Host headers');
        hub_test_assert(!array_key_exists('cache_hit_task_id', $payload) && !array_key_exists('message', $payload), 'initial async responses must discard arbitrary child fields');
        hub_test_assert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'attacker.example') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'station.internal') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), '"1"'), 'initial async responses must not leak child locations or IDs');
    });
});

hub_test('cluster router keeps artifact 10 intact when the remote task ID is 1', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'one_station_token']);
        $db->prepare('UPDATE cluster_routes SET remote_task_id = :task_id WHERE route_id = :route_id')->execute([':task_id' => '1', ':route_id' => $fixture['route_id']]);

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => '1',
            'result' => ['artifacts' => [['id' => 999]]],
            'cluster_artifact_index' => [['id' => 10, 'size_bytes' => 1]],
        ]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(($payload['result']['artifacts'][0]['id'] ?? null) === 10 && !str_contains($response['body'], '"task_id":"1"'), 'opaque task rewriting must not mutate artifact ID 10');
    });
});

hub_test('cluster router sanitizes child error responses and recognizes timeouts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_token' => 'error_station_token']);
        $initial = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id']])],
            'transport' => static fn (): array => hub_gateway_json(500, ['error' => 'remote_task_secret https://station.internal:8080/aihub']),
        ]);
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'followup_error_station_token']);
        $followup = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(500, ['error' => 'remote_task_secret https://station.internal:8080/aihub']));
        $spoofed = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(500, ['error' => 'router_proxy_failed', 'detail' => 'https://station.internal:8080/aihub remote_task_secret']));

        hub_test_assert($initial['status'] === 502 && $followup['status'] === 502 && $spoofed['status'] === 502, 'child failures must become stable router failures');
        hub_test_assert(!str_contains($initial['body'], 'remote_task_secret') && !str_contains($followup['body'], 'station.internal') && !str_contains($spoofed['body'], 'station.internal'), 'child failure bodies must not leak remote details');
        hub_test_assert(hub_cluster_router_terminal_state('cluster_task_status', ['status' => 'timed_out']) === 'timed_out' && hub_cluster_router_terminal_state('cluster_task_status', ['status' => 'timeout']) === 'timed_out', 'native timeout states must be terminalized as timed out');
    });
});

hub_test('cluster router refuses self dispatch without a verified paired router IP', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        hub_add_api_token_ip_rule($db, hub_cluster_node_token_id($db), '198.51.100.44', 'cluster router');
        $station = hub_test_cluster_router_station($db, ['station_key' => 'unpaired_self', 'station_token' => hub_cluster_node_reveal_token($db)]);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'unpaired_self');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $direct = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token'], ['client_ip' => '203.0.113.10']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'unpaired_self'])],
            'direct_dispatcher' => static function () use (&$direct): array {
                $direct++;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ]);

        hub_test_assert($response['status'] === 503 && $direct === 0, 'self dispatch must fail closed without the full verified pairing identity');
    });
});

hub_test('cluster router exposes fresh remote modes for customer permissions', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_cluster_router_station($db, ['station_key' => 'remote_address']);
        hub_cluster_store_station_manifest($db, (int)$station['id'], [
            'modes' => ['taiwan_address'],
            'services' => [[
                'mode' => 'taiwan_address',
                'name' => 'Taiwan Address',
                'method' => 'POST',
                'content_type' => 'application/json',
                'endpoint' => 'api.php?mode=taiwan_address',
            ]],
        ]);
        hub_cluster_store_station_status($db, (int)$station['id'], [
            'snapshot_at' => hub_now(),
            'gpu' => ['available' => true, 'memory_free_mb' => 1024],
            'active_gpu_leases' => 0,
            'queued_jobs' => 0,
            'running_jobs' => 0,
            'modes' => ['taiwan_address'],
        ]);

        hub_test_assert(hub_cluster_router_available_modes($db) === ['taiwan_address'], 'fresh Router-only modes must be available for Token permissions');
        $page = (string)file_get_contents(HUB_ROOT . '/admin/api_token_permissions.php');
        hub_test_assert(str_contains($page, 'hub_cluster_router_available_modes($db)') && str_contains($page, 'Cluster Router Mode'), 'Token permissions page must render live Router modes');
    });
});

hub_test('cluster router guide documents the unified customer entry', function (): void {
    $guide = (string)file_get_contents(HUB_ROOT . '/docs/cluster-router.md');
    $clusterPage = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    $manifestEndpoint = (string)file_get_contents(HUB_ROOT . '/cluster_manifest.json.php');
    $docsEndpoint = (string)file_get_contents(HUB_ROOT . '/cluster_public_api_docs.php');

    foreach (['Cluster Router Mode', '登錄 / 更新本機服務', 'cluster_api.php', 'never cast it to an integer', 'Native `api.php` task IDs are numeric'] as $needle) {
        hub_test_assert(str_contains($guide, $needle), 'cluster guide must document ' . $needle);
    }
    foreach (['live read-only Git probes', 'cached CLI snapshot', 'complete valid set'] as $needle) {
        hub_test_assert(str_contains($guide, $needle), 'cluster guide status fallback must document ' . $needle);
    }
    hub_test_assert(str_contains($clusterPage, 'cluster_public_api_docs.php'), 'Cluster admin page must link to the Router API documentation');
    hub_test_assert(str_contains($layout, 'cluster_public_api_docs.php'), 'customer navigation must link to the Router API documentation');
    hub_test_assert(str_contains($manifestEndpoint, 'hub_cluster_refresh_due_stations($db)') && str_contains($docsEndpoint, 'hub_cluster_refresh_due_stations($db)'), 'public Router discovery must refresh due station inventory');
});

hub_test('cluster public docs explain an empty Router inventory', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');

    $manifest = hub_cluster_public_manifest($db);
    $docs = hub_cluster_public_api_docs_html($db);
    hub_test_assert(str_contains($docs, 'No Router modes are currently available.'), 'empty Router docs must explain that no mode is available');
    hub_test_assert(($manifest['production_audio_modes'] ?? null) === ['audio_cleanup', 'speech_transcribe', 'speech_transcribe_fast_zh', 'voice_generate']
        && str_contains($docs, 'Production audio modes')
        && str_contains($docs, 'audio_cleanup')
        && str_contains($docs, 'speech_transcribe')
        && str_contains($docs, 'speech_transcribe_fast_zh')
        && str_contains($docs, 'voice_generate'),
        'Cluster discovery must formally publish every production audio mode even while live availability is empty');
    hub_test_assert(str_contains((string)($manifest['async_task_contract']['task_id'] ?? ''), 'opaque string')
        && str_contains((string)($manifest['async_task_contract']['task_id'] ?? ''), 'never cast to integer')
        && str_contains((string)($manifest['async_task_contract']['native_difference'] ?? ''), 'Native api.php task_id is numeric')
        && str_contains($docs, 'Async task, result, artifact, and ACK contract'),
        'Cluster discovery must distinguish opaque Router task IDs from native numeric IDs');
});

hub_test('cluster public manifest selects only fresh contracts and rewrites router endpoints', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fresh = hub_test_cluster_router_station($db, [
            'station_key' => 'public_ocr_station',
            'public_base_url' => 'https://configured.station.example/aihub',
            'internal_base_url' => 'https://configured.internal.example:8443/aihub',
            'station_token' => 'configured_station_secret',
        ]);
        $stale = hub_test_cluster_router_station($db, [
            'station_key' => 'public_tts_station',
            'public_base_url' => 'https://stale.station.example/aihub',
            'internal_base_url' => 'https://stale.internal.example:8443/aihub',
            'station_token' => 'stale_station_secret',
        ]);
        $contract = [
            'mode' => 'ocr',
            'method' => 'POST',
            'content_type' => 'application/json',
            'endpoint' => 'api.php?mode=ocr',
            'url' => 'https://configured.station.example/aihub/api.php?mode=ocr',
            'input_fields' => [['name' => '<script>', 'type' => 'string', 'required' => true]],
            'output_keys' => ['ok', 'text'],
            'error_codes' => ['bad_request'],
            'result_artifact_fields' => ['id', 'type', 'mime_type', 'size_bytes', 'sha256'],
            'artifact_delivery_note' => 'Choose id from result.artifacts[], expand artifact_url_template, and ACK via ack_url_template.',
            'task_api' => [
                'status' => 'GET https://configured.station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
                'result' => 'GET https://configured.station.example/aihub/api.php?mode=task_result&task_id=remote_task_42',
                'log' => 'GET https://configured.station.example/aihub/api.php?mode=task_log&task_id=remote_task_42',
                'cancel' => 'POST https://configured.station.example/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
                'artifact' => 'GET https://configured.station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
            ],
            'operations' => [
                ['method' => 'GET', 'query' => [], 'response' => 'verified voice catalogue JSON'],
                ['method' => 'GET', 'query' => ['voice' => '<voice-id>'], 'response' => 'audio/mpeg; Cache-Control: private, no-store'],
                ['method' => 'POST', 'response' => 'asynchronous synthesis task'],
            ],
            'workflow_examples' => [
                'curl' => "curl 'https://configured.station.example/aihub/api.php?mode=task_result&task_id=remote_task_42'",
                'php' => "\$taskId = 'remote_task_42';",
                'js_fetch' => "fetch('https://configured.station.example/aihub/api.php?mode=artifact&artifact_id=77');",
            ],
            'examples' => [
                'curl' => "curl 'https://configured.station.example/aihub/api.php?mode=ocr'",
                'php' => "curl_init('https://configured.station.example/aihub/api.php?mode=ocr');",
                'js_fetch' => "fetch('https://configured.station.example/aihub/api.php?mode=ocr');",
            ],
        ];
        $imageContract = array_replace($contract, [
            'mode' => 'image_upload',
            'content_type' => 'multipart/form-data',
            'input_fields' => [['name' => 'image', 'type' => 'file', 'required' => true]],
            'workflow_examples' => [
                'curl' => "curl '<ROUTER_BASE_URL>/cluster_api.php?mode=cluster_task_status&task_id={task_id}'",
            ],
            'examples' => [
                'curl' => "curl -F 'image=@sample.png' 'https://configured.station.example/aihub/api.php?mode=image_upload'",
                'php' => "new CURLFile('/path/to/sample.png');",
                'js_fetch' => 'const formData = new FormData();',
            ],
        ]);
        $now = hub_now();
        $store = $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        );
        $store->execute([
            ':manifest_json' => json_encode(['modes' => ['ocr', 'image_upload'], 'services' => [$contract, $imageContract]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode(['modes' => ['ocr', 'image_upload'], 'gpu' => ['memory_free_mb' => 4096], 'active_gpu_leases' => 0, 'queued_jobs' => 0, 'running_jobs' => 0], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$fresh['id'],
        ]);
        $store->execute([
            ':manifest_json' => json_encode(['modes' => ['tts'], 'services' => [array_replace($contract, ['mode' => 'tts'])]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => date('Y-m-d H:i:s', time() - 151),
            ':status_json' => json_encode(['modes' => ['tts'], 'gpu' => ['memory_free_mb' => 4096], 'active_gpu_leases' => 0, 'queued_jobs' => 0, 'running_jobs' => 0], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => date('Y-m-d H:i:s', time() - 151),
            ':id' => (int)$stale['id'],
        ]);

        $manifest = hub_cluster_public_manifest($db);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        $servicesByMode = [];
        foreach ($manifest['services'] as $service) {
            if (is_array($service)) {
                $servicesByMode[(string)($service['mode'] ?? '')] = $service;
            }
        }
        $service = $servicesByMode['ocr'] ?? [];
        $imageService = $servicesByMode['image_upload'] ?? [];
        $docs = '';
        hub_test_with_cluster_pair_url(function () use ($db, &$docs): void {
            $_SERVER['HTTP_HOST'] = 'router.example';
            $_SERVER['SCRIPT_NAME'] = '/3waAIHub/cluster_public_api_docs.php';
            $docs = hub_cluster_public_api_docs_html($db);
        });

        hub_test_assert(array_column($manifest['services'], 'mode') === ['image_upload', 'ocr'], 'all fresh Router-compatible services, including multipart modes, may be public');
        hub_test_assert(($manifest['base_endpoint'] ?? '') === 'cluster_api.php' && str_contains((string)($manifest['inventory_note'] ?? ''), 'temporarily remove unavailable modes'), 'manifest must publish the Router base and inventory caveat');
        hub_test_assert(($service['endpoint'] ?? '') === 'cluster_api.php?mode=ocr' && str_contains((string)($service['examples']['curl'] ?? ''), 'cluster_api.php?mode=ocr'), 'all public service endpoints must use the Router');
        hub_test_assert(
            !isset($service['workflow_examples'], $imageService['workflow_examples']),
            'non-voice and partial upstream workflow examples must not enter the Router contract'
        );
        hub_test_assert(($service['task_api'] ?? []) === [
            'status' => 'GET cluster_api.php?mode=cluster_task_status&task_id={task_id}',
            'result' => 'GET cluster_api.php?mode=cluster_task_result&task_id={task_id}',
            'log' => 'GET cluster_api.php?mode=cluster_task_log&task_id={task_id}',
            'cancel' => 'POST cluster_api.php?mode=cluster_task_cancel&task_id={task_id}',
            'artifact' => 'GET cluster_api.php?mode=cluster_artifact&task_id={task_id}&artifact_id={artifact_id}',
        ], 'public async contracts must expose opaque Router followups');
        hub_test_assert(($service['operations'] ?? null) === $contract['operations'], 'public docs must preserve additional operations');
        hub_test_assert(
            ($service['result_artifact_fields'] ?? null) === ['id', 'size_bytes']
            && !str_contains((string)($service['artifact_delivery_note'] ?? ''), 'ack_url_template'),
            'general async Cluster modes must advertise the projected id/size artifact contract without ACK'
        );
        foreach ([
            'Additional operations',
            'verified voice catalogue JSON',
            'cluster_api.php?mode=ocr',
            'cluster_api.php?mode=image_upload',
            '-F',
            'new CURLFile',
            'FormData',
            '&lt;script&gt;',
        ] as $fragment) {
            hub_test_assert(str_contains($docs, $fragment), 'public docs missing escaped or form-aware fragment: ' . $fragment);
        }
        hub_test_assert(!str_contains($docs, 'name&quot;: &quot;<script>'), 'public docs must escape contract field names');
        hub_test_assert(
            str_contains($docs, 'https://router.example/3waAIHub/cluster_api.php')
            && str_contains($docs, 'Live catalog')
            && str_contains($docs, 'Available modes')
            && str_contains($docs, 'href="#mode-ocr"')
            && str_contains($docs, 'id="mode-ocr"')
            && str_contains($docs, 'navigator.clipboard.writeText'),
            'Cluster public docs must render the live developer portal contract'
        );
        foreach (['configured.station.example', 'configured.internal.example', 'stale.station.example', 'configured_station_secret', 'remote_task_42', 'mode=task_', '3wa_live_', 'token_ciphertext', 'token_iv', 'token_tag'] as $secret) {
            hub_test_assert(!str_contains($json, $secret), 'public manifest leaked station detail: ' . $secret);
            hub_test_assert(!str_contains($docs, $secret), 'public docs leaked station detail: ' . $secret);
        }
    });
});

hub_test('cluster artifact docs reserve rich metadata and ACK for rich Router modes', function (): void {
    $contract = [
        'mode' => 'ocr',
        'result_artifact_fields' => ['id', 'type', 'mime_type', 'size_bytes', 'sha256'],
        'artifact_delivery_note' => 'Choose id and ACK via ack_url_template.',
    ];
    foreach (['edge_tts', 'audio_cleanup', 'speech_transcribe', 'speech_transcribe_fast_zh', 'voice_generate'] as $mode) {
        $rich = hub_cluster_rewrite_contract_endpoint(
            array_replace($contract, ['mode' => $mode]),
            'https://station.invalid/aihub/api.php',
            'cluster_api.php',
            $mode === 'voice_generate'
        );
        hub_test_assert(
            ($rich['result_artifact_fields'] ?? null) === ['id', 'type', 'mime_type', 'size_bytes', 'sha256']
            && str_contains((string)($rich['artifact_delivery_note'] ?? ''), 'ack_url_template'),
            'rich Router mode must retain artifact metadata and ACK: ' . $mode
        );
    }
    $general = hub_cluster_rewrite_contract_endpoint(
        $contract,
        'https://station.invalid/aihub/api.php',
        'cluster_api.php'
    );
    hub_test_assert(
        ($general['result_artifact_fields'] ?? null) === ['id', 'size_bytes']
        && !str_contains((string)($general['artifact_delivery_note'] ?? ''), 'ack_url_template'),
        'general Router modes must expose only projected artifact id/size with no ACK'
    );
});

hub_test('cluster child preserves rich artifacts for every production audio task', function (): void {
    foreach (hub_audio_async_routes() as $mode => $route) {
        hub_test_assert(
            hub_gateway_cluster_child_rich_artifact_contract([
                'requested_mode' => $mode,
                'pack_id' => $route['pack_id'],
                'job' => $route['job'],
            ]),
            'Cluster child must preserve type, SHA-256, and ACK for ' . $mode
        );
    }
    hub_test_assert(
        !hub_gateway_cluster_child_rich_artifact_contract([
            'requested_mode' => 'speech_transcribe',
            'pack_id' => 'tts-voxcpm2',
            'job' => 'synthesize',
        ]),
        'Cluster child must not grant a rich artifact contract to a mismatched task'
    );
});

hub_test('cluster voice docs expose only opaque profile task workflow fields', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-cluster-docs']);
        $db->prepare(
            "UPDATE services
             SET mode = 'tts', install_status = 'installed', enabled = 1,
                 status = 'stopped', runtime_status = 'stopped'
             WHERE id = :id"
        )->execute([':id' => (int)$installed['service']['id']]);
        $native = array_column(hub_public_api_services($db, static fn (array $service): bool => true), null, 'mode')['voice_generate'] ?? null;
        hub_test_assert(is_array($native), 'native stopped async voice contract fixture missing');

        $clusterSource = $native;
        $clusterSource['description'] = 'Use VOICE_PROFILE_ID while preserving voice_profile_identifier.';
        $clusterSource['output_keys'][] = 'VOICE_PROFILE_ID';
        $clusterSource['output_keys'][] = 'voice_profile_identifier';
        $clusterSource['examples']['curl'] .= " -F 'VoIcE_PrOfIlE_Id=<CHILD_PROFILE_ID>'";
        $clusterSource['operations'][1]['response_schema'] = [
            'VOICE_PROFILE_ID' => ['type' => 'integer'],
            'voice_profile_identifier' => ['type' => 'string'],
            'properties' => [
                ['name' => 'VoIcE_PrOfIlE_Id', 'type' => 'integer'],
                ['name' => 'voice_profile_identifier', 'type' => 'string'],
                ['name' => 'voice_profile_task_id', 'type' => 'string'],
            ],
        ];
        $clusterSource['nested_contract'] = [
            'output_keys' => ['VOICE_PROFILE_ID', 'voice_profile_identifier', 'voice_profile_task_id'],
            'VOICE_PROFILE_ID' => ['type' => 'integer'],
            'VOICE_PROFILE_ID?' => ['type' => 'integer'],
            'prefix-voice_profile_id-suffix' => ['type' => 'integer'],
            'voice_profile_identifier' => ['type' => 'string'],
            'example' => '{"VOICE_PROFILE_ID":123}',
            'code' => 'const child = voice_PROFILE_id;',
            'safe_code' => 'const voice_profile_identifier = "preserved";',
            'note' => 'Submit voice_Profile_ID only after preparation; voice_profile_identifier remains valid.',
        ];

        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'voice_docs_station',
            'public_base_url' => 'https://voice-docs.invalid/aihub',
            'internal_base_url' => '',
            'modes' => ['voice_generate'],
        ]);
        $now = hub_now();
        $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        )->execute([
            ':manifest_json' => json_encode(['modes' => ['voice_generate'], 'services' => [$clusterSource]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode([
                'modes' => ['voice_generate'],
                'gpu' => ['memory_free_mb' => 16384],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
            ], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$station['id'],
        ]);

        $voice = array_column(hub_cluster_public_manifest($db)['services'], null, 'mode')['voice_generate'] ?? null;
        hub_test_assert(is_array($voice), 'Cluster voice_generate contract missing');
        $json = json_encode($voice, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $fields = array_column((array)$voice['input_fields'], 'name');
        hub_test_assert(in_array('voice_profile_task_id', $fields, true) && !in_array('voice_profile_id', $fields, true), 'Cluster voice_generate must expose only the opaque profile task handle');
        hub_test_assert(preg_match('/(?<![A-Za-z0-9_])voice_profile_id(?![A-Za-z0-9_])/i', $json) !== 1, 'Cluster voice contract must remove exact child voice_profile_id field names case-insensitively');
        hub_test_assert(str_contains($json, 'voice_profile_identifier'), 'Cluster projection must preserve legitimate field-name substrings');
        hub_test_assert(!str_contains($json, 'CHILD_PROFILE_ID') && !str_contains($json, '123'), 'Cluster projection must omit untrusted child examples instead of rewriting their code');
        hub_test_assert(
            ($voice['description'] ?? '') === 'Use voice_profile_task_id while preserving voice_profile_identifier.'
            && ($voice['nested_contract']['note'] ?? '') === 'Submit voice_profile_task_id only after preparation; voice_profile_identifier remains valid.',
            'Cluster projection must replace only standalone child identifier tokens in prose'
        );
        hub_test_assert(
            !isset($voice['nested_contract']['code'])
            && !isset($voice['nested_contract']['VOICE_PROFILE_ID?'])
            && !isset($voice['nested_contract']['prefix-voice_profile_id-suffix'])
            && ($voice['nested_contract']['safe_code'] ?? '') === 'const voice_profile_identifier = "preserved";',
            'Cluster projection must omit unsafe code and decorated forbidden keys while preserving legitimate identifier substrings'
        );
        hub_test_assert(str_contains($json, 'voice_profile_task_id'), 'recursive Cluster projection must preserve the opaque voice_profile_task_id');
        hub_test_assert(array_column((array)$voice['operations'], 'operation') === [
            'profile_prepare', 'profile_status', 'profile_confirm', 'profile_delete', 'synthesize',
        ], 'Cluster voice contract must retain all operations');
        $synthesize = array_column((array)$voice['operations'], null, 'operation')['synthesize'] ?? [];
        hub_test_assert(($synthesize['modes'] ?? null) === ['design', 'clone', 'ultimate_clone'], 'Cluster voice contract must retain all synthesis modes');
        $profileStatus = array_column((array)$voice['operations'], null, 'operation')['profile_status'] ?? [];
        $conditionalOutputs = array_column((array)($profileStatus['conditional_output_fields'] ?? []), null, 'name');
        $statusOutput = [
            'ok', 'task_status', 'profile_status', 'transcription_status', 'transcription_error',
            'transcript_confirmed', 'prompt_text_confirmed_at', 'profile_name', 'language',
            'consent_type', 'reference_audio_sha256', 'created_at', 'updated_at',
        ];
        hub_test_assert(
            ($profileStatus['output_keys'] ?? null) === $statusOutput
            && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'authenticated Profile member')
            && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'transcript_confirmed=false')
            && str_contains((string)($conditionalOutputs['prompt_text']['condition'] ?? ''), 'omitted after confirmation'),
            'Cluster profile_status must retain the safe conditional draft visibility contract'
        );
        $operations = array_column((array)$voice['operations'], null, 'operation');
        hub_test_assert(
            ($operations['profile_confirm']['output_keys'] ?? null) === [...$statusOutput, 'voice_profile_task_id', 'prompt_text_sha256']
            && ($operations['profile_delete']['output_keys'] ?? null) === $statusOutput,
            'Cluster profile operations must document the exact status and confirmation proof fields'
        );
        $confirmationProof = (string)($voice['workflow']['profile_confirmation_proof'] ?? '');
        foreach (['caller', 'opaque', 'authoritative stored exact UTF-8 bytes', 'lowercase SHA-256', 'prompt_text is omitted'] as $needle) {
            hub_test_assert(str_contains($confirmationProof, $needle), 'Cluster confirmation proof docs missing: ' . $needle);
        }
        hub_test_assert(
            ($voice['result_artifact_fields'] ?? null) === ['id', 'type', 'mime_type', 'size_bytes', 'sha256']
            && str_contains((string)($voice['artifact_delivery_note'] ?? ''), 'result.artifacts[]')
            && str_contains((string)($voice['artifact_delivery_note'] ?? ''), 'artifact_url_template')
            && str_contains((string)($voice['artifact_delivery_note'] ?? ''), 'ack_url_template'),
            'Cluster voice docs must retain the canonical artifact contract'
        );

        $errors = array_column((array)($voice['error_table'] ?? []), null, 'code');
        foreach ([
            'invalid_request' => 400,
            'voice_profile_wav_invalid' => 400,
            'voice_profile_transcript_invalid' => 400,
            'profile_task_not_found' => 404,
            'voice_profile_transcript_unconfirmed' => 409,
            'voice_profile_prepare_incomplete' => 409,
            'voice_profile_confirm_failed' => 409,
            'voice_profile_unavailable' => 410,
            'artifact_purged' => 410,
            'pack_runtime_not_ready' => 503,
            'station_unavailable' => 503,
        ] as $code => $status) {
            hub_test_assert(($errors[$code]['http_status'] ?? null) === $status, 'Cluster voice error status mismatch: ' . $code);
        }
        hub_test_assert(($errors['voice_profile_changed']['task_status'] ?? null) === 'failed', 'Cluster docs must retain the asynchronous changed-profile failure');
        hub_test_assert(!isset(
            $errors['voice_profile_forbidden'],
            $errors['voice_profile_not_found'],
            $errors['voice_profile_prepare_conflict'],
            $errors['voice_profile_callback_conflict'],
            $errors['voice_profile_prepare_failed'],
            $errors['voice_profile_delete_failed']
        ), 'Cluster docs must keep native-only ownership, conflict, and internal failure errors separate');
        hub_test_assert(str_contains((string)($voice['workflow']['client_state'] ?? ''), 'voice_profile_task_id'), 'Cluster workflow must tell MyAI what opaque state to retain');
        hub_test_assert(str_contains((string)($voice['workflow']['profile_affinity'] ?? ''), 'pinned station')
            && str_contains((string)($voice['workflow']['profile_affinity'] ?? ''), 'no failover'), 'Cluster workflow must document station pinning without failover');
        hub_test_assert(
            str_contains((string)($voice['workflow']['profile_ownership'] ?? ''), 'currently valid Token')
            && str_contains((string)($voice['workflow']['profile_ownership'] ?? ''), 'voice_generate permission')
            && str_contains((string)($voice['workflow']['profile_ownership'] ?? ''), 'submitting Token'),
            'Cluster workflow must separate member-owned Profiles from Token-bound tasks'
        );

        foreach ((array)$voice['workflow_examples'] as $kind => $example) {
            $example = (string)$example;
            foreach (['profile_prepare', 'cluster_task_status', 'profile_status', 'profile_confirm', 'ultimate_clone', 'cluster_artifact', 'profile_delete'] as $step) {
                hub_test_assert(str_contains($example, $step), 'Cluster ' . $kind . ' workflow example missing ' . $step);
            }
            foreach (['<TOKEN>', '<REFERENCE_WAV>', '<VOICE_PROFILE_TASK_ID>', '<CONFIRMED_TRANSCRIPT>', '<TASK_ID>', '<ARTIFACT_ID>'] as $placeholder) {
                hub_test_assert(str_contains($example, $placeholder), 'Cluster ' . $kind . ' workflow example missing placeholder ' . $placeholder);
            }
            hub_test_assert(str_contains($example, 'pinned station') && str_contains($example, 'no failover'), 'Cluster ' . $kind . ' workflow example must state profile affinity');
            foreach (['voice_profile_id', '3wa_live_', '/home/', '/data/', 'http://', 'https://', 'voice-docs.invalid'] as $forbidden) {
                hub_test_assert(!str_contains($example, $forbidden), 'Cluster ' . $kind . ' workflow example leaked a concrete or child-only value: ' . $forbidden);
            }
        }

        $server = $_SERVER;
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'router-docs.invalid';
        $_SERVER['SCRIPT_NAME'] = '/aihub/cluster_public_api_docs.php';
        try {
            $docs = hub_cluster_public_api_docs_html($db);
        } finally {
            $_SERVER = $server;
        }
        foreach (['profile_prepare', 'cluster_task_status', 'profile_status', 'profile_confirm', 'ultimate_clone', 'cluster_artifact', 'profile_delete', 'pinned station', 'no failover'] as $needle) {
            hub_test_assert(str_contains($docs, $needle), 'Cluster HTML docs missing voice workflow detail: ' . $needle);
        }
        hub_test_assert(
            preg_match('/(?<![A-Za-z0-9_])voice_profile_id(?![A-Za-z0-9_])/i', $docs) !== 1,
            'Cluster HTML docs must not expose an exact child voice_profile_id'
        );
        hub_test_assert(str_contains($docs, 'https://router-docs.invalid/aihub/cluster_api.php'), 'rendered Cluster examples must contain the exact Router base');
        hub_test_assert(str_contains($docs, 'mode=voice_generate'), 'rendered Cluster examples must contain the voice_generate link');
        hub_test_assert(str_contains($docs, 'mode=cluster_task_status'), 'rendered Cluster examples must contain the cluster_task_status link');
        hub_test_assert(str_contains($docs, 'mode=cluster_artifact'), 'rendered Cluster examples must contain the cluster_artifact link');
        hub_test_assert(!str_contains($docs, '&lt;ROUTER_BASE_URL&gt;'), 'rendered Cluster examples must remove the Router placeholder');
        hub_test_assert(!str_contains($docs, '/https://'), 'rendered Cluster examples must not duplicate the Router base');
    });
});

hub_test('cluster voice dispatch safely relays only documented child error pairs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'voice_error_station',
            'station_token' => 'voice_error_station_token',
            'internal_base_url' => 'https://voice-error.internal/aihub',
            'modes' => ['voice_generate'],
        ]);
        $customer = hub_test_cluster_router_customer_token($db, ['voice_generate']);
        $inventory = hub_test_cluster_station_fixture([
            'id' => (int)$station['id'],
            'station_key' => 'voice_error_station',
            'modes' => ['voice_generate'],
        ]);
        $request = hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=voice-errors'],
            'raw_body' => '',
            'post' => ['operation' => 'profile_prepare', 'profile_name' => 'Error fixture', 'consent_type' => 'self_recorded'],
            'files' => [],
        ]);
        $documented = array_column(hub_cluster_voice_generate_error_table(), null, 'code');

        foreach (hub_cluster_voice_generate_relay_errors() as $childCode => $rule) {
            $response = hub_cluster_dispatch($db, 'voice_generate', $request, [
                'refresh_due' => static fn (): array => [$inventory],
                'transport' => static fn (): array => [
                    'status' => $rule['http_status'],
                    'headers' => ['Content-Type: application/json', 'X-Child-Secret: child-secret'],
                    'body' => json_encode([
                        'ok' => false,
                        'error' => $childCode,
                        'message' => 'child-private-message',
                        'request_id' => 'child-request-id',
                    ], JSON_THROW_ON_ERROR),
                ],
            ]);
            $payload = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
            $publicCode = $rule['public_code'];
            hub_test_assert(($documented[$publicCode]['http_status'] ?? null) === $rule['http_status'], 'relayed child error must have the same documented Cluster status: ' . $publicCode);
            hub_test_assert($response['status'] === $rule['http_status'] && ($payload['error'] ?? '') === $publicCode, 'documented child error pair must survive Cluster dispatch: ' . $childCode);
            hub_test_assert(($payload['message'] ?? '') === $rule['message'] && !str_contains($response['body'], 'child-private'), 'relayed child errors must use Router-owned safe JSON');
            hub_test_assert(!str_contains(implode("\n", $response['headers'] ?? []), 'X-Child-Secret'), 'relayed child errors must rebuild safe response headers');
        }

        $designRequest = hub_test_cluster_router_request((string)$customer['plain_token'], [
            'headers' => ['Content-Type' => 'application/json'],
            'raw_body' => json_encode(['mode' => 'design', 'text' => 'Design a calm voice.'], JSON_THROW_ON_ERROR),
            'post' => [],
            'files' => [],
        ]);
        $designResponse = hub_cluster_dispatch($db, 'voice_generate', $designRequest, [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static fn (): array => [
                'status' => 400,
                'headers' => ['Content-Type: application/json', 'X-Child-Secret: child-secret'],
                'body' => json_encode([
                    'ok' => false,
                    'error' => 'invalid_request',
                    'message' => 'child-private-message',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $designPayload = json_decode($designResponse['body'], true, 16, JSON_THROW_ON_ERROR);
        hub_test_assert(
            $designResponse['status'] === 400
            && ($designPayload['error'] ?? '') === 'invalid_request'
            && ($designPayload['message'] ?? '') === hub_cluster_voice_generate_relay_errors()['invalid_request']['message']
            && !str_contains(implode("\n", $designResponse['headers'] ?? []), 'X-Child-Secret'),
            'omitted-operation profile-free design synthesis must use the documented safe voice_generate relay'
        );

        foreach ([
            [409, ['ok' => false, 'error' => 'unknown_child_error', 'message' => 'private']],
            [400, ['ok' => false, 'error' => 'voice_profile_unavailable', 'message' => 'wrong status']],
            [409, ['ok' => false, 'error' => 'voice_profile_unavailable', 'message' => 'old status']],
            [410, ['ok' => true, 'error' => 'voice_profile_unavailable', 'message' => 'wrong ok']],
            [410, ['ok' => false, 'error' => 'voice_profile_unavailable', 'message' => 'private', 'extra' => 'leak']],
            [410, ['ok' => false, 'error' => 'voice_profile_unavailable', 'message' => 'private', 'request_id' => null]],
        ] as [$status, $childPayload]) {
            $response = hub_cluster_dispatch($db, 'voice_generate', $request, [
                'refresh_due' => static fn (): array => [$inventory],
                'transport' => static fn (): array => [
                    'status' => $status,
                    'headers' => ['Content-Type: application/json'],
                    'body' => json_encode($childPayload, JSON_THROW_ON_ERROR),
                ],
            ]);
            hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_response_failed'), 'unknown or malformed child errors must remain generic 502 responses');
        }

        $unknownDesignResponse = hub_cluster_dispatch($db, 'voice_generate', $designRequest, [
            'refresh_due' => static fn (): array => [$inventory],
            'transport' => static fn (): array => [
                'status' => 400,
                'headers' => ['Content-Type: application/json'],
                'body' => json_encode([
                    'ok' => false,
                    'error' => 'unknown_child_error',
                    'message' => 'private',
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        hub_test_assert(
            $unknownDesignResponse['status'] === 502
            && str_contains($unknownDesignResponse['body'], 'router_response_failed'),
            'unknown profile-free voice_generate child errors must remain generic 502 responses'
        );
    });
});

hub_test('cluster router relays only structured field-level Pack validation errors', function (): void {
    $response = hub_cluster_pack_validation_error_response(
        hub_gateway_json(400, [
            'ok' => false,
            'error' => 'invalid_request',
            'message' => 'min_speakers requires diarization=true',
            'field_errors' => ['min_speakers' => 'requires diarization=true'],
        ]),
        [
            'ok' => false,
            'error' => 'invalid_request',
            'message' => 'min_speakers requires diarization=true',
            'field_errors' => ['min_speakers' => 'requires diarization=true'],
        ]
    );
    $payload = json_decode((string)($response['body'] ?? ''), true);

    hub_test_assert(($response['status'] ?? null) === 400
        && ($payload['field_errors'] ?? null) === ['min_speakers' => 'requires diarization=true'],
        'Router must retain the bounded field error needed by Cluster clients');
    hub_test_assert(hub_cluster_pack_validation_error_response(
        hub_gateway_json(400, ['ok' => false]),
        ['ok' => false, 'error' => 'invalid_request', 'message' => 'private URL https://station.invalid', 'field_errors' => ['min_speakers' => 'private URL']]
    ) === null, 'Router must reject malformed or free-form child validation errors');
});

hub_test('cluster docs example URL normalization is exact and idempotent', function (): void {
    $router = 'https://router.example/aihub/cluster_api.php';
    $cases = [
        "curl '<ROUTER_BASE_URL>/cluster_api.php?mode=voice_generate'"
            => "curl '" . $router . "?mode=voice_generate'",
        "curl 'cluster_api.php?mode=voice_generate'"
            => "curl '" . $router . "?mode=voice_generate'",
        "curl '" . $router . "?mode=voice_generate'"
            => "curl '" . $router . "?mode=voice_generate'",
        '{"value":"cluster_api.php"}'
            => '{"value":"cluster_api.php"}',
    ];
    foreach ($cases as $source => $expected) {
        $normalized = hub_cluster_public_docs_example($source, $router);
        hub_test_assert($normalized === $expected, 'Cluster docs URL normalization mismatch');
        hub_test_assert(hub_cluster_public_docs_example($normalized, $router) === $expected, 'Cluster docs URL normalization must be idempotent');
    }
});

hub_test('cluster public contract rewrite removes a selected station base from endpoints and examples', function (): void {
    $service = hub_cluster_rewrite_contract_endpoint([
        'endpoint' => 'api.php?mode=vision',
        'url' => 'https://station.example/aihub/api.php?mode=vision',
        'task_api' => [
            'status' => 'GET https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result' => 'GET api.php?mode=task_result&task_id={task_id}',
            'log' => 'GET https://station.example/aihub/api.php?mode=task_log&task_id={task_id}',
            'cancel' => 'POST api.php?mode=task_cancel&task_id={task_id}',
            'artifact' => 'GET https://station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'links' => [
            'status_url' => 'https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result_url' => 'api.php?mode=task_result&task_id={task_id}',
            'log_url' => 'https://station.example/aihub/api.php?mode=task_log&task_id={task_id}',
            'cancel_url' => 'api.php?mode=task_cancel&task_id={task_id}',
            'artifact_url_template' => 'https://station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'examples' => ['curl' => "curl 'https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42'"],
        'payload' => [
            'json' => '{"text":"cluster_api.php","link":"api.php?mode=task_status"}',
            'voice_profile_identifier' => 'api.php?mode=task_result',
        ],
    ], 'https://station.example/aihub/api.php', 'cluster_api.php');
    $json = json_encode($service, JSON_THROW_ON_ERROR);

    foreach ([
        'cluster_api.php?mode=cluster_task_status&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_result&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_log&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_cancel&task_id={task_id}',
        'cluster_api.php?mode=cluster_artifact&task_id={task_id}&artifact_id={artifact_id}',
    ] as $endpoint) {
        hub_test_assert(str_contains($json, $endpoint), 'async contract must use the Router followup template: ' . $endpoint);
    }
    hub_test_assert(!isset($service['examples']), 'untrusted child examples must be omitted before Router-owned examples are regenerated');
    hub_test_assert(($service['payload']['json'] ?? '') === '{"text":"cluster_api.php","link":"api.php?mode=task_status"}'
        && ($service['payload']['voice_profile_identifier'] ?? '') === 'api.php?mode=task_result', 'contract URL rewriting must not mutate arbitrary payload strings');
    unset($service['payload']);
    $json = json_encode($service, JSON_THROW_ON_ERROR);
    hub_test_assert(!str_contains($json, 'station.example') && !str_contains($json, 'remote_task_42') && !str_contains($json, 'mode=task_') && str_contains($json, 'cluster_api.php?mode=vision'), 'rewritten contracts must expose Router URLs only');
});

hub_test('cluster public contract rewrite removes mixed-case station origins', function (): void {
    $service = hub_cluster_rewrite_contract_endpoint([
        'url' => 'https://STATION.EXAMPLE/aihub/api.php?mode=vision',
        'task_api' => ['status' => 'GET https://STATION.EXAMPLE/aihub/api.php?mode=task_status&task_id=remote_task_42'],
        'examples' => ['curl' => "curl 'https://STATION.EXAMPLE/aihub/api.php?mode=task_status&task_id=remote_task_42'"],
    ], 'https://station.example/aihub/api.php', 'cluster_api.php');
    $json = json_encode($service, JSON_THROW_ON_ERROR);

    hub_test_assert(!str_contains(strtolower($json), 'station.example') && !str_contains($json, 'remote_task_42') && str_contains($json, 'cluster_api.php?mode=cluster_task_status&task_id={task_id}'), 'mixed-case station origins must not leak from public contracts');
});

hub_test('cluster public contract rewrite removes bracketed IPv6 station origins', function (): void {
    $service = hub_cluster_rewrite_contract_endpoint([
        'url' => 'https://[fd00:beef::1]:8080/aihub/api.php?mode=vision',
        'task_api' => ['status' => 'GET https://[fd00:beef::1]:8080/aihub/api.php?mode=task_status&task_id=remote_task_42'],
        'examples' => ['curl' => "curl 'https://[fd00:beef::1]:8080/aihub/api.php?mode=task_status&task_id=remote_task_42'"],
    ], 'https://[fd00:beef::1]:8080/aihub/api.php', 'cluster_api.php');
    $json = json_encode($service, JSON_THROW_ON_ERROR);

    hub_test_assert(!str_contains($json, 'fd00:beef::1') && !str_contains($json, 'remote_task_42') && str_contains($json, 'cluster_api.php?mode=cluster_task_status&task_id={task_id}'), 'bracketed IPv6 station origins must not leak from public contracts');
});

hub_test('cluster router followups never retry pinned stations and reserve private modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_key' => 'pinned_station', 'station_token' => 'pinned_station_token']);
        hub_test_cluster_router_station($db, ['station_key' => 'unused_station', 'station_token' => 'unused_station_token', 'priority' => 99]);
        $calls = 0;

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$calls): array {
            $calls++;
            hub_test_assert($request['url'] === 'https://station.internal:8080/aihub/cluster_followup.php', 'followup must keep the original station control-plane endpoint');
            throw new RuntimeException('station unavailable');
        });

        foreach (['cluster_task_status', 'cluster_task_result', 'cluster_task_log', 'cluster_task_cancel', 'cluster_artifact'] as $mode) {
            hub_test_assert(hub_cluster_router_is_followup_mode($mode), 'reserved followup mode must bypass normal pack selection: ' . $mode);
        }
        hub_test_assert(!hub_cluster_router_is_followup_mode('vision'), 'normal pack modes must not use the private followup path');
        hub_test_assert($response['status'] === 503 && str_contains($response['body'], 'station_unavailable') && $calls === 1, 'pinned transport failures must return 503 without retrying another station');
    });
});

hub_test('cluster admin usage helpers count submit events and keep station presentation secret-free', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $customer = hub_test_cluster_router_customer_token($db, []);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $now = hub_now();
        $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        )->execute([
            ':manifest_json' => json_encode([
                'modes' => ['vision', 'tts'],
                'services' => [
                    ['mode' => 'vision', 'name' => 'Vision'],
                    ['mode' => 'tts', 'name' => 'Speech'],
                ],
            ], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode([
                'modes' => ['vision'],
                'gpu' => ['memory_free_mb' => 4096, 'memory_total_mb' => 8192],
                'service_gpu' => [[
                    'service_key' => 'vision-gpu',
                    'mode' => 'vision',
                    'vram_used_mb' => 2048,
                    'measured' => true,
                ]],
                'service_status' => [[
                    'service_key' => 'vision-gpu',
                    'pack_id' => 'vision-pack',
                    'mode' => 'vision',
                    'enabled' => true,
                    'install_status' => 'installed',
                    'runtime_status' => 'running',
                ]],
                'active_gpu_leases' => 2,
                'queued_jobs' => 3,
                'running_jobs' => 4,
                'release' => [
                    'build_id' => '20260807001',
                    'commit' => 'abcdef1',
                    'dirty' => false,
                    'tag' => '',
                    'token' => 'dashboard-release-secret',
                ],
                'packs' => hub_release_pack_inventory(),
                'runners' => [
                    'vision_pack' => [
                        'digest' => 'sha256:' . str_repeat('b', 64),
                        'path' => '/private/image',
                    ],
                ],
                'health' => [
                    'status' => 'ok',
                    'installed_services' => 2,
                    'running_services' => 1,
                    'failed_services' => 0,
                    'queued_jobs' => 3,
                    'running_jobs' => 4,
                    'output' => 'private health output',
                ],
                'cluster' => [
                    'aggregate' => true,
                    'children_count' => 2,
                    'published_mode_count' => 1,
                    'url' => 'https://private.example',
                ],
            ], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$station['id'],
        ]);
        $route = $db->prepare(
            'INSERT INTO cluster_routes
                (route_id, station_id, member_id, token_id, mode, state, created_at, updated_at, completed_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :state, :created_at, :updated_at, :completed_at)'
        );
        foreach ([
            ['route_admin_1', 'succeeded', '2026-01-01 10:00:00', '2026-01-01 11:00:00'],
            ['route_admin_2', 'failed', '2026-01-01 10:30:00', '2026-01-01 12:00:00'],
            ['route_admin_3', 'active', '2026-01-01 10:45:00', null],
        ] as [$routeId, $state, $createdAt, $completedAt]) {
            $route->execute([
                ':route_id' => $routeId,
                ':station_id' => (int)$station['id'],
                ':member_id' => $memberId,
                ':token_id' => (int)$customer['token_id'],
                ':mode' => 'vision',
                ':state' => $state,
                ':created_at' => $createdAt,
                ':updated_at' => $completedAt ?? $createdAt,
                ':completed_at' => $completedAt,
            ]);
        }
        $access = $db->prepare(
            'INSERT INTO cluster_route_accesses
                (route_id, station_id, member_id, token_id, mode, access_kind, status_code, ok, elapsed_ms, upload_bytes, response_bytes, created_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :access_kind, :status_code, :ok, 0, :upload_bytes, :response_bytes, :created_at)'
        );
        foreach ([
            ['route_admin_1', 'submit', 200, 1, 100, 200],
            ['route_admin_1', 'proxy', 200, 1, 0, 20],
            ['route_admin_2', 'submit', 500, 0, 50, 10],
            ['route_admin_3', 'submit', 202, 1, 25, 40],
        ] as [$routeId, $kind, $statusCode, $ok, $uploadBytes, $responseBytes]) {
            $access->execute([
                ':route_id' => $routeId,
                ':station_id' => (int)$station['id'],
                ':member_id' => $memberId,
                ':token_id' => (int)$customer['token_id'],
                ':mode' => 'vision',
                ':access_kind' => $kind,
                ':status_code' => $statusCode,
                ':ok' => $ok,
                ':upload_bytes' => $uploadBytes,
                ':response_bytes' => $responseBytes,
                ':created_at' => '2026-01-01 10:00:00',
            ]);
        }

        $filters = [
            'member_id' => $memberId,
            'token_id' => (int)$customer['token_id'],
            'station_id' => (int)$station['id'],
            'mode' => 'vision',
        ];
        $summary = hub_cluster_usage_summary($db, $filters);
        $rows = hub_cluster_usage_rows($db, $filters);
        $dashboard = hub_cluster_station_dashboard_rows($db);
        $recent = hub_cluster_recent_routes($db, $filters, 10);

        hub_test_assert($summary === [
            'work_requests' => 3,
            'accesses' => 4,
            'success_count' => 3,
            'failed_count' => 1,
            'active_routes' => 1,
            'peak_concurrency' => 3,
            'upload_bytes' => 175,
            'response_bytes' => 270,
        ], 'cluster usage summary must count submit work separately from all access events and sweep route lifetimes');
        hub_test_assert(count($rows) === 1 && (int)$rows[0]['work_requests'] === 3 && (int)$rows[0]['accesses'] === 4, 'cluster usage rows must group the selected member token and station events');
        hub_test_assert(count($recent) === 3 && !str_contains(json_encode($recent, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'recent routes must be presentation-safe');
        hub_test_assert(count($dashboard) === 1, 'station dashboard must include the paired station');
        hub_test_assert(!empty($dashboard[0]['token_configured']), 'station dashboard must expose only configured token state');
        hub_test_assert(!empty($dashboard[0]['fresh']), 'station dashboard must use cached freshness');
        hub_test_assert((int)$dashboard[0]['active_route_count'] === 1, 'station dashboard must count active Router routes');
        hub_test_assert(($dashboard[0]['mode_readiness'] ?? []) === [
            ['mode' => 'tts', 'ready' => false],
            ['mode' => 'vision', 'ready' => true],
        ], 'station dashboard must show manifest and status readiness per mode');
        hub_test_assert(($dashboard[0]['release'] ?? null) === [
            'build_id' => '20260807001',
            'commit' => 'abcdef1',
            'dirty' => false,
            'tag' => '',
        ], 'station dashboard release shape mismatch');
        hub_test_assert(($dashboard[0]['packs'] ?? null) === hub_release_pack_inventory(), 'station dashboard Pack inventory mismatch');
        hub_test_assert(($dashboard[0]['runners']['vision_pack']['digest'] ?? '') === 'sha256:' . str_repeat('b', 64), 'station dashboard runner digest missing');
        hub_test_assert(($dashboard[0]['health']['status'] ?? '') === 'ok', 'station dashboard health missing');
        hub_test_assert(($dashboard[0]['cluster'] ?? null) === [
            'aggregate' => true,
            'children_count' => 2,
            'published_mode_count' => 1,
        ], 'station dashboard aggregate shape mismatch');
        hub_test_assert(($dashboard[0]['service_gpu'] ?? null) === [[
            'service_key' => 'vision-gpu',
            'mode' => 'vision',
            'vram_used_mb' => 2048,
            'measured' => true,
        ]], 'station dashboard must carry compact child service GPU telemetry');
        hub_test_assert(($dashboard[0]['service_status'] ?? null) === [[
            'service_key' => 'vision-gpu',
            'pack_id' => 'vision-pack',
            'mode' => 'vision',
            'enabled' => true,
            'install_status' => 'installed',
            'runtime_status' => 'running',
        ]], 'station dashboard must carry compact child service runtime telemetry');
        hub_test_assert(($dashboard[0]['service_count'] ?? 0) === 2 && array_column($dashboard[0]['services'] ?? [], 'mode') === ['vision', 'tts'], 'station dashboard supplied services mismatch');
        hub_test_assert(($dashboard[0]['release_compatible'] ?? null) === false && ($dashboard[0]['pack_compatible'] ?? null) === true, 'station dashboard local compatibility mismatch');
        foreach (['dashboard-release-secret', '/private/image', 'private health output', 'private.example'] as $forbidden) {
            hub_test_assert(!str_contains(json_encode($dashboard[0], JSON_THROW_ON_ERROR), $forbidden), 'station dashboard leaked forbidden nested data: ' . $forbidden);
        }
        hub_test_assert(!str_contains(json_encode($dashboard, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'station dashboard must never expose a decrypted station token');
        hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_usage_summary($db, ['station_id' => '1 OR 1=1'])), 'cluster usage filters must reject untrusted station values');
    });
});

hub_test('cluster admin child controls retain only published modes and force one station refresh', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        $configured = hub_cluster_node_configure($db, true, ['vision', 'not_running']);
        $permissions = array_column(hub_list_api_token_permissions($db, hub_cluster_node_token_id($db)), 'mode');
        sort($permissions);
        hub_test_assert(($configured['modes'] ?? []) === ['vision'] && $permissions === ['cluster_status', 'vision'], 'child mode controls must keep only currently published modes plus managed status');

        $station = hub_test_cluster_router_station($db);
        $requests = [];
        hub_cluster_refresh_station_now($db, $station, true, static function (array $request) use (&$requests): array {
            $requests[] = $request;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'vision']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => hub_now(),
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['vision'],
            ], JSON_THROW_ON_ERROR)];
        });
        hub_test_assert(count($requests) === 2, 'forced station refresh must fetch only the selected station inventory');
    });
});

hub_test('cluster router refresh retains one compact GPU metric snapshot', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $snapshotAt = hub_now();
        $fetcher = static function (array $request) use ($snapshotAt): array {
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'vision']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => $snapshotAt,
                'gpu' => [
                    'available' => true,
                    'util_percent' => 42,
                    'memory_used_mb' => 2048,
                    'memory_total_mb' => 8192,
                    'temperature_c' => 71,
                    'memory_free_mb' => 6144,
                    'name' => 'Not History Telemetry',
                    'driver_version' => '555.85.10',
                    'cuda_version' => '12.4',
                    'reason' => 'not_used',
                    'injected' => 'must_not_be_stored',
                ],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['vision'],
            ], JSON_THROW_ON_ERROR)];
        };

        hub_cluster_refresh_station_now($db, $station, true, $fetcher);
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM cluster_gpu_metric_snapshots')->fetchColumn() === 1, 'valid refreshed child must create a GPU metric sample');
        hub_cluster_refresh_station_now($db, $station, true, $fetcher);

        $samples = $db->query('SELECT sampled_at, gpu_json FROM cluster_gpu_metric_snapshots ORDER BY sampled_at DESC')->fetchAll();
        hub_test_assert(count($samples) === 1 && (string)$samples[0]['sampled_at'] === $snapshotAt, 'same refreshed status timestamp must retain exactly one current GPU metric sample');
        hub_test_assert(json_decode((string)$samples[0]['gpu_json'], true, 512, JSON_THROW_ON_ERROR) === [
            'available' => true,
            'util_percent' => 42,
            'memory_used_mb' => 2048,
            'memory_total_mb' => 8192,
            'temperature_c' => 71,
        ], 'GPU metric history must persist only compact GPU fields');

        hub_cluster_refresh_station_now($db, $station, true, static function (array $request): array {
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'vision']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 500, 'body' => ''];
        });
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM cluster_gpu_metric_snapshots')->fetchColumn() === 1, 'failed status refresh must not create a GPU metric sample');
    });
});

hub_test('cluster GPU metric history migration replaces its redundant station time index', function (): void {
    $db = hub_test_reset_db();
    $db->exec('DROP INDEX IF EXISTS idx_cluster_gpu_metric_snapshots_station_time');
    $db->exec('CREATE INDEX idx_cluster_gpu_metric_snapshots_station_time ON cluster_gpu_metric_snapshots(station_id, sampled_at DESC)');

    hub_migrate($db);
    $indexes = array_column($db->query('PRAGMA index_list(cluster_gpu_metric_snapshots)')->fetchAll(), 'name');

    hub_test_assert(!in_array('idx_cluster_gpu_metric_snapshots_station_time', $indexes, true) && in_array('idx_cluster_gpu_metric_snapshots_sampled_at', $indexes, true), 'GPU metric history migration must remove the redundant station time index and add a global sampled time index');
});

hub_test('retention schema gate requires the child GPU sample timestamp', function (): void {
    $db = hub_test_reset_db();
    $db->exec('DROP TABLE cluster_gpu_metric_snapshots');
    $db->exec('CREATE TABLE cluster_gpu_metric_snapshots (id INTEGER PRIMARY KEY, station_id INTEGER NOT NULL, gpu_json TEXT NOT NULL)');

    hub_test_assert(
        hub_retention_schema_missing($db) === ['cluster_gpu_metric_snapshots.sampled_at'],
        'retention schema gate must reject a child GPU history table without the timestamp used by pruning'
    );
});

hub_test('scheduled retention pruning expires offline child GPU metric history', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $db->prepare('UPDATE cluster_stations SET enabled = 0, last_error = :last_error WHERE id = :id')
            ->execute([':last_error' => 'status_fetch_failed', ':id' => (int)$station['id']]);
        $insert = $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        );
        $insert->execute([
            ':station_id' => (int)$station['id'],
            ':sampled_at' => '2026-07-31 11:59:59',
            ':gpu_json' => '{"available":true}',
        ]);
        $insert->execute([
            ':station_id' => (int)$station['id'],
            ':sampled_at' => '2026-07-31 12:00:00',
            ':gpu_json' => '{"available":true}',
        ]);
        $insert->execute([
            ':station_id' => (int)$station['id'],
            ':sampled_at' => '2026-08-01 11:59:00',
            ':gpu_json' => '{"available":true}',
        ]);

        $report = hub_prune_retention($db, '2026-08-01 12:00:00');

        hub_test_assert(($report['cluster_gpu_metrics_purged'] ?? null) === 1, 'scheduled retention pruning must report the expired offline child GPU sample');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM cluster_gpu_metric_snapshots')->fetchColumn() === 2, 'scheduled retention pruning must preserve boundary and current offline child GPU samples without a refresh');
    });
});

hub_test('cluster admin page exposes guarded controls without station encryption internals', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    $members = (string)file_get_contents(HUB_ROOT . '/admin/api_members.php');
    $tokens = (string)file_get_contents(HUB_ROOT . '/admin/api_tokens.php');

    foreach (['hub_require_system_admin($db)', 'hub_check_csrf()', 'save_roles', 'save_child_modes', 'regenerate_node_token', 'renew_invitation', 'pair_child', 'toggle_station', 'refresh_station', 'delete_station', '子入口節點', '統一入口', '子節點 Token', '新增子節點', 'cluster.php?view=usage'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'cluster admin page missing required control: ' . $needle);
    }
    foreach (['token_ciphertext', 'token_iv', 'token_tag', 'hub_cluster_station_token('] as $needle) {
        hub_test_assert(!str_contains($page, $needle), 'cluster admin page must not reference station token internals: ' . $needle);
    }
    hub_test_assert(str_contains($layout, 'cluster.php') && str_contains($layout, 'Cluster'), 'admin navigation must link to Cluster');
    hub_test_assert(str_contains($members, 'Cluster 用量') && str_contains($tokens, 'Cluster 用量'), 'member and token pages must link to filtered Cluster usage');
    hub_test_assert(str_contains($page, '$refreshed = hub_cluster_refresh_station_now') && str_contains($page, "!empty(\$refreshed['last_error']) || empty(\$refreshed['fresh'])"), 'cluster admin refresh must reject failed or stale inventory results');
    hub_test_assert(str_contains($page, 'hub_cluster_pair_invitation_is_current') && str_contains($page, "unset(\$_SESSION['hub_cluster_pair_invite'])"), 'cluster admin must clear stale invitation secrets before rendering a pairing link');
});

hub_test('cluster pairing invitation helper rejects replaced and expired secrets', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $initial = hub_cluster_node_configure($db, true, []);
        $replacement = hub_cluster_create_pair_invitation($db);

        hub_test_assert(!hub_cluster_pair_invitation_is_current($db, (string)$initial['invite']), 'replaced invitation secret must not remain current');
        hub_test_assert(hub_cluster_pair_invitation_is_current($db, (string)$replacement['invite']), 'current invitation secret must match the stored hash and expiry');
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', date('Y-m-d H:i:s', time() - 1));
        hub_test_assert(!hub_cluster_pair_invitation_is_current($db, (string)$replacement['invite']), 'expired invitation secret must not remain current');
    });
});

hub_test('cluster admin pairing descriptor keeps cluster pair at the application root', function (): void {
    $previous = $_SERVER;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'station.example';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/cluster.php';

    try {
        $db = hub_test_reset_db();
        $descriptor = hub_cluster_node_pairing_descriptor($db);
        hub_test_assert($descriptor['public_base_url'] === 'https://station.example/3waAIHub/', 'admin pairing links must resolve cluster_pair.php at the application root');
    } finally {
        $_SERVER = $previous;
    }
});
