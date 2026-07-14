<?php
declare(strict_types=1);

hub_test('PhaseAuth-1 migrates users role fields and protects default admin', function (): void {
    $db = hub_test_reset_db();
    $columns = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    foreach (['role', 'api_member_id', 'display_name', 'email', 'company', 'is_protected', 'is_enabled', 'last_login_at'] as $column) {
        hub_test_assert(in_array($column, $columns, true), 'users missing column ' . $column);
    }

    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    hub_test_assert($admin !== false, 'default admin missing');
    hub_test_assert((string)$admin['role'] === 'system_admin', 'default admin must be system_admin');
    hub_test_assert((int)$admin['is_protected'] === 1, 'default admin must be protected');
    hub_test_assert((int)$admin['is_enabled'] === 1, 'default admin must be enabled');

    hub_test_assert(function_exists('hub_require_system_admin'), 'system admin role guard missing');
    hub_test_assert(function_exists('hub_create_customer_user'), 'customer create helper missing');
    hub_test_assert(function_exists('hub_create_customer_token'), 'customer token helper missing');

    hub_test_assert(hub_user_admin_update_error($db, (int)$admin['id'], ['role' => 'customer']) !== null, 'protected admin must not be downgraded');
    hub_test_assert(hub_user_admin_update_error($db, (int)$admin['id'], ['is_enabled' => 0]) !== null, 'protected admin must not be disabled');
});

hub_test('PhaseAuth-1 creates customer linked to api member with allowed modes and scoped tokens', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);

    $customerId = hub_create_customer_user($db, [
        'username' => 'customer1',
        'password' => 'customer123',
        'display_name' => '客戶一號',
        'email' => 'customer1@example.test',
        'company' => 'Acme',
        'modes' => ['hello'],
    ]);
    $customer = hub_get_user($db, $customerId);
    hub_test_assert($customer !== null, 'customer row missing');
    hub_test_assert((string)$customer['role'] === 'customer', 'customer role mismatch');
    hub_test_assert((int)$customer['api_member_id'] > 0, 'customer must link api_member');

    $allowed = hub_user_allowed_modes($db, $customerId);
    hub_test_assert($allowed === ['hello'], 'customer allowed modes mismatch');

    $token = hub_create_customer_token($db, $customerId, 'Customer smoke token');
    $permissions = hub_list_api_token_permissions($db, (int)$token['token_id']);
    hub_test_assert(count($permissions) === 1 && (string)$permissions[0]['mode'] === 'hello', 'customer token must inherit only allowed modes');
    hub_test_assert(!str_contains(json_encode(hub_get_api_token($db, (int)$token['token_id']), JSON_UNESCAPED_SLASHES), $token['plain_token']), 'plain token must not be stored');

    hub_test_assert(hub_customer_owns_token($db, $customerId, (int)$token['token_id']), 'customer should own own token');
    $otherMember = hub_create_api_member($db, 'Other Customer');
    $otherToken = hub_create_api_token($db, $otherMember, 'Other token', null, null);
    hub_test_assert(!hub_customer_owns_token($db, $customerId, (int)$otherToken['token_id']), 'customer must not own other token');
});

hub_test('PhaseAuth-1 portal and admin page guards are present', function (): void {
    foreach (['my_services.php', 'my_tokens.php', 'my_ip_whitelist.php', 'my_usage.php', 'my_profile.php', 'change_password.php'] as $file) {
        $path = HUB_ROOT . '/admin/' . $file;
        hub_test_assert(is_file($path), 'customer portal page missing ' . $file);
        $source = (string)file_get_contents($path);
        hub_test_assert(str_contains($source, 'hub_require_customer_or_admin'), 'portal page missing role guard ' . $file);
    }

    foreach (['services.php', 'packs.php', 'marketplace.php', 'models.php', 'settings.php', 'environment.php', 'log_explorer.php', 'customers.php', 'customer_edit.php'] as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/admin/' . $file);
        hub_test_assert(str_contains($source, 'hub_require_system_admin'), 'admin page must require system_admin ' . $file);
    }

    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    foreach (['我的服務', '我的 Token', 'IP 白名單', '用量統計', '帳號資料', '變更密碼'] as $label) {
        hub_test_assert(str_contains($layout, $label), 'customer nav missing ' . $label);
    }

    $myServices = (string)file_get_contents(HUB_ROOT . '/admin/my_services.php');
    foreach (['local_port', 'compose_file', 'health_url', 'internal_url', 'HUB_DB_PATH'] as $sensitive) {
        hub_test_assert(!str_contains($myServices, $sensitive), 'customer services page must not expose ' . $sensitive);
    }
});

hub_test('PhaseAuth-1 playground filters customer modes', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-auth1',
        'name' => 'OCR Auth1',
        'mode' => 'ocr',
        'port_mode' => 'manual',
        'local_port' => 18280,
    ]);
    $customerId = hub_create_customer_user($db, [
        'username' => 'customer2',
        'password' => 'customer123',
        'display_name' => '客戶二號',
        'modes' => ['hello'],
    ]);
    hub_create_customer_token($db, $customerId, 'Playground token');
    $customer = hub_get_user($db, $customerId);
    hub_test_assert($customer !== null, 'customer2 missing');

    $adminServices = hub_playground_service_options($db, ['role' => 'system_admin']);
    $customerServices = hub_playground_service_options($db, $customer);
    $adminModes = array_map(static fn (array $service): string => (string)$service['mode'], $adminServices);
    $customerModes = array_map(static fn (array $service): string => (string)$service['mode'], $customerServices);
    hub_test_assert(in_array('ocr', $adminModes, true), 'system_admin playground must see all supported modes');
    hub_test_assert($customerModes === ['hello'], 'customer playground must see only allowed modes');
});

hub_test('PhaseAuth-1 playground exposes photo pseudo mode with customer filtering', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'llm-gemma4-12b', [
        'service_key' => 'gemma4-main',
        'name' => 'Gemma 4 Main',
        'mode' => 'chat',
        'port_mode' => 'manual',
        'local_port' => 18110,
        'idempotent' => true,
    ]);

    $photoCustomerId = hub_create_customer_user($db, [
        'username' => 'photo_playground',
        'password' => 'customer123',
        'modes' => ['photo'],
    ]);
    hub_create_customer_token($db, $photoCustomerId, 'Photo Playground token');
    $photoCustomer = hub_get_user($db, $photoCustomerId);
    hub_test_assert($photoCustomer !== null, 'photo customer missing');

    $helloCustomerId = hub_create_customer_user($db, [
        'username' => 'hello_playground',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    hub_create_customer_token($db, $helloCustomerId, 'Hello Playground token');
    $helloCustomer = hub_get_user($db, $helloCustomerId);
    hub_test_assert($helloCustomer !== null, 'hello customer missing');

    $adminModes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, ['role' => 'system_admin']));
    $photoModes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, $photoCustomer));
    $helloModes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, $helloCustomer));

    hub_test_assert(in_array('photo', $adminModes, true), 'system_admin playground must include photo pseudo mode');
    hub_test_assert($photoModes === ['photo'], 'photo customer playground must include only allowed photo pseudo mode');
    hub_test_assert(!in_array('photo', $helloModes, true), 'customer without photo permission must not see photo pseudo mode');
});
