<?php
declare(strict_types=1);

function hub_test_audio_cleanup_wav(): string
{
    return 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
}

function hub_test_audio_cleanup_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_audio_cleanup_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/output', 0700, true)) {
        throw new RuntimeException('Cannot create audio cleanup workspace.');
    }

    return $workspace;
}

function hub_test_audio_cleanup_remove(string $path): void
{
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            hub_test_audio_cleanup_remove($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_audio_cleanup_write(string $workspace, string $operation): void
{
    $audio = hub_test_audio_cleanup_wav();
    if (in_array($operation, ['separate', 'separate_and_enhance'], true)) {
        file_put_contents($workspace . '/output/vocals.wav', $audio, LOCK_EX);
        file_put_contents($workspace . '/output/background.wav', $audio, LOCK_EX);
    }
    if (in_array($operation, ['enhance', 'separate_and_enhance'], true)) {
        file_put_contents($workspace . '/output/cleaned.wav', $audio, LOCK_EX);
    }
    file_put_contents($workspace . '/output/cleanup_report.json', json_encode([
        'operation' => $operation,
        'actual_chain' => ['demucs'],
        'model_versions' => ['demucs' => 'htdemucs@v4.0.1'],
    ], JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

hub_test('audio-cleanup Pack declares a GPU-only generic runner and fixed cleanup contract', function (): void {
    $pack = hub_get_pack('audio-cleanup');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'audio-cleanup Pack must validate');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'cleanup');
    hub_test_assert(is_array($job), 'audio-cleanup cleanup job contract missing');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'audio_cleanup', 'audio-cleanup must own the fixed audio_cleanup mode');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? null) === true && ($manifest['hardware']['gpu_supported'] ?? null) === true && ($manifest['hardware']['cpu_fallback'] ?? null) === false, 'audio cleanup must never declare a CPU fallback');
    hub_test_assert(($job['source_artifact_types'] ?? []) === ['audio'], 'audio cleanup must accept one audio source type');
    hub_test_assert(($job['runner']['accelerator'] ?? '') === 'gpu' && (int)($job['runner']['required_vram_mb'] ?? 0) > 0, 'audio cleanup runner must be GPU-only');
    hub_test_assert(!str_contains(strtolower(json_encode($job['runner'])), 'myai'), 'audio cleanup must use only the generic Pack runner');
    hub_test_assert(($job['capabilities']['deepfilternet'] ?? null) === false, 'DeepFilterNet must default to unavailable until a GPU runner provides it');
    $aliases = $manifest['model_allowlist']['demucs']['aliases'] ?? [];
    hub_test_assert(array_keys($aliases) === ['balanced', 'quality'], 'Demucs aliases must be a fixed allowlist');
    foreach ($aliases as $config) {
        hub_test_assert(($config['device'] ?? '') === 'cuda' && !str_contains(json_encode($config), 'cpu'), 'Demucs config must not hide a CPU path');
    }
});

hub_test('audio-cleanup request schema rejects invalid operations and unavailable enhancement', function (): void {
    $pack = hub_get_pack('audio-cleanup');
    $route = hub_pack_async_job_contract($pack['manifest'], 'cleanup');
    hub_test_assert(hub_test_throws(static fn (): array => hub_audio_task_input([], $route)), 'operation must be required');
    hub_test_assert(hub_test_throws(static fn (): array => hub_audio_task_input(['operation' => 'anything'], $route)), 'unknown operation must be rejected');
    hub_test_assert(hub_test_throws(static fn (): array => hub_audio_task_input(['operation' => 'separate', 'demucs_model' => 'host-path'], $route)), 'Demucs model must be an allowlisted alias');
    hub_test_assert(hub_test_throws(static fn (): array => hub_audio_task_input(['operation' => 'enhance'], $route)), 'DeepFilterNet-disabled enhancement must be rejected');
    $enabled = $route;
    $enabled['capabilities']['deepfilternet'] = true;
    hub_test_assert(hub_audio_task_input(['operation' => 'enhance'], $enabled) === ['operation' => 'enhance', 'demucs_model' => 'balanced'], 'enabled enhancement must retain the normalized request');
});

hub_test('audio-cleanup conditional outputs require only the artifacts for the requested operation', function (): void {
    $pack = hub_get_pack('audio-cleanup');
    $contract = hub_pack_async_job_contract($pack['manifest'], 'cleanup')['artifact_contract'];
    $probe = static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1];
    foreach ([
        'separate' => ['vocals_audio', 'background_audio', 'cleanup_report'],
        'enhance' => ['cleaned_audio', 'cleanup_report'],
        'separate_and_enhance' => ['vocals_audio', 'background_audio', 'cleaned_audio', 'cleanup_report'],
    ] as $operation => $expectedTypes) {
        $workspace = hub_test_audio_cleanup_workspace();
        try {
            hub_test_audio_cleanup_write($workspace, $operation);
            $artifacts = hub_validate_pack_job_artifacts($workspace, ['operation' => $operation], $contract, $probe);
            hub_test_assert(array_column($artifacts, 'artifact_type') === $expectedTypes, $operation . ' output set mismatch');
        } finally {
            hub_test_audio_cleanup_remove($workspace);
        }
    }
});

hub_test('audio-cleanup admits one source and runs through the injected generic GPU executor', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $resultTaskIds = [];
        try {
        hub_install_pack($db, 'audio-cleanup', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Audio Cleanup Owner');
        $token = hub_create_api_token($db, $memberId, 'audio cleanup token', null, null);
        hub_test_audio_allow($db, [$token], ['audio_cleanup']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $source = hub_test_audio_source_artifact($db, $memberId, (int)$token['token_id']);
        $resultTaskIds[] = (int)$source['task_id'];
        hub_finish_task_success($db, hub_get_task($db, $source['task_id']) ?? [], ['source' => true]);

        $created = hub_test_audio_request($db, 'audio_cleanup', (string)$token['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'operation' => 'separate',
        ]);
        $payload = hub_test_audio_payload($created);
        $taskId = (int)($payload['task_id'] ?? 0);
        $resultTaskIds[] = $taskId;
        $task = hub_get_task($db, $taskId) ?? [];
        hub_test_assert($created['status'] === 200 && ($task['input'] ?? []) === ['operation' => 'separate', 'demucs_model' => 'balanced'] && ($task['queue_name'] ?? '') === 'gpu' && ($task['accelerator'] ?? '') === 'gpu', 'audio cleanup admission must pin its normalized GPU request');

        $enhance = hub_test_audio_request($db, 'audio_cleanup', (string)$token['plain_token'], [
            'source_artifact_id' => (string)$source['artifact_id'],
            'operation' => 'enhance',
        ]);
        hub_test_assert($enhance['status'] === 409 && (hub_test_audio_payload($enhance)['error'] ?? '') === 'capability_unavailable', 'DeepFilterNet-disabled Pack must reject enhancement instead of fabricating cleaned audio');

        $claimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $outcome = hub_run_pack_job_task($db, $claimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'executor' => static function (array $context): array {
                hub_test_assert(($context['runner']['accelerator'] ?? '') === 'gpu' && !str_contains(strtolower(json_encode($context['runner'])), 'myai'), 'audio cleanup must receive the manifest generic runner only');
                hub_test_assert(json_decode((string)file_get_contents($context['workspace'] . '/input/request.json'), true) === ['operation' => 'separate', 'demucs_model' => 'balanced'], 'generic runner must receive the normalized request only');
                $context['started'](['container_id' => 'audio-cleanup-fixture']);
                hub_test_audio_cleanup_write($context['workspace'], 'separate');
                return [
                    'exit_code' => 0,
                    'container_id' => 'audio-cleanup-fixture',
                    'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                ];
            },
        ]);
        $artifacts = $db->query('SELECT artifact_type FROM task_artifacts WHERE task_id = ' . $taskId . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        hub_test_assert(($outcome['status'] ?? '') === 'success' && $artifacts === ['vocals_audio', 'background_audio', 'cleanup_report'] && !in_array('cleaned_audio', $artifacts, true), 'separation must publish valid requested outputs and never fake cleaned audio');
        } finally {
            foreach ($resultTaskIds as $resultTaskId) {
                hub_test_audio_cleanup_remove(hub_task_result_dir($resultTaskId));
            }
        }
    });
});
