<?php
declare(strict_types=1);

hub_test('PhaseDX-3 public API docs policy settings and manifest are safe', function (): void {
    $helperPath = HUB_ROOT . '/app/public_api_docs.php';
    hub_test_assert(is_file($helperPath), 'app/public_api_docs.php missing');
    require_once $helperPath;

    $db = hub_test_reset_db();

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1', 'public docs default must be enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST') === '1', 'public manifest default must be enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '0', 'public API docs default must be open access');

    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST') === true, 'local manifest should be allowed by default');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'public docs should be allowed by default');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === true, 'public docs should allow external IP by default');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST') === true, 'manifest should allow external IP by default');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    hub_test_assert(hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_DOCS') === false, 'local-only docs must block external IP when enabled');

    $manifest = hub_public_api_manifest($db);
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    hub_test_assert(is_array($manifest['services'] ?? null), 'manifest services missing');
    foreach (['hello', 'ocr', 'yolo', 'translate', 'sam3', 'yolo_model_register', 'yolo_model_status'] as $mode) {
        hub_test_assert(in_array($mode, array_column($manifest['services'], 'mode'), true), 'manifest missing mode ' . $mode);
    }
    $yoloRegister = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'yolo_model_register') {
            $yoloRegister = $service;
            break;
        }
    }
    hub_test_assert(is_array($yoloRegister), 'manifest missing yolo_model_register mode');
    hub_test_assert(str_contains((string)$yoloRegister['examples']['curl'], '<ALLOWLISTED_HOST_PATH>/best.pt'), 'yolo register example must use allowlist placeholder');
    hub_test_assert(!str_contains((string)$yoloRegister['examples']['curl'], '/DATA/'), 'yolo register example must not leak host root');
    $photoUpload = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'photo_upload') {
            $photoUpload = $service;
            break;
        }
    }
    hub_test_assert(is_array($photoUpload), 'manifest missing photo_upload mode');
    hub_test_assert(str_contains((string)$photoUpload['examples']['php'], "new CURLFile('/path/to/example.jpg'"), 'photo_upload PHP example must include usable CURLFile');
    hub_test_assert(str_contains((string)$photoUpload['examples']['js_fetch'], 'const formData = new FormData()'), 'photo_upload JS example must define formData');
    hub_test_assert(str_contains((string)$photoUpload['examples']['js_fetch'], "formData.append('image', fileInput.files[0])"), 'photo_upload JS example must define formData image upload');
    hub_test_assert(!str_contains((string)$photoUpload['examples']['php'], 'CURLFile here'), 'photo_upload PHP example must not use placeholder CURLFile text');
    hub_test_assert(!str_contains((string)$photoUpload['examples']['js_fetch'], 'undefined formData'), 'photo_upload JS example must not reference undefined formData');
    $photo = null;
    foreach ($manifest['services'] as $service) {
        if (($service['mode'] ?? '') === 'photo') {
            $photo = $service;
            break;
        }
    }
    hub_test_assert(is_array($photo), 'manifest missing photo mode');
    $photoFields = [];
    foreach ($photo['input_fields'] ?? [] as $field) {
        if (is_array($field)) {
            $photoFields[(string)($field['name'] ?? '')] = $field;
        }
    }
    hub_test_assert(($photoFields['real_inference']['default'] ?? null) === false, 'photo public docs real_inference default must be false');
    foreach (['mock', 'runtime_level', 'model'] as $key) {
        hub_test_assert(in_array($key, $photo['output_keys'] ?? [], true), 'photo public docs response contract missing ' . $key);
    }
    hub_test_assert(str_contains($json, '<TOKEN>'), 'manifest examples must use token placeholder');
    foreach (['local_port', 'docker-compose.generated.yml', '/DATA/models', 'data/logs', '3waaihub.sqlite', 'admin/', 'command_worker', '3wa_live_'] as $secret) {
        hub_test_assert(!str_contains($json, $secret), 'manifest must not leak ' . $secret);
    }

    $docsHtml = hub_public_api_docs_html($db);
    hub_test_assert(str_contains($docsHtml, '3waAIHub API 介接文件'), 'public docs title missing');
    foreach (['API modes', 'Local Jobs', 'bin/aihub-run', 'yolo_train', 'request.json', 'progress.ndjson', 'result.json', 'Local Job Contract v0.1'] as $needle) {
        hub_test_assert(str_contains($docsHtml, $needle), 'public docs local job section missing ' . $needle);
    }
    hub_test_assert(str_contains($docsHtml, 'Authorization: Bearer &lt;TOKEN&gt;'), 'public docs token placeholder missing');
    hub_test_assert(str_contains($docsHtml, 'mode'), 'public docs must keep technical values');
    hub_test_assert(str_contains($docsHtml, 'docparser_parse'), 'public docs must document DocParser task type');
    hub_test_assert(str_contains($docsHtml, 'docparser_repair_translation'), 'public docs must document DocParser repair task type');
    hub_test_assert(str_contains($docsHtml, 'multipart/form-data'), 'public docs must document DocParser multipart upload');
    hub_test_assert(str_contains($docsHtml, 'file=@manual.pdf'), 'public docs must show DocParser PDF file upload');
    hub_test_assert(str_contains($docsHtml, 'mode=task_status&amp;task_id='), 'public docs must show task_status URL');
    hub_test_assert(str_contains($docsHtml, 'mode=task_result&amp;task_id='), 'public docs must show task_result URL');
    hub_test_assert(!str_contains($docsHtml, 'admin/'), 'public docs must not include admin links when not logged in');
    hub_test_assert(!str_contains($docsHtml, 'CURLFile here'), 'public docs multipart PHP example must not use placeholder CURLFile text');
    foreach (['local_port', 'docker-compose.generated.yml', '/DATA/models', 'data/logs', '3waaihub.sqlite', 'command_worker', '3wa_live_', '/DATA/jobs', 'Docker socket'] as $secret) {
        hub_test_assert(!str_contains($docsHtml, $secret), 'public docs must not leak ' . $secret);
    }
});

hub_test('PhaseDX-3 public API docs files and settings UI contract are present', function (): void {
    hub_test_assert(is_file(HUB_ROOT . '/public_api_docs.php'), 'public_api_docs.php missing');
    hub_test_assert(is_file(HUB_ROOT . '/api_manifest.json.php'), 'api_manifest.json.php missing');

    $adminDocs = (string)file_get_contents(HUB_ROOT . '/admin/api_docs.php');
    hub_test_assert(str_contains($adminDocs, 'hub_require_login'), 'admin/api_docs.php must still require login');

    $settingsPage = (string)file_get_contents(HUB_ROOT . '/admin/settings.php');
    foreach (['AIHUB_PUBLIC_API_DOCS', 'AIHUB_PUBLIC_API_MANIFEST', 'AIHUB_PUBLIC_API_LOCAL_ONLY', '未登入 API 文件', '未登入 Agent Manifest', '僅允許本機讀取'] as $needle) {
        hub_test_assert(str_contains($settingsPage, $needle), 'settings API tab missing ' . $needle);
    }
});

hub_test('Client quickstart documents mock defaults and response contract keys', function (): void {
    $quickstart = (string)file_get_contents(HUB_ROOT . '/docs/client_quickstart.md');

    hub_test_assert(str_contains($quickstart, '預設 `real_inference=false`'), 'client quickstart must document real_inference=false default');
    foreach (['`mock`', '`runtime_level`', '`model`'] as $key) {
        hub_test_assert(str_contains($quickstart, $key), 'client quickstart response contract missing ' . $key);
    }
});

hub_test('PhaseDX-3.1 old public docs defaults migrate once only', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '0');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_MANIFEST', '1');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    $db->exec("DELETE FROM settings WHERE key = 'AIHUB_PUBLIC_API_OPEN_ACCESS_MIGRATED'");

    hub_ensure_default_storage_settings($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '1', 'old public docs default must migrate to enabled');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '0', 'old local-only default must migrate to disabled');

    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS', '0');
    hub_set_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY', '1');
    hub_ensure_default_storage_settings($db);
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_DOCS') === '0', 'migration marker must preserve later admin docs setting');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1', 'migration marker must preserve later admin local-only setting');
});
