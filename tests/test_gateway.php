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

hub_test('task_cancel API requests cooperative cancel for running DocParser tasks', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, ['input_file' => HUB_DATA_DIR . '/uploads/tasks/task_1/input.pdf'], null, '127.0.0.1');
    hub_claim_next_task($db);

    $serverBackup = $_SERVER;
    $getBackup = $_GET;
    $postBackup = $_POST;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['task_id' => (string)$taskId];
        $_POST = [];

        $cancel = hub_gateway_dispatch($db, 'task_cancel');
        $cancelPayload = json_decode((string)$cancel['body'], true);
        hub_test_assert($cancel['status'] === 200, 'running DocParser cancel must return 200');
        hub_test_assert(($cancelPayload['status'] ?? '') === 'running', 'running DocParser cancel must keep status running until checkpoint');
        hub_test_assert(($cancelPayload['cancel_requested'] ?? false) === true, 'running DocParser cancel must return cancel_requested=true');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $status = hub_gateway_dispatch($db, 'task_status');
        $statusPayload = json_decode((string)$status['body'], true);
        hub_test_assert(($statusPayload['cancel_requested'] ?? false) === true, 'task_status must expose cancel_requested');
    } finally {
        $_SERVER = $serverBackup;
        $_GET = $getBackup;
        $_POST = $postBackup;
    }
});
