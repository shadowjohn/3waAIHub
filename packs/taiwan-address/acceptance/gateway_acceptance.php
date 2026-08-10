<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/app/bootstrap.php';

if (!HUB_TEST_DATA_DIR_ACTIVE) {
    fwrite(STDERR, "AIHUB_TEST_DB and AIHUB_TEST_DATA_DIR are required for isolated acceptance.\n");
    exit(2);
}

$baseUrl = rtrim((string)(getenv('TWADDR_ACCEPTANCE_BASE_URL') ?: 'http://127.0.0.1:18080'), '/');
if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    fwrite(STDERR, "TWADDR_ACCEPTANCE_BASE_URL is invalid.\n");
    exit(2);
}

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$installed = hub_install_pack($db, 'taiwan-address', [
    'service_key' => 'taiwan-address-acceptance',
    'name' => 'Taiwan Address Acceptance',
    'mode' => 'taiwan_address',
    'port_mode' => 'manual',
    'local_port' => 18118,
    'idempotent' => true,
    'env' => [
        'TWADDR_UPSTREAM_URL' => 'http://host.docker.internal/wash_taiwan_address_php/api.php',
        'TWADDR_TIMEOUT_SEC' => '10',
    ],
]);
$service = $installed['service'];
hub_set_service_enabled($db, 'taiwan_address', true);
hub_update_service_status($db, (int)$service['id'], 'running');
hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

$memberId = hub_create_api_member($db, 'Windows Taiwan Address Acceptance');
$token = hub_create_api_token($db, $memberId, 'isolated acceptance', null, null);
hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'taiwan_address', (int)$service['id']);
unset($db);

$call = static function (array $payload) use ($baseUrl, $token): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'timeout' => 15,
        'ignore_errors' => true,
        'header' => "Authorization: Bearer {$token['plain_token']}\r\nContent-Type: application/json\r\n",
        'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]]);
    $body = file_get_contents($baseUrl . '/api.php?mode=taiwan_address', false, $context);
    if (!is_string($body)) {
        throw new RuntimeException('Gateway did not return a response.');
    }

    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
};

try {
    $address = $call(['operation' => 'getAddress_XY', 'address' => '台中市南區新和街1號']);
    $alias = $call(['operation' => 'searchAlias', 'q' => '國網中心', 'limit' => 1]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gateway acceptance failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$db = hub_db();
$access = $db->query('SELECT status_code, mode, member_id, token_id FROM api_access_logs ORDER BY id DESC LIMIT 2')->fetchAll();
$result = [
    'address' => [
        'ok' => $address['ok'] ?? false,
        'address' => $address['address'] ?? null,
        'kind' => $address['kind'] ?? null,
    ],
    'alias' => [
        'ok' => $alias['ok'] ?? false,
        'full_addr' => $alias['items'][0]['full_addr'] ?? null,
        'quality_flag' => $alias['items'][0]['quality_flag'] ?? null,
        'result_label' => $alias['items'][0]['result_label'] ?? null,
    ],
    'access' => $access,
];
$output = hub_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($output === false) {
    fwrite(STDERR, 'Gateway acceptance result encoding failed.' . PHP_EOL);
    exit(1);
}
echo $output . PHP_EOL;

$passed = ($result['address']['ok'] ?? false) === true
    && ($result['address']['kind'] ?? '') === 'official'
    && ($result['alias']['ok'] ?? false) === true
    && ($result['alias']['quality_flag'] ?? '') === 'alias_reference'
    && count($access) === 2
    && count(array_filter($access, static fn (array $row): bool => (int)($row['status_code'] ?? 0) >= 200 && (int)($row['status_code'] ?? 0) < 300 && ($row['mode'] ?? '') === 'taiwan_address')) === 2;

exit($passed ? 0 : 1);
