<?php
declare(strict_types=1);

hub_test('gateway logs unknown mode service disabled and method not allowed', function (): void {
    $db = hub_test_reset_db();
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=missing';
    $_SERVER['HTTP_USER_AGENT'] = str_repeat('A', 700);

    $unknown = hub_gateway_dispatch($db, 'missing');
    hub_test_assert($unknown['status'] === 404, 'unknown mode must return 404');
    hub_test_assert(hub_latest_api_error_code($db) === 'unknown_mode', 'unknown mode must be logged');

    $disabled = hub_gateway_dispatch($db, 'hello');
    hub_test_assert($disabled['status'] === 503, 'disabled service must return 503');
    hub_test_assert(hub_latest_api_error_code($db) === 'service_disabled', 'disabled service must be logged');

    hub_set_service_enabled($db, 'hello', true);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $method = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($method['status'] === 405, 'wrong method must return 405');
    hub_test_assert(hub_latest_api_error_code($db) === 'method_not_allowed', 'wrong method must be logged');

    $log = $db->query('SELECT * FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert(strlen((string)$log['user_agent']) === 512, 'user agent must be truncated');
});

hub_test('gateway logs successful service API access', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=hello';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($response['status'] === 200, 'hello must still work');
    $log = $db->query('SELECT * FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert((int)$log['ok'] === 1, 'successful access must be logged');
    hub_test_assert($log['error_code'] === null, 'successful access must not have error_code');
});

function hub_latest_api_error_code(PDO $db): string
{
    return (string)$db->query('SELECT error_code FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
}
