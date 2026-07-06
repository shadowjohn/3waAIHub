<?php
declare(strict_types=1);

function hub_run_benchmark_case(PDO $db, string $case, ?string $packId = null, ?string $serviceKey = null): array
{
    $started = microtime(true);
    $serviceId = null;
    $mode = null;
    $status = 'pass';
    $error = null;
    $result = ['ok' => true, 'case' => $case];

    try {
        if ($packId !== null || $serviceKey !== null) {
            $result += hub_benchmark_l5_contract_case($db, $case, $packId, $serviceKey, $serviceId, $mode);
        } else {
            $result += match ($case) {
                'host_smoke' => [
                    'php_version' => PHP_VERSION,
                    'sqlite' => $db->query('SELECT sqlite_version()')->fetchColumn(),
                ],
                'pack_catalog_scan' => [
                    'pack_count' => count(hub_list_packs()),
                    'packs' => array_column(hub_list_packs(), 'id'),
                ],
                'hello_api' => hub_benchmark_hello_api($db, $serviceId, $mode),
                default => throw new InvalidArgumentException('Unknown benchmark case.'),
            };
        }
    } catch (Throwable $e) {
        $status = 'fail';
        $error = $e->getMessage();
        $result = ['ok' => false, 'case' => $case];
    }

    $elapsedMs = (int)round((microtime(true) - $started) * 1000);
    hub_save_benchmark_run($db, $case, $serviceId, $mode, $status, $elapsedMs, $result, $error);

    return [
        'ok' => $status === 'pass',
        'case' => $case,
        'elapsed_ms' => $elapsedMs,
        'status' => $status,
        'result' => $result,
        'error_message' => $error,
    ];
}

function hub_benchmark_l5_contract_case(PDO $db, string $caseId, ?string $packId, ?string $serviceKey, ?int &$serviceId, ?string &$mode): array
{
    $service = hub_benchmark_service($db, $packId, $serviceKey);
    if (!$service) {
        throw new RuntimeException('benchmark service not found.');
    }
    $pack = hub_get_pack((string)$service['pack_id']);
    if (!$pack || $pack['status'] !== 'ok') {
        throw new RuntimeException('benchmark pack is not available.');
    }
    $contract = hub_pack_l5_contract($pack['manifest']);
    $case = hub_l5_benchmark_case($contract, $caseId);
    if (!$case) {
        throw new RuntimeException('benchmark case not declared in l5_contract.');
    }

    $serviceId = (int)$service['id'];
    $mode = (string)($case['mode'] ?? $service['mode']);
    hub_set_service_enabled($db, $mode, true);

    $fixture = HUB_ROOT . '/' . ltrim((string)($case['fixture'] ?? ''), '/');
    if (!is_file($fixture)) {
        throw new RuntimeException('benchmark fixture missing.');
    }

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REQUEST_METHOD'] = strtoupper((string)($case['method'] ?? $contract['method'] ?? 'POST'));
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['CONTENT_LENGTH'] = (string)filesize($fixture);
    $_FILES = [
        'image' => [
            'name' => basename($fixture),
            'type' => 'image/png',
            'tmp_name' => $fixture,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fixture),
        ],
    ];
    $_POST = [];

    try {
        $response = hub_gateway_dispatch($db, $mode, static fn (): array => hub_gateway_json(200, [
            'ok' => true,
            'text' => '3waAIHub OCR mock',
            'blocks' => [['text' => '3waAIHub OCR mock', 'bbox' => [0, 0, 0, 0], 'confidence' => 1.0]],
            'mock' => true,
            'runtime_level' => (string)($pack['manifest']['runtime_level'] ?? ''),
        ]));
    } finally {
        $_SERVER = $oldServer;
        $_FILES = $oldFiles;
        $_POST = $oldPost;
    }

    $payload = json_decode((string)$response['body'], true);
    $expectedKeys = array_map('strval', $case['expected_keys'] ?? $contract['output']['required_keys'] ?? []);
    $missing = [];
    foreach ($expectedKeys as $key) {
        if (!is_array($payload) || !array_key_exists($key, $payload)) {
            $missing[] = $key;
        }
    }
    if ((int)$response['status'] !== 200 || $missing !== []) {
        throw new RuntimeException('benchmark contract check failed.');
    }

    return [
        'case_id' => $caseId,
        'pack_id' => (string)$pack['id'],
        'service_id' => $serviceId,
        'mode' => $mode,
        'http_status' => (int)$response['status'],
        'expected_keys_pass' => true,
        'runtime_level' => (string)($pack['manifest']['runtime_level'] ?? ''),
        'fixture' => (string)($case['fixture'] ?? ''),
    ];
}

function hub_benchmark_service(PDO $db, ?string $packId, ?string $serviceKey): ?array
{
    if ($serviceKey !== null && $serviceKey !== '') {
        return hub_get_service_by_key($db, $serviceKey);
    }
    if ($packId === null || $packId === '') {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM services WHERE pack_id = :pack_id ORDER BY id LIMIT 1');
    $stmt->execute([':pack_id' => $packId]);
    $service = $stmt->fetch();

    return $service ?: null;
}

function hub_pack_l5_contract(array $manifest): array
{
    return is_array($manifest['l5_contract'] ?? null) ? $manifest['l5_contract'] : [];
}

function hub_l5_benchmark_case(array $contract, string $caseId): ?array
{
    foreach (($contract['benchmark']['cases'] ?? []) as $case) {
        if (is_array($case) && (string)($case['id'] ?? '') === $caseId) {
            return $case;
        }
    }

    return null;
}

function hub_pack_api_contracts(): array
{
    $contracts = [];
    foreach (hub_list_packs() as $pack) {
        $contract = hub_pack_l5_contract($pack['manifest']);
        if ($contract === []) {
            continue;
        }
        $contracts[(string)$pack['id']] = [
            'pack' => $pack,
            'contract' => $contract,
        ];
    }

    return $contracts;
}

function hub_pack_l5_readiness(PDO $db, string $packId): array
{
    $pack = hub_get_pack($packId);
    if (!$pack || $pack['status'] !== 'ok') {
        throw new RuntimeException('Pack is not available.');
    }
    $manifest = $pack['manifest'];
    $contract = hub_pack_l5_contract($manifest);
    $caseIds = array_values(array_filter(array_map('strval', array_column($contract['benchmark']['cases'] ?? [], 'id'))));
    $latestPass = false;
    if ($caseIds !== []) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = $db->prepare("SELECT status FROM benchmark_runs WHERE benchmark_key IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute($caseIds);
        $latestPass = (string)($stmt->fetchColumn() ?: '') === 'pass';
    }

    $checks = [
        'has_l5_contract' => $contract !== [],
        'has_input_contract' => is_array($contract['input']['fields'] ?? null) && $contract['input']['fields'] !== [],
        'has_output_contract' => is_array($contract['output']['required_keys'] ?? null) && $contract['output']['required_keys'] !== [],
        'has_error_contract' => is_array($contract['errors'] ?? null) && $contract['errors'] !== [],
        'has_benchmark_cases' => $caseIds !== [],
        'has_api_examples' => is_file(HUB_ROOT . '/docs/api_examples.md'),
        'latest_benchmark_pass' => $latestPass,
        'has_runtime_level' => trim((string)($manifest['runtime_level'] ?? '')) !== '',
        'has_target_level' => trim((string)($manifest['target_level'] ?? '')) !== '',
        'real_inference_benchmark_passed' => false,
        'l4b_real_inference_complete' => in_array((string)($manifest['runtime_level'] ?? ''), ['L4b-real-inference', 'L5-benchmark-ready'], true),
    ];

    return [
        'pack' => $pack,
        'runtime_level' => (string)($manifest['runtime_level'] ?? ''),
        'target_level' => (string)($manifest['target_level'] ?? ''),
        'checks' => $checks,
        'pass_count' => count(array_filter($checks)),
        'total_count' => count($checks),
    ];
}

function hub_benchmark_hello_api(PDO $db, ?int &$serviceId, ?string &$mode): array
{
    $service = hub_get_service_by_mode($db, 'hello');
    if (!$service) {
        throw new RuntimeException('hello service not found.');
    }
    $serviceId = (int)$service['id'];
    $mode = 'hello';
    hub_set_service_enabled($db, 'hello', true);

    $response = hub_gateway_dispatch($db, 'hello', static fn (): array => hub_gateway_json(200, [
        'ok' => true,
        'service' => 'hello',
        'message' => '3waAIHub service is running',
    ]));
    if ((int)$response['status'] !== 200) {
        throw new RuntimeException('hello API failed.');
    }

    return ['mode' => 'hello', 'http_status' => 200];
}

function hub_save_benchmark_run(PDO $db, string $key, ?int $serviceId, ?string $mode, string $status, int $elapsedMs, array $result, ?string $error): void
{
    $stmt = $db->prepare(
        'INSERT INTO benchmark_runs (benchmark_key, service_id, mode, status, elapsed_ms, result_json, error_message, created_at)
         VALUES (:benchmark_key, :service_id, :mode, :status, :elapsed_ms, :result_json, :error_message, :created_at)'
    );
    $stmt->execute([
        ':benchmark_key' => $key,
        ':service_id' => $serviceId,
        ':mode' => $mode,
        ':status' => $status,
        ':elapsed_ms' => $elapsedMs,
        ':result_json' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':error_message' => $error,
        ':created_at' => hub_now(),
    ]);
}

function hub_list_benchmark_runs(PDO $db, int $limit = 50): array
{
    $stmt = $db->prepare(
        'SELECT br.*, s.name AS service_name, s.service_key, s.pack_id
         FROM benchmark_runs br
         LEFT JOIN services s ON s.id = br.service_id
         ORDER BY br.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
