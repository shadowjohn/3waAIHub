<?php
declare(strict_types=1);

function hub_voice_preset_slug(mixed $value): ?string
{
    if (!is_string($value) || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $value) !== 1) {
        return null;
    }

    return $value;
}

function hub_voice_preset_slug_list(mixed $value): ?array
{
    if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 32) {
        return null;
    }
    $items = [];
    foreach ($value as $item) {
        $slug = hub_voice_preset_slug($item);
        if ($slug === null || isset($items[$slug])) {
            return null;
        }
        $items[$slug] = true;
    }

    return array_keys($items);
}

function hub_voice_preset_owner_id(array $auth): int
{
    return max(0, (int)($auth['member_id'] ?? 0));
}

function hub_voice_preset_profile_for_task(PDO $db, int $memberId, mixed $taskId, bool $confirmed = false): ?array
{
    if (!is_string($taskId) || preg_match('/\A[1-9][0-9]{0,17}\z/', $taskId) !== 1) {
        return null;
    }
    $task = hub_voice_profile_task_for_member($db, (int)$taskId, $memberId);
    $profile = is_array($task['voice_profile'] ?? null) ? $task['voice_profile'] : null;
    if ($task === null || $profile === null || (string)($task['status'] ?? '') !== 'success'
        || !empty($profile['deleted_at'])
        || (!empty($profile['expires_at']) && (string)$profile['expires_at'] <= hub_now())) {
        return null;
    }
    if ($confirmed && trim((string)($profile['prompt_text_confirmed_at'] ?? '')) === '') {
        return null;
    }

    return $profile;
}

function hub_voice_preset_public(array $preset): array
{
    $purposes = json_decode((string)($preset['purposes_json'] ?? ''), true);
    $scenes = json_decode((string)($preset['scenes_json'] ?? ''), true);

    return [
        'id' => (string)($preset['preset_id'] ?? ''),
        'label' => (string)($preset['label'] ?? ''),
        'gender' => (string)($preset['gender'] ?? ''),
        'age_bucket' => (string)($preset['age_bucket'] ?? ''),
        'purposes' => is_array($purposes) ? array_values($purposes) : [],
        'scenes' => is_array($scenes) ? array_values($scenes) : [],
        'preset_revision' => (int)($preset['revision'] ?? 0),
    ];
}

function hub_voice_preset_for_owner(PDO $db, int $memberId, string $presetId): ?array
{
    $stmt = $db->prepare('SELECT * FROM voice_presets WHERE owner_member_id = :owner_member_id AND preset_id = :preset_id LIMIT 1');
    $stmt->execute([':owner_member_id' => $memberId, ':preset_id' => $presetId]);
    $preset = $stmt->fetch();

    return $preset ?: null;
}

function hub_voice_preset_upsert(PDO $db, array $auth, array $payload): array
{
    $memberId = hub_voice_preset_owner_id($auth);
    $presetId = hub_voice_preset_slug($payload['voice_preset'] ?? null);
    $label = trim((string)($payload['label'] ?? ''));
    $gender = (string)($payload['gender'] ?? '');
    $ageBucket = (string)($payload['age_bucket'] ?? '');
    $purposes = hub_voice_preset_slug_list($payload['purposes'] ?? null);
    $scenes = hub_voice_preset_slug_list($payload['scenes'] ?? null);
    $profile = $memberId > 0 ? hub_voice_preset_profile_for_task($db, $memberId, $payload['voice_profile_task_id'] ?? null) : null;
    if ($memberId < 1 || $presetId === null || $label === '' || strlen($label) > 120
        || !in_array($gender, ['female', 'male', 'nonbinary', 'unspecified'], true)
        || !in_array($ageBucket, ['child', 'teen', 'adult', 'senior', 'unspecified'], true)
        || $purposes === null || $scenes === null || $profile === null) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    $now = hub_now();
    $existing = hub_voice_preset_for_owner($db, $memberId, $presetId);
    if ($existing === null) {
        $stmt = $db->prepare(
            'INSERT INTO voice_presets
                (owner_member_id, preset_id, label, gender, age_bucket, purposes_json, scenes_json, base_voice_profile_id, revision, enabled, created_at, updated_at)
             VALUES
                (:owner_member_id, :preset_id, :label, :gender, :age_bucket, :purposes_json, :scenes_json, :base_voice_profile_id, 1, 1, :created_at, :updated_at)'
        );
        $stmt->execute([
            ':owner_member_id' => $memberId,
            ':preset_id' => $presetId,
            ':label' => $label,
            ':gender' => $gender,
            ':age_bucket' => $ageBucket,
            ':purposes_json' => json_encode($purposes, JSON_UNESCAPED_SLASHES),
            ':scenes_json' => json_encode($scenes, JSON_UNESCAPED_SLASHES),
            ':base_voice_profile_id' => (int)$profile['id'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    } else {
        $stmt = $db->prepare(
            'UPDATE voice_presets
             SET label = :label, gender = :gender, age_bucket = :age_bucket, purposes_json = :purposes_json,
                 scenes_json = :scenes_json, base_voice_profile_id = :base_voice_profile_id, revision = revision + 1,
                 enabled = 1, updated_at = :updated_at
             WHERE id = :id AND owner_member_id = :owner_member_id'
        );
        $stmt->execute([
            ':label' => $label,
            ':gender' => $gender,
            ':age_bucket' => $ageBucket,
            ':purposes_json' => json_encode($purposes, JSON_UNESCAPED_SLASHES),
            ':scenes_json' => json_encode($scenes, JSON_UNESCAPED_SLASHES),
            ':base_voice_profile_id' => (int)$profile['id'],
            ':updated_at' => $now,
            ':id' => (int)$existing['id'],
            ':owner_member_id' => $memberId,
        ]);
    }
    $preset = hub_voice_preset_for_owner($db, $memberId, $presetId);
    if ($preset === null) {
        throw new RuntimeException('voice_preset_store_failed');
    }

    return ['ok' => true, 'preset' => hub_voice_preset_public($preset)];
}

function hub_voice_preset_anchor_upsert(PDO $db, array $auth, array $payload): array
{
    $memberId = hub_voice_preset_owner_id($auth);
    $presetId = hub_voice_preset_slug($payload['voice_preset'] ?? null);
    $scene = hub_voice_preset_slug($payload['scene'] ?? null);
    $preset = $memberId > 0 && $presetId !== null ? hub_voice_preset_for_owner($db, $memberId, $presetId) : null;
    $profile = $memberId > 0 ? hub_voice_preset_profile_for_task($db, $memberId, $payload['voice_profile_task_id'] ?? null, true) : null;
    $scenes = $preset === null ? [] : json_decode((string)$preset['scenes_json'], true);
    if ($preset === null || $scene === null || !is_array($scenes) || !in_array($scene, $scenes, true) || $profile === null) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT INTO voice_preset_scene_anchors (voice_preset_id, scene, voice_profile_id, created_at, updated_at)
         VALUES (:voice_preset_id, :scene, :voice_profile_id, :created_at, :updated_at)
         ON CONFLICT(voice_preset_id, scene) DO UPDATE SET voice_profile_id = excluded.voice_profile_id, updated_at = excluded.updated_at'
    );
    $stmt->execute([
        ':voice_preset_id' => (int)$preset['id'],
        ':scene' => $scene,
        ':voice_profile_id' => (int)$profile['id'],
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $db->prepare('UPDATE voice_presets SET revision = revision + 1, updated_at = :updated_at WHERE id = :id')
        ->execute([':updated_at' => $now, ':id' => (int)$preset['id']]);
    $updated = hub_voice_preset_for_owner($db, $memberId, $presetId);
    if ($updated === null) {
        throw new RuntimeException('voice_preset_store_failed');
    }

    return ['ok' => true, 'preset' => hub_voice_preset_public($updated)];
}

function hub_voice_preset_list(PDO $db, array $auth): array
{
    $memberId = hub_voice_preset_owner_id($auth);
    if ($memberId < 1) {
        return ['ok' => true, 'voice_presets' => []];
    }
    $stmt = $db->prepare('SELECT * FROM voice_presets WHERE owner_member_id = :owner_member_id AND enabled = 1 ORDER BY preset_id ASC');
    $stmt->execute([':owner_member_id' => $memberId]);

    return ['ok' => true, 'voice_presets' => array_map('hub_voice_preset_public', $stmt->fetchAll())];
}

function hub_voice_preset_api_dispatch(PDO $db, array $route, array $auth, array $payload): ?array
{
    $operation = $payload['operation'] ?? null;
    if (!is_string($operation) || !in_array($operation, ['voice_presets', 'voice_preset_upsert', 'voice_preset_anchor_upsert'], true)) {
        return null;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($operation === 'voice_presets') {
        if ($method !== 'GET' || array_keys($payload) !== ['operation']) {
            return hub_gateway_error(400, 'voice_preset_invalid', 'voice preset request is invalid');
        }

        return hub_gateway_json(200, hub_voice_preset_list($db, $auth));
    }
    if ($method !== 'POST') {
        return hub_gateway_error(405, 'method_not_allowed', 'voice preset request requires POST');
    }
    try {
        $result = $operation === 'voice_preset_upsert'
            ? hub_voice_preset_upsert($db, $auth, $payload)
            : hub_voice_preset_anchor_upsert($db, $auth, $payload);
    } catch (InvalidArgumentException) {
        return hub_gateway_error(400, 'voice_preset_invalid', 'voice preset request is invalid');
    }

    return hub_gateway_json(200, $result);
}
