# Web Screenshot Pack Design

Date: 2026-07-28

Status: approved design; implementation not started

## Scope

Add one controlled asynchronous Playwright capability:

```text
web_capture -> web-screenshot / capture / job / cpu
```

The client submits only `web_capture`. It cannot choose a Pack, image,
entrypoint, command, host path, header, cookie, proxy, or browser flag. Mode
permission remains the sole external authorization; no Pack ACL is added.

The request returns `task_id`. The generic Pack-job worker runs one one-shot
container, using the shared queue, runtime lease, callback outbox, artifact
registry, cancellation, and retention. It never acquires `gpu:0`.

The existing `demo/php/mycut` scheduled/history application is not migrated.
V1 is one-off capture only: no scheduling, history UI, POST submission, login
flows, PDF, scraping, automatic site retries, or dedicated queue.

## Contract

`POST api.php?mode=web_capture` accepts no upload or `source_artifact_id`.

| Field | Rule | Default |
| --- | --- | --- |
| `url` | required absolute `http://` or `https://`; <= 2048 bytes | — |
| `width` | integer 320–2560 CSS px | 1280 |
| `height` | integer 320–2160 CSS px | 720 |
| `delay_seconds` | integer 0–60 | 0 |
| `timeout_seconds` | integer 10–120; total wall-clock deadline | 60 |
| `javascript` | optional UTF-8 page script; <= 16 KiB | absent |
| crop fields | all absent, or valid `x`, `y`, `width`, `height` integers | absent |

`delay_seconds < timeout_seconds`. The deadline covers navigation, delay,
script, screenshots, crop, and report. The capture always uses `fullPage:
true` and `deviceScaleFactor=1`. Crop coordinates use the complete PNG's
top-left `(0,0)` in CSS/PNG pixels, must fit the actual image, and are never
clamped. A capture over 60 million pixels, 30,000 px height, or 50 MiB fails
as `page_too_large`.

Runner order is fixed:

```text
admit -> navigate -> delay -> page JavaScript -> full-page PNG
-> optional crop -> report -> container removal -> Hub validation
-> fenced completion transaction -> callback outbox
```

HTTP 4xx/5xx after navigation is still a successful capture and is reported.
Timeout, disconnect, invalid crop, blocked main document, browser failure, or
oversized output fails. A site is not automatically retried; submit a new task.

## Browser and network boundary

Each task uses a fresh incognito context with fixed version-matched desktop
Chrome headers, `zh-TW` locale, and Asia/Taipei timezone. Reuse the proven
mycut rule: derive `Sec-CH-UA`, `Sec-CH-UA-Mobile`, and
`Sec-CH-UA-Platform` from the fixed Chrome UA major version. Normal UA/client
hint headers therefore contain no `HeadlessChrome`.

This is compatibility for sites such as CMoney, not a bot-control bypass.
There is no wider fingerprint spoofing, CAPTCHA bypass, or access-control
evasion. Callers cannot provide user agents, headers, cookies, credentials, or
proxies.

Only HTTP(S) on ports 80/443 is permitted. Reject URL credentials and all
other schemes. Admission and every navigation, redirect, frame, and
subresource reject localhost, loopback, private, link-local, CGNAT,
multicast, reserved, and metadata-service addresses; DNS answers must be
public.

The browser has no host network, Docker socket, host mounts, or Hub secrets.
It runs on a dedicated egress network with host policy blocking private and
reserved IPv4 destinations and with IPv6 disabled. The destination policy
applies after DNS resolution, preventing redirects and DNS rebinding from
reaching internal services. A blocked main document fails with
`url_not_allowed`; a blocked optional subresource is reported as a warning.

The optional script executes once in the page context after delay, under the
same deadline. It is not logged or copied to a result artifact.

## Artifacts and validation

Successful tasks publish:

- `screenshot_png` — required complete-page PNG.
- `capture_report` — required JSON.
- `crop_png` — only when all crop fields are supplied.

Hub requires regular non-symlink files inside the task workspace, detects MIME
itself, recomputes SHA-256 and size, validates PNG dimensions/pixel limits, and
parses the report. Missing, unsafe, or malformed output fails with
`output_contract_invalid` before success/callback publication.

The report records requested/final URL, HTTP status when available, viewport,
image dimensions, effective delay/timeout, script/crop use, elapsed seconds,
Pack/browser version, and bounded non-secret warnings. It excludes cookies,
request bodies, authorization headers, and script source.

Workspace files use the existing 24-hour retention. Published artifacts use
the standard 30-day retention plus existing pin, ACK, and callback rules.

## Acceptance

1. Route tests prove immutable `web-screenshot/capture/job/cpu`; external
   Pack/command/entrypoint/source-artifact fields are rejected.
2. Contract tests reject bad schemes, credentials/ports, malformed crops,
   overlarge scripts, and impossible delay/deadline combinations.
3. A controlled fixture self-check verifies full-page PNG, crop coordinates,
   paired UA/client hints, report generation, and container cleanup.
4. A smoke proves 404 capture succeeds while an unreachable page fails within
   bounds without holding a web request or GPU lease.
5. Network-policy smoke proves private/loopback and redirect-to-private are
   unreachable, and invalid artifacts cannot trigger completion callbacks.

New PHP files under `/var/www/html` use `0755`; JavaScript/CSS use `0644`; new
public directories use `0755`.
