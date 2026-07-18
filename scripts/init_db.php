<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$modelsRoot = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--models-root=')) {
        $modelsRoot = trim(substr($argument, strlen('--models-root=')));
        break;
    }
}

hub_ensure_runtime_dirs();
$db = hub_db();
hub_migrate($db);
hub_seed_admin_user($db);
hub_ensure_default_storage_settings($db);
if ($modelsRoot !== null) {
    if (!hub_is_safe_models_root($modelsRoot)) {
        throw new InvalidArgumentException('Invalid --models-root path.');
    }
    hub_set_storage_setting($db, 'AIHUB_MODELS_DIR', $modelsRoot);
}
hub_i18n_import_seed($db);
hub_seed_hello_service($db);

echo "SQLite initialized: " . HUB_DB_PATH . PHP_EOL;
