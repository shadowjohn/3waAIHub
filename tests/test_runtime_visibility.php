<?php
declare(strict_types=1);

hub_test('PhaseRuntime-1B dashboard packs and nav expose runtime visibility', function (): void {
    $dashboard = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    $packs = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    $vision = (string)file_get_contents(HUB_ROOT . '/docs/service_platform_vision_v0.1.md');
    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');

    foreach (['Runtime 24h 執行數', '執行中 Runtime', '24h 失敗 Runtime', '支援 Job 的 Pack', '平台能力矩陣', '執行歷程', '資源取樣'] as $needle) {
        hub_test_assert(str_contains($dashboard, $needle), 'dashboard missing runtime visibility ' . $needle);
    }
    foreach (['runtime_runs.php', '執行歷程'] as $needle) {
        hub_test_assert(str_contains($layout, $needle), 'admin nav missing runtime runs link ' . $needle);
    }
    foreach (['Runtime：', 'Service', 'Job', 'Runtime Contract', 'Local Jobs', 'Preview Adapter', 'runtime_modes', 'local_jobs'] as $needle) {
        hub_test_assert(str_contains($packs, $needle), 'packs page missing runtime badge/content ' . $needle);
    }
    foreach (['AI、GIS 與通用容器服務的統一發佈平台', 'Local Job Contract v0.1', 'Run history', 'YOLO 真實 predict/train/export', '規劃中'] as $needle) {
        hub_test_assert(str_contains($vision, $needle), 'service platform vision missing ' . $needle);
    }
    foreach (['輕量級容器服務與 AI 能力管理平台', 'Local Job Runtime 薄版已完成', 'External Database Profile'] as $needle) {
        hub_test_assert(str_contains($readme, $needle), 'README missing platform positioning ' . $needle);
    }
});

hub_test('PhaseRuntime-1B runtime run list and detail render safely', function (): void {
    $db = hub_test_reset_db();
    $workspace = sys_get_temp_dir() . '/3waaihub_runtime_visibility_' . getmypid() . '/jobs/yolo/001';
    @mkdir($workspace . '/runtime', 0775, true);
    @mkdir($workspace . '/logs', 0775, true);
    file_put_contents($workspace . '/status.json', "{\"status\":\"success\"}\n");
    file_put_contents($workspace . '/result.json', "{\"ok\":true}\n");
    file_put_contents($workspace . '/logs/stdout.log', "hello stdout\n");
    file_put_contents($workspace . '/logs/stderr.log', "hello stderr\n");
    file_put_contents($workspace . '/runtime/resource.ndjson', "{\"memory_bytes\":1048576}\n");

    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, pack_version, runner_version, caller, workspace, state, exit_code, started_at, finished_at, duration_ms, memory_peak_bytes, result_json_path, stdout_log_path, stderr_log_path, created_at)
         VALUES
            (:run_id, :pack_id, :task, :pack_version, :runner_version, :caller, :workspace, :state, :exit_code, :started_at, :finished_at, :duration_ms, :memory_peak_bytes, :result_json_path, :stdout_log_path, :stderr_log_path, :created_at)'
    )->execute([
        ':run_id' => 'run_visibility_001',
        ':pack_id' => 'yolo',
        ':task' => 'yolo_predict',
        ':pack_version' => '0.1.0',
        ':runner_version' => '0.1',
        ':caller' => 'test',
        ':workspace' => $workspace,
        ':state' => 'success',
        ':exit_code' => 0,
        ':started_at' => $now,
        ':finished_at' => $now,
        ':duration_ms' => 1234,
        ':memory_peak_bytes' => 1048576,
        ':result_json_path' => $workspace . '/result.json',
        ':stdout_log_path' => $workspace . '/logs/stdout.log',
        ':stderr_log_path' => $workspace . '/logs/stderr.log',
        ':created_at' => $now,
    ]);
    $db->prepare(
        'INSERT INTO runtime_resource_samples (run_id, sampled_at, cpu_percent, memory_bytes, gpu_json)
         VALUES (:run_id, :sampled_at, :cpu_percent, :memory_bytes, :gpu_json)'
    )->execute([
        ':run_id' => 'run_visibility_001',
        ':sampled_at' => $now,
        ':cpu_percent' => 12.5,
        ':memory_bytes' => 1048576,
        ':gpu_json' => '[{"index":0,"vram_used_bytes":2097152}]',
    ]);

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/runtime_runs.php';
    $listHtml = (string)ob_get_clean();
    foreach (['Runtime 執行歷程', 'run_visibility_001', 'yolo_predict', 'success', 'RAM 峰值', '查看詳情'] as $needle) {
        hub_test_assert(str_contains($listHtml, $needle), 'runtime runs list missing ' . $needle);
    }

    $_GET = ['id' => 'run_visibility_001'];
    ob_start();
    require HUB_ROOT . '/admin/runtime_run.php';
    $detailHtml = (string)ob_get_clean();
    foreach (['Run 詳情', 'run_visibility_001', '資源使用', 'status.json', 'result.json', 'stdout', 'stderr', 'resource.ndjson', 'hello stdout'] as $needle) {
        hub_test_assert(str_contains($detailHtml, $needle), 'runtime run detail missing ' . $needle);
    }
    hub_test_assert(!str_contains($detailHtml, $workspace), 'runtime detail must not expose absolute workspace path');
});
