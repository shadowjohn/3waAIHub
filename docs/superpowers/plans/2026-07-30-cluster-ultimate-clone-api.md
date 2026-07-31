# Cluster Ultimate Clone API Implementation Plan

**Status:** implemented; real Cluster smoke passed

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose reusable task-addressed Voice Profiles and real VoxCPM2 `ultimate_clone` inference through the native and Cluster `voice_generate` API.

**Architecture:** A `voice_profile_prepare` task owns the child-local managed Voice Profile, while the existing opaque Cluster route ID is its public handle. Profile operations and clone synthesis resolve that task, pin the request to its original child, and translate the handle only inside the trusted Router-to-child request. The VoxCPM2 task snapshots only WAV and transcript hashes; the worker reloads and verifies the private Profile immediately before GPU execution.

**Tech Stack:** PHP 8.2+, SQLite, existing task queue and Cluster Router, Docker, Python 3, VoxCPM2/PyTorch CUDA, PHP test harness, Python `unittest`, `ffprobe`.

---

## File Structure

- Create: `app/voice_profile_tasks.php` - native Profile task operations, safe responses, and worker execution.
- Create: `scripts/voxcpm2_cluster_acceptance.php` - explicit real Cluster inference smoke.
- Create: `tests/suites/voice-cluster.php` - focused regression suite for this feature.
- Modify: `app/bootstrap.php` - load the Profile task module.
- Modify: `app/db.php` - link an active Voice Profile to its preparation task.
- Modify: `app/task_queue.php` - register the task type and retain active Profile task metadata.
- Modify: `app/voice_profiles.php` - stage uploads without synchronous ASR and preserve native-only SHA cache behavior.
- Modify: `scripts/task_worker.php` - execute the Profile preparation task.
- Modify: `app/gateway.php` - dispatch Profile operations and resolve task handles before synthesis admission.
- Modify: `app/pack_registry.php` - normalize three-mode trusted Voice Context contracts and report installed async modes.
- Modify: `app/pack_job_runner.php` - revalidate transcript hashes and inject plaintext only into the ephemeral workspace.
- Modify: `app/cluster_router.php` - pin task-addressed Profile requests and sanitize Profile task results.
- Modify: `app/public_api_docs.php` - derive installed async modes and document Profile operations.
- Modify: `packs/tts-voxcpm2/pack.json` - declare the `ultimate_clone` async contract.
- Modify: `packs/tts-voxcpm2/service/job.py` - perform real ultimate clone with the verified managed WAV and transcript.
- Modify: `packs/tts-voxcpm2/service/test_job.py` - offline runner coverage.
- Modify: `packs/tts-voxcpm2/README.md` and `README.md` - document the shipped native and Cluster flow.
- Modify: `tests/test_job_first_schema.php`, `tests/test_retention.php`, `tests/test_tts_voxcpm2.php`, `tests/test_cluster_router.php`, and `tests/test_public_api_docs.php` - focused contract and security coverage.

No MyAI file, new dependency, Cluster Profile mapping table, cross-station file copy, synchronous `tts` lifecycle, or admin UI change is included.

### Task 1: Add the Profile task identity and retention boundary

**Files:**
- Create: `tests/suites/voice-cluster.php`
- Modify: `scripts/run_tests.php`
- Modify: `tests/test_job_first_schema.php`
- Modify: `tests/test_retention.php`
- Modify: `app/db.php:375-400,780-950`
- Modify: `app/task_queue.php:4-12,1493-1535`
- Modify: `app/pack_job_runner.php:4-8`
- Test: `tests/suites/voice-cluster.php`

- [x] **Step 1: Add the focused suite and failing schema/retention assertions**

  Create the exact suite:

  ```php
  <?php
  declare(strict_types=1);

  return [
      __DIR__ . '/../test_job_first_schema.php',
      __DIR__ . '/../test_retention.php',
      __DIR__ . '/../test_tts_voxcpm2.php',
      __DIR__ . '/../test_cluster_router.php',
      __DIR__ . '/../test_public_api_docs.php',
  ];
  ```

  Apply the repository's executable PHP policy:

  ```bash
  chmod 755 tests/suites/voice-cluster.php
  ```

  Add `voice-cluster` to the existing suite-name allowlist in
  `scripts/run_tests.php`; keep the current manifest loading and path checks.

  In `test_job_first_schema.php`, require `voice_profiles.source_task_id` and
  the `idx_voice_profiles_source_task` index. In `test_retention.php`, create
  an old terminal `voice_profile_prepare` task, link one active Profile to it,
  and assert metadata purge preserves it. Soft-delete the Profile and assert
  the next prune removes the task.

- [x] **Step 2: Run the focused suite and verify the new checks fail**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because `source_task_id`, its index, and
  `voice_profile_prepare` do not exist.

- [x] **Step 3: Add the minimum schema and task-type changes**

  Add `source_task_id INTEGER NULL` to the `voice_profiles` creation SQL and
  migration, then add:

  ```php
  $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_source_task ON voice_profiles(source_task_id)');
  ```

  Append `voice_profile_prepare` to both `hub_allowed_task_types()` and
  `hub_pack_job_worker_task_types()`.

  Add this dependency query to
  `hub_retention_task_metadata_dependencies_clear()`:

  ```php
  "SELECT 1 FROM voice_profiles
   WHERE source_task_id = :task_id
     AND deleted_at IS NULL
     AND (expires_at IS NULL OR expires_at > :now)"
  ```

  The existing parameter builder already supplies `:now` when present.

- [x] **Step 4: Run the focused schema and retention checks**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: the new schema and retention tests pass; later feature tests remain
  unchanged.

- [x] **Step 5: Commit the persistence slice**

  ```bash
  git add app/db.php app/task_queue.php app/pack_job_runner.php tests/suites/voice-cluster.php tests/test_job_first_schema.php tests/test_retention.php
  git commit -m "feat: retain voice profile task handles"
  ```

### Task 2: Implement native Voice Profile task operations

**Files:**
- Create: `app/voice_profile_tasks.php`
- Modify: `app/bootstrap.php`
- Modify: `app/voice_profiles.php:59-79,396-508,595-655`
- Modify: `app/gateway.php:4-90,1297-1435`
- Modify: `scripts/task_worker.php:20-75`
- Modify: `tests/test_tts_voxcpm2.php`
- Test: `tests/test_tts_voxcpm2.php`

- [x] **Step 1: Add failing native operation tests**

  Add tests that submit a valid isolated WAV with:

  ```php
  [
      'operation' => 'profile_prepare',
      'profile_name' => 'MyAI fixture',
      'consent_type' => 'self_recorded',
      'prompt_text' => '這是一段已確認的參考語音。',
      'transcript_confirmed' => '1',
  ]
  ```

  Assert:

  - the response is a standard async task response;
  - task input is exactly `['voice_profile_id' => <int>]`;
  - task input/result/log/audit/callback contain neither transcript nor path;
  - the linked Profile contains the managed WAV and confirmed transcript;
  - `profile_status` returns safe metadata but no confirmed transcript;
  - an unconfirmed ASR draft is visible only through owner-checked
    `profile_status`;
  - `profile_confirm` confirms it transactionally;
  - `profile_delete` deletes the WAV and is idempotent;
  - a foreign member gets `voice_profile_forbidden`;
  - a paired node Token does not reuse a SHA cache entry from an earlier
    preparation request, while a native member does.

- [x] **Step 2: Run the focused suite and verify operation tests fail**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because `voice_generate` always enters Pack synthesis and no
  Profile task dispatcher exists.

- [x] **Step 3: Stage managed uploads without duplicating the current validator**

  Extend `hub_create_uploaded_voice_profile()` with a final options array:

  ```php
  function hub_create_uploaded_voice_profile(
      PDO $db,
      int $ownerMemberId,
      array $upload,
      array $input,
      ?callable $moveFile = null,
      ?callable $transcribe = null,
      array $options = []
  ): array
  ```

  Support only these internal options:

  ```php
  $deferTranscription = ($options['defer_transcription'] ?? false) === true;
  $allowCache = ($options['allow_cache'] ?? true) === true;
  ```

  Keep the current validation, staging filename, `BEGIN IMMEDIATE`, atomic
  rename, audit, and cleanup behavior. Skip owner/SHA lookup when
  `$allowCache` is false. Pass bounded `prompt_text` and `language` into
  `hub_create_voice_profile()`. When deferred, return the managed Profile
  without calling `hub_run_voice_profile_transcription()`.

  The caller sets:

  ```php
  $allowCache = !hub_cluster_node_token_is_current($db, $tokenId);
  ```

  This preserves native cache reuse and prevents station-Token cache sharing
  across Router customers.

- [x] **Step 4: Add the Profile task dispatcher and worker**

  Load `app/voice_profile_tasks.php` from `bootstrap.php`. Implement these
  public functions with exact operation names:

  ```php
  function hub_voice_profile_api_dispatch(PDO $db, array $route, array $authContext): ?array;
  function hub_voice_profile_task_for_member(PDO $db, int $taskId, int $memberId): ?array;
  function hub_voice_profile_task_status_payload(PDO $db, array $task, array $profile, bool $includeDraft): array;
  function hub_run_voice_profile_prepare_task(PDO $db, array $task): void;
  ```

  Apply the repository's executable PHP policy:

  ```bash
  chmod 755 app/voice_profile_tasks.php
  ```

  `hub_voice_profile_api_dispatch()` returns `null` for missing or
  `synthesize` operation. For `profile_prepare`, require POST multipart,
  validate the one `reference_wav`, call the deferred upload helper, enqueue:

  ```php
  $taskId = hub_enqueue_task(
      $db,
      'voice_profile_prepare',
      'default',
      0,
      ['voice_profile_id' => (int)$profile['id']],
      null,
      hub_get_client_ip(),
      [
          'owner_member_id' => $memberId,
          'owner_token_id' => $tokenId,
          'requested_mode' => 'voice_generate',
          'callback_target_id' => $callbackTargetId,
      ]
  );
  ```

  Set `voice_profiles.source_task_id` to this task ID. If supplied text is
  marked confirmed, call `hub_confirm_voice_profile_prompt()` before
  publishing the task.

  For status/confirm/delete, resolve only a `voice_profile_prepare` task owned
  by the authenticated member, then its linked Profile. The safe payload is:

  ```php
  [
      'ok' => true,
      'task_status' => (string)$task['status'],
      'profile_status' => $deleted ? 'deleted' : ($expired ? 'expired' : 'active'),
      'transcription_status' => (string)$profile['transcription_status'],
      'transcript_confirmed' => !empty($profile['prompt_text_confirmed_at']),
      'prompt_text_confirmed_at' => $profile['prompt_text_confirmed_at'],
      'profile_name' => (string)$profile['name'],
      'language' => $profile['language'],
      'consent_type' => (string)$profile['consent_type'],
      'reference_audio_sha256' => (string)$profile['reference_audio_sha256'],
      'created_at' => (string)$profile['created_at'],
      'updated_at' => (string)$profile['updated_at'],
  ]
  ```

  Add `prompt_text` only while it is non-empty and unconfirmed. Never add the
  local task ID, Profile ID, or path. The caller already owns the native task
  handle or opaque Cluster route handle used for this request.

  The worker reloads the linked Profile. If it has no text, run the existing
  transcription path. Finish with this bounded result:

  ```php
  [
      'kind' => 'voice_profile_prepare',
      'transcription_status' => (string)$profile['transcription_status'],
      'transcript_confirmed' => !empty($profile['prompt_text_confirmed_at']),
      'text_chars' => mb_strlen((string)($profile['prompt_text'] ?? ''), 'UTF-8'),
      'prompt_text_sha256' => hash('sha256', (string)($profile['prompt_text'] ?? '')),
  ]
  ```

  Add the `voice_profile_prepare` branch to `hub_run_task()`.

  In the authenticated async branch of `hub_gateway_dispatch()`, call the
  Profile dispatcher before normal Pack submission. Return its response when
  non-null. For `operation=synthesize`, remove only that exact operation before
  Pack schema normalization.

- [x] **Step 5: Run and commit the native API slice**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  git diff --check
  ```

  Expected: native Profile preparation, confirmation, status, deletion,
  privacy, cache, and worker tests pass.

  Commit:

  ```bash
  git add app/bootstrap.php app/voice_profiles.php app/voice_profile_tasks.php app/gateway.php scripts/task_worker.php tests/test_tts_voxcpm2.php
  git commit -m "feat: add voice profile task API"
  ```

### Task 3: Complete the asynchronous VoxCPM2 ultimate-clone runner

**Files:**
- Modify: `packs/tts-voxcpm2/pack.json:1-123`
- Modify: `app/pack_registry.php:250-310,762-825`
- Modify: `app/task_queue.php:155-225`
- Modify: `app/gateway.php:1297-1600`
- Modify: `app/pack_job_runner.php:160-230,438-585,1030-1125`
- Modify: `packs/tts-voxcpm2/service/job.py`
- Create: `packs/tts-voxcpm2/service/test_job.py`
- Modify: `tests/test_tts_voxcpm2.php`
- Test: Python runner and focused PHP suite

- [x] **Step 1: Add failing three-mode and immutable-context tests**

  In Python tests, require:

  - `ultimate_clone` accepts a managed WAV, confirmed prompt text, and exact
    trusted hashes;
  - it passes `reference_wav_path`, `prompt_wav_path`, and `prompt_text` to
    `TtsRequest`;
  - missing text, changed text hash, changed WAV hash, or extra context key
    fails before synthesis;
  - synthesis metadata contains hashes and an exact device attestation but no
    transcript or local Profile ID;
  - fake synthesis reports `{"type":"fake","real_inference":false}` and real
    CUDA synthesis reports `{"type":"cuda","real_inference":true}`.

  In PHP tests, require the normalized current Voice Context definition:

  ```php
  [
      'mode_input' => 'mode',
      'design_value' => 'design',
      'clone_value' => 'clone',
      'ultimate_value' => 'ultimate_clone',
      'profile_input' => 'voice_profile_id',
      'profile_task_input' => 'voice_profile_task_id',
      'design_prompt_input' => 'voice_prompt',
      'container_path' => '/data/voice_profiles/reference.wav',
  ]
  ```

  Require the ultimate snapshot to include WAV SHA-256, prompt SHA-256, and
  confirmation timestamp but no prompt text.

- [x] **Step 2: Run tests and verify the current design/clone contract fails**

  Run:

  ```bash
  python3 packs/tts-voxcpm2/service/test_job.py
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because the async manifest and runner accept only
  `design`/`clone`.

- [x] **Step 3: Extend the manifest and trusted PHP contract**

  Bump the Pack patch version. Add `voice_profile_task_id` to async input
  fields and declare:

  ```json
  "mode": {"type": "string", "required": false, "default": "design", "enum": ["design", "clone", "ultimate_clone"], "max_length": 16},
  "voice_profile_id": {"type": "integer", "required": false, "min": 1, "max": 2147483647},
  "voice_profile_task_id": {"type": "string", "required": false, "max_length": 64}
  ```

  Extend `hub_pack_async_job_voice_context_contract()` and
  `hub_pack_job_voice_context_snapshot()` with `ultimate_value` and
  `profile_task_input`. Preserve legacy design/clone snapshots for queued old
  tasks.

  Before `hub_pack_job_task_resolve_voice_context()`, resolve one and only one
  of `voice_profile_id` or `voice_profile_task_id`. A native task handle must
  identify a successful owned `voice_profile_prepare` task and linked Profile.
  Replace the handle with the local integer Profile ID and remove the handle
  before task persistence.

  For ultimate mode, require confirmed prompt text and snapshot:

  ```php
  [
      'mode' => 'ultimate_clone',
      'voice_profile_id' => $profileId,
      'reference_audio_sha256' => $wavSha256,
      'prompt_text_sha256' => hash('sha256', $promptText),
      'prompt_text_confirmed_at' => (string)$profile['prompt_text_confirmed_at'],
      'container_path' => '/data/voice_profiles/reference.wav',
  ]
  ```

- [x] **Step 4: Revalidate immediately before GPU inference**

  Move `hub_pack_job_resolve_voice_profile_mount()` to immediately after GPU
  preflight and before workspace creation. For ultimate mode, reload the
  Profile and require the current confirmation timestamp and transcript hash
  to equal the snapshot.

  Return an internal mount descriptor containing:

  ```php
  [
      'source' => $path,
      'container_path' => '/data/voice_profiles/reference.wav',
      'prompt_text' => $mode === 'ultimate_clone' ? $promptText : null,
  ]
  ```

  Pass that descriptor into `hub_pack_job_prepare_workspace()`. Inject
  `prompt_text` into private `workspace/input/request.json` only for ultimate
  mode. Do not add it to Pack input fields or task JSON.

  Throw `voice_profile_changed` for a post-admission WAV/transcript mismatch
  and `voice_profile_unavailable` for deletion, expiry, or missing files.

  In `job.py`, allow all three modes and the ephemeral `prompt_text`. Return a
  public `voice_context` containing mode, control, hashes, and container path,
  but no Profile ID or transcript. For real ultimate inference construct:

  ```python
  request = TtsRequest(
      text=chunk["text"],
      mode=voice["mode"],
      control=voice.get("control"),
      reference_wav_path=str(source),
      prompt_wav_path=str(source) if voice["mode"] == "ultimate_clone" else None,
      prompt_text=prompt_text if voice["mode"] == "ultimate_clone" else None,
  )
  ```

  Add this safe top-level synthesis metadata:

  ```python
  "device": {
      "type": "fake" if fake_enabled() else "cuda",
      "real_inference": not fake_enabled(),
  }
  ```

  Add `device` to the Pack's required `synthesis_metadata` keys. There is no
  CPU fallback in this runner: a real path reaches metadata only after CUDA
  availability and model inference succeed.

- [x] **Step 5: Run and commit the runner slice**

  Run:

  ```bash
  python3 packs/tts-voxcpm2/service/test_job.py
  php scripts/run_tests.php --suite=voice-cluster
  php -r 'require "app/bootstrap.php"; $pack=hub_get_pack("tts-voxcpm2"); exit(is_array($pack) && ($pack["status"] ?? "") === "ok" ? 0 : 1);'
  ```

  Expected: Python and PHP three-mode, privacy, compatibility, and manifest
  checks pass; the manifest probe exits 0.

  Commit:

  ```bash
  git add packs/tts-voxcpm2/pack.json packs/tts-voxcpm2/service/job.py packs/tts-voxcpm2/service/test_job.py app/pack_registry.php app/task_queue.php app/gateway.php app/pack_job_runner.php tests/test_tts_voxcpm2.php
  git commit -m "feat: add async voxcpm2 ultimate clone"
  ```

### Task 4: Pin Profile handles through the Cluster Router

**Files:**
- Modify: `app/cluster_router.php:1000-1285,1406-1560,1984-2215`
- Modify: `tests/test_cluster_router.php`
- Test: `tests/test_cluster_router.php`

- [x] **Step 1: Add failing Router affinity and isolation tests**

  Cover self and remote stations:

  - `profile_prepare` uses normal `voice_generate` selection and rewrites the
    child task response to an opaque route;
  - status/confirm/delete and clone/ultimate requests with that route use the
    same station even when another station has more free VRAM;
  - the child receives only its numeric task ID;
  - another Token receives `profile_task_not_found` before transport;
  - a stale or disabled pinned station returns `station_unavailable`;
  - no second station is tried after a pinned dispatch failure;
  - profile multipart fields and WAV survive relay;
  - child task/Profile IDs do not appear in Router responses or access logs.

- [x] **Step 2: Run the focused suite and verify affinity tests fail**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because `hub_cluster_dispatch()` load-balances every new
  `voice_generate` request and forwards the opaque handle unchanged.

- [x] **Step 3: Resolve and rewrite the Profile task reference**

  Add one helper that reads only a scalar `voice_profile_task_id` from the
  normalized multipart form, URL-encoded form, or top-level JSON object:

  ```php
  function hub_cluster_voice_profile_reference(array $normalized): ?string;
  ```

  Add one mutation helper:

  ```php
  function hub_cluster_replace_voice_profile_reference(array $normalized, string $remoteTaskId): array;
  ```

  Reject arrays, duplicate fields, control characters, and bodies that cannot
  be decoded and re-encoded without changing unrelated values.

  In `hub_cluster_dispatch()`, after customer authentication and request
  normalization, resolve the opaque route through
  `hub_cluster_get_route_for_customer()`. Require:

  ```php
  (string)$route['mode'] === 'voice_generate'
      && (string)$route['remote_task_id'] !== ''
      && (int)$route['station_id'] > 0;
  ```

  Select that exact enabled/fresh station from refreshed inventory instead of
  calling `hub_cluster_select_station()`. Replace the handle only in the
  trusted downstream request.

- [x] **Step 4: Preserve safe Profile task results**

  Extend `hub_cluster_router_public_task_result()` with a
  `voice_profile_prepare` result branch that accepts only:

  ```php
  [
      'kind',
      'transcription_status',
      'transcript_confirmed',
      'text_chars',
      'prompt_text_sha256',
  ]
  ```

  Enforce exact keys and scalar bounds. Do not relay arbitrary child result
  JSON. `profile_status` remains the only route that can return an unconfirmed
  transcript draft.

  Map foreign handles to 404 `profile_task_not_found`, and pinned availability
  failures to 503 `station_unavailable`.

- [x] **Step 5: Run and commit the Router slice**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  git diff --check
  ```

  Expected: all native and remote task-affinity, ownership, relay, no-retry,
  response-redaction, and accounting checks pass.

  Commit:

  ```bash
  git add app/cluster_router.php tests/test_cluster_router.php
  git commit -m "feat: pin cluster voice profiles to tasks"
  ```

### Task 5: Publish the installed async mode and complete API documents

**Files:**
- Modify: `app/pack_registry.php:98-190`
- Modify: `app/cluster_router.php:3068-3130`
- Modify: `app/public_api_docs.php:220-380`
- Modify: `tests/test_public_api_docs.php`
- Modify: `tests/test_cluster_router.php`
- Modify: `packs/tts-voxcpm2/README.md`
- Modify: `README.md`
- Test: focused suite and rendered document contract

- [x] **Step 1: Add failing installed-Pack publication assertions**

  Install and enable `tts-voxcpm2` in a fixture whose only service row is
  `mode=tts`, `runtime_status=stopped`. Assert:

  - `hub_public_api_services()` contains `voice_generate`;
  - `hub_cluster_node_published_modes()` contains `voice_generate`;
  - selected child modes can publish it and grant it to the node Token;
  - a missing, disabled, invalid-version, or non-runtime-ready Pack does not;
  - native docs list all five operations and all three synthesis modes;
  - Cluster docs use `voice_profile_task_id`, not child `voice_profile_id`.

- [x] **Step 2: Run the focused suite and confirm publication fails**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because both inventories currently begin from running service
  rows.

- [x] **Step 3: Derive async modes from their canonical installed Packs**

  Add:

  ```php
  function hub_available_pack_job_async_modes(PDO $db): array
  {
      $available = [];
      foreach (array_keys(hub_pack_job_async_routes()) as $mode) {
          try {
              hub_resolve_pack_job_async_route($db, $mode);
              $available[] = $mode;
          } catch (Throwable) {
          }
      }
      sort($available);
      return $available;
  }
  ```

  Merge this list into `hub_cluster_node_published_modes()`. Keep selected-mode
  filtering unchanged, so installation does not silently publish a mode the
  administrator did not select.

  In `hub_public_api_services()`, add missing available async routes after
  healthy synchronous service processing. Build each service from its
  canonical Pack manifest and `hub_public_api_pack_job_async_contract()`;
  never create a fake service row or health probe.

- [x] **Step 4: Add the operation contract and safe examples**

  For `voice_generate`, append exact operation entries for
  `profile_prepare`, `profile_status`, `profile_confirm`, `profile_delete`,
  and `synthesize`. Add stable errors from the approved spec and examples that
  show:

  ```text
  profile_prepare -> cluster_task_status -> profile_status
  -> profile_confirm -> ultimate_clone -> cluster_artifact
  ```

  Examples contain placeholders only. Update Pack/root README with the same
  flow, pinned-station behavior, explicit deletion, and the statement that
  MyAI stores `voice_profile_task_id`.

- [x] **Step 5: Run and commit publication/docs**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  php -r 'require "app/bootstrap.php"; $p=hub_get_pack("tts-voxcpm2"); exit(is_array($p) && ($p["status"] ?? "") === "ok" ? 0 : 1);'
  git diff --check
  ```

  Expected: focused fixture tests pass and the repository Pack manifest probe
  exits 0. The live database probe runs after the idempotent Pack upgrade in
  Task 7.

  Commit:

  ```bash
  git add app/pack_registry.php app/cluster_router.php app/public_api_docs.php tests/test_public_api_docs.php tests/test_cluster_router.php packs/tts-voxcpm2/README.md README.md
  git commit -m "docs: publish cluster ultimate clone API"
  ```

### Task 6: Add the explicit real Cluster acceptance command

**Files:**
- Create: `scripts/voxcpm2_cluster_acceptance.php`
- Modify: `tests/test_tts_voxcpm2.php`
- Test: static/offline acceptance checks

- [x] **Step 1: Add failing acceptance-script safety assertions**

  Require the script to:

  - call only `cluster_api.php?mode=voice_generate` and returned
    `cluster_task_*`/`cluster_artifact` links;
  - read base URL, Token, WAV path, prompt text, and target text from
    `AIHUB_VOXCPM2_CLUSTER_*` environment variables;
  - never print or persist those values;
  - use bounded connect, request, poll, and total timeouts;
  - validate SHA-256, RIFF/WAVE, `ffprobe`, metadata mode/model/device, and
    artifact acknowledgement;
  - call `profile_delete` in `finally`;
  - refuse to run under the ordinary test environment.

- [x] **Step 2: Run the focused suite and verify the script check fails**

  Run:

  ```bash
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: FAIL because the acceptance command does not exist.

- [x] **Step 3: Implement the bounded standard-cURL workflow**

  Implement one CLI command with these required environment names:

  ```text
  AIHUB_VOXCPM2_CLUSTER_BASE_URL
  AIHUB_VOXCPM2_CLUSTER_TOKEN
  AIHUB_VOXCPM2_CLUSTER_REFERENCE_WAV
  AIHUB_VOXCPM2_CLUSTER_PROMPT_TEXT
  AIHUB_VOXCPM2_CLUSTER_TARGET_TEXT
  ```

  Apply the repository's executable PHP policy:

  ```bash
  chmod 755 scripts/voxcpm2_cluster_acceptance.php
  ```

  Use PHP cURL and JSON decoding only. Submit `profile_prepare` with
  `transcript_confirmed=1`, poll through the returned status URL, call
  `profile_status`, submit `ultimate_clone`, poll, download required artifacts
  to a `0700` temporary directory, verify declared hashes and WAV structure,
  and acknowledge each artifact.

  Run `ffprobe` through `proc_open()` with an argument array, not a shell
  command. Require positive duration and one audio stream. Require metadata:

  ```php
  ($metadata['controls']['mode'] ?? null) === 'ultimate_clone'
      && ($metadata['model']['label'] ?? null) === 'VoxCPM2'
      && ($metadata['device']['type'] ?? null) === 'cuda'
      && ($metadata['device']['real_inference'] ?? null) === true;
  ```

  Print only one final safe JSON object:

  ```json
  {"ok":true,"profile_prepared":true,"ultimate_clone":true,"audio_valid":true,"gpu":true,"artifacts_acknowledged":true}
  ```

  Delete temporary files and invoke `profile_delete` on every terminal path.

- [x] **Step 4: Run offline syntax and safety checks**

  Run:

  ```bash
  php -l scripts/voxcpm2_cluster_acceptance.php
  php scripts/run_tests.php --suite=voice-cluster
  ```

  Expected: syntax and static safety assertions pass without making a network
  call or launching a model.

- [x] **Step 5: Commit the acceptance command**

  ```bash
  git add scripts/voxcpm2_cluster_acceptance.php tests/test_tts_voxcpm2.php
  git commit -m "test: add cluster ultimate clone acceptance"
  ```

### Task 7: Upgrade the live Pack and run real inference

**Files:**
- Modify: installed Pack/service records through existing installer commands
- Modify: `docs/superpowers/specs/2026-07-30-cluster-ultimate-clone-api-design.md`
- Modify: `docs/superpowers/plans/2026-07-30-cluster-ultimate-clone-api.md`
- Test: live Cluster API, Docker GPU runner, downloaded artifacts

- [x] **Step 1: Run final offline verification**

  Run:

  ```bash
  git diff --check
  php scripts/run_tests.php --suite=voice-cluster
  python3 packs/tts-voxcpm2/service/test_job.py
  php -l app/voice_profile_tasks.php
  php -l scripts/voxcpm2_cluster_acceptance.php
  ```

  Expected: all focused checks pass. Do not run the full 532-test suite unless
  focused failures expose a shared regression.

- [x] **Step 2: Upgrade the local Pack through the existing installer path**

  Use the existing idempotent CLI installer so it builds the declared runner
  image and updates the existing default Service Instance without hand-editing
  SQLite:

  ```bash
  php -r 'require "app/bootstrap.php"; $r=hub_install_pack(hub_db(),"tts-voxcpm2",["idempotent"=>true]); echo $r["service"]["service_key"]," ",$r["service"]["pack_version"],PHP_EOL;'
  ```

  Then confirm route resolution from the live database:

  ```bash
  php -r 'require "app/bootstrap.php"; $r=hub_resolve_pack_job_async_route(hub_db(),"voice_generate"); echo $r["pack_version"],PHP_EOL;'
  ```

  Expected: the new Pack patch version prints and no
  `pack_version_unavailable` error occurs.

- [x] **Step 3: Publish and refresh the child inventory**

  Ensure `voice_generate` remains explicitly selected in Cluster node modes,
  then force the existing Cluster inventory refresh. Confirm the live public
  document contains `voice_generate`, `profile_prepare`, and
  `ultimate_clone`.

- [x] **Step 4: Run the real acceptance command**

  Supply consented test material only through the environment and run:

  ```bash
  php scripts/voxcpm2_cluster_acceptance.php
  ```

  Expected:

  ```json
  {"ok":true,"profile_prepared":true,"ultimate_clone":true,"audio_valid":true,"gpu":true,"artifacts_acknowledged":true}
  ```

  Also confirm no active GPU lease, Profile test WAV, or acceptance temporary
  directory remains after completion.

- [x] **Step 5: Record acceptance and commit the approved documents**

  Change the spec status to `implemented; real Cluster smoke passed` and check
  completed plan boxes. Commit only the safe status and boolean acceptance
  facts:

  ```bash
  git add docs/superpowers/specs/2026-07-30-cluster-ultimate-clone-api-design.md docs/superpowers/plans/2026-07-30-cluster-ultimate-clone-api.md
  git commit -m "docs: record cluster ultimate clone acceptance"
  ```

  Do not commit the WAV, transcript, Token, task/artifact IDs, URLs, hashes,
  model cache, generated audio, database, or runtime logs.

## Acceptance Record

Date: 2026-07-31

- `profile_prepared`: true
- `ultimate_clone`: true
- `audio_valid`: true
- `gpu`: true
- `artifacts_acknowledged`: true
- `no_active_gpu_lease`: true
- `no_acceptance_member`: true
- `no_acceptance_token`: true
- `no_active_acceptance_profile`: true
- `no_acceptance_profile_wav`: true
- `no_active_acceptance_task`: true
- `no_cli_temp_dir`: true
- `no_unacknowledged_acceptance_artifacts`: true
