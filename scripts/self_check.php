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

assert(hub_allocate_local_port($db) === 18101);
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
echo "self_check ok\n";
