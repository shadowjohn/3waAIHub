# Whisper Legacy Subtitle Reflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move MyAI Voice's proven cue reflow, punctuation, and word-boundary behavior into the `whisper-asr` Pack, using CKIP first and jieba only when CKIP cannot safely run.

**Architecture:** `subtitle_reflow=legacy_adaptive_v1` is an explicit, manifest-allowed speech-transcribe option; MyAI sends it whenever it requests SRT/VTT. It mounts the Pack-controlled CKIP offline model, uses CKIP while the same GPU Job holds at least the legacy 4 GB headroom, and falls back to the image-bundled CPU jieba on insufficient VRAM or a CKIP load/inference failure. The Hub stays unchanged: it owns the single GPU lease and artifacts, while all text shaping stays in the Pack runner.

**Tech Stack:** Python 3.10, faster-whisper, WhisperX, `ckip-transformers==0.3.4`, `transformers==4.57.6`, `jieba==0.42.1`, `wordsegment==1.3.1`, `opencc-python-reimplemented==0.1.7`, PHP Pack contract tests.

---

## File Structure

- Create: `packs/whisper-asr/service/subtitle_reflow.py` — deterministic port of the legacy normalizer, token rebuilding, cue splitting, timestamp reconstruction, CKIP-first word-boundary resolver, and jieba fallback.
- Modify: `packs/whisper-asr/service/offline_paths.py` — fixed CKIP local model path and marker manifest.
- Modify: `packs/whisper-asr/service/provision_offline_assets.py` — trusted admin-only CKIP snapshot provisioning and regular-file verification.
- Modify: `packs/whisper-asr/jobs/provision_offline_models.sh` — add the opt-in `AIHUB_WHISPER_PROVISION_CKIP=1` switch and the image version it invokes.
- Modify: `packs/whisper-asr/service/job.py` — validate the reflow profile, acquire native words only for reflow, use aligned words only when explicitly requested, and record the actual breaker used.
- Modify: `packs/whisper-asr/service/requirements.txt` — immutable CKIP/Jieba/normalization dependencies.
- Modify: `packs/whisper-asr/pack.json` — request schema, CKIP asset descriptor, versioned runner image.
- Modify: `tests/test_whisper_asr_async.php` — direct reflow and Pack asset/load-matrix regression checks.
- Modify: `/var/www/html/myai/myai_voice/voice_aihub.php` — MyAI’s managed submitter explicitly requests the compatibility profile with its existing SRT/VTT request.

### Task 1: Define the adaptive legacy profile with failing tests

**Files:**
- Modify: `tests/test_whisper_asr_async.php:15-115,370-412`
- Test: `tests/test_whisper_asr_async.php`

- [ ] **Step 1: Add the profile to the manifest assertions**

  Expect the exact new allowed input and default:

  ```php
  hub_test_assert(($job['input_fields'] ?? []) === [
      'model', 'language', 'word_timestamps', 'diarization', 'min_speakers',
      'max_speakers', 'output_srt', 'output_vtt', 'subtitle_reflow',
  ], 'Whisper reflow profile must be a declared Pack input');
  hub_test_assert(($job['request_schema']['subtitle_reflow'] ?? null) === [
      'type' => 'string', 'required' => false,
      'enum' => ['none', 'legacy_adaptive_v1'], 'default' => 'none',
      'max_length' => 32,
  ], 'Whisper reflow profile schema mismatch');
  ```

- [ ] **Step 2: Add asset-preflight assertions**

  Keep basic ASR and explicit alignment assertions. Add that the CKIP asset is absent for `subtitle_reflow=none`, and that `legacy_adaptive_v1` rejects before GPU work until the marker and local model files exist:

  ```php
  hub_test_assert(
      hub_pack_job_resolve_asset_mounts($db, $runner, [
          'language' => 'auto', 'word_timestamps' => false,
          'diarization' => false, 'subtitle_reflow' => 'none',
      ]) === $asrAssets,
      'plain ASR must not require CKIP'
  );
  hub_test_assert(hub_test_throws(static fn (): array => hub_pack_job_resolve_asset_mounts(
      $db, $runner, ['language' => 'zh', 'word_timestamps' => false,
          'diarization' => false, 'subtitle_reflow' => 'legacy_adaptive_v1']
  )), 'legacy reflow must preflight its offline CKIP asset');
  ```

- [ ] **Step 3: Add direct runner fixtures for CKIP and fallback output**

  Mock `subtitle_reflow.ckip_tokens` twice: first return `['臺灣', '人工智慧']`, then raise `RuntimeError('ckip_unavailable')`. For the same Chinese word-time input, assert the first report has `subtitle_breaker='ckip'`, the second `subtitle_breaker='jieba'`, both produce valid SRT cues with the first/last retained word timestamps, and neither loads WhisperX alignment unless `word_timestamps=true`.

- [ ] **Step 4: Run the focused test and verify it fails**

  Run: `php tests/test_whisper_asr_async.php`

  Expected: FAIL because the profile, CKIP asset and reflow module do not yet exist.

- [ ] **Step 5: Commit the failing specification**

  ```bash
  git add tests/test_whisper_asr_async.php
  git commit -m "test: define adaptive whisper subtitle reflow"
  ```

### Task 2: Add an offline CKIP asset without changing Hub Core

**Files:**
- Modify: `packs/whisper-asr/service/offline_paths.py`
- Modify: `packs/whisper-asr/service/provision_offline_assets.py`
- Modify: `packs/whisper-asr/jobs/provision_offline_models.sh`
- Test: `tests/test_whisper_asr_async.php`

- [ ] **Step 1: Define fixed safe paths and a marker**

  Add the fixed local asset constants:

  ```python
  CKIP_MODEL_REPOSITORY = 'ckiplab/bert-base-chinese-ws'
  CKIP_MODEL_DIR = Path('/cache/whisper/ckip/bert-base-chinese-ws')
  CKIP_MARKER = CKIP_MODEL_DIR / '.aihub-ckip-ready.json'

  def ckip_cache_manifest() -> dict[str, object]:
      return {
          'schema': 'aihub-whisper-ckip/v1',
          'repository': CKIP_MODEL_REPOSITORY,
          'model_path': str(CKIP_MODEL_DIR),
          'breaker': 'ckip-transformers-0.3.4',
      }
  ```

- [ ] **Step 2: Provision CKIP only by explicit admin choice**

  Add `--with-ckip` to `provision_offline_assets.py`. After `configure_download_cache()` and importing the existing trusted `snapshot_download`, use:

  ```python
  snapshot_download(CKIP_MODEL_REPOSITORY, local_dir=str(CKIP_MODEL_DIR))
  for name in ('config.json', 'model.safetensors', 'vocab.txt'):
      require_regular_file(CKIP_MODEL_DIR / name, 'ckip_model_unavailable')
  configure_offline_cache()
  from ckip_transformers.nlp import CkipWordSegmenter
  CkipWordSegmenter(model_name=str(CKIP_MODEL_DIR), device=-1)
  write_atomic(CKIP_MARKER, (json.dumps(ckip_cache_manifest(), sort_keys=True) + '\n').encode('utf-8'))
  ```

  This downloads only in the trusted provision container. The production Job gets a read-only mount, `HF_HUB_OFFLINE=1`, and no credentials.

- [ ] **Step 3: Wire the existing shell provisioner**

  Recognize only `AIHUB_WHISPER_PROVISION_CKIP=0|1`, append `--with-ckip` only for `1`, and change its image to `3waaihub/whisper-asr:0.1.1`. Reject any other value with exit code 64.

- [ ] **Step 4: Run the focused test**

  Run: `php tests/test_whisper_asr_async.php`

  Expected: tests still fail only at the not-yet-connected runner/profile behavior; no asset parser regression.

- [ ] **Step 5: Commit the asset work**

  ```bash
  git add packs/whisper-asr/service/offline_paths.py packs/whisper-asr/service/provision_offline_assets.py packs/whisper-asr/jobs/provision_offline_models.sh tests/test_whisper_asr_async.php
  git commit -m "feat: provision whisper CKIP reflow asset"
  ```

### Task 3: Port the smallest complete legacy reflow path

**Files:**
- Create: `packs/whisper-asr/service/subtitle_reflow.py`
- Test: `tests/test_whisper_asr_async.php`

- [ ] **Step 1: Port only the legacy functions that affect cues**

  Copy and isolate from `/var/www/html/myai/myai_voice/run_server.py`: `normalize_commas`, `normalize_spacing`, `restore_latin_spaces`, `smart_join`, `get_token_spans`, `adjust_break_index_for_word_boundary`, `apply_zh_segment_punctuation`, and the word-based portion of `split_segments`. Keep the legacy maximum duration (6.0 seconds), maximum characters (28), candidate pause (0.35 seconds), and CKIP headroom (4 GiB).

- [ ] **Step 2: Use the old adaptive decision at one narrow call site**

  Implement this single resolver, with no new service or Hub table:

  ```python
  def chinese_tokens(text: str, ckip_model_dir: Path | None) -> tuple[str, list[str]]:
      if ckip_model_dir is not None and free_vram_bytes() >= 4 * 1024**3:
          try:
              from ckip_transformers.nlp import CkipWordSegmenter
              model = CkipWordSegmenter(model_name=str(ckip_model_dir), device=0)
              return 'ckip', [item.strip() for item in model([text])[0] if item.strip()]
          except Exception:
              pass
      import jieba
      return 'jieba', [item.strip() for item in jieba.lcut(text, cut_all=False) if item.strip()]
  ```

  Cache the one CKIP runner for the current container process; never cache a failure. The `except` is intentionally narrow to this optional quality step, because the established fallback is part of the old behavior. Include `# ponytail: CKIP is a single Job-local cache; split it only if a future Pack shares it.` beside that cache.

- [ ] **Step 3: Return cue data and diagnostics only**

  `reflow_legacy_segments(segments, language, ckip_model_dir)` returns `(cues, breaker)`. Cues retain `start`, `end`, `text`, optional `speaker`, and optional `words`; their time range is always derived from the selected first/last word. Apply OpenCC `s2twp` only for `zh`, `nan`, or auto-detected Chinese. Never call the network or load ASR/alignment inside this module.

- [ ] **Step 4: Run the focused test and confirm all reflow cases pass**

  Run: `php tests/test_whisper_asr_async.php`

  Expected: PASS for CKIP success, low-VRAM/failed-CKIP jieba fallback, punctuation, timestamp reconstruction, and existing preflight tests.

- [ ] **Step 5: Commit the module**

  ```bash
  git add packs/whisper-asr/service/subtitle_reflow.py tests/test_whisper_asr_async.php
  git commit -m "feat: add adaptive legacy subtitle reflow"
  ```

### Task 4: Connect the profile to the Pack and MyAI

**Files:**
- Modify: `packs/whisper-asr/service/job.py:101-267`
- Modify: `packs/whisper-asr/service/requirements.txt`
- Modify: `packs/whisper-asr/pack.json`
- Modify: `/var/www/html/myai/myai_voice/voice_aihub.php:166-176`
- Test: `tests/test_whisper_asr_async.php`

- [ ] **Step 1: Add the Pack input and conditional CKIP mount**

  Add `subtitle_reflow` to fields/schema with default `none`; add a cache asset descriptor that activates only for `legacy_adaptive_v1` and requires:

  ```json
  {
    "id": "whisper_ckip_word_segmenter",
    "storage": "cache",
    "host_subdir": "whisper/ckip/bert-base-chinese-ws",
    "container_path": "/cache/whisper/ckip/bert-base-chinese-ws",
    "required_paths": [".aihub-ckip-ready.json", "config.json", "model.safetensors", "vocab.txt"],
    "when": {"input": "subtitle_reflow", "equals": "legacy_adaptive_v1"}
  }
  ```

  Its marker must exactly match `ckip_cache_manifest()`. Bump Pack version and all runner/build image references from `0.1.0` to `0.1.1`.

- [ ] **Step 2: Preserve the model-load matrix in `job.py`**

  Validate `subtitle_reflow` and reject `legacy_adaptive_v1` unless SRT or VTT is requested. Use native word timestamps only when `word_timestamps=true` or this profile is selected. Call WhisperX alignment only when `word_timestamps=true`; then invoke reflow only when profile is selected. If the caller did not request words, remove them from transcript JSON after cue construction.

  Add these report fields:

  ```python
  "subtitle_reflow_profile": subtitle_reflow,
  "subtitle_breaker": breaker if subtitle_reflow != "none" else None,
  "timing_source": "whisperx_aligned_words" if word_timestamps else "faster_whisper_native_words",
  ```

- [ ] **Step 3: Pin the existing proven dependencies**

  Append to `requirements.txt`:

  ```text
  ckip-transformers==0.3.4
  transformers==4.57.6
  jieba==0.42.1
  wordsegment==1.3.1
  opencc-python-reimplemented==0.1.7
  ```

  The first two pins are the same versions installed in `/park/conda_vm/faster-whisper`, alongside Torch 2.8.0+cu128, WhisperX 3.8.5, and faster-whisper 1.2.1.

- [ ] **Step 4: Make MyAI explicitly preserve its old behavior**

  In `myai_voice_aihub_submit_transcribe()`, add the fixed safe field beside its existing SRT/VTT fields:

  ```php
  'subtitle_reflow' => 'legacy_adaptive_v1',
  ```

  It is a mode-scoped Pack contract parameter, not a Pack entrypoint or a free-form command.

- [ ] **Step 5: Run regression tests**

  Run: `php tests/test_whisper_asr_async.php && php scripts/run_tests.php`

  Expected: both exit 0. In particular, ASR always loads, alignment only loads for `word_timestamps=true`, pyannote only loads for `diarization=true`, and CKIP only mounts for `legacy_adaptive_v1`.

- [ ] **Step 6: Commit the integration**

  ```bash
  php -l /var/www/html/myai/myai_voice/voice_aihub.php
  git add packs/whisper-asr/service/job.py packs/whisper-asr/service/requirements.txt packs/whisper-asr/pack.json tests/test_whisper_asr_async.php
  git commit -m "feat: use adaptive CKIP subtitle reflow"
  ```

  `/var/www/html/myai` is a deployed companion directory rather than part of the Hub Git worktree. Record its one-line deployment change in its own deployment log; do not try to add that path to the Hub commit.

### Task 5: Build and verify on the RTX 5060 Ti

**Files:**
- Modify: none
- Test: built image and `scripts/audio_packs_acceptance.php`

- [ ] **Step 1: Build the versioned runner and compile its entrypoints**

  ```bash
  sudo docker build --tag 3waaihub/whisper-asr:0.1.1 \
    --file packs/whisper-asr/service/Dockerfile packs/whisper-asr
  sudo docker run --rm 3waaihub/whisper-asr:0.1.1 \
    python3 -m py_compile /app/speech-transcribe /app/subtitle_reflow.py
  ```

  Expected: both exit 0 with the CUDA 12.8 Torch layer.

- [ ] **Step 2: Provision the exact offline assets**

  ```bash
  sudo env AIHUB_MODELS_DIR=/DATA/models AIHUB_CACHE_DIR=/park/3waAIHub/data/cache \
    AIHUB_WHISPER_PROVISION_CKIP=1 \
    bash packs/whisper-asr/jobs/provision_offline_models.sh
  ```

  Expected: large-v3, `en` alignment, and CKIP markers exist. Do not provision pyannote unless the acceptance run explicitly tests diarization.

- [ ] **Step 3: Run one quality and one pressure observation before deciding a static default**

  Submit the same MyAI fixture twice: once normally, and once while an allowed GPU workload leaves under 4 GiB free. Compare cue boundaries and record `subtitle_breaker` plus elapsed seconds from `transcription_report`. Do not kill unmanaged GPU processes to manufacture pressure.

- [ ] **Step 4: Run Hub real acceptance and commit source only after it passes**

  ```bash
  php scripts/audio_packs_acceptance.php \
    --base-url=http://127.0.0.1/3waAIHub/api.php \
    --token="$AIHUB_ACCEPTANCE_TOKEN" \
    --pack=whisper-asr \
    --fixture=/park/3waAIHub/packs/whisper-asr/demo/sample.wav \
    --timeout=7200
  ```

  Expected: common GPU lease acquired once, CKIP or jieba reported truthfully, transcript/SRT/VTT artifacts validate and download, container/PID clean-up completes, and the GPU lease is released. Do not add generated models, tasks, token material, or the user's existing untracked TTS plan to Git.

  ```bash
  git add packs/whisper-asr/service/subtitle_reflow.py packs/whisper-asr/service/offline_paths.py packs/whisper-asr/service/provision_offline_assets.py packs/whisper-asr/jobs/provision_offline_models.sh packs/whisper-asr/service/job.py packs/whisper-asr/service/requirements.txt packs/whisper-asr/pack.json tests/test_whisper_asr_async.php
  git commit -m "feat: deploy adaptive CKIP subtitle reflow"
  ```

## Self-review

- CKIP quality, known 4 GiB guard, jieba fallback, offline verified model data, no arbitrary URLs, generic Pack route/lease preservation, artifact lineage/versioning, and MyAI opt-in are all explicitly covered.
- The plan makes no Hub-Core schema or worker change: the existing asset-mount condition is sufficient.
- The only new heavyweight model is mounted only for the declared reflow profile; plain ASR, alignment, and diarization retain their existing independently-controlled load paths.
