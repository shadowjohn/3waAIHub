# Cluster Router Control Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Router role that gives customers one authenticated 3waAIHub API endpoint, selects a healthy eligible station, pins async work to that station, and records account/Token usage without exposing station credentials.

**Architecture:** Keep every station's existing `api.php` untouched for direct use. Add a small Router control-plane module that owns encrypted station configuration, cached inventory, route selection, proxying, async route mappings, and Router-specific usage rows. New public Router endpoints authenticate the customer at the Router, replace that token with the selected station token, and return only Router URLs. A protected station endpoint reports only non-inference routing facts.

**Tech Stack:** PHP 8.1, SQLite, existing cURL extension, OpenSSL AES-256-GCM, existing API Token model, existing PHP test harness, Markdown.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `app/api_tokens.php` | Extract strict token identity authentication so Router follow-ups can authenticate a customer Token before checking ownership. |
| `app/db.php` | Create Router station, route, route-access, and route-artifact SQLite tables and indexes. |
| `app/storage.php` | Add the two Router safety limits: live proxy transfers and buffered response bytes. |
| `app/bootstrap.php` | Load the Router module after API Token and Gateway helpers. |
| `app/cluster_router.php` | Station-secret encryption, registry CRUD, inventory refresh, pure selection, sync/async proxy helpers, route ledger, and Router contract rendering. |
| `cluster_status.php` | Station-local, Token-protected, non-inference routing snapshot. |
| `cluster_pair.php` | Child-node one-time pairing exchange; returns the already configured child Token once after a valid invitation arrives. |
| `cluster_api.php` | Customer-facing unified API entry and pinned async follow-up entry. |
| `cluster_manifest.json.php` | Machine-readable Router-only contract for currently routable modes. |
| `cluster_public_api_docs.php` | Human Router API page generated from the same Router manifest. |
| `scripts/cluster_refresh.php` | Optional CLI refresh for an external scheduler; request traffic and the admin refresh action remain sufficient without it. |
| `admin/cluster.php` | System-admin station cards, station detail/configuration, manual refresh, route pressure, and account/Token usage filters. |
| `admin/_layout.php` | Add the system-admin Cluster navigation link. |
| `admin/api_members.php`, `admin/api_tokens.php` | Link existing account/Token screens to the Router usage filter when routes exist. |
| `docs/cluster-router.md` | Separate customer entry guide and operator setup/runbook. |
| `docs/client_quickstart.md`, `README.md` | Point clients and agents to the unified entry documentation and live Router manifest. |
| `tests/test_api_tokens.php` | Cover strict identity-only Token authentication without changing existing Gateway behavior. |
| `tests/test_cluster_router.php` | Cover schema, secrets, inventory, priority-overflow selection, proxy credential boundaries, async rewrites, usage aggregation, and public Router contracts. |
| `tests/test_phase_dx4_client_starter.php` | Keep the Router guide, manifest, and refresh command documented and secret-free. |

## Public and Stored Contracts

The following names are used consistently in every task:

```text
Router customer endpoint:  cluster_api.php?mode=<published-mode>
Router task modes:         cluster_task_status, cluster_task_result,
                           cluster_task_log, cluster_task_cancel, cluster_artifact
Station status endpoint:   cluster_status.php
Child pairing endpoint:    cluster_pair.php#invite=<one-time-secret>
Router manifest endpoint:  cluster_manifest.json.php
Router docs endpoint:      cluster_public_api_docs.php
Router task id:            cr_<32 lowercase hex chars>
Station Token privilege:   cluster_status plus every forwarded station mode
Station freshness:         both manifest_fetched_at and status_fetched_at <= 30 seconds old
Refresh interval:          do not refetch an unchanged station inside 10 seconds
Live proxy transfer cap:   AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS=8
Buffered response cap:     AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB=64
```

New SQLite tables have these minimal columns:

```text
cluster_stations(
  id, station_key UNIQUE, display_name, public_base_url, internal_base_url,
  priority, enabled, token_ciphertext, token_iv, token_tag,
  manifest_json, manifest_fetched_at, status_json, status_fetched_at,
  last_error, created_at, updated_at
)
cluster_routes(
  route_id PRIMARY KEY, station_id, member_id, token_id, mode,
  remote_task_id, is_async, state, remote_status, expires_at,
  created_at, updated_at, completed_at
)
cluster_route_accesses(
  id, route_id, station_id, member_id, token_id, mode, access_kind,
  request_id, status_code, ok, error_code, elapsed_ms,
  upload_bytes, response_bytes, created_at
)
cluster_route_artifacts(
  id, route_id, remote_artifact_id, created_at,
  UNIQUE(route_id, remote_artifact_id)
)
```

`cluster_route_accesses.access_kind = 'submit'` is the only row counted as a work request. `task_status`, `task_result`, `task_log`, `task_cancel`, and `artifact` rows count access and bytes only. `cluster_routes.state` is `active` only while async work is queued/running; sync routes become `succeeded` or `failed` before their response is returned.

Cluster roles are stored as existing settings, not a new user role or second account model:

```text
AIHUB_CLUSTER_NODE_ENABLED=0|1
AIHUB_CLUSTER_ROUTER_ENABLED=0|1
AIHUB_CLUSTER_PAIR_INVITE_HASH=<sha256 only while invitation is active>
AIHUB_CLUSTER_PAIR_INVITE_EXPIRES_AT=<Taipei timestamp>
AIHUB_CLUSTER_NODE_TOKEN_ID=<child-created dedicated Token id>
AIHUB_CLUSTER_NODE_ROUTER_NAME=<paired unified-entry display name>
AIHUB_CLUSTER_NODE_TOKEN_CIPHERTEXT=<AES-256-GCM ciphertext>
AIHUB_CLUSTER_NODE_TOKEN_IV=<AES-256-GCM IV>
AIHUB_CLUSTER_NODE_TOKEN_TAG=<AES-256-GCM tag>
AIHUB_CLUSTER_NODE_MODE_JSON=<selected published modes>
```

Only a system administrator changes either role in `admin/cluster.php`. A child invitation link has the form `https://child/3waAIHub/cluster_pair.php#invite=<random>`. The fragment is never sent by ordinary navigation. The unified-entry form parses the pasted fragment and sends the random invitation only in `X-3waAIHub-Pair-Invite` while calling the child endpoint.

The child Token is generated when child-node role is enabled, stored with the same AES-256-GCM helpers, and displayed in full only inside its authenticated system-admin page. Its effective permissions are `cluster_status` plus the checked child-mode list. Regeneration revokes its old API Token, creates a fresh one, refreshes the encrypted saved value, clears pairing state, and requires the unified entry to pair again.

### Task 1: Extract Strict API Token Authentication

**Files:**
- Modify: `app/api_tokens.php:326-383`
- Test: `tests/test_api_tokens.php`

- [ ] **Step 1: Write failing strict-identity tests**

Append a test that creates a `hello`-permitted Token and proves identity authentication can be strict without creating a service request:

```php
$identity = hub_authenticate_api_token($db, '203.0.113.10', $token['plain_token']);
hub_test_assert(($identity['ok'] ?? false) === true, 'strict identity authentication must accept a valid Token');
hub_test_assert((int)$identity['context']['member_id'] === $memberId, 'strict identity authentication must return member ownership');

hub_set_storage_setting($db, 'AIHUB_REQUIRE_API_TOKEN', '0');
hub_set_storage_setting($db, 'AIHUB_LOCALHOST_BYPASS_TOKEN', '1');
$missing = hub_authenticate_api_token($db, '127.0.0.1', '');
hub_test_assert(($missing['response']['status'] ?? 0) === 401, 'Router authentication must not accept localhost without a Token');
```

Use a Token with no service permissions and also assert its `token_id`, `last_used_at`, and `last_used_ip` after the successful strict call. Keep the existing Gateway tests unchanged so the current localhost bypass and mode-denial behavior remain covered.

- [ ] **Step 2: Run the focused suite to verify RED**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_auth.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_0123456789abcdef0123456789abcdef AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: FAIL because `hub_authenticate_api_token()` does not exist.

- [ ] **Step 3: Refactor the current Token gate into one reusable helper**

Move the body of `hub_gateway_authenticate_api_token()` into this helper, retaining the existing hash, member enabled, revoked, validity, IP, `last_used_at`, and optional mode permission checks:

```php
function hub_authenticate_api_token(
    PDO $db,
    string $clientIp,
    string $providedToken,
    ?string $requiredMode = null
): array
```

The strict helper must not read the request header or apply any bypass. Its empty-Token branch is always:

```php
if ($providedToken === '') {
    return ['ok' => false, 'response' => hub_gateway_error(401, 'missing_token', 'API token is required'), 'context' => []];
}
```

When `$requiredMode` is non-null, call `hub_api_token_mode_allowed()` and preserve the current `token_mode_not_allowed` response. Keep header extraction and the two original bypasses in `hub_gateway_authenticate_api_token()` before it delegates a nonempty Token. Its final delegation is:

```php
return hub_authenticate_api_token($db, $clientIp, $plainToken, $mode);
```

- [ ] **Step 4: Run the focused suite to verify GREEN**

Run the command from Step 2. Expected: the new strict-identity assertions and all existing API Token tests pass.

- [ ] **Step 5: Commit the authentication refactor**

```bash
git add app/api_tokens.php tests/test_api_tokens.php
git commit -m "refactor: expose strict API token authentication"
```

### Task 2: Add Router Persistence, Secret Handling, and Pure Selection

**Files:**
- Modify: `app/db.php:605-645, 774-790`
- Modify: `app/bootstrap.php:49-55`
- Create: `app/cluster_router.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing Router core tests**

Create `tests/test_cluster_router.php` and first assert the schema and selection contract with fixtures only:

```php
$db = hub_test_reset_db();
hub_test_assert(hub_table_exists($db, 'cluster_stations'), 'cluster station table missing');
hub_test_assert(hub_table_exists($db, 'cluster_routes'), 'cluster route table missing');

$selected = hub_cluster_select_station('ocr', [
    ['id' => 1, 'priority' => 100, 'enabled' => 1, 'fresh' => true, 'modes' => ['ocr'], 'gpu_free_vram_mb' => 8000, 'active_gpu_leases' => 0, 'queued_jobs' => 0],
    ['id' => 2, 'priority' => 10, 'enabled' => 1, 'fresh' => true, 'modes' => ['ocr'], 'gpu_free_vram_mb' => 6000, 'active_gpu_leases' => 0, 'queued_jobs' => 0],
]);
hub_test_assert((int)$selected['id'] === 1, 'healthy highest-priority station must win normally');

$overflow = hub_cluster_select_station('ocr', [
    ['id' => 1, 'priority' => 100, 'enabled' => 1, 'fresh' => true, 'modes' => ['ocr'], 'gpu_free_vram_mb' => 0, 'active_gpu_leases' => 1, 'queued_jobs' => 3],
    ['id' => 2, 'priority' => 10, 'enabled' => 1, 'fresh' => true, 'modes' => ['ocr'], 'gpu_free_vram_mb' => 6000, 'active_gpu_leases' => 0, 'queued_jobs' => 0],
]);
hub_test_assert((int)$overflow['id'] === 2, 'busy preferred station must overflow to a healthy lower-priority station');
```

Set `AIHUB_CLUSTER_SECRET_KEY` to 64 hex characters, import a synthetic successful pairing response containing Token `3wa_live_station_secret`, and assert `token_ciphertext` does not contain the plain token while `hub_cluster_station_token()` decrypts it only in process.

- [ ] **Step 2: Run the focused suite to verify RED**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_core.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_11111111111111111111111111111111 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: FAIL because the tables and Router functions are absent.

- [ ] **Step 3: Create the tables and indexes in the existing migration style**

Add the four `CREATE TABLE IF NOT EXISTS` definitions from the contract above to `hub_migrate()` immediately after `runtime_resource_leases`. Add these indexes beside the existing API indexes:

```sql
CREATE INDEX IF NOT EXISTS idx_cluster_stations_enabled ON cluster_stations(enabled, priority DESC);
CREATE INDEX IF NOT EXISTS idx_cluster_routes_station_state ON cluster_routes(station_id, state, updated_at DESC);
CREATE INDEX IF NOT EXISTS idx_cluster_routes_member_token ON cluster_routes(member_id, token_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_route ON cluster_route_accesses(route_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cluster_route_accesses_usage ON cluster_route_accesses(member_id, token_id, access_kind, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_cluster_route_artifacts_route ON cluster_route_artifacts(route_id);
```

Use `ON DELETE CASCADE` for rows that belong to a station or route, and `ON DELETE SET NULL` for member/Token audit references. Do not add a generic migration framework or a second database.

- [ ] **Step 4: Implement the focused Router core module**

Create `app/cluster_router.php`, load it after `gateway.php` in `app/bootstrap.php`, and implement these stable boundaries:

```php
function hub_cluster_secret_key(): string;
function hub_cluster_encrypt_station_token(string $plainToken): array;
function hub_cluster_decrypt_station_token(array $station): string;
function hub_cluster_validate_station_base_url(string $value): string;
function hub_cluster_station_request_base_url(array $station): string;
function hub_cluster_create_pair_invitation(PDO $db): array;
function hub_cluster_import_pairing_link(PDO $db, string $pairingLink, ?callable $requester = null): array;
function hub_cluster_save_paired_station(PDO $db, array $pairing): int;
function hub_cluster_get_station(PDO $db, int $stationId): ?array;
function hub_cluster_list_stations(PDO $db): array;
function hub_cluster_station_token(array $station): string;
function hub_cluster_select_station(string $mode, array $stations): ?array;
```

The pairing import response contains only `station_key`, `display_name`, `public_base_url`, the child-selected `modes`, and `station_token`. After URL validation, encrypt the Token and save the normal parent-side station record. There is no manual Token or base-URL entry form.

Require `AIHUB_CLUSTER_SECRET_KEY` to be exactly 64 hexadecimal characters on every Hub that enables either Cluster role. Derive the 32-byte key with `hex2bin()`, generate a 12-byte IV, and store `openssl_encrypt(..., 'aes-256-gcm', ..., OPENSSL_RAW_DATA, $iv, $tag)` as base64 ciphertext with separate base64 IV/tag fields. Refuse role activation and pairing import when the environment key is invalid. Parent station-list/detail helpers never return decrypted Token fields; the child Token is decrypted only for the authenticated system-admin child configuration view and for a valid one-time pairing response.

`hub_cluster_create_pair_invitation()` must require `AIHUB_CLUSTER_NODE_ENABLED=1`, generate `bin2hex(random_bytes(32))`, save only its SHA-256 hash and a 15-minute expiry in settings, and return a link based on the current `cluster_pair.php` URL with `#invite=<random>`. Reissuing an invitation replaces the hash and expiry. `hub_cluster_import_pairing_link()` must accept only an `http`/`https` `cluster_pair.php#invite=<64 hex>` link, remove the fragment from its request URL, and send the invitation in `X-3waAIHub-Pair-Invite`; it must not write the invitation into a database, error message, or access log.

Normalize a station base URL by requiring `http` or `https`, no user/password, no fragment, a nonempty host, and a trailing `/`. `internal_base_url` is used for Router-to-station calls when set; public URLs remain the only values rendered for operators.

Implement selection in two passes:

```php
$eligible = array_values(array_filter($stations, static fn (array $station): bool =>
    !empty($station['enabled'])
    && !empty($station['fresh'])
    && in_array($mode, $station['modes'] ?? [], true)
));
$unpressured = array_values(array_filter($eligible, static fn (array $station): bool =>
    (int)($station['gpu_free_vram_mb'] ?? 0) > 0
    && (int)($station['active_gpu_leases'] ?? 0) === 0
    && (int)($station['queued_jobs'] ?? 0) === 0
));
$candidates = $unpressured !== [] ? $unpressured : $eligible;
```

Sort `$candidates` by descending `priority`, descending `gpu_free_vram_mb`, ascending `active_gpu_leases`, ascending `queued_jobs`, then ascending numeric `id`. This gives 3wa/strong stations stable preference and uses a healthy lower priority station only after the preferred station is pressured or ineligible.

- [ ] **Step 5: Run the focused suite to verify GREEN**

Run the command from Step 2. Expected: schema, at-rest secret, base URL, priority, overflow, disabled, stale, and absent-mode assertions pass.

- [ ] **Step 6: Commit the Router core**

```bash
git add app/db.php app/bootstrap.php app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: add cluster router registry and selection"
```

### Task 3: Publish Protected Station Status and Cached Inventory

**Files:**
- Create: `cluster_status.php`
- Create: `cluster_pair.php`
- Modify: `app/cluster_router.php`
- Create: `scripts/cluster_refresh.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing status and refresh tests**

Add tests for a status payload and a fake station fetcher:

```php
$payload = hub_cluster_status_payload($db, static fn (): array => [
    'available' => true,
    'memory_total_mb' => 16384,
    'memory_free_mb' => 12000,
]);
hub_test_assert(($payload['ok'] ?? false) === true, 'cluster status must be successful');
hub_test_assert((int)$payload['gpu']['memory_free_mb'] === 12000, 'cluster status must publish free VRAM');
hub_test_assert(array_key_exists('queued_jobs', $payload), 'cluster status must publish queue pressure');

$refreshed = hub_cluster_refresh_station($db, $station, static function (string $url, array $headers): array {
    return str_ends_with($url, 'api_manifest.json.php')
        ? ['status' => 200, 'body' => json_encode(['services' => [['mode' => 'ocr', 'endpoint' => 'https://station/3waAIHub/api.php?mode=ocr']]])]
        : ['status' => 200, 'body' => json_encode(['ok' => true, 'snapshot_at' => hub_now(), 'gpu' => ['memory_free_mb' => 12000], 'active_gpu_leases' => 0, 'queued_jobs' => 0, 'running_jobs' => 0, 'modes' => ['ocr']])];
});
hub_test_assert(($refreshed['fresh'] ?? false) === true, 'fresh manifest and status must make station routable');
```

Add a pairing test that enables the child setting with checked mode `ocr`, creates an invitation, extracts the `#invite=` value, and calls `hub_cluster_accept_pair_invitation()` with that value and client IP `192.168.1.10`. Assert the response contains the encrypted child Token in plaintext only for this one pair response, the invitation is unusable a second time, the returned Token has `cluster_status` and `ocr` permissions but not an unchecked published mode, and its IP whitelist contains `192.168.1.10`. Add a token-regeneration assertion: the old child Token is revoked, pairing state is cleared, and a fresh child Token is the only value shown to the authenticated child admin helper.

Assert the status request's `Authorization` header contains the decrypted station Token and no customer Token, a malformed manifest records `last_error`, and a snapshot older than 30 seconds is excluded by `hub_cluster_select_station()`.

- [ ] **Step 2: Run the focused suite to verify RED**

Run the command from Task 2, Step 2. Expected: FAIL because the status and refresh functions do not exist.

- [ ] **Step 3: Implement the child pairing endpoint and station-local status endpoint**

Add these child-node helpers in `app/cluster_router.php`:

```php
function hub_cluster_node_enabled(PDO $db): bool;
function hub_cluster_router_enabled(PDO $db): bool;
function hub_cluster_accept_pair_invitation(PDO $db, string $invite, string $clientIp, string $routerName): array;
function hub_cluster_node_sync_token_permissions(PDO $db, int $tokenId): void;
```

Add `hub_cluster_node_configure(PDO $db, bool $enabled, array $selectedModes): array`, `hub_cluster_node_reveal_token(PDO $db): string`, and `hub_cluster_node_regenerate_token(PDO $db): array`. Child activation creates a dedicated API member/Token, encrypts its plaintext in child settings, and writes exactly `cluster_status` plus the checked currently published modes to its existing Token permissions. `hub_cluster_accept_pair_invitation()` compares only SHA-256 hashes with `hash_equals()`, rejects expired/consumed invitations, reads that encrypted child Token, adds an enabled IP whitelist for the request's actual source IP, saves the Router name in child settings, clears the invitation settings before returning, and returns the Token only in its JSON response. Re-pairing from a replacement unified entry is performed only after `hub_cluster_node_regenerate_token()` revokes the old Token, clears pairing state, creates/encrypts a fresh Token, and creates a new invitation. `hub_cluster_node_sync_token_permissions()` keeps the child Token permission rows equal to `cluster_status` plus the admin-selected modes; it must not silently grant every newly installed mode.

Create executable `cluster_pair.php`. It must return 404 unless child-node role is enabled, accept only `POST`, read `X-3waAIHub-Pair-Invite` and a validated `X-3waAIHub-Router-Name`, call `hub_cluster_accept_pair_invitation()`, and return the child `station_key`, site display name, current public base URL, selected modes, and one-time station Token. Never echo the invitation, previous Token, SQLite exception, or arbitrary request headers.

Create executable `cluster_status.php` with this bootstrap and strict access control:

Create executable `cluster_status.php` with this bootstrap and strict access control:

```php
require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    hub_send_gateway_response(hub_gateway_error(405, 'method_not_allowed', 'cluster status requires GET'));
    exit;
}
$auth = hub_authenticate_api_token($db, hub_get_client_ip(), hub_bearer_token_from_request(), 'cluster_status');
if (empty($auth['ok'])) {
    hub_send_gateway_response($auth['response']);
    exit;
}
hub_send_gateway_response(hub_gateway_json(200, hub_cluster_status_payload($db)));
```

Before authenticating, `cluster_status.php` returns 404 unless child-node role is enabled. After successful authentication, require the Token id to equal `AIHUB_CLUSTER_NODE_TOKEN_ID`, then run `hub_cluster_node_sync_token_permissions()`. `hub_cluster_status_payload()` must call the lightweight `hub_collect_gpu_metric()`, count nonexpired `runtime_resource_leases` where `state = 'leased'`, count queued/running tasks from the existing `tasks` table, and return only the checked child modes that remain installed/enabled/running. Return exactly these non-secret fields: `ok`, `snapshot_at`, `gpu`, `active_gpu_leases`, `queued_jobs`, `running_jobs`, and `modes`. It must not call any Pack API, inference endpoint, model installer, container restart, or artifact path.

- [ ] **Step 4: Implement bounded inventory refresh and CLI entry**

Add these helpers in `app/cluster_router.php`:

```php
function hub_cluster_refresh_station(PDO $db, array $station, ?callable $fetcher = null): array;
function hub_cluster_refresh_due_stations(PDO $db, bool $force = false, ?callable $fetcher = null): array;
function hub_cluster_station_is_fresh(array $station, ?int $now = null): bool;
```

The refresh helper must use the internal base URL when present, otherwise the public base URL, then GET only these two resources in order:

```text
<base>/api_manifest.json.php
<base>/cluster_status.php
```

The second request carries `Authorization: Bearer <decrypted station Token>`. Require each response to be HTTP 200 JSON, require a `services` array from the manifest and a current `snapshot_at` plus `modes` array from status, save compact JSON snapshots, and record a sanitized error code rather than a Token or raw body. Skip a refresh when both saved fetch times are less than 10 seconds old unless `$force` is true. Freshness is 30 seconds for both snapshots.

Create executable `scripts/cluster_refresh.php` that accepts only `--force` and runs `hub_cluster_refresh_due_stations($db, $force)`, printing one line per station with `station_key`, `fresh`, and `last_error`. It is safe for a systemd timer or cron wrapper and never sends inference requests.

- [ ] **Step 5: Run tests and lint to verify GREEN**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_status.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_22222222222222222222222222222222 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php -l app/cluster_router.php cluster_pair.php cluster_status.php scripts/cluster_refresh.php
```

Expected: all tests pass and each lint command reports no syntax errors.

- [ ] **Step 6: Commit the station inventory slice**

```bash
git add app/cluster_router.php cluster_pair.php cluster_status.php scripts/cluster_refresh.php tests/test_cluster_router.php
git commit -m "feat: add cluster child pairing and inventory"
```

### Task 4: Route and Proxy Synchronous Requests

**Files:**
- Create: `cluster_api.php`
- Modify: `app/cluster_router.php`
- Modify: `app/storage.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing synchronous proxy tests**

Add a fake requester that captures all Router-to-station headers and returns both JSON and binary fixtures:

```php
$response = hub_cluster_dispatch($db, 'ocr', static function (array $request) use (&$captured): array {
    $captured = $request;
    return ['status' => 200, 'headers' => ['Content-Type: image/png'], 'body' => "PNG\x00fixture"];
}, [
    'client_ip' => '203.0.113.10',
    'method' => 'POST',
    'bearer_token' => $customer['plain_token'],
    'raw_body' => '{"real_inference":false}',
    'content_type' => 'application/json',
    'request_uri' => '/3waAIHub/cluster_api.php?mode=ocr',
]);
hub_test_assert(($response['status'] ?? 0) === 200, 'Router must return the selected station response');
hub_test_assert(in_array('Authorization: Bearer ' . $stationToken, $captured['headers'], true), 'Router must send the station Token');
hub_test_assert(!str_contains(implode("\n", $captured['headers']), $customer['plain_token']), 'customer Token must never leave Router');
hub_test_assert(($response['body'] ?? '') === "PNG\x00fixture", 'binary response must remain unchanged');
```

Add assertions that a disabled/stale/missing-mode station produces `503 station_unavailable` before requester invocation, customer mode denial produces `403 token_mode_not_allowed` before selection, and one successful `submit` row has the member, Token, station, input bytes, response bytes, and `access_kind = 'submit'`.

Set `AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS=1`, create one fresh `proxying` route, and assert a second dispatch returns `429 router_busy` before requester invocation. Return a fake `Content-Length: 67108865` response while `AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB=64` and assert `502 router_response_too_large` without keeping the response body. Add a self-station fixture and assert it uses a local `hub_gateway_dispatch()` callback rather than an HTTP URL.

- [ ] **Step 2: Run the focused suite to verify RED**

Run the Task 3 focused command. Expected: FAIL because `hub_cluster_dispatch()` and `cluster_api.php` are absent.

- [ ] **Step 3: Implement Router request validation and the proxy boundary**

Implement these helpers in `app/cluster_router.php`:

```php
function hub_cluster_dispatch(PDO $db, string $mode, ?callable $requester = null, array $request = []): array;
function hub_cluster_proxy_request(array $station, string $mode, array $request, string $stationToken, ?callable $requester = null): array;
function hub_cluster_create_route(PDO $db, array $station, array $auth, string $mode, bool $isAsync): array;
function hub_cluster_record_access(PDO $db, array $route, string $accessKind, array $response, int $elapsedMs, int $uploadBytes): void;
function hub_cluster_finish_route(PDO $db, string $routeId, string $state, ?string $remoteStatus = null): void;
```

Add these default settings in `app/storage.php`:

```php
'AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS' => '8',
'AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB' => '64',
```

While creating a route, use `BEGIN IMMEDIATE` and count only rows in `state = 'proxying'` whose `updated_at` is younger than the selected service timeout. Reject a new transport with `hub_gateway_error(429, 'router_busy', 'unified entry is handling too many live transfers')` once it reaches `AIHUB_CLUSTER_ROUTER_MAX_PROXY_TRANSFERS`; immediately mark terminal responses out of `proxying`. `cluster_routes.state = 'active'` async jobs are not live proxy transfers. Keep this intentionally small with one comment in the count helper:

```php
// ponytail: SQLite route count is sufficient for one Router; use a shared limiter only after multi-Router traffic exists.
```

For an initial request, call `hub_authenticate_api_token($db, $clientIp, $customerToken, $mode)`, force a due inventory refresh, select a station, then verify the selected cached service contract permits the exact request method. Reject nested `$_FILES` arrays with `400 unsupported_multipart_shape`; use the existing flat `hub_proxy_post_fields($_POST, $_FILES)` behavior for normal multipart uploads.

Build the station URL only as:

```php
$url = rtrim(hub_cluster_station_request_base_url($station), '/')
    . '/api.php?mode=' . rawurlencode($mode);
```

Forward only `Authorization: Bearer <station Token>`, `Accept`, and a validated request content type. Do not forward the inbound `Authorization`, `Host`, `Content-Length`, `Cookie`, or arbitrary request headers. Reuse `hub_proxy_allowed_response_headers()` for response headers and preserve the response body unchanged for JSON and binary sync responses. Map cURL connect, timeout, and transport failures to the existing `service_unavailable`, `gateway_timeout`, and `proxy_error` vocabulary with Router-safe messages.

For a selected self station, do not make an HTTP loopback request. Call the existing `hub_gateway_dispatch()` with the station Token in its internal request context, preserving the current superglobal upload handling and returning its standard response. For remote requests, PHP upload temp files are passed through the existing `CURLFile` path and are not read into PHP memory. Before `CURLOPT_RETURNTRANSFER` accepts a remote body, reject a valid `Content-Length` greater than `AIHUB_CLUSTER_ROUTER_MAX_PROXY_RESPONSE_MB * 1024 * 1024`; also cap the cURL write total at that value when a header is absent. Return `502 router_response_too_large` rather than allowing one process to exhaust memory. This V1 ceiling deliberately covers normal JSON/images and bounded artifacts; introduce cURL-to-client artifact streaming only when actual artifacts exceed it.

Before dispatch create a `cluster_routes` row. For sync responses terminalize it as `succeeded` for HTTP 2xx/3xx or `failed` otherwise, write one `cluster_route_accesses` row, and append `X-3waAIHub-Cluster-Route: <route_id>` without changing the client response body. Do not retry a dispatched request.

- [ ] **Step 4: Add the public entry script**

Create executable `cluster_api.php`:

```php
require __DIR__ . '/app/bootstrap.php';

$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
if (!hub_cluster_router_enabled($db)) {
    hub_send_gateway_response(hub_gateway_error(404, 'router_disabled', 'unified entry is not enabled'));
    exit;
}
$mode = trim((string)($_GET['mode'] ?? ''));
hub_send_gateway_response(hub_cluster_dispatch($db, $mode));
```

Reserve the five `cluster_task_*`/`cluster_artifact` modes for Task 5. All other mode strings must match `^[A-Za-z0-9_-]+$`; a reserved mode never reaches station selection as a normal Pack mode.

- [ ] **Step 5: Run tests and lint to verify GREEN**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_sync.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_33333333333333333333333333333333 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php -l app/cluster_router.php cluster_api.php
```

Expected: customer Token isolation, response preservation, pre-dispatch rejection, route row, and byte ledger tests pass.

- [ ] **Step 6: Commit the synchronous Router**

```bash
git add app/cluster_router.php app/storage.php cluster_api.php tests/test_cluster_router.php
git commit -m "feat: proxy synchronous cluster requests"
```

### Task 5: Pin Async Tasks, Follow-ups, and Artifacts to One Station

**Files:**
- Modify: `app/cluster_router.php`
- Modify: `cluster_api.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing async rewrite and ownership tests**

Use a fake remote submit response and assert the public response never contains the remote station URL or remote task id:

```php
$remote = ['ok' => true, 'task_id' => 77, 'status' => 'queued'] + hub_task_response_links(77);
$rewritten = hub_cluster_rewrite_async_response($db, $route, $remote, 'https://router/3waAIHub/cluster_api.php');
hub_test_assert(($rewritten['task_id'] ?? '') === $route['route_id'], 'Router task id must be opaque route id');
hub_test_assert(str_contains((string)$rewritten['status_url'], 'mode=cluster_task_status'), 'status link must stay at Router');
hub_test_assert(!str_contains(json_encode($rewritten), 'task_id=77'), 'remote task id must not leak to caller');
```

Add tests that a follow-up made with a different customer Token receives `404 route_not_found`, the original Token is forwarded only as the station Token, a remote status of `success` changes `cluster_routes.state` to `succeeded`, and an artifact id discovered in a result can be downloaded through `cluster_artifact` while an unknown artifact id is rejected before remote dispatch.

- [ ] **Step 2: Run the focused suite to verify RED**

Run the Task 4 focused command. Expected: FAIL because the async functions and reserved follow-up modes do not exist.

- [ ] **Step 3: Persist remote task and artifact mappings, then rewrite every client link**

Add these helpers:

```php
function hub_cluster_rewrite_async_response(PDO $db, array $route, array $payload, string $routerBase): array;
function hub_cluster_get_route_for_customer(PDO $db, string $routeId, array $auth): ?array;
function hub_cluster_dispatch_followup(PDO $db, string $routerMode, array $request = [], ?callable $requester = null): array;
function hub_cluster_sync_route_artifacts(PDO $db, array $route, array $payload): void;
function hub_cluster_router_task_links(string $routeId, string $routerBase): array;
```

When an initial remote JSON response has a scalar `task_id`, save it in `cluster_routes.remote_task_id`, set `is_async = 1` and `state = 'active'`, replace the public `task_id` with `route_id`, and replace all returned `status_url`, `result_url`, `log_url`, `cancel_url`, and `artifact_url_template` values with:

```text
cluster_api.php?mode=cluster_task_status&task_id=<route_id>
cluster_api.php?mode=cluster_task_result&task_id=<route_id>
cluster_api.php?mode=cluster_task_log&task_id=<route_id>
cluster_api.php?mode=cluster_task_cancel&task_id=<route_id>
cluster_api.php?mode=cluster_artifact&task_id=<route_id>&artifact_id={artifact_id}
```

For `cluster_task_status`, `cluster_task_result`, `cluster_task_log`, and `cluster_task_cancel`, first strict-authenticate the customer with no mode permission check, load the route only when both `member_id` and `token_id` match, then proxy the matching native remote task operation using the stored remote task id and station Token. Save one access row for every follow-up. Treat terminal remote states `success`, `failed`, and `cancelled` as `succeeded`, `failed`, and `cancelled` locally. A station transport failure returns `503 station_unavailable`; it never resubmits to another station.

When a result payload contains `artifact_id` fields, insert every scalar id into `cluster_route_artifacts` with `INSERT OR IGNORE`. The artifact endpoint accepts only an id already mapped to this route, then proxies `api.php?mode=artifact&artifact_id=<remote id>` with the station Token. This makes task results establish the allowed artifact set and prevents a caller from using a Router route id to read an unrelated remote artifact.

- [ ] **Step 4: Route reserved modes in the public entry script**

Replace the single dispatch line in `cluster_api.php` with this explicit branch:

```php
$followups = ['cluster_task_status', 'cluster_task_result', 'cluster_task_log', 'cluster_task_cancel', 'cluster_artifact'];
$response = in_array($mode, $followups, true)
    ? hub_cluster_dispatch_followup($db, $mode)
    : hub_cluster_dispatch($db, $mode);
hub_send_gateway_response($response);
```

Do not add these Router-private mode names to customer Token permissions. Follow-up authorization is the exact original member and Token ownership lookup.

- [ ] **Step 5: Run tests and lint to verify GREEN**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_async.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_44444444444444444444444444444444 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php -l app/cluster_router.php cluster_api.php
```

Expected: rewritten links, Token-bound ownership, terminal-state recording, known artifact routing, and no-retry behavior pass.

- [ ] **Step 6: Commit async pinning**

```bash
git add app/cluster_router.php cluster_api.php tests/test_cluster_router.php
git commit -m "feat: pin cluster async routes"
```

### Task 6: Build the Cluster Admin Console and Account Usage View

**Files:**
- Create: `admin/cluster.php`
- Modify: `admin/_layout.php:68-81`
- Modify: `admin/api_members.php`
- Modify: `admin/api_tokens.php`
- Modify: `app/cluster_router.php`
- Test: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing admin and usage tests**

Add source-level UI assertions and data-level usage assertions:

```php
$summary = hub_cluster_usage_summary($db, ['member_id' => $memberId, 'token_id' => $tokenId]);
hub_test_assert((int)$summary['work_requests'] === 1, 'only submit access must count as work request');
hub_test_assert((int)$summary['accesses'] === 4, 'submit plus follow-ups must count as accesses');
hub_test_assert((int)$summary['peak_concurrency'] === 1, 'peak concurrency must count overlapping active routes');

$admin = (string)file_get_contents(HUB_ROOT . '/admin/cluster.php');
foreach (['子入口節點', '統一入口', '子節點 Token', '可供應服務', '配對連結', '新增子節點', '站點', '服務模式', 'Router station Token', '近期路由', '用量統計', 'name="action" value="save_roles"', 'name="action" value="pair_child"', 'name="action" value="regenerate_node_token"'] as $needle) {
    hub_test_assert(str_contains($admin, $needle), 'cluster console missing ' . $needle);
}
```

Also assert the rendered source never echoes `token_ciphertext`, `token_iv`, `token_tag`, or `hub_cluster_station_token(`.

- [ ] **Step 2: Run the focused suite to verify RED**

Run the Task 5 focused command. Expected: FAIL because the console and usage summary are absent.

- [ ] **Step 3: Add small query helpers for cards, detail, and usage**

Implement only these presentation queries in `app/cluster_router.php`:

```php
function hub_cluster_station_dashboard_rows(PDO $db): array;
function hub_cluster_recent_routes(PDO $db, array $filters = [], int $limit = 100): array;
function hub_cluster_usage_summary(PDO $db, array $filters = []): array;
function hub_cluster_usage_rows(PDO $db, array $filters = []): array;
```

`hub_cluster_usage_summary()` must calculate `work_requests`, `success_count`, `failed_count`, `active_routes`, `peak_concurrency`, `upload_bytes`, and `response_bytes` from Router tables. Count work only where `access_kind = 'submit'`. Calculate peak concurrency by ordering active-route start/end events per selected account/Token and taking the maximum running sum; do not use HTTP connections or station GPU activity as a substitute.

Return station service modes from cached manifests and readiness/freshness from cached status. Return only `token_configured` boolean and a mask generated from the encrypted value metadata such as `configured`; never decrypt a Token for display.

- [ ] **Step 4: Implement the admin console with existing layout conventions**

Create executable `admin/cluster.php`, require `app/bootstrap.php` and `admin/_layout.php`, call `hub_require_system_admin($db)`, and protect every POST with `hub_check_csrf()`.

Implement these POST actions exactly:

```text
save_roles       lets only the current system administrator enable/disable child-node and unified-entry roles; enabling child-node creates an encrypted dedicated child Token and a short-lived invitation, while disabling it clears the invitation and revokes the child Token
save_child_modes writes exactly the checked installed/running modes to the child Token alongside mandatory cluster_status permission
regenerate_node_token revokes the old child Token, creates/encrypts a replacement, clears pairing state, and creates a fresh invitation
renew_invitation creates a fresh child-node invitation without changing unified-entry role
pair_child       accepts one pasted cluster_pair.php#invite link and calls hub_cluster_import_pairing_link(); no direct Token/base URL fields exist
toggle_station   changes enabled state for an existing station
refresh_station  force-refreshes one station inventory
```

Use the existing `.hub-card-grid`, `.hub-card`, `.hub-meta`, `.hub-badge-*`, tables, and normal `<form>` controls. The first view contains a roles panel. It has the two labels `子入口節點` and `統一入口`; only the child-enabled state displays a full copyable readonly `子節點 Token` input, checkbox rows for `可供應服務`, a copyable readonly pairing-link input, token-regeneration control, and renewal button. Only the unified-entry state displays the pasted child-link form and station cards. The cards show display name, enabled state, freshness, free/total VRAM, active GPU leases, queued/running work, published mode count, current active Router routes, and links to `?station_id=<id>`. The detail view contains public/internal base URLs returned by pairing, priority, masked Router-side Token configured state, last refresh/error, per-mode readiness, and recent routes. Full Token display is restricted to this authenticated system-admin child configuration panel and is never present in public Router endpoints.

Add a second `?view=usage` view with member, Token, station, and mode filters; show work requests, success/failure, active routes, peak concurrency, upload/response bytes, plus grouped account/Token/station rows. Add a `Cluster` link to the admin navigation. In `admin/api_members.php` and `admin/api_tokens.php`, add only a `Cluster 用量` link that prefilters `cluster.php?view=usage` for that member or Token; do not create a second member model or billing controls.

- [ ] **Step 5: Run tests and lint to verify GREEN**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_admin.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_55555555555555555555555555555555 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php -l app/cluster_router.php admin/cluster.php admin/_layout.php admin/api_members.php admin/api_tokens.php
```

Expected: usage semantics, station-secret masking, cards/detail source contracts, and all existing admin tests pass.

- [ ] **Step 6: Commit the admin console**

```bash
git add app/cluster_router.php admin/cluster.php admin/_layout.php admin/api_members.php admin/api_tokens.php tests/test_cluster_router.php
git commit -m "feat: add cluster router admin console"
```

### Task 7: Publish Router-Only Manifest, Human Guide, and Operations Runbook

**Files:**
- Create: `cluster_manifest.json.php`
- Create: `cluster_public_api_docs.php`
- Modify: `app/cluster_router.php`
- Create: `docs/cluster-router.md`
- Modify: `docs/client_quickstart.md`
- Modify: `README.md`
- Modify: `tests/test_phase_dx4_client_starter.php`
- Modify: `tests/test_cluster_router.php`

- [ ] **Step 1: Write failing public-contract and documentation tests**

Add tests using cached station fixtures where `ocr` is fresh on 5090 and `tts` is stale everywhere:

```php
$manifest = hub_cluster_public_manifest($db);
hub_test_assert(in_array('ocr', array_column($manifest['services'], 'mode'), true), 'fresh routed mode must appear in Router manifest');
hub_test_assert(!in_array('tts', array_column($manifest['services'], 'mode'), true), 'stale-only mode must stay out of Router manifest');
hub_test_assert(str_contains((string)$manifest['services'][0]['endpoint'], 'cluster_api.php?mode='), 'Router manifest must point to unified endpoint');
```

In `tests/test_phase_dx4_client_starter.php`, assert `docs/cluster-router.md`, `cluster_manifest.json.php`, `cluster_public_api_docs.php`, `cluster_api.php`, `cluster_pair.php`, `cluster_status.php`, `AIHUB_CLUSTER_SECRET_KEY`, `子入口節點`, `統一入口`, and `cluster_status` occur in documentation. Assert the guide contains neither `3wa_live_`, an invitation fragment, nor the configured station secret fixture.

- [ ] **Step 2: Run the focused suite to verify RED**

Run the Task 6 focused command. Expected: FAIL because the Router manifest, public page, and guide do not exist.

- [ ] **Step 3: Build one Router contract source and its two public renderers**

Implement these functions in `app/cluster_router.php`:

```php
function hub_cluster_public_manifest(PDO $db): array;
function hub_cluster_public_api_docs_html(PDO $db): string;
function hub_cluster_rewrite_contract_endpoint(array $service, string $stationApiBase, string $routerApiBase): array;
```

For each fresh, enabled station manifest, choose one canonical contract per mode using the same Router selector. Rewrite its `endpoint`, async links, and example URL strings from the selected station's `api.php` base to the current `cluster_api.php` base. Preserve method, content type, fields, output, and examples from the selected station contract. Return only `services` whose selected station is still fresh; include `base_endpoint`, `auth`, `generated_at`, and a plain `inventory_note` that refreshes can temporarily remove unavailable modes. Do not emit station URL, station key, priority, cached status, secrets, local paths, or internal ports.

Create executable endpoints with the existing public-doc allow switch. Both return `404 router_disabled` until a system administrator enables unified-entry role:

```php
// cluster_manifest.json.php
if (!hub_public_api_allowed($db, 'AIHUB_PUBLIC_API_MANIFEST')) {
    hub_send_gateway_response(hub_gateway_error(403, 'public_docs_forbidden', 'Public API manifest is disabled or local-only.'));
    exit;
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(hub_cluster_public_manifest($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

`cluster_public_api_docs.php` must enforce `AIHUB_PUBLIC_API_DOCS`, call the same manifest function, and render a compact mode-by-mode contract with method, content type, fields, Router endpoint, error codes, and curl/PHP/JS examples. It contains no admin navigation and no station identity.

- [ ] **Step 4: Write the separated customer and operator guide**

Create `docs/cluster-router.md` with exactly two sections:

```text
Customer Unified Entry
  - base endpoint cluster_api.php, Router-issued customer Tokens, mode discovery via cluster_manifest.json.php
  - token-free manifest smoke command, sync request example, async task-id/link behavior, station-unavailable behavior
  - no station URL/token leakage and no automatic post-dispatch retry

Operator Setup and Recovery
  - set AIHUB_CLUSTER_SECRET_KEY in every child-node and unified-entry web/CLI environment using openssl rand -hex 32
  - enable 子入口節點 on each execution station, copy its displayed child Token only through the authenticated admin panel, check the services it is allowed to supply, then copy its short-lived pairing link and paste it under the unified-entry's 新增子節點 form
  - explain that cluster_pair.php transfers the existing child Token once, limits it to the unified-entry source IP, and that regenerating the child Token requires a fresh pairing; do not paste Tokens or invitations into tickets, chat, or public logs
  - enable 統一入口 on the designated Router, configure priority/enable state after pairing, perform refresh, verify cards before customer traffic
  - optional scripts/cluster_refresh.php scheduler command, stale/disabled behavior, and how to disable a station safely
  - account/Token route and byte reporting; explicitly state departments, quotas, LLM Tokens, GPU minutes, and kWh are not V1 data
```

Use these runnable examples without a real secret:

```bash
export AIHUB_CLUSTER_SECRET_KEY="$(openssl rand -hex 32)"
php scripts/agent_manifest_smoke.php \
  --manifest-url=https://router.example/3waAIHub/cluster_manifest.json.php
php scripts/cluster_refresh.php --force
```

Update `docs/client_quickstart.md` with a `Unified Router Entry` section that changes only the entry/manifest URLs and explains that customer Tokens are issued by the Router. Update the README API inventory section with links to the new guide and live endpoints; keep direct station `api.php` documentation intact.

- [ ] **Step 5: Run tests, lint, and static secret scan to verify GREEN**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_docs.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_66666666666666666666666666666666 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php -l app/cluster_router.php cluster_manifest.json.php cluster_public_api_docs.php
rg -n "3wa_live_[a-f0-9]{20,}|#invite=[a-f0-9]{32,}|token_ciphertext|token_iv|token_tag" docs/cluster-router.md docs/client_quickstart.md README.md cluster_manifest.json.php cluster_public_api_docs.php
```

Expected: tests and lint pass; the scan produces no customer or station Token values, and any implementation-only field references are absent from both public endpoints.

- [ ] **Step 6: Commit Router documents and contracts**

```bash
git add app/cluster_router.php cluster_manifest.json.php cluster_public_api_docs.php docs/cluster-router.md docs/client_quickstart.md README.md tests/test_cluster_router.php tests/test_phase_dx4_client_starter.php
git commit -m "docs: publish cluster router entry guide"
```

### Task 8: Verify End-to-End Contracts and File Permissions

**Files:**
- Modify only if a verification failure identifies a focused defect in files from Tasks 1-7.

- [ ] **Step 1: Run all PHP syntax checks for new public and CLI scripts**

Run:

```bash
php -l app/cluster_router.php
php -l cluster_api.php
php -l cluster_pair.php
php -l cluster_status.php
php -l cluster_manifest.json.php
php -l cluster_public_api_docs.php
php -l scripts/cluster_refresh.php
php -l admin/cluster.php
```

Expected: every command reports `No syntax errors detected`.

- [ ] **Step 2: Run the full isolated test suite in a PTY and capture its summary**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_router_full.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_77777777777777777777777777777777 AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: `suite=full` ends with `failures=0`. Use the PTY exit code rather than treating a truncated non-PTY response as a successful test run.

- [ ] **Step 3: Verify only intended new PHP entry points are executable**

Run:

```bash
chmod 755 cluster_api.php cluster_pair.php cluster_status.php cluster_manifest.json.php cluster_public_api_docs.php scripts/cluster_refresh.php admin/cluster.php
stat -c '%a %n' cluster_api.php cluster_pair.php cluster_status.php cluster_manifest.json.php cluster_public_api_docs.php scripts/cluster_refresh.php admin/cluster.php
```

Expected: each new PHP entry point prints mode `755`. Do not change permissions on existing files merely because Git reports unrelated mode changes.

- [ ] **Step 4: Verify the final diff and create the integration commit**

Run:

```bash
git diff --check origin/main...HEAD
git status --short
git log --oneline origin/main..HEAD
```

Expected: no whitespace errors; the only uncommitted changes are any focused verification repair made in this task. Commit such a repair with its test in the same commit, then run the full suite again.

- [ ] **Step 5: Push only after the user approves the reviewed implementation**

Run:

```bash
git push origin main
```

Expected: `main` advances with the focused Router commits and the design/plan documentation commits; do not force-push.

## Plan Self-Review

### Spec coverage

| Design requirement | Plan task |
| --- | --- |
| Logical Router role, first deployment on 3wa, direct station API unchanged | Tasks 2, 4, 7 |
| Customer Token authority and private station Token boundary | Tasks 1, 2, 3, 4 |
| Encrypted/masked station Token configuration | Tasks 2 and 6 |
| Manifest plus protected status, 10-second refresh and 30-second stale cutoff | Task 3 |
| Priority-first selection with pressure overflow, no post-dispatch retry | Tasks 2 and 4 |
| Self-station direct Gateway path and bounded transfer/memory protection | Task 4 |
| JSON, multipart, and binary sync response forwarding | Task 4 |
| Async URL rewriting, route pinning, status/result/log/cancel/artifact follow-ups | Task 5 |
| Account/Token request, active/peak concurrency, byte ledger; no department/billing/LLM Token model | Task 6 |
| Host cards, detail/configuration, services, masked Token, recent routes | Task 6 |
| Unified customer manual, live human docs, and Router-only manifest | Task 7 |
| No inference while collecting inventory/status | Task 3 and Task 7 tests |
| PHP executable permissions and complete verification | Task 8 |

### Placeholder scan

The plan has no `TBD`, `TODO`, unspecified validation, or unnamed implementation/test steps. Each new function and table used by a later task is defined in an earlier task.

### Type consistency

`route_id` is consistently an opaque `cr_<hex>` string; station `id`, member `id`, and Token `id` are integers; remote task and artifact ids are persisted as strings; `hub_cluster_dispatch()` and follow-up dispatchers return the existing Gateway response array with `status`, `headers`, and `body`.
