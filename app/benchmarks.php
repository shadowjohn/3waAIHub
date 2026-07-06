<?php
declare(strict_types=1);

function hub_run_benchmark_case(PDO $db, string $case): array
{
    $started = microtime(true);
    $serviceId = null;
    $mode = null;
    $status = 'pass';
    $error = null;
    $result = ['ok' => true, 'case' => $case];

    try {
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
        'SELECT br.*, s.name AS service_name
         FROM benchmark_runs br
         LEFT JOIN services s ON s.id = br.service_id
         ORDER BY br.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
