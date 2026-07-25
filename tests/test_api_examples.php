<?php
declare(strict_types=1);

hub_test('api examples documentation exists', function (): void {
    $path = HUB_ROOT . '/docs/api_examples.md';
    hub_test_assert(is_file($path), 'docs/api_examples.md missing');
    $docs = (string)file_get_contents($path);
    foreach (['mode=hello', 'mode=ocr', 'mode=translate', 'mode=sam3', 'mode=bioclip', 'unknown mode', 'Hello L5 Reference Pack'] as $needle) {
        hub_test_assert(str_contains($docs, $needle), 'api docs missing ' . $needle);
    }
    hub_test_assert(str_contains($docs, 'real_inference'), 'api docs missing real_inference');
    hub_test_assert(str_contains($docs, 'translate_real_text'), 'api docs missing translate real benchmark');
    hub_test_assert(str_contains($docs, 'sam3_mock_image'), 'api docs missing sam3 mock benchmark');
    hub_test_assert(str_contains($docs, 'sam3_real_image'), 'api docs missing sam3 real benchmark');
    hub_test_assert(str_contains($docs, 'bioclip_mock_image'), 'api docs missing BioCLIP mock benchmark');
    hub_test_assert(str_contains($docs, 'bioclip_real_image'), 'api docs missing BioCLIP real benchmark');
    hub_test_assert(str_contains($docs, 'candidate_labels=plant,insect,bird,mammal'), 'api docs missing BioCLIP labels example');
    hub_test_assert(str_contains($docs, 'text=mammal/insect/plant'), 'api docs missing SAM3 semantic prompt example');
    hub_test_assert(str_contains($docs, 'L5 benchmark ready'), 'api docs missing Translate L5 status');
    foreach (['PowerShell', 'Bash', 'curl.exe'] as $needle) {
        hub_test_assert(str_contains($docs, $needle), 'api docs missing platform copy-paste syntax: ' . $needle);
    }
    hub_test_assert(preg_match('/`\r?\n/', $docs) === 1, 'api docs missing PowerShell backtick continuation');
    hub_test_assert(preg_match('/ \\\\\r?\n/', $docs) === 1, 'api docs missing Bash backslash continuation');

    $contracts = hub_pack_api_contracts();
    hub_test_assert(isset($contracts['ocr-ppocrv5']), 'OCR API contract missing');
    hub_test_assert(($contracts['ocr-ppocrv5']['contract']['endpoint'] ?? '') === '/ocr/image', 'OCR API contract endpoint mismatch');
    hub_test_assert(in_array('real_inference', array_column($contracts['ocr-ppocrv5']['contract']['input']['fields'] ?? [], 'name'), true), 'OCR API contract must expose real_inference');
    hub_test_assert(is_file(HUB_ROOT . '/admin/pack_readiness.php'), 'pack readiness page missing');
    $benchmarkPage = (string)file_get_contents(HUB_ROOT . '/admin/benchmarks.php');
    hub_test_assert(str_contains($benchmarkPage, 'ocr_mock_image'), 'benchmark page must show OCR mock benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'ocr_real_image'), 'benchmark page must show OCR real benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'translate_mock_text'), 'benchmark page must show Translate mock benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'translate_real_text'), 'benchmark page must show Translate real benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'sam3_mock_image'), 'benchmark page must show SAM3 mock benchmark');
    hub_test_assert(str_contains($benchmarkPage, 'sam3_real_image'), 'benchmark page must show SAM3 real benchmark');
});

hub_test('audio task documentation publishes async delivery and runtime contracts', function (): void {
    $apiExamples = (string)file_get_contents(HUB_ROOT . '/docs/api_examples.md');
    foreach (['mode=audio_cleanup', 'mode=speech_transcribe', 'mode=voice_generate', 'callback_target', 'source_artifact_id', 'task_artifacts_ack', 'X-AIHub-Signature'] as $needle) {
        hub_test_assert(str_contains($apiExamples, $needle), 'audio API examples missing ' . $needle);
    }
    $quickstart = (string)file_get_contents(HUB_ROOT . '/docs/client_quickstart.md');
    foreach (['task_status', 'task_result', 'artifact', 'polling fallback', 'HMAC'] as $needle) {
        hub_test_assert(str_contains($quickstart, $needle), 'audio quickstart missing ' . $needle);
    }
    $runtime = (string)file_get_contents(HUB_ROOT . '/docs/pack_runtime_contract_v0.1.md');
    hub_test_assert(str_contains($runtime, 'gpu:0') && str_contains($runtime, 'cleanup_failed'), 'runtime contract must publish fenced GPU cleanup');
    $localJob = (string)file_get_contents(HUB_ROOT . '/docs/local_job_contract_v0.1.md');
    hub_test_assert(str_contains($localJob, 'pack_job') && str_contains($localJob, 'checkpoint'), 'local job contract must publish generic Pack jobs and checkpoints');
    $readme = (string)file_get_contents(HUB_ROOT . '/README.md');
    hub_test_assert(is_file(HUB_ROOT . '/scripts/audio_packs_acceptance.php') && str_contains($readme, 'scripts/audio_packs_acceptance.php'), 'README must publish the real audio acceptance command only after it exists');
    foreach (['php scripts/init_db.php', 'scripts/callback_worker.php', 'scripts/prune_retention.php', 'php scripts/benchmark.php --pack=tts-voxcpm2 --case=tts_real_wav', 'Task 13', 'sync_max_duration_seconds=30'] as $needle) {
        hub_test_assert(str_contains($readme, $needle), 'README audio operations missing ' . $needle);
    }
});
