# VoxCPM2 TTS Pack

`tts-voxcpm2` provides the installed async `voice_generate` route. The sync
`tts` service is for short requests and diagnostics; async publication does
not require that sync runtime to stay running.

## Public Contract

`voice_generate` supports six operations:

| operation | purpose |
| --- | --- |
| `profile_prepare` | Upload and transcribe a consented reference WAV. |
| `profile_status` | Read the task-scoped safe profile status and ASR draft. |
| `profile_confirm` | Explicitly confirm the transcript. |
| `profile_delete` | Delete the managed profile and reference audio. |
| `synthesize` | Queue `design`, `clone`, or `ultimate_clone` synthesis. |
| `generic_synthesize` | Explore 1–3 new generic `design` candidates without a Profile or preset. |

Omitting `operation` means `synthesize`.

## Spoken Text Boundary

For Native and Cluster synthesis, `text` contains only intended spoken text.
`voice_prompt`, `control`, role/scenario descriptions, and defaults are never concatenated
into `text`. Until VoxCPM2 provides dedicated style parameters,
that metadata is kept separate and not passed to VoxCPM2.

## Generic exploration

`generic_synthesize` accepts only `text`, `gender`, `age_bucket`, `role_note`,
and `candidate_count` (1–3). The node receives generic `design` work with the
Hub-owned internal `preset_candidates` list; gender, age, and role note do not
enter `text` and current VoxCPM2 does not claim them as applied controls. The
result supplies candidate ID, opaque `audio_artifact_id`, opaque audio URL,
seed, design revision, and `style_status=unverified` only. Native `task_result`
does not contain an ACK template or `cluster_artifact_index`. Router only:
expand its returned `ack_url_template` with the candidate's opaque
`audio_artifact_id` after download, then POST the ACK. It creates no Voice
Profile and does not use `clone` or `ultimate_clone`.

This operation needs no new service setting, container path, model selection,
or restart. Apply the existing resident-service restart rule only when its
actual persisted configuration changes.

## Native Flow

1. Call `profile_prepare`; MyAI stores the returned `task_id` as
   `voice_profile_task_id`.
2. Follow the returned `status_url` (`task_status`), then call
   `profile_status`.
3. Confirm the reviewed draft with `profile_confirm`.
4. Submit `synthesize` with `mode=ultimate_clone`, follow the returned
   `result_url`, choose an `id` from `result.artifacts[]`, expand
   the submit response's `artifact_url_template`, and download the artifact.
5. Call `profile_delete` when the profile is no longer needed.

Native Hub task and artifact links remain direct Hub links. See the generated
public API docs for placeholder-only curl, PHP, and JavaScript examples.

## Cluster Flow

Use the same operation sequence through `cluster_api.php`:

`profile_prepare` -> `cluster_task_status` -> `profile_status` ->
`profile_confirm` -> `ultimate_clone` -> `cluster_task_result` ->
`cluster_artifact` -> `profile_delete`.

MyAI stores only the opaque Router `voice_profile_task_id`; it never stores or
sends a child profile identifier. Profile followups and profile-based
synthesis remain on the pinned station with no failover. If that station is
unavailable, retry later after `station_unavailable`; do not prepare or use the
profile on another station.
The Router rich voice submit response also returns `ack_url_template`; POST the
downloaded artifact `id` through that template after receipt.

After `profile_prepare` succeeds, the Profile handle belongs to the API member.
After Token revocation or rotation, any currently valid Token for that member
with `voice_generate permission` may continue Profile operations. Ordinary task
and artifact followups remain bound to the submitting Token.

## Privacy

- Treat the reference WAV and transcript as sensitive customer data.
- For the authenticated Profile member, `profile_status` may return the unconfirmed
  `prompt_text` ASR draft and `reference_audio_sha256`; the confirmed transcript remains hidden.
- Native Hub task IDs remain part of the native async contract.
- Other public task/log/callback/synthesis payloads do not expose transcript
  plaintext or tokens. Cluster child task/profile IDs and paths remain behind
  the Router boundary.
- Never accept host or container paths. A fixed internal Pack mount path does
  not appear in synthesis metadata; it is an implementation detail, not public contract,
  and clients must not depend on it.
- Member ownership is mandatory. Native ownership failures use
  `voice_profile_forbidden`; Cluster unknown or foreign handles use
  `profile_task_not_found`.
- Confirm the transcript before `ultimate_clone`.
- Always perform explicit `profile_delete` after the product no longer needs
  the profile.

The generated native and Cluster manifests are the canonical field, link,
error-code, and HTTP-status references.
