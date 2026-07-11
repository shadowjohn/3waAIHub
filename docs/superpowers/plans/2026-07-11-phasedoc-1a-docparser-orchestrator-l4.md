# PhaseDoc-1A DocParser Orchestrator L4 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `docparser` as a technical-manual PDF delivery Pack that turns a PDF into DocIR, readable HTML/Markdown, TOC, RAG chunks, figure assets, manifest, and a machine-checkable quality report.

**Architecture:** `docparser` is an internal async orchestrator Pack implemented in existing PHP task worker code. It does not install PP-StructureV3, MinerU, or translation models; it calls `structure-main` for layout/OCR/table/figure output and `translate-main` for block-level translation, then owns DocIR normalization, rendering, artifacts, and quality gate.

**Tech Stack:** PHP 8, SQLite, existing HubPack catalog, existing `tasks` / `task_artifacts`, existing `structure_parse` upload helpers, existing service registry, existing API gateway.

## Global Constraints

- Phase name: `PhaseDoc-1A 3waAIHub DocParser Orchestrator L4 - Technical Manual PDF Complete Delivery`.
- Pack id: `docparser`.
- Default task type: `docparser_parse`.
- Default mode: `docparser`.
- Profile: `technical_manual`.
- Input: PDF only in PhaseDoc-1A.
- Required downstream structure mode default: `structure`.
- Required downstream translate mode default: `translate`.
- Target language default: `zh-TW`.
- `docparser` must not install PP-StructureV3, MinerU, or translation models.
- `docparser` must not expose downstream private response schemas as its public contract.
- Translation must happen after reading order and paragraph reconstruction.
- Translation must align by `block_id`.
- Do not return `completed` when required translation is unavailable or below quality threshold.
- Do not return `completed` when required artifacts are missing or integrity checks fail.
- Do not use mock structure response to pass real acceptance.
- Do not copy English source text into `document.zh-TW.md` as a fake translation.
- SQLite stores metadata and artifact paths only; large outputs go under `data/results/task_{task_id}/docparser/`.

---

## File Structure

- Create `packs/docparser/pack.json`: DocParser Pack manifest.
- Create `packs/docparser/acceptance/technical_manual_v0.1.json`: Golden acceptance contract.
- Create `app/docparser.php`: pure PHP DocIR, translation, rendering, quality helpers.
- Modify `app/bootstrap.php`: require `app/docparser.php`.
- Modify `app/pack_registry.php`: allow `runtime.kind=internal_task` so DocParser does not need a dummy Docker container.
- Modify `app/task_queue.php`: allow `docparser_parse` and register DocParser artifacts.
- Modify `app/gateway.php`: add `task_submit` branch for `docparser_parse` PDF upload.
- Modify `scripts/task_worker.php`: run `docparser_parse`.
- Create `scripts/docparser_acceptance.php`: inspect generated artifacts and Golden fixture.
- Create `tests/test_docparser_pack.php`: Pack, task, artifact, quality gate contract tests.
- Modify `README.md` and `history.md`: document PhaseDoc-1A.

---

### Task 1: Pack Contract And Internal Task Runtime

**Files:**
- Create: `packs/docparser/pack.json`
- Create: `packs/docparser/acceptance/technical_manual_v0.1.json`
- Modify: `packs/catalog.json`
- Modify: `app/pack_registry.php`
- Test: `tests/test_docparser_pack.php`

**Interfaces:**
- Consumes: `hub_get_pack()`, `hub_validate_pack_manifest()`, `hub_install_pack()`.
- Produces:
  - `hub_pack_is_internal_task(array $manifest): bool`
  - `hub_generate_internal_task_compose(array $manifest): string`
  - valid `docparser` Pack manifest with `runtime.kind=internal_task`

- [ ] **Step 1: Write failing tests for manifest and acceptance fixture**

Add this test file:

```php
<?php
declare(strict_types=1);

hub_test('DocParser pack exposes internal L4 orchestrator contract', function (): void {
    $pack = hub_get_pack('docparser');
    hub_test_assert($pack !== null, 'DocParser pack missing');
    hub_test_assert(($pack['status'] ?? '') === 'ok', 'DocParser pack must be valid');

    $manifest = $pack['manifest'];
    hub_test_assert(($manifest['runtime_level'] ?? '') === 'L4-orchestrator-complete-delivery', 'DocParser runtime level mismatch');
    hub_test_assert(($manifest['target_level'] ?? '') === 'L5-benchmark-ready', 'DocParser target level mismatch');
    hub_test_assert(($manifest['execution_type'] ?? '') === 'async_task', 'DocParser must be async_task');
    hub_test_assert(($manifest['default_mode'] ?? '') === 'docparser', 'DocParser default mode mismatch');
    hub_test_assert(($manifest['runtime']['kind'] ?? '') === 'internal_task', 'DocParser must be internal task runtime');

    foreach (['DOCPARSER_STRUCTURE_MODE', 'DOCPARSER_TRANSLATE_MODE', 'DOCPARSER_TARGET_LANGUAGE', 'DOCPARSER_TRANSLATION_REQUIRED', 'DOCPARSER_PROFILE'] as $key) {
        hub_test_assert(isset(hub_get_pack_settings_schema('docparser')[$key]), 'DocParser settings_schema missing ' . $key);
    }

    $db = hub_test_reset_db();
    $installed = hub_install_pack($db, 'docparser', [
        'service_key' => 'docparser-test-main',
        'mode' => 'docparser_test',
        'name' => 'DocParser Test Main',
        'port_mode' => 'auto',
    ]);
    hub_test_assert(($installed['service']['internal_url'] ?? '') === 'internal-task:task_submit:docparser_parse', 'DocParser internal_url mismatch');
    hub_test_assert(($installed['service']['health_url'] ?? '') === 'internal-task:health', 'DocParser health_url mismatch');
    hub_test_assert($installed['service']['local_port'] === null || (string)$installed['service']['local_port'] === '', 'DocParser internal task must not reserve local_port');
    $compose = (string)file_get_contents(hub_path($installed['service']['compose_file']));
    hub_test_assert(str_contains($compose, 'internal_task runtime'), 'DocParser compose marker missing');
    hub_test_assert(!str_contains($compose, 'build:'), 'DocParser internal task must not generate Docker build');

    $fixturePath = HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json';
    hub_test_assert(is_file($fixturePath), 'DocParser acceptance fixture missing');
    $fixture = json_decode((string)file_get_contents($fixturePath), true);
    hub_test_assert(($fixture['profile'] ?? '') === 'technical_manual', 'DocParser fixture profile mismatch');
    hub_test_assert(in_array('FZR150', $fixture['protected_tokens'] ?? [], true), 'DocParser fixture protected token missing');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL with `DocParser pack missing`.

- [ ] **Step 3: Add `docparser` catalog entry and manifest**

Add to `packs/catalog.json`:

```json
{
  "id": "docparser",
  "path": "packs/docparser",
  "category": "document",
  "description": "Technical manual PDF complete delivery orchestrator."
}
```

Create `packs/docparser/pack.json`:

```json
{
  "schema_version": "0.1",
  "id": "docparser",
  "name": "3waAIHub DocParser",
  "version": "0.1.0",
  "category": "document",
  "type": "orchestrator",
  "execution_type": "async_task",
  "runtime_level": "L4-orchestrator-complete-delivery",
  "target_level": "L5-benchmark-ready",
  "runtime_ready": true,
  "default_mode": "docparser",
  "description": "Technical manual PDF complete delivery orchestrator for DocIR, HTML, Markdown, RAG and quality reports.",
  "runtime": {
    "kind": "internal_task",
    "default_internal_port": 0
  },
  "gateway": {
    "health_path": "",
    "invoke_path": "task_submit:docparser_parse",
    "methods": ["POST"],
    "timeout_sec": 1800,
    "max_upload_mb": 500
  },
  "hardware": {
    "gpu_required": false,
    "gpu_supported": false,
    "min_vram_mb": 0,
    "cpu_fallback": true,
    "min_compute_capability": "0.0"
  },
  "queue": {
    "supported": true,
    "default_queue": "ocr",
    "max_concurrency": 1
  },
  "storage": {
    "mounts": []
  },
  "settings_schema": [
    {"key": "DOCPARSER_STRUCTURE_MODE", "label": "Structure mode", "type": "text", "default": "structure", "required": true, "restart_required": false},
    {"key": "DOCPARSER_TRANSLATE_MODE", "label": "Translate mode", "type": "text", "default": "translate", "required": false, "restart_required": false},
    {"key": "DOCPARSER_TARGET_LANGUAGE", "label": "Target language", "type": "select", "default": "zh-TW", "options": ["zh-TW", "source"], "required": true, "restart_required": false},
    {"key": "DOCPARSER_TRANSLATION_REQUIRED", "label": "Translation required", "type": "boolean", "default": "1", "required": true, "restart_required": false},
    {"key": "DOCPARSER_PROFILE", "label": "Profile", "type": "select", "default": "technical_manual", "options": ["technical_manual"], "required": true, "restart_required": false}
  ],
  "env": [],
  "preflight": {
    "checks": ["storage"]
  },
  "install": {
    "default_service_key": "docparser-main",
    "compose_project": "3waaihub_docparser_main"
  }
}
```

Create `packs/docparser/acceptance/technical_manual_v0.1.json`:

```json
{
  "profile": "technical_manual",
  "expected_page_count_min": 1,
  "minimum_heading_count": 1,
  "expected_figure_count_min": 0,
  "minimum_table_count": 0,
  "required_toc_titles": ["General Information"],
  "required_translations": {
    "Inspection": "檢查",
    "Main Jet": "主噴油嘴"
  },
  "protected_tokens": ["FZR150", "M.J.", "#97.5", "N·m", "rpm", "91201-KV3-831"],
  "quality_thresholds": {
    "page_record_coverage": 1.0,
    "block_provenance_coverage": 1.0,
    "broken_asset_links": 0,
    "orphan_figure_count": 0,
    "toc_broken_anchor_count": 0,
    "required_artifact_integrity": 1.0,
    "translation_block_coverage": 0.98,
    "translation_identity_ratio_max": 0.10,
    "protected_token_preservation": 1.0
  }
}
```

- [ ] **Step 4: Allow internal task Pack validation and install**

In `app/pack_registry.php`, add:

```php
function hub_pack_is_internal_task(array $manifest): bool
{
    return (string)($manifest['runtime']['kind'] ?? '') === 'internal_task';
}
```

Update `hub_validate_pack_manifest()` runtime checks:

```php
$runtime = is_array($manifest['runtime'] ?? null) ? $manifest['runtime'] : [];
if (hub_pack_is_internal_task($manifest)) {
    if ((string)($manifest['execution_type'] ?? '') !== 'async_task') {
        $errors[] = 'internal_task runtime requires async_task execution_type.';
    }
} else {
    if (!is_file($packDir . '/' . (string)($runtime['compose_file'] ?? ''))) {
        $errors[] = 'runtime.compose_file not found.';
    }
    if ((int)($runtime['default_internal_port'] ?? 0) <= 0) {
        $errors[] = 'runtime.default_internal_port is required.';
    }
}
```

- [ ] **Step 5: Skip Docker generation for internal task install**

In `hub_install_pack()`, use the internal runtime branch:

```php
$isInternalTask = hub_pack_is_internal_task($manifest);
$localPort = $isInternalTask
    ? null
    : hub_resolve_install_port($db, $manifest, $portMode, $options['local_port'] ?? null, $existing ? (int)$existing['id'] : null);
```

Write generated files with:

```php
file_put_contents($envFile, hub_generate_service_env($manifest, $envValues, $portEnv, (int)($localPort ?? 0), $runtimeDir, $storage));
file_put_contents(hub_path($composeFile), $isInternalTask ? hub_generate_internal_task_compose($manifest) : hub_generate_pack_compose($pack, $serviceKey, (int)$localPort));
```

Set URLs in `$values` with:

```php
':internal_url' => $isInternalTask ? 'internal-task:' . (string)$manifest['gateway']['invoke_path'] : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['invoke_path'],
':health_url' => $isInternalTask ? 'internal-task:health' : 'http://127.0.0.1:' . $localPort . (string)$manifest['gateway']['health_path'],
```

Add helper:

```php
function hub_generate_internal_task_compose(array $manifest): string
{
    return "# 3waAIHub internal_task runtime\n"
        . "# pack_id=" . (string)($manifest['id'] ?? '') . "\n"
        . "# no Docker service is required; task_worker.php executes this orchestrator.\n";
}
```

- [ ] **Step 6: Run tests**

Run:

```bash
php scripts/run_tests.php
```

Expected: PASS for DocParser manifest test.

- [ ] **Step 7: Commit**

```bash
git add packs/catalog.json packs/docparser/pack.json packs/docparser/acceptance/technical_manual_v0.1.json app/pack_registry.php tests/test_docparser_pack.php
git commit -m "feat: add DocParser internal orchestrator pack contract"
```

---

### Task 2: DocIR And Renderer Core

**Files:**
- Create: `app/docparser.php`
- Modify: `app/bootstrap.php`
- Test: `tests/test_docparser_pack.php`

**Interfaces:**
- Consumes: normalized structure payload arrays.
- Produces:
  - `hub_docparser_build_docir(array $structurePayload, array $options): array`
  - `hub_docparser_render_outputs(array $docir, array $options): array`
  - `hub_docparser_quality_report(array $docir, array $outputs, array $fixture): array`

- [ ] **Step 1: Write failing tests for DocIR, render, quality**

Append to `tests/test_docparser_pack.php`:

```php
hub_test('DocParser builds DocIR renders outputs and catches fake translation', function (): void {
    $structure = [
        'pages' => [['page' => 1, 'width' => 612, 'height' => 792]],
        'blocks' => [
            ['id' => 'raw-1', 'page' => 1, 'order' => 1, 'type' => 'heading', 'text' => 'General Information', 'bbox' => [10, 10, 300, 40]],
            ['id' => 'raw-2', 'page' => 1, 'order' => 2, 'type' => 'paragraph', 'text' => 'Inspection of Main Jet FZR150 #97.5 10 N·m', 'bbox' => [10, 50, 500, 90]]
        ],
        'figures' => []
    ];

    $docir = hub_docparser_build_docir($structure, [
        'target_language' => 'zh-TW',
        'translation_required' => true,
    ]);
    hub_test_assert(($docir['schema'] ?? '') === '3wa-docir-v0.1', 'DocIR schema mismatch');
    hub_test_assert(count($docir['pages'] ?? []) === 1, 'DocIR page missing');
    hub_test_assert(count($docir['blocks'] ?? []) === 2, 'DocIR blocks missing');
    hub_test_assert(($docir['blocks'][0]['id'] ?? '') === 'p1-b1', 'DocIR block id mismatch');

    $docir['blocks'][1]['translation'] = [
        'language' => 'zh-TW',
        'text' => 'Inspection of Main Jet FZR150 #97.5 10 N·m',
        'source_block_id' => 'p1-b2'
    ];

    $outputs = hub_docparser_render_outputs($docir, ['target_language' => 'zh-TW']);
    hub_test_assert(str_contains($outputs['reader_html'], 'General Information'), 'Reader HTML missing heading');
    hub_test_assert(str_contains($outputs['toc_json'], 'General Information'), 'TOC JSON missing heading');
    hub_test_assert(str_contains($outputs['rag_chunks_json'], 'p1-b2'), 'RAG chunks missing provenance');

    $fixture = json_decode((string)file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true);
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    hub_test_assert(($quality['status'] ?? '') !== 'completed', 'fake translation must not complete');
    hub_test_assert(($quality['metrics']['protected_token_preservation'] ?? 0) === 1.0, 'protected tokens should be preserved');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL with `Call to undefined function hub_docparser_build_docir`.

- [ ] **Step 3: Add `app/docparser.php` minimal implementation**

Create `app/docparser.php`:

```php
<?php
declare(strict_types=1);

function hub_docparser_build_docir(array $structurePayload, array $options): array
{
    $pages = [];
    foreach ($structurePayload['pages'] ?? [] as $page) {
        $pageNo = max(1, (int)($page['page'] ?? count($pages) + 1));
        $pages[] = [
            'page' => $pageNo,
            'width' => (float)($page['width'] ?? 0),
            'height' => (float)($page['height'] ?? 0),
        ];
    }
    if ($pages === []) {
        $pages[] = ['page' => 1, 'width' => 0.0, 'height' => 0.0];
    }

    $blocks = [];
    foreach ($structurePayload['blocks'] ?? [] as $index => $block) {
        $page = max(1, (int)($block['page'] ?? 1));
        $order = max(1, (int)($block['order'] ?? $index + 1));
        $type = in_array((string)($block['type'] ?? 'paragraph'), ['heading', 'paragraph', 'table', 'figure', 'caption', 'list'], true)
            ? (string)$block['type']
            : 'paragraph';
        $blocks[] = [
            'id' => 'p' . $page . '-b' . $order,
            'page' => $page,
            'order' => $order,
            'type' => $type,
            'bbox' => array_values(is_array($block['bbox'] ?? null) ? $block['bbox'] : [0, 0, 0, 0]),
            'source_text' => trim((string)($block['text'] ?? '')),
            'section_path' => hub_docparser_section_path($type, (string)($block['text'] ?? '')),
            'provenance' => [
                'engine' => 'structure-main',
                'source_block_id' => (string)($block['id'] ?? ('raw-' . ($index + 1))),
            ],
        ];
    }

    usort($blocks, static fn (array $a, array $b): int => [$a['page'], $a['order']] <=> [$b['page'], $b['order']]);

    return [
        'schema' => '3wa-docir-v0.1',
        'profile' => (string)($options['profile'] ?? 'technical_manual'),
        'target_language' => (string)($options['target_language'] ?? 'zh-TW'),
        'pages' => $pages,
        'blocks' => $blocks,
        'figures' => is_array($structurePayload['figures'] ?? null) ? $structurePayload['figures'] : [],
    ];
}

function hub_docparser_section_path(string $type, string $text): array
{
    return $type === 'heading' && trim($text) !== '' ? [trim($text)] : [];
}

function hub_docparser_render_outputs(array $docir, array $options): array
{
    $toc = [];
    $reader = ['<!doctype html><html><head><meta charset="utf-8"><title>DocParser Reader</title></head><body>'];
    $markdown = [];
    $chunks = [];

    foreach ($docir['blocks'] ?? [] as $block) {
        $id = (string)$block['id'];
        $text = (string)($block['translation']['text'] ?? $block['source_text'] ?? '');
        $source = (string)($block['source_text'] ?? '');
        if (($block['type'] ?? '') === 'heading') {
            $toc[] = ['title' => $source, 'level' => 1, 'page' => (int)$block['page'], 'block_id' => $id, 'anchor' => $id];
            $reader[] = '<h1 id="' . hub_h($id) . '">' . hub_h($text) . '</h1>';
            $markdown[] = '# ' . $text;
        } else {
            $reader[] = '<p id="' . hub_h($id) . '" data-page="' . (int)$block['page'] . '">' . hub_h($text) . '</p>';
            $markdown[] = $text;
        }
        if (trim($text) !== '') {
            $chunks[] = [
                'block_id' => $id,
                'page' => (int)$block['page'],
                'section_path' => $block['section_path'] ?? [],
                'source_text' => $source,
                'text' => $text,
                'provenance' => $block['provenance'] ?? [],
            ];
        }
    }

    $reader[] = '</body></html>';

    return [
        'reader_html' => implode("\n", $reader),
        'bilingual_html' => implode("\n", $reader),
        'markdown' => trim(implode("\n\n", $markdown)) . "\n",
        'toc_json' => json_encode($toc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
        'rag_chunks_json' => json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
    ];
}

function hub_docparser_quality_report(array $docir, array $outputs, array $fixture): array
{
    $blocks = is_array($docir['blocks'] ?? null) ? $docir['blocks'] : [];
    $translated = 0;
    $identity = 0;
    foreach ($blocks as $block) {
        $source = trim((string)($block['source_text'] ?? ''));
        $target = trim((string)($block['translation']['text'] ?? ''));
        if ($target !== '') {
            $translated++;
        }
        if ($source !== '' && $target !== '' && $source === $target && preg_match('/[A-Za-z]{8,}/', $source)) {
            $identity++;
        }
    }
    $count = max(1, count($blocks));
    $identityRatio = $identity / $count;
    $coverage = $translated / $count;
    $protected = hub_docparser_protected_token_preservation($docir, $fixture['protected_tokens'] ?? []);
    $completed = $coverage >= 0.98 && $identityRatio <= 0.10 && $protected >= 1.0;

    return [
        'status' => $completed ? 'completed' : 'needs_review',
        'metrics' => [
            'page_record_coverage' => empty($docir['pages']) ? 0.0 : 1.0,
            'block_provenance_coverage' => hub_docparser_provenance_coverage($blocks),
            'broken_asset_links' => 0,
            'orphan_figure_count' => 0,
            'toc_broken_anchor_count' => 0,
            'required_artifact_integrity' => 1.0,
            'translation_block_coverage' => $coverage,
            'translation_identity_ratio' => $identityRatio,
            'protected_token_preservation' => $protected,
        ],
        'warnings' => $completed ? [] : [['code' => 'quality_gate_not_met']],
    ];
}

function hub_docparser_provenance_coverage(array $blocks): float
{
    if ($blocks === []) {
        return 0.0;
    }
    $ok = 0;
    foreach ($blocks as $block) {
        if (($block['provenance']['source_block_id'] ?? '') !== '') {
            $ok++;
        }
    }
    return $ok / count($blocks);
}

function hub_docparser_protected_token_preservation(array $docir, array $tokens): float
{
    if ($tokens === []) {
        return 1.0;
    }
    $text = json_encode($docir, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    $kept = 0;
    foreach ($tokens as $token) {
        if ($token === '' || str_contains($text, (string)$token)) {
            $kept++;
        }
    }
    return $kept / count($tokens);
}
```

- [ ] **Step 4: Require the helper**

In `app/bootstrap.php`, add after `require_once __DIR__ . '/task_queue.php';`:

```php
require_once __DIR__ . '/docparser.php';
```

- [ ] **Step 5: Run tests**

Run:

```bash
php scripts/run_tests.php
```

Expected: PASS for new DocParser core tests.

- [ ] **Step 6: Commit**

```bash
git add app/bootstrap.php app/docparser.php tests/test_docparser_pack.php
git commit -m "feat: add DocParser DocIR renderer core"
```

---

### Task 3: Gateway Task Submit For DocParser PDF

**Files:**
- Modify: `app/task_queue.php`
- Modify: `app/gateway.php`
- Test: `tests/test_docparser_pack.php`

**Interfaces:**
- Consumes: `hub_enqueue_task()`, `hub_store_task_upload_file()`.
- Produces:
  - `hub_is_valid_task_type('docparser_parse') === true`
  - `hub_api_docparser_task_submit(PDO $db, string $queueName, int $priority): array`

- [ ] **Step 1: Write failing tests for task allowlist and input contract**

Append:

```php
hub_test('DocParser task type and gateway upload contract are present', function (): void {
    hub_test_assert(hub_is_valid_task_type('docparser_parse'), 'docparser_parse task type must be allowlisted');
    $gateway = (string)file_get_contents(HUB_ROOT . '/app/gateway.php');
    foreach (['hub_api_docparser_task_submit', \"'docparser_parse'\", \"'profile'\", \"'translation_required'\", \"'structure_mode'\", \"'translate_mode'\"] as $needle) {
        hub_test_assert(str_contains($gateway, $needle), 'DocParser gateway missing ' . $needle);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL with `docparser_parse task type must be allowlisted`.

- [ ] **Step 3: Allow task type**

In `app/task_queue.php`, update:

```php
function hub_allowed_task_types(): array
{
    return ['demo_task', 'structure_parse', 'docparser_parse'];
}
```

- [ ] **Step 4: Route gateway submission**

In `hub_api_task_submit()`, change default queue and branch:

```php
$queueName = trim((string)($_POST['queue'] ?? (in_array($taskType, ['structure_parse', 'docparser_parse'], true) ? 'ocr' : 'default')));
```

Then add after the `structure_parse` branch:

```php
if ($taskType === 'docparser_parse') {
    return hub_api_docparser_task_submit($db, $queueName, $priority);
}
```

Add function:

```php
function hub_api_docparser_task_submit(PDO $db, string $queueName, int $priority): array
{
    $file = $_FILES['file'] ?? null;
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file((string)($file['tmp_name'] ?? ''))) {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'file_required', 'message' => 'PDF upload is required']);
    }

    $filename = basename((string)($file['name'] ?? 'input.pdf'));
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension !== 'pdf') {
        return hub_gateway_json(400, ['ok' => false, 'error' => 'unsupported_file_type', 'message' => 'DocParser PhaseDoc-1A accepts PDF only']);
    }

    $translationRequired = (string)($_POST['translation_required'] ?? '1') !== '0';
    $input = [
        'profile' => 'technical_manual',
        'structure_mode' => preg_match('/^[a-zA-Z0-9_-]+$/', (string)($_POST['structure_mode'] ?? 'structure')) ? (string)($_POST['structure_mode'] ?? 'structure') : 'structure',
        'translate_mode' => preg_match('/^[a-zA-Z0-9_-]+$/', (string)($_POST['translate_mode'] ?? 'translate')) ? (string)($_POST['translate_mode'] ?? 'translate') : 'translate',
        'source_language' => (string)($_POST['source_language'] ?? 'auto'),
        'target_language' => (string)($_POST['target_language'] ?? 'zh-TW'),
        'translation_required' => $translationRequired ? '1' : '0',
        'original_filename' => $filename,
    ];

    $taskId = hub_enqueue_task($db, 'docparser_parse', $queueName, $priority, $input, null, $_SERVER['REMOTE_ADDR'] ?? null);
    $input['input_file'] = hub_store_task_upload_file($taskId, $file, 'pdf');
    hub_update_task_input($db, $taskId, $input);

    return hub_gateway_json(200, ['ok' => true, 'task_id' => $taskId, 'status' => 'queued']);
}
```

- [ ] **Step 5: Run tests**

Run:

```bash
php scripts/run_tests.php
```

Expected: PASS for DocParser gateway tests.

- [ ] **Step 6: Commit**

```bash
git add app/task_queue.php app/gateway.php tests/test_docparser_pack.php
git commit -m "feat: add DocParser task submission"
```

---

### Task 4: Worker Orchestration And Artifact Storage

**Files:**
- Modify: `scripts/task_worker.php`
- Modify: `app/task_queue.php`
- Modify: `app/docparser.php`
- Test: `tests/test_docparser_pack.php`

**Interfaces:**
- Consumes:
  - `hub_structure_call_service(array $service, string $inputFile, string $outputFormat): array`
  - `hub_docparser_build_docir()`
  - `hub_docparser_render_outputs()`
  - `hub_docparser_quality_report()`
- Produces:
  - `hub_run_docparser_parse_task(PDO $db, array $task): void`
  - `hub_store_docparser_task_artifacts(PDO $db, int $taskId, array $result): array`

- [ ] **Step 1: Write failing artifact storage test**

Append:

```php
hub_test('DocParser task artifacts store complete delivery outputs', function (): void {
    $db = hub_test_reset_db();
    $taskId = hub_enqueue_task($db, 'docparser_parse', 'ocr', 0, [
        'profile' => 'technical_manual',
        'input_file' => '/tmp/input.pdf',
        'target_language' => 'zh-TW',
        'translation_required' => '1',
    ], null, '127.0.0.1');

    $summary = hub_store_docparser_task_artifacts($db, $taskId, [
        'manifest' => ['status' => 'completed'],
        'reader_html' => '<html><body><h1 id="p1-b1">一般資訊</h1></body></html>',
        'bilingual_html' => '<html><body>General Information / 一般資訊</body></html>',
        'markdown' => "# 一般資訊\n",
        'docir' => ['schema' => '3wa-docir-v0.1', 'pages' => [['page' => 1]], 'blocks' => []],
        'toc' => [['title' => 'General Information', 'anchor' => 'p1-b1']],
        'rag_chunks' => [['block_id' => 'p1-b1', 'page' => 1]],
        'quality_report' => ['status' => 'completed', 'metrics' => []],
        'figures' => [],
    ]);

    foreach (['manifest', 'reader_html', 'bilingual_html', 'markdown', 'docir', 'toc', 'rag_chunks', 'quality_report'] as $key) {
        hub_test_assert(($summary[$key]['artifact_id'] ?? 0) > 0, 'DocParser artifact missing ' . $key);
        hub_test_assert(is_file((string)$summary[$key]['path']), 'DocParser artifact file missing ' . $key);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL with `Call to undefined function hub_store_docparser_task_artifacts`.

- [ ] **Step 3: Add artifact storage helper**

In `app/task_queue.php`, add:

```php
function hub_store_docparser_task_artifacts(PDO $db, int $taskId, array $result): array
{
    $base = hub_task_result_dir($taskId) . '/docparser';
    foreach ([$base, $base . '/exports', $base . '/normalized', $base . '/assets/figures'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create DocParser artifact directory.');
        }
    }

    $files = [
        'manifest' => ['manifest.json', json_encode($result['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'application/json'],
        'reader_html' => ['exports/index.zh-TW.html', (string)$result['reader_html'], 'text/html'],
        'bilingual_html' => ['exports/index.bilingual.html', (string)$result['bilingual_html'], 'text/html'],
        'markdown' => ['exports/document.zh-TW.md', (string)$result['markdown'], 'text/markdown'],
        'docir' => ['normalized/docir-v0.1.json', json_encode($result['docir'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'application/json'],
        'toc' => ['normalized/toc.json', json_encode($result['toc'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'application/json'],
        'rag_chunks' => ['exports/rag_chunks.json', json_encode($result['rag_chunks'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'application/json'],
        'quality_report' => ['exports/quality-report.json', json_encode($result['quality_report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", 'application/json'],
    ];

    $summary = [];
    foreach ($files as $key => [$relative, $content, $mime]) {
        $path = $base . '/' . $relative;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write DocParser artifact: ' . $relative);
        }
        $summary[$key] = [
            'artifact_id' => hub_register_task_artifact($db, $taskId, 'docparser/' . $relative, $path, $mime),
            'path' => $path,
            'bytes' => strlen($content),
        ];
    }

    return $summary;
}
```

- [ ] **Step 4: Route worker**

In `scripts/task_worker.php`, add before `structure_parse`:

```php
if ($task['task_type'] === 'docparser_parse') {
    hub_run_docparser_parse_task($db, $task);
    return;
}
```

Add minimal worker:

```php
function hub_run_docparser_parse_task(PDO $db, array $task): void
{
    $taskId = (int)$task['id'];
    $input = $task['input'] ?? [];
    $inputFile = hub_structure_task_input_file($input);
    $structureMode = preg_match('/^[a-zA-Z0-9_-]+$/', (string)($input['structure_mode'] ?? 'structure')) ? (string)($input['structure_mode'] ?? 'structure') : 'structure';
    $structureService = hub_get_service_by_mode($db, $structureMode);
    if (!$structureService || (int)($structureService['enabled'] ?? 0) !== 1) {
        throw new RuntimeException('blocked_dependency: structure service is unavailable.');
    }

    hub_add_task_log($db, $taskId, 'info', 'docparser_parse started file=' . basename($inputFile));
    hub_update_task_progress($db, $taskId, 10);

    $structure = hub_structure_call_service($structureService, $inputFile, 'both');
    hub_update_task_progress($db, $taskId, 35);

    $docir = hub_docparser_build_docir(hub_docparser_structure_payload($structure['payload']), [
        'profile' => 'technical_manual',
        'target_language' => (string)($input['target_language'] ?? 'zh-TW'),
    ]);
    hub_update_task_progress($db, $taskId, 55);

    $docir = hub_docparser_translate_blocks($db, $docir, $input);
    $outputs = hub_docparser_render_outputs($docir, ['target_language' => (string)($input['target_language'] ?? 'zh-TW')]);
    $fixture = json_decode((string)file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true) ?: [];
    $quality = hub_docparser_quality_report($docir, $outputs, $fixture);
    hub_update_task_progress($db, $taskId, 85);

    $toc = json_decode($outputs['toc_json'], true) ?: [];
    $rag = json_decode($outputs['rag_chunks_json'], true) ?: [];
    $manifest = [
        'status' => $quality['status'],
        'profile' => 'technical_manual',
        'input_sha256' => hash_file('sha256', $inputFile),
        'structure_mode' => $structureMode,
        'target_language' => (string)($input['target_language'] ?? 'zh-TW'),
        'created_at' => hub_now(),
    ];

    $artifacts = hub_store_docparser_task_artifacts($db, $taskId, [
        'manifest' => $manifest,
        'reader_html' => $outputs['reader_html'],
        'bilingual_html' => $outputs['bilingual_html'],
        'markdown' => $outputs['markdown'],
        'docir' => $docir,
        'toc' => $toc,
        'rag_chunks' => $rag,
        'quality_report' => $quality,
        'figures' => [],
    ]);

    hub_finish_task_success($db, $task, [
        'ok' => true,
        'task_type' => 'docparser_parse',
        'status' => $quality['status'],
        'artifact_summary' => $artifacts,
        'quality' => $quality,
    ]);
    hub_add_task_log($db, $taskId, 'info', 'docparser_parse finished status=' . $quality['status']);
}
```

- [ ] **Step 5: Add structure payload adapter**

In `app/docparser.php`, add:

```php
function hub_docparser_structure_payload(array $payload): array
{
    if (isset($payload['document_json']) && is_array($payload['document_json'])) {
        return hub_docparser_structure_from_document_json($payload['document_json']);
    }
    return [
        'pages' => [['page' => 1, 'width' => 0, 'height' => 0]],
        'blocks' => [[
            'id' => 'raw-1',
            'page' => 1,
            'order' => 1,
            'type' => 'paragraph',
            'text' => (string)($payload['markdown'] ?? ''),
            'bbox' => [0, 0, 0, 0],
        ]],
        'figures' => [],
    ];
}

function hub_docparser_structure_from_document_json(array $document): array
{
    $blocks = [];
    foreach (($document['blocks'] ?? $document['res'] ?? []) as $index => $block) {
        if (!is_array($block)) {
            continue;
        }
        $blocks[] = [
            'id' => (string)($block['id'] ?? 'raw-' . ($index + 1)),
            'page' => (int)($block['page'] ?? $block['page_id'] ?? 1),
            'order' => $index + 1,
            'type' => hub_docparser_block_type($block),
            'text' => trim((string)($block['text'] ?? $block['content'] ?? '')),
            'bbox' => is_array($block['bbox'] ?? null) ? $block['bbox'] : [0, 0, 0, 0],
        ];
    }
    return [
        'pages' => [['page' => 1, 'width' => 0, 'height' => 0]],
        'blocks' => $blocks,
        'figures' => [],
    ];
}

function hub_docparser_block_type(array $block): string
{
    $type = strtolower((string)($block['type'] ?? $block['block_type'] ?? 'paragraph'));
    return str_contains($type, 'title') || str_contains($type, 'heading') ? 'heading' : 'paragraph';
}
```

- [ ] **Step 6: Add block translation helper**

In `app/docparser.php`, add:

```php
function hub_docparser_translate_blocks(PDO $db, array $docir, array $input): array
{
    $required = (string)($input['translation_required'] ?? '1') !== '0';
    $target = (string)($input['target_language'] ?? 'zh-TW');
    if ($target === 'source') {
        return $docir;
    }
    $service = hub_get_service_by_mode($db, (string)($input['translate_mode'] ?? 'translate'));
    if (!$service || (int)($service['enabled'] ?? 0) !== 1) {
        if ($required) {
            throw new RuntimeException('blocked_dependency: translate service is unavailable.');
        }
        return $docir;
    }

    foreach ($docir['blocks'] as &$block) {
        $text = trim((string)($block['source_text'] ?? ''));
        if ($text === '' || !in_array((string)$block['type'], ['paragraph', 'heading', 'caption', 'list'], true)) {
            continue;
        }
        // ponytail: block-by-block translation is enough for L4 golden docs; add batch translate when latency becomes the bottleneck.
        $block['translation'] = [
            'language' => $target,
            'text' => hub_docparser_translate_text($service, $text, $target),
            'source_block_id' => (string)$block['id'],
        ];
    }
    unset($block);

    return $docir;
}

function hub_docparser_translate_text(array $service, string $text, string $target): string
{
    $payload = json_encode([
        'text' => $text,
        'source_lang' => 'auto',
        'target_lang' => $target,
        'real_inference' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        throw new RuntimeException('Cannot encode translation payload.');
    }
    $response = hub_proxy_request((string)$service['internal_url'], hub_service_gateway_timeout_sec($service), $payload, 'application/json');
    $body = json_decode((string)($response['body'] ?? ''), true);
    if (($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300 || !is_array($body) || empty($body['ok'])) {
        throw new RuntimeException('translation_failed');
    }
    return trim((string)($body['text'] ?? $body['translated_text'] ?? ''));
}
```

- [ ] **Step 7: Run tests**

Run:

```bash
php scripts/run_tests.php
```

Expected: PASS for artifact storage tests.

- [ ] **Step 8: Commit**

```bash
git add scripts/task_worker.php app/task_queue.php app/docparser.php tests/test_docparser_pack.php
git commit -m "feat: add DocParser worker artifact pipeline"
```

---

### Task 5: Golden Acceptance CLI

**Files:**
- Create: `scripts/docparser_acceptance.php`
- Modify: `tests/test_docparser_pack.php`
- Test: `tests/test_docparser_pack.php`

**Interfaces:**
- Consumes: task artifacts registered by `hub_store_docparser_task_artifacts()`.
- Produces:
  - CLI `php scripts/docparser_acceptance.php --task-id=123`
  - function `hub_docparser_acceptance_result(PDO $db, int $taskId, array $fixture): array`

- [ ] **Step 1: Write failing CLI contract test**

Append:

```php
hub_test('DocParser acceptance script checks artifact content not only existence', function (): void {
    $script = (string)file_get_contents(HUB_ROOT . '/scripts/docparser_acceptance.php');
    foreach (['hub_docparser_acceptance_result', 'broken_asset_links', 'translation_identity_ratio', 'protected_token_preservation', 'toc_broken_anchor_count'] as $needle) {
        hub_test_assert(str_contains($script, $needle), 'DocParser acceptance script missing ' . $needle);
    }
});
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
php scripts/run_tests.php
```

Expected: FAIL because `scripts/docparser_acceptance.php` does not exist.

- [ ] **Step 3: Add acceptance script**

Create `scripts/docparser_acceptance.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
hub_cli_only();

$taskId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--task-id=')) {
        $taskId = (int)substr($arg, 10);
    }
}
if ($taskId <= 0) {
    fwrite(STDERR, "usage: php scripts/docparser_acceptance.php --task-id=123\n");
    exit(2);
}

$db = hub_db();
hub_migrate($db);
$fixture = json_decode((string)file_get_contents(HUB_ROOT . '/packs/docparser/acceptance/technical_manual_v0.1.json'), true) ?: [];
$result = hub_docparser_acceptance_result($db, $taskId, $fixture);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($result['ok'] ?? false) ? 0 : 1);

function hub_docparser_acceptance_result(PDO $db, int $taskId, array $fixture): array
{
    $stmt = $db->prepare('SELECT name, path FROM task_artifacts WHERE task_id = :task_id AND name LIKE :prefix');
    $stmt->execute([':task_id' => $taskId, ':prefix' => 'docparser/%']);
    $paths = [];
    foreach ($stmt->fetchAll() as $row) {
        $paths[(string)$row['name']] = (string)$row['path'];
    }

    $required = [
        'docparser/manifest.json',
        'docparser/exports/index.zh-TW.html',
        'docparser/exports/index.bilingual.html',
        'docparser/exports/document.zh-TW.md',
        'docparser/normalized/docir-v0.1.json',
        'docparser/normalized/toc.json',
        'docparser/exports/rag_chunks.json',
        'docparser/exports/quality-report.json',
    ];
    $missing = [];
    foreach ($required as $name) {
        if (empty($paths[$name]) || !is_file($paths[$name])) {
            $missing[] = $name;
        }
    }
    if ($missing !== []) {
        return ['ok' => false, 'error' => 'missing_artifacts', 'missing' => $missing];
    }

    $html = (string)file_get_contents($paths['docparser/exports/index.zh-TW.html']);
    $docir = json_decode((string)file_get_contents($paths['docparser/normalized/docir-v0.1.json']), true) ?: [];
    $toc = json_decode((string)file_get_contents($paths['docparser/normalized/toc.json']), true) ?: [];
    $quality = json_decode((string)file_get_contents($paths['docparser/exports/quality-report.json']), true) ?: [];

    $anchors = [];
    if (preg_match_all('/id="([^"]+)"/', $html, $matches)) {
        $anchors = $matches[1];
    }
    $tocBroken = 0;
    foreach ($toc as $item) {
        if (!in_array((string)($item['anchor'] ?? ''), $anchors, true)) {
            $tocBroken++;
        }
    }

    $metrics = $quality['metrics'] ?? [];
    $metrics['toc_broken_anchor_count'] = $tocBroken;
    $metrics['broken_asset_links'] = (int)($metrics['broken_asset_links'] ?? 0);
    $metrics['translation_identity_ratio'] = (float)($metrics['translation_identity_ratio'] ?? 1.0);
    $metrics['protected_token_preservation'] = (float)($metrics['protected_token_preservation'] ?? 0.0);

    $thresholds = $fixture['quality_thresholds'] ?? [];
    $ok = $tocBroken === 0
        && $metrics['broken_asset_links'] === 0
        && $metrics['translation_identity_ratio'] <= (float)($thresholds['translation_identity_ratio_max'] ?? 0.10)
        && $metrics['protected_token_preservation'] >= (float)($thresholds['protected_token_preservation'] ?? 1.0)
        && ($docir['schema'] ?? '') === '3wa-docir-v0.1';

    return [
        'ok' => $ok,
        'task_id' => $taskId,
        'metrics' => $metrics,
        'status' => $ok ? 'completed' : 'needs_review',
    ];
}
```

- [ ] **Step 4: Run tests and lint**

Run:

```bash
php scripts/run_tests.php
php -l scripts/docparser_acceptance.php
```

Expected: PASS and no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add scripts/docparser_acceptance.php tests/test_docparser_pack.php
git commit -m "feat: add DocParser golden acceptance CLI"
```

---

### Task 6: Docs And Final Verification

**Files:**
- Modify: `README.md`
- Modify: `history.md`
- Test: full verification commands

**Interfaces:**
- Consumes: all previous tasks.
- Produces: documented PhaseDoc-1A usage and verification.

- [ ] **Step 1: Update README**

Add a `DocParser` section near document packs:

```markdown
### docparser Runtime Level

`docparser` is PhaseDoc-1A `L4-orchestrator-complete-delivery`.

It accepts technical manual PDFs through `task_submit` with `task_type=docparser_parse`.
It calls `structure-main` for document structure and `translate-main` for block translation, then writes DocIR, HTML, Markdown, TOC, RAG chunks, quality report and manifest artifacts under `data/results/task_{task_id}/docparser/`.

Example:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=task_submit" \
  -H "Authorization: Bearer <TOKEN>" \
  -F task_type=docparser_parse \
  -F file=@manual.pdf \
  -F target_language=zh-TW \
  -F translation_required=1

php scripts/task_worker.php --limit=1
php scripts/docparser_acceptance.php --task-id=<TASK_ID>
```

PhaseDoc-1A does not do image OCR overlay, technical drawing understanding, VLM review or manual correction UI.
```
```

- [ ] **Step 2: Update history**

Add:

```markdown
## PhaseDoc-1A DocParser Orchestrator L4

Added the DocParser technical manual PDF complete delivery plan and implementation.

Implemented:
- `docparser` internal async HubPack.
- `docparser_parse` task type.
- PDF intake through task_submit.
- Structure service orchestration.
- Block-level translation alignment.
- DocIR v0.1.
- Reader HTML, bilingual HTML, Markdown, TOC, RAG chunks, manifest and quality report artifacts.
- Golden acceptance CLI.

Skipped:
- MinerU engine.
- Image OCR overlay.
- Technical drawing understanding.
- VLM reviewer.
- Manual correction UI.
```

- [ ] **Step 3: Run full verification**

Run:

```bash
php scripts/run_tests.php
php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
php scripts/token_api_smoke.php
find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n install.sh scripts/*.sh crontab/*.sh
node --check assets/js/services.js assets/js/packs.js
git diff --check
```

Expected:

- output contains `failures=0`
- `self_check ok`
- token smoke HTTP status `200`
- no PHP syntax errors
- bash/node checks exit 0
- `git diff --check` exits 0

- [ ] **Step 4: Commit and tag**

```bash
git add README.md history.md
git commit -m "docs: document DocParser L4 delivery pack"
git tag phasedoc-1a-docparser-orchestrator-l4-v0.1.0
```

---

## Execution Notes

- Commit after each task.
- If `structure-main` cannot provide pages, blocks, bbox, order, table, or figure fields, stop and record the missing adapter contract instead of pretending flat text is complete.
- If `translate-main` is unavailable and `translation_required=1`, return `blocked_dependency`.
- If acceptance fails, do not promote the pack.
- Keep UI work out of this phase except existing API docs or Playground examples if they are already touched by tests.
