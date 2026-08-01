<?php
declare(strict_types=1);

require_once HUB_ROOT . '/app/admin_dashboard.php';

function hub_test_admin_dashboard_with_cluster_secret(callable $fn): void
{
    $previous = getenv('AIHUB_CLUSTER_SECRET_KEY');
    putenv('AIHUB_CLUSTER_SECRET_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef');
    try {
        $fn();
    } finally {
        $previous === false
            ? putenv('AIHUB_CLUSTER_SECRET_KEY')
            : putenv('AIHUB_CLUSTER_SECRET_KEY=' . $previous);
    }
}

function hub_test_admin_dashboard_station(PDO $db, array $overrides = []): array
{
    $stationId = hub_cluster_save_paired_station($db, array_replace([
        'station_key' => 'station_1080',
        'display_name' => '1080 影像站',
        'public_base_url' => 'https://station.example/aihub',
        'internal_base_url' => 'https://station.internal/aihub',
        'priority' => 7,
        'enabled' => true,
        'station_token' => '3wa_live_station_secret',
        'modes' => ['vision'],
    ], $overrides));
    $station = hub_cluster_get_station($db, $stationId);
    if ($station === null) {
        throw new RuntimeException('dashboard station fixture missing');
    }

    return $station;
}

function hub_test_admin_dashboard_gpu_history_point(string $sampledAt, float $value): array
{
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $sampledAt, new DateTimeZone('Asia/Taipei'));
    if ($timestamp === false) {
        throw new RuntimeException('invalid GPU history fixture timestamp');
    }

    return ['label' => $sampledAt, 'timestamp' => $timestamp->getTimestamp() * 1000, 'value' => $value];
}

hub_test('dashboard model separates child and router station behavior', function (): void {
    $db = hub_test_reset_db();
    hub_cluster_node_configure($db, true, []);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '0');
    $child = hub_admin_dashboard_model($db, []);
    hub_test_assert($child['role'] === 'child', 'child role mismatch');
    hub_test_assert($child['station_tabs'] === [], 'child dashboard must not expose station tabs');

    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    $router = hub_admin_dashboard_model($db, []);
    hub_test_assert($router['role'] === 'aggregate', 'both enabled roles must render aggregate');
    hub_test_assert($router['aggregate'] === true, 'aggregate flag mismatch');
});

hub_test('router dashboard tabs use station display names and query keys', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_admin_dashboard_station($db);
        hub_test_admin_dashboard_station($db, [
            'station_key' => 'station_tts',
            'display_name' => '語音服務站',
        ]);

        $model = hub_admin_dashboard_model($db, ['station' => 'station_1080']);
        hub_test_assert($model['active_station_key'] === 'station_1080', 'station query selection mismatch');
        hub_test_assert($model['station_tabs'][0]['label'] === '1080 影像站', 'station title mismatch');
        hub_test_assert($model['station_tabs'][0]['station_key'] === 'station_1080', 'station key mismatch');
        hub_test_assert(($model['station_tabs'][0]['connection_state'] ?? '') === 'offline', 'station tabs must expose the shared connection state');

        $fallback = hub_admin_dashboard_model($db, ['station' => 'GPU 1']);
        hub_test_assert($fallback['active_station_key'] === 'station_1080', 'invalid station query must select first configured station');
        hub_test_assert(!str_contains(json_encode($fallback['station_tabs'], JSON_THROW_ON_ERROR), 'GPU 1'), 'dashboard must not synthesize GPU station labels');
    });
});

hub_test('aggregate dashboard keeps the self station once and does not write configuration', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_cluster_node_configure($db, true, []);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        hub_test_admin_dashboard_station($db, [
            'station_key' => 'local_station',
            'display_name' => '本機推論站',
        ]);
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_SELF_STATION_KEY', 'local_station');
        $changesBefore = (int)$db->query('SELECT total_changes()')->fetchColumn();

        $model = hub_admin_dashboard_model($db, []);

        $changesAfter = (int)$db->query('SELECT total_changes()')->fetchColumn();
        hub_test_assert(count($model['station_tabs']) === 1, 'self station must not be duplicated');
        hub_test_assert($model['station_tabs'][0]['label'] === '本機推論站', 'self station must retain display name');
        hub_test_assert($changesAfter === $changesBefore, 'dashboard model must be read-only');
    });
});

hub_test('dashboard preserves unknown legacy health instead of reporting stopped services', function (): void {
    $summary = hub_admin_dashboard_station_summary([
        'display_name' => 'Legacy Node',
        'enabled' => true,
        'fresh' => true,
        'services' => [
            ['name' => 'OCR', 'mode' => 'ocr'],
            ['name' => 'TTS', 'mode' => 'tts'],
        ],
    ]);

    hub_test_assert($summary['health']['status'] === 'unknown', 'missing station health must remain unknown');
    hub_test_assert($summary['service_counts']['pending'] === 2, 'unknown legacy services must be pending');
    hub_test_assert($summary['service_counts']['stopped'] === 0, 'unknown legacy services must not be reported stopped');
});

hub_test('dashboard treats legacy child GPU totals as available unless explicitly unavailable', function (): void {
    $legacy = hub_admin_dashboard_station_summary([
        'display_name' => 'Legacy GPU Node',
        'gpu' => ['memory_total_mb' => 8192, 'memory_free_mb' => 4096],
    ]);
    $unavailable = hub_admin_dashboard_station_summary([
        'display_name' => 'Unavailable GPU Node',
        'gpu' => ['available' => false, 'memory_total_mb' => 8192, 'memory_free_mb' => 4096],
    ]);

    hub_test_assert($legacy['gpu']['available'] === true, 'legacy GPU totals without an availability flag must remain available');
    hub_test_assert($unavailable['gpu']['available'] === false, 'an explicit unavailable GPU flag must remain unavailable');
});

hub_test('dashboard preserves partial child GPU memory metrics', function (): void {
    $partial = hub_admin_dashboard_station_summary([
        'display_name' => 'Partial GPU Node',
        'gpu' => ['available' => true, 'memory_total_mb' => 16310, 'memory_used_mb' => 1234],
    ]);
    $missing = hub_admin_dashboard_station_summary([
        'display_name' => 'Missing GPU Memory Node',
        'gpu' => ['available' => true, 'memory_total_mb' => 16310],
    ]);

    hub_test_assert($partial['gpu']['memory_used_mb'] === 1234, 'supplied child GPU VRAM usage must not be derived from missing free VRAM');
    hub_test_assert(!array_key_exists('memory_free_mb', $partial['gpu']), 'missing child GPU free VRAM must not be fabricated');
    hub_test_assert(!array_key_exists('memory_used_mb', $missing['gpu']), 'missing child GPU usage must not be fabricated');
    hub_test_assert(!array_key_exists('memory_free_mb', $missing['gpu']), 'missing child GPU free VRAM must not be fabricated');
});

hub_test('local dashboard expires old metrics and exposes read-only release identity', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, ['gpu' => ['available' => false]]);
    $db->exec("UPDATE host_metric_snapshots SET created_at = '2020-01-01 00:00:00'");

    $model = hub_admin_dashboard_model($db, []);

    hub_test_assert($model['summary']['fresh'] === false, 'old local metrics must be stale');
    hub_test_assert(
        ($model['summary']['release']['build_id'] ?? '') === HUB_VERSION,
        'local dashboard must expose current read-only release identity'
    );
    hub_test_assert($model['summary']['pack_compatible'] === true, 'local Pack inventory is compatible with itself');
});

hub_test('local dashboard masks only explicitly unavailable GPU metrics', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, [
        'gpu' => ['available' => false, 'util_percent' => 73, 'temperature_c' => 66, 'memory_total_mb' => 16384, 'memory_used_mb' => 12288, 'memory_free_mb' => 4096],
    ]);

    $unavailable = hub_admin_dashboard_model($db, [])['summary']['gpu'];
    foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $unavailable), 'explicit unavailable local GPU metrics must not reach Dashboard cards: ' . $field);
    }

    hub_save_host_metric_snapshot($db, [
        'gpu' => ['util_percent' => 73, 'temperature_c' => 66, 'memory_total_mb' => 16384, 'memory_used_mb' => 12288, 'memory_free_mb' => 4096],
    ]);
    $legacy = hub_admin_dashboard_model($db, [])['summary']['gpu'];
    hub_test_assert($legacy['util_percent'] === 73 && $legacy['temperature_c'] === 66 && $legacy['memory_used_mb'] === 12288, 'legacy local GPU metrics without availability must remain visible');
});

hub_test('local dashboard merges measured service GPU telemetry by service key', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'hello', ['service_key' => 'hello-gpu', 'mode' => 'gpu_hello']);
    hub_install_pack($db, 'taiwan-address', ['service_key' => 'address-gpu', 'mode' => 'gpu_address']);
    hub_save_host_metric_snapshot($db, [
        'service_gpu' => [
            ['service_key' => 'hello-gpu', 'mode' => 'gpu_hello', 'vram_used_mb' => 768, 'measured' => true],
            ['service_key' => 'address-gpu', 'mode' => 'gpu_address', 'vram_used_mb' => 0, 'measured' => true],
        ],
    ]);

    $services = array_column(hub_admin_dashboard_model($db, [])['summary']['services'], null, 'service_key');
    hub_test_assert(($services['hello-gpu']['gpu_vram_measured'] ?? false) === true, 'local service must retain a measured VRAM flag');
    hub_test_assert(($services['hello-gpu']['gpu_vram_used_mb'] ?? null) === 768, 'local service must match measured VRAM by service_key');
    hub_test_assert(($services['address-gpu']['gpu_vram_measured'] ?? false) === true, 'local zero VRAM measurement must remain measured');
    hub_test_assert(($services['address-gpu']['gpu_vram_used_mb'] ?? null) === 0, 'local zero VRAM measurement must not disappear');
});

hub_test('child dashboard merges measured service GPU telemetry by manifest mode', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $snapshotAt = date('Y-m-d H:i:s', time() - 30);
        $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        )->execute([
            ':manifest_json' => json_encode(['services' => [
                ['mode' => 'ocr', 'name' => 'OCR'],
                ['mode' => 'edge_tts', 'name' => 'Edge TTS'],
            ]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $snapshotAt,
            ':status_json' => json_encode(['service_gpu' => [
                ['service_key' => 'ocr-gpu', 'mode' => 'ocr', 'vram_used_mb' => 1536, 'measured' => true],
                ['service_key' => 'edge-tts-main', 'mode' => 'edge_tts', 'vram_used_mb' => 0, 'measured' => true],
            ]], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);

        $services = array_column(hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['services'], null, 'mode');
        hub_test_assert(!array_key_exists('service_key', $services['ocr']), 'child manifest rows must not need service_key matching');
        hub_test_assert(($services['ocr']['gpu_vram_measured'] ?? false) === true, 'child manifest service must retain a measured VRAM flag');
        hub_test_assert(($services['ocr']['gpu_vram_used_mb'] ?? null) === 1536, 'child manifest service must match measured VRAM by mode');
        hub_test_assert(($services['edge_tts']['gpu_vram_measured'] ?? false) === true, 'child zero VRAM measurement must remain measured');
        hub_test_assert(($services['edge_tts']['gpu_vram_used_mb'] ?? null) === 0, 'child zero VRAM measurement must not disappear');
    });
});

hub_test('dashboard service GPU merge omits absent or invalid telemetry', function (): void {
    $services = [
        ['service_key' => 'absent-gpu', 'mode' => 'absent'],
        ['service_key' => 'invalid-gpu', 'mode' => 'invalid'],
        ['service_key' => 'false-gpu', 'mode' => 'false_value'],
        ['service_key' => 'unknown-gpu', 'mode' => 'unknown'],
    ];
    $absent = hub_admin_dashboard_services_with_gpu($services, []);
    $invalid = hub_admin_dashboard_services_with_gpu($services, [
        ['service_key' => 'invalid-gpu', 'mode' => 'invalid', 'vram_used_mb' => -1, 'measured' => true],
        ['service_key' => 'false-gpu', 'mode' => 'false_value', 'vram_used_mb' => 256, 'measured' => false],
        ['service_key' => 'unknown-gpu', 'mode' => 'unknown', 'vram_used_mb' => 256, 'measured' => 'unknown'],
    ]);

    foreach ([$absent, $invalid] as $rows) {
        foreach ($rows as $service) {
            hub_test_assert(!array_key_exists('gpu_vram_measured', $service), 'unmeasured service must not expose a VRAM measured flag');
            hub_test_assert(!array_key_exists('gpu_vram_used_mb', $service), 'unmeasured service must not expose a VRAM value');
        }
    }

    $duplicates = hub_admin_dashboard_services_with_gpu([
        ['service_key' => 'duplicate-mode-a', 'mode' => 'duplicate_mode'],
        ['mode' => 'first_mode'],
    ], [
        ['service_key' => 'duplicate-mode-a', 'mode' => 'duplicate_mode', 'vram_used_mb' => 256, 'measured' => true],
        ['service_key' => 'duplicate-mode-b', 'mode' => 'duplicate_mode', 'vram_used_mb' => 512, 'measured' => true],
        ['service_key' => 'shared-key', 'mode' => 'first_mode', 'vram_used_mb' => 768, 'measured' => true],
        ['service_key' => 'shared-key', 'mode' => 'second_mode', 'vram_used_mb' => 1024, 'measured' => true],
    ]);
    foreach ($duplicates as $service) {
        hub_test_assert(!array_key_exists('gpu_vram_measured', $service), 'ambiguous telemetry must not allocate VRAM to a service');
        hub_test_assert(!array_key_exists('gpu_vram_used_mb', $service), 'ambiguous telemetry must not expose a VRAM value');
    }
});

hub_test('successful GPU probe omits unavailable values instead of charting zeroes', function (): void {
    $calls = 0;
    $gpu = hub_collect_gpu_metric(static function () use (&$calls): array {
        return hub_collect_gpu_status(static function (array $command) use (&$calls): array {
            $calls++;
            $stdout = $command === ['nvidia-smi']
                ? 'NVIDIA-SMI 555.42 CUDA Version: 12.8'
                : 'NVIDIA Test GPU, 555.42, N/A, , missing, N/A, N/A';
            return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => '', 'output' => $stdout];
        });
    });

    hub_test_assert($calls === 2 && $gpu['available'] === true, 'successful nvidia-smi probe must still identify the GPU');
    hub_test_assert(($gpu['name'] ?? '') === 'NVIDIA Test GPU' && ($gpu['cuda_version'] ?? '') === '12.8', 'GPU identity fields must remain available');
    foreach (['util_percent', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb', 'temperature_c'] as $field) {
        hub_test_assert(!array_key_exists($field, $gpu), 'unavailable GPU field must not become zero: ' . $field);
    }

    $outOfRange = hub_collect_gpu_metric(static fn (): array => [
        'nvidia_smi_available' => true,
        'vram_total_mb' => '1e20',
        'vram_used_mb' => '-1',
        'vram_free_mb' => '1000000001',
    ]);
    foreach (['memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $outOfRange), 'out-of-range GPU memory field must remain unknown: ' . $field);
    }

    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, ['gpu' => $gpu]);
    $history = hub_admin_dashboard_model($db, [])['summary']['gpu_history'];
    hub_test_assert($history['temperature'] === [] && $history['vram_used'] === [], 'unavailable GPU fields must not create zero-valued Dashboard history');

    $valid = hub_collect_gpu_metric(static fn (): array => [
        'nvidia_smi_available' => true,
        'utilization_percent' => '42',
        'vram_total_mb' => '16384',
        'vram_used_mb' => '4096',
        'vram_free_mb' => '12288',
        'temperature_c' => '67',
    ]);
    hub_test_assert($valid['util_percent'] === 42 && $valid['memory_used_mb'] === 4096 && $valid['temperature_c'] === 67, 'valid GPU values must remain numeric');
});

hub_test('dashboard rejects invalid persisted GPU metrics', function (): void {
    $db = hub_test_reset_db();
    hub_save_host_metric_snapshot($db, [
        'gpu' => [
            'available' => true,
            'util_percent' => 101,
            'temperature_c' => '1e20',
            'memory_total_mb' => '1000000001',
            'memory_used_mb' => -1,
            'memory_free_mb' => '1e20',
        ],
    ]);
    $localGpu = hub_admin_dashboard_model($db, [])['summary']['gpu'];
    foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $localGpu), 'invalid persisted local GPU metric must remain unknown: ' . $field);
    }

    $recentAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true, 'temperature_c' => '1e20', 'memory_used_mb' => -1]],
    ]);
    hub_test_assert($history === ['temperature' => [], 'vram_used' => []], 'invalid persisted GPU history values must not chart');

    $child = hub_admin_dashboard_station_summary([
        'display_name' => 'Invalid GPU Node',
        'gpu' => [
            'available' => true,
            'util_percent' => 101,
            'temperature_c' => -1,
            'memory_total_mb' => '1e20',
            'memory_used_mb' => '1000000001',
        ],
    ])['gpu'];
    foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $child), 'invalid persisted child GPU metric must remain unknown: ' . $field);
    }
});

hub_test('dashboard GPU history readers keep only recent local and child samples', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $recentAt = date('Y-m-d H:i:s', time() - 60);
        $expiredAt = date('Y-m-d H:i:s', time() - 86401);
        $hostInsert = $db->prepare(
            'INSERT INTO host_metric_snapshots (snapshot_json, created_at) VALUES (:snapshot_json, :created_at)'
        );
        foreach ([
            [$expiredAt, ['available' => true, 'temperature_c' => 40, 'memory_used_mb' => 100]],
            [$recentAt, ['available' => true, 'temperature_c' => 55, 'memory_used_mb' => 200]],
        ] as [$createdAt, $gpu]) {
            $hostInsert->execute([
                ':snapshot_json' => json_encode(['gpu' => $gpu], JSON_THROW_ON_ERROR),
                ':created_at' => $createdAt,
            ]);
        }
        $local = hub_admin_dashboard_local_gpu_history($db, $since);
        hub_test_assert($local['temperature'] === [hub_test_admin_dashboard_gpu_history_point($recentAt, 55.0)], 'local GPU history must exclude expired temperature samples');
        hub_test_assert($local['vram_used'] === [hub_test_admin_dashboard_gpu_history_point($recentAt, 200.0)], 'local GPU history must exclude expired VRAM samples');
        hub_test_assert(is_int($local['temperature'][0]['timestamp']), 'GPU history rows must expose numeric epoch-millisecond timestamps');
        hub_test_assert(hub_admin_dashboard_model($db, [])['summary']['gpu_history'] === $local, 'local Dashboard must expose its GPU history');

        $station = hub_test_admin_dashboard_station($db);
        $childInsert = $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        );
        foreach ([
            [$expiredAt, ['available' => true, 'temperature_c' => 41, 'memory_used_mb' => 300]],
            [$recentAt, ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 400]],
        ] as [$sampledAt, $gpu]) {
            $childInsert->execute([
                ':station_id' => (int)$station['id'],
                ':sampled_at' => $sampledAt,
                ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
            ]);
        }
        $child = hub_admin_dashboard_station_gpu_history($db, (int)$station['id'], $since);
        hub_test_assert($child['temperature'] === [hub_test_admin_dashboard_gpu_history_point($recentAt, 66.0)], 'child GPU history must exclude expired temperature samples');
        hub_test_assert($child['vram_used'] === [hub_test_admin_dashboard_gpu_history_point($recentAt, 400.0)], 'child GPU history must exclude expired VRAM samples');
    });
});

hub_test('selected child dashboard retains current GPU metrics and history', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $snapshotAt = date('Y-m-d H:i:s', time() - 30);
        $gpu = [
            'available' => true,
            'memory_total_mb' => 16384,
            'memory_free_mb' => 4096,
            'memory_used_mb' => 12288,
            'util_percent' => 73,
            'temperature_c' => 66,
        ];
        $db->prepare(
            'UPDATE cluster_stations SET status_json = :status_json, status_fetched_at = :status_fetched_at WHERE id = :id'
        )->execute([
            ':status_json' => json_encode(['gpu' => $gpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        )->execute([
            ':station_id' => (int)$station['id'],
            ':sampled_at' => $snapshotAt,
            ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
        ]);

        $model = hub_admin_dashboard_model($db, ['station' => 'station_1080']);

        hub_test_assert(($model['summary']['gpu']['util_percent'] ?? null) === 73, 'child Dashboard must retain current compact GPU utilization');
        hub_test_assert(($model['summary']['gpu']['temperature_c'] ?? null) === 66, 'child Dashboard must retain current compact GPU temperature');
        hub_test_assert($model['summary']['gpu_history']['temperature'] === [hub_test_admin_dashboard_gpu_history_point($snapshotAt, 66.0)], 'child Dashboard must expose selected station temperature history');
        hub_test_assert($model['summary']['gpu_history']['vram_used'] === [hub_test_admin_dashboard_gpu_history_point($snapshotAt, 12288.0)], 'child Dashboard must expose selected station VRAM history');
    });
});

hub_test('child dashboard does not infer missing compact GPU memory metrics', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $db->prepare(
            'UPDATE cluster_stations SET status_json = :status_json, status_fetched_at = :status_fetched_at WHERE id = :id'
        )->execute([
            ':status_json' => json_encode(['gpu' => ['available' => true, 'memory_total_mb' => 16310]], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => date('Y-m-d H:i:s', time() - 30),
            ':id' => (int)$station['id'],
        ]);

        $gpu = hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['gpu'];

        hub_test_assert(!array_key_exists('memory_free_mb', $gpu), 'missing compact child GPU free VRAM must remain unknown');
        hub_test_assert(!array_key_exists('memory_used_mb', $gpu), 'missing compact child GPU used VRAM must remain unknown');
    });
});

hub_test('child dashboard masks compact GPU VRAM without capacity', function (): void {
    $summary = hub_admin_dashboard_station_summary([
        'display_name' => 'No Capacity GPU Node',
        'gpu' => ['available' => true, 'memory_used_mb' => 1234, 'memory_free_mb' => 4096],
    ]);

    hub_test_assert($summary['gpu']['available'] === false, 'GPU without a capacity must not report VRAM availability');
    foreach (['memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
        hub_test_assert(!array_key_exists($field, $summary['gpu']), 'GPU without capacity must mask VRAM: ' . $field);
    }
});

hub_test('child dashboard honors legacy and explicit GPU availability states', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
        $station = hub_test_admin_dashboard_station($db);
        $snapshotAt = date('Y-m-d H:i:s', time() - 30);
        $update = $db->prepare(
            'UPDATE cluster_stations SET status_json = :status_json, status_fetched_at = :status_fetched_at WHERE id = :id'
        );
        $legacyGpu = [
            'memory_total_mb' => 16384,
            'memory_free_mb' => 4096,
            'util_percent' => 73,
            'temperature_c' => 66,
        ];
        $update->execute([
            ':status_json' => json_encode(['gpu' => $legacyGpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $legacy = hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['gpu'];
        hub_test_assert($legacy['available'] === true, 'legacy child GPU with VRAM must remain available');
        hub_test_assert($legacy['util_percent'] === 73 && $legacy['temperature_c'] === 66, 'legacy child GPU metrics must remain visible');

        $unavailableGpu = array_replace($legacyGpu, ['available' => false]);
        $update->execute([
            ':status_json' => json_encode(['gpu' => $unavailableGpu], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $snapshotAt,
            ':id' => (int)$station['id'],
        ]);
        $unavailable = hub_admin_dashboard_model($db, ['station' => 'station_1080'])['summary']['gpu'];
        hub_test_assert($unavailable['available'] === false, 'explicit unavailable child GPU state must remain unavailable');
        foreach (['util_percent', 'temperature_c', 'memory_total_mb', 'memory_used_mb', 'memory_free_mb'] as $field) {
            hub_test_assert(!array_key_exists($field, $unavailable), 'unavailable child GPU metrics must not reach Dashboard cards: ' . $field);
        }
    });
});

hub_test('child GPU history honors legacy and explicit availability states', function (): void {
    hub_test_admin_dashboard_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $station = hub_test_admin_dashboard_station($db);
        $legacyAt = date('Y-m-d H:i:s', time() - 120);
        $unavailableAt = date('Y-m-d H:i:s', time() - 60);
        $insert = $db->prepare(
            'INSERT INTO cluster_gpu_metric_snapshots (station_id, sampled_at, gpu_json) VALUES (:station_id, :sampled_at, :gpu_json)'
        );
        foreach ([
            [$legacyAt, ['temperature_c' => 66, 'memory_used_mb' => 400]],
            [$unavailableAt, ['available' => false, 'temperature_c' => 67, 'memory_used_mb' => 500]],
        ] as [$sampledAt, $gpu]) {
            $insert->execute([
                ':station_id' => (int)$station['id'],
                ':sampled_at' => $sampledAt,
                ':gpu_json' => json_encode($gpu, JSON_THROW_ON_ERROR),
            ]);
        }

        $history = hub_admin_dashboard_station_gpu_history($db, (int)$station['id'], date('Y-m-d H:i:s', time() - 86400));
        hub_test_assert(
            $history === [
                'temperature' => [hub_test_admin_dashboard_gpu_history_point($legacyAt, 66.0)],
                'vram_used' => [hub_test_admin_dashboard_gpu_history_point($legacyAt, 400.0)],
            ],
            'legacy child history must render while explicit false remains hidden'
        );
    });
});

hub_test('dashboard GPU history omits unavailable and missing metric values', function (): void {
    $recentAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => $recentAt, 'gpu' => ['available' => false, 'temperature_c' => 0, 'memory_used_mb' => 0]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true, 'temperature_c' => 'unknown', 'memory_used_mb' => null]],
    ]);

    hub_test_assert($history === ['temperature' => [], 'vram_used' => []], 'unavailable or missing GPU metrics must not create zero-valued history points');
});

hub_test('dashboard GPU history rejects malformed future and expired timestamps', function (): void {
    $recentAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => 'not-a-timestamp', 'gpu' => ['available' => true, 'temperature_c' => 40, 'memory_used_mb' => 100]],
        ['sampled_at' => date('Y-m-d H:i:s', time() + 60), 'gpu' => ['available' => true, 'temperature_c' => 41, 'memory_used_mb' => 101]],
        ['sampled_at' => date('Y-m-d H:i:s', time() - 86401), 'gpu' => ['available' => true, 'temperature_c' => 42, 'memory_used_mb' => 102]],
        ['sampled_at' => $recentAt, 'gpu' => ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 400]],
    ]);

    hub_test_assert(
        $history === [
            'temperature' => [hub_test_admin_dashboard_gpu_history_point($recentAt, 66.0)],
            'vram_used' => [hub_test_admin_dashboard_gpu_history_point($recentAt, 400.0)],
        ],
        'GPU history must accept only canonical timestamps inside the 24-hour window'
    );
});

hub_test('dashboard GPU history keeps the latest same-second sample aligned', function (): void {
    $sampledAt = date('Y-m-d H:i:s', time() - 60);
    $history = hub_admin_dashboard_gpu_history_rows([
        ['sampled_at' => $sampledAt, 'gpu' => ['available' => true, 'temperature_c' => 55, 'memory_used_mb' => 100]],
        ['sampled_at' => $sampledAt, 'gpu' => ['available' => true, 'temperature_c' => 66, 'memory_used_mb' => 200]],
    ]);

    hub_test_assert(
        $history === [
            'temperature' => [hub_test_admin_dashboard_gpu_history_point($sampledAt, 66.0)],
            'vram_used' => [hub_test_admin_dashboard_gpu_history_point($sampledAt, 200.0)],
        ],
        'same-second GPU history must retain the latest aligned sample only'
    );
});

hub_test('dashboard page uses accepted local assets and query-backed station tabs', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/index.php');
    $script = (string)file_get_contents(HUB_ROOT . '/assets/js/admin-dashboard.js');
    $dashboardCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-dashboard.css');
    $baseCss = (string)file_get_contents(HUB_ROOT . '/assets/css/admin-base.css');
    $seed = (string)file_get_contents(HUB_ROOT . '/i18n/seed.json');

    foreach ([
        '../assets/css/admin-dashboard.css',
        '../assets/js/vendor/chart.umd.js',
        'id="dashboard-data"',
        '../assets/js/admin-dashboard.js',
        'class="station-tabs"',
        "rawurlencode(\$station['station_key'])",
        "hub_h(\$station['label'])",
    ] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard page missing contract: ' . $needle);
    }
    foreach (['cdn.jsdelivr', 'echarts', 'GPU1', 'GPU2', 'GPU3'] as $forbidden) {
        hub_test_assert(!str_contains($page, $forbidden), 'dashboard page contains forbidden source or station label: ' . $forbidden);
    }
    foreach (['fetch(', 'localStorage', 'sessionStorage', 'cdn.', 'adapter'] as $forbidden) {
        hub_test_assert(!str_contains($script, $forbidden), 'dashboard script must use server JSON without persistent state: ' . $forbidden);
    }
    hub_test_assert(str_contains($script, 'maintainAspectRatio: false'), 'dashboard charts must preserve accepted container sizing');
    hub_test_assert(str_contains($script, 'ResizeObserver'), 'dashboard charts must respond to container resize');
    foreach (['gpuTemperatureHistory', 'gpuVramHistory', 'gpuTemperatureChart', 'gpuVramHistoryChart'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard page missing GPU history contract: ' . $needle);
    }
    foreach ([
        "create('gpuTemperatureChart', 'line', rows('gpuTemperatureHistory'), palette.amber, lineOptions('°C'))",
        "create('gpuVramHistoryChart', 'line', rows('gpuVramHistory'), palette.blue, lineOptions(' MB'))",
        'function lineOptions(suffix)',
        'x: Number(item.timestamp), y: Number(item.value)',
        'spanGaps: line ? 120000 : false',
        "type: 'linear'",
        'toLocaleTimeString',
        'tooltip: {',
        'items[0].parsed.x',
    ] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'dashboard script missing GPU history chart contract: ' . $needle);
    }
    foreach ([
        'aria-describedby="gpu-temperature-history-summary"',
        'id="gpu-temperature-history-summary"',
        'aria-describedby="gpu-vram-history-summary"',
        'id="gpu-vram-history-summary"',
        '$gpuTemperatureHistorySummary',
        '$gpuVramHistorySummary',
    ] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard page missing GPU history summary contract: ' . $needle);
    }
    hub_test_assert(!str_contains($page, "(float)(\$gpu['memory_free_mb'] ?? 0)"), 'dashboard must not treat missing GPU free VRAM as zero');
    hub_test_assert(str_contains($page, "\$gpuAvailable ? hub_h(hub_admin_dash_value(\$gpuUsed, ' MB'))"), 'dashboard must render unavailable VRAM as N/A');
    foreach ([
        'GPU 溫度（24 小時）',
        'GPU VRAM 使用量（24 小時）',
        'GPU 溫度 24 小時趨勢圖',
        'GPU VRAM 使用量 24 小時趨勢圖',
        '尚無 GPU 歷史資料。',
        '共 %1$d 筆資料；最新 %2$s：%3$s',
        'GPU temperature (24 hours)',
        'GPU VRAM usage (24 hours)',
        'No GPU history data.',
        '%1$d samples; latest %2$s: %3$s',
    ] as $needle) {
        hub_test_assert(str_contains($seed, $needle), 'GPU history i18n seed missing: ' . $needle);
    }
    foreach (['station-tab__status', 'connection_state', '可連線', '無法連線'] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'dashboard station connectivity markup missing ' . $needle);
    }
    foreach (['station-tab__status', 'station-tab__status--online'] as $needle) {
        hub_test_assert(str_contains($dashboardCss, $needle), 'dashboard station connectivity style missing ' . $needle);
    }
    foreach (['station-connection', 'station-connection--online'] as $needle) {
        hub_test_assert(str_contains($baseCss, $needle), 'shared station connectivity style missing ' . $needle);
    }
});
