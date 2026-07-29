<?php
declare(strict_types=1);

require_once HUB_ROOT . '/app/admin_market.php';

hub_test('Market categories are exclusive and sum to all Packs', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $catalog = hub_admin_market_catalog($db, 'all');
    $sum = 0;
    foreach (['reference', 'vision', 'language', 'audio', 'tools', 'experimental'] as $key) {
        $sum += $catalog['counts'][$key];
    }
    hub_test_assert($sum === $catalog['counts']['all'], 'Market category counts overlap or omit a Pack');
    hub_test_assert(hub_admin_market_category('utility') === 'tools', 'legacy utility category must normalize to tools');
    hub_test_assert(hub_admin_market_category('unknown') === 'all', 'unknown category must normalize to all');

    $tools = hub_admin_market_catalog($db, 'utility');
    hub_test_assert($tools['active_category'] === 'tools', 'utility filter must activate tools');
    hub_test_assert(count($tools['packs']) === $tools['counts']['tools'], 'filtered rows must match computed tools count');
    foreach ($tools['packs'] as $pack) {
        hub_test_assert($pack['market_category'] === 'tools', 'tools filter returned another category');
    }

    $installed = hub_admin_market_installed_stats($db);
    hub_test_assert(($installed['hello']['count'] ?? 0) === 1, 'installed stats must count the seeded hello service');
    hub_test_assert(($installed['hello']['first_service_id'] ?? 0) > 0, 'installed stats must expose first service ID');
});

hub_test('Market manifest categories follow the canonical mapping', function (): void {
    hub_test_assert(hub_admin_market_category_for_manifest(['role' => 'reference', 'experimental' => true]) === 'reference', 'reference role must win');
    hub_test_assert(hub_admin_market_category_for_manifest(['experimental' => true, 'category' => 'vision']) === 'experimental', 'experimental flag must override category');

    foreach (['vision', 'ocr', 'segmentation', 'detection', 'object-detection'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'vision', $category . ' must map to vision');
    }
    foreach (['language', 'translation', 'translate', 'llm'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'language', $category . ' must map to language');
    }
    foreach (['utility', 'tool', 'tools', 'web'] as $category) {
        hub_test_assert(hub_admin_market_category_for_manifest(['category' => $category]) === 'tools', $category . ' must map to tools');
    }

    hub_test_assert(hub_admin_market_category_for_manifest(['category' => 'audio']) === 'audio', 'audio must map to audio');
    hub_test_assert(hub_admin_market_category_for_manifest(['category' => 'document']) === 'experimental', 'unrecognized categories must map to experimental');
});

hub_test('Pack purpose uses a keyed Chinese seed and manifest fallback', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $pack = hub_get_pack('ocr-ppocrv5');
    $description = hub_admin_market_pack_description($db, $pack);
    hub_test_assert(str_contains($description, '圖片文字辨識'), 'OCR Chinese purpose copy missing');

    $unknown = ['id' => 'unseeded-pack', 'manifest' => ['description' => 'Manifest fallback']];
    hub_test_assert(hub_admin_market_pack_description($db, $unknown) === 'Manifest fallback', 'manifest description fallback mismatch');

    $malformed = ['id' => 'malformed-pack', 'manifest' => 'invalid', 'description' => 'Top-level fallback'];
    hub_test_assert(hub_admin_market_pack_description($db, $malformed) === 'Top-level fallback', 'top-level description fallback mismatch');
});
