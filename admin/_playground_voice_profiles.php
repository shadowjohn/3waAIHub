<?php
declare(strict_types=1);

function hub_playground_tts_member_id(PDO $db, string $token): int
{
    $auth = hub_gateway_authenticate_api_token($db, 'tts', hub_get_client_ip(), $token);
    $memberId = (int)($auth['context']['member_id'] ?? 0);
    if (empty($auth['ok']) || $memberId < 1) {
        throw new InvalidArgumentException('voice_profile_token_invalid');
    }

    return $memberId;
}

function hub_playground_tts_request_payload(): array
{
    $payload = [
        'mode' => trim((string)($_POST['tts_mode'] ?? 'design')) ?: 'design',
        'text' => trim((string)($_POST['text'] ?? 'RC 閥是用來控制二行程引擎排氣時機的重要機構。')),
        'voice_prompt' => trim((string)($_POST['voice_prompt'] ?? '沉穩的台灣男性技師，語速稍慢，清楚自然')),
        'control' => trim((string)($_POST['control'] ?? '沉穩、稍慢、像技師解說')),
        'seed' => (int)($_POST['seed'] ?? 42),
        'format' => 'wav',
        'real_inference' => !empty($_POST['real_inference']) ? 1 : 0,
    ];
    $voiceProfileId = (int)($_POST['voice_profile_id'] ?? 0);
    if ($voiceProfileId > 0) {
        $payload['voice_profile_id'] = $voiceProfileId;
    }

    return $payload;
}

function hub_playground_tts_active_profiles(PDO $db, string $token): array
{
    try {
        $memberId = hub_playground_tts_member_id($db, trim($token));
        $stmt = $db->prepare(
            'SELECT id, name, transcription_status, owner_member_id, visibility
             FROM voice_profiles
             WHERE deleted_at IS NULL
               AND (owner_member_id = :owner_member_id OR visibility = "shared")
             ORDER BY name ASC, id ASC'
        );
        $stmt->execute([':owner_member_id' => $memberId]);

        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function hub_playground_execute_tts_mode(string $ttsMode, string $token, ?callable $request = null): array
{
    if (!in_array($ttsMode, ['design', 'clone', 'ultimate_clone'], true)) {
        return ['ok' => false, 'error' => 'unsupported_tts_mode'];
    }

    $payload = hub_playground_tts_request_payload();
    $payload['mode'] = $ttsMode;

    if ($request !== null) {
        return $request($ttsMode, $payload, trim($token));
    }

    return hub_playground_execute('tts', $token, $payload);
}

function hub_playground_execute_tts(string $token, ?callable $request = null): array
{
    $ttsMode = trim((string)($_POST['tts_mode'] ?? 'design')) ?: 'design';
    $modes = !empty($_POST['compare_all']) ? ['design', 'clone', 'ultimate_clone'] : [$ttsMode];
    $results = [];

    foreach ($modes as $ttsMode) {
        $results[$ttsMode] = hub_playground_execute_tts_mode($ttsMode, $token, $request);
    }

    if (count($results) === 1) {
        return reset($results) ?: ['ok' => false, 'error' => 'request_failed'];
    }

    $successful = array_filter($results, static fn (array $result): bool => !empty($result['ok']));
    $failed = array_filter($results, static fn (array $result): bool => empty($result['ok']));

    return [
        'ok' => $successful !== [],
        'status' => $failed === [] ? 200 : 'mixed',
        'elapsed_ms' => array_sum(array_map(static fn (array $result): int => (int)($result['elapsed_ms'] ?? 0), $results)),
        'request_id' => '',
        'error' => '',
        'message' => '',
        'results' => $results,
        'pretty_body' => json_encode(['results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_playground_tts_audio_urls(array $service, array $result, ?callable $audioUrlForResult = null): array
{
    $audioUrls = [];
    foreach (is_array($result['results'] ?? null) ? $result['results'] : [] as $ttsMode => $ttsResult) {
        $audioUrl = ($audioUrlForResult ?? 'hub_playground_tts_audio_url')($service, is_array($ttsResult) ? $ttsResult : null);
        if ($audioUrl !== '') {
            $audioUrls[(string)$ttsMode] = $audioUrl;
        }
    }

    return $audioUrls;
}

function hub_playground_voice_profile_result(array $operation): array
{
    $profile = is_array($operation['profile'] ?? null) ? $operation['profile'] : [];
    $transcription = is_array($operation['transcription'] ?? null) ? $operation['transcription'] : [];
    $body = [
        'ok' => true,
        'voice_profile' => [
            'id' => (int)($profile['id'] ?? 0),
            'name' => (string)($profile['name'] ?? ''),
            'transcription_status' => (string)($profile['transcription_status'] ?? 'pending'),
        ],
        'cache_hit' => !empty($operation['cache_hit']),
    ];
    $error = trim((string)($transcription['error'] ?? ''));
    if (in_array($error, ['asr_unavailable', 'asr_failed', 'transcription_pending', 'transcription_lost_lease', 'transcription_save_failed'], true)) {
        $body['transcription'] = ['ok' => false, 'error' => $error];
    }

    return [
        'ok' => true,
        'status' => 200,
        'elapsed_ms' => 0,
        'request_id' => '',
        'error' => '',
        'message' => '',
        'pretty_body' => json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_playground_voice_profile_error_result(): array
{
    return [
        'ok' => false,
        'status' => 400,
        'elapsed_ms' => 0,
        'request_id' => '',
        'error' => 'voice_profile_request_failed',
        'message' => __('Voice Profile 操作失敗。'),
        'pretty_body' => json_encode(['ok' => false, 'error' => 'voice_profile_request_failed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    ];
}

function hub_playground_voice_profile_dispatch(PDO $db, string $action, string $token, array $post, array $files): array
{
    try {
        $memberId = hub_playground_tts_member_id($db, trim($token));
        $operation = match ($action) {
            'voice_profile_upload' => hub_create_uploaded_voice_profile($db, $memberId, is_array($files['reference_wav'] ?? null) ? $files['reference_wav'] : [], [
                'name' => (string)($post['voice_profile_name'] ?? ''),
                'consent_type' => (string)($post['consent_type'] ?? ''),
                'usage_scope' => 'private',
                'visibility' => 'private',
            ]),
            'voice_profile_confirm' => ['profile' => hub_confirm_voice_profile_prompt($db, (int)($post['voice_profile_id'] ?? 0), $memberId, (string)($post['prompt_text'] ?? ''))],
            'voice_profile_retry_asr' => hub_retry_voice_profile_transcription($db, (int)($post['voice_profile_id'] ?? 0), $memberId),
            default => throw new InvalidArgumentException('voice_profile_action_invalid'),
        };

        return hub_playground_voice_profile_result($operation);
    } catch (Throwable) {
        return hub_playground_voice_profile_error_result();
    }
}
