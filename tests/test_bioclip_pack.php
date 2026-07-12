<?php
declare(strict_types=1);

hub_test('BioCLIP pack has L5 benchmark-ready runtime files', function (): void {
    $pack = hub_get_pack('bioclip');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'bioclip pack missing or invalid');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'BioCLIP runtime level mismatch');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'bioclip', 'BioCLIP default mode mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/classify/image', 'BioCLIP endpoint mismatch');
    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(is_array($contract) && $contract !== [], 'BioCLIP l5_contract missing');
    foreach (['endpoint', 'method', 'content_type', 'input', 'output', 'errors', 'benchmark'] as $field) {
        hub_test_assert(array_key_exists($field, $contract), 'BioCLIP l5_contract missing ' . $field);
    }
    $cases = array_column($contract['benchmark']['cases'] ?? [], 'id');
    hub_test_assert(in_array('bioclip_mock_image', $cases, true), 'BioCLIP mock benchmark missing');
    hub_test_assert(in_array('bioclip_real_image', $cases, true), 'BioCLIP real benchmark missing');

    $base = HUB_ROOT . '/packs/bioclip/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py', 'model_smoke.py', 'inference_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'bioclip service missing ' . $file);
    }

    $app = (string)file_get_contents($base . '/app.py');
    hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'BioCLIP app must expose L5 runtime_level');
    hub_test_assert(str_contains($app, '@app.post("/classify/image")'), 'BioCLIP classify endpoint missing');
    hub_test_assert(str_contains($app, 'open_clip.create_model_and_transforms'), 'BioCLIP real inference must use OpenCLIP');

    $storageSmoke = (string)file_get_contents($base . '/storage_smoke.py');
    foreach (['/models/bioclip', '/models/bioclip/huggingface', '/cache/bioclip', '/cache/bioclip/xdg', '/cache/bioclip/home', '/data/service'] as $needle) {
        hub_test_assert(str_contains($storageSmoke, $needle), 'BioCLIP storage_smoke.py missing ' . $needle);
    }

    $requirements = (string)file_get_contents($base . '/requirements.txt');
    hub_test_assert(str_contains($requirements, 'open_clip_torch'), 'BioCLIP requirements must include open_clip_torch');
    hub_test_assert(str_contains($requirements, 'torch'), 'BioCLIP requirements must include torch');
});

hub_test('BioCLIP service instance generates storage env compose and gateway mock', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'bioclip', [
        'service_key' => 'bioclip-main',
        'mode' => 'bioclip',
        'name' => 'BioCLIP Main',
        'port_mode' => 'manual',
        'local_port' => 18111,
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($compose, '127.0.0.1:${BIOCLIP_LOCAL_PORT:-18111}:8000'), 'BioCLIP compose port binding mismatch');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/bioclip:/models/bioclip'), 'BioCLIP compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/bioclip:/cache/bioclip'), 'BioCLIP compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'BioCLIP compose must mount service data');
    foreach ([
        'BIOCLIP_MODEL_DIR=/models/bioclip',
        'BIOCLIP_CACHE_DIR=/cache/bioclip',
        'BIOCLIP_SERVICE_DATA_DIR=/data/service',
        'BIOCLIP_REAL_INFERENCE=1',
        'BIOCLIP_MODEL=hf-hub:imageomics/bioclip',
        'BIOCLIP_DEVICE=cuda',
        'HF_HOME=/models/bioclip/huggingface',
        'XDG_CACHE_HOME=/cache/bioclip/xdg',
        'HOME=/cache/bioclip/home',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'BioCLIP env missing ' . $needle);
    }

    hub_set_service_enabled($db, 'bioclip', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $oldServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_LENGTH'] = '128';
        $response = hub_gateway_dispatch($db, 'bioclip', static function (array $service, int $timeoutSec): array {
            hub_test_assert($service['mode'] === 'bioclip', 'BioCLIP gateway service mismatch');
            hub_test_assert($timeoutSec === 180, 'BioCLIP timeout mismatch');

            return hub_gateway_json(200, [
                'ok' => true,
                'mock' => false,
                'runtime_level' => 'L5-benchmark-ready',
                'labels' => [['label' => 'mock species', 'score' => 1.0]],
            ]);
        });
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200, 'BioCLIP gateway mock should pass');
});
