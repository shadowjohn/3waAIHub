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
        'internal_base_url' => 'http://station.internal:8080/aihub',
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

hub_test('cluster router migration creates all persistence tables', function (): void {
    $db = hub_test_reset_db();
    $tables = array_fill_keys(
        $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN),
        true
    );

    foreach (['cluster_stations', 'cluster_routes', 'cluster_route_accesses', 'cluster_route_artifacts'] as $table) {
        hub_test_assert(isset($tables[$table]), 'cluster router table missing: ' . $table);
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
            'https://user:pass@station.example',
            'https://station.example/path#fragment',
            'https://station.example/path?query=1',
            'https:///missing-host',
        ] as $value) {
            hub_test_assert(hub_test_throws(static fn (): string => hub_cluster_validate_station_base_url($value)), 'invalid station base URL must reject: ' . $value);
        }
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
            'internal_base_url' => 'http://station.internal:8080/private/',
        ]) === 'http://station.internal:8080/private/',
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
    hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_PAIR_EXPIRES_AT') === $invitation['expires_at'], 'pair invitation expiry must use the node setting');
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

hub_test('cluster child config encrypts its dedicated token and limits permissions to selected modes', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');

        $configured = hub_cluster_node_configure($db, true, ['ocr', 'unchecked']);
        $tokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
        $permissions = array_column(hub_list_api_token_permissions($db, $tokenId), 'mode');
        sort($permissions);

        hub_test_assert(!empty($configured['enabled']), 'node must be enabled');
        hub_test_assert($permissions === ['cluster_status', 'ocr'], 'node token must only include cluster status and selected published modes');
        hub_test_assert(hub_cluster_node_reveal_token($db) !== '', 'admin reveal helper must return the token');
        foreach (['AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT', 'AIHUB_CLUSTER_NODE_TOKEN_IV', 'AIHUB_CLUSTER_NODE_TOKEN_TAG'] as $key) {
            hub_test_assert(!str_contains(hub_get_storage_setting($db, $key), '3wa_live_'), 'node token storage must be encrypted');
        }
    });
});

hub_test('cluster child pairing consumes one invitation binds the router IP and regeneration revokes prior access', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_test_cluster_publish_mode($db, 'ocr');
        $configured = hub_cluster_node_configure($db, true, ['ocr']);
        $oldTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
        $paired = hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router');

        hub_test_assert((string)$paired['station_token'] === hub_cluster_node_reveal_token($db), 'pairing must return the existing station token');
        hub_test_assert(hub_test_throws(static fn (): array => hub_cluster_accept_pair_invitation($db, (string)$configured['invite'], '203.0.113.44', 'Primary Router')), 'pair invitation must be one-time');
        $ipRules = hub_list_api_token_ip_rules($db, $oldTokenId);
        hub_test_assert(count($ipRules) === 1 && (string)$ipRules[0]['ip_rule'] === '203.0.113.44', 'paired token must bind to the caller IP');

        $regenerated = hub_cluster_node_regenerate_token($db);
        $newTokenId = (int)hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID');
        hub_test_assert($newTokenId !== $oldTokenId, 'regeneration must replace the station token');
        hub_test_assert((int)(hub_get_api_token($db, $oldTokenId)['enabled'] ?? 1) === 0, 'regeneration must revoke the old token');
        hub_test_assert(hub_get_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME') === '', 'regeneration must clear the paired router');
        hub_test_assert((string)$regenerated['invite'] !== '', 'regeneration must issue a new invitation');
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
        $refreshed = hub_cluster_refresh_station($db, $station, static function (array $request) use (&$requests): array {
            $requests[] = $request;
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
        });

        hub_test_assert(count($requests) === 2 && str_ends_with((string)$requests[0]['url'], '/api_manifest.json.php') && str_ends_with((string)$requests[1]['url'], '/cluster_status.php'), 'refresh must fetch manifest before status');
        hub_test_assert(($requests[0]['headers'] ?? null) === [], 'manifest refresh must be authless');
        hub_test_assert(($requests[1]['headers'] ?? null) === ['Authorization' => 'Bearer 3wa_live_station_secret'], 'status refresh must use only the station token');
        hub_test_assert(!empty($refreshed['fresh']) && (string)($refreshed['last_error'] ?? '') === '', 'successful station refresh must be fresh');
        hub_test_assert(!str_contains(json_encode($refreshed, JSON_THROW_ON_ERROR), '3wa_live_station_secret'), 'refreshed station result must not expose token');

        $stored = hub_cluster_get_station($db, $stationId);
        hub_test_assert($stored !== null && hub_cluster_station_is_fresh($stored), 'freshness requires both stored snapshots');
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
