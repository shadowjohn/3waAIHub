# PhaseP-2 Service Runtime Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each HubService edit Pack-declared runtime settings and regenerate its `.env`.

**Architecture:** Pack manifests declare `settings_schema`; each installed service stores actual values in `service_settings`. Admin UI edits schema-approved keys only, writes `.env`, and marks restart-required changes on `services`.

**Tech Stack:** PHP 8, SQLite, existing admin PHP pages, existing HubPack runtime generation.

---

### Task 1: Schema And Tests

**Files:**
- Create: `tests/test_service_settings.php`
- Modify: `app/db.php`
- Modify: `packs/hello/pack.json`
- Modify: `packs/ocr-ppocrv5/pack.json`
- Modify: `packs/translate-gemma12b/pack.json`

- [ ] Add failing tests for default service settings, `.env` regeneration, integer/select/path validation, and legacy setting backfill.
- [ ] Add `service_settings` table and `services.config_dirty` / `services.restart_required`.
- [ ] Add `settings_schema` to hello, OCR, and TranslateGemma manifests.

### Task 2: Service Settings Helpers

**Files:**
- Create: `app/service_settings.php`
- Modify: `app/bootstrap.php`
- Modify: `app/pack_registry.php`

- [ ] Implement schema lookup, defaults, validation, update, and `.env` write helpers.
- [ ] On pack install, create default `service_settings` and write `.env` from actual service settings.
- [ ] Keep `.env` limited to storage/global values, fixed service info, and schema-declared keys.

### Task 3: Admin UI

**Files:**
- Create: `admin/service_settings.php`
- Modify: `admin/services.php`

- [ ] Add service settings form with CSRF.
- [ ] Do not refill secret values.
- [ ] Save validated settings, regenerate `.env`, mark `restart_required` when needed.
- [ ] Add Settings link and config/restart badges on services list.

### Task 4: Docs And Verification

**Files:**
- Modify: `README.md`
- Modify: `history.md`

- [ ] Document PhaseP-2 settings editor.
- [ ] Run `php scripts/run_tests.php`.
- [ ] Run `php scripts/self_check.php`.
- [ ] Run PHP lint, shell lint, and `git diff --check`.
