<?php
declare(strict_types=1);

hub_test('Facebook crawler Pack declares one fixed CPU job', function (): void {
    $pack = hub_get_pack('facebook-crawler');
    hub_test_assert(is_array($pack) && $pack['status'] === 'ok', 'facebook-crawler Pack must validate');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_ready'] ?? false) === true, 'crawler runtime must be ready');
    hub_test_assert(($manifest['platform_targets']['linux-docker']['supported'] ?? false) === true
        && ($manifest['platform_targets']['windows-wsl2-linux-docker']['supported'] ?? false) === true, 'crawler must use the shared Linux/WSL container runtime');
    $contract = hub_pack_async_job_contract($manifest, 'crawl');
    hub_test_assert(($contract['runner']['accelerator'] ?? '') === 'cpu', 'crawler must not request GPU');
    hub_test_assert(($contract['runner']['network_profile'] ?? '') === 'public_egress', 'crawler requires bounded public egress');
    hub_test_assert(($contract['runner']['entrypoint'] ?? []) === ['/app/crawl-entrypoint.sh', 'python3', '/app/crawl_runner.py'], 'crawler must invoke the runner through Python');
    hub_test_assert(array_keys($contract['request_schema'] ?? []) === ['profile_id', 'targets_json', 'limit_per_target'], 'runner inputs must remain fixed');
    $dataset = $contract['artifact_contract']['artifacts'][0] ?? [];
    hub_test_assert(($dataset['type'] ?? '') === 'facebook_posts_jsonl'
        && ($dataset['max_bytes'] ?? 0) === 4194304
        && ($dataset['text']['max_bytes'] ?? 0) === 4194304, 'crawler JSONL must use the bounded text validator');

    $shellCheck = HUB_ROOT . '/packs/facebook-crawler/service/test_egress_firewall.sh';
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/facebook-crawler/service/Dockerfile');
    hub_test_assert(is_file($shellCheck) && is_executable($shellCheck)
        && str_contains($dockerfile, "FROM base AS test\nCOPY tests ./tests\nCOPY test_egress_firewall.sh ./\nRUN chmod 0755 test_egress_firewall.sh\n\nFROM base AS runtime"), 'crawler test target must include the executable egress boundary check only in its test stage');
});

hub_test('Facebook crawl resolves only the managed Pack route', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    $route = hub_resolve_pack_job_async_route($db, 'facebook_crawl');
    hub_test_assert(($route['pack_id'] ?? '') === 'facebook-crawler'
        && ($route['job'] ?? '') === 'crawl'
        && ($route['accelerator'] ?? '') === 'cpu', 'crawler route cannot be selected by clients');
});
