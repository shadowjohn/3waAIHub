# Manual Vision acceptance

`manual_vision_provision` is a private, operator-only root one-shot: it validates the pinned local snapshot before publishing the verified marker. The resident API stays nonroot, never downloads a model, and never receives a provisioning credential.

Run `manual_vision_acceptance` for the fixed three-image CUDA acceptance after provisioning. An 8 GiB GPU is admitted per node only when that run succeeds with at least 512 MiB remaining VRAM; there is no automatic GPU eviction. An operator may pause another GPU service for the run and restore it afterwards without deleting Voice Profiles or mounted data.

The committed fixtures and acceptance record contain no secrets. The public API exposes neither provisioning settings nor runtime evidence.
