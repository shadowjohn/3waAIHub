<?php
declare(strict_types=1);

function hub_is_local_request(): bool
{
    return hub_is_localhost_ip(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')));
}

function hub_public_api_allowed(PDO $db, string $settingKey): bool
{
    hub_ensure_default_storage_settings($db);
    if (hub_get_storage_setting($db, $settingKey) !== '1') {
        return false;
    }
    if (hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1' && !hub_is_local_request()) {
        return false;
    }

    return true;
}

function hub_public_api_base_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/public_api_docs.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }
    if (str_ends_with($dir, '/admin')) {
        $dir = substr($dir, 0, -6) ?: '';
    }

    return $dir;
}

function hub_public_api_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) ?: 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host . hub_public_api_base_path() . '/api.php';
}

function hub_public_api_mode_url(string $mode): string
{
    return hub_public_api_base_url() . '?mode=' . rawurlencode($mode);
}

function hub_public_api_method(array $manifest, array $contract): string
{
    $method = (string)($contract['method'] ?? '');
    if ($method !== '') {
        return strtoupper($method);
    }
    $methods = is_array($manifest['gateway']['methods'] ?? null) ? $manifest['gateway']['methods'] : [];

    return strtoupper((string)($methods[0] ?? 'POST'));
}

function hub_public_api_content_type(string $method, array $contract): string
{
    $contentType = trim((string)($contract['content_type'] ?? ''));
    if ($contentType !== '') {
        return $contentType;
    }

    return $method === 'GET' ? 'application/json' : 'multipart/form-data';
}

function hub_public_api_contract_for_manifest(array $manifest): array
{
    $contract = hub_pack_l5_contract($manifest);
    if ($contract !== []) {
        return $contract;
    }
    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    $methods = is_array($gateway['methods'] ?? null) ? $gateway['methods'] : [];
    $method = strtoupper((string)($methods[0] ?? 'POST'));
    $invokePath = strtolower((string)($gateway['invoke_path'] ?? ''));
    $fieldName = str_contains($invokePath, 'audio') || str_contains($invokePath, 'asr') ? 'audio' : (str_contains($invokePath, 'image') ? 'image' : '');
    $fields = $fieldName !== '' ? [[
        'name' => $fieldName,
        'type' => 'file',
        'required' => true,
        'max_mb' => (int)($gateway['max_upload_mb'] ?? 0),
    ]] : [];

    return [
        'method' => $method,
        'content_type' => $fieldName !== '' ? 'multipart/form-data' : 'application/json',
        'input' => ['fields' => $fields],
        'output' => ['required_keys' => ['ok']],
        'errors' => is_array($manifest['error_codes'] ?? null) ? $manifest['error_codes'] : ['bad_request', 'service_unavailable'],
    ];
}

function hub_public_api_services(PDO $db): array
{
    $services = [];
    foreach (hub_list_packs() as $pack) {
        if (($pack['status'] ?? '') !== 'ok') {
            continue;
        }
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $mode = (string)($manifest['default_mode'] ?? '');
        if ($mode === '') {
            continue;
        }
        $contract = hub_public_api_contract_for_manifest($manifest);
        $method = hub_public_api_method($manifest, $contract);
        $contentType = hub_public_api_content_type($method, $contract);
        $fields = is_array($contract['input']['fields'] ?? null) ? $contract['input']['fields'] : [];
        $outputKeys = array_values(array_map('strval', is_array($contract['output']['required_keys'] ?? null) ? $contract['output']['required_keys'] : []));
        $errors = array_values(array_map('strval', is_array($contract['errors'] ?? null) ? $contract['errors'] : []));
        $taskApi = is_array($contract['task_api'] ?? null) ? $contract['task_api'] : [];
        $service = [
            'mode' => $mode,
            'pack_id' => (string)($manifest['id'] ?? $pack['id'] ?? ''),
            'name' => (string)($manifest['name'] ?? $pack['id'] ?? ''),
            'description' => (string)($manifest['description'] ?? ''),
            'method' => $method,
            'content_type' => $contentType,
            'endpoint' => 'api.php?mode=' . $mode,
            'url' => hub_public_api_mode_url($mode),
            'execution_type' => (string)($manifest['execution_type'] ?? ''),
            'runtime_level' => (string)($manifest['runtime_level'] ?? ''),
            'task_type' => (string)($contract['task_type'] ?? ''),
            'input_fields' => $fields,
            'output_keys' => $outputKeys,
            'error_codes' => $errors,
            'task_api' => hub_public_api_task_api_refs($taskApi),
        ];
        $service['examples'] = hub_public_api_examples($service);
        $services[] = $service;
    }

    usort($services, static fn (array $a, array $b): int => strcmp((string)$a['mode'], (string)$b['mode']));
    return $services;
}

function hub_public_api_task_api_refs(array $taskApi): array
{
    $refs = [];
    foreach ($taskApi as $key => $value) {
        $ref = (string)$value;
        if ($ref === '') {
            continue;
        }
        $refs[(string)$key] = str_replace('api.php?', hub_public_api_base_url() . '?', $ref);
    }

    return $refs;
}

function hub_public_api_json_body(array $service): array
{
    $body = [];
    foreach ($service['input_fields'] as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = (string)($field['name'] ?? '');
        if ($name === '' || $name === 'mode' || ($field['type'] ?? '') === 'file') {
            continue;
        }
        $body[$name] = match ($name) {
            'text' => 'That was a wonderful time.',
            'source_lang' => 'en',
            'target_lang' => 'zh-TW',
            'real_inference' => true,
            default => $field['default'] ?? '',
        };
    }

    return $body;
}

function hub_public_api_multipart_fields(array $service): array
{
    $fields = [];
    foreach ($service['input_fields'] as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = (string)($field['name'] ?? '');
        if ($name === '' || $name === 'mode') {
            continue;
        }
        $type = (string)($field['type'] ?? '');
        if ($type === 'file') {
            $sample = (string)($field['example'] ?? '');
            if ($sample === '') {
                $sample = $name === 'audio' ? 'sample.wav' : ($name === 'file' ? 'sample.pdf' : 'sample.png');
            }
            $fields[] = $name . '=@' . $sample;
            continue;
        }
        if ($name === 'points_json') {
            $fields[] = $name . '={"points":[[320,240]],"labels":[1]}';
            continue;
        }
        $fields[] = $name . '=' . (string)($field['default'] ?? ($name === 'real_inference' ? '1' : ''));
    }

    return $fields;
}

function hub_public_api_examples(array $service): array
{
    $url = (string)$service['url'];
    $method = (string)$service['method'];
    $contentType = (string)$service['content_type'];
    if ($method === 'GET') {
        $curl = 'curl -H "Authorization: Bearer <TOKEN>" ' . escapeshellarg($url);
        $phpBody = '';
        $jsOptions = "{\n  headers: { Authorization: 'Bearer <TOKEN>' }\n}";
    } elseif ($contentType === 'application/json') {
        $body = json_encode(hub_public_api_json_body($service), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = 'curl -X ' . $method . ' ' . escapeshellarg($url) . ' \\' . "\n"
            . '  -H "Authorization: Bearer <TOKEN>" \\' . "\n"
            . '  -H "Content-Type: application/json" \\' . "\n"
            . "  -d '" . $body . "'";
        $phpBody = '$payload = ' . var_export(json_decode((string)$body, true) ?: [], true) . ";\n";
        $jsOptions = "{\n  method: '{$method}',\n  headers: {\n    Authorization: 'Bearer <TOKEN>',\n    'Content-Type': 'application/json'\n  },\n  body: JSON.stringify(" . json_encode(json_decode((string)$body, true) ?: [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ")\n}";
    } else {
        $fields = hub_public_api_multipart_fields($service);
        $curl = 'curl -X ' . $method . ' ' . escapeshellarg($url) . ' \\' . "\n"
            . '  -H "Authorization: Bearer <TOKEN>"';
        foreach ($fields as $field) {
            $curl .= ' \\' . "\n" . '  -F ' . escapeshellarg($field);
        }
        $phpBody = '$fields = [/* attach files with CURLFile here */];' . "\n";
        $jsOptions = "{\n  method: '{$method}',\n  headers: { Authorization: 'Bearer <TOKEN>' },\n  body: formData\n}";
    }

    $headers = ["'Authorization: Bearer <TOKEN>'"];
    $postFields = '';
    if ($method !== 'GET' && $contentType === 'application/json') {
        $headers[] = "'Content-Type: application/json'";
        $postFields = "    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),\n";
    } elseif ($method !== 'GET') {
        $postFields = "    CURLOPT_POSTFIELDS => \$fields,\n";
    }
    $php = $phpBody
        . '$ch = curl_init(' . var_export($url, true) . ");\n"
        . "curl_setopt_array(\$ch, [\n"
        . "    CURLOPT_RETURNTRANSFER => true,\n"
        . "    CURLOPT_CUSTOMREQUEST => '{$method}',\n"
        . $postFields
        . '    CURLOPT_HTTPHEADER => [' . implode(', ', $headers) . "],\n"
        . "]);\n"
        . 'echo curl_exec($ch);';
    $js = "const res = await fetch(" . json_encode($url, JSON_UNESCAPED_SLASHES) . ", {$jsOptions});\n"
        . 'console.log(await res.json());';

    return ['curl' => $curl, 'php' => $php, 'js_fetch' => $js];
}

function hub_public_api_manifest(PDO $db): array
{
    return [
        'name' => '3waAIHub',
        'version' => HUB_VERSION,
        'auth' => [
            'type' => 'bearer',
            'header' => 'Authorization: Bearer <TOKEN>',
        ],
        'base_endpoint' => 'api.php',
        'services' => hub_public_api_services($db),
    ];
}

function hub_public_api_docs_html(PDO $db, ?array $user = null): string
{
    $services = hub_public_api_services($db);
    ob_start();
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>3waAIHub API 介接文件</title>
    <style>
        :root { color-scheme: light; --bg: #f6f7f9; --panel: #fff; --line: #d9dee7; --text: #1d2430; --muted: #667085; --blue: #1769e0; }
        body { background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; }
        main { max-width: 1120px; margin: 28px auto; padding: 0 16px; }
        .panel, .card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .muted { color: var(--muted); }
        code, pre { background: #101828; color: #f2f4f7; border-radius: 6px; }
        code { padding: 2px 5px; }
        pre { overflow: auto; padding: 12px; white-space: pre-wrap; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid var(--line); padding: 8px; text-align: left; vertical-align: top; }
        th { color: var(--muted); width: 130px; }
        .button { border: 1px solid var(--line); border-radius: 6px; color: var(--text); display: inline-block; padding: 7px 11px; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>3waAIHub API 介接文件</h1>
        <p class="muted">這份文件只提供外部介接所需資訊，不包含後台管理連結、內部部署資訊、主機檔案路徑或 token 明文。</p>
        <p>認證方式：<code>Authorization: Bearer &lt;TOKEN&gt;</code></p>
        <p>API Endpoint：<code><?= hub_h(hub_public_api_base_url()) ?>?mode=&lt;mode&gt;</code></p>
        <?php if ($user !== null): ?>
            <p><a class="button" href="admin/playground.php">開啟 API 測試場</a></p>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2>可用 mode</h2>
        <p><?php foreach ($services as $service): ?><code><?= hub_h((string)$service['mode']) ?></code> <?php endforeach; ?></p>
    </section>
    <section class="grid">
        <?php foreach ($services as $service): ?>
            <article class="card">
                <h2><?= hub_h((string)$service['name']) ?></h2>
                <table>
                    <tr><th>mode</th><td><code><?= hub_h((string)$service['mode']) ?></code></td></tr>
                    <tr><th>pack_id</th><td><code><?= hub_h((string)$service['pack_id']) ?></code></td></tr>
                    <tr><th>method</th><td><code><?= hub_h((string)$service['method']) ?></code></td></tr>
                    <tr><th>endpoint</th><td><code><?= hub_h((string)$service['endpoint']) ?></code></td></tr>
                    <tr><th>content-type</th><td><code><?= hub_h((string)$service['content_type']) ?></code></td></tr>
                    <tr><th>runtime_level</th><td><code><?= hub_h((string)$service['runtime_level']) ?></code></td></tr>
                    <tr><th>execution_type</th><td><code><?= hub_h((string)$service['execution_type']) ?></code></td></tr>
                    <?php if (($service['task_type'] ?? '') !== ''): ?>
                        <tr><th>task_type</th><td><code><?= hub_h((string)$service['task_type']) ?></code></td></tr>
                    <?php endif; ?>
                </table>
                <h3>Request fields</h3>
                <pre><?= hub_h(json_encode($service['input_fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <h3>Response keys</h3>
                <pre><?= hub_h(json_encode($service['output_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php if (($service['task_api'] ?? []) !== []): ?>
                    <h3>Task status / result</h3>
                    <pre><?= hub_h(json_encode($service['task_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <h3>Error codes</h3>
                <pre><?= hub_h(implode(', ', $service['error_codes'])) ?></pre>
                <h3>curl 範例</h3>
                <pre><?= hub_h((string)$service['examples']['curl']) ?></pre>
                <h3>PHP 範例</h3>
                <pre><?= hub_h((string)$service['examples']['php']) ?></pre>
                <h3>JS fetch 範例</h3>
                <pre><?= hub_h((string)$service['examples']['js_fetch']) ?></pre>
            </article>
        <?php endforeach; ?>
    </section>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
