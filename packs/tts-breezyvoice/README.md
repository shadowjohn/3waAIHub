# BreezyVoice Taiwan Mandarin Clone

`tts-breezyvoice` is a B1 authorized, transcript-confirmed private Voice
Profile clone contract. It is for Taiwanese Mandarin; it is not Taigi or
Hokkien. A request is limited to one candidate and accepts only
`ultimate_clone` with a Hub-managed Profile handle.

## Initial state

The default profile targets Linux CUDA 12 / Blackwell. Windows WSL2 hosts with
GTX 1050/1060/1070/1080/1080 Ti use the explicit `pascal-cu118` profile. Both
pin the upstream source and model snapshot. The 3 GB model is provisioned by
the Marketplace `準備 BreezyVoice 模型` one-shot into the managed models
directory; a live task still requires an exclusive 4 GB GPU lease and an
authorized, transcript-confirmed profile.

Synthesis metadata will declare `seed_applied=false`; a supplied seed is only
best-effort reproducibility, never a promise of deterministic output.

## Pronunciation overrides

`synthesize` may optionally carry a `pronunciation` object. The Hub keeps a
small versioned global rule file, then applies caller-owned
`character_overrides`, then `request_overrides`. At one source position the
higher layer wins; within one layer, the longest literal `match` wins. The
replacement is scanned once and is never matched again.

```json
{
  "pronunciation": {
    "character_overrides": [{"id":"character:axian:ai","match":"AI","kind":"spoken_form","value":"欸哀"}],
    "request_overrides": [{"id":"podcast:49:filter","match":"濾心","kind":"bopomofo","readings":["ㄌㄩ4","ㄒㄧㄣ1"]}]
  }
}
```

There are at most 50 caller rules. `match` is literal, never regex;
`spoken_form` cannot contain `[` or `]`; `bopomofo` accepts only Chinese text
and one validated reading per character. Raw Breezy `[:...]` syntax is not a
public input. Invalid input returns `invalid_pronunciation_rules` (400).

The authenticated `synthesis_metadata.json` artifact records `rule_revision`,
`spoken_text`, `model_text`, `applied_rule_ids`, and source/spoken/model
character counts for an opted-in task. It is not copied to ordinary task-result
fields or logs. Original `text` remains the article/subtitle value;
`profile_prepare.prompt_text` is never processed by these rules.

## Runtime boundary

Windows / WSL GTX 1080 uses only the CUDA 11.8 profile. The provisioner has
temporary network access only to download the pinned model revision and write
its SHA-256 manifest; synthesis runs with `--network none`. Runtime settings
use `runtime-settings.conf` only. Do not create `.env` files or store Hugging
Face tokens in this Pack.
