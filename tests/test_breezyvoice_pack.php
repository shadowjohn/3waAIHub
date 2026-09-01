<?php
declare(strict_types=1);

hub_test('BreezyVoice Pack is an on-demand Taiwan Mandarin ultimate clone contract', function (): void {
    $pack = hub_get_pack('tts-breezyvoice');
    hub_test_assert($pack !== null && ($pack['status'] ?? '') === 'ok', 'BreezyVoice Pack must be valid');

    $manifest = $pack['manifest'];
    $targets = $manifest['platform_targets'] ?? [];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    $context = $job['voice_context'] ?? [];
    $artifacts = $job['output']['artifacts'] ?? [];

    hub_test_assert(
        array_keys($targets) === ['linux-docker', 'windows-wsl2-linux-docker']
        && ($targets['linux-docker']['supported'] ?? null) === true
        && ($targets['windows-wsl2-linux-docker']['supported'] ?? null) === true,
        'BreezyVoice must declare only Linux Docker and Windows WSL2 Linux Docker targets'
    );
    hub_test_assert(
        ($manifest['lifecycle']['lifecycle'] ?? '') === 'on_demand'
        && ($manifest['lifecycle']['gpu_policy'] ?? '') === 'exclusive_gpu',
        'BreezyVoice must use an exclusive on-demand GPU lifecycle'
    );
    hub_test_assert(
        ($manifest['tts_modes'] ?? null) === ['ultimate_clone']
        && ($context['ultimate_value'] ?? '') === 'ultimate_clone'
        && ($context['profile_input'] ?? '') === 'voice_profile_id'
        && ($context['profile_task_input'] ?? '') === 'voice_profile_task_id'
        && ($context['container_path'] ?? '') === '/data/voice_profiles/reference.wav'
        && !array_key_exists('design_value', $context)
        && !array_key_exists('design_prompt_input', $context),
        'BreezyVoice must accept only a transcript-confirmed Ultimate Clone context'
    );
    hub_test_assert(
        array_column($artifacts, 'path') === ['generated_audio.wav', 'synthesis_metadata.json'],
        'BreezyVoice must emit its fixed audio and synthesis metadata artifacts'
    );
});
