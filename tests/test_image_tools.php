<?php
declare(strict_types=1);

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
    foreach (['colorize', 'restoration', 'video', 'yolo', 'vulkan'] as $deferred) {
        hub_test_assert(!str_contains(strtolower(json_encode($manifest, JSON_THROW_ON_ERROR)), $deferred), 'image-tools manifest must not advertise deferred ' . $deferred);
        hub_test_assert(!str_contains(strtolower(json_encode($catalogEntry, JSON_THROW_ON_ERROR)), $deferred), 'image-tools catalog must not advertise deferred ' . $deferred);
    }
});

hub_test('image-tools public mode permits internal hyphens only', function (): void {
    $pack = hub_get_pack('image-tools');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'image-tools pack must validate with its public hyphenated mode');

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'image-tools', [
        'service_key' => 'image-tools-contract',
        'port_mode' => 'manual',
        'local_port' => 18113,
    ]);
    hub_test_assert(($installed['service']['mode'] ?? '') === 'image-tools', 'image-tools install must retain its default public mode');

    foreach (['-image-tools', 'image/tools', 'image.tools', "image\ntools"] as $invalidMode) {
        $invalidManifest = $pack['manifest'];
        $invalidManifest['default_mode'] = $invalidMode;
        hub_test_assert(hub_validate_pack_manifest($invalidManifest, $pack['dir']) !== [], 'manifest must reject invalid mode ' . json_encode($invalidMode, JSON_THROW_ON_ERROR));
        hub_test_assert(
            hub_test_throws(static fn () => hub_validate_service_instance_input('image-tools-contract', $invalidMode, 'Image Tools', 'manual', 'production')),
            'service instance must reject invalid mode ' . json_encode($invalidMode, JSON_THROW_ON_ERROR)
        );
    }
});
