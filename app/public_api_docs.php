<?php
declare(strict_types=1);

function hub_is_local_request(): bool
{
    return hub_is_localhost_ip(trim((string)($_SERVER['REMOTE_ADDR'] ?? '')));
}

function hub_public_api_allowed(PDO $db, string $settingKey): bool
{
    hub_ensure_default_storage_settings($db);
    if (hub_get_storage_setting($db, $settingKey) !== '1') {
        return false;
    }
    if (hub_get_storage_setting($db, 'AIHUB_PUBLIC_API_LOCAL_ONLY') === '1' && !hub_is_local_request()) {
        return false;
    }

    return true;
}

function hub_public_api_base_path(): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/3waAIHub/public_api_docs.php');
    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }
    if (str_ends_with($dir, '/admin')) {
        $dir = substr($dir, 0, -6) ?: '';
    }

    return $dir;
}

function hub_public_api_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) ?: 'localhost';

    return ($https ? 'https' : 'http') . '://' . $host . hub_public_api_base_path() . '/api.php';
}

function hub_public_api_mode_url(string $mode): string
{
    return hub_public_api_base_url() . '?mode=' . rawurlencode($mode);
}

function hub_public_api_method(array $manifest, array $contract): string
{
    $method = (string)($contract['method'] ?? '');
    if ($method !== '') {
        return strtoupper($method);
    }
    $methods = is_array($manifest['gateway']['methods'] ?? null) ? $manifest['gateway']['methods'] : [];

    return strtoupper((string)($methods[0] ?? 'POST'));
}

function hub_public_api_content_type(string $method, array $contract): string
{
    $contentType = trim((string)($contract['content_type'] ?? ''));
    if ($contentType !== '') {
        return $contentType;
    }

    return $method === 'GET' ? 'application/json' : 'multipart/form-data';
}

function hub_public_api_contract_for_manifest(array $manifest): array
{
    $contract = hub_pack_l5_contract($manifest);
    if ($contract !== []) {
        if (($manifest['id'] ?? '') === 'image-tools') {
            $contract['input'] = ['fields' => [
                ['name' => 'image', 'type' => 'file', 'required' => false, 'example' => 'sample.png', 'example_include' => true, 'one_of' => ['image', 'base64_string'], 'one_of_required' => true],
                ['name' => 'base64_string', 'type' => 'string', 'required' => false, 'one_of' => ['image', 'base64_string'], 'one_of_required' => true],
                ['name' => 'model', 'type' => 'string', 'required' => false, 'default' => 'realesrgan-x4plus', 'enum' => $contract['models'] ?? []],
                ['name' => 'backend', 'type' => 'string', 'required' => false, 'default' => 'auto', 'enum' => $contract['backends'] ?? []],
            ]];
        }

        return $contract;
    }
    $gateway = is_array($manifest['gateway'] ?? null) ? $manifest['gateway'] : [];
    $methods = is_array($gateway['methods'] ?? null) ? $gateway['methods'] : [];
    $method = strtoupper((string)($methods[0] ?? 'POST'));
    $invokePath = strtolower((string)($gateway['invoke_path'] ?? ''));
    $fieldName = str_contains($invokePath, 'audio') || str_contains($invokePath, 'asr') ? 'audio' : (str_contains($invokePath, 'image') ? 'image' : '');
    $fields = $fieldName !== '' ? [[
        'name' => $fieldName,
        'type' => 'file',
        'required' => true,
        'max_mb' => (int)($gateway['max_upload_mb'] ?? 0),
    ]] : [];

    return [
        'method' => $method,
        'content_type' => $fieldName !== '' ? 'multipart/form-data' : 'application/json',
        'input' => ['fields' => $fields],
        'output' => ['required_keys' => ['ok']],
        'errors' => is_array($manifest['error_codes'] ?? null) ? $manifest['error_codes'] : ['bad_request', 'service_unavailable'],
    ];
}

function hub_public_api_health_response_ok(int $status, string $body): bool
{
    if ($status < 200 || $status >= 400) {
        return false;
    }
    $payload = json_decode($body, true);

    return !is_array($payload)
        || ((!array_key_exists('ok', $payload) || $payload['ok'] !== false)
            && (!array_key_exists('ready', $payload) || $payload['ready'] !== false));
}

function hub_public_api_health_url_allowed(string $url): bool
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'http' || isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

    return in_array($host, ['127.0.0.1', '::1', 'localhost'], true);
}

function hub_public_api_healthy_service_ids(array $services, ?callable $probe = null): array
{
    $deadline = microtime(true) + 1.0;
    $healthy = [];
    $pending = [];
    foreach ($services as $service) {
        $id = (int)($service['id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        if (hub_service_is_internal_task($service)) {
            $healthy[$id] = true;
            continue;
        }
        if (!hub_public_api_health_url_allowed((string)($service['health_url'] ?? ''))) {
            continue;
        }
        if ($probe !== null) {
            if ($probe($service) === true) {
                $healthy[$id] = true;
            }
            continue;
        }
        if (count($pending) >= 128 || microtime(true) >= $deadline) {
            continue;
        }
        $pending[$id] = $service;
    }
    if ($pending === [] || !function_exists('curl_multi_init')) {
        return $healthy;
    }

    $multi = curl_multi_init();
    $handles = [];
    $bodies = [];
    foreach ($pending as $id => $service) {
        if (microtime(true) >= $deadline) {
            break;
        }
        $handle = curl_init((string)$service['health_url']);
        if ($handle === false) {
            continue;
        }
        $bodies[$id] = '';
        $configured = curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT_MS => 250,
            CURLOPT_TIMEOUT_MS => 750,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROXY => '',
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

    while (($info = curl_multi_info_read($multi)) !== false) {
        if (($info['msg'] ?? null) !== CURLMSG_DONE) {
            continue;
        }
        $handle = $info['handle'] ?? null;
        if ($handle === null || ($info['result'] ?? null) !== CURLE_OK) {
            continue;
        }
        $id = (int)curl_getinfo($handle, CURLINFO_PRIVATE);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        if ($id > 0 && hub_public_api_health_response_ok($status, $bodies[$id] ?? '')) {
            $healthy[$id] = true;
        }
    }

    foreach ($handles as $handle) {
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);

    return $healthy;
}

function hub_public_api_service_mode_uses_pack(array $service): bool
{
    $mode = trim((string)($service['mode'] ?? ''));
    if ($mode === 'yolo_gpu_internal' || hub_is_task_api_mode($mode)) {
        return false;
    }
    if (!hub_is_pack_job_async_mode($mode)) {
        return true;
    }

    return (string)($service['pack_id'] ?? '') === (string)(hub_pack_job_async_routes()[$mode]['pack_id'] ?? '');
}

function hub_public_api_voice_generate_examples(bool $cluster = false, string $mode = 'voice_generate', bool $allowDesign = true): array
{
    if (!hub_is_voice_profile_mode($mode)) {
        throw new InvalidArgumentException('unsupported voice profile mode');
    }
    $api = $cluster ? '<ROUTER_BASE_URL>/cluster_api.php' : '<HUB_BASE_URL>/api.php';
    $statusMode = $cluster ? 'cluster_task_status' : 'task_status';
    $resultMode = $cluster ? 'cluster_task_result' : 'task_result';
    $artifactMode = $cluster ? 'cluster_artifact' : 'artifact';
    $curlAffinity = $cluster ? '# Profile followups use the pinned station with no failover.' : '';
    $codeAffinity = $cluster ? '// Profile followups use the pinned station with no failover.' : '';
    $curlAck = $cluster ? <<<'CURL'
ACK_URL_TEMPLATE_LINK="$(printf '%s' "${SYNTHESIS}" | json_value ack_url_template)"
if [ -n "${ACK_URL_TEMPLATE_LINK}" ]; then
  ACK_URL_TEMPLATE="$(resolve_url "${API}" "${ACK_URL_TEMPLATE_LINK}")"
  ACK_URL="${ACK_URL_TEMPLATE//\{artifact_id\}/${ARTIFACT_ID}}"
  curl -sS -X POST -H "Authorization: Bearer ${TOKEN}" "${ACK_URL}"
fi
CURL : '';
    $curl = strtr(<<<'CURL'
TOKEN='<TOKEN>'
API='{{API}}'
{{AFFINITY}}
json_value() {
  php -r '$value=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR); foreach(explode(".",$argv[1]) as $key){if(!is_array($value)||!array_key_exists($key,$value)){exit;} $value=$value[$key];} if(is_scalar($value)){echo $value;}' "$1"
}
resolve_url() {
  php -r '$base=$argv[1];$link=$argv[2];if(preg_match("~\Ahttps?://~i",$link)===1){echo $link;exit;} $parts=parse_url($base);if($link===""||!is_array($parts)||!isset($parts["scheme"],$parts["host"])){exit(2);} $host=(string)$parts["host"];if(str_contains($host,":")&&!str_starts_with($host,"[")){$host="[".$host."]";} $origin=$parts["scheme"]."://".$host.(isset($parts["port"])?":".(int)$parts["port"]:"");if(str_starts_with($link,"//")){echo $parts["scheme"].":".$link;exit;} if(str_starts_with($link,"/")){echo $origin.$link;exit;} $path=(string)($parts["path"]??"/");if(str_starts_with($link,"?")){echo $origin.$path.$link;exit;} $directory=str_ends_with($path,"/")?$path:rtrim(str_replace("\\","/",dirname($path)),"/")."/";echo $origin.$directory.ltrim($link,"/");' "$1" "$2"
}

PREPARED="$(curl -sS -H "Authorization: Bearer ${TOKEN}" \
  -F 'operation=profile_prepare' -F 'profile_name=<PROFILE_NAME>' \
  -F 'consent_type=self_recorded' -F 'expected_text=<EXPECTED_TEXT>' -F 'reference_wav=@<REFERENCE_WAV>' \
  "${API}?mode={{MODE}}")"
VOICE_PROFILE_TASK_ID="$(printf '%s' "${PREPARED}" | json_value task_id)" # <VOICE_PROFILE_TASK_ID>
STATUS_URL_LINK="$(printf '%s' "${PREPARED}" | json_value status_url)"
STATUS_URL="$(resolve_url "${API}" "${STATUS_URL_LINK}")"
# MyAI stores VOICE_PROFILE_TASK_ID and follows the returned status_url.
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  "${STATUS_URL}" # returned mode={{STATUS_MODE}}
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  "${API}?mode={{MODE}}&operation=profile_status&voice_profile_task_id=${VOICE_PROFILE_TASK_ID}"
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  --data-urlencode 'operation=profile_confirm' \
  --data-urlencode "voice_profile_task_id=${VOICE_PROFILE_TASK_ID}" \
  --data-urlencode 'prompt_text=<CONFIRMED_TRANSCRIPT>' \
  "${API}?mode={{MODE}}"
{{DESIGN}}
SYNTHESIS="$(curl -sS -H "Authorization: Bearer ${TOKEN}" \
  -F 'operation=synthesize' -F 'text=<TEXT>' -F 'mode=ultimate_clone' \
  -F "voice_profile_task_id=${VOICE_PROFILE_TASK_ID}" \
  "${API}?mode={{MODE}}")"
TASK_ID="$(printf '%s' "${SYNTHESIS}" | json_value task_id)" # <TASK_ID>
RESULT_URL_LINK="$(printf '%s' "${SYNTHESIS}" | json_value result_url)"
RESULT_URL="$(resolve_url "${API}" "${RESULT_URL_LINK}")"
ARTIFACT_URL_TEMPLATE_LINK="$(printf '%s' "${SYNTHESIS}" | json_value artifact_url_template)"
ARTIFACT_URL_TEMPLATE="$(resolve_url "${API}" "${ARTIFACT_URL_TEMPLATE_LINK}")"
RESULT="$(curl -sS -H "Authorization: Bearer ${TOKEN}" "${RESULT_URL}")" # {{RESULT_MODE}}
ARTIFACT_ID="$(printf '%s' "${RESULT}" | json_value result.artifacts.0.id)" # <ARTIFACT_ID>
# The returned template targets mode={{ARTIFACT_MODE}}; expand that returned value.
ARTIFACT_URL="${ARTIFACT_URL_TEMPLATE//\{artifact_id\}/${ARTIFACT_ID}}"
curl -sS -H "Authorization: Bearer ${TOKEN}" "${ARTIFACT_URL}" # {{ARTIFACT_MODE}}
{{ACK}}
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  -d 'operation=profile_delete' \
  --data-urlencode "voice_profile_task_id=${VOICE_PROFILE_TASK_ID}" \
  "${API}?mode={{MODE}}"
CURL, [
        '{{API}}' => $api,
        '{{MODE}}' => $mode,
        '{{STATUS_MODE}}' => $statusMode,
        '{{RESULT_MODE}}' => $resultMode,
        '{{ARTIFACT_MODE}}' => $artifactMode,
        '{{AFFINITY}}' => $curlAffinity,
        '{{ACK}}' => $curlAck,
    ]);
    $curl = str_replace('{{DESIGN}}', $allowDesign ? <<<'CURL'
curl -sS -H "Authorization: Bearer ${TOKEN}" \
  -F 'text=<TEXT>' -F 'mode=design' -F 'voice_prompt=<VOICE_PROMPT>' \
  "${API}?mode=voice_generate"
CURL : '', $curl);
    $phpAck = $cluster ? <<<'PHP'
if (isset($synthesis['ack_url_template'])) {
    $ackUrlTemplate = $resolveUrl($api, (string)$synthesis['ack_url_template']);
    $decode($request(str_replace('{artifact_id}', (string)$artifactId, $ackUrlTemplate), ''));
}
PHP : '';
    $php = strtr(<<<'PHP'
$token = '<TOKEN>';
$api = '{{API}}';
{{AFFINITY}}
$resolveUrl = static function (string $base, string $link): string {
    if (preg_match('~\Ahttps?://~i', $link) === 1) {
        return $link;
    }
    $parts = parse_url($base);
    if ($link === '' || !is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('Invalid API link.');
    }
    $host = (string)$parts['host'];
    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = '[' . $host . ']';
    }
    $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    if (str_starts_with($link, '//')) {
        return $parts['scheme'] . ':' . $link;
    }
    if (str_starts_with($link, '/')) {
        return $origin . $link;
    }
    $path = (string)($parts['path'] ?? '/');
    if (str_starts_with($link, '?')) {
        return $origin . $path . $link;
    }
    $directory = str_ends_with($path, '/') ? $path : rtrim(str_replace('\\', '/', dirname($path)), '/') . '/';

    return $origin . $directory . ltrim($link, '/');
};
$request = static function (string $url, mixed $body = null, array $headers = []) use ($token): string {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $token], $headers),
    ];
    if ($body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    if (!is_string($response)) {
        throw new RuntimeException(curl_error($ch));
    }
    return $response;
};
$decode = static fn (string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR);

$prepared = $decode($request($api . '?mode={{MODE}}', [
    'operation' => 'profile_prepare',
    'profile_name' => '<PROFILE_NAME>',
    'consent_type' => 'self_recorded',
    'reference_wav' => new CURLFile('<REFERENCE_WAV>'),
]));
$voiceProfileTaskId = $prepared['task_id']; // MyAI stores this as <VOICE_PROFILE_TASK_ID>.
$statusUrl = $resolveUrl($api, (string)$prepared['status_url']);
$decode($request($statusUrl)); // {{STATUS_MODE}}
$decode($request($api . '?mode={{MODE}}&operation=profile_status&voice_profile_task_id=' . rawurlencode((string)$voiceProfileTaskId)));
$decode($request($api . '?mode={{MODE}}', json_encode([
    'operation' => 'profile_confirm',
    'voice_profile_task_id' => $voiceProfileTaskId,
    'prompt_text' => '<CONFIRMED_TRANSCRIPT>',
], JSON_THROW_ON_ERROR), ['Content-Type: application/json']));
{{DESIGN}}
$synthesis = $decode($request($api . '?mode={{MODE}}', json_encode([
    'operation' => 'synthesize',
    'text' => '<TEXT>',
    'mode' => 'ultimate_clone',
    'voice_profile_task_id' => $voiceProfileTaskId,
], JSON_THROW_ON_ERROR), ['Content-Type: application/json']));
$taskId = $synthesis['task_id']; // <TASK_ID>
$resultUrl = $resolveUrl($api, (string)$synthesis['result_url']);
$result = $decode($request($resultUrl)); // {{RESULT_MODE}}
$artifactId = $result['result']['artifacts'][0]['id']; // <ARTIFACT_ID>
$artifactUrlTemplate = $resolveUrl($api, (string)$synthesis['artifact_url_template']);
$artifactUrl = str_replace('{artifact_id}', (string)$artifactId, $artifactUrlTemplate);
$audio = $request($artifactUrl); // {{ARTIFACT_MODE}}
{{ACK}}
$decode($request($api . '?mode={{MODE}}', json_encode([
    'operation' => 'profile_delete',
    'voice_profile_task_id' => $voiceProfileTaskId,
], JSON_THROW_ON_ERROR), ['Content-Type: application/json']));
PHP, [
        '{{API}}' => $api,
        '{{MODE}}' => $mode,
        '{{STATUS_MODE}}' => $statusMode,
        '{{RESULT_MODE}}' => $resultMode,
        '{{ARTIFACT_MODE}}' => $artifactMode,
        '{{AFFINITY}}' => $codeAffinity,
        '{{ACK}}' => $phpAck,
    ]);
    $php = str_replace('{{DESIGN}}', $allowDesign ? <<<'PHP'
$decode($request($api . '?mode=voice_generate', json_encode([
    'text' => '<TEXT>',
    'mode' => 'design',
    'voice_prompt' => '<VOICE_PROMPT>',
], JSON_THROW_ON_ERROR), ['Content-Type: application/json']));
PHP : '', $php);
    $jsAck = $cluster ? <<<'JS'
if (synthesis.ack_url_template) {
  const ackUrlTemplate = resolveUrl(synthesis.ack_url_template);
  await call(ackUrlTemplate.replace('{artifact_id}', artifactId), {method: 'POST'});
}
JS : '';
    $js = strtr(<<<'JS'
const token = '<TOKEN>';
const api = '{{API}}';
{{AFFINITY}}
const resolveUrl = (link) => new URL(link, api).toString();
const call = async (url, options = {}) => {
  const response = await fetch(url, {
    ...options,
    headers: {Authorization: `Bearer ${token}`, ...(options.headers || {})},
  });
  return response.json();
};

const profile = new FormData();
profile.append('operation', 'profile_prepare');
profile.append('profile_name', '<PROFILE_NAME>');
profile.append('consent_type', 'self_recorded');
profile.append('reference_wav', new File([], '<REFERENCE_WAV>', {type: 'audio/wav'}));
const prepared = await call(`${api}?mode={{MODE}}`, {method: 'POST', body: profile});
const voiceProfileTaskId = prepared.task_id; // MyAI stores this as <VOICE_PROFILE_TASK_ID>.
const statusUrl = resolveUrl(prepared.status_url);
await call(statusUrl); // {{STATUS_MODE}}
await call(`${api}?mode={{MODE}}&operation=profile_status&voice_profile_task_id=${voiceProfileTaskId}`);
await call(`${api}?mode={{MODE}}`, {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({operation: 'profile_confirm', voice_profile_task_id: voiceProfileTaskId, prompt_text: '<CONFIRMED_TRANSCRIPT>'}),
});
{{DESIGN}}
const synthesis = await call(`${api}?mode={{MODE}}`, {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({operation: 'synthesize', text: '<TEXT>', mode: 'ultimate_clone', voice_profile_task_id: voiceProfileTaskId}),
});
const taskId = synthesis.task_id; // <TASK_ID>
const resultUrl = resolveUrl(synthesis.result_url);
const result = await call(resultUrl); // {{RESULT_MODE}}
const artifactId = result.result.artifacts[0].id; // <ARTIFACT_ID>
const artifactUrlTemplate = resolveUrl(synthesis.artifact_url_template);
const artifactUrl = artifactUrlTemplate.replace('{artifact_id}', artifactId);
const artifactResponse = await fetch(artifactUrl, {headers: {Authorization: `Bearer ${token}`}});
const audio = await artifactResponse.blob(); // {{ARTIFACT_MODE}}
{{ACK}}
await call(`${api}?mode={{MODE}}`, {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({operation: 'profile_delete', voice_profile_task_id: voiceProfileTaskId}),
});
JS, [
        '{{API}}' => $api,
        '{{MODE}}' => $mode,
        '{{STATUS_MODE}}' => $statusMode,
        '{{RESULT_MODE}}' => $resultMode,
        '{{ARTIFACT_MODE}}' => $artifactMode,
        '{{AFFINITY}}' => $codeAffinity,
        '{{ACK}}' => $jsAck,
    ]);
    $js = str_replace('{{DESIGN}}', $allowDesign ? <<<'JS'
await call(`${api}?mode=voice_generate`, {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({text: '<TEXT>', mode: 'design', voice_prompt: '<VOICE_PROMPT>'}),
});
JS : '', $js);

    return ['curl' => $curl, 'php' => $php, 'js_fetch' => $js];
}

function hub_public_api_voice_generate_contract(array $contract, string $mode = 'voice_generate'): array
{
    if (!hub_is_voice_profile_mode($mode)) {
        throw new InvalidArgumentException('unsupported voice profile mode');
    }
    foreach ($contract['input']['fields'] as &$field) {
        if (($field['name'] ?? '') === 'text') {
            $field['example'] = '<TEXT>';
        } elseif (($field['name'] ?? '') === 'voice_prompt') {
            $field['example'] = '<VOICE_PROMPT>';
        }
    }
    unset($field);
    $taskOutput = ['ok', 'task_id', 'status', 'status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'];
    $profileTaskField = ['name' => 'voice_profile_task_id', 'type' => 'string', 'required' => true, 'max_length' => 18];
    $profileStatusOutput = [
        'ok', 'task_status', 'profile_status', 'transcription_status', 'transcription_error',
        'transcript_confirmed', 'prompt_text_confirmed_at', 'profile_name', 'language',
        'consent_type', 'reference_audio_sha256', 'created_at', 'updated_at',
    ];
    $profileValidationOutput = [
        'name' => 'validation',
        'type' => 'object',
        'condition' => 'Returned when a Whisper transcript is available; includes cer, status, needs_confirmation, and normalizer. When status=error it also includes error=transcript_validation_failed; error is omitted for every other status.',
    ];
    $contract['operations'] = [
        [
            'operation' => 'profile_prepare',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'input_fields' => [
                ['name' => 'reference_wav', 'type' => 'file', 'required' => true, 'max_mb' => 100],
                ['name' => 'profile_name', 'type' => 'string', 'required' => true, 'max_length' => 120],
                ['name' => 'consent_type', 'type' => 'string', 'required' => true, 'enum' => ['self_recorded', 'explicit_permission', 'licensed_voice']],
                ['name' => 'prompt_text', 'type' => 'string', 'required' => false, 'max_length' => 20000],
                ['name' => 'expected_text', 'type' => 'string', 'required' => false, 'max_length' => 20000, 'description' => 'Optional ground-truth text for Whisper CER validation.'],
                ['name' => 'transcript_confirmed', 'type' => 'boolean', 'required' => false],
                ['name' => 'language', 'type' => 'string', 'required' => false, 'max_length' => 64],
                ['name' => 'callback_target', 'type' => 'string', 'required' => false],
            ],
            'output_keys' => $taskOutput,
        ],
        [
            'operation' => 'profile_status',
            'method' => 'GET or POST',
            'input_fields' => [$profileTaskField],
            'output_keys' => $profileStatusOutput,
            'conditional_output_fields' => [[
                'name' => 'prompt_text',
                'type' => 'string',
                'condition' => 'Returned only to the authenticated Profile member when transcript_confirmed=false; omitted after confirmation.',
                'max_length' => 20000,
            ], [
                'name' => 'transcript',
                'type' => 'object',
                'condition' => 'Returned to the authenticated Profile member while the transcript is unconfirmed; contains raw Whisper text and normalized validation text.',
            ], [
                'name' => 'expected_text',
                'type' => 'object or null',
                'condition' => 'Returned when expected_text was supplied; raw and normalized forms are included before confirmation.',
            ], $profileValidationOutput],
        ],
        [
            'operation' => 'profile_confirm',
            'method' => 'POST',
            'content_type' => 'application/json or application/x-www-form-urlencoded',
            'input_fields' => [
                $profileTaskField,
                ['name' => 'prompt_text', 'type' => 'string', 'required' => true, 'max_length' => 20000],
            ],
            'output_keys' => [...$profileStatusOutput, 'voice_profile_task_id', 'prompt_text_sha256'],
            'conditional_output_fields' => [$profileValidationOutput],
        ],
        [
            'operation' => 'profile_delete',
            'method' => 'POST',
            'content_type' => 'application/json or application/x-www-form-urlencoded',
            'input_fields' => [$profileTaskField],
            'output_keys' => $profileStatusOutput,
        ],
        [
            'operation' => 'synthesize',
            'method' => 'POST',
            'content_type' => 'multipart/form-data or application/json',
            'default_when_omitted' => true,
            'modes' => array_values((array)(array_column((array)$contract['input']['fields'], null, 'name')['mode']['enum'] ?? [])),
            'input_fields' => $contract['input']['fields'],
            'output_keys' => $taskOutput,
        ],
    ];
    $contract['workflow'] = [
        'client_state' => 'MyAI stores voice_profile_task_id returned by profile_prepare.',
        'profile_ownership' => 'After profile_prepare succeeds, the Profile handle belongs to the API member and may be used by any currently valid Token for that member with ' . $mode . ' permission. Task and artifact followups remain bound to the submitting Token.',
        'operation_default' => 'Omitting operation means synthesize.',
        'profile_status_visibility' => 'For the authenticated Profile member, profile_status may include the unconfirmed ASR draft and transcript validation (raw/normalized); the confirmed transcript is omitted.',
        'transcript_validation' => 'profile_prepare accepts optional expected_text. Whisper raw text is preserved as transcript.raw, both sides use OpenCC s2twp normalization, and CER is Levenshtein distance divided by normalized expected character count. status is clean at CER 0, pass at <= 0.05, review_required above 0.05, and unverified when expected_text is absent. profile_prepare never confirms a profile; call profile_confirm with the human-reviewed text.',
        'profile_confirmation_proof' => 'profile_confirm returns the caller voice_profile_task_id handle (opaque through Cluster) and lowercase SHA-256 prompt_text_sha256 computed from the authoritative stored exact UTF-8 bytes; confirmed prompt_text is omitted.',
        'steps' => [
            'profile_prepare',
            'task_status via returned status_url',
            'profile_status',
            'profile_confirm',
            'synthesize with mode=ultimate_clone',
            'task_result via returned result_url',
            'expand returned artifact_url_template with result.artifacts[].id',
            'profile_delete',
        ],
    ];
    if ($mode === 'voice_generate_gpt_sovits') {
        $prepareFields = &$contract['operations'][0]['input_fields'];
        $prepareFields = array_values(array_filter(
            $prepareFields,
            static fn (array $field): bool => !in_array((string)($field['name'] ?? ''), ['prompt_text', 'transcript_confirmed'], true)
        ));
        $prepareFields[0]['description'] = 'MyAI derives a mono 32 kHz 3–10 second GPT-SoVITS reference before ASR; clients confirm the returned ASR draft with profile_confirm.';
        unset($prepareFields);
        $contract['workflow']['profile_reference'] = 'GPT-SoVITS derives a mono 32 kHz 3–10 second reference before ASR. Confirm its returned ASR draft with profile_confirm; client-supplied transcript text is rejected.';
    }
    $contract['error_table'] = [
        ['code' => 'invalid_request', 'http_status' => 400],
        ['code' => 'voice_profile_wav_invalid', 'http_status' => 400],
        ['code' => 'voice_profile_transcript_invalid', 'http_status' => 400],
        ['code' => 'voice_profile_forbidden', 'http_status' => 403],
        ['code' => 'voice_profile_not_found', 'http_status' => 404],
        ['code' => 'voice_profile_prepare_conflict', 'http_status' => 409],
        ['code' => 'voice_profile_callback_conflict', 'http_status' => 409],
        ['code' => 'voice_profile_transcript_unconfirmed', 'http_status' => 409],
        ['code' => 'voice_profile_prepare_incomplete', 'http_status' => 409],
        ['code' => 'voice_profile_confirm_failed', 'http_status' => 409],
        ['code' => 'voice_profile_prepare_failed', 'http_status' => 500],
        ['code' => 'transcript_validation_failed', 'http_status' => 500],
        ['code' => 'voice_profile_delete_failed', 'http_status' => 500],
        ['code' => 'voice_profile_changed', 'task_status' => 'failed'],
        ['code' => 'voice_profile_unavailable', 'http_status' => 410, 'task_status' => 'failed'],
        ['code' => 'pack_runtime_not_ready', 'http_status' => 503],
    ];
    if ($mode === 'voice_generate_gpt_sovits') {
        $contract['error_table'][] = ['code' => 'voice_profile_reprepare_required', 'http_status' => 409];
    }
    $contract['errors'] = array_values(array_unique(array_merge($contract['errors'], array_column($contract['error_table'], 'code'))));
    $contract['workflow_examples'] = hub_public_api_voice_generate_examples(false, $mode, $mode === 'voice_generate');

    return $contract;
}

function hub_public_api_facebook_crawl_contract(array $route): array
{
    $artifactTypes = array_values(array_filter(array_map(
        static fn (mixed $artifact): string => is_array($artifact) ? (string)($artifact['type'] ?? '') : '',
        (array)($route['artifact_contract']['artifacts'] ?? [])
    )));

    return [
        'method' => 'POST',
        'content_type' => 'application/json',
        'execution_type' => 'async_task',
        'task_type' => 'pack_job',
        'input' => ['fields' => [
            [
                'name' => 'profile_id',
                'type' => 'string',
                'required' => false,
                'description' => 'Optional node-local managed Facebook login profile. Omit it for public pages that do not require login.',
            ],
            [
                'name' => 'targets',
                'type' => 'array',
                'required' => true,
                'min_items' => 1,
                'max_items' => 30,
                'items' => [
                    'type' => 'object',
                    'required' => ['url'],
                    'properties' => ['url' => ['type' => 'string', 'format' => 'uri']],
                ],
                'example' => [['url' => 'https://www.facebook.com/wra.gov.tw']],
            ],
            [
                'name' => 'limit_per_target',
                'type' => 'integer',
                'required' => false,
                'default' => 10,
                'min' => 10,
                'max' => 30,
            ],
        ]],
        'output' => [
            'required_keys' => ['ok', 'task_id', 'status', 'status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'],
            'result_artifact_fields' => ['id', 'type', 'mime_type', 'size_bytes', 'sha256'],
            'result_artifact_types' => $artifactTypes,
            'artifact_delivery_note' => 'The JSONL dataset is private to the submitting API member and retained for 30 days. Use facebook_dataset_items for bounded pagination.',
        ],
        'task_api' => [
            'status' => 'GET api.php?mode=task_status&task_id={task_id}',
            'result' => 'GET api.php?mode=task_result&task_id={task_id}',
            'log' => 'GET api.php?mode=task_log&task_id={task_id}',
            'cancel' => 'POST api.php?mode=task_cancel&task_id={task_id}',
            'artifact' => 'GET api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'operations' => [
            ['mode' => 'facebook_profile_start', 'method' => 'POST', 'purpose' => 'Create a node-local login profile and open a short-lived browser or password-assisted login session.'],
            ['mode' => 'facebook_profile_status', 'method' => 'GET', 'query' => ['profile_id'], 'purpose' => 'Read the owned profile state.'],
            ['mode' => 'facebook_profile_reauth', 'method' => 'POST', 'purpose' => 'Reopen login for an idle owned profile.'],
            ['mode' => 'facebook_profile_delete', 'method' => 'POST', 'purpose' => 'Delete an idle owned profile and its private browser state.'],
            ['mode' => 'facebook_crawl', 'method' => 'POST', 'purpose' => 'Submit one manual background crawl.'],
            ['mode' => 'task_status', 'method' => 'GET', 'query' => ['task_id'], 'purpose' => 'Poll task state.'],
            ['mode' => 'task_result', 'method' => 'GET', 'query' => ['task_id'], 'purpose' => 'Read terminal metadata and artifact IDs.'],
            ['mode' => 'artifact', 'method' => 'GET', 'query' => ['artifact_id'], 'purpose' => 'Download the private JSONL artifact.'],
            ['mode' => 'facebook_run_last', 'method' => 'GET', 'purpose' => 'Read the latest terminal run for this API member.'],
            ['mode' => 'facebook_dataset_items', 'method' => 'GET', 'query' => ['task_id', 'offset', 'limit'], 'purpose' => 'Read up to 500 JSONL items without downloading the full artifact.'],
        ],
        'workflow' => [
            'Use facebook_profile_start only when a target needs an authenticated account; complete 2FA or CAPTCHA manually in the short-lived local login page.',
            'Submit facebook_crawl once with 1-30 target URLs and a recent-post limit of 10-30.',
            'Poll task_status, then read task_result or facebook_run_last and page facebook_dataset_items.',
        ],
        'error_table' => [
            ['code' => 'facebook_profile_not_found', 'http_status' => 404],
            ['code' => 'facebook_profile_unavailable', 'http_status' => 409],
            ['code' => 'dataset_not_found', 'http_status' => 404],
            ['code' => 'dataset_expired', 'http_status' => 410],
        ],
        'errors' => [
            'method_not_allowed', 'content_type_invalid', 'payload_too_large', 'invalid_request',
            'member_required', 'facebook_profile_not_found', 'facebook_profile_unavailable',
            'facebook_crawl_unavailable', 'dataset_not_found', 'dataset_expired', 'dataset_invalid',
            'missing_token', 'token_mode_not_allowed',
        ],
    ];
}

function hub_public_api_pack_job_async_contract(array $route): array
{
    if (($route['requested_mode'] ?? '') === 'facebook_crawl') {
        return hub_public_api_facebook_crawl_contract($route);
    }

    $fields = [];
    foreach ($route['request_schema'] as $name => $definition) {
        $field = ['name' => (string)$name] + $definition;
        if (!array_key_exists('default', $field)) {
            if (is_array($field['enum'] ?? null) && $field['enum'] !== []) {
                $field['example'] = $field['enum'][0];
            } elseif ($name === 'text') {
                $field['example'] = 'Hello from 3waAIHub.';
            } elseif ($name === 'voice_prompt') {
                $field['example'] = 'A calm, warm narrator.';
            }
        }
        $fields[] = $field;
    }
    $fields[] = ['name' => 'callback', 'type' => 'boolean', 'required' => false];
    $fields[] = ['name' => 'callback_target', 'type' => 'string', 'required' => false];
    $fields[] = ['name' => 'priority', 'type' => 'integer', 'required' => false, 'default' => 0, 'min' => 0, 'max' => 100];
    if ($route['source_required']) {
        $oneOf = ['file', 'source_artifact_id'];
        array_unshift(
            $fields,
            [
                'name' => 'file',
                'type' => 'file',
                'required' => false,
                'example_include' => true,
                'example' => 'sample.wav',
                'max_mb' => intdiv((int)$route['max_upload_bytes'] + 1048575, 1048576),
                'max_bytes' => (int)$route['max_upload_bytes'],
                'source_artifact_types' => array_values($route['source_artifact_types']),
                'one_of' => $oneOf,
                'one_of_required' => true,
            ],
            [
                'name' => 'source_artifact_id',
                'type' => 'integer',
                'required' => false,
                'min' => 1,
                'one_of' => $oneOf,
                'one_of_required' => true,
            ]
        );
    }

    $artifactTypes = array_values(array_filter(array_map(
        static fn (mixed $artifact): string => is_array($artifact) ? (string)($artifact['type'] ?? '') : '',
        (array)($route['artifact_contract']['artifacts'] ?? [])
    )));
    $contract = [
        'method' => 'POST',
        'content_type' => 'multipart/form-data',
        'execution_type' => 'async_task',
        'task_type' => 'pack_job',
        'input' => ['fields' => $fields],
        'output' => [
            'required_keys' => ['ok', 'task_id', 'status', 'status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'],
            'result_artifact_fields' => ['id', 'type', 'mime_type', 'size_bytes', 'sha256'],
            'result_artifact_types' => $artifactTypes,
            'artifact_delivery_note' => 'Choose id from result.artifacts[] and expand the artifact_url_template returned by the submit response. Task and artifact access requires the submitting Bearer Token.',
        ],
        'task_api' => [
            'status' => 'GET api.php?mode=task_status&task_id={task_id}',
            'result' => 'GET api.php?mode=task_result&task_id={task_id}',
            'log' => 'GET api.php?mode=task_log&task_id={task_id}',
            'cancel' => 'POST api.php?mode=task_cancel&task_id={task_id}',
            'artifact' => 'GET api.php?mode=artifact&artifact_id={artifact_id}',
        ],
        'errors' => [
            'method_not_allowed', 'member_required', 'length_required', 'payload_too_large',
            'callback_target_not_found', 'callback_target_disabled', 'capability_unavailable',
            'invalid_request', 'voice_profile_required', 'voice_profile_forbidden',
            'forbidden_task_control', 'source_not_allowed', 'source_required',
            'source_ambiguous', 'source_artifact_invalid', 'source_artifact_not_found',
            'task_upload_workspace_conflict', 'missing_token', 'token_mode_not_allowed',
        ],
    ];
    if (($route['requested_mode'] ?? null) === 'edge_tts') {
        $contract['operations'] = [
            ['method' => 'GET', 'query' => [], 'response' => 'verified voice catalogue JSON'],
            ['method' => 'GET', 'query' => ['voice' => '<voice-id>'], 'response' => 'audio/mpeg; Cache-Control: private, no-store'],
            ['method' => 'POST', 'response' => 'asynchronous synthesis task'],
        ];
    } elseif (hub_is_voice_profile_mode((string)($route['requested_mode'] ?? ''))) {
        $contract = hub_public_api_voice_generate_contract($contract, (string)$route['requested_mode']);
    }

    return $contract;
}

function hub_public_api_service_from_contract(string $mode, array $pack, array $manifest, array $contract): array
{
    $method = hub_public_api_method($manifest, $contract);
    $output = is_array($contract['output'] ?? null) ? $contract['output'] : [];
    $service = [
        'mode' => $mode,
        'pack_id' => (string)($manifest['id'] ?? $pack['id'] ?? ''),
        'name' => (string)($manifest['name'] ?? $pack['id'] ?? ''),
        'description' => (string)($manifest['description'] ?? ''),
        'method' => $method,
        'content_type' => hub_public_api_content_type($method, $contract),
        'endpoint' => 'api.php?mode=' . $mode,
        'url' => hub_public_api_mode_url($mode),
        'execution_type' => (string)($contract['execution_type'] ?? $manifest['execution_type'] ?? ''),
        'runtime_level' => (string)($manifest['runtime_level'] ?? ''),
        'gpu_required' => (bool)($manifest['hardware']['gpu_required'] ?? false),
        'task_type' => (string)($contract['task_type'] ?? ''),
        'input_fields' => is_array($contract['input']['fields'] ?? null) ? $contract['input']['fields'] : [],
        'output_keys' => array_values(array_map('strval', is_array($output['required_keys'] ?? null) ? $output['required_keys'] : [])),
        'response_content_type' => trim((string)($output['content_type'] ?? 'application/json')),
        'response_headers' => array_values(array_map('strval', is_array($output['required_headers'] ?? null) ? $output['required_headers'] : [])),
        'result_artifact_fields' => array_values(array_map('strval', is_array($output['result_artifact_fields'] ?? null) ? $output['result_artifact_fields'] : [])),
        'result_artifact_types' => array_values(array_map('strval', is_array($output['result_artifact_types'] ?? null) ? $output['result_artifact_types'] : [])),
        'artifact_delivery_note' => trim((string)($output['artifact_delivery_note'] ?? '')),
        'error_codes' => array_values(array_map('strval', is_array($contract['errors'] ?? null) ? $contract['errors'] : [])),
        'task_api' => hub_public_api_task_api_refs(is_array($contract['task_api'] ?? null) ? $contract['task_api'] : []),
    ];
    foreach (['operations', 'workflow', 'error_table', 'workflow_examples'] as $key) {
        if (isset($contract[$key])) {
            $service[$key] = $contract[$key];
        }
    }
    $service['examples'] = hub_public_api_examples($service);
    $operationExamples = [];
    foreach ((array)($contract['operations'] ?? []) as $definition) {
        $operation = is_array($definition) ? (string)($definition['operation'] ?? '') : '';
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $operation) !== 1) {
            continue;
        }
        $example = $service;
        $example['operation'] = $operation;
        $example['endpoint'] .= '&operation=' . rawurlencode($operation);
        $example['url'] .= '&operation=' . rawurlencode($operation);
        $example['input_fields'] = array_merge([
            ['name' => 'operation', 'type' => 'string', 'required' => true, 'default' => $operation, 'enum' => [$operation]],
        ], $service['input_fields']);
        if (str_ends_with($operation, '_task')) {
            $example['execution_type'] = 'async_task';
            $example['response_content_type'] = 'application/json';
            $example['response_headers'] = [];
            $example['output_keys'] = ['ok', 'task_id', 'status', 'status_url', 'result_url', 'log_url', 'cancel_url', 'artifact_url_template'];
            $example['task_api'] = hub_public_api_task_api_refs([
                'status' => 'GET api.php?mode=task_status&task_id={task_id}',
                'result' => 'GET api.php?mode=task_result&task_id={task_id}',
                'log' => 'GET api.php?mode=task_log&task_id={task_id}',
                'cancel' => 'POST api.php?mode=task_cancel&task_id={task_id}',
                'artifact' => 'GET api.php?mode=artifact&artifact_id={artifact_id}',
            ]);
        }
        $example['examples'] = hub_public_api_examples($example);
        if ($mode === 'image-tools') {
            $base64Example = $example;
            $base64Example['input_fields'] = array_values(array_filter(array_map(
                static function (array $field): array {
                    if (($field['name'] ?? '') === 'base64_string') {
                        $field['example'] = '<BASE64_STRING>';
                    }

                    return $field;
                },
                $example['input_fields']
            ), static fn (array $field): bool => ($field['name'] ?? '') !== 'image'));
            $base64Example['examples'] = hub_public_api_examples($base64Example);
            $example['base64_examples'] = $base64Example['examples'];
        }
        $operationExamples[] = $example;
    }
    if ($operationExamples !== []) {
        $service['operation_examples'] = $operationExamples;
        $service['examples'] = [];
    }

    return $service;
}

function hub_public_api_services(
    PDO $db,
    ?callable $healthProbe = null,
    ?callable $asyncCatalogLoader = null,
    ?callable $asyncResolver = null
): array
{
    $rows = hub_list_services($db);
    $asyncRoutes = hub_available_pack_job_async_routes_with_catalog(
        $db,
        $asyncCatalogLoader,
        $asyncResolver
    );
    $registeredModes = [];
    foreach ($rows as $row) {
        $mode = trim((string)($row['mode'] ?? ''));
        if ($mode !== '') {
            $registeredModes[$mode] = true;
        }
    }
    $candidates = array_values(array_filter(
        $rows,
        static fn (array $service): bool =>
            (string)($service['install_status'] ?? '') === 'installed'
            && (int)($service['enabled'] ?? 0) === 1
            && (string)($service['runtime_status'] ?? '') === 'running'
            && hub_public_api_service_mode_uses_pack($service)
    ));
    $healthyIds = hub_public_api_healthy_service_ids($candidates, $healthProbe);
    $services = [];
    $derivedParents = [];
    foreach ($candidates as $row) {
        if (!isset($healthyIds[(int)$row['id']])) {
            continue;
        }
        $mode = trim((string)($row['mode'] ?? ''));
        if ($mode === '') {
            continue;
        }
        if (hub_is_pack_job_async_mode($mode)) {
            $asyncRoute = $asyncRoutes[$mode] ?? null;
            if (!is_array($asyncRoute)
                || !is_array($asyncRoute['route'] ?? null)
                || !is_array($asyncRoute['pack'] ?? null)) {
                continue;
            }
            $pack = $asyncRoute['pack'];
            $contract = hub_public_api_pack_job_async_contract($asyncRoute['route']);
        } else {
            $pack = hub_get_pack((string)($row['pack_id'] ?? ''));
            if ($pack === null || ($pack['status'] ?? '') !== 'ok') {
                continue;
            }
            $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
            $contract = hub_public_api_contract_for_manifest($manifest);
        }
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $service = hub_public_api_service_from_contract($mode, $pack, $manifest, $contract);
        $services[$mode] = $service;
        $serviceKey = (string)($row['service_key'] ?? '');
        if ($serviceKey === 'gemma4-main' && $service['pack_id'] === 'llm-gemma4-12b') {
            $derivedParents[$serviceKey] = true;
        }
        if ($serviceKey === 'yolo-cpu' && $service['pack_id'] === 'yolo-serving') {
            $derivedParents[$serviceKey] = true;
        }
    }
    foreach ($asyncRoutes as $mode => $asyncRoute) {
        if (isset($services[$mode])) {
            continue;
        }
        if (!is_array($asyncRoute['route'] ?? null) || !is_array($asyncRoute['pack'] ?? null)) {
            continue;
        }
        $route = $asyncRoute['route'];
        $pack = $asyncRoute['pack'];
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $services[$mode] = hub_public_api_service_from_contract(
            $mode,
            $pack,
            $manifest,
            hub_public_api_pack_job_async_contract($route)
        );
    }
    if (isset($derivedParents['gemma4-main'])) {
        foreach (hub_public_api_gemma4_services() as $service) {
            if (isset($registeredModes[(string)$service['mode']])) {
                continue;
            }
            $service['examples'] = hub_public_api_examples($service);
            $services[(string)$service['mode']] = $service;
        }
    }
    if (isset($derivedParents['yolo-cpu'])) {
        foreach (hub_public_api_yolo_model_services() as $service) {
            if (isset($registeredModes[(string)$service['mode']])) {
                continue;
            }
            $service['examples'] = hub_public_api_examples($service);
            $services[(string)$service['mode']] = $service;
        }
    }

    ksort($services);
    return array_values($services);
}

function hub_public_api_gemma4_services(): array
{
    return [
        [
            'mode' => 'photo_upload',
            'pack_id' => 'llm-gemma4-12b',
            'name' => 'Gemma 4 Photo Upload',
            'description' => 'Upload an image once and reuse image_id for photo questions.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=photo_upload',
            'url' => hub_public_api_mode_url('photo_upload'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L5-benchmark-ready',
            'task_type' => '',
            'input_fields' => [['name' => 'image', 'type' => 'file', 'required' => true, 'example' => 'example.jpg']],
            'output_keys' => ['ok', 'image_id'],
            'error_codes' => ['bad_request', 'file_too_large', 'bad_image', 'missing_token', 'token_mode_denied'],
            'task_api' => [],
        ],
        [
            'mode' => 'photo',
            'pack_id' => 'llm-gemma4-12b',
            'name' => 'Gemma 4 Photo Vision',
            'description' => 'Ask questions by image_id; no server-side session is stored.',
            'method' => 'POST',
            'content_type' => 'application/json',
            'endpoint' => 'api.php?mode=photo',
            'url' => hub_public_api_mode_url('photo'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L5-benchmark-ready',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'image_id', 'type' => 'string', 'required' => true, 'default' => 'img_...'],
                ['name' => 'text', 'type' => 'string', 'required' => true, 'default' => '這張圖裡有什麼？'],
                ['name' => 'max_tokens', 'type' => 'integer', 'required' => false, 'default' => 256],
                ['name' => 'real_inference', 'type' => 'boolean', 'required' => false, 'default' => false],
            ],
            'output_keys' => ['ok', 'mock', 'runtime_level', 'model', 'image_id', 'answer', 'caption', 'tags', 'usage', 'elapsed_ms'],
            'error_codes' => ['image_id_required', 'text_required', 'photo_forbidden', 'model_not_ready', 'vision_timeout', 'vision_bad_response', 'vision_failed'],
            'task_api' => [],
        ],
        [
            'mode' => 'audio_upload',
            'pack_id' => 'llm-gemma4-12b',
            'name' => 'Gemma 4 Audio Upload',
            'description' => 'Upload a short WAV once and reuse audio_id for Gemma 4 audio questions.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=audio_upload',
            'url' => hub_public_api_mode_url('audio_upload'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L5-benchmark-ready',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'audio', 'type' => 'file', 'required' => true, 'example' => 'sample.wav', 'mime' => 'audio/wav', 'max_mb' => 16],
            ],
            'output_keys' => ['ok', 'audio_id', 'mime', 'size', 'duration_ms', 'sample_rate', 'channels', 'expires_at'],
            'error_codes' => ['file_required', 'payload_too_large', 'invalid_audio', 'unsupported_audio_format', 'audio_too_long'],
            'task_api' => [],
        ],
        [
            'mode' => 'audio',
            'pack_id' => 'llm-gemma4-12b',
            'name' => 'Gemma 4 Audio Input',
            'description' => 'Ask about a short WAV directly, or reuse a previously uploaded audio_id. Gemma4 Audio is experimental audio understanding, not production ASR; use Whisper ASR for reliable transcription.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=audio',
            'url' => hub_public_api_mode_url('audio'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L5-benchmark-ready',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'audio', 'type' => 'file', 'required' => false, 'example' => 'sample.wav', 'mime' => 'audio/wav', 'max_mb' => 16],
                ['name' => 'audio_id', 'type' => 'string', 'required' => false, 'default' => 'aud_...'],
                ['name' => 'operation', 'type' => 'string', 'required' => false, 'default' => 'understand'],
                ['name' => 'text', 'type' => 'string', 'required' => false, 'default' => '這段錄音的重點是什麼？'],
                ['name' => 'max_tokens', 'type' => 'integer', 'required' => false, 'default' => 512],
                ['name' => 'real_inference', 'type' => 'boolean', 'required' => false, 'default' => true],
            ],
            'output_keys' => ['ok', 'mock', 'runtime_level', 'model', 'operation', 'answer', 'transcript', 'summary', 'tags', 'warnings', 'audio', 'usage', 'elapsed_ms'],
            'error_codes' => ['file_required', 'payload_too_large', 'invalid_audio', 'unsupported_audio_format', 'audio_too_long', 'audio_not_found', 'model_not_ready', 'audio_failed'],
            'task_api' => [],
        ],
    ];
}

function hub_public_api_yolo_model_services(): array
{
    return [
        [
            'mode' => 'yolo_model_register',
            'pack_id' => 'yolo-serving',
            'name' => 'YOLO Model Register',
            'description' => 'Register an allowlisted YOLO Detect .pt host artifact into the Hub model registry.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=yolo_model_register',
            'url' => hub_public_api_mode_url('yolo_model_register'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L3-storage-mount',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'source_system', 'type' => 'string', 'required' => true, 'default' => 'natureweb'],
                ['name' => 'external_model_key', 'type' => 'string', 'required' => true, 'default' => 'training_result_47'],
                ['name' => 'display_name', 'type' => 'string', 'required' => false, 'default' => 'NatureWeb training result 47'],
                ['name' => 'artifact_path', 'type' => 'string', 'required' => true, 'default' => '<ALLOWLISTED_HOST_PATH>/best.pt'],
                ['name' => 'artifact_sha256', 'type' => 'string', 'required' => true, 'default' => '<SHA256>'],
                ['name' => 'task_type', 'type' => 'string', 'required' => false, 'default' => 'detect'],
            ],
            'output_keys' => ['ok', 'model_ref', 'version_id', 'model_version_id', 'state', 'cpu_available', 'warm_state', 'task_type', 'sha256'],
            'error_codes' => ['bad_request', 'model_import_path_not_allowed', 'model_checksum_mismatch', 'model_task_unsupported', 'model_artifact_missing', 'missing_token', 'token_mode_not_allowed'],
            'task_api' => [],
        ],
        [
            'mode' => 'yolo_model_status',
            'pack_id' => 'yolo-serving',
            'name' => 'YOLO Model Status',
            'description' => 'Query Hub registry and GPU warm-pool state for a model_ref.',
            'method' => 'GET',
            'content_type' => '',
            'endpoint' => 'api.php?mode=yolo_model_status&model_ref=yolo:natureweb:training-result-47:v1',
            'url' => hub_public_api_mode_url('yolo_model_status') . '&model_ref=yolo:natureweb:training-result-47:v1',
            'execution_type' => 'sync_api',
            'runtime_level' => 'L3-storage-mount',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'model_ref', 'type' => 'string', 'required' => true, 'default' => 'yolo:natureweb:training-result-47:v1'],
            ],
            'output_keys' => ['ok', 'model_ref', 'version_id', 'model_version_id', 'state', 'cpu_available', 'warm_state', 'gpu.service_available', 'gpu.service.runtime_status', 'gpu.actual_state', 'gpu.blocked_reason', 'task_type', 'sha256'],
            'error_codes' => ['model_ref_required', 'model_not_found', 'missing_token', 'token_mode_not_allowed'],
            'task_api' => [],
        ],
        [
            'mode' => 'yolo_model_assign_gpu',
            'pack_id' => 'yolo-serving',
            'name' => 'YOLO Model Assign GPU Slot',
            'description' => 'Assign a registered YOLO Detect model_ref to fixed yolo-gpu0 slot 1 or 2 and warm it when the GPU runtime is available.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=yolo_model_assign_gpu',
            'url' => hub_public_api_mode_url('yolo_model_assign_gpu'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L3-storage-mount',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'model_ref', 'type' => 'string', 'required' => true, 'default' => 'yolo:natureweb:training-result-47:v1'],
                ['name' => 'slot_no', 'type' => 'integer', 'required' => true, 'default' => 1],
            ],
            'output_keys' => ['ok', 'model_ref', 'version_id', 'model_version_id', 'service_key', 'slot_no', 'warm_state', 'run_id'],
            'error_codes' => ['gpu_slot_invalid', 'gpu_slot_occupied', 'gpu_model_already_assigned', 'gpu_service_unavailable', 'gpu_warm_failed', 'gpu_out_of_memory', 'model_not_found', 'missing_token', 'token_mode_not_allowed'],
            'task_api' => [],
        ],
        [
            'mode' => 'yolo_model_unassign_gpu',
            'pack_id' => 'yolo-serving',
            'name' => 'YOLO Model Unassign GPU Slot',
            'description' => 'Unload a registered YOLO model_ref from the fixed yolo-gpu0 warm pool. Registry model artifacts are not deleted.',
            'method' => 'POST',
            'content_type' => 'multipart/form-data',
            'endpoint' => 'api.php?mode=yolo_model_unassign_gpu',
            'url' => hub_public_api_mode_url('yolo_model_unassign_gpu'),
            'execution_type' => 'sync_api',
            'runtime_level' => 'L3-storage-mount',
            'task_type' => '',
            'input_fields' => [
                ['name' => 'model_ref', 'type' => 'string', 'required' => true, 'default' => 'yolo:natureweb:training-result-47:v1'],
            ],
            'output_keys' => ['ok', 'model_ref', 'version_id', 'model_version_id', 'service_key', 'run_id'],
            'error_codes' => ['gpu_not_ready', 'gpu_model_slot_mismatch', 'gpu_unload_failed', 'model_not_found', 'missing_token', 'token_mode_not_allowed'],
            'task_api' => [],
        ],
    ];
}

function hub_public_api_task_api_refs(array $taskApi): array
{
    $refs = [];
    foreach ($taskApi as $key => $value) {
        $ref = (string)$value;
        if ($ref === '') {
            continue;
        }
        $refs[(string)$key] = str_replace('api.php?', hub_public_api_base_url() . '?', $ref);
    }

    return $refs;
}

function hub_public_api_json_body(array $service): array
{
    $body = [];
    foreach ($service['input_fields'] as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = (string)($field['name'] ?? '');
        if ($name === '' || ($name === 'mode' && !hub_is_pack_job_async_mode((string)($service['mode'] ?? ''))) || ($field['type'] ?? '') === 'file') {
            continue;
        }
        $body[$name] = match ($name) {
            'text' => 'That was a wonderful time.',
            'image_id' => 'img_...',
            'source_lang' => 'en',
            'target_lang' => 'zh-TW',
            'real_inference' => true,
            'targets' => $field['example'] ?? [['url' => 'https://www.facebook.com/wra.gov.tw']],
            default => $field['default'] ?? '',
        };
    }

    return $body;
}

function hub_public_api_multipart_fields(array $service): array
{
    $fields = [];
    foreach ($service['input_fields'] as $field) {
        if (!is_array($field)) {
            continue;
        }
        $name = (string)($field['name'] ?? '');
        if ($name === '' || ($name === 'mode' && !hub_is_pack_job_async_mode((string)($service['mode'] ?? '')))) {
            continue;
        }
        if ($name === 'base64_string' && empty($field['required']) && !array_key_exists('default', $field) && !array_key_exists('example', $field)) {
            continue;
        }
        $type = (string)($field['type'] ?? '');
        if ($type === 'file') {
            if (empty($field['required']) && empty($field['example_include']) && !(($service['mode'] ?? '') === 'sam3' && $name === 'guidance_mask')) {
                continue;
            }
            $sample = (string)($field['example'] ?? '');
            if ($sample === '') {
                $sample = $name === 'audio' ? 'sample.wav' : ($name === 'file' ? 'sample.pdf' : 'sample.png');
            }
            $fields[] = $name . '=@' . $sample;
            continue;
        }
        if ($name === 'points_json') {
            $fields[] = $name . '={"points":[[320,240]],"labels":[1]}';
            continue;
        }
        if (($service['mode'] ?? '') === 'sam3' && $name === 'prompt_type') {
            $fields[] = $name . '=guidance_mask';
            continue;
        }
        if (($service['mode'] ?? '') === 'sam3' && $name === 'output_format') {
            $fields[] = $name . '=png';
            continue;
        }
        $fields[] = $name . '=' . hub_public_api_example_field_value($field);
    }

    return $fields;
}

function hub_public_api_example_field_value(array $field): string
{
    $value = $field['example'] ?? $field['default'] ?? (($field['name'] ?? '') === 'real_inference' ? true : '');

    return is_bool($value) ? ($value ? '1' : '0') : (string)$value;
}

function hub_public_api_examples(array $service): array
{
    if (($service['execution_type'] ?? '') === 'async_task') {
        $service['input_fields'] = array_values(array_filter(
            $service['input_fields'],
            static fn (mixed $field): bool => is_array($field)
                && (!empty($field['required']) || array_key_exists('default', $field) || array_key_exists('example', $field) || !empty($field['example_include']))
        ));
    }
    $url = (string)$service['url'];
    $method = (string)$service['method'];
    $contentType = (string)$service['content_type'];
    $binaryPng = (string)($service['response_content_type'] ?? '') === 'image/png';
    $isWindows = hub_platform_id() === 'windows';
    $curlExecutable = $isWindows ? 'curl.exe' : 'curl';
    $continuation = $isWindows ? '`' : '\\';
    $quoteArgument = static fn (string $value): string => $isWindows
        ? "'" . str_replace("'", "''", $value) . "'"
        : escapeshellarg($value);
    $jsPrefix = '';
    if ($method === 'GET') {
        $curl = $curlExecutable . ' -H "Authorization: Bearer <TOKEN>" ' . $quoteArgument($url);
        $phpBody = '';
        $jsOptions = "{\n  headers: { Authorization: 'Bearer <TOKEN>' }\n}";
    } elseif ($contentType === 'application/json') {
        $body = json_encode(hub_public_api_json_body($service), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $curl = $curlExecutable . ' -X ' . $method . ' ' . $quoteArgument($url) . ' ' . $continuation . "\n"
            . '  -H "Authorization: Bearer <TOKEN>" ' . $continuation . "\n"
            . '  -H "Content-Type: application/json" ' . $continuation . "\n"
            . '  -d ' . $quoteArgument((string)$body);
        $phpBody = '$payload = ' . var_export(json_decode((string)$body, true) ?: [], true) . ";\n";
        $jsOptions = "{\n  method: '{$method}',\n  headers: {\n    Authorization: 'Bearer <TOKEN>',\n    'Content-Type': 'application/json'\n  },\n  body: JSON.stringify(" . json_encode(json_decode((string)$body, true) ?: [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ")\n}";
    } else {
        $fields = hub_public_api_multipart_fields($service);
        $curl = $curlExecutable . ' -X ' . $method . ' ' . $quoteArgument($url) . ' ' . $continuation . "\n"
            . '  -H "Authorization: Bearer <TOKEN>"';
        foreach ($fields as $field) {
            $curl .= ' ' . $continuation . "\n" . '  -F ' . $quoteArgument($field);
        }
        $phpLines = ["\$fields = ["];
        $jsLines = ["const formData = new FormData();"];
        $fileInputCount = 0;
        foreach ($service['input_fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string)($field['name'] ?? '');
            if ($name === '' || ($name === 'mode' && !hub_is_pack_job_async_mode((string)($service['mode'] ?? '')))) {
                continue;
            }
            if ($name === 'base64_string' && empty($field['required']) && !array_key_exists('default', $field) && !array_key_exists('example', $field)) {
                continue;
            }
            if (($field['type'] ?? '') === 'file') {
                if (empty($field['required']) && empty($field['example_include'])) {
                    continue;
                }
                $sample = (string)($field['example'] ?? '');
                if ($sample === '') {
                    $sample = $name === 'audio' ? 'sample.wav' : ($name === 'file' ? 'sample.pdf' : 'sample.png');
                }
                $phpLines[] = '    ' . var_export($name, true) . ' => new CURLFile(' . var_export('/path/to/' . $sample, true) . '),';
                $inputVar = $fileInputCount === 0 ? 'fileInput' : preg_replace('/[^A-Za-z0-9_]/', '_', $name) . 'Input';
                $fileInputCount++;
                $jsLines[] = "const {$inputVar} = document.querySelector('input[name=\"" . addcslashes($name, "\\'") . "\"]');";
                $jsLines[] = 'formData.append(' . var_export($name, true) . ', ' . $inputVar . '.files[0]);';
                continue;
            }
            if ($name === 'points_json') {
                $value = '{"points":[[320,240]],"labels":[1]}';
            } elseif (($service['mode'] ?? '') === 'sam3' && $name === 'prompt_type') {
                $value = 'guidance_mask';
            } elseif (($service['mode'] ?? '') === 'sam3' && $name === 'output_format') {
                $value = 'png';
            } else {
                $value = hub_public_api_example_field_value($field);
            }
            $phpLines[] = '    ' . var_export($name, true) . ' => ' . var_export($value, true) . ',';
            $jsLines[] = 'formData.append(' . var_export($name, true) . ', ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');';
        }
        $phpLines[] = "];";
        $phpBody = implode("\n", $phpLines) . "\n";
        $jsOptions = "{\n  method: '{$method}',\n  headers: { Authorization: 'Bearer <TOKEN>' },\n  body: formData\n}";
        $jsPrefix = implode("\n", $jsLines) . "\n";
    }

    if ($binaryPng) {
        $curl .= ' ' . $continuation . "\n" . '  --output result.png';
    }

    $headers = ["'Authorization: Bearer <TOKEN>'"];
    $postFields = '';
    if ($method !== 'GET' && $contentType === 'application/json') {
        $headers[] = "'Content-Type: application/json'";
        $postFields = "    CURLOPT_POSTFIELDS => json_encode(\$payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),\n";
    } elseif ($method !== 'GET') {
        $postFields = "    CURLOPT_POSTFIELDS => \$fields,\n";
    }
    $php = $phpBody
        . '$ch = curl_init(' . var_export($url, true) . ");\n"
        . "curl_setopt_array(\$ch, [\n"
        . "    CURLOPT_RETURNTRANSFER => true,\n"
        . "    CURLOPT_CUSTOMREQUEST => '{$method}',\n"
        . $postFields
        . '    CURLOPT_HTTPHEADER => [' . implode(', ', $headers) . "],\n"
        . "]);\n";
    if ($binaryPng) {
        $php .= "\$result = curl_exec(\$ch);\n"
            . "\$status = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);\n"
            . "\$mime = strtolower((string)curl_getinfo(\$ch, CURLINFO_CONTENT_TYPE));\n"
            . "if (\$result === false || \$status < 200 || \$status >= 300 || !str_starts_with(\$mime, 'image/png')) {\n"
            . "    throw new RuntimeException('BiRefNet request failed');\n"
            . "}\n"
            . "file_put_contents('result.png', \$result);";
    } else {
        $php .= 'echo curl_exec($ch);';
    }
    $js = $jsPrefix
        . "const res = await fetch(" . json_encode($url, JSON_UNESCAPED_SLASHES) . ", {$jsOptions});\n";
    if ($binaryPng) {
        $js .= "if (!res.ok) throw new Error(await res.text());\n"
            . "const blob = await res.blob();\n"
            . "const objectUrl = URL.createObjectURL(blob);\n"
            . "console.log(objectUrl);";
    } else {
        $js .= 'console.log(await res.json());';
    }

    return ['curl' => $curl, 'php' => $php, 'js_fetch' => $js];
}

function hub_public_api_manifest(PDO $db, ?callable $healthProbe = null): array
{
    return [
        'name' => '3waAIHub',
        'version' => HUB_VERSION,
        'auth' => [
            'type' => 'bearer',
            'header' => 'Authorization: Bearer <TOKEN>',
        ],
        'base_endpoint' => 'api.php',
        'input_field_extensions' => [
            'one_of' => [
                'type' => 'array<string>',
                'description' => 'Names the mutually exclusive input fields in one group.',
            ],
            'one_of_required' => [
                'type' => 'boolean',
                'description' => 'When true, exactly one field named by one_of is required.',
            ],
            'example_include' => [
                'type' => 'boolean',
                'description' => 'When true, generated examples include this optional field.',
            ],
        ],
        'services' => hub_public_api_services($db, $healthProbe),
    ];
}

function hub_public_api_docs_html(PDO $db, ?array $user = null, ?callable $healthProbe = null): string
{
    $services = hub_public_api_services($db, $healthProbe);
    $packIds = array_fill_keys(array_column($services, 'pack_id'), true);
    $t = static fn (string $value): string => hub_h(hub_i18n_text($value));
    ob_start();
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $t('3waAIHub API 介接文件') ?></title>
    <style>
        :root { color-scheme: light; --bg: #f6f7f9; --panel: #fff; --line: #d9dee7; --text: #1d2430; --muted: #667085; --blue: #1769e0; }
        body { background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 0; }
        main { max-width: 1120px; margin: 28px auto; padding: 0 16px; }
        .panel, .card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        .grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        .muted { color: var(--muted); }
        .tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .tab { border: 1px solid var(--line); border-radius: 999px; color: var(--text); display: inline-block; padding: 8px 13px; text-decoration: none; }
        .tab:hover { border-color: var(--blue); color: var(--blue); }
        .section-title { align-items: baseline; display: flex; gap: 10px; justify-content: space-between; }
        .job-list { margin: 0; padding-left: 20px; }
        code, pre { background: #101828; color: #f2f4f7; border-radius: 6px; }
        code { padding: 2px 5px; }
        pre { overflow: auto; padding: 12px; white-space: pre-wrap; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border-bottom: 1px solid var(--line); padding: 8px; text-align: left; vertical-align: top; }
        th { color: var(--muted); width: 130px; }
        .button { border: 1px solid var(--line); border-radius: 6px; color: var(--text); display: inline-block; padding: 7px 11px; text-decoration: none; }
        .i18n-selector { display: inline-block; margin-top: 8px; }
        .i18n-selector select { border: 1px solid var(--line); border-radius: 6px; font: inherit; padding: 7px 10px; width: auto; }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1><?= $t('3waAIHub API 介接文件') ?></h1>
        <p class="muted"><?= $t('這份文件只提供外部介接所需資訊，不包含後台管理連結、內部部署資訊、主機檔案路徑或 token 明文。') ?></p>
        <p><?= $t('認證方式') ?>：<code>Authorization: Bearer &lt;TOKEN&gt;</code></p>
        <p>API Endpoint：<code><?= hub_h(hub_public_api_base_url()) ?>?mode=&lt;mode&gt;</code></p>
        <?php if (isset($packIds['docparser'])): ?>
            <p>DocParser <code>translation_policy=auto</code>：<?= $t('預設只翻譯非中文 block；已是繁中目標語言的 block 會直接沿用原文，避免中文文件重複翻譯。需要全部硬翻時可送') ?> <code>translation_policy=always</code>。</p>
            <p>DocParser <?= $t('局部補翻譯') ?>：<?= $t('看') ?> <code>quality_report.missing_translation_blocks</code>，<?= $t('再送') ?> <code>task_type=docparser_repair_translation</code>、<code>task_id</code>、<code>block_ids</code> <?= $t('到') ?> <code><?= hub_h(hub_public_api_base_url()) ?>?mode=task_submit</code>。<?= $t('此流程只重翻指定 block，不重跑 OCR / layout / figure extraction。') ?></p>
        <?php endif; ?>
        <nav class="tabs" aria-label="<?= $t('公開 API 文件區段') ?>">
            <a class="tab" href="#api">API modes / <?= $t('API 模式') ?></a>
            <?php if (isset($packIds['yolo'])): ?>
                <a class="tab" href="#local-jobs">Local Jobs / <?= $t('本機工作') ?></a>
            <?php endif; ?>
        </nav>
        <?= hub_i18n_language_selector() ?>
        <?php if ($user !== null): ?>
            <p><a class="button" href="admin/playground.php"><?= $t('開啟 API 測試場') ?></a></p>
        <?php endif; ?>
    </section>
    <section id="api" class="panel">
        <div class="section-title">
            <h2>API modes / <?= $t('API 模式') ?></h2>
            <span class="muted">HTTP Gateway</span>
        </div>
        <?php if ($services === []): ?>
            <p><?= $t('目前沒有健康且可用的 API 服務。') ?></p>
        <?php else: ?>
            <p><?php foreach ($services as $service): ?><code><?= hub_h((string)$service['mode']) ?></code> <?php endforeach; ?></p>
        <?php endif; ?>
    </section>
    <section class="grid">
        <?php foreach ($services as $service): ?>
            <article class="card">
                <h2><?= hub_h((string)$service['name']) ?></h2>
                <table>
                    <tr><th>mode</th><td><code><?= hub_h((string)$service['mode']) ?></code></td></tr>
                    <tr><th>pack_id</th><td><code><?= hub_h((string)$service['pack_id']) ?></code></td></tr>
                    <tr><th>method</th><td><code><?= hub_h((string)$service['method']) ?></code></td></tr>
                    <tr><th>endpoint</th><td><code><?= hub_h((string)$service['endpoint']) ?></code></td></tr>
                    <tr><th>content-type</th><td><code><?= hub_h((string)$service['content_type'] !== '' ? (string)$service['content_type'] : '-') ?></code></td></tr>
                    <tr><th>response-content-type</th><td><code><?= hub_h((string)($service['response_content_type'] ?? 'application/json')) ?></code></td></tr>
                    <tr><th>runtime_level</th><td><code><?= hub_h((string)$service['runtime_level']) ?></code></td></tr>
                    <tr><th>execution_type</th><td><code><?= hub_h((string)$service['execution_type']) ?></code></td></tr>
                    <?php if (($service['task_type'] ?? '') !== ''): ?>
                        <tr><th>task_type</th><td><code><?= hub_h((string)$service['task_type']) ?></code></td></tr>
                    <?php endif; ?>
                </table>
                <h3><?= $t('Request 欄位') ?></h3>
                <pre><?= hub_h(json_encode($service['input_fields'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <h3><?= $t('Response keys') ?></h3>
                <pre><?= hub_h(json_encode($service['output_keys'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php if (($service['result_artifact_fields'] ?? []) !== []): ?>
                    <h3>Artifact delivery</h3>
                    <pre><?= hub_h(json_encode([
                        'result.artifacts[]' => $service['result_artifact_fields'],
                        'types' => $service['result_artifact_types'] ?? [],
                        'note' => $service['artifact_delivery_note'] ?? '',
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if (($service['operations'] ?? []) !== []): ?>
                    <h3>Additional operations</h3>
                    <pre><?= hub_h(json_encode($service['operations'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if (($service['operation_examples'] ?? []) !== []): ?>
                    <h3><?= $t('Operation examples') ?></h3>
                    <?php foreach ($service['operation_examples'] as $operationExample): ?>
                        <h4><code><?= hub_h((string)$operationExample['operation']) ?></code> / <code><?= hub_h((string)$operationExample['execution_type']) ?></code></h4>
                        <pre><?= hub_h((string)$operationExample['examples']['curl']) ?></pre>
                        <?php if (($operationExample['base64_examples'] ?? []) !== []): ?>
                            <h5>Base64 source (use instead of image)</h5>
                            <pre><?= hub_h((string)$operationExample['base64_examples']['curl']) ?></pre>
                        <?php endif; ?>
                        <?php if (($operationExample['task_api'] ?? []) !== []): ?>
                            <pre><?= hub_h(json_encode($operationExample['task_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (($service['workflow'] ?? []) !== []): ?>
                    <h3><?= $t('Workflow') ?></h3>
                    <pre><?= hub_h(json_encode($service['workflow'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if (($service['response_headers'] ?? []) !== []): ?>
                    <h3><?= $t('Response headers') ?></h3>
                    <pre><?= hub_h(json_encode($service['response_headers'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if (($service['task_api'] ?? []) !== []): ?>
                    <h3><?= $t('Task 狀態 / 結果') ?></h3>
                    <pre><?= hub_h(json_encode($service['task_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <h3><?= $t('錯誤碼') ?></h3>
                <pre><?= hub_h(implode(', ', $service['error_codes'])) ?></pre>
                <?php if (($service['error_table'] ?? []) !== []): ?>
                    <h3><?= $t('Error status table') ?></h3>
                    <pre><?= hub_h(json_encode($service['error_table'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <?php if (($service['examples'] ?? []) !== []): ?>
                    <h3><?= $t('curl 範例') ?></h3>
                    <pre><?= hub_h((string)$service['examples']['curl']) ?></pre>
                    <h3><?= $t('PHP 範例') ?></h3>
                    <pre><?= hub_h((string)$service['examples']['php']) ?></pre>
                    <h3><?= $t('JS fetch 範例') ?></h3>
                    <pre><?= hub_h((string)$service['examples']['js_fetch']) ?></pre>
                <?php endif; ?>
                <?php if (($service['workflow_examples'] ?? []) !== []): ?>
                    <h3><?= $t('Workflow curl example') ?></h3>
                    <pre><?= hub_h((string)$service['workflow_examples']['curl']) ?></pre>
                    <h3><?= $t('Workflow PHP example') ?></h3>
                    <pre><?= hub_h((string)$service['workflow_examples']['php']) ?></pre>
                    <h3><?= $t('Workflow JS example') ?></h3>
                    <pre><?= hub_h((string)$service['workflow_examples']['js_fetch']) ?></pre>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
    <?php if (isset($packIds['yolo'])): ?>
        <section id="local-jobs" class="panel">
        <div class="section-title">
            <h2>Local Jobs / <?= $t('本機工作') ?></h2>
            <span class="muted">Local Job Contract v0.1</span>
        </div>
        <p class="muted">Local Job <?= $t('是本機 CLI / workspace contract，不是') ?> <code>api.php?mode=...</code>。<?= $t('適合批次推論、訓練、模型匯出、GIS 批次處理等需要檔案工作區的任務。') ?></p>
        <p><?= $t('薄呼叫入口') ?>：</p>
        <pre>bin/aihub-run yolo_predict --pack yolo --workspace &lt;WORKSPACE&gt;
bin/aihub-run yolo_train --pack yolo --workspace &lt;WORKSPACE&gt; --gpu 0
bin/aihub-run yolo_export_onnx --pack yolo --workspace &lt;WORKSPACE&gt;</pre>
        <div class="grid">
            <article class="card">
                <h3><?= $t('Workspace contract') ?></h3>
                <pre>workspace/
├─ input/
├─ output/
├─ logs/
├─ runtime/
│  ├─ run.json
│  ├─ resource.ndjson
│  └─ events.ndjson
├─ request.json
├─ status.json
├─ progress.ndjson
└─ result.json</pre>
            </article>
            <article class="card">
                <h3><?= $t('本機工作') ?></h3>
                <ul class="job-list">
                    <li><code>yolo_predict</code>：<?= $t('真實 Ultralytics 批次 predict runner') ?></li>
                    <li><code>yolo_train</code>：<?= $t('真實 Ultralytics training runner') ?></li>
                    <li><code>yolo_export_onnx</code>：<?= $t('真實 Ultralytics ONNX export runner') ?></li>
                </ul>
                <p class="muted">Local Job <?= $t('由受控本機環境執行；公開文件不提供內部 port、主機路徑、Docker 權限端點或敏感設定。') ?></p>
            </article>
            <article class="card">
                <h3><?= $t('結果規則') ?></h3>
                <table>
                    <tr><th>status.json</th><td><?= $t('目前狀態、stage、progress、message。') ?></td></tr>
                    <tr><th>progress.ndjson</th><td><?= $t('可串接 UI 的逐行進度事件。') ?></td></tr>
                    <tr><th>result.json</th><td><?= $t('最終輸出摘要、artifacts、metrics、exit_code。') ?></td></tr>
                    <tr><th>exit code</th><td><code>0</code> <?= $t('表示 success；非') ?> <code>0</code> <?= $t('表示 failed。') ?></td></tr>
                </table>
            </article>
        </div>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
