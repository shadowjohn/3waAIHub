<?php
declare(strict_types=1);

hub_test('PhaseAuth-1A.2 login IP lockout locks after three failures and expires', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $ip = '203.0.113.77';

    hub_test_assert(hub_login_lock_status($db, $ip)['locked'] === false, 'fresh IP should not be locked');
    hub_test_assert(hub_login_with_lockout($db, 'admin', 'bad1', $ip, 'test-agent')['ok'] === false, 'bad password should fail');
    hub_test_assert(hub_login_lock_status($db, $ip)['locked'] === false, 'one failure should not lock');
    hub_test_assert(hub_login_with_lockout($db, 'admin', 'bad2', $ip, 'test-agent')['ok'] === false, 'second bad password should fail');
    hub_test_assert(hub_login_lock_status($db, $ip)['locked'] === false, 'two failures should not lock');
    hub_test_assert(hub_login_with_lockout($db, 'admin', 'bad3', $ip, 'test-agent')['ok'] === false, 'third bad password should fail');

    $locked = hub_login_lock_status($db, $ip);
    hub_test_assert($locked['locked'] === true, 'third failure should lock IP');
    $_SESSION = [];
    $lockedLogin = hub_login_with_lockout($db, 'admin', 'admin123', $ip, 'test-agent');
    hub_test_assert($lockedLogin['ok'] === false && $lockedLogin['reason'] === 'ip_locked', 'locked IP must not verify correct password');
    hub_test_assert(empty($_SESSION['user_id']), 'locked login must not create session');

    $db->prepare('UPDATE login_ip_locks SET locked_until = :locked_until WHERE ip = :ip')
        ->execute([':locked_until' => date('Y-m-d H:i:s', time() - 60), ':ip' => $ip]);
    $_SESSION = [];
    hub_test_assert(hub_login_with_lockout($db, 'admin', 'admin123', $ip, 'test-agent')['ok'] === true, 'expired lock should allow correct login');
});

hub_test('PhaseAuth-1A.2 successful login resets failure count and records attempts', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $ip = '203.0.113.78';

    hub_login_with_lockout($db, 'admin', 'bad1', $ip, 'ua-a');
    hub_login_with_lockout($db, 'admin', 'bad2', $ip, 'ua-a');
    $before = $db->query("SELECT failed_count FROM login_ip_locks WHERE ip = '203.0.113.78'")->fetchColumn();
    hub_test_assert((int)$before === 2, 'two failures should be counted');

    $_SESSION = [];
    hub_test_assert(hub_login_with_lockout($db, 'admin', 'admin123', $ip, 'ua-a')['ok'] === true, 'correct login should pass');
    $lock = $db->query("SELECT failed_count, locked_until FROM login_ip_locks WHERE ip = '203.0.113.78'")->fetch();
    hub_test_assert((int)$lock['failed_count'] === 0, 'successful login should reset failed_count');
    hub_test_assert($lock['locked_until'] === null || $lock['locked_until'] === '', 'successful login should clear locked_until');

    $attempts = $db->query("SELECT success, reason, user_agent FROM login_attempts WHERE ip = '203.0.113.78' ORDER BY id")->fetchAll();
    hub_test_assert(count($attempts) === 3, 'login attempts should audit failures and success');
    hub_test_assert((int)$attempts[0]['success'] === 0 && (string)$attempts[0]['reason'] === 'invalid_login', 'failure reason should be generic');
    hub_test_assert((int)$attempts[2]['success'] === 1 && (string)$attempts[2]['user_agent'] === 'ua-a', 'success attempt should be recorded');
});

hub_test('PhaseAuth-1A.2 lockout scopes by REMOTE_ADDR and ignores forwarded headers', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.200';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.201';
    hub_test_assert(hub_client_ip() === '198.51.100.10', 'client IP must use REMOTE_ADDR only');

    hub_login_with_lockout($db, 'admin', 'bad1', '198.51.100.10', '');
    hub_login_with_lockout($db, 'admin', 'bad2', '198.51.100.10', '');
    hub_login_with_lockout($db, 'admin', 'bad3', '198.51.100.10', '');
    hub_test_assert(hub_login_lock_status($db, '198.51.100.10')['locked'] === true, 'first IP should lock');
    hub_test_assert(hub_login_lock_status($db, '198.51.100.11')['locked'] === false, 'different IP should not be locked');
});

hub_test('PhaseAuth-1A.2 disabled user counts as generic login failure', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $customerId = hub_create_customer_user($db, [
        'username' => 'disabled_lockout',
        'password' => 'customer123',
        'is_enabled' => 0,
    ]);
    hub_test_assert($customerId > 0, 'customer should be created');
    $result = hub_login_with_lockout($db, 'disabled_lockout', 'customer123', '203.0.113.79', 'ua-disabled');
    hub_test_assert($result['ok'] === false && $result['reason'] === 'invalid_login', 'disabled user should fail generically');
    $attempt = $db->query("SELECT success, reason FROM login_attempts WHERE ip = '203.0.113.79' ORDER BY id DESC LIMIT 1")->fetch();
    hub_test_assert((int)$attempt['success'] === 0 && (string)$attempt['reason'] === 'invalid_login', 'disabled user attempt should be audited generically');
});

hub_test('PhaseAuth-1A.2 captcha failures are audited but do not lock IP', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    $ip = '203.0.113.80';

    for ($i = 0; $i < 3; $i++) {
        hub_record_login_attempt($db, $ip, 'admin', false, 'captcha_failed', 'ua-captcha');
    }

    hub_test_assert(hub_login_lock_status($db, $ip)['locked'] === false, 'captcha failures must not lock IP');
    $attempts = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE ip = '203.0.113.80' AND reason = 'captcha_failed'")->fetchColumn();
    hub_test_assert($attempts === 3, 'captcha failures should still be audited');
    $loginSource = (string)file_get_contents(HUB_ROOT . '/login.php');
    hub_test_assert(str_contains($loginSource, "'captcha_failed'"), 'login page should audit captcha failures separately');
});

hub_test('PhaseAuth-1A.2 login lockout defaults and schema exist', function (): void {
    $db = hub_test_reset_db();
    hub_ensure_default_storage_settings($db);
    foreach (['login_attempts', 'login_ip_locks'] as $table) {
        $exists = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'")->fetchColumn();
        hub_test_assert($exists === $table, 'missing login lockout table ' . $table);
    }
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_LOGIN_MAX_FAILED_ATTEMPTS') === '3', 'max failed attempts default mismatch');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_LOGIN_LOCK_MINUTES') === '5', 'lock minutes default mismatch');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_LOGIN_FAIL_WINDOW_MINUTES') === '10', 'fail window default mismatch');
});
