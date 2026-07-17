<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$db = hub_db();
hub_migrate($db);

$path = $argv[1] ?? hub_i18n_seed_path();
$dir = dirname($path);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create i18n seed directory: ' . $dir);
}

$json = json_encode(hub_i18n_export_seed($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new RuntimeException('Cannot encode i18n seed JSON.');
}

file_put_contents($path, $json . PHP_EOL);
echo 'Exported i18n seed: ' . $path . PHP_EOL;
