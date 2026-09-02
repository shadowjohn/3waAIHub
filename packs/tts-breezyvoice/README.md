# BreezyVoice Taiwan Mandarin Clone

`tts-breezyvoice` is a B1 authorized, transcript-confirmed private Voice
Profile clone contract. It is for Taiwanese Mandarin; it is not Taigi or
Hokkien. A request is limited to one candidate and accepts only
`ultimate_clone` with a Hub-managed Profile handle.

## Initial state

This first runnable profile targets Linux CUDA 12 / Blackwell and pins both
the upstream source and model snapshot. The 3 GB model is provisioned outside
the image into the managed models directory; a live task still requires an
exclusive 4 GB GPU lease and an authorized, transcript-confirmed profile.

Synthesis metadata will declare `seed_applied=false`; a supplied seed is only
best-effort reproducibility, never a promise of deterministic output.

## Runtime boundary

Windows / WSL GTX 1080 is not enabled by this release: its CUDA 11.8 profile
needs a separate real-inference acceptance run. Runtime settings use
`runtime-settings.conf` only. Do not create `.env` files or store Hugging Face
tokens in this Pack.
