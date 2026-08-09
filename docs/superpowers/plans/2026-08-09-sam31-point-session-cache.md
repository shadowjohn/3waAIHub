# SAM 3.1 Point Session Cache Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reuse one SAM 3.1 multiplex point session for an identical uploaded image for 60 seconds, eliminating repeated frame encoding during interactive point refinement.

**Architecture:** The synchronous service maintains one process-local point session keyed by the SHA-256 of raw image bytes and the resident video predictor identity. The session owns one private JPEG frame directory. A daemon timer closes it after 60 seconds of inactivity; a new image, inference failure, model switch, or service shutdown path closes it immediately. Text inference remains on the existing image predictor path.

**Tech Stack:** Python 3.12, FastAPI, Meta SAM 3.1 multiplex predictor, PyTorch CUDA, `unittest`.

## Global Constraints

- Cache exactly one point session per service process for 60 seconds.
- Reuse only byte-identical images and the currently resident video predictor.
- Every cache replacement, timeout, model switch, and point inference exception must close the predictor session and remove its private frame directory.
- Preserve the public multipart contract and existing point mask geometry.
- Do not retain the image predictor and multiplex predictor together.

---

### Task 1: Add regression coverage for point session lifecycle

**Files:**
- Modify: `packs/sam3/service/test_sam31.py`
- Modify: `packs/sam3/service/test_app.py`

**Interfaces:**
- Produces adapter tests for opening, reusing, and closing a point session.
- Produces service tests that assert identical image bytes reuse one session and a model switch clears it.

- [x] **Step 1: Write failing tests.**

Add fake-predictor tests that call the new point-session helpers twice with the same session and assert one `start_session`, two `add_prompt` calls, and no `close_session` until explicit cleanup. Add service tests that use a fake timer, assert the image SHA key is reused, and assert expiration invokes `close_session` and removes the cache.

- [x] **Step 2: Run the focused tests.**

Run: `docker run --rm -v "$PWD/packs/sam3/service:/app" -w /app 3waaihub-sam3-main:0.2.0 python3 -m unittest test_sam31 test_app`

Expected: failure because the point-session APIs and cache do not yet exist.

### Task 2: Implement a bounded reusable point session

**Files:**
- Modify: `packs/sam3/service/sam31.py`
- Modify: `packs/sam3/service/app.py`

**Interfaces:**
- `open_point_session(predictor, image, workspace) -> Sam31PointSession`
- `segment_point_session(predictor, session, image_size, points, labels) -> list[dict[str, Any]]`
- `close_point_session(predictor, session) -> None`

- [x] **Step 1: Implement the adapter lifecycle.**

Materialize one JPEG in a `mkdtemp` workspace only when opening a session. Seed SAM 3.1's frame-0 cache once. Reused requests call `add_prompt` with `obj_id=1` against that session, so SAM's tracker refines the same object and replaces old points. Cleanup is idempotent and always removes the directory.

- [x] **Step 2: Implement the service cache.**

Compute the image SHA-256 from the already uploaded bytes. Store only `{image_sha256, predictor, session}` in the global cache. Reschedule a daemon `threading.Timer(60, ...)` after every successful point request. The expiry callback holds `_SAM_LOCK`; cache replacement, error handling, and `resident_sam3_model()` model replacement call the same clear helper before CUDA cache release.

- [x] **Step 3: Run focused tests.**

Run: `docker run --rm -v "$PWD/packs/sam3/service:/app" -w /app 3waaihub-sam3-main:0.2.0 python3 -m unittest test_sam31 test_app`

Expected: all focused adapter and service lifecycle tests pass.

### Task 3: Deploy and verify live interaction

**Files:**
- Modify: `packs/sam3/service/sam31.py`
- Modify: `packs/sam3/service/app.py`
- Modify: `packs/sam3/service/test_sam31.py`
- Modify: `packs/sam3/service/test_app.py`

- [x] **Step 1: Run full service and control-plane tests.**

Run the SAM service `unittest` discovery and `php scripts/run_tests.php --suite=control-plane`.

- [x] **Step 2: Rebuild and restart `sam3-main`.**

Use the generated compose file under `data/services/sam3-main`, then confirm `/health` reports real inference ready.

- [x] **Step 3: Prove the cache with real point requests.**

Send the same real image and point request twice after an initial warm-up. Record response `elapsed_ms`, returned mask count, and GPU memory. Wait 65 seconds, submit it again, and verify the session is recreated without an inference error.

- [ ] **Step 4: Commit and push only the SAM3 files and plan.**

Commit the four service files plus this plan, preserve unrelated working-tree changes, and push the rebased commit to `origin/main`.
