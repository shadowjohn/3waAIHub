<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$db = hub_db();
hub_migrate($db);

$processed = 0;
while ($processed < $limit) {
    $job = hub_claim_next_command_job($db);
    if (!$job) {
        break;
    }

    $result = hub_execute_command_job($db, $job);
    hub_finish_command_job(
        $db,
        $job,
        $result['exit_code'] === 0 ? 'success' : 'failed',
        (int)$result['exit_code'],
        (string)($result['stdout'] ?? ''),
        (string)($result['stderr'] ?? ''),
        $result['exit_code'] === 0 ? null : (string)($result['stderr'] ?? $result['output'] ?? 'Command failed.')
    );
    hub_audit($db, 'command_worker', 'job_' . $job['action'], 'job_id=' . $job['id'] . ' exit=' . $result['exit_code']);
    echo 'job ' . $job['id'] . ' ' . $job['action'] . ' exit=' . $result['exit_code'] . PHP_EOL;
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

    return match ($action) {
        'service_start', 'service_install' => hub_start_service_with_job($db, $service, $job),
        'service_build' => hub_build_service($db, $service, $job),
        'service_stop' => hub_stop_service($db, $service),
        'service_restart' => hub_restart_service($db, $service),
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
        $command[] = '--model=' . $model;
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
