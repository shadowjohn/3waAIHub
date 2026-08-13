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

function hub_test_runtime_telemetry_locking_pdo(string $attemptPath): PDO
{
    $db = new class('sqlite:' . HUB_DB_PATH, $attemptPath) extends PDO {
        private bool $attemptSignaled = false;

        public function __construct(string $dsn, private string $attemptPath)
        {
            parent::__construct($dsn);
        }

        public function exec(string $statement): int|false
        {
            if (!$this->attemptSignaled && $statement === 'BEGIN IMMEDIATE') {
                if (is_file($this->attemptPath) || file_put_contents($this->attemptPath, "attempt\n", LOCK_EX) === false) {
                    throw new RuntimeException('Cannot signal SQLite BEGIN attempt.');
                }
                $this->attemptSignaled = true;
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

    foreach ([null, 123, '', 'not-a-date', '2026-02-30 12:00:00'] as $expiry) {
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
