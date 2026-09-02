<?php
declare(strict_types=1);

const HUB_SERVICE_HEALTH_MAX_AGE_SECONDS = 150;

function hub_service_health_contracts(): array
{
    return [
        'bioclip' => [
            'model' => 'BioCLIP-2',
            'required_modes' => ['bioclip'],
        ],
        'photo' => [
            'model' => 'gemma4-12b',
            'required_modes' => ['photo_upload', 'photo'],
        ],
    ];
}

function hub_service_health_permission_modes(): array
{
    return ['service_health' => '服務可用性預判'];
}

function hub_service_health_requested_modes(mixed $value): ?array
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $contracts = hub_service_health_contracts();
    $requested = [];
    foreach (explode(',', $value) as $mode) {
        $mode = trim($mode);
        if ($mode === '' || !isset($contracts[$mode]) || isset($requested[$mode])) {
            return null;
        }
        $requested[$mode] = true;
    }

    return array_keys($requested);
}

function hub_service_health_authenticate(PDO $db, string $clientIp, ?string $providedToken, array $requestedModes): array
{
    $plainToken = $providedToken ?? hub_bearer_token_from_request();
    $auth = hub_authenticate_api_token($db, $clientIp, $plainToken);
    if (empty($auth['ok'])) {
        return $auth;
    }
    $tokenId = (int)($auth['context']['token_id'] ?? 0);
    $required = ['service_health'];
    foreach ($requestedModes as $mode) {
        foreach ((array)(hub_service_health_contracts()[$mode]['required_modes'] ?? []) as $requiredMode) {
            $required[$requiredMode] = $requiredMode;
        }
    }
    foreach ($required as $requiredMode) {
        if (!hub_api_token_mode_allowed($db, $tokenId, (string)$requiredMode)) {
            return [
                'ok' => false,
                'context' => $auth['context'],
                'response' => hub_gateway_error(403, 'token_mode_denied', 'token is not authorized for the requested service health'),
            ];
        }
    }

    return $auth;
}

function hub_service_health_local_payload(PDO $db, array $requestedModes): array
{
    $snapshot = hub_service_health_read_snapshot();
    $snapshot ??= [];
    foreach ($requestedModes as $mode) {
        $service = hub_service_health_logical_service($db, $mode);
        $entry = $service === null
            ? ['ready' => false, 'runtime_status' => 'stopped', 'reason' => 'service_not_found']
            : hub_service_health_precheck($service);
        if ($entry !== []) {
            $snapshot['services'][$mode] = $entry + ['model' => hub_service_health_contracts()[$mode]['model']];
        }
    }

    return hub_service_health_public_payload($snapshot, $requestedModes);
}

function hub_service_health_snapshot_path(): string
{
    return HUB_DATA_DIR . '/cache/service_health.json';
}

function hub_service_health_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'http' || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

    return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
}

function hub_service_health_response_ok(int $status, string $body): bool
{
    if ($status < 200 || $status >= 400) {
        return false;
    }
    $payload = json_decode($body, true);

    return is_array($payload)
        && (($payload['ok'] ?? null) === true)
        && (($payload['ready'] ?? null) !== false);
}

function hub_service_health_runtime_status(array $service): string
{
    return (string)($service['runtime_status'] ?? '') === 'running' ? 'running' : 'stopped';
}

function hub_service_health_precheck(array $service): array
{
    if ((int)($service['enabled'] ?? 0) !== 1) {
        return ['ready' => false, 'runtime_status' => hub_service_health_runtime_status($service), 'reason' => 'service_disabled'];
    }
    if ((string)($service['install_status'] ?? '') !== 'installed') {
        return ['ready' => false, 'runtime_status' => hub_service_health_runtime_status($service), 'reason' => 'service_not_installed'];
    }
    if ((string)($service['runtime_status'] ?? '') !== 'running') {
        return ['ready' => false, 'runtime_status' => 'stopped', 'reason' => 'runtime_not_ready'];
    }
    if (hub_service_is_internal_task($service)) {
        return ['ready' => true, 'runtime_status' => 'running', 'reason' => ''];
    }
    if (!hub_service_health_url_allowed((string)($service['health_url'] ?? ''))) {
        return ['ready' => false, 'runtime_status' => 'running', 'reason' => 'health_check_failed'];
    }

    return [];
}

function hub_service_health_probe_services(array $services): array
{
    if ($services === [] || !function_exists('curl_multi_init')) {
        return [];
    }
    $deadline = microtime(true) + 2.0;
    $multi = curl_multi_init();
    $handles = [];
    $bodies = [];
    foreach (array_slice($services, 0, 128) as $service) {
        $id = (int)($service['id'] ?? 0);
        if ($id < 1 || microtime(true) >= $deadline) {
            continue;
        }
        $handle = curl_init((string)$service['health_url']);
        if ($handle === false) {
            continue;
        }
        $bodies[$id] = '';
        $configured = curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT_MS => 500,
            CURLOPT_TIMEOUT_MS => 2000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PRIVATE => (string)$id,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bodies, $id): int {
                if (strlen($bodies[$id]) + strlen($chunk) > 65536) {
                    return 0;
                }
                $bodies[$id] .= $chunk;

                return strlen($chunk);
            },
        ]);
        if (!$configured || curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
            unset($bodies[$id]);
            curl_close($handle);
            continue;
        }
        $handles[] = $handle;
    }

    do {
        $result = curl_multi_exec($multi, $running);
        if ($result !== CURLM_OK || $running === 0 || microtime(true) >= $deadline) {
            break;
        }
        if (curl_multi_select($multi, min(0.05, max(0.001, $deadline - microtime(true)))) === -1) {
            usleep(10000);
        }
    } while (true);

    $results = [];
    while (($info = curl_multi_info_read($multi)) !== false) {
        if (($info['msg'] ?? null) !== CURLMSG_DONE) {
            continue;
        }
        $handle = $info['handle'] ?? null;
        if ($handle === null) {
            continue;
        }
        $id = (int)curl_getinfo($handle, CURLINFO_PRIVATE);
        if ($id < 1) {
            continue;
        }
        $results[$id] = [
            'status' => (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => $bodies[$id] ?? '',
            'curl_result' => (int)($info['result'] ?? CURLE_FAILED_INIT),
        ];
    }
    foreach ($handles as $handle) {
        $id = (int)curl_getinfo($handle, CURLINFO_PRIVATE);
        if ($id > 0 && !isset($results[$id])) {
            $results[$id] = ['status' => 0, 'body' => '', 'curl_result' => CURLE_OPERATION_TIMEDOUT];
        }
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $results;
}

function hub_service_health_entry_from_probe(array $probe): array
{
    $curlResult = (int)($probe['curl_result'] ?? CURLE_FAILED_INIT);
    if ($curlResult === CURLE_OPERATION_TIMEDOUT) {
        return ['ready' => false, 'runtime_status' => 'running', 'reason' => 'health_timeout'];
    }
    if ($curlResult !== CURLE_OK || !hub_service_health_response_ok((int)($probe['status'] ?? 0), (string)($probe['body'] ?? ''))) {
        return ['ready' => false, 'runtime_status' => 'running', 'reason' => 'health_check_failed'];
    }

    return ['ready' => true, 'runtime_status' => 'running', 'reason' => ''];
}

function hub_service_health_logical_service(PDO $db, string $mode): ?array
{
    return match ($mode) {
        'bioclip' => hub_get_service_by_mode($db, 'bioclip'),
        'photo' => hub_get_service_by_key($db, (string)hub_photo_settings($db)['vision_service_key']),
        default => null,
    };
}

function hub_service_health_write_snapshot(PDO $db, ?callable $probe = null): array
{
    $checks = [];
    $pending = [];
    foreach (hub_list_services($db) as $service) {
        $id = (int)($service['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $entry = hub_service_health_precheck($service);
        if ($entry === []) {
            $pending[] = $service;
            continue;
        }
        $checks[$id] = $entry;
    }
    $probes = $probe === null ? hub_service_health_probe_services($pending) : $probe($pending);
    $probes = is_array($probes) ? $probes : [];
    foreach ($pending as $service) {
        $id = (int)$service['id'];
        $checks[$id] = hub_service_health_entry_from_probe(is_array($probes[$id] ?? null) ? $probes[$id] : []);
    }

    $services = [];
    foreach (hub_service_health_contracts() as $mode => $contract) {
        $service = hub_service_health_logical_service($db, $mode);
        $entry = $service === null
            ? ['ready' => false, 'runtime_status' => 'stopped', 'reason' => 'service_not_found']
            : ($checks[(int)$service['id']] ?? ['ready' => false, 'runtime_status' => 'stopped', 'reason' => 'runtime_not_ready']);
        $services[$mode] = $entry + ['model' => $contract['model']];
    }
    $snapshot = [
        'checked_at' => date(DATE_ATOM),
        'services' => $services,
        'service_checks' => $checks,
    ];
    $path = hub_service_health_snapshot_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('service health snapshot directory is unavailable');
    }
    $temporary = $dir . '/.service_health_' . bin2hex(random_bytes(12)) . '.tmp';
    $encoded = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $encoded . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('service health snapshot write failed');
    }
    @chmod($temporary, 0640);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('service health snapshot replace failed');
    }

    return $snapshot;
}

function hub_service_health_read_snapshot(): ?array
{
    $path = hub_service_health_snapshot_path();
    if (!is_file($path) || is_link($path) || filesize($path) > 262144) {
        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return null;
    }
    try {
        $snapshot = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($snapshot) && is_array($snapshot['services'] ?? null) ? $snapshot : null;
}

function hub_service_health_public_payload(array $snapshot, array $requestedModes, ?int $now = null): array
{
    $now ??= time();
    $contracts = hub_service_health_contracts();
    $checkedAt = trim((string)($snapshot['checked_at'] ?? ''));
    $checkedTimestamp = $checkedAt === '' ? false : strtotime($checkedAt);
    $fresh = $checkedTimestamp !== false
        && $checkedTimestamp <= $now
        && ($now - $checkedTimestamp) <= HUB_SERVICE_HEALTH_MAX_AGE_SECONDS;
    $services = [];

    foreach ($requestedModes as $mode) {
        if (!is_string($mode) || !isset($contracts[$mode])) {
            continue;
        }
        $source = is_array($snapshot['services'][$mode] ?? null) ? $snapshot['services'][$mode] : [];
        $runtimeStatus = (string)($source['runtime_status'] ?? 'stopped');
        if (!in_array($runtimeStatus, ['running', 'stopped'], true)) {
            $runtimeStatus = 'stopped';
        }
        $ready = $fresh && $runtimeStatus === 'running' && ($source['ready'] ?? null) === true;
        $reason = $ready ? '' : trim((string)($source['reason'] ?? ''));
        if (!$ready && !in_array($reason, [
            'service_not_found',
            'service_disabled',
            'service_not_installed',
            'runtime_not_ready',
            'health_check_failed',
            'health_timeout',
        ], true)) {
            $reason = 'runtime_not_ready';
        }
        if (!$fresh) {
            $reason = 'runtime_not_ready';
        }

        $services[$mode] = [
            'ready' => $ready,
            'runtime_status' => $runtimeStatus,
            'reason' => $reason,
            'model' => $contracts[$mode]['model'],
        ];
    }

    return [
        'ok' => true,
        'checked_at' => $checkedAt,
        'services' => $services,
    ];
}
