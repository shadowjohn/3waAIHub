# Agent Manifest Contract Smoke Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the public Agent Manifest self-describing for its input-field extensions and provide a no-inference CLI smoke check that validates the live manifest, endpoint metadata, and generated examples.

**Architecture:** Keep `hub_public_api_manifest()` as the only producer of public contract metadata. Add one static root-level description for the existing `one_of`, `one_of_required`, and `example_include` input-field extensions. Add one standalone CLI that fetches the public manifest, validates its declared contract and examples without calling Pack APIs, and exits nonzero on drift.

**Tech Stack:** PHP 8.1, existing cURL extension, existing PHP test harness, Markdown.

---

## File Structure

- Modify: `app/public_api_docs.php`
  - Add the root `input_field_extensions` schema description beside the existing `auth`, `base_endpoint`, and `services` manifest fields.
- Create: `scripts/agent_manifest_smoke.php`
  - Fetch a public manifest URL and validate its JSON, service metadata, generated curl examples, one-of source rules, and example-selection rules without sending inference or job requests.
- Modify: `tests/test_public_api_docs.php`
  - Assert the root schema description and validate a real generated manifest through the CLI validator helpers.
- Modify: `tests/test_phase_dx4_client_starter.php`
  - Assert CLI help and the documented agent intake command stay present and secret-free.
- Modify: `docs/client_quickstart.md`
  - Document the manifest smoke command as the token-free first agent/client intake check.
- Modify: `README.md`
  - Add the same short operational command in the live API inventory section.

## Task 1: Declare the Extension Semantics

**Files:**
- Modify: `app/public_api_docs.php:799-812`
- Test: `tests/test_public_api_docs.php`

- [ ] **Step 1: Write the failing manifest-schema assertion**

Add a test after the existing `hub_public_api_manifest()` assertions:

```php
$extensions = $manifest['input_field_extensions'] ?? [];
hub_test_assert(($extensions['one_of']['type'] ?? '') === 'array<string>', 'manifest must describe one_of field groups');
hub_test_assert(($extensions['one_of_required']['type'] ?? '') === 'boolean', 'manifest must describe one_of_required');
hub_test_assert(($extensions['example_include']['type'] ?? '') === 'boolean', 'manifest must describe example_include');
```

- [ ] **Step 2: Run the focused test to verify RED**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the new assertion fails because `input_field_extensions` is absent.

- [ ] **Step 3: Add one static root schema description**

Add this key to the return value of `hub_public_api_manifest()`:

```php
'input_field_extensions' => [
    'one_of' => [
        'type' => 'array<string>',
        'description' => 'Names the mutually exclusive input fields in one group.',
    ],
    'one_of_required' => [
        'type' => 'boolean',
        'description' => 'When true, exactly one field named by one_of is required.',
    ],
    'example_include' => [
        'type' => 'boolean',
        'description' => 'When true, generated examples include this optional field.',
    ],
],
```

Do not add a general schema framework, version registry, or duplicate service contracts. These three entries describe fields already emitted by the canonical service list.

- [ ] **Step 4: Run the focused test to verify GREEN**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the new manifest assertions pass and no existing test regresses.

- [ ] **Step 5: Commit the manifest metadata**

```bash
git add app/public_api_docs.php tests/test_public_api_docs.php
git commit -m "feat: describe agent manifest input extensions"
```

## Task 2: Add a No-Inference Manifest Smoke CLI

**Files:**
- Create: `scripts/agent_manifest_smoke.php`
- Test: `tests/test_public_api_docs.php`
- Test: `tests/test_phase_dx4_client_starter.php`

- [ ] **Step 1: Write failing validator tests**

Add tests that construct a healthy manifest with `hub_public_api_manifest($db, static fn (array $service): bool => true)` and assert:

```php
require_once HUB_ROOT . '/scripts/agent_manifest_smoke.php';
hub_test_assert(hub_agent_manifest_smoke_validate($manifest) === [], 'generated public manifest must pass agent smoke validation');

$invalid = $manifest;
$invalid['services'][0]['endpoint'] = 'api.php?mode=wrong';
hub_test_assert(hub_agent_manifest_smoke_validate($invalid) !== [], 'endpoint/mode drift must fail agent smoke validation');
```

Use an audio async service fixture as well. Remove `example_include` from its upload field and assert the validator reports a one-of example violation. This proves the CLI interprets the new extension semantics rather than merely checking that the keys exist.

- [ ] **Step 2: Run the focused test to verify RED**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the test fails because `scripts/agent_manifest_smoke.php` and `hub_agent_manifest_smoke_validate()` do not exist.

- [ ] **Step 3: Implement the standalone validator and CLI**

Create `scripts/agent_manifest_smoke.php` with these exact responsibilities:

```php
function hub_agent_manifest_smoke_validate(array $manifest): array
{
    // Return human-readable errors; never invoke a Pack endpoint.
}

function hub_agent_manifest_smoke_fetch(string $url, int $timeout): array
{
    // cURL GET only, 5 MiB response cap, HTTP 200 JSON object required.
}
```

The validator must check:

```text
- root input_field_extensions declares array<string>/boolean/boolean for one_of, one_of_required, example_include;
- services is an array; every service has a nonempty mode, GET or POST method, an `api.php` endpoint whose `mode` query equals rawurlencode(mode) (additional documented default query values allowed), a URL containing the same mode, and a curl example with Authorization: Bearer <TOKEN>;
- POST curl examples contain -X POST; GET curl examples do not claim a conflicting -X method;
- every required input field and every example_include field appears in the curl example (file fields use name=@; JSON examples use "name"; multipart scalar fields use name=);
- all members of an one_of group exist, declare the same member list and one_of_required value, and a required group selects exactly one member in its default curl example;
- examples never include an optional field without example_include/default/example solely because it is present in the manifest;
```

The CLI contract is:

```text
Usage: php scripts/agent_manifest_smoke.php --manifest-url=https://host/3waAIHub/api_manifest.json.php [--timeout=5]
```

It must print one `PASS` line with the service count on success, print each validation error to STDERR on failure, use exit code `2` for missing/invalid arguments or unavailable cURL, and use exit code `1` for fetch/validation failure. Wrap main execution so test files can `require_once` the script without running the CLI.

- [ ] **Step 4: Run focused tests to verify GREEN**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
php scripts/agent_manifest_smoke.php --help
```

Expected: the test suite passes and help exits `0` with `--manifest-url` and `--timeout`.

- [ ] **Step 5: Commit the smoke CLI**

```bash
git add scripts/agent_manifest_smoke.php tests/test_public_api_docs.php tests/test_phase_dx4_client_starter.php
git commit -m "feat: add agent manifest smoke check"
```

## Task 3: Publish the Intake Command

**Files:**
- Modify: `docs/client_quickstart.md:5-12`
- Modify: `README.md:404-410`
- Test: `tests/test_phase_dx4_client_starter.php`

- [ ] **Step 1: Write the failing documentation assertions**

Extend the existing client starter test with:

```php
foreach (['scripts/agent_manifest_smoke.php', '--manifest-url=', 'input_field_extensions', 'one_of', 'example_include'] as $needle) {
    hub_test_assert(str_contains($quickstart, $needle), 'client quickstart missing agent manifest intake detail: ' . $needle);
}
```

Add a README assertion for `scripts/agent_manifest_smoke.php` and `input_field_extensions`.

- [ ] **Step 2: Run the focused test to verify RED**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the new documentation assertions fail.

- [ ] **Step 3: Add the smallest operational guidance**

In `docs/client_quickstart.md`, insert after the manifest step:

```markdown
Before an agent generates requests, validate the live manifest without a token or Pack inference:

```bash
php scripts/agent_manifest_smoke.php \\
  --manifest-url=https://host/3waAIHub/api_manifest.json.php
```

`input_field_extensions` defines `one_of`, `one_of_required`, and `example_include`; agents must select exactly one member of a required `one_of` group and may use `example_include` to choose the safe default example path.
```

In `README.md`, add one sentence in the live API inventory paragraph that names the same command and root field. Do not add a second API reference or document every Pack again.

- [ ] **Step 4: Run documentation and full-suite checks**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
git diff --check
```

Expected: all tests pass and the diff has no whitespace errors.

- [ ] **Step 5: Commit the published guidance**

```bash
git add README.md docs/client_quickstart.md tests/test_phase_dx4_client_starter.php
git commit -m "docs: publish agent manifest intake smoke"
```

## Final Verification

- [ ] Run `php -l app/public_api_docs.php scripts/agent_manifest_smoke.php`.
- [ ] Run `AIHUB_TEST_QUIET=1 php scripts/run_tests.php`.
- [ ] Run `php scripts/agent_manifest_smoke.php --manifest-url=https://3wa.tw/3waAIHub/api_manifest.json.php`.
- [ ] Confirm the command exits `0`, the manifest contains only currently live services, no Pack API endpoint was invoked, and `git status --short --branch` is clean.

## Plan Review

- Spec coverage: Task 1 formalizes all three existing custom manifest fields; Task 2 consumes the live manifest and validates endpoint, method, and default-example consistency without inference; Task 3 documents the operator/agent entry point.
- Placeholder scan: no TBD/TODO or unspecified test behavior remains.
- Type consistency: `input_field_extensions`, `one_of`, `one_of_required`, `example_include`, and `hub_agent_manifest_smoke_validate()` use one spelling throughout.
