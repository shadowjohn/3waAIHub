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
