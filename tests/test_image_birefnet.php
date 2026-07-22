<?php
declare(strict_types=1);

hub_test('BiRefNet pack starts with the approved binary API contract', function (): void {
    $pack = hub_get_pack('image-birefnet');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-birefnet pack missing or invalid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L3-storage-mount', 'BiRefNet runtime level mismatch');
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
    hub_test_assert(($contract['limits']['max_decoded_pixels'] ?? 0) === 40000000, 'BiRefNet pixel limit mismatch');
    hub_test_assert(($contract['limits']['max_upload_mb'] ?? 0) === 50, 'BiRefNet per-file upload limit mismatch');

    $expectedErrors = [
        'file_required', 'payload_too_large', 'unsupported_media_type', 'invalid_image',
        'invalid_parameter', 'model_not_present', 'model_load_failed', 'inference_failed',
        'inference_timeout', 'runtime_not_ready', 'gateway_timeout',
    ];
    hub_test_assert(($contract['errors'] ?? []) === $expectedErrors, 'BiRefNet error contract mismatch');

    $base = HUB_ROOT . '/packs/image-birefnet';
    foreach (['pack.json', 'docker-compose.yml', 'service/Dockerfile', 'service/requirements.txt', 'service/app.py', 'service/provision_offline_assets.py', 'service/storage_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'BiRefNet runtime file missing ' . $file);
    }
    $app = (string)file_get_contents($base . '/service/app.py');
    hub_test_assert(str_contains($app, '@app.get("/health")'), 'BiRefNet health endpoint missing');
    hub_test_assert(str_contains($app, '@app.post("/remove-background/image")'), 'BiRefNet invoke endpoint missing');
    hub_test_assert(str_contains($app, 'runtime_not_ready'), 'BiRefNet L1 endpoint must fail closed');
    hub_test_assert(!str_contains($app, 'mock'), 'BiRefNet must not expose placeholder inference');
    $requirements = (string)file_get_contents($base . '/service/requirements.txt');
    foreach (['torch==', 'torchvision==', 'transformers==', 'timm==', 'kornia==', 'einops=='] as $dependency) {
        hub_test_assert(str_contains($requirements, $dependency), 'BiRefNet dependency missing ' . $dependency);
    }
    $dockerfile = (string)file_get_contents($base . '/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'python3 /app/smoke.py'), 'BiRefNet dependency smoke missing');
    hub_test_assert(str_contains($dockerfile, 'test_image_pipeline.py test_provision_offline_assets.py'), 'BiRefNet unit tests missing from build');
    hub_test_assert(!str_contains($dockerfile, 'python3 /app/provision_offline_assets.py'), 'BiRefNet build must not download model assets');

    $provisioner = (string)file_get_contents($base . '/service/provision_offline_assets.py');
    hub_test_assert(str_contains($provisioner, 'ZhengPeng7/BiRefNet'), 'BiRefNet pinned repository missing');
    hub_test_assert(str_contains($provisioner, 'e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4'), 'BiRefNet pinned revision missing');
    hub_test_assert(str_contains($provisioner, 'snapshot_download'), 'BiRefNet explicit provisioner missing');

    $compose = (string)file_get_contents($base . '/docker-compose.yml');
    foreach (['HF_HOME: /models/birefnet/huggingface', 'HF_HUB_OFFLINE: "1"', 'TRANSFORMERS_OFFLINE: "1"', 'XDG_CACHE_HOME: /cache/birefnet/xdg', 'HOME: /cache/birefnet/home'] as $needle) {
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
    foreach (['${AIHUB_MODELS_DIR}/birefnet:/models/birefnet', '${AIHUB_CACHE_DIR}/birefnet:/cache/birefnet', '${SERVICE_DATA_DIR}:/data/service'] as $needle) {
        hub_test_assert(str_contains($gpuCompose, $needle), 'BiRefNet generated compose missing ' . $needle);
    }
    foreach (['BIREFNET_USE_GPU=1', 'BIREFNET_DEVICE=auto', 'HF_HOME=/models/birefnet/huggingface', 'HF_HUB_OFFLINE=1', 'TRANSFORMERS_OFFLINE=1'] as $needle) {
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
