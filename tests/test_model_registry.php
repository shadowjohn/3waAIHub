<?php
declare(strict_types=1);

hub_test('model registry scans models root safely and skips symlinks', function (): void {
    hub_test_require_symlink_fixture('Model registry symlink fixtures are unavailable on this Windows host.');
    $db = hub_test_reset_db();
    hub_test_assert(is_file(HUB_ROOT . '/admin/models.php'), 'admin/models.php missing');
    $root = sys_get_temp_dir() . '/3waaihub_models_' . bin2hex(random_bytes(4));
    mkdir($root . '/yolo', 0775, true);
    mkdir($root . '/yolo/datasets/images/train', 0775, true);
    mkdir($root . '/yolo/datasets/labels/train', 0775, true);
    mkdir($root . '/paddleocr/home/.paddlex', 0775, true);
    file_put_contents($root . '/yolo/yolo11n.pt', 'model');
    file_put_contents($root . '/yolo/datasets/images/train/a.jpg', 'image');
    file_put_contents($root . '/yolo/datasets/images/train/b.jpeg', 'image');
    file_put_contents($root . '/yolo/datasets/images/train/c.png', 'image');
    file_put_contents($root . '/yolo/datasets/labels/train/a.txt', "0 0.5 0.5 1 1\n");
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
    if (hub_platform_id() === 'windows') {
        $systemRoot = (string)getenv('SystemRoot');
        hub_test_assert($systemRoot !== '', 'SystemRoot is unavailable');
        hub_test_assert(!hub_is_safe_models_root($systemRoot), 'SystemRoot accepted as models root');
    } else {
        hub_test_assert(!hub_is_safe_models_root('/etc'), 'etc path accepted as models root');
        hub_test_assert(!hub_is_safe_models_root('/var/lib/docker'), 'docker root accepted as models root');
    }
    hub_test_assert(!hub_is_safe_models_root(HUB_ROOT), 'repo root accepted as models root');

    $modelsPage = (string)file_get_contents(HUB_ROOT . '/admin/models.php');
    hub_test_assert(str_contains($modelsPage, '可用 / 總量'), 'models page must show free / total heading');
    hub_test_assert(str_contains($modelsPage, '影像檔案'), 'models page must show image file count label');
    hub_test_assert(str_contains($modelsPage, 'png / jpg / jpeg'), 'models page must explain supported image extensions');
    hub_test_assert(str_contains($modelsPage, '標記檔案'), 'models page must show label file count label');
    hub_test_assert(str_contains($modelsPage, 'YOLO txt'), 'models page must explain YOLO label extension');
    hub_test_assert(strpos($modelsPage, "usage['free_bytes']") < strpos($modelsPage, "usage['total_bytes']"), 'models page must render free bytes before total bytes');

    $scan = hub_scan_model_assets($db, ['max_depth' => 5, 'limit' => 50]);
    $paths = array_column($scan['assets'], 'relative_path');
    hub_test_assert(in_array('yolo/yolo11n.pt', $paths, true), 'YOLO model file missing from scan');
    foreach (['yolo/datasets/images/train/a.jpg', 'yolo/datasets/images/train/b.jpeg', 'yolo/datasets/images/train/c.png'] as $imagePath) {
        hub_test_assert(in_array($imagePath, $paths, true), 'dataset image missing from scan: ' . $imagePath);
    }
    hub_test_assert(in_array('yolo/datasets/labels/train/a.txt', $paths, true), 'dataset label missing from scan');
    hub_test_assert(in_array('paddleocr/home/.paddlex', $paths, true), 'PaddleOCR model directory missing from scan');
    foreach ($scan['assets'] as $asset) {
        if ($asset['relative_path'] === 'yolo/yolo11n.pt') {
            hub_test_assert(in_array('yolo-test-main', $asset['linked_services'], true), 'linked YOLO service missing');
        }
        if (in_array($asset['relative_path'], ['yolo/datasets/images/train/a.jpg', 'yolo/datasets/images/train/b.jpeg', 'yolo/datasets/images/train/c.png'], true)) {
            hub_test_assert($asset['type'] === 'image_file', 'jpg/jpeg/png dataset assets must be classified as image_file');
        }
        if ($asset['relative_path'] === 'yolo/datasets/labels/train/a.txt') {
            hub_test_assert($asset['type'] === 'label_file', 'YOLO labels/*.txt assets must be classified as label_file');
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

    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', '/');
    hub_test_assert(hub_test_throws(static fn (): string => hub_models_root($db)), 'models root must revalidate a corrupted setting before filesystem use');
});

hub_test('model registry creates requested directories only under the physical models root', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_model_create_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);

    $target = hub_model_asset_safe_directory($root, 'huggingface/my-model');

    hub_test_assert(is_dir($target), 'model directory must be created');
    hub_test_assert(hub_storage_path_is_within($target, $root), 'model directory must remain under models root');
});

hub_test('model registry resolves existing and missing assets only beneath the physical models root', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_model_existing_' . bin2hex(random_bytes(4));
    mkdir($root . '/yolo', 0775, true);
    file_put_contents($root . '/yolo/model.pt', 'model');

    $existing = hub_model_asset_safe_existing_path($root, 'yolo/model.pt');
    $missing = hub_model_asset_safe_existing_path($root, 'future/model.pt');

    hub_test_assert(is_file($existing) && hub_storage_path_is_within(dirname($existing), $root), 'existing model asset must stay under models root');
    hub_test_assert(hub_storage_path_is_within($missing, $root) && !is_dir(dirname($missing)), 'missing model asset must not create a selector directory');
    hub_test_assert(hub_test_throws(static fn (): string => hub_model_asset_safe_existing_path($root, '../escape.pt')), 'existing model resolver accepted traversal');
});

hub_test('YOLO registry slugs cannot introduce path components', function (): void {
    $slug = hub_yolo_slug('../Vendor\\..\\Weights');

    hub_test_assert($slug === 'vendor-weights', 'YOLO registry slug must collapse separators and traversal syntax');
    hub_test_assert(preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) === 1, 'YOLO registry slug must contain only one safe path segment');
    hub_test_assert(hub_test_throws(static fn (): string => hub_yolo_slug('...')), 'empty YOLO registry slug must be rejected');
});

hub_test('model registry refuses model directories beneath a symlinked ancestor', function (): void {
    hub_test_require_symlink_fixture('Model registry directory creation requires symlink fixtures.');
    $root = sys_get_temp_dir() . '/3waaihub_model_symlink_root_' . bin2hex(random_bytes(4));
    $outside = sys_get_temp_dir() . '/3waaihub_model_symlink_outside_' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    mkdir($outside, 0775, true);
    symlink($outside, $root . '/linked');

    hub_test_assert(
        hub_test_throws(static fn (): string => hub_model_asset_safe_directory($root, 'linked/escape')),
        'model directory creation must reject a symlinked ancestor'
    );
    hub_test_assert(!is_dir($outside . '/escape'), 'model directory creation must not escape through a symlinked ancestor');
});
