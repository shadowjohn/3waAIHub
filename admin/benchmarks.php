<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/_layout.php';

$db = hub_db();
$user = hub_require_system_admin($db);
$runs = hub_list_benchmark_runs($db, 100);

hub_admin_header('Benchmark 測試', $user);
?>
<section class="panel">
    <h1>Benchmark 測試</h1>
    <p class="muted">Benchmark 可跑主機 smoke、Pack catalog，以及 Pack l5_contract 宣告的 mock / real contract cases。</p>
    <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=pack_catalog_scan
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=ocr-ppocrv5 --case=ocr_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=ocr-main --case=ocr_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=ocr-main --case=ocr_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=yolo --case=yolo_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=yolo-main --case=yolo_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=sam3 --case=sam3_mock_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=sam3-main --case=sam3_real_image
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=structure-main --case=structure_page_pdf
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=structure-main --case=structure_10page_pdf
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=docparser --case=docparser_submit_pdf
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=docparser --case=docparser_submit_10page_pdf
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=speech-fast-zh --case=speech_fast_zh_submit_audio
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --pack=translate-gemma12b --case=translate_mock_text
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --service=translate-main --case=translate_real_text
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=hello_api
php <?= hub_h(HUB_ROOT . '/scripts/benchmark.php') ?> --case=host_smoke</pre>
    <h2>Fast Chinese ASR L5 實際驗收</h2>
    <p class="muted">submit smoke 只驗證 API contract，並會取消示範 task。下列命令才會以本機公開 API 跑真實 CPU 推論、校驗 artifacts，並更新 L5 狀態。</p>
    <pre class="inline-pre">php <?= hub_h(HUB_ROOT . '/scripts/audio_packs_acceptance.php') ?> \
  --base-url=&lt;LOCAL_API_URL&gt; \
  --token='&lt;TOKEN&gt;' \
  --pack=speech-fast-zh \
  --fixture=<?= hub_h(HUB_ROOT . '/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav') ?> \
  --record-l5 \
  --json</pre>
    <p class="muted"><code>--record-l5</code> 僅接受 loopback API URL，避免遠端驗收結果被登記成本機狀態。</p>
</section>
<section class="panel">
    <h2>Benchmark 執行紀錄</h2>
    <table>
        <tr><th>ID</th><th>案例</th><th>Pack</th><th>Mode</th><th>服務</th><th>狀態</th><th>耗時</th><th>結果</th><th>建立時間</th></tr>
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
