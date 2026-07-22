<?php
declare(strict_types=1);

require_once HUB_ROOT . '/admin/_playground_tts_artifacts.php';
require_once HUB_ROOT . '/admin/_playground_voice_profiles.php';

function hub_test_install_playground_tts(PDO $db, string $serviceKey): array
{
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => $serviceKey,
        'mode' => 'tts',
        'name' => 'Playground TTS test',
        'port_mode' => 'manual',
        'local_port' => 18108,
    ]);
    hub_set_service_enabled($db, 'tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');

    return $installed['service'];
}

hub_test('playground internal TTS gateway context preserves client token IP checks', function (): void {
    $db = hub_test_reset_db();
    $service = hub_test_install_playground_tts($db, 'playground-context-tts');
    $memberId = hub_create_api_member($db, 'Playground IP Owner');
    $token = hub_create_api_token($db, $memberId, 'IP restricted TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts', (int)$service['id']);
    hub_add_api_token_ip_rule($db, (int)$token['token_id'], '203.0.113.80', 'playground source');
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');

    $oldServer = $_SERVER;
    $_SERVER = [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.80',
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/3waAIHub/admin/playground.php',
    ];
    $requesterCalls = 0;
    $requester = static function () use (&$requesterCalls): array {
        $requesterCalls++;
        return hub_gateway_json(200, ['ok' => true, 'artifact_url' => '/artifacts/tts_context.wav']);
    };
    $context = [
        'client_ip' => '203.0.113.80',
        'bearer_token' => (string)$token['plain_token'],
        'raw_body' => json_encode(['mode' => 'design', 'text' => 'context check'], JSON_UNESCAPED_UNICODE),
        'method' => 'POST',
        'request_uri' => '/3waAIHub/api.php?mode=tts',
    ];

    try {
        $allowed = hub_gateway_dispatch($db, 'tts', $requester, $context);
        hub_test_assert($allowed['status'] === 200 && $requesterCalls === 1, 'allowed source IP must reach the TTS requester once');
        $allowedLog = $db->query('SELECT client_ip, token_id, method FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
        hub_test_assert(($allowedLog['client_ip'] ?? '') === '203.0.113.80', 'internal TTS request must log the real client IP, not loopback');
        hub_test_assert((int)($allowedLog['token_id'] ?? 0) === (int)$token['token_id'], 'internal TTS request must authenticate the supplied bearer token');
        hub_test_assert(($allowedLog['method'] ?? '') === 'POST', 'internal TTS request must retain its server-built method');

        $deniedContext = $context;
        $deniedContext['client_ip'] = '198.51.100.90';
        $denied = hub_gateway_dispatch($db, 'tts', $requester, $deniedContext);
        $deniedBody = json_decode((string)$denied['body'], true);
        hub_test_assert($denied['status'] === 403 && ($deniedBody['error'] ?? '') === 'token_ip_not_allowed', 'disallowed source IP must be rejected by the token rule');
        hub_test_assert($requesterCalls === 1, 'disallowed source IP must not reach the TTS requester');
        $deniedLog = $db->query('SELECT client_ip FROM api_access_logs ORDER BY id DESC LIMIT 1')->fetch();
        hub_test_assert(($deniedLog['client_ip'] ?? '') === '198.51.100.90', 'denied internal request must not log loopback or forwarded headers');
    } finally {
        $_SERVER = $oldServer;
    }
});

hub_test('playground TTS artifact mapping limits downloads to the generating API member', function (): void {
    hub_test_assert(function_exists('hub_playground_register_tts_artifact'), 'playground TTS artifact registration helper is missing');
    hub_test_assert(function_exists('hub_playground_tts_artifact_access_allowed'), 'playground TTS artifact authorization helper is missing');

    $db = hub_test_reset_db();
    hub_migrate($db);
    $service = hub_test_install_playground_tts($db, 'playground-artifact-tts');
    $ownerMemberId = hub_create_api_member($db, 'Artifact Owner');
    $foreignMemberId = hub_create_api_member($db, 'Artifact Foreign Member');
    $result = [
        'ok' => true,
        'request_id' => 'req_playground_artifact',
        'body' => json_encode(['artifact_url' => '/artifacts/tts_owner.wav']),
    ];

    hub_test_assert(hub_playground_register_tts_artifact($db, $service, $result, $ownerMemberId), 'successful allowlisted TTS artifact must be registered');
    hub_test_assert(
        !hub_playground_register_tts_artifact($db, $service, array_replace($result, ['request_id' => 'req_collision']), $foreignMemberId),
        'filename collision must not replace an existing artifact owner'
    );
    hub_test_assert(
        hub_playground_tts_artifact_access_allowed($db, ['role' => 'customer', 'api_member_id' => $ownerMemberId], $service, 'tts_owner.wav'),
        'generating member must be allowed to fetch its mapped artifact'
    );
    hub_test_assert(
        !hub_playground_tts_artifact_access_allowed($db, ['role' => 'customer', 'api_member_id' => $foreignMemberId], $service, 'tts_owner.wav'),
        'another member must not fetch the owner artifact'
    );
    hub_test_assert(
        !hub_playground_tts_artifact_access_allowed($db, ['role' => 'customer', 'api_member_id' => $ownerMemberId], $service, 'tts_old.wav'),
        'unknown old artifact must be denied'
    );
    hub_test_assert(
        hub_playground_tts_artifact_access_allowed($db, ['role' => 'system_admin'], $service, 'tts_owner.wav'),
        'system admin must retain the artifact download override'
    );
    hub_test_assert(
        !hub_playground_register_tts_artifact($db, $service, ['ok' => false, 'body' => json_encode(['artifact_url' => '/artifacts/tts_failed.wav'])], $ownerMemberId),
        'failed TTS results must not create artifact mappings'
    );

    $mapping = $db->query('SELECT filename, service_id, owner_member_id, request_id, created_at FROM playground_tts_artifacts')->fetch();
    hub_test_assert(($mapping['filename'] ?? '') === 'tts_owner.wav', 'artifact mapping must store only the allowlisted filename');
    hub_test_assert((int)($mapping['service_id'] ?? 0) === (int)$service['id'] && (int)($mapping['owner_member_id'] ?? 0) === $ownerMemberId, 'artifact mapping must bind service and owner');
    hub_test_assert(($mapping['request_id'] ?? '') === 'req_playground_artifact' && ($mapping['created_at'] ?? '') !== '', 'artifact mapping must retain request provenance');

    $endpoint = (string)file_get_contents(HUB_ROOT . '/admin/playground_artifact.php');
    hub_test_assert(str_contains($endpoint, 'hub_playground_tts_artifact_access_allowed') && str_contains($endpoint, 'http_response_code(404)'), 'artifact endpoint must deny unmapped or foreign artifacts as not found');
});

hub_test('playground owner-only draft prefill keeps transcript out of action results', function (): void {
    hub_test_assert(function_exists('hub_playground_voice_profile_draft_prefill'), 'playground voice-profile draft prefill helper is missing');

    $db = hub_test_reset_db();
    $ownerMemberId = hub_create_api_member($db, 'Draft Owner');
    $foreignMemberId = hub_create_api_member($db, 'Draft Foreign Member');
    $ownerToken = hub_create_api_token($db, $ownerMemberId, 'Draft owner token', null, null);
    $foreignToken = hub_create_api_token($db, $foreignMemberId, 'Draft foreign token', null, null);
    hub_add_api_token_mode_permission($db, (int)$ownerToken['token_id'], 'tts');
    hub_add_api_token_mode_permission($db, (int)$foreignToken['token_id'], 'tts');
    $draft = '只可由擁有者檢閱的 ASR 草稿';
    $path = hub_voice_profile_storage_dir() . '/playground_draft_prefill.wav';
    file_put_contents($path, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $ownerMemberId, [
        'name' => 'Shared-but-owner-managed draft',
        'reference_audio_path' => $path,
        'prompt_text' => $draft,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'shared',
    ]);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.91';

    try {
        hub_test_assert(
            hub_playground_voice_profile_draft_prefill($db, (string)$ownerToken['plain_token'], $profileId) === $draft,
            'owner TTS token must receive its draft only for confirmation prefill'
        );
        hub_test_assert(
            hub_playground_voice_profile_draft_prefill($db, (string)$foreignToken['plain_token'], $profileId) === null,
            'shared-profile consumer must not retrieve the owner draft'
        );
        $safeResult = hub_playground_voice_profile_draft_result();
        foreach ([(string)($safeResult['pretty_body'] ?? ''), json_encode($safeResult)] as $body) {
            hub_test_assert(!str_contains((string)$body, $draft), 'draft-load action result must stay redacted');
        }
    } finally {
        $_SERVER = $oldServer;
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $ownerMemberId, true);
        }
    }

    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($playground, 'load_voice_profile_draft') && str_contains($playground, 'hub_h($voiceProfileDraftPrefill)'), 'draft action must be CSRF-rendered into the confirmation textarea without browser-side transcript handling');
});
