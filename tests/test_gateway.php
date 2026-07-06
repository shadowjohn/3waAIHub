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
