<?php
declare(strict_types=1);

hub_test('Whisper ASR pack has L5 GPU-first runtime files', function (): void {
    $base = HUB_ROOT . '/packs/whisper-asr/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'whisper-asr service missing ' . $file);
    }
    hub_test_assert(is_file(HUB_ROOT . '/packs/whisper-asr/demo/sample.wav'), 'whisper-asr demo sample.wav missing');

    $app = (string)file_get_contents($base . '/app.py');
    hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'whisper-asr app must expose L5 runtime_level');
    hub_test_assert(str_contains($app, '@app.post("/asr/audio")'), 'whisper-asr endpoint mismatch');
    hub_test_assert(str_contains($app, 'run_real_inference'), 'whisper-asr must execute real inference');
    hub_test_assert(!str_contains($app, 'runtime_not_ready'), 'whisper-asr must not retain the L3 runtime_not_ready branch');
    foreach (['configure_cuda_library_path', 'LD_LIBRARY_PATH'] as $needle) {
        hub_test_assert(!str_contains($app, $needle), 'whisper-asr app must not rely on late CUDA loader mutation: ' . $needle);
    }

    $dockerfile = (string)file_get_contents($base . '/Dockerfile');
    foreach (['ct2-nvidia.conf', 'nvidia/cublas/lib', 'nvidia/cudnn/lib', 'ldconfig', 'python3 -m unittest -v test_app.py'] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'whisper-asr Dockerfile missing CUDA loader setup: ' . $needle);
    }

    $manifest = hub_get_pack('whisper-asr')['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'whisper-asr manifest runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'whisper-asr manifest target level mismatch');
    hub_test_assert(($manifest['hardware']['cpu_fallback'] ?? false) === true, 'whisper-asr must retain CPU fallback');
    $gpuEnv = [];
    foreach ($manifest['env'] ?? [] as $item) {
        if (($item['name'] ?? '') === 'USE_GPU') {
            $gpuEnv = $item;
        }
    }
    hub_test_assert(($gpuEnv['default'] ?? '') === '1', 'whisper-asr manifest must request GPUs with USE_GPU=1');

    $sourceCompose = (string)file_get_contents(HUB_ROOT . '/packs/whisper-asr/docker-compose.yml');
    hub_test_assert(str_contains($sourceCompose, 'gpus: all'), 'whisper-asr source compose must request GPUs for direct development');
    hub_test_assert(str_contains($sourceCompose, 'NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-all}"'), 'whisper-asr source compose must default visible GPUs to all');
    hub_test_assert(str_contains($sourceCompose, 'NVIDIA_DRIVER_CAPABILITIES: "compute,utility"'), 'whisper-asr source compose must expose NVIDIA compute and utility capabilities');
    hub_test_assert(!str_contains($sourceCompose, '/DATA/'), 'whisper-asr source compose must not hard-code a host storage path');

    $whisperSchema = hub_get_pack_settings_schema('whisper-asr');
    hub_test_assert(($whisperSchema['USE_GPU']['type'] ?? '') === 'boolean', 'whisper-asr USE_GPU must be a boolean setting');
    hub_test_assert(($whisperSchema['USE_GPU']['default'] ?? '') === '1', 'whisper-asr USE_GPU must default to GPU allocation');
    hub_test_assert(($whisperSchema['USE_GPU']['restart_required'] ?? false) === true, 'whisper-asr USE_GPU must require restart');

    $requirements = (string)file_get_contents($base . '/requirements.txt');
    foreach (['nvidia-cublas-cu12', 'nvidia-cudnn-cu12==9.'] as $needle) {
        hub_test_assert(str_contains($requirements, $needle), 'whisper-asr requirements missing ' . $needle);
    }

    $smoke = (string)file_get_contents($base . '/smoke.py');
    foreach (['fastapi', 'faster_whisper'] as $needle) {
        hub_test_assert(str_contains($smoke, $needle), 'whisper-asr smoke.py missing ' . $needle);
    }
    foreach (['WhisperModel(', 'transcribe', 'download'] as $needle) {
        hub_test_assert(!str_contains($smoke, $needle), 'whisper-asr smoke.py must not load model or transcribe: ' . $needle);
    }

    $storageSmoke = (string)file_get_contents($base . '/storage_smoke.py');
    foreach (['/models/whisper', '/models/whisper/huggingface', '/cache/whisper', '/cache/whisper/xdg', '/cache/whisper/home', '/data/service'] as $needle) {
        hub_test_assert(str_contains($storageSmoke, $needle), 'whisper-asr storage_smoke.py missing ' . $needle);
    }
});

hub_test('Whisper ASR service instance generates GPU compose and gateway response', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'whisper-asr', [
        'service_key' => 'asr-main',
        'mode' => 'asr',
        'name' => 'Whisper ASR Main',
        'port_mode' => 'manual',
        'local_port' => 18107,
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($compose, '127.0.0.1:${ASR_LOCAL_PORT:-18107}:8000'), 'whisper-asr compose port binding mismatch');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/whisper:/models/whisper'), 'whisper-asr compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/whisper:/cache/whisper'), 'whisper-asr compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'whisper-asr compose must mount service data');
    hub_test_assert(str_contains($compose, 'gpus: all'), 'whisper-asr generated compose must request GPUs via USE_GPU=1');
    foreach ([
        'WHISPER_MODEL_DIR=/models/whisper',
        'WHISPER_CACHE_DIR=/cache/whisper',
        'WHISPER_SERVICE_DATA_DIR=/data/service',
        'WHISPER_MODEL=small',
        'WHISPER_DEVICE=auto',
        'WHISPER_COMPUTE_TYPE=auto',
        'WHISPER_REAL_INFERENCE=1',
        'USE_GPU=1',
        'WHISPER_MAX_UPLOAD_MB=100',
        'HF_HOME=/models/whisper/huggingface',
        'XDG_CACHE_HOME=/cache/whisper/xdg',
        'HOME=/cache/whisper/home',
        'PYTHONUNBUFFERED=1',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'whisper-asr env missing ' . $needle);
    }

    $updated = hub_update_service_settings($db, (int)$installed['service']['id'], ['USE_GPU' => '0']);
    hub_test_assert($updated['changed'] === true, 'whisper-asr USE_GPU update must change service settings');
    $cpuEnv = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($cpuEnv, 'USE_GPU=0'), 'whisper-asr USE_GPU update must rewrite env');
    $cpuCompose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(!str_contains($cpuCompose, 'gpus: all'), 'whisper-asr USE_GPU=0 must regenerate a CPU compose');

    $refreshed = hub_refresh_service_runtime_files($db, hub_get_service($db, (int)$installed['service']['id']) ?: $installed['service']);
    $refreshEnv = (string)file_get_contents(dirname(hub_path($refreshed['compose_file'])) . '/.env');
    $refreshCompose = (string)file_get_contents(hub_path($refreshed['compose_file']));
    hub_test_assert(str_contains($refreshEnv, 'USE_GPU=0'), 'whisper-asr refresh must preserve persisted CPU env');
    hub_test_assert(!str_contains($refreshCompose, 'gpus: all'), 'whisper-asr refresh must preserve persisted CPU compose');

    hub_set_service_enabled($db, 'asr', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $oldServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_LENGTH'] = '128';
        $response = hub_gateway_dispatch($db, 'asr', static function (array $service, int $timeoutSec): array {
            hub_test_assert($service['mode'] === 'asr', 'ASR gateway service mismatch');
            hub_test_assert($timeoutSec === 180, 'ASR timeout mismatch');

            return hub_gateway_json(200, [
                'ok' => true,
                'mock' => false,
                'runtime_level' => 'L5-benchmark-ready',
                'language' => 'auto',
                'text' => 'mock transcription',
                'segments' => [],
                'device' => ['requested' => 'auto', 'effective' => 'cuda', 'compute_type' => 'float16', 'fallback_used' => false],
            ]);
        });
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200, 'ASR gateway should pass');
});

hub_test('Whisper ASR install-time CPU override survives non-GPU settings updates', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'whisper-asr', [
        'service_key' => 'asr-cpu',
        'mode' => 'asr_cpu',
        'name' => 'Whisper ASR CPU',
        'port_mode' => 'manual',
        'local_port' => 18108,
        'env' => ['USE_GPU' => '0'],
    ]);
    $service = $installed['service'];

    $settings = hub_list_service_settings($db, (int)$service['id']);
    hub_test_assert(($settings['USE_GPU']['value'] ?? '') === '0', 'whisper-asr install USE_GPU override must seed service settings');
    $envPath = dirname(hub_path($service['compose_file'])) . '/.env';
    hub_test_assert(str_contains((string)file_get_contents($envPath), 'USE_GPU=0'), 'whisper-asr install USE_GPU override must write env');
    $composePath = hub_path($service['compose_file']);
    hub_test_assert(!str_contains((string)file_get_contents($composePath), 'gpus: all'), 'whisper-asr install USE_GPU=0 must generate CPU compose');

    hub_update_service_settings($db, (int)$service['id'], ['WHISPER_MODEL' => 'base']);
    hub_test_assert(!str_contains((string)file_get_contents($composePath), 'gpus: all'), 'non-GPU settings update must preserve Whisper CPU compose');
});
