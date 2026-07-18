<?php
declare(strict_types=1);

hub_test('PhaseS-4.1 token API smoke runs OCR Bearer flow over HTTP', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), '3waaihub_token_smoke_');
    if ($dbPath === false) {
        throw new RuntimeException('cannot allocate smoke db');
    }
    @unlink($dbPath);

    $result = hub_run_command([PHP_BINARY, HUB_ROOT . '/scripts/token_api_smoke.php', '--db=' . $dbPath], 75);
    @unlink($dbPath);
    @unlink($dbPath . '-wal');
    @unlink($dbPath . '-shm');

    $text = $result['output'];
    hub_test_assert($result['exit_code'] === 0, 'token API smoke failed: ' . $text);
    foreach ([
        'member created',
        'token created',
        'mode permission set: ocr',
        'token IP whitelist set',
        'curl status: 200',
        'Log Explorer query verified',
        'Usage aggregate verified',
    ] as $needle) {
        hub_test_assert(str_contains($text, $needle), 'smoke output missing: ' . $needle);
    }
});
