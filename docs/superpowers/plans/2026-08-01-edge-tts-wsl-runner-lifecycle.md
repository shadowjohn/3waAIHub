# Edge TTS WSL Runner Lifecycle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run Edge TTS through a ready Windows WSL Runtime while Marketplace build/start/stop controls manage its runner image and Hub service state.

**Architecture:** Edge TTS stays an `internal_task`, not a long-running HTTP daemon. Only a Pack that explicitly declares `windows-wsl2-linux-docker` and `windows_wsl_job` can use `wsl.exe` and WSL Docker. Marketplace Start provisions its image and verified demos before logical Hub activation; Stop only closes admission.

**Tech Stack:** PHP 8, SQLite, PowerShell, WSL2 Ubuntu, Docker Engine in WSL, Python 3.13 Edge TTS container, Hub artifact registry.

---

## File map

| File | Responsibility |
| --- | --- |
| `packs/edge-tts/pack.json` | Explicit WSL target declaration. |
| `app/docker_runner.php` | Fail-closed WSL runner image build, demo bridge, Marketplace lifecycle. |
| `app/pack_job_runner.php` | Edge WSL job execution and declared-artifact handoff. |
| `scripts/windows/install-wsl-runtime.ps1` | Ext4 runtime source synchronization. |
| `tests/test_edge_tts_pack.php` | Edge manifest, lifecycle, demo, and WSL runner contracts. |
| `tests/test_web_screenshot_pack.php` | Regression coverage for shared WSL runner mechanics. |
| `tests/test_runtime_portability.php` | Explicit target selection and direct `linux-docker` regression. |
| `tests/test_windows_installer.ps1` | WSL source synchronization contract. |
| `README.md`, `packs/edge-tts/README.md` | Operator-facing Windows WSL boundary. |

### Task 1: Declare Edge TTS as an explicit WSL job Pack

**Files:**
- Modify: `packs/edge-tts/pack.json`
- Modify: `tests/test_edge_tts_pack.php`
- Modify: `tests/test_runtime_portability.php`

- [ ] **Step 1: Write the failing target-resolution test**

```php
$manifest = hub_get_pack('edge-tts')['manifest'];
hub_test_assert(!empty($manifest['runtime']['windows_wsl_job']), 'Edge TTS must explicitly opt into WSL jobs');
hub_test_assert(!empty($manifest['platform_targets']['windows-wsl2-linux-docker']['supported']), 'Edge TTS must declare WSL support');
$profile = ['runtime_targets' => ['windows-wsl2-linux-docker' => [
    'supported' => true,
    'distro' => 'Ubuntu-24.04',
    'runtime_root' => '/DATA/3waAIHub-runtime',
]]];
$wsl = hub_pack_runtime_target_resolution($manifest, 'windows', $profile);
$direct = hub_runtime_target_resolution('linux-docker', 'windows', $profile);
hub_test_assert($wsl['target'] === 'windows-wsl2-linux-docker' && $wsl['supported'] === true, 'Edge must select WSL only when declared and ready');
hub_test_assert($direct['supported'] === false && $direct['reason'] === HUB_WINDOWS_LINUX_DOCKER_UNSUPPORTED, 'direct Linux Docker must remain blocked');
```

- [ ] **Step 2: Run the suite and observe failure**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the new Edge assertion fails because the WSL declaration does not yet exist.

- [ ] **Step 3: Add the minimal manifest declaration**

```json
"runtime": {
  "kind": "internal_task",
  "windows_wsl_job": true
},
"platform_targets": {
  "linux-docker": true,
  "windows-wsl2-linux-docker": true
},
```

Do not add `windows_wsl_compose`: Edge TTS has no daemon to keep running.

- [ ] **Step 4: Re-run the control-plane suite**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: Edge WSL declaration passes; record pre-existing failures separately.

- [ ] **Step 5: Commit the Pack contract**

```powershell
git add -- packs/edge-tts/pack.json tests/test_edge_tts_pack.php tests/test_runtime_portability.php
git commit -m "feat: declare Edge TTS WSL job target"
```

### Task 2: Make Marketplace provision declared WSL job runners

**Files:**
- Modify: `app/docker_runner.php`
- Modify: `tests/test_edge_tts_pack.php`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Write failing helper and lifecycle assertions**

```php
$service = hub_install_pack($db, 'edge-tts', [
    'idempotent' => true,
    'provision_runner' => false,
    'initialize_edge_tts_demos' => false,
])['service'];
hub_test_assert(hub_service_declares_wsl_job($service), 'Edge must be recognized as a declared WSL job service');
hub_test_assert(!hub_service_is_wsl_compose_service($service), 'Edge must not be treated as a persistent Compose service');
```

For its build command, decode the WSL payload and assert it contains `/DATA/3waAIHub-runtime/packs/edge-tts/service/Dockerfile`, `3waaihub/edge-tts:0.3.0`, and no normalized `HUB_ROOT` path. Assert `docker pull` throws.

- [ ] **Step 2: Run the suite and observe failure**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the current Web Screenshot-only helper cannot provide Edge provisioning.

- [ ] **Step 3: Introduce the narrow shared build helper**

Add these predicates to `app/docker_runner.php`:

```php
function hub_service_declares_wsl_job(array $service): bool
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    return hub_platform_id() === 'windows'
        && is_array($pack['manifest'] ?? null)
        && !empty($pack['manifest']['runtime']['windows_wsl_job']);
}

function hub_service_is_wsl_compose_service(array $service): bool
{
    $pack = hub_get_pack((string)($service['pack_id'] ?? ''));
    return hub_service_declares_wsl_job($service)
        && is_array($pack['manifest'] ?? null)
        && !empty($pack['manifest']['runtime']['windows_wsl_compose']);
}
```

Extract `hub_wsl_container_runner_build_command(array $service, array $docker, ?array $profile = null): array`. It must derive `image`, `dockerfile`, and `context` with `hub_pack_container_runner_build_contract()` and accept only:

```php
['docker', 'image', 'inspect', '--format', '{{.Id}}', $image]
['docker', 'build', '--tag', $image, '--file', $dockerfile, $context]
```

Build only from `{$runtimeRoot}/packs/{$packId}/service`. Reject malformed Pack ID/runtime data and every other Docker array. Keep `hub_web_screenshot_wsl_runner_build_command()` as a delegating compatibility wrapper if an existing caller needs it.

- [ ] **Step 4: Wire Start and Build before the internal-task no-op**

In `hub_start_service_with_job()`, `hub_build_service()`, and `hub_refresh_service_runtime_files()`, replace the Web Screenshot-only predicate with `hub_service_declares_wsl_job($service)`. Pass these options to `hub_install_pack()`:

```php
$options['runner_build_runner'] = static function (array $docker, int $timeoutSeconds) use ($service): array {
    return hub_run_command(hub_wsl_container_runner_build_command($service, $docker), $timeoutSeconds);
};
if ((string)$service['pack_id'] === 'edge-tts') {
    $options['edge_tts_demo_runner'] = hub_edge_tts_wsl_demo_runner($service);
}
```

Implement `hub_edge_tts_wsl_demo_runner()` to accept only the existing Edge demo `docker run` and `docker container rm` arrays. For a run, convert the generated staging directory with `wslpath -a`, preserve `--network bridge`, `--cap-add NET_ADMIN`, the declared image, and `/app/generate_demos.py`; execute through `hub_wsl_script_command()`. Do not change `hub_stop_service()` or `hub_refresh_service_status()` for internal tasks: they remain logical state changes with no port or Compose container.

- [ ] **Step 5: Verify the lifecycle contracts**

Run:

```powershell
php -l app/docker_runner.php
php -l tests/test_edge_tts_pack.php
php -l tests/test_web_screenshot_pack.php
php scripts/run_tests.php --suite=control-plane
```

Expected: Edge Build/Start obtain their image and demos through WSL; Web Screenshot build remains ext4-only; unsupported Packs still exit 78 before Docker.

- [ ] **Step 6: Commit Marketplace provisioning**

```powershell
git add -- app/docker_runner.php tests/test_edge_tts_pack.php tests/test_web_screenshot_pack.php
git commit -m "feat: provision Edge TTS runner through WSL"
```

### Task 3: Run Edge TTS jobs through WSL with bounded artifact handoff

**Files:**
- Modify: `app/pack_job_runner.php`
- Modify: `tests/test_edge_tts_pack.php`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Write the failing Edge WSL execution-plan test**

Use an isolated Windows workspace containing `input/request.json`, `output/`, and `checkpoints/`; create a ready WSL profile and an Edge `synthesize` context with `include_subtitles=true`.

```php
$plan = hub_edge_tts_wsl_execution_plan($service, $context, $profile);
$payload = hub_test_web_screenshot_wsl_payload($plan['command']);
hub_test_assert(str_contains($payload, '/DATA/3waAIHub-runtime/jobs/edge-tts/packjob-42-abcdef0123456789'), 'Edge task must use an ext4 WSL workspace');
hub_test_assert(str_contains($payload, 'generated_audio.mp3') && str_contains($payload, 'synthesis_metadata.json'), 'Edge task must copy required artifacts');
hub_test_assert(str_contains($payload, 'subtitle.vtt') && str_contains($payload, 'subtitle.srt') && str_contains($payload, 'speech_timeline.json'), 'subtitle request must copy all declared optional artifacts');
hub_test_assert(!str_contains($payload, 'cp -a') && !str_contains($payload, '--gpus'), 'Edge task must be CPU-only and copy only declared names');
```

Add an unready-profile executor assertion that returns `HUB_EXIT_UNSUPPORTED` without calling its process runner.

- [ ] **Step 2: Run the suite and observe failure**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: failure because Edge has no WSL plan/executor.

- [ ] **Step 3: Extract only shared WSL workspace mechanics**

Keep Pack wrappers fail-closed. Add a helper called only after Pack-specific validation. It receives a validated Pack ID/run ID/container command/exact artifact list and generates a WSL script that:

```text
1. converts the Windows workspace with wslpath -a;
2. copies input/request.json to $runtimeRoot/jobs/$packId/$runId/input;
3. mounts only that WSL job directory into Docker;
4. copies only the supplied artifact filenames to Windows output;
5. traps container removal and rm -rf only the validated $runtimeRoot/jobs/$packId/$runId root.
```

Reject invalid run IDs, job roots outside `/DATA/.../jobs/<pack>/`, symlinked input/artifacts, and unexpected Docker arrays. Make `hub_web_screenshot_wsl_execution_plan()` retain its exact Screenshot contract and delegate its workspace operations to this helper.

- [ ] **Step 4: Add Edge’s fixed wrapper and executor selection**

Add `hub_edge_tts_wsl_service_for_task()`, `hub_edge_tts_wsl_execution_plan()`, and `hub_edge_tts_wsl_executor()` beside the Web Screenshot functions. Accept only:

```php
$task['pack_id'] === 'edge-tts'
$task['job'] === 'synthesize'
$runner['image'] === '3waaihub/edge-tts:0.3.0'
$runner['entrypoint'] === ['/app/edge-tts-entrypoint.sh', '/app/synthesize.py']
$runner['accelerator'] === 'cpu'
$runner['network_profile'] === 'public_egress'
```

Always copy `generated_audio.mp3` and `synthesis_metadata.json`; add `subtitle.vtt`, `subtitle.srt`, and `speech_timeline.json` only for `include_subtitles=true`. Select this executor only in the existing Windows WSL branch. Default Linux container execution is unchanged.

- [ ] **Step 5: Verify the task path**

Run:

```powershell
php -l app/pack_job_runner.php
php -l tests/test_edge_tts_pack.php
php scripts/run_tests.php --suite=control-plane
```

Expected: Edge and Web Screenshot WSL plans pass; an unready WSL profile remains an early exit-78 gate.

- [ ] **Step 6: Commit the WSL job runner**

```powershell
git add -- app/pack_job_runner.php tests/test_edge_tts_pack.php tests/test_web_screenshot_pack.php
git commit -m "feat: run Edge TTS jobs through WSL"
```

### Task 4: Synchronize source and document operation

**Files:**
- Modify: `scripts/windows/install-wsl-runtime.ps1`
- Modify: `tests/test_windows_installer.ps1`
- Modify: `README.md`
- Modify: `packs/edge-tts/README.md`

- [ ] **Step 1: Write the failing WSL installer assertion**

```powershell
Assert-InstallerContract ($installWslSource -match 'packs/edge-tts') 'WSL runtime must sync the Edge TTS Pack source'
```

- [ ] **Step 2: Run the installer contract and observe failure**

Run: `pwsh -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1`

Expected: the new Edge source-sync assertion fails.

- [ ] **Step 3: Add Edge TTS to the existing WSL synchronization list**

Add this source/destination pair through the current WSL-safe payload and LF normalization logic:

```powershell
@('packs/edge-tts', "$RuntimeRoot/packs/edge-tts")
```

Do not copy the full Windows checkout or build from `/mnt/d`.

- [ ] **Step 4: Document exact Marketplace semantics**

Add this behavior to both Windows WSL documentation locations:

```text
Edge TTS on Windows uses only its explicit windows-wsl2-linux-docker job target.
Build provisions the WSL image; Start provisions verified demos and enables Hub admission; Stop disables admission.
The Pack remains CPU-only. Direct linux-docker remains unsupported on Windows.
```

- [ ] **Step 5: Run installer and documentation checks**

Run:

```powershell
pwsh -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1
php scripts/run_tests.php --suite=control-plane
```

Expected: installer contract passes and documentation matches actual behavior.

- [ ] **Step 6: Commit source sync and documentation**

```powershell
git add -- scripts/windows/install-wsl-runtime.ps1 tests/test_windows_installer.ps1 README.md packs/edge-tts/README.md
git commit -m "docs: describe Edge TTS WSL runner workflow"
```

### Task 5: Perform bounded Windows real acceptance

**Files:**
- Verify: `data/runtime_profile.json`
- Verify: `data/results/edge-tts-demos/edge-tts-main/current/`
- Verify: `data/results/task_<task_id>/`

- [ ] **Step 1: Verify readiness without installing prerequisites**

```powershell
.\install.ps1 -Mode WslRuntime -Check -InstallRoot 'D:\DATA\3waAIHub' -WslDistro 'Ubuntu-24.04' -LinuxDataRoot '/DATA'
```

Expected: `Status: READY` and `Ready: true`.

- [ ] **Step 2: Use Marketplace Build and Start, then consume only their jobs**

```powershell
Set-Location -LiteralPath 'D:\DATA\3waAIHub'
php '.\scripts\command_worker.php' --limit=5
```

Expected: Edge Build/Start succeed, service is `enabled=1` and `runtime_status=running`, and verified demos are stored under the Hub data root.

- [ ] **Step 3: Submit a real subtitle task and verify stored hashes**

Submit through the authenticated Playground or API:

```text
mode=edge_tts
text=這是 Windows WSL Edge TTS 驗收。
voice=zh-TW-HsiaoChenNeural
include_subtitles=true
```

Poll to success. Verify MP3, metadata, VTT, SRT, and timeline are owned task artifacts; calculate each downloaded SHA-256 and compare it with task artifact metadata before acknowledgement.

- [ ] **Step 4: Verify Stop and Remove safety**

Stop via Marketplace and consume its job; new work must return `runtime_not_ready`. Remove the test service and consume its job; registration must disappear while the WSL image and completed task artifact directory remain.

- [ ] **Step 5: Preserve only evidence, not runtime data**

Record job IDs, task ID, image ID, artifact types, bytes, and SHA-256 values in the handoff. Do not commit `data/`, demo MP3s, task artifacts, Docker images, or acceptance temporary directories.

## Plan self-review

- Spec coverage: Tasks 1-3 cover the explicit target, WSL provisioning, Marketplace lifecycle, demos, task execution, artifact boundary, cleanup, and early unsupported gate. Task 4 covers WSL source and documentation. Task 5 covers real acceptance and retention.
- Scope: No Windows-native runner, daemon, factory, or broad Linux Docker enablement is introduced.
- Type consistency: Task 2 defines `hub_service_declares_wsl_job()`, `hub_service_is_wsl_compose_service()`, `hub_wsl_container_runner_build_command()`, and `hub_edge_tts_wsl_demo_runner()` before Task 3 uses them; Task 3 defines the Edge WSL plan/executor pair before acceptance.
