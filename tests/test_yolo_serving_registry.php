<?php
declare(strict_types=1);

function hub_test_yolo_source_model(string $root, string $name, string $content = 'fake-yolo-pt'): array
{
    $dir = $root . '/natureweb/run47';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create test YOLO source dir.');
    }
    $path = $dir . '/' . $name;
    file_put_contents($path, $content);

    return [$path, hash_file('sha256', $path)];
}

hub_test('YOLO serving registry imports allowlisted host path as immutable model version', function (): void {
    $db = hub_test_reset_db();
    $sourceRoot = sys_get_temp_dir() . '/3waaihub_yolo_import_' . bin2hex(random_bytes(4));
    [$sourcePath, $sha256] = hub_test_yolo_source_model($sourceRoot, 'best.pt');
    hub_set_storage_setting($db, 'AIHUB_MODEL_IMPORT_ROOTS', $sourceRoot);

    $input = [
        'source_system' => 'natureweb',
        'external_model_key' => 'training_result:47',
        'display_name' => 'NatureWeb Result 47',
        'task_type' => 'detect',
        'artifact' => ['type' => 'host_path', 'path' => $sourcePath, 'sha256' => $sha256],
        'metadata' => ['imgsz' => 800, 'class_count' => 2, 'labels' => ['bird', 'plant']],
    ];
    $model = hub_yolo_register_model_version($db, $input, static fn (string $path): array => [
        'task_type' => 'detect',
        'framework_version' => 'ultralytics-test',
        'labels' => ['bird', 'plant'],
    ]);

    hub_test_assert($model['model_ref'] === 'yolo:natureweb:training-result-47:v1', 'model_ref mismatch');
    hub_test_assert($model['artifact_path'] === 'yolo/registry/natureweb/training-result-47/v1/model.pt', 'artifact path must be registry-relative');
    hub_test_assert(is_file(hub_yolo_model_version_host_path($db, $model)), 'registry model copy missing');
    hub_test_assert(hub_yolo_model_version_container_path($model) === '/models/registry/natureweb/training-result-47/v1/model.pt', 'container model path mismatch');

    $again = hub_yolo_register_model_version($db, $input);
    hub_test_assert((int)$again['id'] === (int)$model['id'], 'same key and sha must be idempotent');

    [$sourcePath2, $sha2] = hub_test_yolo_source_model($sourceRoot, 'best2.pt', 'different-fake-yolo-pt');
    $input['artifact'] = ['type' => 'host_path', 'path' => $sourcePath2, 'sha256' => $sha2];
    $v2 = hub_yolo_register_model_version($db, $input);
    hub_test_assert($v2['model_ref'] === 'yolo:natureweb:training-result-47:v2', 'different checksum must create v2');

    $outside = sys_get_temp_dir() . '/3waaihub_yolo_outside_' . bin2hex(random_bytes(4)) . '.pt';
    file_put_contents($outside, 'outside');
    $bad = $input;
    $bad['artifact'] = ['type' => 'host_path', 'path' => $outside, 'sha256' => hash_file('sha256', $outside)];
    hub_test_assert(hub_test_throws(static fn () => hub_yolo_register_model_version($db, $bad)), 'outside import path accepted');

    $bad['artifact'] = ['type' => 'host_path', 'path' => $sourcePath2, 'sha256' => str_repeat('0', 64)];
    hub_test_assert(hub_test_throws(static fn () => hub_yolo_register_model_version($db, $bad)), 'checksum mismatch accepted');
});

hub_test('YOLO serving CPU pack installs yolo_predict service with read-only registry mount', function (): void {
    $db = hub_test_reset_db();
    $pack = hub_get_pack('yolo-serving');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'yolo-serving pack missing or invalid');
    $requiredKeys = $pack['manifest']['l5_contract']['output']['required_keys'] ?? [];
    foreach (['model_ref', 'version_id', 'model_version_id', 'device_used', 'fallback_reason', 'detections'] as $key) {
        hub_test_assert(in_array($key, $requiredKeys, true), 'yolo-serving contract missing output key ' . $key);
    }
    $inputFields = array_column($pack['manifest']['l5_contract']['input']['fields'] ?? [], null, 'name');
    foreach (['conf', 'iou', 'imgsz', 'max_det'] as $field) {
        hub_test_assert(isset($inputFields[$field]), 'yolo-serving contract missing input field ' . $field);
    }

    $installed = hub_install_pack($db, 'yolo-serving', [
        'service_key' => 'yolo-cpu',
        'name' => 'YOLO CPU Serving',
        'mode' => 'yolo_predict',
        'port_mode' => 'manual',
        'local_port' => 18180,
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    hub_test_assert(($service['mode'] ?? '') === 'yolo_predict', 'yolo_predict service mode mismatch');
    hub_test_assert(str_ends_with((string)$service['internal_url'], '/detect/image'), 'yolo-serving internal URL mismatch');

    $compose = (string)file_get_contents(hub_path((string)$service['compose_file']));
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/yolo/registry:/models/registry:ro'), 'registry mount must be read-only');
    hub_test_assert(!str_contains($compose, 'gpus: all'), 'CPU serving compose must not request GPU');

    $env = (string)file_get_contents(dirname(hub_path((string)$service['compose_file'])) . '/.env');
    foreach (['YOLO_SERVING_DEVICE=cpu', 'YOLO_MODEL_REGISTRY_DIR=/models/registry'] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'yolo-serving env missing ' . $needle);
    }
});

hub_test('YOLO serving gateway registers models and injects container path for predict', function (): void {
    $db = hub_test_reset_db();
    $sourceRoot = sys_get_temp_dir() . '/3waaihub_yolo_gateway_' . bin2hex(random_bytes(4));
    [$sourcePath, $sha256] = hub_test_yolo_source_model($sourceRoot, 'best.pt');
    hub_set_storage_setting($db, 'AIHUB_MODEL_IMPORT_ROOTS', $sourceRoot);

    $serverBackup = $_SERVER;
    $getBackup = $_GET;
    $postBackup = $_POST;
    $filesBackup = $_FILES;
    try {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=yolo_model_register';
        $_POST = [
            'source_system' => 'natureweb',
            'external_model_key' => 'training_result:47',
            'display_name' => 'NatureWeb Result 47',
            'artifact_path' => $sourcePath,
            'artifact_sha256' => $sha256,
            'imgsz' => '800',
            'class_count' => '2',
        ];
        $_FILES = [];

        $registered = hub_gateway_dispatch($db, 'yolo_model_register');
        $registeredPayload = json_decode((string)$registered['body'], true);
        hub_test_assert($registered['status'] === 200, 'register mode must return 200');
        hub_test_assert(($registeredPayload['model_ref'] ?? '') === 'yolo:natureweb:training-result-47:v1', 'register response model_ref mismatch');
        hub_test_assert(($registeredPayload['cpu_available'] ?? false) === true, 'registered model must be CPU available');
        hub_test_assert(!str_contains((string)$registered['body'], $sourcePath), 'register response leaked source host path');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['model_ref' => 'yolo:natureweb:training-result-47:v1'];
        $_POST = [];
        $status = hub_gateway_dispatch($db, 'yolo_model_status');
        $statusPayload = json_decode((string)$status['body'], true);
        hub_test_assert($status['status'] === 200, 'model status mode must return 200');
        hub_test_assert(($statusPayload['state'] ?? '') === 'registered', 'status state mismatch');
        hub_test_assert(($statusPayload['cpu_available'] ?? false) === true, 'status cpu_available mismatch');
        hub_test_assert(($statusPayload['warm_state'] ?? '') === 'cold', '1A warm state must be cold');

        hub_install_pack($db, 'yolo-serving', [
            'service_key' => 'yolo-cpu',
            'name' => 'YOLO CPU Serving',
            'mode' => 'yolo_predict',
            'port_mode' => 'manual',
            'local_port' => 18181,
            'environment' => 'production',
        ]);
        hub_set_service_enabled($db, 'yolo_predict', true);
        $service = hub_get_service_by_mode($db, 'yolo_predict');
        hub_update_service_status($db, (int)$service['id'], 'running');

        $image = tempnam(sys_get_temp_dir(), '3wa-yolo-img-');
        file_put_contents($image, 'fake-image');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=yolo_predict';
        $_POST = [
            'model_ref' => 'yolo:natureweb:training-result-47:v1',
            'execution_policy' => 'auto',
            'conf' => '0.31',
            'iou' => '0.62',
            'imgsz' => '800',
            'max_det' => '123',
        ];
        $_FILES = ['image' => [
            'name' => 'sample.jpg',
            'type' => 'image/jpeg',
            'tmp_name' => $image,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($image),
        ]];

        $predict = hub_gateway_dispatch($db, 'yolo_predict', static function (array $service, int $timeoutSec): array {
            hub_test_assert($_POST['model_path'] === '/models/registry/natureweb/training-result-47/v1/model.pt', 'gateway must inject container registry path');
            hub_test_assert($_POST['device'] === 'cpu', '1A must route to CPU device only');
            hub_test_assert($_POST['model_version_id'] > 0, 'gateway must inject model version id');
            hub_test_assert($_POST['conf'] === '0.31' && $_POST['iou'] === '0.62', 'gateway must preserve conf and iou');
            hub_test_assert($_POST['imgsz'] === '800' && $_POST['max_det'] === '123', 'gateway must preserve imgsz and max_det');

            return hub_gateway_json(200, [
                'ok' => true,
                'mock' => true,
                'model' => ['model_ref' => $_POST['model_ref'], 'model_version_id' => (int)$_POST['model_version_id']],
                'runtime' => ['device_used' => 'cpu', 'fallback' => false],
                'detections' => [],
            ]);
        });
        hub_test_assert($predict['status'] === 200, 'predict request must pass');

        $_POST = ['model_ref' => 'yolo:natureweb:training-result-47:v1', 'host_path' => $sourcePath];
        $blocked = hub_gateway_dispatch($db, 'yolo_predict', static fn (): array => throw new RuntimeException('unsafe request reached runtime'));
        hub_test_assert($blocked['status'] === 400, 'client host path must be rejected');
        hub_test_assert(str_contains($blocked['body'], 'bad_request'), 'unsafe request must return bad_request');
    } finally {
        $_SERVER = $serverBackup;
        $_GET = $getBackup;
        $_POST = $postBackup;
        $_FILES = $filesBackup;
        if (isset($image) && is_file($image)) {
            unlink($image);
        }
    }
});
