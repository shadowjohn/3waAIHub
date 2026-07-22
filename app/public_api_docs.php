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

function hub_public_api_services(PDO $db): array
{
    $services = [];
    foreach (hub_list_packs() as $pack) {
        if (($pack['status'] ?? '') !== 'ok') {
            continue;
        }
        $manifest = is_array($pack['manifest'] ?? null) ? $pack['manifest'] : [];
        $mode = (string)($manifest['default_mode'] ?? '');
        if ($mode === '') {
            continue;
        }
        $contract = hub_public_api_contract_for_manifest($manifest);
        $method = hub_public_api_method($manifest, $contract);
        $contentType = hub_public_api_content_type($method, $contract);
        $fields = is_array($contract['input']['fields'] ?? null) ? $contract['input']['fields'] : [];
        $outputKeys = array_values(array_map('strval', is_array($contract['output']['required_keys'] ?? null) ? $contract['output']['required_keys'] : []));
        $errors = array_values(array_map('strval', is_array($contract['errors'] ?? null) ? $contract['errors'] : []));
        $taskApi = is_array($contract['task_api'] ?? null) ? $contract['task_api'] : [];
        $service = [
            'mode' => $mode,
            'pack_id' => (string)($manifest['id'] ?? $pack['id'] ?? ''),
            'name' => (string)($manifest['name'] ?? $pack['id'] ?? ''),
            'description' => (string)($manifest['description'] ?? ''),
            'method' => $method,
            'content_type' => $contentType,
            'endpoint' => 'api.php?mode=' . $mode,
            'url' => hub_public_api_mode_url($mode),
            'execution_type' => (string)($manifest['execution_type'] ?? ''),
            'runtime_level' => (string)($manifest['runtime_level'] ?? ''),
            'task_type' => (string)($contract['task_type'] ?? ''),
            'input_fields' => $fields,
            'output_keys' => $outputKeys,
            'error_codes' => $errors,
            'task_api' => hub_public_api_task_api_refs($taskApi),
        ];
        $service['examples'] = hub_public_api_examples($service);
        $services[] = $service;
    }
    if (hub_get_pack('llm-gemma4-12b') !== null) {
        foreach (hub_public_api_gemma4_services() as $service) {
            $service['examples'] = hub_public_api_examples($service);
            $services[] = $service;
        }
    }
    if (hub_get_pack('yolo-serving') !== null) {
        foreach (hub_public_api_yolo_model_services() as $service) {
            $service['examples'] = hub_public_api_examples($service);
            $services[] = $service;
        }
    }

    usort($services, static fn (array $a, array $b): int => strcmp((string)$a['mode'], (string)$b['mode']));
    return $services;
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
            'description' => 'Ask about a short WAV directly, or reuse a previously uploaded audio_id. This does not create sessions and does not replace Whisper ASR.',
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
            'output_keys' => ['ok', 'mock', 'runtime_level', 'model', 'operation', 'answer', 'transcript', 'summary', 'tags', 'audio', 'usage', 'elapsed_ms'],
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
        if ($name === '' || $name === 'mode' || ($field['type'] ?? '') === 'file') {
            continue;
        }
        $body[$name] = match ($name) {
            'text' => 'That was a wonderful time.',
            'image_id' => 'img_...',
            'source_lang' => 'en',
            'target_lang' => 'zh-TW',
            'real_inference' => true,
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
        if ($name === '' || $name === 'mode') {
            continue;
        }
        $type = (string)($field['type'] ?? '');
        if ($type === 'file') {
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
        $fields[] = $name . '=' . (string)($field['default'] ?? ($name === 'real_inference' ? '1' : ''));
    }

    return $fields;
}

function hub_public_api_examples(array $service): array
{
    $url = (string)$service['url'];
    $method = (string)$service['method'];
    $contentType = (string)$service['content_type'];
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
        foreach ($service['input_fields'] as $field) {
            if (!is_array($field)) {
                continue;
            }
            $name = (string)($field['name'] ?? '');
            if ($name === '' || $name === 'mode') {
                continue;
            }
            if (($field['type'] ?? '') === 'file') {
                $sample = (string)($field['example'] ?? '');
                if ($sample === '') {
                    $sample = $name === 'audio' ? 'sample.wav' : ($name === 'file' ? 'sample.pdf' : 'sample.png');
                }
                $phpLines[] = '    ' . var_export($name, true) . ' => new CURLFile(' . var_export('/path/to/' . $sample, true) . '),';
                $jsLines[] = "const fileInput = document.querySelector('input[name=\"" . addcslashes($name, "\\'") . "\"]');";
                $jsLines[] = 'formData.append(' . var_export($name, true) . ', fileInput.files[0]);';
                continue;
            }
            $value = $name === 'points_json' ? '{"points":[[320,240]],"labels":[1]}' : (string)($field['default'] ?? ($name === 'real_inference' ? '1' : ''));
            $phpLines[] = '    ' . var_export($name, true) . ' => ' . var_export($value, true) . ',';
            $jsLines[] = 'formData.append(' . var_export($name, true) . ', ' . json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');';
        }
        $phpLines[] = "];";
        $phpBody = implode("\n", $phpLines) . "\n";
        $jsOptions = "{\n  method: '{$method}',\n  headers: { Authorization: 'Bearer <TOKEN>' },\n  body: formData\n}";
        $jsPrefix = implode("\n", $jsLines) . "\n";
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
        . "]);\n"
        . 'echo curl_exec($ch);';
    $js = $jsPrefix
        . "const res = await fetch(" . json_encode($url, JSON_UNESCAPED_SLASHES) . ", {$jsOptions});\n"
        . 'console.log(await res.json());';

    return ['curl' => $curl, 'php' => $php, 'js_fetch' => $js];
}

function hub_public_api_manifest(PDO $db): array
{
    return [
        'name' => '3waAIHub',
        'version' => HUB_VERSION,
        'auth' => [
            'type' => 'bearer',
            'header' => 'Authorization: Bearer <TOKEN>',
        ],
        'base_endpoint' => 'api.php',
        'services' => hub_public_api_services($db),
    ];
}

function hub_public_api_docs_html(PDO $db, ?array $user = null): string
{
    $services = hub_public_api_services($db);
    $t = static fn (string $value): string => hub_h(__($value));
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
        <p>DocParser <?= $t('局部補翻譯') ?>：<?= $t('看') ?> <code>quality_report.missing_translation_blocks</code>，<?= $t('再送') ?> <code>task_type=docparser_repair_translation</code>、<code>task_id</code>、<code>block_ids</code> <?= $t('到') ?> <code><?= hub_h(hub_public_api_base_url()) ?>?mode=task_submit</code>。<?= $t('此流程只重翻指定 block，不重跑 OCR / layout / figure extraction。') ?></p>
        <nav class="tabs" aria-label="<?= $t('公開 API 文件區段') ?>">
            <a class="tab" href="#api">API modes / <?= $t('API 模式') ?></a>
            <a class="tab" href="#local-jobs">Local Jobs / <?= $t('本機工作') ?></a>
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
        <p><?php foreach ($services as $service): ?><code><?= hub_h((string)$service['mode']) ?></code> <?php endforeach; ?></p>
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
                <?php if (($service['task_api'] ?? []) !== []): ?>
                    <h3><?= $t('Task 狀態 / 結果') ?></h3>
                    <pre><?= hub_h(json_encode($service['task_api'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                <?php endif; ?>
                <h3><?= $t('錯誤碼') ?></h3>
                <pre><?= hub_h(implode(', ', $service['error_codes'])) ?></pre>
                <h3><?= $t('curl 範例') ?></h3>
                <pre><?= hub_h((string)$service['examples']['curl']) ?></pre>
                <h3><?= $t('PHP 範例') ?></h3>
                <pre><?= hub_h((string)$service['examples']['php']) ?></pre>
                <h3><?= $t('JS fetch 範例') ?></h3>
                <pre><?= hub_h((string)$service['examples']['js_fetch']) ?></pre>
            </article>
        <?php endforeach; ?>
    </section>
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
</main>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
