<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$options = getopt('', ['service:', 'model::']);
$serviceArg = trim((string)($options['service'] ?? ''));
if ($serviceArg === '') {
    fwrite(STDERR, "service_not_found: --service is required\n");
    exit(2);
}

$db = hub_db();
hub_migrate($db);
$service = ctype_digit($serviceArg) ? hub_get_service($db, (int)$serviceArg) : hub_get_service_by_key($db, $serviceArg);
if (!$service) {
    fwrite(STDERR, "service_not_found: {$serviceArg}\n");
    exit(2);
}

if ((string)($service['pack_id'] ?? '') !== 'translate-gemma12b') {
    fwrite(STDERR, "pack_not_supported: " . (string)($service['pack_id'] ?? '') . "\n");
    exit(3);
}

$composePath = hub_path((string)$service['compose_file']);
if (!is_file($composePath)) {
    fwrite(STDERR, "compose_not_found: {$composePath}\n");
    exit(4);
}

$model = trim((string)($options['model'] ?? ''));
if ($model === '') {
    $settings = hub_ensure_service_settings($db, $service);
    $schema = hub_get_pack_settings_schema('translate-gemma12b');
    $model = trim((string)($settings['OLLAMA_MODEL']['value'] ?? $schema['OLLAMA_MODEL']['default'] ?? 'translategemma:12b-it-q4_K_M'));
}
if ($model === '' || preg_match('/\s/', $model)) {
    fwrite(STDERR, "model_empty\n");
    exit(5);
}

$logDir = HUB_LOG_DIR . '/models';
if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    fwrite(STDERR, "log_dir_failed: {$logDir}\n");
    exit(6);
}
$safeServiceKey = preg_replace('/[^A-Za-z0-9_.-]/', '_', (string)$service['service_key']) ?: 'service';
$logPath = $logDir . '/ollama_pull_' . $safeServiceKey . '_' . date('Ymd_His') . '.log';
file_put_contents($logPath, "service={$service['service_key']}\nmodel={$model}\n");

$env = hub_compose_env($service);
$check = hub_run_command(hub_compose_command($service, ['exec', '-T', 'ollama', 'ollama', 'list']), 60, $env);
if ((int)$check['exit_code'] !== 0) {
    file_put_contents($logPath, "\n[ollama list]\n" . (string)$check['output'] . "\n", FILE_APPEND);
    fwrite(STDERR, "ollama_not_running\nlog={$logPath}\n" . (string)$check['output'] . "\n");
    exit(6);
}

$command = hub_compose_command($service, ['exec', '-T', 'ollama', 'ollama', 'pull', $model]);
file_put_contents($logPath, "\n[ollama pull]\n", FILE_APPEND);
$pull = hub_run_command_streamed($command, 14400, $env, $logPath, $logPath);
if ((int)$pull['exit_code'] !== 0) {
    fwrite(STDERR, "pull_failed\nlog={$logPath}\n" . (string)$pull['output'] . "\n");
    exit((int)$pull['exit_code']);
}

$verify = hub_run_command(hub_compose_command($service, ['exec', '-T', 'ollama', 'ollama', 'list']), 60, $env);
file_put_contents($logPath, "\n[ollama list]\n" . (string)$verify['output'] . "\n", FILE_APPEND);
if ((int)$verify['exit_code'] !== 0 || !str_contains((string)$verify['stdout'], $model)) {
    fwrite(STDERR, "model_not_present_after_pull\nlog={$logPath}\n" . (string)$verify['output'] . "\n");
    exit(7);
}

echo "model_present={$model}\n";
echo "log={$logPath}\n";
