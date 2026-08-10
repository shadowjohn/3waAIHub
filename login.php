<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$error = '';
$siteTitle = hub_site_title($db);
$siteSubtitle = hub_site_subtitle($db);
if (hub_current_user($db)) {
    hub_redirect(hub_login_redirect_path($db));
}

$captchaCode = hub_login_captcha_code();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ip = hub_client_ip();
    $username = trim((string)($_POST['username'] ?? ''));
    $lock = hub_login_lock_status($db, $ip);
    if ($lock['locked']) {
        hub_record_login_failure($db, $ip, $username, 'ip_locked', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $error = hub_i18n_text('登入嘗試過多，請稍後再試。');
    } elseif (!hub_verify_login_captcha((string)($_POST['captcha'] ?? ''))) {
        hub_record_login_attempt($db, $ip, $username, false, 'captcha_failed', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $error = hub_i18n_text('驗證碼錯誤，請重新輸入。');
    } elseif (hub_login_with_lockout($db, $username, (string)($_POST['password'] ?? ''), $ip, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''))['ok']) {
        hub_redirect(hub_login_redirect_path($db));
    } else {
        $error = hub_login_lock_status($db, $ip)['locked']
            ? hub_i18n_text('登入嘗試過多，請稍後再試。')
            : hub_i18n_text('帳號或密碼錯誤，或目前無法登入。');
    }
    $captchaCode = hub_login_captcha_code();
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= hub_h(hub_i18n_text('登入')) ?> - <?= hub_h($siteTitle) ?></title>
    <link rel="icon" href="branding_asset.php">
    <link rel="stylesheet" href="assets/css/admin-base.css">
    <link rel="stylesheet" href="assets/css/admin-login.css">
</head>
<body>
<div class="bg" aria-hidden="true">
    <img class="bg__art" src="assets/images/login-bg.svg" alt="">
    <span class="bg__scan"></span>
</div>

<a class="skip-link" href="#loginForm"><?= hub_h(hub_i18n_text('跳至登入表單')) ?></a>

<div class="page">
    <main class="auth" id="main">
        <section class="brand">
            <div class="brand__top">
                <img class="brand__logo" src="branding_asset.php" width="56" height="56" alt="<?= hub_h($siteTitle . ' ' . hub_i18n_text('標誌')) ?>">
                <div>
                    <h1 class="brand__title"><?= hub_h($siteTitle) ?></h1>
                    <p class="brand__sub"><?= hub_h($siteSubtitle) ?></p>
                </div>
            </div>

            <ul class="brand__list">
                <li class="brand__item">
                    <span class="brand__ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s7-5.2 7-11a7 7 0 1 0-14 0c0 5.8 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>
                    </span>
                    <span><b><?= hub_h(hub_i18n_text('本地 AI 服務整合')) ?></b><em><?= hub_h(hub_i18n_text('集中管理模型、服務與執行環境')) ?></em></span>
                </li>
                <li class="brand__item">
                    <span class="brand__ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="8" y="8" width="8" height="8" rx="2"/><path d="M10 2v3M14 2v3M10 19v3M14 19v3M2 10h3M2 14h3M19 10h3M19 14h3"/></svg>
                    </span>
                    <span><b><?= hub_h(hub_i18n_text('AI 模型服務調度')) ?></b><em><?= hub_h(hub_i18n_text('統一安裝、測試與監控服務')) ?></em></span>
                </li>
                <li class="brand__item">
                    <span class="brand__ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4.5 6v6c0 4.4 3.1 8.3 7.5 9.4 4.4-1.1 7.5-5 7.5-9.4V6L12 3Z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>
                    </span>
                    <span><b><?= hub_h(hub_i18n_text('帳號與權限控管')) ?></b><em><?= hub_h(hub_i18n_text('保留登入與操作稽核記錄')) ?></em></span>
                </li>
            </ul>

            <p class="brand__note"><?= hub_h(hub_i18n_text('本系統僅供授權人員使用，所有操作將留存記錄。')) ?></p>
        </section>

        <section class="panel">
            <div class="panel__brand">
                <img class="panel__brandLogo" src="branding_asset.php" width="44" height="44" alt="<?= hub_h($siteTitle . ' ' . hub_i18n_text('標誌')) ?>">
                <div>
                    <p class="panel__brandName"><?= hub_h($siteTitle) ?></p>
                    <p class="panel__brandSub"><?= hub_h($siteSubtitle) ?></p>
                </div>
            </div>

            <header class="panel__head">
                <div>
                    <h2 class="panel__title"><?= hub_h(hub_i18n_text('系統登入')) ?></h2>
                    <p class="panel__desc"><?= hub_h(hub_i18n_text('請輸入帳號密碼以進入平台')) ?></p>
                </div>
                <div class="lang">
                    <span class="lang__label"><?= hub_h(hub_i18n_text('語言')) ?></span>
                    <?= hub_i18n_language_selector('lang__field') ?>
                </div>
            </header>

            <?php if ($error !== ''): ?>
                <div class="alert" role="alert" aria-live="assertive">
                    <span class="alert__ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7.5v5M12 16h.01"/></svg>
                    </span>
                    <span><?= hub_h($error) ?></span>
                </div>
            <?php endif; ?>

            <form id="loginForm" class="form" method="post" autocomplete="on">
                <div class="field">
                    <label class="field__label" for="username"><?= hub_h(hub_i18n_text('帳號')) ?></label>
                    <div class="control">
                        <span class="control__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 1 0-16 0"/><circle cx="12" cy="8" r="4"/></svg>
                        </span>
                        <input id="username" name="username" type="text" class="control__input" autocomplete="username" required aria-required="true" placeholder="<?= hub_h(hub_i18n_text('請輸入帳號')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="password"><?= hub_h(hub_i18n_text('密碼')) ?></label>
                    <div class="control">
                        <span class="control__ico" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10.5" rx="2.5"/><path d="M8 10.5V7a4 4 0 1 1 8 0v3.5"/><path d="M12 15v2"/></svg>
                        </span>
                        <input id="password" name="password" type="password" class="control__input" autocomplete="current-password" required aria-required="true" placeholder="<?= hub_h(hub_i18n_text('請輸入密碼')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="field__label" for="captcha"><?= hub_h(hub_i18n_text('驗證碼')) ?></label>
                    <div class="captcha">
                        <div class="control captcha__control">
                            <span class="control__ico" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4.5 6v6c0 4.4 3.1 8.3 7.5 9.4 4.4-1.1 7.5-5 7.5-9.4V6L12 3Z"/><path d="m9 12 2.2 2.2L15.5 10"/></svg>
                            </span>
                            <input id="captcha" name="captcha" type="text" class="control__input" inputmode="latin" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="5" required aria-required="true" aria-describedby="captchaHint" placeholder="<?= hub_h(hub_i18n_text('請輸入驗證碼')) ?>">
                        </div>
                        <div class="captcha__img" role="img" aria-label="<?= hub_h(hub_i18n_text('登入驗證碼') . ' ' . $captchaCode) ?>">
                            <span aria-hidden="true"><?= hub_h($captchaCode) ?></span>
                        </div>
                    </div>
                    <p class="hint" id="captchaHint"><?= hub_h(hub_i18n_text('驗證碼不分大小寫。')) ?></p>
                </div>

                <button type="submit" class="btn btn--block">
                    <span><?= hub_h(hub_i18n_text('登入')) ?></span>
                    <svg class="btn__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h13M13 6l6 6-6 6"/></svg>
                </button>
            </form>

            <footer class="panel__foot">
                <span><?= hub_h($siteSubtitle) ?></span>
                <span class="dot" aria-hidden="true">·</span>
                <span><?= hub_h(HUB_VERSION) ?></span>
            </footer>
        </section>
    </main>
</div>
</body>
</html>
