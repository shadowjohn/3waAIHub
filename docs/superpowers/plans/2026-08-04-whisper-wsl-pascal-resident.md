# Whisper WSL Pascal Resident Queue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the Whisper ASR Pack run its resident `speech_transcribe` queue on an explicitly configured Windows WSL2 runtime with a GTX 1080 CUDA 11.8 image, while preserving the direct Windows `linux-docker` Exit 78 boundary.

**Architecture:** The Pack declares `windows-wsl2-linux-docker` and a Pascal CUDA 11.8 profile. Windows still controls PHP, SQLite, Marketplace and queue state; a narrow WSL path builds the Pack, runs the resident API, and stages per-run inputs and outputs in `$runtime_root/services/asr-main/resident_jobs`. The existing resident HTTP token, status polling, cancellation, artifact and stale-recovery contracts remain the coordinator.

**Tech Stack:** PHP 8.3, SQLite, Windows PowerShell, WSL2 Ubuntu, Docker Compose, NVIDIA CUDA 11.8/cuDNN 8, Python/faster-whisper/CTranslate2.

---

## File map

- `packs/whisper-asr/pack.json`: Declare the WSL target and two immutable WSL image profiles; add the `small` model alias with its VRAM admission value.
- `packs/whisper-asr/service/Dockerfile.pascal-cu118`, `requirements.pascal-cu118.txt`: CUDA 11.8/cuDNN 8 dependency set used only by Pascal.
- `app/docker_runner.php`: Generate the fixed Whisper WSL compose document with GPU and ext4 mounts; leave the generic Taiwan Address WSL compose route intact.
- `app/pack_job_runner.php`: Switch only Whisper WSL resident preparation, output copy and cleanup to WSL ext4 staging; derive required VRAM from the Pack-selected model alias.
- `app/pack_registry.php`: Validate alias-selected VRAM as part of the stored runner config.
- `scripts/windows/install-wsl-runtime.ps1`, `scripts/windows/write-runtime-profile.ps1`: Sync Whisper runtime source and record the selected Whisper profile.
- `tests/test_whisper_asr_async.php`, `tests/test_runtime_portability.php`, `tests/test_windows_installer.ps1`: Lock the metadata, direct-target gate, WSL command and safe staging contracts.
- `docs/operations/whisper-asr-resident.md`, `README.md`: Document the explicit Windows WSL/Pascal scope, first-model warmup and feature limits.

### Task 1: Lock metadata and profile selection tests

**Files:**
- Modify: `tests/test_whisper_asr_async.php`
- Modify: `tests/test_runtime_portability.php`
- Modify: `tests/test_windows_installer.ps1`

- [ ] **Step 1: Write the failing assertions**

```php
hub_test_assert(($manifest['platform_targets']['windows-wsl2-linux-docker']['supported'] ?? false) === true, 'Whisper must declare the explicit WSL target');
hub_test_assert(($manifest['wsl_runtime_profiles']['pascal-cu118']['dockerfile'] ?? '') === 'service/Dockerfile.pascal-cu118', 'Pascal image contract mismatch');
hub_test_assert(($aliases['small']['required_vram_mb'] ?? null) === 2500, 'small model admission mismatch');
```

```powershell
$profile.runtime_targets.'windows-wsl2-linux-docker'.pack_profiles.'whisper-asr' | Should -Be 'pascal-cu118'
```

- [ ] **Step 2: Run the focused tests and verify failure**

Run: `php tests/run.php tests/test_whisper_asr_async.php tests/test_runtime_portability.php`

Expected: the new WSL target/profile assertions fail before implementation.

- [ ] **Step 3: Declare the profile without changing Linux defaults**

```json
"platform_targets": {
  "windows-wsl2-linux-docker": {"supported": true, "support_level": "preview"}
},
"wsl_runtime_profiles": {
  "default": {"id": "default", "dockerfile": "service/Dockerfile", "image": "3waaihub/whisper-asr:0.1.1"},
  "pascal-cu118": {"id": "pascal-cu118", "dockerfile": "service/Dockerfile.pascal-cu118", "image": "3waaihub/whisper-asr:0.1.1-pascal-cu118", "gpu_name_patterns": ["GTX 1050", "GTX 1080"]}
}
```

The `small` alias carries `required_vram_mb: 2500`; `large_v3` keeps `required_vram_mb: 10000`. The caller must never silently replace an explicit `large_v3` request with `small`.

- [ ] **Step 4: Run the focused tests**

Run: `php tests/run.php tests/test_whisper_asr_async.php tests/test_runtime_portability.php`

Expected: PASS.

### Task 2: Add the isolated Pascal CUDA 11.8 image

**Files:**
- Create: `packs/whisper-asr/service/Dockerfile.pascal-cu118`
- Create: `packs/whisper-asr/service/requirements.pascal-cu118.txt`
- Modify: `packs/whisper-asr/service/app.py`
- Modify: `packs/whisper-asr/service/job.py`
- Test: `packs/whisper-asr/service/test_app.py`

- [ ] **Step 1: Write tests for the CUDA probe and model config**

```python
def test_resident_job_accepts_small_wsl_config(self):
    stage = self.stage(model={"model": "small", "label": "small", "required_vram_mb": 2500, "allow_download": True})
    self.assertEqual({"run_id": "asr-run-1", "state": "succeeded"}, app.internal_job_start({"run_id": "asr-run-1"}, self.token))
```

The test must prove that only Pack-produced config can request the small download path and that the existing `large-v3` offline contract still passes.

- [ ] **Step 2: Build the minimum compatible image**

```dockerfile
FROM nvidia/cuda:11.8.0-cudnn8-runtime-ubuntu22.04
COPY service/requirements.pascal-cu118.txt .
RUN python3 -m pip install --no-cache-dir -r requirements.pascal-cu118.txt && python3 -m pip check
COPY service/app.py service/job.py service/offline_paths.py service/subtitle_reflow.py ./
```

Pin the CUDA 11-compatible CTranslate2/faster-whisper pair in the new requirements file. Do not edit `requirements.txt` or `Dockerfile`; they remain the Linux CUDA 12.8 implementation. The Pascal profile exposes baseline transcription and SRT/VTT only; word alignment and diarization must return a stable capability error before resident execution.

- [ ] **Step 3: Run Python unit tests in the profile image**

Run: `wsl.exe -d Ubuntu-24.04 -- docker build --progress=plain -f /DATA/3waAIHub-runtime/packs/whisper-asr/service/Dockerfile.pascal-cu118 /DATA/3waAIHub-runtime/packs/whisper-asr`

Expected: dependency import and Python tests pass without downloading a model during image build.

### Task 3: Add the explicit WSL service lifecycle

**Files:**
- Modify: `app/docker_runner.php`
- Modify: `scripts/windows/install-wsl-runtime.ps1`
- Modify: `scripts/windows/write-runtime-profile.ps1`
- Test: `tests/test_runtime_portability.php`

- [ ] **Step 1: Write the failing WSL compose command test**

```php
$command = hub_wsl_service_compose_command($whisperService, ['up', '-d', '--build']);
hub_test_assert(in_array('powershell.exe', $command, true), 'Whisper WSL lifecycle must use the Windows argv bridge');
hub_test_assert(str_contains(hub_test_decode_wsl_command($command), '/DATA/3waAIHub-runtime/services/asr-main/data:/data/service'), 'resident data must be ext4 mounted');
hub_test_assert(str_contains(hub_test_decode_wsl_command($command), 'gpus: all'), 'Whisper WSL compose must request GPU');
```

- [ ] **Step 2: Implement one fixed Whisper branch**

`hub_wsl_service_compose_command()` dispatches to `hub_whisper_wsl_service_compose_command()` only when `pack_id === 'whisper-asr'`. That function validates the selected profile id and renders:

```yaml
build:
  context: /DATA/3waAIHub-runtime/packs/whisper-asr
  dockerfile: service/Dockerfile.pascal-cu118
gpus: all
volumes:
  - /DATA/models/whisper:/models/whisper
  - /DATA/3waAIHub-runtime/cache/whisper:/cache/whisper
  - /DATA/3waAIHub-runtime/services/asr-main/data:/data/service
```

The normal generic WSL compose function remains responsible for Packs that only need its current context. `install-wsl-runtime.ps1` copies `packs/whisper-asr` with the same LF normalization treatment as shell/Python files and `write-runtime-profile.ps1` records `pack_profiles.whisper-asr` beside `pack_profiles.yolo`.

- [ ] **Step 3: Run focused static tests**

Run: `php tests/run.php tests/test_runtime_portability.php; powershell -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1`

Expected: PASS; `hub_pack_runtime_target_resolution(..., 'windows')` is WSL-only for Whisper, while a Pack without the declaration still resolves to unsupported `linux-docker`.

### Task 4: Route resident data through WSL ext4 safely

**Files:**
- Modify: `app/pack_job_runner.php`
- Test: `tests/test_whisper_asr_async.php`

- [ ] **Step 1: Write failing tests for WSL staging, output, and cleanup**

```php
hub_test_assert(str_contains($script, 'stage_root=/DATA/3waAIHub-runtime/services/asr-main/data/resident_jobs'), 'Whisper WSL stage must be inside ext4 service data');
hub_test_assert(!str_contains($script, 'docker run'), 'resident queue must use the already-running service');
hub_test_assert($result['error_code'] === 'resident_stage_unavailable', 'failed WSL transfer must not dispatch the resident service');
```

- [ ] **Step 2: Implement the narrow WSL stage bridge**

For a supported Whisper WSL resident service only, `hub_pack_job_resident_prepare_stage`, `hub_pack_job_resident_copy_output`, and `hub_pack_job_resident_remove_stage` call an argv-safe WSL script. The script uses `wslpath` only to read the already validated Windows task workspace, copies the three approved input files into `data/resident_jobs/<run_id>/input`, and copies only artifact-contract filenames back out. It rejects links, pre-existing run directories, traversal run ids, missing input files, and output paths outside the validated task workspace.

`hub_pack_job_resident_request()` remains the existing host-loopback HTTP transport, therefore token headers, capacity checks, cancellation, polling and `resident_job_runs` reconciliation keep their current behavior.

- [ ] **Step 3: Implement alias-selected GPU admission**

```php
function hub_pack_job_runner_required_vram(array $runner, ?array $config): int
{
    $value = $config['model']['required_vram_mb'] ?? $runner['required_vram_mb'];
    if (!is_int($value) || $value < 0 || $value > 1_000_000) {
        throw new RuntimeException('job_contract_unavailable');
    }
    return $value;
}
```

Use this helper for GPU acquire, capacity waiting detail, and the runner context. This preserves `large_v3=10000` and admits `small=2500` on an idle 8 GB GTX 1080.

- [ ] **Step 4: Run resident regression tests**

Run: `php tests/run.php tests/test_whisper_asr_async.php tests/test_audio_task_gateway.php`

Expected: PASS, including cancellation, terminal reconciliation and normal Linux resident staging fixtures.

### Task 5: Documentation and real GTX 1080 acceptance

**Files:**
- Modify: `docs/operations/whisper-asr-resident.md`
- Modify: `README.md`

- [ ] **Step 1: Document the exact supported matrix**

```text
Windows + direct linux-docker: unsupported (exit 78)
Windows + WSL2 + GTX 1050/1080: Whisper Pascal CUDA 11.8 preview
Windows Pascal queue: model=small, basic transcript/SRT/VTT
Linux/modern CUDA: existing image and large_v3 contract unchanged
```

State that the first explicit `small` request downloads its model into `/DATA/models/whisper`; no model download happens during Docker image build.

- [ ] **Step 2: Sync source and run static checks**

Run:

```powershell
.\install.ps1 -Mode WslRuntime
php -l app\docker_runner.php
php -l app\pack_job_runner.php
php tests\run.php --suite=control-plane
```

Expected: source copied to `/DATA/3waAIHub-runtime`, lint passes, and the Windows control-plane suite is green.

- [ ] **Step 3: Build, start, and test the resident Pack**

Run Marketplace actions for `asr-main`: Build, Start, Health Check; set `WHISPER_EXECUTION_MODE=resident`, `WHISPER_MODEL=small`, `WHISPER_DEVICE=cuda`, and use an explicit generated internal token. Verify `/health` through Windows `http://127.0.0.1:18107/health` and WSL `docker compose ps`.

- [ ] **Step 4: Submit one real queue job**

Create a managed short WAV source, submit `speech_transcribe` with `model=small`, poll task state through the normal Hub endpoint, and verify `transcript.json` plus `transcription_report.json` are available artifacts. Capture `nvidia-smi` before/during/after to prove the job used the GTX 1080 and released the lease.

- [ ] **Step 5: Verify negative contracts**

Run a Windows direct `linux-docker` Pack action and verify exit `78` with `unsupported: linux-docker target is not available on Windows host`. Submit Pascal `word_timestamps=true` and verify the documented early unsupported capability error, not a runtime trace.

## Self-review

- The direct Linux target remains unmodified and blocked before runtime side effects.
- Linux CUDA 12.8 Dockerfile, dependency pins, large-v3 storage contract and normal resident staging remain unchanged.
- Only the explicit Whisper WSL/Pascal branch reads `/mnt/d`, and only while copying allowlisted task files to or from ext4.
- The plan has an actual build/start/health/queue/artifact/GPU acceptance path and tests for every new branch.
