<?php
declare(strict_types=1);

function hub_current_user(PDO $db): ?array
{
    hub_start_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT id, username, must_change_password, role, api_member_id, display_name, email, company,
                is_protected, is_enabled, last_login_at
         FROM users
         WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || (int)($user['is_enabled'] ?? 1) !== 1) {
        hub_logout();
        return null;
    }

    return $user ?: null;
}

function hub_login(PDO $db, string $username, string $password): bool
{
    hub_start_session();
    $stmt = $db->prepare('SELECT id, username, password_hash, is_enabled FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || (int)($user['is_enabled'] ?? 1) !== 1 || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $db->prepare('UPDATE users SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :id')
        ->execute([':last_login_at' => hub_now(), ':updated_at' => hub_now(), ':id' => (int)$user['id']]);
    hub_audit($db, $user['username'], 'login', 'admin login');

    return true;
}

function hub_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '0.0.0.0';
    }

    return substr($ip, 0, 128);
}

function hub_login_lock_status(PDO $db, string $ip): array
{
    $stmt = $db->prepare('SELECT * FROM login_ip_locks WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    if (!$row || empty($row['locked_until'])) {
        return ['locked' => false, 'locked_until' => null, 'remaining_seconds' => 0];
    }

    $lockedUntil = strtotime((string)$row['locked_until']);
    if ($lockedUntil === false || $lockedUntil <= time()) {
        $db->prepare('UPDATE login_ip_locks SET failed_count = 0, locked_until = NULL, updated_at = :updated_at WHERE ip = :ip')
            ->execute([':updated_at' => hub_now(), ':ip' => $ip]);

        return ['locked' => false, 'locked_until' => null, 'remaining_seconds' => 0];
    }

    return [
        'locked' => true,
        'locked_until' => (string)$row['locked_until'],
        'remaining_seconds' => max(0, $lockedUntil - time()),
    ];
}

function hub_record_login_attempt(PDO $db, string $ip, string $username, bool $success, string $reason, string $userAgent): void
{
    $stmt = $db->prepare(
        'INSERT INTO login_attempts (ip, username, success, reason, user_agent, created_at)
         VALUES (:ip, :username, :success, :reason, :user_agent, :created_at)'
    );
    $stmt->execute([
        ':ip' => substr($ip, 0, 128),
        ':username' => substr($username, 0, 128),
        ':success' => $success ? 1 : 0,
        ':reason' => substr($reason, 0, 64),
        ':user_agent' => substr($userAgent, 0, 255),
        ':created_at' => hub_now(),
    ]);
}

function hub_record_login_failure(PDO $db, string $ip, string $username, string $reason = 'invalid_login', string $userAgent = ''): void
{
    hub_record_login_attempt($db, $ip, $username, false, $reason, $userAgent);
    if ($reason === 'ip_locked') {
        return;
    }

    $now = time();
    $windowSeconds = max(1, (int)hub_get_storage_setting($db, 'AIHUB_LOGIN_FAIL_WINDOW_MINUTES')) * 60;
    $maxFailures = max(1, (int)hub_get_storage_setting($db, 'AIHUB_LOGIN_MAX_FAILED_ATTEMPTS'));
    $lockSeconds = max(1, (int)hub_get_storage_setting($db, 'AIHUB_LOGIN_LOCK_MINUTES')) * 60;

    $stmt = $db->prepare('SELECT failed_count, last_failed_at FROM login_ip_locks WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
    $row = $stmt->fetch();
    $lastFailed = $row && !empty($row['last_failed_at']) ? strtotime((string)$row['last_failed_at']) : false;
    $failedCount = ($lastFailed !== false && $lastFailed >= ($now - $windowSeconds))
        ? ((int)$row['failed_count'] + 1)
        : 1;
    $lockedUntil = $failedCount >= $maxFailures ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;

    $db->prepare(
        'INSERT INTO login_ip_locks (ip, failed_count, locked_until, last_failed_at, updated_at)
         VALUES (:ip, :failed_count, :locked_until, :last_failed_at, :updated_at)
         ON CONFLICT(ip) DO UPDATE SET
             failed_count = excluded.failed_count,
             locked_until = excluded.locked_until,
             last_failed_at = excluded.last_failed_at,
             updated_at = excluded.updated_at'
    )->execute([
        ':ip' => $ip,
        ':failed_count' => $failedCount,
        ':locked_until' => $lockedUntil,
        ':last_failed_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);
}

function hub_record_login_success(PDO $db, string $ip, int $userId, string $username = '', string $userAgent = ''): void
{
    hub_record_login_attempt($db, $ip, $username, true, 'success', $userAgent);
    $db->prepare(
        'INSERT INTO login_ip_locks (ip, failed_count, locked_until, last_failed_at, updated_at)
         VALUES (:ip, 0, NULL, NULL, :updated_at)
         ON CONFLICT(ip) DO UPDATE SET failed_count = 0, locked_until = NULL, updated_at = excluded.updated_at'
    )->execute([':ip' => $ip, ':updated_at' => hub_now()]);
}

function hub_login_with_lockout(PDO $db, string $username, string $password, ?string $ip = null, ?string $userAgent = null): array
{
    $ip = $ip !== null ? substr(trim($ip), 0, 128) : hub_client_ip();
    $userAgent = $userAgent ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $lockStatus = hub_login_lock_status($db, $ip);
    if ($lockStatus['locked']) {
        hub_record_login_failure($db, $ip, $username, 'ip_locked', $userAgent);
        return ['ok' => false, 'reason' => 'ip_locked', 'lock' => $lockStatus];
    }

    if (!hub_login($db, $username, $password)) {
        hub_record_login_failure($db, $ip, $username, 'invalid_login', $userAgent);
        return ['ok' => false, 'reason' => 'invalid_login', 'lock' => hub_login_lock_status($db, $ip)];
    }

    $userId = (int)($_SESSION['user_id'] ?? 0);
    hub_record_login_success($db, $ip, $userId, $username, $userAgent);

    return ['ok' => true, 'reason' => 'success', 'lock' => hub_login_lock_status($db, $ip)];
}

function hub_prune_login_attempts(PDO $db): void
{
    $db->prepare('DELETE FROM login_attempts WHERE created_at < :cutoff')
        ->execute([':cutoff' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
}

function hub_login_redirect_path(PDO $db): string
{
    $user = hub_current_user($db);
    if ($user && hub_current_user_role_from_row($user) === 'customer') {
        return 'admin/my_services.php';
    }

    return 'admin/';
}

function hub_login_captcha_code(bool $refresh = false): string
{
    hub_start_session();
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }

    if ($refresh || empty($_SESSION['login_captcha_code']) || !is_string($_SESSION['login_captcha_code'])) {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $_SESSION['login_captcha_code'] = $code;
    }

    return (string)$_SESSION['login_captcha_code'];
}

function hub_verify_login_captcha(string $answer): bool
{
    $expected = hub_login_captcha_code();
    $normalizedAnswer = strtoupper(trim($answer));
    $ok = $expected !== '' && hash_equals($expected, $normalizedAnswer);
    hub_login_captcha_code(true);

    return $ok;
}

function hub_require_login(PDO $db): array
{
    $user = hub_current_user($db);
    if (!$user) {
        hub_redirect('../login.php');
    }

    return $user;
}

function hub_current_user_role(PDO $db): ?string
{
    $user = hub_current_user($db);

    return $user ? hub_current_user_role_from_row($user) : null;
}

function hub_current_user_role_from_row(array $user): string
{
    return (string)($user['role'] ?? 'system_admin');
}

function hub_is_system_admin(array $user): bool
{
    return hub_current_user_role_from_row($user) === 'system_admin';
}

function hub_is_customer(array $user): bool
{
    return hub_current_user_role_from_row($user) === 'customer';
}

function hub_current_api_member_id(array $user): ?int
{
    $memberId = (int)($user['api_member_id'] ?? 0);

    return $memberId > 0 ? $memberId : null;
}

function hub_require_system_admin(PDO $db): array
{
    $user = hub_require_login($db);
    if (!hub_is_system_admin($user)) {
        http_response_code(403);
        exit('Forbidden');
    }

    return $user;
}

function hub_require_customer_or_admin(PDO $db): array
{
    return hub_require_login($db);
}

function hub_logout(): void
{
    hub_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function hub_update_password(PDO $db, int $userId, string $currentPassword, string $newPassword): ?string
{
    if (strlen($newPassword) < 8) {
        return '新密碼至少 8 碼。';
    }

    $stmt = $db->prepare('SELECT username, password_hash FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        return '目前密碼不正確。';
    }

    $stmt = $db->prepare(
        'UPDATE users SET password_hash = :password_hash, must_change_password = 0, updated_at = :updated_at WHERE id = :id'
    );
    $stmt->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':updated_at' => hub_now(),
        ':id' => $userId,
    ]);
    hub_audit($db, $user['username'], 'password_update', 'admin password changed');

    return null;
}

function hub_user_admin_update_error(PDO $db, int $userId, array $changes): ?string
{
    $stmt = $db->prepare('SELECT id, username, role, is_protected, is_enabled FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return '找不到帳號。';
    }

    $newRole = (string)($changes['role'] ?? $user['role']);
    $newEnabled = array_key_exists('is_enabled', $changes) ? (int)$changes['is_enabled'] : (int)$user['is_enabled'];

    if ((int)$user['is_protected'] === 1) {
        if ($newRole !== 'system_admin') {
            return '受保護 admin 帳號不可降級。';
        }
        if ($newEnabled !== 1) {
            return '受保護 admin 帳號不可停用。';
        }
    }

    if ((string)$user['role'] === 'system_admin' && ($newRole !== 'system_admin' || $newEnabled !== 1)) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM users
             WHERE role = 'system_admin' AND is_enabled = 1 AND id <> :id"
        );
        $stmt->execute([':id' => $userId]);
        if ((int)$stmt->fetchColumn() < 1) {
            return '不可移除最後一個啟用中的 system_admin。';
        }
    }

    return null;
}

function hub_count_enabled_system_admins(PDO $db): int
{
    return (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND is_enabled = 1")->fetchColumn();
}

function hub_can_modify_user_role(PDO $db, int $userId, string $newRole): ?string
{
    return hub_user_admin_update_error($db, $userId, ['role' => $newRole]);
}

function hub_can_disable_user(PDO $db, int $userId): ?string
{
    return hub_user_admin_update_error($db, $userId, ['is_enabled' => 0]);
}

function hub_can_delete_user(PDO $db, int $userId): ?string
{
    $stmt = $db->prepare('SELECT role, is_protected FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return '找不到帳號。';
    }
    if ((int)$user['is_protected'] === 1) {
        return '受保護 admin 帳號不可刪除。';
    }
    if ((string)$user['role'] === 'system_admin' && hub_count_enabled_system_admins($db) <= 1) {
        return '不可刪除最後一個啟用中的 system_admin。';
    }

    return null;
}

function hub_csrf_token(): string
{
    hub_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['csrf_token'];
}

function hub_check_csrf(): void
{
    hub_start_session();
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(400);
        exit('Bad request');
    }
}

function hub_audit(PDO $db, string $username, string $action, string $details): void
{
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (username, action, details, created_at)
         VALUES (:username, :action, :details, :created_at)'
    );
    $stmt->execute([
        ':username' => $username,
        ':action' => $action,
        ':details' => $details,
        ':created_at' => hub_now(),
    ]);
}
