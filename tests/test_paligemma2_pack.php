<?php
declare(strict_types=1);

function hub_test_paligemma2_wsl_payload(array $command): string
{
    $script = (string)end($command);
    if (preg_match('/printf %s ([A-Za-z0-9+\\/=]+) \\| base64 -d \\| bash/', $script, $matches) !== 1) {
        throw new RuntimeException('PaliGemma 2 WSL command payload is missing.');
    }

    $payload = base64_decode($matches[1], true);
    if ($payload === false) {
        throw new RuntimeException('PaliGemma 2 WSL command payload is invalid.');
    }

    return $payload;
}

function hub_test_paligemma2_wsl_compose_payload(string $script): string
{
    if (preg_match("/compose_payload='([A-Za-z0-9+\\/=]+)'/", $script, $matches) !== 1) {
        throw new RuntimeException('PaliGemma 2 WSL compose payload is missing.');
    }

    $payload = base64_decode($matches[1], true);
    if ($payload === false) {
        throw new RuntimeException('PaliGemma 2 WSL compose payload is invalid.');
    }

    return $payload;
}

hub_test('PaliGemma 2 publishes an honest pre-acceptance Pack contract', function (): void {
    $pack = hub_get_pack('vlm-paligemma2');
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'PaliGemma 2 Pack is missing or invalid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L2-deps-import', 'PaliGemma 2 must not claim L5 before real acceptance');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'PaliGemma 2 target level mismatch');
    hub_test_assert(($manifest['runtime_ready'] ?? true) === false, 'PaliGemma 2 must remain not-ready before real model acceptance');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'paligemma2', 'PaliGemma 2 public mode mismatch');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? false) === true, 'PaliGemma 2 must require its declared GPU runtime');
    hub_test_assert(($manifest['hardware']['cpu_fallback'] ?? true) === false, 'PaliGemma 2 must not silently fall back to CPU');
    hub_test_assert(is_file(HUB_ROOT . '/packs/vlm-paligemma2/README.md'), 'PaliGemma 2 must document its pre-acceptance boundary');
    hub_test_assert(is_file(HUB_ROOT . '/packs/vlm-paligemma2/runtime-settings.example.conf'), 'PaliGemma 2 runtime settings template is missing');
    hub_test_assert(!file_exists(HUB_ROOT . '/packs/vlm-paligemma2/.env.example'), 'PaliGemma 2 must not publish a legacy .env template');
    $gpuSmoke = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/service/gpu_smoke.py');
    hub_test_assert(str_contains($gpuSmoke, 'required = True'), 'PaliGemma 2 GPU smoke must require a GPU unconditionally');
    hub_test_assert(!str_contains($gpuSmoke, 'PALIGEMMA2_GPU_REQUIRED'), 'PaliGemma 2 GPU requirement must not be disabled through an environment override');

    $app = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/service/app.py');
    hub_test_assert(str_contains($app, 'return "L2-deps-import"'), 'PaliGemma 2 health must report its actual pre-acceptance level');
    hub_test_assert(str_contains($app, '"runtime_ready": False'), 'PaliGemma 2 health must expose not-ready state');
    hub_test_assert(str_contains($app, 'env_enabled(real_inference) and env_enabled(os.getenv("PALIGEMMA2_REAL_INFERENCE"))'), 'PaliGemma 2 real inference must require both request and runtime opt-in');

    $pascalDockerfile = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/service/Dockerfile.pascal-cu118');
    $defaultDockerfile = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/service/Dockerfile');
    $pascalRequirements = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/service/requirements.pascal-cu118.txt');
    hub_test_assert(str_contains($pascalDockerfile, '--index-url https://download.pytorch.org/whl/cu118') && str_contains($pascalDockerfile, 'torch==2.6.0'), 'PaliGemma 2 Pascal image must pin the official CUDA 11.8 Torch wheel');
    hub_test_assert(str_contains($defaultDockerfile, '--index-url https://download.pytorch.org/whl/cu126') && str_contains($defaultDockerfile, 'torch==2.6.0'), 'PaliGemma 2 default image must pin its CUDA 12.6 Torch wheel');
    hub_test_assert(!str_contains($pascalRequirements, 'torch>='), 'PaliGemma 2 Pascal requirements must not override the selected CUDA 11.8 Torch wheel');
    hub_test_assert(!str_contains($pascalRequirements, 'bitsandbytes'), 'PaliGemma 2 pre-acceptance runtime must not install an unused quantization dependency');

    $composeTemplate = (string)file_get_contents(HUB_ROOT . '/packs/vlm-paligemma2/docker-compose.yml');
    hub_test_assert(str_contains($composeTemplate, 'context: service') && str_contains($composeTemplate, 'dockerfile: Dockerfile'), 'PaliGemma 2 tracked compose template must build from the service source directory');
});

hub_test('PaliGemma 2 real inference uses a pinned local gated-model snapshot', function (): void {
    $packDir = HUB_ROOT . '/packs/vlm-paligemma2';
    $manifest = json_decode((string)file_get_contents($packDir . '/pack.json'), true, 512, JSON_THROW_ON_ERROR);
    $schema = hub_get_pack_settings_schema('vlm-paligemma2');
    $app = (string)file_get_contents($packDir . '/service/app.py');

    hub_test_assert(($schema['PALIGEMMA2_MODEL']['default'] ?? '') === 'google/paligemma2-3b-pt-224', 'PaliGemma 2 must select the verified 3B 224 model');
    hub_test_assert(($schema['PALIGEMMA2_MODEL_REVISION']['default'] ?? '') === '96eeb174da13ca1a2b247e4d0867436296c36420', 'PaliGemma 2 must pin its model revision');
    hub_test_assert(($schema['HF_TOKEN']['type'] ?? '') === 'secret', 'PaliGemma 2 gated model token must use the secret setting boundary');
    hub_test_assert(!empty($schema['HF_TOKEN']['required']), 'PaliGemma 2 gated model token must be explicitly required for provisioning');
    hub_test_assert(!empty($schema['PALIGEMMA2_REAL_INFERENCE']['restart_required']), 'PaliGemma 2 real-inference opt-in must recreate the runtime so the new environment reaches the container');
    hub_test_assert(is_file($packDir . '/service/provision.py'), 'PaliGemma 2 must provide an explicit model provisioner');
    hub_test_assert(str_contains($app, 'local_files_only=True'), 'PaliGemma 2 API must never download a model during inference');
    hub_test_assert(str_contains($app, '"<image>"'), 'PaliGemma 2 inference prompt must include the image token');
    hub_test_assert(!str_contains($app, 'PALIGEMMA2_LOAD_IN_4BIT'), 'PaliGemma 2 Pascal runtime must not claim an unverified quantization fallback');
    hub_test_assert(($manifest['runtime_ready'] ?? true) === false, 'PaliGemma 2 must remain pre-acceptance until real GPU inference is recorded');
});

hub_test('PaliGemma 2 exposes a controlled explicit model provision command', function (): void {
    $runner = (string)file_get_contents(HUB_ROOT . '/app/docker_runner.php');
    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/command_worker.php');
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');

    hub_test_assert(hub_is_valid_job_action('paligemma2_provision'), 'PaliGemma 2 provision command must be allowlisted');
    hub_test_assert(str_contains($runner, 'function hub_run_paligemma2_provision_job'), 'PaliGemma 2 must own an explicit provision command runner');
    hub_test_assert(str_contains($runner, "['run', '--rm', '--no-deps', 'adapter', 'python3', '/app/provision.py']"), 'PaliGemma 2 provision command must run only its controlled provisioner');
    hub_test_assert(str_contains($worker, "'paligemma2_provision' => hub_run_paligemma2_provision_job"), 'command worker must dispatch PaliGemma 2 provision jobs');
    hub_test_assert(str_contains($marketplace, "'provision_paligemma2' => 'paligemma2_provision'"), 'Marketplace must expose PaliGemma 2 provisioning');
    hub_test_assert(str_contains($marketplace, 'hub_paligemma2_provisioning_plan($service)'), 'Marketplace must not offer PaliGemma 2 provisioning on an unsupported runtime');
});

hub_test('PaliGemma 2 exposes a fixture-only CUDA acceptance command', function (): void {
    $runner = (string)file_get_contents(HUB_ROOT . '/app/docker_runner.php');
    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/command_worker.php');
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');

    hub_test_assert(hub_is_valid_job_action('paligemma2_acceptance'), 'PaliGemma 2 CUDA acceptance command must be allowlisted');
    hub_test_assert(str_contains($runner, 'function hub_run_paligemma2_acceptance_job'), 'PaliGemma 2 must own its real inference acceptance runner');
    hub_test_assert(str_contains($runner, '/app/acceptance.py'), 'PaliGemma 2 acceptance must invoke only the checked-in acceptance entrypoint');
    hub_test_assert(str_contains($runner, '/fixture/sample.png:ro'), 'PaliGemma 2 acceptance must mount only the fixed Pack fixture read-only');
    hub_test_assert(str_contains($worker, "'paligemma2_acceptance' => hub_run_paligemma2_acceptance_job"), 'command worker must dispatch PaliGemma 2 acceptance jobs');
    hub_test_assert(str_contains($marketplace, "'accept_paligemma2' => 'paligemma2_acceptance'"), 'Marketplace must expose PaliGemma 2 CUDA acceptance');
});

hub_test('PaliGemma 2 CUDA acceptance fixture is a non-ambiguous RGB image', function (): void {
    $fixture = HUB_ROOT . '/packs/vlm-paligemma2/demo/sample.png';
    $bytes = (string)file_get_contents($fixture);
    $header = unpack('Nwidth/Nheight/Cbit_depth/Ccolor_type', substr($bytes, 16, 10));

    hub_test_assert(is_array($header), 'PaliGemma 2 acceptance fixture must contain a PNG IHDR header');
    hub_test_assert(($header['width'] ?? 0) >= 224 && ($header['height'] ?? 0) >= 224, 'PaliGemma 2 acceptance fixture must not be a placeholder-sized image');
    hub_test_assert(in_array($header['color_type'] ?? -1, [2, 6], true), 'PaliGemma 2 acceptance fixture must use RGB or RGBA pixels');
});

hub_test('PaliGemma 2 Windows WSL compose selects the Pascal CUDA 11.8 profile', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'vlm-paligemma2', [
        'service_key' => 'vlm-paligemma2-main',
        'mode' => 'vision',
        'name' => 'PaliGemma 2 Main',
        'port_mode' => 'manual',
        'local_port' => 18105,
    ]);
    $installed = hub_install_pack($db, 'vlm-paligemma2', ['idempotent' => true, 'provision_runner' => false]);
    $service = $installed['service'];
    hub_test_assert(($service['mode'] ?? '') === 'paligemma2', 'PaliGemma 2 idempotent refresh must migrate the legacy vision mode');
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
        'pack_profiles' => ['vlm-paligemma2' => 'pascal-cu118'],
    ]]];

    hub_test_assert(function_exists('hub_paligemma2_wsl_runtime_profile'), 'PaliGemma 2 must define a dedicated WSL runtime selector');
    hub_test_assert(function_exists('hub_paligemma2_wsl_service_compose_command'), 'PaliGemma 2 must define a dedicated WSL compose command');

    $runtime = hub_paligemma2_wsl_runtime_profile($service, $profile);
    $command = hub_wsl_service_compose_command($service, ['build', '--progress=plain'], $profile);
    $script = hub_test_paligemma2_wsl_payload($command);
    $compose = hub_test_paligemma2_wsl_compose_payload($script);

    hub_test_assert(($runtime['profile_id'] ?? '') === 'pascal-cu118', 'GTX 1080 must select PaliGemma 2 Pascal CUDA 11.8');
    hub_test_assert(hub_service_runtime_image_tag($service, $profile) === '3waaihub/vlm-paligemma2:0.1.0-pascal-cu118', 'PaliGemma 2 image tag must use Pascal CUDA 11.8');
    foreach ([
        'context: "/DATA/3waAIHub-runtime/packs/vlm-paligemma2/service"',
        'dockerfile: "Dockerfile.pascal-cu118"',
        '3waaihub/vlm-paligemma2:0.1.0-pascal-cu118',
        'gpus: all',
        'NVIDIA_VISIBLE_DEVICES: "all"',
        '/DATA/models/paligemma2:/models/paligemma2',
        '/DATA/3waAIHub-runtime/cache/paligemma2:/cache/paligemma2',
        '/DATA/3waAIHub-runtime/services/vlm-paligemma2-main/data:/data/service',
    ] as $needle) {
        hub_test_assert(str_contains($compose, $needle), 'PaliGemma 2 Pascal WSL compose missing ' . $needle);
    }
    hub_test_assert(str_contains($script, "DOCKER_BUILDKIT=1 docker build --progress=plain --tag '3waaihub/vlm-paligemma2:0.1.0-pascal-cu118'"), 'PaliGemma 2 Pascal build must use BuildKit and selected image');
    hub_test_assert(str_contains($script, "--file '/DATA/3waAIHub-runtime/packs/vlm-paligemma2/service/Dockerfile.pascal-cu118'"), 'PaliGemma 2 Pascal build must select the CUDA 11.8 Dockerfile');
    hub_test_assert(str_contains($script, "'/DATA/3waAIHub-runtime/packs/vlm-paligemma2/service'"), 'PaliGemma 2 Pascal build context must be the Dockerfile service directory');
});

hub_test('PaliGemma 2 is available as an explicit API Playground mode', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(in_array('paligemma2', hub_playground_supported_modes(), true), 'PaliGemma 2 must be allowed in the Playground mode filter');
    foreach ([
        "'paligemma2' => ['label' => 'PaliGemma 2'",
        'api.php?mode=paligemma2',
        'name="paligemma2_prompt"',
        '文件內容是什麼？',
    ] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'PaliGemma 2 Playground is missing ' . $needle);
    }
});
