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
    hub_test_assert(function_exists('hub_delete_customer_user'), 'customer delete helper missing');
    hub_test_assert(function_exists('hub_delete_api_member'), 'API member delete helper missing');
    hub_test_assert(function_exists('hub_delete_api_token'), 'API Token delete helper missing');

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

hub_test('PhaseAuth-1 deletes customer credentials and standalone API credentials safely', function (): void {
    $db = hub_test_reset_db();
    hub_set_service_enabled($db, 'hello', true);

    $customerId = hub_create_customer_user($db, [
        'username' => 'delete_customer',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    $customer = hub_get_user($db, $customerId);
    $customerToken = hub_create_customer_token($db, $customerId, 'Delete customer token');
    $customerMemberId = (int)$customer['api_member_id'];
    $linkedMemberDeleteError = '';
    try {
        hub_delete_api_member($db, $customerMemberId);
    } catch (InvalidArgumentException $e) {
        $linkedMemberDeleteError = $e->getMessage();
    }
    hub_test_assert($linkedMemberDeleteError !== '', 'linked customer member must not be deleted directly');
    hub_test_assert(hub_get_api_member($db, $customerMemberId) !== null, 'linked customer member must remain intact');

    hub_test_assert(hub_delete_customer_user($db, $customerId) === null, 'customer deletion must succeed');
    hub_test_assert(hub_get_user($db, $customerId) === null, 'customer account must be deleted');
    hub_test_assert(hub_get_api_member($db, $customerMemberId) === null, 'customer API member must be deleted');
    hub_test_assert(hub_get_api_token($db, (int)$customerToken['token_id']) === null, 'customer Token must be deleted');

    $sharedFirstId = hub_create_customer_user($db, [
        'username' => 'shared_first',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    $sharedSecondId = hub_create_customer_user($db, [
        'username' => 'shared_second',
        'password' => 'customer123',
        'modes' => ['hello'],
    ]);
    $sharedMemberId = (int)hub_get_user($db, $sharedFirstId)['api_member_id'];
    $db->prepare('UPDATE users SET api_member_id = :member_id WHERE id = :id')
        ->execute([':member_id' => $sharedMemberId, ':id' => $sharedSecondId]);
    hub_test_assert(hub_delete_customer_user($db, $sharedFirstId) === null, 'customer deletion must not fail for a shared API member');
    hub_test_assert(hub_get_api_member($db, $sharedMemberId) !== null, 'shared API member must remain for the other customer');
    hub_test_assert(hub_delete_customer_user($db, $sharedSecondId) === null, 'last shared customer deletion must succeed');
    hub_test_assert(hub_get_api_member($db, $sharedMemberId) === null, 'unshared API member must be deleted with its last customer');

    $memberId = hub_create_api_member($db, 'Standalone member');
    $token = hub_create_api_token($db, $memberId, 'Delete Token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'hello', (int)hub_get_service_by_mode($db, 'hello')['id']);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.10');
    hub_record_api_token_usage($db, ['member_id' => $memberId, 'token_id' => (int)$token['token_id']], 'hello', true, 10, 0, 20);
    hub_delete_api_token($db, (int)$token['token_id']);
    hub_test_assert(hub_get_api_token($db, (int)$token['token_id']) === null, 'API Token must be deleted');
    hub_test_assert(hub_list_api_token_permissions($db, (int)$token['token_id']) === [], 'Token permissions must cascade on deletion');
    hub_test_assert(hub_list_api_token_ip_rules($db, (int)$token['token_id']) === [], 'Token IP rules must cascade on deletion');
    $usageCount = (int)$db->query('SELECT COUNT(*) FROM api_token_usage_daily')->fetchColumn();
    hub_test_assert($usageCount === 0, 'Token usage must cascade on deletion');

    $memberToken = hub_create_api_token($db, $memberId, 'Delete member Token', null, null);
    hub_delete_api_member($db, $memberId);
    hub_test_assert(hub_get_api_member($db, $memberId) === null, 'standalone API member must be deleted');
    hub_test_assert(hub_get_api_token($db, (int)$memberToken['token_id']) === null, 'member Token must cascade on member deletion');
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

    $customers = (string)file_get_contents(HUB_ROOT . '/admin/customers.php');
    $members = (string)file_get_contents(HUB_ROOT . '/admin/api_members.php');
    $tokens = (string)file_get_contents(HUB_ROOT . '/admin/api_tokens.php');
    hub_test_assert(str_contains($customers, 'hub_delete_customer_user'), 'customer admin page must provide delete action');
    hub_test_assert(str_contains($members, 'hub_delete_api_member'), 'API member admin page must provide delete action');
    hub_test_assert(str_contains($tokens, 'hub_delete_api_token'), 'API Token admin page must provide delete action');

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
