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
