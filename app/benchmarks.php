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

    $bodyJson = $case['body_json'] ?? null;
    $jsonBody = is_array($bodyJson) ? json_encode($bodyJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $fixture = HUB_ROOT . '/' . ltrim((string)($case['fixture'] ?? ''), '/');
    $hasFixture = trim((string)($case['fixture'] ?? '')) !== '';
    if ($hasFixture && !is_file($fixture)) {
        throw new RuntimeException('benchmark fixture missing.');
    }

    [$oldServer, $oldFiles, $oldPost] = [$_SERVER, $_FILES, $_POST];
    $_SERVER['REQUEST_METHOD'] = strtoupper((string)($case['method'] ?? $contract['method'] ?? 'POST'));
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['CONTENT_TYPE'] = (string)($case['content_type'] ?? $contract['content_type'] ?? '');
    $_SERVER['CONTENT_LENGTH'] = $jsonBody !== '' ? (string)strlen($jsonBody) : ($hasFixture ? (string)filesize($fixture) : '0');
    $_FILES = $hasFixture ? [
        'image' => [
            'name' => basename($fixture),
            'type' => 'image/png',
            'tmp_name' => $fixture,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($fixture),
        ],
    ] : [];
    $realInference = !empty($case['real_inference']);
    $form = is_array($case['form'] ?? null) ? array_map('strval', $case['form']) : [];
    if ($hasFixture && $realInference) {
        $form['real_inference'] = '1';
    }
    $_POST = $hasFixture ? $form : [];

    try {
        $response = hub_gateway_dispatch(
            $db,
            $mode,
            $realInference
                ? ($jsonBody !== '' ? static fn (array $service, int $timeoutSec): array => hub_benchmark_proxy_json($service, $timeoutSec, $jsonBody) : null)
                : static fn (): array => hub_gateway_json(200, hub_benchmark_mock_payload($pack['manifest'], is_array($bodyJson) ? $bodyJson : []))
        );
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
    $minBlocks = (int)($case['expected_min_blocks'] ?? 0);
    $blockCount = is_array($payload['blocks'] ?? null) ? count($payload['blocks']) : 0;
    $minDetections = (int)($case['expected_min_detections'] ?? 0);
    $detectionCount = is_array($payload['detections'] ?? null) ? count($payload['detections']) : 0;
    $maskCount = is_array($payload['masks'] ?? null) ? count($payload['masks']) : 0;
    $contractFailed = (int)$response['status'] !== 200 || $missing !== [] || $blockCount < $minBlocks || $detectionCount < $minDetections;
    if (array_key_exists('expected_mock', $case) && is_array($payload) && (bool)($payload['mock'] ?? null) !== (bool)$case['expected_mock']) {
        $contractFailed = true;
    }
    if (!empty($case['expected_text_non_empty']) && trim((string)($payload['text'] ?? '')) === '') {
        $contractFailed = true;
    }
    if (!empty($case['expected_text_not_equal_input']) && trim((string)($payload['text'] ?? '')) === trim((string)($bodyJson['text'] ?? ''))) {
        $contractFailed = true;
    }
    if (!empty($case['expected_cjk']) && !preg_match('/[\x{4E00}-\x{9FFF}]/u', (string)($payload['text'] ?? ''))) {
        $contractFailed = true;
    }
    if (isset($case['expected_model']) && (string)($payload['model'] ?? '') !== (string)$case['expected_model']) {
        $contractFailed = true;
    }
    if (isset($case['expected_target_lang']) && (string)($payload['target_lang'] ?? '') !== (string)$case['expected_target_lang']) {
        $contractFailed = true;
    }
    if (isset($payload['elapsed_ms']) && (int)$payload['elapsed_ms'] < 0) {
        $contractFailed = true;
    }
    if (!empty($case['expected_elapsed_ms_positive']) && (int)($payload['elapsed_ms'] ?? 0) <= 0) {
        $contractFailed = true;
    }
    if (!empty($case['expected_model_checkpoint']) && trim((string)($payload['model']['checkpoint'] ?? '')) === '') {
        $contractFailed = true;
    }
    if (isset($case['expected_mask_key']) && $maskCount > 0 && !array_key_exists((string)$case['expected_mask_key'], $payload['masks'][0] ?? [])) {
        $contractFailed = true;
    }
    $device = is_array($payload['device'] ?? null) ? $payload['device'] : [];
    if ($contractFailed) {
        throw new RuntimeException('benchmark contract check failed.');
    }

    return [
        'case_id' => $caseId,
        'pack_id' => (string)$pack['id'],
        'service_id' => $serviceId,
        'mode' => $mode,
        'http_status' => (int)$response['status'],
        'expected_keys_pass' => true,
        'real_inference' => $realInference,
        'block_count' => $blockCount,
        'detection_count' => $detectionCount,
        'mask_count' => $maskCount,
        'mock' => is_array($payload) ? ($payload['mock'] ?? null) : null,
        'model_checkpoint' => is_array($payload['model'] ?? null) ? (string)($payload['model']['checkpoint'] ?? '') : '',
        'text_length' => is_array($payload) ? strlen((string)($payload['text'] ?? '')) : 0,
        'requested_device' => (string)($device['requested'] ?? ''),
        'effective_device' => (string)($device['effective'] ?? ''),
        'runtime_level' => (string)($pack['manifest']['runtime_level'] ?? ''),
        'fixture' => (string)($case['fixture'] ?? ''),
    ];
}

function hub_benchmark_proxy_json(array $service, int $timeoutSec, string $jsonBody): array
{
    $ch = curl_init((string)$service['internal_url']);
    if ($ch === false) {
        return hub_gateway_json(502, ['ok' => false, 'error' => 'curl unavailable']);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $jsonBody,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => max(1, $timeoutSec),
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $errno = curl_errno($ch);
        curl_close($ch);
        return match ($errno) {
            CURLE_OPERATION_TIMEDOUT => hub_gateway_error(504, 'gateway_timeout', 'service gateway timeout'),
            CURLE_COULDNT_CONNECT => hub_gateway_error(503, 'service_unavailable', 'service is unavailable'),
            default => hub_gateway_error(502, 'proxy_error', 'service proxy error'),
        };
    }

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 502;
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE) ?: 0;
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
    $body = substr($raw, $headerSize);
    curl_close($ch);

    return ['status' => $status, 'headers' => ['Content-Type: ' . $contentType], 'body' => $body];
}

function hub_benchmark_mock_payload(array $manifest, array $input = []): array
{
    $runtimeLevel = (string)($manifest['runtime_level'] ?? '');
    if (($manifest['id'] ?? '') === 'hello') {
        return [
            'ok' => true,
            'service' => 'hello',
            'message' => '3waAIHub service is running',
        ];
    }
    if (($manifest['id'] ?? '') === 'yolo') {
        return [
            'ok' => true,
            'mock' => true,
            'runtime_level' => $runtimeLevel,
            'detections' => [],
        ];
    }
    if (($manifest['id'] ?? '') === 'sam3') {
        return [
            'ok' => true,
            'mock' => true,
            'runtime_level' => $runtimeLevel,
            'prompt_type' => 'auto',
            'output_format' => 'metadata',
            'masks' => [],
            'elapsed_ms' => 0,
        ];
    }
    if (($manifest['id'] ?? '') === 'translate-gemma12b') {
        return [
            'ok' => true,
            'mock' => true,
            'runtime_level' => $runtimeLevel,
            'model' => 'translategemma:12b-it-q4_K_M',
            'source_lang' => (string)($input['source_lang'] ?? 'auto'),
            'target_lang' => (string)($input['target_lang'] ?? 'zh-TW'),
            'text' => 'mock translation',
            'elapsed_ms' => 0,
        ];
    }

    return [
        'ok' => true,
        'text' => '3waAIHub OCR mock',
        'blocks' => [['text' => '3waAIHub OCR mock', 'bbox' => [0, 0, 0, 0], 'confidence' => 1.0]],
        'mock' => true,
        'runtime_level' => $runtimeLevel,
        'device' => ['requested' => 'auto', 'effective' => 'cpu', 'fallback_to_cpu' => true],
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
    $realInferencePass = false;
    if ($caseIds !== []) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $stmt = $db->prepare("SELECT status FROM benchmark_runs WHERE benchmark_key IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute($caseIds);
        $latestPass = (string)($stmt->fetchColumn() ?: '') === 'pass';
    }
    $realCaseIds = [];
    foreach (($contract['benchmark']['cases'] ?? []) as $case) {
        if (is_array($case) && !empty($case['real_inference']) && !empty($case['id'])) {
            $realCaseIds[] = (string)$case['id'];
        }
    }
    if ($contract !== [] && $realCaseIds === []) {
        $realInferencePass = true;
    }
    if ($realCaseIds !== []) {
        $placeholders = implode(',', array_fill(0, count($realCaseIds), '?'));
        $stmt = $db->prepare("SELECT status FROM benchmark_runs WHERE benchmark_key IN ({$placeholders}) ORDER BY id DESC LIMIT 1");
        $stmt->execute($realCaseIds);
        $realInferencePass = (string)($stmt->fetchColumn() ?: '') === 'pass';
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
        'real_inference_benchmark_passed' => $realInferencePass,
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
