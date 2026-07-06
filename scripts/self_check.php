<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$tmp = tempnam(sys_get_temp_dir(), '3waaihub_');
if ($tmp === false) {
    throw new RuntimeException('Cannot create temp database.');
}

$db = new PDO('sqlite:' . $tmp);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
hub_migrate($db);
hub_seed_admin_user($db);
hub_seed_hello_service($db);
hub_ensure_default_storage_settings($db);

$storage = hub_get_storage_paths($db);
assert($storage['AIHUB_MODELS_DIR'] === '/DATA/models');
assert($storage['AIHUB_CACHE_DIR'] === HUB_DATA_DIR . '/cache');
assert($storage['AIHUB_UPLOADS_DIR'] === HUB_DATA_DIR . '/uploads');
assert($storage['AIHUB_RESULTS_DIR'] === HUB_DATA_DIR . '/results');
assert($storage['AIHUB_LOGS_DIR'] === HUB_LOG_DIR);
assert(hub_get_storage_setting($db, 'AIHUB_DOCKER_PORT_START') === '18100');
assert(hub_get_storage_setting($db, 'AIHUB_DOCKER_PORT_END') === '18999');
assert(hub_is_safe_absolute_path('/DATA/aihub_models') === true);
assert(hub_is_safe_absolute_path('/') === false);
assert(hub_is_safe_absolute_path('/etc') === false);
assert(hub_is_safe_absolute_path('/var/lib/docker') === false);
assert(hub_is_safe_absolute_path('relative/path') === false);
assert(hub_is_safe_absolute_path("/DATA/bad\0path") === false);
assert(hub_docker_root_warning('/var/lib/docker', 50 * 1024 * 1024 * 1024) !== '');
assert(hub_docker_root_warning('/DATA/docker', 50 * 1024 * 1024 * 1024) === '');
assert(hub_storage_settings_warnings(['AIHUB_MODELS_DIR' => HUB_DATA_DIR . '/models']) !== []);

$packs = hub_list_packs();
$helloPack = null;
foreach ($packs as $pack) {
    if (($pack['id'] ?? '') === 'hello') {
        $helloPack = $pack;
        break;
    }
}
assert($helloPack !== null);
assert($helloPack['status'] === 'ok');
assert($helloPack['manifest']['schema_version'] === '0.1');

$installed = hub_install_pack($db, 'hello', 'hello-main');
assert($installed['service']['service_key'] === 'hello-main');
assert($installed['service']['pack_id'] === 'hello');
assert($installed['service']['pack_version'] === '0.1.0');
assert($installed['service']['compose_file'] === 'data/services/hello-main/docker-compose.generated.yml');
assert($installed['service']['install_status'] === 'installed');
assert(is_dir(HUB_DATA_DIR . '/services/hello-main'));
assert(is_file(HUB_DATA_DIR . '/services/hello-main/.env'));
assert(is_file(HUB_DATA_DIR . '/services/hello-main/docker-compose.generated.yml'));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/hello-main/.env'), 'HELLO_LOCAL_PORT=18100'));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/hello-main/.env'), 'AIHUB_MODELS_DIR='));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/hello-main/docker-compose.generated.yml'), '127.0.0.1:${HELLO_LOCAL_PORT:-18100}:8000'));

hub_install_pack($db, 'hello', 'hello-main');
$stmt = $db->query("SELECT COUNT(*) FROM services WHERE service_key = 'hello-main'");
assert((int)$stmt->fetchColumn() === 1);

$user = $db->query("SELECT id, password_hash FROM users WHERE username = 'admin'")->fetch(PDO::FETCH_ASSOC);
assert($user !== false);
assert(password_verify('admin123', $user['password_hash']));
assert(hub_update_password($db, (int)$user['id'], 'wrong-password', 'changed123') === '目前密碼不正確。');
assert(hub_update_password($db, (int)$user['id'], 'admin123', 'changed123') === null);

$_SESSION = [];
$captchaCode = hub_login_captcha_code(true);
assert(preg_match('/^[A-Z0-9]{5}$/', $captchaCode) === 1);
assert(hub_verify_login_captcha('wrong') === false);

$captchaCode = hub_login_captcha_code();
assert(hub_verify_login_captcha('  ' . strtolower($captchaCode) . '  ') === true);
assert(hub_verify_login_captcha($captchaCode) === false);

$disabled = hub_gateway_dispatch($db, 'hello', static function (): array {
    return ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => '{"ok":true}'];
});
assert($disabled['status'] === 503);
assert(str_contains($disabled['body'], 'disabled'));

hub_set_service_enabled($db, 'hello', true);
$enabled = hub_gateway_dispatch($db, 'hello', static function (): array {
    return ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => '{"ok":true}'];
});
assert($enabled['status'] === 200);
assert($enabled['body'] === '{"ok":true}');

assert(hub_compose_status_from_ps("NAME SERVICE STATUS\nhello hello running\n") === 'running');
assert(hub_compose_status_from_ps('') === 'stopped');

$service = hub_get_service_by_mode($db, 'hello');
assert($service !== null);
assert((int)$service['local_port'] === 18100);
assert($service['port_mode'] === 'auto');
assert((int)$service['hot_reload'] === 0);
assert($service['environment'] === 'production');
assert(hub_is_valid_job_action('service_start'));
assert(!hub_is_valid_job_action('docker compose down && rm -rf /'));

$jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], ['reason' => 'self_check'], (int)$user['id'], '127.0.0.1');
$job = hub_get_command_job($db, $jobId);
assert($job !== null);
assert($job['status'] === 'queued');
assert($job['action'] === 'service_start');
assert($job['requested_ip'] === '127.0.0.1');

$claimed = hub_claim_next_command_job($db);
assert($claimed !== null);
assert((int)$claimed['id'] === $jobId);
assert($claimed['status'] === 'running');
assert($claimed['lock_token'] !== '');

hub_finish_command_job($db, $claimed, 'success', 0, 'ok', '');
$finished = hub_get_command_job($db, $jobId);
assert($finished['status'] === 'success');
assert((int)$finished['exit_code'] === 0);
assert(is_file($finished['stdout_path']));
assert(trim((string)file_get_contents($finished['stdout_path'])) === 'ok');

hub_save_env_snapshot($db, ['host' => ['hostname' => 'self-check']], 'ok', null);
$snapshot = hub_latest_env_snapshot($db);
assert($snapshot !== null);
assert($snapshot['data']['host']['hostname'] === 'self-check');

$metricTables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'host_metric_snapshots'")->fetchAll();
assert(count($metricTables) === 1);
$sampleMetric = [
    'gpu' => ['available' => false],
    'host' => ['load_1' => 0.1, 'ram_total_mb' => 100, 'ram_used_mb' => 50],
    'docker' => ['available' => false],
    'counts' => ['packs' => 1, 'services' => 1],
];
hub_save_host_metric_snapshot($db, $sampleMetric);
$latestMetric = hub_latest_host_metric_snapshot($db);
assert($latestMetric !== null);
assert($latestMetric['data']['host']['ram_used_mb'] === 50);
$collectedMetric = hub_collect_host_metrics($db);
assert(isset($collectedMetric['gpu'], $collectedMetric['host'], $collectedMetric['docker'], $collectedMetric['counts']));
assert(array_key_exists('available', $collectedMetric['gpu']));
assert(array_key_exists('ram_total_mb', $collectedMetric['host']));
assert(array_key_exists('ram_buff_cache_mb', $collectedMetric['host']));
assert(array_key_exists('ram_available_percent', $collectedMetric['host']));
assert(array_key_exists('swap_used_mb', $collectedMetric['host']));
assert(array_key_exists('vmstat_si', $collectedMetric['host']));
assert(array_key_exists('services', $collectedMetric['counts']));
assert(hub_parse_vmstat_swap_io("procs -----------memory---------- ---swap-- -----io---- -system-- ------cpu-----\nr b swpd free buff cache si so bi bo in cs us sy id wa st\n0 0 0 1 2 3 4 5 0 0 0 0 0 0 100 0 0\n") === ['si' => 4, 'so' => 5]);

$allocatedPort = hub_allocate_local_port($db);
assert($allocatedPort >= 18101 && $allocatedPort <= 18999);
assert(hub_validate_service_port(18100) === true);
assert(hub_validate_service_port(18099) === false);

$service = hub_get_service_by_mode($db, 'hello');
assert($service['execution_type'] === 'sync_api');
assert(hub_is_valid_task_type('demo_task'));
assert(!hub_is_valid_task_type('sam3'));

$lowTaskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'low'], null, '127.0.0.1');
$highTaskId = hub_enqueue_task($db, 'demo_task', 'default', 10, ['name' => 'high'], null, '127.0.0.1');
$claimedTask = hub_claim_next_task($db);
assert($claimedTask !== null);
assert((int)$claimedTask['id'] === $highTaskId);
assert($claimedTask['status'] === 'running');
assert($claimedTask['lock_token'] !== '');

hub_add_task_log($db, (int)$claimedTask['id'], 'info', 'demo started');
hub_finish_task_success($db, $claimedTask, ['ok' => true, 'message' => 'done']);
$finishedTask = hub_get_task($db, $highTaskId);
assert($finishedTask['status'] === 'success');
assert($finishedTask['result']['message'] === 'done');
assert(count(hub_list_task_logs($db, $highTaskId)) === 1);

assert(hub_cancel_task($db, $lowTaskId) === true);
assert(hub_get_task($db, $lowTaskId)['status'] === 'cancelled');
assert(hub_cancel_task($db, $highTaskId) === false);

$gpuRow = hub_parse_nvidia_gpu_row('NVIDIA GeForce RTX 5090, 595.71.05, 32607, 1024, 31583, 7, 42');
assert($gpuRow['name'] === 'NVIDIA GeForce RTX 5090');
assert($gpuRow['driver_version'] === '595.71.05');
assert($gpuRow['vram_total_mb'] === '32607');
assert($gpuRow['utilization_percent'] === '7');
assert($gpuRow['temperature_c'] === '42');

$cron = hub_parse_command_worker_cron("* * * * * root cd /DATA/3waAIHub && /DATA/3waAIHub/crontab/1min.sh >> /DATA/3waAIHub/data/logs/command_worker_1min.log 2>&1\n");
assert($cron['installed'] === true);
assert($cron['user'] === 'root');
assert($cron['line'] !== '');
$cronMissing = hub_parse_command_worker_cron("# no worker here\n");
assert($cronMissing['installed'] === false);
assert($cronMissing['user'] === '');

$catalog = hub_load_pack_catalog();
assert($catalog['schema_version'] === '0.1');
assert(count($catalog['packs']) >= 2);
$catalogPacks = hub_list_catalog_packs();
$catalogIds = array_column($catalogPacks, 'id');
assert(in_array('ocr-ppocrv5', $catalogIds, true));
assert(in_array('translate-gemma12b', $catalogIds, true));
assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/Dockerfile'));
assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/requirements.txt'));
assert(is_file(HUB_ROOT . '/packs/ocr-ppocrv5/service/app.py'));

$ocr = hub_install_pack($db, 'ocr-ppocrv5', [
    'service_key' => 'ocr-main',
    'name' => 'PP-OCRv5 OCR Main',
    'mode' => 'ocr',
    'port_mode' => 'auto',
    'environment' => 'production',
]);
assert($ocr['service']['service_key'] === 'ocr-main');
assert($ocr['service']['mode'] === 'ocr');
assert($ocr['service']['pack_id'] === 'ocr-ppocrv5');
assert(is_dir(HUB_DATA_DIR . '/services/ocr-main'));
assert(is_file(HUB_DATA_DIR . '/services/ocr-main/.env'));
assert(is_file(HUB_DATA_DIR . '/services/ocr-main/docker-compose.generated.yml'));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/ocr-main/.env'), 'OCR_MOCK_TEXT=3waAIHub OCR mock'));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/ocr-main/docker-compose.generated.yml'), 'env_file:'));
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/ocr-main/docker-compose.generated.yml'), '127.0.0.1:${OCR_LOCAL_PORT:-18101}:8000'));

$ocrGpu = hub_install_pack($db, 'ocr-ppocrv5', [
    'service_key' => 'ocr-gpu',
    'name' => 'PP-OCRv5 OCR GPU',
    'mode' => 'ocr_gpu',
    'port_mode' => 'manual',
    'local_port' => 18103,
    'environment' => 'production',
]);
assert($ocrGpu['service']['service_key'] === 'ocr-gpu');
assert($ocrGpu['service']['mode'] === 'ocr_gpu');
assert((int)$ocrGpu['service']['local_port'] === 18103);
assert(is_file(HUB_DATA_DIR . '/services/ocr-gpu/.env'));
assert(is_file(HUB_DATA_DIR . '/services/ocr-gpu/docker-compose.generated.yml'));

$uploadTmp = tempnam(sys_get_temp_dir(), '3waaihub_upload_');
file_put_contents($uploadTmp, 'image-bytes');
$proxyFields = hub_proxy_post_fields(
    ['note' => 'ocr smoke'],
    ['image' => ['tmp_name' => $uploadTmp, 'name' => 'sample.png', 'type' => 'image/png', 'error' => UPLOAD_ERR_OK]]
);
assert($proxyFields['note'] === 'ocr smoke');
assert($proxyFields['image'] instanceof CURLFile);
unlink($uploadTmp);

$translate = hub_install_pack($db, 'translate-gemma12b', [
    'service_key' => 'translate-main',
    'name' => 'TranslateGemma Main',
    'mode' => 'translate',
    'port_mode' => 'auto',
    'environment' => 'production',
]);
assert($translate['service']['service_key'] === 'translate-main');
assert($translate['service']['mode'] === 'translate');
assert($translate['service']['pack_id'] === 'translate-gemma12b');
assert(str_contains((string)file_get_contents(HUB_DATA_DIR . '/services/translate-main/.env'), 'OLLAMA_MODEL=translategemma:12b-it-q4_K_M'));
assert(is_file(HUB_DATA_DIR . '/services/translate-main/docker-compose.generated.yml'));

assert(hub_self_check_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
    'service_key' => 'ocr-main',
    'name' => 'Duplicate Key',
    'mode' => 'ocr_dup_key',
    'port_mode' => 'auto',
    'environment' => 'production',
])));
assert(hub_self_check_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
    'service_key' => 'ocr-new',
    'name' => 'Duplicate Mode',
    'mode' => 'ocr',
    'port_mode' => 'auto',
    'environment' => 'production',
])));
assert(hub_self_check_throws(static fn () => hub_install_pack($db, 'ocr-ppocrv5', [
    'service_key' => 'ocr-port',
    'name' => 'Duplicate Port',
    'mode' => 'ocr_port',
    'port_mode' => 'manual',
    'local_port' => 18103,
    'environment' => 'production',
])));
assert((int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'ocr-main'")->fetchColumn() === 1);
assert((int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'ocr-gpu'")->fetchColumn() === 1);
assert((int)$db->query("SELECT COUNT(*) FROM services WHERE service_key = 'translate-main'")->fetchColumn() === 1);

$artifactDir = hub_task_result_dir($highTaskId);
if (!is_dir($artifactDir)) {
    mkdir($artifactDir, 0775, true);
}
$artifactPath = $artifactDir . '/result_' . bin2hex(random_bytes(4)) . '.txt';
file_put_contents($artifactPath, 'artifact ok');
$artifactId = hub_register_task_artifact($db, $highTaskId, 'result.txt', $artifactPath, 'text/plain');
$artifact = hub_get_task_artifact($db, $artifactId);
assert($artifact !== null);
assert(hub_artifact_safe_path($artifact['path']) === $artifactPath);
assert(hub_artifact_safe_path(HUB_ROOT . '/README.md') === null);
unlink($artifactPath);

unlink($tmp);

if (is_file(HUB_DB_PATH)) {
    $runtimeDb = hub_db();
    hub_migrate($runtimeDb);
    hub_ensure_default_storage_settings($runtimeDb);
    foreach (hub_storage_settings_warnings(hub_get_storage_paths($runtimeDb)) as $warning) {
        echo "WARNING: {$warning}\n";
        echo "Manual migration:\n";
        echo "  sudo mkdir -p /DATA/models\n";
        echo "  sudo rsync -aHAX " . HUB_DATA_DIR . "/models/ /DATA/models/\n";
        echo "Then update AIHUB_MODELS_DIR=/DATA/models in Settings.\n";
    }
}

echo "self_check ok\n";

function hub_self_check_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable) {
        return true;
    }

    return false;
}
