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
        && in_array('application/json', $dataset['mime_types'] ?? [], true)
        && in_array('application/x-empty', $dataset['mime_types'] ?? [], true)
        && ($dataset['text']['max_bytes'] ?? 0) === 4194304
        && ($dataset['text']['allow_empty'] ?? false) === true, 'crawler JSONL must use the bounded empty-dataset text validator');

    $shellCheck = HUB_ROOT . '/packs/facebook-crawler/service/test_egress_firewall.sh';
    $dockerfile = str_replace("\r\n", "\n", (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/service/Dockerfile'));
    hub_test_assert(is_file($shellCheck) && (PHP_OS_FAMILY === 'Windows' || is_executable($shellCheck))
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
    if (PHP_OS_FAMILY === 'Windows') {
        hub_test_assert(!is_link(dirname($path)) && !is_link($path), 'Windows profile storage must reject link paths');
    } else {
        hub_test_assert((fileperms(dirname($path)) & 0777) === 0700, 'profile directory must be private');
        hub_test_assert((fileperms($path) & 0777) === 0600, 'storage-state file must be private');
    }

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
    hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/.gitignore'), 'data/facebook-crawler/'), 'private browser state must never enter Git');

    hub_facebook_profile_delete($db, $profile['profile_id'], $memberA);
});

hub_test('Facebook login verification opens only a canonical private state path', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler state path owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'State path profile');
    $path = hub_facebook_login_state_path($profile);

    hub_test_assert(basename($path) === 'storage_state.json', 'login state file name must remain fixed');
    hub_test_assert(hub_storage_paths_equal(dirname($path), hub_facebook_profile_directory((string)$profile['profile_id'])), 'login state path must remain inside its verified profile directory');
    hub_test_assert(hub_test_throws(static fn (): string => hub_facebook_login_state_path(['profile_id' => '../escape'])), 'login state path accepted traversal profile ID');

    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook crawler destruction resolves only a canonical private state path', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler destruction path owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'Destruction path profile');
    $profileId = (string)$profile['profile_id'];
    $path = hub_facebook_profile_storage_state_path($profileId);

    hub_test_assert(basename($path) === 'storage_state.json', 'destruction state filename must remain fixed');
    hub_test_assert(hub_storage_paths_equal(dirname($path), hub_facebook_profile_directory($profileId)), 'destruction state path must remain inside its verified profile directory');
    hub_test_assert(hub_test_throws(static fn (): string => hub_facebook_profile_storage_state_path('../escape')), 'destruction state path accepted traversal profile ID');

    hub_facebook_profile_delete($db, $profileId, $memberId);
});

hub_test('Facebook crawler deletion remains non-active and retryable after final DB failure', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler delete retry owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'Retry delete profile');
    $path = hub_facebook_profile_state_path($profile);
    $profileId = (string)$profile['profile_id'];
    $db->exec("CREATE TRIGGER facebook_profile_delete_failure
        BEFORE UPDATE OF deleted_at ON facebook_crawler_profiles
        WHEN NEW.deleted_at IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'delete_db_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static fn (): bool => hub_facebook_profile_delete($db, $profileId, $memberId)), 'final metadata failure must be surfaced');
        $row = $db->query("SELECT state, deleted_at FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profileId))->fetch();
        hub_test_assert(is_array($row) && $row['state'] === 'deleting' && $row['deleted_at'] === null, 'failed finalization must leave a resumable deleting tombstone');
        hub_test_assert(hub_facebook_profile_for_member($db, $profileId, $memberId) === null, 'deleting profile must not remain API-active');
        hub_test_assert(!is_file($path) && !is_dir(dirname($path)), 'successful state destruction must not be rolled back into an active row');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS facebook_profile_delete_failure');
    }

    hub_test_assert(hub_facebook_profile_delete($db, $profileId, $memberId), 'deleting tombstone must finalize on retry');
    $deletedAt = $db->query("SELECT deleted_at FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profileId))->fetchColumn();
    hub_test_assert(is_string($deletedAt) && $deletedAt !== '', 'retry must persist deleted_at');
});

hub_test('Facebook crawler profiles block owner deletion until private state is removed', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler protected owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'Protected login');
    $path = hub_facebook_profile_state_path($profile);

    hub_test_assert(hub_test_throws(static function () use ($db, $memberId): void {
        hub_delete_api_member($db, $memberId);
    }), 'member deletion must not orphan an active Facebook profile');
    hub_test_assert(hub_get_api_member($db, $memberId) !== null && is_file($path), 'blocked member deletion must preserve metadata and private state');

    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
    hub_delete_api_member($db, $memberId);
    hub_test_assert(hub_get_api_member($db, $memberId) === null, 'member must be deletable after its private profiles are finalized');

    $apiSource = (string)file_get_contents(HUB_ROOT . '/app/api_tokens.php');
    hub_test_assert(substr_count($apiSource, 'NOT EXISTS') >= 2, 'member deletion must atomically fence users and Facebook profiles');
});

hub_test('Facebook crawler bootstrap rejects a symlinked private parent', function (): void {
    hub_test_require_symlink_fixture('Facebook crawler profile symlink fixtures are unavailable on this Windows host.');
    $parent = HUB_DATA_DIR . '/facebook-crawler';
    $backup = HUB_DATA_DIR . '/facebook-crawler-safe-' . bin2hex(random_bytes(8));
    $outside = sys_get_temp_dir() . '/3waaihub_bootstrap_parent_' . bin2hex(random_bytes(16));
    if (!is_dir($parent) || !rename($parent, $backup) || !mkdir($outside, 0700, true) || !symlink($outside, $parent)) {
        throw new RuntimeException('Cannot create bootstrap parent symlink fixture.');
    }
    $marker = $outside . '/marker.txt';
    file_put_contents($marker, 'bootstrap-outside');
    chmod($marker, 0640);
    $before = lstat($marker);

    try {
        hub_test_assert(hub_test_throws(static function (): void {
            hub_ensure_runtime_dirs();
        }), 'bootstrap must reject a symlinked Facebook profile parent');
        clearstatcache(true, $marker);
        hub_test_assert(lstat($marker) === $before && file_get_contents($marker) === 'bootstrap-outside', 'bootstrap must not mutate the symlink target');
    } finally {
        if (is_link($parent)) {
            hub_test_remove_symlink($parent);
        }
        @rename($backup, $parent);
        hub_test_remove_data_tree($outside);
    }
    hub_test_assert(!is_link($parent) && is_dir($parent), 'bootstrap parent symlink fixture must restore its managed directory');
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
    hub_test_require_symlink_fixture('Facebook crawler profile symlink fixtures are unavailable on this Windows host.');
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
        hub_test_assert(hub_facebook_profile_for_member($db, $symlinkProfile['profile_id'], $memberId) === null, 'rejected symlink profile must fail closed outside the active API');
        hub_test_assert(hub_facebook_profile_for_member($db, $hardlinkProfile['profile_id'], $memberId) === null, 'rejected hardlink profile must fail closed outside the active API');
        $deletingCount = (int)$db->query("SELECT COUNT(*) FROM facebook_crawler_profiles WHERE state = 'deleting' AND deleted_at IS NULL")->fetchColumn();
        hub_test_assert($deletingCount === 3, 'rejected storage cleanup must retain retryable deleting tombstones');
    } finally {
        @unlink($symlinkPath);
        file_put_contents($symlinkPath, "{}\n");
        @chmod($symlinkPath, 0600);
        @unlink($hardlinkAlias);
        if (is_link($directoryPath)) {
            hub_test_remove_symlink($directoryPath);
        }
        @rename($movedDirectory, $directoryPath);
        @unlink($outside);
    }
    hub_test_assert(!is_link($directoryPath) && is_dir($directoryPath), 'profile directory symlink fixture must restore its managed directory');

    hub_facebook_profile_delete($db, $symlinkProfile['profile_id'], $memberId);
    hub_facebook_profile_delete($db, $hardlinkProfile['profile_id'], $memberId);
    hub_facebook_profile_delete($db, $directoryProfile['profile_id'], $memberId);
});

function hub_test_facebook_login_token(PDO $db, int $memberId, string $name): array
{
    $installed = hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    $token = hub_create_api_token($db, $memberId, $name, null, null);
    hub_add_api_token_mode_permission(
        $db,
        (int)$token['token_id'],
        'facebook_crawl',
        (int)$installed['service']['id']
    );

    return $token;
}

function hub_test_facebook_login_payload(array $response): array
{
    $payload = json_decode((string)($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function hub_test_facebook_login_request(
    PDO $db,
    string $mode,
    string $token,
    string $method,
    array $payload,
    ?callable $runner = null,
    ?callable $transport = null
): array {
    return hub_gateway_dispatch($db, $mode, null, [
        'bearer_token' => $token,
        'client_ip' => '203.0.113.44',
        'method' => $method,
        'request_uri' => '/api.php?mode=' . $mode,
        'raw_body' => $method === 'POST' ? hub_json_encode($payload) : null,
        'query' => $method === 'GET' ? $payload : [],
        'https' => true,
        'command_runner' => $runner,
        'login_transport' => $transport,
        'platform' => 'linux',
    ]);
}

function hub_test_facebook_mark_ready(PDO $db, array $profile): void
{
    $row = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote((string)$profile['profile_id']))->fetch();
    $path = hub_facebook_profile_state_path($row);
    file_put_contents($path, '{"cookies":[{"name":"c_user","value":"123","domain":".facebook.com"}]}');
    chmod($path, 0600);
    $db->prepare("UPDATE facebook_crawler_profiles SET state = 'ready', last_verified_at = :now WHERE profile_id = :profile_id")
        ->execute([':now' => hub_now(), ':profile_id' => $profile['profile_id']]);
}

function hub_test_facebook_crawl_request(PDO $db, string $token, array $payload): array
{
    return hub_gateway_dispatch($db, 'facebook_crawl', null, [
        'bearer_token' => $token,
        'client_ip' => '203.0.113.44',
        'method' => 'POST',
        'request_uri' => '/api.php?mode=facebook_crawl',
        'raw_body' => hub_json_encode($payload),
        'content_type' => 'application/json',
    ]);
}

function hub_test_facebook_wsl_payload(array $command): string
{
    $script = (string)end($command);
    if (preg_match('/printf %s ([A-Za-z0-9+\\/=]+) \\| base64 -d \\| bash/', $script, $matches) !== 1) {
        throw new RuntimeException('Facebook WSL command payload is missing.');
    }
    $payload = base64_decode($matches[1], true);
    if ($payload === false) {
        throw new RuntimeException('Facebook WSL command payload is invalid.');
    }

    return $payload;
}

hub_test('Facebook crawl accepts a canonical page URL with a sharing query', function (): void {
    hub_test_assert(
        hub_facebook_crawl_target_url('https://www.facebook.com/NTPC119/?locale=zh_TW') === 'https://www.facebook.com/NTPC119',
        'sharing query parameters must be removed from canonical Facebook page URLs'
    );
});

hub_test('Facebook crawl admission stores only normalized managed input', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler admission owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler admission token');
    $profile = hub_facebook_profile_create($db, $memberId, 'Public sources');
    hub_test_facebook_mark_ready($db, $profile);

    $response = hub_test_facebook_crawl_request($db, $token['plain_token'], [
        'profile_id' => $profile['profile_id'],
        'targets' => [
            ['url' => 'https://www.facebook.com/wra.gov.tw/'],
            ['url' => 'https://m.facebook.com/groups/123456789/'],
        ],
        'limit_per_target' => 20,
    ]);
    $payload = hub_test_facebook_login_payload($response);
    $task = hub_get_task($db, (int)($payload['task_id'] ?? 0));
    hub_test_assert($response['status'] === 200 && is_array($task), 'valid Facebook crawl JSON must enqueue one task');
    hub_test_assert(array_keys($task['input']) === ['profile_id', 'targets_json', 'limit_per_target'], 'task input must contain only managed crawler fields');
    hub_test_assert(json_decode((string)$task['input']['targets_json'], true) === [
        ['url' => 'https://www.facebook.com/wra.gov.tw'],
        ['url' => 'https://www.facebook.com/groups/123456789'],
    ], 'Facebook target URLs must use one canonical host and path');
    hub_test_assert(!str_contains($task['input_json'], 'password') && !str_contains($task['input_json'], 'cookie'), 'task JSON must contain no login secret');

    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook crawl admission rejects unsafe targets controls and unavailable profiles', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler admission A');
    $memberB = hub_create_api_member($db, 'Crawler admission B');
    $tokenA = hub_test_facebook_login_token($db, $memberA, 'crawler admission A');
    $tokenB = hub_test_facebook_login_token($db, $memberB, 'crawler admission B');
    $profile = hub_facebook_profile_create($db, $memberA, 'Admission profile');
    hub_test_facebook_mark_ready($db, $profile);
    $validTarget = [['url' => 'https://www.facebook.com/wra.gov.tw']];
    $cases = [
        [['targets' => []], 400],
        [['targets' => array_fill(0, 31, ['url' => 'https://www.facebook.com/a'])], 400],
        [['targets' => $validTarget, 'limit_per_target' => 9], 400],
        [['targets' => $validTarget, 'limit_per_target' => 31], 400],
        [['targets' => [['url' => 'http://www.facebook.com/a']]], 400],
        [['targets' => [['url' => 'https://user:pass@www.facebook.com/a']]], 400],
        [['targets' => [['url' => 'https://www.facebook.com/a#posts']]], 400],
        [['targets' => [['url' => 'https://www.facebook.com:444/a']]], 400],
        [['targets' => [['url' => 'https://127.0.0.1/a']]], 400],
        [['targets' => [['url' => 'https://www.facebook.com/hashtag/flood']]], 400],
        [['targets' => [['url' => 'https://www.facebook.com/search/top?q=flood']]], 400],
        [['targets' => [['url' => 'https://www.facebook.com/a', 'cookie' => 'secret']]], 400],
        [['targets' => $validTarget, 'command' => 'browser'], 400],
        [['targets' => [
            ['url' => 'https://www.facebook.com/wra.gov.tw/'],
            ['url' => 'https://m.facebook.com/wra.gov.tw'],
        ]], 400],
    ];
    foreach ($cases as [$request, $status]) {
        $before = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $response = hub_test_facebook_crawl_request($db, $tokenA['plain_token'], $request);
        hub_test_assert($response['status'] === $status, 'unsafe Facebook crawl request must fail admission');
        hub_test_assert((int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $before, 'invalid admission must not enqueue a task');
    }

    $foreign = hub_test_facebook_crawl_request($db, $tokenB['plain_token'], [
        'profile_id' => $profile['profile_id'],
        'targets' => $validTarget,
    ]);
    hub_test_assert($foreign['status'] === 404, 'another member profile must remain hidden');
    $db->prepare("UPDATE facebook_crawler_profiles SET state = 'reauth_required' WHERE profile_id = :profile_id")
        ->execute([':profile_id' => $profile['profile_id']]);
    $unavailable = hub_test_facebook_crawl_request($db, $tokenA['plain_token'], [
        'profile_id' => $profile['profile_id'],
        'targets' => $validTarget,
    ]);
    hub_test_assert($unavailable['status'] === 409, 'unready owned profile must fail admission');

    $anonymous = hub_test_facebook_crawl_request($db, $tokenA['plain_token'], ['targets' => $validTarget]);
    hub_test_assert($anonymous['status'] === 200, 'public targets must remain usable without a profile');
    $db->prepare("UPDATE facebook_crawler_profiles SET state = 'ready' WHERE profile_id = :profile_id")
        ->execute([':profile_id' => $profile['profile_id']]);
    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberA);
});

function hub_test_facebook_enqueue_crawl(PDO $db, int $memberId, int $tokenId, ?string $profileId): int
{
    $route = hub_resolve_pack_job_async_route($db, 'facebook_crawl');
    $input = [];
    if ($profileId !== null) {
        $input['profile_id'] = $profileId;
    }
    $input['targets_json'] = '[{"url":"https://www.facebook.com/wra.gov.tw"}]';
    $input['limit_per_target'] = 10;

    return hub_enqueue_owned_pack_job($db, $route, $input, $memberId, $tokenId, '203.0.113.44');
}

function hub_test_facebook_dataset_task(PDO $db, int $memberId, int $tokenId, array $items): array
{
    $taskId = hub_test_facebook_enqueue_crawl($db, $memberId, $tokenId, null);
    $directory = hub_task_result_dir($taskId);
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        throw new RuntimeException('Cannot create Facebook dataset fixture directory.');
    }
    $path = $directory . '/facebook_posts.jsonl';
    $content = '';
    foreach ($items as $item) {
        $content .= hub_json_encode($item) . "\n";
    }
    file_put_contents($path, $content);
    chmod($path, 0600);
    $artifactId = hub_register_task_artifact($db, $taskId, 'facebook_posts.jsonl', $path, 'application/x-ndjson');
    $db->prepare(
        "UPDATE task_artifacts
         SET artifact_type = 'facebook_posts_jsonl', sha256 = :sha256
         WHERE id = :id"
    )->execute([':sha256' => hash_file('sha256', $path), ':id' => $artifactId]);
    $now = hub_now();
    $db->prepare(
        "UPDATE tasks
         SET status = 'success', progress = 100, result_json = :result_json,
             started_at = :started_at, finished_at = :finished_at, updated_at = :updated_at
         WHERE id = :id"
    )->execute([
        ':result_json' => hub_json_encode(['outcome' => 'complete', 'post_count' => count($items)]),
        ':started_at' => $now,
        ':finished_at' => $now,
        ':updated_at' => $now,
        ':id' => $taskId,
    ]);

    return ['task_id' => $taskId, 'artifact_id' => $artifactId, 'path' => $path];
}

function hub_test_facebook_dataset_request(PDO $db, string $mode, string $token, array $query = [], string $method = 'GET'): array
{
    return hub_gateway_dispatch($db, $mode, null, [
        'bearer_token' => $token,
        'client_ip' => '203.0.113.44',
        'method' => $method,
        'request_uri' => '/api.php?mode=' . $mode,
        'query' => $query,
    ]);
}

hub_test('Facebook profile lock serializes waits cancellation and promotion', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler lock owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler lock token');
    $profile = hub_facebook_profile_create($db, $memberId, 'Serialized profile');
    hub_test_facebook_mark_ready($db, $profile);
    $holderId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$profile['profile_id']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = :lock_token WHERE id = :id")
        ->execute([':lock_token' => str_repeat('a', 32), ':id' => $holderId]);
    $holder = hub_get_task($db, $holderId);
    $held = hub_facebook_profile_acquire_for_task($db, $holder);
    hub_test_assert(is_array($held) && (int)$held['active_task_id'] === $holderId, 'first task must acquire its profile');

    $waiterId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$profile['profile_id']);
    $waiter = hub_claim_next_task($db, ['pack_job']);
    $called = 0;
    $outcome = hub_run_pack_job_task($db, $waiter, [
        'worker_id' => 'facebook-profile-waiter',
        'profile_backoff_seconds' => 5,
        'executor' => static function () use (&$called): array {
            $called++;
            return [];
        },
    ]);
    $waitingTask = hub_get_task($db, $waiterId);
    $waitingRun = $db->query('SELECT * FROM runtime_runs WHERE task_id = ' . $waiterId)->fetch();
    hub_test_assert(($outcome['status'] ?? '') === 'waiting_profile' && $called === 0, 'busy profile must wait before executor dispatch');
    hub_test_assert(($waitingTask['status'] ?? '') === 'waiting_profile' && ($waitingTask['waiting_reason'] ?? '') === 'profile_busy' && empty($waitingTask['lock_token']), 'waiting task must clear its worker fence');
    hub_test_assert(($waitingRun['state'] ?? '') === 'waiting_profile' && $waitingRun['worker_id'] === null && $waitingRun['lease_token'] === null && $waitingRun['container_id'] === null, 'waiting runtime must clear its lease without a container');
    hub_test_assert(!is_dir(hub_task_result_dir($waiterId) . '/workspace'), 'profile wait must happen before workspace staging');
    hub_test_assert(hub_task_waiting_status_fields($waitingTask, time())['waiting_reason'] === 'profile_busy', 'profile wait status must expose one stable reason');
    hub_test_assert(hub_cancel_task($db, $waiterId) && (hub_get_task($db, $waiterId)['status'] ?? '') === 'cancelled', 'waiting profile task must be cancellable without work cleanup');

    $promotedId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$profile['profile_id']);
    $promotedTask = hub_claim_next_task($db, ['pack_job']);
    $promotedOutcome = hub_run_pack_job_task($db, $promotedTask, ['worker_id' => 'facebook-profile-promote']);
    hub_test_assert(($promotedOutcome['status'] ?? '') === 'waiting_profile', 'second waiter fixture must enter waiting_profile');
    $db->prepare('UPDATE tasks SET next_attempt_at = :past WHERE id = :id')
        ->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $promotedId]);
    hub_test_assert(!hub_promote_due_waiting_profile_task($db), 'held profile must not promote a waiter');
    hub_test_assert(hub_facebook_profile_release_for_task($db, (string)$profile['profile_id'], $holderId), 'holder must release only its own profile fence');
    hub_test_assert(hub_promote_due_waiting_profile_task($db) && !hub_promote_due_waiting_profile_task($db), 'released profile must promote one due waiter exactly once');
    $queued = hub_get_task($db, $promotedId);
    $queuedRun = $db->query('SELECT * FROM runtime_runs WHERE task_id = ' . $promotedId)->fetch();
    hub_test_assert(($queued['status'] ?? '') === 'queued' && ($queuedRun['state'] ?? '') === 'queued', 'promotion must restore both task and runtime queues');

    $otherProfile = hub_facebook_profile_create($db, $memberId, 'Independent profile');
    hub_test_facebook_mark_ready($db, $otherProfile);
    $otherId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$otherProfile['profile_id']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = :lock_token WHERE id = :id")
        ->execute([':lock_token' => str_repeat('b', 32), ':id' => $otherId]);
    $other = hub_get_task($db, $otherId);
    hub_test_assert(is_array(hub_facebook_profile_acquire_for_task($db, $other)), 'different profile must acquire independently');
    $anonymousId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], null);
    hub_test_assert(hub_facebook_profile_acquire_for_task($db, hub_get_task($db, $anonymousId)) === null, 'anonymous public crawl must not request a profile lock');

    hub_cancel_task($db, $promotedId);
    hub_cancel_task($db, $anonymousId);
    hub_facebook_profile_release_for_task($db, (string)$otherProfile['profile_id'], $otherId);
    $db->prepare("UPDATE tasks SET status = 'cancelled', lock_token = NULL WHERE id = :id")->execute([':id' => $otherId]);
    $db->prepare("UPDATE tasks SET status = 'cancelled' WHERE id = :id")->execute([':id' => $holderId]);
    hub_facebook_profile_delete($db, (string)$otherProfile['profile_id'], $memberId);
    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook crawler mounts one verified profile file and releases every terminal path', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler mount owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler mount token');
    $profile = hub_facebook_profile_create($db, $memberId, 'Mounted profile');
    hub_test_facebook_mark_ready($db, $profile);
    $statePath = hub_facebook_profile_state_path($profile);

    $taskId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$profile['profile_id']);
    $task = hub_claim_next_task($db, ['pack_job']);
    $mountArguments = [];
    $outcome = hub_run_pack_job_task($db, $task, [
        'worker_id' => 'facebook-profile-mount',
        'executor' => static function (array $context) use (&$mountArguments): array {
            $execution = hub_pack_job_default_runner_command($context);
            $mountArguments = array_values(array_filter(
                $execution['command'],
                static fn (mixed $argument): bool => is_string($argument) && str_contains($argument, '/data/facebook_profile/storage_state.json')
            ));
            hub_test_assert(!file_exists($context['workspace'] . '/input/storage_state.json'), 'profile state must never enter the task workspace');
            $context['started'](['container_id' => 'facebook-mount-test']);
            file_put_contents(
                $context['workspace'] . '/output/facebook_posts.jsonl',
                "{\"source_url\":\"https://www.facebook.com/wra.gov.tw\",\"content\":\"fixture one\"}\n"
                . "{\"source_url\":\"https://www.facebook.com/wra.gov.tw\",\"content\":\"fixture two\"}\n"
            );
            file_put_contents($context['workspace'] . '/output/facebook_crawl_report.json', hub_json_encode([
                'outcome' => 'complete',
                'target_count' => 1,
                'post_count' => 2,
                'limit_per_target' => 10,
                'targets' => [['url' => 'https://www.facebook.com/wra.gov.tw', 'status' => 'completed']],
                'created_at' => hub_now(),
                'runner_version' => 'test',
            ]));
            return ['exit_code' => 0, 'container_id' => 'facebook-mount-test', 'cleanup' => hub_pack_job_no_work_cleanup()];
        },
    ]);
    $expectedMount = 'type=bind,src=' . $statePath . ',dst=/data/facebook_profile/storage_state.json,readonly';
    hub_test_assert(
        ($outcome['status'] ?? '') === 'success',
        'verified Facebook profile fixture must complete: ' . hub_json_encode(['outcome' => $outcome, 'logs' => hub_list_task_logs($db, $taskId)])
    );
    hub_test_assert($mountArguments === [$expectedMount], 'crawler must receive exactly one fixed read-only profile mount');
    hub_test_assert($db->query("SELECT active_task_id FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote((string)$profile['profile_id']))->fetchColumn() === null, 'successful task must release its profile fence');

    $failedId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], (string)$profile['profile_id']);
    $failedTask = hub_claim_next_task($db, ['pack_job']);
    $failed = hub_run_pack_job_task($db, $failedTask, [
        'worker_id' => 'facebook-profile-failure',
        'executor' => static fn (): array => [
            'exit_code' => 1,
            'error_code' => 'fixture_failure',
            'completed_no_process_evidence' => true,
            'cleanup' => hub_pack_job_no_work_cleanup(),
        ],
    ]);
    hub_test_assert(($failed['status'] ?? '') === 'failed' && (int)$failedTask['id'] === $failedId, 'failure fixture must reach one terminal task');
    hub_test_assert($db->query("SELECT active_task_id FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote((string)$profile['profile_id']))->fetchColumn() === null, 'failed task must release its profile fence');

    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook crawler WSL plan copies only request and public artifacts', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'facebook-crawler', ['idempotent' => true])['service'];
    $pack = hub_get_pack('facebook-crawler');
    $job = is_array($pack) ? hub_pack_async_job_contract($pack['manifest'], 'crawl') : null;
    hub_test_assert(is_array($job), 'Facebook crawler WSL contract is required');
    $memberId = hub_create_api_member($db, 'Crawler WSL owner');
    $profileRow = hub_facebook_profile_create($db, $memberId, 'WSL profile');
    hub_test_facebook_mark_ready($db, $profileRow);
    $workspace = HUB_DATA_DIR . '/results/facebook-wsl-plan-' . bin2hex(random_bytes(8));
    foreach (['input', 'output', 'checkpoints'] as $name) {
        if (!mkdir($workspace . '/' . $name, 0700, true)) {
            throw new RuntimeException('Cannot create Facebook WSL workspace fixture.');
        }
    }
    file_put_contents($workspace . '/input/request.json', "{\"targets_json\":\"[]\",\"limit_per_target\":10}\n");
    $runtimeProfile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
    ]]];
    $mount = [
        'source' => hub_facebook_profile_state_path($profileRow),
        'container_path' => '/data/facebook_profile/storage_state.json',
    ];
    $task = ['id' => 42, 'pack_id' => 'facebook-crawler', 'job' => 'crawl', 'input' => ['profile_id' => $profileRow['profile_id']]];
    $context = [
        'task' => $task,
        'run' => ['run_id' => 'packjob-42-facebook012345'],
        'workspace' => $workspace,
        'runner' => hub_pack_job_runner_arguments(
            $job['runner'],
            $task,
            ['run_id' => 'packjob-42-facebook012345'],
            $workspace,
            null,
            [],
            null,
            $mount
        ),
    ];
    try {
        $plan = hub_facebook_crawler_wsl_execution_plan($service, $context, $runtimeProfile);
        $payload = hub_test_facebook_wsl_payload($plan['command']);
        hub_test_assert(str_contains($payload, '/DATA/3waAIHub-runtime/jobs/facebook-crawler/packjob-42-facebook012345')
            && str_contains($payload, "'--network' 'bridge'")
            && str_contains($payload, "'--cap-add' 'NET_ADMIN'")
            && str_contains($payload, '3waaihub/facebook-crawler:0.1.0')
            && str_contains($payload, 'wslpath -a "$windows_profile"')
            && str_contains($payload, 'dst=/data/facebook_profile/storage_state.json,readonly')
            && str_contains($payload, 'facebook_posts.jsonl')
            && str_contains($payload, 'facebook_crawl_report.json')
            && !str_contains($payload, 'cp -- "$profile_state"')
            && !str_contains($payload, 'storage_state.json" "$host_workspace')
            && !str_contains($payload, 'cp -a'), 'Facebook WSL job must keep cookie state out of task workspaces');
    } finally {
        hub_test_remove_data_tree($workspace);
        hub_facebook_profile_delete($db, (string)$profileRow['profile_id'], $memberId);
    }
});

hub_test('Facebook dataset APIs distinguish latest terminal run from latest available dataset', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler dataset owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler dataset token');
    $items = [
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'post_url' => 'https://www.facebook.com/posts/1', 'content' => 'one'],
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'post_url' => 'https://www.facebook.com/posts/2', 'content' => 'two'],
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'post_url' => 'https://www.facebook.com/posts/3', 'content' => 'three'],
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'post_url' => 'https://www.facebook.com/posts/4', 'content' => 'four'],
    ];
    $dataset = hub_test_facebook_dataset_task($db, $memberId, (int)$token['token_id'], $items);
    $failedTaskId = hub_test_facebook_enqueue_crawl($db, $memberId, (int)$token['token_id'], null);
    $db->prepare("UPDATE tasks SET status = 'failed', error_code = 'no_accessible_targets', finished_at = :now WHERE id = :id")
        ->execute([':now' => hub_now(), ':id' => $failedTaskId]);

    $last = hub_test_facebook_dataset_request($db, 'facebook_run_last', $token['plain_token']);
    $lastPayload = hub_test_facebook_login_payload($last);
    hub_test_assert($last['status'] === 200
        && (int)($lastPayload['task_id'] ?? 0) === $failedTaskId
        && ($lastPayload['status'] ?? '') === 'failed'
        && ($lastPayload['dataset_available'] ?? null) === false,
        'latest run must report the newest owned terminal task without inventing a dataset');

    $page = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token'], ['offset' => '1', 'limit' => '2']);
    $pagePayload = hub_test_facebook_login_payload($page);
    hub_test_assert($page['status'] === 200
        && (int)($pagePayload['task_id'] ?? 0) === $dataset['task_id']
        && ($pagePayload['offset'] ?? null) === 1
        && ($pagePayload['limit'] ?? null) === 2
        && ($pagePayload['count'] ?? null) === 2
        && ($pagePayload['next_offset'] ?? null) === 3
        && array_column($pagePayload['items'] ?? [], 'content') === ['two', 'three'],
        'dataset pagination must select the newest available success and return a deterministic slice');
    $artifact = hub_get_task_artifact($db, (int)$dataset['artifact_id']);
    hub_test_assert(!empty($artifact['last_accessed_at']) && empty($artifact['download_claim_token']), 'dataset reads must update access time and release the artifact claim');

    $modes = $db->query("SELECT mode FROM api_access_logs WHERE mode IN ('facebook_run_last', 'facebook_dataset_items') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    hub_test_assert($modes === ['facebook_run_last', 'facebook_dataset_items'], 'dataset convenience calls must retain their real operation names in API logs');
});

hub_test('Facebook dataset APIs enforce member scope and bounded query values', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler dataset A');
    $memberB = hub_create_api_member($db, 'Crawler dataset B');
    $tokenA = hub_test_facebook_login_token($db, $memberA, 'crawler dataset A');
    $tokenB = hub_test_facebook_login_token($db, $memberB, 'crawler dataset B');
    $dataset = hub_test_facebook_dataset_task($db, $memberA, (int)$tokenA['token_id'], [
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'content' => 'owned'],
    ]);

    $explicit = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $tokenA['plain_token'], [
        'task_id' => (string)$dataset['task_id'],
        'offset' => '0',
        'limit' => '100',
    ]);
    hub_test_assert($explicit['status'] === 200 && (hub_test_facebook_login_payload($explicit)['count'] ?? null) === 1, 'owner must read an explicit successful dataset');
    $foreign = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $tokenB['plain_token'], ['task_id' => (string)$dataset['task_id']]);
    hub_test_assert($foreign['status'] === 404, 'another member must not discover a Facebook dataset');
    $foreignLast = hub_test_facebook_dataset_request($db, 'facebook_run_last', $tokenB['plain_token']);
    hub_test_assert($foreignLast['status'] === 404, 'another member must not inherit the latest run');

    foreach ([
        ['offset' => '-1'],
        ['offset' => '1.5'],
        ['limit' => '0'],
        ['limit' => '501'],
        ['limit' => '1.5'],
        ['task_id' => 'not-a-task'],
        ['task_id' => ['nested']],
        ['unknown' => 'field'],
    ] as $query) {
        $response = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $tokenA['plain_token'], $query);
        hub_test_assert($response['status'] === 400, 'invalid Facebook dataset query values must fail closed');
    }
    $wrongMethod = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $tokenA['plain_token'], [], 'POST');
    hub_test_assert($wrongMethod['status'] === 405, 'dataset convenience API must remain GET-only');
});

hub_test('Facebook dataset API rejects invalid expired and purged artifacts', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler invalid dataset owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler invalid dataset token');
    $dataset = hub_test_facebook_dataset_task($db, $memberId, (int)$token['token_id'], [
        ['source_url' => 'https://www.facebook.com/wra.gov.tw', 'content' => 'valid'],
    ]);
    file_put_contents($dataset['path'], "{not-json}\n");
    $invalid = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token'], ['task_id' => (string)$dataset['task_id']]);
    hub_test_assert($invalid['status'] === 409 && (hub_test_facebook_login_payload($invalid)['error'] ?? '') === 'dataset_invalid', 'invalid JSONL must fail closed');

    file_put_contents($dataset['path'], hub_json_encode(['content' => str_repeat('x', 1024 * 1024 + 1)]) . "\n");
    $oversized = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token'], ['task_id' => (string)$dataset['task_id']]);
    hub_test_assert($oversized['status'] === 409 && (hub_test_facebook_login_payload($oversized)['error'] ?? '') === 'dataset_invalid', 'JSONL lines over one MiB must fail closed');

    $db->prepare("UPDATE task_artifacts SET expires_at = :expired WHERE id = :id")
        ->execute([':expired' => date('Y-m-d H:i:s', time() - 60), ':id' => $dataset['artifact_id']]);
    $expired = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token'], ['task_id' => (string)$dataset['task_id']]);
    hub_test_assert($expired['status'] === 410 && (hub_test_facebook_login_payload($expired)['error'] ?? '') === 'dataset_expired', 'expired datasets must return a stable tombstone');
    $latestExpired = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token']);
    hub_test_assert($latestExpired['status'] === 410 && (hub_test_facebook_login_payload($latestExpired)['error'] ?? '') === 'dataset_expired', 'latest lookup must preserve the expired dataset tombstone when none remain available');

    $db->prepare("UPDATE task_artifacts SET state = 'purged', purged_at = :now WHERE id = :id")
        ->execute([':now' => hub_now(), ':id' => $dataset['artifact_id']]);
    $purged = hub_test_facebook_dataset_request($db, 'facebook_dataset_items', $token['plain_token'], ['task_id' => (string)$dataset['task_id']]);
    hub_test_assert($purged['status'] === 410 && (hub_test_facebook_login_payload($purged)['error'] ?? '') === 'dataset_expired', 'purged datasets must return the same stable tombstone');
});

hub_test('Facebook login start stores only proof hash and confines credentials to one broker POST', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler login owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler login token');
    $commands = [];
    $requests = [];
    $runner = static function (array $command, int $timeout = 60) use (&$commands): array {
        $commands[] = $command;
        if (array_slice($command, 0, 2) === ['docker', 'port']) {
            return ['exit_code' => 0, 'stdout' => '127.0.0.1:49176', 'stderr' => '', 'output' => '127.0.0.1:49176'];
        }
        return ['exit_code' => 0, 'stdout' => 'container-id', 'stderr' => '', 'output' => 'container-id'];
    };
    $transport = static function (string $method, string $url, ?array $body, int $maxBytes) use (&$requests): array {
        $requests[] = compact('method', 'url', 'body', 'maxBytes');
        return ['status' => 200, 'content_type' => 'application/json', 'body' => '{"ok":true}'];
    };
    $username = 'private.person@example.test';
    $password = 'private-facebook-password';
    $response = hub_test_facebook_login_request($db, 'facebook_profile_start', $token['plain_token'], 'POST', [
        'display_name' => 'Emergency account',
        'method' => 'password',
        'username' => $username,
        'password' => $password,
    ], $runner, $transport);
    $payload = hub_test_facebook_login_payload($response);

    hub_test_assert($response['status'] === 200 && !empty($payload['ok']), 'password-assisted profile start must succeed');
    $loginUrl = (string)($payload['login_url'] ?? '');
    hub_test_assert(preg_match('/\Afacebook_profile_login\.php#session=([A-Za-z0-9_-]{43})\z/', $loginUrl, $matches) === 1, 'login proof must use URL fragment only');
    hub_test_assert(!str_contains($loginUrl, '?session='), 'login proof must never enter the query string');
    $row = $db->query('SELECT * FROM facebook_crawler_profiles ORDER BY id DESC LIMIT 1')->fetch();
    hub_test_assert(is_array($row) && hash_equals((string)$row['login_secret_hash'], hash('sha256', $matches[1])), 'only the login proof hash may be stored');
    hub_test_assert((string)$row['login_secret_hash'] !== $matches[1], 'plain login proof must not be stored');
    hub_test_assert((int)$row['login_port'] === 49176 && hub_facebook_login_container_valid((string)$row['login_container_name']), 'validated loopback broker metadata must be stored');
    hub_test_assert(strtotime((string)$row['login_expires_at']) <= time() + 600, 'login expiry must not exceed ten minutes');
    hub_test_assert(count($requests) === 2 && $requests[0]['method'] === 'GET' && str_ends_with($requests[0]['url'], '/health'), 'broker health must be checked before credentials');
    hub_test_assert($requests[1]['method'] === 'POST' && str_ends_with($requests[1]['url'], '/credentials'), 'credentials must use one broker POST');
    hub_test_assert($requests[1]['body'] === ['username' => $username, 'password' => $password], 'broker credential body changed');

    $forbiddenHaystacks = [
        hub_json_encode($commands),
        hub_json_encode($row),
        hub_json_encode($payload),
        hub_json_encode($db->query('SELECT * FROM tasks')->fetchAll()),
        hub_json_encode($db->query('SELECT * FROM api_access_logs')->fetchAll()),
    ];
    foreach ([$username, $password] as $secret) {
        foreach ($forbiddenHaystacks as $haystack) {
            hub_test_assert(!str_contains($haystack, $secret), 'credential escaped its one broker POST body');
        }
    }
    foreach ($commands as $command) {
        hub_test_assert(is_array($command), 'Docker commands must remain argv arrays');
    }
    $run = $commands[0] ?? [];
    hub_test_assert(array_slice($run, 0, 8) === ['docker', 'run', '-d', '--rm', '--pull=never', '--network', 'bridge', '--publish'], 'login broker must use the fixed Docker run prefix');
    hub_test_assert(in_array('127.0.0.1::8765', $run, true) && in_array('3waaihub/facebook-crawler:0.1.0', $run, true), 'login broker image or loopback publish changed');
    $cap = array_search('--cap-add', $run, true);
    hub_test_assert($cap !== false && ($run[$cap + 1] ?? '') === 'NET_ADMIN', 'login broker entrypoint requires only NET_ADMIN for its egress firewall');
    hub_test_assert(($run[array_search('--entrypoint', $run, true) + 1] ?? '') === '/app/crawl-entrypoint.sh' && end($run) === '/app/login_broker.py', 'login broker entrypoint must be fixed');
    hub_test_assert(!str_contains(hub_json_encode($payload['profile'] ?? []), HUB_DATA_DIR), 'API profile must not expose a host path');
});

hub_test('Facebook profile gateway operations enforce facebook_crawl owner isolation', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler owner A');
    $memberB = hub_create_api_member($db, 'Crawler owner B');
    $tokenA = hub_test_facebook_login_token($db, $memberA, 'crawler A');
    $tokenB = hub_test_facebook_login_token($db, $memberB, 'crawler B');
    $profile = hub_facebook_profile_create($db, $memberA, 'Owned Facebook profile');

    foreach ([
        ['facebook_profile_status', 'GET'],
        ['facebook_profile_reauth', 'POST'],
        ['facebook_profile_delete', 'POST'],
    ] as [$mode, $method]) {
        $response = hub_test_facebook_login_request(
            $db,
            $mode,
            $tokenB['plain_token'],
            $method,
            ['profile_id' => $profile['profile_id']],
            static fn (array $command): array => throw new RuntimeException('foreign profile must not reach Docker'),
            static fn (): array => throw new RuntimeException('foreign profile must not reach broker')
        );
        hub_test_assert($response['status'] === 404, $mode . ' must hide another member profile');
    }

    $denied = hub_create_api_token($db, $memberA, 'no crawler permission', null, null);
    $response = hub_test_facebook_login_request($db, 'facebook_profile_status', $denied['plain_token'], 'GET', ['profile_id' => $profile['profile_id']]);
    hub_test_assert($response['status'] === 403, 'profile operations must require facebook_crawl permission');
    hub_facebook_profile_delete($db, $profile['profile_id'], $memberA);
});

hub_test('Facebook profile gateway rejects nested and surplus lifecycle fields', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler input owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler input token');
    $cases = [
        ['facebook_profile_start', ['display_name' => ['nested'], 'method' => 'browser']],
        ['facebook_profile_reauth', ['profile_id' => ['nested'], 'method' => 'browser']],
        ['facebook_profile_delete', ['profile_id' => 'fbp_' . str_repeat('a', 48), 'password' => 'must-not-be-accepted']],
    ];
    foreach ($cases as [$mode, $payload]) {
        $response = hub_test_facebook_login_request(
            $db,
            $mode,
            $token['plain_token'],
            'POST',
            $payload,
            static fn (): array => throw new RuntimeException('invalid input must not reach Docker')
        );
        hub_test_assert($response['status'] === 400, $mode . ' must reject nested or surplus fields');
    }
});

hub_test('Facebook proof relay is POST-only owner-logged and closes only with secure logged-in state', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler relay owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'relay token');
    $commands = [];
    $runner = static function (array $command) use (&$commands): array {
        $commands[] = $command;
        if (array_slice($command, 0, 2) === ['docker', 'port']) {
            return ['exit_code' => 0, 'stdout' => '127.0.0.1:49201', 'stderr' => '', 'output' => '127.0.0.1:49201'];
        }
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
    };
    $startTransport = static fn (): array => ['status' => 200, 'content_type' => 'application/json', 'body' => '{"ok":true}'];
    $start = hub_test_facebook_login_request($db, 'facebook_profile_start', $token['plain_token'], 'POST', [
        'display_name' => 'Relay account',
        'method' => 'browser',
    ], $runner, $startTransport);
    $startPayload = hub_test_facebook_login_payload($start);
    preg_match('/#session=([A-Za-z0-9_-]{43})\z/', (string)$startPayload['login_url'], $matches);
    $proof = $matches[1] ?? '';
    $profileId = (string)$startPayload['profile']['profile_id'];

    $get = hub_test_facebook_login_request($db, 'facebook_profile_frame', '', 'GET', ['proof' => $proof]);
    hub_test_assert($get['status'] === 405, 'proof relay must reject GET');

    $frame = hub_gateway_dispatch($db, 'facebook_profile_frame', null, [
        'client_ip' => '203.0.113.55',
        'method' => 'POST',
        'request_uri' => '/api.php?mode=facebook_profile_frame',
        'raw_body' => hub_json_encode(['proof' => $proof]),
        'login_transport' => static fn (string $method, string $url): array => [
            'status' => 200,
            'content_type' => 'image/png',
            'body' => "\x89PNG\r\n\x1a\nframe",
        ],
        'command_runner' => $runner,
        'platform' => 'linux',
    ]);
    hub_test_assert($frame['status'] === 200 && in_array('Content-Type: image/png', $frame['headers'], true), 'frame relay must preserve bounded PNG response');
    $log = $db->query("SELECT * FROM api_access_logs WHERE mode = 'facebook_profile_frame' ORDER BY id DESC LIMIT 1")->fetch();
    hub_test_assert((int)$log['member_id'] === $memberId && !str_contains((string)$log['request_uri'], $proof), 'relay log must attach owner without proof');

    $row = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profileId))->fetch();
    $statePath = hub_facebook_profile_state_path($row);
    $closeTransport = static function (string $method, string $url, ?array $body) use ($statePath): array {
        hub_test_assert($method === 'POST' && str_ends_with($url, '/close') && $body === [], 'close must relay only to broker close');
        file_put_contents($statePath, '{"cookies":[{"name":"c_user","value":"123","domain":".facebook.com"}]}');
        chmod($statePath, 0600);
        return ['status' => 200, 'content_type' => 'application/json', 'body' => '{"ok":true,"state":"logged_in","logged_in":true}'];
    };
    $close = hub_gateway_dispatch($db, 'facebook_profile_close', null, [
        'client_ip' => '203.0.113.55',
        'method' => 'POST',
        'request_uri' => '/api.php?mode=facebook_profile_close',
        'raw_body' => hub_json_encode(['proof' => $proof]),
        'login_transport' => $closeTransport,
        'command_runner' => $runner,
        'platform' => 'linux',
    ]);
    $closedPayload = hub_test_facebook_login_payload($close);
    hub_test_assert($close['status'] === 200 && ($closedPayload['profile']['state'] ?? '') === 'ready', 'secure logged-in close must mark profile ready');
    $closed = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profileId))->fetch();
    foreach (['login_secret_hash', 'login_container_name', 'login_port', 'login_expires_at'] as $field) {
        hub_test_assert($closed[$field] === null, 'close must clear ' . $field);
    }
    hub_test_assert($closed['last_verified_at'] !== null, 'close must record verification time');
    hub_test_assert(array_slice(end($commands), 0, 2) === ['docker', 'stop'], 'close must stop the validated container');
    hub_facebook_profile_delete($db, $profileId, $memberId);
});

hub_test('Facebook relay rejects malformed proof metadata and cleanup stops at ten expired sessions', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler cleanup owner');
    $profiles = [];
    $proofs = [];
    for ($i = 0; $i < 11; $i++) {
        $profile = hub_facebook_profile_create($db, $memberId, 'Expired profile ' . $i);
        $profiles[] = $profile;
        $proofs[] = str_pad((string)$i, 43, 'a');
        $db->prepare(
            'UPDATE facebook_crawler_profiles
             SET login_secret_hash = :hash, login_container_name = :container, login_port = :port, login_expires_at = :expires
             WHERE profile_id = :profile_id'
        )->execute([
            ':hash' => hash('sha256', $proofs[$i]),
            ':container' => hub_facebook_login_container_name($profile['profile_id']),
            ':port' => 49300 + $i,
            ':expires' => date('Y-m-d H:i:s', time() - 60),
            ':profile_id' => $profile['profile_id'],
        ]);
    }
    $commands = [];
    $cleaned = hub_facebook_login_cleanup_expired(
        $db,
        10,
        static function (array $command) use (&$commands): array {
            $commands[] = $command;
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
        },
        'linux'
    );
    hub_test_assert($cleaned === 10 && count($commands) === 10, 'cleanup must stop at ten expired sessions');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM facebook_crawler_profiles WHERE login_secret_hash IS NOT NULL')->fetchColumn() === 1, 'cleanup must leave the eleventh session for the next run');
    foreach ($commands as $command) {
        hub_test_assert(count($command) === 3 && $command[0] === 'docker' && $command[1] === 'stop' && hub_facebook_login_container_valid($command[2]), 'cleanup must stop only the exact validated container');
    }

    $remaining = $db->query('SELECT * FROM facebook_crawler_profiles WHERE login_secret_hash IS NOT NULL LIMIT 1')->fetch();
    $db->prepare('UPDATE facebook_crawler_profiles SET login_container_name = :bad, login_expires_at = :expires WHERE id = :id')
        ->execute([
            ':bad' => '../../bad-container',
            ':expires' => date('Y-m-d H:i:s', time() + 300),
            ':id' => (int)$remaining['id'],
        ]);
    $transportCalled = false;
    $relay = hub_gateway_dispatch($db, 'facebook_profile_login_status', null, [
        'method' => 'POST',
        'request_uri' => '/api.php?mode=facebook_profile_login_status',
        'raw_body' => hub_json_encode(['proof' => $proofs[10]]),
        'login_transport' => static function () use (&$transportCalled): array {
            $transportCalled = true;
            return [];
        },
        'platform' => 'linux',
    ]);
    hub_test_assert($relay['status'] >= 400 && !$transportCalled, 'malformed login metadata must fail before broker transport');

    $db->exec('UPDATE facebook_crawler_profiles SET login_secret_hash = NULL, login_container_name = NULL, login_port = NULL, login_expires_at = NULL');
    foreach ($profiles as $profile) {
        hub_facebook_profile_delete($db, $profile['profile_id'], $memberId);
    }
});

hub_test('Facebook login cleanup cannot clear a replacement session', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler session fence owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'Session fence profile');
    $container = hub_facebook_login_container_name((string)$profile['profile_id']);
    $db->prepare(
        'UPDATE facebook_crawler_profiles
         SET login_secret_hash = :hash, login_container_name = :container, login_port = :port, login_expires_at = :expires
         WHERE profile_id = :profile_id'
    )->execute([
        ':hash' => hash('sha256', 'old-session'),
        ':container' => $container,
        ':port' => 49501,
        ':expires' => date('Y-m-d H:i:s', time() + 300),
        ':profile_id' => $profile['profile_id'],
    ]);
    $stale = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profile['profile_id']))->fetch();
    $replacementHash = hash('sha256', 'replacement-session');
    $db->prepare('UPDATE facebook_crawler_profiles SET login_secret_hash = :hash, login_port = :port WHERE id = :id')
        ->execute([':hash' => $replacementHash, ':port' => 49502, ':id' => (int)$stale['id']]);

    hub_test_assert(!hub_facebook_login_clear($db, $stale), 'stale session metadata must not clear a replacement session');
    $current = $db->query('SELECT * FROM facebook_crawler_profiles WHERE id = ' . (int)$stale['id'])->fetch();
    hub_test_assert($current['login_secret_hash'] === $replacementHash && (int)$current['login_port'] === 49502, 'replacement session metadata must survive stale cleanup');

    $db->exec('UPDATE facebook_crawler_profiles SET login_secret_hash = NULL, login_container_name = NULL, login_port = NULL, login_expires_at = NULL');
    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook login stop confirms an already absent container', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    $runner = static function (array $command) use (&$commands): array {
        $commands[] = $command;
        if (array_slice($command, 0, 3) === ['docker', 'ps', '-a']) {
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
        }
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'not found', 'output' => 'not found'];
    };
    $name = 'aihub-fb-login-' . str_repeat('a', 24);

    hub_test_assert(hub_facebook_login_stop($db, $name, $runner, 'linux'), 'an absent exact container is already stopped');
    hub_test_assert(count($commands) === 2 && array_slice($commands[1], 0, 3) === ['docker', 'ps', '-a'], 'failed stop must use one fixed absence check');

    $profileId = 'fbp_' . str_repeat('b', 48);
    $first = hub_facebook_login_container_name($profileId);
    $second = hub_facebook_login_container_name($profileId);
    hub_test_assert($first !== $second && hub_facebook_login_container_valid($first) && hub_facebook_login_container_valid($second), 'each login session must use a unique validated container name');
});

hub_test('Facebook ready profile can start a fenced reauth session', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler reauth owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler reauth token');
    $profile = hub_facebook_profile_create($db, $memberId, 'Ready reauth profile');
    $db->prepare("UPDATE facebook_crawler_profiles SET state = 'ready', last_verified_at = :now WHERE profile_id = :profile_id")
        ->execute([':now' => hub_now(), ':profile_id' => $profile['profile_id']]);
    $runner = static function (array $command): array {
        if (array_slice($command, 0, 2) === ['docker', 'port']) {
            return ['exit_code' => 0, 'stdout' => '127.0.0.1:49601', 'stderr' => '', 'output' => '127.0.0.1:49601'];
        }
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
    };
    $response = hub_test_facebook_login_request(
        $db,
        'facebook_profile_reauth',
        $token['plain_token'],
        'POST',
        ['profile_id' => $profile['profile_id'], 'method' => 'browser'],
        $runner,
        static fn (): array => ['status' => 200, 'content_type' => 'application/json', 'body' => '{"ok":true}']
    );
    $payload = hub_test_facebook_login_payload($response);
    hub_test_assert($response['status'] === 200 && ($payload['profile']['state'] ?? '') === 'preparing', 'ready profile reauth must start one preparing session');

    hub_facebook_login_delete($db, $memberId, (string)$profile['profile_id'], $runner, 'linux');
});

hub_test('Facebook active profile cannot start a reauth session', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler busy reauth owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'crawler busy reauth token');
    $profile = hub_facebook_profile_create($db, $memberId, 'Busy reauth profile');
    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null);
    $db->prepare("UPDATE facebook_crawler_profiles SET state = 'ready', active_task_id = :task_id WHERE profile_id = :profile_id")
        ->execute([':task_id' => $taskId, ':profile_id' => $profile['profile_id']]);
    $runnerCalled = false;
    $response = hub_test_facebook_login_request(
        $db,
        'facebook_profile_reauth',
        $token['plain_token'],
        'POST',
        ['profile_id' => $profile['profile_id'], 'method' => 'browser'],
        static function () use (&$runnerCalled): array {
            $runnerCalled = true;
            return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
        }
    );
    hub_test_assert($response['status'] === 409 && !$runnerCalled, 'active profile reauth must fail before launching a broker');

    $db->prepare('UPDATE facebook_crawler_profiles SET active_task_id = NULL WHERE profile_id = :profile_id')
        ->execute([':profile_id' => $profile['profile_id']]);
    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook close revokes a failed broker session without marking the profile ready', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler failed close owner');
    $token = hub_test_facebook_login_token($db, $memberId, 'failed close token');
    $runner = static function (array $command): array {
        if (array_slice($command, 0, 2) === ['docker', 'port']) {
            return ['exit_code' => 0, 'stdout' => '127.0.0.1:49401', 'stderr' => '', 'output' => '127.0.0.1:49401'];
        }
        return ['exit_code' => 0, 'stdout' => '', 'stderr' => '', 'output' => ''];
    };
    $start = hub_test_facebook_login_request(
        $db,
        'facebook_profile_start',
        $token['plain_token'],
        'POST',
        ['display_name' => 'Failed close account', 'method' => 'browser'],
        $runner,
        static fn (): array => ['status' => 200, 'content_type' => 'application/json', 'body' => '{"ok":true}']
    );
    $payload = hub_test_facebook_login_payload($start);
    preg_match('/#session=([A-Za-z0-9_-]{43})\z/', (string)$payload['login_url'], $matches);
    $profileId = (string)$payload['profile']['profile_id'];
    $close = hub_gateway_dispatch($db, 'facebook_profile_close', null, [
        'method' => 'POST',
        'request_uri' => '/api.php?mode=facebook_profile_close',
        'raw_body' => hub_json_encode(['proof' => $matches[1]]),
        'login_transport' => static fn (): array => ['status' => 502, 'content_type' => 'application/json', 'body' => '{"ok":false}'],
        'command_runner' => $runner,
        'platform' => 'linux',
    ]);
    hub_test_assert($close['status'] >= 400, 'failed broker close must not report success');
    $row = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profileId))->fetch();
    hub_test_assert($row['state'] === 'preparing', 'failed close must not mark the profile ready');
    foreach (['login_secret_hash', 'login_container_name', 'login_port', 'login_expires_at'] as $field) {
        hub_test_assert($row[$field] === null, 'failed close must clear ' . $field);
    }
    hub_facebook_profile_delete($db, $profileId, $memberId);
});

hub_test('Facebook login state accepts only Facebook cookie domains', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Crawler cookie owner');
    $profile = hub_facebook_profile_create($db, $memberId, 'Cookie domain profile');
    $row = $db->query("SELECT * FROM facebook_crawler_profiles WHERE profile_id = " . $db->quote($profile['profile_id']))->fetch();
    $path = hub_facebook_profile_state_path($row);

    file_put_contents($path, '{"cookies":[{"name":"c_user","value":"123","domain":".evilfacebook.com"}]}');
    chmod($path, 0600);
    hub_test_assert(!hub_facebook_login_state_secure($row), 'lookalike cookie domains must not verify a profile');

    file_put_contents($path, '{"cookies":[{"name":"c_user","value":"123","domain":".facebook.com"}]}');
    chmod($path, 0600);
    hub_test_assert(hub_facebook_login_state_secure($row), 'facebook.com cookie domains must verify a profile');

    hub_facebook_profile_delete($db, (string)$profile['profile_id'], $memberId);
});

hub_test('Facebook login production command runner is bounded and web-safe', function (): void {
    $result = hub_facebook_login_command_runner([PHP_BINARY, '-r', 'fwrite(STDOUT, "runner-ok");'], 5);
    hub_test_assert((int)$result['exit_code'] === 0 && $result['stdout'] === 'runner-ok', 'login command runner must execute argv arrays');
    $large = hub_facebook_login_command_runner([PHP_BINARY, '-r', 'fwrite(STDOUT, str_repeat("x", 70000));'], 5);
    hub_test_assert(strlen((string)$large['stdout']) === HUB_FACEBOOK_LOGIN_COMMAND_OUTPUT_MAX, 'login command runner must cap captured output');
    $timedOut = hub_facebook_login_command_runner([PHP_BINARY, '-r', 'sleep(3);'], 1);
    hub_test_assert((int)$timedOut['exit_code'] === 124 && str_contains((string)$timedOut['stderr'], 'timed out'), 'login command runner must enforce its timeout');
    $source = (string)file_get_contents(HUB_ROOT . '/app/facebook_crawler_login.php');
    hub_test_assert(!str_contains($source, "\$runner ??= 'hub_run_command'"), 'web login lifecycle must not call the CLI-only command runner');
});

hub_test('Facebook crawler remains local while Phase A Cluster publication excludes it', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);

    hub_test_assert(hub_resolve_pack_job_async_route($db, 'facebook_crawl') !== null, 'local crawler route must remain available');
    hub_test_assert(!in_array('facebook_crawl', hub_cluster_node_published_modes($db), true), 'Phase A must not publish node-owned crawler profiles through Cluster');
});

hub_test('Facebook profile bootstrap does not rechmod an already private directory', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/app/bootstrap.php');
    hub_test_assert(str_contains($source, '((int)$facebookProfileMode & 0777) !== 0700'), 'bootstrap must inspect the private directory mode first');
    hub_test_assert(!str_contains($source, "is_link(\$facebookProfileRoot) || !@chmod(\$facebookProfileRoot, 0700)"), 'bootstrap must not rechmod a secure directory on every request');
});

hub_test('Facebook crawler real smoke keeps credentials file-only and verifies content', function (): void {
    $path = HUB_ROOT . '/scripts/facebook_crawler_smoke.php';
    hub_test_assert(is_file($path), 'crawler smoke script missing');
    if (PHP_OS_FAMILY !== 'Windows') {
        hub_test_assert((fileperms($path) & 0777) === 0755, 'crawler smoke script must be executable');
    }
    $source = (string)file_get_contents($path);
    foreach ([
        "!== 'https'",
        'token_file_must_be_outside_webroot',
        'token_file_permissions_too_open',
        "(int)(\$stat['nlink'] ?? 0) !== 1",
        'usleep(2000000)',
        'hash_equals($expectedSha256, $actualSha256)',
        '$jsonlCount < 1',
    ] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'crawler smoke missing security/content check ' . $needle);
    }
    $outputStart = strpos($source, 'echo hub_json_encode([');
    $outputEnd = $outputStart === false ? false : strpos($source, '], JSON_UNESCAPED_SLASHES', $outputStart);
    $outputSource = $outputStart === false || $outputEnd === false
        ? ''
        : substr($source, $outputStart, $outputEnd - $outputStart);
    hub_test_assert($outputSource !== ''
        && !str_contains($outputSource, '$token')
        && !str_contains($outputSource, '$profileId'), 'crawler smoke output must not print secrets or profile identity');
});
