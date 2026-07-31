#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

const HUB_VOXCPM2_CLUSTER_CONNECT_TIMEOUT_SECONDS = 10;
const HUB_VOXCPM2_CLUSTER_REQUEST_TIMEOUT_SECONDS = 180;
const HUB_VOXCPM2_CLUSTER_POLL_TIMEOUT_SECONDS = 7200;
const HUB_VOXCPM2_CLUSTER_TOTAL_TIMEOUT_SECONDS = 9000;
const HUB_VOXCPM2_CLUSTER_CLEANUP_TIMEOUT_SECONDS = 30;
const HUB_VOXCPM2_CLUSTER_FFPROBE_TIMEOUT_SECONDS = 20;
const HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES = 1048576;
const HUB_VOXCPM2_CLUSTER_AUDIO_MAX_BYTES = 67108864;
const HUB_VOXCPM2_CLUSTER_METADATA_MAX_BYTES = 4194304;
const HUB_VOXCPM2_CLUSTER_REFERENCE_MAX_BYTES = 104857600;
const HUB_VOXCPM2_CLUSTER_PROCESS_OUTPUT_MAX_BYTES = 65536;
const HUB_VOXCPM2_CLUSTER_HEADER_MAX_BYTES = 65536;
const HUB_VOXCPM2_CLUSTER_HEADER_MAX_COUNT = 256;
const HUB_VOXCPM2_CLUSTER_SUCCESS_LINE = '{"ok":true,"profile_prepared":true,"ultimate_clone":true,"audio_valid":true,"gpu":true,"artifacts_acknowledged":true}';

final class HubVoxCpm2ClusterAcceptanceFailure extends RuntimeException
{
    public function __construct(private readonly string $stableCode)
    {
        parent::__construct($stableCode);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }
}

function hub_voxcpm2_cluster_acceptance_fail(string $code): never
{
    throw new HubVoxCpm2ClusterAcceptanceFailure($code);
}

function hub_voxcpm2_cluster_acceptance_success_line(): string
{
    return HUB_VOXCPM2_CLUSTER_SUCCESS_LINE . PHP_EOL;
}

function hub_voxcpm2_cluster_acceptance_failure_line(string $code): string
{
    $messages = [
        'test_environment_refused' => 'Acceptance cannot run in a test environment.',
        'config_invalid' => 'Acceptance configuration is invalid.',
        'dependency_missing' => 'A required local dependency is unavailable.',
        'request_failed' => 'Cluster request failed.',
        'response_invalid' => 'Cluster response was invalid.',
        'task_failed' => 'Cluster task did not complete successfully.',
        'timeout' => 'Cluster acceptance timed out.',
        'profile_unusable' => 'Prepared profile was not usable.',
        'artifact_invalid' => 'Cluster artifact validation failed.',
        'ack_failed' => 'Cluster artifact acknowledgement failed.',
        'cleanup_failed' => 'Cluster acceptance cleanup failed.',
        'interrupted' => 'Cluster acceptance was interrupted.',
        'internal_error' => 'Cluster acceptance failed.',
    ];
    if (!isset($messages[$code])) {
        $code = 'internal_error';
    }

    return json_encode(
        ['ok' => false, 'error' => $code, 'message' => $messages[$code]],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
}

function hub_voxcpm2_cluster_acceptance_main(
    array $argv,
    ?callable $transport = null,
    ?callable $probe = null,
    ?array $environment = null,
): int {
    if (defined('HUB_TESTING')
        || getenv('AIHUB_TEST_DB') !== false
        || getenv('AIHUB_TEST_DATA_DIR') !== false
    ) {
        echo hub_voxcpm2_cluster_acceptance_failure_line('test_environment_refused');
        return 1;
    }

    set_error_handler(static function (int $severity): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new ErrorException('runtime warning', 0, $severity);
    });
    $signals = [];
    $failureCode = null;
    try {
        if (count($argv) !== 1) {
            hub_voxcpm2_cluster_acceptance_fail('config_invalid');
        }
        if (!function_exists('curl_init') || !class_exists('CURLFile') || !function_exists('proc_open')) {
            hub_voxcpm2_cluster_acceptance_fail('dependency_missing');
        }
        $config = hub_voxcpm2_cluster_acceptance_config($environment);
        $transport ??= 'hub_voxcpm2_cluster_acceptance_http';
        $probe ??= 'hub_voxcpm2_cluster_acceptance_ffprobe';
        $signals = hub_voxcpm2_cluster_acceptance_install_signal_handlers();
        hub_voxcpm2_cluster_acceptance_execute($config, $transport, $probe);
    } catch (Throwable $error) {
        $failureCode = $error instanceof HubVoxCpm2ClusterAcceptanceFailure
            ? $error->stableCode()
            : 'internal_error';
    } finally {
        $failureCode = hub_voxcpm2_cluster_acceptance_restore_signal_handlers_safely($signals, $failureCode);
        restore_error_handler();
    }

    if ($failureCode !== null) {
        echo hub_voxcpm2_cluster_acceptance_failure_line($failureCode);
        return 1;
    }

    echo hub_voxcpm2_cluster_acceptance_success_line();
    return 0;
}

function hub_voxcpm2_cluster_acceptance_config(?array $environment = null): array
{
    $read = static function (string $name) use ($environment): string {
        $value = $environment === null ? getenv($name) : ($environment[$name] ?? '');
        return is_string($value) ? trim($value) : '';
    };
    $baseUrl = $read('AIHUB_VOXCPM2_CLUSTER_BASE_URL');
    $token = $read('AIHUB_VOXCPM2_CLUSTER_TOKEN');
    $reference = $read('AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV');
    $promptText = $read('AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT');
    $targetText = $read('AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT');
    if ($baseUrl === '' || $token === '' || $reference === '' || $promptText === '' || $targetText === ''
        || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7F]/', $token) === 1
        || !hub_voxcpm2_cluster_acceptance_text_valid($promptText, 20000)
        || !hub_voxcpm2_cluster_acceptance_text_valid($targetText, 4096)
    ) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }

    $referencePath = realpath($reference);
    $referenceSize = $referencePath === false ? false : filesize($referencePath);
    if ($referencePath === false || !is_file($referencePath) || !is_readable($referencePath)
        || $referenceSize === false || $referenceSize < 12 || $referenceSize > HUB_VOXCPM2_CLUSTER_REFERENCE_MAX_BYTES
        || !hub_voxcpm2_cluster_acceptance_wave_header_valid($referencePath)
    ) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }
    $referenceSha256 = hash_file('sha256', $referencePath);
    $normalizedInput = hub_voxcpm2_cluster_acceptance_normalized_input($targetText);
    if (!is_string($referenceSha256) || preg_match('/\A[a-f0-9]{64}\z/', $referenceSha256) !== 1
        || $normalizedInput === null
    ) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }

    return hub_voxcpm2_cluster_acceptance_base_url($baseUrl) + [
        'token' => $token,
        'reference_wav' => $referencePath,
        'prompt_text' => $promptText,
        'target_text' => $targetText,
        'reference_audio_sha256' => $referenceSha256,
        'prompt_text_sha256' => hash('sha256', $promptText),
        'normalized_input' => $normalizedInput,
        'normalized_input_sha256' => hash('sha256', $normalizedInput),
    ];
}

function hub_voxcpm2_cluster_acceptance_text_valid(string $value, int $maxBytes): bool
{
    return $value !== ''
        && strlen($value) <= $maxBytes
        && preg_match('//u', $value) === 1
        && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1;
}

function hub_voxcpm2_cluster_acceptance_normalized_input(string $text): ?string
{
    $normalized = preg_replace('/(*UCP)\s+/u', ' ', $text);
    if (!is_string($normalized)) {
        return null;
    }
    $normalized = trim($normalized, ' ');

    return $normalized !== '' ? $normalized : null;
}

function hub_voxcpm2_cluster_acceptance_canonical_json(mixed $value): ?string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }
        ksort($item, SORT_STRING);
        foreach ($item as $key => $nested) {
            $item[$key] = $normalize($nested);
        }

        return $item;
    };
    try {
        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_LINE_TERMINATORS
                | JSON_THROW_ON_ERROR
        );
    } catch (JsonException) {
        return null;
    }
}

function hub_voxcpm2_cluster_acceptance_exact_keys(array $value, array $expected): bool
{
    $keys = array_keys($value);
    sort($keys, SORT_STRING);
    sort($expected, SORT_STRING);

    return $keys === $expected;
}

function hub_voxcpm2_cluster_acceptance_exact_value(mixed $actual, mixed $expected): bool
{
    $actualJson = hub_voxcpm2_cluster_acceptance_canonical_json($actual);
    $expectedJson = hub_voxcpm2_cluster_acceptance_canonical_json($expected);

    return $actualJson !== null
        && $expectedJson !== null
        && hash_equals($expectedJson, $actualJson);
}

function hub_voxcpm2_cluster_acceptance_utf8_length(string $value): ?int
{
    if (preg_match('//u', $value) !== 1) {
        return null;
    }
    $matched = preg_match_all('/./us', $value, $matches);

    return $matched === false ? null : $matched;
}

function hub_voxcpm2_cluster_acceptance_chunk_seed(string $chunkId): array
{
    $sha256 = hash('sha256', '42' . $chunkId);
    $seed = (int)(hexdec(substr($sha256, 8, 8)) % 2147483648);

    return ['sha256' => $sha256, 'seed' => $seed];
}

function hub_voxcpm2_cluster_acceptance_base_url(string $value): array
{
    if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }
    $parts = parse_url($value);
    if (!is_array($parts)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
    ) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));
    $port = $parts['port'] ?? null;
    if (!in_array($scheme, ['http', 'https'], true)
        || !hub_voxcpm2_cluster_acceptance_host_valid($host)
        || ($port !== null && (!is_int($port) || $port < 1 || $port > 65535))
        || ($scheme === 'http' && !hub_voxcpm2_cluster_acceptance_private_http_host($host))
    ) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }

    $path = (string)($parts['path'] ?? '');
    if ($path !== '' && (!str_starts_with($path, '/') || str_contains($path, '\\')
        || str_contains($path, '%') || str_contains($path, '//')
        || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
    )) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            hub_voxcpm2_cluster_acceptance_fail('config_invalid');
        }
    }
    $trimmedPath = rtrim($path, '/');
    if ($trimmedPath === '') {
        $apiPath = '/cluster_api.php';
    } elseif (str_ends_with($trimmedPath, '/cluster_api.php')) {
        $apiPath = $trimmedPath;
    } elseif (str_contains(basename($trimmedPath), '.php')) {
        hub_voxcpm2_cluster_acceptance_fail('config_invalid');
    } else {
        $apiPath = $trimmedPath . '/cluster_api.php';
    }

    $hostForUrl = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $host . ']' : $host;
    $authority = $hostForUrl . ($port === null ? '' : ':' . $port);

    return [
        'api_url' => $scheme . '://' . $authority . $apiPath,
        'scheme' => $scheme,
        'host' => $host,
        'port' => hub_voxcpm2_cluster_acceptance_effective_port($scheme, $port),
        'api_path' => $apiPath,
    ];
}

function hub_voxcpm2_cluster_acceptance_host_valid(string $host): bool
{
    if ($host === '') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return true;
    }
    if (strlen($host) > 253) {
        return false;
    }
    foreach (explode('.', $host) as $label) {
        if ($label === '' || strlen($label) > 63
            || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/i', $label) !== 1
        ) {
            return false;
        }
    }

    return true;
}

function hub_voxcpm2_cluster_acceptance_private_http_host(string $host): bool
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $octets = array_map('intval', explode('.', $host));
        return $octets[0] === 10
            || ($octets[0] === 172 && $octets[1] >= 16 && $octets[1] <= 31)
            || ($octets[0] === 192 && $octets[1] === 168)
            || $octets[0] === 127;
    }
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
        return false;
    }

    return $host === '::1' || str_starts_with($host, 'fc') || str_starts_with($host, 'fd');
}

function hub_voxcpm2_cluster_acceptance_effective_port(string $scheme, mixed $port): int
{
    if ($port === null) {
        return $scheme === 'https' ? 443 : 80;
    }

    return is_int($port) && $port >= 1 && $port <= 65535 ? $port : -1;
}

function hub_voxcpm2_cluster_acceptance_query(string $query): array
{
    $values = [];
    foreach (explode('&', $query) as $pair) {
        if ($pair === '' || !str_contains($pair, '=')) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        [$rawKey, $rawValue] = explode('=', $pair, 2);
        if ($rawKey === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $rawKey . $rawValue) === 1) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        $key = rawurldecode($rawKey);
        $decoded = rawurldecode($rawValue);
        if ($key === '' || isset($values[$key]) || str_contains($key, '[') || str_contains($key, ']')
            || preg_match('/[\x00-\x1F\x7F]/', $key . $decoded) === 1
        ) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        $values[$key] = $decoded;
    }

    return $values;
}

function hub_voxcpm2_cluster_acceptance_task_id(mixed $value): ?string
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $taskId = (string)$value;

    return preg_match('/\Aroute_(?:[a-f0-9]{32}|[a-f0-9]{34})\z/', $taskId) === 1 ? $taskId : null;
}

function hub_voxcpm2_cluster_acceptance_artifact_id(mixed $value): ?string
{
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    $artifactId = (string)$value;

    return preg_match('/\A[1-9][0-9]{0,17}\z/', $artifactId) === 1 ? $artifactId : null;
}

function hub_voxcpm2_cluster_acceptance_followup_url(
    array $config,
    mixed $value,
    string $mode,
    string $taskId,
    ?string $artifactId = null,
): string {
    $allowedModes = [
        'cluster_task_status',
        'cluster_task_result',
        'cluster_task_log',
        'cluster_task_cancel',
        'cluster_artifact',
        'cluster_task_artifacts_ack',
    ];
    if (!in_array($mode, $allowedModes, true)
        || hub_voxcpm2_cluster_acceptance_task_id($taskId) === null
        || ($artifactId !== null && $artifactId !== '{artifact_id}'
            && hub_voxcpm2_cluster_acceptance_artifact_id($artifactId) === null)
        || !is_string($value) || $value === '' || strlen($value) > 2048
        || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
    ) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }

    $parts = parse_url($value);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || !is_string($parts['query'] ?? null) || $parts['query'] === ''
    ) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    $hasOrigin = isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']);
    if ($hasOrigin && (
        strtolower((string)($parts['scheme'] ?? '')) !== (string)$config['scheme']
        || strtolower(trim((string)($parts['host'] ?? ''), '[]')) !== (string)$config['host']
        || hub_voxcpm2_cluster_acceptance_effective_port(
            strtolower((string)($parts['scheme'] ?? '')),
            $parts['port'] ?? null
        ) !== (int)$config['port']
    )) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    $path = (string)($parts['path'] ?? '');
    if ($path !== '' && $path !== (string)$config['api_path'] && $path !== basename((string)$config['api_path'])) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }

    $query = hub_voxcpm2_cluster_acceptance_query((string)$parts['query']);
    $expected = ['mode' => $mode, 'task_id' => $taskId];
    if ($artifactId !== null) {
        $expected['artifact_id'] = $artifactId;
    }
    if (count($query) !== count($expected)) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    foreach ($expected as $key => $expectedValue) {
        if (!isset($query[$key]) || !hash_equals($expectedValue, $query[$key])) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
    }

    return (string)$config['api_url'] . '?' . (string)$parts['query'];
}

function hub_voxcpm2_cluster_acceptance_task_links(
    array $config,
    array $payload,
    string $taskId,
    bool $requireAcknowledgement,
): array {
    $links = [];
    foreach ([
        'status_url' => ['cluster_task_status', null],
        'result_url' => ['cluster_task_result', null],
        'log_url' => ['cluster_task_log', null],
        'cancel_url' => ['cluster_task_cancel', null],
        'artifact_url_template' => ['cluster_artifact', '{artifact_id}'],
    ] as $name => [$mode, $artifactId]) {
        $links[$name] = hub_voxcpm2_cluster_acceptance_followup_url(
            $config,
            $payload[$name] ?? null,
            $mode,
            $taskId,
            $artifactId
        );
    }
    if ($requireAcknowledgement) {
        $links['ack_url_template'] = hub_voxcpm2_cluster_acceptance_followup_url(
            $config,
            $payload['ack_url_template'] ?? null,
            'cluster_task_artifacts_ack',
            $taskId,
            '{artifact_id}'
        );
    }

    return $links;
}

function hub_voxcpm2_cluster_acceptance_template_url(
    array $config,
    string $template,
    string $mode,
    string $taskId,
    string $artifactId,
): string {
    if (substr_count($template, '{artifact_id}') !== 1
        || hub_voxcpm2_cluster_acceptance_artifact_id($artifactId) === null
    ) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    $url = str_replace('{artifact_id}', rawurlencode($artifactId), $template);
    hub_voxcpm2_cluster_acceptance_followup_url($config, $url, $mode, $taskId, $artifactId);

    return $url;
}

function hub_voxcpm2_cluster_acceptance_execute(
    array $config,
    callable $transport,
    callable $probe,
    ?callable $sleeper = null,
): array {
    $sleeper ??= static function (int $microseconds): void {
        usleep($microseconds);
    };
    $deadline = microtime(true) + HUB_VOXCPM2_CLUSTER_TOTAL_TIMEOUT_SECONDS;
    $profileTaskId = null;
    $temporaryDirectory = null;
    $primaryFailure = null;
    $cleanupFailed = false;
    $result = null;

    try {
        $prepared = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'POST',
            (string)$config['api_url'] . '?mode=voice_generate',
            [
                'operation' => 'profile_prepare',
                'profile_name' => 'VoxCPM2 Cluster Acceptance',
                'consent_type' => 'self_recorded',
                'prompt_text' => (string)$config['prompt_text'],
                'transcript_confirmed' => '1',
                'expires_in_seconds' => '3600',
                'reference_wav' => new CURLFile((string)$config['reference_wav'], 'audio/wav', 'reference.wav'),
            ],
            $deadline
        );
        $profileTaskId = hub_voxcpm2_cluster_acceptance_task_id($prepared['task_id'] ?? null);
        if (($prepared['ok'] ?? null) !== true || $profileTaskId === null) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        $profileLinks = hub_voxcpm2_cluster_acceptance_task_links($config, $prepared, $profileTaskId, false);
        hub_voxcpm2_cluster_acceptance_poll(
            $config,
            $transport,
            $profileTaskId,
            $profileLinks['status_url'],
            $deadline,
            $sleeper
        );
        $profileResult = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'GET',
            $profileLinks['result_url'],
            '',
            $deadline
        );
        if (!hub_voxcpm2_cluster_acceptance_profile_result_valid($profileResult, $profileTaskId, $config)) {
            hub_voxcpm2_cluster_acceptance_fail('profile_unusable');
        }

        $profile = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'POST',
            (string)$config['api_url'] . '?mode=voice_generate',
            hub_voxcpm2_cluster_acceptance_json_encode([
                'operation' => 'profile_status',
                'voice_profile_task_id' => $profileTaskId,
            ]),
            $deadline,
            true
        );
        if (!hub_voxcpm2_cluster_acceptance_profile_usable($profile, $config)) {
            hub_voxcpm2_cluster_acceptance_fail('profile_unusable');
        }

        $submitted = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'POST',
            (string)$config['api_url'] . '?mode=voice_generate',
            hub_voxcpm2_cluster_acceptance_json_encode([
                'mode' => 'ultimate_clone',
                'voice_profile_task_id' => $profileTaskId,
                'text' => (string)$config['target_text'],
                'waveform_preview' => false,
            ]),
            $deadline,
            true
        );
        $taskId = hub_voxcpm2_cluster_acceptance_task_id($submitted['task_id'] ?? null);
        if (($submitted['ok'] ?? null) !== true || $taskId === null) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        $links = hub_voxcpm2_cluster_acceptance_task_links($config, $submitted, $taskId, true);
        hub_voxcpm2_cluster_acceptance_poll(
            $config,
            $transport,
            $taskId,
            $links['status_url'],
            $deadline,
            $sleeper
        );
        $taskResult = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'GET',
            $links['result_url'],
            '',
            $deadline
        );
        if (!hub_voxcpm2_cluster_acceptance_task_matches($taskResult, $taskId)
            || !is_array($taskResult['result']['artifacts'] ?? null)
        ) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }

        $artifacts = hub_voxcpm2_cluster_acceptance_validate_artifacts($taskResult['result']['artifacts']);
        $temporaryDirectory = hub_voxcpm2_cluster_acceptance_temp_directory();
        $paths = [];
        foreach ($artifacts as $type => $artifact) {
            try {
                $artifactUrl = hub_voxcpm2_cluster_acceptance_template_url(
                    $config,
                    $links['artifact_url_template'],
                    'cluster_artifact',
                    $taskId,
                    $artifact['id']
                );
                $response = hub_voxcpm2_cluster_acceptance_request(
                    $config,
                    $transport,
                    'GET',
                    $artifactUrl,
                    '',
                    $deadline,
                    $artifact['max_bytes']
                );
                if (!hub_voxcpm2_cluster_acceptance_mime_matches($response['headers'], $artifact['mime_types'])) {
                    hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
                }
                $extension = $type === 'generated_audio' ? '.wav' : '.json';
                $path = $temporaryDirectory . '/' . $type . $extension;
                hub_voxcpm2_cluster_acceptance_write_file($path, $response['body']);
                $size = filesize($path);
                $hash = hash_file('sha256', $path);
                if ($size !== $artifact['size_bytes'] || !is_string($hash)
                    || !hash_equals($artifact['sha256'], $hash)
                ) {
                    hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
                }
                $paths[$type] = $path;
            } catch (Throwable $error) {
                if ($error instanceof HubVoxCpm2ClusterAcceptanceFailure
                    && $error->stableCode() === 'artifact_invalid'
                ) {
                    throw $error;
                }
                hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
            }
        }

        if (!hub_voxcpm2_cluster_acceptance_wave_header_valid($paths['generated_audio'])
            || !$probe($paths['generated_audio'])
            || !hub_voxcpm2_cluster_acceptance_metadata_valid($paths['synthesis_metadata'], $config)
        ) {
            hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
        }
        hub_voxcpm2_cluster_acceptance_ensure_deadline($deadline);

        foreach ($artifacts as $artifact) {
            try {
                $ack = hub_voxcpm2_cluster_acceptance_json_request(
                    $config,
                    $transport,
                    'POST',
                    hub_voxcpm2_cluster_acceptance_template_url(
                        $config,
                        $links['ack_url_template'],
                        'cluster_task_artifacts_ack',
                        $taskId,
                        $artifact['id']
                    ),
                    '',
                    $deadline
                );
                if (!hub_voxcpm2_cluster_acceptance_task_matches($ack, $taskId)) {
                    hub_voxcpm2_cluster_acceptance_fail('ack_failed');
                }
            } catch (Throwable $error) {
                if ($error instanceof HubVoxCpm2ClusterAcceptanceFailure
                    && $error->stableCode() === 'ack_failed'
                ) {
                    throw $error;
                }
                hub_voxcpm2_cluster_acceptance_fail('ack_failed');
            }
        }

        $result = [
            'profile_prepared' => true,
            'ultimate_clone' => true,
            'audio_valid' => true,
            'gpu' => true,
            'artifacts_acknowledged' => true,
        ];
    } catch (Throwable $error) {
        $primaryFailure = $error;
    } finally {
        if ($profileTaskId !== null) {
            try {
                $deleted = hub_voxcpm2_cluster_acceptance_json_request(
                    $config,
                    $transport,
                    'POST',
                    (string)$config['api_url'] . '?mode=voice_generate',
                    hub_voxcpm2_cluster_acceptance_json_encode([
                        'operation' => 'profile_delete',
                        'voice_profile_task_id' => $profileTaskId,
                    ]),
                    microtime(true) + HUB_VOXCPM2_CLUSTER_CLEANUP_TIMEOUT_SECONDS,
                    true
                );
                if (($deleted['ok'] ?? null) !== true || ($deleted['profile_status'] ?? null) !== 'deleted') {
                    $cleanupFailed = true;
                }
            } catch (Throwable) {
                $cleanupFailed = true;
            }
        }
        if ($temporaryDirectory !== null) {
            try {
                hub_voxcpm2_cluster_acceptance_remove_tree($temporaryDirectory);
                if (file_exists($temporaryDirectory)) {
                    $cleanupFailed = true;
                }
            } catch (Throwable) {
                $cleanupFailed = true;
            }
        }
    }

    if ($primaryFailure !== null) {
        if ($primaryFailure instanceof HubVoxCpm2ClusterAcceptanceFailure) {
            throw $primaryFailure;
        }
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
    if ($cleanupFailed || !is_array($result)) {
        hub_voxcpm2_cluster_acceptance_fail('cleanup_failed');
    }

    return $result;
}

function hub_voxcpm2_cluster_acceptance_profile_result_valid(
    array $payload,
    string $taskId,
    array $config,
): bool {
    $result = $payload['result'] ?? null;

    return hub_voxcpm2_cluster_acceptance_task_matches($payload, $taskId)
        && is_array($result)
        && hub_voxcpm2_cluster_acceptance_exact_keys($result, [
            'kind',
            'transcription_status',
            'transcript_confirmed',
            'text_chars',
            'prompt_text_sha256',
        ])
        && ($result['kind'] ?? null) === 'voice_profile_prepare'
        && ($result['transcription_status'] ?? null) === 'ready'
        && ($result['transcript_confirmed'] ?? null) === true
        && is_int($result['text_chars'] ?? null)
        && $result['text_chars'] > 0
        && $result['text_chars'] <= 20000
        && is_string($result['prompt_text_sha256'] ?? null)
        && is_string($config['prompt_text_sha256'] ?? null)
        && hash_equals($config['prompt_text_sha256'], $result['prompt_text_sha256']);
}

function hub_voxcpm2_cluster_acceptance_profile_usable(array $profile, array $config): bool
{
    return ($profile['ok'] ?? null) === true
        && in_array($profile['task_status'] ?? null, ['success', 'succeeded', 'completed'], true)
        && ($profile['profile_status'] ?? null) === 'active'
        && ($profile['transcription_status'] ?? null) === 'ready'
        && ($profile['transcript_confirmed'] ?? null) === true
        && is_string($profile['prompt_text_confirmed_at'] ?? null)
        && $profile['prompt_text_confirmed_at'] !== ''
        && ($profile['profile_name'] ?? null) === 'VoxCPM2 Cluster Acceptance'
        && ($profile['consent_type'] ?? null) === 'self_recorded'
        && is_string($profile['reference_audio_sha256'] ?? null)
        && preg_match('/\A[a-f0-9]{64}\z/', $profile['reference_audio_sha256']) === 1
        && is_string($config['reference_audio_sha256'] ?? null)
        && hash_equals($config['reference_audio_sha256'], $profile['reference_audio_sha256'])
        && !array_key_exists('prompt_text', $profile);
}

function hub_voxcpm2_cluster_acceptance_poll(
    array $config,
    callable $transport,
    string $taskId,
    string $statusUrl,
    float $totalDeadline,
    callable $sleeper,
): void {
    $deadline = min($totalDeadline, microtime(true) + HUB_VOXCPM2_CLUSTER_POLL_TIMEOUT_SECONDS);
    $delay = 250000;
    while (true) {
        $status = hub_voxcpm2_cluster_acceptance_json_request(
            $config,
            $transport,
            'GET',
            $statusUrl,
            '',
            $deadline
        );
        if (!hub_voxcpm2_cluster_acceptance_task_matches($status, $taskId)
            || !is_string($status['status'] ?? null)
        ) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        $state = strtolower($status['status']);
        if (in_array($state, ['success', 'succeeded', 'completed'], true)) {
            return;
        }
        if (in_array($state, ['failed', 'cancelled', 'canceled', 'timed_out', 'timeout'], true)) {
            hub_voxcpm2_cluster_acceptance_fail('task_failed');
        }
        if (!in_array($state, ['queued', 'running'], true)) {
            hub_voxcpm2_cluster_acceptance_fail('response_invalid');
        }
        if (microtime(true) + ($delay / 1000000) >= $deadline) {
            hub_voxcpm2_cluster_acceptance_fail('timeout');
        }
        $sleeper($delay);
        $delay = min($delay * 2, 5000000);
    }
}

function hub_voxcpm2_cluster_acceptance_task_matches(array $payload, string $taskId): bool
{
    $returned = $payload['task_id'] ?? null;
    return ($payload['ok'] ?? null) === true
        && (is_string($returned) || is_int($returned))
        && hash_equals($taskId, (string)$returned);
}

function hub_voxcpm2_cluster_acceptance_validate_artifacts(array $declared): array
{
    $expected = [
        'generated_audio' => [
            'mime_types' => ['audio/wav', 'audio/x-wav'],
            'max_bytes' => HUB_VOXCPM2_CLUSTER_AUDIO_MAX_BYTES,
        ],
        'synthesis_metadata' => [
            'mime_types' => ['application/json'],
            'max_bytes' => HUB_VOXCPM2_CLUSTER_METADATA_MAX_BYTES,
        ],
    ];
    if (!array_is_list($declared) || count($declared) !== count($expected)) {
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }
    $safe = [];
    foreach ($declared as $artifact) {
        $type = is_array($artifact) ? ($artifact['type'] ?? null) : null;
        $id = is_array($artifact) ? hub_voxcpm2_cluster_acceptance_artifact_id($artifact['id'] ?? null) : null;
        $mime = is_array($artifact) && is_string($artifact['mime_type'] ?? null)
            ? strtolower($artifact['mime_type'])
            : '';
        $size = is_array($artifact) ? ($artifact['size_bytes'] ?? null) : null;
        $sha256 = is_array($artifact) ? ($artifact['sha256'] ?? null) : null;
        if (!is_string($type) || !isset($expected[$type]) || isset($safe[$type]) || $id === null
            || !in_array($mime, $expected[$type]['mime_types'], true)
            || !is_int($size) || $size < 1 || $size > $expected[$type]['max_bytes']
            || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
        ) {
            hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
        }
        $safe[$type] = [
            'id' => $id,
            'mime_types' => $expected[$type]['mime_types'],
            'max_bytes' => $expected[$type]['max_bytes'],
            'size_bytes' => $size,
            'sha256' => $sha256,
        ];
    }
    if (array_diff_key($expected, $safe) !== []) {
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }

    return array_replace($expected, $safe);
}

function hub_voxcpm2_cluster_acceptance_wave_header_valid(string $path): bool
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $header = fread($handle, 12);
    fclose($handle);

    return is_string($header) && strlen($header) === 12
        && substr($header, 0, 4) === 'RIFF'
        && substr($header, 8, 4) === 'WAVE';
}

function hub_voxcpm2_cluster_acceptance_metadata_valid(string $path, array $config): bool
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return false;
    }
    try {
        $metadata = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    $model = is_array($metadata) ? ($metadata['model'] ?? null) : null;
    $modelValid = hub_voxcpm2_cluster_acceptance_exact_value($model, [
        'label' => 'VoxCPM2',
        'version' => '2.0.3',
        'sample_rate' => 48000,
    ]) || hub_voxcpm2_cluster_acceptance_exact_value($model, [
        'model' => '/models/voxcpm2/model',
        'label' => 'VoxCPM2',
        'version' => '2.0.3',
        'sample_rate' => 48000,
    ]);
    if (!is_array($metadata)
        || !hub_voxcpm2_cluster_acceptance_exact_keys($metadata, [
            'normalized_input',
            'plan',
            'model',
            'voice_context',
            'controls',
            'chunks',
            'final_format',
            'loudness',
            'timeline',
            'device',
        ])
        || !is_string($config['reference_audio_sha256'] ?? null)
        || !is_string($config['prompt_text_sha256'] ?? null)
        || !is_string($config['normalized_input'] ?? null)
        || !is_string($config['normalized_input_sha256'] ?? null)
        || !is_string($metadata['normalized_input'] ?? null)
        || !hash_equals($config['normalized_input'], $metadata['normalized_input'])
        || !hash_equals($config['normalized_input_sha256'], hash('sha256', $metadata['normalized_input']))
        || !$modelValid
        || !hub_voxcpm2_cluster_acceptance_exact_value($metadata['controls'] ?? null, [
            'mode' => 'ultimate_clone',
            'seed_policy' => 'derived_per_chunk',
            'task_seed' => 42,
        ])
        || !hub_voxcpm2_cluster_acceptance_exact_value(
            $metadata['device'] ?? null,
            ['type' => 'cuda', 'real_inference' => true]
        )
    ) {
        return false;
    }

    $voice = $metadata['voice_context'] ?? null;
    $voiceCore = [
        'mode' => 'ultimate_clone',
        'control' => '',
        'reference_audio_sha256' => $config['reference_audio_sha256'],
        'prompt_text_sha256' => $config['prompt_text_sha256'],
    ];
    $legacyVoiceCore = $voiceCore + ['container_path' => '/data/voice_profiles/reference.wav'];
    $voiceWithoutHash = is_array($voice) ? $voice : [];
    $voiceSha256 = is_string($voiceWithoutHash['sha256'] ?? null) ? $voiceWithoutHash['sha256'] : '';
    unset($voiceWithoutHash['sha256']);
    $matchedVoiceCore = hub_voxcpm2_cluster_acceptance_exact_value($voiceWithoutHash, $voiceCore)
        ? $voiceCore
        : (hub_voxcpm2_cluster_acceptance_exact_value($voiceWithoutHash, $legacyVoiceCore) ? $legacyVoiceCore : null);
    $voiceCanonical = $matchedVoiceCore === null
        ? null
        : hub_voxcpm2_cluster_acceptance_canonical_json($matchedVoiceCore);
    if ($voiceCanonical === null || $voiceSha256 === ''
        || !hash_equals(hash('sha256', $voiceCanonical), $voiceSha256)) {
        return false;
    }

    $plan = $metadata['plan'] ?? null;
    if (!is_array($plan)
        || !hub_voxcpm2_cluster_acceptance_exact_keys($plan, [
            'normalization',
            'normalized_input',
            'max_chunk_chars',
            'task_seed',
            'seed_policy',
            'chunks',
            'plan_sha256',
        ])
        || ($plan['normalization'] ?? null) !== 'semantic-v1'
        || ($plan['normalized_input'] ?? null) !== $config['normalized_input']
        || ($plan['max_chunk_chars'] ?? null) !== 240
        || ($plan['task_seed'] ?? null) !== 42
        || ($plan['seed_policy'] ?? null) !== 'derived_per_chunk'
        || !is_array($plan['chunks'] ?? null)
        || !array_is_list($plan['chunks'])
        || $plan['chunks'] === []
        || count($plan['chunks']) > 128
        || !is_string($plan['plan_sha256'] ?? null)
    ) {
        return false;
    }
    $joined = '';
    foreach ($plan['chunks'] as $index => $chunk) {
        $chunkId = sprintf('chunk-%04d', $index + 1);
        $text = is_array($chunk) ? ($chunk['text'] ?? null) : null;
        $length = is_string($text) ? hub_voxcpm2_cluster_acceptance_utf8_length($text) : null;
        $seed = hub_voxcpm2_cluster_acceptance_chunk_seed($chunkId);
        if (!is_array($chunk)
            || !hub_voxcpm2_cluster_acceptance_exact_keys($chunk, [
                'id',
                'text',
                'text_sha256',
                'seed',
                'seed_sha256',
            ])
            || ($chunk['id'] ?? null) !== $chunkId
            || !is_string($text)
            || $length === null
            || $length < 1
            || $length > 240
            || !is_string($chunk['text_sha256'] ?? null)
            || !hash_equals(hash('sha256', $text), $chunk['text_sha256'])
            || ($chunk['seed'] ?? null) !== $seed['seed']
            || !is_string($chunk['seed_sha256'] ?? null)
            || !hash_equals($seed['sha256'], $chunk['seed_sha256'])
        ) {
            return false;
        }
        $joined .= $text;
    }
    if (!hash_equals($config['normalized_input'], $joined)
        || !hash_equals($config['normalized_input_sha256'], hash('sha256', $joined))
    ) {
        return false;
    }
    $planCore = $plan;
    $planSha256 = $planCore['plan_sha256'];
    unset($planCore['plan_sha256']);
    $planCanonical = hub_voxcpm2_cluster_acceptance_canonical_json($planCore);
    if ($planCanonical === null || !hash_equals(hash('sha256', $planCanonical), $planSha256)) {
        return false;
    }

    $chunks = $metadata['chunks'] ?? null;
    $timeline = $metadata['timeline'] ?? null;
    if (!is_array($chunks) || !array_is_list($chunks) || count($chunks) !== count($plan['chunks'])
        || !is_array($timeline) || !array_is_list($timeline) || count($timeline) !== count($chunks)
    ) {
        return false;
    }
    $previousEnd = 0;
    foreach ($chunks as $index => $chunk) {
        $planned = $plan['chunks'][$index];
        $event = $timeline[$index] ?? null;
        if (!is_array($chunk)
            || !hub_voxcpm2_cluster_acceptance_exact_keys($chunk, [
                'id',
                'seed',
                'seed_sha256',
                'attempts',
                'duration_frames',
                'duration_seconds',
                'peak_gain',
                'reused_checkpoint',
                'action',
                'trim_frames',
                'pause_frames',
                'crossfade_frames',
            ])
            || ($chunk['id'] ?? null) !== $planned['id']
            || ($chunk['seed'] ?? null) !== $planned['seed']
            || ($chunk['seed_sha256'] ?? null) !== $planned['seed_sha256']
            || !is_int($chunk['attempts'] ?? null) || $chunk['attempts'] < 1 || $chunk['attempts'] > 100
            || !is_int($chunk['duration_frames'] ?? null) || $chunk['duration_frames'] < 1
            || !is_numeric($chunk['duration_seconds'] ?? null) || (float)$chunk['duration_seconds'] <= 0
            || !is_numeric($chunk['peak_gain'] ?? null) || (float)$chunk['peak_gain'] <= 0 || (float)$chunk['peak_gain'] > 1
            || !is_bool($chunk['reused_checkpoint'] ?? null)
            || !in_array($chunk['action'] ?? null, [
                'direct_concat',
                'silence_insert',
                'crossfade',
                'trim_then_pause',
                'regenerate_chunk',
            ], true)
            || !is_int($chunk['trim_frames'] ?? null) || $chunk['trim_frames'] < 0
            || !is_int($chunk['pause_frames'] ?? null) || $chunk['pause_frames'] < 0
            || !is_int($chunk['crossfade_frames'] ?? null) || $chunk['crossfade_frames'] < 0
            || !is_array($event)
            || !hub_voxcpm2_cluster_acceptance_exact_keys($event, [
                'chunk_id',
                'start_frame',
                'end_frame',
                'sample_rate',
            ])
            || ($event['chunk_id'] ?? null) !== $planned['id']
            || ($event['sample_rate'] ?? null) !== 48000
            || !is_int($event['start_frame'] ?? null) || $event['start_frame'] < $previousEnd
            || !is_int($event['end_frame'] ?? null) || $event['end_frame'] <= $event['start_frame']
        ) {
            return false;
        }
        $previousEnd = $event['end_frame'];
    }
    $format = $metadata['final_format'] ?? null;
    $loudness = $metadata['loudness'] ?? null;

    return is_array($format)
        && hub_voxcpm2_cluster_acceptance_exact_keys($format, [
            'mime_type',
            'sample_rate',
            'channels',
            'frames',
        ])
        && ($format['mime_type'] ?? null) === 'audio/wav'
        && ($format['sample_rate'] ?? null) === 48000
        && ($format['channels'] ?? null) === 1
        && is_int($format['frames'] ?? null)
        && $format['frames'] === $previousEnd
        && is_array($loudness)
        && hub_voxcpm2_cluster_acceptance_exact_keys($loudness, ['passes', 'target_lufs', 'gain'])
        && ($loudness['passes'] ?? null) === 1
        && is_numeric($loudness['target_lufs'] ?? null)
        && (float)$loudness['target_lufs'] === -16.0
        && is_numeric($loudness['gain'] ?? null)
        && (float)$loudness['gain'] > 0
        && (float)$loudness['gain'] <= 2;
}

function hub_voxcpm2_cluster_acceptance_json_encode(array $value): string
{
    try {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
}

function hub_voxcpm2_cluster_acceptance_json_request(
    array $config,
    callable $transport,
    string $method,
    string $url,
    mixed $body,
    float $deadline,
    bool $jsonBody = false,
): array {
    $response = hub_voxcpm2_cluster_acceptance_request(
        $config,
        $transport,
        $method,
        $url,
        $body,
        $deadline,
        HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES,
        $jsonBody
    );
    if (!hub_voxcpm2_cluster_acceptance_mime_matches($response['headers'], ['application/json'])) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    try {
        $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }
    if (!is_array($payload) || ($payload['ok'] ?? null) !== true) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }

    return $payload;
}

function hub_voxcpm2_cluster_acceptance_request(
    array $config,
    callable $transport,
    string $method,
    string $url,
    mixed $body,
    float $deadline,
    int $maxBodyBytes,
    bool $jsonBody = false,
): array {
    hub_voxcpm2_cluster_acceptance_ensure_deadline($deadline);
    hub_voxcpm2_cluster_acceptance_assert_request_url($config, $url);
    if (!in_array($method, ['GET', 'POST'], true) || $maxBodyBytes < 1) {
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
    $remaining = max(1, (int)ceil($deadline - microtime(true)));
    $headers = [
        'Authorization' => 'Bearer ' . (string)$config['token'],
        'Accept' => 'application/json',
    ];
    if ($jsonBody) {
        $headers['Content-Type'] = 'application/json';
    }
    try {
        $response = $transport([
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'follow_redirects' => false,
            'connect_timeout' => min(HUB_VOXCPM2_CLUSTER_CONNECT_TIMEOUT_SECONDS, $remaining),
            'request_timeout' => min(HUB_VOXCPM2_CLUSTER_REQUEST_TIMEOUT_SECONDS, $remaining),
            'max_body_bytes' => $maxBodyBytes,
        ]);
    } catch (HubVoxCpm2ClusterAcceptanceFailure $error) {
        throw $error;
    } catch (Throwable) {
        hub_voxcpm2_cluster_acceptance_fail('request_failed');
    }
    if (!is_array($response) || !empty($response['transport_error'])) {
        hub_voxcpm2_cluster_acceptance_fail('request_failed');
    }
    $status = $response['status'] ?? null;
    $responseBody = $response['body'] ?? null;
    if (!is_int($status) || $status < 200 || $status >= 300) {
        hub_voxcpm2_cluster_acceptance_fail('request_failed');
    }
    if (!is_string($responseBody) || !empty($response['too_large']) || strlen($responseBody) > $maxBodyBytes
        || !is_array($response['headers'] ?? null)
    ) {
        hub_voxcpm2_cluster_acceptance_fail('response_invalid');
    }

    return ['status' => $status, 'headers' => $response['headers'], 'body' => $responseBody];
}

function hub_voxcpm2_cluster_acceptance_assert_request_url(array $config, string $url): void
{
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== (string)$config['scheme']
        || strtolower(trim((string)($parts['host'] ?? ''), '[]')) !== (string)$config['host']
        || hub_voxcpm2_cluster_acceptance_effective_port(
            strtolower((string)($parts['scheme'] ?? '')),
            $parts['port'] ?? null
        ) !== (int)$config['port']
        || (string)($parts['path'] ?? '') !== (string)$config['api_path']
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || !is_string($parts['query'] ?? null)
    ) {
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
    $query = hub_voxcpm2_cluster_acceptance_query((string)$parts['query']);
    $mode = $query['mode'] ?? '';
    if ($mode === 'voice_generate') {
        if ($query !== ['mode' => 'voice_generate']) {
            hub_voxcpm2_cluster_acceptance_fail('internal_error');
        }
        return;
    }
    if (!in_array($mode, [
        'cluster_task_status',
        'cluster_task_result',
        'cluster_task_log',
        'cluster_task_cancel',
        'cluster_artifact',
        'cluster_task_artifacts_ack',
    ], true)
        || hub_voxcpm2_cluster_acceptance_task_id($query['task_id'] ?? null) === null
    ) {
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
    $needsArtifact = in_array($mode, ['cluster_artifact', 'cluster_task_artifacts_ack'], true);
    if (($needsArtifact && (count($query) !== 3
            || hub_voxcpm2_cluster_acceptance_artifact_id($query['artifact_id'] ?? null) === null))
        || (!$needsArtifact && count($query) !== 2)
    ) {
        hub_voxcpm2_cluster_acceptance_fail('internal_error');
    }
}

function hub_voxcpm2_cluster_acceptance_http(array $request): array
{
    $handle = curl_init((string)$request['url']);
    if ($handle === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'transport_error' => true];
    }
    $headers = [];
    foreach ((array)($request['headers'] ?? []) as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    $headers[] = 'Expect:';
    $responseHeaders = [];
    $responseBody = '';
    $tooLarge = false;
    $headerBytes = 0;
    $headerCount = 0;
    $maxBodyBytes = max(1, (int)($request['max_body_bytes'] ?? 0));
    $options = [
        CURLOPT_CUSTOMREQUEST => (string)$request['method'],
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => max(1, (int)$request['connect_timeout']),
        CURLOPT_TIMEOUT => max(1, (int)$request['request_timeout']),
        CURLOPT_NOSIGNAL => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (
            &$responseHeaders,
            &$headerBytes,
            &$headerCount,
            &$tooLarge,
            $maxBodyBytes,
        ): int {
            return hub_voxcpm2_cluster_acceptance_capture_header(
                $responseHeaders,
                $headerBytes,
                $headerCount,
                $tooLarge,
                $maxBodyBytes,
                $header
            );
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBody, &$tooLarge, $maxBodyBytes): int {
            if (strlen($chunk) > $maxBodyBytes - strlen($responseBody)) {
                $tooLarge = true;
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (($request['method'] ?? '') === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $request['body'];
    }
    hub_voxcpm2_cluster_acceptance_apply_curl_options($handle, $options);
    $completed = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $responseBody,
        'too_large' => $tooLarge,
        'transport_error' => $completed === false && !$tooLarge,
    ];
}

function hub_voxcpm2_cluster_acceptance_apply_curl_options(
    mixed $handle,
    array $options,
    ?callable $setter = null,
    ?callable $closer = null,
): void {
    $setter ??= 'curl_setopt_array';
    $closer ??= 'curl_close';
    try {
        $configured = $setter($handle, $options);
    } catch (Throwable) {
        try {
            $closer($handle);
        } catch (Throwable) {
        }
        hub_voxcpm2_cluster_acceptance_fail('request_failed');
    }
    if ($configured === true) {
        return;
    }
    try {
        $closer($handle);
    } catch (Throwable) {
    }
    hub_voxcpm2_cluster_acceptance_fail('request_failed');
}

function hub_voxcpm2_cluster_acceptance_capture_header(
    array &$responseHeaders,
    int &$headerBytes,
    int &$headerCount,
    bool &$tooLarge,
    int $maxBodyBytes,
    string $header,
): int {
    $length = strlen($header);
    if ($headerCount >= HUB_VOXCPM2_CLUSTER_HEADER_MAX_COUNT
        || $length > HUB_VOXCPM2_CLUSTER_HEADER_MAX_BYTES - $headerBytes
    ) {
        $tooLarge = true;
        return 0;
    }
    $headerBytes += $length;
    $headerCount++;

    $line = trim($header);
    if ($line === '') {
        return $length;
    }
    if (str_starts_with(strtolower($line), 'content-length:')) {
        $declared = trim(substr($line, strlen('content-length:')));
        if (ctype_digit($declared)
            && (strlen($declared) > 18 || (int)$declared > $maxBodyBytes)
        ) {
            $tooLarge = true;
            return 0;
        }
    }
    $responseHeaders[] = $line;

    return $length;
}

function hub_voxcpm2_cluster_acceptance_mime_matches(array $headers, array $allowed): bool
{
    $contentType = '';
    foreach ($headers as $name => $value) {
        if (is_string($name) && strtolower($name) === 'content-type') {
            $contentType = (string)$value;
        } elseif (is_string($value) && str_starts_with(strtolower($value), 'content-type:')) {
            $contentType = trim(substr($value, strlen('content-type:')));
        }
    }
    $contentType = strtolower(trim((string)strtok($contentType, ';')));

    return in_array($contentType, $allowed, true);
}

function hub_voxcpm2_cluster_acceptance_ensure_deadline(float $deadline): void
{
    if (microtime(true) >= $deadline) {
        hub_voxcpm2_cluster_acceptance_fail('timeout');
    }
}

function hub_voxcpm2_cluster_acceptance_temp_directory(): string
{
    $root = realpath(sys_get_temp_dir());
    if ($root === false) {
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }
    try {
        $name = '3waaihub_voxcpm2_cluster_' . bin2hex(random_bytes(12));
    } catch (Throwable) {
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (!mkdir($path, 0700) || !chmod($path, 0700)
        || realpath($path) !== $path || (fileperms($path) & 0777) !== 0700
    ) {
        if (is_dir($path)) {
            @rmdir($path);
        }
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }

    return $path;
}

function hub_voxcpm2_cluster_acceptance_write_file(string $path, string $contents): void
{
    $handle = @fopen($path, 'x+b');
    if ($handle === false || !chmod($path, 0600)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }
    $offset = 0;
    $length = strlen($contents);
    while ($offset < $length) {
        $written = fwrite($handle, substr($contents, $offset));
        if ($written === false || $written === 0) {
            fclose($handle);
            hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
        }
        $offset += $written;
    }
    if (!fflush($handle)) {
        fclose($handle);
        hub_voxcpm2_cluster_acceptance_fail('artifact_invalid');
    }
    fclose($handle);
}

function hub_voxcpm2_cluster_acceptance_remove_tree(string $directory): void
{
    $root = realpath(sys_get_temp_dir());
    $real = realpath($directory);
    if ($root === false || $real === false || is_link($directory)
        || dirname($real) !== $root
        || preg_match('/\A3waaihub_voxcpm2_cluster_[a-f0-9]{24}\z/', basename($real)) !== 1
    ) {
        throw new RuntimeException('unsafe cleanup target');
    }
    hub_voxcpm2_cluster_acceptance_remove_tree_contents($real);
    if (!rmdir($real)) {
        throw new RuntimeException('cleanup failed');
    }
}

function hub_voxcpm2_cluster_acceptance_remove_tree_contents(string $directory): void
{
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $name;
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('cleanup failed');
            }
        } elseif (is_dir($path)) {
            hub_voxcpm2_cluster_acceptance_remove_tree_contents($path);
            if (!rmdir($path)) {
                throw new RuntimeException('cleanup failed');
            }
        } else {
            throw new RuntimeException('cleanup failed');
        }
    }
}

function hub_voxcpm2_cluster_acceptance_ffprobe(
    string $path,
    ?callable $processFactory = null,
): bool
{
    $command = [
        'ffprobe',
        '-v', 'error',
        '-select_streams', 'a',
        '-show_entries', 'stream=codec_type:format=duration',
        '-of', 'json',
        $path,
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $environment = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin',
        'LANG' => 'C',
    ];
    $pipes = [];
    try {
        $process = $processFactory === null
            ? proc_open($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true])
            : $processFactory($command, $descriptors, $pipes, null, $environment, ['bypass_shell' => true]);
    } catch (Throwable) {
        return false;
    }
    if (!is_resource($process)) {
        return false;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $deadline = microtime(true) + HUB_VOXCPM2_CLUSTER_FFPROBE_TIMEOUT_SECONDS;
    $stdout = '';
    $stderr = '';
    $status = ['running' => true, 'exitcode' => -1];
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        if (strlen($stdout) > HUB_VOXCPM2_CLUSTER_PROCESS_OUTPUT_MAX_BYTES
            || strlen($stderr) > HUB_VOXCPM2_CLUSTER_PROCESS_OUTPUT_MAX_BYTES
        ) {
            proc_terminate($process, 9);
            $status = proc_get_status($process);
            break;
        }
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(10000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    $exitCode = (int)($status['exitcode'] ?? -1);
    if ($exitCode < 0) {
        $exitCode = $closed;
    }
    if ($exitCode !== 0 || strlen($stdout) > HUB_VOXCPM2_CLUSTER_PROCESS_OUTPUT_MAX_BYTES) {
        return false;
    }
    try {
        $probe = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return false;
    }
    $streams = is_array($probe) ? ($probe['streams'] ?? null) : null;
    $duration = is_array($probe) ? ($probe['format']['duration'] ?? null) : null;

    return is_array($streams) && array_is_list($streams) && count($streams) === 1
        && ($streams[0]['codec_type'] ?? null) === 'audio'
        && (is_string($duration) || is_int($duration) || is_float($duration))
        && is_finite((float)$duration) && (float)$duration > 0;
}

function hub_voxcpm2_cluster_acceptance_install_signal_handlers(): array
{
    if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
        return [];
    }
    $state = ['async' => pcntl_async_signals(), 'handlers' => []];
    pcntl_async_signals(true);
    foreach (['SIGINT', 'SIGTERM', 'SIGHUP'] as $name) {
        if (!defined($name)) {
            continue;
        }
        $signal = constant($name);
        $state['handlers'][$signal] = function_exists('pcntl_signal_get_handler')
            ? pcntl_signal_get_handler($signal)
            : SIG_DFL;
        pcntl_signal($signal, static function (): never {
            hub_voxcpm2_cluster_acceptance_fail('interrupted');
        });
    }

    return $state;
}

function hub_voxcpm2_cluster_acceptance_restore_signal_handlers(array $state): void
{
    if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
        return;
    }
    foreach ((array)($state['handlers'] ?? []) as $signal => $handler) {
        pcntl_signal((int)$signal, $handler);
    }
    if (isset($state['async']) && is_bool($state['async'])) {
        pcntl_async_signals($state['async']);
    }
}

function hub_voxcpm2_cluster_acceptance_restore_signal_handlers_safely(
    array $state,
    ?string $primaryCode,
    ?callable $restorer = null,
): ?string {
    $restorer ??= 'hub_voxcpm2_cluster_acceptance_restore_signal_handlers';
    try {
        $restorer($state);
    } catch (Throwable) {
        return $primaryCode ?? 'internal_error';
    }

    return $primaryCode;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(hub_voxcpm2_cluster_acceptance_main($argv));
}
