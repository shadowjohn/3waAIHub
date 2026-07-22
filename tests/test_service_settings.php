<?php
declare(strict_types=1);

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
