# Edge TTS Playground Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `admin/playground.php?mode=edge_tts` submit valid Edge TTS jobs and show three runnable test presets plus matching SDK snippets.

**Architecture:** Extend the existing Playground profile map with one URL-encoded POST kind, then use the Edge TTS Pack's fixed catalogue helper and contract values to render a dedicated form. The current task-response renderer remains unchanged; it already rewrites and presents asynchronous task links.

**Tech Stack:** PHP 8, cURL, native HTML form controls, existing Edge TTS Pack catalogue, custom PHP test runner.

---

## File structure

- Modify: `admin/playground.php` — Edge TTS profile, payload, URL-encoded execution, presets, dedicated form, and curl/PHP/JS examples.
- Modify: `tests/test_phase_dx4_client_starter.php` — regression coverage for the Edge TTS Playground contract and generated examples; this file is already part of the `control-plane` suite.

### Task 1: Lock down the missing Playground contract

**Files:**
- Modify: `tests/test_phase_dx4_client_starter.php` after `PhaseDX-4 public docs and playground examples use current host URLs`
- Modify: `admin/playground.php:9-26`, `admin/playground.php:74-200`, `admin/playground.php:341-450`, `admin/playground.php:480-900`, `admin/playground.php:950-970`, `admin/playground.php:1070-1210`

- [ ] **Step 1: Write the failing regression test**

Append this test after the existing Playground current-host test. It deliberately checks the dedicated profile, all six Pack fields, all three preset labels, and form-encoded example mechanics that do not exist yet.

```php
hub_test('Edge TTS Playground exposes form controls and URL-encoded examples', function (): void {
    $page = (string)file_get_contents(HUB_ROOT . '/admin/playground.php');
    foreach ([
        "'edge_tts' => ['label' => 'Edge TTS', 'method' => 'POST', 'kind' => 'form']",
        "'include_subtitles' => !empty(\$_POST['include_subtitles'])",
        '台灣女聲旁白',
        '慢速技術解說',
        '快速粵語公告',
        'name="voice"',
        'name="rate"',
        'name="volume"',
        'name="pitch"',
        'name="include_subtitles"',
        'application/x-www-form-urlencoded',
    ] as $needle) {
        hub_test_assert(str_contains($page, $needle), 'Edge TTS Playground missing ' . $needle);
    }

    $previousPost = $_POST;
    try {
        $_POST = [
            'text' => 'Edge TTS Playground request',
            'voice' => 'zh-HK-WanLungNeural',
            'rate' => '+25%',
            'volume' => '-25%',
            'pitch' => '+25Hz',
            'include_subtitles' => '1',
        ];
        hub_test_assert(hub_playground_request_payload('edge_tts') === [
            'text' => 'Edge TTS Playground request',
            'voice' => 'zh-HK-WanLungNeural',
            'rate' => '+25%',
            'volume' => '-25%',
            'pitch' => '+25Hz',
            'include_subtitles' => true,
        ], 'Edge TTS Playground must preserve all form fields with a boolean subtitle flag');

        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'nature.focusit.tw';
        $_SERVER['SCRIPT_NAME'] = '/3waAIHub/admin/playground.php';
        $examples = hub_playground_examples('edge_tts');
        foreach (['curl', 'php', 'js'] as $kind) {
            hub_test_assert(
                str_contains($examples[$kind], 'https://nature.focusit.tw/3waAIHub/api.php?mode=edge_tts'),
                'Edge TTS Playground ' . $kind . ' example must use the current host'
            );
            hub_test_assert(str_contains($examples[$kind], 'include_subtitles'), 'Edge TTS Playground ' . $kind . ' example must include subtitle control');
        }
        hub_test_assert(str_contains($examples['curl'], '--data-urlencode'), 'Edge TTS curl example must use form encoding');
        hub_test_assert(str_contains($examples['php'], 'http_build_query'), 'Edge TTS PHP example must use form encoding');
        hub_test_assert(str_contains($examples['js'], 'URLSearchParams'), 'Edge TTS JS example must use form encoding');
    } finally {
        $_POST = $previousPost;
    }
});
```

- [ ] **Step 2: Run the control-plane suite and confirm RED**

Run:

```bash
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: non-zero exit with `Edge TTS Playground missing 'edge_tts' => ...` (or the next missing Edge TTS contract assertion).

- [ ] **Step 3: Add the minimal Edge TTS helpers and profile**

In `admin/playground.php`, add the profile beside `tts`:

```php
'edge_tts' => ['label' => 'Edge TTS', 'method' => 'POST', 'kind' => 'form'],
```

Add a preset helper before `hub_playground_request_payload()`; do not duplicate the voice catalogue, use the existing `hub_edge_tts_voice_catalog()` only when rendering the select.

```php
function hub_playground_edge_tts_presets(): array
{
    return [
        'taiwan_narration' => [
            'label' => '台灣女聲旁白',
            'payload' => ['text' => '這是一段使用台灣女聲的 API 測試旁白。', 'voice' => 'zh-TW-HsiaoChenNeural', 'rate' => '+0%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => false],
        ],
        'slow_technical' => [
            'label' => '慢速技術解說',
            'payload' => ['text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。', 'voice' => 'zh-TW-YunJheNeural', 'rate' => '-25%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => true],
        ],
        'fast_cantonese' => [
            'label' => '快速粵語公告',
            'payload' => ['text' => '呢個係一段粵語 API 測試公告。', 'voice' => 'zh-HK-WanLungNeural', 'rate' => '+25%', 'volume' => '+0%', 'pitch' => '+0Hz', 'include_subtitles' => false],
        ],
    ];
}
```

Add the `edge_tts` branch at the start of `hub_playground_request_payload()`:

```php
if ($mode === 'edge_tts') {
    return [
        'text' => trim((string)($_POST['text'] ?? '這是一段使用台灣女聲的 API 測試旁白。')),
        'voice' => trim((string)($_POST['voice'] ?? 'zh-TW-HsiaoChenNeural')),
        'rate' => trim((string)($_POST['rate'] ?? '+0%')),
        'volume' => trim((string)($_POST['volume'] ?? '+0%')),
        'pitch' => trim((string)($_POST['pitch'] ?? '+0Hz')),
        'include_subtitles' => !empty($_POST['include_subtitles']),
    ];
}
```

- [ ] **Step 4: Send Edge TTS using its declared content type**

Extend the existing `POST` branch in `hub_playground_execute()` before its multipart fallback. This keeps the existing JSON and upload flows unchanged.

```php
if ($profile['kind'] === 'form') {
    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    $options[CURLOPT_HTTPHEADER] = $headers;
    $options[CURLOPT_POSTFIELDS] = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
} elseif ($profile['kind'] === 'json') {
    // existing JSON branch
} else {
    // existing upload branch
}
```

Keep the existing `hub_playground_public_task_links()` call, because it already maps the asynchronous submission URLs to the local API endpoint.

- [ ] **Step 5: Render presets and all six native controls**

After `$examples = hub_playground_examples($selectedMode);`, calculate the selected preset and form values:

```php
$edgeTtsPresets = hub_playground_edge_tts_presets();
$edgeTtsPresetKey = (string)($_GET['edge_tts_preset'] ?? 'taiwan_narration');
$edgeTtsPreset = $edgeTtsPresets[$edgeTtsPresetKey] ?? $edgeTtsPresets['taiwan_narration'];
$edgeTtsValues = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    ? hub_playground_request_payload('edge_tts')
    : $edgeTtsPreset['payload'];
```

Add this `elseif ($selectedMode === 'edge_tts')` request-form branch before the ordinary `tts` branch. It uses native controls and normal links only:

```php
<?php elseif ($selectedMode === 'edge_tts'): ?>
    <p class="muted"><?= hub_h(__('選擇範例後仍可調整所有參數。')) ?></p>
    <div class="hub-actions">
        <?php foreach ($edgeTtsPresets as $presetKey => $preset): ?>
            <a class="button" href="playground.php?mode=edge_tts&edge_tts_preset=<?= urlencode((string)$presetKey) ?>"><?= hub_h((string)$preset['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <label><?= hub_h(__('文字')) ?> text</label>
    <textarea name="text" rows="5" maxlength="4096" required><?= hub_h((string)$edgeTtsValues['text']) ?></textarea>
    <label><?= hub_h(__('聲線')) ?> voice</label>
    <select name="voice">
        <?php foreach (hub_edge_tts_voice_catalog() as $voice): ?>
            <?php $voiceId = (string)$voice['id']; ?>
            <option value="<?= hub_h($voiceId) ?>" <?= $edgeTtsValues['voice'] === $voiceId ? 'selected' : '' ?>><?= hub_h((string)$voice['display_name']) ?> / <?= hub_h($voiceId) ?></option>
        <?php endforeach; ?>
    </select>
    <?php foreach (['rate' => ['-50%', '-25%', '+0%', '+25%', '+50%'], 'volume' => ['-50%', '-25%', '+0%', '+25%', '+50%'], 'pitch' => ['-50Hz', '-25Hz', '+0Hz', '+25Hz', '+50Hz']] as $field => $choices): ?>
        <label><?= hub_h($field) ?></label>
        <select name="<?= hub_h($field) ?>">
            <?php foreach ($choices as $choice): ?>
                <option value="<?= hub_h($choice) ?>" <?= $edgeTtsValues[$field] === $choice ? 'selected' : '' ?>><?= hub_h($choice) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endforeach; ?>
    <label><input name="include_subtitles" type="checkbox" value="1" <?= $edgeTtsValues['include_subtitles'] ? 'checked' : '' ?>> include_subtitles</label>
    <p class="muted"><?= hub_h(__('開啟字幕會產生 VTT、SRT 與 speech timeline artifacts。')) ?></p>
<?php elseif ($selectedMode === 'tts'): ?>
```

- [ ] **Step 6: Add form-encoded curl, PHP, and JavaScript examples**

Add an `if ($mode === 'edge_tts')` branch in `hub_playground_examples()` before the generic multipart fallback. Use this same payload in every sample:

```php
$payload = [
    'text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
    'voice' => 'zh-TW-YunJheNeural',
    'rate' => '-25%',
    'volume' => '+0%',
    'pitch' => '+0Hz',
    'include_subtitles' => 'true',
];
```

Build and return the samples with these exact form-encoding mechanics:

```php
$curl = "$curlExecutable -X POST \"$url\" $curlContinuation\n  -H \"Authorization: Bearer <TOKEN>\" $curlContinuation\n  -H \"Content-Type: application/x-www-form-urlencoded\" $curlContinuation\n  --data-urlencode 'text=RC 閥是用來控制二行程引擎排氣時機的重要機構。' $curlContinuation\n  --data-urlencode 'voice=zh-TW-YunJheNeural' $curlContinuation\n  --data-urlencode 'rate=-25%' $curlContinuation\n  --data-urlencode 'volume=+0%' $curlContinuation\n  --data-urlencode 'pitch=+0Hz' $curlContinuation\n  --data-urlencode 'include_subtitles=true'";
$php = <<<PHP
\$payload = [
    'text' => 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
    'voice' => 'zh-TW-YunJheNeural',
    'rate' => '-25%',
    'volume' => '+0%',
    'pitch' => '+0Hz',
    'include_subtitles' => 'true',
];
\$ch = curl_init($phpUrl);
curl_setopt_array(\$ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>', 'Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS => http_build_query(\$payload, '', '&', PHP_QUERY_RFC3986),
]);
echo curl_exec(\$ch);
PHP;
$js = <<<JS
const payload = {
  text: 'RC 閥是用來控制二行程引擎排氣時機的重要機構。',
  voice: 'zh-TW-YunJheNeural', rate: '-25%', volume: '+0%', pitch: '+0Hz', include_subtitles: 'true'
};
const res = await fetch($jsUrl, {
  method: 'POST',
  headers: { Authorization: 'Bearer <TOKEN>', 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams(payload)
});
console.log(await res.json());
JS;
return ['curl' => $curl, 'php' => $php, 'js' => $js];
```

- [ ] **Step 7: Run focused validation and confirm GREEN**

Run:

```bash
php -l admin/playground.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
```

Expected: PHP reports no syntax errors; the suite summary reports `failures=0`.

- [ ] **Step 8: Inspect the diff and commit the completed feature**

Run:

```bash
git diff --check
git diff -- admin/playground.php tests/test_phase_dx4_client_starter.php
git status -sb
```

Stage only the two feature files and commit:

```bash
git add admin/playground.php tests/test_phase_dx4_client_starter.php
git commit -m "feat: add edge tts playground examples"
```

Do not stage the unrelated untracked Web Screenshot draft or Cluster plan.

## Plan review

- Spec coverage: profile/root-cause fix, all six exact Pack fields, three no-JavaScript presets, form-encoded SDK samples, and asynchronous result reuse are all covered by Task 1.
- Scope control: no Pack, runner, gateway, database, dependency, or JavaScript changes are planned.
- Type consistency: the request payload uses `include_subtitles` as a boolean for the internal gateway call; SDK examples intentionally encode it as `true`, which the Pack request normalizer accepts.
- Placeholder scan: complete; all code, values, paths, test commands, and commit scope are specified.
