<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$services = hub_list_services($db);
$contracts = hub_pack_api_contracts();
$baseUrl = '../api.php';

hub_admin_header('API Docs', $user);
?>
<section class="panel">
    <h1>API Docs</h1>
    <p class="muted">Base URL: <code><?= hub_h($baseUrl) ?></code></p>
    <p class="muted">錯誤回應會包含 <code>request_id</code>，外部系統串接失敗時請提供 request_id、mode、時間與來源 IP。</p>
</section>
<section class="panel">
    <h2>Bearer Token</h2>
    <p class="muted">外部 IP 預設需要 Bearer token；localhost 可由 settings 略過 token。Token 明文只會在建立時顯示一次。</p>
    <pre>curl "http://localhost/3waAIHub/api.php?mode=hello" \
  -H "Authorization: Bearer 3wa_live_xxx"</pre>
    <p><a class="button" href="api_members.php">API Members</a> <a class="button" href="api_usage.php">API Usage</a></p>
</section>
<section class="panel">
    <h2>Mode 對應 Service Instance</h2>
    <table>
        <tr><th>Mode</th><th>Service Key</th><th>Name</th><th>Pack</th><th>Status</th></tr>
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
    <h2>Pack API Contracts</h2>
    <?php foreach ($contracts as $packId => $item): ?>
        <?php
        $contract = $item['contract'];
        $mode = (string)($item['pack']['manifest']['default_mode'] ?? $packId);
        $method = (string)($contract['method'] ?? 'POST');
        $endpoint = 'api.php?mode=' . $mode;
        $contentType = (string)($contract['content_type'] ?? '');
        $jsonExample = [];
        foreach (($contract['benchmark']['cases'] ?? []) as $case) {
            if (is_array($case) && is_array($case['body_json'] ?? null) && empty($case['real_inference'])) {
                $jsonExample = $case['body_json'];
                break;
            }
        }
        ?>
        <h3><?= hub_h((string)($item['pack']['manifest']['name'] ?? $packId)) ?></h3>
        <table>
            <tr><th>Mode</th><td><code><?= hub_h($mode) ?></code></td></tr>
            <tr><th>Method</th><td><code><?= hub_h($method) ?></code></td></tr>
            <tr><th>Endpoint</th><td><code><?= hub_h($endpoint) ?></code></td></tr>
            <tr><th>Content-Type</th><td><code><?= hub_h($contentType) ?></code></td></tr>
            <tr><th>Input</th><td><pre class="inline-pre"><?= hub_h(json_encode($contract['input']['fields'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>Output</th><td><pre class="inline-pre"><?= hub_h(json_encode($contract['output'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
            <tr><th>Errors</th><td><code><?= hub_h(implode(', ', array_map('strval', $contract['errors'] ?? []))) ?></code></td></tr>
        </table>
        <?php if ($contentType === 'application/json'): ?>
        <pre>curl -X <?= hub_h($method) ?> "http://localhost/3waAIHub/<?= hub_h($endpoint) ?>" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -H "Content-Type: application/json" \
  -d '<?= hub_h(json_encode($jsonExample, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'</pre>
        <?php else: ?>
        <pre>curl -X <?= hub_h($method) ?> "http://localhost/3waAIHub/<?= hub_h($endpoint) ?>" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -F "image=@sample.png"</pre>
        <?php endif; ?>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<section class="panel">
    <h2>GET hello</h2>
    <pre>curl "http://localhost/3waAIHub/api.php?mode=hello"</pre>
    <pre>{
  "ok": true,
  "service": "hello",
  "message": "3waAIHub service is running"
}</pre>
</section>
<section class="panel">
    <h2>POST OCR</h2>
    <p class="muted">Status: L5 benchmark ready. Mock mode is the default; real inference mode uses <code>real_inference=1</code> or service setting <code>OCR_REAL_INFERENCE=1</code>.</p>
    <h3>Mock mode</h3>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -F "image=@sample.png"</pre>
    <h3>Real inference mode</h3>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -F "image=@sample.png" \
  -F "real_inference=1"</pre>
</section>
<section class="panel">
    <h2>POST Translate</h2>
    <p class="muted">Status: L5 benchmark ready. Mock mode is the default; real inference mode uses <code>real_inference=1</code>.</p>
    <h3>Mock mode</h3>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time."
  }'</pre>
    <h3>Real inference mode</h3>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -H "Content-Type: application/json" \
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time.",
    "real_inference": 1
  }'</pre>
</section>
<section class="panel">
    <h2>Unknown Mode</h2>
    <pre>curl "http://localhost/3waAIHub/api.php?mode=unknown"</pre>
    <pre>{
  "ok": false,
  "error": "unknown_mode",
  "message": "mode is not registered",
  "request_id": "req_20260706171853_abc123"
}</pre>
</section>
<?php hub_admin_footer(); ?>
