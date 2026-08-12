<?php
declare(strict_types=1);

class HubVoiceProfileTaskRetry extends RuntimeException
{
}

function hub_voice_profile_api_dispatch(PDO $db, array $route, array $authContext): ?array
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($method === 'POST' && $contentType === 'application/json') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
        $raw = file_get_contents('php://input', false, null, 0, 262145);
        try {
            $payload = is_string($raw) && strlen($raw) <= 262144
                ? json_decode($raw, true, 32, JSON_THROW_ON_ERROR)
                : null;
        } catch (Throwable) {
            $payload = null;
        }
        if (!is_array($payload) || array_is_list($payload)) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
        $_POST = $payload;
    } else {
        $payload = $method === 'GET' ? $_GET : $_POST;
    }
    $operation = $payload['operation'] ?? null;
    if ($operation === null) {
        return null;
    }
    if (!is_string($operation)) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    if ($operation === 'synthesize') {
        unset($payload['operation']);
        $_POST = $payload;
        return null;
    }

    return match ($operation) {
        'profile_prepare' => hub_voice_profile_api_prepare($db, $route, $authContext, $payload),
        'profile_status' => hub_voice_profile_api_status($db, $authContext, $payload),
        'profile_confirm' => hub_voice_profile_api_confirm($db, $authContext, $payload),
        'profile_delete' => hub_voice_profile_api_delete($db, $authContext, $payload),
        default => hub_gateway_error(400, 'invalid_request', 'invalid request'),
    };
}

function hub_voice_profile_task_for_member(PDO $db, int $taskId, int $memberId): ?array
{
    $task = $taskId > 0 ? hub_get_task($db, $taskId) : null;
    if (
        $task === null
        || (string)($task['task_type'] ?? '') !== 'voice_profile_prepare'
        || (int)($task['owner_member_id'] ?? 0) !== $memberId
        || array_keys((array)($task['input'] ?? [])) !== ['voice_profile_id']
        || (int)($task['input']['voice_profile_id'] ?? 0) < 1
    ) {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND source_task_id = :source_task_id AND owner_member_id = :owner_member_id');
    $stmt->execute([
        ':id' => (int)$task['input']['voice_profile_id'],
        ':source_task_id' => $taskId,
        ':owner_member_id' => $memberId,
    ]);
    $profile = $stmt->fetch();
    if (!$profile) {
        return null;
    }

    $task['voice_profile'] = $profile;
    return $task;
}

function hub_voice_profile_task_status_payload(PDO $db, array $task, array $profile, bool $includeDraft): array
{
    $expiresAt = trim((string)($profile['expires_at'] ?? ''));
    $profileStatus = !empty($profile['deleted_at'])
        ? 'deleted'
        : ($expiresAt !== '' && (strtotime($expiresAt) ?: PHP_INT_MAX) <= time() ? 'expired' : 'active');
    $tombstone = $profileStatus !== 'active';
    $confirmed = !$tombstone && trim((string)($profile['prompt_text_confirmed_at'] ?? '')) !== '';
    $transcriptionError = $tombstone ? '' : trim((string)($profile['transcription_error'] ?? ''));
    $payload = [
        'ok' => true,
        'task_status' => (string)($task['status'] ?? ''),
        'profile_status' => $profileStatus,
        'transcription_status' => $tombstone ? 'failed' : (string)($profile['transcription_status'] ?? 'pending'),
        'transcription_error' => $transcriptionError === ''
            ? null
            : hub_voice_profile_transcription_error_code($transcriptionError),
        'transcript_confirmed' => $confirmed,
        'prompt_text_confirmed_at' => $confirmed ? (string)$profile['prompt_text_confirmed_at'] : null,
        'profile_name' => $tombstone ? ucfirst($profileStatus) . ' voice profile' : substr((string)($profile['name'] ?? ''), 0, 120),
        'language' => $tombstone || trim((string)($profile['language'] ?? '')) === '' ? null : substr((string)$profile['language'], 0, 64),
        'consent_type' => substr((string)($profile['consent_type'] ?? ''), 0, 32),
        'reference_audio_sha256' => $tombstone ? '' : (string)($profile['reference_audio_sha256'] ?? ''),
        'created_at' => (string)($profile['created_at'] ?? ''),
        'updated_at' => (string)($profile['updated_at'] ?? ''),
    ];
    $promptText = trim((string)($profile['prompt_text'] ?? ''));
    if (!$tombstone && $includeDraft && !$confirmed && $promptText !== '') {
        $payload['prompt_text'] = substr($promptText, 0, 20000);
    }
    if (!$tombstone) {
        $validation = json_decode((string)($profile['transcript_validation_json'] ?? ''), true);
        if (is_array($validation) && is_array($validation['validation'] ?? null)) {
            $payload['validation'] = $validation['validation'] + [
                'normalizer' => (string)($validation['normalizer'] ?? HUB_VOICE_TRANSCRIPT_NORMALIZER_VERSION),
            ];
            if ($includeDraft && !$confirmed) {
                if (is_array($validation['transcript'] ?? null)) {
                    $payload['transcript'] = [
                        'raw' => substr((string)($validation['transcript']['raw'] ?? ''), 0, 20000),
                        'normalized' => substr((string)($validation['transcript']['normalized'] ?? ''), 0, 20000),
                    ];
                }
                $expected = $validation['expected_text'] ?? null;
                $payload['expected_text'] = is_array($expected)
                    ? ['raw' => substr((string)($expected['raw'] ?? ''), 0, 20000), 'normalized' => substr((string)($expected['normalized'] ?? ''), 0, 20000)]
                    : null;
            }
        }
    }

    return $payload;
}

function hub_run_voice_profile_prepare_task(PDO $db, array $task, ?callable $transcribe = null): void
{
    $taskId = (int)($task['id'] ?? 0);
    $memberId = (int)($task['owner_member_id'] ?? 0);
    $linked = hub_voice_profile_task_for_member($db, $taskId, $memberId);
    $lockToken = (string)($task['lock_token'] ?? '');
    if (
        $linked === null
        || (string)($task['task_type'] ?? '') !== 'voice_profile_prepare'
        || (string)($task['status'] ?? '') !== 'running'
        || (string)($linked['status'] ?? '') !== 'running'
        || $lockToken === ''
        || !hash_equals((string)($linked['lock_token'] ?? ''), $lockToken)
    ) {
        throw new RuntimeException('voice_profile_prepare_invalid');
    }
    $profile = (array)$linked['voice_profile'];
    if (!empty($profile['deleted_at'])) {
        throw new RuntimeException('voice_profile_unavailable');
    }

    hub_add_task_log($db, $taskId, 'info', 'voice_profile_prepare started');
    if (
        (string)($task['requested_mode'] ?? '') === 'voice_generate_gpt_sovits'
        && ($profile['reference_contract'] ?? 'generic') !== 'gpt_sovits_v1'
    ) {
        $profile = hub_promote_gpt_sovits_reference($db, $task, $profile);
    }
    $promptText = trim((string)($profile['prompt_text'] ?? ''));
    if ($promptText === '') {
        $transcriptionStatus = (string)($profile['transcription_status'] ?? '');
        if ($transcriptionStatus === 'failed') {
            hub_retry_voice_profile_transcription($db, (int)$profile['id'], $memberId, $transcribe);
        } elseif ($transcriptionStatus === 'pending') {
            hub_run_voice_profile_transcription($db, $profile, $memberId, $transcribe);
        }
        if (in_array($transcriptionStatus, ['failed', 'pending'], true)) {
            $profile = hub_get_voice_profile($db, (int)$profile['id']) ?? throw new RuntimeException('voice_profile_unavailable');
        }
    }

    $promptText = trim((string)($profile['prompt_text'] ?? ''));
    $result = [
        'kind' => 'voice_profile_prepare',
        'transcription_status' => (string)($profile['transcription_status'] ?? 'pending'),
        'transcript_confirmed' => trim((string)($profile['prompt_text_confirmed_at'] ?? '')) !== '',
        'text_chars' => function_exists('mb_strlen') ? mb_strlen($promptText, 'UTF-8') : strlen($promptText),
        'prompt_text_sha256' => hash('sha256', $promptText),
    ];
    hub_add_task_log($db, $taskId, 'info', 'voice_profile_prepare finished transcription_status=' . $result['transcription_status']);
    hub_finish_voice_profile_prepare_task($db, $task, 'success', $result);
}

function hub_finish_voice_profile_prepare_task(PDO $db, array $task, string $status, array $result = [], ?string $errorMessage = null): void
{
    if (!in_array($status, ['success', 'failed', 'cancelled'], true)) {
        throw new InvalidArgumentException('voice_profile_terminal_invalid');
    }
    $taskId = (int)($task['id'] ?? 0);
    $memberId = (int)($task['owner_member_id'] ?? 0);
    $profileId = (int)($task['input']['voice_profile_id'] ?? 0);
    $lockToken = (string)($task['lock_token'] ?? '');
    if ($taskId < 1 || $memberId < 1 || $profileId < 1 || $lockToken === '') {
        throw new RuntimeException('voice_profile_task_fence_lost');
    }
    $resultJson = $status === 'success'
        ? json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : null;
    if ($status === 'success' && $resultJson === false) {
        throw new RuntimeException('voice_profile_result_encode_failed');
    }

    $terminalTransactionStarted = false;
    $terminalUpdated = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $terminalTransactionStarted = true;
        $now = hub_now();
        $stmt = $db->prepare(
            "UPDATE tasks
             SET status = :status, progress = 100, result_json = :result_json,
                 error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id AND task_type = 'voice_profile_prepare'
               AND status = 'running' AND lock_token = :lock_token
               AND EXISTS (
                   SELECT 1 FROM voice_profiles
                   WHERE id = :voice_profile_id AND source_task_id = :source_task_id
                     AND owner_member_id = :owner_member_id
               )"
        );
        $stmt->execute([
            ':status' => $status,
            ':result_json' => $resultJson,
            ':error_message' => $errorMessage === null ? null : substr($errorMessage, 0, 2048),
            ':finished_at' => $now,
            ':updated_at' => $now,
            ':id' => $taskId,
            ':lock_token' => $lockToken,
            ':voice_profile_id' => $profileId,
            ':source_task_id' => $taskId,
            ':owner_member_id' => $memberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('voice_profile_task_fence_lost');
        }
        $terminalUpdated = true;
        hub_apply_task_terminal_retention($db, $taskId, $status, $now);
        hub_release_task_artifact_holds($db, $taskId);
        hub_enqueue_task_callback_delivery($db, $taskId);
        $db->exec('COMMIT');
        $terminalTransactionStarted = false;
    } catch (Throwable $e) {
        if ($terminalTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $terminalTransactionStarted = false;
        }
        if ($terminalUpdated) {
            $requeue = $db->prepare(
                "UPDATE tasks
                 SET status = 'queued', progress = 0, result_json = NULL, error_message = NULL,
                     finished_at = NULL, started_at = NULL, lock_token = NULL, updated_at = :updated_at
                 WHERE id = :id AND task_type = 'voice_profile_prepare'
                   AND status = 'running' AND lock_token = :lock_token
                   AND EXISTS (
                       SELECT 1 FROM voice_profiles
                       WHERE id = :voice_profile_id AND source_task_id = :source_task_id
                         AND owner_member_id = :owner_member_id
                   )"
            );
            $requeue->execute([
                ':updated_at' => hub_now(),
                ':id' => $taskId,
                ':lock_token' => $lockToken,
                ':voice_profile_id' => $profileId,
                ':source_task_id' => $taskId,
                ':owner_member_id' => $memberId,
            ]);
            if ($requeue->rowCount() === 1) {
                throw new HubVoiceProfileTaskRetry('voice_profile_terminal_retry', 0, $e);
            }
        }
        throw $e;
    }
}

function hub_cancel_superseded_voice_profile_prepare_task(PDO $db, array $task): void
{
    $taskId = (int)($task['id'] ?? 0);
    $now = hub_now();
    $stmt = $db->prepare(
        "UPDATE tasks
         SET status = 'cancelled', progress = 100, error_message = 'superseded',
             finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id AND task_type = 'voice_profile_prepare'
           AND status IN ('staging', 'queued') AND lock_token IS NULL"
    );
    $stmt->execute([':finished_at' => $now, ':updated_at' => $now, ':id' => $taskId]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('voice_profile_predecessor_conflict');
    }
    hub_apply_task_terminal_retention($db, $taskId, 'cancelled', $now);
    hub_release_task_artifact_holds($db, $taskId);
    hub_enqueue_task_callback_delivery($db, $taskId);
}

function hub_voice_profile_api_prepare(PDO $db, array $route, array $authContext, array $payload): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'profile_prepare requires multipart POST');
    }
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($contentType !== 'multipart/form-data') {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    if (
        array_diff(array_keys($payload), ['operation', 'profile_name', 'consent_type', 'prompt_text', 'expected_text', 'transcript_confirmed', 'language', 'callback_target', 'expires_in_seconds']) !== []
        || count($_FILES) !== 1
        || !is_array($_FILES['reference_wav'] ?? null)
    ) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    foreach ($payload as $value) {
        if (!is_string($value) && !is_bool($value)) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
    }
    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
        if (is_array($_FILES['reference_wav'][$key] ?? null)) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
    }

    $memberId = (int)($authContext['member_id'] ?? 0);
    $tokenId = (int)($authContext['token_id'] ?? 0);
    $profileName = trim((string)($payload['profile_name'] ?? ''));
    $promptText = trim((string)($payload['prompt_text'] ?? ''));
    if (array_key_exists('expected_text', $payload) && !is_string($payload['expected_text'])) {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
    }
    $expectedText = array_key_exists('expected_text', $payload) ? $payload['expected_text'] : null;
    $language = trim((string)($payload['language'] ?? ''));
    if ($expectedText === '' || ($expectedText !== null && preg_match('//u', $expectedText) !== 1)) {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
    }
    if ($memberId < 1 || $profileName === '' || strlen($profileName) > 120 || strlen($promptText) > 20000 || ($expectedText !== null && strlen($expectedText) > 20000) || strlen($language) > 64) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    $confirmed = false;
    if (array_key_exists('transcript_confirmed', $payload)) {
        if (in_array($payload['transcript_confirmed'], [true, 'true', '1'], true)) {
            $confirmed = true;
        } elseif (!in_array($payload['transcript_confirmed'], [false, 'false', '0'], true)) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
    }
    if ($confirmed && $promptText === '') {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
    }
    $isGptSoVits = (string)($route['requested_mode'] ?? '') === 'voice_generate_gpt_sovits';
    if ($isGptSoVits && (array_key_exists('prompt_text', $payload) || array_key_exists('transcript_confirmed', $payload))) {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'GPT-SoVITS confirms the derived ASR draft');
    }
    $expiresAt = null;
    if (array_key_exists('expires_in_seconds', $payload)) {
        $expiresIn = $payload['expires_in_seconds'];
        if (!is_string($expiresIn) || preg_match('/^[1-9][0-9]*$/', $expiresIn) !== 1) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
        $expiresInSeconds = (int)$expiresIn;
        if ($expiresInSeconds < 300 || $expiresInSeconds > 86400) {
            return hub_gateway_error(400, 'invalid_request', 'invalid request');
        }
        $expiresAt = hub_retention_deadline($expiresInSeconds);
    }

    try {
        $consentType = hub_valid_voice_profile_consent((string)($payload['consent_type'] ?? ''));
        $callbackTargetId = hub_pack_job_task_callback_target_id($db, $memberId, array_intersect_key($payload, ['callback_target' => true]));
        $moveFile = defined('HUB_TESTING') && HUB_TESTING
            ? static fn (string $from, string $to): bool => copy($from, $to)
            : null;
        $taskId = 0;
        hub_create_uploaded_voice_profile_internal($db, $memberId, (array)$_FILES['reference_wav'], [
            'name' => $profileName,
            'consent_type' => $consentType,
            'prompt_text' => $promptText,
            'expected_text' => $expectedText,
            'language' => $language,
            'transcript_confirmed' => $confirmed,
            'expires_at' => $expiresAt,
        ], $moveFile, null, [
            'defer_transcription' => true,
            'allow_cache' => !$isGptSoVits && $expiresAt === null && !hub_cluster_node_token_is_current($db, $tokenId),
        ], static function (array $createdState) use (
            $db,
            $memberId,
            $tokenId,
            $route,
            $callbackTargetId,
            $confirmed,
            $promptText,
            $expectedText,
            &$taskId
        ): void {
            $profile = (array)$createdState['profile'];
            if ($confirmed && $expectedText === null) {
                $profile = hub_confirm_voice_profile_prompt_in_transaction($db, (int)$profile['id'], $memberId, $promptText);
            }
            $previousTaskId = (int)($createdState['previous_source_task_id'] ?? 0);
            if (!empty($createdState['draft_changed']) && $previousTaskId > 0) {
                $previous = hub_get_task($db, $previousTaskId);
                if (
                    $previous !== null
                    && (string)($previous['task_type'] ?? '') === 'voice_profile_prepare'
                    && (int)($previous['owner_member_id'] ?? 0) === $memberId
                    && (int)($previous['input']['voice_profile_id'] ?? 0) === (int)$profile['id']
                ) {
                    if ((string)($previous['status'] ?? '') === 'running') {
                        throw new RuntimeException('voice_profile_predecessor_running');
                    }
                    if (in_array((string)($previous['status'] ?? ''), ['staging', 'queued'], true)) {
                        hub_cancel_superseded_voice_profile_prepare_task($db, $previous);
                    }
                }
            }

            $profileStmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
            $profileStmt->execute([':id' => (int)$profile['id'], ':owner_member_id' => $memberId]);
            $profile = $profileStmt->fetch() ?: throw new RuntimeException('voice_profile_missing');
            $existing = hub_voice_profile_task_for_member($db, (int)($profile['source_task_id'] ?? 0), $memberId);
            if ($existing !== null && in_array((string)($existing['status'] ?? ''), ['staging', 'queued', 'running', 'success'], true)) {
                if ((int)($existing['callback_target_id'] ?? 0) !== (int)$callbackTargetId) {
                    throw new RuntimeException('voice_profile_callback_conflict');
                }
                $taskId = (int)$existing['id'];
                return;
            }

            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => (int)$profile['id']], null, hub_get_client_ip(), [
                'owner_member_id' => $memberId,
                'owner_token_id' => $tokenId > 0 ? $tokenId : null,
                'requested_mode' => (string)($route['requested_mode'] ?? 'voice_generate'),
                'callback_target_id' => $callbackTargetId,
                'status' => 'staging',
            ]);
            $stmt = $db->prepare('UPDATE voice_profiles SET source_task_id = :source_task_id, updated_at = :updated_at WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
            $stmt->execute([
                ':source_task_id' => $taskId,
                ':updated_at' => hub_now(),
                ':id' => (int)$profile['id'],
                ':owner_member_id' => $memberId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('voice_profile_link_failed');
            }
            $publish = $db->prepare("UPDATE tasks SET status = 'queued', updated_at = :updated_at WHERE id = :id AND task_type = 'voice_profile_prepare' AND status = 'staging'");
            $publish->execute([':updated_at' => hub_now(), ':id' => $taskId]);
            if ($publish->rowCount() !== 1) {
                throw new RuntimeException('voice_profile_task_publish_failed');
            }
        });
        if ($taskId < 1) {
            throw new RuntimeException('voice_profile_task_missing');
        }

        return hub_gateway_json(200, hub_task_submit_response($taskId));
    } catch (InvalidArgumentException $e) {
        if (in_array($e->getMessage(), ['callback_target_not_found', 'callback_target_disabled'], true)) {
            return hub_gateway_error($e->getMessage() === 'callback_target_not_found' ? 404 : 409, $e->getMessage(), 'callback target is unavailable');
        }
        if (in_array($e->getMessage(), ['voice_profile_upload_failed', 'voice_profile_file_required', 'voice_profile_wav_size_invalid', 'voice_profile_wav_invalid'], true)) {
            return hub_gateway_error(400, 'voice_profile_wav_invalid', 'voice profile WAV is invalid');
        }
        if ($e->getMessage() === 'voice_profile_transcript_invalid') {
            return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
        }
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'voice_profile_predecessor_running') {
            return hub_gateway_error(409, 'voice_profile_prepare_conflict', 'voice profile preparation is already running');
        }
        if ($e->getMessage() === 'voice_profile_callback_conflict') {
            return hub_gateway_error(409, 'voice_profile_callback_conflict', 'voice profile callback target conflicts with the existing task');
        }
        return hub_gateway_error(500, 'voice_profile_prepare_failed', 'voice profile preparation failed');
    } catch (Throwable) {
        return hub_gateway_error(500, 'voice_profile_prepare_failed', 'voice profile preparation failed');
    }
}

function hub_voice_profile_api_status(PDO $db, array $authContext, array $payload): array
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'POST'], true)) {
        return hub_gateway_error(405, 'method_not_allowed', 'profile_status requires GET or POST');
    }
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if ($method === 'POST' && !in_array($contentType, ['application/x-www-form-urlencoded', 'application/json'], true)) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    if (array_diff(array_keys($payload), ['mode', 'operation', 'voice_profile_task_id']) !== []) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    $rawTaskId = $payload['voice_profile_task_id'] ?? null;
    if (!is_string($rawTaskId) && !is_int($rawTaskId)) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    $rawTaskId = (string)$rawTaskId;
    if (preg_match('/^[1-9][0-9]*$/', $rawTaskId) !== 1 || strlen($rawTaskId) > 18) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }

    $taskId = (int)$rawTaskId;
    $memberId = (int)($authContext['member_id'] ?? 0);
    $task = hub_voice_profile_task_for_member($db, $taskId, $memberId);
    if ($task === null) {
        $candidate = hub_get_task($db, $taskId);
        if ($candidate !== null && (string)($candidate['task_type'] ?? '') === 'voice_profile_prepare' && (int)($candidate['owner_member_id'] ?? 0) !== $memberId) {
            return hub_gateway_error(403, 'voice_profile_forbidden', 'voice profile is not available for this member');
        }
        return hub_gateway_error(404, 'voice_profile_not_found', 'voice profile task was not found');
    }

    return hub_gateway_json(200, hub_voice_profile_task_status_payload($db, $task, (array)$task['voice_profile'], true));
}

function hub_voice_profile_api_confirm(PDO $db, array $authContext, array $payload): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'profile_confirm requires POST');
    }
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if (!in_array($contentType, ['application/x-www-form-urlencoded', 'application/json'], true)
        || array_diff(array_keys($payload), ['operation', 'voice_profile_task_id', 'prompt_text']) !== []) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    $rawTaskId = $payload['voice_profile_task_id'] ?? null;
    $promptText = $payload['prompt_text'] ?? null;
    if (!is_string($rawTaskId) && !is_int($rawTaskId)) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    if (!is_string($promptText)) {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
    }
    $rawTaskId = (string)$rawTaskId;
    if (preg_match('/^[1-9][0-9]*$/', $rawTaskId) !== 1 || strlen($rawTaskId) > 18) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    if (!hub_voice_profile_confirmation_text_is_valid($promptText)) {
        return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
    }

    $taskId = (int)$rawTaskId;
    $memberId = (int)($authContext['member_id'] ?? 0);
    $task = hub_voice_profile_task_for_member($db, $taskId, $memberId);
    if ($task === null) {
        $candidate = hub_get_task($db, $taskId);
        if ($candidate !== null && (string)($candidate['task_type'] ?? '') === 'voice_profile_prepare' && (int)($candidate['owner_member_id'] ?? 0) !== $memberId) {
            return hub_gateway_error(403, 'voice_profile_forbidden', 'voice profile is not available for this member');
        }
        return hub_gateway_error(404, 'voice_profile_not_found', 'voice profile task was not found');
    }
    $profile = (array)$task['voice_profile'];
    $status = hub_voice_profile_task_status_payload($db, $task, $profile, false);
    if (($status['profile_status'] ?? '') !== 'active') {
        return hub_gateway_error(410, 'voice_profile_unavailable', 'voice profile is unavailable');
    }
    if ((string)($task['status'] ?? '') !== 'success') {
        return hub_gateway_error(409, 'voice_profile_prepare_incomplete', 'voice profile preparation is incomplete');
    }

    try {
        $profile = hub_confirm_voice_profile_prompt($db, (int)$profile['id'], $memberId, $promptText);
    } catch (InvalidArgumentException $e) {
        if ($e->getMessage() === 'voice_profile_unavailable') {
            return hub_gateway_error(410, 'voice_profile_unavailable', 'voice profile is unavailable');
        }
        if ($e->getMessage() === 'voice_profile_transcript_invalid') {
            return hub_gateway_error(400, 'voice_profile_transcript_invalid', 'voice profile transcript is invalid');
        }
        return hub_gateway_error(409, 'voice_profile_confirm_failed', 'voice profile confirmation failed');
    } catch (Throwable) {
        return hub_gateway_error(409, 'voice_profile_confirm_failed', 'voice profile confirmation failed');
    }

    $response = hub_voice_profile_task_status_payload($db, $task, $profile, false);
    $response['voice_profile_task_id'] = $rawTaskId;
    $response['prompt_text_sha256'] = hash('sha256', (string)$profile['prompt_text']);

    return hub_gateway_json(200, $response);
}

function hub_voice_profile_api_delete(PDO $db, array $authContext, array $payload): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'profile_delete requires POST');
    }
    $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
    if (!in_array($contentType, ['application/x-www-form-urlencoded', 'application/json'], true)
        || array_diff(array_keys($payload), ['operation', 'voice_profile_task_id']) !== []) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }
    $rawTaskId = $payload['voice_profile_task_id'] ?? null;
    if ((!is_string($rawTaskId) && !is_int($rawTaskId)) || preg_match('/^[1-9][0-9]*$/', (string)$rawTaskId) !== 1 || strlen((string)$rawTaskId) > 18) {
        return hub_gateway_error(400, 'invalid_request', 'invalid request');
    }

    $taskId = (int)$rawTaskId;
    $memberId = (int)($authContext['member_id'] ?? 0);
    $task = hub_voice_profile_task_for_member($db, $taskId, $memberId);
    if ($task === null) {
        $candidate = hub_get_task($db, $taskId);
        if ($candidate !== null && (string)($candidate['task_type'] ?? '') === 'voice_profile_prepare' && (int)($candidate['owner_member_id'] ?? 0) !== $memberId) {
            return hub_gateway_error(403, 'voice_profile_forbidden', 'voice profile is not available for this member');
        }
        return hub_gateway_error(404, 'voice_profile_not_found', 'voice profile task was not found');
    }

    $profile = (array)$task['voice_profile'];
    try {
        $deleted = hub_soft_delete_voice_profile($db, (int)$profile['id'], $memberId, true);
    } catch (Throwable) {
        return hub_gateway_error(500, 'voice_profile_delete_failed', 'voice profile deletion failed');
    }
    if (!empty($deleted['audio_cleanup_failed'])) {
        return hub_gateway_error(500, 'voice_profile_delete_failed', 'voice profile deletion failed');
    }

    $task = hub_voice_profile_task_for_member($db, $taskId, $memberId) ?? throw new RuntimeException('voice_profile_missing');
    return hub_gateway_json(200, hub_voice_profile_task_status_payload($db, $task, (array)$task['voice_profile'], false));
}
