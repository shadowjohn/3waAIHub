<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/templates/layout.php';

$db = hub_db();
hub_ensure_default_storage_settings($db);
$user = hub_current_user($db);
$items = hub_catalog_show_items();
$allowedModes = hub_catalog_show_user_modes($db, $user);
$selected = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['mode'] ?? 'ocr'));
if (!isset($items[$selected])) {
    $selected = 'ocr';
}
$sessionTokens = [];
hub_start_session();
foreach ($_SESSION['catalog_show_tokens'] ?? [] as $key => $token) {
    if (is_string($token) && str_starts_with((string)$key, (string)($user['id'] ?? 0) . ':')) {
        $sessionTokens[] = $token;
    }
}

hub_catalog_show_header('3waAIHub 火力展示', $user);
?>
<section class="hero">
    <div>
        <h1>3waAIHub 火力展示</h1>
        <p>一台 AI 主機，從服務管理、權限、API、記錄、模型到硬體狀態，整理成可以展示、測試、交付的工具箱。</p>
    </div>
    <div class="hero-panel">
        <strong>GIDAY 展示就緒</strong>
        <span>公開可看範例，登入後可測自己有權限的 API。</span>
    </div>
</section>

<section class="tabs" aria-label="AI tools">
    <?php foreach ($items as $mode => $item): ?>
        <a class="<?= $mode === $selected ? 'active' : '' ?>" href="?mode=<?= urlencode($mode) ?>"><?= hub_h($item['title']) ?></a>
    <?php endforeach; ?>
</section>

<section class="tool-shell" data-catalog-show>
    <aside class="tool-list">
        <?php foreach ($items as $mode => $item): ?>
            <?php $canRun = $user && (hub_is_system_admin($user) || in_array($mode, $allowedModes, true)); ?>
            <a class="tool-card <?= $mode === $selected ? 'selected' : '' ?>" href="?mode=<?= urlencode($mode) ?>">
                <span><?= hub_h($item['title']) ?></span>
                <strong><?= hub_h($item['name']) ?></strong>
                <small><?= $canRun ? '可測試' : '展示' ?></small>
            </a>
        <?php endforeach; ?>
    </aside>

    <article class="workbench" data-mode="<?= hub_h($selected) ?>">
        <?php $item = $items[$selected]; ?>
        <?php $canRun = $user && (hub_is_system_admin($user) || in_array($selected, $allowedModes, true)); ?>
        <div class="workbench-head">
            <div>
                <p class="eyebrow">api.php?mode=<?= hub_h($selected) ?></p>
                <h2><?= hub_h($item['name']) ?></h2>
                <p><?= hub_h($item['summary']) ?></p>
            </div>
            <span class="status <?= $canRun ? 'ok' : 'muted' ?>"><?= $canRun ? '登入可測' : '展示模式' ?></span>
        </div>

        <div class="split">
            <section class="panel">
                <h3>線上使用範例</h3>
                <p class="muted">範例情境：<?= hub_h($item['sample']) ?></p>
                <?php if (!$user): ?>
                    <div class="notice">請先登入，登入後即可用自己有權限的 mode 測 API。</div>
                <?php elseif (!$canRun): ?>
                    <div class="notice">你的帳號目前沒有 <code><?= hub_h($selected) ?></code> 權限。</div>
                <?php else: ?>
                    <form class="demo-form" data-demo-form data-endpoint="api_proxy.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
                        <input type="hidden" name="mode" value="<?= hub_h($selected) ?>">
                        <label>Bearer Token（可空白，空白時使用登入帳號展示 token）</label>
                        <input name="bearer_token" type="text" placeholder="<TOKEN>">

                        <?php if (in_array($item['kind'], ['image', 'sam3', 'photo'], true)): ?>
                            <label>圖片</label>
                            <input name="image" type="file" accept="image/*">
                        <?php endif; ?>
                        <?php if ($item['kind'] === 'audio'): ?>
                            <label>音訊檔</label>
                            <input name="audio" type="file" accept="audio/wav,.wav">
                        <?php endif; ?>
                        <?php if ($item['kind'] === 'document'): ?>
                            <label>PDF</label>
                            <input name="file" type="file" accept="application/pdf">
                        <?php endif; ?>
                        <?php if (in_array($selected, ['chat', 'photo', 'sam3', 'audio'], true)): ?>
                            <label>提示文字</label>
                            <textarea name="text" rows="4"><?= hub_h($selected === 'sam3' ? 'mammal/insect/plant' : ($selected === 'photo' ? '這張圖裡有什麼？' : ($selected === 'audio' ? '這段錄音的重點是什麼？' : '請用正體中文介紹 3waAIHub。'))) ?></textarea>
                        <?php endif; ?>
                        <?php if ($selected === 'photo'): ?>
                            <label>圖片 ID image_id（已上傳過可直接填）</label>
                            <input name="image_id" placeholder="img_...">
                            <label>最大輸出 token 數 max_tokens</label>
                            <input name="max_tokens" type="number" min="32" max="2048" value="256">
                        <?php endif; ?>
                        <?php if ($selected === 'audio'): ?>
                            <label>音訊 ID audio_id（已上傳過可直接填）</label>
                            <input name="audio_id" placeholder="aud_...">
                            <label>操作 operation</label>
                            <select name="operation"><option value="understand">understand</option><option value="summarize">summarize</option><option value="transcribe">transcribe</option></select>
                            <label>最大輸出 token 數 max_tokens</label>
                            <input name="max_tokens" type="number" min="32" max="2048" value="512">
                            <p class="muted">第一次上傳後會自動帶入 <code>audio_id</code>，下一次追問可不必重傳檔案。</p>
                        <?php endif; ?>
                        <?php if ($selected === 'chat'): ?>
                            <label>最大輸出 token 數 max_tokens</label>
                            <input name="max_tokens" type="number" min="1" max="4096" value="256">
                        <?php endif; ?>
                        <?php if ($selected === 'sam3'): ?>
                            <label>提示類型 prompt_type</label>
                            <select name="prompt_type"><option value="text">text</option><option value="auto">auto</option><option value="points">points</option></select>
                            <label>輸出格式 output_format</label>
                            <select name="output_format"><option value="polygon">polygon</option><option value="metadata">metadata</option><option value="rle">rle</option><option value="both">both</option></select>
                        <?php endif; ?>
                        <label class="check"><input name="real_inference" type="checkbox" value="1" checked> 真實推論</label>
                        <button type="submit">執行展示</button>
                    </form>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h3>回應結果</h3>
                <pre data-demo-output>{ "status": "ready" }</pre>
                <?php if ($sessionTokens !== []): ?>
                    <h3>本次登入可複製 Token</h3>
                    <?php foreach ($sessionTokens as $token): ?>
                        <pre><?= hub_h($token) ?></pre>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>
        </div>

        <section class="panel">
            <h3>介接範例</h3>
            <pre>curl -H "Authorization: Bearer &lt;TOKEN&gt;" "<?= hub_h(hub_catalog_show_api_url($selected)) ?>"</pre>
        </section>
    </article>
</section>
<?php hub_catalog_show_footer(); ?>
