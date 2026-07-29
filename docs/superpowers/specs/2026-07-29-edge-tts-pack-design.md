# Edge TTS Pack Design

Date: 2026-07-29

Status: approved; L2--L4b complete; L5 design approved, implementation pending

## Scope

Add one optional external text-to-speech Pack:

```text
edge_tts -> edge-tts / synthesize / job / cpu
```

`edge_tts` is independent from the local `tts` / VoxCPM2 capability. It is
an asynchronous internal task with CPU-only concurrency one. It uses
Microsoft Edge's online speech service through the pinned Python `edge-tts`
client; it is not local inference, does not use a GPU, and is not a fallback
for VoxCPM2.

The Pack is installed and enabled only through the existing administrator
service controls. No new table, API key, token storage, or settings page is
added. A customer Token still needs its existing mode permission for
`edge_tts`. A child node publishes the mode through the existing Cluster
manifest only while the Pack is installed, enabled, and fresh.

## Contract

`POST api.php?mode=edge_tts` accepts JSON or form input and returns the
standard async task response. The Cluster exposes the same contract through
`cluster_api.php?mode=edge_tts`.

| Field | Rule | Default |
| --- | --- | --- |
| `text` | required UTF-8 string, 1--4096 bytes | -- |
| `voice` | selected safe voice identifier | `zh-TW-HsiaoChenNeural` |
| `rate` | signed percentage | `+0%` |
| `volume` | signed percentage | `+0%` |
| `pitch` | signed Hz value | `+0Hz` |
| `include_subtitles` | optional boolean; when `true`, publish all three caption artifacts below | `false` |

V1 publishes a small fixed allowlist: `zh-TW-HsiaoChenNeural`,
`zh-TW-HsiaoYuNeural`, `zh-TW-YunJheNeural`,
`en-US-EmmaMultilingualNeural`, and `en-US-AndrewMultilingualNeural`.
Clients cannot supply SSML, an endpoint, a proxy, an upload, a host path, a
command, or an arbitrary voice identifier.

Each successful task retains these base artifacts:

- `generated_audio`: required `generated_audio.mp3`, `audio/mpeg`.
- `synthesis_metadata`: required JSON recording the Pack/client version,
  selected voice, rate, volume, pitch, final format, byte count, elapsed
  time, and bounded non-secret warnings.

When `include_subtitles` is `true`, the task also publishes all three
conditional caption artifacts:

- `subtitle_vtt`: `subtitle.vtt`.
- `subtitle_srt`: `subtitle.srt`.
- `speech_timeline`: `speech_timeline.json`.

The task stays within the existing artifact, ownership, callback, retention,
cancellation, and task-result contract. It acquires no GPU lease.

## Runtime and Egress Boundary

The Pack is a one-shot Python container with a pinned `edge-tts` version.
It receives only the Hub-created request JSON and writes only its output
directory. It has no Hub secret, Docker socket, host filesystem mount, GPU,
proxy input, or runtime voice-list request.

It reuses the existing `public_egress` container launch profile because that
profile provides the network namespace and `NET_ADMIN` needed for a
container-local firewall. The manifest validator permits this profile only
for the immutable `edge-tts/synthesize` job in addition to the existing Web
Screenshot exception.

Before running the client, the Pack entrypoint fails closed:

1. It enables an egress-drop firewall.
2. It permits local Docker DNS only long enough to resolve the fixed
   `speech.platform.bing.com` hostname.
3. It permits only the resolved public IPv4 addresses on TCP 443.
4. It removes the DNS allowance before synthesis and runs the worker as an
   unprivileged user.

No host firewall or `nat.sh` rule is changed automatically. If the host
blocks Docker egress, the task reports `upstream_unavailable`. The
container never retries through a different provider and never silently
returns generated content after a failed upstream request.

## Promotion Ladder

Edge TTS is a stateless external-provider job. It must not add a fake model
or storage mount merely to claim a level that does not apply.

| Level | Edge TTS acceptance |
| --- | --- |
| L2 | The pinned container runner, request/output contracts, CPU queue, and fail-closed fixed-provider egress self-check pass. |
| L3 | Not applicable: the Pack has no model or persistent storage mount. `storage.mounts` remains empty. |
| L4a | The Pack installs, is enabled, and its offline runner/egress checks pass on the target station. |
| L4b | A controlled real smoke completes through the normal public API and task worker, publishes a valid MP3 and metadata, acknowledges the artifact, and proves no GPU lease. Completed on the target station on 2026-07-29. |
| L5 | A declared async contract and an explicit station-only `async_complete` benchmark repeat the L4b path and persist only redacted acceptance facts. Marketplace L5 readiness may pass only after that benchmark passes. |

The existing generic `async_submit` benchmark is insufficient for L5: it
only verifies queue submission and then cancels the task. The Edge TTS L5
case must wait for the actual task terminal state and validate the registered
`generated_audio` and `synthesis_metadata` artifacts. It must be opt-in and
never part of the ordinary offline test suite, because it calls the external
provider.

## L5 Station Acceptance Design

L5 adds one station-only command:

```text
php scripts/edge_tts_acceptance.php
```

It receives the same-station API base URL and a caller-held Hub API Token only
from `AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL` and
`AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN`. The Token needs only `edge_tts`,
`task_status`, `task_result`, `artifact`, and `task_artifacts_ack`. It submits
one fixed, short, non-confidential Chinese request with
`include_subtitles=true` through `api.php?mode=edge_tts`. It polls the normal
public task API; it never calls a Pack runner or task worker directly.

On success, it requires exactly these registered artifact types:

- `generated_audio`
- `synthesis_metadata`
- `subtitle_vtt`
- `subtitle_srt`
- `speech_timeline`

The command downloads each artifact through the ordinary artifact API into a
private temporary directory, verifies its declared size and SHA-256, validates
MP3 with `ffprobe`, validates VTT/SRT syntax, validates the required JSON
keys, and acknowledges every artifact. It then reads only this local task's
runtime record to require `accelerator=cpu`, no GPU indexes, zero GPU metrics,
no owned GPU PIDs, and no GPU resource lease. It removes its temporary files
on every completion path.

The command records the result with the existing `benchmark_runs` storage as
`edge_tts_async_complete`. Its saved result contains only boolean acceptance
checks, artifact type/mime/byte-count summaries, acknowledgement count, and
elapsed time. It never records the submitted text, Hub Token, base URL, task
or artifact IDs, artifact URLs, SHA-256 values, audio, captions, or provider
responses. A failure stores only one bounded local code:
`acceptance_submission_failed`, `acceptance_task_failed`,
`acceptance_timeout`, `acceptance_artifact_invalid`, or
`acceptance_runtime_invalid`.

`pack.json` declares the public async `l5_contract`, sets
`runtime_level` and `target_level` to `L5-benchmark-ready`, and declares
`edge_tts_async_complete` as a real-inference, station-only benchmark case.
The existing Pack Readiness calculation therefore stays unchanged: its real
benchmark check becomes green only after a recorded passing acceptance run.
The generic `scripts/benchmark.php` remains offline and must reject or leave
this case unexecuted rather than calling the external provider.

Cluster behavior remains unchanged. The existing Cluster manifest may publish
`edge_tts` when the Pack is installed, enabled, and fresh; L5 adds readiness
evidence only. Cluster callers still require their own allowed `edge_tts` and
task/artifact Token modes.

The implementation adds focused offline coverage for the L5 manifest contract,
redacted benchmark-result shape, and acceptance validation using fake HTTP and
runtime-record inputs. No real provider call runs in CI or the ordinary full
test suite.

## Errors and Privacy

The runner maps DNS, TLS, websocket, timeout, no-audio, and upstream failures
to fixed public errors: `upstream_unavailable`, `edge_tts_timeout`,
`edge_tts_failed`, and `artifact_write_failed`. It does not return upstream
response bodies, headers, or submitted text.

The submitted text is sent to Microsoft's online service and remains subject
to existing Hub task retention. The Pack README and root README state that it
must not be used for confidential text. There is no automatic alternate
provider, custom retry policy, streaming, or voice cloning in V1.

## Captions and Speech Timeline

The shipped captions contract has one optional request field:

```json
{"include_subtitles": true}
```

It defaults to `false`. When false, the base response and artifacts are
unchanged. When true, the runner consumes the one upstream boundary stream
used for synthesis and publishes the additional owned artifacts listed above:

| Type | Path | MIME type | Maximum size |
| --- | --- | --- | --- |
| `subtitle_vtt` | `subtitle.vtt` | `text/plain` on this station; allowed portable values include `text/vtt` | 512 KiB |
| `subtitle_srt` | `subtitle.srt` | `text/plain` on this station; allowed portable values include `application/x-subrip`, `text/x-subrip`, and `text/srt` | 512 KiB |
| `speech_timeline` | `speech_timeline.json` | `application/json` | 512 KiB |

The provider `WordBoundary` stream is the authoritative timeline. The runner
uses milliseconds and derives ordered sentence and word entries locally as
`{text, start_ms, end_ms}`. Its timestamps must be non-negative and monotonic;
entries are bounded by the timeline's own maximum end, which supplies
`duration_ms` from provider timing coverage. The runner does not independently
parse MP3 duration or compare timestamps against it. VTT and SRT are derived
from the same events; the Pack never makes a second provider request to
generate captions.

Caption artifacts contain the submitted text. They therefore use the normal
owned-artifact retention and acknowledgement path, are never copied into
metadata or task logs, and require the caller's explicit opt-in. Clients
cannot select individual subtitle formats, names, paths, providers, or an
arbitrary event payload. One boolean always publishes all three formats.

Offline runner tests cover exact derived output, opt-out absence, and invalid
timing rejection, alongside manifest, Gateway, Cluster, and artifact-contract
coverage. The implementation does not add a streaming endpoint, SSML, batch
jobs, voice cloning, a player UI, or a second synthesis API. L5 promotion
remains a separate station-only real acceptance step.

## Acceptance

1. Manifest, catalog, and route tests prove the immutable
   `edge_tts/edge-tts/synthesize/job/cpu` route and zero GPU requirement.
2. Gateway and Cluster tests require `edge_tts` Token permission, reject
   unknown fields and invalid text, voice, rate, volume, or pitch values, and
   preserve standard task ownership.
3. Runner tests verify the fixed image, read-only input/output boundaries,
   no GPU flag, `public_egress` launch, and the exact upstream hostname
   firewall policy.
4. Container self-checks prove that unrelated outbound traffic and a forced
   firewall setup failure cannot synthesize audio.
5. A manual real smoke submits short Chinese text, completes one task,
   downloads a valid MP3 artifact, confirms no GPU lease, and records the
   external-service limitation. This smoke is never part of the ordinary
   offline test suite.

New public PHP files use mode `0755`; Pack Python, shell, JSON, CSS, and
documentation follow the repository's existing file modes.
