# PhaseShow-1 Catalog Show Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `/catalog_show/` as a public AIHub showcase where visitors can see examples and logged-in users can test allowed API modes.

**Architecture:** Keep `api.php` unchanged. `catalog_show/index.php` renders the product showcase; `catalog_show/api_proxy.php` accepts logged-in test requests and calls the local gateway with either the submitted token or a token owned by the logged-in user.

**Tech Stack:** PHP SSR, existing SQLite helpers, existing API token permissions, plain CSS and vanilla JS.

## Global Constraints

- Do not relax `api.php` Bearer Token rules.
- Public visitors may view examples, not execute API calls.
- Logged-in users may only test modes allowed by their own tokens.
- Token display is only for logged-in users and only their own token prefixes/plain submitted token values.
- No React/Vue/build step.

---

### Task 1: Contract Tests

**Files:**
- Create: `tests/test_catalog_show.php`

**Interfaces:**
- Produces checks for `hub_catalog_show_items()`, `hub_catalog_show_user_modes()`, `hub_catalog_show_pick_user_token()`, and `/catalog_show/` files.

- [ ] Write failing tests.
- [ ] Run `php scripts/run_tests.php` and verify the new test fails because files/functions are missing.

### Task 2: Showcase Helpers And Pages

**Files:**
- Create: `app/catalog_show.php`
- Create: `catalog_show/index.php`
- Create: `catalog_show/api_proxy.php`
- Create: `catalog_show/templates/layout.php`
- Create: `catalog_show/assets/catalog.css`
- Create: `catalog_show/assets/catalog.js`

**Interfaces:**
- `hub_catalog_show_items(): array`
- `hub_catalog_show_user_modes(PDO $db, ?array $user): array`
- `hub_catalog_show_pick_user_token(PDO $db, array $user, string $mode): ?array`

- [ ] Implement minimal helpers and SSR UI.
- [ ] Implement proxy that requires login, validates CSRF, validates mode, chooses token, and calls local `api.php`.
- [ ] Run tests and PHP lint.

### Task 3: Docs

**Files:**
- Modify: `README.md`
- Modify: `history.md`

- [ ] Add short PhaseShow-1 note.
- [ ] Run `git diff --check`.
