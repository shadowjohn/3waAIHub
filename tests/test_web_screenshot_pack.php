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

hub_test('web capture allowlist is normalized, bounded, and audited', function (): void {
    $db = hub_test_reset_db();
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === implode("\n", [
        '3wa.tw', 'fmg.wra.gov.tw', 'fmgb.wra.gov.tw', 'focusit.tw',
        'focusit.com.tw', 'gis.tw', 'wmts.nlsc.gov.tw', 'maps.nlsc.gov.tw',
        'mts1.google.com', 'api.maptiler.com', 'tile.openstreetmap.org',
    ]), 'web capture defaults must seed the approved hosts');

    $hosts = hub_web_capture_save_allowed_hosts($db, 'admin', " 3WA.TW.\nfocusit.tw\n3wa.tw\n");
    hub_test_assert($hosts === ['3wa.tw', 'focusit.tw'], 'save must lower-case, trim, and deduplicate hosts');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === "3wa.tw\nfocusit.tw", 'save must persist canonical newline text');
    hub_test_assert($db->query("SELECT details FROM audit_logs WHERE action = 'web_capture_allowlist_updated' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'added=0 removed=9 total=2', 'save must write a bounded allowlist audit summary');

    $before = hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS');
    try {
        hub_web_capture_save_allowed_hosts($db, 'admin', "3wa.tw\nhttps://bad.example/");
        throw new RuntimeException('invalid allowlist line must throw');
    } catch (InvalidArgumentException $e) {
        hub_test_assert($e->getMessage() === 'web_capture_allowed_hosts_invalid_line:2', 'invalid entry must identify its line');
    }
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === $before, 'invalid input must not change the saved list');
    hub_test_assert(hub_web_capture_parse_allowed_hosts("\n\n") === [], 'an empty allowlist must remain an explicit disable switch');
    hub_test_assert(hub_test_throws(static fn (): array => hub_web_capture_parse_allowed_hosts(implode("\n", array_map(static fn (int $i): string => "h{$i}.example", range(1, 129))))), 'more than 128 hosts must be rejected');

    $cases = json_decode((string)file_get_contents(HUB_ROOT . '/packs/web-screenshot/service/url_policy_cases.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach ($cases['valid_hosts'] as $host) {
        hub_test_assert(hub_web_capture_parse_allowed_hosts($host) === [$host], 'fixture valid host must parse: ' . $host);
    }
    foreach ($cases['invalid_hosts'] as $host) {
        hub_test_assert(hub_test_throws(static fn (): array => hub_web_capture_parse_allowed_hosts($host)), 'fixture invalid host must be rejected: ' . $host);
    }
    foreach ($cases['canonical_hosts'] as $case) {
        hub_test_assert(hub_web_capture_parse_allowed_hosts($case['input']) === [$case['output']], 'fixture canonical host must normalize');
    }
    $settingsSource = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    hub_test_assert(str_contains($settingsSource, '<textarea name="AIHUB_WEB_CAPTURE_ALLOWED_HOSTS"') && str_contains($settingsSource, 'hub_web_capture_save_allowed_hosts('), 'API settings must save the web capture allowlist textarea');
});

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
        && ($route['runner']['accelerator'] ?? '') === 'cpu'
        && ($route['runner']['required_vram_mb'] ?? null) === 0
        && ($route['runner']['timeout_seconds'] ?? null) === 135
        && ($route['runner']['network_profile'] ?? null) === 'public_egress'
        && ($route['input_fields'] ?? []) === ['url', 'width', 'height', 'delay_seconds', 'timeout_seconds', 'javascript', 'crop_x', 'crop_y', 'crop_width', 'crop_height'], 'web capture must persist its fixed CPU Pack route and declared inputs');
    hub_test_assert(hub_audio_async_routes() === [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize'],
    ] && !hub_is_audio_async_mode('web_capture'), 'audio compatibility routes must remain audio-only');
});

hub_test('web capture Pack and README publish the allowlist bridge contract', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('web-screenshot');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'Web Screenshot Pack must validate');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'capture');
    hub_test_assert(is_array($job), 'Web Screenshot capture job contract missing');
    hub_test_assert(($manifest['version'] ?? '') === '0.1.2'
        && ($manifest['runner_build']['image'] ?? '') === '3waaihub/web-screenshot:0.1.2'
        && ($job['runner']['image'] ?? '') === '3waaihub/web-screenshot:0.1.2'
        && ($job['runner']['network_profile'] ?? '') === 'public_egress', 'web capture must retain the public-egress 0.1.2 Pack image contract');
    $installed = hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
    $route = hub_resolve_pack_job_async_route($db, 'web_capture');
    hub_test_assert(($installed['service']['pack_version'] ?? null) === '0.1.2'
        && ($route['runner']['network_profile'] ?? null) === 'public_egress', 'web capture must install as the public-egress 0.1.2 Pack');

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    $section = strstr($readme, '### Web Screenshot allowed hosts');
    hub_test_assert(is_string($section), 'README must document Web Screenshot allowed hosts');
    $section = strstr($section, "\n## ", true);
    hub_test_assert(is_string($section), 'Web Screenshot README section must end before the next top-level section');
    foreach (['container-local fail-closed egress firewall', 'NET_ADMIN', 'non-root user', 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', '設定 → API 與安全', "Docker's existing `bridge` network"] as $needle) {
        hub_test_assert(str_contains($section, $needle), 'Web Screenshot README section missing ' . $needle);
    }
    hub_test_assert(!str_contains($section, 'scripts/install_capture_egress_network.sh --check'), 'Web Screenshot README section must not require the obsolete egress installer');
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

        hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', '3wa.tw');
        $blocked = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://8.8.8.8/capture']);
        hub_test_assert($blocked['status'] === 400 && (hub_test_web_capture_payload($blocked)['error'] ?? '') === 'url_not_allowed', 'unlisted initial host must return the normal 400 error');

        $normalized = hub_web_capture_validate_input($db, ['url' => 'HTTPS://3WA.TW./capture'], static fn (string $host): array => ['93.184.216.34']);
        hub_test_assert($normalized['url'] === 'https://3wa.tw/capture', 'allowed hostname must normalize before enqueue');

        $forged = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://3wa.tw/', 'allowed_hosts' => 'evil.example']);
        hub_test_assert($forged['status'] === 400, 'client must not inject the runner allowlist');
    });
});

hub_test('web capture checks the current allowlist before starting execution', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
    hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', '3wa.tw');
    $route = hub_resolve_pack_job_async_route($db, 'web_capture');
    $input = hub_web_capture_validate_input($db, ['url' => 'https://3wa.tw/capture'], static fn (string $host): array => ['93.184.216.34']);
    $taskId = hub_enqueue_owned_pack_job($db, $route, $input, 1, null, '203.0.113.71');
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    hub_test_assert(is_array($task), 'valid web capture task must be claimed before the allowlist changes');

    hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', '');
    $started = 0;
    $result = hub_run_pack_job_task($db, $task, [
        'executor' => static function () use (&$started): array {
            $started++;
            return ['exit_code' => 1, 'cleanup' => hub_pack_job_no_work_cleanup()];
        },
    ]);
    $latest = hub_get_task($db, $taskId);
    $run = $db->query('SELECT container_id, image_name, attempt_no FROM runtime_runs WHERE task_id = ' . $taskId)->fetch();
    hub_test_assert(($result['status'] ?? '') === 'failed' && ($latest['error_code'] ?? '') === 'url_not_allowed' && $started === 0, 'a removed web capture host must fail before its executor starts');
    hub_test_assert(!file_exists(hub_task_result_dir($taskId)) && !is_file(hub_task_result_dir($taskId) . '/workspace/input/request.json') && ($run['container_id'] ?? null) === null && ($run['image_name'] ?? null) === null && (int)($run['attempt_no'] ?? -1) === 0, 'a removed web capture host must not create a workspace, write a request, or record container start metadata');
});

hub_test('web capture admission rejects partial crops and impossible deadlines', function (): void {
    hub_test_web_capture_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Web Capture Contract Owner');
        $token = hub_create_api_token($db, $memberId, 'web capture contract token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'web_capture', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $route = hub_resolve_pack_job_async_route($db, 'web_capture');
        $cropFields = ['crop_x', 'crop_y', 'crop_width', 'crop_height'];
        hub_test_assert(array_diff($cropFields, (array)($route['input_fields'] ?? [])) === []
            && ($route['request_schema']['timeout_seconds']['gt_field'] ?? null) === 'delay_seconds', 'crop and deadline test inputs must be declared before admission is tested');

        foreach ([
            ['crop_x' => '0'],
            ['crop_x' => '0', 'crop_y' => '0', 'crop_width' => '1'],
            ['delay_seconds' => '30', 'timeout_seconds' => '30'],
            ['delay_seconds' => '30', 'timeout_seconds' => '20'],
        ] as $input) {
            $response = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://8.8.8.8/capture'] + $input);
            hub_test_assert($response['status'] === 400 && (hub_test_web_capture_payload($response)['error'] ?? '') === 'invalid_request', 'partial crop and impossible deadline requests must fail contract admission');
        }
    });
});

hub_test('web capture crop artifact requires every crop input', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
    $route = hub_resolve_pack_job_async_route($db, 'web_capture');
    $artifacts = $route['artifact_contract']['artifacts'] ?? [];
    $cropArtifact = null;
    foreach ($artifacts as $artifact) {
        if (($artifact['type'] ?? '') === 'crop_png') {
            $cropArtifact = $artifact;
            break;
        }
    }

    hub_test_assert(is_array($cropArtifact)
        && ($cropArtifact['when'] ?? null) === ['all_present' => ['crop_x', 'crop_y', 'crop_width', 'crop_height']], 'crop artifact must use the declared all-present condition');
    $imageArtifacts = array_values(array_filter($artifacts, static fn (array $artifact): bool => isset($artifact['image'])));
    hub_test_assert(count($imageArtifacts) === 2, 'web capture must declare complete and optional crop PNG outputs');
    foreach ($imageArtifacts as $artifact) {
        hub_test_assert(($artifact['image'] ?? null) === [
            'format' => 'png',
            'max_width' => 2560,
            'max_height' => 30000,
            'max_pixels' => 60000000,
        ], 'web capture artifacts must declare the bounded PNG output contract');
    }
    $reportArtifact = array_values(array_filter($artifacts, static fn (array $artifact): bool => ($artifact['type'] ?? '') === 'capture_report'))[0] ?? null;
    hub_test_assert(is_array($reportArtifact)
        && ($reportArtifact['path'] ?? null) === 'capture_report.json'
        && ($reportArtifact['json']['required_keys'] ?? null) === ['requested_url', 'final_url', 'http_status', 'viewport', 'image', 'delay_seconds', 'timeout_seconds', 'javascript_executed', 'crop', 'elapsed_seconds', 'playwright_version', 'warnings'], 'web capture must declare its redacted capture report');
    hub_test_assert(!hub_pack_job_artifact_is_expected($cropArtifact, ['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1]), 'crop artifact must not be required for a partial crop');
    hub_test_assert(hub_pack_job_artifact_is_expected($cropArtifact, ['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 1, 'crop_height' => 1]), 'crop artifact must be required for a complete crop');

    $invalid = hub_get_pack('web-screenshot')['manifest'];
    $invalid['async_jobs'][0]['output']['artifacts'][1]['when'] = ['all_present' => ['crop_x', 'unknown_field']];
    hub_test_assert(hub_pack_async_job_contract($invalid, 'capture') === null, 'all-present artifact fields must be declared request inputs');
});

hub_test('web capture Pack rejects a gt_field string peer', function (): void {
    $invalid = hub_get_pack('web-screenshot')['manifest'];
    $invalid['async_jobs'][0]['input']['request_schema']['timeout_seconds']['gt_field'] = 'javascript';

    hub_test_assert(hub_pack_async_job_contract($invalid, 'capture') === null, 'gt_field must reference an integer request field');
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
