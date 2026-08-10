<?php
declare(strict_types=1);

hub_test('test runner releases failed PDO references before resetting SQLite', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        hub_test_skip('PDO exception handle retention is specific to Windows SQLite handles.');
    }

    $path = sys_get_temp_dir() . '/3waaihub_runner_recovery_' . bin2hex(random_bytes(12)) . '.sqlite';
    $failure = null;
    try {
        try {
            (static function (string $path): void {
                $db = new PDO('sqlite:' . $path);
                $db->exec('PRAGMA journal_mode = WAL');
                $db->exec('CREATE TABLE probe (id INTEGER)');
                (static function (PDO $connection): void {
                    throw new RuntimeException('intentional test failure');
                })($db);
            })($path);
        } catch (Throwable $e) {
            $failure = $e;
            unset($e);
        }

        hub_test_assert($failure instanceof Throwable, 'fixture must retain a failed PDO call trace');
        hub_test_assert(!@unlink($path), 'captured PDO failure must keep its SQLite file open on Windows');
        hub_test_release_failure_context($failure);
        hub_test_assert(@unlink($path), 'runner must release failed PDO references before the next SQLite reset');
    } finally {
        unset($failure);
        foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
            @unlink($candidate);
        }
    }
});

hub_test('test runner releases completed PDO closures before resetting SQLite', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        hub_test_skip('PDO closure handle retention is specific to Windows SQLite handles.');
    }

    $path = sys_get_temp_dir() . '/3waaihub_runner_closure_' . bin2hex(random_bytes(12)) . '.sqlite';
    $db = new PDO('sqlite:' . $path);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('CREATE TABLE probe (id INTEGER)');
    $registry = ['probe' => static function () use ($db): void {
    }];
    $test = $registry['probe'];
    unset($db);

    try {
        hub_test_assert(!@unlink($path), 'registered test closure must keep its SQLite handle open on Windows');
        hub_test_release_completed_test($registry, 'probe', $test);
        hub_test_assert(!array_key_exists('probe', $registry) && @unlink($path), 'runner must release completed test closures before the next SQLite reset');
    } finally {
        unset($registry, $test);
        foreach ([$path, $path . '-wal', $path . '-shm'] as $candidate) {
            @unlink($candidate);
        }
    }
});

hub_test('test database reset rebuilds a locked Windows SQLite fixture', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        hub_test_skip('Locked SQLite fixture rebuild is specific to Windows file handles.');
    }

    $first = hub_test_reset_db();
    $first->exec('CREATE TABLE runner_reset_probe (id INTEGER)');
    $second = null;
    try {
        $second = hub_test_reset_db();
        $count = (int)$second->query(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'runner_reset_probe'"
        )->fetchColumn();
        hub_test_assert($count === 0, 'locked Windows reset must rebuild a fresh fixture schema');
    } finally {
        unset($first, $second);
    }
});

hub_test('Windows voice profile storage accepts normalized managed paths without POSIX mode bits', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        hub_test_skip('Windows path and ACL semantics are not exercised on this host.');
    }

    $root = hub_voice_profile_storage_dir();
    $path = $root . '/windows_voice_profile_path.wav';
    $outside = tempnam(sys_get_temp_dir(), '3waaihub_voice_outside_');
    if ($outside === false) {
        throw new RuntimeException('Cannot create unmanaged voice profile fixture.');
    }
    file_put_contents($path, 'RIFFmanaged-voice-profile');
    try {
        hub_test_assert(hub_voice_profile_safe_host_path($path) !== null, 'mixed Windows separators must retain a managed voice profile path');
        hub_test_assert(hub_voice_profile_safe_host_path($outside) === null, 'voice profile helper accepted a path outside managed storage');
        $snapshotDir = hub_voice_profile_snapshot_dir();
        hub_test_assert(
            hub_storage_paths_equal($snapshotDir, $root . DIRECTORY_SEPARATOR . '.snapshots'),
            'Windows snapshot storage must stay inside the managed voice profile root'
        );
    } finally {
        @unlink($path);
        @unlink($outside);
    }
});

hub_test('GPT-SoVITS promotion rebinds stored audio paths through the managed voice profile boundary', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/voice_profiles.php');

    hub_test_assert(str_contains($source, '$rawPath = hub_voice_profile_safe_host_path($rawPath);'), 'GPT-SoVITS promotion must canonicalize its stored audio path before sensitive I/O');
    hub_test_assert(str_contains($source, '$raw = @fopen($rawPath, \'r+b\');'), 'GPT-SoVITS cleanup must retain the canonicalized path for its descriptor check');
});
