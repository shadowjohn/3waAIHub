<?php
declare(strict_types=1);

hub_test('Facebook crawler Pack declares one fixed CPU job', function (): void {
    $pack = hub_get_pack('facebook-crawler');
    hub_test_assert(is_array($pack) && $pack['status'] === 'ok', 'facebook-crawler Pack must validate');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_ready'] ?? false) === true, 'crawler runtime must be ready');
    hub_test_assert(($manifest['platform_targets']['linux-docker']['supported'] ?? false) === true
        && ($manifest['platform_targets']['windows-wsl2-linux-docker']['supported'] ?? false) === true, 'crawler must use the shared Linux/WSL container runtime');
    $contract = hub_pack_async_job_contract($manifest, 'crawl');
    hub_test_assert(($contract['runner']['accelerator'] ?? '') === 'cpu', 'crawler must not request GPU');
    hub_test_assert(($contract['runner']['network_profile'] ?? '') === 'public_egress', 'crawler requires bounded public egress');
    hub_test_assert(($contract['runner']['entrypoint'] ?? []) === ['/app/crawl-entrypoint.sh', 'python3', '/app/crawl_runner.py'], 'crawler must invoke the runner through Python');
    hub_test_assert(array_keys($contract['request_schema'] ?? []) === ['profile_id', 'targets_json', 'limit_per_target'], 'runner inputs must remain fixed');
    $dataset = $contract['artifact_contract']['artifacts'][0] ?? [];
    hub_test_assert(($dataset['type'] ?? '') === 'facebook_posts_jsonl'
        && ($dataset['max_bytes'] ?? 0) === 4194304
        && ($dataset['text']['max_bytes'] ?? 0) === 4194304, 'crawler JSONL must use the bounded text validator');

    $shellCheck = HUB_ROOT . '/packs/facebook-crawler/service/test_egress_firewall.sh';
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/service/Dockerfile');
    hub_test_assert(is_file($shellCheck) && is_executable($shellCheck)
        && str_contains($dockerfile, "FROM base AS test\nCOPY tests ./tests\nCOPY test_egress_firewall.sh ./\nRUN chmod 0755 test_egress_firewall.sh\n\nFROM base AS runtime"), 'crawler test target must include the executable egress boundary check only in its test stage');
});

hub_test('Facebook crawl resolves only the managed Pack route', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    $route = hub_resolve_pack_job_async_route($db, 'facebook_crawl');
    hub_test_assert(($route['pack_id'] ?? '') === 'facebook-crawler'
        && ($route['job'] ?? '') === 'crawl'
        && ($route['accelerator'] ?? '') === 'cpu', 'crawler route cannot be selected by clients');
});

hub_test('Facebook crawler profiles are member-owned and node-private', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler A');
    $memberB = hub_create_api_member($db, 'Crawler B');
    $profile = hub_facebook_profile_create($db, $memberA, 'WRA account');
    $path = hub_facebook_profile_state_path($profile);
    clearstatcache(true, $path);

    hub_test_assert(preg_match('/^fbp_[a-f0-9]{48}$/', (string)$profile['profile_id']) === 1, 'profile ID must be opaque');
    hub_test_assert(($profile['node_name'] ?? '') === (gethostname() ?: 'localhost'), 'profile must be pinned to the local node');
    hub_test_assert(hub_facebook_profile_for_member($db, $profile['profile_id'], $memberA) !== null, 'owner must resolve profile');
    hub_test_assert(hub_facebook_profile_for_member($db, $profile['profile_id'], $memberB) === null, 'other member must not resolve profile');
    hub_test_assert(count(hub_facebook_profiles_for_member($db, $memberA)) === 1, 'owner list must include profile');
    hub_test_assert(hub_facebook_profiles_for_member($db, $memberB) === [], 'other member list must exclude profile');
    hub_test_assert(is_file($path), 'storage-state file must exist');
    hub_test_assert((fileperms(dirname($path)) & 0777) === 0700, 'profile directory must be private');
    hub_test_assert((fileperms($path) & 0777) === 0600, 'storage-state file must be private');

    foreach ([$profile, hub_facebook_profile_for_member($db, $profile['profile_id'], $memberA)] as $publicProfile) {
        $json = json_encode($publicProfile, JSON_THROW_ON_ERROR);
        hub_test_assert(!str_contains($json, HUB_DATA_DIR) && !str_contains($json, 'storage_state.json'), 'profile arrays must not expose host paths');
    }

    $columns = array_column($db->query('PRAGMA table_info(facebook_crawler_profiles)')->fetchAll(), 'name');
    $indexes = array_column($db->query("PRAGMA index_list('facebook_crawler_profiles')")->fetchAll(), 'name');
    hub_test_assert(!in_array('storage_path', $columns, true), 'profile schema must derive browser-state paths');
    hub_test_assert(in_array('idx_facebook_profiles_owner', $indexes, true), 'owner profile index missing');
    hub_test_assert(in_array('idx_facebook_profiles_login_expiry', $indexes, true), 'login expiry index missing');
    hub_test_assert(!in_array('facebook_crawler_profiles', hub_runtime_schema_missing($db), true), 'runtime profile schema missing');

    hub_facebook_profile_delete($db, $profile['profile_id'], $memberA);
});

hub_test('Facebook crawler profile repository caps owners and fails closed on active login metadata', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler cap A');
    $memberB = hub_create_api_member($db, 'Crawler cap B');
    $profiles = [];
    for ($i = 1; $i <= 20; $i++) {
        $profiles[] = hub_facebook_profile_create($db, $memberA, 'Profile ' . $i);
    }

    hub_test_assert(hub_test_throws(static fn (): array => hub_facebook_profile_create($db, $memberA, 'Profile 21')), 'owner must be capped at 20 active profiles');
    $foreign = hub_facebook_profile_create($db, $memberB, 'Other owner profile');
    hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $profiles[0]['profile_id'], $memberB)), 'other member must not delete profile');

    $db->prepare('UPDATE facebook_crawler_profiles SET login_container_name = :name WHERE profile_id = :profile_id')
        ->execute([':name' => 'fb-login-active', ':profile_id' => $profiles[0]['profile_id']]);
    hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $profiles[0]['profile_id'], $memberA)), 'delete must fail closed while login metadata is active');
    hub_test_assert(is_file(hub_facebook_profile_state_path($profiles[0])), 'active login rejection must preserve browser state');
    $db->prepare('UPDATE facebook_crawler_profiles SET login_container_name = NULL WHERE profile_id = :profile_id')
        ->execute([':profile_id' => $profiles[0]['profile_id']]);

    foreach ($profiles as $profile) {
        hub_facebook_profile_delete($db, $profile['profile_id'], $memberA);
    }
    hub_facebook_profile_delete($db, $foreign['profile_id'], $memberB);
    hub_test_assert(hub_facebook_profiles_for_member($db, $memberA) === [], 'deleted profiles must leave the active owner list');
});

hub_test('Facebook crawler profile deletion rejects symlinks and hardlinks', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler path owner');
    $outside = tempnam(sys_get_temp_dir(), 'facebook_state_');
    if ($outside === false) {
        throw new RuntimeException('Cannot create crawler state fixture.');
    }
    file_put_contents($outside, 'private outside state');

    $symlinkProfile = hub_facebook_profile_create($db, $memberId, 'Symlink profile');
    $symlinkPath = hub_facebook_profile_state_path($symlinkProfile);
    unlink($symlinkPath);
    if (!symlink($outside, $symlinkPath)) {
        throw new RuntimeException('Cannot create crawler state symlink fixture.');
    }

    $hardlinkProfile = hub_facebook_profile_create($db, $memberId, 'Hardlink profile');
    $hardlinkPath = hub_facebook_profile_state_path($hardlinkProfile);
    $hardlinkAlias = sys_get_temp_dir() . '/facebook_state_alias_' . bin2hex(random_bytes(8));
    if (!link($hardlinkPath, $hardlinkAlias)) {
        throw new RuntimeException('Cannot create crawler state hardlink fixture.');
    }

    $directoryProfile = hub_facebook_profile_create($db, $memberId, 'Directory symlink profile');
    $directoryPath = dirname(hub_facebook_profile_state_path($directoryProfile));
    $movedDirectory = sys_get_temp_dir() . '/facebook_profile_dir_' . bin2hex(random_bytes(8));
    if (!rename($directoryPath, $movedDirectory) || !symlink($movedDirectory, $directoryPath)) {
        throw new RuntimeException('Cannot create crawler profile directory symlink fixture.');
    }

    try {
        hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $symlinkProfile['profile_id'], $memberId)), 'state symlink must be rejected');
        hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $hardlinkProfile['profile_id'], $memberId)), 'state hardlink must be rejected');
        hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $directoryProfile['profile_id'], $memberId)), 'profile directory symlink must be rejected');
        hub_test_assert(file_get_contents($outside) === 'private outside state', 'symlink target must remain untouched');
        hub_test_assert(hub_facebook_profile_for_member($db, $symlinkProfile['profile_id'], $memberId) !== null, 'rejected symlink profile must remain active');
        hub_test_assert(hub_facebook_profile_for_member($db, $hardlinkProfile['profile_id'], $memberId) !== null, 'rejected hardlink profile must remain active');
    } finally {
        @unlink($symlinkPath);
        file_put_contents($symlinkPath, "{}\n");
        @chmod($symlinkPath, 0600);
        @unlink($hardlinkAlias);
        @unlink($directoryPath);
        @rename($movedDirectory, $directoryPath);
        @unlink($outside);
    }

    hub_facebook_profile_delete($db, $symlinkProfile['profile_id'], $memberId);
    hub_facebook_profile_delete($db, $hardlinkProfile['profile_id'], $memberId);
    hub_facebook_profile_delete($db, $directoryProfile['profile_id'], $memberId);
});
