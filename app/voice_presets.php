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

function hub_voice_preset_public_value(mixed $value): ?array
{
    if (!is_array($value) || array_keys($value) !== ['id', 'label', 'gender', 'age_bucket', 'purposes', 'scenes', 'preset_revision']) {
        return null;
    }
    $id = hub_voice_preset_slug($value['id'] ?? null);
    $label = is_string($value['label'] ?? null) ? trim($value['label']) : '';
    $gender = $value['gender'] ?? null;
    $ageBucket = $value['age_bucket'] ?? null;
    $purposes = hub_voice_preset_slug_list($value['purposes'] ?? null);
    $scenes = hub_voice_preset_slug_list($value['scenes'] ?? null);
    $revision = $value['preset_revision'] ?? null;
    if ($id === null || $label === '' || strlen($label) > 120
        || !in_array($gender, ['female', 'male', 'nonbinary', 'unspecified'], true)
        || !in_array($ageBucket, ['child', 'teen', 'adult', 'senior', 'unspecified'], true)
        || $purposes === null || $scenes === null || !is_int($revision) || $revision < 1) {
        return null;
    }

    return [
        'id' => $id,
        'label' => $label,
        'gender' => $gender,
        'age_bucket' => $ageBucket,
        'purposes' => $purposes,
        'scenes' => $scenes,
        'preset_revision' => $revision,
    ];
}

function hub_voice_preset_batch_result_candidates(array $taskInput, mixed $result, array $artifactIds): ?array
{
    $batch = hub_voice_preset_batch_snapshot($taskInput['voice_preset_batch'] ?? null);
    $candidates = is_array($result) ? ($result['candidates'] ?? null) : null;
    if ($batch === null || !is_array($candidates) || !array_is_list($candidates) || count($candidates) !== count($batch['candidates'])) {
        return null;
    }
    $available = array_fill_keys(array_map(static fn (mixed $id): int => (int)$id, $artifactIds), true);
    foreach ($candidates as $index => $candidate) {
        $expected = $batch['candidates'][$index];
        if (!is_array($candidate)
            || array_keys($candidate) !== ['candidate_id', 'audio_artifact_id', 'seed', 'preset_revision']
            || $candidate['candidate_id'] !== $expected['candidate_id']
            || $candidate['seed'] !== $expected['seed']
            || $candidate['preset_revision'] !== $batch['preset_revision']
            || !is_int($candidate['audio_artifact_id']) || $candidate['audio_artifact_id'] < 1
            || !isset($available[$candidate['audio_artifact_id']])) {
            return null;
        }
    }

    return $candidates;
}

function hub_voice_generic_batch_snapshot(mixed $value): ?array
{
    if (!is_array($value) || array_keys($value) !== [
        'gender',
        'age_bucket',
        'role_note',
        'voice_design_revision',
        'style_status',
        'candidates',
    ]) {
        return null;
    }
    $roleNoteLength = hub_voxcpm2_metadata_utf8_length($value['role_note'] ?? null);
    if (!in_array($value['gender'] ?? null, ['unspecified', 'male', 'female'], true)
        || !in_array($value['age_bucket'] ?? null, ['child', 'teen', 'young_adult', 'mature', 'senior'], true)
        || $roleNoteLength === null || $roleNoteLength > 300
        || ($value['voice_design_revision'] ?? null) !== 1
        || ($value['style_status'] ?? null) !== 'unverified'
        || !is_array($value['candidates'] ?? null) || !array_is_list($value['candidates'])
        || count($value['candidates']) < 1 || count($value['candidates']) > 3) {
        return null;
    }
    foreach ($value['candidates'] as $index => $candidate) {
        if (!is_array($candidate)
            || $candidate !== ['candidate_id' => 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT), 'seed' => $candidate['seed'] ?? null]
            || !is_int($candidate['seed'] ?? null)
            || $candidate['seed'] < 0 || $candidate['seed'] > 2147483647) {
            return null;
        }
    }

    return $value;
}

function hub_voice_generic_batch_result_candidates(array $taskInput, mixed $result, array $artifactIds): ?array
{
    $batch = hub_voice_generic_batch_snapshot($taskInput['generic_voice_batch'] ?? null);
    $candidates = is_array($result) ? ($result['candidates'] ?? null) : null;
    if ($batch === null || !is_array($candidates) || !array_is_list($candidates) || count($candidates) !== count($batch['candidates'])) {
        return null;
    }
    $available = array_fill_keys(array_map(static fn (mixed $id): int => (int)$id, $artifactIds), true);
    $seen = [];
    foreach ($candidates as $index => $candidate) {
        $expected = $batch['candidates'][$index];
        if (!is_array($candidate)
            || array_keys($candidate) !== ['candidate_id', 'audio_artifact_id', 'seed', 'voice_design_revision', 'style_status']
            || $candidate['candidate_id'] !== $expected['candidate_id']
            || $candidate['seed'] !== $expected['seed']
            || $candidate['voice_design_revision'] !== 1
            || $candidate['style_status'] !== 'unverified'
            || !is_int($candidate['audio_artifact_id']) || $candidate['audio_artifact_id'] < 1
            || !isset($available[$candidate['audio_artifact_id']])
            || isset($seen[$candidate['audio_artifact_id']])) {
            return null;
        }
        $seen[$candidate['audio_artifact_id']] = true;
    }

    return $candidates;
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

function hub_voice_preset_delete(PDO $db, array $auth, array $payload): array
{
    $memberId = hub_voice_preset_owner_id($auth);
    $presetId = hub_voice_preset_slug($payload['voice_preset'] ?? null);
    if ($memberId < 1 || $presetId === null || array_keys($payload) !== ['voice_preset']) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    $stmt = $db->prepare('DELETE FROM voice_presets WHERE owner_member_id = :owner_member_id AND preset_id = :preset_id');
    $stmt->execute([':owner_member_id' => $memberId, ':preset_id' => $presetId]);
    if ($stmt->rowCount() !== 1) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }

    return ['ok' => true, 'voice_preset' => $presetId, 'status' => 'deleted'];
}

function hub_voice_preset_batch_snapshot(mixed $value): ?array
{
    if (!is_array($value) || array_keys($value) !== ['preset_id', 'preset_revision', 'candidates']
        || hub_voice_preset_slug($value['preset_id'] ?? null) === null
        || !is_int($value['preset_revision'] ?? null) || (int)$value['preset_revision'] < 1
        || !is_array($value['candidates'] ?? null) || !array_is_list($value['candidates'])) {
        return null;
    }
    $candidates = $value['candidates'];
    if (count($candidates) < 1 || count($candidates) > 3) {
        return null;
    }
    foreach ($candidates as $index => $candidate) {
        if (!is_array($candidate)
            || $candidate !== ['candidate_id' => 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT), 'seed' => $candidate['seed'] ?? null]
            || !is_int($candidate['seed'] ?? null)
            || $candidate['seed'] < 0 || $candidate['seed'] > 2147483647) {
            return null;
        }
    }

    return $value;
}

function hub_voice_preset_candidate_artifact_definitions(array $taskInput, ?array $primary): array
{
    $batch = hub_voice_preset_batch_snapshot($taskInput['voice_preset_batch'] ?? null);
    if ($batch === null) {
        return [];
    }
    if ($primary === null || ($primary['path'] ?? null) !== 'generated_audio.wav') {
        throw new InvalidArgumentException('voice_preset_output_invalid');
    }
    $definitions = [];
    foreach (array_slice($batch['candidates'], 1) as $index => $candidate) {
        $number = $index + 2;
        $definitions[] = array_replace($primary, [
            'type' => 'voice_candidate_' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'path' => 'candidate-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) . '.wav',
        ]);
    }

    return $definitions;
}

function hub_voice_generic_candidate_artifact_definitions(array $taskInput, ?array $primary): array
{
    $batch = hub_voice_generic_batch_snapshot($taskInput['generic_voice_batch'] ?? null);
    if ($batch === null) {
        return [];
    }
    if ($primary === null || ($primary['path'] ?? null) !== 'generated_audio.wav') {
        throw new InvalidArgumentException('generic_voice_output_invalid');
    }
    $definitions = [];
    foreach (array_slice($batch['candidates'], 1) as $index => $candidate) {
        $number = $index + 2;
        $definitions[] = array_replace($primary, [
            'type' => 'voice_candidate_' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'path' => 'candidate-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT) . '.wav',
        ]);
    }

    return $definitions;
}

function hub_voice_preset_batch_task_result(PDO $db, int $taskId, array $registered): array
{
    $task = hub_get_task($db, $taskId);
    $batch = is_array($task['input'] ?? null) ? hub_voice_preset_batch_snapshot($task['input']['voice_preset_batch'] ?? null) : null;
    if ($batch === null) {
        return [];
    }
    $byName = [];
    foreach ($registered as $artifact) {
        if (is_string($artifact['name'] ?? null) && is_int($artifact['id'] ?? null)) {
            $byName[$artifact['name']] = $artifact['id'];
        }
    }
    $candidates = [];
    foreach ($batch['candidates'] as $index => $candidate) {
        $name = $index === 0 ? 'generated_audio.wav' : 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '.wav';
        if (!isset($byName[$name])) {
            throw new InvalidArgumentException('voice_preset_output_invalid');
        }
        $candidates[] = [
            'candidate_id' => $candidate['candidate_id'],
            'audio_artifact_id' => $byName[$name],
            'seed' => $candidate['seed'],
            'preset_revision' => $batch['preset_revision'],
        ];
    }

    return ['candidates' => $candidates];
}

function hub_voice_generic_batch_task_result(PDO $db, int $taskId, array $registered): array
{
    $task = hub_get_task($db, $taskId);
    $batch = is_array($task['input'] ?? null) ? hub_voice_generic_batch_snapshot($task['input']['generic_voice_batch'] ?? null) : null;
    if ($batch === null) {
        return [];
    }
    $byName = [];
    foreach ($registered as $artifact) {
        if (is_string($artifact['name'] ?? null) && is_int($artifact['id'] ?? null)) {
            $byName[$artifact['name']] = $artifact['id'];
        }
    }
    $candidates = [];
    foreach ($batch['candidates'] as $index => $candidate) {
        $name = $index === 0 ? 'generated_audio.wav' : 'candidate-' . str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) . '.wav';
        if (!isset($byName[$name])) {
            throw new InvalidArgumentException('generic_voice_output_invalid');
        }
        $candidates[] = [
            'candidate_id' => $candidate['candidate_id'],
            'audio_artifact_id' => $byName[$name],
            'seed' => $candidate['seed'],
            'voice_design_revision' => 1,
            'style_status' => 'unverified',
        ];
    }

    return ['candidates' => $candidates];
}

function hub_voice_preset_seed(array $payload, int $index): int
{
    if ($index === 1 && array_key_exists('seed', $payload)) {
        return (int)$payload['seed'];
    }
    $source = implode("\n", [
        (string)$payload['voice_preset'],
        (string)$payload['preset_revision'],
        (string)$payload['purpose'],
        (string)$payload['scene'],
        (string)$payload['text'],
        (string)($payload['seed'] ?? ''),
        (string)$index,
    ]);

    return (int)(hexdec(substr(hash('sha256', $source), 0, 8)) & 0x7fffffff);
}

function hub_voice_preset_api_synthesize(PDO $db, array $route, array $auth, array $payload): array
{
    $allowed = ['voice_preset' => true, 'purpose' => true, 'scene' => true, 'candidate_count' => true, 'text' => true, 'seed' => true];
    if (array_diff_key($payload, $allowed) !== []) {
        throw new InvalidArgumentException('voice_preset_forbidden_input');
    }
    $memberId = hub_voice_preset_owner_id($auth);
    $presetId = hub_voice_preset_slug($payload['voice_preset'] ?? null);
    if ($presetId === null) {
        throw new InvalidArgumentException('voice_preset_required');
    }
    $purpose = hub_voice_preset_slug($payload['purpose'] ?? null);
    $scene = hub_voice_preset_slug($payload['scene'] ?? null);
    $candidateCount = $payload['candidate_count'] ?? null;
    $text = $payload['text'] ?? null;
    $seed = $payload['seed'] ?? null;
    if ($purpose === null || $scene === null || !is_int($candidateCount) || $candidateCount < 1 || $candidateCount > 3
        || !is_string($text) || trim($text) === '' || strlen($text) > 4096
        || ($seed !== null && (!is_int($seed) || $seed < 0 || $seed > 2147483647))) {
        throw new InvalidArgumentException(
            !is_int($candidateCount) || $candidateCount < 1 || $candidateCount > 3
                ? 'voice_preset_candidate_count_invalid'
                : 'voice_preset_invalid'
        );
    }
    $preset = $memberId > 0 ? hub_voice_preset_for_owner($db, $memberId, $presetId) : null;
    if ($preset === null) {
        throw new InvalidArgumentException('voice_preset_not_found');
    }
    if ((int)($preset['enabled'] ?? 0) !== 1) {
        throw new InvalidArgumentException('voice_preset_unavailable');
    }
    $purposes = json_decode((string)($preset['purposes_json'] ?? ''), true);
    $scenes = json_decode((string)($preset['scenes_json'] ?? ''), true);
    if (!is_array($purposes) || !in_array($purpose, $purposes, true)) {
        throw new InvalidArgumentException('voice_preset_invalid');
    }
    if (!is_array($scenes) || !in_array($scene, $scenes, true)) {
        throw new InvalidArgumentException('voice_preset_scene_invalid');
    }
    $binding = hub_voice_preset_engine_binding_for_preset($db, $preset);
    if (($binding['pack_id'] ?? null) === HUB_VOICE_PRESET_BREEZY_PACK_ID && $candidateCount !== 1) {
        throw new InvalidArgumentException('voice_preset_candidate_count_unsupported');
    }
    $route = hub_resolve_voice_preset_engine_route($db, (string)$binding['pack_id']);
    $anchor = $db->prepare('SELECT voice_profile_id FROM voice_preset_scene_anchors WHERE voice_preset_id = :voice_preset_id AND scene = :scene LIMIT 1');
    $anchor->execute([':voice_preset_id' => (int)$preset['id'], ':scene' => $scene]);
    $anchorProfileId = (int)$anchor->fetchColumn();
    if (($binding['pack_id'] ?? null) === HUB_VOICE_PRESET_BREEZY_PACK_ID) {
        $mode = 'ultimate_clone';
        $profileId = (int)$preset['base_voice_profile_id'];
    } else {
        $mode = $anchorProfileId > 0 ? 'ultimate_clone' : 'clone';
        $profileId = $anchorProfileId > 0 ? $anchorProfileId : (int)$preset['base_voice_profile_id'];
    }
    $firstSeedPayload = $payload + ['preset_revision' => (int)$preset['revision']];
    $candidates = [];
    for ($index = 1; $index <= $candidateCount; $index++) {
        $candidates[] = [
            'candidate_id' => 'candidate-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
            'seed' => hub_voice_preset_seed($firstSeedPayload, $index),
        ];
    }
    $input = hub_pack_job_task_input([
        'text' => $text,
        'mode' => $mode,
        'voice_profile_id' => $profileId,
        'seed' => $candidates[0]['seed'],
    ], $route);
    $input = hub_pack_job_task_resolve_voice_context($db, $input, $route, $memberId, (int)($auth['token_id'] ?? 0));
    $taskId = hub_enqueue_owned_pack_job($db, $route, $input, $memberId, (int)($auth['token_id'] ?? 0) ?: null, hub_get_client_ip());
    $task = hub_get_task($db, $taskId);
    if ($task === null) {
        throw new RuntimeException('voice_preset_task_store_failed');
    }
    $taskInput = (array)$task['input'];
    $taskInput['voice_preset_batch'] = [
        'preset_id' => $presetId,
        'preset_revision' => (int)$preset['revision'],
        'candidates' => $candidates,
    ];
    hub_update_task_input($db, $taskId, $taskInput);

    return hub_task_submit_response($taskId);
}

function hub_voice_generic_api_synthesize(PDO $db, array $route, array $auth, array $payload): array
{
    $allowed = ['text' => true, 'gender' => true, 'age_bucket' => true, 'role_note' => true, 'candidate_count' => true];
    if (array_diff_key($payload, $allowed) !== []) {
        throw new InvalidArgumentException('generic_voice_forbidden_input');
    }
    $memberId = hub_voice_preset_owner_id($auth);
    $text = $payload['text'] ?? null;
    $gender = $payload['gender'] ?? null;
    $ageBucket = $payload['age_bucket'] ?? null;
    $roleNote = $payload['role_note'] ?? '';
    $candidateCount = $payload['candidate_count'] ?? null;
    $roleNoteLength = hub_voxcpm2_metadata_utf8_length($roleNote);
    if ($memberId < 1
        || !is_string($text) || trim($text) === '' || strlen($text) > 4096 || hub_voxcpm2_metadata_utf8_length($text) === null
        || !in_array($gender, ['unspecified', 'male', 'female'], true)
        || !in_array($ageBucket, ['child', 'teen', 'young_adult', 'mature', 'senior'], true)
        || $roleNoteLength === null || $roleNoteLength > 300) {
        throw new InvalidArgumentException('generic_voice_invalid');
    }
    if (!is_int($candidateCount) || $candidateCount < 1 || $candidateCount > 3) {
        throw new InvalidArgumentException('generic_voice_candidate_count_invalid');
    }
    $candidates = [];
    for ($index = 1; $index <= $candidateCount; $index++) {
        $candidates[] = [
            'candidate_id' => 'candidate-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT),
            'seed' => random_int(0, 2147483647),
        ];
    }
    $input = hub_pack_job_task_input([
        'text' => $text,
        'mode' => 'design',
        'seed' => $candidates[0]['seed'],
    ], $route);
    $recipe = [
        'gender' => $gender,
        'age_bucket' => $ageBucket,
        'role_note' => $roleNote,
        'voice_design_revision' => 1,
        'style_status' => 'unverified',
        'candidates' => $candidates,
    ];
    $taskId = hub_stage_owned_pack_job($db, $route, $input, $memberId, (int)($auth['token_id'] ?? 0) ?: null, hub_get_client_ip());
    try {
        $task = hub_get_task($db, $taskId);
        if ($task === null) {
            throw new RuntimeException('generic_voice_task_store_failed');
        }
        $taskInput = (array)$task['input'];
        $taskInput['generic_voice_batch'] = $recipe;
        hub_update_task_input($db, $taskId, $taskInput);
        hub_publish_staged_pack_job($db, $taskId);
    } catch (Throwable $e) {
        $db->prepare("DELETE FROM tasks WHERE id = :id AND task_type = 'pack_job' AND status = 'staging'")
            ->execute([':id' => $taskId]);
        throw $e;
    }

    return hub_task_submit_response($taskId);
}

function hub_voice_preset_api_dispatch(PDO $db, array $route, array $auth, array $payload): ?array
{
    $operation = $payload['operation'] ?? null;
    if (!is_string($operation) || !in_array($operation, ['voice_presets', 'voice_preset_upsert', 'voice_preset_anchor_upsert', 'voice_preset_engine_bind', 'voice_preset_delete', 'preset_synthesize', 'generic_synthesize'], true)) {
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
        unset($payload['operation']);
        $result = match ($operation) {
            'voice_preset_upsert' => hub_voice_preset_upsert($db, $auth, $payload),
            'voice_preset_anchor_upsert' => hub_voice_preset_anchor_upsert($db, $auth, $payload),
            'voice_preset_engine_bind' => hub_voice_preset_engine_bind($db, $auth, $payload),
            'voice_preset_delete' => hub_voice_preset_delete($db, $auth, $payload),
            'preset_synthesize' => hub_voice_preset_api_synthesize($db, $route, $auth, $payload),
            'generic_synthesize' => hub_voice_generic_api_synthesize($db, $route, $auth, $payload),
        };
    } catch (InvalidArgumentException $error) {
        $code = in_array($error->getMessage(), [
            'voice_preset_required',
            'voice_preset_not_found',
            'voice_preset_unavailable',
            'voice_preset_scene_invalid',
            'voice_preset_candidate_count_invalid',
            'voice_preset_candidate_count_unsupported',
            'voice_preset_forbidden_input',
            'voice_preset_invalid',
            'voice_preset_engine_incompatible',
            'generic_voice_invalid',
            'generic_voice_candidate_count_invalid',
            'generic_voice_forbidden_input',
        ], true) ? $error->getMessage() : 'voice_preset_invalid';

        $status = match ($code) {
            'voice_preset_not_found' => 404,
            'voice_preset_unavailable' => 410,
            'voice_preset_engine_incompatible' => 409,
            default => 400,
        };

        return hub_gateway_error($status, $code, 'voice preset request is invalid');
    } catch (RuntimeException $error) {
        $code = in_array($error->getMessage(), [
            'pack_not_installed',
            'pack_runtime_not_ready',
            'pack_service_disabled',
            'pack_version_unavailable',
        ], true) ? $error->getMessage() : 'pack_version_unavailable';

        return hub_gateway_error(503, $code, 'voice generation runtime is not ready');
    }

    return hub_gateway_json(200, $result);
}
