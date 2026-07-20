<?php
declare(strict_types=1);

function hub_test_callback_target(PDO $db, int $memberId, string $alias = 'default'): array
{
    $targetId = hub_register_callback_target($db, $memberId, $alias, 'https://8.8.8.8/callback');
    $stmt = $db->prepare('SELECT * FROM task_callback_targets WHERE id = :id');
    $stmt->execute([':id' => $targetId]);
    $target = $stmt->fetch();
    if (!is_array($target)) {
        throw new RuntimeException('Cannot load callback target fixture.');
    }

    return $target;
}

function hub_test_callback_task(PDO $db, int $memberId, int $targetId): int
{
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, '203.0.113.51', [
        'owner_member_id' => $memberId,
        'callback_target_id' => $targetId,
    ]);
    hub_finish_task_success($db, hub_get_task($db, $taskId) ?? [], ['ok' => true]);

    return $taskId;
}

hub_test('audio callbacks resolve only the member default or registered alias', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        $memberA = hub_create_api_member($db, 'Callback Owner');
        $memberB = hub_create_api_member($db, 'Callback Stranger');
        $tokenA = hub_create_api_token($db, $memberA, 'callback owner token', null, null);
        hub_test_audio_allow($db, [$tokenA], ['speech_transcribe', 'task_submit']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $default = hub_test_callback_target($db, $memberA);
        $alias = hub_test_callback_target($db, $memberA, 'reports');
        $other = hub_test_callback_target($db, $memberB, 'foreign');
        $source = hub_test_audio_source_artifact($db, $memberA, (int)$tokenA['token_id']);

        $defaultResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback' => 'true',
        ]);
        $defaultTask = hub_get_task($db, (int)(hub_test_audio_payload($defaultResponse)['task_id'] ?? 0));
        hub_test_assert((int)($defaultTask['callback_target_id'] ?? 0) === (int)$default['id'], 'callback=true must use the member default target');

        $aliasResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback_target' => 'reports',
        ]);
        $aliasTask = hub_get_task($db, (int)(hub_test_audio_payload($aliasResponse)['task_id'] ?? 0));
        hub_test_assert((int)($aliasTask['callback_target_id'] ?? 0) === (int)$alias['id'], 'callback alias must resolve within the owning member');

        $noneResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback' => 'false',
            'callback_target' => 'reports',
        ]);
        $noneTask = hub_get_task($db, (int)(hub_test_audio_payload($noneResponse)['task_id'] ?? 0));
        hub_test_assert(($noneTask['callback_target_id'] ?? null) === null, 'callback=false must not attach a target');

        $foreignResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback_target' => (string)$other['target_alias'],
        ]);
        hub_test_assert($foreignResponse['status'] === 404 && (hub_test_audio_payload($foreignResponse)['error'] ?? '') === 'callback_target_not_found', 'another member target must not be discoverable');

        hub_set_callback_target_enabled($db, $memberA, 'reports', false);
        $disabledResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback_target' => 'reports',
        ]);
        hub_test_assert($disabledResponse['status'] === 409 && (hub_test_audio_payload($disabledResponse)['error'] ?? '') === 'callback_target_disabled', 'disabled own target must fail explicitly');

        $invalidAliasResponse = hub_test_audio_request($db, 'speech_transcribe', (string)$tokenA['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'callback_target' => ['reports'],
        ]);
        hub_test_assert($invalidAliasResponse['status'] === 400 && (hub_test_audio_payload($invalidAliasResponse)['error'] ?? '') === 'forbidden_task_control', 'callback target aliases must be scalar values');

        foreach ([
            ['callback_url' => 'https://attacker.invalid/'],
            ['callback_secret' => 'attacker-secret'],
            ['callback_target_id' => (string)$default['id']],
            ['callback' => 'https://attacker.invalid/'],
            ['callback_target' => 'default'],
        ] as $controls) {
            $before = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
            $response = hub_test_audio_request($db, 'task_submit', (string)$tokenA['plain_token'], ['task_type' => 'demo_task'] + $controls);
            hub_test_assert($response['status'] === 400 && (hub_test_audio_payload($response)['error'] ?? '') === 'forbidden_task_control' && (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $before, 'generic task submit must reject every callback control without persisting it');
        }
        $before = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['task_type' => 'demo_task', 'callback' => 'true'];
        $internal = hub_api_task_submit($db, ['internal_task' => true]);
        hub_test_assert($internal['status'] === 400 && (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $before, 'internal generic task submission must not bypass callback controls');
    });
});

hub_test('callback target registration rejects unsafe endpoints', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Endpoint Owner');
    foreach ([
        'http://8.8.8.8/callback',
        'https://user:pass@8.8.8.8/callback',
        'https://localhost/callback',
        'https://127.0.0.1/callback',
        'https://10.0.0.1/callback',
        'https://169.254.1.1/callback',
        'https://8.8.8.8:8443/callback',
    ] as $index => $url) {
        hub_test_assert(hub_test_throws(static fn (): int => hub_register_callback_target($db, $memberId, 'unsafe_' . $index, $url)), 'unsafe callback URL must be rejected: ' . $url);
    }
    foreach (['2001:2::1', '64:ff9b:1::7f00:1', '::127.0.0.1'] as $ip) {
        hub_test_assert(!hub_callback_ip_is_public($ip), 'special IPv6 callback target must be rejected: ' . $ip);
    }
    hub_test_assert(hub_callback_ip_is_public('2606:4700:4700::1111'), 'normal global-unicast IPv6 must remain usable for callback targets');
});

hub_test('callback outbox preserves raw payload signs it and retries safely', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Delivery Owner');
    $target = hub_test_callback_target($db, $memberId);
    $taskId = hub_test_callback_task($db, $memberId, (int)$target['id']);
    $path = hub_task_result_dir($taskId) . '/private-result.json';
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0775, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Cannot create callback artifact fixture.');
    }
    file_put_contents($path, '{"secret":"artifact-body"}', LOCK_EX);
    $artifactId = hub_register_task_artifact($db, $taskId, 'private-result.json', $path, 'application/json');
    $dangerousPath = '/srv/private/runner.stderr';
    $db->prepare('UPDATE tasks SET error_code = :error_code WHERE id = :id')->execute([
        ':error_code' => 'runner_failed:' . $dangerousPath,
        ':id' => $taskId,
    ]);
    $db->prepare('UPDATE task_artifacts SET artifact_type = :type, mime_type = :mime_type, sha256 = :sha256 WHERE id = :id')->execute([
        ':type' => $dangerousPath,
        ':mime_type' => 'application/' . $dangerousPath,
        ':sha256' => 'secret:' . $dangerousPath,
        ':id' => $artifactId,
    ]);

    hub_enqueue_task_callback_delivery($db, $taskId);
    hub_enqueue_task_callback_delivery($db, $taskId);
    $delivery = $db->query('SELECT * FROM task_callback_deliveries')->fetch();
    hub_test_assert(is_array($delivery) && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 1, 'outbox enqueue must be durable and idempotent');
    $payload = json_decode((string)$delivery['payload_json'], true);
    hub_test_assert(is_array($payload) && array_keys($payload) === ['task', 'artifacts'] && array_keys($payload['task'] ?? []) === ['id', 'state', 'completed_at', 'error_code'], 'callback payload must expose only the published task contract');
    hub_test_assert(!str_contains((string)$delivery['payload_json'], $path) && !str_contains((string)$delivery['payload_json'], $dangerousPath) && !str_contains((string)$delivery['payload_json'], 'artifact-body') && !str_contains((string)$delivery['payload_json'], (string)$target['signing_secret']), 'callback payload must not expose paths, artifact bodies, runner errors, or secrets');

    $sent = [];
    $startedAt = 1700000000;
    $db->prepare('UPDATE task_callback_deliveries SET next_attempt_at = :next_attempt_at')->execute([
        ':next_attempt_at' => hub_callback_time($startedAt),
    ]);
    $first = hub_callback_process_next($db, static function (array $claimed, array $headers) use (&$sent): array {
        $sent = ['delivery' => $claimed, 'headers' => $headers];
        return ['status' => 500];
    }, $startedAt);
    hub_test_assert(($first['state'] ?? '') === 'retry' && ($sent['delivery']['payload_json'] ?? '') === $delivery['payload_json'], 'worker must send the stored raw payload once');
    hub_test_assert(($sent['headers']['X-AIHub-Signature'] ?? '') === 'sha256=' . hash_hmac('sha256', (string)$delivery['payload_json'], (string)$target['signing_secret']), 'worker signature must cover exact stored payload bytes');
    hub_test_assert(($sent['headers']['Content-Type'] ?? '') === 'application/json' && ($sent['headers']['X-AIHub-Event'] ?? '') === 'task.completed' && ($sent['headers']['X-AIHub-Delivery'] ?? '') === (string)$delivery['delivery_id'] && ($sent['headers']['X-AIHub-Timestamp'] ?? '') === (string)$startedAt, 'worker headers must identify the immutable delivery');

    hub_test_assert(hub_callback_process_next($db, static fn (): array => ['status' => 204], $startedAt + 29) === null, 'retry must wait 30 seconds after first failure');
    $second = hub_callback_process_next($db, static fn (): array => ['status' => 204], $startedAt + 30);
    $afterSuccess = $db->query('SELECT * FROM task_callback_deliveries')->fetch();
    hub_test_assert(($second['state'] ?? '') === 'delivered' && !empty($afterSuccess['delivered_at']) && (int)$afterSuccess['attempt_count'] === 2, 'only a 2xx result must mark delivery complete');
    hub_test_assert(hub_callback_process_next($db, static fn (): array => ['status' => 204], $startedAt + 9999) === null, 'delivered callbacks must never be sent again');
});

hub_test('callback worker limits attempts and atomically reserves claims', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Retry Owner');
    $target = hub_test_callback_target($db, $memberId);
    $taskId = hub_test_callback_task($db, $memberId, (int)$target['id']);
    hub_enqueue_task_callback_delivery($db, $taskId);
    $now = 1700100000;
    $db->prepare('UPDATE task_callback_deliveries SET next_attempt_at = :next_attempt_at')->execute([
        ':next_attempt_at' => hub_callback_time($now),
    ]);
    $firstClaim = hub_callback_claim_due_delivery($db, $now);
    $otherDb = hub_db();
    hub_test_assert($firstClaim !== null && hub_callback_claim_due_delivery($otherDb, $now) === null, 'only one worker may reserve a due delivery');
    hub_callback_finalize_delivery($db, $firstClaim, ['status' => 500], $now);

    foreach ([$now + 30, $now + 150, $now + 750, $now + 4350] as $attemptAt) {
        hub_callback_process_next($db, static fn (): array => ['status' => 500], $attemptAt);
    }
    $row = $db->query('SELECT * FROM task_callback_deliveries')->fetch();
    hub_test_assert((int)$row['attempt_count'] === 5 && $row['next_attempt_at'] === null && empty($row['delivered_at']), 'failed callbacks must stop after five total attempts');
    hub_test_assert(hub_callback_process_next($db, static fn (): array => ['status' => 204], $now + 99999) === null, 'exhausted callbacks must not be sent again');
});

hub_test('callback claims survive crashes and reject stale finalizers', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Claim Recovery Owner');
    $target = hub_test_callback_target($db, $memberId);
    $taskId = hub_test_callback_task($db, $memberId, (int)$target['id']);
    hub_enqueue_task_callback_delivery($db, $taskId);
    $now = 1700300000;
    $db->prepare('UPDATE task_callback_deliveries SET next_attempt_at = :next_attempt_at')->execute([
        ':next_attempt_at' => hub_callback_time($now),
    ]);

    for ($claimAt = $now; $claimAt < $now + (5 * 121); $claimAt += 121) {
        $abandoned = hub_callback_claim_due_delivery($db, $claimAt, 120);
        hub_test_assert($abandoned !== null && (int)$abandoned['attempt_count'] === 0 && !empty($abandoned['claim_token']), 'abandoned callback claim must not consume an attempt');
    }
    $reclaimed = hub_callback_claim_due_delivery($db, $now + (5 * 121), 120);
    hub_test_assert($reclaimed !== null && (int)$reclaimed['attempt_count'] === 0, 'expired callback claims must remain deliverable after repeated worker crashes');

    $newer = hub_callback_claim_due_delivery($db, $now + (5 * 121) + 121, 120);
    hub_test_assert($newer !== null && (string)$newer['claim_token'] !== (string)$reclaimed['claim_token'], 'an expired claim must receive a new token');
    hub_test_assert(!hub_callback_finalize_delivery($db, $reclaimed, ['status' => 500], $now + (5 * 121) + 121), 'stale callback claim token must not finalize a newer claim');
    hub_test_assert(hub_callback_finalize_delivery($db, $newer, ['status' => 204], $now + (5 * 121) + 121), 'current callback claim token must finalize delivery');
    $row = $db->query('SELECT attempt_count, delivered_at, claim_token, claim_expires_at FROM task_callback_deliveries')->fetch();
    hub_test_assert((int)$row['attempt_count'] === 1 && !empty($row['delivered_at']) && $row['claim_token'] === null && $row['claim_expires_at'] === null, 'only the completed send outcome must increment attempts and clear its claim');
});

hub_test('disabled callback targets are finalized without a network send', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Disabled Owner');
    $target = hub_test_callback_target($db, $memberId);
    $taskId = hub_test_callback_task($db, $memberId, (int)$target['id']);
    hub_enqueue_task_callback_delivery($db, $taskId);
    hub_set_callback_target_enabled($db, $memberId, 'default', false);
    $db->prepare('UPDATE task_callback_deliveries SET next_attempt_at = :next_attempt_at')->execute([
        ':next_attempt_at' => hub_callback_time(1700200000),
    ]);
    $called = false;
    $result = hub_callback_process_next($db, static function () use (&$called): array {
        $called = true;
        return ['status' => 204];
    }, 1700200000);
    $row = $db->query('SELECT * FROM task_callback_deliveries')->fetch();
    hub_test_assert(($result['state'] ?? '') === 'disabled' && !$called && (int)$row['attempt_count'] === 5 && ($row['last_error'] ?? '') === 'callback_target_disabled', 'disabled target must not receive a callback or retain retry work');
});

hub_test('callback outbox ignores a target removed after task admission', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Callback Removed Target Owner');
    $target = hub_test_callback_target($db, $memberId);
    $taskId = hub_test_callback_task($db, $memberId, (int)$target['id']);
    $db->prepare('DELETE FROM task_callback_targets WHERE id = :id')->execute([':id' => (int)$target['id']]);

    hub_test_assert(hub_enqueue_task_callback_delivery($db, $taskId) === null && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries')->fetchColumn() === 0, 'terminal callback enqueue must be a safe no-op after its target is removed');
});

hub_test('trusted callback target provisioning keeps the signing secret off the public surface', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Trusted Callback Target Owner');
    $secret = 'trusted-callback-secret-0123456789abcdef';
    $targetId = hub_register_callback_target_from_trusted_config($db, $memberId, 'trusted', 'https://8.8.8.8/callback', $secret);
    $stored = $db->query('SELECT signing_secret FROM task_callback_targets WHERE id = ' . $targetId)->fetchColumn();
    hub_test_assert($stored === $secret, 'trusted provisioning must persist the configured shared signing secret');

    $script = (string)file_get_contents(HUB_ROOT . '/scripts/register_callback_target.php');
    hub_test_assert(str_contains($script, "getenv('AIHUB_CALLBACK_SIGNING_SECRET')") && !str_contains($script, '--secret') && !str_contains($script, '$_POST') && !str_contains($script, '$_GET'), 'operator registration must read the secret only from trusted environment configuration');

    $output = [];
    $exitCode = 0;
    exec('env ' . escapeshellarg('AIHUB_TEST_DB=' . HUB_DB_PATH) . ' '
        . escapeshellarg('AIHUB_CALLBACK_OWNER_MEMBER_ID=' . $memberId) . ' '
        . escapeshellarg('AIHUB_CALLBACK_TARGET_ALIAS=cli-target') . ' '
        . escapeshellarg('AIHUB_CALLBACK_URL=https://8.8.8.8/callback') . ' '
        . escapeshellarg('AIHUB_CALLBACK_SIGNING_SECRET=' . $secret) . ' '
        . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HUB_ROOT . '/scripts/register_callback_target.php') . ' 2>&1', $output, $exitCode);
    $cliTarget = $db->query("SELECT signing_secret FROM task_callback_targets WHERE target_alias = 'cli-target'")->fetchColumn();
    hub_test_assert($exitCode === 0 && $cliTarget === $secret && !str_contains(implode("\n", $output), $secret), 'operator registration must provision a shared secret without printing it');
});

hub_test('callback worker requires an upgraded schema and cron runs it', function (): void {
    $path = tempnam(sys_get_temp_dir(), '3waaihub_callback_schema_');
    if ($path === false) {
        throw new RuntimeException('Cannot create callback schema fixture.');
    }
    try {
        $fixture = new PDO('sqlite:' . $path);
        $fixture->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        hub_migrate($fixture);
        $fixture->exec('DROP INDEX idx_task_callback_deliveries_claim');
        $fixture->exec('ALTER TABLE task_callback_deliveries DROP COLUMN claim_token');
        $fixture->exec('ALTER TABLE task_callback_deliveries DROP COLUMN claim_expires_at');
        unset($fixture);

        $output = [];
        $exitCode = 0;
        exec('env ' . escapeshellarg('AIHUB_TEST_DB=' . $path) . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(HUB_ROOT . '/scripts/callback_worker.php') . ' 2>&1', $output, $exitCode);
        $message = implode("\n", $output);
        hub_test_assert($exitCode === 1 && str_contains($message, 'schema_upgrade_required:') && str_contains($message, 'task_callback_deliveries.claim_token') && str_contains($message, 'task_callback_deliveries.claim_expires_at') && str_contains($message, 'php scripts/init_db.php'), 'callback worker must direct an old callback schema operator to init_db');
        hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/crontab/1min.sh'), 'php scripts/callback_worker.php --limit="$CALLBACK_WORKER_LIMIT"'), 'cron must invoke the callback worker');
    } finally {
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

hub_test('callback HTTP transport fails closed when resolved-IP pinning is unavailable', function (): void {
    $delivery = [
        'callback_url' => 'https://127.0.0.1/callback',
        'payload_json' => '{}',
        'event_type' => 'task.completed',
        'delivery_id' => 'cb_test_pinning',
        'signing_secret' => 'test-secret',
    ];
    $attempted = false;
    $result = hub_callback_send_http($delivery, [], static function () use (&$attempted): array {
        $attempted = true;
        return ['status' => 204];
    }, false);
    hub_test_assert(($result['error'] ?? '') === 'callback_network_error' && !$attempted, 'callback transport must not send when cURL cannot pin the resolved address');

    $delivery['callback_url'] = 'https://8.8.8.8/callback';
    $configured = false;
    $result = hub_callback_send_http($delivery, [], null, null, static function ($handle, array $options) use (&$configured): bool {
        $configured = isset($options[CURLOPT_RESOLVE]) && isset($options[CURLOPT_WRITEFUNCTION]) && !isset($options[CURLOPT_RETURNTRANSFER]);
        return false;
    });
    hub_test_assert($configured && ($result['error'] ?? '') === 'callback_network_error', 'callback transport must stop before execution when cURL rejects its pinning options');
    $source = (string)file_get_contents(HUB_ROOT . '/app/task_callbacks.php');
    hub_test_assert(!str_contains($source, 'CURLOPT_RETURNTRANSFER') && str_contains($source, 'CURLOPT_WRITEFUNCTION'), 'callback HTTP transport must discard response bodies instead of buffering them');
});
