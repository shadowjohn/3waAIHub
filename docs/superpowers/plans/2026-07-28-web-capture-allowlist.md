# Web Capture Allowlist Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `web_capture` usable for administrator-approved public hosts while blocking unapproved document navigation, without creating Docker networks or changing iptables.

**Architecture:** Store one normalized global host list in the existing `settings` table and edit it from the system-admin API/security page. The gateway checks the requested host before enqueueing; the Pack worker rechecks the current list before starting Docker and injects it into the Hub-owned request file. The Playwright runner allows only same-host document navigation, but continues to validate public HTTP(S) subresources for CDN compatibility. The Pack uses a closed `public_egress` profile that maps only to Docker's existing `bridge` network.

**Tech Stack:** PHP 8, SQLite settings/audit log, existing PHP test harness, Docker, Playwright 1.61.1/Chromium, Node.js 22, Sharp.

---

## File map

| File | Responsibility |
| --- | --- |
| `app/storage.php` | Seed the newline-delimited default allowlist. |
| `app/web_capture.php` | Parse/serialize allowlist values, update/audit settings, admission, and worker pre-start recheck. |
| `admin/settings.php` | Render and save the system-admin allowlist textarea. |
| `app/gateway.php` | Convert a non-allowlisted initial URL into the existing HTTP 400 `url_not_allowed` response. |
| `app/pack_registry.php` | Admit the closed `public_egress` runner profile. |
| `app/pack_job_runner.php` | Recheck the list before workspace/container creation and map `public_egress` to Docker `bridge`. |
| `packs/web-screenshot/pack.json` | Select `public_egress` and publish the patched Pack version. |
| `packs/web-screenshot/service/url_policy.js` | Validate Hub-provided hosts and exact document-navigation targets. |
| `packs/web-screenshot/service/capture.js` | Inject document navigation enforcement, popup rejection, and late-navigation checks. |
| `packs/web-screenshot/service/url_policy_cases.json` | Shared host-normalization fixtures consumed by PHP and Node checks. |
| `packs/web-screenshot/service/{Dockerfile,package.json,package-lock.json,test_url_policy.js,test_capture.js}` | Package the shared fixture, bump the runner patch version, and self-check the policy. |
| `tests/test_web_screenshot_pack.php` | PHP coverage for settings, gateway, worker recheck, and manifest behavior. |
| `tests/test_pack_job_adapter.php` | Closed network-profile mapping regression coverage. |
| `README.md` | Administrator workflow and the `bridge`/residual-risk boundary. |

No task runs `scripts/install_capture_egress_network.sh`, creates a Docker network, or invokes `iptables`.

### Task 1: Add one audited, normalized administrator allowlist

**Files:**
- Modify: `app/storage.php:4-63`
- Modify: `app/web_capture.php:4-35`
- Modify: `admin/settings.php:157-175,391-435`
- Modify: `tests/test_web_screenshot_pack.php:1-106`
- Create: `packs/web-screenshot/service/url_policy_cases.json`

- [ ] **Step 1: Write failing parser/default/audit tests.**

  Add this test before the existing route tests. It fixes the exact defaults, normal form, error location, empty-list behavior, and audit event without needing a browser session.

  ```php
  hub_test('web capture allowlist is normalized, bounded, and audited', function (): void {
      $db = hub_test_reset_db();
      hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === implode("\n", [
          '3wa.tw', 'fmg.wra.gov.tw', 'fmgb.wra.gov.tw', 'focusit.tw',
          'focusit.com.tw', 'gis.tw', 'wmts.nlsc.gov.tw', 'maps.nlsc.gov.tw',
          'mts1.google.com', 'api.maptiler.com', 'tile.openstreetmap.org',
      ]), 'web capture defaults must seed the approved hosts');

      $hosts = hub_web_capture_save_allowed_hosts($db, 'admin', " 3WA.TW.\nfocusit.tw\n3wa.tw\n");
      hub_test_assert($hosts === ['3wa.tw', 'focusit.tw'], 'save must lower-case, trim, and deduplicate hosts');
      hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === "3wa.tw\nfocusit.tw", 'save must persist canonical newline text');
      hub_test_assert($db->query("SELECT details FROM audit_logs WHERE action = 'web_capture_allowlist_updated' ORDER BY id DESC LIMIT 1")->fetchColumn() === 'added=1 removed=10 total=2', 'save must write a bounded allowlist audit summary');

      $before = hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS');
      try {
          hub_web_capture_save_allowed_hosts($db, 'admin', "3wa.tw\nhttps://bad.example/");
          throw new RuntimeException('invalid allowlist line must throw');
      } catch (InvalidArgumentException $e) {
          hub_test_assert($e->getMessage() === 'web_capture_allowed_hosts_invalid_line:2', 'invalid entry must identify its line');
      }
      hub_test_assert(hub_get_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS') === $before, 'invalid input must not change the saved list');
      hub_test_assert(hub_web_capture_parse_allowed_hosts("\n\n") === [], 'an empty allowlist must remain an explicit disable switch');
      hub_test_assert(hub_test_throws(static fn (): array => hub_web_capture_parse_allowed_hosts(implode("\n", array_map(static fn (int $i): string => "h{$i}.example", range(1, 129))))), 'more than 128 hosts must be rejected');
  });
  ```

- [ ] **Step 2: Run the PHP harness and confirm the new test fails because the allowlist helpers/default do not exist.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: non-zero exit with an undefined `hub_web_capture_save_allowed_hosts()` or default-setting assertion failure; existing baseline tests identify no unrelated failure.

- [ ] **Step 3: Implement the single PHP authority for this setting.**

  In `app/storage.php`, add `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS` with the eleven approved hosts joined by `"\n"`. In `app/web_capture.php`, add these helpers; do not create a second settings table or a Pack-specific setting:

  ```php
  function hub_web_capture_parse_allowed_hosts(string $raw): array
  {
      if (strlen($raw) > 16384) {
          throw new InvalidArgumentException('web_capture_allowed_hosts_too_large');
      }
      if (preg_match('//u', $raw) !== 1) {
          throw new InvalidArgumentException('web_capture_allowed_hosts_invalid_encoding');
      }
      $hosts = [];
      foreach (preg_split('/\R/u', $raw) ?: [] as $index => $line) {
          if (trim($line) === '') {
              continue;
          }
          $host = hub_web_capture_normalize_allowed_host($line);
          if ($host === null) {
              throw new InvalidArgumentException('web_capture_allowed_hosts_invalid_line:' . ($index + 1));
          }
          $hosts[$host] = true;
          if (count($hosts) > 128) {
              throw new InvalidArgumentException('web_capture_allowed_hosts_too_many');
          }
      }
      return array_keys($hosts);
  }

  function hub_web_capture_allowed_host_is_valid(string $host): bool
  {
      return str_contains($host, '.')
          && strlen($host) <= 253
          && $host !== 'localhost'
          && !str_ends_with($host, '.localhost')
          && filter_var($host, FILTER_VALIDATE_IP) === false
          && preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host) === 1;
  }

  function hub_web_capture_normalize_allowed_host(string $value): ?string
  {
      $host = strtolower(rtrim(trim($value, " \t\r\n\0\x0B[]"), '.'));
      return hub_web_capture_allowed_host_is_valid($host) ? $host : null;
  }

  function hub_web_capture_save_allowed_hosts(PDO $db, string $username, string $raw): array
  {
      $hosts = hub_web_capture_parse_allowed_hosts($raw);
      $previous = hub_web_capture_allowed_hosts($db);
      $text = implode("\n", $hosts);
      $added = count(array_diff($hosts, $previous));
      $removed = count(array_diff($previous, $hosts));
      $db->beginTransaction();
      try {
          hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', $text);
          hub_audit($db, $username, 'web_capture_allowlist_updated', "added={$added} removed={$removed} total=" . count($hosts));
          $db->commit();
      } catch (Throwable $e) {
          if ($db->inTransaction()) $db->rollBack();
          throw $e;
      }
      return $hosts;
  }
  ```

  `hub_web_capture_allowed_hosts(PDO $db): array` must parse the stored value through the same parser. Map the parser error codes in a small `hub_web_capture_allowed_hosts_error_message()` helper so `admin/settings.php` can show a Chinese line-numbered error rather than raw exception text.

  In the API form branch, parse the textarea before any writes, retain the existing `hub_validate_storage_input()` validation for the other API fields, then call `hub_web_capture_save_allowed_hosts($db, (string)$user['username'], $raw)`. Add one textarea after the token settings:

  ```php
  <label>Web Screenshot 允許主機 / <code>AIHUB_WEB_CAPTURE_ALLOWED_HOSTS</code></label>
  <textarea name="AIHUB_WEB_CAPTURE_ALLOWED_HOSTS" rows="8" spellcheck="false"><?= hub_h($settings['AIHUB_WEB_CAPTURE_ALLOWED_HOSTS']) ?></textarea>
  <p class="form-help">每行一個精確主機名；空白清單會停用新的 web_capture 任務。</p>
  ```

- [ ] **Step 4: Add the shared hostname fixture and ensure the UI is wired to the helper.**

  Create `packs/web-screenshot/service/url_policy_cases.json` with these exact values; it is data, not executable configuration:

  ```json
  {
    "valid_hosts": ["3wa.tw", "focusit.tw", "tile.openstreetmap.org"],
    "invalid_hosts": ["https://3wa.tw", "3wa.tw:443", "*.3wa.tw", "127.0.0.1", "localhost", "metadata", "bad host", "例子.測試"],
    "canonical_hosts": [{"input":"3WA.TW.","output":"3wa.tw"}]
  }
  ```

  Extend the PHP test to load this JSON and assert that its valid, invalid, and canonical cases agree with `hub_web_capture_parse_allowed_hosts()`. Add a source assertion that `admin/settings.php` contains the exact textarea name and calls `hub_web_capture_save_allowed_hosts()`.

- [ ] **Step 5: Run the PHP harness and commit the setting/UI slice.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: exit 0 and `failures=0`.

  ```bash
  git add app/storage.php app/web_capture.php admin/settings.php \
    packs/web-screenshot/service/url_policy_cases.json tests/test_web_screenshot_pack.php
  git commit -m "feat: manage web capture allowed hosts"
  ```

### Task 2: Enforce the list at admission and immediately before execution

**Files:**
- Modify: `app/web_capture.php:4-35`
- Modify: `app/gateway.php:1284-1300,1471-1497`
- Modify: `app/pack_job_runner.php:152-223,1000-1060`
- Modify: `tests/test_web_screenshot_pack.php:74-106`

- [ ] **Step 1: Write failing gateway and worker-recheck tests.**

  Replace the old successful public-IP submission with these assertions. They deliberately avoid live DNS for the positive validator check by passing a resolver fixture.

  ```php
  hub_set_storage_setting($db, 'AIHUB_WEB_CAPTURE_ALLOWED_HOSTS', '3wa.tw');
  $blocked = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://8.8.8.8/capture']);
  hub_test_assert($blocked['status'] === 400 && (hub_test_web_capture_payload($blocked)['error'] ?? '') === 'url_not_allowed', 'unlisted initial host must return the normal 400 error');

  $normalized = hub_web_capture_validate_input($db, ['url' => 'HTTPS://3WA.TW./capture'], static fn (string $host): array => ['93.184.216.34']);
  hub_test_assert($normalized['url'] === 'https://3wa.tw/capture', 'allowed hostname must normalize before enqueue');

  $forged = hub_test_web_capture_request($db, (string)$token['plain_token'], ['url' => 'https://3wa.tw/', 'allowed_hosts' => 'evil.example']);
  hub_test_assert($forged['status'] === 400, 'client must not inject the runner allowlist');
  ```

  Add a second test that enqueues a valid `web_capture` task, removes `3wa.tw` before `hub_run_pack_job_task()`, and uses an executor that increments `$started`. Assert `status=failed`, `error_code=url_not_allowed`, `$started === 0`, and no `request.json` or container start metadata exists.

- [ ] **Step 2: Run the PHP harness and confirm the admission and pre-start tests fail.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: non-zero exit because the current gateway admits a public literal and the worker does not re-read this setting.

- [ ] **Step 3: Keep generic Pack input unchanged and add the web-specific checks at its two trust boundaries.**

  Preserve the existing public URL checks in `hub_web_capture_validate_input`, but change its signature to accept the database and optional resolver for deterministic unit tests:

  ```php
  function hub_web_capture_validate_input(PDO $db, array $input, ?callable $resolvePublicIps = null): array
  {
      $url = $input['url'] ?? null;
      if (!is_string($url) || $url === '' || trim($url) !== $url) {
          throw new InvalidArgumentException('invalid_request');
      }
      $parts = parse_url($url);
      if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
          || !isset($parts['host']) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
          throw new InvalidArgumentException('invalid_request');
      }
      $rawHost = strtolower(rtrim(trim((string)$parts['host'], '[]'), '.'));
      $port = $parts['port'] ?? null;
      $resolve = $resolvePublicIps ?? 'hub_callback_resolve_public_ips';
      if ($rawHost === '' || $rawHost === 'localhost' || str_ends_with($rawHost, '.localhost')
          || ($port !== null && !in_array((int)$port, [80, 443], true)) || $resolve($rawHost) === []) {
          throw new InvalidArgumentException('invalid_request');
      }
      $host = hub_web_capture_normalize_allowed_host($rawHost);
      if ($host === null || !in_array($host, hub_web_capture_allowed_hosts($db), true)) {
          throw new InvalidArgumentException('url_not_allowed');
      }
      $authority = $host . ($port === null ? '' : ':' . (int)$port);
      $input['url'] = strtolower((string)$parts['scheme']) . '://' . $authority . (string)($parts['path'] ?? '')
          . (array_key_exists('query', $parts) ? '?' . (string)$parts['query'] : '')
          . (array_key_exists('fragment', $parts) ? '#' . (string)$parts['fragment'] : '');
      return $input;
  }
  ```

  Do not add `allowed_hosts` to the manifest or public input fields. Move the web-specific call out of `hub_pack_job_task_input()` and immediately after it in `hub_api_pack_job_task_submit()`, where `$db` is already available. Map `url_not_allowed` before the generic `invalid_request` catch:

  ```php
  if ($e->getMessage() === 'url_not_allowed') {
      return hub_gateway_error(400, 'url_not_allowed', 'URL host is not allowed for web capture');
  }
  ```

  Add `hub_web_capture_prepare_runner_request(PDO $db, array $request): array`. It re-reads the setting, requires the normalized task URL host to remain a member, then returns `$request + ['allowed_hosts' => $hosts]`; removal therefore throws `RuntimeException('url_not_allowed')` before the container starts.

  Change `hub_pack_job_prepare_workspace()` to receive `PDO $db`, call that helper only when `$task['requested_mode'] === 'web_capture'`, and write the returned `allowed_hosts` into Hub-owned `request.json`. Update its one caller in `hub_run_pack_job_task()`. Extend `hub_pack_job_failure_code()` to preserve `url_not_allowed` so the terminal task and callback record the intended code.

- [ ] **Step 4: Run the targeted task lifecycle tests and the full PHP harness.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: exit 0; unsafe URL syntax remains `invalid_request`, an unlisted public URL is `url_not_allowed`, and a removed queued target fails before its executor is called.

- [ ] **Step 5: Commit the admission/worker boundary.**

  ```bash
  git add app/web_capture.php app/gateway.php app/pack_job_runner.php tests/test_web_screenshot_pack.php
  git commit -m "feat: enforce web capture host policy"
  ```

### Task 3: Use a closed Docker-bridge profile without firewall commands

**Files:**
- Modify: `app/pack_registry.php:457-523`
- Modify: `app/pack_job_runner.php:463-540`
- Modify: `packs/web-screenshot/pack.json`
- Modify: `tests/test_pack_job_adapter.php:110-166`
- Modify: `tests/test_web_screenshot_pack.php:44-72`

- [ ] **Step 1: Add failing profile-mapping tests.**

  Add a `public_egress` fixture beside the existing `capture_egress` fixture:

  ```php
  $publicManifest = hub_test_adapter_manifest('adapter-network-public', '1.0.0');
  $publicManifest['async_jobs'][0]['runner']['network_profile'] = 'public_egress';
  $public = hub_pack_async_job_contract($publicManifest, 'convert');
  hub_test_assert(is_array($public), 'public egress must be a closed valid profile');
  $command = hub_pack_job_default_runner_command([
      'workspace' => $workspace,
      'run' => ['run_id' => 'adapter-network-public'],
      'runner' => hub_pack_job_runner_arguments($public['runner'], ['id' => 1], ['run_id' => 'adapter-network-public'], $workspace),
  ])['command'];
  $index = array_search('--network', $command, true);
  hub_test_assert($index !== false && ($command[$index + 1] ?? null) === 'bridge', 'public egress must select only Docker bridge');
  ```

  Change the Web Screenshot route assertion to expect `network_profile === 'public_egress'`.

- [ ] **Step 2: Run the PHP harness and confirm `public_egress` is currently rejected.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: non-zero exit at the new profile-contract assertion.

- [ ] **Step 3: Add only the third closed enum value and map it deterministically.**

  In `hub_pack_async_job_runner_contract()`, extend the existing closed list to:

  ```php
  in_array($networkProfile, ['isolated', 'capture_egress', 'public_egress'], true)
  ```

  In `hub_pack_job_default_runner_command()`, retain the existing two mappings and add the bridge mapping without accepting arbitrary manifest values:

  ```php
  $network = match ($runner['network_profile'] ?? 'isolated') {
      'capture_egress' => 'aihub-capture-egress',
      'public_egress' => 'bridge',
      default => 'none',
  };
  ```

  Change only `packs/web-screenshot/pack.json` to `"network_profile": "public_egress"`. Keep `capture_egress` available for any existing Pack that explicitly requires its already-provisioned policy; do not invoke its installer or service unit.

- [ ] **Step 4: Run the complete PHP harness.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: exit 0; `isolated` still maps to `none`, `capture_egress` still maps to its fixed name, `public_egress` maps to `bridge`, and `customer-network` remains invalid.

- [ ] **Step 5: Commit the closed runner-profile change.**

  ```bash
  git add app/pack_registry.php app/pack_job_runner.php packs/web-screenshot/pack.json \
    tests/test_pack_job_adapter.php tests/test_web_screenshot_pack.php
  git commit -m "feat: use controlled public egress for web capture"
  ```

### Task 4: Enforce same-host Playwright document navigation

**Files:**
- Modify: `packs/web-screenshot/service/url_policy.js`
- Modify: `packs/web-screenshot/service/capture.js`
- Modify: `packs/web-screenshot/service/test_url_policy.js`
- Modify: `packs/web-screenshot/service/test_capture.js`
- Modify: `packs/web-screenshot/service/Dockerfile`
- Modify: `packs/web-screenshot/service/package.json`
- Modify: `packs/web-screenshot/service/package-lock.json`
- Modify: `packs/web-screenshot/pack.json`

- [ ] **Step 1: Write failing Node tests for the Hub-injected list and navigation matrix.**

  In `test_url_policy.js`, load `url_policy_cases.json` and assert the exact-host behavior:

  ```js
  const assert = require('node:assert/strict');
  const { validateAllowedHosts, validateDocumentNavigation } = require('./url_policy');
  const resolve = () => [{ address: '93.184.216.34', family: 4 }];
  const allowed = validateAllowedHosts(['3wa.tw', 'tile.openstreetmap.org']);

  assert.deepEqual(allowed, ['3wa.tw', 'tile.openstreetmap.org']);
  assert.equal(await validateDocumentNavigation('https://3wa.tw/next', '3wa.tw', allowed, resolve), 'https://3wa.tw/next');
  await assert.rejects(() => validateDocumentNavigation('https://tile.openstreetmap.org/0/0/0.png', '3wa.tw', allowed, resolve), /url_not_allowed/);
  assert.throws(() => validateAllowedHosts(['3wa.tw', '3wa.tw']), /url_not_allowed/);
  assert.throws(() => validateAllowedHosts(['*.3wa.tw']), /url_not_allowed/);
  ```

  In `test_capture.js`, call a newly exported pure `captureNavigationDecision()` with `(kind, pageIsPrimary, isMainFrame, url, initialHost, allowedHosts)`. Cover: same-host main 301 continuation; cross-host main failure; same-host iframe continuation; cross-host iframe warning/abort; and every popup document navigation abort. This unit function must be the function the route callback invokes, not a parallel test-only implementation.

- [ ] **Step 2: Run the Node checks and confirm they fail before the new policy functions exist.**

  Run: `docker build -t 3waaihub/web-screenshot:0.1.1 packs/web-screenshot/service && docker run --rm --network none 3waaihub/web-screenshot:0.1.1 npm test`

  Expected: non-zero exit from missing exports or missing `allowed_hosts` parsing; no host firewall operation occurs.

- [ ] **Step 3: Parse the Hub-owned list and make one decision function own every document route.**

  In `url_policy.js`, keep `validatePublicHttpUrl()` for non-document resources. Add `validateAllowedHosts()` that accepts only a nonempty array of canonical ASCII hostnames, rejects duplicates, and returns a copy. Add `validateDocumentNavigation(url, initialHost, allowedHosts, resolve)` that first calls `validatePublicHttpUrl()`, then requires the parsed hostname to equal `initialHost` and `initialHost` to be in `allowedHosts`; otherwise it throws `policyError()`.

  In `capture.js`, require `allowed_hosts` in `parseCaptureRequest()` and store its normalized array on the request. Compute `initialHost` from the validated initial URL. Implement the route decision in one exported helper with these outcomes:

  ```js
  // return { action: 'continue' } | { action: 'abort', mainBlocked: boolean, warning: boolean }
  async function captureNavigationDecision(kind, pageIsPrimary, isMainFrame, url, initialHost, allowedHosts, resolve) {
    if (kind !== 'document') {
      await validatePublicHttpUrl(url, resolve);
      return { action: 'continue' };
    }
    if (!pageIsPrimary) return { action: 'abort', mainBlocked: false, warning: true };
    try {
      await validateDocumentNavigation(url, initialHost, allowedHosts, resolve);
      return { action: 'continue' };
    } catch {
      return { action: 'abort', mainBlocked: isMainFrame, warning: !isMainFrame };
    }
  }
  ```

  The actual `context.route()` callback passes `route.request().isNavigationRequest() ? 'document' : 'resource'`, `route.request().frame().page() === page`, and `route.request().frame() === page.mainFrame()`. It calls `route.abort('blockedbyclient')` only for `abort`; it records a bounded warning for non-main aborts. Register a `context.on('page')` handler that closes every page other than the original; the route decision aborts its document navigation first.

  Add an `assertMainDocumentAllowed()` check after `page.goto`, delay, optional page script, animation frame, and screenshot. Compute `finalUrl` only after the final check, so a delayed same-host URL is reported and a delayed cross-host redirect cannot publish output.

- [ ] **Step 4: Package and version the changed runner.**

  Change the package version, package-lock root version, Pack version, and runner image tag from `0.1.0` to `0.1.1`. Extend the Dockerfile copy list so the shared fixture is present:

  ```dockerfile
  COPY url_policy.js capture.js url_policy_cases.json test_url_policy.js test_capture.js ./
  ```

  Do not add an npm dependency or a browser plugin.

- [ ] **Step 5: Rebuild and run the offline Node self-checks.**

  Run: `docker build -t 3waaihub/web-screenshot:0.1.1 packs/web-screenshot/service && docker run --rm --network none 3waaihub/web-screenshot:0.1.1 npm test`

  Expected: both Node self-checks pass; the check runs with `--network none` and does not depend on a live target site.

- [ ] **Step 6: Commit the browser policy.**

  ```bash
  git add packs/web-screenshot/pack.json packs/web-screenshot/service
  git commit -m "feat: restrict web capture document navigation"
  ```

### Task 5: Replace the obsolete firewall instructions and verify the integrated contract

**Files:**
- Modify: `README.md:338-370`
- Modify: `tests/test_web_screenshot_pack.php`

- [ ] **Step 1: Write the failing documentation/manifest regression assertions.**

  Add assertions that the installed web Pack has version `0.1.1`, `network_profile === 'public_egress'`, and the README includes `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS`, `設定 → API 與安全`, and `Docker bridge`, while excluding `scripts/install_capture_egress_network.sh --check` from the Web Screenshot section.

- [ ] **Step 2: Run the PHP harness and confirm the old Pack/README contract fails.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: non-zero exit while the old firewall prerequisite text remains.

- [ ] **Step 3: Replace only the Web Screenshot README subsection.**

  Replace the current prerequisite/install block with concise operator guidance:

  ```markdown
  ### Web Screenshot allowed hosts

  `web_capture` uses Docker's existing `bridge` network and does not require
  an iptables command or a dedicated Docker network. A system administrator
  maintains exact target hosts at **設定 → API 與安全** under
  `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS`; add one hostname per line.

  The Pack follows redirects only on the initial exact hostname. CDN resources
  remain subject to public-IP checks. Treat every allowed hostname as trusted:
  this mode is not a general arbitrary-URL screenshot service.
  ```

  Retain the general command-worker instructions that follow it. Do not delete the old egress installer, systemd unit, or cron drop-in because they may be used by another deployment; merely stop documenting them as a requirement for this Pack.

- [ ] **Step 4: Run all repository and image verification commands.**

  Run:

  ```bash
  git diff --check
  AIHUB_TEST_QUIET=1 php scripts/run_tests.php
  docker build -t 3waaihub/web-screenshot:0.1.1 packs/web-screenshot/service
  docker run --rm --network none 3waaihub/web-screenshot:0.1.1 npm test
  ```

  Expected: every command exits 0; the PHP summary has `failures=0`; Docker performs no `iptables` command and no Docker network creation.

- [ ] **Step 5: Commit documentation and final checks.**

  ```bash
  git add README.md tests/test_web_screenshot_pack.php
  git commit -m "docs: explain web capture host allowlist"
  git status --short --branch
  ```

  Expected: no uncommitted implementation files. The only branch-ahead commits are the reviewable allowlist implementation commits and the pre-existing design/plan commits.
