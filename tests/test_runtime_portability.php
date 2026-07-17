<?php
declare(strict_types=1);

hub_test('PhaseRuntime-0 platform and path helpers keep host and container paths separate', function (): void {
    hub_test_assert(hub_platform_id('Linux') === 'linux', 'Linux platform id mismatch');
    hub_test_assert(hub_platform_id('Windows') === 'windows', 'Windows platform id mismatch');
    hub_test_assert(hub_platform_id('Darwin') === 'darwin', 'Darwin platform id mismatch');
    hub_test_assert(hub_platform_id('Plan9') === 'unknown', 'unknown platform id mismatch');

    foreach (['/DATA/models/yolo', 'D:\\DATA\\3waAIHub', 'D:/DATA/3waAIHub', '\\\\server\\share\\models'] as $path) {
        hub_test_assert(hub_is_host_absolute_path($path), 'host absolute path not detected: ' . $path);
    }
    hub_test_assert(!hub_is_host_absolute_path('data/models'), 'relative path must not be absolute');
    hub_test_assert(hub_path('D:\\DATA\\3waAIHub') === 'D:/DATA/3waAIHub', 'Windows drive path must not be joined to HUB_ROOT');
    hub_test_assert(hub_path('\\\\server\\share\\models') === '//server/share/models', 'UNC path must not be joined to HUB_ROOT');

    hub_test_assert(hub_container_path('/models/yolo') === '/models/yolo', 'container path mismatch');
    hub_test_assert(hub_container_path('/output/artifacts') === '/output/artifacts', 'container artifact path mismatch');
    foreach (['D:\\DATA\\models', '\\\\server\\share\\models', '../models', '/models/../etc', 'models/yolo'] as $path) {
        hub_test_assert(hub_test_throws(static fn () => hub_container_path($path)), 'unsafe container path accepted: ' . $path);
    }
});

hub_test('PhaseRuntime-0 pack manifests normalize platform targets once with legacy inference', function (): void {
    $legacy = hub_normalize_pack_manifest([
        'id' => 'legacy-docker',
        'runtime' => ['kind' => 'docker'],
    ]);
    hub_test_assert(($legacy['platform_targets']['linux-docker']['supported'] ?? null) === true, 'legacy docker must infer linux-docker support');
    hub_test_assert(($legacy['platform_targets']['linux-docker']['source'] ?? '') === 'legacy_inferred', 'legacy docker source mismatch');

    $declared = hub_normalize_pack_manifest([
        'id' => 'declared-pack',
        'runtime' => ['kind' => 'docker'],
        'platform_targets' => [
            'linux-docker' => true,
            'remote-agent' => ['supported' => true, 'reason' => 'agent handles execution'],
        ],
    ]);
    hub_test_assert(($declared['platform_targets']['linux-docker']['source'] ?? '') === 'declared', 'declared target source mismatch');
    hub_test_assert(($declared['platform_targets']['remote-agent']['reason'] ?? '') === 'agent handles execution', 'declared reason mismatch');

    $unsupported = hub_platform_target_supported('linux-docker', 'windows');
    hub_test_assert($unsupported['supported'] === false, 'Windows host must not support linux-docker locally');
    hub_test_assert(str_contains((string)$unsupported['reason'], 'not available on Windows host'), 'unsupported reason must be explicit');
});

hub_test('PhaseRuntime-0 portability docs and pack UI expose target source and reason', function (): void {
    $doc = (string)@file_get_contents(HUB_ROOT . '/docs/runtime_portability_guardrails.md');
    hub_test_assert(str_contains($doc, 'Portability Guardrails'), 'portability guardrails doc missing');
    hub_test_assert(str_contains($doc, '新增十個 Pack，不如先保證一個 Job 跑一千次都不會莫名其妙'), 'runtime principle missing');

    $packsPage = (string)file_get_contents(HUB_ROOT . '/admin/packs.php');
    hub_test_assert(str_contains($packsPage, 'platform_targets'), 'packs UI must expose platform_targets');
    hub_test_assert(str_contains($packsPage, 'legacy inferred'), 'packs UI must expose legacy inferred source');
    hub_test_assert(str_contains($packsPage, 'unsupported reason'), 'packs UI must expose unsupported reason');
});
