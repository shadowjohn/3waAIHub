# Managed Voice Presets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add owner-managed, model-independent voice presets that queue one asynchronous batch task and return stable candidate references without exposing voice assets or model controls.

**Architecture:** `app/voice_presets.php` owns preset metadata, profile bindings, semantic request validation, seed derivation, and the private task snapshot. It reuses the existing voice-profile consent/upload flow and VoxCPM2 Pack job runtime; the job emits at most three controlled WAV outputs and the existing artifact layer publishes their safe URLs. Cluster Router records the selected station for each owner/preset so future synthesis remains co-located with the protected profile.

**Tech Stack:** PHP 8, PDO SQLite, existing task queue/artifact runtime, VoxCPM2 Python job runner, existing Cluster Router, assert-based PHP/Python tests.

---

### Task 1: Persist and manage preset metadata

**Files:**
- Create: `app/voice_presets.php`
- Modify: `app/bootstrap.php`
- Modify: `app/db.php`
- Modify: `app/voice_profile_tasks.php`
- Test: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing direct-API tests for a private, owner-scoped preset catalog**

Add a fixture with two API members and confirmed `voice_profiles`. Exercise `voice_preset_upsert`, `voice_preset_anchor_upsert`, `voice_presets`, and `voice_preset_delete`; assert that the list returns only:

```php
['id' => 'azhe', 'label' => '阿哲', 'gender' => 'male',
 'age_bucket' => 'adult', 'purposes' => ['scene_preview'],
 'scenes' => ['nervous', 'calm'], 'preset_revision' => 1]
```

Assert that a second member cannot list, modify, or resolve the first member's preset and that no response JSON contains `reference_audio_path`, `voice_profile_id`, or `VoxCPM2`.

- [ ] **Step 2: Run the new test to verify it fails**

Run: `php tests/test_tts_voxcpm2.php`

Expected: FAIL because `voice_preset_upsert` is unknown.

- [ ] **Step 3: Add the smallest owner-scoped tables and helpers**

In `app/db.php`, create `voice_presets` and `voice_preset_scene_anchors`, both foreign-keyed to `api_members` and the existing `voice_profiles`; add owner/preset and preset/scene unique indexes and include their required columns in the schema completeness check.

In `app/voice_presets.php`, define strict slug/metadata helpers and store only profile IDs internally:

```php
function hub_voice_preset_api_dispatch(PDO $db, array $route, array $auth, array $payload): ?array;
function hub_voice_preset_upsert(PDO $db, array $auth, array $payload): array;
function hub_voice_preset_anchor_upsert(PDO $db, array $auth, array $payload): array;
function hub_voice_preset_list(PDO $db, array $auth): array;
```

Require a completed, active voice-profile task owned by the caller when binding a base or scene anchor. Increment `preset_revision` whenever the base metadata or an anchor changes. Load `voice_presets.php` after `voice_profiles.php`, and delegate its operations from `hub_voice_profile_api_dispatch()` before the legacy profile-operation match.

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `php tests/test_tts_voxcpm2.php`

Expected: PASS, including owner isolation and public metadata checks.

- [ ] **Step 5: Commit the catalog boundary**

```bash
git add app/bootstrap.php app/db.php app/voice_presets.php app/voice_profile_tasks.php tests/test_tts_voxcpm2.php
git commit -m "feat: add managed voice preset catalog"
```

### Task 2: Admit semantic synthesis with locked strategy and seeds

**Files:**
- Modify: `app/voice_presets.php`
- Modify: `app/pack_job_runner.php`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing synthesis-admission tests**

Create an enabled `azhe` preset with a base profile and a confirmed `nervous` anchor. Assert that `preset_synthesize`:

```php
['operation' => 'preset_synthesize', 'voice_preset' => 'azhe',
 'purpose' => 'scene_preview', 'scene' => 'nervous',
 'candidate_count' => 3, 'text' => '等一下，我再確認一次……']
```

returns `hub_task_submit_response()` with one task ID. Assert its private task input has `mode => 'ultimate_clone'`, a sealed `voice_context`, three `candidate-01` through `candidate-03` seeds, and the current revision. Repeat with declared `calm` but no anchor and assert `mode => 'clone'`. Assert unknown scenes, absent presets, counts outside `1..3`, and extra `model`, `mode`, `voice_prompt`, `control`, `voice_profile_id`, or path fields fail with the public stable code and create no task.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php tests/test_tts_voxcpm2.php`

Expected: FAIL because `preset_synthesize` is unknown.

- [ ] **Step 3: Reuse Pack admission and append only a private batch snapshot**

Implement `hub_voice_preset_api_synthesize()` with an exact JSON-key allowlist. Resolve the owner's preset and declared purpose/scene. Set `clone` for a base/fallback asset or `ultimate_clone` for an anchor, then call the existing `hub_pack_job_task_input()`, `hub_pack_job_task_resolve_voice_context()`, and `hub_enqueue_owned_pack_job()` helpers. Persist only this bounded batch state after normal Pack admission:

```php
$input['voice_preset_batch'] = [
    'preset_id' => $preset['preset_id'],
    'preset_revision' => (int)$preset['revision'],
    'candidates' => [
        ['candidate_id' => 'candidate-01', 'seed' => $firstSeed],
        ['candidate_id' => 'candidate-02', 'seed' => $secondSeed],
    ],
];
```

Derive omitted seeds deterministically from preset ID, revision, purpose, scene, text, and candidate index; preserve a supplied first seed. In `hub_pack_job_prepare_workspace()`, copy only this candidate list to the private runner request as `preset_candidates`; never copy the preset ID, revision, path, or profile ID.

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `php tests/test_tts_voxcpm2.php`

Expected: PASS; the spoken request still contains the exact caller text and no control prose.

- [ ] **Step 5: Commit semantic admission**

```bash
git add app/voice_presets.php app/pack_job_runner.php tests/test_tts_voxcpm2.php
git commit -m "feat: queue preset voice synthesis"
```

### Task 3: Produce and publish bounded candidate artifacts

**Files:**
- Modify: `packs/tts-voxcpm2/service/job.py`
- Modify: `app/task_queue.php`
- Modify: `app/gateway.php`
- Modify: `packs/tts-voxcpm2/service/test_job.py`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing runner and artifact tests**

In Python, pass a `preset_candidates` list with seeds `101`, `202`, and `303` to `run_job()` under fake synthesis. Assert it creates `generated_audio.wav`, `candidate-02.wav`, and `candidate-03.wav`, with no prompt/control string in any spoken text path.

In PHP, finalize a preset batch workspace and assert `task_result` contains:

```php
['candidates' => [
  ['candidate_id' => 'candidate-01', 'audio_artifact_id' => 1, 'seed' => 101, 'preset_revision' => 4],
]]
```

and its public result adds `audio_url`; assert arbitrary extra output files remain `output_contract_invalid`.

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `python3 -m unittest packs/tts-voxcpm2/service/test_job.py && php tests/test_tts_voxcpm2.php`

Expected: FAIL because batch candidates are not accepted or published.

- [ ] **Step 3: Extend only the sealed Vox job path**

Add `preset_candidates` to `job.py`'s private request validator. For candidate 1, retain `generated_audio.wav`; for candidates 2–3, execute the same plan/synthesis pipeline in a candidate-specific checkpoint directory and write `candidate-02.wav` / `candidate-03.wav`.

In `hub_validate_pack_job_artifacts()`, append controlled, exact `candidate-0N.wav` audio definitions only when `voice_preset_batch` has a valid sealed list. Do not add wildcard artifact support. In `hub_commit_published_pack_job_success()`, map the registered primary and candidate artifacts to each candidate ID, seed, and revision. Extend `hub_task_result_publicize_value()` so a safe `audio_artifact_id` is converted to the normal authenticated `audio_url` artifact endpoint.

- [ ] **Step 4: Run runner and focused PHP tests to verify they pass**

Run: `python3 -m unittest packs/tts-voxcpm2/service/test_job.py && php tests/test_tts_voxcpm2.php`

Expected: PASS; all candidates are regular validated WAV artifacts and task output exposes only candidate metadata plus authenticated URLs.

- [ ] **Step 5: Commit batch output support**

```bash
git add packs/tts-voxcpm2/service/job.py packs/tts-voxcpm2/service/test_job.py app/task_queue.php app/gateway.php tests/test_tts_voxcpm2.php
git commit -m "feat: publish preset voice candidates"
```

### Task 4: Preserve preset affinity through Cluster Router

**Files:**
- Modify: `app/db.php`
- Modify: `app/cluster_router.php`
- Modify: `tests/test_cluster_router.php`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing Cluster tests**

Use a routed profile preparation and `voice_preset_upsert` request. Assert the Router records the authenticated member, `voice_preset`, selected station, and public revision after a successful child response. Submit `preset_synthesize` with no low-level profile reference and assert it is pinned to that station, forwards only its semantic JSON payload, and rewrites the one returned task ID/URLs using the ordinary async route path. Assert an unknown preset returns `voice_preset_not_found` and never attempts a station selection.

- [ ] **Step 2: Run the Cluster test to verify it fails**

Run: `php tests/test_cluster_router.php`

Expected: FAIL because preset operations are not recognized or pinned.

- [ ] **Step 3: Add the minimal route index and allowlists**

Add a `cluster_voice_preset_routes` table keyed by router member and preset ID. On a successful preset upsert, save the already selected profile-affinity station and safe catalog metadata. On delete, retire its route. Before normal station selection, resolve `preset_synthesize` and `voice_presets` from this index; use the recorded station for synthesis and expose only stored safe catalog values for discovery.

Extend the voice-operation parser, scalar/JSON field allowlists, request-size rules, error relay map, and public contract rewrite for `voice_presets`, `voice_preset_upsert`, `voice_preset_anchor_upsert`, `voice_preset_delete`, and `preset_synthesize`. Preserve the existing opaque profile-task replacement for management calls. Do not copy internal profile IDs, profile paths, seeds beyond requested result data, or model/mode fields into Router payloads or responses.

- [ ] **Step 4: Run Cluster and focused voice tests to verify they pass**

Run: `php tests/test_cluster_router.php && php tests/test_tts_voxcpm2.php`

Expected: PASS; direct and Router calls have the same public candidate/task contract.

- [ ] **Step 5: Commit Cluster affinity**

```bash
git add app/db.php app/cluster_router.php tests/test_cluster_router.php tests/test_tts_voxcpm2.php
git commit -m "feat: route managed voice presets in cluster"
```

### Task 5: Publish the contract and verify the suites

**Files:**
- Modify: `app/public_api_docs.php`
- Modify: `docs/cluster-router.md`
- Create: `docs/operations/managed-voice-presets.md`
- Modify: `tests/test_public_api_docs.php`
- Modify: `README.md`

- [ ] **Step 1: Write failing documentation-contract checks**

Assert public docs show `voice_presets` and `preset_synthesize`, the semantic request shape, candidate persistence fields, anchor fallback policy, and the exact spoken-text boundary. Assert generated public examples/doc strings never include `voice_profile_id`, a filesystem path, `voice_prompt`, `control`, model selection, or a clone-mode choice.

- [ ] **Step 2: Run the documentation test to verify it fails**

Run: `php tests/test_public_api_docs.php`

Expected: FAIL because preset operations are absent.

- [ ] **Step 3: Document only caller-visible behavior and operator lifecycle**

Add caller-facing discovery/synthesis examples in `app/public_api_docs.php`. Document generic owner management, profile consent, revision behavior, candidate replay limitations, fallback-to-base policy, and Cluster affinity in `docs/operations/managed-voice-presets.md` and `docs/cluster-router.md`. Add a concise README capability entry; do not document internal asset locations or engine controls.

- [ ] **Step 4: Run focused and regression verification**

Run:

```bash
python3 -m unittest packs/tts-voxcpm2/service/test_job.py
php tests/test_tts_voxcpm2.php
php tests/test_cluster_router.php
php tests/test_public_api_docs.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: every command exits `0`; the final two lines report `failures=0`.

- [ ] **Step 5: Commit documentation and verification**

```bash
git add README.md app/public_api_docs.php docs/cluster-router.md docs/operations/managed-voice-presets.md tests/test_public_api_docs.php
git commit -m "docs: describe managed voice presets"
```

## Self-review

- Direct management, discovery, semantic synthesis, automatic anchor fallback, seed/revision candidate data, protected text, batch artifacts, Cluster affinity, docs, and regression checks each have an implementing task.
- The plan uses only existing PHP, SQLite, task, artifact, profile, and Cluster patterns; it adds no dependency or generic wildcard output system.
- Field names are consistent: `voice_preset`, `preset_revision`, `candidate_id`, `audio_artifact_id`, `audio_url`, and `preset_candidates` are fixed across runner, task result, docs, and tests.
