<?php
declare(strict_types=1);

function hub_test_runtime_rm(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        hub_test_runtime_rm($path . '/' . $entry);
    }
    rmdir($path);
}

hub_test('Pack Runtime Contract docs and YOLO local jobs are declared', function (): void {
    $manifest = json_decode((string)file_get_contents(HUB_ROOT . '/packs/yolo/pack.json'), true);
    hub_test_assert(($manifest['runtime_contract'] ?? '') === '0.1', 'YOLO must declare runtime_contract 0.1');
    hub_test_assert(in_array('job', $manifest['runtime_modes'] ?? [], true), 'YOLO must support job runtime mode');
    hub_test_assert(($manifest['capabilities']['local_job'] ?? false) === true, 'YOLO must declare local_job capability');

    $jobs = [];
    foreach (($manifest['local_jobs'] ?? []) as $job) {
        $jobs[(string)($job['job_key'] ?? '')] = $job;
    }
    foreach (['yolo_predict', 'yolo_train', 'yolo_export_onnx'] as $jobKey) {
        hub_test_assert(isset($jobs[$jobKey]), 'YOLO missing local job ' . $jobKey);
        hub_test_assert(is_file(HUB_ROOT . '/packs/yolo/' . $jobs[$jobKey]['entrypoint']), 'YOLO job entrypoint missing for ' . $jobKey);
        $source = (string)file_get_contents(HUB_ROOT . '/packs/yolo/' . $jobs[$jobKey]['entrypoint']);
        hub_test_assert(str_contains($source, 'docker_args=(--rm -i '), $jobKey . ' must keep stdin open for docker-run python heredoc');
        hub_test_assert(str_contains($source, '--user "$(id -u):$(id -g)"'), $jobKey . ' must not write root-owned workspace artifacts');
        hub_test_assert(str_contains($source, 'AIHUB_YOLO_MODELS_DIR'), $jobKey . ' must support the shared YOLO model directory');
        hub_test_assert(str_contains($source, '/models/yolo'), $jobKey . ' must mount models into the container at /models/yolo');
        if ($jobKey === 'yolo_train') {
            hub_test_assert(str_contains($source, '--shm-size "${AIHUB_YOLO_SHM_SIZE:-8g}"'), 'yolo_train must allocate Docker shm for PyTorch dataloader');
            hub_test_assert(str_contains($source, 'best_model.predict('), 'yolo_train must generate validation predictions from best.pt');
            hub_test_assert(str_contains($source, '"image_id"'), 'yolo_train validation predictions must use NatureWeb image_id format');
            hub_test_assert(str_contains($source, '"category_id"'), 'yolo_train validation predictions must use NatureWeb category_id format');
        }
    }

    $runtimeDoc = (string)file_get_contents(HUB_ROOT . '/docs/pack_runtime_contract_v0.1.md');
    $jobDoc = (string)file_get_contents(HUB_ROOT . '/docs/local_job_contract_v0.1.md');
    hub_test_assert(str_contains($runtimeDoc, 'Local Job Contract'), 'runtime contract doc must mention Local Job Contract');
    hub_test_assert(str_contains($jobDoc, 'bin/aihub-run yolo_predict'), 'local job doc must show thin aihub-run usage');
});

hub_test('aihub-run executes YOLO local job and rejects workspace escape', function (): void {
    if (hub_platform_id() === 'windows') {
        hub_test_skip('Linux Docker local-job execution is unsupported on Windows.');
    }

    $db = hub_test_reset_db();
    $root = sys_get_temp_dir() . '/3waaihub_runtime_contract_' . getmypid();
    hub_test_runtime_rm($root);
    mkdir($root . '/jobs/yolo/001/input', 0775, true);
    file_put_contents($root . '/jobs/yolo/001/input/sample.jpg', 'fake-image');
    file_put_contents($root . '/jobs/yolo/001/request.json', json_encode(['image' => 'input/sample.jpg'], JSON_UNESCAPED_SLASHES) . "\n");

    $runId = 'test-yolo-run-' . getmypid();
    $run = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_predict',
        '--pack',
        'yolo',
        '--run-id',
        $runId,
        '--caller',
        'test',
        '--workspace',
        $root . '/jobs/yolo/001',
    ], 120, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_PREDICT_DRY_RUN' => '1',
    ]);
    hub_test_assert($run['exit_code'] === 0, 'aihub-run yolo_predict failed: ' . $run['output']);

    $result = json_decode((string)file_get_contents($root . '/jobs/yolo/001/result.json'), true);
    hub_test_assert(($result['ok'] ?? false) === true, 'local job result.json must be ok');
    hub_test_assert(($result['job_key'] ?? '') === 'yolo_predict', 'local job result must include job_key');
    hub_test_assert(isset($result['runtime']['duration_ms']), 'local job result must include runtime summary');
    hub_test_assert(is_file($root . '/jobs/yolo/001/progress.ndjson'), 'local job must write progress.ndjson');
    hub_test_assert(is_file($root . '/jobs/yolo/001/runtime/run.json'), 'local job must write runtime/run.json');
    hub_test_assert(is_file($root . '/jobs/yolo/001/runtime/resource.ndjson'), 'local job must write runtime/resource.ndjson');

    $runtimeRun = $db->query("SELECT * FROM runtime_runs WHERE run_id = " . $db->quote($runId))->fetch();
    hub_test_assert($runtimeRun !== false, 'runtime_runs must record aihub-run execution');
    hub_test_assert((string)$runtimeRun['pack_id'] === 'yolo', 'runtime run must record pack_id');
    hub_test_assert((string)$runtimeRun['task'] === 'yolo_predict', 'runtime run must record task');
    hub_test_assert((string)$runtimeRun['state'] === 'succeeded', 'runtime run must record succeeded state');
    $sampleCount = (int)$db->query("SELECT COUNT(*) FROM runtime_resource_samples WHERE run_id = " . $db->quote($runId))->fetchColumn();
    hub_test_assert($sampleCount >= 2, 'runtime_resource_samples must record start and end samples');

    $bad = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_predict',
        '--pack',
        'yolo',
        '--workspace',
        $root . '/outside',
    ], 30, ['AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs']);
    hub_test_assert($bad['exit_code'] !== 0, 'aihub-run must reject workspace outside AIHUB_LOCAL_JOB_ROOT');
    hub_test_assert(str_contains($bad['stderr'], 'workspace must be under'), 'workspace escape error should be clear');

    hub_test_runtime_rm($root);
});

hub_test('YOLO local train validates workspace and writes real runner contract', function (): void {
    if (hub_platform_id() === 'windows') {
        hub_test_skip('Linux Docker local-job execution is unsupported on Windows.');
    }

    $db = hub_test_reset_db();
    $root = sys_get_temp_dir() . '/3waaihub_yolo_train_' . getmypid();
    hub_test_runtime_rm($root);
    $workspace = $root . '/jobs/yolo/train-001';
    mkdir($workspace . '/datasets', 0775, true);
    file_put_contents($workspace . '/data.yaml', "path: datasets\ntrain: images/train\nval: images/val\nnames:\n  0: object\n");
    file_put_contents($workspace . '/train_config.json', json_encode([
        'model' => 'yolo11n.pt',
        'epochs' => 1,
        'imgsz' => 64,
        'batch' => 1,
    ], JSON_UNESCAPED_SLASHES) . "\n");

    $runId = 'test-yolo-train-' . getmypid();
    $run = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_train',
        '--pack',
        'yolo',
        '--run-id',
        $runId,
        '--caller',
        'test',
        '--workspace',
        $workspace,
        '--gpu',
        '0',
    ], 120, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_TRAIN_DRY_RUN' => '1',
    ]);
    hub_test_assert($run['exit_code'] === 0, 'aihub-run yolo_train failed: ' . $run['output']);

    $result = json_decode((string)file_get_contents($workspace . '/result.json'), true);
    hub_test_assert(($result['ok'] ?? false) === true, 'yolo_train result must be ok');
    hub_test_assert(($result['mock'] ?? true) === false, 'yolo_train must not report mock true');
    hub_test_assert(($result['job_key'] ?? '') === 'yolo_train', 'yolo_train result must include job_key');
    hub_test_assert(is_file($workspace . '/runs/train/output/results.csv'), 'yolo_train must write results.csv');
    hub_test_assert(is_file($workspace . '/runs/train/output/weights/best.pt'), 'yolo_train must write best.pt');
    hub_test_assert(is_file($workspace . '/runs/detect/val/predictions.json'), 'yolo_train must write predictions.json');

    $run = $db->query("SELECT * FROM runtime_runs WHERE run_id = " . $db->quote($runId))->fetch();
    hub_test_assert($run !== false && (string)$run['state'] === 'succeeded', 'runtime_runs must record yolo_train success');

    $badWorkspace = $root . '/jobs/yolo/missing-data';
    mkdir($badWorkspace, 0775, true);
    file_put_contents($badWorkspace . '/train_config.json', "{}\n");
    $bad = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_train',
        '--pack',
        'yolo',
        '--workspace',
        $badWorkspace,
    ], 30, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_TRAIN_DRY_RUN' => '1',
    ]);
    hub_test_assert($bad['exit_code'] !== 0, 'yolo_train must fail without data.yaml');
    $badResult = json_decode((string)file_get_contents($badWorkspace . '/result.json'), true);
    hub_test_assert(($badResult['error'] ?? '') === 'missing_data_yaml', 'yolo_train missing data.yaml error must be explicit');

    hub_test_runtime_rm($root);
});

hub_test('YOLO local predict and export write real runner contracts', function (): void {
    if (hub_platform_id() === 'windows') {
        hub_test_skip('Linux Docker local-job execution is unsupported on Windows.');
    }

    $db = hub_test_reset_db();
    $root = sys_get_temp_dir() . '/3waaihub_yolo_predict_export_' . getmypid();
    hub_test_runtime_rm($root);

    $predictWorkspace = $root . '/jobs/yolo/predict-001';
    mkdir($predictWorkspace . '/input', 0775, true);
    file_put_contents($predictWorkspace . '/input/sample.jpg', 'fake-image');
    file_put_contents($predictWorkspace . '/request.json', json_encode([
        'images' => ['input/sample.jpg'],
        'model' => 'yolo11n.pt',
        'conf' => 0.25,
    ], JSON_UNESCAPED_SLASHES) . "\n");

    $predictRunId = 'test-yolo-predict-' . getmypid();
    $predictRun = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_predict',
        '--pack',
        'yolo',
        '--run-id',
        $predictRunId,
        '--caller',
        'test',
        '--workspace',
        $predictWorkspace,
        '--gpu',
        '0',
    ], 120, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_PREDICT_DRY_RUN' => '1',
    ]);
    hub_test_assert($predictRun['exit_code'] === 0, 'aihub-run yolo_predict failed: ' . $predictRun['output']);

    $predictResult = json_decode((string)file_get_contents($predictWorkspace . '/result.json'), true);
    hub_test_assert(($predictResult['ok'] ?? false) === true, 'yolo_predict result must be ok');
    hub_test_assert(($predictResult['mock'] ?? true) === false, 'yolo_predict must not report mock true');
    hub_test_assert(is_file($predictWorkspace . '/runs/predict/output/predictions.json'), 'yolo_predict must write predictions.json');
    hub_test_assert(is_file($predictWorkspace . '/runs/predict/output/labels/sample.txt'), 'yolo_predict must write labels artifact');

    $exportWorkspace = $root . '/jobs/yolo/export-001';
    mkdir($exportWorkspace . '/input', 0775, true);
    file_put_contents($exportWorkspace . '/input/best.pt', 'fake-weights');
    file_put_contents($exportWorkspace . '/request.json', json_encode([
        'model' => 'input/best.pt',
        'format' => 'onnx',
    ], JSON_UNESCAPED_SLASHES) . "\n");

    $exportRunId = 'test-yolo-export-' . getmypid();
    $exportRun = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_export_onnx',
        '--pack',
        'yolo',
        '--run-id',
        $exportRunId,
        '--caller',
        'test',
        '--workspace',
        $exportWorkspace,
        '--gpu',
        '0',
    ], 120, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_EXPORT_DRY_RUN' => '1',
    ]);
    hub_test_assert($exportRun['exit_code'] === 0, 'aihub-run yolo_export_onnx failed: ' . $exportRun['output']);

    $exportResult = json_decode((string)file_get_contents($exportWorkspace . '/result.json'), true);
    hub_test_assert(($exportResult['ok'] ?? false) === true, 'yolo_export_onnx result must be ok');
    hub_test_assert(($exportResult['mock'] ?? true) === false, 'yolo_export_onnx must not report mock true');
    hub_test_assert(is_file($exportWorkspace . '/runs/export/output/model.onnx'), 'yolo_export_onnx must write model.onnx');

    foreach ([$predictRunId => 'yolo_predict', $exportRunId => 'yolo_export_onnx'] as $runId => $task) {
        $run = $db->query("SELECT * FROM runtime_runs WHERE run_id = " . $db->quote((string)$runId))->fetch();
        hub_test_assert($run !== false && (string)$run['task'] === $task && (string)$run['state'] === 'succeeded', 'runtime_runs must record ' . $task . ' success');
    }

    $badWorkspace = $root . '/jobs/yolo/predict-missing-image';
    mkdir($badWorkspace, 0775, true);
    file_put_contents($badWorkspace . '/request.json', json_encode(['images' => ['input/missing.jpg']], JSON_UNESCAPED_SLASHES) . "\n");
    $bad = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_predict',
        '--pack',
        'yolo',
        '--workspace',
        $badWorkspace,
    ], 30, [
        'AIHUB_LOCAL_JOB_ROOT' => $root . '/jobs',
        'AIHUB_YOLO_PREDICT_DRY_RUN' => '1',
    ]);
    hub_test_assert($bad['exit_code'] !== 0, 'yolo_predict must fail when input image is missing');
    $badResult = json_decode((string)file_get_contents($badWorkspace . '/result.json'), true);
    hub_test_assert(($badResult['error'] ?? '') === 'input_image_missing', 'yolo_predict missing image error must be explicit');

    hub_test_runtime_rm($root);
});

hub_test('Windows aihub-run exits 78 before creating local job state', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only aihub-run unsupported contract.');
    }

    $root = sys_get_temp_dir() . '/3waaihub_windows_cli_gate_' . getmypid();
    hub_test_runtime_rm($root);
    $jobRoot = $root . '/jobs';
    $workspace = $jobRoot . '/yolo/001';
    $result = hub_run_command([
        PHP_BINARY,
        HUB_ROOT . '/bin/aihub-run',
        'yolo_predict',
        '--workspace',
        $workspace,
    ], 30, [
        'AIHUB_LOCAL_JOB_ROOT' => $jobRoot,
        'AIHUB_TEST_DB' => $root . '/runtime.sqlite',
    ]);

    hub_test_assert($result['exit_code'] === 78, 'Windows aihub-run exit mismatch: ' . $result['output']);
    hub_test_assert($result['stdout'] === '', 'Windows aihub-run stdout must be empty');
    hub_test_assert($result['stderr'] === 'unsupported: linux-docker target is not available on Windows host', 'Windows aihub-run stderr mismatch');
    hub_test_assert(!is_dir($jobRoot), 'Windows aihub-run must not create local job root');
    hub_test_assert(!is_dir($workspace), 'Windows aihub-run must not create workspace');
    hub_test_assert(!is_file($root . '/runtime.sqlite'), 'Windows aihub-run must not create runtime DB');
});
