# WSL Web Screenshot Pack Job Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Let the Windows PHP task worker run only Web Screenshot capture jobs through the ready WSL Docker runtime and return the existing validated artifacts.

**Architecture:** Web Screenshot declares a WSL async-job opt-in and is the sole Pack selected by it. The PHP workspace remains the authority for URL validation and artifact finalization; a narrowly named bridge creates a WSL ext4 workspace, invokes Docker Playwright through the existing wsl.exe transport, copies only declared files back, and cleans the exact WSL path. Native Linux Docker routing remains untouched.

**Tech Stack:** PHP 8+, SQLite task queue, PowerShell wsl.exe, Ubuntu WSL2, Docker Engine, Playwright image.

---

## File structure

- Modify: packs/web-screenshot/pack.json — declares the one explicit WSL async-job target.
- Modify: app/runtime_portability.php — recognizes runtime.windows_wsl_job without changing direct Linux target behavior.
- Modify: app/docker_runner.php — builds/verifies this Pack's controlled runner image through existing WSL command transport during CLI install.
- Modify: app/pack_job_runner.php — executes and cleans the one WSL screenshot job, then delegates artifact validation to the existing finalizer.
- Modify: scripts/windows/install-wsl-runtime.ps1 — syncs the Web Screenshot runner source into WSL ext4.
- Modify: README.md — states that the WSL preview now includes the CPU Web Screenshot vertical slice.
- Modify: tests/test_runtime_portability.php, tests/test_web_screenshot_pack.php, tests/test_windows_installer.ps1 — lock target selection, native-Docker non-use, bridge command shape, and installer source sync.

### Task 1: Explicit WSL target selection

**Files:**

- Modify: packs/web-screenshot/pack.json:13-15
- Modify: app/runtime_portability.php:123-134
- Test: tests/test_runtime_portability.php
- Test: tests/test_web_screenshot_pack.php

- [ ] **Step 1: Write the target-selection tests**

Add a test that uses a ready WSL profile and the actual Web Screenshot manifest. It must prove that the one new flag selects WSL and that an otherwise identical Pack without the flag remains direct-target blocked.

~~~php
$profile = ['runtime_targets' => [
    'windows-wsl2-linux-docker' => [
        'supported' => true,
        'distro' => 'Ubuntu-24.04',
        'runtime_root' => '/DATA/3waAIHub-runtime',
    ],
]];
$manifest = hub_get_pack('web-screenshot')['manifest'];
$wsl = hub_pack_runtime_target_resolution($manifest, 'windows', $profile);
hub_test_assert($wsl['target'] === 'windows-wsl2-linux-docker' && $wsl['supported'] === true,
    'Web Screenshot must select its explicit WSL job target');
$direct = hub_pack_runtime_target_resolution([
    'runtime' => ['kind' => 'internal_task'],
    'platform_targets' => ['linux-docker' => true, 'windows-wsl2-linux-docker' => true],
], 'windows', $profile);
hub_test_assert($direct['target'] === 'linux-docker' && $direct['supported'] === false,
    'an internal Pack without windows_wsl_job must remain blocked');
~~~

- [ ] **Step 2: Run the focused suite and confirm the new assertion fails**

Run: php scripts/run_tests.php --suite=control-plane

Expected: the new Web Screenshot WSL target assertion fails because the manifest has no opt-in.

- [ ] **Step 3: Add the manifest opt-in and minimal resolver condition**

Change the Web Screenshot runtime and targets to:

~~~json
"runtime": {
  "kind": "internal_task",
  "windows_wsl_job": true
},
"platform_targets": {
  "linux-docker": true,
  "windows-wsl2-linux-docker": true
},
~~~

Replace the resolver condition with the explicit two-transport form:

~~~php
$usesWslTransport = !empty($manifest['runtime']['windows_wsl_compose'])
    || !empty($manifest['runtime']['windows_wsl_job']);
if ($platform === 'windows' && $usesWslTransport && $wslDeclared) {
    return hub_runtime_target_resolution('windows-wsl2-linux-docker', $platform, $profile);
}
~~~

- [ ] **Step 4: Run the focused suite and confirm the target contract passes**

Run: php scripts/run_tests.php --suite=control-plane

Expected: exit 0; ordinary Docker Pack Windows tests continue to report the fixed direct linux-docker unsupported contract.

- [ ] **Step 5: Commit the target declaration**

~~~powershell
git add packs/web-screenshot/pack.json app/runtime_portability.php tests/test_runtime_portability.php tests/test_web_screenshot_pack.php
git commit -m "feat: declare WSL web screenshot target"
~~~

### Task 2: WSL-only controlled image provisioning

**Files:**

- Modify: app/docker_runner.php:487-607
- Test: tests/test_web_screenshot_pack.php

- [ ] **Step 1: Write a command-shape test for runner image provisioning**

Add a test that resolves the installed web_capture service against a ready WSL profile and asserts the controlled provisioner emits a PowerShell command containing the selected distro, /DATA/3waAIHub-runtime/packs/web-screenshot/service/Dockerfile, and docker image inspect or docker build; it must not expose a top-level native docker command.

~~~php
$command = hub_web_screenshot_wsl_runner_build_command(
    $service,
    ['docker', 'build', '--tag', '3waaihub/web-screenshot:0.1.2']
);
hub_test_assert(($command[0] ?? '') === 'powershell.exe'
    && str_contains((string)end($command), "-d 'Ubuntu-24.04'")
    && str_contains((string)end($command), '/packs/web-screenshot/service/Dockerfile')
    && !in_array('docker', array_slice($command, 0, 5), true),
    'Web Screenshot image build must be routed through WSL, never native Docker');
~~~

- [ ] **Step 2: Run the focused suite and confirm the helper is absent**

Run: php scripts/run_tests.php --suite=control-plane

Expected: failure for undefined hub_web_screenshot_wsl_runner_build_command.

- [ ] **Step 3: Implement the narrow build command and wire it into CLI service refresh**

Add hub_web_screenshot_wsl_runner_build_command(array $service, array $docker): array in app/docker_runner.php. It must accept only docker image inspect and docker build --tag <declared-image>, resolve the service's ready runtime through hub_wsl_service_runtime(), and return hub_wsl_script_command() for a script using the fixed WSL Pack directory. Reject every other command with InvalidArgumentException.

Use it only for a CLI refresh of the web-screenshot internal service:

~~~php
$environment = json_decode((string)($service['environment_json'] ?? ''), true);
$options = [
    'service_key' => (string)$service['service_key'],
    'name' => (string)$service['name'],
    'mode' => (string)$service['mode'],
    'port_mode' => (string)$service['port_mode'],
    'local_port' => (int)$service['local_port'],
    'environment' => (string)$service['environment'],
    'hot_reload' => (int)$service['hot_reload'] === 1,
    'env' => is_array($environment) ? $environment : [],
    'idempotent' => true,
];
if ((string)$service['pack_id'] === 'web-screenshot' && hub_service_uses_wsl_runtime($service)) {
    $options['runner_build_runner'] = static function (array $docker, int $timeout) use ($service): array {
        return hub_run_command(hub_web_screenshot_wsl_runner_build_command($service, $docker), $timeout);
    };
}
hub_install_pack($db, (string)$service['pack_id'], $options);
~~~

Do not alter hub_pack_provision_container_runner_image() or its Linux command runner; the callback is the existing extension point.

- [ ] **Step 4: Run the focused suite and confirm no HTTP request invokes Docker**

Run: php scripts/run_tests.php --suite=control-plane

Expected: exit 0; the existing marketplace test remains proof that installation is queued rather than run by PHP-FPM.

- [ ] **Step 5: Commit WSL image provisioning**

~~~powershell
git add app/docker_runner.php tests/test_web_screenshot_pack.php
git commit -m "feat: provision web screenshot runner in WSL"
~~~

### Task 3: WSL ext4 screenshot executor and artifact return

**Files:**

- Modify: app/pack_job_runner.php:857-1145,1393-1600
- Test: tests/test_web_screenshot_pack.php

- [ ] **Step 1: Write failing executor-plan tests**

Create a ready WSL service and a canonical temporary workspace containing input/request.json, then assert the produced WSL execution command contains the controlled image, --network bridge, --cap-add NET_ADMIN, the WSL ext4 job path, and only the three declared output names. Assert an unready profile returns exit 78 before the test command runner is called.

~~~php
$plan = hub_web_screenshot_wsl_execution_plan($service, $context);
$script = (string)end($plan['command']);
hub_test_assert(str_contains($script, '--network bridge') && str_contains($script, '--cap-add NET_ADMIN')
    && str_contains($script, '3waaihub/web-screenshot:0.1.2')
    && str_contains($script, 'screenshot.png') && str_contains($script, 'capture_report.json')
    && !str_contains($script, 'cp -a'),
    'WSL capture must retain the Playwright firewall and copy only declared artifacts');
~~~

- [ ] **Step 2: Run the focused suite and confirm the executor plan is absent**

Run: php scripts/run_tests.php --suite=control-plane

Expected: failure for undefined hub_web_screenshot_wsl_execution_plan.

- [ ] **Step 3: Implement the smallest WSL bridge**

In app/pack_job_runner.php:

1. Extract the polling proc_open loop from hub_pack_job_default_process_runner() into hub_pack_job_process_runner(); leave the direct Linux Docker exit-78 guard in the existing default wrapper.
2. Add hub_web_screenshot_wsl_execution_plan(array $service, array $context): array. Validate the fixed Pack/job/CPU/public-egress runner contract, use hub_wsl_service_runtime($service), derive <runtime_root>/jobs/web-screenshot/<run_id>, and return a hub_wsl_script_command() script that follows this exact shell structure:

~~~sh
set -eu
host_workspace="$(wslpath -a "$windows_workspace")"
job_root="$runtime_root/jobs/web-screenshot/$run_id"
install -d -m 0700 "$job_root/input" "$job_root/output" "$job_root/checkpoints"
cp -- "$host_workspace/input/request.json" "$job_root/input/request.json"
docker run --pull=never --network bridge --cap-add NET_ADMIN \
  --mount "type=bind,src=$job_root/output,dst=/workspace/output" \
  --mount "type=bind,src=$job_root/checkpoints,dst=/workspace/checkpoints" \
  --mount "type=bind,src=$job_root/input/request.json,dst=/workspace/input/request.json" \
  --name "$container_name" --entrypoint /app/capture-entrypoint.sh \
  3waaihub/web-screenshot:0.1.2 /app/capture
~~~

3. After a successful run, copy regular files only: required screenshot.png and capture_report.json, plus crop.png only when present. Remove the exact WSL job directory only after stop/remove and transfer. The existing PHP finalizer remains responsible for MIME, image, JSON, and artifact registration checks.
4. Add hub_web_screenshot_wsl_executor() and select it inside hub_run_pack_job_task() only when the stored task is web-screenshot/capture and its installed service resolves to ready WSL. All other container jobs keep hub_pack_job_default_executor() unchanged.
5. On WSL unready, use hub_unsupported_runtime_result('windows-wsl2-linux-docker', ...) before command construction or Docker invocation. Cleanup must use the same WSL command transport for inspect/stop/remove and then delete only the prevalidated run directory.

- [ ] **Step 4: Run focused tests and the complete control-plane suite**

Run: php scripts/run_tests.php --suite=control-plane

Expected: exit 0, with Windows-only runtime tests visibly reported as skips where already specified. Confirm that direct Linux Docker tests still require exit 78 on Windows.

- [ ] **Step 5: Commit the job vertical slice**

~~~powershell
git add app/pack_job_runner.php tests/test_web_screenshot_pack.php
git commit -m "feat: run web screenshot jobs through WSL"
~~~

### Task 4: WSL source sync, documentation, and real-host acceptance

**Files:**

- Modify: scripts/windows/install-wsl-runtime.ps1:89-114
- Modify: tests/test_windows_installer.ps1:117-129
- Modify: README.md

- [ ] **Step 1: Write installer and documentation assertions**

Extend the installer contract test and README checks:

~~~powershell
Assert-InstallerContract ($installWslSource -match 'packs/web-screenshot') 'WSL runtime must sync the Web Screenshot Pack source'
~~~

~~~php
hub_test_assert(str_contains($readme, 'Web Screenshot') && str_contains($readme, 'WSL'),
    'README must state the explicit WSL Web Screenshot preview slice');
~~~

- [ ] **Step 2: Run the Windows installer contract and confirm it fails before the sync change**

Run: pwsh -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1

Expected: the new packs/web-screenshot assertion fails.

- [ ] **Step 3: Sync only this Pack and document the boundary**

Extend the existing sync command with "$runtime_root/packs/web-screenshot" and:

~~~sh
cp -a "$source_root/packs/web-screenshot/." "$runtime_root/packs/web-screenshot/"
~~~

Update the README Windows WSL section to state: YOLO Local Job and Web Screenshot CPU Pack Job are the two explicit WSL preview slices; all other Linux Docker Packs remain unavailable on a Windows host unless they declare and implement their own WSL route.

- [ ] **Step 4: Run regression checks**

Run:

~~~powershell
php -l app/runtime_portability.php
php -l app/docker_runner.php
php -l app/pack_job_runner.php
pwsh -NoProfile -ExecutionPolicy Bypass -File tests/test_windows_installer.ps1
php scripts/run_tests.php --suite=control-plane
git diff --check
~~~

Expected: every command exits 0; the runner prints suite=control-plane ... failures=0.

- [ ] **Step 5: Execute the real Windows WSL acceptance**

1. Run ./install.ps1 -Mode WslRuntime to synchronize the Pack source and refresh the runtime profile.
2. Install/enable Web Screenshot from Marketplace, then run php scripts/command_worker.php --limit=5 until its service_install command succeeds.
3. Submit https://3wa.tw/ through admin/playground.php?mode=web_capture and run php scripts/task_worker.php --limit=1.
4. Verify the task becomes success, has a capture_report.json artifact, and serves a valid PNG screenshot through the normal task result route.
5. Confirm the WSL job directory for that run no longer exists and direct linux-docker Pack actions on Windows still emit the fixed exit-78 message.

- [ ] **Step 6: Commit source sync and docs**

~~~powershell
git add scripts/windows/install-wsl-runtime.ps1 tests/test_windows_installer.ps1 README.md
git commit -m "docs: document WSL web screenshot preview"
~~~
