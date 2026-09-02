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

## Runtime boundary

Windows / WSL GTX 1080 uses only the CUDA 11.8 profile. The provisioner has
temporary network access only to download the pinned model revision and write
its SHA-256 manifest; synthesis runs with `--network none`. Runtime settings
use `runtime-settings.conf` only. Do not create `.env` files or store Hugging
Face tokens in this Pack.
