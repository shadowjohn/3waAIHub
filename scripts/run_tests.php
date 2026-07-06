<?php
declare(strict_types=1);

putenv('AIHUB_TEST_DB=' . (getenv('AIHUB_TEST_DB') ?: sys_get_temp_dir() . '/3waaihub_test.sqlite'));

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$tests = [];
$failures = 0;

function hub_test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function hub_test_reset_db(): PDO
{
    foreach ([HUB_DB_PATH, HUB_DB_PATH . '-wal', HUB_DB_PATH . '-shm'] as $path) {
        if (is_file($path)) {
            unlink($path);
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
    } catch (Throwable $e) {
        $failures++;
        echo '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL;
    }
}

echo 'tests=' . count($tests) . ' failures=' . $failures . PHP_EOL;
exit($failures === 0 ? 0 : 1);
