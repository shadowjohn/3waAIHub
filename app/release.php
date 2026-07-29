<?php
declare(strict_types=1);

function hub_release_display_version(string $buildId): string
{
    return preg_match('/\A(\d{4})(\d{2})(\d{2})(\d{3})\z/', $buildId, $match) === 1
        ? $match[1] . '.' . $match[2] . '.' . $match[3] . '.' . $match[4]
        : $buildId;
}

function hub_release_pack_inventory(): array
{
    $inventory = [];
    foreach (hub_list_packs() as $pack) {
        $packId = (string)($pack['id'] ?? '');
        if ($packId !== '') {
            $inventory[$packId] = (string)($pack['manifest']['version'] ?? '');
        }
    }
    ksort($inventory, SORT_STRING);

    return $inventory;
}

function hub_release_read_only_git_command(array $command): array
{
    $allowed = [
        ['git', '-C', HUB_ROOT, 'rev-parse', '--short=12', 'HEAD'],
        ['git', '-C', HUB_ROOT, 'status', '--porcelain', '--untracked-files=no'],
        ['git', '-C', HUB_ROOT, 'tag', '--points-at', 'HEAD'],
    ];
    if (!in_array($command, $allowed, true)) {
        return ['exit_code' => 126, 'stdout' => '', 'stderr' => 'command_not_allowed', 'output' => ''];
    }

    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $environment['GIT_OPTIONAL_LOCKS'] = '0';
    $process = @proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        HUB_ROOT,
        $environment
    );
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'command_unavailable', 'output' => ''];
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $stdout = '';
    $stderr = '';
    $startedAt = microtime(true);
    $observedExitCode = null;
    do {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($process);
        if (!$status['running']) {
            $observedExitCode = hub_observed_process_exit_code($status) ?? $observedExitCode;
            break;
        }
        if (microtime(true) - $startedAt > 3) {
            proc_terminate($process);
            $stderr = 'command_timeout';
            break;
        }
        usleep(20000);
    } while (true);

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = hub_process_exit_code(proc_close($process), $observedExitCode);

    return [
        'exit_code' => $exitCode,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'output' => '',
    ];
}

function hub_release_local_cache_path(): string
{
    return HUB_DATA_DIR . '/cache/release_local.json';
}

function hub_release_write_local_cache(
    array $report,
    ?string $path = null,
    ?callable $clock = null
): void
{
    $path ??= hub_release_local_cache_path();
    $clock ??= 'hub_now';
    $buildId = is_string($report['build_id'] ?? null) ? $report['build_id'] : '';
    $commit = is_string($report['commit'] ?? null) ? strtolower($report['commit']) : '';
    $tag = is_string($report['tag'] ?? null) ? $report['tag'] : '';
    $label = is_string($report['label'] ?? null) ? $report['label'] : '';
    if (
        preg_match('/\A\d{11}\z/', $buildId) !== 1
        || preg_match('/\A[0-9a-f]{7,40}\z/', $commit) !== 1
        || ($tag !== '' && preg_match('/\A\d{11}\z/', $tag) !== 1)
        || !is_bool($report['dirty'] ?? null)
        || strlen($label) > 160
    ) {
        throw new InvalidArgumentException('local_release_invalid');
    }

    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('cache_unavailable');
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    try {
        $payload = [
            'build_id' => $buildId,
            'display_version' => hub_release_display_version($buildId),
            'label' => $label,
            'commit' => $commit,
            'dirty' => $report['dirty'],
            'tag' => $tag,
            'snapshot_at' => $clock(),
            'source' => 'cli_snapshot',
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !chmod($temporary, 0664)) {
            throw new RuntimeException('cache_unavailable');
        }
        if (!rename($temporary, $path) || !chmod($path, 0664)) {
            throw new RuntimeException('cache_unavailable');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function hub_release_read_local_cache(?string $path = null): array
{
    $empty = [
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'label' => HUB_RELEASE_LABEL,
        'commit' => '',
        'dirty' => null,
        'tag' => '',
        'snapshot_at' => '',
        'source' => 'unknown',
    ];
    $path ??= hub_release_local_cache_path();
    if (!is_file($path)) {
        return $empty;
    }

    $payload = json_decode((string)file_get_contents($path), true);
    if (
        !is_array($payload)
        || !is_string($payload['build_id'] ?? null)
        || preg_match('/\A\d{11}\z/', $payload['build_id']) !== 1
        || !is_string($payload['commit'] ?? null)
        || preg_match('/\A[0-9a-f]{7,40}\z/', $payload['commit']) !== 1
        || !is_bool($payload['dirty'] ?? null)
        || !is_string($payload['tag'] ?? null)
        || ($payload['tag'] !== '' && preg_match('/\A\d{11}\z/', $payload['tag']) !== 1)
        || !is_string($payload['label'] ?? null)
        || strlen($payload['label']) > 160
        || !is_string($payload['snapshot_at'] ?? null)
        || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $payload['snapshot_at']) !== 1
        || ($payload['source'] ?? null) !== 'cli_snapshot'
    ) {
        return $empty;
    }
    $timezone = new DateTimeZone(date_default_timezone_get());
    $snapshot = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $payload['snapshot_at'], $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (
        $snapshot === false
        || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $snapshot->format('Y-m-d H:i:s') !== $payload['snapshot_at']
    ) {
        return $empty;
    }
    $snapshotTime = $snapshot->getTimestamp();
    if ($snapshotTime > time() + 30 || time() - $snapshotTime > 300) {
        return $empty;
    }

    return [
        'build_id' => $payload['build_id'],
        'display_version' => hub_release_display_version($payload['build_id']),
        'label' => $payload['label'],
        'commit' => $payload['commit'],
        'dirty' => $payload['dirty'],
        'tag' => $payload['tag'],
        'snapshot_at' => $payload['snapshot_at'],
        'source' => 'cli_snapshot',
    ];
}

function hub_release_local_git_report(
    ?callable $runner = null,
    ?string $cachePath = null,
    bool $allowCache = true
): array
{
    $runner ??= 'hub_release_read_only_git_command';
    $commit = $runner(['git', '-C', HUB_ROOT, 'rev-parse', '--short=12', 'HEAD']);
    $dirty = $runner(['git', '-C', HUB_ROOT, 'status', '--porcelain', '--untracked-files=no']);
    $tag = $runner(['git', '-C', HUB_ROOT, 'tag', '--points-at', 'HEAD']);
    $commitValue = (int)($commit['exit_code'] ?? 1) === 0 ? trim((string)($commit['stdout'] ?? '')) : '';
    if (preg_match('/\A[0-9a-f]{7,40}\z/', $commitValue) !== 1) {
        $commitValue = '';
    }
    $tags = (int)($tag['exit_code'] ?? 1) === 0
        ? (preg_split('/\R/', trim((string)($tag['stdout'] ?? ''))) ?: [])
        : [];
    $releaseTags = array_values(array_filter(
        $tags,
        static fn (string $value): bool => preg_match('/\A\d{11}\z/', $value) === 1
    ));
    rsort($releaseTags, SORT_STRING);

    $report = [
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'label' => HUB_RELEASE_LABEL,
        'commit' => $commitValue,
        'dirty' => (int)($dirty['exit_code'] ?? 1) === 0
            ? trim((string)($dirty['stdout'] ?? '')) !== ''
            : null,
        'tag' => $releaseTags[0] ?? '',
        'snapshot_at' => hub_now(),
        'source' => 'git',
    ];

    return $allowCache && $report['commit'] === ''
        ? hub_release_read_local_cache($cachePath)
        : $report;
}

function hub_release_snapshot_local_git(?callable $runner = null, ?string $path = null): array
{
    $report = hub_release_local_git_report($runner, null, false);
    if ($report['commit'] !== '' && is_bool($report['dirty'])) {
        hub_release_write_local_cache($report, $path);
    }

    return $report;
}

function hub_release_runner_inventory(PDO $db): array
{
    if (!hub_table_exists($db, 'runtime_runs')) {
        return [];
    }

    $inventory = [];
    $rows = $db->query(
        "SELECT id, pack_id, pack_version, runner_version, image_name, image_digest, created_at
         FROM runtime_runs
         WHERE TRIM(COALESCE(pack_id, '')) <> ''
           AND (TRIM(COALESCE(image_name, '')) <> '' OR TRIM(COALESCE(image_digest, '')) <> '')
         ORDER BY id DESC"
    )->fetchAll();
    foreach ($rows as $row) {
        $packId = (string)$row['pack_id'];
        if (isset($inventory[$packId])) {
            continue;
        }
        $inventory[$packId] = [
            'pack_version' => (string)($row['pack_version'] ?? ''),
            'runner_version' => (string)($row['runner_version'] ?? ''),
            'image' => (string)($row['image_name'] ?? ''),
            'digest' => (string)($row['image_digest'] ?? ''),
            'observed_at' => (string)($row['created_at'] ?? ''),
        ];
    }
    ksort($inventory, SORT_STRING);

    return $inventory;
}

function hub_release_health_summary(PDO $db): array
{
    $installed = (int)$db->query("SELECT COUNT(*) FROM services WHERE install_status = 'installed'")->fetchColumn();
    $running = (int)$db->query(
        "SELECT COUNT(*) FROM services
         WHERE install_status = 'installed' AND enabled = 1
           AND (runtime_status = 'running' OR status = 'running')"
    )->fetchColumn();
    $failed = (int)$db->query(
        "SELECT COUNT(*) FROM services
         WHERE install_status = 'failed' OR runtime_status IN ('error', 'failed') OR status IN ('error', 'failed')"
    )->fetchColumn();
    $queued = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status = 'queued'")->fetchColumn();
    $active = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE status = 'running'")->fetchColumn();

    return [
        'status' => $failed > 0 ? 'degraded' : 'ok',
        'installed_services' => $installed,
        'running_services' => $running,
        'failed_services' => $failed,
        'queued_jobs' => $queued,
        'running_jobs' => $active,
    ];
}

function hub_release_node_report(PDO $db, ?callable $runner = null): array
{
    return [
        'git' => hub_release_local_git_report($runner),
        'packs' => hub_release_pack_inventory(),
        'runners' => hub_release_runner_inventory($db),
        'health' => hub_release_health_summary($db),
    ];
}

function hub_release_latest_remote_tag(string $output): string
{
    $releases = [];
    foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
        if (preg_match('/\A[0-9a-f]{40,64}\s+refs\/tags\/(\d{11})\z/i', trim($line), $match) === 1) {
            $releases[] = $match[1];
        }
    }
    $ids = array_values(array_unique($releases));
    rsort($ids, SORT_STRING);

    return $ids[0] ?? '';
}

function hub_release_remote_cache_path(): string
{
    return HUB_DATA_DIR . '/cache/release_remote.json';
}

function hub_release_write_remote_cache(array $payload, ?string $path = null): void
{
    $path ??= hub_release_remote_cache_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('cache_unavailable');
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(8));
    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !chmod($temporary, 0664)) {
            throw new RuntimeException('cache_unavailable');
        }
        if (!rename($temporary, $path) || !chmod($path, 0664)) {
            throw new RuntimeException('cache_unavailable');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function hub_release_read_remote_cache(?string $path = null): array
{
    $empty = ['checked_at' => '', 'latest_release' => '', 'error' => 'not_checked'];
    $path ??= hub_release_remote_cache_path();
    if (!is_file($path)) {
        return $empty;
    }
    $payload = json_decode((string)file_get_contents($path), true);
    if (
        !is_array($payload)
        || !is_string($payload['checked_at'] ?? null)
        || !is_string($payload['latest_release'] ?? null)
        || !is_string($payload['error'] ?? null)
        || (($payload['latest_release'] ?? '') !== '' && preg_match('/\A\d{11}\z/', $payload['latest_release']) !== 1)
        || strlen($payload['error']) > 64
    ) {
        return ['checked_at' => '', 'latest_release' => '', 'error' => 'cache_invalid'];
    }

    return [
        'checked_at' => substr($payload['checked_at'], 0, 19),
        'latest_release' => $payload['latest_release'],
        'error' => $payload['error'],
    ];
}

function hub_release_check_remote(
    ?callable $runner = null,
    ?string $cachePath = null,
    ?callable $clock = null
): array {
    $runner ??= static fn (array $command): array => hub_run_command($command, 10);
    $clock ??= 'hub_now';
    $result = $runner(['git', '-C', HUB_ROOT, 'ls-remote', '--tags', '--refs', 'origin']);
    $latest = (int)($result['exit_code'] ?? 1) === 0
        ? hub_release_latest_remote_tag((string)($result['stdout'] ?? ''))
        : '';
    $payload = [
        'checked_at' => $clock(),
        'latest_release' => $latest,
        'error' => (int)($result['exit_code'] ?? 1) !== 0
            ? 'remote_unavailable'
            : ($latest === '' ? 'release_not_found' : ''),
    ];
    hub_release_write_remote_cache($payload, $cachePath);

    return $payload;
}

function hub_release_update_commands(string $platform): array
{
    $isWindows = hub_platform_id($platform) === 'windows';
    $location = $isWindows
        ? 'Set-Location -LiteralPath ' . hub_powershell_single_quoted_literal(HUB_ROOT)
        : 'cd ' . escapeshellarg(HUB_ROOT);
    $separator = "\n";

    return [
        'integration_host' => [
            'commands' => $location . $separator
                . "git fetch origin\n"
                . 'git merge --ff-only origin/main',
        ],
        'execution_node' => [
            'commands' => $location . $separator
                . "git fetch --tags origin\n"
                . 'git checkout --detach RELEASE_ID',
        ],
        'authority' => [
            'push' => '3wa',
            'execution_nodes' => '5090 / 1080: fetch or immutable tag checkout; never push',
            'wsl' => 'authoring / validation only; not deployment authority',
        ],
    ];
}

function hub_release_station_report(array $station, array $localReport): array
{
    $status = json_decode((string)($station['status_json'] ?? ''), true);
    $status = is_array($status) ? $status : [];
    $git = is_array($status['release'] ?? null) ? $status['release'] : [];
    $buildId = is_string($git['build_id'] ?? null) && preg_match('/\A\d{11}\z/', $git['build_id']) === 1
        ? $git['build_id']
        : '';
    $commit = is_string($git['commit'] ?? null) && preg_match('/\A[0-9a-f]{7,40}\z/', $git['commit']) === 1
        ? $git['commit']
        : '';
    $tag = is_string($git['tag'] ?? null) && preg_match('/\A\d{11}\z/', $git['tag']) === 1
        ? $git['tag']
        : '';
    $dirty = is_bool($git['dirty'] ?? null) ? $git['dirty'] : null;
    $packs = is_array($status['packs'] ?? null) ? $status['packs'] : null;
    $localPacks = is_array($localReport['packs'] ?? null) ? $localReport['packs'] : [];
    if ($packs !== null) {
        $packs = array_filter(
            $packs,
            static fn (mixed $version, mixed $packId): bool => is_string($packId)
                && preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/', $packId) === 1
                && is_string($version)
                && strlen($version) <= 64,
            ARRAY_FILTER_USE_BOTH
        );
        ksort($packs, SORT_STRING);
    }
    ksort($localPacks, SORT_STRING);
    $health = is_array($status['health'] ?? null) && is_string($status['health']['status'] ?? null)
        ? substr($status['health']['status'], 0, 32)
        : 'unknown';
    $localBuildId = (string)($localReport['git']['build_id'] ?? '');
    $localCommit = (string)($localReport['git']['commit'] ?? '');
    $localTag = (string)($localReport['git']['tag'] ?? '');
    $localDirty = is_bool($localReport['git']['dirty'] ?? null) ? $localReport['git']['dirty'] : null;
    $updateNeeded = null;
    if ($buildId !== '' && preg_match('/\A\d{11}\z/', $localBuildId) === 1) {
        if ($buildId !== $localBuildId) {
            $updateNeeded = true;
        } elseif ($commit !== '' && preg_match('/\A[0-9a-f]{7,40}\z/', $localCommit) === 1) {
            if ($commit !== $localCommit || ($tag !== '' || $localTag !== '') && $tag !== $localTag || $dirty === true) {
                $updateNeeded = true;
            } elseif ($dirty === false && $localDirty === false) {
                $updateNeeded = false;
            }
        }
    }

    return [
        'display_name' => (string)($station['display_name'] ?? ''),
        'known' => $buildId !== '',
        'build_id' => $buildId,
        'display_version' => $buildId !== '' ? hub_release_display_version($buildId) : '',
        'commit' => $commit,
        'tag' => $tag,
        'dirty' => $dirty,
        'health' => $health,
        'pack_compatible' => $packs === null ? null : $packs === $localPacks,
        'pack_count' => $packs === null ? null : count($packs),
        'update_needed' => $updateNeeded,
    ];
}

function hub_release_status_label(string $status): string
{
    $label = [
        'ok' => '正常',
        'degraded' => '異常',
        'unknown' => '未知',
        'none' => '無',
        '' => '未知',
    ][$status] ?? $status;

    return __($label);
}

function hub_release_source_label(string $source): string
{
    return __([
        'git' => '即時 Git',
        'cli_snapshot' => 'CLI 快照',
        'unknown' => '未知',
        '' => '未知',
    ][$source] ?? '未知');
}
