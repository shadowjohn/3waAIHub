# Web Screenshot Container Egress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent Web Screenshot Chromium from connecting to private or reserved destinations at connection time with a fail-closed firewall inside its own container.

**Architecture:** Only the immutable `web-screenshot` / `capture` job may use `public_egress`; the runner maps it to Docker `bridge` and adds `NET_ADMIN`. A trusted image entrypoint installs and verifies IPv4/IPv6 `OUTPUT` rules, then clears all capabilities and execs the capture runtime as a dedicated non-root user. The browser policy keeps its exact-host navigation rules, but fixes popup and finalization races. No host firewall rule, Docker network creation, proxy, or legacy egress installer is used.

**Tech Stack:** PHP 8, existing Pack registry/job runner tests, Docker, container-local iptables/ip6tables, Bash, Node.js 22, Playwright 1.61.1, Sharp.

## Global Constraints

- Do not invoke or modify host `iptables`, `DOCKER-USER`, `install_capture_egress_network.sh`, systemd, Docker daemon settings, or Docker networks.
- `NET_ADMIN` is added only to the immutable `web-screenshot` `capture` container; no Pack manifest may request capabilities or a network name.
- The entrypoint must fail before Node/Chromium starts if firewall setup or verification fails, then clear the capability bounding set and run as a dedicated non-root user.
- Firewall policy must allow resolver addresses from `/etc/resolv.conf` only on port 53, public TCP 80/443, and reject the same non-public address classes as `url_policy.js` for both IPv4 and IPv6.
- Browser output may be written once only after all primary document route handlers have settled and the blocked state is checked again.
- Web Screenshot's package, package-lock root, Pack manifest, runner image tag, documentation assertions, and README must all use `0.1.2`.
- Docker integration checks require `--cap-add=NET_ADMIN`; if Docker is unavailable locally, report that exact limitation and run them in Docker-capable CI.

---

## File map

| File | Responsibility |
| --- | --- |
| `app/pack_registry.php` | Reject `public_egress` for every manifest except immutable Web Screenshot capture. |
| `app/pack_job_runner.php` | Add `NET_ADMIN` only for the normalized Web Screenshot public-egress runner command. |
| `tests/test_pack_job_adapter.php` | Lock the route/profile/capability command contract. |
| `packs/web-screenshot/service/capture-entrypoint.sh` | Install, verify, and then drop container-local firewall privileges. |
| `packs/web-screenshot/service/test_egress_firewall.sh` | Mocked command-contract test for setup, denial ranges, verification, and capability drop. |
| `packs/web-screenshot/service/egress_self_check.js` | Docker integration probe for real namespace policy and runtime capability state. |
| `packs/web-screenshot/service/Dockerfile` | Install netfilter tools, create the runtime user, and make the entrypoint mandatory. |
| `packs/web-screenshot/service/{package.json,package-lock.json}` | Run the firewall contract test and publish `0.1.2`. |
| `packs/web-screenshot/service/{capture.js,test_capture.js}` | Close all popups and make primary-route finalization race-free. |
| `packs/web-screenshot/pack.json` | Publish runner image and Pack `0.1.2`. |
| `tests/test_web_screenshot_pack.php` | Assert Pack version, image, profile, and egress-entrypoint packaging contract. |
| `README.md` | Explain container-local egress policy without implying a host firewall prerequisite. |

### Task 1: Lock public egress to Web Screenshot and grant only its setup capability

**Files:**
- Modify: `app/pack_registry.php:214-285,457-523`
- Modify: `app/pack_job_runner.php:492-540`
- Modify: `tests/test_pack_job_adapter.php:121-170`

**Interfaces:**
- Consumes: `hub_pack_async_job_contract(array $manifest, string $job): ?array` and the runner's normalized `network_profile`.
- Produces: `public_egress` is valid only when `$manifest['id'] === 'web-screenshot'` and `$job === 'capture'`; its Docker command includes `--network bridge --cap-add NET_ADMIN`.

- [ ] **Step 1: Preserve the interrupted red regression and add a capability assertion.**

  Keep the existing uncommitted generic-public-egress rejection fixture; it is deliberate test work from the interrupted final review. Extend its real-Web-Screenshot command check:

  ```php
  $webScreenshot = hub_get_pack('web-screenshot')['manifest'];
  $public = hub_pack_async_job_contract($webScreenshot, 'capture');
  hub_test_assert(is_array($public) && ($public['runner']['network_profile'] ?? null) === 'public_egress', 'only the immutable Web Screenshot capture route may use public egress');
  $command = hub_pack_job_default_runner_command([
      'workspace' => $workspace,
      'run' => ['run_id' => 'web-screenshot-public'],
      'runner' => hub_pack_job_runner_arguments($public['runner'], ['id' => 1], ['run_id' => 'web-screenshot-public'], $workspace),
  ])['command'];
  $network = array_search('--network', $command, true);
  $capability = array_search('--cap-add', $command, true);
  hub_test_assert($network !== false && ($command[$network + 1] ?? null) === 'bridge', 'Web Screenshot public egress must use Docker bridge');
  hub_test_assert($capability !== false && ($command[$capability + 1] ?? null) === 'NET_ADMIN', 'Web Screenshot firewall setup must receive NET_ADMIN');
  ```

- [ ] **Step 2: Run the PHP harness to prove the generic public profile and capability assertions currently fail.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: the Pack adapter test fails because generic manifests still receive `public_egress` and the runner command lacks `--cap-add NET_ADMIN`. Record unrelated baseline failures separately; do not label them as passing.

- [ ] **Step 3: Gate the profile at contract resolution and hard-code the Docker capability.**

  In `hub_pack_async_job_contract()`, immediately after `hub_pack_async_job_runner_contract()` returns, reject the one privileged profile unless it is the immutable route:

  ```php
  if ($runner !== null
      && ($runner['network_profile'] ?? 'isolated') === 'public_egress'
      && ((string)($manifest['id'] ?? '') !== 'web-screenshot' || $job !== 'capture')) {
      return null;
  }
  ```

  Keep `hub_pack_async_job_runner_contract()`'s closed enum unchanged so `capture_egress` remains available to existing Packs. In `hub_pack_job_default_runner_command()`, append only a fixed Docker flag for the normalized public profile:

  ```php
  if (($runner['network_profile'] ?? 'isolated') === 'public_egress') {
      $command[] = '--cap-add';
      $command[] = 'NET_ADMIN';
  }
  ```

  Put this after the fixed `--network` selection and before mounts; do not add a manifest field, configurable capability, arbitrary Docker argument, or privileged mode.

- [ ] **Step 4: Run the targeted adapter contract and full PHP harness.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: generic `public_egress` manifests are invalid; Web Screenshot alone gets `bridge` and `NET_ADMIN`; `isolated` and `capture_egress` retain their existing mappings.

- [ ] **Step 5: Commit the runner trust boundary.**

  ```bash
  git add app/pack_registry.php app/pack_job_runner.php tests/test_pack_job_adapter.php
  git commit -m "fix: confine web capture egress capability"
  ```

### Task 2: Install a fail-closed firewall in the container and drop runtime privilege

**Files:**
- Create: `packs/web-screenshot/service/capture-entrypoint.sh`
- Create: `packs/web-screenshot/service/test_egress_firewall.sh`
- Create: `packs/web-screenshot/service/egress_self_check.js`
- Modify: `packs/web-screenshot/service/Dockerfile`
- Modify: `packs/web-screenshot/service/package.json`
- Modify: `packs/web-screenshot/service/package-lock.json`
- Modify: `packs/web-screenshot/pack.json`

**Interfaces:**
- Consumes: Docker's fixed `NET_ADMIN` capability from Task 1 and `capture` as the entrypoint command.
- Produces: `capture-entrypoint.sh [command...]` either configures/validates the namespace and runs `[command...]` as `capture`, or exits nonzero before Node starts.

- [ ] **Step 1: Write the firewall command-contract test before the entrypoint exists.**

  Create `test_egress_firewall.sh`. It creates a temporary `PATH` containing mock `iptables`, `ip6tables`, `getent`, and `setpriv` commands that append their arguments to `LOG`; the `setpriv` mock records its argument list without running the capture command. Run the missing entrypoint and assert these exact properties:

  ```bash
  assert_contains 'iptables -N AIHUB_CAPTURE_OUTPUT' "$LOG"
  assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 10.0.0.0/8 -j REJECT' "$LOG"
  assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 172.16.0.0/12 -j REJECT' "$LOG"
  assert_contains 'iptables -A AIHUB_CAPTURE_OUTPUT -d 169.254.0.0/16 -j REJECT' "$LOG"
  assert_contains 'ip6tables -A AIHUB_CAPTURE_OUTPUT6 -d fc00::/7 -j REJECT' "$LOG"
  assert_contains 'ip6tables -A AIHUB_CAPTURE_OUTPUT6 -d fe80::/10 -j REJECT' "$LOG"
  assert_contains 'setpriv --reuid=capture --regid=capture --clear-groups --bounding-set=-all --ambient-caps=-all -- /app/capture' "$LOG"
  ```

  Add a second run with `CAPTURE_EGRESS_FORCE_FAIL=1`; assert nonzero exit and that `setpriv` never appears in the log.

- [ ] **Step 2: Run the shell test and confirm it fails because the entrypoint is missing.**

  Run: `bash packs/web-screenshot/service/test_egress_firewall.sh`

  Expected: non-zero exit referring to `capture-entrypoint.sh`.

- [ ] **Step 3: Implement the minimal verified namespace firewall.**

  Create `capture-entrypoint.sh` with `#!/usr/bin/env bash`, `set -euo pipefail`, root-only setup, and a `CAPTURE_EGRESS_FORCE_FAIL=1` test hook that exits before setup. Use two terminal chains, `AIHUB_CAPTURE_OUTPUT` and `AIHUB_CAPTURE_OUTPUT6`; create/flush each chain, insert it as rule 1 in `OUTPUT`, then verify every inserted rule with `-C`.

  The IPv4 policy must use this exact non-public list before the final public-port accept:

  ```bash
  ipv4_blocked=(
    0.0.0.0/8 10.0.0.0/8 100.64.0.0/10 127.0.0.0/8
    169.254.0.0/16 172.16.0.0/12 192.0.0.0/24 192.0.2.0/24
    192.168.0.0/16 198.18.0.0/15 198.51.100.0/24 203.0.113.0/24
    224.0.0.0/4 240.0.0.0/4
  )
  ```

  The IPv6 chain must explicitly reject `::/96`, `::ffff:0:0/96`, `64:ff9b::/96`, `64:ff9b:1::/48`, `2001::/23`, `2001:db8::/32`, `2002::/16`, `3fff::/20`, `fc00::/7`, `fe80::/10`, and `ff00::/8`; then allow TCP 80/443 only to `2000::/3` and reject everything else.

  Parse `/etc/resolv.conf` with `awk '/^nameserver / { print $2 }'`, validate each resolver with `getent ahosts`, and insert only UDP/TCP port-53 accepts for those numeric addresses before the blocked ranges. Insert `ESTABLISHED,RELATED` accepts, then public TCP accepts. The final rule in every chain is `REJECT`; do not use `RETURN`.

  Finish only after all `iptables -C`/`ip6tables -C` checks pass:

  ```bash
  exec setpriv --reuid=capture --regid=capture --clear-groups \
    --bounding-set=-all --ambient-caps=-all -- "$@"
  ```

  In the Dockerfile, install `iptables`, create system user/group `capture`, copy the entrypoint and test/self-check sources, set executable modes, and set:

  ```dockerfile
  ENTRYPOINT ["/app/capture-entrypoint.sh"]
  CMD ["/app/capture"]
  ```

  Keep the image build user as root only for trusted setup; `/app/capture` itself is reached only through the entrypoint as `capture`.

- [ ] **Step 4: Add the in-image real namespace self-check and publish the patch version.**

  Create `egress_self_check.js` using only `node:dns`, `node:net`, `node:fs`, and global `fetch`. It must parse `CapEff` from `/proc/self/status`, fail if bit 12 (`CAP_NET_ADMIN`) is set or `process.getuid() === 0`, require `dns.lookup('example.com', { all: true })` to return at least one answer, require HTTP and HTTPS fetches of `example.com` to complete, and require each of these connection attempts to reject or time out within two seconds:

  ```js
  ['10.0.0.1', 'fc00::1', 'fe80::1', '169.254.169.254', 'host.docker.internal', dockerGateway]
  ```

  Derive `dockerGateway` from the little-endian gateway field in `/proc/net/route`. Follow an `https://httpbingo.org/redirect-to?url=http%3A%2F%2F169.254.169.254` redirect and require it to fail. Print only `egress_self_check: ok` on success.

  Change `package.json` test script to run the shell contract test before the Node policy/capture tests, update its version and package-lock root to `0.1.2`, and update Pack/image version strings to `0.1.2`. Extend Dockerfile `COPY` to include all new test files.

- [ ] **Step 5: Run static tests, then Docker namespace checks where Docker access is available.**

  Run:

  ```bash
  bash packs/web-screenshot/service/test_egress_firewall.sh
  node --check packs/web-screenshot/service/egress_self_check.js
  docker build -t 3waaihub/web-screenshot:0.1.2 packs/web-screenshot/service
  docker run --rm --network none --cap-add=NET_ADMIN 3waaihub/web-screenshot:0.1.2 npm test
  docker run --rm --network bridge --cap-add=NET_ADMIN --add-host host.docker.internal:host-gateway 3waaihub/web-screenshot:0.1.2 node egress_self_check.js
  docker run --rm --network bridge --cap-add=NET_ADMIN -e CAPTURE_EGRESS_FORCE_FAIL=1 3waaihub/web-screenshot:0.1.2 npm test
  ```

  Expected: static and first two Docker checks exit 0; the forced-failure command exits nonzero before `npm test` begins. If Docker socket permission blocks a command, record that exact failure and leave the Docker checks for CI.

- [ ] **Step 6: Commit the container runtime boundary.**

  ```bash
  git add packs/web-screenshot/service/Dockerfile packs/web-screenshot/service/capture-entrypoint.sh \
    packs/web-screenshot/service/test_egress_firewall.sh packs/web-screenshot/service/egress_self_check.js \
    packs/web-screenshot/service/package.json packs/web-screenshot/service/package-lock.json packs/web-screenshot/pack.json
  git commit -m "feat: enforce web capture egress in container"
  ```

### Task 3: Make popup handling and finalization route-safe

**Files:**
- Modify: `packs/web-screenshot/service/capture.js:78-123,253-398`
- Modify: `packs/web-screenshot/service/test_capture.js:1-150`

**Interfaces:**
- Consumes: existing `captureNavigationDecision()` and `assertMainDocumentAllowed()`.
- Produces: `closeNonPrimaryPage(primary, candidate): Promise<void>` and `trackMainDocumentRoute(routes, isPrimaryMainDocument, handler): Promise<mixed>` used by the production route callback and tests.

- [ ] **Step 1: Keep the interrupted failing popup/finalization regressions and add an output-once assertion.**

  Preserve the uncommitted `about:blank` and full-route-lifetime test changes already in `test_capture.js`. Add a pure finalization test that increments a local `writes` counter only after `assertMainDocumentAllowed()` resolves; start an in-flight primary route, set the block flag before releasing it, and assert:

  ```js
  await assert.rejects(() => finalize(), /url_not_allowed/);
  assert.equal(writes, 0, 'a blocked primary route must not publish a report');
  ```

- [ ] **Step 2: Run the capture test in the image to prove the new exports and lifecycle behavior fail first.**

  Run: `docker run --rm --network none --cap-add=NET_ADMIN 3waaihub/web-screenshot:0.1.2 node test_capture.js`

  Expected: non-zero exit because `closeNonPrimaryPage` and `trackMainDocumentRoute` do not yet exist or the old route set resolves before `route.continue()` completes. If Docker is unavailable, run `node --check test_capture.js` and record the image-only test as unrun.

- [ ] **Step 3: Implement one production lifecycle for popup closure and route draining.**

  Add these helpers in `capture.js`:

  ```js
  async function closeNonPrimaryPage(primary, candidate) {
    if (candidate !== primary && !candidate.isClosed()) {
      await candidate.close().catch(() => {});
    }
  }

  function trackMainDocumentRoute(routes, isPrimaryMainDocument, handler) {
    const lifetime = Promise.resolve().then(handler);
    if (isPrimaryMainDocument) {
      routes.add(lifetime);
      void lifetime.finally(() => routes.delete(lifetime));
    }
    return lifetime;
  }
  ```

  `context.on('page')` must call `closeNonPrimaryPage(page, candidate)` immediately, so `about:blank` closes even when no request is routed. Keep the route callback's abort/close logic as defense in depth.

  Wrap the complete route callback body—including `await route.continue()` or `await route.abort()`—in `trackMainDocumentRoute()`. Do not delete a primary-main promise before continuing. Before report construction, await the final document guard, close the context, await `Promise.allSettled([...mainDocumentRoutes])`, and throw `url_not_allowed` if the block flag changed. Set `context = undefined` only after this successful close. Call `writeReport()` exactly once on the sole success path.

- [ ] **Step 4: Run the browser-policy suite.**

  Run:

  ```bash
  docker run --rm --network none --cap-add=NET_ADMIN 3waaihub/web-screenshot:0.1.2 npm test
  ```

  Expected: policy, firewall-contract, popup, delayed-navigation, full-route-lifetime, and single-output regressions pass. If Docker is unavailable, run `node --check capture.js && node --check test_capture.js` and record the Docker test as required CI work.

- [ ] **Step 5: Commit the route lifecycle fix.**

  ```bash
  git add packs/web-screenshot/service/capture.js packs/web-screenshot/service/test_capture.js
  git commit -m "fix: close web capture route races"
  ```

### Task 4: Publish the container-local boundary and verify the integrated contract

**Files:**
- Modify: `README.md:338-349`
- Modify: `tests/test_web_screenshot_pack.php:102-130`

**Interfaces:**
- Consumes: Task 1 runner command, Task 2 image `0.1.2`, Task 3 route behavior.
- Produces: administrator documentation that distinguishes container-local firewall setup from prohibited host changes.

- [ ] **Step 1: Write failing Pack/README assertions.**

  Update the existing Web Screenshot contract test to require version/image `0.1.2`, `public_egress`, the string `container-local fail-closed egress firewall`, and both `NET_ADMIN` and `non-root user` in the scoped README section. Keep assertions that the section contains `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS`, `設定 → API 與安全`, and `Docker bridge`, and still excludes `scripts/install_capture_egress_network.sh --check`.

- [ ] **Step 2: Run the PHP harness and confirm the old release/docs assertion fails.**

  Run: `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`

  Expected: non-zero exit with the new `0.1.2` or container-local-documentation assertion failure before the README/manifest update; record unrelated baseline failures separately.

- [ ] **Step 3: Update only the Web Screenshot README subsection.**

  Replace its first paragraph with:

  ```markdown
  `web_capture` uses Docker's existing `bridge` network and a container-local
  fail-closed egress firewall. It does not require a host iptables command or
  a dedicated Docker network. The immutable Web Screenshot container receives
  `NET_ADMIN` only while its trusted entrypoint installs and verifies the
  policy; it then removes capabilities and runs Chromium as a non-root user.
  ```

  Retain the current exact-host allowlist, same-host redirect, CDN public-IP,
  and trusted-host guidance. Do not change the following command-worker text
  or delete the legacy installer/systemd/cron assets.

- [ ] **Step 4: Run repository checks and all Docker-capable acceptance checks.**

  Run:

  ```bash
  git diff --check
  AIHUB_TEST_QUIET=1 php scripts/run_tests.php
  docker build -t 3waaihub/web-screenshot:0.1.2 packs/web-screenshot/service
  docker run --rm --network none --cap-add=NET_ADMIN 3waaihub/web-screenshot:0.1.2 npm test
  docker run --rm --network bridge --cap-add=NET_ADMIN --add-host host.docker.internal:host-gateway 3waaihub/web-screenshot:0.1.2 node egress_self_check.js
  ```

  Expected: static checks are clean; PHP reports `failures=0`; image tests prove public HTTP/HTTPS and DNS work, while private IPv4, ULA/link-local IPv6, metadata, host gateway, Docker gateway, private redirect, popup bypass, firewall-init failure, runtime `NET_ADMIN`, and duplicate finalization all fail closed as specified.

- [ ] **Step 5: Commit documentation and integrated assertions.**

  ```bash
  git add README.md tests/test_web_screenshot_pack.php
  git commit -m "docs: describe container-local capture egress"
  git status --short --branch
  ```
