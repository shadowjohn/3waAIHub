<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/public_api_docs.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

header('Content-Type: application/json; charset=utf-8');
if (!hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST')) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'public_docs_forbidden',
        'message' => 'Public API manifest is disabled or local-only.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(hub_public_api_manifest($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
