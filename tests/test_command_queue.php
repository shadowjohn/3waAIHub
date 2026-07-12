<?php
declare(strict_types=1);

hub_test('command worker allowlist includes Docker builder prune only as explicit maintenance action', function (): void {
    hub_test_assert(hub_is_valid_job_action('docker_builder_prune'), 'docker_builder_prune must be allowlisted');
    hub_test_assert(!hub_is_valid_job_action('docker system prune -af'), 'raw Docker commands must stay rejected');
});

hub_test('cron loop runs both command and task workers', function (): void {
    $loop = (string)file_get_contents(HUB_ROOT . '/crontab/1min.sh');
    hub_test_assert(str_contains($loop, 'scripts/command_worker.php'), 'cron loop must run command worker');
    hub_test_assert(str_contains($loop, 'scripts/task_worker.php'), 'cron loop must run task worker');
    hub_test_assert(str_contains($loop, 'scripts/collect_host_metrics.php'), 'cron loop must refresh host metrics snapshots');
    hub_test_assert(str_contains($loop, 'scripts/fix_permissions.sh'), 'cron loop must auto-repair runtime permissions when needed');
    hub_test_assert(str_contains($loop, 'data/3waaihub.sqlite-wal'), 'cron permission guard must include SQLite WAL file');
    hub_test_assert(str_contains($loop, "stat -c '%G'"), 'cron permission guard must detect wrong runtime group');
    hub_test_assert(str_contains($loop, 'TASK_WORKER_LIMIT'), 'cron loop must expose task worker limit');
});
