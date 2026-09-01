# Manual Vision DocVQA operations

`vlm-manual-vision` is a CUDA-only English DocVQA Pack. It answers a bounded English question about one document image; it is not literal OCR. PP-OCRv5 keeps responsibility for transcripts, table numbers, and coordinates. PaliGemma2 and `pdf2html` remain separate; connecting `pdf2html` is a later change after this real-image gate passes.

The service always builds `answer en {question}` and currently generates at most 64 server-owned tokens (hard limit 128). The public request has only `operation=docvqa`, one PNG/JPEG `image` up to 50 MiB, and one English `question`. Never expose or accept caller control of the model, immutable revision, Profile, host/container path, prompt, device, dtype, token limit, or Hugging Face credential.

## Release gate

1. Accept the gated model licence with the operator account. Install `vlm-manual-vision`, set its immutable 40-hex revision, and store the Hugging Face token only in the service's provision-only secret setting. The `manual_vision_provision` one-shot is the only online step and runs its container process as root; the token crosses into that child process only as `HF_TOKEN`, is redacted from bounded logs, and is never given to the resident service.
2. Build the pinned Pack image, then run its read-only GPU smoke before downloading. On native Linux use `docker run --rm --pull never --gpus all --entrypoint /usr/bin/python3 3waaihub-manual-vision-main:0.1.0 /app/gpu_smoke.py`. On Windows run the corresponding command inside the configured WSL distribution with its profile image `3waaihub/vlm-manual-vision:0.1.0`. Confirm CUDA, driver, device, and free VRAM. This probe does not stop or start any service.
3. If VRAM requires it, explicitly pause one chosen GPU container and record its name. In the authenticated Marketplace service card, use **準備 Manual Vision 模型** (`provision_manual_vision`, queued as `manual_vision_provision`), then start the resident service normally. The Marketplace action requires the normal admin session and CSRF check and rejects any other Pack. Native Linux and WSL use the same action; WSL transports `HF_TOKEN` through the child process environment rather than command arguments.
4. Use **執行 Manual Vision 驗收** (`accept_manual_vision`, queued as `manual_vision_acceptance`). It is token-free, sets both Hugging Face and Transformers offline modes, uses `--network none`, reads the model and committed fixtures read-only, and runs serially on CUDA. Inspect all three cold and warm results:

   | Case | Question | Exact normalized answer |
   | --- | --- | --- |
   | `manual-text` | `What is the shutdown temperature?` | `85 °C` |
   | `spec-table` | `What is the rated capacity?` | `1.2 L` |
   | `labelled-diagram` | `What component is marked A?` | `Fuse` |

   Acceptance trims and collapses ASCII whitespace only. Each case records cold/warm milliseconds, peak VRAM, and remaining VRAM; every answer must match and the lowest remaining VRAM must be at least 512 MiB.
5. Confirm `/health` is ready only after the acceptance record matches the configured revision and verified manifest. Put only a sanitized acceptance summary and redacted evidence in the deployment ticket; keep the raw node record private. Enable/select `manual_vision` on the child node, register/update its services, then refresh the Router with `php scripts/cluster_refresh.php --force`. Verify the live manifest before trying the Native and Cluster examples below. This repository change does not perform those deployment actions or advertise an unaccepted node.
6. On any failure, leave the Pack disabled or unpublished, collect only the bounded redacted command/service log, and restore any container paused in step 3. Do not delete model storage, cache, service data, a voice-profile volume, or any other Pack as recovery.

The model snapshot, cache, verified marker, raw acceptance JSON, immutable revision, and `HF_TOKEN` are node-private and must never enter Git, tickets, or public logs. A deployment ticket may contain only the sanitized acceptance summary and redacted evidence from step 5. Backup retention for these node assets is a separate operator policy review; do not improvise it during recovery.

## Request checks

Native Hub:

```bash
curl -fsS -X POST "<HUB_BASE_URL>/api.php?mode=manual_vision" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "operation=docvqa" \
  -F "image=@manual-page.png;type=image/png" \
  -F "question=What is the shutdown temperature?"
```

Cluster Router:

```bash
curl -fsS -X POST "<ROUTER_BASE_URL>/cluster_api.php?mode=manual_vision" \
  -H "Authorization: Bearer <CUSTOMER_TOKEN>" \
  -F "operation=docvqa" \
  -F "image=@manual-page.png;type=image/png" \
  -F "question=What is the shutdown temperature?"
```

A success body has exactly eight keys: `ok`, `mode`, `operation`, `answer`, `answer_language=en`, `contract_revision=1`, `elapsed_ms`, and the service `request_id`. Both gateways add an outer `X-3waAIHub-Request-Id`; save it separately from the body ID. Native authorization returns `missing_token` or `token_mode_not_allowed`. Service validation/runtime errors are `bad_request`, `unsupported_operation`, `bad_image`, `file_too_large`, `gpu_unavailable`, `model_not_provisioned`, `model_manifest_invalid`, `runtime_not_ready`, `inference_failed`, and `gateway_timeout`. Cluster additionally uses `router_unavailable` when no accepted station is live and `router_response_invalid` for an unsafe station response.

## Repository checks

These checks do not download the model or run GPU inference:

```bash
python3 -m unittest -v \
  packs/vlm-manual-vision/service/tests/test_app.py \
  packs/vlm-manual-vision/service/tests/test_provision.py \
  packs/vlm-manual-vision/service/tests/test_acceptance.py
php tests/test_manual_vision_pack.php
AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
git diff --check
```

`test_cluster_router.php`, `test_command_queue.php`, and `test_public_api_docs.php` are loaded by the control-plane runner and are not standalone scripts. In this development environment, FastAPI endpoint tests were not run because the host FastAPI installation lacks `annotated_doc`; the Pack-pinned dependencies in `service/requirements.txt` remain the deployment runtime. Real Docker runtime execution, model download/provision, GPU smoke, CUDA/model acceptance, deployment, and Router publication were not run here and remain authorized post-merge operator steps.
