<?php
declare(strict_types=1);

function hub_current_user(PDO $db): ?array
{
    hub_start_session();
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, username, must_change_password FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function hub_login(PDO $db, string $username, string $password): bool
{
    hub_start_session();
    $stmt = $db->prepare('SELECT id, username, password_hash FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    hub_audit($db, $user['username'], 'login', 'admin login');

    return true;
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

function hub_logout(): void
{
    hub_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
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
