# Manual Vision English DocVQA

This Pack answers one English question about one PNG/JPEG. English DocVQA is an answer capability, not literal OCR: PP-OCRv5 remains responsible for transcripts, table numbers, and coordinates. Existing PaliGemma2 and `pdf2html` flows remain separate.

The public multipart request has exactly three fields: `operation`, `image`, and `question`; the sole operation is `docvqa`, and the PNG/JPEG image limit is 50 MiB. Callers cannot select a model, revision, Profile, path, device, or generation control. The service composes exactly `answer en {question}` and owns the answer budget (64 tokens by default, hard maximum 128).

`manual_vision_provision` is a private, operator-only root one-shot: it validates the pinned local snapshot before publishing the verified marker. The resident API stays nonroot, never downloads a model, and never receives a provisioning credential.

Run `manual_vision_acceptance` for the fixed three-image CUDA acceptance after provisioning. An 8 GiB GPU is admitted per node only when that run succeeds with at least 512 MiB remaining VRAM; there is no automatic GPU eviction. An operator may pause another GPU service for the run and restore it afterwards without deleting Voice Profiles or mounted data.

The three acceptance answers are exactly `85 °C`, `1.2 L`, and `Fuse`, after trimming and collapsing ASCII whitespace. Model snapshot, cache, acceptance record, revision, and provisioning token stay node-private. The public API exposes neither provisioning settings nor runtime evidence.
