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

function hub_test_image_tools_runner_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_image_tools_runner_' . bin2hex(random_bytes(8));
    foreach (['input', 'output', 'checkpoints'] as $name) {
        if (!mkdir($workspace . '/' . $name, 0700, true)) {
            throw new RuntimeException('Cannot create image-tools runner workspace.');
        }
    }
    if (file_put_contents($workspace . '/input/request.json', "{\"model\":\"realesrgan-x4plus\",\"backend\":\"cpu\"}\n", LOCK_EX) === false
        || file_put_contents($workspace . '/input/source', 'source', LOCK_EX) === false) {
        throw new RuntimeException('Cannot create image-tools runner input.');
    }

    return $workspace;
}

function hub_test_image_tools_remove_workspace(string $workspace): void
{
    foreach (['input/request.json', 'input/source'] as $file) {
        @unlink($workspace . '/' . $file);
    }
    foreach (['input', 'output', 'checkpoints'] as $name) {
        @rmdir($workspace . '/' . $name);
    }
    @rmdir($workspace);
}

function hub_test_with_image_tools_runtime_ready(callable $callback): mixed
{
    $path = HUB_ROOT . '/packs/image-tools/pack.json';
    $original = (string)file_get_contents($path);
    $manifest = json_decode($original, true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Cannot load image-tools Pack fixture.');
    }
    $manifest['runtime_ready'] = true;
    if (file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot enable image-tools Pack fixture.');
    }
    try {
        return $callback();
    } finally {
        file_put_contents($path, $original, LOCK_EX);
    }
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

hub_test('image-tools gateway validates the configured backend', function (): void {
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
});

hub_test('image-tools async runners fix their backend and artifact report contract', function (): void {
    $pack = hub_get_pack('image-tools');
    hub_test_assert(is_array($pack), 'image-tools Pack must be registered');
    $expectedReport = ['model', 'backend', 'source_width', 'source_height', 'width', 'height', 'elapsed_ms', 'output_sha256'];
    foreach ([
        'upscale_image_gpu' => ['backend' => 'cuda', 'accelerator' => 'gpu'],
        'upscale_image_cpu' => ['backend' => 'cpu', 'accelerator' => 'cpu'],
    ] as $jobName => $expected) {
        $job = hub_pack_async_job_contract((array)$pack['manifest'], $jobName);
        hub_test_assert(is_array($job), $jobName . ' contract must be available');
        $runner = (array)($job['runner'] ?? []);
        $args = (array)($runner['args'] ?? []);
        $backendPosition = array_search('--backend', $args, true);
        hub_test_assert(($runner['accelerator'] ?? '') === $expected['accelerator'] && is_int($backendPosition)
            && ($args[$backendPosition + 1] ?? null) === $expected['backend'], $jobName . ' must pass its immutable backend to jobs.py');
        hub_test_assert(($runner['workspace_user'] ?? '') === 'owner', $jobName . ' must opt into the private workspace output owner identity');
        $artifacts = (array)(($job['artifact_contract']['artifacts'] ?? []));
        hub_test_assert(array_column($artifacts, 'path') === ['upscaled_image.png', 'upscale_report.json'], $jobName . ' must publish only the image and report artifacts');
        hub_test_assert(($artifacts[1]['json']['required_keys'] ?? null) === $expectedReport, $jobName . ' report must attest the image metadata and digest');
    }
});

hub_test('image-tools generic runner grants its non-root job only the private output owner identity', function (): void {
    $pack = hub_get_pack('image-tools');
    $job = is_array($pack) ? hub_pack_async_job_contract((array)$pack['manifest'], 'upscale_image_cpu') : null;
    hub_test_assert(is_array($job), 'image-tools CPU runner contract must be available');
    $workspace = hub_test_image_tools_runner_workspace();
    try {
        $outputStat = lstat($workspace . '/output');
        hub_test_assert(is_array($outputStat) && (int)($outputStat['uid'] ?? 0) > 0 && (int)($outputStat['gid'] ?? 0) > 0, 'image-tools runner fixture needs a non-root private output owner');
        $runner = (array)$job['runner'];
        unset($runner['asset_mounts']);
        $plan = hub_pack_job_default_runner_command([
            'workspace' => $workspace,
            'run' => ['run_id' => 'image-tools-owner-fixture'],
            'runner' => $runner,
        ]);
        $command = $plan['command'] ?? [];
        $user = array_search('--user', $command, true);
        hub_test_assert(is_int($user) && ($command[$user + 1] ?? null) === ((int)$outputStat['uid'] . ':' . (int)$outputStat['gid']), 'generic runner must use only the private output owner identity for image-tools');
        hub_test_assert(in_array('type=bind,src=' . realpath($workspace . '/output') . ',dst=/workspace/output', $command, true), 'generic runner must mount only the private task output directory writable');
        foreach (['request.json', 'source'] as $file) {
            hub_test_assert(in_array('type=bind,src=' . realpath($workspace . '/input/' . $file) . ',dst=/workspace/input/' . $file . ',readonly', $command, true), 'generic runner must keep image-tools input ' . $file . ' read-only');
        }
    } finally {
        hub_test_image_tools_remove_workspace($workspace);
    }
});

hub_test('image-tools async routing fixes the selected backend and stages Base64 without persisting it', function (): void {
    hub_test_with_image_tools_runtime_ready(function (): void {
        $db = hub_test_reset_db();
        $service = hub_test_image_tools_service($db);
        $gpuProbe = static fn (): array => ['free_vram_mb' => 8192, 'processes' => [], 'process_details' => []];
        $cpuProbe = static fn (): array => ['free_vram_mb' => 0, 'processes' => [], 'process_details' => [], 'probe_error' => 'gpu_probe_failed'];

        hub_save_host_metric_snapshot($db, ['gpu' => ['available' => false, 'memory_free_mb' => 0]]);
        $cachedUnavailable = hub_image_tools_cached_gpu_probe($db);
        hub_test_assert(($cachedUnavailable['probe_error'] ?? '') === 'gpu_snapshot_unavailable', 'HTTP async CUDA routing must fail closed from unavailable cached host metrics without executing a probe');
        hub_test_assert(hub_image_tools_effective_async_backend($db, 'auto') === 'cpu', 'auto must use CPU when cached host metrics do not confirm CUDA');
        try {
            hub_image_tools_effective_async_backend($db, 'cuda');
            throw new RuntimeException('explicit CUDA must not fall back when cached host metrics do not confirm it');
        } catch (RuntimeException $error) {
            hub_test_assert($error->getMessage() === 'backend_unavailable', 'explicit CUDA must remain unavailable without a cached CUDA confirmation');
        }
        hub_save_host_metric_snapshot($db, ['gpu' => ['available' => true, 'memory_free_mb' => 8192]]);
        $cachedReady = hub_image_tools_cached_gpu_probe($db);
        hub_test_assert(($cachedReady['free_vram_mb'] ?? 0) === 8192 && !isset($cachedReady['probe_error']), 'HTTP async CUDA routing must consume only the cached free-VRAM snapshot');
        hub_test_assert(hub_image_tools_effective_async_backend($db, 'cuda') === 'cuda', 'explicit CUDA must retain CUDA when cached host metrics meet its contract');

        $gpu = hub_resolve_image_tools_operation_route($db, 'upscale_task', 'cuda', $gpuProbe);
        $cpu = hub_resolve_image_tools_operation_route($db, 'upscale_task', 'cpu', $gpuProbe);
        $autoGpu = hub_resolve_image_tools_operation_route($db, 'upscale_task', 'auto', $gpuProbe);
        $autoCpu = hub_resolve_image_tools_operation_route($db, 'upscale_task', 'auto', $cpuProbe);
        hub_test_assert(($gpu['job'] ?? '') === 'upscale_image_gpu' && ($gpu['accelerator'] ?? '') === 'gpu', 'explicit CUDA must retain the GPU job contract');
        hub_test_assert(($cpu['job'] ?? '') === 'upscale_image_cpu' && ($cpu['accelerator'] ?? '') === 'cpu', 'explicit CPU must retain the CPU job contract');
        hub_test_assert(($autoGpu['job'] ?? '') === 'upscale_image_gpu' && ($autoCpu['job'] ?? '') === 'upscale_image_cpu', 'auto must select the ready GPU job or fall back to CPU once');
        hub_test_assert((hub_revalidate_pack_job_async_route($db, $gpu + ['task_type' => 'pack_job'])['job'] ?? '') === 'upscale_image_gpu', 'stored GPU route must revalidate as the fixed GPU job without re-probing');
        hub_test_assert((hub_revalidate_pack_job_async_route($db, $cpu + ['task_type' => 'pack_job'])['job'] ?? '') === 'upscale_image_cpu', 'stored CPU route must revalidate as the fixed CPU job');

        hub_set_storage_setting($db, 'AIHUB_GPU_VRAM_SAFETY_MARGIN_MB', '256');
        $marginBoundaryProbe = static fn (): array => ['free_vram_mb' => 4096, 'processes' => [], 'process_details' => []];
        hub_test_assert((hub_resolve_image_tools_operation_route($db, 'upscale_task', 'auto', $marginBoundaryProbe)['job'] ?? '') === 'upscale_image_cpu', 'auto must honor the configured GPU VRAM safety margin');
        try {
            hub_resolve_image_tools_operation_route($db, 'upscale_task', 'cuda', $marginBoundaryProbe);
            throw new RuntimeException('CUDA at the bare model VRAM floor must not bypass the configured margin');
        } catch (RuntimeException $error) {
            hub_test_assert($error->getMessage() === 'backend_unavailable', 'explicit CUDA below its safety margin must return backend_unavailable');
        }

        $db->prepare("UPDATE service_settings SET value = '0' WHERE service_id = :service_id AND key = 'IMAGE_TOOLS_USE_GPU'")
            ->execute([':service_id' => (int)$service['id']]);
        try {
            hub_resolve_image_tools_operation_route($db, 'upscale_task', 'cuda', $gpuProbe);
            throw new RuntimeException('disabled CUDA must not select a CPU fallback');
        } catch (RuntimeException $error) {
            hub_test_assert($error->getMessage() === 'backend_unavailable', 'explicit unavailable CUDA must return backend_unavailable');
        }
        hub_test_assert((hub_resolve_image_tools_operation_route($db, 'upscale_task', 'auto', $gpuProbe)['job'] ?? '') === 'upscale_image_cpu', 'disabled CUDA must make auto choose CPU');
        $db->prepare("UPDATE service_settings SET value = '1' WHERE service_id = :service_id AND key = 'IMAGE_TOOLS_USE_GPU'")
            ->execute([':service_id' => (int)$service['id']]);

        $memberId = hub_create_api_member($db, 'Image Tools Task Owner');
        $token = hub_create_api_token($db, $memberId, 'image tools task token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'image-tools', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $server = $_SERVER;
        $post = $_POST;
        $files = $_FILES;
        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_LENGTH'] = '128';
            $response = hub_gateway_dispatch($db, 'image-tools', null, [
                'method' => 'POST',
                'client_ip' => '203.0.113.51',
                'request_uri' => '/api.php?mode=image-tools',
                'bearer_token' => (string)$token['plain_token'],
                'query' => ['mode' => 'image-tools'],
                'post' => ['operation' => 'upscale_task', 'backend' => 'cpu', 'base64_string' => base64_encode('async source')],
            ]);
            $payload = json_decode((string)($response['body'] ?? ''), true);
            hub_test_assert(($response['status'] ?? 0) === 200 && is_array($payload) && !empty($payload['task_id']), 'upscale_task must enqueue through the existing pack-job queue');
            $task = hub_get_task($db, (int)$payload['task_id']);
            hub_test_assert(($task['requested_mode'] ?? '') === 'image-tools' && ($task['job'] ?? '') === 'upscale_image_cpu', 'async request must snapshot its resolved CPU job');
            hub_test_assert(is_string($task['input']['source_upload_path'] ?? null) && is_file((string)$task['input']['source_upload_path']), 'async Base64 must be stored through the owned-task upload flow');
            hub_test_assert(!array_key_exists('base64_string', (array)($task['input'] ?? [])), 'raw Base64 must never enter stored task input');
            hub_test_assert(($task['input']['backend'] ?? '') === 'cpu', 'stored task input must retain only the resolved backend');

            $syncCalls = 0;
            $sync = hub_gateway_dispatch($db, 'image-tools', static function () use (&$syncCalls): array {
                $syncCalls++;
                return ['status' => 200, 'headers' => ['content-type' => 'image/png'], 'body' => 'png'];
            }, [
                'method' => 'POST',
                'client_ip' => '203.0.113.51',
                'request_uri' => '/api.php?mode=image-tools',
                'bearer_token' => (string)$token['plain_token'],
                'query' => ['mode' => 'image-tools'],
                'post' => ['operation' => 'upscale', 'base64_string' => base64_encode('sync source')],
            ]);
            hub_test_assert(($sync['status'] ?? 0) === 200 && $syncCalls === 1, 'sync upscale must continue to proxy unchanged');

            $db->prepare("UPDATE service_settings SET value = '0' WHERE service_id = :service_id AND key = 'IMAGE_TOOLS_USE_GPU'")
                ->execute([':service_id' => (int)$service['id']]);
            $disabled = hub_gateway_dispatch($db, 'image-tools', null, [
                'method' => 'POST',
                'client_ip' => '203.0.113.51',
                'request_uri' => '/api.php?mode=image-tools',
                'bearer_token' => (string)$token['plain_token'],
                'query' => ['mode' => 'image-tools'],
                'post' => ['operation' => 'upscale_task', 'backend' => 'cuda', 'base64_string' => base64_encode('cuda source')],
            ]);
            hub_test_assert(($disabled['status'] ?? 0) === 503 && hub_test_image_tools_error($disabled) === 'backend_unavailable', 'unavailable CUDA must not enqueue a CPU fallback');
        } finally {
            $_SERVER = $server;
            $_POST = $post;
            $_FILES = $files;
        }
    });
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

    foreach ([
        "X-3waAIHub-Backend: cuda\r\nX-3waAIHub-Backend: vulkan\r\n",
        "X-3waAIHub-Backend: cuda\r\nX-3waAIHub-Backend: cpu\r\n",
    ] as $rawBackendHeaders) {
        $duplicates = hub_proxy_allowed_response_headers("HTTP/1.1 200 OK\r\n" . $rawBackendHeaders, 'image/png');
        hub_test_assert(!array_filter($duplicates, static fn (string $header): bool => str_starts_with($header, 'X-3waAIHub-Backend:')), 'invalid or mismatched duplicate Backend values must suppress the header');
    }

    $same = hub_proxy_allowed_response_headers("HTTP/1.1 200 OK\r\nX-3waAIHub-Backend: cuda\r\nX-3waAIHub-Backend: cuda\r\n", 'image/png');
    hub_test_assert(count(array_filter($same, static fn (string $header): bool => $header === 'X-3waAIHub-Backend: cuda')) === 1, 'identical valid duplicate Backend values must remain canonical');
    $final = hub_gateway_safe_response_headers(['X-3waAIHub-Backend: cuda']);
    hub_test_assert(in_array('X-3waAIHub-Backend: cuda', $final, true), 'validated Backend must survive the final Gateway response-header allowlist');
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
        hub_test_assert(($job['runner']['args'] ?? []) === ['--request', '/workspace/input/request.json', '--source', '/workspace/input/source', '--output-dir', '/workspace/output', '--backend', $expectedBackend], 'image-tools job args mismatch');
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

hub_test('image-tools publishes bounded Playground and documentation contracts', function (): void {
    hub_test_assert(in_array('image-tools', hub_playground_supported_modes(), true), 'image-tools must be selectable in the Playground');

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($playground, "'image-tools' => ['label' => 'Image Tools'")
        && str_contains($playground, "'kind' => 'image_tools'")
        && str_contains($playground, "&operation=' . rawurlencode((string)\$payload['operation'])")
        && str_contains($playground, 'download="upscaled-image.png"'), 'image-tools Playground profile, guarded query operation, and PNG download name must be present');
    hub_test_assert(substr_count($playground, 'name="image" type="file" accept="image/jpeg,image/png,image/webp,image/bmp"') === 1
        && str_contains($playground, '<option value="upscale"')
        && str_contains($playground, '<option value="upscale_task"')
        && str_contains($playground, '<textarea name="base64_string"')
        && !str_contains($playground, 'name="image_path"')
        && !str_contains($playground, 'name="image_url"')
        && !str_contains($playground, 'name="custom_command"')
        && !str_contains($playground, 'name="output_filename"')
        && !str_contains($playground, 'name="deferred_operation"'), 'image-tools Playground must accept exactly the bounded image-tools controls');
    foreach (['realesrgan-x4plus', 'realesrgan-x4plus-anime', 'realesr-animevideov3-x2', 'realesr-animevideov3-x3', 'realesr-animevideov3-x4', 'auto', 'cuda', 'cpu', 'X-3waAIHub-Model', 'X-3waAIHub-Backend', 'X-3waAIHub-Elapsed-Ms', 'X-3waAIHub-Width', 'X-3waAIHub-Height'] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'image-tools Playground is missing ' . $needle);
    }

    $pack = hub_get_pack('image-tools');
    hub_test_assert($pack !== null, 'image-tools Pack must exist for public docs');
    $service = hub_public_api_service_from_contract('image-tools', $pack, $pack['manifest'], hub_public_api_contract_for_manifest($pack['manifest']));
    hub_test_assert(array_column($service['operation_examples'] ?? [], 'operation') === ['upscale', 'upscale_task'], 'public docs must render separate image-tools sync and async operation examples');
    hub_test_assert(($service['operation_examples'][0]['response_content_type'] ?? '') === 'image/png'
        && ($service['operation_examples'][1]['execution_type'] ?? '') === 'async_task'
        && ($service['examples'] ?? null) === []
        && str_contains((string)($service['operation_examples'][0]['examples']['curl'] ?? ''), "-F 'image=@sample.png'")
        && str_contains((string)($service['operation_examples'][1]['examples']['curl'] ?? ''), 'operation=upscale_task'), 'image-tools public examples must retain multipart sync and async task contracts');
    foreach ($service['operation_examples'] as $operationExample) {
        $base64Curl = (string)($operationExample['base64_examples']['curl'] ?? '');
        hub_test_assert(str_contains($base64Curl, "-F 'base64_string=<BASE64_STRING>'")
            && !str_contains($base64Curl, 'image=@sample.png')
            && str_contains($base64Curl, 'operation=' . (string)$operationExample['operation']), 'each image-tools operation must publish a Base64-only example without image bytes');
    }

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    $runbook = HUB_ROOT . '/docs/operations/image-tools.md';
    hub_test_assert(str_contains($readme, "-F 'image=@sample.png'")
        && str_contains($readme, 'base64_string')
        && str_contains($readme, 'upscale_task')
        && str_contains($readme, 'JPEG/JPG, PNG, WEBP, BMP')
        && str_contains($readme, 'file_required, source_ambiguous, invalid_base64'), 'README must publish image-tools source, async, format, and error guidance');
    hub_test_assert(is_file($runbook), 'image-tools operation runbook must exist');
    $runbookText = (string)file_get_contents($runbook);
    foreach (['ready.json', 'SHA-256', 'Docker', 'CUDA', 'GPU-first', 'CPU', 'upscale_task', 'task_status', 'artifact', 'cancellation', 'cleanup', 'rollback', 'retention', 'pending'] as $needle) {
        hub_test_assert(str_contains($runbookText, $needle), 'image-tools runbook is missing ' . $needle);
    }
});
