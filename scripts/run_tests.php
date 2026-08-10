<?php
declare(strict_types=1);

define('HUB_TESTING', true);
$testRunId = bin2hex(random_bytes(16));
putenv('AIHUB_TEST_DB=' . (getenv('AIHUB_TEST_DB') ?: sys_get_temp_dir() . '/3waaihub_test_' . $testRunId . '.sqlite'));
putenv('AIHUB_TEST_DATA_DIR=' . (getenv('AIHUB_TEST_DATA_DIR') ?: sys_get_temp_dir() . '/3waaihub_test_data_' . $testRunId));

$suite = 'full';
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--suite=')) {
        $suite = substr($argument, strlen('--suite='));
        continue;
    }

    fwrite(STDERR, 'Unknown argument: ' . $argument . PHP_EOL);
    echo 'suite=' . $suite . ' tests=0 failures=1 skipped=0' . PHP_EOL;
    exit(2);
}

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

function hub_test_release_failure_context(?Throwable &$error): void
{
    $error = null;
    gc_collect_cycles();
}

/**
 * Windows 的 SQLite 連線會被 closure capture 持有到 runner 結束；每筆測試結束後
 * 立即移除註冊 closure，讓下一筆測試能安全重建同一個暫存資料庫。
 *
 * @param array<string, callable> $registry
 */
function hub_test_release_completed_test(array &$registry, string $name, ?callable &$test): void
{
    unset($registry[$name]);
    $test = null;
    gc_collect_cycles();
}

function hub_test_symlink_fixture_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $root = sys_get_temp_dir() . '/3waaihub_symlink_probe_' . bin2hex(random_bytes(12));
    $fileTarget = $root . '/target.txt';
    $fileLink = $root . '/file-link.txt';
    $directoryTarget = $root . '/target-dir';
    $directoryLink = $root . '/directory-link';
    if (!mkdir($directoryTarget, 0700, true) || file_put_contents($fileTarget, 'probe') === false) {
        throw new RuntimeException('Cannot create symlink capability probe.');
    }

    $fileCreated = @symlink($fileTarget, $fileLink);
    $directoryCreated = @symlink($directoryTarget, $directoryLink);
    $available = $fileCreated && $directoryCreated;

    @unlink($fileLink);
    @unlink($directoryLink);
    @unlink($fileTarget);
    @rmdir($directoryTarget);
    @rmdir($root);

    return $available;
}

function hub_test_require_symlink_fixture(string $reason): void
{
    if (hub_test_symlink_fixture_available()) {
        return;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_skip($reason);
    }

    throw new RuntimeException('Cannot create symlink fixture: ' . $reason);
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
    $snapshotDir = $realDir . '/.snapshots';
    if (file_exists($snapshotDir) || is_link($snapshotDir)) {
        $snapshotReal = realpath($snapshotDir);
        if (is_link($snapshotDir) || !is_dir($snapshotDir) || $snapshotReal === false || !hub_storage_paths_equal($snapshotReal, $snapshotDir)) {
            throw new RuntimeException('Cannot remove isolated test voice profile snapshots.');
        }
        foreach (new FilesystemIterator($snapshotDir, FilesystemIterator::SKIP_DOTS) as $entry) {
            $name = $entry->getFilename();
            $path = $entry->getPathname();
            if (
                preg_match('/^voice_profile_snapshot_[a-f0-9]{32}\.wav$/', $name) !== 1
                || $entry->isLink()
                || !$entry->isFile()
                || !unlink($path)
            ) {
                throw new RuntimeException('Cannot remove isolated test voice profile snapshot: ' . $path);
            }
        }
        if (!rmdir($snapshotDir)) {
            throw new RuntimeException('Cannot remove isolated test voice profile snapshots.');
        }
    }
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
    if (!hub_storage_paths_equal($dir, $realDir)) {
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
    if (!hub_storage_paths_equal($dir, $realDir)) {
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
        $resolved = realpath($path);
        if (
            is_link($path)
            || !is_dir($path)
            || $resolved === false
            || !hub_storage_paths_equal($resolved, $path)
            || !hub_storage_path_is_within($resolved, $dataRoot)
        ) {
            throw new RuntimeException('Test task data reset target is invalid.');
        }
        hub_test_remove_data_tree($path);
    }
    hub_ensure_runtime_dirs();
}

/**
 * Windows 不允許刪除仍被同一筆測試 PDO 持有的 SQLite 檔案。此處只在測試資料庫
 * 的刪檔失敗時，以新的連線刪除受信任 sqlite_master 列出的 schema 物件；若連線
 * 仍有鎖定，回傳 false 讓原本的 reset 失敗訊息保留，不會把不完整 reset 當成功。
 */
function hub_test_rebuild_locked_sqlite_schema(): bool
{
    if (PHP_OS_FAMILY !== 'Windows' || !is_file(HUB_DB_PATH)) {
        return false;
    }

    $db = null;
    try {
        $db = new PDO('sqlite:' . HUB_DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA foreign_keys = OFF');
        $objects = $db->query(
            "SELECT type, name
             FROM sqlite_master
             WHERE type IN ('table', 'view', 'trigger')
               AND name NOT LIKE 'sqlite_%'
             ORDER BY CASE type WHEN 'trigger' THEN 1 WHEN 'view' THEN 2 ELSE 3 END"
        )->fetchAll();
        foreach ($objects as $object) {
            $type = (string)($object['type'] ?? '');
            $name = (string)($object['name'] ?? '');
            if (!in_array($type, ['table', 'view', 'trigger'], true) || $name === '') {
                return false;
            }
            $db->exec('DROP ' . strtoupper($type) . ' IF EXISTS ' . hub_sqlite_schema_identifier($name));
        }
        $db->exec('PRAGMA foreign_keys = ON');
        return true;
    } catch (Throwable) {
        return false;
    } finally {
        $db = null;
        gc_collect_cycles();
    }
}

function hub_test_reset_db(): PDO
{
    // Windows 需先釋放上一個測試結束後的 PDO 循環參考，否則 SQLite 檔可能仍被鎖住。
    gc_collect_cycles();
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    foreach (['CONTENT_LENGTH', 'CONTENT_TYPE', 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR', 'REQUEST_METHOD', 'REQUEST_URI'] as $key) {
        unset($_SERVER[$key]);
    }
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
    $databaseRemoved = !is_file(HUB_DB_PATH) || @unlink(HUB_DB_PATH);
    if (!$databaseRemoved && !hub_test_rebuild_locked_sqlite_schema()) {
        throw new RuntimeException('Cannot reset test SQLite file: ' . HUB_DB_PATH);
    }
    if ($databaseRemoved) {
        foreach ([HUB_DB_PATH . '-wal', HUB_DB_PATH . '-shm'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Cannot reset test SQLite file: ' . $path);
            }
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

function hub_test_suite_files(string $suite): array
{
    if ($suite === 'full') {
        return glob(HUB_ROOT . '/tests/test_*.php') ?: [];
    }

    if (!in_array($suite, ['control-plane', 'admin-ui', 'voice-cluster'], true)) {
        throw new InvalidArgumentException('Unknown suite: ' . $suite);
    }

    $manifestPath = HUB_ROOT . '/tests/suites/' . $suite . '.php';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Suite manifest is missing: ' . $suite);
    }
    $files = require $manifestPath;
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('Suite manifest must return a non-empty file list: ' . $suite);
    }

    $testsRoot = realpath(HUB_ROOT . '/tests');
    if ($testsRoot === false) {
        throw new RuntimeException('Tests directory is missing.');
    }
    $normalize = static function (string $path): string {
        $path = str_replace('\\', '/', $path);
        return hub_platform_id() === 'windows' ? strtolower($path) : $path;
    };
    $testsPrefix = rtrim($normalize($testsRoot), '/') . '/';
    $seen = [];
    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            throw new RuntimeException('Suite manifest references a missing regular file.');
        }
        $realFile = realpath($file);
        if ($realFile === false || !str_starts_with($normalize($realFile), $testsPrefix)) {
            throw new RuntimeException('Suite manifest file must stay inside tests/.');
        }
        $key = $normalize($realFile);
        if (isset($seen[$key])) {
            throw new RuntimeException('Suite manifest contains a duplicate test file: ' . basename($realFile));
        }
        $seen[$key] = true;
    }

    return $files;
}

try {
    $testFiles = hub_test_suite_files($suite);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    echo 'suite=' . $suite . ' tests=0 failures=1 skipped=0' . PHP_EOL;
    exit(2);
}

foreach ($testFiles as $file) {
    require $file;
}

$testCount = count($tests);
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
        hub_test_release_failure_context($e);
    } catch (Throwable $e) {
        $failures++;
        echo '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL;
        hub_test_release_failure_context($e);
    } finally {
        hub_test_release_completed_test($tests, $name, $fn);
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

echo 'suite=' . $suite . ' tests=' . $testCount . ' failures=' . $failures . ' skipped=' . $skipped . PHP_EOL;
exit($failures === 0 ? 0 : 1);
