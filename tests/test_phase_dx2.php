<?php
declare(strict_types=1);

hub_test('PhaseDX-2 playground onboarding polish contract is present', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');

    foreach (['需要 Bearer Token', '前往 API 金鑰建立', 'Authorization header', 'Authorization: Bearer <TOKEN>'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'token onboarding missing ' . $needle);
    }
    foreach (['data-copy-target', 'copy-auth-header', 'copy-curl', 'copy-php', 'copy-js', 'navigator.clipboard.writeText'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'copy support missing ' . $needle);
    }
    foreach (['顯示 token', '隱藏 token', 'data-token-toggle'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'token visibility toggle missing ' . $needle);
    }
    foreach (['api_docs.php', 'benchmarks.php', 'pack_readiness.php?pack_id=', 'log_explorer.php?mode=', 'API 文件', 'Benchmark 測試', '準備狀態', 'API 記錄'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'quick link missing ' . $needle);
    }
    hub_test_assert(str_contains($page, 'log_explorer.php?request_id='), 'request_id log link missing');
    foreach (['mode', 'pack_id', 'runtime_level', 'endpoint', 'request_id', 'error_code'] as $technical) {
        hub_test_assert(str_contains($page, $technical), 'technical value should stay English ' . $technical);
    }
    foreach (['API 測試場', '選擇服務', '執行測試', '回應結果', '介接範例'] as $label) {
        hub_test_assert(str_contains($page, $label), 'localized label missing ' . $label);
    }
    hub_test_assert(!str_contains($page, '3wa_live_'), 'examples must not contain a real token prefix');
});
