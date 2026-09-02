# Manual Vision DocVQA Design

## Goal

Add a separate, capability-first document question-answering Pack.  Its public
API is `mode=manual_vision` and `operation=docvqa`; the first implementation
uses the gated `google/paligemma-3b-ft-docvqa-448` checkpoint.

The mode describes the stable capability, not PaliGemma.  A later accepted
engine may replace the implementation without changing callers.

## Scope and compatibility

This is a new Pack with ID `vlm-manual-vision` and a new service.  It does not
change, reconfigure, or alias the existing `vlm-paligemma2` / `paligemma2`
baseline.  It also does not change VoxCPM2, GPT-SoVITS, Voice Profiles, or any
character-clone route.

The first release is single-image, synchronous DocVQA only.  It is not OCR,
captioning, detection, diagram localization, translation, a generic chat
endpoint, or a multi-page PDF workflow.  `pdf2html` integration is deferred
until the Pack has passed its real-image acceptance set.

## Public contract

The Router and a native station expose the same authenticated request:

```text
POST ?mode=manual_vision
Content-Type: multipart/form-data
Authorization: Bearer <token with manual_vision permission>
```

```text
operation=docvqa
image=<one PNG or JPEG, at most 50 MiB>
question=<trimmed ASCII English question, 1–400 characters>
```

`operation` is required and its only accepted value in revision 1 is
`docvqa`.  The Hub creates the model input exactly as:

```text
answer en {question}
```

`question` may contain printable ASCII plus spaces, must contain at least one
English letter, and is otherwise rejected as `bad_request`.  The service
trims leading/trailing ASCII whitespace and preserves the remaining question
bytes when composing that prompt.

The Hub does not accept caller-provided `prompt`, `model`, `revision`,
`max_tokens`, `temperature`, device choices, filesystem paths, model URLs, or
an arbitrary task name.  The response is direct JSON, with no task, artifact,
or ACK protocol:

```json
{
  "ok": true,
  "mode": "manual_vision",
  "operation": "docvqa",
  "answer": "1.2 L",
  "answer_language": "en",
  "contract_revision": 1,
  "elapsed_ms": 840,
  "request_id": "req_..."
}
```

The model identifier, snapshot path, GPU name, host, container, private
prompt recipe, and Hugging Face token are never public response fields.  The
checkpoint/revision is stored in private provisioning and acceptance records.

Because the prompt is fixed to `answer en`, callers must use English questions
and treat the original answer as English.  Translation is an independent Hub
capability, not an undocumented model-prompt override.

## Runtime and model policy

The Pack provisions only an operator-approved, immutable float16 snapshot of
`google/paligemma-3b-ft-docvqa-448` after the operator has accepted its gated
license.  Inference is CUDA-only, loads from that local snapshot, and never
downloads a model on a request.  It uses one request at a time and a
server-owned output limit of 64 new tokens, hard-capped at 128.

The service owns the DocVQA input formatter.  It must not reuse the current
PaliGemma 2 baseline formatter or its `<image>` convention without a
checkpoint-specific compatibility test.  The service returns the decoded
answer only; it does not parse or claim object coordinates.

An 8 GiB GPU is a deployment hypothesis, not a Pack guarantee.  The runtime
may be admitted only after a cold-start and real-inference acceptance on the
target GPU.  It must leave at least 512 MiB free at observed peak allocation.
There is no automatic eviction or stop action for VoxCPM2 or another service.
An operator may temporarily stop a GPU container for the acceptance run, then
restore it; this is a runtime operation and does not delete any Voice Profile
or mounted data.

## Errors and routing

Stable client errors are `bad_request`, `unsupported_operation`, `bad_image`,
`file_too_large`, `missing_token`, and `token_mode_not_allowed`.  Operational
errors are `gpu_unavailable`, `model_not_provisioned`,
`model_manifest_invalid`, `runtime_not_ready`, `inference_failed`, and
`gateway_timeout`.

The Cluster Router may advertise `manual_vision` only after the child has
published a successful fixed-image CUDA acceptance record for this Pack.  It
forwards the multipart request once to an eligible accepted station and
returns the station JSON synchronously.  A missing live-manifest entry is an
availability result; it must not reveal a node, GPU, model path, or secret.

## Acceptance and release gate

Provisioning requires a secret Hub-managed Hugging Face token and creates a
verified local manifest for the pinned float16 snapshot.  The Pack is not
enabled merely because dependencies import or the model files exist.

The release acceptance is a single-GPU, no-concurrent-inference run using
three versioned, non-sensitive fixtures with known English answers:

1. a text-heavy service-manual page;
2. a specification-table page; and
3. a labelled diagram page with a question whose answer is visible text.

For each fixture the acceptance records the exact normalized-answer match,
cold and warm elapsed time, and peak GPU memory.  Normalization
is trim plus collapsing ASCII whitespace runs to one space; it never changes
case, punctuation, digits, or units.  Any CUDA OOM,
missing answer, wrong answer, or peak leaving less than 512 MiB free fails the
acceptance and keeps the Pack unpublished.  The manifest and public API
documentation must identify this as an English DocVQA capability, not an OCR
source of truth.

## Verification and documentation

Tests cover strict request validation, the exact `answer en {question}`
formatter, rejection of caller model/prompt/generation controls, snapshot
integrity, CUDA-only readiness, bounded generation, and the three-fixture
acceptance record.  Router tests cover token permission, live-manifest
publication, multipart relay, and redaction of runtime details.  Node and
Cluster documentation cover provisioning, the fixed acceptance command,
resource preflight, failure recovery, and the direct synchronous response.

Only after those checks pass may `pdf2html` add an optional DocVQA step.  Its
existing OCR remains the literal transcription and number/table source; this
Pack supplies an answer to a bounded visual question rather than replacing
that pipeline.
