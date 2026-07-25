<?php
declare(strict_types=1);

function hub_test_with_cluster_secret(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_SECRET_KEY');
        } else {
            putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
        }
    }
}

function hub_test_cluster_station_pairing(): array
{
    return [
        'station_key' => 'taipei_gpu_1',
        'display_name' => 'Taipei GPU 1',
        'public_base_url' => 'https://station.example/aihub',
        'internal_base_url' => 'https://station.internal:8080/aihub',
        'priority' => 7,
        'enabled' => true,
        'station_token' => '3wa_live_station_secret',
        'modes' => ['vision', 'tts'],
    ];
}

function hub_test_cluster_station_fixture(array $overrides = []): array
{
    return array_replace([
        'id' => 1,
        'priority' => 10,
        'enabled' => true,
        'fresh' => true,
        'modes' => ['vision'],
        'gpu_free_vram_mb' => 4096,
        'active_gpu_leases' => 0,
        'queued_jobs' => 0,
    ], $overrides);
}

function hub_test_cluster_router_customer_token(PDO $db, array $modes): array
{
    $memberId = hub_create_api_member($db, 'Cluster Router Customer');
    $token = hub_create_api_token($db, $memberId, 'cluster router token', null, null);
    foreach ($modes as $mode) {
        hub_add_api_token_mode_permission($db, (int)$token['token_id'], $mode);
    }

    return $token;
}

function hub_test_cluster_router_station(PDO $db, array $overrides = []): array
{
    $pairing = array_replace(hub_test_cluster_station_pairing(), $overrides);
    $stationId = hub_cluster_save_paired_station($db, $pairing);
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('cluster router station missing');
    }

    return $station;
}

function hub_test_cluster_router_request(string $token, array $overrides = []): array
{
    return array_replace([
        'bearer_token' => $token,
        'client_ip' => '203.0.113.10',
        'method' => 'POST',
        'raw_body' => '{"text":"hello"}',
        'query' => [],
        'headers' => ['Content-Type' => 'application/json'],
        'files' => [],
        'request_uri' => '/cluster_api.php?mode=vision',
    ], $overrides);
}

function hub_test_cluster_router_async_route(PDO $db, array $stationOverrides = []): array
{
    $station = hub_test_cluster_router_station($db, $stationOverrides);
    $customer = hub_test_cluster_router_customer_token($db, []);
    $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
    $routeId = 'route_' . str_repeat('a', 32);
    $db->prepare(
        "INSERT INTO cluster_routes
            (route_id, station_id, member_id, token_id, mode, remote_task_id, is_async, state, created_at, updated_at)
         VALUES
            (:route_id, :station_id, :member_id, :token_id, 'vision', 'remote_task_42', 1, 'active', :created_at, :updated_at)"
    )->execute([
        ':route_id' => $routeId,
        ':station_id' => (int)$station['id'],
        ':member_id' => $memberId,
        ':token_id' => (int)$customer['token_id'],
        ':created_at' => hub_now(),
        ':updated_at' => hub_now(),
    ]);

    return ['station' => $station, 'customer' => $customer, 'member_id' => $memberId, 'route_id' => $routeId];
}

function hub_test_with_cluster_router_env(string $key, string $value, callable $fn): void
{
    $previous = getenv($key);
    putenv($key . '=' . $value);

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv($key);
        } else {
            putenv($key . '=' . $previous);
        }
    }
}

function hub_test_cluster_publish_mode(PDO $db, string $mode, bool $running = true): void
{
    $existingMode = hub_get_service_by_mode($db, 'hello') === null ? $mode : 'hello';
    $db->prepare(
        'UPDATE services
         SET mode = :mode, install_status = :install_status, enabled = 1,
             runtime_status = :runtime_status, status = :status, updated_at = :updated_at
         WHERE mode = :existing_mode'
    )->execute([
        ':mode' => $mode,
        ':install_status' => 'installed',
        ':runtime_status' => $running ? 'running' : 'stopped',
        ':status' => $running ? 'running' : 'stopped',
        ':updated_at' => hub_now(),
        ':existing_mode' => $existingMode,
    ]);
}

function hub_test_with_cluster_http_internal(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL');
    putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL=1');

    try {
        $fn();
    } finally {
        if ($previous === false) {
            putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL');
        } else {
            putenv('AIHUB_CLUSTER_ALLOW_HTTP_INTERNAL=' . $previous);
        }
    }
}

function hub_test_with_cluster_pair_url(callable $fn): void
{
    $keys = ['HTTPS', 'HTTP_HOST', 'SCRIPT_NAME', 'SERVER_NAME', 'SERVER_PORT'];
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null;
    }
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'station.example';
    $_SERVER['SCRIPT_NAME'] = '/cluster_pair.php';

    try {
        $fn();
    } finally {
        foreach ($previous as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }
    }
}

hub_test('cluster router migration creates all persistence tables', function (): void {
    $db = hub_test_reset_db();
    $tables = array_fill_keys(
        $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN),
        true
    );

    foreach (['cluster_stations', 'cluster_routes', 'cluster_route_accesses', 'cluster_route_artifacts'] as $table) {
        hub_test_assert(isset($tables[$table]), 'cluster router table missing: ' . $table);
    }
    $accessIndexes = array_column($db->query('PRAGMA index_list(cluster_route_accesses)')->fetchAll(), 'name');
    foreach (['idx_cluster_route_accesses_station_usage', 'idx_cluster_route_accesses_mode_usage'] as $index) {
        hub_test_assert(in_array($index, $accessIndexes, true), 'cluster usage index missing: ' . $index);
    }
});

hub_test('cluster router rejects NULL route IDs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());

        hub_test_assert(hub_test_throws(static function () use ($db, $stationId): void {
            $db->prepare(
                'INSERT INTO cluster_routes (route_id, station_id, mode, state, created_at, updated_at)
                 VALUES (:route_id, :station_id, :mode, :state, :created_at, :updated_at)'
            )->execute([
                ':route_id' => null,
                ':station_id' => $stationId,
                ':mode' => 'vision',
                ':state' => 'created',
                ':created_at' => hub_now(),
                ':updated_at' => hub_now(),
            ]);
        }), 'cluster route NULL ID must reject');
    });
});

hub_test('cluster router upgrades legacy nullable route IDs without losing valid routes or indexes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $db->exec('PRAGMA foreign_keys = OFF');
        $db->exec('DROP TABLE cluster_routes');
        $db->exec(<<<'SQL'
CREATE TABLE cluster_routes (
    route_id TEXT PRIMARY KEY,
    station_id INTEGER NOT NULL,
    member_id INTEGER NULL,
    token_id INTEGER NULL,
    mode TEXT NOT NULL,
    remote_task_id TEXT NULL,
    is_async INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL,
    remote_status TEXT NULL,
    expires_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    FOREIGN KEY(station_id) REFERENCES cluster_stations(id) ON DELETE CASCADE,
    FOREIGN KEY(member_id) REFERENCES api_members(id) ON DELETE SET NULL,
    FOREIGN KEY(token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);
SQL);
        $db->exec('CREATE INDEX idx_cluster_routes_legacy_remote_task ON cluster_routes(remote_task_id)');
        $db->prepare(
            'INSERT INTO cluster_routes (route_id, station_id, mode, remote_task_id, state, created_at, updated_at)
             VALUES (:route_id, :station_id, :mode, :remote_task_id, :state, :created_at, :updated_at)'
        )->execute([
            ':route_id' => 'route_legacy_1',
            ':station_id' => $stationId,
            ':mode' => 'vision',
            ':remote_task_id' => 'remote_legacy_1',
            ':state' => 'created',
            ':created_at' => hub_now(),
            ':updated_at' => hub_now(),
        ]);
        $db->exec('PRAGMA foreign_keys = ON');

        hub_migrate($db);
        hub_migrate($db);

        $columns = array_column($db->query('PRAGMA table_info(cluster_routes)')->fetchAll(), null, 'name');
        hub_test_assert((int)$columns['route_id']['notnull'] === 1, 'legacy cluster route ID must become NOT NULL');
        hub_test_assert((string)$db->query("SELECT remote_task_id FROM cluster_routes WHERE route_id = 'route_legacy_1'")->fetchColumn() === 'remote_legacy_1', 'legacy valid route must survive rebuild');
        $indexes = array_column($db->query('PRAGMA index_list(cluster_routes)')->fetchAll(), 'name');
        hub_test_assert(in_array('idx_cluster_routes_legacy_remote_task', $indexes, true), 'legacy route index must survive rebuild');
        hub_test_assert(hub_test_throws(static function () use ($db, $stationId): void {
            $db->prepare(
                'INSERT INTO cluster_routes (route_id, station_id, mode, state, created_at, updated_at)
                 VALUES (NULL, :station_id, :mode, :state, :created_at, :updated_at)'
            )->execute([
                ':station_id' => $stationId,
                ':mode' => 'vision',
                ':state' => 'created',
                ':created_at' => hub_now(),
                ':updated_at' => hub_now(),
            ]);
        }), 'upgraded cluster route NULL ID must reject');
    });
});

hub_test('cluster router encrypts station tokens at rest and decrypts internal records', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $stored = $db->query('SELECT * FROM cluster_stations WHERE id = ' . (int)$stationId)->fetch();

        hub_test_assert($stored !== false, 'paired station row missing');
        hub_test_assert(!str_contains(implode(' ', array_map('strval', $stored)), '3wa_live_station_secret'), 'raw station token must not be stored');
        hub_test_assert(hub_cluster_station_token($stored) === '3wa_live_station_secret', 'internal station token must decrypt');

        $listed = hub_cluster_list_stations($db);
        hub_test_assert(count($listed) === 1, 'paired station must be listed');
        foreach (['token_ciphertext', 'token_iv', 'token_tag', 'station_token'] as $secretField) {
            hub_test_assert(!array_key_exists($secretField, $listed[0]), 'station list must hide ' . $secretField);
        }
    });
});

hub_test('cluster router rejects an invalid secret and invalid station base URLs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        putenv('AIHUB_CLUSTER_SECRET_KEY=not-a-valid-key');
        hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_secret_key()), 'invalid cluster secret must reject');

        putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
        hub_test_assert(
            hub_cluster_validate_station_base_url('https://station.example/aihub') === 'https://station.example/aihub/',
            'station base URL must normalize its path trailing slash'
        );
        foreach ([
            'ftp://station.example',
            'http://192.168.1.106/aihub',
            'https://user:pass@station.example',
            'https://station.example/path#fragment',
            'https://station.example/path?query=1',
            'https:///missing-host',
        ] as $value) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($value)), 'invalid station base URL must reject: ' . $value);
        }
    });
});

hub_test('cluster router permits explicit HTTP only for private literal stations', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_http_internal(function (): void {
            hub_test_assert(
                hub_cluster_validate_station_base_url('http://192.168.1.106/aihub') === 'http://192.168.1.106/aihub/',
                'explicit internal HTTP allowance must accept private LAN stations'
            );
            hub_test_assert(
                hub_cluster_validate_station_base_url('http://127.0.0.1/aihub') === 'http://127.0.0.1/aihub/',
                'explicit internal HTTP allowance must accept literal loopback IPs'
            );
            foreach (['http://203.0.113.10/aihub', 'http://169.254.169.254/aihub', 'http://localhost/aihub', 'http://station.example/aihub'] as $url) {
                hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($url)), 'internal HTTP allowance must reject non-private targets: ' . $url);
            }

            $db = hub_test_reset_db();
            $station = hub_cluster_import_pairing_link(
                $db,
                'http://192.168.1.106/cluster_pair.php#invite=' . str_repeat('e', 64),
                static fn (): array => ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)]
            );
            hub_test_assert((int)($station['id'] ?? 0) > 0, 'private HTTP pairing import must use the same station URL validation');
        });
    });
});

hub_test('cluster router rejects invalid explicit ports in station and pairing URLs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        foreach ([
            'https://station.example:0/aihub',
            'https://station.example:65536/aihub',
        ] as $url) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($url)), 'invalid station port must reject: ' . $url);
        }

        $db = hub_test_reset_db();
        $invite = str_repeat('d', 64);
        foreach ([0, 65536] as $port) {
            $requested = false;
            $rejected = hub_test_throws(function () use ($db, $port, $invite, &$requested): array {
                return hub_cluster_import_pairing_link($db, 'https://station.example:' . $port . '/cluster_pair.php#invite=' . $invite, static function () use (&$requested): array {
                    $requested = true;
                    return ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)];
                });
            });
            hub_test_assert($rejected && !$requested, 'invalid pairing port must reject before requesting: ' . $port);
        }
    });
});

hub_test('cluster router prefers an internal station base URL for requests', function (): void {
    hub_test_assert(
        hub_cluster_station_request_base_url([
            'public_base_url' => 'https://station.example/public/',
            'internal_base_url' => 'https://station.internal:8080/private/',
        ]) === 'https://station.internal:8080/private/',
        'internal station URL must be preferred for requests'
    );
    hub_test_assert(
        hub_cluster_station_request_base_url(['public_base_url' => 'https://station.example/public']) === 'https://station.example/public/',
        'public station URL must be used when internal URL is empty'
    );
});

hub_test('cluster router creates only hashed node pairing invitations', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '1');
    $before = time();
    $invitation = hub_cluster_create_pair_invitation($db);
    $after = time();

    hub_test_assert(preg_match('/^[a-f0-9]{64}$/', (string)($invitation['invite'] ?? '')) === 1, 'pair invitation must be 64 hex chars');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH') === hash('sha256', $invitation['invite']), 'only pair invitation hash must be stored');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_HASH') !== $invitation['invite'], 'raw invitation must not be stored');
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $invitation['expires_at'], 'pair invitation expiry must use the node setting');
    $expiresAt = strtotime((string)($invitation['expires_at'] ?? ''));
    hub_test_assert($expiresAt !== false && $expiresAt >= $before + 899 && $expiresAt <= $after + 901, 'pair invitation must expire in 15 minutes');

    hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '0');
    hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_create_pair_invitation($db)), 'disabled node must not create pair invitation');
});

hub_test('cluster router pairing import sends invites only in headers and saves encrypted station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_SITE_TITLE', str_repeat('Router ', 30));
        $invite = str_repeat('a', 64);
        $seenRequest = [];
        $station = hub_cluster_import_pairing_link(
            $db,
            'https://station.example:8443/cluster_pair.php#invite=' . $invite,
            static function (array $request) use (&$seenRequest): array {
                $seenRequest = $request;
                return [
                    'status' => 200,
                    'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR),
                ];
            }
        );

        hub_test_assert(($seenRequest['url'] ?? '') === 'https://station.example:8443/cluster_pair.php', 'pair requester URL must omit invite fragment and retain port');
        hub_test_assert(!str_contains((string)($seenRequest['url'] ?? ''), $invite), 'pair requester URL must not expose invite');
        hub_test_assert(!str_contains((string)($seenRequest['url'] ?? ''), '?'), 'pair requester URL must not contain a query');
        hub_test_assert(($seenRequest['headers']['X-3waAIHub-Pair-Invite'] ?? '') === $invite, 'pair requester must receive invite header');
        hub_test_assert(strlen((string)($seenRequest['headers']['X-3waAIHub-Router-Name'] ?? '')) <= 120, 'pair requester router name must be limited');
        hub_test_assert((int)($station['id'] ?? 0) > 0, 'pair import must return saved station');
        hub_test_assert(!array_key_exists('station_token', $station), 'pair import result must not expose station token');
        hub_test_assert(!str_contains(implode(' ', array_map('strval', $station)), '3wa_live_station_secret'), 'pair import result must not expose station token value');

        $raw = $db->query('SELECT token_ciphertext FROM cluster_stations WHERE id = ' . (int)$station['id'])->fetchColumn();
        hub_test_assert(!str_contains((string)$raw, '3wa_live_station_secret'), 'pair import must encrypt saved station token');
    });
});

hub_test('cluster router rejects malformed pairing links and invalid pairing responses', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        foreach ([
            'ftp://station.example/cluster_pair.php#invite=' . str_repeat('a', 64),
            'https://station.example/not_cluster_pair.php#invite=' . str_repeat('a', 64),
            'https://station.example/cluster_pair.php',
            'https://station.example/cluster_pair.php#invite=short',
        ] as $link) {
            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_import_pairing_link($db, $link)), 'malformed pairing link must reject');
        }
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_cluster_import_pairing_link(
                $db,
                'https://station.example/cluster_pair.php#invite=' . str_repeat('b', 64),
                static fn (): array => ['status' => 200, 'body' => '{}']
            )),
            'invalid pairing response must reject'
        );
    });
});

hub_test('cluster router rejects credential-bearing and query-bearing pairing links before requesting', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $invite = str_repeat('c', 64);
        foreach ([
            'https://router:secret@station.example/cluster_pair.php#invite=' . $invite,
            'https://station.example/cluster_pair.php?scope=local#invite=' . $invite,
            'https://station.example/cluster_pair.php?#invite=' . $invite,
        ] as $link) {
            $requested = false;
            $rejected = hub_test_throws(function () use ($db, $link, &$requested): array {
                return hub_cluster_import_pairing_link($db, $link, static function () use (&$requested): array {
                    $requested = true;
                    return ['status' => 200, 'body' => json_encode(hub_test_cluster_station_pairing(), JSON_THROW_ON_ERROR)];
                });
            });
            hub_test_assert($rejected && !$requested, 'credential-bearing or query-bearing pairing link must reject before requesting');
        }
    });
});

hub_test('cluster router selects the highest-priority healthy station', function (): void {
    $selected = hub_cluster_select_station('vision', [
        hub_test_cluster_station_fixture(['id' => 2, 'priority' => 5]),
        hub_test_cluster_station_fixture(['id' => 1, 'priority' => 10]),
    ]);

    hub_test_assert((int)($selected['id'] ?? 0) === 1, 'highest-priority healthy station must win');
});

hub_test('cluster router favors lower-priority unpressured stations over pressured preferred stations', function (): void {
    foreach ([
        ['gpu_free_vram_mb' => 0],
        ['active_gpu_leases' => 1],
        ['queued_jobs' => 1],
    ] as $pressure) {
        $selected = hub_cluster_select_station('vision', [
            hub_test_cluster_station_fixture(array_replace(['id' => 1, 'priority' => 10], $pressure)),
            hub_test_cluster_station_fixture(['id' => 2, 'priority' => 5]),
        ]);
        hub_test_assert((int)($selected['id'] ?? 0) === 2, 'unpressured station must outrank preferred pressured station');
    }
});

hub_test('cluster router falls back to priority ordering when every eligible station is pressured', function (): void {
    $selected = hub_cluster_select_station('vision', [
        hub_test_cluster_station_fixture(['id' => 3, 'priority' => 5, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
        hub_test_cluster_station_fixture(['id' => 2, 'priority' => 10, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
        hub_test_cluster_station_fixture(['id' => 1, 'priority' => 10, 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 1]),
    ]);

    hub_test_assert((int)($selected['id'] ?? 0) === 1, 'all-pressured eligible stations must fall back to priority then ID ordering');
});

hub_test('cluster router returns null when no station is eligible', function (): void {
    foreach ([
        hub_test_cluster_station_fixture(['enabled' => false]),
        hub_test_cluster_station_fixture(['fresh' => false]),
        hub_test_cluster_station_fixture(['modes' => ['tts']]),
    ] as $station) {
        hub_test_assert(hub_cluster_select_station('vision', [$station]) === null, 'disabled stale or unsupported station must be ineligible');
    }
});

hub_test('unpaired cluster child limits its token to status and selected modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');

        $configured = hub_cluster_node_configure($db, true, ['ocr', 'unchecked']);
        $tokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        sort($permissions);

        hub_test_assert(!empty($configured['enabled']), 'node must be enabled');
        hub_test_assert($permissions === ['cluster_status', 'ocr'], 'unpaired node token must include only cluster status and selected published modes');
        $plainToken = hub_cluster_node_reveal_token($db);
        hub_test_assert($plainToken !== '', 'admin reveal helper must return the token');
        hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', $plainToken, 'task_status')['ok']), 'unpaired node token must not authenticate native task followups');
        hub_test_assert(array_intersect($permissions, ['task_retry', 'task_artifacts_ack', 'task_artifact_retention']) === [], 'node token must never gain retry, ACK, or retention permissions');
        foreach (['AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT', 'AIHUB_CLUSTER_NODE_TOKEN_IV', 'AIHUB_CLUSTER_NODE_TOKEN_TAG'] as $key) {
            hub_test_assert(!str_contains(hub_get_storage_setting($db, $key), '3wa_live_'), 'node token storage must be encrypted');
        }
    });
});

hub_test('cluster child pairing retains only status and selected service permissions', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'ocr');
            $configured = hub_cluster_node_configure($db, true, ['ocr']);
            $oldTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
            $paired = hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

            hub_test_assert((string)$paired['station_token'] === hub_cluster_node_reveal_token($db), 'pairing must return the existing station token');
            hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router')), 'pair invitation must be one-time');
            $ipRules = hub_list_api_token_ip_rules($db, $oldTokenId);
            hub_test_assert(count($ipRules) === 1 && (string)$ipRules[0]['ip_rule'] === '203.0.113.44', 'paired token must bind to the caller IP');
            $pairedPermissions = array_column(hub_list_api_token_permissions($db, $oldTokenId), 'mode');
            sort($pairedPermissions);
            hub_test_assert($pairedPermissions === ['cluster_status', 'ocr'], 'paired node token must retain only cluster status and selected published modes');
            hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', (string)$paired['station_token'], 'task_status')['ok']), 'paired node token must not authenticate native task modes');

            hub_cluster_node_clear_pairing($db);
            $clearedPermissions = array_column(hub_list_api_token_permissions($db, $oldTokenId), 'mode');
            sort($clearedPermissions);
            hub_test_assert($clearedPermissions === ['cluster_status', 'ocr'], 'clearing a pairing must retain only base node permissions');
            $replacementInvite = hub_cluster_create_pair_invitation($db);
            hub_cluster_accept_pair_invitation($db, (string)$replacementInvite['invite'], '203.0.113.44', 'Primary Router');

            $regenerated = hub_cluster_node_regenerate_token($db);
            $newTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
            hub_test_assert($newTokenId !== $oldTokenId, 'regeneration must replace the station token');
            hub_test_assert((int)(hub_get_api_token($db, $oldTokenId)['enabled'] ?? 1) === 0, 'regeneration must revoke the old token');
            hub_test_assert(hub_list_api_token_permissions($db, $oldTokenId) === [], 'regeneration must remove the old token control-plane permissions');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') === '', 'regeneration must clear the paired router');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '', 'regeneration must clear the legacy invitation expiry');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $regenerated['expires_at'], 'regeneration must set the exact invitation expiry key');
            hub_test_assert((string)$regenerated['invite'] !== '', 'regeneration must issue a new invitation');
            $regeneratedPermissions = array_column(hub_list_api_token_permissions($db, $newTokenId), 'mode');
            sort($regeneratedPermissions);
            hub_test_assert($regeneratedPermissions === ['cluster_status', 'ocr'], 'regenerated unpaired node token must retain only base permissions');

            $regeneratedToken = hub_cluster_node_reveal_token($db);
            hub_cluster_node_configure($db, false, []);
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '' && hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === '', 'disabling must clear both invitation expiry keys');
            hub_test_assert(empty(hub_authenticate_api_token($db, '203.0.113.44', $regeneratedToken, 'task_status')['ok']), 'disabling must keep control-plane followups unavailable');
            hub_test_assert(hub_list_api_token_permissions($db, $newTokenId) === [], 'disabling must remove the active child token permissions');
        });
    });
});

hub_test('cluster child accepts a current legacy invitation expiry then clears both keys', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'ocr');
            $configured = hub_cluster_node_configure($db, true, ['ocr']);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', '');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT', $configured['expires_at']);
            hub_test_assert(hub_cluster_pair_invitation_expires_at($db) === $configured['expires_at'], 'current legacy invitation expiry must migrate to the exact key');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === $configured['expires_at'], 'legacy invitation expiry migration must persist the exact key');

            $paired = hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.45', 'Legacy Router');
            hub_test_assert((string)($paired['station_token'] ?? '') !== '', 'current legacy invitation expiry must remain pairable');
            hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT') === '' && hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === '', 'pair consumption must clear exact and legacy invitation expiry keys');
        });
    });
});

hub_test('cluster child followup requires the paired node token, source, and whitelist', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'vision');
            $configured = hub_cluster_node_configure($db, true, ['vision']);
            $nodeToken = hub_cluster_node_reveal_token($db);
            $nodeTokenId = hub_cluster_node_token_id($db);
            $nodeMemberId = (int)hub_get_api_token($db, $nodeTokenId)['member_id'];
            $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $nodeMemberId, 'owner_token_id' => $nodeTokenId]);
            $request = [
                'bearer_token' => $nodeToken,
                'client_ip' => '203.0.113.44',
                'method' => 'GET',
                'query' => ['mode' => 'task_status', 'task_id' => (string)$taskId],
            ];

            $native = hub_gateway_dispatch($db, 'task_status', null, $request);
            $unpaired = hub_cluster_child_followup_dispatch($db, $request);
            hub_test_assert($native['status'] === 403 && str_contains($native['body'], 'token_mode_not_allowed'), 'direct native task API must deny the node token');
            hub_test_assert($unpaired['status'] === 403, 'unpaired nodes must not use the child control plane');

            hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');
            $paired = hub_cluster_child_followup_dispatch($db, $request);
            $wrongSource = hub_cluster_child_followup_dispatch($db, array_replace($request, ['client_ip' => '203.0.113.45']));
            $otherToken = hub_test_cluster_router_customer_token($db, ['cluster_status']);
            $wrongToken = hub_cluster_child_followup_dispatch($db, array_replace($request, ['bearer_token' => (string)$otherToken['plain_token']]));
            $wrongMode = hub_cluster_child_followup_dispatch($db, array_replace_recursive($request, ['query' => ['mode' => 'task_retry']]));
            $wrongTask = hub_cluster_child_followup_dispatch($db, array_replace_recursive($request, ['query' => ['task_id' => 'not-a-task']]));

            $payload = json_decode($paired['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert($paired['status'] === 200 && ($payload['task_id'] ?? null) === $taskId, 'paired router peer must use the child control plane for its own task');
            hub_test_assert($wrongSource['status'] === 403 && $wrongToken['status'] === 403 && $wrongMode['status'] === 404 && $wrongTask['status'] === 400, 'child control plane must reject wrong source, token, operation, and task identifiers');

            hub_cluster_node_regenerate_token($db);
            $afterRegeneration = hub_cluster_child_followup_dispatch($db, $request);
            hub_test_assert($afterRegeneration['status'] === 403, 'regeneration must make the previous child control-plane credential unusable');
        });
    });
});

hub_test('cluster node reconciliation removes legacy task permissions and direct task control stays denied', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        $token = hub_cluster_node_reveal_token($db);
        $tokenId = hub_cluster_node_token_id($db);
        foreach (['task_status', 'task_result', 'task_log', 'task_cancel', 'artifact'] as $mode) {
            hub_add_api_token_mode_permission($db, $tokenId, $mode);
        }

        hub_migrate($db);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($permissions === ['cluster_status', 'vision'], 'migration reconciliation must remove all legacy node task-control permissions');

        hub_add_api_token_mode_permission($db, $tokenId, 'task_result');
        hub_ensure_default_storage_settings($db);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($permissions === ['cluster_status', 'vision'], 'startup reconciliation must remove later stale node task-control permissions');

        hub_add_api_token_mode_permission($db, $tokenId, 'task_status');
        $response = hub_gateway_dispatch($db, 'task_status', null, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'GET',
            'query' => ['task_id' => '1'],
        ]);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert($response['status'] === 403 && $permissions === ['cluster_status', 'vision'], 'node authentication must reconcile stale permissions before direct task control can run');
    });
});

hub_test('cluster node authentication removes unpublished selected modes before dispatch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        $token = hub_cluster_node_reveal_token($db);
        $tokenId = hub_cluster_node_token_id($db);
        hub_test_assert(!empty(hub_authenticate_api_token($db, '203.0.113.44', $token, 'vision')['ok']), 'published selected modes must authenticate for the node token');

        hub_test_cluster_publish_mode($db, 'vision', false);
        $auth = hub_authenticate_api_token($db, '203.0.113.44', $token, 'vision');
        $response = hub_gateway_dispatch($db, 'vision', null, [
            'bearer_token' => $token,
            'client_ip' => '203.0.113.44',
            'method' => 'POST',
            'raw_body' => '{}',
        ]);
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        hub_test_assert(empty($auth['ok']) && $response['status'] === 403 && $permissions === ['cluster_status'], 'node authentication must remove unavailable selected modes before dispatch');
    });
});

hub_test('cluster child result builds a bounded authoritative artifact index from task storage', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            $db = hub_test_reset_db();
            hub_test_cluster_publish_mode($db, 'vision');
            $configured = hub_cluster_node_configure($db, true, ['vision']);
            $token = hub_cluster_node_reveal_token($db);
            $tokenId = hub_cluster_node_token_id($db);
            $memberId = (int)hub_get_api_token($db, $tokenId)['member_id'];
            $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $memberId, 'owner_token_id' => $tokenId]);
            hub_finish_task_success($db, hub_get_task($db, $taskId), ['artifacts' => [['id' => 999]], 'metadata' => ['artifact_id' => 998]]);
            $artifact = $db->prepare(
                "INSERT INTO task_artifacts (task_id, name, path, mime_type, size_bytes, state, created_at)
                 VALUES (:task_id, :name, :path, 'application/octet-stream', :size_bytes, 'available', :created_at)"
            );
            for ($index = 1; $index <= 128; $index++) {
                $artifact->execute([
                    ':task_id' => $taskId,
                    ':name' => 'artifact_' . $index,
                    ':path' => '/not-served/' . $index,
                    ':size_bytes' => $index,
                    ':created_at' => hub_now(),
                ]);
            }
            hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

            $response = hub_cluster_child_followup_dispatch($db, [
                'bearer_token' => $token,
                'client_ip' => '203.0.113.44',
                'method' => 'GET',
                'query' => ['mode' => 'task_result', 'task_id' => (string)$taskId],
            ]);
            $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
            hub_test_assert($response['status'] === 200 && count($payload['cluster_artifact_index'] ?? []) === 128, 'child result must index every native task artifact up to its 128-item limit');
            hub_test_assert(($payload['cluster_artifact_index'][0]['id'] ?? null) === 1 && ($payload['cluster_artifact_index'][127]['id'] ?? null) === 128 && !str_contains($response['body'], '999') && !str_contains($response['body'], '998'), 'child result must ignore arbitrary stored result artifact fields');
        });
    });
});

hub_test('cluster child status stays lightweight and filters unavailable selected modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');
        hub_cluster_node_configure($db, true, ['ocr']);
        $db->exec("UPDATE runtime_resource_leases SET state = 'leased', lease_expires_at = '2999-01-01 00:00:00' WHERE resource_key = 'gpu:0'");
        $now = hub_now();
        $db->prepare('INSERT INTO tasks (task_type, status, created_at, updated_at) VALUES (:task_type, :status, :created_at, :updated_at)')
            ->execute([':task_type' => 'test', ':status' => 'queued', ':created_at' => $now, ':updated_at' => $now]);
        $db->prepare('INSERT INTO tasks (task_type, status, created_at, updated_at) VALUES (:task_type, :status, :created_at, :updated_at)')
            ->execute([':task_type' => 'test', ':status' => 'running', ':created_at' => $now, ':updated_at' => $now]);

        $payload = hub_cluster_status_payload($db);
        hub_test_assert(array_keys($payload) === ['ok', 'snapshot_at', 'gpu', 'active_gpu_leases', 'queued_jobs', 'running_jobs', 'modes'], 'status payload must keep its exact lightweight shape');
        hub_test_assert($payload['modes'] === ['ocr'], 'status payload must include selected running modes only');
        hub_test_assert($payload['active_gpu_leases'] === 1 && $payload['queued_jobs'] === 1 && $payload['running_jobs'] === 1, 'status payload counters must reflect current work');

        hub_test_cluster_publish_mode($db, 'ocr', false);
        hub_test_assert(hub_cluster_status_payload($db)['modes'] === [], 'status payload must omit stopped selected modes');
    });
});

hub_test('cluster inventory refresh fetches manifest then authenticated status without leaking station secrets', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        $requests = [];
        $snapshotAt = hub_now();
        $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use (&$requests, $snapshotAt): array {
            $requests[] = $request;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => $snapshotAt,
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['ocr'],
            ], JSON_THROW_ON_ERROR)];
        });

        hub_test_assert(count($requests) === 2 && str_ends_with((string)$requests[0]['url'], '/api_manifest.json.php') && str_ends_with((string)$requests[1]['url'], '/cluster_status.php'), 'refresh must fetch manifest before status');
        hub_test_assert(($requests[0]['headers'] ?? null) === [], 'manifest refresh must be authless');
        hub_test_assert(($requests[1]['headers'] ?? null) === ['Authorization' => 'Bearer 3wa_live_station_secret'], 'status refresh must use only the station token');
        hub_test_assert(!empty($refreshed['fresh']) && (string)($refreshed['last_error'] ?? '') === '', 'successful station refresh must be fresh');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'refreshed station result must not expose token');

        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null && hub_cluster_station_is_fresh($stored), 'freshness requires both stored snapshots');
        hub_test_assert($stored['status_fetched_at'] === $snapshotAt, 'status freshness must use the verified child snapshot time');
        $skippedRequests = 0;
        hub_cluster_refresh_station($db, $stored, static function () use (&$skippedRequests): array {
            $skippedRequests++;
            return ['status' => 500, 'body' => ''];
        });
        hub_test_assert($skippedRequests === 0, 'station refresh must not repeat within ten seconds');
        $stored['manifest_fetched_at'] = date('Y-m-d H:i:s', time() - 31);
        hub_test_assert(!hub_cluster_station_is_fresh($stored), 'stale manifest must make a station unavailable');
    });
});

hub_test('cluster inventory refresh reports malformed responses without raw response or token leaks', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        $refreshed = hub_cluster_refresh_station($db, $station, static fn (): array => ['status' => 200, 'body' => '{not-json 3wa_live_station_secret}']);

        hub_test_assert((string)($refreshed['last_error'] ?? '') === 'manifest_invalid', 'malformed manifest must have a compact error');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'refresh errors must not leak raw response or token');
    });
});

hub_test('cluster inventory normalizes small future skew and rejects invalid status snapshots', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $now = time();
        hub_test_assert(
            hub_cluster_verified_status_snapshot_at(date('Y-m-d H:i:s', $now + 1), $now) === date('Y-m-d H:i:s', $now),
            'a small future snapshot must normalize to the router receipt time'
        );
        foreach ([date('Y-m-d H:i:s', $now - 31), 'not-a-timestamp', date('Y-m-d H:i:s', $now + 300)] as $snapshotAt) {
            $db = hub_test_reset_db();
            $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
            $station = hub_cluster_get_station($db, $stationId);
            hub_test_assert($station !== null, 'paired station missing');
            $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use ($snapshotAt): array {
                if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                    return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
                }

                return ['status' => 200, 'body' => json_encode([
                    'ok' => true,
                    'snapshot_at' => $snapshotAt,
                    'gpu' => ['available' => true],
                    'active_gpu_leases' => 0,
                    'queued_jobs' => 0,
                    'running_jobs' => 0,
                    'modes' => ['ocr'],
                ], JSON_THROW_ON_ERROR)];
            });
            hub_test_assert((string)($refreshed['last_error'] ?? '') === 'status_invalid' && empty($refreshed['fresh']), 'invalid remote snapshot time must not become fresh');
        }
    });
});

hub_test('cluster inventory backs off failed partial refreshes but retries stale successful snapshots', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $stationId = hub_cluster_save_paired_station($db, hub_test_cluster_station_pairing());
        $station = hub_cluster_get_station($db, $stationId);
        hub_test_assert($station !== null, 'paired station missing');
        hub_cluster_refresh_station($db, $station, static function (array $request): array {
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => '{bad-status}'];
        });

        $requests = 0;
        $retry = static function (array $request) use (&$requests): array {
            $requests++;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => hub_now(),
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['ocr'],
            ], JSON_THROW_ON_ERROR)];
        };
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'partial station missing');
        $skipped = hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 0 && (string)$skipped['last_error'] === 'status_invalid', 'failed partial refresh must respect its ten-second attempt backoff');

        $db->prepare('UPDATE cluster_stations SET updated_at = :updated_at WHERE id = :id')
            ->execute([':updated_at' => date('Y-m-d H:i:s', time() - 11), ':id' => $stationId]);
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'backoff station missing');
        $requests = 0;
        $recovered = hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 2 && !empty($recovered['fresh']), 'partial refresh must retry once its failure backoff elapses');

        $db->prepare('UPDATE cluster_stations SET status_fetched_at = :status_fetched_at, updated_at = :updated_at WHERE id = :id')
            ->execute([':status_fetched_at' => date('Y-m-d H:i:s', time() - 11), ':updated_at' => hub_now(), ':id' => $stationId]);
        $requests = 0;
        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null, 'stale station missing');
        hub_cluster_refresh_station($db, $stored, $retry);
        hub_test_assert($requests === 2, 'stale successful snapshot must refresh even when updated_at is current');
    });
});

hub_test('cluster router dispatch returns 404 while routing is disabled', function (): void {
    $db = hub_test_reset_db();
    $refreshes = 0;

    $response = hub_cluster_dispatch($db, 'vision', [], [
        'refresh_due' => static function () use (&$refreshes): array {
            $refreshes++;
            return [];
        },
    ]);

    hub_test_assert($response['status'] === 404 && str_contains($response['body'], 'router_disabled'), 'disabled router must return a safe 404');
    hub_test_assert($refreshes === 0, 'disabled router must not refresh stations');
});

hub_test('cluster router dispatch uses strict customer authentication and mode permissions', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '0');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
    $token = hub_test_cluster_router_customer_token($db, []);
    $refreshes = 0;
    $seams = [
        'refresh_due' => static function () use (&$refreshes): array {
            $refreshes++;
            return [];
        },
    ];

    $missing = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request('', ['client_ip' => '127.0.0.1']), $seams);
    $denied = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), $seams);

    hub_test_assert($missing['status'] === 401 && str_contains($missing['body'], 'missing_token'), 'router must not use legacy anonymous or localhost authentication');
    hub_test_assert($denied['status'] === 403 && str_contains($denied['body'], 'token_mode_not_allowed'), 'router must require the customer token mode permission');
    hub_test_assert($refreshes === 0, 'unauthenticated requests must not refresh stations');
});

hub_test('cluster router dispatch refreshes then selects only a fresh eligible station', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $stale = hub_test_cluster_router_station($db, ['station_key' => 'stale_gpu', 'priority' => 99, 'station_token' => 'stale_station_token']);
        $fresh = hub_test_cluster_router_station($db, [
            'station_key' => 'fresh_gpu',
            'priority' => 1,
            'station_token' => 'fresh_station_token',
            'internal_base_url' => 'https://fresh.internal/aihub',
        ]);
        $refreshes = 0;
        $proxied = [];

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static function () use (&$refreshes, $stale, $fresh): array {
                $refreshes++;
                return [
                    hub_test_cluster_station_fixture(['id' => (int)$stale['id'], 'priority' => 99, 'fresh' => false]),
                    hub_test_cluster_station_fixture(['id' => (int)$fresh['id'], 'priority' => 1, 'station_key' => 'fresh_gpu']),
                ];
            },
            'transport' => static function (array $request) use (&$proxied): array {
                $proxied[] = $request;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ]);

        hub_test_assert($response['status'] === 200 && $refreshes === 1 && count($proxied) === 1, 'router must refresh before one eligible dispatch');
        hub_test_assert(($proxied[0]['headers']['Authorization'] ?? '') === 'Bearer fresh_station_token', 'router must select only the fresh eligible station');
        $route = $db->query('SELECT station_id, state FROM cluster_routes ORDER BY created_at DESC LIMIT 1')->fetch();
        hub_test_assert((int)($route['station_id'] ?? 0) === (int)$fresh['id'] && ($route['state'] ?? '') === 'completed', 'router must record the selected station without secrets');
    });
});

hub_test('cluster router dispatches a configured self station directly with its paired router IP', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_cluster_publish_mode($db, 'vision');
        $configured = hub_cluster_node_configure($db, true, ['vision']);
        hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '198.51.100.44', 'Primary Router');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_key' => 'self_station', 'station_token' => hub_cluster_node_reveal_token($db)]);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'self_station');
        $direct = 0;
        $http = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'self_station'])],
            'direct_dispatcher' => static function (PDO $db, string $mode, array $request) use (&$direct): array {
                $direct++;
                hub_test_assert(($request['bearer_token'] ?? '') === hub_cluster_node_reveal_token($db), 'self dispatch must use the selected station token');
                hub_test_assert(($request['client_ip'] ?? '') === '198.51.100.44', 'self dispatch must use the paired router IP, never the customer IP');
                return hub_gateway_json(200, ['ok' => true, 'mode' => $mode]);
            },
            'transport' => static function () use (&$http): array {
                $http++;
                return hub_gateway_error(500, 'unexpected_http', 'unexpected HTTP');
            },
        ]);

        hub_test_assert($response['status'] === 200 && $direct === 1 && $http === 0, 'configured self station must dispatch once in-process without HTTP');
        });
    });
});

hub_test('cluster router remote dispatch uses station auth safe headers and no redirects', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_key' => 'remote_gpu', 'station_token' => 'remote_station_token']);
        $proxied = [];
        $request = hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => [
                'Authorization' => 'Bearer customer_token',
                'Cookie' => 'session=customer',
                'Proxy-Authorization' => 'Basic customer',
                'X-Forwarded-For' => '203.0.113.99',
                'Forwarded' => 'for=203.0.113.99',
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ],
            'query' => ['task_id' => '42'],
        ]);

        $response = hub_cluster_dispatch($db, 'vision', $request, [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'remote_gpu'])],
            'transport' => static function (array $request) use (&$proxied): array {
                $proxied[] = $request;
                return [
                    'status' => 200,
                    'headers' => ['Content-Type: application/json', 'Set-Cookie: station=secret'],
                    'body' => '{"ok":true}',
                ];
            },
        ]);

        hub_test_assert($response['status'] === 200 && count($proxied) === 1, 'remote station must receive one dispatch');
        hub_test_assert(($proxied[0]['url'] ?? '') === 'https://station.internal:8080/aihub/api.php', 'remote target must be the validated station api endpoint');
        hub_test_assert(($proxied[0]['query'] ?? []) === ['task_id' => '42', 'mode' => 'vision'], 'remote target must receive only the fixed API query contract');
        hub_test_assert(($proxied[0]['headers'] ?? []) === [
            'Authorization' => 'Bearer remote_station_token',
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept' => 'application/json',
        ], 'remote request must use station auth and the narrow safe header set only');
        hub_test_assert(($proxied[0]['follow_redirects'] ?? true) === false, 'remote dispatch must forbid redirects');
        hub_test_assert(!str_contains(implode("\n", $response['headers']), 'Set-Cookie'), 'remote response headers must remain filtered');
    });
});

hub_test('cluster router rejects multipart and uploaded file proxy requests before dispatch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_key' => 'upload_gpu']);
        $transportCalls = 0;
        $seams = [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'upload_gpu'])],
            'transport' => static function () use (&$transportCalls): array {
                $transportCalls++;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ];

        $multipart = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=test'],
        ]), $seams);
        $uploaded = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token'], [
            'files' => ['source' => ['error' => UPLOAD_ERR_OK]],
        ]), $seams);

        hub_test_assert($multipart['status'] === 415 && $uploaded['status'] === 415, 'router must return a stable upload proxy rejection');
        hub_test_assert(str_contains($multipart['body'], 'router_upload_unsupported') && str_contains($uploaded['body'], 'router_upload_unsupported'), 'router upload rejection code mismatch');
        hub_test_assert($transportCalls === 0, 'unsupported uploads must not begin a dispatch');
    });
});

hub_test('cluster router does not retry another station after remote dispatch failure', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $token = hub_test_cluster_router_customer_token($db, ['vision']);
        $first = hub_test_cluster_router_station($db, ['station_key' => 'first_gpu', 'priority' => 9, 'station_token' => 'first_token']);
        $second = hub_test_cluster_router_station($db, ['station_key' => 'second_gpu', 'priority' => 1, 'station_token' => 'second_token']);
        $calls = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
            'refresh_due' => static fn (): array => [
                hub_test_cluster_station_fixture(['id' => (int)$first['id'], 'priority' => 9, 'station_key' => 'first_gpu']),
                hub_test_cluster_station_fixture(['id' => (int)$second['id'], 'priority' => 1, 'station_key' => 'second_gpu']),
            ],
            'transport' => static function () use (&$calls): array {
                $calls++;
                throw new RuntimeException('remote connection failed');
            },
        ]);

        hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_proxy_failed'), 'remote failures must return a generic router error');
        hub_test_assert($calls === 1, 'router must never retry a second station after dispatch begins');
    });
});

hub_test('cluster router atomically admits proxy capacity and releases it after completion', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'capacity_gpu']);
            $request = hub_test_cluster_router_request((string)$token['plain_token']);
            $baseSeams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'capacity_gpu'])],
            ];
            $nested = [];
            $outer = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                'transport' => static function () use (&$nested, $db, $request, $baseSeams): array {
                    $nested = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                        'transport' => static fn (): array => hub_gateway_json(200, ['ok' => true]),
                    ]);
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);
            $after = hub_cluster_dispatch($db, 'vision', $request, $baseSeams + [
                'transport' => static fn (): array => hub_gateway_json(200, ['ok' => true]),
            ]);

            hub_test_assert($outer['status'] === 200 && ($nested['status'] ?? 0) === 429 && str_contains((string)($nested['body'] ?? ''), 'router_busy'), 'active proxy admission must reject at capacity');
            hub_test_assert($after['status'] === 200, 'completed proxy admission must release capacity');
            hub_test_assert((int)$db->query("SELECT COUNT(*) FROM cluster_routes WHERE state = 'proxying'")->fetchColumn() === 0, 'proxy capacity rows must be terminal after dispatch');
        });
    });
});

hub_test('cluster router rejects oversized remote responses without forwarding a partial body', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'large_response_gpu']);

            $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$token['plain_token']), [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'large_response_gpu'])],
                'transport' => static fn (): array => ['status' => 200, 'headers' => ['Content-Type: application/json'], 'body' => str_repeat('x', 1024 * 1024 + 1)],
            ]);

            hub_test_assert($response['status'] === 502 && str_contains($response['body'], 'router_response_too_large'), 'oversized proxy response must have a stable 502');
            hub_test_assert(!str_contains($response['body'], str_repeat('x', 64)), 'oversized proxy response must not leak a partial downstream body');
        });
    });
});

hub_test('cluster router preserves only safe downstream content headers from captured responses', function (): void {
    foreach (['application/json; charset=utf-8', 'image/png', 'audio/mpeg'] as $mime) {
        $response = hub_cluster_router_proxy_response([
            'status' => 200,
            'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: {$mime}\r\nX-3waAIHub-Device: cuda\r\nSet-Cookie: station=session\r\nAuthorization: Bearer leaked\r\nConnection: close\r\nX-Forwarded-For: 203.0.113.1\r\n",
            'body' => 'safe-body',
        ], 'station_token');

        hub_test_assert($response['headers'][0] === 'Content-Type: ' . $mime, 'captured downstream MIME must be preserved');
        hub_test_assert(in_array('X-3waAIHub-Device: cuda', $response['headers'], true), 'allowlisted downstream API header must be preserved');
        hub_test_assert(!str_contains(implode("\n", $response['headers']), 'Cookie') && !str_contains(implode("\n", $response['headers']), 'Authorization') && !str_contains(implode("\n", $response['headers']), 'Forwarded'), 'unsafe downstream headers must be ignored');
    }

    $unsafe = hub_cluster_router_proxy_response([
        'status' => 200,
        'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: text/plain\x00bad\r\n",
        'body' => 'safe-body',
    ], 'station_token');
    hub_test_assert($unsafe['headers'][0] === 'Content-Type: application/octet-stream', 'invalid captured content types must fall back safely');
});

hub_test('cluster router reaps only expired proxy admissions before enforcing capacity', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS', '1', function (): void {
            $db = hub_test_reset_db();
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
            $token = hub_test_cluster_router_customer_token($db, ['vision']);
            $station = hub_test_cluster_router_station($db, ['station_key' => 'reaper_gpu']);
            $request = hub_test_cluster_router_request((string)$token['plain_token']);
            $seams = [
                'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'reaper_gpu'])],
            ];
            $insert = static function (string $routeId, string $updatedAt) use ($db, $station): void {
                $db->prepare(
                    "INSERT INTO cluster_routes (route_id, station_id, mode, is_async, state, created_at, updated_at)
                     VALUES (:route_id, :station_id, 'vision', 0, 'proxying', :created_at, :updated_at)"
                )->execute([
                    ':route_id' => $routeId,
                    ':station_id' => (int)$station['id'],
                    ':created_at' => $updatedAt,
                    ':updated_at' => $updatedAt,
                ]);
            };
            $staleAt = date('Y-m-d H:i:s', time() - hub_cluster_proxy_stale_after_seconds() - 1);
            $insert('route_stale_proxy', $staleAt);
            $calls = 0;
            $reaped = hub_cluster_dispatch($db, 'vision', $request, $seams + [
                'transport' => static function () use (&$calls): array {
                    $calls++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);

            hub_test_assert($reaped['status'] === 200 && $calls === 1, 'expired proxy rows must not consume new admission capacity');
            hub_test_assert((string)$db->query("SELECT state FROM cluster_routes WHERE route_id = 'route_stale_proxy'")->fetchColumn() === 'failed', 'expired proxy row must be terminalized during admission');

            $insert('route_fresh_proxy', hub_now());
            $fresh = hub_cluster_dispatch($db, 'vision', $request, $seams + [
                'transport' => static function () use (&$calls): array {
                    $calls++;
                    return hub_gateway_json(200, ['ok' => true]);
                },
            ]);
            hub_test_assert($fresh['status'] === 429 && $calls === 1, 'fresh proxy rows must retain capacity until completion or expiry');
        });
    });
});

hub_test('cluster router bounds declared and streamed request bodies', function (): void {
    hub_test_with_cluster_router_env('AIHUB_CLUSTER_ROUTER_MAX_REQUEST_MB', '1', function (): void {
        $limit = hub_cluster_proxy_request_limit_bytes();
        $declared = hub_cluster_router_normalize_request('vision', [
            'method' => 'POST',
            'headers' => ['Content-Type' => 'application/json'],
            'files' => [],
            'content_length' => (string)($limit + 1),
            'raw_body' => '',
            'query' => [],
        ]);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('cannot create request test stream');
        }
        fwrite($stream, str_repeat('x', $limit + 1));
        rewind($stream);
        try {
            $streamed = hub_cluster_router_normalize_request('vision', [
                'method' => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'files' => [],
                'content_length' => '',
                'body_stream' => $stream,
                'query' => [],
            ]);
        } finally {
            fclose($stream);
        }

        hub_test_assert(($declared['response']['status'] ?? 0) === 413 && str_contains((string)($declared['response']['body'] ?? ''), 'router_request_too_large'), 'oversized declared request bodies must fail before reading');
        hub_test_assert(($streamed['response']['status'] ?? 0) === 413 && str_contains((string)($streamed['response']['body'] ?? ''), 'router_request_too_large'), 'oversized unknown-length request streams must stop at the cap');
    });
});

hub_test('cluster router endpoint mode helper rejects nested query values', function (): void {
    hub_test_assert(hub_cluster_router_requested_mode('vision') === 'vision', 'scalar mode must pass through unchanged');
    hub_test_assert(hub_cluster_router_requested_mode('') === null && hub_cluster_router_requested_mode(['vision']) === null, 'empty or nested mode query values must reject without casting');
});

hub_test('cluster router rewrites async task responses without child details', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'async_remote',
            'station_token' => 'async_station_token',
        ]);
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = hub_cluster_router_admit_route($db, $station, [
            'member_id' => $memberId,
            'token_id' => (int)$customer['token_id'],
        ], 'vision', true);
        hub_test_assert(is_string($routeId), 'async route admission must succeed');

        $payload = hub_cluster_rewrite_async_response($db, [
            'route_id' => $routeId,
            'station_id' => (int)$station['id'],
        ], [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'status_url' => 'https://station.internal:8080/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result_url' => 'https://station.internal:8080/aihub/api.php?mode=task_result&task_id=remote_task_42',
            'log_url' => 'https://station.internal:8080/aihub/api.php?mode=task_log&task_id=remote_task_42',
            'cancel_url' => 'https://station.internal:8080/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
            'artifact_url_template' => 'https://station.internal:8080/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ], 'https://router.example/cluster_api.php');

        $links = hub_cluster_router_task_links($routeId, 'https://router.example/cluster_api.php');
        hub_test_assert($payload['task_id'] === $routeId && array_intersect_assoc($links, $payload) === $links, 'async response must expose only opaque router task links');
        hub_test_assert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'remote_task_42') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'station.internal'), 'async response must not leak child task or station details');
        $route = $db->query("SELECT remote_task_id, is_async, state FROM cluster_routes WHERE route_id = '" . $routeId . "'")->fetch();
        hub_test_assert($route === ['remote_task_id' => 'remote_task_42', 'is_async' => 1, 'state' => 'active'], 'async route must persist the remote task as active');
    });
});

hub_test('cluster router rewrites fake remote async dispatch responses', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_token' => 'initial_async_station_token']);

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id']])],
            'transport' => static function (array $request): array {
                hub_test_assert(($request['headers']['Authorization'] ?? '') === 'Bearer initial_async_station_token', 'initial async dispatch must use the selected station token');
                return hub_gateway_json(200, [
                    'ok' => true,
                    'task_id' => 'remote_task_42',
                    'status_url' => 'https://station.internal:8080/aihub/api.php?mode=task_status&task_id=remote_task_42',
                    'result_url' => 'https://station.internal:8080/aihub/api.php?mode=task_result&task_id=remote_task_42',
                    'log_url' => 'https://station.internal:8080/aihub/api.php?mode=task_log&task_id=remote_task_42',
                    'cancel_url' => 'https://station.internal:8080/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
                    'artifact_url_template' => 'https://station.internal:8080/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
                ]);
            },
        ]);

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $route = $db->query('SELECT route_id, remote_task_id, state FROM cluster_routes ORDER BY created_at DESC LIMIT 1')->fetch();
        hub_test_assert($response['status'] === 200 && $payload['task_id'] === $route['route_id'] && ($route['remote_task_id'] ?? '') === 'remote_task_42' && ($route['state'] ?? '') === 'active', 'initial remote async dispatch must persist and return an opaque active route');
        hub_test_assert(!str_contains($response['body'], 'remote_task_42') && !str_contains($response['body'], 'station.internal') && str_contains($payload['status_url'], 'cluster_api.php?mode=cluster_task_status&task_id='), 'initial remote async dispatch must expose only router links');
    });
});

hub_test('cluster router followups require the exact customer token before pinned dispatch', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'followup_station_token']);
        $other = hub_test_cluster_router_customer_token($db, []);
        $requests = [];

        $denied = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$other['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return hub_gateway_json(200, ['ok' => true]);
        });

        hub_test_assert($denied['status'] === 404 && str_contains($denied['body'], 'route_not_found') && $requests === [], 'other customer tokens must fail before transport');

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$requests): array {
            $requests[] = $request;
            return hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'status' => 'success']);
        });

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert($response['status'] === 200 && $payload['task_id'] === $fixture['route_id'], 'followups must bypass normal pack permissions and hide the remote task ID');
        hub_test_assert(count($requests) === 1 && $requests[0]['url'] === 'https://station.internal:8080/aihub/cluster_followup.php' && $requests[0]['query'] === ['mode' => 'task_status', 'task_id' => 'remote_task_42'], 'followups must use the pinned child control-plane operation');
        hub_test_assert(($requests[0]['headers']['Authorization'] ?? '') === 'Bearer followup_station_token' && !str_contains(implode("\n", $requests[0]['headers']), (string)$fixture['customer']['plain_token']), 'followups must send only the selected station token');
        hub_test_assert((string)$db->query("SELECT state FROM cluster_routes WHERE route_id = '" . $fixture['route_id'] . "'")->fetchColumn() === 'succeeded', 'terminal status must update the pinned route');
        hub_test_assert((int)$db->query("SELECT COUNT(*) FROM cluster_route_accesses WHERE route_id = '" . $fixture['route_id'] . "'")->fetchColumn() === 1, 'each dispatched followup must record exactly one access');
    });
});

hub_test('cluster router result maps artifacts and proxies only mapped artifacts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'artifact_station_token']);
        $requests = 0;
        $result = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, [
                'ok' => true,
                'task_id' => 'remote_task_42',
                'result' => [
                    'artifacts' => [['id' => 999]],
                    'metadata' => ['artifact_id' => 'attacker-controlled'],
                ],
                'cluster_artifact_index' => [['id' => 10, 'size_bytes' => 7], ['id' => 11, 'size_bytes' => 3]],
            ]);
        });

        hub_test_assert($result['status'] === 200 && $requests === 1, 'result followup must dispatch once');
        $resultPayload = json_decode($result['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(!isset($resultPayload['result']['artifacts'][0]['type']) && !isset($resultPayload['result']['artifacts'][0]['mime_type']), 'public result artifacts must omit child-controlled strings');
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY remote_artifact_id")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mapped === ['10', '11'], 'only native result artifact entries may become downloadable');

        $artifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '10'],
        ], static function (array $request) use (&$requests): array {
            $requests++;
            hub_test_assert($request['query'] === ['mode' => 'artifact', 'task_id' => 'remote_task_42', 'artifact_id' => '10'], 'artifact proxy must use the mapped remote task and artifact IDs only');
            return ['status' => 200, 'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: image/png\r\n", 'body' => 'png-data'];
        });

        $unknown = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => ['nested']],
        ], static function () use (&$requests): array {
            $requests++;
            return hub_gateway_json(200, ['ok' => true]);
        });

        hub_test_assert($artifact['status'] === 200 && $artifact['body'] === 'png-data' && ($artifact['headers'][0] ?? '') === 'Content-Type: image/png', 'known artifacts must preserve permitted proxy content types');
        hub_test_assert($unknown['status'] === 404 && str_contains($unknown['body'], 'artifact_not_found') && $requests === 2, 'unknown or nested artifact IDs must reject before dispatch');
    });
});

hub_test('cluster router preserves and maps native oversized result artifacts only after task identity matches', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'summary_artifact_station_token']);
        $summary = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'stored_as_artifact' => true,
                'artifact_id' => 17,
                'path' => '/private/task/remote_task_42.json',
                'bytes' => 4096,
            ],
            'cluster_artifact_index' => [['id' => 17, 'size_bytes' => 4096]],
        ]));

        $summaryPayload = json_decode($summary['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert($summary['status'] === 200 && ($summaryPayload['result'] ?? null) === ['stored_as_artifact' => true, 'artifact_id' => 17, 'bytes' => 4096], 'native oversized result summaries must retain only safe artifact fields');
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "'")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mapped === ['17'], 'native oversized result artifact must be authorized for the exact route');

        $artifact = hub_cluster_dispatch_followup($db, 'cluster_artifact', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id'], 'artifact_id' => '17'],
        ], static function (array $request): array {
            hub_test_assert($request['query'] === ['mode' => 'artifact', 'task_id' => 'remote_task_42', 'artifact_id' => '17'], 'oversized result artifact must use the pinned native task and artifact IDs');
            return ['status' => 200, 'raw_headers' => "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n", 'body' => '{}'];
        });
        hub_test_assert($artifact['status'] === 200, 'mapped oversized result artifact must proxy through the router');

        $mismatch = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'different_remote_task',
            'result' => ['stored_as_artifact' => true, 'artifact_id' => 99],
        ]));
        $mappedAfterMismatch = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY remote_artifact_id")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($mismatch['status'] === 502 && str_contains($mismatch['body'], 'router_response_invalid') && $mappedAfterMismatch === ['17'], 'mismatched native task IDs must not expose or map artifacts');
    });
});

hub_test('cluster router maps only the authoritative child artifact index up to 128 entries', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'authoritative_index_station_token']);
        $index = [];
        for ($id = 1; $id <= 128; $id++) {
            $index[] = ['id' => $id, 'size_bytes' => $id];
        }
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'artifacts' => [['id' => 999]],
                'metadata' => ['artifact_id' => 998],
            ],
            'cluster_artifact_index' => $index,
        ]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $mapped = $db->query("SELECT remote_artifact_id FROM cluster_route_artifacts WHERE route_id = '" . $fixture['route_id'] . "' ORDER BY CAST(remote_artifact_id AS INTEGER)")->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert($response['status'] === 200 && count($mapped) === 128 && $mapped[0] === '1' && $mapped[127] === '128', 'all 128 authoritative task artifacts must map for the pinned route');
        hub_test_assert(($payload['result']['artifacts'][0]['id'] ?? null) === 1 && !isset($payload['cluster_artifact_index']) && !str_contains($response['body'], '999') && !str_contains($response['body'], '998'), 'client results must ignore arbitrary stored result artifact fields and hide the control-plane index');
    });
});

hub_test('cluster router projects bounded sanitized native task logs', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'log_station_token']);
        $logs = [];
        for ($index = 0; $index < 105; $index++) {
            $logs[] = [
                'id' => $index + 1,
                'task_id' => 'remote_task_42',
                'level' => 'info',
                'message' => 'queued remote_task_42 at https://station.internal:8080/aihub/api.php?task_id=remote_task_42 ' . str_repeat('x', 1600),
                'created_at' => '2026-07-26 12:00:00',
                'unsafe' => 'discard me',
            ];
        }
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'logs' => $logs]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        $firstLog = $payload['logs'][0] ?? [];
        hub_test_assert($response['status'] === 200 && count($payload['logs'] ?? []) === 100 && array_keys($firstLog) === ['level', 'message', 'created_at'], 'native logs must retain only capped safe fields');
        hub_test_assert(str_starts_with((string)($firstLog['message'] ?? ''), 'queued ') && strlen((string)($firstLog['message'] ?? '')) <= 1024, 'safe log messages must arrive with a bounded length');
        hub_test_assert(!str_contains($response['body'], 'remote_task_42') && !str_contains($response['body'], 'station.internal') && !str_contains($response['body'], 'discard me'), 'log projection must redact remote task IDs, station links, and unsafe fields');

        $invalid = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, ['ok' => true, 'task_id' => 'remote_task_42', 'logs' => 'not-a-native-log-list']));
        hub_test_assert($invalid['status'] === 502 && str_contains($invalid['body'], 'router_response_invalid'), 'invalid native log shapes must not masquerade as empty logs');
    });
});

hub_test('cluster router redacts configured station origins including bare hosts and IPv6 authorities', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, [
            'public_base_url' => 'https://station.internal:8080/aihub',
            'internal_base_url' => 'https://[fd00:beef::1]:8080/aihub',
        ]);
        $message = 'bare station.internal dotted station.internal. default station.internal:443 full https://station.internal:8080/aihub/api.php ipv6 [fd00:beef::1]:8080 ipv6default [fd00:beef::1]:443 raw fd00:beef::1 full6 https://[fd00:beef::1]:8080/aihub/api.php';
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'logs' => [[
                'level' => 'info',
                'message' => $message,
                'created_at' => '2026-07-26 12:00:00',
            ]],
        ]));

        foreach (['https://station.internal:8080/aihub/api.php', 'station.internal', 'station.internal:443', 'station.internal.', '[fd00:beef::1]:8080', '[fd00:beef::1]:443', 'fd00:beef::1'] as $origin) {
            hub_test_assert(!str_contains($response['body'], $origin), 'public log projection must redact configured station authority form: ' . $origin);
        }
    });
});

hub_test('cluster station redaction terms include scheme defaults and sort longest first', function (): void {
    hub_test_with_cluster_http_internal(function (): void {
        $terms = hub_cluster_station_redaction_terms([
            'public_base_url' => 'https://station.internal/aihub',
            'internal_base_url' => 'http://192.168.1.25/aihub',
        ]);
        $lengths = array_map('strlen', $terms);
        $descending = $lengths;
        rsort($descending, SORT_NUMERIC);
        hub_test_assert(in_array('station.internal:443', $terms, true) && in_array('192.168.1.25:80', $terms, true) && $lengths === $descending, 'validated station bases must derive scheme-default authorities in longest-first order');
    });
});

hub_test('cluster router result projection discards configured station origins', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, [
            'public_base_url' => 'https://station.internal:8080/aihub',
            'internal_base_url' => 'https://[fd00:beef::1]:8080/aihub',
        ]);
        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => 'remote_task_42',
            'result' => [
                'message' => 'https://station.internal:8080/aihub station.internal:443 station.internal. [fd00:beef::1]:8080 [fd00:beef::1]:443 fd00:beef::1',
                'metadata' => ['origin' => 'station.internal'],
            ],
            'cluster_artifact_index' => [],
        ]));

        foreach (['station.internal', 'station.internal:443', 'station.internal.', '[fd00:beef::1]:8080', '[fd00:beef::1]:443', 'fd00:beef::1'] as $origin) {
            hub_test_assert($response['status'] === 200 && !str_contains($response['body'], $origin), 'public result projection must discard configured station authority form: ' . $origin);
        }
    });
});

hub_test('cluster child followup redacts native spool paths and bare station hosts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        hub_test_with_cluster_pair_url(function (): void {
            hub_test_with_cluster_router_env('AIHUB_CLUSTER_CANONICAL_HOST', 'station.internal', function (): void {
                $db = hub_test_reset_db();
                hub_test_cluster_publish_mode($db, 'vision');
                $configured = hub_cluster_node_configure($db, true, ['vision']);
                $token = hub_cluster_node_reveal_token($db);
                $memberId = (int)hub_get_api_token($db, hub_cluster_node_token_id($db))['member_id'];
                for ($index = 0; $index < 42; $index++) {
                    $taskId = hub_enqueue_task($db, 'demo_task', 'default', 0, [], null, null, ['owner_member_id' => $memberId, 'owner_token_id' => hub_cluster_node_token_id($db)]);
                }
                hub_test_assert($taskId === 42, 'test task must exercise the native task_42 spool path');
                $_SERVER['HTTP_HOST'] = 'station.internal:8080';
                $_SERVER['SERVER_NAME'] = 'station.internal';
                $_SERVER['SERVER_PORT'] = '8080';
                hub_add_task_log($db, $taskId, 'info', 'station.internal station.internal. station.internal:8080 config.json task.log release.v1 [fd00:beef::1]:443 fd00:beef::1. ::ffff:192.168.1.25. [face] [cab] remote task 42 ' . str_repeat('x', 4097));
                hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

                $response = hub_cluster_child_followup_dispatch($db, [
                    'bearer_token' => $token,
                    'client_ip' => '203.0.113.44',
                    'method' => 'GET',
                    'query' => ['mode' => 'task_log', 'task_id' => '42'],
                ]);
                $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
                hub_test_assert($response['status'] === 200 && !empty($payload['logs']), 'paired child control plane must return projected native logs');
                $projectedLogs = json_encode($payload['logs'], JSON_THROW_ON_ERROR);
                hub_test_assert(!str_contains($projectedLogs, '42') && !str_contains($projectedLogs, 'task_42.log') && !str_contains($projectedLogs, 'station.internal') && !str_contains($projectedLogs, '[fd00:beef::1]:443') && !str_contains($projectedLogs, 'fd00:beef::1') && !str_contains($projectedLogs, '::ffff:192.168.1.25') && !str_contains($projectedLogs, '192.168.1.25') && str_contains($projectedLogs, '[redacted-ipv6].') && str_contains($projectedLogs, '[face]') && str_contains($projectedLogs, '[cab]') && str_contains($projectedLogs, 'config.json') && str_contains($projectedLogs, 'task.log') && str_contains($projectedLogs, 'release.v1'), 'child logs must redact known local authorities and IPv6 without changing filenames, versions, or ordinary bracket text');
            });
        });
    });
});

hub_test('cluster child log terms redact only known local authorities', function (): void {
    $terms = hub_cluster_child_local_authority_terms([
        'HTTPS' => 'on',
        'HTTP_HOST' => 'config.json',
        'SERVER_NAME' => 'config.json',
        'SERVER_ADDR' => '192.0.2.10',
        'SERVER_PORT' => '443',
    ], 'node.example');
    $message = hub_cluster_redact_log_references('node.example station.internal config.json task.log release.v1', $terms, true);

    hub_test_assert(!str_contains($message, 'node.example') && str_contains($message, 'station.internal') && str_contains($message, 'config.json') && str_contains($message, 'task.log') && str_contains($message, 'release.v1'), 'host-derived values must not enter child authority terms unless they match a trusted local identity');
});

hub_test('cluster router emits relative links and allowlists initial async fields', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = hub_cluster_router_admit_route($db, $station, ['member_id' => $memberId, 'token_id' => (int)$customer['token_id']], 'vision', true);
        hub_test_assert(is_string($routeId), 'async route admission must succeed');
        $previous = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['SCRIPT_NAME'] = '/cluster_api.php';
        try {
            $routerBase = hub_cluster_router_api_base_url();
            $payload = hub_cluster_rewrite_async_response($db, ['route_id' => $routeId, 'station_id' => (int)$station['id']], [
                'ok' => true,
                'task_id' => '1',
                'status' => 'queued',
                'cached' => true,
                'cache_age_seconds' => 12,
                'cache_hit_task_id' => '1',
                'message' => 'task 1 at https://station.internal:8080/aihub',
            ], $routerBase);
        } finally {
            $_SERVER = $previous;
        }

        hub_test_assert($routerBase === 'cluster_api.php', 'router links must not derive a public base from Host headers');
        hub_test_assert(!array_key_exists('cache_hit_task_id', $payload) && !array_key_exists('message', $payload), 'initial async responses must discard arbitrary child fields');
        hub_test_assert(!str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'attacker.example') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'station.internal') && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), '"1"'), 'initial async responses must not leak child locations or IDs');
    });
});

hub_test('cluster router keeps artifact 10 intact when the remote task ID is 1', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'one_station_token']);
        $db->prepare('UPDATE cluster_routes SET remote_task_id = :task_id WHERE route_id = :route_id')->execute([':task_id' => '1', ':route_id' => $fixture['route_id']]);

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_result', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'task_id' => '1',
            'result' => ['artifacts' => [['id' => 999]]],
            'cluster_artifact_index' => [['id' => 10, 'size_bytes' => 1]],
        ]));

        $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        hub_test_assert(($payload['result']['artifacts'][0]['id'] ?? null) === 10 && !str_contains($response['body'], '"task_id":"1"'), 'opaque task rewriting must not mutate artifact ID 10');
    });
});

hub_test('cluster router sanitizes child error responses and recognizes timeouts', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $station = hub_test_cluster_router_station($db, ['station_token' => 'error_station_token']);
        $initial = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id']])],
            'transport' => static fn (): array => hub_gateway_json(500, ['error' => 'remote_task_secret https://station.internal:8080/aihub']),
        ]);
        $fixture = hub_test_cluster_router_async_route($db, ['station_token' => 'followup_error_station_token']);
        $followup = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(500, ['error' => 'remote_task_secret https://station.internal:8080/aihub']));
        $spoofed = hub_cluster_dispatch_followup($db, 'cluster_task_status', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static fn (): array => hub_gateway_json(500, ['error' => 'router_proxy_failed', 'detail' => 'https://station.internal:8080/aihub remote_task_secret']));

        hub_test_assert($initial['status'] === 502 && $followup['status'] === 502 && $spoofed['status'] === 502, 'child failures must become stable router failures');
        hub_test_assert(!str_contains($initial['body'], 'remote_task_secret') && !str_contains($followup['body'], 'station.internal') && !str_contains($spoofed['body'], 'station.internal'), 'child failure bodies must not leak remote details');
        hub_test_assert(hub_cluster_router_terminal_state('cluster_task_status', ['status' => 'timed_out']) === 'timed_out' && hub_cluster_router_terminal_state('cluster_task_status', ['status' => 'timeout']) === 'timed_out', 'native timeout states must be terminalized as timed out');
    });
});

hub_test('cluster router refuses self dispatch without a verified paired router IP', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_cluster_publish_mode($db, 'vision');
        hub_cluster_node_configure($db, true, ['vision']);
        hub_add_api_token_ip_rule($db, hub_cluster_node_token_id($db), '198.51.100.44', 'cluster router');
        $station = hub_test_cluster_router_station($db, ['station_key' => 'unpaired_self', 'station_token' => hub_cluster_node_reveal_token($db)]);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'unpaired_self');
        $customer = hub_test_cluster_router_customer_token($db, ['vision']);
        $direct = 0;

        $response = hub_cluster_dispatch($db, 'vision', hub_test_cluster_router_request((string)$customer['plain_token'], ['client_ip' => '203.0.113.10']), [
            'refresh_due' => static fn (): array => [hub_test_cluster_station_fixture(['id' => (int)$station['id'], 'station_key' => 'unpaired_self'])],
            'direct_dispatcher' => static function () use (&$direct): array {
                $direct++;
                return hub_gateway_json(200, ['ok' => true]);
            },
        ]);

        hub_test_assert($response['status'] === 503 && $direct === 0, 'self dispatch must fail closed without the full verified pairing identity');
    });
});

hub_test('cluster public manifest selects only fresh contracts and rewrites router endpoints', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fresh = hub_test_cluster_router_station($db, [
            'station_key' => 'public_ocr_station',
            'public_base_url' => 'https://configured.station.example/aihub',
            'internal_base_url' => 'https://configured.internal.example:8443/aihub',
            'station_token' => 'configured_station_secret',
        ]);
        $stale = hub_test_cluster_router_station($db, [
            'station_key' => 'public_tts_station',
            'public_base_url' => 'https://stale.station.example/aihub',
            'internal_base_url' => 'https://stale.internal.example:8443/aihub',
            'station_token' => 'stale_station_secret',
        ]);
        $contract = [
            'mode' => 'ocr',
            'method' => 'POST',
            'content_type' => 'application/json',
            'endpoint' => 'api.php?mode=ocr',
            'url' => 'https://configured.station.example/aihub/api.php?mode=ocr',
            'input_fields' => [['name' => '<script>', 'type' => 'string', 'required' => true]],
            'output_keys' => ['ok', 'text'],
            'error_codes' => ['bad_request'],
            'task_api' => [
                'status' => 'GET https://configured.station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
                'result' => 'GET https://configured.station.example/aihub/api.php?mode=task_result&task_id=remote_task_42',
                'log' => 'GET https://configured.station.example/aihub/api.php?mode=task_log&task_id=remote_task_42',
                'cancel' => 'POST https://configured.station.example/aihub/api.php?mode=task_cancel&task_id=remote_task_42',
                'artifact' => 'GET https://configured.station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
            ],
            'examples' => [
                'curl' => "curl 'https://configured.station.example/aihub/api.php?mode=ocr'",
                'php' => "curl_init('https://configured.station.example/aihub/api.php?mode=ocr');",
                'js_fetch' => "fetch('https://configured.station.example/aihub/api.php?mode=ocr');",
            ],
        ];
        $now = hub_now();
        $store = $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        );
        $store->execute([
            ':manifest_json' => json_encode(['modes' => ['ocr'], 'services' => [$contract]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode(['modes' => ['ocr'], 'gpu' => ['memory_free_mb' => 4096], 'active_gpu_leases' => 0, 'queued_jobs' => 0, 'running_jobs' => 0], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$fresh['id'],
        ]);
        $store->execute([
            ':manifest_json' => json_encode(['modes' => ['tts'], 'services' => [array_replace($contract, ['mode' => 'tts'])]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => date('Y-m-d H:i:s', time() - 31),
            ':status_json' => json_encode(['modes' => ['tts'], 'gpu' => ['memory_free_mb' => 4096], 'active_gpu_leases' => 0, 'queued_jobs' => 0, 'running_jobs' => 0], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => date('Y-m-d H:i:s', time() - 31),
            ':id' => (int)$stale['id'],
        ]);

        $manifest = hub_cluster_public_manifest($db);
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        $service = $manifest['services'][0] ?? [];
        $docs = hub_cluster_public_api_docs_html($db);

        hub_test_assert(array_column($manifest['services'], 'mode') === ['ocr'], 'only the fresh selected service may be public');
        hub_test_assert(($manifest['base_endpoint'] ?? '') === 'cluster_api.php' && str_contains((string)($manifest['inventory_note'] ?? ''), 'temporarily remove unavailable modes'), 'manifest must publish the Router base and inventory caveat');
        hub_test_assert(($service['endpoint'] ?? '') === 'cluster_api.php?mode=ocr' && str_contains((string)($service['examples']['curl'] ?? ''), 'cluster_api.php?mode=ocr'), 'all public service endpoints must use the Router');
        hub_test_assert(($service['task_api'] ?? []) === [
            'status' => 'GET cluster_api.php?mode=cluster_task_status&task_id={task_id}',
            'result' => 'GET cluster_api.php?mode=cluster_task_result&task_id={task_id}',
            'log' => 'GET cluster_api.php?mode=cluster_task_log&task_id={task_id}',
            'cancel' => 'POST cluster_api.php?mode=cluster_task_cancel&task_id={task_id}',
            'artifact' => 'GET cluster_api.php?mode=cluster_artifact&task_id={task_id}&artifact_id={artifact_id}',
        ], 'public async contracts must expose opaque Router followups');
        hub_test_assert(str_contains($docs, 'cluster_api.php?mode=ocr') && str_contains($docs, '&lt;script&gt;') && !str_contains($docs, '<script>'), 'public docs must render the same Router contract with escaped fields');
        foreach (['configured.station.example', 'configured.internal.example', 'stale.station.example', 'configured_station_secret', 'remote_task_42', 'mode=task_', '3wa_live_', 'token_ciphertext', 'token_iv', 'token_tag'] as $secret) {
            hub_test_assert(!str_contains($json, $secret), 'public manifest leaked station detail: ' . $secret);
        }
    });
});

hub_test('cluster public contract rewrite removes a selected station base from endpoints and examples', function (): void {
    $service = hub_cluster_rewrite_contract_endpoint([
        'endpoint' => 'api.php?mode=vision',
        'url' => 'https://station.example/aihub/api.php?mode=vision',
        'task_api' => [
            'status' => 'GET https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result' => 'GET api.php?mode=task_result&task_id={task_id}',
            'log' => 'GET https://station.example/aihub/api.php?mode=task_log&task_id={task_id}',
            'cancel' => 'POST api.php?mode=task_cancel&task_id={task_id}',
            'artifact' => 'GET https://station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'links' => [
            'status_url' => 'https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42',
            'result_url' => 'api.php?mode=task_result&task_id={task_id}',
            'log_url' => 'https://station.example/aihub/api.php?mode=task_log&task_id={task_id}',
            'cancel_url' => 'api.php?mode=task_cancel&task_id={task_id}',
            'artifact_url_template' => 'https://station.example/aihub/api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'examples' => ['curl' => "curl 'https://station.example/aihub/api.php?mode=task_status&task_id=remote_task_42'"],
    ], 'https://station.example/aihub/api.php', 'cluster_api.php');
    $json = json_encode($service, JSON_THROW_ON_ERROR);

    foreach ([
        'cluster_api.php?mode=cluster_task_status&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_result&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_log&task_id={task_id}',
        'cluster_api.php?mode=cluster_task_cancel&task_id={task_id}',
        'cluster_api.php?mode=cluster_artifact&task_id={task_id}&artifact_id={artifact_id}',
    ] as $endpoint) {
        hub_test_assert(str_contains($json, $endpoint), 'async contract must use the Router followup template: ' . $endpoint);
    }
    hub_test_assert(!str_contains($json, 'station.example') && !str_contains($json, 'remote_task_42') && !str_contains($json, 'mode=task_') && str_contains($json, 'cluster_api.php?mode=vision'), 'rewritten contracts must expose Router URLs only');
});

hub_test('cluster router followups never retry pinned stations and reserve private modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $fixture = hub_test_cluster_router_async_route($db, ['station_key' => 'pinned_station', 'station_token' => 'pinned_station_token']);
        hub_test_cluster_router_station($db, ['station_key' => 'unused_station', 'station_token' => 'unused_station_token', 'priority' => 99]);
        $calls = 0;

        $response = hub_cluster_dispatch_followup($db, 'cluster_task_log', [
            'bearer_token' => (string)$fixture['customer']['plain_token'],
            'client_ip' => '203.0.113.10',
            'query' => ['task_id' => $fixture['route_id']],
        ], static function (array $request) use (&$calls): array {
            $calls++;
            hub_test_assert($request['url'] === 'https://station.internal:8080/aihub/cluster_followup.php', 'followup must keep the original station control-plane endpoint');
            throw new RuntimeException('station unavailable');
        });

        foreach (['cluster_task_status', 'cluster_task_result', 'cluster_task_log', 'cluster_task_cancel', 'cluster_artifact'] as $mode) {
            hub_test_assert(hub_cluster_router_is_followup_mode($mode), 'reserved followup mode must bypass normal pack selection: ' . $mode);
        }
        hub_test_assert(!hub_cluster_router_is_followup_mode('vision'), 'normal pack modes must not use the private followup path');
        hub_test_assert($response['status'] === 503 && str_contains($response['body'], 'station_unavailable') && $calls === 1, 'pinned transport failures must return 503 without retrying another station');
    });
});

hub_test('cluster admin usage helpers count submit events and keep station presentation secret-free', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_cluster_router_station($db);
        $customer = hub_test_cluster_router_customer_token($db, []);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $now = hub_now();
        $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        )->execute([
            ':manifest_json' => json_encode(['modes' => ['vision', 'tts']], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode([
                'modes' => ['vision'],
                'gpu' => ['memory_free_mb' => 4096, 'memory_total_mb' => 8192],
                'active_gpu_leases' => 2,
                'queued_jobs' => 3,
                'running_jobs' => 4,
            ], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$station['id'],
        ]);
        $route = $db->prepare(
            'INSERT INTO cluster_routes
                (route_id, station_id, member_id, token_id, mode, state, created_at, updated_at, completed_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :state, :created_at, :updated_at, :completed_at)'
        );
        foreach ([
            ['route_admin_1', 'succeeded', '2026-01-01 10:00:00', '2026-01-01 11:00:00'],
            ['route_admin_2', 'failed', '2026-01-01 10:30:00', '2026-01-01 12:00:00'],
            ['route_admin_3', 'active', '2026-01-01 10:45:00', null],
        ] as [$routeId, $state, $createdAt, $completedAt]) {
            $route->execute([
                ':route_id' => $routeId,
                ':station_id' => (int)$station['id'],
                ':member_id' => $memberId,
                ':token_id' => (int)$customer['token_id'],
                ':mode' => 'vision',
                ':state' => $state,
                ':created_at' => $createdAt,
                ':updated_at' => $completedAt ?? $createdAt,
                ':completed_at' => $completedAt,
            ]);
        }
        $access = $db->prepare(
            'INSERT INTO cluster_route_accesses
                (route_id, station_id, member_id, token_id, mode, access_kind, status_code, ok, elapsed_ms, upload_bytes, response_bytes, created_at)
             VALUES
                (:route_id, :station_id, :member_id, :token_id, :mode, :access_kind, :status_code, :ok, 0, :upload_bytes, :response_bytes, :created_at)'
        );
        foreach ([
            ['route_admin_1', 'submit', 200, 1, 100, 200],
            ['route_admin_1', 'proxy', 200, 1, 0, 20],
            ['route_admin_2', 'submit', 500, 0, 50, 10],
            ['route_admin_3', 'submit', 202, 1, 25, 40],
        ] as [$routeId, $kind, $statusCode, $ok, $uploadBytes, $responseBytes]) {
            $access->execute([
                ':route_id' => $routeId,
                ':station_id' => (int)$station['id'],
                ':member_id' => $memberId,
                ':token_id' => (int)$customer['token_id'],
                ':mode' => 'vision',
                ':access_kind' => $kind,
                ':status_code' => $statusCode,
                ':ok' => $ok,
                ':upload_bytes' => $uploadBytes,
                ':response_bytes' => $responseBytes,
                ':created_at' => '2026-01-01 10:00:00',
            ]);
        }

        $filters = [
            'member_id' => $memberId,
            'token_id' => (int)$customer['token_id'],
            'station_id' => (int)$station['id'],
            'mode' => 'vision',
        ];
        $summary = hub_cluster_usage_summary($db, $filters);
        $rows = hub_cluster_usage_rows($db, $filters);
        $dashboard = hub_cluster_station_dashboard_rows($db);
        $recent = hub_cluster_recent_routes($db, $filters, 10);

        hub_test_assert($summary === [
            'work_requests' => 3,
            'accesses' => 4,
            'success_count' => 3,
            'failed_count' => 1,
            'active_routes' => 1,
            'peak_concurrency' => 3,
            'upload_bytes' => 175,
            'response_bytes' => 270,
        ], 'cluster usage summary must count submit work separately from all access events and sweep route lifetimes');
        hub_test_assert(count($rows) === 1 && (int)$rows[0]['work_requests'] === 3 && (int)$rows[0]['accesses'] === 4, 'cluster usage rows must group the selected member token and station events');
        hub_test_assert(count($recent) === 3 && !str_contains(json_encode($recent, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'recent routes must be presentation-safe');
        hub_test_assert(count($dashboard) === 1, 'station dashboard must include the paired station');
        hub_test_assert(!empty($dashboard[0]['token_configured']), 'station dashboard must expose only configured token state');
        hub_test_assert(!empty($dashboard[0]['fresh']), 'station dashboard must use cached freshness');
        hub_test_assert((int)$dashboard[0]['active_route_count'] === 1, 'station dashboard must count active Router routes');
        hub_test_assert(($dashboard[0]['mode_readiness'] ?? []) === [
            ['mode' => 'tts', 'ready' => false],
            ['mode' => 'vision', 'ready' => true],
        ], 'station dashboard must show manifest and status readiness per mode');
        hub_test_assert(!str_contains(json_encode($dashboard, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'station dashboard must never expose a decrypted station token');
        hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_usage_summary($db, ['station_id' => '1 OR 1=1'])), 'cluster usage filters must reject untrusted station values');
    });
});

hub_test('cluster admin child controls retain only published modes and force one station refresh', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'vision');
        $configured = hub_cluster_node_configure($db, true, ['vision', 'not_running']);
        $permissions = array_column(hub_list_api_token_permissions($db, hub_cluster_node_token_id($db)), 'mode');
        sort($permissions);
        hub_test_assert(($configured['modes'] ?? []) === ['vision'] && $permissions === ['cluster_status', 'vision'], 'child mode controls must keep only currently published modes plus managed status');

        $station = hub_test_cluster_router_station($db);
        $requests = [];
        hub_cluster_refresh_station_now($db, $station, true, static function (array $request) use (&$requests): array {
            $requests[] = $request;
            if (str_ends_with((string)$request['url'], '/api_manifest.json.php')) {
                return ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'vision']]], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 200, 'body' => json_encode([
                'ok' => true,
                'snapshot_at' => hub_now(),
                'gpu' => ['available' => true],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
                'modes' => ['vision'],
            ], JSON_THROW_ON_ERROR)];
        });
        hub_test_assert(count($requests) === 2, 'forced station refresh must fetch only the selected station inventory');
    });
});

hub_test('cluster admin page exposes guarded controls without station encryption internals', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');
    $layout = (string)file_get_contents(HUB_ROOT . '/admin/_layout.php');
    $members = (string)file_get_contents(HUB_ROOT . '/admin/api_members.php');
    $tokens = (string)file_get_contents(HUB_ROOT . '/admin/api_tokens.php');

    foreach (['hub_require_system_admin($db)', 'hub_check_csrf()', 'save_roles', 'save_child_modes', 'regenerate_node_token', 'renew_invitation', 'pair_child', 'toggle_station', 'refresh_station', '子入口節點', '統一入口', '子節點 Token', '新增子節點', 'cluster.php?view=usage'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'cluster admin page missing required control: ' . $needle);
    }
    foreach (['token_ciphertext', 'token_iv', 'token_tag', 'hub_cluster_station_token('] as $needle) {
        hub_test_assert(!str_contains($page, $needle), 'cluster admin page must not reference station token internals: ' . $needle);
    }
    hub_test_assert(str_contains($layout, 'cluster.php') && str_contains($layout, 'Cluster'), 'admin navigation must link to Cluster');
    hub_test_assert(str_contains($members, 'Cluster 用量') && str_contains($tokens, 'Cluster 用量'), 'member and token pages must link to filtered Cluster usage');
    hub_test_assert(str_contains($page, '$refreshed = hub_cluster_refresh_station_now') && str_contains($page, "!empty(\$refreshed['last_error']) || empty(\$refreshed['fresh'])"), 'cluster admin refresh must reject failed or stale inventory results');
    hub_test_assert(str_contains($page, 'hub_cluster_pair_invitation_is_current') && str_contains($page, "unset(\$_SESSION['hub_cluster_pair_invite'])"), 'cluster admin must clear stale invitation secrets before rendering a pairing link');
});

hub_test('cluster pairing invitation helper rejects replaced and expired secrets', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $initial = hub_cluster_node_configure($db, true, []);
        $replacement = hub_cluster_create_pair_invitation($db);

        hub_test_assert(!hub_cluster_pair_invitation_is_current($db, (string)$initial['invite']), 'replaced invitation secret must not remain current');
        hub_test_assert(hub_cluster_pair_invitation_is_current($db, (string)$replacement['invite']), 'current invitation secret must match the stored hash and expiry');
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT', date('Y-m-d H:i:s', time() - 1));
        hub_test_assert(!hub_cluster_pair_invitation_is_current($db, (string)$replacement['invite']), 'expired invitation secret must not remain current');
    });
});

hub_test('cluster admin pairing descriptor keeps cluster pair at the application root', function (): void {
    $previous = $_SERVER;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['HTTP_HOST'] = 'station.example';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/cluster.php';

    try {
        $db = hub_test_reset_db();
        $descriptor = hub_cluster_node_pairing_descriptor($db);
        hub_test_assert($descriptor['public_base_url'] === 'https://station.example/3waAIHub/', 'admin pairing links must resolve cluster_pair.php at the application root');
    } finally {
        $_SERVER = $previous;
    }
});
