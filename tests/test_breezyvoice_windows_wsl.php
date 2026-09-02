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
