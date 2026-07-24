<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

function hub_api_docs_public_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $adminDir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/admin/api_docs.php')), '/');
    $basePath = preg_replace('#/admin$#', '', $adminDir) ?: '';

    return ($https ? 'https' : 'http') . '://' . $host . $basePath . '/api.php';
}

function hub_api_docs_mode_url(string $mode): string
{
    return hub_api_docs_public_base_url() . '?mode=' . rawurlencode($mode);
}

function hub_api_docs_multipart_curl_fields(array $contract, string $curlContinuation): string
{
    $lines = [];
    foreach (($contract['input']['fields'] ?? []) as $field) {
        if (!is_array($field)) {
            continue;
        }

        $name = (string)($field['name'] ?? '');
        if ($name === '' || (string)($field['type'] ?? '') === 'file') {
            continue;
        }

        $value = $field['default'] ?? ($field['example'] ?? null);
        if ($value === null || $value === '') {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        } else {
            $value = (string)$value;
        }
        $value = str_replace(["\\", "\"", "\r", "\n"], ["\\\\", "\\\"", ' ', ' '], $value);
        $lines[] = '  -F "' . $name . '=' . $value . '"';
    }

    return $lines === [] ? '' : ' ' . $curlContinuation . "\n" . implode(' ' . $curlContinuation . "\n", $lines);
}

$db = hub_db();
// hub_require_login is enforced by the stricter system_admin guard.
$user = hub_require_system_admin($db);
$services = hub_list_services($db);
$contracts = hub_pack_api_contracts();
$baseUrl = hub_api_docs_public_base_url();
$curlExecutable = hub_platform_id() === 'windows' ? 'curl.exe' : 'curl';
$curlContinuation = hub_platform_id() === 'windows' ? chr(96) : '\\';

hub_admin_header('API 文件', $user);
?>
<section class="panel">
    <h1>API 文件</h1>
    <p class="muted">Base URL: <code><?= hub_h($baseUrl) ?></code></p>
    <p class="muted">錯誤回應會包含 <code>request_id</code>，外部系統串接失敗時請提供 request_id、mode、時間與來源 IP。</p>
</section>
<section class="panel">
    <h2>Bearer Token</h2>
    <p class="muted">外部 IP 預設需要 Bearer token；localhost 可由 settings 略過 token。Token 明文只會在建立時顯示一次。</p>
    <pre><?= hub_h($curlExecutable) ?> "<?= hub_h(hub_api_docs_mode_url('hello')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx"</pre>
    <p><a class="button" href="api_members.php">API 會員</a> <a class="button" href="api_usage.php">API 用量</a></p>
</section>
<section class="panel">
    <h2>Mode 對應服務實例</h2>
    <table>
        <tr><th>Mode</th><th>Service Key</th><th>服務名稱</th><th>Pack</th><th>狀態</th></tr>
        <?php foreach ($services as $service): ?>
            <tr>
                <td><code><?= hub_h($service['mode']) ?></code></td>
                <td><code><?= hub_h((string)$service['service_key']) ?></code></td>
                <td><?= hub_h($service['name']) ?></td>
                <td><?= hub_h((string)$service['pack_id']) ?></td>
                <td class="<?= hub_status_class($service['status']) ?>"><?= hub_h(hub_status_label($service['status'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php if ($contracts): ?>
<section class="panel">
    <h2>Pack API Contract</h2>
    <?php foreach ($contracts as $packId => $item): ?>
        <?php
        $contract = $item['contract'];
        $mode = (string)($item['pack']['manifest']['default_mode'] ?? $packId);
        $method = (string)($contract['method'] ?? 'POST');
        $endpoint = 'api.php?mode=' . $mode;
        $contractUrl = hub_api_docs_mode_url($mode);
        $contentType = (string)($contract['content_type'] ?? '');
        $fileField = 'image';
        foreach (($contract['input']['fields'] ?? []) as $field) {
            if (is_array($field) && (string)($field['type'] ?? '') === 'file' && (string)($field['name'] ?? '') !== '') {
                $fileField = (string)$field['name'];
                $sampleFile = (string)($field['example'] ?? '');
                break;
            }
        }
        $sampleFile = $sampleFile ?? '';
        if ($sampleFile === '') {
            $sampleFile = $fileField === 'audio' ? 'sample.wav' : ($fileField === 'file' ? 'sample.pdf' : 'sample.png');
        }
        $jsonExample = [];
        foreach (($contract['benchmark']['cases'] ?? []) as $case) {
            if (is_array($case) && is_array($case['body_json'] ?? null) && empty($case['real_inference'])) {
                $jsonExample = $case['body_json'];
                break;
            }
        }
        $multipartExtra = hub_api_docs_multipart_curl_fields($contract, $curlContinuation);
        ?>
        <h3><?= hub_h((string)($item['pack']['manifest']['name'] ?? $packId)) ?><?= (($item['pack']['manifest']['role'] ?? '') === 'reference') ? ' / 參考 Pack' : '' ?></h3>
        <table>
            <tr><th>Mode</th><td><code><?= hub_h($mode) ?></code></td></tr>
            <tr><th>HTTP 方法</th><td><code><?= hub_h($method) ?></code></td></tr>
            <tr><th>API 端點</th><td><code><?= hub_h($endpoint) ?></code></td></tr>
            <tr><th>Content-Type</th><td><code><?= hub_h($contentType) ?></code></td></tr>
            <tr><th>輸入欄位</th><td><pre class="inline-pre"><?= hub_h(json_encode($contract['input']['fields'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>輸出格式</th><td><pre class="inline-pre"><?= hub_h(json_encode($contract['output'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>錯誤碼</th><td><code><?= hub_h(implode(', ', array_map('strval', $contract['errors'] ?? []))) ?></code></td></tr>
        </table>
        <?php if ($method === 'GET'): ?>
        <pre><?= hub_h($curlExecutable) ?> "<?= hub_h($contractUrl) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx"</pre>
        <?php elseif ($contentType === 'application/json'): ?>
        <pre><?= hub_h($curlExecutable) ?> -X <?= hub_h($method) ?> "<?= hub_h($contractUrl) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -H "Content-Type: application/json" <?= hub_h($curlContinuation) ?>
  -d '<?= hub_h(json_encode($jsonExample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'</pre>
        <?php else: ?>
        <pre><?= hub_h($curlExecutable) ?> -X <?= hub_h($method) ?> "<?= hub_h($contractUrl) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "<?= hub_h($fileField) ?>=@<?= hub_h($sampleFile) ?>"<?= hub_h($multipartExtra) ?></pre>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<section class="panel">
    <h2>GET hello</h2>
    <pre><?= hub_h($curlExecutable) ?> "<?= hub_h(hub_api_docs_mode_url('hello')) ?>"</pre>
    <pre>{
  "ok": true,
  "service": "hello",
  "message": "3waAIHub service is running"
}</pre>
</section>
<section class="panel">
    <h2>POST OCR</h2>
    <p class="muted">狀態：L5 可驗收。預設為 mock 模式；真實推論使用 <code>real_inference=1</code> 或服務設定 <code>OCR_REAL_INFERENCE=1</code>。</p>
    <h3>Mock 模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('ocr')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@sample.png"</pre>
    <h3>真實推論模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('ocr')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@sample.png" <?= hub_h($curlContinuation) ?>
  -F "real_inference=1"</pre>
</section>
<section class="panel">
    <h2>POST Translate</h2>
    <p class="muted">狀態：L5 可驗收。預設為 mock 模式；真實推論使用 <code>real_inference=1</code>。</p>
    <h3>Mock 模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('translate')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -H "Content-Type: application/json" <?= hub_h($curlContinuation) ?>
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time."
  }'</pre>
    <h3>真實推論模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('translate')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -H "Content-Type: application/json" <?= hub_h($curlContinuation) ?>
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time.",
    "real_inference": 1
  }'</pre>
</section>
<section class="panel">
    <h2>POST SAM3</h2>
    <p class="muted">狀態：L5 可驗收。預設為 mock 模式；真實推論使用 <code>real_inference=1</code>。</p>
    <h3>Mock 模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=auto"</pre>
    <h3>真實推論模式</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=auto" <?= hub_h($curlContinuation) ?>
  -F "real_inference=1" <?= hub_h($curlContinuation) ?>
  -F "output_format=polygon"</pre>
    <h3>點位 prompt</h3>
    <p class="muted"><code>points_json.labels</code>：<code>1</code> 選取目標，<code>0</code> 排除目標；至少需要一個 <code>1</code>，負點只用來修正排除範圍。</p>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=points" <?= hub_h($curlContinuation) ?>
  -F 'points_json={"points":[[320,240]],"labels":[1]}' <?= hub_h($curlContinuation) ?>
  -F "real_inference=1" <?= hub_h($curlContinuation) ?>
  -F "output_format=both"</pre>
    <h3>PNG mask output</h3>
    <p class="muted"><code>output_format=png</code> 會回傳同尺寸 PNG：白色不透明為選取區域，透明為背景。</p>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=points" <?= hub_h($curlContinuation) ?>
  -F 'points_json={"points":[[320,240]],"labels":[1]}' <?= hub_h($curlContinuation) ?>
  -F "real_inference=1" <?= hub_h($curlContinuation) ?>
  -F "output_format=png" <?= hub_h($curlContinuation) ?>
  -o mask.png</pre>
    <h3>手繪 guidance mask</h3>
    <p class="muted"><code>guidance_mask</code> 必須是與原圖同尺寸的 PNG；非透明像素代表要選取的目標，透明像素為中立，不代表負提示。</p>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "guidance_mask=@guidance.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=guidance_mask" <?= hub_h($curlContinuation) ?>
  -F "real_inference=1" <?= hub_h($curlContinuation) ?>
  -F "output_format=png" <?= hub_h($curlContinuation) ?>
  -o mask.png</pre>
    <h3>語意文字 prompt</h3>
    <pre><?= hub_h($curlExecutable) ?> -X POST "<?= hub_h(hub_api_docs_mode_url('sam3')) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx" <?= hub_h($curlContinuation) ?>
  -F "image=@packs/sam3/demo/camera_cat.png" <?= hub_h($curlContinuation) ?>
  -F "prompt_type=text" <?= hub_h($curlContinuation) ?>
  -F "text=mammal/insect/plant" <?= hub_h($curlContinuation) ?>
  -F "real_inference=1" <?= hub_h($curlContinuation) ?>
  -F "output_format=polygon"</pre>
</section>
<section class="panel">
    <h2>未知 Mode</h2>
    <pre><?= hub_h($curlExecutable) ?> "<?= hub_h(hub_api_docs_mode_url('unknown')) ?>"</pre>
    <pre>{
  "ok": false,
  "error": "unknown_mode",
  "message": "mode is not registered",
  "request_id": "req_20260706171853_abc123"
}</pre>
</section>
<?php hub_admin_footer(); ?>
