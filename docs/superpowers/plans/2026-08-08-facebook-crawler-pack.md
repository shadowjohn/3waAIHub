# Facebook Crawler HubPack Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a portable CPU HubPack that manually queues recent Facebook page/group crawls, keeps member-owned login state on the installing node, and exposes Apify-like task datasets.

**Architecture:** Reuse the supplied Python Playwright extractor inside one governed Pack runner. 3waAIHub owns profile metadata, the node-private browser-state file, task admission, locking, artifacts, retention, API auth, and UI; the Pack receives only normalized target JSON plus a read-only mount for the selected profile. Login uses a short-lived loopback broker and a same-origin screenshot/input relay, so Linux, Apache/nginx, and Windows WSL do not need new public ports or WebSocket configuration.

**Tech Stack:** PHP 8.2, SQLite, existing 3waAIHub Pack/task/artifact APIs, Python 3.11, Playwright 1.61.1 Chromium, Docker, Windows WSL2 adapter, vanilla JavaScript, existing `__()` i18n.

---

## File Map

Create:

- `app/facebook_crawler.php` - profile repository, target validation, task admission, profile lock, and dataset reads.
- `app/facebook_crawler_login.php` - ephemeral login-broker lifecycle and same-origin relay handlers.
- `facebook_profile_login.php` - one-time browser login page; no API Token or proof in the request URI.
- `admin/_playground_facebook_crawler.php` - focused Test Center rendering and admin actions.
- `packs/facebook-crawler/pack.json` - fixed `facebook_crawl` async contract.
- `packs/facebook-crawler/README.md` - operator scope, install, login, API, and safety notes.
- `packs/facebook-crawler/service/Dockerfile` - pinned Python Playwright runtime.
- `packs/facebook-crawler/service/requirements.txt` - pinned Python dependencies.
- `packs/facebook-crawler/service/crawl-entrypoint.sh` - non-root runner entrypoint.
- `packs/facebook-crawler/service/crawl_runner.py` - bounded multi-target task adapter.
- `packs/facebook-crawler/service/login_broker.py` - one-profile screenshot/input login broker.
- `packs/facebook-crawler/service/crawler/**` - vetted modules imported from the supplied archive.
- `packs/facebook-crawler/service/fixtures/**` - supplied offline page/group/access-wall fixtures.
- `packs/facebook-crawler/service/tests/test_runner.py` - runner contract and partial-result checks.
- `packs/facebook-crawler/service/tests/test_login_broker.py` - relay event and secret-redaction checks.
- `scripts/facebook_profile_cleanup.php` - stop expired login containers and clear expired session metadata.
- `scripts/facebook_crawler_smoke.php` - opt-in real public-target acceptance.
- `tests/test_facebook_crawler_pack.php` - DB, API, ownership, task, mount, dataset, and Cluster-boundary tests.
- `tests/test_facebook_crawler_ui.php` - Test Center, docs, i18n, and login-page rendering tests.

Modify:

- `app/bootstrap.php` - load the two focused crawler modules and create the private runtime directory.
- `app/db.php` - add `facebook_crawler_profiles`, indexes, and runtime schema requirements.
- `app/pack_registry.php` - register the fixed `facebook_crawl` Pack-job route.
- `app/gateway.php` - dispatch crawl/profile/dataset and one-time login relay operations.
- `app/pack_job_runner.php` - acquire/release a profile lock, mount one storage-state file, and run the Windows WSL adapter.
- `app/task_queue.php` - expose and promote `waiting_profile`; allow cancellation from that state.
- `app/public_api_docs.php` - replace hidden runner fields with the public targets/profile workflow.
- `app/cluster_router.php` - exclude `facebook_crawl` from Phase A Cluster publication.
- `admin/playground.php` - register the mode and include the focused partial.
- `crontab/1min.sh` - run bounded expired-login cleanup only; it does not schedule crawls.
- `tests/suites/control-plane.php` - include crawler control-plane tests.
- `tests/suites/admin-ui.php` - include crawler UI tests.

Do not modify the currently unrelated work in `admin/environment.php`,
`app/release.php`, `tests/test_i18n.php`, or `tests/test_release_status.php`.

### Task 1: Import And Constrain The Supplied Crawler

**Files:**

- Create: `packs/facebook-crawler/service/crawler/**`
- Create: `packs/facebook-crawler/service/fixtures/**`
- Create: `packs/facebook-crawler/service/crawl_runner.py`
- Create: `packs/facebook-crawler/service/tests/test_runner.py`

- [ ] **Step 1: Import only the reusable source and fixtures**

Extract the user-supplied archive to a generated temporary directory, then copy the `crawler` package and fixture HTML as a mechanical import. Do not import `.env`, the standalone web server, its HTML UI, output data, or proxy credentials.

```bash
IMPORT_ROOT="$(mktemp -d /tmp/3waaihub-facebook-crawler.XXXXXX)"
unzip -q /home/john/.codex/attachments/b92c10fc-3e18-4eb7-b4d1-306575ad828b/dist.zip -d "$IMPORT_ROOT"
unzip -q "$IMPORT_ROOT/dist/fb_disaster_crawler-deploy-20260710.zip" -d "$IMPORT_ROOT/source"
mkdir -p packs/facebook-crawler/service
cp -R "$IMPORT_ROOT/source/fb_disaster_crawler/crawler" packs/facebook-crawler/service/crawler
cp -R "$IMPORT_ROOT/source/fb_disaster_crawler/fixtures" packs/facebook-crawler/service/fixtures
```

Expected: imported Python source contains no cookie file, `.env`, output JSONL, or SQLite database.

- [ ] **Step 2: Write failing runner tests**

Create `packs/facebook-crawler/service/tests/test_runner.py` with dependency injection so tests do not contact Facebook:

```python
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

from crawl_runner import execute
from crawler.retry.policy import AttemptResult, ErrorClass, EXIT_OK, EXIT_SESSION_FAILURE


class RunnerTest(unittest.TestCase):
    def test_multi_target_partial_dataset(self):
        request = {
            "targets_json": json.dumps([
                {"url": "https://www.facebook.com/wra.gov.tw"},
                {"url": "https://www.facebook.com/groups/123456789"},
            ]),
            "limit_per_target": 10,
        }
        scrape = Mock(side_effect=[
            AttemptResult(
                error_class=ErrorClass.OK,
                exit_code=EXIT_OK,
                message="ok",
                records=[{"source_url": request["targets_json"], "post_url": "https://www.facebook.com/posts/1", "content": "防災資訊", "fetched_at": "2026-08-08T00:00:00+00:00"}],
                meta={"duration_seconds": 0.1},
            ),
            AttemptResult(
                error_class=ErrorClass.SESSION,
                exit_code=EXIT_SESSION_FAILURE,
                message="private group — not a member",
                records=[],
                meta={"health_code": "group_access_denied", "duration_seconds": 0.1},
            ),
        ])
        with tempfile.TemporaryDirectory() as temp:
            result = execute(request, Path(temp), scrape=scrape)
            report = json.loads((Path(temp) / "facebook_crawl_report.json").read_text())
            lines = (Path(temp) / "facebook_posts.jsonl").read_text().splitlines()
        self.assertEqual(result, 0)
        self.assertEqual(report["outcome"], "partial")
        self.assertEqual([item["status"] for item in report["targets"]], ["completed", "not_accessible"])
        self.assertEqual(len(lines), 1)

    def test_no_accessible_target_fails_without_success_dataset(self):
        scrape = Mock(return_value=AttemptResult(
            error_class=ErrorClass.SESSION,
            exit_code=EXIT_SESSION_FAILURE,
            message="login wall",
            records=[],
            meta={"health_code": "login_required"},
        ))
        request = {"targets_json": '[{"url":"https://www.facebook.com/groups/1"}]', "limit_per_target": 10}
        with tempfile.TemporaryDirectory() as temp:
            self.assertEqual(execute(request, Path(temp), scrape=scrape), 2)
            self.assertFalse((Path(temp) / "facebook_posts.jsonl").exists())


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 3: Run the tests to verify they fail**

Run:

```bash
cd packs/facebook-crawler/service
python3 -m unittest tests.test_runner -v
```

Expected: FAIL because `crawl_runner` does not exist.

- [ ] **Step 4: Add the minimal governed runner**

Create `crawl_runner.py`. It must accept only the hidden Pack contract, force `headless=True`, `stealth=False`, `human=False`, `proxy=None`, two transient attempts, and a bounded scroll count derived from 10-30 requested posts.

```python
from __future__ import annotations

import hashlib
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from crawler.discovery.facebook import target_from_user_input
from crawler.retry.policy import ErrorClass, RetryConfig
from crawler.scraper import ScrapeOptions, scrape_target


def _dedupe(records: list[dict]) -> list[dict]:
    seen: set[str] = set()
    kept: list[dict] = []
    for record in records:
        content = " ".join(str(record.get("content", "")).split())
        post_url = str(record.get("post_url", "")).strip()
        source_url = str(record.get("source_url", "")).strip()
        key = post_url if post_url and post_url != source_url else source_url + "|" + hashlib.sha256(content.encode()).hexdigest()
        if content and key not in seen:
            seen.add(key)
            kept.append(record)
    return kept


def execute(request: dict, output_dir: Path, scrape: Callable = scrape_target) -> int:
    targets = json.loads(request["targets_json"])
    limit = int(request.get("limit_per_target", 10))
    state = Path("/data/facebook_profile/storage_state.json")
    options = ScrapeOptions(
        headless=True,
        stealth=False,
        human=False,
        storage_state=state if state.is_file() else None,
        proxy=None,
        retry=RetryConfig(max_attempts=2),
    )
    records: list[dict] = []
    outcomes: list[dict] = []
    for item in targets:
        target = target_from_user_input(item["url"], kind="auto", scrolls=min(5, max(1, (limit + 9) // 10)), limit=limit, allow_zero=True)
        result = scrape(target, options)
        health = str((result.meta or {}).get("health_code", ""))
        status = "completed" if result.error_class is ErrorClass.OK else "empty" if result.error_class is ErrorClass.ZERO_RECORDS else "not_accessible" if health == "group_access_denied" else "login_required" if result.error_class is ErrorClass.SESSION else "navigation_failed"
        outcomes.append({"url": item["url"], "status": status, "count": len(result.records or []), "message": str(result.message)[:240]})
        records.extend(result.records or [])
    records = _dedupe(records)
    valid = [item for item in outcomes if item["status"] in ("completed", "empty")]
    if not valid:
        return 2
    output_dir.mkdir(parents=True, exist_ok=True)
    dataset = output_dir / "facebook_posts.jsonl"
    dataset.write_text("".join(json.dumps(item, ensure_ascii=False) + "\n" for item in records), encoding="utf-8")
    report = {
        "outcome": "complete" if len(valid) == len(outcomes) else "partial",
        "target_count": len(outcomes),
        "post_count": len(records),
        "limit_per_target": limit,
        "targets": outcomes,
        "created_at": datetime.now(timezone.utc).isoformat(),
        "runner_version": "0.1.0",
    }
    (output_dir / "facebook_crawl_report.json").write_text(json.dumps(report, ensure_ascii=False), encoding="utf-8")
    return 0


if __name__ == "__main__":
    request = json.loads(Path("/workspace/input/request.json").read_text(encoding="utf-8"))
    raise SystemExit(execute(request, Path("/workspace/output")))
```

Keep the supplied health/extraction implementation, but delete proxy loading from the executable path and do not load `.env`. Preserve its explicit session-failure no-retry behavior.

- [ ] **Step 5: Run the imported and new Python unit tests**

Run:

```bash
cd packs/facebook-crawler/service
python3 -m unittest tests.test_runner -v
python3 -m unittest discover -s tests -v
```

Expected: PASS; no test performs network access.

- [ ] **Step 6: Commit the runner core**

```bash
git add packs/facebook-crawler/service/crawler packs/facebook-crawler/service/fixtures packs/facebook-crawler/service/crawl_runner.py packs/facebook-crawler/service/tests/test_runner.py
git commit -m "feat: add governed Facebook crawler runner"
```

### Task 2: Add The Portable Pack Contract

**Files:**

- Create: `packs/facebook-crawler/pack.json`
- Create: `packs/facebook-crawler/service/Dockerfile`
- Create: `packs/facebook-crawler/service/requirements.txt`
- Create: `packs/facebook-crawler/service/crawl-entrypoint.sh`
- Create: `tests/test_facebook_crawler_pack.php`
- Modify: `app/pack_registry.php:98-109`
- Modify: `tests/suites/control-plane.php`

- [ ] **Step 1: Write failing manifest and route tests**

Start `tests/test_facebook_crawler_pack.php` with:

```php
<?php
declare(strict_types=1);

hub_test('Facebook crawler Pack declares one fixed CPU job', function (): void {
    $pack = hub_get_pack('facebook-crawler');
    hub_test_assert(is_array($pack) && $pack['status'] === 'ok', 'facebook-crawler Pack must validate');
    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_ready'] ?? false) === true, 'crawler runtime must be ready');
    hub_test_assert(($manifest['platform_targets'] ?? []) === [
        'linux-docker' => true,
        'windows-wsl2-linux-docker' => true,
    ], 'crawler must use the shared Linux/WSL container runtime');
    $contract = hub_pack_async_job_contract($manifest, 'crawl');
    hub_test_assert(($contract['runner']['accelerator'] ?? '') === 'cpu', 'crawler must not request GPU');
    hub_test_assert(($contract['runner']['network_profile'] ?? '') === 'public_egress', 'crawler requires bounded public egress');
    hub_test_assert(array_keys($contract['request_schema'] ?? []) === ['profile_id', 'targets_json', 'limit_per_target'], 'runner inputs must remain fixed');
});

hub_test('Facebook crawl resolves only the managed Pack route', function (): void {
    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'facebook-crawler', ['idempotent' => true]);
    hub_set_service_enabled($db, 'facebook_crawl', true);
    $route = hub_resolve_pack_job_async_route($db, 'facebook_crawl');
    hub_test_assert(($route['pack_id'] ?? '') === 'facebook-crawler'
        && ($route['job'] ?? '') === 'crawl'
        && ($route['accelerator'] ?? '') === 'cpu', 'crawler route cannot be selected by clients');
});
```

Add the file once to `tests/suites/control-plane.php`.

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php scripts/run_tests.php --suite=control-plane
```

Expected: FAIL because the Pack and route do not exist.

- [ ] **Step 3: Add the fixed route and manifest**

Add this one route to `hub_pack_job_async_routes()`:

```php
'facebook_crawl' => ['pack_id' => 'facebook-crawler', 'job' => 'crawl', 'accelerator' => 'cpu'],
```

Create `pack.json` with these contract-defining sections; do not add user-configurable browser flags:

```json
{
  "schema_version": "0.1",
  "id": "facebook-crawler",
  "name": "Facebook Recent Post Crawler",
  "version": "0.1.0",
  "category": "web",
  "type": "internal_task",
  "execution_type": "async_task",
  "runtime_level": "L3-container-runner",
  "runtime_ready": true,
  "default_mode": "facebook_crawl",
  "description": "輕量擷取授權 Facebook 粉專與社團的近期貼文；CPU 執行，登入失效時需人工重新登入。",
  "runtime": {"kind": "internal_task", "windows_wsl_job": true},
  "platform_targets": {"linux-docker": true, "windows-wsl2-linux-docker": true},
  "runner_build": {"context": "service", "dockerfile": "Dockerfile", "image": "3waaihub/facebook-crawler:0.1.0"},
  "gateway": {"invoke_path": "task_submit:pack_job", "methods": ["POST"], "timeout_sec": 1800, "max_upload_mb": 1, "require_service_enabled": true},
  "async_jobs": [{
    "job": "crawl",
    "input": {
      "fields": ["profile_id", "targets_json", "limit_per_target"],
      "source_artifact_types": [],
      "source_required": false,
      "request_schema": {
        "profile_id": {"type": "string", "required": false, "max_length": 68},
        "targets_json": {"type": "string", "required": true, "max_length": 16384},
        "limit_per_target": {"type": "integer", "required": false, "min": 10, "max": 30, "default": 10}
      }
    },
    "runner": {
      "image": "3waaihub/facebook-crawler:0.1.0",
      "entrypoint": ["/app/crawl-entrypoint.sh", "/app/crawl_runner.py"],
      "args": [],
      "output_dir": "output",
      "accelerator": "cpu",
      "required_vram_mb": 0,
      "timeout_seconds": 1800,
      "network_profile": "public_egress",
      "executor": "container"
    },
    "output": {"artifacts": [
      {"type": "facebook_posts_jsonl", "path": "facebook_posts.jsonl", "mime_types": ["application/x-ndjson", "text/plain"], "max_bytes": 10485760, "text": {"max_bytes": 10485760}},
      {"type": "facebook_crawl_report", "path": "facebook_crawl_report.json", "mime_types": ["application/json"], "max_bytes": 262144, "json": {"required_keys": ["outcome", "target_count", "post_count", "limit_per_target", "targets", "created_at", "runner_version"]}}
    ]}
  }],
  "hardware": {"gpu_required": false, "gpu_supported": false, "min_vram_mb": 0},
  "queue": {"supported": true, "default_queue": "cpu", "max_concurrency": 1},
  "storage": {"mounts": []},
  "env": [],
  "preflight": {"checks": ["docker"]},
  "install": {"default_service_key": "facebook-crawler-main", "compose_project": "3waaihub_facebook_crawler_main"}
}
```

- [ ] **Step 4: Add the pinned non-root container**

Use `mcr.microsoft.com/playwright/python:v1.61.1-jammy`, pin `playwright==1.61.1`, create user `crawler`, and keep only `/workspace/output` writable. `crawl-entrypoint.sh` must apply the existing public-egress firewall helper pattern before dropping privileges and executing Python.

```dockerfile
FROM mcr.microsoft.com/playwright/python:v1.61.1-jammy AS base
USER root
WORKDIR /app
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && apt-get update \
    && apt-get install -y --no-install-recommends acl iptables \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system crawler \
    && useradd --system --gid crawler --home-dir /app --shell /usr/sbin/nologin crawler
COPY crawler ./crawler
COPY crawl_runner.py crawl-entrypoint.sh ./
RUN sed -i 's/\r$//' crawl-entrypoint.sh \
    && chmod 0755 crawl-entrypoint.sh crawl_runner.py \
    && install -d -o crawler -g crawler -m 0700 /workspace/output
ENTRYPOINT ["/app/crawl-entrypoint.sh"]
CMD ["/app/crawl_runner.py"]

FROM base AS test
COPY tests ./tests

FROM base AS runtime
```

`requirements.txt`:

```text
playwright==1.61.1
```

- [ ] **Step 5: Run validation and image tests**

Run:

```bash
php scripts/run_tests.php --suite=control-plane
docker build --target test -t 3waaihub/facebook-crawler:0.1.0-test packs/facebook-crawler/service
docker run --rm --entrypoint python 3waaihub/facebook-crawler:0.1.0-test -m unittest discover -s /app/tests -v
docker build -t 3waaihub/facebook-crawler:0.1.0 packs/facebook-crawler/service
```

Expected: route/manifest tests PASS; the test target passes Python tests; the final runtime image builds without carrying `/app/tests`.

- [ ] **Step 6: Commit the Pack contract**

```bash
git add app/pack_registry.php packs/facebook-crawler/pack.json packs/facebook-crawler/service/Dockerfile packs/facebook-crawler/service/requirements.txt packs/facebook-crawler/service/crawl-entrypoint.sh tests/test_facebook_crawler_pack.php tests/suites/control-plane.php
git commit -m "feat: register Facebook crawler HubPack"
```

### Task 3: Add Owner-Scoped Profile Storage

**Files:**

- Create: `app/facebook_crawler.php`
- Modify: `app/db.php:236-275, 875-1045, 1290-1320`
- Modify: `app/bootstrap.php:35-70, 75-90`
- Test: `tests/test_facebook_crawler_pack.php`

- [ ] **Step 1: Write failing schema, ownership, and permission tests**

Append tests that create two API members and prove the second cannot resolve the first member's profile. Also assert the storage directory is `0700`, storage-state file is `0600`, and symlinks/hardlinks are rejected.

```php
hub_test('Facebook crawler profiles are member-owned and node-private', function (): void {
    $db = hub_test_reset_db();
    $memberA = hub_create_api_member($db, 'Crawler A');
    $memberB = hub_create_api_member($db, 'Crawler B');
    $profile = hub_facebook_profile_create($db, $memberA, 'WRA account');
    $path = hub_facebook_profile_state_path($profile);
    hub_test_assert(hub_facebook_profile_for_member($db, $profile['profile_id'], $memberA) !== null, 'owner must resolve profile');
    hub_test_assert(hub_facebook_profile_for_member($db, $profile['profile_id'], $memberB) === null, 'other member must not resolve profile');
    hub_test_assert((fileperms(dirname($path)) & 0777) === 0700, 'profile directory must be private');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php scripts/run_tests.php --suite=control-plane
```

Expected: FAIL because profile schema/helpers do not exist.

- [ ] **Step 3: Add the profile table and required schema contract**

Add this table in `hub_migrate()` and add it to `hub_runtime_required_schema()`:

```sql
CREATE TABLE IF NOT EXISTS facebook_crawler_profiles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_id TEXT NOT NULL UNIQUE,
    owner_member_id INTEGER NOT NULL,
    node_name TEXT NOT NULL,
    display_name TEXT NOT NULL,
    state TEXT NOT NULL DEFAULT 'preparing',
    last_verified_at TEXT NULL,
    active_task_id INTEGER NULL,
    login_secret_hash TEXT NULL,
    login_container_name TEXT NULL,
    login_port INTEGER NULL,
    login_expires_at TEXT NULL,
    deleted_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(owner_member_id) REFERENCES api_members(id) ON DELETE CASCADE,
    FOREIGN KEY(active_task_id) REFERENCES tasks(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_facebook_profiles_owner
    ON facebook_crawler_profiles(owner_member_id, deleted_at, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_facebook_profiles_login_expiry
    ON facebook_crawler_profiles(login_expires_at) WHERE login_expires_at IS NOT NULL;
```

- [ ] **Step 4: Implement the minimal repository and safe paths**

In `app/facebook_crawler.php`, define constants through functions rather than environment variables:

```php
function hub_facebook_profile_root(): string
{
    $root = HUB_DATA_DIR . '/facebook-crawler/profiles';
    if (is_link($root) || (!is_dir($root) && !mkdir($root, 0700, true))) {
        throw new RuntimeException('profile_storage_unavailable');
    }
    @chmod($root, 0700);
    return $root;
}

function hub_facebook_profile_id(): string
{
    return 'fbp_' . bin2hex(random_bytes(24));
}

function hub_facebook_profile_state_path(array $profile): string
{
    $profileId = (string)($profile['profile_id'] ?? '');
    if (preg_match('/\Afbp_[a-f0-9]{48}\z/', $profileId) !== 1) {
        throw new RuntimeException('profile_storage_unavailable');
    }
    return hub_facebook_profile_root() . '/' . $profileId . '/storage_state.json';
}
```

Create/query/delete by `owner_member_id`; cap active profiles at 20 per member. Derive the path from `profile_id` instead of storing a host path in SQLite. On delete, stop any broker first, verify the file is a single-link regular file beneath the profile root, truncate/unlink it, remove the empty directory, then set `deleted_at`.

- [ ] **Step 5: Load the module and runtime directory**

Require `facebook_crawler.php` after task APIs and add `HUB_DATA_DIR . '/facebook-crawler/profiles'` to `hub_ensure_runtime_dirs()`. Explicitly `chmod(0700)` after creation because the global umask may be permissive.

- [ ] **Step 6: Run tests and commit**

```bash
php scripts/run_tests.php --suite=control-plane
git add app/db.php app/bootstrap.php app/facebook_crawler.php tests/test_facebook_crawler_pack.php
git commit -m "feat: add private Facebook crawler profiles"
```

Expected: control-plane PASS; no browser-state path appears in an API-facing profile array.

### Task 4: Implement Short-Lived Interactive Login

**Files:**

- Create: `packs/facebook-crawler/service/login_broker.py`
- Create: `packs/facebook-crawler/service/tests/test_login_broker.py`
- Modify: `packs/facebook-crawler/service/Dockerfile`
- Create: `app/facebook_crawler_login.php`
- Create: `facebook_profile_login.php`
- Create: `scripts/facebook_profile_cleanup.php`
- Modify: `app/bootstrap.php`
- Modify: `app/gateway.php:4-124`
- Modify: `crontab/1min.sh`
- Test: `tests/test_facebook_crawler_pack.php`
- Test: `tests/test_facebook_crawler_ui.php`

- [ ] **Step 1: Write failing broker protocol tests**

Test the broker handler without launching Chromium. Inject a fake page and assert these exact operations:

```python
class BrokerTest(unittest.TestCase):
    def test_frame_and_input_are_bounded(self):
        page = FakePage(png=b"\x89PNG\r\n\x1a\n")
        broker = LoginSession(page=page, state_path=Path("/profile/storage_state.json"))
        self.assertEqual(broker.frame(), b"\x89PNG\r\n\x1a\n")
        broker.input({"type": "click", "x": 320, "y": 180})
        broker.input({"type": "text", "text": "123456"})
        self.assertEqual(page.events, [("click", 320, 180), ("text", "123456")])
        with self.assertRaises(ValueError):
            broker.input({"type": "text", "text": "x" * 257})

    def test_status_never_returns_cookies_or_passwords(self):
        status = LoginSession(FakePage(logged_in=True), Path("/profile/storage_state.json")).status()
        self.assertEqual(set(status), {"ok", "state", "logged_in"})
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
cd packs/facebook-crawler/service
python3 -m unittest tests.test_login_broker -v
```

Expected: FAIL because `login_broker` does not exist.

- [ ] **Step 3: Implement the loopback broker**

Use Python stdlib `ThreadingHTTPServer`; no Flask/FastAPI dependency. It serves only JSON/PNG endpoints and binds `0.0.0.0:8765` inside the container, while Docker publishes it to host loopback only.

```python
ALLOWED_EVENTS = {"click", "text", "key", "scroll"}

def validate_event(payload: dict) -> dict:
    kind = payload.get("type")
    if kind not in ALLOWED_EVENTS:
        raise ValueError("input_invalid")
    if kind == "click" and not all(isinstance(payload.get(k), int) and 0 <= payload[k] <= 4096 for k in ("x", "y")):
        raise ValueError("input_invalid")
    if kind == "text" and (not isinstance(payload.get("text"), str) or len(payload["text"]) > 256):
        raise ValueError("input_invalid")
    return payload
```

Broker endpoints:

```text
GET  /health       -> {ok:true}
GET  /status       -> {ok,state,logged_in}
GET  /frame        -> image/png, max 3 MiB
POST /input        -> one validated click/text/key/scroll event
POST /credentials  -> username/password typed into detected login fields
POST /close        -> save state only when c_user exists, then stop
```

The broker never echoes request bodies, cookies, page HTML, URLs containing tokens, or storage paths. It uses a 1280x720 Chromium context, allows only Facebook navigation, and automatically writes `/profile/storage_state.json` with mode `0600` after detecting `c_user`.

Update the `base` stage in `Dockerfile` to copy `login_broker.py` and mark it `0755`. Keep the existing `test` and final `runtime` stages unchanged.

- [ ] **Step 4: Write failing PHP lifecycle and secret tests**

Use injected command/transport callbacks. Assert:

- a start URL is `facebook_profile_login.php#session=...`, never `?session=`;
- only `hash('sha256', $secret)` is stored;
- username/password reach only the injected broker POST body;
- neither value appears in profile rows, tasks, API logs, task logs, command arguments, or returned JSON;
- expired sessions stop the exact validated container name and clear port/hash fields;
- another member's API Token cannot start/status/reauth/delete the profile.

- [ ] **Step 5: Implement Linux and Windows WSL broker lifecycle**

In `app/facebook_crawler_login.php`, build commands as arrays. Linux starts:

```php
[
    'docker', 'run', '-d', '--rm', '--pull=never',
    '--network', 'bridge',
    '--publish', '127.0.0.1::8765',
    '--mount', 'type=bind,src=' . $profileDir . ',dst=/profile',
    '--name', $containerName,
    '--entrypoint', '/app/crawl-entrypoint.sh',
    '3waaihub/facebook-crawler:0.1.0', '/app/login_broker.py',
]
```

Resolve the assigned host port only through `docker port <validated-name> 8765/tcp`, accept `127.0.0.1:<port>` only, and persist it after the broker health check succeeds. On Windows call the same Docker command through `hub_wsl_service_runtime()` and `hub_wsl_script_command()`; convert the generated profile directory with `wslpath -a` before creating the bind mount. If loopback forwarding cannot reach `/health`, return `login_broker_unavailable` and stop the container.

Password-assisted start sends credentials in one internal HTTP POST body after the container starts. Never place credentials in Docker args, env, `.env`, SQLite, or a queued task.

- [ ] **Step 6: Add authenticated relay operations**

Normal Token-authenticated operations all authorize against `facebook_crawl` permission:

```text
facebook_profile_start
facebook_profile_status
facebook_profile_reauth
facebook_profile_delete
```

The one-time page uses separate session-proof operations; all four use `POST` even when the response is PNG or status JSON:

```text
facebook_profile_frame
facebook_profile_input
facebook_profile_login_status
facebook_profile_close
```

The page reads the secret from `location.hash`, immediately calls `history.replaceState()` to remove it, and sends it only in each relay POST body. The gateway hashes that body field, finds one unexpired `preparing` profile with `hash_equals()`, removes the proof before building any log context, and dispatches only the four relay operations. It attaches the profile's member ID to the access-log context but never logs the body or proof.

- [ ] **Step 7: Add the one-time page and cleanup**

`facebook_profile_login.php` is a small same-origin page with a stable 16:9 image surface, password-safe text input, pointer mapping, scroll buttons/icons, status, and close command. All visible strings use `__()` and all JavaScript/CSS is inline or local.

`scripts/facebook_profile_cleanup.php` calls one bounded helper that handles at most 10 expired sessions. Add one line to `crontab/1min.sh`:

```bash
php "$APP_ROOT/scripts/facebook_profile_cleanup.php" --limit=10 >> "$APP_ROOT/data/logs/facebook-profile-cleanup.log" 2>&1
```

This is security cleanup, not crawl scheduling.

- [ ] **Step 8: Run tests and commit**

```bash
python3 -m unittest packs/facebook-crawler/service/tests/test_login_broker.py -v
docker build --target test -t 3waaihub/facebook-crawler:0.1.0-test packs/facebook-crawler/service
docker build -t 3waaihub/facebook-crawler:0.1.0 packs/facebook-crawler/service
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
git add packs/facebook-crawler/service/Dockerfile packs/facebook-crawler/service/login_broker.py packs/facebook-crawler/service/tests/test_login_broker.py app/facebook_crawler_login.php app/bootstrap.php app/gateway.php facebook_profile_login.php scripts/facebook_profile_cleanup.php crontab/1min.sh tests/test_facebook_crawler_pack.php tests/test_facebook_crawler_ui.php tests/suites/admin-ui.php
git commit -m "feat: add secure Facebook profile login"
```

New public PHP file mode must be `0755`; Python/entrypoint executables `0755`; JS/CSS/Markdown/JSON `0644`.

### Task 5: Admit Crawl Tasks And Serialize Profile Use

**Files:**

- Modify: `app/facebook_crawler.php`
- Modify: `app/gateway.php:25-71, 1287-1460`
- Modify: `app/pack_job_runner.php:95-147, 1533-1705, 2515-2825`
- Modify: `app/task_queue.php:28-107, 562-678, 1002-1080`
- Test: `tests/test_facebook_crawler_pack.php`

- [ ] **Step 1: Write failing URL/admission tests**

Test a JSON request with 1-30 objects of exactly `{"url":"..."}`. Accept only HTTPS `facebook.com` subdomains and normalized page/group paths. Reject URL credentials, fragments, non-default ports, localhost/private IP literals, duplicate canonical URLs, hashtag/search URLs, arrays over 30, and limits outside 10-30.

```php
hub_test('Facebook crawl admission stores only normalized managed input', function (): void {
    $db = hub_test_facebook_ready_service();
    [$memberId, $token] = hub_test_facebook_member_token($db, 'facebook_crawl');
    $profile = hub_facebook_profile_create($db, $memberId, 'Public sources');
    hub_facebook_profile_mark_ready($db, $profile['profile_id']);
    $response = hub_test_facebook_json_request($db, 'facebook_crawl', $token, [
        'profile_id' => $profile['profile_id'],
        'targets' => [
            ['url' => 'https://www.facebook.com/wra.gov.tw/'],
            ['url' => 'https://www.facebook.com/groups/123456789'],
        ],
        'limit_per_target' => 20,
    ]);
    $payload = json_decode($response['body'], true);
    $task = hub_get_task($db, (int)$payload['task_id']);
    hub_test_assert(array_keys($task['input']) === ['profile_id', 'targets_json', 'limit_per_target'], 'task input must contain no browser controls or secrets');
    hub_test_assert(!str_contains($task['input_json'], 'password') && !str_contains($task['input_json'], 'cookie'), 'task JSON must contain no login secret');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php scripts/run_tests.php --suite=control-plane
```

Expected: FAIL because custom array admission is absent.

- [ ] **Step 3: Add dedicated public JSON admission**

Intercept `facebook_crawl` after normal Pack route resolution/auth and before `hub_api_pack_job_task_submit()`. Parse JSON once, require an API member, validate profile ownership/readiness, canonicalize targets, then call the existing `hub_enqueue_owned_pack_job()`:

```php
$input = [
    'targets_json' => json_encode($targets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
    'limit_per_target' => $limit,
];
if ($profile !== null) {
    $input = ['profile_id' => $profile['profile_id']] + $input;
}
$taskId = hub_enqueue_owned_pack_job(
    $db,
    $route,
    $input,
    $memberId,
    (int)$authContext['token_id'],
    hub_get_client_ip()
);
return hub_gateway_json(200, hub_task_submit_response($taskId));
```

Do not extend the global Pack schema to arbitrary arrays; `targets_json` remains an internal runner field and public docs show `targets`.

- [ ] **Step 4: Write failing lock/wait/cancel tests**

Create two queued tasks for one ready profile. Assert the first acquires `active_task_id`; the second transitions to task/runtime state `waiting_profile` with `waiting_reason=profile_busy`; cancellation works from that state; release promotes a due waiter; a different profile or anonymous task is unaffected.

- [ ] **Step 5: Add the minimal profile lock state**

Implement atomic acquire/release in `app/facebook_crawler.php` with `BEGIN IMMEDIATE`:

```sql
UPDATE facebook_crawler_profiles
SET active_task_id = :task_id, updated_at = :now
WHERE profile_id = :profile_id
  AND owner_member_id = :owner_member_id
  AND state = 'ready'
  AND deleted_at IS NULL
  AND active_task_id IS NULL;
```

Before acquire, clear `active_task_id` only when its referenced task is terminal or missing. Never steal from a queued/running/waiting task. Release uses both `profile_id` and `active_task_id` as its fence.

Add `hub_facebook_wait_for_profile()` and `hub_promote_due_waiting_profile_task()` beside the existing GPU wait functions. Use a 5-second retry. Extend status messages/fields and `hub_cancel_task()` for `waiting_profile`; do not rename or refactor the existing GPU path.

The transition must update both `tasks.status` and `runtime_runs.state` to `waiting_profile`, clear the worker/task fences, and leave no started container or staged workspace. Call the profile promotion helper from `hub_claim_next_task()` beside GPU promotion; include `waiting_profile` in cancellation and retention-busy checks so a waiter cannot be purged as idle.

- [ ] **Step 6: Mount exactly one profile state file**

Add a separate optional `facebook_profile_mount` beside `voice_profile_mount`. Validate owner, profile readiness, path containment, regular file, one hardlink, and exact container destination `/data/facebook_profile/storage_state.json`. Add only:

```text
--mount type=bind,src=<verified state>,dst=/data/facebook_profile/storage_state.json,readonly
```

Acquire before runner preparation and release in the outer `finally` for every success, failure, cancel, timeout, fence loss, and thrown exception. If no profile is supplied, add no mount or lock.

- [ ] **Step 7: Add the Windows WSL execution adapter**

Follow `hub_web_screenshot_wsl_execution_plan()` with crawler-specific fixed image/entrypoint checks. Copy `request.json` into the WSL job root, bind-mount the verified profile state read-only via its `wslpath`, copy only `facebook_posts.jsonl` and `facebook_crawl_report.json` back to the Windows task workspace, and remove the WSL job root in a trap. No cookie copy enters the Windows task workspace.

- [ ] **Step 8: Run tests and commit**

```bash
php scripts/run_tests.php --suite=control-plane
git add app/facebook_crawler.php app/gateway.php app/pack_job_runner.php app/task_queue.php tests/test_facebook_crawler_pack.php
git commit -m "feat: queue node-owned Facebook crawl tasks"
```

### Task 6: Add Latest-Run And Paged Dataset APIs

**Files:**

- Modify: `app/facebook_crawler.php`
- Modify: `app/gateway.php`
- Test: `tests/test_facebook_crawler_pack.php`

- [ ] **Step 1: Write failing ownership, pagination, and expiry tests**

Register a controlled JSONL artifact on a successful `facebook_crawl` task. Assert:

- `facebook_run_last` returns the newest terminal task owned by the member;
- no `task_id` selects the newest available successful dataset;
- explicit `task_id`, `offset`, and `limit` return deterministic slices;
- another member receives 404;
- invalid JSONL fails closed as `dataset_invalid`;
- purged/expired artifacts return `410 dataset_expired`;
- `limit > 500`, negative offset, and non-integers return 400.

- [ ] **Step 2: Run the test to verify it fails**

```bash
php scripts/run_tests.php --suite=control-plane
```

Expected: FAIL because dataset modes are not registered.

- [ ] **Step 3: Add member-scoped lookup helpers**

Query only tasks with `owner_member_id=:member`, `requested_mode='facebook_crawl'`, and a terminal state. Resolve the artifact by `artifact_type='facebook_posts_jsonl'`, `state='available'`, and `purged_at IS NULL`. Do not accept a path from the request or task result JSON.

- [ ] **Step 4: Add bounded line-by-line pagination**

Use `SplFileObject`; never load the full artifact:

```php
function hub_facebook_dataset_page(string $path, int $offset, int $limit): array
{
    $file = new SplFileObject($path, 'rb');
    $items = [];
    $index = 0;
    while (!$file->eof() && count($items) < $limit) {
        $line = $file->fgets();
        if ($line === '' || trim($line) === '') {
            continue;
        }
        if (strlen($line) > 1024 * 1024) {
            throw new RuntimeException('dataset_invalid');
        }
        if ($index++ < $offset) {
            continue;
        }
        $item = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($item) || array_is_list($item)) {
            throw new RuntimeException('dataset_invalid');
        }
        $items[] = $item;
    }
    return ['items' => $items, 'next_offset' => count($items) === $limit ? $offset + count($items) : null];
}
```

Return `task_id`, `offset`, `limit`, `count`, `next_offset`, and `items`. Update `last_accessed_at` through the existing artifact access helper.

- [ ] **Step 5: Register and dispatch the convenience modes**

Authorize both modes against `facebook_crawl` permission while logging their real operation names:

```text
GET api.php?mode=facebook_run_last
GET api.php?mode=facebook_dataset_items&task_id=123&offset=0&limit=100
```

Keep generic `task_result` and `artifact` unchanged and token-bound; the convenience APIs are member-bound as approved.

- [ ] **Step 6: Run tests and commit**

```bash
php scripts/run_tests.php --suite=control-plane
git add app/facebook_crawler.php app/gateway.php tests/test_facebook_crawler_pack.php
git commit -m "feat: expose Facebook crawl datasets"
```

### Task 7: Add Test Center, API Docs, And Phase A Cluster Boundary

**Files:**

- Create: `admin/_playground_facebook_crawler.php`
- Create: `packs/facebook-crawler/README.md`
- Modify: `admin/playground.php:4-30, 95-224, form rendering section`
- Modify: `app/public_api_docs.php:610-699`
- Modify: `app/cluster_router.php:4282-4305`
- Modify: `tests/test_facebook_crawler_ui.php`
- Modify: `tests/test_facebook_crawler_pack.php`
- Modify: `tests/suites/admin-ui.php`

- [ ] **Step 1: Write failing docs, UI, i18n, and Cluster tests**

Assert:

- local API docs show `targets` as an array and hide `targets_json`, storage paths, broker ports, passwords, cookies, and session proof;
- docs include profile start/status/reauth/delete, submit, task poll/result/artifact, latest run, and paged dataset;
- Test Center renders profile selection, target textarea, 10-30 control, Run button, task links, and dataset preview;
- every new Chinese UI string passes through `__()`;
- `hub_cluster_node_published_modes()` excludes `facebook_crawl` even when installed/running;
- local `hub_public_api_services()` still includes `facebook_crawl`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
```

Expected: FAIL on missing docs/UI and Cluster exclusion.

- [ ] **Step 3: Publish the special API contract**

In `hub_public_api_pack_job_async_contract()`, special-case only `facebook_crawl`. Replace runner fields with:

```php
[
    ['name' => 'profile_id', 'type' => 'string', 'required' => false],
    ['name' => 'targets', 'type' => 'array', 'required' => true, 'min_items' => 1, 'max_items' => 30,
        'items' => ['type' => 'object', 'required_keys' => ['url']]],
    ['name' => 'limit_per_target', 'type' => 'integer', 'required' => false, 'default' => 10, 'min' => 10, 'max' => 30],
]
```

Add workflow operations and stable errors from the design. Examples use JSON and never include real credentials or a real profile ID.

- [ ] **Step 4: Add the focused Test Center partial**

Register `facebook_crawl` in `hub_playground_profiles()` and delegate mode-specific actions/rendering to `_playground_facebook_crawler.php`. Keep credentials in password inputs and send them only when the user presses profile setup. Do not store them in `$_SESSION`, hidden inputs, HTML, logs, or redisplayed form values.

Render target URLs one per line and convert them server-side into the approved JSON array. The Test Center may show returned task/dataset links but must not auto-poll faster than every two seconds.

- [ ] **Step 5: Keep Phase A out of Cluster publication**

Filter exactly one mode at the final node catalog boundary:

```php
$modes = array_values(array_filter($modes, static fn (string $mode): bool => $mode !== 'facebook_crawl'));
```

Do not remove the local Pack route, Token permission, API docs, or Test Center entry. Add a `ponytail:` comment: node-pinned Router dispatch belongs to Phase B when a real caller needs it.

- [ ] **Step 6: Write the operator README**

Document:

- Linux/Windows WSL install and CPU requirements;
- local-node Profile rule;
- browser/password-assisted login and 2FA/CAPTCHA human completion;
- public page/group and already-joined private group behavior;
- manual API and `nchc_ai` external scheduling;
- 30-day artifact retention;
- no auto-join, CAPTCHA solving, deep history, proxy input, or Cluster routing in Phase A;
- exact smoke command from Task 8.

- [ ] **Step 7: Run tests and commit**

```bash
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
git add admin/playground.php admin/_playground_facebook_crawler.php app/public_api_docs.php app/cluster_router.php packs/facebook-crawler/README.md tests/test_facebook_crawler_ui.php tests/test_facebook_crawler_pack.php tests/suites/admin-ui.php
git commit -m "feat: document and test Facebook crawl workflow"
```

### Task 8: Portability, Security, And Real Acceptance

**Files:**

- Create: `scripts/facebook_crawler_smoke.php`
- Modify: `tests/test_facebook_crawler_pack.php`
- Modify: `tests/test_facebook_crawler_ui.php`
- Verify: all files from Tasks 1-7

- [ ] **Step 1: Add the opt-in real smoke script**

The script accepts secrets only from command options so they are not committed:

```text
php scripts/facebook_crawler_smoke.php \
  --api-base=https://3wa.tw/3waAIHub/api.php \
  --token-file=/path/outside/webroot/facebook-crawler.token \
  --profile-id=fbp_<opaque> \
  --target=https://www.facebook.com/<approved-official-page>
```

It must reject HTTP, read the Token from a `0600` regular single-link file, submit one target with limit 10, poll every two seconds with a 20-minute deadline, fetch `task_result`, fetch `facebook_dataset_items`, require at least one post, verify the JSONL SHA-256 through the artifact API, and print only task ID/count/timing. It never prints the Token, profile ID, cookies, dataset text, or request headers.

- [ ] **Step 2: Add security regression checks**

Search tracked crawler code and generated test DB/logs for forbidden persistence after an injected password setup:

```bash
rg -n "CRAWLER_PROXY|--use-proxy|google.*vision|captcha.*solve" packs/facebook-crawler app/facebook_crawler*.php
```

Expected: no exposed proxy setting or CAPTCHA solver. References explaining that CAPTCHA is human-only are allowed.

The test must also assert:

- browser-state files are outside every Web root;
- generated Docker names match `aihub-fb-login-[a-f0-9]{24}`;
- login broker ports are 1-65535 and loopback-only;
- raw credentials never reach task/runtime command arrays;
- profile locks release after all terminal paths;
- dataset lines over 1 MiB fail closed;
- root public PHP permissions are `0755`.

- [ ] **Step 3: Run focused suites**

```bash
python3 -m unittest discover -s packs/facebook-crawler/service/tests -v
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
```

Expected: all PASS. Do not run the full 500+ test suite unless a shared task/gateway change fails focused coverage or before the final push.

- [ ] **Step 4: Build/install smoke on Linux**

```bash
docker build -t 3waaihub/facebook-crawler:0.1.0 packs/facebook-crawler/service
php -r 'require "app/bootstrap.php"; $r=hub_install_pack(hub_db(), "facebook-crawler", ["idempotent"=>true]); echo json_encode(["pack_id"=>$r["pack"]["id"],"mode"=>$r["service"]["mode"]], JSON_UNESCAPED_SLASHES), PHP_EOL;'
```

Expected: `{"pack_id":"facebook-crawler","mode":"facebook_crawl"}` and Marketplace shows installable/installed, CPU, no model required.

- [ ] **Step 5: Run fixture and real public-target acceptance**

First run the fixture acceptance without a Facebook account. Then create/reauth one profile through the one-time login page and run `scripts/facebook_crawler_smoke.php` against an explicitly approved official page. Confirm:

```text
task status=success
outcome=complete or partial
dataset count >= 1
post count <= 30
login container absent after close/expiry
crawl container absent after task completion
no crawler process owns GPU VRAM
```

If Facebook returns a checkpoint or CAPTCHA, complete it manually; do not add automation to bypass it.

- [ ] **Step 6: Run Windows WSL smoke**

Install the same Pack on the Windows WSL node, complete one profile login, submit one approved public target, and verify task artifacts are copied back while `storage_state.json` remains only under the profile root. Record runtime support through the existing release/Pack status, not a new settings system.

- [ ] **Step 7: Final verification and commit**

```bash
git diff --check
php -l app/facebook_crawler.php
php -l app/facebook_crawler_login.php
php -l facebook_profile_login.php
php -l scripts/facebook_profile_cleanup.php
php -l scripts/facebook_crawler_smoke.php
php scripts/run_tests.php --suite=control-plane
php scripts/run_tests.php --suite=admin-ui
git status --short
git add scripts/facebook_crawler_smoke.php tests/test_facebook_crawler_pack.php tests/test_facebook_crawler_ui.php
git commit -m "test: verify Facebook crawler Pack L5"
```

Expected: only the user's pre-existing unrelated files remain modified. New PHP files under the Web root are `0755`; scripts and shell/Python entrypoints are executable where invoked directly.

## Deferred Phase B

Phase B is deliberately not implemented by this plan. `nchc_ai` remains the scheduler. Add recurring Hub schedules, hashtag discovery, or Cluster Router node-pinned Profile dispatch only after Phase A usage provides a concrete caller and failure data. Threads.net remains a separate future source contract.
