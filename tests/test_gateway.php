<?php
declare(strict_types=1);

hub_test('hello gateway and unknown mode keep expected contract', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);

    $hello = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, [
        'ok' => true,
        'service' => 'hello',
        'message' => '3waAIHub service is running',
    ]));
    hub_test_assert($hello['status'] === 200, 'hello did not return 200');
    hub_test_assert(str_contains($hello['body'], '"ok":true'), 'hello body missing ok');

    $unknown = hub_gateway_dispatch($db, 'not_exists');
    hub_test_assert($unknown['status'] === 404, 'unknown mode did not return 404');
});

hub_test('gateway applies manifest upload limit and timeout', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'translate-gemma12b', ['idempotent' => true]);
    hub_set_service_enabled($db, 'translate', true);
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=translate';
    $_SERVER['CONTENT_LENGTH'] = (string)(3 * 1024 * 1024);

    $oversize = hub_gateway_dispatch($db, 'translate', static fn (): array => throw new RuntimeException('oversize request must not reach service'));
    hub_test_assert($oversize['status'] === 413, 'oversize request must return 413');
    hub_test_assert(str_contains($oversize['body'], 'payload_too_large'), 'oversize response must name payload_too_large');

    unset($_SERVER['CONTENT_LENGTH']);
    $response = hub_gateway_dispatch($db, 'translate', static function (array $service, int $timeoutSec): array {
        hub_test_assert($service['mode'] === 'translate', 'requester must receive service');
        hub_test_assert($timeoutSec === 180, 'translate gateway timeout must come from manifest');

        return hub_gateway_json(200, ['ok' => true]);
    });
    hub_test_assert($response['status'] === 200, 'translate request should pass after content length is acceptable');
});
