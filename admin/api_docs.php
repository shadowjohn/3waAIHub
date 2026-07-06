<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$services = hub_list_services($db);
$baseUrl = '../api.php';

hub_admin_header('API Docs', $user);
?>
<section class="panel">
    <h1>API Docs</h1>
    <p class="muted">Base URL: <code><?= hub_h($baseUrl) ?></code></p>
    <p class="muted">錯誤回應會包含 <code>request_id</code>，外部系統串接失敗時請提供 request_id、mode、時間與來源 IP。</p>
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
    <p class="muted">Status: Runtime adapter pending / L1 mock only.</p>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -F "image=@sample.png"</pre>
</section>
<section class="panel">
    <h2>POST Translate</h2>
    <p class="muted">Status: Runtime adapter pending.</p>
    <pre>curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Content-Type: application/json" \
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time."
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
