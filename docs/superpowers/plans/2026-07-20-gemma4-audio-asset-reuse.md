# Gemma4 Audio Asset Reuse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `audio_upload + audio_id` so Gemma4 audio can be uploaded once and asked multiple times.

**Architecture:** Reuse the existing Photo Asset pattern. Audio files are short-lived owner-scoped uploads stored under `data/uploads/audio/{audio_id}/original.wav`; `mode=audio` accepts either a multipart `audio` file or JSON/form `audio_id`.

**Tech Stack:** PHP Gateway, SQLite, FastAPI Gemma4 adapter, existing benchmark/docs/playground helpers.

## Global Constraints

- WAV only, 16kHz mono, <= 30 seconds, <= 16MB.
- No session, no long audio chunking, no MP3/M4A conversion, no diarization, no timestamps.
- Customer can only use owned `audio_id`.
- Do not accept host path, container path, URL, or arbitrary storage path from clients.

---

### Task 1: Audio Asset Storage

**Files:**
- Modify: `/DATA/3waAIHub/app/db.php`
- Create: `/DATA/3waAIHub/app/audio_assets.php`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Produces: `hub_audio_store_upload(PDO $db, array $file, array $owner): array`
- Produces: `hub_audio_get_asset_for_auth(PDO $db, string $audioId, array $owner): ?array`
- Produces: `hub_audio_asset_host_path(array $asset): ?string`

- [x] Write failing tests for DB table, upload validation, owner lookup, and unsafe path rejection.
- [x] Run `php scripts/run_tests.php` and confirm the new tests fail.
- [x] Add the table and minimal helper functions.
- [x] Run `php scripts/run_tests.php` and confirm green.

### Task 2: Gateway Modes

**Files:**
- Modify: `/DATA/3waAIHub/app/gateway.php`
- Modify: `/DATA/3waAIHub/app/customer_accounts.php`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Produces: `api.php?mode=audio_upload`
- Extends: `api.php?mode=audio` with `audio_id`

- [x] Write failing tests for `audio_upload`, JSON `audio_id`, ownership, and docs-safe response contract.
- [x] Run `php scripts/run_tests.php` and confirm the new tests fail.
- [x] Implement `audio_upload` and reuse in `audio`.
- [x] Run `php scripts/run_tests.php` and confirm green.

### Task 3: Playground, Docs, Benchmark Contract

**Files:**
- Modify: `/DATA/3waAIHub/admin/playground.php`
- Modify: `/DATA/3waAIHub/app/public_api_docs.php`
- Modify: `/DATA/3waAIHub/docs/api_examples.md`
- Modify: `/DATA/3waAIHub/docs/client_quickstart.md`
- Modify: `/DATA/3waAIHub/packs/llm-gemma4-12b/pack.json`
- Modify: `/DATA/3waAIHub/history.md`
- Test: `/DATA/3waAIHub/tests/test_gemma4_audio.php`

**Interfaces:**
- Playground supports direct WAV upload or existing `audio_id`.
- Public docs show `audio_upload -> audio` two-step flow.

- [x] Write failing tests for UI/docs/manifest text.
- [x] Run `php scripts/run_tests.php` and confirm failure.
- [x] Update UI and docs.
- [x] Run full verification.
