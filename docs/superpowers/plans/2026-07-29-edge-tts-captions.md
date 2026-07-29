# Edge TTS Captions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add opt-in VTT, SRT, and word/sentence timeline artifacts to the existing asynchronous Edge TTS Pack without changing its MP3 API or making another provider request.

**Architecture:** `include_subtitles` is a manifest-declared boolean defaulting to `false`. The normal path keeps `save_sync()` and publishes only MP3 plus metadata; the opt-in path consumes `edge_tts.Communicate.stream_sync()` once, writes its audio chunks, turns the provider's boundary events into three bounded artifacts, and leaves metadata free of submitted text. Existing Pack-job artifact conditions, public API documentation, and Cluster contract publication already derive from the manifest, so Hub Core and Cluster routing need no code change.

**Tech Stack:** Python 3.13, pinned `edge-tts==7.2.6`, Python `unittest`, PHP Pack contract tests, Docker, existing Pack-job artifacts.

---

## File Structure

- Modify: `packs/edge-tts/service/synthesize.py` — validate the boolean opt-in; consume one boundary stream; atomically write MP3, VTT, SRT, and timeline JSON.
- Modify: `packs/edge-tts/service/test_synthesize.py` — offline fake-stream coverage for opt-out, all three outputs, and malformed timing cleanup.
- Modify: `packs/edge-tts/pack.json` — declare the request flag and conditional artifact contract.
- Modify: `tests/test_edge_tts_pack.php` — verify manifest normalization, Gateway queue snapshot, generated public/Cluster service contract, artifact conditions, and documentation promises.
- Modify: `packs/edge-tts/README.md` — document the one-flag request and text-retention consequence.
- Modify: `README.md` — replace the deferred-V2 statement with the shipped additive contract.
- Modify: `docs/operations/edge-tts-real-smoke.md` — let the explicit real smoke validate caption artifacts without printing their contents.

No application PHP, schema, service, firewall, Dockerfile, dependency, or Playground change is required. The pinned image already contains `stream_sync()` and `SubMaker`; this plan uses a small local formatter because captions use sentence events while the timeline retains both sentence and word events.

### Task 1: Specify and implement the offline streaming runner

**Files:**
- Modify: `packs/edge-tts/service/test_synthesize.py:18-130`
- Modify: `packs/edge-tts/service/synthesize.py:17-156`
- Test: `packs/edge-tts/service/test_synthesize.py`

- [ ] **Step 1: Add failing opt-in stream fixtures and assertions**

  Change `request()` to include `"include_subtitles": False`. Add a fake whose `stream_sync()` returns one MP3 payload plus both kinds of timing event:

  ```python
  class StreamingCommunicate:
      def __init__(self, *args, **kwargs):
          pass

      def stream_sync(self):
          yield {"type": "audio", "data": b"ID3fake-edge-tts"}
          yield {"type": "SentenceBoundary", "offset": 0, "duration": 15_000_000, "text": "Hello world."}
          yield {"type": "WordBoundary", "offset": 0, "duration": 5_000_000, "text": "Hello"}
          yield {"type": "WordBoundary", "offset": 5_000_000, "duration": 10_000_000, "text": "world."}
  ```

  Add `test_subtitle_opt_in_writes_all_derived_artifacts_once`. Patch `Communicate` with this fake, call `run_job()` with `include_subtitles=True`, and assert the exact files and values:

  ```python
  self.assertEqual((self.output_dir / "subtitle.vtt").read_text(), "WEBVTT\n\n00:00:00.000 --> 00:00:01.500\nHello world.\n")
  self.assertEqual((self.output_dir / "subtitle.srt").read_text(), "1\n00:00:00,000 --> 00:00:01,500\nHello world.\n")
  self.assertEqual(json.loads((self.output_dir / "speech_timeline.json").read_text()), {
      "version": 1, "unit": "milliseconds", "duration_ms": 1500,
      "sentences": [{"text": "Hello world.", "start_ms": 0, "end_ms": 1500}],
      "words": [
          {"text": "Hello", "start_ms": 0, "end_ms": 500},
          {"text": "world.", "start_ms": 500, "end_ms": 1500},
      ],
  })
  ```

  Extend the existing non-caption success test with `assertFalse((self.output_dir / "subtitle.vtt").exists())`, and add an invalid-stream fake with a negative `duration`. That test must raise `RunnerError("edge_tts_failed")` and leave `output` empty, including no temporary audio file.

- [ ] **Step 2: Run the runner test and confirm the new test fails**

  Run:

  ```bash
  python3 packs/edge-tts/service/test_synthesize.py
  ```

  Expected: the new test fails because the request allowlist rejects `include_subtitles` and the runner still calls `save_sync()`.

- [ ] **Step 3: Implement the smallest single-stream caption path**

  In `synthesize.py`, add `include_subtitles` to `ALLOWED_REQUEST`; require it to be an actual `bool`; and return it from `validate_request()` beside the existing normalized controls. Keep `save_sync()` unchanged when it is false.

  For the true branch, use exactly one `Communicate(...).stream_sync()` call. Write only `chunk["data"]` from `type == "audio"` into `.generated_audio.mp3.tmp`. Convert only `WordBoundary` and `SentenceBoundary` chunks with this normalization:

  ```python
  TICKS_PER_MILLISECOND = 10_000
  MAX_CAPTION_BYTES = 512 * 1024

  def boundary_entry(chunk: dict[str, Any]) -> dict[str, int | str]:
      offset, duration, text = chunk.get("offset"), chunk.get("duration"), chunk.get("text")
      if not isinstance(offset, int) or isinstance(offset, bool) or offset < 0:
          fail("edge_tts_failed")
      if not isinstance(duration, int) or isinstance(duration, bool) or duration <= 0:
          fail("edge_tts_failed")
      if not isinstance(text, str) or text == "":
          fail("edge_tts_failed")
      start_ms = offset // TICKS_PER_MILLISECOND
      end_ms = (offset + duration + TICKS_PER_MILLISECOND - 1) // TICKS_PER_MILLISECOND
      if end_ms <= start_ms:
          end_ms = start_ms + 1
      return {"text": text, "start_ms": start_ms, "end_ms": end_ms}
  ```

  Keep word and sentence lists separately. Require at least one sentence and one word; require each list to be monotonic (`current.start_ms >= previous.end_ms`); render VTT/SRT from sentence entries; and set timeline `duration_ms` to the greatest `end_ms`. Use a small local timestamp formatter (`HH:MM:SS.mmm` for VTT and `HH:MM:SS,mmm` for SRT), not a new dependency or a second provider call.

  Add one atomic `write_text_artifact(path, text, max_bytes)` helper parallel to `write_metadata()`: reject a symlink/non-regular target, UTF-8 encode, enforce `MAX_CAPTION_BYTES`, write a sibling dot-temp file, then replace. Before each job remove the three final captions and all dot-temp files. On every synthesis or validation exception, remove all finals and temps so a failed task cannot publish partial text.

- [ ] **Step 4: Run the runner test and confirm all offline cases pass**

  Run:

  ```bash
  python3 packs/edge-tts/service/test_synthesize.py
  ```

  Expected: PASS. The existing MP3/metadata case retains its exact metadata key set and still contains no submitted text; opt-in adds exactly the three caption files; malformed boundary data leaves no artifacts.

- [ ] **Step 5: Commit the runner slice**

  ```bash
  git add packs/edge-tts/service/synthesize.py packs/edge-tts/service/test_synthesize.py
  git commit -m "feat: add edge tts caption artifacts"
  ```

### Task 2: Declare the additive API and output contract

**Files:**
- Modify: `tests/test_edge_tts_pack.php:104-149,194-211,256-286,314-336`
- Modify: `packs/edge-tts/pack.json:33-86`
- Test: `tests/test_edge_tts_pack.php`

- [ ] **Step 1: Add failing PHP contract assertions**

  Update the exact request-schema expectation to include:

  ```php
  'include_subtitles' => [
      'type' => 'boolean',
      'required' => false,
      'default' => false,
  ],
  ```

  Expect `input_fields` to end in `include_subtitles`; expect normalizing `['text' => 'Taiwan Edge TTS']` to persist `include_subtitles => false`; and assert that `['text' => 'Taiwan Edge TTS', 'include_subtitles' => 'true']` normalizes to `true` while `include_subtitles => 'yes'` is rejected.

  Add three conditional artifact expectations after the existing MP3 and metadata artifacts:

  ```php
  [
      'type' => 'subtitle_vtt', 'path' => 'subtitle.vtt',
      'mime_types' => ['text/plain', 'text/vtt'], 'max_bytes' => 524288,
      'when' => ['input' => 'include_subtitles', 'equals' => true],
      'text' => ['max_bytes' => 524288],
  ],
  [
      'type' => 'subtitle_srt', 'path' => 'subtitle.srt',
      'mime_types' => ['text/plain', 'application/x-subrip', 'text/x-subrip', 'text/srt'], 'max_bytes' => 524288,
      'when' => ['input' => 'include_subtitles', 'equals' => true],
      'text' => ['max_bytes' => 524288],
  ],
  [
      'type' => 'speech_timeline', 'path' => 'speech_timeline.json',
      'mime_types' => ['application/json'], 'max_bytes' => 524288,
      'when' => ['input' => 'include_subtitles', 'equals' => true],
      'json' => ['required_keys' => ['version', 'unit', 'duration_ms', 'sentences', 'words']],
  ],
  ```

  In the authorized-Gateway test, queue an opt-in request and assert its stored `input_json` contains the normalized boolean. In the public service assertion, locate the `include_subtitles` field in `input_fields` and assert it is optional, boolean, and defaults to false. This covers the same generated contract Cluster publishes; no separate Cluster code path needs changing.

- [ ] **Step 2: Run the Pack PHP test and confirm it fails**

  Run:

  ```bash
  php scripts/run_tests.php --suite=full
  ```

  Expected: the Edge TTS assertions fail because the Pack manifest has no caption input or output declarations. Record any pre-existing unrelated failures separately; do not weaken the new Edge TTS assertions.

- [ ] **Step 3: Add the manifest declarations without a Hub-Core change**

  In `packs/edge-tts/pack.json`, append `include_subtitles` to `input.fields` and add this request-schema entry:

  ```json
  "include_subtitles": {"type": "boolean", "required": false, "default": false}
  ```

  Append the three artifact declarations from Step 1 to `output.artifacts`. The detected MIME type for both representative VTT and SRT payloads is `text/plain` on this station, so retain `text/plain` in both allowlists; the additional standards MIME values preserve portability. Do not change the existing MP3 or metadata artifacts, runner image, timeout, egress profile, queue, or runtime level.

- [ ] **Step 4: Run the Pack PHP test and manifest scan**

  Run:

  ```bash
  php scripts/run_tests.php --suite=full
  php -r 'require "app/bootstrap.php"; $pack = hub_get_pack("edge-tts"); exit(is_array($pack) && ($pack["status"] ?? "") === "ok" ? 0 : 1);'
  ```

  Expected: all Edge TTS contract, Gateway, generated public/Cluster field, and artifact-condition assertions pass; the manifest scan exits 0.

- [ ] **Step 5: Commit the contract slice**

  ```bash
  git add packs/edge-tts/pack.json tests/test_edge_tts_pack.php
  git commit -m "feat: declare edge tts subtitle contract"
  ```

### Task 3: Publish the API, privacy, and real-smoke guidance

**Files:**
- Modify: `packs/edge-tts/README.md:13-40`
- Modify: `README.md:1537-1547`
- Modify: `docs/operations/edge-tts-real-smoke.md:49-142`
- Modify: `tests/test_edge_tts_pack.php:338-370`
- Test: `tests/test_edge_tts_pack.php`

- [ ] **Step 1: Add failing documentation assertions**

  Replace the deferred-V2 regex assertion with checks that the root and Pack guides contain `include_subtitles`, `subtitle.vtt`, `subtitle.srt`, `speech_timeline.json`, and the phrase `contain the submitted text`. Extend the smoke-document assertion list with those three artifact names and `include_subtitles`.

- [ ] **Step 2: Update operator-facing documentation**

  In `packs/edge-tts/README.md`, keep the default curl example unchanged and add this second additive payload:

  ```json
  {"text":"\\u9019\\u662f\\u4e00\\u6bb5\\u975e\\u6a5f\\u5bc6\\u7684\\u4e2d\\u6587\\u5408\\u6210\\u3002","voice":"zh-TW-HsiaoChenNeural","include_subtitles":true}
  ```

  State that it produces `subtitle.vtt`, `subtitle.srt`, and `speech_timeline.json` from one synthesis stream, and that all three contain submitted text and follow normal artifact retention/acknowledgement. Replace the root README sentence about deferred V2 with the same behavior in one concise paragraph.

  Update `docs/operations/edge-tts-real-smoke.md` so its explicit request includes `"include_subtitles":true`. After the existing MP3 validation, select the three owned artifact records by type, download them to the temporary work directory without printing them, and verify:

  ```bash
  grep -Fqx 'WEBVTT' "$WORKDIR/subtitle.vtt"
  grep -Eq '^1$' "$WORKDIR/subtitle.srt"
  php -r '
    $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    foreach (["version", "unit", "duration_ms", "sentences", "words"] as $key) {
      if (!array_key_exists($key, $value)) { throw new RuntimeException("timeline key missing"); }
    }
  ' "$WORKDIR/speech_timeline.json"
  ```

  Keep the existing no-token/no-text/no-artifact-content logging rule. The runbook records only artifact IDs, SHA checks, and boolean validation outcomes.

- [ ] **Step 3: Run documentation and full regression checks**

  Run:

  ```bash
  git diff --check
  php scripts/run_tests.php --suite=full
  ```

  Expected: documentation assertions pass; no new failures occur outside the known baseline failures, if they remain on the station.

- [ ] **Step 4: Commit the documentation slice**

  ```bash
  git add README.md packs/edge-tts/README.md docs/operations/edge-tts-real-smoke.md tests/test_edge_tts_pack.php
  git commit -m "docs: describe edge tts captions"
  ```

### Task 4: Build and perform bounded station acceptance

**Files:**
- Modify: none
- Test: built `3waaihub/edge-tts:0.1.0` image and the documented real smoke

- [ ] **Step 1: Build the existing image tag and run offline checks**

  The service is an on-demand internal task, so `status=stopped` is normal between jobs. Rebuild the existing immutable tag from the Pack-controlled service directory:

  ```bash
  sg docker -c 'docker build --tag 3waaihub/edge-tts:0.1.0 --file packs/edge-tts/service/Dockerfile packs/edge-tts/service'
  bash packs/edge-tts/service/test_egress_firewall.sh
  ```

  Expected: Docker build succeeds, including `python3 -m unittest -v test_synthesize.py`; firewall self-check prints `test_egress_firewall: ok`.

- [ ] **Step 2: Run the opt-in public API smoke only with environment-held credentials**

  Do not read tokens from the database or place them in shell history. When both are already set, run the documented procedure:

  ```bash
  test -n "${AIHUB_EDGE_TTS_BASE_URL:-}"
  test -n "${AIHUB_EDGE_TTS_TOKEN:-}"
  ```

  Then follow `docs/operations/edge-tts-real-smoke.md`. Expected: task succeeds; MP3 is valid; all three caption artifacts exist and validate; acknowledgement succeeds; and no `gpu:0` lease exists. If the fixed provider is unreachable, record the bounded `upstream_unavailable` task result and do not relax egress or substitute a provider.

- [ ] **Step 3: Inspect the final change set before handoff**

  Run:

  ```bash
  git status --short
  git log --oneline --max-count=4
  ```

  Expected: only the three Phase B commits are staged in history; the unrelated `docs/superpowers/specs/2026-07-29-web-screenshot-field-intel-draft.md` remains untracked and untouched. Do not push without a separate user request.

## Self-Review

- Spec coverage: one opt-in boolean, three additive artifacts, shared single synthesis stream, default compatibility, timing normalization, privacy/retention, offline tests, manifest/Gateway/generated Cluster contract, documentation, and station smoke each map to Tasks 1–4.
- Deliberate exclusions: no SSML, streaming endpoint, player UI, batch endpoint, voice cloning, new dependency, Hub schema, firewall change, or L5 promotion work is introduced.
- Type consistency: `include_subtitles` is boolean in the manifest, PHP normalizer, runner request, and artifact conditions; `subtitle_vtt`, `subtitle_srt`, and `speech_timeline` are the same artifact types in tests, manifest, docs, and smoke procedure.
