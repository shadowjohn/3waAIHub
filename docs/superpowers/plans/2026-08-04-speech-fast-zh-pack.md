# Speech Fast ZH Pack Implementation Plan

> **For implementation:** follow `superpowers:executing-plans` for inline work or
> `superpowers:subagent-driven-development` for delegated work. Apply the steps in
> order and keep the existing `whisper-asr` contract unchanged.

**Goal:** Ship `speech-fast-zh`, a separate CPU-only, offline Paraformer Chinese
draft-transcription Pack. It accepts Hub-managed audio artifacts through the
asynchronous task API and can optionally emit coarse, explicitly named subtitle
artifacts. It is a fast draft path for later voice-profile preparation, never a
replacement for Whisper quality or a source of automatic profile confirmation.

**Architecture:** Add a normal `internal_task` Pack backed by the generic Pack job
adapter. Its runner is a network-isolated CPU container with a read-only mount of a
Hub-provisioned Paraformer model. The runner normalizes the accepted audio locally,
performs offline `sherpa-onnx` inference, and publishes declared task artifacts.
The only control-plane changes are the fixed Pack route and audio-mode compatibility
registration, so Cluster discovery, token authorization, task relay, and public API
documentation use their existing generic paths.

**Stack:** PHP 8 / existing Pack registry and Pack job runner; Python 3.11,
`sherpa-onnx==1.13.4`, NumPy, and ffmpeg inside a `python:3.11-slim` runner image;
Hub-managed model storage; existing PHP and Python unittest suites.

**Non-goals:** no GPU fallback or GPU lease; no resident service; no external network
during inference; no broad semantic cleanup, punctuation recovery, diarization,
streaming, WhisperX alignment, hotword controls, or automatic writes to
`voice_profiles.prompt_text`.

## Contract Decisions

- Pack id: `speech-fast-zh`; version `0.1.0`; category `asr`; type
  `internal_task`; `runtime_level` and `target_level` `L4-real-inference` after the
  real smoke passes.
- Async mode and job: `speech_transcribe_fast_zh` / `transcribe`, mapped with the
  `cpu` accelerator and included in `hub_audio_async_routes()`.
- Request: one accepted source artifact of type `audio`, `cleaned_audio`, or
  `vocals_audio`, plus the optional boolean `include_draft_subtitles` (default
  `false`). The generic audio upload and `source_artifact_id` flows remain unchanged.
- Required outputs:
  - `transcript_json` / `transcript.json`: unmodified `raw_text`, normalized `text`,
    `language`, `engine`, `provider`, `model`, `audio_seconds`, `elapsed_seconds`,
    and `rtf`.
  - `transcription_report` / `transcription_report.json`: the engine/model and the
    measured task facts needed to audit an inference run.
  - When requested only: `draft_subtitle_srt` / `draft_subtitle.srt` and
    `draft_segments` / `draft_segments.json`. Their names and Pack description make
    the timing quality clear without adding noisy UI warnings.
- Model root: `models/speech-fast-zh/paraformer-zh-small-2024-03-09`, mounted read
  only at `/models/paraformer`. Required files are `model.int8.onnx`, `tokens.txt`,
  and `.aihub-speech-fast-zh-ready.json`.
- Provision source: the fixed sherpa-onnx release archive
  `https://github.com/k2-fsa/sherpa-onnx/releases/download/asr-models/sherpa-onnx-paraformer-zh-small-2024-03-09.tar.bz2`.
  Provisioning verifies the archive digest, rejects unsafe archive layouts and
  symlinks, stages then atomically publishes the model, and writes the marker only
  after its files and hashes validate. The runtime is offline and never downloads.
- Rough subtitles are constructed only from the model's token timestamps, with pause,
  duration, and CJK character-count boundaries. No separate VAD model is added in
  this release. This is deliberately not word-accurate alignment.
- `text` is a narrow user-facing zh-TW normalization learned from SpeakSlow: OpenCC
  `s2twp` plus `賬→帳`, ASCII width normalization, separated-letter joining, and the
  conservative Taiwan-pronunciation `樂色／勒色→垃圾` correction. It never overwrites
  `raw_text`, removes spoken content, inserts punctuation, or attempts broad semantic
  correction.

## Work Plan

### 1. Lock the public Pack contract and route with failing PHP tests

**Files:**
- Modify: `app/pack_registry.php`
- Modify: `packs/catalog.json`
- Create: `packs/speech-fast-zh/pack.json`
- Create: `tests/test_speech_fast_zh_pack.php`
- Modify: `tests/suites/voice-cluster.php`
- Modify: `tests/test_web_screenshot_pack.php`
- Modify: `tests/test_audio_task_gateway.php`
- Modify: `tests/test_public_api_docs.php`

**Implementation:**
1. Add a failing test file that installs `speech-fast-zh`, resolves
   `speech_transcribe_fast_zh`, and asserts the immutable route fields:
   Pack id, `transcribe` job, CPU accelerator, `required_vram_mb=0`, default CPU
   queue, one-worker concurrency, no GPU support, and no public-egress profile.
2. Add test cases for the only request option: missing uses `false`; `true` enables
   exactly the two draft subtitle artifacts; non-boolean values fail generic schema
   validation. Reuse the existing audio artifact helper to prove the normal upload
   and allowed chained artifact types remain authorized and ownership-checked.
3. Add the Pack manifest and catalog row with a Chinese-purpose description that says
   it is a lightweight CPU Chinese draft transcript and its subtitles are coarse.
   Declare it as an internal async task with `gateway.require_service_enabled=true`,
   a 200 MiB upload limit, `cpu` queue, and `max_concurrency=1`.
4. Define the output JSON schemas and conditional artifact declarations precisely:
   `transcript_json`, `transcription_report`, `draft_subtitle_srt`, and
   `draft_segments`. Keep `speech_transcribe` and its `subtitle_srt` contract
   untouched.
5. Add `speech_transcribe_fast_zh` to `hub_pack_job_async_routes()` and
   `hub_audio_async_routes()`. Update exact-array route assertions and generic public
   API documentation assertions so the mode appears only when the Pack is installed,
   enabled, and live through current health/catalog filtering.
6. Add this focused test to `voice-cluster.php`, because it is an audio Pack that
   must work through the same Cluster task/result relay as Whisper.

**Verification:**
```bash
php tests/test_speech_fast_zh_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
```

### 2. Build the offline CPU runner and unit-test its output logic

**Files:**
- Create: `packs/speech-fast-zh/service/Dockerfile`
- Create: `packs/speech-fast-zh/service/requirements.txt`
- Create: `packs/speech-fast-zh/service/job.py`
- Create: `packs/speech-fast-zh/service/test_job.py`
- Create: `packs/speech-fast-zh/docker-compose.yml`

**Implementation:**
1. Use `python:3.11-slim`, install only `ffmpeg` and the pinned Python dependencies,
   and run the Python unit tests during image build. Run as a non-root service user.
   Do not include model weights or a model download step in the Dockerfile.
2. Implement the generic runner CLI used by the Pack job adapter:
   `--workspace`, `--input`, `--output`, and `--runner-config`. Verify that input and
   output resolve to the expected Hub workspace children before reading them.
3. Use ffmpeg locally to normalize the accepted source into 16 kHz mono PCM WAV in a
   private workspace directory. Reject missing, unreadable, or non-audio sources with
   stable runner error codes. Delete the private normalized file before exit.
4. Load only `/models/paraformer/model.int8.onnx` and `tokens.txt` using
   `sherpa_onnx.OfflineRecognizer.from_paraformer` with `provider="cpu"` and a
   single decode thread. Feed waveform samples to one offline stream and obtain the
   true result from `stream.result`, not an obsolete recognizer-level accessor.
5. Write `transcript.json` and `transcription_report.json` atomically, recording the
   untouched Paraformer `raw_text`, a separate normalized zh-TW `text`, audio duration,
   elapsed seconds, and RTF. The normalizer reuses only SpeakSlow's low-risk pieces:
   OpenCC `s2twp` with `賬→帳`, full-width ASCII normalization, separated-English-letter
   joining, and the context-guarded `樂色／勒色→垃圾` Taiwan-pronunciation repair. Do not
   remove fillers/repeats, add punctuation, emit emoji, or broadly rewrite numbers.
   Use `provider: "cpu"`, `engine: "sherpa-onnx"`, and the fixed model identifier.
6. When `include_draft_subtitles=true`, form `draft_segments.json` from token
   timestamps. Break a line on a meaningful timestamp gap, about 4.5 seconds, or a
   modest Chinese-character budget; serialize the same segment list to valid SRT.
   Keep English/BPE joining minimal and deterministic. Do not claim word-level timing
   precision.
7. Unit-test path validation, request parsing/defaults, 16 kHz conversion command
   construction, token joining, timestamp gap/length segmentation, SRT formatting,
   safe zh-TW normalization while preserving `raw_text`, empty output handling, and the
   exact required JSON shape using a fake recognizer.

**Verification:**
```bash
python3 -m unittest -v packs/speech-fast-zh/service/test_job.py
docker build -f packs/speech-fast-zh/service/Dockerfile packs/speech-fast-zh/service
```

### 3. Add explicit, verified managed-model provisioning

**Files:**
- Create: `packs/speech-fast-zh/service/provision_offline_assets.py`
- Create: `packs/speech-fast-zh/service/test_provision_offline_assets.py`
- Create: `packs/speech-fast-zh/jobs/provision_offline_models.sh`
- Modify: `packs/speech-fast-zh/service/Dockerfile`
- Modify: `packs/speech-fast-zh/pack.json`
- Create: `packs/speech-fast-zh/README.md`

**Implementation:**
1. Put the archive URL, the verified release archive SHA-256, expected extracted
   directory, and expected hashes for `model.int8.onnx` and `tokens.txt` in the
   provisioner. Recompute and lock these values from the source archive rather than
   relying only on download filename or size.
2. Add a trusted explicit provision command following the existing Whisper and
   GPT-SoVITS pattern. It requires absolute `AIHUB_MODELS_DIR`, mounts only the
   dedicated model directory into a short-lived container, and invokes an explicit
   provision entry point. Support an administrator-supplied absolute archive path for
   air-gapped installs.
3. Reject symlinked roots, archive members outside the expected prefix, duplicate
   entries, device files, and unexpected extracted files. Stage inside the managed
   model root, validate exact files and digests, then publish the directory and marker
   atomically. Remove the marker before a failed re-provision can leave a false-ready
   state.
4. Add the manifest `asset_mounts` descriptor with the read-only Paraformer storage
   mount, its required paths, and marker JSON contract. The normal runner therefore
   fails in preflight before consuming CPU if assets are absent or altered.
5. Document one model-provisioning command, expected storage location, offline
   inference guarantee, output types, and the explicit draft-subtitle limitation.
   Do not place model archives, extracted files, or temporary download state in Git.

**Verification:**
```bash
python3 -m unittest -v packs/speech-fast-zh/service/test_provision_offline_assets.py
php tests/test_speech_fast_zh_pack.php
```

### 4. Run L4 real CPU inference and validate end-to-end task artifacts

**Files:**
- Create: `packs/speech-fast-zh/service/inference_smoke.py`
- Modify: `packs/speech-fast-zh/README.md`
- Modify: `tests/test_speech_fast_zh_pack.php`

**Implementation:**
1. Add a non-network real-smoke helper that invokes the built runner with the
   pre-provisioned model and existing committed fixture
   `packs/llm-gemma4-12b/demo/audio_zh_smoke.wav`. It must request draft subtitles.
2. Assert that `raw_text` and normalized `text` are non-empty, `provider` is `cpu`,
   duration and elapsed time are non-negative, RTF is present, `draft_subtitle.srt` has a valid SRT block,
   and `draft_segments.json` contains the matching non-empty segment list. Do not
   assert the exact Mandarin wording: L4 proves live execution and artifact integrity,
   while recognition-quality thresholds belong to the deferred consented L5 set.
3. Run one generic Pack task test with a prepared model-storage fixture to confirm the
   artifact contract accepts all declared outputs, task result relay returns them, and
   Cluster routing requires neither a GPU lease nor resident capacity.
4. Record the measured CPU execution time and RTF in the Pack README's acceptance
   notes, clearly marking host/model conditions. Never portray the L0 fixture result
   as a broad accuracy benchmark.

**Verification:**
```bash
python3 packs/speech-fast-zh/service/inference_smoke.py \
  --model-dir "$AIHUB_MODELS_DIR/speech-fast-zh/paraformer-zh-small-2024-03-09" \
  --audio packs/llm-gemma4-12b/demo/audio_zh_smoke.wav
php tests/test_speech_fast_zh_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
```

### 5. Perform focused regression and release-readiness checks

**Files:**
- Modify as needed only from failures in the files above.

**Implementation:**
1. Confirm a machine with no NVIDIA runtime can install and expose the Pack after its
   CPU runner image and model assets are prepared.
2. Confirm an uninstalled/disabled Pack does not appear in available API modes or
   Cluster live inventory; an installed/enabled Pack appears through the normal
   generic catalog and permission flow.
3. Confirm `include_draft_subtitles=false` creates only transcript/report artifacts;
   `true` creates both draft subtitle artifacts in addition. Confirm no code path
   mutates a managed voice profile.
4. Run the affected audio, public-documentation, and portability suites. Preserve
   existing Whisper, VoxCPM2, GPT-SoVITS, and Edge TTS assertions unchanged except
   for explicit route/catalog expectations expanded by the new mode.

**Verification:**
```bash
php tests/test_speech_fast_zh_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
php tests/test_runtime_portability.php
git diff --check
git status --short
```

## Deferred L5

Create a consented Mandarin evaluation set, establish character error rate and timing
tolerances, then compare this Pack against Whisper/faster-whisper. Only after that
may a later Voice Profile UI offer this Pack as a prefill candidate; the existing
human confirmation remains required.
