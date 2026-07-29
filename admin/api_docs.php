<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/public_api_docs.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$services = hub_public_api_services($db);
$baseUrl = hub_public_api_base_url();
$genericModeUrl = $baseUrl . '?mode=<mode>';
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
    <pre><?= hub_h($curlExecutable) ?> "<?= hub_h($genericModeUrl) ?>" <?= hub_h($curlContinuation) ?>
  -H "Authorization: Bearer 3wa_live_xxx"</pre>
    <p><a class="button" href="api_members.php">API 會員</a> <a class="button" href="log_explorer.php?tab=api">API 用量</a></p>
</section>
<section class="panel">
    <h2>Live API Contracts</h2>
    <?php if ($services === []): ?>
        <p class="muted">目前沒有健康且可用的 API 服務。</p>
    <?php else: ?>
        <?php foreach ($services as $service): ?>
            <h3><?= hub_h((string)$service['name']) ?></h3>
            <table>
                <tr><th>Mode</th><td><code><?= hub_h((string)$service['mode']) ?></code></td></tr>
                <tr><th>Pack</th><td><code><?= hub_h((string)$service['pack_id']) ?></code></td></tr>
                <tr><th>endpoint</th><td><code><?= hub_h((string)$service['endpoint']) ?></code></td></tr>
                <tr><th>HTTP 方法</th><td><code><?= hub_h((string)$service['method']) ?></code></td></tr>
                <tr><th>Request Content-Type</th><td><code><?= hub_h((string)$service['content_type']) ?></code></td></tr>
                <tr><th>Response Content-Type</th><td><code><?= hub_h((string)($service['response_content_type'] ?? 'application/json')) ?></code></td></tr>
                <tr><th>runtime_level</th><td><code><?= hub_h((string)$service['runtime_level']) ?></code></td></tr>
                <tr><th>execution_type</th><td><code><?= hub_h((string)$service['execution_type']) ?></code></td></tr>
                <?php if (($service['task_type'] ?? '') !== ''): ?>
                    <tr><th>task_type</th><td><code><?= hub_h((string)$service['task_type']) ?></code></td></tr>
                <?php endif; ?>
                <tr><th>輸入欄位</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['input_fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
                <tr><th>輸出 Keys</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['output_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
                <?php if (($service['response_headers'] ?? []) !== []): ?>
                    <tr><th>Response Headers</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['response_headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
                <?php endif; ?>
                <?php if (($service['task_api'] ?? []) !== []): ?>
                    <tr><th>Task API</th><td><pre class="inline-pre"><?= hub_h(json_encode($service['task_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></td></tr>
                <?php endif; ?>
                <tr><th>錯誤碼</th><td><code><?= hub_h(implode(', ', $service['error_codes'])) ?></code></td></tr>
            </table>
            <h4>curl 範例</h4>
            <pre><?= hub_h((string)$service['examples']['curl']) ?></pre>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
<section class="panel">
    <h2>未知 Mode</h2>
    <pre><?= hub_h($curlExecutable) ?> "<?= hub_h(hub_public_api_mode_url('unknown')) ?>"</pre>
    <pre>{
  "ok": false,
  "error": "unknown_mode",
  "message": "mode is not registered",
  "request_id": "req_20260706171853_abc123"
}</pre>
</section>
<?php hub_admin_footer(); ?>
