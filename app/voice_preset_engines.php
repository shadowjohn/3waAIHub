<?php
declare(strict_types=1);

const HUB_VOICE_PRESET_DEFAULT_PACK_ID = 'tts-voxcpm2';
const HUB_VOICE_PRESET_BREEZY_PACK_ID = 'tts-breezyvoice';

function hub_voice_preset_engine_pack_id(mixed $engine): ?string
{
    return match ($engine) {
        'voxcpm2' => HUB_VOICE_PRESET_DEFAULT_PACK_ID,
        'breezyvoice' => HUB_VOICE_PRESET_BREEZY_PACK_ID,
        default => null,
    };
}

function hub_voice_preset_engine_positive_id(mixed $value): ?int
{
    if (is_int($value)) {
        return $value > 0 ? $value : null;
    }
    if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
        return null;
    }

    $id = (int)$value;
    return $id > 0 ? $id : null;
}

function hub_voice_preset_engine_binding_for_preset(PDO $db, array $preset): array
{
    $presetId = hub_voice_preset_engine_positive_id($preset['id'] ?? null);
    if ($presetId === null) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }
    $stmt = $db->prepare(
        'SELECT binding.pack_id, binding.compatibility_state
         FROM voice_presets AS preset
         LEFT JOIN voice_preset_engine_bindings AS binding ON binding.voice_preset_id = preset.id
         WHERE preset.id = :voice_preset_id'
    );
    $stmt->execute([':voice_preset_id' => $presetId]);
    $binding = $stmt->fetch();
    if ($binding === false) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }
    if (($binding['pack_id'] ?? null) === null) {
        return ['pack_id' => HUB_VOICE_PRESET_DEFAULT_PACK_ID, 'explicit' => false];
    }

    $packId = $binding['pack_id'] ?? null;
    if (
        !is_string($packId)
        || !in_array($packId, [HUB_VOICE_PRESET_DEFAULT_PACK_ID, HUB_VOICE_PRESET_BREEZY_PACK_ID], true)
        || ($binding['compatibility_state'] ?? null) !== 'ready'
    ) {
        throw new InvalidArgumentException('voice_preset_engine_incompatible');
    }

    return ['pack_id' => $packId, 'explicit' => true];
}

function hub_voice_preset_engine_binding_for_owner(PDO $db, int $memberId, string $presetId): array
{
    $preset = $memberId > 0 ? hub_voice_preset_for_owner($db, $memberId, $presetId) : null;
    if ($preset === null) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }

    return hub_voice_preset_engine_binding_for_preset($db, $preset);
}

function hub_voice_preset_breezy_profile_is_compatible(array $profile, int $memberId): bool
{
    $language = $profile['language'] ?? null;
    $expiresAt = $profile['expires_at'] ?? null;
    if ($expiresAt !== null && $expiresAt !== '') {
        if (!is_string($expiresAt)) {
            return false;
        }
        $expiresAtValue = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $expiresAt);
        $expiresAtErrors = DateTimeImmutable::getLastErrors();
        if (
            $expiresAtValue === false
            || ($expiresAtErrors !== false && (
                ($expiresAtErrors['warning_count'] ?? 0) !== 0
                || ($expiresAtErrors['error_count'] ?? 0) !== 0
            ))
            || $expiresAtValue->format('Y-m-d H:i:s') !== $expiresAt
            || $expiresAtValue->getTimestamp() <= time()
        ) {
            return false;
        }
    }

    return $memberId > 0
        && (int)($profile['owner_member_id'] ?? 0) === $memberId
        && (string)($profile['visibility'] ?? '') === 'private'
        && (string)($profile['usage_scope'] ?? '') === 'private'
        && trim((string)($profile['consent_type'] ?? '')) !== ''
        && in_array((string)($profile['transcription_status'] ?? ''), ['ready', 'confirmed'], true)
        && trim((string)($profile['prompt_text'] ?? '')) !== ''
        && trim((string)($profile['prompt_text_confirmed_at'] ?? '')) !== ''
        && ($language === null || $language === '' || $language === 'zh-TW')
        && trim((string)($profile['deleted_at'] ?? '')) === '';
}

function hub_voice_preset_engine_base_profile(PDO $db, array $preset): ?array
{
    $profileId = hub_voice_preset_engine_positive_id($preset['base_voice_profile_id'] ?? null);
    if ($profileId === null) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM voice_profiles WHERE id = :id');
    $stmt->execute([':id' => $profileId]);
    $profile = $stmt->fetch();

    return $profile ?: null;
}

function hub_voice_preset_engine_token_id(PDO $db, array $auth, int $memberId): ?int
{
    if (!array_key_exists('token_id', $auth) || $auth['token_id'] === null || $auth['token_id'] === '') {
        return null;
    }
    $tokenId = hub_voice_preset_engine_positive_id($auth['token_id']);
    $token = $tokenId === null ? null : hub_get_api_token($db, $tokenId);
    if ($token === null || (int)($token['member_id'] ?? 0) !== $memberId) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }

    return $tokenId;
}

function hub_voice_preset_engine_bind(PDO $db, array $auth, array $payload): array
{
    if (
        count($payload) !== 2
        || !array_key_exists('voice_preset', $payload)
        || !array_key_exists('engine', $payload)
        || array_diff(array_keys($payload), ['voice_preset', 'engine']) !== []
    ) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    $memberId = hub_voice_preset_owner_id($auth);
    $presetId = hub_voice_preset_slug($payload['voice_preset']);
    $packId = hub_voice_preset_engine_pack_id($payload['engine']);
    if ($memberId < 1 || $presetId === null || $packId === null) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    $tokenId = hub_voice_preset_engine_token_id($db, $auth, $memberId);
    $preset = hub_voice_preset_for_owner($db, $memberId, $presetId);
    if ($preset === null) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }
    $profile = hub_voice_preset_engine_base_profile($db, $preset);
    if ($packId === HUB_VOICE_PRESET_BREEZY_PACK_ID
        && ($profile === null || !hub_voice_preset_breezy_profile_is_compatible($profile, $memberId))) {
        throw new InvalidArgumentException('voice_preset_engine_incompatible');
    }

    $transactionStarted = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
        $preset = hub_voice_preset_for_owner($db, $memberId, $presetId);
        if ($preset === null) {
            throw new InvalidArgumentException('voice_preset_not_found');
        }
        $profile = hub_voice_preset_engine_base_profile($db, $preset);
        if ($packId === HUB_VOICE_PRESET_BREEZY_PACK_ID
            && ($profile === null || !hub_voice_preset_breezy_profile_is_compatible($profile, $memberId))) {
            throw new InvalidArgumentException('voice_preset_engine_incompatible');
        }

        $binding = $db->prepare(
            'SELECT pack_id
             FROM voice_preset_engine_bindings
             WHERE voice_preset_id = :voice_preset_id'
        );
        $binding->execute([':voice_preset_id' => (int)$preset['id']]);
        $existingBinding = $binding->fetch();
        $currentPackId = $existingBinding === false
            ? HUB_VOICE_PRESET_DEFAULT_PACK_ID
            : (string)($existingBinding['pack_id'] ?? '');
        $packChanged = $currentPackId !== $packId;
        $now = hub_now();
        $db->prepare(
            'INSERT INTO voice_preset_engine_bindings
                (voice_preset_id, pack_id, compatibility_state, created_at, updated_at)
             VALUES
                (:voice_preset_id, :pack_id, :compatibility_state, :created_at, :updated_at)
             ON CONFLICT(voice_preset_id) DO UPDATE SET
                pack_id = excluded.pack_id,
                compatibility_state = excluded.compatibility_state,
                updated_at = excluded.updated_at'
        )->execute([
            ':voice_preset_id' => (int)$preset['id'],
            ':pack_id' => $packId,
            ':compatibility_state' => 'ready',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $db->prepare(
            'UPDATE voice_presets
             SET revision = revision + :revision_increment, updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :owner_member_id'
        )->execute([
            ':revision_increment' => $packChanged ? 1 : 0,
            ':updated_at' => $now,
            ':id' => (int)$preset['id'],
            ':owner_member_id' => $memberId,
        ]);
        $updatedPreset = hub_voice_preset_for_owner($db, $memberId, $presetId);
        if ($updatedPreset === null) {
            throw new RuntimeException('voice_preset_store_failed');
        }
        hub_record_voice_profile_audit(
            $db,
            $profile === null ? null : (int)$profile['id'],
            $memberId,
            $tokenId,
            'preset_engine_bind',
            null,
            [
                'voice_preset' => $presetId,
                'pack_id' => $packId,
                'preset_revision' => (int)$updatedPreset['revision'],
            ]
        );
        $db->exec('COMMIT');
        $transactionStarted = false;

        return ['ok' => true, 'preset' => hub_voice_preset_public($updatedPreset)];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $error;
    }
}
