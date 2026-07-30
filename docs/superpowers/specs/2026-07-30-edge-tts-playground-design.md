# Edge TTS Playground design

## Goal

Make `admin/playground.php?mode=edge_tts` a usable API test surface for the
existing Edge TTS Pack. It must submit the exact Pack contract and provide
three safe, representative test presets.

## Current state and root cause

`edge_tts` is included in `hub_playground_supported_modes()`, but is absent
from `hub_playground_profiles()`. The generic executor therefore returns
`unsupported_mode`; the rendered request area falls back to the `hello` UI,
and `hub_playground_examples()` falls back to an unrelated multipart upload
example.

The Pack contract in `packs/edge-tts/pack.json` accepts only these fields:

- `text` (required; at most 4096 bytes)
- `voice` (one of the fixed 14-voice catalogue)
- `rate`, `volume` (five percentage values)
- `pitch` (five Hz values)
- `include_subtitles` (boolean)

The public endpoint requires a URL-encoded POST and returns an asynchronous
task. The existing Playground result rendering and task URL rewriting already
cover that response.

## Design

Add one Edge TTS profile using the existing profile structure, with a normal
form-encoded POST body. Keep the form and payload in `admin/playground.php`:

- textarea for `text`;
- select controls for fixed Pack enums: `voice`, `rate`, `volume`, `pitch`;
- checkbox for `include_subtitles`;
- three preset links that reload the same page with named presets and prefill
  the controls without JavaScript:
  - Taiwan female narration: `zh-TW-HsiaoChenNeural`, defaults, audio only;
  - slow technical explanation: `zh-TW-YunJheNeural`, `rate=-25%`, subtitles;
  - fast Cantonese announcement: `zh-HK-WanLungNeural`, `rate=+25%`, audio only.

The Edge TTS branch of `hub_playground_examples()` will provide matching
curl, PHP, and JavaScript examples using
`application/x-www-form-urlencoded`. It will show one concise submission
request; the named form presets are the additional runnable examples.

No Pack, runner, gateway, database, or JavaScript changes are needed. The
server-side payload will explicitly send all six fields, so the runner's
strict request-key validation remains satisfied.

## Verification

Add focused coverage asserting that Edge TTS has a POST form profile, maps
each submitted control to the valid payload types and defaults, and renders
the form labels/preset names plus URL-encoded SDK examples. Run that focused
test file and PHP syntax validation for `admin/playground.php`.
