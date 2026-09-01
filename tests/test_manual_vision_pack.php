<?php
declare(strict_types=1);

if (!function_exists('hub_test')) {
    define('HUB_TESTING', true);
    require_once __DIR__ . '/../app/bootstrap.php';
    function hub_test(string $name, callable $test): void
    {
        try {
            $test();
            echo '[PASS] ' . $name . PHP_EOL;
        } catch (Throwable $e) {
            fwrite(STDERR, '[FAIL] ' . $name . ': ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
    function hub_test_assert(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }
}

hub_test('Manual Vision Pack exposes the narrow English DocVQA contract', function (): void {
    $pack = hub_get_pack('vlm-manual-vision');
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'Manual Vision Pack missing or invalid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['type'] ?? '') === 'api_service', 'Manual Vision must be an api_service');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'Manual Vision must be sync_api');
    hub_test_assert(($manifest['provider'] ?? '') === 'google-paligemma', 'Manual Vision provider mismatch');
    hub_test_assert(($manifest['capability'] ?? '') === 'document-question-answering', 'Manual Vision capability mismatch');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? false) === true && ($manifest['hardware']['gpu_supported'] ?? false) === true, 'Manual Vision must be CUDA-only');
    hub_test_assert(($manifest['hardware']['cpu_fallback'] ?? true) === false, 'Manual Vision must not offer CPU fallback');
    hub_test_assert(($manifest['queue']['max_concurrency'] ?? 0) === 1, 'Manual Vision must be single-concurrency');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'manual_vision', 'Manual Vision mode mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/vision/docvqa', 'Manual Vision invoke path mismatch');
    hub_test_assert(($manifest['gateway']['max_upload_mb'] ?? 0) === 50, 'Manual Vision upload limit mismatch');

    $contract = hub_pack_l5_contract($manifest);
    $fields = array_column($contract['input']['fields'] ?? [], 'name');
    hub_test_assert($fields === ['operation', 'image', 'question'], 'Manual Vision fields must be exact');
    $operation = $contract['input']['fields'][0] ?? [];
    hub_test_assert(($operation['enum'] ?? []) === ['docvqa'], 'Manual Vision operation enum must be docvqa only');
    hub_test_assert(($contract['output']['required_keys'] ?? []) === [
        'ok', 'mode', 'operation', 'answer', 'answer_language', 'contract_revision', 'elapsed_ms', 'request_id',
    ], 'Manual Vision public output keys must be exact');
    hub_test_assert(($contract['errors'] ?? []) === [
        'bad_request', 'unsupported_operation', 'bad_image', 'file_too_large', 'missing_token', 'token_mode_not_allowed',
        'gpu_unavailable', 'model_not_provisioned', 'model_manifest_invalid', 'runtime_not_ready', 'inference_failed', 'gateway_timeout',
    ], 'Manual Vision errors must be exact');

    $settings = hub_get_pack_settings_schema('vlm-manual-vision');
    foreach (['MANUAL_VISION_MODEL', 'MANUAL_VISION_MODEL_REVISION', 'MANUAL_VISION_DEVICE', 'MANUAL_VISION_TORCH_DTYPE', 'MANUAL_VISION_MAX_NEW_TOKENS', 'MANUAL_VISION_MAX_UPLOAD_MB', 'HF_TOKEN'] as $key) {
        hub_test_assert(isset($settings[$key]), 'Manual Vision setting missing ' . $key);
    }
    hub_test_assert(($settings['MANUAL_VISION_MAX_NEW_TOKENS']['min'] ?? null) === 1 && ($settings['MANUAL_VISION_MAX_NEW_TOKENS']['max'] ?? null) === 128, 'Manual Vision answer limit range mismatch');
    hub_test_assert(($settings['MANUAL_VISION_MAX_UPLOAD_MB']['min'] ?? null) === 1 && ($settings['MANUAL_VISION_MAX_UPLOAD_MB']['max'] ?? null) === 50, 'Manual Vision upload limit range mismatch');
    hub_test_assert(!empty($settings['HF_TOKEN']['secret']) && !empty($settings['HF_TOKEN']['provision_only']), 'Manual Vision token must be provision-only secret');
});
