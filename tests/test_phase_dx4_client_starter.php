<?php
declare(strict_types=1);

hub_test('PhaseDX-4 client quickstart and examples are integration ready', function (): void {
    $quickstartPath = HUB_ROOT . '/docs/client_quickstart.md';
    hub_test_assert(is_file($quickstartPath), 'docs/client_quickstart.md missing');
    $quickstart = (string)file_get_contents($quickstartPath);
    foreach ([
        'Public Docs 是說明書',
        'Bearer Token 才是鑰匙',
        '建立客戶',
        'public_api_docs.php',
        'api_manifest.json.php',
        'scripts/api_smoke_client.php',
        '非同步文件任務流程',
        'curl',
        'PHP',
        'JS fetch',
        '<BASE_URL>',
        '<TOKEN>',
        'mode=docparser',
        'task_status',
        'task_result',
        'docparser_repair_translation',
        'missing_translation_blocks',
        'artifact_url_template',
        'figure_assets.items',
    ] as $needle) {
        hub_test_assert(str_contains($quickstart, $needle), 'client quickstart missing ' . $needle);
    }
    foreach (['hello', 'ocr', 'yolo', 'translate', 'sam3'] as $mode) {
        hub_test_assert(str_contains($quickstart, 'mode=' . $mode), 'client quickstart missing mode ' . $mode);
    }
    foreach (['request contract', 'response contract', 'error contract'] as $contract) {
        hub_test_assert(str_contains($quickstart, $contract), 'client quickstart missing ' . $contract);
    }

    $apiExamples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    hub_test_assert(str_contains($apiExamples, '<BASE_URL>'), 'API examples should use BASE_URL placeholder');
    hub_test_assert(!str_contains($apiExamples, 'http://localhost/3waAIHub/api.php'), 'API examples must not hardcode localhost');
});

hub_test('PhaseDX-4 API smoke client script exposes safe CLI contract', function (): void {
    $scriptPath = HUB_ROOT . '/scripts/api_smoke_client.php';
    hub_test_assert(is_file($scriptPath), 'scripts/api_smoke_client.php missing');
    $script = (string)file_get_contents($scriptPath);
    foreach (['--base-url', '--token', '--modes', 'hello,ocr,yolo,translate,sam3', 'Authorization: Bearer', 'real_inference'] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'api smoke client missing ' . $needle);
    }
    hub_test_assert(!str_contains($script, '3wa_live_'), 'api smoke client must not contain real token');

    $output = [];
    $exitCode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . ' --help 2>&1', $output, $exitCode);
    hub_test_assert($exitCode === 0, 'api smoke client --help must exit 0');
    $help = implode("\n", $output);
    foreach (['Usage:', '--base-url=', '--token=', '--modes='] as $needle) {
        hub_test_assert(str_contains($help, $needle), 'api smoke client help missing ' . $needle);
    }
});

hub_test('PhaseDX-4 public docs and playground examples use current host URLs', function (): void {
    $db = hub_test_reset_db();
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'nature.focusit.tw';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/public_api_docs.php';

    require_once HUB_ROOT . '/app/public_api_docs.php';
    $services = hub_public_api_services($db);
    $hello = null;
    foreach ($services as $service) {
        if ((string)$service['mode'] === 'hello') {
            $hello = $service;
            break;
        }
    }
    hub_test_assert(is_array($hello), 'public docs must include hello service');
    hub_test_assert(
        str_contains((string)$hello['examples']['curl'], 'https://nature.focusit.tw/3waAIHub/api.php?mode=hello'),
        'public docs examples must use current host'
    );
    $curlExecutable = hub_platform_id() === 'windows' ? 'curl.exe' : 'curl';
    $continuation = hub_platform_id() === 'windows' ? "`" : "\\";
    hub_test_assert(str_starts_with((string)$hello['examples']['curl'], $curlExecutable . ' '), 'public docs must use the current platform curl executable');

    require_once HUB_ROOT . '/admin/playground.php';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/playground.php';
    $examples = hub_playground_examples('hello');
    hub_test_assert(
        str_contains($examples['curl'], 'https://nature.focusit.tw/3waAIHub/api.php?mode=hello'),
        'playground examples must use current host'
    );
    hub_test_assert(str_starts_with($examples['curl'], $curlExecutable . ' '), 'playground must use the current platform curl executable');
    $translateExamples = hub_playground_examples('translate');
    hub_test_assert(str_contains($translateExamples['curl'], ' ' . $continuation . "\n"), 'playground must use the current platform continuation');
});
