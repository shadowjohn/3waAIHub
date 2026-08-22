<?php
declare(strict_types=1);

function hub_test_voxcpm2_remove(string $path): void
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
            hub_test_voxcpm2_remove($path . '/' . $name);
        }
    }
    rmdir($path);
}

function hub_test_voxcpm2_job_workspace(): string
{
    $workspace = sys_get_temp_dir() . '/3waaihub_voxcpm2_job_' . bin2hex(random_bytes(8));
    if (!mkdir($workspace . '/input', 0700, true) || !mkdir($workspace . '/output', 0700, true) || !mkdir($workspace . '/checkpoints', 0700, true)) {
        throw new RuntimeException('Cannot create VoxCPM2 job workspace.');
    }

    return $workspace;
}

$hubPlaygroundVoiceProfiles = HUB_ROOT . '/admin/_playground_voice_profiles.php';
if (is_file($hubPlaygroundVoiceProfiles)) {
    require_once $hubPlaygroundVoiceProfiles;
}
$hubPlaygroundTtsArtifacts = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
if (is_file($hubPlaygroundTtsArtifacts)) {
    require_once $hubPlaygroundTtsArtifacts;
}

hub_test('Voice profile transcript validation normalizes UTF-8 text and reports CER', function (): void {
    $clean = hub_voice_transcript_validation(
        '今天天氣很好，我想出去走走。',
        "今天天氣很好\n我想出去走走"
    );
    hub_test_assert(
        ($clean['transcript']['normalized'] ?? '') === '今天天氣很好我想出去走走'
        && ($clean['expected_text']['normalized'] ?? '') === '今天天氣很好我想出去走走'
        && ($clean['validation']['cer'] ?? null) === 0.0
        && ($clean['validation']['status'] ?? '') === 'clean'
        && ($clean['validation']['needs_confirmation'] ?? true) === false,
        'matching Chinese transcript must normalize punctuation/newlines and be clean'
    );

    $variant = hub_voice_transcript_validation('台湾今天很好', '臺灣今天很好');
    hub_test_assert(($variant['validation']['cer'] ?? 1.0) === 0.0, 's2twp transcript validation must compare Taiwan variants consistently');

    $pass = hub_voice_transcript_validation('一二三四五六七八九十一二三四五六七八九十', '一二三四五六七八九零一二三四五六七八九十');
    hub_test_assert((float)($pass['validation']['cer'] ?? 1.0) <= 0.05 && ($pass['validation']['status'] ?? '') === 'pass' && !($pass['validation']['needs_confirmation'] ?? true), 'small CER must pass without a confirmation flag');

    $review = hub_voice_transcript_validation('一二三四五六七八九十', '一二三四五六七八九零');
    hub_test_assert(($review['validation']['status'] ?? '') === 'review_required' && ($review['validation']['needs_confirmation'] ?? false), 'large CER must require review');

    $unverified = hub_voice_transcript_validation(null, 'draft only');
    hub_test_assert(array_key_exists('cer', $unverified['validation']) && $unverified['validation']['cer'] === null && ($unverified['validation']['status'] ?? '') === 'unverified' && ($unverified['validation']['needs_confirmation'] ?? false), 'missing expected text must remain unverified');
});

hub_test('Managed voice presets are owner-scoped and disclose only catalog metadata', function (): void {
    if (!function_exists('hub_voice_preset_upsert')) {
        hub_test_assert(false, 'managed voice preset API is missing');
        return;
    }
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $owner = hub_create_api_member($db, 'Preset Owner');
        $other = hub_create_api_member($db, 'Preset Other');
        $paths = [];
        $createProfileTask = static function (int $memberId, string $name, bool $confirmed) use ($db, &$paths): int {
            $path = hub_voice_profile_storage_dir() . '/managed-preset-' . bin2hex(random_bytes(6)) . '.wav';
            file_put_contents($path, 'RIFF' . $name);
            $paths[] = $path;
            $profileId = hub_create_voice_profile($db, $memberId, [
                'name' => $name,
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
                'prompt_text' => $confirmed ? '已確認的情境台詞' : null,
            ]);
            if ($confirmed) {
                hub_confirm_voice_profile_prompt($db, $profileId, $memberId, '已確認的情境台詞');
            }
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.91', [
                'owner_member_id' => $memberId,
                'requested_mode' => 'voice_generate',
            ]);
            $db->prepare("UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id")
                ->execute([':task_id' => $taskId, ':id' => $profileId]);
            $db->prepare("UPDATE tasks SET status = 'success', progress = 100, finished_at = :now, updated_at = :now WHERE id = :id")
                ->execute([':now' => hub_now(), ':id' => $taskId]);

            return $taskId;
        };
        try {
            $baseTaskId = $createProfileTask($owner, 'Preset base voice', false);
            $anchorTaskId = $createProfileTask($owner, 'Preset nervous voice', true);
            $baseProfile = hub_voice_preset_profile_for_task($db, $owner, (string)$baseTaskId);
            hub_test_assert($baseProfile !== null, 'preset base profile task must be active and successful before binding');
            $created = hub_voice_preset_upsert($db, ['member_id' => $owner], [
                'voice_preset' => 'azhe',
                'label' => '阿哲',
                'gender' => 'male',
                'age_bucket' => 'adult',
                'purposes' => ['scene_preview'],
                'scenes' => ['nervous', 'calm'],
                'voice_profile_task_id' => (string)$baseTaskId,
            ]);
            $anchored = hub_voice_preset_anchor_upsert($db, ['member_id' => $owner], [
                'voice_preset' => 'azhe',
                'scene' => 'nervous',
                'voice_profile_task_id' => (string)$anchorTaskId,
            ]);
            $catalog = hub_voice_preset_list($db, ['member_id' => $owner]);
            $otherCatalog = hub_voice_preset_list($db, ['member_id' => $other]);
            $server = $_SERVER;
            $get = $_GET;
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_GET = ['mode' => 'voice_generate', 'operation' => 'voice_presets'];
            try {
                $directCatalog = hub_voice_profile_api_dispatch($db, [], ['member_id' => $owner]);
            } finally {
                $_SERVER = $server;
                $_GET = $get;
            }
            $directPayload = json_decode((string)($directCatalog['body'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
            $json = json_encode([$created, $anchored, $catalog, $otherCatalog, $directPayload], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            hub_test_assert(
                ($created['preset']['id'] ?? null) === 'azhe'
                && ($anchored['preset']['preset_revision'] ?? null) === 2
                && ($catalog['voice_presets'] ?? null) === [[
                    'id' => 'azhe',
                    'label' => '阿哲',
                    'gender' => 'male',
                    'age_bucket' => 'adult',
                    'purposes' => ['scene_preview'],
                    'scenes' => ['nervous', 'calm'],
                    'preset_revision' => 2,
                ]]
                && ($otherCatalog['voice_presets'] ?? null) === []
                && $directPayload === $catalog,
                'managed voice preset catalog must be owner-scoped and have its fixed safe shape'
            );
            $deleted = hub_voice_preset_delete($db, ['member_id' => $owner], ['voice_preset' => 'azhe']);
            hub_test_assert(
                $deleted === ['ok' => true, 'voice_preset' => 'azhe', 'status' => 'deleted']
                && hub_voice_preset_list($db, ['member_id' => $owner]) === ['ok' => true, 'voice_presets' => []],
                'managed preset owners must be able to retire their catalog entry'
            );
            foreach (['reference_audio_path', 'voice_profile_id', 'VoxCPM2', '/data/'] as $private) {
                hub_test_assert(!str_contains($json, $private), 'managed preset catalog must not disclose ' . $private);
            }
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('Managed voice preset synthesis seals strategy, fallback, and candidate seeds', function (): void {
    if (!function_exists('hub_voice_preset_api_synthesize')) {
        hub_test_assert(false, 'managed voice preset synthesis API is missing');
        return;
    }
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Preset Synthesis Owner');
        $token = hub_create_api_token($db, $owner, 'preset synthesis token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        $paths = [];
        $createProfileTask = static function (string $name, bool $confirmed) use ($db, $owner, &$paths): int {
            $path = hub_voice_profile_storage_dir() . '/preset-synthesis-' . bin2hex(random_bytes(6)) . '.wav';
            file_put_contents($path, 'RIFF' . $name);
            $paths[] = $path;
            $profileId = hub_create_voice_profile($db, $owner, [
                'name' => $name,
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
                'prompt_text' => $confirmed ? '緊張情境樣本' : null,
            ]);
            if ($confirmed) {
                hub_confirm_voice_profile_prompt($db, $profileId, $owner, '緊張情境樣本');
            }
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.92', [
                'owner_member_id' => $owner,
                'requested_mode' => 'voice_generate',
            ]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')
                ->execute([':task_id' => $taskId, ':id' => $profileId]);
            $db->prepare("UPDATE tasks SET status = 'success', progress = 100, finished_at = :now, updated_at = :now WHERE id = :id")
                ->execute([':now' => hub_now(), ':id' => $taskId]);

            return $taskId;
        };
        try {
            $baseTaskId = $createProfileTask('Preset base', false);
            $anchorTaskId = $createProfileTask('Preset nervous', true);
            hub_voice_preset_upsert($db, ['member_id' => $owner], [
                'voice_preset' => 'azhe',
                'label' => '阿哲',
                'gender' => 'male',
                'age_bucket' => 'adult',
                'purposes' => ['scene_preview'],
                'scenes' => ['nervous', 'calm'],
                'voice_profile_task_id' => (string)$baseTaskId,
            ]);
            hub_voice_preset_anchor_upsert($db, ['member_id' => $owner], [
                'voice_preset' => 'azhe',
                'scene' => 'nervous',
                'voice_profile_task_id' => (string)$anchorTaskId,
            ]);
            $route = hub_resolve_audio_async_route($db, 'voice_generate');
            $auth = ['member_id' => $owner, 'token_id' => (int)$token['token_id']];
            $request = [
                'voice_preset' => 'azhe',
                'purpose' => 'scene_preview',
                'scene' => 'nervous',
                'candidate_count' => 3,
                'seed' => 77,
                'text' => '等一下，我再確認一次……',
            ];
            $accepted = hub_voice_preset_api_synthesize($db, $route, $auth, $request);
            $task = hub_get_task($db, (int)($accepted['task_id'] ?? 0));
            $fallback = hub_voice_preset_api_synthesize($db, $route, $auth, array_replace($request, ['scene' => 'calm']));
            $fallbackTask = hub_get_task($db, (int)($fallback['task_id'] ?? 0));
            $rejected = null;
            try {
                hub_voice_preset_api_synthesize($db, $route, $auth, $request + ['voice_prompt' => 'must not be accepted']);
            } catch (InvalidArgumentException $error) {
                $rejected = $error->getMessage();
            }

            hub_test_assert(
                ($accepted['status'] ?? null) === 'queued'
                && ($task['task_type'] ?? null) === 'pack_job'
                && ($task['input']['text'] ?? null) === '等一下，我再確認一次……'
                && ($task['input']['mode'] ?? null) === 'ultimate_clone'
                && !array_key_exists('voice_prompt', (array)($task['input'] ?? []))
                && !array_key_exists('control', (array)($task['input'] ?? []))
                && ($task['input']['voice_preset_batch'] ?? null) === [
                    'preset_id' => 'azhe',
                    'preset_revision' => 2,
                    'candidates' => [
                        ['candidate_id' => 'candidate-01', 'seed' => 77],
                        ['candidate_id' => 'candidate-02', 'seed' => $task['input']['voice_preset_batch']['candidates'][1]['seed'] ?? null],
                        ['candidate_id' => 'candidate-03', 'seed' => $task['input']['voice_preset_batch']['candidates'][2]['seed'] ?? null],
                    ],
                ]
                && ($fallbackTask['input']['mode'] ?? null) === 'clone'
                && $rejected === 'voice_preset_forbidden_input',
                'preset synthesis must lock model strategy internally, retain exact spoken text, and use a bounded seeded batch'
            );
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('Managed voice preset batches expose only fixed candidate artifact URLs', function (): void {
    if (!function_exists('hub_voice_preset_candidate_artifact_definitions')) {
        hub_test_assert(false, 'managed voice preset candidate artifact definitions are missing');
        return;
    }
    $definitions = hub_voice_preset_candidate_artifact_definitions([
        'voice_preset_batch' => [
            'preset_id' => 'azhe',
            'preset_revision' => 2,
            'candidates' => [
                ['candidate_id' => 'candidate-01', 'seed' => 101],
                ['candidate_id' => 'candidate-02', 'seed' => 202],
                ['candidate_id' => 'candidate-03', 'seed' => 303],
            ],
        ],
    ], ['type' => 'generated_audio', 'path' => 'generated_audio.wav', 'mime_types' => ['audio/wav'], 'max_bytes' => 1024]);
    $public = hub_task_result_publicize_value(['audio_artifact_id' => 42], [42 => 'https://hub.example/api.php?mode=artifact&artifact_id=42']);

    hub_test_assert(
        array_column($definitions, 'path') === ['candidate-02.wav', 'candidate-03.wav']
        && ($public['audio_url'] ?? null) === 'https://hub.example/api.php?mode=artifact&artifact_id=42'
        && !array_key_exists('path', $public),
        'preset candidates must use only fixed output filenames and authenticated artifact URLs'
    );
});

hub_test('Generic voice exploration keeps preferences private and queues design candidates', function (): void {
    if (!function_exists('hub_voice_generic_api_synthesize')) {
        hub_test_assert(false, 'generic voice exploration API is missing');
        return;
    }
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Generic Voice Owner');
        $token = hub_create_api_token($db, $owner, 'generic voice token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        $route = hub_resolve_audio_async_route($db, 'voice_generate');
        $auth = ['member_id' => $owner, 'token_id' => (int)$token['token_id']];
        $request = [
            'text' => 'generic-stage-visibility',
            'gender' => 'female',
            'age_bucket' => 'young_adult',
            'role_note' => '活潑有節奏的活動主持人，聲音明亮而有感染力。',
            'candidate_count' => 3,
        ];
        $db->exec("CREATE TRIGGER generic_voice_must_stage
            BEFORE INSERT ON tasks
            WHEN NEW.task_type = 'pack_job'
              AND NEW.input_json LIKE '%generic-stage-visibility%'
              AND NEW.status <> 'staging'
            BEGIN
                SELECT RAISE(ABORT, 'generic_voice_task_must_stage');
            END");
        $db->exec("CREATE TRIGGER generic_voice_recipe_before_publish
            BEFORE UPDATE OF status ON tasks
            WHEN OLD.task_type = 'pack_job'
              AND OLD.status = 'staging' AND NEW.status = 'queued'
              AND OLD.input_json LIKE '%generic-stage-visibility%'
              AND (instr(OLD.input_json, '\"generic_voice_batch\"') = 0
                   OR instr(OLD.input_json, '\"voice_design_revision\":1') = 0)
            BEGIN
                SELECT RAISE(ABORT, 'generic_voice_recipe_before_publish');
            END");
        $accepted = hub_voice_generic_api_synthesize($db, $route, $auth, $request);
        $task = hub_get_task($db, (int)($accepted['task_id'] ?? 0));
        $taskInput = (array)($task['input'] ?? []);
        $recipe = $taskInput['generic_voice_batch'] ?? null;
        $contract = json_decode((string)($task['job_contract_json'] ?? ''), true, 32, JSON_THROW_ON_ERROR);
        $workspace = hub_pack_job_prepare_workspace($db, $task ?? [], $contract);
        $runnerRequest = json_decode((string)file_get_contents($workspace . '/input/request.json'), true, 32, JSON_THROW_ON_ERROR);
        $runnerJson = json_encode($runnerRequest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $errors = [];
        foreach ([
            $request + ['voice_prompt' => 'must not be accepted'],
            $request + ['control' => 'must not be accepted'],
            $request + ['mode' => 'clone'],
            $request + ['model' => 'voxcpm2'],
            $request + ['voice_profile_id' => 1],
            $request + ['seed' => 1],
            $request + ['unexpected' => true],
            array_replace($request, ['age_bucket' => 'adult']),
            array_replace($request, ['candidate_count' => 0]),
            array_replace($request, ['candidate_count' => 4]),
        ] as $invalid) {
            try {
                hub_voice_generic_api_synthesize($db, $route, $auth, $invalid);
            } catch (InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }

        hub_test_assert(
            ($accepted['status'] ?? null) === 'queued'
            && ($task['task_type'] ?? null) === 'pack_job'
            && ($taskInput['text'] ?? null) === $request['text']
            && ($taskInput['mode'] ?? null) === 'design'
            && !array_key_exists('voice_profile_id', $taskInput)
            && !array_key_exists('voice_context', $taskInput)
            && $recipe === [
                'gender' => 'female',
                'age_bucket' => 'young_adult',
                'role_note' => $request['role_note'],
                'voice_design_revision' => 1,
                'style_status' => 'unverified',
                'candidates' => [
                    ['candidate_id' => 'candidate-01', 'seed' => $recipe['candidates'][0]['seed'] ?? null],
                    ['candidate_id' => 'candidate-02', 'seed' => $recipe['candidates'][1]['seed'] ?? null],
                    ['candidate_id' => 'candidate-03', 'seed' => $recipe['candidates'][2]['seed'] ?? null],
                ],
            ]
            && array_key_exists('preset_candidates', $runnerRequest)
            && !array_key_exists('gender', $runnerRequest)
            && !array_key_exists('age_bucket', $runnerRequest)
            && !array_key_exists('role_note', $runnerRequest)
            && !str_contains($runnerJson, $request['role_note'])
            && $errors === [
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_forbidden_input',
                'generic_voice_invalid',
                'generic_voice_candidate_count_invalid',
                'generic_voice_candidate_count_invalid',
            ],
            'generic exploration must queue only design input and retain its private preference recipe'
        );
    });
});

hub_test('Generic voice exploration validates fixed artifacts and candidate results', function (): void {
    if (!function_exists('hub_voice_generic_candidate_artifact_definitions')
        || !function_exists('hub_voice_generic_batch_task_result')
        || !function_exists('hub_voice_generic_batch_result_candidates')) {
        hub_test_assert(false, 'generic voice exploration result helpers are missing');
        return;
    }
    $db = hub_test_reset_db();
    $taskInput = [
        'generic_voice_batch' => [
            'gender' => 'male',
            'age_bucket' => 'mature',
            'role_note' => '沉穩可靠的企業旁白。',
            'voice_design_revision' => 1,
            'style_status' => 'unverified',
            'candidates' => [
                ['candidate_id' => 'candidate-01', 'seed' => 101],
                ['candidate_id' => 'candidate-02', 'seed' => 202],
                ['candidate_id' => 'candidate-03', 'seed' => 303],
            ],
        ],
    ];
    $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, $taskInput, null, '203.0.113.93');
    $registered = [
        ['name' => 'generated_audio.wav', 'id' => 11],
        ['name' => 'candidate-02.wav', 'id' => 12],
        ['name' => 'candidate-03.wav', 'id' => 13],
    ];
    $result = hub_voice_generic_batch_task_result($db, $taskId, $registered);
    $definitions = hub_voice_generic_candidate_artifact_definitions($taskInput, [
        'type' => 'generated_audio',
        'path' => 'generated_audio.wav',
        'mime_types' => ['audio/wav'],
        'max_bytes' => 1024,
    ]);
    $candidates = $result['candidates'] ?? null;
    $malformedId = is_array($candidates) ? $candidates : [];
    $malformedRevision = is_array($candidates) ? $candidates : [];
    $malformedStatus = is_array($candidates) ? $candidates : [];
    $malformedId[1]['candidate_id'] = 'candidate-99';
    $malformedRevision[1]['voice_design_revision'] = 2;
    $malformedStatus[1]['style_status'] = 'applied';
    $public = hub_task_result_publicize_value($result, [
        11 => 'https://hub.example/api.php?mode=artifact&artifact_id=11',
        12 => 'https://hub.example/api.php?mode=artifact&artifact_id=12',
        13 => 'https://hub.example/api.php?mode=artifact&artifact_id=13',
    ]);

    hub_test_assert(
        array_column($definitions, 'path') === ['candidate-02.wav', 'candidate-03.wav']
        && is_array($candidates)
        && array_keys($candidates[0]) === ['candidate_id', 'audio_artifact_id', 'seed', 'voice_design_revision', 'style_status']
        && hub_voice_generic_batch_result_candidates($taskInput, $result, [11, 12, 13]) === $candidates
        && hub_voice_generic_batch_result_candidates($taskInput, ['candidates' => $malformedId], [11, 12, 13]) === null
        && hub_voice_generic_batch_result_candidates($taskInput, ['candidates' => $malformedRevision], [11, 12, 13]) === null
        && hub_voice_generic_batch_result_candidates($taskInput, ['candidates' => $malformedStatus], [11, 12, 13]) === null
        && hub_voice_generic_batch_result_candidates($taskInput, $result, [11, 12]) === null
        && ($public['candidates'][2]['audio_url'] ?? null) === 'https://hub.example/api.php?mode=artifact&artifact_id=13'
        && !str_contains((string)json_encode($public, JSON_UNESCAPED_UNICODE), '沉穩可靠'),
        'generic exploration candidates must use fixed WAV artifacts and safe immutable recipe metadata'
    );
});

hub_test('VoxCPM2 profile_prepare stores raw Whisper text and validation without confirming', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Transcript validation owner');
        $path = hub_voice_profile_storage_dir() . '/transcript-validation.wav';
        file_put_contents($path, 'RIFFvalidation');
        try {
            $profileId = hub_create_voice_profile($db, $memberId, [
                'name' => 'Transcript validation profile',
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
                'transcript_validation_json' => hub_voice_profile_validation_json('台湾今天很好', null),
            ]);
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '127.0.0.1', ['owner_member_id' => $memberId]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([':task_id' => $taskId, ':id' => $profileId]);
            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $claimed ?? [], static fn (): array => [
                'ok' => true,
                'text' => '臺灣今天很好',
                'language' => 'zh',
                'device' => ['effective' => 'cpu'],
            ]);
            $profile = hub_get_voice_profile($db, $profileId);
            $validation = json_decode((string)($profile['transcript_validation_json'] ?? ''), true);
            $status = hub_voice_profile_task_status_payload($db, hub_get_task($db, $taskId) ?? [], $profile ?? [], true);
            hub_test_assert(
                ($validation['transcript']['raw'] ?? '') === '臺灣今天很好'
                && (float)($validation['validation']['cer'] ?? -1.0) === 0.0
                && ($validation['validation']['status'] ?? '') === 'clean'
                && ($status['transcript']['raw'] ?? '') === '臺灣今天很好'
                && ($status['expected_text']['raw'] ?? '') === '台湾今天很好'
                && ($status['validation']['status'] ?? '') === 'clean'
                && ($profile['prompt_text_confirmed_at'] ?? null) === null,
                'profile_prepare must retain raw Whisper text, validate it, and leave confirmation to profile_confirm'
            );
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
});

hub_test('Edge TTS ground-truth text can feed Voice Profile validation', function (): void {
    hub_test_assert(is_file(HUB_ROOT . '/packs/edge-tts/pack.json'), 'Edge TTS fixture Pack must remain available');
    $groundTruth = '今天台灣的天氣很好，我們一起出發。';
    $validation = hub_voice_transcript_validation($groundTruth, "今天台灣的天氣很好\n我們一起出發");
    hub_test_assert(($validation['validation']['status'] ?? '') === 'clean' && (float)($validation['validation']['cer'] ?? -1) === 0.0, 'Edge TTS ground truth must validate after subtitle formatting differences');
});

hub_test('VoxCPM2 profile_prepare accepts optional expected_text without putting it in task input', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Expected text owner');
        $token = hub_create_api_token($db, $memberId, 'expected text token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-expected-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create expected text WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-expected';
            $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Expected text profile',
                'consent_type' => 'self_recorded',
                'expected_text' => '台湾今天很好',
            ], [], ['reference_wav' => [
                'name' => 'expected.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]]);
            $taskId = (int)(hub_test_audio_payload($response)['task_id'] ?? 0);
            $task = hub_get_task($db, $taskId);
            $profile = hub_get_voice_profile($db, (int)($task['input']['voice_profile_id'] ?? 0));
            $seed = json_decode((string)($profile['transcript_validation_json'] ?? ''), true);
            hub_test_assert(
                $response['status'] === 200
                && array_keys((array)($task['input'] ?? [])) === ['voice_profile_id']
                && ($seed['expected_text']['raw'] ?? '') === '台湾今天很好'
                && ($seed['validation']['status'] ?? '') === 'unverified'
                && ($profile['prompt_text_confirmed_at'] ?? null) === null,
                'expected_text must be stored for validation while the queued task stays path-free and unconfirmed'
            );
        } finally {
            if (is_file($tmpName)) {
                unlink($tmpName);
            }
        }
    });
});

hub_test('VoxCPM2 experimental TTS pack manifest and service files exist', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'tts-voxcpm2 pack must be valid');
    $manifest = $pack['manifest'];

    hub_test_assert(($manifest['version'] ?? '') === '0.1.8', 'VoxCPM2 Pack version mismatch');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'tts', 'VoxCPM2 default mode mismatch');
    hub_test_assert(($manifest['capability'] ?? '') === 'text_to_speech', 'VoxCPM2 capability mismatch');
    hub_test_assert(($manifest['model'] ?? '') === 'openbmb/VoxCPM2', 'VoxCPM2 model id mismatch');
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L5-benchmark-ready', 'VoxCPM2 runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'VoxCPM2 target level mismatch');
    hub_test_assert(!empty($manifest['experimental']), 'VoxCPM2 must be experimental');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'sync_api', 'VoxCPM2 execution type mismatch');
    hub_test_assert(($manifest['gateway']['invoke_path'] ?? '') === '/v1/tts', 'VoxCPM2 gateway endpoint mismatch');
    hub_test_assert(in_array('design', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support design mode');
    hub_test_assert(in_array('clone', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support controlled clone mode');
    hub_test_assert(in_array('ultimate_clone', $manifest['tts_modes'] ?? [], true), 'VoxCPM2 must support Ultimate Clone');
    hub_test_assert(($manifest['lifecycle']['lifecycle'] ?? '') === 'on_demand', 'VoxCPM2 lifecycle mismatch');
    hub_test_assert(($manifest['lifecycle']['gpu_policy'] ?? '') === 'exclusive_gpu', 'VoxCPM2 GPU policy mismatch');
    hub_test_assert((int)($manifest['lifecycle']['idle_unload_seconds'] ?? 0) === 0, 'VoxCPM2 idle unload mismatch');
    hub_test_assert(($manifest['runner_build']['image'] ?? '') === '3waaihub/tts-voxcpm2:0.1.8', 'VoxCPM2 runner build image mismatch');
    hub_test_assert(($manifest['async_jobs'][0]['runner']['image'] ?? '') === '3waaihub/tts-voxcpm2:0.1.8', 'VoxCPM2 runner image mismatch');
    $settings = hub_get_pack_settings_schema('tts-voxcpm2');
    hub_test_assert(($settings['VOXCPM2_EXECUTION_MODE']['default'] ?? '') === 'isolated', 'VoxCPM2 execution mode must default to isolated');
    hub_test_assert(($settings['VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB']['default'] ?? '') === '1024', 'VoxCPM2 resident VRAM default mismatch');
    hub_test_assert(($settings['VOXCPM2_IDLE_UNLOAD_SECONDS']['default'] ?? '') === '0' && ($settings['VOXCPM2_IDLE_UNLOAD_SECONDS']['min'] ?? null) === 0, 'VoxCPM2 idle unload setting must allow zero');
    $contract = $manifest['l5_contract'] ?? [];
    hub_test_assert(is_array($contract) && !empty($contract['benchmark']['supported']), 'VoxCPM2 L5 benchmark must be supported');
    foreach (['success', 'mock', 'real_inference_requested', 'runtime_level', 'artifact_url', 'sample_rate', 'duration_ms', 'model', 'seed', 'elapsed_ms'] as $key) {
        hub_test_assert(in_array($key, $contract['output']['required_keys'] ?? [], true), 'VoxCPM2 contract output missing ' . $key);
    }
    $cases = $contract['benchmark']['cases'] ?? [];
    hub_test_assert(in_array('tts_mock_wav', array_column($cases, 'id'), true), 'VoxCPM2 mock benchmark case missing');
    hub_test_assert(in_array('tts_real_wav', array_column($cases, 'id'), true), 'VoxCPM2 real benchmark case missing');
    foreach ($cases as $case) {
        if (($case['id'] ?? '') === 'tts_real_wav') {
            hub_test_assert(!empty($case['real_inference']), 'VoxCPM2 real benchmark must be marked real_inference');
        }
    }

    foreach (['Dockerfile', 'requirements.txt', 'app.py', 'smoke.py', 'storage_smoke.py'] as $file) {
        hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/service/' . $file), 'VoxCPM2 service missing ' . $file);
    }
    hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json'), 'VoxCPM2 acceptance set missing');
});

hub_test('VoxCPM2 generated Compose builds from the pack root', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'tts-voxcpm2 pack must be valid');

    $compose = hub_generate_pack_compose($pack, 'tts-build-root', 18108);
    hub_test_assert(str_contains($compose, 'context: ' . $pack['dir'] . "\n"), 'VoxCPM2 build context must include service and jobs directories');
    hub_test_assert(str_contains($compose, 'dockerfile: service/Dockerfile'), 'VoxCPM2 build must retain its service Dockerfile');
});

hub_test('VoxCPM2 allows enough time for a first image build', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', ['service_key' => 'tts-build-timeout']);

    hub_test_assert(hub_service_build_timeout_sec($installed['service']) === 1800, 'VoxCPM2 first build must allow time for its CUDA dependencies and image export');
});

hub_test('VoxCPM2 service app exposes TTS voice-design and managed clone modes', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    foreach (['@app.get("/health")', '@app.get("/v1/models")', '@app.post("/v1/voice-design")', '@app.post("/v1/tts")'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'VoxCPM2 app missing ' . $needle);
    }
    foreach (['/clone', 'WebSocket'] as $needle) {
        hub_test_assert(!str_contains($app, $needle), 'VoxCPM2 app must not expose separate clone/streaming surface: ' . $needle);
    }
    foreach (['split_text', 'seed', 'artifact_url', 'sample_rate', 'duration_ms', 'manifest', 'reference_wav_path', 'prompt_wav_path', 'prompt_text', 'clone', 'ultimate_clone'] as $needle) {
        hub_test_assert(str_contains($app, $needle), 'VoxCPM2 app missing TTS behavior ' . $needle);
    }
    hub_test_assert(str_contains($app, 'return "L5-benchmark-ready"'), 'VoxCPM2 app must expose L5 runtime level');
});

hub_test('VoxCPM2 real inference requests cannot silently return mock audio', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    $requirements = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/requirements.txt');
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile');

    hub_test_assert(str_contains($app, 'real_inference:'), 'TTS request schema must accept real_inference');
    hub_test_assert(str_contains($app, 'request.real_inference'), 'real_inference request flag must control mock fallback');
    hub_test_assert(str_contains($app, 'real_inference_requested'), 'manifest must record whether real inference was requested');
    hub_test_assert(str_contains($app, 'set_runtime_seed(seed)'), 'VoxCPM2 seed must be applied before generation');
    hub_test_assert(preg_match('/kwargs: dict\\[str, Any\\] = \\{(?P<kwargs>.*?)\\n    \\}/s', $app, $match) === 1, 'VoxCPM2 generate kwargs block must be present');
    hub_test_assert(!str_contains($match['kwargs'], '"seed": seed'), 'VoxCPM2 generate kwargs must not pass unsupported seed argument');
    hub_test_assert(str_contains($requirements, 'voxcpm==2.0.3'), 'VoxCPM2 runtime package must be pinned');
    hub_test_assert(str_contains($requirements, 'soundfile'), 'VoxCPM2 runtime must include soundfile');
    hub_test_assert(str_contains($dockerfile, 'libsndfile1'), 'VoxCPM2 image must include libsndfile runtime dependency');
    hub_test_assert(str_contains($dockerfile, 'gcc'), 'VoxCPM2 image must include a C compiler for Triton warmup');
});

hub_test('VoxCPM2 defaults to no torch compile warmup on shared 16 GB GPUs', function (): void {
    $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert($pack !== null && $pack['status'] === 'ok', 'tts-voxcpm2 pack must be valid');

    hub_test_assert(str_contains($app, 'VOXCPM2_TORCH_COMPILE'), 'VoxCPM2 app must make torch compile opt-in');
    hub_test_assert(str_contains($app, 'optimize=env_enabled(os.getenv("VOXCPM2_TORCH_COMPILE"))'), 'VoxCPM2 must pass the opt-in compile setting to the model loader');
    $settings = array_column($pack['manifest']['settings_schema'] ?? [], null, 'key');
    hub_test_assert(($settings['VOXCPM2_TORCH_COMPILE']['default'] ?? null) === '0', 'VoxCPM2 torch compile must be disabled by default');
    hub_test_assert(($settings['VOXCPM2_TORCH_COMPILE']['restart_required'] ?? null) === true, 'VoxCPM2 torch compile setting must require restart');
});

hub_test('VoxCPM2 native profile_prepare queues only a managed profile handle', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Native Profile Prepare Owner');
        $token = hub_create_api_token($db, $memberId, 'native profile prepare token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        hub_register_callback_target($db, $memberId, 'profile-events', 'https://8.8.8.8/voice-profile');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-api-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile_prepare WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $upload = [
            'name' => 'reference.wav',
            'type' => 'audio/wav',
            'tmp_name' => $tmpName,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpName),
        ];

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-test';
            $expiresAfter = time() + 3600;
            $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Task 2 profile',
                'consent_type' => 'self_recorded',
                'prompt_text' => 'RC Valve draft',
                'transcript_confirmed' => 'true',
                'language' => 'en',
                'callback_target' => 'profile-events',
                'expires_in_seconds' => '3600',
            ], [], ['reference_wav' => $upload]);
            $payload = hub_test_audio_payload($response);
            hub_test_assert($response['status'] === 200 && (int)($payload['task_id'] ?? 0) > 0, 'profile_prepare must return a standard async task handle');
            $task = hub_get_task($db, (int)$payload['task_id']);
            hub_test_assert(($task['task_type'] ?? '') === 'voice_profile_prepare' && ($task['queue_name'] ?? '') === 'default', 'profile_prepare must use the dedicated default-queue task');
            hub_test_assert(array_keys((array)($task['input'] ?? [])) === ['voice_profile_id'] && (int)$task['input']['voice_profile_id'] > 0, 'profile_prepare task input must contain only the managed profile ID');
            $profile = hub_get_voice_profile($db, (int)$task['input']['voice_profile_id']);
            hub_test_assert($profile !== null && (int)($profile['source_task_id'] ?? 0) === (int)$task['id'], 'profile_prepare must atomically link the managed profile to its task');
            hub_test_assert((string)($profile['prompt_text_confirmed_at'] ?? '') !== '', 'confirmed supplied text must be confirmed before the task handle is exposed');
            hub_test_assert(
                strtotime((string)($profile['expires_at'] ?? '')) >= $expiresAfter
                && strtotime((string)($profile['expires_at'] ?? '')) <= time() + 3600,
                'profile_prepare must persist the exact bounded expiry deadline'
            );

            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_test_assert($claimed !== null && (int)$claimed['id'] === (int)$task['id'], 'profile_prepare worker must claim the queued task');
            hub_run_voice_profile_prepare_task($db, $claimed);
            $finished = hub_get_task($db, (int)$task['id']);
            $expectedResult = [
                'kind' => 'voice_profile_prepare',
                'transcription_status' => 'ready',
                'transcript_confirmed' => true,
                'text_chars' => strlen('RC Valve draft'),
                'prompt_text_sha256' => hash('sha256', 'RC Valve draft'),
            ];
            hub_test_assert(($finished['status'] ?? '') === 'success' && ($finished['result'] ?? null) === $expectedResult, 'profile_prepare worker result must have the exact safe shape');

            $logs = $db->query('SELECT message FROM task_logs WHERE task_id = ' . (int)$task['id'])->fetchAll(PDO::FETCH_COLUMN);
            $audits = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$profile['id'])->fetchAll(PDO::FETCH_COLUMN);
            $callbacks = $db->query('SELECT payload_json FROM task_callback_deliveries WHERE task_id = ' . (int)$task['id'])->fetchAll(PDO::FETCH_COLUMN);
            $stored = json_encode([$task['input'], $finished['result'], $logs, $audits, $callbacks], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            hub_test_assert(is_string($stored) && !str_contains($stored, 'RC Valve draft') && !str_contains($stored, (string)$profile['reference_audio_path']), 'task input/result/log/audit/callback must contain neither transcript nor host path');
        } finally {
            if (is_file($tmpName)) {
                unlink($tmpName);
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare rolls back profile audio task and writer lock together', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Transaction Owner');
        $token = hub_create_api_token($db, $memberId, 'profile transaction token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-transaction-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile transaction WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $finalPattern = hub_voice_profile_storage_dir() . '/voice_profile_' . $memberId . '_*.wav';
        $db->exec("CREATE TRIGGER voice_profile_task_publish_failure
            BEFORE UPDATE OF status ON tasks
            WHEN OLD.task_type = 'voice_profile_prepare'
              AND OLD.status = 'staging' AND NEW.status = 'queued'
            BEGIN
                SELECT RAISE(ABORT, 'voice_profile_task_publish_failed');
            END");

        $response = null;
        $profileCount = -1;
        $taskCount = -1;
        $finalFiles = [];
        $secondWriterSucceeded = false;
        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-transaction';
            $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Atomic profile',
                'consent_type' => 'self_recorded',
                'prompt_text' => 'atomic confirmed prompt',
                'transcript_confirmed' => '1',
                'language' => 'en',
            ], [], ['reference_wav' => [
                'name' => 'atomic.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]]);
            $profileCount = (int)$db->query('SELECT COUNT(*) FROM voice_profiles WHERE owner_member_id = ' . $memberId)->fetchColumn();
            $taskCount = (int)$db->query("SELECT COUNT(*) FROM tasks WHERE task_type = 'voice_profile_prepare'")->fetchColumn();
            $finalFiles = glob($finalPattern) ?: [];

            $otherDb = new PDO('sqlite:' . HUB_DB_PATH);
            $otherDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $otherDb->exec('PRAGMA busy_timeout = 50');
            $otherDb->exec('BEGIN IMMEDIATE');
            $otherDb->exec('ROLLBACK');
            $secondWriterSucceeded = true;
        } catch (Throwable) {
            $secondWriterSucceeded = false;
        } finally {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            $db->exec('DROP TRIGGER IF EXISTS voice_profile_task_publish_failure');
            @unlink($tmpName);
            foreach (glob($finalPattern) ?: [] as $path) {
                @unlink($path);
            }
        }

        hub_test_assert(is_array($response) && $response['status'] === 500, 'injected task publish failure must return bounded profile_prepare failure');
        hub_test_assert($profileCount === 0 && $taskCount === 0, 'failed native prepare must leave neither profile nor task rows');
        hub_test_assert($finalFiles === [], 'failed native prepare must remove its finalized managed WAV');
        hub_test_assert($secondWriterSucceeded, 'failed native prepare must release the SQLite writer lock');
    });
});

hub_test('VoxCPM2 late cache hit reuses the upload transaction without a nested BEGIN', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Late Cache Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-late-cache-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create late cache WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $racePath = hub_voice_profile_storage_dir() . '/late_cache_profile.wav';
    $profileId = 0;
    $error = null;
    $result = null;

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK],
            [
                'name' => 'Late cache request',
                'consent_type' => 'self_recorded',
                'prompt_text' => 'current late draft',
                'language' => 'zh-TW',
            ],
            static function (string $from, string $to) use ($db, $memberId, $racePath, &$profileId): bool {
                if (!copy($from, $to) || !copy($from, $racePath)) {
                    return false;
                }
                $profileId = hub_create_voice_profile($db, $memberId, [
                    'name' => 'Late cache winner',
                    'reference_audio_path' => $racePath,
                    'consent_type' => 'self_recorded',
                    'prompt_text' => 'old late draft',
                    'language' => 'en',
                    'transcription_status' => 'ready',
                ]);
                return true;
            },
            null,
            ['defer_transcription' => true, 'allow_cache' => true]
        );
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        @unlink($tmpName);
    }

    $profile = $profileId > 0 ? hub_get_voice_profile($db, $profileId) : null;
    try {
        hub_test_assert($error === null, 'late cache hit must not attempt a nested BEGIN IMMEDIATE');
        hub_test_assert(
            is_array($result)
            && array_keys($result) === ['profile', 'cache_hit']
            && !empty($result['cache_hit'])
            && (int)($result['profile']['id'] ?? 0) === $profileId
            && ($profile['prompt_text'] ?? '') === 'current late draft'
            && ($profile['language'] ?? '') === 'zh-TW',
            'late cache hit must atomically apply the current deferred draft'
        );
    } finally {
        if ($profileId > 0 && hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
        if (is_file($racePath)) {
            unlink($racePath);
        }
    }
});

hub_test('VoxCPM2 profile_status returns only the owned task-scoped safe profile view', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Status Owner');
        $token = hub_create_api_token($db, $memberId, 'profile status token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $path = hub_voice_profile_storage_dir() . '/profile_status_task.wav';
        file_put_contents($path, 'RIFFstatus');
        $profileId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Status profile',
            'reference_audio_path' => $path,
            'prompt_text' => 'owner draft',
            'language' => 'en',
            'consent_type' => 'self_recorded',
        ]);
        $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.51', [
            'owner_member_id' => $memberId,
            'owner_token_id' => (int)$token['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([':task_id' => $taskId, ':id' => $profileId]);
        $db->prepare("UPDATE voice_profiles SET transcription_status = 'failed', transcription_error = :error WHERE id = :id")
            ->execute([':error' => 'ASR backend exposed a private draft failure', ':id' => $profileId]);

        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
            'operation' => 'profile_status',
            'voice_profile_task_id' => (string)$taskId,
        ]);
        $payload = hub_test_audio_payload($response);
        hub_test_assert(
            $response['status'] === 200
            && ($payload['task_status'] ?? '') === 'queued'
            && ($payload['prompt_text'] ?? '') === 'owner draft'
            && ($payload['transcription_error'] ?? null) === 'asr_failed'
            && !str_contains(json_encode($payload, JSON_THROW_ON_ERROR), 'private draft failure'),
            'owned queued preparation task must expose its draft with only a bounded transcription error code'
        );
        hub_test_assert(
            array_keys($payload) === [
                'ok',
                'task_status',
                'profile_status',
                'transcription_status',
                'transcription_error',
                'transcript_confirmed',
                'prompt_text_confirmed_at',
                'profile_name',
                'language',
                'consent_type',
                'reference_audio_sha256',
                'created_at',
                'updated_at',
                'prompt_text',
            ],
            'profile_status must return the exact approved safe payload'
        );
        foreach (['task_id', 'voice_profile_id', 'id', 'reference_audio_path', 'transcription_lease_token', 'owner_member_id', 'owner_token_id'] as $privateKey) {
            hub_test_assert(!array_key_exists($privateKey, $payload), 'profile_status must not expose ' . $privateKey);
        }
    });
});

hub_test('VoxCPM2 profile_status projects an unpruned expired Profile as a safe tombstone', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Expired Profile Status Owner');
        $token = hub_create_api_token($db, $memberId, 'expired profile status token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $path = hub_voice_profile_storage_dir() . '/expired_profile_status.wav';
        file_put_contents($path, 'RIFFexpired-status-secret');
        $profileId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Private expired profile name',
            'reference_audio_path' => $path,
            'prompt_text' => 'private expired draft',
            'language' => 'private-language',
            'consent_type' => 'self_recorded',
            'expires_at' => '2000-01-01 00:00:00',
        ]);
        $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.51', [
            'owner_member_id' => $memberId,
            'owner_token_id' => (int)$token['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        $db->prepare('UPDATE voice_profiles
                      SET source_task_id = :task_id, transcription_status = :status, transcription_error = :error
                      WHERE id = :id')
            ->execute([
                ':task_id' => $taskId,
                ':status' => 'failed',
                ':error' => 'private transcription backend detail',
                ':id' => $profileId,
            ]);
        $referenceSha256 = hash_file('sha256', $path);
        $request = static function () use ($db, $token, $taskId): array {
            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
            return hub_test_audio_payload(hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_status',
                'voice_profile_task_id' => (string)$taskId,
            ]));
        };
        $assertTombstone = static function (array $payload) use ($referenceSha256): void {
            $json = json_encode($payload, JSON_THROW_ON_ERROR);
            hub_test_assert(
                ($payload['profile_status'] ?? '') === 'expired'
                && ($payload['transcription_status'] ?? '') === 'failed'
                && array_key_exists('transcription_error', $payload)
                && $payload['transcription_error'] === null
                && ($payload['transcript_confirmed'] ?? null) === false
                && array_key_exists('prompt_text_confirmed_at', $payload)
                && $payload['prompt_text_confirmed_at'] === null
                && ($payload['profile_name'] ?? '') === 'Expired voice profile'
                && array_key_exists('language', $payload)
                && $payload['language'] === null
                && ($payload['reference_audio_sha256'] ?? null) === ''
                && !array_key_exists('prompt_text', $payload)
                && !str_contains($json, 'Private expired profile name')
                && !str_contains($json, 'private expired draft')
                && !str_contains($json, 'private-language')
                && !str_contains($json, $referenceSha256)
                && !str_contains($json, 'asr_failed'),
                'expired profile_status must return only the safe tombstone projection'
            );
        };

        $assertTombstone($request());
        $db->prepare('UPDATE voice_profiles SET prompt_text_confirmed_at = :confirmed_at WHERE id = :id')
            ->execute([':confirmed_at' => '1999-12-31 23:59:59', ':id' => $profileId]);
        $assertTombstone($request());

        $stored = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        hub_test_assert(
            is_array($stored)
            && empty($stored['deleted_at'])
            && ($stored['prompt_text'] ?? '') === 'private expired draft'
            && ($stored['name'] ?? '') === 'Private expired profile name'
            && ($stored['prompt_text_confirmed_at'] ?? '') === '1999-12-31 23:59:59',
            'profile_status must not prune or mutate the expired Profile row'
        );
    });
});

hub_test('VoxCPM2 voice profile public helpers keep their approved signatures', function (): void {
    $upload = new ReflectionFunction('hub_create_uploaded_voice_profile');
    $uploadParameters = $upload->getParameters();
    hub_test_assert(
        array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $uploadParameters)
            === ['db', 'ownerMemberId', 'upload', 'input', 'moveFile', 'transcribe', 'options']
        && $uploadParameters[6]->isDefaultValueAvailable()
        && $uploadParameters[6]->getDefaultValue() === [],
        'uploaded voice profile helper must end with the approved options parameter'
    );

    $cachedDraft = new ReflectionFunction('hub_apply_cached_voice_profile_draft');
    hub_test_assert(
        array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $cachedDraft->getParameters())
            === ['db', 'profile', 'ownerMemberId', 'promptText', 'language', 'transcriptConfirmed'],
        'cached voice profile draft helper must not expose a transaction bypass'
    );

    $confirm = new ReflectionFunction('hub_confirm_voice_profile_prompt');
    hub_test_assert(
        array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $confirm->getParameters())
            === ['db', 'profileId', 'ownerMemberId', 'promptText'],
        'voice profile confirmation helper must not expose a transaction bypass'
    );
});

hub_test('VoxCPM2 profile confirmation validates Unicode characters and preserves reviewed bytes', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Exact Profile Confirmation Owner');
    $path = hub_voice_profile_storage_dir() . '/exact_profile_confirmation.wav';
    file_put_contents($path, 'RIFFexact-confirmation');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Exact confirmation profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
    ]);

    $maxUnicode = str_repeat('界', 20000);
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $maxUnicode);
    hub_test_assert(
        ($confirmed['prompt_text'] ?? null) === $maxUnicode && strlen($maxUnicode) === 60000,
        'confirmation must use a 20,000 Unicode-character limit instead of a 20,000-byte limit'
    );

    $reviewed = "  台灣\tA  + \\literal\r\n第二行  ";
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $reviewed);
    hub_test_assert(($confirmed['prompt_text'] ?? null) === $reviewed, 'confirmation must preserve whitespace, backslashes, tabs, and CRLF byte-for-byte');

    foreach ([
        '',
        str_repeat('x', 20001),
        "invalid\xC3\x28",
        "nul\0byte",
        "vertical\x0btab",
        "c1\xC2\x80control",
    ] as $invalid) {
        hub_test_assert(
            hub_test_throws(static fn (): array => hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $invalid)),
            'confirmation must reject empty, over-limit, malformed UTF-8, and dangerous control text'
        );
    }
    hub_test_assert(
        (hub_get_voice_profile($db, $profileId)['prompt_text'] ?? null) === $reviewed,
        'rejected confirmation text must not alter the authoritative stored transcript'
    );
});

hub_test('VoxCPM2 profile confirmation transaction fences expired profiles authoritatively', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Expired Profile Confirmation Owner');
    $path = hub_voice_profile_storage_dir() . '/expired_profile_confirmation.wav';
    file_put_contents($path, 'RIFFexpired-confirmation');
    $createProfile = static function (string $name, ?string $expiresAt) use ($db, $memberId, $path): int {
        return hub_create_voice_profile($db, $memberId, [
            'name' => $name,
            'reference_audio_path' => $path,
            'consent_type' => 'self_recorded',
            'prompt_text' => 'original draft',
            'expires_at' => $expiresAt,
        ]);
    };
    $stored = static function (int $profileId) use ($db): array {
        $profile = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        return is_array($profile) ? $profile : throw new RuntimeException('Missing expiry test profile.');
    };
    $assertUnavailableWithoutMutation = static function (int $profileId, string $replacement) use ($db, $memberId, $stored): void {
        $before = $stored($profileId);
        $error = null;
        try {
            hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $replacement);
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        }
        $after = $stored($profileId);
        hub_test_assert(
            $error === 'voice_profile_unavailable'
            && $after['prompt_text'] === $before['prompt_text']
            && $after['prompt_text_confirmed_at'] === $before['prompt_text_confirmed_at'],
            'expired confirmation must fail unavailable without changing transcript or confirmation time'
        );
    };

    $expiredId = $createProfile('Already expired profile', '2000-01-01 00:00:00');
    $assertUnavailableWithoutMutation($expiredId, 'must remain expired');

    $boundaryId = $createProfile('Boundary expired profile', hub_now());
    $assertUnavailableWithoutMutation($boundaryId, 'expires_at equal to now is expired');

    $raceId = $createProfile('Precheck race profile', null);
    $raceBefore = $stored($raceId);
    $precheck = hub_voice_profile_task_status_payload($db, ['status' => 'success'], $raceBefore, false);
    hub_test_assert(($precheck['profile_status'] ?? '') === 'active', 'race fixture must pass the operation active precheck');
    $db->prepare('UPDATE voice_profiles SET expires_at = :expires_at WHERE id = :id')
        ->execute([':expires_at' => hub_now(), ':id' => $raceId]);
    $assertUnavailableWithoutMutation($raceId, 'must reject precheck race');

    $nullExpiryId = $createProfile('No expiry profile', null);
    $nullConfirmed = hub_confirm_voice_profile_prompt($db, $nullExpiryId, $memberId, 'confirmed without expiry');
    hub_test_assert(
        ($nullConfirmed['prompt_text'] ?? null) === 'confirmed without expiry'
        && trim((string)($nullConfirmed['prompt_text_confirmed_at'] ?? '')) !== '',
        'confirmation must remain available when expires_at is null'
    );

    $futureExpiryId = $createProfile('Future expiry profile', hub_retention_deadline(3600));
    $futureConfirmed = hub_confirm_voice_profile_prompt($db, $futureExpiryId, $memberId, 'confirmed before expiry');
    hub_test_assert(
        ($futureConfirmed['prompt_text'] ?? null) === 'confirmed before expiry'
        && trim((string)($futureConfirmed['prompt_text_confirmed_at'] ?? '')) !== '',
        'confirmation must remain available while expires_at is in the future'
    );
});

hub_test('VoxCPM2 task-scoped profile confirm and delete stay owner-only and idempotent', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Mutation Owner');
        $foreignMemberId = hub_create_api_member($db, 'Profile Mutation Foreign');
        $token = hub_create_api_token($db, $memberId, 'profile mutation token', null, null);
        $foreignToken = hub_create_api_token($db, $foreignMemberId, 'foreign profile mutation token', null, null);
        hub_test_audio_allow($db, [$token, $foreignToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-mutation-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile mutation WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $upload = [
            'name' => 'mutation.wav',
            'type' => 'audio/wav',
            'tmp_name' => $tmpName,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpName),
        ];

        try {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-mutation';
            $prepared = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Mutation profile',
                'consent_type' => 'explicit_permission',
                'prompt_text' => 'unconfirmed draft',
                'language' => 'en',
            ], [], ['reference_wav' => $upload]);
            $taskId = (int)(hub_test_audio_payload($prepared)['task_id'] ?? 0);
            $task = hub_get_task($db, $taskId);
            $profile = hub_get_voice_profile($db, (int)($task['input']['voice_profile_id'] ?? 0));
            $path = (string)($profile['reference_audio_path'] ?? '');
            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $claimed ?? []);

            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
            $foreignStatus = hub_test_audio_request($db, 'voice_generate', (string)$foreignToken['plain_token'], [
                'operation' => 'profile_status',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            hub_test_assert($foreignStatus['status'] === 403 && (hub_test_audio_payload($foreignStatus)['error'] ?? '') === 'voice_profile_forbidden', 'foreign member must not poll an owned profile task');

            $reviewed = "  edited\tconfirmed  \\ transcript\r\n第二行  ";
            $confirmed = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => $reviewed,
            ]);
            $confirmedPayload = hub_test_audio_payload($confirmed);
            $storedConfirmed = hub_get_voice_profile($db, (int)$profile['id']);
            hub_test_assert(
                $confirmed['status'] === 200
                && !empty($confirmedPayload['transcript_confirmed'])
                && !array_key_exists('prompt_text', $confirmedPayload)
                && ($confirmedPayload['voice_profile_task_id'] ?? null) === (string)$taskId
                && ($confirmedPayload['prompt_text_sha256'] ?? null) === hash('sha256', $reviewed)
                && ($storedConfirmed['prompt_text'] ?? null) === $reviewed,
                'profile_confirm must prove the caller task and authoritative exact transcript without returning its text'
            );

            $repeated = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => $reviewed,
            ]);
            $replacement = "replacement  text\n";
            $replaced = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => $replacement,
            ]);
            hub_test_assert(
                (hub_test_audio_payload($repeated)['prompt_text_sha256'] ?? null) === hash('sha256', $reviewed)
                && (hub_test_audio_payload($replaced)['prompt_text_sha256'] ?? null) === hash('sha256', $replacement)
                && (hub_get_voice_profile($db, (int)$profile['id'])['prompt_text'] ?? null) === $replacement,
                'repeat confirmation must retain existing same-text and replacement semantics with authoritative hashes'
            );

            $status = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_status',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            $statusPayload = hub_test_audio_payload($status);
            hub_test_assert(
                $status['status'] === 200
                && !array_key_exists('prompt_text', $statusPayload)
                && !array_key_exists('voice_profile_task_id', $statusPayload)
                && !array_key_exists('prompt_text_sha256', $statusPayload),
                'confirmed transcript and confirmation proof must stay absent from profile_status'
            );

            $db->prepare("UPDATE voice_profiles SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")
                ->execute([':id' => (int)$profile['id']]);
            $beforeExpiredConfirm = $db->query('SELECT prompt_text, prompt_text_confirmed_at FROM voice_profiles WHERE id = ' . (int)$profile['id'])->fetch();
            $expiredConfirm = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => 'must not revive expired profile',
            ]);
            $expiredPayload = hub_test_audio_payload($expiredConfirm);
            $afterExpiredConfirm = $db->query('SELECT prompt_text, prompt_text_confirmed_at FROM voice_profiles WHERE id = ' . (int)$profile['id'])->fetch();
            hub_test_assert(
                $expiredConfirm['status'] === 410
                && ($expiredPayload['error'] ?? '') === 'voice_profile_unavailable'
                && !array_key_exists('voice_profile_task_id', $expiredPayload)
                && !array_key_exists('prompt_text_sha256', $expiredPayload)
                && $afterExpiredConfirm === $beforeExpiredConfirm,
                'profile_confirm must report an expired profile without proof or stored transcript mutation'
            );
            $db->prepare('UPDATE voice_profiles SET expires_at = NULL WHERE id = :id')
                ->execute([':id' => (int)$profile['id']]);

            $deleted = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_delete',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            $deletedPayload = hub_test_audio_payload($deleted);
            hub_test_assert($deleted['status'] === 200 && ($deletedPayload['profile_status'] ?? '') === 'deleted' && !is_file($path), 'profile_delete must soft-delete the profile and remove its managed WAV');
            hub_test_assert(hub_get_voice_profile($db, (int)$profile['id']) === null, 'deleted profile must stay hidden from the general profile lookup');
            $tombstone = $db->query('SELECT * FROM voice_profiles WHERE id = ' . (int)$profile['id'])->fetch();
            hub_test_assert(
                $tombstone !== false
                && ($tombstone['name'] ?? null) === 'Deleted voice profile'
                && ($tombstone['reference_audio_path'] ?? null) === ''
                && ($tombstone['reference_audio_sha256'] ?? null) === ''
                && ($tombstone['prompt_text'] ?? null) === null
                && ($tombstone['prompt_text_confirmed_at'] ?? null) === null
                && ($tombstone['language'] ?? null) === null
                && ($tombstone['transcription_error'] ?? null) === null
                && ($tombstone['transcription_started_at'] ?? null) === null
                && ($tombstone['transcription_lease_token'] ?? null) === null
                && (string)($tombstone['expires_at'] ?? '') !== '',
                'profile_delete must leave only a bounded tombstone after removing the WAV'
            );

            $deletedStatus = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_status',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            $repeatedDelete = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_delete',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            hub_test_assert(
                $deletedStatus['status'] === 200
                && (hub_test_audio_payload($deletedStatus)['profile_status'] ?? '') === 'deleted'
                && $repeatedDelete['status'] === 200
                && (hub_test_audio_payload($repeatedDelete)['profile_status'] ?? '') === 'deleted',
                'same owner must be able to query and repeat task-scoped deletion safely'
            );
            $deletedConfirm = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => 'must not revive deleted profile',
            ]);
            $deleteAuditCount = (int)$db->query(
                "SELECT COUNT(*) FROM voice_profile_audit_logs
                 WHERE voice_profile_id = " . (int)$profile['id'] . " AND action = 'delete'"
            )->fetchColumn();
            hub_test_assert(
                $deletedConfirm['status'] === 410
                && (hub_test_audio_payload($deletedConfirm)['error'] ?? '') === 'voice_profile_unavailable'
                && $deleteAuditCount === 1,
                'deleted confirmation and repeated deletion must remain unavailable and idempotent'
            );

            $foreignDelete = hub_test_audio_request($db, 'voice_generate', (string)$foreignToken['plain_token'], [
                'operation' => 'profile_delete',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            hub_test_assert($foreignDelete['status'] === 403 && (hub_test_audio_payload($foreignDelete)['error'] ?? '') === 'voice_profile_forbidden', 'foreign member must not delete an owned profile task');
        } finally {
            if (is_file($tmpName)) {
                unlink($tmpName);
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare worker transcribes missing text into an owner-only draft', function (): void {
    hub_test_audio_isolate(static function (): void {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            hub_test_skip('profile_prepare ASR worker test requires cURL and proc_open');
        }
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $asr = hub_install_pack($db, 'whisper-asr', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile ASR Owner');
        $token = hub_create_api_token($db, $memberId, 'profile ASR token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $router = tempnam(sys_get_temp_dir(), 'voice-profile-asr-router-');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-asr-');
        $failedTmpName = tempnam(sys_get_temp_dir(), 'voice-profile-failed-asr-');
        if ($router === false || $tmpName === false || $failedTmpName === false) {
            throw new RuntimeException('Cannot create profile ASR fixtures.');
        }
        file_put_contents($router, "<?php\nheader('Content-Type: application/json');\necho json_encode(['ok' => true, 'text' => 'worker ASR draft', 'language' => 'en', 'device' => ['effective' => 'cpu']]);\n");
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        file_put_contents($failedTmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . "\0");
        $server = null;

        try {
            $server = hub_test_public_api_start_server($router);
            $db->prepare("UPDATE services SET internal_url = :internal_url, install_status = 'installed', enabled = 1, runtime_status = 'running' WHERE id = :id")->execute([
                ':internal_url' => 'http://127.0.0.1:' . (int)$server['port'] . '/v1/transcribe',
                ':id' => (int)$asr['service']['id'],
            ]);
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-asr';
            $prepared = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'ASR profile',
                'consent_type' => 'self_recorded',
            ], [], ['reference_wav' => [
                'name' => 'asr.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]]);
            $taskId = (int)(hub_test_audio_payload($prepared)['task_id'] ?? 0);
            $task = hub_get_task($db, $taskId);
            $profileId = (int)($task['input']['voice_profile_id'] ?? 0);
            hub_test_assert((hub_get_voice_profile($db, $profileId)['transcription_status'] ?? '') === 'pending', 'missing supplied text must defer ASR with the existing pending lease');

            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $claimed ?? []);
            $profile = hub_get_voice_profile($db, $profileId);
            hub_test_assert($profile !== null && $profile['transcription_status'] === 'ready' && $profile['prompt_text'] === 'worker ASR draft' && $profile['prompt_text_confirmed_at'] === null, 'worker must run existing ASR and save an unconfirmed draft');

            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
            $status = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_status',
                'voice_profile_task_id' => (string)$taskId,
            ]);
            hub_test_assert($status['status'] === 200 && (hub_test_audio_payload($status)['prompt_text'] ?? '') === 'worker ASR draft', 'owner status must expose the unconfirmed ASR draft');
            $confirmed = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => (string)$taskId,
                'prompt_text' => 'worker ASR draft',
            ]);
            hub_test_assert($confirmed['status'] === 200 && !array_key_exists('prompt_text', hub_test_audio_payload($confirmed)), 'confirming the ASR draft must remove it from safe responses');

            $failedPath = hub_voice_profile_storage_dir() . '/cached_failed_profile.wav';
            copy($failedTmpName, $failedPath);
            $failedProfileId = hub_create_voice_profile($db, $memberId, [
                'name' => 'Cached failed profile',
                'reference_audio_path' => $failedPath,
                'consent_type' => 'self_recorded',
                'transcription_status' => 'failed',
                'transcription_error' => 'asr_failed',
            ]);
            $failedTaskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $failedProfileId], null, '127.0.0.1', [
                'owner_member_id' => $memberId,
                'owner_token_id' => (int)$token['token_id'],
                'requested_mode' => 'voice_generate',
            ]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([':task_id' => $failedTaskId, ':id' => $failedProfileId]);
            hub_finish_task_failed($db, hub_get_task($db, $failedTaskId) ?? [], 'asr failed');

            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-failed-asr';
            $retried = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Cached failed profile',
                'consent_type' => 'self_recorded',
            ], [], ['reference_wav' => [
                'name' => 'failed-asr.wav',
                'type' => 'audio/wav',
                'tmp_name' => $failedTmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($failedTmpName),
            ]]);
            $retryTaskId = (int)(hub_test_audio_payload($retried)['task_id'] ?? 0);
            hub_test_assert($retryTaskId > 0 && $retryTaskId !== $failedTaskId, 'cached failed profile must receive a fresh preparation task');
            $retryClaimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $retryClaimed ?? []);
            $retriedProfile = hub_get_voice_profile($db, $failedProfileId);
            $retryResult = hub_get_task($db, $retryTaskId)['result'] ?? [];
            hub_test_assert(
                $retriedProfile !== null
                && $retriedProfile['transcription_status'] === 'ready'
                && $retriedProfile['prompt_text'] === 'worker ASR draft'
                && ($retryResult['text_chars'] ?? 0) > 0,
                'failed cached profile with empty prompt must rerun ASR instead of reporting empty success'
            );
        } finally {
            if (is_array($server)) {
                hub_test_public_api_stop_servers([$server]);
            }
            foreach ([$router, $tmpName, $failedTmpName] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare terminal states atomically enqueue callbacks and retry publication failures', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Profile Terminal Owner');
        $callbackTargetId = hub_register_callback_target($db, $memberId, 'profile-terminal-events', 'https://8.8.8.8/voice-profile-terminal');
        $paths = [];
        $createClaimed = static function (string $name) use ($db, $memberId, $callbackTargetId, &$paths): array {
            $path = hub_voice_profile_storage_dir() . '/profile_terminal_' . strtolower(str_replace(' ', '_', $name)) . '.wav';
            file_put_contents($path, 'RIFF' . $name);
            $paths[] = $path;
            $profileId = hub_create_voice_profile($db, $memberId, [
                'name' => $name,
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
                'prompt_text' => 'terminal draft',
                'language' => 'en',
                'transcription_status' => 'ready',
            ]);
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '127.0.0.1', [
                'owner_member_id' => $memberId,
                'requested_mode' => 'voice_generate',
                'callback_target_id' => $callbackTargetId,
            ]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([
                ':task_id' => $taskId,
                ':id' => $profileId,
            ]);
            $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_test_assert((int)($claimed['id'] ?? 0) === $taskId, 'terminal callback fixture must claim its task');
            return $claimed;
        };

        try {
            $successTask = $createClaimed('Callback Retry');
            $successTaskId = (int)$successTask['id'];
            $db->exec("CREATE TRIGGER voice_profile_callback_insert_failure
                BEFORE INSERT ON task_callback_deliveries
                WHEN NEW.task_id = " . $successTaskId . "
                BEGIN
                    SELECT RAISE(ABORT, 'callback_insert_failed');
                END");
            try {
                hub_run_voice_profile_prepare_task($db, $successTask);
            } catch (Throwable) {
            } finally {
                $db->exec('DROP TRIGGER IF EXISTS voice_profile_callback_insert_failure');
            }
            $afterCallbackFailure = hub_get_task($db, $successTaskId);
            hub_test_assert(
                ($afterCallbackFailure['status'] ?? '') === 'queued'
                && ($afterCallbackFailure['result'] ?? null) === null
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $successTaskId)->fetchColumn() === 0,
                'callback enqueue failure must roll back success and leave the task retriable'
            );

            $successRetry = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_run_voice_profile_prepare_task($db, $successRetry ?? []);
            hub_test_assert(
                (hub_get_task($db, $successTaskId)['status'] ?? '') === 'success'
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $successTaskId)->fetchColumn() === 1,
                'retried success must atomically persist one idempotent callback delivery'
            );

            $failedTask = $createClaimed('Callback Failed');
            hub_finish_voice_profile_prepare_task($db, $failedTask, 'failed', [], 'worker failed');
            hub_test_assert(
                (hub_get_task($db, (int)$failedTask['id'])['status'] ?? '') === 'failed'
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$failedTask['id'])->fetchColumn() === 1,
                'voice profile worker failure must atomically enqueue its callback'
            );

            $cancelledTask = $createClaimed('Callback Cancelled');
            hub_finish_voice_profile_prepare_task($db, $cancelledTask, 'cancelled', [], 'cancelled');
            hub_test_assert(
                (hub_get_task($db, (int)$cancelledTask['id'])['status'] ?? '') === 'cancelled'
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . (int)$cancelledTask['id'])->fetchColumn() === 1,
                'voice profile worker cancellation must atomically enqueue its callback'
            );
        } finally {
            $db->exec('DROP TRIGGER IF EXISTS voice_profile_callback_insert_failure');
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('VoxCPM2 generic cancellation atomically cancels queued and staging profile tasks with callbacks', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        $memberId = hub_create_api_member($db, 'Profile Cancel Owner');
        $callbackTargetId = hub_register_callback_target($db, $memberId, 'profile-cancel-events', 'https://8.8.8.8/voice-profile-cancel');
        $paths = [];
        $createCancelable = static function (string $name, string $status) use ($db, $memberId, $callbackTargetId, &$paths): int {
            $path = hub_voice_profile_storage_dir() . '/profile_cancel_' . strtolower(str_replace(' ', '_', $name)) . '.wav';
            file_put_contents($path, 'RIFF' . $name);
            $paths[] = $path;
            $profileId = hub_create_voice_profile($db, $memberId, [
                'name' => $name,
                'reference_audio_path' => $path,
                'consent_type' => 'self_recorded',
                'transcription_status' => 'pending',
            ]);
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '127.0.0.1', [
                'owner_member_id' => $memberId,
                'requested_mode' => 'voice_generate',
                'callback_target_id' => $callbackTargetId,
                'status' => $status,
            ]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([
                ':task_id' => $taskId,
                ':id' => $profileId,
            ]);
            return $taskId;
        };

        try {
            $queuedTaskId = $createCancelable('Queued Cancel', 'queued');
            $stagingTaskId = $createCancelable('Staging Cancel', 'staging');
            $rollbackTaskId = $createCancelable('Rollback Cancel', 'queued');
            $queuedCancelled = hub_cancel_task($db, $queuedTaskId);
            $stagingCancelled = hub_cancel_task($db, $stagingTaskId);

            $db->exec("CREATE TRIGGER voice_profile_cancel_callback_failure
                BEFORE INSERT ON task_callback_deliveries
                WHEN NEW.task_id = " . $rollbackTaskId . "
                BEGIN
                    SELECT RAISE(ABORT, 'callback_insert_failed');
                END");
            $callbackFailed = false;
            try {
                hub_cancel_task($db, $rollbackTaskId);
            } catch (Throwable) {
                $callbackFailed = true;
            } finally {
                $db->exec('DROP TRIGGER IF EXISTS voice_profile_cancel_callback_failure');
            }

            hub_test_assert(
                $queuedCancelled
                && $stagingCancelled
                && (hub_get_task($db, $queuedTaskId)['status'] ?? '') === 'cancelled'
                && (hub_get_task($db, $stagingTaskId)['status'] ?? '') === 'cancelled'
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $queuedTaskId)->fetchColumn() === 1
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $stagingTaskId)->fetchColumn() === 1,
                'generic cancellation must atomically cancel queued and staging voice-profile tasks with callbacks'
            );
            hub_test_assert(
                $callbackFailed
                && (hub_get_task($db, $rollbackTaskId)['status'] ?? '') === 'queued'
                && (int)$db->query('SELECT COUNT(*) FROM task_callback_deliveries WHERE task_id = ' . $rollbackTaskId)->fetchColumn() === 0,
                'voice-profile callback failure must roll back generic cancellation'
            );
        } finally {
            $db->exec('DROP TRIGGER IF EXISTS voice_profile_cancel_callback_failure');
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare reuses native handles but not the current Cluster token', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Cache Owner');
        $nativeToken = hub_create_api_token($db, $memberId, 'native profile cache token', null, null);
        $nodeToken = hub_create_api_token($db, $memberId, 'node profile cache token', null, null);
        hub_test_audio_allow($db, [$nativeToken, $nodeToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $cacheCallbackTargetId = hub_register_callback_target($db, $memberId, 'profile-cache-events', 'https://8.8.8.8/voice-profile-cache');
        hub_register_callback_target($db, $memberId, 'profile-cache-other', 'https://8.8.4.4/voice-profile-cache');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-cache-api-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile cache WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $request = static function (
            array $token,
            bool $authenticatedDispatcher = false,
            string $promptText = 'cache draft',
            ?string $language = null,
            ?string $transcriptConfirmed = null,
            int $expectedStatus = 200,
            ?string $callbackTarget = 'profile-cache-events'
        ) use ($db, $memberId, $tmpName): int|array {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-cache';
            $post = [
                'operation' => 'profile_prepare',
                'profile_name' => 'Cached profile',
                'consent_type' => 'self_recorded',
                'prompt_text' => $promptText,
            ];
            if ($callbackTarget !== null) {
                $post['callback_target'] = $callbackTarget;
            }
            if ($language !== null) {
                $post['language'] = $language;
            }
            if ($transcriptConfirmed !== null) {
                $post['transcript_confirmed'] = $transcriptConfirmed;
            }
            $files = ['reference_wav' => [
                'name' => 'cache.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]];
            if ($authenticatedDispatcher) {
                $_SERVER['REMOTE_ADDR'] = '203.0.113.51';
                $_SERVER['REQUEST_METHOD'] = 'POST';
                $_POST = $post;
                $_FILES = $files;
                $response = hub_voice_profile_api_dispatch($db, hub_resolve_audio_async_route($db, 'voice_generate'), [
                    'member_id' => $memberId,
                    'token_id' => (int)$token['token_id'],
                ]);
            } else {
                $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], $post, [], $files);
            }
            hub_test_assert(is_array($response), 'profile cache dispatcher must handle profile_prepare');
            hub_test_assert($response['status'] === $expectedStatus, 'profile cache request must return the expected status');
            if ($expectedStatus !== 200) {
                return $response;
            }
            return (int)(hub_test_audio_payload($response)['task_id'] ?? 0);
        };

        try {
            $nativeFirst = $request($nativeToken);
            $nativeAgain = $request($nativeToken);
            hub_test_assert($nativeAgain === $nativeFirst, 'native owner+SHA cache reuse must preserve the usable source task handle');
            $nativeFirstTask = hub_get_task($db, $nativeFirst);
            hub_finish_task_cancelled($db, $nativeFirstTask ?? []);
            $nativeAfterCancelled = $request($nativeToken);
            $replacementTask = hub_get_task($db, $nativeAfterCancelled);
            hub_test_assert(
                $nativeAfterCancelled > 0
                && $nativeAfterCancelled !== $nativeFirst
                && (int)($replacementTask['input']['voice_profile_id'] ?? 0) === (int)($nativeFirstTask['input']['voice_profile_id'] ?? 0),
                'native cache reuse must replace a cancelled source task while retaining the managed profile'
            );

            $profileId = (int)($replacementTask['input']['voice_profile_id'] ?? 0);
            $db->prepare(
                "UPDATE voice_profiles
                 SET prompt_text = 'old failed draft', language = 'fr', prompt_text_confirmed_at = :confirmed_at,
                     transcription_status = 'failed', transcription_error = 'asr_failed',
                     transcription_started_at = :started_at, transcription_lease_token = :lease_token
                 WHERE id = :id"
            )->execute([
                ':confirmed_at' => hub_now(),
                ':started_at' => hub_now(),
                ':lease_token' => str_repeat('a', 64),
                ':id' => $profileId,
            ]);
            $changedDraft = 'replacement unconfirmed draft';
            $changedTaskId = $request($nativeToken, false, $changedDraft, 'zh-TW');
            $changedTask = hub_get_task($db, $changedTaskId);
            $changedProfile = hub_get_voice_profile($db, $profileId);
            hub_test_assert(
                $changedTaskId > 0
                && $changedTaskId !== $nativeAfterCancelled
                && (int)($changedTask['input']['voice_profile_id'] ?? 0) === $profileId
                && $changedProfile !== null
                && $changedProfile['prompt_text'] === $changedDraft
                && $changedProfile['language'] === 'zh-TW'
                && $changedProfile['transcription_status'] === 'ready'
                && $changedProfile['prompt_text_confirmed_at'] === null
                && $changedProfile['transcription_error'] === null
                && $changedProfile['transcription_started_at'] === null
                && $changedProfile['transcription_lease_token'] === null,
                'changed cached draft must replace failed content and receive a fresh task'
            );
            $auditJson = (string)$db->query(
                'SELECT details_json FROM voice_profile_audit_logs
                 WHERE voice_profile_id = ' . $profileId . "
                   AND action = 'cache_hit'
                 ORDER BY id DESC
                 LIMIT 1"
            )->fetchColumn();
            $auditDetails = json_decode($auditJson, true);
            hub_test_assert(
                is_array($auditDetails)
                && ($auditDetails['status'] ?? '') === 'ready'
                && ($auditDetails['text_chars'] ?? null) === strlen($changedDraft)
                && ($auditDetails['prompt_text_sha256'] ?? '') === hash('sha256', $changedDraft)
                && !str_contains($auditJson, $changedDraft)
                && !str_contains($auditJson, 'old failed draft'),
                'changed cached draft audit must contain only safe status, count, and hash'
            );

            $supersededTask = hub_get_task($db, $nativeAfterCancelled);
            $supersededCallback = $db->query(
                'SELECT event_type FROM task_callback_deliveries WHERE task_id = ' . $nativeAfterCancelled
            )->fetchColumn();
            hub_test_assert(
                ($supersededTask['status'] ?? '') === 'cancelled'
                && $supersededCallback === 'task.failed',
                'changed cached draft must atomically cancel its queued predecessor and enqueue its callback'
            );
            $changedClaimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_test_assert((int)($changedClaimed['id'] ?? 0) === $changedTaskId, 'changed cached draft must publish only its fresh task for preparation');
            hub_run_voice_profile_prepare_task($db, $changedClaimed ?? []);
            $changedResult = hub_get_task($db, $changedTaskId)['result'] ?? [];
            hub_test_assert(
                ($changedResult['transcription_status'] ?? '') === 'ready'
                && ($changedResult['transcript_confirmed'] ?? true) === false
                && ($changedResult['prompt_text_sha256'] ?? '') === hash('sha256', $changedDraft),
                'changed cached draft worker must skip ASR and finish with the current safe hash'
            );
            $unchangedTaskId = $request($nativeToken, false, $changedDraft, 'zh-TW');
            hub_test_assert($unchangedTaskId === $changedTaskId, 'unchanged cached draft must continue reusing its usable source task');
            $confirmedTaskId = $request($nativeToken, false, $changedDraft, 'zh-TW', '1');
            hub_test_assert(
                $confirmedTaskId > 0 && $confirmedTaskId !== $changedTaskId,
                'confirming an unchanged cached draft must create a fresh task with current confirmation state'
            );
            $confirmedClaimed = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_test_assert((int)($confirmedClaimed['id'] ?? 0) === $confirmedTaskId, 'confirmed cached draft must publish its fresh preparation task');
            hub_run_voice_profile_prepare_task($db, $confirmedClaimed ?? []);
            $confirmedResult = hub_get_task($db, $confirmedTaskId)['result'] ?? [];
            hub_test_assert(
                ($confirmedResult['transcript_confirmed'] ?? false) === true
                && ($confirmedResult['prompt_text_sha256'] ?? '') === hash('sha256', $changedDraft),
                'confirmed cached draft worker result must reflect the current confirmation state'
            );
            $confirmedAgainTaskId = $request($nativeToken, false, $changedDraft, 'zh-TW', '1');
            hub_test_assert(
                $confirmedAgainTaskId === $confirmedTaskId,
                'identical confirmed cached draft must preserve confirmation and reuse its usable source task'
            );
            $beforeCallbackConflict = hub_get_voice_profile($db, $profileId);
            $beforeCallbackConflictAudits = (int)$db->query(
                'SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId
            )->fetchColumn();
            $callbackConflict = $request($nativeToken, false, $changedDraft, 'zh-TW', '1', 409, 'profile-cache-other');
            $afterCallbackConflict = hub_get_voice_profile($db, $profileId);
            hub_test_assert(
                (hub_test_audio_payload($callbackConflict)['error'] ?? '') === 'voice_profile_callback_conflict'
                && $beforeCallbackConflict !== null
                && $afterCallbackConflict !== null
                && (int)$afterCallbackConflict['source_task_id'] === $confirmedTaskId
                && $afterCallbackConflict['prompt_text_confirmed_at'] === $beforeCallbackConflict['prompt_text_confirmed_at']
                && (int)(hub_get_task($db, $confirmedTaskId)['callback_target_id'] ?? 0) === $cacheCallbackTargetId
                && (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId)->fetchColumn() === $beforeCallbackConflictAudits,
                'cached handle callback mismatch must return conflict without mutating profile task or audit state'
            );
            $draftAgainTaskId = $request($nativeToken, false, $changedDraft, 'zh-TW');
            $draftAgainProfile = hub_get_voice_profile($db, $profileId);
            hub_test_assert(
                $draftAgainTaskId > 0
                && $draftAgainTaskId !== $confirmedTaskId
                && $draftAgainProfile !== null
                && $draftAgainProfile['prompt_text_confirmed_at'] === null,
                'changing an identical confirmed cached profile back to draft must clear confirmation and create a fresh task'
            );
            $runningPredecessor = hub_claim_next_task($db, ['voice_profile_prepare']);
            hub_test_assert((int)($runningPredecessor['id'] ?? 0) === $draftAgainTaskId, 'running predecessor fixture must claim the current source task');
            $beforeRunningConflict = hub_get_voice_profile($db, $profileId);
            $runningConflict = $request($nativeToken, false, 'must not replace running draft', 'en', null, 409);
            $afterRunningConflict = hub_get_voice_profile($db, $profileId);
            hub_test_assert(
                (hub_test_audio_payload($runningConflict)['error'] ?? '') === 'voice_profile_prepare_conflict'
                && $beforeRunningConflict !== null
                && $afterRunningConflict !== null
                && $afterRunningConflict['prompt_text'] === $beforeRunningConflict['prompt_text']
                && $afterRunningConflict['language'] === $beforeRunningConflict['language']
                && $afterRunningConflict['prompt_text_confirmed_at'] === $beforeRunningConflict['prompt_text_confirmed_at']
                && $afterRunningConflict['source_task_id'] === $beforeRunningConflict['source_task_id'],
                'running predecessor must return conflict without mutating the cached profile'
            );

            $db->prepare('UPDATE tasks SET lock_token = :lock_token WHERE id = :id')->execute([
                ':lock_token' => str_repeat('b', 32),
                ':id' => $draftAgainTaskId,
            ]);
            hub_test_assert(
                hub_test_throws(static fn (): mixed => hub_run_voice_profile_prepare_task($db, $runningPredecessor ?? []))
                && (hub_get_task($db, $draftAgainTaskId)['status'] ?? '') === 'running'
                && (hub_get_task($db, $draftAgainTaskId)['result'] ?? null) === null,
                'stale voice profile worker must not finish after losing its task lock token'
            );

            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ENABLED', '1');
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_TOKEN_ID', (string)$nodeToken['token_id']);
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_MODE_JSON', json_encode(['voice_generate'], JSON_THROW_ON_ERROR));
            hub_set_storage_setting($db, 'AIHUB_CLUSTER_NODE_ROUTER_NAME', 'Primary Router');
            $nodeFirst = $request($nodeToken, true);
            $nodeAgain = $request($nodeToken, true);
            $nodeFirstTask = hub_get_task($db, $nodeFirst);
            $nodeAgainTask = hub_get_task($db, $nodeAgain);
            hub_test_assert(
                $nodeFirst > 0
                && $nodeAgain > 0
                && $nodeFirst !== $nodeAgain
                && (int)($nodeFirstTask['input']['voice_profile_id'] ?? 0) !== (int)($nodeAgainTask['input']['voice_profile_id'] ?? 0),
                'current paired Cluster token must create a distinct profile and task for identical owner+SHA requests'
            );
        } finally {
            if (is_file($tmpName)) {
                unlink($tmpName);
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare boolean forms and validation errors stay exact', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Boolean Owner');
        $token = hub_create_api_token($db, $memberId, 'profile boolean token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpPaths = [];
        $request = static function (mixed $value, int $index, bool $includePrompt = true) use ($db, $token, &$tmpPaths): array {
            $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-boolean-');
            if ($tmpName === false) {
                throw new RuntimeException('Cannot create profile boolean WAV fixture.');
            }
            $tmpPaths[] = $tmpName;
            file_put_contents($tmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . chr($index));
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-boolean';
            $post = [
                'operation' => 'profile_prepare',
                'profile_name' => 'Boolean profile ' . $index,
                'consent_type' => 'self_recorded',
                'transcript_confirmed' => $value,
            ];
            if ($includePrompt) {
                $post['prompt_text'] = 'Boolean draft ' . $index;
            }
            return hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], $post, [], ['reference_wav' => [
                'name' => 'boolean.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ]]);
        };

        try {
            foreach ([[true, true], [false, false], ['true', true], ['false', false], ['1', true], ['0', false]] as $index => [$value, $expectedConfirmed]) {
                $response = $request($value, $index + 1);
                $task = hub_get_task($db, (int)(hub_test_audio_payload($response)['task_id'] ?? 0));
                $profile = hub_get_voice_profile($db, (int)($task['input']['voice_profile_id'] ?? 0));
                hub_test_assert(
                    $response['status'] === 200
                    && $profile !== null
                    && (!empty($profile['prompt_text_confirmed_at'])) === $expectedConfirmed,
                    'profile_prepare must accept only the approved exact boolean representations'
                );
            }
            foreach (['yes', ['true']] as $index => $value) {
                $response = $request($value, $index + 20);
                hub_test_assert(
                    $response['status'] === 400
                    && (hub_test_audio_payload($response)['error'] ?? '') === 'invalid_request',
                    'profile_prepare must reject malformed transcript_confirmed values'
                );
            }

            $confirmedWithoutText = $request('1', 30, false);
            hub_test_assert(
                $confirmedWithoutText['status'] === 400
                && (hub_test_audio_payload($confirmedWithoutText)['error'] ?? '') === 'voice_profile_transcript_invalid',
                'confirmed profile_prepare without prompt text must use the stable transcript error'
            );

            $invalidWav = tempnam(sys_get_temp_dir(), 'voice-profile-invalid-wav-');
            if ($invalidWav === false) {
                throw new RuntimeException('Cannot create invalid WAV fixture.');
            }
            $tmpPaths[] = $invalidWav;
            file_put_contents($invalidWav, 'not a wav');
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-errors';
            $invalidWavResponse = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => 'Invalid WAV',
                'consent_type' => 'self_recorded',
            ], [], ['reference_wav' => [
                'name' => 'invalid.wav',
                'type' => 'audio/wav',
                'tmp_name' => $invalidWav,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($invalidWav),
            ]]);
            hub_test_assert(
                $invalidWavResponse['status'] === 400
                && (hub_test_audio_payload($invalidWavResponse)['error'] ?? '') === 'voice_profile_wav_invalid',
                'invalid WAV validation must use the stable redacted WAV error'
            );

            $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
            $invalidConfirm = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => '1',
                'prompt_text' => '',
            ]);
            hub_test_assert(
                $invalidConfirm['status'] === 400
                && (hub_test_audio_payload($invalidConfirm)['error'] ?? '') === 'voice_profile_transcript_invalid',
                'invalid profile_confirm transcript must use the stable transcript error'
            );
        } finally {
            foreach ($tmpPaths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    });
});

hub_test('VoxCPM2 profile_prepare accepts only canonical bounded expiry seconds', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Expiry Owner');
        $token = hub_create_api_token($db, $memberId, 'profile expiry token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-expiry-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile expiry WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $upload = [
            'name' => 'expiry.wav',
            'type' => 'audio/wav',
            'tmp_name' => $tmpName,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($tmpName),
        ];
        $request = static function (mixed $expires, string $name) use ($db, $token, $upload): array {
            $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=voice-profile-expiry';
            return hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'operation' => 'profile_prepare',
                'profile_name' => $name,
                'consent_type' => 'self_recorded',
                'prompt_text' => 'Confirmed expiry transcript',
                'transcript_confirmed' => '1',
                'expires_in_seconds' => $expires,
            ], [], ['reference_wav' => $upload]);
        };

        try {
            foreach (['300', '86400'] as $index => $expires) {
                hub_test_assert($request($expires, 'Valid expiry ' . $index)['status'] === 200, 'bounded canonical expiry must be accepted');
            }
            foreach (['299', '86401', '0300', '+300', '300.0', '', true, 300, ['3600']] as $index => $expires) {
                $response = $request($expires, 'Invalid expiry ' . $index);
                hub_test_assert(
                    $response['status'] === 400
                    && (hub_test_audio_payload($response)['error'] ?? '') === 'invalid_request',
                    'non-canonical or out-of-range expiry must be rejected'
                );
            }
        } finally {
            @unlink($tmpName);
        }
    });
});

hub_test('VoxCPM2 JSON profile operations and synthesize share voice_generate safely', function (): void {
    hub_test_audio_isolate(static function (): void {
        if (!function_exists('curl_init') || !function_exists('proc_open')) {
            hub_test_skip('voice profile JSON API test requires cURL and proc_open');
        }
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile JSON Owner');
        $token = hub_create_api_token($db, $memberId, 'profile JSON token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $path = hub_voice_profile_storage_dir() . '/profile_json.wav';
        file_put_contents($path, 'RIFFjson');
        $profileId = hub_create_voice_profile($db, $memberId, [
            'name' => 'JSON profile',
            'reference_audio_path' => $path,
            'prompt_text' => 'JSON owner draft',
            'language' => 'en',
            'consent_type' => 'self_recorded',
        ]);
        $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '127.0.0.1', [
            'owner_member_id' => $memberId,
            'owner_token_id' => (int)$token['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([':task_id' => $taskId, ':id' => $profileId]);
        $task = hub_get_task($db, $taskId);
        hub_finish_task_success($db, $task ?? [], [
            'kind' => 'voice_profile_prepare',
            'transcription_status' => 'ready',
            'transcript_confirmed' => false,
            'text_chars' => strlen('JSON owner draft'),
            'prompt_text_sha256' => hash('sha256', 'JSON owner draft'),
        ]);
        $server = hub_test_public_api_start_server(HUB_ROOT . '/api.php');
        $request = static function (array $payload) use ($server, $token): array {
            $ch = curl_init('http://127.0.0.1:' . (int)$server['port'] . '/api.php?mode=voice_generate');
            if ($ch === false) {
                throw new RuntimeException('Cannot initialize profile JSON request.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . (string)$token['plain_token'],
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $decoded = json_decode((string)$body, true);
            hub_test_assert(is_array($decoded), 'profile JSON response must be JSON');
            return ['status' => $status, 'payload' => $decoded];
        };

        try {
            $status = $request(['operation' => 'profile_status', 'voice_profile_task_id' => $taskId]);
            hub_test_assert($status['status'] === 200 && ($status['payload']['prompt_text'] ?? '') === 'JSON owner draft', 'profile_status must accept a bounded top-level JSON object');
            $confirmed = $request([
                'operation' => 'profile_confirm',
                'voice_profile_task_id' => $taskId,
                'prompt_text' => 'JSON confirmed text',
            ]);
            hub_test_assert($confirmed['status'] === 200 && !array_key_exists('prompt_text', $confirmed['payload']), 'profile_confirm JSON response must hide confirmed text');

            $synthesized = $request([
                'operation' => 'synthesize',
                'text' => 'RC Valve JSON synthesis',
                'voice_prompt' => 'clear technician voice',
            ]);
            $synthesisTask = hub_get_task($db, (int)($synthesized['payload']['task_id'] ?? 0));
            hub_test_assert(
                $synthesized['status'] === 200
                && ($synthesisTask['task_type'] ?? '') === 'pack_job'
                && !array_key_exists('operation', (array)($synthesisTask['input'] ?? [])),
                'JSON synthesize must remove only operation before Pack normalization'
            );
        } finally {
            hub_test_public_api_stop_servers([$server]);
        }
    });
});

hub_test('VoxCPM2 profile_prepare worker hashes empty prompt text in its fixed result', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Profile Empty Hash Owner');
    $path = hub_voice_profile_storage_dir() . '/profile_empty_hash.wav';
    file_put_contents($path, 'RIFFempty-hash');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Empty hash profile',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'transcription_status' => 'failed',
        'transcription_error' => 'asr_unavailable',
    ]);
    $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, null, [
        'owner_member_id' => $memberId,
        'requested_mode' => 'voice_generate',
    ]);
    $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')->execute([':task_id' => $taskId, ':id' => $profileId]);
    $claimed = hub_claim_next_task($db, ['voice_profile_prepare']);
    hub_run_voice_profile_prepare_task($db, $claimed ?? []);
    $result = hub_get_task($db, $taskId)['result'] ?? [];
    hub_test_assert(($result['prompt_text_sha256'] ?? null) === hash('sha256', ''), 'empty prompt text must use the 64-character SHA-256 of the empty string');
});

hub_test('VoxCPM2 profile operation validation separates methods from malformed fields', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $memberId = hub_create_api_member($db, 'Profile Validation Owner');
        $token = hub_create_api_token($db, $memberId, 'profile validation token', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-validation-');
        if ($tmpName === false) {
            throw new RuntimeException('Cannot create profile validation WAV fixture.');
        }
        file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
        $validUpload = [
            'reference_wav' => [
                'name' => 'validation.wav',
                'type' => 'audio/wav',
                'tmp_name' => $tmpName,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmpName),
            ],
        ];
        $cases = [
            ['POST', 'text/plain', ['operation' => 'profile_prepare'], [], 400],
            ['POST', 'multipart/form-datax', ['operation' => 'profile_prepare', 'profile_name' => 'x', 'consent_type' => 'self_recorded'], $validUpload, 400],
            ['GET', 'multipart/form-data; boundary=x', ['operation' => 'profile_prepare'], [], 405],
            ['POST', 'application/x-www-form-urlencoded', ['operation' => 'profile_confirm', 'voice_profile_task_id' => '1', 'prompt_text' => 'x', 'extra' => 'x'], [], 400],
            ['POST', 'application/x-www-form-urlencoded', ['operation' => 'profile_status', 'voice_profile_task_id' => ['1']], [], 400],
            ['POST', 'multipart/form-data; boundary=x', ['operation' => 'profile_prepare', 'profile_name' => 'x', 'consent_type' => 'self_recorded', 'transcript_confirmed' => '1'], ['reference_wav' => []], 400],
            ['PUT', 'application/x-www-form-urlencoded', ['operation' => 'profile_delete', 'voice_profile_task_id' => '1'], [], 405],
        ];
        try {
            foreach ($cases as [$method, $contentType, $post, $files, $expectedStatus]) {
                $_SERVER['CONTENT_TYPE'] = $contentType;
                $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], $post, [], $files, $method);
                $payload = hub_test_audio_payload($response);
                hub_test_assert($response['status'] === $expectedStatus, 'profile validation status mismatch for ' . $method . ' ' . ($post['operation'] ?? ''));
                hub_test_assert(in_array((string)($payload['error'] ?? ''), ['invalid_request', 'method_not_allowed', 'voice_profile_wav_invalid', 'voice_profile_transcript_invalid'], true), 'profile validation errors must stay stable and bounded');
            }
        } finally {
            if (is_file($tmpName)) {
                unlink($tmpName);
            }
        }
    });
});

hub_test('VoxCPM2 voice profile drafts confirm per owner and accept explicit tokens', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Voice Owner');
    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/owner_reference.wav';
    file_put_contents($wav, 'RIFFmock');

    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => '羽山哥技師聲線',
        'reference_audio_path' => $wav,
        'prompt_text' => '今天我們來說明 RC 閥的檢查方式。',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $profile = hub_get_voice_profile_for_member($db, $profileId, $memberId);
    hub_test_assert($profile !== null, 'owner must be able to load profile');
    $otherProfileId = hub_create_voice_profile($db, $otherMemberId, [
        'name' => 'Other private profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $otherProfile = hub_get_voice_profile_for_member($db, $otherProfileId, $otherMemberId);
    hub_test_assert($otherProfile !== null && $otherProfile['reference_audio_sha256'] === $profile['reference_audio_sha256'], 'private profiles must retain same SHA for matching audio');
    hub_test_assert(hub_get_voice_profile_for_member($db, $profileId, $otherMemberId) === null, 'same SHA must not make a private profile visible');
    hub_test_assert(str_starts_with(hub_voice_profile_container_path($profile), '/data/voice_profiles/'), 'voice profile must map to container path');
    hub_test_assert(($profile['prompt_text_confirmed_at'] ?? null) === null, 'draft transcript must start unconfirmed');
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text_confirmed_at'] ?? null) === null, 'later migrations must not confirm new drafts');

    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, '繁中測試');
    hub_test_assert($confirmed['prompt_text'] === '繁中測試', 'confirmation must retain edited transcript');
    hub_test_assert((string)$confirmed['prompt_text_confirmed_at'] !== '', 'confirmation timestamp must be written');
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text_confirmed_at'] ?? null) !== null, 'confirmed transcript must remain confirmed after later migrations');

    $token = hub_create_api_token($db, $memberId, 'TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
    $auth = hub_gateway_authenticate_api_token($db, 'tts', '203.0.113.10', (string)$token['plain_token']);
    hub_test_assert(!empty($auth['ok']) && (int)$auth['context']['member_id'] === $memberId, 'explicit TTS token must use its token member');
    hub_test_assert(!str_contains((string)json_encode($auth), (string)$token['plain_token']), 'auth context must not expose supplied token');
    $emptyToken = hub_gateway_authenticate_api_token($db, 'tts', '127.0.0.1', '');
    hub_test_assert(empty($emptyToken['ok']) && ($emptyToken['response']['status'] ?? 0) === 401, 'explicit empty token must not use localhost bypass');

    $audit = $db->query('SELECT action, details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . ' ORDER BY id ASC')->fetchAll();
    hub_test_assert(array_column($audit, 'action') === ['create', 'confirm_transcript'], 'voice profile create and confirmation must be audited');
    hub_test_assert(!str_contains((string)$audit[1]['details_json'], '繁中測試'), 'transcript contents must not be included in audit details');
    $auditDetails = json_decode((string)$audit[1]['details_json'], true);
    hub_test_assert(($auditDetails['text_chars'] ?? null) === (function_exists('mb_strlen') ? 4 : strlen('繁中測試')), 'confirmation audit must count Traditional Chinese characters correctly');
});

hub_test('VoxCPM2 rolls back confirmation when its audit cannot be written', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirm Audit Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirm_audit_failure.wav';
    file_put_contents($path, 'RIFFconfirm');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirm audit failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $before = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing confirmation audit failure profile.');
    $db->exec("CREATE TRIGGER voice_profile_confirm_audit_failure
        BEFORE INSERT ON voice_profile_audit_logs
        WHEN NEW.voice_profile_id = " . $profileId . " AND NEW.action = 'confirm_transcript'
        BEGIN
            SELECT RAISE(ABORT, 'confirm_audit_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'must not confirm')), 'confirmation audit failure must surface');
        $after = hub_get_voice_profile($db, $profileId);
        $confirmCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'confirm_transcript'")->fetchColumn();
        hub_test_assert($after !== null && $after['prompt_text'] === $before['prompt_text'] && $after['prompt_text_confirmed_at'] === $before['prompt_text_confirmed_at'] && $after['transcription_status'] === $before['transcription_status'] && $after['transcription_error'] === $before['transcription_error'] && $after['transcription_started_at'] === $before['transcription_started_at'] && $after['transcription_lease_token'] === $before['transcription_lease_token'], 'confirmation audit failure must leave transcript, confirmation, status, and lease state unchanged');
        hub_test_assert($confirmCount === 0, 'confirmation audit failure must not leave a confirm audit');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_confirm_audit_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 stores validated WAV uploads as unconfirmed ASR drafts', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Uploaded Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Uploaded draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => '自動字幕', 'language' => 'zh-TW', 'device' => ['effective' => 'cuda']]
        );
    } finally {
        @unlink($tmpName);
    }

    $profile = $result['profile'];
    hub_test_assert($result['cache_hit'] === false, 'new WAV upload must not be a cache hit');
    hub_test_assert($profile['prompt_text'] === '自動字幕', 'successful ASR must save the draft text');
    hub_test_assert($profile['language'] === 'zh-TW', 'successful ASR must save the language');
    hub_test_assert($profile['prompt_text_confirmed_at'] === null, 'ASR text must remain unconfirmed');
    hub_test_assert($profile['transcription_status'] === 'ready' && $profile['transcription_error'] === null, 'successful ASR must mark the draft ready without an error');
    hub_test_assert(hub_voice_profile_safe_host_path((string)$profile['reference_audio_path']) === $profile['reference_audio_path'], 'uploaded WAV must stay in managed storage');

    $indexSql = $db->query("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'idx_voice_profiles_owner_sha_active'")->fetchColumn();
    hub_test_assert(str_contains((string)$indexSql, 'CREATE INDEX') && !str_contains((string)$indexSql, 'CREATE UNIQUE INDEX') && str_contains((string)$indexSql, 'WHERE deleted_at IS NULL'), 'owner SHA cache lookup needs a nonunique active-profile partial index');
    $audit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$profile['id'] . " AND action = 'transcribe'")->fetchColumn();
    hub_test_assert(json_decode((string)$audit, true) === ['status' => 'success', 'device' => ['effective' => 'cuda'], 'text_chars' => 4], 'transcribe audit must contain status, device, and character count only');
});

hub_test('VoxCPM2 keeps duplicate legacy owner SHA profiles during migration', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Legacy Duplicate Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/legacy_duplicate.wav';
    file_put_contents($path, 'RIFFlegacy');
    $firstId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy one',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $secondId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Legacy two',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_migrate($db);
    $sha256 = hash_file('sha256', $path);
    hub_test_assert((int)$db->query('SELECT COUNT(*) FROM voice_profiles WHERE owner_member_id = ' . $memberId . ' AND deleted_at IS NULL')->fetchColumn() === 2, 'legacy duplicate active profiles must remain intact');
    hub_test_assert(hub_find_active_voice_profile_by_owner_sha($db, $memberId, (string)$sha256) !== null, 'legacy duplicate owner SHA must remain searchable');
    hub_test_assert($firstId !== $secondId, 'legacy duplicate fixtures must be distinct profiles');
});

hub_test('VoxCPM2 caches uploaded WAV bytes only for the same owner', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Cache Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Cache Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $moveCalls = 0;
    $asrCalls = 0;
    $move = static function (string $from, string $to) use (&$moveCalls): bool {
        $moveCalls++;
        return copy($from, $to);
    };
    $transcribe = static function () use (&$asrCalls): array {
        $asrCalls++;
        return ['ok' => true, 'text' => 'draft', 'language' => 'en', 'device' => []];
    };

    try {
        $first = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Cached draft', 'consent_type' => 'self_recorded'], $move, $transcribe);
        $again = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Ignored name', 'consent_type' => 'self_recorded'], $move, static fn (): array => throw new RuntimeException('cache hit must not transcribe'));
        $other = hub_create_uploaded_voice_profile($db, $otherMemberId, $upload, ['name' => 'Other draft', 'consent_type' => 'self_recorded'], $move, $transcribe);
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert($again['cache_hit'] === true && (int)$again['profile']['id'] === (int)$first['profile']['id'], 'same owner and bytes must reuse the active profile');
    hub_test_assert($moveCalls === 2 && $asrCalls === 2, 'same-owner ready cache hit must skip staging and ASR');
    hub_test_assert($other['cache_hit'] === false && (int)$other['profile']['owner_member_id'] === $otherMemberId, 'matching bytes must not cross profile ownership');
    hub_test_assert((int)$other['profile']['id'] !== (int)$first['profile']['id'], 'other owner must receive a separate private profile');
    $cacheAudit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$first['profile']['id'] . " AND action = 'cache_hit'")->fetchColumn();
    hub_test_assert(json_decode((string)$cacheAudit, true) === ['status' => 'reused'], 'active cache reuse must be audited without transcript details');
});

hub_test('VoxCPM2 validates profile input before moving an uploaded WAV', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Validation Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $moveCalls = 0;
    $move = static function (string $from, string $to) use (&$moveCalls): bool {
        $moveCalls++;
        return copy($from, $to);
    };

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => '', 'consent_type' => 'self_recorded', 'usage_scope' => 'private', 'visibility' => 'private'], $move)), 'blank profile name must be rejected');
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Invalid consent', 'consent_type' => 'unknown', 'usage_scope' => 'private', 'visibility' => 'private'], $move)), 'invalid profile consent must be rejected');
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert($moveCalls === 0, 'invalid profile input must not leave a managed WAV behind');
});

hub_test('VoxCPM2 reports a pending owner SHA upload without a cache hit', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Pending Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $path = hub_voice_profile_storage_dir() . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    $moveCalls = 0;
    file_put_contents($path, (string)file_get_contents($tmpName));
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Pending draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
        'transcription_status' => 'pending',
    ]);
    hub_test_assert((string)((hub_get_voice_profile($db, $profileId) ?? [])['transcription_started_at'] ?? '') !== '', 'new pending profile must start a transcription lease');

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Second pending upload', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$moveCalls): bool {
                $moveCalls++;
                return copy($from, $to);
            },
            static fn (): array => throw new RuntimeException('pending upload must not transcribe')
        );
        hub_test_assert((glob(hub_voice_profile_storage_dir() . '/voice_profile_stage_' . $memberId . '_*.wav') ?: []) === [], 'pending upload must delete only its staging WAV');
    } finally {
        @unlink($tmpName);
        hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        foreach (glob(hub_voice_profile_storage_dir() . '/voice_profile_stage_' . $memberId . '_*.wav') ?: [] as $staging) {
            @unlink($staging);
        }
    }

    hub_test_assert($result['cache_hit'] === false, 'pending upload must not report a completed cache hit');
    hub_test_assert(($result['transcription']['error'] ?? '') === 'transcription_pending', 'pending upload must return a safe pending status');
    hub_test_assert((int)$result['profile']['id'] === $profileId && $moveCalls === 0, 'pending upload must retain the active profile without staging its duplicate bytes');
});

hub_test('VoxCPM2 reclaims a stale pending owner SHA upload without moving bytes', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Stale Pending Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $path = hub_voice_profile_storage_dir() . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    file_put_contents($path, (string)file_get_contents($tmpName));
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale pending draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
        'transcription_status' => 'pending',
    ]);
    $db->prepare('UPDATE voice_profiles SET transcription_started_at = :started_at WHERE id = :id')
        ->execute([':started_at' => date('Y-m-d H:i:s', time() - 301), ':id' => $profileId]);
    $moveCalls = 0;

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Stale retry upload', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$moveCalls): bool {
                $moveCalls++;
                return copy($from, $to);
            },
            static fn (): array => ['ok' => true, 'text' => 'reclaimed draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($result['cache_hit'] === false && (int)$result['profile']['id'] === $profileId, 'stale pending upload must reclaim the existing profile');
        hub_test_assert($result['profile']['transcription_status'] === 'ready' && $moveCalls === 0, 'stale pending upload must retranscribe managed bytes without staging another file');
    } finally {
        @unlink($tmpName);
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 removes only stale unreferenced generated upload WAVs on a cache miss', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Staging Cleanup Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $cleanupTmpName = tempnam(sys_get_temp_dir(), 'voice-profile-cleanup-');
    if ($cleanupTmpName === false) {
        throw new RuntimeException('Cannot create cleanup WAV fixture.');
    }
    file_put_contents($cleanupTmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . "\0");
    $cleanupUpload = ['tmp_name' => $cleanupTmpName, 'size' => filesize($cleanupTmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $sha256 = hash_file('sha256', $tmpName);
    $dir = hub_voice_profile_storage_dir();
    $path = $dir . '/voice_profile_' . $memberId . '_' . $sha256 . '.wav';
    $oldStaging = $dir . '/voice_profile_stage_99_' . str_repeat('a', 32) . '.wav';
    $freshStaging = $dir . '/voice_profile_stage_99_' . str_repeat('b', 32) . '.wav';
    $oldUnreferencedFinal = $dir . '/voice_profile_99_' . str_repeat('c', 32) . '.wav';
    $activeFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('d', 32) . '.wav';
    $deletedFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('e', 32) . '.wav';
    $freshUnreferencedFinal = $dir . '/voice_profile_99_' . str_repeat('f', 32) . '.wav';
    $oldLegacyFinal = $dir . '/voice_profile_99_' . str_repeat('a', 64) . '.wav';
    file_put_contents($path, (string)file_get_contents($tmpName));
    file_put_contents($oldStaging, 'old');
    file_put_contents($freshStaging, 'fresh');
    file_put_contents($oldUnreferencedFinal, 'old final');
    file_put_contents($activeFinal, 'active final');
    file_put_contents($deletedFinal, 'deleted final');
    file_put_contents($freshUnreferencedFinal, 'fresh final');
    file_put_contents($oldLegacyFinal, 'legacy final');
    $oldAt = time() - hub_voice_profile_transcription_lease_seconds($db) - 1;
    touch($oldStaging, $oldAt);
    touch($oldUnreferencedFinal, $oldAt);
    touch($activeFinal, $oldAt);
    touch($deletedFinal, $oldAt);
    touch($oldLegacyFinal, $oldAt);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Ready cleanup draft',
        'reference_audio_path' => $path,
        'prompt_text' => 'ready',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $activeProfileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Active final cleanup draft',
        'reference_audio_path' => $activeFinal,
        'prompt_text' => 'active',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $deletedProfileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Deleted final cleanup draft',
        'reference_audio_path' => $deletedFinal,
        'prompt_text' => 'deleted',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_soft_delete_voice_profile($db, $deletedProfileId, $memberId);
    $cleanupProfileId = 0;

    try {
        $cached = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Ready cache', 'consent_type' => 'self_recorded'],
            static fn (): bool => throw new RuntimeException('ready cache must not move')
        );
        hub_test_assert($cached['cache_hit'] === true, 'ready profile must remain a cache hit without cleanup');
        hub_test_assert(is_file($oldStaging) && is_file($oldUnreferencedFinal), 'ready cache hit must not run staging or final cleanup');
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $cleanupUpload,
            ['name' => 'Cleanup cache miss', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'cleanup draft', 'language' => 'en', 'device' => []]
        );
        $cleanupProfileId = (int)$result['profile']['id'];
        hub_test_assert($result['cache_hit'] === false, 'cleanup fixture upload must take the no-cache-miss path');
        hub_test_assert(!is_file($oldStaging) && is_file($freshStaging), 'upload entry must remove only stale random staging WAVs');
        hub_test_assert(!is_file($oldUnreferencedFinal), 'upload entry must remove an old unreferenced immutable final WAV');
        hub_test_assert(is_file($activeFinal) && is_file($deletedFinal), 'upload entry must retain old immutable final WAVs referenced by active or soft-deleted profiles');
        hub_test_assert(is_file($freshUnreferencedFinal), 'upload entry must retain a fresh unreferenced immutable final WAV');
        hub_test_assert(is_file($oldLegacyFinal), 'upload entry must not remove arbitrary legacy WAV filenames');
    } finally {
        @unlink($tmpName);
        @unlink($cleanupTmpName);
        @unlink($oldStaging);
        @unlink($freshStaging);
        @unlink($oldUnreferencedFinal);
        @unlink($deletedFinal);
        @unlink($freshUnreferencedFinal);
        @unlink($oldLegacyFinal);
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
        if (hub_get_voice_profile($db, $activeProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $activeProfileId, $memberId, true);
        }
        if ($cleanupProfileId > 0 && hub_get_voice_profile($db, $cleanupProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $cleanupProfileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 final cleanup waits for writer lock before checking final references', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Concurrent Cleanup Voice Owner');
    $concurrentDb = hub_db();
    $concurrentDb->exec('PRAGMA busy_timeout = 0');
    $dir = hub_voice_profile_storage_dir();
    $writerStaging = $dir . '/voice_profile_stage_' . $memberId . '_' . str_repeat('1', 32) . '.wav';
    $protectedFinal = $dir . '/voice_profile_' . $memberId . '_' . str_repeat('2', 32) . '.wav';
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-concurrent-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create concurrent WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 37) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 1) . "\0");
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    file_put_contents($writerStaging, 'RIFFuncommitted');
    touch($writerStaging, time() - hub_voice_profile_transcription_lease_seconds($db) - 1);
    $writerTransactionStarted = false;
    $writerProfileId = 0;
    $uploadProfileId = 0;

    try {
        $db->exec('BEGIN IMMEDIATE');
        $writerTransactionStarted = true;
        hub_test_assert(rename($writerStaging, $protectedFinal), 'writer must rename its final WAV before creating the profile');
        $writerProfileId = hub_create_voice_profile($db, $memberId, [
            'name' => 'Uncommitted writer draft',
            'reference_audio_path' => $protectedFinal,
            'prompt_text' => 'writer',
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
            'visibility' => 'private',
        ]);
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile(
            $concurrentDb,
            $memberId,
            $upload,
            ['name' => 'Concurrent cleanup miss', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'child', 'language' => 'en', 'device' => []]
        )), 'concurrent upload must wait for the writer before final cleanup');
        hub_test_assert(is_file($protectedFinal), 'cleanup must not run before the writer lock can expose its profile reference');

        $db->exec('COMMIT');
        $writerTransactionStarted = false;
        $result = hub_create_uploaded_voice_profile(
            $concurrentDb,
            $memberId,
            $upload,
            ['name' => 'Concurrent cleanup retry', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => true, 'text' => 'child', 'language' => 'en', 'device' => []]
        );
        $uploadProfileId = (int)$result['profile']['id'];
        hub_test_assert(is_file($protectedFinal), 'cleanup after the writer lock must preserve the committed profile WAV');
    } finally {
        if ($writerTransactionStarted) {
            try {
                $db->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        $concurrentDb = null;
        @unlink($tmpName);
        @unlink($writerStaging);
        if ($uploadProfileId > 0 && hub_get_voice_profile($db, $uploadProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $uploadProfileId, $memberId, true);
        }
        if ($writerProfileId > 0 && hub_get_voice_profile($db, $writerProfileId) !== null) {
            hub_soft_delete_voice_profile($db, $writerProfileId, $memberId, true);
        } elseif (is_file($protectedFinal)) {
            unlink($protectedFinal);
        }
    }
});

hub_test('VoxCPM2 reuploads after a profile is soft-deleted', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Reupload Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $asrCalls = 0;
    $transcribe = static function () use (&$asrCalls): array {
        $asrCalls++;
        return ['ok' => true, 'text' => 'draft', 'language' => 'en', 'device' => []];
    };

    $firstPath = null;
    $secondPath = null;
    try {
        $first = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'First upload', 'consent_type' => 'self_recorded'], static fn (string $from, string $to): bool => copy($from, $to), $transcribe);
        $firstPath = (string)$first['profile']['reference_audio_path'];
        hub_soft_delete_voice_profile($db, (int)$first['profile']['id'], $memberId);
        $second = hub_create_uploaded_voice_profile($db, $memberId, $upload, ['name' => 'Second upload', 'consent_type' => 'self_recorded'], static fn (string $from, string $to): bool => copy($from, $to), $transcribe);
        $secondPath = (string)$second['profile']['reference_audio_path'];
        hub_test_assert(is_file($firstPath) && unlink($firstPath), 'delayed old audio cleanup fixture must remove the old WAV');
        hub_test_assert($firstPath !== $secondPath && is_file($secondPath), 'delayed cleanup for a soft-deleted profile must not remove a matching reupload WAV');
    } finally {
        @unlink($tmpName);
        if (isset($second) && hub_get_voice_profile($db, (int)$second['profile']['id']) !== null) {
            hub_soft_delete_voice_profile($db, (int)$second['profile']['id'], $memberId, true);
        }
        if ($firstPath !== null && is_file($firstPath)) {
            unlink($firstPath);
        }
    }

    hub_test_assert($second['cache_hit'] === false && (int)$second['profile']['id'] !== (int)$first['profile']['id'], 'reupload after soft delete must create a new active profile');
    hub_test_assert($asrCalls === 2, 'reupload after soft delete must transcribe the new profile');
});

hub_test('VoxCPM2 removes staged and final WAVs when profile creation fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed Insert Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $dir = hub_voice_profile_storage_dir();
    $finalPattern = $dir . '/voice_profile_' . $memberId . '_*.wav';
    $stagingPattern = $dir . '/voice_profile_stage_' . $memberId . '_*.wav';
    $db->exec("CREATE TRIGGER voice_profile_insert_failure
        BEFORE INSERT ON voice_profiles
        BEGIN
            SELECT RAISE(ABORT, 'profile_insert_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Failed insert', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => throw new RuntimeException('ASR must not run')
        )), 'profile insert failure must surface');
        hub_test_assert((glob($finalPattern) ?: []) === [], 'failed profile insert must remove its final WAV before rollback');
        hub_test_assert((glob($stagingPattern) ?: []) === [], 'failed profile insert must remove this request staging WAV');
    } finally {
        $db->exec('DROP TRIGGER voice_profile_insert_failure');
        @unlink($tmpName);
        foreach (glob($finalPattern) ?: [] as $final) {
            @unlink($final);
        }
        foreach (glob($stagingPattern) ?: [] as $staging) {
            @unlink($staging);
        }
    }
});

hub_test('VoxCPM2 rejects WAV uploads without a RIFF WAVE header', function (): void {
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create invalid WAV fixture.');
    }
    file_put_contents($tmpName, 'RIFF0000NOTWAVE');

    try {
        try {
            hub_validate_voice_profile_wav(['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK]);
            throw new RuntimeException('invalid WAV header must be rejected');
        } catch (InvalidArgumentException) {
        }
    } finally {
        @unlink($tmpName);
    }
});

hub_test('VoxCPM2 retains a Basic Clone profile when ASR transcription fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed ASR Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];

    try {
        $result = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Retryable draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR is unavailable']
        );
    } finally {
        @unlink($tmpName);
    }

    hub_test_assert(($result['transcription']['error'] ?? '') === 'asr_unavailable', 'ASR failure must be returned to the caller');
    hub_test_assert(hub_get_voice_profile_for_member($db, (int)$result['profile']['id'], $memberId) !== null, 'ASR failure must preserve the Basic Clone profile');
    hub_test_assert($result['profile']['prompt_text'] === null && $result['profile']['prompt_text_confirmed_at'] === null, 'failed ASR must leave an unconfirmed empty draft');
    hub_test_assert($result['profile']['transcription_status'] === 'failed' && $result['profile']['transcription_error'] === 'asr_unavailable', 'failed ASR must persist only its safe error code');
    $failureAudit = $db->query('SELECT details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . (int)$result['profile']['id'] . " AND action = 'transcribe'")->fetchColumn();
    hub_test_assert(json_decode((string)$failureAudit, true) === ['status' => 'failed', 'error' => 'asr_unavailable'], 'ASR failure must be audited without transcript details');
});

hub_test('VoxCPM2 retranscribes a failed matching upload without a new profile', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Failed Reupload Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $profileId = 0;
    $retryMoveCalls = 0;

    try {
        $failed = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Failed upload', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable']
        );
        $profileId = (int)$failed['profile']['id'];
        $retried = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Ignored failed retry name', 'consent_type' => 'self_recorded'],
            static function (string $from, string $to) use (&$retryMoveCalls): bool {
                $retryMoveCalls++;
                return copy($from, $to);
            },
            static fn (): array => ['ok' => true, 'text' => 'reupload draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($retried['cache_hit'] === false && (int)$retried['profile']['id'] === $profileId, 'failed matching upload must retry the same profile instead of creating a duplicate');
        hub_test_assert($retried['profile']['transcription_status'] === 'ready' && $retried['profile']['prompt_text'] === 'reupload draft', 'failed matching upload must return its ready retry result');
        hub_test_assert($retryMoveCalls === 0, 'failed matching upload must retry managed bytes without staging another file');
    } finally {
        @unlink($tmpName);
        if ($profileId > 0 && hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 retries a failed owned profile to ready', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Retry Voice Owner');
    $otherMemberId = hub_create_api_member($db, 'Other Retry Voice Owner');
    $tmpName = tempnam(sys_get_temp_dir(), 'voice-profile-');
    if ($tmpName === false) {
        throw new RuntimeException('Cannot create WAV fixture.');
    }
    file_put_contents($tmpName, "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0));
    $upload = ['tmp_name' => $tmpName, 'size' => filesize($tmpName), 'type' => 'audio/wav', 'error' => UPLOAD_ERR_OK];
    $profileId = 0;

    try {
        $failed = hub_create_uploaded_voice_profile(
            $db,
            $memberId,
            $upload,
            ['name' => 'Retryable draft', 'consent_type' => 'self_recorded'],
            static fn (string $from, string $to): bool => copy($from, $to),
            static fn (): array => ['ok' => false, 'error' => 'asr_unavailable', 'message' => 'ASR is unavailable']
        );
        $profileId = (int)$failed['profile']['id'];
        hub_test_assert(hub_test_throws(static fn (): array => hub_retry_voice_profile_transcription($db, $profileId, $otherMemberId)), 'retry must reject a nonowner');
        $retried = hub_retry_voice_profile_transcription(
            $db,
            $profileId,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'retry draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert($retried['profile']['transcription_status'] === 'ready', 'retry must mark the owned profile ready');
        hub_test_assert($retried['profile']['transcription_error'] === null && $retried['profile']['prompt_text'] === 'retry draft', 'retry must clear the safe error and save its new draft');
    } finally {
        @unlink($tmpName);
        if ($profileId > 0 && hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 completes a matching transcription lease normally', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Lease Completion Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/lease_completion.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Lease completion draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing lease profile.');

    try {
        hub_test_assert((string)($claim['transcription_lease_token'] ?? '') !== '', 'pending transcription must claim a lease token');
        $result = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'completed draft', 'language' => 'en', 'device' => []]
        );
        hub_test_assert(($result['transcription']['ok'] ?? false) === true, 'matching transcription lease must complete normally');
        hub_test_assert($result['profile']['transcription_status'] === 'ready' && $result['profile']['transcription_lease_token'] === null && $result['profile']['transcription_started_at'] === null, 'completed transcription must clear its lease state');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 preserves the requested Profile locale over generic ASR detection', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Profile Locale Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/profile_locale.wav';
    file_put_contents($path, 'RIFFlocale');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Profile locale draft',
        'language' => 'zh-TW',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing locale profile.');

    try {
        $result = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => '語言保留測試', 'language' => 'zh', 'device' => []]
        );
        $stored = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing completed locale profile.');

        hub_test_assert(
            ($result['transcription']['language'] ?? '') === 'zh-TW'
            && ($result['profile']['language'] ?? '') === 'zh-TW'
            && ($stored['language'] ?? '') === 'zh-TW',
            'The requested Profile locale must win over generic ASR detection.'
        );
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 rolls back a transcription result when its audit cannot be written', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Audit Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/audit_failure.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Audit failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing audit failure lease profile.');
    $db->exec("CREATE TRIGGER voice_profile_transcribe_audit_failure
        BEFORE INSERT ON voice_profile_audit_logs
        WHEN NEW.voice_profile_id = " . $profileId . " AND NEW.action = 'transcribe'
        BEGIN
            SELECT RAISE(ABORT, 'transcribe_audit_failed');
        END");

    try {
        $result = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'must roll back', 'language' => 'en', 'device' => []]
        );
        $after = hub_get_voice_profile($db, $profileId);
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();
        hub_test_assert(($result['transcription']['error'] ?? '') === 'transcription_save_failed', 'audit failure must return a recoverable transcription save error');
        hub_test_assert($after !== null && $after['transcription_status'] === 'pending' && $after['prompt_text'] === null && $after['transcription_lease_token'] === $claim['transcription_lease_token'] && $after['transcription_started_at'] === $claim['transcription_started_at'], 'audit failure must roll back the fenced transcription state');
        hub_test_assert($transcribeCount === 0, 'audit failure must not leave a transcribe audit or a completed transcription state');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_transcribe_audit_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 fences an old transcription lease after confirmation', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirmed Lease Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirmed_lease.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirmed lease draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldClaim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing old lease profile.');
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'confirmed transcript');

    try {
        $lost = hub_run_voice_profile_transcription(
            $db,
            $oldClaim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'old result', 'language' => 'en', 'device' => []]
        );
        $after = hub_get_voice_profile($db, $profileId);
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();
        hub_test_assert(($lost['transcription']['error'] ?? '') === 'transcription_lost_lease', 'superseded ASR completion must return a safe lost-lease result');
        hub_test_assert($after !== null && $after['transcription_status'] === 'ready' && $after['prompt_text'] === $confirmed['prompt_text'] && $after['prompt_text_confirmed_at'] === $confirmed['prompt_text_confirmed_at'] && $after['transcription_lease_token'] === null && $after['transcription_started_at'] === null, 'old ASR completion must not overwrite a confirmed transcript');
        hub_test_assert($transcribeCount === 0, 'lost ASR completion must not write a transcribe audit');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 soft deletion fences an in-flight transcription lease', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Deleted Lease Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/deleted_lease.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Deleted lease draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $claim = hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('Missing deleted lease profile.');

    try {
        hub_soft_delete_voice_profile($db, $profileId, $memberId);
        $before = $db->query('SELECT transcription_status, transcription_error, transcription_started_at, transcription_lease_token, deleted_at FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        $lost = hub_run_voice_profile_transcription(
            $db,
            $claim,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'late deleted result', 'language' => 'en', 'device' => []]
        );
        $after = $db->query('SELECT transcription_status, transcription_error, transcription_started_at, transcription_lease_token, deleted_at FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        $transcribeCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'transcribe'")->fetchColumn();

        hub_test_assert(is_array($before) && ($before['transcription_status'] ?? null) === 'failed' && array_key_exists('transcription_started_at', $before) && $before['transcription_started_at'] === null && array_key_exists('transcription_lease_token', $before) && $before['transcription_lease_token'] === null && (string)($before['deleted_at'] ?? '') !== '', 'soft delete must invalidate a pending transcription lease');
        hub_test_assert(($lost['transcription']['error'] ?? '') === 'transcription_lost_lease', 'late completion after deletion must return lost lease');
        hub_test_assert($after === $before, 'late completion after deletion must not mutate the deleted profile');
        hub_test_assert($transcribeCount === 0, 'late completion after deletion must not add a transcribe audit');
    } finally {
        @unlink($path);
    }
});

hub_test('VoxCPM2 soft delete keeps audio when the database mutation fails', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Delete Failure Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/delete_db_failure.wav';
    file_put_contents($path, 'RIFFlease');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Delete failure draft',
        'reference_audio_path' => $path,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $db->exec("CREATE TRIGGER voice_profile_delete_failure
        BEFORE UPDATE OF deleted_at ON voice_profiles
        WHEN NEW.deleted_at IS NOT NULL
        BEGIN
            SELECT RAISE(ABORT, 'delete_db_failed');
        END");

    try {
        hub_test_assert(hub_test_throws(static function () use ($db, $profileId, $memberId): void {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }), 'soft delete must surface a database failure');
        hub_test_assert(is_file($path), 'database failure must leave the managed audio intact');
        hub_test_assert(hub_get_voice_profile($db, $profileId) !== null, 'database failure must leave the voice profile active');
    } finally {
        $db->exec('DROP TRIGGER IF EXISTS voice_profile_delete_failure');
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        } else {
            @unlink($path);
        }
    }
});

hub_test('VoxCPM2 failed profile WAV deletion stays scrubbed and retention-retryable', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Delete Retry Voice Owner');
    $dir = hub_voice_profile_storage_dir() . '/delete-retry';
    if (!mkdir($dir, 0700)) {
        throw new RuntimeException('Cannot create delete retry directory.');
    }
    $path = $dir . '/blocked.wav';
    file_put_contents($path, 'RIFFprivate-delete-retry', LOCK_EX);
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Identifying delete retry name',
        'reference_audio_path' => $path,
        'prompt_text' => 'Private delete retry transcript',
        'language' => 'zh-TW',
        'consent_type' => 'self_recorded',
    ]);
    hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'Private delete retry transcript');
    $db->prepare(
        "UPDATE voice_profiles
         SET transcription_error = 'raw private error',
             transcription_started_at = '2026-07-01 00:00:00',
             transcription_lease_token = 'private-lease-token'
         WHERE id = :id"
    )->execute([':id' => $profileId]);
    chmod($dir, 0500);

    try {
        if (is_writable($dir)) {
            hub_test_skip('filesystem permissions cannot simulate unlink failure');
        }
        $deleted = hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        $pending = $db->query('SELECT * FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        hub_test_assert(
            !empty($deleted['audio_cleanup_failed'])
            && is_file($path)
            && $pending !== false
            && !empty($pending['deleted_at'])
            && ($pending['reference_audio_path'] ?? null) === $path
            && ($pending['name'] ?? null) === 'Deleted voice profile'
            && ($pending['reference_audio_sha256'] ?? null) === ''
            && ($pending['prompt_text'] ?? null) === null
            && ($pending['prompt_text_confirmed_at'] ?? null) === null
            && ($pending['language'] ?? null) === null
            && ($pending['transcription_error'] ?? null) === null
            && ($pending['transcription_started_at'] ?? null) === null
            && ($pending['transcription_lease_token'] ?? null) === null
            && (string)($pending['expires_at'] ?? '') !== '',
            'failed unlink must retain only the managed path needed for an expiring retry'
        );

        chmod($dir, 0700);
        $retried = hub_prune_expired_voice_profiles($db, '2099-01-01 00:00:00', 10);
        $finished = $db->query('SELECT reference_audio_path FROM voice_profiles WHERE id = ' . $profileId)->fetch();
        hub_test_assert(
            !file_exists($path)
            && ($finished['reference_audio_path'] ?? null) === ''
            && (int)($retried['audio_purged'] ?? 0) === 1
            && (int)($retried['errors'] ?? 0) === 0,
            'retention must retry the preserved managed path and clear it only after unlink succeeds'
        );
    } finally {
        chmod($dir, 0700);
        @unlink($path);
        @rmdir($dir);
    }
});

hub_test('VoxCPM2 stale lease recovery fences old ready and failed results', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Stale Lease Voice Owner');
    $readyPath = hub_voice_profile_storage_dir() . '/stale_lease_ready.wav';
    $failedPath = hub_voice_profile_storage_dir() . '/stale_lease_failed.wav';
    file_put_contents($readyPath, 'RIFFlease');
    file_put_contents($failedPath, 'RIFFlease');
    $readyId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale ready lease draft',
        'reference_audio_path' => $readyPath,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $failedId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Stale failed lease draft',
        'reference_audio_path' => $failedPath,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldReadyClaim = hub_get_voice_profile($db, $readyId) ?? throw new RuntimeException('Missing stale ready lease.');
    $oldFailedClaim = hub_get_voice_profile($db, $failedId) ?? throw new RuntimeException('Missing stale failed lease.');
    $db->prepare('UPDATE voice_profiles SET transcription_started_at = :started_at WHERE id IN (:ready_id, :failed_id)')
        ->execute([':started_at' => date('Y-m-d H:i:s', time() - 301), ':ready_id' => $readyId, ':failed_id' => $failedId]);
    $newReadyToken = null;
    $newFailedToken = null;

    try {
        $newReady = hub_retry_voice_profile_transcription(
            $db,
            $readyId,
            $memberId,
            static function () use ($db, $readyId, &$newReadyToken): array {
                $newReadyToken = (string)((hub_get_voice_profile($db, $readyId) ?? [])['transcription_lease_token'] ?? '');
                return ['ok' => true, 'text' => 'new ready', 'language' => 'en', 'device' => []];
            }
        );
        $newFailed = hub_retry_voice_profile_transcription(
            $db,
            $failedId,
            $memberId,
            static function () use ($db, $failedId, &$newFailedToken): array {
                $newFailedToken = (string)((hub_get_voice_profile($db, $failedId) ?? [])['transcription_lease_token'] ?? '');
                return ['ok' => false, 'error' => 'asr_unavailable'];
            }
        );
        $oldReady = hub_run_voice_profile_transcription($db, $oldReadyClaim, $memberId, static fn (): array => ['ok' => false, 'error' => 'asr_unavailable']);
        $oldFailed = hub_run_voice_profile_transcription($db, $oldFailedClaim, $memberId, static fn (): array => ['ok' => true, 'text' => 'old ready', 'language' => 'en', 'device' => []]);
        $readyAfter = hub_get_voice_profile($db, $readyId);
        $failedAfter = hub_get_voice_profile($db, $failedId);
        $readyAuditCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $readyId . " AND action = 'transcribe'")->fetchColumn();
        $failedAuditCount = (int)$db->query('SELECT COUNT(*) FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $failedId . " AND action = 'transcribe'")->fetchColumn();

        hub_test_assert($newReadyToken !== '' && $newReadyToken !== (string)($oldReadyClaim['transcription_lease_token'] ?? ''), 'stale recovery must atomically replace the ready lease token');
        hub_test_assert($newFailedToken !== '' && $newFailedToken !== (string)($oldFailedClaim['transcription_lease_token'] ?? ''), 'stale recovery must atomically replace the failed lease token');
        hub_test_assert(($newReady['transcription']['ok'] ?? false) === true && ($newFailed['transcription']['error'] ?? '') === 'asr_unavailable', 'new lease completions must retain their normal results');
        hub_test_assert(($oldReady['transcription']['error'] ?? '') === 'transcription_lost_lease' && ($oldFailed['transcription']['error'] ?? '') === 'transcription_lost_lease', 'old lease completions must be fenced after recovery');
        hub_test_assert($readyAfter !== null && $readyAfter['transcription_status'] === 'ready' && $readyAfter['prompt_text'] === 'new ready', 'old failed lease must not overwrite newer ready state');
        hub_test_assert($failedAfter !== null && $failedAfter['transcription_status'] === 'failed' && $failedAfter['transcription_error'] === 'asr_unavailable' && $failedAfter['prompt_text'] === null, 'old successful lease must not overwrite newer failed state');
        hub_test_assert($readyAuditCount === 1 && $failedAuditCount === 1, 'lost lease completions must not add success or failure audits');
    } finally {
        if (hub_get_voice_profile($db, $readyId) !== null) {
            hub_soft_delete_voice_profile($db, $readyId, $memberId, true);
        }
        if (hub_get_voice_profile($db, $failedId) !== null) {
            hub_soft_delete_voice_profile($db, $failedId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 retry leaves a confirmed ready profile untouched', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Confirmed Retry Voice Owner');
    $path = hub_voice_profile_storage_dir() . '/confirmed_retry.wav';
    file_put_contents($path, 'RIFFconfirmed');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Confirmed retry draft',
        'reference_audio_path' => $path,
        'prompt_text' => 'initial draft',
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $memberId, 'confirmed transcript');

    try {
        hub_test_assert(hub_test_throws(static fn (): array => hub_retry_voice_profile_transcription(
            $db,
            $profileId,
            $memberId,
            static fn (): array => ['ok' => true, 'text' => 'must not replace', 'language' => 'en', 'device' => []]
        )), 'retry must reject a ready profile');
        $after = hub_get_voice_profile($db, $profileId);
        hub_test_assert($after !== null && $after['prompt_text'] === $confirmed['prompt_text'] && $after['prompt_text_confirmed_at'] === $confirmed['prompt_text_confirmed_at'], 'ready retry rejection must preserve confirmed transcript text and timestamp');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 test reset clears managed voice profile WAVs', function (): void {
    $dir = hub_voice_profile_storage_dir();
    $productionDir = HUB_DATA_DIR . '/uploads/voice_profiles';
    $finalPath = $dir . '/voice_profile_99_' . str_repeat('a', 64) . '.wav';
    $stagingPath = $dir . '/voice_profile_stage_99_' . str_repeat('b', 32) . '.wav';
    file_put_contents($finalPath, 'final');
    file_put_contents($stagingPath, 'staging');

    try {
        hub_test_reset_db();
        hub_test_assert($dir !== $productionDir, 'test voice profile storage must not use the production upload directory');
        hub_test_assert(preg_match('/^3waaihub_test_voice_profiles_[a-f0-9]{32}$/', basename($dir)) === 1, 'test voice profile storage must use a random directory name');
        if (DIRECTORY_SEPARATOR !== '\\') {
            hub_test_assert((fileperms($dir) & 0777) === 0700, 'test voice profile storage must be private');
        }
        hub_test_assert((glob($dir . '/*.wav') ?: []) === [], 'test reset must clear all managed voice profile WAVs');
    } finally {
        @unlink($finalPath);
        @unlink($stagingPath);
    }
});

hub_test('VoxCPM2 guarded final test storage teardown removes only its requested root', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_voice_profile_teardown_' . bin2hex(random_bytes(8));
    $sibling = $root . '_sibling';
    if (!mkdir($root, 0700) || !mkdir($sibling, 0700)) {
        throw new RuntimeException('Cannot create test teardown fixtures.');
    }
    $rootWav = $root . '/owned.wav';
    $siblingWav = $sibling . '/other.wav';
    $snapshotDir = $root . '/.snapshots';
    $snapshotWav = $snapshotDir . '/voice_profile_snapshot_' . str_repeat('a', 32) . '.wav';
    file_put_contents($rootWav, 'RIFFowned');
    file_put_contents($siblingWav, 'RIFFother');
    if (!mkdir($snapshotDir, 0700) || file_put_contents($snapshotWav, 'RIFFsnapshot') === false) {
        throw new RuntimeException('Cannot create test snapshot fixture.');
    }

    try {
        hub_test_remove_voice_profile_storage_dir($root);
        hub_test_assert(!file_exists($root), 'guarded teardown must remove its requested isolated root');
        hub_test_assert(is_file($siblingWav) && file_get_contents($siblingWav) === 'RIFFother', 'guarded teardown must leave neighboring temporary paths untouched');
    } finally {
        @unlink($rootWav);
        @rmdir($root);
        @unlink($siblingWav);
        @rmdir($sibling);
    }
});

hub_test('VoxCPM2 test reset refuses a symlinked voice profile directory', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_voice_profile_symlink_' . bin2hex(random_bytes(8));
    $link = $root . '_link';
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create symlink target fixture.');
    }
    $targetWav = $root . '/target.wav';
    file_put_contents($targetWav, 'RIFFtarget');
    if (!@symlink($root, $link)) {
        @unlink($targetWav);
        @rmdir($root);
        hub_test_skip('Symlink fixture is unavailable on this host.');
    }

    try {
        hub_test_assert(hub_test_throws(static fn (): string => hub_test_voice_profile_cleanup_dir($link)), 'test reset must refuse a symlinked voice profile directory');
        hub_test_assert(is_file($targetWav) && file_get_contents($targetWav) === 'RIFFtarget', 'symlink cleanup refusal must not delete the target WAV');
    } finally {
        @unlink($link);
        @unlink($targetWav);
        @rmdir($root);
    }
});

hub_test('VoxCPM2 test reset preserves production voice profile WAVs', function (): void {
    $productionDir = HUB_DATA_DIR . '/uploads/voice_profiles';
    if (!is_dir($productionDir) && !mkdir($productionDir, 0775, true) && !is_dir($productionDir)) {
        throw new RuntimeException('Cannot create production voice profile fixture directory.');
    }
    $productionPath = $productionDir . '/non_test_voice_profile_reset_guard.wav';
    file_put_contents($productionPath, 'RIFFproduction');

    try {
        hub_test_reset_db();
        hub_test_assert(hub_voice_profile_storage_dir() !== $productionDir, 'test storage override must remain separate from production uploads');
        hub_test_assert(is_file($productionPath) && file_get_contents($productionPath) === 'RIFFproduction', 'test reset must never delete a production voice profile WAV');
    } finally {
        @unlink($productionPath);
    }
});

hub_test('VoxCPM2 migrates legacy transcripts once without overwriting confirmation', function (): void {
    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Legacy Voice Owner');
    $db->exec('DROP TABLE voice_profile_audit_logs');
    $db->exec('DROP TABLE voice_profiles');
    $db->exec('CREATE TABLE voice_profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        owner_member_id INTEGER NOT NULL,
        reference_audio_sha256 TEXT NOT NULL,
        prompt_text TEXT NULL,
        prompt_text_confirmed_at TEXT NULL,
        transcription_error TEXT NULL,
        deleted_at TEXT NULL,
        updated_at TEXT NOT NULL
    )');
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, prompt_text, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :prompt_text, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('a', 64),
            ':prompt_text' => '既有逐字稿',
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, transcription_error, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :transcription_error, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('b', 64),
            ':transcription_error' => 'asr_unavailable',
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('INSERT INTO voice_profiles (owner_member_id, reference_audio_sha256, updated_at) VALUES (:owner_member_id, :reference_audio_sha256, :updated_at)')
        ->execute([
            ':owner_member_id' => $memberId,
            ':reference_audio_sha256' => str_repeat('c', 64),
            ':updated_at' => '2000-01-01 00:00:00',
        ]);
    $db->prepare('DELETE FROM settings WHERE key = :key')
        ->execute([':key' => 'db_migration_voice_profiles_prompt_text_confirmed_at_v1']);
    $db->prepare('DELETE FROM settings WHERE key = :key')
        ->execute([':key' => 'db_migration_voice_profiles_transcription_state_v1']);
    $db->exec("CREATE TRIGGER voice_profile_confirmation_marker_failure
        BEFORE INSERT ON settings
        WHEN NEW.key = 'db_migration_voice_profiles_prompt_text_confirmed_at_v1'
        BEGIN
            SELECT RAISE(ABORT, 'marker_write_failed');
        END");
    hub_test_assert(hub_test_throws(static fn () => hub_migrate($db)), 'marker write failure must be surfaced');
    hub_test_assert((hub_get_voice_profile($db, 1)['prompt_text_confirmed_at'] ?? null) === null, 'marker write failure must roll back transcript confirmation');
    $db->exec('DROP TRIGGER voice_profile_confirmation_marker_failure');

    hub_migrate($db);
    $legacy = hub_get_voice_profile($db, 1);
    hub_test_assert(($legacy['prompt_text_confirmed_at'] ?? null) === '2000-01-01 00:00:00', 'legacy nonempty transcript must migrate as confirmed');
    hub_test_assert(array_key_exists('transcription_lease_token', $legacy ?? []), 'legacy voice profiles must migrate the nullable transcription lease token');
    hub_test_assert(hub_get_storage_setting($db, 'db_migration_voice_profiles_prompt_text_confirmed_at_v1') === '1', 'successful retry must mark transcript migration complete');
    hub_test_assert(($legacy['transcription_status'] ?? null) === 'ready', 'legacy transcript must migrate to ready');
    hub_test_assert((hub_get_voice_profile($db, 2)['transcription_status'] ?? null) === 'failed', 'recorded legacy transcription failure must migrate to failed');
    hub_test_assert((hub_get_voice_profile($db, 3)['transcription_status'] ?? null) === 'pending', 'unknown legacy transcription state must migrate to pending');
    $db->prepare('UPDATE voice_profiles SET prompt_text_confirmed_at = :confirmed_at WHERE id = 1')
        ->execute([':confirmed_at' => '2001-01-01 00:00:00']);
    hub_migrate($db);
    hub_test_assert((hub_get_voice_profile($db, 1)['prompt_text_confirmed_at'] ?? null) === '2001-01-01 00:00:00', 'migration must not overwrite existing confirmation');
});

hub_test('VoxCPM2 install generates GPU compose storage env and gateway contract', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-main',
        'mode' => 'tts',
        'name' => 'VoxCPM2 TTS Main',
        'port_mode' => 'manual',
        'local_port' => 18108,
    ]);

    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    $env = (string)file_get_contents(dirname(hub_path($installed['service']['compose_file'])) . '/runtime-settings.conf');
    hub_test_assert(str_contains($compose, '127.0.0.1:${TTS_LOCAL_PORT:-18108}:8000'), 'VoxCPM2 compose port binding mismatch');
    hub_test_assert(str_contains($compose, 'gpus: all'), 'VoxCPM2 compose must request GPU');
    hub_test_assert(str_contains($compose, '${AIHUB_MODELS_DIR}/voxcpm2:/models/voxcpm2'), 'VoxCPM2 compose must mount model storage');
    hub_test_assert(str_contains($compose, '${AIHUB_CACHE_DIR}/voxcpm2:/cache/voxcpm2'), 'VoxCPM2 compose must mount cache storage');
    hub_test_assert(str_contains($compose, '${SERVICE_DATA_DIR}:/data/service'), 'VoxCPM2 compose must mount service data');
    hub_test_assert(str_contains($compose, '${AIHUB_UPLOADS_DIR}/voice_profiles:/data/voice_profiles:ro'), 'VoxCPM2 compose must mount managed voice profiles read-only');
    foreach ([
        'VOXCPM2_MODEL_DIR=/models/voxcpm2',
        'VOXCPM2_CACHE_DIR=/cache/voxcpm2',
        'VOXCPM2_SERVICE_DATA_DIR=/data/service',
        'VOXCPM2_MODEL_ID=openbmb/VoxCPM2',
        'VOXCPM2_SAMPLE_RATE=48000',
        'VOXCPM2_DEFAULT_SEED=42',
        'VOXCPM2_REAL_INFERENCE=0',
        'VOXCPM2_TORCH_COMPILE=0',
        'VOXCPM2_GPU_POLICY=exclusive_gpu',
        'VOXCPM2_EXECUTION_MODE=isolated',
        'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB=1024',
        'VOXCPM2_IDLE_UNLOAD_SECONDS=0',
    ] as $needle) {
        hub_test_assert(str_contains($env, $needle), 'VoxCPM2 env missing ' . $needle);
    }

    hub_set_service_enabled($db, 'tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');
    $oldServer = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $response = hub_gateway_dispatch($db, 'tts', static function (array $service, int $timeoutSec): array {
            hub_test_assert($service['mode'] === 'tts', 'TTS gateway service mismatch');
            hub_test_assert($timeoutSec === 180, 'TTS timeout mismatch');

            return hub_gateway_json(200, [
                'success' => true,
                'artifact_url' => '/artifacts/mock.wav',
                'sample_rate' => 48000,
                'duration_ms' => 8640,
                'model' => 'VoxCPM2',
                'seed' => 42,
            ]);
        });
    } finally {
        $_SERVER = $oldServer;
    }
    hub_test_assert($response['status'] === 200, 'TTS gateway mock should pass');
});

hub_test('VoxCPM2 gateway rewrites clone profile IDs without exposing host paths', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-main',
        'mode' => 'tts',
        'name' => 'VoxCPM2 TTS Main',
        'port_mode' => 'manual',
        'local_port' => 18108,
    ]);
    $memberId = hub_create_api_member($db, 'Clone Member');
    $token = hub_create_api_token($db, $memberId, 'TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts', (int)$installed['service']['id']);
    hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
    hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
    hub_set_service_enabled($db, 'tts', true);
    hub_update_service_status($db, (int)$installed['service']['id'], 'running');

    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/clone_reference.wav';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Clone profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'explicit_permission',
        'usage_scope' => 'private',
    ]);

    $payload = [
        'mode' => 'clone',
        'text' => 'RC 閥的調整方式如下。',
        'reference_audio_id' => 'voice_profile_' . $profileId,
        'control' => '沉穩、稍慢、像技師解說',
        'seed' => 42,
        'format' => 'wav',
    ];
    $prepared = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], [
        'member_id' => $memberId,
        'token_id' => (int)$token['token_id'],
    ], json_encode($payload, JSON_UNESCAPED_UNICODE));
    hub_test_assert(($prepared['error'] ?? null) === null, 'clone payload should prepare');
    $body = json_decode((string)$prepared['body'], true);
    hub_test_assert(($body['reference_wav_path'] ?? '') === '/data/voice_profiles/clone_reference.wav', 'clone must use mapped container path');
    hub_test_assert(!str_contains((string)$prepared['body'], HUB_ROOT), 'clone payload must not expose host path');
    hub_test_assert(!isset($body['reference_audio_id']), 'public reference ID must not be forwarded');

    $actions = $db->query('SELECT action FROM voice_profile_audit_logs ORDER BY id DESC LIMIT 1')->fetchColumn();
    hub_test_assert($actions === 'use', 'clone use must be audited');

    $blocked = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], [], json_encode($payload, JSON_UNESCAPED_UNICODE));
    hub_test_assert(($blocked['response']['status'] ?? 0) === 403, 'clone without token member must be rejected');
});

hub_test('VoxCPM2 gateway injects only confirmed Ultimate Clone prompts', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-ultimate',
        'mode' => 'tts',
        'name' => 'VoxCPM2 Ultimate Clone',
        'enabled' => 1,
    ]);
    $memberId = hub_create_api_member($db, 'Ultimate Clone Owner');
    $token = hub_create_api_token($db, $memberId, 'Ultimate Clone token', null, null);
    $dir = hub_voice_profile_storage_dir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create voice profile test dir.');
    }
    $wav = $dir . '/ultimate_reference.wav';
    $promptText = '已確認的私密參考字幕';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $memberId, [
        'name' => 'Ultimate profile',
        'reference_audio_path' => $wav,
        'prompt_text' => $promptText,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $payload = [
        'mode' => 'ultimate_clone',
        'text' => '要說的內容。',
        'voice_profile_id' => $profileId,
        'format' => 'wav',
    ];
    $context = ['member_id' => $memberId, 'token_id' => (int)$token['token_id']];

    try {
        $unconfirmed = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $unconfirmedBody = json_decode((string)($unconfirmed['response']['body'] ?? ''), true);
        hub_test_assert(($unconfirmed['response']['status'] ?? 0) === 409 && ($unconfirmedBody['error'] ?? '') === 'voice_profile_transcript_unconfirmed', 'ultimate clone must reject an unconfirmed transcript');

        hub_confirm_voice_profile_prompt($db, $profileId, $memberId, $promptText);
        $prepared = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $body = json_decode((string)($prepared['body'] ?? ''), true);
        hub_test_assert(($body['reference_wav_path'] ?? '') === '/data/voice_profiles/ultimate_reference.wav', 'ultimate clone must map the managed reference WAV');
        hub_test_assert(($body['prompt_wav_path'] ?? '') === ($body['reference_wav_path'] ?? ''), 'ultimate clone must use the same managed prompt WAV');
        hub_test_assert(($body['prompt_text'] ?? '') === $promptText, 'ultimate clone must inject the confirmed transcript');
        hub_test_assert(!str_contains((string)$prepared['body'], HUB_ROOT), 'ultimate clone must not expose host paths');

        foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path', 'prompt_text'] as $forgedKey) {
            $forged = $payload;
            $forged[$forgedKey] = $forgedKey === 'prompt_text' ? 'forged transcript' : '/tmp/forged.wav';
            $blocked = hub_prepare_tts_voxcpm2_payload($db, $installed['service'], $context, json_encode($forged, JSON_UNESCAPED_UNICODE));
            hub_test_assert(($blocked['response']['status'] ?? 0) === 400, 'gateway must reject forged ' . $forgedKey);
        }

        $audit = $db->query('SELECT action, mode, details_json FROM voice_profile_audit_logs WHERE voice_profile_id = ' . $profileId . " AND action = 'use' ORDER BY id DESC LIMIT 1")->fetch();
        hub_test_assert(($audit['mode'] ?? '') === 'ultimate_clone', 'ultimate clone use must be audited by mode');
        hub_test_assert(!str_contains((string)($audit['details_json'] ?? ''), $promptText), 'ultimate transcript must not enter audit metadata');
        $app = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/app.py');
        hub_test_assert(preg_match('/def manifest_payload\(.*?\n\n/s', $app, $manifestMatch) === 1 && !str_contains($manifestMatch[0], 'prompt_text'), 'TTS artifact manifest must not contain the prompt transcript');
        hub_test_assert(str_contains($app, 'kwargs["prompt_wav_path"] = str(prompt)') && str_contains($app, 'kwargs["prompt_text"] = request.prompt_text.strip()'), 'Ultimate Clone must pass managed prompt inputs to VoxCPM2');
        hub_test_assert(str_contains($app, '@app.exception_handler(RequestValidationError)') && !str_contains($app, 'str(exc).splitlines()'), 'TTS errors must not echo the internal prompt transcript');
    } finally {
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $memberId, true);
        }
    }
});

hub_test('VoxCPM2 appears in customer playground when user and token allow tts', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'service_key' => 'voxcpm2-auth-playground',
        'mode' => 'tts',
        'name' => 'VoxCPM2 Auth Playground',
        'port_mode' => 'manual',
        'local_port' => 18283,
    ]);
    $customerId = hub_create_customer_user($db, [
        'username' => 'tts_playground',
        'password' => 'customer123',
        'modes' => ['tts'],
    ]);
    $customer = hub_get_user($db, $customerId);
    $token = hub_create_api_token($db, (int)$customer['api_member_id'], 'Own TTS', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts', (int)$installed['service']['id']);

    hub_test_assert(in_array('tts', hub_playground_supported_modes(), true), 'playground supported modes must include tts');
    $modes = array_map(static fn (array $service): string => (string)$service['mode'], hub_playground_service_options($db, $customer));
    hub_test_assert($modes === ['tts'], 'customer playground must show tts when user and own token allow it');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach (['api.php?mode=tts', 'voice_prompt', 'voice_profile_id', 'compare_all', 'ultimate_clone'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground TTS UI missing ' . $needle);
    }
    hub_test_assert(!str_contains($source, 'name="reference_audio_id"'), 'playground must not accept arbitrary voice profile IDs');
    $ttsStart = strpos($source, "\$selectedMode === 'tts'):");
    hub_test_assert($ttsStart !== false, 'playground TTS branch missing');
    $ttsEnd = strpos($source, "<?php elseif (in_array(\$selectedMode, ['ocr', 'yolo'], true)):", $ttsStart);
    hub_test_assert($ttsEnd !== false, 'playground TTS branch end missing');
    $ttsBranch = substr($source, $ttsStart, $ttsEnd - $ttsStart);
    hub_test_assert(str_contains($ttsBranch, 'name="real_inference" type="checkbox" value="1" checked'), 'playground TTS real inference must be checked by default');
});

hub_test('VoxCPM2 playground provides managed three-mode comparison controls', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');

    foreach (['value="ultimate_clone"', 'name="compare_all"', 'name="reference_wav"', 'name="prompt_text"', 'name="voice_profile_id"', '$audioUrls'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground TTS comparison UI missing ' . $needle);
    }
    hub_test_assert(!str_contains($source, 'name="reference_audio_id"'), 'playground must not expose a free-form reference audio ID');

    $examplesStart = strpos($source, 'function hub_playground_examples');
    $ttsExamplesStart = $examplesStart === false ? false : strpos($source, "if (\$mode === 'tts') {", $examplesStart);
    $ttsExamplesEnd = strpos($source, "if (\$mode === 'chat') {", $ttsExamplesStart);
    hub_test_assert($ttsExamplesStart !== false && $ttsExamplesEnd !== false, 'playground TTS example block missing');
    $ttsExamples = substr($source, $ttsExamplesStart, $ttsExamplesEnd - $ttsExamplesStart);
    foreach (['prompt_text', 'reference_wav_path', 'prompt_wav_path', 'reference_audio_path'] as $forbidden) {
        hub_test_assert(!str_contains($ttsExamples, $forbidden), 'browser TTS examples must not expose ' . $forbidden);
    }
    $helper = (string)file_get_contents(HUB_ROOT . '/admin/_playground_voice_profiles.php');
    hub_test_assert(str_contains($source, 'hub_playground_tts_audio_urls($selectedService, $result)') && str_contains($helper, 'hub_playground_tts_audio_url'), 'comparison audio must use the protected artifact URL helper');
});

hub_test('VoxCPM2 playground lists only active profiles accessible to the bearer token member', function (): void {
    hub_test_assert(function_exists('hub_playground_tts_active_profiles'), 'playground active profile selector helper missing');

    $db = hub_test_reset_db();
    $viewerId = hub_create_api_member($db, 'Playground profile viewer');
    $ownerId = hub_create_api_member($db, 'Playground profile owner');
    $token = hub_create_api_token($db, $viewerId, 'Playground profile viewer TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    $wav = hub_voice_profile_storage_dir() . '/playground_selector.wav';
    file_put_contents($wav, 'RIFFselector');
    $ownId = hub_create_voice_profile($db, $viewerId, [
        'name' => 'Viewer active',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $sharedId = hub_create_voice_profile($db, $ownerId, [
        'name' => 'Shared active',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'shared',
    ]);
    $privateId = hub_create_voice_profile($db, $ownerId, [
        'name' => 'Private foreign',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $deletedId = hub_create_voice_profile($db, $viewerId, [
        'name' => 'Viewer deleted',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    hub_soft_delete_voice_profile($db, $deletedId, $viewerId);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.38';

    try {
        $profiles = hub_playground_tts_active_profiles($db, (string)$token['plain_token']);
        $managementProfiles = hub_playground_tts_owned_profiles($db, (string)$token['plain_token']);
    } finally {
        $_SERVER = $oldServer;
    }

    $ids = array_map(static fn (array $profile): int => (int)$profile['id'], $profiles);
    sort($ids);
    $expected = [$ownId, $sharedId];
    sort($expected);
    hub_test_assert($ids === $expected, 'profile selector must include only active owned or shared profiles');
    hub_test_assert(!in_array($privateId, $ids, true) && !in_array($deletedId, $ids, true), 'profile selector must not expose private foreign or deleted profiles');
    hub_test_assert(array_map(static fn (array $profile): int => (int)$profile['id'], $managementProfiles) === [$ownId], 'management selectors must exclude shared profiles because their mutations are owner-only');
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, 'foreach ($ttsProfiles as $ttsProfile)') && substr_count($source, 'foreach ($ttsManagementProfiles as $ttsProfile)') === 2, 'execution and management selectors must use their distinct profile lists');
});

hub_test('VoxCPM2 playground returns a safe error when loading profiles with an invalid token', function (): void {
    hub_test_assert(function_exists('hub_playground_tts_profile_options_result'), 'playground profile-load action helper missing');

    $db = hub_test_reset_db();
    $result = hub_playground_tts_profile_options_result($db, 'invalid-playground-token');
    hub_test_assert(empty($result['ok']) && ($result['error'] ?? '') === 'voice_profile_request_failed', 'invalid profile load must return a generic action error');
    foreach (['invalid-playground-token', HUB_ROOT, 'reference_audio_path'] as $secret) {
        hub_test_assert(!str_contains((string)($result['pretty_body'] ?? ''), $secret), 'profile-load error must not expose token or storage details');
    }

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, "\$action === 'voice_profile_list'") && str_contains($source, 'hub_playground_tts_profile_options_result'), 'profile-load action must render the safe helper result');
});

hub_test('VoxCPM2 playground compares all modes sequentially and keeps each result', function (): void {
    hub_test_assert(function_exists('hub_playground_execute_tts'), 'playground TTS comparison executor missing');
    hub_test_assert(function_exists('hub_playground_tts_audio_urls'), 'playground TTS audio URL mapper missing');

    $oldPost = $_POST;
    $_POST = [
        'tts_mode' => 'clone',
        'compare_all' => '1',
        'text' => '比較三個聲音模式。',
        'voice_prompt' => '清楚自然',
        'voice_profile_id' => '42',
        'control' => '稍慢',
        'seed' => '42',
        'real_inference' => '1',
    ];
    $order = [];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode, array $payload, string $token) use (&$order): array {
            $expected = ['design', 'clone', 'ultimate_clone'];
            hub_test_assert($ttsMode === $expected[count($order)], 'comparison must wait for the preceding mode before the next request');
            hub_test_assert($payload['mode'] === $ttsMode, 'each comparison request must contain its own mode');
            hub_test_assert(($payload['voice_profile_id'] ?? null) === 42, 'clone requests must use the selected managed profile ID');
            hub_test_assert(!array_key_exists('prompt_text', $payload), 'playground must not send profile transcripts to the gateway');
            hub_test_assert($token === 'test-token', 'comparison must keep the transient bearer token server-side');
            $order[] = $ttsMode;

            return [
                'ok' => true,
                'status' => 200,
                'elapsed_ms' => 1,
                'request_id' => $ttsMode,
                'error' => '',
                'message' => '',
                'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']),
                'pretty_body' => '{}',
            ];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert($order === ['design', 'clone', 'ultimate_clone'], 'comparison must invoke modes in the documented sequence');
    hub_test_assert(array_keys($result['results'] ?? []) === ['design', 'clone', 'ultimate_clone'], 'comparison must retain independent results for every mode');
    $audioUrls = hub_playground_tts_audio_urls(['id' => 9], $result, static function (array $service, ?array $ttsResult): string {
        $artifact = json_decode((string)($ttsResult['body'] ?? ''), true);
        return 'playground_artifact.php?service_id=' . (int)$service['id'] . '&file=' . basename((string)($artifact['artifact_url'] ?? ''));
    });
    hub_test_assert(array_keys($audioUrls) === ['design', 'clone', 'ultimate_clone'], 'comparison must retain independent audio URLs for every mode');
    foreach ($audioUrls as $ttsMode => $audioUrl) {
        hub_test_assert(str_contains($audioUrl, 'playground_artifact.php?') && str_contains($audioUrl, 'file=tts_' . $ttsMode . '.wav'), 'comparison audio URL must use the protected artifact endpoint');
    }
});

hub_test('VoxCPM2 playground maps single TTS artifacts through the protected endpoint', function (): void {
    $artifactHelpers = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
    hub_test_assert(is_file($artifactHelpers), 'side-effect-free TTS artifact helpers missing');
    hub_test_assert(function_exists('hub_playground_tts_audio_url'), 'protected TTS artifact URL helper missing');

    $service = ['id' => 9];
    $result = ['ok' => true, 'body' => json_encode(['artifact_url' => '/artifacts/tts_design.wav'])];
    hub_test_assert(hub_playground_tts_audio_url($service, $result) === 'playground_artifact.php?service_id=9&file=tts_design.wav', 'single TTS artifact must use the protected endpoint URL');

    foreach (['https://example.test/tts_design.wav', '/artifacts/tts_design.mp3'] as $unsafeArtifactUrl) {
        hub_test_assert(hub_playground_tts_audio_url($service, ['ok' => true, 'body' => json_encode(['artifact_url' => $unsafeArtifactUrl])]) === '', 'unsafe TTS artifact URL must be rejected');
    }
});

hub_test('VoxCPM2 playground keeps the protected single-result audio fallback', function (): void {
    $oldPost = $_POST;
    $_POST = ['tts_mode' => 'design'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            return [
                'ok' => true,
                'status' => 200,
                'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']),
                'pretty_body' => '{}',
            ];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(!array_key_exists('results', $result), 'single TTS execution must retain its direct gateway result');
    hub_test_assert(hub_playground_tts_audio_urls(['id' => 9], $result, static function (): string {
        throw new RuntimeException('single TTS result must not use the comparison audio mapper');
    }) === [], 'single TTS execution must leave comparison audio URLs empty');

    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    hub_test_assert(str_contains($source, 'hub_playground_tts_audio_url($selectedService, $result)'), 'single TTS result must use the protected artifact fallback');
    $singleAudioStart = strpos($source, "elseif (\$audioUrl !== '')");
    $singleAudioEnd = $singleAudioStart === false ? false : strpos($source, '<?php endif; ?>', $singleAudioStart);
    $singleAudio = $singleAudioStart === false || $singleAudioEnd === false ? '' : substr($source, $singleAudioStart, $singleAudioEnd - $singleAudioStart);
    hub_test_assert(str_contains($singleAudio, '<audio controls src="<?= hub_h($audioUrl) ?>"></audio>'), 'single TTS result must render its protected audio player');
});

hub_test('VoxCPM2 playground preserves an unconfirmed Ultimate Clone result', function (): void {
    $oldPost = $_POST;
    $_POST = ['compare_all' => '1', 'voice_profile_id' => '42'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            if ($ttsMode === 'ultimate_clone') {
                return ['ok' => false, 'status' => 409, 'error' => 'voice_profile_transcript_unconfirmed', 'message' => 'confirmed transcript required', 'pretty_body' => '{}'];
            }

            return ['ok' => true, 'status' => 200, 'body' => json_encode(['artifact_url' => '/artifacts/tts_' . $ttsMode . '.wav']), 'pretty_body' => '{}'];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(($result['results']['design']['ok'] ?? false) === true && ($result['results']['clone']['ok'] ?? false) === true, 'successful design and clone results must remain available');
    hub_test_assert(($result['results']['ultimate_clone']['status'] ?? 0) === 409 && ($result['results']['ultimate_clone']['error'] ?? '') === 'voice_profile_transcript_unconfirmed', 'unconfirmed Ultimate Clone must remain its own result');
});

hub_test('VoxCPM2 playground gives an all-failed comparison a concrete aggregate error', function (): void {
    $oldPost = $_POST;
    $_POST = ['compare_all' => '1', 'voice_profile_id' => '42'];

    try {
        $result = hub_playground_execute_tts('test-token', static function (string $ttsMode): array {
            return ['ok' => false, 'status' => 409, 'error' => 'voice_profile_transcript_unconfirmed', 'message' => $ttsMode, 'pretty_body' => '{}'];
        });
    } finally {
        $_POST = $oldPost;
    }

    hub_test_assert(empty($result['ok']) && ($result['status'] ?? null) === 500 && ($result['error'] ?? '') === 'tts_comparison_failed', 'all-failed comparison must not be reported as mixed');
    hub_test_assert(array_keys($result['results'] ?? []) === ['design', 'clone', 'ultimate_clone'], 'all-failed comparison must preserve each mode result');
});

hub_test('VoxCPM2 playground manages voice profiles with request-scoped TTS tokens', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(is_file($helperFile), 'playground voice-profile helper include missing');
    $helper = (string)file_get_contents($helperFile);

    foreach (['voice_profile_upload', 'voice_profile_confirm', 'voice_profile_retry_asr'] as $action) {
        hub_test_assert(str_contains($source, 'name="action" value="' . $action . '"'), 'playground must provide POST action ' . $action);
    }
    hub_test_assert(str_contains($source, "require_once __DIR__ . '/_playground_voice_profiles.php';"), 'playground must load the voice-profile helper');
    foreach ([
        "hub_gateway_authenticate_api_token(\$db, 'tts', hub_get_client_ip(), \$token)",
        'hub_create_uploaded_voice_profile',
        'hub_confirm_voice_profile_prompt',
        'hub_retry_voice_profile_transcription',
    ] as $needle) {
        hub_test_assert(str_contains($helper, $needle), 'playground voice-profile helper missing ' . $needle);
    }
    hub_test_assert(str_contains($source, 'hub_check_csrf()'), 'playground voice-profile flow must keep CSRF protection');

    $examplesStart = strpos($source, 'function hub_playground_examples');
    $ttsExamplesStart = $examplesStart === false ? false : strpos($source, "if (\$mode === 'tts') {", $examplesStart);
    $ttsExamplesEnd = strpos($source, "if (\$mode === 'chat') {", $ttsExamplesStart);
    hub_test_assert($examplesStart !== false && $ttsExamplesStart !== false && $ttsExamplesEnd !== false, 'playground TTS example block missing');
    $ttsExamples = substr($source, $ttsExamplesStart, $ttsExamplesEnd - $ttsExamplesStart);
    foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path'] as $forbidden) {
        hub_test_assert(!str_contains($ttsExamples, $forbidden), 'browser TTS payload must not contain ' . $forbidden);
    }
    hub_test_assert(!str_contains($source, 'mode=voice_profile_transcribe'), 'playground must not expose a public voice-profile transcription API mode');
});

hub_test('VoxCPM2 playground resolves voice-profile ownership from the TTS token', function (): void {
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(is_file($helperFile), 'playground TTS token helper include missing');
    require_once $helperFile;
    hub_test_assert(function_exists('hub_playground_tts_member_id'), 'playground TTS token helper missing');

    $db = hub_test_reset_db();
    $memberId = hub_create_api_member($db, 'Playground Voice Token Owner');
    $token = hub_create_api_token($db, $memberId, 'Playground TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$token['token_id'], 'tts');
    $unscoped = hub_create_api_token($db, $memberId, 'Playground unscoped token', null, null);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.34';

    try {
        hub_test_assert(
            hub_playground_tts_member_id($db, (string)$token['plain_token']) === $memberId,
            'playground must use the authenticated TTS token member as voice-profile owner'
        );
        hub_test_assert(
            hub_test_throws(static fn (): int => hub_playground_tts_member_id($db, (string)$unscoped['plain_token'])),
            'playground must reject a token without TTS access'
        );
    } finally {
        $_SERVER = $oldServer;
    }
});

hub_test('VoxCPM2 playground rejects foreign token voice-profile mutations with a redacted error', function (): void {
    $source = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $helperFile = HUB_ROOT . '/admin/_playground_voice_profiles.php';
    hub_test_assert(str_contains($source, "require_once __DIR__ . '/_playground_voice_profiles.php';"), 'playground controller must load its voice-profile dispatcher');
    hub_test_assert(is_file($helperFile), 'playground voice-profile dispatcher include missing');
    require_once $helperFile;

    $db = hub_test_reset_db();
    $ownerMemberId = hub_create_api_member($db, 'Playground Profile Owner');
    $foreignMemberId = hub_create_api_member($db, 'Playground Profile Foreign Member');
    $foreignToken = hub_create_api_token($db, $foreignMemberId, 'Playground foreign TTS token', null, null);
    hub_add_api_token_mode_permission($db, (int)$foreignToken['token_id'], 'tts');
    $dir = hub_voice_profile_storage_dir();
    $wav = $dir . '/playground_foreign_owner.wav';
    file_put_contents($wav, 'RIFFmock');
    $profileId = hub_create_voice_profile($db, $ownerMemberId, [
        'name' => 'Owner-only profile',
        'reference_audio_path' => $wav,
        'consent_type' => 'self_recorded',
        'usage_scope' => 'private',
        'visibility' => 'private',
    ]);
    $oldServer = $_SERVER;
    $_SERVER['REMOTE_ADDR'] = '203.0.113.35';

    try {
        $tokenMemberId = hub_playground_tts_member_id($db, (string)$foreignToken['plain_token']);
        hub_test_assert($tokenMemberId === $foreignMemberId, 'playground must derive the mutation owner from the supplied token');
        $confirm = hub_playground_voice_profile_dispatch($db, 'voice_profile_confirm', (string)$foreignToken['plain_token'], [
            'voice_profile_id' => $profileId,
            'prompt_text' => 'foreign transcript',
        ], []);
        $retry = hub_playground_voice_profile_dispatch($db, 'voice_profile_retry_asr', (string)$foreignToken['plain_token'], [
            'voice_profile_id' => $profileId,
        ], []);
        hub_test_assert((hub_get_voice_profile($db, $profileId)['prompt_text'] ?? null) === null, 'foreign token mutation must leave the owner profile unchanged');

        foreach ([$confirm, $retry] as $error) {
            $body = (string)($error['pretty_body'] ?? '');
            hub_test_assert(($error['error'] ?? '') === 'voice_profile_request_failed', 'playground controller error must stay generic');
            foreach ([$wav, 'foreign transcript', 'voice_profile_forbidden'] as $secret) {
                hub_test_assert(!str_contains($body, $secret), 'playground controller error must redact ' . $secret);
            }
        }
    } finally {
        $_SERVER = $oldServer;
        if (hub_get_voice_profile($db, $profileId) !== null) {
            hub_soft_delete_voice_profile($db, $profileId, $ownerMemberId, true);
        }
    }
});

hub_test('VoxCPM2 playground exposes generated WAV through authenticated audio player', function (): void {
    $playground = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    $artifactHelpers = HUB_ROOT . '/admin/_playground_tts_artifacts.php';
    hub_test_assert(is_file($artifactHelpers), 'playground TTS artifact helper missing');
    hub_test_assert(str_contains($playground, "require_once __DIR__ . '/_playground_tts_artifacts.php';"), 'playground must load the TTS artifact helper');
    hub_test_assert(str_contains((string)file_get_contents($artifactHelpers), 'playground_artifact.php'), 'playground must link TTS artifacts through admin artifact endpoint');
    hub_test_assert(str_contains($playground, '<audio controls'), 'playground must render an audio player for TTS artifacts');

    $artifactPage = HUB_ROOT . '/admin/playground_artifact.php';
    hub_test_assert(is_file($artifactPage), 'playground artifact endpoint missing');
    $source = (string)file_get_contents($artifactPage);
    foreach (['hub_require_login', 'audio/wav', 'hub_playground_artifact_path', 'basename'] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'playground artifact endpoint missing ' . $needle);
    }
});

hub_test('VoxCPM2 acceptance set covers Traditional Chinese maintenance text', function (): void {
    $cases = json_decode((string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json'), true);
    hub_test_assert(is_array($cases) && count($cases) === 12, 'VoxCPM2 acceptance set must contain 12 cases');
    $joined = json_encode($cases, JSON_UNESCAPED_UNICODE);
    foreach (['8,500 rpm', '0.7 mm', '12 N·m', 'NSR', 'RC Valve', 'PGM-III', '6902', '91201-KV3-831', '臺', '閥', '機車', '汽缸'] as $needle) {
        hub_test_assert(str_contains((string)$joined, $needle), 'VoxCPM2 acceptance set missing ' . $needle);
    }
});

hub_test('VoxCPM2 long-form job is a fixed GPU container Pack contract with safe artifacts', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    hub_test_assert(is_array($pack) && ($pack['status'] ?? '') === 'ok', 'VoxCPM2 Pack must validate before its long-form job is usable');
    $manifest = $pack['manifest'];
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(is_array($job), 'VoxCPM2 synthesize job contract missing');
    hub_test_assert(($job['input_fields'] ?? []) === ['text', 'mode', 'voice_prompt', 'control', 'seed', 'seed_policy', 'model', 'voice_profile_id', 'voice_profile_task_id', 'waveform_preview'], 'long-form input must be a closed Pack allowlist');
    hub_test_assert(($manifest['version'] ?? '') === '0.1.8', 'resident execution changes must bump the Pack patch version');
    hub_test_assert(($job['request_schema']['mode'] ?? []) === ['type' => 'string', 'required' => false, 'enum' => ['design', 'clone', 'ultimate_clone'], 'max_length' => 16, 'default' => 'design'], 'async synthesis mode must default to design and declare all three modes');
    hub_test_assert(($job['request_schema']['voice_profile_id'] ?? []) === ['type' => 'integer', 'required' => false, 'min' => 1, 'max' => 2147483647], 'managed profile IDs must retain exact integer bounds');
    hub_test_assert(($job['request_schema']['voice_profile_task_id'] ?? []) === ['type' => 'string', 'required' => false, 'max_length' => 64], 'native profile task handles must be bounded strings');
    hub_test_assert(($job['voice_context'] ?? []) === [
        'mode_input' => 'mode',
        'design_value' => 'design',
        'clone_value' => 'clone',
        'ultimate_value' => 'ultimate_clone',
        'profile_input' => 'voice_profile_id',
        'profile_task_input' => 'voice_profile_task_id',
        'design_prompt_input' => 'voice_prompt',
        'container_path' => '/data/voice_profiles/reference.wav',
    ], 'Voice Context must expose the exact Ultimate Clone contract');
    hub_test_assert(($job['source_required'] ?? true) === false && ($job['source_artifact_types'] ?? null) === [], 'long-form synthesis must receive text and managed voice context, never an external audio source');
    hub_test_assert(($job['runner'] ?? []) === [
        'image' => '3waaihub/tts-voxcpm2:0.1.8',
        'entrypoint' => ['/app/voice-generate'],
        'args' => ['--workspace', '{workspace}', '--input', '{input_dir}', '--output', '{output_dir}', '--runner-config', '{input_dir}/runner_config.json'],
        'output_dir' => 'output',
        'accelerator' => 'gpu',
        'required_vram_mb' => 9600,
        'timeout_seconds' => 7200,
        'executor' => 'container',
        'asset_mounts' => [[
            'id' => 'voxcpm2_model',
            'storage' => 'models',
            'host_subdir' => 'voxcpm2/model',
            'container_path' => '/models/voxcpm2/model',
            'required_paths' => ['config.json'],
        ]],
    ], 'long-form synthesis must use the generic GPU container runner with only its controlled model mount');
    hub_test_assert(($job['runner_config'] ?? []) === [
        'alias_input' => 'model',
        'model_allowlist' => 'voxcpm2',
        'aliases' => [
            'voxcpm2' => [
                'model' => '/models/voxcpm2/model',
                'label' => 'VoxCPM2',
                'version' => '2.0.3',
                'sample_rate' => 48000,
            ],
        ],
        'default_alias' => 'voxcpm2',
    ], 'model, version, and sample rate must be a frozen task snapshot');
    hub_test_assert(($manifest['hardware']['gpu_required'] ?? null) === true && ($manifest['hardware']['cpu_fallback'] ?? null) === false, 'long-form synthesis must not declare a CPU path');
    hub_test_assert(array_column($job['artifact_contract']['artifacts'] ?? [], 'type') === ['generated_audio', 'synthesis_metadata', 'waveform_preview'], 'long-form artifact contract mismatch');
    $metadataArtifact = array_values(array_filter($job['artifact_contract']['artifacts'] ?? [], static fn (array $artifact): bool => ($artifact['type'] ?? '') === 'synthesis_metadata'))[0] ?? [];
    hub_test_assert(in_array('device', $metadataArtifact['json']['required_keys'] ?? [], true), 'synthesis metadata must require device attestation');
    foreach (['jobs/voice_generate.sh', 'service/job.py', 'service/long_form.py', 'service/long_form_smoke.py'] as $asset) {
        hub_test_assert(is_file(HUB_ROOT . '/packs/tts-voxcpm2/' . $asset), 'long-form job asset missing ' . $asset);
    }
    $dockerfile = (string)file_get_contents(HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile');
    foreach (['long_form.py', 'job.py', 'voice_generate.sh', 'voice-generate'] as $needle) {
        hub_test_assert(str_contains($dockerfile, $needle), 'controlled job image must install ' . $needle);
    }
});

hub_test('VoxCPM2 six-key modern Voice Context accepts an omitted default design mode', function (): void {
    $pack = hub_get_pack('tts-voxcpm2');
    $job = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), 'synthesize');
    $modern = $job;
    $modern['input_fields'] = array_values(array_diff($modern['input_fields'], ['voice_profile_task_id']));
    $modern['request_schema']['mode']['enum'] = ['design', 'clone'];
    unset($modern['request_schema']['voice_profile_task_id']);
    $modern['voice_context'] = [
        'mode_input' => 'mode',
        'design_value' => 'design',
        'clone_value' => 'clone',
        'profile_input' => 'voice_profile_id',
        'design_prompt_input' => 'voice_prompt',
        'container_path' => '/data/voice_profiles/reference.wav',
    ];
    $modernSnapshot = hub_pack_job_contract_snapshot($modern);
    $modernInput = hub_pack_job_normalize_request_input(['text' => 'modern default design'], $modern);
    hub_test_assert(is_string($modernSnapshot['digest'] ?? null)
        && ($modernSnapshot['contract']['voice_context'] ?? null) === $modern['voice_context']
        && $modernInput === ['text' => 'modern default design']
        && hub_pack_job_voice_context_snapshot($modern['voice_context'], $modernInput, null) === [],
        'six-key modern Voice Context must validate without legacy opt-in and preserve omitted default design mode');
});

hub_test('VoxCPM2 Ultimate Clone canonicalizes successful native profile tasks into private immutable snapshots', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Ultimate Clone Owner');
        $other = hub_create_api_member($db, 'Ultimate Clone Other');
        $ownerToken = hub_create_api_token($db, $owner, 'ultimate clone owner', null, null);
        $otherToken = hub_create_api_token($db, $other, 'ultimate clone other', null, null);
        hub_test_audio_allow($db, [$ownerToken, $otherToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $path = hub_voice_profile_storage_dir() . '/ultimate_async_reference.wav';
        file_put_contents($path, 'RIFFultimate-async', LOCK_EX);
        $prompt = 'private confirmed Ultimate Clone transcript';
        $profileId = hub_create_voice_profile($db, $owner, [
            'name' => 'Ultimate async profile',
            'reference_audio_path' => $path,
            'prompt_text' => $prompt,
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
        ]);
        $profile = hub_confirm_voice_profile_prompt($db, $profileId, $owner, $prompt);
        $profileTaskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.51', [
            'owner_member_id' => $owner,
            'owner_token_id' => (int)$ownerToken['token_id'],
            'requested_mode' => 'voice_generate',
        ]);
        $db->prepare("UPDATE tasks SET status = 'success', finished_at = :now, updated_at = :now WHERE id = :id")
            ->execute([':now' => hub_now(), ':id' => $profileTaskId]);
        $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')
            ->execute([':task_id' => $profileTaskId, ':id' => $profileId]);

        $request = [
            'text' => 'RC Valve Ultimate Clone',
            'mode' => 'ultimate_clone',
            'voice_profile_task_id' => (string)$profileTaskId,
            'control' => 'clear',
        ];
        $accepted = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $request);
        $task = hub_get_task($db, (int)(hub_test_audio_payload($accepted)['task_id'] ?? 0));
        $acceptedSuccessRunner = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_replace($request, [
            'text' => 'RC Valve Ultimate Clone success runner',
        ]));
        $successRunnerTask = hub_get_task($db, (int)(hub_test_audio_payload($acceptedSuccessRunner)['task_id'] ?? 0));
        $acceptedPrepareFailure = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_replace($request, [
            'text' => 'RC Valve Ultimate Clone partial prepare',
        ]));
        $prepareFailureTask = hub_get_task($db, (int)(hub_test_audio_payload($acceptedPrepareFailure)['task_id'] ?? 0));
        $acceptedFenceFailure = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_replace($request, [
            'text' => 'RC Valve Ultimate Clone pre-executor fence',
        ]));
        $fenceFailureTask = hub_get_task($db, (int)(hub_test_audio_payload($acceptedFenceFailure)['task_id'] ?? 0));
        $snapshot = $task['input']['voice_context'] ?? null;
        $expectedSnapshot = [
            'mode' => 'ultimate_clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $path),
            'prompt_text_sha256' => hash('sha256', $prompt),
            'prompt_text_confirmed_at' => (string)$profile['prompt_text_confirmed_at'],
            'container_path' => '/data/voice_profiles/reference.wav',
        ];
        hub_test_assert($accepted['status'] === 200 && $snapshot === $expectedSnapshot, 'successful owned profile task must become the exact Ultimate Clone snapshot');
        hub_test_assert(!array_key_exists('voice_profile_task_id', $task['input']) && ($task['input']['voice_profile_id'] ?? null) === $profileId, 'native task handle must be replaced by the local profile ID before persistence');
        hub_test_assert(!str_contains((string)json_encode($task['input'], JSON_UNESCAPED_UNICODE), $prompt), 'task JSON must never persist confirmed prompt plaintext');

        $both = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $request + ['voice_profile_id' => (string)$profileId]);
        $missing = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_diff_key($request, ['voice_profile_task_id' => true]));
        $foreign = hub_test_audio_request($db, 'voice_generate', (string)$otherToken['plain_token'], $request);
        $db->prepare("UPDATE tasks SET status = 'failed', error_code = 'synthetic_failure', updated_at = :now WHERE id = :id")
            ->execute([':now' => hub_now(), ':id' => $profileTaskId]);
        $nonSuccess = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $request);
        hub_test_assert($both['status'] === 400 && (hub_test_audio_payload($both)['error'] ?? '') === 'voice_profile_required', 'Ultimate Clone must accept exactly one profile reference');
        hub_test_assert($missing['status'] === 400 && (hub_test_audio_payload($missing)['error'] ?? '') === 'voice_profile_required', 'Ultimate Clone must require one profile reference');
        hub_test_assert($foreign['status'] === 403 && (hub_test_audio_payload($foreign)['error'] ?? '') === 'voice_profile_forbidden', 'native profile task handles must remain owner-only');
        hub_test_assert($nonSuccess['status'] === 403 && (hub_test_audio_payload($nonSuccess)['error'] ?? '') === 'voice_profile_forbidden', 'same-owner non-success profile task handles must be rejected');

        $route = hub_resolve_audio_async_route($db, 'voice_generate');
        $legacyDefinition = [
            'mode_input' => 'mode',
            'design_value' => 'design',
            'clone_value' => 'clone',
            'profile_input' => 'voice_profile_id',
            'design_prompt_input' => 'voice_prompt',
            'container_path' => '/data/voice_profiles/reference.wav',
        ];
        $cloneSnapshot = [
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $path),
            'container_path' => '/data/voice_profiles/reference.wav',
        ];
        hub_test_assert(hub_pack_job_voice_context_snapshot($legacyDefinition, ['mode' => 'design', 'voice_prompt' => 'voice'], null) === [], 'legacy design snapshots must remain valid');
        hub_test_assert(hub_pack_job_voice_context_snapshot($legacyDefinition, ['mode' => 'clone', 'voice_profile_id' => $profileId], $cloneSnapshot) === $cloneSnapshot, 'legacy clone snapshots must remain byte-compatible');
        hub_test_assert(($route['voice_context'] ?? []) !== $legacyDefinition, 'new routes must use the Ultimate Clone contract');

        $modelDir = hub_test_models_dir() . '/voxcpm2/model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
            throw new RuntimeException('Cannot create VoxCPM2 model fixture.');
        }
        file_put_contents($modelDir . '/config.json', '{}', LOCK_EX);
        $claimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $privateRequest = null;
        hub_run_pack_job_task($db, $claimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static function (array $context) use (&$privateRequest): array {
                $privateRequest = json_decode((string)file_get_contents($context['workspace'] . '/input/request.json'), true);
                return [
                    'exit_code' => 1,
                    'error_code' => 'synthetic_failure',
                    'completed_no_process_evidence' => true,
                    'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                ];
            },
        ]);
        $retainedFailureRequest = (string)file_get_contents(hub_task_result_dir((int)$task['id']) . '/workspace/input/request.json');
        hub_test_assert(($privateRequest['prompt_text'] ?? '') === $prompt, 'confirmed prompt plaintext must be injected only into the private ephemeral runner request');
        hub_test_assert(($privateRequest['voice_context'] ?? null) === $expectedSnapshot, 'ephemeral request must retain the trusted hash snapshot');
        hub_test_assert(
            !str_contains($retainedFailureRequest, $prompt)
            && !array_key_exists('prompt_text', json_decode($retainedFailureRequest, true, 32, JSON_THROW_ON_ERROR)),
            'failed runner execution must scrub prompt plaintext from its retained request'
        );
        hub_test_assert(!str_contains((string)json_encode(hub_get_task($db, (int)$task['id']), JSON_UNESCAPED_UNICODE), $prompt), 'persisted task data must remain prompt-free after runner preparation');

        $successClaimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        hub_test_assert((int)($successClaimed['id'] ?? 0) === (int)($successRunnerTask['id'] ?? 0), 'second Ultimate Clone task must remain claimable');
        hub_run_pack_job_task($db, $successClaimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static function (array $context) use ($prompt): array {
                $private = json_decode((string)file_get_contents($context['workspace'] . '/input/request.json'), true);
                hub_test_assert(($private['prompt_text'] ?? '') === $prompt, 'successful runner path must receive the private prompt');
                return [
                    'exit_code' => 0,
                    'completed_no_process_evidence' => true,
                    'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                ];
            },
        ]);
        $retainedSuccessRequest = (string)file_get_contents(hub_task_result_dir((int)$successRunnerTask['id']) . '/workspace/input/request.json');
        $retainedSuccess = json_decode($retainedSuccessRequest, true, 32, JSON_THROW_ON_ERROR);
        hub_test_assert(
            !str_contains($retainedSuccessRequest, $prompt)
            && !array_key_exists('prompt_text', $retainedSuccess)
            && ($retainedSuccess['voice_context'] ?? null) === $expectedSnapshot,
            'successful runner execution must scrub only prompt plaintext and preserve safe retry fields'
        );

        $prepareClaimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        hub_test_assert((int)($prepareClaimed['id'] ?? 0) === (int)($prepareFailureTask['id'] ?? 0), 'partial prepare task must remain claimable');
        $prepareInput = hub_task_result_dir((int)$prepareFailureTask['id']) . '/workspace/input';
        if (!mkdir($prepareInput . '/runner_config.json', 0700, true)) {
            throw new RuntimeException('Cannot create partial workspace failure fixture.');
        }
        $prepareExecutorCalled = false;
        hub_run_pack_job_task($db, $prepareClaimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static function () use (&$prepareExecutorCalled): array {
                $prepareExecutorCalled = true;
                return [];
            },
        ]);
        $prepareWorkspaceText = '';
        $prepareIterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                hub_task_result_dir((int)$prepareFailureTask['id']) . '/workspace',
                FilesystemIterator::SKIP_DOTS
            )
        );
        foreach ($prepareIterator as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $prepareWorkspaceText .= (string)file_get_contents($file->getPathname());
            }
        }
        hub_test_assert(
            !$prepareExecutorCalled && !str_contains($prepareWorkspaceText, $prompt),
            'partial workspace preparation must never retain prompt plaintext before returning a workspace'
        );

        $fenceClaimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        hub_test_assert((int)($fenceClaimed['id'] ?? 0) === (int)($fenceFailureTask['id'] ?? 0), 'pre-executor fence task must remain claimable');
        $fenceExecutorCalled = false;
        $fenceInvalidated = false;
        hub_run_pack_job_task($db, $fenceClaimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'pid_inspector' => static function (array $context) use ($db, $prompt, &$fenceInvalidated): array {
                $private = (string)file_get_contents($context['workspace'] . '/input/request.json');
                hub_test_assert(str_contains($private, $prompt), 'pre-executor fixture must reach the private request boundary');
                if (!$fenceInvalidated) {
                    $db->prepare('UPDATE runtime_runs SET lease_token = :lease_token WHERE id = :id')
                        ->execute([':lease_token' => str_repeat('f', 64), ':id' => (int)$context['run']['id']]);
                    $fenceInvalidated = true;
                }

                return [];
            },
            'executor' => static function () use (&$fenceExecutorCalled): array {
                $fenceExecutorCalled = true;
                return [];
            },
        ]);
        $fenceRetained = (string)file_get_contents(
            hub_task_result_dir((int)$fenceFailureTask['id']) . '/workspace/input/request.json'
        );
        hub_test_assert(
            !$fenceExecutorCalled
            && !str_contains($fenceRetained, $prompt)
            && !array_key_exists('prompt_text', json_decode($fenceRetained, true, 32, JSON_THROW_ON_ERROR)),
            'every pre-executor early return must scrub prompt plaintext while retaining a safe request'
        );
    });
});

hub_test('VoxCPM2 async clone admission distinguishes unavailable profiles from foreign or unknown references', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Unavailable Clone Owner');
        $other = hub_create_api_member($db, 'Unavailable Clone Other');
        $ownerToken = hub_create_api_token($db, $owner, 'unavailable clone owner', null, null);
        $otherToken = hub_create_api_token($db, $other, 'unavailable clone other', null, null);
        hub_test_audio_allow($db, [$ownerToken, $otherToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');

        $makeProfile = static function (string $name) use ($db, $owner, $ownerToken): array {
            $path = hub_voice_profile_storage_dir() . '/' . $name . '.wav';
            file_put_contents($path, 'RIFF' . $name, LOCK_EX);
            $prompt = 'Confirmed ' . $name . ' transcript';
            $profileId = hub_create_voice_profile($db, $owner, [
                'name' => $name,
                'reference_audio_path' => $path,
                'prompt_text' => $prompt,
                'consent_type' => 'self_recorded',
                'usage_scope' => 'private',
            ]);
            hub_confirm_voice_profile_prompt($db, $profileId, $owner, $prompt);
            $taskId = hub_enqueue_task($db, 'voice_profile_prepare', 'default', 0, ['voice_profile_id' => $profileId], null, '203.0.113.51', [
                'owner_member_id' => $owner,
                'owner_token_id' => (int)$ownerToken['token_id'],
                'requested_mode' => 'voice_generate',
            ]);
            $db->prepare("UPDATE tasks SET status = 'success', finished_at = :now, updated_at = :now WHERE id = :id")
                ->execute([':now' => hub_now(), ':id' => $taskId]);
            $db->prepare('UPDATE voice_profiles SET source_task_id = :task_id WHERE id = :id')
                ->execute([':task_id' => $taskId, ':id' => $profileId]);

            return ['profile_id' => $profileId, 'task_id' => $taskId];
        };

        $deleted = $makeProfile('deleted_async_clone');
        hub_soft_delete_voice_profile($db, $deleted['profile_id'], $owner);
        $expired = $makeProfile('expired_async_clone');
        $db->prepare("UPDATE voice_profiles SET expires_at = '2000-01-01 00:00:00' WHERE id = :id")
            ->execute([':id' => $expired['profile_id']]);

        foreach ([
            ['clone', 'voice_profile_task_id', $deleted['task_id']],
            ['ultimate_clone', 'voice_profile_id', $deleted['profile_id']],
            ['ultimate_clone', 'voice_profile_task_id', $expired['task_id']],
            ['clone', 'voice_profile_id', $expired['profile_id']],
        ] as [$mode, $field, $value]) {
            $response = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], [
                'text' => 'Unavailable profile admission',
                'mode' => $mode,
                $field => (string)$value,
            ]);
            hub_test_assert(
                $response['status'] === 410
                && (hub_test_audio_payload($response)['error'] ?? '') === 'voice_profile_unavailable',
                'owned deleted or expired ' . $field . ' must return voice_profile_unavailable for ' . $mode
            );
        }

        foreach ([
            [(string)$otherToken['plain_token'], 'voice_profile_task_id', $deleted['task_id']],
            [(string)$otherToken['plain_token'], 'voice_profile_id', $deleted['profile_id']],
            [(string)$ownerToken['plain_token'], 'voice_profile_task_id', '999999999999999999'],
            [(string)$ownerToken['plain_token'], 'voice_profile_id', '2147483647'],
        ] as [$token, $field, $value]) {
            $response = hub_test_audio_request($db, 'voice_generate', $token, [
                'text' => 'Non-enumerating profile admission',
                'mode' => 'clone',
                $field => (string)$value,
            ]);
            hub_test_assert(
                $response['status'] === 403
                && (hub_test_audio_payload($response)['error'] ?? '') === 'voice_profile_forbidden',
                'foreign and unknown ' . $field . ' references must remain non-enumerating'
            );
        }
    });
});

hub_test('VoxCPM2 executes immutable 0.1.4 through 0.1.7 queued tasks after the 0.1.8 Pack bump', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $route = hub_resolve_audio_async_route($db, 'voice_generate');
        $legacy = $route;
        $legacy['input_fields'] = array_values(array_diff($legacy['input_fields'], ['voice_profile_task_id']));
        $legacy['request_schema']['mode'] = ['type' => 'string', 'required' => false, 'enum' => ['design', 'clone'], 'max_length' => 16];
        $legacy['request_schema']['voice_profile_id']['requires'] = ['mode' => 'clone'];
        unset($legacy['request_schema']['voice_profile_task_id']);
        $legacy['runner']['image'] = '3waaihub/tts-voxcpm2:0.1.0';
        $legacy['voice_context'] = [
            'mode_input' => 'mode',
            'design_value' => 'design',
            'clone_value' => 'clone',
            'profile_input' => 'voice_profile_id',
            'design_prompt_input' => 'voice_prompt',
            'container_path' => '/data/voice_profiles/reference.wav',
        ];
        $required = &$legacy['artifact_contract']['artifacts'][1]['json']['required_keys'];
        $required = array_values(array_diff($required, ['device']));
        unset($required);
        $snapshot = hub_pack_job_contract_snapshot($legacy, true);

        $owner = hub_create_api_member($db, 'Queued 0.1.4 Owner');
        $profilePath = hub_voice_profile_storage_dir() . '/queued_014_clone.wav';
        file_put_contents($profilePath, 'RIFFqueued-014-clone', LOCK_EX);
        $profileId = hub_create_voice_profile($db, $owner, [
            'name' => 'Queued 0.1.4 clone',
            'reference_audio_path' => $profilePath,
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
        ]);
        $cloneContext = [
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $profilePath),
            'container_path' => '/data/voice_profiles/reference.wav',
        ];
        $enqueue = static function (array $input) use ($db, $owner, $snapshot): int {
            return hub_enqueue_task($db, 'pack_job', 'gpu', 0, $input, null, '203.0.113.51', [
                'owner_member_id' => $owner,
                'requested_mode' => 'voice_generate',
                'pack_id' => 'tts-voxcpm2',
                'pack_version' => '0.1.4',
                'job' => 'synthesize',
                'job_contract_json' => $snapshot['json'],
                'job_contract_digest' => $snapshot['digest'],
                'runtime_mode' => 'job',
                'accelerator' => 'gpu',
                'route_resolved_at' => '2026-07-30 00:00:00',
            ]);
        };
        $designTaskId = $enqueue([
            'text' => 'queued legacy design',
            'voice_prompt' => 'private legacy design prompt',
        ]);
        $cloneTaskId = $enqueue([
            'text' => 'queued legacy clone',
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'voice_context' => $cloneContext,
        ]);
        $modelDir = hub_test_models_dir() . '/voxcpm2/model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
            throw new RuntimeException('Cannot create VoxCPM2 legacy model fixture.');
        }
        file_put_contents($modelDir . '/config.json', '{}', LOCK_EX);
        $executed = [];
        $modeOmitted = [];
        foreach ([$designTaskId, $cloneTaskId] as $taskId) {
            $claimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
            hub_test_assert((int)($claimed['id'] ?? 0) === $taskId, 'legacy queued task must remain claimable in order');
            $outcome = hub_run_pack_job_task($db, $claimed ?? [], [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
                'executor' => static function (array $context) use (&$executed, &$modeOmitted): array {
                    $request = json_decode((string)file_get_contents($context['workspace'] . '/input/request.json'), true);
                    $modeOmitted[] = !array_key_exists('mode', $request);
                    $mode = $request['mode'] ?? 'design';
                    $executed[] = $mode;
                    $containerId = 'legacy-014-' . $mode;
                    $context['started'](['container_id' => $containerId, 'baseline_pids' => [], 'owned_pids' => []]);
                    return [
                        'exit_code' => 1,
                        'error_code' => 'synthetic_legacy_exit',
                        'container_id' => $containerId,
                        'baseline_pids' => [],
                        'owned_pids' => [],
                        'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                    ];
                },
            ]);
            hub_test_assert(($outcome['error_code'] ?? '') === 'synthetic_legacy_exit', 'legacy queued task must reach its immutable executor: ' . json_encode($outcome));
        }
        hub_test_assert($executed === ['design', 'clone'], 'both 0.1.4 modes must execute through their stored contracts');
        hub_test_assert($modeOmitted === [true, false], 'omitted-mode 0.1.4 design tasks must execute without mutating their stored request');
        $previous = $route;
        $previous['runner']['image'] = '3waaihub/tts-voxcpm2:0.1.5';
        $previousSnapshot = hub_pack_job_contract_snapshot($previous, true);
        $previousTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, ['text' => 'queued 0.1.5 design'], null, '203.0.113.51', [
            'owner_member_id' => $owner,
            'requested_mode' => 'voice_generate',
            'pack_id' => 'tts-voxcpm2',
            'pack_version' => '0.1.5',
            'job' => 'synthesize',
            'job_contract_json' => $previousSnapshot['json'],
            'job_contract_digest' => $previousSnapshot['digest'],
            'runtime_mode' => 'job',
            'accelerator' => 'gpu',
            'route_resolved_at' => '2026-07-30 00:00:00',
        ]);
        $previousStored = hub_get_task($db, $previousTaskId);
        $previousContract = $previousStored ? hub_resolve_stored_pack_job($db, $previousStored) : null;
        hub_test_assert(($previousContract['runner']['image'] ?? '') === '3waaihub/tts-voxcpm2:0.1.5', '0.1.5 stored contract must resolve against the current Pack');
        $previousClaimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $previousExecuted = false;
        $previousOutcome = hub_run_pack_job_task($db, $previousClaimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static function () use (&$previousExecuted): array {
                $previousExecuted = true;
                return [
                    'exit_code' => 1,
                    'error_code' => 'synthetic_legacy_exit',
                    'completed_no_process_evidence' => true,
                    'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                ];
            },
        ]);
        hub_test_assert(
            (int)($previousClaimed['id'] ?? 0) === $previousTaskId
            && $previousExecuted
            && ($previousOutcome['error_code'] ?? '') === 'synthetic_legacy_exit',
            'queued 0.1.5 task must execute through its immutable stored contract'
        );
        foreach (['0.1.6', '0.1.7'] as $preResidentVersion) {
            $preResident = $route;
            $preResident['runner']['image'] = '3waaihub/tts-voxcpm2:' . $preResidentVersion;
            $preResidentSnapshot = hub_pack_job_contract_snapshot($preResident, true);
            $preResidentTaskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, ['text' => 'queued ' . $preResidentVersion . ' design'], null, '203.0.113.51', [
                'owner_member_id' => $owner,
                'requested_mode' => 'voice_generate',
                'pack_id' => 'tts-voxcpm2',
                'pack_version' => $preResidentVersion,
                'job' => 'synthesize',
                'job_contract_json' => $preResidentSnapshot['json'],
                'job_contract_digest' => $preResidentSnapshot['digest'],
                'runtime_mode' => 'job',
                'accelerator' => 'gpu',
                'route_resolved_at' => '2026-07-30 00:00:00',
            ]);
            $preResidentStored = hub_get_task($db, $preResidentTaskId);
            $preResidentContract = $preResidentStored ? hub_resolve_stored_pack_job($db, $preResidentStored) : null;
            hub_test_assert(($preResidentContract['runner']['image'] ?? '') === '3waaihub/tts-voxcpm2:' . $preResidentVersion, $preResidentVersion . ' stored contract must resolve against the current Pack');
            $preResidentClaimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
            $preResidentExecuted = false;
            $preResidentOutcome = hub_run_pack_job_task($db, $preResidentClaimed ?? [], [
                'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
                'executor' => static function () use (&$preResidentExecuted): array {
                    $preResidentExecuted = true;
                    return [
                        'exit_code' => 1,
                        'error_code' => 'synthetic_legacy_exit',
                        'completed_no_process_evidence' => true,
                        'cleanup' => ['runner_exited' => true, 'container_removed' => true, 'owned_gpu_pids_gone' => true],
                    ];
                },
            ]);
            hub_test_assert(
                (int)($preResidentClaimed['id'] ?? 0) === $preResidentTaskId
                && $preResidentExecuted
                && ($preResidentOutcome['error_code'] ?? '') === 'synthetic_legacy_exit',
                'queued ' . $preResidentVersion . ' task must execute through its immutable stored contract'
            );
        }
        hub_test_assert((string)$db->query("SELECT pack_version FROM services WHERE pack_id = 'tts-voxcpm2'")->fetchColumn() === '0.1.8', 'compatibility must run against the upgraded installed Pack');
        $unsupported = hub_get_task($db, $designTaskId) ?? [];
        $unsupported['pack_version'] = '0.1.3';
        hub_test_assert(hub_test_throws(static fn (): array => hub_resolve_stored_pack_job($db, $unsupported)), 'the stored-version exception must reject every other VoxCPM2 version');
    });
});

hub_test('VoxCPM2 Ultimate Clone revalidates profile state after GPU preflight and before workspace creation', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Ultimate Late Validation Owner');
        $token = hub_create_api_token($db, $owner, 'ultimate late validation', null, null);
        hub_test_audio_allow($db, [$token], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $modelDir = hub_test_models_dir() . '/voxcpm2/model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
            throw new RuntimeException('Cannot create VoxCPM2 model fixture.');
        }
        file_put_contents($modelDir . '/config.json', '{}', LOCK_EX);

        $createTask = static function (string $suffix) use ($db, $owner, $token): array {
            $path = hub_voice_profile_storage_dir() . '/ultimate_late_' . $suffix . '.wav';
            file_put_contents($path, 'RIFFultimate-' . $suffix, LOCK_EX);
            $prompt = 'confirmed transcript ' . $suffix;
            $profileId = hub_create_voice_profile($db, $owner, [
                'name' => 'Ultimate late ' . $suffix,
                'reference_audio_path' => $path,
                'prompt_text' => $prompt,
                'consent_type' => 'self_recorded',
                'usage_scope' => 'private',
            ]);
            hub_confirm_voice_profile_prompt($db, $profileId, $owner, $prompt);
            $response = hub_test_audio_request($db, 'voice_generate', (string)$token['plain_token'], [
                'text' => 'late validation',
                'mode' => 'ultimate_clone',
                'voice_profile_id' => (string)$profileId,
            ]);
            return [hub_get_task($db, (int)(hub_test_audio_payload($response)['task_id'] ?? 0)), $profileId, $path];
        };

        [$waitingTask, , $missingPath] = $createTask('missing');
        unlink($missingPath);
        $waitingClaim = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $waiting = hub_run_pack_job_task($db, $waitingClaim ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 0, 'processes' => []],
            'gpu_backoff_seconds' => 300,
            'executor' => static fn (): array => throw new RuntimeException('executor must not run'),
        ]);
        hub_test_assert(($waiting['status'] ?? '') === 'waiting_gpu' && !is_dir(hub_task_result_dir((int)$waitingTask['id']) . '/workspace'), 'GPU preflight must happen before profile mount resolution or workspace creation');

        [$changedTask, $changedProfileId] = $createTask('changed');
        hub_confirm_voice_profile_prompt($db, $changedProfileId, $owner, 'changed confirmed transcript');
        $changedClaim = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $changed = hub_run_pack_job_task($db, $changedClaim ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static fn (): array => throw new RuntimeException('executor must not run'),
        ]);
        hub_test_assert(($changed['error_code'] ?? '') === 'voice_profile_changed' && !is_dir(hub_task_result_dir((int)$changedTask['id']) . '/workspace'), 'changed confirmed profile hashes must fail before workspace creation');

        [$removedTask, $removedProfileId] = $createTask('removed-transcript');
        $db->prepare('UPDATE voice_profiles SET prompt_text = NULL WHERE id = :id')->execute([':id' => $removedProfileId]);
        $removedClaim = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $removed = hub_run_pack_job_task($db, $removedClaim ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static fn (): array => throw new RuntimeException('executor must not run'),
        ]);
        hub_test_assert(($removed['error_code'] ?? '') === 'voice_profile_changed' && !is_dir(hub_task_result_dir((int)$removedTask['id']) . '/workspace'), 'removed transcript content must be classified as a post-admission profile change');

        [$unconfirmedTask, $unconfirmedProfileId] = $createTask('unconfirmed');
        $db->prepare('UPDATE voice_profiles SET prompt_text_confirmed_at = NULL WHERE id = :id')->execute([':id' => $unconfirmedProfileId]);
        $unconfirmedClaim = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $unconfirmed = hub_run_pack_job_task($db, $unconfirmedClaim ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static fn (): array => throw new RuntimeException('executor must not run'),
        ]);
        hub_test_assert(($unconfirmed['error_code'] ?? '') === 'voice_profile_changed' && !is_dir(hub_task_result_dir((int)$unconfirmedTask['id']) . '/workspace'), 'removed confirmation must be classified as a post-admission profile change');

        [$unavailableTask, , $unavailablePath] = $createTask('unavailable');
        unlink($unavailablePath);
        $unavailableClaim = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $unavailable = hub_run_pack_job_task($db, $unavailableClaim ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'executor' => static fn (): array => throw new RuntimeException('executor must not run'),
        ]);
        hub_test_assert(($unavailable['error_code'] ?? '') === 'voice_profile_unavailable' && !is_dir(hub_task_result_dir((int)$unavailableTask['id']) . '/workspace'), 'missing managed profiles must fail as unavailable before workspace creation');
    });
});

hub_test('VoxCPM2 long-form fake runner is deterministic, resumable, and emits no public checkpoint', function (): void {
    $service = HUB_ROOT . '/packs/tts-voxcpm2/service';
    $workspace = hub_test_voxcpm2_job_workspace();
    $request = [
        'text' => 'Dr. Lin 說：「8,500 rpm 時，RC Valve 的間隙是 0.7 mm。」請確認 PGM-III 與 91201-KV3-831。',
        'mode' => 'design',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
        'seed' => 42,
        'seed_policy' => 'derived_per_chunk',
        'waveform_preview' => true,
    ];
    $pack = hub_get_pack('tts-voxcpm2');
    $contract = hub_pack_async_job_contract((array)($pack['manifest'] ?? []), 'synthesize');
    $config = hub_pack_job_runner_config_for_task((array)$contract, $request);
    hub_test_assert($config === [
        'allowlist' => 'voxcpm2',
        'alias' => 'voxcpm2',
        'model' => [
        'model' => '/models/voxcpm2/model',
        'label' => 'VoxCPM2',
        'version' => '2.0.3',
        'sample_rate' => 48000,
        ],
    ], 'ordinary design synthesis without a model must use the fixed runner default');
    file_put_contents($workspace . '/input/request.json', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    file_put_contents($workspace . '/input/runner_config.json', json_encode($config, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
    file_put_contents($workspace . '/input/source', 'managed-source-not-a-path', LOCK_EX);
    try {
        $planScript = <<<'PY'
import importlib.util
import json
import sys

spec = importlib.util.spec_from_file_location("long_form", sys.argv[1])
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
text = 'Dr. Lin 說：「8,500 rpm 時，RC Valve 的間隙是 0.7 mm。」請確認 PGM-III 與 91201-KV3-831。'
first = module.make_plan(text, 42, 'derived_per_chunk', 42)
second = module.make_plan(text, 42, 'derived_per_chunk', 42)
assert module.canonical_json(first) == module.canonical_json(second)
assert first['normalization'] == 'semantic-v1'
assert ''.join(chunk['text'] for chunk in first['chunks']) == first['normalized_input']
assert '8,500 rpm' in first['normalized_input'] and '0.7 mm' in first['normalized_input']
assert all('word_alignment' not in chunk for chunk in first['chunks'])
unit_chunks = module.split_semantic_v1('A' * 41 + 'N·m。tail', 42)
assert all(not (left.endswith('N') and right.startswith('·m')) for left, right in zip(unit_chunks, unit_chunks[1:]))
assert 'N·m' in ''.join(unit_chunks)
protected = module.make_plan('A' * 250, 42, 'derived_per_chunk', 240)
assert [len(chunk['text']) for chunk in protected['chunks']] == [250]
PY;
        $planSmoke = $workspace . '/input/plan_smoke.py';
        file_put_contents($planSmoke, $planScript . "\n", LOCK_EX);
        $plan = hub_run_command(['python3', $planSmoke, $service . '/long_form.py'], 10);
        hub_test_assert(($plan['exit_code'] ?? 1) === 0, 'semantic-v1 plan must be byte-deterministic: ' . ($plan['stderr'] ?? ''));

        $command = ['python3', $service . '/job.py', '--workspace', $workspace, '--input', $workspace . '/input', '--output', $workspace . '/output', '--runner-config', $workspace . '/input/runner_config.json'];
        $environment = ['VOXCPM2_JOB_FAKE_SYNTHESIS' => '1'];
        $first = hub_run_command($command, 30, $environment);
        hub_test_assert(($first['exit_code'] ?? 1) === 0, 'deterministic fake long-form synthesis must run without a model: ' . ($first['stderr'] ?? ''));
        $audio = $workspace . '/output/generated_audio.wav';
        $metadata = $workspace . '/output/synthesis_metadata.json';
        $waveform = $workspace . '/output/waveform_preview.json';
        $checkpoint = $workspace . '/checkpoints/plan/chunks.json';
        hub_test_assert(is_file($audio) && is_file($metadata) && is_file($waveform) && is_file($checkpoint), 'job must emit only declared artifacts plus a private checkpoint');
        $audioHash = hash_file('sha256', $audio);
        $metadataValue = json_decode((string)file_get_contents($metadata), true);
        hub_test_assert(is_array($metadataValue), 'synthesis metadata must be JSON');
        foreach (['normalized_input', 'plan', 'model', 'voice_context', 'chunks', 'final_format', 'loudness', 'timeline'] as $key) {
            hub_test_assert(array_key_exists($key, $metadataValue), 'synthesis metadata missing ' . $key);
        }
        hub_test_assert(($metadataValue['plan']['normalization'] ?? '') === 'semantic-v1', 'metadata must preserve the immutable semantic plan');
        hub_test_assert(
            !array_key_exists('model', (array)($metadataValue['model'] ?? []))
            && ($metadataValue['model']['label'] ?? null) === 'VoxCPM2'
            && ($metadataValue['model']['version'] ?? null) === '2.0.3'
            && ($metadataValue['model']['sample_rate'] ?? null) === 48000
            && ($metadataValue['controls']['task_seed'] ?? null) === 42,
            'runner defaults must be recorded without exposing the internal model path'
        );
        hub_test_assert(!str_contains((string)file_get_contents($metadata), $workspace), 'metadata must not disclose workspace paths');
        $second = hub_run_command($command, 30, $environment);
        hub_test_assert(($second['exit_code'] ?? 1) === 0 && $audioHash === hash_file('sha256', $audio), 'resume must reuse matching chunk checkpoints deterministically');
        hub_test_assert(!is_file($workspace . '/output/chunks.json') && !is_dir($workspace . '/output/checkpoints'), 'checkpoints must never be public artifacts');
    } finally {
        hub_test_voxcpm2_remove($workspace);
    }
});

hub_test('VoxCPM2 long-form admission freezes only manifest controls and rejects profile misuse', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
    $route = hub_resolve_audio_async_route($db, 'voice_generate');
    $design = hub_pack_job_normalize_request_input([
        'text' => 'RC Valve 8,500 rpm',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
    ], $route);
    hub_test_assert($design === [
        'text' => 'RC Valve 8,500 rpm',
        'voice_prompt' => '沉穩的台灣男性技師',
        'control' => '清楚、稍慢',
    ], 'omitted design mode must remain omitted from normalized legacy task input');
    hub_test_assert(hub_pack_job_normalize_request_input([
        'text' => 'explicit design',
        'mode' => 'design',
    ], $route) === [
        'text' => 'explicit design',
        'mode' => 'design',
    ], 'explicit design mode must remain explicit');

    $memberId = hub_create_api_member($db, 'VoxCPM2 Legacy Design Owner');
    $token = hub_create_api_token($db, $memberId, 'VoxCPM2 legacy design token', null, null);
    $taskId = hub_enqueue_owned_pack_job($db, $route, ['text' => 'legacy text only'], $memberId, (int)$token['token_id'], '203.0.113.51');
    hub_test_assert((hub_get_task($db, $taskId)['input'] ?? null) === ['text' => 'legacy text only'], 'queued text-only tasks must not persist an implicit design mode');

    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'voice_profile_id' => 1], $route)), 'design must reject clone profile IDs at Pack admission');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'reference_wav_path' => '/host.wav'], $route)), 'async tasks must reject external reference paths');
    hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_normalize_request_input(['text' => 'x', 'voice_prompt' => 'voice', 'model' => 'anything-else'], $route)), 'async tasks must reject arbitrary model controls');
});

hub_test('VoxCPM2 upgrade builds the versioned runner when only the old image exists', function (): void {
    $db = hub_test_reset_db();
    $commands = [];
    $images = ['3waaihub/tts-voxcpm2:0.1.0' => 'sha256:old-voxcpm2'];
    $installed = hub_install_pack($db, 'tts-voxcpm2', [
        'idempotent' => true,
        'runner_build_runner' => static function (array $command, int $timeoutSeconds) use (&$commands, &$images): array {
            $commands[] = $command;
            if (($command[1] ?? '') === 'image' && ($command[2] ?? '') === 'inspect') {
                $image = (string)($command[5] ?? '');
                return isset($images[$image])
                    ? ['exit_code' => 0, 'stdout' => $images[$image], 'stderr' => '']
                    : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'missing'];
            }
            if (($command[1] ?? '') === 'build') {
                $images[(string)($command[3] ?? '')] = 'sha256:new-voxcpm2';
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            throw new RuntimeException('unexpected VoxCPM2 runner image command');
        },
    ]);
    hub_test_assert($commands === [
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/tts-voxcpm2:0.1.8'],
        ['docker', 'build', '--tag', '3waaihub/tts-voxcpm2:0.1.8', '--file', HUB_ROOT . '/packs/tts-voxcpm2/service/Dockerfile', HUB_ROOT . '/packs/tts-voxcpm2'],
        ['docker', 'image', 'inspect', '--format', '{{.Id}}', '3waaihub/tts-voxcpm2:0.1.8'],
    ] && ($images['3waaihub/tts-voxcpm2:0.1.0'] ?? '') === 'sha256:old-voxcpm2'
        && ($images['3waaihub/tts-voxcpm2:0.1.8'] ?? '') === 'sha256:new-voxcpm2'
        && ($installed['service']['install_status'] ?? '') === 'installed',
        'an existing old image must not suppress building and verifying the new Pack-versioned runner');
});

hub_test('VoxCPM2 runner terminal failures emit PHP-classifiable stable markers', function (): void {
    $workspace = hub_test_voxcpm2_job_workspace();
    try {
        file_put_contents($workspace . '/input/request.json', "{}\n", LOCK_EX);
        $result = hub_run_command([
            'python3',
            HUB_ROOT . '/packs/tts-voxcpm2/service/job.py',
            '--workspace', $workspace,
            '--input', $workspace . '/input',
            '--output', $workspace . '/output',
            '--runner-config', $workspace . '/input/runner_config.json',
        ], 30);
        hub_test_assert(($result['exit_code'] ?? 0) === 1
            && hub_pack_job_runner_error_code($result) === 'request_invalid'
            && substr_count((string)($result['stderr'] ?? ''), 'AIHUB_ERROR_CODE=') === 1,
            'runner failures must emit exactly one stable marker recognized by the Pack executor');
    } finally {
        hub_test_voxcpm2_remove($workspace);
    }
});

hub_test('VoxCPM2 async clone resolves one owned profile into a path-free snapshot and controlled mount', function (): void {
    hub_test_audio_isolate(static function (): void {
        $db = hub_test_reset_db();
        hub_install_pack($db, 'tts-voxcpm2', ['idempotent' => true]);
        $owner = hub_create_api_member($db, 'Async Clone Owner');
        $other = hub_create_api_member($db, 'Async Clone Other');
        $ownerToken = hub_create_api_token($db, $owner, 'async clone owner', null, null);
        $otherToken = hub_create_api_token($db, $other, 'async clone other', null, null);
        hub_test_audio_allow($db, [$ownerToken, $otherToken], ['voice_generate']);
        hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '1');
        hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '0');
        $profilePath = hub_voice_profile_storage_dir() . '/async_clone_reference.wav';
        file_put_contents($profilePath, 'RIFFmanaged-profile', LOCK_EX);
        $profileId = hub_create_voice_profile($db, $owner, [
            'name' => 'Async clone profile',
            'reference_audio_path' => $profilePath,
            'consent_type' => 'self_recorded',
            'usage_scope' => 'private',
        ]);
        $input = [
            'text' => 'RC Valve 8,500 rpm',
            'mode' => 'clone',
            'voice_profile_id' => (string)$profileId,
            'control' => '清楚、稍慢',
        ];
        $accepted = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input);
        hub_test_assert($accepted['status'] === 200, 'owned clone profile must be admitted');
        $task = hub_get_task($db, (int)(hub_test_audio_payload($accepted)['task_id'] ?? 0));
        $snapshot = $task['input']['voice_context'] ?? null;
        hub_test_assert(is_array($snapshot) && $snapshot === [
            'mode' => 'clone',
            'voice_profile_id' => $profileId,
            'reference_audio_sha256' => hash_file('sha256', $profilePath),
            'container_path' => '/data/voice_profiles/reference.wav',
        ], 'task must persist only immutable profile identity/hash and its fixed container path');
        hub_test_assert(!str_contains((string)json_encode($task['input']), $profilePath), 'task input must never persist a host profile path');

        $route = hub_resolve_audio_async_route($db, 'voice_generate');
        $mount = hub_pack_job_resolve_voice_profile_mount($db, $task, hub_pack_job_contract_from_snapshot($task));
        hub_test_assert($mount === ['source' => realpath($profilePath), 'container_path' => '/data/voice_profiles/reference.wav'], 'generic runner must derive the sole read-only profile mount from the trusted snapshot');
        $runner = $route['runner'];
        $runner['asset_mounts'] = [];
        $command = hub_pack_job_default_runner_command([
            'workspace' => hub_test_voxcpm2_job_workspace(),
            'run' => ['run_id' => 'voice-profile-mount-test'],
            'runner' => array_replace($runner, ['voice_profile_mount' => $mount]),
        ])['command'];
        $profileMount = 'type=bind,src=' . realpath($profilePath) . ',dst=/data/voice_profiles/reference.wav,readonly';
        hub_test_assert(in_array($profileMount, $command, true) && !str_contains(implode("\n", $command), 'async_clone_reference.wav') === false, 'runner command must receive only the Hub-derived read-only reference mount');

        $legacyContract = json_decode((string)$task['job_contract_json'], true, 512, JSON_THROW_ON_ERROR);
        $legacyContract['input_fields'] = array_values(array_diff($legacyContract['input_fields'], ['voice_profile_task_id']));
        unset($legacyContract['request_schema']['voice_profile_task_id']);
        unset(
            $legacyContract['voice_context']['ultimate_value'],
            $legacyContract['voice_context']['profile_task_input'],
            $legacyContract['voice_context']['design_prompt_input']
        );
        $legacyJson = json_encode($legacyContract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $legacyTask = $task;
        $legacyTask['job_contract_json'] = $legacyJson;
        $legacyTask['job_contract_digest'] = hash('sha256', $legacyJson);
        $legacyResolved = hub_pack_job_contract_from_snapshot($legacyTask);
        hub_test_assert(array_keys($legacyResolved['voice_context'] ?? []) === ['mode_input', 'design_value', 'clone_value', 'profile_input', 'container_path']
            && hub_pack_job_voice_context_snapshot($legacyResolved['voice_context'], ['mode' => 'design'], null) === [], 'valid legacy design/clone contracts must canonicalize without adding a client path or prompt control');
        hub_test_assert(hub_pack_job_resolve_voice_profile_mount($db, $legacyTask, $legacyResolved) === $mount, 'valid legacy clone contracts must preserve the owned profile hash and controlled mount');
        $legacyPromptInput = $legacyTask['input'];
        $legacyPromptInput['voice_prompt'] = 'forbidden clone prompt';
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_voice_context_snapshot($legacyResolved['voice_context'], $legacyPromptInput, $snapshot)), 'legacy clone contracts must not bypass the prompt prohibition before GPU work');
        $db->prepare('UPDATE tasks SET job_contract_json = :json, job_contract_digest = :digest WHERE id = :id')->execute([
            ':json' => $legacyJson,
            ':digest' => $legacyTask['job_contract_digest'],
            ':id' => (int)$task['id'],
        ]);
        $task = $legacyTask;

        $beforeClonePrompt = (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn();
        $clonePrompt = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input + ['voice_prompt' => 'must be rejected before GPU']);
        hub_test_assert($clonePrompt['status'] === 400 && (hub_test_audio_payload($clonePrompt)['error'] ?? '') === 'invalid_request'
            && (int)$db->query('SELECT COUNT(*) FROM tasks')->fetchColumn() === $beforeClonePrompt, 'clone voice_prompt must be rejected at gateway admission before a task or GPU work exists');

        $modelDir = hub_test_models_dir() . '/voxcpm2/model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0700, true) && !is_dir($modelDir)) {
            throw new RuntimeException('Cannot create VoxCPM2 model fixture.');
        }
        file_put_contents($modelDir . '/config.json', '{}', LOCK_EX);
        $claimed = hub_claim_next_task($db, hub_pack_job_worker_task_types());
        $dockerRun = [];
        hub_run_pack_job_task($db, $claimed ?? [], [
            'gpu_probe' => static fn (): array => ['free_vram_mb' => 20000, 'processes' => []],
            'command_runner' => static function (array $command, int $timeout) use (&$dockerRun): array {
                if (($command[1] ?? '') === 'run') {
                    $dockerRun = $command;
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'synthetic runner failure'];
                }
                if (($command[1] ?? '') === 'container' && ($command[2] ?? '') === 'inspect') {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No such container'];
                }
                return ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            },
        ]);
        $checkpointMount = 'type=bind,src=' . hub_task_result_dir((int)$task['id']) . '/workspace/checkpoints,dst=/workspace/checkpoints';
        hub_test_assert(in_array($profileMount, $dockerRun, true) && in_array($checkpointMount, $dockerRun, true)
            && !in_array('type=bind,src=' . hub_task_result_dir((int)$task['id']) . '/workspace/input/source,dst=/workspace/input/source,readonly', $dockerRun, true), 'default executor must retain the clone mount and writable private checkpoints after execution starts');

        $crossMember = hub_test_audio_request($db, 'voice_generate', (string)$otherToken['plain_token'], $input);
        hub_test_assert($crossMember['status'] === 403 && (hub_test_audio_payload($crossMember)['error'] ?? '') === 'voice_profile_forbidden', 'another member must not clone an owned profile');
        $design = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], array_replace($input, ['mode' => 'design', 'voice_prompt' => 'voice']));
        hub_test_assert($design['status'] === 400 && (hub_test_audio_payload($design)['error'] ?? '') === 'invalid_request', 'design must reject a profile ID');
        $external = hub_test_audio_request($db, 'voice_generate', (string)$ownerToken['plain_token'], $input + ['reference_wav_path' => '/host/reference.wav']);
        hub_test_assert($external['status'] === 400 && (hub_test_audio_payload($external)['error'] ?? '') === 'forbidden_task_control', 'async clone must reject external reference paths');

        unlink($profilePath);
        hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_voice_profile_mount($db, $task, hub_pack_job_contract_from_snapshot($task))), 'profile file changes or removal must fail before GPU work');
        foreach ([$task] as $item) {
            if (is_array($item) && isset($item['id'])) {
                hub_test_voxcpm2_remove(hub_task_result_dir((int)$item['id']));
            }
        }
    });
});
hub_test('VoxCPM2 three-mode smoke runbook keeps the safe operator contract', function (): void {
    $path = HUB_ROOT . '/docs/operations/voxcpm2-three-mode-smoke.md';
    hub_test_assert(is_file($path), 'VoxCPM2 three-mode smoke runbook missing');
    $doc = (string)file_get_contents($path);

    foreach ([
        'app/bootstrap.php',
        'compose_project',
        'hub_get_storage_setting($db, "AIHUB_MODELS_DIR")',
        'WHISPER_REAL_INFERENCE=1',
        'docker compose',
        '-p "$ASR_COMPOSE_PROJECT"',
        '-p "$TTS_COMPOSE_PROJECT"',
        'up -d --build',
        'nvidia-smi',
        'python3 -m unittest -v test_app.py',
        'mock": false',
        'effective": "cuda"',
        'ultimate_clone',
        'voice_profile_transcript_unconfirmed',
        '409',
        'CPU fallback',
        'Docker NVIDIA runtime',
        'does not authorize use of non-consented voice audio',
        'https://github.com/SYSTRAN/faster-whisper/blob/master/README.md',
        'https://huggingface.co/openbmb/VoxCPM2',
        'VOXCPM2_EXECUTION_MODE=resident',
        'VOXCPM2_IDLE_UNLOAD_SECONDS=0',
        'VOXCPM2_RESIDENT_MIN_FREE_VRAM_MB=1024',
        'cold',
        'ready',
        '9600 MB',
        'two serial real Cluster `voice_generate` acceptance tasks',
        'nvidia-smi --query-compute-apps=pid,process_name,used_memory --format=csv,noheader',
        'retain the staged run',
        'do not dispatch a duplicate',
        'GPU lease blocked until authenticated reconciliation',
        'stopping or restarting VoxCPM2',
        'never auto-unloads Vox',
        'competing GPU Pack waits or the Cluster Router routes it to another eligible station',
    ] as $needle) {
        hub_test_assert(str_contains($doc, $needle), 'three-mode smoke runbook missing ' . $needle);
    }

    foreach (['/DATA/models', 'Authorization: Bearer', 'reference_audio_path', 'prompt_wav_path', 'request_id'] as $forbidden) {
        hub_test_assert(!str_contains($doc, $forbidden), 'three-mode smoke runbook must not include ' . $forbidden);
    }
});

$hubVoxCpm2ClusterAcceptance = HUB_ROOT . '/scripts/voxcpm2_cluster_acceptance.php';
if (is_file($hubVoxCpm2ClusterAcceptance)) {
    require_once $hubVoxCpm2ClusterAcceptance;
}

function hub_test_voxcpm2_cluster_acceptance_wav(): string
{
    $path = tempnam(sys_get_temp_dir(), 'voxcpm2-cluster-reference-');
    if ($path === false) {
        throw new RuntimeException('Cannot create VoxCPM2 Cluster acceptance WAV fixture.');
    }
    file_put_contents(
        $path,
        "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . "data" . pack('V', 0),
        LOCK_EX
    );

    return $path;
}

function hub_test_voxcpm2_cluster_acceptance_env(string $wav, string $baseUrl = 'https://router.example/3waAIHub'): array
{
    return [
        'AIHUB_VOXCPM2_CLUSTER_BASE_URL' => $baseUrl,
        'AIHUB_VOXCPM2_CLUSTER_TOKEN' => 'voxcpm2-cluster-unit-token-secret',
        'AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV' => $wav,
        'AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT' => 'Confirmed private prompt text.',
        'AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT' => 'Generate this private target text.',
    ];
}

function hub_test_voxcpm2_cluster_acceptance_links(string $taskId, bool $ack = false): array
{
    $links = [
        'status_url' => 'cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
        'result_url' => 'cluster_api.php?mode=cluster_task_result&task_id=' . $taskId,
        'log_url' => 'cluster_api.php?mode=cluster_task_log&task_id=' . $taskId,
        'cancel_url' => 'cluster_api.php?mode=cluster_task_cancel&task_id=' . $taskId,
        'artifact_url_template' => 'cluster_api.php?mode=cluster_artifact&task_id=' . $taskId . '&artifact_id={artifact_id}',
    ];
    if ($ack) {
        $links['ack_url_template'] = 'cluster_api.php?mode=cluster_task_artifacts_ack&task_id=' . $taskId . '&artifact_id={artifact_id}';
    }

    return $links;
}

function hub_test_voxcpm2_cluster_runner_canonical_json(mixed $value): string
{
    $normalize = static function (mixed $item) use (&$normalize): mixed {
        if (!is_array($item)) {
            return $item;
        }
        if (array_is_list($item)) {
            return array_map($normalize, $item);
        }
        ksort($item, SORT_STRING);
        foreach ($item as $key => $nested) {
            $item[$key] = $normalize($nested);
        }

        return $item;
    };

    return json_encode(
        $normalize($value),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR
    );
}

function hub_test_voxcpm2_cluster_runner_metadata(
    string $referenceSha256,
    string $promptSha256,
    string $targetText,
    string $mode = 'ultimate_clone',
    string $control = '',
    int $taskSeed = 42,
    string $seedPolicy = 'derived_per_chunk',
    array $device = ['type' => 'cuda', 'real_inference' => true],
): array {
    $normalized = preg_replace('/(*UCP)\s+/u', ' ', $targetText);
    $normalized = is_string($normalized) ? trim($normalized, ' ') : null;
    if (!is_string($normalized) || $normalized === '') {
        throw new RuntimeException('Invalid production metadata fixture target.');
    }
    $chunkId = 'chunk-0001';
    if (!in_array($seedPolicy, ['fixed', 'derived_per_chunk'], true)) {
        throw new RuntimeException('Invalid production metadata fixture seed policy.');
    }
    $seedSha256 = hash('sha256', $seedPolicy === 'fixed' ? (string)$taskSeed : $taskSeed . $chunkId);
    $seed = $seedPolicy === 'fixed'
        ? $taskSeed
        : (int)(hexdec(substr($seedSha256, 8, 8)) % 2147483648);
    $chunks = [[
        'id' => $chunkId,
        'text' => $normalized,
        'text_sha256' => hash('sha256', $normalized),
        'seed' => $seed,
        'seed_sha256' => $seedSha256,
    ]];
    $planCore = [
        'normalization' => 'semantic-v1',
        'normalized_input' => $normalized,
        'max_chunk_chars' => 240,
        'task_seed' => $taskSeed,
        'seed_policy' => $seedPolicy,
        'chunks' => $chunks,
    ];
    if (!in_array($mode, ['design', 'clone', 'ultimate_clone'], true)) {
        throw new RuntimeException('Invalid production metadata fixture mode.');
    }
    $voiceCore = [
        'mode' => $mode,
        'control' => $control,
    ];
    if ($mode !== 'design') {
        $voiceCore['reference_audio_sha256'] = $referenceSha256;
    }
    if ($mode === 'ultimate_clone') {
        $voiceCore['prompt_text_sha256'] = $promptSha256;
    }

    return [
        'normalized_input' => $normalized,
        'plan' => $planCore + [
            'plan_sha256' => hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($planCore)),
        ],
        'model' => [
            'label' => 'VoxCPM2',
            'version' => '2.0.3',
            'sample_rate' => 48000,
        ],
        'voice_context' => $voiceCore + [
            'sha256' => hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($voiceCore)),
        ],
        'controls' => [
            'mode' => $mode,
            'seed_policy' => $seedPolicy,
            'task_seed' => $taskSeed,
        ],
        'chunks' => [[
            'id' => $chunkId,
            'seed' => $seed,
            'seed_sha256' => $seedSha256,
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
        'final_format' => [
            'mime_type' => 'audio/wav',
            'sample_rate' => 48000,
            'channels' => 1,
            'frames' => 12000,
        ],
        'loudness' => [
            'passes' => 1,
            'target_lufs' => -16.0,
            'gain' => 1.0,
        ],
        'timeline' => [[
            'chunk_id' => $chunkId,
            'start_frame' => 0,
            'end_frame' => 12000,
            'sample_rate' => 48000,
        ]],
        'device' => $device,
    ];
}

function hub_test_voxcpm2_cluster_runner_input(
    string $referenceSha256,
    string $promptSha256,
    string $targetText,
    string $mode,
    string $control = '',
    int $taskSeed = 42,
    string $seedPolicy = 'derived_per_chunk',
): array {
    $input = [
        'text' => $targetText,
        'mode' => $mode,
        'seed' => $taskSeed,
        'seed_policy' => $seedPolicy,
        'model' => 'voxcpm2',
    ];
    if ($control !== '') {
        $input['control'] = $control;
    }
    if ($mode === 'design') {
        return $input;
    }
    $input['voice_profile_id'] = 17;
    $input['voice_context'] = [
        'mode' => $mode,
        'voice_profile_id' => 17,
        'reference_audio_sha256' => $referenceSha256,
    ];
    if ($mode === 'ultimate_clone') {
        $input['voice_context'] += [
            'prompt_text_sha256' => $promptSha256,
            'prompt_text_confirmed_at' => '2026-07-31 12:00:00',
        ];
    }
    $input['voice_context']['container_path'] = '/data/voice_profiles/reference.wav';

    return $input;
}

function hub_test_voxcpm2_cluster_runner_rechunk(
    array $metadata,
    array $texts,
    array $boundaries,
): array {
    if ($texts === [] || count($texts) !== count($boundaries)) {
        throw new RuntimeException('Invalid production metadata rechunk fixture.');
    }
    $taskSeed = $metadata['plan']['task_seed'];
    $seedPolicy = $metadata['plan']['seed_policy'];
    $planChunks = [];
    $chunks = [];
    $timeline = [];
    $previousEnd = 0;
    foreach ($texts as $index => $text) {
        $chunkId = sprintf('chunk-%04d', $index + 1);
        $seedSha256 = hash(
            'sha256',
            $seedPolicy === 'fixed' ? (string)$taskSeed : $taskSeed . $chunkId
        );
        $seed = $seedPolicy === 'fixed'
            ? $taskSeed
            : (int)(hexdec(substr($seedSha256, 8, 8)) % 2147483648);
        $boundary = $boundaries[$index];
        $durationFrames = 12000;
        $startFrame = $previousEnd;
        $renderedFrames = $durationFrames;
        if ($boundary['action'] === 'silence_insert') {
            $startFrame += $boundary['pause_frames'];
        } elseif ($boundary['action'] === 'trim_then_pause') {
            $startFrame += $boundary['pause_frames'] - min($boundary['trim_frames'], $previousEnd);
        } elseif ($boundary['action'] === 'crossfade') {
            $renderedFrames -= min($boundary['crossfade_frames'], $previousEnd, $durationFrames);
        }
        $endFrame = $startFrame + $renderedFrames;
        $planChunks[] = [
            'id' => $chunkId,
            'text' => $text,
            'text_sha256' => hash('sha256', $text),
            'seed' => $seed,
            'seed_sha256' => $seedSha256,
        ];
        $chunks[] = [
            'id' => $chunkId,
            'seed' => $seed,
            'seed_sha256' => $seedSha256,
            'attempts' => 1,
            'duration_frames' => $durationFrames,
            'duration_seconds' => $durationFrames / 48000,
            'peak_gain' => 1.0,
            'reused_checkpoint' => false,
        ] + $boundary;
        $timeline[] = [
            'chunk_id' => $chunkId,
            'start_frame' => $startFrame,
            'end_frame' => $endFrame,
            'sample_rate' => 48000,
        ];
        $previousEnd = $endFrame;
    }
    $planCore = [
        'normalization' => 'semantic-v1',
        'normalized_input' => implode('', $texts),
        'max_chunk_chars' => 240,
        'task_seed' => $taskSeed,
        'seed_policy' => $seedPolicy,
        'chunks' => $planChunks,
    ];
    $metadata['normalized_input'] = $planCore['normalized_input'];
    $metadata['plan'] = $planCore + [
        'plan_sha256' => hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($planCore)),
    ];
    $metadata['chunks'] = $chunks;
    $metadata['timeline'] = $timeline;
    $metadata['final_format']['frames'] = $previousEnd;

    return $metadata;
}

hub_test('VoxCPM2 public metadata enforces the current schema and only normalizes legacy Pack versions', function (): void {
    $db = hub_test_reset_db();
    $pathFreeMetadata = hub_test_voxcpm2_cluster_runner_metadata(
        str_repeat('a', 64),
        str_repeat('b', 64),
        'Version-bound public metadata.'
    );
    $metadata = $pathFreeMetadata;
    $metadata['model'] = ['model' => '/models/voxcpm2/model'] + $metadata['model'];
    $legacyVoice = $metadata['voice_context'];
    unset($legacyVoice['sha256']);
    $legacyVoice['container_path'] = '/data/voice_profiles/reference.wav';
    $metadata['voice_context'] = $legacyVoice + [
        'sha256' => hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($legacyVoice)),
    ];
    $legacyJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $makeArtifact = static function (string $version, string $name, string $json, array $input = []) use ($db): array {
        $taskId = hub_enqueue_task($db, 'pack_job', 'gpu', 0, $input, null, '203.0.113.44', [
            'requested_mode' => 'voice_generate',
            'pack_id' => 'tts-voxcpm2',
            'pack_version' => $version,
            'job' => 'synthesize',
        ]);
        $path = hub_task_result_dir($taskId) . '/' . $name;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Cannot create version-bound metadata fixture.');
        }
        file_put_contents($path, $json, LOCK_EX);

        return [$taskId, [
            'name' => $name,
            'artifact_type' => 'synthesis_metadata',
            'path' => $path,
            'mime_type' => 'application/json',
            'size_bytes' => strlen($json),
            'sha256' => hash('sha256', $json),
        ]];
    };
    $audioProbe = [
        'duration_seconds' => 0.25,
        'sample_rate' => 48000,
        'channels' => 1,
        'frames' => 12000,
    ];

    $canonicalCases = [
        'design' => ["Canonical \n design metadata.", 'steady', 7, 'fixed', ['type' => 'fake', 'real_inference' => false]],
        'clone' => ['Canonical clone metadata.', 'calm', 41, 'derived_per_chunk', ['type' => 'cuda', 'real_inference' => true]],
        'ultimate_clone' => ['Canonical ultimate_clone metadata.', '', 42, 'derived_per_chunk', ['type' => 'cuda', 'real_inference' => true]],
    ];
    foreach ($canonicalCases as $mode => [$targetText, $control, $taskSeed, $seedPolicy, $device]) {
        $currentMetadata = hub_test_voxcpm2_cluster_runner_metadata(
            str_repeat('a', 64),
            str_repeat('b', 64),
            $targetText,
            $mode,
            $control,
            $taskSeed,
            $seedPolicy,
            $device
        );
        $currentJson = json_encode(
            $currentMetadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        [$currentTaskId, $currentArtifact] = $makeArtifact(
            '0.1.8',
            'current-' . $mode . '-metadata.json',
            $currentJson,
            hub_test_voxcpm2_cluster_runner_input(
                str_repeat('a', 64),
                str_repeat('b', 64),
                $targetText,
                $mode,
                $control,
                $taskSeed,
                $seedPolicy
            )
        );
        hub_test_assert(
            hub_voxcpm2_public_metadata_artifact(
                $db,
                $currentTaskId,
                $currentArtifact,
                $audioProbe
            ) === $currentArtifact,
            '0.1.8 must accept canonical ' . $mode . ' metadata without rewriting it'
        );
    }

    foreach (['0.1.4', '0.1.5'] as $legacyVersion) {
        [$legacyTaskId, $legacyArtifact] = $makeArtifact(
            $legacyVersion,
            'legacy-' . str_replace('.', '-', $legacyVersion) . '-metadata.json',
            $legacyJson
        );
        $normalized = hub_voxcpm2_public_metadata_artifact($db, $legacyTaskId, $legacyArtifact);
        $publicJson = (string)file_get_contents($normalized['path']);
        $public = json_decode($publicJson, true, 64, JSON_THROW_ON_ERROR);
        $publicVoice = $public['voice_context'] ?? [];
        $publicVoiceSha256 = is_array($publicVoice) ? (string)($publicVoice['sha256'] ?? '') : '';
        if (is_array($publicVoice)) {
            unset($publicVoice['sha256']);
        }
        hub_test_assert(
            !str_contains($publicJson, '/models/')
            && !str_contains($publicJson, '/data/voice_profiles/')
            && !str_contains($publicJson, 'container_path')
            && is_array($publicVoice)
            && hash_equals(
                hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($publicVoice)),
                $publicVoiceSha256
            )
            && (int)$normalized['size_bytes'] === strlen($publicJson)
            && hash_equals((string)$normalized['sha256'], hash('sha256', $publicJson)),
            'stored ' . $legacyVersion . ' tasks must normalize fixed legacy paths and bind the rewritten public artifact'
        );
    }

    $invalidCases = [
        'substituted normalized plaintext with matching digests' => static function (array $value): array {
            $substituted = 'Private prompt plaintext must not replace the target.';
            $value['normalized_input'] = $substituted;
            $value['plan']['normalized_input'] = $substituted;
            $value['plan']['chunks'][0]['text'] = $substituted;
            $value['plan']['chunks'][0]['text_sha256'] = hash('sha256', $substituted);
            $planCore = $value['plan'];
            unset($planCore['plan_sha256']);
            $value['plan']['plan_sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($planCore)
            );
            return $value;
        },
        'substituted control token with matching voice digest' => static function (array $value): array {
            $value['voice_context']['control'] = 'cluster-token-secret';
            $voiceCore = $value['voice_context'];
            unset($voiceCore['sha256']);
            $value['voice_context']['sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($voiceCore)
            );
            return $value;
        },
        'substituted mode with matching voice digest' => static function (array $value): array {
            $value['controls']['mode'] = 'clone';
            $value['voice_context']['mode'] = 'clone';
            unset($value['voice_context']['prompt_text_sha256']);
            $voiceCore = $value['voice_context'];
            unset($voiceCore['sha256']);
            $value['voice_context']['sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($voiceCore)
            );
            return $value;
        },
        'substituted seed with matching plan digests' => static function (array $value): array {
            $taskSeed = 99;
            $chunkId = $value['plan']['chunks'][0]['id'];
            $seedSha256 = hash('sha256', $taskSeed . $chunkId);
            $seed = (int)(hexdec(substr($seedSha256, 8, 8)) % 2147483648);
            $value['controls']['task_seed'] = $taskSeed;
            $value['plan']['task_seed'] = $taskSeed;
            $value['plan']['chunks'][0]['seed'] = $seed;
            $value['plan']['chunks'][0]['seed_sha256'] = $seedSha256;
            $value['chunks'][0]['seed'] = $seed;
            $value['chunks'][0]['seed_sha256'] = $seedSha256;
            $planCore = $value['plan'];
            unset($planCore['plan_sha256']);
            $value['plan']['plan_sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($planCore)
            );
            return $value;
        },
        'substituted Profile reference hash with matching voice digest' => static function (array $value): array {
            $value['voice_context']['reference_audio_sha256'] = str_repeat('c', 64);
            $voiceCore = $value['voice_context'];
            unset($voiceCore['sha256']);
            $value['voice_context']['sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($voiceCore)
            );
            return $value;
        },
        'substituted Profile prompt hash with matching voice digest' => static function (array $value): array {
            $value['voice_context']['prompt_text_sha256'] = str_repeat('d', 64);
            $voiceCore = $value['voice_context'];
            unset($voiceCore['sha256']);
            $value['voice_context']['sha256'] = hash(
                'sha256',
                hub_test_voxcpm2_cluster_runner_canonical_json($voiceCore)
            );
            return $value;
        },
        'voice digest mismatch' => static function (array $value): array {
            $value['voice_context']['sha256'] = str_repeat('f', 64);
            return $value;
        },
        'plan digest path substitution' => static function (array $value): array {
            $value['plan']['plan_sha256'] = '/srv/private/chunks.json';
            return $value;
        },
        'chunk digest token substitution' => static function (array $value): array {
            $value['plan']['chunks'][0]['text_sha256'] = 'cluster-token-secret';
            return $value;
        },
        'seed digest mismatch' => static function (array $value): array {
            $value['plan']['chunks'][0]['seed_sha256'] = str_repeat('e', 64);
            $value['chunks'][0]['seed_sha256'] = str_repeat('e', 64);
            return $value;
        },
        'Profile hash path substitution' => static function (array $value): array {
            $value['voice_context']['reference_audio_sha256'] = '/data/voice_profiles/reference.wav';
            return $value;
        },
        'Profile prompt plaintext substitution' => static function (array $value): array {
            $value['voice_context']['prompt_text_sha256'] = 'confirmed prompt plaintext';
            return $value;
        },
        'model constant substitution' => static function (array $value): array {
            $value['model']['version'] = 'private/model/path';
            return $value;
        },
        'chunk action path substitution' => static function (array $value): array {
            $value['chunks'][0]['action'] = '/srv/private/output.wav';
            return $value;
        },
        'chunk duration inconsistency' => static function (array $value): array {
            $value['chunks'][0]['duration_seconds'] = 4.25;
            return $value;
        },
        'chunk retry range substitution' => static function (array $value): array {
            $value['chunks'][0]['attempts'] = 4;
            return $value;
        },
        'timeline final frame inconsistency' => static function (array $value): array {
            $value['timeline'][0]['end_frame'] = 11999;
            return $value;
        },
        'final format frame inconsistency' => static function (array $value): array {
            $value['final_format']['frames'] = 11999;
            return $value;
        },
        'loudness constant substitution' => static function (array $value): array {
            $value['loudness']['target_lufs'] = -14.0;
            return $value;
        },
        'loudness gain range substitution' => static function (array $value): array {
            $value['loudness']['gain'] = 0.2;
            return $value;
        },
        'device attestation mismatch' => static function (array $value): array {
            $value['device'] = ['type' => 'fake', 'real_inference' => true];
            return $value;
        },
        'top-level host_path' => static function (array $value): array {
            $value['host_path'] = '/srv/private/model';
            return $value;
        },
        'model.model' => static function (array $value): array {
            $value['model']['model'] = '/models/voxcpm2/model';
            return $value;
        },
        'model.weights_path' => static function (array $value): array {
            $value['model']['weights_path'] = '/models/voxcpm2/weights';
            return $value;
        },
        'voice_context.container_path' => static function (array $value): array {
            $value['voice_context']['container_path'] = '/data/voice_profiles/reference.wav';
            return $value;
        },
        'voice_context.host_path' => static function (array $value): array {
            $value['voice_context']['host_path'] = '/srv/private/reference.wav';
            return $value;
        },
        'plan extra key' => static function (array $value): array {
            $value['plan']['host_path'] = '/srv/private/plan';
            return $value;
        },
        'plan chunk extra key' => static function (array $value): array {
            $value['plan']['chunks'][0]['host_path'] = '/srv/private/chunk';
            return $value;
        },
        'chunk extra key' => static function (array $value): array {
            $value['chunks'][0]['host_path'] = '/srv/private/audio';
            return $value;
        },
        'timeline extra key' => static function (array $value): array {
            $value['timeline'][0]['host_path'] = '/srv/private/timeline';
            return $value;
        },
        'final_format extra key' => static function (array $value): array {
            $value['final_format']['host_path'] = '/srv/private/output.wav';
            return $value;
        },
        'loudness extra key' => static function (array $value): array {
            $value['loudness']['host_path'] = '/srv/private/loudness';
            return $value;
        },
        'device extra key' => static function (array $value): array {
            $value['device']['host_path'] = '/dev/private';
            return $value;
        },
        'object-shaped plan chunks' => static function (array $value): array {
            $value['plan']['chunks'] = ['chunk' => $value['plan']['chunks'][0]];
            return $value;
        },
        'object-shaped chunks' => static function (array $value): array {
            $value['chunks'] = ['chunk' => $value['chunks'][0]];
            return $value;
        },
        'object-shaped timeline' => static function (array $value): array {
            $value['timeline'] = ['event' => $value['timeline'][0]];
            return $value;
        },
        'list-shaped model' => static function (array $value): array {
            $value['model'] = array_values($value['model']);
            return $value;
        },
    ];
    $currentInput = hub_test_voxcpm2_cluster_runner_input(
        str_repeat('a', 64),
        str_repeat('b', 64),
        'Version-bound public metadata.',
        'ultimate_clone'
    );
    foreach ($invalidCases as $case => $mutate) {
        $currentMetadata = $mutate($pathFreeMetadata);
        $currentJson = json_encode(
            $currentMetadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        [$currentTaskId, $currentArtifact] = $makeArtifact(
            '0.1.8',
            'current-' . $case . '-metadata.json',
            $currentJson,
            $currentInput
        );
        $rejected = null;
        try {
            hub_voxcpm2_public_metadata_artifact(
                $db,
                $currentTaskId,
                $currentArtifact,
                $audioProbe
            );
        } catch (Throwable $error) {
            $rejected = $error->getMessage();
        }
        hub_test_assert(
            $rejected === 'validated_artifact_invalid',
            '0.1.8 must reject metadata with ' . $case
        );
    }

    $overlongText = str_repeat('word ', 48) . 'x';
    hub_test_assert(
        !hub_voxcpm2_public_metadata_schema_valid(
            hub_test_voxcpm2_cluster_runner_metadata(
                str_repeat('a', 64),
                str_repeat('b', 64),
                $overlongText,
                'design'
            ),
            hub_test_voxcpm2_cluster_runner_input(
                str_repeat('a', 64),
                str_repeat('b', 64),
                $overlongText,
                'design'
            ),
            $audioProbe
        ),
        '0.1.6 must reject an internally consistent unsplit chunk above max_chunk_chars'
    );

    $protectedText = str_repeat('A', 250);
    $protectedInput = hub_test_voxcpm2_cluster_runner_input(
        str_repeat('a', 64),
        str_repeat('b', 64),
        $protectedText,
        'design'
    );
    $protectedMetadata = hub_test_voxcpm2_cluster_runner_metadata(
        str_repeat('a', 64),
        str_repeat('b', 64),
        $protectedText,
        'design'
    );
    hub_test_assert(
        hub_voxcpm2_public_metadata_schema_valid(
            $protectedMetadata,
            $protectedInput,
            $audioProbe
        ),
        '0.1.6 must accept the planner protected-string extension above max_chunk_chars'
    );
    $protectedAlternative = hub_test_voxcpm2_cluster_runner_rechunk(
        $protectedMetadata,
        [substr($protectedText, 0, 240), substr($protectedText, 240)],
        [[
            'action' => 'direct_concat',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 0,
        ], [
            'action' => 'crossfade',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 1920,
        ]]
    );
    $protectedAlternativeProbe = [
        'duration_seconds' => $protectedAlternative['final_format']['frames'] / 48000,
        'sample_rate' => 48000,
        'channels' => 1,
        'frames' => $protectedAlternative['final_format']['frames'],
    ];
    hub_test_assert(
        !hub_voxcpm2_public_metadata_schema_valid(
            $protectedAlternative,
            $protectedInput,
            $protectedAlternativeProbe
        ),
        '0.1.6 must reject resegmentation of a canonical protected-string extension'
    );

    $probeMismatchMetadata = hub_test_voxcpm2_cluster_runner_metadata(
        str_repeat('a', 64),
        str_repeat('b', 64),
        'Probe-bound target.',
        'design'
    );
    $probeMismatchJson = json_encode(
        $probeMismatchMetadata,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    [$probeMismatchTaskId, $probeMismatchArtifact] = $makeArtifact(
        '0.1.6',
        'probe-mismatch-metadata.json',
        $probeMismatchJson,
        hub_test_voxcpm2_cluster_runner_input(
            str_repeat('a', 64),
            str_repeat('b', 64),
            'Probe-bound target.',
            'design'
        )
    );
    $probeWav = tempnam(sys_get_temp_dir(), 'voxcpm2-metadata-probe-');
    if ($probeWav === false) {
        throw new RuntimeException('Cannot create VoxCPM2 metadata probe fixture.');
    }
    $probeFrames = 24000;
    $probePcm = str_repeat("\0\0", $probeFrames);
    if (file_put_contents(
        $probeWav,
        'RIFF'
            . pack('V', 36 + strlen($probePcm))
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16)
            . 'data'
            . pack('V', strlen($probePcm))
            . $probePcm,
        LOCK_EX
    ) === false) {
        unlink($probeWav);
        throw new RuntimeException('Cannot write VoxCPM2 metadata probe fixture.');
    }
    $actualWavProbe = [];
    $siblingWavProbe = null;
    $probeMismatchRejected = null;
    try {
        $actualWavProbe = hub_pack_job_validate_audio_output($probeWav, ['audio' => []], null);
        $siblingWavProbe = hub_voxcpm2_generated_audio_probe([[
            'artifact_type' => 'generated_audio',
            'metadata' => $actualWavProbe,
        ]]);
        hub_voxcpm2_public_metadata_artifact(
            $db,
            $probeMismatchTaskId,
            $probeMismatchArtifact,
            $siblingWavProbe
        );
    } catch (Throwable $error) {
        $probeMismatchRejected = $error->getMessage();
    } finally {
        unlink($probeWav);
    }
    hub_test_assert(
        ($actualWavProbe['sample_rate'] ?? null) === 48000
        && ($actualWavProbe['channels'] ?? null) === 1
        && ($actualWavProbe['frames'] ?? null) === $probeFrames
        && $siblingWavProbe === $actualWavProbe
        && $probeMismatchRejected === 'validated_artifact_invalid',
        '0.1.6 metadata final frames must match the completed generated WAV probe'
    );

    $firstCanonicalText = str_repeat('A', 200) . '。';
    $secondCanonicalText = str_repeat('B', 50) . '。';
    $longText = $firstCanonicalText . $secondCanonicalText;
    $longInput = hub_test_voxcpm2_cluster_runner_input(
        str_repeat('a', 64),
        str_repeat('b', 64),
        $longText,
        'design'
    );
    $direct = [
        'action' => 'direct_concat',
        'trim_frames' => 0,
        'pause_frames' => 0,
        'crossfade_frames' => 0,
    ];
    $canonicalBoundary = [
        'action' => 'silence_insert',
        'trim_frames' => 0,
        'pause_frames' => 11040,
        'crossfade_frames' => 0,
    ];
    $longMetadata = hub_test_voxcpm2_cluster_runner_rechunk(
        hub_test_voxcpm2_cluster_runner_metadata(
            str_repeat('a', 64),
            str_repeat('b', 64),
            $longText,
            'design'
        ),
        [$firstCanonicalText, $secondCanonicalText],
        [$direct, $canonicalBoundary]
    );
    $longProbe = [
        'duration_seconds' => $longMetadata['final_format']['frames'] / 48000,
        'sample_rate' => 48000,
        'channels' => 1,
        'frames' => $longMetadata['final_format']['frames'],
    ];
    hub_test_assert(
        hub_voxcpm2_public_metadata_schema_valid($longMetadata, $longInput, $longProbe),
        '0.1.6 must accept the unique semantic-v1 split and boundary plan'
    );

    $alternativeFirst = str_repeat('A', 180);
    $alternativeChunks = hub_test_voxcpm2_cluster_runner_rechunk(
        $longMetadata,
        [$alternativeFirst, substr($longText, strlen($alternativeFirst))],
        [$direct, [
            'action' => 'crossfade',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 1920,
        ]]
    );
    $alternativeChunksProbe = [
        'duration_seconds' => $alternativeChunks['final_format']['frames'] / 48000,
        'sample_rate' => 48000,
        'channels' => 1,
        'frames' => $alternativeChunks['final_format']['frames'],
    ];
    hub_test_assert(
        !hub_voxcpm2_public_metadata_schema_valid(
            $alternativeChunks,
            $longInput,
            $alternativeChunksProbe
        ),
        '0.1.6 must reject an internally consistent alternative semantic-v1 chunk split'
    );

    $alternativeBoundary = hub_test_voxcpm2_cluster_runner_rechunk(
        $longMetadata,
        [$firstCanonicalText, $secondCanonicalText],
        [$direct, [
            'action' => 'crossfade',
            'trim_frames' => 0,
            'pause_frames' => 0,
            'crossfade_frames' => 1920,
        ]]
    );
    $alternativeBoundaryProbe = [
        'duration_seconds' => $alternativeBoundary['final_format']['frames'] / 48000,
        'sample_rate' => 48000,
        'channels' => 1,
        'frames' => $alternativeBoundary['final_format']['frames'],
    ];
    hub_test_assert(
        !hub_voxcpm2_public_metadata_schema_valid(
            $alternativeBoundary,
            $longInput,
            $alternativeBoundaryProbe
        ),
        '0.1.6 must reject an internally consistent substituted boundary action and timeline'
    );
});

hub_test('VoxCPM2 private request write failure leaves no prompt bytes when rename and unlink fail', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_request_' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create private request cleanup fixture.');
    }
    $requestPath = $root . '/request.json';
    $prompt = 'Private rename failure prompt must not remain.';
    $json = json_encode(
        ['text' => 'safe target', 'prompt_text' => $prompt],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $renameObserved = false;
    $errorCode = null;
    try {
        hub_pack_job_write_private_request(
            $requestPath,
            $json,
            static function (string $temporaryPath) use ($prompt, &$renameObserved): bool {
                $renameObserved = str_contains((string)file_get_contents($temporaryPath), $prompt);
                return false;
            },
            static fn (string $path): bool => false
        );
    } catch (Throwable $error) {
        $errorCode = $error->getMessage();
    }

    try {
        $retained = '';
        $retainedBytes = 0;
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $contents = (string)file_get_contents($path);
                $retained .= $contents;
                $retainedBytes += strlen($contents);
            }
        }
        hub_test_assert(
            $renameObserved
            && $errorCode === 'workspace_privacy_cleanup_failed'
            && $retainedBytes === 0
            && !str_contains($retained, $prompt)
            && !file_exists($requestPath),
            'failed private request publication must verify prompt-bearing files are absent or securely truncated'
        );
    } finally {
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($root);
    }
});

hub_test('VoxCPM2 private request chmod failure occurs before prompt bytes are written', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_chmod_' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create private chmod cleanup fixture.');
    }
    $requestPath = $root . '/request.json';
    $prompt = 'Private chmod failure prompt must not remain.';
    $json = json_encode(
        ['text' => 'safe chmod target', 'prompt_text' => $prompt],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $chmodObserved = false;
    $renameCalled = false;
    $errorCode = null;
    try {
        hub_pack_job_write_private_request(
            $requestPath,
            $json,
            static function () use (&$renameCalled): bool {
                $renameCalled = true;
                return false;
            },
            static fn (string $path): bool => false,
            static function (string $temporaryPath, int $mode) use ($prompt, &$chmodObserved): bool {
                $chmodObserved = $mode === 0600
                    && filesize($temporaryPath) === 0
                    && !str_contains((string)file_get_contents($temporaryPath), $prompt);
                return false;
            }
        );
    } catch (Throwable $error) {
        $errorCode = $error->getMessage();
    }

    try {
        $retained = '';
        $retainedBytes = 0;
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_file($path) && !is_link($path)) {
                $contents = (string)file_get_contents($path);
                $retained .= $contents;
                $retainedBytes += strlen($contents);
            }
        }
        hub_test_assert(
            $chmodObserved
            && !$renameCalled
            && $errorCode === 'workspace_privacy_cleanup_failed'
            && $retainedBytes === 0
            && !str_contains($retained, $prompt)
            && !file_exists($requestPath),
            'chmod failure must occur while the exclusive private request is still empty and leave no prompt bytes'
        );
    } finally {
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($root);
    }
});

hub_test('VoxCPM2 private request is restrictive before writing and clears stale hard links', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_mode_' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create restrictive private request fixture.');
    }
    $requestPath = $root . '/request.json';
    $stalePath = $root . '/request.private.0123456789abcdef';
    $staleLink = $root . '/stale-private-hardlink';
    $stalePrompt = 'Stale private prompt must be cleared through every hard link.';
    file_put_contents($stalePath, $stalePrompt, LOCK_EX);
    chmod($stalePath, 0644);
    if (!link($stalePath, $staleLink)) {
        throw new RuntimeException('Cannot create stale private request hard-link fixture.');
    }
    $prompt = 'New private prompt is written only after restrictive creation.';
    $json = json_encode(
        ['text' => 'safe restrictive target', 'prompt_text' => $prompt],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    $modeAtWrite = null;
    $emptyAtWrite = false;
    $oldUmask = umask(0002);
    try {
        hub_pack_job_write_private_request(
            $requestPath,
            $json,
            null,
            null,
            null,
            static function ($handle, string $temporaryPath, string $payload) use (&$modeAtWrite, &$emptyAtWrite): int {
                $stat = fstat($handle);
                $modeAtWrite = is_array($stat) ? ((int)$stat['mode'] & 0777) : null;
                $emptyAtWrite = is_file($temporaryPath)
                    && filesize($temporaryPath) === 0
                    && ftell($handle) === 0;
                $written = fwrite($handle, $payload);
                return is_int($written) ? $written : 0;
            }
        );
    } finally {
        umask($oldUmask);
    }

    try {
        $published = (string)file_get_contents($requestPath);
        $publishedStat = lstat($requestPath);
        hub_test_assert(
            $modeAtWrite === 0600
            && $emptyAtWrite
            && is_array($publishedStat)
            && (((int)$publishedStat['mode'] & 0777) === 0600)
            && str_contains($published, $prompt)
            && !file_exists($stalePath)
            && is_file($staleLink)
            && filesize($staleLink) === 0
            && !str_contains((string)file_get_contents($staleLink), $stalePrompt),
            'private request bytes must begin in a 0600 exclusive file after stale hard-linked material is cleared'
        );
    } finally {
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($root);
    }
});

hub_test('VoxCPM2 normal private prompt scrub clears every hard link to the old request inode', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_hardlink_' . bin2hex(random_bytes(12));
    $input = $root . '/input';
    if (!mkdir($input, 0700, true)) {
        throw new RuntimeException('Cannot create hard-linked scrub fixture.');
    }
    $requestPath = $input . '/request.json';
    $hardLinkPath = $root . '/retained-request-hardlink';
    $prompt = 'Hard-linked private prompt must not survive normal scrub.';
    file_put_contents(
        $requestPath,
        json_encode(
            ['text' => 'safe retained field', 'prompt_text' => $prompt],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL,
        LOCK_EX
    );
    chmod($requestPath, 0600);
    if (!link($requestPath, $hardLinkPath)) {
        throw new RuntimeException('Cannot create private request hard-link fixture.');
    }

    try {
        hub_pack_job_scrub_private_prompt($root);
        $scrubbedJson = (string)file_get_contents($requestPath);
        $scrubbed = json_decode($scrubbedJson, true, 32, JSON_THROW_ON_ERROR);
        hub_test_assert(
            ($scrubbed['text'] ?? null) === 'safe retained field'
            && !array_key_exists('prompt_text', $scrubbed)
            && !str_contains($scrubbedJson, $prompt)
            && is_file($hardLinkPath)
            && filesize($hardLinkPath) === 0
            && !str_contains((string)file_get_contents($hardLinkPath), $prompt),
            'normal scrub must truncate and flush the old prompt inode before replacing its request path'
        );
    } finally {
        foreach ([$requestPath, $hardLinkPath] as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($input);
        rmdir($root);
    }
});

hub_test('VoxCPM2 private cleanup revalidates a regular replacement after symlink unlink', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_symlink_race_' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create symlink replacement fixture.');
    }
    $targetPath = $root . '/unrelated-target';
    $requestPath = $root . '/request.private.0123456789abcdef';
    $replacementLink = $root . '/replacement-hardlink';
    $targetContents = 'Unrelated symlink target must remain untouched.';
    $prompt = 'Replacement private prompt must be securely cleared.';
    file_put_contents($targetPath, $targetContents, LOCK_EX);
    if (!symlink($targetPath, $requestPath)) {
        throw new RuntimeException('Cannot create private request symlink fixture.');
    }
    $unlinkCalls = 0;

    try {
        hub_pack_job_secure_remove_private_file(
            $requestPath,
            static function (string $path) use ($replacementLink, $prompt, &$unlinkCalls): bool {
                $unlinkCalls++;
                if ($unlinkCalls === 1) {
                    if (!unlink($path)) {
                        return false;
                    }
                    file_put_contents($path, $prompt, LOCK_EX);
                    if (!link($path, $replacementLink)) {
                        throw new RuntimeException('Cannot retain replacement inode fixture.');
                    }
                    return true;
                }

                return unlink($path);
            }
        );

        $requestSafe = !file_exists($requestPath)
            || (!is_link($requestPath)
                && is_file($requestPath)
                && filesize($requestPath) === 0
                && !str_contains((string)file_get_contents($requestPath), $prompt));
        hub_test_assert(
            $unlinkCalls === 2
            && $requestSafe
            && is_file($replacementLink)
            && filesize($replacementLink) === 0
            && !str_contains((string)file_get_contents($replacementLink), $prompt)
            && file_get_contents($targetPath) === $targetContents,
            'cleanup must revalidate the pathname and securely clear a regular replacement inode'
        );
    } finally {
        foreach ([$requestPath, $replacementLink, $targetPath] as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($root);
    }
});

hub_test('VoxCPM2 private cleanup fails closed after bounded regular replacement churn', function (): void {
    $root = sys_get_temp_dir() . '/3waaihub_private_regular_race_' . bin2hex(random_bytes(12));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Cannot create regular replacement fixture.');
    }
    $requestPath = $root . '/request.private.0123456789abcdef';
    $prompt = 'Repeated replacement prompt must not survive bounded cleanup.';
    file_put_contents($requestPath, $prompt, LOCK_EX);
    $unlinkCalls = 0;
    $replacementLinks = [];
    $errorCode = null;

    try {
        hub_pack_job_secure_remove_private_file(
            $requestPath,
            static function (string $path) use ($root, $prompt, &$unlinkCalls, &$replacementLinks): bool {
                $unlinkCalls++;
                if (!unlink($path)) {
                    return false;
                }
                file_put_contents($path, $prompt . ' #' . $unlinkCalls, LOCK_EX);
                $linkPath = $root . '/replacement-hardlink-' . $unlinkCalls;
                if (!link($path, $linkPath)) {
                    throw new RuntimeException('Cannot retain repeated replacement inode fixture.');
                }
                $replacementLinks[] = $linkPath;
                return true;
            }
        );
    } catch (Throwable $error) {
        $errorCode = $error->getMessage();
    }

    try {
        $requestSafe = is_file($requestPath)
            && !is_link($requestPath)
            && filesize($requestPath) === 0
            && !str_contains((string)file_get_contents($requestPath), $prompt);
        $linksSafe = $replacementLinks !== [];
        foreach ($replacementLinks as $linkPath) {
            $linksSafe = $linksSafe
                && is_file($linkPath)
                && filesize($linkPath) === 0
                && !str_contains((string)file_get_contents($linkPath), $prompt);
        }
        hub_test_assert(
            $errorCode === 'workspace_privacy_cleanup_failed'
            && $unlinkCalls >= 1
            && $unlinkCalls <= 4
            && $requestSafe
            && $linksSafe,
            'unstable private path cleanup must stop after bounded retries and leave every observed inode clear'
        );
    } finally {
        foreach (array_merge([$requestPath], $replacementLinks) as $path) {
            if (is_link($path) || is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
        rmdir($root);
    }
});

function hub_test_voxcpm2_cluster_acceptance_profile(
    string $referenceSha256,
    string $status = 'active',
): array
{
    return [
        'ok' => true,
        'task_status' => 'success',
        'profile_status' => $status,
        'transcription_status' => 'ready',
        'transcript_confirmed' => true,
        'prompt_text_confirmed_at' => '2026-07-31 12:00:00',
        'profile_name' => 'VoxCPM2 Cluster Acceptance',
        'language' => null,
        'consent_type' => 'self_recorded',
        'reference_audio_sha256' => $referenceSha256,
        'created_at' => '2026-07-31 11:00:00',
        'updated_at' => '2026-07-31 12:00:00',
    ];
}

function hub_test_voxcpm2_cluster_acceptance_transport(
    array &$requests,
    bool $validMetadata = true,
    bool $deleteSucceeds = true,
): callable {
    $profileTaskId = 'route_' . str_repeat('a', 34);
    $synthesisTaskId = 'route_' . str_repeat('b', 34);
    $genericTaskId = 'route_' . str_repeat('c', 34);
    $audio = "RIFF" . pack('V', 36) . "WAVEfmt " . pack('VvvVVvv', 16, 1, 1, 48000, 96000, 2, 16) . "data" . pack('V', 0);
    $referenceSha256 = null;
    $promptSha256 = null;
    $targetText = null;
    $json = static fn (array $payload, int $status = 200): array => [
        'status' => $status,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ];

    return static function (array $request) use (
        &$requests,
        $profileTaskId,
        $synthesisTaskId,
        $genericTaskId,
        $audio,
        &$referenceSha256,
        &$promptSha256,
        &$targetText,
        $validMetadata,
        $deleteSucceeds,
        $json,
    ): array {
        $parts = parse_url((string)($request['url'] ?? ''));
        parse_str((string)($parts['query'] ?? ''), $query);
        $requests[] = [
            'method' => $request['method'] ?? null,
            'url' => $request['url'] ?? null,
            'max_body_bytes' => $request['max_body_bytes'] ?? null,
            'operation' => null,
        ];
        $requestIndex = array_key_last($requests);
        hub_test_assert(($request['follow_redirects'] ?? true) === false, 'Cluster acceptance must disable redirects');
        hub_test_assert(
            (($request['headers']['Authorization'] ?? null) === 'Bearer voxcpm2-cluster-unit-token-secret'),
            'Cluster acceptance must use its supplied token only in the request header'
        );

        if (($query['mode'] ?? '') === 'voice_generate') {
            if (is_array($request['body'] ?? null)) {
                $fields = $request['body'];
                $requests[$requestIndex]['operation'] = $fields['operation'] ?? null;
                hub_test_assert(
                    ($fields['operation'] ?? '') === 'profile_prepare'
                    && ($fields['prompt_text'] ?? '') === 'Confirmed private prompt text.'
                    && ($fields['transcript_confirmed'] ?? '') === '1'
                    && ($fields['expires_in_seconds'] ?? '') === '3600'
                    && ($fields['profile_name'] ?? '') === 'VoxCPM2 Cluster Acceptance'
                    && ($fields['consent_type'] ?? '') === 'self_recorded'
                    && ($fields['reference_wav'] ?? null) instanceof CURLFile,
                    'profile_prepare must carry only the consented configured material and safe fixed controls'
                );
                $referenceSha256 = hash_file('sha256', $fields['reference_wav']->getFilename());
                $promptSha256 = hash('sha256', (string)$fields['prompt_text']);
                return $json(['ok' => true, 'task_id' => $profileTaskId] + hub_test_voxcpm2_cluster_acceptance_links($profileTaskId));
            }
            $body = json_decode((string)($request['body'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
            $requests[$requestIndex]['operation'] = $body['operation'] ?? 'synthesize';
            if (($body['operation'] ?? '') === 'profile_status') {
                return $json(hub_test_voxcpm2_cluster_acceptance_profile((string)$referenceSha256));
            }
            if (($body['operation'] ?? '') === 'profile_delete') {
                return $deleteSucceeds
                    ? $json(hub_test_voxcpm2_cluster_acceptance_profile((string)$referenceSha256, 'deleted'))
                    : $json(['ok' => false], 500);
            }
            if (($body['operation'] ?? '') === 'generic_synthesize') {
                hub_test_assert(
                    array_keys($body) === ['operation', 'text', 'gender', 'age_bucket', 'role_note', 'candidate_count']
                    && ($body['text'] ?? '') === 'Generate this private target text.'
                    && ($body['gender'] ?? '') === 'unspecified'
                    && ($body['age_bucket'] ?? '') === 'young_adult'
                    && ($body['role_note'] ?? '') === '聲音探索驗收，不屬於既有角色。'
                    && ($body['candidate_count'] ?? null) === 2
                    && !array_key_exists('voice_profile_task_id', $body)
                    && !array_key_exists('mode', $body),
                    'generic acceptance must submit only the fixed semantic exploration request'
                );
                return $json(['ok' => true, 'task_id' => $genericTaskId] + hub_test_voxcpm2_cluster_acceptance_links($genericTaskId, true));
            }
            hub_test_assert(
                !array_key_exists('operation', $body)
                && ($body['mode'] ?? '') === 'ultimate_clone'
                && ($body['voice_profile_task_id'] ?? '') === $profileTaskId
                && ($body['text'] ?? '') === 'Generate this private target text.'
                && ($body['waveform_preview'] ?? null) === false,
                'ultimate_clone acceptance must exercise the omitted-operation synthesis contract'
            );
            $targetText = (string)$body['text'];
            return $json(['ok' => true, 'task_id' => $synthesisTaskId] + hub_test_voxcpm2_cluster_acceptance_links($synthesisTaskId, true));
        }
        if (($query['mode'] ?? '') === 'cluster_task_status') {
            return $json(['ok' => true, 'task_id' => (string)$query['task_id'], 'status' => 'success']);
        }
        if (($query['mode'] ?? '') === 'cluster_task_result') {
            if (($query['task_id'] ?? '') === $profileTaskId) {
                return $json([
                    'ok' => true,
                    'task_id' => $profileTaskId,
                    'result' => [
                        'kind' => 'voice_profile_prepare',
                        'transcription_status' => 'ready',
                        'transcript_confirmed' => true,
                        'text_chars' => 30,
                        'prompt_text_sha256' => $promptSha256,
                    ],
                ]);
            }
            if (($query['task_id'] ?? '') === $genericTaskId) {
                return $json([
                    'ok' => true,
                    'task_id' => $genericTaskId,
                    'result' => ['candidates' => [
                        [
                            'candidate_id' => 'candidate-01',
                            'audio_artifact_id' => 19,
                            'seed' => 401,
                            'voice_design_revision' => 1,
                            'style_status' => 'unverified',
                            'audio_url' => 'cluster_api.php?mode=cluster_artifact&task_id=' . $genericTaskId . '&artifact_id=19',
                        ],
                        [
                            'candidate_id' => 'candidate-02',
                            'audio_artifact_id' => 20,
                            'seed' => 402,
                            'voice_design_revision' => 1,
                            'style_status' => 'unverified',
                            'audio_url' => 'cluster_api.php?mode=cluster_artifact&task_id=' . $genericTaskId . '&artifact_id=20',
                        ],
                    ]],
                ]);
            }
            $metadata = json_encode(hub_test_voxcpm2_cluster_runner_metadata(
                (string)$referenceSha256,
                (string)$promptSha256,
                $validMetadata ? (string)$targetText : 'stale target material'
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $json([
                'ok' => true,
                'task_id' => $synthesisTaskId,
                'result' => ['artifacts' => [
                    [
                        'id' => 17,
                        'type' => 'generated_audio',
                        'mime_type' => 'audio/wav',
                        'size_bytes' => strlen($audio),
                        'sha256' => hash('sha256', $audio),
                    ],
                    [
                        'id' => 18,
                        'type' => 'synthesis_metadata',
                        'mime_type' => 'application/json',
                        'size_bytes' => strlen($metadata),
                        'sha256' => hash('sha256', $metadata),
                    ],
                ]],
            ]);
        }
        if (($query['mode'] ?? '') === 'cluster_artifact') {
            if (($query['task_id'] ?? '') === $genericTaskId) {
                return ['status' => 200, 'headers' => ['Content-Type' => 'audio/wav'], 'body' => $audio];
            }
            $metadata = json_encode(hub_test_voxcpm2_cluster_runner_metadata(
                (string)$referenceSha256,
                (string)$promptSha256,
                $validMetadata ? (string)$targetText : 'stale target material'
            ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return (string)($query['artifact_id'] ?? '') === '17'
                ? ['status' => 200, 'headers' => ['Content-Type' => 'audio/wav'], 'body' => $audio]
                : ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => $metadata];
        }
        if (($query['mode'] ?? '') === 'cluster_task_artifacts_ack') {
            hub_test_assert(($request['method'] ?? '') === 'POST' && ($request['body'] ?? null) === '', 'artifact ACK must use the returned standard POST link');
            return $json(['ok' => true, 'task_id' => (string)($query['task_id'] ?? '')]);
        }

        throw new RuntimeException('Unexpected offline VoxCPM2 Cluster acceptance request.');
    };
}

hub_test('VoxCPM2 Cluster acceptance CLI statically keeps a bounded non-leaking surface', function (): void {
    $path = HUB_ROOT . '/scripts/voxcpm2_cluster_acceptance.php';
    hub_test_assert(is_file($path), 'VoxCPM2 Cluster acceptance CLI missing');
    $source = (string)file_get_contents($path);

    preg_match_all('/AIHUB_[A-Z0-9_]+/', $source, $matches);
    $environmentNames = array_values(array_unique($matches[0] ?? []));
    sort($environmentNames);
    $expectedEnvironmentNames = [
        'AIHUB_TEST_DATA_DIR',
        'AIHUB_TEST_DB',
        'AIHUB_VOXCPM2_CLUSTER_BASE_URL',
        'AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT',
        'AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV',
        'AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT',
        'AIHUB_VOXCPM2_CLUSTER_TOKEN',
    ];
    sort($expectedEnvironmentNames);
    hub_test_assert($environmentNames === $expectedEnvironmentNames, 'Cluster acceptance must use only the five material inputs and repository test guards');

    foreach ([
        'voice_generate',
        'cluster_task_status',
        'cluster_task_result',
        'cluster_task_log',
        'cluster_task_cancel',
        'cluster_artifact',
        'cluster_task_artifacts_ack',
        'profile_prepare',
        'profile_status',
        'profile_delete',
    ] as $endpoint) {
        hub_test_assert(str_contains($source, $endpoint), 'Cluster acceptance endpoint contract missing ' . $endpoint);
    }
    foreach ([
        'HUB_VOXCPM2_CLUSTER_CONNECT_TIMEOUT_SECONDS',
        'HUB_VOXCPM2_CLUSTER_REQUEST_TIMEOUT_SECONDS',
        'HUB_VOXCPM2_CLUSTER_POLL_TIMEOUT_SECONDS',
        'HUB_VOXCPM2_CLUSTER_TOTAL_TIMEOUT_SECONDS',
        'HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES',
        'HUB_VOXCPM2_CLUSTER_AUDIO_MAX_BYTES',
        'HUB_VOXCPM2_CLUSTER_HEADER_MAX_BYTES',
        'HUB_VOXCPM2_CLUSTER_HEADER_MAX_COUNT',
        'CURLOPT_FOLLOWLOCATION => false',
        'CURLOPT_MAXREDIRS => 0',
        'CURLOPT_PROXY => \'\'',
        'CURLOPT_NOPROXY => \'*\'',
        'hash_file(\'sha256\'',
        'RIFF',
        'WAVE',
        'controls',
        'ultimate_clone',
        'VoxCPM2',
        'real_inference',
        'proc_open($command',
        'set_error_handler',
        'finally',
        'pcntl_signal',
        'hub_voxcpm2_cluster_acceptance_remove_tree',
        '{"ok":true,"profile_prepared":true,"ultimate_clone":true,"generic_exploration":true,"audio_valid":true,"gpu":true,"artifacts_acknowledged":true}',
    ] as $needle) {
        hub_test_assert(str_contains($source, $needle), 'Cluster acceptance safety contract missing ' . $needle);
    }
    hub_test_assert(
        preg_match('/\b(?:exec|shell_exec|system|passthru|popen)\s*\(/', $source) !== 1
        && preg_match('/[\'"]curl[\'"]/', $source) !== 1
        && !str_contains($source, 'error_log(')
        && !str_contains($source, 'getMessage()')
        && !str_contains($source, 'app/bootstrap.php'),
        'Cluster acceptance must not shell curl, log exception values, or persist through application bootstrap'
    );
});

hub_test('VoxCPM2 Cluster acceptance caps cumulative response headers offline', function (): void {
    $headers = [];
    $bytes = 0;
    $count = 0;
    $tooLarge = false;
    for ($index = 0; $index < HUB_VOXCPM2_CLUSTER_HEADER_MAX_COUNT; $index++) {
        $line = "X-Acceptance-Header: bounded\r\n";
        hub_test_assert(
            hub_voxcpm2_cluster_acceptance_capture_header(
                $headers,
                $bytes,
                $count,
                $tooLarge,
                HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES,
                $line
            ) === strlen($line),
            'header callback must accept values within both cumulative bounds'
        );
    }
    hub_test_assert(
        hub_voxcpm2_cluster_acceptance_capture_header(
            $headers,
            $bytes,
            $count,
            $tooLarge,
            HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES,
            "X-One-Too-Many: rejected\r\n"
        ) === 0
        && $tooLarge,
        'header callback must abort when the cumulative count exceeds its strict cap'
    );

    $headers = [];
    $bytes = 0;
    $count = 0;
    $tooLarge = false;
    $oversized = 'X-Oversized: ' . str_repeat('x', HUB_VOXCPM2_CLUSTER_HEADER_MAX_BYTES) . "\r\n";
    hub_test_assert(
        hub_voxcpm2_cluster_acceptance_capture_header(
            $headers,
            $bytes,
            $count,
            $tooLarge,
            HUB_VOXCPM2_CLUSTER_JSON_MAX_BYTES,
            $oversized
        ) === 0
        && $tooLarge
        && $headers === [],
        'header callback must abort before retaining a response that exceeds the cumulative byte cap'
    );
});

hub_test('VoxCPM2 Cluster acceptance aborts partial cURL option setup offline', function (): void {
    if (!function_exists('curl_init')) {
        hub_test_skip('partial cURL setup test requires the PHP cURL extension');
    }
    $handle = curl_init('https://127.0.0.1/cluster_api.php?mode=voice_generate');
    if ($handle === false) {
        throw new RuntimeException('Cannot create offline cURL setup fixture.');
    }
    $sets = 0;
    $closes = 0;
    $code = null;
    ob_start();
    try {
        hub_voxcpm2_cluster_acceptance_apply_curl_options(
            $handle,
            [CURLOPT_NOSIGNAL => true, CURLOPT_FOLLOWLOCATION => false],
            static function ($curl, array $options) use (&$sets): bool {
                $sets++;
                curl_setopt($curl, CURLOPT_NOSIGNAL, $options[CURLOPT_NOSIGNAL]);
                return false;
            },
            static function ($curl) use (&$closes): void {
                $closes++;
                curl_close($curl);
            }
        );
    } catch (HubVoxCpm2ClusterAcceptanceFailure $error) {
        $code = $error->stableCode();
    }
    $output = (string)ob_get_clean();
    if ($closes === 0) {
        curl_close($handle);
    }

    $source = (string)file_get_contents(HUB_ROOT . '/scripts/voxcpm2_cluster_acceptance.php');
    $setupAt = strpos($source, 'hub_voxcpm2_cluster_acceptance_apply_curl_options($handle, $options)');
    $executeAt = strpos($source, 'curl_exec($handle)');
    hub_test_assert(
        $sets === 1
        && $closes === 1
        && $code === 'request_failed'
        && $output === ''
        && is_int($setupAt)
        && is_int($executeAt)
        && $setupAt < $executeAt,
        'partial cURL option failure must close once and throw safely before execution or output'
    );
});

hub_test('VoxCPM2 Cluster acceptance launches its probe with a scrubbed environment offline', function (): void {
    if (!function_exists('proc_open')) {
        hub_test_skip('probe environment test requires proc_open');
    }
    $reflection = new ReflectionFunction('hub_voxcpm2_cluster_acceptance_ffprobe');
    hub_test_assert(
        $reflection->getNumberOfParameters() >= 2,
        'ffprobe helper must expose an offline process-factory seam'
    );

    $previous = getenv('AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT');
    putenv('AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT=must-not-reach-probe');
    $capturedEnvironment = null;
    try {
        $valid = hub_voxcpm2_cluster_acceptance_ffprobe(
            '/offline/not-real.wav',
            static function (
                array $command,
                array $descriptors,
                array &$pipes,
                ?string $cwd,
                array $environment,
                array $options,
            ) use (&$capturedEnvironment) {
                $capturedEnvironment = $environment;
                $child = <<<'PHP'
$environment = getenv();
$unsafe = array_filter(
    is_array($environment) ? array_keys($environment) : [],
    static fn (string $name): bool => str_starts_with($name, 'AIHUB_')
);
if ($unsafe !== [] || getenv('LANG') !== 'C') {
    exit(9);
}
echo '{"streams":[{"codec_type":"audio"}],"format":{"duration":"1.25"}}';
PHP;

                return proc_open([PHP_BINARY, '-r', $child], $descriptors, $pipes, $cwd, $environment, $options);
            }
        );
    } finally {
        if ($previous === false) {
            putenv('AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT');
        } else {
            putenv('AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT=' . $previous);
        }
    }

    hub_test_assert(
        $valid
        && $capturedEnvironment === [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'LANG' => 'C',
        ],
        'probe child must receive only the fixed executable path and locale'
    );
});

hub_test('VoxCPM2 Cluster acceptance validates material and exact same-origin Router links offline', function (): void {
    $wav = hub_test_voxcpm2_cluster_acceptance_wav();
    try {
        $config = hub_voxcpm2_cluster_acceptance_config(hub_test_voxcpm2_cluster_acceptance_env($wav));
        hub_test_assert(
            ($config['api_url'] ?? '') === 'https://router.example/3waAIHub/cluster_api.php',
            'Cluster base URL must resolve to its sole API path'
        );
        $private = hub_voxcpm2_cluster_acceptance_config(
            hub_test_voxcpm2_cluster_acceptance_env($wav, 'http://192.168.1.106/3waAIHub/cluster_api.php')
        );
        hub_test_assert(
            ($private['api_url'] ?? '') === 'http://192.168.1.106/3waAIHub/cluster_api.php',
            'literal private LAN HTTP must follow the existing Cluster policy'
        );

        $taskId = 'route_' . str_repeat('c', 34);
        $status = hub_voxcpm2_cluster_acceptance_followup_url(
            $config,
            'cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
            'cluster_task_status',
            $taskId
        );
        hub_test_assert(
            $status === 'https://router.example/3waAIHub/cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
            'relative Router link must canonicalize to the validated same origin'
        );
        foreach ([
            'https://child.example/cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
            'https://user@router.example/3waAIHub/cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
            'https://router.example/other/cluster_api.php?mode=cluster_task_status&task_id=' . $taskId,
            'cluster_api.php?mode=cluster_task_status&task_id=' . $taskId . '&extra=1',
            'cluster_api.php?mode=cluster_task_result&task_id=' . $taskId,
            'cluster_api.php?mode=cluster_task_status&task_id=' . $taskId . '#private',
        ] as $link) {
            hub_test_assert(
                hub_test_throws(static fn (): string => hub_voxcpm2_cluster_acceptance_followup_url(
                    $config,
                    $link,
                    'cluster_task_status',
                    $taskId
                )),
                'unsafe or non-exact Router follow-up must reject'
            );
        }

        foreach ([
            'http://router.example/3waAIHub',
            'http://fc.example/3waAIHub',
            'http://169.254.169.254/3waAIHub',
            'http://localhost/3waAIHub',
            'https://user:pass@router.example/3waAIHub',
            'https://router.example/3waAIHub?child=1',
            'https://router.example/3waAIHub#fragment',
            'ftp://router.example/3waAIHub',
        ] as $baseUrl) {
            hub_test_assert(
                hub_test_throws(static fn (): array => hub_voxcpm2_cluster_acceptance_config(
                    hub_test_voxcpm2_cluster_acceptance_env($wav, $baseUrl)
                )),
                'invalid Cluster base URL must reject before HTTP'
            );
        }
        $invalidProfile = hub_test_voxcpm2_cluster_acceptance_profile(str_repeat('a', 64));
        $invalidProfile['reference_audio_sha256'] = 'not-a-sha256';
        hub_test_assert(
            !hub_voxcpm2_cluster_acceptance_profile_usable($invalidProfile, $config),
            'confirmed profile status must include a bounded lowercase reference SHA-256'
        );
    } finally {
        @unlink($wav);
    }
});

hub_test('VoxCPM2 Cluster acceptance binds profile and result hashes to requested material', function (): void {
    $wav = hub_test_voxcpm2_cluster_acceptance_wav();
    $metadataPath = tempnam(sys_get_temp_dir(), 'voxcpm2-cluster-metadata-');
    if ($metadataPath === false) {
        @unlink($wav);
        throw new RuntimeException('Cannot create Cluster metadata fixture.');
    }
    try {
        $config = hub_voxcpm2_cluster_acceptance_config(hub_test_voxcpm2_cluster_acceptance_env($wav));
        $referenceSha256 = hash_file('sha256', $wav);
        $promptSha256 = hash('sha256', 'Confirmed private prompt text.');
        $normalizedTarget = 'Generate this private target text.';
        hub_test_assert(
            ($config['reference_audio_sha256'] ?? null) === $referenceSha256
            && ($config['prompt_text_sha256'] ?? null) === $promptSha256
            && ($config['normalized_input'] ?? null) === $normalizedTarget
            && ($config['normalized_input_sha256'] ?? null) === hash('sha256', $normalizedTarget),
            'acceptance config must derive production material hashes without exposing them'
        );

        $profile = hub_test_voxcpm2_cluster_acceptance_profile((string)$referenceSha256);
        $staleProfile = hub_test_voxcpm2_cluster_acceptance_profile(str_repeat('b', 64));
        hub_test_assert(
            hub_voxcpm2_cluster_acceptance_profile_usable($profile, $config)
            && !hub_voxcpm2_cluster_acceptance_profile_usable($staleProfile, $config),
            'profile_status must match the exact uploaded reference WAV hash'
        );

        $profileTaskId = 'route_' . str_repeat('d', 34);
        $prepared = [
            'ok' => true,
            'task_id' => $profileTaskId,
            'result' => [
                'kind' => 'voice_profile_prepare',
                'transcription_status' => 'ready',
                'transcript_confirmed' => true,
                'text_chars' => 30,
                'prompt_text_sha256' => $promptSha256,
            ],
        ];
        $stalePrepared = $prepared;
        $stalePrepared['result']['prompt_text_sha256'] = str_repeat('c', 64);
        hub_test_assert(
            hub_voxcpm2_cluster_acceptance_profile_result_valid($prepared, $profileTaskId, $config)
            && !hub_voxcpm2_cluster_acceptance_profile_result_valid($stalePrepared, $profileTaskId, $config),
            'profile prepare result must match the exact confirmed prompt hash'
        );

        file_put_contents(
            $metadataPath,
            json_encode(
                hub_test_voxcpm2_cluster_runner_metadata((string)$referenceSha256, $promptSha256, $normalizedTarget),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            LOCK_EX
        );
        hub_test_assert(
            hub_voxcpm2_cluster_acceptance_metadata_valid($metadataPath, $config),
            'actual production VoxCPM2 metadata must match all requested material'
        );
        $legacyMetadata = hub_test_voxcpm2_cluster_runner_metadata(
            (string)$referenceSha256,
            $promptSha256,
            $normalizedTarget
        );
        $legacyMetadata['model'] = ['model' => '/models/voxcpm2/model'] + $legacyMetadata['model'];
        $legacyVoice = $legacyMetadata['voice_context'];
        unset($legacyVoice['sha256']);
        $legacyVoice['container_path'] = '/data/voice_profiles/reference.wav';
        $legacyMetadata['voice_context'] = $legacyVoice + [
            'sha256' => hash('sha256', hub_test_voxcpm2_cluster_runner_canonical_json($legacyVoice)),
        ];
        file_put_contents(
            $metadataPath,
            json_encode($legacyMetadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        hub_test_assert(
            !hub_voxcpm2_cluster_acceptance_metadata_valid($metadataPath, $config),
            'live acceptance must reject all legacy path-bearing metadata even when its legacy hash is valid'
        );
        file_put_contents(
            $metadataPath,
            json_encode(
                hub_test_voxcpm2_cluster_runner_metadata((string)$referenceSha256, $promptSha256, 'stale target material'),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            LOCK_EX
        );
        hub_test_assert(
            !hub_voxcpm2_cluster_acceptance_metadata_valid($metadataPath, $config),
            'internally valid metadata for stale target material must reject'
        );
        file_put_contents(
            $metadataPath,
            json_encode(
                hub_test_voxcpm2_cluster_runner_metadata((string)$referenceSha256, str_repeat('e', 64), $normalizedTarget),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            LOCK_EX
        );
        hub_test_assert(
            !hub_voxcpm2_cluster_acceptance_metadata_valid($metadataPath, $config),
            'internally valid metadata for another confirmed prompt must reject'
        );
    } finally {
        @unlink($wav);
        @unlink($metadataPath);
    }
});

hub_test('VoxCPM2 Cluster acceptance completes its full offline flow and removes all material', function (): void {
    $wav = hub_test_voxcpm2_cluster_acceptance_wav();
    $before = glob(sys_get_temp_dir() . '/3waaihub_voxcpm2_cluster_*') ?: [];
    $requests = [];
    $probes = [];
    try {
        $config = hub_voxcpm2_cluster_acceptance_config(hub_test_voxcpm2_cluster_acceptance_env($wav));
        $result = hub_voxcpm2_cluster_acceptance_execute(
            $config,
            hub_test_voxcpm2_cluster_acceptance_transport($requests),
            static function (string $path) use (&$probes): bool {
                $probes[] = $path;
                $header = (string)file_get_contents($path, false, null, 0, 12);
                return substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WAVE';
            },
            static function (): void {
                throw new RuntimeException('Immediate terminal fixtures must not sleep.');
            }
        );
    } finally {
        @unlink($wav);
    }
    $after = glob(sys_get_temp_dir() . '/3waaihub_voxcpm2_cluster_*') ?: [];
    $modes = array_map(static function (array $request): string {
        parse_str((string)(parse_url((string)$request['url'], PHP_URL_QUERY) ?? ''), $query);
        return (string)($query['mode'] ?? '');
    }, $requests);

    hub_test_assert($result === [
        'profile_prepared' => true,
        'ultimate_clone' => true,
        'generic_exploration' => true,
        'audio_valid' => true,
        'gpu' => true,
        'artifacts_acknowledged' => true,
    ], 'offline Cluster acceptance result must contain only the safe success facts');
    hub_test_assert(count($requests) === 19, 'generic acceptance must use the expected bounded request count');
    hub_test_assert(count($probes) === 3, 'generic acceptance must ffprobe both WAV candidates');
    hub_test_assert($before === $after, 'generic acceptance must remove its temporary files');
    hub_test_assert(count(array_filter($requests, static fn (array $request): bool => ($request['operation'] ?? null) === 'profile_delete')) === 1, 'generic acceptance must retain one profile cleanup');
    hub_test_assert(
        $modes === [
            'voice_generate',
            'cluster_task_status',
            'cluster_task_result',
            'voice_generate',
            'voice_generate',
            'cluster_task_status',
            'cluster_task_result',
            'cluster_artifact',
            'cluster_artifact',
            'cluster_task_artifacts_ack',
            'cluster_task_artifacts_ack',
            'voice_generate',
            'cluster_task_status',
            'cluster_task_result',
            'cluster_artifact',
            'cluster_task_artifacts_ack',
            'cluster_artifact',
            'cluster_task_artifacts_ack',
            'voice_generate',
        ],
        'offline flow must validate and ACK two generic candidates after the profile flow'
    );
});

hub_test('VoxCPM2 Cluster acceptance rejects malformed or leaky generic candidates', function (): void {
    $wav = hub_test_voxcpm2_cluster_acceptance_wav();
    try {
        $config = hub_voxcpm2_cluster_acceptance_config(hub_test_voxcpm2_cluster_acceptance_env($wav));
        $taskId = 'route_' . str_repeat('c', 34);
        $candidate = static fn (int $number, int $artifactId): array => [
            'candidate_id' => 'candidate-' . str_pad((string)$number, 2, '0', STR_PAD_LEFT),
            'audio_artifact_id' => $artifactId,
            'seed' => 400 + $number,
            'voice_design_revision' => 1,
            'style_status' => 'unverified',
            'audio_url' => 'cluster_api.php?mode=cluster_artifact&task_id=' . $taskId . '&artifact_id=' . $artifactId,
        ];
        $valid = [
            'ok' => true,
            'task_id' => $taskId,
            'result' => ['candidates' => [$candidate(1, 19), $candidate(2, 20)]],
        ];
        $malformed = $valid;
        unset($malformed['result']['candidates'][1]['style_status']);
        $leaky = $valid;
        $leaky['result']['candidates'][0]['voice_profile_task_id'] = 'route_' . str_repeat('a', 34);

        hub_test_assert(
            hub_voxcpm2_cluster_acceptance_generic_candidates($config, $valid, $taskId) === [
                ['id' => '19', 'url' => 'https://router.example/3waAIHub/cluster_api.php?mode=cluster_artifact&task_id=' . $taskId . '&artifact_id=19'],
                ['id' => '20', 'url' => 'https://router.example/3waAIHub/cluster_api.php?mode=cluster_artifact&task_id=' . $taskId . '&artifact_id=20'],
            ]
            && hub_test_throws(static fn (): array => hub_voxcpm2_cluster_acceptance_generic_candidates($config, $malformed, $taskId))
            && hub_test_throws(static fn (): array => hub_voxcpm2_cluster_acceptance_generic_candidates($config, $leaky, $taskId)),
            'generic acceptance must require exactly two safe candidate descriptors and same-task artifact URLs'
        );
    } finally {
        @unlink($wav);
    }
});

hub_test('VoxCPM2 Cluster acceptance cleanup preserves the primary failure', function (): void {
    $wav = hub_test_voxcpm2_cluster_acceptance_wav();
    $before = glob(sys_get_temp_dir() . '/3waaihub_voxcpm2_cluster_*') ?: [];
    $requests = [];
    $code = null;
    try {
        $config = hub_voxcpm2_cluster_acceptance_config(hub_test_voxcpm2_cluster_acceptance_env($wav));
        hub_voxcpm2_cluster_acceptance_execute(
            $config,
            hub_test_voxcpm2_cluster_acceptance_transport($requests, false, false),
            static fn (string $path): bool => is_file($path),
            static function (): void {
            }
        );
    } catch (HubVoxCpm2ClusterAcceptanceFailure $error) {
        $code = $error->stableCode();
    } finally {
        @unlink($wav);
    }
    $after = glob(sys_get_temp_dir() . '/3waaihub_voxcpm2_cluster_*') ?: [];
    $deleteAttempts = array_filter(
        $requests,
        static fn (array $request): bool => ($request['operation'] ?? null) === 'profile_delete'
    );

    hub_test_assert(
        $code === 'artifact_invalid'
        && count($deleteAttempts) === 1
        && $before === $after,
        'artifact failure must remain primary while profile deletion and recursive temp cleanup are still attempted'
    );
});

hub_test('VoxCPM2 Cluster acceptance signal cleanup preserves the primary failure offline', function (): void {
    $restores = 0;
    $throwingRestore = static function (array $state) use (&$restores): void {
        $restores++;
        throw new RuntimeException('synthetic restore failure');
    };

    $primary = hub_voxcpm2_cluster_acceptance_restore_signal_handlers_safely(
        ['handlers' => []],
        'interrupted',
        $throwingRestore
    );
    $cleanupOnly = hub_voxcpm2_cluster_acceptance_restore_signal_handlers_safely(
        ['handlers' => []],
        null,
        $throwingRestore
    );

    hub_test_assert(
        $restores === 2
        && $primary === 'interrupted'
        && $cleanupOnly === 'internal_error',
        'signal restoration failure must preserve an existing stable failure and become internal only without a primary'
    );
});

hub_test('VoxCPM2 Cluster acceptance refuses the ordinary test runner and exposes exact safe output', function (): void {
    $calls = 0;
    ob_start();
    $exit = hub_voxcpm2_cluster_acceptance_main(
        ['voxcpm2_cluster_acceptance.php'],
        static function () use (&$calls): array {
            $calls++;
            return [];
        }
    );
    $output = (string)ob_get_clean();

    hub_test_assert(
        $exit === 1
        && $calls === 0
        && $output === "{\"ok\":false,\"error\":\"test_environment_refused\",\"message\":\"Acceptance cannot run in a test environment.\"}\n",
        'ordinary test execution must refuse before any network or model path'
    );
    hub_test_assert(
        hub_voxcpm2_cluster_acceptance_success_line()
            === "{\"ok\":true,\"profile_prepared\":true,\"ultimate_clone\":true,\"generic_exploration\":true,\"audio_valid\":true,\"gpu\":true,\"artifacts_acknowledged\":true}\n",
        'successful CLI output must be exactly one fixed safe JSON line'
    );
    $failure = hub_voxcpm2_cluster_acceptance_failure_line('request_failed');
    hub_test_assert(
        $failure === "{\"ok\":false,\"error\":\"request_failed\",\"message\":\"Cluster request failed.\"}\n"
        && !str_contains($failure, 'voxcpm2-cluster-unit-token-secret')
        && !str_contains($failure, 'router.example'),
        'failure output must be a bounded stable code and message without values'
    );
});
