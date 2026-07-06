<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$limit = 5;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int)substr($arg, 8));
    }
}

$db = hub_db();
hub_migrate($db);

$processed = 0;
while ($processed < $limit) {
    $task = hub_claim_next_task($db);
    if (!$task) {
        break;
    }

    try {
        hub_run_task($db, $task);
    } catch (Throwable $e) {
        hub_add_task_log($db, (int)$task['id'], 'error', $e->getMessage());
        hub_finish_task_failed($db, $task, $e->getMessage());
    }

    $latest = hub_get_task($db, (int)$task['id']);
    echo 'task ' . $task['id'] . ' ' . $task['task_type'] . ' status=' . ($latest['status'] ?? 'missing') . PHP_EOL;
    $processed++;
}

function hub_run_task(PDO $db, array $task): void
{
    if ($task['task_type'] !== 'demo_task') {
        throw new RuntimeException('Unknown task type.');
    }

    hub_add_task_log($db, (int)$task['id'], 'info', 'demo_task started');
    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'demo_task',
        'message' => 'demo task completed',
        'input' => $task['input'],
    ]);
    hub_add_task_log($db, (int)$task['id'], 'info', 'demo_task finished');
}
