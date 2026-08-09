# Fortify Runtime Settings and SQLite Safety Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove `.env` from 3waAIHub runtime deployment, provide an idempotent safe migration for deployed services, and add SQLite-native safe query helpers while removing dynamic schema SQL replay.

**Architecture:** Service runtime settings become `runtime-settings.conf`, an explicit Compose `--env-file` input and an `env_file` entry rather than Docker Compose's implicit `.env` discovery. A CLI migrator validates each registered service against the Hub runtime root, atomically writes the new file, verifies its SHA-256, then retires only the matching regular legacy `.env`; it never follows symlinks or deletes files outside the service directory. WSL emits the same settings through a same-directory temporary file, checks `sha256sum`, applies mode `0600`, and renames it before legacy retirement. SQLite helpers use fixed SQL statements and PDO placeholders for their supported contracts; table-rebuild migrations validate a fixed compatibility index catalog through bound `pragma_*` table functions, never by executing SQL read from `sqlite_master` or rebuilding arbitrary DB-supplied DDL.

**Tech Stack:** PHP 8, PDO SQLite, Docker Compose v2, PowerShell/Linux CLI, existing `scripts/run_tests.php` control-plane suite.

**Implementation status (2026-08-09):** Tasks 1–5 completed on `codex/fortify-c1-runtime-settings`. Verification: PHP lint, `self_check`, idempotent migration `--apply`/`--check`, Windows installer contract, and `suite=control-plane tests=413 failures=0 skipped=24`. The remediated Fortify FPR uses the expanded 255-file scope and has SHA-256 `229f90579deff06879907eb68fbea6c7caae91a4cb131598e482e6fb3ff19854`.

---

### Task 1: SQLite Helper Contract and Dynamic Schema Replay Removal

**Files:**
- Modify: `app/db.php:8-80,1200-1405`
- Modify: `tests/test_sqlite_safety.php`

- [ ] **Step 1: Write failing SQLite helper tests**

Add tests for all four helper operations: a bound `SELECT`, a generated `INSERT`, a generated equality-only `UPDATE`, and rejection of `users; DROP TABLE users`. Add a source-contract assertion that table-rebuild migrations do not execute SQL selected from `sqlite_master`; their standard Hub indexes are recreated by the fixed `CREATE INDEX IF NOT EXISTS` pass already present later in `hub_migrate()`.

```php
hub_test('SQLite safe helpers bind values and reject dynamic identifiers', function (): void {
    $db = hub_test_reset_db();
    hub_sqlite_insert_safe($db, 'settings', ['key' => 'safe_key', 'value' => 'x', 'updated_at' => hub_now()]);
    $row = hub_sqlite_select_safe($db, 'SELECT value FROM settings WHERE key = :key', [':key' => 'safe_key'])[0] ?? [];
    hub_test_assert(($row['value'] ?? null) === 'x', 'safe select must bind named parameters');
    hub_test_assert(hub_test_throws(static fn () => hub_sqlite_insert_safe($db, 'settings; DROP TABLE users', ['key' => 'x'])), 'unsafe table identifier was accepted');
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the new test fails because `hub_sqlite_insert_safe()` and related helpers do not yet exist.

- [ ] **Step 3: Implement SQLite-native safe helpers**

Add these functions near the top of `app/db.php`:

```php
function hub_sqlite_identifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $identifier) !== 1) {
        throw new InvalidArgumentException('Invalid SQLite identifier.');
    }
    return '"' . $identifier . '"';
}

function hub_sqlite_exec_safe(PDO $db, string $sql, array $parameters = []): PDOStatement
{
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
```

Implement `hub_sqlite_select_safe()`, `hub_sqlite_insert_safe()`, and `hub_sqlite_update_safe()` on top of these primitives. `INSERT` and `UPDATE` must quote each validated identifier, generate internal `:value_N` placeholders, reject empty input, and support only equality conditions supplied as an associative array. They must not accept a free-form `WHERE` fragment.

Remove each `SELECT sql FROM sqlite_master` plus `$db->exec($indexSql)` loop from the three rebuild migrations. The static `CREATE INDEX IF NOT EXISTS` statements already run after these rebuilds in `hub_migrate()`, so normal Hub indexes are recreated without replaying database-owned SQL. An unexpected user-defined index is not silently replayed as executable SQL.

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: `suite=control-plane ... failures=0`; the old dynamic `$db->exec($indexSql)` pattern is absent from `app/db.php`.

- [ ] **Step 5: Commit**

```powershell
git add app/db.php tests/test_sqlite_safety.php
git commit -m "fix: harden SQLite migration queries"
```

### Task 2: Explicit Runtime Settings File and Safe Atomic Write

**Files:**
- Modify: `app/bootstrap.php:9-30`
- Modify: `app/service_settings.php:344-405`
- Modify: `app/pack_registry.php:1760-1865,2240-2360`
- Modify: `app/docker_runner.php:206-245,584-710,959-1045`
- Modify: `admin/service_settings.php`
- Modify: `tests/test_service_settings.php`
- Modify: `tests/test_pack_registry.php`
- Modify: `tests/test_gateway.php`

- [ ] **Step 1: Write failing runtime settings tests**

Change all current service-settings assertions from `/.env` to `/runtime-settings.conf`. Add coverage that generated Compose contains `env_file: - runtime-settings.conf`, `hub_compose_command()` contains `--env-file` followed by the exact runtime settings path, and writing settings retires a regular legacy `.env` only after the new file is available. Add a symlink fixture asserting a legacy `.env` symlink is rejected and its target is untouched.

```php
hub_test('service settings use an explicit runtime file and retire only a regular legacy env', function (): void {
    $db = hub_test_reset_db();
    $service = hub_install_pack($db, 'hello', ['service_key' => 'settings-migrate-main'])['service'];
    $runtimeDir = dirname(hub_path((string)$service['compose_file']));
    file_put_contents($runtimeDir . '/.env', "LEGACY_ONLY=1\n");
    $settingsPath = hub_write_service_runtime_settings($db, $service);
    hub_test_assert(basename($settingsPath) === 'runtime-settings.conf', 'runtime settings file name mismatch');
    hub_test_assert(!file_exists($runtimeDir . '/.env'), 'legacy env must be retired after a successful write');
});
```

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the test fails because `runtime-settings.conf` and `hub_write_service_runtime_settings()` do not yet exist.

- [ ] **Step 3: Implement explicit settings generation**

Define `HUB_RUNTIME_SETTINGS_FILENAME = 'runtime-settings.conf'` and `HUB_LEGACY_RUNTIME_ENV_FILENAME = '.env'` in `app/bootstrap.php`. Replace `hub_write_service_env()` with `hub_write_service_runtime_settings()` and add a runtime-directory validator that resolves the directory, rejects a symlink, and returns only a path below the resolved service root.

Write settings through a same-directory temporary file created with `tempnam()`, `file_put_contents(..., LOCK_EX)`, `chmod(..., 0600)` on non-Windows hosts, `hash_file('sha256', ...)` verification, then `rename()`. Only after the new hash verifies, retire a legacy `.env` if it is a regular non-symlink file directly inside the same resolved runtime directory. A legacy symlink, a wrong file type, or an unlink failure must throw without following or deleting its target.

Generate `env_file: - runtime-settings.conf` in all Hub-generated Compose text. Build the native Compose argv as:

```php
['docker', 'compose', '--env-file', $settingsPath, '-p', $service['compose_project'], '-f', hub_path($service['compose_file'])]
```

Require the settings file before launch; it must not fall back to a legacy `.env`. Update WSL command scripts to create `runtime-settings.conf`, use `docker compose --env-file "$service_root/runtime-settings.conf"`, and remove only their fixed service-root `.env` after the new file is written. Update runtime cleanup validation to include the new file and optionally retire the fixed legacy file after path validation.

- [ ] **Step 4: Run the focused test and verify it passes**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: `suite=control-plane ... failures=0`; generated and WSL Compose sources contain no `env_file: - .env` or writes to `$service_root/.env`.

- [ ] **Step 5: Commit**

```powershell
git add app/bootstrap.php app/service_settings.php app/pack_registry.php app/docker_runner.php admin/service_settings.php tests/test_service_settings.php tests/test_pack_registry.php tests/test_gateway.php
git commit -m "feat: replace implicit runtime env files"
```

### Task 3: Idempotent Deployed-Service Migration

**Files:**
- Create: `scripts/migrate_runtime_settings.php`
- Modify: `tests/test_service_settings.php`
- Modify: `README.md`

- [ ] **Step 1: Write failing migration tests**

Add tests that create two installed services: one regular runtime with a legacy `.env`, and one runtime whose legacy file is a symlink. Invoke the migration's extracted callable with `apply=false` then `apply=true`. Assert check mode changes nothing, apply mode reports `migrated=1 rejected=1`, writes `runtime-settings.conf`, retires only the regular file, and leaves the symlink target unchanged. Repeat apply mode and assert `migrated=0 already_current=1`.

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: the test fails because the migration callable and CLI do not exist.

- [ ] **Step 3: Implement the migration script**

Create a CLI-only script accepting exactly `--check`, `--apply`, `--service-key=<key>`, and `--json`. Default to `--check`; reject unknown arguments and `--check` with `--apply` using exit code 2. Query installed pack services through `hub_sqlite_select_safe()`, validate every service key and expected `compose_file`, lock each runtime directory, then call the safe writer from Task 2. Do not start, stop, build, or claim jobs.

Use stable machine output:

```text
mode=apply scanned=6 migrated=5 already_current=1 rejected=0
```

For `--json`, emit the same counts plus a per-service outcome/reason array. A successful readiness/migration report exits 0; one or more rejected services exits 1; invalid CLI arguments or bootstrap failure exits 2. Document both Linux and Windows commands and require `--check` before `--apply`.

- [ ] **Step 4: Run migration tests and a dry-run smoke**

Run:

```powershell
php scripts/run_tests.php --suite=control-plane
php scripts/migrate_runtime_settings.php --check
```

Expected: all control-plane tests pass; the current host reports every runtime service without modifying it.

- [ ] **Step 5: Commit**

```powershell
git add scripts/migrate_runtime_settings.php tests/test_service_settings.php README.md
git commit -m "feat: add runtime settings migration"
```

### Task 4: Templates, Server Protection, Documentation, and Host Migration

**Files:**
- Rename: `.env.example` to `runtime-settings.example.conf`
- Modify: `.gitignore`
- Modify: `.htaccess`
- Modify: `web.config`
- Modify: `app/environment_probe.php`
- Modify: `scripts/self_check.php`
- Modify: `admin/service_settings.php`
- Modify: `README.md`
- Modify: `docs/operations/voxcpm2-three-mode-smoke.md`
- Modify: `history.md`
- Modify: `packs/yolo-serving/docker-compose.yml`
- Modify: `packs/yolo/docker-compose.yml`
- Modify: `packs/ocr-ppocrv5/docker-compose.yml`
- Modify: `packs/rag-nemotron/docker-compose.yml`
- Modify: `packs/sam3/docker-compose.yml`
- Modify: `packs/taiwan-address/docker-compose.yml`
- Modify: `packs/translate-gemma12b/docker-compose.yml`
- Modify: `packs/llm-gemma4-12b/docker-compose.yml`
- Modify: `packs/whisper-asr/docker-compose.yml`
- Modify: `tests/test_phase_auth1a2_login_lockout.php`
- Modify: `tests/test_service_settings.php`

- [ ] **Step 1: Write failing protection and template tests**

Assert the root template is `runtime-settings.example.conf`, no source Pack Compose file names `.env` as its `env_file`, IIS hides `runtime-settings.conf`, Apache blocks `.conf`, and `self_check.php` expects the new runtime path. Preserve assertions that IIS/Apache/Nginx deny legacy `.env` because older deployment residues must remain unreadable.

- [ ] **Step 2: Run the focused test and verify it fails**

Run: `php scripts/run_tests.php --suite=control-plane`

Expected: failure until all templates, server deny lists, docs, and self-check references use the new contract.

- [ ] **Step 3: Implement protection and migration documentation**

Rename the tracked root template and replace `.gitignore`'s `.env` ignore rules with `runtime-settings.conf`, `runtime-settings.*.conf`, and an exception for `runtime-settings.example.conf`. This makes accidental root `.env` files visible to Git rather than silently hidden.

Add `runtime-settings.conf` and `runtime-settings.example.conf` to IIS hidden segments, deny all `.conf` files under Apache, and update the Environment probe's equivalent Nginx/Apache/IIS expectations. Keep legacy `.env` deny patterns for upgrade safety; they are a defensive compatibility rule, not an active configuration mechanism.

Replace every Pack Compose `env_file: - .env` with `env_file: - runtime-settings.conf`, update all operator documentation and UI text, and explain the mandatory two-step upgrade:

```powershell
php scripts/migrate_runtime_settings.php --check
php scripts/migrate_runtime_settings.php --apply
```

```bash
php scripts/migrate_runtime_settings.php --check
php scripts/migrate_runtime_settings.php --apply
```

- [ ] **Step 4: Run verification and migrate the local host**

Run:

```powershell
php -l app/db.php
php -l app/service_settings.php
php -l app/pack_registry.php
php -l app/docker_runner.php
php -l scripts/migrate_runtime_settings.php
php scripts/run_tests.php --suite=control-plane
php scripts/self_check.php
php scripts/migrate_runtime_settings.php --check
php scripts/migrate_runtime_settings.php --apply
php scripts/migrate_runtime_settings.php --check
git diff --check
```

Expected: lint succeeds; control-plane suite has zero failures; self-check succeeds; first local apply retires only valid legacy files; second check reports no migratable `.env`; no active runtime or Pack template uses `.env`.

- [ ] **Step 5: Commit**

```powershell
git add .gitignore .htaccess web.config app/environment_probe.php scripts/self_check.php admin/service_settings.php README.md docs/operations/voxcpm2-three-mode-smoke.md history.md packs tests runtime-settings.example.conf
git rm .env.example
git commit -m "docs: retire legacy runtime env files"
```

### Task 5: Fortify Evidence Refresh

**Files:**
- No source change required

- [ ] **Step 1: Run the approved production scan scope**

Use the same production scope as the current FPR: exclude `.github`, `tests`, and `docs`; retain `app`, `admin`, `scripts`, Pack runtime code, server configuration, and installers. Do not exclude `packs/*/acceptance/` or `packs/*/service/tests/` by default unless they are proved non-deployed.

- [ ] **Step 2: Compare against the correct baseline**

Compare the new FPR with `C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_fortify_20260809_175600.fpr`. Report active FVDL count and BIRT count separately; do not compare either to an Audit Workbench aggregate without matching the same filter and audit state.

- [ ] **Step 3: Inspect the target categories**

Verify that the three `app/db.php` migration replay sinks and `.env` deployment references no longer appear in the scanned source. Record any remaining SQL Injection findings by instance ID and explain whether they are application code, test utility, or require an independent batch; do not mark unreviewed findings false-positive just to lower the count.
