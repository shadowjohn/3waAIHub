<?php
declare(strict_types=1);

hub_test('GPT-SoVITS Cluster contract keeps clone profiles on their own mode', function (): void {
    hub_test_with_cluster_secret(function (): void {
        $db = hub_test_reset_db();
        $pack = hub_get_pack('tts-gpt-sovits');
        hub_test_assert($pack !== null && $pack['status'] === 'ok', 'GPT-SoVITS Pack must be valid');
        $route = hub_pack_async_job_contract($pack['manifest'], 'synthesize') + [
            'requested_mode' => 'voice_generate_gpt_sovits',
        ];
        $contract = hub_public_api_pack_job_async_contract($route);
        $service = hub_public_api_service_from_contract(
            'voice_generate_gpt_sovits',
            $pack,
            $pack['manifest'],
            $contract
        );
        $station = hub_test_cluster_router_station($db, [
            'station_key' => 'gpt_sovits_cluster',
            'station_token' => 'gpt_sovits_cluster_token',
            'modes' => ['voice_generate_gpt_sovits'],
        ]);
        $now = hub_now();
        $db->prepare(
            'UPDATE cluster_stations
             SET manifest_json = :manifest_json, manifest_fetched_at = :manifest_fetched_at,
                 status_json = :status_json, status_fetched_at = :status_fetched_at
             WHERE id = :id'
        )->execute([
            ':manifest_json' => json_encode(['modes' => ['voice_generate_gpt_sovits'], 'services' => [$service]], JSON_THROW_ON_ERROR),
            ':manifest_fetched_at' => $now,
            ':status_json' => json_encode([
                'modes' => ['voice_generate_gpt_sovits'],
                'gpu' => ['memory_free_mb' => 16384],
                'active_gpu_leases' => 0,
                'queued_jobs' => 0,
                'running_jobs' => 0,
            ], JSON_THROW_ON_ERROR),
            ':status_fetched_at' => $now,
            ':id' => (int)$station['id'],
        ]);

        $clusterService = array_column(hub_cluster_public_manifest($db)['services'], null, 'mode')['voice_generate_gpt_sovits'] ?? null;
        hub_test_assert(is_array($clusterService), 'Cluster must publish GPT-SoVITS');
        $operations = array_column((array)($clusterService['operations'] ?? []), null, 'operation');
        hub_test_assert(
            ($operations['synthesize']['modes'] ?? null) === ['clone', 'ultimate_clone'],
            'Cluster GPT-SoVITS contract must retain clone-only synthesis'
        );
        foreach ((array)($clusterService['workflow_examples'] ?? []) as $example) {
            hub_test_assert(
                str_contains((string)$example, 'mode=voice_generate_gpt_sovits')
                && !str_contains((string)$example, 'mode=design'),
                'Cluster GPT-SoVITS examples must not inherit Vox design mode'
            );
        }
        hub_test_assert(
            ($clusterService['result_artifact_fields'] ?? null) === ['id', 'type', 'mime_type', 'size_bytes', 'sha256']
            && hub_cluster_router_rich_artifact_mode('voice_generate_gpt_sovits'),
            'Cluster GPT-SoVITS must retain rich audio artifacts and ACK support'
        );

        $customer = hub_test_cluster_router_customer_token($db, ['voice_generate_gpt_sovits']);
        $memberId = (int)$db->query('SELECT member_id FROM api_tokens WHERE id = ' . (int)$customer['token_id'])->fetchColumn();
        $routeId = hub_cluster_router_admit_route($db, $station, [
            'member_id' => $memberId,
            'token_id' => (int)$customer['token_id'],
        ], 'voice_generate_gpt_sovits', true, true, 'profile_prepare');
        hub_test_assert(is_string($routeId), 'GPT-SoVITS profile route must be admitted');
        hub_cluster_rewrite_async_response($db, [
            'route_id' => $routeId,
            'station_id' => (int)$station['id'],
        ], ['ok' => true, 'task_id' => '9081'], 'cluster_api.php');
        $db->prepare("UPDATE cluster_routes SET state = 'succeeded' WHERE route_id = :route_id")
            ->execute([':route_id' => $routeId]);
        hub_test_assert(
            hub_cluster_get_voice_profile_route_for_member($db, $routeId, ['member_id' => $memberId], 'voice_generate_gpt_sovits') !== null
            && hub_cluster_get_voice_profile_route_for_member($db, $routeId, ['member_id' => $memberId], 'voice_generate') === null,
            'GPT-SoVITS profile followups must stay pinned to their own public mode'
        );
    });
});
