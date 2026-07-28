# Web Screenshot Pack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the signed, asynchronous `web_capture` Playwright Pack that creates a full-page screenshot and optional crop without blocking PHP-FPM or exposing internal network targets.

**Architecture:** Generalize the existing fixed audio Pack route just enough to admit one fixed CPU route, `web_capture -> web-screenshot/capture`. The existing generic Pack-job adapter remains the only executor; its default network stays `none` except for a manifest-selected, fixed `capture_egress` profile. A one-shot Playwright image produces only contract-declared artifacts, then the Hub validates and publishes them through the existing fenced completion path.

**Tech Stack:** PHP 8 + SQLite task runtime, Docker, Playwright 1.61.1/Chromium, Node.js 22, Sharp, iptables/DOCKER-USER, existing Hub PHP test runner.

---

## File map

| File | Responsibility |
| --- | --- |
| `app/pack_registry.php` | Fixed generic async route map; manifest schema for cross-field input rules and the restricted runner network profile. |
| `app/gateway.php` | Generic Pack-job admission (renamed from audio-only helpers) and public initial-URL validation. |
| `app/web_capture.php` | Fixed-mode initial URL admission guard; the runner separately guards every later browser request. |
| `app/public_api_docs.php` | Publish all fixed async Pack contracts, including `web_capture`. |
| `app/pack_job_runner.php` | Map only `capture_egress` to the fixed Docker network; retain `none` for every other Pack. |
| `app/task_queue.php` | Validate PNG artifact dimensions and conditional crop artifact presence. |
| `packs/catalog.json` | Register the Pack. |
| `packs/web-screenshot/pack.json` | Immutable input/output/runner contract for `web_capture`. |
| `packs/web-screenshot/service/{Dockerfile,package.json,package-lock.json,capture.js,url_policy.js}` | Pinned Playwright runner, public URL policy, capture/crop/report implementation. |
| `packs/web-screenshot/service/test_*.js` | Node assertion self-checks for URL policy, UA hints, crop, and report. |
| `scripts/install_capture_egress_network.sh` | Idempotently create/check the dedicated Docker network and its destination firewall rules. |
| `deploy/systemd/aihub-capture-egress.service` | Reapply the egress policy after Docker/network restart. |
| `tests/test_web_screenshot_pack.php` | Core contract, gateway, adapter command, artifact, and callback-fencing tests. |
| `README.md` | Document mode, task flow, egress prerequisite, limits, and acceptance command. |

The created Pack directories are `0755`; new JavaScript/CSS are `0644`; any new PHP file under `/var/www/html` is `0755`.

### Task 1: Generalize the fixed async Pack route without weakening audio routes

**Files:**

- Modify: `app/pack_registry.php:91-180`
- Modify: `app/gateway.php:23-43,1272-1517`
- Modify: `app/public_api_docs.php:222-303,335-345`
- Create: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Write the failing fixed-route and admission tests.**

```php
hub_test('web_capture has one immutable CPU Pack route', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'web-screenshot', ['idempotent' => true]);
    $route = hub_resolve_pack_job_async_route($db, 'web_capture');
    hub_test_assert(($route['pack_id'] ?? '') === 'web-screenshot', 'web mode Pack mismatch');
    hub_test_assert(($route['job'] ?? '') === 'capture', 'web mode job mismatch');
    hub_test_assert(($route['runtime_mode'] ?? '') === 'job' && ($route['accelerator'] ?? '') === 'cpu', 'web job must not use GPU');
});
```

Also create a member/token with only `web_capture`; assert that caller-supplied `pack_id`, `entrypoint`, `command`, `callback_url`, and `source_artifact_id` all fail. Add initial URL cases for `file:`, URL credentials, `localhost`, private literals, port 8080, and a hostname resolving to a private address.

- [ ] **Step 2: Run the new file in the complete PHP harness to prove it fails because the route/Pack does not exist.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: a failing `web_capture has one immutable CPU Pack route` assertion; existing tests remain the baseline.

- [ ] **Step 3: Add a generic fixed route resolver, retaining the audio compatibility wrappers.**

In `app/pack_registry.php`, introduce exactly these public functions:

```php
function hub_pack_job_async_routes(): array
{
    return [
        'audio_cleanup' => ['pack_id' => 'audio-cleanup', 'job' => 'cleanup', 'accelerator' => 'gpu'],
        'speech_transcribe' => ['pack_id' => 'whisper-asr', 'job' => 'transcribe', 'accelerator' => 'gpu'],
        'voice_generate' => ['pack_id' => 'tts-voxcpm2', 'job' => 'synthesize', 'accelerator' => 'gpu'],
        'web_capture' => ['pack_id' => 'web-screenshot', 'job' => 'capture', 'accelerator' => 'cpu'],
    ];
}

function hub_is_pack_job_async_mode(string $mode): bool
{
    return array_key_exists($mode, hub_pack_job_async_routes());
}
```

`hub_resolve_pack_job_async_route()` reuses installed-Pack/version and contract-snapshot checks from `hub_resolve_audio_async_route()`, then requires the runner accelerator to equal the mapped accelerator. Persist the mapped accelerator rather than a hard-coded GPU value. Keep `hub_audio_async_routes()`, `hub_is_audio_async_mode()`, and `hub_resolve_audio_async_route()` as wrappers limited to the three existing audio modes.

Rename `hub_api_audio_task_submit()` and its `hub_audio_task_*` helpers to `hub_api_pack_job_task_submit()` / `hub_pack_job_task_*`; do not duplicate callback, source, ownership, or staging logic. Change their response text from “audio” to “Pack job”. Gateway dispatch authenticates the requested mode and calls the generic function only when `hub_is_pack_job_async_mode($mode)` is true. Public API docs use the generic resolver and render file/source fields only for `source_required=true`.

Create `app/web_capture.php`, require it from `app/bootstrap.php`, and call `hub_web_capture_validate_input()` immediately after generic normalization when `requested_mode === 'web_capture'`. It accepts only an absolute HTTP(S) URL on port 80/443 without user/password, normalizes the host, rejects `localhost`/`.localhost`, and calls the existing public-IP resolver used by callback registration. It returns no path or IP to the task input; it is admission only. The Playwright runner remains responsible for redirect, frame, and subresource enforcement.

- [ ] **Step 4: Run the route/gateway/public-doc tests.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: all existing audio tests still pass; the new mode is accepted only with a token that has `web_capture` permission.

- [ ] **Step 5: Commit the route-only change.**

```bash
git add app/pack_registry.php app/gateway.php app/public_api_docs.php tests/test_web_screenshot_pack.php
git commit -m "feat: add fixed web capture Pack route"
```

### Task 2: Express crop and deadline invariants in the shared contract validator

**Files:**

- Modify: `app/pack_registry.php:560-710,740-815`
- Modify: `app/task_queue.php:1945-2075,2135-2165`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Add failing cross-field admission cases.**

```php
$invalid = [
    ['crop_x' => 1],
    ['crop_x' => 0, 'crop_y' => 0, 'crop_width' => 10],
    ['delay_seconds' => 30, 'timeout_seconds' => 30],
];
foreach ($invalid as $post) {
    $response = hub_test_web_capture_request($db, $token, ['url' => 'https://example.com/'] + $post);
    hub_test_assert($response['status'] === 400, 'invalid crop/deadline input must be rejected at admission');
}
```

- [ ] **Step 2: Run the test and confirm the current scalar-only schema admits at least one bad case.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: the crop/deadline assertions fail before the schema extension exists.

- [ ] **Step 3: Add only the reusable primitives needed by this Pack.**

Allow `requires_all` and `gt_field` in a request-field definition.

```php
if (isset($definition['requires_all'])) {
    $names = $definition['requires_all'];
    if (!is_array($names) || !array_is_list($names) || $names === []) return null;
    foreach ($names as $other) {
        if (!is_string($other) || $other === $name || !isset($allowed[$other])) return null;
    }
    $item['requires_all'] = array_values(array_unique($names));
}
if (isset($definition['gt_field'])) {
    $other = $definition['gt_field'];
    if ($type !== 'integer' || !is_string($other) || $other === $name || !isset($allowed[$other])) return null;
    $item['gt_field'] = $other;
}
```

After defaults are applied, reject a provided field when any `requires_all` peer is absent, and reject `value <= input[gt_field]`. Do not change the existing `requires`, `gte_field`, or `requires_when` meanings.

Add `when: {"all_present": ["..."]}` as the third artifact condition. It is true only when each named input key exists; the crop contract uses it to make `crop_png` required only for a complete crop request.

- [ ] **Step 4: Verify the shared primitives and old behavior.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: crop/deadline cases return `invalid_request`; current ASR/TTS and conditional audio artifact cases continue to pass.

- [ ] **Step 5: Commit the shared contract extension.**

```bash
git add app/pack_registry.php app/task_queue.php tests/test_web_screenshot_pack.php
git commit -m "feat: validate Pack cross-field job contracts"
```

### Task 3: Limit the generic runner to two non-arbitrary network profiles

**Files:**

- Modify: `app/pack_registry.php:419-490`
- Modify: `app/pack_job_runner.php:463-540`
- Modify: `tests/test_pack_job_adapter.php`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Add failing adapter-command tests.**

```php
$none = hub_pack_job_default_runner_command($cpuFixture);
hub_test_assert(in_array('none', $none['command'], true), 'ordinary Pack jobs must keep network none');

$capture = hub_pack_job_default_runner_command($captureFixture);
hub_test_assert(in_array('aihub-capture-egress', $capture['command'], true), 'capture profile must use the fixed egress network');
hub_test_assert(!in_array('--gpus', $capture['command'], true), 'capture profile must not request GPU');

hub_test_assert(hub_pack_async_job_contract($manifestWithNetworkName, 'capture') === null, 'manifest must not select arbitrary Docker networks');
```

- [ ] **Step 2: Run the adapter tests to prove `network_profile` is not supported yet.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: the capture fixture contract is invalid or still emits `--network none`.

- [ ] **Step 3: Add the closed enum and Docker mapping.**

Permit the runner key `network_profile` with only `isolated` (default) and `capture_egress`. Copy it into `hub_pack_job_runner_arguments()`. In `hub_pack_job_default_runner_command()` replace the literal network argument with:

```php
$network = ($runner['network_profile'] ?? 'isolated') === 'capture_egress'
    ? 'aihub-capture-egress'
    : 'none';
$command = [
    'docker', 'run', '--pull=never', '--network', $network,
    '--mount', 'type=bind,src=' . $output . ',dst=/workspace/output',
    '--mount', 'type=bind,src=' . $checkpoints . ',dst=/workspace/checkpoints',
    '--name', $name,
];
```

No manifest value becomes a Docker network name. GPU behavior, mounts, cleanup, and the default `none` profile remain unchanged.

- [ ] **Step 4: Run adapter, runtime, and screenshot tests.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: existing Pack fixtures remain isolated; only the fixed screenshot contract selects `aihub-capture-egress`.

- [ ] **Step 5: Commit runner network support.**

```bash
git add app/pack_registry.php app/pack_job_runner.php tests/test_pack_job_adapter.php tests/test_web_screenshot_pack.php
git commit -m "feat: add restricted capture egress runner profile"
```

### Task 4: Validate PNG artifacts and preserve fenced completion behavior

**Files:**

- Modify: `app/task_queue.php:1900-2270,2674-2725`
- Modify: `tests/test_pack_job_artifacts.php`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Add failing PNG artifact fixtures.**

```php
$contract['artifacts'][0] = [
    'type' => 'screenshot_png',
    'path' => 'screenshot.png',
    'mime_types' => ['image/png'],
    'max_bytes' => 52428800,
    'image' => ['format' => 'png', 'max_width' => 2560, 'max_height' => 30000, 'max_pixels' => 60000000],
];
hub_test_assert(
    hub_test_pack_job_contract_fails(static fn () => hub_validate_pack_job_artifacts($workspace, [], $contract)),
    'fake PNG bytes must fail'
);
```

Cover a valid small PNG, fake PNG bytes, a pixel-limit breach, a symlink, and a crop artifact absent/present contrary to `all_present`.

- [ ] **Step 2: Run the test to prove current validation accepts PNG only by MIME.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: at least the fake-PNG or dimension case fails the new assertion.

- [ ] **Step 3: Add the image validator to the existing artifact contract.**

Allow the `image` member only as:

```php
['format' => 'png', 'max_width' => int, 'max_height' => int, 'max_pixels' => int]
```

Require positive bounded values and `max_width * max_height >= max_pixels`. After Hub-owned MIME detection, call `getimagesize($path)`, require `IMAGETYPE_PNG`, positive dimensions, each dimension limit, and the pixel limit. Return this metadata merged with existing audio metadata:

```php
['width' => $width, 'height' => $height, 'format' => 'png']
```

Never trust the runner report for image dimensions.

- [ ] **Step 4: Run artifact and completion-transaction tests.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

Expected: only a real bounded PNG is registered; invalid output creates neither a task success state nor a callback delivery.

- [ ] **Step 5: Commit output safety validation.**

```bash
git add app/task_queue.php tests/test_pack_job_artifacts.php tests/test_web_screenshot_pack.php
git commit -m "feat: validate Pack PNG artifacts"
```

### Task 5: Build the isolated capture-egress prerequisite

**Files:**

- Create: `scripts/install_capture_egress_network.sh`
- Create: `deploy/systemd/aihub-capture-egress.service`
- Modify: `README.md`

- [ ] **Step 1: Write a non-mutating firewall/network self-check.**

The `--check` branch fails unless Docker reports exactly subnet `172.31.240.0/24`, IPv6 disabled, and the `DOCKER-USER` jump to `AIHUB_CAPTURE_EGRESS` exists. It prints only `capture_egress=ready` on success.

```bash
if [[ "${1:-}" == "--check" ]]; then
  docker network inspect -f '{{(index .IPAM.Config 0).Subnet}}|{{.EnableIPv6}}' aihub-capture-egress | grep -Fx '172.31.240.0/24|false'
  iptables -C DOCKER-USER -s 172.31.240.0/24 -j AIHUB_CAPTURE_EGRESS
  echo 'capture_egress=ready'
  exit 0
fi
```

- [ ] **Step 2: Run the check before installation.**

Run: `sudo bash scripts/install_capture_egress_network.sh --check`

Expected: non-zero on an unprepared host; no Docker or iptables state changes.

- [ ] **Step 3: Implement the idempotent installer and restart-safe systemd unit.**

The installer creates only `aihub-capture-egress` with `--subnet 172.31.240.0/24 --ipv6=false`, then creates an `AIHUB_CAPTURE_EGRESS` chain reached only from that subnet. The chain rejects these destination ranges:

```text
0.0.0.0/8  10.0.0.0/8  100.64.0.0/10  127.0.0.0/8
169.254.0.0/16  172.16.0.0/12  192.0.0.0/24  192.168.0.0/16
198.18.0.0/15  224.0.0.0/4  240.0.0.0/4
```

It also rejects TCP ports other than 80/443, then returns. Every mutation is guarded by `iptables -C ... || iptables -A ...`; it never flushes or edits another chain. The systemd unit runs the same installer after `docker.service` and before the task worker.

- [ ] **Step 4: Verify the host prerequisite.**

Run: `sudo bash scripts/install_capture_egress_network.sh && sudo systemctl enable --now aihub-capture-egress.service && sudo bash scripts/install_capture_egress_network.sh --check`

Expected: `capture_egress=ready`; no existing Docker network or firewall rule is deleted.

- [ ] **Step 5: Commit deployment support.**

```bash
git add scripts/install_capture_egress_network.sh deploy/systemd/aihub-capture-egress.service README.md
git commit -m "ops: add isolated screenshot egress network"
```

### Task 6: Create the Playwright Pack and runner self-checks

**Files:**

- Create: `packs/web-screenshot/pack.json`
- Create: `packs/web-screenshot/service/Dockerfile`
- Create: `packs/web-screenshot/service/package.json`
- Create: `packs/web-screenshot/service/package-lock.json`
- Create: `packs/web-screenshot/service/url_policy.js`
- Create: `packs/web-screenshot/service/capture.js`
- Create: `packs/web-screenshot/service/test_url_policy.js`
- Create: `packs/web-screenshot/service/test_capture.js`
- Modify: `packs/catalog.json`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Write Node tests before the runner.**

`test_url_policy.js` uses `node:assert/strict` to reject `file:`, URL credentials, `127.0.0.1`, `[::1]`, `localhost`, port 8080, and a DNS result with `10.0.0.1`; it accepts `https://example.com/path?q=1` with a public stub resolver. `test_capture.js` asserts the fixed Windows Chrome UA, matching client hints, report redaction (no script source), crop bounds, and a crop output with expected dimensions from a generated PNG fixture.

```js
assert.throws(() => validatePublicHttpUrl('http://127.0.0.1/', resolve), /url_not_allowed/);
assert.deepEqual(buildClientHints(FIXED_USER_AGENT), {
  'Sec-CH-UA': '"Google Chrome";v="144", "Chromium";v="144", "Not)A;Brand";v="24"',
  'Sec-CH-UA-Mobile': '?0',
  'Sec-CH-UA-Platform': '"Windows"'
});
```

- [ ] **Step 2: Run the Node tests and confirm missing modules cause failure.**

Run: `cd packs/web-screenshot/service && node test_url_policy.js && node test_capture.js`

Expected: failure until policy and runner modules exist.

- [ ] **Step 3: Implement the minimal runner and immutable Pack manifest.**

Pin `playwright` to `1.61.1` (the proven mycut version) and `sharp` to `0.34.5`; commit the generated lockfile. Use `mcr.microsoft.com/playwright:v1.61.1-jammy`, `npm ci --omit=dev`, and no host Node module mounts.

`capture.js` reads only `/workspace/input/request.json`, validates the same limits defensively, starts Chromium headless, creates a new context with the fixed UA/client hints/locale/timezone and `deviceScaleFactor: 1`, and installs `context.route('**/*', ...)`. The route handler calls `url_policy.js` for every HTTP(S) navigation, frame, and subresource; it aborts non-public traffic and records only bounded host/reason warnings.

`page.goto()` uses the remaining deadline, then the runner waits `delay_seconds`, evaluates the optional script once, waits one animation frame, writes `screenshot.png` with `fullPage: true`, and uses Sharp to crop that PNG into `crop.png` when all four crop fields exist. `finally` always closes context and browser.

The report has exactly these top-level keys:

```js
{
  requested_url, final_url, http_status, viewport, image,
  delay_seconds, timeout_seconds, javascript_executed, crop,
  elapsed_seconds, playwright_version, warnings
}
```

It writes no cookies, headers, response bodies, or JavaScript source. A main-document policy failure exits non-zero with `error_code=url_not_allowed`; HTTP 4xx/5xx still emits image/report; a page over the declared height/pixel limit exits with `error_code=page_too_large`.

The manifest declares `source_required: false`, CPU/zero VRAM, timeout 135 seconds, `network_profile: capture_egress`, and the following output contract:

```json
[
  {"type":"screenshot_png","path":"screenshot.png","mime_types":["image/png"],"max_bytes":52428800,"image":{"format":"png","max_width":2560,"max_height":30000,"max_pixels":60000000}},
  {"type":"crop_png","path":"crop.png","mime_types":["image/png"],"max_bytes":52428800,"when":{"all_present":["crop_x","crop_y","crop_width","crop_height"]},"image":{"format":"png","max_width":2560,"max_height":30000,"max_pixels":60000000}},
  {"type":"capture_report","path":"capture_report.json","mime_types":["application/json"],"max_bytes":65536,"json":{"required_keys":["requested_url","final_url","http_status","viewport","image","delay_seconds","timeout_seconds","javascript_executed","crop","elapsed_seconds","playwright_version","warnings"]}}
]
```

Use symmetric `requires_all` crop fields and `timeout_seconds: {"gt_field":"delay_seconds"}`. Add the Pack to `packs/catalog.json` with the same id, version, category, description, and path.

- [ ] **Step 4: Build and run offline self-checks.**

Run: `docker build -t 3waaihub/web-screenshot:0.1.0 packs/web-screenshot/service && docker run --rm --network none 3waaihub/web-screenshot:0.1.0 node test_url_policy.js && docker run --rm --network none 3waaihub/web-screenshot:0.1.0 node test_capture.js`

Expected: both self-checks pass offline; the image contains exactly the pinned Playwright/Sharp dependency graph.

- [ ] **Step 5: Run PHP Pack/gateway validation and commit.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

```bash
git add packs/catalog.json packs/web-screenshot tests/test_web_screenshot_pack.php
git commit -m "feat: add asynchronous Playwright screenshot Pack"
```

### Task 7: Install, smoke-test, and document the production contract

**Files:**

- Modify: `README.md:1440-1535`
- Modify: `docs/superpowers/specs/2026-07-28-web-screenshot-pack-design.md` only if acceptance measurements require a factual correction

- [ ] **Step 1: Prepare the schema, egress network, and isolated mode permission.**

Run: `php scripts/init_db.php && sudo bash scripts/install_capture_egress_network.sh --check`

Install `web-screenshot` from the Hub Pack installer as `web-screenshot-job` with mode `web_capture`; create a test API token whose only service permission is `web_capture`.

- [ ] **Step 2: Submit a real asynchronous capture and verify artifacts.**

```bash
curl --fail-with-body -X POST 'https://3wa.tw/3waAIHub/api.php?mode=web_capture' \
  -H "Authorization: Bearer $WEB_CAPTURE_TOKEN" \
  -F 'url=https://example.com/' -F 'width=1280' -F 'height=720' \
  -F 'delay_seconds=1' -F 'timeout_seconds=60' \
  -F 'crop_x=0' -F 'crop_y=0' -F 'crop_width=400' -F 'crop_height=200'
```

Expected: immediate JSON with `task_id`; poll `task_status` until completed, then retrieve `screenshot_png`, `crop_png`, and `capture_report` by artifact ID. Confirm no `runtime_resource_leases` row was claimed for GPU.

- [ ] **Step 3: Run bounded negative and cleanup smokes.**

Submit `http://127.0.0.1/`, a redirect fixture leading to a private address, and an unreachable public host. Expect `url_not_allowed`, `url_not_allowed`, and a bounded failed terminal task. Submit a public 404 and expect completed screenshot/report with its HTTP status. Check that a completion callback delivery is inserted only after the terminal transaction.

- [ ] **Step 4: Document measured limits and deliberate boundaries.**

Update README with the endpoint, required mode permission, full-page coordinate system, 4xx/5xx behavior, artifact names, 24-hour/30-day retention, `install_capture_egress_network.sh --check`, and the absence of custom headers, cookies, login, scheduling, and anti-bot bypass.

- [ ] **Step 5: Run final verification and commit.**

Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php && php scripts/self_check.php`

Expected: PHP suite passes and self-check reports no schema upgrade required.

```bash
git add README.md docs/superpowers/specs/2026-07-28-web-screenshot-pack-design.md
git commit -m "docs: document web capture Pack operation"
```
