<?php
declare(strict_types=1);

hub_test('model registry scans models root safely and skips symlinks', function (): void {
    $db = hub_test_reset_db();
    hub_test_assert(is_file(HUB_ROOT . '/admin/models.php'), 'admin/models.php missing');
    $root = sys_get_temp_dir() . '/3waaihub_models_' . bin2hex(random_bytes(4));
    mkdir($root . '/yolo', 0775, true);
    mkdir($root . '/paddleocr/home/.paddlex', 0775, true);
    file_put_contents($root . '/yolo/yolo11n.pt', 'model');
    symlink('/etc', $root . '/bad-link');
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $root);
    hub_install_pack($db, 'yolo', [
        'service_key' => 'yolo-test-main',
        'name' => 'YOLO Test Main',
        'mode' => 'yolo_test',
        'port_mode' => 'manual',
        'local_port' => 18170,
        'environment' => 'production',
    ]);

    hub_test_assert(hub_models_root($db) === $root, 'models root mismatch');
    hub_test_assert(hub_model_asset_safe_path('yolo/yolo11n.pt') === 'yolo/yolo11n.pt', 'safe relative path mismatch');
    hub_test_assert(hub_test_throws(static fn () => hub_model_asset_safe_path('../etc/passwd')), 'path traversal was accepted');
    hub_test_assert(hub_test_throws(static fn () => hub_model_asset_safe_path('/etc/passwd')), 'absolute asset path was accepted');
    hub_test_assert(!hub_is_safe_models_root('/'), 'root path accepted as models root');
    hub_test_assert(!hub_is_safe_models_root('/etc'), 'etc path accepted as models root');
    hub_test_assert(!hub_is_safe_models_root('/var/lib/docker'), 'docker root accepted as models root');
    hub_test_assert(!hub_is_safe_models_root(HUB_ROOT), 'repo root accepted as models root');

    $modelsPage = (string)file_get_contents(HUB_ROOT . '/admin/models.php');
    hub_test_assert(str_contains($modelsPage, '可用 / 總量'), 'models page must show free / total heading');
    hub_test_assert(strpos($modelsPage, "usage['free_bytes']") < strpos($modelsPage, "usage['total_bytes']"), 'models page must render free bytes before total bytes');

    $scan = hub_scan_model_assets($db, ['max_depth' => 4, 'limit' => 50]);
    $paths = array_column($scan['assets'], 'relative_path');
    hub_test_assert(in_array('yolo/yolo11n.pt', $paths, true), 'YOLO model file missing from scan');
    hub_test_assert(in_array('paddleocr/home/.paddlex', $paths, true), 'PaddleOCR model directory missing from scan');
    foreach ($scan['assets'] as $asset) {
        if ($asset['relative_path'] === 'yolo/yolo11n.pt') {
            hub_test_assert(in_array('yolo-test-main', $asset['linked_services'], true), 'linked YOLO service missing');
        }
        if ($asset['relative_path'] === 'bad-link') {
            hub_test_assert($asset['type'] === 'symlink' && !empty($asset['skipped']), 'symlink must be marked skipped');
        }
    }

    $options = hub_model_selector_options($db, [
        'type' => 'file',
        'root_subdir' => 'yolo',
        'extensions' => ['.pt'],
    ]);
    hub_test_assert(($options[0]['value'] ?? '') === 'yolo11n.pt', 'YOLO selector must expose model file relative to root_subdir');

    mkdir($root . '/ollama/models/manifests/registry.ollama.ai/library/translategemma', 0775, true);
    file_put_contents($root . '/ollama/models/manifests/registry.ollama.ai/library/translategemma/12b-it-q4_K_M', '{}');
    $ollamaStatus = hub_model_selector_status($db, [
        'type' => 'ollama_tag',
        'root_subdir' => 'ollama',
    ], 'translategemma:12b-it-q4_K_M');
    hub_test_assert(($ollamaStatus['model_present'] ?? false) === true, 'Ollama selector must detect present model tag');
});
