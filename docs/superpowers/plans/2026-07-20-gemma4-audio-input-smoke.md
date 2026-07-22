# PhaseL-1D Gemma 4 Audio Input Smoke Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Prove `gemma4-main` can accept one short WAV through 3waAIHub Gateway, ask Gemma 4 about it, and return normalized transcription / summary / understanding JSON.

**Architecture:** Keep Gemma 4 inside the existing Hub boundary. Add a Pack-local `/audio` adapter beside `/chat` and `/photo`, then expose one Gateway mode `audio` that proxies multipart upload without storing audio assets. The first gate proves vLLM audio support before any public API work proceeds.

**Tech Stack:** PHP 8 + existing Gateway/token/IP/audit flow; FastAPI adapter in `packs/llm-gemma4-12b/service`; vLLM sidecar; Python stdlib `wave` for WAV validation; `python-multipart` for FastAPI upload parsing.

## Global Constraints

- Do not add `audio_assets`, `audio_id`, session, or long-audio persistence in PhaseL-1D.
- Do not add streaming microphone, timestamps, diarization, VAD, MP3/M4A, multi-audio, Voice RAG, or TTS.
- Do not replace Whisper ASR; Gemma4 audio is for one-shot understanding smoke.
- Public endpoint is only `POST api.php?mode=audio`.
- Request content type is `multipart/form-data`.
- Required upload field is `audio`.
- Supported audio format is WAV only.
- WAV must be 16 kHz, mono, duration `<= 30` seconds, upload `<= 16 MB`.
- Gateway and adapter reject client-supplied path fields: `audio_path`, `file_path`, `host_path`, `container_path`, `audio_url`, `audio_internal_path`.
- Existing `chat` and `photo` smoke / benchmark cases must keep passing.
- If direct vLLM audio proof fails, stop before adding Gateway mode.

---

### Task 1: vLLM Audio Runtime Proof

**Files:**
- Create: `/DATA/3waAIHub/packs/llm-gemma4-12b/scripts/smoke_audio_vllm.py`
- Create: `/DATA/3waAIHub/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav`
- Modify only if proof passes: none

**Interfaces:**
- Consumes: running `gemma4-main` internal vLLM endpoint, default `http://127.0.0.1:<vllm-port>/v1/chat/completions` or Docker network equivalent.
- Produces: a repeatable command that sends `input_audio` base64 WAV to vLLM and exits non-zero on empty output.

- [x] **Step 1: Add the smallest direct vLLM smoke script**

  Create `/DATA/3waAIHub/packs/llm-gemma4-12b/scripts/smoke_audio_vllm.py` with:
  - CLI args: `--base-url`, `--model`, `--audio`.
  - Validate WAV via `wave.open()`: `1` channel, `16000` Hz, `<= 30` sec.
  - Send OpenAI-compatible chat payload:
    - first content item: text instruction in 正體中文
    - second content item: `input_audio` with base64 data and `format: wav`
  - Print JSON with `ok`, `text`, `usage`.
  - Exit `2` for bad WAV, `3` for vLLM HTTP failure, `4` for empty model output.

- [x] **Step 2: Add one tiny committed WAV fixture**

  Add `/DATA/3waAIHub/packs/llm-gemma4-12b/demo/audio_zh_smoke.wav`.
  The fixture must be:
  - WAV PCM
  - mono
  - 16000 Hz
  - 5 to 8 seconds
  - content: `今天下午兩點，請檢查 NSR 的 RC 閥。`
  - small enough for git

- [x] **Step 3: Run direct proof**

  ```bash
  cd /DATA/3waAIHub
  python3 packs/llm-gemma4-12b/scripts/smoke_audio_vllm.py \
    --base-url http://127.0.0.1:<VLLM_PORT> \
    --model gemma4-12b \
    --audio packs/llm-gemma4-12b/demo/audio_zh_smoke.wav
  ```

  Expected: `ok=true`, non-empty `text`, no crash.

- [x] **Step 4: Confirm existing Gemma4 regressions before continuing**

  ```bash
  php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_chat
  php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_photo_general
  ```

  Expected: both PASS. If either fails after enabling audio runtime, stop and fix runtime config before Gateway work.

---

### Task 2: vLLM Audio Runtime Image And Compose

**Files:**
- Create: `/DATA/3waAIHub/packs/llm-gemma4-12b/vllm/Dockerfile`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/docker-compose.yml`
- Modify: `/DATA/3waAIHub/app/pack_registry.php`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/README.md`

**Interfaces:**
- Produces: `gemma4-main` vLLM sidecar with audio extras installed and `--limit-mm-per-prompt '{"image":1,"audio":1}'`.
- Consumes: current `VLLM_MODEL`, `VLLM_SERVED_MODEL_NAME`, `VLLM_MAX_MODEL_LEN`, `VLLM_GPU_MEMORY_UTILIZATION`, `VLLM_MAX_NUM_SEQS`.

- [x] **Step 1: Lock vLLM extras to the working runtime version**

  Add Dockerfile that starts from the same vLLM image used today and installs audio extras for the exact installed vLLM version. Do not guess a version string in PHP.

- [x] **Step 2: Preserve photo support while adding audio**

  In static compose and generated compose, keep the existing vLLM serve command and add only:

  ```bash
  --limit-mm-per-prompt '{"image":1,"audio":1}'
  ```

- [x] **Step 3: Rebuild and run config checks**

  ```bash
  docker compose -f data/services/gemma4-main/docker-compose.generated.yml config >/tmp/gemma4-compose.yml
  docker compose -f data/services/gemma4-main/docker-compose.generated.yml build vllm
  docker compose -f data/services/gemma4-main/docker-compose.generated.yml up -d
  ```

  Expected: vLLM starts, `/health` ready, `chat` and `photo` still work.

---

### Task 3: Pack-local `/audio` Adapter

**Files:**
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/service/requirements.txt`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/service/app.py`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Produces: `POST /audio` on the Pack adapter.
- Request fields: `audio`, `operation`, `text`, `max_tokens`, `real_inference`.
- Response keys: `ok`, `mock`, `runtime_level`, `model`, `operation`, `answer`, `transcript`, `summary`, `tags`, `audio`, `usage`, `elapsed_ms`.

- [x] **Step 1: Add adapter tests first**

  Create `/DATA/3waAIHub/tests/test_gemma4_audio.php` assertions for:
  - `pack.json` contains `audio_contract`.
  - service app contains `@app.post("/audio")`.
  - service app rejects non-WAV / stereo / non-16kHz / over-30s paths.
  - response contract includes `transcript`, `summary`, and `answer` even when null.

- [x] **Step 2: Add upload dependency**

  Add to requirements:

  ```text
  python-multipart==0.0.20
  ```

- [x] **Step 3: Implement WAV validation with stdlib**

  In `app.py`, use `wave.open()` on uploaded bytes. Reject:
  - empty file: `file_required`
  - size over 16 MB: `payload_too_large`
  - invalid WAV: `invalid_audio`
  - sample rate not 16000: `unsupported_audio_format`
  - channels not 1: `unsupported_audio_format`
  - duration over 30 seconds: `audio_too_long`

- [x] **Step 4: Implement mock path**

  If `real_inference` is false, return a deterministic mock response with complete contract keys and `mock=true`.

- [x] **Step 5: Implement real path**

  For `operation=transcribe`, prompt the model to output only a transcript and wrap it as:
  - `transcript=<model text>`
  - `answer=null`
  - `summary=null`
  - `tags=[]`

  For `operation=understand` and `operation=summarize`, ask for JSON and parse with existing `parse_model_json()`.

- [x] **Step 6: Compile Python**

  ```bash
  python3 -m py_compile packs/llm-gemma4-12b/service/*.py
  ```

  Expected: no syntax errors.

---

### Task 4: Gateway `mode=audio`

**Files:**
- Modify: `/DATA/3waAIHub/app/gateway.php`
- Modify: `/DATA/3waAIHub/app/customer_accounts.php`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Produces: `POST api.php?mode=audio` protected by existing token/IP/request logging flow.
- Consumes: existing `hub_proxy_request()` multipart forwarding and `hub_photo_vision_service_for_request()` style readiness check.

- [x] **Step 1: Add Gateway tests**

  Assert:
  - `audio` is recognized as special Gemma4 mode.
  - `GET mode=audio` returns 405.
  - missing upload returns `file_required`.
  - path fields in `$_POST` return `bad_request`.
  - customer mode list can include `audio`.

- [x] **Step 2: Add dispatch helpers**

  Add minimal helpers:
  - `hub_is_audio_api_mode(string $mode): bool`
  - `hub_audio_api_dispatch(PDO $db, string $mode, array $authContext): array`
  - `hub_api_audio(PDO $db, array $authContext): array`
  - `hub_audio_normalize_proxy_response(array $response): array`

- [x] **Step 3: Reuse existing multipart proxy**

  Use `hub_proxy_request()` with no custom file client. Set adapter URL by replacing `/chat` with `/audio` on `gemma4-main` service internal URL.

- [x] **Step 4: Keep audio ephemeral**

  Do not create DB tables, upload directories, TTL logic, or artifact records.

---

### Task 5: Manifest, Benchmarks, Docs, Playground

**Files:**
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/pack.json`
- Modify: `/DATA/3waAIHub/app/benchmarks.php`
- Modify: `/DATA/3waAIHub/admin/playground.php`
- Modify: `/DATA/3waAIHub/app/public_api_docs.php`
- Modify: `/DATA/3waAIHub/docs/api_examples.md`
- Modify: `/DATA/3waAIHub/docs/client_quickstart.md`
- Modify: `/DATA/3waAIHub/README.md`
- Modify: `/DATA/3waAIHub/history.md`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Produces benchmark cases: `gemma4_mock_audio`, `gemma4_real_audio_transcribe_zh`, `gemma4_real_audio_understand`.

- [x] **Step 1: Update Pack manifest**

  Add capabilities:

  ```json
  ["chat", "reasoning", "vision", "audio_understanding", "audio_transcription"]
  ```

  Add `audio_contract` with endpoint `/audio`, `multipart/form-data`, WAV limits, output keys, and error codes.

- [x] **Step 2: Add benchmark cases**

  Add:
  - `gemma4_mock_audio`
  - `gemma4_real_audio_transcribe_zh`
  - `gemma4_real_audio_understand`

  Transcribe acceptance: normalized output matches at least two of `下午兩點`, `RC`, `檢查`.

  Understand acceptance: normalized output mentions both concepts `停止運轉` and `通知維護`.

- [x] **Step 3: Add Playground input**

  Add `mode=audio` form:
  - audio file input
  - operation select: `understand`, `transcribe`, `summarize`
  - text prompt
  - max tokens
  - real inference checked by default

- [x] **Step 4: Update public docs**

  Add curl example:

  ```bash
  curl -X POST "<BASE_URL>?mode=audio" \
    -H "Authorization: Bearer <TOKEN>" \
    -F "audio=@sample.wav" \
    -F "operation=understand" \
    -F "text=這段錄音的重點是什麼？" \
    -F "real_inference=1"
  ```

- [x] **Step 5: Run full regression**

  ```bash
  php scripts/run_tests.php
  php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
  php scripts/token_api_smoke.php
  find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
  bash -n install.sh scripts/*.sh crontab/*.sh
  node --check assets/js/services.js assets/js/packs.js assets/js/playground.js
  python3 -m py_compile packs/llm-gemma4-12b/service/*.py
  git diff --check
  ```

  Expected: all pass.

---

## Manual Runtime Acceptance

Run only after Docker rebuild succeeds:

```bash
php scripts/benchmark.php --service=gemma4-main --case=gemma4_mock_chat
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_chat
php scripts/benchmark.php --service=gemma4-main --case=gemma4_mock_photo
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_photo_general
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_photo_ui
php scripts/benchmark.php --service=gemma4-main --case=gemma4_mock_audio
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_audio_transcribe_zh
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_audio_understand
```

## Explicitly Skipped

- `audio_assets` / `audio_id`
- long audio chunking
- persisted audio reuse
- MP3/M4A conversion
- timestamps
- speaker diarization
- VAD
- streaming microphone
- Voice RAG
- TTS chaining
- replacing `whisper-asr`
- industrial abnormal-sound quality claims

## Self-Review

- Spec coverage: includes runtime proof, adapter, Gateway, manifest, benchmark, docs, and regression gates.
- Scope check: one service, one endpoint, no storage model; safe for one implementation phase.
- Placeholder scan: no TBD/TODO placeholders; every deferred item is listed under Explicitly Skipped.
- Type consistency: endpoint names are `audio`, `/audio`, and benchmark IDs use `gemma4_*_audio`.
