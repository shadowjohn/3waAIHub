<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
$error = '';
if (hub_current_user($db)) {
    hub_redirect('admin/');
}

$captchaCode = hub_login_captcha_code();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hub_verify_login_captcha((string)($_POST['captcha'] ?? ''))) {
        $error = '驗證碼錯誤。';
    } elseif (hub_login($db, trim((string)($_POST['username'] ?? '')), (string)($_POST['password'] ?? ''))) {
        hub_redirect('admin/');
    } else {
        $error = '帳號或密碼錯誤。';
    }
    $captchaCode = hub_login_captcha_code();
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - 3waAIHub</title>
    <style>
        body { align-items: center; background: #f6f7f9; display: flex; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; justify-content: center; min-height: 100vh; margin: 0; }
        form { background: #fff; border: 1px solid #d9dee7; border-radius: 8px; padding: 24px; width: min(360px, calc(100vw - 32px)); }
        input, button { box-sizing: border-box; font: inherit; width: 100%; }
        input { border: 1px solid #d9dee7; border-radius: 6px; margin: 6px 0 14px; padding: 9px 10px; }
        button { background: #1769e0; border: 0; border-radius: 6px; color: #fff; cursor: pointer; padding: 10px; }
        .captcha-box { align-items: center; background: #0b1220; border: 1px solid #243b53; border-radius: 6px; color: #54e68b; display: flex; font-family: "SFMono-Regular", Consolas, monospace; justify-content: center; margin: 6px 0 10px; padding: 10px 12px; }
        .captcha-code { font-size: 20px; font-weight: 700; letter-spacing: 4px; user-select: none; }
        .error { color: #b42318; margin-bottom: 12px; }
        .muted { color: #667085; }
    </style>
</head>
<body>
<form method="post">
    <h1>3waAIHub</h1>
    <p class="muted">本機管理後台</p>
    <?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
    <label>帳號</label>
    <input name="username" autocomplete="username" required>
    <label>密碼</label>
    <input name="password" type="password" autocomplete="current-password" required>
    <label>驗證碼</label>
    <div class="captcha-box" aria-label="登入驗證碼">
        <span class="captcha-code"><?= hub_h($captchaCode) ?></span>
    </div>
    <input name="captcha" autocomplete="off" autocapitalize="characters" required>
    <button type="submit">登入</button>
</form>
</body>
</html>
