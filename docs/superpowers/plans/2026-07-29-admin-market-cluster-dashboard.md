# Admin Market and Cluster Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the 8/7 administration experience with Guo-Yang's supplied visual system, one canonical Market and Record Center, role-aware Cluster dashboards, configurable branding, and read-only release consistency reporting.

**Architecture:** Preserve the existing PHP/SQLite control plane and move shared presentation queries out of legacy pages into focused application helpers. `admin/_layout.php` remains the single shell; canonical pages compose existing Pack, Service, Runtime, log, and Cluster contracts while legacy URLs stay hidden but directly reachable for diagnosis. The supplied `system_web` assets are adapted into local, versioned assets and treated as a fidelity contract rather than a loose style reference.

**Tech Stack:** PHP 8.3, SQLite/PDO, existing `__()` i18n and command queue, HTML/CSS/vanilla JavaScript plus existing local jQuery, local Chart.js, Bash cron, repository PHP test harness, Browser/IAB and Playwright fallback for visual verification.

---

## Visual Source Of Truth

- Accepted source archive: `/home/john/.codex/attachments/1a69a2d3-f336-45a5-b4d4-07ba5694c92c/system_web.zip`
- Read-only extraction used during planning: `/tmp/3waaihub-system-web-20260729/system_web`
- Primary references:
  - `/tmp/3waaihub-system-web-20260729/system_web/dashboard.html`
  - `/tmp/3waaihub-system-web-20260729/system_web/packs.html`
  - `/tmp/3waaihub-system-web-20260729/system_web/index.html`
  - `/tmp/3waaihub-system-web-20260729/system_web/css/base.css`
  - `/tmp/3waaihub-system-web-20260729/system_web/css/app.css`
  - `/tmp/3waaihub-system-web-20260729/system_web/css/dashboard.css`
  - `/tmp/3waaihub-system-web-20260729/system_web/css/packs.css`
  - `/tmp/3waaihub-system-web-20260729/system_web/css/login.css`
- Exact visual constants to preserve include `#1D4ED8`, `#2563EB`, `#0891B2`, `#EFF5FC`, `#0F2547`, the supplied 4/8 spacing scale, the supplied desktop shell width, and the supplied mobile navigation behavior.
- Functional data replaces mock template data, but layout hierarchy, density, typography, color, control geometry, icons, selected states, disclosure treatment, and responsive behavior do not change without a documented functional reason.
- Do not add Image Gen concepts. The user supplied and approved the complete design source.

## File Ownership Map

**Shared shell and assets**

- Modify: `admin/_layout.php` - one role-aware app shell, grouped navigation, active state, local assets.
- Modify: `login.php` - real login behavior inside the accepted login composition.
- Create: `assets/css/admin-base.css` - adapted reset, tokens, typography, controls, alerts, badges.
- Create: `assets/css/admin-shell.css` - adapted desktop/mobile header, navigation, submenu, user menu, shell.
- Create: `assets/css/admin-dashboard.css` - accepted dashboard grid, station tabs, charts, states.
- Create: `assets/css/admin-market.css` - accepted Pack catalog, workspace tabs, cards, disclosures, service controls.
- Create: `assets/css/admin-login.css` - accepted login layout and responsive behavior.
- Create: `assets/js/admin-shell.js` - mobile nav, submenu, user menu, Escape/outside-click behavior.
- Create: `assets/js/admin-dashboard.js` - local Chart.js rendering from server-provided data.
- Create: `assets/js/vendor/chart.umd.js` - bundled Chart.js from the supplied archive.
- Create: `assets/images/logo.svg`, `assets/images/login-bg.svg` - supplied default identity assets.
- Create: `assets/fonts/*` - local DM Sans, Space Grotesk, and Noto Sans TC files pinned to Google Fonts commit `7ff85c87f93ea6cca5f41c69f2e4edcb90240f26`, with per-family licenses and hashes.

**Canonical data/presentation helpers**

- Create: `app/admin_market.php` - Market categories, localized Pack copy, Pack/service view models.
- Create: `app/admin_records.php` - Runtime, API, job, service, and system record filters/queries.
- Create: `app/admin_dashboard.php` - standalone, child, router, and aggregate dashboard view model.
- Create: `app/branding.php` - validated managed logo storage and active asset resolution.
- Create: `app/release.php` - build ID formatting, local Git report, Pack/runner report, remote release cache, compatibility comparison.
- Modify: `app/bootstrap.php` - release ID and shared helper loading.
- Modify: `app/i18n.php`, `i18n/seed.json` - keyed Pack descriptions and all new labels.
- Modify: `app/storage.php` - branding setting default.
- Modify: `app/cluster_router.php` - 90-second freshness, compact release/Pack/aggregate status, dashboard station data.

**Canonical pages**

- Rewrite: `admin/marketplace.php` - `view=market|services`, `category=...`, Pack install and service operation surface.
- Rewrite: `admin/log_explorer.php` - five canonical record tabs.
- Rewrite: `admin/index.php` - role-aware dashboard using station titles.
- Modify: `admin/settings.php` - branding upload/preview/restore and CLI-only update guidance.
- Modify: `admin/environment.php` - read-only local/remote/station release state.
- Modify: `admin/cluster.php` - aggregate display label, release compatibility, localized labels.
- Create: `branding_asset.php` - safe endpoint serving the active managed or bundled logo.

**Legacy transition**

- Modify: `admin/packs.php`, `admin/models.php`, `admin/services.php`, `admin/api_usage.php`, `admin/runtime_runs.php` - hidden diagnostic notice and canonical link; no redirect.
- Modify: `README.md`, `docs/cluster-router.md`.
- Create: `docs/operations/release-freeze.md`.

**Focused tests**

- Create: `tests/suites/admin-ui.php`.
- Create: `tests/test_admin_shell.php`.
- Create: `tests/test_admin_market.php`.
- Create: `tests/test_admin_records.php`.
- Create: `tests/test_branding.php`.
- Create: `tests/test_release_status.php`.
- Create: `tests/test_admin_dashboard.php`.
- Modify: `scripts/run_tests.php`.
- Modify: existing UI, i18n, Cluster, environment, release, and runtime visibility tests where their old navigation contract is intentionally retired.

### Task 1: Add A Focused Admin UI Test Suite

**Files:**
- Create: `tests/suites/admin-ui.php`
- Modify: `scripts/run_tests.php:284-310`

- [ ] **Step 1: Write the failing suite-selection test**

Add this assertion to `tests/test_release_ci.php`:

```php
$runner = (string)file_get_contents(HUB_ROOT . '/scripts/run_tests.php');
hub_test_assert(
    str_contains($runner, "'admin-ui'"),
    'run_tests must expose the focused admin-ui suite'
);
```

- [ ] **Step 2: Run the control-plane suite to verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: one failure containing `run_tests must expose the focused admin-ui suite`.

- [ ] **Step 3: Allow named checked-in suite manifests**

Change the suite guard in `scripts/run_tests.php` to:

```php
function hub_test_suite_files(string $suite): array
{
    if ($suite === 'full') {
        return glob(HUB_ROOT . '/tests/test_*.php') ?: [];
    }
    if (!in_array($suite, ['control-plane', 'admin-ui'], true)) {
        throw new InvalidArgumentException('Unknown suite: ' . $suite);
    }

    $manifestPath = HUB_ROOT . '/tests/suites/' . $suite . '.php';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Suite manifest is missing: ' . $suite);
    }
    $files = require $manifestPath;
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('Suite manifest must return a non-empty file list: ' . $suite);
    }

    return $files;
}
```

Create `tests/suites/admin-ui.php`:

```php
<?php
declare(strict_types=1);

return [
    __DIR__ . '/../test_i18n.php',
    __DIR__ . '/../test_phase_ui2.php',
    __DIR__ . '/../test_pack_catalog_ui.php',
    __DIR__ . '/../test_log_explorer.php',
    __DIR__ . '/../test_cluster_admin.php',
    __DIR__ . '/../test_environment_probe.php',
    __DIR__ . '/../test_release_ci.php',
];
```

- [ ] **Step 4: Set PHP file permissions and run both focused suites**

Run:

```bash
chmod 755 tests/suites/admin-ui.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: both summaries end with `failures=0`.

- [ ] **Step 5: Commit**

```bash
git add docs/superpowers/plans/2026-07-29-admin-market-cluster-dashboard.md scripts/run_tests.php tests/suites/admin-ui.php tests/test_release_ci.php
git commit -m "test: add focused admin UI suite"
```

### Task 2: Vendor The Accepted Visual System And Build The Shared Shell

**Files:**
- Create: `assets/css/admin-base.css`
- Create: `assets/css/admin-shell.css`
- Create: `assets/css/admin-login.css`
- Create: `assets/js/admin-shell.js`
- Create: `assets/js/vendor/chart.umd.js`
- Create: `assets/images/logo.svg`
- Create: `assets/images/login-bg.svg`
- Create: `assets/fonts/DM-Sans-Variable.ttf`
- Create: `assets/fonts/Space-Grotesk-Variable.ttf`
- Create: `assets/fonts/Noto-Sans-TC-Variable.ttf`
- Create: `assets/fonts/DM-Sans-OFL.txt`
- Create: `assets/fonts/Space-Grotesk-OFL.txt`
- Create: `assets/fonts/Noto-Sans-TC-OFL.txt`
- Create: `assets/fonts/SOURCES.md`
- Create: `tests/test_admin_shell.php`
- Modify: `tests/suites/admin-ui.php`
- Modify: `admin/_layout.php`
- Modify: `login.php`
- Modify: `assets/css/admin.css`
- Modify: `tests/test_phase_ui2.php`
- Modify: `tests/test_phase_auth1a_hardening.php`

- [ ] **Step 1: Write shell contract tests**

Create `tests/test_admin_shell.php` with tests that render both roles and inspect local assets:

```php
<?php
declare(strict_types=1);

hub_test('admin shell exposes exactly nine agreed destinations and no legacy navigation', function (): void {
    $db = hub_test_reset_db();
    $admin = $db->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/index.php';
    $_SERVER['REQUEST_URI'] = '/3waAIHub/admin/index.php';
    ob_start();
    hub_admin_header('控制台', $admin);
    hub_admin_footer();
    $html = (string)ob_get_clean();

    foreach (['控制台', '安裝套件', '客戶管理', 'API 金鑰', 'Cluster 管理', '測試中心', '記錄中心', '系統環境', '系統設定'] as $label) {
        hub_test_assert(substr_count($html, 'data-top-nav="' . $label . '"') === 1, 'top navigation mismatch: ' . $label);
    }
    foreach (['packs.php', 'models.php', 'services.php', 'api_usage.php', 'runtime_runs.php'] as $legacy) {
        hub_test_assert(!str_contains($html, 'href="' . $legacy), 'legacy URL leaked into navigation: ' . $legacy);
    }
    foreach (['admin-base.css', 'admin-shell.css', 'admin-shell.js', 'assets/images/logo.svg'] as $asset) {
        hub_test_assert(str_contains($html, $asset), 'shell asset missing: ' . $asset);
    }
});

hub_test('admin runtime pages have no CDN dependency', function (): void {
    foreach (['admin/_layout.php', 'login.php', 'admin/index.php', 'admin/marketplace.php'] as $path) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $path);
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com'] as $remote) {
            hub_test_assert(!str_contains($source, $remote), $path . ' contains remote asset ' . $remote);
        }
    }
    foreach ([
        'assets/css/admin-base.css',
        'assets/css/admin-shell.css',
        'assets/css/admin-login.css',
        'assets/js/admin-shell.js',
        'assets/js/vendor/chart.umd.js',
        'assets/images/logo.svg',
        'assets/images/login-bg.svg',
    ] as $path) {
        hub_test_assert(is_file(HUB_ROOT . '/' . $path), 'vendored visual asset missing: ' . $path);
    }
});
```

Append the test file to `tests/suites/admin-ui.php`.

- [ ] **Step 2: Run the focused suite to verify shell tests fail**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: failures report the old navigation and missing local asset files.

- [ ] **Step 3: Copy only accepted static assets and pin local fonts**

Copy the default logo, login background, and Chart.js exactly from the supplied extraction. Download the three OFL font variable files and their licenses from the official Google Fonts repository at immutable commit `7ff85c87f93ea6cca5f41c69f2e4edcb90240f26`. Record the source URLs and expected hashes in `assets/fonts/SOURCES.md`; commit all files so production performs no font download.

Run:

```bash
mkdir -p assets/images assets/fonts assets/js/vendor
install -m 0644 /tmp/3waaihub-system-web-20260729/system_web/images/logo.svg assets/images/logo.svg
install -m 0644 /tmp/3waaihub-system-web-20260729/system_web/images/login-bg.svg assets/images/login-bg.svg
install -m 0644 /tmp/3waaihub-system-web-20260729/system_web/js/vendor/chart.umd.js assets/js/vendor/chart.umd.js
FONT_BASE='https://raw.githubusercontent.com/google/fonts/7ff85c87f93ea6cca5f41c69f2e4edcb90240f26/ofl'
curl -fL "$FONT_BASE/dmsans/DMSans%5Bopsz%2Cwght%5D.ttf" -o assets/fonts/DM-Sans-Variable.ttf
curl -fL "$FONT_BASE/spacegrotesk/SpaceGrotesk%5Bwght%5D.ttf" -o assets/fonts/Space-Grotesk-Variable.ttf
curl -fL "$FONT_BASE/notosanstc/NotoSansTC%5Bwght%5D.ttf" -o assets/fonts/Noto-Sans-TC-Variable.ttf
curl -fL "$FONT_BASE/dmsans/OFL.txt" -o assets/fonts/DM-Sans-OFL.txt
curl -fL "$FONT_BASE/spacegrotesk/OFL.txt" -o assets/fonts/Space-Grotesk-OFL.txt
curl -fL "$FONT_BASE/notosanstc/OFL.txt" -o assets/fonts/Noto-Sans-TC-OFL.txt
sha256sum -c <<'EOF'
8cd08d97e89c24d0aa92edd2f0f4c8ee6195eee9b7c9f154865a58b02f0c1c0d  assets/fonts/DM-Sans-Variable.ttf
acad6de1fc93436f5c0f1f4137751ef04f1aea3063e7036535970ffcfbd79f72  assets/fonts/Space-Grotesk-Variable.ttf
864727d210d54f2537bbe23b3a839436c3992af72de9322af5270897246bd44f  assets/fonts/Noto-Sans-TC-Variable.ttf
9af36190332437f5ecd09974de43c1f7c77a310a996cdd8ceb25628b458840e1  assets/fonts/DM-Sans-OFL.txt
564ce565c371c5e5bbf286006565a7c9aa55a9f56e7ca58d56e05d649dd61a72  assets/fonts/Space-Grotesk-OFL.txt
1c05c68c34f9708415aada51f17e1b0092d2cea709bf4a94cd38114f9e73d7d9  assets/fonts/Noto-Sans-TC-OFL.txt
EOF
sha256sum assets/images/logo.svg assets/images/login-bg.svg assets/js/vendor/chart.umd.js
```

Expected: all six pinned font/license checks report `OK`, and the three supplied visual assets produce SHA-256 lines. `SOURCES.md` records the immutable commit, all six raw URLs, and the six expected hashes above. Do not copy `js/i18n.js`, template fake datasets, or template login/captcha behavior.

Create `assets/fonts/SOURCES.md` with this exact provenance:

```markdown
# Local Font Sources

Source repository: `https://github.com/google/fonts`
Pinned commit: `7ff85c87f93ea6cca5f41c69f2e4edcb90240f26`

| Local file | Repository path | SHA-256 |
| --- | --- | --- |
| `DM-Sans-Variable.ttf` | `ofl/dmsans/DMSans[opsz,wght].ttf` | `8cd08d97e89c24d0aa92edd2f0f4c8ee6195eee9b7c9f154865a58b02f0c1c0d` |
| `Space-Grotesk-Variable.ttf` | `ofl/spacegrotesk/SpaceGrotesk[wght].ttf` | `acad6de1fc93436f5c0f1f4137751ef04f1aea3063e7036535970ffcfbd79f72` |
| `Noto-Sans-TC-Variable.ttf` | `ofl/notosanstc/NotoSansTC[wght].ttf` | `864727d210d54f2537bbe23b3a839436c3992af72de9322af5270897246bd44f` |
| `DM-Sans-OFL.txt` | `ofl/dmsans/OFL.txt` | `9af36190332437f5ecd09974de43c1f7c77a310a996cdd8ceb25628b458840e1` |
| `Space-Grotesk-OFL.txt` | `ofl/spacegrotesk/OFL.txt` | `564ce565c371c5e5bbf286006565a7c9aa55a9f56e7ca58d56e05d649dd61a72` |
| `Noto-Sans-TC-OFL.txt` | `ofl/notosanstc/OFL.txt` | `1c05c68c34f9708415aada51f17e1b0092d2cea709bf4a94cd38114f9e73d7d9` |
```

- [ ] **Step 4: Adapt design tokens without changing the accepted values**

Build `assets/css/admin-base.css` from supplied `base.css`. The font declarations must be local:

```css
@font-face {
  font-family: "DM Sans";
  src: url("../fonts/DM-Sans-Variable.ttf") format("truetype");
  font-style: normal;
  font-weight: 400 700;
  font-display: swap;
}
@font-face {
  font-family: "Space Grotesk";
  src: url("../fonts/Space-Grotesk-Variable.ttf") format("truetype");
  font-style: normal;
  font-weight: 500 700;
  font-display: swap;
}
@font-face {
  font-family: "Noto Sans TC";
  src: url("../fonts/Noto-Sans-TC-Variable.ttf") format("truetype");
  font-style: normal;
  font-weight: 400 700;
  font-display: swap;
}

:root {
  --primary: #1D4ED8;
  --primary-600: #2563EB;
  --primary-700: #1E40AF;
  --primary-050: #EFF5FF;
  --accent: #0891B2;
  --accent-text: #0E7490;
  --bg: #EFF5FC;
  --surface: #FFFFFF;
  --surface-soft: #F7FAFD;
  --fg: #0F2547;
  --fg-muted: #55688A;
  --border: #DCE6F2;
  --border-strong: #C7D7EA;
  --sp-1: 4px;
  --sp-2: 8px;
  --sp-3: 12px;
  --sp-4: 16px;
  --sp-5: 20px;
  --sp-6: 24px;
  --sp-8: 32px;
  --font-display: "Space Grotesk", "Noto Sans TC", "Microsoft JhengHei", system-ui, sans-serif;
  --font-body: "DM Sans", "Noto Sans TC", "Microsoft JhengHei", system-ui, sans-serif;
}
```

Adapt supplied `app.css` into `assets/css/admin-shell.css` and supplied `login.css` into `assets/css/admin-login.css`. Retain their breakpoints, navigation geometry, focus states, true white surfaces, and mobile drawer behavior. Move the small job progress rules from `assets/css/admin.css` into `admin-base.css`; leave `admin.css` as a compatibility import:

```css
@import url("./admin-base.css");
```

- [ ] **Step 5: Replace the layout with one data-driven shell**

Keep the public function signatures backward compatible and add these helpers in `admin/_layout.php`:

```php
function hub_admin_nav_group(string $script): string
{
    return match ($script) {
        'index.php' => 'dashboard',
        'marketplace.php', 'packs.php', 'models.php', 'services.php' => 'market',
        'customers.php', 'customer_edit.php' => 'customers',
        'api_members.php', 'api_member_edit.php', 'api_tokens.php',
        'api_token_permissions.php', 'api_token_whitelist.php' => 'keys',
        'cluster.php' => 'cluster',
        'playground.php', 'api_docs.php', 'benchmarks.php' => 'testing',
        'log_explorer.php', 'api_usage.php', 'runtime_runs.php', 'runtime_run.php',
        'service_logs.php', 'log_detail.php' => 'records',
        'environment.php' => 'environment',
        'settings.php', 'i18n.php' => 'settings',
        default => '',
    };
}

function hub_admin_top_navigation(): array
{
    return [
        ['key' => 'dashboard', 'label' => '控制台', 'href' => 'index.php'],
        ['key' => 'market', 'label' => '安裝套件', 'href' => 'marketplace.php'],
        ['key' => 'customers', 'label' => '客戶管理', 'href' => 'customers.php'],
        ['key' => 'keys', 'label' => 'API 金鑰', 'href' => 'api_members.php'],
        ['key' => 'cluster', 'label' => 'Cluster 管理', 'href' => 'cluster.php'],
        ['key' => 'testing', 'label' => '測試中心', 'children' => [
            ['label' => 'API 測試場', 'href' => 'playground.php'],
            ['label' => 'API 文件', 'href' => 'api_docs.php'],
            ['label' => 'Benchmark 測試', 'href' => 'benchmarks.php'],
        ]],
        ['key' => 'records', 'label' => '記錄中心', 'children' => [
            ['label' => '執行歷程', 'href' => 'log_explorer.php?tab=runs'],
            ['label' => 'API 記錄', 'href' => 'log_explorer.php?tab=api'],
            ['label' => '背景工作', 'href' => 'log_explorer.php?tab=jobs'],
            ['label' => '服務記錄', 'href' => 'log_explorer.php?tab=service'],
            ['label' => '系統記錄', 'href' => 'log_explorer.php?tab=system'],
        ]],
        ['key' => 'environment', 'label' => '系統環境', 'href' => 'environment.php'],
        ['key' => 'settings', 'label' => '系統設定', 'href' => 'settings.php'],
    ];
}
```

Render the supplied `appbar`, brand, `mainnav`, grouped submenu, user menu, language selector, mobile toggle, skip link, `<main class="shell">`, and footer exactly once. Mark each top item with `data-top-nav="<?= hub_h($item['label']) ?>"`, use `aria-current="page"` for the active group, and escape every dynamic label and URL.

Keep the customer navigation branch, but render it in the same shell and preserve its current local and Cluster API document links.

- [ ] **Step 6: Adapt only shell interaction code**

Create `assets/js/admin-shell.js` from the supplied `app.js`, retaining:

```js
(() => {
  'use strict';

  const closeExpanded = (except) => {
    document.querySelectorAll('[aria-expanded="true"]').forEach((control) => {
      if (control !== except) {
        control.setAttribute('aria-expanded', 'false');
        const target = document.getElementById(control.getAttribute('aria-controls') || '');
        if (target) target.hidden = true;
      }
    });
  };

  document.querySelectorAll('[aria-controls]').forEach((control) => {
    const target = document.getElementById(control.getAttribute('aria-controls') || '');
    if (!target) return;
    control.addEventListener('click', () => {
      const opening = control.getAttribute('aria-expanded') !== 'true';
      closeExpanded(control);
      control.setAttribute('aria-expanded', opening ? 'true' : 'false');
      target.hidden = !opening;
    });
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeExpanded(null);
  });
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('.appbar')) closeExpanded(null);
  });
})();
```

Preserve the supplied mobile body/drawer class behavior as part of this file. Do not carry over template fake language data or fake user state.

- [ ] **Step 7: Recompose the real login form**

Keep all current lockout, server captcha, CSRF/session, error, and redirect behavior. Replace only HTML/CSS composition with the supplied login layout. The server captcha stays text-based and must remain accessible; do not copy the template's client-generated canvas captcha.

Load:

```html
<link rel="icon" href="assets/images/logo.svg">
<link rel="stylesheet" href="assets/css/admin-base.css">
<link rel="stylesheet" href="assets/css/admin-login.css">
```

Use `hub_site_title()`, `hub_site_subtitle()`, and `hub_i18n_language_selector()`. Use the bundled `assets/images/logo.svg` in every accepted brand position during this task; Task 6 replaces those references atomically with the managed branding endpoint after that endpoint exists and has tests.

- [ ] **Step 8: Run focused tests and syntax checks**

Run:

```bash
chmod 755 tests/test_admin_shell.php
php -l admin/_layout.php
php -l login.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: no syntax errors; both suites end with `failures=0`.

- [ ] **Step 9: Commit**

```bash
git add admin/_layout.php login.php assets tests/suites/admin-ui.php tests/test_admin_shell.php tests/test_phase_ui2.php tests/test_phase_auth1a_hardening.php
git commit -m "feat: adopt shared admin visual shell"
```

### Task 3: Add Keyed Pack Descriptions And Canonical Market View Models

**Files:**
- Create: `app/admin_market.php`
- Create: `tests/test_admin_market.php`
- Modify: `app/i18n.php`
- Modify: `i18n/seed.json`
- Modify: `tests/test_i18n.php`
- Modify: `tests/test_pack_catalog_ui.php`
- Modify: `tests/suites/admin-ui.php`

- [ ] **Step 1: Write failing i18n and category tests**

At the top of `tests/test_admin_market.php`, load the helper explicitly:

```php
require_once HUB_ROOT . '/app/admin_market.php';
```

The new tests must prove one-category-only behavior and keyed Chinese fallback:

```php
hub_test('Market categories are exclusive and sum to all Packs', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $catalog = hub_admin_market_catalog($db, 'all');
    $sum = 0;
    foreach (['reference', 'vision', 'language', 'audio', 'tools', 'experimental'] as $key) {
        $sum += $catalog['counts'][$key];
    }
    hub_test_assert($sum === $catalog['counts']['all'], 'Market category counts overlap or omit a Pack');
    hub_test_assert(hub_admin_market_category('utility') === 'tools', 'legacy utility category must normalize to tools');
    hub_test_assert(hub_admin_market_category('unknown') === 'all', 'unknown category must normalize to all');
});

hub_test('Pack purpose uses a keyed Chinese seed and manifest fallback', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $pack = hub_get_pack('ocr-ppocrv5');
    $description = hub_admin_market_pack_description($db, $pack);
    hub_test_assert(str_contains($description, '圖片文字辨識'), 'OCR Chinese purpose copy missing');

    $unknown = ['id' => 'unseeded-pack', 'manifest' => ['description' => 'Manifest fallback']];
    hub_test_assert(hub_admin_market_pack_description($db, $unknown) === 'Manifest fallback', 'manifest description fallback mismatch');
});
```

- [ ] **Step 2: Run the focused suite to verify the new functions are absent**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: failure naming `hub_admin_market_catalog`.

- [ ] **Step 3: Support keyed seed rows without changing existing `__()` calls**

Add this helper in `app/i18n.php`:

```php
function hub_i18n_seeded(string $key, string $fallback, ?string $lang = null, ?PDO $db = null): string
{
    $key = trim($key);
    $fallback = trim($fallback);
    if ($key === '') {
        return __($fallback, $lang);
    }
    $lang = hub_i18n_normalize_lang($lang ?? hub_i18n_current_lang());
    $db ??= hub_db();
    $stmt = $db->prepare('SELECT trans FROM i18n WHERE title = :title AND lang = :lang ORDER BY id DESC LIMIT 1');
    $stmt->execute([':title' => $key, ':lang' => $lang]);
    $translation = trim((string)($stmt->fetchColumn() ?: ''));
    if ($translation !== '') {
        return $translation;
    }

    return $lang === 'zh_TW' ? $fallback : __($fallback, $lang);
}
```

Update `hub_i18n_import_seed()` validation so `zh_TW` is accepted only for namespaced keys:

```php
$isKeyedSource = preg_match('/\A[a-z0-9_.-]+\z/i', $title) === 1 && str_contains($title, '.');
if ($title === '' || $trans === '' || ($lang === 'zh_TW' && !$isKeyedSource)) {
    continue;
}
```

Existing natural-language `__()` behavior remains unchanged.

- [ ] **Step 4: Seed concise Chinese purpose copy for every current Pack**

Append `zh_TW` rows keyed exactly as:

```json
[
  {"title":"pack.hello.description","lang":"zh_TW","trans":"範例 API 服務，用來驗證 Pack 安裝、健康檢查與 Gateway。"},
  {"title":"pack.ocr-ppocrv5.description","lang":"zh_TW","trans":"圖片文字辨識服務，可擷取中英文文字、座標與信心分數。"},
  {"title":"pack.yolo.description","lang":"zh_TW","trans":"影像物件偵測服務，可回傳類別、信心分數與框選座標。"},
  {"title":"pack.yolo-serving.description","lang":"zh_TW","trans":"受管的 YOLO 模型部署與 GPU 推論服務。"},
  {"title":"pack.sam3.description","lang":"zh_TW","trans":"依提示分割影像目標，輸出遮罩、輪廓或 RLE。"},
  {"title":"pack.image-birefnet.description","lang":"zh_TW","trans":"高品質圖片去背服務，輸出含透明背景的 PNG。"},
  {"title":"pack.bioclip.description","lang":"zh_TW","trans":"以影像辨識生物物種並回傳候選分類與信心分數。"},
  {"title":"pack.translate-gemma12b.description","lang":"zh_TW","trans":"以 Gemma 模型進行多語翻譯與正體中文輸出。"},
  {"title":"pack.llm-gemma4-12b.description","lang":"zh_TW","trans":"整合文字對話、圖片理解與短音訊分析的多模態服務。"},
  {"title":"pack.rag-nemotron.description","lang":"zh_TW","trans":"以 Nemotron Embedding 與 Rerank 強化文件檢索排序。"},
  {"title":"pack.structure-ppstructurev3.description","lang":"zh_TW","trans":"解析文件版面、表格與區塊結構，供後續資料處理。"},
  {"title":"pack.docparser.description","lang":"zh_TW","trans":"整合 PDF 轉換、OCR、版面、圖片與翻譯的文件處理流程。"},
  {"title":"pack.taiwan-address.description","lang":"zh_TW","trans":"清理台灣地址並介接可信任的定位與門牌資料服務。"},
  {"title":"pack.whisper-asr.description","lang":"zh_TW","trans":"將 WAV 語音辨識為文字，支援 GPU 非同步工作。"},
  {"title":"pack.tts-voxcpm2.description","lang":"zh_TW","trans":"以參考聲音、字幕或設計描述產生語音的實驗服務。"},
  {"title":"pack.edge-tts.description","lang":"zh_TW","trans":"輕量免 GPU 的多語語音生成與聲線試聽服務。"},
  {"title":"pack.audio-cleanup.description","lang":"zh_TW","trans":"分離人聲與背景音，並可輸出增強後的乾淨音訊。"},
  {"title":"pack.web-screenshot.description","lang":"zh_TW","trans":"在受控網路規則下擷取網頁畫面，供分析與自動化流程使用。"}
]
```

Merge these objects into the existing JSON array rather than replacing current translations.

- [ ] **Step 5: Implement the Market view-model helper**

Create `app/admin_market.php` with these stable interfaces:

```php
<?php
declare(strict_types=1);

function hub_admin_market_categories(): array
{
    return [
        'all' => '全部',
        'reference' => '參考樣板',
        'vision' => '視覺影像',
        'language' => '語言文字',
        'audio' => '音訊語音',
        'tools' => '工具',
        'experimental' => '實驗中',
    ];
}

function hub_admin_market_category(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === 'utility') {
        $value = 'tools';
    }
    return array_key_exists($value, hub_admin_market_categories()) ? $value : 'all';
}

function hub_admin_market_category_for_manifest(array $manifest): string
{
    if (strtolower((string)($manifest['role'] ?? '')) === 'reference') {
        return 'reference';
    }
    if (!empty($manifest['experimental'])) {
        return 'experimental';
    }
    $category = strtolower((string)($manifest['category'] ?? ''));
    if (in_array($category, ['vision', 'ocr', 'segmentation', 'detection', 'object-detection'], true)) {
        return 'vision';
    }
    if (in_array($category, ['language', 'translation', 'translate', 'llm'], true)) {
        return 'language';
    }
    if ($category === 'audio') {
        return 'audio';
    }
    if (in_array($category, ['utility', 'tool', 'tools', 'web'], true)) {
        return 'tools';
    }
    return 'experimental';
}

function hub_admin_market_pack_description(PDO $db, array $pack): string
{
    $packId = (string)($pack['id'] ?? $pack['manifest']['id'] ?? '');
    $fallback = (string)($pack['manifest']['description'] ?? '');
    return hub_i18n_seeded('pack.' . $packId . '.description', $fallback, null, $db);
}
```

Also move the existing runtime, GPU, model readiness, endpoint, installed statistics, and L5 readiness helpers from `admin/packs.php` and `admin/marketplace.php` into this file under the `hub_admin_market_*` prefix. Implement `hub_admin_market_catalog()` with computed counts and rows:

```php
$activeCategory = hub_admin_market_category($requestedCategory);
$categories = hub_admin_market_categories();
$counts = array_fill_keys(array_keys($categories), 0);
$rows = [];

foreach (hub_list_packs() as $pack) {
    $category = hub_admin_market_category_for_manifest((array)($pack['manifest'] ?? []));
    $counts['all']++;
    $counts[$category]++;
    $pack['market_category'] = $category;
    $pack['purpose'] = hub_admin_market_pack_description($db, $pack);
    if ($activeCategory === 'all' || $activeCategory === $category) {
        $rows[] = $pack;
    }
}

return [
    'active_category' => $activeCategory,
    'categories' => $categories,
    'counts' => $counts,
    'packs' => $rows,
];
```

Assert in the helper test that the six non-`all` values sum to `all`; never hard-code the Pack total.

- [ ] **Step 6: Run tests and import the seed into the test database**

Run:

```bash
chmod 755 app/admin_market.php tests/test_admin_market.php
php -l app/admin_market.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: `failures=0`.

- [ ] **Step 7: Commit**

```bash
git add app/admin_market.php app/i18n.php i18n/seed.json tests/test_i18n.php tests/test_pack_catalog_ui.php tests/test_admin_market.php tests/suites/admin-ui.php
git commit -m "feat: add localized Market catalog model"
```

### Task 4: Merge Pack Discovery And Installed Services Into Canonical Market

**Files:**
- Create: `assets/css/admin-market.css`
- Modify: `admin/marketplace.php`
- Modify: `assets/js/services.js`
- Modify: `assets/js/packs.js`
- Modify: `assets/css/admin.css`
- Modify: `admin/packs.php`
- Modify: `admin/models.php`
- Modify: `admin/services.php`
- Modify: `tests/test_admin_market.php`
- Modify: `tests/test_pack_catalog_ui.php`
- Modify: `tests/test_runtime_visibility.php`

- [ ] **Step 1: Write failing canonical endpoint tests**

Add render tests for both workspaces:

```php
hub_test('canonical Market renders category counts and collapsed technical details', function (): void {
    $db = hub_test_reset_db();
    hub_i18n_import_seed($db);
    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/marketplace.php';
    $_GET = ['view' => 'market', 'category' => 'vision'];
    ob_start();
    require HUB_ROOT . '/admin/marketplace.php';
    $html = (string)ob_get_clean();

    foreach (['data-market-view="market"', 'category=vision', 'ocr-ppocrv5', '圖片文字辨識', '<details', 'runtime_level', 'pack_id'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'Market render missing ' . $needle);
    }
    hub_test_assert(!str_contains($html, 'href="packs.php"'), 'canonical Market must not link to legacy packs page');
});

hub_test('installed-services workspace keeps command queue actions and details collapsed', function (): void {
    $db = hub_test_reset_db();
    hub_install_pack($db, 'hello', ['provision_runner' => false]);
    $_SESSION = ['user_id' => 1, 'username' => 'admin', 'csrf_token' => 'test'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['view' => 'services'];
    ob_start();
    require HUB_ROOT . '/admin/marketplace.php';
    $html = (string)ob_get_clean();

    foreach (['data-market-view="services"', 'service-action-form', 'value="start"', 'value="stop"', 'value="refresh"', '<details', 'service_key'] as $needle) {
        hub_test_assert(str_contains($html, $needle), 'service workspace missing ' . $needle);
    }
});
```

- [ ] **Step 2: Run the focused suite to verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: canonical workspace assertions fail against the old Marketplace.

- [ ] **Step 3: Add a strict workspace/action controller**

After the existing bootstrap load at the top of `admin/marketplace.php`, add:

```php
require_once HUB_ROOT . '/app/admin_market.php';
```

Load the same helper from legacy `admin/packs.php` only for the compatibility functions that remain there. Then normalize query state:

```php
$view = in_array((string)($_GET['view'] ?? 'market'), ['market', 'services'], true)
    ? (string)$_GET['view']
    : 'market';
$category = hub_admin_market_category((string)($_GET['category'] ?? 'all'));
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
```

Retain the current Pack install options and `service_install` background job. For service operations, use only this allowlist:

```php
$serviceActions = [
    'build' => 'service_build',
    'start' => 'service_start',
    'stop' => 'service_stop',
    'restart' => 'service_restart',
    'rebuild' => 'service_rebuild',
    'refresh' => 'service_health_check',
];
```

Validate CSRF, fetch the service by integer ID, reject unknown actions, enqueue through `hub_enqueue_command_job()`, and return the same JSON shape currently consumed by `assets/js/services.js`. `start` is the enable operation and `stop` is the disable operation because the worker already updates `services.enabled`; do not add another queue action.

- [ ] **Step 4: Render the accepted Market workspace**

Adapt supplied `packs.css` into `assets/css/admin-market.css`. Render:

```php
<nav class="workspace-tabs" aria-label="<?= hub_h(__('安裝套件工作區')) ?>">
    <a class="workspace-tab<?= $view === 'market' ? ' is-active' : '' ?>"
       href="marketplace.php?view=market&amp;category=<?= hub_h($category) ?>">
        <?= hub_h(__('套件市集')) ?>
    </a>
    <a class="workspace-tab<?= $view === 'services' ? ' is-active' : '' ?>"
       href="marketplace.php?view=services">
        <?= hub_h(__('已安裝服務')) ?>
    </a>
</nav>
```

For `view=market`, render the seven dense category links with counts. Each Pack card contains, in this order:

1. name and `pack_id`;
2. localized purpose;
3. runtime, CPU/GPU, model, installed/readiness badges;
4. primary install/configure action;
5. native `<details>` containing version, type, `runtime_level`, `target_level`, `mode`, `endpoint`, `execution_type`, model selectors, preflight checks, and validation errors.

For `view=services`, preserve start, stop, restart, build, rebuild, health refresh, settings, logs, Benchmark, playground, API URL copy, active job progress, and polling. Keep endpoint/runtime/config/recent-job detail inside a native `<details>`; required errors and operation buttons stay visible.

Use `hub_status_label()` or a shared mapping with these visible results:

```php
[
    'queued' => __('排隊中'),
    'starting' => __('啟動中'),
    'running' => __('執行中'),
    'stopped' => __('已停止'),
    'unhealthy' => __('異常'),
    'failed' => __('失敗'),
]
```

- [ ] **Step 5: Feed JavaScript a page-scoped in-memory dictionary**

Render:

```php
<script id="market-i18n" type="application/json"><?= hub_json_encode([
    'running' => __('執行中'),
    'stopped' => __('已停止'),
    'unknown' => __('未知'),
    'health_ok' => __('健康正常'),
    'health_checking' => __('健康檢查中'),
    'health_failed' => __('健康異常'),
    'poll_failed' => __('讀取背景工作狀態失敗，請稍後重試或重新整理。'),
    'action_failed' => __('操作失敗，請重新整理後再試。'),
    'queued' => __('已排入背景工作。'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
```

At the top of `assets/js/services.js`, parse once:

```js
const dictionaryNode = document.getElementById('market-i18n');
const dictionary = dictionaryNode ? JSON.parse(dictionaryNode.textContent || '{}') : {};
const t = (key, fallback) => dictionary[key] || fallback;
```

Replace every visible hard-coded Chinese status/error string with `t()`. Do not create a translation endpoint or write translations to `localStorage`.

Point Pack readiness AJAX in `assets/js/packs.js` to `marketplace.php` and return it from the canonical controller.

- [ ] **Step 6: Mark legacy pages without redirecting or sharing their HTML**

At the top of each legacy page body, add:

```php
<section class="notice legacy-debug" role="note">
    <strong><?= hub_h(__('Legacy debug 頁面')) ?></strong>
    <?= hub_h(__('此頁已退出主選單，正式操作請使用「安裝套件」。')) ?>
    <a href="marketplace.php"><?= hub_h(__('前往安裝套件')) ?></a>
</section>
```

Add a PHPDoc marker near each controller:

```php
/** @deprecated Canonical UI: admin/marketplace.php */
```

Do not redirect and do not include a legacy page from `marketplace.php`.

- [ ] **Step 7: Run focused tests and action regression tests**

Run:

```bash
php -l admin/marketplace.php
php -l admin/packs.php
php -l admin/models.php
php -l admin/services.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: syntax checks pass; both suites report `failures=0`; rendered POST tests still create `command_jobs`, never execute Docker in the web request.

- [ ] **Step 8: Commit**

```bash
git add admin/marketplace.php admin/packs.php admin/models.php admin/services.php assets/css/admin-market.css assets/css/admin.css assets/js/services.js assets/js/packs.js tests
git commit -m "feat: merge Pack and service administration"
```

### Task 5: Consolidate The Five Record Center Views

**Files:**
- Create: `app/admin_records.php`
- Create: `tests/test_admin_records.php`
- Modify: `admin/log_explorer.php`
- Modify: `admin/runtime_runs.php`
- Modify: `admin/api_usage.php`
- Modify: `tests/test_log_explorer.php`
- Modify: `tests/test_runtime_visibility.php`
- Modify: `tests/suites/admin-ui.php`

- [ ] **Step 1: Write failing tab-order and query tests**

```php
hub_test('Record Center exposes the agreed five tabs in order', function (): void {
    hub_test_assert(hub_admin_record_tabs() === [
        'runs' => '執行歷程',
        'api' => 'API 記錄',
        'jobs' => '背景工作',
        'service' => '服務記錄',
        'system' => '系統記錄',
    ], 'Record Center tab contract mismatch');
    hub_test_assert(hub_admin_record_tab('unknown') === 'runs', 'unknown record tab must use first canonical tab');
});

hub_test('Record Center runtime query reuses detail links', function (): void {
    $db = hub_test_reset_db();
    $runs = hub_admin_record_runtime_runs($db, ['pack_id' => '', 'task' => '', 'state' => '', 'q' => ''], 100);
    hub_test_assert(is_array($runs), 'runtime query result must be an array');
    $page = (string)file_get_contents(HUB_ROOT . '/admin/log_explorer.php');
    hub_test_assert(str_contains($page, 'runtime_run.php?id='), 'runtime detail link missing from Record Center');
});
```

- [ ] **Step 2: Run the focused suite to verify it fails**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: missing `hub_admin_record_tabs`.

- [ ] **Step 3: Move reusable filters and queries into `app/admin_records.php`**

At the top of `tests/test_admin_records.php`, add:

```php
require_once HUB_ROOT . '/app/admin_records.php';
```

Define:

```php
function hub_admin_record_tabs(): array
{
    return [
        'runs' => '執行歷程',
        'api' => 'API 記錄',
        'jobs' => '背景工作',
        'service' => '服務記錄',
        'system' => '系統記錄',
    ];
}

function hub_admin_record_tab(string $value): string
{
    $value = trim($value);
    return array_key_exists($value, hub_admin_record_tabs()) ? $value : 'runs';
}
```

Move the validated runtime filters/query from `admin/runtime_runs.php`, job filters/query/tail reader from `admin/log_explorer.php`, and add:

```php
function hub_admin_record_service_logs(PDO $db, int $serviceId = 0, int $limit = 200): array
{
    $sql = 'SELECT l.*, s.name AS service_name, s.service_key
            FROM service_logs l
            JOIN services s ON s.id = l.service_id';
    $params = [];
    if ($serviceId > 0) {
        $sql .= ' WHERE l.service_id = :service_id';
        $params[':service_id'] = $serviceId;
    }
    $sql .= ' ORDER BY l.id DESC LIMIT :limit';
    $stmt = $db->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function hub_admin_record_system_logs(PDO $db, int $limit = 200): array
{
    $stmt = $db->prepare('SELECT * FROM audit_logs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
```

- [ ] **Step 4: Render each canonical tab from its existing data source**

Load `app/admin_records.php` with `require_once` after bootstrap in `admin/log_explorer.php`, `admin/runtime_runs.php`, and `admin/api_usage.php`. `admin/log_explorer.php` defaults to `tab=runs`. Keep all existing API filters, base64url IP handling, pagination, job filters, bounded log tails, and detail links. Add Runtime rows from `runtime_runs`, Service rows from `service_logs`, and System rows from `audit_logs`.

Every tab link is a query URL. Filter forms retain their own tab in a hidden field. Tables use a responsive overflow wrapper rather than converting dense records into cards.

- [ ] **Step 5: Keep old list URLs as hidden diagnostics**

Add the same `Legacy debug` notice pattern to `admin/runtime_runs.php` and `admin/api_usage.php`, pointing to:

```text
log_explorer.php?tab=runs
log_explorer.php?tab=api
```

Keep their direct behavior and existing detail URLs. Remove all normal navigation links to them.

- [ ] **Step 6: Run tests and commit**

Run:

```bash
chmod 755 app/admin_records.php tests/test_admin_records.php
php -l app/admin_records.php
php -l admin/log_explorer.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git add app/admin_records.php admin/log_explorer.php admin/runtime_runs.php admin/api_usage.php tests
git commit -m "feat: consolidate admin record center"
```

Expected: tests report `failures=0`; no record query accepts an unvalidated column or raw IP query parameter.

### Task 6: Add Safe Configurable Branding

**Files:**
- Create: `app/branding.php`
- Create: `branding_asset.php`
- Create: `tests/test_branding.php`
- Modify: `app/bootstrap.php`
- Modify: `app/storage.php`
- Modify: `admin/settings.php`
- Modify: `admin/_layout.php`
- Modify: `login.php`
- Modify: `tests/test_phase_ui2.php`
- Modify: `tests/suites/admin-ui.php`

- [ ] **Step 1: Write failing branding storage tests**

```php
hub_test('branding accepts validated raster images and rejects SVG', function (): void {
    $db = hub_test_reset_db();
    $source = HUB_ROOT . '/packs/image-birefnet/demo/acceptance/person_hair.png';
    $tmp = tempnam(sys_get_temp_dir(), 'brand_png_');
    copy($source, $tmp);
    $stored = hub_branding_store_logo($db, [
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => filesize($tmp),
        'name' => 'logo.png',
    ]);
    hub_test_assert($stored['mime'] === 'image/png', 'branding PNG MIME mismatch');
    hub_test_assert(is_file($stored['path']), 'managed branding file missing');
    hub_test_assert(hub_branding_active_asset($db)['path'] === $stored['path'], 'active branding asset mismatch');

    $svg = tempnam(sys_get_temp_dir(), 'brand_svg_');
    file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
    try {
        hub_branding_store_logo($db, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $svg, 'size' => filesize($svg), 'name' => 'logo.svg']);
        hub_test_assert(false, 'SVG upload must be rejected');
    } catch (RuntimeException $error) {
        hub_test_assert($error->getMessage() === 'branding_unsupported_media_type', 'SVG rejection mismatch');
    }
});
```

- [ ] **Step 2: Run the focused suite to verify helper absence**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: missing `hub_branding_store_logo`.

- [ ] **Step 3: Implement managed branding storage**

Add `AIHUB_BRANDING_LOGO_FILE => ''` to default storage settings. Create `app/branding.php` with these constraints:

```php
function hub_branding_limits(): array
{
    return ['max_bytes' => 2 * 1024 * 1024, 'max_width' => 2048, 'max_height' => 2048, 'max_pixels' => 4_194_304];
}

function hub_branding_root(): string
{
    return HUB_DATA_DIR . '/uploads/branding';
}

function hub_branding_allowed_mimes(): array
{
    return ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'];
}
```

`hub_branding_store_logo()` must:

1. require `UPLOAD_ERR_OK` and a regular temporary file;
2. enforce the byte limit;
3. inspect MIME with `finfo(FILEINFO_MIME_TYPE)`;
4. validate width/height/pixels with `getimagesize()`;
5. create `uploads/branding` with `0775`;
6. write a random `logo-<32 hex>.<extension>` filename;
7. verify the new file exists and resolves within the branding root;
8. save only the basename in `AIHUB_BRANDING_LOGO_FILE`;
9. remove the previous managed file only after the setting update succeeds.

`hub_branding_restore_default()` clears the setting then removes the old managed file. `hub_branding_active_asset()` returns the managed file or:

```php
[
    'path' => HUB_ROOT . '/assets/images/logo.svg',
    'mime' => 'image/svg+xml',
    'managed' => false,
]
```

- [ ] **Step 4: Serve the active logo from one endpoint**

Create `branding_asset.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
$db = hub_db();
hub_migrate($db);
hub_ensure_default_storage_settings($db);
$asset = hub_branding_active_asset($db);
$etag = '"' . hash_file('sha256', $asset['path']) . '"';
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Type: ' . $asset['mime']);
header('Content-Length: ' . (string)filesize($asset['path']));
header('Cache-Control: public, max-age=300');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
readfile($asset['path']);
```

The endpoint accepts no path parameter.

- [ ] **Step 5: Add upload, preview, and restore controls**

In the Appearance tab, use `enctype="multipart/form-data"`, keep title/subtitle inputs, and add:

```php
<section class="setting-card branding-settings">
    <h3><?= hub_h(__('站台識別')) ?></h3>
    <img class="branding-preview" src="../branding_asset.php?v=<?= urlencode(hub_branding_version($db)) ?>" alt="<?= hub_h(__('目前站台 Logo')) ?>">
    <label for="branding-logo"><?= hub_h(__('上傳 Logo')) ?></label>
    <input id="branding-logo" name="branding_logo" type="file" accept="image/png,image/webp,image/jpeg">
    <p class="form-help"><?= hub_h(__('接受 PNG、WebP、JPEG；最大 2 MB、2048 × 2048。')) ?></p>
    <button class="btn" name="branding_action" value="upload" type="submit"><?= hub_h(__('上傳並套用')) ?></button>
    <button class="btn btn--ghost" name="branding_action" value="restore" type="submit"
            onclick="return confirm('<?= hub_h(__('確定恢復預設 Logo？')) ?>');"><?= hub_h(__('恢復預設')) ?></button>
</section>
```

Only system admins reach this page. Handle upload/restore after CSRF validation and report localized error messages without exposing filesystem paths.

- [ ] **Step 6: Switch every brand position to the tested endpoint**

Add `require_once __DIR__ . '/branding.php';` after storage in `app/bootstrap.php`.

Replace the bundled logo references introduced in Task 2 with:

```html
<!-- login.php -->
<link rel="icon" href="branding_asset.php">
<img src="branding_asset.php" alt="">

<!-- admin/_layout.php -->
<img src="../branding_asset.php" alt="">
```

Keep the textual site title beside the mark, retain explicit image dimensions in CSS to prevent layout shift, and add shell assertions that both pages now reference `branding_asset.php`.

- [ ] **Step 7: Verify permissions and tests**

Run:

```bash
chmod 755 app/branding.php branding_asset.php tests/test_branding.php
php -l app/branding.php
php -l branding_asset.php
php -l admin/settings.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: `failures=0`.

- [ ] **Step 8: Commit**

```bash
git add app/branding.php app/bootstrap.php app/storage.php branding_asset.php admin/settings.php admin/_layout.php login.php tests
git commit -m "feat: add managed site branding"
```

### Task 7: Introduce Date Build IDs And Read-Only Update Reporting

**Files:**
- Create: `app/release.php`
- Create: `scripts/check_release_update.php`
- Create: `tests/test_release_status.php`
- Create: `docs/operations/release-freeze.md`
- Modify: `app/bootstrap.php`
- Modify: `admin/environment.php`
- Modify: `admin/settings.php`
- Modify: `tests/test_release_ci.php`
- Modify: `tests/test_environment_probe.php`
- Modify: `tests/suites/admin-ui.php`
- Modify: `README.md`

- [ ] **Step 1: Write failing release-format and no-web-deploy tests**

```php
hub_test('date build IDs format for machine and UI use', function (): void {
    hub_test_assert(HUB_VERSION === '20260729001', 'development build ID mismatch');
    hub_test_assert(hub_release_display_version(HUB_VERSION) === '2026.07.29.001', 'display release format mismatch');
    hub_test_assert(hub_release_display_version('bad') === 'bad', 'invalid release must remain inspectable');
});

hub_test('settings and environment never execute deployment from HTTP', function (): void {
    foreach (['admin/settings.php', 'admin/environment.php'] as $path) {
        $source = (string)file_get_contents(HUB_ROOT . '/' . $path);
        foreach (['git pull', 'git checkout', 'git reset', 'shell_exec(', 'exec('] as $forbidden) {
            hub_test_assert(!str_contains($source, $forbidden), $path . ' contains web deployment operation ' . $forbidden);
        }
    }
});
```

- [ ] **Step 2: Run the focused suite to verify release tests fail**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: build ID and helper failures.

- [ ] **Step 3: Set the development build ID and release helper**

In `app/bootstrap.php`:

```php
define('HUB_VERSION', '20260729001');
define('HUB_RELEASE_LABEL', '8/7 Admin Market + Cluster Dashboard Preview');
```

Create `app/release.php` with:

```php
function hub_release_display_version(string $buildId): string
{
    return preg_match('/\A(\d{4})(\d{2})(\d{2})(\d{3})\z/', $buildId, $match) === 1
        ? $match[1] . '.' . $match[2] . '.' . $match[3] . '.' . $match[4]
        : $buildId;
}

function hub_release_pack_inventory(): array
{
    $inventory = [];
    foreach (hub_list_packs() as $pack) {
        $packId = (string)($pack['id'] ?? '');
        if ($packId !== '') {
            $inventory[$packId] = (string)($pack['manifest']['version'] ?? '');
        }
    }
    ksort($inventory, SORT_STRING);
    return $inventory;
}
```

Add injectable command execution for tests:

```php
function hub_release_local_git_report(?callable $runner = null): array
{
    $runner ??= static fn (array $command): array => hub_run_command($command, 3);
    $commit = $runner(['git', '-C', HUB_ROOT, 'rev-parse', '--short=12', 'HEAD']);
    $dirty = $runner(['git', '-C', HUB_ROOT, 'status', '--porcelain', '--untracked-files=no']);
    $tag = $runner(['git', '-C', HUB_ROOT, 'tag', '--points-at', 'HEAD']);
    $tags = preg_split('/\R/', trim((string)($tag['stdout'] ?? ''))) ?: [];
    $releaseTags = array_values(array_filter($tags, static fn (string $value): bool => preg_match('/\A\d{11}\z/', $value) === 1));
    rsort($releaseTags, SORT_STRING);
    return [
        'build_id' => HUB_VERSION,
        'display_version' => hub_release_display_version(HUB_VERSION),
        'commit' => trim((string)($commit['stdout'] ?? '')),
        'dirty' => trim((string)($dirty['stdout'] ?? '')) !== '',
        'tag' => $releaseTags[0] ?? '',
    ];
}
```

Add `hub_release_runner_inventory(PDO $db)` from the latest `runtime_runs.image_name/image_digest` per Pack, and `hub_release_node_report(PDO $db)` combining build, commit, dirty, tag, Pack versions, runner digests, and a health summary.

Load `app/release.php` from `app/bootstrap.php` after Pack/runtime helpers so Cluster reporting and admin pages use the same release contract.

- [ ] **Step 4: Implement CLI-only remote release discovery**

`scripts/check_release_update.php` must call `hub_cli_only()`, run:

```text
git -C <HUB_ROOT> ls-remote --tags --refs origin
```

Parse only `refs/tags/<11 digits>`, select the greatest ID, and atomically write:

```json
{
  "checked_at": "2026-07-29 12:00:00",
  "latest_release": "20260807001",
  "error": ""
}
```

to `HUB_DATA_DIR/cache/release_remote.json` with mode `0664`. On failure, preserve a compact error code such as `remote_unavailable`; never save credentials, command output, or remote URL.

- [ ] **Step 5: Render read-only update state**

In System Environment, show:

- current machine/UI release;
- commit and clean/dirty state;
- current tag;
- cached remote release and check time;
- Pack count and known runner digest count;
- each station's release, commit, health, Pack compatibility, and update-needed badge.

In System Settings, show the recommended commands as escaped text generated by `hub_release_update_commands(hub_platform_id())`; do not place literal `git pull` text in the page controller. Linux integration-host guidance may display `git fetch origin` and `git merge --ff-only origin/main`; execution-node guidance may display `git fetch --tags origin` and immutable tag checkout. Windows guidance uses PowerShell equivalents.

- [ ] **Step 6: Document the 8/7 freeze without creating the future tag**

`docs/operations/release-freeze.md` must state:

1. current development ID is `20260729001`;
2. change to `20260807001` only in the freeze commit;
3. run focused, control-plane, then full test suites;
4. verify all three hosts report the same tag/commit, Pack inventory, runner digest state, and health;
5. create immutable annotated tag `20260807001` from the verified commit;
6. 3wa is the normal push source;
7. 5090 and 1080 fetch/fast-forward or check out the immutable tag and never push;
8. WSL remains an authoring/validation environment, not a deployment authority.

- [ ] **Step 7: Run tests and commit**

Run:

```bash
chmod 755 app/release.php scripts/check_release_update.php tests/test_release_status.php
php -l app/release.php
php -l scripts/check_release_update.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git add app/release.php app/bootstrap.php scripts/check_release_update.php admin/environment.php admin/settings.php docs/operations/release-freeze.md README.md tests
git commit -m "feat: add release identity and update visibility"
```

Expected: tests report `failures=0`; no HTTP request changes Git state.

### Task 8: Extend Cluster Health Reports And Install Router Refresh Cron

**Files:**
- Modify: `app/cluster_router.php`
- Modify: `scripts/cluster_refresh.php`
- Modify: `crontab/1min.sh`
- Modify: `scripts/install_command_worker_cron.sh`
- Modify: `app/environment_probe.php`
- Modify: `admin/environment.php`
- Modify: `tests/test_cluster_router.php`
- Modify: `tests/test_environment_probe.php`
- Modify: `scripts/bootstrap_self_check.sh`

- [ ] **Step 1: Write failing freshness, status, and cron tests**

Add tests proving:

```php
$now = strtotime('2026-07-29 12:00:00');
$station = [
    'manifest_fetched_at' => '2026-07-29 11:58:40',
    'status_fetched_at' => '2026-07-29 11:58:40',
];
hub_test_assert(hub_cluster_station_is_fresh($station, $now), '80-second station snapshot must survive cron jitter');
$station['status_fetched_at'] = '2026-07-29 11:58:29';
hub_test_assert(!hub_cluster_station_is_fresh($station, $now), '91-second station snapshot must be stale');

$payload = hub_cluster_status_payload($db);
foreach (['release', 'packs', 'runners', 'health', 'cluster'] as $key) {
    hub_test_assert(array_key_exists($key, $payload), 'cluster status report missing ' . $key);
}
hub_test_assert(array_keys($payload['cluster']) === ['aggregate', 'children_count', 'published_mode_count'], 'aggregate report shape mismatch');
```

Add source assertions that `crontab/1min.sh` invokes `php scripts/cluster_refresh.php` exactly once and no other periodic script invokes it.

- [ ] **Step 2: Run focused and Cluster tests to verify failures**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: freshness/status/cron contract failures.

- [ ] **Step 3: Change cached station freshness to 90 seconds**

In `hub_cluster_station_is_fresh()`:

```php
if ($fetchedAt === false || $fetchedAt > $now || ($now - $fetchedAt) > 90) {
    return false;
}
```

Keep `hub_cluster_verified_status_snapshot_at()` at its current anti-replay bound. A freshly fetched child status still carries the child's current timestamp; only cached dashboard/router eligibility expands to 90 seconds.

- [ ] **Step 4: Add compact release and aggregate status**

Extend `hub_cluster_status_payload()`:

```php
$release = hub_release_node_report($db);
$childrenCount = (int)$db->query('SELECT COUNT(*) FROM cluster_stations')->fetchColumn();
$publishedModes = hub_cluster_node_selected_published_modes($db);

return [
    'ok' => true,
    'snapshot_at' => $now,
    'gpu' => $gpu,
    'active_gpu_leases' => (int)$lease->fetchColumn(),
    'queued_jobs' => (int)$queued,
    'running_jobs' => (int)$running,
    'modes' => $publishedModes,
    'release' => $release['git'],
    'packs' => $release['packs'],
    'runners' => $release['runners'],
    'health' => $release['health'],
    'cluster' => [
        'aggregate' => hub_cluster_router_enabled($db) && hub_cluster_node_enabled($db),
        'children_count' => $childrenCount,
        'published_mode_count' => count($publishedModes),
    ],
];
```

Update `hub_cluster_compact_status_snapshot()` to validate:

- build IDs as exactly 11 digits;
- commit as 7-40 lowercase hex characters or empty;
- `dirty` and `aggregate` as booleans;
- Pack IDs/versions and runner digests as bounded strings;
- child/published counts as non-negative integers;
- no nested URL, token, path, command output, or arbitrary key.

Extend `hub_cluster_station_dashboard_rows()` with validated `release`, `packs`, `runners`, `health`, `cluster`, `services`, `service_count`, and local compatibility booleans. Services come from the already compacted `manifest_json.services`; do not add a table.

- [ ] **Step 5: Invoke the existing refresh script once per minute**

In `crontab/1min.sh`, after host metrics and before worker ticks:

```bash
if ! php scripts/cluster_refresh.php; then
  echo "[3waAIHub] cluster station refresh failed."
fi
```

`scripts/cluster_refresh.php` exits successfully with a localized/compact `router_disabled` line when Router mode is off; it remains the only station refresh CLI. The existing cron log receives station key, fresh flag, and compact error.

Expose `cluster_refresh_configured`, `cluster_refresh_log_path`, and last refresh log time in `hub_collect_command_worker_status()`. Do not install a second cron file.

- [ ] **Step 6: Run syntax, shell, and focused tests**

Run:

```bash
php -l app/cluster_router.php
php -l scripts/cluster_refresh.php
bash -n crontab/1min.sh
bash -n scripts/install_command_worker_cron.sh
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: all checks pass. Do not execute the root cron installer during ordinary tests.

- [ ] **Step 7: Commit**

```bash
git add app/cluster_router.php app/environment_probe.php admin/environment.php scripts/cluster_refresh.php crontab/1min.sh scripts/install_command_worker_cron.sh scripts/bootstrap_self_check.sh tests
git commit -m "feat: report and refresh Cluster station health"
```

### Task 9: Build The Role-Aware Dashboard With Station Titles

**Files:**
- Create: `app/admin_dashboard.php`
- Create: `assets/css/admin-dashboard.css`
- Create: `assets/js/admin-dashboard.js`
- Create: `tests/test_admin_dashboard.php`
- Modify: `admin/index.php`
- Modify: `admin/cluster.php`
- Modify: `tests/test_cluster_admin.php`
- Modify: `tests/test_phase_ui2.php`
- Modify: `tests/suites/admin-ui.php`

- [ ] **Step 1: Write failing dashboard role tests**

```php
hub_test('dashboard model separates child and router station behavior', function (): void {
    $db = hub_test_reset_db();
    hub_cluster_node_configure($db, true, []);
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '0');
    $child = hub_admin_dashboard_model($db, []);
    hub_test_assert($child['role'] === 'child', 'child role mismatch');
    hub_test_assert($child['station_tabs'] === [], 'child dashboard must not expose station tabs');

    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    $router = hub_admin_dashboard_model($db, []);
    hub_test_assert($router['role'] === 'aggregate', 'both enabled roles must render aggregate');
    hub_test_assert($router['aggregate'] === true, 'aggregate flag mismatch');
});

hub_test('router dashboard tabs use station display names and query keys', function (): void {
    $db = hub_test_reset_db();
    hub_set_storage_setting($db, 'AIHUB_CLUSTER_ROUTER_ENABLED', '1');
    hub_test_cluster_router_station($db, ['station_key' => 'station_1080', 'display_name' => '1080 影像站']);
    $model = hub_admin_dashboard_model($db, ['station' => 'station_1080']);
    hub_test_assert($model['active_station_key'] === 'station_1080', 'station query selection mismatch');
    hub_test_assert($model['station_tabs'][0]['label'] === '1080 影像站', 'station title mismatch');
});
```

- [ ] **Step 2: Run the focused suite to verify helper absence**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
```

Expected: missing `hub_admin_dashboard_model`.

- [ ] **Step 3: Implement the dashboard view model**

At the top of `tests/test_admin_dashboard.php`, add:

```php
require_once HUB_ROOT . '/app/admin_dashboard.php';
```

Create `app/admin_dashboard.php` with:

```php
function hub_admin_dashboard_role(PDO $db): string
{
    $router = hub_cluster_router_enabled($db);
    $node = hub_cluster_node_enabled($db);
    if ($router && $node) {
        return 'aggregate';
    }
    if ($router) {
        return 'router';
    }
    if ($node) {
        return 'child';
    }
    return 'standalone';
}
```

`hub_admin_dashboard_model(PDO $db, array $query)` must return:

```php
[
    'role' => 'router',
    'aggregate' => false,
    'children_count' => 0,
    'published_mode_count' => 0,
    'station_tabs' => [],
    'active_station_key' => '',
    'active_station' => null,
    'local' => [
        'site_title' => hub_site_title($db),
        'metrics_snapshot' => hub_latest_host_metric_snapshot($db),
        'services' => [],
        'queued_jobs' => 0,
        'running_jobs' => 0,
        'active_gpu_leases' => 0,
        'health' => [],
    ],
    'summary' => [],
];
```

For Router/Aggregate, station tabs come only from `hub_cluster_station_dashboard_rows()` and use `station_key` plus `display_name`. A self station previously created by `hub_cluster_register_self_station()` is already one of these rows: include it once and do not synthesize a second local tab. Normalize the requested `station` against those keys; use the first station when absent/invalid. For Child/Standalone, return no station tabs and populate only local host/GPU/queue/Pack/service/health data.

Do not write Cluster configuration from this helper.

- [ ] **Step 4: Recompose `admin/index.php` from the accepted dashboard**

After bootstrap in `admin/index.php`, load `app/admin_dashboard.php` with `require_once`. Adapt supplied `dashboard.css` to `assets/css/admin-dashboard.css`. Preserve its header rhythm, metric hierarchy, station tab treatment, data tables, chart sizing, and mobile collapse. Remove the old page `<style>` and CDN ECharts script.

Router/Aggregate markup:

```php
<nav class="station-tabs" aria-label="<?= hub_h(__('站台')) ?>">
<?php foreach ($model['station_tabs'] as $station): ?>
    <a class="station-tab<?= $model['active_station_key'] === $station['station_key'] ? ' is-active' : '' ?>"
       href="index.php?station=<?= rawurlencode($station['station_key']) ?>">
        <?= hub_h($station['label']) ?>
    </a>
<?php endforeach; ?>
</nav>
```

The station panel shows title, freshness, last refresh, health, VRAM, GPU leases, queue/running, published modes, active routes, release compatibility, Pack compatibility, supplied services, and aggregate child count where reported.

Child/Standalone markup has no station `<nav>` and shows local host, GPU, queue, Pack, services, and health. Empty states cover:

- Router disabled/no station configuration;
- Router enabled/no paired station;
- selected station stale;
- selected station offline/error;
- local metrics not collected;
- no installed services.

- [ ] **Step 5: Render charts with local Chart.js**

Load:

```html
<script src="../assets/js/vendor/chart.umd.js"></script>
<script id="dashboard-data" type="application/json">SERVER_JSON</script>
<script src="../assets/js/admin-dashboard.js"></script>
```

`admin-dashboard.js` parses once, creates charts only for present canvases, uses the accepted data palette, tabular numeric labels, `maintainAspectRatio: false`, and listens for container resize. It must not fetch fake data, rename stations, or write persistent browser state.

- [ ] **Step 6: Add aggregate display to Cluster management**

When both roles are enabled, show:

```php
<span class="badge badge--info"><?= hub_h(__('聚合站台')) ?></span>
<span><?= number_format(count($stationRows)) ?> <?= hub_h(__('個子節點')) ?></span>
<span><?= number_format(count($selectedModes)) ?> <?= hub_h(__('個已發佈 Mode')) ?></span>
```

This is display-only. Do not add upstream pairing, another token, route path, hop limit, forwarding, or schema.

- [ ] **Step 7: Run tests and commit**

Run:

```bash
chmod 755 app/admin_dashboard.php tests/test_admin_dashboard.php
php -l app/admin_dashboard.php
php -l admin/index.php
php -l admin/cluster.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git add app/admin_dashboard.php admin/index.php admin/cluster.php assets/css/admin-dashboard.css assets/js/admin-dashboard.js tests
git commit -m "feat: add role-aware Cluster dashboard"
```

Expected: tests report `failures=0`; no rendered generated tab label contains `GPU 1`, `GPU 2`, or `GPU 3`.

### Task 10: Complete I18n, Documentation, And Visual Acceptance

**Files:**
- Modify: `i18n/seed.json`
- Modify: `README.md`
- Modify: `docs/cluster-router.md`
- Modify: all changed PHP pages containing new visible text
- Modify: visual CSS/JS files as inspection finds drift

- [ ] **Step 1: Add an i18n coverage test**

Extend `tests/test_i18n.php` to scan the canonical pages for raw visible labels introduced by this redesign and assert that they appear through `__()`, `hub_i18n_seeded()`, or an escaped translated value. Explicitly cover navigation, workspaces, categories, record tabs, role labels, release labels, stale/offline states, branding actions, service states, notices, and legacy notices.

Also assert technical identifiers remain unchanged:

```php
foreach (['pack_id', 'mode', 'runtime_level', 'target_level', 'endpoint', 'execution_type', 'service_key'] as $technical) {
    hub_test_assert(str_contains($marketSource, $technical), 'technical contract label changed: ' . $technical);
}
```

- [ ] **Step 2: Import seed and run focused integration tests**

Run:

```bash
php scripts/init_db.php
php -r 'require "app/bootstrap.php"; echo hub_i18n_import_seed(hub_db()) . PHP_EOL;'
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: database initialization succeeds; both suites end with `failures=0`.

- [ ] **Step 3: Update operator-facing documentation**

Update the README top banner to:

```markdown
Current: `20260729001` (`2026.07.29.001`) / 8/7 Admin Market + Cluster Dashboard Preview.
```

Document:

- canonical Market and hidden legacy URLs;
- five-tab Record Center;
- role-aware Dashboard;
- site logo upload restrictions;
- local-only UI assets;
- Router one-minute refresh and 90-second freshness;
- 3wa push authority and execution-node fetch/tag policy;
- System Environment update view;
- no browser-side Git deployment;
- future aggregate routing explicitly excluded.

Update `docs/cluster-router.md` with status report fields and the display-only aggregate reservation.

- [ ] **Step 4: Render the accepted references for comparison**

Serve the accepted files read-only:

```bash
python3 -m http.server 8911 --directory /tmp/3waaihub-system-web-20260729/system_web
```

Capture:

- `http://127.0.0.1:8911/dashboard.html` at `1440x900` and `390x844`;
- `http://127.0.0.1:8911/packs.html` at `1440x900` and `390x844`;
- `http://127.0.0.1:8911/index.html` at `1440x900` and `390x844`.

Store temporary reference screenshots under `/tmp/3waaihub-ui-qa/reference/`.

- [ ] **Step 5: Exercise the real application in Browser/IAB**

Use `https://3wa.tw/3waAIHub/` because `/var/www/html/3waAIHub` resolves to this checkout. Verify the authenticated core workflow:

1. login and language switch;
2. open/close desktop submenus and mobile drawer;
3. Market `view=market&category=vision`;
4. Market `view=services`, enqueue health refresh, watch polling update;
5. Record Center `tab=runs`, `tab=api`, `tab=jobs`, `tab=service`, `tab=system`;
6. Router station switch using `?station=<station_key>`;
7. child-node Dashboard with no station switcher;
8. System Settings logo validation, preview, and restore;
9. System Environment release compatibility.

Confirm direct legacy URLs still return `200` with the Legacy debug notice.

- [ ] **Step 6: Capture and inspect desktop/mobile implementation screenshots**

Capture the same `1440x900` and `390x844` viewports for login, Dashboard, Market catalog, Market services, Record Center, Branding settings, and System Environment under `/tmp/3waaihub-ui-qa/rendered/`.

Use `view_image` on each accepted reference and its latest implementation screenshot in the same QA pass. Record and repair mismatches across at least:

1. shell/header/navigation geometry;
2. exact palette and surface temperature;
3. typography family, size, weight, and line height;
4. Market tab/card/detail density;
5. dashboard station tabs, chart dimensions, and data hierarchy;
6. icon weight/alignment and interaction states;
7. mobile drawer, tab wrapping, tables, text clipping, and overflow.

Re-capture after every CSS correction. Completion requires no clipped primary content, browser-default control typography, accidental wrapping, unreadable contrast, remote asset request, or unexplained drift from Guo-Yang's template.

- [ ] **Step 7: Run URL, asset, syntax, permission, and diff checks**

Run:

```bash
chmod 755 \
  app/admin_market.php \
  app/admin_records.php \
  app/branding.php \
  app/release.php \
  app/admin_dashboard.php \
  branding_asset.php \
  scripts/check_release_update.php \
  tests/suites/admin-ui.php \
  tests/test_admin_shell.php \
  tests/test_admin_market.php \
  tests/test_admin_records.php \
  tests/test_branding.php \
  tests/test_release_status.php \
  tests/test_admin_dashboard.php
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
rg -n 'fonts\\.googleapis\\.com|fonts\\.gstatic\\.com|cdn\\.jsdelivr\\.net|unpkg\\.com' admin login.php assets
rg -n 'href="(?:packs|models|services|api_usage|runtime_runs)\\.php' admin/_layout.php README.md docs
git diff --check
```

Expected:

- all PHP files report no syntax errors;
- remote asset scan returns no match;
- legacy navigation scan returns no normal shell/documentation links;
- `git diff --check` returns no output;
- every new PHP file is mode `755`.

- [ ] **Step 8: Run the full test suite once at the integration gate**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php
```

Expected: the full summary ends with `failures=0`. This is the only ordinary implementation-time full-suite run; run it again only for the final `20260807001` freeze commit or after a broad regression fix.

- [ ] **Step 9: Review final diff and commit**

Run:

```bash
git status --short
git diff --stat
git diff -- admin app assets scripts crontab tests README.md docs
```

Confirm `docs/superpowers/specs/2026-07-29-web-screenshot-field-intel-draft.md` remains untracked and untouched.

Commit:

```bash
git add admin app assets scripts crontab tests i18n README.md docs/cluster-router.md docs/operations/release-freeze.md branding_asset.php
git commit -m "feat: complete 8/7 admin experience"
```

## Final Acceptance Checklist

- [ ] Admin shell has exactly the nine agreed top-level destinations.
- [ ] Guo-Yang's design is the visual baseline; all deviations have a functional reason.
- [ ] No admin runtime page requests remote CSS, JavaScript, images, or fonts.
- [ ] Market categories are exclusive and sum to All.
- [ ] Every current Pack has concise Chinese purpose copy with manifest fallback.
- [ ] Service operations still enqueue existing command jobs and preserve confirmations.
- [ ] Legacy diagnostic URLs work but are absent from normal navigation and docs.
- [ ] Record Center has five real data-backed tabs in the agreed order.
- [ ] Router tabs use `cluster_stations.display_name`; child dashboards have no multi-station tabs.
- [ ] Aggregate station is display-only and introduces no hierarchical forwarding state.
- [ ] Router refresh runs once per minute through `scripts/cluster_refresh.php`; freshness is 90 seconds.
- [ ] Branding accepts validated PNG/WebP/JPEG, rejects SVG, and never trusts a user path.
- [ ] Release ID is `20260729001`, UI shows `2026.07.29.001`, and web requests cannot deploy.
- [ ] Station release/Pack/runner/health mismatches are visible.
- [ ] Market, Record Center, and Dashboard station state are shareable in query strings.
- [ ] Desktop/mobile reference comparison passes after direct `view_image` inspection.
- [ ] Focused, control-plane, and final full suites report zero failures.
- [ ] Every new PHP file is mode `755`.
