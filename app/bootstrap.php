<?php
declare(strict_types=1);

define('HUB_ROOT', dirname(__DIR__));
define('HUB_DATA_DIR', HUB_ROOT . '/data');
$hubDbPath = getenv('AIHUB_TEST_DB') ?: HUB_DATA_DIR . '/3waaihub.sqlite';
define('HUB_DB_PATH', $hubDbPath);
define('HUB_LOG_DIR', HUB_DATA_DIR . '/logs');
define('HUB_JOB_LOG_DIR', HUB_LOG_DIR . '/jobs');
define('HUB_TASK_LOG_DIR', HUB_LOG_DIR . '/tasks');
define('HUB_SERVICE_DIR', HUB_DATA_DIR . '/services');
define('HUB_VERSION', 'v0.2.x');
define('HUB_RELEASE_LABEL', 'Local Catalog + Token Auth MVP');

date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/model_registry.php';
require_once __DIR__ . '/service_repo.php';
require_once __DIR__ . '/port_policy.php';
require_once __DIR__ . '/pack_registry.php';
require_once __DIR__ . '/service_settings.php';
require_once __DIR__ . '/command_queue.php';
require_once __DIR__ . '/task_queue.php';
require_once __DIR__ . '/docparser.php';
require_once __DIR__ . '/api_access.php';
require_once __DIR__ . '/api_tokens.php';
require_once __DIR__ . '/voice_profiles.php';
require_once __DIR__ . '/photo_assets.php';
require_once __DIR__ . '/customer_accounts.php';
require_once __DIR__ . '/catalog_show.php';
require_once __DIR__ . '/environment_probe.php';
require_once __DIR__ . '/host_metrics.php';
require_once __DIR__ . '/benchmarks.php';
require_once __DIR__ . '/docker_runner.php';
require_once __DIR__ . '/gateway.php';

function hub_ensure_runtime_dirs(): void
{
    foreach ([HUB_DATA_DIR, HUB_LOG_DIR, HUB_JOB_LOG_DIR, HUB_TASK_LOG_DIR, HUB_DATA_DIR . '/jobs', HUB_DATA_DIR . '/results', HUB_DATA_DIR . '/uploads', HUB_DATA_DIR . '/uploads/voice_profiles', HUB_DATA_DIR . '/uploads/photo', HUB_DATA_DIR . '/cache', HUB_LOG_DIR . '/install', HUB_SERVICE_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create runtime directory: ' . $dir);
        }
    }
}

function hub_path(string $path): string
{
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return HUB_ROOT . '/' . ltrim($path, '/');
}

function hub_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hub_now(): string
{
    return date('Y-m-d H:i:s');
}

function hub_start_session(): void
{
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function hub_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function hub_cli_only(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('CLI only');
    }
}

hub_i18n_apply_request_language();
