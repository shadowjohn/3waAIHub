# PhaseDoc-1A 3waAIHub DocParser Orchestrator L4

## Technical Manual PDF Complete Delivery

PhaseDoc-1A 建立 `docparser` HubPack，目標不是包一層 `structure-main`，而是交付一份人可讀、系統可用、問題可追溯的技術手冊 PDF 結果。

第一版只承諾 `technical_manual` profile：

- 輸入：PDF。
- 主要文件類型：技術手冊、維修手冊、操作手冊。
- 輸出：繁中 Reader、雙語 Reader、Markdown、DocIR、TOC、RAG chunks、quality report、figure assets、manifest。

不承諾所有文件格式，不承諾圖面理解，不承諾人工修正 UI。

## Architecture

DocParser 是 orchestrator，不內建重模型。

- `structure-main`：提供 OCR、layout、table、figure detection 與原始解析結果。
- `translate-main`：提供區塊翻譯。
- `docparser`：負責 DocIR、段落重建、TOC、翻譯對齊、HTML/Markdown/RAG render、Quality Gate。

`docparser` 不得重新安裝 PP-StructureV3、MinerU 或翻譯模型。下游 service 由 service settings 綁定，不寫死 service id。

建議 settings：

- `DOCPARSER_STRUCTURE_MODE=structure`
- `DOCPARSER_TRANSLATE_MODE=translate`
- `DOCPARSER_TARGET_LANGUAGE=zh-TW`
- `DOCPARSER_TRANSLATION_REQUIRED=1`
- `DOCPARSER_PROFILE=technical_manual`

## Pipeline

固定流程：

1. PDF intake。
2. 呼叫 `structure-main`。
3. Structure Adapter 正規化。
4. 產生 DocIR v0.1。
5. 閱讀順序與段落重建。
6. 標題、章節、TOC 建立。
7. 以 block_id 做區塊翻譯。
8. Render HTML、Markdown、RAG chunks。
9. Quality Gate。

禁止 OCR 每行直接翻譯後再串接。翻譯必須發生在段落重建後，且譯文必須回寫同一個 block id。

## Task Contract

新增 task type：

- `docparser_parse`

輸入：

```json
{
  "profile": "technical_manual",
  "input_file": "data/uploads/tasks/task_123/input.pdf",
  "source_language": "auto",
  "target_language": "zh-TW",
  "translation": {
    "required": true
  },
  "structure_mode": "structure",
  "translate_mode": "translate"
}
```

狀態：

- `completed`
- `completed_with_warnings`
- `needs_review`
- `blocked_dependency`
- `failed`

當 `translation.required=true` 且翻譯服務不可用或覆蓋率不足時，不得回 `completed`。

## Required Artifacts

每次成功或帶警告的結果都寫入：

`data/results/task_{task_id}/docparser/`

必要檔案：

- `manifest.json`
- `exports/index.zh-TW.html`
- `exports/index.bilingual.html`
- `exports/document.zh-TW.md`
- `normalized/docir-v0.1.json`
- `normalized/toc.json`
- `exports/rag_chunks.json`
- `exports/quality-report.json`
- `assets/figures/*`

SQLite 只登錄 artifact path 與摘要，不存大型內容。

缺少必要 artifact 或 integrity check 失敗時，不得回 `completed`。

## DocIR v0.1

DocIR 必須保留頁面、區塊、來源與產物關係。

最小 block：

```json
{
  "id": "p12-b8",
  "page": 12,
  "order": 8,
  "type": "paragraph",
  "bbox": [72, 144, 512, 198],
  "source_text": "Inspect the carburetor and replace the main jet if damaged.",
  "section_path": ["Carburetor", "Inspection"],
  "provenance": {
    "engine": "structure-main",
    "source_block_id": "raw-p12-b8"
  },
  "translation": {
    "language": "zh-TW",
    "text": "檢查化油器，如主噴油嘴損壞應予更換。",
    "source_block_id": "p12-b8"
  }
}
```

figure block 必須包含：

- `asset_id`
- `page`
- `bbox`
- `order`
- `section_path`
- `caption_block_id`
- `surrounding_block_ids`
- `asset_path`

## Figure Requirements

L4 不做圖內 OCR、overlay 或 VLM reviewer，但圖片責任不能省。

必須做到：

- 保存原始圖片 asset。
- 保留頁碼與 bbox。
- 保留閱讀順序。
- 關聯 section path。
- 關聯 caption。
- 關聯前後段落。
- Reader HTML / Markdown 插入正確相對路徑。
- broken asset links 必須為 0。

## Translation Rules

翻譯以 block 為單位，不以 OCR line 為單位。

必須保護：

- 型號：`FZR150`
- 縮寫：`M.J.`
- 噴嘴/規格值：`#97.5`
- 單位：`N·m`, `rpm`, `mm`
- 料號：`91201-KV3-831`

`document.zh-TW.md` 必須在翻譯成功且通過 Quality Gate 時才輸出。不得把英文原文複製後改名成繁中 artifact。

## Quality Gate

L4 就要有 Golden Acceptance，不延後到 L5。

新增：

- `scripts/docparser_acceptance.php`
- `packs/docparser/acceptance/technical_manual_v0.1.json`

最低通過條件：

- `page_record_coverage = 100%`
- `block_provenance_coverage = 100%`
- `broken_asset_links = 0`
- `orphan_figure_count = 0`
- `toc_broken_anchor_count = 0`
- `required_artifact_integrity = 100%`
- `translation_block_coverage >= 98%`
- `translation_identity_ratio <= 10%`
- `protected_token_preservation = 100%`
- Golden fixture assertions 全部通過

Golden fixture 至少檢查：

- page count。
- heading count。
- figure count。
- table count。
- required TOC titles。
- required translations。
- protected tokens。
- DocIR schema。
- RAG chunk provenance。

## Error Handling

依狀況回：

- `blocked_dependency`：必要 service 不存在、不啟用或 health failed。
- `needs_review`：解析可完成但品質低於門檻。
- `completed_with_warnings`：非必要能力失敗，但核心 artifacts 完整。
- `failed`：必要 artifact 缺失、input 不合法、下游回不可恢復錯誤。

不可對外暴露 `structure-main` 私有 response schema。

## Tests

第一刀測試順序：

1. 先寫 failing tests：manifest、task type、acceptance fixture、必要 artifact contract。
2. `docparser_acceptance.php` 應能打開 artifacts 檢查內容，不只 `file_exists`。
3. fixture 不允許 mock structure response 通過 real acceptance。
4. 翻譯覆蓋率不足時不得回 `completed`。
5. 破圖、斷 anchor、缺 DocIR provenance 均不得通過 acceptance。

驗證指令：

```bash
php scripts/run_tests.php
php -d zend.assertions=1 -d assert.exception=1 scripts/self_check.php
php scripts/token_api_smoke.php
find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n install.sh scripts/*.sh crontab/*.sh
node --check assets/js/services.js assets/js/packs.js
git diff --check
```

## Deferred

不做：

- MinerU engine。
- 圖內 OCR。
- 圖片 overlay。
- 技術圖面理解。
- VLM reviewer。
- 人工修正 UI。
- 多文件格式。
- 多引擎路由。
- L5 benchmark/readiness UI。

這些只在 DocParser L4 交付物穩定後再開新 phase。
