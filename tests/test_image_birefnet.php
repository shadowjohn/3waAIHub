<?php
declare(strict_types=1);

hub_test('BiRefNet pack starts with the approved binary API contract', function (): void {
    $pack = hub_get_pack('image-birefnet');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-birefnet pack missing or invalid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'BiRefNet runtime level mismatch');
    hub_test_assert(($manifest['runtime_ready'] ?? false) === true, 'BiRefNet runtime must be ready after real inference');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'BiRefNet target mismatch');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'background_remove', 'BiRefNet mode mismatch');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'BiRefNet execution type mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/remove-background/image', 'BiRefNet endpoint mismatch');
    hub_test_assert(($manifest['gateway']['max_upload_mb'] ?? 0) === 101, 'BiRefNet aggregate upload limit mismatch');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? true) === false, 'BiRefNet must retain CPU fallback');
    hub_test_assert(($manifest['hardware']['gpu_supported'] ?? false) === true, 'BiRefNet must advertise GPU support');
    hub_test_assert(($manifest['hardware']['cpu_fallback'] ?? false) === true, 'BiRefNet CPU fallback missing');
    hub_test_assert(($manifest['queue']['max_concurrency'] ?? 0) === 1, 'BiRefNet must remain single concurrency');

    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(($contract['content_type'] ?? '') === 'multipart/form-data', 'BiRefNet request MIME mismatch');
    hub_test_assert(($contract['output']['content_type'] ?? '') === 'image/png', 'BiRefNet success MIME mismatch');
    hub_test_assert(($contract['output']['required_headers'] ?? []) === [
        'X-3waAIHub-Model',
        'X-3waAIHub-Device',
        'X-3waAIHub-Elapsed-Ms',
        'X-3waAIHub-Width',
        'X-3waAIHub-Height',
    ], 'BiRefNet response metadata mismatch');

    $fields = [];
    foreach (($contract['input']['fields'] ?? []) as $field) {
        if (is_array($field) && isset($field['name'])) {
            $fields[(string)$field['name']] = $field;
        }
    }
    foreach (['image', 'output', 'feather_px', 'edge_offset_px', 'defringe', 'background', 'background_color', 'background_image'] as $name) {
        hub_test_assert(isset($fields[$name]), 'BiRefNet input field missing ' . $name);
    }
    hub_test_assert(($fields['output']['enum'] ?? []) === ['cutout', 'mask', 'composite'], 'BiRefNet output enum mismatch');
    hub_test_assert(($fields['background']['enum'] ?? []) === ['transparent', 'white', 'color', 'image'], 'BiRefNet background enum mismatch');
    hub_test_assert(($fields['feather_px']['min'] ?? null) === 0 && ($fields['feather_px']['max'] ?? null) === 20, 'BiRefNet feather range mismatch');
    hub_test_assert(($fields['edge_offset_px']['min'] ?? null) === -20 && ($fields['edge_offset_px']['max'] ?? null) === 20, 'BiRefNet edge offset range mismatch');
    hub_test_assert(($contract['limits']['max_axis_px'] ?? 0) === 8192, 'BiRefNet axis limit mismatch');
    hub_test_assert(($contract['limits']['max_decoded_pixels'] ?? 0) === 10000000, 'BiRefNet pixel limit mismatch');
    hub_test_assert(($contract['limits']['max_upload_mb'] ?? 0) === 50, 'BiRefNet per-file upload limit mismatch');

    $expectedErrors = [
        'file_required', 'payload_too_large', 'unsupported_media_type', 'invalid_image',
        'invalid_parameter', 'model_not_present', 'model_load_failed', 'inference_failed',
        'runtime_not_ready', 'gateway_timeout',
    ];
    hub_test_assert(($contract['errors'] ?? []) === $expectedErrors, 'BiRefNet error contract mismatch');

    $base = HUB_ROOT . '/packs/image-birefnet';
    foreach (['pack.json', 'docker-compose.yml', 'demo/smoke.png', 'service/Dockerfile', 'service/requirements.txt', 'service/app.py', 'service/inference_smoke.py', 'service/model_runtime.py', 'service/model_smoke.py', 'service/provision_offline_assets.py', 'service/storage_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'BiRefNet runtime file missing ' . $file);
    }
    $app = (string)file_get_contents($base . '/service/app.py');
    hub_test_assert(str_contains($app, '@app.get("/health")'), 'BiRefNet health endpoint missing');
    hub_test_assert(str_contains($app, '@app.post("/remove-background/image")'), 'BiRefNet invoke endpoint missing');
    hub_test_assert(str_contains($app, 'infer_alpha'), 'BiRefNet real inference path missing');
    hub_test_assert(str_contains($app, 'torch.cuda.OutOfMemoryError'), 'BiRefNet CUDA OOM fallback check missing');
    hub_test_assert(str_contains($app, 'run_in_threadpool'), 'BiRefNet inference must not block the health event loop');
    hub_test_assert(!str_contains($app, 'write_bytes'), 'BiRefNet requests must stay in memory');
    hub_test_assert(!str_contains($app, 'mock'), 'BiRefNet must not expose placeholder inference');
    $requirements = (string)file_get_contents($base . '/service/requirements.txt');
    foreach (['torch==', 'torchvision==', 'transformers==', 'timm==', 'kornia==', 'einops=='] as $dependency) {
        hub_test_assert(str_contains($requirements, $dependency), 'BiRefNet dependency missing ' . $dependency);
    }
    $dockerfile = (string)file_get_contents($base . '/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'python3 /app/smoke.py'), 'BiRefNet dependency smoke missing');
    hub_test_assert(str_contains($dockerfile, 'test_app.py test_endpoint.py test_image_pipeline.py test_provision_offline_assets.py'), 'BiRefNet unit tests missing from build');
    hub_test_assert(!str_contains($dockerfile, 'python3 /app/provision_offline_assets.py'), 'BiRefNet build must not download model assets');
    hub_test_assert(str_contains($dockerfile, 'USER 65532:65532'), 'BiRefNet runtime must not run as root');

    $provisioner = (string)file_get_contents($base . '/service/provision_offline_assets.py');
    hub_test_assert(str_contains($provisioner, 'ZhengPeng7/BiRefNet'), 'BiRefNet pinned repository missing');
    hub_test_assert(str_contains($provisioner, 'e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4'), 'BiRefNet pinned revision missing');
    hub_test_assert(str_contains($provisioner, 'snapshot_download'), 'BiRefNet explicit provisioner missing');

    $modelRuntime = (string)file_get_contents($base . '/service/model_runtime.py');
    foreach (['trust_remote_code=True', 'local_files_only=True', 'MODEL_REVISION', 'torch_module.cuda.is_available()', 'torch_module.cuda'] as $needle) {
        hub_test_assert(str_contains($modelRuntime, $needle), 'BiRefNet offline model loader missing ' . $needle);
    }
    hub_test_assert(!str_contains($modelRuntime, 'snapshot_download'), 'BiRefNet runtime must never download model assets');
    $modelSmoke = (string)file_get_contents($base . '/service/model_smoke.py');
    hub_test_assert(str_contains($modelSmoke, 'verify_ready()'), 'BiRefNet model smoke must verify checksums');
    hub_test_assert(str_contains($modelSmoke, 'load_model()'), 'BiRefNet model smoke must initialize the model');
    hub_test_assert(!str_contains($modelSmoke, 'Image.open'), 'BiRefNet L4a smoke must not run image inference');
    $inferenceSmoke = (string)file_get_contents($base . '/service/inference_smoke.py');
    hub_test_assert(str_contains($inferenceSmoke, 'requests.post'), 'BiRefNet inference smoke must use HTTP');
    hub_test_assert(str_contains($inferenceSmoke, '--expect-device'), 'BiRefNet inference smoke must verify GPU and CPU device');
    hub_test_assert(!str_contains($inferenceSmoke, 'in-process'), 'BiRefNet inference smoke must not bypass HTTP');

    $compose = (string)file_get_contents($base . '/docker-compose.yml');
    foreach (['HF_HOME: /tmp/huggingface', 'HF_HUB_OFFLINE: "1"', 'TRANSFORMERS_OFFLINE: "1"', 'XDG_CACHE_HOME: /tmp/xdg', 'HOME: /tmp/home'] as $needle) {
        hub_test_assert(str_contains($compose, $needle), 'BiRefNet offline compose env missing ' . $needle);
    }

    $catalogIds = array_column(json_decode((string)file_get_contents(HUB_ROOT . '/packs/catalog.json'), true)['packs'] ?? [], 'id');
    hub_test_assert(in_array('image-birefnet', $catalogIds, true), 'BiRefNet catalog entry missing');
});

hub_test('BiRefNet install generates GPU-first compose and permits explicit CPU override', function (): void {
    $db = hub_test_reset_db();
    $gpu = hub_install_pack($db, 'image-birefnet', [
        'service_key' => 'birefnet-gpu',
        'mode' => 'background_remove',
        'name' => 'BiRefNet GPU',
        'port_mode' => 'manual',
        'local_port' => 18112,
    ]);
    $gpuCompose = (string)file_get_contents(hub_path($gpu['service']['compose_file']));
    $gpuEnv = (string)file_get_contents(dirname(hub_path($gpu['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($gpuCompose, 'gpus: all'), 'BiRefNet default install must request GPU');
    foreach (['${AIHUB_MODELS_DIR}/birefnet:/models/birefnet:ro'] as $needle) {
        hub_test_assert(str_contains($gpuCompose, $needle), 'BiRefNet generated compose missing ' . $needle);
    }
    hub_test_assert(!str_contains($gpuCompose, '/cache/birefnet') && !str_contains($gpuCompose, '/data/service'), 'BiRefNet generated compose must not persist request or runtime cache data');
    foreach (['BIREFNET_USE_GPU=1', 'BIREFNET_DEVICE=auto', 'HF_HOME=/tmp/huggingface', 'HF_HUB_OFFLINE=1', 'TRANSFORMERS_OFFLINE=1'] as $needle) {
        hub_test_assert(str_contains($gpuEnv, $needle), 'BiRefNet generated GPU env missing ' . $needle);
    }

    $cpu = hub_install_pack($db, 'image-birefnet', [
        'service_key' => 'birefnet-cpu',
        'mode' => 'background_remove_cpu',
        'name' => 'BiRefNet CPU',
        'port_mode' => 'manual',
        'local_port' => 18113,
        'env' => ['BIREFNET_USE_GPU' => '0', 'BIREFNET_DEVICE' => 'cpu'],
    ]);
    $cpuCompose = (string)file_get_contents(hub_path($cpu['service']['compose_file']));
    $cpuEnv = (string)file_get_contents(dirname(hub_path($cpu['service']['compose_file'])) . '/.env');
    hub_test_assert(!str_contains($cpuCompose, 'gpus: all'), 'BiRefNet CPU override must not request GPU');
    hub_test_assert(str_contains($cpuEnv, 'BIREFNET_USE_GPU=0'), 'BiRefNet CPU env must disable GPU');
    hub_test_assert(str_contains($cpuEnv, 'BIREFNET_DEVICE=cpu'), 'BiRefNet CPU env must select CPU');
});

hub_test('BiRefNet gateway allowlists only validated final response metadata', function (): void {
    $rawHeaders = "HTTP/1.1 100 Continue\r\n"
        . "X-3waAIHub-Device: cpu\r\n\r\n"
        . "HTTP/1.1 200 OK\r\n"
        . "Set-Cookie: secret=1\r\n"
        . "Location: https://example.invalid/private\r\n"
        . "X-Internal-Path: /models/private\r\n"
        . "x-3WAAIhub-model: bad\x00value\r\n"
        . "X-3waAIHub-Model: ZhengPeng7/BiRefNet@revision\r\n"
        . "X-3waAIHub-Device: TPU\r\n"
        . "x-3waaihub-device: cuda\r\n"
        . "X-3waAIHub-Elapsed-Ms: 12ms\r\n"
        . "x-3waaihub-elapsed-ms: 12\r\n"
        . "X-3waAIHub-Width: 1280\r\n"
        . "X-3waAIHub-Height: 720\r\n\r\n";
    hub_test_assert(hub_proxy_allowed_response_headers($rawHeaders, 'image/png') === [
        'Content-Type: image/png',
        'X-3waAIHub-Model: ZhengPeng7/BiRefNet@revision',
        'X-3waAIHub-Device: cuda',
        'X-3waAIHub-Elapsed-Ms: 12',
        'X-3waAIHub-Width: 1280',
        'X-3waAIHub-Height: 720',
    ], 'BiRefNet gateway response header allowlist mismatch');
});

hub_test('BiRefNet binary gateway preserves PNG bytes metadata request id and accounting', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'image-birefnet', [
        'service_key' => 'birefnet-main',
        'mode' => 'background_remove',
        'name' => 'BiRefNet Main',
        'port_mode' => 'manual',
        'local_port' => 18112,
    ]);
    $readiness = hub_pack_l5_readiness($db, 'image-birefnet');
    hub_test_assert(($readiness['checks']['has_output_contract'] ?? false) === true, 'BiRefNet binary output must satisfy L5 readiness');
    hub_set_service_enabled($db, 'background_remove', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $png = "\x89PNG\r\n\x1a\n" . random_bytes(64);
    $oldServer = $_SERVER;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=background_remove';
        $_SERVER['CONTENT_LENGTH'] = '128';
        $response = hub_gateway_dispatch($db, 'background_remove', static fn (): array => [
            'status' => 200,
            'headers' => [
                'Content-Type: image/png',
                'X-3waAIHub-Model: ZhengPeng7/BiRefNet@revision',
                'X-3waAIHub-Device: cuda',
                'X-3waAIHub-Elapsed-Ms: 12',
                'X-3waAIHub-Width: 1280',
                'X-3waAIHub-Height: 720',
            ],
            'body' => $png,
        ]);
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200 && $response['body'] === $png, 'BiRefNet PNG body changed in gateway');
    hub_test_assert(in_array('Content-Type: image/png', $response['headers'], true), 'BiRefNet PNG MIME missing after gateway');
    hub_test_assert(in_array('X-3waAIHub-Device: cuda', $response['headers'], true), 'BiRefNet device metadata missing after gateway');
    hub_test_assert(count(array_filter($response['headers'], static fn (string $header): bool => str_starts_with($header, 'X-3waAIHub-Request-Id: '))) === 1, 'BiRefNet request id header missing');
    $log = $db->query('SELECT response_bytes, ok, error_code FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert((int)$log['response_bytes'] === strlen($png), 'BiRefNet PNG output byte accounting mismatch');
    hub_test_assert((int)$log['ok'] === 1 && $log['error_code'] === null, 'BiRefNet PNG success log mismatch');
});

hub_test('BiRefNet playground parses PNG metadata without exposing binary body', function (): void {
    hub_test_assert(in_array('background_remove', hub_playground_supported_modes(), true), 'BiRefNet mode missing from playground allowlist');
    $png = "\x89PNG\r\n\x1a\n" . random_bytes(32);
    $headers = "HTTP/1.1 200 OK\r\n"
        . "Content-Type: image/png\r\n"
        . "X-3waAIHub-Request-Id: req_birefnet_playground\r\n"
        . "X-3waAIHub-Model: ZhengPeng7/BiRefNet@revision\r\n"
        . "X-3waAIHub-Device: cuda\r\n"
        . "X-3waAIHub-Elapsed-Ms: 12\r\n"
        . "X-3waAIHub-Width: 1280\r\n"
        . "X-3waAIHub-Height: 720\r\n\r\n";
    $result = hub_playground_parse_response(200, $headers, 'image/png', $png, 14);
    hub_test_assert(($result['ok'] ?? false) === true, 'BiRefNet playground PNG parse failed');
    hub_test_assert(($result['body'] ?? null) === '', 'BiRefNet playground must not retain raw PNG as response text');
    hub_test_assert(str_starts_with((string)($result['preview_data_uri'] ?? ''), 'data:image/png;base64,'), 'BiRefNet playground preview data URI missing');
    hub_test_assert(base64_decode(substr((string)$result['preview_data_uri'], strlen('data:image/png;base64,')), true) === $png, 'BiRefNet playground preview bytes changed');
    hub_test_assert(($result['metadata'] ?? []) === [
        'model' => 'ZhengPeng7/BiRefNet@revision',
        'device' => 'cuda',
        'elapsed_ms' => 12,
        'width' => 1280,
        'height' => 720,
    ], 'BiRefNet playground metadata mismatch');
    hub_test_assert(!str_contains((string)$result['pretty_body'], "\x89PNG"), 'BiRefNet playground pretty body contains PNG bytes');
});

hub_test('BiRefNet playground exposes only the approved single-image controls and preview', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach ([
        "'background_remove' => ['label' => 'BiRefNet 去背'",
        'name="image" type="file"',
        'name="background_image" type="file"',
        'name="output"',
        'name="background"',
        'name="feather_px" type="number"',
        'name="edge_offset_px" type="number"',
        'name="defringe" type="checkbox"',
        'name="background_color" type="color"',
        'preview_data_uri',
        'download="background-removed.png"',
        'X-3waAIHub-Model',
        'await res.blob()',
    ] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'BiRefNet playground UI missing ' . $needle);
    }
    hub_test_assert(!str_contains($source, "'background_remove' => ['label' => 'BiRefNet 去背', 'method' => 'POST', 'kind' => 'json']"), 'BiRefNet playground must not use JSON response handling');
});

hub_test('BiRefNet declares one structural benchmark and three per-fixture quality gates', function (): void {
    $manifest = hub_get_pack('image-birefnet')['manifest'];
    $contract = $manifest['l5_contract'] ?? [];
    $case = hub_l5_benchmark_case($contract, 'birefnet_real_image');
    hub_test_assert(is_array($case), 'BiRefNet real binary benchmark missing');
    hub_test_assert(($case['expected_content_type'] ?? '') === 'image/png', 'BiRefNet benchmark MIME mismatch');
    hub_test_assert(($case['expected_png'] ?? false) === true, 'BiRefNet benchmark PNG check missing');
    hub_test_assert(($case['expected_dimensions_from_fixture'] ?? false) === true, 'BiRefNet benchmark dimension check missing');
    hub_test_assert(($case['expected_response_headers'] ?? []) === [
        'X-3waAIHub-Model',
        'X-3waAIHub-Device',
        'X-3waAIHub-Elapsed-Ms',
        'X-3waAIHub-Width',
        'X-3waAIHub-Height',
    ], 'BiRefNet benchmark response headers mismatch');

    $quality = $contract['quality_benchmark'] ?? [];
    hub_test_assert(($quality['runner'] ?? '') === 'service/acceptance.py', 'BiRefNet quality runner mismatch');
    hub_test_assert((float)($quality['f_score_min'] ?? 0) === 0.80, 'BiRefNet F-score threshold mismatch');
    hub_test_assert((float)($quality['mae_max'] ?? 1) === 0.10, 'BiRefNet MAE threshold mismatch');
    hub_test_assert(count($quality['fixtures'] ?? []) === 3, 'BiRefNet quality fixture count mismatch');
    foreach ($quality['fixtures'] ?? [] as $fixture) {
        foreach (['image', 'mask'] as $key) {
            $path = HUB_ROOT . '/packs/image-birefnet/' . ltrim((string)($fixture[$key] ?? ''), '/');
            hub_test_assert(is_file($path), 'BiRefNet acceptance ' . $key . ' missing');
        }
    }
    hub_test_assert(is_file(HUB_ROOT . '/packs/image-birefnet/service/acceptance.py'), 'BiRefNet acceptance runner missing');
    hub_test_assert(is_file(HUB_ROOT . '/packs/image-birefnet/demo/acceptance/README.md'), 'BiRefNet acceptance README missing');
});

hub_test('BiRefNet operations runbook covers provisioning acceptance and rollback', function (): void {
    $path = HUB_ROOT . '/docs/operations/image-birefnet.md';
    hub_test_assert(is_file($path), 'BiRefNet operations runbook missing');
    $runbook = (string)file_get_contents($path);
    foreach ([
        'ZhengPeng7/BiRefNet',
        'e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4',
        'provision_offline_assets.py',
        'verify_ready',
        "'BIREFNET_USE_GPU' => '0'",
        '--expect-device cuda',
        '--expect-device cpu',
        '/app/acceptance.py',
        'birefnet_real_image',
        'previous known-good Pack',
        'no tiling, batch API, video workflow, interactive editor, or output',
    ] as $needle) {
        hub_test_assert(str_contains($runbook, $needle), 'BiRefNet runbook missing ' . $needle);
    }
    hub_test_assert(!str_contains($runbook, '$oauthtoken'), 'BiRefNet runbook must not contain registry credentials');
});
