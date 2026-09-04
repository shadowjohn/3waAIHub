# Breezy pronunciation rules implementation plan

> **For Codex:** Execute this plan with the Superpowers TDD workflow. Keep the
> existing no-`pronunciation` Breezy path byte-for-byte equivalent at its public
> boundary, and do not alter `profile_prepare` transcript handling.

**Goal:** Let a Breezy `synthesize` request carry strictly validated character
and one-request pronunciation overrides.  The Hub applies a small,
version-controlled global rule set plus those overrides before inference and
returns private synthesis metadata showing what it actually sent to Breezy.

**Architecture:** The public PHP boundary permits one bounded JSON object only
for the Breezy pack and rejects bad rules using `invalid_pronunciation_rules`.
The Breezy service independently validates and compiles the rules, which keeps
the model-only `[:bopomofo]` syntax out of the external API.  Existing callers
without `pronunciation` stay on the current runner path; pronunciation-aware
jobs create the derived text only inside the service and persist it solely in
the authenticated metadata artifact.

**Tech Stack:** PHP 8 Hub API/queue/Router, Python Breezy service, JSON data,
PHP test harness, pytest.

---

### Task 1: Define the small versioned global rule source and compiler

**Files:**
- Create: `packs/tts-breezyvoice/service/pronunciation-rules.json`
- Create: `packs/tts-breezyvoice/service/pronunciation.py`
- Create: `packs/tts-breezyvoice/service/test_pronunciation.py`
- Modify: `packs/tts-breezyvoice/Dockerfile`
- Modify: `packs/tts-breezyvoice/Dockerfile.pascal-cu118`

1. Write failing service tests for global-file loading, duplicate same-layer
   rejection, priority at the same text offset, longest match within one layer,
   a non-recursive replacement, and the `AI` / `K&N 204-1` / `濾心` example.
2. Run `python3 -m pytest -q packs/tts-breezyvoice/service/test_pronunciation.py`
   and confirm the missing compiler fails.
3. Add the smallest stdlib-only compiler.  The file has a positive integer
   `revision` and a bounded list of global rules; the initial data contains
   only the demonstrative low-priority `global:kn` spoken-form rule.  No UI,
   database, registry, or dictionary import.
4. Validate exact rule keys; cap external combined rules at 50; reject control
   characters and raw `[` / `]` / `[:` markers.  Require literal Chinese-only
   bopomofo matches, one valid reading per Han character, and bounded compiled
   output.
5. Compile in exactly two passes: spoken forms over original text, then
   bopomofo markers over Breezy-normalized text.  Record each applied ID once
   in scan order.
6. Re-run the focused tests and both Dockerfile syntax/compile checks.

### Task 2: Admit only the bounded Breezy request object at the Hub boundary

**Files:**
- Create: `app/breezy_pronunciation.php`
- Modify: `app/bootstrap.php`
- Modify: `app/pack_registry.php`
- Modify: `app/gateway.php`
- Modify: `app/cluster_router.php`
- Modify: `packs/tts-breezyvoice/pack.json`
- Modify: `tests/test_breezyvoice_pack.php`
- Modify: `tests/test_cluster_router.php`

1. Add failing PHP tests showing `pronunciation` is accepted only as a bounded
   object for `tts-breezyvoice`, while an array/object remains rejected for
   every other pack field.
2. Add failing request tests for over 50 rules, duplicate same-layer matches,
   unsafe marker text, invalid bopomofo shape, and a Router 400 projection of
   `invalid_pronunciation_rules`.
3. Extend the existing schema normalizer with the single reusable `object`
   type needed by this manifest; it must accept associative JSON only and cap
   its encoded size.  Do not create generic free-form nested input support.
4. Add a localized Breezy boundary validator which normalizes safe rule input
   but never emits model marker syntax.  Map all validation failures to the
   stable `invalid_pronunciation_rules` error in native Gateway and Cluster.
5. Update the manifest request schema and API contract descriptions.
6. Run the focused PHP tests, then the `voice-cluster` suite.

### Task 3: Integrate compilation with only pronunciation-aware synthesis

**Files:**
- Modify: `packs/tts-breezyvoice/service/job.py`
- Modify: `packs/tts-breezyvoice/service/test_job.py`

1. Add a failing test proving a request with no `pronunciation` still takes the
   existing isolated subprocess/resident call path and its metadata remains
   unchanged.
2. Add a failing fake-runtime test for the supplied example: the model gets
   `欸哀協助檢查 K and N 二零四之一濾[:ㄌㄩ4]心[:ㄒㄧㄣ1]。`, while the
   source `text` and `profile_prepare.prompt_text` remain untouched.
3. Add the smallest pronunciation-only direct inference path needed to use
   Breezy's existing normalizer before inserting markers.  Leave the current
   no-pronunciation subprocess path intact.
4. Redirect upstream stdout/stderr for that direct path so derived/source text
   cannot escape into Hub logs; record only safe task/rule/error identifiers.
5. Write `synthesis_metadata.json` pronunciation metadata only for an opted-in
   request: global rule revision, `spoken_text`, `model_text`, and ordered
   `applied_rule_ids`.
6. Run `python3 -m pytest -q packs/tts-breezyvoice/service/test_pronunciation.py packs/tts-breezyvoice/service/test_job.py`.

### Task 4: Verify artifact contract, Router projection, and docs

**Files:**
- Modify: `app/pack_job_runner.php`
- Modify: `tests/test_pack_job_artifacts.php`
- Modify: `README.md`
- Modify: `docs/api_examples.md`
- Modify: `docs/cluster-router.md`
- Modify: `packs/tts-breezyvoice/README.md`
- Modify: `tests/test_public_api_docs.php`

1. Add failing artifact tests: no-pronunciation metadata remains valid with no
   new field; opted-in metadata requires the exact safe pronunciation shape;
   malformed metadata is rejected before publication.
2. Pass the original task input only to the existing Breezy artifact checker
   and conditionally validate the new metadata.  Do not expose text, profile,
   container path, or raw model markers through public task-result fields.
3. Document the API object, layer priority, stable error, metadata artifact
   access, the strict no-raw-marker boundary, and explicitly exclude
   `profile_prepare`.
4. Run `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=voice-cluster` and
   `AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane`.

### Task 5: Final review and operator handoff

**Files:**
- Review all changed files only.

1. Run PHP lint on changed PHP files, both Breezy Python test files, and
   `git diff --check`.
2. Review every `synthesize` and `profile_prepare` caller to confirm the
   feature is additive and does not process preparation transcripts.
3. Record host-only test limitations separately from product failures (the
   host FastAPI installation may lack `annotated_doc`); do not mask them with
   skipped tests.
4. Keep deployment separate: after merge, rebuild/recreate only the Breezy
   image and run one authorized ownership-token smoke request before exposing
   it to MyAI.
