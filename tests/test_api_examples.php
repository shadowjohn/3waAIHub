<?php
declare(strict_types=1);

hub_test('api examples documentation exists', function (): void {
    $path = HUB_ROOT . '/docs/api_examples.md';
    hub_test_assert(is_file($path), 'docs/api_examples.md missing');
    $docs = (string)file_get_contents($path);
    foreach (['mode=hello', 'mode=ocr', 'mode=translate', 'unknown mode'] as $needle) {
        hub_test_assert(str_contains($docs, $needle), 'api docs missing ' . $needle);
    }

    $contracts = hub_pack_api_contracts();
    hub_test_assert(isset($contracts['ocr-ppocrv5']), 'OCR API contract missing');
    hub_test_assert(($contracts['ocr-ppocrv5']['contract']['endpoint'] ?? '') === '/ocr/image', 'OCR API contract endpoint mismatch');
    hub_test_assert(is_file(HUB_ROOT . '/admin/pack_readiness.php'), 'pack readiness page missing');
    $apiDocsPage = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(str_contains($apiDocsPage, 'hub_pack_api_contracts'), 'admin API docs must read pack contracts');
});
