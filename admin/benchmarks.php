<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_login($db);
$runs = hub_list_benchmark_runs($db, 100);

hub_admin_header('Benchmark 測試', $user);
?>
<section class="panel">
    <h1>Benchmark 測試</h1>
    <p class="muted">Benchmark 可跑 host smoke、Pack catalog，以及 Pack l5_contract 宣告的 mock / real contract cases。</p>
    <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=pack_catalog_scan
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=ocr-ppocrv5 --case=ocr_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=ocr-main --case=ocr_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=ocr-main --case=ocr_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=yolo --case=yolo_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=yolo-main --case=yolo_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=sam3 --case=sam3_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=sam3-main --case=sam3_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=translate-gemma12b --case=translate_mock_text
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=translate-main --case=translate_real_text
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=hello_api
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=host_smoke</pre>
</section>
<section class="panel">
    <h2>Benchmark Runs</h2>
    <table>
        <tr><th>ID</th><th>Case</th><th>Pack</th><th>Mode</th><th>Service</th><th>Status</th><th>Elapsed</th><th>Result</th><th>Created</th></tr>
        <?php foreach ($runs as $run): ?>
            <tr>
                <td>#<?= (int)$run['id'] ?></td>
                <td><code><?= hub_h($run['benchmark_key']) ?></code></td>
                <td><code><?= hub_h((string)($run['pack_id'] ?? '')) ?></code></td>
                <td><?= hub_h((string)$run['mode']) ?></td>
                <td><?= hub_h((string)($run['service_name'] ?? '')) ?> <span class="muted"><?= hub_h((string)($run['service_key'] ?? '')) ?></span></td>
                <td class="<?= hub_status_class($run['status']) ?>"><?= hub_h(hub_status_label($run['status'])) ?></td>
                <td><?= $run['elapsed_ms'] === null ? '' : (int)$run['elapsed_ms'] . ' ms' ?></td>
                <td><pre class="inline-pre"><?= hub_h((string)$run['result_json']) ?></pre><?= $run['error_message'] ? '<p class="bad">' . hub_h($run['error_message']) . '</p>' : '' ?></td>
                <td><?= hub_h($run['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php hub_admin_footer(); ?>
