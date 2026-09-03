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
    $actionStartedNs = hrtime(true);
    $beginRequestedNs = hrtime(true);
    $beginStats = [];
    $txStartedNs = null;
    $txEndedNs = null;
    $txBeginAt = null;
    $txCommitAt = null;
    $ownsTransaction = false;
    $emitAction = false;
    $outcome = 'failed';
    $result = null;
    $error = null;
    try {
        hub_sqlite_begin_immediate($db, $beginStats);
        $ownsTransaction = true;
        $txStartedNs = hrtime(true);
        $txBeginAt = hub_runtime_telemetry_timestamp();
        $guard = $db->prepare("SELECT 1 FROM tasks WHERE id = :id AND task_type = 'pack_job' AND status = 'running' AND lock_token = :lock_token");
        $guard->execute([':id' => $taskId, ':lock_token' => $taskLock]);
        if ($guard->fetchColumn() === false) {
            $db->exec('COMMIT');
            $ownsTransaction = false;
            $emitAction = true;
            $outcome = 'fence_lost';
        } else {
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
                $ownsTransaction = false;
                $emitAction = true;
                $outcome = 'committed';
            } else {
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
                    $ownsTransaction = false;
                    $emitAction = true;
                    $outcome = 'fence_lost';
                } else {
                    $result = hub_runtime_fetch_run($db, (int)$run['id']);
                    $db->exec('COMMIT');
                    $ownsTransaction = false;
                    $emitAction = true;
                    $outcome = 'committed';
                }
            }
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
                $ownsTransaction = false;
                $emitAction = true;
            } catch (Throwable) {
            }
        }
        $outcome = !empty($beginStats['lock_exhausted']) ? 'lock_exhausted' : 'failed';
        $emitAction = $emitAction || !empty($beginStats['lock_exhausted']);
        $error = $e;
    }
    $txEndedNs = hrtime(true);
    $txCommitAt = hub_runtime_telemetry_timestamp();
    if ($emitAction) {
        $emitStartedNs = hrtime(true);
        hub_runtime_telemetry_emit([
            'action' => 'claim',
            'variant' => 'runtime',
            'outcome' => $outcome,
            'tx_mode' => 'immediate',
            'tx_begin_at' => $txBeginAt,
            'tx_commit_at' => $txCommitAt,
            'pre_tx_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $beginRequestedNs),
            'lock_wait_ms' => (float)($beginStats['lock_wait_ms'] ?? 0.0),
            'lock_wait_kind' => 'begin_immediate',
            'tx_ms' => $txStartedNs === null ? 0.0 : hub_runtime_telemetry_elapsed_ms($txStartedNs, $txEndedNs),
            'post_tx_ms' => hub_runtime_telemetry_elapsed_ms($txEndedNs, $emitStartedNs),
            'total_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $emitStartedNs),
            'retry_count' => (int)($beginStats['retry_count'] ?? 0),
            'skipped_ticks' => 0,
        ]);
    }
    if ($error !== null) {
        throw $error;
    }

    return $result;
}

function hub_pack_job_wait_without_gpu(PDO $db, int $taskId, array $run, string $reason, int $backoffSeconds, array $details = []): bool
{
    if ($db->inTransaction()) {
        throw new LogicException('pack_job_wait_transaction_required');
    }
    $runtime = hub_runtime_gpu_runtime_identity($run);
    $now = hub_now();
    $actionStartedNs = hrtime(true);
    $beginRequestedNs = hrtime(true);
    $beginStats = [];
    $txStartedNs = null;
    $txBeginAt = null;
    $ownsTransaction = false;
    $emitAction = false;
    $outcome = 'failed';
    $result = false;
    $error = null;
    try {
        hub_sqlite_begin_immediate($db, $beginStats);
        $ownsTransaction = true;
        $txStartedNs = hrtime(true);
        $txBeginAt = hub_runtime_telemetry_timestamp();
        if (!hub_runtime_gpu_runtime_fence_in_transaction($db, $run, $taskId)) {
            $db->exec('ROLLBACK');
            $ownsTransaction = false;
            $emitAction = true;
            $outcome = 'fence_lost';
        } else {
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
                     waiting_detail_json = :waiting_detail_json,
                     lock_token = NULL, updated_at = :now
                 WHERE id = :id AND task_type = 'pack_job' AND status = 'running'"
            );
            $taskStmt->execute([
                ':reason' => $reason,
                ':next_attempt_at' => hub_runtime_lease_until(max(1, $backoffSeconds)),
                ':waiting_detail_json' => hub_task_waiting_detail_json($details),
                ':now' => $now,
                ':id' => $taskId,
            ]);
            if ($runStmt->rowCount() !== 1 || $taskStmt->rowCount() !== 1) {
                $db->exec('ROLLBACK');
                $ownsTransaction = false;
                $emitAction = true;
                $outcome = 'fence_lost';
            } else {
                $db->exec('COMMIT');
                $ownsTransaction = false;
                $emitAction = true;
                $outcome = 'committed';
                $result = true;
            }
        }
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
                $ownsTransaction = false;
                $emitAction = true;
            } catch (Throwable) {
            }
        }
        $outcome = !empty($beginStats['lock_exhausted']) ? 'lock_exhausted' : 'failed';
        $emitAction = $emitAction || !empty($beginStats['lock_exhausted']);
        $error = $e;
    }
    $txEndedNs = hrtime(true);
    $txCommitAt = hub_runtime_telemetry_timestamp();
    if ($emitAction) {
        $emitStartedNs = hrtime(true);
        hub_runtime_telemetry_emit([
            'action' => 'wait',
            'variant' => 'gpu',
            'outcome' => $outcome,
            'tx_mode' => 'immediate',
            'tx_begin_at' => $txBeginAt,
            'tx_commit_at' => $txCommitAt,
            'pre_tx_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $beginRequestedNs),
            'lock_wait_ms' => (float)($beginStats['lock_wait_ms'] ?? 0.0),
            'lock_wait_kind' => 'begin_immediate',
            'tx_ms' => $txStartedNs === null ? 0.0 : hub_runtime_telemetry_elapsed_ms($txStartedNs, $txEndedNs),
            'post_tx_ms' => hub_runtime_telemetry_elapsed_ms($txEndedNs, $emitStartedNs),
            'total_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $emitStartedNs),
            'retry_count' => (int)($beginStats['retry_count'] ?? 0),
            'skipped_ticks' => 0,
        ]);
    }
    if ($error !== null) {
        throw $error;
    }

    return $result;
}

function hub_pack_job_no_work_cleanup(): array
{
    return ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true];
}

function hub_pack_job_resident_token_setting(array $resident): ?string
{
    $modeSetting = (string)($resident['mode_setting'] ?? '');
    if (preg_match('/^([A-Z][A-Z0-9_]*)_EXECUTION_MODE$/', $modeSetting, $matches) !== 1) {
        return null;
    }

    return $matches[1] . '_INTERNAL_JOB_TOKEN';
}

function hub_pack_job_resident_service(PDO $db, array $task, array $contract): ?array
{
    $resident = $contract['resident'] ?? null;
    if (!is_array($resident) || ($resident['protocol'] ?? null) !== 'service_data_v1') {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT * FROM services
         WHERE pack_id = :pack_id AND pack_version = :pack_version
         ORDER BY id ASC LIMIT 1"
    );
    $stmt->execute([
        ':pack_id' => (string)($task['pack_id'] ?? ''),
        ':pack_version' => (string)($task['pack_version'] ?? ''),
    ]);
    $service = $stmt->fetch();
    if (!is_array($service)) {
        return null;
    }
    $settings = hub_service_settings_values($db, $service);
    $modeSetting = (string)($resident['mode_setting'] ?? '');
    if (($settings[$modeSetting] ?? null) !== ($resident['mode_value'] ?? null)) {
        return null;
    }
    $plan = hub_pack_job_resident_plan_for_service($db, $service, $contract);

    return $plan ?? ['eligible' => false, 'reason' => 'resident_service_unavailable'];
}

function hub_pack_job_resident_uses_cpu(array $residentPlan): bool
{
    $resident = $residentPlan['resident'] ?? null;
    $settings = $residentPlan['settings'] ?? null;
    if (!is_array($resident) || !is_array($settings)) {
        return false;
    }
    $setting = $resident['cpu_fallback_setting'] ?? null;
    $value = $resident['cpu_fallback_value'] ?? null;

    return is_string($setting) && is_string($value) && ($settings[$setting] ?? null) === $value;
}

function hub_whisper_wsl_pascal_job_capability_error(array $task, ?array $runnerConfig, ?array $residentPlan): ?string
{
    if (hub_platform_id() !== 'windows' || (string)($task['pack_id'] ?? '') !== 'whisper-asr' || (string)($task['job'] ?? '') !== 'transcribe'
        || !is_array($residentPlan) || empty($residentPlan['eligible'])) {
        return null;
    }
    $runtime = hub_whisper_wsl_runtime_profile((array)$residentPlan['service']);
    if ($runtime === null || ($runtime['profile_id'] ?? null) !== 'pascal-cu118') {
        return null;
    }
    $input = is_array($task['input'] ?? null) ? $task['input'] : [];

    return hub_whisper_pascal_reflow_capability_error($input, $runnerConfig);
}

/**
 * Pascal CUDA 11.8 只接受已驗收的 small Whisper 與 CKIP 字幕重切組合。
 * WhisperX 對齊與 Pyannote 說話者分離仍需要另一個獨立的資產／VRAM 驗收切片。
 */
function hub_whisper_pascal_reflow_capability_error(array $input, ?array $runnerConfig): ?string
{
    if (($runnerConfig['alias'] ?? null) !== 'small') {
        return 'Whisper CUDA 11.8 on Pascal requires model=small.';
    }
    if (!empty($input['word_timestamps'])) {
        return 'Whisper CUDA 11.8 on Pascal does not support WhisperX word timestamps.';
    }
    if (!empty($input['diarization'])) {
        return 'Whisper CUDA 11.8 on Pascal does not support speaker diarization.';
    }

    return null;
}

function hub_pack_job_resident_plan_for_service(PDO $db, array $service, array $contract, bool $requireResidentMode = true): ?array
{
    $resident = $contract['resident'] ?? null;
    if (!is_array($resident) || ($resident['protocol'] ?? null) !== 'service_data_v1') {
        return null;
    }
    if ((string)($service['install_status'] ?? '') !== 'installed'
        || (int)($service['enabled'] ?? 0) !== 1
        || (string)($service['runtime_status'] ?? '') !== 'running'
        || (int)($service['local_port'] ?? 0) < 1) {
        return null;
    }
    $settings = hub_service_settings_values($db, $service);
    $modeSetting = (string)$resident['mode_setting'];
    $tokenSetting = hub_pack_job_resident_token_setting($resident);
    if (($requireResidentMode && ($settings[$modeSetting] ?? null) !== $resident['mode_value'])
        || $tokenSetting === null || trim((string)($settings[$tokenSetting] ?? '')) === '') {
        return null;
    }

    return ['eligible' => true, 'service' => $service, 'settings' => $settings, 'resident' => $resident, 'token_setting' => $tokenSetting];
}

function hub_pack_job_resident_base_url(array $service): string
{
    $port = (int)($service['local_port'] ?? 0);
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('resident_service_unavailable');
    }

    return 'http://127.0.0.1:' . $port;
}

function hub_pack_job_resident_progress(?callable $tick, float $intervalSeconds = 10.0, bool $continueAfterStop = false): ?callable
{
    if ($tick === null) {
        return null;
    }
    $nextAt = 0.0;

    return static function () use ($tick, $intervalSeconds, $continueAfterStop, &$nextAt): ?string {
        $now = microtime(true);
        if ($now < $nextAt) {
            return null;
        }
        $nextAt = $now + max(0.0, $intervalSeconds);
        $intent = $tick();

        if ($intent === 'fence_lost') {
            return $intent;
        }

        return !$continueAfterStop && in_array($intent, ['cancelled', 'timed_out'], true) ? $intent : null;
    };
}

function hub_pack_job_resident_heartbeat_interval(int $leaseSeconds, mixed $requestedInterval = null): float
{
    $leaseSeconds = max(5, $leaseSeconds);
    $maximum = $leaseSeconds / 3;
    $requested = is_numeric($requestedInterval) ? (float)$requestedInterval : min(10.0, $maximum);

    return min($maximum, max(1.0, $requested));
}

function hub_pack_job_resident_transport_intent(Throwable $error): ?string
{
    $message = $error->getMessage();

    return preg_match('/^resident_transport_(fence_lost|cancelled|timed_out)$/', $message, $matches) === 1 ? $matches[1] : null;
}

function hub_pack_job_resident_transport(string $method, string $url, array $headers, ?array $payload = null, ?callable $transport = null, float $timeoutSeconds = 15.0, ?callable $progress = null): array
{
    if ($transport !== null) {
        $intent = $progress === null ? null : $progress();
        if ($intent !== null) {
            throw new RuntimeException('resident_transport_' . $intent);
        }
        $response = $transport($method, $url, $headers, $payload, $progress, $timeoutSeconds);
        if (!is_array($response)) {
            throw new RuntimeException('resident_transport_invalid');
        }
        $intent = $progress === null ? null : $progress();
        if ($intent !== null) {
            throw new RuntimeException('resident_transport_' . $intent);
        }
        return $response;
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('resident_transport_unavailable');
    }
    $body = $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('resident_transport_invalid');
    }
    $timeoutMilliseconds = max(1, (int)floor(max(0.001, $timeoutSeconds) * 1000));
    if (!defined('CURLOPT_TIMEOUT_MS') || !defined('CURLOPT_CONNECTTIMEOUT_MS')) {
        throw new RuntimeException('resident_transport_unavailable');
    }
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('resident_transport_unavailable');
    }
    try {
        $intent = null;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            constant('CURLOPT_CONNECTTIMEOUT_MS') => min(3000, $timeoutMilliseconds),
            constant('CURLOPT_TIMEOUT_MS') => $timeoutMilliseconds,
        ] + ($body === null ? [] : [CURLOPT_POSTFIELDS => $body]);
        if ($progress !== null) {
            $callback = static function (...$unused) use ($progress, &$intent): int {
                try {
                    $next = $progress();
                } catch (Throwable) {
                    $next = 'fence_lost';
                }
                if (in_array($next, ['fence_lost', 'cancelled', 'timed_out'], true)) {
                    $intent = $next;
                    return 1;
                }

                return 0;
            };
            $options[CURLOPT_NOPROGRESS] = false;
            if (defined('CURLOPT_XFERINFOFUNCTION')) {
                $options[constant('CURLOPT_XFERINFOFUNCTION')] = $callback;
            } else {
                $options[CURLOPT_PROGRESSFUNCTION] = $callback;
            }
        }
        $configured = curl_setopt_array($handle, $options);
        $raw = $configured ? curl_exec($handle) : false;
        if ($intent !== null) {
            throw new RuntimeException('resident_transport_' . $intent);
        }
        if ($raw === false) {
            throw new RuntimeException('resident_transport_unavailable');
        }
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('resident_transport_invalid');
        }
        return ['status' => (int)(curl_getinfo($handle, CURLINFO_RESPONSE_CODE) ?: 0), 'json' => $json];
    } finally {
        curl_close($handle);
    }
}

function hub_pack_job_resident_request(array $residentPlan, string $method, string $path, ?array $payload = null, ?callable $transport = null, float $timeoutSeconds = 15.0, ?callable $progress = null): array
{
    if (!preg_match('~^/internal(?:/|$)~', $path)) {
        throw new RuntimeException('resident_endpoint_invalid');
    }
    $token = (string)($residentPlan['settings'][$residentPlan['token_setting']] ?? '');
    if ($token === '') {
        throw new RuntimeException('resident_service_unavailable');
    }
    return hub_pack_job_resident_transport(
        $method,
        hub_pack_job_resident_base_url((array)$residentPlan['service']) . $path,
        array_merge(['Accept: application/json', 'X-AIHub-Internal-Token: ' . $token], $payload === null ? [] : ['Content-Type: application/json']),
        $payload,
        $transport,
        $timeoutSeconds,
        $progress,
    );
}

function hub_pack_job_resident_status_payload(array $residentPlan, string $residentRunId, ?callable $transport = null, ?callable $progress = null, float $timeoutSeconds = 15.0): ?array
{
    if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $residentRunId) !== 1) {
        return null;
    }
    try {
        $response = hub_pack_job_resident_request($residentPlan, 'GET', '/internal/jobs/' . rawurlencode($residentRunId), null, $transport, $timeoutSeconds, $progress);
    } catch (Throwable) {
        return null;
    }
    $json = $response['json'] ?? null;
    if ((int)($response['status'] ?? 0) !== 200 || !is_array($json)
        || ($json['run_id'] ?? null) !== $residentRunId
        || !is_string($json['state'] ?? null)
        || !in_array($json['state'], ['running', 'succeeded', 'failed', 'cancelled', 'unknown'], true)) {
        return null;
    }

    $result = ['state' => (string)$json['state']];
    if ($json['state'] === 'failed' && array_key_exists('error_code', $json)) {
        if (!is_string($json['error_code']) || preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $json['error_code']) !== 1) {
            return null;
        }
        $result['error_code'] = $json['error_code'];
    }

    return $result;
}

function hub_pack_job_resident_status(array $residentPlan, string $residentRunId, ?callable $transport = null, ?callable $progress = null, float $timeoutSeconds = 15.0): ?string
{
    $payload = hub_pack_job_resident_status_payload($residentPlan, $residentRunId, $transport, $progress, $timeoutSeconds);

    return is_array($payload) ? (string)$payload['state'] : null;
}

function hub_pack_job_resident_capacity(array $residentPlan, ?callable $transport = null, ?callable $progress = null): ?string
{
    try {
        $response = hub_pack_job_resident_request($residentPlan, 'GET', '/internal/capacity', null, $transport, 15, $progress);
    } catch (Throwable) {
        return null;
    }
    $json = $response['json'] ?? null;
    if ((int)($response['status'] ?? 0) !== 200 || !is_array($json)
        || !is_string($json['model_state'] ?? null)
        || !in_array($json['model_state'], ['cold', 'ready', 'running'], true)
        || !is_int($json['active_runs'] ?? null) || $json['active_runs'] < 0) {
        return null;
    }

    return $json['model_state'];
}

function hub_pack_job_resident_confirm_terminal(array $context, string $residentRunId, ?callable $transport = null, ?callable $progress = null): array
{
    $residentPlan = $context['resident_plan'] ?? null;
    if (!is_array($residentPlan)) {
        return [];
    }
    $graceSeconds = 60;
    $pollSeconds = max(1, min(30, (int)($context['resident_status_poll_seconds'] ?? 5)));
    $clock = isset($context['resident_clock']) && is_callable($context['resident_clock'])
        ? $context['resident_clock']
        : static fn (): float => microtime(true);
    $sleeper = isset($context['resident_sleeper']) && is_callable($context['resident_sleeper'])
        ? $context['resident_sleeper']
        : static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int)round($seconds * 1000000));
            }
        };
    $deadline = $clock() + $graceSeconds;
    do {
        $intent = $progress === null ? null : $progress();
        if ($intent !== null) {
            return ['intent' => $intent];
        }
        $remaining = $deadline - $clock();
        if ($remaining <= 0) {
            break;
        }
        $status = hub_pack_job_resident_status_payload($residentPlan, $residentRunId, $transport, $progress, min(15.0, $remaining));
        $state = is_array($status) ? ($status['state'] ?? null) : null;
        if (in_array($state, ['succeeded', 'failed', 'cancelled'], true)) {
            return ['state' => $state] + (isset($status['error_code']) ? ['error_code' => $status['error_code']] : []);
        }
        $remaining = $deadline - $clock();
        if ($remaining <= 0) {
            break;
        }
        $sleeper(min((float)$pollSeconds, $remaining));
    } while (true);

    return [];
}

function hub_pack_job_resident_run_id(): string
{
    return 'resident-' . bin2hex(random_bytes(20));
}

function hub_pack_job_resident_stage_root(array $service): string
{
    $runtimeDir = dirname(hub_path((string)($service['compose_file'] ?? '')));
    $runtimeDir = realpath($runtimeDir);
    if ($runtimeDir === false || is_link($runtimeDir)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    $root = $runtimeDir . '/resident_jobs';
    if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700, true)) || !is_dir($root)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    $root = realpath($root);
    if ($root === false || is_link($root) || !str_starts_with($root, $runtimeDir . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('resident_stage_unavailable');
    }

    return $root;
}

function hub_pack_job_resident_stage_path(array $service, string $residentRunId): string
{
    if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $residentRunId) !== 1) {
        throw new RuntimeException('resident_stage_unavailable');
    }

    return hub_pack_job_resident_stage_root($service) . '/' . $residentRunId;
}

function hub_whisper_wsl_resident_stage(array $service, string $residentRunId): ?array
{
    if (hub_platform_id() !== 'windows' || (string)($service['pack_id'] ?? '') !== 'whisper-asr'
        || preg_match('/^[a-z0-9][a-z0-9_-]*$/', (string)($service['service_key'] ?? '')) !== 1
        || preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $residentRunId) !== 1) {
        return null;
    }
    $runtime = hub_whisper_wsl_runtime_profile($service);
    if ($runtime === null) {
        return null;
    }
    $root = (string)$runtime['runtime_root'] . '/services/' . (string)$service['service_key'] . '/data/resident_jobs';

    return ['runtime' => $runtime, 'root' => $root, 'stage' => $root . '/' . $residentRunId];
}

/**
 * Windows Whisper resident service 使用 WSL ext4 cache，不可沿用 Windows
 * Control Plane storage path，否則預檢與實際 container 掛載會落在不同位置。
 *
 * @return array{runtime: array<string, mixed>, script: string}|null
 */
function hub_whisper_wsl_resident_asset_preflight(array $service, array $runner, array $input, ?array $profile = null): ?array
{
    $runtime = hub_whisper_wsl_runtime_profile($service, $profile);
    if ($runtime === null) {
        return null;
    }
    $descriptors = hub_pack_async_job_runner_asset_mounts($runner['asset_mounts'] ?? []);
    if ($descriptors === null) {
        return null;
    }

    $roots = [
        'models' => (string)($runtime['models_root'] ?? ''),
        'cache' => rtrim((string)($runtime['runtime_root'] ?? ''), '/') . '/cache',
    ];
    $checks = ['set -eu'];
    foreach (hub_pack_job_asset_mounts_for_input($descriptors, $input) as $descriptor) {
        $root = $roots[(string)($descriptor['storage'] ?? '')] ?? '';
        $subdir = (string)($descriptor['host_subdir'] ?? '');
        if ($root === '' || $subdir === '') {
            return null;
        }
        try {
            $source = hub_container_path(rtrim($root, '/') . '/' . $subdir);
        } catch (InvalidArgumentException) {
            return null;
        }
        $checks[] = 'test -d ' . hub_wsl_shell_literal($source);
        $checks[] = 'test ! -L ' . hub_wsl_shell_literal($source);
        foreach ((array)($descriptor['required_paths'] ?? []) as $requiredPath) {
            if (!is_string($requiredPath) || $requiredPath === '') {
                return null;
            }
            try {
                $required = hub_container_path($source . '/' . $requiredPath);
            } catch (InvalidArgumentException) {
                return null;
            }
            $checks[] = 'test -f ' . hub_wsl_shell_literal($required);
            $checks[] = 'test ! -L ' . hub_wsl_shell_literal($required);
        }
    }

    return ['runtime' => $runtime, 'script' => implode("\n", $checks) . "\n"];
}

function hub_whisper_wsl_resident_prepare_stage(array $stage, string $workspace, ?array $voiceProfileMount): void
{
    $voiceSource = $voiceProfileMount['source'] ?? null;
    if ($voiceSource !== null && (!is_string($voiceSource) || !is_file($voiceSource) || is_link($voiceSource))) {
        throw new RuntimeException('voice_profile_unavailable');
    }
    $script = "set -eu\n"
        . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
        . 'stage_root=' . hub_wsl_shell_literal((string)$stage['root']) . "\n"
        . 'stage=' . hub_wsl_shell_literal((string)$stage['stage']) . "\n"
        . 'windows_voice_source=' . hub_wsl_shell_literal((string)($voiceSource ?? '')) . "\n"
        . 'case "$stage" in "$stage_root"/resident-*) ;; *) echo "Invalid Whisper resident stage." >&2; exit 2;; esac' . "\n"
        . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
        . 'if [ -e "$stage" ] || [ -L "$stage" ] || [ ! -d "$host_workspace/input" ] || [ -L "$host_workspace/input" ]; then echo "Whisper resident stage is unavailable." >&2; exit 2; fi' . "\n"
        . 'copy_required() { source=$1; destination=$2; [ -f "$source" ] && [ ! -L "$source" ] || exit 2; cp -- "$source" "$destination"; }' . "\n"
        . 'install -d -m 0700 "$stage/input" "$stage/output" "$stage/checkpoints"' . "\n"
        . 'copy_required "$host_workspace/input/request.json" "$stage/input/request.json"' . "\n"
        . 'copy_required "$host_workspace/input/runner_config.json" "$stage/input/runner_config.json"' . "\n"
        . 'copy_required "$host_workspace/input/source" "$stage/input/source"' . "\n"
        . 'if [ -n "$windows_voice_source" ]; then host_voice_source="$(wslpath -a "$windows_voice_source")"; copy_required "$host_voice_source" "$stage/input/source"; fi' . "\n";
    $result = hub_run_command(hub_wsl_script_command((array)$stage['runtime'], $script), 60);
    if ((int)($result['exit_code'] ?? 1) !== 0) {
        throw new RuntimeException('resident_stage_unavailable');
    }
}

function hub_pack_job_resident_copy_file(string $source, string $destination): void
{
    if (!is_file($source) || is_link($source) || file_exists($destination) || is_link($destination) || !copy($source, $destination)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    @chmod($destination, 0600);
}

function hub_pack_job_resident_prepare_stage(array $residentPlan, string $residentRunId, string $workspace, ?array $voiceProfileMount = null): string
{
    $workspace = realpath($workspace);
    if ($workspace === false || is_link($workspace) || !is_dir($workspace . '/input')) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    $wslStage = hub_whisper_wsl_resident_stage((array)$residentPlan['service'], $residentRunId);
    if ($wslStage !== null) {
        hub_whisper_wsl_resident_prepare_stage($wslStage, $workspace, $voiceProfileMount);

        return (string)$wslStage['stage'];
    }
    $stage = hub_pack_job_resident_stage_path((array)$residentPlan['service'], $residentRunId);
    if (file_exists($stage) || is_link($stage) || !mkdir($stage, 0700)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    try {
        foreach (['input', 'output', 'checkpoints'] as $name) {
            if (!mkdir($stage . '/' . $name, 0700) || is_link($stage . '/' . $name)) {
                throw new RuntimeException('resident_stage_unavailable');
            }
        }
        foreach (['request.json', 'runner_config.json', 'source'] as $name) {
            $source = $workspace . '/input/' . $name;
            if (is_file($source)) {
                hub_pack_job_resident_copy_file($source, $stage . '/input/' . $name);
            }
        }
        if (!is_file($stage . '/input/request.json')) {
            throw new RuntimeException('resident_stage_unavailable');
        }
        if ($voiceProfileMount !== null) {
            $source = $voiceProfileMount['source'] ?? null;
            if (!is_string($source) || !is_file($source) || is_link($source)) {
                throw new RuntimeException('voice_profile_unavailable');
            }
            $destination = $stage . '/input/source';
            if (is_file($destination)) {
                @unlink($destination);
            }
            hub_pack_job_resident_copy_file($source, $destination);
        }
        $realStage = realpath($stage);
        $root = hub_pack_job_resident_stage_root((array)$residentPlan['service']);
        if ($realStage === false || !str_starts_with($realStage, $root . DIRECTORY_SEPARATOR) || is_link($realStage)) {
            throw new RuntimeException('resident_stage_unavailable');
        }

        return $realStage;
    } catch (Throwable $e) {
        try {
            hub_pack_job_resident_remove_stage((array)$residentPlan['service'], $residentRunId);
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_pack_job_resident_remove_tree(string $path, string $root): void
{
    $realRoot = realpath($root);
    $realPath = realpath($path);
    if ($realRoot === false || $realPath === false || $realPath === $realRoot || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR) || is_link($realPath)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    foreach (scandir($realPath) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $child = $realPath . '/' . $name;
        if (is_link($child) || is_file($child)) {
            if (!@unlink($child)) {
                throw new RuntimeException('resident_stage_unavailable');
            }
            continue;
        }
        if (!is_dir($child)) {
            throw new RuntimeException('resident_stage_unavailable');
        }
        hub_pack_job_resident_remove_tree($child, $realRoot);
    }
    if (!@rmdir($realPath)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
}

function hub_pack_job_resident_remove_stage(array $service, string $residentRunId): void
{
    $wslStage = hub_whisper_wsl_resident_stage($service, $residentRunId);
    if ($wslStage !== null) {
        $script = 'set -eu' . "\n"
            . 'stage_root=' . hub_wsl_shell_literal((string)$wslStage['root']) . "\n"
            . 'stage=' . hub_wsl_shell_literal((string)$wslStage['stage']) . "\n"
            . 'case "$stage" in "$stage_root"/resident-*) ;; *) echo "Invalid Whisper resident stage." >&2; exit 2;; esac' . "\n"
            . 'rm -rf -- "$stage"';
        $result = hub_run_command(hub_wsl_script_command((array)$wslStage['runtime'], $script), 60);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('resident_stage_unavailable');
        }

        return;
    }
    $root = hub_pack_job_resident_stage_root($service);
    $stage = $root . '/' . $residentRunId;
    if (!file_exists($stage) && !is_link($stage)) {
        return;
    }
    if (is_link($stage)) {
        throw new RuntimeException('resident_stage_unavailable');
    }
    hub_pack_job_resident_remove_tree($stage, $root);
}

/**
 * Resident task 僅回收固定 output 根目錄下一層的 Pack 宣告檔案。
 * runtime run 的 workspace 與 artifact snapshot 都可能來自資料庫，因此先解析實體
 * workspace/output，再拒絕分隔符、Windows ADS 與 link，才交給 copy()。
 */
function hub_pack_job_resident_output_file(string $workspace, string $name): string
{
    if (
        $name === ''
        || $name === '.'
        || $name === '..'
        || preg_match('/\A[.A-Za-z0-9][A-Za-z0-9._-]{0,254}\z/D', $name) !== 1
    ) {
        throw new RuntimeException('resident_output_unavailable');
    }

    clearstatcache(true, $workspace);
    $workspaceReal = realpath($workspace);
    if ($workspaceReal === false || !is_dir($workspaceReal) || is_link($workspace)) {
        throw new RuntimeException('resident_output_unavailable');
    }

    $outputDir = rtrim($workspaceReal, '/\\') . DIRECTORY_SEPARATOR . 'output';
    clearstatcache(true, $outputDir);
    $outputReal = realpath($outputDir);
    if (
        $outputReal === false
        || !is_dir($outputReal)
        || is_link($outputDir)
        || !hub_storage_paths_equal(dirname($outputReal), $workspaceReal)
    ) {
        throw new RuntimeException('resident_output_unavailable');
    }

    $path = rtrim($outputReal, '/\\') . DIRECTORY_SEPARATOR . $name;
    clearstatcache(true, $path);
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('resident_output_unavailable');
    }

    return $path;
}

function hub_pack_job_resident_copy_output(string $stage, string $workspace, array $artifactContract, ?array $service = null): void
{
    $workspaceInput = $workspace;
    $workspace = realpath($workspace);
    if ($service !== null && ($wslStage = hub_whisper_wsl_resident_stage($service, basename($stage))) !== null) {
        if (!hub_storage_paths_equal($stage, (string)$wslStage['stage']) || $workspace === false || is_link($workspaceInput) || !is_dir($workspace . '/output')) {
            throw new RuntimeException('resident_output_unavailable');
        }
        $copies = '';
        foreach ((array)($artifactContract['artifacts'] ?? []) as $artifact) {
            $name = is_array($artifact) ? (string)($artifact['path'] ?? '') : '';
            if ($name === '' || basename($name) !== $name) {
                throw new RuntimeException('resident_output_unavailable');
            }
            $copies .= 'copy_optional ' . hub_wsl_shell_literal($name) . "\n";
        }
        $script = "set -eu\n"
            . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
            . 'stage_root=' . hub_wsl_shell_literal((string)$wslStage['root']) . "\n"
            . 'stage=' . hub_wsl_shell_literal((string)$wslStage['stage']) . "\n"
            . 'case "$stage" in "$stage_root"/resident-*) ;; *) echo "Invalid Whisper resident stage." >&2; exit 2;; esac' . "\n"
            . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
            . 'if [ ! -d "$host_workspace/output" ] || [ -L "$host_workspace/output" ]; then echo "Whisper output is unavailable." >&2; exit 2; fi' . "\n"
            . 'copy_optional() { source="$stage/output/$1"; [ ! -e "$source" ] && return 0; [ -f "$source" ] && [ ! -L "$source" ] || exit 2; cp -- "$source" "$host_workspace/output/$1"; }' . "\n"
            . $copies;
        $result = hub_run_command(hub_wsl_script_command((array)$wslStage['runtime'], $script), 60);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException('resident_output_unavailable');
        }

        return;
    }
    $stageInput = $stage;
    $stage = realpath($stage);
    if ($workspace === false || $stage === false || is_link($workspaceInput) || is_link($stageInput)) {
        throw new RuntimeException('resident_output_unavailable');
    }
    foreach ((array)($artifactContract['artifacts'] ?? []) as $artifact) {
        $name = is_array($artifact) ? (string)($artifact['path'] ?? '') : '';
        if ($name === '' || basename($name) !== $name) {
            throw new RuntimeException('resident_output_unavailable');
        }
        $source = hub_pack_job_resident_output_file($stageInput, $name);
        if (!is_file($source) || is_link($source)) {
            continue;
        }
        $destination = hub_pack_job_resident_output_file($workspaceInput, $name);
        if (!copy($source, $destination)) {
            throw new RuntimeException('resident_output_unavailable');
        }
    }
}

function hub_pack_job_resident_record(PDO $db, array $run, array $task, array $service, string $residentRunId, string $lifecycle): bool
{
    if (!in_array($lifecycle, ['dispatched', 'cancel_requested', 'unconfirmed', 'reconciled'], true)) {
        throw new InvalidArgumentException('resident_lifecycle_invalid');
    }
    $now = hub_now();
    $write = static function () use ($db, $run, $task, $service, $residentRunId, $lifecycle, $now): void {
        $stmt = $db->prepare(
            'INSERT INTO resident_job_runs
                (runtime_run_id, task_id, service_id, resident_run_id, lifecycle, dispatched_at, cancel_requested_at, unconfirmed_at, reconciled_at, updated_at)
             VALUES
                (:runtime_run_id, :task_id, :service_id, :resident_run_id, :lifecycle, :dispatched_at, :cancel_requested_at, :unconfirmed_at, :reconciled_at, :updated_at)
             ON CONFLICT(runtime_run_id) DO UPDATE SET lifecycle = excluded.lifecycle,
                cancel_requested_at = COALESCE(excluded.cancel_requested_at, resident_job_runs.cancel_requested_at),
                unconfirmed_at = COALESCE(excluded.unconfirmed_at, resident_job_runs.unconfirmed_at),
                reconciled_at = COALESCE(excluded.reconciled_at, resident_job_runs.reconciled_at), updated_at = excluded.updated_at'
        );
        $stmt->execute([
            ':runtime_run_id' => (string)$run['run_id'],
            ':task_id' => (int)$task['id'],
            ':service_id' => (int)$service['id'],
            ':resident_run_id' => $residentRunId,
            ':lifecycle' => $lifecycle,
            ':dispatched_at' => $now,
            ':cancel_requested_at' => $lifecycle === 'cancel_requested' ? $now : null,
            ':unconfirmed_at' => $lifecycle === 'unconfirmed' ? $now : null,
            ':reconciled_at' => $lifecycle === 'reconciled' ? $now : null,
            ':updated_at' => $now,
        ]);
    };
    if ($db->inTransaction()) {
        $write();

        return true;
    }
    hub_sqlite_begin_immediate($db);
    try {
        $write();
        $db->exec('COMMIT');

        return true;
    } catch (Throwable $e) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $e;
    }
}

function hub_pack_job_resident_existing(PDO $db, array $run): ?array
{
    $stmt = $db->prepare('SELECT * FROM resident_job_runs WHERE runtime_run_id = :runtime_run_id');
    $stmt->execute([':runtime_run_id' => (string)($run['run_id'] ?? '')]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function hub_pack_job_resident_terminal_result(PDO $db, array $context, string $residentRunId, string $stage, string $state, ?string $errorCode = null): array
{
    $residentPlan = (array)$context['resident_plan'];
    hub_pack_job_resident_copy_output($stage, (string)$context['workspace'], (array)$context['contract']['artifact_contract'], (array)$residentPlan['service']);
    hub_pack_job_resident_remove_stage((array)$residentPlan['service'], $residentRunId);
    hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'reconciled');
    if ($state === 'succeeded') {
        return ['exit_code' => 0, 'completed_no_process_evidence' => true, 'cleanup' => hub_pack_job_no_work_cleanup(), 'resident_terminal' => true];
    }

    return [
        'exit_code' => 1,
        'error_code' => $state === 'cancelled' ? 'cancelled' : (is_string($errorCode) && preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $errorCode) === 1 ? $errorCode : 'resident_job_failed'),
        'completed_no_process_evidence' => true,
        'cleanup' => hub_pack_job_no_work_cleanup(),
        'resident_terminal' => true,
    ] + ($state === 'cancelled' ? ['intent' => 'cancelled'] : []);
}

function hub_pack_job_resident_executor(array $context, ?callable $transport = null): array
{
    $db = $context['db'] ?? null;
    $residentPlan = $context['resident_plan'] ?? null;
    if (!$db instanceof PDO || !is_array($residentPlan)) {
        throw new RuntimeException('resident_service_unavailable');
    }
    $existing = hub_pack_job_resident_existing($db, (array)$context['run']);
    if ($existing !== null) {
        return [
            'exit_code' => 1,
            'error_code' => 'resident_dispatch_duplicate',
            'completed_no_process_evidence' => true,
            'cleanup' => [],
        ];
    }
    $residentRunId = hub_pack_job_resident_run_id();
    $stage = hub_pack_job_resident_prepare_stage($residentPlan, $residentRunId, (string)$context['workspace'], $context['voice_profile_mount'] ?? null);
    hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'dispatched');
    $context['resident_run_id'] = $residentRunId;
    $context['resident_stage'] = $stage;
    $context['started']([]);
    $progress = hub_pack_job_resident_progress(
        isset($context['tick']) && is_callable($context['tick']) ? $context['tick'] : null,
        (float)($context['resident_heartbeat_interval_seconds'] ?? 10),
    );
    $intent = null;
    try {
        $start = hub_pack_job_resident_request($residentPlan, 'POST', '/internal/jobs', ['run_id' => $residentRunId], $transport, (int)($context['runner']['timeout_seconds'] ?? 15), $progress);
        if ((int)($start['status'] ?? 0) !== 200 || !is_array($start['json'] ?? null) || ($start['json']['run_id'] ?? null) !== $residentRunId) {
            throw new RuntimeException('resident_dispatch_unconfirmed');
        }
    } catch (Throwable $error) {
        $intent = hub_pack_job_resident_transport_intent($error);
    }
    if ($intent === null) {
        try {
            $confirmed = hub_pack_job_resident_confirm_terminal($context, $residentRunId, $transport, $progress);
        } catch (Throwable $error) {
            $confirmed = [];
            $intent = hub_pack_job_resident_transport_intent($error);
        }
        if (isset($confirmed['state'])) {
            return hub_pack_job_resident_terminal_result($db, $context, $residentRunId, $stage, (string)$confirmed['state'], isset($confirmed['error_code']) ? (string)$confirmed['error_code'] : null);
        }
        $intent ??= $confirmed['intent'] ?? null;
    }
    hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'unconfirmed');

    return [
        'exit_code' => 1,
        'error_code' => 'resident_dispatch_unconfirmed',
        'completed_no_process_evidence' => true,
        'cleanup' => [],
        'resident_run_id' => $residentRunId,
        'resident_stage' => $stage,
    ] + ($intent === null ? [] : ['intent' => $intent]);
}

function hub_pack_job_resident_cancel(array $context, string $reason, ?callable $transport = null): array
{
    if (!empty($context['resident_terminal'])) {
        return ['cleanup' => hub_pack_job_no_work_cleanup()];
    }
    $db = $context['db'] ?? null;
    $residentPlan = $context['resident_plan'] ?? null;
    $residentRunId = (string)($context['resident_run_id'] ?? '');
    $stage = (string)($context['resident_stage'] ?? '');
    if (!$db instanceof PDO || !is_array($residentPlan) || $residentRunId === '' || $stage === '') {
        return ['cleanup' => []];
    }
    hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'cancel_requested');
    $progress = hub_pack_job_resident_progress(
        isset($context['tick']) && is_callable($context['tick']) ? $context['tick'] : null,
        (float)($context['resident_heartbeat_interval_seconds'] ?? 10),
        true,
    );
    $intent = null;
    try {
        $cancel = hub_pack_job_resident_request($residentPlan, 'POST', '/internal/jobs/' . rawurlencode($residentRunId) . '/cancel', null, $transport, 15, $progress);
        if ((int)($cancel['status'] ?? 0) !== 200) {
            throw new RuntimeException('resident_cancel_unconfirmed');
        }
    } catch (Throwable $error) {
        $intent = hub_pack_job_resident_transport_intent($error);
    }
    if ($intent === null) {
        try {
            $confirmed = hub_pack_job_resident_confirm_terminal($context, $residentRunId, $transport, $progress);
        } catch (Throwable $error) {
            $confirmed = [];
            $intent = hub_pack_job_resident_transport_intent($error);
        }
        if (isset($confirmed['state'])) {
            hub_pack_job_resident_copy_output($stage, (string)$context['workspace'], (array)$context['contract']['artifact_contract'], (array)$residentPlan['service']);
            hub_pack_job_resident_remove_stage((array)$residentPlan['service'], $residentRunId);
            hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'reconciled');
            return ['cleanup' => hub_pack_job_no_work_cleanup()];
        }
    }
    hub_pack_job_resident_record($db, (array)$context['run'], (array)$context['task'], (array)$residentPlan['service'], $residentRunId, 'unconfirmed');

    return ['cleanup' => []];
}

function hub_pack_job_failure_code(Throwable $error, string $fallback = 'job_unavailable'): string
{
    $message = $error->getMessage();
    return in_array($message, ['pack_version_unavailable', 'job_unavailable', 'job_contract_unavailable', 'url_not_allowed'], true) ? $message : $fallback;
}

function hub_pack_job_adapter_failure(PDO $db, int $taskId, array $run, string $code, string $message, array $cleanup, ?array $gpuLease, ?array &$heartbeatState = null): array
{
    hub_commit_pack_job_failure($db, $taskId, $run, 'failed', $code, $message, $cleanup, $gpuLease, $heartbeatState);
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
    $candidateBatch = null;
    if (array_key_exists('voice_preset_batch', $input)) {
        $candidateBatch = hub_voice_preset_batch_snapshot($input['voice_preset_batch']);
        if ($candidateBatch === null) {
            throw new RuntimeException('voice_preset_unavailable');
        }
    }
    if (array_key_exists('generic_voice_batch', $input)) {
        $genericBatch = hub_voice_generic_batch_snapshot($input['generic_voice_batch']);
        if ($genericBatch === null) {
            throw new RuntimeException('generic_voice_unavailable');
        }
        if ($candidateBatch !== null) {
            throw new RuntimeException('voice_candidate_batch_invalid');
        }
        $candidateBatch = $genericBatch;
    }
    if ($candidateBatch !== null) {
        $request['preset_candidates'] = $candidateBatch['candidates'];
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
    if ($input === false || !hub_storage_paths_equal($input, $workspace . '/input')) {
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
    hub_sqlite_begin_immediate($db);
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
    if (($config['materializer'] ?? null) === 'breezyvoice_ultimate_v1') {
        return hub_pack_job_breezyvoice_runner_config_for_task($contract, $input);
    }
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

function hub_pack_job_breezyvoice_runner_config_for_task(array $contract, array $input): array
{
    $definition = $contract['voice_context'] ?? null;
    $runnerConfig = $contract['runner_config'] ?? null;
    $model = is_array($runnerConfig) ? ($runnerConfig['aliases']['best_effort'] ?? null) : null;
    if (($runnerConfig['materializer'] ?? null) !== 'breezyvoice_ultimate_v1'
        || !is_array($definition) || !hub_pack_async_job_breezyvoice_runner_config_model($model)) {
        throw new RuntimeException('job_contract_unavailable');
    }
    try {
        $snapshot = hub_pack_job_voice_context_snapshot($definition, $input, $input['voice_context'] ?? null);
    } catch (Throwable) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $seed = $input['seed'] ?? null;
    if ($seed !== null && (!is_int($seed) || $seed < 0 || $seed > 2147483647)) {
        throw new RuntimeException('job_contract_unavailable');
    }

    return [
        'schema_version' => 'breezyvoice_runner_config_v1',
        'model' => $model['model'],
        'model_revision' => $model['model_revision'],
        'upstream_revision' => $model['upstream_revision'],
        'model_dir' => '/models/breezyvoice',
        'voice_profile_id' => $snapshot['voice_profile_id'],
        'reference_audio_sha256' => $snapshot['reference_audio_sha256'],
        'transcript_sha256' => $snapshot['prompt_text_sha256'],
        'prompt_text_confirmed_at' => $snapshot['prompt_text_confirmed_at'],
        'prompt_transcript_confirmed' => true,
        'seed' => $seed,
        'seed_applied' => $model['seed_applied'],
        'reproducibility' => $model['reproducibility'],
        'device' => $model['device'],
        'sample_rate' => $model['sample_rate'],
        'channels' => $model['channels'],
        'sample_format' => $model['sample_format'],
        'max_input_chars' => $model['max_input_chars'],
    ];
}

function hub_pack_job_breezyvoice_runner_config_valid(array $config): bool
{
    if (array_keys($config) !== [
        'schema_version', 'model', 'model_revision', 'upstream_revision', 'model_dir', 'voice_profile_id',
        'reference_audio_sha256', 'transcript_sha256', 'prompt_text_confirmed_at', 'prompt_transcript_confirmed',
        'seed', 'seed_applied', 'reproducibility', 'device', 'sample_rate', 'channels', 'sample_format', 'max_input_chars',
    ]) {
        return false;
    }

    return $config['schema_version'] === 'breezyvoice_runner_config_v1'
        && $config['model'] === 'MediaTek-Research/BreezyVoice'
        && is_string($config['model_revision']) && preg_match('/^[a-f0-9]{40}$/', $config['model_revision']) === 1
        && is_string($config['upstream_revision']) && preg_match('/^[a-f0-9]{40}$/', $config['upstream_revision']) === 1
        && $config['model_dir'] === '/models/breezyvoice'
        && is_int($config['voice_profile_id']) && $config['voice_profile_id'] > 0
        && is_string($config['reference_audio_sha256']) && preg_match('/^[a-f0-9]{64}$/', $config['reference_audio_sha256']) === 1
        && is_string($config['transcript_sha256']) && preg_match('/^[a-f0-9]{64}$/', $config['transcript_sha256']) === 1
        && is_string($config['prompt_text_confirmed_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $config['prompt_text_confirmed_at']) === 1
        && $config['prompt_transcript_confirmed'] === true
        && ($config['seed'] === null || (is_int($config['seed']) && $config['seed'] >= 0 && $config['seed'] <= 2147483647))
        && $config['seed_applied'] === false && $config['reproducibility'] === 'best_effort'
        && $config['device'] === 'cuda' && $config['sample_rate'] === 22050
        && $config['channels'] === 1 && $config['sample_format'] === 'pcm_s16le'
        && $config['max_input_chars'] === 2000;
}

function hub_pack_job_breezyvoice_artifact_contract_valid(string $workspace, array $config): bool
{
    if (!hub_pack_job_breezyvoice_runner_config_valid($config)) {
        return false;
    }
    $workspace = realpath($workspace);
    $output = $workspace === false ? false : realpath($workspace . '/output');
    if ($workspace === false || $output === false || !hub_storage_paths_equal($output, $workspace . '/output')) {
        return false;
    }
    $audioPath = $output . '/generated_audio.wav';
    $metadataPath = $output . '/synthesis_metadata.json';
    if (is_link($audioPath) || is_link($metadataPath) || !is_file($audioPath) || !is_file($metadataPath)) {
        return false;
    }
    $audioSize = filesize($audioPath);
    $metadataSize = filesize($metadataPath);
    $audioSha256 = hash_file('sha256', $audioPath);
    if (!is_int($audioSize) || $audioSize < 1 || !is_int($metadataSize) || $metadataSize < 1 || $metadataSize > 1048576
        || !is_string($audioSha256) || preg_match('/^[a-f0-9]{64}$/', $audioSha256) !== 1) {
        return false;
    }
    try {
        $metadata = json_decode((string)file_get_contents($metadataPath), true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return false;
    }
    if (!is_array($metadata) || array_is_list($metadata)) {
        return false;
    }
    foreach ([
        'model', 'model_revision', 'upstream_revision', 'reference_audio_sha256', 'transcript_sha256',
        'seed', 'seed_applied', 'reproducibility', 'device',
    ] as $field) {
        if (!array_key_exists($field, $metadata) || $metadata[$field] !== $config[$field]) {
            return false;
        }
    }
    if (($metadata['audio_sha256'] ?? null) !== $audioSha256 || ($metadata['audio_size_bytes'] ?? null) !== $audioSize
        || ($metadata['final_format'] ?? null) != [
            'mime_type' => 'audio/wav',
            'sample_rate' => $config['sample_rate'],
            'channels' => $config['channels'],
            'sample_format' => $config['sample_format'],
        ]) {
        return false;
    }

    return true;
}

function hub_pack_job_runner_required_vram(array $runner, ?array $config): int
{
    $model = is_array($config) && is_array($config['model'] ?? null) ? $config['model'] : [];
    $value = $model['required_vram_mb'] ?? ($runner['required_vram_mb'] ?? null);
    if (!is_int($value) || $value < 0 || $value > 1048576) {
        throw new RuntimeException('job_contract_unavailable');
    }

    return $value;
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
    if (
        ($task['requested_mode'] ?? '') === 'voice_generate_gpt_sovits'
        && ($profile['reference_contract'] ?? 'generic') !== 'gpt_sovits_v1'
    ) {
        throw new RuntimeException('voice_profile_reprepare_required');
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

function hub_pack_job_resolve_facebook_profile_mount(PDO $db, array $task): ?array
{
    $profileId = hub_facebook_task_profile_id($task);
    if ($profileId === null) {
        return null;
    }
    $taskId = (int)($task['id'] ?? 0);
    $ownerMemberId = (int)($task['owner_member_id'] ?? 0);
    if ($taskId < 1 || $ownerMemberId < 1) {
        throw new RuntimeException('facebook_profile_unavailable');
    }
    $stmt = $db->prepare(
        "SELECT * FROM facebook_crawler_profiles
         WHERE profile_id = :profile_id AND owner_member_id = :owner_member_id
           AND active_task_id = :task_id AND state = 'ready' AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute([':profile_id' => $profileId, ':owner_member_id' => $ownerMemberId, ':task_id' => $taskId]);
    $profile = $stmt->fetch();
    if (!is_array($profile) || !hub_facebook_login_state_secure($profile)) {
        throw new RuntimeException('facebook_profile_unavailable');
    }
    $source = hub_facebook_profile_state_path($profile);
    clearstatcache(true, $source);
    $stat = @lstat($source);
    $real = realpath($source);
    $root = realpath(hub_facebook_profile_root());
    if (!is_array($stat) || $real === false || $root === false || is_link($source)
        || (((int)$stat['mode'] & 0170000) !== 0100000)
        || (PHP_OS_FAMILY !== 'Windows' && (((int)$stat['mode'] & 0777) !== 0600))
        || (int)($stat['nlink'] ?? 0) !== 1
        || dirname(dirname($real)) !== $root
        || basename($real) !== 'storage_state.json') {
        throw new RuntimeException('facebook_profile_unavailable');
    }

    return ['source' => $source, 'container_path' => '/data/facebook_profile/storage_state.json'];
}

function hub_pack_job_runner_arguments(
    array $runner,
    array $task,
    array $run,
    string $workspace,
    ?array $config = null,
    array $assetMounts = [],
    ?array $voiceProfileMount = null,
    ?array $facebookProfileMount = null
): array
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
        'required_vram_mb' => hub_pack_job_runner_required_vram($runner, $config),
        'timeout_seconds' => $runner['timeout_seconds'],
        'network_profile' => $runner['network_profile'] ?? 'isolated',
    ] + ($config === null ? [] : ['config' => $config])
        + ($assetMounts === [] ? [] : ['asset_mounts' => $assetMounts])
        + (!isset($runner['workspace_user']) ? [] : ['workspace_user' => $runner['workspace_user']])
        + ($voiceProfileMount === null ? [] : ['voice_profile_mount' => $voiceProfileMount])
        + ($facebookProfileMount === null ? [] : ['facebook_profile_mount' => $facebookProfileMount]);
}

function hub_pack_job_workspace_owner_identity(string $output): string
{
    $stat = lstat($output);
    if (!is_array($stat) || ((int)$stat['mode'] & 0170000) !== 0040000
        || (int)($stat['uid'] ?? 0) < 1 || (int)($stat['gid'] ?? 0) < 1) {
        throw new RuntimeException('workspace_unavailable');
    }

    return (int)$stat['uid'] . ':' . (int)$stat['gid'];
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
    if (($runner['workspace_user'] ?? null) === 'owner') {
        $command[] = '--user';
        $command[] = hub_pack_job_workspace_owner_identity($output);
    }
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
    $facebookProfileMount = $runner['facebook_profile_mount'] ?? null;
    if ($facebookProfileMount !== null) {
        $source = is_array($facebookProfileMount) ? ($facebookProfileMount['source'] ?? null) : null;
        $containerPath = is_array($facebookProfileMount) ? ($facebookProfileMount['container_path'] ?? null) : null;
        clearstatcache(true, is_string($source) ? $source : '');
        $stat = is_string($source) ? @lstat($source) : false;
        $real = is_string($source) ? realpath($source) : false;
        $root = realpath(hub_facebook_profile_root());
        if (!is_string($source) || !is_string($containerPath) || !is_array($stat)
            || $real === false || $root === false || is_link($source)
            || (((int)$stat['mode'] & 0170000) !== 0100000)
            || (PHP_OS_FAMILY !== 'Windows' && (((int)$stat['mode'] & 0777) !== 0600))
            || (int)($stat['nlink'] ?? 0) !== 1
            || dirname(dirname($real)) !== $root
            || basename($real) !== 'storage_state.json'
            || $containerPath !== '/data/facebook_profile/storage_state.json') {
            throw new RuntimeException('facebook_profile_unavailable');
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
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, HUB_ROOT, null, hub_process_execution_options());
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

function hub_pack_job_breezyvoice_diagnostic_text(mixed $value, array $redactions): string
{
    if (!is_string($value) || $value === '') {
        return '';
    }
    foreach (array_unique($redactions) as $privateText) {
        if (is_string($privateText) && $privateText !== '') {
            $value = str_replace($privateText, '[redacted]', $value);
        }
    }
    $maxBytes = 8192;
    if (strlen($value) > $maxBytes) {
        $value = "[... truncated ...]\n" . substr($value, -$maxBytes);
    }

    return trim($value);
}

function hub_pack_job_breezyvoice_failure_summary(mixed $stderr, array $redactions): string
{
    $diagnostic = hub_pack_job_breezyvoice_diagnostic_text($stderr, $redactions);
    if (str_contains($diagnostic, "AttributeError: 'Loader' object has no attribute 'max_depth'")) {
        return 'BreezyVoice YAML loader compatibility error (AttributeError).';
    }
    if (preg_match('/(?:\A|\R)([A-Za-z_][A-Za-z0-9_]{0,80}(?:Error|Exception))(?::|\R)/', $diagnostic, $matches) === 1) {
        return 'BreezyVoice runner exception: ' . $matches[1] . '.';
    }

    return 'BreezyVoice inference failed.';
}

function hub_pack_job_breezyvoice_persist_failure_diagnostics(PDO $db, array $task, array $run, string $workspace, array $result, array $redactions): void
{
    if ((string)($task['pack_id'] ?? '') !== 'tts-breezyvoice' || (int)($result['exit_code'] ?? 0) === 0) {
        return;
    }
    try {
        $workspace = realpath($workspace);
        $logs = $workspace === false ? false : realpath($workspace . '/logs');
        if ($workspace === false || $logs === false || is_link($logs) || !str_starts_with($logs, $workspace . DIRECTORY_SEPARATOR)) {
            return;
        }
        $paths = [];
        $size = 0;
        foreach (['stdout' => 'runner_stdout', 'stderr' => 'runner_stderr'] as $stream => $field) {
            $diagnostic = hub_pack_job_breezyvoice_diagnostic_text($result[$field] ?? null, $redactions);
            if ($diagnostic === '') {
                continue;
            }
            $path = $logs . '/runner.' . $stream . '.log';
            if (file_exists($path) || is_link($path) || file_put_contents($path, $diagnostic . "\n", LOCK_EX) === false) {
                foreach ($paths as $written) {
                    @unlink($written);
                }
                @unlink($path);
                return;
            }
            $resolvedPath = realpath($path);
            $resolvedParent = $resolvedPath === false ? false : realpath(dirname($resolvedPath));
            if ($resolvedPath === false || !is_file($resolvedPath) || $resolvedParent === false
                || !hub_storage_paths_equal($resolvedParent, $logs) || basename($resolvedPath) !== basename($path)) {
                foreach ($paths as $written) {
                    @unlink($written);
                }
                @unlink($path);
                return;
            }
            // NTFS ACL 是 Windows 的私密邊界；PHP 的 POSIX 0600 回報在 NTFS 不可靠。
            $permissionsSecured = PHP_OS_FAMILY === 'Windows'
                || (@chmod($path, 0600) && (((int)@fileperms($path) & 0777) === 0600));
            if (!$permissionsSecured) {
                foreach ($paths as $written) {
                    @unlink($written);
                }
                @unlink($path);
                return;
            }
            $paths[$stream] = $path;
            $size += filesize($path) ?: 0;
        }
        $stmt = $db->prepare(
            "UPDATE runtime_runs
             SET exit_code = :exit_code, log_size_bytes = :log_size_bytes,
                 stdout_log_path = :stdout_log_path, stderr_log_path = :stderr_log_path
             WHERE id = :id AND task_id = :task_id AND worker_id = :worker_id AND lease_token = :lease_token
               AND state IN ('claimed', 'running') AND lease_expires_at IS NOT NULL AND lease_expires_at > :now"
        );
        $stmt->execute([
            ':exit_code' => (int)$result['exit_code'],
            ':log_size_bytes' => $size,
            ':stdout_log_path' => $paths['stdout'] ?? null,
            ':stderr_log_path' => $paths['stderr'] ?? null,
            ':id' => (int)($run['id'] ?? 0),
            ':task_id' => (int)($task['id'] ?? 0),
            ':worker_id' => (string)($run['worker_id'] ?? ''),
            ':lease_token' => (string)($run['lease_token'] ?? ''),
            ':now' => hub_now(),
        ]);
        if ($stmt->rowCount() !== 1) {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    } catch (Throwable) {
        // Diagnostics are best-effort; a log-write failure must not mask the Pack's error code.
    }
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
    $diagnostics = [];
    if ($exitCode !== 0 && (string)($context['task']['pack_id'] ?? '') === 'tts-breezyvoice') {
        foreach (['runner_stdout' => 'stdout', 'runner_stderr' => 'stderr'] as $field => $source) {
            if (is_string($result[$source] ?? null) && $result[$source] !== '') {
                $diagnostics[$field] = $result[$source];
            }
        }
    }

    return [
        'exit_code' => $exitCode,
        'container_id' => $execution['name'],
        'owned_pids' => $cleanup['owned_pids'],
        'cleanup' => $cleanup['cleanup'],
    ] + ($errorCode === null ? [] : ['error_code' => $errorCode])
        + $diagnostics
        + (isset($result['intent']) ? ['intent' => $result['intent']] : []);
}

function hub_breezyvoice_wsl_service_for_task(PDO $db, array $task): ?array
{
    if ((string)($task['pack_id'] ?? '') !== 'tts-breezyvoice' || (string)($task['job'] ?? '') !== 'synthesize') {
        return null;
    }
    $service = hub_get_service_by_mode($db, 'voice_generate_breezy');

    return is_array($service) && (string)($service['pack_id'] ?? '') === 'tts-breezyvoice' ? $service : null;
}

/**
 * Windows 的 Voice Profile 只能先複製到 WSL ext4，再由 CUDA container 讀取。
 * 不可將 NTFS task workspace 直接交給 Docker Desktop，避免路徑與 ACL 語意漂移。
 */
function hub_breezyvoice_wsl_execution_plan(array $service, array $context, ?array $profile = null): array
{
    $task = is_array($context['task'] ?? null) ? $context['task'] : [];
    $runner = is_array($context['runner'] ?? null) ? $context['runner'] : [];
    if (
        (string)($service['pack_id'] ?? '') !== 'tts-breezyvoice'
        || (string)($task['pack_id'] ?? '') !== 'tts-breezyvoice'
        || (string)($task['job'] ?? '') !== 'synthesize'
        || ($runner['image'] ?? null) !== '3waaihub/tts-breezyvoice:0.1.1-cu128'
        || ($runner['entrypoint'] ?? null) !== ['/app/voice_generate.sh']
        || !is_array($runner['args'] ?? null)
        || ($runner['accelerator'] ?? null) !== 'gpu'
        || ($runner['required_vram_mb'] ?? null) !== 4096
        || ($runner['network_profile'] ?? 'isolated') !== 'isolated'
    ) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $runtime = hub_breezyvoice_wsl_runtime_profile($service, $profile);
    $workspace = realpath((string)($context['workspace'] ?? ''));
    $runId = (string)($context['run']['run_id'] ?? '');
    $voiceProfile = is_array($runner['voice_profile_mount'] ?? null) ? $runner['voice_profile_mount'] : [];
    $reference = realpath((string)($voiceProfile['source'] ?? ''));
    if ($runtime === null || $workspace === false || !is_dir($workspace . '/input') || !is_dir($workspace . '/output')
        || !is_file($workspace . '/input/request.json') || is_link($workspace . '/input/request.json')
        || !is_file($workspace . '/input/runner_config.json') || is_link($workspace . '/input/runner_config.json')
        || $reference === false || !is_file($reference) || is_link($reference)
        || preg_match('/^[a-z0-9][a-z0-9_.-]{0,95}$/', $runId) !== 1) {
        throw new RuntimeException('workspace_unavailable');
    }
    // 正式 worker 會傳入展開過的 argv；plan unit fixture 則保留 manifest 原文。
    // 兩種都是固定 contract，實際 Docker argv 永遠在下方重新由 allowlist 組裝。
    $resolvedRunnerArgs = [
        $workspace,
        $workspace . '/input',
        $workspace . '/output',
        $workspace . '/input/runner_config.json',
    ];
    $manifestRunnerArgs = ['{workspace}', '{input_dir}', '{output_dir}', '{input_dir}/runner_config.json'];
    if (!in_array($runner['args'] ?? null, [$resolvedRunnerArgs, $manifestRunnerArgs], true)
        || !in_array($runner['output_dir'] ?? null, [$workspace . '/output', 'output'], true)) {
        throw new RuntimeException('job_contract_unavailable');
    }
    $runtimeRoot = rtrim((string)$runtime['runtime_root'], '/');
    $jobRoot = hub_container_path($runtimeRoot . '/jobs/tts-breezyvoice/' . $runId);
    if (!str_starts_with($jobRoot, $runtimeRoot . '/jobs/tts-breezyvoice/')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $name = 'aihub-pack-' . substr(preg_replace('/[^a-z0-9_.-]/', '-', strtolower($runId)) ?: 'run', 0, 48);
    $docker = [
        'docker', 'run', '--pull=never', '--network', 'none', '--gpus', 'all',
        // Windows workspace 沒有可靠的 POSIX uid/gid；在 WSL ext4 端取目前 worker 身分。
        '--user', '__AIHUB_WSL_WORKSPACE_OWNER__',
        '--mount', 'type=bind,src=' . $jobRoot . '/output,dst=/workspace/output',
        '--mount', 'type=bind,src=' . $jobRoot . '/checkpoints,dst=/workspace/checkpoints',
        '--mount', 'type=bind,src=' . $jobRoot . '/input/request.json,dst=/workspace/input/request.json,readonly',
        '--mount', 'type=bind,src=' . $jobRoot . '/input/runner_config.json,dst=/workspace/input/runner_config.json,readonly',
        '--mount', 'type=bind,src=' . $jobRoot . '/reference.wav,dst=/data/voice_profiles/reference.wav,readonly',
        '--mount', 'type=bind,src=' . (string)$runtime['models_root'] . '/breezyvoice,dst=/models/breezyvoice,readonly',
        '--name', $name, '--entrypoint', '/app/voice_generate.sh', (string)$runtime['image'],
        '/workspace', '/workspace/input', '/workspace/output', '/workspace/input/runner_config.json',
    ];
    $dockerCommand = implode(' ', array_map(
        static fn (string $argument): string => $argument === '__AIHUB_WSL_WORKSPACE_OWNER__'
            ? '"$runtime_uid:$runtime_gid"'
            : hub_wsl_shell_literal($argument),
        $docker,
    ));
    $script = "set -eu\n"
        . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
        . 'windows_reference=' . hub_wsl_shell_literal($reference) . "\n"
        . 'runtime_root=' . hub_wsl_shell_literal($runtimeRoot) . "\n"
        . 'job_root=' . hub_wsl_shell_literal($jobRoot) . "\n"
        . 'container_name=' . hub_wsl_shell_literal($name) . "\n"
        . 'runtime_uid="$(id -u)"' . "\n"
        . 'runtime_gid="$(id -g)"' . "\n"
        . 'case "$runtime_uid:$runtime_gid" in *[!0-9:]*|:*|*:) echo "Invalid WSL runtime identity." >&2; exit 2;; esac' . "\n"
        . 'case "$job_root" in "$runtime_root"/jobs/tts-breezyvoice/*) ;; *) echo "Invalid WSL job root." >&2; exit 2;; esac' . "\n"
        . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
        . 'host_reference="$(wslpath -a "$windows_reference")"' . "\n"
        . 'for source in "$host_workspace/input/request.json" "$host_workspace/input/runner_config.json" "$host_reference"; do if [ ! -f "$source" ] || [ -L "$source" ]; then echo "BreezyVoice source is unavailable." >&2; exit 2; fi; done' . "\n"
        . 'install -d -m 0700 "$job_root/input" "$job_root/output" "$job_root/checkpoints"' . "\n"
        . 'install -d -m 0755 "' . (string)$runtime['models_root'] . '/breezyvoice"' . "\n"
        . 'cp -- "$host_workspace/input/request.json" "$job_root/input/request.json"' . "\n"
        . 'cp -- "$host_workspace/input/runner_config.json" "$job_root/input/runner_config.json"' . "\n"
        . 'cp -- "$host_reference" "$job_root/reference.wav"' . "\n"
        . 'cleanup() { docker container rm -f "$container_name" >/dev/null 2>&1 || true; rm -rf -- "$job_root"; }' . "\n"
        . 'trap cleanup EXIT HUP INT TERM' . "\n"
        . 'copy_required() { source=$1; destination=$2; if [ ! -f "$source" ] || [ -L "$source" ]; then echo "BreezyVoice artifact is unavailable: $source" >&2; exit 2; fi; cp -- "$source" "$destination"; }' . "\n"
        . $dockerCommand . "\n"
        . 'copy_required "$job_root/output/generated_audio.wav" "$host_workspace/output/generated_audio.wav"' . "\n"
        . 'copy_required "$job_root/output/synthesis_metadata.json" "$host_workspace/output/synthesis_metadata.json"' . "\n";

    return ['command' => hub_wsl_script_command($runtime, $script), 'container_id' => $name, 'runtime' => $runtime, 'job_root' => $jobRoot];
}

function hub_breezyvoice_wsl_executor(
    array $service,
    array $context,
    ?callable $processRunner = null,
    ?array $profile = null,
    ?callable $cleanupRunner = null
): array
{
    $unsupported = hub_service_runtime_unsupported_result($service, 'windows', $profile);
    if ($unsupported !== null) {
        return $unsupported + ['cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    try {
        $plan = hub_breezyvoice_wsl_execution_plan($service, $context, $profile);
    } catch (Throwable $e) {
        // 規劃階段只有兩個已定義的可公開機器碼；不把例外內容帶入 task/API。
        $errorCode = in_array($e->getMessage(), ['job_contract_unavailable', 'workspace_unavailable'], true)
            ? $e->getMessage()
            : 'wsl_execution_plan_unavailable';

        return [
            'exit_code' => 1,
            'error_code' => $errorCode,
            'cleanup' => hub_pack_job_no_work_cleanup(),
            'completed_no_process_evidence' => true,
        ];
    }
    $context['started'](['container_id' => $plan['container_id']]);
    try {
        $process = $processRunner ?? 'hub_pack_job_process_runner';
        $result = $process($plan['command'], (int)$context['runner']['timeout_seconds'], $context['tick'] ?? null);
    } catch (Throwable) {
        $result = ['exit_code' => 1];
    }
    $exitCode = is_array($result) ? (int)($result['exit_code'] ?? 1) : 1;
    $cleanupRunner ??= static function (array $docker, int $timeoutSeconds) use ($plan): array {
        return hub_run_command(hub_wsl_script_command($plan['runtime'], 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $docker))), $timeoutSeconds);
    };
    $cleanup = hub_pack_job_default_container_cleanup($cleanupRunner, (string)$plan['container_id'], (int)$context['runner']['timeout_seconds']);
    $diagnostics = [];
    if ($exitCode !== 0 && is_array($result)) {
        // 僅交給 Breezy 私有診斷層做遮蔽、限長與檔案 ACL；不得回傳到 task/API payload。
        foreach (['stdout' => 'runner_stdout', 'stderr' => 'runner_stderr'] as $source => $field) {
            if (is_string($result[$source] ?? null) && $result[$source] !== '') {
                $diagnostics[$field] = $result[$source];
            }
        }
    }

    return ['exit_code' => $exitCode, 'container_id' => (string)$plan['container_id'], 'owned_pids' => $cleanup['owned_pids'], 'cleanup' => $cleanup['cleanup']]
        + ($exitCode === 0 ? [] : ['error_code' => is_array($result) ? ((string)($result['error_code'] ?? '') ?: hub_pack_job_runner_error_code($result)) : 'runner_failed'])
        + $diagnostics;
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

function hub_facebook_crawler_wsl_service_for_task(PDO $db, array $task): ?array
{
    if ((string)($task['pack_id'] ?? '') !== 'facebook-crawler' || (string)($task['job'] ?? '') !== 'crawl') {
        return null;
    }
    $service = hub_get_service_by_mode($db, 'facebook_crawl');

    return is_array($service) && (string)($service['pack_id'] ?? '') === 'facebook-crawler' ? $service : null;
}

function hub_facebook_crawler_wsl_execution_plan(array $service, array $context, ?array $profile = null): array
{
    $task = is_array($context['task'] ?? null) ? $context['task'] : [];
    $runner = is_array($context['runner'] ?? null) ? $context['runner'] : [];
    if ((string)($service['pack_id'] ?? '') !== 'facebook-crawler'
        || (string)($task['pack_id'] ?? '') !== 'facebook-crawler'
        || (string)($task['job'] ?? '') !== 'crawl'
        || ($runner['image'] ?? null) !== '3waaihub/facebook-crawler:0.1.0'
        || ($runner['entrypoint'] ?? null) !== ['/app/crawl-entrypoint.sh', 'python3', '/app/crawl_runner.py']
        || ($runner['args'] ?? null) !== []
        || ($runner['accelerator'] ?? null) !== 'cpu'
        || ($runner['required_vram_mb'] ?? null) !== 0
        || ($runner['network_profile'] ?? null) !== 'public_egress') {
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
    $jobRoot = hub_container_path($runtimeRoot . '/jobs/facebook-crawler/' . $runId);
    if (!str_starts_with($jobRoot, $runtimeRoot . '/jobs/facebook-crawler/')) {
        throw new RuntimeException('workspace_unavailable');
    }
    $facebookMount = $runner['facebook_profile_mount'] ?? null;
    $windowsProfile = null;
    if ($facebookMount !== null) {
        $windowsProfile = is_array($facebookMount) ? ($facebookMount['source'] ?? null) : null;
        if (!is_string($windowsProfile)
            || ($facebookMount['container_path'] ?? null) !== '/data/facebook_profile/storage_state.json') {
            throw new RuntimeException('facebook_profile_unavailable');
        }
    }
    $docker = [
        'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
        '--mount', 'type=bind,src=' . $jobRoot . '/output,dst=/workspace/output',
        '--mount', 'type=bind,src=' . $jobRoot . '/checkpoints,dst=/workspace/checkpoints',
        '--mount', 'type=bind,src=' . $jobRoot . '/input/request.json,dst=/workspace/input/request.json',
    ];
    $dockerCommand = implode(' ', array_map('hub_wsl_shell_literal', $docker));
    if ($windowsProfile !== null) {
        $dockerCommand .= ' --mount "$profile_mount"';
    }
    $dockerCommand .= ' ' . implode(' ', array_map('hub_wsl_shell_literal', [
        '--name', (string)$execution['name'],
        '--entrypoint', '/app/crawl-entrypoint.sh',
        '3waaihub/facebook-crawler:0.1.0', 'python3', '/app/crawl_runner.py',
    ]));
    $script = "set -eu\n"
        . 'windows_workspace=' . hub_wsl_shell_literal($workspace) . "\n"
        . 'runtime_root=' . hub_wsl_shell_literal($runtimeRoot) . "\n"
        . 'job_root=' . hub_wsl_shell_literal($jobRoot) . "\n"
        . 'container_name=' . hub_wsl_shell_literal((string)$execution['name']) . "\n"
        . 'case "$job_root" in "$runtime_root"/jobs/facebook-crawler/*) ;; *) echo "Invalid WSL job root." >&2; exit 2;; esac' . "\n"
        . 'host_workspace="$(wslpath -a "$windows_workspace")"' . "\n"
        . 'if [ ! -f "$host_workspace/input/request.json" ] || [ -L "$host_workspace/input/request.json" ]; then echo "Facebook crawler request is unavailable." >&2; exit 2; fi' . "\n"
        . 'install -d -m 0700 "$job_root/input" "$job_root/output" "$job_root/checkpoints"' . "\n"
        . 'cp -- "$host_workspace/input/request.json" "$job_root/input/request.json"' . "\n";
    if ($windowsProfile !== null) {
        $script .= 'windows_profile=' . hub_wsl_shell_literal($windowsProfile) . "\n"
            . 'profile_state="$(wslpath -a "$windows_profile")"' . "\n"
            . 'if [ ! -f "$profile_state" ] || [ -L "$profile_state" ]; then echo "Facebook profile state is unavailable." >&2; exit 2; fi' . "\n"
            . 'profile_mount="type=bind,src=${profile_state},dst=/data/facebook_profile/storage_state.json,readonly"' . "\n";
    }
    $script .= 'cleanup() { docker container rm -f "$container_name" >/dev/null 2>&1 || true; rm -rf -- "$job_root"; }' . "\n"
        . 'trap cleanup EXIT HUP INT TERM' . "\n"
        . 'copy_required() { source=$1; destination=$2; if [ ! -f "$source" ] || [ -L "$source" ]; then echo "Facebook crawler artifact is unavailable: $source" >&2; exit 2; fi; cp -- "$source" "$destination"; }' . "\n"
        . $dockerCommand . "\n"
        . 'copy_required "$job_root/output/facebook_posts.jsonl" "$host_workspace/output/facebook_posts.jsonl"' . "\n"
        . 'copy_required "$job_root/output/facebook_crawl_report.json" "$host_workspace/output/facebook_crawl_report.json"' . "\n";

    return [
        'command' => hub_wsl_script_command($runtime, $script),
        'container_id' => (string)$execution['name'],
        'runtime' => $runtime,
        'job_root' => $jobRoot,
    ];
}

function hub_facebook_crawler_wsl_executor(array $service, array $context, ?callable $processRunner = null, ?array $profile = null): array
{
    $unsupported = hub_service_runtime_unsupported_result($service, 'windows', $profile);
    if ($unsupported !== null) {
        return $unsupported + ['cleanup' => hub_pack_job_no_work_cleanup(), 'completed_no_process_evidence' => true];
    }
    try {
        $plan = hub_facebook_crawler_wsl_execution_plan($service, $context, $profile);
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
        return hub_run_command(
            hub_wsl_script_command($plan['runtime'], 'exec ' . implode(' ', array_map('hub_wsl_shell_literal', $docker))),
            $timeoutSeconds
        );
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
    if (!hub_wsl_job_cleanup_workspace($plan['runtime'], 'facebook-crawler', (string)$plan['job_root'], (int)$context['runner']['timeout_seconds'])
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

const HUB_PACK_JOB_HEARTBEAT_RENEW_THRESHOLD_SECONDS = 30;

function hub_pack_job_heartbeat_state(array $run, ?array $gpuLease): array
{
    return [
        'runtime_expires_at' => $run['lease_expires_at'] ?? null,
        'gpu_required' => $gpuLease !== null,
        'gpu_expires_at' => $gpuLease['lease_expires_at'] ?? null,
        'skipped_ticks' => 0,
    ];
}

function hub_pack_job_heartbeat_expiry_timestamp(string $expiry): ?int
{
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry) !== 1) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiry);
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed === false
        || (is_array($errors) && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
        || $parsed->format('Y-m-d H:i:s') !== $expiry) {
        return null;
    }

    return $parsed->getTimestamp();
}

function hub_pack_job_heartbeat_should_renew(array $state, int $now): bool
{
    $expiries = [$state['runtime_expires_at'] ?? null];
    if (($state['gpu_required'] ?? false) === true) {
        $expiries[] = $state['gpu_expires_at'] ?? null;
    }
    foreach ($expiries as $expiry) {
        if (!is_string($expiry) || trim($expiry) === '') {
            return true;
        }
        $parsed = hub_pack_job_heartbeat_expiry_timestamp($expiry);
        if ($parsed === null) {
            return true;
        }
        if ($parsed - $now <= HUB_PACK_JOB_HEARTBEAT_RENEW_THRESHOLD_SECONDS) {
            return true;
        }
    }

    return false;
}

function hub_pack_job_heartbeat_mark_skipped(array &$state): void
{
    $skipped = $state['skipped_ticks'] ?? 0;
    $skipped = is_int($skipped) && $skipped >= 0 ? $skipped : 0;
    $state['skipped_ticks'] = $skipped === PHP_INT_MAX ? PHP_INT_MAX : $skipped + 1;
}

function hub_pack_job_heartbeat_mark_committed(array &$state, string $newExpiry): int
{
    if (hub_pack_job_heartbeat_expiry_timestamp($newExpiry) === null) {
        throw new InvalidArgumentException('pack_job_heartbeat_expiry_invalid');
    }

    $skipped = $state['skipped_ticks'] ?? 0;
    $skipped = is_int($skipped) && $skipped >= 0 ? $skipped : 0;
    $state['runtime_expires_at'] = $newExpiry;
    if (($state['gpu_required'] ?? false) === true) {
        $state['gpu_expires_at'] = $newExpiry;
    }
    $state['skipped_ticks'] = 0;

    return $skipped;
}

function hub_pack_job_heartbeat_take_skipped(?array &$state): int
{
    if ($state === null) {
        return 0;
    }

    $skipped = $state['skipped_ticks'] ?? 0;
    $skipped = is_int($skipped) && $skipped >= 0 ? $skipped : 0;
    $state['skipped_ticks'] = 0;

    return $skipped;
}

function hub_pack_job_heartbeat_emit(int $actionStartedNs, array $stats, bool $gpu, string $outcome, int $skippedTicks): void
{
    $emitStartedNs = hrtime(true);
    $beginRequestedNs = $stats['begin_requested_ns'] ?? $actionStartedNs;
    $txStartedNs = $stats['tx_started_ns'] ?? null;
    $txEndedNs = $stats['tx_ended_ns'] ?? $emitStartedNs;
    hub_runtime_telemetry_emit([
        'action' => 'heartbeat',
        'variant' => $gpu ? 'gpu' : 'cpu',
        'outcome' => $outcome,
        'tx_mode' => $gpu ? 'immediate' : 'autocommit',
        'tx_begin_at' => $stats['tx_begin_at'] ?? null,
        'tx_commit_at' => $stats['tx_commit_at'] ?? null,
        'pre_tx_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $beginRequestedNs),
        'lock_wait_ms' => (float)($stats['lock_wait_ms'] ?? 0.0),
        'lock_wait_kind' => (string)($stats['lock_wait_kind'] ?? ($gpu ? 'begin_immediate' : 'first_write_upper_bound')),
        'tx_ms' => $txStartedNs === null ? 0.0 : hub_runtime_telemetry_elapsed_ms($txStartedNs, $txEndedNs),
        'post_tx_ms' => hub_runtime_telemetry_elapsed_ms($txEndedNs, $emitStartedNs),
        'total_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $emitStartedNs),
        'retry_count' => (int)($stats['retry_count'] ?? 0),
        'skipped_ticks' => $skippedTicks,
    ]);
}

function hub_pack_job_tick(PDO $db, array $run, ?array $gpuLease, int $leaseSeconds, array &$heartbeatState): ?string
{
    $actionStartedNs = hrtime(true);
    $now = time();
    $skipped = !hub_pack_job_heartbeat_should_renew($heartbeatState, $now);
    if ($skipped) {
        hub_pack_job_heartbeat_mark_skipped($heartbeatState);
    } else {
        $expiresAt = date('Y-m-d H:i:s', $now + max(1, $leaseSeconds));
        $stats = [];
        try {
            $alive = $gpuLease === null
                ? hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], $leaseSeconds, $expiresAt, $stats)
                : hub_runtime_gpu_heartbeat($db, $run, $gpuLease, $leaseSeconds, $expiresAt, $stats);
        } catch (Throwable $e) {
            if (($stats['transaction_closed'] ?? null) !== false) {
                hub_pack_job_heartbeat_emit($actionStartedNs, $stats, $gpuLease !== null, !empty($stats['lock_exhausted']) ? 'lock_exhausted' : 'failed', 0);
            }
            throw $e;
        }
        if (!$alive) {
            hub_pack_job_heartbeat_emit($actionStartedNs, $stats, $gpuLease !== null, 'fence_lost', 0);
            return 'fence_lost';
        }
        $skippedTicks = hub_pack_job_heartbeat_mark_committed($heartbeatState, $expiresAt);
        hub_pack_job_heartbeat_emit($actionStartedNs, $stats, $gpuLease !== null, 'committed', $skippedTicks);
    }
    if (hub_runtime_is_cancel_requested($db, (int)$run['id'])) {
        return 'cancelled';
    }
    $current = hub_runtime_fetch_run($db, (int)$run['id']);
    if ($current === null || !hash_equals((string)$run['lease_token'], (string)$current['lease_token'])) {
        return 'fence_lost';
    }
    if ($skipped) {
        $runtimeExpiry = $current['lease_expires_at'] ?? null;
        if (!in_array((string)($current['state'] ?? ''), ['claimed', 'running'], true)
            || (string)($current['run_id'] ?? '') !== (string)($run['run_id'] ?? '')
            || (string)($current['worker_id'] ?? '') !== (string)($run['worker_id'] ?? '')
            || !is_string($runtimeExpiry)
            || !is_string($heartbeatState['runtime_expires_at'] ?? null)
            || $runtimeExpiry !== $heartbeatState['runtime_expires_at']
            || ($runtimeExpiryAt = hub_pack_job_heartbeat_expiry_timestamp($runtimeExpiry)) === null
            || $runtimeExpiryAt <= time()) {
            return 'fence_lost';
        }
        if ($gpuLease !== null) {
            $gpu = hub_runtime_gpu_fetch($db);
            $gpuExpiry = $gpu['lease_expires_at'] ?? null;
            if (!is_array($gpu)
                || (string)($gpu['resource_key'] ?? '') !== (string)($gpuLease['resource_key'] ?? '')
                || (string)($gpu['state'] ?? '') !== 'leased'
                || (string)($gpu['runtime_run_id'] ?? '') !== (string)($run['run_id'] ?? '')
                || (string)($gpu['runtime_run_id'] ?? '') !== (string)($gpuLease['runtime_run_id'] ?? '')
                || (string)($gpu['worker_id'] ?? '') !== (string)($run['worker_id'] ?? '')
                || (string)($gpu['worker_id'] ?? '') !== (string)($gpuLease['worker_id'] ?? '')
                || !hash_equals((string)($run['lease_token'] ?? ''), (string)($gpu['lease_token'] ?? ''))
                || !hash_equals((string)($gpuLease['lease_token'] ?? ''), (string)($gpu['lease_token'] ?? ''))
                || !is_string($gpuExpiry)
                || !is_string($heartbeatState['gpu_expires_at'] ?? null)
                || $gpuExpiry !== $heartbeatState['gpu_expires_at']
                || ($gpuExpiryAt = hub_pack_job_heartbeat_expiry_timestamp($gpuExpiry)) === null
                || $gpuExpiryAt <= time()) {
                return 'fence_lost';
            }
        }
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
    if (!empty($result['resident_terminal'])) {
        return $result;
    }
    if (isset($context['resident_plan']) && is_array($context['resident_plan'])) {
        $stopped = hub_pack_job_resident_cancel(
            $context,
            $reason,
            isset($context['resident_transport']) && is_callable($context['resident_transport']) ? $context['resident_transport'] : null,
        );
        if (is_array($stopped) && isset($stopped['cleanup']) && is_array($stopped['cleanup'])) {
            $result['cleanup'] = $stopped['cleanup'];
        }
    }
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

function hub_pack_job_recovery_telemetry_emit(int $actionStartedNs, int $beginRequestedNs, ?int $txStartedNs, int $txEndedNs, ?string $txBeginAt, ?string $txCommitAt, array $beginStats, bool $gpu, string $outcome, int $skippedTicks): void
{
    $emitStartedNs = hrtime(true);
    hub_runtime_telemetry_emit([
        'action' => 'recovery',
        'variant' => $gpu ? 'gpu' : 'runtime',
        'outcome' => $outcome,
        'tx_mode' => 'immediate',
        'tx_begin_at' => $txBeginAt,
        'tx_commit_at' => $txCommitAt,
        'pre_tx_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $beginRequestedNs),
        'lock_wait_ms' => (float)($beginStats['lock_wait_ms'] ?? 0.0),
        'lock_wait_kind' => 'begin_immediate',
        'tx_ms' => $txStartedNs === null ? 0.0 : hub_runtime_telemetry_elapsed_ms($txStartedNs, $txEndedNs),
        'post_tx_ms' => hub_runtime_telemetry_elapsed_ms($txEndedNs, $emitStartedNs),
        'total_ms' => hub_runtime_telemetry_elapsed_ms($actionStartedNs, $emitStartedNs),
        'retry_count' => (int)($beginStats['retry_count'] ?? 0),
        'skipped_ticks' => $skippedTicks,
    ]);
}

function hub_pack_job_reconcile_lost_fence(PDO $db, array $task, array $run, array $cleanup, ?array $gpuLease = null, ?array &$heartbeatState = null): bool
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
    $actionStartedNs = hrtime(true);
    $beginRequestedNs = hrtime(true);
    $beginStats = [];
    $txStartedNs = null;
    $txBeginAt = null;
    $ownsTransaction = false;
    $transactionClosed = false;
    $result = false;
    $outcome = 'failed';
    $error = null;
    try {
        hub_sqlite_begin_immediate($db, $beginStats);
        $ownsTransaction = true;
        $txStartedNs = hrtime(true);
        $txBeginAt = hub_runtime_telemetry_timestamp();
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
                $ownsTransaction = false;
                $transactionClosed = true;
                $outcome = 'fence_lost';
                $result = false;
            }
            if (!$gpuMatched) {
                throw new RuntimeException('runtime_ownership_conflict');
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
            $ownsTransaction = false;
            $transactionClosed = true;
            $outcome = 'fence_lost';
            $result = false;
            throw new RuntimeException('runtime_ownership_conflict');
        }
        $taskStmt = $db->prepare(
            "UPDATE tasks
             SET status = 'failed', progress = 100, result_json = NULL, error_code = :error_code,
                 error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at,
                 lock_token = NULL, waiting_reason = NULL, next_attempt_at = NULL, waiting_detail_json = NULL
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
            $ownsTransaction = false;
            $transactionClosed = true;
            $outcome = 'fence_lost';
            $result = false;
            throw new RuntimeException('task_ownership_conflict');
        }
        hub_apply_task_terminal_retention($db, $taskId, 'failed', $now);
        hub_release_task_artifact_holds($db, $taskId);
        hub_enqueue_task_callback_delivery($db, $taskId);
        $db->exec('COMMIT');
        $ownsTransaction = false;
        $transactionClosed = true;
        $outcome = 'committed';
        $result = true;
    } catch (Throwable $e) {
        $error = $e;
        if ($ownsTransaction) {
            try {
                $db->exec('ROLLBACK');
                $ownsTransaction = false;
                $transactionClosed = true;
                $outcome = 'rolled_back';
            } catch (Throwable) {
            }
        } elseif ($txStartedNs === null && !empty($beginStats['lock_exhausted'])) {
            $transactionClosed = true;
            $outcome = 'lock_exhausted';
        }
    }
    $txEndedNs = hrtime(true);
    if ($transactionClosed) {
        hub_pack_job_recovery_telemetry_emit(
            $actionStartedNs,
            $beginRequestedNs,
            $txStartedNs,
            $txEndedNs,
            $txBeginAt,
            hub_runtime_telemetry_timestamp(),
            $beginStats,
            $gpuLease !== null,
            $outcome,
            hub_pack_job_heartbeat_take_skipped($heartbeatState),
        );
    }
    if ($error !== null && !($transactionClosed && $outcome === 'fence_lost')) {
        throw $error;
    }

    return $result;
}

function hub_pack_job_lost_fence_outcome(PDO $db, array $task, array $run, array $options, bool $started, ?array $context, array $details, ?callable $pidInspector, ?array $gpuLease = null, ?array $cleanup = null, ?array &$heartbeatState = null): array
{
    if ($cleanup === null) {
        $cleanup = $started && $context !== null
            ? hub_pack_job_cleanup_after_started_failure($options, $context, $details, $pidInspector, 'runtime_lease_lost')
            : hub_pack_job_no_work_cleanup();
    }
    if (!hub_pack_job_reconcile_lost_fence($db, $task, $run, $cleanup, $gpuLease, $heartbeatState)) {
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

function hub_reconcile_resident_job_runs(PDO $db, ?callable $transport = null): int
{
    // 固定查詢與後續 WSL 執行鏈相連；保留 PDO prepared contract，避免將 DB 回傳列直接當成 command source。
    $statement = $db->prepare(
        "SELECT resident_job_runs.*, runtime_runs.worker_id, runtime_runs.lease_token, runtime_runs.run_id,
                runtime_runs.task_id AS runtime_task_id, services.pack_id AS service_pack_id
         FROM resident_job_runs
         JOIN runtime_runs ON runtime_runs.run_id = resident_job_runs.runtime_run_id
         JOIN services ON services.id = resident_job_runs.service_id
         WHERE resident_job_runs.lifecycle IN ('cancel_requested', 'unconfirmed')
         ORDER BY resident_job_runs.updated_at ASC"
    );
    $statement->execute();
    $rows = $statement->fetchAll();
    $reconciled = 0;
    foreach ($rows as $row) {
        $task = hub_get_task($db, (int)($row['task_id'] ?? 0));
        $service = hub_get_service($db, (int)($row['service_id'] ?? 0));
        if (!is_array($task) || !is_array($service) || (int)($row['runtime_task_id'] ?? 0) !== (int)$task['id']) {
            continue;
        }
        try {
            $contract = hub_pack_job_contract_from_snapshot($task);
        } catch (Throwable) {
            continue;
        }
        if (($service['pack_id'] ?? null) !== ($task['pack_id'] ?? null)) {
            continue;
        }
        $residentPlan = hub_pack_job_resident_plan_for_service($db, $service, $contract, false);
        if ($residentPlan === null) {
            continue;
        }
        $state = hub_pack_job_resident_status($residentPlan, (string)$row['resident_run_id'], $transport);
        if (!in_array($state, ['succeeded', 'failed', 'cancelled'], true)) {
            continue;
        }
        try {
            hub_pack_job_resident_remove_stage($service, (string)$row['resident_run_id']);
        } catch (Throwable) {
            continue;
        }
        if (!hub_runtime_gpu_release_resident_block($db, $row)) {
            continue;
        }
        $stmt = $db->prepare(
            "UPDATE resident_job_runs SET lifecycle = 'reconciled', reconciled_at = :now, updated_at = :now
             WHERE runtime_run_id = :runtime_run_id AND resident_run_id = :resident_run_id
               AND lifecycle IN ('cancel_requested', 'unconfirmed')"
        );
        $stmt->execute([
            ':now' => hub_now(),
            ':runtime_run_id' => (string)$row['runtime_run_id'],
            ':resident_run_id' => (string)$row['resident_run_id'],
        ]);
        if ($stmt->rowCount() === 1) {
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
    $heartbeatState = hub_pack_job_heartbeat_state($run, null);
    $gpuLease = null;
    $started = false;
    $context = null;
    $pidInspector = null;
    $details = [];
    $cleanup = null;
    $terminalErrorCode = null;
    $privatePromptWorkspace = null;
    $breezyDiagnosticRedactions = [];
    $facebookProfileId = null;
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
                && in_array($storedVersion, ['0.1.4', '0.1.5', '0.1.6', '0.1.7', '0.1.8'], true)
                && (string)($task['job'] ?? '') === 'synthesize'
                && (string)($task['requested_mode'] ?? '') === 'voice_generate'
                && (string)($task['accelerator'] ?? '') === 'gpu'
                && in_array($storedMode, $compatibleModes, true)
            ) {
                $resolutionTask['pack_version'] = '0.1.9';
            }
            $contract = hub_resolve_stored_pack_job($db, $resolutionTask);
        } catch (Throwable $e) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, hub_pack_job_failure_code($e), 'Stored Pack job is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        if (!isset($contract['runner'])) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'job_unavailable', 'Stored Pack job has no runner contract', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        try {
            $facebookProfile = hub_facebook_profile_acquire_for_task($db, $task);
        } catch (Throwable) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'facebook_profile_unavailable', 'Managed Facebook profile is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        if ($facebookProfile === false) {
            if (hub_facebook_wait_for_profile($db, $taskId, $run, max(1, (int)($options['profile_backoff_seconds'] ?? 5)))) {
                return ['status' => 'waiting_profile'];
            }
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, null, null, $heartbeatState);
        }
        if (is_array($facebookProfile)) {
            $facebookProfileId = (string)$facebookProfile['profile_id'];
        }
        $runner = $contract['runner'];
        $runnerConfig = hub_pack_job_runner_config_for_task($contract, (array)($task['input'] ?? []));
        $requiredVram = hub_pack_job_runner_required_vram($runner, $runnerConfig);
        $residentPlan = hub_pack_job_resident_service($db, $task, $contract);
        $residentUsesCpu = is_array($residentPlan) && !empty($residentPlan['eligible'])
            && hub_pack_job_resident_uses_cpu($residentPlan);
        $run['effective_gpu_lease_required'] = hub_runtime_task_requires_gpu($task) && !$residentUsesCpu;
        $residentTransport = isset($options['resident_transport']) && is_callable($options['resident_transport'])
            ? $options['resident_transport']
            : null;
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'whisper-asr' && (string)($task['job'] ?? '') === 'transcribe' && ($residentPlan === null || empty($residentPlan['eligible']))) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'resident_service_unavailable', 'Whisper ASR WSL Runtime service is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        if (($whisperCapabilityError = hub_whisper_wsl_pascal_job_capability_error($task, $runnerConfig, $residentPlan)) !== null) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'runtime_capability_unsupported', $whisperCapabilityError, hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        if ($residentPlan !== null && empty($residentPlan['eligible'])) {
            if (hub_pack_job_wait_without_gpu(
                $db,
                $taskId,
                $run,
                (string)($residentPlan['reason'] ?? 'resident_service_unavailable'),
                max(1, (int)($options['gpu_backoff_seconds'] ?? 30)),
                ['required_vram_mb' => $requiredVram]
            )) {
                return ['status' => 'waiting_gpu'];
            }
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, null, null, $heartbeatState);
        }
        $wslResidentAssets = null;
        if ($residentPlan !== null && !empty($residentPlan['eligible'])) {
            $wslResidentAssets = hub_whisper_wsl_resident_asset_preflight(
                (array)($residentPlan['service'] ?? []),
                $runner,
                (array)($task['input'] ?? [])
            );
        }
        if ($wslResidentAssets !== null) {
            $assetCheck = hub_run_command(
                hub_wsl_script_command((array)$wslResidentAssets['runtime'], (string)$wslResidentAssets['script']),
                30
            );
            if ((int)($assetCheck['exit_code'] ?? 1) !== 0) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'model_assets_unavailable', 'Required offline model or cache assets are unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
            $assetMounts = [];
        } else {
            try {
                $assetMounts = hub_pack_job_resolve_asset_mounts($db, $runner, (array)($task['input'] ?? []));
            } catch (Throwable) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'model_assets_unavailable', 'Required offline model or cache assets are unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
        }
        $webScreenshotService = null;
        $edgeTtsService = null;
        $facebookCrawlerService = null;
        $breezyVoiceService = null;
        // 僅供受控 worker 測試注入 host-local readiness；request payload 永不參與 runtime profile。
        $runtimeProfile = is_array($options['runtime_profile'] ?? null) ? $options['runtime_profile'] : null;
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'tts-breezyvoice' && (string)($task['job'] ?? '') === 'synthesize') {
            $breezyVoiceService = hub_breezyvoice_wsl_service_for_task($db, $task);
            if ($breezyVoiceService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'BreezyVoice WSL Runtime service is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
        }
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'web-screenshot' && (string)($task['job'] ?? '') === 'capture') {
            $webScreenshotService = hub_web_screenshot_wsl_service_for_task($db, $task);
            if ($webScreenshotService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'Web Screenshot service is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
        }
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'edge-tts' && (string)($task['job'] ?? '') === 'synthesize') {
            $edgeTtsService = hub_edge_tts_wsl_service_for_task($db, $task);
            if ($edgeTtsService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'Edge TTS service is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
        }
        if (hub_platform_id() === 'windows' && (string)($task['pack_id'] ?? '') === 'facebook-crawler' && (string)($task['job'] ?? '') === 'crawl') {
            $facebookCrawlerService = hub_facebook_crawler_wsl_service_for_task($db, $task);
            if ($facebookCrawlerService === null) {
                return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'Facebook crawler service is unavailable', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
            }
        }
        if ($residentPlan !== null) {
            $executor = static fn (array $context): array => hub_pack_job_resident_executor($context, $residentTransport);
        } elseif (isset($options['executor']) && is_callable($options['executor'])) {
            $executor = $options['executor'];
        } elseif ($breezyVoiceService !== null) {
            $executor = static fn (array $context): array => hub_breezyvoice_wsl_executor(
                $breezyVoiceService,
                $context,
                isset($options['process_runner']) && is_callable($options['process_runner']) ? $options['process_runner'] : null,
                $runtimeProfile,
                isset($options['command_runner']) && is_callable($options['command_runner']) ? $options['command_runner'] : null,
            );
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
        } elseif ($facebookCrawlerService !== null) {
            $executor = static fn (array $context): array => hub_facebook_crawler_wsl_executor(
                $facebookCrawlerService,
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
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'runner_unavailable', 'No controlled Pack job executor is configured', hub_pack_job_no_work_cleanup(), null, $heartbeatState);
        }
        if ($residentUsesCpu) {
            $capacity = hub_pack_job_resident_capacity($residentPlan, $residentTransport);
            if ($capacity !== 'cold' && $capacity !== 'ready') {
                if (hub_pack_job_wait_without_gpu(
                    $db,
                    $taskId,
                    $run,
                    $capacity === 'running' ? 'resident_busy' : 'resident_unknown',
                    max(1, (int)($options['gpu_backoff_seconds'] ?? 30)),
                    ['required_vram_mb' => 0]
                )) {
                    return ['status' => 'waiting_gpu'];
                }
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, null, null, $heartbeatState);
            }
        }
        if (hub_runtime_task_requires_gpu($task) && !$residentUsesCpu) {
            $gpuLease = hub_runtime_gpu_acquire_for_task($db, $task, $run, $leaseSeconds);
            if ($gpuLease === null) {
                if (hub_pack_job_wait_without_gpu(
                    $db,
                    $taskId,
                    $run,
                    'gpu_unavailable',
                    max(1, (int)($options['gpu_backoff_seconds'] ?? 30)),
                    ['required_vram_mb' => $requiredVram]
                )) {
                    return ['status' => 'waiting_gpu'];
                }
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, null, null, $heartbeatState);
            }
            $heartbeatState['gpu_required'] = true;
            $heartbeatState['gpu_expires_at'] = $gpuLease['lease_expires_at'] ?? null;
            $probe = isset($options['gpu_probe']) && is_callable($options['gpu_probe'])
                ? $options['gpu_probe']
                : static fn (): array => hub_runtime_gpu_probe(isset($options['gpu_probe_runner']) && is_callable($options['gpu_probe_runner']) ? $options['gpu_probe_runner'] : null);
            $safetyMargin = null;
            $probeSnapshot = null;
            if ($residentPlan !== null) {
                $capacity = hub_pack_job_resident_capacity($residentPlan, $residentTransport);
                if ($capacity !== 'cold' && $capacity !== 'ready') {
                    $probeSnapshot = $probe();
                    $waiting = hub_runtime_gpu_wait_for_capacity(
                        $db,
                        $taskId,
                        $run,
                        $gpuLease,
                        $capacity === 'running' ? 'resident_busy' : 'resident_unknown',
                        max(1, (int)($options['gpu_backoff_seconds'] ?? 30)),
                        hub_runtime_gpu_preflight_result($run, $requiredVram, 0, $probeSnapshot),
                    );
                    if (($waiting['reason'] ?? '') !== 'lost_gpu_lease') {
                        return ['status' => 'waiting_gpu'];
                    }
                    return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, $gpuLease, null, $heartbeatState);
                }
                if ($capacity === 'ready') {
                    $requiredVram = 0;
                    $safetyMargin = max(0, (int)($residentPlan['settings'][$residentPlan['resident']['min_free_vram_setting']] ?? 1024));
                }
            }
            $preflightProbe = $probeSnapshot === null ? $probe : static fn (): array => $probeSnapshot;
            $preflight = hub_runtime_gpu_preflight($db, $taskId, $run, $gpuLease, $requiredVram, $preflightProbe, max(1, (int)($options['gpu_backoff_seconds'] ?? 30)), $safetyMargin);
            if (empty($preflight['ok'])) {
                if (($preflight['reason'] ?? '') !== 'lost_gpu_lease') {
                    return ['status' => 'waiting_gpu'];
                }
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, [], null, $gpuLease, null, $heartbeatState);
            }
        }
        try {
            $voiceProfileMount = hub_pack_job_resolve_voice_profile_mount($db, $task, $contract);
        } catch (Throwable $e) {
            $code = in_array($e->getMessage(), ['voice_profile_changed', 'voice_profile_reprepare_required'], true)
                ? $e->getMessage()
                : 'voice_profile_unavailable';
            return hub_pack_job_adapter_failure($db, $taskId, $run, $code, 'Managed voice profile is unavailable', hub_pack_job_no_work_cleanup(), $gpuLease, $heartbeatState);
        }
        try {
            $facebookProfileMount = hub_pack_job_resolve_facebook_profile_mount($db, $task);
        } catch (Throwable) {
            return hub_pack_job_adapter_failure($db, $taskId, $run, 'facebook_profile_unavailable', 'Managed Facebook profile is unavailable', hub_pack_job_no_work_cleanup(), $gpuLease, $heartbeatState);
        }
        $hasPrivatePrompt = isset($voiceProfileMount['prompt_text']);
        if ((string)($task['pack_id'] ?? '') === 'tts-breezyvoice') {
            $taskInput = is_array($task['input'] ?? null) ? $task['input'] : [];
            foreach ([$taskInput['text'] ?? null, $voiceProfileMount['prompt_text'] ?? null] as $privateText) {
                if (is_string($privateText) && $privateText !== '') {
                    $breezyDiagnosticRedactions[] = $privateText;
                }
            }
        }
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
            'contract' => $contract,
            'runner' => hub_pack_job_runner_arguments($runner, $task, $run, $workspace, $runnerConfig, $assetMounts, $voiceProfileMount, $facebookProfileMount),
        ] + ($residentPlan === null ? [] : [
            'resident_plan' => $residentPlan,
            'resident_transport' => $residentTransport,
            'voice_profile_mount' => $voiceProfileMount,
            'resident_heartbeat_interval_seconds' => hub_pack_job_resident_heartbeat_interval(
                $leaseSeconds,
                $options['resident_heartbeat_interval_seconds'] ?? null,
            ),
            'resident_status_poll_seconds' => (int)($options['resident_status_poll_seconds'] ?? 5),
        ] + (isset($options['resident_clock']) && is_callable($options['resident_clock']) ? ['resident_clock' => $options['resident_clock']] : [])
          + (isset($options['resident_sleeper']) && is_callable($options['resident_sleeper']) ? ['resident_sleeper' => $options['resident_sleeper']] : []));
        $pidInspector = $gpuLease === null ? null : ($options['pid_inspector'] ?? static fn (): array => []);
        $baseline = $pidInspector === null ? [] : hub_runtime_gpu_recovery_pids($pidInspector($context));
        $details = hub_pack_job_execution_details(['baseline_pids' => $baseline]);
        // A baseline is needed for GPU recovery but is not proof that this executor owned no process.
        $details['has_process_evidence'] = false;
        if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            $scrubPrivatePrompt();
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, $details, $pidInspector, $gpuLease, null, $heartbeatState);
        }
        $startedRun = hub_pack_job_begin_execution($db, $task, $run, $runner, $gpuLease);
        if ($startedRun === null) {
            $scrubPrivatePrompt();
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, false, null, $details, $pidInspector, $gpuLease, null, $heartbeatState);
        }
        $run = $startedRun;
        $heartbeatState['runtime_expires_at'] = $run['lease_expires_at'] ?? null;
        $run['effective_gpu_lease_required'] = hub_runtime_task_requires_gpu($task) && !$residentUsesCpu;
        $context['run'] = $run;
        $context['runner'] = hub_pack_job_runner_arguments($runner, $task, $run, $workspace, $runnerConfig, $assetMounts, $voiceProfileMount, $facebookProfileMount);
        $fenceLost = false;
        $context['started'] = static function (array $startedDetails) use (&$details, &$fenceLost, $db, $task, $run, $gpuLease, $baseline): void {
            $details = hub_pack_job_execution_details($startedDetails, ['baseline_pids' => $baseline]);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                $fenceLost = true;
            }
        };
        $context['tick'] = static function () use ($db, $run, $gpuLease, $leaseSeconds, &$heartbeatState): ?string {
            return hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds, $heartbeatState);
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
        foreach (['resident_run_id', 'resident_stage'] as $field) {
            if (isset($result[$field]) && is_string($result[$field])) {
                $context[$field] = $result[$field];
            }
        }
        $details = hub_pack_job_execution_details($result, $details);
        if (!$fenceLost && empty($result['resident_terminal']) && !hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
            $fenceLost = true;
        }
        $intent = in_array($result['intent'] ?? null, ['fence_lost', 'cancelled', 'timed_out'], true)
            ? $result['intent']
            : hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds, $heartbeatState);
        if ($fenceLost || $intent === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease, null, $heartbeatState);
        }
        if ($intent === 'cancelled' || $intent === 'timed_out') {
            $result = hub_pack_job_stop_result($options, $context, $intent, $result);
            $details = hub_pack_job_execution_details($result, $details);
            if (!hub_pack_job_record_execution($db, $task, $run, $gpuLease, $details)) {
                $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
                return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease, $cleanup, $heartbeatState);
            }
            $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
            hub_commit_pack_job_failure($db, $taskId, $run, $intent, $intent, 'Pack job ' . $intent, $cleanup, $gpuLease, $heartbeatState);
            $latest = hub_get_task($db, $taskId);
            return ['status' => (string)($latest['status'] ?? 'failed'), 'error_code' => (string)($latest['error_code'] ?? $intent)];
        }
        $cleanup = hub_pack_job_cleanup_from_result($result, $details, $pidInspector, $context);
        if ((int)($result['exit_code'] ?? 1) !== 0) {
            hub_pack_job_breezyvoice_persist_failure_diagnostics($db, $task, $run, $workspace, $result, $breezyDiagnosticRedactions);
            $code = (string)($result['error_code'] ?? 'runtime_exit_nonzero');
            if (preg_match('/^[a-z0-9_:-]{1,120}$/i', $code) !== 1) {
                $code = 'runtime_exit_nonzero';
            }
            $terminalErrorCode = $code;
            $message = (string)($task['pack_id'] ?? '') === 'tts-breezyvoice'
                ? hub_pack_job_breezyvoice_failure_summary($result['runner_stderr'] ?? null, $breezyDiagnosticRedactions)
                : 'Pack job exited unsuccessfully';
            if ($message !== 'Pack job exited unsuccessfully') {
                try {
                    hub_add_task_log($db, $taskId, 'error', $message);
                } catch (Throwable) {
                    // The terminal task state remains authoritative if best-effort log storage is unavailable.
                }
            }
            return hub_pack_job_adapter_failure($db, $taskId, $run, $code, $message, $cleanup, $gpuLease, $heartbeatState);
        }
        if ((string)($task['pack_id'] ?? '') === 'tts-breezyvoice'
            && (string)($task['job'] ?? '') === 'synthesize'
            && !hub_pack_job_breezyvoice_artifact_contract_valid($workspace, $runnerConfig ?? [])) {
            return hub_pack_job_adapter_failure(
                $db,
                $taskId,
                $run,
                'artifact_contract_rejected',
                'BreezyVoice artifact contract validation failed',
                $cleanup,
                $gpuLease,
                $heartbeatState,
            );
        }
        $final = hub_finalize_pack_job_success($db, $taskId, $run, $workspace, (array)($task['input'] ?? []), $contract['artifact_contract'], $cleanup, $audioProbe, $gpuLease, $contract['runner_config'] ?? null, $sourceAudioAttestation, $heartbeatState);
        $latest = hub_get_task($db, $taskId);
        if (($final['ok'] ?? false) !== true && ($latest['status'] ?? '') === 'running' && hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds, $heartbeatState) === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, true, $context, $details, $pidInspector, $gpuLease, $cleanup, $heartbeatState);
        }
        return ['status' => (string)($latest['status'] ?? (($final['ok'] ?? false) ? 'success' : 'failed'))] + $final;
    } catch (Throwable $e) {
        $scrubPrivatePrompt();
        if (hub_pack_job_tick($db, $run, $gpuLease, $leaseSeconds, $heartbeatState) === 'fence_lost') {
            return hub_pack_job_lost_fence_outcome($db, $task, $run, $options, $started, $context, $details, $pidInspector, $gpuLease, $cleanup, $heartbeatState);
        }
        if (!is_array($cleanup) || !hub_pack_job_cleanup_attested($cleanup)) {
            $cleanup = $started && $context !== null
                ? hub_pack_job_cleanup_after_started_failure($options, $context, $details, $pidInspector, 'runtime_execution_failed')
                : hub_pack_job_no_work_cleanup();
        }
        $errorCode = $terminalErrorCode ?? hub_pack_job_failure_code($e, 'runtime_execution_failed');
        return hub_pack_job_adapter_failure(
            $db,
            $taskId,
            $run,
            $errorCode,
            $terminalErrorCode === null ? 'Pack job adapter failed: ' . substr($e->getMessage(), 0, 512) : 'Pack job exited unsuccessfully',
            $cleanup,
            $gpuLease,
            $heartbeatState
        );
    } finally {
        $scrubPrivatePrompt();
        if ($facebookProfileId !== null) {
            hub_facebook_profile_release_for_task($db, $facebookProfileId, $taskId);
        }
    }
}
