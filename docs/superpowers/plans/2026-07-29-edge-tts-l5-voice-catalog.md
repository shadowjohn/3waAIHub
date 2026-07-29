# Edge TTS L5 Voice Catalogue Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Promote the Edge TTS HubPack to L5 with a verified 14-voice Taiwan/China/Hong Kong catalogue, installer-generated MP3 demos, authenticated list/demo delivery on both local and Cluster API routes, and a real public-API acceptance client.

**Architecture:** `voice_catalog.json` is the immutable Pack source of truth. The controlled Edge TTS container uses it for both synthesis admission and sequential demo generation. Installation runs that same image once with the existing container-local fail-closed egress bootstrap, validates the generated availability record, and atomically publishes only verified MP3s beneath `HUB_DATA_DIR/results`. The existing `edge_tts` mode remains the only public route: POST queues synthesis; GET lists verified voices or streams a verified demo. L5 acceptance is intentionally external to the offline benchmark runner and records only redacted observations.

**Tech Stack:** PHP 8 + SQLite, existing HubPack/Task/Cluster gateway, Docker + Bash + iptables/ip6tables bootstrap, Python 3 + `edge-tts`, PHPUnit-style in-repository test runner, curl and ffprobe for operator acceptance.

## Global Constraints

- Preserve `POST api.php?mode=edge_tts` and its existing asynchronous task/artifact contract unchanged except for the 14 approved static voice IDs.
- Add no host iptables rule, host firewall change, Docker daemon change, Docker network change, legacy-installer change, provider credential, or user-selectable provider/style control.
- Demo generation uses the existing Edge TTS image with `NET_ADMIN` only during its trusted entrypoint firewall setup; it must then run `/app/generate_demos.py` as the non-root `edge` user with all capabilities removed.
- Keep the entrypoint fail-closed: missing DNS resolution, firewall setup, ACL verification, capability finalisation, or generator failure must fail installation. Do not weaken the existing provider-only egress policy.
- Do not copy MP3s from `/var/www/html/admin/avatars/` or `/var/www/html/demo/php/easy_podcast/demo_voice/` into this Pack. Copy only the approved catalogue metadata/text from `/var/www/html/demo/php/easy_podcast/demo_voice.py` into a repository-owned static JSON source.
- Publish only MP3s that the installer independently verifies as regular, bounded files whose SHA-256 and byte count match the generated availability record. A symlink, missing file, malformed record, unexpected ID, duplicate, mismatch, or stale data is unavailable.
- A partial demo generation result is valid; all candidates failing is `edge_tts_demo_initialization_failed`, must leave an existing published catalogue untouched, and must not create/update the service database record or version.
- GET list/demo calls require the same `edge_tts` token permission, normal installed/enabled/running service state, and normal Cluster routing. Never put a token in `demo_url`, response body, stored availability JSON, logs, benchmark rows, or documentation examples.
- Demo URLs are the exact relative query form `?mode=edge_tts&voice=<urlencoded-id>` so they resolve against either `api.php` or `cluster_api.php`.
- Keep generated demo content within `data/results/edge-tts-demos/<service-key>/current/`; do not widen `hub_artifact_safe_path()` beyond the existing results root.
- New PHP files under `/var/www/html` must be mode `0755`; new web-visible directories must be `0755`. Preserve the unrelated untracked `docs/superpowers/specs/2026-07-29-web-screenshot-field-intel-draft.md`.
- PONYTAIL applies: add the explicit Edge TTS branch needed here, not a generic Pack lifecycle/hook system.

## Approved Static Catalogue

The JSON catalogue contains exactly these source profiles, preserving `name`, `locale`, `gender`, `note`, `text`, and fixed MP3 filename from `demo_voice.py`. Its JSON keys are `id`, `display_name`, `locale`, `gender`, `memo`, `demo_text`, and `demo_file`.

| ID | display_name | locale | gender | demo_file |
| --- | --- | --- | --- | --- |
| `zh-TW-HsiaoChenNeural` | 小晴 | `zh-TW` | `female` | `01_tw_xiaoqing_hsiaochen.mp3` |
| `zh-TW-HsiaoYuNeural` | 阿岑 | `zh-TW` | `female` | `02_tw_acen_hsiaoyu.mp3` |
| `zh-TW-YunJheNeural` | 阿哲 | `zh-TW` | `male` | `03_tw_azhe_yunjhe.mp3` |
| `zh-CN-XiaoxiaoNeural` | 曉曉 | `zh-CN` | `female` | `04_cn_xiaoxiao.mp3` |
| `zh-CN-XiaoyiNeural` | 小藝 | `zh-CN` | `female` | `05_cn_xiaoyi.mp3` |
| `zh-CN-YunjianNeural` | 雲健 | `zh-CN` | `male` | `06_cn_yunjian.mp3` |
| `zh-CN-YunxiNeural` | 雲希 | `zh-CN` | `male` | `07_cn_yunxi.mp3` |
| `zh-CN-YunxiaNeural` | 雲夏 | `zh-CN` | `male` | `08_cn_yunxia.mp3` |
| `zh-CN-YunyangNeural` | 雲揚 | `zh-CN` | `male` | `09_cn_yunyang.mp3` |
| `zh-CN-liaoning-XiaobeiNeural` | 小北 | `zh-CN-liaoning` | `female` | `10_cn_liaoning_xiaobei.mp3` |
| `zh-CN-shaanxi-XiaoniNeural` | 小妮 | `zh-CN-shaanxi` | `female` | `11_cn_shaanxi_xiaoni.mp3` |
| `zh-HK-HiuGaaiNeural` | 嘉嘉 | `zh-HK` | `female` | `12_hk_hiugaai.mp3` |
| `zh-HK-HiuMaanNeural` | 漫漫 | `zh-HK` | `female` | `13_hk_hiumaan.mp3` |
| `zh-HK-WanLungNeural` | 阿龍 | `zh-HK` | `male` | `14_hk_wanlung.mp3` |

The earlier provisional English voices are intentionally removed. `memo` is a curated listening note only; it is not an upstream style capability or a synthesis instruction.

## File Structure

```text
packs/edge-tts/
├── pack.json                                  # L5 contract, GET+POST declaration, 14-ID enum
├── README.md                                  # list/demo/operator contract
└── service/
    ├── Dockerfile                             # includes catalogue/generator tests
    ├── edge-tts-entrypoint.sh                 # trusted normal/demo ACL branches
    ├── synthesize.py                          # reads static catalogue instead of a literal set
    ├── generate_demos.py                      # sequential, bounded demo producer
    ├── test_egress_firewall.sh                # demo branch capability/ACL assertions
    ├── test_synthesize.py                     # catalogue admission regression
    ├── test_generate_demos.py                 # partial/all-failure generator tests
    └── voice_catalog.json                     # the immutable 14-profile source

app/
├── bootstrap.php                              # load Edge TTS voice helpers before gateway dispatch
├── edge_tts_voices.php                        # catalogue, install, availability, GET dispatch
├── gateway.php                                # authenticated GET branch + safe cache header proxying
├── pack_registry.php                          # explicit Edge TTS install initializer call/result
├── public_api_docs.php                        # expose and render dual operations
└── cluster_router.php                         # preserve/render declared extra operations

admin/
├── packs.php                                  # show Edge TTS demo success/failure counts
└── marketplace.php                            # show the same summary for marketplace install

scripts/
└── edge_tts_acceptance.php                    # env-only real L5 acceptance client

tests/
├── test_edge_tts_pack.php                     # Pack, installer, GET, and public-doc tests
├── test_cluster_router.php                    # remote/self Cluster binary + header coverage
└── test_benchmark.php                         # L5 readiness and offline-case refusal

README.md
docs/api_examples.md
docs/operations/edge-tts-real-smoke.md
```

---

## Task 1: Make the container own one strict 14-voice source and demo generator

**Files:**
- Create: `packs/edge-tts/service/voice_catalog.json`
- Create: `packs/edge-tts/service/generate_demos.py`
- Create: `packs/edge-tts/service/test_generate_demos.py`
- Modify: `packs/edge-tts/service/synthesize.py`
- Modify: `packs/edge-tts/service/edge-tts-entrypoint.sh`
- Modify: `packs/edge-tts/service/test_egress_firewall.sh`
- Modify: `packs/edge-tts/service/test_synthesize.py`
- Modify: `packs/edge-tts/service/Dockerfile`
- Modify: `tests/test_edge_tts_pack.php`

- [ ] Add failing Python tests first. They must import the generator, replace `edge_tts.Communicate` with a recording fake, and assert every catalogue item is attempted at the fixed neutral controls:

  ```python
  self.assertEqual(fake.calls, [
      (entry["demo_text"], entry["id"], "+0%", "+0%", "+0Hz")
      for entry in catalogue
  ])
  self.assertEqual(availability["version"], 1)
  self.assertEqual({item["id"] for item in availability["voices"]}, successful_ids)
  ```

  Test one failure continues and publishes only the successful records, a zero-success run exits non-zero with exactly `AIHUB_ERROR_CODE=edge_tts_demo_initialization_failed`, and every written MP3 is a regular non-empty file at or under `1 MiB` with the declared SHA-256 and byte count. Do not contact Edge in tests.

- [ ] Add a failing PHP Pack test that decodes `voice_catalog.json` and asserts exactly the approved 14 IDs, exact filename/metadata mapping, no English provisional IDs, valid lowercase `male|female`, unique `id`/`demo_file`, and equality with the `voice` request enum later placed in `pack.json`.

- [ ] Add `voice_catalog.json` from the approved table and source text. Require a top-level JSON array and exactly these seven string keys per record. IDs and filenames are ASCII fixed allowlist values; no duplicate, traversal, control character, or unexpected key is accepted by either Python or PHP.

- [ ] Refactor `synthesize.py` to replace its literal `VOICES` set with a strict local `load_voice_catalog()` / `voice_ids()` implementation based on `Path(__file__).with_name("voice_catalog.json")`. On a malformed/missing catalogue, use the existing bounded `edge_tts_failed` path. `validate_request()` must continue to validate all current request keys/rates/volumes/pitches, but admit exactly the loaded 14 IDs.

- [ ] Implement `generate_demos.py` as a small executable companion, importing the same catalogue loader from `synthesize.py`; do not duplicate voice IDs or sample text. Its only workspace is `/workspace/output`, and it must:

  ```python
  for voice in load_voice_catalog():
      temporary = output / ("." + voice["demo_file"] + ".tmp")
      try:
          edge_tts.Communicate(
              voice["demo_text"], voice["id"],
              rate="+0%", volume="+0%", pitch="+0Hz",
          ).save_sync(str(temporary))
          verify_regular_mp3(temporary, max_bytes=1024 * 1024)
          temporary.replace(output / voice["demo_file"])
          successes.append(availability_entry(...))
      except Exception:
          remove_regular_temporary(temporary)
  write_availability(output / "available.json", successes)
  if not successes:
      fail("edge_tts_demo_initialization_failed")
  ```

  Availability contains only `version` and a `voices` list of `{id, file, bytes, sha256}`. It contains neither demo text, memo, provider exception, URL, token, nor timestamp. It writes atomically and emits counts only (`succeeded`, `failed`) to stdout.

- [ ] Add an explicit entrypoint command branch before normal workspace ACL setup. The only accepted demo command is the exact `/app/generate_demos.py`; it calls `grant_demo_workspace_access()` to grant `edge` only `rwx` on `/workspace/output`. The existing normal branch retains its current `request.json` read ACL. Both branches execute the identical DNS/firewall verification and end in:

  ```bash
  exec setpriv --reuid=edge --regid=edge --clear-groups \
    --bounding-set=-all --ambient-caps=-all -- "$@"
  ```

  Reject any other command, missing output directory, or ACL mismatch through the existing `upstream_unavailable` sentinel. Extend the shell mock test to prove the demo branch neither reads `/workspace/input/request.json` nor reaches the generator as root/capable, while its existing provider-only IPv4/IPv6 firewall assertions remain unchanged.

- [ ] Update the Docker build context deliberately, then run both offline Python suites during image build:

  ```dockerfile
  COPY edge-tts-entrypoint.sh synthesize.py generate_demos.py voice_catalog.json \
       test_egress_firewall.sh test_synthesize.py test_generate_demos.py ./
  RUN chmod 0755 edge-tts-entrypoint.sh synthesize.py generate_demos.py test_egress_firewall.sh \
   && python3 -m unittest -v test_synthesize.py test_generate_demos.py
  ```

- [ ] Run the focused tests and inspect their output before continuing:

  ```bash
  cd /var/www/html/3waAIHub
  python3 -m unittest -v packs/edge-tts/service/test_synthesize.py packs/edge-tts/service/test_generate_demos.py
  php scripts/run_tests.php --suite=full
  git diff --check
  ```

- [ ] Commit this independently reviewable unit, for example:

  ```bash
  git add packs/edge-tts/service tests/test_edge_tts_pack.php
  git commit -m "feat: add edge tts voice catalogue demos"
  ```

## Task 2: Generate and atomically publish verified demos during Edge TTS installation

**Files:**
- Create: `app/edge_tts_voices.php` (mode `0755`)
- Modify: `app/bootstrap.php`
- Modify: `app/pack_registry.php`
- Modify: `admin/packs.php`
- Modify: `admin/marketplace.php`
- Modify: `tests/test_edge_tts_pack.php`

- [ ] Add failing installer tests with a synthetic `edge_tts_demo_runner` seam. The seam parses the fixed bind mount source from the Docker command and writes controlled staged `available.json`/MP3 fixtures. Cover full success, partial success, all-failure, hash mismatch, symlink, malformed JSON, previous-current preservation, no database row/version update on all failure, and no invocation for an unrelated Pack.

- [ ] Implement the narrow `app/edge_tts_voices.php` helpers. Keep this Edge-TTS-specific instead of adding a generic Pack hook framework. The public PHP boundary is:

  ```php
  function hub_edge_tts_voice_catalog(): array;
  function hub_edge_tts_demo_root(string $serviceKey): string;
  function hub_edge_tts_initialize_voice_demos(array $pack, string $serviceKey, ?callable $runner = null): array;
  function hub_edge_tts_verified_voices(string $serviceKey): array;
  function hub_edge_tts_voice_catalog_dispatch(PDO $db, array $route, array $query): array;
  ```

  Catalogue loading rejects the source before exposing any record. A service key is validated using the existing service-key grammar before it becomes a results subdirectory. The current location is exactly `HUB_DATA_DIR . '/results/edge-tts-demos/' . $serviceKey . '/current'`; it is intentionally below the already-safe artifact root.

- [ ] Have `hub_edge_tts_initialize_voice_demos()` create a sibling staging directory with private permissions, call only this fixed command through the existing command runner, and always run best-effort named-container cleanup using the existing Pack-job cleanup primitives:

  ```php
  [
      'docker', 'run', '--pull=never', '--network', 'bridge', '--cap-add', 'NET_ADMIN',
      '--mount', 'type=bind,src=' . $staging . ',dst=/workspace/output',
      '--name', $containerName,
      '--entrypoint', '/app/edge-tts-entrypoint.sh',
      $manifest['runner_build']['image'], '/app/generate_demos.py',
  ]
  ```

  Use no input mount, no secrets, no environment forwarding, no GPU, and no caller-supplied command/image/path. Give it a bounded 300-second timeout. Before publish, validate every availability entry against the static catalogue and `hash_file('sha256', ...)`; reject extras and normalize to the static fixed filename. Atomically rename a fully verified stage to `current` only after retaining the prior `current` as rollback until replacement succeeds. On failure remove only the staging directory and return/throw the bounded `edge_tts_demo_initialization_failed` outcome without disclosing command or provider stderr.

- [ ] Load the new file in `app/bootstrap.php` before `gateway.php`, and in `hub_install_pack()` call the initializer only when `(string)$manifest['id'] === 'edge-tts'`. Call it after runner image provisioning and storage resolution but before generated compose/env files and before any INSERT/UPDATE of `services`; therefore a total demo failure cannot promote a new service record or overwrite its Pack version. In tests, retain legacy install behavior unless the explicit demo runner seam is supplied; production always runs the initializer.

- [ ] Return an `edge_tts_demos` summary with only `succeeded` and `failed` counts. Append that summary to the success flash in both Pack install screens. Do not put per-voice provider errors, demo text, local paths, command output, token, or raw availability content in the browser.

- [ ] Make the new PHP helper executable as required, then verify the installer-focused suite:

  ```bash
  chmod 0755 app/edge_tts_voices.php
  php scripts/run_tests.php --suite=full
  git diff --check
  ```

- [ ] Commit the host lifecycle separately:

  ```bash
  git add app/edge_tts_voices.php app/bootstrap.php app/pack_registry.php admin/packs.php admin/marketplace.php tests/test_edge_tts_pack.php
  git commit -m "feat: generate verified edge tts demos on install"
  ```

## Task 3: Expose the verified catalogue through the existing authenticated Edge TTS route

**Files:**
- Modify: `packs/edge-tts/pack.json`
- Modify: `app/gateway.php`
- Modify: `app/public_api_docs.php`
- Modify: `app/cluster_router.php`
- Modify: `tests/test_edge_tts_pack.php`
- Modify: `tests/test_cluster_router.php`

- [ ] Write failing gateway tests first. With a permitted token and an installed/enabled/running Edge TTS service backed by a synthetic verified demo tree, assert:

  ```php
  $list = hub_gateway_dispatch($db, 'edge_tts', null, [
      'method' => 'GET', 'query' => [], 'bearer_token' => $token,
  ]);
  hub_test_assert($list['status'] === 200);
  hub_test_assert($payload['voices'][0]['demo_url'] === '?mode=edge_tts&voice=zh-TW-HsiaoChenNeural');
  ```

  Assert all returned records have the seven specified public fields; no raw path/hash/bytes/token/provider error is disclosed. Assert `voice=<verified-id>` returns a stream response with `Content-Type: audio/mpeg`, `Content-Disposition: inline`, `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, and no task row. Assert missing permission, disabled/not-running service, POST query confusion, duplicate/unknown query keys, unknown IDs, unavailable IDs, symlink/hash mismatch, and a stale availability record fail closed without a path leak or task creation.

- [ ] Change the Pack gateway declaration to `"methods": ["GET", "POST"]`, replace the five-ID POST enum by the exact 14 catalogue IDs, and advance the controlled Pack pair together to `version: "0.3.0"` / `3waaihub/edge-tts:0.3.0`. Set `runtime_level` and `target_level` to `"L5-benchmark-ready"`. Do not add a second mode or an `edge_tts_voices` endpoint.

- [ ] In the existing async-mode branch of `hub_gateway_dispatch()`, authenticate and resolve the route first. Only when `$mode === 'edge_tts' && $requestMethod === 'GET'`, require the installed/enabled/running service and call `hub_edge_tts_voice_catalog_dispatch()` with `internalRequest['query']` or `$_GET`. All non-GET Edge TTS calls continue to the current POST task submission path; reject any method other than GET/POST with the existing bounded `method_not_allowed` response.

- [ ] In `hub_edge_tts_voice_catalog_dispatch()`, allow only optional scalar `voice`; list for no query, stream for exactly one static verified ID. Build a response by calling `hub_gateway_stream_file_response()` on only the independently verified expected file beneath `current`, then replace the fixed attachment disposition with its fixed safe inline name and append the cache/nosniff headers. Do not alter `hub_artifact_safe_path()` or trust a filename/query/path from the request.

- [ ] Preserve the demo cache policy through a remote Cluster hop with the smallest exact extension to `hub_proxy_allowed_response_headers()`: accept only `Cache-Control: private, no-store` in addition to its current canonical headers. Do not forward arbitrary `Cache-Control`, `Content-Disposition`, cookies, redirects, or service-provided headers. Extend Cluster tests for self-direct and remote-proxy GET list/demo calls, query forwarding, relative URL non-rewrite, binary body preservation, and the exact cache header.

- [ ] Make public API discovery document both operations while retaining the existing POST task contract as primary. `hub_public_api_pack_job_async_contract()` adds a declared `operations` list for Edge TTS:

  ```php
  [
      ['method' => 'GET', 'query' => [], 'response' => 'verified voice catalogue JSON'],
      ['method' => 'GET', 'query' => ['voice' => '<voice-id>'], 'response' => 'audio/mpeg; Cache-Control: private, no-store'],
      ['method' => 'POST', 'response' => 'asynchronous synthesis task'],
  ]
  ```

  Include it in `hub_public_api_services()`, render it as an additional operations block in local public docs, preserve it in the Cluster public manifest allowlist, and render the same block in Cluster docs. Keep generated example URLs free of real tokens and paths. Add coverage for the manifest and both HTML portals.

- [ ] Run the API/Cluster/documentation tests and a manifest lint:

  ```bash
  cd /var/www/html/3waAIHub
  php scripts/run_tests.php --suite=full
  jq empty packs/edge-tts/pack.json
  git diff --check
  ```

- [ ] Commit the externally visible contract as one change:

  ```bash
  git add packs/edge-tts/pack.json app/gateway.php app/public_api_docs.php app/cluster_router.php tests/test_edge_tts_pack.php tests/test_cluster_router.php
  git commit -m "feat: publish edge tts voice demos"
  ```

## Task 4: Add a real L5 public-API acceptance client without making offline benchmarks networked

**Files:**
- Create: `scripts/edge_tts_acceptance.php` (mode `0755`)
- Modify: `app/benchmarks.php`
- Modify: `tests/test_edge_tts_pack.php`
- Modify: `tests/test_benchmark.php`

- [ ] Add failing tests that require the script (its executable main must be guarded with `realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__`) and use injected HTTP/command seams. Verify no external connection is made in CI; config accepts only the two required environment variables; submission/poll/artifact checks/ACK order is exact; failure classes map only to the five approved errors; a success row is redacted; and generic `scripts/benchmark.php --pack=edge-tts --case=edge_tts_async_complete` rejects the external-only case rather than attempting it.

- [ ] Set an `l5_contract` in the Edge TTS manifest with the fixed asynchronous POST request/owned-artifact response contract plus a real acceptance benchmark case:

  ```json
  {
    "id": "edge_tts_async_complete",
    "name": "Edge TTS real async public-API acceptance",
    "type": "external_acceptance",
    "mode": "edge_tts",
    "method": "POST",
    "real_inference": true
  }
  ```

  Include all current input fields, required task response keys, bounded errors, CPU limits, and the five subtitle/no-subtitle artifact requirements. The generic benchmark implementation must throw a bounded instruction for `external_acceptance`; it must neither queue a task nor write a misleading pass row.

- [ ] Implement `scripts/edge_tts_acceptance.php` with no token CLI argument and no shell tracing. It reads only:

  ```bash
  AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL='https://hub.example/3waAIHub/api.php'
  AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN='…'
  ```

  It normalizes a supplied `api.php` or `cluster_api.php` URL without dropping its path, uses curl with an `Authorization: Bearer` header and redirects disabled, and sends only a short non-confidential fixed sentence, one verified list voice, and `include_subtitles=true`. It must not call a Pack runner or direct service URL.

- [ ] The acceptance sequence is:

  ```text
  GET edge_tts list → GET first verified relative demo URL → save 0600 temp MP3 → ffprobe
  → POST edge_tts synthesis → poll task_status to terminal state → GET task_result
  → fetch five declared artifacts → compare declared size/SHA-256 → ffprobe MP3
  → parse VTT/SRT/metadata/timeline → ACK every artifact
  → query local runtime record: CPU, no gpu:0 lease/owned PID → redact and save benchmark row
  ```

  Resolve the relative demo query against the configured endpoint, never append a token to it, and delete temporary files in `finally`. Use a bounded polling timeout. Validate exactly `generated_audio`, `synthesis_metadata`, `subtitle_vtt`, `subtitle_srt`, and `speech_timeline`; reject duplicate/missing/unowned artifacts. Validate VTT/SRT timing, metadata fields, and timeline schema without persisting submitted text or response bodies.

- [ ] Map failures only to `edge_tts_acceptance_config_invalid`, `edge_tts_acceptance_list_demo_failed`, `edge_tts_acceptance_submission_failed`, `edge_tts_acceptance_task_failed`, or `edge_tts_acceptance_artifact_invalid`. Success calls:

  ```php
  hub_save_benchmark_run(
      $db,
      'edge_tts_async_complete',
      (int) $service['id'],
      'edge_tts',
      'pass',
      $elapsedMs,
      $redactedResult,
      null,
  );
  ```

  `$redactedResult` is limited to pass booleans, counts, MIME types, byte lengths, elapsed time, CPU/no-GPU observations, and error code. It excludes the token, URL, task/artifact IDs, SHA-256, user text, demo body, and headers.

- [ ] Run the static/offline acceptance tests. Do not claim a real acceptance pass until the operator has supplied the environment token and intentionally run it:

  ```bash
  chmod 0755 scripts/edge_tts_acceptance.php
  php scripts/run_tests.php --suite=full
  AIHUB_EDGE_TTS_ACCEPTANCE_BASE_URL='https://hub.example/3waAIHub/api.php' \
  AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN="$AIHUB_EDGE_TTS_ACCEPTANCE_TOKEN" \
  php scripts/edge_tts_acceptance.php
  ```

- [ ] Commit L5 acceptance separately:

  ```bash
  git add packs/edge-tts/pack.json app/benchmarks.php scripts/edge_tts_acceptance.php tests/test_edge_tts_pack.php tests/test_benchmark.php
  git commit -m "feat: add edge tts l5 acceptance"
  ```

## Task 5: Publish the operator and client contract, then perform full verification

**Files:**
- Modify: `README.md`
- Modify: `packs/edge-tts/README.md`
- Modify: `docs/api_examples.md`
- Modify: `docs/operations/edge-tts-real-smoke.md`
- Modify: `tests/test_edge_tts_pack.php`

- [ ] Add failing documentation assertions before editing prose. They must require the 14-voice regional catalogue, `GET api.php?mode=edge_tts`, `?mode=edge_tts&voice=…`, `audio/mpeg`, `private, no-store`, same token permission, partial-versus-total install behavior, container-local firewall/non-root generator, L5 acceptance script/environment variable names, exact real artifact set, and the CPU/no-GPU postcondition. Assert that docs never suggest host firewall changes or contain a bearer-token value (the literal `<TOKEN>` placeholder remains acceptable).

- [ ] Update the root README and Pack README with concise curl examples for a token-authenticated GET list, then fetching the returned relative demo URL with the same header. Explain that callers choose only a verified returned ID, `memo` is descriptive not a provider style control, list membership can be smaller after a partial demo build, and every demo URL still requires the token.

- [ ] Update `docs/api_examples.md` and the real-smoke runbook. The runbook should tell administrators that install generates fresh demos, gives the allowed edge_tts/task permission set, runs the new acceptance script from an environment-held token, covers `ffprobe` and exact artifact verification/ACK, and distinguishes a real L5 acceptance from offline CI. It must remain free of live secret values and direct Pack-runner commands.

- [ ] Run the complete local verification sequence, inspect the working tree, and verify PHP modes:

  ```bash
  cd /var/www/html/3waAIHub
  php -l app/edge_tts_voices.php
  php -l scripts/edge_tts_acceptance.php
  python3 -m unittest -v packs/edge-tts/service/test_synthesize.py packs/edge-tts/service/test_generate_demos.py
  php scripts/run_tests.php --suite=full
  jq empty packs/edge-tts/pack.json
  git diff --check
  test "$(stat -c '%a' app/edge_tts_voices.php)" = 755
  test "$(stat -c '%a' scripts/edge_tts_acceptance.php)" = 755
  git status --short
  ```

- [ ] Inspect the assembled Pack with a targeted security checklist before commit: no demo file escapes `data/results`; no arbitrary GET query/filename flows to disk; no public token/external provider error is returned; Cluster preserves only the exact cache header; the generator still drops capabilities and uses no input mount; and the all-failure install path leaves the prior service/current catalogue intact.

- [ ] Commit the documentation and final regression coverage:

  ```bash
  git add README.md packs/edge-tts/README.md docs/api_examples.md docs/operations/edge-tts-real-smoke.md tests/test_edge_tts_pack.php
  git commit -m "docs: document edge tts voice catalogue"
  ```

## Acceptance Criteria

- The Pack source contains exactly the approved 14 regional profiles and their exact listening text/memos; synthesis admits exactly those IDs.
- Pack installation generates demos inside the container with the existing fail-closed egress bootstrap and non-root/no-capability process; it succeeds when one or more voices succeed and fails atomically when none does.
- `GET edge_tts` returns only verified voices with `display_name`, `locale`, `gender`, `memo`, `demo_text`, and a same-endpoint relative `demo_url`; it does not create a task.
- `GET edge_tts&voice=<id>` streams only a verified fixed MP3 with `audio/mpeg`, `inline`, `private, no-store`, and `nosniff`; unknown, unavailable, malformed, tampered, or path-like input is rejected without filesystem disclosure.
- POST synthesis continues to queue the existing async task using the selected approved voice, including current caption/timeline behavior.
- Local public docs and Cluster public docs both expose GET list/demo plus POST synthesis; Cluster remote proxy preserves only allowed safe metadata and the exact cache policy.
- L5 readiness has the Edge TTS contract and external acceptance case. Offline benchmarks never execute it. A deliberate real acceptance validates list/demo, full async artifact lifecycle and ACKs, CPU/no-GPU postcondition, and a redacted `edge_tts_async_complete` benchmark record.
