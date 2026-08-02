<?php
declare(strict_types=1);

function hub_test_gpu_lease_run(PDO $db, string $runId, string $workerId = 'gpu-worker'): array
{
    $now = hub_now();
    $db->prepare(
        'INSERT INTO runtime_runs
            (run_id, pack_id, task, workspace, state, worker_id, lease_token, lease_expires_at, task_id, started_at, created_at)
         VALUES
            (:run_id, :pack_id, :task, :workspace, :state, :worker_id, :lease_token, :lease_expires_at, :task_id, :started_at, :created_at)'
    )->execute([
        ':run_id' => $runId,
        ':pack_id' => 'gpu-test',
        ':task' => 'test',
        ':workspace' => sys_get_temp_dir() . '/' . $runId,
        ':state' => 'claimed',
        ':worker_id' => $workerId,
        ':lease_token' => bin2hex(random_bytes(32)),
        ':lease_expires_at' => hub_runtime_lease_until(60),
        ':task_id' => null,
        ':started_at' => $now,
        ':created_at' => $now,
    ]);

    return $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($runId))->fetch() ?: [];
}

function hub_test_gpu_recovery_evidence(bool $containerExists = false, bool $containerRunning = false, array $ownedPids = [], bool $ambiguous = false): array
{
    return [
        'container' => ['exists' => $containerExists, 'running' => $containerRunning],
        'owned_pids' => $ownedPids,
        'ambiguous' => $ambiguous,
    ];
}

function hub_test_gpu_lease_expire(PDO $db, array $lease): void
{
    $db->prepare(
        'UPDATE runtime_resource_leases SET lease_expires_at = :expires_at
         WHERE resource_key = :resource_key AND runtime_run_id = :runtime_run_id AND lease_token = :lease_token'
    )->execute([
        ':expires_at' => date('Y-m-d H:i:s', time() - 60),
        ':resource_key' => $lease['resource_key'],
        ':runtime_run_id' => $lease['runtime_run_id'],
        ':lease_token' => $lease['lease_token'],
    ]);
}

function hub_test_resident_vox_fixture(PDO $db, bool $clone = false): array
{
    hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    $service = hub_get_service_by_key($db, 'voxcpm2-main');
    if (!is_array($service)) {
        throw new RuntimeException('Resident service fixture is unavailable.');
    }
    hub_update_service_settings($db, (int)$service['id'], [
        'VOXCPM2_EXECUTION_MODE' => 'resident',
        'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB' => '1024',
    ]);
    $db->prepare(
        "UPDATE services
         SET install_status = 'installed', enabled = 1, status = 'running', runtime_status = 'running', restart_required = 0
         WHERE id = :id"
    )->execute([':id' => (int)$service['id']]);
    $service = hub_get_service($db, (int)$service['id']) ?: $service;
    $model = hub_test_models_dir() . '/voxcpm2/model';
    if (!is_dir($model) && !mkdir($model, 0700, true) && !is_dir($model)) {
        throw new RuntimeException('Cannot create resident model fixture.');
    }
    file_put_contents($model . '/config.json', "{}\n", LOCK_EX);
    $contract = hub_pack_async_job_contract((array)(hub_get_pack('tts-voxcpm2')['manifest'] ?? []), 'synthesize');
    if (!is_array($contract)) {
        throw new RuntimeException('Resident contract fixture is unavailable.');
    }
    $snapshot = hub_pack_job_contract_snapshot($contract);
    $input = [
        'text' => 'resident smoke',
        'mode' => 'design',
        'voice_prompt' => 'clear voice',
        'model' => 'voxcpm2',
    ];
    $attributes = [
        'requested_mode' => 'voice_generate',
        'pack_id' => 'tts-voxcpm2',
        'pack_version' => '0.1.7',
        'job' => 'synthesize',
        'job_contract_json' => $snapshot['json'],
        'job_contract_digest' => $snapshot['digest'],
        'runtime_mode' => 'job',
        'accelerator' => 'gpu',
        'route_resolved_at' => hub_now(),
    ];
    $referencePath = null;
    if ($clone) {
        $ownerMemberId = hub_create_api_member($db, 'Resident clone owner');
        $referencePath = hub_voice_profile_storage_dir() . '/resident_clone_reference.wav';
        file_put_contents($referencePath, 'RIFFresident-clone', LOCK_EX);
        $profileId = hub_create_voice_profile($db, $ownerMemberId, [
            'name' => 'Resident clone profile',
            'reference_audio_path' => $referencePath,
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
        ]);
        $input = [
            'text' => 'resident clone smoke',
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'control' => 'clear voice',
            'model' => 'voxcpm2',
            'voice_context' => [
                'mode' => 'clone',
                'voice_profile_id' => $profileId,
                'reference_audio_sha256' => hash_file('sha256', $referencePath),
                'container_path' => '/data/voice_profiles/reference.wav',
            ],
        ];
        $attributes['owner_member_id'] = $ownerMemberId;
    }
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, $input, null, '127.0.0.1', $attributes);
    $task = hub_claim_next_task($db, hub_pack_job_worker_task_types());
    if (!is_array($task) || (int)$task['id'] !== $taskId) {
        throw new RuntimeException('Resident task fixture was not claimed.');
    }

    return ['service' => $service, 'contract' => $contract, 'task' => $task, 'task_id' => $taskId, 'reference_path' => $referencePath];
}

function hub_test_resident_audio_probe(string $path): array
{
    hub_test_assert(is_file($path), 'resident audio probe must receive a staged output path');

    return ['duration_seconds' => 0.25, 'sample_rate' => 48000, 'channels' => 1, 'frames' => 12000];
}

function hub_test_resident_write_output(string $stage): void
{
    $request = json_decode((string)file_get_contents($stage . '/input/request.json'), true, 64, JSON_THROW_ON_ERROR);
    $expected = hub_voxcpm2_metadata_task_contract($request);
    if ($expected === null) {
        throw new RuntimeException('Resident output fixture request is invalid.');
    }
    $chunkId = 'chunk-0001';
    $seed = hub_voxcpm2_metadata_chunk_seed((int)$expected['task_seed'], (string)$expected['seed_policy'], $chunkId);
    if ($seed === null) {
        throw new RuntimeException('Resident output fixture seed is invalid.');
    }
    $planChunk = [
        'id' => $chunkId,
        'text' => $expected['normalized_input'],
        'text_sha256' => hash('sha256', $expected['normalized_input']),
        'seed' => $seed['seed'],
        'seed_sha256' => $seed['sha256'],
    ];
    $plan = [
        'normalization' => 'semantic-v1',
        'normalized_input' => $expected['normalized_input'],
        'max_chunk_chars' => 240,
        'task_seed' => $expected['task_seed'],
        'seed_policy' => $expected['seed_policy'],
        'chunks' => [$planChunk],
    ];
    $voice = $expected['voice'];
    $metadata = [
        'normalized_input' => $expected['normalized_input'],
        'plan' => $plan + ['plan_sha256' => hash('sha256', hub_voxcpm2_metadata_canonical_json($plan))],
        'model' => ['label' => 'VoxCPM2', 'version' => '2.0.3', 'sample_rate' => 48000],
        'voice_context' => $voice + ['sha256' => hash('sha256', hub_voxcpm2_metadata_canonical_json($voice))],
        'controls' => ['mode' => $expected['mode'], 'seed_policy' => $expected['seed_policy'], 'task_seed' => $expected['task_seed']],
        'chunks' => [[
            'id' => $chunkId,
            'seed' => $seed['seed'],
            'seed_sha256' => $seed['sha256'],
            'attempts' => 1,
            'duration_frames' => 12000,
            'duration_seconds' => 0.25,
            'peak_gain' => 1.0,
            'reused_checkpoint' => false,
            'action' => 'direct_concat',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 0,
        ]],
        'final_format' => ['mime_type' => 'audio/wav', 'sample_rate' => 48000, 'channels' => 1, 'frames' => 12000],
        'loudness' => ['passes' => 1, 'target_lufs' => -16.0, 'gain' => 1.0],
        'timeline' => [['chunk_id' => $chunkId, 'start_frame' => 0, 'end_frame' => 12000, 'sample_rate' => 48000]],
        'device' => ['type' => 'fake', 'real_inference' => false],
    ];
    file_put_contents($stage . '/output/generated_audio.wav', hub_test_pack_job_wav(), LOCK_EX);
    file_put_contents($stage . '/output/synthesis_metadata.json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
}

function hub_test_resident_transport(array $service, string $capacity, array &$requests, bool $terminal = true, ?string $expectedSource = null): callable
{
    return static function (string $method, string $url, array $headers, ?array $payload, ?callable $progress = null) use ($service, $capacity, &$requests, $terminal, $expectedSource): array {
        $requests[] = compact('method', 'url', 'headers', 'payload');
        $token = hub_service_settings_values(hub_db(), $service)['VOXCPM2_INTERNAL_JOB_TOKEN'] ?? '';
        hub_test_assert(str_starts_with($url, 'http://127.0.0.1:' . (int)$service['local_port'] . '/internal/'), 'resident requests must use the service loopback endpoint');
        hub_test_assert(in_array('X-AIHub-Internal-Token: ' . $token, $headers, true), 'resident requests must carry only the internal service token');
        if ($url === 'http://127.0.0.1:' . (int)$service['local_port'] . '/internal/capacity') {
            return ['status' => 200, 'json' => ['model_state' => $capacity, 'active_runs' => $capacity === 'running' ? 1 : 0]];
        }
        if ($method === 'POST' && str_ends_with($url, '/internal/jobs')) {
            $runId = (string)($payload['run_id'] ?? '');
            $stage = hub_pack_job_resident_stage_path($service, $runId);
            if ($expectedSource !== null) {
                hub_test_assert(is_file($stage . '/input/source')
                    && hash_equals((string)hash_file('sha256', $expectedSource), (string)hash_file('sha256', $stage . '/input/source')), 'resident clone must stage the managed reference audio');
            }
            hub_test_resident_write_output($stage);
            return ['status' => 200, 'json' => ['run_id' => $runId, 'state' => 'running']];
        }
        if ($method === 'GET' && preg_match('~/internal/jobs/([^/]+)$~', $url, $matches) === 1) {
            return ['status' => 200, 'json' => ['run_id' => rawurldecode($matches[1]), 'state' => $terminal ? 'succeeded' : 'unknown']];
        }

        return ['status' => 500, 'json' => []];
    };
}

function hub_test_resident_loopback_server(): array
{
    $directory = sys_get_temp_dir() . '/3waaihub_resident_http_' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create resident HTTP fixture directory.');
    }
    $counter = $directory . '/requests';
    $router = $directory . '/router.php';
    file_put_contents(
        $router,
        "<?php\nfile_put_contents(" . var_export($counter, true) . ", \"1\\n\", FILE_APPEND | LOCK_EX);\nusleep(1500000);\nheader('Content-Type: application/json');\necho '{\"ok\":true}';\n",
        LOCK_EX,
    );
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        unlink($router);
        rmdir($directory);
        throw new RuntimeException('Cannot allocate resident HTTP fixture port: ' . $error);
    }
    $address = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    $separator = strrpos($address, ':');
    if ($separator === false) {
        unlink($router);
        rmdir($directory);
        throw new RuntimeException('Cannot parse resident HTTP fixture port.');
    }
    $port = (int)substr($address, $separator + 1);
    $stdout = tempnam(sys_get_temp_dir(), '3waaihub_resident_http_out_');
    $stderr = tempnam(sys_get_temp_dir(), '3waaihub_resident_http_err_');
    if ($stdout === false || $stderr === false) {
        foreach ([$stdout, $stderr, $router] as $path) {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
        rmdir($directory);
        throw new RuntimeException('Cannot allocate resident HTTP fixture logs.');
    }
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
        [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'a'], 2 => ['file', $stderr, 'a']],
        $pipes,
        HUB_ROOT,
    );
    if (!is_resource($process)) {
        unlink($stdout);
        unlink($stderr);
        unlink($router);
        rmdir($directory);
        throw new RuntimeException('Cannot start resident HTTP fixture.');
    }
    fclose($pipes[0]);
    $deadline = microtime(true) + 5.0;
    do {
        $ready = @stream_socket_client('tcp://127.0.0.1:' . $port, $readyErrno, $readyError, 0.1);
        if ($ready !== false) {
            fclose($ready);
            return compact('directory', 'counter', 'router', 'port', 'process', 'stdout', 'stderr');
        }
        if (empty(proc_get_status($process)['running'])) {
            $message = trim((string)file_get_contents($stderr));
            proc_close($process);
            unlink($stdout);
            unlink($stderr);
            unlink($router);
            rmdir($directory);
            throw new RuntimeException('Resident HTTP fixture exited: ' . $message);
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    proc_terminate($process);
    proc_close($process);
    unlink($stdout);
    unlink($stderr);
    unlink($router);
    rmdir($directory);
    throw new RuntimeException('Resident HTTP fixture did not become ready.');
}

function hub_test_resident_loopback_stop(array $server): void
{
    if (isset($server['process']) && is_resource($server['process'])) {
        if (!empty(proc_get_status($server['process'])['running'])) {
            proc_terminate($server['process']);
            usleep(100000);
            if (!empty(proc_get_status($server['process'])['running'])) {
                proc_terminate($server['process'], 9);
            }
        }
        proc_close($server['process']);
    }
    foreach (['counter', 'router', 'stdout', 'stderr'] as $key) {
        if (is_file((string)($server[$key] ?? ''))) {
            unlink((string)$server[$key]);
        }
    }
    if (is_dir((string)($server['directory'] ?? ''))) {
        rmdir((string)$server['directory']);
    }
}

hub_test('Resident Pack contract freezes only the declared service-data channel', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    $manifest = (array)($pack['manifest'] ?? []);
    $contract = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(($contract['resident']['protocol'] ?? null) === 'service_data_v1', 'VoxCPM2 resident declaration must be admitted into the async contract');
    $snapshot = hub_pack_job_contract_snapshot($contract ?? []);
    $frozen = json_decode($snapshot['json'], true, 64, JSON_THROW_ON_ERROR);
    hub_test_assert(($frozen['resident']['mode_value'] ?? null) === 'resident', 'resident declaration must be immutable in the task snapshot');

    $invalid = $manifest;
    $invalid['async_jobs'][0]['resident']['extra'] = 'nope';
    hub_test_assert(hub_pack_async_job_contract($invalid, 'synthesize') === null, 'resident declarations must reject undeclared fields');
    $invalid = $manifest;
    $invalid['async_jobs'][0]['resident']['mode_setting'] = 'UNDECLARED_SETTING';
    hub_test_assert(hub_pack_async_job_contract($invalid, 'synthesize') === null, 'resident declarations must name Pack-declared settings');
    $frozen['resident']['protocol'] = 'anything_else';
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_contract_snapshot($frozen)), 'tampered resident snapshots must fail strict revalidation');
});

hub_test('Resident capacity uses cold full VRAM, ready floor, and never falls back while busy', function (): void {
    $db = hub_test_reset_db();
    $cold = hub_test_resident_vox_fixture($db);
    $requests = [];
    $outcome = hub_run_pack_job_task($db, $cold['task'], [
        'gpu_probe' => static fn (): array => ['free_vram_mb' => 2048, 'processes' => []],
        'resident_transport' => hub_test_resident_transport($cold['service'], 'cold', $requests),
        'command_runner' => static fn (): array => throw new RuntimeException('resident path must not run docker'),
    ]);
    hub_test_assert(($outcome['status'] ?? '') === 'waiting_gpu' && count($requests) === 1, 'cold resident must reserve full model VRAM before dispatch');

    $db = hub_test_reset_db();
    $ready = hub_test_resident_vox_fixture($db);
    $requests = [];
    $outcome = hub_run_pack_job_task($db, $ready['task'], [
        'gpu_probe' => static fn (): array => ['free_vram_mb' => 1024, 'processes' => []],
        'resident_transport' => hub_test_resident_transport($ready['service'], 'ready', $requests),
        'audio_probe' => 'hub_test_resident_audio_probe',
        'command_runner' => static fn (): array => throw new RuntimeException('resident path must not run docker'),
    ]);
    hub_test_assert(($outcome['status'] ?? '') === 'success' && count($requests) >= 3, 'ready resident must use only the configured free-VRAM floor and relay artifacts');

    foreach (['running', 'unknown'] as $state) {
        $db = hub_test_reset_db();
        $busy = hub_test_resident_vox_fixture($db);
        $requests = [];
        $outcome = hub_run_pack_job_task($db, $busy['task'], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'resident_transport' => hub_test_resident_transport($busy['service'], $state, $requests),
            'command_runner' => static fn (): array => throw new RuntimeException('resident path must not run docker'),
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'waiting_gpu' && count($requests) === 1, $state . ' resident capacity must wait without dispatch or fallback');
    }
});

hub_test('Stopped resident services wait before GPU acquisition or dispatch', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_resident_vox_fixture($db);
    hub_update_service_status($db, (int)$fixture['service']['id'], 'stopped');
    $requests = [];
    $outcome = hub_run_pack_job_task($db, $fixture['task'], [
        'gpu_probe' => static fn (): array => throw new RuntimeException('stopped resident must not probe GPU'),
        'resident_transport' => static function () use (&$requests): array {
            $requests[] = true;
            throw new RuntimeException('stopped resident must not dispatch');
        },
    ]);
    $task = hub_get_task($db, $fixture['task_id']);
    $lease = hub_runtime_gpu_fetch($db);
    hub_test_assert(($outcome['status'] ?? '') === 'waiting_gpu'
        && ($task['waiting_reason'] ?? '') === 'resident_service_unavailable'
        && $requests === []
        && (int)$db->query('SELECT COUNT(*) FROM resident_job_runs')->fetchColumn() === 0
        && ($lease === null || ($lease['state'] ?? '') === 'available'), 'stopped resident must not acquire GPU, stage work, or fall back to container dispatch');
});

hub_test('Resident heartbeat interval stays within the runtime lease TTL', function (): void {
    hub_test_assert(hub_pack_job_resident_heartbeat_interval(5, 60) <= (5 / 3), 'minimum resident lease must heartbeat at least three times before expiry');
    hub_test_assert(hub_pack_job_resident_heartbeat_interval(60, 60) <= 20.0, 'resident heartbeat must remain bounded by the configured runtime lease TTL');
});

hub_test('Resident cURL progress callback heartbeats and aborts in-flight loopback requests', function (): void {
    if (!function_exists('curl_init') || !function_exists('proc_open') || (!defined('CURLOPT_XFERINFOFUNCTION') && !defined('CURLOPT_PROGRESSFUNCTION'))) {
        hub_test_skip('resident cURL progress test requires cURL and proc_open');
    }
    $db = hub_test_reset_db();
    $fixture = hub_test_resident_vox_fixture($db);
    $run = hub_pack_job_claim_runtime($db, $fixture['task'], 'resident-curl-test', 5);
    $lease = is_array($run) ? hub_runtime_gpu_acquire_for_task($db, $fixture['task'], $run, 5) : null;
    hub_test_assert(is_array($run) && is_array($lease), 'resident cURL fixture must acquire its runtime and GPU fence');
    $db->prepare("UPDATE runtime_runs SET heartbeat_at = '2000-01-01 00:00:00' WHERE id = :id")->execute([':id' => (int)$run['id']]);
    $server = hub_test_resident_loopback_server();
    $url = 'http://127.0.0.1:' . (int)$server['port'] . '/internal/jobs';
    try {
        $heartbeats = 0;
        $response = hub_pack_job_resident_transport(
            'POST',
            $url,
            ['Accept: application/json', 'X-AIHub-Internal-Token: local-test'],
            ['run_id' => 'resident-curl-heartbeat'],
            null,
            5,
            hub_pack_job_resident_progress(static function () use ($db, $run, $lease, &$heartbeats): ?string {
                $heartbeats++;
                return hub_pack_job_tick($db, $run, $lease, 5);
            }, 0.05),
        );
        $heartbeatAt = (string)$db->query('SELECT heartbeat_at FROM runtime_runs WHERE id = ' . (int)$run['id'])->fetchColumn();
        hub_test_assert(($response['status'] ?? 0) === 200 && $heartbeats >= 2 && $heartbeatAt !== '2000-01-01 00:00:00', 'actual cURL progress callback must refresh the runtime GPU fence while the resident request is in flight');

        foreach (['cancelled', 'timed_out'] as $intent) {
            $startedAt = microtime(true);
            $actual = null;
            try {
                hub_pack_job_resident_transport(
                    'POST',
                    $url,
                    ['Accept: application/json', 'X-AIHub-Internal-Token: local-test'],
                    ['run_id' => 'resident-curl-' . $intent],
                    null,
                    5,
                    hub_pack_job_resident_progress(
                        static fn (): ?string => microtime(true) - $startedAt >= 0.2 ? $intent : null,
                        0.01,
                    ),
                );
            } catch (RuntimeException $error) {
                $actual = $error->getMessage();
            }
            hub_test_assert($actual === 'resident_transport_' . $intent && microtime(true) - $startedAt < 1.25, 'actual cURL progress callback must abort an in-flight resident request for ' . $intent);
        }
        $requests = is_file($server['counter']) ? substr_count((string)file_get_contents($server['counter']), "1\n") : 0;
        hub_test_assert($requests >= 1, 'resident cURL fixture must receive the successful heartbeat request over loopback');
    } finally {
        hub_test_resident_loopback_stop($server);
    }
});

hub_test('Resident Vox clone stages its managed reference and relays artifacts without Docker', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_resident_vox_fixture($db, true);
    $requests = [];
    try {
        $outcome = hub_run_pack_job_task($db, $fixture['task'], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 1024, 'processes' => []],
            'resident_transport' => hub_test_resident_transport($fixture['service'], 'ready', $requests, true, $fixture['reference_path']),
            'audio_probe' => 'hub_test_resident_audio_probe',
            'command_runner' => static fn (): array => throw new RuntimeException('resident clone must not run docker'),
        ]);
        hub_test_assert(($outcome['status'] ?? '') === 'success'
            && array_keys((array)($requests[1]['payload'] ?? [])) === ['run_id'], 'resident clone must post only its opaque run identity and return artifacts');
    } finally {
        if (is_string($fixture['reference_path']) && is_file($fixture['reference_path'])) {
            unlink($fixture['reference_path']);
        }
    }
});

hub_test('Resident confirmation caps each status request at the remaining grace deadline', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_resident_vox_fixture($db);
    $plan = hub_pack_job_resident_plan_for_service($db, $fixture['service'], $fixture['contract']);
    hub_test_assert(is_array($plan), 'resident confirmation fixture requires an eligible service');
    $now = 0.0;
    $advanced = false;
    $timeouts = [];
    $sleeps = [];
    $result = hub_pack_job_resident_confirm_terminal([
        'resident_plan' => $plan,
        'resident_clock' => static function () use (&$now): float {
            return $now;
        },
        'resident_sleeper' => static function (float $seconds) use (&$now, &$sleeps): void {
            $sleeps[] = $seconds;
            $now += $seconds;
        },
    ], 'resident-confirm-deadline', static function (string $method, string $url, array $headers, ?array $payload, ?callable $progress, float $timeoutSeconds) use (&$timeouts): array {
        $timeouts[] = $timeoutSeconds;
        return ['status' => 200, 'json' => ['run_id' => rawurldecode((string)basename($url)), 'state' => 'unknown']];
    }, static function () use (&$now, &$advanced): ?string {
        if (!$advanced) {
            $now = 58.0;
            $advanced = true;
        }

        return null;
    });
    hub_test_assert($result === [] && $timeouts === [2.0] && $sleeps === [2.0] && $now === 60.0, 'resident confirmation must cap status timeout to the remaining grace and stop without a busy retry');
});

hub_test('Resident unconfirmed cancellation retains its exact stage until authenticated reconciliation', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_resident_vox_fixture($db);
    $requests = [];
    $now = 0.0;
    $outcome = hub_run_pack_job_task($db, $fixture['task'], [
        'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
        'resident_transport' => hub_test_resident_transport($fixture['service'], 'cold', $requests, false),
        'resident_status_poll_seconds' => 30,
        'resident_clock' => static function () use (&$now): float {
            return $now;
        },
        'resident_sleeper' => static function (float $seconds) use (&$now): void {
            $now += $seconds;
        },
    ]);
    $row = $db->query('SELECT * FROM resident_job_runs')->fetch();
    $run = $db->query('SELECT * FROM runtime_runs WHERE task_id = ' . (int)$fixture['task_id'])->fetch();
    $stage = hub_pack_job_resident_stage_path($fixture['service'], (string)$row['resident_run_id']);
    $statusPolls = array_values(array_filter($requests, static fn (array $request): bool => ($request['method'] ?? '') === 'GET' && str_contains((string)($request['url'] ?? ''), '/internal/jobs/')));
    hub_test_assert(($outcome['error_code'] ?? '') === 'cleanup_failed' && ($row['lifecycle'] ?? '') === 'unconfirmed' && is_dir($stage)
        && count($statusPolls) === 2 && $now === 60.0, 'resident status confirmation must use the full 60-second grace without starting a request at its deadline');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'blocked', 'unconfirmed resident status must block only its GPU lease');

    $plan = hub_pack_job_resident_plan_for_service($db, $fixture['service'], $fixture['contract']);
    $cancelRequests = [];
    $cancelNow = 0.0;
    $cancel = hub_pack_job_resident_cancel([
        'db' => $db, 'task' => hub_get_task($db, $fixture['task_id']), 'run' => $run, 'workspace' => hub_task_result_dir($fixture['task_id']) . '/workspace',
        'contract' => $fixture['contract'], 'resident_plan' => $plan, 'resident_run_id' => $row['resident_run_id'], 'resident_stage' => $stage,
        'resident_status_poll_seconds' => 30,
        'resident_clock' => static function () use (&$cancelNow): float {
            return $cancelNow;
        },
        'resident_sleeper' => static function (float $seconds) use (&$cancelNow): void {
            $cancelNow += $seconds;
        },
    ], 'cancelled', static function (string $method, string $url, array $headers, ?array $payload) use (&$cancelRequests): array {
        $cancelRequests[] = ['method' => $method, 'url' => $url];
        if ($method === 'POST' && str_ends_with($url, '/cancel')) {
            return ['status' => 200, 'json' => ['ok' => true]];
        }
        if ($method === 'GET' && str_contains($url, '/internal/jobs/')) {
            return ['status' => 200, 'json' => ['run_id' => rawurldecode((string)basename($url)), 'state' => 'unknown']];
        }

        return ['status' => 500, 'json' => []];
    });
    $cancelPosts = array_values(array_filter($cancelRequests, static fn (array $request): bool => ($request['method'] ?? '') === 'POST'));
    $cancelStatusPolls = array_values(array_filter($cancelRequests, static fn (array $request): bool => ($request['method'] ?? '') === 'GET'));
    hub_test_assert(($cancel['cleanup'] ?? null) === [] && is_dir($stage)
        && count($cancelPosts) === 1 && count($cancelStatusPolls) === 2 && $cancelNow === 60.0, 'unconfirmed cancel must post once, then poll authenticated status until the 60-second deadline without deleting the staged resident run');

    hub_update_service_settings($db, (int)$fixture['service']['id'], ['VOXCPM2_EXECUTION_MODE' => 'isolated']);
    $service = hub_get_service($db, (int)$fixture['service']['id']) ?: [];
    $dispatchPlan = hub_pack_job_resident_service($db, $fixture['task'], $fixture['contract']);
    $recoveryPlan = hub_pack_job_resident_plan_for_service($db, $service, $fixture['contract'], false);
    hub_test_assert(empty($dispatchPlan['eligible']) && is_array($recoveryPlan), 'switching to isolated must reject new resident dispatch while preserving an enabled resident endpoint for recovery');

    $reconciled = hub_reconcile_resident_job_runs($db, hub_test_resident_transport($fixture['service'], 'cold', $requests));
    $row = $db->query('SELECT * FROM resident_job_runs')->fetch();
    hub_test_assert($reconciled === 1 && ($row['lifecycle'] ?? '') === 'reconciled' && !is_dir($stage), 'authenticated terminal reconciliation must clean only the staged resident run');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'authenticated reconciliation must release only the matching blocked GPU lease');
});

hub_test('GPU lease acquires once for gpu:0 and uses the runtime text run id', function (): void {
    $db = hub_test_reset_db();
    $first = hub_test_gpu_lease_run($db, 'gpu_race_a', 'worker-a');
    $second = hub_test_gpu_lease_run($db, 'gpu_race_b', 'worker-b');

    $firstLease = hub_runtime_gpu_acquire($db, $first, 60);
    $secondLease = hub_runtime_gpu_acquire($db, $second, 60);

    hub_test_assert(is_array($firstLease) && $secondLease === null, 'gpu:0 must lease to exactly one claimant');
    hub_test_assert(($firstLease['runtime_run_id'] ?? '') === 'gpu_race_a', 'resource lease must use immutable text run_id');
    hub_test_assert(($firstLease['lease_token'] ?? '') === ($first['lease_token'] ?? ''), 'GPU lease must share the runtime current fence token');
});

hub_test('GPU lease rejects non-owner heartbeat release and block operations', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_nonowner');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $other = $lease;
    $other['worker_id'] = 'other-worker';

    hub_test_assert(!hub_runtime_gpu_heartbeat($db, $run, $other, 60), 'non-owner heartbeat must fail');
    hub_test_assert(!hub_runtime_gpu_release($db, $run, $other), 'non-owner release must fail');
    hub_test_assert(!hub_runtime_gpu_block($db, $run, $other, 'test_block'), 'non-owner block must fail');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'non-owner actions must not change lease state');
});

hub_test('GPU and runtime heartbeats roll back together on an invalid GPU fence', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_heartbeat_atomic');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $db->prepare("UPDATE runtime_runs SET heartbeat_at = '2000-01-01 00:00:00' WHERE run_id = :run_id")->execute([':run_id' => $run['run_id']]);
    $bad = $lease;
    $bad['lease_token'] = 'lost-fence';

    hub_test_assert(!hub_runtime_gpu_heartbeat($db, $run, $bad, 60), 'invalid GPU heartbeat must fail');
    hub_test_assert((string)$db->query('SELECT heartbeat_at FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetchColumn() === '2000-01-01 00:00:00', 'runtime heartbeat must roll back with GPU heartbeat');
});

hub_test('GPU release rejects a runtime lease that was taken over', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_release_takeover', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$run['id'], 'worker-b', 60) !== null, 'fixture must take over runtime lease');

    hub_test_assert(!hub_runtime_gpu_release($db, $run, $lease), 'old runtime owner must not release GPU after takeover');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'stale release must leave GPU unchanged');
    hub_test_assert(!hub_runtime_gpu_block($db, $run, $lease, 'stale-worker'), 'old runtime owner must not block GPU after takeover');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'stale block must leave GPU unchanged');
});

hub_test('Expired GPU leases enter recovery_required and cannot be rented immediately', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_expired');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);

    $expired = hub_runtime_gpu_expire($db);
    hub_test_assert(is_array($expired) && ($expired['state'] ?? '') === 'recovery_required', 'expired lease must fence into recovery');
    hub_test_assert(hub_runtime_gpu_acquire($db, hub_test_gpu_lease_run($db, 'gpu_waiting'), 60) === null, 'recovery-required GPU must not be re-rented');
});

hub_test('GPU recovery reopens only clean residue and blocks stuck or ambiguous residue', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_clean_recovery');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'clean fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_runtime_gpu_expire($db);
    $clean = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence());
    hub_test_assert(($clean['state'] ?? '') === 'available', 'clean recovery must reopen GPU');

    $stuckRun = hub_test_gpu_lease_run($db, 'gpu_stuck_recovery');
    $stuckLease = hub_runtime_gpu_acquire($db, $stuckRun, 60);
    hub_test_assert(is_array($stuckLease), 'stuck fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $stuckLease);
    hub_runtime_gpu_expire($db);
    $stuck = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence(false, false, [1234]));
    hub_test_assert(($stuck['state'] ?? '') === 'blocked', 'owned PID residue must block GPU');

    $db->prepare("UPDATE runtime_resource_leases SET state = 'available', runtime_run_id = NULL, worker_id = NULL, lease_token = NULL WHERE resource_key = 'gpu:0'")->execute();
    $ambiguousRun = hub_test_gpu_lease_run($db, 'gpu_ambiguous_recovery');
    $ambiguousLease = hub_runtime_gpu_acquire($db, $ambiguousRun, 60);
    hub_test_assert(is_array($ambiguousLease), 'ambiguous fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $ambiguousLease);
    hub_runtime_gpu_expire($db);
    $ambiguous = hub_runtime_gpu_recover($db, static fn (array $run, array $lease): array => hub_test_gpu_recovery_evidence(false, false, [], true));
    hub_test_assert(($ambiguous['state'] ?? '') === 'blocked', 'ambiguous residue must block GPU');
});

hub_test('GPU recovery blocks a lease whose runtime ownership was taken over without inspection', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_recovery_takeover', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    hub_test_assert(hub_runtime_takeover_stale($db, (int)$run['id'], 'worker-b', 60) !== null, 'fixture must take over runtime');
    $inspected = false;
    $recovered = hub_runtime_gpu_recover($db, static function () use (&$inspected): array {
        $inspected = true;
        return hub_test_gpu_recovery_evidence();
    });

    hub_test_assert(!$inspected, 'takeover mismatch must not inspect or clean another owner run');
    hub_test_assert(($recovered['state'] ?? '') === 'blocked' && ($recovered['last_error'] ?? '') === 'runtime_ownership_conflict', 'takeover mismatch must block GPU safely');
});

hub_test('GPU recovery reopens clean residue when both the runtime and GPU leases expired together', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_recovery_runtime_expired', 'worker-a');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);
    $recovered = hub_runtime_gpu_recover($db, static function (): array {
        return hub_test_gpu_recovery_evidence();
    });

    hub_test_assert(($recovered['state'] ?? '') === 'available', 'matching expired runtime ownership with no residue must reopen GPU');
});

hub_test('GPU recovery blocks unavailable malformed or incomplete inspector evidence', function (): void {
    foreach ([
        [],
        ['container' => ['exists' => false, 'running' => false], 'owned_pids' => []],
        ['container' => ['exists' => 'no', 'running' => false], 'owned_pids' => [], 'ambiguous' => false],
        ['container' => ['exists' => false, 'running' => false], 'owned_pids' => ['not-a-pid'], 'ambiguous' => false],
    ] as $evidence) {
        $db = hub_test_reset_db();
        $run = hub_test_gpu_lease_run($db, 'gpu_bad_evidence_' . bin2hex(random_bytes(3)));
        $lease = hub_runtime_gpu_acquire($db, $run, 60);
        hub_test_assert(is_array($lease), 'fixture must acquire GPU');
        hub_test_gpu_lease_expire($db, $lease);
        hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'fixture must require recovery');

        $result = hub_runtime_gpu_recover($db, static fn (): array => $evidence);
        hub_test_assert(($result['state'] ?? '') === 'blocked', 'malformed recovery evidence must block GPU');
    }
});

hub_test('GPU recovery blocks inspector and cleanup callback failures without further actions', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_inspector');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'throwing inspector fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'throwing inspector fixture must require recovery');
    $cleanupCalled = false;
    $inspectorResult = hub_runtime_gpu_recover(
        $db,
        static function (): array {
            throw new RuntimeException('inspector transport failed');
        },
        static function () use (&$cleanupCalled): bool {
            $cleanupCalled = true;
            return true;
        }
    );
    hub_test_assert(($inspectorResult['state'] ?? '') === 'blocked' && ($inspectorResult['last_error'] ?? '') === 'recovery_inspection_failed', 'throwing inspector must block the exact recovery lease');
    hub_test_assert(!$cleanupCalled, 'throwing inspector must not invoke cleanup afterward');

    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_cleanup');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'throwing cleanup fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'throwing cleanup fixture must require recovery');
    $inspections = 0;
    $cleanupResult = hub_runtime_gpu_recover(
        $db,
        static function () use (&$inspections): array {
            $inspections++;
            return hub_test_gpu_recovery_evidence(true);
        },
        static function (): bool {
            throw new RuntimeException('container removal failed');
        }
    );
    hub_test_assert(($cleanupResult['state'] ?? '') === 'blocked' && ($cleanupResult['last_error'] ?? '') === 'container_cleanup_failed', 'throwing cleanup must block the exact recovery lease');
    hub_test_assert($inspections === 1, 'throwing cleanup must not inspect or act again afterward');

    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_throwing_inspector_fence_lost');
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'lost blocking fence fixture must acquire GPU');
    hub_test_gpu_lease_expire($db, $lease);
    hub_test_assert(hub_runtime_gpu_expire($db) !== null, 'lost blocking fence fixture must require recovery');
    $error = null;
    try {
        hub_runtime_gpu_recover($db, static function () use ($db): array {
            $db->exec("UPDATE runtime_resource_leases SET lease_token = 'new-recovery-owner' WHERE resource_key = 'gpu:0'");
            throw new RuntimeException('inspector lost its exact recovery fence');
        });
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    hub_test_assert($error === 'inspector lost its exact recovery fence', 'callback error may escape only after its exact blocking fence is lost');
});

hub_test('GPU acquire rejects an expired runtime lease', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'gpu_acquire_expired');
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expired WHERE run_id = :run_id')->execute([
        ':expired' => date('Y-m-d H:i:s', time() - 60),
        ':run_id' => $run['run_id'],
    ]);

    hub_test_assert(hub_runtime_gpu_acquire($db, $run, 60) === null, 'expired runtime owner must not acquire GPU');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'expired acquisition must leave GPU available');
});

hub_test('GPU preflight waits with a stable reason and releases the lease without probing hardware in tests', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-task-lock' WHERE id = :id")->execute([':id' => $taskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_low_vram');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id WHERE run_id = :run_id')->execute([':task_id' => $taskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');

    $result = hub_runtime_gpu_preflight($db, $taskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 900, 'processes' => []], 15, 256);
    $task = hub_get_task($db, $taskId);
    hub_test_assert(($result['reason'] ?? '') === 'insufficient_vram' && ($task['status'] ?? '') === 'waiting_gpu', 'low VRAM must wait with stable reason');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'preflight wait must release GPU');
    hub_test_assert(!empty($task['next_attempt_at']), 'preflight wait must schedule a backoff');

    $db->prepare("UPDATE tasks SET status = 'running', waiting_reason = NULL WHERE id = :id")->execute([':id' => $taskId]);
    $db->prepare("UPDATE runtime_runs SET state = 'claimed', lease_token = :token, worker_id = :worker, lease_expires_at = :lease_expires_at WHERE run_id = :run_id")->execute([
        ':token' => bin2hex(random_bytes(32)),
        ':worker' => 'gpu-worker',
        ':lease_expires_at' => hub_runtime_lease_until(60),
        ':run_id' => $run['run_id'],
    ]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    $result = hub_runtime_gpu_preflight($db, $taskId, $run, $lease ?: [], 1, static fn (): array => ['free_vram_mb' => 4096, 'processes' => [991]], 15, 256);
    hub_test_assert(!empty($result['ok']) && ($result['unmanaged_pids'] ?? []) === [991], 'unmanaged GPU processes may coexist when the required VRAM plus margin is available');

    $result = hub_runtime_gpu_preflight($db, $taskId, $run, $lease ?: [], 4096, static fn (): array => ['free_vram_mb' => 4096, 'processes' => [991]], 15, 256);
    hub_test_assert(($result['reason'] ?? '') === 'unmanaged_gpu_process', 'unmanaged GPU processes must wait without being killed when they leave insufficient VRAM');
});

hub_test('GPU preflight cannot move a different task into waiting_gpu', function (): void {
    $db = hub_test_reset_db();
    $ownerTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $otherTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-owner' WHERE id = :id")->execute([':id' => $ownerTaskId]);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-other' WHERE id = :id")->execute([':id' => $otherTaskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_cross_task');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id WHERE run_id = :run_id')->execute([':task_id' => $ownerTaskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');

    $result = hub_runtime_gpu_preflight($db, $otherTaskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 0, 'processes' => []], 15, 256);
    hub_test_assert(($result['reason'] ?? '') === 'lost_gpu_lease', 'cross-task preflight must lose its fence');
    hub_test_assert((hub_get_task($db, $otherTaskId)['status'] ?? '') === 'running', 'cross-task preflight must not change another task');
    hub_test_assert((string)$db->query('SELECT state FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetchColumn() === 'claimed', 'cross-task preflight must not change runtime state');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'leased', 'cross-task preflight must not release GPU');
});

hub_test('Due waiting_gpu Pack task promotes once and is claimable without changing runtime attempt', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, [], null, '127.0.0.1', ['accelerator' => 'gpu']);
    $db->prepare("UPDATE tasks SET status = 'running', lock_token = 'gpu-retry-lock' WHERE id = :id")->execute([':id' => $taskId]);
    $run = hub_test_gpu_lease_run($db, 'gpu_waiting_retry');
    $db->prepare('UPDATE runtime_runs SET task_id = :task_id, attempt_no = 7 WHERE run_id = :run_id')->execute([':task_id' => $taskId, ':run_id' => $run['run_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'fixture must acquire GPU');
    hub_runtime_gpu_preflight($db, $taskId, $run, $lease, 1024, static fn (): array => ['free_vram_mb' => 0, 'processes' => []], 1, 256);
    $db->prepare('UPDATE tasks SET next_attempt_at = :past WHERE id = :id')->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $taskId]);

    hub_test_assert(hub_promote_due_waiting_gpu_task($db), 'one due waiting GPU task must promote');
    hub_test_assert(!hub_promote_due_waiting_gpu_task($db), 'promoted task must not promote twice');
    $task = hub_get_task($db, $taskId);
    $retryRun = $db->query('SELECT state, worker_id, lease_token, attempt_no FROM runtime_runs WHERE run_id = ' . $db->quote($run['run_id']))->fetch();
    hub_test_assert(($task['status'] ?? '') === 'queued' && empty($task['lock_token']) && empty($task['next_attempt_at']), 'promotion must restore queued task without a lock');
    hub_test_assert(($retryRun['state'] ?? '') === 'queued' && $retryRun['worker_id'] === null && $retryRun['lease_token'] === null && (int)$retryRun['attempt_no'] === 7, 'promotion must clear runtime lease without changing attempt');
    $claimed = hub_claim_next_task($db, ['pack_job']);
    hub_test_assert((int)($claimed['id'] ?? 0) === $taskId, 'promoted task must be claimable once');

    $db->prepare("UPDATE tasks SET status = 'waiting_gpu', next_attempt_at = :past WHERE id = :id")->execute([':past' => date('Y-m-d H:i:s', time() - 60), ':id' => $taskId]);
    $db->prepare("UPDATE runtime_runs SET state = 'waiting_gpu' WHERE run_id = :run_id")->execute([':run_id' => $run['run_id']]);
    $db->prepare("UPDATE runtime_resource_leases SET state = 'blocked' WHERE resource_key = 'gpu:0'")->execute();
    hub_test_assert(!hub_promote_due_waiting_gpu_task($db), 'blocked GPU must not promote waiting work');
    hub_test_assert((hub_get_task($db, $taskId)['status'] ?? '') === 'waiting_gpu', 'blocked GPU must leave waiting task unchanged');
});

hub_test('CPU ffmpeg tasks do not request a GPU lease', function (): void {
    $db = hub_test_reset_db();
    $run = hub_test_gpu_lease_run($db, 'cpu_ffmpeg');
    hub_test_assert(!hub_runtime_task_requires_gpu(['runtime_mode' => 'ffmpeg', 'accelerator' => 'cpu']), 'CPU ffmpeg must not require GPU');
    hub_test_assert(hub_runtime_gpu_acquire_for_task($db, ['runtime_mode' => 'ffmpeg', 'accelerator' => 'cpu'], $run, 60) === null, 'CPU ffmpeg must not acquire GPU');
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'CPU ffmpeg must leave GPU available');
});

hub_test('GPU Pack terminal completion releases only its exact GPU fence atomically', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'terminal fixture must acquire GPU');
    $bad = $lease;
    $bad['lease_token'] = 'lost-gpu-fence';

    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted(), $bad)), 'terminal fence must roll back when GPU lease was lost');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running' && (string)$db->query('SELECT state FROM runtime_runs WHERE id = ' . (int)$run['id'])->fetchColumn() === 'running', 'lost GPU fence must roll back terminal task and run');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', hub_test_pack_job_cleanup_asserted(), $lease);
    hub_test_assert((string)$db->query("SELECT state FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetchColumn() === 'available', 'terminal completion must release exact GPU lease');
});

hub_test('GPU Pack stale ownership cannot publish artifacts before terminal fencing', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'success fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');
    $bad = $lease;
    $bad['lease_token'] = 'lost-gpu-fence';

    hub_test_assert(hub_test_throws(static fn () => hub_commit_pack_job_success($db, $fixture['task_id'], $run, $validated, hub_test_pack_job_cleanup_asserted(), $bad)), 'stale GPU ownership must reject Pack success');
    hub_test_assert(!is_dir(hub_task_result_dir($fixture['task_id']) . '/artifacts'), 'stale GPU ownership must not publish artifacts before terminal fencing');
});

hub_test('GPU Pack fence loss after artifact staging removes only its staged handoff without DB success', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'handoff fixture must acquire GPU');
    hub_test_assert(is_string(hub_pack_job_handoff_scope($run, $lease)), 'GPU handoff scope must bind the active runtime fence');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $result = hub_commit_pack_job_success(
        $db,
        $fixture['task_id'],
        $run,
        $validated,
        hub_test_pack_job_cleanup_asserted(),
        $lease,
        static function () use ($db): void {
            $db->exec("UPDATE runtime_resource_leases SET lease_token = 'stale-after-stage' WHERE resource_key = 'gpu:0'");
        }
    );
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert(($result['ok'] ?? true) === false && ($result['error_code'] ?? '') === 'gpu_ownership_conflict', 'post-stage GPU fence loss must not succeed');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'post-stage fence loss must not register artifacts');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'post-stage fence loss must not enqueue callbacks');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'post-stage fence loss must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack inner terminal fence loss removes its staged handoff', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'inner terminal fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $error = null;
    try {
        hub_commit_pack_job_success(
            $db,
            $fixture['task_id'],
            $run,
            $validated,
            hub_test_pack_job_cleanup_asserted(),
            $lease,
            null,
            static function () use ($db): void {
                $db->exec("UPDATE runtime_resource_leases SET lease_token = 'stale-inside-terminal' WHERE resource_key = 'gpu:0'");
            }
        );
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert($error === 'gpu_ownership_conflict', 'inner terminal fence loss must preserve its ownership error');
    hub_test_assert((hub_get_task($db, $fixture['task_id'])['status'] ?? '') === 'running', 'inner terminal fence loss must not terminalize its task');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_artifacts WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'inner terminal fence loss must not register artifacts');
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$fixture['task_id'])->fetchColumn() === 0, 'inner terminal fence loss must not enqueue callbacks');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'inner terminal fence loss must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack staged handoff failure discards only its lease-scoped directory', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    hub_test_pack_job_clear_published_artifacts($db, $fixture['task_id']);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $db->prepare('UPDATE runtime_runs SET lease_expires_at = :expires_at WHERE id = :id')->execute([':expires_at' => hub_runtime_lease_until(60), ':id' => $fixture['run']['id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'handoff failure fixture must acquire GPU');
    hub_test_pack_job_write($fixture['workspace'] . '/output/transcript.json', "{\"text\":\"hello\"}\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/subtitle.srt', "1\n00:00:00,000 --> 00:00:01,000\nhello\n");
    hub_test_pack_job_write($fixture['workspace'] . '/output/audio.wav', hub_test_pack_job_wav());
    $validated = hub_validate_pack_job_artifacts($fixture['workspace'], ['include_subtitles' => true], hub_test_pack_job_contract(), 'hub_test_pack_job_audio_probe');

    $result = hub_commit_pack_job_success(
        $db,
        $fixture['task_id'],
        $run,
        $validated,
        hub_test_pack_job_cleanup_asserted(),
        $lease,
        static function (): void {
            throw new RuntimeException('handoff hook failed');
        }
    );
    $artifactRoot = hub_task_result_dir($fixture['task_id']) . '/artifacts';
    hub_test_assert(($result['ok'] ?? true) === false && ($result['error_code'] ?? '') === 'output_contract_invalid', 'handoff failure must preserve its terminal outcome');
    hub_test_assert(!is_dir($artifactRoot) || (glob($artifactRoot . '/*') ?: []) === [], 'handoff failure must remove its lease-scoped handoff directory');
});

hub_test('GPU Pack cleanup failure terminalizes with cleanup_failed and blocks the exact GPU lease', function (): void {
    $db = hub_test_reset_db();
    $fixture = hub_test_pack_job_create_terminal_fixture($db);
    $db->prepare("UPDATE tasks SET accelerator = 'gpu' WHERE id = :id")->execute([':id' => $fixture['task_id']]);
    $run = $db->query('SELECT * FROM runtime_runs WHERE id = ' . (int)$fixture['run']['id'])->fetch();
    $lease = hub_runtime_gpu_acquire($db, $run, 60);
    hub_test_assert(is_array($lease), 'cleanup fixture must acquire GPU');

    hub_commit_pack_job_failure($db, $fixture['task_id'], $run, 'failed', 'runtime_exit_nonzero', 'runner failed', [], $lease);
    $task = hub_get_task($db, $fixture['task_id']);
    $resource = $db->query("SELECT state, last_error FROM runtime_resource_leases WHERE resource_key = 'gpu:0'")->fetch();
    hub_test_assert(($task['status'] ?? '') === 'failed' && ($task['error_code'] ?? '') === 'cleanup_failed', 'cleanup failure must terminalize as cleanup_failed');
    hub_test_assert(($resource['state'] ?? '') === 'blocked' && ($resource['last_error'] ?? '') === 'cleanup_failed', 'cleanup failure must block GPU instead of releasing it');
});
