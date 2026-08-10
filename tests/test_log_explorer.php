<?php
declare(strict_types=1);

hub_test('base64url IP filters decode then validate', function (): void {
    $encoded = aihub_b64url_encode('2001:db8::1');
    hub_test_assert($encoded === 'MjAwMTpkYjg6OjE', 'IPv6 base64url mismatch');
    hub_test_assert(aihub_b64url_decode($encoded) === '2001:db8::1', 'IPv6 base64url decode mismatch');
    hub_test_assert(hub_decode_ip_get_filter($encoded, false) === '2001:db8::1', 'valid IP filter should decode');
    hub_test_assert(hub_decode_ip_get_filter(aihub_b64url_encode('../../etc/passwd'), true) === null, 'invalid CIDR filter should fail');
    hub_test_assert(hub_decode_ip_get_filter(aihub_b64url_encode('192.168.1.0/999'), true) === null, 'invalid CIDR prefix should fail');
});

hub_test('Record Center API filters preserve base64url IP and validated tokens', function (): void {
    $encoded = aihub_b64url_encode('192.168.1.11');
    $filters = hub_admin_record_api_filters([
        'client_ip_b64' => $encoded,
        'mode' => 'hello',
        'service_id' => '2',
        'member_id' => '3',
        'token_id' => '4',
        'ok' => '0',
        'status_code' => '404',
        'error_code' => 'unknown_mode',
        'method' => 'POST',
        'request_id' => 'req_123',
        'keyword' => 'needle',
    ]);

    hub_test_assert($filters['client_ip_b64'] === $encoded, 'valid client IP must remain base64url encoded');
    hub_test_assert($filters['mode'] === 'hello' && $filters['error_code'] === 'unknown_mode', 'technical token filters changed');
    hub_test_assert($filters['service_id'] === 2 && $filters['member_id'] === 3 && $filters['token_id'] === 4, 'numeric API filters changed');

    $invalid = hub_admin_record_api_filters([
        'client_ip_b64' => aihub_b64url_encode('../../etc/passwd'),
        'mode' => ['hello'],
        'status_code' => ['200'],
        'method' => ["GET\nX-Test"],
        'service_id' => ['2'],
    ]);
    hub_test_assert($invalid['client_ip_b64'] === '' && $invalid['mode'] === '' && $invalid['status_code'] === '' && $invalid['method'] === '' && $invalid['service_id'] === 0, 'invalid API filters must be discarded');
});

hub_test('legacy API log redirect emits only a bounded canonical relative URL', function (): void {
    $query = hub_admin_record_api_redirect_query([
        'client_ip' => '192.168.1.11',
        'mode' => 'hello',
        'keyword' => "needle\r\nLocation: https://evil.test",
        'unexpected' => 'must-not-survive',
    ]);
    $url = hub_admin_record_log_explorer_url($query);
    hub_test_assert(str_starts_with($url, 'log_explorer.php?tab=api&'), 'legacy redirect must stay on the canonical API log tab');
    hub_test_assert(str_contains($url, 'client_ip_b64=MTkyLjE2OC4xLjEx') && str_contains($url, 'mode=hello'), 'legacy redirect must retain validated API filters');
    hub_test_assert(!str_contains($url, "\r") && !str_contains($url, "\n") && !str_contains($url, 'unexpected'), 'legacy redirect must not reflect unsafe or unknown header input');

    $page = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    hub_test_assert(str_contains($page, 'hub_redirect(hub_admin_record_log_explorer_url($query))'), 'Record Center POST redirect must use the canonical relative URL helper');
});

hub_test('api access query supports b64 client IP mode error and keyword filters', function (): void {
    $db = hub_test_reset_db();
    hub_insert_api_log_for_test($db, 'req_a', '192.168.1.10', 'hello', 200, 1, null, 'ok uri', 'normal ua');
    hub_insert_api_log_for_test($db, 'req_b', '192.168.1.11', 'missing', 404, 0, 'unknown_mode', 'needle reason', 'bad ua');

    $logs = hub_list_api_access_logs($db, [
        'client_ip_b64' => aihub_b64url_encode('192.168.1.11'),
        'mode' => 'missing',
        'error_code' => 'unknown_mode',
        'ok' => '0',
        'keyword' => "needle%' OR 1=1 --",
    ], 200, 0);

    hub_test_assert(count($logs) === 0, 'keyword must be parameterized');

    $logs = hub_list_api_access_logs($db, [
        'client_ip_b64' => aihub_b64url_encode('192.168.1.11'),
        'mode' => 'missing',
        'error_code' => 'unknown_mode',
        'ok' => '0',
        'keyword' => 'needle',
    ], 200, 0);

    hub_test_assert(count($logs) === 1, 'b64 IP and keyword filters should match one row');
    hub_test_assert($logs[0]['request_id'] === 'req_b', 'request_id should be selected');
});

hub_test('Record Center keeps API filters pagination and detail links', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    foreach (['time_from', 'time_to', 'client_ip_b64', 'mode', 'service_id', 'member_id', 'token_id', 'ok', 'status_code', 'error_code', 'method', 'request_id', 'keyword'] as $filter) {
        hub_test_assert(str_contains($page, $filter), 'API filter missing from canonical Record Center: ' . $filter);
    }
    foreach (['log_detail.php?id=', "unset(\$query['page'])", "'page=' . (\$page - 1)", "'page=' . (\$page + 1)"] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'API pagination/detail contract missing ' . $needle);
    }

    $legacy = (string)file_get_contents(HUB_ROOT . '/admin/api_usage.php');
    hub_test_assert(str_contains($legacy, "/** @deprecated Canonical UI: admin/log_explorer.php?tab=api */"), 'API usage legacy marker missing');
    hub_test_assert(str_contains($legacy, 'class="notice legacy-debug"'), 'API usage legacy notice missing');
    hub_test_assert(str_contains($legacy, 'log_explorer.php?tab=api'), 'API usage canonical link missing');
    hub_test_assert(!str_contains($legacy, 'hub_redirect(') && !str_contains($legacy, "require __DIR__ . '/log_explorer.php'"), 'API usage diagnostic must not redirect or include canonical UI');
});

hub_test('gateway generates request_id and stores it in api access logs', function (): void {
    $db = hub_test_reset_db();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=missing';

    $response = hub_gateway_dispatch($db, 'missing');
    $payload = json_decode((string)$response['body'], true);
    hub_test_assert(isset($payload['request_id']) && preg_match('/^req_[0-9]{14}_[a-f0-9]{6}$/', $payload['request_id']) === 1, 'error JSON must include request_id');
    hub_test_assert(in_array('X-3waAIHub-Request-Id: ' . $payload['request_id'], $response['headers'], true), 'response header must include request_id');

    $log = hub_get_api_access_log($db, (int)$db->query('SELECT id FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetchColumn());
    hub_test_assert($log !== null && $log['request_id'] === $payload['request_id'], 'log must store request_id');
});

function hub_insert_api_log_for_test(PDO $db, string $requestId, string $clientIp, string $mode, int $status, int $ok, ?string $errorCode, string $reason, string $userAgent): void
{
    $stmt = $db->prepare(
        'INSERT INTO api_access_logs
            (request_id, service_id, mode, client_ip, method, request_uri, status_code, ok, error_code, reason, user_agent, elapsed_ms, created_at)
         VALUES
            (:request_id, NULL, :mode, :client_ip, :method, :request_uri, :status_code, :ok, :error_code, :reason, :user_agent, 3, :created_at)'
    );
    $stmt->execute([
        ':request_id' => $requestId,
        ':mode' => $mode,
        ':client_ip' => $clientIp,
        ':method' => 'GET',
        ':request_uri' => '/api.php?mode=' . $mode,
        ':status_code' => $status,
        ':ok' => $ok,
        ':error_code' => $errorCode,
        ':reason' => $reason,
        ':user_agent' => $userAgent,
        ':created_at' => hub_now(),
    ]);
}
