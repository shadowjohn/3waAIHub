<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$dryRun = in_array('--dry-run', $argv, true);
$apply = in_array('--apply', $argv, true);
if ($dryRun === $apply) {
    fwrite(STDERR, "Usage: php scripts/prune_db.php --dry-run|--apply\n");
    exit(2);
}

$db = hub_db();
$missing = hub_runtime_schema_missing($db);
if ($missing !== []) {
    fwrite(STDERR, 'schema_upgrade_required: ' . implode(', ', $missing) . '. Run php scripts/init_db.php.' . PHP_EOL);
    exit(1);
}
hub_ensure_default_storage_settings($db);

$metricCutoff = hub_prune_cutoff((int)hub_get_storage_setting($db, 'AIHUB_METRIC_RETENTION_DAYS'));
$logCutoff = hub_prune_cutoff((int)hub_get_storage_setting($db, 'AIHUB_LOG_RETENTION_DAYS'));
$taskCutoff = hub_prune_cutoff((int)hub_get_storage_setting($db, 'AIHUB_TASK_RETENTION_DAYS'));
$apiAccessCutoff = hub_prune_cutoff((int)hub_get_storage_setting($db, 'AIHUB_API_ACCESS_LOG_RETENTION_DAYS'));
$terminalStatuses = "'success','failed','cancelled','timeout'";

$plans = [
    [
        'key' => 'host_metric_snapshots',
        'sql' => 'DELETE FROM host_metric_snapshots WHERE created_at < :cutoff',
        'count_sql' => 'SELECT COUNT(*) FROM host_metric_snapshots WHERE created_at < :cutoff',
        'params' => [':cutoff' => $metricCutoff],
    ],
    [
        'key' => 'benchmark_runs',
        'sql' => 'DELETE FROM benchmark_runs WHERE created_at < :cutoff',
        'count_sql' => 'SELECT COUNT(*) FROM benchmark_runs WHERE created_at < :cutoff',
        'params' => [':cutoff' => $metricCutoff],
    ],
    [
        'key' => 'command_jobs',
        'sql' => "DELETE FROM command_jobs WHERE status IN ($terminalStatuses) AND COALESCE(finished_at, updated_at, created_at) < :cutoff",
        'count_sql' => "SELECT COUNT(*) FROM command_jobs WHERE status IN ($terminalStatuses) AND COALESCE(finished_at, updated_at, created_at) < :cutoff",
        'params' => [':cutoff' => $logCutoff],
    ],
    [
        'key' => 'task_logs',
        'sql' => 'DELETE FROM task_logs WHERE created_at < :cutoff',
        'count_sql' => 'SELECT COUNT(*) FROM task_logs WHERE created_at < :cutoff',
        'params' => [':cutoff' => $taskCutoff],
    ],
    [
        'key' => 'api_access_logs',
        'sql' => 'DELETE FROM api_access_logs WHERE created_at < :cutoff',
        'count_sql' => 'SELECT COUNT(*) FROM api_access_logs WHERE created_at < :cutoff',
        'params' => [':cutoff' => $apiAccessCutoff],
    ],
];

$report = [
    'ok' => true,
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'cutoffs' => [
        'metric' => $metricCutoff,
        'log' => $logCutoff,
        'task' => $taskCutoff,
        'api_access' => $apiAccessCutoff,
    ],
    'deleted' => [],
    'wal_checkpoint' => 'skipped',
];

if ($dryRun) {
    foreach ($plans as $plan) {
        $report['deleted'][$plan['key']] = hub_prune_count($db, $plan['count_sql'], $plan['params']);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
}

$db->beginTransaction();
try {
    foreach ($plans as $plan) {
        $report['deleted'][$plan['key']] = hub_prune_delete($db, $plan['sql'], $plan['params']);
    }
    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$report['wal_checkpoint'] = 'truncate';

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

function hub_prune_cutoff(int $retentionDays): string
{
    $days = max(1, $retentionDays);
    return date('Y-m-d H:i:s', time() - ($days * 86400));
}

function hub_prune_count(PDO $db, string $sql, array $params): int
{
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function hub_prune_delete(PDO $db, string $sql, array $params): int
{
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return $stmt->rowCount();
}
