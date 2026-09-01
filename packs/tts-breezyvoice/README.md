# BreezyVoice Taiwan Mandarin Clone

`tts-breezyvoice` is a B1 authorized, transcript-confirmed private Voice
Profile clone contract. It is for Taiwanese Mandarin; it is not Taigi or
Hokkien. A request is limited to one candidate and accepts only
`ultimate_clone` with a Hub-managed Profile handle.

## Initial state

This Pack is metadata and dependency declaration only. `runtime_ready` remains
`false`: no model has been downloaded, no runner implementation or Dockerfile
is supplied here, and no real inference or acceptance is claimed.
`BREEZYVOICE_UPSTREAM_REVISION` is empty in the example runtime settings,
which deliberately keeps the runtime not ready.

Synthesis metadata will declare `seed_applied=false`; a supplied seed is only
best-effort reproducibility, never a promise of deterministic output.

## Windows + WSL2 GTX 1080

On Windows, this Pack must use the `windows-wsl2-linux-docker` target. GTX 1080
Pascal uses the CUDA 11.8 `pascal-cu118` profile. Models, cache, and service
data stay on WSL ext4 under `/DATA`; do not mount `/mnt/d` or another Windows
host path.

Runtime settings use `runtime-settings.conf` only. Do not create `.env` files
or store Hugging Face tokens in this Pack.
