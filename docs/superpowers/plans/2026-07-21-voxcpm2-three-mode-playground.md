# VoxCPM2 Three-Mode Playground Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a real-inference VoxCPM2 playground with Voice Design, Basic Clone, and Ultimate Clone, backed by owner-scoped WAV voice profiles and GPU-first Whisper transcription.

**Architecture:** Keep public TTS requests path-free: the gateway resolves a caller-owned profile and injects the managed container WAV path only for the internal TTS request. Voice-profile upload, SHA-256 deduplication, transcript drafting/confirmation, and internal ASR invocation live in the PHP application layer; the browser never receives a host path or chooses an arbitrary server file. Whisper runs real inference with `auto` choosing CUDA first and CPU `int8` only when CUDA initialization or transcription fails; VoxCPM2 Ultimate Clone receives the same managed WAV for reference and prompt plus only a confirmed transcript.

**Tech Stack:** PHP 8/PDO/SQLite, FastAPI/Pydantic, faster-whisper 1.2.1/CTranslate2, VoxCPM 2.0.3, Docker Compose with NVIDIA GPU access, existing PHP test runner and Python `unittest`.

---

## Guardrails And Preconditions

- The existing working-tree change in `packs/tts-voxcpm2/pack.json` lowers `min_vram_mb` and `recommended_vram_mb` to `9600`. It belongs to the user. Preserve both values exactly; never discard, amend, or stage that hunk without the user's explicit approval.
- Before the task that changes the same manifest, obtain approval to include the existing VRAM hunk in a dedicated commit, or have the user commit it first. This avoids accidentally attributing the user's hardware decision to the three-mode feature.
- The real smoke test needs a WAV for which the API-member owner has consent. Do not use someone else's audio, and do not put a reference WAV, bearer token, transcript, or any `/park/` host path into Git.
- This is one vertical feature: the ASR runtime, profile lifecycle, gateway policy, and TTS UI must land together. Do not expose a public `voice_profile_transcribe` API mode or bypass normal token policy from the browser.

## File Structure

| Path | Responsibility |
| --- | --- |
| `app/db.php` | Add transcript-confirmation persistence and owner+hash lookup index, including migration of old nonempty transcripts to confirmed state. |
| `app/api_tokens.php` | Let the Playground validate an explicitly supplied, transient Bearer token using the same active/expiry/IP/mode rules as the gateway. |
| `app/voice_profiles.php` | Validate and store WAV uploads, find owner-scoped SHA cache hits, invoke the local ASR service, and confirm a draft transcript. |
| `app/gateway.php` | Permit `ultimate_clone` only after resolving an accessible, transcript-confirmed voice profile; inject all internal-only prompt fields. |
| `admin/playground.php` | Provide the profile upload/confirm/retry workflow and sequential three-mode comparison with independent players. |
| `packs/whisper-asr/{pack.json,docker-compose.yml}` | Promote the ASR pack to GPU-first real inference. `USE_GPU=1` in the manifest drives `app/pack_registry.php` to generate production compose with `gpus: all`; the source compose remains consistent for direct development use. |
| `packs/whisper-asr/service/{Dockerfile,requirements.txt,app.py,test_app.py}` | Package CUDA runtime libraries, perform cached faster-whisper inference, report the selected device, and unit-test CUDA-to-CPU fallback. |
| `packs/tts-voxcpm2/{pack.json,service/app.py}` | Advertise Ultimate Clone and pass the validated reference WAV plus confirmed prompt WAV/text to VoxCPM2. |
| `tests/test_tts_voxcpm2.php` | Cover schema, profile privacy/cache behavior, gateway injection, and Playground source/response contracts. |
| `tests/test_audio_packs.php` | Cover the ASR L5 manifest, GPU compose contract, and real-inference source contract. |
| `docs/operations/voxcpm2-three-mode-smoke.md` | Give operators an exact, secret-free real-inference validation procedure. |

### Task 1: Add Profile Confirmation State And Explicit Playground Token Validation

**Files:**
- Modify: `app/db.php:337-354, 512-580`
- Modify: `app/api_tokens.php:292-355`
- Modify: `app/voice_profiles.php:48-112`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write the failing PHP tests for confirmation and explicit token lookup.**

  Add tests that create two members and a `tts`-permitted token, then assert all of the following:

  ```php
  hub_test_assert(hub_get_voice_profile_for_member($db, $profileId, $ownerId) !== null, 'owner must load profile');
  hub_test_assert(hub_get_voice_profile_for_member($db, $profileId, $otherMemberId) === null, 'same SHA must not make a private profile visible');
  hub_test_assert(($profile['prompt_text_confirmed_at'] ?? null) === null, 'draft transcript must start unconfirmed');

  $confirmed = hub_confirm_voice_profile_prompt($db, $profileId, $ownerId, '這是一段已人工確認的字幕。');
  hub_test_assert($confirmed['prompt_text'] === '這是一段已人工確認的字幕。', 'confirmation must retain edited transcript');
  hub_test_assert((string)$confirmed['prompt_text_confirmed_at'] !== '', 'confirmation timestamp must be written');

  $auth = hub_gateway_authenticate_api_token($db, 'tts', '203.0.113.10', (string)$token['plain_token']);
  hub_test_assert(!empty($auth['ok']) && (int)$auth['context']['member_id'] === $ownerId, 'explicit playground token must use gateway rules');
  ```

- [ ] **Step 2: Run the focused test and verify that it fails because the field and helpers do not exist.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL mentioning `prompt_text_confirmed_at`, `hub_confirm_voice_profile_prompt`, or the fourth argument to `hub_gateway_authenticate_api_token`.

- [ ] **Step 3: Add the schema migration and profile confirmation helper.**

  Add the nullable column to both the create-table definition and idempotent migration, migrate existing nonempty legacy prompts once, and index exact owner-hash cache lookups:

  ```php
  hub_add_column_if_missing($db, 'voice_profiles', 'prompt_text_confirmed_at', 'TEXT NULL');
  $db->exec("UPDATE voice_profiles
             SET prompt_text_confirmed_at = COALESCE(prompt_text_confirmed_at, updated_at)
             WHERE prompt_text IS NOT NULL AND trim(prompt_text) <> ''");
  $db->exec('CREATE INDEX IF NOT EXISTS idx_voice_profiles_owner_sha_active
             ON voice_profiles(owner_member_id, reference_audio_sha256)
             WHERE deleted_at IS NULL');
  ```

  Make new `hub_create_voice_profile()` calls leave `prompt_text_confirmed_at` `NULL` unless an explicit trusted, already-confirmed migration input is passed. Add this owner-only helper, including an audit action of `confirm_transcript` and no transcript in audit details:

  ```php
  function hub_confirm_voice_profile_prompt(PDO $db, int $profileId, int $ownerMemberId, string $promptText): array
  {
      $profile = hub_get_voice_profile_for_member($db, $profileId, $ownerMemberId);
      $promptText = trim($promptText);
      if (!$profile || (int)$profile['owner_member_id'] !== $ownerMemberId || $promptText === '') {
          throw new InvalidArgumentException('voice_profile_transcript_invalid');
      }
      $now = hub_now();
      $db->prepare('UPDATE voice_profiles SET prompt_text = :prompt_text, prompt_text_confirmed_at = :confirmed_at, updated_at = :updated_at WHERE id = :id')
          ->execute([':prompt_text' => $promptText, ':confirmed_at' => $now, ':updated_at' => $now, ':id' => $profileId]);
      hub_record_voice_profile_audit($db, $profileId, $ownerMemberId, null, 'confirm_transcript', null, ['text_chars' => strlen($promptText)]);

      return hub_get_voice_profile($db, $profileId) ?? throw new RuntimeException('voice_profile_missing');
  }
  ```

- [ ] **Step 4: Refactor token authentication without changing normal gateway callers.**

  Keep the first three arguments source-compatible and distinguish an omitted token from an explicitly supplied empty token so localhost bypass remains a gateway-only behavior:

  ```php
  function hub_gateway_authenticate_api_token(PDO $db, string $mode, string $clientIp, ?string $providedToken = null): array
  {
      $tokenCameFromRequest = $providedToken === null;
      $plainToken = $providedToken ?? hub_bearer_token_from_request();
      if ($plainToken === '' && $tokenCameFromRequest && hub_is_localhost_ip($clientIp)
          && hub_get_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN') === '1') {
          return ['ok' => true, 'context' => []];
      }
      // Retain the existing hash lookup, enabled/revoked, validity, IP, mode, and usage checks.
  }
  ```

  Do not log, persist, return, or render `$providedToken`.

- [ ] **Step 5: Run regression tests and commit the isolated persistence/auth change.**

  Run: `php scripts/run_tests.php`

  Expected: all PHP tests pass.

  ```bash
  git add app/db.php app/api_tokens.php app/voice_profiles.php tests/test_tts_voxcpm2.php
  git commit -m "feat: track confirmed voice profile transcripts"
  ```

### Task 2: Store Owner-Scoped WAV Profiles, Reuse Exact Matches, And Transcribe Internally

**Files:**
- Modify: `app/voice_profiles.php`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing tests for WAV validation, SHA cache scope, ASR failure retention, and retryable transcript drafts.**

  Use small temporary files with a RIFF/WAVE header, inject a move callback and an ASR callback so the test never calls Docker, and assert:

  ```php
  $first = hub_create_uploaded_voice_profile($db, $ownerId, $upload, $input, $move, static fn (): array => ['ok' => true, 'text' => '自動字幕']);
  hub_test_assert(!$first['cache_hit'] && $first['profile']['prompt_text_confirmed_at'] === null, 'new upload creates an unconfirmed ASR draft');

  $again = hub_create_uploaded_voice_profile($db, $ownerId, $sameUpload, $input, $move, static fn (): array => throw new RuntimeException('must not transcribe'));
  hub_test_assert($again['cache_hit'] && (int)$again['profile']['id'] === (int)$first['profile']['id'], 'same owner bytes must reuse profile');

  $other = hub_create_uploaded_voice_profile($db, $otherMemberId, $sameUpload, $input, $move, static fn (): array => ['ok' => false, 'error' => 'asr_unavailable']);
  hub_test_assert(!$other['cache_hit'] && (int)$other['profile']['owner_member_id'] === $otherMemberId, 'cache must never cross member ownership');
  hub_test_assert(($other['transcription']['error'] ?? '') === 'asr_unavailable', 'ASR error must be returned without deleting the Basic Clone profile');
  ```

- [ ] **Step 2: Run the PHP suite and verify that the new upload helper is absent.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL with `Call to undefined function hub_create_uploaded_voice_profile()`.

- [ ] **Step 3: Implement strict managed-WAV storage and owner-scoped reuse.**

  Add focused helpers in `app/voice_profiles.php`; do not place upload handling in the gateway:

  ```php
  function hub_find_active_voice_profile_by_owner_sha(PDO $db, int $ownerMemberId, string $sha256): ?array;
  function hub_validate_voice_profile_wav(array $upload): array;
  function hub_create_uploaded_voice_profile(PDO $db, int $ownerMemberId, array $upload, array $input, ?callable $moveFile = null, ?callable $transcribe = null): array;
  function hub_transcribe_voice_profile(PDO $db, array $upload): array;
  function hub_retry_voice_profile_transcription(PDO $db, int $profileId, int $ownerMemberId): array;
  ```

  `hub_validate_voice_profile_wav()` must reject upload errors, an empty file, input above `100 * 1024 * 1024`, MIME types outside `audio/wav`, `audio/x-wav`, and `audio/wave`, and files whose first twelve bytes do not contain `RIFF` at offsets `0-3` and `WAVE` at offsets `8-11`. Hash the temporary upload before moving it. On cache hit query only `owner_member_id`, `reference_audio_sha256`, and `deleted_at IS NULL`; return that profile and do not call ASR or write another file. On a miss generate a non-user-controlled filename from the owner id and SHA, move it under `hub_voice_profile_storage_dir()`, create a private profile with a null confirmation timestamp, and audit the upload without recording transcript content.

- [ ] **Step 4: Implement internal ASR invocation and error-safe profile retention.**

  `hub_transcribe_voice_profile()` must obtain the enabled installed `asr` service through `hub_get_service_by_mode($db, 'asr')`, temporarily supply the validated file as `$_FILES['audio']`, set `$_POST['real_inference'] = '1'`, call `hub_proxy_request((string) $service['internal_url'], hub_service_gateway_timeout_sec($service))`, and restore both globals in a `finally` block. It must return a normalized value, never an HTTP response:

  ```php
  ['ok' => true, 'text' => trim((string) $body['text']), 'language' => (string) ($body['language'] ?? 'auto'), 'device' => $body['device'] ?? []]
  ['ok' => false, 'error' => (string) ($body['error'] ?? 'asr_failed'), 'message' => (string) ($body['message'] ?? 'ASR transcription failed')]
  ```

  `hub_create_uploaded_voice_profile()` writes a successful returned text as a **draft** (`prompt_text_confirmed_at = NULL`) and audits `transcribe` with success/device metadata only. On a failure it returns the created profile plus the normalized error and leaves it usable for Basic Clone and later manual confirmation/retry. `hub_retry_voice_profile_transcription()` loads only an owned active profile, reuses its managed WAV, updates `prompt_text` and `language` only on a successful ASR result, resets `prompt_text_confirmed_at` to `NULL`, and records `transcribe_retry`; it returns the same normalized success/error shape.

- [ ] **Step 5: Run regression tests and commit the profile lifecycle.**

  Run: `php scripts/run_tests.php`

  Expected: all PHP tests pass, including exact-byte cache reuse, member isolation, and ASR-failure retention.

  ```bash
  git add app/voice_profiles.php tests/test_tts_voxcpm2.php
  git commit -m "feat: add cached WAV voice profiles"
  ```

### Task 3: Promote Whisper ASR To GPU-First Real Inference With CPU Fallback

**Files:**
- Modify: `packs/whisper-asr/pack.json`
- Modify: `packs/whisper-asr/docker-compose.yml`
- Modify: `packs/whisper-asr/service/Dockerfile`
- Modify: `packs/whisper-asr/service/requirements.txt`
- Modify: `packs/whisper-asr/service/app.py`
- Create: `packs/whisper-asr/service/test_app.py`
- Modify: `tests/test_audio_packs.php`

- [ ] **Step 1: Write failing PHP and Python tests for the L5 contract and candidate order.**

  Update the pack test to require `L5-benchmark-ready`, generated `gpus: all`, `USE_GPU=1`, `WHISPER_REAL_INFERENCE=1`, and no `runtime_not_ready` branch. Create the Python unit test with a fake model factory that fails CUDA and succeeds on CPU:

  ```python
  class WhisperFallbackTests(unittest.TestCase):
      def test_auto_tries_cuda_then_cpu_int8(self) -> None:
          attempts: list[tuple[str, str]] = []
          def factory(_model: str, device: str, compute_type: str, **_kwargs: object) -> FakeModel:
              attempts.append((device, compute_type))
              if device == "cuda":
                  raise RuntimeError("CUDA unavailable")
              return FakeModel("已轉成文字")

          result = app.transcribe_audio_bytes(b"RIFF0000WAVE", "auto", factory)
          self.assertEqual(attempts, [("cuda", "float16"), ("cpu", "int8")])
          self.assertEqual(result["device"]["effective"], "cpu")
          self.assertFalse(result["mock"])
  ```

- [ ] **Step 2: Verify the tests fail against the L3 mock adapter.**

  Run: `php scripts/run_tests.php && docker build -t 3waaihub-whisper-asr:test packs/whisper-asr/service`

  Expected: PHP test fails because the manifest/service still advertises L3/mock behavior; the current image build may succeed, but no real-inference unit test exists yet.

- [ ] **Step 3: Implement a cached faster-whisper runtime with deterministic fallback semantics.**

  Change the pack defaults to `runtime_level` and `target_level` `L5-benchmark-ready`, `WHISPER_REAL_INFERENCE=1`, and preserve `WHISPER_DEVICE=auto` plus `cpu_fallback=true`. Add `USE_GPU=1` to the manifest `env` list so the existing `hub_pack_requests_gpu()` generator produces `gpus: all` for installed instances; also add `gpus: all` to the checked-in source Compose service for direct development use. Set the default compute type to `auto` in the manifest/env settings.

  In `app.py`, load `faster_whisper` only after CUDA libraries are discoverable, cache models by `(model, device, compute_type)`, and implement these exact candidates:

  ```python
  def whisper_candidates() -> list[tuple[str, str]]:
      requested = os.getenv("WHISPER_DEVICE", "auto").strip().lower()
      requested_compute = os.getenv("WHISPER_COMPUTE_TYPE", "auto").strip().lower()
      if requested == "cpu":
          return [("cpu", "int8")]
      if requested == "cuda":
          return [("cuda", "float16" if requested_compute == "auto" else requested_compute)]
      return [("cuda", "float16"), ("cpu", "int8")]
  ```

  `transcribe_audio_bytes()` must write only the request bytes to a named temporary file, call `WhisperModel(model, device=device, compute_type=compute_type, download_root=model_dir)`, exhaust the returned segments, and join nonempty `segment.text.strip()` values. Its successful body includes `ok: true`, `mock: false`, `text`, `segments`, `language`, and `device: {requested, effective, compute_type, fallback_used}`. If every candidate fails, return a `503` `real_inference_failed` response with a safe attempt summary; never return mock transcription for a real request.

- [ ] **Step 4: Supply CUDA 12/CuDNN 9 runtime libraries without embedding a model in the image.**

  Add these pinned runtime dependencies alongside faster-whisper:

  ```text
  nvidia-cublas-cu12
  nvidia-cudnn-cu12==9.*
  ```

  Add `configure_cuda_library_path()` before importing `faster_whisper`; it imports `nvidia.cublas.lib` and `nvidia.cudnn.lib`, prepends their package directories to `LD_LIBRARY_PATH`, and skips missing packages on CPU. Keep `/models/whisper` and `/cache/whisper` as writable mounts; `download_root` must be `/models/whisper`, so downloaded CTranslate2 model files remain local between container restarts. Extend the Dockerfile to copy and run `test_app.py` with `python3 -m unittest -v test_app.py`; the build test must use fakes and never download a model.

- [ ] **Step 5: Run source, unit, and image regression checks, then commit.**

  Run:

  ```bash
  php scripts/run_tests.php
  docker build -t 3waaihub-whisper-asr:test packs/whisper-asr/service
  docker run --rm 3waaihub-whisper-asr:test python3 -m unittest -v test_app.py
  ```

  Expected: PHP suite passes, image builds, and Python reports `OK` with the CUDA-then-CPU test.

  ```bash
  git add packs/whisper-asr tests/test_audio_packs.php
  git commit -m "feat: run Whisper ASR with GPU-first inference"
  ```

### Task 4: Enable Ultimate Clone In The Gateway And VoxCPM2 Adapter

**Files:**
- Modify: `app/gateway.php:155-230`
- Modify: `packs/tts-voxcpm2/service/app.py:134-350`
- Modify: `packs/tts-voxcpm2/pack.json`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Resolve the pre-existing TTS pack manifest hunk before editing it.**

  Run: `git diff -- packs/tts-voxcpm2/pack.json`

  Expected: only the user-owned `min_vram_mb` and `recommended_vram_mb` changes are present. Obtain explicit approval to commit those values separately, or wait until the user has committed them. Do not run `git add packs/tts-voxcpm2/pack.json` while this condition is unresolved.

- [ ] **Step 2: Add failing tests for Ultimate Clone authorization and safe payload injection.**

  Exercise `hub_prepare_tts_voxcpm2_payload()` with an owned profile in three states:

  ```php
  hub_test_assert(($unconfirmed['response']['status'] ?? 0) === 409, 'ultimate clone must require a confirmed transcript');
  hub_test_assert(($preparedBody['reference_wav_path'] ?? '') === '/data/voice_profiles/clone_reference.wav', 'gateway must map the profile path');
  hub_test_assert(($preparedBody['prompt_wav_path'] ?? '') === $preparedBody['reference_wav_path'], 'ultimate clone must use the same managed WAV for prompt and reference');
  hub_test_assert(($preparedBody['prompt_text'] ?? '') === '確認過的字幕', 'gateway must inject the stored transcript');
  hub_test_assert(!str_contains((string)$prepared['body'], HUB_ROOT), 'host paths must not leave the gateway');
  ```

  Also assert that client-supplied `reference_audio_path`, `prompt_wav_path`, `prompt_audio_path`, or `prompt_text` returns `400`, and update the service-source test to expect `ultimate_clone` rather than a `501` branch.

- [ ] **Step 3: Run the PHP suite and verify that Ultimate Clone remains unsupported.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL with `ultimate_clone_not_ready` or missing `prompt_wav_path`/`prompt_text` support.

- [ ] **Step 4: Implement server-owned Ultimate Clone fields in the gateway.**

  Treat `clone` and `ultimate_clone` as profile-based modes. After owner/shared access resolution, always set `reference_wav_path`, `voice_profile_id`, and `reference_audio_sha256`; for Ultimate Clone require both a nonempty `prompt_text` and nonempty `prompt_text_confirmed_at`, then inject:

  ```php
  if ($ttsMode === 'ultimate_clone') {
      if (trim((string) $profile['prompt_text']) === '' || empty($profile['prompt_text_confirmed_at'])) {
          return ['response' => hub_gateway_error(409, 'voice_profile_transcript_unconfirmed', 'Ultimate clone requires a confirmed voice profile transcript')];
      }
      $payload['prompt_wav_path'] = $payload['reference_wav_path'];
      $payload['prompt_text'] = (string) $profile['prompt_text'];
  }
  ```

  Keep exact existing `visibility = "shared"` authorization intact. Audit both modes as `use` but include only profile id, service, mode, and text character count in audit details.

- [ ] **Step 5: Implement Ultimate Clone in the TTS container and advertise it.**

  Extend `TtsRequest` and the generated kwargs:

  ```python
  class TtsRequest(BaseModel):
      # existing fields
      prompt_wav_path: str | None = None
      prompt_text: str | None = None

  if request.mode == "ultimate_clone":
      prompt = validate_reference_path(request.prompt_wav_path)
      reference = validate_reference_path(request.reference_wav_path)
      if prompt is None or reference is None or prompt != reference:
          raise ValueError("ultimate_clone_prompt_wav_required")
      if not (request.prompt_text or "").strip():
          raise ValueError("ultimate_clone_prompt_text_required")
      kwargs["reference_wav_path"] = str(reference)
      kwargs["prompt_wav_path"] = str(prompt)
      kwargs["prompt_text"] = request.prompt_text.strip()
  ```

  Permit `ultimate_clone` in the endpoint's allowed modes and use the same validation for mock and real paths. Update `pack.json` to list all three `tts_modes`; no public manifest, artifact metadata, or error body may echo `prompt_text`.

- [ ] **Step 6: Run regression tests and commit only after the manifest precondition is cleared.**

  Run: `php scripts/run_tests.php`

  Expected: all PHP tests pass; Ultimate Clone fails only for an unconfirmed/inaccessible profile and otherwise receives internal managed fields.

  ```bash
  git add app/gateway.php packs/tts-voxcpm2/service/app.py tests/test_tts_voxcpm2.php
  git add packs/tts-voxcpm2/pack.json
  git commit -m "feat: enable VoxCPM2 ultimate voice clone"
  ```

### Task 5: Add Voice Profile Actions To The Authenticated Playground

**Files:**
- Modify: `admin/playground.php:77-384, 650-885`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing Playground source/behavior tests.**

  Add checks for the server-side action names and security boundary:

  ```php
  foreach (['voice_profile_upload', 'voice_profile_confirm', 'voice_profile_retry_asr', 'hub_gateway_authenticate_api_token', 'hub_create_uploaded_voice_profile'] as $needle) {
      hub_test_assert(str_contains($source, $needle), 'playground must provide secured voice profile workflow: ' . $needle);
  }
  foreach (['reference_audio_path', 'prompt_wav_path', 'prompt_audio_path'] as $forbidden) {
      hub_test_assert(!str_contains($ttsClientPayload, $forbidden), 'playground client payload must not contain ' . $forbidden);
  }
  ```

- [ ] **Step 2: Run the PHP suite and verify that the new actions are absent.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL because the current page exposes only a free-text `reference_audio_id` and `execute` action.

- [ ] **Step 3: Implement server-side profile action handlers before rendering the page.**

  Add a Playground helper that validates the one-time `bearer_token` with `hub_gateway_authenticate_api_token($db, 'tts', hub_get_client_ip(), $token)`, requires a returned `member_id`, and discards the token immediately after use. Dispatch only these CSRF-protected actions:

  ```php
  match ($_POST['action'] ?? '') {
      'voice_profile_upload' => hub_create_uploaded_voice_profile($db, $memberId, $_FILES['reference_wav'] ?? [], $input),
      'voice_profile_confirm' => hub_confirm_voice_profile_prompt($db, $profileId, $memberId, (string) ($_POST['prompt_text'] ?? '')),
      'voice_profile_retry_asr' => hub_retry_voice_profile_transcription($db, $profileId, $memberId),
      'execute' => hub_playground_execute_tts($token),
      default => null,
  };
  ```

  `hub_retry_voice_profile_transcription()` must use the existing managed profile WAV, preserve the profile when ASR fails, write only a new draft on success, and reset `prompt_text_confirmed_at` to `NULL`. Never obtain a profile's owner from the session user when the bearer token says otherwise.

- [ ] **Step 4: Run the PHP suite and commit the server-side action layer.**

  Run: `php scripts/run_tests.php`

  Expected: all PHP tests pass; upload and retry are restricted by the TTS-authorized token's member id.

  ```bash
  git add admin/playground.php tests/test_tts_voxcpm2.php app/voice_profiles.php
  git commit -m "feat: manage voice profiles from TTS playground"
  ```

### Task 6: Render The Three-Mode Flow And Sequential Comparison

**Files:**
- Modify: `admin/playground.php:77-384, 769-885`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Write failing tests for all three controls and independent audio URLs.**

  Add assertions for `ultimate_clone`, `compare_all`, `reference_wav`, `prompt_text`, `audioUrls`, and three named result keys `design`, `clone`, `ultimate_clone` in `admin/playground.php`. Keep the existing single-result audio test intact.

- [ ] **Step 2: Run the PHP suite and verify that comparison rendering does not exist.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL because the existing page supports only `design`, `clone`, and one `$audioUrl`.

- [ ] **Step 3: Replace the free-form profile ID workflow with owned profile selection and mode-specific inputs.**

  On the TTS panel, render:

  ```html
  <input name="reference_wav" type="file" accept="audio/wav,.wav">
  <select name="reference_audio_id">
      <option value="">Select a Voice Profile</option>
      <?php foreach ($voiceProfiles as $voiceProfile): ?>
          <option value="voice_profile_<?= (int) $voiceProfile['id'] ?>"><?= hub_h((string) $voiceProfile['name']) ?></option>
      <?php endforeach; ?>
  </select>
  <textarea name="prompt_text" rows="4"></textarea>
  <input name="tts_mode" type="radio" value="design">
  <input name="tts_mode" type="radio" value="clone">
  <input name="tts_mode" type="radio" value="ultimate_clone">
  <input name="compare_all" type="checkbox" value="1">
  ```

  Populate the select only from active profiles available to the transient token's member; it may display a shared profile when the existing rule permits it, but upload/cache actions remain owner-only. Show an ASR-produced transcript as editable text and a distinct confirm submit action. Do not put a browser-visible host/container path, transcript cache hash, or bearer token into generated JavaScript.

- [ ] **Step 4: Execute comparison strictly sequentially and render one player per successful mode.**

  Add `hub_playground_execute_tts(string $token): array` and `hub_playground_execute_tts_mode(string $ttsMode, string $token): array`; the second helper uses the existing local API cURL behavior with a server-built JSON payload. When `compare_all=1`, call the local TTS API in this exact order and wait for each HTTP result before the next:

  ```php
  $modes = !empty($_POST['compare_all']) ? ['design', 'clone', 'ultimate_clone'] : [trim((string) $_POST['tts_mode'])];
  foreach ($modes as $ttsMode) {
      $results[$ttsMode] = hub_playground_execute_tts_mode($ttsMode, $token);
  }
  ```

  Resolve each artifact through the existing authenticated `playground_artifact.php` mechanism and return `audioUrls` keyed by mode. Render a labeled `<audio controls>` for every nonempty URL; do not stop or evict NIM, Whisper, or VoxCPM2 services before or after comparison. When Ultimate Clone is not confirmed, render its `409 voice_profile_transcript_unconfirmed` result beside the successful Design/Basic results rather than substituting a different mode.

- [ ] **Step 5: Run regression tests and commit the UI/compare behavior.**

  Run: `php scripts/run_tests.php`

  Expected: all PHP tests pass and static contracts cover all three modes, upload/confirmation, and multiple audio players.

  ```bash
  git add admin/playground.php tests/test_tts_voxcpm2.php
  git commit -m "feat: compare VoxCPM2 voice modes in playground"
  ```

### Task 7: Verify Real Inference And Document The Operator Smoke Test

**Files:**
- Create: `docs/operations/voxcpm2-three-mode-smoke.md`
- Modify: `tests/test_audio_packs.php`
- Modify: `tests/test_tts_voxcpm2.php`

- [ ] **Step 1: Add failing test assertions for the documented GPU-first smoke contract.**

  Assert the operations document exists and contains each verifiable token:

  ```php
  foreach (['WHISPER_REAL_INFERENCE=1', 'mock": false', 'effective": "cuda"', 'ultimate_clone', 'voice_profile_transcript_unconfirmed'] as $needle) {
      hub_test_assert(str_contains($operationsDoc, $needle), 'operator smoke document missing ' . $needle);
  }
  ```

- [ ] **Step 2: Run the PHP suite and verify that the operations document is missing.**

  Run: `php scripts/run_tests.php`

  Expected: FAIL because `docs/operations/voxcpm2-three-mode-smoke.md` does not exist.

- [ ] **Step 3: Write the operations procedure with no secrets or private audio.**

  The document must tell the operator to:

  ```bash
  cd /park/3waAIHub
  WHISPER_COMPOSE="$(php -r 'require "app/bootstrap.php"; $s = hub_get_service_by_mode(hub_db(), "asr"); if (!$s) { exit(1); } echo hub_path((string) $s["compose_file"]);')"
  TTS_COMPOSE="$(php -r 'require "app/bootstrap.php"; $s = hub_get_service_by_mode(hub_db(), "tts"); if (!$s) { exit(1); } echo hub_path((string) $s["compose_file"]);')"
  ASR_SERVICE="$(php -r 'require "app/bootstrap.php"; $s = hub_get_service_by_mode(hub_db(), "asr"); if (!$s) { exit(1); } echo $s["service_key"];')"
  docker compose -f "$WHISPER_COMPOSE" up -d --build
  docker compose -f "$TTS_COMPOSE" up -d --build
  nvidia-smi
  ```

  Then use the authenticated Playground at `admin/playground.php?mode=tts` with a consented WAV and an API token that has `tts` access. It must specify expected observations: upload creates/reuses an owner-only profile; first use downloads Whisper/VoxCPM2 files under the configured `AIHUB_MODELS_DIR` (currently `/park/models` on 3wa); ASR returns `mock: false` and `device.effective: cuda` on the GPU path; transcript confirmation is required for Ultimate; `Test all 3` creates three independently playable WAV artifacts; and a failed CUDA attempt must report `device.effective: cpu` with `fallback_used: true`, not mock output.

  Include the operational caveat that Docker requires its NVIDIA runtime to start the GPU compose service; the inference-level CPU fallback handles CUDA model failures after the container starts and is not a substitute for a host without a usable Docker GPU runtime. Cite [the official faster-whisper CUDA 12/cuDNN 9 requirements](https://github.com/SYSTRAN/faster-whisper/blob/master/README.md) and [the official VoxCPM2 model card](https://huggingface.co/openbmb/VoxCPM2) for the Basic/Ultimate reference fields.

- [ ] **Step 4: Run the full automated suite and real smoke on 3wa.**

  Run:

  ```bash
  php scripts/run_tests.php
  php -l app/db.php app/api_tokens.php app/voice_profiles.php app/gateway.php admin/playground.php
  docker compose -f "$WHISPER_COMPOSE" exec "$ASR_SERVICE" python3 -m unittest -v test_app.py
  ```

  Expected: PHP tests and syntax checks pass; Python unit tests report `OK`. Perform the documented authenticated Playground flow once with real inference, save only the resulting request IDs in the deployment notes, and confirm `mock: false` for ASR plus each of the three TTS responses.

- [ ] **Step 5: Commit tests and operations documentation.**

  ```bash
  git add docs/operations/voxcpm2-three-mode-smoke.md tests/test_audio_packs.php tests/test_tts_voxcpm2.php
  git commit -m "docs: add three-mode voice inference smoke procedure"
  ```

## Final Acceptance Checklist

- [ ] `php scripts/run_tests.php` is green.
- [ ] Whisper's first candidate is CUDA `float16`; its auto fallback is CPU `int8`; failed real inference is never mocked.
- [ ] Exact-byte SHA cache reuse is limited to the uploading member; existing explicit shared-profile use continues to work.
- [ ] WAV validation rejects non-WAV, invalid RIFF/WAVE headers, empty, and oversized files before persistent storage.
- [ ] A failed ASR request preserves the profile for Basic Clone, and retry/manual confirmation remains possible.
- [ ] Gateway rejects client path/transcript injection and lets Ultimate Clone use only the profile's confirmed transcript plus the same managed WAV for both fields.
- [ ] Playground comparison invokes modes `design`, `clone`, and `ultimate_clone` sequentially and renders independent artifact players.
- [ ] No bearer token, private transcript, host path, profile bytes, downloaded model, service-generated WAV, or smoke-test audio is added to Git.
