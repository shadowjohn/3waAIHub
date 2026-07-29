<?php
declare(strict_types=1);

hub_test('admin pack catalog tabs render expected contract', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    $helper = (string)file_get_contents(HUB_ROOT . '/app/admin_market.php');

    hub_test_assert(str_contains($page, "require_once __DIR__ . '/../app/admin_market.php'"), 'packs page must load shared Market helper');
    foreach (['hub_admin_market_category', 'hub_admin_market_category_for_manifest', 'hub_admin_market_runtime_badge_class', 'hub_admin_market_model_label', 'hub_admin_market_categories'] as $fn) {
        hub_test_assert(str_contains($helper, 'function ' . $fn), 'shared Market helper missing ' . $fn);
    }
    foreach (['hub_admin_market_catalog', 'hub_admin_market_runtime_badge_class', 'hub_admin_market_model_label', 'hub_admin_market_categories'] as $fn) {
        hub_test_assert(str_contains($page, $fn), 'packs page must call ' . $fn);
    }
    hub_test_assert(str_contains($page, 'packs.php?tab='), 'packs tab link missing');

    foreach (['全部', '參考樣板', '視覺影像', '語言文字', '音訊語音', '工具', '實驗中'] as $label) {
        hub_test_assert(str_contains($helper, $label), 'localized tab label missing ' . $label);
    }

    foreach (['套件名稱', '套件 ID', '執行層級', 'L5 可驗收', '已安裝服務', 'modes:', '目前沒有音訊語音套件。'] as $needle) {
        hub_test_assert(str_contains($page . $helper, $needle), 'pack catalog contract missing ' . $needle);
    }

    foreach (['安裝為服務', '查看 API 文件', 'Benchmark 測試', '準備狀態', '已安裝服務'] as $action) {
        hub_test_assert(str_contains($page, $action), 'packs action missing ' . $action);
    }

    foreach (['pack_id', 'mode', 'runtime_level', 'endpoint'] as $technicalValue) {
        hub_test_assert(str_contains($page, $technicalValue), 'technical value label should stay English ' . $technicalValue);
    }

    hub_test_assert(!str_contains($page, 'function hub_pack_catalog_tab'), 'packs page must not duplicate category helpers');
    hub_test_assert(!str_contains($page, 'function hub_pack_runtime_label'), 'packs page must not duplicate runtime helpers');

    $db = hub_test_reset_db();
    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['tab' => 'all'];
    ob_start();
    require HUB_ROOT . '/admin/packs.php';
    $html = (string)ob_get_clean();

    foreach (['pack-card', 'hello-service', 'ocr-ppocrv5', 'yolo', 'sam3', 'translate-gemma12b', 'L5 可驗收', '已安裝服務'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered packs page missing ' . $needle);
    }

    hub_test_assert(hub_admin_market_category('not_a_tab') === 'all', 'unknown tab must fall back to all');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('hello')['manifest']) === 'reference', 'hello must be reference tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('ocr-ppocrv5')['manifest']) === 'vision', 'ocr must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('yolo')['manifest']) === 'vision', 'yolo must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('sam3')['manifest']) === 'vision', 'sam3 must be vision tab');
    hub_test_assert(hub_admin_market_category_for_manifest(hub_get_pack('translate-gemma12b')['manifest']) === 'language', 'translate must be language tab');
});

hub_test('canonical Market JS uses one page dictionary and canonical readiness endpoint', function (): void {
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    $servicesJs = (string)file_get_contents(HUB_ROOT . '/assets/js/services.js');
    $packsJs = (string)file_get_contents(HUB_ROOT . '/assets/js/packs.js');

    hub_test_assert(str_contains($marketplace, 'id="market-i18n"'), 'canonical Market dictionary node missing');
    hub_test_assert(str_contains($marketplace, 'hub_json_encode(')
        && str_contains($marketplace, 'JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES'), 'Market dictionary must keep readable Unicode and slashes through the shared HTML-safe encoder');
    foreach ([$servicesJs, $packsJs] as $script) {
        hub_test_assert(substr_count($script, 'JSON.parse(') === 1, 'Market script must parse its page dictionary once');
        hub_test_assert(str_contains($script, "getElementById('market-i18n')"), 'Market script dictionary node lookup missing');
        hub_test_assert(str_contains($script, 'function t(') || str_contains($script, 'const t ='), 'Market script translation helper missing');
        hub_test_assert(!str_contains($script, 'localStorage'), 'Market script must not persist translations');
        hub_test_assert(!str_contains($script, 'translation.php'), 'Market script must not call a translation endpoint');
    }
    hub_test_assert(str_contains($packsJs, "url: 'marketplace.php'"), 'Pack readiness must use canonical marketplace endpoint');
    foreach (['refreshing', 'refresh', 'readiness_failed'] as $key) {
        hub_test_assert(str_contains($packsJs, "t('" . $key . "'"), 'Pack readiness feedback must use dictionary key ' . $key);
    }
    foreach ([
        'running',
        'stopped',
        'unknown',
        'health_ok',
        'health_checking',
        'health_failed',
        'poll_failed',
        'action_failed',
        'queued',
        'action_service_start',
        'action_service_stop',
        'action_service_restart',
        'action_service_build',
        'action_service_rebuild',
        'action_service_health_check',
        'job_status_queued',
        'job_status_running',
        'job_status_success',
        'job_status_failed',
        'job_status_cancelled',
        'job_status_timeout',
    ] as $key) {
        hub_test_assert(str_contains($servicesJs, "t('" . $key . "'"), 'service feedback must use dictionary key ' . $key);
    }
});

hub_test('shared JSON encoder blocks script breakout in the Market dictionary', function (): void {
    $payload = ['malicious' => '</script><img src=x onerror=alert(1)>'];
    $encoded = hub_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    hub_test_assert(is_string($encoded), 'malicious dictionary payload must encode');
    hub_test_assert(!str_contains($encoded, '</script>'), 'shared JSON encoder must not emit a raw closing script tag');
    hub_test_assert(str_contains($encoded, '\\u003C/script\\u003E'), 'shared JSON encoder must hex-escape the opening angle brackets');
    hub_test_assert(str_contains($encoded, '\\u003Cimg'), 'shared JSON encoder must hex-escape the injected element');
});

hub_test('Market scripts expose validation polling state and i18n consistency contracts', function (): void {
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    $servicesJs = (string)file_get_contents(HUB_ROOT . '/assets/js/services.js');
    $packsJs = (string)file_get_contents(HUB_ROOT . '/assets/js/packs.js');

    foreach ([
        "document.addEventListener('invalid'",
        "closest('.pack-details')",
        'details.open = true',
        'window.setTimeout',
        'control.focus',
        "t('required_fields'",
    ] as $needle) {
        hub_test_assert(str_contains($packsJs, $needle), 'Pack invalid-field enhancement missing ' . $needle);
    }
    hub_test_assert(!str_contains($packsJs, '.submit()'), 'Pack invalid handler must not submit the form');

    foreach ([
        'function jobActionLabel',
        'function jobStatusLabel',
        'function syncServiceState',
        'function updateServiceSummary',
        "job.status === 'success'",
        "job.error_code !== 'platform_target_unsupported'",
        'return submitServiceAction',
        "['failed', 'cancelled', 'timeout'].indexOf(job.status)",
        'window.location.reload()',
        "serviceRuntimeStatus(job) === 'running'",
        ".attr('role', isError ? 'alert' : 'status')",
        ".attr('aria-live', isError ? 'assertive' : 'polite')",
    ] as $needle) {
        hub_test_assert(str_contains($servicesJs, $needle), 'Service polling contract missing ' . $needle);
    }
    hub_test_assert(!str_contains($servicesJs, 'job.action_label ||'), 'JS must map action codes through the page dictionary');
    hub_test_assert(!str_contains($servicesJs, 'job.status_label ||'), 'JS must map job status codes through the page dictionary');

    foreach ([
        "'action_service_start' => __('啟動服務')",
        "'action_service_stop' => __('停止服務')",
        "'action_service_restart' => __('重啟服務')",
        "'action_service_build' => __('建置服務')",
        "'action_service_rebuild' => __('重新建置')",
        "'action_service_health_check' => __('健康檢查')",
        "'job_status_queued' => __('排隊中')",
        "'job_status_running' => __('執行中')",
        "'job_status_success' => __('成功')",
        "'job_status_failed' => __('失敗')",
        "'job_status_cancelled' => __('已取消')",
        "'job_status_timeout' => __('逾時')",
        "'required_fields' => __('請完成標示的必填欄位。')",
    ] as $needle) {
        hub_test_assert(str_contains($marketplace, $needle), 'canonical Market dictionary missing ' . $needle);
    }
});

hub_test('legacy Market pages stay directly available with deprecation notices', function (): void {
    foreach (['packs.php', 'models.php', 'services.php'] as $file) {
        $source = (string)file_get_contents(HUB_ROOT . '/admin/' . $file);
        hub_test_assert(str_contains($source, '/** @deprecated Canonical UI: admin/marketplace.php */'), $file . ' deprecation marker missing');
        hub_test_assert(str_contains($source, 'class="notice legacy-debug"'), $file . ' legacy debug notice missing');
        hub_test_assert(str_contains($source, 'href="marketplace.php'), $file . ' canonical operation link missing');
        hub_test_assert(!str_contains($source, "header('Location: marketplace.php"), $file . ' must not redirect');
    }

    $canonical = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');
    foreach (['packs.php', 'models.php', 'services.php'] as $legacy) {
        hub_test_assert(!str_contains($canonical, "require HUB_ROOT . '/admin/" . $legacy), 'canonical page must not include ' . $legacy);
        hub_test_assert(!str_contains($canonical, "require_once HUB_ROOT . '/admin/" . $legacy), 'canonical page must not include ' . $legacy);
    }
});

hub_test('canonical Market stylesheet stays dense local and zero tracking', function (): void {
    $css = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-market.css');

    foreach (['.workspace-tabs', '.market-categories', '.pack-grid', '.pack-card', '.service-grid', '.service-card', '.market-spec'] as $selector) {
        hub_test_assert(str_contains($css, $selector), 'Market stylesheet missing ' . $selector);
    }
    preg_match_all('/letter-spacing\s*:\s*([^;}]+)/', $css, $tracking);
    foreach ($tracking[1] as $value) {
        hub_test_assert(trim($value) === '0', 'Market stylesheet contains nonzero letter-spacing');
    }
    foreach (['linear-gradient', 'radial-gradient', 'https://', 'http://', 'orb', 'glow'] as $forbidden) {
        hub_test_assert(!str_contains($css, $forbidden), 'Market stylesheet contains forbidden decoration or asset ' . $forbidden);
    }
    foreach (['.pack-card', '.service-card'] as $selector) {
        $start = strpos($css, $selector);
        $block = $start === false ? '' : substr($css, $start, 400);
        hub_test_assert(preg_match('/border-radius:\s*(?:[0-8](?:\\.\\d+)?px|var\\(--r-sm\\))/', $block) === 1, $selector . ' radius exceeds 8px');
    }
});
