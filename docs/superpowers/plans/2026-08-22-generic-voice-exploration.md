# Generic Voice Exploration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add private, generic VoxCPM2 voice exploration to 3waAIHub and its VoxCPM2 Demo, returning 1–3 downloadable WAV candidates with reproducibility metadata while leaving character Voice Profiles untouched.

**Architecture:** `generic_synthesize` is an additive `voice_generate` operation. It stores an owner-private generic recipe, dispatches VoxCPM2 only in `design` mode, and reuses the existing multi-WAV task/artifact plumbing. The Demo becomes an exploration client: it imports every candidate into its current temporary-output store and displays/selects them; it never creates or mutates a Hub Voice Profile or the character clone application.

**Tech Stack:** PHP 8, SQLite/PDO Hub task runtime, existing VoxCPM2 resident service, Cluster Router, MyAI PHP/JavaScript/WaveSurfer, existing PHP assertion tests.

---

## File map

- Modify: `app/voice_presets.php` — strict generic request validation, private batch recipe, candidate result projection, operation dispatch.
- Modify: `app/pack_job_runner.php` and `app/task_queue.php` — reuse the current multi-candidate runner/artifact flow for generic batches.
- Modify: `app/gateway.php` and `app/cluster_router.php` — expose generic candidate summaries over Cluster and relay stable generic request errors.
- Modify: `app/public_api_docs.php`, `README.md`, `docs/operations/managed-voice-presets.md`, `docs/cluster-router.md`, `docs/operations/voxcpm2-three-mode-smoke.md`, and `packs/tts-voxcpm2/README.md` — document the generic contract for callers, Router operators, and execution-node operators, including reproducible validation commands.
- Modify: `tests/test_tts_voxcpm2.php`, `tests/test_cluster_router.php`, and `scripts/voxcpm2_cluster_acceptance.php` — Hub, Router, and real-node Cluster acceptance coverage.
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/{api.php,index.php,schema.sql,assets/voxcpm2-demo.js,assets/voxcpm2-demo.css,docs/ui-api.html,docs/architecture.html}` — exploration API, temporary candidate persistence, UI, and user-facing documentation.
- Modify: `/var/www/html/myai/myai_voice/tests/{voxcpm2_demo_helpers_test.php,voxcpm2_demo_assets_schema_test.php,voxcpm2_demo_ui_static_test.php}` — Demo regression coverage.
- Do not modify: `/var/www/html/myai/my_charactor_voice_clone/**`.

### Task 1: Lock the Hub generic-operation contract in tests

**Files:**
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Add the failing native contract test**

Add a test after the existing preset synthesis tests that invokes a missing `hub_voice_generic_api_synthesize()` with a route that resolves to VoxCPM2 and asserts the intended task input and response shape:

```php
$accepted = hub_voice_generic_api_synthesize($db, $route, $auth, [
    'text' => '等一下，我再確認一次……',
    'gender' => 'female',
    'age_bucket' => 'young_adult',
    'role_note' => '活潑有節奏的活動主持人，聲音明亮而有感染力。',
    'candidate_count' => 3,
]);
$task = hub_get_task($db, (int)$accepted['task_id']);
hub_test_assert(
    ($task['input']['mode'] ?? null) === 'design'
    && !array_key_exists('voice_profile_id', $task['input'])
    && ($task['input']['generic_voice_batch']['voice_design_revision'] ?? null) === 1
    && ($task['input']['generic_voice_batch']['style_status'] ?? null) === 'unverified'
    && count($task['input']['generic_voice_batch']['candidates'] ?? []) === 3,
    'generic exploration must enqueue private design candidates without a Voice Profile'
);
```

In the same test, assert `voice_prompt`, `control`, `mode`, `model`, `voice_profile_id`, caller-supplied `seed`, an unknown key, an invalid age bucket, and counts 0/4 each throw `InvalidArgumentException` with the documented generic code. Assert the recipe retains the requested gender, age bucket, and role note, while the executable input contains only `text`, `mode`, `seed`, and the internal candidate list.

- [ ] **Step 2: Run the focused Hub test and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: FAIL because `hub_voice_generic_api_synthesize` does not exist.

- [ ] **Step 3: Add the failing generic result/artifact test**

Add assertions for a completed three-candidate task. Its public result must be exactly ordered candidates with these keys:

```php
['candidate_id', 'audio_artifact_id', 'seed', 'voice_design_revision', 'style_status']
```

and its extra artifact definitions must be `candidate-02.wav` / `candidate-03.wav`. Verify malformed candidate IDs, a missing artifact, a revision other than `1`, or a status other than `unverified` is rejected.

- [ ] **Step 4: Run again and verify the new assertions fail for missing generic batch handling**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: FAIL in the new generic-result assertions, not in unrelated audio tests.

### Task 2: Implement the minimal Hub generic batch path

**Files:**
- Modify: `app/voice_presets.php`
- Modify: `app/pack_job_runner.php`
- Modify: `app/task_queue.php`
- Modify: `app/gateway.php`

- [ ] **Step 1: Add strict generic validators and a private recipe snapshot**

In `app/voice_presets.php`, add a `hub_voice_generic_batch_snapshot()` that accepts only this stored shape:

```php
[
    'gender' => 'female',
    'age_bucket' => 'young_adult',
    'role_note' => '...',
    'voice_design_revision' => 1,
    'style_status' => 'unverified',
    'candidates' => [
        ['candidate_id' => 'candidate-01', 'seed' => 123],
    ],
]
```

Validate `gender` against `unspecified|male|female`, `age_bucket` against `child|teen|young_adult|mature|senior`, role note as valid UTF-8 of at most 300 characters, and a list of 1–3 ordinal candidates whose seeds are unsigned 31-bit integers. Use `random_int(0, 2147483647)` for each server-generated seed; do not accept a seed from the caller.

- [ ] **Step 2: Implement `generic_synthesize` using the existing pack job**

Add `hub_voice_generic_api_synthesize(PDO $db, array $route, array $auth, array $payload): array`. Permit exactly `text`, `gender`, `age_bucket`, `role_note`, and `candidate_count`; require non-empty `text` up to 4096 bytes. Enqueue the existing route with:

```php
$input = hub_pack_job_task_input([
    'text' => $text,
    'mode' => 'design',
    'seed' => $candidates[0]['seed'],
], $route);
$taskId = hub_enqueue_owned_pack_job($db, $route, $input, $memberId, $tokenId, hub_get_client_ip());
$input['generic_voice_batch'] = $recipe;
hub_update_task_input($db, $taskId, $input);
```

Do not call `hub_pack_job_task_resolve_voice_context()`, add `voice_context`, or attach any Voice Profile. Do not place gender, age, or role note in `text` or the service request.

Extend `hub_voice_preset_api_dispatch()` to recognize `generic_synthesize`, keep it POST-only, and map only `generic_voice_invalid`, `generic_voice_candidate_count_invalid`, and `generic_voice_forbidden_input` to stable 400 responses.

- [ ] **Step 3: Reuse the existing multi-candidate worker and artifact machinery**

Add generic equivalents of the three existing preset helpers:

```php
hub_voice_generic_candidate_artifact_definitions($taskInput, $primary);
hub_voice_generic_batch_task_result($db, $taskId, $registered);
hub_voice_generic_batch_result_candidates($taskInput, $result, $artifactIds);
```

Keep the first WAV as `generated_audio.wav` and additional WAV names as `candidate-02.wav` and `candidate-03.wav`. In `hub_pack_job_prepare_workspace()`, accept exactly one batch source (`voice_preset_batch` or `generic_voice_batch`) and pass only its `candidates` array to the existing internal `preset_candidates` service field. In `hub_validate_pack_job_artifacts()` and terminal task completion, merge the generic artifact/result helpers beside the existing preset helpers.

In `hub_gateway_cluster_child_result_summary()`, recognize generic batch results before the ordinary one-artifact summary, so Cluster child results expose candidate metadata but never the private role note or model details.

- [ ] **Step 4: Run the Hub suite and verify GREEN**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: `failures=0`; existing preset and profile tests remain green.

- [ ] **Step 5: Commit the Hub runtime change**

```bash
git add app/voice_presets.php app/pack_job_runner.php app/task_queue.php app/gateway.php tests/test_tts_voxcpm2.php
git commit -m "feat: add generic voice exploration"
```

### Task 3: Cover the Cluster Router and public Hub contract

**Files:**
- Modify: `tests/test_cluster_router.php`
- Modify: `app/cluster_router.php`
- Modify: `app/public_api_docs.php`
- Modify: `README.md`
- Modify: `docs/operations/managed-voice-presets.md`
- Modify: `docs/cluster-router.md`
- Modify: `docs/operations/voxcpm2-three-mode-smoke.md`
- Modify: `packs/tts-voxcpm2/README.md`

- [ ] **Step 1: Add a failing Router relay test**

In the existing managed-preset Router fixture section, send this normal `voice_generate` body through `hub_cluster_dispatch()`:

```php
[
    'operation' => 'generic_synthesize',
    'text' => '今天也一起把事情做好吧',
    'gender' => 'male',
    'age_bucket' => 'mature',
    'role_note' => '沉穩可靠的企業旁白。',
    'candidate_count' => 2,
]
```

Assert it is not pinned to a preset station, its async response rewrites to one opaque Router task ID, and the forwarded body is byte-for-byte the supplied semantic request. Add child-result fixtures with two generic candidates and assert the public Router result adds authenticated `audio_url` values while omitting `role_note`, `mode`, model, and profile fields.

- [ ] **Step 2: Run the Router suite and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: the new generic error/result assertion fails because generic errors are not yet mapped or projected.

- [ ] **Step 3: Add only the Router rules required by the test**

Add the three generic request codes to `hub_cluster_voice_generate_relay_errors()`. Do not add `generic_synthesize` to the preset-catalog/pinned-station branch: ordinary eligible `voice_generate` station selection and the existing final async rewrite already supply the correct routing behavior. Extend `hub_cluster_voice_generate_error_table()` through that shared error map.

In `app/public_api_docs.php`, add this separate `generic_voice_exploration` contract alongside `managed_voice_presets`:

```php
[
    'synthesis_operation' => 'generic_synthesize',
    'request_fields' => ['text', 'gender', 'age_bucket', 'role_note', 'candidate_count'],
    'result_candidates' => ['candidate_id', 'audio_url', 'seed', 'voice_design_revision', 'style_status'],
    'strategy' => 'VoxCPM2 design only; no Voice Profile is created or selected.',
    'style_status' => 'unverified until an engine has official independent controls.',
]
```

Append matching concise documentation to `docs/operations/managed-voice-presets.md` and the README capability paragraph. State the exact spoken-text boundary and that a recipe helps provenance but does not guarantee identical output after a runtime revision.

Update `docs/cluster-router.md` with a separate **Generic voice exploration** subsection: it uses ordinary eligible-station selection at submission, becomes task-pinned only after acceptance, returns opaque Router task/artifact links, and does not read or create a managed preset/Profile. Update `packs/tts-voxcpm2/README.md` for node operators: the generic path is internal `design` mode plus server-owned `preset_candidates`; it requires no new service setting, no container path, and no model change. Update `docs/operations/voxcpm2-three-mode-smoke.md` with the exact deployed Router acceptance procedure from Task 7, including the requirement to restart the existing resident service only when its configuration changed, not merely because `generic_synthesize` was added.

- [ ] **Step 4: Run the Router suite and documentation contract assertions**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: `failures=0`, including the existing public-doc contract tests.

- [ ] **Step 5: Commit the Router and documentation change**

```bash
git add app/cluster_router.php app/public_api_docs.php README.md docs/operations/managed-voice-presets.md tests/test_cluster_router.php
git commit -m "docs: publish generic voice exploration contract"
```

### Task 4: Lock Demo behaviour before changing it

**Files:**
- Modify: `/var/www/html/myai/myai_voice/tests/voxcpm2_demo_helpers_test.php`
- Modify: `/var/www/html/myai/myai_voice/tests/voxcpm2_demo_assets_schema_test.php`
- Modify: `/var/www/html/myai/myai_voice/tests/voxcpm2_demo_ui_static_test.php`

- [ ] **Step 1: Replace preset-only helper expectations with generic candidate expectations**

Keep legacy preset tests for historic rows, then add failing generic helpers that accept only:

```php
[
    'candidate_id' => 'candidate-01',
    'audio_artifact_id' => 11,
    'seed' => 123,
    'voice_design_revision' => 1,
    'style_status' => 'unverified',
    'audio_url' => 'cluster_api.php?...',
]
```

Assert a list with three correctly ordered candidates succeeds, invalid role note/age/gender/count inputs fail, and a different artifact URL, MIME, hash, candidate ordinal, revision, or style status fails.

- [ ] **Step 2: Add failing schema and UI assertions**

Require `candidate_count`, `voice_design_revision`, and `style_status` in `schema.sql` and the runtime column guard. Require the public page to show the five named age buckets, a 1–3 candidate selector, the `unverified` preference explanation, individual candidate buttons, and a recipe download action. Assert it no longer contains `vxVoicePreset`, `vxScene`, `loadVoicePresets`, `mode: 'voice_presets'`, `preset_synthesize`, or UI text claiming role notes change the voice.

Also assert the JS create payload contains:

```js
gender: gender.value,
age_bucket: age.value,
role_note: character.value,
candidate_count: Number(candidateCount.value)
```

and not a model, profile, prompt, control, seed, preset, purpose, or scene field.

- [ ] **Step 3: Run the three Demo tests and verify RED**

Run:

```bash
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_helpers_test.php
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_assets_schema_test.php
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_ui_static_test.php
```

Expected: the new generic assertions fail; no character-clone test is run or edited.

### Task 5: Implement the Demo's temporary generic candidate flow

**Files:**
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/api.php`
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/schema.sql`
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/index.php`
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/assets/voxcpm2-demo.js`
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/assets/voxcpm2-demo.css`

- [ ] **Step 1: Add only the required local recipe columns**

Add these nullable/defaulted fields to `schema.sql` and the idempotent runtime column guard:

```sql
`candidate_count` TINYINT UNSIGNED NOT NULL DEFAULT 1,
`voice_design_revision` INT UNSIGNED NULL,
`style_status` VARCHAR(32) NULL,
UNIQUE KEY `uq_voxcpm2_demo_parent_candidate` (`parent_uuid`, `candidate_id`)
```

Retain the existing `candidate_id` and `candidate_seed` columns. Do not add a Voice Profile, consent, or character mapping column.

- [ ] **Step 2: Submit generic requests without caching or preset discovery**

Add strict Demo validators for the semantic gender, age bucket, and 1–3 count. Create `voxcpm2_demo_create_generic_job()` that stores the role note in the existing `character_prompt`, uses a UUID-derived non-reusable cache key because exploration must remain random, submits only:

```php
[
    'operation' => 'generic_synthesize',
    'text' => $text,
    'gender' => $gender,
    'age_bucket' => $ageBucket,
    'role_note' => $role,
    'candidate_count' => $candidateCount,
]
```

Change `mode=create` to call this function. Preserve the old preset functions only for historic-job admin regeneration; the public create path must neither fetch `voice_presets` nor call `preset_synthesize`.

- [ ] **Step 3: Import all completed candidates idempotently**

Add a generic result validator that cross-checks every candidate's artifact ID, expected Router artifact URL, WAV MIME, and SHA-256 against `cluster_artifact_index`. While holding the parent import lock:

1. download `candidate-01` into the parent job;
2. create/find one child job per `candidate-02`/`candidate-03` using `parent_uuid` plus the unique candidate key;
3. download each child WAV through the existing allowlisted URL and store candidate ID, seed, design revision, and style status;
4. acknowledge each imported artifact when the existing ACK URL is supplied.

If an import is interrupted, leave the parent retryable and use the unique parent/candidate key to avoid duplicate child rows. Return candidate data only after all requested WAVs are locally present. Never use a Hub Voice Profile or route inside `/my_charactor_voice_clone/`.

- [ ] **Step 4: Return a selected job's candidate set and recipe**

Extend `mode=status` for a generic parent to return ordered `candidates`, each with safe local `audio_url`, `download_url`, `candidate_id`, `seed`, `voice_design_revision`, and `style_status`. Add `mode=recipe` as a GET-only JSON attachment endpoint for the selected local candidate:

```json
{
  "task_id": "opaque task id",
  "candidate_id": "candidate-02",
  "seed": 123,
  "voice_design_revision": 1,
  "style_status": "unverified",
  "gender": "female",
  "age_bucket": "young_adult",
  "role_note": "...",
  "text": "..."
}
```

It must not expose an internal Hub path, model name, token, profile ID, or clone mode.

- [ ] **Step 5: Replace the public form and result selector**

Remove the preset and scene controls. Keep the avatar as an explicitly visual preview only. Add enabled selectors with values `unspecified|male|female` and `child|teen|young_adult|mature|senior`, plus a 1–3 candidate-count selector. Label all three as exploration preferences, with the current model's `style_status=unverified` caveat.

Use one WaveSurfer instance and a `renderCandidates(candidates)` function built with DOM APIs and `textContent`. Each candidate button selects its WAV, shows its candidate ID/seed/revision/status, updates the existing WAV download link, and updates a recipe-download link. Do not use `innerHTML` or construct audio URLs in JavaScript.

- [ ] **Step 6: Run Demo tests and verify GREEN**

Run:

```bash
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_helpers_test.php
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_assets_schema_test.php
php /var/www/html/myai/myai_voice/tests/voxcpm2_demo_ui_static_test.php
```

Expected: all pass. Then run `php -l` for each modified Demo PHP file.

### Task 6: Finish the Demo documentation and verify isolation

**Files:**
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/docs/ui-api.html`
- Modify: `/var/www/html/myai/myai_voice/VoxCPM2_demo/docs/architecture.html`

- [ ] **Step 1: Document the new public workflow**

Replace preset/scene instructions with: choose optional preferences, generate 1–3 temporary candidates, select/play/download a WAV and its recipe, and use the existing character clone application's independent reference-upload flow only if the user later chooses to make a character. State that no Voice Profile is created by this Demo and preferences are not guaranteed current VoxCPM2 acoustic controls.

- [ ] **Step 2: Repeat focused tests and syntax checks**

Run the three Demo tests from Task 5 plus:

```bash
git -C /park/3waAIHub diff --check
git -C /var/www/html/myai/myai_voice diff --check
git -C /var/www/html/myai/myai_voice diff --name-only -- my_charactor_voice_clone
```

Expected: both diff checks are clean and the last command prints nothing. Preserve pre-existing changes to `inc/voice.php`, `migrate.php`, `tests/migrate_test.php`, `tests/voice_test.php`, `HISTORY.md`, and `README.md`.

- [ ] **Step 3: Commit only the Demo-owned paths**

In `/var/www/html/myai/myai_voice`, stage only the Demo and its three test files, never `git add -A`:

```bash
git add VoxCPM2_demo tests/voxcpm2_demo_helpers_test.php tests/voxcpm2_demo_assets_schema_test.php tests/voxcpm2_demo_ui_static_test.php
git commit -m "feat: explore generic VoxCPM2 voices"
```

If the deployed MyAI checkout intentionally is not to be committed, report the exact changed paths and leave the unrelated dirty paths intact rather than attempting a broad commit.

### Task 7: Add and document real Node / Cluster acceptance

**Files:**
- Modify: `scripts/voxcpm2_cluster_acceptance.php`
- Modify: `docs/cluster-router.md`
- Modify: `docs/operations/voxcpm2-three-mode-smoke.md`
- Modify: `packs/tts-voxcpm2/README.md`

- [ ] **Step 1: Add a failing generic phase to the acceptance-script unit seams**

Extend the existing acceptance test seams in `tests/test_tts_voxcpm2.php` so the real-acceptance helper is expected to submit one additional request after its profile-clone checks:

```php
[
    'operation' => 'generic_synthesize',
    'text' => $config['target_text'],
    'gender' => 'unspecified',
    'age_bucket' => 'young_adult',
    'role_note' => 'Cluster generic acceptance probe',
    'candidate_count' => 2,
]
```

Assert the acceptance parser requires two ordered candidates, validates each WAV and SHA-256 through the returned Router artifact/ACK templates, and rejects a response that leaks `voice_profile_id`, `mode`, `voice_prompt`, or `control`.

- [ ] **Step 2: Run the focused Hub suite and verify RED**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: the acceptance seam fails because the script still validates only the profile/ultimate-clone phase.

- [ ] **Step 3: Implement the generic acceptance phase and success signal**

Extend `scripts/voxcpm2_cluster_acceptance.php` after its existing ultimate-clone artifact validation. Reuse its bounded poll, authenticated artifact download, WAV/ffprobe, SHA-256, ACK, and temporary-directory cleanup helpers. Add `generic_exploration: true` to the final machine-readable success line only after both generic candidate WAVs validate and ACK succeeds. Do not create another profile or make any generic result depend on the temporary clone profile.

- [ ] **Step 4: Publish the operator runbook and test methods**

Document these exact layers, with secrets supplied only through existing environment variables and never copied into shell history:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
python3 -m unittest -v packs/tts-voxcpm2/service/test_app.py packs/tts-voxcpm2/service/test_job.py packs/tts-voxcpm2/service/test_http_routes.py
php scripts/voxcpm2_cluster_acceptance.php
```

In the smoke document, state the environment-variable prerequisites already used by the acceptance script (`AIHUB_VOXCPM2_CLUSTER_BASE_URL`, `AIHUB_VOXCPM2_CLUSTER_TOKEN`, `AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV`, `AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT`, and `AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT`) and the expected success JSON fields. In the Cluster document, keep node locations, paths, ports, and tokens out of customer-facing examples.

- [ ] **Step 5: Run the complete automated Node/Cluster checks**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
python3 -m unittest -v packs/tts-voxcpm2/service/test_app.py packs/tts-voxcpm2/service/test_job.py packs/tts-voxcpm2/service/test_http_routes.py
```

Expected: all tests pass. Run the live acceptance command only on an authorized deployed Router after Task 8's code is deployed; it must report both the historical clone checks and `generic_exploration:true`.

- [ ] **Step 6: Commit the node, Cluster, and acceptance updates**

```bash
git add scripts/voxcpm2_cluster_acceptance.php docs/cluster-router.md docs/operations/voxcpm2-three-mode-smoke.md packs/tts-voxcpm2/README.md tests/test_tts_voxcpm2.php
git commit -m "test: cover generic voice cluster acceptance"
```

### Task 8: Final end-to-end contract check

**Files:**
- No new files.

- [ ] **Step 1: Run the full relevant Hub suite**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster`

Expected: `failures=0`.

- [ ] **Step 2: Run the complete Demo test group**

Run:

```bash
for test in /var/www/html/myai/myai_voice/tests/voxcpm2_demo_*_test.php; do php "$test" || exit 1; done
```

Expected: every VoxCPM2 Demo test passes.

- [ ] **Step 3: Perform a safe live contract probe only after deployment is authorized**

Using the Demo's existing authorized token, submit `generic_synthesize` with `candidate_count=2`, poll its normal Router task URL, and verify two WAV artifacts plus candidate metadata return. Confirm `text` is the only spoken content and inspect no output or response for Voice Profile IDs, paths, model/mode, `voice_prompt`, or `control`. Do not call this probe before the Hub and Demo changes are deployed.
