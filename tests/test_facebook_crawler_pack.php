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
    hub_test_assert(str_contains((string)file_get_contents(HUB_ROOT . '/.gitignore'), 'data/facebook-crawler/'), 'private browser state must never enter Git');

    hub_facebook_profile_delete($db, $profile['profile_id'], $memberA);
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
        @unlink($parent);
        @rename($backup, $parent);
        hub_test_remove_data_tree($outside);
    }
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
        hub_test_assert(hub_facebook_profile_for_member($db, $symlinkProfile['profile_id'], $memberId) === null, 'rejected symlink profile must fail closed outside the active API');
        hub_test_assert(hub_facebook_profile_for_member($db, $hardlinkProfile['profile_id'], $memberId) === null, 'rejected hardlink profile must fail closed outside the active API');
        $deletingCount = (int)$db->query("SELECT COUNT(*) FROM facebook_crawler_profiles WHERE state = 'deleting' AND deleted_at IS NULL")->fetchColumn();
        hub_test_assert($deletingCount === 3, 'rejected storage cleanup must retain retryable deleting tombstones');
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
