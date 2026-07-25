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

hub_test('PhaseDX-4 agent manifest smoke CLI exposes token-free contract', function (): void {
    $scriptPath = HUB_ROOT . '/scripts/agent_manifest_smoke.php';
    hub_test_assert(is_file($scriptPath), 'scripts/agent_manifest_smoke.php missing');

    $output = [];
    $exitCode = 1;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($scriptPath) . ' --help 2>&1', $output, $exitCode);
    hub_test_assert($exitCode === 0, 'agent manifest smoke --help must exit 0');
    $help = implode("\n", $output);
    foreach (['Usage:', '--manifest-url=', '--timeout='] as $needle) {
        hub_test_assert(str_contains($help, $needle), 'agent manifest smoke help missing ' . $needle);
    }
});

hub_test('PhaseDX-4 client documentation publishes agent manifest intake', function (): void {
    $quickstart = (string)file_get_contents(HUB_ROOT . '/docs/client_quickstart.md');
    foreach (['scripts/agent_manifest_smoke.php', '--manifest-url=', 'input_field_extensions', 'one_of', 'example_include'] as $needle) {
        hub_test_assert(str_contains($quickstart, $needle), 'client quickstart missing agent manifest intake detail: ' . $needle);
    }

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    foreach (['scripts/agent_manifest_smoke.php', 'input_field_extensions'] as $needle) {
        hub_test_assert(str_contains($readme, $needle), 'README missing agent manifest intake detail: ' . $needle);
    }
});

hub_test('PhaseDX-4 public docs and playground examples use current host URLs', function (): void {
    $db = hub_test_reset_db();
    $db->exec(
        "UPDATE services SET install_status = 'installed', enabled = 1, runtime_status = 'running', status = 'running'
         WHERE mode = 'hello'"
    );
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'nature.focusit.tw';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/public_api_docs.php';

    require_once HUB_ROOT . '/app/public_api_docs.php';
    $services = hub_public_api_services($db, static fn (array $service): bool => true);
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

hub_test('cluster Router public entry documents and endpoints remain disclosure-safe', function (): void {
    $guidePath = HUB_ROOT . '/docs/cluster-router.md';
    $manifestPath = HUB_ROOT . '/cluster_manifest.json.php';
    $docsPath = HUB_ROOT . '/cluster_public_api_docs.php';
    foreach ([$guidePath, $manifestPath, $docsPath] as $path) {
        hub_test_assert(is_file($path), 'cluster Router public file missing: ' . basename($path));
    }
    $guide = (string)file_get_contents($guidePath);
    $sources = $guide . "\n" . (string)file_get_contents($manifestPath) . "\n" . (string)file_get_contents($docsPath);

    foreach ([
        'cluster_api.php', 'cluster_pair.php', 'cluster_status.php', 'AIHUB_CLUSTER_SECRET_KEY', '子入口節點', '統一入口', 'cluster_status',
        'export AIHUB_CLUSTER_SECRET_KEY="$(openssl rand -hex 32)"',
        'php scripts/agent_manifest_smoke.php --manifest-url=https://router.example/3waAIHub/cluster_manifest.json.php',
        'php scripts/cluster_refresh.php --force', '新增子節點', 'priority', 'route', 'byte',
        'transfers the existing child Token exactly once', 'binds it to the unified Router source IP',
        'never paste child Tokens or pairing invitations in tickets, chat, or public logs',
    ] as $needle) {
        hub_test_assert(str_contains($sources, $needle), 'cluster Router public material missing ' . $needle);
    }
    hub_test_assert(preg_match_all('/^# /m', $guide) === 2, 'cluster Router guide must keep exactly two top-level sections');
    foreach (['3wa_live_', '#invite=', 'token_ciphertext', 'token_iv', 'token_tag', 'configured_station_secret', 'https://configured.station.example'] as $secret) {
        hub_test_assert(!str_contains($sources, $secret), 'cluster Router public material leaked ' . $secret);
    }
    foreach ([$manifestPath, $docsPath] as $path) {
        $source = (string)file_get_contents($path);
        hub_test_assert(str_contains($source, 'hub_cluster_router_enabled($db)') && str_contains($source, "hub_gateway_error(404, 'router_disabled'"), 'public endpoint must gate disabled Router safely');
        hub_test_assert((fileperms($path) & 0111) === 0111, 'public endpoint must be executable: ' . basename($path));
    }
    hub_test_assert(str_contains((string)file_get_contents($manifestPath), "hub_public_api_allowed(\$db, 'AIHUB_PUBLIC_API_MANIFEST')"), 'public manifest must use the manifest allow switch');
    hub_test_assert(str_contains((string)file_get_contents($docsPath), "hub_public_api_allowed(\$db, 'AIHUB_PUBLIC_API_DOCS')"), 'public docs must use the docs allow switch');
});

hub_test('cluster Router public endpoints enforce disabled and public-doc gates', function (): void {
    $db = hub_test_reset_db();
    $run = static function (string $path): string {
        $output = [];
        $exitCode = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        hub_test_assert($exitCode === 0, 'public endpoint must exit cleanly: ' . basename($path));

        return implode("\n", $output);
    };

    foreach (['cluster_manifest.json.php', 'cluster_public_api_docs.php'] as $endpoint) {
        hub_test_assert(str_contains($run(HUB_ROOT . '/' . $endpoint), 'router_disabled'), 'disabled Router must hide public endpoint: ' . $endpoint);
    }
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST', '0');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '0');

    hub_test_assert(str_contains($run(HUB_ROOT . '/cluster_manifest.json.php'), 'public_docs_forbidden'), 'manifest must enforce the public manifest switch');
    hub_test_assert(str_contains($run(HUB_ROOT . '/cluster_public_api_docs.php'), 'Router API documentation is unavailable.'), 'docs must enforce the public docs switch');
});
