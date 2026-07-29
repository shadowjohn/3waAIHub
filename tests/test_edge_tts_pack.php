<?php
declare(strict_types=1);

function hub_test_edge_tts_payload(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    hub_test_assert(is_array($payload), 'Edge TTS gateway response must be JSON');

    return $payload;
}

function hub_test_edge_tts_request(PDO $db, string $token, array $post = [], string $method = 'POST'): array
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.71';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=edge_tts';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_SERVER['HTTP_HOST'] = 'hub.test';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/api.php';
    $_SERVER['CONTENT_LENGTH'] = (string)strlen(http_build_query($post));
    $_POST = $post;
    $_GET = [];
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

hub_test('Edge TTS Pack publishes the ready CPU-only async runner contract', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('edge-tts');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'Edge TTS Pack must validate with its runner build context');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');

    hub_test_assert(($manifest['id'] ?? null) === 'edge-tts'
        && ($manifest['version'] ?? null) === '0.2.0'
        && ($manifest['category'] ?? null) === 'audio'
        && ($manifest['runtime_level'] ?? null) === 'L2-container-runner'
        && ($manifest['runtime_ready'] ?? null) === true
        && ($manifest['default_mode'] ?? null) === 'edge_tts'
        && ($manifest['experimental'] ?? null) === true
        && ($manifest['runtime'] ?? null) === ['kind' => 'internal_task']
        && ($manifest['gateway'] ?? null) === [
            'invoke_path' => 'task_submit:pack_job',
            'methods' => ['POST'],
            'timeout_sec' => 180,
            'max_upload_mb' => 1,
            'require_service_enabled' => true,
        ]
        && ($manifest['runner_build'] ?? null) === [
            'context' => 'service',
            'dockerfile' => 'Dockerfile',
            'image' => '3waaihub/edge-tts:0.2.0',
        ], 'Edge TTS must publish its controlled Task 2 runner build metadata');
    foreach (['Dockerfile', 'edge-tts-entrypoint.sh', 'synthesize.py', 'test_egress_firewall.sh', 'test_synthesize.py'] as $file) {
        $path = HUB_ROOT . '/packs/edge-tts/service/' . $file;
        hub_test_assert(is_file($path), 'Edge TTS runner asset must be present: ' . $file);
    }
    foreach (['edge-tts-entrypoint.sh', 'synthesize.py', 'test_egress_firewall.sh', 'test_synthesize.py'] as $file) {
        $path = HUB_ROOT . '/packs/edge-tts/service/' . $file;
        hub_test_assert((fileperms($path) & 0777) === 0755, 'Edge TTS runnable asset must use mode 0755: ' . $file);
    }
    hub_test_assert(hub_pack_container_runner_build_contract($manifest, HUB_ROOT . '/packs/edge-tts') === [
        'image' => '3waaihub/edge-tts:0.2.0',
        'context' => HUB_ROOT . '/packs/edge-tts/service',
        'dockerfile' => HUB_ROOT . '/packs/edge-tts/service/Dockerfile',
    ], 'Edge TTS runner build must use the fixed service-directory context');
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/edge-tts/service/Dockerfile');
    foreach (['FROM python:3.13-slim-bookworm', 'edge-tts==7.2.6', 'COPY edge-tts-entrypoint.sh synthesize.py test_egress_firewall.sh test_synthesize.py ./', 'python3 -m unittest -v test_synthesize.py'] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'Edge TTS Dockerfile must pin and offline-test its runner: ' . $needle);
    }
    hub_test_assert(!str_contains($dockerfile, 'mawk'), 'Edge TTS Dockerfile must not install unused mawk');
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
                'enum' => ['zh-TW-HsiaoChenNeural', 'zh-TW-HsiaoYuNeural', 'zh-TW-YunJheNeural', 'en-US-EmmaMultilingualNeural', 'en-US-AndrewMultilingualNeural'],
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
            'image' => '3waaihub/edge-tts:0.2.0',
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
    $result = hub_run_command([HUB_ROOT . '/packs/edge-tts/service/test_egress_firewall.sh'], 20);
    hub_test_assert(($result['exit_code'] ?? 1) === 0 && ($result['stdout'] ?? '') === 'test_egress_firewall: ok',
        'Edge TTS firewall test must execute provider-only TCP 443, DNS removal, terminal DROP, and forced-failure sentinel checks: ' . ($result['output'] ?? ''));
});

hub_test('Edge TTS ready route still requires the administrator enable gate', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'edge-tts', ['idempotent' => true]);

    hub_test_assert((hub_pack_job_async_routes()['edge_tts'] ?? null) === [
        'pack_id' => 'edge-tts',
        'job' => 'synthesize',
        'accelerator' => 'cpu',
    ], 'Edge TTS must be registered as the fixed CPU async route');
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
    hub_test_assert(is_array($edgeTts) && ($edgeTts['mode'] ?? null) === 'edge_tts'
        && $subtitleField === [
            'name' => 'include_subtitles',
            'type' => 'boolean',
            'required' => false,
            'default' => false,
        ], 'ready enabled Edge TTS must publish its boolean subtitle input in the public API');
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
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/edge-tts:0.2.0'],
        ['docker', 'build', '--tag', '3waaihub/edge-tts:0.2.0', '--file', HUB_ROOT . '/packs/edge-tts/service/Dockerfile', HUB_ROOT . '/packs/edge-tts/service'],
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/edge-tts:0.2.0'],
    ] && ($installed['service']['install_status'] ?? '') === 'installed', 'Edge TTS runner must build from its Pack-controlled context and be verified before installation');
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
                'text' => ['max_bytes' => 524288],
            ],
            [
                'type' => 'subtitle_srt',
                'path' => 'subtitle.srt',
                'mime_types' => ['text/plain', 'application/x-subrip', 'text/x-subrip', 'text/srt'],
                'max_bytes' => 524288,
                'when' => ['input' => 'include_subtitles', 'equals' => true],
                'text' => ['max_bytes' => 524288],
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
    hub_test_assert(is_file($packPath) && is_file($smokePath), 'Edge TTS Pack and real-smoke documentation must exist');
    $pack = (string)file_get_contents($packPath);
    $smoke = (string)file_get_contents($smokePath);

    foreach ([
        'edge_tts',
        'speech.platform.bing.com',
        "Microsoft Edge's online speech service",
        'Do not submit confidential text',
        'GPU is not used',
        'include_subtitles',
        'subtitle.vtt',
        'subtitle.srt',
        'speech_timeline.json',
        'contain the submitted text',
    ] as $needle) {
        hub_test_assert(str_contains($root, $needle) && str_contains($pack, $needle), 'Edge TTS root and Pack documentation must state: ' . $needle);
    }
    hub_test_assert(str_contains($pack, '## Captions And Speech Timeline') && !str_contains($pack, '## Deferred V2'),
        'Edge TTS Pack documentation must describe active captions rather than deferred V2');
    foreach ([
        'admin/packs.php',
        'php scripts/command_worker.php --limit=5',
        'php scripts/task_worker.php --limit=1',
        'api.php?mode=edge_tts',
        'task_status',
        'task_result',
        'generated_audio',
        'task_artifacts_ack',
        '3waaihub/edge-tts:0.2.0',
        'include_subtitles',
        'subtitle.vtt',
        'subtitle.srt',
        'speech_timeline.json',
        'ffprobe',
        'sha256',
        'runtime_resource_leases',
        'gpu:0',
        'AIHUB_EDGE_TTS_TOKEN',
    ] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'Edge TTS real smoke must cover: ' . $needle);
    }
    foreach (['upstream_unavailable', 'edge_tts_timeout', 'edge_tts_failed', 'artifact_write_failed'] as $code) {
        hub_test_assert(str_contains($pack, $code), 'Edge TTS Pack documentation must describe bounded error code: ' . $code);
    }
    hub_test_assert(!str_contains($smoke, 'Bearer <TOKEN>'), 'Edge TTS real smoke must keep its token in the environment');
});
