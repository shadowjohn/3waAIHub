<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$actions = array_values(array_filter(array_slice($argv, 1), static fn (string $arg): bool => in_array($arg, ['--status', '--checkpoint', '--vacuum'], true)));
if (count($actions) !== 1) {
    fwrite(STDERR, "Usage: php scripts/db_maintenance.php --status|--checkpoint|--vacuum [--yes]\n");
    exit(2);
}

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);

match ($actions[0]) {
    '--status' => hub_db_maintenance_status($db),
    '--checkpoint' => hub_db_maintenance_checkpoint($db),
    '--vacuum' => hub_db_maintenance_vacuum($db, in_array('--yes', $argv, true)),
};

function hub_db_maintenance_status(PDO $db): void
{
    $dbPath = HUB_DB_PATH;
    $walPath = HUB_DB_PATH . '-wal';
    $shmPath = HUB_DB_PATH . '-shm';
    $pageCount = (int)$db->query('PRAGMA page_count')->fetchColumn();
    $freelistCount = (int)$db->query('PRAGMA freelist_count')->fetchColumn();
    $pageSize = (int)$db->query('PRAGMA page_size')->fetchColumn();
    $maxSizeMb = (int)hub_get_storage_setting($db, 'AIHUB_DB_MAX_SIZE_MB');
    $dbSize = hub_file_size($dbPath);

    $report = [
        'ok' => true,
        'database' => [
            'path' => $dbPath,
            'size_bytes' => $dbSize,
            'size_human' => hub_human_bytes($dbSize),
            'wal_size_bytes' => hub_file_size($walPath),
            'wal_size_human' => hub_human_bytes(hub_file_size($walPath)),
            'shm_size_bytes' => hub_file_size($shmPath),
            'shm_size_human' => hub_human_bytes(hub_file_size($shmPath)),
            'max_size_mb' => $maxSizeMb,
            'over_max_size' => $maxSizeMb > 0 && $dbSize > ($maxSizeMb * 1024 * 1024),
        ],
        'pragmas' => [
            'journal_mode' => strtolower((string)$db->query('PRAGMA journal_mode')->fetchColumn()),
            'busy_timeout' => (int)$db->query('PRAGMA busy_timeout')->fetchColumn(),
            'foreign_keys' => (int)$db->query('PRAGMA foreign_keys')->fetchColumn(),
            'synchronous' => (int)$db->query('PRAGMA synchronous')->fetchColumn(),
            'page_count' => $pageCount,
            'freelist_count' => $freelistCount,
            'page_size' => $pageSize,
            'freelist_bytes_estimate' => $freelistCount * $pageSize,
            'freelist_human_estimate' => hub_human_bytes($freelistCount * $pageSize),
        ],
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function hub_db_maintenance_checkpoint(PDO $db): void
{
    $rows = $db->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
    echo json_encode([
        'ok' => true,
        'action' => 'checkpoint',
        'mode' => 'TRUNCATE',
        'result' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function hub_db_maintenance_vacuum(PDO $db, bool $yes): void
{
    if (!$yes) {
        fwrite(STDERR, "WARNING: VACUUM rewrites the SQLite database and can take time. Type VACUUM to continue: ");
        $answer = trim((string)fgets(STDIN));
        if ($answer !== 'VACUUM') {
            fwrite(STDERR, "VACUUM cancelled.\n");
            exit(2);
        }
    }

    $db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
    $db->exec('VACUUM');
    echo json_encode([
        'ok' => true,
        'action' => 'vacuum',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

function hub_file_size(string $path): int
{
    return is_file($path) ? (int)filesize($path) : 0;
}

function hub_human_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TB') {
            return round($value, $unit === 'B' ? 0 : 1) . ' ' . $unit;
        }
        $value /= 1024;
    }

    return $bytes . ' B';
}
