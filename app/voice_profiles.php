<?php
declare(strict_types=1);

function hub_voice_profile_storage_dir(): string
{
    if (defined('HUB_TESTING') && HUB_TESTING) {
        static $testDir = null;
        if ($testDir === null) {
            $testDir = sys_get_temp_dir() . '/3waaihub_test_voice_profiles_' . bin2hex(random_bytes(16));
            if (!mkdir($testDir, 0700)) {
                throw new RuntimeException('Cannot create test voice profile directory.');
            }
        }

        return $testDir;
    }

    $dir = HUB_DATA_DIR . '/uploads/voice_profiles';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile directory.');
    }

    return $dir;
}

function hub_normalize_voice_profile_ref(string|int $value): int
{
    $value = trim((string)$value);
    if (preg_match('/^(?:voice_profile_|voice_asset_)?([1-9][0-9]*)$/', $value, $matches) !== 1) {
        throw new InvalidArgumentException('voice_profile_required');
    }

    return (int)$matches[1];
}

function hub_voice_profile_safe_host_path(string $path): ?string
{
    $root = realpath(hub_voice_profile_storage_dir());
    $real = realpath($path);
    if ($root === false || $real === false || !is_file($real)) {
        return null;
    }

    return str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
}

function hub_voice_profile_container_path(array $profile): string
{
    $root = realpath(hub_voice_profile_storage_dir());
    $real = hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']);
    if ($root === false || $real === null) {
        throw new RuntimeException('Invalid voice profile audio path.');
    }

    $relative = ltrim(substr($real, strlen($root)), DIRECTORY_SEPARATOR);
    return '/data/voice_profiles/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
}

function hub_find_active_voice_profile_by_owner_sha(PDO $db, int $ownerMemberId, string $sha256): ?array
{
    $sha256 = strtolower(trim($sha256));
    if ($ownerMemberId < 1 || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT * FROM voice_profiles
         WHERE owner_member_id = :owner_member_id
           AND reference_audio_sha256 = :reference_audio_sha256
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([':owner_member_id' => $ownerMemberId, ':reference_audio_sha256' => $sha256]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_validate_voice_profile_wav(array $upload): array
{
    if (!isset($upload['error']) || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('voice_profile_upload_failed');
    }
    $tmpName = $upload['tmp_name'] ?? null;
    if (!is_string($tmpName) || trim($tmpName) === '' || !is_file($tmpName)) {
        throw new InvalidArgumentException('voice_profile_file_required');
    }

    $size = filesize($tmpName);
    if ($size === false || $size < 1 || $size > 100 * 1024 * 1024) {
        throw new InvalidArgumentException('voice_profile_wav_size_invalid');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    if (!in_array($mime, ['audio/wav', 'audio/x-wav', 'audio/wave'], true)) {
        throw new InvalidArgumentException('voice_profile_wav_invalid');
    }
    $header = file_get_contents($tmpName, false, null, 0, 12);
    if ($header === false || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
        throw new InvalidArgumentException('voice_profile_wav_invalid');
    }
    $sha256 = hash_file('sha256', $tmpName);
    if ($sha256 === false) {
        throw new RuntimeException('voice_profile_hash_failed');
    }

    return ['tmp_name' => $tmpName, 'mime' => $mime, 'size' => $size, 'sha256' => $sha256];
}

function hub_voice_profile_transcription_error_code(mixed $error): string
{
    return trim((string)$error) === 'asr_unavailable' ? 'asr_unavailable' : 'asr_failed';
}

function hub_voice_profile_transcription_lease_seconds(PDO $db): int
{
    $service = hub_get_service_by_mode($db, 'asr');
    // ponytail: timeout+30s lease (300s fallback) avoids infinite pending; durable worker leases can replace it later.
    return $service && trim((string)($service['internal_url'] ?? '')) !== ''
        ? hub_service_gateway_timeout_sec($service) + 30
        : 300;
}

function hub_voice_profile_transcription_is_stale(PDO $db, array $profile): bool
{
    $startedAt = strtotime((string)($profile['transcription_started_at'] ?? ''));

    return $startedAt === false || time() - $startedAt >= hub_voice_profile_transcription_lease_seconds($db);
}

function hub_claim_voice_profile_transcription(PDO $db, int $profileId): array
{
    $now = hub_now();
    $leaseToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare('UPDATE voice_profiles SET transcription_status = :transcription_status, transcription_error = NULL, transcription_started_at = :transcription_started_at, transcription_lease_token = :transcription_lease_token, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([
        ':transcription_status' => 'pending',
        ':transcription_started_at' => $now,
        ':transcription_lease_token' => $leaseToken,
        ':updated_at' => $now,
        ':id' => $profileId,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('voice_profile_missing');
    }

    return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
}

function hub_voice_profile_lost_lease_response(PDO $db, int $profileId, array $profile): array
{
    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_lost_lease',
            'message' => 'Transcription result was superseded',
        ],
    ];
}

function hub_voice_profile_save_failure_response(PDO $db, int $profileId, array $profile): array
{
    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_save_failed',
            'message' => 'Transcription result could not be saved',
        ],
    ];
}

function hub_finalize_voice_profile_transcription(PDO $db, int $profileId, int $ownerMemberId, string $sql, array $parameters, array $auditDetails): string
{
    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $stmt = $db->prepare($sql);
        $stmt->execute($parameters);
        if ($stmt->rowCount() !== 1) {
            $db->exec('COMMIT');
            $transactionStarted = false;
            return 'lost';
        }
        hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'transcribe', null, $auditDetails);
        $db->exec('COMMIT');
        $transactionStarted = false;

        return 'applied';
    } catch (Throwable) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }

        return 'error';
    }
}

function hub_cleanup_stale_voice_profile_staging(PDO $db): void
{
    $leaseSeconds = hub_voice_profile_transcription_lease_seconds($db);
    $dir = hub_voice_profile_storage_dir();
    foreach (glob($dir . '/voice_profile_stage_*.wav') ?: [] as $path) {
        if (
            preg_match('/^voice_profile_stage_[1-9][0-9]*_[a-f0-9]{32}\.wav$/', basename($path)) !== 1
            || !is_file($path)
        ) {
            continue;
        }
        $modifiedAt = filemtime($path);
        if ($modifiedAt !== false && time() - $modifiedAt >= $leaseSeconds && !unlink($path)) {
            throw new RuntimeException('voice_profile_staging_cleanup_failed');
        }
    }
}

function hub_cleanup_stale_voice_profile_finals(PDO $db): void
{
    $leaseSeconds = hub_voice_profile_transcription_lease_seconds($db);
    $dir = hub_voice_profile_storage_dir();
    $referenceStmt = $db->prepare('SELECT 1 FROM voice_profiles WHERE reference_audio_path = :reference_audio_path LIMIT 1');
    foreach (glob($dir . '/voice_profile_*.wav') ?: [] as $path) {
        if (
            preg_match('/^voice_profile_[1-9][0-9]*_[a-f0-9]{32}\.wav$/', basename($path)) !== 1
            || !is_file($path)
        ) {
            continue;
        }
        $modifiedAt = filemtime($path);
        if ($modifiedAt === false || time() - $modifiedAt < $leaseSeconds) {
            continue;
        }
        $referenceStmt->execute([':reference_audio_path' => $path]);
        if ($referenceStmt->fetchColumn() !== false) {
            continue;
        }
        if (!unlink($path)) {
            throw new RuntimeException('voice_profile_final_cleanup_failed');
        }
    }
}

function hub_voice_profile_pending_response(array $profile): array
{
    return [
        'profile' => $profile,
        'cache_hit' => false,
        'transcription' => [
            'ok' => false,
            'error' => 'transcription_pending',
            'message' => 'Transcription is pending',
        ],
    ];
}

function hub_run_voice_profile_transcription(PDO $db, array $profile, int $ownerMemberId, ?callable $transcribe = null): array
{
    $profileId = (int)($profile['id'] ?? 0);
    $leaseToken = trim((string)($profile['transcription_lease_token'] ?? ''));
    if ($profileId < 1 || $leaseToken === '') {
        return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
    }
    $path = hub_voice_profile_safe_host_path((string)($profile['reference_audio_path'] ?? ''));
    if ($path === null) {
        $transcription = ['ok' => false, 'error' => 'asr_failed'];
    } else {
        try {
            $transcription = $transcribe === null
                ? hub_transcribe_voice_profile($db, [
                    'tmp_name' => $path,
                    'type' => 'audio/wav',
                    'size' => (int)(filesize($path) ?: 0),
                    'error' => UPLOAD_ERR_OK,
                ])
                : $transcribe();
        } catch (Throwable) {
            $transcription = ['ok' => false, 'error' => 'asr_failed'];
        }
    }
    if (!is_array($transcription) || empty($transcription['ok'])) {
        $error = hub_voice_profile_transcription_error_code($transcription['error'] ?? null);
        $failure = [
            'ok' => false,
            'error' => $error,
            'message' => $error === 'asr_unavailable' ? 'ASR service is unavailable' : 'ASR transcription failed',
        ];
        $finalization = hub_finalize_voice_profile_transcription(
            $db,
            $profileId,
            $ownerMemberId,
            "UPDATE voice_profiles SET transcription_status = :transcription_status, transcription_error = :transcription_error, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL AND transcription_status = 'pending' AND transcription_lease_token = :transcription_lease_token",
            [
                ':transcription_status' => 'failed',
                ':transcription_error' => $error,
                ':updated_at' => hub_now(),
                ':id' => $profileId,
                ':transcription_lease_token' => $leaseToken,
            ],
            ['status' => 'failed', 'error' => $error]
        );
        if ($finalization === 'lost') {
            return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
        }
        if ($finalization !== 'applied') {
            return hub_voice_profile_save_failure_response($db, $profileId, $profile);
        }

        return [
            'profile' => hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing'),
            'cache_hit' => false,
            'transcription' => $failure,
        ];
    }

    $text = trim((string)($transcription['text'] ?? ''));
    $language = trim((string)($transcription['language'] ?? '')) ?: 'auto';
    $device = is_array($transcription['device'] ?? null) ? $transcription['device'] : [];
    $finalization = hub_finalize_voice_profile_transcription(
        $db,
        $profileId,
        $ownerMemberId,
        "UPDATE voice_profiles SET prompt_text = :prompt_text, language = :language, prompt_text_confirmed_at = NULL, transcription_status = :transcription_status, transcription_error = NULL, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL AND transcription_status = 'pending' AND transcription_lease_token = :transcription_lease_token",
        [
            ':prompt_text' => $text,
            ':language' => $language,
            ':transcription_status' => 'ready',
            ':updated_at' => hub_now(),
            ':id' => $profileId,
            ':transcription_lease_token' => $leaseToken,
        ],
        [
            'status' => 'success',
            'device' => $device,
            'text_chars' => function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text),
        ]
    );
    if ($finalization === 'lost') {
        return hub_voice_profile_lost_lease_response($db, $profileId, $profile);
    }
    if ($finalization !== 'applied') {
        return hub_voice_profile_save_failure_response($db, $profileId, $profile);
    }

    return [
        'profile' => hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing'),
        'cache_hit' => false,
        'transcription' => ['ok' => true, 'text' => $text, 'language' => $language, 'device' => $device],
    ];
}

function hub_retry_voice_profile_transcription(PDO $db, int $profileId, int $ownerMemberId, ?callable $transcribe = null): array
{
    $profile = null;
    $retryTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $retryTransactionStarted = true;
        $profile = hub_get_voice_profile($db, $profileId);
        if ($profile === null || (int)$profile['owner_member_id'] !== $ownerMemberId) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        $status = (string)($profile['transcription_status'] ?? 'pending');
        if ($status === 'ready') {
            throw new InvalidArgumentException('voice_profile_transcription_not_retryable');
        }
        if ($status === 'pending' && !hub_voice_profile_transcription_is_stale($db, $profile)) {
            $db->exec('COMMIT');
            $retryTransactionStarted = false;
            return hub_voice_profile_pending_response($profile);
        }
        if ($status !== 'failed' && $status !== 'pending') {
            throw new InvalidArgumentException('voice_profile_transcription_not_retryable');
        }
        $profile = hub_claim_voice_profile_transcription($db, $profileId);
        $db->exec('COMMIT');
        $retryTransactionStarted = false;
    } catch (Throwable $e) {
        if ($retryTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }

    return hub_run_voice_profile_transcription($db, $profile, $ownerMemberId, $transcribe);
}

function hub_create_uploaded_voice_profile(PDO $db, int $ownerMemberId, array $upload, array $input, ?callable $moveFile = null, ?callable $transcribe = null): array
{
    if (!hub_get_api_member($db, $ownerMemberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $wav = hub_validate_voice_profile_wav($upload);
    $profileInput = hub_validate_voice_profile_input($input);
    $profile = hub_find_active_voice_profile_by_owner_sha($db, $ownerMemberId, $wav['sha256']);
    if ($profile !== null) {
        $status = (string)($profile['transcription_status'] ?? 'pending');
        if ($status === 'ready') {
            hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'cache_hit', null, ['status' => 'reused']);
            return ['profile' => $profile, 'cache_hit' => true];
        }
        if ($status === 'pending') {
            if (!hub_voice_profile_transcription_is_stale($db, $profile)) {
                hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'transcription_pending', null, ['status' => 'pending']);
                return hub_voice_profile_pending_response($profile);
            }

            return hub_retry_voice_profile_transcription($db, (int)$profile['id'], $ownerMemberId, $transcribe);
        }

        return hub_retry_voice_profile_transcription($db, (int)$profile['id'], $ownerMemberId, $transcribe);
    }
    hub_cleanup_stale_voice_profile_staging($db);
    $dir = hub_voice_profile_storage_dir();
    $stagingPath = $dir . DIRECTORY_SEPARATOR . 'voice_profile_stage_' . $ownerMemberId . '_' . bin2hex(random_bytes(16)) . '.wav';
    $path = null;
    $moveFile ??= static fn (string $from, string $to): bool => move_uploaded_file($from, $to);
    if (!$moveFile($wav['tmp_name'], $stagingPath) || !is_file($stagingPath)) {
        throw new RuntimeException('voice_profile_upload_failed');
    }

    $profile = null;
    $outcome = null;
    $finalized = false;
    $voiceProfileUploadTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $voiceProfileUploadTransactionStarted = true;
        $profile = hub_find_active_voice_profile_by_owner_sha($db, $ownerMemberId, $wav['sha256']);
        if ($profile !== null) {
            if (!unlink($stagingPath)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            $status = (string)($profile['transcription_status'] ?? 'pending');
            if ($status === 'ready') {
                $outcome = 'cache_hit';
            } elseif ($status === 'failed') {
                $profile = hub_claim_voice_profile_transcription($db, (int)$profile['id']);
                $outcome = 'transcribe';
            } elseif (hub_voice_profile_transcription_is_stale($db, $profile)) {
                $profile = hub_claim_voice_profile_transcription($db, (int)$profile['id']);
                $outcome = 'transcribe';
            } else {
                $outcome = 'pending';
            }
        } else {
            hub_cleanup_stale_voice_profile_finals($db);
            $path = $dir . DIRECTORY_SEPARATOR . 'voice_profile_' . $ownerMemberId . '_' . bin2hex(random_bytes(16)) . '.wav';
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            if (!rename($stagingPath, $path) || !is_file($path)) {
                throw new RuntimeException('voice_profile_upload_failed');
            }
            $finalized = true;
            $profileId = hub_create_voice_profile($db, $ownerMemberId, [
                'name' => $profileInput['name'],
                'reference_audio_path' => $path,
                'consent_type' => $profileInput['consent_type'],
                'usage_scope' => 'private',
                'visibility' => 'private',
                'retain_original_audio' => $input['retain_original_audio'] ?? 1,
                'transcription_status' => 'pending',
            ]);
            $profile = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
            $outcome = 'transcribe';
        }
        $db->exec('COMMIT');
        $voiceProfileUploadTransactionStarted = false;
    } catch (Throwable $e) {
        $cleanupFailed = false;
        if ($voiceProfileUploadTransactionStarted) {
            if ($finalized && $path !== null && is_file($path) && !unlink($path)) {
                $cleanupFailed = true;
            }
            if (is_file($stagingPath) && !unlink($stagingPath)) {
                $cleanupFailed = true;
            }
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        } elseif (is_file($stagingPath) && !unlink($stagingPath)) {
            $cleanupFailed = true;
        }
        if ($cleanupFailed) {
            throw new RuntimeException('voice_profile_upload_cleanup_failed', 0, $e);
        }
        throw $e;
    }
    if ($outcome === 'cache_hit') {
        hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'cache_hit', null, ['status' => 'reused']);
        return ['profile' => $profile, 'cache_hit' => true];
    }
    if ($outcome === 'pending') {
        hub_record_voice_profile_audit($db, (int)$profile['id'], $ownerMemberId, null, 'transcription_pending', null, ['status' => 'pending']);
        return hub_voice_profile_pending_response($profile);
    }

    return hub_run_voice_profile_transcription($db, $profile, $ownerMemberId, $transcribe);
}

function hub_transcribe_voice_profile(PDO $db, array $upload): array
{
    $service = hub_get_service_by_mode($db, 'asr');
    if (
        !$service
        || (int)($service['enabled'] ?? 0) !== 1
        || (string)($service['install_status'] ?? '') !== 'installed'
        || trim((string)($service['internal_url'] ?? '')) === ''
    ) {
        return ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR service is unavailable'];
    }

    $previousFiles = $_FILES;
    $previousPost = $_POST;
    $hadRequestMethod = array_key_exists('REQUEST_METHOD', $_SERVER);
    $previousRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    try {
        $_FILES = ['audio' => [
            'name' => 'voice-profile.wav',
            'type' => (string)($upload['type'] ?? 'audio/wav'),
            'tmp_name' => (string)($upload['tmp_name'] ?? ''),
            'error' => UPLOAD_ERR_OK,
            'size' => (int)($upload['size'] ?? 0),
        ]];
        $_POST = ['real_inference' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $response = hub_proxy_request((string)$service['internal_url'], hub_service_gateway_timeout_sec($service));
        $body = json_decode((string)($response['body'] ?? ''), true);
        if ((int)($response['status'] ?? 0) >= 200 && (int)($response['status'] ?? 0) < 300 && is_array($body) && !empty($body['ok'])) {
            return [
                'ok' => true,
                'text' => trim((string)($body['text'] ?? '')),
                'language' => (string)($body['language'] ?? 'auto'),
                'device' => is_array($body['device'] ?? null) ? $body['device'] : [],
            ];
        }

        return [
            'ok' => false,
            'error' => trim((string)($body['error'] ?? '')) ?: 'asr_failed',
            'message' => trim((string)($body['message'] ?? '')) ?: 'ASR transcription failed',
        ];
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'asr_failed', 'message' => 'ASR transcription failed'];
    } finally {
        $_FILES = $previousFiles;
        $_POST = $previousPost;
        if ($hadRequestMethod) {
            $_SERVER['REQUEST_METHOD'] = $previousRequestMethod;
        } else {
            unset($_SERVER['REQUEST_METHOD']);
        }
    }
}

function hub_valid_voice_profile_consent(string $value): string
{
    $value = trim($value);
    if (!in_array($value, ['self_recorded', 'explicit_permission', 'licensed_voice'], true)) {
        throw new InvalidArgumentException('consent_type must be self_recorded, explicit_permission or licensed_voice.');
    }

    return $value;
}

function hub_validate_voice_profile_input(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Voice profile name is required.');
    }
    $consentType = hub_valid_voice_profile_consent((string)($input['consent_type'] ?? ''));
    $visibility = trim((string)($input['visibility'] ?? 'private'));
    if (!in_array($visibility, ['private', 'shared'], true)) {
        throw new InvalidArgumentException('Invalid visibility.');
    }
    $usageScope = trim((string)($input['usage_scope'] ?? 'private')) ?: 'private';
    if (!in_array($usageScope, ['private', 'internal', 'licensed'], true)) {
        throw new InvalidArgumentException('Invalid usage scope.');
    }

    return ['name' => $name, 'consent_type' => $consentType, 'visibility' => $visibility, 'usage_scope' => $usageScope];
}

function hub_create_voice_profile(PDO $db, int $ownerMemberId, array $input): int
{
    if (!hub_get_api_member($db, $ownerMemberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $profileInput = hub_validate_voice_profile_input($input);
    $path = hub_voice_profile_safe_host_path((string)($input['reference_audio_path'] ?? ''));
    if ($path === null) {
        throw new InvalidArgumentException('reference audio must be a managed voice profile asset.');
    }

    $promptText = trim((string)($input['prompt_text'] ?? '')) ?: null;
    $transcriptionStatus = trim((string)($input['transcription_status'] ?? ''));
    if ($transcriptionStatus === '') {
        $transcriptionStatus = $promptText === null ? 'pending' : 'ready';
    }
    if (!in_array($transcriptionStatus, ['pending', 'ready', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid transcription status.');
    }
    $transcriptionError = $transcriptionStatus === 'failed'
        ? hub_voice_profile_transcription_error_code($input['transcription_error'] ?? null)
        : null;
    $now = hub_now();
    $transcriptionStartedAt = $transcriptionStatus === 'pending' ? $now : null;
    $transcriptionLeaseToken = $transcriptionStatus === 'pending' ? bin2hex(random_bytes(32)) : null;
    $stmt = $db->prepare(
        'INSERT INTO voice_profiles
            (owner_member_id, name, reference_audio_path, reference_audio_sha256, prompt_text, language,
             transcription_status, transcription_error, transcription_started_at, transcription_lease_token, consent_type, usage_scope, visibility, retain_original_audio, expires_at, created_at, updated_at)
         VALUES
            (:owner_member_id, :name, :reference_audio_path, :reference_audio_sha256, :prompt_text, :language,
             :transcription_status, :transcription_error, :transcription_started_at, :transcription_lease_token, :consent_type, :usage_scope, :visibility, :retain_original_audio, :expires_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':owner_member_id' => $ownerMemberId,
        ':name' => $profileInput['name'],
        ':reference_audio_path' => $path,
        ':reference_audio_sha256' => hash_file('sha256', $path),
        ':prompt_text' => $promptText,
        ':language' => trim((string)($input['language'] ?? '')) ?: null,
        ':transcription_status' => $transcriptionStatus,
        ':transcription_error' => $transcriptionError,
        ':transcription_started_at' => $transcriptionStartedAt,
        ':transcription_lease_token' => $transcriptionLeaseToken,
        ':consent_type' => $profileInput['consent_type'],
        ':usage_scope' => $profileInput['usage_scope'],
        ':visibility' => $profileInput['visibility'],
        ':retain_original_audio' => !array_key_exists('retain_original_audio', $input) || (int)$input['retain_original_audio'] === 1 ? 1 : 0,
        ':expires_at' => trim((string)($input['expires_at'] ?? '')) ?: null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $profileId = (int)$db->lastInsertId();
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'create', null, [
        'consent_type' => $profileInput['consent_type'],
        'usage_scope' => $profileInput['usage_scope'],
        'visibility' => $profileInput['visibility'],
    ]);

    return $profileId;
}

function hub_get_voice_profile(PDO $db, int $profileId): ?array
{
    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $profileId]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_get_voice_profile_for_member(PDO $db, int $profileId, int $memberId): ?array
{
    $stmt = $db->prepare(
        'SELECT * FROM voice_profiles
         WHERE id = :id
           AND deleted_at IS NULL
           AND (owner_member_id = :owner_member_id OR visibility = "shared")'
    );
    $stmt->execute([':id' => $profileId, ':owner_member_id' => $memberId]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_confirm_voice_profile_prompt(PDO $db, int $profileId, int $ownerMemberId, string $promptText): array
{
    $promptText = trim($promptText);
    if ($promptText === '') {
        throw new InvalidArgumentException('voice_profile_transcript_invalid');
    }

    $confirmationTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $confirmationTransactionStarted = true;
        $profile = hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId);
        if (!$profile || (int)$profile['owner_member_id'] !== $ownerMemberId) {
            throw new InvalidArgumentException('voice_profile_transcript_invalid');
        }
        $now = hub_now();
        $stmt = $db->prepare('UPDATE voice_profiles SET prompt_text = :prompt_text, prompt_text_confirmed_at = :confirmed_at, transcription_status = :transcription_status, transcription_error = NULL, transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL');
        $stmt->execute([
            ':prompt_text' => $promptText,
            ':confirmed_at' => $now,
            ':transcription_status' => 'ready',
            ':updated_at' => $now,
            ':id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new InvalidArgumentException('voice_profile_transcript_invalid');
        }
        hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'confirm_transcript', null, ['text_chars' => function_exists('mb_strlen') ? mb_strlen($promptText, 'UTF-8') : strlen($promptText)]);
        $db->exec('COMMIT');
        $confirmationTransactionStarted = false;
    } catch (Throwable $e) {
        if ($confirmationTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }

    return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
}

function hub_soft_delete_voice_profile(PDO $db, int $profileId, int $ownerMemberId, bool $deleteAudio = false): array
{
    $profile = hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId);
    if (!$profile || (int)$profile['owner_member_id'] !== $ownerMemberId) {
        throw new InvalidArgumentException('voice_profile_forbidden');
    }
    $path = $deleteAudio ? hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']) : null;
    $deleteTransactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $deleteTransactionStarted = true;
        $stmt = $db->prepare("UPDATE voice_profiles SET deleted_at = :deleted_at, transcription_status = 'failed', transcription_error = 'asr_failed', transcription_started_at = NULL, transcription_lease_token = NULL, updated_at = :updated_at WHERE id = :id AND owner_member_id = :owner_member_id AND deleted_at IS NULL");
        $stmt->execute([
            ':deleted_at' => hub_now(),
            ':updated_at' => hub_now(),
            ':id' => $profileId,
            ':owner_member_id' => $ownerMemberId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new InvalidArgumentException('voice_profile_forbidden');
        }
        hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'delete', null, ['delete_audio' => $deleteAudio]);
        $db->exec('COMMIT');
        $deleteTransactionStarted = false;
    } catch (Throwable $e) {
        if ($deleteTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $e;
    }
    $audioCleanupFailed = false;
    if ($path !== null && is_file($path) && !unlink($path)) {
        $audioCleanupFailed = true;
    }

    return ['audio_cleanup_failed' => $audioCleanupFailed];
}

function hub_record_voice_profile_audit(PDO $db, ?int $profileId, ?int $ownerMemberId, ?int $tokenId, string $action, ?string $mode, array $details = []): void
{
    $stmt = $db->prepare(
        'INSERT INTO voice_profile_audit_logs
            (voice_profile_id, owner_member_id, token_id, action, mode, details_json, created_at)
         VALUES
            (:voice_profile_id, :owner_member_id, :token_id, :action, :mode, :details_json, :created_at)'
    );
    $stmt->execute([
        ':voice_profile_id' => $profileId,
        ':owner_member_id' => $ownerMemberId,
        ':token_id' => $tokenId,
        ':action' => $action,
        ':mode' => $mode,
        ':details_json' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':created_at' => hub_now(),
    ]);
}
