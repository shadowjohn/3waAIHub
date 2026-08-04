# Edge TTS WSL Runner Lifecycle Design

## Goal

Enable the existing `edge-tts` async Pack on a Windows host with a ready WSL Runtime. The Pack must retain its normal Linux behavior and must not make Windows direct `linux-docker` runnable.

## Chosen approach

Edge TTS remains an async container runner, as it is today. Marketplace actions manage the runner image and Hub service state; they do not invent a long-running HTTP container.

| Marketplace action | Windows WSL behavior |
| --- | --- |
| Install / Build | Build the declared Edge TTS image inside the configured WSL distro. |
| Start | Verify the image, generate and verify Edge voice demos through WSL Docker, then enable the Hub service. |
| Stop | Disable the Hub service and stop admitting new jobs. No persistent container is expected. |
| Remove | Remove only the Hub registration and generated runtime files; keep models, images, and artifacts. |

Each `edge_tts` task runs through the existing job flow:

`Windows PHP task worker -> wsl.exe -> WSL Docker runner -> verified artifacts in Hub storage`

The container-local, fail-closed Edge egress firewall remains unchanged.

personal


## Runtime declaration and boundaries

`packs/edge-tts/pack.json` will explicitly declare both `linux-docker` and `windows-wsl2-linux-docker`, and mark its internal task runner as `windows_wsl_job`.

Only Packs with this explicit declaration may use the WSL job path. Docker CLI availability alone never changes direct `linux-docker` capability. Unmodified Packs on Windows continue to return exit 78 with `error_code=platform_target_unsupported` before Docker side effects.

The existing Web Screenshot-specific WSL runner build and job checks will be generalized only enough to support a second declared Pack. No runtime adapter factory or generic host shell layer is introduced.

## WSL source and demo handoff

`install.ps1 -Mode WslRuntime` will synchronize `packs/edge-tts` to `/DATA/3waAIHub-runtime/packs/edge-tts` on the WSL ext4 filesystem.

The runner image builds from that ext4 source. Edge TTS demo generation uses the same WSL Docker image and writes its short verified demo artifacts into the existing Windows Hub data directory, because PHP serves those owned files. This bounded handoff is not a training workspace or model cache mount.

## Error handling

An unready or malformed WSL profile returns the existing unsupported runtime contract before any Docker command. WSL build, demo, upstream speech, artifact, and cleanup failures keep their existing bounded error contracts and leave the service disabled or the task failed as applicable.

## Acceptance

1. `install.ps1 -Mode WslRuntime -Check` remains readiness-only and reports ready on the configured host.
2. Marketplace install/build/start for `edge-tts` succeeds through WSL Docker without using Windows Docker CLI.
3. `POST api.php?mode=edge_tts` with `include_subtitles=true` completes a real async task and returns verified MP3, metadata, VTT, SRT, and timeline artifacts.
4. Artifact SHA-256 values and declared artifact contracts validate before publication.
5. Marketplace stop blocks new Edge TTS work; remove succeeds without deleting Docker images or retained artifacts.
6. Linux full behavior remains unchanged; Windows control-plane and WSL contract tests cover the explicit target selection and no-Docker-before-gate behavior.

## Out of scope

- A long-running Edge TTS HTTP service or a new adapter daemon.
- Windows native Edge TTS execution.
- Broadly enabling Linux Docker Packs on Windows.
- CUDA or GPU support; Edge TTS stays CPU-only.
