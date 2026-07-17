<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_layout.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$user = hub_require_system_admin($db);
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    hub_check_csrf();
    try {
        $subdir = hub_model_asset_safe_path((string)($_POST['subdir'] ?? ''));
        $target = hub_models_root($db) . '/' . $subdir;
        if (is_link($target)) {
            throw new RuntimeException(__('拒絕在 symlink 上建立模型子目錄。'));
        }
        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new RuntimeException(__('無法建立模型子目錄。'));
        }
        $message = __('模型子目錄已建立：') . $subdir;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$root = hub_models_root($db);
$usage = hub_get_disk_usage_for_path($root);
$scan = hub_scan_model_assets($db, ['max_depth' => 5, 'limit' => 300]);
$commonDirs = ['paddleocr', 'yolo', 'ollama', 'sam3', 'whisper', 'huggingface'];
$linkMap = hub_model_service_link_map($db);
$linkedServices = [];
foreach ($linkMap as $serviceKeys) {
    foreach ($serviceKeys as $serviceKey) {
        $linkedServices[$serviceKey] = true;
    }
}

hub_admin_header('模型倉庫', $user);
?>
<?php if ($message !== ''): ?><div class="notice"><?= hub_h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error"><?= hub_h($error) ?></div><?php endif; ?>
<section class="panel">
    <h1><?= hub_h(__('模型倉庫')) ?></h1>
    <p class="muted"><?= hub_h(__('AIHUB_MODELS_DIR 與 model path 保留英文，方便對照 Pack manifest 與 service_key。')) ?></p>
    <div class="hub-card-grid">
        <article class="hub-card">
            <h2><?= hub_h(__('模型根目錄概覽')) ?></h2>
            <p><code><?= hub_h($root) ?></code></p>
            <p>
                <span class="hub-badge <?= $usage['exists'] ? 'hub-badge-ok' : 'hub-badge-bad' ?>"><?= $usage['exists'] ? hub_h(__('存在')) : hub_h(__('缺少')) ?></span>
                <span class="hub-badge <?= $usage['readable'] ? 'hub-badge-ok' : 'hub-badge-bad' ?>"><?= $usage['readable'] ? hub_h(__('可讀')) : hub_h(__('不可讀')) ?></span>
                <span class="hub-badge <?= $usage['writable'] ? 'hub-badge-ok' : 'hub-badge-bad' ?>"><?= $usage['writable'] ? hub_h(__('可寫')) : hub_h(__('不可寫')) ?></span>
            </p>
            <div class="hub-meta">
                <div class="hub-meta-label"><?= hub_h(__('設定鍵')) ?></div>
                <div class="hub-meta-value"><code>AIHUB_MODELS_DIR</code></div>
                <div class="hub-meta-label">model path</div>
                <div class="hub-meta-value"><code><?= hub_h($root) ?></code></div>
                <div class="hub-meta-label"><?= hub_h(__('狀態')) ?></div>
                <div class="hub-meta-value"><?= $usage['exists'] && $usage['readable'] && $usage['writable'] ? hub_h(__('可用')) : hub_h(__('需要檢查權限或路徑')) ?></div>
            </div>
        </article>
        <article class="hub-card">
            <h2><?= hub_h(__('磁碟空間')) ?></h2>
            <div class="hub-meta">
                <div class="hub-meta-label"><?= hub_h(__('可用 / 總量')) ?></div>
                <div class="hub-meta-value"><?= hub_h(hub_model_format_bytes(is_numeric($usage['free_bytes']) ? (float)$usage['free_bytes'] : null)) ?> / <?= hub_h(hub_model_format_bytes(is_numeric($usage['total_bytes']) ? (float)$usage['total_bytes'] : null)) ?></div>
                <div class="hub-meta-label"><?= hub_h(__('可寫入')) ?></div>
                <div class="hub-meta-value"><?= $usage['writable'] ? hub_h(__('是')) : hub_h(__('否')) ?></div>
            </div>
        </article>
        <article class="hub-card">
            <h2><?= hub_h(__('連結服務')) ?></h2>
            <div class="hub-meta">
                <div class="hub-meta-label"><?= hub_h(__('連結路徑數')) ?></div>
                <div class="hub-meta-value"><?= count($linkMap) ?></div>
                <div class="hub-meta-label">service_key</div>
                <div class="hub-meta-value"><?= $linkedServices === [] ? hub_h(__('尚無連結服務')) : hub_h(implode(', ', array_keys($linkedServices))) ?></div>
            </div>
        </article>
    </div>
    <div class="hub-actions">
        <a class="button" href="models.php"><?= hub_h(__('重新整理')) ?></a>
        <a class="button" href="settings.php?tab=storage"><?= hub_h(__('開啟儲存設定')) ?></a>
    </div>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(__('常見模型子目錄')) ?></h2>
        <span class="muted"><?= hub_h(__('空資料夾也會顯示狀態')) ?></span>
    </div>
    <div class="hub-card-grid">
        <?php foreach ($commonDirs as $dir): ?>
            <?php
            $path = $root . '/' . $dir;
            $exists = is_dir($path);
            $assetCount = 0;
            foreach ($scan['assets'] as $asset) {
                $relative = (string)($asset['relative_path'] ?? '');
                if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
                    $assetCount++;
                }
            }
            ?>
            <article class="hub-card">
                <h3><code><?= hub_h($dir) ?></code></h3>
                <p>
                    <span class="hub-badge <?= $exists ? 'hub-badge-ok' : 'hub-badge-muted' ?>"><?= $exists ? hub_h(__('已建立')) : hub_h(__('尚未建立')) ?></span>
                    <?php if ($exists): ?><span class="hub-badge <?= $assetCount > 1 ? 'hub-badge-ok' : 'hub-badge-warn' ?>"><?= $assetCount > 1 ? hub_h(__('有內容')) : hub_h(__('空目錄')) ?></span><?php endif; ?>
                </p>
                <div class="hub-meta">
                    <div class="hub-meta-label"><?= hub_h(__('相對路徑')) ?></div>
                    <div class="hub-meta-value"><code><?= hub_h($dir) ?></code></div>
                    <div class="hub-meta-label"><?= hub_h(__('大小')) ?></div>
                    <div class="hub-meta-value"><?= hub_h(hub_model_format_bytes(hub_model_asset_size($path))) ?></div>
                    <div class="hub-meta-label"><?= hub_h(__('修改時間')) ?></div>
                    <div class="hub-meta-value"><?= $exists ? hub_h(date('Y-m-d H:i:s', filemtime($path) ?: time())) : '-' ?></div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<section class="panel">
    <h2><?= hub_h(__('建立子目錄')) ?></h2>
    <div class="hub-card">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= hub_h(hub_csrf_token()) ?>">
            <label><?= hub_h(__('模型根目錄下的相對子目錄')) ?></label>
            <input name="subdir" placeholder="huggingface/my-model">
            <div class="hub-actions"><button class="primary" type="submit"><?= hub_h(__('建立子目錄')) ?></button></div>
        </form>
    </div>
</section>
<section class="panel">
    <div class="hub-section-title">
        <h2><?= hub_h(__('模型檔案清單')) ?></h2>
        <span class="muted"><?= hub_h(__('symlink 會被略過，避免掃描穿出 models root。')) ?></span>
    </div>
    <?php foreach ($scan['errors'] as $scanError): ?><div class="notice"><?= hub_h($scanError) ?></div><?php endforeach; ?>
    <p class="muted"><?= hub_h(__('最多掃描深度')) ?> <?= (int)$scan['max_depth'] ?>、<?= (int)$scan['limit'] ?> <?= hub_h(__('筆；symlink 只列出但不跟隨。')) ?></p>
    <?php if ($scan['assets'] === []): ?>
        <div class="hub-empty-state"><?= hub_h(__('目前沒有掃描到模型檔案。')) ?></div>
    <?php else: ?>
        <table>
            <tr><th><?= hub_h(__('相對路徑')) ?></th><th><?= hub_h(__('類型')) ?></th><th><?= hub_h(__('大小')) ?></th><th><?= hub_h(__('修改時間')) ?></th><th><?= hub_h(__('連結服務')) ?></th><th><?= hub_h(__('狀態')) ?></th></tr>
            <?php foreach ($scan['assets'] as $asset): ?>
                <tr>
                    <td><code><?= hub_h((string)$asset['relative_path']) ?></code></td>
                    <td><?= hub_h((string)$asset['type']) ?></td>
                    <td><?= hub_h(hub_model_format_bytes($asset['size_bytes'])) ?></td>
                    <td><?= hub_h(date('Y-m-d H:i:s', (int)$asset['mtime'])) ?></td>
                    <td><?= hub_h(implode(', ', (array)($asset['linked_services'] ?? []))) ?></td>
                    <td class="<?= !empty($asset['skipped']) ? 'bad' : 'ok' ?>"><?= !empty($asset['skipped']) ? hub_h(__('symlink 已略過：') . (string)($asset['skip_reason'] ?? '')) : hub_h(__('可用')) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</section>
<?php hub_admin_footer(); ?>
