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

hub_test('service settings validate unsafe path and backfill legacy service', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $db->prepare('DELETE FROM service_settings WHERE service_id = :service_id')->execute([':service_id' => (int)$service['id']]);

    $settings = hub_ensure_service_settings($db, $service);
    hub_test_assert(isset($settings['HELLO_MESSAGE']), 'legacy defaults were not backfilled');
    hub_test_assert($settings['HELLO_MESSAGE']['value'] === '3waAIHub service is running', 'legacy default mismatch');
    hub_test_assert(hub_test_throws(static fn () => hub_validate_service_setting_value([
        'key' => 'MODEL_DIR',
        'type' => 'path',
        'required' => true,
    ], '/etc')), 'unsafe path was accepted');
    hub_test_assert(hub_test_throws(static fn () => hub_update_service_settings($db, (int)$service['id'], [
        'UNDECLARED_ENV' => 'x',
    ])), 'arbitrary setting key was accepted');
});
