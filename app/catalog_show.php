<?php
declare(strict_types=1);

function hub_catalog_show_items(): array
{
    return [
        'ocr' => [
            'title' => 'OCR',
            'name' => 'OCR 文字辨識',
            'summary' => '上傳圖片，辨識文字區塊與信心值。',
            'kind' => 'image',
            'sample' => '維修手冊、掃描頁、照片文字',
        ],
        'yolo' => [
            'title' => 'YOLO',
            'name' => '物件偵測',
            'summary' => '辨識畫面中的物件、位置與 confidence。',
            'kind' => 'image',
            'sample' => '監視器畫面、設備照片、現場巡檢',
        ],
        'sam3' => [
            'title' => 'SAM3',
            'name' => '語意分割',
            'summary' => '用文字或點選提示切出 polygon / mask metadata。',
            'kind' => 'sam3',
            'sample' => 'text=mammal/insect/plant',
        ],
        'chat' => [
            'title' => 'Gemma Chat',
            'name' => '文字對話',
            'summary' => '本機 LLM 問答，適合摘要、說明與工作輔助。',
            'kind' => 'json',
            'sample' => '請用正體中文整理這段技術內容。',
        ],
        'photo' => [
            'title' => 'Photo Vision',
            'name' => '圖片理解',
            'summary' => '先上傳圖片取得 image_id，再針對圖片追問。',
            'kind' => 'photo',
            'sample' => '這張圖裡有什麼？',
        ],
        'docparser' => [
            'title' => 'DocParser',
            'name' => '文件解析機',
            'summary' => 'PDF 解析、翻譯、HTML / Markdown / RAG chunks 匯出。',
            'kind' => 'document',
            'sample' => '維修手冊、規格書、技術文件',
        ],
    ];
}

function hub_catalog_show_user_modes(PDO $db, ?array $user): array
{
    if (!$user) {
        return [];
    }
    $supported = array_fill_keys(array_keys(hub_catalog_show_items()), true);
    if (hub_is_system_admin($user)) {
        $modes = array_keys($supported);
        sort($modes);

        return $modes;
    }
    $grantedModes = hub_user_allowed_modes($db, (int)$user['id']);
    $tokenModes = hub_user_token_allowed_modes($db, (int)$user['id']);
    $modes = array_values(array_filter(array_intersect($grantedModes, $tokenModes), static fn (string $mode): bool => isset($supported[$mode])));
    sort($modes);

    return $modes;
}

function hub_catalog_show_pick_user_token(PDO $db, array $user, string $mode): ?array
{
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        return null;
    }
    $memberId = hub_current_api_member_id($user);
    if ($memberId === null && hub_is_system_admin($user)) {
        $stmt = $db->prepare(
            'SELECT t.*
             FROM api_tokens t
             JOIN api_token_service_permissions p ON p.token_id = t.id
             JOIN api_members m ON m.id = t.member_id
             WHERE p.mode = :mode
               AND p.enabled = 1
               AND t.enabled = 1
               AND t.revoked_at IS NULL
               AND m.enabled = 1
               AND (t.valid_from IS NULL OR t.valid_from <= :now)
               AND (t.valid_until IS NULL OR t.valid_until >= :now)
             ORDER BY t.id DESC
             LIMIT 1'
        );
        $stmt->execute([':mode' => $mode, ':now' => hub_now()]);
        $token = $stmt->fetch();

        return $token ?: null;
    }
    if ($memberId === null) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT t.*
         FROM api_tokens t
         JOIN api_token_service_permissions p ON p.token_id = t.id
         WHERE t.member_id = :member_id
           AND p.mode = :mode
           AND p.enabled = 1
           AND t.enabled = 1
           AND t.revoked_at IS NULL
           AND (t.valid_from IS NULL OR t.valid_from <= :now)
           AND (t.valid_until IS NULL OR t.valid_until >= :now)
         ORDER BY t.id DESC
         LIMIT 1'
    );
    $stmt->execute([':member_id' => $memberId, ':mode' => $mode, ':now' => hub_now()]);
    $token = $stmt->fetch();

    return $token ?: null;
}

function hub_catalog_show_session_token(PDO $db, array $user, string $mode): string
{
    hub_start_session();
    $_SESSION['catalog_show_tokens'] ??= [];
    $key = (string)($user['id'] ?? 0) . ':catalog';
    if (is_string($_SESSION['catalog_show_tokens'][$key] ?? null)) {
        return (string)$_SESSION['catalog_show_tokens'][$key];
    }
    if (!in_array($mode, hub_catalog_show_user_modes($db, $user), true)) {
        return '';
    }
    if (hub_is_customer($user)) {
        $token = hub_create_customer_token($db, (int)$user['id'], 'Catalog Show session token');
        $_SESSION['catalog_show_tokens'][$key] = (string)$token['plain_token'];

        return (string)$token['plain_token'];
    }
    if (!hub_is_system_admin($user)) {
        return '';
    }

    $memberId = hub_catalog_show_admin_member($db, $user);
    $token = hub_create_api_token($db, $memberId, 'Catalog Show admin session token', null, null);
    $modes = array_keys(hub_catalog_show_items());
    $modes[] = 'photo_upload';
    foreach (array_unique($modes) as $allowedMode) {
        $service = hub_get_service_by_mode($db, $allowedMode);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], $allowedMode, $service ? (int)$service['id'] : null);
    }
    $_SESSION['catalog_show_tokens'][$key] = (string)$token['plain_token'];

    return (string)$token['plain_token'];
}

function hub_catalog_show_admin_member(PDO $db, array $user): int
{
    $email = 'catalog-show-admin-' . (int)($user['id'] ?? 0) . '@local.3waaihub';
    $stmt = $db->prepare('SELECT id FROM api_members WHERE contact_email = :email ORDER BY id DESC LIMIT 1');
    $stmt->execute([':email' => $email]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    return hub_create_api_member($db, 'Catalog Show Admin', (string)($user['username'] ?? 'admin'), $email, 'Auto-created for catalog_show logged-in demos.');
}

function hub_catalog_show_api_url(string $mode): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/catalog_show/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base = preg_replace('#/catalog_show$#', '', $dir) ?: '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host . $base . '/api.php?mode=' . rawurlencode($mode);
}

function hub_catalog_show_local_api_url(string $mode): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/catalog_show/index.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    $base = preg_replace('#/catalog_show$#', '', $dir) ?: '';

    return 'http://127.0.0.1' . $base . '/api.php?mode=' . rawurlencode($mode);
}
