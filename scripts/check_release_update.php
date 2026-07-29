<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

hub_cli_only();
$command = ['git', '-C', HUB_ROOT, 'ls-remote', '--tags', '--refs', 'origin'];
$report = hub_release_check_remote(static function (array $requested) use ($command): array {
    if ($requested !== $command) {
        return ['exit_code' => 126, 'stdout' => '', 'stderr' => 'command_not_allowed', 'output' => ''];
    }

    return hub_run_command($requested, 10);
});

echo hub_json_encode($report, JSON_UNESCAPED_SLASHES) . PHP_EOL;
