# Web Screenshot Field Intel Draft

## Context

`demo_3waAIHub` already turns an uploaded photograph into a field card using
BiRefNet, PP-OCRv5, BioCLIP, Gemma, and VoxCPM2. The new `web_capture` Pack can
collect an allowlisted public webpage as a PNG plus a capture report. It should
become another trustworthy source for the same field-card workflow, not a
general URL fetcher.

## Directions

### A. Web Field Note (recommended)

Paste one allowlisted URL. The demo captures it, stores the capture evidence,
then sends the PNG through the existing image pipeline. The result is a
"web field card": page image, OCR signals, visual category hints, a cautious
summary, and optional short audio title.

Why: it reuses the existing card UI and five AI modes while making the new Pack
feel useful immediately.

### B. Page Change Watch

Store a baseline capture and run cron comparisons later. This is useful for
monitoring dashboards or public notices, but needs retention rules, scheduling,
and notification policy.

Not in this slice.

### C. Evidence Dossier

Capture several URLs into one dossier and let Gemma compare them. It is good
for research, but needs multi-task progress and a new review UI.

Not in this slice.

## Proposed Flow

1. The user selects `圖片採樣` or `網頁採樣` in the existing demo.
2. `網頁採樣` accepts one HTTPS URL. PHP sends `web_capture` to the Router with
   the demo token; the browser never sees the token.
3. PHP polls the returned task until terminal state, fetches only the declared
   PNG artifact and constrained capture report, then saves them under the case
   UUID.
4. The captured PNG follows the existing BiRefNet, PP-OCRv5, BioCLIP, Gemma,
   and optional VoxCPM2 pipeline. The UI names the source URL and final URL so
   an observation cannot be mistaken for a direct photograph.
5. The card exposes the protected original screenshot, cutout, and optional
   WAV through the existing UUID-only `file.php` surface.

## Small Data Change

Extend `shadow.demo_3waaihub_signal` with only:

- `source_kind` (`upload` or `web_capture`)
- `source_url`
- `capture_report_json`

The current source file, pipeline JSON, and fixed UUID directory remain the
single storage model. No new table and no cron are needed for on-demand capture.

## Security and Truthfulness

- `web_capture` stays bounded by 3waAIHub's admin-maintained exact-host
  allowlist, public-IP checks, redirect policy, and container-local egress
  firewall.
- The demo accepts only HTTPS and relies on the Pack for the authoritative
  allowlist decision. It does not duplicate a weaker client-side URL policy.
- Artifact URLs are not trusted directly: PHP accepts the Router task result,
  validates the expected PNG/report shape, and keeps all files behind
  `file.php`.
- If capture or any optional AI stage is unavailable, the card records that
  fact. It never fabricates a webpage observation.

## Acceptance

Use an allowlisted page such as `https://3wa.tw/` to prove one real capture:

- Web Screenshot returns a real PNG and report.
- The demo stores and displays the source/final URL.
- At least OCR, BioCLIP, and Gemma receive the captured PNG; BiRefNet and TTS
  remain visible as real success or explicit graceful degradation.
- The screenshot and any derived WAV download only through the protected case
  file endpoint.

## Decision Needed

Approve direction A, **Web Field Note**, before implementation. It is the
smallest path that turns the screenshot Pack into a memorable cluster demo
without adding a scheduler or a second application.
