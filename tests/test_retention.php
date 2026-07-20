<?php
declare(strict_types=1);

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
