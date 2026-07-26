# Cluster Developer Portal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `cluster_public_api_docs.php` a polished, live, customer-facing Router API manual while preserving its current safe manifest contract.

**Architecture:** Keep `cluster_public_api_docs.php` as the gate and due-inventory refresh trigger. Rework only `hub_cluster_public_api_docs_html()` so it derives a display-only absolute Router URL from the existing public API base URL, presents the current safe manifest as a developer portal, and uses browser clipboard enhancement without changing the JSON manifest or exposing station metadata.

**Tech Stack:** PHP 8, server-rendered HTML/CSS, minimal browser Clipboard API JavaScript, existing SQLite-backed Cluster manifest, PHP test runner.

---

## File Structure

- Modify: `app/cluster_router.php` — derive the display-only Router URL and render the accessible responsive developer-portal document.
- Modify: `tests/test_cluster_router.php` — assert the public document's portal structure, safe absolute Router URL, empty state, and disclosure boundaries.

### Task 1: Add Portal Contract Regression Coverage

**Files:**
- Modify: `tests/test_cluster_router.php:1968-2056`

- [x] **Step 1: Write the failing portal document test**

Extend the existing fresh public-manifest fixture so it calls `hub_cluster_public_api_docs_html($db)` with an HTTPS request context and asserts that the rendered HTML contains the developer-portal contract:

```php
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'router.example';
$_SERVER['SCRIPT_NAME'] = '/3waAIHub/cluster_public_api_docs.php';

hub_test_assert(
    str_contains($docs, 'https://router.example/3waAIHub/cluster_api.php')
    && str_contains($docs, 'Live catalog')
    && str_contains($docs, 'Available modes')
    && str_contains($docs, 'href="#mode-ocr"')
    && str_contains($docs, 'id="mode-ocr"')
    && str_contains($docs, 'navigator.clipboard.writeText'),
    'Cluster public docs must render the live developer portal contract'
);
```

Preserve and restore the changed `$_SERVER` keys in `finally`, following `hub_test_with_cluster_pair_url()`.

Add assertions that the empty render still contains `No Router modes are currently available.` and that neither rendered document contains `configured.station.example`, `configured_station_secret`, or `remote_task_42`.

- [x] **Step 2: Run the full test suite and verify the new test fails**

Run:

```bash
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: FAIL mentioning the missing developer-portal contract in the Cluster public docs test.

- [x] **Step 3: Commit the failing-test checkpoint only if execution needs a review boundary**

Do not commit a known-failing tree during normal inline execution. Keep the test staged only after Task 2 turns it green.

### Task 2: Render the Live Developer Portal

**Files:**
- Modify: `app/cluster_router.php:848-900`
- Test: `tests/test_cluster_router.php:1968-2056`

- [x] **Step 1: Add the display-only absolute Router endpoint**

At the start of `hub_cluster_public_api_docs_html()`, derive the browser-facing endpoint from the existing public API URL without changing `hub_cluster_public_manifest()`:

```php
$manifest = hub_cluster_public_manifest($db);
$services = is_array($manifest['services'] ?? null) ? $manifest['services'] : [];
$apiUrl = hub_public_api_base_url();
$routerUrl = preg_replace('~api\.php\z~', 'cluster_api.php', $apiUrl) ?: 'cluster_api.php';
$example = static fn (string $value): string => str_replace('cluster_api.php', $routerUrl, $value);
$json = static fn (mixed $value): string => (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
```

Use `$routerUrl` only in human HTML and examples. Keep the JSON manifest's `base_endpoint` and service contract values unchanged and relative.

- [x] **Step 2: Replace the current document shell with the portal reading path**

Render these semantic sections in order:

```php
<header class="portal-hero">
    <p class="eyebrow">3waAIHub / Unified entry</p>
    <h1>Cluster API</h1>
    <p class="lede">One stable API entry for the Router's currently available services.</p>
    <div class="endpoint-row">
        <code><?= hub_h($routerUrl) ?></code>
        <button class="copy-button" type="button" data-copy="<?= hub_h($routerUrl) ?>" aria-label="Copy Router endpoint" title="Copy Router endpoint">Copy</button>
    </div>
    <p class="auth-line">Authorization: <code>Bearer &lt;TOKEN&gt;</code></p>
</header>
<section class="catalog-summary" aria-label="Live Router catalog">
    <div><span>Available modes</span><strong><?= count($services) ?></strong></div>
    <div><span>Catalog status</span><strong class="live">Live catalog</strong></div>
    <div><span>Updated</span><strong><?= hub_h((string)$manifest['generated_at']) ?></strong></div>
</section>
<nav class="mode-directory" aria-label="Available Router modes">
    <?php foreach ($services as $service): ?>
        <a href="#mode-<?= hub_h((string)$service['mode']) ?>"><code><?= hub_h((string)$service['mode']) ?></code></a>
    <?php endforeach; ?>
</nav>
```

For each service use an `<article class="service-card" id="mode-...">` containing the mode/name, a concise method/content-type row, request/response/error `pre` blocks, and copy-enabled curl/PHP/JS code blocks whose examples pass through `$example()`.

When `$services === []`, render the existing `No Router modes are currently available.` message in the portal's empty state after the summary. Do not display any station identity or diagnostics.

- [x] **Step 3: Apply responsive, restrained styling**

Replace the existing short style block with CSS that preserves the established light 3waAIHub colors and implements these required layout rules:

```css
:root { --bg: #f6f7f9; --panel: #fff; --ink: #1d2430; --muted: #667085; --line: #d9dee7; --blue: #1769e0; --green: #067647; --code: #101828; }
main { max-width: 1120px; margin: 0 auto; padding: 40px 20px 64px; }
.catalog-summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); border-block: 1px solid var(--line); gap: 16px; margin: 32px 0 20px; padding: 18px 0; }
.service-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
@media (max-width: 680px) { main { padding: 28px 16px 44px; } .catalog-summary { grid-template-columns: 1fr; } .service-grid { grid-template-columns: 1fr; } }
```

Use 8px-or-less radii, visible keyboard focus, `overflow-wrap:anywhere` for endpoint/code text, and no gradients, decorative imagery, charts, or animations.

- [x] **Step 4: Add progressive clipboard enhancement**

At the end of the document include one small script that copies a button's `data-copy` value and briefly changes only that button's text:

```html
<script>
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copy || '');
            const label = button.textContent;
            button.textContent = 'Copied';
            window.setTimeout(() => { button.textContent = label; }, 1200);
        } catch (_) {}
    });
});
</script>
```

The document remains fully usable without JavaScript: all endpoint and example text remains visible.

- [x] **Step 5: Run PHP syntax validation and the full suite**

Run:

```bash
php -l app/cluster_router.php
php -l tests/test_cluster_router.php
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: each linter prints `No syntax errors detected`; suite prints `failures=0`.

- [x] **Step 6: Commit the green implementation**

```bash
git add app/cluster_router.php tests/test_cluster_router.php
git commit -m "feat: present cluster API as developer portal"
```

### Task 3: Verify the Live Portal at Desktop and Mobile Widths

**Files:**
- Modify: none unless visual QA exposes a verified defect

- [x] **Step 1: Refresh the live catalog and inspect the public response**

Run:

```bash
curl -k -sS https://3wa.tw/3waAIHub/cluster_public_api_docs.php -o /tmp/cluster_public_api_docs.html
rg -n 'Cluster API|Live catalog|Available modes|cluster_api\.php|configured\.station|configured_station_secret' /tmp/cluster_public_api_docs.html
```

Expected: the first four terms appear; the configured station hostname and secret do not.

- [x] **Step 2: Inspect desktop and mobile renderings with Playwright**

Open `https://3wa.tw/3waAIHub/cluster_public_api_docs.php` at `1440x1000` and `390x844`. Verify that the hero endpoint, summary values, mode directory, service cards, and code blocks are visible; mobile has one column; no text overlaps, clips, or causes the body to scroll horizontally.

- [x] **Step 3: Re-run the full suite after any visual correction**

Run:

```bash
AIHUB_TEST_DB="$(mktemp /tmp/3waaihub_test_XXXXXX.sqlite)" AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: `failures=0`.
