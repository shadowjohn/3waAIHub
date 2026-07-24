<?php
declare(strict_types=1);

function hub_gateway_dispatch(PDO $db, string $mode, ?callable $requester = null, array $internalRequest = []): array
{
    $started = microtime(true);
    $requestId = hub_new_request_id();
    $authContext = [];
    $clientIp = trim((string)($internalRequest['client_ip'] ?? hub_get_client_ip())) ?: hub_get_client_ip();
    $providedToken = array_key_exists('bearer_token', $internalRequest) ? (string)$internalRequest['bearer_token'] : null;
    $rawBody = array_key_exists('raw_body', $internalRequest) ? (string)$internalRequest['raw_body'] : null;
    $requestMethod = strtoupper(trim((string)($internalRequest['method'] ?? $_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?: 'GET';
    $requestContext = [
        'client_ip' => $clientIp,
        'method' => $requestMethod,
        'request_uri' => (string)($internalRequest['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? ''),
        'upload_bytes' => $rawBody === null ? hub_gateway_upload_bytes() : strlen($rawBody),
    ];
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $mode)) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(400, 'bad_request', 'invalid mode'), $started, $requestId, [], $requestContext);
    }
    if ($mode === 'yolo_gpu_internal') {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(404, 'unknown_mode', 'mode is not registered'), $started, $requestId, [], $requestContext);
    }
    $service = hub_get_service_by_mode($db, $mode);
    if (hub_is_audio_async_mode($mode)) {
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
        }
        try {
            $route = hub_resolve_audio_async_route($db, $mode);
        } catch (RuntimeException $e) {
            $code = in_array($e->getMessage(), ['pack_not_installed', 'pack_version_unavailable'], true) ? $e->getMessage() : 'pack_not_installed';
            return hub_gateway_finish($db, null, $mode, hub_gateway_error(503, $code, $code), $started, $requestId, $authContext, $requestContext);
        }

        return hub_gateway_finish($db, null, $mode, hub_api_audio_task_submit($db, $route, $authContext), $started, $requestId, $authContext, $requestContext);
    }
    if (hub_is_task_api_mode($mode)) {
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            if (($providedToken ?? hub_bearer_token_from_request()) === '' && hub_gateway_admin_legacy_task_session_allowed($db, $mode)) {
                $sessionContext = ['session_admin' => true];
                return hub_gateway_finish($db, null, $mode, hub_task_api_dispatch($db, $mode, $sessionContext), $started, $requestId, $sessionContext, $requestContext);
            }
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
        }

        return hub_gateway_finish($db, null, $mode, hub_task_api_dispatch($db, $mode, $authContext), $started, $requestId, $authContext, $requestContext);
    }
    if (!$service && hub_is_photo_api_mode($mode)) {
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
        }
        $photoResponse = hub_photo_api_dispatch($db, $mode, $authContext);
        $logService = is_array($photoResponse['service'] ?? null) ? $photoResponse['service'] : null;
        unset($photoResponse['service']);

        return hub_gateway_finish($db, $logService, $mode, $photoResponse, $started, $requestId, $authContext, $requestContext);
    }
    if (!$service && hub_is_audio_api_mode($mode)) {
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
        }
        $audioResponse = hub_audio_api_dispatch($db, $mode, $authContext);
        $logService = is_array($audioResponse['service'] ?? null) ? $audioResponse['service'] : null;
        unset($audioResponse['service']);

        return hub_gateway_finish($db, $logService, $mode, $audioResponse, $started, $requestId, $authContext, $requestContext);
    }
    if (!$service && hub_is_yolo_model_api_mode($mode)) {
        $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
        $authContext = $auth['context'] ?? [];
        if (empty($auth['ok'])) {
            return hub_gateway_finish($db, null, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
        }

        return hub_gateway_finish($db, null, $mode, hub_yolo_model_api_dispatch($db, $mode), $started, $requestId, $authContext, $requestContext);
    }
    if (!$service) {
        return hub_gateway_finish($db, null, $mode, hub_gateway_error(404, 'unknown_mode', 'mode is not registered'), $started, $requestId, [], $requestContext);
    }
    $auth = hub_gateway_authenticate_api_token($db, $mode, $clientIp, $providedToken);
    $authContext = $auth['context'] ?? [];
    if (empty($auth['ok'])) {
        return hub_gateway_finish($db, $service, $mode, $auth['response'], $started, $requestId, $authContext, $requestContext);
    }
    if ((int)$service['enabled'] !== 1) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'service_disabled', 'service is disabled'), $started, $requestId, $authContext, $requestContext);
    }
    if (in_array((string)$service['runtime_status'], ['pending', 'not_ready'], true) || (string)$service['install_status'] !== 'installed') {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(503, 'runtime_not_ready', 'service runtime is not ready'), $started, $requestId, $authContext, $requestContext);
    }
    if (!hub_gateway_service_ip_allowed_after_auth($db, $service, $clientIp, $authContext)) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(403, 'ip_not_allowed', 'client IP is not allowed for this service'), $started, $requestId, $authContext, $requestContext);
    }
    if (!hub_service_method_allowed($service, $requestMethod)) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(405, 'method_not_allowed', 'HTTP method is not allowed for this mode'), $started, $requestId, $authContext, $requestContext);
    }
    $isAudioSync = hub_audio_sync_route($service) !== null;
    if (!$isAudioSync && !hub_service_upload_size_allowed($service, (string)($rawBody === null ? $_SERVER['CONTENT_LENGTH'] ?? '' : strlen($rawBody)))) {
        return hub_gateway_finish($db, $service, $mode, hub_gateway_error(413, 'payload_too_large', 'request body is larger than this service allows'), $started, $requestId, $authContext, $requestContext);
    }

    $timeoutSec = hub_service_gateway_timeout_sec($service);
    $prepared = [];
    if ($requester === null || (string)($service['pack_id'] ?? '') === 'yolo-serving') {
        $prepared = hub_gateway_prepare_service_request($db, $service, $authContext, $rawBody);
        if (isset($prepared['response'])) {
            return hub_gateway_finish($db, $service, $mode, $prepared['response'], $started, $requestId, $authContext, $requestContext);
        }
        if (is_array($prepared['service'] ?? null)) {
            $service = $prepared['service'];
            $timeoutSec = hub_service_gateway_timeout_sec($service);
        }
    }
    if (hub_service_is_internal_task($service)) {
        return hub_gateway_finish($db, $service, $mode, hub_dispatch_internal_task_service($db, $service, $authContext), $started, $requestId, $authContext, $requestContext);
    }
    $syncAdmission = null;
    if ($isAudioSync) {
        $syncAdmission = hub_validate_audio_sync_request($db, $service);
        if (isset($syncAdmission['response'])) {
            return hub_gateway_finish($db, $service, $mode, $syncAdmission['response'], $started, $requestId, $authContext, $requestContext);
        }
    }
    $requester ??= static fn (array $service, int $timeoutSec): array => hub_proxy_request(
        $service['internal_url'],
        $timeoutSec,
        is_string($prepared['body'] ?? null) ? (string)$prepared['body'] : null,
        is_string($prepared['content_type'] ?? null) ? (string)$prepared['content_type'] : null,
        $requestMethod
    );

    $response = $isAudioSync
        ? hub_gateway_invoke_requester($requester, $service, $timeoutSec)
        : $requester($service, $timeoutSec);
    if (
        (string)($service['pack_id'] ?? '') === 'yolo-serving'
        && is_array($prepared['fallback_service'] ?? null)
        && hub_yolo_gateway_response_error($response) === 'gpu_not_ready'
    ) {
        hub_yolo_inject_predict_payload($prepared['model'], 'auto', 'cpu', null, 'gpu_not_ready');
        $service = $prepared['fallback_service'];
        $timeoutSec = hub_service_gateway_timeout_sec($service);
        $response = $requester($service, $timeoutSec);
    }

    if (isset($syncAdmission['run'])) {
        $response = hub_finish_audio_sync_request($db, $service, $syncAdmission, $response);
    }

    return hub_gateway_finish($db, $service, $mode, $response, $started, $requestId, $authContext, $requestContext);
}

function hub_gateway_invoke_requester(callable $requester, array $service, int $timeoutSec): array
{
    try {
        return $requester($service, $timeoutSec);
    } catch (Throwable) {
        return hub_gateway_error(502, 'proxy_error', 'service proxy error');
    }
}

function hub_audio_sync_route(array $service): ?array
{
    return match ([(string)($service['mode'] ?? ''), (string)($service['pack_id'] ?? '')]) {
        ['asr', 'whisper-asr'] => ['async_mode' => 'speech_transcribe'],
        ['tts', 'tts-voxcpm2'] => ['async_mode' => 'voice_generate'],
        default => null,
    };
}

function hub_validate_audio_sync_request(PDO $db, array $service): array
{
    $route = hub_audio_sync_route($service);
    if ($route === null) {
        return [];
    }
    $asyncMode = (string)$route['async_mode'];
    foreach (hub_audio_sync_request_control_keys() as $key) {
        if ($key === 'source_artifact_id' || str_starts_with($key, 'callback')) {
            return ['response' => hub_gateway_error(400, 'async_required', 'use ' . $asyncMode . '; sync requests do not accept ' . $key)];
        }
    }
    if (hub_audio_sync_upload_bytes() > hub_audio_sync_max_upload_bytes($db, $service)) {
        return ['response' => hub_gateway_error(413, 'async_required', 'use ' . $asyncMode . '; sync upload is too large')];
    }
    foreach (hub_audio_sync_upload_paths() as $path) {
        $probe = hub_pack_job_ffprobe($path);
        if (!is_array($probe) || !is_numeric($probe['duration_seconds'] ?? null) || (float)$probe['duration_seconds'] > 30.0) {
            return ['response' => hub_gateway_error(413, 'async_required', 'use ' . $asyncMode . '; sync audio must be 30 seconds or less')];
        }
    }
    if (!hub_audio_sync_requires_gpu($db, $service)) {
        return [];
    }

    $run = hub_audio_sync_create_runtime_run($db, $service);
    $lease = hub_runtime_gpu_acquire($db, $run, max(60, hub_service_gateway_timeout_sec($service) + 30));
    if ($lease === null) {
        hub_runtime_finish($db, (int)$run['id'], (string)$run['lease_token'], 'failed', ['error' => 'sync_busy']);
        return ['response' => hub_gateway_error(409, 'sync_busy', 'the single sync audio slot is busy')];
    }
    if (!hub_runtime_mark_running($db, (int)$run['id'], (string)$run['lease_token'])) {
        hub_audio_sync_abort($db, $run, $lease, 'runtime_lease_lost');
        return ['response' => hub_gateway_error(503, 'runtime_not_ready', 'sync runtime lease is unavailable')];
    }
    $baseline = hub_audio_sync_gpu_processes();
    if ($baseline === null || !hub_runtime_record_gpu_ownership($db, $run, $lease, hub_audio_sync_container_name($service), $baseline, [])) {
        hub_audio_sync_abort($db, $run, $lease, 'gpu_probe_failed');
        return ['response' => hub_gateway_error(503, 'runtime_not_ready', 'sync GPU inspection is unavailable')];
    }

    return ['route' => $route, 'run' => $run, 'lease' => $lease, 'baseline_pids' => $baseline];
}

function hub_audio_sync_requires_gpu(PDO $db, array $service): bool
{
    $settings = hub_service_settings_values($db, $service);
    $configured = match ((string)$service['pack_id']) {
        'whisper-asr' => $settings['WHISPER_REAL_INFERENCE'] ?? '0',
        'tts-voxcpm2' => $settings['VOXCPM2_REAL_INFERENCE'] ?? '0',
        default => '0',
    };
    $requested = $_POST['real_inference'] ?? null;
    if ($requested === null) {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        $requested = is_array($payload) ? ($payload['real_inference'] ?? null) : null;
    }

    return hub_photo_parse_bool($configured) || hub_photo_parse_bool($requested);
}

function hub_audio_sync_request_control_keys(): array
{
    $payload = [];
    $rawBody = (string)file_get_contents('php://input');
    if ($rawBody !== '') {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $keys = [];
    foreach ([$_GET ?? [], $_POST ?? [], $payload] as $source) {
        foreach (array_keys($source) as $key) {
            if (is_string($key)) {
                $keys[strtolower($key)] = true;
            }
        }
    }

    return array_keys($keys);
}

function hub_audio_sync_upload_bytes(): int
{
    $bytes = hub_gateway_upload_bytes();
    foreach ($_FILES ?? [] as $file) {
        if (is_array($file) && isset($file['size']) && is_numeric($file['size']) && !is_array($file['size'])) {
            $bytes = max($bytes, (int)$file['size']);
        }
    }

    return $bytes;
}

function hub_audio_sync_max_upload_bytes(PDO $db, array $service): int
{
    $maxMb = hub_service_gateway_int($service, 'max_upload_mb', 0);
    foreach (hub_service_settings_values($db, $service) as $key => $value) {
        if (str_ends_with($key, '_MAX_UPLOAD_MB') && ctype_digit($value)) {
            $maxMb = $maxMb > 0 ? min($maxMb, (int)$value) : (int)$value;
        }
    }

    return max(1, $maxMb) * 1024 * 1024;
}

function hub_audio_sync_upload_paths(): array
{
    $paths = [];
    foreach ($_FILES ?? [] as $file) {
        $path = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_file($path)) {
            $paths[] = $path;
        }
    }

    return $paths;
}

function hub_audio_sync_create_runtime_run(PDO $db, array $service): array
{
    $now = hub_now();
    $run = [
        'run_id' => 'sync-' . (string)$service['mode'] . '-' . bin2hex(random_bytes(12)),
        'worker_id' => 'gateway-sync-' . substr(hash('sha256', gethostname() ?: 'host'), 0, 12),
        'lease_token' => bin2hex(random_bytes(32)),
    ];
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, pack_version, runner_version, caller, workspace, state, worker_id, lease_token, lease_expires_at, heartbeat_at, claimed_at, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :pack_version, :runner_version, :caller, :workspace, :state, :worker_id, :lease_token, :lease_expires_at, :heartbeat_at, :claimed_at, :started_at, :created_at)'
    )->execute([
        ':run_id' => $run['run_id'],
        ':pack_id' => (string)$service['pack_id'],
        ':task' => (string)$service['mode'],
        ':pack_version' => (string)($service['pack_version'] ?? ''),
        ':runner_version' => 'gateway-sync/0.1',
        ':caller' => $run['worker_id'],
        ':workspace' => HUB_DATA_DIR . '/services/' . (string)$service['service_key'],
        ':state' => 'claimed',
        ':worker_id' => $run['worker_id'],
        ':lease_token' => $run['lease_token'],
        ':lease_expires_at' => hub_runtime_lease_until(max(60, hub_service_gateway_timeout_sec($service) + 30)),
        ':heartbeat_at' => $now,
        ':claimed_at' => $now,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    return hub_runtime_fetch_run($db, (int)$db->lastInsertId()) ?? throw new RuntimeException('sync_runtime_create_failed');
}

function hub_finish_audio_sync_request(PDO $db, array $service, array $admission, array $response): array
{
    $run = $admission['run'];
    $lease = $admission['lease'];
    $clean = hub_audio_sync_cleanup($db, $service, $run, $lease, (array)$admission['baseline_pids']);
    if (!$clean) {
        hub_runtime_gpu_block($db, $run, $lease, 'cleanup_failed');
        hub_runtime_finish($db, (int)$run['id'], (string)$run['lease_token'], 'failed', ['error' => 'cleanup_failed']);
        return hub_gateway_error(500, 'cleanup_failed', 'sync audio cleanup could not be proven');
    }
    if (!hub_runtime_gpu_release($db, $run, $lease)) {
        hub_runtime_gpu_block($db, $run, $lease, 'cleanup_failed');
        hub_runtime_finish($db, (int)$run['id'], (string)$run['lease_token'], 'failed', ['error' => 'cleanup_failed']);
        return hub_gateway_error(500, 'cleanup_failed', 'sync GPU release could not be fenced');
    }
    $terminal = hub_audio_sync_terminal_result($response);
    hub_runtime_finish($db, (int)$run['id'], (string)$run['lease_token'], $terminal['state'], $terminal['result']);
    hub_update_service_status($db, (int)$service['id'], 'stopped');

    return $response;
}

function hub_audio_sync_terminal_result(array $response): array
{
    return (int)($response['status'] ?? 500) < 400
        ? ['state' => 'succeeded', 'result' => []]
        : ['state' => 'failed', 'result' => ['error' => 'sync_proxy_failed']];
}

function hub_audio_sync_abort(PDO $db, array $run, array $lease, string $error): void
{
    hub_runtime_gpu_release($db, $run, $lease);
    hub_runtime_finish($db, (int)$run['id'], (string)$run['lease_token'], 'failed', ['error' => $error]);
}

function hub_audio_sync_cleanup(PDO $db, array $service, array $run, array $lease, array $baselinePids): bool
{
    $ownedPids = hub_audio_sync_gpu_processes();
    if ($ownedPids === null) {
        return false;
    }
    $ownedPids = array_values(array_diff($ownedPids, hub_runtime_gpu_recovery_pids($baselinePids)));
    $container = hub_audio_sync_container_name($service);
    if (!hub_runtime_record_gpu_ownership($db, $run, $lease, $container, $baselinePids, $ownedPids)
        || !hub_audio_sync_remove_container($container)) {
        return false;
    }
    $remainingPids = hub_audio_sync_gpu_processes();

    return $remainingPids !== null && array_intersect($ownedPids, $remainingPids) === [];
}

function hub_audio_sync_container_name(array $service): string
{
    $serviceKey = (string)($service['service_key'] ?? '');
    if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $serviceKey) !== 1) {
        throw new RuntimeException('sync_service_container_invalid');
    }

    return '3waaihub-' . $serviceKey;
}

function hub_audio_sync_gpu_processes(): ?array
{
    if (!function_exists('exec')) {
        return null;
    }
    $output = [];
    $exitCode = 1;
    exec('nvidia-smi --query-compute-apps=pid --format=csv,noheader,nounits 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        return null;
    }

    return hub_runtime_gpu_recovery_pids($output);
}

function hub_audio_sync_remove_container(string $container): bool
{
    $before = hub_audio_sync_container_state($container);
    if (!is_array($before) || empty($before['exists'])) {
        return false;
    }
    foreach ([['stop', '-t', '10'], ['container', 'rm', '-f']] as $command) {
        $output = [];
        $exitCode = 1;
        exec('docker ' . implode(' ', array_map('escapeshellarg', $command)) . ' ' . escapeshellarg($container) . ' 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            return false;
        }
    }
    $after = hub_audio_sync_container_state($container);

    return is_array($after) && empty($after['exists']);
}

function hub_audio_sync_container_state(string $container): ?array
{
    if (!function_exists('exec')) {
        return null;
    }
    $output = [];
    $exitCode = 1;
    exec('docker container inspect --format ' . escapeshellarg('{{json .State}}') . ' ' . escapeshellarg($container) . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0) {
        return preg_match('/no such (?:container|object)/i', implode("\n", $output)) === 1 ? ['exists' => false] : null;
    }
    try {
        $state = json_decode(implode("\n", $output), true, 16, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($state) && is_bool($state['Running'] ?? null) ? ['exists' => true, 'running' => $state['Running']] : null;
}

function hub_gateway_prepare_service_request(PDO $db, array $service, array $authContext, ?string $rawBody = null): array
{
    return match ((string)($service['pack_id'] ?? '')) {
        'tts-voxcpm2' => hub_prepare_tts_voxcpm2_payload($db, $service, $authContext, $rawBody ?? (string)file_get_contents('php://input')),
        'yolo-serving' => hub_prepare_yolo_serving_payload($db, $service),
        default => [],
    };
}

function hub_dispatch_internal_task_service(PDO $db, array $service, array $authContext = []): array
{
    $internalUrl = (string)($service['internal_url'] ?? '');
    if (!str_starts_with($internalUrl, 'internal-task:')) {
        return hub_gateway_error(500, 'internal_task_invalid', 'internal_task service URL is invalid');
    }

    $route = substr($internalUrl, strlen('internal-task:'));
    if (!str_starts_with($route, 'task_submit:')) {
        return hub_gateway_error(501, 'internal_task_not_ready', 'internal_task route is not supported yet');
    }

    $taskType = substr($route, strlen('task_submit:'));
    if (!hub_is_valid_task_type($taskType)) {
        return hub_gateway_json(501, [
            'ok' => false,
            'error' => 'internal_task_not_ready',
            'message' => 'internal task type is not allowlisted yet',
            'task_type' => $taskType,
        ]);
    }
    if ($taskType === 'pack_job') {
        return hub_gateway_error(400, 'forbidden_task_control', 'client task controls are not accepted');
    }

    $previousTaskType = $_POST['task_type'] ?? null;
    $_POST['task_type'] = $taskType;
    try {
        return hub_api_task_submit($db, array_merge($authContext, ['internal_task' => true]));
    } finally {
        if ($previousTaskType === null) {
            unset($_POST['task_type']);
        } else {
            $_POST['task_type'] = $previousTaskType;
        }
    }
}

function hub_prepare_tts_voxcpm2_payload(PDO $db, array $service, array $authContext, string $rawBody): array
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) !== 'POST' && trim($rawBody) === '') {
        return [];
    }
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'JSON body is required')];
    }
    foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path', 'prompt_text'] as $blockedKey) {
        if (array_key_exists($blockedKey, $payload)) {
            return ['response' => hub_gateway_error(400, 'bad_request', 'server-side audio paths are not accepted')];
        }
    }

    $ttsMode = trim((string)($payload['mode'] ?? 'design')) ?: 'design';
    if (!in_array($ttsMode, ['design', 'clone', 'ultimate_clone'], true)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'mode must be design, clone, or ultimate_clone')];
    }

    if (in_array($ttsMode, ['clone', 'ultimate_clone'], true)) {
        if (empty($authContext['member_id'])) {
            return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice clone requires an owned voice profile')];
        }
        try {
            $profileId = hub_normalize_voice_profile_ref($payload['voice_profile_id'] ?? $payload['reference_audio_id'] ?? '');
            $profile = hub_get_voice_profile_for_member($db, $profileId, (int)$authContext['member_id']);
            if (!$profile) {
                return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice profile is not available for this member')];
            }
            $payload['reference_wav_path'] = hub_voice_profile_container_path($profile);
            $payload['voice_profile_id'] = (int)$profile['id'];
            $payload['reference_audio_sha256'] = (string)$profile['reference_audio_sha256'];
            unset($payload['reference_audio_id']);
            if ($ttsMode === 'ultimate_clone') {
                if (trim((string)($profile['prompt_text'] ?? '')) === '' || empty($profile['prompt_text_confirmed_at'])) {
                    return ['response' => hub_gateway_error(409, 'voice_profile_transcript_unconfirmed', 'Ultimate clone requires a confirmed voice profile transcript')];
                }
                $payload['prompt_wav_path'] = $payload['reference_wav_path'];
                $payload['prompt_text'] = (string)$profile['prompt_text'];
            }
            hub_record_voice_profile_audit(
                $db,
                (int)$profile['id'],
                (int)$profile['owner_member_id'],
                isset($authContext['token_id']) ? (int)$authContext['token_id'] : null,
                'use',
                $ttsMode,
                [
                    'service_id' => (int)($service['id'] ?? 0),
                    'mode' => (string)($service['mode'] ?? 'tts'),
                    'text_chars' => function_exists('mb_strlen') ? mb_strlen((string)($payload['text'] ?? ''), 'UTF-8') : strlen((string)($payload['text'] ?? '')),
                ]
            );
        } catch (InvalidArgumentException) {
            return ['response' => hub_gateway_error(400, 'voice_profile_required', 'reference_audio_id or voice_profile_id is required')];
        } catch (Throwable) {
            return ['response' => hub_gateway_error(403, 'voice_profile_forbidden', 'Voice profile could not be used')];
        }
    }

    return [
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'content_type' => 'application/json',
    ];
}

function hub_prepare_yolo_serving_payload(PDO $db, array $service): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return [];
    }
    foreach (['host_path', 'source_path', 'artifact_path', 'model_path', 'container_path', 'file_path', 'slot_no', 'device'] as $blockedKey) {
        if (array_key_exists($blockedKey, $_POST)) {
            return ['response' => hub_gateway_error(400, 'bad_request', 'client model paths are not accepted')];
        }
    }

    $modelRef = trim((string)($_POST['model_ref'] ?? ''));
    if ($modelRef === '') {
        return ['response' => hub_gateway_error(400, 'model_ref_required', 'model_ref is required')];
    }
    $executionPolicy = trim((string)($_POST['execution_policy'] ?? 'auto')) ?: 'auto';
    if (!in_array($executionPolicy, ['auto', 'cpu_only', 'gpu_only'], true)) {
        return ['response' => hub_gateway_error(400, 'bad_request', 'execution_policy must be auto, cpu_only, or gpu_only')];
    }
    $model = hub_get_yolo_model_version($db, $modelRef);
    if (!$model) {
        return ['response' => hub_gateway_error(404, 'model_not_found', 'model_ref was not found')];
    }
    if ((string)$model['task_type'] !== 'detect') {
        return ['response' => hub_gateway_error(400, 'model_task_unsupported', 'YOLO serving 1A supports Detect .pt models only')];
    }
    if (!is_file(hub_yolo_model_version_host_path($db, $model))) {
        return ['response' => hub_gateway_error(404, 'model_artifact_missing', 'registered model artifact is missing')];
    }

    $cpuService = hub_get_service_by_key($db, 'yolo-cpu') ?: $service;
    $deployment = hub_yolo_hot_deployment_for_model($db, (int)$model['id']);
    if ($executionPolicy === 'cpu_only') {
        hub_yolo_inject_predict_payload($model, $executionPolicy, 'cpu');

        return ['service' => $cpuService, 'model' => $model];
    }
    if (!$deployment) {
        if ($executionPolicy === 'gpu_only') {
            return ['response' => hub_gateway_error(409, 'gpu_not_ready', 'YOLO model is not hot in a GPU slot')];
        }
        hub_yolo_inject_predict_payload($model, $executionPolicy, 'cpu', null, 'gpu_not_ready');

        return ['service' => $cpuService, 'model' => $model];
    }

    $gpuService = hub_get_service_by_key($db, hub_yolo_gpu_service_key());
    $gpuReady = $gpuService
        && (int)($gpuService['enabled'] ?? 0) === 1
        && (string)($gpuService['install_status'] ?? '') === 'installed'
        && (string)($gpuService['runtime_status'] ?? '') === 'running';
    if (!$gpuReady) {
        if ($executionPolicy === 'gpu_only') {
            return ['response' => hub_gateway_error(503, 'gpu_service_unavailable', 'YOLO GPU serving service is not available')];
        }
        hub_yolo_inject_predict_payload($model, $executionPolicy, 'cpu', null, 'gpu_service_unavailable');

        return ['service' => $cpuService, 'model' => $model];
    }

    hub_yolo_inject_predict_payload($model, $executionPolicy, 'cuda:0', $deployment);

    return [
        'service' => $gpuService,
        'fallback_service' => $executionPolicy === 'auto' ? $cpuService : null,
        'model' => $model,
    ];
}

function hub_yolo_inject_predict_payload(array $model, string $executionPolicy, string $device, ?array $deployment = null, ?string $fallbackReason = null): void
{
    $_POST['model_ref'] = (string)$model['model_ref'];
    $_POST['model_version_id'] = (string)(int)$model['id'];
    $_POST['model_path'] = hub_yolo_model_version_container_path($model);
    $_POST['model_sha256'] = (string)$model['sha256'];
    $_POST['execution_policy'] = $executionPolicy;
    $_POST['device'] = $device;
    if (trim((string)($_POST['imgsz'] ?? '')) === '' && (int)($model['imgsz'] ?? 0) > 0) {
        $_POST['imgsz'] = (string)(int)$model['imgsz'];
    }
    if ($deployment) {
        $_POST['slot_no'] = (string)(int)$deployment['slot_no'];
    } else {
        unset($_POST['slot_no']);
    }
    if ($fallbackReason !== null && $fallbackReason !== '') {
        $_POST['fallback_reason'] = $fallbackReason;
    } else {
        unset($_POST['fallback_reason']);
    }
}

function hub_yolo_gateway_response_error(array $response): ?string
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return null;
    }

    return isset($payload['error']) ? (string)$payload['error'] : null;
}

function hub_gateway_service_ip_allowed_after_auth(PDO $db, array $service, string $clientIp, array $authContext): bool
{
    if (empty($authContext['token_id'])) {
        return hub_service_ip_allowed($db, $service, $clientIp);
    }
    if (hub_get_storage_setting($db, 'AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST') !== '1') {
        return true;
    }

    return hub_enabled_service_ip_rules($db, (int)$service['id']) === [] || hub_service_ip_allowed($db, $service, $clientIp);
}

function hub_is_task_api_mode(string $mode): bool
{
    return array_key_exists($mode, hub_task_api_modes());
}

function hub_is_photo_api_mode(string $mode): bool
{
    return array_key_exists($mode, hub_photo_modes());
}

function hub_is_audio_api_mode(string $mode): bool
{
    return in_array($mode, ['audio_upload', 'audio'], true);
}

function hub_is_yolo_model_api_mode(string $mode): bool
{
    return in_array($mode, ['yolo_model_register', 'yolo_model_status', 'yolo_model_assign_gpu', 'yolo_model_unassign_gpu'], true);
}

function hub_task_api_modes(): array
{
    return [
        'task_submit' => 'Task Submit',
        'task_status' => 'Task Status',
        'task_result' => 'Task Result',
        'task_log' => 'Task Log',
        'task_cancel' => 'Task Cancel',
        'task_retry' => 'Task Retry',
        'task_artifacts_ack' => 'Task Artifact Acknowledge',
        'task_artifact_retention' => 'Task Artifact Retention Control',
        'artifact' => 'Task Artifact',
    ];
}

function hub_yolo_model_api_dispatch(PDO $db, string $mode): array
{
    return match ($mode) {
        'yolo_model_register' => hub_api_yolo_model_register($db),
        'yolo_model_status' => hub_api_yolo_model_status($db),
        'yolo_model_assign_gpu' => hub_api_yolo_model_assign_gpu($db),
        'yolo_model_unassign_gpu' => hub_api_yolo_model_unassign_gpu($db),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown_mode']),
    };
}

function hub_api_yolo_model_register(PDO $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'yolo_model_register requires POST');
    }

    try {
        $model = hub_yolo_register_model_version($db, hub_yolo_model_register_input());
    } catch (Throwable $e) {
        $errorCode = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
            ? (string)$e->getMessage()
            : 'model_import_failed';
        if ($errorCode === '' || str_contains($errorCode, ' ')) {
            $errorCode = 'model_import_failed';
        }

        return hub_gateway_error(hub_yolo_model_error_status($errorCode), $errorCode, $errorCode);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'model_ref' => (string)$model['model_ref'],
        'version_id' => (int)$model['id'],
        'model_version_id' => (int)$model['id'],
        'state' => 'registered',
        'cpu_available' => true,
        'warm_state' => 'cold',
        'task_type' => (string)$model['task_type'],
        'sha256' => (string)$model['sha256'],
    ]);
}

function hub_yolo_model_register_input(): array
{
    $payload = $_POST;
    if ($payload === [] && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    $artifact = is_array($payload['artifact'] ?? null) ? $payload['artifact'] : [
        'type' => 'host_path',
        'path' => (string)($payload['artifact_path'] ?? $payload['host_path'] ?? ''),
        'sha256' => (string)($payload['artifact_sha256'] ?? $payload['sha256'] ?? ''),
    ];
    $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
    foreach (['imgsz', 'class_count'] as $key) {
        if (array_key_exists($key, $payload) && !array_key_exists($key, $metadata)) {
            $metadata[$key] = is_numeric($payload[$key]) ? (int)$payload[$key] : $payload[$key];
        }
    }
    if (isset($payload['labels']) && !isset($metadata['labels'])) {
        $metadata['labels'] = is_array($payload['labels'])
            ? $payload['labels']
            : array_values(array_filter(array_map('trim', explode(',', (string)$payload['labels']))));
    }

    return [
        'source_system' => (string)($payload['source_system'] ?? ''),
        'external_model_key' => (string)($payload['external_model_key'] ?? ''),
        'display_name' => (string)($payload['display_name'] ?? ''),
        'task_type' => (string)($payload['task_type'] ?? 'detect'),
        'artifact' => $artifact,
        'metadata' => $metadata,
        'source_run_id' => (string)($payload['source_run_id'] ?? ''),
    ];
}

function hub_api_yolo_model_status(PDO $db): array
{
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
        return hub_gateway_error(405, 'method_not_allowed', 'yolo_model_status requires GET or POST');
    }
    $modelRef = trim((string)($_GET['model_ref'] ?? $_POST['model_ref'] ?? ''));
    if ($modelRef === '') {
        return hub_gateway_error(400, 'model_ref_required', 'model_ref is required');
    }
    $model = hub_get_yolo_model_version($db, $modelRef);
    if (!$model) {
        return hub_gateway_error(404, 'model_not_found', 'model_ref was not found');
    }

    $hostPath = hub_yolo_model_version_host_path($db, $model);
    $registered = is_file($hostPath);
    $gpu = hub_yolo_model_gpu_status($db, $model);

    return hub_gateway_json(200, [
        'ok' => true,
        'model_ref' => (string)$model['model_ref'],
        'version_id' => (int)$model['id'],
        'model_version_id' => (int)$model['id'],
        'state' => $registered ? 'registered' : 'error',
        'cpu_available' => $registered,
        'warm_state' => $gpu['warm_state'] ?? 'cold',
        'gpu' => $gpu,
        'task_type' => (string)$model['task_type'],
        'sha256' => (string)$model['sha256'],
        'error' => $registered ? null : 'model_artifact_missing',
    ]);
}

function hub_api_yolo_model_assign_gpu(PDO $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'yolo_model_assign_gpu requires POST');
    }
    $payload = $_POST;
    if ($payload === [] && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    try {
        $assigned = hub_yolo_assign_gpu_slot($db, (string)($payload['model_ref'] ?? ''), (int)($payload['slot_no'] ?? 0));
    } catch (Throwable $e) {
        $errorCode = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
            ? (string)$e->getMessage()
            : 'gpu_warm_failed';
        if ($errorCode === '' || str_contains($errorCode, ' ')) {
            $errorCode = 'gpu_warm_failed';
        }

        return hub_gateway_error(hub_yolo_model_error_status($errorCode), $errorCode, $errorCode);
    }

    $deployment = $assigned['deployment'] ?? [];

    return hub_gateway_json(200, [
        'ok' => true,
        'model_ref' => (string)($assigned['model']['model_ref'] ?? ''),
        'version_id' => (int)($assigned['model']['id'] ?? 0),
        'model_version_id' => (int)($assigned['model']['id'] ?? 0),
        'service_key' => hub_yolo_gpu_service_key(),
        'slot_no' => (int)($deployment['slot_no'] ?? 0),
        'warm_state' => (string)($deployment['actual_state'] ?? 'queued'),
        'run_id' => (string)($assigned['run_id'] ?? ''),
    ]);
}

function hub_api_yolo_model_unassign_gpu(PDO $db): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'yolo_model_unassign_gpu requires POST');
    }
    $payload = $_POST;
    if ($payload === [] && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    try {
        $removed = hub_yolo_unassign_gpu($db, (string)($payload['model_ref'] ?? ''));
    } catch (Throwable $e) {
        $errorCode = $e instanceof RuntimeException || $e instanceof InvalidArgumentException
            ? (string)$e->getMessage()
            : 'gpu_unload_failed';
        if ($errorCode === '' || str_contains($errorCode, ' ')) {
            $errorCode = 'gpu_unload_failed';
        }

        return hub_gateway_error(hub_yolo_model_error_status($errorCode), $errorCode, $errorCode);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'model_ref' => (string)($removed['model']['model_ref'] ?? ''),
        'version_id' => (int)($removed['model']['id'] ?? 0),
        'model_version_id' => (int)($removed['model']['id'] ?? 0),
        'service_key' => hub_yolo_gpu_service_key(),
        'run_id' => (string)($removed['run_id'] ?? ''),
    ]);
}

function hub_yolo_model_error_status(string $errorCode): int
{
    return match ($errorCode) {
        'model_artifact_missing', 'model_not_found' => 404,
        'model_import_path_not_allowed', 'model_checksum_mismatch', 'model_task_unsupported',
        'model_ref_required', 'model_path_forbidden', 'gpu_slot_invalid', 'bad_request' => 400,
        'gpu_slot_occupied', 'gpu_model_already_assigned', 'gpu_not_ready', 'gpu_model_slot_mismatch' => 409,
        'gpu_service_unavailable', 'gpu_warm_failed', 'gpu_out_of_memory', 'gpu_unload_failed' => 503,
        default => 500,
    };
}

function hub_photo_api_dispatch(PDO $db, string $mode, array $authContext): array
{
    return match ($mode) {
        'photo_upload' => hub_api_photo_upload($db, $authContext),
        'photo' => hub_api_photo($db, $authContext),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown_mode']),
    };
}

function hub_audio_api_dispatch(PDO $db, string $mode, array $authContext): array
{
    return match ($mode) {
        'audio_upload' => hub_api_audio_upload($db, $authContext),
        'audio' => hub_api_audio($db, $authContext),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown_mode']),
    };
}

function hub_api_audio_upload(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'audio_upload requires POST');
    }
    foreach (['audio_path', 'file_path', 'host_path', 'container_path', 'storage_relpath', 'audio_url', 'audio_internal_path'] as $blocked) {
        if (array_key_exists($blocked, $_POST)) {
            return hub_gateway_error(400, 'bad_request', 'client audio paths are not accepted');
        }
    }
    try {
        $asset = hub_audio_store_upload($db, is_array($_FILES['audio'] ?? null) ? $_FILES['audio'] : [], $authContext);
    } catch (RuntimeException $e) {
        return hub_gateway_error(match ($e->getMessage()) {
            'payload_too_large', 'audio_too_long' => 413,
            'unsupported_audio_format' => 415,
            default => 400,
        }, $e->getMessage(), $e->getMessage());
    } catch (Throwable) {
        return hub_gateway_error(500, 'storage_failed', 'audio storage failed');
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'audio_id' => $asset['audio_id'],
        'mime' => $asset['mime'],
        'size' => (int)$asset['byte_size'],
        'duration_ms' => (int)$asset['duration_ms'],
        'sample_rate' => (int)$asset['sample_rate'],
        'channels' => (int)$asset['channels'],
        'expires_at' => $asset['expires_at'],
    ]);
}

function hub_api_audio(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'audio requires POST');
    }
    $payload = $_POST;
    if (empty($payload) && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
        $json = json_decode((string)file_get_contents('php://input'), true);
        $payload = is_array($json) ? $json : [];
    }
    foreach (['audio_path', 'file_path', 'host_path', 'container_path', 'storage_relpath', 'audio_url', 'audio_internal_path'] as $blocked) {
        if (array_key_exists($blocked, $payload)) {
            return hub_gateway_error(400, 'bad_request', 'client audio paths are not accepted');
        }
    }
    $audioId = trim((string)($payload['audio_id'] ?? ''));
    $hasUpload = is_array($_FILES['audio'] ?? null) && (int)($_FILES['audio']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    if (!$hasUpload && $audioId === '') {
        return hub_gateway_error(400, 'file_required', 'audio upload is required');
    }
    $assetPath = null;
    if ($audioId !== '') {
        $asset = hub_audio_get_asset_for_auth($db, $audioId, $authContext);
        $assetPath = $asset ? hub_audio_asset_host_path($asset) : null;
        if (!$asset || $assetPath === null) {
            return hub_gateway_error(404, 'audio_not_found', 'audio was not found or is not available');
        }
    }

    $serviceLookup = hub_audio_service_for_request($db, hub_get_client_ip(), $authContext);
    if (isset($serviceLookup['response'])) {
        return $serviceLookup['response'];
    }
    $service = $serviceLookup['service'];
    $url = preg_replace('#/chat$#', '/audio', (string)$service['internal_url']) ?: (string)$service['internal_url'];
    if ($audioId !== '') {
        $response = hub_proxy_audio_asset_request($url, hub_service_gateway_timeout_sec($service), (string)$assetPath, [
            'operation' => (string)($payload['operation'] ?? 'understand'),
            'text' => (string)($payload['text'] ?? ''),
            'max_tokens' => (string)($payload['max_tokens'] ?? '512'),
            'real_inference' => (string)($payload['real_inference'] ?? '0'),
        ]);
    } else {
        $response = hub_proxy_request($url, hub_service_gateway_timeout_sec($service));
    }
    $response = hub_audio_normalize_proxy_response($response);
    $response['service'] = $service;

    return $response;
}

function hub_audio_service_for_request(PDO $db, string $clientIp, array $authContext): array
{
    $service = hub_get_service_by_key($db, 'gemma4-main');
    if (
        !$service
        || (int)$service['enabled'] !== 1
        || (string)$service['install_status'] !== 'installed'
        || (string)$service['runtime_status'] !== 'running'
    ) {
        return ['response' => hub_gateway_error(503, 'model_not_ready', 'audio service is not ready')];
    }
    if (!hub_gateway_service_ip_allowed_after_auth($db, $service, $clientIp, $authContext)) {
        return ['service' => $service, 'response' => hub_gateway_error(403, 'ip_not_allowed', 'client IP is not allowed for this service')];
    }

    return ['service' => $service];
}

function hub_audio_normalize_proxy_response(array $response): array
{
    $status = (int)($response['status'] ?? 0);
    if ($status < 200 || $status >= 400) {
        return $response;
    }
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($payload) || ($payload['ok'] ?? null) === false) {
        return $response;
    }

    $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
    $payload['ok'] = true;
    $payload['mock'] = (bool)($payload['mock'] ?? false);
    $payload['runtime_level'] = (string)($payload['runtime_level'] ?? 'L5-benchmark-ready');
    $payload['model'] = (string)($payload['model'] ?? 'gemma4-12b');
    $payload['operation'] = (string)($payload['operation'] ?? 'understand');
    $payload['answer'] = (string)($payload['answer'] ?? '');
    $payload['transcript'] = (string)($payload['transcript'] ?? '');
    $payload['summary'] = (string)($payload['summary'] ?? '');
    $payload['tags'] = is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : [];
    $payload['warnings'] = is_array($payload['warnings'] ?? null) ? array_values($payload['warnings']) : [];
    $payload['audio'] = is_array($payload['audio'] ?? null) ? $payload['audio'] : [];
    $payload['usage'] = [
        'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
        'total_tokens' => (int)($usage['total_tokens'] ?? 0),
    ];
    $payload['elapsed_ms'] = (int)($payload['elapsed_ms'] ?? 0);

    return hub_gateway_json($status, $payload);
}

function hub_proxy_audio_asset_request(string $url, int $timeoutSec, string $audioPath, array $fields): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return hub_gateway_json(502, ['ok' => false, 'error' => 'curl unavailable']);
    }
    $fields['audio'] = new CURLFile($audioPath, 'audio/wav', 'audio.wav');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
        CURLOPT_POSTFIELDS => $fields,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => hub_gateway_error(504, 'gateway_timeout', 'service gateway timeout'),
            CURLE_COULDNT_CONNECT => hub_gateway_error(503, 'service_unavailable', 'service is unavailable'),
            default => hub_gateway_error(502, 'proxy_error', 'service proxy error'),
        };
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => ['Content-Type: ' . $contentType], 'body' => $body];
}

function hub_api_photo_upload(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'photo_upload requires POST');
    }
    try {
        $asset = hub_photo_store_upload($db, is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [], $authContext);
    } catch (RuntimeException $e) {
        return hub_gateway_error(match ($e->getMessage()) {
            'payload_too_large' => 413,
            'unsupported_media_type' => 415,
            default => 400,
        }, $e->getMessage(), $e->getMessage());
    } catch (Throwable) {
        return hub_gateway_error(500, 'storage_failed', 'photo storage failed');
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'image_id' => $asset['image_id'],
        'mime' => $asset['mime'],
        'size' => (int)$asset['byte_size'],
        'width' => (int)$asset['width'],
        'height' => (int)$asset['height'],
        'expires_at' => $asset['expires_at'],
    ]);
}

function hub_api_photo(PDO $db, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'photo requires POST');
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        return hub_gateway_error(400, 'bad_request', 'JSON body is required');
    }
    $imageId = trim((string)($payload['image_id'] ?? ''));
    if ($imageId === '') {
        return hub_gateway_error(400, 'image_id_required', 'image_id is required');
    }
    $text = trim((string)($payload['text'] ?? ''));
    if ($text === '') {
        return hub_gateway_error(400, 'text_required', 'text is required');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) > 12000) {
        return hub_gateway_error(400, 'bad_request', 'text is too long');
    }
    foreach (['image_path', 'file_path', 'host_path', 'container_path', 'storage_relpath', 'image_url', 'image_internal_path'] as $blocked) {
        if (array_key_exists($blocked, $payload)) {
            return hub_gateway_error(400, 'bad_request', 'client image paths are not accepted');
        }
    }

    $asset = hub_photo_get_asset_for_auth($db, $imageId, $authContext);
    if (!$asset || hub_photo_asset_host_path($asset) === null) {
        return hub_gateway_error(404, 'image_not_found', 'image was not found or is not available');
    }
    $settings = hub_photo_settings($db);
    $serviceLookup = hub_photo_vision_service_for_request($db, hub_get_client_ip(), $authContext, (string)$settings['vision_service_key']);
    if (isset($serviceLookup['response'])) {
        return $serviceLookup['response'];
    }
    $service = $serviceLookup['service'];

    $url = preg_replace('#/chat$#', '/photo', (string)$service['internal_url']) ?: (string)$service['internal_url'];
    $response = hub_proxy_request($url, hub_service_gateway_timeout_sec($service), json_encode(
        hub_photo_request_payload($db, $asset, $payload),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ), 'application/json');
    $response = hub_photo_normalize_proxy_response($response, $imageId);
    $response['service'] = $service;

    return $response;
}

function hub_photo_normalize_proxy_response(array $response, string $imageId): array
{
    $status = (int)($response['status'] ?? 0);
    if ($status < 200 || $status >= 400) {
        return $response;
    }
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($payload) || ($payload['ok'] ?? null) === false) {
        return $response;
    }

    $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
    $payload['ok'] = true;
    $payload['mock'] = (bool)($payload['mock'] ?? false);
    $payload['runtime_level'] = (string)($payload['runtime_level'] ?? 'L5-benchmark-ready');
    $payload['model'] = (string)($payload['model'] ?? 'gemma4-12b');
    $payload['image_id'] = (string)($payload['image_id'] ?? $imageId);
    $payload['answer'] = (string)($payload['answer'] ?? '');
    $payload['caption'] = (string)($payload['caption'] ?? '');
    $payload['tags'] = is_array($payload['tags'] ?? null) ? array_values($payload['tags']) : [];
    $payload['usage'] = [
        'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
        'total_tokens' => (int)($usage['total_tokens'] ?? 0),
    ];
    $payload['elapsed_ms'] = (int)($payload['elapsed_ms'] ?? 0);

    return hub_gateway_json($status, $payload);
}

function hub_photo_request_payload(PDO $db, array $asset, array $payload): array
{
    $settings = hub_photo_settings($db);

    return [
        'image_id' => (string)$asset['image_id'],
        'image_internal_path' => hub_photo_asset_container_path($asset),
        'text' => trim((string)($payload['text'] ?? '')),
        'max_tokens' => max(32, min((int)$settings['max_tokens'], (int)($payload['max_tokens'] ?? 256))),
        'real_inference' => hub_photo_parse_bool($payload['real_inference'] ?? false),
    ];
}

function hub_photo_vision_service_for_request(PDO $db, string $clientIp, array $authContext, ?string $serviceKey = null): array
{
    $serviceKey ??= (string)hub_photo_settings($db)['vision_service_key'];
    $service = hub_get_service_by_key($db, $serviceKey);
    if (
        !$service
        || (int)$service['enabled'] !== 1
        || (string)$service['install_status'] !== 'installed'
        || (string)$service['runtime_status'] !== 'running'
    ) {
        return ['response' => hub_gateway_error(503, 'model_not_ready', 'photo vision service is not ready')];
    }
    if (!hub_gateway_service_ip_allowed_after_auth($db, $service, $clientIp, $authContext)) {
        return ['service' => $service, 'response' => hub_gateway_error(403, 'ip_not_allowed', 'client IP is not allowed for this service')];
    }

    return ['service' => $service];
}

function hub_task_api_dispatch(PDO $db, string $mode, array $authContext = []): array
{
    return match ($mode) {
        'task_submit' => hub_api_task_submit($db, $authContext),
        'task_status' => hub_api_task_status($db, $authContext),
        'task_result' => hub_api_task_result($db, $authContext),
        'task_log' => hub_api_task_log($db, $authContext),
        'task_cancel' => hub_api_task_cancel($db, $authContext),
        'task_retry' => hub_api_task_retry($db, $authContext),
        'task_artifacts_ack' => hub_api_task_artifacts_ack($db, $authContext),
        'task_artifact_retention' => hub_api_task_artifact_retention($db, $authContext),
        'artifact' => hub_api_artifact($db, $authContext),
        default => hub_gateway_json(404, ['ok' => false, 'error' => 'unknown mode']),
    };
}

function hub_api_audio_task_submit(PDO $db, array $route, array $authContext): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'audio task submission requires POST');
    }
    $ownerMemberId = (int)($authContext['member_id'] ?? 0);
    if ($ownerMemberId <= 0) {
        return hub_gateway_error(403, 'member_required', 'audio task submission requires an API member');
    }
    $sourceArtifactId = trim((string)($_POST['source_artifact_id'] ?? ''));
    if ($sourceArtifactId !== '' && !hub_audio_task_has_valid_content_length()) {
        return hub_gateway_error(411, 'length_required', 'source artifact requests require Content-Length');
    }
    if (!hub_audio_task_request_size_allowed($route)) {
        return hub_gateway_error(413, 'payload_too_large', 'request body is larger than this service allows');
    }
    try {
        $callbackTargetId = hub_audio_callback_target_id($db, $ownerMemberId, $_POST);
        $taskInput = $_POST;
        unset($taskInput['callback'], $taskInput['callback_target']);
        $input = hub_audio_task_input($taskInput, $route);
        $input = hub_audio_task_resolve_voice_context($db, $input, $route, $ownerMemberId, (int)($authContext['token_id'] ?? 0));
    } catch (InvalidArgumentException $e) {
        if (in_array($e->getMessage(), ['callback_target_not_found', 'callback_target_disabled'], true)) {
            return hub_gateway_error($e->getMessage() === 'callback_target_not_found' ? 404 : 409, $e->getMessage(), 'callback target is unavailable');
        }
        if ($e->getMessage() === 'capability_unavailable') {
            return hub_gateway_error(409, 'capability_unavailable', 'requested audio capability is not available');
        }
        if ($e->getMessage() === 'invalid_request') {
            return hub_gateway_error(400, 'invalid_request', 'audio request does not match the Pack contract');
        }
        if ($e->getMessage() === 'voice_profile_required') {
            return hub_gateway_error(400, 'voice_profile_required', 'clone mode requires one owned managed voice profile');
        }
        if ($e->getMessage() === 'voice_profile_forbidden') {
            return hub_gateway_error(403, 'voice_profile_forbidden', 'voice profile is not available for this member');
        }
        return hub_gateway_error(400, 'forbidden_task_control', 'client task controls are not accepted');
    }

    $uploads = hub_audio_task_uploads();
    $sourceRequired = ($route['source_required'] ?? true) === true;
    if (!$sourceRequired && ($sourceArtifactId !== '' || $uploads !== [])) {
        return hub_gateway_error(400, 'source_not_allowed', 'this Pack job does not accept a source file');
    }
    if ($sourceRequired && (($sourceArtifactId === '' && $uploads === []) || ($sourceArtifactId !== '' && $uploads !== []))) {
        return hub_gateway_error(400, $sourceArtifactId === '' ? 'source_required' : 'source_ambiguous', 'provide exactly one managed source');
    }
    if ($sourceArtifactId !== '') {
        if (!ctype_digit($sourceArtifactId) || (int)$sourceArtifactId <= 0) {
            return hub_gateway_error(400, 'source_artifact_invalid', 'source_artifact_id is invalid');
        }
        try {
            $source = hub_validate_pack_job_source_artifact($db, (int)$sourceArtifactId, $ownerMemberId, $route);
        } catch (RuntimeException) {
            return hub_gateway_error(409, 'source_artifact_invalid', 'source artifact is unavailable');
        }
        if ($source === null) {
            return hub_gateway_error(404, 'source_artifact_not_found', 'source artifact was not found');
        }

        $taskId = hub_enqueue_owned_pack_job($db, $route, $input, $ownerMemberId, (int)($authContext['token_id'] ?? 0), hub_get_client_ip(), [
            'source_artifact_id' => (int)$source['id'],
            'source_task_id' => (int)$source['task_id'],
            'callback_target_id' => $callbackTargetId,
        ]);
        return hub_gateway_json(200, hub_task_submit_response($taskId));
    }

    if (!$sourceRequired) {
        $taskId = hub_enqueue_owned_pack_job($db, $route, $input, $ownerMemberId, (int)($authContext['token_id'] ?? 0), hub_get_client_ip(), [
            'callback_target_id' => $callbackTargetId,
        ]);

        return hub_gateway_json(200, hub_task_submit_response($taskId));
    }

    if (count($uploads) !== 1) {
        return hub_gateway_error(400, 'source_ambiguous', 'provide exactly one managed source');
    }
    $file = $uploads[0];
    if (!hub_audio_task_upload_size_allowed($route, $file)) {
        return hub_gateway_error(413, 'payload_too_large', 'request body is larger than this service allows');
    }
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) ? $extension : 'bin';
    $taskId = hub_stage_owned_pack_job($db, $route, $input, $ownerMemberId, (int)($authContext['token_id'] ?? 0), hub_get_client_ip(), [
        'callback_target_id' => $callbackTargetId,
    ]);
    try {
        $input = hub_get_task($db, $taskId)['input'] ?? [];
        $input['source_upload_path'] = hub_store_task_upload_file($taskId, $file, $extension);
        $input['original_filename'] = basename((string)($file['name'] ?? 'source.' . $extension));
        hub_update_task_input($db, $taskId, $input);
        hub_publish_staged_pack_job($db, $taskId);
    } catch (Throwable $e) {
        $now = hub_now();
        $stmt = $db->prepare(
            "UPDATE tasks SET status = 'failed', error_code = 'staging_failed', error_message = :error_message,
                 finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id AND status = 'staging'"
        );
        $stmt->execute([
            ':error_message' => substr($e->getMessage(), 0, 2048),
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':id' => $taskId,
        ]);
        if ($stmt->rowCount() === 1) {
            hub_apply_task_terminal_retention($db, $taskId, 'failed', $now);
        }
        if ($e->getMessage() === 'task_upload_workspace_exists') {
            return hub_gateway_error(409, 'task_upload_workspace_conflict', 'managed task workspace already exists');
        }
        throw $e;
    }

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_audio_task_has_forbidden_control(array $input): bool
{
    foreach ($input as $key => $value) {
        if (!is_string($key) || hub_audio_task_is_reserved_control_key($key)) {
            return true;
        }
        if (is_array($value) && hub_audio_task_has_forbidden_control($value)) {
            return true;
        }
    }

    return false;
}

function hub_audio_task_is_reserved_control_key(string $key): bool
{
    $key = strtolower($key);

    return in_array($key, ['requested_mode', 'pack_id', 'pack_version', 'job', 'runtime_mode', 'accelerator', 'route_resolved_at', 'entrypoint', 'command', 'script', 'env', 'environment', 'environment_json', 'host_path', 'container_path', 'path', 'input_file', 'source_path', 'source_upload_path', 'workdir', 'working_dir', 'working_directory', 'secret', 'secrets', 'callback', 'callback_target', 'callback_url', 'callback_secret', 'callback_target_id'], true)
        || str_starts_with($key, 'env_')
        || str_starts_with($key, 'environment_')
        || str_starts_with($key, 'secret_')
        || str_starts_with($key, 'callback_');
}

function hub_audio_task_uploads(): array
{
    $uploads = [];
    foreach ($_FILES as $file) {
        if (!is_array($file) || is_array($file['tmp_name'] ?? null)) {
            continue;
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
            return [];
        }
        $uploads[] = $file;
    }

    return $uploads;
}

function hub_audio_task_upload_size_allowed(array $route, array $file): bool
{
    if (!hub_audio_task_request_size_allowed($route)) {
        return false;
    }
    $maxUploadBytes = (int)$route['max_upload_bytes'];
    $declaredSize = $file['size'] ?? null;
    if (!is_int($declaredSize) && !(is_string($declaredSize) && ctype_digit($declaredSize))) {
        return false;
    }
    if ((int)$declaredSize > $maxUploadBytes) {
        return false;
    }
    $actualSize = filesize((string)($file['tmp_name'] ?? ''));

    return $actualSize !== false && $actualSize <= $maxUploadBytes;
}

function hub_audio_task_request_size_allowed(array $route): bool
{
    $maxUploadBytes = (int)($route['max_upload_bytes'] ?? 0);
    if ($maxUploadBytes <= 0) {
        return false;
    }
    $contentLength = trim((string)($_SERVER['CONTENT_LENGTH'] ?? ''));

    return $contentLength === '' || (ctype_digit($contentLength) && (int)$contentLength <= $maxUploadBytes);
}

function hub_audio_task_has_valid_content_length(): bool
{
    return ctype_digit(trim((string)($_SERVER['CONTENT_LENGTH'] ?? '')));
}

function hub_audio_task_input(array $input, array $route): array
{
    if (hub_audio_task_has_forbidden_control($input)) {
        throw new InvalidArgumentException('forbidden_task_control');
    }
    $allowed = array_fill_keys((array)($route['input_fields'] ?? []), true);
    $filtered = [];
    foreach ($input as $key => $value) {
        if ($key === 'source_artifact_id') {
            if (!is_scalar($value)) {
                throw new InvalidArgumentException('forbidden_task_control');
            }
            continue;
        }
        if (!is_string($key) || !isset($allowed[$key]) || !is_scalar($value)) {
            throw new InvalidArgumentException('forbidden_task_control');
        }
        $filtered[$key] = $value;
    }

    return hub_pack_job_normalize_request_input($filtered, $route);
}

function hub_audio_task_resolve_voice_context(PDO $db, array $input, array $route, int $ownerMemberId, int $tokenId): array
{
    $definition = $route['voice_context'] ?? [];
    if (!is_array($definition) || $definition === []) {
        return $input;
    }
    $modeInput = (string)($definition['mode_input'] ?? '');
    $profileInput = (string)($definition['profile_input'] ?? '');
    $designPromptInput = (string)($definition['design_prompt_input'] ?? '');
    $mode = $input[$modeInput] ?? null;
    if ($mode === null) {
        return $input;
    }
    if (!is_string($mode) || $mode === '') {
        throw new InvalidArgumentException('invalid_request');
    }
    if ($mode === ($definition['design_value'] ?? null)) {
        if (array_key_exists($profileInput, $input)) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        return $input;
    }
    if ($mode !== ($definition['clone_value'] ?? null)) {
        throw new InvalidArgumentException('invalid_request');
    }
    if (array_key_exists($designPromptInput, $input)) {
        throw new InvalidArgumentException('invalid_request');
    }
    $profileId = $input[$profileInput] ?? null;
    if (!is_int($profileId) || $profileId < 1) {
        throw new InvalidArgumentException('voice_profile_required');
    }
    $profile = hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId);
    if (!$profile || (int)($profile['owner_member_id'] ?? 0) !== $ownerMemberId
        || (!empty($profile['expires_at']) && (string)$profile['expires_at'] <= hub_now())) {
        throw new InvalidArgumentException('voice_profile_forbidden');
    }
    $path = hub_voice_profile_safe_host_path((string)($profile['reference_audio_path'] ?? ''));
    $sha256 = $path === null ? false : hash_file('sha256', $path);
    if ($path === null || !is_string($sha256) || !hash_equals((string)($profile['reference_audio_sha256'] ?? ''), $sha256)) {
        throw new InvalidArgumentException('voice_profile_forbidden');
    }
    $input['voice_context'] = [
        'mode' => $mode,
        'voice_profile_id' => $profileId,
        'reference_audio_sha256' => $sha256,
        'container_path' => (string)$definition['container_path'],
    ];
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, $tokenId > 0 ? $tokenId : null, 'use', 'clone', [
        'requested_mode' => (string)($route['requested_mode'] ?? ''),
        'text_chars' => function_exists('mb_strlen') ? mb_strlen((string)($input['text'] ?? ''), 'UTF-8') : strlen((string)($input['text'] ?? '')),
    ]);

    return $input;
}

function hub_api_task_submit(PDO $db, array $authContext = []): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_json(405, ['ok' => false, 'error' => 'task_submit requires POST']);
    }

    $taskType = trim((string)($_POST['task_type'] ?? ''));
    if (!hub_is_valid_task_type($taskType)) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unknown task_type']);
    }
    if ((empty($authContext['internal_task']) && $taskType === 'pack_job') || hub_audio_task_has_forbidden_control($_POST)) {
        return hub_gateway_error(400, 'forbidden_task_control', 'client task controls are not accepted');
    }

    $queueName = trim((string)($_POST['queue'] ?? (in_array($taskType, ['structure_parse', 'docparser_parse', 'docparser_repair_translation'], true) ? 'ocr' : 'default')));
    if (!hub_is_valid_task_queue($queueName)) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unknown queue']);
    }

    $priority = max(0, min(100, (int)($_POST['priority'] ?? 0)));
    if ($taskType === 'structure_parse') {
        return hub_api_structure_task_submit($db, $queueName, $priority, $authContext);
    }
    if ($taskType === 'docparser_parse') {
        return hub_api_docparser_task_submit($db, $queueName, $priority, $authContext);
    }
    if ($taskType === 'docparser_repair_translation') {
        return hub_api_docparser_repair_task_submit($db, $queueName, $priority, $authContext);
    }

    $input = $_POST;
    unset($input['task_type'], $input['queue'], $input['priority']);

    $taskId = hub_enqueue_task($db, $taskType, $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null, hub_task_owner_attributes($authContext));

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_gateway_api_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/api.php'));
    if (!str_ends_with($script, '/api.php')) {
        $script = rtrim(str_replace('\\', '/', dirname($script)), '/') . '/api.php';
    }

    return ($https ? 'https' : 'http') . '://' . $host . $script;
}

function hub_task_submit_response(int $taskId): array
{
    return [
        'ok' => true,
        'task_id' => $taskId,
        'status' => 'queued',
    ] + hub_task_response_links($taskId);
}

function hub_task_cached_response(array $task): array
{
    $taskId = (int)$task['id'];

    return [
        'ok' => true,
        'task_id' => $taskId,
        'status' => (string)($task['status'] ?? 'success'),
        'cached' => true,
        'cache_hit_task_id' => $taskId,
        'cache_age_seconds' => (int)($task['cache_age_seconds'] ?? 0),
    ] + hub_task_response_links($taskId);
}

function hub_task_response_links(int $taskId): array
{
    $base = hub_gateway_api_base_url();

    return [
        'status_url' => $base . '?mode=task_status&task_id=' . $taskId,
        'result_url' => $base . '?mode=task_result&task_id=' . $taskId,
        'log_url' => $base . '?mode=task_log&task_id=' . $taskId,
        'cancel_url' => $base . '?mode=task_cancel&task_id=' . $taskId,
        'artifact_url_template' => $base . '?mode=artifact&artifact_id={artifact_id}',
    ];
}

function hub_api_structure_task_submit(PDO $db, string $queueName, int $priority, array $authContext = []): array
{
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'file_required', 'message' => 'file upload is required']);
    }

    $filename = basename((string)($file['name'] ?? 'input.pdf'));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'], true)) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unsupported_file_type']);
    }

    $input = [
        'mode' => preg_match('/^[a-zA-Z0-9_-]+$/', (string)($_POST['mode'] ?? 'structure')) ? (string)($_POST['mode'] ?? 'structure') : 'structure',
        'output_format' => in_array((string)($_POST['output_format'] ?? 'both'), ['markdown', 'json', 'both'], true) ? (string)($_POST['output_format'] ?? 'both') : 'both',
        'real_inference' => '1',
        'original_filename' => $filename,
    ];

    $taskId = hub_enqueue_task($db, 'structure_parse', $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null, hub_task_owner_attributes($authContext));
    $input['input_file'] = hub_store_task_upload_file($taskId, $file, $extension);
    hub_update_task_input($db, $taskId, $input);

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_api_docparser_task_submit(PDO $db, string $queueName, int $priority, array $authContext = []): array
{
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'file_required', 'message' => 'PDF upload is required']);
    }

    $filename = basename((string)($file['name'] ?? 'input.pdf'));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unsupported_file_type', 'message' => 'DocParser PhaseDoc-1A accepts PDF only']);
    }
    if (!hub_file_has_pdf_magic((string)($file['tmp_name'] ?? ''))) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'invalid_pdf_file', 'message' => 'Uploaded file is not a valid PDF']);
    }

    $structureMode = (string)($_POST['structure_mode'] ?? 'structure');
    $translateMode = (string)($_POST['translate_mode'] ?? 'translate');
    $input = [
        'profile' => 'technical_manual',
        'structure_mode' => preg_match('/^[a-zA-Z0-9_-]+$/', $structureMode) ? $structureMode : 'structure',
        'translate_mode' => preg_match('/^[a-zA-Z0-9_-]+$/', $translateMode) ? $translateMode : 'translate',
        'source_language' => (string)($_POST['source_language'] ?? 'auto'),
        'target_language' => (string)($_POST['target_language'] ?? 'zh-TW'),
        'translation_required' => (string)($_POST['translation_required'] ?? '1') !== '0' ? '1' : '0',
        'original_filename' => $filename,
    ];
    if (!empty($authContext['member_id'])) {
        $input['api_member_id'] = (int)$authContext['member_id'];
    }
    if (!empty($authContext['token_id'])) {
        $input['api_token_id'] = (int)$authContext['token_id'];
    }

    $inputSha256 = hash_file('sha256', (string)$file['tmp_name']);
    if ($inputSha256 === false) {
        return hub_gateway_json(500, ['ok' => false, 'error' => 'hash_failed', 'message' => 'Cannot hash uploaded PDF']);
    }
    $cacheVersion = hub_docparser_cache_version($db);
    $input['input_sha256'] = $inputSha256;
    $input['docparser_cache_version'] = $cacheVersion;
    $input['docparser_cache_key'] = hub_docparser_cache_key($inputSha256, $input, $cacheVersion);

    $cachedTask = hub_docparser_find_cached_task($db, $inputSha256, $input);
    if ($cachedTask !== null) {
        hub_add_task_log($db, (int)$cachedTask['id'], 'info', 'docparser_cache_hit age_seconds=' . (int)($cachedTask['cache_age_seconds'] ?? 0));

        return hub_gateway_json(200, hub_task_cached_response($cachedTask));
    }

    $taskId = hub_enqueue_task($db, 'docparser_parse', $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null, hub_task_owner_attributes($authContext));
    $input['input_file'] = hub_store_task_upload_file($taskId, $file, 'pdf');
    hub_update_task_input($db, $taskId, $input);

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_api_docparser_repair_task_submit(PDO $db, string $queueName, int $priority, array $authContext = []): array
{
    $rawTaskId = trim((string)($_POST['task_id'] ?? ''));
    if ($rawTaskId === '' || !ctype_digit($rawTaskId) || (int)$rawTaskId <= 0) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'invalid_task_id']);
    }

    try {
        $blockIds = hub_docparser_parse_repair_block_ids((string)($_POST['block_ids'] ?? ''));
    } catch (InvalidArgumentException) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'invalid_block_ids']);
    }

    $sourceTaskId = (int)$rawTaskId;
    $sourceTask = hub_get_task($db, $sourceTaskId);
    if (!$sourceTask || (string)($sourceTask['task_type'] ?? '') !== 'docparser_parse') {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task_not_found']);
    }
    if (!hub_docparser_repair_allowed_for_auth($sourceTask, $authContext)) {
        return hub_gateway_json(403, ['ok' => false, 'error' => 'task_forbidden']);
    }

    try {
        $docir = hub_docparser_load_registered_docir_artifact($db, $sourceTaskId);
        hub_docparser_assert_repair_blocks_exist($docir, $blockIds);
    } catch (Throwable $e) {
        return hub_gateway_json(409, ['ok' => false, 'error' => $e->getMessage()]);
    }

    $input = [
        'source_task_id' => $sourceTaskId,
        'block_ids' => $blockIds,
    ];
    if (!empty($authContext['member_id'])) {
        $input['api_member_id'] = (int)$authContext['member_id'];
    }
    if (!empty($authContext['token_id'])) {
        $input['api_token_id'] = (int)$authContext['token_id'];
    }

    $attributes = hub_task_owner_attributes($authContext);
    $attributes['source_task_id'] = $sourceTaskId;
    $taskId = hub_enqueue_task($db, 'docparser_repair_translation', $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null, $attributes);

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_docparser_repair_allowed_for_auth(array $sourceTask, array $authContext): bool
{
    if (empty($authContext['member_id'])) {
        return true;
    }

    $sourceMemberId = (int)($sourceTask['input']['api_member_id'] ?? 0);
    return $sourceMemberId > 0 && $sourceMemberId === (int)$authContext['member_id'];
}

function hub_task_owner_attributes(array $authContext): array
{
    $memberId = (int)($authContext['member_id'] ?? 0);
    if ($memberId <= 0) {
        return [];
    }

    return [
        'owner_member_id' => $memberId,
        'owner_token_id' => !empty($authContext['token_id']) ? (int)$authContext['token_id'] : null,
    ];
}

function hub_docparser_assert_repair_blocks_exist(array $docir, array $blockIds): void
{
    $known = [];
    foreach (($docir['blocks'] ?? []) as $block) {
        if (is_array($block) && (string)($block['id'] ?? '') !== '') {
            $known[(string)$block['id']] = true;
        }
    }
    foreach ($blockIds as $blockId) {
        if (!isset($known[$blockId])) {
            throw new RuntimeException('unknown_block_id');
        }
    }
}

function hub_file_has_pdf_magic(string $path): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }

    $magic = file_get_contents($path, false, null, 0, 4);
    return $magic === '%PDF';
}

function hub_store_task_upload_file(int $taskId, array $file, string $extension): string
{
    $dir = HUB_DATA_DIR . '/uploads/tasks/task_' . $taskId;
    if (is_link($dir) || file_exists($dir)) {
        throw new RuntimeException('task_upload_workspace_exists');
    }
    if (!@mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create task upload directory.');
    }

    $path = $dir . '/input.' . $extension;
    $tmpName = (string)$file['tmp_name'];
    $ok = is_uploaded_file($tmpName)
        ? move_uploaded_file($tmpName, $path)
        : copy($tmpName, $path);
    if (!$ok) {
        throw new RuntimeException('Cannot store task upload.');
    }

    return $path;
}

function hub_api_task_status(PDO $db, array $authContext = []): array
{
    $task = hub_api_load_task($db, $authContext);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$task['id'],
        'task_type' => $task['task_type'],
        'queue' => $task['queue_name'],
        'priority' => (int)$task['priority'],
        'status' => $task['status'],
        'progress' => (int)$task['progress'],
        'cancel_requested' => (string)($task['input']['cancel_requested'] ?? '') === '1',
        'error_message' => $task['error_message'],
        'created_at' => $task['created_at'],
        'started_at' => $task['started_at'],
        'finished_at' => $task['finished_at'],
    ]);
}

function hub_api_task_result(PDO $db, array $authContext = []): array
{
    $task = hub_api_load_task($db, $authContext);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    if ($task['status'] !== 'success') {
        return hub_gateway_json(409, ['ok' => false, 'task_id' => (int)$task['id'], 'status' => $task['status']]);
    }

    return hub_gateway_json(200, ['ok' => true, 'task_id' => (int)$task['id'], 'result' => $task['result']]);
}

function hub_api_task_log(PDO $db, array $authContext = []): array
{
    $task = hub_api_load_task($db, $authContext);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$task['id'],
        'logs' => hub_list_task_logs($db, (int)$task['id']),
    ]);
}

function hub_api_task_cancel(PDO $db, array $authContext = []): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_json(405, ['ok' => false, 'error' => 'task_cancel requires POST']);
    }

    $task = hub_api_load_task($db, $authContext);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }

    $taskId = (int)$task['id'];
    if (!hub_cancel_task($db, $taskId)) {
        return hub_gateway_json(409, ['ok' => false, 'task_id' => (int)$task['id'], 'status' => $task['status'], 'error' => 'only queued tasks can be cancelled']);
    }

    $updated = hub_get_task($db, $taskId);
    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => $taskId,
        'status' => (string)($updated['status'] ?? 'cancelled'),
        'cancel_requested' => (string)($updated['input']['cancel_requested'] ?? '') === '1',
    ]);
}

function hub_api_task_retry(PDO $db, array $authContext = []): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_json(405, ['ok' => false, 'error' => 'task_retry requires POST']);
    }

    $task = hub_api_load_task($db, $authContext);
    if (!$task) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'task not found']);
    }
    try {
        $taskId = hub_create_manual_retry($db, (int)$task['id'], $authContext);
    } catch (InvalidArgumentException|RuntimeException $e) {
        if (in_array($e->getMessage(), ['pack_not_installed', 'pack_version_unavailable'], true)) {
            return hub_gateway_error(503, $e->getMessage(), $e->getMessage());
        }
        return hub_gateway_json(409, ['ok' => false, 'error' => $e->getMessage()]);
    }

    return hub_gateway_json(200, hub_task_submit_response($taskId));
}

function hub_api_task_artifacts_ack(PDO $db, array $authContext = []): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'task_artifacts_ack requires POST');
    }
    $taskId = trim((string)($_POST['task_id'] ?? ''));
    $artifactId = trim((string)($_POST['artifact_id'] ?? ''));
    $memberId = (int)($authContext['member_id'] ?? 0);
    if ($memberId <= 0 || !ctype_digit($taskId) || !ctype_digit($artifactId) || (int)$taskId < 1 || (int)$artifactId < 1) {
        return hub_gateway_error(400, 'bad_request', 'task_id and artifact_id are required');
    }
    try {
        if (!hub_ack_task_artifact($db, $memberId, (int)$taskId, (int)$artifactId)) {
            return hub_gateway_error(404, 'artifact_not_found', 'artifact was not found');
        }
    } catch (RuntimeException $e) {
        return hub_gateway_error(409, $e->getMessage(), 'artifact is unavailable');
    }
    $artifact = hub_get_task_artifact($db, (int)$artifactId);

    return hub_gateway_json(200, [
        'ok' => true,
        'task_id' => (int)$taskId,
        'artifact_id' => (int)$artifactId,
        'acknowledged_at' => $artifact['acknowledged_at'] ?? null,
        'expires_at' => $artifact['expires_at'] ?? null,
    ]);
}

function hub_api_task_artifact_retention(PDO $db, array $authContext = []): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'task_artifact_retention requires POST');
    }
    $user = hub_current_user($db);
    if (!is_array($user) || (string)($user['role'] ?? '') !== 'system_admin') {
        return hub_gateway_error(403, 'admin_required', 'administrator session required');
    }
    if (hub_bearer_token_from_request() === '' && !hub_check_csrf(false)) {
        return hub_gateway_error(400, 'csrf_invalid', 'valid CSRF token required');
    }
    $artifactId = trim((string)($_POST['artifact_id'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    if (!ctype_digit($artifactId) || (int)$artifactId < 1 || !in_array($action, ['pin', 'unpin', 'legal_hold', 'release_legal_hold'], true)) {
        return hub_gateway_error(400, 'bad_request', 'artifact_id and valid action are required');
    }
    try {
        $artifact = hub_get_task_artifact($db, (int)$artifactId);
        if (!is_array($artifact)) {
            throw new RuntimeException('artifact_unavailable');
        }
        hub_set_task_artifact_retention_protection(
            $db,
            (int)$artifactId,
            $action === 'pin' ? true : ($action === 'unpin' ? false : !empty($artifact['pinned_at'])),
            $action === 'legal_hold' ? true : ($action === 'release_legal_hold' ? false : !empty($artifact['legal_hold']))
        );
    } catch (RuntimeException $e) {
        return hub_gateway_error(409, $e->getMessage(), 'artifact is unavailable');
    }
    $artifact = hub_get_task_artifact($db, (int)$artifactId);

    return hub_gateway_json(200, ['ok' => true, 'artifact_id' => (int)$artifactId, 'pinned_at' => $artifact['pinned_at'] ?? null, 'legal_hold' => (int)($artifact['legal_hold'] ?? 0)]);
}

function hub_api_artifact(PDO $db, array $authContext = []): array
{
    $artifactId = (int)($_GET['artifact_id'] ?? $_POST['artifact_id'] ?? 0);
    $artifact = $artifactId > 0 ? hub_get_task_artifact($db, $artifactId) : null;
    if (!$artifact) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'artifact not found']);
    }
    $task = hub_get_task($db, (int)$artifact['task_id']);
    if (!$task || !hub_task_access_allowed($db, $task, $authContext)) {
        return hub_gateway_json(404, ['ok' => false, 'error' => 'artifact not found']);
    }
    if (($artifact['state'] ?? '') === 'purged' || !empty($artifact['purged_at'])) {
        return hub_gateway_error(410, 'artifact_purged', 'artifact has been purged');
    }

    $path = hub_artifact_safe_path($artifact['path']);
    if ($path === null) {
        return hub_gateway_json(403, ['ok' => false, 'error' => 'artifact path rejected']);
    }
    $downloadToken = hub_claim_task_artifact_download($db, $artifactId);
    if ($downloadToken === null) {
        return hub_gateway_error(409, 'artifact_not_available', 'artifact is unavailable');
    }
    $response = hub_gateway_stream_file_response($path, (string)$artifact['mime_type'], (string)$artifact['name']);
    if ($response === null) {
        hub_release_task_artifact_download($db, $artifactId, $downloadToken);
        return hub_gateway_json(403, ['ok' => false, 'error' => 'artifact path rejected']);
    }
    $response['stream_artifact_id'] = $artifactId;
    $response['stream_download_token'] = $downloadToken;

    return $response;
}

function hub_api_load_task(PDO $db, array $authContext = []): ?array
{
    $taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
    $task = $taskId > 0 ? hub_get_task($db, $taskId) : null;

    return $task && hub_task_access_allowed($db, $task, $authContext) ? $task : null;
}

function hub_task_access_allowed(PDO $db, array $task, array $authContext): bool
{
    $memberId = (int)($authContext['member_id'] ?? 0);
    if (($task['owner_member_id'] ?? null) !== null) {
        return $memberId > 0 && (int)$task['owner_member_id'] === $memberId;
    }
    if (hub_is_localhost_ip(hub_get_client_ip())) {
        return true;
    }

    $user = hub_current_user($db);
    return is_array($user) && (string)($user['role'] ?? '') === 'system_admin';
}

function hub_gateway_admin_legacy_task_session_allowed(PDO $db, string $mode): bool
{
    if (!in_array($mode, ['task_status', 'task_result', 'task_log', 'artifact', 'task_artifact_retention'], true)) {
        return false;
    }
    $user = hub_current_user($db);
    if (!is_array($user) || (string)($user['role'] ?? '') !== 'system_admin') {
        return false;
    }
    if ($mode === 'task_artifact_retention') {
        return true;
    }
    if ($mode === 'artifact') {
        $artifactId = (int)($_GET['artifact_id'] ?? $_POST['artifact_id'] ?? 0);
        $artifact = $artifactId > 0 ? hub_get_task_artifact($db, $artifactId) : null;
        $task = $artifact ? hub_get_task($db, (int)$artifact['task_id']) : null;
    } else {
        $taskId = (int)($_GET['task_id'] ?? $_POST['task_id'] ?? 0);
        $task = $taskId > 0 ? hub_get_task($db, $taskId) : null;
    }

    return $task !== null && ($task['owner_member_id'] ?? null) === null;
}

function hub_proxy_allowed_response_headers(string $rawHeaders, string $contentType): array
{
    $contentType = trim($contentType);
    if ($contentType === '' || strlen($contentType) > 200 || preg_match('/[\x00-\x1F\x7F]/', $contentType) === 1) {
        $contentType = 'application/octet-stream';
    }
    $headers = ['Content-Type: ' . $contentType];
    $normalized = str_replace("\r\n", "\n", $rawHeaders);
    $blocks = preg_split('/\n\n+/', trim($normalized)) ?: [];
    $final = '';
    foreach (array_reverse($blocks) as $block) {
        if (preg_match('/^HTTP\/\S+\s+\d{3}(?:\s|$)/', $block) === 1) {
            $final = $block;
            break;
        }
    }

    $canonical = [
        'x-3waaihub-model' => 'X-3waAIHub-Model',
        'x-3waaihub-device' => 'X-3waAIHub-Device',
        'x-3waaihub-elapsed-ms' => 'X-3waAIHub-Elapsed-Ms',
        'x-3waaihub-width' => 'X-3waAIHub-Width',
        'x-3waaihub-height' => 'X-3waAIHub-Height',
    ];
    $accepted = [];
    foreach (preg_split('/\n/', $final) ?: [] as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$rawName, $rawValue] = explode(':', $line, 2);
        $name = strtolower(trim($rawName));
        if (!isset($canonical[$name])) {
            continue;
        }
        $value = trim($rawValue, " \t");
        if ($name === 'x-3waaihub-model') {
            $valid = $value !== '' && strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
        } elseif ($name === 'x-3waaihub-device') {
            $valid = in_array($value, ['cuda', 'cpu'], true);
        } else {
            $valid = $value !== '' && strlen($value) <= 20 && ctype_digit($value);
        }
        if ($valid) {
            $accepted[$name] = $value;
        }
    }
    foreach ($canonical as $name => $outputName) {
        if (isset($accepted[$name])) {
            $headers[] = $outputName . ': ' . $accepted[$name];
        }
    }

    return $headers;
}

function hub_proxy_request(string $url, int $timeoutSec = 60, ?string $bodyOverride = null, ?string $contentTypeOverride = null, ?string $methodOverride = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return hub_gateway_json(502, ['ok' => false, 'error' => 'curl unavailable']);
    }

    $method = $methodOverride ?? $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $headers = [];
    $hasUploads = !empty($_FILES);
    if (!$hasUploads && $contentTypeOverride !== null) {
        $headers[] = 'Content-Type: ' . $contentTypeOverride;
    } elseif (!$hasUploads && !empty($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
    ]);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $hasUploads ? hub_proxy_post_fields($_POST, $_FILES) : ($bodyOverride ?? file_get_contents('php://input')));
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => hub_gateway_error(504, 'gateway_timeout', 'service gateway timeout'),
            CURLE_COULDNT_CONNECT => hub_gateway_error(503, 'service_unavailable', 'service is unavailable'),
            default => hub_gateway_error(502, 'proxy_error', 'service proxy error'),
        };
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => hub_proxy_allowed_response_headers($rawHeaders, $contentType), 'body' => $body];
}

function hub_proxy_post_fields(array $post, array $files): array
{
    $fields = $post;
    foreach ($files as $field => $file) {
        if (!is_array($file) || is_array($file['tmp_name'] ?? null)) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)$file['tmp_name'])) {
            continue;
        }
        $fields[$field] = new CURLFile(
            (string)$file['tmp_name'],
            (string)($file['type'] ?? 'application/octet-stream'),
            (string)($file['name'] ?? $field)
        );
    }

    return $fields;
}

function hub_gateway_json(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['Content-Type: application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_gateway_stream_file_response(string $path, string $mimeType, string $downloadName): ?array
{
    $path = hub_artifact_safe_path($path);
    if ($path === null) {
        return null;
    }
    clearstatcache(true, $path);
    $size = filesize($path);
    if ($size === false || $size < 0) {
        return null;
    }
    $mimeType = preg_match('/^[a-z0-9.+-]{1,64}\/[a-z0-9.+-]{1,64}$/i', $mimeType) === 1
        ? strtolower($mimeType)
        : 'application/octet-stream';
    $downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($downloadName)) ?: 'artifact';

    return [
        'status' => 200,
        'headers' => [
            'Content-Type: ' . $mimeType,
            'Content-Length: ' . (int)$size,
            'Content-Disposition: attachment; filename="' . $downloadName . '"',
        ],
        'body' => '',
        'stream_path' => $path,
        'stream_size' => (int)$size,
    ];
}

function hub_gateway_error(int $status, string $errorCode, string $message): array
{
    return hub_gateway_json($status, ['ok' => false, 'error' => $errorCode, 'message' => $message]);
}

function hub_gateway_finish(PDO $db, ?array $service, string $mode, array $response, float $started, string $requestId, array $authContext = [], array $requestContext = []): array
{
    $status = (int)$response['status'];
    $response = hub_gateway_attach_request_id($response, $requestId);
    [$errorCode, $reason] = $status >= 400 ? hub_gateway_response_error($response) : [null, null];
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    hub_log_api_access(
        $db,
        $service,
        $mode,
        $status,
        $status >= 200 && $status < 400,
        $status >= 400 ? $errorCode : null,
        $status >= 400 ? $reason : null,
        $elapsedMs,
        $requestId,
        $authContext,
        hub_gateway_upload_bytes(isset($requestContext['upload_bytes']) ? (int)$requestContext['upload_bytes'] : null),
        hub_gateway_response_output_bytes($response),
        $requestContext
    );

    return $response;
}

function hub_gateway_response_output_bytes(array $response): int
{
    if (is_int($response['stream_size'] ?? null) && $response['stream_size'] >= 0) {
        return $response['stream_size'];
    }

    return strlen((string)($response['body'] ?? ''));
}

function hub_gateway_upload_bytes(?int $providedBytes = null): int
{
    if ($providedBytes !== null) {
        return max(0, $providedBytes);
    }
    $contentLength = trim((string)($_SERVER['CONTENT_LENGTH'] ?? ''));
    if ($contentLength !== '' && ctype_digit($contentLength)) {
        return (int)$contentLength;
    }
    $bytes = 0;
    foreach ($_FILES ?? [] as $file) {
        if (is_array($file) && isset($file['size']) && is_numeric($file['size']) && !is_array($file['size'])) {
            $bytes += (int)$file['size'];
        }
    }

    return $bytes;
}

function hub_gateway_attach_request_id(array $response, string $requestId): array
{
    $response['headers'][] = 'X-3waAIHub-Request-Id: ' . $requestId;
    if ((int)$response['status'] < 400) {
        return $response;
    }

    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (is_array($payload) && !isset($payload['request_id'])) {
        $payload['request_id'] = $requestId;
        $response['body'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    return $response;
}

function hub_gateway_response_error(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    if (is_array($payload)) {
        return [
            is_string($payload['error'] ?? null) ? $payload['error'] : 'proxy_error',
            is_string($payload['message'] ?? null) ? $payload['message'] : null,
        ];
    }

    return ['proxy_error', null];
}

function hub_service_method_allowed(array $service, string $method): bool
{
    $methods = hub_service_gateway_methods($service);
    return $methods === [] || in_array(strtoupper($method), $methods, true);
}

function hub_service_gateway_methods(array $service): array
{
    $packId = (string)($service['pack_id'] ?? '');
    if ($packId === '') {
        return [];
    }
    $pack = hub_get_pack($packId);
    $methods = $pack['manifest']['gateway']['methods'] ?? [];
    if (!is_array($methods)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($method): string => strtoupper((string)$method), $methods)));
}

function hub_service_upload_size_allowed(array $service, string $contentLength): bool
{
    $maxUploadMb = hub_service_gateway_int($service, 'max_upload_mb', 0);
    if ($maxUploadMb <= 0 || trim($contentLength) === '') {
        return true;
    }

    return (float)$contentLength <= $maxUploadMb * 1024 * 1024;
}

function hub_service_gateway_timeout_sec(array $service): int
{
    return max(1, hub_service_gateway_int($service, 'timeout_sec', 60));
}

function hub_service_gateway_int(array $service, string $key, int $default): int
{
    $packId = (string)($service['pack_id'] ?? '');
    if ($packId === '') {
        return $default;
    }
    $pack = hub_get_pack($packId);
    $value = $pack['manifest']['gateway'][$key] ?? null;

    return is_numeric($value) ? (int)$value : $default;
}

function hub_send_gateway_response(array $response): never
{
    $streamPath = is_string($response['stream_path'] ?? null) ? hub_artifact_safe_path($response['stream_path']) : null;
    $streamSize = $response['stream_size'] ?? null;
    $stream = null;
    if ($streamPath !== null && is_int($streamSize) && $streamSize >= 0) {
        clearstatcache(true, $streamPath);
        $stream = @fopen($streamPath, 'rb');
        $stat = $stream === false ? false : fstat($stream);
        if (!is_array($stat) || (int)($stat['size'] ?? -1) !== $streamSize || !flock($stream, LOCK_SH)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $stream = null;
        }
        if (is_resource($stream) && !empty($response['stream_artifact_id']) && is_string($response['stream_download_token'] ?? null)
            && !hub_refresh_task_artifact_download(hub_db(), (int)$response['stream_artifact_id'], (string)$response['stream_download_token'])) {
            flock($stream, LOCK_UN);
            fclose($stream);
            $stream = null;
        }
    }
    if (array_key_exists('stream_path', $response) && !is_resource($stream)) {
        if (!empty($response['stream_artifact_id']) && is_string($response['stream_download_token'] ?? null)) {
            hub_release_task_artifact_download(hub_db(), (int)$response['stream_artifact_id'], (string)$response['stream_download_token']);
        }
        $response = hub_gateway_error(404, 'artifact_not_available', 'artifact is not available');
    }
    http_response_code((int)$response['status']);
    foreach ($response['headers'] as $header) {
        header($header);
    }
    if (is_resource($stream)) {
        try {
            fpassthru($stream);
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
            if (!empty($response['stream_artifact_id']) && is_string($response['stream_download_token'] ?? null)) {
                hub_release_task_artifact_download(hub_db(), (int)$response['stream_artifact_id'], (string)$response['stream_download_token']);
            }
        }
        exit;
    }
    echo $response['body'];
    exit;
}
