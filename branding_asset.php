<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$asset = hub_branding_active_asset($db);
$handle = @fopen($asset['path'], 'rb');
if (!is_resource($handle) && $asset['managed']) {
    $asset = hub_branding_default_asset();
    $handle = @fopen($asset['path'], 'rb');
}
if (!is_resource($handle)) {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    exit;
}

$stat = fstat($handle);
$hash = hash_init('sha256');
if (!is_array($stat) || !isset($stat['size']) || $stat['size'] < 0 || hash_update_stream($hash, $handle) === false) {
    fclose($handle);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    http_response_code(404);
    exit;
}
$etag = '"' . hash_final($hash) . '"';
rewind($handle);

header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    fclose($handle);
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $asset['mime']);
header('Content-Length: ' . (string)$stat['size']);
fpassthru($handle);
fclose($handle);
