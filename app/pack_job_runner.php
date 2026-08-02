<?php
declare(strict_types=1);

function hub_pack_job_worker_task_types(): array
{
    return ['demo_task', 'structure_parse', 'docparser_parse', 'docparser_repair_translation', 'pack_job', 'voice_profile_prepare'];
}

function hub_pack_job_adapter_worker_id(array $options): string
{
    $workerId = trim((string)($options['worker_id'] ?? ('task-worker-' . gethostname())));
    if ($workerId === '') {
        throw new InvalidArgumentException('worker_id_required');
    }

    return substr($workerId, 0, 128);
}

function hub_pack_job_claim_runtime(PDO $db, array $task, string $workerId, int $leaseSeconds): ?array
{
    $taskId = (int)($task['id'] ?? 0);
    $taskLock = (string)($task['lock_token'] ?? '');
    if ($taskId <= 0 || $taskLock === '') {
        return null;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $guard = $db->prepare("SELECT 1 FROM tasks WHERE id = :id AND task_type = 'pack_job' AND status = 'running' AND lock_token = :lock_token");
        $guard->execute([':id' => $taskId, ':lock_token' => $taskLock]);
        if ($guard->fetchColumn() === false) {
            $db->exec('COMMIT');
            return null;
        }
        $find = $db->prepare('SELECT * FROM runtime_runs WHERE task_id = :task_id ORDER BY id ASC LIMIT 1');
        $find->execute([':task_id' => $taskId]);
        $run = $find->fetch();
        if (!$run) {
            $now = hub_now();
            $db->prepare(
                'INSERT INTO runtime_runs
                    (run_id, task_id, attempt_no, pack_id, task, pack_version, runner_version, caller, workspace, state, started_at, created_at)
                 VALUES
                    (:run_id, :task_id, 0, :pack_id, :task, :pack_version, :runner_version, :caller, :workspace, :state, :started_at, :created_at)'
            )->execute([
                ':run_id' => 'packjob-' . $taskId . '-' . bin2hex(random_bytes(12)),
                ':task_id' => $taskId,
                ':pack_id' => (string)$task['pack_id'],
                ':task' => (string)$task['job'],
                ':pack_version' => (string)$task['pack_version'],
                ':runner_version' => 'pack-job-adapter/0.1',
                ':caller' => $workerId,
                ':workspace' => hub_task_result_dir($taskId) . '/workspace',
                ':state' => 'queued',
                ':started_at' => $now,
                ':created_at' => $now,
            ]);
            $find->execute([':task_id' => $taskId]);
            $run = $find->fetch();
        }
        if (!is_array($run) || ($run['state'] ?? '') !== 'queued') {
            $db->exec('COMMIT');
            return null;
        }
        $token = bin2hex(random_bytes(32));
        $now = hub_now();
        $claim = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'claimed', worker_id = :worker_id, lease_token = :lease_token, claimed_at = :now,
                 heartbeat_at = :now, lease_expires_at = :lease_expires_at
             WHERE id = :id AND state = 'queued'"
        );
        $claim->execute([
            ':worker_id' => $workerId,
            ':lease_token' => $token,
            ':now' => $now,
            ':lease_expires_at' => hub_runtime_lease_until($leaseSeconds),
            ':id' => (int)$run['id'],
        ]);
        if ($claim->rowCount() !== 1) {
            $db->exec('COMMIT');
            return null;
        }
        $run = hub_runtime_fetch_run($db, (int)$run['id']);
        $db->exec('COMMIT');

        return $run;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_wait_without_gpu(PDO $db, int $taskId, array $run, string $reason, int $backoffSeconds): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_wait_transaction_required');
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $db->exec('BEGIN IMMEDIATE');
    try {
        if (!hub_runtime_gpu_runtime_fence_in_transaction($db, $run, $taskId)) {
            $db->exec('ROLLBACK');
            return false;
        }
        $runStmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'waiting_gpu', lease_expires_at = NULL, heartbeat_at = :now, error_code = NULL
             WHERE run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND task_id = :task_id AND state IN ('claimed', 'running')"
        );
        $runStmt->execute([
            ':now' => $now,
            ':run_id' => $runtime['run_id'],
            ':worker_id' => $runtime['worker_id'],
            ':lease_token' => $runtime['lease_token'],
            ':task_id' => $taskId,
        ]);
        $taskStmt = $db->prepare(
            "UPDATE tasks
             SET status = 'waiting_gpu', waiting_reason = :reason, next_attempt_at = :next_attempt_at,
                 lock_token = NULL, updated_at = :now
             WHERE id = :id AND task_type = 'pack_job' AND status = 'running'"
        );
        $taskStmt->execute([
            ':reason' => $reason,
            ':next_attempt_at' => hub_runtime_lease_until(max(1, $backoffSeconds)),
            ':now' => $now,
            ':id' => $taskId,
        ]);
        if ($runStmt->rowCount() !== 1 || $taskStmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return false;
        }
        $db->exec('COMMIT');
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_no_work_cleanup(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

function hub_pack_job_failure_code(Throwable $error, string $fallback = 'job_unavailable'): string
{
    $message = $error->getMessage();
    return in_array($message, ['pack_version_unavailable', 'job_unavailable', 'job_contract_unavailable', 'url_not_allowed'], true) ? $message : $fallback;
}

function hub_pack_job_adapter_failure(PDO $db, int $taskId, array $run, string $code, string $message, array $cleanup, ?array $gpuLease): array
{
    hub_commit_pack_job_failure($db, $taskId, $run, 'failed', $code, $message, $cleanup, $gpuLease);
    $task = hub_get_task($db, $taskId);

    return ['status' => (string)($task['status'] ?? 'failed'), 'error_code' => (string)($task['error_code'] ?? $code)];
}

function hub_pack_job_secure_remove_private_file(string $path, ?callable $unlinker = null): void
{
    $unlinker ??= static fn (string $candidate): bool => @unlink($candidate);
    $maxAttempts = 4;
    $unstable = false;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            return;
        }
        $type = (int)$stat['mode'] & 0170000;
        if ($type === 0120000) {
            if ($unstable && $attempt === $maxAttempts - 1) {
                break;
            }
            try {
                $unlinker($path);
            } catch (Throwable) {
            }
            clearstatcache(true, $path);
            if (!is_array(@lstat($path))) {
                return;
            }
            $unstable = true;
            continue;
        }
        if ($type !== 0100000) {
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }

        $handle = @fopen($path, 'r+b');
        if ($handle === false) {
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!is_array($current)) {
                return;
            }
            if (((int)$current['mode'] & 0170000) !== 0100000
                || (int)$current['dev'] !== (int)$stat['dev']
                || (int)$current['ino'] !== (int)$stat['ino']) {
                $unstable = true;
                continue;
            }
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }
        $locked = false;
        $truncated = false;
        $retry = false;
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('workspace_privacy_cleanup_failed');
            }
            $locked = true;
            $openStat = fstat($handle);
            if (!is_array($openStat)
                || (((int)$openStat['mode'] & 0170000) !== 0100000)
                || (int)$openStat['dev'] !== (int)$stat['dev']
                || (int)$openStat['ino'] !== (int)$stat['ino']) {
                $retry = true;
            } elseif (!ftruncate($handle, 0)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('workspace_privacy_cleanup_failed');
            } else {
                $truncated = true;
            }
        } finally {
            if ($locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
        if (!$truncated) {
            if ($retry) {
                $unstable = true;
                continue;
            }
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }

        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($after)) {
            return;
        }
        $sameZeroInode = (((int)$after['mode'] & 0170000) === 0100000)
            && (int)$after['dev'] === (int)$stat['dev']
            && (int)$after['ino'] === (int)$stat['ino']
            && (int)$after['size'] === 0;
        if (!$sameZeroInode) {
            $unstable = true;
            continue;
        }
        if ($unstable && $attempt === $maxAttempts - 1) {
            break;
        }

        try {
            $unlinker($path);
        } catch (Throwable) {
        }
        clearstatcache(true, $path);
        $afterUnlink = @lstat($path);
        if (!is_array($afterUnlink)) {
            return;
        }
        if ((((int)$afterUnlink['mode'] & 0170000) === 0100000)
            && (int)$afterUnlink['dev'] === (int)$stat['dev']
            && (int)$afterUnlink['ino'] === (int)$stat['ino']
            && (int)$afterUnlink['size'] === 0) {
            return;
        }
        $unstable = true;
    }

    throw new RuntimeException('workspace_privacy_cleanup_failed');
}

function hub_pack_job_cleanup_private_files(array $paths, ?callable $unlinker = null): void
{
    $failure = null;
    foreach (array_unique($paths) as $path) {
        if (!is_string($path) || $path === '') {
            continue;
        }
        try {
            hub_pack_job_secure_remove_private_file($path, $unlinker);
        } catch (Throwable $error) {
            $failure ??= $error;
        }
    }
    if ($failure !== null) {
        throw new RuntimeException('workspace_privacy_cleanup_failed', 0, $failure);
    }
}

function hub_pack_job_cleanup_stale_private_requests(string $input, ?callable $unlinker = null): void
{
    $directory = @opendir($input);
    if ($directory === false) {
        throw new RuntimeException('workspace_privacy_cleanup_failed');
    }
    $paths = [];
    try {
        while (($name = readdir($directory)) !== false) {
            if (preg_match('/\Arequest\.private\.[a-f0-9]{16}\z/D', $name) === 1) {
                $paths[] = $input . DIRECTORY_SEPARATOR . $name;
            }
        }
    } finally {
        closedir($directory);
    }
    hub_pack_job_cleanup_private_files($paths, $unlinker);
}

function hub_pack_job_write_private_request(
    string $requestPath,
    string $json,
    ?callable $renamer = null,
    ?callable $unlinker = null,
    ?callable $chmodder = null,
    ?callable $writer = null,
): void {
    $requestParent = dirname($requestPath);
    $input = realpath($requestParent);
    $normalize = static function (string $path): string {
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        return hub_platform_id() === 'windows' ? strtolower($path) : $path;
    };
    if ($input === false || $normalize($requestParent) !== $normalize($input)) {
        throw new RuntimeException('workspace_privacy_cleanup_failed');
    }
    $requestPath = $input . DIRECTORY_SEPARATOR . basename($requestPath);
    hub_pack_job_cleanup_stale_private_requests($input, $unlinker);
    hub_pack_job_secure_remove_private_file($requestPath, $unlinker);
    try {
        $temporaryPath = $input . '/request.private.' . bin2hex(random_bytes(8));
    } catch (Throwable $error) {
        throw new RuntimeException('workspace_privacy_cleanup_failed', 0, $error);
    }
    $payload = $json . PHP_EOL;
    $renamer ??= static fn (string $from, string $to): bool => @rename($from, $to);
    $chmodder ??= static fn (string $path, int $mode): bool => chmod($path, $mode);
    $writer ??= static function ($handle, string $path, string $contents): int {
        $total = 0;
        $length = strlen($contents);
        while ($total < $length) {
            $written = fwrite($handle, substr($contents, $total));
            if (!is_int($written) || $written <= 0) {
                return $total;
            }
            $total += $written;
        }

        return $total;
    };
    $handle = false;
    try {
        $oldUmask = umask(0077);
        try {
            $handle = @fopen($temporaryPath, 'x+b');
        } finally {
            umask($oldUmask);
        }
        if ($handle === false || !flock($handle, LOCK_EX) || !(bool)$chmodder($temporaryPath, 0600)) {
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }
        $openStat = fstat($handle);
        clearstatcache(true, $temporaryPath);
        $pathStat = lstat($temporaryPath);
        $restrictive = hub_platform_id() === 'windows'
            || (is_array($openStat) && (((int)$openStat['mode'] & 0777) === 0600));
        if (!is_array($openStat)
            || !is_array($pathStat)
            || (((int)$openStat['mode'] & 0170000) !== 0100000)
            || (((int)$pathStat['mode'] & 0170000) !== 0100000)
            || (int)($openStat['nlink'] ?? 0) !== 1
            || (int)$openStat['dev'] !== (int)$pathStat['dev']
            || (int)$openStat['ino'] !== (int)$pathStat['ino']
            || (int)$openStat['size'] !== 0
            || !$restrictive) {
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }
        $written = $writer($handle, $temporaryPath, $payload);
        if (!is_int($written)
            || $written !== strlen($payload)
            || !fflush($handle)
            || (function_exists('fsync') && !fsync($handle))) {
            throw new RuntimeException('workspace_privacy_cleanup_failed');
        }
        flock($handle, LOCK_UN);
        fclose($handle);
        $handle = false;

        clearstatcache(true, $temporaryPath);
        $temporaryStat = lstat($temporaryPath);
        $moved = is_array($temporaryStat)
            && (((int)$temporaryStat['mode'] & 0170000) === 0100000)
            && (int)($temporaryStat['nlink'] ?? 0) === 1
            && (hub_platform_id() === 'windows' || (((int)$temporaryStat['mode'] & 0777) === 0600))
            && (int)$temporaryStat['size'] === strlen($payload)
            && hash_equals(hash('sha256', $payload), (string)hash_file('sha256', $temporaryPath))
            && (bool)$renamer($temporaryPath, $requestPath);
        clearstatcache(true, $temporaryPath);
        clearstatcache(true, $requestPath);
        $stat = $moved && !is_link($requestPath) && is_file($requestPath) ? lstat($requestPath) : false;
        if ($moved
            && !file_exists($temporaryPath)
            && !is_link($temporaryPath)
            && is_array($stat)
            && (((int)$stat['mode'] & 0170000) === 0100000)
            && (int)($stat['nlink'] ?? 0) === 1
            && (hub_platform_id() === 'windows' || (((int)$stat['mode'] & 0777) === 0600))
            && hash_equals(hash('sha256', $payload), (string)hash_file('sha256', $requestPath))) {
            return;
        }
    } catch (Throwable) {
    } finally {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    hub_pack_job_cleanup_private_files([$temporaryPath, $requestPath], $unlinker);
    throw new RuntimeException('workspace_privacy_cleanup_failed');
}

function hub_pack_job_prepare_workspace(PDO $db, array $task, array $contract, ?array $voiceProfileMount = null): string
{
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    $request = [];
    foreach ((array)($contract['input_fields'] ?? []) as $field) {
        if (array_key_exists($field, $input)) {
            $request[$field] = $input[$field];
        }
    }
    if (isset($input['voice_context'])) {
        $request['voice_context'] = $input['voice_context'];
    }
    $hasPrivatePrompt = false;
    if (isset($voiceProfileMount['prompt_text'])) {
        if (($request['voice_context']['mode'] ?? null) !== 'ultimate_clone'
            || !is_string($voiceProfileMount['prompt_text']) || $voiceProfileMount['prompt_text'] === '') {
            throw new RuntimeException('voice_profile_unavailable');
        }
        $request['prompt_text'] = $voiceProfileMount['prompt_text'];
        $hasPrivatePrompt = true;
    }
    if (($task['requested_mode'] ?? '') === 'web_capture') {
        $request = hub_web_capture_prepare_runner_request($db, $request);
    }

    $taskId = (int)$task['id'];
    $taskRoot = hub_task_result_dir($taskId);
    if (is_link($taskRoot) || (!is_dir($taskRoot) && !mkdir($taskRoot, 0700, true))) {
        throw new RuntimeException('workspace_unavailable');
    }
    $taskRoot = realpath($taskRoot);
    if ($taskRoot === false) {
        throw new RuntimeException('workspace_unavailable');
    }
    $workspace = $taskRoot . '/workspace';
    if (is_link($workspace) || (!is_dir($workspace) && !mkdir($workspace, 0700, true))) {
        throw new RuntimeException('workspace_unavailable');
    }
    foreach (['input', 'output', 'logs', 'checkpoints'] as $name) {
        $dir = $workspace . '/' . $name;
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true))) {
            throw new RuntimeException('workspace_unavailable');
        }
    }
    $workspace = realpath($workspace);
    if ($workspace === false || !str_starts_with($workspace, $taskRoot . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('workspace_unavailable');
    }
    $source = null;
    if ((int)($task['source_artifact_id'] ?? 0) <= 0 && isset($input['source_upload_path'])) {
        $source = hub_managed_task_upload_path($taskId, (string)$input['source_upload_path']);
        if ($source === null) {
            throw new RuntimeException('source_upload_invalid');
        }
    }
    if ($source !== null && !copy($source, $workspace . '/input/source')) {
        throw new RuntimeException('source_copy_failed');
    }
    $requestPath = $workspace . '/input/request.json';
    if ($hasPrivatePrompt && (is_link($requestPath) || is_file($requestPath))) {
        hub_pack_job_secure_remove_private_file($requestPath);
    } elseif ($hasPrivatePrompt && file_exists($requestPath)) {
        throw new RuntimeException('workspace_unavailable');
    }
    $runnerConfig = hub_pack_job_runner_config_for_task($contract, $input);
    if ($runnerConfig !== null) {
        $runnerConfigPath = $workspace . '/input/runner_config.json';
        if (is_link($runnerConfigPath) || (file_exists($runnerConfigPath) && !is_file($runnerConfigPath))) {
            throw new RuntimeException('workspace_unavailable');
        }
        $json = json_encode($runnerConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents($runnerConfigPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('workspace_unavailable');
        }
    }
    $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!$hasPrivatePrompt) {
        if ($json === false || file_put_contents($requestPath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('workspace_unavailable');
        }

        return $workspace;
    }
    if ($json === false) {
        throw new RuntimeException('workspace_privacy_cleanup_failed');
    }
    hub_pack_job_write_private_request($requestPath, $json);

    return $workspace;
}

function hub_pack_job_scrub_private_prompt(string $workspace): void
{
    $input = realpath($workspace . '/input');
    $requestPath = $workspace . '/input/request.json';
    if ($input === false || $input !== $workspace . '/input') {
        throw new RuntimeException('workspace_privacy_cleanup_failed');
    }
    if (is_link($requestPath)) {
        hub_pack_job_secure_remove_private_file($requestPath);
        return;
    }
    if (!file_exists($requestPath)) {
        return;
    }
    if (!is_file($requestPath)) {
        throw new RuntimeException('workspace_privacy_cleanup_failed');
    }
    try {
        $request = json_decode((string)file_get_contents($requestPath), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        hub_pack_job_secure_remove_private_file($requestPath);
        return;
    }
    if (!is_array($request) || array_is_list($request)) {
        hub_pack_job_secure_remove_private_file($requestPath);
        return;
    }
    unset($request['prompt_text']);
    $json = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        $temporaryPath = $input . '/request.scrubbed.' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        hub_pack_job_secure_remove_private_file($requestPath);
        return;
    }
    try {
        if ($json !== false
            && file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) !== false
            && chmod($temporaryPath, 0600)) {
            hub_pack_job_secure_remove_private_file($requestPath);
            if (rename($temporaryPath, $requestPath)) {
                return;
            }
        }
    } catch (Throwable) {
    }
    hub_pack_job_cleanup_private_files([$temporaryPath, $requestPath]);
}

function hub_pack_job_copy_source_artifact(PDO $db, array $task, string $workspace): void
{
    $artifactId = (int)($task['source_artifact_id'] ?? 0);
    if ($artifactId <= 0) {
        return;
    }
    $artifact = hub_get_task_artifact($db, $artifactId);
    $source = is_array($artifact) ? hub_artifact_safe_path((string)($artifact['path'] ?? '')) : null;
    if ($source === null || !copy($source, $workspace . '/input/source')) {
        throw new RuntimeException('source_artifact_invalid');
    }
}

function hub_pack_job_begin_execution(PDO $db, array $task, array $run, array $runner, ?array $gpuLease): ?array
{
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_execution_transaction_required');
    }
    $timeout = date('Y-m-d H:i:s', time() + (int)$runner['timeout_seconds']);
    $taskId = (int)$task['id'];
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($gpuLease !== null && (!hub_runtime_gpu_runtime_fence_in_transaction($db, $run, $taskId) || !hub_runtime_gpu_active($db, $run, $gpuLease, $taskId))) {
            $db->exec('ROLLBACK');
            return null;
        }
        $stmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'running', started_at = :started_at, image_name = :image_name, timeout_at = :timeout_at,
                 attempt_no = COALESCE(attempt_no, 0) + 1
             WHERE id = :id AND task_id = :task_id AND lease_token = :lease_token AND state = 'claimed'
               AND lease_expires_at IS NOT NULL AND lease_expires_at > :now"
        );
        $stmt->execute([
            ':started_at' => hub_now(),
            ':image_name' => $runner['image'],
            ':timeout_at' => $timeout,
            ':id' => (int)$run['id'],
            ':task_id' => $taskId,
            ':lease_token' => (string)$run['lease_token'],
            ':now' => hub_now(),
        ]);
        if ($stmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return null;
        }
        $started = hub_runtime_fetch_run($db, (int)$run['id']);
        $db->exec('COMMIT');

        return $started;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_runner_config_for_task(array $contract, array $input): ?array
{
    if (!isset($contract['runner_config'])) {
        return null;
    }
    $config = $contract['runner_config'];
    $alias = $input[$config['alias_input'] ?? ''] ?? ($config['default_alias'] ?? null);
    if (!is_string($alias) || !isset($config['aliases'][$alias])) {
        throw new RuntimeException('job_contract_unavailable');
    }

    return [
        'allowlist' => $config['model_allowlist'],
        'alias' => $alias,
        'model' => $config['aliases'][$alias],
    ];
}

function hub_pack_job_asset_descendant(string $root, string $relative): ?string
{
    $path = $root;
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return null;
        }
        $path .= DIRECTORY_SEPARATOR . $part;
        if (is_link($path)) {
            return null;
        }
    }
    $resolved = realpath($path);
    if ($resolved === false || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
        return null;
    }

    return $resolved;
}

function hub_pack_job_asset_mounts_for_input(array $descriptors, array $input): array
{
    $active = [];
    foreach ($descriptors as $descriptor) {
        $when = $descriptor['when'] ?? null;
        if ($when !== null && (!array_key_exists($when['input'], $input) || $input[$when['input']] !== $when['equals'])) {
            continue;
        }
        $active[] = $descriptor;
    }

    return $active;
}

function hub_pack_job_asset_marker_json_valid(string $source, array $marker, array $input): bool
{
    $path = hub_pack_job_asset_descendant($source, (string)($marker['path'] ?? ''));
    $size = $path === null ? false : filesize($path);
    if ($path === null || $size === false || $size < 1 || $size > 65536) {
        return false;
    }
    try {
        $value = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }
    if (!is_array($value) || array_is_list($value)) {
        return false;
    }
    $exactKeys = $marker['exact_keys'] ?? null;
    if (!is_array($exactKeys) || count($value) !== count($exactKeys)
        || array_diff(array_keys($value), $exactKeys) !== [] || array_diff($exactKeys, array_keys($value)) !== []) {
        return false;
    }
    foreach ((array)($marker['required_strings'] ?? []) as $field => $expected) {
        if (!is_string($field) || !is_string($expected) || !array_key_exists($field, $value)
            || !is_string($value[$field]) || $value[$field] !== $expected) {
            return false;
        }
    }
    foreach ((array)($marker['string_lists'] ?? []) as $field => $allowed) {
        $items = is_string($field) && array_key_exists($field, $value) ? $value[$field] : null;
        if (!is_array($items) || !array_is_list($items) || $items === [] || !is_array($allowed) || $allowed === []) {
            return false;
        }
        $seen = [];
        foreach ($items as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true) || isset($seen[$item])) {
                return false;
            }
            $seen[$item] = true;
        }
    }
    $membership = $marker['input_membership'] ?? null;
    if ($membership !== null) {
        $inputField = $membership['input'] ?? null;
        $listField = $membership['list_field'] ?? null;
        $items = is_string($listField) && array_key_exists($listField, $value) ? $value[$listField] : null;
        $requested = is_string($inputField) && array_key_exists($inputField, $input) ? $input[$inputField] : null;
        if (!is_string($requested) || !is_array($items)
            || (!in_array('*', $items, true) && !in_array($requested, $items, true))) {
            return false;
        }
    }

    return true;
}

function hub_pack_job_resolve_asset_mounts(PDO $db, array $runner, array $input = []): array
{
    $descriptors = hub_pack_async_job_runner_asset_mounts($runner['asset_mounts'] ?? []);
    if ($descriptors === null) {
        throw new RuntimeException('model_assets_unavailable');
    }
    $storage = hub_get_storage_paths($db);
    $roots = [
        'models' => (string)($storage['AIHUB_MODELS_DIR'] ?? ''),
        'cache' => (string)($storage['AIHUB_CACHE_DIR'] ?? ''),
    ];
    $resolved = [];
    foreach (hub_pack_job_asset_mounts_for_input($descriptors, $input) as $descriptor) {
        $configuredRoot = $roots[$descriptor['storage']] ?? '';
        if ($configuredRoot === '' || is_link($configuredRoot)) {
            throw new RuntimeException('model_assets_unavailable');
        }
        $root = realpath($configuredRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('model_assets_unavailable');
        }
        $source = hub_pack_job_asset_descendant($root, (string)$descriptor['host_subdir']);
        if ($source === null || !is_dir($source)) {
            throw new RuntimeException('model_assets_unavailable');
        }
        foreach ($descriptor['required_paths'] as $requiredPath) {
            $required = hub_pack_job_asset_descendant($source, (string)$requiredPath);
            if ($required === null || !is_file($required)) {
                throw new RuntimeException('model_assets_unavailable');
            }
        }
        if (isset($descriptor['marker_json']) && !hub_pack_job_asset_marker_json_valid($source, $descriptor['marker_json'], $input)) {
            throw new RuntimeException('model_assets_unavailable');
        }
        $resolved[] = [
            'id' => $descriptor['id'],
            'source' => $source,
            'container_path' => $descriptor['container_path'],
        ];
    }

    return $resolved;
}

function hub_pack_job_resolve_voice_profile_mount(PDO $db, array $task, array $contract): ?array
{
    $definition = $contract['voice_context'] ?? [];
    if (!is_array($definition) || $definition === []) {
        return null;
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];
    $snapshot = hub_pack_job_voice_context_snapshot($definition, $input, $input['voice_context'] ?? null);
    if ($snapshot === []) {
        return null;
    }
    $profileId = (int)($snapshot['voice_profile_id'] ?? 0);
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    $profile = $profileId > 0 && $ownerMemberId > 0 ? hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId) : null;
    if (!$profile || (int)($profile['owner_member_id'] ?? 0) !== $ownerMemberId
        || (!empty($profile['expires_at']) && (string)$profile['expires_at'] <= hub_now())) {
        throw new RuntimeException('voice_profile_unavailable');
    }
    $path = hub_voice_profile_safe_host_path((string)($profile['reference_audio_path'] ?? ''));
    if ($path === null) {
        throw new RuntimeException('voice_profile_unavailable');
    }
    $sha256 = hash_file('sha256', $path);
    if (!is_string($sha256)) {
        throw new RuntimeException('voice_profile_unavailable');
    }
    if (($snapshot['mode'] ?? null) === ($definition['ultimate_value'] ?? null)) {
        $promptText = (string)($profile['prompt_text'] ?? '');
        $confirmedAt = trim((string)($profile['prompt_text_confirmed_at'] ?? ''));
        if ($promptText === '' || $confirmedAt === '') {
            throw new RuntimeException('voice_profile_changed');
        }
        if (!hash_equals((string)($snapshot['reference_audio_sha256'] ?? ''), $sha256)
            || !hash_equals((string)($profile['reference_audio_sha256'] ?? ''), $sha256)
            || !hash_equals((string)($snapshot['prompt_text_sha256'] ?? ''), hash('sha256', $promptText))
            || !hash_equals((string)($snapshot['prompt_text_confirmed_at'] ?? ''), $confirmedAt)) {
            throw new RuntimeException('voice_profile_changed');
        }

        return ['source' => $path, 'container_path' => (string)$definition['container_path'], 'prompt_text' => $promptText];
    }
    if (!hash_equals((string)($snapshot['reference_audio_sha256'] ?? ''), $sha256)
        || !hash_equals((string)($profile['reference_audio_sha256'] ?? ''), $sha256)) {
        throw new RuntimeException('voice_profile_unavailable');
    }

    return ['source' => $path, 'container_path' => (string)$definition['container_path']];
}

function hub_pack_job_runner_arguments(array $runner, array $task, array $run, string $workspace, ?array $config = null, array $assetMounts = [], ?array $voiceProfileMount = null): array
{
    $replacements = [
        '{workspace}' => $workspace,
        '{input_dir}' => $workspace . '/input',
        '{output_dir}' => $workspace . '/output',
        '{run_id}' => (string)$run['run_id'],
        '{task_id}' => (string)$task['id'],
    ];
    $replace = static fn (string $value): string => strtr($value, $replacements);

    return [
        'image' => $runner['image'],
        'entrypoint' => array_map($replace, $runner['entrypoint']),
        'args' => array_map($replace, $runner['args']),
        'output_dir' => $workspace . '/output',
        'accelerator' => $runner['accelerator'],
        'required_vram_mb' => $runner['required_vram_mb'],
        'timeout_seconds' => $runner['timeout_seconds'],
        'network_profile' => $runner['network_profile'] ?? 'isolated',
    ] + ($config === null ? [] : ['config' => $config])
        + ($assetMounts === [] ? [] : ['asset_mounts' => $assetMounts])
        + ($voiceProfileMount === null ? [] : ['voice_profile_mount' => $voiceProfileMount]);
}

function hub_pack_job_default_runner_command(array $context): array
{
    $runner = $context['runner'] ?? [];
    $workspace = realpath((string)($context['workspace'] ?? ''));
    if ($workspace === false || !is_dir($workspace . '/input') || !is_dir($workspace . '/output')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $input = realpath($workspace . '/input');
    $output = realpath($workspace . '/output');
    $checkpointPath = $workspace . '/checkpoints';
    if (is_link($checkpointPath) || (!is_dir($checkpointPath) && !mkdir($checkpointPath, 0700, true))) {
        throw new RuntimeException('workspace_unavailable');
    }
    $checkpoints = realpath($checkpointPath);
    if ($input === false || $output === false || $checkpoints === false || is_link($workspace . '/output')
        || !hub_storage_paths_equal($input, $workspace . '/input')
        || !hub_storage_paths_equal($output, $workspace . '/output')
        || !hub_storage_paths_equal($checkpoints, $workspace . '/checkpoints')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $name = 'aihub-pack-' . substr(preg_replace('/[^a-z0-9_.-]/', '-', strtolower((string)($context['run']['run_id'] ?? 'run'))) ?: 'run', 0, 48);
    $containerWorkspace = '/workspace';
    $replace = static fn (string $value): string => strtr($value, [
        $input => $containerWorkspace . '/input',
        $output => $containerWorkspace . '/output',
        $workspace => $containerWorkspace,
    ]);
    $entrypoint = $runner['entrypoint'] ?? [];
    $args = $runner['args'] ?? [];
    if (!is_array($entrypoint) || $entrypoint === [] || !is_array($args)) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $network = match ($runner['network_profile'] ?? 'isolated') {
        'capture_egress' => 'aihub-capture-egress',
        'public_egress' => 'bridge',
        default => 'none',
    };
    $command = ['docker', 'run', '--pull=never', '--network', $network];
    if (($runner['network_profile'] ?? 'isolated') === 'public_egress') {
        $command[] = '--cap-add';
        $command[] = 'NET_ADMIN';
    }
    $command[] = '--mount';
    $command[] = 'type=bind,src=' . $output . ',dst=' . $containerWorkspace . '/output';
    $command[] = '--mount';
    $command[] = 'type=bind,src=' . $checkpoints . ',dst=' . $containerWorkspace . '/checkpoints';
    $command[] = '--name';
    $command[] = $name;
    $voiceProfileMount = $runner['voice_profile_mount'] ?? null;
    foreach (['source', 'request.json', 'runner_config.json'] as $file) {
        if ($file === 'source' && $voiceProfileMount !== null) {
            continue;
        }
        $path = $input . '/' . $file;
        if (is_file($path) && !is_link($path)) {
            $command[] = '--mount';
            $command[] = 'type=bind,src=' . $path . ',dst=' . $containerWorkspace . '/input/' . $file
                . ($file === 'request.json' && ($runner['network_profile'] ?? 'isolated') === 'public_egress' ? '' : ',readonly');
        }
    }
    foreach ((array)($runner['asset_mounts'] ?? []) as $asset) {
        $source = is_array($asset) ? ($asset['source'] ?? null) : null;
        $containerPath = is_array($asset) ? ($asset['container_path'] ?? null) : null;
        if (!is_string($source) || !is_string($containerPath)
            || !is_dir($source) || is_link($source)
            || preg_match('~^/(?:models|cache)/[A-Za-z0-9][A-Za-z0-9._/-]{0,239}$~', $containerPath) !== 1) {
            throw new RuntimeException('model_assets_unavailable');
        }
        $command[] = '--mount';
        $command[] = 'type=bind,src=' . $source . ',dst=' . $containerPath . ',readonly';
    }
    if ($voiceProfileMount !== null) {
        $source = is_array($voiceProfileMount) ? ($voiceProfileMount['source'] ?? null) : null;
        $containerPath = is_array($voiceProfileMount) ? ($voiceProfileMount['container_path'] ?? null) : null;
        if (!is_string($source) || !is_string($containerPath) || !is_file($source) || is_link($source)
            || $containerPath !== '/data/voice_profiles/reference.wav') {
            throw new RuntimeException('voice_profile_unavailable');
        }
        $command[] = '--mount';
        $command[] = 'type=bind,src=' . $source . ',dst=' . $containerPath . ',readonly';
    }
    if (($runner['accelerator'] ?? '') === 'gpu') {
        $command[] = '--gpus';
        $command[] = 'all';
    }
    foreach ((array)($runner['secret_env'] ?? []) as $name) {
        if (is_string($name) && getenv($name) !== false) {
            $command[] = '--env';
            $command[] = $name;
        }
    }
    $command[] = '--entrypoint';
    $command[] = $replace((string)$entrypoint[0]);
    $command[] = (string)($runner['image'] ?? '');
    foreach (array_merge(array_slice($entrypoint, 1), $args) as $value) {
        $command[] = $replace((string)$value);
    }

    return ['name' => $name, 'command' => $command];
}

function hub_pack_job_default_process_runner(array $command, int $timeoutSeconds, callable $poll): array
{
    $unsupported = hub_linux_docker_unsupported_result();
    if ($unsupported !== null) {
        return $unsupported;
    }

    return hub_pack_job_process_runner($command, $timeoutSeconds, $poll);
}

function hub_pack_job_process_runner(array $command, int $timeoutSeconds, callable $poll): array
{
    hub_cli_only();
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, HUB_ROOT);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Cannot start process.'];
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $observedExitCode = null;
    $intent = null;
    $startedAt = microtime(true);
    do {
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = hub_observed_process_exit_code($status) ?? $observedExitCode;
            break;
        }
        $intent = $poll();
        if ($intent !== null) {
            proc_terminate($process);
            break;
        }
        if (microtime(true) - $startedAt >= max(1, $timeoutSeconds)) {
            $intent = 'timed_out';
            proc_terminate($process);
            $stderr .= "\nCommand timed out.";
            break;
        }
        usleep(1000000);
    } while (true);

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);
    $result = [
        'exit_code' => hub_process_exit_code(proc_close($process), $observedExitCode),
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
    ];
    if ($intent !== null) {
        $result['intent'] = $intent;
    }

    return $result;
}

function hub_pack_job_docker_container_state(callable $runner, string $name, int $timeoutSeconds): ?array
{
    try {
        $result = $runner(['docker', 'container', 'inspect', '--format', '{{json .State}}', $name], $timeoutSeconds);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($result)) {
        return null;
    }
    if ((int)($result['exit_code'] ?? 1) !== 0) {
        $message = (string)($result['stderr'] ?? '') . "\n" . (string)($result['stdout'] ?? '');
        return preg_match('/no such (?:container|object)/i', $message) === 1 ? ['exists' => false, 'pid' => 0] : null;
    }
    try {
        $state = json_decode((string)($result['stdout'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    if (!is_array($state) || !is_bool($state['Running'] ?? null) || !is_int($state['Pid'] ?? null) || (int)$state['Pid'] < 0) {
        return null;
    }

    return ['exists' => true, 'pid' => (int)$state['Pid']];
}

function hub_pack_job_default_container_cleanup(callable $runner, string $name, int $timeoutSeconds): array
{
    $state = hub_pack_job_docker_container_state($runner, $name, $timeoutSeconds);
    if ($state === null) {
        return ['cleanup' => [], 'owned_pids' => []];
    }
    $ownedPids = $state['pid'] > 0 ? [(int)$state['pid']] : [];
    if ($state['exists']) {
        try {
            $runner(['docker', 'stop', '-t', '10', $name], $timeoutSeconds);
            $runner(['docker', 'container', 'rm', '-f', $name], $timeoutSeconds);
        } catch (Throwable) {
            return ['cleanup' => [], 'owned_pids' => $ownedPids];
        }
    }
    $after = hub_pack_job_docker_container_state($runner, $name, $timeoutSeconds);
    if ($after === null || $after['exists']) {
        return ['cleanup' => [], 'owned_pids' => $ownedPids];
    }

    return ['cleanup' => hub_pack_job_no_work_cleanup(), 'owned_pids' => $ownedPids];
}

function hub_pack_job_runner_error_code(array $result): ?string
{
    $stderr = $result['stderr'] ?? null;
    if (!is_string($stderr)) {
        return null;
    }
    $errorCode = null;
    foreach (preg_split('/\R/', $stderr) as $line) {
        if (!str_starts_with($line, 'AIHUB_ERROR_CODE=')) {
            continue;
        }
        if ($errorCode !== null || preg_match('/\AAIHUB_ERROR_CODE=([a-z0-9_]{1,120})\z/D', $line, $matches) !== 1) {
            return null;
        }
        $errorCode = $matches[1];
    }

    return $errorCode;
}

function hub_pack_job_default_executor(array $context, ?callable $commandRunner = null, ?callable $processRunner = null): array
{
    $execution = hub_pack_job_default_runner_command($context);
    $context['started'](['container_id' => $execution['name']]);
    $runner = $commandRunner ?? 'hub_run_linux_docker_command';
    try {
        if ($processRunner === null && $commandRunner !== null) {
            $result = $runner($execution['command'], (int)$context['runner']['timeout_seconds']);
        } else {
            $intent = null;
            $poll = static function () use ($context, &$intent): ?string {
                if (!isset($context['tick']) || !is_callable($context['tick'])) {
                    return null;
                }
                $next = $context['tick']();
                if (in_array($next, ['fence_lost', 'cancelled', 'timed_out'], true)) {
                    $intent = $next;
                }

                return $intent;
            };
            $process = $processRunner ?? 'hub_pack_job_default_process_runner';
            $result = $process($execution['command'], (int)$context['runner']['timeout_seconds'], $poll);
        }
    } catch (Throwable) {
        $result = ['exit_code' => 1];
    }
    if (!is_array($result)) {
        $result = ['exit_code' => 1];
    }
    $exitCode = (int)($result['exit_code'] ?? 1);
    $errorCode = $exitCode === 0 ? null : hub_pack_job_runner_error_code($result);
    $cleanup = hub_pack_job_default_container_cleanup($runner, $execution['name'], (int)$context['runner']['timeout_seconds']);

    return [
        'exit_code' => $exitCode,
        'container_id' => $execution['name'],
        'owned_pids' => $cleanup['owned_pids'],
        'cleanup' => $cleanup['cleanup'],
    ] + ($errorCode === null ? [] : ['error_code' => $errorCode])
        + (isset($result['intent']) ? ['intent' => $result['intent']] : []);
}

function hub_web_screenshot_wsl_service_for_task(PDO $db, array $task): ?array
{
    if ((string)($task['pack_id'] ?? '') !== 'web-screenshot' || (string)($task['job'] ?? '') !== 'capture') {
        return null;
    }
    $service = hub_get_service_by_mode($db, 'web_capture');

    return is_array($service) && (string)($service['pack_id'] ?? '') === 'web-screenshot' ? $service : null;
}

function hub_web_screenshot_wsl_execution_plan(array $service, array $context, ?array $profile = null): array
{
    $task = is_array($context['task'] ?? null) ? $context['task'] : [];
    $runner = is_array($context['runner'] ?? null) ? $context['runner'] : [];
    if (
        (string)($service['pack_id'] ?? '') !== 'web-screenshot'
        || (string)($task['pack_id'] ?? '') !== 'web-screenshot'
        || (string)($task['job'] ?? '') !== 'capture'
        || ($runner['image'] ?? null) !== '3waaihub/web-screenshot:0.1.2'
        || ($runner['entrypoint'] ?? null) !== ['/app/capture-entrypoint.sh', '/app/capture']
        || ($runner['args'] ?? null) !== []
        || ($runner['accelerator'] ?? null) !== 'cpu'
        || ($runner['required_vram_mb'] ?? null) !== 0
        || ($runner['network_profile'] ?? null) !== 'public_egress'
    ) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
    $workspace = realpath((string)($context['workspace'] ?? ''));
    $runId = (string)($context['run']['run_id'] ?? '');
    if ($runtime === null || $workspace === false || !is_dir($workspace . '/input') || !is_dir($workspace . '/output')
        || !is_string($runner['output_dir'] ?? null) || !hub_storage_paths_equal($runner['output_dir'], $workspace . '/output')
        || !is_file($workspace . '/input/request.json') || is_link($workspace . '/input/request.json')
        || preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $runId) !== 1) {
        throw new RuntimeException('workspace_unavailable');
    }
    $execution = hub_pack_job_default_runner_command($context);
    $runtimeRoot = rtrim((string)$runtime['runtime_root'], '/');
    $jobRoot = hub_container_path($runtimeRoot . '/jobs/web-screenshot/' . $runId);
    if (!str_starts_with($jobRoot, $runtimeRoot . '/jobs/web-screenshot/')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $docker = [
        'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
        '--mount', 'type=bind,src=' . $jobRoot . '/output,dst=/workspace/output',
        '--mount', 'type=bind,src=' . $jobRoot . '/checkpoints,dst=/workspace/checkpoints',
        '--mount', 'type=bind,src=' . $jobRoot . '/input/request.json,dst=/workspace/input/request.json',
        '--name', (string)$execution['name'],
        '--entrypoint', '/app/capture-entrypoint.sh', '3waaihub/web-screenshot:0.1.2', '/app/capture',
    ];
    $script = "set -eu\n"
        . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
        . 'runtime_root=' . hub_wsl_shell_literal($runtimeRoot) . "\n"
        . 'job_root=' . hub_wsl_shell_literal($jobRoot) . "\n"
        . 'container_name=' . hub_wsl_shell_literal((string)$execution['name']) . "\n"
        . 'case "$job_root" in "$runtime_root"/jobs/web-screenshot/*) ;; *) echo "Invalid WSL job root." >&2; exit 2;; esac' . "\n"
        . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
        . 'if [ ! -f "$host_workspace/input/request.json" ] || [ -L "$host_workspace/input/request.json" ]; then echo "Web Screenshot request is unavailable." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0700 "$job_root/input" "$job_root/output" "$job_root/checkpoints"' . "\n"
        . 'cp -- "$host_workspace/input/request.json" "$job_root/input/request.json"' . "\n"
        . 'cleanup() { docker container rm -f "$container_name" >/dev/null 2>&1 || true; rm -rf -- "$job_root"; }' . "\n"
        . 'trap cleanup EXIT HUP INT TERM' . "\n"
        . 'copy_required() { source=$1; destination=$2; if [ ! -f "$source" ] || [ -L "$source" ]; then echo "Web Screenshot artifact is unavailable: $source" >&2; exit 2; fi; cp -- "$source" "$destination"; }' . "\n"
        . implode(' ', array_map('hub_wsl_shell_literal', $docker)) . "\n"
        . 'copy_required "$job_root/output/screenshot.png" "$host_workspace/output/screenshot.png"' . "\n"
        . 'copy_required "$job_root/output/capture_report.json" "$host_workspace/output/capture_report.json"' . "\n"
        . 'if [ -e "$job_root/output/crop.png" ] || [ -L "$job_root/output/crop.png" ]; then copy_required "$job_root/output/crop.png" "$host_workspace/output/crop.png"; fi' . "\n";

    return [
        'command' => hub_wsl_script_command($runtime, $script),
        'container_id' => (string)$execution['name'],
        'runtime' => $runtime,
        'job_root' => $jobRoot,
    ];
}

function hub_wsl_job_cleanup_workspace(array $runtime, string $packId, string $jobRoot, int $timeoutSeconds): bool
{
    $runtimeRoot = rtrim((string)($runtime['runtime_root'] ?? ''), '/');
    $prefix = $runtimeRoot . '/jobs/' . $packId . '/';
    if ($runtimeRoot === '' || !str_starts_with($jobRoot, $prefix)
        || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $packId) !== 1
        || preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', substr($jobRoot, strlen($prefix))) !== 1) {
        return false;
    }
    $result = hub_run_command(hub_wsl_script_command($runtime, 'rm -rf -- ' . hub_wsl_shell_literal($jobRoot)), $timeoutSeconds);

    return (int)($result['exit_code'] ?? 1) === 0;
}

function hub_web_screenshot_wsl_cleanup_workspace(array $runtime, string $jobRoot, int $timeoutSeconds): bool
{
    return hub_wsl_job_cleanup_workspace($runtime, 'web-screenshot', $jobRoot, $timeoutSeconds);
}

function hub_web_screenshot_wsl_executor(array $service, array $context, ?callable $processRunner = null, ?array $profile = null): array
{
    $unsupported = hub_service_runtime_unsupported_result($service, 'windows', $profile);
    if ($unsupported !== null) {
        return $unsupported + ['cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    try {
        $plan = hub_web_screenshot_wsl_execution_plan($service, $context, $profile);
    } catch (Throwable) {
        return ['exit_code' => 1, 'cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    $context['started'](['container_id' => $plan['container_id']]);
    $intent = null;
    $poll = static function () use ($context, &$intent): ?string {
        if (!isset($context['tick']) || !is_callable($context['tick'])) {
            return null;
        }
        $next = $context['tick']();
        if (in_array($next, ['fence_lost', 'cancelled', 'timed_out'], true)) {
            $intent = $next;
        }

        return $intent;
    };
    $runner = static function (array $docker, int $timeoutSeconds) use ($plan): array {
        if (($docker[0] ?? null) !== 'docker') {
            throw new InvalidArgumentException('Unexpected WSL Docker command.');
        }
        $script = 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $docker));

        return hub_run_command(hub_wsl_script_command($plan['runtime'], $script), $timeoutSeconds);
    };
    try {
        $process = $processRunner ?? 'hub_pack_job_process_runner';
        $result = $process($plan['command'], (int)$context['runner']['timeout_seconds'], $poll);
    } catch (Throwable) {
        $result = ['exit_code' => 1];
    }
    if (!is_array($result)) {
        $result = ['exit_code' => 1];
    }
    $cleanup = hub_pack_job_default_container_cleanup($runner, (string)$plan['container_id'], (int)$context['runner']['timeout_seconds']);
    if (!hub_web_screenshot_wsl_cleanup_workspace($plan['runtime'], (string)$plan['job_root'], (int)$context['runner']['timeout_seconds'])
        && (int)($result['exit_code'] ?? 1) === 0) {
        $result = ['exit_code' => 1, 'error_code' => 'workspace_cleanup_failed'];
    }
    $exitCode = (int)($result['exit_code'] ?? 1);
    $errorCode = $exitCode === 0 ? null : ((string)($result['error_code'] ?? '') ?: hub_pack_job_runner_error_code($result));

    return [
        'exit_code' => $exitCode,
        'container_id' => (string)$plan['container_id'],
        'owned_pids' => $cleanup['owned_pids'],
        'cleanup' => $cleanup['cleanup'],
    ] + ($errorCode === null ? [] : ['error_code' => $errorCode])
        + (isset($result['intent']) ? ['intent' => $result['intent']] : []);
}

function hub_edge_tts_wsl_service_for_task(PDO $db, array $task): ?array
{
    if ((string)($task['pack_id'] ?? '') !== 'edge-tts' || (string)($task['job'] ?? '') !== 'synthesize') {
        return null;
    }
    $service = hub_get_service_by_mode($db, 'edge_tts');

    return is_array($service) && (string)($service['pack_id'] ?? '') === 'edge-tts' ? $service : null;
}

function hub_edge_tts_wsl_execution_plan(array $service, array $context, ?array $profile = null): array
{
    $task = is_array($context['task'] ?? null) ? $context['task'] : [];
    $runner = is_array($context['runner'] ?? null) ? $context['runner'] : [];
    if (
        (string)($service['pack_id'] ?? '') !== 'edge-tts'
        || (string)($task['pack_id'] ?? '') !== 'edge-tts'
        || (string)($task['job'] ?? '') !== 'synthesize'
        || ($runner['image'] ?? null) !== '3waaihub/edge-tts:0.3.0'
        || ($runner['entrypoint'] ?? null) !== ['/app/edge-tts-entrypoint.sh', '/app/synthesize.py']
        || ($runner['args'] ?? null) !== []
        || ($runner['accelerator'] ?? null) !== 'cpu'
        || ($runner['required_vram_mb'] ?? null) !== 0
        || ($runner['network_profile'] ?? null) !== 'public_egress'
    ) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $runtime = hub_wsl_service_runtime($service, 'windows', $profile);
    $workspace = realpath((string)($context['workspace'] ?? ''));
    $runId = (string)($context['run']['run_id'] ?? '');
    if ($runtime === null || $workspace === false || !is_dir($workspace . '/input') || !is_dir($workspace . '/output')
        || !is_file($workspace . '/input/request.json') || is_link($workspace . '/input/request.json')
        || !is_string($runner['output_dir'] ?? null) || !hub_storage_paths_equal($runner['output_dir'], $workspace . '/output')
        || preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $runId) !== 1) {
        throw new RuntimeException('workspace_unavailable');
    }
    $execution = hub_pack_job_default_runner_command($context);
    $runtimeRoot = rtrim((string)$runtime['runtime_root'], '/');
    $jobRoot = hub_container_path($runtimeRoot . '/jobs/edge-tts/' . $runId);
    if (!str_starts_with($jobRoot, $runtimeRoot . '/jobs/edge-tts/')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $docker = [
        'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
        '--mount', 'type=bind,src=' . $jobRoot . '/output,dst=/workspace/output',
        '--mount', 'type=bind,src=' . $jobRoot . '/checkpoints,dst=/workspace/checkpoints',
        '--mount', 'type=bind,src=' . $jobRoot . '/input/request.json,dst=/workspace/input/request.json',
        '--name', (string)$execution['name'],
        '--entrypoint', '/app/edge-tts-entrypoint.sh', '3waaihub/edge-tts:0.3.0', '/app/synthesize.py',
    ];
    $artifacts = ['generated_audio.mp3', 'synthesis_metadata.json'];
    if (($task['input']['include_subtitles'] ?? false) === true) {
        array_push($artifacts, 'subtitle.vtt', 'subtitle.srt', 'speech_timeline.json');
    }
    $script = "set -eu\n"
        . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
        . 'runtime_root=' . hub_wsl_shell_literal($runtimeRoot) . "\n"
        . 'job_root=' . hub_wsl_shell_literal($jobRoot) . "\n"
        . 'container_name=' . hub_wsl_shell_literal((string)$execution['name']) . "\n"
        . 'case "$job_root" in "$runtime_root"/jobs/edge-tts/*) ;; *) echo "Invalid WSL job root." >&2; exit 2;; esac' . "\n"
        . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
        . 'if [ ! -f "$host_workspace/input/request.json" ] || [ -L "$host_workspace/input/request.json" ]; then echo "Edge TTS request is unavailable." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0700 "$job_root/input" "$job_root/output" "$job_root/checkpoints"' . "\n"
        . 'cp -- "$host_workspace/input/request.json" "$job_root/input/request.json"' . "\n"
        . 'cleanup() { docker container rm -f "$container_name" >/dev/null 2>&1 || true; rm -rf -- "$job_root"; }' . "\n"
        . 'trap cleanup EXIT HUP INT TERM' . "\n"
        . 'copy_required() { source=$1; destination=$2; if [ ! -f "$source" ] || [ -L "$source" ]; then echo "Edge TTS artifact is unavailable: $source" >&2; exit 2; fi; cp -- "$source" "$destination"; }' . "\n"
        . implode(' ', array_map('hub_wsl_shell_literal', $docker)) . "\n";
    foreach ($artifacts as $artifact) {
        $script .= 'copy_required "$job_root/output/' . $artifact . '" "$host_workspace/output/' . $artifact . '"' . "\n";
    }

    return [
        'command' => hub_wsl_script_command($runtime, $script),
        'container_id' => (string)$execution['name'],
        'runtime' => $runtime,
        'job_root' => $jobRoot,
    ];
}

function hub_edge_tts_wsl_executor(array $service, array $context, ?callable $processRunner = null, ?array $profile = null): array
{
    $unsupported = hub_service_runtime_unsupported_result($service, 'windows', $profile);
    if ($unsupported !== null) {
        return $unsupported + ['cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    try {
        $plan = hub_edge_tts_wsl_execution_plan($service, $context, $profile);
    } catch (Throwable) {
        return ['exit_code' => 1, 'cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    $context['started'](['container_id' => $plan['container_id']]);
    $intent = null;
    $poll = static function () use ($context, &$intent): ?string {
        if (!isset($context['tick']) || !is_callable($context['tick'])) {
            return null;
        }
        $next = $context['tick']();
        if (in_array($next, ['fence_lost', 'cancelled', 'timed_out'], true)) {
            $intent = $next;
        }

        return $intent;
    };
    $runner = static function (array $docker, int $timeoutSeconds) use ($plan): array {
        if (($docker[0] ?? null) !== 'docker') {
            throw new InvalidArgumentException('Unexpected WSL Docker command.');
        }

        return hub_run_command(hub_wsl_script_command($plan['runtime'], 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $docker))), $timeoutSeconds);
    };
    try {
        $process = $processRunner ?? 'hub_pack_job_process_runner';
        $result = $process($plan['command'], (int)$context['runner']['timeout_seconds'], $poll);
    } catch (Throwable) {
        $result = ['exit_code' => 1];
    }
    if (!is_array($result)) {
        $result = ['exit_code' => 1];
    }
    $cleanup = hub_pack_job_default_container_cleanup($runner, (string)$plan['container_id'], (int)$context['runner']['timeout_seconds']);
    if (!hub_wsl_job_cleanup_workspace($plan['runtime'], 'edge-tts', (string)$plan['job_root'], (int)$context['runner']['timeout_seconds'])
        && (int)($result['exit_code'] ?? 1) === 0) {
        $result = ['exit_code' => 1, 'error_code' => 'workspace_cleanup_failed'];
    }
    $exitCode = (int)($result['exit_code'] ?? 1);
    $errorCode = $exitCode === 0 ? null : ((string)($result['error_code'] ?? '') ?: hub_pack_job_runner_error_code($result));

    return [
        'exit_code' => $exitCode,
        'container_id' => (string)$plan['container_id'],
        'owned_pids' => $cleanup['owned_pids'],
        'cleanup' => $cleanup['cleanup'],
    ] + ($errorCode === null ? [] : ['error_code' => $errorCode])
        + (isset($result['intent']) ? ['intent' => $result['intent']] : []);
}

function hub_pack_job_execution_details(array $details, array $fallback = []): array
{
    $reportedEvidence = (isset($details['container_id']) && trim((string)$details['container_id']) !== '')
        || array_key_exists('baseline_pids', $details)
        || array_key_exists('owned_pids', $details);
    $details += $fallback;
    $containerId = isset($details['container_id']) ? trim((string)$details['container_id']) : null;
    if ($containerId === '') {
        $containerId = null;
    }
    if ($containerId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,254}$/', $containerId) !== 1) {
        throw new RuntimeException('runtime_metadata_invalid');
    }

    return [
        'container_id' => $containerId,
        'baseline_pids' => hub_runtime_gpu_recovery_pids($details['baseline_pids'] ?? []),
        'owned_pids' => hub_runtime_gpu_recovery_pids($details['owned_pids'] ?? []),
        'has_process_evidence' => $reportedEvidence || !empty($fallback['has_process_evidence']),
    ];
}

function hub_pack_job_record_execution(PDO $db, array $task, array $run, ?array $gpuLease, array $details): bool
{
    if ($gpuLease !== null) {
        return hub_runtime_record_gpu_ownership($db, $run, $gpuLease, $details['container_id'], $details['baseline_pids'], $details['owned_pids']);
    }
    $stmt = $db->prepare(
        "UPDATE runtime_runs SET container_id = :container_id
         WHERE id = :id AND task_id = :task_id AND lease_token = :lease_token AND state IN ('claimed', 'running')"
    );
    $stmt->execute([
        ':container_id' => $details['container_id'],
        ':id' => (int)$run['id'],
        ':task_id' => (int)$task['id'],
        ':lease_token' => (string)$run['lease_token'],
    ]);

    return $stmt->rowCount() === 1;
}

function hub_pack_job_tick(PDO $db, array $run, ?array $gpuLease, int $leaseSeconds): ?string
{
    $alive = $gpuLease === null
        ? hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], $leaseSeconds)
        : hub_runtime_gpu_heartbeat($db, $run, $gpuLease, $leaseSeconds);
    if (!$alive) {
        return 'fence_lost';
    }
    if (hub_runtime_is_cancel_requested($db, (int)$run['id'])) {
        return 'cancelled';
    }
    $current = hub_runtime_fetch_run($db, (int)$run['id']);
    if ($current === null || !hash_equals((string)$run['lease_token'], (string)$current['lease_token'])) {
        return 'fence_lost';
    }
    if (!empty($current['timeout_at']) && (string)$current['timeout_at'] <= hub_now()) {
        return 'timed_out';
    }

    return null;
}

function hub_pack_job_cleanup_from_result(array $result, array $details, ?callable $pidInspector, array $context): array
{
    $cleanup = is_array($result['cleanup'] ?? null) ? $result['cleanup'] : [];
    if (!$details['has_process_evidence'] && empty($result['completed_no_process_evidence'])) {
        return [];
    }
    if ($pidInspector !== null && $details['owned_pids'] !== []) {
        $pids = hub_runtime_gpu_recovery_pids($pidInspector($context));
        if (array_intersect($details['owned_pids'], $pids) !== []) {
            $cleanup['owned_gpu_pids_gone'] = false;
        }
    }

    return $cleanup;
}

function hub_pack_job_stop_result(array $options, array $context, string $reason, array $result): array
{
    if (!isset($options['stopper']) || !is_callable($options['stopper'])) {
        return $result;
    }
    $stopped = $options['stopper']($context, $reason, $result);
    if (!is_array($stopped)) {
        throw new RuntimeException('runtime_stop_invalid');
    }
    if (array_intersect(['runner_exited', 'container_removed', 'owned_gpu_pids_gone'], array_keys($stopped)) !== []) {
        $result['cleanup'] = $stopped;
        return $result;
    }

    return array_replace($result, $stopped);
}

function hub_pack_job_cleanup_after_started_failure(array $options, array $context, array $details, ?callable $pidInspector, string $reason): array
{
    try {
        $result = hub_pack_job_stop_result($options, $context, $reason, [
            'container_id' => $details['container_id'] ?? null,
            'baseline_pids' => $details['baseline_pids'] ?? [],
            'owned_pids' => $details['owned_pids'] ?? [],
        ]);
        $details = hub_pack_job_execution_details($result, $details);

        return hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
    } catch (Throwable) {
        return [];
    }
}

function hub_pack_job_reconcile_lost_fence(PDO $db, array $task, array $run, array $cleanup, ?array $gpuLease = null): bool
{
    $taskId = (int)($task['id'] ?? 0);
    $runId = (int)($run['id'] ?? 0);
    $runtimeId = (string)($run['run_id'] ?? '');
    $workerId = (string)($run['worker_id'] ?? '');
    $leaseToken = (string)($run['lease_token'] ?? '');
    if ($taskId <= 0 || $runId <= 0 || $runtimeId === '' || $workerId === '' || $leaseToken === '' || $db->inTransaction()) {
        return false;
    }
    $clean = hub_pack_job_cleanup_attested($cleanup);
    if ($gpuLease !== null && !hub_runtime_gpu_fence_matches_run($run, $gpuLease)) {
        return false;
    }
    $errorCode = $clean ? 'runtime_lease_lost' : 'cleanup_failed';
    $message = $clean ? 'Pack runtime lease expired' : 'Pack cleanup was not attested';
    $taskLock = (string)($task['lock_token'] ?? '');
    $lockPredicate = $taskLock === '' ? 'lock_token IS NULL' : 'lock_token = :task_lock';
    $db->exec('BEGIN IMMEDIATE');
    try {
        if ($gpuLease !== null) {
            $gpu = hub_runtime_gpu_lease_identity($gpuLease);
            $gpuSet = $clean
                ? "runtime_run_id = NULL, worker_id = NULL, lease_token = NULL, state = 'available', acquired_at = NULL, heartbeat_at = NULL, lease_expires_at = NULL, last_error = NULL, updated_at = :updated_at"
                : "state = 'blocked', last_error = 'cleanup_failed', updated_at = :updated_at";
            if (($gpuLease['state'] ?? '') === 'blocked') {
                $gpuStmt = $db->prepare(
                    "SELECT 1 FROM runtime_resource_leases
                     WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
                       AND lease_token = :lease_token AND state = 'blocked'"
                );
            } else {
                $gpuStmt = $db->prepare(
                    "UPDATE runtime_resource_leases SET {$gpuSet}
                     WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND worker_id = :worker_id
                       AND lease_token = :lease_token AND state IN ('leased', 'recovery_required')"
                );
            }
            $params = [
                ':resource_key' => $gpu['resource_key'],
                ':runtime_run_id' => $gpu['runtime_run_id'],
                ':worker_id' => $gpu['worker_id'],
                ':lease_token' => $gpu['lease_token'],
            ];
            if (($gpuLease['state'] ?? '') !== 'blocked') {
                $params[':updated_at'] = hub_now();
            }
            $gpuStmt->execute($params);
            $gpuMatched = ($gpuLease['state'] ?? '') === 'blocked'
                ? $gpuStmt->fetchColumn() !== false
                : $gpuStmt->rowCount() === 1;
            if (!$gpuMatched) {
                $db->exec('ROLLBACK');
                return false;
            }
        }
        $runStmt = $db->prepare(
            "UPDATE runtime_runs
             SET state = 'failed', finished_at = :finished_at, error_code = :error_code, lease_expires_at = NULL
             WHERE id = :id AND run_id = :run_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at <= :now"
        );
        $now = hub_now();
        $runStmt->execute([
            ':finished_at' => $now,
            ':error_code' => $errorCode,
            ':id' => $runId,
            ':run_id' => $runtimeId,
            ':worker_id' => $workerId,
            ':lease_token' => $leaseToken,
            ':now' => $now,
        ]);
        if ($runStmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return false;
        }
        $taskStmt = $db->prepare(
            "UPDATE tasks
             SET status = 'failed', progress = 100, result_json = NULL, error_code = :error_code,
                 error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at,
                 lock_token = NULL, waiting_reason = NULL, next_attempt_at = NULL
             WHERE id = :id AND task_type = 'pack_job' AND status = 'running' AND {$lockPredicate}"
        );
        $params = [
            ':error_code' => $errorCode,
            ':error_message' => $message,
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':id' => $taskId,
        ];
        if ($taskLock !== '') {
            $params[':task_lock'] = $taskLock;
        }
        $taskStmt->execute($params);
        if ($taskStmt->rowCount() !== 1) {
            $db->exec('ROLLBACK');
            return false;
        }
        hub_apply_task_terminal_retention($db, $taskId, 'failed', $now);
        hub_release_task_artifact_holds($db, $taskId);
        hub_enqueue_task_callback_delivery($db, $taskId);
        $db->exec('COMMIT');

        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function hub_pack_job_lost_fence_outcome(PDO $db, array $task, array $run, array $options, bool $started, ?array $context, array $details, ?callable $pidInspector, ?array $gpuLease = null, ?array $cleanup = null): array
{
    if ($cleanup === null) {
        $cleanup = $started && $context !== null
            ? hub_pack_job_cleanup_after_started_failure($options, $context, $details, $pidInspector, 'runtime_lease_lost')
            : hub_pack_job_no_work_cleanup();
    }
    if (!hub_pack_job_reconcile_lost_fence($db, $task, $run, $cleanup, $gpuLease)) {
        return ['status' => 'fence_lost'];
    }
    $latest = hub_get_task($db, (int)$task['id']);

    return ['status' => (string)($latest['status'] ?? 'failed'), 'error_code' => (string)($latest['error_code'] ?? 'runtime_lease_lost')];
}

function hub_reconcile_expired_pack_job_runs(PDO $db): int
{
    $reconciled = 0;
    foreach (hub_runtime_find_stale($db) as $run) {
        $taskId = (int)($run['task_id'] ?? 0);
        $task = $taskId > 0 ? hub_get_task($db, $taskId) : null;
        if (!is_array($task) || ($task['task_type'] ?? '') !== 'pack_job' || ($task['status'] ?? '') !== 'running') {
            continue;
        }
        $requiresGpu = hub_runtime_task_requires_gpu($task);
        $ownedPids = hub_runtime_gpu_recovery_pids(json_decode((string)($run['owned_gpu_pids_json'] ?? ''), true));
        $cleanup = ($run['state'] ?? '') !== 'running' && trim((string)($run['container_id'] ?? '')) === '' && $ownedPids === []
            ? hub_pack_job_no_work_cleanup()
            : [];
        $gpuLease = null;
        if ($requiresGpu) {
            $candidate = hub_runtime_gpu_fetch($db);
            if (is_array($candidate) && ($candidate['runtime_run_id'] ?? '') === ($run['run_id'] ?? '')) {
                $gpuLease = $candidate;
            }
        }
        if (hub_pack_job_reconcile_lost_fence($db, $task, $run, $cleanup, $gpuLease)) {
            $reconciled++;
        }
    }

    return $reconciled;
}

function hub_run_pack_job_task(PDO $db, array $task, array $options = []): array
{
    if (($task['task_type'] ?? '') !== 'pack_job' || ($task['status'] ?? '') !== 'running') {
        throw new InvalidArgumentException('pack_job_task_required');
    }
    $taskId = (int)$task['id'];
    $leaseSeconds = max(5, (int)($options['lease_seconds'] ?? 60));
    $workerId = hub_pack_job_adapter_worker_id($options);
    $run = hub_pack_job_claim_runtime($db, $task, $workerId, $leaseSeconds);
    if ($run === null) {
        return ['status' => 'fence_lost'];
    }
    $gpuLease = null;
    $started = false;
    $context = null;
    $pidInspector = null;
    $details = [];
    $cleanup = null;
    $privatePromptWorkspace = null;
    $scrubPrivatePrompt = static function () use (&$privatePromptWorkspace): void {
        if ($privatePromptWorkspace !== null) {
            hub_pack_job_scrub_private_prompt($privatePromptWorkspace);
            $privatePromptWorkspace = null;
        }
    };
    try {
        try {
            $resolutionTask = $task;
            $storedVersion = (string)($task['pack_version'] ?? '');
            $storedInput = is_array($task['input'] ?? null) ? $task['input'] : [];
            $storedMode = $storedInput['mode'] ?? 'design';
            $compatibleModes = $storedVersion === '0.1.4'
                ? ['design', 'clone']
                : ['design', 'clone', 'ultimate_clone'];
            if (
                (string)($task['pack_id'] ?? '') === 'tts-voxcpm2'
                && in_array($storedVersion, ['0.1.4', '0.1.5'], true)
                && (string)($task['job'] ?? '') === 'synthesize'
                && (string)($task['requested_mode'] ?? '') === 'voice_generate'
                && (string)($task['accelerator'] ?? '') === 'gpu'
                && in_array($storedMode, $compatibleModes, true)
            ) {
                $resolutionTask['pack_version'] = '0.1.7';
            }
            $contract = hub_resolve_stored_pack_job($db, $resolutionTask);
        } catch (Throwable $e) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, hub_pack_job_failure_code($e), 'Stored Pack job is unavailable', hub_pack_job_no_work_cleanup(), null);
        }
        if (!isset($contract['runner'])) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'job_unavailable', 'Stored Pack job has no runner contract', hub_pack_job_no_work_cleanup(), null);
        }
        $runner = $contract['runner'];
        $runnerConfig = hub_pack_job_runner_config_for_task($contract, (array)($task['input'] ?? []));
        try {
            $assetMounts = hub_pack_job_resolve_asset_mounts($db, $runner, (array)($task['input'] ?? []));
        } catch (Throwable) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'model_assets_unavailable', 'Required offline model or cache assets are unavailable', hub_pack_job_no_work_cleanup(), null);
        }
        $webScreenshotService = null;
        $edgeTtsService = null;
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'web-screenshot' && (string)($task['job'] ?? '') === 'capture') {
            $webScreenshotService = hub_web_screenshot_wsl_service_for_task($db, $task);
            if ($webScreenshotService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'Web Screenshot service is unavailable', hub_pack_job_no_work_cleanup(), null);
            }
        }
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'edge-tts' && (string)($task['job'] ?? '') === 'synthesize') {
            $edgeTtsService = hub_edge_tts_wsl_service_for_task($db, $task);
            if ($edgeTtsService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'Edge TTS service is unavailable', hub_pack_job_no_work_cleanup(), null);
            }
        }
        if (isset($options['executor']) && is_callable($options['executor'])) {
            $executor = $options['executor'];
        } elseif ($webScreenshotService !== null) {
            $executor = static fn (array $context): array => hub_web_screenshot_wsl_executor(
                $webScreenshotService,
                $context,
                isset($options['process_runner']) && is_callable($options['process_runner']) ? $options['process_runner'] : null
            );
        } elseif ($edgeTtsService !== null) {
            $executor = static fn (array $context): array => hub_edge_tts_wsl_executor(
                $edgeTtsService,
                $context,
                isset($options['process_runner']) && is_callable($options['process_runner']) ? $options['process_runner'] : null
            );
        } elseif (($runner['executor'] ?? '') === 'container') {
            $executor = static fn (array $context): array => hub_pack_job_default_executor(
                $context,
                isset($options['command_runner']) && is_callable($options['command_runner']) ? $options['command_runner'] : null,
                isset($options['process_runner']) && is_callable($options['process_runner']) ? $options['process_runner'] : null
            );
        } else {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'No controlled Pack job executor is configured', hub_pack_job_no_work_cleanup(), null);
        }
        if (hub_runtime_task_requires_gpu($task)) {
            $gpuLease = hub_runtime_gpu_acquire_for_task($db, $task, $run, $leaseSeconds);
            if ($gpuLease === null) {
                if (hub_pack_job_wait_without_gpu($db, $taskId, $run, 'gpu_unavailable', max(1, (int)($options['gpu_backoff_seconds'] ?? 30)))) {
                    return ['status' => 'waiting_gpu'];
                }
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null);
            }
            $probe = isset($options['gpu_probe']) && is_callable($options['gpu_probe'])
                ? $options['gpu_probe']
                : static fn (): array => hub_runtime_gpu_probe(isset($options['gpu_probe_runner']) && is_callable($options['gpu_probe_runner']) ? $options['gpu_probe_runner'] : null);
            $preflight = hub_runtime_gpu_preflight($db, $taskId, $run, $gpuLease, (int)$runner['required_vram_mb'], $probe, max(1, (int)($options['gpu_backoff_seconds'] ?? 30)));
            if (empty($preflight['ok'])) {
                if (($preflight['reason'] ?? '') !== 'lost_gpu_lease') {
                    return ['status' => 'waiting_gpu'];
                }
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, $gpuLease);
            }
        }
        try {
            $voiceProfileMount = hub_pack_job_resolve_voice_profile_mount($db, $task, $contract);
        } catch (Throwable $e) {
            $code = $e->getMessage() === 'voice_profile_changed' ? 'voice_profile_changed' : 'voice_profile_unavailable';
            return hub_pack_job_adapter_failure($db, $taskId, $run, $code, 'Managed voice profile is unavailable', hub_pack_job_no_work_cleanup(), $gpuLease);
        }
        $hasPrivatePrompt = isset($voiceProfileMount['prompt_text']);
        $workspace = hub_pack_job_prepare_workspace($db, $task, $contract, $voiceProfileMount);
        if ($hasPrivatePrompt) {
            $privatePromptWorkspace = $workspace;
        }
        if ($voiceProfileMount !== null) {
            unset($voiceProfileMount['prompt_text']);
        }
        hub_pack_job_copy_source_artifact($db, $task, $workspace);
        $audioProbe = isset($options['audio_probe']) && is_callable($options['audio_probe']) ? $options['audio_probe'] : null;
        $sourceAudioAttestation = isset($contract['artifact_contract']['report_attestation']) && is_file($workspace . '/input/source')
            ? hub_pack_job_capture_staged_source_audio_attestation($workspace, $audioProbe)
            : null;
        $context = [
            'db' => $db,
            'task' => $task,
            'run' => $run,
            'workspace' => $workspace,
            'runner' => hub_pack_job_runner_arguments($runner, $task, $run, $workspace, $runnerConfig, $assetMounts, $voiceProfileMount),
        ];
        $pidInspector = $gpuLease === null ? null : ($options['pid_inspector'] ?? static fn (): array => []);
        $baseline = $pidInspector === null ? [] : hub_runtime_gpu_recovery_pids($pidInspector($context));
        $details = hub_pack_job_execution_details(['baseline_pids' => $baseline]);
        // A baseline is needed for GPU recovery but is not proof that this executor owned no process.
        $details['has_process_evidence'] = false;
        if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            $scrubPrivatePrompt();
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, $details, $pidInspector, $gpuLease);
        }
        $startedRun = hub_pack_job_begin_execution($db, $task, $run, $runner, $gpuLease);
        if ($startedRun === null) {
            $scrubPrivatePrompt();
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, $details, $pidInspector, $gpuLease);
        }
        $run = $startedRun;
        $context['run'] = $run;
        $context['runner'] = hub_pack_job_runner_arguments($runner, $task, $run, $workspace, $runnerConfig, $assetMounts, $voiceProfileMount);
        $fenceLost = false;
        $context['started'] = static function (array $startedDetails) use (&$details, &$fenceLost, $db, $task, $run, $gpuLease, $baseline): void {
            $details = hub_pack_job_execution_details($startedDetails, ['baseline_pids' => $baseline]);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                $fenceLost = true;
            }
        };
        $context['tick'] = static function () use ($db, $run, $gpuLease, $leaseSeconds): ?string {
            return hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds);
        };
        $started = true;
        try {
            $result = $executor($context);
        } finally {
            $scrubPrivatePrompt();
        }
        if (!is_array($result)) {
            throw new RuntimeException('runtime_execution_invalid');
        }
        $details = hub_pack_job_execution_details($result, $details);
        if (!$fenceLost && !hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            $fenceLost = true;
        }
        $intent = in_array($result['intent'] ?? null, ['fence_lost', 'cancelled', 'timed_out'], true)
            ? $result['intent']
            : hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds);
        if ($fenceLost || $intent === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease);
        }
        if ($intent === 'cancelled' || $intent === 'timed_out') {
            $result = hub_pack_job_stop_result($options, $context, $intent, $result);
            $details = hub_pack_job_execution_details($result, $details);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease, $cleanup);
            }
            $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
            hub_commit_pack_job_failure($db, $taskId, $run, $intent, $intent, 'Pack job ' . $intent, $cleanup, $gpuLease);
            $latest = hub_get_task($db, $taskId);
            return ['status' => (string)($latest['status'] ?? 'failed'), 'error_code' => (string)($latest['error_code'] ?? $intent)];
        }
        $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            $code = (string)($result['error_code'] ?? 'runtime_exit_nonzero');
            if (preg_match('/^[a-z0-9_:-]{1,120}$/i', $code) !== 1) {
                $code = 'runtime_exit_nonzero';
            }
            return hub_pack_job_adapter_failure($db, $taskId, $run, $code, 'Pack job exited unsuccessfully', $cleanup, $gpuLease);
        }
        $final = hub_finalize_pack_job_success($db, $taskId, $run, $workspace, (array)($task['input'] ?? []), $contract['artifact_contract'], $cleanup, $audioProbe, $gpuLease, $contract['runner_config'] ?? null, $sourceAudioAttestation);
        $latest = hub_get_task($db, $taskId);
        if (($final['ok'] ?? false) !== true && ($latest['status'] ?? '') === 'running' && hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds) === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease, $cleanup);
        }
        return ['status' => (string)($latest['status'] ?? (($final['ok'] ?? false) ? 'success' : 'failed'))] + $final;
    } catch (Throwable $e) {
        $scrubPrivatePrompt();
        if (hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds) === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, $started, $context, $details, $pidInspector, $gpuLease, $cleanup);
        }
        $cleanup = $started && $context !== null
            ? hub_pack_job_cleanup_after_started_failure($options, $context, $details, $pidInspector, 'runtime_execution_failed')
            : hub_pack_job_no_work_cleanup();
        return hub_pack_job_adapter_failure(
            $db,
            $taskId,
            $run,
            hub_pack_job_failure_code($e, 'runtime_execution_failed'),
            'Pack job adapter failed: ' . substr($e->getMessage(), 0, 512),
            $cleanup,
            $gpuLease
        );
    } finally {
        $scrubPrivatePrompt();
    }
}
