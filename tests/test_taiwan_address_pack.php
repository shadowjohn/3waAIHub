<?php
declare(strict_types=1);

hub_test('Taiwan address pack keeps trusted upstream and quality contracts explicit', function (): void {
    $pack = hub_get_pack('taiwan-address');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'Taiwan address pack must validate');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['default_mode'] ?? '') === 'taiwan_address', 'Taiwan address mode mismatch');
    hub_test_assert(($manifest['gateway']['timeout_sec'] ?? 0) === 15, 'Taiwan address timeout mismatch');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? true) === false, 'Taiwan address must not require GPU');
    hub_test_assert(($manifest['platform_targets']['linux-docker']['supported'] ?? false) === true, 'Taiwan address must declare Linux Docker target');
    hub_test_assert(($manifest['platform_targets']['windows-wsl2-linux-docker']['supported'] ?? false) === true, 'Taiwan address must declare its explicit WSL target');
    hub_test_assert(!empty($manifest['runtime']['windows_wsl_compose']), 'Taiwan address must opt in to the WSL compose transport');
    $wslProfile = ['runtime_targets' => ['windows-wsl2-linux-docker' => ['supported' => true, 'distro' => 'Ubuntu-24.04', 'runtime_root' => '/DATA/3waAIHub-runtime']]];
    hub_test_assert(hub_service_uses_wsl_runtime(['pack_id' => 'taiwan-address'], 'windows', $wslProfile), 'Taiwan address must select WSL only from the explicit readiness profile');
    hub_test_assert(!hub_service_uses_wsl_runtime(['pack_id' => 'yolo'], 'windows', $wslProfile), 'ordinary Docker Packs must not inherit Taiwan address WSL transport');
    foreach (['result_label', 'quality_flag', 'include_in_coverage', 'geo_check_status', 'geo_warning_code'] as $field) {
        hub_test_assert(in_array($field, $manifest['l5_contract']['output']['quality_fields'] ?? [], true), 'Taiwan address quality field missing: ' . $field);
    }

    $router = (string)file_get_contents(HUB_ROOT . '/packs/taiwan-address/service/router.php');
    foreach (['TWADDR_UPSTREAM_URL', 'TWADDR_OPERATIONS', 'operation_not_allowed', 'getAddress_XY', 'searchOpenData'] as $needle) {
        hub_test_assert(str_contains($router, $needle), 'Taiwan address router missing ' . $needle);
    }
    hub_test_assert(!str_contains($router, "['url']"), 'Taiwan address router must not accept caller-controlled upstream URL');

    $acceptance = HUB_ROOT . '/packs/taiwan-address/acceptance/gateway_acceptance.php';
    hub_test_assert(is_file($acceptance), 'Taiwan address gateway acceptance script missing');
    $acceptanceSource = (string)file_get_contents($acceptance);
    foreach (['HUB_TEST_DATA_DIR_ACTIVE', 'hub_create_api_token', 'Authorization: Bearer', "'idempotent' => true", 'getAddress_XY', 'searchAlias', 'api_access_logs', 'status_code'] as $needle) {
        hub_test_assert(str_contains($acceptanceSource, $needle), 'Taiwan address gateway acceptance missing ' . $needle);
    }

    $examples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    foreach (['## POST Taiwan Address', 'mode=taiwan_address', 'TWADDR_UPSTREAM_URL', 'operation_not_allowed', 'quality_flag'] as $needle) {
        hub_test_assert(str_contains($examples, $needle), 'Taiwan address API example missing ' . $needle);
    }

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(in_array('taiwan_address', hub_playground_supported_modes(), true), 'Taiwan address mode must be visible in the Playground allowlist');
    foreach (["'taiwan_address' => ['label' => '台灣地址洗滌／地理編碼'", "\$selectedMode === 'taiwan_address'", "'operation' => trim"] as $needle) {
        hub_test_assert(str_contains($playground, $needle), 'Taiwan address Playground contract missing ' . $needle);
    }

    $dockerRunner = (string)file_get_contents(HUB_ROOT . '/app/docker_runner.php');
    hub_test_assert(str_contains($dockerRunner, "['up', '-d', '--force-recreate']"), 'restart-required settings must recreate the Compose container');
});

hub_test('Taiwan address service instance writes only declared trusted upstream settings', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'taiwan-address', [
        'service_key' => 'taiwan-address-test',
        'name' => 'Taiwan Address Test',
        'mode' => 'taiwan_address_test',
        'port_mode' => 'manual',
        'local_port' => 18118,
        'env' => [
            'TWADDR_UPSTREAM_URL' => 'http://host.docker.internal/tw-address/api.php',
            'TWADDR_TIMEOUT_SEC' => '12',
            'UNDECLARED_ENV' => 'must_not_write',
        ],
    ]);
    $service = $installed['service'];
    $env = (string)file_get_contents(dirname(hub_path($service['compose_file'])) . '/.env');
    $compose = (string)file_get_contents(hub_path($service['compose_file']));
    hub_test_assert(str_contains($env, 'TWADDR_UPSTREAM_URL=http://host.docker.internal/tw-address/api.php'), 'Taiwan address upstream missing from env');
    hub_test_assert(str_contains($env, 'TWADDR_TIMEOUT_SEC=12'), 'Taiwan address timeout missing from env');
    hub_test_assert(!str_contains($env, 'UNDECLARED_ENV='), 'Taiwan address env must not contain arbitrary keys');
    hub_test_assert(str_contains($compose, '127.0.0.1:${TWADDR_LOCAL_PORT:-18118}:8000'), 'Taiwan address compose port mismatch');
    hub_test_assert(!str_contains($compose, 'gpus: all'), 'Taiwan address compose must not request GPU');
});
