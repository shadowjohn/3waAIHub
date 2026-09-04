# Image Tools Dual Resident GPU Workers

## Goal

Make `image-tools-main` a predictable GPU service: starting the service loads every supported GPU image model once, requests never unload a model just because the next request selects another operation, and the operator can see the package's declared resident VRAM budget.

## Decision

Keep the public `image-tools` API and its loopback port, but generate a three-service compose project:

```text
PHP Gateway -> image-tools-api (public loopback :8000)
                 |- image-tools-upscale (Docker-internal GPU worker)
                 `- image-tools-colorize (Docker-internal GPU worker)
```

- `image-tools-api` validates the existing request contract and proxies internally by operation. It does not load a model.
- `image-tools-upscale` loads all supported Real-ESRGAN aliases at startup and keeps an alias-to-upsampler cache for its lifetime.
- `image-tools-colorize` loads DDColor at startup and keeps it for its lifetime.
- The two GPU workers are separate Python processes. This is required because the DDColor and Real-ESRGAN import stacks cannot safely coexist in one process. Their Docker ports are not published.
- A service restart, explicit stop, or disabled service is the only model-unload boundary. Request handling never calls the cache-release path.

## Settings and VRAM Contract

`image-tools-main` gains these restart-required settings:

| Setting | Default | Meaning |
| --- | --- | --- |
| `IMAGE_TOOLS_EXECUTION_MODE` | `resident_dual` | `resident_dual` starts both GPU workers and preloads their models; `on_demand` preserves the current single-process behavior for constrained hosts. |
| `IMAGE_TOOLS_UPSCALE_RESIDENT_VRAM_MB` | `3072` | Initial reservation for the Real-ESRGAN worker, including image-processing headroom. |
| `IMAGE_TOOLS_COLORIZE_RESIDENT_VRAM_MB` | `1024` | Initial reservation for the DDColor worker, including image-processing headroom. |

The configured total (default 4096 MiB) is the service's admission and UI budget. It is not a claim that every image size has the same peak use. Worker health also reports its loaded model list and PyTorch allocated/reserved memory; host metrics remain the source for actual GPU-process usage. These initial budgets are operational defaults and must be adjusted after a measured warm-start and 4 MP inference benchmark.

Before starting `resident_dual`, the Hub preflight requires the configured total plus the global GPU safety margin to be free. If it is not available, the service stays stopped and reports `resident_vram_insufficient`; it must not evict another resident service or silently fall back to a cold worker.

## Request and Health Flow

1. Service start performs GPU admission, starts the compose project, and waits for both worker `/internal/health` endpoints to report `ready`.
2. The upscale worker preloads every allowlisted Real-ESRGAN alias; the colorize worker preloads DDColor.
3. `image-tools-api` returns ready only when both workers are ready in `resident_dual` mode. A request retains the existing `POST /process/image` contract and response headers.
4. `upscale` requests proxy only to the upscale worker; `colorize` requests proxy only to the colorize worker. The existing inference lock remains per worker, so the two operations can run independently.
5. Service health exposes worker readiness, loaded models, configured per-worker reservation, total reservation, and worker-reported torch memory. No customer image, path, or request payload is exposed.

## Compatibility and Boundaries

- Existing modes, form fields, response headers, size limits, authentication, and API URLs do not change.
- CPU requests remain supported. `on_demand` remains the explicit escape hatch for hosts that cannot reserve the dual GPU budget.
- Async `upscale_task` remains a separate isolated Pack job and does not borrow either resident worker in this phase.
- The Hub records the dual service as a managed resident GPU occupant for display and admission. It does not add automatic eviction, LRU model unloading, a second GPU scheduler, or cross-node routing.

## Failure Handling

- Missing or invalid model assets fail the affected worker at start; the API stays unhealthy with the worker-specific reason.
- A worker crash makes the API unhealthy and returns a stable `runtime_not_ready` response rather than attempting an in-request cold reload.
- Worker-to-worker imports remain isolated. No `sys.modules` cleanup occurs in resident mode.
- GPU admission failure is explicit and leaves the other resident services untouched.

## Verification

- Unit tests: resident role selection, startup preload of every Real-ESRGAN alias and DDColor, no request-time cache eviction, sanitized worker health, and stable error mapping.
- Compose tests: only the API publishes the service port; workers use the internal network and receive GPU access and read-only model mounts.
- Hub tests: settings validation, calculated 4096 MiB default budget, safety-margin preflight, health aggregation, and no service start when the budget is unavailable.
- Runtime acceptance: first upscale and first colorize after a successful start are both warm requests; switching operations does not reload either model; `nvidia-smi`/health budget matches the two resident workers.

## Rollout

Build the new image, rebuild `image-tools-main`, save settings, then start it. On the current VRAM-constrained host, service start is expected to refuse cleanly until the declared 4096 MiB plus safety margin is free; it must not compete destructively with BreezyVoice, Whisper, or other resident services.
