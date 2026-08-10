# 3wa-fortify skill design

## Purpose

Create a globally discoverable `3wa-fortify` skill for 3WA PHP, JavaScript, PowerShell, and Pack Python code. Its outcome is a repeatable Fortify delivery package: an FPR summary, a finding-by-finding disposition register, and minimal behavior-preserving remediation guidance.

The skill must distinguish a real exploitable flow from a rule-name or sink-name match. It must not claim that an FPR is clean merely because a prior audit state has no active entries.

## Inputs and outputs

Input is an FPR plus its source checkout. The FPR is authoritative for instance IDs, categories, trace locations, severity, and audit state; source code is authoritative for reachability and compensating controls.

The skill produces:

1. A deterministic FPR inventory with active and removed counts, category counts, locations, and source-archive scope.
2. A Markdown exception register with one row per active instance: trace, business purpose, actual source-to-sink path, controls, executable regression evidence, owner decision, and suggested Fortify audit text.
3. A remediation queue for confirmed vulnerabilities. Do not mix this queue with audit candidates.
4. A scan-scope review that permits excluding only non-deployable fixtures, historical checkouts, or third-party/generated code with provenance.

## 3WA legacy pattern policy

Classify legacy code before changing it.

| Class | Treatment |
| --- | --- |
| Direct mitigation | Preserve or improve it, then prove the complete path. Examples: parameter binding plus identifier allowlist, upload MIME/size/path containment, response DTO redaction, CSRF validation. |
| Context-specific compatibility | Retain only when production behavior requires it. Document the context and add a focused regression test. Examples: JavaScript string transport, protocol-required `password` keys, non-security checksums. |
| Scanner-blind dynamic invocation | Treat existing use as technical debt and report it separately. Do not add new use as a remediation or audit strategy. |

The skill may document a legacy pattern's effect on scan results, but must not offer code transformations intended to conceal a security-relevant source or sink from Fortify.

## Required FPR classifications

Apply the following tests before recommending audit disposition.

- **Password management:** distinguish a hardcoded secret from an optional credential field, an environment/CLI injected value, or a statement that a log fingerprint excludes secrets. Require proof that the value is not persisted, returned, or logged.
- **File upload:** require type/content validation, limits, generated storage name, non-executable/public storage boundary, authorization, and path containment. A mere filename check is insufficient.
- **SQL injection:** values must be bound. Dynamic identifiers or DDL require a finite, server-owned allowlist with a traceable source; otherwise fix the code.
- **Cookie security and CSRF:** inspect runtime session/cookie configuration and every state-changing server endpoint. Do not audit away missing browser controls solely because a JavaScript call exists.
- **Weak cryptographic hash:** distinguish non-security fingerprints/cache paths from password, signature, token, or integrity uses. Record the usage contract.
- **JavaScript output:** identify HTML, attribute, URL, JavaScript-string, and JSON contexts separately. Prefer direct context-aware encoding and DOM APIs; preserve a legacy transport only with a regression test for quotes, tags, ampersands, and Unicode.

## Validation contract

Validate the skill itself with the 2026-08-10 3waAIHub FPR fixture. It must report the 59 active Low findings and identify the two proxy findings as separate `Null Password` and `Password in Comment` instances without conflating them with a hardcoded credential.

The helper must be read-only against FPRs. It must not alter audit state, source code, scanner configuration, or releases.

## Files

The global skill folder will contain only:

- `SKILL.md`: trigger conditions, workflow, decision gates, and output format.
- `agents/openai.yaml`: generated UI metadata.
- `scripts/inspect-fpr.ps1`: read-only FPR inventory and Markdown/JSON output.
- `references/3wa-legacy-patterns.md`: pattern classifications and evidence requirements.

No scanner binary, FPR, credentials, source extract, or copied project code belongs in the skill.

## Acceptance criteria

- `quick_validate.py` accepts the skill metadata and name.
- The FPR inspector runs on the 3waAIHub FPR without Fortify tooling installed.
- The inspector's category total equals its active finding total.
- The generated register differentiates remediation, audit candidate, and scan-scope candidate.
- Existing application code remains unchanged by this task.
