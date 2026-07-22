<?php
declare(strict_types=1);

define('HUB_TESTING', true);
putenv('AIHUB_TEST_DB=' . (getenv('AIHUB_TEST_DB') ?: sys_get_temp_dir() . '/3waaihub_test.sqlite'));
putenv('AIHUB_TEST_DATA_DIR=' . (getenv('AIHUB_TEST_DATA_DIR') ?: sys_get_temp_dir() . '/3waaihub_test_data_' . bin2hex(random_bytes(16))));

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();
hub_ensure_runtime_dirs();

$tests = [];
$failures = 0;
$skipped = 0;
$testQuiet = getenv('AIHUB_TEST_QUIET') === '1';

final class HubTestSkipped extends RuntimeException
{
}

function hub_test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function hub_test_skip(string $reason): never
{
    throw new HubTestSkipped($reason);
}

function hub_test_voice_profile_cleanup_dir(?string $dir = null): string
{
    $dir ??= hub_voice_profile_storage_dir();
    $tempRoot = realpath(sys_get_temp_dir());
    $productionDir = realpath(HUB_DATA_DIR . '/uploads/voice_profiles');
    if ($tempRoot === false || $productionDir === false || is_link($dir)) {
        throw new RuntimeException('Test voice profile storage must be an isolated directory.');
    }
    $realDir = realpath($dir);
    $tempPrefix = rtrim($tempRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (
        $realDir === false
        || !is_dir($realDir)
        || $realDir === $productionDir
        || !str_starts_with($realDir, $tempPrefix)
    ) {
        throw new RuntimeException('Test voice profile storage must be an isolated directory.');
    }

    return $realDir;
}

function hub_test_remove_voice_profile_storage_dir(string $dir): void
{
    $realDir = hub_test_voice_profile_cleanup_dir($dir);
    foreach (glob($realDir . '/*') ?: [] as $path) {
        if (is_link($path) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException('Cannot remove isolated test voice profile storage: ' . $path);
        }
    }
    if (!rmdir($realDir)) {
        throw new RuntimeException('Cannot remove isolated test voice profile directory.');
    }
}

function hub_test_teardown_voice_profile_storage(): void
{
    $dir = hub_voice_profile_storage_dir();
    $realDir = hub_test_voice_profile_cleanup_dir($dir);
    if ($dir !== $realDir) {
        throw new RuntimeException('Test voice profile storage must be the generated directory.');
    }
    hub_test_remove_voice_profile_storage_dir($realDir);
}

function hub_test_audio_asset_cleanup_dir(?string $dir = null): string
{
    $dir ??= hub_audio_upload_root();
    $tempRoot = realpath(sys_get_temp_dir());
    $productionDir = realpath(HUB_DATA_DIR . '/uploads/audio');
    if ($tempRoot === false || is_link($dir)) {
        throw new RuntimeException('Test audio asset storage must be an isolated directory.');
    }
    $realDir = realpath($dir);
    $tempPrefix = rtrim($tempRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (
        $realDir === false
        || !is_dir($realDir)
        || ($productionDir !== false && $realDir === $productionDir)
        || !str_starts_with($realDir, $tempPrefix)
        || preg_match('/^3waaihub_test_audio_assets_[a-f0-9]{32}$/', basename($realDir)) !== 1
    ) {
        throw new RuntimeException('Test audio asset storage must be an isolated directory.');
    }

    return $realDir;
}

function hub_test_clear_audio_asset_storage(?string $dir = null): void
{
    $realDir = hub_test_audio_asset_cleanup_dir($dir);
    foreach (glob($realDir . '/*') ?: [] as $assetDir) {
        if (is_link($assetDir) || !is_dir($assetDir) || preg_match('/^aud_[A-Za-z0-9_-]{20,64}$/', basename($assetDir)) !== 1) {
            throw new RuntimeException('Cannot remove isolated test audio asset storage: ' . $assetDir);
        }
        foreach (glob($assetDir . '/*') ?: [] as $path) {
            if (is_link($path) || !is_file($path) || basename($path) !== 'original.wav' || !unlink($path)) {
                throw new RuntimeException('Cannot remove isolated test audio asset: ' . $path);
            }
        }
        if (!rmdir($assetDir)) {
            throw new RuntimeException('Cannot remove isolated test audio asset directory.');
        }
    }
}

function hub_test_teardown_audio_asset_storage(): void
{
    $dir = hub_audio_upload_root();
    $realDir = hub_test_audio_asset_cleanup_dir($dir);
    if ($dir !== $realDir) {
        throw new RuntimeException('Test audio asset storage must be the generated directory.');
    }
    hub_test_clear_audio_asset_storage($realDir);
    if (!rmdir($realDir)) {
        throw new RuntimeException('Cannot remove isolated test audio asset directory.');
    }
}

function hub_test_data_root(): string
{
    $tempRoot = realpath(sys_get_temp_dir());
    $dataRoot = realpath(HUB_DATA_DIR);
    if (
        !HUB_TEST_DATA_DIR_ACTIVE
        || $tempRoot === false
        || $dataRoot === false
        || is_link(HUB_DATA_DIR)
        || dirname($dataRoot) !== $tempRoot
        || preg_match('/^3waaihub_test_data_[a-f0-9]{32}$/', basename($dataRoot)) !== 1
    ) {
        throw new RuntimeException('Test runtime data root must be an isolated temporary directory.');
    }

    return $dataRoot;
}

function hub_test_remove_data_tree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_link($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Cannot remove isolated test symlink: ' . $path);
            }
            continue;
        }
        if (is_dir($path)) {
            hub_test_remove_data_tree($path);
            continue;
        }
        if (!is_file($path) || !unlink($path)) {
            throw new RuntimeException('Cannot remove isolated test data file: ' . $path);
        }
    }

    if (!rmdir($dir)) {
        throw new RuntimeException('Cannot remove isolated test data directory: ' . $dir);
    }
}

function hub_test_teardown_data_root(): void
{
    hub_test_remove_data_tree(hub_test_data_root());
}

function hub_test_clear_data_root(): void
{
    $dataRoot = hub_test_data_root();
    // Only task-ID-addressed data needs clearing between SQLite resets.
    // Keep the rest of the isolated data root intact so tests can prove that
    // test reset does not erase unrelated managed uploads.
    foreach (['uploads/tasks', 'results'] as $relativePath) {
        $path = $dataRoot . DIRECTORY_SEPARATOR . $relativePath;
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            continue;
        }
        if (is_link($path) || !is_dir($path) || realpath($path) !== $path || !str_starts_with($path, $dataRoot . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Test task data reset target is invalid.');
        }
        hub_test_remove_data_tree($path);
    }
    hub_ensure_runtime_dirs();
}

function hub_test_reset_db(): PDO
{
    // Windows 需先釋放上一個測試結束後的 PDO 循環參考，否則 SQLite 檔可能仍被鎖住。
    gc_collect_cycles();
    $testVoiceProfileDir = hub_test_voice_profile_cleanup_dir();
    foreach (glob($testVoiceProfileDir . '/*.wav') ?: [] as $path) {
        if (is_link($path)) {
            throw new RuntimeException('Cannot reset symlinked test voice profile upload: ' . $path);
        }
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Cannot reset test voice profile upload: ' . $path);
        }
    }
    hub_test_clear_audio_asset_storage();
    // SQLite IDs start again from 1 after a reset.  Remove the matching
    // isolated task files as well, otherwise one test can impersonate a
    // stale workspace from a previous fixture.
    hub_test_clear_data_root();
    foreach ([HUB_DB_PATH, HUB_DB_PATH . '-wal', HUB_DB_PATH . '-shm'] as $path) {
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Cannot reset test SQLite file: ' . $path);
        }
    }
    $db = hub_db();
    hub_migrate($db);
    hub_seed_admin_user($db);
    hub_seed_hello_service($db);
    hub_ensure_default_storage_settings($db);
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', hub_test_models_dir());

    return $db;
}

function hub_test_models_dir(): string
{
    static $dir = null;
    if ($dir === null) {
        $dir = getenv('AIHUB_TEST_MODELS_DIR') ?: sys_get_temp_dir() . '/3waaihub_test_models_' . getmypid();
    }
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create test models directory: ' . $dir);
    }

    return $dir;
}

function hub_test_assert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function hub_test_throws(callable $fn): bool
{
    try {
        $fn();
    } catch (Throwable) {
        return true;
    }

    return false;
}

foreach (glob(HUB_ROOT . '/tests/test_*.php') ?: [] as $file) {
    require $file;
}

foreach ($tests as $name => $fn) {
    try {
        $fn();
        if (!$testQuiet) {
            echo '[PASS] ' . $name . PHP_EOL;
        }
    } catch (HubTestSkipped $e) {
        $skipped++;
        if (!$testQuiet) {
            echo '[SKIP] ' . $name . ': ' . $e->getMessage() . PHP_EOL;
        }
    } catch (Throwable $e) {
        $failures++;
        echo '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL;
    }
}

try {
    hub_test_teardown_voice_profile_storage();
} catch (Throwable $e) {
    $failures++;
    echo '[FAIL] Voice profile test storage teardown: ' . $e->getMessage() . PHP_EOL;
}

try {
    hub_test_teardown_audio_asset_storage();
} catch (Throwable $e) {
    $failures++;
    echo '[FAIL] Audio asset test storage teardown: ' . $e->getMessage() . PHP_EOL;
}

try {
    hub_test_teardown_data_root();
} catch (Throwable $e) {
    $failures++;
    echo '[FAIL] Test runtime data teardown: ' . $e->getMessage() . PHP_EOL;
}

echo 'tests=' . count($tests) . ' failures=' . $failures . ' skipped=' . $skipped . PHP_EOL;
exit($failures === 0 ? 0 : 1);
