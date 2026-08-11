<?php
declare(strict_types=1);

function hub_test_image_tools_service(PDO $db): array
{
    $installed = hub_install_pack($db, 'image-tools', [
        'service_key' => 'image-tools-gateway',
        'port_mode' => 'manual',
        'local_port' => 18113,
    ]);
    hub_set_service_enabled($db, 'image-tools', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');

    return $installed['service'];
}

function hub_test_image_tools_dispatch(PDO $db, array $post, array $query = [], array $files = [], ?callable $requester = null): array
{
    $_FILES = $files;

    return hub_gateway_dispatch($db, 'image-tools', $requester, [
        'method' => 'POST',
        'client_ip' => '127.0.0.1',
        'request_uri' => '/3waAIHub/api.php?mode=image-tools',
        'query' => $query,
        'post' => $post,
    ]);
}

function hub_test_image_tools_error(array $response): string
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    hub_test_assert(is_array($payload), 'image-tools gateway error must be JSON');

    return (string)($payload['error'] ?? '');
}

function hub_test_image_tools_fixture(string $bytes = 'image-tools fixture'): string
{
    $path = tempnam(sys_get_temp_dir(), '3waaihub_image_tools_');
    if ($path === false || file_put_contents($path, $bytes, LOCK_EX) === false || !chmod($path, 0600)) {
        throw new RuntimeException('Cannot create image-tools upload fixture.');
    }

    return $path;
}

hub_test('image-tools gateway injects query operations and stages Base64 without forwarding it', function (): void {
    $db = hub_test_reset_db();
    hub_test_image_tools_service($db);
    $_POST = ['sentinel' => 'keep'];
    $stagedPaths = [];
    $calls = 0;

    foreach ([
        base64_encode('raw Base64 source'),
        'data:image/png;base64,' . base64_encode('data URI source'),
    ] as $source) {
        $response = hub_test_image_tools_dispatch(
            $db,
            ['base64_string' => $source],
            ['mode' => 'image-tools', 'operation' => 'upscale'],
            [],
            static function (array $service, int $timeoutSec) use (&$stagedPaths, &$calls, $source): array {
                $calls++;
                hub_test_assert(str_ends_with((string)$service['internal_url'], '/process/image'), 'image-tools must proxy to /process/image');
                hub_test_assert($timeoutSec === 900, 'image-tools must retain the Pack gateway timeout');
                hub_test_assert($_POST === [
                    'operation' => 'upscale',
                    'backend' => 'auto',
                    'model' => 'realesrgan-x4plus',
                ], 'image-tools must forward only normalized fields');
                hub_test_assert(!str_contains(implode('', $_POST), $source), 'raw Base64 must never reach the proxy post fields');
                hub_test_assert(array_keys($_FILES) === ['image'], 'Base64 must become one image upload');
                $file = $_FILES['image'];
                hub_test_assert(array_keys($file) === ['name', 'type', 'tmp_name', 'error', 'size'], 'staged Base64 upload must retain only safe metadata');
                hub_test_assert($file['name'] === 'source.bin' && $file['type'] === 'application/octet-stream' && $file['error'] === UPLOAD_ERR_OK, 'staged Base64 upload metadata must be fixed');
                hub_test_assert(is_file($file['tmp_name']) && (fileperms($file['tmp_name']) & 0777) === 0600, 'staged Base64 file must be private');
                $stagedPaths[] = $file['tmp_name'];

                return hub_gateway_json(200, ['ok' => true]);
            }
        );
        hub_test_assert($response['status'] === 200, 'valid Base64 source must proxy');
    }

    hub_test_assert($calls === 2 && $_POST === ['sentinel' => 'keep'] && $_FILES === [], 'image-tools proxy scope must restore request globals');
    foreach ($stagedPaths as $path) {
        hub_test_assert(!is_file($path), 'staged Base64 file must be removed after proxying');
    }
});

hub_test('image-tools gateway enforces exact query and form duplicates', function (): void {
    $db = hub_test_reset_db();
    hub_test_image_tools_service($db);
    $source = base64_encode('duplicate fixture');

    foreach ([
        'operation' => ['query' => 'upscale', 'form' => 'upscale_task'],
        'backend' => ['query' => 'cpu', 'form' => 'cuda'],
        'model' => ['query' => 'realesrgan-x4plus', 'form' => 'realesrgan-x4plus-anime'],
    ] as $field => $case) {
        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale', 'base64_string' => $source, $field => $case['form']],
            ['mode' => 'image-tools', $field => $case['query']],
            [],
            static fn (): array => throw new RuntimeException('mismatched duplicate must not proxy')
        );
        hub_test_assert($response['status'] === 400 && hub_test_image_tools_error($response) === 'invalid_request', $field . ' duplicate must match byte-for-byte');
    }

    $response = hub_test_image_tools_dispatch(
        $db,
        [
            'operation' => 'upscale',
            'backend' => 'cpu',
            'model' => 'realesrgan-x4plus-anime',
            'base64_string' => $source,
        ],
        [
            'mode' => 'image-tools',
            'operation' => 'upscale',
            'backend' => 'cpu',
            'model' => 'realesrgan-x4plus-anime',
        ],
        [],
        static function (): array {
            hub_test_assert($_POST === [
                'operation' => 'upscale',
                'backend' => 'cpu',
                'model' => 'realesrgan-x4plus-anime',
            ], 'matching query and form values must be normalized once');

            return hub_gateway_json(200, ['ok' => true]);
        }
    );
    hub_test_assert($response['status'] === 200, 'matching duplicates must proxy');
});

hub_test('image-tools gateway rejects untrusted field shapes and upload metadata', function (): void {
    $db = hub_test_reset_db();
    hub_test_image_tools_service($db);
    $source = base64_encode('validation fixture');

    foreach ([
        [['operation' => 'upscale', 'base64_string' => $source, 'unknown' => 'x'], []],
        [['operation' => ['upscale'], 'base64_string' => $source], []],
        [['operation' => "upscale\x00", 'base64_string' => $source], []],
        [['operation' => 'upscale', 'model' => str_repeat('a', 65), 'base64_string' => $source], []],
        [['operation' => 'upscale', 'base64_string' => $source], ['mode' => 'image-tools', 'unknown' => 'x']],
        [['operation' => 'upscale', 'base64_string' => $source], ['mode' => 'image_tools']],
    ] as [$post, $query]) {
        $response = hub_test_image_tools_dispatch(
            $db,
            $post,
            $query,
            [],
            static fn (): array => throw new RuntimeException('invalid field request must not proxy')
        );
        hub_test_assert($response['status'] === 400, 'unknown, array, control, overlong, and invalid mode fields must reject');
    }

    $fixture = hub_test_image_tools_fixture();
    try {
        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale'],
            [],
            ['image' => ['name' => '../source.png', 'type' => 'image/png', 'tmp_name' => $fixture, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fixture)]],
            static fn (): array => throw new RuntimeException('path-like filename must not proxy')
        );
        hub_test_assert($response['status'] === 400 && hub_test_image_tools_error($response) === 'invalid_request', 'path-like filenames must reject');

        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale'],
            [],
            ['image' => ['name' => ['source.png'], 'type' => 'image/png', 'tmp_name' => [$fixture], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($fixture)]]],
            static fn (): array => throw new RuntimeException('nested upload must not proxy')
        );
        hub_test_assert($response['status'] === 400 && hub_test_image_tools_error($response) === 'invalid_request', 'nested image upload must reject');
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
});

hub_test('image-tools gateway requires exactly one source and sanitizes file uploads', function (): void {
    $db = hub_test_reset_db();
    hub_test_image_tools_service($db);
    $source = base64_encode('source fixture');
    $missing = hub_test_image_tools_dispatch(
        $db,
        ['operation' => 'upscale'],
        [],
        [],
        static fn (): array => throw new RuntimeException('missing source must not proxy')
    );
    hub_test_assert($missing['status'] === 400 && hub_test_image_tools_error($missing) === 'file_required', 'missing image source must return file_required');

    $fixture = hub_test_image_tools_fixture('uploaded source');
    try {
        $upload = ['image' => ['name' => 'source.png', 'type' => 'image/png', 'tmp_name' => $fixture, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fixture)]];
        $ambiguous = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale', 'base64_string' => $source],
            [],
            $upload,
            static fn (): array => throw new RuntimeException('ambiguous source must not proxy')
        );
        hub_test_assert($ambiguous['status'] === 400 && hub_test_image_tools_error($ambiguous) === 'source_ambiguous', 'both image sources must return source_ambiguous');

        $calls = 0;
        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale', 'backend' => 'cpu', 'model' => 'realesr-animevideov3-x2'],
            [],
            $upload,
            static function () use (&$calls, $fixture): array {
                $calls++;
                hub_test_assert($_POST === [
                    'operation' => 'upscale',
                    'backend' => 'cpu',
                    'model' => 'realesr-animevideov3-x2',
                ], 'file upload must proxy only normalized form values');
                hub_test_assert($_FILES === ['image' => [
                    'name' => 'source.png',
                    'type' => 'application/octet-stream',
                    'tmp_name' => $fixture,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($fixture),
                ]], 'file upload must retain only safe metadata and be forwarded once');

                return hub_gateway_json(200, ['ok' => true]);
            }
        );
        hub_test_assert($response['status'] === 200 && $calls === 1, 'one valid file upload must proxy exactly once');
    } finally {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
});

hub_test('image-tools gateway rejects invalid and oversized Base64 without proxying', function (): void {
    $db = hub_test_reset_db();
    hub_test_image_tools_service($db);

    foreach (['AAAA=', 'data:image/gif;base64,QUFBQQ==', "QUFB\x00QQ=="] as $source) {
        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale', 'base64_string' => $source],
            [],
            [],
            static fn (): array => throw new RuntimeException('invalid Base64 must not proxy')
        );
        hub_test_assert($response['status'] === 400 && hub_test_image_tools_error($response) === 'invalid_base64', 'invalid Base64 must reject');
    }

    $encodedCap = 4 * (int)ceil((50 * 1024 * 1024) / 3);
    $oversized = str_repeat('A', $encodedCap + 1);
    try {
        $response = hub_test_image_tools_dispatch(
            $db,
            ['operation' => 'upscale', 'base64_string' => $oversized],
            [],
            [],
            static fn (): array => throw new RuntimeException('oversized Base64 must not proxy')
        );
        hub_test_assert($response['status'] === 400 && hub_test_image_tools_error($response) === 'invalid_base64', 'Base64 above the decoded-source cap must reject');
    } finally {
        unset($oversized);
    }
});

hub_test('image-tools gateway validates the configured backend and recognizes async operations', function (): void {
    $db = hub_test_reset_db();
    $service = hub_test_image_tools_service($db);
    $source = base64_encode('backend fixture');
    hub_service_settings_values($db, $service);
    $db->prepare("UPDATE service_settings SET value = 'cuda ' WHERE service_id = :service_id AND key = 'IMAGE_TOOLS_DEFAULT_BACKEND'")
        ->execute([':service_id' => (int)$service['id']]);

    $invalidBackend = hub_test_image_tools_dispatch(
        $db,
        ['operation' => 'upscale', 'base64_string' => $source],
        [],
        [],
        static fn (): array => throw new RuntimeException('invalid configured backend must not proxy')
    );
    hub_test_assert($invalidBackend['status'] === 400 && hub_test_image_tools_error($invalidBackend) === 'invalid_backend', 'configured default backend must validate exactly');

    $db->prepare("UPDATE service_settings SET value = 'auto' WHERE service_id = :service_id AND key = 'IMAGE_TOOLS_DEFAULT_BACKEND'")
        ->execute([':service_id' => (int)$service['id']]);
    $task = hub_test_image_tools_dispatch(
        $db,
        ['operation' => 'upscale_task', 'base64_string' => $source],
        [],
        [],
        static fn (): array => throw new RuntimeException('Task 2 must not dispatch async work')
    );
    hub_test_assert($task['status'] === 400 && hub_test_image_tools_error($task) === 'invalid_operation', 'validated upscale_task must retain the Task 2 routing placeholder');
});

hub_test('image-tools backend response header is canonical and rejects unsafe values', function (): void {
    $valid = hub_proxy_allowed_response_headers(
        "HTTP/1.1 200 OK\r\nX-3waAIHub-Model: realesrgan-x4plus\r\nX-3waAIHub-Backend: cuda\r\nX-3waAIHub-Device: cpu\r\nX-3waAIHub-Elapsed-Ms: 12\r\n",
        'image/png'
    );
    hub_test_assert($valid === [
        'Content-Type: image/png',
        'X-Content-Type-Options: nosniff',
        'X-3waAIHub-Model: realesrgan-x4plus',
        'X-3waAIHub-Device: cpu',
        'X-3waAIHub-Backend: cuda',
        'X-3waAIHub-Elapsed-Ms: 12',
    ], 'valid Backend must be canonical without changing Device behavior');

    $invalid = hub_proxy_allowed_response_headers(
        "HTTP/1.1 200 OK\r\nX-3waAIHub-Backend: CUDA\r\nX-3waAIHub-Backend: cpu\x00\r\n",
        'image/png'
    );
    hub_test_assert(!in_array('X-3waAIHub-Backend: CUDA', $invalid, true) && !array_filter($invalid, static fn (string $header): bool => str_starts_with($header, 'X-3waAIHub-Backend:')), 'invalid Backend values and controls must not escape');
});

hub_test('image-tools Pack declares the L1 upscaling contract', function (): void {
    $pack = hub_get_pack('image-tools');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-tools pack missing or invalid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['id'] ?? '') === 'image-tools', 'image-tools pack ID mismatch');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'image-tools must expose a sync API');
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L1-contract' && ($manifest['runtime_ready'] ?? true) === false, 'image-tools must remain an unready L1 contract');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'image-tools', 'image-tools public mode mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/process/image', 'image-tools invoke path mismatch');
    hub_test_assert(($manifest['gateway']['health_path'] ?? '') === '/health', 'image-tools health path mismatch');
    hub_test_assert(($manifest['gateway']['max_upload_mb'] ?? 0) === 70, 'image-tools gateway request cap mismatch');
    hub_test_assert(($manifest['queue']['max_concurrency'] ?? 0) === 1, 'image-tools must remain single concurrency');

    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(($contract['output']['content_type'] ?? '') === 'image/png', 'image-tools output must be PNG');
    hub_test_assert(($contract['output']['required_headers'] ?? []) === [
        'X-3waAIHub-Model',
        'X-3waAIHub-Backend',
        'X-3waAIHub-Elapsed-Ms',
        'X-3waAIHub-Width',
        'X-3waAIHub-Height',
    ], 'image-tools PNG response headers mismatch');
    hub_test_assert(array_column($contract['operations'] ?? [], 'operation') === ['upscale', 'upscale_task'], 'image-tools must expose only its two public operations');
    hub_test_assert(($contract['models'] ?? []) === [
        'realesrgan-x4plus',
        'realesrgan-x4plus-anime',
        'realesr-animevideov3-x2',
        'realesr-animevideov3-x3',
        'realesr-animevideov3-x4',
    ], 'image-tools model aliases mismatch');
    hub_test_assert(($contract['backends'] ?? []) === ['auto', 'cuda', 'cpu'], 'image-tools backend enum mismatch');
    hub_test_assert(($contract['limits'] ?? []) === [
        'max_decoded_mb' => 50,
        'max_axis_px' => 8192,
        'max_sync_pixels' => 4000000,
        'max_async_pixels' => 10000000,
        'max_output_pixels' => 64000000,
        'accepted_formats' => ['JPEG', 'JPG', 'PNG', 'WEBP', 'BMP'],
    ], 'image-tools limits mismatch');
    hub_test_assert(($contract['errors'] ?? []) === [
        'file_required',
        'source_ambiguous',
        'invalid_base64',
        'unsupported_media_type',
        'invalid_image',
        'invalid_operation',
        'invalid_model',
        'invalid_backend',
        'invalid_request',
        'backend_unavailable',
        'model_not_present',
        'model_load_failed',
        'payload_too_large',
        'runtime_not_ready',
        'inference_failed',
    ], 'image-tools error contract mismatch');
    hub_test_assert(($manifest['model_source']['commit'] ?? '') === 'a4abfb2979a7bbff3f69f58f58ae324608821e27', 'image-tools source revision mismatch');
    hub_test_assert(($manifest['model_source']['asset_dir'] ?? '') === '/DATA/models/image-tools/realesrgan', 'image-tools asset directory mismatch');

    $jobs = $manifest['async_jobs'] ?? [];
    hub_test_assert(array_column($jobs, 'job') === ['upscale_image_gpu', 'upscale_image_cpu'], 'image-tools must declare only fixed GPU then CPU jobs');
    foreach ($jobs as $index => $job) {
        $expectedBackend = $index === 0 ? 'cuda' : 'cpu';
        hub_test_assert(($job['input']['fields'] ?? []) === ['model', 'backend'], 'image-tools jobs may expose only model and backend');
        hub_test_assert(array_keys($job['input']['request_schema'] ?? []) === ['model', 'backend'], 'image-tools request schema must expose only model and backend');
        hub_test_assert(($job['input']['request_schema']['backend']['enum'] ?? []) === [$expectedBackend], 'image-tools job backend must already be resolved');
        hub_test_assert(($job['runner']['entrypoint'] ?? []) === ['python3', '/app/jobs.py'], 'image-tools job entrypoint mismatch');
        hub_test_assert(($job['runner']['args'] ?? []) === ['--request', '/workspace/input/request.json', '--source', '/workspace/input/source', '--output-dir', '/workspace/output'], 'image-tools job args mismatch');
        hub_test_assert(($job['runner']['output_dir'] ?? '') === 'output' && ($job['runner']['network_profile'] ?? '') === 'isolated', 'image-tools job runner isolation mismatch');
        hub_test_assert(($job['runner']['accelerator'] ?? '') === ($index === 0 ? 'gpu' : 'cpu'), 'image-tools job accelerator mismatch');
        hub_test_assert(($job['runner']['required_vram_mb'] ?? -1) === ($index === 0 ? 4096 : 0), 'image-tools job VRAM contract mismatch');
        hub_test_assert(($job['runner']['asset_mounts'] ?? []) === [[
            'id' => 'realesrgan_models',
            'storage' => 'models',
            'host_subdir' => 'image-tools/realesrgan',
            'container_path' => '/models/image-tools/realesrgan',
            'required_paths' => ['ready.json'],
        ]], 'image-tools job model mount mismatch');
        hub_test_assert(array_column($job['output']['artifacts'] ?? [], 'path') === ['upscaled_image.png', 'upscale_report.json'], 'image-tools artifact contract mismatch');
    }

    hub_test_assert(($manifest['storage']['mounts'] ?? []) === [[
        'type' => 'models',
        'host_subdir' => 'image-tools/realesrgan',
        'container_path' => '/models/image-tools/realesrgan',
        'read_only' => true,
    ]], 'image-tools service model mount must be read-only');

    hub_test_assert(($manifest['settings_schema'] ?? []) === [
        ['key' => 'IMAGE_TOOLS_USE_GPU', 'label' => 'Use GPU', 'type' => 'boolean', 'default' => '1', 'required' => true, 'restart_required' => true],
        ['key' => 'IMAGE_TOOLS_DEFAULT_BACKEND', 'label' => 'Default backend', 'type' => 'select', 'default' => 'auto', 'options' => ['auto', 'cuda', 'cpu'], 'required' => true, 'restart_required' => true],
        ['key' => 'IMAGE_TOOLS_MAX_UPLOAD_MB', 'label' => 'Max upload MB', 'type' => 'integer', 'default' => '50', 'min' => 1, 'max' => 50, 'required' => true, 'restart_required' => true],
    ], 'image-tools install settings mismatch');
    hub_test_assert(($manifest['env'] ?? []) === [
        ['name' => 'IMAGE_TOOLS_USE_GPU', 'default' => '1', 'required' => true],
        ['name' => 'IMAGE_TOOLS_DEFAULT_BACKEND', 'default' => 'auto', 'required' => true],
        ['name' => 'IMAGE_TOOLS_MODEL_DIR', 'default' => '/models/image-tools/realesrgan', 'required' => true],
        ['name' => 'IMAGE_TOOLS_MAX_UPLOAD_MB', 'default' => '50', 'required' => true],
    ], 'image-tools runtime environment mismatch');
    hub_test_assert(($manifest['service']['local_port_env'] ?? '') === 'IMAGE_TOOLS_PORT', 'image-tools port environment mismatch');

    $catalog = json_decode((string)file_get_contents(HUB_ROOT . '/packs/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $catalogEntry = null;
    foreach (($catalog['packs'] ?? []) as $entry) {
        if (($entry['id'] ?? '') === 'image-tools') {
            $catalogEntry = $entry;
            break;
        }
    }
    hub_test_assert($catalogEntry !== null, 'image-tools catalog entry missing');
});

hub_test('image-tools checked-in Compose requests all GPUs', function (): void {
    $compose = (string)file_get_contents(HUB_ROOT . '/packs/image-tools/docker-compose.yml');
    hub_test_assert(str_contains($compose, 'gpus: all'), 'image-tools checked-in Compose must request all GPUs');
});

hub_test('image-tools public mode permits internal hyphens only', function (): void {
    $pack = hub_get_pack('image-tools');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-tools pack must validate with its public hyphenated mode');
    hub_test_assert(
        hub_local_gateway_url('/3waAIHub', 'image-tools') === 'http://127.0.0.1/3waAIHub/api.php?mode=image-tools',
        'image-tools public mode must form a local gateway URL'
    );

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'image-tools', [
        'service_key' => 'image-tools-contract',
        'port_mode' => 'manual',
        'local_port' => 18113,
    ]);
    hub_test_assert(($installed['service']['mode'] ?? '') === 'image-tools', 'image-tools install must retain its default public mode');
    $compose = (string)file_get_contents(hub_path((string)$installed['service']['compose_file']));
    hub_test_assert(str_contains($compose, 'gpus: all'), 'image-tools generated Compose must request all GPUs by default');

    $cpuInstalled = hub_install_pack($db, 'image-tools', [
        'service_key' => 'image-tools-cpu',
        'mode' => 'image-tools-cpu',
        'port_mode' => 'manual',
        'local_port' => 18114,
        'env' => ['IMAGE_TOOLS_USE_GPU' => '0'],
    ]);
    $cpuCompose = (string)file_get_contents(hub_path((string)$cpuInstalled['service']['compose_file']));
    hub_test_assert(!str_contains($cpuCompose, 'gpus: all'), 'image-tools generated Compose must omit GPUs when explicitly disabled');

    foreach (['-image-tools', 'image/tools', 'image.tools', "image\ntools", "\nimage-tools", "image-tools\n", "\0image-tools"] as $invalidMode) {
        $invalidManifest = $pack['manifest'];
        $invalidManifest['default_mode'] = $invalidMode;
        hub_test_assert(hub_validate_pack_manifest($invalidManifest, $pack['dir']) !== [], 'manifest must reject invalid mode ' . json_encode($invalidMode, JSON_THROW_ON_ERROR));
        hub_test_assert(
            hub_test_throws(static fn () => hub_validate_service_instance_input('image-tools-contract', $invalidMode, 'Image Tools', 'manual', 'production')),
            'service instance must reject invalid mode ' . json_encode($invalidMode, JSON_THROW_ON_ERROR)
        );
        hub_test_assert(
            hub_test_throws(static fn (): string => hub_local_gateway_url('/3waAIHub', $invalidMode)),
            'local gateway URL must reject invalid mode ' . json_encode($invalidMode, JSON_THROW_ON_ERROR)
        );
    }
});
