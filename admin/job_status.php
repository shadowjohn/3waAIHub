<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

$db = hub_db();
hub_require_system_admin($db);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$payload = hub_command_job_status_payload($db, (int)($_GET['job_id'] ?? 0));
if (!$payload) {
    http_response_code(404);
    echo hub_json_encode(['ok' => false, 'error' => 'job not found'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

echo hub_json_encode(['ok' => true, 'job' => $payload], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
