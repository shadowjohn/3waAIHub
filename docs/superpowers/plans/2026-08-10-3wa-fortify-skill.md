# 3wa-fortify Skill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a globally discoverable 3WA Fortify skill that inventories FPRs, generates evidence-backed finding registers, and supplies behavior-preserving PHP/JS/Python modification templates.

**Architecture:** Keep the global skill self-contained under `%USERPROFILE%\\.codex\\skills\\3wa-fortify`. A read-only PowerShell FPR inspector parses ZIP/XML with .NET only and emits object, JSON, or Markdown representations. The skill body routes work through the inspector and small reference files; the references provide remediation and audit templates without embedding project code or FPR artifacts.

**Tech Stack:** Markdown, PowerShell 7, .NET `System.IO.Compression`, XML, Python 3 skill-creator validation.

## Global Constraints

- Create the skill at `C:\Users\stw_s\.codex\skills\3wa-fortify`; do not place the skill inside the application repository.
- Do not add third-party packages, scanner binaries, credentials, FPR files, or copied application source to the skill.
- Preserve application code: this task creates only the global skill and repository design/plan documentation.
- Treat FPR `audit.fvdl` and `audit.xml` separately; an empty active list is not proof that historical findings were remediated.
- Offer direct, context-aware remediation and auditable exception text; do not introduce scanner-blind dynamic invocation or reversible encoding as a remediation mechanism.
- Use the fixture `C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr` only for read-only validation.

---

## File Structure

| File | Responsibility |
| --- | --- |
| `C:\Users\stw_s\.codex\skills\3wa-fortify\SKILL.md` | Trigger description, operating gates, FPR-to-code workflow, decision matrix, output contract. |
| `C:\Users\stw_s\.codex\skills\3wa-fortify\agents\openai.yaml` | Generated discoverability metadata. |
| `C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\inspect-fpr.ps1` | Read-only FPR inventory, category aggregation, finding records, audit counts, and source-archive scope. |
| `C:\Users\stw_s\.codex\skills\3wa-fortify\references\3wa-legacy-patterns.md` | 3WA legacy pattern classification and proof requirements. |
| `C:\Users\stw_s\.codex\skills\3wa-fortify\references\php-js-remediation-templates.md` | Copy-paste modification and audit-exception templates. |

### Task 1: Initialize the global skill scaffold

**Files:**
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\SKILL.md`
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\agents\openai.yaml`
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\`
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\references\`

**Interfaces:**
- Consumes: the approved design at `docs/superpowers/specs/2026-08-10-3wa-fortify-design.md`.
- Produces: a valid, globally discoverable skill directory that subsequent tasks populate.

- [ ] **Step 1: Assert that no skill folder exists**

Run:

```powershell
Test-Path -LiteralPath 'C:\Users\stw_s\.codex\skills\3wa-fortify'
```

Expected: `False`; stop if it is already present so user-owned changes are not overwritten.

- [ ] **Step 2: Initialize the scaffold through skill-creator**

Run:

```powershell
python 'C:\Users\stw_s\.codex\skills\.system\skill-creator\scripts\init_skill.py' 3wa-fortify --path 'C:\Users\stw_s\.codex\skills' --resources scripts,references --interface display_name='3WA Fortify' --interface short_description='FPR evidence and PHP JS remediation' --interface default_prompt='Use $3wa-fortify to classify this Fortify FPR and prepare safe 3WA remediation evidence.'
```

Expected: the command creates `SKILL.md`, `agents/openai.yaml`, `scripts`, and `references` under the exact global folder.

- [ ] **Step 3: Confirm generated-template validation behavior before adding content**

Run:

```powershell
python 'C:\Users\stw_s\.codex\skills\.system\skill-creator\scripts\quick_validate.py' 'C:\Users\stw_s\.codex\skills\3wa-fortify'
```

Expected: the generated TODO frontmatter may fail because YAML interprets its bracketed placeholder as a list. Record that expected baseline failure; Task 4 replaces the frontmatter and its final validation is the authoritative gate.

### Task 2: Implement the read-only FPR inspector

**Files:**
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\inspect-fpr.ps1`

**Interfaces:**
- Consumes: `-FprPath <path>`, optional `-Format Markdown|Json|Object`, and optional `-OutFile <path>`.
- Produces: records with `InstanceId`, `Category`, `Subtype`, `DefaultSeverity`, `File`, and `Line`; a summary with `ActiveCount`, `AuditActiveCount`, `AuditRemovedCount`, category counts, and top-level source-archive buckets.

- [ ] **Step 1: Write the failing fixture assertion**

Run this one-off assertion before the script exists:

```powershell
& 'C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\inspect-fpr.ps1' -FprPath 'C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr' -Format Object
```

Expected: command fails because `inspect-fpr.ps1` does not exist.

- [ ] **Step 2: Implement ZIP/XML parsing without extracting files**

Implement these exact responsibilities:

```powershell
param(
    [Parameter(Mandatory)][ValidateNotNullOrEmpty()][string]$FprPath,
    [ValidateSet('Object', 'Json', 'Markdown')][string]$Format = 'Markdown',
    [string]$OutFile
)

Add-Type -AssemblyName System.IO.Compression.FileSystem
# OpenRead($FprPath); read audit.fvdl, audit.xml, and src-archive/index.xml streams only.
# Select XML nodes by local-name() so Fortify namespaces do not change parsing.
# Never call ExtractToFile(), never alter the FPR, and dispose every stream/archive in finally.
```

For each `Vulnerability` in `audit.fvdl`, retrieve the `ClassInfo` type/subtype/default severity, `InstanceInfo/InstanceID`, and first `SourceLocation` attributes. For `audit.xml`, count `IssueList/Issue` and `IssueList/RemovedIssue`. For `src-archive/index.xml`, group entry keys by their first path segment, assigning root-level files to `[root]`.

- [ ] **Step 3: Emit deterministic output formats**

Implement an object payload with this shape:

```powershell
[pscustomobject]@{
    FprPath = $resolvedPath
    ActiveCount = $records.Count
    AuditActiveCount = $auditActiveCount
    AuditRemovedCount = $auditRemovedCount
    Categories = @($categoryRows)
    SourceArchiveBuckets = @($bucketRows)
    Findings = @($records)
}
```

For Markdown, render a title, the three counts, a category table, a source-archive table, and a finding table with an empty `Disposition` column. For JSON, use `ConvertTo-Json -Depth 8`. Write output only when `-OutFile` is supplied; otherwise return the object or write formatted text to stdout.

- [ ] **Step 4: Run the fixture contract test**

Run:

```powershell
$result = & 'C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\inspect-fpr.ps1' -FprPath 'C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr' -Format Object
if ($result.ActiveCount -ne 59) { throw "Expected 59 active findings, got $($result.ActiveCount)" }
if (($result.Categories | Measure-Object -Property Count -Sum).Sum -ne 59) { throw 'Category total does not equal active findings.' }
if (($result.Findings | Where-Object { $_.InstanceId -eq '0BA569BAE388DD6F22D70FB7AEFFFD53' -and $_.Subtype -eq 'Null Password' }).Count -ne 1) { throw 'Missing proxy null-password finding.' }
if (($result.Findings | Where-Object { $_.InstanceId -eq '83C66C873004B0F1F1DAF5A56F6B1D7E' -and $_.Subtype -eq 'Password in Comment' }).Count -ne 1) { throw 'Missing proxy password-comment finding.' }
```

Expected: no exception. Preserve the FPR SHA-256 before and after the run to prove the script did not mutate it.

- [ ] **Step 5: Commit the global skill implementation as a user-visible local artifact only if it is inside a Git repository**

Run:

```powershell
git -C 'C:\Users\stw_s\.codex\skills' rev-parse --is-inside-work-tree
```

Expected: if `false` or the command fails, do not initialize a repository and record the global skill path in the completion summary. If true, stage only `3wa-fortify` and commit with message `feat: add 3wa fortify inspector`.

### Task 3: Write legacy classification and modification templates

**Files:**
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\references\3wa-legacy-patterns.md`
- Create: `C:\Users\stw_s\.codex\skills\3wa-fortify\references\php-js-remediation-templates.md`

**Interfaces:**
- Consumes: FPR finding record plus the source trace.
- Produces: a decision of `remediate`, `audit-candidate`, or `scan-scope-candidate`, together with a compatible code pattern and verification command.

- [ ] **Step 1: Write the legacy-classification reference**

Define three explicit classes:

```markdown
| Class | Decision | Required proof |
| Direct mitigation | Retain or improve | Complete source-to-sink test |
| Context-specific compatibility | Retain narrowly | Context test plus no-secret/no-leak proof |
| Scanner-blind dynamic invocation | Do not add | Technical-debt inventory and direct replacement plan |
```

Include project-backed examples: optional proxy credentials with a non-secret fingerprint, generated-name upload storage with containment, finite schema metadata identifiers, and public response DTO redaction. State that lexical matches such as `password`, `cookie`, `upload`, and `hash` require trace review rather than automatic remediation.

- [ ] **Step 2: Write the PHP/JS modification reference**

Add short templates with preconditions, code, regression check, and audit fallback. Include at least these code patterns:

```php
function hub_allowed_identifier(string $requested, array $allowed): string {
    if (!array_key_exists($requested, $allowed)) {
        throw new InvalidArgumentException('Unsupported SQL identifier.');
    }
    return $allowed[$requested];
}

$table = hub_allowed_identifier($tableKey, ['tasks' => 'tasks', 'services' => 'services']);
$stmt = $db->prepare("SELECT id, state FROM {$table} WHERE owner_member_id = :owner_member_id");
$stmt->execute([':owner_member_id' => $memberId]);
```

```php
function hub_json_for_script(mixed $value): string {
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
}
```

```javascript
var requestData = $form.serializeArray();
requestData.push({ name: 'csrf_token', value: window.HUB_CSRF_TOKEN });
$.ajax({ method: 'POST', url: window.location.href, data: requestData, dataType: 'json' });
```

For password contracts, use an explicit external key only at the SDK boundary and require a test that checks the secret is absent from JSON, logs, exceptions, and metadata. For a non-security hash, require an explicit comment naming the cache/test-runtime purpose and a test that it is never used for authentication or integrity.

- [ ] **Step 3: Add the audit exception template**

Provide this exact compact format:

```markdown
**Fortify instance:** `<instance-id>` — `<category> / <subtype>`

**Disposition:** Audit candidate; no exploitable source-to-sink path under the deployed contract.

**Evidence:** `<file>:<line>` uses `<purpose>`; `<controls>`; regression `<command>` proves `<observable outcome>`.

**Boundary:** This conclusion applies only to `<component/release/scan scope>` and must be re-reviewed when the trace, data owner, or deployment contract changes.
```

- [ ] **Step 4: Verify no unsafe bypass guidance entered the references**

Run:

```powershell
rg -n -i 'base64.*prepare|dynamic callable.*remediation|hide.*sink|bypass.*fortify|reversible encoding.*remediation' 'C:\Users\stw_s\.codex\skills\3wa-fortify\references'
```

Expected: no match. Review all references once to confirm every template includes preconditions and verification.

### Task 4: Write the skill workflow and UI metadata

**Files:**
- Modify: `C:\Users\stw_s\.codex\skills\3wa-fortify\SKILL.md`
- Modify: `C:\Users\stw_s\.codex\skills\3wa-fortify\agents\openai.yaml`

**Interfaces:**
- Consumes: an FPR, checkout path, and optionally a specific finding/category.
- Produces: inspector output, source trace analysis, a remediation/audit/scope decision, and a user-facing evidence register.

- [ ] **Step 1: Replace the generated SKILL.md with a concise imperative workflow**

Use frontmatter exactly as follows:

```yaml
---
name: 3wa-fortify
description: Analyze Fortify FPR findings and remediate or prepare audit evidence for 3WA legacy PHP, JavaScript, PowerShell, and Pack Python code. Use when a Fortify scan repeatedly reports the same finding, when an FPR needs active/removed/scope analysis, or when Codex must prepare compatible code changes and an evidence-backed exception register.
---
```

Require these gates in the body:

1. Record FPR path, SHA-256, scan date/build, and checkout revision.
2. Run `scripts/inspect-fpr.ps1`; distinguish active findings from audit history.
3. Retrieve each trace and apply `references/3wa-legacy-patterns.md` before proposing a code change.
4. Load `references/php-js-remediation-templates.md` only for the affected category.
5. Separate confirmed fixes, audit candidates, and scan-scope candidates in the final register.
6. Verify code behavior and direct the user to re-run Fortify; never claim closure from source inspection alone.

- [ ] **Step 2: Regenerate UI metadata from the final skill content**

Run:

```powershell
python 'C:\Users\stw_s\.codex\skills\.system\skill-creator\scripts\generate_openai_yaml.py' 'C:\Users\stw_s\.codex\skills\3wa-fortify' --interface display_name='3WA Fortify' --interface short_description='FPR evidence and PHP JS remediation' --interface default_prompt='Use $3wa-fortify to classify this Fortify FPR and prepare safe 3WA remediation evidence.'
```

Expected: `agents/openai.yaml` contains quoted interface strings and the default prompt explicitly names `$3wa-fortify`.

- [ ] **Step 3: Validate the final skill**

Run:

```powershell
python 'C:\Users\stw_s\.codex\skills\.system\skill-creator\scripts\quick_validate.py' 'C:\Users\stw_s\.codex\skills\3wa-fortify'
```

Expected: validation passes; `SKILL.md` has no generated placeholders and references only files that exist.

### Task 5: Forward-validate the deliverable against the 59-finding FPR

**Files:**
- Test: `C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr` (read only)

**Interfaces:**
- Consumes: final skill and FPR inspector.
- Produces: a reproducible Markdown register preview and validation evidence without modifying the FPR or project source.

- [ ] **Step 1: Capture fixture hash before inspection**

Run:

```powershell
$before = (Get-FileHash -LiteralPath 'C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr' -Algorithm SHA256).Hash
$before
```

Expected: one 64-character SHA-256 value.

- [ ] **Step 2: Generate the Markdown evidence-register preview**

Run:

```powershell
& 'C:\Users\stw_s\.codex\skills\3wa-fortify\scripts\inspect-fpr.ps1' -FprPath 'C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr' -Format Markdown -OutFile "$env:TEMP\3wa-fortify-project-command-preview.md"
Get-Content -LiteralPath "$env:TEMP\3wa-fortify-project-command-preview.md" -TotalCount 80
```

Expected: 59 active findings, `SQL Injection` 29, `Often Misused / File Upload` 21, and both proxy password findings are present in the table.

- [ ] **Step 3: Confirm read-only behavior**

Run:

```powershell
$after = (Get-FileHash -LiteralPath 'C:\Users\stw_s\Desktop\3waAIHub_report\3waAIHub_project_command_20260810.fpr' -Algorithm SHA256).Hash
if ($before -ne $after) { throw 'FPR was modified.' }
```

Expected: no exception.

- [ ] **Step 4: Review final artifact boundaries**

Run:

```powershell
Get-ChildItem -LiteralPath 'C:\Users\stw_s\.codex\skills\3wa-fortify' -Recurse | Select-Object FullName,Length
rg -n -i 'password\s*=\s*["'"'][^"'"']+|api[_-]?key\s*=\s*["'"'][^"'"']+|token\s*=\s*["'"'][^"'"']+' 'C:\Users\stw_s\.codex\skills\3wa-fortify'
```

Expected: only the planned skill files exist and no literal credential is present. An empty `rg` result is success.

- [ ] **Step 5: Report completion without claiming a clean scan**

State the global skill path, validation commands, observed FPR counts, and any scan categories still requiring code fixes or Fortify audit approval. Do not modify application code, remote Fortify projects, audit state, or release artifacts.
