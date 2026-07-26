# Web Protection Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show web-server protection status and safe remediation guidance in the existing System Environment page.

**Architecture:** The queued environment probe only inspects local process state and committed protection files. `admin/environment.php` performs fixed same-origin browser `HEAD` checks after rendering the persisted snapshot. Nginx remains advisory: the UI renders a copyable snippet but never writes or reloads configuration.

**Tech Stack:** PHP 8, SQLite environment snapshots, existing command worker, vanilla browser JavaScript, Apache `.htaccess`, IIS `web.config`, Nginx configuration text.

---

### Task 1: Lock the Probe Contract With Tests

**Files:**
- Modify: `tests/test_environment_probe.php`
- Modify: `tests/test_phase_auth1a2_login_lockout.php`

- [ ] **Step 1: Write the failing environment-probe test**

Append this test to `tests/test_environment_probe.php`:

```php
hub_test('web protection probe reports local server state without network access', function (): void {
    $calls = [];
    $runner = static function (array $command, int $timeoutSeconds) use (&$calls): array {
        $calls[] = $command;
        $active = $command === ['systemctl', 'is-active', 'apache2'];

        return ['exit_code' => $active ? 0 : 3, 'stdout' => $active ? "active\n" : "inactive\n", 'stderr' => '', 'output' => ''];
    };
    $htaccess = (string)file_get_contents(HUB_ROOT . '/.htaccess');
    $status = hub_collect_web_protection_status('linux', $runner, $htaccess, '');

    hub_test_assert(($status['apache_active'] ?? false) === true, 'Apache must report active');
    hub_test_assert(($status['nginx_active'] ?? true) === false, 'Nginx must report inactive');
    hub_test_assert(($status['apache_rules_present'] ?? false) === true, 'required Apache rules must pass');
    hub_test_assert($calls === [['systemctl', 'is-active', 'apache2'], ['systemctl', 'is-active', 'nginx']], 'probe must only inspect local services');

    $windowsCalls = 0;
    $iis = hub_collect_web_protection_status('windows', static function () use (&$windowsCalls): array {
        $windowsCalls++;
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => '', 'output' => ''];
    }, '', (string)file_get_contents(HUB_ROOT . '/web.config'));
    hub_test_assert(($iis['apache_active'] ?? null) === hub_not_applicable_status(), 'Windows Apache status must be N/A');
    hub_test_assert(($iis['nginx_active'] ?? null) === hub_not_applicable_status(), 'Windows Nginx status must be N/A');
    hub_test_assert(($iis['iis_rules_present'] ?? false) === true, 'required IIS rules must pass');
    hub_test_assert($windowsCalls === 0, 'Windows probe must not invoke Linux service commands');
});
```

- [ ] **Step 2: Run the full suite to verify the test fails**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_web_protection_red.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_0f0c558077b5a3a83cd682d3b1c0ea6d AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the new test fails because `hub_collect_web_protection_status()` is undefined.

- [ ] **Step 3: Write the failing page contract test**

Append this test to `tests/test_phase_auth1a2_login_lockout.php`:

```php
hub_test('System Environment page performs fixed same-origin web protection HEAD checks', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/environment.php');

    foreach (['data/cluster.key', 'docs/cluster-router.md', 'scripts/init_db.php', "method: 'HEAD'", "credentials: 'same-origin'", "cache: 'no-store'"] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'web protection live probe missing ' . $needle);
    }
    hub_test_assert(!str_contains($page, 'response.text(') && !str_contains($page, 'response.json('), 'web protection probe must not read HTTP response bodies');
});
```

- [ ] **Step 4: Run the full suite to verify the page test fails**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_web_protection_page_red.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_959de7667fd38e4f0d1d9f33b87701f2 AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: the new page contract test fails because the paths and `HEAD` probe are absent.

### Task 2: Add Local Web-Protection Snapshot and Guidance

**Files:**
- Modify: `app/environment_probe.php:42-102`
- Modify: `admin/environment.php:320-416`
- Test: `tests/test_environment_probe.php`

- [ ] **Step 1: Add the local-only probe and Nginx guidance helpers**

Insert these helpers before `hub_collect_env_snapshot()`:

```php
function hub_web_protection_nginx_snippet(): string
{
    return <<<'NGINX'
# Replace /3waAIHub with this Hub's URL prefix before adding to the matching server block.
autoindex off;
location ~* ^/3waAIHub/(?:app|crontab|data|docs|i18n|packs|scripts|templates|tests|tools|\.git|\.github|vendor|node_modules)(?:/|$) { return 404; }
location ~* ^/3waAIHub/(?:.*\/)?\.[^/]+$ { return 404; }
location ~* ^/3waAIHub/(?:README\.md|history\.md|install\.sh|composer\.(?:json|lock)|package(?:-lock)?\.json)$ { return 404; }
location ~* ^/3waAIHub/(?:.*\.(?:sqlite(?:-.+)?|db|env|key|log|ps1|bat|cmd|sh|sql|ini|ya?ml|xml|bak)|.*~)$ { return 404; }
NGINX;
}

function hub_collect_web_protection_status(?string $platform = null, ?callable $runner = null, ?string $htaccess = null, ?string $webConfig = null): array
{
    $platform = hub_platform_id($platform);
    if ($platform === 'windows') {
        $webConfig ??= (string)(@file_get_contents(HUB_ROOT . '/web.config') ?: '');
        $iisRulesPresent = true;
        foreach (['<directoryBrowse enabled="false" />', '<add segment="data" />', '<add segment="app" />', '<add fileExtension=".sqlite" allowed="false" />'] as $needle) {
            if (!str_contains($webConfig, $needle)) {
                $iisRulesPresent = false;
                break;
            }
        }

        return [
            'apache_active' => hub_not_applicable_status(),
            'nginx_active' => hub_not_applicable_status(),
            'iis_rules_present' => $iisRulesPresent,
        ];
    }

    $runner ??= 'hub_run_command';
    $htaccess ??= (string)(@file_get_contents(HUB_ROOT . '/.htaccess') ?: '');
    $apache = $runner(['systemctl', 'is-active', 'apache2'], 5);
    $nginx = $runner(['systemctl', 'is-active', 'nginx'], 5);
    $apacheRulesPresent = true;
    foreach (['Options -Indexes', 'RewriteRule ^(?:app|crontab|data|docs|i18n|packs|scripts|templates|tests|tools)', 'RewriteRule ^(?:\\.git|\\.github|vendor|node_modules)', '\\.key$', '\\.sqlite', '\\.sh$'] as $needle) {
        if (!str_contains($htaccess, $needle)) {
            $apacheRulesPresent = false;
            break;
        }
    }

    return [
        'apache_active' => ($apache['exit_code'] ?? 1) === 0,
        'nginx_active' => ($nginx['exit_code'] ?? 1) === 0,
        'apache_rules_present' => $apacheRulesPresent,
    ];
}
```

- [ ] **Step 2: Attach the probe to persisted environment snapshots**

Add this entry beside the existing `command_worker` section in the array returned by `hub_collect_env_snapshot()`:

```php
'web_protection' => hub_collect_web_protection_status($platform),
```

- [ ] **Step 3: Add bounded suggestions to `hub_env_fix_suggestions()`**

Add this block immediately before the final `return $suggestions;` in `admin/environment.php`:

```php
$protection = is_array($data['web_protection'] ?? null) ? $data['web_protection'] : [];
if (!$isWindows && ($protection['nginx_active'] ?? false) === true) {
    $suggestions[] = [
        'title' => '設定 Nginx 檔案防護',
        'body' => 'Nginx 不讀 .htaccess。請把下列規則放進此站的 server 區塊，驗證 nginx -t 後再 reload。',
        'commands' => hub_web_protection_nginx_snippet() . "\n\nsudo nginx -t\nsudo systemctl reload nginx",
    ];
} elseif (!$isWindows && ($protection['apache_active'] ?? false) === true && ($protection['apache_rules_present'] ?? false) !== true) {
    $suggestions[] = [
        'title' => '修正 Apache 檔案防護',
        'body' => 'Apache 正在提供 Hub，但 .htaccess 缺少必要規則。確認 AllowOverride Options FileInfo AuthConfig Limit 後重新套用專案的 .htaccess。',
        'commands' => "sudo apache2ctl -t\nsudo systemctl reload apache2",
    ];
} elseif ($isWindows && ($protection['iis_rules_present'] ?? false) !== true) {
    $suggestions[] = [
        'title' => '修正 IIS 檔案防護',
        'body' => 'web.config 缺少 Hub 的 runtime data 或 directory browse 防護規則。請從主線還原 web.config 後重新套用 IIS site。',
        'commands' => ".\\scripts\\windows\\configure-iis-fastcgi.ps1",
    ];
}
```

- [ ] **Step 4: Run the full suite to verify the probe and suggestions pass**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_web_protection_green.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_eb530a9f60d4ed168873d01c753b0ccb AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: `failures=0`; the Linux fixture confirms only local `systemctl` command arrays run, while the Windows fixture confirms IIS file checks without Linux service commands. Neither helper accepts a URL input.

- [ ] **Step 5: Commit the snapshot implementation**

```bash
git add app/environment_probe.php admin/environment.php tests/test_environment_probe.php
git commit -m "feat: diagnose web protection configuration"
```

### Task 3: Render the Persisted and Live Checks in System Environment

**Files:**
- Modify: `admin/environment.php:32-123`
- Modify: `admin/environment.php:415-470`
- Test: `tests/test_phase_auth1a2_login_lockout.php`

- [ ] **Step 1: Register localized labels for the new snapshot section**

Add these entries to the two existing label maps:

```php
'web_protection' => 'Web 檔案防護',
```

```php
'apache_active' => 'Apache 執行中',
'nginx_active' => 'Nginx 執行中',
'apache_rules_present' => 'Apache 規則完整',
'iis_rules_present' => 'IIS 規則完整',
```

- [ ] **Step 2: Render a fixed-path live probe after the System Environment header panel**

Insert this section immediately after the form panel and before `<?php if (!$snapshot): ?>`. `new URL('../', window.location.href)` intentionally resolves the Hub root from `/admin/environment.php` without a host-specific base URL setting:

```php
<section class="panel" id="webProtectionLive">
    <h2>即時 Web 檔案防護</h2>
    <p class="muted" id="webProtectionLiveStatus">正在檢查同源保護規則...</p>
</section>
<script>
(() => {
    const root = new URL('../', window.location.href);
    const targets = ['data/cluster.key', 'docs/cluster-router.md', 'scripts/init_db.php'];
    const status = document.getElementById('webProtectionLiveStatus');
    Promise.all(targets.map(async (path) => {
        try {
            const response = await fetch(new URL(path, root), {
                method: 'HEAD',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
            });
            return `${path}: ${[403, 404].includes(response.status) ? 'PASS' : `FAIL (${response.status})`}`;
        } catch {
            return `${path}: FAIL (network)`;
        }
    })).then((rows) => {
        status.textContent = rows.join(' | ');
    });
})();
</script>
```

- [ ] **Step 3: Run the page contract test through the full suite**

Run:

```bash
env AIHUB_TEST_DB=/tmp/3waaihub_web_protection_ui.sqlite AIHUB_TEST_DATA_DIR=/tmp/3waaihub_test_data_1456fa69e0e1f3cbe0f1d833bf5e504d AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=full
```

Expected: `failures=0`; the test confirms the three fixed paths, `HEAD`, same-origin credentials, no-store cache, and no response-body reader.

- [ ] **Step 4: Verify the live host response and Apache syntax**

Run:

```bash
sudo apache2ctl -t
for path in data/cluster.key docs/cluster-router.md scripts/init_db.php admin/; do
  curl -k -sS -o /dev/null -w "$path %{http_code}\n" --max-time 10 "https://3wa.tw/3waAIHub/$path"
done
```

Expected: `Syntax OK`; the first three paths return `403` or `404`; `admin/` remains an application response such as `200` or `302`.

- [ ] **Step 5: Commit the UI implementation and push the branch**

```bash
git add admin/environment.php tests/test_phase_auth1a2_login_lockout.php
git commit -m "feat: show web protection diagnostics"
git push origin main
```
