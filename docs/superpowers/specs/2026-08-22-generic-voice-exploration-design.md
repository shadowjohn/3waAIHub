# Generic Voice Exploration Design

## Goal

Extend the Hub and `/var/www/html/myai/myai_voice/VoxCPM2_demo/` so a user can
explore new, non-character voices using VoxCPM2's generic synthesis.  The
result is a downloadable WAV plus a reproducibility record.  It is not a
Voice Profile and does not change the existing character voice-clone product.

## Scope and compatibility

The Hub adds one additive `voice_generate` operation, `generic_synthesize`.
Existing `synthesize`, `profile_prepare`, `profile_confirm`,
`voice_presets`, and `preset_synthesize` requests and responses remain
unchanged.  The Cluster Router relays the new operation using the same normal
task and artifact URLs as other `voice_generate` work.

`/var/www/html/myai/my_charactor_voice_clone/` is out of scope.  Its database
tables, API modes, Hub calls, Voice Profiles, and clone/ultimate-clone choices
are not modified.  A user who later wants to use a selected exploration WAV
can use that product's existing reference-WAV upload flow manually.

The VoxCPM2 Demo changes from a managed-preset browser to an exploration
form.  It does not create, adopt, upload, or retain a Hub Voice Profile.

## Public request and response

`generic_synthesize` accepts only the following semantic JSON fields:

```json
{
  "operation": "generic_synthesize",
  "text": "等一下，我再確認一次……",
  "gender": "female",
  "age_bucket": "young_adult",
  "role_note": "活潑有節奏的活動主持人，聲音明亮而有感染力。",
  "candidate_count": 3
}
```

`text` is the only spoken field.  `gender` is `unspecified`, `male`, or
`female`; `age_bucket` is `child`, `teen`, `young_adult`, `mature`, or
`senior`; `role_note` is optional, valid UTF-8, and limited to 300 characters;
and `candidate_count` is 1–3.  The request rejects model names, Voice Profile
identifiers, paths, clone modes, `voice_prompt`, `control`, seeds, and unknown
fields with the existing stable invalid-request response.

The accepted task uses the normal asynchronous task/status/result URLs.  On
completion, every candidate returns:

```json
{
  "candidate_id": "candidate-01",
  "audio_url": "cluster_api.php?...",
  "seed": 123456789,
  "voice_design_revision": 1,
  "style_status": "unverified"
}
```

The existing task artifact index remains the authority for the WAV artifact.
Candidate IDs are stable inside the task only.  The caller should persist
`task_id`, `candidate_id`, `seed`, and `voice_design_revision` alongside its
downloaded WAV when it needs provenance.

## Synthesis policy

The Hub chooses VoxCPM2 generic `design` mode and server-derived candidate
seeds.  It does not attach an existing profile, use `clone` or
`ultimate_clone`, or expose a model selection switch.

Current VoxCPM2 has no supported, separate gender, age, or style-control
parameter.  The Hub therefore records the requested gender, age bucket, and
role note in the private task recipe, but does not concatenate any of them
onto `text` or falsely claim that they changed the acoustic result.
`style_status` is consequently always `unverified` in this first release.
The fields become independently applicable only when a selected engine offers
official non-spoken controls; that later capability must change the status,
not the spoken-text boundary.

The recipe is useful for retrying and comparing a generic design under the
same runtime revision.  It cannot promise byte-identical output across model
or inference-environment revisions.  A selected WAV remains the actual
acoustic reference if a user later elects to create a character voice
elsewhere.

## Demo behaviour

The Demo form contains optional gender and age preference selectors, an
optional role note, spoken text, and a fixed 1–3 candidate-count control.
It labels gender, age, and role note as exploration preferences rather than
guaranteed sound traits.  It sends `generic_synthesize`, polls the returned
task, validates every candidate's artifact reference, and lets the user play
and download each WAV.

For every completed candidate, the Demo stores the Hub task ID, candidate ID,
seed, design revision, and style status with its existing local job record.
It has no save-to-profile action.  Existing preset cache entries remain
readable for historic jobs, but new exploration jobs neither fetch
`voice_presets` nor call `preset_synthesize`.

## Errors and safety

The Hub reports stable invalid-request errors for invalid field values and
candidate counts, and preserves its ordinary task failure/status responses for
runtime errors.  The Router must treat the new operation as an allowed
voice-generation task and preserve opaque task/artifact routing.

No response exposes a model, internal profile ID, filesystem path, raw role
note from a different user, or internal task recipe.  The Demo continues to
download artifacts through its existing allowlisted Cluster URL and validates
the returned MIME type and SHA-256 before making a local file available.

## Verification

Hub tests cover strict request validation, the non-spoken boundary, exactly
one to three deterministic candidate descriptors, private task recipe
contents, generic `design` dispatch, result projection, and Cluster routing.
Demo tests or static checks cover the new form labels, request payload,
candidate metadata persistence, multi-candidate rendering, and absence of
new calls or schema changes in `my_charactor_voice_clone`.
