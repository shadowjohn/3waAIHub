# GPT-SoVITS Pack Phase A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Add a local-model GPT-SoVITS Pack at voice_generate_gpt_sovits, with governed clone/ultimate_clone, optional resident reuse, Cluster relay, and a measured comparison with VoxCPM2.

**Architecture:** The Pack declares its own immutable async job and voice-context contract. The current async task path creates and validates the trusted profile snapshot, Pack runner mounts it, and the generic resident protocol dispatches it. The new mode is explicit: it never replaces voice_generate or chooses a provider silently.

**Tech Stack:** PHP 8, SQLite, existing Hub task/Cluster code, Docker Compose, FastAPI, Python, CUDA/PyTorch, ffmpeg, existing PHP test runner, Python unittest.

---

## File map

- Modify: app/pack_registry.php and app/task_queue.php - register the explicit Pack and add the clone-only profile-context contract shape.
- Modify: app/cluster_router.php - treat both voice modes as one profile-sensitive family.
- Modify: app/public_api_docs.php - reuse the profile workflow examples for either explicit mode.
- Create: packs/tts-gpt-sovits/pack.json, docker-compose.yml, jobs/voice_generate.sh, README.md, demo/request.json.
- Create: packs/tts-gpt-sovits/service/Dockerfile, requirements.txt, job.py, app.py, test_job.py, test_app.py.
- Create: tests/test_tts_gpt_sovits.php; modify tests/test_tts_voxcpm2.php, tests/test_api_examples.php, tests/test_audio_packs.php.
- Modify: scripts/audio_packs_acceptance.php; create docs/operations/gpt-sovits-voxcpm2-pk.md; modify README.md.

No database migration, second profile table, sync gateway branch, provider abstraction, dashboard, automatic eviction, design mode, runtime model download, or native Windows Docker path is needed.

### Task 1: Add the Pack contract and explicit async mode

**Files:**
- Modify: app/pack_registry.php:98-340
- Modify: app/pack_registry.php:926-974
- Modify: app/task_queue.php:174-241
- Create: packs/tts-gpt-sovits/pack.json
- Create: packs/tts-gpt-sovits/docker-compose.yml
- Create: packs/tts-gpt-sovits/jobs/voice_generate.sh
- Create: packs/tts-gpt-sovits/README.md
- Create: packs/tts-gpt-sovits/demo/request.json
- Create: tests/test_tts_gpt_sovits.php
- Modify: tests/test_tts_voxcpm2.php

- [ ] **Step 1: Write failing route and manifest tests**

~~~php
hub_test('GPT-SoVITS is a separate governed audio mode', function (): void {
    hub_test_assert((hub_pack_job_async_routes()['voice_generate_gpt_sovits'] ?? null) === [
        'pack_id' => 'tts-gpt-sovits',
        'job' => 'synthesize',
        'accelerator' => 'gpu',
    ], 'GPT route mismatch');

    $manifest = hub_get_pack('tts-gpt-sovits')['manifest'] ?? [];
    hub_test_assert(($manifest['tts_modes'] ?? []) === ['clone', 'ultimate_clone'], 'GPT clone modes mismatch');
    $job = hub_pack_async_job_contract($manifest, 'synthesize');
    hub_test_assert(($job['runner']['required_vram_mb'] ?? 0) === 6144, 'cold GPU budget mismatch');
    hub_test_assert(($job['resident']['protocol'] ?? '') === 'service_data_v1', 'resident protocol mismatch');
});

hub_test('GPT profile jobs use the existing signed snapshot contract', function (): void {
    $manifest = hub_get_pack('tts-gpt-sovits')['manifest'] ?? [];
    $context = hub_pack_async_job_contract($manifest, 'synthesize')['voice_context'] ?? [];
    hub_test_assert(($context['clone_value'] ?? '') === 'clone', 'clone snapshot mismatch');
    hub_test_assert(($context['ultimate_value'] ?? '') === 'ultimate_clone', 'ultimate snapshot mismatch');
    hub_test_assert(!array_key_exists('design_value', $context), 'GPT must not declare design');
});

hub_test('VoxCPM2 public route remains unchanged', function (): void {
    hub_test_assert(hub_pack_job_async_routes()['voice_generate']['pack_id'] === 'tts-voxcpm2', 'Vox route changed');
});
~~~

- [ ] **Step 2: Run and verify failure**

Run: php tests/run.php tests/test_tts_gpt_sovits.php

Expected: FAIL because both route and Pack are absent.

- [ ] **Step 3: Declare the literal route and Pack**

Add this one route to hub_pack_job_async_routes():

~~~php
'voice_generate_gpt_sovits' => ['pack_id' => 'tts-gpt-sovits', 'job' => 'synthesize', 'accelerator' => 'gpu'],
~~~

Add voice_generate_gpt_sovits to the literal list in hub_audio_async_routes(). Keep voice_generate mapped to tts-voxcpm2.

pack.json declares:
- id tts-gpt-sovits, version 0.1.0, default_mode voice_generate_gpt_sovits, capability text_to_speech, tts_modes clone and ultimate_clone;
- one async synthesize job whose request schema is text, mode, voice_profile_id, voice_profile_task_id, language, and waveform_preview; mode defaults to clone and only accepts clone/ultimate_clone;
- clone-only voice_context with mode_input mode, clone_value clone, ultimate_value ultimate_clone, profile_input voice_profile_id, profile_task_input voice_profile_task_id, and container_path /data/voice_profiles/reference.wav;
- gpu runner with required_vram_mb 6144, timeout_seconds 7200, executor container, output generated_audio plus synthesis_metadata, and the existing service_data_v1 resident declaration;
- asset mounts under local Model Repository for a fixed GPT checkpoint, fixed SoVITS checkpoint, Chinese HuBERT, and Chinese RoBERTa; all model paths are required;
- service port 18109 using GPT_SOVITS_LOCAL_PORT, service key gpt-sovits-main, and compose project 3waaihub_gpt_sovits_main;
- settings GPT_SOVITS_EXECUTION_MODE isolated/resident default isolated, GPT_SOVITS_IDLE_UNLOAD_SECONDS default 0, GPT_SOVITS_RESIDENT_MIN_FREE_VRAM_MB default 1024, and generated GPT_SOVITS_INTERNAL_JOB_TOKEN.

docker-compose.yml mounts models at /models/gpt_sovits, cache at /cache/gpt_sovits, service data at /data/service, profiles read-only at /data/voice_profiles, requests all GPUs, and exposes only loopback port 18109. jobs/voice_generate.sh contains:

~~~bash
#!/usr/bin/env bash
set -euo pipefail
exec python3 /app/job.py "$@"
~~~

Add exactly one clone-only voice-context shape to the shared contract before
declaring the Pack. In app/pack_registry.php accept the ordered keys:

~~~php
$cloneOnlyKeys = ['mode_input', 'clone_value', 'ultimate_value', 'profile_input', 'profile_task_input', 'container_path'];
~~~

Validate clone_value and ultimate_value against the mode enum, profile_input as
an integer field, profile_task_input as a string field, and the fixed profile
container path. Return that six-field shape without design_value or
design_prompt_input. In app/task_queue.php accept the same shape, skip the
design branch entirely, and retain the existing clone/ultimate snapshot checks.
No other input mode may produce an empty profile snapshot.

- [ ] **Step 4: Verify Pack validation and existing profile safety**

Run: php tests/run.php tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php && php scripts/validate_packs.php

Expected: PASS; the manifest creates a signed clone-only snapshot, while its request schema rejects design, raw paths, prompt text, and client-supplied hashes.

- [ ] **Step 5: Commit**

~~~bash
git add app/pack_registry.php app/task_queue.php packs/tts-gpt-sovits tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php
git commit -m "feat: add GPT-SoVITS Pack contract"
~~~

### Task 2: Implement the isolated offline adapter

**Files:**
- Create: packs/tts-gpt-sovits/service/Dockerfile
- Create: packs/tts-gpt-sovits/service/requirements.txt
- Create: packs/tts-gpt-sovits/service/job.py
- Create: packs/tts-gpt-sovits/service/test_job.py
- Modify: tests/test_tts_gpt_sovits.php

- [ ] **Step 1: Write failing adapter tests**

~~~python
class GptSoVitsJobTest(unittest.TestCase):
    def test_rejects_non_governed_request_fields(self) -> None:
        with self.assertRaisesRegex(RuntimeError, "request_invalid"):
            job.validate_request({"text": "測試", "mode": "design"})
        with self.assertRaisesRegex(RuntimeError, "request_invalid"):
            job.validate_request({"text": "測試", "mode": "clone", "reference_audio_path": "/tmp/a.wav"})

    def test_normalizes_a_staged_copy_only(self) -> None:
        source = self.fixture_wav(seconds=12)
        original = source.read_bytes()
        staged, prompt = job.normalize_reference(source, "已確認逐字稿", self.temporary)
        self.assertEqual(source.read_bytes(), original)
        self.assertGreaterEqual(job.wav_seconds(staged), 3.0)
        self.assertLessEqual(job.wav_seconds(staged), 10.0)
        self.assertTrue(prompt)
~~~

- [ ] **Step 2: Run and verify failure**

Run: python3 -m unittest -v packs/tts-gpt-sovits/service/test_job.py

Expected: FAIL because job.py does not exist.

- [ ] **Step 3: Implement a fixed-model adapter**

Dockerfile checks out source revision d523079fc05d9a8028d6085bffe4a2757c32abb6 during image build, installs pinned requirements, runs the Python unit tests, then makes runtime loading offline:

~~~dockerfile
ENV HF_HUB_OFFLINE=1 TRANSFORMERS_OFFLINE=1 PIP_NO_INDEX=1
~~~

job.py:
1. permits only text, mode, voice_profile_id, prompt_text, language, waveform_preview, and Hub-injected voice_context;
2. accepts clone/ultimate_clone only, validates the snapshot, and checks a regular reference WAV against its SHA-256;
3. copies the mounted profile WAV into the workspace and never changes the profile source;
4. normalizes the copy to a three-to-ten-second reference at a nearby silence boundary around five seconds, rejecting shorter input;
5. requires confirmed prompt_text for ultimate_clone and uses a boundary-safe temporary excerpt if the audio was shortened;
6. loads only the four declared local assets and synthesizes a complete non-streaming WAV with parallel_infer=True, batch_size=1, and text_split_method="cut5";
7. writes generated_audio.wav and synthesis_metadata.json; missing assets, invalid stage, CUDA failure, and inference failure return stable error codes.

Use one injected inference callable in tests. Do not import MyAI, monkey patch libraries, download assets, expose a weight picker, or provide fake production inference.

- [ ] **Step 4: Verify**

Run: python3 -m unittest -v packs/tts-gpt-sovits/service/test_job.py && php scripts/validate_packs.php

Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add packs/tts-gpt-sovits/service/Dockerfile packs/tts-gpt-sovits/service/requirements.txt packs/tts-gpt-sovits/service/job.py packs/tts-gpt-sovits/service/test_job.py
git commit -m "feat: add GPT-SoVITS isolated adapter"
~~~

### Task 3: Add resident execution through the current protocol

**Files:**
- Create: packs/tts-gpt-sovits/service/app.py
- Create: packs/tts-gpt-sovits/service/test_app.py
- Modify: packs/tts-gpt-sovits/service/job.py
- Modify: packs/tts-gpt-sovits/service/Dockerfile
- Modify: tests/test_tts_gpt_sovits.php

- [ ] **Step 1: Write failing resident service tests**

~~~python
class GptSoVitsResidentTest(unittest.TestCase):
    def test_internal_job_requires_secret_and_valid_run(self) -> None:
        self.assertEqual(self.client.post("/internal/jobs", json={"run_id": "resident-a"}).status_code, 403)
        self.assertEqual(self.authorized_post("/internal/jobs", {"run_id": "../../bad"}).status_code, 400)

    def test_capacity_is_cold_then_ready(self) -> None:
        self.assertEqual(self.authorized_get("/internal/capacity").json()["model_state"], "cold")
        self.run_injected_job("resident-a")
        self.assertEqual(self.authorized_get("/internal/capacity").json()["model_state"], "ready")
~~~

- [ ] **Step 2: Run and verify failure**

Run: python3 -m unittest -v packs/tts-gpt-sovits/service/test_app.py

Expected: FAIL because app.py does not exist.

- [ ] **Step 3: Reuse the proven resident boundary**

Implement GET /health, GET /internal/capacity, POST /internal/jobs, GET /internal/jobs/{run_id}, and POST /internal/jobs/{run_id}/cancel. Copy the VoxCPM2 service boundary for secret comparison, run-ID validation, symlink-resistant service-data stage checks, atomic terminal.json, single lock, cancellation, and idle unload. Change only environment names to GPT_SOVITS and call job.py.

One threading.RLock and one active run is the Phase A ceiling. Add this comment at the lock:

~~~python
# ponytail: one resident inference at a time; add a queued service worker only when measured throughput needs it.
~~~

With GPT_SOVITS_IDLE_UNLOAD_SECONDS=0, do not create an idle timer. A positive value unloads only after the terminal state. Do not modify app/pack_job_runner.php: its generic implementation already provides cold/ready/running capacity, 6144 MiB cold preflight, 1024 MiB ready margin, fences, cleanup, cancellation, and Windows WSL transport.

- [ ] **Step 4: Verify**

Run: python3 -m unittest -v packs/tts-gpt-sovits/service/test_app.py packs/tts-gpt-sovits/service/test_job.py && php tests/run.php tests/test_tts_gpt_sovits.php

Expected: PASS; unauthorized/traversal calls fail and cancellation cannot publish a partial output.

- [ ] **Step 5: Commit**

~~~bash
git add packs/tts-gpt-sovits/service tests/test_tts_gpt_sovits.php
git commit -m "feat: add GPT-SoVITS resident worker"
~~~

### Task 4: Treat both voice modes as one Cluster profile family

**Files:**
- Modify: app/cluster_router.php:896-1023,1072-1087,1325-1487,1976,2137-2156,2966-2982
- Modify: tests/test_tts_gpt_sovits.php
- Modify: tests/test_tts_voxcpm2.php

- [ ] **Step 1: Write failing Cluster tests**

~~~php
hub_test('GPT mode is profile-sensitive in Cluster', function (): void {
    hub_test_assert(in_array('voice_generate_gpt_sovits', hub_cluster_voice_profile_modes(), true), 'GPT mode family missing');
    hub_test_assert(hub_cluster_router_rich_artifact_mode('voice_generate_gpt_sovits'), 'GPT artifacts need acknowledgement');
});

hub_test('profile handle can pin either voice Pack to its prepared station', function (): void {
    $result = hub_test_cluster_profile_followup('voice_generate_gpt_sovits', 'voice_generate');
    hub_test_assert(($result['status'] ?? 0) === 200, 'same-station cross-Pack profile use failed');
});
~~~

- [ ] **Step 2: Run and verify failure**

Run: php tests/run.php tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php

Expected: FAIL because Cluster checks voice_generate literally.

- [ ] **Step 3: Replace exact-mode checks with one family helper**

~~~php
function hub_cluster_voice_profile_modes(): array
{
    return ['voice_generate', 'voice_generate_gpt_sovits'];
}

function hub_cluster_is_voice_profile_mode(string $mode): bool
{
    return in_array($mode, hub_cluster_voice_profile_modes(), true);
}

function hub_cluster_router_rich_artifact_mode(?string $mode): bool
{
    return $mode === 'edge_tts' || (is_string($mode) && hub_cluster_is_voice_profile_mode($mode));
}
~~~

Use hub_cluster_is_voice_profile_mode() for contract rewriting, example projection, profile reference lookup, profile request detection, profile-sensitive route admission, profile_prepare role, error relay, and public manifest rewriting. Change hub_cluster_get_voice_profile_route_for_member() to accept the requested mode and query mode IN both allowed voice modes instead of a fixed mode. The selected station must still publish the requested mode before the router forwards it, so a profile is never sent to a station without that Pack.

- [ ] **Step 4: Verify Cluster compatibility**

Run: php tests/run.php tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php

Expected: PASS; the profile may be prepared once and used for either engine only on its original eligible station.

- [ ] **Step 5: Commit**

~~~bash
git add app/cluster_router.php tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php
git commit -m "feat: route GPT-SoVITS profiles through Cluster"
~~~

### Task 5: Reuse the public profile workflow documentation

**Files:**
- Modify: app/public_api_docs.php:235-587,640-710
- Modify: tests/test_api_examples.php
- Modify: tests/test_tts_gpt_sovits.php

- [ ] **Step 1: Write failing docs tests**

~~~php
hub_test('local and Cluster docs render GPT clone flow', function (): void {
    $local = (string) file_get_contents(HUB_ROOT . '/app/public_api_docs.php');
    hub_test_assert(str_contains($local, 'voice_generate_gpt_sovits'), 'local GPT docs missing');
    $cluster = hub_cluster_public_api_docs_html(hub_test_reset_db());
    hub_test_assert(!str_contains($cluster, 'voice_profile_id'), 'Cluster examples must hide profile IDs');
});
~~~

- [ ] **Step 2: Run and verify failure**

Run: php tests/run.php tests/test_tts_gpt_sovits.php tests/test_api_examples.php

Expected: FAIL because only voice_generate receives the profile workflow.

- [ ] **Step 3: Parameterize only presentation helpers**

Rename the public documentation helpers from voice_generate-specific to voice_profile-specific, accept string $mode, and replace the hard-coded mode in their existing curl/PHP/JS templates. Use the helper when requested_mode is in the two-value profile-mode family. The input table for GPT shows clone and ultimate_clone only; Vox continues to show its three modes. Keep all Cluster redaction/projection behavior.

- [ ] **Step 4: Verify**

Run: php tests/run.php tests/test_tts_gpt_sovits.php tests/test_api_examples.php tests/test_tts_voxcpm2.php

Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add app/public_api_docs.php tests/test_tts_gpt_sovits.php tests/test_api_examples.php
git commit -m "docs: publish GPT-SoVITS clone API"
~~~

### Task 6: Extend the existing real acceptance client

**Files:**
- Modify: scripts/audio_packs_acceptance.php
- Create: docs/operations/gpt-sovits-voxcpm2-pk.md
- Modify: tests/test_audio_packs.php
- Modify: README.md

- [ ] **Step 1: Write failing acceptance test**

~~~php
hub_test('audio acceptance supports GPT without source disclosure', function (): void {
    $script = (string) file_get_contents(HUB_ROOT . '/scripts/audio_packs_acceptance.php');
    foreach (['tts-gpt-sovits', 'voice_generate_gpt_sovits', 'voice_profile_id', 'generated_audio', 'synthesis_metadata'] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'GPT acceptance missing ' . $needle);
    }
    hub_test_assert(!str_contains($script, 'prompt_text='), 'transcript must not be serialized');
});
~~~

- [ ] **Step 2: Run and verify failure**

Run: php tests/run.php tests/test_audio_packs.php

Expected: FAIL because the CLI has no GPT Pack branch.

- [ ] **Step 3: Add one branch to the current command**

~~~php
$mode = $pack === 'tts-gpt-sovits' ? 'voice_generate_gpt_sovits' : 'voice_generate';
$fields = [
    'text' => $config['text'],
    'mode' => $config['clone_mode'],
    'voice_profile_id' => $config['voice_profile_id'],
];
~~~

Require an approved existing profile through configuration. Reuse present polling, artifact hash verification, acknowledgement, ffprobe, and nvidia-smi code. Return JSON with profile ID, task ID, queue and total seconds, output seconds, real-time factor, cold/warm label, GPU MiB, artifact result, and Cluster relay result. Do not upload, echo, persist, log, or commit source audio, transcript, token, or raw results.

The operations guide specifies cold isolated, cold resident, and warm resident runs with the same profile and text. When preflight, and only preflight, says capacity is insufficient, the operator stops VoxCPM2 before GPT:

~~~bash
docker compose -f data/services/voxcpm2-main/docker-compose.generated.yml stop
~~~

The script never stops services, evicts a model, or deletes volumes.

- [ ] **Step 4: Verify**

Run: php tests/run.php tests/test_audio_packs.php tests/test_api_examples.php && php -l scripts/audio_packs_acceptance.php

Expected: PASS.

- [ ] **Step 5: Commit**

~~~bash
git add scripts/audio_packs_acceptance.php docs/operations/gpt-sovits-voxcpm2-pk.md tests/test_audio_packs.php README.md
git commit -m "docs: add GPT-SoVITS comparison smoke"
~~~

### Task 7: Build and measure on the RTX 5060 Ti

**Files:**
- Modify: packs/tts-gpt-sovits/pack.json only if actual paths or capacity disprove the contract.
- Modify: docs/operations/gpt-sovits-voxcpm2-pk.md only to append non-secret measured findings.

- [ ] **Step 1: Run targeted automated checks**

~~~bash
php tests/run.php tests/test_tts_gpt_sovits.php tests/test_tts_voxcpm2.php tests/test_audio_packs.php tests/test_api_examples.php
python3 -m unittest -v packs/tts-gpt-sovits/service/test_job.py packs/tts-gpt-sovits/service/test_app.py
php scripts/validate_packs.php
~~~

Expected: all pass before a model build or live request.

- [ ] **Step 2: Install and prove offline readiness**

Install tts-gpt-sovits as gpt-sovits-main in isolated mode, install declared assets through Model Repository, build/start the service, and verify health says real_inference=true, CUDA available, all required paths present, and no download attempted.

Expected: live catalog publishes voice_generate_gpt_sovits only when health is ready.

- [ ] **Step 3: Exercise real clone and ultimate_clone**

Use a token explicitly permitted for voice_generate_gpt_sovits plus one consent-qualified profile. Verify task completion, generated WAV, metadata, authenticated artifact download, and Cluster relay for clone then confirmed ultimate_clone.

Set resident through settings and restart. If GPU preflight says capacity is insufficient, stop VoxCPM2 using Task 6's documented command, then retry. Do not stop VoxCPM2 automatically or preemptively.

Expected: a second resident task reports ready capacity and idle unload 0 leaves the model loaded.

- [ ] **Step 4: Record fair PK evidence**

Run Task 6 once for VoxCPM2 and once for GPT-SoVITS with identical approved profile/text, separately cold and warm. Keep raw result JSON, profile audio, transcript, and token outside Git.

Expected: each measurement has task/artifact/relay evidence or a stable failure code plus observed VRAM condition.

- [ ] **Step 5: Commit measured tracked changes only**

~~~bash
git add packs/tts-gpt-sovits/pack.json docs/operations/gpt-sovits-voxcpm2-pk.md
git commit -m "docs: record GPT-SoVITS GPU acceptance"
~~~

Skip this commit when neither tracked file changed.

## Plan self-review

- Spec coverage: Task 1 adds explicit mode/local model contract; Task 2 protects staged reference inference; Task 3 reuses the existing resident lifecycle; Tasks 4-5 keep Cluster profile affinity and docs correct; Tasks 6-7 provide a real fair comparison and the explicit Vox stop only when VRAM requires it.
- Skipped deliberately: database work, automatic eviction, a provider abstraction, design, online download, custom checkpoints, streaming, dashboard work, and a Windows-native container runtime. The existing Hub contracts already cover Phase A.
- Checks: every changed non-trivial path has a focused PHP or Python check. Real GPU validation is an operator acceptance step, not normal CI.
