<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/_playground_tts_artifacts.php';

function hub_playground_artifact_path(array $service, string $file): ?string
{
    $file = basename($file);
    if (preg_match('/^tts_[A-Za-z0-9_-]+\.wav$/', $file) !== 1) {
        return null;
    }
    $base = realpath(dirname(hub_path((string)$service['compose_file'])) . '/artifacts');
    $path = $base === false ? false : realpath($base . '/' . $file);
    if ($base === false || $path === false || !is_file($path)) {
        return null;
    }

    return str_starts_with($path, $base . DIRECTORY_SEPARATOR) ? $path : null;
}

$db = hub_db();
hub_migrate($db);
$user = hub_require_login($db);
$service = hub_get_service($db, (int)($_GET['service_id'] ?? 0));
if (!$service || (string)($service['pack_id'] ?? '') !== 'tts-voxcpm2') {
    http_response_code(404);
    exit('not found');
}

$path = hub_playground_artifact_path($service, (string)($_GET['file'] ?? ''));
if ($path === null || !hub_playground_tts_artifact_access_allowed($db, $user, $service, (string)($_GET['file'] ?? ''))) {
    http_response_code(404);
    exit('not found');
}

header('Content-Type: audio/wav');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
