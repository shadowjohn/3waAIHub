<?php
declare(strict_types=1);

hub_test('PhaseP-1 command jobs keep progress metadata and status payload tails logs', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');

    $jobId = hub_enqueue_command_job($db, 'service_build', (int)$service['id'], ['reason' => 'test'], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    hub_test_assert((int)$job['progress'] === 0, 'queued job progress must default to 0');
    hub_test_assert($job['stage'] === 'queued', 'queued job stage must default to queued');

    hub_update_command_job_progress($db, $jobId, 'docker_build', 42, 'Installing Python requirements');
    $job = hub_get_command_job($db, $jobId);
    hub_prepare_command_job_logs($db, $job);
    $job = hub_get_command_job($db, $jobId);
    file_put_contents((string)$job['stdout_path'], "line 1\nline 2\n");
    file_put_contents((string)$job['stderr_path'], "warn 1\n");

    $payload = hub_command_job_status_payload($db, $jobId);
    hub_test_assert($payload['status'] === 'queued', 'payload status mismatch');
    hub_test_assert($payload['progress'] === 42, 'payload progress mismatch');
    hub_test_assert($payload['stage'] === 'docker_build', 'payload stage mismatch');
    hub_test_assert($payload['current_message'] === 'Installing Python requirements', 'payload message mismatch');
    hub_test_assert(str_contains($payload['stdout_tail'], 'line 2'), 'stdout tail missing');
    hub_test_assert(str_contains($payload['stderr_tail'], 'warn 1'), 'stderr tail missing');
});

hub_test('PhaseP-1 generated compose has fixed image tag and start/build commands are split', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-main',
        'name' => 'PP-OCRv5 OCR Main',
        'mode' => 'ocr',
        'port_mode' => 'auto',
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    $compose = (string)file_get_contents(hub_path($service['compose_file']));

    hub_test_assert(str_contains($compose, 'image: 3waaihub-ocr-main:0.1.0'), 'generated compose must include fixed image tag');
    hub_test_assert(hub_service_image_tag($service) === '3waaihub-ocr-main:0.1.0', 'service image tag mismatch');
    $asr = hub_install_pack($db, 'whisper-asr', ['service_key' => 'asr-main'])['service'];
    hub_test_assert(hub_service_image_tag($asr) === '3waaihub/whisper-asr:0.1.1', 'Whisper image checks must match its generated compose image');
    hub_test_assert(hub_compose_command($service, ['build', '--progress=plain']) === hub_service_build_command($service), 'build command must use plain progress');
    hub_test_assert(!in_array('--build', hub_service_start_command($service), true), 'start command must not rebuild');
});

hub_test('PhaseP-1 Compose host commands do not inherit container-only HOME', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-host-env']);
    $service = $installed['service'];

    hub_test_assert((hub_compose_env($service)['HOME'] ?? null) === '/cache/voxcpm2/home', 'TTS container HOME must remain in its generated environment');
    $hostEnvironment = hub_docker_command_environment();
    hub_test_assert(($hostEnvironment['HOME'] ?? null) === HUB_DATA_DIR . '/docker-cli', 'Docker host command must use the Hub-controlled CLI home');
    hub_test_assert(($hostEnvironment['DOCKER_CONFIG'] ?? null) === HUB_DATA_DIR . '/docker-cli', 'Docker host command must keep config outside the container HOME');
});

hub_test('PhaseP-1 default setting auto-builds missing images', function (): void {
    $db = hub_test_reset_db();

    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_AUTO_BUILD_MISSING_IMAGE') === '1', 'auto build missing image default must be enabled');
    hub_test_assert(hub_is_valid_job_action('service_build'), 'service_build must be allowlisted');
});

hub_test('PhaseP-1 restart-required service builds a missing local image before recreate', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $db->prepare('UPDATE services SET restart_required = 1 WHERE id = :id')->execute([':id' => (int)$service['id']]);
    $service = hub_get_service($db, (int)$service['id']);

    $dir = sys_get_temp_dir() . '/3waaihub_restart_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    $log = $dir . '/docker.log';
    $state = $dir . '/image-built';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$MOCK_DOCKER_LOG"
if [ "$1" = "image" ] && [ "$2" = "inspect" ]; then
  [ -f "$MOCK_DOCKER_STATE" ] && exit 0
  exit 1
fi
case " $* " in
  *" build "*) touch "$MOCK_DOCKER_STATE"; exit 0 ;;
  *" up "*) [ -f "$MOCK_DOCKER_STATE" ] && exit 0; echo "pull access denied" >&2; exit 1 ;;
  *" ps "*) echo "running"; exit 0 ;;
esac
exit 0
SH
    );
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');
    try {
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        putenv('MOCK_DOCKER_LOG=' . $log);
        putenv('MOCK_DOCKER_STATE=' . $state);

        $result = hub_restart_service($db, $service);
        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        hub_test_assert($result['exit_code'] === 0, 'restart-required service must build its missing local image before recreate');
        hub_test_assert(count($commands) >= 3 && str_contains($commands[1], ' build ') && str_contains($commands[2], ' up '), 'restart must run build before compose up');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        putenv('MOCK_DOCKER_LOG');
        putenv('MOCK_DOCKER_STATE');
        @unlink($bin . '/docker');
        @unlink($log);
        @unlink($state);
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 hello compose keeps legacy service name to avoid orphan conflict', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_key($db, 'hello-main');
    hub_test_assert($service !== null, 'hello-main missing');
    $compose = (string)file_get_contents(hub_path($service['compose_file']));

    hub_test_assert(str_contains($compose, "\n  hello:\n"), 'hello-main compose service must remain hello');
});

hub_test('Windows service build and start reject Linux Docker before runtime and port side effects', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only service side-effect contract.');
    }

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'ocr-ppocrv5', [
        'service_key' => 'ocr-windows-gate',
        'name' => 'OCR Windows Gate',
        'mode' => 'ocr_windows_gate',
        'port_mode' => 'auto',
        'environment' => 'production',
    ]);
    $service = $installed['service'];
    $db->exec('UPDATE services SET local_port = NULL WHERE id = ' . (int)$service['id']);
    $service = hub_get_service($db, (int)$service['id']);
    $composePath = hub_path((string)$service['compose_file']);
    $composeBefore = "# unsupported gate marker\n";
    file_put_contents($composePath, $composeBefore);
    $buildJobId = hub_enqueue_command_job($db, 'service_build', (int)$service['id'], [], null, '127.0.0.1');
    $startJobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');

    foreach ([
        hub_build_service($db, $service, hub_get_command_job($db, $buildJobId)),
        hub_start_service_with_job($db, $service, hub_get_command_job($db, $startJobId)),
    ] as $result) {
        hub_test_assert($result['exit_code'] === 78, 'Windows service action must return unsupported exit 78');
        hub_test_assert($result['error_code'] === 'platform_target_unsupported', 'Windows service action error code mismatch');
    }

    $after = hub_get_service($db, (int)$service['id']);
    hub_test_assert($after['local_port'] === null, 'unsupported service start must not allocate a port');
    hub_test_assert((string)file_get_contents($composePath) === $composeBefore, 'unsupported service action must not rewrite compose');
    hub_test_assert(hub_get_command_job($db, $buildJobId)['stage'] === 'queued', 'unsupported build must not claim preparation progress');
    hub_test_assert(hub_get_command_job($db, $startJobId)['stage'] === 'queued', 'unsupported start must not claim preparation progress');
});

hub_test('Windows direct service status stop restart and logs reject before Docker', function (): void {
    if (hub_platform_id() !== 'windows') {
        hub_test_skip('Windows-only direct service gate contract.');
    }

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    foreach ([
        hub_refresh_service_status($db, $service),
        hub_stop_service($db, $service),
        hub_restart_service($db, $service),
        hub_tail_service_logs($db, $service),
    ] as $result) {
        hub_test_assert(is_array($result), 'unsupported direct service action must return a result contract');
        hub_test_assert($result['exit_code'] === 78, 'unsupported direct service action exit mismatch');
        hub_test_assert($result['error_code'] === 'platform_target_unsupported', 'unsupported direct service action error code mismatch');
    }
});

hub_test('PhaseP-1 service removal action and active-job guard are explicit', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    hub_test_assert(hub_is_valid_job_action('service_remove'), 'service_remove must be allowlisted');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === false, 'fresh service must be idle');
    $jobId = hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id']) === true, 'queued command must make service busy');
    hub_test_assert(hub_service_has_active_command_job($db, (int)$service['id'], $jobId) === false, 'current removal job must be excludable from its own busy check');
});

hub_test('PhaseP-1 Marketplace request upgrades service retention schemas before queueing removal', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $ownerMemberId = hub_create_api_member($db, 'Marketplace Migration Owner');
    $db->exec('DROP TABLE playground_tts_artifacts');
    $db->exec(<<<'SQL'
CREATE TABLE playground_tts_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    service_id INTEGER NOT NULL,
    owner_member_id INTEGER NOT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_id, filename),
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
)
SQL);
    $db->prepare(
        'INSERT INTO playground_tts_artifacts (filename, service_id, owner_member_id, request_id, created_at, updated_at)
         VALUES (:filename, :service_id, :owner_member_id, :request_id, :created_at, :updated_at)'
    )->execute([
        ':filename' => 'marketplace_legacy.wav',
        ':service_id' => (int)$service['id'],
        ':owner_member_id' => $ownerMemberId,
        ':request_id' => 'req_marketplace_legacy',
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);
    $artifactId = (int)$db->lastInsertId();
    $db->exec('DROP TABLE service_logs');
    $db->exec(<<<'SQL'
CREATE TABLE service_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    output TEXT NOT NULL,
    exit_code INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
)
SQL);
    $db->prepare(
        'INSERT INTO service_logs (service_id, action, output, exit_code, created_at)
         VALUES (:service_id, :action, :output, :exit_code, :created_at)'
    )->execute([
        ':service_id' => (int)$service['id'],
        ':action' => 'marketplace_legacy',
        ':output' => 'preserve',
        ':exit_code' => 0,
        ':created_at' => hub_now(),
    ]);
    $serviceLogId = (int)$db->lastInsertId();

    $script = "define('HUB_TESTING', true);"
        . 'require ' . var_export(HUB_ROOT . '/app/bootstrap.php', true) . ';'
        . "\$_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];"
        . "\$_SERVER = ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.80', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];"
        . "\$_GET = ['view' => 'services'];"
        . "\$_POST = ['csrf_token' => 'test', 'service_id' => '" . (int)$service['id'] . "', 'action' => 'remove'];"
        . 'require ' . var_export(HUB_ROOT . '/admin/marketplace.php', true) . ';';
    $result = hub_run_command([PHP_BINARY, '-r', $script], 30, [
        'AIHUB_TEST_DB' => (string)getenv('AIHUB_TEST_DB'),
        'AIHUB_TEST_DATA_DIR' => (string)getenv('AIHUB_TEST_DATA_DIR'),
    ]);
    $payload = json_decode($result['stdout'], true);

    hub_test_assert($result['exit_code'] === 0 && is_array($payload) && ($payload['ok'] ?? false) === true, 'Marketplace must queue removal after upgrading legacy schemas');
    $artifactForeignKeys = $db->query('PRAGMA foreign_key_list(playground_tts_artifacts)')->fetchAll();
    $artifactServiceKey = array_values(array_filter($artifactForeignKeys, static fn (array $key): bool => $key['from'] === 'service_id'))[0] ?? null;
    $serviceLogForeignKeys = $db->query('PRAGMA foreign_key_list(service_logs)')->fetchAll();
    $serviceLogKey = array_values(array_filter($serviceLogForeignKeys, static fn (array $key): bool => $key['from'] === 'service_id'))[0] ?? null;
    hub_test_assert(($artifactServiceKey['on_delete'] ?? '') === 'SET NULL' && ($serviceLogKey['on_delete'] ?? '') === 'SET NULL', 'Marketplace must migrate retention references before removal queueing');

    $db->prepare('DELETE FROM services WHERE id = :id')->execute([':id' => (int)$service['id']]);
    $artifact = $db->query('SELECT service_id FROM playground_tts_artifacts WHERE id = ' . $artifactId)->fetch();
    $serviceLog = $db->query('SELECT service_id FROM service_logs WHERE id = ' . $serviceLogId)->fetch();
    hub_test_assert($artifact !== false && $artifact['service_id'] === null && $serviceLog !== false && $serviceLog['service_id'] === null, 'Marketplace-upgraded retention rows must survive service deletion');
});

hub_test('PhaseP-1 service removal queue admission is exclusive', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
    $removeThenStartRejected = false;
    try {
        hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    } catch (RuntimeException) {
        $removeThenStartRejected = true;
    }
    hub_test_assert($removeThenStartRejected, 'service start must not queue behind removal');

    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
    $startThenRemoveRejected = false;
    try {
        hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
    } catch (RuntimeException) {
        $startThenRemoveRejected = true;
    }
    hub_test_assert($startThenRemoveRejected, 'service removal must not queue behind another service command');

    hub_test_assert(hub_enqueue_command_job($db, 'env_probe', null, [], null, '127.0.0.1') > 0, 'service-less commands must remain queueable');
});

hub_test('PhaseP-1 stale service removal cannot delete a service updated after queueing', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    $args = json_decode((string)($job['args_json'] ?? '{}'), true);
    hub_test_assert(
        is_array($args) && ($args['service_updated_at'] ?? '') === (string)$service['updated_at'],
        'service removal queueing must capture the current service version'
    );

    $db->prepare('UPDATE services SET updated_at = :updated_at WHERE id = :id')->execute([
        ':updated_at' => '2099-01-01 00:00:00',
        ':id' => (int)$service['id'],
    ]);
    $updated = hub_get_service($db, (int)$service['id']);
    $result = hub_remove_service($db, $updated, $job);
    $composePath = hub_path((string)$updated['compose_file']);

    hub_test_assert(($result['error_code'] ?? '') === 'service_changed', 'stale removal must stop before Docker or registration deletion');
    hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'service updated after queueing must remain registered');
    hub_test_assert(file_exists($composePath) && file_exists(dirname($composePath) . '/.env'), 'service updated after queueing must keep its regenerated runtime files');
});

hub_test('PhaseP-1 legacy removal jobs without a service snapshot fail safe', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
    $db->prepare('UPDATE command_jobs SET args_json = :args_json WHERE id = :id')->execute([
        ':args_json' => '{}',
        ':id' => $jobId,
    ]);

    $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
    hub_test_assert(($result['error_code'] ?? '') === 'service_changed', 'legacy removal without a version snapshot must require an explicit requeue');
    hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'legacy removal without a snapshot must preserve the service registration');
});

hub_test('PhaseP-1 service removal stops only an idle stopped service and preserves unrelated files', function (): void {
    $dir = sys_get_temp_dir() . '/3waaihub_remove_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    $log = $dir . '/docker.log';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$MOCK_DOCKER_LOG"
case " $* " in
  *" down "*)
    [ -f "$MOCK_COMPOSE_PATH" ] && [ -f "$MOCK_ENV_PATH" ] || exit 12
    [ "${MOCK_DOCKER_DOWN_FAILURE:-0}" = 1 ] && exit 1
    if [ "${MOCK_SWAP_COMPOSE_AFTER_DOWN:-0}" = 1 ]; then
      mv "$MOCK_COMPOSE_PATH" "$MOCK_COMPOSE_PATH.after-down" || exit 13
      ln -s "$MOCK_COMPOSE_PATH.after-down" "$MOCK_COMPOSE_PATH" || exit 14
    fi
    ;;
esac
exit 0
SH
    );
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');

    try {
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        putenv('MOCK_DOCKER_LOG=' . $log);

        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        hub_test_assert($service !== null, 'hello service missing');
        $composePath = hub_path((string)$service['compose_file']);
        $envPath = dirname($composePath) . '/.env';
        $artifactPath = dirname($composePath) . '/artifact.keep';
        $pack = hub_get_pack((string)$service['pack_id']);
        hub_test_assert($pack !== null, 'hello HubPack missing');
        file_put_contents($artifactPath, 'keep');
        $ownerMemberId = hub_create_api_member($db, 'Removal Artifact Owner');
        $artifactMapping = $db->prepare(
            'INSERT INTO playground_tts_artifacts (filename, service_id, owner_member_id, request_id, created_at, updated_at)
             VALUES (:filename, :service_id, :owner_member_id, :request_id, :created_at, :updated_at)'
        );
        $artifactMapping->execute([
            ':filename' => 'tts_preserved.wav',
            ':service_id' => (int)$service['id'],
            ':owner_member_id' => $ownerMemberId,
            ':request_id' => 'req_service_removal',
            ':created_at' => hub_now(),
            ':updated_at' => hub_now(),
        ]);
        $artifactMappingId = (int)$db->lastInsertId();
        $serviceLog = $db->prepare(
            'INSERT INTO service_logs (service_id, action, output, exit_code, created_at)
             VALUES (:service_id, :action, :output, :exit_code, :created_at)'
        );
        $serviceLog->execute([
            ':service_id' => (int)$service['id'],
            ':action' => 'history',
            ':output' => 'preserve',
            ':exit_code' => 0,
            ':created_at' => hub_now(),
        ]);
        $serviceLogId = (int)$db->lastInsertId();
        hub_test_assert(hub_service_generated_runtime_files($db, $service) === [$composePath, $envPath], 'generated runtime files must match the service compose and env paths');
        $unmanaged = $service;
        $unmanaged['service_key'] = '';
        hub_test_assert(hub_service_generated_runtime_files($db, $unmanaged) === null, 'missing service key must not produce a generated runtime cleanup target');
        putenv('MOCK_COMPOSE_PATH=' . $composePath);
        putenv('MOCK_ENV_PATH=' . $envPath);
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        hub_test_assert($result['exit_code'] === 0, 'idle stopped service removal must succeed');
        hub_test_assert(count($commands) === 1 && str_contains($commands[0], ' down '), 'service removal must run docker compose down');
        hub_test_assert(hub_get_service($db, (int)$service['id']) === null, 'removed service must be deleted from registration');
        hub_test_assert(!file_exists($composePath) && !file_exists($envPath), 'generated compose and env files must be deleted');
        hub_test_assert(file_exists($artifactPath) && is_dir((string)$pack['dir']), 'service artifact and HubPack must remain');
        $mapping = $db->query('SELECT service_id, owner_member_id FROM playground_tts_artifacts WHERE id = ' . $artifactMappingId)->fetch();
        hub_test_assert($mapping !== false && $mapping['service_id'] === null && (int)$mapping['owner_member_id'] === $ownerMemberId, 'playground artifact mapping must survive with its service reference cleared');
        $serviceLog = $db->query('SELECT service_id, action, output, exit_code FROM service_logs WHERE id = ' . $serviceLogId)->fetch();
        hub_test_assert($serviceLog !== false && $serviceLog['service_id'] === null && $serviceLog['action'] === 'history' && $serviceLog['output'] === 'preserve' && (int)$serviceLog['exit_code'] === 0, 'service history must survive with its service reference cleared');

        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        hub_update_service_status($db, (int)$service['id'], 'running');
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
        $result = hub_remove_service($db, hub_get_service($db, (int)$service['id']), hub_get_command_job($db, $jobId));

        hub_test_assert(($result['error_code'] ?? '') === 'service_not_stopped', 'running service removal must be rejected');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'running service must remain registered');
        hub_test_assert(count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 1, 'Docker must not run for a running service');

        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        hub_enqueue_command_job($db, 'service_start', (int)$service['id'], [], null, '127.0.0.1');
        $result = hub_remove_service($db, $service, ['id' => 0]);

        hub_test_assert(($result['error_code'] ?? '') === 'service_job_active', 'busy service removal must be rejected');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'busy service must remain registered');
        hub_test_assert(count(file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) === 1, 'Docker must not run for a busy service');

        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        $composePath = hub_path((string)$service['compose_file']);
        $envPath = dirname($composePath) . '/.env';
        $artifactPath = dirname($composePath) . '/artifact.keep';
        file_put_contents($artifactPath, 'keep');
        putenv('MOCK_COMPOSE_PATH=' . $composePath);
        putenv('MOCK_ENV_PATH=' . $envPath);
        putenv('MOCK_DOCKER_DOWN_FAILURE=1');
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));

        hub_test_assert($result['exit_code'] !== 0, 'failed docker down must fail service removal');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'failed docker down must keep the service registration');
        hub_test_assert(file_exists($composePath) && file_exists($envPath) && file_exists($artifactPath), 'failed docker down must keep generated and artifact files');

        putenv('MOCK_DOCKER_DOWN_FAILURE=0');
        putenv('MOCK_SWAP_COMPOSE_AFTER_DOWN=1');
        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        $composePath = hub_path((string)$service['compose_file']);
        $envPath = dirname($composePath) . '/.env';
        putenv('MOCK_COMPOSE_PATH=' . $composePath);
        putenv('MOCK_ENV_PATH=' . $envPath);
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));

        hub_test_assert(($result['error_code'] ?? '') === 'service_runtime_unmanaged', 'runtime paths changed during docker down must block cleanup');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'runtime paths changed during docker down must preserve registration');
        hub_test_assert(is_link($composePath) && file_exists($envPath), 'runtime paths changed during docker down must remain untouched');
        unlink($composePath);
        rename($composePath . '.after-down', $composePath);
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        if (isset($composePath) && is_file($composePath . '.after-down')) {
            if (is_link($composePath)) {
                unlink($composePath);
            }
            if (!file_exists($composePath)) {
                rename($composePath . '.after-down', $composePath);
            }
        }
        putenv('MOCK_DOCKER_LOG');
        putenv('MOCK_COMPOSE_PATH');
        putenv('MOCK_ENV_PATH');
        putenv('MOCK_DOCKER_DOWN_FAILURE');
        putenv('MOCK_SWAP_COMPOSE_AFTER_DOWN');
        @unlink($bin . '/docker');
        @unlink($log);
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 service removal keeps generated files when registration deletion fails', function (): void {
    $dir = sys_get_temp_dir() . '/3waaihub_remove_delete_failure_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nexit 0\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');

    try {
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        $composePath = hub_path((string)$service['compose_file']);
        $envPath = dirname($composePath) . '/.env';
        $db->exec(
            "CREATE TRIGGER fail_service_removal_delete
             BEFORE DELETE ON services
             WHEN OLD.id = " . (int)$service['id'] . "
             BEGIN
                 SELECT RAISE(ABORT, 'forced service deletion failure');
             END"
        );
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        try {
            $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
        } catch (Throwable) {
            $result = ['exit_code' => 1];
        } finally {
            $db->exec('DROP TRIGGER IF EXISTS fail_service_removal_delete');
        }

        hub_test_assert(($result['error_code'] ?? '') === 'service_remove_failed', 'service registration deletion failure must return the safe failure contract');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'service registration deletion failure must preserve registration');
        hub_test_assert(file_exists($composePath) && file_exists($envPath), 'service registration deletion failure must preserve generated files');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        @unlink($bin . '/docker');
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 service removal preflights generated runtime cleanup before deletion', function (): void {
    $dir = sys_get_temp_dir() . '/3waaihub_remove_preflight_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nexit 0\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');

    try {
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        $db = hub_test_reset_db();
        $service = hub_get_service_by_mode($db, 'hello');
        $composePath = hub_path((string)$service['compose_file']);
        $envPath = dirname($composePath) . '/.env';
        $runtimeDir = dirname($composePath);
        $permissions = fileperms($runtimeDir) & 0777;
        if (!chmod($runtimeDir, 0555)) {
            hub_test_skip('Cannot make the generated runtime directory unwritable.');
        }
        clearstatcache(true, $runtimeDir);
        if (is_writable($runtimeDir)) {
            chmod($runtimeDir, $permissions);
            hub_test_skip('Runtime user can still write the generated runtime directory.');
        }

        try {
            $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
            $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
        } finally {
            chmod($runtimeDir, $permissions);
        }

        hub_test_assert(($result['error_code'] ?? '') === 'service_runtime_cleanup_unavailable', 'unwritable runtime cleanup must be rejected before registration deletion');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'unwritable runtime cleanup must preserve registration');
        hub_test_assert(file_exists($composePath) && file_exists($envPath), 'unwritable runtime cleanup must preserve generated files');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        @unlink($bin . '/docker');
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 removed service runtime cleanup retries only after registration is gone', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    hub_test_assert($service !== null, 'hello service missing');
    $composePath = hub_path((string)$service['compose_file']);
    $envPath = dirname($composePath) . '/.env';
    $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');
    $job = hub_get_command_job($db, $jobId);
    hub_test_assert($job !== null, 'service removal job missing');
    hub_test_assert(
        hub_command_job_mark_runtime_cleanup_pending($db, $job, (string)$service['service_key']),
        'service removal cleanup marker must persist before registration deletion'
    );

    hub_retry_pending_service_runtime_cleanup($db);
    hub_test_assert(file_exists($composePath) && file_exists($envPath), 'queued removal must not clean a registered service runtime');

    $db->prepare("UPDATE command_jobs SET status = 'success' WHERE id = :id")->execute([':id' => $jobId]);
    hub_retry_pending_service_runtime_cleanup($db);
    $registeredJob = hub_get_command_job($db, $jobId);
    $registeredArgs = json_decode((string)($registeredJob['args_json'] ?? '{}'), true);
    hub_test_assert(file_exists($composePath) && file_exists($envPath), 'a reinstalled matching service runtime must never be removed by an old cleanup job');
    hub_test_assert(
        is_array($registeredArgs) && !isset($registeredArgs['runtime_cleanup_pending']),
        'a matching registered service must retire an old cleanup marker'
    );

    hub_test_assert(
        hub_command_job_mark_runtime_cleanup_pending($db, $registeredJob, (string)$service['service_key']),
        'removed service cleanup marker must be restorable for retry'
    );
    $db->prepare('DELETE FROM services WHERE id = :id')->execute([':id' => (int)$service['id']]);
    hub_retry_pending_service_runtime_cleanup($db);

    $completedJob = hub_get_command_job($db, $jobId);
    $completedArgs = json_decode((string)($completedJob['args_json'] ?? '{}'), true);
    hub_test_assert(!file_exists($composePath) && !file_exists($envPath), 'successful removed-service job must retry generated runtime cleanup');
    hub_test_assert(
        is_array($completedArgs) && !isset($completedArgs['runtime_cleanup_pending']),
        'completed runtime cleanup must clear its retry marker'
    );
});

hub_test('PhaseP-1 playground TTS artifact migration preserves rows and owner references', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $ownerMemberId = hub_create_api_member($db, 'Legacy Artifact Owner');
    $db->exec('DROP TABLE playground_tts_artifacts');
    $db->exec(<<<'SQL'
CREATE TABLE playground_tts_artifacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    service_id INTEGER NOT NULL,
    owner_member_id INTEGER NOT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(service_id, filename),
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE
)
SQL);
    $db->prepare(
        'INSERT INTO playground_tts_artifacts (filename, service_id, owner_member_id, request_id, created_at, updated_at)
         VALUES (:filename, :service_id, :owner_member_id, :request_id, :created_at, :updated_at)'
    )->execute([
        ':filename' => 'tts_legacy.wav',
        ':service_id' => (int)$service['id'],
        ':owner_member_id' => $ownerMemberId,
        ':request_id' => 'req_legacy_artifact',
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);
    $artifactId = (int)$db->lastInsertId();
    $db->exec('DROP TABLE service_logs');
    $db->exec(<<<'SQL'
CREATE TABLE service_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    output TEXT NOT NULL,
    exit_code INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY(service_id) REFERENCES services(id) ON DELETE CASCADE
)
SQL);
    $db->exec('CREATE INDEX idx_legacy_service_logs_action ON service_logs(action)');
    $db->prepare(
        'INSERT INTO service_logs (service_id, action, output, exit_code, created_at)
         VALUES (:service_id, :action, :output, :exit_code, :created_at)'
    )->execute([
        ':service_id' => (int)$service['id'],
        ':action' => 'legacy_history',
        ':output' => 'legacy output',
        ':exit_code' => 7,
        ':created_at' => hub_now(),
    ]);
    $serviceLogId = (int)$db->lastInsertId();

    hub_migrate($db);
    hub_migrate($db);

    $foreignKeys = $db->query('PRAGMA foreign_key_list(playground_tts_artifacts)')->fetchAll();
    $serviceKey = array_values(array_filter($foreignKeys, static fn (array $key): bool => $key['from'] === 'service_id'))[0] ?? null;
    $ownerKey = array_values(array_filter($foreignKeys, static fn (array $key): bool => $key['from'] === 'owner_member_id'))[0] ?? null;
    hub_test_assert(($serviceKey['on_delete'] ?? '') === 'SET NULL' && ($ownerKey['on_delete'] ?? '') === 'CASCADE', 'artifact migration must preserve owner FK and replace service cascade');
    hub_test_assert((int)($db->query('PRAGMA table_info(playground_tts_artifacts)')->fetchAll()[2]['notnull'] ?? 1) === 0, 'artifact service reference must be nullable');
    $serviceLogForeignKeys = $db->query('PRAGMA foreign_key_list(service_logs)')->fetchAll();
    $serviceLogKey = array_values(array_filter($serviceLogForeignKeys, static fn (array $key): bool => $key['from'] === 'service_id'))[0] ?? null;
    $serviceLogColumns = array_column($db->query('PRAGMA table_info(service_logs)')->fetchAll(), null, 'name');
    hub_test_assert(($serviceLogKey['on_delete'] ?? '') === 'SET NULL' && (int)($serviceLogColumns['service_id']['notnull'] ?? 1) === 0, 'service log migration must replace service cascade with a nullable reference');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'idx_legacy_service_logs_action'")->fetchColumn() === 1, 'service log migration must preserve explicit indexes');

    $db->prepare('DELETE FROM services WHERE id = :id')->execute([':id' => (int)$service['id']]);
    $mapping = $db->query('SELECT service_id, owner_member_id FROM playground_tts_artifacts WHERE id = ' . $artifactId)->fetch();
    hub_test_assert($mapping !== false && $mapping['service_id'] === null && (int)$mapping['owner_member_id'] === $ownerMemberId, 'artifact migration must preserve rows when their service is removed');
    $serviceLog = $db->query('SELECT service_id, action, output, exit_code FROM service_logs WHERE id = ' . $serviceLogId)->fetch();
    hub_test_assert($serviceLog !== false && $serviceLog['service_id'] === null && $serviceLog['action'] === 'legacy_history' && $serviceLog['output'] === 'legacy output' && (int)$serviceLog['exit_code'] === 7, 'service log migration must preserve rows when their service is removed');
});

hub_test('PhaseP-1 service removal rejects symlinked generated files before Docker', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $composePath = hub_path((string)$service['compose_file']);
    $composeTarget = $composePath . '.target';
    $dir = sys_get_temp_dir() . '/3waaihub_remove_link_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    $log = $dir . '/docker.log';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$log\"\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');
    $linked = false;

    try {
        if (!rename($composePath, $composeTarget) || !@symlink($composeTarget, $composePath)) {
            if (is_file($composeTarget) && !file_exists($composePath)) {
                rename($composeTarget, $composePath);
            }
            hub_test_skip('Symlink fixture is unavailable.');
        }
        $linked = true;
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));

        hub_test_assert(($result['error_code'] ?? '') === 'service_runtime_unmanaged', 'symlinked generated files must block removal');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'symlinked generated files must preserve registration');
        hub_test_assert(!file_exists($log), 'symlinked generated files must block Docker execution');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        if ($linked && is_link($composePath)) {
            unlink($composePath);
        }
        if (is_file($composeTarget) && !file_exists($composePath)) {
            rename($composeTarget, $composePath);
        }
        @unlink($bin . '/docker');
        @unlink($log);
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 service removal rejects a symlinked runtime directory before Docker', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $runtimeDir = hub_pack_runtime_dir($db, (string)$service['service_key']);
    $runtimeTarget = $runtimeDir . '.target';
    $dir = sys_get_temp_dir() . '/3waaihub_remove_runtime_link_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    $log = $dir . '/docker.log';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$log\"\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');
    $linked = false;

    try {
        if (!rename($runtimeDir, $runtimeTarget) || !@symlink($runtimeTarget, $runtimeDir)) {
            if (is_dir($runtimeTarget) && !file_exists($runtimeDir)) {
                rename($runtimeTarget, $runtimeDir);
            }
            hub_test_skip('Symlink fixture is unavailable.');
        }
        $linked = true;
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));

        hub_test_assert(($result['error_code'] ?? '') === 'service_runtime_unmanaged', 'symlinked runtime directories must block removal');
        hub_test_assert(hub_get_service($db, (int)$service['id']) !== null, 'symlinked runtime directories must preserve registration');
        hub_test_assert(!file_exists($log), 'symlinked runtime directories must block Docker execution');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        if ($linked && is_link($runtimeDir)) {
            unlink($runtimeDir);
        }
        if (is_dir($runtimeTarget) && !file_exists($runtimeDir)) {
            rename($runtimeTarget, $runtimeDir);
        }
        @unlink($bin . '/docker');
        @unlink($log);
        @rmdir($bin);
        @rmdir($dir);
    }
});

hub_test('PhaseP-1 service removal accepts a symlinked runtime base with normal child files', function (): void {
    $db = hub_test_reset_db();
    $service = hub_get_service_by_mode($db, 'hello');
    $runtimeBase = hub_pack_runtime_base_dir($db);
    $runtimeBaseTarget = $runtimeBase . '.target';
    $composePath = hub_path((string)$service['compose_file']);
    $envPath = dirname($composePath) . '/.env';
    $dir = sys_get_temp_dir() . '/3waaihub_remove_base_link_' . bin2hex(random_bytes(4));
    $bin = $dir . '/bin';
    $log = $dir . '/docker.log';
    mkdir($bin, 0775, true);
    file_put_contents($bin . '/docker', "#!/bin/sh\nprintf '%s\\n' \"$*\" >> \"$log\"\n");
    chmod($bin . '/docker', 0755);
    $path = getenv('PATH');
    $linked = false;

    try {
        if (!rename($runtimeBase, $runtimeBaseTarget) || !@symlink($runtimeBaseTarget, $runtimeBase)) {
            if (is_dir($runtimeBaseTarget) && !file_exists($runtimeBase)) {
                rename($runtimeBaseTarget, $runtimeBase);
            }
            hub_test_skip('Symlink fixture is unavailable.');
        }
        $linked = true;
        putenv('PATH=' . $bin . PATH_SEPARATOR . $path);
        hub_test_assert(hub_service_generated_runtime_files($db, $service) === [$composePath, $envPath], 'a symlinked runtime base must allow normal generated child files');
        $jobId = hub_enqueue_command_job($db, 'service_remove', (int)$service['id'], [], null, '127.0.0.1');

        $result = hub_remove_service($db, $service, hub_get_command_job($db, $jobId));
        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        hub_test_assert($result['exit_code'] === 0, 'a symlinked runtime base must allow service removal');
        hub_test_assert(count($commands) === 1 && str_contains($commands[0], ' down '), 'a symlinked runtime base must still run docker compose down');
        hub_test_assert(hub_get_service($db, (int)$service['id']) === null, 'a symlinked runtime base must allow registration deletion');
    } finally {
        putenv($path === false ? 'PATH' : 'PATH=' . $path);
        if ($linked && is_link($runtimeBase)) {
            unlink($runtimeBase);
        }
        if (is_dir($runtimeBaseTarget) && !file_exists($runtimeBase)) {
            rename($runtimeBaseTarget, $runtimeBase);
        }
        @unlink($bin . '/docker');
        @unlink($log);
        @rmdir($bin);
        @rmdir($dir);
    }
});
