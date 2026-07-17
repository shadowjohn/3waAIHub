<?php
declare(strict_types=1);

hub_test('API token gateway authenticates by Bearer token and records usage', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $memberId = hub_create_api_member($db, 'Acme Client', 'Alice', 'alice@example.test', 'token test');
    $token = hub_create_api_token($db, $memberId, 'prod token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'hello', (int)$service['id']);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.10', 'test client');

    $stored = hub_get_api_token($db, (int)$token['token_id']);
    hub_test_assert($stored !== null, 'token row missing');
    hub_test_assert($stored['token_hash'] !== $token['plain_token'], 'DB must not store plain token');
    hub_test_assert(!str_contains(json_encode($stored, JSON_UNESCAPED_SLASHES), $token['plain_token']), 'token row must not contain plain token');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];
    unset($_SERVER['CONTENT_LENGTH']);

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($response['status'] === 200, 'valid token must pass');

    $log = $db->query('SELECT * FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert((int)$log['member_id'] === $memberId, 'access log must record member_id');
    hub_test_assert((int)$log['token_id'] === (int)$token['token_id'], 'access log must record token_id');
    hub_test_assert((int)$log['response_bytes'] > 0, 'access log must record response bytes');
    $filteredLogs = hub_list_api_access_logs($db, ['member_id' => $memberId, 'token_id' => (int)$token['token_id']], 10, 0);
    hub_test_assert(count($filteredLogs) === 1, 'access log member/token filters must match one row');
    hub_test_assert($filteredLogs[0]['member_name'] === 'Acme Client', 'access log list must include member_name');
    hub_test_assert($filteredLogs[0]['token_prefix'] === hub_api_token_prefix($token['plain_token']), 'access log list must include token_prefix');

    $usage = $db->query('SELECT * FROM api_token_usage_daily ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert((int)$usage['token_id'] === (int)$token['token_id'], 'usage must record token_id');
    hub_test_assert((int)$usage['request_count'] === 1, 'usage request count mismatch');
    hub_test_assert((int)$usage['success_count'] === 1, 'usage success count mismatch');
});

hub_test('admin API usage page renders byte totals and numeric columns for scanning', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/api_usage.php');

    hub_test_assert(str_contains($source, 'hub_model_format_bytes'), 'api_usage.php must render byte totals as human readable sizes');
    hub_test_assert(str_contains($source, '回應容量'), 'api_usage.php must label response bytes in Chinese');
    hub_test_assert(str_contains($source, '回應時間 (ms)'), 'api_usage.php must label response time clearly');
    hub_test_assert(str_contains($source, 'class="usage-num"'), 'api_usage.php numeric cells must be right-aligned');
});

hub_test('token IP whitelist help explains CIDR ranges instead of wildcard syntax', function (): void {
    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    $customerPage = (string)file_get_contents(HUB_ROOT . '/admin/my_ip_whitelist.php');
    $adminPage = (string)file_get_contents(HUB_ROOT . '/admin/api_token_whitelist.php');

    foreach ([
        '192.168.*.*',
        '192.168.0.0/16',
        '*.*.*.*',
        '0.0.0.0/0',
        '::/0',
        '不設定任何規則',
    ] as $needle) {
        hub_test_assert(str_contains($readme, $needle), 'README token IP help missing ' . $needle);
    }

    foreach ([$customerPage, $adminPage] as $source) {
        hub_test_assert(str_contains($source, '192.168.0.0/16'), 'token whitelist UI must show private subnet CIDR example');
        hub_test_assert(str_contains($source, '0.0.0.0/0'), 'token whitelist UI must show all IPv4 CIDR example');
        hub_test_assert(str_contains($source, '::/0'), 'token whitelist UI must show all IPv6 CIDR example');
        hub_test_assert(str_contains($source, '不支援萬用字元'), 'token whitelist UI must explain wildcard syntax is unsupported');
    }
});

hub_test('API token gateway rejects missing expired mode denied and IP denied tokens', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $memberId = hub_create_api_member($db, 'Blocked Client', '', '', '');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);

    $missing = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($missing['status'] === 401, 'missing token must return 401');
    hub_test_assert(str_contains($missing['body'], 'missing_token'), 'missing token error mismatch');

    $expired = hub_create_api_token($db, $memberId, 'expired', null, date('Y-m-d H:i:s', time() - 86400));
    hub_add_api_token_mode_permission($db, (int)$expired['token_id'], 'hello', (int)$service['id']);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $expired['plain_token'];
    $expiredResponse = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($expiredResponse['status'] === 403, 'expired token must return 403');
    hub_test_assert(str_contains($expiredResponse['body'], 'token_expired'), 'expired token error mismatch');

    $revoked = hub_create_api_token($db, $memberId, 'revoked', null, null);
    hub_add_api_token_mode_permission($db, (int)$revoked['token_id'], 'hello', (int)$service['id']);
    hub_revoke_api_token($db, (int)$revoked['token_id']);
    hub_set_api_token_enabled($db, (int)$revoked['token_id'], true);
    $revokedRow = hub_get_api_token($db, (int)$revoked['token_id']);
    hub_test_assert($revokedRow !== null && (int)$revokedRow['enabled'] === 0, 'revoked token must not be re-enabled');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $revoked['plain_token'];
    $revokedResponse = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($revokedResponse['status'] === 403, 'revoked token must return 403');
    hub_test_assert(str_contains($revokedResponse['body'], 'token_disabled'), 'revoked token error mismatch');

    $modeDenied = hub_create_api_token($db, $memberId, 'mode denied', null, null);
    hub_add_api_token_ip_rule($db, (int)$modeDenied['token_id'], '203.0.113.20', '');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $modeDenied['plain_token'];
    $modeResponse = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($modeResponse['status'] === 403, 'mode denied token must return 403');
    hub_test_assert(str_contains($modeResponse['body'], 'token_mode_not_allowed'), 'mode denied error mismatch');

    $ipDenied = hub_create_api_token($db, $memberId, 'ip denied', null, null);
    hub_add_api_token_mode_permission($db, (int)$ipDenied['token_id'], 'hello', (int)$service['id']);
    hub_add_api_token_ip_rule($db, (int)$ipDenied['token_id'], '198.51.100.0/24', '');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $ipDenied['plain_token'];
    $ipResponse = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($ipResponse['status'] === 403, 'IP denied token must return 403');
    hub_test_assert(str_contains($ipResponse['body'], 'token_ip_not_allowed'), 'IP denied error mismatch');
});

hub_test('API token gate runs before service status for external clients', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.25';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($response['status'] === 401, 'external request must require token before service status');
    hub_test_assert(str_contains($response['body'], 'missing_token'), 'missing token must be returned before service_disabled');
});

hub_test('API token localhost bypass keeps smoke tests simple', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($response['status'] === 200, 'localhost bypass must allow missing token');
});

hub_test('API token protects task API modes for external clients', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=task_submit';
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);
    $_POST = ['task_type' => 'demo_task', 'name' => 'Token Task'];

    $missing = hub_gateway_dispatch($db, 'task_submit');
    hub_test_assert($missing['status'] === 401, 'task_submit must reject missing token for external IP');
    hub_test_assert(str_contains($missing['body'], 'missing_token'), 'task_submit missing token error mismatch');

    $memberId = hub_create_api_member($db, 'Task Client', '', '', '');
    $token = hub_create_api_token($db, $memberId, 'task token', null, null);
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['plain_token'];

    $denied = hub_gateway_dispatch($db, 'task_submit');
    hub_test_assert($denied['status'] === 403, 'task_submit must reject token without mode permission');
    hub_test_assert(str_contains($denied['body'], 'token_mode_not_allowed'), 'task_submit denied mode error mismatch');

    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'task_submit', null);
    $created = hub_gateway_dispatch($db, 'task_submit');
    hub_test_assert($created['status'] === 200, 'task_submit must pass with token mode permission');
    hub_test_assert(str_contains($created['body'], '"status":"queued"'), 'task_submit must queue task');
    $_POST = [];
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_LENGTH']);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
});
