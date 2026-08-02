<?php
declare(strict_types=1);

function hub_test_service_settings_request(int $serviceId, string $lang): array
{
    $script = "define('HUB_TESTING', true);"
        . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];"
        . "\$_COOKIE = ['USER_LANG' => " . var_export($lang, true) . '];'
        . "\$_SERVER = ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '203.0.113.80'];"
        . '$_GET = ' . var_export(['service_id' => $serviceId], true) . ';'
        . 'require ' . var_export(HUB_ROOT . '/admin/service_settings.php', true) . ';';

    return hub_run_command([PHP_BINARY, '-r', $script], 30, [
        'AIHUB_TEST_DB' => (string)getenv('AIHUB_TEST_DB'),
        'AIHUB_TEST_DATA_DIR' => (string)getenv('AIHUB_TEST_DATA_DIR'),
    ]);
}

hub_test('service settings defaults are created from pack schema and write env', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-settings-main',
        'name' => 'OCR Settings Main',
        'mode' => 'ocr_settings',
        'port_mode' => 'manual',
        'local_port' => 18160,
        'environment' => 'production',
    ]);
    $service = $installed['service'];

    $settings = hub_list_service_settings($db, (int)$service['id']);
    hub_test_assert(isset($settings['OCR_MOCK_TEXT']), 'OCR_MOCK_TEXT setting missing');
    hub_test_assert($settings['OCR_MOCK_TEXT']['value'] === '3waAIHub OCR mock', 'OCR_MOCK_TEXT default mismatch');
    hub_test_assert(isset($settings['OCR_LANG']), 'OCR_LANG setting missing');
    hub_test_assert(isset($settings['OCR_REAL_INFERENCE']), 'OCR_REAL_INFERENCE setting missing');
    hub_test_assert($settings['OCR_REAL_INFERENCE']['value'] === '0', 'OCR_REAL_INFERENCE default mismatch');

    $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
    hub_test_assert(str_contains($env, 'AIHUB_MODELS_DIR='), 'env missing AIHUB_MODELS_DIR');
    hub_test_assert(str_contains($env, 'LOCAL_PORT=18160'), 'env missing LOCAL_PORT');
    hub_test_assert(str_contains($env, 'SERVICE_KEY=ocr-settings-main'), 'env missing SERVICE_KEY');
    hub_test_assert(str_contains($env, 'MODE=ocr_settings'), 'env missing MODE');
    hub_test_assert(str_contains($env, 'OCR_MOCK_TEXT=3waAIHub OCR mock'), 'env missing OCR_MOCK_TEXT');
    hub_test_assert(str_contains($env, 'OCR_REAL_INFERENCE=0'), 'env missing OCR_REAL_INFERENCE');
    hub_test_assert(!str_contains($env, 'UNDECLARED_ENV='), 'env must not include arbitrary keys');
});

hub_test('VoxCPM2 settings default to isolated execution and preserve generated tokens', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-settings-default',
        'mode' => 'voxcpm2_settings_default',
    ]);
    $service = $installed['service'];
    $settings = hub_list_service_settings($db, (int)$service['id']);
    $token = (string)($settings['VOXCPM2_INTERNAL_JOB_TOKEN']['value'] ?? '');

    hub_test_assert(($settings['VOXCPM2_EXECUTION_MODE']['value'] ?? '') === 'isolated', 'VoxCPM2 must default to isolated execution');
    hub_test_assert(($settings['VOXCPM2_IDLE_UNLOAD_SECONDS']['value'] ?? '') === '0', 'VoxCPM2 idle unload must default to zero');
    hub_test_assert(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 'VoxCPM2 internal job token must be 64 lowercase hex characters');
    hub_test_assert(
        (hub_ensure_service_settings($db, $service)['VOXCPM2_INTERNAL_JOB_TOKEN']['value'] ?? '') === $token,
        'VoxCPM2 generated token must remain stable after backfill'
    );

    $update = hub_update_service_settings($db, (int)$service['id'], [
        'VOXCPM2_EXECUTION_MODE' => 'resident',
        'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB' => '2048',
    ]);
    $service = hub_get_service($db, (int)$service['id']);
    hub_test_assert(!empty($update['restart_required']) && (int)($service['restart_required'] ?? 0) === 1, 'resident VoxCPM2 settings must require restart');

    $override = str_repeat('a', 64);
    $overridden = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-settings-override',
        'mode' => 'voxcpm2_settings_override',
        'env' => ['VOXCPM2_INTERNAL_JOB_TOKEN' => $override],
    ])['service'];
    $overriddenSettings = hub_ensure_service_settings($db, $overridden);
    hub_test_assert(($overriddenSettings['VOXCPM2_INTERNAL_JOB_TOKEN']['value'] ?? '') === $override, 'VoxCPM2 install environment override must win over generated token');

    hub_i18n_import_seed($db);
    $page = hub_test_service_settings_request((int)$service['id'], 'en');
    hub_test_assert($page['exit_code'] === 0, 'VoxCPM2 service settings page must render: ' . $page['output']);
    foreach (['Execution mode', 'One-shot container', 'Resident model'] as $label) {
        hub_test_assert(str_contains($page['stdout'], $label), 'translated VoxCPM2 select label missing: ' . $label);
    }
});

hub_test('GPT-SoVITS settings default to isolated execution and preserve generated tokens', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-gpt-sovits', [
        'service_key' => 'gpt-sovits-settings-default',
        'mode' => 'gpt_sovits_settings_default',
    ]);
    $service = $installed['service'];
    $settings = hub_list_service_settings($db, (int)$service['id']);
    $token = (string)($settings['GPT_SOVITS_INTERNAL_JOB_TOKEN']['value'] ?? '');

    hub_test_assert(($settings['GPT_SOVITS_EXECUTION_MODE']['value'] ?? '') === 'isolated', 'GPT-SoVITS must default to isolated execution');
    hub_test_assert(($settings['GPT_SOVITS_IDLE_UNLOAD_SECONDS']['value'] ?? '') === '0', 'GPT-SoVITS idle unload must default to zero');
    hub_test_assert(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 'GPT-SoVITS internal job token must be 64 lowercase hex characters');
    hub_test_assert(
        (hub_ensure_service_settings($db, $service)['GPT_SOVITS_INTERNAL_JOB_TOKEN']['value'] ?? '') === $token,
        'GPT-SoVITS generated token must remain stable after backfill'
    );
    $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
    hub_test_assert(str_contains($env, 'GPT_SOVITS_SERVICE_DATA_DIR=/data/service'), 'GPT-SoVITS service data path missing from env');
    hub_test_assert(str_contains($env, 'GPT_SOVITS_INTERNAL_JOB_TOKEN=' . $token), 'GPT-SoVITS generated token missing from env');
});

hub_test('resident TTS internal job tokens are restored automatically and never required in the form', function (): void {
    $db = hub_test_reset_db();
    $fixtures = [
        ['tts-voxcpm2', 'voxcpm2-settings-token-repair', 'VOXCPM2_EXECUTION_MODE', 'VOXCPM2_INTERNAL_JOB_TOKEN'],
        ['tts-gpt-sovits', 'gpt-sovits-settings-token-repair', 'GPT_SOVITS_EXECUTION_MODE', 'GPT_SOVITS_INTERNAL_JOB_TOKEN'],
    ];

    foreach ($fixtures as [$packId, $serviceKey, $modeKey, $tokenKey]) {
        $service = hub_install_pack($db, $packId, [
            'service_key' => $serviceKey,
            'mode' => str_replace('-', '_', $serviceKey),
        ])['service'];
        $db->prepare('UPDATE service_settings SET value = :value WHERE service_id = :service_id AND key = :key')
            ->execute([':value' => '', ':service_id' => (int)$service['id'], ':key' => $tokenKey]);

        hub_update_service_settings($db, (int)$service['id'], [
            $modeKey => 'resident',
            $tokenKey => '',
        ]);
        $settings = hub_list_service_settings($db, (int)$service['id']);
        hub_test_assert(
            preg_match('/^[a-f0-9]{64}$/D', (string)($settings[$tokenKey]['value'] ?? '')) === 1,
            $packId . ' must restore a blank internal job token during save'
        );

        $page = hub_test_service_settings_request((int)$service['id'], 'zh-TW');
        hub_test_assert($page['exit_code'] === 0, $packId . ' settings page must render');
        hub_test_assert(str_contains($page['stdout'], 'name="' . $tokenKey . '" type="password"'), $packId . ' internal token must be a password field');
        hub_test_assert(!str_contains($page['stdout'], 'name="' . $tokenKey . '" type="password" required'), $packId . ' internal token must not block saving when blank');
        hub_test_assert(str_contains($page['stdout'], '留空則保留既有值'), $packId . ' internal token form must explain blank preservation');
    }
});

hub_test('service settings update validates values writes env and marks restart', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-settings-update',
        'name' => 'OCR Settings Update',
        'mode' => 'ocr_settings_update',
        'port_mode' => 'manual',
        'local_port' => 18161,
        'environment' => 'production',
    ]);
    $service = $installed['service'];

    hub_update_service_settings($db, (int)$service['id'], [
        'OCR_MOCK_TEXT' => 'PhaseP-2 smoke text',
        'OCR_MAX_UPLOAD_MB' => '64',
        'OCR_LANG' => 'en',
        'OCR_USE_GPU' => '0',
        'KEEP_WARM' => '1',
    ]);
    $service = hub_get_service($db, (int)$service['id']);
    hub_test_assert($service !== null && (int)$service['restart_required'] === 1, 'restart_required must be marked');
    hub_test_assert((int)$service['config_dirty'] === 0, 'config_dirty must be clear after env write');

    $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
    hub_test_assert(str_contains($env, 'OCR_MOCK_TEXT=PhaseP-2 smoke text'), 'updated OCR_MOCK_TEXT missing from env');
    hub_test_assert(str_contains($env, 'OCR_MAX_UPLOAD_MB=64'), 'updated OCR_MAX_UPLOAD_MB missing from env');
    hub_test_assert(str_contains($env, 'OCR_LANG=en'), 'updated OCR_LANG missing from env');

    hub_test_assert(hub_test_throws(static fn () => hub_update_service_settings($db, (int)$service['id'], [
        'OCR_MAX_UPLOAD_MB' => 'abc',
    ])), 'invalid integer was accepted');
    hub_test_assert(hub_test_throws(static fn () => hub_update_service_settings($db, (int)$service['id'], [
        'OCR_LANG' => 'invalid_lang',
    ])), 'invalid select was accepted');
});

hub_test('service settings override pack runtime env defaults when writing env', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'structure-ppstructurev3', [
        'service_key' => 'structure-settings-update',
        'name' => 'Structure Settings Update',
        'mode' => 'structure_settings_update',
        'port_mode' => 'manual',
        'local_port' => 18162,
    ]);
    $service = $installed['service'];

    hub_update_service_settings($db, (int)$service['id'], [
        'STRUCTURE_DEVICE' => 'gpu',
        'STRUCTURE_MAX_UPLOAD_MB' => '512',
    ]);
    $service = hub_get_service($db, (int)$service['id']);
    hub_test_assert($service !== null, 'updated structure service missing');

    $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
    hub_test_assert(str_contains($env, 'STRUCTURE_DEVICE=gpu'), 'service setting must override runtime env default');
    hub_test_assert(!str_contains($env, 'STRUCTURE_DEVICE=cpu'), 'runtime env default must not shadow updated service setting');
    hub_test_assert(str_contains($env, 'STRUCTURE_MAX_UPLOAD_MB=512'), 'updated structure upload limit missing from env');
});

hub_test('install environment overrides seed declared GPU settings', function (): void {
    $db = hub_test_reset_db();
    $nemotron = hub_install_pack($db, 'rag-nemotron', [
        'service_key' => 'nemotron-settings-cpu',
        'name' => 'Nemotron Settings CPU',
        'mode' => 'nemotron_settings_cpu',
        'port_mode' => 'manual',
        'local_port' => 18163,
        'env' => ['NEMOTRON_USE_GPU' => '0'],
    ]);
    $yolo = hub_install_pack($db, 'yolo', [
        'service_key' => 'yolo-settings-gpu',
        'name' => 'YOLO Settings GPU',
        'mode' => 'yolo_settings_gpu',
        'port_mode' => 'manual',
        'local_port' => 18164,
        'env' => ['YOLO_USE_GPU' => '1'],
    ]);

    foreach ([
        [$nemotron['service'], 'NEMOTRON_USE_GPU', '0', false],
        [$yolo['service'], 'YOLO_USE_GPU', '1', true],
    ] as [$service, $key, $value, $usesGpu]) {
        $storedOverrides = json_decode((string)$service['environment_json'], true);
        hub_test_assert(is_array($storedOverrides) && ($storedOverrides[$key] ?? '') === $value, $service['pack_id'] . ' must persist the validated install override');
        $settings = hub_list_service_settings($db, (int)$service['id']);
        hub_test_assert(($settings[$key]['value'] ?? '') === $value, $service['pack_id'] . ' setting must honor install override');
        $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
        $compose = (string)file_get_contents(hub_path($service['compose_file']));
        hub_test_assert(str_contains($env, $key . '=' . $value), $service['pack_id'] . ' env must honor install override');
        hub_test_assert(str_contains($compose, 'gpus: all') === $usesGpu, $service['pack_id'] . ' compose must honor install override');
    }
});

hub_test('legacy GPU service settings backfill keeps GPU-special defaults', function (): void {
    $db = hub_test_reset_db();
    $yolo = hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-gpu0',
        'name' => 'YOLO GPU Legacy Backfill',
        'mode' => 'yolo_gpu_legacy_backfill',
        'port_mode' => 'manual',
        'local_port' => 18165,
    ]);
    $ocr = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-gpu',
        'name' => 'OCR GPU Legacy Backfill',
        'mode' => 'ocr_gpu_legacy_backfill',
        'port_mode' => 'manual',
        'local_port' => 18166,
    ]);

    foreach ([$yolo['service'], $ocr['service']] as $service) {
        $pack = hub_get_pack((string)$service['pack_id']);
        hub_test_assert($pack !== null, 'legacy pack must be available');
        $db->prepare('UPDATE services SET environment_json = :environment_json WHERE id = :id')->execute([
            ':environment_json' => json_encode(hub_pack_env_values($pack['manifest']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':id' => (int)$service['id'],
        ]);
        $db->prepare('DELETE FROM service_settings WHERE service_id = :service_id')->execute([':service_id' => (int)$service['id']]);
    }

    $yoloSettings = hub_ensure_service_settings($db, hub_get_service($db, (int)$yolo['service']['id']) ?: $yolo['service']);
    hub_test_assert(($yoloSettings['YOLO_SERVING_DEVICE']['value'] ?? '') === 'cuda:0', 'legacy yolo-gpu0 backfill must retain CUDA device');
    hub_test_assert(($yoloSettings['YOLO_GPU_SLOTS']['value'] ?? '') === '2', 'legacy yolo-gpu0 backfill must retain GPU slots');

    $ocrSettings = hub_ensure_service_settings($db, hub_get_service($db, (int)$ocr['service']['id']) ?: $ocr['service']);
    hub_test_assert(($ocrSettings['OCR_DEVICE']['value'] ?? '') === 'gpu', 'legacy ocr-gpu backfill must retain GPU device');
    hub_test_assert(($ocrSettings['OCR_USE_GPU']['value'] ?? '') === '1', 'legacy ocr-gpu backfill must retain GPU enablement');
});

hub_test('mixed legacy GPU snapshots preserve changed settings and GPU-special defaults', function (): void {
    $db = hub_test_reset_db();
    $yolo = hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-gpu0',
        'name' => 'YOLO GPU Mixed Legacy Backfill',
        'mode' => 'yolo_gpu_mixed_legacy_backfill',
        'port_mode' => 'manual',
        'local_port' => 18167,
    ]);
    $ocr = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-gpu',
        'name' => 'OCR GPU Mixed Legacy Backfill',
        'mode' => 'ocr_gpu_mixed_legacy_backfill',
        'port_mode' => 'manual',
        'local_port' => 18168,
    ]);

    foreach ([
        [$yolo['service'], ['YOLO_SERVING_REAL_INFERENCE' => '0']],
        [$ocr['service'], ['OCR_REAL_INFERENCE' => '1']],
    ] as [$service, $changedValues]) {
        $pack = hub_get_pack((string)$service['pack_id']);
        hub_test_assert($pack !== null, 'legacy pack must be available');
        $environment = array_merge(hub_pack_env_values($pack['manifest']), $changedValues);
        $db->prepare('UPDATE services SET environment_json = :environment_json WHERE id = :id')->execute([
            ':environment_json' => json_encode($environment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ':id' => (int)$service['id'],
        ]);
        $db->prepare('DELETE FROM service_settings WHERE service_id = :service_id')->execute([':service_id' => (int)$service['id']]);
    }

    $yoloSettings = hub_ensure_service_settings($db, hub_get_service($db, (int)$yolo['service']['id']) ?: $yolo['service']);
    hub_test_assert(($yoloSettings['YOLO_SERVING_REAL_INFERENCE']['value'] ?? '') === '0', 'mixed legacy yolo setting must persist');
    hub_test_assert(($yoloSettings['YOLO_SERVING_DEVICE']['value'] ?? '') === 'cuda:0', 'mixed legacy yolo-gpu0 backfill must retain CUDA device');
    hub_test_assert(($yoloSettings['YOLO_GPU_SLOTS']['value'] ?? '') === '2', 'mixed legacy yolo-gpu0 backfill must retain GPU slots');

    $ocrSettings = hub_ensure_service_settings($db, hub_get_service($db, (int)$ocr['service']['id']) ?: $ocr['service']);
    hub_test_assert(($ocrSettings['OCR_REAL_INFERENCE']['value'] ?? '') === '1', 'mixed legacy OCR setting must persist');
    hub_test_assert(($ocrSettings['OCR_DEVICE']['value'] ?? '') === 'gpu', 'mixed legacy ocr-gpu backfill must retain GPU device');
    hub_test_assert(($ocrSettings['OCR_USE_GPU']['value'] ?? '') === '1', 'mixed legacy ocr-gpu backfill must retain GPU enablement');
});

hub_test('service settings validate unsafe path and backfill legacy service', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $db->prepare('DELETE FROM service_settings WHERE service_id = :service_id')->execute([':service_id' => (int)$service['id']]);

    $settings = hub_ensure_service_settings($db, $service);
    hub_test_assert(isset($settings['HELLO_MESSAGE']), 'legacy defaults were not backfilled');
    hub_test_assert($settings['HELLO_MESSAGE']['value'] === '3waAIHub service is running', 'legacy default mismatch');
    $unsafePath = hub_platform_id() === 'windows' ? (string)getenv('SystemRoot') : '/etc';
    hub_test_assert($unsafePath !== '', 'platform system directory is unavailable');
    hub_test_assert(hub_test_throws(static fn () => hub_validate_service_setting_value([
        'key' => 'MODEL_DIR',
        'type' => 'path',
        'required' => true,
    ], $unsafePath)), 'unsafe path was accepted');
    hub_test_assert(hub_test_throws(static fn () => hub_update_service_settings($db, (int)$service['id'], [
        'UNDECLARED_ENV' => 'x',
    ])), 'arbitrary setting key was accepted');
});
