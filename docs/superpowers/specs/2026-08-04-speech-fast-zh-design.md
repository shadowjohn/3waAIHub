# Speech Fast ZH Pack Design

## Purpose

Add `speech-fast-zh` as an independent CPU-only Chinese transcription Pack.
It serves fast draft transcription and does not replace `whisper-asr`.

## Scope

- Public async mode: `speech_transcribe_fast_zh`.
- Engine: `sherpa-onnx` Paraformer Chinese small int8.
- Runtime: one CPU runner, no GPU lease, no network access during inference.
- Input: Hub-managed WAV audio upload or accepted source artifact. Optional
  `include_draft_subtitles` defaults to `false`.
- Output: `transcript_json` containing untouched `raw_text`, normalized Taiwanese
  Traditional-Chinese `text`, measured elapsed time, audio duration, RTF, engine
  identifier, and `provider=cpu`. When requested, it also returns a
  `draft_subtitle_srt` artifact and `draft_segments`.
- Models live in Hub-managed storage and are provisioned explicitly. Model files do not
  enter Git or the container image.

## Boundaries

- Keep `raw_text` exactly as Paraformer returned it. The user-facing draft `text` applies
  only deterministic Taiwanese conversion and corrections learned from SpeakSlow:
  `s2twp` OpenCC (with `賬→帳`), full-width ASCII normalization, joining a run of
  separated ASCII letters, and conservative `樂色／勒色→垃圾` pronunciation correction.
  Do not remove fillers, collapse repeated words, add punctuation, infer emojis,
  or perform broad number conversion in this Pack version.
- No WhisperX-style alignment, diarization, streaming, or GPU fallback. Draft subtitle
  timing uses Paraformer token timestamps and VAD/pause-based line breaks.
- No change to the existing `speech_transcribe` / Whisper API contract.
- No automatic write or confirmation of `voice_profiles.prompt_text`.

## Voice Clone Use

A later, separate integration may select this mode to prefill a managed voice profile's
draft transcript. The existing explicit transcript confirmation remains mandatory before
`ultimate_clone` can use the profile.

## Runtime Levels

1. `L1-contract`: Pack manifest, async route, request validation, and transcript artifact contract.
2. `L2-deps-import`: Docker image imports pinned `sherpa-onnx` and NumPy dependencies.
3. `L3-storage-mount`: explicit model provisioning to Hub-managed storage with required files.
4. `L4-real-inference`: CPU runner produces non-empty `raw_text` and, when requested,
   a valid `draft_subtitle_srt` from the committed Chinese WAV fixture.

`L5` is deferred until we have a varied, consented Mandarin evaluation set and agreed quality thresholds.

## Acceptance

- Install validation succeeds without NVIDIA runtime.
- The mode appears only when this Pack is installed and enabled.
- One real 16 kHz mono WAV run returns a `transcript_json` artifact with `provider=cpu`.
- `include_draft_subtitles=true` returns a non-empty SRT artifact named and described as a
  draft subtitle result.
- The runner remains network-isolated and receives no GPU runtime arguments.
- Existing Whisper async and voice-profile confirmation tests remain unchanged.
