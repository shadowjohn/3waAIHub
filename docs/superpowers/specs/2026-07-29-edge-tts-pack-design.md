# Edge TTS Pack Design

Date: 2026-07-29

Status: approved design; implementation not started

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

V1 publishes a small fixed allowlist: `zh-TW-HsiaoChenNeural`,
`zh-TW-HsiaoYuNeural`, `zh-TW-YunJheNeural`,
`en-US-EmmaMultilingualNeural`, and `en-US-AndrewMultilingualNeural`.
Clients cannot supply SSML, an endpoint, a proxy, an upload, a host path, a
command, or an arbitrary voice identifier.

Each successful task publishes:

- `generated_audio`: required `generated_audio.mp3`, `audio/mpeg`.
- `synthesis_metadata`: required JSON recording the Pack/client version,
  selected voice, rate, volume, pitch, final format, byte count, elapsed
  time, and bounded non-secret warnings.

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

## Errors and Privacy

The runner maps DNS, TLS, websocket, timeout, no-audio, and upstream failures
to fixed public errors: `upstream_unavailable`, `edge_tts_timeout`,
`edge_tts_failed`, and `artifact_write_failed`. It does not return upstream
response bodies, headers, or submitted text.

The submitted text is sent to Microsoft's online service and remains subject
to existing Hub task retention. The Pack README and root README state that it
must not be used for confidential text. There is no automatic alternate
provider, custom retry policy, streaming, or voice cloning in V1.

## Deliberate V1 Boundary and Subtitle Path

V1 produces MP3 only. The upstream client already receives sentence and word
boundary events, but it does not publish a subtitle artifact yet.

V2 can add an optional `include_subtitles` boolean and an additive
`subtitle_vtt` artifact. The existing request and MP3 artifact names remain
unchanged, so clients that only consume audio need no migration. V2 will add
its own output validation and tests when a consumer needs timed captions.

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
