<?php
declare(strict_types=1);

function hub_playground_tts_artifact_file(array $result): string
{
    $payload = json_decode((string)($result['body'] ?? ''), true);
    $artifactUrl = is_array($payload) ? (string)($payload['artifact_url'] ?? '') : '';
    if ($artifactUrl === '' || !str_starts_with($artifactUrl, '/artifacts/')) {
        return '';
    }
    $file = basename($artifactUrl);
    return preg_match('/^tts_[A-Za-z0-9_-]+\.wav$/', $file) === 1 ? $file : '';
}

function hub_playground_tts_audio_url(array $service, ?array $result): string
{
    if ($result === null || empty($result['ok'])) {
        return '';
    }
    $file = hub_playground_tts_artifact_file($result);
    if ($file === '') {
        return '';
    }

    return 'playground_artifact.php?' . http_build_query([
        'service_id' => (int)$service['id'],
        'file' => $file,
    ]);
}

function hub_playground_register_tts_artifact(PDO $db, array $service, array $result, int $ownerMemberId): bool
{
    $file = hub_playground_tts_artifact_file($result);
    $requestId = trim((string)($result['request_id'] ?? ''));
    if (empty($result['ok']) || $file === '' || $requestId === '' || $ownerMemberId < 1 || (int)($service['id'] ?? 0) < 1 || (string)($service['pack_id'] ?? '') !== 'tts-voxcpm2') {
        return false;
    }

    $now = hub_now();
    $stmt = $db->prepare(
        'INSERT OR IGNORE INTO playground_tts_artifacts (filename, service_id, owner_member_id, request_id, created_at, updated_at)
         VALUES (:filename, :service_id, :owner_member_id, :request_id, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':filename' => $file,
        ':service_id' => (int)$service['id'],
        ':owner_member_id' => $ownerMemberId,
        ':request_id' => substr($requestId, 0, 128),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return $stmt->rowCount() === 1;
}

function hub_playground_tts_artifact_access_allowed(PDO $db, array $user, array $service, string $file): bool
{
    if (hub_is_system_admin($user)) {
        return true;
    }
    $memberId = hub_current_api_member_id($user);
    if ($memberId === null || hub_playground_tts_artifact_file(['body' => json_encode(['artifact_url' => '/artifacts/' . basename($file)])]) === '') {
        return false;
    }

    $stmt = $db->prepare(
        'SELECT 1 FROM playground_tts_artifacts
         WHERE filename = :filename AND service_id = :service_id AND owner_member_id = :owner_member_id'
    );
    $stmt->execute([
        ':filename' => basename($file),
        ':service_id' => (int)($service['id'] ?? 0),
        ':owner_member_id' => $memberId,
    ]);

    return (bool)$stmt->fetchColumn();
}
