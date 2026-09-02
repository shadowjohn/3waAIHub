<?php
declare(strict_types=1);

hub_test('service health exposes only fresh allowlisted snapshot data', function (): void {
    $checkedAt = '2026-09-02T11:20:00+08:00';
    $snapshot = [
        'checked_at' => $checkedAt,
        'services' => [
            'bioclip' => [
                'ready' => true,
                'runtime_status' => 'running',
                'reason' => '',
                'model' => 'untrusted-model-name',
                'health_url' => 'http://127.0.0.1:18111/health',
            ],
            'photo' => [
                'ready' => false,
                'runtime_status' => 'stopped',
                'reason' => 'service_disabled',
                'model' => 'untrusted-model-name',
                'internal_url' => 'http://127.0.0.1:18110/chat',
            ],
        ],
    ];

    $payload = hub_service_health_public_payload($snapshot, ['bioclip', 'photo'], strtotime('2026-09-02 11:21:00'));

    hub_test_assert($payload === [
        'ok' => true,
        'checked_at' => $checkedAt,
        'services' => [
            'bioclip' => [
                'ready' => true,
                'runtime_status' => 'running',
                'reason' => '',
                'model' => 'BioCLIP-2',
            ],
            'photo' => [
                'ready' => false,
                'runtime_status' => 'stopped',
                'reason' => 'service_disabled',
                'model' => 'gemma4-12b',
            ],
        ],
    ], 'service health response must expose only its fixed public contract');
});

hub_test('service health probe requires an explicitly ready JSON response', function (): void {
    hub_test_assert(hub_service_health_response_ok(200, '{"ok":true}'), 'ok=true JSON must be healthy');
    hub_test_assert(hub_service_health_response_ok(200, '{"ok":true,"ready":true}'), 'ready=true JSON must be healthy');
    hub_test_assert(!hub_service_health_response_ok(204, ''), 'empty successful response must not be treated as ready');
    hub_test_assert(!hub_service_health_response_ok(200, '{}'), 'health JSON must explicitly set ok=true');
    hub_test_assert(!hub_service_health_response_ok(200, '{"ok":true,"ready":false}'), 'ready=false must be unavailable');
});

hub_test('service health is an explicit API Token permission mode', function (): void {
    hub_test_assert(
        hub_service_health_permission_modes() === ['service_health' => '服務可用性預判'],
        'service health must have an explicit Token permission label'
    );
    $page = (string)file_get_contents(HUB_ROOT . '/admin/api_token_permissions.php');
    hub_test_assert(str_contains($page, 'hub_service_health_permission_modes()'), 'Token permission page must render the service health mode');
});

hub_test('service health writer persists a verified local snapshot', function (): void {
    $db = hub_test_reset_db();
    $now = hub_now();
    $db->prepare(
        "UPDATE services
         SET service_key = 'bioclip-main', pack_id = 'bioclip', mode = 'bioclip', enabled = 1,
             install_status = 'installed', status = 'running', runtime_status = 'running',
             health_url = 'http://127.0.0.1:18111/health', updated_at = :updated_at
         WHERE mode = 'hello'"
    )->execute([':updated_at' => $now]);
    $service = hub_get_service_by_mode($db, 'bioclip');
    hub_test_assert($service !== null, 'BioCLIP fixture service must exist');

    $snapshot = hub_service_health_write_snapshot($db, static function (array $services) use ($service): array {
        hub_test_assert(count($services) === 1 && (int)$services[0]['id'] === (int)$service['id'], 'writer must probe the eligible local service once');

        return [(int)$service['id'] => ['status' => 200, 'body' => '{"ok":true,"ready":true}', 'curl_result' => CURLE_OK]];
    });

    hub_test_assert(is_file(hub_service_health_snapshot_path()), 'writer must persist the runtime snapshot');
    hub_test_assert(($snapshot['services']['bioclip']['ready'] ?? null) === true, 'healthy BioCLIP must be cached as ready');
    hub_test_assert(($snapshot['services']['photo']['reason'] ?? null) === 'service_not_found', 'missing Photo service must use a stable reason');
});

hub_test('one minute cron refreshes service health before Cluster inventory', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    $healthCall = 'service_health_snapshot.php';
    $clusterCall = 'cluster_refresh.php';

    hub_test_assert(is_file(HUB_ROOT . '/scripts/service_health_snapshot.php'), 'service health cron writer must exist');
    hub_test_assert(str_contains($script, $healthCall), 'one-minute cron must refresh the health snapshot');
    hub_test_assert(strpos($script, $healthCall) < strpos($script, $clusterCall), 'health snapshot must precede Cluster refresh');
});

hub_test('gateway service health requires its own and each queried mode permission', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Service health gateway test');
    $token = hub_create_api_token($db, $memberId, 'service health partial permission', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'service_health');
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'bioclip');

    $response = hub_gateway_dispatch($db, 'service_health', null, [
        'method' => 'GET',
        'client_ip' => '203.0.113.10',
        'bearer_token' => (string)$token['plain_token'],
        'query' => ['services' => 'bioclip,photo'],
    ]);

    hub_test_assert($response['status'] === 403, 'Photo health must require photo_upload and photo permission');
    hub_test_assert(str_contains($response['body'], 'token_mode_denied'), 'service health permission failure must have the stable error code');
});

hub_test('local service health immediately reflects a disabled service over a fresh snapshot', function (): void {
    $db = hub_test_reset_db();
    $db->prepare(
        "UPDATE services
         SET service_key = 'bioclip-main', pack_id = 'bioclip', mode = 'bioclip', enabled = 1,
             install_status = 'installed', status = 'running', runtime_status = 'running',
             health_url = 'http://127.0.0.1:18111/health', updated_at = :updated_at
         WHERE mode = 'hello'"
    )->execute([':updated_at' => hub_now()]);
    $service = hub_get_service_by_mode($db, 'bioclip');
    hub_test_assert($service !== null, 'BioCLIP fixture service must exist');
    hub_service_health_write_snapshot($db, static function (array $services) use ($service): array {
        return [(int)$service['id'] => ['status' => 200, 'body' => '{"ok":true}', 'curl_result' => CURLE_OK]];
    });
    $db->prepare('UPDATE services SET enabled = 0, updated_at = :updated_at WHERE id = :id')
        ->execute([':updated_at' => hub_now(), ':id' => (int)$service['id']]);

    $payload = hub_service_health_local_payload($db, ['bioclip']);

    hub_test_assert(($payload['services']['bioclip'] ?? null) === [
        'ready' => false,
        'runtime_status' => 'running',
        'reason' => 'service_disabled',
        'model' => 'BioCLIP-2',
    ], 'current lifecycle must override a stale ready verdict without probing');
});
