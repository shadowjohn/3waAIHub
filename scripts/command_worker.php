<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$limit = 5;
$runtime = 'all';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
        continue;
    }
    if (str_starts_with($arg, '--runtime=')) {
        $runtime = substr($arg, 10);
    }
}
if (!in_array($runtime, ['all', 'core', 'wsl'], true)) {
    fwrite(STDERR, 'runtime must be all, core, or wsl.' . PHP_EOL);
    exit(64);
}

$db = hub_db();
$missing = hub_runtime_schema_missing($db);
if ($missing !== []) {
    fwrite(STDERR, 'schema_upgrade_required: ' . implode(', ', $missing) . '. Run php scripts/init_db.php.' . PHP_EOL);
    exit(1);
}

hub_retry_pending_service_runtime_cleanup($db);
$recovered = hub_recover_stale_command_jobs($db);
if ($recovered > 0) {
    echo 'recovered stale command jobs=' . $recovered . PHP_EOL;
}

$processed = 0;
while ($processed < $limit) {
    $job = hub_claim_next_command_job($db, static function (array $candidate) use ($db, $runtime): bool {
        if ($runtime === 'all' || $candidate['service_id'] === null) {
            return $runtime !== 'wsl';
        }
        $service = hub_get_service($db, (int)$candidate['service_id']);
        $usesWsl = $service !== null && (hub_service_runtime_resolution($service)['target'] ?? '') === 'windows-wsl2-linux-docker';

        return $runtime === 'wsl' ? $usesWsl : !$usesWsl;
    });
    if (!$job) {
        break;
    }

    try {
        $result = hub_execute_command_job($db, $job);
    } catch (Throwable $e) {
        $result = [
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Command execution failed: ' . $e->getMessage(),
            'message' => 'Command execution failed: ' . $e->getMessage(),
            'error_code' => 'command_execution_failed',
        ];
    }
    hub_finish_command_job(
        $db,
        $job,
        $result['exit_code'] === 0 ? 'success' : 'failed',
        (int)$result['exit_code'],
        (string)($result['stdout'] ?? ''),
        (string)($result['stderr'] ?? ''),
        $result['exit_code'] === 0 ? null : (string)($result['message'] ?? $result['stderr'] ?? $result['output'] ?? 'Command failed.'),
        isset($result['error_code']) ? (string)$result['error_code'] : null
    );
    if (($result['error_code'] ?? null) === 'platform_target_unsupported') {
        fwrite(STDERR, (string)$result['stderr'] . PHP_EOL);
    }
    hub_audit($db, 'command_worker', 'job_' . $job['action'], 'job_id=' . $job['id'] . ' exit=' . $result['exit_code']);
    echo hub_command_worker_output_line($job, (int)$result['exit_code']) . PHP_EOL;
    $processed++;
}

function hub_execute_command_job(PDO $db, array $job): array
{
    $action = (string)$job['action'];
    if (!hub_is_valid_job_action($action)) {
        return ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Invalid command action.'];
    }

    $service = null;
    if ($job['service_id'] !== null) {
        $service = hub_get_service($db, (int)$job['service_id']);
        if (!$service) {
            return ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Service not found.'];
        }
    }
    if (str_starts_with($action, 'service_') && !$service) {
        return ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Service id is required.'];
    }

    $requiresLinuxDocker = in_array($action, ['permissions_fix', 'docker_prune_check', 'docker_builder_prune', 'ollama_model_pull'], true)
        || (
            $service !== null
            && !hub_service_is_internal_task($service)
            && $action !== 'service_remove'
            && str_starts_with($action, 'service_')
            && hub_service_runtime_resolution($service)['target'] === 'linux-docker'
        );
    if ($requiresLinuxDocker) {
        $unsupported = hub_linux_docker_unsupported_result();
        if ($unsupported !== null) {
            return $unsupported;
        }
    }

    return match ($action) {
        'service_start', 'service_install' => hub_start_service_with_job($db, $service, $job),
        'service_build' => hub_build_service($db, $service, $job),
        'service_stop' => hub_stop_service($db, $service, $job),
        'service_remove' => hub_remove_service($db, $service, $job),
        'service_restart' => hub_restart_service($db, $service, $job),
        'service_rebuild' => hub_build_service($db, $service, $job),
        'service_logs_collect' => hub_tail_service_logs($db, $service),
        'service_health_check' => ['exit_code' => 0, 'stdout' => 'status=' . hub_refresh_service_status($db, $service), 'stderr' => ''],
        'env_probe' => hub_run_env_probe_job($db),
        'permissions_fix' => hub_run_command(['bash', HUB_ROOT . '/scripts/fix_permissions.sh'], 60),
        'docker_prune_check' => hub_run_command(['docker', 'system', 'df'], 30),
        'docker_builder_prune' => hub_run_command(['docker', 'builder', 'prune', '-af'], 900),
        'ollama_model_pull' => hub_run_ollama_model_pull_job($db, $service, $job),
        'benchmark_run' => ['exit_code' => 4, 'stdout' => '', 'stderr' => 'benchmark_run is not implemented in PhaseB local hardening.'],
        default => ['exit_code' => 2, 'stdout' => '', 'stderr' => 'Unhandled action.'],
    };
}

function hub_run_ollama_model_pull_job(PDO $db, ?array $service, array $job): array
{
    $unsupported = hub_linux_docker_unsupported_result();
    if ($unsupported !== null) {
        return $unsupported;
    }

    hub_job_progress($db, $job, 'checking_service', 5, 'Checking TranslateGemma service.');
    if (!$service) {
        return ['exit_code' => 3, 'stdout' => '', 'stderr' => 'Service id is required.'];
    }
    if ((string)($service['pack_id'] ?? '') !== 'translate-gemma12b') {
        return ['exit_code' => 3, 'stdout' => '', 'stderr' => 'pack_not_supported'];
    }

    $args = json_decode((string)($job['args_json'] ?? '{}'), true);
    $model = trim((string)($args['model'] ?? ''));
    $command = ['php', HUB_ROOT . '/scripts/ollama_model_pull.php', '--service=' . (string)$service['service_key']];
    if ($model !== '') {
        try {
            $command[] = '--model=' . hub_ollama_model_reference($model);
        } catch (InvalidArgumentException) {
            return ['exit_code' => 2, 'stdout' => '', 'stderr' => 'invalid_model_reference'];
        }
    }

    hub_job_progress($db, $job, 'checking_ollama', 10, 'Checking Ollama container.');
    hub_job_progress($db, $job, 'pulling_model', 20, 'Pulling Ollama model.');
    $result = hub_run_service_command($db, $job, $command, 14400, [], 'pulling_model', 20, 85);
    if ((int)$result['exit_code'] === 0) {
        hub_job_progress($db, $job, 'verifying_model', 90, 'Ollama model present.');
    }

    return $result;
}

function hub_run_env_probe_job(PDO $db): array
{
    try {
        $snapshot = hub_collect_env_snapshot();
        hub_save_env_snapshot($db, $snapshot, 'ok', null);
        return ['exit_code' => 0, 'stdout' => json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'stderr' => ''];
    } catch (Throwable $e) {
        hub_save_env_snapshot($db, [], 'error', $e->getMessage());
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => $e->getMessage()];
    }
}
