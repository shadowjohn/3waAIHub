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

hub_test('Edge TTS Pack publishes the exact CPU-only async contract', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('edge-tts');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'Edge TTS Pack must validate without a runner build context');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');

    hub_test_assert(($manifest['id'] ?? null) === 'edge-tts'
        && ($manifest['version'] ?? null) === '0.1.0'
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
        ]
        && !array_key_exists('runner_build', $manifest), 'Edge TTS must remain an internal async Pack without Task 2 runner_build metadata');
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
        && ($job['input_fields'] ?? null) === ['text', 'voice', 'rate', 'volume', 'pitch']
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
        ]
        && ($job['runner'] ?? null) === [
            'image' => '3waaihub/edge-tts:0.1.0',
            'entrypoint' => ['/app/edge-tts-entrypoint.sh', '/app/synthesize.py'],
            'args' => [],
            'output_dir' => 'output',
            'accelerator' => 'cpu',
            'required_vram_mb' => 0,
            'timeout_seconds' => 150,
            'network_profile' => 'public_egress',
            'executor' => 'container',
        ], 'Edge TTS must expose only the pinned CPU runner and typed synthesis controls');

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
        'version' => '0.1.0',
        'category' => 'audio',
        'description' => 'Experimental CPU-only text-to-speech adapter for Microsoft Edge\'s online speech service.',
        'path' => 'packs/edge-tts',
        'featured' => true,
    ], 'Edge TTS must have the approved featured catalog entry');
});

hub_test('Edge TTS route normalizes only its declared synthesis input', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'edge-tts', ['idempotent' => true]);

    hub_test_assert((hub_pack_job_async_routes()['edge_tts'] ?? null) === [
        'pack_id' => 'edge-tts',
        'job' => 'synthesize',
        'accelerator' => 'cpu',
    ], 'Edge TTS must be registered as the fixed CPU async route');
    hub_test_assert(in_array('edge_tts', hub_playground_supported_modes(), true), 'Edge TTS must be selectable in the customer playground');

    $route = hub_resolve_pack_job_async_route($db, 'edge_tts');
    hub_test_assert(($route['pack_id'] ?? null) === 'edge-tts'
        && ($route['pack_version'] ?? null) === ($installed['service']['pack_version'] ?? null)
        && ($route['job'] ?? null) === 'synthesize'
        && ($route['accelerator'] ?? null) === 'cpu'
        && ($route['runner']['accelerator'] ?? null) === 'cpu'
        && ($route['runner']['required_vram_mb'] ?? null) === 0
        && ($route['runner']['timeout_seconds'] ?? null) === 150
        && ($route['runner']['network_profile'] ?? null) === 'public_egress'
        && ($route['runner']['executor'] ?? null) === 'container', 'Edge TTS route must resolve its immutable CPU contract');
    hub_test_assert(hub_pack_job_normalize_request_input(['text' => 'Taiwan Edge TTS'], $route) === [
        'text' => 'Taiwan Edge TTS',
        'voice' => 'zh-TW-HsiaoChenNeural',
        'rate' => '+0%',
        'volume' => '+0%',
        'pitch' => '+0Hz',
    ], 'Edge TTS must persist the manifest defaults with the supplied text');
    foreach ([
        [],
        ['text' => 'x', 'voice' => 'unknown'],
        ['text' => 'x', 'rate' => '0%'],
        ['text' => 'x', 'volume' => '+75%'],
        ['text' => 'x', 'pitch' => '+10Hz'],
        ['text' => 'x', 'source_artifact_id' => 1],
        ['text' => 'x', 'callback_url' => 'https://example.test/callback'],
    ] as $input) {
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input($input, $route)), 'Edge TTS must reject invalid undeclared local input');
    }
});

hub_test('Edge TTS admission requires mode permission and queues only valid source-free requests', function (): void {
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
        $accepted = hub_test_edge_tts_request($db, (string)$token['plain_token'], ['text' => 'Queued']);
        $payload = hub_test_edge_tts_payload($accepted);
        $task = hub_get_task($db, (int)($payload['task_id'] ?? 0));
        hub_test_assert($accepted['status'] === 200 && ($payload['status'] ?? null) === 'queued'
            && is_array($task) && ($task['requested_mode'] ?? null) === 'edge_tts'
            && ($task['pack_id'] ?? null) === 'edge-tts'
            && ($task['job'] ?? null) === 'synthesize'
            && ($task['accelerator'] ?? null) === 'cpu'
            && ($task['input'] ?? null) === [
                'text' => 'Queued',
                'voice' => 'zh-TW-HsiaoChenNeural',
                'rate' => '+0%',
                'volume' => '+0%',
                'pitch' => '+0Hz',
            ], 'a permitted Edge TTS request must queue only normalized manifest input');

        foreach ([
            ['voice' => 'zh-TW-HsiaoChenNeural'],
            ['text' => 'bad enum', 'voice' => 'unknown'],
            ['text' => 'unknown input', 'model' => 'anything'],
            ['text' => 'source rejected', 'source_artifact_id' => '1'],
            ['text' => 'callback rejected', 'callback_url' => 'https://example.test/callback'],
        ] as $input) {
            $response = hub_test_edge_tts_request($db, (string)$token['plain_token'], $input);
            hub_test_assert($response['status'] === 400, 'Edge TTS must reject invalid, unknown, source, and direct callback input');
        }
    });
});

hub_test('Edge TTS public API documents only its source-free synthesis controls', function (): void {
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

    hub_test_assert(is_array($edgeTts), 'enabled running Edge TTS must be documented in the public API');
    $fields = (array)($edgeTts['input_fields'] ?? []);
    $declared = array_values(array_filter($fields, static fn (array $field): bool => !in_array($field['name'] ?? '', ['callback', 'callback_target'], true)));
    hub_test_assert(array_column($declared, 'name') === ['text', 'voice', 'rate', 'volume', 'pitch']
        && !in_array('file', array_column($fields, 'name'), true)
        && !in_array('source_artifact_id', array_column($fields, 'name'), true), 'Edge TTS public API must expose its declared synthesis controls without source fields');
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
        ],
    ], 'Edge TTS must require the fixed MP3 and synthesis metadata artifacts');
});
