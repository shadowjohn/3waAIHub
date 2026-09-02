<?php
declare(strict_types=1);

const HUB_TEST_BREEZY_PASCAL_IMAGE = '3waaihub/tts-breezyvoice:0.1.1-pascal-cu118';

function hub_test_breezy_wsl_script_payload(array $command): string
{
    $script = (string)end($command);
    if (preg_match('/printf %s ([A-Za-z0-9+\\/=]+) \\| base64 -d \\| bash/', $script, $matches) !== 1) {
        throw new RuntimeException('BreezyVoice WSL payload is missing.');
    }
    $payload = base64_decode($matches[1], true);
    if (!is_string($payload)) {
        throw new RuntimeException('BreezyVoice WSL payload is invalid.');
    }

    return $payload;
}

function hub_test_breezy_wsl_compose_payload(string $script): string
{
    if (preg_match("/compose_payload='([A-Za-z0-9+\\/=]+)'/", $script, $matches) !== 1) {
        throw new RuntimeException('BreezyVoice WSL compose payload is missing.');
    }
    $payload = base64_decode($matches[1], true);
    if (!is_string($payload)) {
        throw new RuntimeException('BreezyVoice WSL compose payload is invalid.');
    }

    return $payload;
}

function hub_test_breezy_wsl_remove(string $path): void
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
            hub_test_breezy_wsl_remove($path . '/' . $name);
        }
    }
    rmdir($path);
}

hub_test('BreezyVoice Windows WSL compose selects the Pascal CUDA 11.8 profile', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'tts-breezyvoice', [
        'service_key' => 'breezy-pascal',
        'idempotent' => true,
        'provision_runner' => false,
    ])['service'];
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
        'pack_profiles' => ['tts-breezyvoice' => 'pascal-cu118'],
    ]]];

    hub_test_assert(function_exists('hub_breezyvoice_wsl_runtime_profile'), 'BreezyVoice must define a dedicated WSL runtime selector');
    $runtime = hub_breezyvoice_wsl_runtime_profile($service, $profile);
    $script = hub_test_breezy_wsl_script_payload(hub_wsl_service_compose_command($service, ['build', '--progress=plain'], $profile));
    $compose = hub_test_breezy_wsl_compose_payload($script);

    hub_test_assert(
        ($runtime['profile_id'] ?? '') === 'pascal-cu118'
        && hub_service_build_timeout_sec(['pack_id' => 'tts-breezyvoice']) === 2100
        && hub_service_runtime_image_tag($service, $profile) === HUB_TEST_BREEZY_PASCAL_IMAGE
        && str_contains($compose, 'dockerfile: "Dockerfile.pascal-cu118"')
        && str_contains($compose, 'image: "' . HUB_TEST_BREEZY_PASCAL_IMAGE . '"')
        && str_contains($compose, '/DATA/models/breezyvoice:/models/breezyvoice')
        && str_contains($compose, 'gpus: all')
        && str_contains($script, "DOCKER_BUILDKIT=1 docker build --progress=plain --tag '" . HUB_TEST_BREEZY_PASCAL_IMAGE . "'")
        && str_contains($script, "--file '/DATA/3waAIHub-runtime/packs/tts-breezyvoice/service/Dockerfile.pascal-cu118'"),
        'GTX 1080 must build and run BreezyVoice only with the Pascal CUDA 11.8 profile'
    );
});

hub_test('BreezyVoice Pascal CUDA 11.8 image can host the managed health API and isolated runner', function (): void {
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/service/Dockerfile.pascal-cu118');
    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/tts-breezyvoice/service/requirements.pascal-cu118.txt');

    hub_test_assert(
        str_contains($dockerfile, 'ARG BREEZYVOICE_UPSTREAM_REVISION=d592c9d3e8927a0f53f68616387060dcd32a05ea')
        && str_contains($dockerfile, 'libsndfile1 ffmpeg')
        && str_contains($dockerfile, 'COPY service/app.py service/job.py service/provision.py ./')
        && str_contains($dockerfile, 'python3 -m py_compile /app/app.py /app/job.py /app/provision.py')
        && str_contains($dockerfile, 'CMD ["python3", "-m", "uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]')
        && !str_contains($dockerfile, 'ENTRYPOINT ["/app/voice_generate.sh"]')
        && str_contains($requirements, 'fastapi==0.111.0')
        && str_contains($requirements, 'uvicorn[standard]==0.30.1'),
        'Pascal image must serve health checks by default while preserving the explicit one-shot entrypoint'
    );
});

hub_test('BreezyVoice provisions its pinned model through an explicit WSL one-shot', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'tts-breezyvoice', [
        'service_key' => 'breezy-provision',
        'idempotent' => true,
        'provision_runner' => false,
    ])['service'];
    $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
        'models_root' => '/DATA/models',
        'pack_profiles' => ['tts-breezyvoice' => 'pascal-cu118'],
    ]]];
    $plan = function_exists('hub_breezyvoice_provisioning_plan')
        ? hub_breezyvoice_provisioning_plan($db, $service, $profile, 'windows')
        : null;
    $payload = is_array($plan) ? hub_test_breezy_wsl_script_payload($plan['command']) : '';
    $runner = (string)file_get_contents(HUB_ROOT . '/app/docker_runner.php');
    $worker = (string)file_get_contents(HUB_ROOT . '/scripts/command_worker.php');
    $marketplace = (string)file_get_contents(HUB_ROOT . '/admin/marketplace.php');

    hub_test_assert(
        hub_is_valid_job_action('breezyvoice_provision')
        && is_array($plan)
        && str_contains($payload, '--network bridge')
        && str_contains($payload, "--volume '/DATA/models:/models'")
        && str_contains($payload, '/app/provision_models.sh')
        && str_contains($payload, "'/models/breezyvoice' 'MediaTek-Research/BreezyVoice' 'e33b502e0ac21c16b0ee0d00df66ac3fa737393d'")
        && !str_contains($payload, 'docker compose')
        && str_contains($runner, 'function hub_run_breezyvoice_provision_job')
        && str_contains($worker, "'breezyvoice_provision' => hub_run_breezyvoice_provision_job")
        && str_contains($marketplace, "'provision_breezyvoice' => 'breezyvoice_provision'"),
        'BreezyVoice model download must be an explicit pinned WSL one-shot, not an inference side effect'
    );
});

hub_test('BreezyVoice provisioning streams long WSL output through the managed command job logs', function (): void {
    $runner = (string)file_get_contents(HUB_ROOT . '/app/docker_runner.php');
    $start = strpos($runner, 'function hub_run_breezyvoice_provision_job');
    $end = $start === false ? false : strpos($runner, "\n/**", $start);
    $function = $start === false ? '' : substr($runner, $start, ($end === false ? strlen($runner) : $end) - $start);

    hub_test_assert(
        str_contains($function, 'hub_run_service_command(')
        && str_contains($function, "'provisioning_model'")
        && !str_contains($function, 'hub_run_command($plan[\'command\']'),
        'BreezyVoice provisioning must stream long WSL download output instead of blocking on Windows process pipes'
    );
});

hub_test('BreezyVoice Windows WSL jobs require a dedicated ext4 one-shot executor', function (): void {
    hub_test_assert(
        function_exists('hub_breezyvoice_wsl_execution_plan')
        && function_exists('hub_breezyvoice_wsl_executor'),
        'BreezyVoice must not send a Windows workspace to the direct Linux Docker runner'
    );
});

hub_test('BreezyVoice WSL one-shot stages only declared files and returns declared artifacts', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'tts-breezyvoice', ['idempotent' => true, 'provision_runner' => false])['service'];
    $pack = hub_get_pack('tts-breezyvoice');
    $job = is_array($pack) ? hub_pack_async_job_contract($pack['manifest'], 'synthesize') : null;
    hub_test_assert(is_array($job), 'BreezyVoice synthesize contract is required');
    $workspace = sys_get_temp_dir() . '/3waaihub_breezy_wsl_' . bin2hex(random_bytes(8));
    try {
        foreach (['input', 'output', 'checkpoints'] as $directory) {
            hub_test_assert(mkdir($workspace . '/' . $directory, 0700, true), 'Cannot create BreezyVoice WSL workspace fixture');
        }
        hub_test_assert(file_put_contents($workspace . '/input/request.json', "{}\n", LOCK_EX) !== false, 'Cannot write request fixture');
        hub_test_assert(file_put_contents($workspace . '/input/runner_config.json', "{}\n", LOCK_EX) !== false, 'Cannot write runner config fixture');
        $reference = $workspace . '/reference.wav';
        hub_test_assert(file_put_contents($reference, 'RIFFfixture', LOCK_EX) !== false, 'Cannot write reference fixture');
        $profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
            'supported' => true,
            'distro' => 'Ubuntu-24.04',
            'runtime_root' => '/DATA/3waAIHub-runtime',
            'models_root' => '/DATA/models',
            'pack_profiles' => ['tts-breezyvoice' => 'pascal-cu118'],
        ]]];
        $runner = $job['runner'];
        $runner['voice_profile_mount'] = ['source' => $reference, 'container_path' => '/data/voice_profiles/reference.wav'];
        $context = [
            'task' => ['pack_id' => 'tts-breezyvoice', 'job' => 'synthesize'],
            'run' => ['run_id' => 'packjob-73-breezyfixture'],
            'workspace' => $workspace,
            'runner' => $runner,
        ];
        $plan = hub_breezyvoice_wsl_execution_plan($service, $context, $profile);
        $payload = hub_test_breezy_wsl_script_payload($plan['command']);
        hub_test_assert(
            ($plan['container_id'] ?? '') === 'aihub-pack-packjob-73-breezyfixture'
            && str_contains($payload, '/DATA/3waAIHub-runtime/jobs/tts-breezyvoice/packjob-73-breezyfixture')
            && str_contains($payload, "'--network' 'none' '--gpus' 'all'")
            && str_contains($payload, HUB_TEST_BREEZY_PASCAL_IMAGE)
            && str_contains($payload, '/DATA/models/breezyvoice')
            && str_contains($payload, 'generated_audio.wav')
            && str_contains($payload, 'synthesis_metadata.json')
            && !str_contains($payload, 'type=bind,src=' . $workspace),
            'WSL one-shot must stage source files on ext4 and never bind-mount the Windows workspace'
        );
    } finally {
        hub_test_breezy_wsl_remove($workspace);
    }
});
