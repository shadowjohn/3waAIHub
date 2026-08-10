<?php
declare(strict_types=1);

define('HUB_ROOT', dirname(__DIR__));
const HUB_RUNTIME_SETTINGS_FILENAME = 'runtime-settings.conf';
const HUB_LEGACY_RUNTIME_ENV_FILENAME = '.env';
$hubDbPath = trim((string)(getenv('AIHUB_TEST_DB') ?: ''));
$hubTestDataDir = trim((string)(getenv('AIHUB_TEST_DATA_DIR') ?: ''));
$hubDataDir = HUB_ROOT . '/data';
$hubTestDataDirActive = false;

if ($hubDbPath !== '' && $hubTestDataDir !== '') {
    $hubTempRoot = realpath(sys_get_temp_dir());
    $hubNormalizedTestDataDir = rtrim(str_replace('\\', '/', $hubTestDataDir), '/');
    $hubNormalizedTempRoot = rtrim(str_replace('\\', '/', $hubTempRoot !== false ? $hubTempRoot : sys_get_temp_dir()), '/');

    if (
        dirname($hubNormalizedTestDataDir) !== $hubNormalizedTempRoot
        || preg_match('/^3waaihub_test_data_[a-f0-9]{32}$/', basename($hubNormalizedTestDataDir)) !== 1
    ) {
        throw new RuntimeException('AIHUB_TEST_DATA_DIR must be a dedicated directory beneath the system temp root.');
    }

    $hubDataDir = $hubNormalizedTestDataDir;
    $hubTestDataDirActive = true;
}

define('HUB_DATA_DIR', $hubDataDir);
define('HUB_TEST_DATA_DIR_ACTIVE', $hubTestDataDirActive);
define('HUB_SESSION_DIR', HUB_DATA_DIR . '/sessions');
$hubDbPath = $hubDbPath !== '' ? $hubDbPath : HUB_DATA_DIR . '/3waaihub.sqlite';
define('HUB_DB_PATH', $hubDbPath);
define('HUB_LOG_DIR', HUB_DATA_DIR . '/logs');
define('HUB_JOB_LOG_DIR', HUB_LOG_DIR . '/jobs');
define('HUB_TASK_LOG_DIR', HUB_LOG_DIR . '/tasks');
define('HUB_SERVICE_DIR', HUB_DATA_DIR . '/services');
define('HUB_VERSION', '20260729001');
define('HUB_RELEASE_LABEL', '8/7 Admin Market + Cluster Dashboard Preview');

date_default_timezone_set('Asia/Taipei');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/runtime_portability.php';
require_once __DIR__ . '/runtime_worker.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/model_registry.php';
require_once __DIR__ . '/service_repo.php';
require_once __DIR__ . '/port_policy.php';
require_once __DIR__ . '/pack_registry.php';
require_once __DIR__ . '/service_settings.php';
require_once __DIR__ . '/command_queue.php';
require_once __DIR__ . '/task_queue.php';
require_once __DIR__ . '/task_callbacks.php';
require_once __DIR__ . '/sam3_sources.php';
require_once __DIR__ . '/facebook_crawler.php';
require_once __DIR__ . '/web_capture.php';
require_once __DIR__ . '/pack_job_runner.php';
require_once __DIR__ . '/edge_tts_voices.php';
require_once __DIR__ . '/docparser.php';
require_once __DIR__ . '/api_access.php';
require_once __DIR__ . '/api_tokens.php';
require_once __DIR__ . '/voice_profiles.php';
require_once __DIR__ . '/voice_profile_tasks.php';
require_once __DIR__ . '/photo_assets.php';
require_once __DIR__ . '/audio_assets.php';
require_once __DIR__ . '/customer_accounts.php';
require_once __DIR__ . '/catalog_show.php';
require_once __DIR__ . '/environment_probe.php';
require_once __DIR__ . '/host_metrics.php';
require_once __DIR__ . '/benchmarks.php';
require_once __DIR__ . '/docker_runner.php';
require_once __DIR__ . '/facebook_crawler_login.php';
require_once __DIR__ . '/release.php';
require_once __DIR__ . '/gateway.php';
require_once __DIR__ . '/cluster_router.php';

function hub_ensure_runtime_dirs(): void
{
    $facebookProfileParent = HUB_DATA_DIR . '/facebook-crawler';
    $facebookProfileRoot = HUB_DATA_DIR . '/facebook-crawler/profiles';
    foreach ([HUB_DATA_DIR, HUB_SESSION_DIR, HUB_LOG_DIR, HUB_JOB_LOG_DIR, HUB_TASK_LOG_DIR, HUB_DATA_DIR . '/jobs', HUB_DATA_DIR . '/results', HUB_DATA_DIR . '/uploads', HUB_DATA_DIR . '/uploads/voice_profiles', HUB_DATA_DIR . '/uploads/photo', HUB_DATA_DIR . '/uploads/audio', HUB_DATA_DIR . '/cache', HUB_LOG_DIR . '/install', HUB_SERVICE_DIR] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create runtime directory: ' . $dir);
        }
    }

    if (
        is_link($facebookProfileParent)
        || (file_exists($facebookProfileParent) && !is_dir($facebookProfileParent))
        || is_link($facebookProfileRoot)
        || (file_exists($facebookProfileRoot) && !is_dir($facebookProfileRoot))
        || (!is_dir($facebookProfileRoot) && !mkdir($facebookProfileRoot, 0700, true))
    ) {
        throw new RuntimeException('Cannot secure Facebook crawler profile directory.');
    }
    // NTFS ACLs handle Windows privacy; PHP cannot verify POSIX 0700 there.
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }
    clearstatcache(true, $facebookProfileParent);
    clearstatcache(true, $facebookProfileRoot);
    $facebookProfileMode = @fileperms($facebookProfileRoot);
    $facebookProfileModeNeedsRepair = $facebookProfileMode !== false
        && (((int)$facebookProfileMode & 0777) !== 0700);
    if (is_link($facebookProfileParent) || is_link($facebookProfileRoot) || $facebookProfileMode === false
        || ($facebookProfileModeNeedsRepair && !@chmod($facebookProfileRoot, 0700))
    ) {
        throw new RuntimeException('Cannot secure Facebook crawler profile directory.');
    }
    clearstatcache(true, $facebookProfileRoot);
    if ((((int)@fileperms($facebookProfileRoot)) & 0777) !== 0700) {
        throw new RuntimeException('Cannot secure Facebook crawler profile directory.');
    }
}

function hub_path(string $path): string
{
    if (hub_is_host_absolute_path($path)) {
        return hub_normalize_host_path($path);
    }

    $relativePath = ltrim($path, '/');
    if (HUB_TEST_DATA_DIR_ACTIVE && ($relativePath === 'data' || str_starts_with($relativePath, 'data/'))) {
        return HUB_DATA_DIR . substr($relativePath, 4);
    }

    return HUB_ROOT . '/' . $relativePath;
}

function hub_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * JSON 回應與 application/json script block 共用的編碼邊界。
 * 即使呼叫端要求保留 Unicode 或 slash，HTML delimiter 仍一律轉成 Unicode escape。
 */
function hub_json_encode(mixed $value, int $flags = 0): string|false
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | $flags);
}

/**
 * 內部展示頁只可呼叫同一台主機的 Gateway。基底路徑雖來自 Web server，
 * 仍在組成 URL 前拒絕 query、fragment、控制字元與 traversal，避免它成為 SSRF 轉送器。
 */
function hub_local_gateway_url(string $basePath, string $mode): string
{
    $mode = trim($mode);
    if (preg_match('/\A[a-z0-9_]{1,80}\z/D', $mode) !== 1) {
        throw new InvalidArgumentException('Gateway mode is invalid.');
    }

    $basePath = str_replace('\\', '/', trim($basePath));
    if (
        ($basePath !== '' && !str_starts_with($basePath, '/'))
        || preg_match('/[\x00-\x1F\x7F?#]/', $basePath) === 1
        || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $basePath) === 1
    ) {
        throw new InvalidArgumentException('Gateway base path is invalid.');
    }
    $basePath = rtrim($basePath, '/');
    $url = 'http://127.0.0.1' . $basePath . '/api.php?mode=' . rawurlencode($mode);
    $parts = parse_url($url);
    parse_str((string)($parts['query'] ?? ''), $query);

    if (
        $parts === false
        || ($parts['scheme'] ?? null) !== 'http'
        || ($parts['host'] ?? null) !== '127.0.0.1'
        || isset($parts['port'], $parts['user'], $parts['pass'], $parts['fragment'])
        || !is_array($query)
        || $query !== ['mode' => $mode]
        || !str_ends_with((string)($parts['path'] ?? ''), '/api.php')
    ) {
        throw new InvalidArgumentException('Gateway loopback URL is invalid.');
    }

    return $url;
}

function hub_now(): string
{
    return date('Y-m-d H:i:s');
}

function hub_start_session(): void
{
    if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
        if (!is_dir(HUB_SESSION_DIR) && !mkdir(HUB_SESSION_DIR, 0775, true) && !is_dir(HUB_SESSION_DIR)) {
            throw new RuntimeException('Cannot create session directory: ' . HUB_SESSION_DIR);
        }
        session_save_path(HUB_SESSION_DIR);
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
