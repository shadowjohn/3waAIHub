<?php
declare(strict_types=1);

function hub_test_source_calls_migrate(string $source): bool
{
    $tokens = token_get_all($source);
    foreach ($tokens as $index => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'hub_migrate') {
            continue;
        }
        for ($next = $index + 1; isset($tokens[$next]); $next++) {
            if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                continue;
            }
            if ($tokens[$next] === '(') {
                return true;
            }
            break;
        }
    }

    return false;
}

hub_test('job-first schema migration is idempotent and operational entry points stay read-only', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, ['name' => 'legacy'], null, '127.0.0.1');

    hub_migrate($db);
    hub_migrate($db);

    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM tasks WHERE id = ' . $taskId)->fetchColumn() === 1, 'legacy task must survive repeated migration');

    foreach (['task_callback_targets', 'task_callback_deliveries', 'runtime_resource_leases'] as $table) {
        hub_test_assert((bool)$db->query('SELECT 1 FROM sqlite_master WHERE type = ' . $db->quote('table') . ' AND name = ' . $db->quote($table))->fetchColumn(), "{$table} must exist");
    }

    $requiredColumns = [
        'tasks' => ['owner_member_id', 'owner_token_id', 'requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at', 'source_artifact_id', 'source_task_id', 'retry_of_task_id', 'callback_target_id', 'waiting_reason', 'next_attempt_at', 'error_code', 'source_expires_at', 'workspace_expires_at', 'source_state', 'workspace_state', 'retention_state', 'purged_at', 'freed_bytes'],
        'task_artifacts' => ['artifact_type', 'sha256', 'expires_at', 'state', 'pinned_at', 'legal_hold', 'acknowledged_at', 'last_accessed_at', 'purged_at', 'purge_error'],
        'runtime_runs' => ['task_id', 'attempt_no', 'gpu_process_baseline_json', 'owned_gpu_pids_json'],
    ];
    foreach ($requiredColumns as $table => $columns) {
        $present = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
        foreach ($columns as $column) {
            hub_test_assert(in_array($column, $present, true), "{$table}.{$column} must exist");
        }
    }

    hub_test_assert(hub_runtime_schema_missing($db) === [], 'migrated database must have the runtime schema');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM runtime_resource_leases WHERE resource_key = 'gpu:0/available' AND state = 'available'")->fetchColumn() === 1, 'gpu:0 available lease must be seeded once');

    foreach ([
        'AIHUB_SOURCE_RETENTION_DAYS' => '7',
        'AIHUB_WORKSPACE_RETENTION_HOURS' => '24',
        'AIHUB_ARTIFACT_RETENTION_DAYS' => '30',
        'AIHUB_PARTIAL_RETENTION_HOURS' => '1',
        'AIHUB_TASK_RETENTION_DAYS' => '180',
        'AIHUB_GPU_VRAM_SAFETY_MARGIN_MB' => '256',
        'AIHUB_RUNTIME_MAX_ATTEMPTS' => '2',
        'AIHUB_CALLBACK_ALLOWED_HOSTS' => '127.0.0.1,localhost',
        'AIHUB_CALLBACK_ALLOW_LOOPBACK_HTTP' => '1',
        'AIHUB_SYNC_MAX_AUDIO_SECONDS' => '30',
        'AIHUB_SYNC_CONCURRENCY' => '1',
    ] as $key => $value) {
        hub_test_assert((string)(hub_default_storage_settings()[$key] ?? '') === $value, "{$key} default mismatch");
    }

    foreach (['scripts/task_worker.php', 'scripts/command_worker.php', 'scripts/self_check.php', 'scripts/prune_db.php', 'bin/aihub-run'] as $path) {
        hub_test_assert(!hub_test_source_calls_migrate((string)file_get_contents(HUB_ROOT . '/' . $path)), "{$path} must not run migrations");
    }
});
