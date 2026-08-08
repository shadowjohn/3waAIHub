# GPT-SoVITS Profile Alignment Design

Date: 2026-08-08

Status: approved design; implementation pending spec review

## Goal

Make a GPT-SoVITS `ultimate_clone` prompt transcript describe exactly the
reference WAV supplied to the model. This supersedes the per-synthesis
proportional prompt trimming described in the Phase A design.

## Decision

When `voice_generate_gpt_sovits` prepares a Voice Profile, it creates one
derived GPT-SoVITS reference WAV before ASR and confirmation. The derived WAV
is mono 32 kHz and three to ten seconds long; for longer audio it ends at the
existing nearby-silence boundary, targeting five seconds. The existing Voice
Profile ASR then transcribes that exact managed WAV and the owner confirms
that draft.

`ultimate_clone` uses the confirmed prompt and this reference unchanged. It
does not estimate prompt length or re-cut audio while synthesizing.

## Scope and compatibility

- This applies only to Profiles prepared through `voice_generate_gpt_sovits`.
  Existing VoxCPM2 Profile behavior stays unchanged.
- `voice_profiles.reference_contract` has exactly `generic` (the migration
  default) and `gpt_sovits_v1`. The latter marks a derived reference suitable
  for either GPT-SoVITS clone mode.
- The prepared GPT-SoVITS Profile remains a normal owner-scoped, consented,
  expiring managed Profile. No new public path, raw upload field, or model
  download is added.
- A generic or legacy Profile is rejected for either GPT-SoVITS clone mode
  with a bounded re-prepare error. It is never silently aligned by
  character-count heuristics.
- `clone` requires `gpt_sovits_v1` but not a confirmed transcript;
  `ultimate_clone` requires both.

## Data flow

1. The existing profile-prepare task validates and owns the uploaded WAV. GPT
   preparation does not reuse a generic Profile cache entry.
2. For the GPT-SoVITS mode it writes a staged, silence-boundary normalised WAV
   and atomically promotes it as the managed reference artifact, replacing and
   securely removing the raw managed upload. It stores the derived SHA-256 and
   `gpt_sovits_v1` marker on the Profile.
3. The existing ASR path produces a draft from that derived artifact; the
   authenticated owner confirms the same text through `profile_confirm`.
4. Queue admission requires the marker and snapshots the derived WAV SHA-256
   plus confirmed-text hash.
   Synthesis retains the current ownership, expiry, SHA-256, and prompt
   confirmation checks.
5. The Pack service validates a three-to-ten-second reference as a defensive
   invariant, then passes it and the confirmed prompt to GPT-SoVITS unchanged.

## Failure and privacy rules

- Sources shorter than three seconds, failed ffmpeg normalisation, invalid
  derived WAVs, generic/legacy Profiles, and mismatched hashes fail before ASR
  or GPU inference.
- The temporary raw upload is securely removed after promotion. The derived
  WAV and transcript keep current owner-only storage, redaction, retention,
  audit, and prompt-scrubbing rules.
- No ASR timestamps, WhisperX dependency, extra GPU model, or transcript is
  exposed in task logs, callbacks, or synthesis metadata.

## Verification

Focused tests prove that a GPT-SoVITS Profile ASR input is the derived WAV,
the confirmed transcript hash belongs to that WAV, and long legacy Profiles
are rejected before queueing or GPU work. Existing Pack tests prove that the
service accepts only a three-to-ten-second prepared reference, keeps request
scrubbing, and preserves artifact contracts.

## Rejected alternatives

Per-synthesis character-per-second trimming (including the MyAI reference
implementation) reduces duration mismatch but cannot prove that spoken words
match the retained prompt. WhisperX word-level alignment is more exact but
duplicates model assets and GPU work already avoided by this Profile-prepared,
human-confirmed contract. It is deferred unless automatic arbitrary-long
reference selection becomes a product requirement.
