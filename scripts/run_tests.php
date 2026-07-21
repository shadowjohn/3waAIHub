<?php
declare(strict_types=1);

define('HUB_TESTING', true);
putenv('AIHUB_TEST_DB=' . (getenv('AIHUB_TEST_DB') ?: sys_get_temp_dir() . '/3waaihub_test.sqlite'));

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$tests = [];
$failures = 0;
$skipped = 0;

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
        echo '[PASS] ' . $name . PHP_EOL;
    } catch (HubTestSkipped $e) {
        $skipped++;
        echo '[SKIP] ' . $name . ': ' . $e->getMessage() . PHP_EOL;
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

echo 'tests=' . count($tests) . ' failures=' . $failures . ' skipped=' . $skipped . PHP_EOL;
exit($failures === 0 ? 0 : 1);
