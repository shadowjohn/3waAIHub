<?php
declare(strict_types=1);

function hub_test_audio_cleanup_wav(): string
{
    return 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . 'data' . pack('V', 2) . "\0\0";
}

function hub_test_audio_cleanup_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_audio_cleanup_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/input', 0700, true) || !mkdir($workspace . '/output', 0700, true)) {
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
    if (is_dir($workspace . '/input') && !is_file($workspace . '/input/source')) {
        file_put_contents($workspace . '/input/source', $audio, LOCK_EX);
    }
    if (in_array($operation, ['separate', 'separate_and_enhance'], true)) {
        file_put_contents($workspace . '/output/vocals.wav', $audio, LOCK_EX);
        file_put_contents($workspace . '/output/background.wav', $audio, LOCK_EX);
    }
    if (in_array($operation, ['enhance', 'separate_and_enhance'], true)) {
        file_put_contents($workspace . '/output/cleaned.wav', $audio, LOCK_EX);
    }
    file_put_contents($workspace . '/output/cleanup_report.json', json_encode(hub_test_audio_cleanup_report($operation, $workspace . '/input/source'), JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
}

function hub_test_audio_cleanup_install(PDO $db, array $options = []): array
{
    $built = false;
    $options += [
        'idempotent' => true,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$built): array {
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                return $built
                    ? ['exit_code' => 0, 'stdout' => 'sha256:test-audio-cleanup', 'stderr' => '']
                    : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such image'];
            }
            if (($command[1] ?? '') === 'build') {
                $built = true;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected runner image lifecycle command');
        },
    ];

    return hub_install_pack($db, 'audio-cleanup', $options);
}

function hub_test_audio_cleanup_report(string $operation, ?string $sourcePath = null): array
{
    $stages = match ($operation) {
        'separate' => ['demucs'],
        'enhance' => ['deepfilternet'],
        'separate_and_enhance' => ['demucs', 'deepfilternet'],
        default => [],
    };
    $versions = [];
    foreach ($stages as $stage) {
        $versions[$stage] = $stage === 'demucs' ? 'htdemucs@v4.0.1' : 'DeepFilterNet@3.0.0';
    }
    $properties = ['sample_rate' => 48000, 'channels' => 1, 'duration_seconds' => 0.01];
    $outputs = [];
    if (in_array($operation, ['separate', 'separate_and_enhance'], true)) {
        $outputs['vocals_audio'] = $properties;
        $outputs['background_audio'] = $properties;
    }
    if (in_array($operation, ['enhance', 'separate_and_enhance'], true)) {
        $outputs['cleaned_audio'] = $properties;
    }

    return [
        'operation' => $operation,
        'actual_chain' => $stages,
        'model_versions' => $versions,
        'source_audio' => $properties,
        'source_sha256' => $sourcePath === null ? hash('sha256', hub_test_audio_cleanup_wav()) : (hash_file('sha256', $sourcePath) ?: ''),
        'outputs' => $outputs,
        'elapsed_seconds' => 0.01,
        'warnings' => [],
    ];
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
    hub_test_assert(($job['runner']['image'] ?? '') === '3waaihub/audio-cleanup:0.1.0' && ($job['runner']['executor'] ?? '') === 'container', 'audio cleanup runner must point to the locally buildable container image');
    foreach (['docker-compose.yml', 'service/Dockerfile', 'service/requirements.txt', 'service/job.py'] as $asset) {
        hub_test_assert(is_file(HUB_ROOT . '/packs/audio-cleanup/' . $asset), 'audio-cleanup Pack asset missing ' . $asset);
    }
    $runner = (string)file_get_contents(HUB_ROOT . '/packs/audio-cleanup/service/job.py');
    foreach (['--runner-config', 'demucs', 'deepFilter', 'file_sha256', 'source_sha256', 'cleanup_report.json'] as $needle) {
        hub_test_assert(str_contains($runner, $needle), 'audio-cleanup runner missing ' . $needle);
    }
});

hub_test('audio-cleanup image provisions only offline Demucs weights', function (): void {
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/audio-cleanup/service/Dockerfile');
    foreach ([
        'AIHUB_DEMUCS_MODEL_DIR=/opt/aihub/models/demucs',
        'htdemucs.yaml',
        'htdemucs_ft.yaml',
        'curl --fail --location',
    ] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'audio-cleanup image must provision the allowlisted local weight ' . $needle);
    }
    $runner = (string)file_get_contents(HUB_ROOT . '/packs/audio-cleanup/service/job.py');
    foreach (['TORCH_HOME', 'require_demucs_model', '--repo', 'model_assets_missing:demucs', 'require_deepfilter_model', 'model_assets_missing:deepfilternet', '--model-base-dir'] as $needle) {
        hub_test_assert(str_contains($runner, $needle), 'audio-cleanup runner must stay offline for ' . $needle);
    }
});

hub_test('audio-cleanup image verifies every allowlisted weight with a full SHA-256 lock', function (): void {
    $service = HUB_ROOT . '/packs/audio-cleanup/service';
    $lock = (string)file_get_contents($service . '/model-lock.sha256');
    $pins = preg_split('/\R/', trim($lock)) ?: [];
    hub_test_assert(count($pins) === 5, 'audio-cleanup must lock exactly its five allowlisted Demucs weights');
    foreach ($pins as $pin) {
        hub_test_assert(preg_match('/^[a-f0-9]{64}  [a-f0-9]{8}-[a-f0-9]{8}\.th$/', $pin) === 1, 'audio-cleanup model lock must contain a full SHA-256 and expected release filename');
    }
    $dockerfile = (string)file_get_contents($service . '/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'COPY model-lock.sha256 /tmp/model-lock.sha256') && str_contains($dockerfile, 'sha256sum -c /tmp/model-lock.sha256'), 'audio-cleanup image must verify the checked-in full digest lock');
    hub_test_assert(!str_contains($dockerfile, 'substr($1, 1, 8)'), 'audio-cleanup image must not accept truncated model checksums');
});

hub_test('audio-cleanup install builds and verifies its declared local runner image', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    $built = false;
    $installed = hub_install_pack($db, 'audio-cleanup', [
        'idempotent' => true,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$commands, &$built): array {
            $commands[] = $command;
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                return $built
                    ? ['exit_code' => 0, 'stdout' => 'sha256:audio-cleanup', 'stderr' => '']
                    : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such image'];
            }
            if (($command[1] ?? '') === 'build') {
                $built = true;
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected local image command');
        },
    ]);
    $serviceDir = HUB_ROOT . '/packs/audio-cleanup/service';
    hub_test_assert($commands === [
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/audio-cleanup:0.1.0'],
        ['docker', 'build', '--tag', '3waaihub/audio-cleanup:0.1.0', '--file', $serviceDir . '/Dockerfile', $serviceDir],
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/audio-cleanup:0.1.0'],
    ] && (($installed['service']['install_status'] ?? '') === 'installed'), 'internal runner install must build only its declared context/tag and verify the local image before marking installed');
});

hub_test('audio-cleanup install does not mark its service installed when the controlled build fails', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    try {
        hub_install_pack($db, 'audio-cleanup', [
            'idempotent' => true,
            'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$commands): array {
                $commands[] = $command;
                if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such image'];
                }
                if (($command[1] ?? '') === 'build') {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'controlled build failed'];
                }
                throw new RuntimeException('unexpected local image command');
            },
        ]);
        throw new RuntimeException('controlled build failure unexpectedly installed the Pack');
    } catch (RuntimeException $error) {
        hub_test_assert($error->getMessage() === 'internal_runner_image_unavailable', 'internal runner install must fail from its controlled image build: ' . $error->getMessage());
    }
    hub_test_assert($commands === [
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/audio-cleanup:0.1.0'],
        ['docker', 'build', '--tag', '3waaihub/audio-cleanup:0.1.0', '--file', HUB_ROOT . '/packs/audio-cleanup/service/Dockerfile', HUB_ROOT . '/packs/audio-cleanup/service'],
    ], 'failed controlled image builds must stop before an image verification can succeed');
    hub_test_assert((int)$db->query("SELECT COUNT(*) FROM services WHERE pack_id = 'audio-cleanup'")->fetchColumn() === 0, 'failed controlled image builds must not write an installed service state');
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

    $invalidAliasSchema = $pack['manifest'];
    $invalidAliasSchema['async_jobs'][0]['input']['request_schema']['demucs_model']['enum'] = ['host-path'];
    hub_test_assert(hub_pack_async_job_contract($invalidAliasSchema, 'cleanup') === null, 'Demucs request aliases must match the fixed model allowlist');
});

hub_test('audio-cleanup conditional outputs require only the artifacts for the requested operation', function (): void {
    $pack = hub_get_pack('audio-cleanup');
    $route = hub_pack_async_job_contract($pack['manifest'], 'cleanup');
    $contract = $route['artifact_contract'];
    $probe = static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1];
    foreach ([
        'separate' => ['vocals_audio', 'background_audio', 'cleanup_report'],
        'enhance' => ['cleaned_audio', 'cleanup_report'],
        'separate_and_enhance' => ['vocals_audio', 'background_audio', 'cleaned_audio', 'cleanup_report'],
    ] as $operation => $expectedTypes) {
        $workspace = hub_test_audio_cleanup_workspace();
        try {
            hub_test_audio_cleanup_write($workspace, $operation);
            $artifacts = hub_validate_pack_job_artifacts($workspace, ['operation' => $operation, 'demucs_model' => 'balanced'], $contract, $probe, $route['runner_config']);
            hub_test_assert(array_column($artifacts, 'artifact_type') === $expectedTypes, $operation . ' output set mismatch');
        } finally {
            hub_test_audio_cleanup_remove($workspace);
        }
    }
});

hub_test('audio-cleanup report attests Hub-probed source outputs and frozen model config', function (): void {
    $pack = hub_get_pack('audio-cleanup');
    $route = hub_pack_async_job_contract($pack['manifest'], 'cleanup');
    $contract = $route['artifact_contract'];
    $probe = static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1];
    foreach ([
        static fn (array $report): array => $report,
        static function (array $report): array {
            $report['source_audio']['sample_rate'] = 16000;
            return $report;
        },
        static function (array $report): array {
            $report['outputs']['vocals_audio']['channels'] = 2;
            return $report;
        },
        static function (array $report): array {
            $report['model_versions']['demucs'] = 'tampered@v0';
            return $report;
        },
        static function (array $report): array {
            unset($report['source_sha256']);
            return $report;
        },
        static function (array $report): array {
            $report['source_sha256'] = str_repeat('0', 64);
            return $report;
        },
    ] as $index => $mutate) {
        $workspace = hub_test_audio_cleanup_workspace();
        try {
            hub_test_audio_cleanup_write($workspace, 'separate');
            $report = $mutate(hub_test_audio_cleanup_report('separate'));
            file_put_contents($workspace . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
            $valid = !hub_test_throws(static fn (): array => hub_validate_pack_job_artifacts($workspace, ['operation' => 'separate', 'demucs_model' => 'balanced'], $contract, $probe, $route['runner_config']));
            hub_test_assert($valid === ($index === 0), 'cleanup report must bind to Hub-probed source/output metadata and frozen model config');
        } finally {
            hub_test_audio_cleanup_remove($workspace);
        }
    }
});

hub_test('audio-cleanup captures source metadata and hash before runner execution', function (): void {
    $workspace = hub_test_audio_cleanup_workspace();
    try {
        file_put_contents($workspace . '/input/source', hub_test_audio_cleanup_wav(), LOCK_EX);
        $attestation = hub_pack_job_capture_staged_source_audio_attestation($workspace, static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1]);
        hub_test_assert(($attestation['metadata'] ?? null) === ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1]
            && preg_match('/^[a-f0-9]{64}$/', (string)($attestation['sha256'] ?? '')) === 1, 'Hub must retain prelaunch ffprobe metadata and a full source hash');
    } finally {
        hub_test_audio_cleanup_remove($workspace);
    }
});

hub_test('audio-cleanup report cannot attest a source mutated after prelaunch', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $source = hub_test_audio_source_artifact($db, 1, 1);
    file_put_contents($source['path'], hub_test_audio_cleanup_wav(), LOCK_EX);
    hub_finish_task_success($db, hub_get_task($db, (int)$source['task_id']) ?? [], ['source' => true]);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1', [
        'source_artifact_id' => $source['artifact_id'],
        'source_task_id' => $source['task_id'],
    ]);
    $task = hub_test_adapter_claim($db);
    try {
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'audio_probe' => static function (string $path): array {
                return file_get_contents($path) === 'mutated-source'
                    ? ['duration_seconds' => 0.01, 'sample_rate' => 16000, 'channels' => 1]
                    : ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1];
            },
            'executor' => static function (array $context): array {
                $context['started'](['container_id' => 'mutated-source']);
                hub_test_audio_cleanup_write($context['workspace'], 'separate');
                $report = hub_test_audio_cleanup_report('separate');
                $report['source_audio']['sample_rate'] = 16000;
                file_put_contents($context['workspace'] . '/input/source', 'mutated-source', LOCK_EX);
                file_put_contents($context['workspace'] . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

                return ['exit_code' => 0, 'container_id' => 'mutated-source', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        $latest = hub_get_task($db, $taskId) ?? [];
        hub_test_assert(($latest['error_code'] ?? '') === 'output_contract_invalid', 'a report must not use a source mutation to replace Hub prelaunch evidence: ' . json_encode($latest));
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        hub_test_audio_cleanup_remove(hub_task_result_dir((int)$source['task_id']));
    }
});

hub_test('audio-cleanup report source SHA rejects a swap and restore race', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $source = hub_test_audio_source_artifact($db, 1, 1);
    $original = hub_test_audio_cleanup_wav();
    file_put_contents($source['path'], $original, LOCK_EX);
    hub_finish_task_success($db, hub_get_task($db, (int)$source['task_id']) ?? [], ['source' => true]);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1', [
        'source_artifact_id' => $source['artifact_id'],
        'source_task_id' => $source['task_id'],
    ]);
    $task = hub_test_adapter_claim($db);
    try {
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'audio_probe' => static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1],
            'executor' => static function (array $context) use ($original): array {
                $context['started'](['container_id' => 'source-swap-race']);
                hub_test_audio_cleanup_write($context['workspace'], 'separate');
                $alternate = $original . 'same-probe-different-source';
                file_put_contents($context['workspace'] . '/input/source', $alternate, LOCK_EX);
                $report = hub_test_audio_cleanup_report('separate', $context['workspace'] . '/input/source');
                file_put_contents($context['workspace'] . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
                file_put_contents($context['workspace'] . '/input/source', $original, LOCK_EX);

                return ['exit_code' => 0, 'container_id' => 'source-swap-race', 'cleanup' => hub_test_adapter_cleanup()];
            },
        ]);
        hub_test_assert((hub_get_task($db, $taskId)['error_code'] ?? '') === 'output_contract_invalid', 'cleanup report source SHA must bind to the trusted prelaunch source even when the source is restored before validation');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        hub_test_audio_cleanup_remove(hub_task_result_dir((int)$source['task_id']));
    }
});

hub_test('audio-cleanup default command mounts only output writable and input snapshots read-only', function (): void {
    $workspace = hub_test_audio_cleanup_workspace();
    try {
        foreach (['source' => hub_test_audio_cleanup_wav(), 'request.json' => '{"operation":"separate"}\n', 'runner_config.json' => '{"alias":"balanced"}\n'] as $file => $contents) {
            file_put_contents($workspace . '/input/' . $file, $contents, LOCK_EX);
        }
        $route = hub_pack_async_job_contract(hub_get_pack('audio-cleanup')['manifest'], 'cleanup');
        $command = hub_pack_job_default_runner_command([
            'workspace' => $workspace,
            'run' => ['run_id' => 'audio-mount-test'],
            'runner' => $route['runner'],
        ])['command'];
        $mounts = [];
        foreach ($command as $index => $argument) {
            if ($argument === '--mount') {
                $mount = (string)($command[$index + 1] ?? '');
                preg_match('/(?:^|,)src=([^,]+),dst=([^,]+)(?:,(readonly))?/', $mount, $matches);
                if ($matches !== []) {
                    $mounts[$matches[2]] = [$matches[1], $matches[3] ?? ''];
                }
            }
        }
        hub_test_assert(($mounts['/workspace/output'] ?? null) === [$workspace . '/output', '']
            && ($mounts['/workspace/input/source'] ?? null) === [$workspace . '/input/source', 'readonly']
            && ($mounts['/workspace/input/request.json'] ?? null) === [$workspace . '/input/request.json', 'readonly']
            && ($mounts['/workspace/input/runner_config.json'] ?? null) === [$workspace . '/input/runner_config.json', 'readonly']
            && !in_array($workspace, array_column($mounts, 0), true), 'container argv must expose writable output and immutable input snapshots only');
    } finally {
        hub_test_audio_cleanup_remove($workspace);
    }
});

hub_test('audio-cleanup admits one source and runs through the injected generic GPU executor', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $resultTaskIds = [];
        try {
        hub_test_audio_cleanup_install($db);
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
            'audio_probe' => static fn (): array => ['duration_seconds' => 0.01, 'sample_rate' => 48000, 'channels' => 1],
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

hub_test('audio-cleanup default executor dispatches its snapshotted container runner', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    $runs = 0;
    $probes = [];

    try {
        $result = hub_run_pack_job_task($db, $task, [
            'gpu_probe_runner' => static function (array $command, int $timeoutSeconds) use ($db, &$probes): array {
                hub_test_assert(!$db->inTransaction(), 'production GPU probe must run outside a database transaction');
                $probes[] = $command;
                return str_contains(implode(' ', $command), 'memory.free')
                    ? ['exit_code' => 0, 'stdout' => "16384\n", 'stderr' => '']
                    : ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
            'command_runner' => static function (array $command, int $timeoutSeconds) use (&$runs): array {
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: No such container'];
                }
                $runs++;
                hub_test_assert($command[0] === 'docker' && $command[1] === 'run' && in_array('--gpus', $command, true), 'default executor must use the controlled GPU container runner');
                hub_test_assert(in_array('3waaihub/audio-cleanup:0.1.0', $command, true), 'default executor must use the snapshotted audio-cleanup image');
                hub_test_assert(in_array('/app/audio-cleanup', $command, true), 'default executor must use the snapshotted audio-cleanup entrypoint');
                hub_test_assert(!str_contains(json_encode($command), 'never-from-client'), 'default executor must not execute client controls');
                $outputMount = null;
                foreach ($command as $index => $argument) {
                    if ($argument === '--mount' && str_contains((string)($command[$index + 1] ?? ''), 'dst=/workspace/output')) {
                        $outputMount = (string)$command[$index + 1];
                        break;
                    }
                }
                preg_match('/(?:^|,)src=([^,]+)/', (string)$outputMount, $matches);
                hub_test_assert(!empty($matches[1]), 'default executor must retain writable managed output');
                hub_test_audio_cleanup_write(dirname((string)$matches[1]), 'separate');

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);

        hub_test_assert($probes === [
            ['nvidia-smi', '--query-gpu=memory.free', '--format=csv,noheader,nounits'],
            ['nvidia-smi', '--query-compute-apps=pid', '--format=csv,noheader,nounits'],
        ] && $runs === 1 && ($result['status'] ?? '') === 'success', 'task-worker default executor must use the real GPU probe and not wait solely because no probe hook was supplied');
        hub_test_assert((hub_get_task($db, $taskId)['error_code'] ?? null) === null, 'default executor must not report runner_unavailable');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup async container process heartbeats before it completes', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    $polls = 0;
    $inspects = 0;
    try {
        $result = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'command_runner' => static function (array $command, int $timeoutSeconds) use (&$inspects): array {
                hub_test_assert(($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect', 'command runner must only clean an asynchronously started container');
                $inspects++;
                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: No such container'];
            },
            'process_runner' => static function (array $command, int $timeoutSeconds, callable $poll) use ($db, &$polls): array {
                hub_test_assert(!$db->inTransaction(), 'process polling must not hold a database transaction');
                hub_test_assert(($command[0] ?? '') === 'docker' && ($command[1] ?? '') === 'run', 'process runner must start the controlled Docker argv');
                hub_test_assert($poll() === null && $poll() === null, 'a long container must heartbeat while it is running');
                $polls += 2;
                $mount = $command[array_search('--mount', $command, true) + 1] ?? '';
                preg_match('/(?:^|,)src=([^,]+)/', (string)$mount, $matches);
                hub_test_audio_cleanup_write(dirname((string)($matches[1] ?? 'missing')), 'separate');

                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);
        hub_test_assert(($result['status'] ?? '') === 'success' && $polls === 2 && $inspects === 2, 'async container completion must preserve heartbeats and verified cleanup');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup async container fence loss stops and cleans without success', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    $inspects = 0;
    $stops = 0;
    try {
        $outcome = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'command_runner' => static function (array $command, int $timeoutSeconds) use (&$inspects, &$stops): array {
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    $inspects++;
                    return $inspects === 1
                        ? ['exit_code' => 0, 'stdout' => '{"Running":true,"Pid":4242}', 'stderr' => '']
                        : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: No such container'];
                }
                if (($command[1] ?? '') === 'stop' || (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'rm')) {
                    $stops++;
                    return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                }
                throw new RuntimeException('synchronous docker run must not start when process runner is supplied');
            },
            'process_runner' => static function (array $command, int $timeoutSeconds, callable $poll) use ($db, $taskId): array {
                $runId = (int)$db->query('SELECT id FROM runtime_runs WHERE task_id = ' . $taskId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
                $db->prepare('UPDATE runtime_runs SET lease_token = :token WHERE id = :id')->execute([':token' => 'lost-during-process', ':id' => $runId]);
                hub_test_assert($poll() === 'fence_lost', 'async process poll must surface a lost runtime fence');

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'intent' => 'fence_lost'];
            },
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'fence_lost' && (hub_get_task($db, $taskId)['status'] ?? '') !== 'success' && $inspects === 2 && $stops === 2, 'lost fence must stop/remove the named container before success is impossible');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup async container cancellation stops before natural completion', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    $inspects = 0;
    $stops = 0;
    try {
        $outcome = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'command_runner' => static function (array $command, int $timeoutSeconds) use (&$inspects, &$stops): array {
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    $inspects++;
                    return $inspects === 1
                        ? ['exit_code' => 0, 'stdout' => '{"Running":true,"Pid":4242}', 'stderr' => '']
                        : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: No such container'];
                }
                if (($command[1] ?? '') === 'stop' || (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'rm')) {
                    $stops++;
                    return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                }
                throw new RuntimeException('natural completion must not run after cancellation');
            },
            'process_runner' => static function (array $command, int $timeoutSeconds, callable $poll) use ($db, $taskId): array {
                $runId = (int)$db->query('SELECT id FROM runtime_runs WHERE task_id = ' . $taskId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
                hub_test_assert(hub_runtime_request_cancel($db, $runId, 'test cancel'), 'test must request cancellation while the process is running');
                hub_test_assert($poll() === 'cancelled', 'async process poll must surface cancellation before natural completion');

                return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'intent' => 'cancelled'];
            },
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'cancelled' && (hub_get_task($db, $taskId)['status'] ?? '') === 'cancelled' && $inspects === 2 && $stops === 2, 'cancellation must stop/remove the running container and skip success');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup default executor proves container cleanup before reporting success', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    $commands = [];
    $inspects = 0;
    try {
        $result = hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'command_runner' => static function (array $command, int $timeoutSeconds) use (&$commands, &$inspects): array {
                $commands[] = $command;
                if (($command[1] ?? '') === 'run') {
                    $mount = $command[array_search('--mount', $command, true) + 1] ?? '';
                    preg_match('/(?:^|,)src=([^,]+)/', (string)$mount, $matches);
                    hub_test_audio_cleanup_write(dirname((string)($matches[1] ?? 'missing')), 'separate');
                    return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                }
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    $inspects++;
                    return $inspects === 1
                        ? ['exit_code' => 0, 'stdout' => '{"Running":true,"Pid":4242}', 'stderr' => '']
                        : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: No such container'];
                }
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);
        hub_test_assert(($result['status'] ?? '') === 'success' && $inspects === 2, 'default executor must re-inspect after stopping and removing the exact container');
        hub_test_assert(array_map(static fn (array $command): string => implode(' ', array_slice($command, 1, 3)), $commands) === ['run --pull=never --network', 'container inspect --format', 'stop -t 10', 'container rm -f', 'container inspect --format'], 'default executor cleanup command order must be deterministic');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup default executor blocks GPU when container cleanup cannot be proven', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
    $task = hub_test_adapter_claim($db);
    try {
        hub_run_pack_job_task($db, $task, [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
            'command_runner' => static function (array $command, int $timeoutSeconds): array {
                if (($command[1] ?? '') === 'run') {
                    return ['exit_code' => 124, 'stdout' => '', 'stderr' => 'Command timed out.'];
                }
                return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Cannot connect to the Docker daemon'];
            },
        ]);
        hub_test_assert((hub_get_task($db, $taskId)['error_code'] ?? '') === 'cleanup_failed' && $db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'blocked', 'unknown timeout cleanup must block GPU instead of claiming a clean exit');
    } finally {
        hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
    }
});

hub_test('audio-cleanup report semantics reject empty and mismatched reports', function (): void {
    foreach ([
        ['operation' => 'separate', 'actual_chain' => [], 'model_versions' => []],
        ['operation' => 'enhance', 'actual_chain' => ['demucs'], 'model_versions' => ['demucs' => 'htdemucs@v4.0.1']],
        array_replace(hub_test_audio_cleanup_report('separate'), ['source_audio' => []]),
        array_replace(hub_test_audio_cleanup_report('separate'), ['elapsed_seconds' => 'fast', 'warnings' => 'none']),
        array_replace(hub_test_audio_cleanup_report('separate'), ['outputs' => ['vocals_audio' => ['sample_rate' => 48000, 'channels' => 1, 'duration_seconds' => 0.01]]]),
        (static function (): array {
            $report = hub_test_audio_cleanup_report('separate');
            $report['source_audio']['sample_rate'] = 16000;
            return $report;
        })(),
        (static function (): array {
            $report = hub_test_audio_cleanup_report('separate');
            $report['outputs']['vocals_audio']['channels'] = 2;
            return $report;
        })(),
        (static function (): array {
            $report = hub_test_audio_cleanup_report('separate');
            $report['model_versions']['demucs'] = 'tampered@v0';
            return $report;
        })(),
    ] as $report) {
        $db = hub_test_reset_db();
        hub_test_audio_cleanup_install($db);
        $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
        $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
        $task = hub_test_adapter_claim($db);
        try {
            hub_run_pack_job_task($db, $task, [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
                'executor' => static function (array $context) use ($report): array {
                    $context['started'](['container_id' => 'invalid-cleanup-report']);
                    hub_test_audio_cleanup_write($context['workspace'], 'separate');
                    file_put_contents($context['workspace'] . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

                    return ['exit_code' => 0, 'container_id' => 'invalid-cleanup-report', 'cleanup' => hub_test_adapter_cleanup()];
                },
            ]);
            hub_test_assert((hub_get_task($db, $taskId)['error_code'] ?? '') === 'output_contract_invalid', 'invalid cleanup report must fail the output contract');
        } finally {
            hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        }
    }
});

hub_test('audio-cleanup report model versions must be nonempty strings', function (): void {
    foreach (['', true, 4] as $version) {
        $db = hub_test_reset_db();
        hub_test_audio_cleanup_install($db);
        $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
        $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'balanced'], 1, null, '127.0.0.1');
        $task = hub_test_adapter_claim($db);
        try {
            hub_run_pack_job_task($db, $task, [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
                'executor' => static function (array $context) use ($version): array {
                    $context['started'](['container_id' => 'invalid-model-version']);
                    hub_test_audio_cleanup_write($context['workspace'], 'separate');
                    $report = hub_test_audio_cleanup_report('separate');
                    $report['model_versions'] = ['demucs' => $version];
                    file_put_contents($context['workspace'] . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

                    return ['exit_code' => 0, 'container_id' => 'invalid-model-version', 'cleanup' => hub_test_adapter_cleanup()];
                },
            ]);
            hub_test_assert((hub_get_task($db, $taskId)['error_code'] ?? '') === 'output_contract_invalid', 'model version values must be nonempty strings');
        } finally {
            hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        }
    }
});

hub_test('audio-cleanup task freezes the selected Demucs config for its runner', function (): void {
    $db = hub_test_reset_db();
    hub_test_audio_cleanup_install($db);
    $route = hub_resolve_audio_async_route($db, 'audio_cleanup');
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['operation' => 'separate', 'demucs_model' => 'quality'], 1, null, '127.0.0.1');
    $manifestPath = HUB_ROOT . '/packs/audio-cleanup/pack.json';
    $original = (string)file_get_contents($manifestPath);
    $manifest = json_decode($original, true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Cannot decode audio-cleanup manifest fixture.');
    }
    $manifest['model_allowlist']['demucs']['aliases']['quality']['model'] = 'changed-live-model';

    try {
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        try {
            $task = hub_test_adapter_claim($db);
            $result = hub_run_pack_job_task($db, $task, [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 16384, 'processes' => []],
                'executor' => static function (array $context): array {
                    hub_test_assert(($context['runner']['config']['alias'] ?? '') === 'quality', 'runner must receive the selected Demucs alias');
                    hub_test_assert(($context['runner']['config']['model']['model'] ?? '') === 'htdemucs_ft', 'runner config must come from the immutable task snapshot');
                    $context['started'](['container_id' => 'frozen-demucs-config']);
                    hub_test_audio_cleanup_write($context['workspace'], 'separate');
                    $report = hub_test_audio_cleanup_report('separate');
                    $report['model_versions']['demucs'] = 'htdemucs_ft@v4.0.1';
                    file_put_contents($context['workspace'] . '/output/cleanup_report.json', json_encode($report, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);

                    return ['exit_code' => 0, 'container_id' => 'frozen-demucs-config', 'cleanup' => hub_test_adapter_cleanup()];
                },
            ]);
            hub_test_assert(($result['status'] ?? '') === 'success', 'frozen Demucs config task must execute');
        } finally {
            hub_test_audio_cleanup_remove(hub_task_result_dir($taskId));
        }
    } finally {
        file_put_contents($manifestPath, $original, LOCK_EX);
    }
});
