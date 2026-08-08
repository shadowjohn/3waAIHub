#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

hub_cli_only();
$limit = 10;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/\A--limit=([0-9]+)\z/', $argument, $matches) !== 1) {
        fwrite(STDERR, "usage: facebook_profile_cleanup.php [--limit=1..10]\n");
        exit(2);
    }
    $limit = max(1, min(10, (int)$matches[1]));
}

$db = hub_db();
hub_migrate($db);
$cleaned = hub_facebook_login_cleanup_expired($db, $limit);
fwrite(STDOUT, 'cleaned=' . $cleaned . PHP_EOL);
