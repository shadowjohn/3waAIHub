<?php
declare(strict_types=1);

function hub_test_service_compose_project_file(string $relativePath = 'data/test_services/structure-main/docker-compose.generated.yml'): string
{
    $composePath = hub_path($relativePath);
    $dir = dirname($composePath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create test compose directory.');
    }
    file_put_contents($composePath, "services:\n  adapter:\n    image: test\n");
    file_put_contents(hub_runtime_settings_path($dir), "LOCAL_PORT=18100\n");

    return $relativePath;
}

hub_test('Service compose command prefers the detected active legacy project', function (): void {
    $service = [
        'compose_project' => '3waaihub_structure_main',
        'active_compose_project' => 'structure-main',
        'compose_file' => 'data/test_services/structure-main/docker-compose.generated.yml',
        'hot_reload' => 0,
        'environment' => 'production',
    ];

    hub_test_service_compose_project_file($service['compose_file']);
    $settingsPath = hub_runtime_settings_path(dirname(hub_path($service['compose_file'])));
    $command = hub_compose_command($service, ['down', '--timeout', '5']);
    hub_test_assert($command === [
        'docker',
        'compose',
        '--env-file',
        $settingsPath,
        '-p',
        'structure-main',
        '-f',
        hub_path($service['compose_file']),
        'down',
        '--timeout',
        '5',
    ], 'service stop must target the detected active legacy Compose project');
});

hub_test('Service active compose project detects the single legacy project for the generated compose file', function (): void {
    $composeFile = hub_test_service_compose_project_file();
    $commands = [];
    $runner = static function (array $command, int $timeoutSeconds, array $env) use (&$commands, $composeFile): array {
        $commands[] = $command;
        $expectedFilter = 'label=com.docker.compose.project.config_files=' . hub_path($composeFile);
        if ($command === ['docker', 'ps', '-q', '--filter', $expectedFilter]) {
            return ['exit_code' => 0, 'stdout' => "container-a\ncontainer-b\n", 'stderr' => '', 'output' => "container-a\ncontainer-b"];
        }
        if ($command === ['docker', 'inspect', '-f', '{{ index .Config.Labels "com.docker.compose.project" }}', 'container-a', 'container-b']) {
            return ['exit_code' => 0, 'stdout' => "structure-main\nstructure-main\n", 'stderr' => '', 'output' => "structure-main\nstructure-main"];
        }

        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'unexpected command', 'output' => 'unexpected command'];
    };

    $project = hub_active_service_compose_project([
        'compose_project' => '3waaihub_structure_main',
        'compose_file' => $composeFile,
    ], $runner);

    hub_test_assert($project === 'structure-main', 'single active legacy Compose project must be detected');
    hub_test_assert(count($commands) === 2, 'Compose project detection must inspect containers from the generated compose file');
});

hub_test('Service active compose project does not guess when multiple non-configured projects share a compose file', function (): void {
    $composeFile = hub_test_service_compose_project_file('data/test_services/shared/docker-compose.generated.yml');
    $runner = static function (array $command, int $timeoutSeconds, array $env) use ($composeFile): array {
        $expectedFilter = 'label=com.docker.compose.project.config_files=' . hub_path($composeFile);
        if ($command === ['docker', 'ps', '-q', '--filter', $expectedFilter]) {
            return ['exit_code' => 0, 'stdout' => "container-a\ncontainer-b\n", 'stderr' => '', 'output' => "container-a\ncontainer-b"];
        }
        if ($command === ['docker', 'inspect', '-f', '{{ index .Config.Labels "com.docker.compose.project" }}', 'container-a', 'container-b']) {
            return ['exit_code' => 0, 'stdout' => "legacy-one\nlegacy-two\n", 'stderr' => '', 'output' => "legacy-one\nlegacy-two"];
        }

        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'unexpected command', 'output' => 'unexpected command'];
    };

    $project = hub_active_service_compose_project([
        'compose_project' => '3waaihub_shared',
        'compose_file' => $composeFile,
    ], $runner);

    hub_test_assert($project === null, 'ambiguous active Compose projects must fall back to the configured project');
});
