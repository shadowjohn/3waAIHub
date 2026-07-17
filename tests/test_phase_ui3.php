<?php
declare(strict_types=1);

hub_test('PhaseUI-3 marketplace card contract is present and renders', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');

    foreach (['hub-card-grid', 'hub-card', 'hub-badge', 'hub-meta', 'hub-actions'] as $class) {
        hub_test_assert(str_contains($page, $class), 'marketplace missing UI class ' . $class);
    }
    foreach (['套件名稱', '套件 ID', '執行層級', '目標層級', '預設 mode', 'API endpoint', 'GPU 需求', '模型需求', '已安裝服務數', '安裝狀態'] as $label) {
        hub_test_assert(str_contains($page, $label), 'marketplace missing label ' . $label);
    }
    foreach (['安裝為服務', '查看 API 文件', 'Benchmark 測試', '準備狀態', '已安裝服務'] as $action) {
        hub_test_assert(str_contains($page, $action), 'marketplace missing action ' . $action);
    }
    foreach (['pack_id', 'mode', 'runtime_level', 'execution_type', 'endpoint'] as $technical) {
        hub_test_assert(str_contains($page, $technical), 'marketplace technical value should stay English ' . $technical);
    }

    $db = hub_test_reset_db();
    $modelsDir = sys_get_temp_dir() . '/3waaihub_phase_ui3_marketplace_models_' . bin2hex(random_bytes(4));
    mkdir($modelsDir . '/ollama/models/manifests/registry.ollama.ai/library/translategemma', 0775, true);
    file_put_contents($modelsDir . '/ollama/models/manifests/registry.ollama.ai/library/translategemma/12b-it-q4_K_M', '{}');
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $modelsDir);

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/marketplace.php';
    $html = (string)ob_get_clean();

    foreach (['hello-service', 'ocr-ppocrv5', 'yolo', 'translate-gemma12b', 'sam3', 'hub-card', '安裝為服務'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered marketplace missing ' . $needle);
    }
    hub_test_assert(str_contains($html, 'name="service_key"'), 'install flow service_key input missing');
    hub_test_assert(str_contains($html, 'name="mode"'), 'install flow mode input missing');

    $translatePos = strpos($html, 'translate-gemma12b');
    hub_test_assert($translatePos !== false, 'rendered marketplace missing translate card');
    $translateCard = substr($html, $translatePos, 5000);
    hub_test_assert(str_contains($translateCard, '模型已就緒'), 'TranslateGemma Ollama tag model should render as ready');
});

hub_test('PhaseUI-3 models card contract is present and renders', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/models.php');

    foreach (['hub-card-grid', 'hub-card', 'hub-badge', 'hub-meta', 'hub-actions', 'hub-empty-state', 'hub-section-title'] as $class) {
        hub_test_assert(str_contains($page, $class), 'models missing UI class ' . $class);
    }
    foreach (['模型根目錄概覽', '磁碟空間', '常見模型子目錄', '模型檔案清單', '連結服務', '建立子目錄'] as $section) {
        hub_test_assert(str_contains($page, $section), 'models missing section ' . $section);
    }
    foreach (['paddleocr', 'yolo', 'ollama', 'sam3', 'whisper', 'huggingface'] as $dir) {
        hub_test_assert(str_contains($page, $dir), 'models missing common dir ' . $dir);
    }
    foreach (['symlink 會被略過', '相對路徑', '類型', '大小', '修改時間', '連結服務'] as $label) {
        hub_test_assert(str_contains($page, $label), 'models missing inventory label ' . $label);
    }
    foreach (['AIHUB_MODELS_DIR', 'model path', 'service_key'] as $technical) {
        hub_test_assert(str_contains($page, $technical), 'models technical value should stay English ' . $technical);
    }

    $db = hub_test_reset_db();
    $modelsDir = sys_get_temp_dir() . '/3waaihub_phase_ui3_models_' . bin2hex(random_bytes(4));
    mkdir($modelsDir, 0775, true);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $modelsDir);
    @mkdir($modelsDir . '/sam3', 0775, true);
    file_put_contents($modelsDir . '/sam3/demo.pt', 'demo');
    @symlink($modelsDir . '/sam3/demo.pt', $modelsDir . '/symlink-demo.pt');

    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    require HUB_ROOT . '/admin/models.php';
    $html = (string)ob_get_clean();

    foreach (['模型根目錄概覽', '可用 / 總量', 'sam3', 'whisper', 'demo.pt', 'symlink 已略過', 'name="subdir"'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'rendered models page missing ' . $needle);
    }
});
