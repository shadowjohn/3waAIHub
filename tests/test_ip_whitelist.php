<?php
declare(strict_types=1);

hub_test('ip whitelist allows localhost exact ip and cidr but rejects invalid rules', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_set_service_enabled($db, 'hello', true);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    hub_test_assert(hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]))['status'] === 200, 'localhost must be allowed');

    hub_add_service_ip_rule($db, (int)$service['id'], '203.0.113.10', 'exact', null);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    hub_test_assert(hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]))['status'] === 200, 'exact IP must be allowed');

    hub_add_service_ip_rule($db, (int)$service['id'], '198.51.100.0/24', 'cidr', null);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
    hub_test_assert(hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]))['status'] === 200, 'CIDR IP must be allowed');

    hub_test_assert(hub_test_throws(fn () => hub_add_service_ip_rule($db, (int)$service['id'], '999.999.1.1', '', null)), 'invalid IP rule must fail');
    hub_test_assert(hub_test_throws(fn () => hub_add_service_ip_rule($db, (int)$service['id'], '203.0.113.10', 'duplicate', null)), 'duplicate IP rule must fail');
});

hub_test('external IP is denied by default and X-Forwarded-For is ignored', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '127.0.0.1';

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, ['ok' => true]));
    hub_test_assert($response['status'] === 403, 'external IP must be denied by default');
    hub_test_assert(str_contains($response['body'], 'ip_not_allowed'), 'denied response must name ip_not_allowed');
});
