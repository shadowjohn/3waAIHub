<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_i18n_apply_request_language();
header("Content-Security-Policy: default-src 'none'; connect-src 'self'; img-src 'self' blob:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$labels = [
    'connecting' => __('連線中'),
    'waiting' => __('等待登入'),
    'logged_in' => __('登入完成'),
    'unavailable' => __('連線失敗'),
    'invalid' => __('登入連結無效或已過期'),
    'closed' => __('已關閉'),
];
?>
<!doctype html>
<html lang="<?= hub_h(str_replace('_', '-', hub_i18n_current_lang())) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= hub_h(__('Facebook 登入')) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #17191c; color: #f5f7f8; font: 16px system-ui, sans-serif; }
        main { width: min(1180px, 100%); margin: 0 auto; padding: 20px; }
        header, .controls, .statusbar { display: flex; align-items: center; gap: 10px; }
        header { justify-content: space-between; margin-bottom: 14px; }
        h1 { margin: 0; font-size: 22px; letter-spacing: 0; }
        .screen { width: 100%; aspect-ratio: 16 / 9; background: #fff; border: 1px solid #4d5359; overflow: hidden; }
        .screen img { width: 100%; height: 100%; display: block; object-fit: contain; cursor: crosshair; }
        .statusbar { min-height: 42px; justify-content: space-between; }
        .controls { flex-wrap: wrap; padding-top: 12px; }
        label { font-weight: 600; }
        input { min-width: min(360px, 100%); flex: 1; border: 1px solid #767d84; background: #fff; color: #111; padding: 10px 12px; }
        button { min-height: 42px; border: 1px solid #697078; background: #272b30; color: #fff; padding: 8px 13px; cursor: pointer; }
        button:hover, button:focus-visible { background: #343a40; border-color: #aeb6bd; }
        button.icon { width: 44px; padding: 6px; font-size: 21px; }
        button.danger { border-color: #a94b50; background: #7a282e; }
        button:disabled, input:disabled { opacity: .55; cursor: default; }
        #status { color: #d4dae0; }
        @media (max-width: 640px) {
            main { padding: 12px; }
            header { align-items: flex-start; }
            h1 { font-size: 19px; }
            .controls label { width: 100%; }
            input { min-width: 0; width: 100%; }
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1><?= hub_h(__('Facebook 登入')) ?></h1>
    </header>
    <div class="statusbar">
        <strong><?= hub_h(__('登入狀態')) ?></strong>
        <span id="status" role="status" aria-live="polite"><?= hub_h(__('連線中')) ?></span>
    </div>
    <div class="screen"><img id="frame" width="1280" height="720" alt="<?= hub_h(__('Facebook 登入畫面')) ?>"></div>
    <div class="controls">
        <label for="text-input"><?= hub_h(__('輸入')) ?></label>
        <input id="text-input" type="password" autocomplete="off" autocapitalize="off" spellcheck="false">
        <button id="send" type="button"><?= hub_h(__('送出')) ?></button>
        <button type="button" data-key="Tab" title="<?= hub_h(__('Tab')) ?>"><?= hub_h(__('Tab')) ?></button>
        <button type="button" data-key="Enter" title="<?= hub_h(__('Enter')) ?>"><?= hub_h(__('Enter')) ?></button>
        <button type="button" data-key="Backspace" title="<?= hub_h(__('退格')) ?>">&#9003;</button>
        <button class="icon" type="button" data-scroll="up" title="<?= hub_h(__('向上捲動')) ?>" aria-label="<?= hub_h(__('向上捲動')) ?>">&#8593;</button>
        <button class="icon" type="button" data-scroll="down" title="<?= hub_h(__('向下捲動')) ?>" aria-label="<?= hub_h(__('向下捲動')) ?>">&#8595;</button>
        <button class="danger" id="close" type="button"><?= hub_h(__('關閉')) ?></button>
    </div>
</main>
<script>
(() => {
    const fragment = new URLSearchParams(location.hash.slice(1));
    let proof = fragment.get('session') || '';
    history.replaceState(null, '', location.pathname + location.search);

    const labels = <?= hub_json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const frame = document.getElementById('frame');
    const status = document.getElementById('status');
    const textInput = document.getElementById('text-input');
    let active = /^[A-Za-z0-9_-]{43}$/.test(proof);
    let frameUrl = '';

    const relay = async (mode, payload = {}) => fetch(`api.php?mode=${mode}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ proof, ...payload }),
        cache: 'no-store',
        credentials: 'same-origin'
    });

    const setEnabled = enabled => {
        document.querySelectorAll('button, input').forEach(element => { element.disabled = !enabled; });
    };

    const sendInput = async payload => {
        if (!active) return;
        const response = await relay('facebook_profile_input', payload);
        if (!response.ok) throw new Error('relay');
    };

    const loadFrame = async () => {
        if (!active) return;
        try {
            const response = await relay('facebook_profile_frame');
            if (!response.ok || response.headers.get('Content-Type') !== 'image/png') throw new Error('frame');
            const nextUrl = URL.createObjectURL(await response.blob());
            frame.src = nextUrl;
            if (frameUrl) URL.revokeObjectURL(frameUrl);
            frameUrl = nextUrl;
        } catch (_) {
            status.textContent = labels.unavailable;
        }
    };

    const poll = async () => {
        if (!active) return;
        try {
            const response = await relay('facebook_profile_login_status');
            const data = await response.json();
            status.textContent = data.logged_in ? labels.logged_in : labels.waiting;
        } catch (_) {
            status.textContent = labels.unavailable;
        }
        if (active) window.setTimeout(poll, 1000);
    };

    frame.addEventListener('click', event => {
        const rect = frame.getBoundingClientRect();
        const x = Math.round((event.clientX - rect.left) * 1280 / rect.width);
        const y = Math.round((event.clientY - rect.top) * 720 / rect.height);
        sendInput({ type: 'click', x, y }).catch(() => { status.textContent = labels.unavailable; });
    });
    document.getElementById('send').addEventListener('click', () => {
        const text = textInput.value;
        textInput.value = '';
        sendInput({ type: 'text', text }).catch(() => { status.textContent = labels.unavailable; });
    });
    document.querySelectorAll('[data-key]').forEach(button => button.addEventListener('click', () => {
        sendInput({ type: 'key', key: button.dataset.key }).catch(() => { status.textContent = labels.unavailable; });
    }));
    document.querySelector('[data-scroll="up"]').addEventListener('click', () => {
        sendInput({ type: 'scroll', delta_x: 0, delta_y: -720 }).catch(() => { status.textContent = labels.unavailable; });
    });
    document.querySelector('[data-scroll="down"]').addEventListener('click', () => {
        sendInput({ type: 'scroll', delta_x: 0, delta_y: 720 }).catch(() => { status.textContent = labels.unavailable; });
    });
    document.getElementById('close').addEventListener('click', async () => {
        if (!active) return;
        active = false;
        setEnabled(false);
        try {
            const response = await relay('facebook_profile_close');
            status.textContent = response.ok ? labels.closed : labels.unavailable;
        } catch (_) {
            status.textContent = labels.unavailable;
        }
        proof = '';
    });

    if (!active) {
        status.textContent = labels.invalid;
        setEnabled(false);
        return;
    }
    loadFrame();
    window.setInterval(loadFrame, 800);
    poll();
})();
</script>
</body>
</html>
