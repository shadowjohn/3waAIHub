<?php
declare(strict_types=1);

hub_test('GPT-SoVITS is a separate governed audio mode', function (): void {
    hub_test_assert((hub_pack_job_async_routes()['voice_generate_gpt_sovits'] ?? null) === [
        'pack_id' => 'tts-gpt-sovits',
        'job' => 'synthesize',
        'accelerator' => 'gpu',
    ], 'GPT-SoVITS async route mismatch');

    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'GPT-SoVITS must expose its verified benchmark-ready level');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'GPT-SoVITS target level mismatch');
    hub_test_assert(($manifest['tts_modes'] ?? []) === ['clone', 'ultimate_clone'], 'GPT-SoVITS must expose clone modes only');
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(($job['runner']['required_vram_mb'] ?? 0) === 6144, 'GPT-SoVITS cold GPU budget mismatch');
    hub_test_assert(($job['resident']['protocol'] ?? '') === 'service_data_v1', 'GPT-SoVITS resident protocol mismatch');
    hub_test_assert(in_array('pretrained_models/chinese-roberta-wwm-ext-large/tokenizer.json', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline RoBERTa tokenizer');
    hub_test_assert(in_array('nltk_data/corpora/cmudict/cmudict', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline English pronunciation dictionary');
    hub_test_assert(in_array('g2pw/g2pW.onnx', (array)($job['runner']['asset_mounts'][0]['required_paths'] ?? []), true), 'GPT-SoVITS must require the offline G2PW model');
});

hub_test('GPT-SoVITS clone profile jobs use the existing signed snapshot contract', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $job = hub_pack_async_job_contract($pack['manifest'], 'synthesize');
    $context = $job['voice_context'] ?? [];

    hub_test_assert(($context['clone_value'] ?? '') === 'clone', 'GPT-SoVITS clone snapshot mismatch');
    hub_test_assert(($context['ultimate_value'] ?? '') === 'ultimate_clone', 'GPT-SoVITS ultimate clone snapshot mismatch');
    hub_test_assert(!array_key_exists('design_value', $context), 'GPT-SoVITS must not declare design mode');
    hub_test_assert(!array_key_exists('design_prompt_input', $context), 'GPT-SoVITS must not declare a design prompt');
});

hub_test('GPT-SoVITS publishes clone-only profile API documentation', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
    $route = hub_pack_async_job_contract($pack['manifest'], 'synthesize');
    $contract = hub_public_api_pack_job_async_contract($route + [
        'requested_mode' => 'voice_generate_gpt_sovits',
    ]);
    $operations = array_column((array)($contract['operations'] ?? []), null, 'operation');
    hub_test_assert(
        ($operations['synthesize']['modes'] ?? null) === ['clone', 'ultimate_clone'],
        'GPT-SoVITS public contract must expose clone modes only'
    );
    foreach ((array)($contract['workflow_examples'] ?? []) as $example) {
        hub_test_assert(
            str_contains((string)$example, 'mode=voice_generate_gpt_sovits')
            && !str_contains((string)$example, 'mode=design'),
            'GPT-SoVITS workflow example must use its own mode without design'
        );
    }
    hub_test_assert(hub_is_voice_profile_mode('voice_generate_gpt_sovits'), 'GPT-SoVITS must use the managed voice profile family');
});

hub_test('GPT-SoVITS generated Compose builds from the Pack root', function (): void {
    $pack = hub_get_pack('tts-gpt-sovits');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');

    $compose = hub_generate_pack_compose($pack, 'gpt-sovits-build-root', 18109);
    hub_test_assert(str_contains($compose, 'context: ' . $pack['dir'] . "\n"), 'GPT-SoVITS build context must include service and jobs directories');
    hub_test_assert(str_contains($compose, 'dockerfile: service/Dockerfile'), 'GPT-SoVITS build must retain its service Dockerfile');
    hub_test_assert(str_contains($compose, 'image: 3waaihub/tts-gpt-sovits:0.1.0'), 'GPT-SoVITS service must reuse its runner image');
    hub_test_assert(hub_service_image_tag(['pack_id' => 'tts-gpt-sovits', 'pack_version' => '0.1.0', 'service_key' => 'gpt-sovits-build-root']) === '3waaihub/tts-gpt-sovits:0.1.0', 'GPT-SoVITS service image tag mismatch');
});

hub_test('GPT-SoVITS image exposes upstream top-level modules', function (): void {
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-gpt-sovits/service/Dockerfile');
    hub_test_assert(str_contains($dockerfile, 'PYTHONPATH=/opt/gpt-sovits:/opt/gpt-sovits/GPT_SoVITS:/opt/gpt-sovits/GPT_SoVITS/eres2net'), 'GPT-SoVITS must expose the upstream AR and ERes2Net module paths');
    hub_test_assert(str_contains($dockerfile, '/models/gpt_sovits/g2pw'), 'GPT-SoVITS must resolve the upstream G2PW path from the managed model mount');
});

hub_test('VoxCPM2 public route remains unchanged', function (): void {
    hub_test_assert((hub_pack_job_async_routes()['voice_generate']['pack_id'] ?? '') === 'tts-voxcpm2', 'VoxCPM2 route changed');
});
