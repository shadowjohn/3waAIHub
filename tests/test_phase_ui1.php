<?php
declare(strict_types=1);

hub_test('PhaseUI-1 command job payload exposes service status for AJAX refresh', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');

    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'ui-test'], null, '127.0.0.1');
    hub_update_service_status($db, (int)$service['id'], 'running');

    $payload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert($payload !== null, 'job payload missing');
    hub_test_assert(($payload['action'] ?? '') === 'service_start', 'job payload must include action');
    hub_test_assert((int)($payload['service_id'] ?? 0) === (int)$service['id'], 'job payload must include service_id');
    hub_test_assert(($payload['status_label'] ?? '') !== '', 'job payload must include localized job status label');
    hub_test_assert(($payload['service']['status'] ?? '') === 'running', 'job payload must include latest service status');
    hub_test_assert(($payload['service']['status_label'] ?? '') === '執行中', 'job payload must include localized service status');
});

hub_test('PhaseUI-1 services page has AJAX update hooks', function (): void {
    $servicesPage = (string)file_get_contents(HUB_ROOT . '/admin/services.php');
    $servicesJs = (string)file_get_contents(HUB_ROOT . '/assets/js/services.js');

    foreach (['data-service-row-id', 'data-service-status', 'data-service-status-label', 'data-service-refresh-form'] as $needle) {
        hub_test_assert(str_contains($servicesPage, $needle), 'services page missing AJAX hook ' . $needle);
    }
    foreach (['function updateServiceRow', 'function triggerServiceRefresh', 'data-service-status', 'job.status_class', '.fail(function'] as $needle) {
        hub_test_assert(str_contains($servicesJs, $needle), 'services.js missing ' . $needle);
    }
    hub_test_assert(str_contains($servicesJs, "job.error_code !== 'platform_target_unsupported'"), 'unsupported terminal jobs must not trigger a service refresh');
});

hub_test('PhaseUI-1 packs page has readiness AJAX hooks', function (): void {
    $packsPage = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    hub_test_assert(is_file(HUB_ROOT . '/assets/js/packs.js'), 'packs.js missing');
    $packsJs = (string)file_get_contents(HUB_ROOT . '/assets/js/packs.js');

    foreach (['ajax=readiness', 'pack-readiness-value', 'data-pack-id', 'pack-readiness-refresh'] as $needle) {
        hub_test_assert(str_contains($packsPage, $needle), 'packs page missing readiness AJAX hook ' . $needle);
    }
    foreach (['function refreshReadiness', 'packs.php', "\"readiness\"", '.fail(function'] as $needle) {
        hub_test_assert(str_contains($packsJs, $needle), 'packs.js missing ' . $needle);
    }
});
