<?php
declare(strict_types=1);

hub_test('api examples documentation exists', function (): void {
    $path = HUB_ROOT . '/docs/api_examples.md';
    hub_test_assert(is_file($path), 'docs/api_examples.md missing');
    $docs = (string)file_get_contents($path);
    foreach (['mode=hello', 'mode=ocr', 'mode=translate', 'unknown mode'] as $needle) {
        hub_test_assert(str_contains($docs, $needle), 'api docs missing ' . $needle);
    }
    hub_test_assert(str_contains($docs, 'real_inference'), 'api docs missing real_inference');
    hub_test_assert(str_contains($docs, 'translate_real_text'), 'api docs missing translate real benchmark');
    hub_test_assert(str_contains($docs, 'L5 benchmark ready'), 'api docs missing Translate L5 status');

    $contracts = hub_pack_api_contracts();
    hub_test_assert(isset($contracts['ocr-ppocrv5']), 'OCR API contract missing');
    hub_test_assert(($contracts['ocr-ppocrv5']['contract']['endpoint'] ?? '') === '/ocr/image', 'OCR API contract endpoint mismatch');
    hub_test_assert(in_array('real_inference', array_column($contracts['ocr-ppocrv5']['contract']['input']['fields'] ?? [], 'name'), true), 'OCR API contract must expose real_inference');
    hub_test_assert(is_file(HUB_ROOT . '/admin/pack_readiness.php'), 'pack readiness page missing');
    $apiDocsPage = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(str_contains($apiDocsPage, 'hub_pack_api_contracts'), 'admin API docs must read pack contracts');
    hub_test_assert(str_contains($apiDocsPage, 'Mock mode'), 'admin API docs must show OCR mock mode');
    hub_test_assert(str_contains($apiDocsPage, 'Real inference mode'), 'admin API docs must show OCR real inference mode');
    hub_test_assert(str_contains($apiDocsPage, 'mode=translate'), 'admin API docs must show translate mode');
    hub_test_assert(str_contains($apiDocsPage, 'Content-Type: application/json'), 'admin API docs must show JSON curl');
    $benchmarkPage = (string)file_get_contents(HUB_ROOT . '/admin/benchmarks.php');
    hub_test_assert(str_contains($benchmarkPage, 'ocr_mock_image'), 'benchmark page must show OCR mock benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'ocr_real_image'), 'benchmark page must show OCR real benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'translate_mock_text'), 'benchmark page must show Translate mock benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'translate_real_text'), 'benchmark page must show Translate real benchmark');
});
