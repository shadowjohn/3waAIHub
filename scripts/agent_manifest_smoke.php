<?php
declare(strict_types=1);

const HUB_AGENT_MANIFEST_SMOKE_MAX_BYTES = 5242880;

function hub_agent_manifest_smoke_usage(): string
{
    return 'Usage: php scripts/agent_manifest_smoke.php --manifest-url=https://host/3waAIHub/api_manifest.json.php [--timeout=5]' . PHP_EOL;
}

function hub_agent_manifest_smoke_field_in_curl(string $curl, array $field, string $method, string $contentType): bool
{
    $name = (string)($field['name'] ?? '');
    if ($name === '' || $name === 'mode') {
        return true;
    }
    if ((string)($field['type'] ?? '') === 'file') {
        return str_contains($curl, $name . '=@');
    }
    if ($method === 'GET') {
        return str_contains($curl, $name . '=');
    }
    if ($contentType === 'application/json') {
        return str_contains($curl, '"' . $name . '"');
    }

    return str_contains($curl, $name . '=');
}

function hub_agent_manifest_smoke_validate_mode_url(string $value, string $mode, bool $absolute, string $label): ?string
{
    $parts = parse_url($value);
    if (!is_array($parts)) {
        return $label . ' is not a valid URL';
    }
    if ($absolute && !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return $label . ' must use http or https';
    }
    if ($absolute && (string)($parts['host'] ?? '') === '') {
        return $label . ' must include a host';
    }
    if (basename((string)($parts['path'] ?? '')) !== 'api.php') {
        return $label . ' must target api.php';
    }

    $modeValues = [];
    foreach (explode('&', (string)($parts['query'] ?? '')) as $pair) {
        [$key, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
        if ($key === 'mode') {
            $modeValues[] = $rawValue;
        }
    }
    if (count($modeValues) !== 1) {
        return $label . ' must contain exactly one mode query value';
    }
    if ($modeValues[0] !== rawurlencode($mode)) {
        return $label . ' mode does not match service mode';
    }

    return null;
}

function hub_agent_manifest_smoke_validate_service(array $service, int $index): array
{
    $errors = [];
    $mode = trim((string)($service['mode'] ?? ''));
    $prefix = 'service[' . ($mode !== '' ? $mode : (string)$index) . '] ';
    if ($mode === '') {
        return [$prefix . 'mode is required'];
    }

    $method = strtoupper(trim((string)($service['method'] ?? '')));
    if (!in_array($method, ['GET', 'POST'], true)) {
        $errors[] = $prefix . 'method must be GET or POST';
    }
    foreach ([
        ['value' => (string)($service['endpoint'] ?? ''), 'absolute' => false, 'label' => 'endpoint'],
        ['value' => (string)($service['url'] ?? ''), 'absolute' => true, 'label' => 'url'],
    ] as $candidate) {
        $error = hub_agent_manifest_smoke_validate_mode_url($candidate['value'], $mode, $candidate['absolute'], $candidate['label']);
        if ($error !== null) {
            $errors[] = $prefix . $error;
        }
    }

    $curl = (string)($service['examples']['curl'] ?? '');
    if ($curl === '') {
        return [...$errors, $prefix . 'curl example is required'];
    }
    if (!str_contains($curl, 'Authorization: Bearer <TOKEN>')) {
        $errors[] = $prefix . 'curl example must use the token placeholder';
    }
    if ((string)($service['url'] ?? '') !== '' && !str_contains($curl, (string)$service['url'])) {
        $errors[] = $prefix . 'curl example must target the declared url';
    }
    if ($method === 'POST' && !str_contains($curl, '-X POST')) {
        $errors[] = $prefix . 'POST curl example must declare -X POST';
    }
    if ($method === 'GET' && preg_match_all('/(?:^|\s)-X\s+([A-Za-z]+)/', $curl, $matches) > 0) {
        foreach ($matches[1] as $exampleMethod) {
            if (strtoupper($exampleMethod) !== 'GET') {
                $errors[] = $prefix . 'GET curl example claims a conflicting -X method';
                break;
            }
        }
    }

    $fields = $service['input_fields'] ?? null;
    if (!is_array($fields)) {
        return [...$errors, $prefix . 'input_fields must be an array'];
    }
    $contentType = trim((string)($service['content_type'] ?? ''));
    $fieldMap = [];
    $groups = [];
    foreach ($fields as $fieldIndex => $field) {
        if (!is_array($field)) {
            $errors[] = $prefix . 'input_fields[' . $fieldIndex . '] must be an object';
            continue;
        }
        $name = trim((string)($field['name'] ?? ''));
        if ($name === '') {
            $errors[] = $prefix . 'input_fields[' . $fieldIndex . '] name is required';
            continue;
        }
        if (isset($fieldMap[$name])) {
            $errors[] = $prefix . 'input field ' . $name . ' is duplicated';
            continue;
        }
        $fieldMap[$name] = $field;
        if (array_key_exists('example_include', $field) && !is_bool($field['example_include'])) {
            $errors[] = $prefix . 'input field ' . $name . ' example_include must be boolean';
        }
        if (!empty($field['required']) || ($field['example_include'] ?? false) === true) {
            if (!hub_agent_manifest_smoke_field_in_curl($curl, $field, $method, $contentType)) {
                $errors[] = $prefix . 'curl example must include input field ' . $name;
            }
        }
        if (!array_key_exists('one_of', $field)) {
            continue;
        }
        if (!is_array($field['one_of']) || count($field['one_of']) < 2) {
            $errors[] = $prefix . 'input field ' . $name . ' one_of must name at least two fields';
            continue;
        }
        $members = array_values($field['one_of']);
        if (count(array_unique($members, SORT_STRING)) !== count($members) || array_filter($members, static fn (mixed $member): bool => !is_string($member) || $member === '') !== []) {
            $errors[] = $prefix . 'input field ' . $name . ' one_of members must be unique names';
            continue;
        }
        if (!in_array($name, $members, true)) {
            $errors[] = $prefix . 'input field ' . $name . ' must be a member of its one_of group';
        }
        $groupKeyMembers = $members;
        sort($groupKeyMembers, SORT_STRING);
        $groupKey = implode("\0", $groupKeyMembers);
        $groups[$groupKey][] = ['name' => $name, 'members' => $members, 'required' => $field['one_of_required'] ?? null];
    }

    foreach ($groups as $group) {
        $declaredMembers = $group[0]['members'];
        $groupLabel = implode(', ', $declaredMembers);
        $required = $group[0]['required'];
        if (!is_bool($required)) {
            $errors[] = $prefix . 'one_of group ' . $groupLabel . ' must declare boolean one_of_required';
            continue;
        }
        foreach ($group as $declaration) {
            if ($declaration['members'] !== $declaredMembers || $declaration['required'] !== $required) {
                $errors[] = $prefix . 'one_of group ' . $groupLabel . ' members must declare the same one_of metadata';
                break;
            }
        }
        foreach ($declaredMembers as $member) {
            $memberField = $fieldMap[$member] ?? null;
            if (!is_array($memberField)) {
                $errors[] = $prefix . 'one_of group ' . $groupLabel . ' references missing input field ' . $member;
                continue;
            }
            if (($memberField['one_of'] ?? null) !== $declaredMembers || ($memberField['one_of_required'] ?? null) !== $required) {
                $errors[] = $prefix . 'one_of group ' . $groupLabel . ' member ' . $member . ' metadata differs';
            }
        }
        if (!$required) {
            continue;
        }
        $selected = [];
        $defaults = [];
        foreach ($declaredMembers as $member) {
            $memberField = $fieldMap[$member] ?? [];
            if (hub_agent_manifest_smoke_field_in_curl($curl, $memberField, $method, $contentType)) {
                $selected[] = $member;
            }
            if (($memberField['example_include'] ?? false) === true) {
                $defaults[] = $member;
            }
        }
        if (count($selected) !== 1) {
            $errors[] = $prefix . 'required one_of group ' . $groupLabel . ' must select exactly one input in its curl example';
        }
        if (count($defaults) !== 1) {
            $errors[] = $prefix . 'required one_of group ' . $groupLabel . ' must declare exactly one example_include default';
        } elseif (count($selected) === 1 && $selected[0] !== $defaults[0]) {
            $errors[] = $prefix . 'required one_of group ' . $groupLabel . ' curl selection must match example_include';
        }
    }

    return $errors;
}

function hub_agent_manifest_smoke_validate(array $manifest): array
{
    $errors = [];
    $extensions = $manifest['input_field_extensions'] ?? null;
    $expectedExtensions = [
        'one_of' => 'array<string>',
        'one_of_required' => 'boolean',
        'example_include' => 'boolean',
    ];
    if (!is_array($extensions)) {
        $errors[] = 'manifest input_field_extensions must be an object';
    } else {
        foreach ($expectedExtensions as $name => $type) {
            if (($extensions[$name]['type'] ?? null) !== $type) {
                $errors[] = 'manifest input_field_extensions.' . $name . ' must declare type ' . $type;
            }
        }
    }

    $services = $manifest['services'] ?? null;
    if (!is_array($services)) {
        return [...$errors, 'manifest services must be an array'];
    }
    foreach ($services as $index => $service) {
        if (!is_array($service)) {
            $errors[] = 'manifest services[' . $index . '] must be an object';
            continue;
        }
        $errors = [...$errors, ...hub_agent_manifest_smoke_validate_service($service, (int)$index)];
    }

    return $errors;
}

function hub_agent_manifest_smoke_fetch(string $url, int $timeout): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP curl extension is required'];
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || (string)($parts['host'] ?? '') === '') {
        return ['ok' => false, 'error' => 'manifest URL must be an absolute http or https URL'];
    }

    $body = '';
    $tooLarge = false;
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'error' => 'curl_init failed'];
    }
    curl_setopt_array($handle, [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_PROXY => '',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > HUB_AGENT_MANIFEST_SMOKE_MAX_BYTES) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $result = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($handle);
    curl_close($handle);
    if ($tooLarge) {
        return ['ok' => false, 'error' => 'manifest response exceeds 5 MiB'];
    }
    if ($result === false) {
        return ['ok' => false, 'error' => 'manifest fetch failed: ' . $curlError];
    }
    if ($status !== 200) {
        return ['ok' => false, 'error' => 'manifest fetch returned HTTP ' . $status];
    }
    if (!str_starts_with(ltrim($body), '{')) {
        return ['ok' => false, 'error' => 'manifest response must be a JSON object'];
    }
    $manifest = json_decode($body, true);
    if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'manifest response is not valid JSON: ' . json_last_error_msg()];
    }

    return ['ok' => true, 'manifest' => $manifest];
}

function hub_agent_manifest_smoke_main(array $argv): int
{
    $options = getopt('', ['manifest-url:', 'timeout::', 'help']);
    if (isset($options['help'])) {
        echo hub_agent_manifest_smoke_usage();

        return 0;
    }
    $url = trim((string)($options['manifest-url'] ?? ''));
    if ($url === '') {
        fwrite(STDERR, 'Missing --manifest-url. Run with --help.' . PHP_EOL);

        return 2;
    }
    $timeoutValue = (string)($options['timeout'] ?? '5');
    if (preg_match('/^[1-9][0-9]*$/', $timeoutValue) !== 1 || (int)$timeoutValue > 30) {
        fwrite(STDERR, '--timeout must be an integer between 1 and 30 seconds.' . PHP_EOL);

        return 2;
    }
    if (!function_exists('curl_init')) {
        fwrite(STDERR, 'PHP curl extension is required.' . PHP_EOL);

        return 2;
    }

    $fetched = hub_agent_manifest_smoke_fetch($url, (int)$timeoutValue);
    if (($fetched['ok'] ?? false) !== true) {
        fwrite(STDERR, 'FAIL ' . (string)($fetched['error'] ?? 'manifest fetch failed') . PHP_EOL);

        return 1;
    }
    $errors = hub_agent_manifest_smoke_validate($fetched['manifest']);
    if ($errors !== []) {
        foreach ($errors as $error) {
            fwrite(STDERR, 'FAIL ' . $error . PHP_EOL);
        }

        return 1;
    }

    echo 'PASS manifest services=' . count($fetched['manifest']['services']) . PHP_EOL;

    return 0;
}

$scriptPath = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
if ($scriptPath !== false && $scriptPath === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('CLI only');
    }
    exit(hub_agent_manifest_smoke_main($argv));
}
