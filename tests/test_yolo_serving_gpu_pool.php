<?php

declare(strict_types=1);

function hub_test_yolo_gpu_source_model(string $root, string $name, string $content): string
{
    $dir = rtrim($root, '/\\') . '/exports';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $path = $dir . '/' . $name;
    file_put_contents($path, $content);

    return $path;
}

function hub_test_yolo_gpu_register(PDO $db, string $sourceRoot, string $key, string $content): array
{
    $source = hub_test_yolo_gpu_source_model($sourceRoot, $key . '.pt', $content);

    return hub_yolo_register_model_version($db, [
        'source_system' => 'natureweb',
        'external_model_key' => $key,
        'artifact' => [
            'type' => 'host_path',
            'path' => $source,
            'sha256' => hash_file('sha256', $source),
        ],
        'model_type' => 'detect',
        'task_type' => 'detect',
    ]);
}

function hub_test_yolo_gpu_error_code(callable $fn): string
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    throw new RuntimeException('Expected RuntimeException was not thrown.');
}

function hub_test_yolo_gpu_prepare_upload(string $fileName = 'image.jpg'): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'hub-yolo-gpu-image-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp image.');
    }
    file_put_contents($tmp, 'fake-image');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'model_ref' => 'pending',
        'execution_policy' => 'auto',
    ];
    $_FILES = [
        'image' => [
            'name' => $fileName,
            'type' => 'image/jpeg',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmp),
        ],
    ];
}

hub_test('YOLO GPU pool assigns fixed slots and records warm/unload runs', function (): void {
    $db = hub_test_reset_db();
    $sourceRoot = sys_get_temp_dir() . '/hub-yolo-gpu-source-' . bin2hex(random_bytes(4));
    hub_set_storage_setting($db, 'AIHUB_MODEL_IMPORT_ROOTS', $sourceRoot);
    hub_set_storage_setting($db, 'AIHUB_YOLO_MODEL_REGISTRY_DIR', sys_get_temp_dir() . '/hub-yolo-gpu-registry-' . bin2hex(random_bytes(4)));

    $m1 = hub_test_yolo_gpu_register($db, $sourceRoot, 'training_result:101', 'model-a');
    $m2 = hub_test_yolo_gpu_register($db, $sourceRoot, 'training_result:102', 'model-b');
    $m3 = hub_test_yolo_gpu_register($db, $sourceRoot, 'training_result:103', 'model-c');

    $warm = static function (string $action, array $deployment, array $model): array {
        hub_test_assert($action === 'warm', 'Runtime caller should receive warm action.');
        hub_test_assert((int)$deployment['slot_no'] >= 1 && (int)$deployment['slot_no'] <= 2, 'Warm slot must be 1 or 2.');
        hub_test_assert($model['model_ref'] !== '', 'Warm call should include model_ref.');

        return [
            'ok' => true,
            'state' => 'hot',
            'vram_bytes' => 123456789,
            'load_duration_ms' => 321,
            'warm_inference_ms' => 45,
        ];
    };

    $slot1 = hub_yolo_assign_gpu_slot($db, $m1['model_ref'], 1, $warm);
    $slot2 = hub_yolo_assign_gpu_slot($db, $m2['model_ref'], 2, $warm);

    hub_test_assert($slot1['deployment']['actual_state'] === 'hot', 'Slot 1 should become hot after successful warm.');
    hub_test_assert($slot2['deployment']['actual_state'] === 'hot', 'Slot 2 should become hot after successful warm.');
    hub_test_assert((int)$slot1['deployment']['slot_no'] === 1, 'Slot 1 assignment should stay in slot 1.');
    hub_test_assert((int)$slot2['deployment']['slot_no'] === 2, 'Slot 2 assignment should stay in slot 2.');
    hub_test_assert($slot1['run_id'] !== '', 'Warm should create a runtime run id.');

    $invalidSlot = hub_test_yolo_gpu_error_code(static fn () => hub_yolo_assign_gpu_slot($db, $m3['model_ref'], 3, $warm));
    hub_test_assert($invalidSlot === 'gpu_slot_invalid', 'Slot outside 1/2 should be rejected.');

    $occupied = hub_test_yolo_gpu_error_code(static fn () => hub_yolo_assign_gpu_slot($db, $m3['model_ref'], 1, $warm));
    hub_test_assert($occupied === 'gpu_slot_occupied', 'Occupied slot should reject a third model.');

    $already = hub_test_yolo_gpu_error_code(static fn () => hub_yolo_assign_gpu_slot($db, $m1['model_ref'], 2, $warm));
    hub_test_assert($already === 'gpu_model_already_assigned', 'Same model should not be assigned to two slots.');

    $unload = static function (string $action, array $deployment, array $model): array {
        hub_test_assert($action === 'unload', 'Runtime caller should receive unload action.');
        hub_test_assert($deployment['model_version_id'] === $model['id'], 'Unload call should match the model version.');

        return ['ok' => true];
    };

    $removed = hub_yolo_unassign_gpu($db, $m1['model_ref'], $unload);
    hub_test_assert($removed['ok'] === true, 'Unassign should succeed.');
    hub_test_assert($removed['run_id'] !== '', 'Unload should create a runtime run id.');

    $remaining = $db->prepare('SELECT COUNT(*) FROM yolo_model_deployments WHERE model_version_id = ?');
    $remaining->execute([(int)$m1['id']]);
    hub_test_assert((int)$remaining->fetchColumn() === 0, 'Unassign should remove deployment row only.');

    $modelStillExists = $db->prepare('SELECT COUNT(*) FROM yolo_model_versions WHERE id = ?');
    $modelStillExists->execute([(int)$m1['id']]);
    hub_test_assert((int)$modelStillExists->fetchColumn() === 1, 'Unassign must not delete registry model version.');

    $runs = $db->query("SELECT task, state FROM runtime_runs WHERE task IN ('yolo_model_warm', 'yolo_model_unload') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    hub_test_assert(count($runs) === 3, 'Two warm runs and one unload run should be recorded.');
    hub_test_assert($runs[0]['state'] === 'succeeded', 'Successful warm run should be marked succeeded.');
    hub_test_assert($runs[2]['task'] === 'yolo_model_unload', 'Unload should be tracked as yolo_model_unload.');
    $resultRows = $db->query("SELECT result_json_path FROM runtime_runs WHERE task IN ('yolo_model_warm', 'yolo_model_unload') ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($resultRows as $row) {
        hub_test_assert((string)$row['result_json_path'] !== '', 'Warm/unload runtime run should record result_json_path.');
        hub_test_assert(is_file(hub_path((string)$row['result_json_path'])), 'Warm/unload runtime result JSON should exist.');
    }
});

hub_test('YOLO serving GPU service compose uses CUDA runtime without changing CPU service', function (): void {
    $db = hub_test_reset_db();

    $cpu = hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-cpu',
        'name' => 'YOLO CPU Serving',
        'mode' => 'yolo_predict',
        'port_mode' => 'manual',
        'local_port' => 18320,
        'environment' => 'production',
        'env' => [
            'YOLO_SERVING_DEVICE' => 'cpu',
        ],
    ]);

    $gpu = hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-gpu0',
        'name' => 'YOLO GPU Warm Pool',
        'mode' => 'yolo_gpu_internal',
        'port_mode' => 'manual',
        'local_port' => 18321,
        'environment' => 'production',
        'env' => [
            'YOLO_SERVING_DEVICE' => 'cuda:0',
            'YOLO_GPU_SLOTS' => '2',
        ],
    ]);

    $cpuCompose = file_get_contents(hub_path((string)$cpu['service']['compose_file']));
    $gpuCompose = file_get_contents(hub_path((string)$gpu['service']['compose_file']));
    $gpuEnv = file_get_contents(dirname(hub_path((string)$gpu['service']['compose_file'])) . '/.env');

    hub_test_assert($cpuCompose !== false && !str_contains($cpuCompose, 'gpus: all'), 'CPU serving compose should not request GPU.');
    hub_test_assert($gpuCompose !== false && str_contains($gpuCompose, 'gpus: all'), 'GPU serving compose should request GPU.');
    hub_test_assert($gpuCompose !== false && str_contains($gpuCompose, 'NVIDIA_VISIBLE_DEVICES: "${GPU_VISIBLE_DEVICES:-0}"'), 'GPU serving should default to GPU 0.');
    hub_test_assert($gpuCompose !== false && str_contains($gpuCompose, '/models/registry:ro'), 'GPU serving should mount model registry read-only.');
    hub_test_assert($gpuEnv !== false && str_contains($gpuEnv, 'YOLO_SERVING_DEVICE=cuda:0'), 'GPU env should request cuda:0.');
    hub_test_assert($gpuEnv !== false && str_contains($gpuEnv, 'YOLO_GPU_SLOTS=2'), 'GPU env should declare two slots.');

    $pack = hub_get_pack('yolo-serving');
    $manifest = $pack['manifest'] ?? [];
    hub_test_assert(($manifest['hardware']['gpu_supported'] ?? false) === true, 'YOLO serving manifest should support GPU.');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? true) === false, 'YOLO serving manifest should still allow CPU.');
});

hub_test('YOLO gateway routes model_ref through GPU hot slot or CPU fallback safely', function (): void {
    $db = hub_test_reset_db();
    $sourceRoot = sys_get_temp_dir() . '/hub-yolo-gpu-route-source-' . bin2hex(random_bytes(4));
    hub_set_storage_setting($db, 'AIHUB_MODEL_IMPORT_ROOTS', $sourceRoot);
    hub_set_storage_setting($db, 'AIHUB_YOLO_MODEL_REGISTRY_DIR', sys_get_temp_dir() . '/hub-yolo-gpu-route-registry-' . bin2hex(random_bytes(4)));
    $model = hub_test_yolo_gpu_register($db, $sourceRoot, 'training_result:201', 'route-model');

    hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-cpu',
        'name' => 'YOLO CPU Serving',
        'mode' => 'yolo_predict',
        'port_mode' => 'manual',
        'local_port' => 18330,
        'environment' => 'production',
    ]);
    hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-gpu0',
        'name' => 'YOLO GPU Warm Pool',
        'mode' => 'yolo_gpu_internal',
        'port_mode' => 'manual',
        'local_port' => 18331,
        'environment' => 'production',
        'env' => [
            'YOLO_SERVING_DEVICE' => 'cuda:0',
            'YOLO_GPU_SLOTS' => '2',
        ],
    ]);
    $cpuService = hub_get_service_by_mode($db, 'yolo_predict');
    $gpuService = hub_get_service_by_mode($db, 'yolo_gpu_internal');
    hub_set_service_enabled($db, 'yolo_predict', true);
    hub_set_service_enabled($db, 'yolo_gpu_internal', true);
    hub_update_service_status($db, (int)$cpuService['id'], 'running');
    hub_update_service_status($db, (int)$gpuService['id'], 'running');

    hub_yolo_assign_gpu_slot($db, $model['model_ref'], 1, static fn (): array => [
        'ok' => true,
        'state' => 'hot',
        'vram_bytes' => 123,
        'load_duration_ms' => 1,
        'warm_inference_ms' => 1,
    ]);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['model_ref' => $model['model_ref']];
    $_POST = [];
    $hotStatus = hub_gateway_dispatch($db, 'yolo_model_status');
    $hotPayload = json_decode((string)$hotStatus['body'], true);
    hub_test_assert(($hotPayload['warm_state'] ?? '') === 'hot', 'Running GPU service should report hot warm_state.');
    hub_test_assert(($hotPayload['gpu']['service_available'] ?? false) === true, 'Running GPU service should be available.');
    hub_test_assert(($hotPayload['gpu']['service']['runtime_status'] ?? '') === 'running', 'GPU status should expose runtime_status.');

    hub_update_service_status($db, (int)$gpuService['id'], 'stopped');
    $stoppedStatus = hub_gateway_dispatch($db, 'yolo_model_status');
    $stoppedPayload = json_decode((string)$stoppedStatus['body'], true);
    hub_test_assert(($stoppedPayload['warm_state'] ?? '') === 'cold', 'Stopped GPU service must not report top-level hot warm_state.');
    hub_test_assert(($stoppedPayload['gpu']['actual_state'] ?? '') === 'hot', 'GPU status should preserve DB slot actual_state.');
    hub_test_assert(($stoppedPayload['gpu']['service_available'] ?? true) === false, 'Stopped GPU service should not be available.');
    hub_test_assert(($stoppedPayload['gpu']['blocked_reason'] ?? '') === 'gpu_service_unavailable', 'Stopped GPU service should explain blocked reason.');
    hub_update_service_status($db, (int)$gpuService['id'], 'running');

    hub_test_yolo_gpu_prepare_upload();
    $_POST['model_ref'] = $model['model_ref'];
    $_POST['execution_policy'] = 'auto';
    $calls = [];
    $gpuResponse = hub_gateway_dispatch($db, 'yolo_predict', function (array $service, int $timeoutSec) use (&$calls, $model): array {
        $calls[] = $service['service_key'];
        hub_test_assert($timeoutSec > 0, 'Gateway should pass timeout.');
        hub_test_assert($service['service_key'] === 'yolo-gpu0', 'Auto route should prefer hot GPU slot.');
        hub_test_assert(($_POST['slot_no'] ?? '') === '1', 'Gateway should inject slot_no for GPU runtime.');
        hub_test_assert(($_POST['device'] ?? '') === 'cuda:0', 'Gateway should inject CUDA device.');
        hub_test_assert(($_POST['model_version_id'] ?? '') === (string)$model['id'], 'Gateway should inject model version.');

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'ok' => true,
                'model_ref' => $model['model_ref'],
                'version_id' => $model['id'],
                'device_used' => 'cuda:0',
                'slot_no' => 1,
                'detections' => [],
            ]),
        ];
    });
    hub_test_assert($gpuResponse['status'] === 200, 'Auto GPU route should return success.');
    hub_test_assert($calls === ['yolo-gpu0'], 'Auto route should call GPU service exactly once when hot.');

    $db->prepare("UPDATE yolo_model_deployments SET actual_state = 'warming' WHERE model_version_id = ?")->execute([(int)$model['id']]);

    hub_test_yolo_gpu_prepare_upload();
    $_POST['model_ref'] = $model['model_ref'];
    $_POST['execution_policy'] = 'auto';
    $calls = [];
    $cpuResponse = hub_gateway_dispatch($db, 'yolo_predict', function (array $service) use (&$calls, $model): array {
        $calls[] = $service['service_key'];
        hub_test_assert($service['service_key'] === 'yolo-cpu', 'Auto route should fallback to CPU when GPU is not hot.');
        hub_test_assert(($_POST['device'] ?? '') === 'cpu', 'CPU fallback should inject cpu device.');
        hub_test_assert(($_POST['fallback_reason'] ?? '') !== '', 'CPU fallback should include fallback_reason.');

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'ok' => true,
                'model_ref' => $model['model_ref'],
                'version_id' => $model['id'],
                'device_used' => 'cpu',
                'fallback_reason' => $_POST['fallback_reason'] ?? '',
                'detections' => [],
            ]),
        ];
    });
    hub_test_assert($cpuResponse['status'] === 200, 'Auto route should allow CPU fallback.');
    hub_test_assert($calls === ['yolo-cpu'], 'Auto fallback should call CPU once when GPU is warming.');

    hub_test_yolo_gpu_prepare_upload();
    $_POST['model_ref'] = $model['model_ref'];
    $_POST['execution_policy'] = 'gpu_only';
    $gpuOnlyResponse = hub_gateway_dispatch($db, 'yolo_predict', static fn (): array => throw new RuntimeException('gpu_only should fail before proxy when GPU is not hot.'));
    hub_test_assert($gpuOnlyResponse['status'] === 409, 'gpu_only should return 409 when GPU is not hot.');
    hub_test_assert(json_decode($gpuOnlyResponse['body'], true)['error'] === 'gpu_not_ready', 'gpu_only should return gpu_not_ready.');

    $db->prepare("UPDATE yolo_model_deployments SET actual_state = 'hot' WHERE model_version_id = ?")->execute([(int)$model['id']]);

    hub_test_yolo_gpu_prepare_upload();
    $_POST['model_ref'] = $model['model_ref'];
    $_POST['execution_policy'] = 'auto';
    $calls = [];
    $fallbackResponse = hub_gateway_dispatch($db, 'yolo_predict', function (array $service) use (&$calls, $model): array {
        $calls[] = $service['service_key'];
        if ($service['service_key'] === 'yolo-gpu0') {
            return [
                'status' => 409,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode(['ok' => false, 'error' => 'gpu_not_ready']),
            ];
        }

        hub_test_assert($service['service_key'] === 'yolo-cpu', 'Runtime gpu_not_ready should retry CPU once.');
        hub_test_assert(($_POST['device'] ?? '') === 'cpu', 'Retry should switch payload to CPU.');

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'ok' => true,
                'model_ref' => $model['model_ref'],
                'version_id' => $model['id'],
                'device_used' => 'cpu',
                'fallback_reason' => $_POST['fallback_reason'] ?? '',
                'detections' => [],
            ]),
        ];
    });
    hub_test_assert($fallbackResponse['status'] === 200, 'Auto route should fallback once when GPU runtime is not ready.');
    hub_test_assert($calls === ['yolo-gpu0', 'yolo-cpu'], 'Auto route should call GPU then CPU fallback once.');

    hub_test_yolo_gpu_prepare_upload();
    $_POST['model_ref'] = $model['model_ref'];
    $_POST['slot_no'] = '1';
    $blocked = hub_gateway_dispatch($db, 'yolo_predict', static fn (): array => throw new RuntimeException('Client slot_no must be rejected before proxy.'));
    hub_test_assert($blocked['status'] === 400, 'Client-supplied slot_no should be rejected.');
});

hub_test('YOLO GPU warm pool docs and runtime endpoints are exposed', function (): void {
    $db = hub_test_reset_db();
    $html = hub_public_api_docs_html($db);
    hub_test_assert(str_contains($html, 'yolo_model_assign_gpu'), 'Public docs should mention GPU assign mode.');
    hub_test_assert(str_contains($html, 'yolo_model_unassign_gpu'), 'Public docs should mention GPU unassign mode.');
    hub_test_assert(str_contains($html, '?mode=yolo_model_status&amp;model_ref='), 'Public docs should show yolo_model_status as GET query.');

    $manifest = hub_public_api_manifest($db);
    $modes = array_column($manifest['services'], 'mode');
    hub_test_assert(in_array('yolo_model_assign_gpu', $modes, true), 'Agent manifest should include GPU assign mode.');
    hub_test_assert(in_array('yolo_model_unassign_gpu', $modes, true), 'Agent manifest should include GPU unassign mode.');
    $statusService = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'yolo_model_status') {
            $statusService = $service;
            break;
        }
    }
    hub_test_assert(is_array($statusService), 'Agent manifest should include YOLO status mode.');
    hub_test_assert(($statusService['method'] ?? '') === 'GET', 'YOLO status should be documented as GET.');
    hub_test_assert(($statusService['content_type'] ?? null) === '', 'YOLO status GET should not advertise a request Content-Type.');

    $source = (string)file_get_contents(HUB_ROOT . '/packs/yolo-serving/service/app.py');
    foreach (['@app.get("/models")', '@app.get("/models/{slot_no}/status")', '@app.post("/models/warm"', '@app.post("/models/unload"'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'YOLO serving runtime missing ' . $needle);
    }
    hub_test_assert(substr_count($source, '"fallback": bool(fallback_reason)') >= 2, 'YOLO predict response should expose top-level fallback flag.');

    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    hub_test_assert(str_contains($readme, '/DATA/models/yolo/registry'), 'README should document YOLO registry write permissions.');
    $fixPermissions = (string)file_get_contents(HUB_ROOT . '/scripts/fix_permissions.sh');
    hub_test_assert(str_contains($fixPermissions, '/DATA/models/yolo/registry'), 'fix_permissions should prepare YOLO registry directory.');
    hub_test_assert(str_contains($fixPermissions, 'setfacl'), 'fix_permissions should apply ACL when available.');
});
