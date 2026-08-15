<?php
declare(strict_types=1);

function hub_test_runtime_telemetry_events(): array
{
    $path = HUB_LOG_DIR . '/runtime-telemetry-' . date('Y-m-d') . '.ndjson';
    if (!file_exists($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Cannot open runtime telemetry log.');
    }

    $events = [];
    try {
        while (($line = fgets($handle)) !== false) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
    } finally {
        fclose($handle);
    }

    return $events;
}

function hub_test_runtime_telemetry_claim_events_after(int $before, string $variant): array
{
    return array_values(array_filter(
        array_slice(hub_test_runtime_telemetry_events(), $before),
        static fn (array $event): bool => ($event['action'] ?? null) === 'claim' && ($event['variant'] ?? null) === $variant
    ));
}

function hub_test_runtime_telemetry_heartbeat_events_after(int $before, string $variant): array
{
    return array_values(array_filter(
        array_slice(hub_test_runtime_telemetry_events(), $before),
        static fn (array $event): bool => ($event['action'] ?? null) === 'heartbeat' && ($event['variant'] ?? null) === $variant
    ));
}

function hub_test_runtime_telemetry_heartbeat_run(PDO $db, string $accelerator = 'cpu'): array
{
    hub_test_runtime_telemetry_enqueue_pack_job($db, $accelerator);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Heartbeat fixture must claim a Pack task.');
    }
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-heartbeat-worker', 60);
    if (!is_array($run)) {
        throw new RuntimeException('Heartbeat fixture must claim a runtime.');
    }

    return [$task, $run];
}

function hub_test_runtime_telemetry_start_sqlite_writer_lock(string $readyPath, string $attemptPath, int $holdUs): array
{
    if (!function_exists('proc_open') || !defined('PHP_BINARY') || !is_file(PHP_BINARY) || !is_executable(PHP_BINARY)) {
        hub_test_skip('SQLite lock telemetry test requires an executable PHP child process');
    }
    $child = <<<'PHP'
$db = new PDO('sqlite:' . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA busy_timeout = 5000');
$db->exec('BEGIN IMMEDIATE');
try {
    if (file_put_contents($argv[2], "ready\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot signal SQLite lock readiness.');
    }
    $deadline = microtime(true) + 2.0;
    while (!is_file($argv[3])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for SQLite BEGIN attempt.');
        }
        usleep(5000);
    }
    usleep((int)$argv[4]);
    $db->exec('COMMIT');
} catch (Throwable $e) {
    try {
        $db->exec('ROLLBACK');
    } catch (Throwable) {
    }
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
PHP;
    $process = proc_open(
        [PHP_BINARY, '-r', $child, HUB_DB_PATH, $readyPath, $attemptPath, (string)$holdUs],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        HUB_ROOT,
    );
    if (!is_resource($process)) {
        hub_test_skip('SQLite lock telemetry test requires an executable PHP child process');
    }
    try {
        fclose($pipes[0]);
        $deadline = microtime(true) + 2.0;
        while (microtime(true) < $deadline && trim((string)file_get_contents($readyPath)) !== 'ready') {
            usleep(5000);
        }
        hub_test_assert(trim((string)file_get_contents($readyPath)) === 'ready', 'SQLite writer-lock child must signal readiness');
    } catch (Throwable $e) {
        if ((proc_get_status($process)['running'] ?? false)) {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        throw $e;
    }

    return [$process, $pipes];
}

function hub_test_runtime_telemetry_with_sqlite_writer_lock(int $holdUs, callable $fn): void
{
    $readyPath = tempnam(sys_get_temp_dir(), '3waaihub_telemetry_lock_');
    $attemptPath = tempnam(sys_get_temp_dir(), '3waaihub_telemetry_attempt_');
    if ($readyPath === false || $attemptPath === false || !unlink($attemptPath)) {
        throw new RuntimeException('Cannot create SQLite lock telemetry fixture.');
    }
    $process = null;
    $pipes = [];
    try {
        [$process, $pipes] = hub_test_runtime_telemetry_start_sqlite_writer_lock($readyPath, $attemptPath, $holdUs);
        $fn($attemptPath);
        $deadline = microtime(true) + 2.0;
        do {
            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                break;
            }
            usleep(5000);
        } while (microtime(true) < $deadline);
        hub_test_assert(
            is_array($status) && !($status['running'] ?? true) && ($status['exitcode'] ?? -1) === 0,
            'SQLite writer-lock child must exit successfully'
        );
    } finally {
        if (is_resource($process) && (proc_get_status($process)['running'] ?? false)) {
            proc_terminate($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_close($process);
        }
        if (is_file($readyPath)) {
            unlink($readyPath);
        }
        if (is_file($attemptPath)) {
            unlink($attemptPath);
        }
    }
}

function hub_test_runtime_telemetry_locking_pdo(string $attemptPath, bool $signalDeferred = false): PDO
{
    $db = new class('sqlite:' . HUB_DB_PATH, $attemptPath, $signalDeferred) extends PDO {
        private bool $attemptSignaled = false;

        public function __construct(string $dsn, private string $attemptPath, private bool $signalDeferred)
        {
            parent::__construct($dsn);
        }

        private function signalAttempt(): void
        {
            if ($this->attemptSignaled) {
                return;
            }
            if (is_file($this->attemptPath) || file_put_contents($this->attemptPath, "attempt\n", LOCK_EX) === false) {
                throw new RuntimeException('Cannot signal SQLite BEGIN attempt.');
            }
            $this->attemptSignaled = true;
        }

        public function beginTransaction(): bool
        {
            if ($this->signalDeferred) {
                $this->signalAttempt();
            }

            return parent::beginTransaction();
        }

        public function exec(string $statement): int|false
        {
            if ($statement === 'BEGIN IMMEDIATE') {
                $this->signalAttempt();
            }

            return parent::exec($statement);
        }
    };
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 0');

    return $db;
}

function hub_test_runtime_telemetry_rollback_failure_pdo(string $statementNeedle, string $statementError): PDO
{
    $db = new class('sqlite:' . HUB_DB_PATH, $statementNeedle, $statementError) extends PDO {
        public bool $failRollback = true;

        public function __construct(string $dsn, private string $statementNeedle, private string $statementError)
        {
            parent::__construct($dsn);
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (str_contains($query, $this->statementNeedle)) {
                throw new PDOException($this->statementError);
            }

            return parent::prepare($query, $options);
        }

        public function exec(string $statement): int|false
        {
            if ($this->failRollback && $statement === 'ROLLBACK') {
                throw new PDOException('forced_rollback_failure');
            }

            return parent::exec($statement);
        }
    };
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA busy_timeout = 0');

    return $db;
}

function hub_test_runtime_telemetry_enqueue_pack_job(PDO $db, string $accelerator = 'gpu'): int
{
    return hub_enqueue_task($db, 'pack_job', $accelerator, 0, ['private_input' => 'must-not-emit'], null, '127.0.0.1', [
        'pack_id' => 'telemetry-pack',
        'pack_version' => '1.0.0',
        'job' => 'convert',
        'accelerator' => $accelerator,
    ]);
}

function hub_test_runtime_telemetry_events_after(int $before, string $action, string $variant): array
{
    return array_values(array_filter(
        array_slice(hub_test_runtime_telemetry_events(), $before),
        static fn (array $event): bool => ($event['action'] ?? null) === $action && ($event['variant'] ?? null) === $variant
    ));
}

function hub_test_runtime_telemetry_terminal_fixture(PDO $db, string $accelerator = 'cpu'): array
{
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, $accelerator);
    $output = hub_task_result_dir((int)$task['id']) . '/workspace/output';
    if (!is_dir($output) && !mkdir($output, 0775, true) && !is_dir($output)) {
        throw new RuntimeException('Cannot create terminal telemetry output fixture.');
    }
    $path = $output . '/result.txt';
    if (file_put_contents($path, "terminal telemetry\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot write terminal telemetry output fixture.');
    }
    $path = realpath($path);
    $stat = $path === false ? false : lstat($path);
    if ($path === false || !is_array($stat)) {
        throw new RuntimeException('Cannot stat terminal telemetry output fixture.');
    }

    return [
        'task' => $task,
        'run' => $run,
        'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
        'artifacts' => [[
            'name' => 'result.txt',
            'artifact_type' => 'result',
            'path' => $path,
            'mime_type' => hub_pack_job_detect_mime($path),
            'size_bytes' => (int)$stat['size'],
            'max_bytes' => 1024,
            'sha256' => hash_file('sha256', $path),
            'metadata' => [],
            'device' => (int)$stat['dev'],
            'inode' => (int)$stat['ino'],
        ]],
    ];
}

hub_test('BEGIN IMMEDIATE reports retry timing after a coordinated SQLite writer lock', function (): void {
    hub_test_reset_db();
    hub_test_runtime_telemetry_with_sqlite_writer_lock(150000, static function (string $attemptPath): void {
        $db = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        $stats = [];
        hub_sqlite_begin_immediate($db, $stats);
        $rolledBack = false;
        try {
            hub_test_assert(($stats['retry_count'] ?? 0) >= 1, 'BEGIN IMMEDIATE retry must be counted');
            hub_test_assert(($stats['lock_wait_ms'] ?? 0) > 0, 'BEGIN IMMEDIATE wait must be measured');
            hub_test_assert(($stats['lock_exhausted'] ?? true) === false, 'successful retry must not be exhausted');
            hub_test_assert($db->exec('ROLLBACK') === 0, 'successful BEGIN IMMEDIATE must leave a transaction open for rollback');
            $rolledBack = true;
        } finally {
            if (!$rolledBack) {
                try {
                    $db->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
        }
    });
});

hub_test('BEGIN IMMEDIATE reports exhaustion after seven locked attempts', function (): void {
    hub_test_reset_db();
    hub_test_runtime_telemetry_with_sqlite_writer_lock(700000, static function (string $attemptPath): void {
        $db = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        $stats = [];
        hub_test_assert(hub_test_throws(static function () use ($db, &$stats): void {
            hub_sqlite_begin_immediate($db, $stats);
        }), 'locked BEGIN IMMEDIATE must throw after retries');
        hub_test_assert(($stats['lock_exhausted'] ?? false) === true, 'seventh locked BEGIN failure must be exhausted');
        hub_test_assert(($stats['retry_count'] ?? -1) === 6, 'exhausted BEGIN IMMEDIATE must count six sleeps');
        hub_test_assert(($stats['lock_wait_ms'] ?? 0) > 0, 'exhausted BEGIN IMMEDIATE wait must be measured');
        hub_test_assert(!$db->inTransaction(), 'exhausted BEGIN IMMEDIATE must not leave a transaction open');
    });
});

hub_test('waiting GPU promotion retries a short SQLite writer lock', function (): void {
    hub_test_reset_db();
    hub_test_runtime_telemetry_with_sqlite_writer_lock(150000, static function (string $attemptPath): void {
        $db = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        hub_test_assert(hub_promote_due_waiting_gpu_task($db) === false, 'empty waiting GPU promotion must complete after the writer lock clears');
    });
});

hub_test('callback claim retries a short SQLite writer lock', function (): void {
    hub_test_reset_db();
    hub_test_runtime_telemetry_with_sqlite_writer_lock(150000, static function (string $attemptPath): void {
        $db = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        hub_test_assert(hub_callback_claim_due_delivery($db, time()) === null, 'empty callback claim must complete after the writer lock clears');
    });
});

hub_test('cluster refresh retries a short SQLite writer lock before reading its write transaction', function (): void {
    $setup = hub_test_reset_db();
    $stationId = hub_cluster_save_paired_station($setup, [
        'station_key' => 'sqlite_lock_station',
        'display_name' => 'SQLite lock station',
        'public_base_url' => 'https://station.example/aihub',
        'internal_base_url' => null,
        'priority' => 1,
        'enabled' => true,
        'station_token' => 'sqlite_lock_station_token',
        'modes' => ['ocr'],
    ]);
    $station = hub_cluster_get_station($setup, $stationId);
    hub_test_assert(is_array($station), 'cluster lock fixture station must exist');
    $setup = null;

    hub_test_runtime_telemetry_with_sqlite_writer_lock(150000, static function (string $attemptPath) use ($station): void {
        $db = hub_test_runtime_telemetry_locking_pdo($attemptPath, true);
        $refreshed = hub_cluster_refresh_station_now($db, $station, true, static fn (): array => ['status' => 200, 'body' => '{}']);
        hub_test_assert(($refreshed['last_error'] ?? null) === 'manifest_invalid', 'cluster refresh must continue after the writer lock clears');
    });
});

hub_test('demo queue claim emits no task telemetry', function (): void {
    $db = hub_test_reset_db();
    hub_enqueue_task($db, 'demo_task', 'cpu', 0, ['private_input' => 'must-not-emit'], null, '127.0.0.1');
    $before = count(hub_test_runtime_telemetry_events());
    $task = hub_claim_next_task($db, ['demo_task']);

    hub_test_assert(is_array($task) && ($task['task_type'] ?? null) === 'demo_task', 'demo task must be claimed');
    hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'task') === [], 'demo task claim must not emit claim/task');
});

hub_test('pack queue claim emits one sanitized committed task telemetry event', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $before = count(hub_test_runtime_telemetry_events());
    $task = hub_claim_next_task($db, ['pack_job']);
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'task');

    hub_test_assert(is_array($task) && ($task['status'] ?? null) === 'running', 'pack task must commit before telemetry');
    hub_test_assert(count($events) === 1, 'pack queue claim must emit exactly one claim/task event');
    $event = $events[0];
    hub_test_assert(($event['outcome'] ?? null) === 'committed' && ($event['tx_mode'] ?? null) === 'deferred'
        && ($event['lock_wait_kind'] ?? null) === 'first_write_upper_bound' && ($event['retry_count'] ?? null) === 0
        && ($event['skipped_ticks'] ?? null) === 0, 'pack queue claim telemetry fields mismatch');
    $fields = ['action', 'variant', 'outcome', 'tx_mode', 'tx_begin_at', 'tx_commit_at', 'pre_tx_ms', 'lock_wait_ms', 'lock_wait_kind', 'tx_ms', 'post_tx_ms', 'total_ms', 'retry_count', 'skipped_ticks', 'schema_version', 'observed_at'];
    sort($fields);
    $eventFields = array_keys($event);
    sort($eventFields);
    hub_test_assert($eventFields === $fields, 'pack queue claim telemetry must not expose task identity or input fields');
});

hub_test('pack runtime claim emits committed telemetry for a claimed runtime no-match', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Pack runtime telemetry fixture must claim a task.');
    }
    $before = count(hub_test_runtime_telemetry_events());
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-runtime-worker', 60);
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'runtime');

    hub_test_assert(is_array($run) && ($run['state'] ?? null) === 'claimed', 'pack runtime must be claimed');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'immediate' && ($events[0]['lock_wait_kind'] ?? null) === 'begin_immediate', 'successful runtime claim telemetry mismatch');

    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_pack_job_claim_runtime($db, $task, 'telemetry-runtime-worker', 60) === null, 'claimed runtime must not be claimed twice');
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'runtime');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'committed', 'claimed runtime no-match must emit committed telemetry');
});

hub_test('pack runtime claim emits fence-lost telemetry when the task guard changes', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Pack runtime guard telemetry fixture must claim a task.');
    }
    $db->prepare('UPDATE tasks SET lock_token = :lock_token WHERE id = :id')->execute([
        ':lock_token' => 'replaced-task-lock',
        ':id' => (int)$task['id'],
    ]);
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_claim_runtime($db, $task, 'telemetry-runtime-worker', 60) === null, 'changed task guard must reject the runtime claim');
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'runtime');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'fence_lost', 'changed task guard must emit fence_lost telemetry');
});

hub_test('pack runtime claim preserves a caller-owned transaction on BEGIN failure', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Caller transaction fixture must claim a task.');
    }
    $key = 'AIHUB_MODELS_DIR';
    $original = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $original->execute([':key' => $key]);
    $originalValue = $original->fetchColumn();
    $before = count(hub_test_runtime_telemetry_events());
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE settings SET value = :value WHERE key = :key')->execute([
            ':value' => 'caller-transaction-value',
            ':key' => $key,
        ]);
        $error = null;
        try {
            hub_pack_job_claim_runtime($db, $task, 'telemetry-runtime-worker', 60);
        } catch (Throwable $e) {
            $error = $e;
        }

        hub_test_assert($error instanceof PDOException, 'caller transaction must receive the original BEGIN exception');
        hub_test_assert($db->inTransaction(), 'runtime claim must not roll back a caller-owned transaction');
        $current = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $current->execute([':key' => $key]);
        hub_test_assert($current->fetchColumn() === 'caller-transaction-value', 'caller uncommitted change must remain intact');
        hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'runtime') === [], 'caller-owned transaction rejection must not emit telemetry');
    } finally {
        if ($db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (Throwable) {
            }
        }
    }
    $restored = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $restored->execute([':key' => $key]);
    hub_test_assert($restored->fetchColumn() === $originalValue, 'caller transaction rollback must discard its uncommitted change');
});

hub_test('pack runtime claim preserves a raw SQLite caller transaction without telemetry', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Raw caller transaction fixture must claim a task.');
    }
    $markerKey = 'runtime-raw-marker-' . bin2hex(random_bytes(4));
    $before = count(hub_test_runtime_telemetry_events());
    $rawTransactionOpen = false;
    $db->exec('BEGIN IMMEDIATE');
    $rawTransactionOpen = true;
    try {
        hub_test_assert(!$db->inTransaction(), 'PDO must not report a raw SQLite BEGIN IMMEDIATE transaction');
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'runtime-raw-marker',
            ':updated_at' => hub_now(),
        ]);
        $error = null;
        try {
            hub_pack_job_claim_runtime($db, $task, 'telemetry-runtime-worker', 60);
        } catch (Throwable $e) {
            $error = $e;
        }

        hub_test_assert($error instanceof PDOException, 'raw caller transaction must receive the original BEGIN exception');
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($marker->fetchColumn() === 'runtime-raw-marker', 'raw caller marker must remain after nested runtime begin fails');
        hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'runtime') === [], 'raw caller runtime rejection must not emit telemetry');
        hub_test_assert($db->exec('ROLLBACK') !== false, 'raw caller must be able to roll back its own transaction');
        $rawTransactionOpen = false;
    } finally {
        if ($rawTransactionOpen) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }
    $marker = $db->prepare('SELECT 1 FROM settings WHERE key = :key');
    $marker->execute([':key' => $markerKey]);
    hub_test_assert($marker->fetchColumn() === false, 'raw caller rollback must discard its marker');
});

hub_test('pack runtime claim suppresses telemetry when its owned rollback fails', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Runtime rollback-failure fixture must claim a task.');
    }
    $claimDb = hub_test_runtime_telemetry_rollback_failure_pdo('SELECT 1 FROM tasks', 'runtime_claim_statement_failure');
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    try {
        try {
            hub_pack_job_claim_runtime($claimDb, $task, 'telemetry-runtime-worker', 60);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert($error instanceof PDOException
            && $error->getMessage() === 'runtime_claim_statement_failure'
            && hub_test_runtime_telemetry_claim_events_after($before, 'runtime') === [],
            'runtime claim must preserve its statement error and suppress telemetry when rollback fails');
        $claimDb->failRollback = false;
        hub_test_assert($claimDb->exec('ROLLBACK') !== false, 'runtime claim test must explicitly close the held raw transaction');
    } finally {
        $claimDb->failRollback = false;
        try {
            $claimDb->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
});

hub_test('invalid pack runtime claim emits no telemetry', function (): void {
    $db = hub_test_reset_db();
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_pack_job_claim_runtime($db, [], 'telemetry-runtime-worker', 60) === null, 'invalid runtime claim must return null');
    hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'runtime') === [], 'invalid runtime claim must not emit claim/runtime');
});

hub_test('pack GPU claim emits telemetry while CPU, non-pack, and direct callers do not', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Pack GPU telemetry fixture must claim a task.');
    }
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-gpu-worker', 60);
    if (!is_array($run)) {
        throw new RuntimeException('Pack GPU telemetry fixture must claim a runtime.');
    }

    $before = count(hub_test_runtime_telemetry_events());
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'gpu');
    hub_test_assert(is_array($lease) && ($lease['state'] ?? null) === 'leased', 'pack GPU claim must acquire the lease');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'immediate' && ($events[0]['lock_wait_kind'] ?? null) === 'begin_immediate', 'pack GPU claim telemetry mismatch');

    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_gpu_acquire_for_task($db, ['task_type' => 'pack_job', 'accelerator' => 'cpu'], $run, 60) === null, 'CPU task must not acquire GPU');
    hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'gpu') === [], 'CPU task must not emit claim/gpu');

    $before = count(hub_test_runtime_telemetry_events());
    hub_runtime_gpu_acquire_for_task($db, ['task_type' => 'demo_task', 'accelerator' => 'gpu'], $run, 60);
    hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'gpu') === [], 'non-pack GPU task must not emit claim/gpu');

    $before = count(hub_test_runtime_telemetry_events());
    hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'gpu') === [], 'direct gateway-shaped GPU caller must not emit claim/gpu');
});

hub_test('pack GPU claim distinguishes unavailable resource from a lost runtime fence', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    $firstTask = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($firstTask)) {
        throw new RuntimeException('First GPU outcome fixture must claim a task.');
    }
    $firstRun = hub_pack_job_claim_runtime($db, $firstTask, 'telemetry-gpu-first-worker', 60);
    if (!is_array($firstRun) || !is_array(hub_runtime_gpu_acquire_for_task($db, $firstTask, $firstRun, 60))) {
        throw new RuntimeException('First GPU outcome fixture must occupy gpu:0.');
    }
    $secondTask = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($secondTask)) {
        throw new RuntimeException('Second GPU outcome fixture must claim a task.');
    }
    $secondRun = hub_pack_job_claim_runtime($db, $secondTask, 'telemetry-gpu-second-worker', 60);
    if (!is_array($secondRun)) {
        throw new RuntimeException('Second GPU outcome fixture must claim a runtime.');
    }

    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_gpu_acquire_for_task($db, $secondTask, $secondRun, 60) === null, 'busy GPU resource must be unavailable');
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'gpu');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'committed', 'busy GPU resource must emit committed claim/gpu telemetry');

    $db->prepare('UPDATE runtime_runs SET lease_token = :lease_token WHERE id = :id')->execute([
        ':lease_token' => 'lost-runtime-fence',
        ':id' => (int)$secondRun['id'],
    ]);
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_gpu_acquire_for_task($db, $secondTask, $secondRun, 60) === null, 'lost runtime fence must reject GPU acquisition');
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'gpu');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'fence_lost', 'lost runtime fence must emit fence_lost claim/gpu telemetry');
});

hub_test('pack GPU claim preserves a raw SQLite caller transaction without telemetry', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Raw GPU caller fixture must claim a task.');
    }
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-gpu-worker', 60);
    if (!is_array($run)) {
        throw new RuntimeException('Raw GPU caller fixture must claim a runtime.');
    }
    $markerKey = 'gpu-raw-marker-' . bin2hex(random_bytes(4));
    $before = count(hub_test_runtime_telemetry_events());
    $rawTransactionOpen = false;
    $db->exec('BEGIN IMMEDIATE');
    $rawTransactionOpen = true;
    try {
        hub_test_assert(!$db->inTransaction(), 'PDO must not report a raw SQLite GPU transaction');
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'gpu-raw-marker',
            ':updated_at' => hub_now(),
        ]);
        $error = null;
        try {
            hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
        } catch (Throwable $e) {
            $error = $e;
        }

        hub_test_assert($error instanceof PDOException, 'raw GPU caller transaction must receive the original BEGIN exception');
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($marker->fetchColumn() === 'gpu-raw-marker', 'raw GPU caller marker must remain after nested begin fails');
        hub_test_assert(hub_test_runtime_telemetry_claim_events_after($before, 'gpu') === [], 'raw GPU caller rejection must not emit telemetry');
        hub_test_assert($db->exec('ROLLBACK') !== false, 'raw GPU caller must be able to roll back its own transaction');
        $rawTransactionOpen = false;
    } finally {
        if ($rawTransactionOpen) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }
    $marker = $db->prepare('SELECT 1 FROM settings WHERE key = :key');
    $marker->execute([':key' => $markerKey]);
    hub_test_assert($marker->fetchColumn() === false, 'raw GPU caller rollback must discard its marker');
});

hub_test('pack GPU claim suppresses telemetry when its owned rollback fails', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('GPU rollback-failure fixture must claim a task.');
    }
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-gpu-worker', 60);
    if (!is_array($run)) {
        throw new RuntimeException('GPU rollback-failure fixture must claim a runtime.');
    }
    $claimDb = hub_test_runtime_telemetry_rollback_failure_pdo('SELECT 1 FROM runtime_runs', 'gpu_claim_statement_failure');
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    try {
        try {
            hub_runtime_gpu_acquire_for_task($claimDb, $task, $run, 60);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert($error instanceof PDOException
            && $error->getMessage() === 'gpu_claim_statement_failure'
            && hub_test_runtime_telemetry_claim_events_after($before, 'gpu') === [],
            'GPU claim must preserve its statement error and suppress telemetry when rollback fails');
        $claimDb->failRollback = false;
        hub_test_assert($claimDb->exec('ROLLBACK') !== false, 'GPU claim test must explicitly close the held raw transaction');
    } finally {
        $claimDb->failRollback = false;
        try {
            $claimDb->exec('ROLLBACK');
        } catch (Throwable) {
        }
    }
});

hub_test('runtime claim lock exhaustion emits zero transaction duration', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db);
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('Runtime lock exhaustion fixture must claim a task.');
    }
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_runtime_telemetry_with_sqlite_writer_lock(700000, static function (string $attemptPath) use ($task): void {
        $lockedDb = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        hub_test_assert(hub_test_throws(static function () use ($lockedDb, $task): void {
            hub_pack_job_claim_runtime($lockedDb, $task, 'telemetry-runtime-worker', 60);
        }), 'runtime claim must throw when BEGIN IMMEDIATE exhausts');
    });
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'runtime');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && (float)($events[0]['tx_ms'] ?? -1) === 0.0, 'runtime lock exhaustion must report zero transaction duration');
});

hub_test('GPU claim lock exhaustion emits zero transaction duration', function (): void {
    $db = hub_test_reset_db();
    hub_test_runtime_telemetry_enqueue_pack_job($db, 'gpu');
    $task = hub_claim_next_task($db, ['pack_job']);
    if (!is_array($task)) {
        throw new RuntimeException('GPU lock exhaustion fixture must claim a task.');
    }
    $run = hub_pack_job_claim_runtime($db, $task, 'telemetry-gpu-worker', 60);
    if (!is_array($run)) {
        throw new RuntimeException('GPU lock exhaustion fixture must claim a runtime.');
    }
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_runtime_telemetry_with_sqlite_writer_lock(700000, static function (string $attemptPath) use ($task, $run): void {
        $lockedDb = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        hub_test_assert(hub_test_throws(static function () use ($lockedDb, $task, $run): void {
            hub_runtime_gpu_acquire_for_task($lockedDb, $task, $run, 60);
        }), 'GPU claim must throw when BEGIN IMMEDIATE exhausts');
    });
    $events = hub_test_runtime_telemetry_claim_events_after($before, 'gpu');
    hub_test_assert(count($events) === 1 && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && (float)($events[0]['tx_ms'] ?? -1) === 0.0, 'GPU lock exhaustion must report zero transaction duration');
});

$validRuntimeTelemetryEvent = [
    'action' => 'heartbeat',
    'variant' => 'cpu',
    'outcome' => 'committed',
    'tx_mode' => 'autocommit',
    'tx_begin_at' => null,
    'tx_commit_at' => null,
    'pre_tx_ms' => 0.125,
    'lock_wait_ms' => 0,
    'lock_wait_kind' => 'none',
    'tx_ms' => 0,
    'post_tx_ms' => 0.25,
    'total_ms' => 0.375,
    'retry_count' => 0,
    'skipped_ticks' => 0,
];

hub_test('runtime telemetry suppresses expected default write warnings', function () use ($validRuntimeTelemetryEvent): void {
    $path = hub_runtime_telemetry_path(new DateTimeImmutable());
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Cannot reset runtime telemetry write fixture.');
    }
    if (is_dir($path) && !rmdir($path)) {
        throw new RuntimeException('Cannot reset runtime telemetry write fixture.');
    }
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Cannot create runtime telemetry write fixture.');
    }

    $warnings = 0;
    set_error_handler(static function (int $severity) use (&$warnings): bool {
        if (($severity & error_reporting()) !== 0) {
            $warnings++;
        }
        return true;
    });
    try {
        $result = hub_runtime_telemetry_emit($validRuntimeTelemetryEvent);
    } finally {
        restore_error_handler();
        rmdir($path);
    }

    hub_test_assert($result === false, 'default write failure must return false');
    hub_test_assert($warnings === 0, 'default write failure must not emit a PHP warning');
});

hub_test('runtime telemetry emits one heartbeat line with schema version', function () use ($validRuntimeTelemetryEvent): void {
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_runtime_telemetry_emit($validRuntimeTelemetryEvent), 'valid heartbeat must emit');

    $events = hub_test_runtime_telemetry_events();
    hub_test_assert(count($events) === $before + 1, 'heartbeat must append exactly one event');
    $event = $events[array_key_last($events)];
    hub_test_assert(($event['action'] ?? null) === 'heartbeat', 'heartbeat action mismatch');
    hub_test_assert(($event['variant'] ?? null) === 'cpu', 'heartbeat variant mismatch');
    hub_test_assert(($event['schema_version'] ?? null) === 1, 'schema version mismatch');
    hub_test_assert(is_string($event['observed_at'] ?? null), 'observed_at must be emitted');
});

hub_test('runtime telemetry keeps observed date and writer path aligned', function () use ($validRuntimeTelemetryEvent): void {
    $capturedPath = null;
    $capturedEvent = null;
    $result = hub_runtime_telemetry_emit(
        $validRuntimeTelemetryEvent,
        static function (string $path, string $line) use (&$capturedPath, &$capturedEvent): int {
            $capturedPath = $path;
            $capturedEvent = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            return strlen($line);
        }
    );

    hub_test_assert($result, 'valid telemetry must be written');
    hub_test_assert(is_string($capturedPath) && is_array($capturedEvent), 'writer must capture telemetry');
    preg_match('/runtime-telemetry-(\d{4}-\d{2}-\d{2})\.ndjson$/', $capturedPath, $pathMatch);
    hub_test_assert(($pathMatch[1] ?? null) === substr((string)($capturedEvent['observed_at'] ?? ''), 0, 10), 'observed date and writer path must match');
});

hub_test('runtime telemetry rejects partial writes and invalid timings', function () use ($validRuntimeTelemetryEvent): void {
    $partialWriter = static fn (string $path, string $line): int => strlen($line) - 1;
    hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $partialWriter), 'partial write must fail');

    foreach ([NAN, INF, true] as $timing) {
        $event = $validRuntimeTelemetryEvent;
        $event['total_ms'] = $timing;
        $writerCalls = 0;
        $writer = static function (string $path, string $line) use (&$writerCalls): int {
            $writerCalls++;
            return strlen($line);
        };
        hub_test_assert(!hub_runtime_telemetry_emit($event, $writer), 'invalid timing must be rejected');
        hub_test_assert($writerCalls === 0, 'invalid timing must not invoke the writer');
    }
});

hub_test('runtime telemetry diagnoses writer failures without path warnings', function () use ($validRuntimeTelemetryEvent): void {
    $errorLogPath = tempnam(sys_get_temp_dir(), '3waaihub_telemetry_');
    if ($errorLogPath === false) {
        throw new RuntimeException('Cannot create runtime telemetry diagnostic fixture.');
    }

    $previousErrorLog = ini_get('error_log');
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (($severity & error_reporting()) !== 0) {
            $warnings[] = $message;
        }
        return true;
    });
    try {
        if (ini_set('error_log', $errorLogPath) === false) {
            throw new RuntimeException('Cannot redirect runtime telemetry diagnostics.');
        }
        $falseWriter = static fn (string $path, string $line): int|false => false;
        $partialWriter = static fn (string $path, string $line): int => strlen($line) - 1;
        hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $falseWriter), 'false writer must fail');
        hub_test_assert(!hub_runtime_telemetry_emit($validRuntimeTelemetryEvent, $partialWriter), 'partial writer must fail');
        $diagnostics = (string)file_get_contents($errorLogPath);
    } finally {
        restore_error_handler();
        ini_set('error_log', $previousErrorLog);
        unlink($errorLogPath);
    }

    hub_test_assert($warnings === [], 'writer failures must not expose a filesystem warning');
    hub_test_assert(substr_count($diagnostics, '[3waAIHub] runtime telemetry append failed') === 2, 'writer failures must emit exactly two fixed diagnostics');
});

hub_test('runtime telemetry rejects unknown fields and absorbs writer failures', function () use ($validRuntimeTelemetryEvent): void {
    $unknownFieldEvent = $validRuntimeTelemetryEvent;
    $unknownFieldEvent['token'] = 'secret';
    hub_test_assert(!hub_runtime_telemetry_emit($unknownFieldEvent), 'unknown field must be rejected');

    $writerResult = null;
    $result = hub_runtime_telemetry_emit(
        $validRuntimeTelemetryEvent,
        static function (string $path, string $line) use (&$writerResult): int|false {
            $writerResult = [$path, $line];
            return false;
        }
    );
    hub_test_assert($result === false, 'failed writer must return false');
    hub_test_assert(is_array($writerResult) && $writerResult[0] === hub_runtime_telemetry_path(new DateTimeImmutable()), 'writer path mismatch');
});

hub_test('runtime telemetry rejects empty and impossible timestamps before writing', function () use ($validRuntimeTelemetryEvent): void {
    $writerCalls = 0;
    $writer = static function (string $path, string $line) use (&$writerCalls): int {
        $writerCalls++;
        return strlen($line);
    };

    foreach (['', '2026-02-30T12:00:00.000000+08:00'] as $timestamp) {
        $event = $validRuntimeTelemetryEvent;
        $event['tx_begin_at'] = $timestamp;
        hub_test_assert(!hub_runtime_telemetry_emit($event, $writer), 'invalid timestamp must be rejected');
    }

    hub_test_assert($writerCalls === 0, 'invalid timestamps must not invoke the writer');
});

hub_test('runtime telemetry validates elapsed milliseconds and paths', function (): void {
    hub_test_assert(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/', hub_runtime_telemetry_timestamp()) === 1, 'timestamp format mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(2_000_000, 3_234_567) === 1.235, 'elapsed milliseconds mismatch');
    hub_test_assert(hub_runtime_telemetry_elapsed_ms(3, 2) === 0.0, 'elapsed milliseconds must be nonnegative');
    hub_test_assert(hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-14')) === HUB_LOG_DIR . '/runtime-telemetry-2026-08-14.ndjson', 'telemetry path mismatch');
});

function hub_test_runtime_telemetry_fixture_line(array $event): string
{
    return json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

hub_test('runtime telemetry parses relative summary ranges and nearest-rank quantiles', function (): void {
    $now = new DateTimeImmutable('2026-08-14T01:00:00.000000+08:00');
    $dstNow = new DateTimeImmutable('2026-03-08 03:30:00', new DateTimeZone('America/New_York'));

    hub_test_assert(
        hub_runtime_telemetry_parse_since('1 hour', $now)->format('c') === '2026-08-14T00:00:00+08:00',
        'one hour must parse relative to now'
    );
    hub_test_assert(hub_test_throws(static fn (): DateTimeImmutable => hub_runtime_telemetry_parse_since('all time', $now)), 'malformed relative range must be rejected');
    hub_test_assert(
        $dstNow->getTimestamp() - hub_runtime_telemetry_parse_since('1 hour', $dstNow)->getTimestamp() === 3600,
        'relative ranges must subtract elapsed seconds across DST'
    );
    hub_test_assert(hub_runtime_telemetry_quantile([1, 2, 3], 0.5) === 2.0, 'p50 must use nearest rank');
    hub_test_assert(hub_runtime_telemetry_quantile([10, 20], 0.95) === 20.0, 'p95 must use nearest rank');
});

hub_test('runtime telemetry summary rejects counter-overflow lines before aggregation', function () use ($validRuntimeTelemetryEvent): void {
    $event = static function (int $txMs, int $retries, int $skipped) use ($validRuntimeTelemetryEvent): array {
        return array_replace($validRuntimeTelemetryEvent, [
            'schema_version' => HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION,
            'observed_at' => '2026-08-14T00:00:00.000000+08:00',
            'tx_ms' => $txMs,
            'retry_count' => $retries,
            'skipped_ticks' => $skipped,
        ]);
    };
    $fixture = hub_test_runtime_telemetry_fixture_line($event(1, PHP_INT_MAX, PHP_INT_MAX))
        . hub_test_runtime_telemetry_fixture_line($event(2, 1, 1));
    $summary = hub_runtime_telemetry_summary(
        new DateTimeImmutable('2026-08-14T00:00:00.000000+08:00'),
        new DateTimeImmutable('2026-08-14T00:00:00.000000+08:00'),
        static function (string $path) use ($fixture) {
            $handle = fopen('php://temp', 'w+b');
            fwrite($handle, $fixture);
            rewind($handle);

            return $handle;
        }
    );

    hub_test_assert(($summary['invalid_lines'] ?? null) === 1, 'counter-overflow line must be invalid');
    hub_test_assert(($summary['groups']['heartbeat/cpu'] ?? null) === [
        'action' => 'heartbeat', 'variant' => 'cpu', 'count' => 1, 'p50_tx' => 1.0, 'p95_tx' => 1.0, 'p99_tx' => 1.0,
        'lock_count' => 0, 'retries' => PHP_INT_MAX, 'exhausted' => 0, 'skipped' => PHP_INT_MAX,
    ], 'counter-overflow line must not partially mutate group totals');
});

hub_test('runtime telemetry summarizes only direct daily candidates with independent groups', function () use ($validRuntimeTelemetryEvent): void {
    $fixtureDir = sys_get_temp_dir() . '/3waaihub_runtime_telemetry_' . bin2hex(random_bytes(8));
    if (!mkdir($fixtureDir, 0700)) {
        throw new RuntimeException('Cannot create runtime telemetry summary fixture.');
    }
    $since = new DateTimeImmutable('2026-08-13T23:00:00.000000+08:00');
    $until = new DateTimeImmutable('2026-08-14T01:00:00.000000+08:00');
    $paths = [
        hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-13')),
        hub_runtime_telemetry_path(new DateTimeImmutable('2026-08-14')),
    ];
    $event = static function (string $observedAt, int $txMs, array $overrides = []) use ($validRuntimeTelemetryEvent): array {
        return array_replace($validRuntimeTelemetryEvent, [
            'schema_version' => HUB_RUNTIME_TELEMETRY_SCHEMA_VERSION,
            'observed_at' => $observedAt,
            'tx_ms' => $txMs,
        ], $overrides);
    };
    $fixtures = [
        $paths[0] => hub_test_runtime_telemetry_fixture_line($event('2026-08-13T22:59:59.000000+08:00', 99))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-13T23:15:00.000000+08:00', 1))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-13T23:30:00.000000+08:00', 2)),
        $paths[1] => hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:15:00.000000+08:00', 3))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:20:00.000000+08:00', 10, [
                'action' => 'claim', 'variant' => 'runtime', 'lock_wait_ms' => 4, 'retry_count' => 1, 'skipped_ticks' => 2,
            ]))
            . hub_test_runtime_telemetry_fixture_line($event('2026-08-14T00:50:00.000000+08:00', 20, [
                'action' => 'claim', 'variant' => 'runtime', 'outcome' => 'lock_exhausted', 'retry_count' => 2, 'skipped_ticks' => 1,
            ]))
            . "{malformed\n",
    ];
    foreach ($fixtures as $path => $contents) {
        if (file_put_contents($fixtureDir . '/' . basename($path), $contents) === false) {
            throw new RuntimeException('Cannot write runtime telemetry summary fixture.');
        }
    }
    $requested = [];
    try {
        $summary = hub_runtime_telemetry_summary($since, $until, static function (string $path) use (&$requested, $fixtureDir) {
            $requested[] = $path;
            return fopen($fixtureDir . '/' . basename($path), 'rb');
        });
    } finally {
        foreach ($fixtures as $path => $_contents) {
            unlink($fixtureDir . '/' . basename($path));
        }
        rmdir($fixtureDir);
    }

    hub_test_assert($requested === $paths, 'summary must request only the two direct daily paths in order');
    hub_test_assert(($summary['invalid_lines'] ?? null) === 1, 'summary must count malformed lines');
    hub_test_assert(($summary['groups']['claim/runtime'] ?? null) === [
        'action' => 'claim', 'variant' => 'runtime', 'count' => 2, 'p50_tx' => 10.0, 'p95_tx' => 20.0, 'p99_tx' => 20.0,
        'lock_count' => 1, 'retries' => 3, 'exhausted' => 1, 'skipped' => 3,
    ], 'claim/runtime summary totals mismatch');
    hub_test_assert(($summary['groups']['heartbeat/cpu'] ?? null) === [
        'action' => 'heartbeat', 'variant' => 'cpu', 'count' => 3, 'p50_tx' => 2.0, 'p95_tx' => 3.0, 'p99_tx' => 3.0,
        'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0,
    ], 'heartbeat/cpu summary quantiles mismatch');
});

hub_test('runtime telemetry renders sorted tabular summaries including invalid lines', function (): void {
    $rendered = hub_runtime_telemetry_render_summary([
        'invalid_lines' => 1,
        'groups' => [
            ['action' => 'heartbeat', 'variant' => 'cpu', 'count' => 3, 'p50_tx' => 2.0, 'p95_tx' => 3.0, 'p99_tx' => 3.0, 'lock_count' => 0, 'retries' => 0, 'exhausted' => 0, 'skipped' => 0],
            ['action' => 'claim', 'variant' => 'runtime', 'count' => 2, 'p50_tx' => 10.0, 'p95_tx' => 20.0, 'p99_tx' => 20.0, 'lock_count' => 1, 'retries' => 3, 'exhausted' => 1, 'skipped' => 3],
        ],
    ]);

    hub_test_assert($rendered === "action\tvariant\tcount\tp50_tx\tp95_tx\tp99_tx\tlock>0\tretries\texhausted\tskipped\nclaim\truntime\t2\t10\t20\t20\t1\t3\t1\t3\nheartbeat\tcpu\t3\t2\t3\t3\t0\t0\t0\t0\ninvalid_lines=1\n", 'summary table must remain sorted and complete');
});

hub_test('runtime telemetry retention removes only expired regular dated files', function (): void {
    hub_test_require_symlink_fixture('Runtime telemetry retention requires symlink fixtures.');
    $files = [
        'runtime-telemetry-2026-08-07.ndjson',
        'runtime-telemetry-2026-08-08.ndjson',
        'runtime-telemetry-2026-08-14.ndjson',
        'runtime-telemetry-bad.ndjson',
        'runtime-telemetry-unrelated.log',
    ];
    $original = [];
    foreach ($files as $file) {
        $path = HUB_LOG_DIR . '/' . $file;
        $original[$path] = is_file($path) ? file_get_contents($path) : null;
        if (file_put_contents($path, 'runtime telemetry fixture') === false) {
            throw new RuntimeException('Cannot create runtime telemetry retention fixture.');
        }
    }
    $target = sys_get_temp_dir() . '/3waaihub_runtime_telemetry_link_' . bin2hex(random_bytes(8));
    $link = HUB_LOG_DIR . '/runtime-telemetry-2026-08-01.ndjson';
    if (file_put_contents($target, 'symlink target') === false || !symlink($target, $link)) {
        throw new RuntimeException('Cannot create runtime telemetry symlink fixture.');
    }
    try {
        $purged = hub_prune_runtime_telemetry(new DateTimeImmutable('2026-08-14T12:00:00.000000+08:00'));
        hub_test_assert($purged === 1, 'only the expired regular telemetry file must be purged');
        hub_test_assert(!file_exists(HUB_LOG_DIR . '/runtime-telemetry-2026-08-07.ndjson'), 'expired telemetry file must be removed');
        foreach (array_slice($files, 1) as $file) {
            hub_test_assert(is_file(HUB_LOG_DIR . '/' . $file), 'non-expired or unrelated file must be retained');
        }
        hub_test_assert(is_link($link) && file_exists($target), 'expired-looking telemetry symlink must be retained');
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        unlink($target);
        foreach ($original as $path => $contents) {
            if ($contents === null) {
                @unlink($path);
            } else {
                file_put_contents($path, $contents);
            }
        }
    }
});

hub_test('runtime telemetry summary CLI accepts one strict since option', function (): void {
    $script = HUB_ROOT . '/scripts/runtime_telemetry_summary.php';
    $valid = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('--since=1 hour') . ' 2>&1', $valid, $validStatus);
    hub_test_assert($validStatus === 0 && ($valid[0] ?? null) === "action\tvariant\tcount\tp50_tx\tp95_tx\tp99_tx\tlock>0\tretries\texhausted\tskipped", 'valid CLI range must render the summary table');

    $invalid = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg('--since=all time') . ' 2>&1', $invalid, $invalidStatus);
    hub_test_assert($invalidStatus === 64 && $invalid === ['runtime_telemetry_since_invalid'], 'invalid CLI range must use the fixed error');
});

hub_test('pack heartbeat state preserves raw lease expiries and follows the 30 second cadence', function (): void {
    $base = strtotime('2026-08-14 12:00:00');
    $state = hub_pack_job_heartbeat_state(
        ['lease_expires_at' => '2026-08-14 12:01:00'],
        null
    );
    hub_test_assert($state === [
        'runtime_expires_at' => '2026-08-14 12:01:00',
        'gpu_required' => false,
        'gpu_expires_at' => null,
        'skipped_ticks' => 0,
    ], 'heartbeat state must preserve raw runtime expiry and exact fields');

    $skips = 0;
    $renewals = 0;
    $consumed = [];
    foreach ([10, 20, 30, 40, 50, 60] as $offset) {
        if (!hub_pack_job_heartbeat_should_renew($state, $base + $offset)) {
            hub_pack_job_heartbeat_mark_skipped($state);
            $skips++;
            continue;
        }
        $renewals++;
        $consumed[] = hub_pack_job_heartbeat_mark_committed(
            $state,
            date('Y-m-d H:i:s', $base + $offset + 60)
        );
    }

    hub_test_assert($skips === 4 && $renewals === 2, 'heartbeat cadence must skip four ticks and renew twice');
    hub_test_assert($consumed === [2, 2] && $state['skipped_ticks'] === 0, 'each committed renewal must consume and reset two skips');
});

hub_test('pack heartbeat closures observe committed state through an explicit reference', function (): void {
    $base = strtotime('2026-08-14 12:00:00');
    $state = hub_pack_job_heartbeat_state(['lease_expires_at' => '2026-08-14 12:01:00'], null);
    $shouldRenew = static function (int $now) use (&$state): bool {
        return hub_pack_job_heartbeat_should_renew($state, $now);
    };

    hub_test_assert($shouldRenew($base + 30), 'the first exact-threshold tick must renew');
    hub_pack_job_heartbeat_mark_committed($state, '2026-08-14 12:01:30');
    hub_test_assert(!$shouldRenew($base + 31), 'the next tick must observe the committed expiry');
});

hub_test('pack heartbeat renews for GPU expiry and invalid or exact-threshold dates', function (): void {
    $base = strtotime('2026-08-14 12:00:00');
    $gpuState = hub_pack_job_heartbeat_state(
        ['lease_expires_at' => '2026-08-14 12:02:00'],
        ['lease_expires_at' => '2026-08-14 12:00:20']
    );
    hub_test_assert(hub_pack_job_heartbeat_should_renew($gpuState, $base), 'the earlier GPU expiry must control renewal');
    hub_pack_job_heartbeat_mark_committed($gpuState, '2026-08-14 12:03:00');
    hub_test_assert($gpuState['runtime_expires_at'] === '2026-08-14 12:03:00'
        && $gpuState['gpu_expires_at'] === '2026-08-14 12:03:00', 'commit must write the exact expiry to both leases');

    foreach ([null, 123, '', 'not-a-date', 'tomorrow', '2026-08-14T12:01:00+08:00', '2026-02-30 12:00:00'] as $expiry) {
        $invalidState = [
            'runtime_expires_at' => $expiry,
            'gpu_required' => false,
            'gpu_expires_at' => null,
            'skipped_ticks' => 0,
        ];
        hub_test_assert(hub_pack_job_heartbeat_should_renew($invalidState, $base), 'invalid expiry must fail safe to renewal');
    }
    hub_test_assert(hub_pack_job_heartbeat_should_renew([
        'runtime_expires_at' => date('Y-m-d H:i:s', $base + 30),
        'gpu_required' => false,
        'gpu_expires_at' => null,
        'skipped_ticks' => 0,
    ], $base), 'exactly 30 seconds remaining must renew');
    hub_test_assert(!hub_pack_job_heartbeat_should_renew([
        'runtime_expires_at' => date('Y-m-d H:i:s', $base + 31),
        'gpu_required' => false,
        'gpu_expires_at' => null,
        'skipped_ticks' => 0,
    ], $base), 'more than 30 seconds remaining must skip');
    hub_test_assert(hub_pack_job_heartbeat_should_renew([
        'runtime_expires_at' => date('Y-m-d H:i:s', $base - 1),
        'gpu_required' => false,
        'gpu_expires_at' => null,
        'skipped_ticks' => 0,
    ], $base), 'expired runtime lease must renew');
});

hub_test('pack heartbeat rejects invalid commits without mutating state', function (): void {
    foreach (['', '2026-02-30 12:00:00', '2026-08-14T12:01:00', 'tomorrow'] as $expiry) {
        $state = [
            'runtime_expires_at' => '2026-08-14 12:00:00',
            'gpu_required' => true,
            'gpu_expires_at' => '2026-08-14 12:00:00',
            'skipped_ticks' => 3,
        ];
        $before = serialize($state);
        $threw = false;
        try {
            hub_pack_job_heartbeat_mark_committed($state, $expiry);
        } catch (InvalidArgumentException) {
            $threw = true;
        }
        hub_test_assert($threw, 'invalid committed expiry must throw InvalidArgumentException');
        hub_test_assert(serialize($state) === $before, 'invalid committed expiry must leave state unchanged');
    }
});

hub_test('pack heartbeat counters normalize, saturate, and drain terminally', function (): void {
    foreach ([[], ['skipped_ticks' => -1], ['skipped_ticks' => 2.5], ['skipped_ticks' => '2']] as $state) {
        hub_pack_job_heartbeat_mark_skipped($state);
        hub_test_assert($state['skipped_ticks'] === 1, 'invalid skipped count must normalize before incrementing');
    }
    $saturated = ['skipped_ticks' => PHP_INT_MAX];
    hub_pack_job_heartbeat_mark_skipped($saturated);
    hub_test_assert($saturated['skipped_ticks'] === PHP_INT_MAX, 'skipped count must saturate at PHP_INT_MAX');

    $state = ['skipped_ticks' => 4];
    hub_test_assert(hub_pack_job_heartbeat_take_skipped($state) === 4 && $state['skipped_ticks'] === 0, 'take must consume and reset skips');
    hub_test_assert(hub_pack_job_heartbeat_take_skipped($state) === 0, 'take must be terminal after reset');
    $null = null;
    hub_test_assert(hub_pack_job_heartbeat_take_skipped($null) === 0, 'take must return zero for null state');
});

hub_test('CPU heartbeat commits one due renewal and skips writes while checking cancellation', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $db->prepare("UPDATE runtime_runs SET heartbeat_at = '2000-01-01 00:00:00', lease_expires_at = :expires_at WHERE id = :id")->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() + 1),
        ':id' => (int)$run['id'],
    ]);
    $run = hub_runtime_fetch_run($db, (int)$run['id']);
    if (!is_array($run)) {
        throw new RuntimeException('CPU heartbeat fixture must retain its runtime.');
    }
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === null, 'due CPU heartbeat must stay alive');
    $renewed = hub_runtime_fetch_run($db, (int)$run['id']);
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'cpu');
    hub_test_assert(is_array($renewed)
        && $state['runtime_expires_at'] === $renewed['lease_expires_at']
        && $state['skipped_ticks'] === 0,
        'committed CPU renewal must update database and memory to one exact expiry');
    hub_test_assert(count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'autocommit'
        && ($events[0]['lock_wait_kind'] ?? null) === 'first_write_upper_bound'
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'CPU renewal must emit one committed autocommit heartbeat with consumed skips');

    $beforeHeartbeat = [(string)$renewed['heartbeat_at'], (string)$renewed['lease_expires_at']];
    hub_test_assert(hub_runtime_request_cancel($db, (int)$run['id'], 'test cancellation'), 'CPU fixture must accept cancellation');
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === 'cancelled', 'skipped CPU heartbeat must still observe cancellation');
    $after = hub_runtime_fetch_run($db, (int)$run['id']);
    hub_test_assert(is_array($after)
        && [(string)$after['heartbeat_at'], (string)$after['lease_expires_at']] === $beforeHeartbeat
        && $state['skipped_ticks'] === 1
        && hub_test_runtime_telemetry_heartbeat_events_after($before, 'cpu') === [],
        'healthy CPU skip must leave heartbeat values unchanged, increment skips, and emit nothing');
});

hub_test('CPU heartbeat fails safe for malformed memory and an expired database fence', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['runtime_expires_at'] = 'tomorrow';
    $state['skipped_ticks'] = 3;
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === null, 'malformed CPU state must renew rather than skip');
    $renewed = hub_runtime_fetch_run($db, (int)$run['id']);
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'cpu');
    hub_test_assert(is_array($renewed)
        && $state['runtime_expires_at'] === $renewed['lease_expires_at']
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['skipped_ticks'] ?? null) === 3,
        'malformed CPU memory must commit a renewal and consume skips');

    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() - 1),
        ':id' => (int)$run['id'],
    ]);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['skipped_ticks'] = 5;
    $beforeState = serialize($state);
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === 'fence_lost', 'expired CPU database lease must lose its fence');
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'cpu');
    hub_test_assert(serialize($state) === $beforeState
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'fence_lost'
        && ($events[0]['skipped_ticks'] ?? null) === 0,
        'CPU fence loss must leave memory and skips unchanged');
});

hub_test('heartbeat writers preserve supplied exact expiry and reject normalized dates', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $stats = [];
    $expiry = '2030-01-02 03:04:05';

    hub_test_assert(hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], 60, $expiry, $stats), 'CPU writer must renew a valid fence');
    $renewed = hub_runtime_fetch_run($db, (int)$run['id']);
    foreach (['tx_begin_at', 'tx_commit_at', 'begin_requested_ns', 'tx_started_ns', 'tx_ended_ns', 'lock_wait_ms', 'lock_wait_kind', 'retry_count', 'lock_exhausted'] as $field) {
        hub_test_assert(array_key_exists($field, $stats), 'CPU heartbeat stats must include ' . $field);
    }
    hub_test_assert(is_array($renewed)
        && $renewed['lease_expires_at'] === $expiry
        && ($stats['lock_wait_kind'] ?? null) === 'first_write_upper_bound'
        && ($stats['retry_count'] ?? null) === 0
        && ($stats['lock_exhausted'] ?? null) === false,
        'CPU writer must retain its supplied exact expiry and autocommit stats');
    $before = [(string)$renewed['heartbeat_at'], (string)$renewed['lease_expires_at']];
    hub_test_assert(hub_test_throws(static function () use ($db, $run): void {
        hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], 60, '2026-02-30 12:00:00');
    }), 'CPU writer must reject impossible supplied expiry instead of normalizing it');
    $after = hub_runtime_fetch_run($db, (int)$run['id']);
    hub_test_assert(is_array($after) && [(string)$after['heartbeat_at'], (string)$after['lease_expires_at']] === $before, 'invalid supplied CPU expiry must not write');
});

hub_test('GPU heartbeat commits a single two-row renewal with one exact expiry', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU heartbeat fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, $lease, 60, $state) === null, 'due GPU heartbeat must stay alive');
    $runtime = hub_runtime_fetch_run($db, (int)$run['id']);
    $gpu = hub_runtime_gpu_fetch($db);
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'gpu');
    hub_test_assert(is_array($runtime) && is_array($gpu)
        && $runtime['lease_expires_at'] === $gpu['lease_expires_at']
        && $runtime['lease_expires_at'] === $state['runtime_expires_at']
        && $gpu['lease_expires_at'] === $state['gpu_expires_at'],
        'GPU heartbeat must write byte-identical expiry to both rows and both memory fields');
    hub_test_assert(count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'immediate'
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'two-row GPU transaction must emit one committed heartbeat');
});

hub_test('GPU heartbeat rolls back both rows before emitting a failed event', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU rollback fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $state['skipped_ticks'] = 4;
    $beforeState = serialize($state);
    $beforeRuntime = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $db->exec("CREATE TEMP TRIGGER heartbeat_gpu_abort BEFORE UPDATE ON runtime_resource_leases WHEN NEW.resource_key = 'gpu:0' BEGIN SELECT RAISE(ABORT, 'heartbeat_gpu_abort'); END");
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    try {
        hub_pack_job_tick($db, $run, $lease, 60, $state);
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS heartbeat_gpu_abort');
    }
    $afterRuntime = hub_runtime_fetch_run($db, (int)$run['id']);
    $afterGpu = hub_runtime_gpu_fetch($db);
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'gpu');

    hub_test_assert($error instanceof PDOException
        && serialize($state) === $beforeState
        && serialize($afterRuntime) === serialize($beforeRuntime)
        && serialize($afterGpu) === serialize($beforeGpu),
        'GPU trigger failure must roll back both rows and leave memory unchanged');
    hub_test_assert(count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'failed'
        && ($events[0]['skipped_ticks'] ?? null) === 0,
        'GPU trigger failure must emit one failed heartbeat only after rollback');
});

hub_test('GPU heartbeat leaves state unchanged when its runtime or GPU fence is lost', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU fence fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $state['skipped_ticks'] = 5;
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() - 1),
        ':id' => (int)$run['id'],
    ]);
    $beforeState = serialize($state);
    $beforeRuntime = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $before = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, $lease, 60, $state) === 'fence_lost', 'expired GPU runtime fence must fail');
    $events = hub_test_runtime_telemetry_heartbeat_events_after($before, 'gpu');
    hub_test_assert(serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRuntime)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'fence_lost',
        'expired GPU fence must roll back and preserve rows and memory');

    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU mismatch fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $beforeState = serialize($state);
    $beforeRuntime = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $mismatched = $lease;
    $mismatched['lease_token'] = 'mismatched-fence';

    hub_test_assert(hub_pack_job_tick($db, $run, $mismatched, 60, $state) === 'fence_lost', 'mismatched GPU fence must fail');
    hub_test_assert(serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRuntime)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu),
        'mismatched GPU fence must leave rows and memory unchanged');
});

hub_test('direct GPU heartbeat preserves a raw caller-owned transaction on nested BEGIN', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('Raw GPU heartbeat fixture must acquire GPU.');
    }
    $markerKey = 'gpu-heartbeat-raw-' . bin2hex(random_bytes(4));
    $stats = [];
    $open = false;
    $db->exec('BEGIN IMMEDIATE');
    $open = true;
    try {
        hub_test_assert(!$db->inTransaction(), 'PDO must not report raw GPU heartbeat transaction ownership');
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'caller-owned',
            ':updated_at' => hub_now(),
        ]);
        $error = null;
        try {
            hub_runtime_gpu_heartbeat($db, $run, $lease, 60, '2030-01-02 03:04:05', $stats);
        } catch (Throwable $e) {
            $error = $e;
        }
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($error instanceof PDOException
            && $marker->fetchColumn() === 'caller-owned'
            && ($stats['tx_started_ns'] ?? null) === null
            && ($stats['lock_exhausted'] ?? true) === false,
            'nested raw GPU heartbeat must rethrow without rolling back its caller');
        $db->exec('ROLLBACK');
        $open = false;
    } finally {
        if ($open) {
            $db->exec('ROLLBACK');
        }
    }
});

hub_test('CPU heartbeat classifies a real locked autocommit writer failure', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['skipped_ticks'] = 2;
    $beforeState = serialize($state);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $error = null;

    hub_test_runtime_telemetry_with_sqlite_writer_lock(200000, static function (string $attemptPath) use ($run, &$state, &$error): void {
        $lockedDb = new PDO('sqlite:' . HUB_DB_PATH);
        $lockedDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $lockedDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $lockedDb->exec('PRAGMA busy_timeout = 0');
        if (file_put_contents($attemptPath, "attempt\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot signal SQLite CPU heartbeat attempt.');
        }
        try {
            hub_pack_job_tick($lockedDb, $run, null, 60, $state);
        } catch (Throwable $e) {
            $error = $e;
        }
        hub_test_assert(!$lockedDb->inTransaction(), 'locked CPU heartbeat must not leave a caller transaction open');
    });

    $events = hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu');
    hub_test_assert($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'database is locked'), 'CPU heartbeat fixture must throw the real SQLite lock error');
    hub_test_assert(serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && ($events[0]['retry_count'] ?? null) === 0,
        'locked CPU heartbeat must preserve memory and emit one lock_exhausted event');
});

hub_test('GPU heartbeat classifies a real locked raw BEGIN failure', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU lock fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $state['skipped_ticks'] = 2;
    $beforeState = serialize($state);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $error = null;

    hub_test_runtime_telemetry_with_sqlite_writer_lock(200000, static function (string $attemptPath) use ($run, $lease, &$state, &$error): void {
        $lockedDb = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        try {
            hub_pack_job_tick($lockedDb, $run, $lease, 60, $state);
        } catch (Throwable $e) {
            $error = $e;
        }
        hub_test_assert(!$lockedDb->inTransaction(), 'locked GPU heartbeat must not leave a caller transaction open');
    });

    $events = hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu');
    hub_test_assert($error instanceof PDOException && str_contains(strtolower($error->getMessage()), 'database is locked'), 'GPU heartbeat fixture must throw the real SQLite lock error');
    hub_test_assert(serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && ($events[0]['retry_count'] ?? null) === 0
        && $events[0]['tx_begin_at'] === null
        && is_string($events[0]['tx_commit_at'] ?? null)
        && (float)($events[0]['tx_ms'] ?? -1) === 0.0,
        'locked GPU heartbeat must preserve rows and memory and emit zero-duration lock_exhausted telemetry');
});

hub_test('skipped CPU heartbeat rejects expired and divergent live runtime leases without writes', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $memoryExpiry = date('Y-m-d H:i:s', time() + 60);
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['runtime_expires_at'] = $memoryExpiry;
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() - 1),
        ':id' => (int)$run['id'],
    ]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === 'fence_lost', 'skipped CPU heartbeat must reject a lease that expired while the task was paused');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && $state['runtime_expires_at'] === $memoryExpiry
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu') === [],
        'expired skipped CPU fence must not write or emit telemetry');

    $state['skipped_ticks'] = 0;
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() + 59),
        ':id' => (int)$run['id'],
    ]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === 'fence_lost', 'skipped CPU heartbeat must reject live expiry divergence from shared memory');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu') === [],
        'divergent skipped CPU fence must not write or emit telemetry');
});

hub_test('skipped heartbeat rejects a live runtime row whose run id changed', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $memoryExpiry = date('Y-m-d H:i:s', time() + 60);
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['runtime_expires_at'] = $memoryExpiry;
    $db->prepare('UPDATE runtime_runs SET run_id = :run_id, lease_expires_at = :expires_at WHERE id = :id')->execute([
        ':run_id' => 'changed-live-run-' . bin2hex(random_bytes(4)),
        ':expires_at' => $memoryExpiry,
        ':id' => (int)$run['id'],
    ]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, null, 60, $state) === 'fence_lost', 'skipped heartbeat must reject a live runtime row whose run id no longer matches the claimed run');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu') === [],
        'changed live runtime run id must not write or emit telemetry');
});

hub_test('skipped GPU heartbeat rejects expired and mismatched live GPU leases without writes', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('Skipped GPU fence fixture must acquire GPU.');
    }
    $memoryExpiry = date('Y-m-d H:i:s', time() + 60);
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = $memoryExpiry;
    $state['gpu_expires_at'] = $memoryExpiry;
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => $memoryExpiry, ':id' => (int)$run['id']]);
    $db->prepare("UPDATE runtime_resource_leases SET lease_expires_at = :expires_at WHERE resource_key = 'gpu:0'")->execute([':expires_at' => date('Y-m-d H:i:s', time() - 1)]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, $lease, 60, $state) === 'fence_lost', 'skipped GPU heartbeat must reject an expired live GPU lease');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu') === [],
        'expired skipped GPU fence must not write or emit telemetry');

    $state['skipped_ticks'] = 0;
    $db->prepare("UPDATE runtime_resource_leases SET lease_expires_at = :expires_at, lease_token = 'lost-skipped-gpu-fence' WHERE resource_key = 'gpu:0'")->execute([':expires_at' => $memoryExpiry]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, $lease, 60, $state) === 'fence_lost', 'skipped GPU heartbeat must reject a mismatched live GPU identity');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu') === [],
        'mismatched skipped GPU fence must not write or emit telemetry');

    $state['skipped_ticks'] = 0;
    $db->prepare("UPDATE runtime_resource_leases SET state = 'available', lease_token = :lease_token WHERE resource_key = 'gpu:0'")->execute([':lease_token' => (string)$lease['lease_token']]);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());

    hub_test_assert(hub_pack_job_tick($db, $run, $lease, 60, $state) === 'fence_lost', 'skipped GPU heartbeat must reject a live GPU lease that is no longer leased');
    hub_test_assert(serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu') === [],
        'unleased skipped GPU fence must not write or emit telemetry');
});

hub_test('GPU heartbeat rejects a PDO caller transaction without telemetry', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('PDO GPU heartbeat fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $markerKey = 'gpu-heartbeat-pdo-' . bin2hex(random_bytes(4));
    $directStats = [];
    $directError = null;
    $tickError = null;
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'caller-owned',
            ':updated_at' => hub_now(),
        ]);
        try {
            hub_runtime_gpu_heartbeat($db, $run, $lease, 60, '2030-01-02 03:04:05', $directStats);
        } catch (Throwable $e) {
            $directError = $e;
        }
        try {
            hub_pack_job_tick($db, $run, $lease, 60, $state);
        } catch (Throwable $e) {
            $tickError = $e;
        }
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($directError instanceof LogicException
            && $directError->getMessage() === 'runtime_gpu_heartbeat_transaction_required'
            && $tickError instanceof LogicException
            && $tickError->getMessage() === 'runtime_gpu_heartbeat_transaction_required'
            && ($directStats['transaction_closed'] ?? true) === false
            && $db->inTransaction()
            && $marker->fetchColumn() === 'caller-owned',
            'GPU heartbeat must reject a PDO caller transaction without closing it');
        hub_test_assert(serialize($state) === $beforeState
            && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
            && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
            && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu') === [],
            'PDO GPU transaction rejection must leave rows and memory unchanged without telemetry');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $marker = $db->prepare('SELECT 1 FROM settings WHERE key = :key');
    $marker->execute([':key' => $markerKey]);
    hub_test_assert($marker->fetchColumn() === false, 'GPU caller rollback must discard its marker');
});

hub_test('CPU heartbeat rejects a PDO caller transaction without telemetry', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $markerKey = 'cpu-heartbeat-pdo-' . bin2hex(random_bytes(4));
    $directStats = [];
    $directError = null;
    $tickError = null;
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'caller-owned',
            ':updated_at' => hub_now(),
        ]);
        try {
            hub_runtime_heartbeat($db, (int)$run['id'], (string)$run['lease_token'], 60, '2030-01-02 03:04:05', $directStats);
        } catch (Throwable $e) {
            $directError = $e;
        }
        try {
            hub_pack_job_tick($db, $run, null, 60, $state);
        } catch (Throwable $e) {
            $tickError = $e;
        }
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($directError instanceof LogicException
            && $directError->getMessage() === 'runtime_heartbeat_transaction_required'
            && $tickError instanceof LogicException
            && $tickError->getMessage() === 'runtime_heartbeat_transaction_required'
            && ($directStats['transaction_closed'] ?? true) === false
            && $db->inTransaction()
            && $marker->fetchColumn() === 'caller-owned',
            'CPU heartbeat must reject a PDO caller transaction without closing it');
        hub_test_assert(serialize($state) === $beforeState
            && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
            && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu') === [],
            'PDO CPU transaction rejection must leave rows and memory unchanged without telemetry');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    $marker = $db->prepare('SELECT 1 FROM settings WHERE key = :key');
    $marker->execute([':key' => $markerKey]);
    hub_test_assert($marker->fetchColumn() === false, 'CPU caller rollback must discard its marker');
});

hub_test('CPU heartbeat rejects a raw caller transaction before its due renewal write', function (): void {
    $db = hub_test_reset_db();
    [, $run] = hub_test_runtime_telemetry_heartbeat_run($db);
    $db->prepare("UPDATE runtime_runs SET heartbeat_at = '2000-01-01 00:00:00', lease_expires_at = :expires_at WHERE id = :id")->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() + 1),
        ':id' => (int)$run['id'],
    ]);
    $run = hub_runtime_fetch_run($db, (int)$run['id']);
    if (!is_array($run)) {
        throw new RuntimeException('Raw CPU heartbeat fixture must retain its runtime.');
    }
    $state = hub_pack_job_heartbeat_state($run, null);
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $beforeRun = serialize($run);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $markerKey = 'cpu-heartbeat-raw-' . bin2hex(random_bytes(4));
    $rawTransactionOpen = false;
    $db->exec('BEGIN IMMEDIATE');
    $rawTransactionOpen = true;
    try {
        hub_test_assert(!$db->inTransaction(), 'PDO must not report the raw CPU heartbeat transaction');
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'caller-owned',
            ':updated_at' => hub_now(),
        ]);
        $error = null;
        try {
            hub_pack_job_tick($db, $run, null, 60, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($error instanceof PDOException
            && $marker->fetchColumn() === 'caller-owned'
            && serialize($state) === $beforeState
            && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === $beforeRun
            && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'cpu') === [],
            'raw CPU heartbeat must reject before writing or mutating shared state');
        hub_test_assert($db->exec('ROLLBACK') !== false, 'raw CPU heartbeat caller must be able to roll back its transaction');
        $rawTransactionOpen = false;
    } finally {
        if ($rawTransactionOpen) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }
    $marker = $db->prepare('SELECT 1 FROM settings WHERE key = :key');
    $marker->execute([':key' => $markerKey]);
    hub_test_assert($marker->fetchColumn() === false
        && serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === $beforeRun,
        'raw CPU caller rollback must discard its marker and preserve heartbeat state');
});

hub_test('GPU heartbeat suppresses telemetry when a rollback-triggered close is unconfirmed', function (): void {
    $db = hub_test_reset_db();
    [$task, $run] = hub_test_runtime_telemetry_heartbeat_run($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $task, $run, 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU rollback-close fixture must acquire GPU.');
    }
    $state = hub_pack_job_heartbeat_state($run, $lease);
    $state['runtime_expires_at'] = date('Y-m-d H:i:s', time() + 1);
    $state['gpu_expires_at'] = $state['runtime_expires_at'];
    $beforeState = serialize($state);
    $beforeRun = hub_runtime_fetch_run($db, (int)$run['id']);
    $beforeGpu = hub_runtime_gpu_fetch($db);
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $db->exec("CREATE TEMP TRIGGER heartbeat_gpu_rollback BEFORE UPDATE ON runtime_resource_leases WHEN NEW.resource_key = 'gpu:0' BEGIN SELECT RAISE(ROLLBACK, 'heartbeat_gpu_rollback'); END");
    $error = null;
    $unlocked = false;
    try {
        hub_pack_job_tick($db, $run, $lease, 60, $state);
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS heartbeat_gpu_rollback');
    }
    try {
        $db->exec('BEGIN IMMEDIATE');
        $db->exec('ROLLBACK');
        $unlocked = true;
    } catch (Throwable) {
    }

    hub_test_assert($error instanceof PDOException && str_contains($error->getMessage(), 'heartbeat_gpu_rollback'), 'rollback trigger must preserve the original UPDATE exception');
    hub_test_assert(serialize($state) === $beforeState
        && serialize(hub_runtime_fetch_run($db, (int)$run['id'])) === serialize($beforeRun)
        && serialize(hub_runtime_gpu_fetch($db)) === serialize($beforeGpu)
        && hub_test_runtime_telemetry_heartbeat_events_after($beforeEvents, 'gpu') === []
        && $unlocked,
        'unconfirmed GPU close must suppress telemetry, preserve memory, and leave no lock');
});

hub_test('published Pack success emits one committed finish event and drains terminal skips', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $published = hub_handoff_pack_job_artifacts($db, (int)$fixture['task']['id'], $fixture['run'], $fixture['artifacts']);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());

    $result = hub_commit_published_pack_job_success($db, (int)$fixture['task']['id'], $fixture['run'], $published, $fixture['cleanup'], null, null, $state);
    $events = hub_test_runtime_telemetry_events_after($before, 'finish', 'success');
    hub_test_assert(($result['ok'] ?? false) === true
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'success'
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'deferred'
        && ($events[0]['lock_wait_kind'] ?? null) === 'first_write_upper_bound'
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'published Pack success must emit after commit and consume its skipped ticks once');

    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $published = hub_handoff_pack_job_artifacts($db, (int)$fixture['task']['id'], $fixture['run'], $fixture['artifacts']);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 2;
    $path = hub_runtime_telemetry_path(new DateTimeImmutable());
    $previous = is_file($path) ? file_get_contents($path) : null;
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Cannot prepare terminal telemetry writer failure.');
    }
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Cannot block terminal telemetry writer.');
    }
    try {
        $result = hub_commit_published_pack_job_success($db, (int)$fixture['task']['id'], $fixture['run'], $published, $fixture['cleanup'], null, null, $state);
    } finally {
        rmdir($path);
        if (is_string($previous)) {
            file_put_contents($path, $previous, LOCK_EX);
        }
    }
    hub_test_assert(($result['ok'] ?? false) === true
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'success'
        && $state['skipped_ticks'] === 0,
        'a terminal telemetry writer failure must not undo success or retain skips');
});

hub_test('Pack failures emit one committed finish failure event for every terminal status', function (): void {
    $db = hub_test_reset_db();
    foreach (['failed', 'cancelled', 'timed_out'] as $status) {
        $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
        if ($status === 'cancelled') {
            $db->prepare('UPDATE runtime_runs SET cancel_requested_at = :now WHERE id = :id')->execute([
                ':now' => hub_now(),
                ':id' => (int)$fixture['run']['id'],
            ]);
        }
        if ($status === 'timed_out') {
            $db->prepare('UPDATE runtime_runs SET timeout_at = :now WHERE id = :id')->execute([
                ':now' => '2000-01-01 00:00:00',
                ':id' => (int)$fixture['run']['id'],
            ]);
        }
        $state = hub_pack_job_heartbeat_state($fixture['run'], null);
        $state['skipped_ticks'] = 2;
        $before = count(hub_test_runtime_telemetry_events());
        hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], $status, $status, 'terminal fixture', $fixture['cleanup'], null, $state);
        $events = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');
        hub_test_assert((hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === $status
            && $state['skipped_ticks'] === 0
            && count($events) === 1
            && ($events[0]['outcome'] ?? null) === 'committed'
            && ($events[0]['skipped_ticks'] ?? null) === 2,
            'Pack ' . $status . ' must emit one committed finish/failure event');
    }
});

hub_test('a short Pack task drains skipped ticks only at its first terminal attempt', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    hub_test_assert(hub_pack_job_tick($db, $fixture['run'], null, 60, $state) === null && $state['skipped_ticks'] === 1, 'short Pack task must skip an unnecessary renewal');
    $before = count(hub_test_runtime_telemetry_events());
    hub_pack_job_adapter_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
    $first = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');

    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $before = count(hub_test_runtime_telemetry_events());
    hub_pack_job_adapter_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
    $second = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');
    hub_test_assert($state['skipped_ticks'] === 0
        && count($first) === 1 && ($first[0]['skipped_ticks'] ?? null) === 1
        && count($second) === 1 && ($second[0]['skipped_ticks'] ?? null) === 0,
        'a reset heartbeat state must not repeat terminal skipped ticks');
});

hub_test('terminal fences and rollback failures emit after the transaction closes', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $published = hub_handoff_pack_job_artifacts($db, (int)$fixture['task']['id'], $fixture['run'], $fixture['artifacts']);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 3;
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    try {
        hub_commit_published_pack_job_success(
            $db,
            (int)$fixture['task']['id'],
            $fixture['run'],
            $published,
            $fixture['cleanup'],
            null,
            static function () use ($db, $fixture): void {
                $db->prepare('UPDATE runtime_runs SET lease_token = :lease_token WHERE id = :id')->execute([
                    ':lease_token' => 'terminal-fence-lost',
                    ':id' => (int)$fixture['run']['id'],
                ]);
            },
            $state,
        );
    } catch (Throwable $caught) {
        $error = $caught;
    }
    $fenceEvents = hub_test_runtime_telemetry_events_after($before, 'finish', 'success');
    hub_test_assert($error instanceof Throwable
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'running'
        && $state['skipped_ticks'] === 0
        && count($fenceEvents) === 1
        && ($fenceEvents[0]['outcome'] ?? null) === 'fence_lost'
        && ($fenceEvents[0]['skipped_ticks'] ?? null) === 3,
        'a terminal fence conflict must roll back before emitting finish/success');

    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 4;
    $db->exec("CREATE TEMP TRIGGER terminal_telemetry_rollback BEFORE UPDATE ON runtime_runs WHEN NEW.id = " . (int)$fixture['run']['id'] . " BEGIN SELECT RAISE(FAIL, 'terminal_telemetry_rollback'); END");
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    try {
        hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS terminal_telemetry_rollback');
    }
    $rollbackEvents = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');
    hub_test_assert($error instanceof PDOException
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'running'
        && $state['skipped_ticks'] === 0
        && count($rollbackEvents) === 1
        && ($rollbackEvents[0]['outcome'] ?? null) === 'rolled_back'
        && ($rollbackEvents[0]['skipped_ticks'] ?? null) === 4,
        'a terminal trigger failure must roll back before emitting finish/failure');
});

hub_test('expired Pack recovery emits one matching committed event and drains skips', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$fixture['run']['id']]);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());
    $reconciled = hub_pack_job_reconcile_lost_fence($db, hub_get_task($db, (int)$fixture['task']['id']), $fixture['run'], $fixture['cleanup'], null, $state);
    $events = hub_test_runtime_telemetry_events_after($before, 'recovery', 'runtime');
    hub_test_assert($reconciled
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['tx_mode'] ?? null) === 'immediate'
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'expired CPU recovery must emit one committed recovery/runtime event');
});

hub_test('GPU recovery emits once as GPU and recovery fence loss drains only after rollback', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $fixture['task'], $fixture['run'], 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU recovery telemetry fixture must acquire a lease.');
    }
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$fixture['run']['id']]);
    $before = count(hub_test_runtime_telemetry_events());
    hub_test_assert(hub_reconcile_expired_pack_job_runs($db) === 1, 'expired GPU fixture must reconcile once');
    $events = array_values(array_filter(array_slice(hub_test_runtime_telemetry_events(), $before), static fn (array $event): bool => ($event['action'] ?? null) === 'recovery'));
    hub_test_assert(count($events) === 1
        && ($events[0]['variant'] ?? null) === 'gpu'
        && ($events[0]['outcome'] ?? null) === 'committed'
        && ($events[0]['skipped_ticks'] ?? null) === 0,
        'matching GPU recovery must never duplicate recovery/runtime telemetry');

    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$fixture['run']['id']]);
    $task = hub_get_task($db, (int)$fixture['task']['id']);
    $db->prepare('UPDATE tasks SET lock_token = :lock_token WHERE id = :id')->execute([
        ':lock_token' => 'changed-recovery-lock',
        ':id' => (int)$fixture['task']['id'],
    ]);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());
    $reconciled = hub_pack_job_reconcile_lost_fence($db, $task, $fixture['run'], $fixture['cleanup'], null, $state);
    $events = hub_test_runtime_telemetry_events_after($before, 'recovery', 'runtime');
    hub_test_assert(!$reconciled
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'fence_lost'
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'a recovery fence mismatch must emit after its rollback and consume skips');
});

hub_test('Pack terminal and recovery reject caller transactions without telemetry or skip consumption', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $before = count(hub_test_runtime_telemetry_events());
    $db->beginTransaction();
    try {
        $error = null;
        try {
            hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert($error instanceof LogicException
            && $db->inTransaction()
            && serialize($state) === $beforeState
            && hub_test_runtime_telemetry_events_after($before, 'finish', 'failure') === [],
            'PDO terminal caller transaction must remain open without telemetry or skip consumption');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$fixture['run']['id']]);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $before = count(hub_test_runtime_telemetry_events());
    $db->exec('BEGIN IMMEDIATE');
    try {
        $error = null;
        try {
            hub_pack_job_reconcile_lost_fence($db, hub_get_task($db, (int)$fixture['task']['id']), $fixture['run'], $fixture['cleanup'], null, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert($error instanceof PDOException
            && serialize($state) === $beforeState
            && hub_test_runtime_telemetry_events_after($before, 'recovery', 'runtime') === [],
            'raw recovery caller transaction must remain untouched without telemetry or skip consumption');
    } finally {
        $db->exec('ROLLBACK');
    }
});

hub_test('GPU terminal fence timing captures its first no-op runtime write', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db, 'gpu');
    $lease = hub_runtime_gpu_acquire_for_task($db, $fixture['task'], $fixture['run'], 60);
    if (!is_array($lease)) {
        throw new RuntimeException('GPU terminal timing fixture must acquire a lease.');
    }
    $timing = [];
    $db->beginTransaction();
    try {
        $matched = hub_runtime_gpu_runtime_fence_in_transaction($db, $fixture['run'], (int)$fixture['task']['id'], $timing);
        hub_test_assert($matched
            && is_int($timing['started_ns'] ?? null)
            && is_int($timing['ended_ns'] ?? null)
            && $timing['ended_ns'] >= $timing['started_ns'],
            'GPU terminal timing must surround its existing no-op runtime UPDATE only');
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
});

hub_test('terminal writer lock emits one exhausted finish event after rollback', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 6;
    $beforeState = serialize($state);
    $beforeRun = serialize(hub_runtime_fetch_run($db, (int)$fixture['run']['id']));
    $beforeTask = serialize(hub_get_task($db, (int)$fixture['task']['id']));
    $beforeEvents = count(hub_test_runtime_telemetry_events());
    $error = null;
    hub_test_runtime_telemetry_with_sqlite_writer_lock(700000, static function (string $attemptPath) use ($fixture, &$state, &$error): void {
        $terminalDb = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        if (file_put_contents($attemptPath, "attempt\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot signal terminal write attempt.');
        }
        try {
            hub_commit_pack_job_failure($terminalDb, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert(!$terminalDb->inTransaction(), 'terminal writer lock must roll back before returning');
    });
    $events = hub_test_runtime_telemetry_events_after($beforeEvents, 'finish', 'failure');
    hub_test_assert($error instanceof PDOException
        && (int)($error->errorInfo[1] ?? 0) === 5
        && serialize($state) !== $beforeState
        && ($state['skipped_ticks'] ?? null) === 0
        && serialize(hub_runtime_fetch_run($db, (int)$fixture['run']['id'])) === $beforeRun
        && serialize(hub_get_task($db, (int)$fixture['task']['id'])) === $beforeTask
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && ($events[0]['skipped_ticks'] ?? null) === 6,
        'terminal SQLITE_BUSY must emit exhausted telemetry only after rollback and skip consumption');
    $observedAt = new DateTimeImmutable((string)$events[0]['observed_at']);
    $line = hub_test_runtime_telemetry_fixture_line($events[0]);
    $summary = hub_runtime_telemetry_summary($observedAt, $observedAt, static function (string $_path) use ($line) {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $line);
        rewind($handle);

        return $handle;
    });
    hub_test_assert(($summary['groups']['finish/failure']['count'] ?? null) === 1
        && ($summary['groups']['finish/failure']['exhausted'] ?? null) === 1,
        'terminal lock_exhausted event must increment the summary exhausted count');
});

hub_test('terminal automatic rollback emits after close and preserves its statement error', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 5;
    $before = count(hub_test_runtime_telemetry_events());
    $db->exec("CREATE TEMP TRIGGER terminal_automatic_rollback BEFORE UPDATE ON runtime_runs WHEN NEW.id = " . (int)$fixture['run']['id'] . " BEGIN SELECT RAISE(ROLLBACK, 'terminal_automatic_rollback'); END");
    $error = null;
    try {
        hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS terminal_automatic_rollback');
    }
    $freshTransaction = false;
    try {
        $db->beginTransaction();
        $db->rollBack();
        $freshTransaction = true;
    } catch (Throwable) {
    }
    $events = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');
    $run = hub_runtime_fetch_run($db, (int)$fixture['run']['id']);
    hub_test_assert($error instanceof PDOException
        && str_contains($error->getMessage(), 'terminal_automatic_rollback')
        && !$db->inTransaction()
        && $freshTransaction
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'running'
        && ($run['state'] ?? null) === 'claimed'
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'rolled_back'
        && ($events[0]['skipped_ticks'] ?? null) === 5,
        'automatic terminal rollback must close before telemetry while preserving its original statement error');
});

hub_test('raw terminal BEGIN remains caller-owned without telemetry or skip consumption', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 3;
    $beforeState = serialize($state);
    $before = count(hub_test_runtime_telemetry_events());
    $markerKey = 'terminal-raw-' . bin2hex(random_bytes(4));
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('INSERT INTO settings (key, value, updated_at) VALUES (:key, :value, :updated_at)')->execute([
            ':key' => $markerKey,
            ':value' => 'caller-owned',
            ':updated_at' => hub_now(),
        ]);
        $error = null;
        try {
            hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $marker = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $marker->execute([':key' => $markerKey]);
        hub_test_assert($error instanceof PDOException
            && $marker->fetchColumn() === 'caller-owned'
            && serialize($state) === $beforeState
            && hub_test_runtime_telemetry_events_after($before, 'finish', 'failure') === [],
            'raw terminal BEGIN must remain intact without telemetry or skip consumption');
    } finally {
        $db->exec('ROLLBACK');
    }
});

hub_test('recovery lock exhaustion emits zero-duration telemetry after known no-begin close', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $db->prepare("UPDATE runtime_runs SET lease_expires_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$fixture['run']['id']]);
    $task = hub_get_task($db, (int)$fixture['task']['id']);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 2;
    $before = count(hub_test_runtime_telemetry_events());
    $error = null;
    hub_test_runtime_telemetry_with_sqlite_writer_lock(700000, static function (string $attemptPath) use ($task, $fixture, &$state, &$error): void {
        $lockedDb = hub_test_runtime_telemetry_locking_pdo($attemptPath);
        try {
            hub_pack_job_reconcile_lost_fence($lockedDb, $task, $fixture['run'], $fixture['cleanup'], null, $state);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        hub_test_assert(!$lockedDb->inTransaction(), 'exhausted recovery BEGIN must not leave a transaction open');
    });
    $events = hub_test_runtime_telemetry_events_after($before, 'recovery', 'runtime');
    hub_test_assert($error instanceof PDOException
        && str_contains(strtolower($error->getMessage()), 'database is locked')
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'lock_exhausted'
        && (float)($events[0]['tx_ms'] ?? -1) === 0.0
        && ($events[0]['skipped_ticks'] ?? null) === 2,
        'recovery lock exhaustion may consume skips only after the helper proves no transaction began');
});

hub_test('terminal commit failure rolls back and preserves its commit exception', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_runtime_telemetry_terminal_fixture($db);
    $state = hub_pack_job_heartbeat_state($fixture['run'], null);
    $state['skipped_ticks'] = 4;
    $before = count(hub_test_runtime_telemetry_events());
    $db->exec('PRAGMA defer_foreign_keys = ON');
    $db->exec("CREATE TEMP TRIGGER terminal_commit_foreign_key AFTER UPDATE OF state ON runtime_runs WHEN NEW.id = " . (int)$fixture['run']['id'] . " BEGIN INSERT INTO task_artifact_holds (source_artifact_id, downstream_task_id, held_at) VALUES (-1, NEW.task_id, '2000-01-01 00:00:00'); END");
    $error = null;
    try {
        hub_commit_pack_job_failure($db, (int)$fixture['task']['id'], $fixture['run'], 'failed', 'runtime_exit_nonzero', 'terminal fixture', $fixture['cleanup'], null, $state);
    } catch (Throwable $caught) {
        $error = $caught;
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS terminal_commit_foreign_key');
    }
    $events = hub_test_runtime_telemetry_events_after($before, 'finish', 'failure');
    hub_test_assert($error instanceof PDOException
        && str_contains($error->getMessage(), 'FOREIGN KEY constraint failed')
        && (hub_get_task($db, (int)$fixture['task']['id'])['status'] ?? null) === 'running'
        && $state['skipped_ticks'] === 0
        && count($events) === 1
        && ($events[0]['outcome'] ?? null) === 'rolled_back'
        && ($events[0]['skipped_ticks'] ?? null) === 4,
        'terminal commit failure must preserve its commit exception after a confirmed rollback');
});
