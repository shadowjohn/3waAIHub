<?php
declare(strict_types=1);

hub_test('date build IDs format for machine and UI use', function (): void {
    hub_test_assert(HUB_VERSION === '20260729001', 'development build ID mismatch');
    hub_test_assert(HUB_RELEASE_LABEL === '8/7 Admin Market + Cluster Dashboard Preview', 'release label mismatch');
    hub_test_assert(hub_release_display_version(HUB_VERSION) === '2026.07.29.001', 'display release format mismatch');
    hub_test_assert(hub_release_display_version('bad') === 'bad', 'invalid release must remain inspectable');
});

hub_test('local Git report uses only bounded command arrays', function (): void {
    $calls = [];
    $report = hub_release_local_git_report(static function (array $command) use (&$calls): array {
        $calls[] = $command;
        $tail = array_slice($command, 3);
        $stdout = match ($tail) {
            ['rev-parse', '--short=12', 'HEAD'] => "abcdef123456\n",
            ['status', '--porcelain', '--untracked-files=no'] => " M README.md\n",
            ['tag', '--points-at', 'HEAD'] => "preview\n20260729001\n20260807001\n",
            default => '',
        };

        return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
    });

    hub_test_assert($calls === [
        ['git', '-C', HUB_ROOT, 'rev-parse', '--short=12', 'HEAD'],
        ['git', '-C', HUB_ROOT, 'status', '--porcelain', '--untracked-files=no'],
        ['git', '-C', HUB_ROOT, 'tag', '--points-at', 'HEAD'],
    ], 'local Git report command contract mismatch');
    hub_test_assert($report['commit'] === 'abcdef123456', 'local commit mismatch');
    hub_test_assert($report['dirty'] === true, 'tracked dirty state mismatch');
    hub_test_assert($report['tag'] === '20260807001', 'greatest release tag mismatch');

    $source = (string)file_get_contents(HUB_ROOT . '/app/release.php');
    hub_test_assert(str_contains($source, 'GIT_OPTIONAL_LOCKS'), 'read-only Git probes must disable optional index locks');
});

hub_test('failed local Git status remains unknown', function (): void {
    $report = hub_release_local_git_report(static fn (array $command): array => [
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => 'credential=https://user:password@example.test',
        'output' => 'sensitive output',
    ]);

    hub_test_assert($report['commit'] === '', 'failed Git commit must stay empty');
    hub_test_assert($report['dirty'] === null, 'failed Git status must not look clean');
    hub_test_assert($report['tag'] === '', 'failed Git tag must stay empty');
    hub_test_assert(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'password'), 'Git report leaked command output');
});

hub_test('release inventory keeps latest runner evidence per Pack', function (): void {
    $db = hub_test_reset_db();
    $insert = $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, pack_version, runner_version, image_name, image_digest, state, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :pack_version, :runner_version, :image_name, :image_digest, :state, :started_at, :created_at)'
    );
    foreach ([
        ['old', 'hello', 'hello', '0.1.0', '1', 'hello:old', 'sha256:old', 'succeeded', '2026-07-29 10:00:00'],
        ['new', 'hello', 'hello', '0.2.0', '2', 'hello:new', 'sha256:new', 'succeeded', '2026-07-29 11:00:00'],
        ['ocr', 'ocr-ppocrv5', 'ocr', '5.0.0', '1', 'ocr:gpu', '', 'failed', '2026-07-29 12:00:00'],
    ] as [$runId, $packId, $task, $packVersion, $runnerVersion, $image, $digest, $state, $createdAt]) {
        $insert->execute([
            ':run_id' => 'release_' . $runId,
            ':pack_id' => $packId,
            ':task' => $task,
            ':pack_version' => $packVersion,
            ':runner_version' => $runnerVersion,
            ':image_name' => $image,
            ':image_digest' => $digest,
            ':state' => $state,
            ':started_at' => $createdAt,
            ':created_at' => $createdAt,
        ]);
    }

    $runners = hub_release_runner_inventory($db);
    hub_test_assert(($runners['hello']['image'] ?? '') === 'hello:new', 'latest runner image mismatch');
    hub_test_assert(($runners['hello']['digest'] ?? '') === 'sha256:new', 'latest runner digest mismatch');
    hub_test_assert(($runners['hello']['pack_version'] ?? '') === '0.2.0', 'latest runner Pack version mismatch');
    hub_test_assert(($runners['ocr-ppocrv5']['image'] ?? '') === 'ocr:gpu', 'second Pack runner missing');

    $report = hub_release_node_report($db, static fn (array $command): array => [
        'exit_code' => 0,
        'stdout' => array_slice($command, 3) === ['rev-parse', '--short=12', 'HEAD'] ? 'abcdef123456' : '',
        'stderr' => '',
        'output' => '',
    ]);
    hub_test_assert(array_keys($report) === ['git', 'packs', 'runners', 'health'], 'node report shape mismatch');
    hub_test_assert(($report['git']['build_id'] ?? '') === HUB_VERSION, 'node build ID missing');
    hub_test_assert(isset($report['packs']['hello']), 'Pack inventory missing hello');
    hub_test_assert(($report['health']['status'] ?? '') !== '', 'health summary missing');
});

hub_test('remote release discovery accepts only immutable date build refs', function (): void {
    $output = implode("\n", [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa refs/tags/20260729001',
        'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb refs/tags/20260807001',
        'cccccccccccccccccccccccccccccccccccccccc refs/tags/20260807001^{}',
        'dddddddddddddddddddddddddddddddddddddddd refs/tags/v20260901001',
        'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee refs/heads/20269999001',
        'malformed refs/tags/99999999999',
    ]);

    hub_test_assert(hub_release_latest_remote_tag($output) === '20260807001', 'remote tag parser accepted an invalid ref');
    hub_test_assert(hub_release_latest_remote_tag('secret=https://user:pass@example.test/repo.git') === '', 'remote parser must ignore arbitrary output');
});

hub_test('remote release cache is atomic compact and credential free', function (): void {
    hub_test_clear_data_root();
    $cachePath = HUB_DATA_DIR . '/cache/release_remote.json';
    $result = hub_release_check_remote(
        static fn (array $command): array => [
            'exit_code' => 1,
            'stdout' => 'https://user:password@example.test/private.git',
            'stderr' => 'fatal: credential rejected',
            'output' => 'remote origin https://user:password@example.test/private.git',
        ],
        $cachePath,
        static fn (): string => '2026-07-29 12:00:00'
    );

    hub_test_assert($result === [
        'checked_at' => '2026-07-29 12:00:00',
        'latest_release' => '',
        'error' => 'remote_unavailable',
    ], 'remote failure cache shape mismatch');
    $stored = (string)file_get_contents($cachePath);
    hub_test_assert(!str_contains($stored, 'password') && !str_contains($stored, 'example.test'), 'cache leaked remote output');
    hub_test_assert((fileperms($cachePath) & 0777) === 0664, 'release cache mode must be 0664');
    hub_test_assert(glob($cachePath . '.tmp.*') === [], 'atomic cache temp file leaked');
});

hub_test('local release cache lets an unprivileged web runtime read CLI Git identity', function (): void {
    hub_test_clear_data_root();
    $cachePath = HUB_DATA_DIR . '/cache/release_local.json';
    hub_release_write_local_cache([
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'label' => HUB_RELEASE_LABEL,
        'commit' => 'abcdef123456',
        'dirty' => false,
        'tag' => '20260729001',
    ], $cachePath);

    $report = hub_release_local_git_report(static fn (array $command): array => [
        'exit_code' => 1,
        'stdout' => '',
        'stderr' => 'permission denied',
        'output' => '',
    ], $cachePath);
    hub_test_assert($report['commit'] === 'abcdef123456', 'web Git failure must fall back to the CLI snapshot');
    hub_test_assert($report['dirty'] === false, 'cached dirty state mismatch');
    hub_test_assert($report['tag'] === '20260729001', 'cached release tag mismatch');
    hub_test_assert($report['source'] === 'cli_snapshot', 'cached release source mismatch');
    hub_test_assert($report['snapshot_at'] !== '', 'cached release snapshot time missing');
    hub_test_assert((fileperms($cachePath) & 0777) === 0664, 'local release cache mode must be 0664');

    hub_release_write_local_cache([
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'label' => HUB_RELEASE_LABEL,
        'commit' => 'fedcba654321',
        'dirty' => false,
        'tag' => '',
    ], $cachePath, static fn (): string => '2020-01-01 00:00:00');
    $stale = hub_release_read_local_cache($cachePath);
    hub_test_assert($stale['commit'] === '', 'stale CLI snapshots must become unknown');
    hub_test_assert($stale['source'] === 'unknown', 'stale CLI snapshot source must become unknown');

    hub_release_write_local_cache([
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'label' => HUB_RELEASE_LABEL,
        'commit' => 'fedcba654321',
        'dirty' => false,
        'tag' => '',
    ], $cachePath, static fn (): string => date('Y-m-d H:i:60'));
    $invalidTime = hub_release_read_local_cache($cachePath);
    hub_test_assert($invalidTime['commit'] === '', 'impossible CLI snapshot times must be rejected');
});

hub_test('release update guidance preserves authority roles', function (): void {
    $linux = hub_release_update_commands('linux');
    $windows = hub_release_update_commands('windows');

    hub_test_assert(str_contains($linux['integration_host']['commands'], 'git fetch origin'), 'Linux integration host fetch missing');
    hub_test_assert(str_contains($linux['integration_host']['commands'], 'git merge --ff-only origin/main'), 'Linux fast-forward guidance missing');
    hub_test_assert(str_contains($linux['execution_node']['commands'], 'git fetch --tags origin'), 'Linux execution node tag fetch missing');
    hub_test_assert(str_contains($linux['execution_node']['commands'], 'git checkout --detach RELEASE_ID'), 'Linux immutable checkout missing');
    hub_test_assert(str_contains($windows['integration_host']['commands'], 'Set-Location -LiteralPath'), 'Windows PowerShell location missing');
    hub_test_assert($linux['authority']['push'] === '3wa', '3wa must remain normal push authority');
    hub_test_assert($linux['authority']['execution_nodes'] === '5090 / 1080: fetch or immutable tag checkout; never push', 'execution-node authority mismatch');
    hub_test_assert($linux['authority']['wsl'] === 'authoring / validation only; not deployment authority', 'WSL authority mismatch');
});

hub_test('old Cluster stations degrade safely without release fields', function (): void {
    $local = [
        'git' => ['build_id' => HUB_VERSION, 'commit' => 'abcdef123456', 'dirty' => false, 'tag' => ''],
        'packs' => ['hello' => '0.1.0'],
        'runners' => [],
        'health' => ['status' => 'ok'],
    ];
    $old = hub_release_station_report([
        'display_name' => 'Old node',
        'status_json' => json_encode(['ok' => true, 'modes' => ['hello']], JSON_THROW_ON_ERROR),
    ], $local);

    hub_test_assert($old['known'] === false, 'old station release must remain unknown');
    hub_test_assert($old['update_needed'] === null, 'old station must not invent update state');
    hub_test_assert($old['pack_compatible'] === null, 'old station must not invent Pack compatibility');
    hub_test_assert($old['health'] === 'unknown', 'old station health must degrade to unknown');
    hub_test_assert($old['node_type'] === 'child', 'paired legacy station must remain a child node');

    $newer = hub_release_station_report([
        'display_name' => 'Newer node',
        'status_json' => json_encode([
            'release' => ['build_id' => '20260807001', 'commit' => 'fedcba654321', 'dirty' => false],
            'packs' => ['hello' => '0.1.0'],
            'health' => ['status' => 'ok'],
        ], JSON_THROW_ON_ERROR),
    ], $local);
    hub_test_assert($newer['update_needed'] === true, 'any station build mismatch must require alignment');
    hub_test_assert($newer['pack_compatible'] === true, 'matching station Pack inventory must be compatible');

    $differentCommit = hub_release_station_report([
        'display_name' => 'Different commit',
        'status_json' => json_encode([
            'release' => [
                'build_id' => HUB_VERSION,
                'commit' => 'fedcba654321',
                'dirty' => false,
                'tag' => '',
            ],
            'packs' => ['hello' => '0.1.0'],
            'health' => ['status' => 'ok'],
        ], JSON_THROW_ON_ERROR),
    ], $local);
    hub_test_assert($differentCommit['update_needed'] === true, 'same build with a different commit must require alignment');

    $sameCommit = hub_release_station_report([
        'display_name' => 'Aligned commit',
        'status_json' => json_encode([
            'release' => [
                'build_id' => HUB_VERSION,
                'commit' => 'abcdef123456',
                'dirty' => false,
                'tag' => '',
            ],
            'packs' => ['hello' => '0.1.0'],
            'health' => ['status' => 'ok'],
        ], JSON_THROW_ON_ERROR),
    ], $local);
    hub_test_assert($sameCommit['update_needed'] === false, 'same build and commit should be aligned');
    hub_test_assert(hub_release_status_label('ok') === '正常', 'release status label mismatch');
    hub_test_assert(hub_release_status_label('unknown') === '未知', 'unknown release label mismatch');
});

hub_test('Cluster station release reports classify declared node roles', function (): void {
    $local = ['git' => [], 'packs' => [], 'runners' => [], 'health' => ['status' => 'ok']];
    $base = [
        'display_name' => 'Cluster node',
        'status_json' => json_encode([
            'release' => ['build_id' => HUB_VERSION, 'commit' => '', 'dirty' => false, 'tag' => ''],
            'packs' => [],
            'health' => ['status' => 'ok'],
        ], JSON_THROW_ON_ERROR),
    ];

    foreach ([
        ['aggregate' => true, 'children_count' => 2, 'expected' => 'aggregate'],
        ['aggregate' => false, 'children_count' => 2, 'expected' => 'parent'],
        ['aggregate' => false, 'children_count' => 0, 'expected' => 'child'],
    ] as $case) {
        $status = json_decode($base['status_json'], true, 32, JSON_THROW_ON_ERROR);
        $status['cluster'] = [
            'aggregate' => $case['aggregate'],
            'children_count' => $case['children_count'],
            'published_mode_count' => 0,
        ];
        $base['status_json'] = json_encode($status, JSON_THROW_ON_ERROR);
        $report = hub_release_station_report($base, $local);
        hub_test_assert($report['node_type'] === $case['expected'], 'Cluster node type mismatch: ' . $case['expected']);
    }
});

hub_test('self Cluster station uses its local report when the stored snapshot is legacy', function (): void {
    $local = [
        'git' => ['build_id' => HUB_VERSION, 'commit' => 'abcdef123456', 'dirty' => false, 'tag' => ''],
        'packs' => ['hello' => '0.1.0'],
        'runners' => [],
        'health' => ['status' => 'ok'],
    ];
    $report = hub_release_station_report([
        'display_name' => 'GIS集團-AI服務平台',
        'is_self' => true,
        'self_router_enabled' => true,
        'self_node_enabled' => true,
        'self_children_count' => 3,
        'status_json' => json_encode(['ok' => true, 'modes' => ['hello']], JSON_THROW_ON_ERROR),
    ], $local);

    hub_test_assert($report['display_version'] === hub_release_display_version(HUB_VERSION), 'self station must use the local version');
    hub_test_assert($report['node_type'] === 'aggregate', 'self aggregate station role mismatch');
    hub_test_assert($report['health'] === 'ok' && $report['pack_count'] === 1, 'self station local health and Pack count mismatch');
    hub_test_assert($report['update_needed'] === false, 'self station must already be aligned');
});

hub_test('Environment version page renders a concise Cluster inventory', function (): void {
    $environment = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');

    foreach (['Git commit', '版本資料來源', '目前 Tag', '遠端最新版本', 'Pack / Runner Digest'] as $hidden) {
        hub_test_assert(!str_contains($environment, "__('{$hidden}')"), 'Environment must hide: ' . $hidden);
    }
    foreach (['項次', '站台', '版本', '節點類型', '健康', 'Pack數', '狀況'] as $header) {
        hub_test_assert(str_contains($environment, "__('{$header}')"), 'Environment Cluster header missing: ' . $header);
    }
    hub_test_assert(str_contains($environment, "'aggregate' => 0, 'parent' => 1, 'child' => 2"), 'aggregate nodes must sort first');
    hub_test_assert(!str_contains($environment, "rows.join(' | ')"), 'web protection checks must not be inline');
    hub_test_assert(str_contains($environment, "document.createElement('li')"), 'web protection checks must render list items');
    hub_test_assert(str_contains($environment, '（安全）'), 'web protection pass entries need a safety label');
});

hub_test('release checker is CLI only and web pages cannot deploy', function (): void {
    $scriptPath = HUB_ROOT . '/scripts/check_release_update.php';
    hub_test_assert(is_file($scriptPath), 'release checker missing');
    $script = (string)file_get_contents($scriptPath);
    hub_test_assert(str_contains($script, 'hub_cli_only();'), 'release checker must be CLI only');
    hub_test_assert(str_contains($script, "['git', '-C', HUB_ROOT, 'ls-remote', '--tags', '--refs', 'origin']"), 'release checker must use a command array');

    foreach (['admin/settings.php', 'admin/environment.php'] as $path) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $path);
        foreach (['git pull', 'git checkout', 'git reset', 'shell_exec(', 'exec('] as $forbidden) {
            hub_test_assert(!str_contains($source, $forbidden), $path . ' contains web deployment operation ' . $forbidden);
        }
        hub_test_assert(str_contains($source, 'hub_release_'), $path . ' missing shared read-only release contract');
    }
});
