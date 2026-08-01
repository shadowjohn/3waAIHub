# Windows WSL Web Screenshot Pack Job Design

## Goal

On a Windows Control Plane host with a ready `windows-wsl2-linux-docker` profile, execute only the `web-screenshot` Pack's `capture` job through WSL Docker, then publish its existing screenshot artifacts through the normal PHP task flow.

The first real-host acceptance URL is `https://3wa.tw/`. It must already be present in `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS`; the existing allowlist and container-local egress firewall remain unchanged.

## Scope

This is one explicit vertical slice:

`PHP task worker -> wsl.exe -> Docker Playwright -> verified artifact -> existing task result`

It applies only when all of these are true:

- host platform is Windows;
- Pack is `web-screenshot`, job is `capture`, and runner is its current CPU container contract;
- the Pack explicitly declares `windows-wsl2-linux-docker` support for an async WSL job; and
- the local runtime profile says the selected WSL runtime is ready.

The direct `linux-docker` target stays unavailable on Windows and continues to return the existing exit-78 contract before Docker side effects. No other Pack gains WSL job support from this work.

## Chosen Design

### Pack and runtime declaration

`packs/web-screenshot/pack.json` will explicitly declare the WSL target and a `windows_wsl_job` runtime opt-in. The runtime-resolution helper will recognize that one opt-in in addition to the existing compose opt-in. The names stay distinct: this Pack runs an async job, not a Compose service.

### Image lifecycle

The existing Marketplace flow queues `service_install` for the CLI command worker. For this one internal Pack, that command worker will build or verify `3waaihub/web-screenshot:0.1.2` inside the configured WSL runtime before enabling the service. The web request continues to enqueue only; it never invokes Docker.

`install.ps1 -Mode WslRuntime` will synchronize `packs/web-screenshot` into `/DATA/3waAIHub-runtime/packs/web-screenshot` so the build uses WSL ext4 sources. It does not build every Pack image and does not change the readiness-only meaning of `-Check`.

### Job execution and storage boundary

PHP continues to create the canonical Windows task workspace and validate the URL before any WSL invocation. The WSL caller then:

1. creates an exact run directory below `<runtime_root>/jobs/web-screenshot/` on WSL ext4;
2. copies only `input/request.json` into that directory;
3. runs the existing Playwright image with the same `bridge` network and `NET_ADMIN` capability required by its in-container fail-closed egress firewall;
4. copies only the declared regular artifact names (`screenshot.png`, optional `crop.png`, `capture_report.json`) back to the canonical Windows output directory; and
5. removes the exact WSL run directory after the container has been stopped and removed.

The PHP finalizer remains the authority that verifies and registers artifacts. Symlinks, unknown output paths, and failed copy/cleanup are failures, not successful artifacts.

### Process and cancellation behavior

The WSL caller reuses the existing `hub_wsl_script_command()` PowerShell-safe transport. It polls the existing task lease/cancellation callback, and its cleanup uses WSL Docker inspect/stop/remove for the known container name. A cancelled, timed-out, or failed execution removes its WSL run directory and does not publish partial output.

There is no Windows-native Playwright fallback and no generic WSL Pack-job adapter or runtime factory.

## Error Contract

- WSL not ready or the Pack has no explicit WSL opt-in: `platform_target_unsupported`, exit 78, with no Docker invocation.
- WSL source/image/build/run failure: existing Pack-job failed state with a stable runner error code where available.
- Artifact validation or transfer failure: existing Pack-job failure; no artifact is registered.
- Direct `linux-docker` on Windows: unchanged fixed message, `unsupported: linux-docker target is not available on Windows host`.

## Verification

Automated coverage will prove:

- Web Screenshot alone selects the explicit WSL target; ordinary Docker Packs remain direct-target blocked on Windows.
- Install/build commands are sent through `wsl.exe` and do not call native `docker` on Windows.
- The WSL run script retains `--network bridge`, `--cap-add NET_ADMIN`, the controlled image, and only declared artifact copy paths.
- WSL unready paths fail before any Docker/WSL run side effect.
- Linux behavior and current Web Screenshot contracts remain unchanged.

Real-host acceptance will run the Windows task worker against `https://3wa.tw/`, then verify a successful task, `capture_report.json`, and a valid PNG artifact. This CPU Pack does not depend on the GTX 1080 Ti or CUDA profile.

## Non-goals

- generic WSL support for every async Pack job;
- Windows-native Playwright, Docker Desktop inference, or an alternate firewall model;
- GPU Pack enablement, Whisper, BiRefNet, or any change to CUDA 11.8 policy;
- remote-agent routing.
