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
