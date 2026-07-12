<?php
declare(strict_types=1);

hub_test('PhaseAuth-2A customer read-only portal only exposes own services and safe fields', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-auth2a',
        'name' => 'OCR Auth2A',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18281,
    ]);
    $customerA = hub_create_customer_user($db, [
        'username' => 'auth2a_a',
        'password' => 'customer123',
        'display_name' => 'Customer A',
        'modes' => ['hello'],
    ]);
    $customerB = hub_create_customer_user($db, [
        'username' => 'auth2a_b',
        'password' => 'customer123',
        'display_name' => 'Customer B',
        'modes' => ['ocr'],
    ]);

    hub_test_assert(array_map(static fn (array $row): string => (string)$row['mode'], hub_user_allowed_services($db, $customerA)) === ['hello'], 'customer A must see only own service modes');
    hub_test_assert(array_map(static fn (array $row): string => (string)$row['mode'], hub_user_allowed_services($db, $customerB)) === ['ocr'], 'customer B must see only own service modes');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/my_services.php');
    foreach (['local_port', 'compose_file', 'health_url', 'internal_url', 'HUB_DB_PATH', 'HUB_JOB_LOG_DIR', '/DATA/models', 'docker-compose'] as $sensitive) {
        hub_test_assert(!str_contains($source, $sensitive), 'my_services.php must not expose ' . $sensitive);
    }
    foreach (['我的服務', 'mode', 'pack_id', 'runtime_level', 'endpoint'] as $label) {
        hub_test_assert(str_contains($source, $label), 'my_services.php missing read-only label ' . $label);
    }
});

hub_test('PhaseAuth-2A customer usage is scoped to own api member', function (): void {
    $db = hub_test_reset_db();
    $customerA = hub_create_customer_user($db, [
        'username' => 'usage_a',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    $customerB = hub_create_customer_user($db, [
        'username' => 'usage_b',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    $tokenA = hub_create_customer_token($db, $customerA, 'Usage A');
    $tokenB = hub_create_customer_token($db, $customerB, 'Usage B');
    $userA = hub_get_user($db, $customerA);
    $userB = hub_get_user($db, $customerB);
    hub_record_api_token_usage($db, ['member_id' => (int)$userA['api_member_id'], 'token_id' => (int)$tokenA['token_id']], 'hello', true, 10, 0, 20);
    hub_record_api_token_usage($db, ['member_id' => (int)$userB['api_member_id'], 'token_id' => (int)$tokenB['token_id']], 'hello', false, 20, 0, 30);

    $usageA = hub_list_customer_usage($db, $customerA);
    $usageB = hub_list_customer_usage($db, $customerB);
    hub_test_assert(count($usageA) === 1 && (int)$usageA[0]['token_id'] === (int)$tokenA['token_id'], 'customer A usage must only include own token');
    hub_test_assert(count($usageB) === 1 && (int)$usageB[0]['token_id'] === (int)$tokenB['token_id'], 'customer B usage must only include own token');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/my_usage.php');
    hub_test_assert(str_contains($source, 'hub_model_format_bytes'), 'my_usage.php must render byte totals as human readable sizes');
});

hub_test('PhaseAuth-2A profile and password only change allowed account fields', function (): void {
    $db = hub_test_reset_db();
    $customerId = hub_create_customer_user($db, [
        'username' => 'profile_a',
        'password' => 'customer123',
        'display_name' => 'Before',
        'email' => 'before@example.test',
        'company' => 'Before Co',
        'modes' => ['hello'],
    ]);
    $before = hub_get_user($db, $customerId);
    hub_update_current_user_profile($db, $customerId, [
        'display_name' => 'After',
        'email' => 'after@example.test',
        'company' => 'After Co',
        'role' => 'system_admin',
        'is_enabled' => 0,
        'api_member_id' => 999,
        'is_protected' => 1,
    ]);
    $after = hub_get_user($db, $customerId);
    hub_test_assert((string)$after['display_name'] === 'After', 'display_name should update');
    hub_test_assert((string)$after['email'] === 'after@example.test', 'email should update');
    hub_test_assert((string)$after['company'] === 'After Co', 'company should update');
    hub_test_assert((string)$after['role'] === 'customer', 'profile must not update role');
    hub_test_assert((int)$after['is_enabled'] === 1, 'profile must not update enabled');
    hub_test_assert((int)$after['api_member_id'] === (int)$before['api_member_id'], 'profile must not relink api member');
    hub_test_assert((int)$after['is_protected'] === 0, 'profile must not update protected flag');

    hub_test_assert(hub_update_password($db, $customerId, 'wrong-password', 'newpass123') !== null, 'wrong current password should fail');
    hub_test_assert(hub_update_password($db, $customerId, 'customer123', 'newpass123') === null, 'password change should pass');
    $_SESSION = [];
    hub_test_assert(!hub_login($db, 'profile_a', 'customer123'), 'old password should fail after change');
    hub_test_assert(hub_login($db, 'profile_a', 'newpass123'), 'new password should pass after change');
});

hub_test('PhaseAuth-2A playground filters customer modes by own member token permissions', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-auth2a-playground',
        'name' => 'OCR Auth2A Playground',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18282,
    ]);
    $customerId = hub_create_customer_user($db, [
        'username' => 'playground_a',
        'password' => 'customer123',
        'modes' => ['hello', 'ocr'],
    ]);
    $customer = hub_get_user($db, $customerId);
    $helloToken = hub_create_api_token($db, (int)$customer['api_member_id'], 'Own hello only', null, null);
    hub_add_api_token_mode_permission($db, (int)$helloToken['token_id'], 'hello', (int)hub_get_service_by_mode($db, 'hello')['id']);

    $otherMember = hub_create_api_member($db, 'Other member');
    $otherToken = hub_create_api_token($db, $otherMember, 'Other OCR', null, null);
    hub_add_api_token_mode_permission($db, (int)$otherToken['token_id'], 'ocr', (int)hub_get_service_by_mode($db, 'ocr')['id']);

    $modes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, $customer));
    hub_test_assert($modes === ['hello'], 'customer playground must use own member token permissions only');
});
