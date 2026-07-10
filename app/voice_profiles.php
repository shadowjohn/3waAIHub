<?php
declare(strict_types=1);

function hub_voice_profile_storage_dir(): string
{
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

function hub_valid_voice_profile_consent(string $value): string
{
    $value = trim($value);
    if (!in_array($value, ['self_recorded', 'explicit_permission', 'licensed_voice'], true)) {
        throw new InvalidArgumentException('consent_type must be self_recorded, explicit_permission or licensed_voice.');
    }

    return $value;
}

function hub_create_voice_profile(PDO $db, int $ownerMemberId, array $input): int
{
    if (!hub_get_api_member($db, $ownerMemberId)) {
        throw new InvalidArgumentException('Member not found.');
    }
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Voice profile name is required.');
    }
    $path = hub_voice_profile_safe_host_path((string)($input['reference_audio_path'] ?? ''));
    if ($path === null) {
        throw new InvalidArgumentException('reference audio must be a managed voice profile asset.');
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

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO voice_profiles
            (owner_member_id, name, reference_audio_path, reference_audio_sha256, prompt_text, language,
             consent_type, usage_scope, visibility, retain_original_audio, expires_at, created_at, updated_at)
         VALUES
            (:owner_member_id, :name, :reference_audio_path, :reference_audio_sha256, :prompt_text, :language,
             :consent_type, :usage_scope, :visibility, :retain_original_audio, :expires_at, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':owner_member_id' => $ownerMemberId,
        ':name' => $name,
        ':reference_audio_path' => $path,
        ':reference_audio_sha256' => hash_file('sha256', $path),
        ':prompt_text' => trim((string)($input['prompt_text'] ?? '')) ?: null,
        ':language' => trim((string)($input['language'] ?? '')) ?: null,
        ':consent_type' => $consentType,
        ':usage_scope' => $usageScope,
        ':visibility' => $visibility,
        ':retain_original_audio' => !array_key_exists('retain_original_audio', $input) || (int)$input['retain_original_audio'] === 1 ? 1 : 0,
        ':expires_at' => trim((string)($input['expires_at'] ?? '')) ?: null,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $profileId = (int)$db->lastInsertId();
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'create', null, [
        'consent_type' => $consentType,
        'usage_scope' => $usageScope,
        'visibility' => $visibility,
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

function hub_soft_delete_voice_profile(PDO $db, int $profileId, int $ownerMemberId, bool $deleteAudio = false): void
{
    $profile = hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId);
    if (!$profile || (int)$profile['owner_member_id'] !== $ownerMemberId) {
        throw new InvalidArgumentException('voice_profile_forbidden');
    }
    if ($deleteAudio) {
        $path = hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }
    $db->prepare('UPDATE voice_profiles SET deleted_at = :deleted_at, updated_at = :updated_at WHERE id = :id')
        ->execute([':deleted_at' => hub_now(), ':updated_at' => hub_now(), ':id' => $profileId]);
    hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'delete', null, ['delete_audio' => $deleteAudio]);
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
