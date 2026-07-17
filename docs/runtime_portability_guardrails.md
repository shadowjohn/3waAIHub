# Runtime Portability Guardrails

PhaseRuntime-0 is a guardrail, not a Windows port.

3waAIHub keeps Linux as the current default runtime host, but new runtime code must keep host paths, container paths, scheduler assumptions, metrics assumptions, and platform targets separated.

## Rules

- `/DATA` may be a Linux default setting. Runtime logic must not hard-code `/DATA/...`; use storage settings or helpers.
- `/proc` belongs only in Linux metrics code.
- cron belongs only in Linux scheduler or install scripts.
- UID/GID handling belongs only in Linux Docker mount preparation.
- Container contract paths are POSIX paths such as `/models/yolo`, `/input`, and `/output/artifacts`.
- Host paths and container paths are not the same thing.

## Helpers

- `hub_platform_id()` returns `linux`, `windows`, `darwin`, or `unknown`.
- `hub_is_host_absolute_path()` detects Linux absolute paths, Windows drive paths, and UNC paths.
- `hub_normalize_host_path()` normalizes host path separators without mapping to containers.
- `hub_container_path()` validates canonical container POSIX paths and rejects traversal.
- `hub_platform_target_supported()` reports whether the current host can run a target.

## Pack Manifest

Pack manifests may declare `platform_targets`.

```json
{
  "platform_targets": {
    "linux-docker": true,
    "remote-agent": {
      "supported": true,
      "reason": "Executed by a remote Linux station"
    }
  }
}
```

Loaded manifests are normalized once:

```php
[
    'platform_targets' => [
        'linux-docker' => [
            'supported' => true,
            'source' => 'legacy_inferred',
            'reason' => null,
        ],
    ],
]
```

If an old Docker pack does not declare `platform_targets`, `runtime.kind=docker` is inferred as `linux-docker` with `source=legacy_inferred`.

## Current Support

Only local Linux + Docker is implemented now.

Windows must return an explicit unsupported result for local `linux-docker`:

```json
{
  "platform": "windows",
  "target": "linux-docker",
  "supported": false,
  "reason": "linux-docker target is not available on Windows host"
}
```

Windows Control Plane can later submit work to a Linux Remote Agent. PhaseRuntime-0 does not implement that agent.

## Principle

新增十個 Pack，不如先保證一個 Job 跑一千次都不會莫名其妙。

