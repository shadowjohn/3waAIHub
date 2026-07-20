<?php
declare(strict_types=1);

function hub_get_user(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        'SELECT u.*, m.name AS api_member_name,
                (SELECT COUNT(*) FROM api_tokens t WHERE t.member_id = u.api_member_id) AS token_count
         FROM users u
         LEFT JOIN api_members m ON m.id = u.api_member_id
         WHERE u.id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function hub_get_user_by_username(PDO $db, string $username): ?array
{
    $stmt = $db->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute([':username' => trim($username)]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function hub_list_users(PDO $db): array
{
    return $db->query(
        'SELECT u.*, m.name AS api_member_name,
                (SELECT COUNT(*) FROM api_tokens t WHERE t.member_id = u.api_member_id) AS token_count
         FROM users u
         LEFT JOIN api_members m ON m.id = u.api_member_id
         ORDER BY u.role DESC, u.id ASC'
    )->fetchAll();
}

function hub_valid_user_role(string $role): string
{
    $role = trim($role);
    if (!in_array($role, ['system_admin', 'customer'], true)) {
        throw new InvalidArgumentException('Invalid role.');
    }

    return $role;
}

function hub_create_customer_user(PDO $db, array $input): int
{
    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,64}$/', $username)) {
        throw new InvalidArgumentException('帳號格式不合法。');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('密碼至少 8 碼。');
    }
    if (hub_get_user_by_username($db, $username)) {
        throw new InvalidArgumentException('帳號已存在。');
    }

    $now = hub_now();
    $displayName = trim((string)($input['display_name'] ?? '')) ?: $username;
    $email = trim((string)($input['email'] ?? ''));
    $company = trim((string)($input['company'] ?? ''));
    $memberId = hub_create_api_member(
        $db,
        $displayName,
        $displayName,
        $email,
        $company
    );

    $stmt = $db->prepare(
        'INSERT INTO users
            (username, password_hash, must_change_password, role, api_member_id, display_name, email, company, is_protected, is_enabled, created_at, updated_at)
         VALUES
            (:username, :password_hash, :must_change_password, :role, :api_member_id, :display_name, :email, :company, 0, :is_enabled, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':must_change_password' => !empty($input['must_change_password']) ? 1 : 0,
        ':role' => hub_valid_user_role((string)($input['role'] ?? 'customer')),
        ':api_member_id' => $memberId,
        ':display_name' => $displayName,
        ':email' => $email,
        ':company' => $company,
        ':is_enabled' => !array_key_exists('is_enabled', $input) || (int)$input['is_enabled'] === 1 ? 1 : 0,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $userId = (int)$db->lastInsertId();
    hub_set_user_mode_permissions($db, $userId, is_array($input['modes'] ?? null) ? $input['modes'] : []);

    return $userId;
}

function hub_ensure_user_api_member(PDO $db, int $userId): int
{
    $user = hub_get_user($db, $userId);
    if (!$user) {
        throw new InvalidArgumentException('找不到帳號。');
    }
    $memberId = (int)($user['api_member_id'] ?? 0);
    if ($memberId > 0 && hub_get_api_member($db, $memberId)) {
        return $memberId;
    }

    $displayName = trim((string)($user['display_name'] ?? '')) ?: (string)$user['username'];
    $memberId = hub_create_api_member(
        $db,
        $displayName,
        $displayName,
        (string)($user['email'] ?? ''),
        (string)($user['company'] ?? '')
    );
    $db->prepare('UPDATE users SET api_member_id = :api_member_id, updated_at = :updated_at WHERE id = :id')
        ->execute([':api_member_id' => $memberId, ':updated_at' => hub_now(), ':id' => $userId]);

    return $memberId;
}

function hub_update_user_admin(PDO $db, int $userId, array $input): ?string
{
    $role = hub_valid_user_role((string)($input['role'] ?? 'customer'));
    $isEnabled = !empty($input['is_enabled']) ? 1 : 0;
    $error = hub_user_admin_update_error($db, $userId, ['role' => $role, 'is_enabled' => $isEnabled]);
    if ($error !== null) {
        return $error;
    }

    $user = hub_get_user($db, $userId);
    if (!$user) {
        return '找不到帳號。';
    }
    if ($role === 'customer') {
        hub_ensure_user_api_member($db, $userId);
    }

    $stmt = $db->prepare(
        'UPDATE users
         SET role = :role, display_name = :display_name, email = :email, company = :company,
             is_enabled = :is_enabled, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':role' => $role,
        ':display_name' => trim((string)($input['display_name'] ?? '')) ?: (string)$user['username'],
        ':email' => trim((string)($input['email'] ?? '')),
        ':company' => trim((string)($input['company'] ?? '')),
        ':is_enabled' => $isEnabled,
        ':updated_at' => hub_now(),
        ':id' => $userId,
    ]);

    $newPassword = (string)($input['new_password'] ?? '');
    if ($newPassword !== '') {
        if (strlen($newPassword) < 8) {
            return '新密碼至少 8 碼。';
        }
        $db->prepare('UPDATE users SET password_hash = :password_hash, must_change_password = 1, updated_at = :updated_at WHERE id = :id')
            ->execute([
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':updated_at' => hub_now(),
                ':id' => $userId,
            ]);
    }

    hub_set_user_mode_permissions($db, $userId, is_array($input['modes'] ?? null) ? $input['modes'] : []);

    return null;
}

function hub_update_current_user_profile(PDO $db, int $userId, array $input): void
{
    $stmt = $db->prepare(
        'UPDATE users
         SET display_name = :display_name, email = :email, company = :company, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':display_name' => trim((string)($input['display_name'] ?? '')),
        ':email' => trim((string)($input['email'] ?? '')),
        ':company' => trim((string)($input['company'] ?? '')),
        ':updated_at' => hub_now(),
        ':id' => $userId,
    ]);
}

function hub_set_user_mode_permissions(PDO $db, int $userId, array $modes): void
{
    $db->prepare('UPDATE user_mode_permissions SET enabled = 0, updated_at = :updated_at WHERE user_id = :user_id')
        ->execute([':updated_at' => hub_now(), ':user_id' => $userId]);

    foreach (array_values(array_unique(array_map('strval', $modes))) as $mode) {
        $mode = trim($mode);
        if ($mode === '') {
            continue;
        }
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
            throw new InvalidArgumentException('Invalid mode.');
        }
        $service = hub_get_service_by_mode($db, $mode);
        $db->prepare(
            'INSERT INTO user_mode_permissions (user_id, service_id, mode, enabled, created_at, updated_at)
             VALUES (:user_id, :service_id, :mode, 1, :created_at, :updated_at)
             ON CONFLICT(user_id, mode) DO UPDATE SET service_id = excluded.service_id, enabled = 1, updated_at = excluded.updated_at'
        )->execute([
            ':user_id' => $userId,
            ':service_id' => $service ? (int)$service['id'] : null,
            ':mode' => $mode,
            ':created_at' => hub_now(),
            ':updated_at' => hub_now(),
        ]);
    }
}

function hub_user_allowed_modes(PDO $db, int $userId): array
{
    $stmt = $db->prepare('SELECT mode FROM user_mode_permissions WHERE user_id = :user_id AND enabled = 1 ORDER BY mode');
    $stmt->execute([':user_id' => $userId]);

    return array_map('strval', array_column($stmt->fetchAll(), 'mode'));
}

function hub_user_token_allowed_modes(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT DISTINCT p.mode
         FROM users u
         JOIN api_members m ON m.id = u.api_member_id
         JOIN api_tokens t ON t.member_id = m.id
         JOIN api_token_service_permissions p ON p.token_id = t.id
         WHERE u.id = :user_id
           AND m.enabled = 1
           AND t.enabled = 1
           AND t.revoked_at IS NULL
           AND (t.valid_from IS NULL OR t.valid_from <= :now)
           AND (t.valid_until IS NULL OR t.valid_until >= :now)
           AND p.enabled = 1
         ORDER BY p.mode'
    );
    $stmt->execute([':user_id' => $userId, ':now' => hub_now()]);

    return array_map('strval', array_column($stmt->fetchAll(), 'mode'));
}

function hub_user_allowed_services(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        'SELECT s.*
         FROM user_mode_permissions p
         JOIN services s ON s.mode = p.mode
         WHERE p.user_id = :user_id AND p.enabled = 1
         ORDER BY s.name'
    );
    $stmt->execute([':user_id' => $userId]);

    return $stmt->fetchAll();
}

function hub_user_has_mode_permission(PDO $db, int $userId, string $mode): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM user_mode_permissions WHERE user_id = :user_id AND mode = :mode AND enabled = 1');
    $stmt->execute([':user_id' => $userId, ':mode' => $mode]);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_create_customer_token(PDO $db, int $userId, string $tokenName, ?string $validUntil = null): array
{
    $memberId = hub_ensure_user_api_member($db, $userId);
    $token = hub_create_api_token($db, $memberId, $tokenName, null, $validUntil);
    $modes = hub_user_allowed_modes($db, $userId);
    if (in_array('photo', $modes, true) && !in_array('photo_upload', $modes, true)) {
        $modes[] = 'photo_upload';
    }
    if (in_array('audio', $modes, true) && !in_array('audio_upload', $modes, true)) {
        $modes[] = 'audio_upload';
    }
    foreach ($modes as $mode) {
        $service = hub_get_service_by_mode($db, $mode);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], $mode, $service ? (int)$service['id'] : null);
    }

    return $token;
}

function hub_customer_owns_token(PDO $db, int $userId, int $tokenId): bool
{
    $user = hub_get_user($db, $userId);
    $memberId = (int)($user['api_member_id'] ?? 0);
    if ($memberId < 1) {
        return false;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM api_tokens WHERE id = :id AND member_id = :member_id');
    $stmt->execute([':id' => $tokenId, ':member_id' => $memberId]);

    return (int)$stmt->fetchColumn() > 0;
}

function hub_list_customer_tokens(PDO $db, int $userId): array
{
    $user = hub_get_user($db, $userId);
    $memberId = (int)($user['api_member_id'] ?? 0);
    if ($memberId < 1) {
        return [];
    }

    return hub_list_api_tokens($db, $memberId);
}

function hub_list_customer_usage(PDO $db, int $userId): array
{
    $user = hub_get_user($db, $userId);
    $memberId = (int)($user['api_member_id'] ?? 0);
    if ($memberId < 1) {
        return [];
    }

    return hub_list_api_usage_daily($db, ['member_id' => $memberId]);
}

function hub_playground_supported_modes(): array
{
    return ['hello', 'translate', 'ocr', 'yolo', 'sam3', 'tts', 'chat', 'photo', 'audio'];
}

function hub_playground_service_options(PDO $db, ?array $user = null): array
{
    $supported = array_fill_keys(hub_playground_supported_modes(), true);
    $allowedModes = null;
    if ($user && hub_is_customer($user)) {
        $grantedModes = hub_user_allowed_modes($db, (int)$user['id']);
        $tokenModes = hub_user_token_allowed_modes($db, (int)$user['id']);
        $allowedModes = array_fill_keys(array_values(array_intersect($grantedModes, $tokenModes)), true);
    }

    $services = [];
    $hasPhoto = false;
    $hasAudio = false;
    foreach (hub_list_services($db) as $service) {
        $mode = (string)($service['mode'] ?? '');
        if (!isset($supported[$mode])) {
            continue;
        }
        if ($allowedModes !== null && !isset($allowedModes[$mode])) {
            continue;
        }
        if ($mode === 'photo') {
            $hasPhoto = true;
        }
        if ($mode === 'audio') {
            $hasAudio = true;
        }
        $services[] = $service;
    }
    if (!$hasPhoto && ($allowedModes === null || isset($allowedModes['photo']))) {
        $settings = hub_photo_settings($db);
        $visionService = hub_get_service_by_key($db, (string)$settings['vision_service_key']);
        if ($visionService) {
            $visionService['mode'] = 'photo';
            $visionService['name'] = 'Gemma 4 Photo Vision';
            $services[] = $visionService;
        }
    }
    if (!$hasAudio && ($allowedModes === null || isset($allowedModes['audio']))) {
        $audioService = hub_get_service_by_key($db, 'gemma4-main');
        if ($audioService) {
            $audioService['mode'] = 'audio';
            $audioService['name'] = 'Gemma 4 Audio Input';
            $services[] = $audioService;
        }
    }

    return $services;
}
