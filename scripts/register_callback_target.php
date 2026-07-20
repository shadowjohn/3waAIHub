<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

if (in_array('--help', $argv, true)) {
    echo 'Set AIHUB_CALLBACK_OWNER_MEMBER_ID, AIHUB_CALLBACK_TARGET_ALIAS, AIHUB_CALLBACK_URL, and AIHUB_CALLBACK_SIGNING_SECRET in trusted operator configuration.' . PHP_EOL;
    exit(0);
}

$ownerMemberId = (int)getenv('AIHUB_CALLBACK_OWNER_MEMBER_ID');
$alias = (string)getenv('AIHUB_CALLBACK_TARGET_ALIAS');
$callbackUrl = (string)getenv('AIHUB_CALLBACK_URL');
$signingSecret = (string)getenv('AIHUB_CALLBACK_SIGNING_SECRET');

try {
    $db = hub_db();
    $missing = hub_runtime_schema_missing($db);
    if ($missing !== []) {
        throw new RuntimeException('schema_upgrade_required');
    }
    $targetId = hub_register_callback_target_from_trusted_config($db, $ownerMemberId, $alias, $callbackUrl, $signingSecret);
    echo 'callback target registered id=' . $targetId . ' alias=' . $alias . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, 'callback_target_registration_failed' . PHP_EOL);
    exit(1);
}
