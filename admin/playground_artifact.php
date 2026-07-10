<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

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

$allowed = hub_is_system_admin($user);
if (!$allowed) {
    foreach (hub_playground_service_options($db, $user) as $allowedService) {
        if ((int)$allowedService['id'] === (int)$service['id']) {
            $allowed = true;
            break;
        }
    }
}
if (!$allowed) {
    http_response_code(403);
    exit('forbidden');
}

$path = hub_playground_artifact_path($service, (string)($_GET['file'] ?? ''));
if ($path === null) {
    http_response_code(404);
    exit('not found');
}

header('Content-Type: audio/wav');
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
