<?php
declare(strict_types=1);

if (!function_exists('hub_test_audio_isolate') || !function_exists('hub_test_pack_job_create_terminal_fixture')) {
    $registeredTests = $tests;
    require_once __DIR__ . '/test_audio_task_gateway.php';
    require_once __DIR__ . '/test_pack_job_artifacts.php';
    $tests = $registeredTests;
}

hub_test('retention policy fixes formal periods and clamps failed-source retention', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_FAILED_SOURCE_RETENTION_DAYS', '999');

    $policy = hub_retention_policy($db);

    hub_test_assert($policy['partial_hours'] === 1 && $policy['workspace_hours'] === 24, 'partial and workspace retention must be fixed');
    hub_test_assert($policy['completed_source_days'] === 7 && $policy['artifact_days'] === 30 && $policy['metadata_days'] === 180, 'formal retention periods must be fixed');
    hub_test_assert($policy['failed_source_days'] === 7, 'failed source retention must be capped at seven days');
});

hub_test('task lifecycle records explicit retention deadlines and terminal states', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $created = hub_get_task($db, $taskId);

    hub_test_assert(!empty($created['source_expires_at']) && !empty($created['workspace_expires_at']), 'new task must have explicit source and workspace deadlines');
    hub_test_assert(($created['source_state'] ?? '') === 'active' && ($created['workspace_state'] ?? '') === 'active' && ($created['retention_state'] ?? '') === 'active', 'new task retention must start active');

    hub_finish_task_success($db, $created, ['ok' => true]);
    $terminal = hub_get_task($db, $taskId);
    hub_test_assert(($terminal['source_state'] ?? '') === 'retention' && ($terminal['workspace_state'] ?? '') === 'retention' && ($terminal['retention_state'] ?? '') === 'retention', 'terminal task must enter retention');
    hub_test_assert(strtotime((string)$terminal['source_expires_at']) >= strtotime((string)$terminal['finished_at']) + 6 * 86400, 'completed source must retain for seven days');
    hub_test_assert(strtotime((string)$terminal['workspace_expires_at']) >= strtotime((string)$terminal['finished_at']) + 23 * 3600, 'terminal workspace must retain for twenty-four hours');
});

hub_test('registered artifact expires at policy deadline and owner ACK keeps twenty-four hours', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'retention owner');
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', ['owner_member_id' => $memberId]);
    $path = hub_task_result_dir($taskId) . '/ack.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create artifact fixture.');
    }
    file_put_contents($path, 'ack', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'ack.txt', $path, 'text/plain');
    $artifact = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(strtotime((string)$artifact['expires_at']) >= strtotime((string)$artifact['created_at']) + 29 * 86400, 'registered artifacts must receive thirty-day expiry');

    hub_ack_task_artifact($db, $memberId, $taskId, $artifactId);
    $acknowledged = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(!empty($acknowledged['acknowledged_at']), 'ACK must be recorded');
    hub_test_assert(strtotime((string)$acknowledged['expires_at']) >= strtotime((string)$acknowledged['acknowledged_at']) + 23 * 3600, 'ACK must never shorten below twenty-four hours');
});

hub_test('retention prune deletes only due managed artifacts and keeps metadata', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $task = hub_get_task($db, $taskId);
    hub_finish_task_success($db, $task, ['ok' => true]);
    $path = hub_task_result_dir($taskId) . '/due.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create due artifact fixture.');
    }
    file_put_contents($path, 'delete me', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'due.txt', $path, 'text/plain');
    $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);

    $result = hub_prune_retention($db, '2026-07-20 00:00:00');
    $artifact = hub_get_task_artifact($db, $artifactId);
    $after = hub_get_task($db, $taskId);
    hub_test_assert(($result['purged'] ?? 0) === 1 && !file_exists($path), 'due artifact file must be removed once');
    hub_test_assert(($artifact['state'] ?? '') === 'purged' && !empty($artifact['purged_at']), 'artifact metadata must survive as purged');
    hub_test_assert($after !== null && (int)$after['freed_bytes'] >= 9, 'task metadata must remain with freed bytes');
});

hub_test('retention prune removes due managed source workspace and abandoned partial only', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $source = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/input.wav';
    $workspace = hub_task_result_dir($taskId) . '/workspace';
    if ((!is_dir(dirname($source)) && !mkdir(dirname($source), 0775, true)) || (!is_dir($workspace . '/output') && !mkdir($workspace . '/output', 0775, true))) {
        throw new RuntimeException('Cannot create managed source/workspace fixture.');
    }
    file_put_contents($source, 'source', LOCK_EX);
    file_put_contents($workspace . '/output/result.txt', 'workspace', LOCK_EX);
    hub_update_task_input($db, $taskId, ['source_upload_path' => $source]);
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $db->prepare("UPDATE tasks SET source_expires_at = '2000-01-01 00:00:00', workspace_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $taskId]);

    $partial = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/abandoned.partial';
    file_put_contents($partial, 'partial', LOCK_EX);
    touch($partial, strtotime('2000-01-01 00:00:00'));
    hub_prune_retention($db, '2026-07-20 00:00:00');
    $after = hub_get_task($db, $taskId);

    hub_test_assert(!file_exists($source) && !file_exists($workspace) && !file_exists($partial), 'only due managed task resources must be removed');
    hub_test_assert(($after['source_state'] ?? '') === 'purged' && ($after['workspace_state'] ?? '') === 'purged' && !empty($after['purged_at']), 'task resource metadata must record purge');
});

hub_test('partial retention purges a failed terminal upload after one hour without waiting for resource expiry', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_failed($db, hub_get_task($db, $taskId), 'upload failed');
    $now = hub_now();
    $finishedAt = hub_retention_deadline(-7200, $now);
    $db->prepare('UPDATE tasks SET finished_at = :finished_at, updated_at = :updated_at WHERE id = :id')
        ->execute([':finished_at' => $finishedAt, ':updated_at' => $finishedAt, ':id' => $taskId]);
    $partial = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/failed-upload.partial';
    if (!is_dir(dirname($partial)) && !mkdir(dirname($partial), 0775, true)) {
        throw new RuntimeException('Cannot create terminal partial fixture.');
    }
    file_put_contents($partial, 'partial', LOCK_EX);
    touch($partial, strtotime($finishedAt));

    hub_prune_retention($db, $now);
    $after = hub_get_task($db, $taskId);

    hub_test_assert(!file_exists($partial), 'a managed failed-task partial older than one hour must be removed before source or workspace retention expires');
    hub_test_assert(($after['source_state'] ?? '') === 'retention' && ($after['workspace_state'] ?? '') === 'retention', 'partial cleanup must not wait for or alter independent task resource retention');
});

hub_test('partial retention retries a failed deletion after source and workspace are purged', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_failed($db, hub_get_task($db, $taskId), 'upload failed');
    $now = hub_now();
    $old = hub_retention_deadline(-7200, $now);
    $db->prepare(
        "UPDATE tasks
         SET finished_at = :old, updated_at = :old,
             source_expires_at = '2000-01-01 00:00:00', workspace_expires_at = '2000-01-01 00:00:00'
         WHERE id = :id"
    )->execute([':old' => $old, ':id' => $taskId]);
    $partial = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId . '/retry-after-resource-purge.partial';
    $root = dirname($partial);
    if (!is_dir($root) && !mkdir($root, 0775, true)) {
        throw new RuntimeException('Cannot create partial retry fixture.');
    }
    file_put_contents($partial, 'partial', LOCK_EX);
    touch($partial, strtotime($old));
    if (!chmod($root, 0555)) {
        throw new RuntimeException('Cannot make partial directory read-only.');
    }
    try {
        hub_prune_retention($db, $now);
        $failed = hub_get_task($db, $taskId);
        hub_test_assert(file_exists($partial) && !empty($failed['partial_purge_error'])
            && ($failed['source_state'] ?? '') === 'purged' && ($failed['workspace_state'] ?? '') === 'purged', 'a failed partial deletion must retain its retry marker after independent resource cleanup');
    } finally {
        chmod($root, 0775);
    }

    hub_prune_retention($db, $now);
    $retried = hub_get_task($db, $taskId);
    hub_test_assert(!file_exists($partial) && empty($retried['partial_purge_error']), 'a managed partial retry must reselect the exact stale path after source and workspace reach purged');
});

hub_test('artifact ACK API accepts owned task and artifact identifiers only', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'ack api owner');
        $token = hub_create_api_token($db, $memberId, 'ack api token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'task_artifacts_ack', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', ['owner_member_id' => $memberId]);
        $path = hub_task_result_dir($taskId) . '/api-ack.txt';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
            throw new RuntimeException('Cannot create API ACK fixture.');
        }
        file_put_contents($path, 'ack', LOCK_EX);
        $artifactId = hub_register_task_artifact($db, $taskId, 'api-ack.txt', $path, 'text/plain');

        $response = hub_test_audio_request($db, 'task_artifacts_ack', (string)$token['plain_token'], ['task_id' => (string)$taskId, 'artifact_id' => (string)$artifactId]);
        $payload = hub_test_audio_payload($response);
        hub_test_assert($response['status'] === 200 && !empty($payload['acknowledged_at']), 'owned artifact ACK must be accepted');
    });
});

hub_test('artifact download records access and blocks prune while stream lease is active', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'download owner');
        $token = hub_create_api_token($db, $memberId, 'download token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'artifact', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', ['owner_member_id' => $memberId]);
        hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
        $path = hub_task_result_dir($taskId) . '/stream.txt';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
            throw new RuntimeException('Cannot create stream fixture.');
        }
        file_put_contents($path, 'stream', LOCK_EX);
        $artifactId = hub_register_task_artifact($db, $taskId, 'stream.txt', $path, 'text/plain');
        $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);

        $response = hub_test_audio_request($db, 'artifact', (string)$token['plain_token'], [], ['artifact_id' => (string)$artifactId], [], 'GET', false);
        $artifact = hub_get_task_artifact($db, $artifactId);
        hub_prune_retention($db, '2026-07-20 00:00:00');
        hub_test_assert($response['status'] === 200 && !empty($artifact['last_accessed_at']) && !empty($artifact['download_claim_expires_at']), 'download must claim a short stream lease');
        hub_test_assert(file_exists($path) && (hub_get_task_artifact($db, $artifactId)['state'] ?? '') === 'available', 'prune must not delete an active stream');
    });
});

hub_test('retention cron worker refuses missing schema without migrating', function (): void {
    $db = hub_test_reset_db();
    hub_test_assert(hub_retention_schema_missing($db) === [], 'migrated retention schema must be complete');
    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/prune_retention.php');
    hub_test_assert(!hub_test_source_calls_migrate($worker), 'retention worker must stay read-only for schema');
    hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/crontab/1min.sh'), 'scripts/prune_retention.php'), 'cron must invoke the retention worker');
});

hub_test('retention guards holds pins running tasks and rejects path escapes', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $path = hub_task_result_dir($taskId) . '/guard.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create retention guard fixture.');
    }
    file_put_contents($path, 'guard', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'guard.txt', $path, 'text/plain');
    $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00', pinned_at = '2026-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);
    hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert(file_exists($path), 'pinned artifacts must never be purged');
    $db->prepare('UPDATE task_artifacts SET pinned_at = NULL, legal_hold = 1 WHERE id = :id')->execute([':id' => $artifactId]);
    hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert(file_exists($path), 'legal-hold artifacts must never be purged');
    $db->prepare('UPDATE task_artifacts SET legal_hold = 0 WHERE id = :id')->execute([':id' => $artifactId]);
    $downstream = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $db->prepare('INSERT INTO task_artifact_holds (source_artifact_id, downstream_task_id, held_at) VALUES (:source, :downstream, :held_at)')->execute([':source' => $artifactId, ':downstream' => $downstream, ':held_at' => hub_now()]);
    hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert(file_exists($path), 'active source holds must block purge');
    $db->prepare('UPDATE task_artifact_holds SET released_at = :now WHERE source_artifact_id = :id')->execute([':now' => hub_now(), ':id' => $artifactId]);
    $db->prepare("UPDATE tasks SET status = 'running' WHERE id = :id")->execute([':id' => $taskId]);
    hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert(file_exists($path), 'running task must block purge');
    $db->prepare("UPDATE tasks SET status = 'success' WHERE id = :id")->execute([':id' => $taskId]);
    $first = hub_prune_retention($db, '2026-07-20 00:00:00');
    $second = hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert(($first['purged'] ?? 0) === 1 && ($second['purged'] ?? 0) === 0, 'claim transition must prevent a double purge');

    $outside = tempnam(sys_get_temp_dir(), '3waaihub_retention_escape_');
    if ($outside === false) {
        throw new RuntimeException('Cannot create path escape fixture.');
    }
    file_put_contents($outside, 'outside', LOCK_EX);
    try {
        $db->prepare("INSERT INTO task_artifacts (task_id, name, path, mime_type, size_bytes, expires_at, state, created_at) VALUES (:task_id, 'escape', :path, 'text/plain', 7, '2000-01-01 00:00:00', 'available', :created_at)")
            ->execute([':task_id' => $taskId, ':path' => $outside, ':created_at' => hub_now()]);
        $escapeId = (int)$db->lastInsertId();
        hub_prune_retention($db, '2026-07-20 00:00:00');
        $escape = hub_get_task_artifact($db, $escapeId);
        hub_test_assert(file_exists($outside) && ($escape['purge_error'] ?? '') === 'path_rejected', 'escaped artifact path must not be deleted');
    } finally {
        if (is_file($outside)) {
            unlink($outside);
        }
    }
});

hub_test('retention deletion failure records retryable error without false purge', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $dir = hub_task_result_dir($taskId) . '/retryable';
    if (!mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create retry fixture.');
    }
    $outside = tempnam(sys_get_temp_dir(), '3waaihub_retention_link_');
    if ($outside === false || !symlink($outside, $dir . '/escape')) {
        throw new RuntimeException('Cannot create retry symlink fixture.');
    }
    try {
        $db->prepare("INSERT INTO task_artifacts (task_id, name, path, mime_type, size_bytes, expires_at, state, created_at) VALUES (:task_id, 'retry', :path, 'application/octet-stream', 0, '2000-01-01 00:00:00', 'available', :created_at)")
            ->execute([':task_id' => $taskId, ':path' => $dir, ':created_at' => hub_now()]);
        $artifactId = (int)$db->lastInsertId();
        hub_prune_retention($db, '2026-07-20 00:00:00');
        $failed = hub_get_task_artifact($db, $artifactId);
        hub_test_assert(($failed['state'] ?? '') === 'available' && ($failed['purge_error'] ?? '') === 'path_rejected', 'failed deletion must stay retryable and unpurged');
        unlink($dir . '/escape');
        hub_prune_retention($db, '2026-07-20 00:00:00');
        hub_test_assert((hub_get_task_artifact($db, $artifactId)['state'] ?? '') === 'purged', 'next prune must retry a recovered deletion');
    } finally {
        if (is_file($outside)) {
            unlink($outside);
        }
    }
});

hub_test('retention controls only a trusted helper can pin or legal-hold an artifact', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $path = hub_task_result_dir($taskId) . '/protected.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create protected artifact fixture.');
    }
    file_put_contents($path, 'protected', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'protected.txt', $path, 'text/plain');

    hub_set_task_artifact_retention_protection($db, $artifactId, true, true);
    $protected = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(!empty($protected['pinned_at']) && (int)$protected['legal_hold'] === 1, 'trusted helper must set protections');
    hub_set_task_artifact_retention_protection($db, $artifactId, false, false);
    $released = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(empty($released['pinned_at']) && (int)$released['legal_hold'] === 0, 'trusted helper must release protections');
});

hub_test('retention prune recovers stale claims and purged artifacts return gone', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'gone owner');
        $token = hub_create_api_token($db, $memberId, 'gone token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'artifact', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', ['owner_member_id' => $memberId]);
        hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
        $path = hub_task_result_dir($taskId) . '/stale.txt';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
            throw new RuntimeException('Cannot create stale claim fixture.');
        }
        file_put_contents($path, 'stale', LOCK_EX);
        $artifactId = hub_register_task_artifact($db, $taskId, 'stale.txt', $path, 'text/plain');
        $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00', state = 'purging', purge_claim_token = 'abandoned', purge_claimed_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);
        $staleTaskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
        $db->prepare("UPDATE tasks SET source_state = 'purging', workspace_state = 'purging', purge_claim_token = 'abandoned', purge_claimed_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $staleTaskId]);

        hub_prune_retention($db, '2026-07-20 00:00:00');
        $response = hub_test_audio_request($db, 'artifact', (string)$token['plain_token'], [], ['artifact_id' => (string)$artifactId], [], 'GET', false);
        $recoveredTask = hub_get_task($db, $staleTaskId);
        hub_test_assert((hub_get_task_artifact($db, $artifactId)['state'] ?? '') === 'purged' && !file_exists($path), 'stale purge claims must be recovered and retried');
        hub_test_assert(($recoveredTask['source_state'] ?? '') === 'retention' && ($recoveredTask['workspace_state'] ?? '') === 'retention' && empty($recoveredTask['purge_claim_token']), 'a stale shared task claim must recover every purging resource');
        hub_test_assert($response['status'] === 410 && (hub_test_audio_payload($response)['error'] ?? '') === 'artifact_purged', 'purged artifact requests must return gone');
    });
});

hub_test('DB maintenance never deletes terminal task metadata directly', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/scripts/prune_db.php');
    hub_test_assert(!str_contains($source, 'DELETE FROM tasks'), 'metadata retention must not bypass retention-safe file cleanup');
});

hub_test('lost runtime fence terminalization applies retention lifecycle', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $fixture['run']['id']]);

    hub_test_assert(hub_pack_job_reconcile_lost_fence($db, hub_get_task($db, $fixture['task_id']), $fixture['run'], hub_test_pack_job_cleanup_asserted()), 'expired fence must reconcile');
    $task = hub_get_task($db, $fixture['task_id']);
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['retention_state'] ?? '') === 'retention' && !empty($task['source_expires_at']), 'lost-fence terminal task must enter retention');
});

hub_test('shared stream lock makes retention prune retry without deleting', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $path = hub_task_result_dir($taskId) . '/locked.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create lock fixture.');
    }
    file_put_contents($path, 'locked', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'locked.txt', $path, 'text/plain');
    $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);
    $stream = fopen($path, 'rb');
    if ($stream === false || !flock($stream, LOCK_SH)) {
        throw new RuntimeException('Cannot acquire shared lock fixture.');
    }
    try {
        hub_prune_retention($db, '2026-07-20 00:00:00');
        hub_test_assert(file_exists($path) && (hub_get_task_artifact($db, $artifactId)['state'] ?? '') === 'available', 'exclusive prune must not delete while shared stream lock exists');
    } finally {
        flock($stream, LOCK_UN);
        fclose($stream);
    }
    hub_prune_retention($db, '2026-07-20 00:00:00');
    hub_test_assert((hub_get_task_artifact($db, $artifactId)['state'] ?? '') === 'purged', 'prune must retry after stream lock release');
});

hub_test('retention rejects protections during a purge claim and aborts a newly protected claim', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $path = hub_task_result_dir($taskId) . '/claimed-protection.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create claimed protection fixture.');
    }
    file_put_contents($path, 'protected', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'claimed-protection.txt', $path, 'text/plain');
    $db->prepare("UPDATE task_artifacts SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);
    $claim = hub_retention_claim_artifact($db, $artifactId, '2026-07-20 00:00:00');
    hub_test_assert(is_array($claim), 'due artifact must enter a purge claim');

    $error = null;
    try {
        hub_set_task_artifact_retention_protection($db, $artifactId, true, true);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    hub_test_assert($error === 'purge_in_progress', 'protection updates must reject an active purge claim');

    $db->prepare("UPDATE task_artifacts SET pinned_at = '2026-07-20 00:00:00' WHERE id = :id")->execute([':id' => $artifactId]);
    hub_test_assert(function_exists('hub_retention_revalidate_artifact_claim'), 'prune must revalidate protections immediately before deleting');
    hub_test_assert(hub_retention_revalidate_artifact_claim($db, $claim) === false, 'new protection must abort the claim before file deletion');
    $artifact = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(($artifact['state'] ?? '') === 'available' && !empty($artifact['pinned_at']) && empty($artifact['purge_claim_token']) && file_exists($path), 'aborted protection claim must keep the artifact active and intact');

    hub_set_task_artifact_retention_protection($db, $artifactId, false, false);
    $claim = hub_retention_claim_artifact($db, $artifactId, '2026-07-20 00:00:00');
    hub_test_assert(is_array($claim) && hub_retention_revalidate_artifact_claim($db, $claim), 'a clear artifact must obtain a fresh valid claim');
    $db->prepare('UPDATE task_artifacts SET legal_hold = 1 WHERE id = :id')->execute([':id' => $artifactId]);
    hub_test_assert(hub_retention_finish_artifact_claim($db, $claim, 0, null, '2026-07-20 00:00:00') === false, 'finalization must not purge an artifact protected after pre-delete validation');
    $artifact = hub_get_task_artifact($db, $artifactId);
    hub_test_assert(($artifact['state'] ?? '') === 'available' && (int)($artifact['legal_hold'] ?? 0) === 1 && empty($artifact['purge_claim_token']), 'finalization protection fence must release the claim without purging metadata');
});

hub_test('task retention resource claims serialize source and workspace finalization', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $now = '2026-07-20 00:00:00';
    $db->prepare(
        "UPDATE tasks
         SET source_state = 'retention', workspace_state = 'retention',
             source_expires_at = '2000-01-01 00:00:00', workspace_expires_at = '2000-01-01 00:00:00',
             purge_claim_token = NULL, purge_claimed_at = NULL
         WHERE id = :id"
    )->execute([':id' => $taskId]);

    $source = hub_retention_claim_task_resource($db, $taskId, 'source', $now);
    hub_test_assert(is_array($source), 'source must acquire the task purge claim');
    hub_test_assert(hub_retention_claim_task_resource($db, $taskId, 'workspace', $now) === null, 'workspace must not overwrite an active source claim');
    hub_retention_finish_task_resource_claim($db, $source, 0, null, $now);

    $workspace = hub_retention_claim_task_resource($db, $taskId, 'workspace', $now);
    hub_test_assert(is_array($workspace), 'workspace must acquire the claim after source finalizes');
    hub_retention_finish_task_resource_claim($db, $workspace, 0, null, $now);
    $task = hub_get_task($db, $taskId);
    hub_test_assert(($task['source_state'] ?? '') === 'purged' && ($task['workspace_state'] ?? '') === 'purged' && empty($task['purge_claim_token']), 'serialized resource finalization must not leave either resource purging');
});

function hub_test_retention_metadata_ready_task(PDO $db, string $finishedAt, string $taskType = 'demo_task'): int
{
    $taskId = hub_enqueue_task($db, $taskType, 'default', 0, [], null, '127.0.0.1');
    hub_finish_task_success($db, hub_get_task($db, $taskId), ['ok' => true]);
    $db->prepare(
        "UPDATE tasks
         SET finished_at = :finished_at, updated_at = :finished_at,
             source_state = 'purged', workspace_state = 'purged', retention_state = 'purged',
             purge_claim_token = NULL, purge_claimed_at = NULL
         WHERE id = :id"
    )->execute([':finished_at' => $finishedAt, ':id' => $taskId]);

    return $taskId;
}

hub_test('retention keeps terminal metadata before 180 days then purges an eligible record', function (): void {
    $db = hub_test_reset_db();
    $now = '2026-07-20 00:00:00';
    $taskId = hub_test_retention_metadata_ready_task($db, '2026-02-01 00:00:00');

    $before = hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) !== null && (int)($before['metadata_purged'] ?? 0) === 0, 'terminal metadata must remain before the 180-day deadline');

    $db->prepare("UPDATE tasks SET finished_at = '2025-01-01 00:00:00', updated_at = '2025-01-01 00:00:00' WHERE id = :id")->execute([':id' => $taskId]);
    $after = hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) === null && (int)($after['metadata_purged'] ?? 0) === 1, 'eligible terminal metadata must be deleted only after 180 days');
});

hub_test('legacy timeout tasks remain eligible for final retention cleanup', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_test_retention_metadata_ready_task($db, '2025-01-01 00:00:00');
    $db->prepare("UPDATE tasks SET status = 'timeout' WHERE id = :id")->execute([':id' => $taskId]);
    hub_migrate($db);
    hub_test_assert((hub_get_task($db, $taskId)['status'] ?? '') === 'timed_out', 'migration must normalize legacy timeout task status before retention runs');

    $result = hub_prune_retention($db, '2026-07-20 00:00:00');

    hub_test_assert(hub_get_task($db, $taskId) === null && (int)($result['metadata_purged'] ?? 0) === 1, 'legacy timeout metadata must be normalized or treated as terminal by retention cleanup');
});

hub_test('active voice profile retains prepare task metadata until soft delete', function (): void {
    $db = hub_test_reset_db();
    $now = '2026-07-20 00:00:00';
    $taskId = hub_test_retention_metadata_ready_task($db, '2025-01-01 00:00:00', 'voice_profile_prepare');
    $memberId = hub_create_api_member($db, 'Voice profile metadata owner');
    $path = hub_voice_profile_storage_dir() . '/metadata-retention.wav';
    file_put_contents($path, 'RIFFmetadata');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Metadata retention',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'expires_at' => '2026-07-21 00:00:00',
    ]);
    $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')
        ->execute([':task_id' => $taskId, ':id' => $profileId]);

    $beforeDelete = hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) !== null && (int)($beforeDelete['metadata_purged'] ?? 0) === 0, 'an active non-expired voice profile must retain prepare task metadata');

    hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
    $afterDelete = hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) === null && (int)($afterDelete['metadata_purged'] ?? 0) === 1, 'soft delete must release prepare task metadata for the next prune');
});

hub_test('profile prepare does not reuse an unpruned expired voice profile', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Expired profile cache owner');
        $token = hub_create_api_token($db, $memberId, 'expired profile cache token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $wav = "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0);
        $expiredPath = hub_voice_profile_storage_dir() . '/expired-cache.wav';
        file_put_contents($expiredPath, $wav, LOCK_EX);
        $expiredId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Expired cache profile',
            'reference_audio_path' => $expiredPath,
            'consent_type' => 'self_recorded',
            'expires_at' => '2000-01-01 00:00:00',
        ]);
        $tmpName = tempnam(sys_get_temp_dir(), 'expired-profile-cache-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create expired profile cache WAV fixture.');
        }
        file_put_contents($tmpName, $wav, LOCK_EX);

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=expired-profile-cache';
            $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Replacement profile',
                'consent_type' => 'self_recorded',
            ], [], ['reference_wav' => [
                'name' => 'replacement.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]]);
            $task = hub_get_task($db, (int)(hub_test_audio_payload($response)['task_id'] ?? 0));
            $replacement = hub_get_voice_profile($db, (int)($task['input']['voice_profile_id'] ?? 0));

            hub_test_assert(
                $response['status'] === 200
                && $replacement !== null
                && (int)$replacement['id'] !== $expiredId
                && (empty($replacement['expires_at']) || (string)$replacement['expires_at'] > hub_now()),
                'same-WAV profile_prepare must create a usable profile instead of returning an expired cache hit'
            );
        } finally {
            unlink($tmpName);
        }
    });
});

hub_test('owned voice profiles with unavailable managed WAVs return 410 without weakening lookup boundaries', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $ownerId = hub_create_api_member($db, 'Unavailable WAV owner');
        $foreignId = hub_create_api_member($db, 'Unavailable WAV foreign member');
        $ownerToken = hub_create_api_token($db, $ownerId, 'unavailable WAV owner token', null, null);
        $foreignToken = hub_create_api_token($db, $foreignId, 'unavailable WAV foreign token', null, null);
        hub_test_audio_allow($db, [$ownerToken, $foreignToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $paths = [];
        $profileIds = [];
        foreach (['missing', 'unsafe', 'unreadable'] as $state) {
            $path = hub_voice_profile_storage_dir() . '/unavailable-' . $state . '.wav';
            file_put_contents($path, 'RIFFunavailable-' . $state, LOCK_EX);
            $paths[$state] = $path;
            $profileIds[$state] = hub_create_voice_profile($db, $ownerId, [
                'name' => ucfirst($state) . ' managed WAV',
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
            ]);
        }
        unlink($paths['missing']);
        $unsafeTarget = hub_voice_profile_storage_dir() . '/unsafe-target.wav';
        file_put_contents($unsafeTarget, 'RIFFunsafe-target', LOCK_EX);
        unlink($paths['unsafe']);
        if (!symlink($unsafeTarget, $paths['unsafe'])) {
            throw new RuntimeException('Cannot create unavailable voice profile symlink fixture.');
        }
        chmod($paths['unreadable'], 0000);

        try {
            foreach ($profileIds as $state => $profileId) {
                $response = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], [
                    'text' => 'Unavailable managed WAV',
                    'mode' => 'clone',
                    'voice_profile_id' => (string)$profileId,
                ]);
                hub_test_assert(
                    $response['status'] === 410
                    && (hub_test_audio_payload($response)['error'] ?? '') === 'voice_profile_unavailable',
                    'owned profile with ' . $state . ' managed WAV must return voice_profile_unavailable'
                );
            }

            foreach ([
                [(string)$foreignToken['plain_token'], (string)$profileIds['missing']],
                [(string)$ownerToken['plain_token'], '2147483647'],
            ] as [$tokenValue, $profileId]) {
                $response = hub_test_audio_request($db, 'voice_generate', $tokenValue, [
                    'text' => 'Non-enumerating unavailable profile',
                    'mode' => 'clone',
                    'voice_profile_id' => $profileId,
                ]);
                hub_test_assert(
                    $response['status'] === 403
                    && (hub_test_audio_payload($response)['error'] ?? '') === 'voice_profile_forbidden',
                    'foreign and unknown profiles must remain non-enumerating'
                );
            }
        } finally {
            chmod($paths['unreadable'], 0600);
            unlink($paths['unsafe']);
        }
    });
});

hub_test('voice profile hardlinks fail closed at creation ASR and deletion boundaries', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Hardlink voice profile owner');
    $root = hub_voice_profile_storage_dir();
    $createPath = $root . '/hardlink-create.wav';
    $createAlias = $root . '/hardlink-create-alias.wav';
    $asrPath = $root . '/hardlink-asr.wav';
    $asrAlias = $root . '/hardlink-asr-alias.wav';
    $deletePath = $root . '/hardlink-delete.wav';
    $deleteAlias = $root . '/hardlink-delete-alias.wav';
    foreach ([$createPath, $asrPath, $deletePath] as $path) {
        file_put_contents($path, 'RIFF' . basename($path), LOCK_EX);
    }
    if (!link($createPath, $createAlias) || !link($asrPath, $asrAlias) || !link($deletePath, $deleteAlias)) {
        throw new RuntimeException('Cannot create voice profile hardlink fixtures.');
    }

    $asrId = 0;
    $deleteId = 0;
    try {
        hub_test_assert(
            hub_test_throws(static fn (): int => hub_create_voice_profile($db, $memberId, [
                'name' => 'Rejected hardlink profile',
                'reference_audio_path' => $createPath,
                'consent_type' => 'self_recorded',
            ])),
            'a multiply linked WAV must never enter managed Profile state'
        );

        unlink($asrAlias);
        $asrId = hub_create_voice_profile($db, $memberId, [
            'name' => 'ASR hardlink profile',
            'reference_audio_path' => $asrPath,
            'consent_type' => 'self_recorded',
        ]);
        link($asrPath, $asrAlias);
        $asrCalled = false;
        $asrResult = hub_run_voice_profile_transcription(
            $db,
            hub_get_voice_profile($db, $asrId) ?? [],
            $memberId,
            static function () use (&$asrCalled): array {
                $asrCalled = true;
                return ['ok' => true, 'text' => 'must not transcribe', 'language' => 'en'];
            }
        );

        unlink($deleteAlias);
        $deleteId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Delete hardlink profile',
            'reference_audio_path' => $deletePath,
            'consent_type' => 'self_recorded',
        ]);
        link($deletePath, $deleteAlias);
        $deleted = hub_soft_delete_voice_profile($db, $deleteId, $memberId, true);
        $deleteRow = $db->query('SELECT reference_audio_path FROM voice_profiles WHERE id = ' . $deleteId)->fetch();

        hub_test_assert(
            !$asrCalled
            && ($asrResult['transcription']['error'] ?? '') === 'asr_failed'
            && !empty($deleted['audio_cleanup_failed'])
            && is_file($deletePath)
            && is_file($deleteAlias)
            && ($deleteRow['reference_audio_path'] ?? '') === $deletePath,
            'ASR and delete must reject multiply linked Profile WAVs without consuming or losing cleanup state'
        );
    } finally {
        foreach ([$createAlias, $asrAlias, $deleteAlias] as $alias) {
            if (is_file($alias)) {
                unlink($alias);
            }
        }
    }
});

hub_test('voice profile ASR snapshots and delete identity checks resist pathname replacement', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Voice profile replacement owner');
    $root = hub_voice_profile_storage_dir();
    $asrPath = $root . '/replacement-asr.wav';
    $asrReplacement = $root . '/replacement-asr-other.wav';
    $originalAudio = 'RIFForiginal-private-voice';
    file_put_contents($asrPath, $originalAudio, LOCK_EX);
    file_put_contents($asrReplacement, 'RIFFother-profile-voice', LOCK_EX);
    $asrId = hub_create_voice_profile($db, $memberId, [
        'name' => 'ASR replacement profile',
        'reference_audio_path' => $asrPath,
        'consent_type' => 'self_recorded',
    ]);

    $asrResult = hub_run_voice_profile_transcription(
        $db,
        hub_get_voice_profile($db, $asrId) ?? [],
        $memberId,
        static function (array $verifiedUpload) use ($asrPath, $asrReplacement, $originalAudio): array {
            rename($asrReplacement, $asrPath);
            return [
                'ok' => true,
                'text' => file_get_contents((string)$verifiedUpload['tmp_name']) === $originalAudio ? 'original voice' : 'wrong voice',
                'language' => 'en',
            ];
        }
    );

    $deletePath = $root . '/replacement-delete.wav';
    $protectedPath = $root . '/replacement-protected.wav';
    file_put_contents($deletePath, 'RIFFdelete-profile', LOCK_EX);
    file_put_contents($protectedPath, 'RIFFprotected-profile', LOCK_EX);
    $deleteId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Delete replacement profile',
        'reference_audio_path' => $deletePath,
        'consent_type' => 'self_recorded',
    ]);
    hub_create_voice_profile($db, $memberId, [
        'name' => 'Protected replacement profile',
        'reference_audio_path' => $protectedPath,
        'consent_type' => 'self_recorded',
    ]);
    rename($protectedPath, $deletePath);
    $deleted = hub_soft_delete_voice_profile($db, $deleteId, $memberId, true);

    hub_test_assert(
        ($asrResult['transcription']['text'] ?? '') === 'original voice'
        && !empty($deleted['audio_cleanup_failed'])
        && file_get_contents($deletePath) === 'RIFFprotected-profile',
        'ASR must consume a verified snapshot and delete must preserve a different Profile inode moved into the pathname'
    );
});

hub_test('voice profile disposal scrubs a hardlink raced onto the quarantined inode', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Disposal race owner');
    $path = hub_voice_profile_storage_dir() . '/disposal-race.wav';
    $alias = hub_voice_profile_storage_dir() . '/disposal-race-alias.wav';
    file_put_contents($path, 'RIFFprivate-disposal-race', LOCK_EX);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Disposal race profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
    ]);
    $profile = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing disposal race Profile.');
    hub_soft_delete_voice_profile($db, $profileId, $memberId, false);

    try {
        $purged = hub_purge_deleted_voice_profile_audio(
            $db,
            $profileId,
            $path,
            (string)$profile['reference_audio_sha256'],
            static function (string $quarantinePath) use ($alias): void {
                if (!link($quarantinePath, $alias)) {
                    throw new RuntimeException('Cannot inject disposal hardlink race.');
                }
            }
        );
        $storedPath = $db->query('SELECT reference_audio_path FROM voice_profiles WHERE id = ' . $profileId)->fetchColumn();
        $deleteAudits = (int)$db->query(
            "SELECT COUNT(*) FROM voice_profile_audit_logs
             WHERE voice_profile_id = " . $profileId . " AND action = 'delete'"
        )->fetchColumn();

        hub_test_assert(
            $purged
            && !file_exists($path)
            && is_file($alias)
            && filesize($alias) === 0
            && file_get_contents($alias) === ''
            && $storedPath === ''
            && $deleteAudits === 1,
            'disposal must durably scrub the exact inode before unlink so a raced alias retains no private bytes or pending Profile path'
        );
    } finally {
        if (is_file($alias)) {
            unlink($alias);
        }
        if (is_file($path)) {
            unlink($path);
        }
    }
});

hub_test('voice profile snapshots are managed with bounded stale and capacity cleanup', function (): void {
    $outside = tempnam(sys_get_temp_dir(), 'voice-profile-snapshot-outside-');
    if ($outside === false) {
        throw new RuntimeException('Cannot create outside snapshot cleanup fixture.');
    }
    file_put_contents($outside, 'outside-protected', LOCK_EX);
    $snapshotDir = null;
    $sourcePath = null;

    try {
        $snapshotDir = hub_voice_profile_snapshot_dir();
        $stale = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('a', 32) . '.wav';
        $staleLink = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('b', 32) . '.wav';
        file_put_contents($stale, 'stale-private-snapshot', LOCK_EX);
        if (!symlink($outside, $staleLink)) {
            throw new RuntimeException('Cannot create stale snapshot symlink fixture.');
        }
        $cleanup = hub_cleanup_stale_voice_profile_snapshots(time() + 7200);
        $outsideUntouched = is_file($outside) && file_get_contents($outside) === 'outside-protected';

        $sourcePath = hub_voice_profile_storage_dir() . '/snapshot-capacity-source.wav';
        file_put_contents($sourcePath, 'RIFFsnapshot-capacity-source', LOCK_EX);
        $managedSnapshot = hub_voice_profile_verified_upload($sourcePath, hash_file('sha256', $sourcePath));
        $managedStat = is_array($managedSnapshot) ? lstat((string)$managedSnapshot['tmp_name']) : false;
        $managedPrivate = is_array($managedSnapshot)
            && dirname((string)$managedSnapshot['tmp_name']) === $snapshotDir
            && is_array($managedStat)
            && (((int)$managedStat['mode'] & 0777) === 0600);
        if (is_array($managedSnapshot)) {
            unlink((string)$managedSnapshot['tmp_name']);
        }

        for ($index = 0; $index < 32; $index++) {
            file_put_contents(
                $snapshotDir . '/voice_profile_snapshot_' . sprintf('%032x', $index) . '.wav',
                'x',
                LOCK_EX
            );
        }
        $countBlocked = hub_voice_profile_verified_upload($sourcePath, hash_file('sha256', $sourcePath));
        if (is_array($countBlocked)) {
            unlink((string)$countBlocked['tmp_name']);
        }
        foreach (glob($snapshotDir . '/voice_profile_snapshot_*.wav') ?: [] as $snapshot) {
            if (is_file($snapshot) && !is_link($snapshot)) {
                unlink($snapshot);
            }
        }

        $large = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('c', 32) . '.wav';
        $largeHandle = fopen($large, 'w+b');
        if ($largeHandle === false || !ftruncate($largeHandle, 256 * 1024 * 1024)) {
            throw new RuntimeException('Cannot create sparse snapshot capacity fixture.');
        }
        fclose($largeHandle);
        $bytesBlocked = hub_voice_profile_verified_upload($sourcePath, hash_file('sha256', $sourcePath));
        if (is_array($bytesBlocked)) {
            unlink((string)$bytesBlocked['tmp_name']);
        }

        hub_test_assert(
            (int)($cleanup['purged'] ?? 0) === 2
            && !file_exists($stale)
            && !is_link($staleLink)
            && $outsideUntouched
            && $managedPrivate
            && $countBlocked === null
            && $bytesBlocked === null,
            'snapshot cleanup must stay inside its private area and enforce stale, file-count, and byte-capacity boundaries'
        );
    } finally {
        if (is_string($snapshotDir) && is_dir($snapshotDir)) {
            foreach (scandir($snapshotDir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $snapshot = $snapshotDir . '/' . $name;
                if (is_link($snapshot) || is_file($snapshot)) {
                    unlink($snapshot);
                }
            }
            rmdir($snapshotDir);
        }
        if (is_string($sourcePath) && is_file($sourcePath)) {
            unlink($sourcePath);
        }
        unlink($outside);
    }
});

hub_test('retention prune clears stale voice profile snapshots without an ASR upload', function (): void {
    $db = hub_test_reset_db();
    $outside = tempnam(sys_get_temp_dir(), 'voice-profile-prune-snapshot-outside-');
    if ($outside === false) {
        throw new RuntimeException('Cannot create outside retention snapshot fixture.');
    }
    file_put_contents($outside, 'outside-protected', LOCK_EX);
    $snapshotDir = null;

    try {
        $snapshotDir = hub_voice_profile_snapshot_dir();
        $stale = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('d', 32) . '.wav';
        $staleLink = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('e', 32) . '.wav';
        file_put_contents($stale, 'stale-private-snapshot', LOCK_EX);
        if (!symlink($outside, $staleLink)) {
            throw new RuntimeException('Cannot create retention snapshot symlink fixture.');
        }

        $result = hub_prune_expired_voice_profiles($db, date('Y-m-d H:i:s', time() + 7200), 1);
        $outsideUntouched = is_file($outside) && file_get_contents($outside) === 'outside-protected';

        hub_test_assert(
            (int)($result['profiles_deleted'] ?? 0) === 0
            && !file_exists($stale)
            && !is_link($staleLink)
            && $outsideUntouched,
            'retention prune must clean stale private snapshots without following snapshot symlinks'
        );
    } finally {
        if (is_string($snapshotDir) && is_dir($snapshotDir)) {
            foreach (scandir($snapshotDir) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $snapshot = $snapshotDir . '/' . $name;
                if (is_link($snapshot) || is_file($snapshot)) {
                    unlink($snapshot);
                }
            }
            rmdir($snapshotDir);
        }
        unlink($outside);
    }
});

hub_test('legacy soft-deleted voice profile WAV cleanup recovers safely and idempotently', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Legacy cleanup owner');
    $path = hub_voice_profile_storage_dir() . '/legacy-empty-sha.wav';
    file_put_contents($path, 'RIFFlegacy-empty-sha', LOCK_EX);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy empty SHA profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
    ]);
    hub_soft_delete_voice_profile($db, $profileId, $memberId, false);
    $db->prepare(
        "UPDATE voice_profile_audit_logs
         SET details_json = '{\"delete_audio\":false}'
         WHERE voice_profile_id = :voice_profile_id AND action = 'delete'"
    )->execute([':voice_profile_id' => $profileId]);

    $first = hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
    $second = hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
    $legacy = $db->query(
        'SELECT reference_audio_path, reference_audio_sha256 FROM voice_profiles WHERE id = ' . $profileId
    )->fetch();

    $sharedPath = hub_voice_profile_storage_dir() . '/legacy-active-reference.wav';
    file_put_contents($sharedPath, 'RIFFlegacy-active-reference', LOCK_EX);
    $legacySharedId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy shared tombstone',
        'reference_audio_path' => $sharedPath,
        'consent_type' => 'self_recorded',
    ]);
    hub_soft_delete_voice_profile($db, $legacySharedId, $memberId, false);
    $db->prepare(
        "UPDATE voice_profile_audit_logs
         SET details_json = '{\"delete_audio\":false}'
         WHERE voice_profile_id = :voice_profile_id AND action = 'delete'"
    )->execute([':voice_profile_id' => $legacySharedId]);
    $activeId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Active shared path profile',
        'reference_audio_path' => $sharedPath,
        'consent_type' => 'self_recorded',
    ]);
    $blocked = hub_soft_delete_voice_profile($db, $legacySharedId, $memberId, true);
    $blockedPath = $db->query(
        'SELECT reference_audio_path FROM voice_profiles WHERE id = ' . $legacySharedId
    )->fetchColumn();

    hub_test_assert(
        empty($first['audio_cleanup_failed'])
        && empty($second['audio_cleanup_failed'])
        && !file_exists($path)
        && $legacy !== false
        && ($legacy['reference_audio_path'] ?? null) === ''
        && ($legacy['reference_audio_sha256'] ?? null) === ''
        && !empty($blocked['audio_cleanup_failed'])
        && is_file($sharedPath)
        && $blockedPath === $sharedPath
        && hub_get_voice_profile($db, $activeId) !== null,
        'legacy recovery must clean an unreferenced exact inode idempotently and refuse a path still owned by an active Profile'
    );
});

hub_test('voice profile purge rollback restores only the verified managed source path', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Disposal rollback owner');
    $path = hub_voice_profile_storage_dir() . '/disposal-rollback.wav';
    file_put_contents($path, 'RIFFprivate-disposal-rollback', LOCK_EX);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Disposal rollback profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
    ]);
    $profile = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing rollback profile.');
    hub_soft_delete_voice_profile($db, $profileId, $memberId, false);

    $purged = hub_purge_deleted_voice_profile_audio(
        $db,
        $profileId,
        $path,
        (string)$profile['reference_audio_sha256'],
        static function (): void {
            throw new RuntimeException('Inject rollback after quarantine.');
        }
    );

    hub_test_assert(
        !$purged
        && is_file($path)
        && file_get_contents($path) === 'RIFFprivate-disposal-rollback',
        'failed disposal must restore only the verified managed source asset'
    );
});

hub_test('retention expires a handle-lost voice profile and removes its managed WAV', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Expired voice profile owner');
    $path = hub_voice_profile_storage_dir() . '/expired-handle-lost.wav';
    file_put_contents($path, 'RIFFexpired-handle-lost', LOCK_EX);
    $privatePrompt = 'Private expired voice transcript.';
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Expired handle-lost profile',
        'reference_audio_path' => $path,
        'prompt_text' => $privatePrompt,
        'language' => 'zh-TW',
        'consent_type' => 'self_recorded',
        'expires_at' => '2026-07-19 23:59:59',
    ]);
    hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $privatePrompt);

    $result = hub_prune_retention($db, '2026-07-20 00:00:00');
    $stmt = $db->prepare(
        'SELECT deleted_at, reference_audio_path, reference_audio_sha256, prompt_text,
                prompt_text_confirmed_at, language, name, source_task_id
         FROM voice_profiles WHERE id = :id'
    );
    $stmt->execute([':id' => $profileId]);
    $expired = $stmt->fetch();

    hub_test_assert(
        $expired !== false
        && !empty($expired['deleted_at'])
        && ($expired['reference_audio_path'] ?? null) === ''
        && ($expired['reference_audio_sha256'] ?? null) === ''
        && ($expired['prompt_text'] ?? null) === null
        && ($expired['prompt_text_confirmed_at'] ?? null) === null
        && ($expired['language'] ?? null) === null
        && ($expired['name'] ?? null) === 'Expired voice profile'
        && empty($expired['source_task_id'])
        && !file_exists($path),
        'expired profile cleanup must not depend on receiving or retaining a task handle'
    );
    hub_test_assert(
        !str_contains(json_encode($expired, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $privatePrompt),
        'expired profile cleanup must remove confirmed prompt plaintext and identifying voice metadata'
    );
    hub_test_assert(
        (int)($result['voice_profiles_deleted'] ?? 0) === 1
        && (int)($result['voice_profile_audio_purged'] ?? 0) === 1
        && (int)($result['voice_profile_errors'] ?? -1) === 0,
        'retention must report bounded profile and WAV cleanup without sensitive details'
    );
});

hub_test('expired voice profile cleanup never follows another Profile WAV symlink', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Symlink voice profile owner');
    $root = hub_voice_profile_storage_dir();
    $protectedPath = $root . '/protected-profile.wav';
    $aliasSourcePath = $root . '/alias-source.wav';
    $aliasPath = $root . '/expired-profile-link.wav';
    file_put_contents($protectedPath, 'RIFFprotected-profile', LOCK_EX);
    file_put_contents($aliasSourcePath, 'RIFFalias-source', LOCK_EX);
    $protectedId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Protected profile',
        'reference_audio_path' => $protectedPath,
        'consent_type' => 'self_recorded',
    ]);
    $expiredId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Expired linked profile',
        'reference_audio_path' => $aliasSourcePath,
        'prompt_text' => 'Linked profile private prompt.',
        'consent_type' => 'self_recorded',
        'expires_at' => '2026-07-19 23:59:59',
    ]);
    if (!symlink($protectedPath, $aliasPath)) {
        throw new RuntimeException('Cannot create voice profile symlink fixture.');
    }
    $db->prepare('UPDATE voice_profiles SET reference_audio_path = :path WHERE id = :id')
        ->execute([':path' => $aliasPath, ':id' => $expiredId]);

    try {
        $result = hub_prune_expired_voice_profiles($db, '2026-07-20 00:00:00', 10);
        $expired = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $expiredId)->fetch();

        hub_test_assert(
            is_file($protectedPath)
            && is_link($aliasPath)
            && hub_get_voice_profile($db, $protectedId) !== null,
            'cleanup must never follow an expired Profile symlink into another active Profile WAV'
        );
        hub_test_assert(
            $expired !== false
            && !empty($expired['deleted_at'])
            && ($expired['reference_audio_path'] ?? null) === $aliasPath
            && ($expired['prompt_text'] ?? null) === null
            && (int)($result['errors'] ?? 0) === 1,
            'unsafe linked audio must remain retryable while private database material is scrubbed'
        );
    } finally {
        if (is_link($aliasPath)) {
            unlink($aliasPath);
        }
        if (is_file($aliasSourcePath)) {
            unlink($aliasSourcePath);
        }
    }
});

hub_test('expired voice profile cleanup rotates permanent failures past the batch limit', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Voice cleanup rotation owner');
    $root = hub_voice_profile_storage_dir();
    $protectedPath = $root . '/rotation-protected.wav';
    $aliasPath = $root . '/rotation-blocker-link.wav';
    file_put_contents($protectedPath, 'RIFFrotation-protected', LOCK_EX);
    $protectedId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Rotation protected profile',
        'reference_audio_path' => $protectedPath,
        'consent_type' => 'self_recorded',
    ]);
    if (!symlink($protectedPath, $aliasPath)) {
        throw new RuntimeException('Cannot create cleanup rotation symlink fixture.');
    }

    try {
        for ($index = 0; $index < 101; $index++) {
            $sourcePath = $root . '/rotation-bad-' . $index . '.wav';
            file_put_contents($sourcePath, 'RIFFrotation-bad-' . $index, LOCK_EX);
            $profileId = hub_create_voice_profile($db, $memberId, [
                'name' => 'Blocked expired profile ' . $index,
                'reference_audio_path' => $sourcePath,
                'prompt_text' => 'Blocked private prompt ' . $index,
                'consent_type' => 'self_recorded',
                'expires_at' => '2026-07-19 23:59:59',
            ]);
            $db->prepare(
                'UPDATE voice_profiles
                 SET reference_audio_path = :path, updated_at = :updated_at
                 WHERE id = :id'
            )->execute([
                ':path' => $aliasPath,
                ':updated_at' => '2026-07-01 00:00:00',
                ':id' => $profileId,
            ]);
            unlink($sourcePath);
        }

        $validPath = $root . '/rotation-valid.wav';
        file_put_contents($validPath, 'RIFFrotation-valid', LOCK_EX);
        $validId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Later valid expired profile',
            'reference_audio_path' => $validPath,
            'prompt_text' => 'Later valid private prompt.',
            'consent_type' => 'self_recorded',
            'expires_at' => '2026-07-19 23:59:59',
        ]);
        $db->prepare('UPDATE voice_profiles SET updated_at = :updated_at WHERE id = :id')
            ->execute([':updated_at' => '2026-07-01 00:00:00', ':id' => $validId]);

        $first = hub_prune_expired_voice_profiles($db, '2026-07-20 00:00:00', 100);
        hub_test_assert(
            is_file($validPath) && (int)($first['errors'] ?? 0) === 100,
            'the first bounded cleanup pass must report only its selected permanent failures'
        );

        $second = hub_prune_expired_voice_profiles($db, '2026-07-20 00:01:00', 100);
        $valid = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $validId)->fetch();
        hub_test_assert(
            $valid !== false
            && !empty($valid['deleted_at'])
            && ($valid['reference_audio_path'] ?? null) === ''
            && !file_exists($validPath)
            && (int)($second['audio_purged'] ?? 0) === 1,
            'rotating failed rows must let a later valid expired Profile complete on a bounded successive pass'
        );
        hub_test_assert(
            is_file($protectedPath)
            && is_link($aliasPath)
            && hub_get_voice_profile($db, $protectedId) !== null,
            'retries must remain fail-closed and never delete the shared symlink target'
        );
    } finally {
        if (is_link($aliasPath)) {
            unlink($aliasPath);
        }
    }
});

hub_test('DocParser repair lineage retains parent metadata while queued or running', function (): void {
    $db = hub_test_reset_db();
    $now = '2026-07-20 00:00:00';
    $parentId = hub_test_retention_metadata_ready_task($db, '2025-01-01 00:00:00');
    $repairId = hub_enqueue_task($db, 'docparser_repair_translation', 'ocr', 0, ['source_task_id' => $parentId, 'block_ids' => ['p1-b1']], null, '127.0.0.1', [
        'source_task_id' => $parentId,
    ]);

    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $parentId) !== null, 'a queued DocParser repair must retain its source task metadata');

    $db->prepare("UPDATE tasks SET status = 'running' WHERE id = :id")->execute([':id' => $repairId]);
    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $parentId) !== null, 'a running DocParser repair must retain its source task metadata');

    $db->prepare('UPDATE tasks SET source_task_id = NULL WHERE id = :id')->execute([':id' => $repairId]);
    $result = hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $parentId) === null && hub_get_task($db, $repairId) !== null && (int)($result['metadata_purged'] ?? 0) === 1, 'parent metadata becomes eligible only after the repair lineage column is cleared');
});

hub_test('metadata purge keeps records with an artifact hold or pending callback', function (): void {
    $db = hub_test_reset_db();
    $now = '2026-07-20 00:00:00';
    $taskId = hub_test_retention_metadata_ready_task($db, '2025-01-01 00:00:00');
    $path = hub_task_result_dir($taskId) . '/metadata-blocker.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create metadata blocker artifact.');
    }
    file_put_contents($path, 'metadata blocker', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'metadata-blocker.txt', $path, 'text/plain');

    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) !== null, 'an unpurged artifact must block metadata deletion');

    unlink($path);
    $db->prepare("UPDATE task_artifacts SET state = 'purged', purged_at = '2025-01-02 00:00:00', pinned_at = NULL, legal_hold = 0 WHERE id = :id")->execute([':id' => $artifactId]);
    $downstreamId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1');
    $db->prepare('INSERT INTO task_artifact_holds (source_artifact_id, downstream_task_id, held_at) VALUES (:artifact_id, :task_id, :held_at)')
        ->execute([':artifact_id' => $artifactId, ':task_id' => $downstreamId, ':held_at' => '2025-01-03 00:00:00']);
    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) !== null, 'an active artifact hold must block metadata deletion');

    $db->prepare('UPDATE task_artifact_holds SET released_at = :released_at WHERE source_artifact_id = :artifact_id')
        ->execute([':released_at' => '2025-01-04 00:00:00', ':artifact_id' => $artifactId]);
    $memberId = hub_create_api_member($db, 'metadata callback owner');
    $targetId = hub_register_callback_target($db, $memberId, 'metadata', 'https://8.8.8.8/callback');
    $db->prepare(
        'INSERT INTO task_callback_deliveries
            (delivery_id, callback_target_id, task_id, event_type, payload_json, attempt_count, next_attempt_at, created_at, updated_at)
         VALUES (:delivery_id, :target_id, :task_id, :event_type, :payload_json, 0, :next_attempt_at, :created_at, :updated_at)'
    )->execute([
        ':delivery_id' => 'metadata-' . $taskId,
        ':target_id' => $targetId,
        ':task_id' => $taskId,
        ':event_type' => 'task.completed',
        ':payload_json' => '{}',
        ':next_attempt_at' => '2025-01-05 00:00:00',
        ':created_at' => '2025-01-05 00:00:00',
        ':updated_at' => '2025-01-05 00:00:00',
    ]);
    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) !== null, 'a pending callback must block metadata deletion');

    $db->prepare('UPDATE task_callback_deliveries SET attempt_count = 5, next_attempt_at = NULL WHERE task_id = :task_id')->execute([':task_id' => $taskId]);
    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $taskId) === null && hub_get_task($db, $downstreamId) !== null, 'final metadata deletion must keep the downstream task intact');
});

hub_test('metadata purge preserves a parent referenced by a completed downstream task', function (): void {
    $db = hub_test_reset_db();
    $now = '2026-07-20 00:00:00';
    $parentId = hub_test_retention_metadata_ready_task($db, '2025-01-01 00:00:00');
    $path = hub_task_result_dir($parentId) . '/purged-parent-artifact.txt';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
        throw new RuntimeException('Cannot create parent artifact fixture.');
    }
    file_put_contents($path, 'purged parent artifact', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $parentId, 'purged-parent-artifact.txt', $path, 'text/plain');
    unlink($path);
    $db->prepare("UPDATE task_artifacts SET state = 'purged', purged_at = '2025-01-02 00:00:00' WHERE id = :id")
        ->execute([':id' => $artifactId]);
    $childId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '127.0.0.1', [
        'source_artifact_id' => $artifactId,
        'source_task_id' => $parentId,
        'retry_of_task_id' => $parentId,
    ]);
    hub_finish_task_success($db, hub_get_task($db, $childId), ['ok' => true]);

    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $parentId) !== null && hub_get_task($db, $childId) !== null, 'completed downstream lineage must retain the parent metadata and artifact record');

    $db->prepare('UPDATE tasks SET source_artifact_id = NULL, source_task_id = NULL, retry_of_task_id = NULL WHERE id = :id')
        ->execute([':id' => $childId]);
    hub_prune_retention($db, $now);
    hub_test_assert(hub_get_task($db, $parentId) === null && hub_get_task($db, $childId) !== null, 'the parent becomes eligible only after every downstream lineage reference is cleared');
});

hub_test('session-admin artifact retention controls require CSRF while bearer controls remain token-authenticated', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '203.0.113.51');
        $path = hub_task_result_dir($taskId) . '/csrf.txt';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true)) {
            throw new RuntimeException('Cannot create CSRF artifact fixture.');
        }
        file_put_contents($path, 'csrf', LOCK_EX);
        $artifactId = hub_register_task_artifact($db, $taskId, 'csrf.txt', $path, 'text/plain');
        $memberId = hub_create_api_member($db, 'retention bearer owner');
        $token = hub_create_api_token($db, $memberId, 'retention bearer token', null, null);
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'task_artifact_retention', null);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $session = $_SESSION ?? [];
        $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'csrf-good'];
        try {
            $missing = hub_test_audio_request($db, 'task_artifact_retention', '', ['artifact_id' => (string)$artifactId, 'action' => 'pin']);
            $bad = hub_test_audio_request($db, 'task_artifact_retention', '', ['artifact_id' => (string)$artifactId, 'action' => 'pin', 'csrf_token' => 'csrf-bad']);
            hub_test_assert($missing['status'] === 400 && $bad['status'] === 400 && empty(hub_get_task_artifact($db, $artifactId)['pinned_at']), 'missing or invalid session CSRF must reject retention mutation without changing the artifact');

            $valid = hub_test_audio_request($db, 'task_artifact_retention', '', ['artifact_id' => (string)$artifactId, 'action' => 'pin', 'csrf_token' => 'csrf-good']);
            $bearer = hub_test_audio_request($db, 'task_artifact_retention', (string)$token['plain_token'], ['artifact_id' => (string)$artifactId, 'action' => 'unpin']);
            hub_test_assert($valid['status'] === 200 && $bearer['status'] === 200 && empty(hub_get_task_artifact($db, $artifactId)['pinned_at']), 'valid session CSRF must mutate while bearer-authenticated control does not require a cookie CSRF token');

            hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['REQUEST_URI'] = '/3waAIHub/api.php?mode=task_artifact_retention';
            $_SERVER['HTTP_AUTHORIZATION'] = '';
            $_SERVER['HTTP_HOST'] = 'hub.test';
            $_SERVER['SCRIPT_NAME'] = '/3waAIHub/api.php';
            $_POST = ['artifact_id' => (string)$artifactId, 'action' => 'pin'];
            $_GET = [];
            $_FILES = [];
            $localMissing = hub_gateway_dispatch($db, 'task_artifact_retention');
            $_POST['csrf_token'] = 'csrf-good';
            $localValid = hub_gateway_dispatch($db, 'task_artifact_retention');
            hub_test_assert($localMissing['status'] === 400 && $localValid['status'] === 200 && !empty(hub_get_task_artifact($db, $artifactId)['pinned_at']), 'localhost token bypass without a bearer credential must still require session CSRF before mutation');
        } finally {
            $_SESSION = $session;
        }
    });
});

hub_test('partial retention only scans stale task candidates', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/task_queue.php');
    hub_test_assert(!str_contains($source, "SELECT id FROM tasks ORDER BY id ASC"), 'partial retention must not scan every task forever');
});
