<?php
declare(strict_types=1);

hub_test('station hardware profile maps GPU and pack preflight blocks weak compute capability', function (): void {
    $db = hub_test_reset_db();

    hub_save_host_metric_snapshot($db, [
        'gpu' => [
            'available' => true,
            'name' => 'NVIDIA GeForce RTX 5060 Ti',
            'driver_version' => '595.71.05',
            'cuda_version' => '13.2',
            'memory_total_mb' => 16384,
        ],
        'docker' => [
            'available' => true,
            'daemon_reachable' => true,
            'compose_available' => true,
            'nvidia_container_toolkit' => true,
            'nvidia_runtime_available' => true,
        ],
    ]);

    $profile = hub_station_hardware_profile($db, 'linux');
    hub_test_assert($profile['gpu']['compute_capability'] === '12.0', 'RTX 5060 Ti compute capability mismatch');

    $translate = hub_get_pack('translate-gemma12b')['manifest'];
    $preflight = hub_pack_preflight($db, $translate, 'linux');
    hub_test_assert($preflight['summary']['status'] === 'pass', '5060 Ti should pass translate preflight');

    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, [
        'gpu' => [
            'available' => true,
            'name' => 'NVIDIA GeForce GTX 1080 Ti',
            'driver_version' => '535.0',
            'cuda_version' => '12.2',
            'memory_total_mb' => 11264,
        ],
        'docker' => [
            'available' => true,
            'daemon_reachable' => true,
            'compose_available' => true,
            'nvidia_container_toolkit' => true,
            'nvidia_runtime_available' => true,
        ],
    ]);

    $preflight = hub_pack_preflight($db, $translate, 'linux');
    hub_test_assert($preflight['checks']['compute_capability']['status'] === 'fail', 'GTX 1080 Ti should fail compute capability preflight');
});

hub_test('host metric failures provide repair suggestions', function (): void {
    $suggestions = hub_host_metric_fix_suggestions([
        'gpu' => [
            'available' => true,
            'name' => 'NVIDIA GeForce RTX 5090',
        ],
        'docker' => [
            'daemon_reachable' => false,
            'reason' => 'permission denied while trying to connect to the docker API at unix:///var/run/docker.sock',
            'compose_available' => false,
            'nvidia_container_toolkit' => false,
            'nvidia_runtime_available' => false,
        ],
    ], 'www-data', 'linux');

    $text = json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    hub_test_assert(str_contains($text, 'install_command_worker_cron.sh'), 'Docker permission suggestion missing worker cron command');
    hub_test_assert(str_contains($text, '--with-docker'), 'Compose suggestion missing Docker bootstrap command');
    hub_test_assert(str_contains($text, '--with-nvidia'), 'NVIDIA runtime suggestion missing NVIDIA bootstrap command');
});

hub_test('Windows pack preflight stays blocked even when Docker and GPU host facts are favorable', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, [
        'gpu' => [
            'available' => true,
            'name' => 'NVIDIA GeForce RTX 5090',
            'driver_version' => '595.71.05',
            'cuda_version' => '13.2',
            'memory_total_mb' => 32768,
        ],
        'docker' => [
            'available' => true,
            'daemon_reachable' => true,
            'compose_available' => true,
            'nvidia_container_toolkit' => true,
            'nvidia_runtime_available' => true,
        ],
    ]);

    $profile = hub_station_hardware_profile($db, 'windows');
    hub_test_assert($profile['docker_gpu']['daemon_reachable'] === true, 'Docker daemon host fact must remain visible');
    hub_test_assert($profile['linux_docker_target']['supported'] === false, 'Windows direct linux-docker target must be blocked');
    hub_test_assert($profile['docker_gpu']['available'] === false, 'Docker GPU capability must include the platform target gate');

    $translate = hub_get_pack('translate-gemma12b')['manifest'];
    $preflight = hub_pack_preflight($db, $translate, 'windows');
    hub_test_assert($preflight['summary']['status'] === 'fail', 'favorable host facts must not enable a Windows Linux Pack');
    hub_test_assert(($preflight['checks']['platform_target']['status'] ?? '') === 'fail', 'platform target preflight gate missing');
    hub_test_assert(($preflight['checks']['docker']['status'] ?? '') === 'pass', 'Docker daemon must remain a separate host fact');
});
