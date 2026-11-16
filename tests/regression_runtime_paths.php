<?php
declare(strict_types=1);
// Standalone filesystem regression. No bootstrap, production DB, Docker, or removal calls.
$fixture = sys_get_temp_dir() . '/3waaihub_path_regression_' . bin2hex(random_bytes(8));
mkdir($fixture . '/data/services/sample', 0700, true);
define('HUB_ROOT', realpath($fixture));
define('HUB_DATA_DIR', HUB_ROOT . '/data');
define('HUB_SERVICE_DIR', HUB_DATA_DIR . '/services');
require __DIR__ . '/../app/runtime_portability.php';
require __DIR__ . '/../app/storage.php';
require __DIR__ . '/../app/pack_registry.php';
require __DIR__ . '/../app/docker_runner.php';
// Match bootstrap's relative-path composition while keeping all IO in this fixture.
function hub_path(string $path): string { return HUB_ROOT . '/' . $path; }
function hub_runtime_settings_path(string $dir): string { return $dir . '/runtime-settings.conf'; }
function hub_legacy_runtime_env_path(string $dir): string { return $dir . '/.env'; }
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "PASS $label\n"; }
$db = new PDO('sqlite:' . HUB_DATA_DIR . '/3waaihub.sqlite');
$service = ['service_key' => 'sample', 'compose_file' => 'data/services/sample/docker-compose.generated.yml'];
$compose = hub_path($service['compose_file']);
$settings = HUB_SERVICE_DIR . '/sample/runtime-settings.conf';
file_put_contents($compose, "services: {}\n");
file_put_contents($settings, "TEST=1\n");
try {
    check(hub_service_generated_runtime_files($db, $service) !== null, 'native mixed separators accept managed runtime');
    check(count(hub_service_generated_runtime_cleanup_files($db, 'sample') ?? []) === 2, 'cleanup candidate list accepts native mixed separators');
    $other = $service;
    $other['compose_file'] = 'data/services/other/docker-compose.generated.yml';
    check(hub_service_generated_runtime_files($db, $other) === null, 'different service path rejected');
    $other['compose_file'] = 'data/services/sample/../../outside.yml';
    check(hub_service_generated_runtime_files($db, $other) === null, 'traversal compose rejected');
    check(hub_service_generated_runtime_cleanup_files($db, '../escape') === null, 'traversal key rejected');
    check(hub_storage_paths_equal('D:/DATA/Hub/service', 'd:\\data\\hub\\SERVICE', 'windows'), 'Windows separators and case are equivalent');
    check(!hub_storage_paths_equal('/data/Hub/service', '/data/hub/service', 'linux'), 'Linux case stays significant');
    check(!hub_storage_path_is_within('D:/DATA/Hub/services-evil/x', 'D:/DATA/Hub/services', 'windows'), 'sibling prefix rejected');
    check(!hub_storage_path_is_within('D:/DATA/Hub/services/../outside', 'D:/DATA/Hub/services', 'windows'), 'parent escape rejected');
} finally {
    $db = null;
    // Only explicitly created test files; never recursive deletion.
    unlink($compose); unlink($settings); unlink(HUB_DATA_DIR . '/3waaihub.sqlite');
    rmdir(HUB_SERVICE_DIR . '/sample'); rmdir(HUB_SERVICE_DIR); rmdir(HUB_DATA_DIR); rmdir($fixture);
}
