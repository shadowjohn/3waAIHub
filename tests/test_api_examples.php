<?php
declare(strict_types=1);

hub_test('api examples documentation exists', function (): void {
    $path = HUB_ROOT . '/docs/api_examples.md';
    hub_test_assert(is_file($path), 'docs/api_examples.md missing');
    $docs = (string)file_get_contents($path);
    foreach (['mode=hello', 'mode=ocr', 'mode=translate', 'unknown mode'] as $needle) {
        hub_test_assert(str_contains($docs, $needle), 'api docs missing ' . $needle);
    }
});
