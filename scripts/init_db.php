<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

hub_ensure_runtime_dirs();
$db = hub_db();
hub_migrate($db);
hub_seed_admin_user($db);
hub_ensure_default_storage_settings($db);
hub_i18n_import_seed($db);
hub_seed_hello_service($db);

echo "SQLite initialized: " . HUB_DB_PATH . PHP_EOL;
