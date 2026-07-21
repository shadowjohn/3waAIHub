<?php
declare(strict_types=1);

hub_test('Whisper ASR pack has storage mount runtime files', function (): void {
    $base = HUB_ROOT . '/packs/whisper-asr/service';
    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py'] as $file) {
        hub_test_assert(is_file($base . '/' . $file), 'whisper-asr service missing ' . $file);
    }
    hub_test_assert(is_file(HUB_ROOT . '/packs/whisper-asr/demo/sample.wav'), 'whisper-asr demo sample.wav missing');

    $app = (string)file_get_contents($base . '/app.py');
    hub_test_assert(str_contains($app, 'return "L3-storage-mount"'), 'whisper-asr app must expose L3 runtime_level');
    hub_test_assert(str_contains($app, '@app.post("/asr/audio")'), 'whisper-asr endpoint mismatch');
    hub_test_assert(str_contains($app, 'runtime_not_ready'), 'whisper-asr real inference must stay disabled');

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

hub_test('Whisper ASR service instance generates env compose and gateway mock', function (): void {
    $db = hub_test_reset_db();
    $built = false;
    $commands = [];
    $installed = hub_install_pack($db, 'whisper-asr', [
        'service_key' => 'asr-main',
        'mode' => 'asr',
        'name' => 'Whisper ASR Main',
        'port_mode' => 'manual',
        'local_port' => 18107,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$built, &$commands): array {
            $commands[] = $command;
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                return $built
                    ? ['exit_code' => 0, 'stdout' => 'sha256:test-whisper-asr', 'stderr' => '']
                    : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such image'];
            }
            if (($command[1] ?? '') === 'build') {
                $built = true;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected Whisper runner image lifecycle command');
        },
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/.env');
    hub_test_assert(str_contains($compose, '127.0.0.1:${ASR_LOCAL_PORT:-18107}:8000'), 'whisper-asr compose port binding mismatch');
    hub_test_assert(str_contains($compose, 'context: ' . HUB_ROOT . '/packs/whisper-asr') && str_contains($compose, 'dockerfile: service/Dockerfile'), 'whisper-asr generated compose must include its controlled Pack job launcher');
    hub_test_assert(str_contains($compose, 'image: 3waaihub/whisper-asr:0.1.0'), 'whisper-asr service image must match the generic Pack runner image');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/whisper:/models/whisper'), 'whisper-asr compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/whisper:/cache/whisper'), 'whisper-asr compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'whisper-asr compose must mount service data');
    hub_test_assert($built && in_array(['docker', 'build', '--tag', '3waaihub/whisper-asr:0.1.0', '--file', HUB_ROOT . '/packs/whisper-asr/service/Dockerfile', HUB_ROOT . '/packs/whisper-asr'], $commands, true), 'install must build and verify the declared generic Pack runner image');
    foreach ([
        'WHISPER_MODEL_DIR=/models/whisper',
        'WHISPER_CACHE_DIR=/cache/whisper',
        'WHISPER_SERVICE_DATA_DIR=/data/service',
        'WHISPER_MODEL=small',
        'WHISPER_DEVICE=auto',
        'WHISPER_COMPUTE_TYPE=int8',
        'WHISPER_REAL_INFERENCE=0',
        'WHISPER_MAX_UPLOAD_MB=100',
        'HF_HOME=/models/whisper/huggingface',
        'XDG_CACHE_HOME=/cache/whisper/xdg',
        'HOME=/cache/whisper/home',
        'PYTHONUNBUFFERED=1',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'whisper-asr env missing ' . $needle);
    }

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
                'mock' => true,
                'runtime_level' => 'L3-storage-mount',
                'language' => 'auto',
                'text' => 'mock transcription',
                'segments' => [],
                'device' => ['requested' => 'auto', 'effective' => 'mock'],
            ]);
        });
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200, 'ASR gateway mock should pass');
});
