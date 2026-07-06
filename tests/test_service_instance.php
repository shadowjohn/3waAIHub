<?php
declare(strict_types=1);

hub_test('service instance uniqueness checks reject collisions', function (): void {
    $db = hub_test_reset_db();

    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-main',
        'name' => 'OCR Test Main',
        'mode' => 'ocr_test',
        'port_mode' => 'manual',
        'local_port' => 18150,
        'environment' => 'production',
    ]);

    hub_test_assert(str_contains($installed['service']['compose_file'], 'data/test_services/'), 'test DB runtime files must not be written to production services dir');
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(str_contains($compose, 'gpus: all'), 'OCR generated compose must request GPU access');
    hub_test_assert(str_contains($compose, 'NVIDIA_VISIBLE_DEVICES'), 'OCR generated compose must set NVIDIA_VISIBLE_DEVICES');
    hub_test_assert(str_contains($compose, '/models/paddleocr'), 'OCR generated compose must mount model storage');

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-main',
        'name' => 'Duplicate Key',
        'mode' => 'ocr_test_key',
        'port_mode' => 'manual',
        'local_port' => 18151,
        'environment' => 'production',
    ])), 'duplicate service_key was accepted');

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-mode',
        'name' => 'Duplicate Mode',
        'mode' => 'ocr_test',
        'port_mode' => 'manual',
        'local_port' => 18152,
        'environment' => 'production',
    ])), 'duplicate mode was accepted');

    hub_test_assert(hub_test_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-test-port',
        'name' => 'Duplicate Port',
        'mode' => 'ocr_test_port',
        'port_mode' => 'manual',
        'local_port' => 18150,
        'environment' => 'production',
    ])), 'duplicate local_port was accepted');
});
