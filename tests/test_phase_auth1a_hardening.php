<?php
declare(strict_types=1);

hub_test('PhaseAuth-1A.1 admin-only pages and POST actions are guarded', function (): void {
    $adminOnlyPages = [
        'services.php',
        'packs.php',
        'marketplace.php',
        'models.php',
        'settings.php',
        'environment.php',
        'log_explorer.php',
        'benchmarks.php',
        'api_members.php',
        'api_tokens.php',
        'api_usage.php',
        'customers.php',
        'customer_edit.php',
        'service_whitelist.php',
        'api_access_logs.php',
        'job_status.php',
    ];
    foreach ($adminOnlyPages as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/admin/' . $file);
        hub_test_assert(str_contains($source, 'hub_require_system_admin'), 'admin-only page missing system_admin guard: ' . $file);
    }

    $postActionPages = [
        'services.php',
        'marketplace.php',
        'models.php',
        'settings.php',
        'customer_edit.php',
        'api_member_edit.php',
        'api_tokens.php',
        'api_token_permissions.php',
        'api_token_whitelist.php',
        'service_whitelist.php',
        'service_settings.php',
        'environment.php',
    ];
    foreach ($postActionPages as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/admin/' . $file);
        $guardAt = strpos($source, 'hub_require_system_admin');
        $postAt = strpos($source, 'REQUEST_METHOD');
        hub_test_assert($guardAt !== false, 'POST page missing admin guard: ' . $file);
        hub_test_assert($postAt === false || $guardAt < $postAt, 'POST guard must run before actions: ' . $file);
    }

    foreach (['services.php', 'service_settings.php', 'environment.php'] as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/admin/' . $file);
        hub_test_assert(str_contains($source, 'hub_enqueue_command_job'), 'expected command job page missing enqueue: ' . $file);
        hub_test_assert(strpos($source, 'hub_require_system_admin') < strpos($source, 'hub_enqueue_command_job'), 'command job enqueue must be behind admin guard: ' . $file);
    }
});

hub_test('PhaseAuth-1A.1 protected admin rules expose delete disable downgrade guards', function (): void {
    $db = hub_test_reset_db();
    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    hub_test_assert($admin !== false, 'admin row missing');
    hub_test_assert(function_exists('hub_count_enabled_system_admins'), 'enabled admin counter missing');
    hub_test_assert(function_exists('hub_can_delete_user'), 'delete guard helper missing');
    hub_test_assert(function_exists('hub_can_disable_user'), 'disable guard helper missing');
    hub_test_assert(function_exists('hub_can_modify_user_role'), 'role guard helper missing');

    hub_test_assert(hub_count_enabled_system_admins($db) === 1, 'expected one enabled system_admin');
    hub_test_assert(hub_can_delete_user($db, (int)$admin['id']) !== null, 'protected admin delete must be blocked');
    hub_test_assert(hub_can_disable_user($db, (int)$admin['id']) !== null, 'protected admin disable must be blocked');
    hub_test_assert(hub_can_modify_user_role($db, (int)$admin['id'], 'customer') !== null, 'protected admin downgrade must be blocked');

    $otherAdmin = hub_create_customer_user($db, [
        'username' => 'admin2',
        'password' => 'admin222',
        'role' => 'system_admin',
        'display_name' => 'Admin 2',
    ]);
    hub_test_assert(hub_count_enabled_system_admins($db) === 2, 'expected two enabled system_admins');
    hub_test_assert(hub_can_disable_user($db, $otherAdmin) === null, 'second admin should be disable-able');
    hub_test_assert(hub_can_modify_user_role($db, $otherAdmin, 'customer') === null, 'second admin should be downgrade-able');
});

hub_test('PhaseAuth-1A.1 login hardening and role nav behave as expected', function (): void {
    $db = hub_test_reset_db();
    $customerId = hub_create_customer_user($db, [
        'username' => 'nav_customer',
        'password' => 'customer123',
        'display_name' => 'Nav Customer',
        'is_enabled' => 0,
    ]);
    hub_test_assert(!hub_login($db, 'nav_customer', 'customer123'), 'disabled user login must be rejected');

    $db->prepare('UPDATE users SET is_enabled = 1 WHERE id = :id')->execute([':id' => $customerId]);
    $_SESSION = [];
    hub_test_assert(hub_login($db, 'nav_customer', 'customer123'), 'enabled customer login should pass');
    $customer = hub_get_user($db, $customerId);
    hub_test_assert(!empty($customer['last_login_at']), 'last_login_at should update on login');
    hub_test_assert(hub_login_redirect_path($db) === 'admin/my_services.php', 'customer should land on customer area');

    ob_start();
    hub_admin_header('測試', $customer);
    $customerNav = (string)ob_get_clean();
    foreach (['我的服務', '我的 Token', '我的用量', '帳號資料', '變更密碼', 'API 文件', 'API 測試場', '登出'] as $label) {
        hub_test_assert(str_contains($customerNav, $label), 'customer nav missing ' . $label);
    }
    foreach (['服務管理', 'HubPack 套件', '安裝套件', '模型倉庫', '系統設定', '系統環境', 'Log Explorer', '客戶管理'] as $label) {
        hub_test_assert(!str_contains($customerNav, $label), 'customer nav must not show ' . $label);
    }

    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    ob_start();
    hub_admin_header('測試', $admin);
    $adminNav = (string)ob_get_clean();
    foreach (['服務管理', 'HubPack 套件', '安裝套件', '模型倉庫', '系統設定', '系統環境', 'Log Explorer', '客戶管理'] as $label) {
        hub_test_assert(str_contains($adminNav, $label), 'system_admin nav missing ' . $label);
    }
});
