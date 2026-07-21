# VoxCPM2 Three-Mode Playground Design

## Goal

Make the TTS playground usable for side-by-side Voice Design, Basic Clone, and Ultimate Clone trials without accepting arbitrary server file paths or sharing voice data across members.

## Scope

- Add a reusable Voice Profile upload flow to the TTS playground.
- Cache exact WAV uploads by `owner_member_id` and SHA-256.
- Transcribe a cache miss with the existing Whisper ASR service, then allow the owner to edit and confirm the transcript.
- Support all three VoxCPM2 modes and generate a sequential three-mode comparison.
- Implement real Whisper inference with GPU-first, CPU-fallback behavior.

This does not implement the planned generic audio job runtime. The first version is a synchronous, admin-playground workflow.

## Voice Profiles

A Voice Profile remains the only public reference to a clone source. The browser uploads a WAV to the managed `data/uploads/voice_profiles/` directory; the browser and public API never supply a host or container path.

The profile lookup key is `(owner_member_id, reference_audio_sha256, deleted_at IS NULL)`. A byte-identical upload by the same owner reuses the existing profile, its stored transcript, and its managed WAV. Profiles belonging to other members are never selected or exposed.

`voice_profiles` gains nullable `prompt_text_confirmed_at`.

- `prompt_text` may hold an ASR draft or an owner-edited transcript.
- a non-null confirmation timestamp means the transcript is approved for Ultimate Clone.
- existing non-empty transcripts migrate as confirmed.
- create, ASR cache hit or miss, transcript confirmation, and clone use are recorded in the existing audit log.

The upload action requires a profile name and a valid consent type. It accepts WAV only after size, MIME, and RIFF/WAVE signature checks.

## Playground Flow

The TTS surface exposes three mode choices:

1. Voice Design: target text and a voice prompt. No profile is used.
2. Basic Clone: target text, an owned profile, and optional control text. The profile WAV is required; an approved transcript is not.
3. Ultimate Clone: target text and an owned profile with an approved transcript.

The profile panel lets an owner upload or select an existing profile. On an upload cache miss, the playground stores the WAV, asks the installed ASR service for a real transcript, displays the result for editing, and saves it as an unconfirmed draft. Confirmation makes Ultimate Clone available. If ASR fails, the profile remains available to Basic Clone and the owner can retry transcription or enter a transcript manually.

The Compare action uses one target text and, in order, invokes Voice Design, Basic Clone, and Ultimate Clone. It renders three independent authenticated WAV players and their result summaries. It is enabled only when the selected profile has a confirmed transcript.

## Trusted Gateway Mapping

The gateway continues to reject client-provided `reference_audio_path`, `prompt_wav_path`, and `prompt_audio_path`.

For Basic Clone it resolves the owned profile and injects:

- `reference_wav_path`
- `voice_profile_id`
- `reference_audio_sha256`

For Ultimate Clone it additionally injects the same managed WAV as `prompt_wav_path` and the confirmed `prompt_text`. The TTS adapter passes these fields to `VoxCPM.generate`. The transcript is never returned in a TTS artifact manifest or public response.

## ASR And GPU Behavior

Whisper ASR upgrades from its L3 placeholder to real `faster-whisper` inference. `WHISPER_DEVICE=auto` prefers CUDA and falls back to CPU under its existing pack configuration. It returns a non-empty transcript and segments only after real inference succeeds; no real-inference request can return mock text.

The initial workflow is synchronous and reuses existing service-readiness checks before dispatch. It does not invent a VRAM budget estimator or stop unrelated services such as Nemotron. CUDA, model-load, and out-of-memory failures are explicit retryable errors and leave the profile and transcript cache intact.

## Failure Handling

- Invalid, oversized, or unauthorized audio is rejected before persistence.
- Cache hits do not re-run ASR.
- ASR failure preserves the managed profile and reports a retryable state.
- Ultimate Clone rejects missing or unconfirmed transcripts.
- Real inference errors remain errors; they never degrade to mock output.

## Verification

Automated checks cover owner-scoped SHA cache behavior, cross-owner isolation, WAV validation, transcript confirmation, gateway path rewriting, and the distinct Basic and Ultimate VoxCPM2 arguments.

A real smoke uses an approved short WAV. It verifies real ASR returns text, then produces valid WAV output for Voice Design, Basic Clone, and Ultimate Clone in sequence. Output manifests retain profile identity and audio SHA-256 without emitting the source transcript.
