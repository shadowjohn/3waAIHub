<?php
declare(strict_types=1);

hub_test('Facebook login page removes fragment proof and uses POST-only local relays', function (): void {
    $path = HUB_ROOT . '/facebook_profile_login.php';
    hub_test_assert(is_file($path), 'Facebook login page missing');
    hub_test_assert((fileperms($path) & 0777) === 0755, 'Facebook login page must be executable');
    $source = (string)file_get_contents($path);
    hub_test_assert(str_starts_with($source, "<?php\n"), 'public login page must not emit bytes before PHP headers');
    hub_test_assert(!str_contains($source, 'hub_i18n_language_selector'), 'login page must not navigate away and discard its fragment proof');

    $hash = strpos($source, 'location.hash');
    $replace = strpos($source, 'history.replaceState');
    hub_test_assert($hash !== false && $replace !== false && $hash < $replace, 'fragment proof must be read before immediate history removal');
    hub_test_assert(!str_contains($source, '?session=') && !str_contains($source, 'URLSearchParams(location.search'), 'login proof must never use a query string');
    foreach (['facebook_profile_frame', 'facebook_profile_input', 'facebook_profile_login_status', 'facebook_profile_close'] as $mode) {
        hub_test_assert(str_contains($source, "'" . $mode . "'"), 'login page missing relay mode ' . $mode);
    }
    hub_test_assert(str_contains($source, "method: 'POST'") && str_contains($source, 'JSON.stringify({ proof'), 'relay proof must be sent only in POST JSON');
    foreach (['http://', 'https://', 'cdn.', 'unpkg', 'jsdelivr'] as $remote) {
        hub_test_assert(!str_contains($source, $remote), 'login page must use only same-origin local assets');
    }
});

hub_test('Facebook login page keeps a stable password-safe localized control surface', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/facebook_profile_login.php');
    foreach ([
        "__('Facebook 登入')",
        "__('登入狀態')",
        "__('輸入')",
        "__('送出')",
        "__('向上捲動')",
        "__('向下捲動')",
        "__('關閉')",
    ] as $translation) {
        hub_test_assert(str_contains($source, $translation), 'visible login string must use __(): ' . $translation);
    }
    hub_test_assert(str_contains($source, 'aspect-ratio: 16 / 9'), 'login frame must keep a stable 16:9 surface');
    hub_test_assert(str_contains($source, 'type="password"') && str_contains($source, 'autocomplete="off"'), 'relay text input must be password-safe');
    hub_test_assert(str_contains($source, '1280 / rect.width') && str_contains($source, '720 / rect.height'), 'pointer coordinates must map to the broker viewport');
    hub_test_assert(str_contains($source, 'delta_y: -720') && str_contains($source, 'delta_y: 720'), 'scroll icon controls must use bounded events');
    hub_test_assert(!str_contains($source, '<script src=') && !str_contains($source, '<link rel="stylesheet"'), 'login page JS and CSS must be inline or local');
});

hub_test('Facebook login cleanup and Docker stages keep the bounded runtime contract', function (): void {
    $script = HUB_ROOT . '/scripts/facebook_profile_cleanup.php';
    hub_test_assert(is_file($script) && (fileperms($script) & 0777) === 0755, 'Facebook cleanup script must be executable');
    $cleanup = (string)file_get_contents($script);
    hub_test_assert(str_contains($cleanup, 'hub_facebook_login_cleanup_expired') && str_contains($cleanup, 'min(10'), 'cleanup script must call the bounded helper');

    $cron = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    $line = 'php "$APP_ROOT/scripts/facebook_profile_cleanup.php" --limit=10 >> "$APP_ROOT/data/logs/facebook-profile-cleanup.log" 2>&1';
    hub_test_assert(substr_count($cron, $line) === 1, 'one-minute cron must run exactly one bounded login cleanup');
    hub_test_assert(strpos($cron, $line) > strpos($cron, 'if needs_permission_fix; then'), 'login cleanup must run after runtime permission repair');
    hub_test_assert(str_contains($cron, "if ! {\n  " . $line . ";\n}; then"), 'login cleanup failure must not stop command and task workers');

    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'COPY crawl_runner.py login_broker.py crawl-entrypoint.sh ./'), 'Docker base must copy the login broker');
    hub_test_assert(str_contains($dockerfile, 'chmod 0755 crawl-entrypoint.sh crawl_runner.py login_broker.py'), 'Docker base must make the broker executable');
    $testStage = strpos($dockerfile, 'FROM base AS test');
    $runtimeStage = strpos($dockerfile, 'FROM base AS runtime');
    hub_test_assert($testStage !== false && $runtimeStage !== false && $testStage < $runtimeStage, 'Docker test/runtime stages changed');
    hub_test_assert(strpos($dockerfile, 'COPY tests ./tests', $testStage) < $runtimeStage, 'Docker test stage must include Python tests');
    hub_test_assert(!str_contains(substr($dockerfile, $runtimeStage), 'COPY tests'), 'Docker runtime stage must exclude tests');

    $entrypoint = (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/service/crawl-entrypoint.sh');
    foreach ([
        "stat -c '%u' /profile",
        "stat -c '%g' /profile",
        '--reuid="$profile_uid"',
        '--regid="$profile_gid"',
    ] as $ownerContract) {
        hub_test_assert(str_contains($entrypoint, $ownerContract), 'login broker must drop to the mounted profile owner: ' . $ownerContract);
    }
});

hub_test('Facebook crawler public docs expose only the managed local workflow', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    require_once HUB_ROOT . '/app/public_api_docs.php';

    $service = null;
    foreach (hub_public_api_services($db, static fn (): bool => true) as $candidate) {
        if (($candidate['mode'] ?? '') === 'facebook_crawl') {
            $service = $candidate;
            break;
        }
    }
    hub_test_assert(is_array($service), 'local public docs must include facebook_crawl');
    hub_test_assert(($service['content_type'] ?? '') === 'application/json', 'crawler submission must use JSON');
    hub_test_assert(
        array_column($service['input_fields'] ?? [], 'name') === ['profile_id', 'targets', 'limit_per_target'],
        'public crawler fields must hide runner and login internals'
    );
    $targets = $service['input_fields'][1] ?? [];
    hub_test_assert(($targets['type'] ?? '') === 'array' && ($targets['min_items'] ?? 0) === 1 && ($targets['max_items'] ?? 0) === 30, 'target array bounds missing');
    hub_test_assert(($targets['items']['required'] ?? []) === ['url'], 'target items must require only url');

    $operationModes = array_column($service['operations'] ?? [], 'mode');
    foreach ([
        'facebook_profile_start', 'facebook_profile_status', 'facebook_profile_reauth', 'facebook_profile_delete',
        'facebook_crawl', 'task_status', 'task_result', 'artifact', 'facebook_run_last', 'facebook_dataset_items',
    ] as $mode) {
        hub_test_assert(in_array($mode, $operationModes, true), 'crawler docs missing operation ' . $mode);
    }

    $serialized = hub_json_encode($service);
    foreach (['targets_json', 'storage_state', 'login_port', 'cookie', 'session proof'] as $secret) {
        hub_test_assert(!str_contains($serialized, $secret), 'crawler docs expose internal field ' . $secret);
    }
    hub_test_assert(str_contains((string)($service['examples']['curl'] ?? ''), 'https://www.facebook.com/wra.gov.tw'), 'crawler example must use a safe official page');
});

hub_test('Facebook crawler Test Center renders a focused localized workflow', function (): void {
    $partial = HUB_ROOT . '/admin/_playground_facebook_crawler.php';
    hub_test_assert(is_file($partial), 'crawler Playground partial missing');
    hub_test_assert((fileperms($partial) & 0777) === 0755, 'new crawler PHP partial must be executable');
    $source = (string)file_get_contents($partial);
    foreach ([
        "__('Facebook 登入 Profile')",
        "__('目標粉絲專頁／社團')",
        "__('每個目標近期文章數')",
        "__('任務連結')",
        "__('Dataset 預覽')",
    ] as $translation) {
        hub_test_assert(str_contains($source, $translation), 'crawler visible text must use __(): ' . $translation);
    }
    hub_test_assert(str_contains($source, 'type="password"') && !str_contains($source, "value=\"<?= hub_h((string)(\$_POST['facebook_password']"), 'credentials must be password-only and never redisplayed');
    foreach (['http://', 'https://', '<script src=', '<link rel="stylesheet"'] as $remote) {
        hub_test_assert(!str_contains($source, $remote), 'crawler partial must not load external assets');
    }

    ob_start();
    require_once HUB_ROOT . '/admin/playground.php';
    ob_end_clean();
    hub_test_assert(in_array('facebook_crawl', hub_playground_supported_modes(), true), 'crawler must be selectable in Test Center');
    $db = hub_test_reset_db();
    hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    $service = hub_get_service_by_mode($db, 'facebook_crawl');
    hub_test_assert(is_array($service) && hub_playground_basic_readiness($service) === null, 'enabled internal task must not require a resident service runtime');
    $previousPost = $_POST;
    try {
        $_POST = [];
        hub_test_assert(hub_playground_request_payload('facebook_crawl') === [
            'targets' => [['url' => 'https://www.facebook.com/wra.gov.tw']],
            'limit_per_target' => 10,
        ], 'crawler Playground must submit the public JSON contract');
    } finally {
        $_POST = $previousPost;
    }
    foreach (['name="facebook_profile_id"', 'name="facebook_targets"', 'name="facebook_limit_per_target"', 'min="10" max="30"', 'data-facebook-task-links', 'data-facebook-dataset-preview'] as $needle) {
        hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/admin/playground.php') . $source, $needle), 'crawler Playground missing ' . $needle);
    }

    $readme = (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/README.md');
    foreach (['Linux', 'Windows WSL', '2FA', 'CAPTCHA', '30 天', 'nchc_ai', 'Phase B', 'scripts/facebook_crawler_smoke.php'] as $needle) {
        hub_test_assert(str_contains($readme, $needle), 'crawler README missing ' . $needle);
    }
});
