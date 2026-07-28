<?php
declare(strict_types=1);

function hub_test_web_capture_payload(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    hub_test_assert(is_array($payload), 'web capture gateway response must be JSON');

    return $payload;
}

function hub_test_web_capture_request(PDO $db, string $token, array $post = [], string $method = 'POST'): array
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.71';
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=web_capture';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_SERVER['HTTP_HOST'] = 'hub.test';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/api.php';
    $_SERVER['CONTENT_LENGTH'] = (string)strlen(http_build_query($post));
    $_POST = $post;
    $_GET = [];
    $_FILES = [];

    return hub_gateway_dispatch($db, 'web_capture');
}

function hub_test_web_capture_isolate(callable $fn): void
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

hub_test('web capture route is immutable and CPU backed', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);

    hub_test_assert(hub_pack_job_async_routes() === [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup', 'accelerator' => 'gpu'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe', 'accelerator' => 'gpu'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize', 'accelerator' => 'gpu'],
        'web_capture' => ['pack_id' => 'web-screenshot', 'job' => 'capture', 'accelerator' => 'cpu'],
    ], 'Pack job routes must be fixed and include the expected accelerator');

    $route = hub_resolve_pack_job_async_route($db, 'web_capture');
    hub_test_assert(($route['requested_mode'] ?? '') === 'web_capture'
        && ($route['pack_id'] ?? '') === 'web-screenshot'
        && ($route['pack_version'] ?? '') === (string)$installed['service']['pack_version']
        && ($route['job'] ?? '') === 'capture'
        && ($route['runtime_mode'] ?? '') === 'job'
        && ($route['accelerator'] ?? '') === 'cpu'
        && ($route['runner']['accelerator'] ?? '') === 'cpu', 'web capture must persist its fixed CPU Pack route');
    hub_test_assert(hub_audio_async_routes() === [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'],
    ] && !hub_is_audio_async_mode('web_capture'), 'audio compatibility routes must remain audio-only');
});

hub_test('web capture admission rejects caller controls and unsafe URLs', function (): void {
    hub_test_web_capture_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Web Capture Owner');
        $token = hub_create_api_token($db, $memberId, 'web capture token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'web_capture', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        foreach (['pack_id' => 'other-pack', 'entrypoint' => '/tmp/client', 'command' => 'client-command', 'callback_url' => 'https://8.8.8.8/callback', 'source_artifact_id' => '1'] as $key => $value) {
            $response = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://8.8.8.8/capture', $key => $value]);
            hub_test_assert($response['status'] === 400, $key . ' must not be accepted from a web capture client');
        }

        foreach (['file:///etc/passwd', 'http://user:pass@8.8.8.8/', 'http://localhost/', 'http://127.0.0.1/', 'http://8.8.8.8:8080/', 'http://127.0.0.1.nip.io/'] as $url) {
            $response = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => $url]);
            hub_test_assert($response['status'] === 400 && (hub_test_web_capture_payload($response)['error'] ?? '') === 'invalid_request', 'unsafe web capture URL must be rejected: ' . $url);
        }

        $created = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'HTTPS://8.8.8.8./capture']);
        $payload = hub_test_web_capture_payload($created);
        $task = hub_get_task($db, (int)($payload['task_id'] ?? 0));
        hub_test_assert($created['status'] === 200 && ($task['input'] ?? null) === ['url' => 'https://8.8.8.8/capture']
            && ($task['accelerator'] ?? '') === 'cpu' && empty($task['source_artifact_id']), 'web capture must persist only the normalized URL on its fixed CPU task');
    });
});

hub_test('web capture public API contract has no source fields', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
    hub_set_service_enabled($db, 'web_capture', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $services = hub_public_api_services($db, static fn (array $service): bool => true);
    $webCapture = null;
    foreach ($services as $service) {
        if (($service['mode'] ?? '') === 'web_capture') {
            $webCapture = $service;
            break;
        }
    }
    hub_test_assert(is_array($webCapture), 'web capture must be listed in public API services');
    $names = array_column((array)($webCapture['input_fields'] ?? []), 'name');
    hub_test_assert(in_array('url', $names, true) && !in_array('file', $names, true) && !in_array('source_artifact_id', $names, true), 'source-free Pack jobs must not document file or source artifact fields');
});
