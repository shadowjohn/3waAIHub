# 3waAIHub History

## PhaseL-1A Gemma 4 12B Unified LLM Pack

Added the first generic LLM HubPack while keeping the existing 3waAIHub boundary intact.

Implemented:

- Added `llm-gemma4-12b` catalog entry and Pack manifest.
- Kept `schema_version=0.1`, `type=api_service`, `execution_type=sync_api`.
- Exposed Hub adapter endpoint `POST /chat` through `api.php?mode=chat`.
- Kept vLLM as an internal Docker sidecar instead of exposing OpenAI-compatible Gateway behavior.
- Added adapter service files:
  - `packs/llm-gemma4-12b/service/Dockerfile`
  - `app.py`
  - `smoke.py`
  - `storage_smoke.py`
  - `requirements.txt`
- Generated compose now includes:
  - `vllm` sidecar using `vllm/vllm-openai:gemma4`
  - `chat-api` adapter bound to `127.0.0.1:${GEMMA4_LOCAL_PORT:-18110}:8000`
- Added L5 benchmark cases:
  - `gemma4_mock_chat`
  - `gemma4_real_chat`
- Playground `mode=chat` now sends Hub `/chat` payload fields, not OpenAI `messages` / `stream`.

Deferred:

- SSE streaming passthrough.
- Vision / image input.
- Tool calling.
- Structured output.
- OpenAI-compatible public Gateway surface.

## SAM3 Mask Client Contract Polish

Improved SAM3 mask metadata for external clients.

Changed:

- Real SAM3 mask items now include `confidence` as a client-friendly alias of `score`.
- Real SAM3 mask items now include `label_name`.
- Semantic text prompt results use the submitted text prompt as a label fallback.
- `polygon` output remains multi-contour `[[[x,y]...]]`.
- Added frontend-friendly `polygons` output with `{outer, holes}` geometry.
- SAM3 dependency smoke now imports `cv2` so container builds catch missing OpenCV early.
- Updated SAM3 manifest contract and client docs.

## PhaseDoc Repair Translation Slim

Added a focused DocParser translation repair workflow.

Implemented:

- `docparser_repair_translation` async task type.
- `task_submit` repair input: `task_id` plus comma-separated `block_ids`.
- Block ID validation with strict `p{page}-b{block}` format and a 50-block limit.
- API member ownership checks for repair tasks.
- Registered DocIR artifact loading only from `data/results/task_{task_id}/`.
- Selective retranslation of missing/invalid blocks while skipping already translated blocks.
- Original DocParser artifacts are rewritten after repair and quality report is recomputed.
- New DocParser tasks store API member/token ownership in task input.
- DocParser cache now avoids cross-member cache hits.

Skipped:

- UI repair button.
- repair all.
- OCR/layout/figure reruns.
- Webhook or quota accounting.

## DocParser Table Translation Quality Gate

Fixed DocParser table translation coverage so table blocks are not silently accepted with empty translations.

Changed:

- `table` blocks are now included in DocParser translation.
- HTML table source is normalized with row/cell separators before plain-text extraction.
- Quality report now includes `translation_coverage_by_type`.
- Missing translations are reported by type and with page/block IDs.
- Missing table translations fail quality gate with `translation_coverage_by_type.table`.
- Translation calls now retry up to 3 attempts per block/chunk before failing the task.

Skipped:

- `repair_translation(task_id, block_ids)` endpoint; add as a follow-up repair workflow if partial reprocessing becomes common.

## PhaseM BioCLIP L4 Real Inference Smoke

Promoted `bioclip` from L3 storage/mock to L4 real inference smoke.

Implemented:

- `runtime_level=L4-real-inference-smoke`
- GPU/CUDA default runtime for BioCLIP
- Torch / OpenCLIP pinned runtime dependencies
- `hf-hub:imageomics/bioclip` as the default model
- Real `POST /classify/image` zero-shot classification path
- `model_smoke.py` and `inference_smoke.py`

Skipped:

- Taxonomy database
- Batch classification
- NatureID server integration
- L5 benchmark/readiness contract

## PhaseM BioCLIP L5 Benchmark Ready

Promoted `bioclip` to L5 benchmark-ready.

Implemented:

- `runtime_level=L5-benchmark-ready`
- `l5_contract` for `POST /classify/image`
- `bioclip_mock_image` benchmark case
- `bioclip_real_image` benchmark case
- Pack readiness support through existing L5 helpers

Skipped:

- Taxonomy database
- Batch classification
- NatureID server integration

## PhaseM BioCLIP L2/L3 Evaluation Pack

Added a minimal BioCLIP species classification HubPack for upcoming species-identification evaluation.

Implemented:

- `packs/bioclip/pack.json`
- `packs/bioclip/service/` FastAPI mock adapter
- `POST /classify/image` mock API contract
- L2 import smoke for FastAPI / Pillow / NumPy
- L3 storage mount smoke for models/cache/service data
- Catalog entry and generated service env support

Skipped:

- Real BioCLIP inference
- Model download
- Torch / OpenCLIP pinning
- NatureID server integration

Verified:

- BioCLIP pack tests PASS.

## Worker Loop Responsiveness Fix

Fixed the cron worker loop so long `task_worker.php` jobs no longer starve Docker command jobs.

Changed:

- `crontab/1min.sh` now uses separate locks for command jobs and user-facing tasks.
- Long DocParser tasks can keep running while later service start/stop jobs are still consumed by the next cron tick.
- Service `up -d`, `down`, and `restart` command timeouts were shortened for faster UI feedback.
- Docker `down` / `restart` now use a 5 second compose timeout.

Verified:

- `service_stop` job for `sam3-main` completed successfully.
- `bash -n crontab/1min.sh scripts/install_command_worker_cron.sh` PASS.

## SAM3 Text Prompt Runtime Dependency

Added the Ultralytics CLIP runtime dependency to the SAM3 service image so semantic `prompt_type=text` real inference can run inside Docker.

Verified:

- Rebuilt and restarted `sam3-main`.
- NatureWeb black bear smoke call through `mode=sam3` returned HTTP 200, `ok=1`, `mock=0`, `prompt_type=text`, and 1 bbox.

## PhaseTask-1 DocParser Cooperative Cancel

Added safe cancel support for long DocParser tasks.

Implemented:

- `task_cancel` still cancels queued tasks immediately.
- Running `docparser_parse` tasks now accept a cooperative cancel request.
- Cancel request is stored in task input as `cancel_requested=1`.
- `task_worker.php` checks cancellation at DocParser checkpoints:
  - before structure parsing
  - after structure parsing
  - after figure extraction
  - during translation blocks
  - before render / quality / artifact write
- Worker marks the task `cancelled` instead of `failed` when a checkpoint sees the request.
- Submit response includes `cancel_url`.
- Docs clarify that this does not hard-kill workers, Docker containers, or in-flight backend HTTP calls.

Verified:

- `php scripts/run_tests.php` PASS with running DocParser cancel coverage.

## SAM3 Semantic Text Prompt

Added semantic text prompt support to the SAM3 API.

Implemented:

- `POST /segment/image` accepts `prompt_type=text`.
- `text` / `text_prompt` can carry concepts such as `mammal/insect/plant`.
- Real inference uses the SAM3 semantic predictor path.
- Mock responses keep the same request contract and echo normalized text prompts.
- Playground, admin API docs, public examples and client quickstart document the new field.

Verified:

- `php scripts/run_tests.php` PASS.
- `python3 -m py_compile packs/sam3/service/*.py` PASS.

## DocParser Artifact Cache

Added a lightweight DocParser submit cache to avoid repeating expensive PDF conversions.

Implemented:

- Same PDF SHA-256 + same conversion rules can reuse a fresh completed `docparser_parse` task.
- Default cache TTL is `AIHUB_DOCPARSER_CACHE_TTL_DAYS=7`.
- Cache version is `AIHUB_DOCPARSER_CACHE_VERSION=docparser-v0.1`.
- Cache hit response returns `cached=true`, `cache_hit_task_id`, and the normal task follow-up URLs.
- Cache reuse requires the registered DocParser artifacts to still exist on disk.

Verified:

- `php scripts/run_tests.php` PASS with DocParser cache coverage.

## DocParser GPU / VRAM Stability Fix

Investigated document conversion failures on the RTX 5090 host.

Root cause:

- `structure-main` was GPU-capable and Paddle reported `device=gpu:0`; failures were caused by VRAM pressure, not CPU-only runtime.
- SAM3 / VoxCPM2 / external NatureID services were occupying most VRAM, leaving only about 1-2GB for PP-StructureV3 temporary allocations.
- Some 100MB+ PDFs were blocked by `structure-ppstructurev3` upload limit mismatch.
- TranslateGemma could hit Ollama CUDA OOM during DocParser translation when GPU memory was full.
- TranslateGemma model presence was verified through the Ollama sidecar and `scripts/ollama_model_pull.php`.

Changed:

- Raised `structure-ppstructurev3` upload contract/default from 100MB to 512MB.
- Added `OLLAMA_NUM_GPU` setting/env support to TranslateGemma adapter so document batches can use CPU fallback with `OLLAMA_NUM_GPU=0`.
- Fixed generated `.env` precedence so service settings override pack runtime defaults such as `STRUCTURE_DEVICE`.
- Improved backend error summaries so task failures include clipped backend messages such as Paddle `ResourceExhaustedError`.
- Updated the live host:
  - `structure-main`: `STRUCTURE_DEVICE=gpu`, `STRUCTURE_MAX_UPLOAD_MB=512`
  - `translate-main`: `OLLAMA_NUM_GPU=0`

Verified:

- `structure-main` container env reports `STRUCTURE_DEVICE=gpu`.
- Paddle reports `compiled_cuda=true` and `device=gpu:0`.
- Direct parse of failed 39MB / 335-page PDF returned HTTP 200 with `device=gpu`, `result_count=335`, `elapsed_ms=125204` after freeing 3waAIHub SAM3/VoxCPM2 VRAM.
- TranslateGemma real inference returned zh-TW text with `OLLAMA_NUM_GPU=0`.
- `ollama pull translategemma:12b-it-q4_K_M` verified the model is present in the Ollama volume.
- `php scripts/run_tests.php` PASS with 131 tests.

## PhaseDoc-1C DocParser L5 Benchmark Ready

Promoted `docparser` from L4 orchestrator delivery to L5 benchmark-ready.

Implemented:

- `runtime_level=L5-benchmark-ready`
- DocParser async submit benchmark cases:
  - `docparser_submit_pdf`
  - `docparser_submit_10page_pdf`
- Task result contract for DocIR / reader HTML / bilingual HTML / Markdown / TOC / RAG chunks / quality report / manifest artifacts.
- Figure crop contract with per-image `artifact_id`.
- Benchmark page and client docs now include DocParser L5 commands.
- Async submit benchmarks cancel their queued demo tasks after contract validation; full output quality remains covered by `docparser_acceptance.php --task-id=<SUCCESS_TASK_ID>`.

Verified:

- `php scripts/run_tests.php` PASS.

## DocParser Figure Artifact IDs

Exposed per-crop figure artifact IDs for downstream visual RAG.

Implemented:

- `artifact_summary.figure_assets.items[]` now includes:
  - `figure_id`
  - `block_id`
  - `page`
  - `bbox`
  - `caption`
  - `asset_path`
  - `artifact_id`
  - `bytes`
- `normalized/docir-v0.1.json` figures now include the registered `artifact_id`.
- Updated client quickstart and API examples.

## Task Worker Cron Enablement

Fixed queued async AI tasks not being consumed by the installed 1-minute worker loop.

Implemented:

- `crontab/1min.sh` now runs both:
  - runtime permission guard, calling `scripts/fix_permissions.sh` only when key `data/` directories or SQLite/WAL files need repair
  - `scripts/collect_host_metrics.php`
  - `scripts/command_worker.php`
  - `scripts/task_worker.php`
- Added `TASK_WORKER_LIMIT` support to the cron installer.
- Updated README worker / dashboard metrics description.

Reason:

- Docker/service background jobs used `command_worker.php`.
- DocParser / Structure async API tasks use `tasks` and require `task_worker.php`.

## DocParser Async API DX Fix

Fixed external DocParser integration discoverability after real cross-host testing.

Implemented:

- `task_submit` responses now include:
  - `status_url`
  - `result_url`
  - `log_url`
  - `artifact_url_template`
- Added DocParser multipart upload contract to `packs/docparser/pack.json`.
- Public API docs now show `docparser_parse`, `file=@manual.pdf`, and task status / result URLs.
- Client quickstart and API examples now document the async submit / poll / artifact flow.

Verified:

- DocParser PDF submit returns follow-up URLs.
- Public API docs render DocParser as multipart task API instead of empty JSON.
- `php scripts/run_tests.php` targeted checks PASS.

## PhaseM-4C PP-StructureV3 L5 Benchmark Ready

Promoted `structure-ppstructurev3` from L4 real inference to L5 benchmark-ready.

Implemented:

- Set `runtime_level=L5-benchmark-ready`.
- Added `/v1/parse` L5 contract.
- Added PDF benchmark cases:
  - `structure_page_pdf`
  - `structure_10page_pdf`
- Added small PDF fixtures under `packs/structure-ppstructurev3/demo/`.
- Updated generic benchmark runner to support multipart fixture field `file`.
- Updated API docs examples to use `file=@sample.pdf` for document parser contracts.

Verified:

- `structure_page_pdf` benchmark PASS:
  - result_count: 1
  - elapsed: about 10.8s
- `structure_10page_pdf` benchmark PASS:
  - result_count: 10
  - elapsed: about 10.8s
- Pack readiness: 11/11.

Skipped:

- DocParser pipeline changes.
- Viewer / overlay UI.
- Batch management UI.

## Structure-main GPU Runtime Enablement

Enabled GPU runtime for the existing `structure-main` PP-StructureV3 service on the RTX 5090 host.

Implemented:

- Switched `structure-ppstructurev3` Docker runtime from CPU PaddlePaddle to CUDA 12.9 PaddlePaddle GPU wheel.
- Pinned:
  - `paddleocr[doc-parser]==3.7.0`
  - `paddlepaddle-gpu==3.3.1` CUDA 12.9 wheel
  - `numpy>=1.24,<2.4`
- Updated build smoke and health reporting to accept both `paddlepaddle` and `paddlepaddle-gpu` package metadata.
- Updated the existing runtime instance:
  - `STRUCTURE_DEVICE=gpu`
  - `GPU_VISIBLE_DEVICES=all`
  - generated compose `gpus: all`

Verified:

- Docker GPU device request present on `3waaihub-structure-main`.
- Paddle reports `compiled_cuda=true` and `device=gpu:0`.
- `PPStructureV3(device="gpu")` initializes on RTX 5090 / compute capability 12.0.
- `GET /health` returns ready with PaddlePaddle `3.3.1`.
- NSR manual page 1 real parse PASS on GPU:
  - first warm-up parse: about 22.7s
  - warm parse: about 0.3s
- NSR manual pages 1-10 real parse PASS on GPU:
  - result_count: 10
  - elapsed: about 3.3s
  - markdown output: 4285 chars

Note:

- The previous CUDA 12.6 Paddle wheel could import, but model load failed with `Unsupported GPU architecture` because it did not include SM120 support.
- New installs still default `STRUCTURE_DEVICE=cpu`; switch service settings to GPU only on compatible hosts.

## PhaseDoc-1A DocParser Orchestrator L4

Added the DocParser technical manual PDF complete delivery plan and implementation.

Implemented:

- `docparser` internal async HubPack.
- `docparser_parse` task type.
- PDF intake through task_submit.
- Structure service orchestration.
- Block-level translation alignment.
- DocIR v0.1.
- Reader HTML, bilingual HTML, Markdown, TOC, RAG chunks, manifest and quality report artifacts.
- Golden acceptance CLI.

Skipped:

- MinerU engine.
- Image OCR overlay.
- Technical drawing understanding.
- VLM reviewer.
- Manual correction UI.

## PhaseM-4B PP-StructureV3 L4 Real Inference Pipeline

Promoted `structure-ppstructurev3` from L3 storage/mock to L4 real inference.

Implemented:

- Set `runtime_level=L4-real-inference`.
- Pinned PP-StructureV3 runtime dependencies:
  - `paddleocr[doc-parser]==3.7.0`
  - `paddlepaddle==3.2.0`
- Added lazy `PPStructureV3` adapter for `POST /v1/parse`.
- Added health dependency reporting for PaddleOCR / PaddlePaddle.
- Kept model/cache/service data mounted outside the image.
- Added `structure_parse` task type.
- Added PDF / document image upload storage under `data/uploads/tasks/task_{id}/`.
- Added task worker path that calls `structure-main` and stores Markdown / JSON artifacts under `data/results/task_{id}/`.
- Added tests for L4 manifest, version pins, task allowlist, and artifact registration.

Skipped:

- L5 benchmark-ready contract.
- GPU tuning.
- Viewer / overlay UI.
- Batch management UI.

## PhaseM-4A PP-StructureV3 L3 Storage Mock Pack

Added a PP-StructureV3 document parsing HubPack entry without enabling real parsing yet.

Implemented:

- Added `structure-ppstructurev3` to Local HubPack Catalog.
- Added L3 mock/storage service files:
  - `Dockerfile`
  - `requirements.txt`
  - `app.py`
  - `smoke.py`
  - `storage_smoke.py`
- Added `POST /v1/parse` mock contract for PDF / document image uploads.
- Added `GET /health` storage readiness.
- Generated service env/compose support for:
  - `/models/ppstructurev3`
  - `/cache/ppstructurev3`
  - `/data/service`
- Added `mode=structure` support to API Playground.

Skipped:

- Real PP-StructureV3 inference.
- PaddleOCR version pinning.
- PDF async task pipeline.
- Markdown/JSON artifact workflow.

## PhaseDX-3.1 Public API Docs Open Access

Changed public API docs and Agent Manifest defaults to open access.

Implemented:

- Default `AIHUB_PUBLIC_API_DOCS=1`.
- Default `AIHUB_PUBLIC_API_MANIFEST=1`.
- Default `AIHUB_PUBLIC_API_LOCAL_ONLY=0`.
- Settings copy now clarifies that public docs are only API contracts and API calls still require Bearer Token.
- Kept `admin/api_docs.php` login-protected and gateway token auth unchanged.

Skipped:

- Public Playground.
- Admin docs open access.
- Gateway auth changes.

## PhaseDX-3.1 Home Public API Docs Links

Added public integration entry points to the root home page and dashboard.

Implemented:

- Root `index.php` now links to `public_api_docs.php`, `api_manifest.json.php`, and `admin/`.
- Root home shows a short setting-aware hint for public docs / manifest availability.
- Dashboard quick links now include `admin/api_docs.php`, public API docs, and Agent Manifest.
- Kept public docs permissions, admin API docs login, gateway, runtime, and token auth unchanged.

Skipped:

- Public playground.
- Permission logic changes.
- Dashboard redesign.

## PhaseUI-6.2 Dashboard Public Integration Status

Added DX-3 public integration visibility to the dashboard.

Implemented:

- Dashboard now shows `介接公開狀態` for public API docs, Agent Manifest, and local-only policy.
- Added quick links to `public_api_docs.php` and `api_manifest.json.php`.
- Kept metrics collector, gateway, runtime, and admin auth unchanged.

Skipped:

- Dashboard redesign.
- New charts.
- Public playground.

## PhaseDX-3 Public API Docs / Agent Manifest

Added unauthenticated API integration docs and a machine-readable agent manifest while keeping admin API docs protected.

Implemented:

- Added `public_api_docs.php` for public-facing integration docs.
- Added `api_manifest.json.php` for AI agent / Codex / MCP machine-readable API contracts.
- Added shared `app/public_api_docs.php` helpers to generate docs and manifest from HubPack `l5_contract`, gateway metadata, and `pack.json`.
- Added settings:
  - `AIHUB_PUBLIC_API_DOCS=0`
  - `AIHUB_PUBLIC_API_MANIFEST=1`
  - `AIHUB_PUBLIC_API_LOCAL_ONLY=1`
- Added API settings UI for public docs, public manifest, and local-only policy.
- Kept `admin/api_docs.php` behind admin login.
- Public docs / manifest use `<TOKEN>` placeholders and avoid admin links, local ports, Docker paths, model host paths, log paths, SQLite paths, and plaintext tokens.

Skipped:

- OpenAPI generator.
- SDK package.
- Public playground.
- Token auto-listing.
- Gateway/runtime changes.

## PhaseM-2D-L5.1 SAM3 Mask Geometry Output

Added optional SAM3 mask geometry output without adding a viewer or artifacts.

Implemented:

- `/segment/image` now accepts `output_format=metadata|polygon|rle|both`.
- Default output remains `metadata` to keep existing benchmarks small.
- Real inference masks can include `polygon` and raw uncompressed RLE `rle`.
- `prompt_type=points` now validates `points_json={"points":[[x,y]],"labels":[1]}`.
- Playground adds `points_json` and `output_format` controls for SAM3.
- API Docs and examples include polygon / points prompt curl examples.
- Added `sam3_real_polygon_image` benchmark case.
- Added `geometry.py` and `geometry_smoke.py` for mask geometry helpers.

Skipped:

- Mask PNG artifacts.
- Overlay viewer.
- Batch/video segmentation.
- COCO compressed RLE / pycocotools.

## PhaseDX-2.3 API Docs Current Host and Real Inference UX

Polished API onboarding examples and Playground defaults.

Implemented:

- `admin/api_docs.php` now generates API example URLs from the current admin request host.
- API Docs examples no longer hardcode `http://localhost/3waAIHub/...`.
- Playground `real_inference` checkbox is checked by default.
- Playground `real_inference` label now displays as `真實推論`.
- Added regression coverage for API Docs URL generation and Playground default real inference UI.

Skipped:

- Static `docs/api_examples.md` URL rewrite.
- Gateway/runtime changes.
- Token/auth behavior changes.

## PhaseDX-2.2 Playground Local Gateway Execution

Fixed API Playground server-side execution after current-host examples were added.

Root cause:

- The generated examples correctly used the current public host.
- The server-side Playground test reused the same URL, so public deployments could timeout when the host called its own public domain.

Implemented:

- Added `hub_playground_local_api_url()` for Playground execution.
- `hub_playground_execute()` now calls local `127.0.0.1` gateway.
- Generated curl / PHP / JS fetch examples still use the current page host.
- Added regression coverage for public example URLs vs local execution URL.

Skipped:

- Gateway rewrite.
- Direct PHP dispatch.
- Token/auth behavior changes.

## PhaseDX-2.1 Playground Current Host Examples

Updated API Playground examples to use the current request host instead of hardcoded `localhost`.

Implemented:

- `curl`, PHP, and JS fetch examples now reuse `hub_playground_api_url()`.
- Examples render as `http://localhost/...` locally and `https://nature.focusit.tw/...` behind the public host.
- Added a regression test for current-host example generation.

## PhaseUI-6.1 Dashboard Health Summary

Added service health truthfulness to the dashboard without changing collectors or schemas.

Implemented:

- Dashboard summary cards now include `健康正常` and `健康異常 / 未檢查`.
- Health counts are derived from the latest `service_health_check` command job per service.
- Storage text now includes `/ disk free` alongside Docker root and Models Root free space.

Skipped:

- metrics collector changes.
- new health persistence schema.
- chart redesign / SPA work.

## PhaseUI-4.1 Service Status Truthfulness / Playground Readiness Guard

Made service status labels more explicit without adding a new health schema.

Implemented:

- Split service cards into enabled, container, health, config, and last job status.
- Health status is derived from the latest `service_health_check` command job.
- Unknown health now renders as `健康未檢查`; failed health renders as `健康異常`.
- Service AJAX polling updates container status, health status, and last job summary.
- Auto health refresh after start/restart/build/rebuild now excludes stop jobs.
- Playground checks selected service enabled/running state before executing.
- Playground performs a short `health_url` check before API execution and reports health failure instead of waiting for timeout.
- Playground error messages now map common request failures to Chinese explanations.

Skipped:

- new DB health status schema.
- command worker rewrite.
- gateway/token auth changes.
- SPA work.

## PhaseUI-6 Dashboard Control Center Polish

Tightened the admin dashboard into a clearer first-screen control center.

Implemented:

- Localized dashboard summary cards for service totals, running services, disabled services, API 24h calls, API 24h failures, active background jobs, and recent failed jobs.
- Added an explicit L5 Pack count card.
- Added quick links for HubPack 套件 and Log Explorer.
- Added Docker root free and Models Root free / total text to the storage section.
- Updated recent failed API links to open Log Explorer `tab=api`.

Skipped:

- metrics collector changes.
- large chart redesign.
- SPA work.
- runtime / gateway / worker changes.

## PhaseUI-5 Log Explorer Tabs / Background Jobs

Consolidated operational logs into a single Log Explorer entry point.

Implemented:

- Added `admin/log_explorer.php` tabs for API 記錄, 背景工作, 服務記錄, and 系統記錄.
- Kept existing API access log filters as the default `tab=api` behavior.
- Added `tab=jobs` command job filters for status, action, service, keyword, and time range.
- Added command job rows with localized status/action labels, service name/key, progress, stage, requested info, error summary, and bounded stdout/stderr tail.
- Removed the full recent background jobs history table from `admin/services.php`.
- Added service-level job links from service cards to Log Explorer.
- Kept services page focused on current status, actions, and the latest/current job summary.
- Added PhaseUI-5 log explorer tab tests.

Skipped:

- Large log viewer.
- log streaming / SSE.
- command worker changes.
- API access schema changes.
- runtime / gateway changes.

## PhaseUI-5 Dashboard Control Center Polish

Polished the admin dashboard into a clearer control center.

Implemented:

- Added dashboard summary cards for services, API calls last 24h, failed API calls last 24h, background jobs, Pack readiness, and model storage usage.
- Added quick links to services, API Playground, models, and API keys.
- Added Recent command jobs and recent failed API request summaries.
- Kept the existing host metrics snapshot and ECharts flow.
- Added PhaseUI-5 dashboard render contract test.

Skipped:

- SPA rewrite.
- ECharts rewrite.
- metrics collector changes.
- runtime / gateway changes.
- new Pack work.

## PhaseUI-4 Services Management Card UI

Redesigned the services management page while preserving the existing AJAX command flow.

Implemented:

- Added service summary cards for total, running, stopped, disabled, active jobs, and failed jobs.
- Replaced the wide services table with service cards.
- Localized primary service actions: start, stop, restart, build, rebuild, and refresh/health check.
- Kept PhaseUI-1 AJAX hooks: `data-service-row-id`, `data-service-status`, `data-service-refresh-form`, and service job polling.
- Added clearer API entry display and links to API Playground by `mode`.
- Moved service-level whitelist out of primary actions as `舊版 IP 白名單`.
- Added command action labels for job rows and AJAX-prepended jobs.

Skipped:

- SPA rewrite.
- command worker changes.
- gateway changes.
- service whitelist backend removal.
- token/member permission model changes.

## PhaseDX-2 API Client Onboarding Polish

Improved the API Playground for first-time client integration.

Implemented:

- Playground now shows a Bearer token hint and API key creation link.
- Added Authorization header example with `<TOKEN>` placeholder.
- Added show/hide token toggle.
- Added copy buttons for Authorization header, curl, PHP, and JS fetch examples.
- Added quick links from the selected service to API docs, benchmarks, readiness, and API logs.
- `request_id` links continue to open Log Explorer without requiring a matching log row.
- README and API examples now document the onboarding flow.

Skipped:

- SDK package.
- Saved token storage.
- Token dropdown with plaintext tokens.
- Public playground.
- Gateway / token auth rewrite.
- Request replay DB.
- Streaming / batch workflow.

## PhaseDX-1 API Integration Playground

Added the first admin-side API integration playground.

Implemented:

- Added `admin/playground.php`.
- Admin nav now links to `API 測試場`.
- Playground can select installed service modes for `hello`, `translate`, `ocr`, `yolo`, and `sam3`.
- Requests are executed server-side against local `api.php?mode=...`.
- Bearer token is accepted only for the current request, masked after submit, and never saved.
- Response panel shows HTTP status, `elapsed_ms`, `request_id`, formatted JSON, and a log explorer link.
- Generated curl / PHP / JS fetch examples use `<TOKEN>` placeholders.
- Added PhaseDX-1 UI contract tests.

Skipped:

- Public playground.
- Saved tokens.
- SDK package.
- Batch / streaming workflow.
- Gateway / runtime / worker changes.

## PhaseUI-3 Marketplace / Models Card UI

Aligned `admin/marketplace.php` and `admin/models.php` with the HubPack card-style admin UI.

Implemented:

- Marketplace now uses card layout for local HubPacks.
- Marketplace keeps the existing Install as Service form and POST flow.
- Marketplace cards show `pack_id`, version, type, `runtime_level`, `target_level`, default `mode`, API `endpoint`, `execution_type`, GPU/model requirement, installed count, and install status.
- Models page now shows card sections for Models Root, disk usage, linked services, common model subdirectories, Model Inventory, and Create Subdir.
- Added common lightweight admin UI classes: `hub-card`, `hub-card-grid`, `hub-badge`, `hub-meta`, `hub-actions`, `hub-empty-state`, and `hub-section-title`.
- Added PhaseUI-3 UI contract tests and kept model inventory symlink behavior visible as `symlink skipped`.

Skipped:

- SPA / AJAX additions.
- i18n framework.
- Pack install wizard rewrite.
- Model upload / download / delete / move.
- Runtime / gateway / worker changes.
- Remote marketplace.

## PhaseUI-2 Admin Shell Localization / Brand Settings / Settings Tabs

Localized the admin shell and organized system settings without adding an i18n framework.

Implemented:

- Top navigation now uses Traditional Chinese labels while keeping technical values in English.
- Added `AIHUB_SITE_TITLE` and `AIHUB_SITE_SUBTITLE` settings.
- Top bar, login page, dashboard heading, and admin HTML title use the configurable site title.
- `admin/settings.php` now uses query-string tabs:
  - `basic`
  - `appearance`
  - `storage`
  - `api`
  - `docker`
  - `maintenance`
  - `account`
- Settings remain stored in the existing `settings` table.
- Main page titles were localized for services, HubPack, models, API docs, API usage, benchmarks, environment, and settings.

Skipped:

- i18n framework.
- language files.
- SPA / AJAX rewrite.
- Marketplace or Models card redesign.
- Gateway / runtime / worker changes.

## PhaseUI-1 Admin AJAX Micro Interactions

Improved the existing admin AJAX flow without turning the backend into a SPA.

Implemented:

- `admin/services.php` service rows now expose stable `data-service-*` hooks.
- `job_status.php` payload now includes command action, localized job status, and latest service status.
- `assets/js/services.js` updates service status cells and command job rows during polling.
- Finished service jobs enqueue one follow-up health/status refresh job, except for health-check jobs themselves.
- Polling and action failures keep a visible error message instead of failing silently.
- `admin/packs.php` exposes a lightweight `ajax=readiness` JSON branch.
- `assets/js/packs.js` refreshes Pack readiness text in place.

Skipped:

- SPA rewrite.
- command worker changes.
- runtime changes.
- install wizard changes.

## PhaseUI-0 Pack Catalog Tabs + Localization

Upgraded `admin/packs.php` from a single long table into a localized Pack Catalog view.

Implemented:

- Query-string tabs: `all`, `reference`, `vision`, `language`, `audio`, `utility`, `experimental`.
- Traditional Chinese tab labels and card labels while keeping technical values such as `pack_id`, `mode`, `endpoint`, `runtime_level`, and `execution_type` in English.
- Card layout showing runtime level, target level, endpoint, GPU/model requirement, installed service count, modes, and L5 readiness.
- Reference Pack callout for `hello`.
- Empty tab states in Traditional Chinese.
- Links for API docs, Benchmark, Readiness, installed services, settings, logs, and health check entry points.
- UI contract test for localized tabs, card render, helper labels, and pack tab classification.

Skipped:

- i18n framework.
- AJAX.
- Pack install wizard rewrite.
- Runtime / gateway / benchmark logic changes.

## PhaseM-2D-L5 SAM3 Benchmark Ready

Promoted `sam3` from L4b real-inference smoke to L5 benchmark-ready.

Implemented:

- `runtime_level = L5-benchmark-ready`.
- Added SAM3 `l5_contract`.
- Added `sam3_mock_image` and `sam3_real_image` benchmark cases.
- Benchmark contract checks mask list, positive elapsed time, and model checkpoint presence without asserting mask count.
- API Docs and API examples now include SAM3 mock and real curl examples.

Updated:

- SAM3 and YOLO benchmark fixtures now use `camera_cat.png`, a real 1280x720 camera frame.

Skipped:

- Mask PNG artifact.
- RLE / polygon output.
- Mask viewer.
- Batch or video segmentation.
- Interactive prompt UI.
- Quality tuning.

## PhaseM-2D-L4b SAM3 Real Inference Smoke

Advanced `sam3` from L4a model-present smoke to L4b real-inference smoke.

Implemented:

- `runtime_level = L4b-real-inference-smoke`.
- Added `ultralytics` runtime dependency import smoke.
- Added lightweight runtime dependency status to `/health`.
- Added safe checkpoint loadability guard so tiny fake smoke files are not treated as inference-ready.
- Added `inference_smoke.py` for single-image `real_inference=1` validation.
- Kept mock mode as the default `/segment/image` behavior.
- Added fixed error code contract for model/runtime/image/inference failures.

Verified:

- SAM3 Docker build PASS after moving containerd data root off `/`.
- `sam3-main` start PASS.
- `/health` PASS with `/models/sam3/sam3.pt`.
- `model_smoke.py` PASS and selects the real checkpoint instead of `sam3-smoke.pt`.
- `inference_smoke.py` PASS with `mock=false`.
- Gateway `api.php?mode=sam3` real inference smoke PASS.

Skipped:

- L5 benchmark-ready promotion.
- Mask artifact output.
- RLE / polygon export.
- Batch or video segmentation.
- Advanced prompt UI.

## Host Maintenance: containerd Root Move

Moved containerd state from `/var/lib/containerd` to `/DATA/containerd` so heavy Docker builds do not fill the root filesystem.

Recorded:

- Docker Root Dir remains `/DATA/docker`.
- containerd root is now `/DATA/containerd`.
- Previous containerd data kept at `/DATA/containerd.varlib.bak.20260707_092343`.
- containerd config backup: `/etc/containerd/config.toml.bak.20260707_092343`.
- Install log: `data/logs/install/move_containerd_root_20260707_092343.log`.

Verified:

- `containerd` active.
- `docker` active.
- Running containers restored.
- `/` free space recovered to about 68G.

## PhaseM-0 Hello L5 Reference Pack

Promoted `hello` to the minimal L5 reference HubPack.

Implemented:

- `runtime_level = L5-benchmark-ready`.
- `target_level = L5-benchmark-ready`.
- `role = reference`.
- Added `l5_contract` for `GET /`.
- Added `hello_api` L5 benchmark case.
- Pack Readiness can report `hello` as 11/11 after `hello_api` PASS.
- `admin/packs.php` marks reference packs.
- `admin/api_docs.php` renders GET contract examples without fake upload fields.

Skipped:

- Docker runtime changes.
- New features.
- GPU/model/storage behavior.

## PhaseM-3A Whisper ASR L3 Storage Mount

Added `whisper-asr` as the first audio-to-text HubPack.

Base:

- Previous commit: `9dad562 feat: add SAM3 model present smoke`.
- Previous tag: `phase-m2d-sam3-l4a-model-smoke-v0.1.0`.

Implemented:

- Added `whisper-asr` to local catalog.
- Added Pack manifest with `runtime_level = L3-storage-mount`.
- Added `/health`.
- Added `POST /asr/audio` mock transcription endpoint.
- Added `smoke.py` for FastAPI / faster-whisper import only.
- Added `storage_smoke.py`.
- Added demo `sample.wav`.
- Added storage mounts for `/models/whisper`, `/cache/whisper`, and `/data/service`.
- Added gateway mode `asr` through service instance install.

Verified:

- `asr-main` Docker build PASS.
- `asr-main` start PASS.
- `GET /health` PASS.
- `storage_smoke.py` PASS.
- Direct `POST /asr/audio` mock PASS.
- Gateway `api.php?mode=asr` mock PASS.
- `api.php?mode=hello` PASS.
- `php scripts/run_tests.php` PASS.
- `php scripts/self_check.php` PASS.
- `php scripts/token_api_smoke.php` PASS.
- PHP lint / shell syntax / Python compile / `git diff --check` PASS.

Skipped:

- Real transcription.
- Model download/load.
- GPU runtime.
- VAD.
- Diarization.
- Subtitle export.
- Streaming.

## PhaseM-2D-L4a SAM3 Model Present Smoke

Advanced `sam3` from L3 storage mount to L4a model-present smoke.

Implemented:

- `runtime_level = L4a-model-present-smoke`.
- `/health` model-present section with `present`, `checkpoint`, `source`, and `candidates_count`.
- Missing model reports `ready=false` with `model_not_present` warning.
- Safe `SAM3_CHECKPOINT` resolution under `/models/sam3` only.
- Recursive checkpoint scan for `.pt`, `.pth`, `.safetensors`, and `.ckpt`.
- `model_smoke.py` for checkpoint presence validation without torch/SAM3 import.
- Docker image now copies `model_smoke.py`.

Verified:

- `sam3-main` Docker build PASS.
- `sam3-main` start PASS.
- `GET /health` PASS with model present from `/models/sam3/sam3-smoke.pt`.
- `model_smoke.py` PASS.
- Direct `/segment/image` mock PASS.
- Gateway `api.php?mode=sam3` mock PASS.
- `real_inference=1` still returns `runtime_not_ready`.
- `php scripts/run_tests.php` PASS.
- `php scripts/self_check.php` PASS.
- `php scripts/token_api_smoke.php` PASS.
- PHP lint / shell syntax / Python compile / `git diff --check` PASS.

Skipped:

- Checkpoint download.
- HuggingFace pull.
- Torch / SAM3 import.
- Real segmentation.
- Mask generation.
- L4b / L5 promotion.

## PhaseM-2A-GPU OCR GPU Runtime Stabilization

Stabilized `ocr-ppocrv5` CPU/GPU service behavior.

Implemented:

- Added OCR GPU settings: `OCR_DEVICE`, `GPU_VISIBLE_DEVICES`, `OCR_GPU_FALLBACK_TO_CPU`, `OCR_GPU_REQUIRED`.
- Split generated compose behavior: CPU OCR instances do not force GPU; `ocr-gpu` style instances request `gpus: all`.
- Added `/health` GPU diagnostics and requested/effective device reporting.
- Added `device` to OCR mock and real inference responses.
- Added `gpu_smoke.py` for Paddle CUDA availability checks without model download or inference.
- Added benchmark result device fields for L5 OCR contract cases.
- Regenerated local `ocr-main` / `ocr-gpu` runtime files.

Verified:

- `ocr-gpu` Docker build PASS.
- `ocr-gpu` start PASS.
- `GET /health` PASS.
- Direct `POST /ocr/image` mock PASS.
- Direct `POST /ocr/image real_inference=1` PASS with CPU fallback.
- `api.php?mode=ocr_gpu` gateway PASS.
- `api.php?mode=hello` PASS.
- `php scripts/run_tests.php` PASS.
- `php scripts/self_check.php` PASS.
- `git diff --check` PASS.

Known:

- NVIDIA runtime is visible, but current `paddlepaddle` package is CPU build, so OCR GPU requests fallback to CPU unless `OCR_GPU_REQUIRED=1`.

## PhaseM-2D SAM3 L3 Storage Mount

Advanced `sam3` from L1 skeleton to L3 storage-mount runtime.

Implemented:

- `runtime_level = L3-storage-mount`.
- SAM3 adapter dependency import smoke.
- SAM3 model/cache/service_data storage mounts.
- HuggingFace and Torch cache locations under `/models/sam3`.
- Runtime cache locations under `/cache/sam3`.
- `/health` storage checks.
- `/segment/image` mock segmentation JSON.
- `real_inference=1` returns `runtime_not_ready`.
- `storage_smoke.py`.
- `SAM3_CHECKPOINT` model selector for `/DATA/models/sam3`.
- Tiny demo PNG fixture.

Skipped:

- Checkpoint download.
- HuggingFace model pull.
- SAM3 real inference.
- Mask generation.
- Batch or video segmentation.
- L4a model-present smoke.
- L5 benchmark-ready promotion.

## PhaseM-2C-L5 TranslateGemma Benchmark Ready

Promoted `translate-gemma12b` to L5 benchmark-ready.

Implemented:

- `runtime_level = L5-benchmark-ready`.
- Added TranslateGemma `l5_contract`.
- Added `translate_mock_text` benchmark case.
- Added `translate_real_text` benchmark case.
- Extended L5 benchmark runner to support JSON request bodies.
- API docs now show TranslateGemma mock and real inference curl examples.
- Benchmark page now lists TranslateGemma benchmark commands.
- Mock translation response now includes `elapsed_ms`.

Skipped:

- Streaming.
- Chat / multi-turn translation.
- Batch translation.
- File translation.
- Glossary / termbase.
- Keep warm UI.

## PhaseM-2C-L4b TranslateGemma Real Translation

Advanced `translate-gemma12b` to L4b real translation.

Implemented:

- `runtime_level = L4b-real-translation`.
- `/translate` keeps mock mode by default.
- `/translate real_inference=1` calls Ollama `/api/generate` with `stream=false`.
- Added prompt template with source/target language and Taiwan Traditional Chinese preference for `zh-TW`.
- Added non-streaming response normalization.
- Added stable real inference error codes: `ollama_unavailable`, `model_not_present`, `input_too_long`, `ollama_timeout`, `ollama_bad_response`, and `translation_failed`.
- Added `inference_smoke.py` for real translation smoke.
- Fixed `self_check.php` runtime path assertions for temp DB test services.

Skipped:

- Streaming.
- Chat / multi-turn translation.
- Batch translation.
- File translation.
- Keep warm UI.
- Benchmark-ready promotion.

## PhaseM-2C-L4a TranslateGemma Ollama Model Smoke

Advanced `translate-gemma12b` to L4a model-present smoke.

Implemented:

- `runtime_level = L4a-model-present-smoke`.
- Added `scripts/ollama_model_pull.php` for explicit CLI model pull.
- Added `ollama_model_pull` command worker action.
- Added `model_smoke.py` to check Ollama `/api/tags` for `OLLAMA_MODEL`.
- `/health` now reports model name, present status, and `model_not_present` warning.
- Service settings can enqueue model pull through `command_jobs`.
- Ollama tag selector can show manifest present/missing status under `/DATA/models/ollama`.

Skipped:

- `/api/generate`.
- Real translation.
- Streaming.
- Keep warm.
- Benchmark-ready promotion.

## PhaseP-3 Model Registry / Models Directory UI

Added a read-only model registry for host-level model assets.

Implemented:

- Added `admin/models.php`.
- Added `app/model_registry.php`.
- Models root stays controlled by `AIHUB_MODELS_DIR`.
- Scanner only walks under the models root.
- Path traversal is rejected.
- Symlinks are listed as skipped and not followed.
- Common model subdirs are shown: `paddleocr`, `yolo`, `ollama`, `sam3`, `huggingface`.
- `YOLO_MODEL` has a model selector for `/DATA/models/yolo/*.pt` / `*.onnx`.
- `OLLAMA_MODEL` remains a tag text field with `/DATA/models/ollama` status.
- Service settings show model root and selected model exists/missing status.

Verified:

- `php scripts/run_tests.php` PASS.
- `php scripts/self_check.php` PASS.
- `php scripts/token_api_smoke.php` PASS.
- PHP lint PASS.
- `bash -n` and `git diff --check` PASS.
- Scan sees `/DATA/models`, `yolo/yolo11n.pt`, and `paddleocr/home/.paddlex`.
- YOLO model selector exposes `yolo11n.pt`.
- OCR mock / real PASS.
- YOLO mock / real benchmarks PASS.
- `api.php?mode=hello` PASS.

Skipped:

- Model upload.
- Model download.
- Model delete.
- Model move.
- Ollama pull UI.
- Arbitrary host path picker.
- Symlink-following scan.

## PaddleOCR Model Location Normalization

Moved PaddleOCR / PaddleX model-home writes to host-level model storage.

Implemented:

- Generated OCR env now sets `HOME=/models/paddleocr/home`.
- Kept `XDG_CACHE_HOME=/cache/paddleocr/xdg` for framework cache.
- Kept `PADDLEOCR_HOME=/models/paddleocr`.
- Updated `model_smoke.py` and OCR app fallback to use the same model-home path.

Verified:

- `model_smoke.py` created `/DATA/models/paddleocr/home/.paddlex`.
- `/root` and `/app` did not receive new files during model smoke.
- OCR mock gateway PASS.
- OCR real gateway PASS.
- OCR mock / real benchmarks PASS.

Skipped:

- Deleting old `/DATA/3waAIHub/data/cache/paddleocr/home/.paddlex`.

## PhaseM-2C-L1-L3 TranslateGemma Ollama Adapter / Storage Mount

Advanced `translate-gemma12b` to L3 storage-mount runtime.

Implemented:

- `runtime_level = L3-storage-mount`.
- Split runtime into `ollama` sidecar and `translator-api` FastAPI adapter.
- Mounted `${AIHUB_MODELS_DIR}/ollama` into Ollama `/root/.ollama`.
- Mounted translator cache and service data into `/cache/translate` and `/data/service`.
- Added adapter import smoke for `fastapi` / `requests`.
- Added translator storage smoke.
- `/health` checks Ollama `/api/tags` and adapter storage without exposing Ollama host port.
- `/translate` remains mock JSON by default.
- Real inference requests return `runtime_not_ready`.

Verified:

- `php scripts/run_tests.php` PASS.
- `php scripts/self_check.php` PASS.
- `php scripts/token_api_smoke.php` PASS.
- Docker build PASS with `smoke.py` importing adapter routes.
- `translate-main` start PASS with `ollama` and `translator-api` containers.
- Direct `/health` PASS with `runtime_level=L3-storage-mount`.
- Direct `/translate` mock PASS.
- Direct real inference request returns `runtime_not_ready`.
- Gateway `api.php?mode=translate` mock PASS.
- Translator `storage_smoke.py` PASS.
- Ollama `/root/.ollama` mount writable.
- OCR and YOLO mock / real benchmark regressions PASS.

Skipped:

- Model pull.
- Model initialization.
- Real translation.
- Keep-warm loading.
- Streaming.
- Benchmark-ready promotion.

## Storage Policy: Host-level Models Directory

Moved the default model storage policy to host-level storage.

Implemented:

- Default `AIHUB_MODELS_DIR=/DATA/models`.
- Kept cache/uploads/results/logs under `/DATA/3waAIHub/data`.
- `install.sh` creates `/DATA/models` plus `paddleocr`, `yolo`, `ollama`, and `sam3` subdirectories when permitted.
- `.env.example` and README document `/DATA/models` as the model asset home.
- `self_check.php` warns when an existing install still points `AIHUB_MODELS_DIR` at the project data directory.
- Pack compose fallbacks continue to use `AIHUB_MODELS_DIR` instead of hardcoding repo-local model storage.

Skipped:

- Automatic model migration.
- Docker data-root changes.
- Cache/results/uploads default relocation.

## 2026-07-06

Initialized 3waAIHub Local MVP.

Scope locked to the first runnable loop:

- `install.sh`
- SQLite database
- Admin login with `admin / admin123`
- Password change page
- Services page
- `hello-service` HubPack
- Docker start / stop / restart
- Service log view
- `api.php?mode=hello` gateway proxy

Deferred on purpose:

- SAM3 / OCR / translate / OpenMVS / 3DGS packs
- Service CRUD
- API keys
- IP whitelist UI
- Job queue
- Benchmarking
- Skill registry

## 2026-07-06 PhaseB

Added local host-control hardening.

- Web admin service actions now enqueue `command_jobs` instead of executing Docker directly.
- Added `scripts/command_worker.php` for CLI/crontab execution of allowlisted actions.
- Added job stdout/stderr files under `data/logs/jobs/`.
- Added cached environment diagnostics with `env_snapshots` and `admin/environment.php`.
- Added `scripts/fix_permissions.sh` using `775` directories and `664` files, without `chmod 777`.
- Updated `install.sh` for root/non-root setup behavior and Docker security warnings.
- Added local Docker port policy fields: `local_port`, `port_mode`, `hot_reload`, `environment`.
- Updated hello compose binding to `127.0.0.1:${HELLO_LOCAL_PORT:-18100}:8000`.

Still deferred:

- SAM3 / OCR / Translate / OpenMVS / 3DGS
- Multi-node Station
- Cloud deployment / Kubernetes
- Marketplace
- Complex RBAC

## 2026-07-06 PhaseA.1

Standardized fresh-host bootstrap.

- `./install.sh` remains app-only and does not install system packages.
- Added `./install.sh --check` for read-only environment status.
- Added root-only `--bootstrap-host --with-docker` and `--bootstrap-host --with-nvidia`.
- Added `scripts/install_docker_ubuntu.sh` using Docker official apt repository, not snap.
- Added `scripts/install_nvidia_container_toolkit.sh` using NVIDIA Container Toolkit repository.
- NVIDIA bootstrap requires existing `nvidia-smi` and does not install drivers.
- Docker daemon config is backed up before `nvidia-ctk runtime configure`.
- Bootstrap logs are written under `data/logs/install/`.
- Added GPU smoke fallback from CUDA `12.9.0` image to `12.6.3` image.

Still deferred:

- Additional PhaseB web/worker expansion
- SAM3 / OCR / Translate
- NVIDIA driver installation

## 2026-07-06 Task Queue Phase 1

Added the user-facing task execution contract.

- Kept `hello` as `sync_api`.
- Added `services.execution_type`: `sync_api`, `async_task`, `long_job`.
- Kept `command_jobs` for internal host operations only.
- Added SQLite FIFO task queue tables: `tasks`, `task_logs`, `task_artifacts`.
- Added `scripts/task_worker.php --limit=5`.
- FIFO claim order is `priority DESC, created_at ASC`.
- Added task API modes: `task_submit`, `task_status`, `task_result`, `task_log`, `task_cancel`, `artifact`.
- First allowed `task_type` is only `demo_task`.
- Artifact endpoint only serves DB-registered files under `data/results/`.

Still deferred:

- Redis
- SSE
- SAM3 / OCR / OpenMVS / 3DGS
- Web UI
- Real long-job pipeline

## 2026-07-06 PhaseA.2

Added a single-file terminal-style entry page.

- Added `/index.php`.
- Shows `3waAIHub`, slogan, `Local MVP`, admin path, and hello gateway path.
- Links to `login.php`.
- Does not implement login, session, or auth logic.
- Does not expose SQLite path, server path, Docker socket, OS user, root status, or API keys.

## 2026-07-06 Login Captcha

Added a low-dependency admin login captcha.

- Login page now requires a session-backed 5-character captcha before password verification.
- Captcha refreshes after each login attempt.
- Existing admin auth/session flow remains unchanged.
- Login lives at `/login.php`; successful login redirects to `/admin/`.
- Runtime permissions can be fixed with `WEB_GROUP=www-data scripts/fix_permissions.sh`.
- `install.sh` now runs runtime permission normalization during app setup.
- Added root `.htaccess` to deny direct HTTP access to SQLite/runtime/internal files.
- Admin pages now use Chinese labels where practical.
- Environment diagnostics show human-readable MB/GB values and inline red reasons for failed checks.
- Fixed GPU probe false negative caused by querying unsupported `nvidia-smi` field `cuda_version`.
- Environment diagnostics now show command suggestions for Docker daemon permission failures.
- Services page now explains that Docker actions are executed by `command_worker.php` and shows a worker command when jobs are queued.
- Services page actions now submit through jQuery AJAX and prepend queued command jobs without a full page reload.
- Added `crontab/1min.sh` with `flock` protection for command worker cron execution.
- Added root-only `scripts/install_command_worker_cron.sh` to install `/etc/cron.d/3waaihub-command-worker`.
- `install.sh --check` now reports command worker cron status; root app install auto-installs the cron entry, while non-root install prints the root command.
- Environment diagnostics now show live command worker cron status even before a full worker-generated snapshot exists.

## 2026-07-06 PhaseC-1

Standardized the first HubPack flow.

- Added HubPack manifest schema v0.1 validation in `app/pack_registry.php`.
- Updated `packs/hello/pack.json` to the standard manifest shape.
- Added `admin/packs.php` to list available packs and install valid packs.
- Installing hello creates service instance `hello-main`.
- Generated runtime files now live under `data/services/hello-main/`.
- Hello service Docker operations now use `data/services/hello-main/docker-compose.generated.yml`.
- `api.php?mode=hello` remains a sync API gateway.

Still deferred:

- SAM3 / OCR / Translate / LLM
- OpenMVS / 3DGS
- Redis / marketplace / multi-node

## 2026-07-06 Host Maintenance

Moved Docker data-root from `/var/lib/docker` to `/DATA/docker`.

- Preserved Docker data with `rsync -aHAX --numeric-ids`.
- Backed up daemon config to `/etc/docker/daemon.json.bak.20260706_160821`.
- Preserved NVIDIA Container Toolkit runtime config.
- Renamed old Docker root to `/var/lib/docker.bak.20260706_160821`; not deleted.
- Docker hello-world PASS.
- NVIDIA GPU container `nvidia-smi` PASS with `nvidia/cuda:12.9.0-base-ubuntu22.04`.
- Operation log: `data/logs/install/move_docker_data_root_20260706_160821.log`.

## 2026-07-06 PhaseC-0

Storage Settings / Model Directory.

- Added global storage settings in SQLite.
- Added default models/cache/uploads/results/logs directories.
- Added `.env.example`.
- Added storage diagnostics.
- Added Docker Root Dir warning.

## PhaseM-1 HubPack Kit MVP

Completed Local HubPack Catalog and multi Service Instance model.

Added:

- `packs/catalog.json`
- `ocr-ppocrv5` HubPack manifest
- `translate-gemma12b` HubPack manifest
- `app/pack_registry.php`
- `admin/marketplace.php`

Implemented:

- HubPack as template
- HubService as installable instance
- multi instance installation from the same Pack
- service_key / mode / local_port uniqueness checks
- generated service runtime directory:
  - `data/services/{service_key}/.env`
  - `data/services/{service_key}/docker-compose.generated.yml`

Verified:

- `ocr-main` -> `mode=ocr`, `port=18101`
- `ocr-gpu` -> `mode=ocr_gpu`, `port=18103`
- `services.php` shows multiple instances
- `api.php?mode=hello` still works
- unknown mode returns 404
- duplicate service_key / mode / local_port checks pass
- PHP lint PASS
- `scripts/self_check.php` PASS
- `git diff --check` PASS

Skipped:

- remote marketplace
- Pack download/signature
- real OCR runtime
- real TranslateGemma runtime
- Redis/SSE
- multi-host Hub

## PhaseM-2A PP-OCRv5 Runtime Adapter L1

Completed `ocr-ppocrv5` L1 `api_mock` runtime.

Added:

- `packs/ocr-ppocrv5/service/Dockerfile`
- `packs/ocr-ppocrv5/service/requirements.txt`
- `packs/ocr-ppocrv5/service/app.py`

Implemented:

- `GET /health`
- `POST /ocr/image`
- mock OCR JSON response
- multipart upload forwarding in `api.php` gateway
- generated compose `env_file` support

Verified:

- Docker build `ocr-main` PASS
- Start `ocr-main` PASS
- `GET http://127.0.0.1:18101/health` PASS
- `POST http://127.0.0.1:18101/ocr/image` PASS
- `POST api.php?mode=ocr` PASS
- `api.php?mode=hello` still works

Skipped:

- PaddleOCR install
- model download
- real OCR inference
- PDF OCR
- async task
- benchmark

## PhaseD-0 Dashboard Metrics / ECharts Host Monitor

Added first host dashboard snapshot flow.

Added:

- `host_metric_snapshots` SQLite table
- `app/host_metrics.php`
- `scripts/collect_host_metrics.php`
- ECharts dashboard in `admin/index.php`

Implemented:

- CLI-only metrics collection
- GPU / VRAM / temperature snapshot
- host load / RAM / disk snapshot
- Docker availability / Docker root snapshot
- pack / service / task / command job counts
- dashboard cards and charts reading SQLite only

Skipped:

- Prometheus
- Grafana
- SSE realtime streaming
- multi-host monitoring
- web request shell execution
- automatic host repair

## PhaseM-1.1 Unit Test / Benchmark / API Examples

Added basic HubPack Kit verification framework.

Added:

- `scripts/run_tests.php`
- `tests/test_pack_registry.php`
- `tests/test_service_instance.php`
- `tests/test_gateway.php`
- `tests/test_api_examples.php`
- `scripts/benchmark.php`
- `admin/benchmarks.php`
- `admin/api_docs.php`
- `docs/api_examples.md`

Implemented:

- test DB override with `AIHUB_TEST_DB`
- catalog / pack manifest checks
- service_key / mode / local_port collision tests
- hello gateway and unknown mode tests
- `benchmark_runs` SQLite table
- benchmark cases: `host_smoke`, `pack_catalog_scan`, `hello_api`
- API examples for hello / OCR / Translate / unknown mode

Skipped:

- PP-OCRv5 real inference benchmark
- TranslateGemma real inference benchmark
- PHPUnit
- Redis / SSE
- multi-host benchmark

## Dashboard RAM Metric Correction

Adjusted dashboard memory metrics to use Linux `MemAvailable` instead of free memory.

- Displayed Used / BuffCache / Available / SwapUsed separately.
- Added `vmstat si/so` to host metrics.
- Memory pressure now follows MemAvailable percentage and swap in/out activity.

## PhaseS-1 SQLite Write Guard / Retention / Prune

Added SQLite write safety and retention controls.

Added:

- `scripts/prune_db.php`
- `scripts/db_maintenance.php`
- SQLite safety tests in `tests/test_sqlite_safety.php`

Implemented:

- SQLite PRAGMAs: WAL, busy timeout, foreign keys, synchronous NORMAL
- default retention / size settings
- large task result spillover to `data/results/task_{task_id}/`
- large task log spillover to `data/logs/tasks/task_{task_id}.log`
- bounded task log rows via `AIHUB_MAX_TASK_LOG_ROWS`
- host metrics 30-second write throttle with `--force`
- DB prune for old metrics, benchmark runs, command jobs, task logs, and terminal task records
- WAL checkpoint truncate after prune apply
- DB status / checkpoint / explicit VACUUM maintenance CLI
- runtime permission repair includes `data/logs/tasks`

Skipped:

- MySQL / PostgreSQL migration
- Redis
- high-frequency monitoring
- docker logs ingestion into SQLite
- Web UI VACUUM

## PhaseS-2 Service IP Whitelist / API Access Audit

Added service-level API access control.

Added:

- `app/api_access.php`
- `admin/service_whitelist.php`
- `admin/api_access_logs.php`
- `service_ip_whitelists` table
- `api_access_logs` table
- whitelist / access log links in `admin/services.php`

Implemented:

- localhost auto allow
- exact IP and CIDR whitelist rules
- default external API deny via `AIHUB_DEFAULT_ALLOW_EXTERNAL_API=0`
- API access logs for success and failures
- distinct gateway error codes for unknown mode, disabled service, IP denied, wrong method, runtime pending, unavailable runtime, timeout, and proxy errors
- Top failed IPs summary
- retention setting `AIHUB_API_ACCESS_LOG_RETENTION_DAYS=30`
- `scripts/prune_db.php` cleanup for API access logs

Skipped:

- API keys
- rate limit / ban
- WAF / fail2ban integration
- trusted reverse proxy headers
- request body logging

## PhaseS-3 Log Explorer / API Trace

Added API trace lookup for admin.

Added:

- `admin/log_explorer.php`
- `admin/log_detail.php`
- `admin/ip_profile.php`
- request_id support in `api_access_logs`
- base64url helpers for IP/CIDR GET filters

Implemented:

- `X-3waAIHub-Request-Id` response header
- JSON error `request_id`
- Log Explorer filters for time, IP, mode, service, ok, status, error_code, method, request_id, and keyword
- keyword search via prepared statements
- log detail page with service whitelist context and related requests
- IP profile page using `ip_b64`
- last-24h failed IPs, error codes, unknown modes, and denied IP summaries
- IP/CIDR GET links generated with base64url helpers only

Skipped:

- CSV export
- fail2ban / auto-ban
- Elasticsearch / Loki / Grafana

## Architecture Decisions

Today locked the 3waAIHub Local service model:

- Pack = template.
- Service = instance.
- Mode = public API route.
- Models / cache / results use global storage paths.
- Docker ports bind to `127.0.0.1` only.
- API access uses service-level IP whitelist.
- Logs / audit record `request_id`, IP, mode, and `error_code`.
- SQLite stores metadata only; large logs / results go to files under `data/`.

## PhaseM-2A OCR GPU L2 Runtime Prep

Prepared `ocr-ppocrv5` for GPU-backed runtime without enabling real OCR inference yet.

Added:

- Version banner constants: `HUB_VERSION`, `HUB_RELEASE_LABEL`
- Runtime readiness fields in HubPack manifests
- OCR `smoke.py` dependency import check
- Minimal GitHub Actions CI

Implemented:

- `ocr-ppocrv5` runtime level set to `L2-deps-import`
- `translate-gemma12b` marked `L0-manifest-only` / not runtime ready
- OCR Dockerfile switched to NVIDIA CUDA 12.9 runtime base
- OCR Docker build uses `pip check`
- OCR generated compose requests `gpus: all`
- OCR services mount models/cache/service data paths
- Existing `ocr-main` and `ocr-gpu` runtime files regenerated under `data/services/`

Correction:

- OCR runtime level was reduced to `L1-gpu-api-mock` after Docker build showed GPU Paddle import requires runtime-mounted `libcuda.so.1`.
- PaddleOCR / PaddlePaddle GPU dependencies are deferred to L2 with a cached/base-image strategy.

Skipped:

- real OCR inference
- model download
- PDF OCR
- benchmark tuning

## PhaseH-1 Station Hardware Profile / Pack Preflight

Added lightweight hardware compatibility checks for Marketplace installs.

Implemented:

- Station Hardware Profile derived from latest `host_metric_snapshots`
- GPU compute capability map for common cards:
  - RTX 5090 / 5080 / 5070 / 5060 Ti / 5060 => 12.0
  - RTX 4090 / 4080 / 4070 / 4060 Ti / 4060 => 8.9
  - RTX 3090 / 3080 / 3070 / 3060 => 8.6
  - GTX 1080 Ti => 6.1
- Docker metric now records Docker Compose, NVIDIA Container Toolkit, and Docker NVIDIA runtime availability
- Pack manifest `preflight.checks`
- Marketplace preflight display for Docker, Compose, GPU, Docker GPU runtime, VRAM, compute capability, and storage
- Environment diagnostics now surfaces repair commands from Marketplace host metric failures
- TranslateGemma requires VRAM >= 10000MB and compute capability >= 8.0
- OCR GPU L2 requires compute capability >= 8.0

Skipped:

- new `station_hardware_profile` table
- Web request host command execution
- deviceQuery
- hard-blocking install on preflight failure

## PhaseM-2B TranslateGemma Ollama Adapter L1

Completed `translate-gemma12b` L1 runtime.

Implemented:

- Gateway now applies manifest `timeout_sec` and `max_upload_mb`.
- Added `packs/translate-gemma12b/service/` FastAPI adapter.
- Adapter starts Ollama inside the container and calls `/api/generate`.
- Translate image uses multi-stage Docker build and copies Ollama runtime libraries without apt in the Ollama base image.
- `OLLAMA_AUTO_PULL=1` pulls `translategemma:12b-it-q4_K_M` into `AIHUB_MODELS_DIR/ollama`.
- `zh-TW` prompt maps to Traditional Chinese / Taiwan wording.
- Test DB pack installs write to `data/test_services/{hash}` instead of overwriting runtime services.
- Added `docker_builder_prune` command worker action for explicit Docker build cache cleanup.

Verified:

- `php scripts/run_tests.php` PASS.
- `GET http://127.0.0.1:18102/health` returns `ready:true`.
- `POST api.php?mode=translate` returns translated Traditional Chinese text.
- Docker builder cache prune recovered root filesystem space.

Skipped:

- Translate async queue.
- Model management UI.
- Streaming translate responses.

## PhaseV-1 YOLO / SAM3 HubPacks

Added first Ultralytics vision HubPacks.

Implemented:

- Added `yolo` HubPack.
- Added `sam3` HubPack.
- Added FastAPI runtime adapter files for both packs.
- YOLO endpoint: `POST /detect/image`.
- SAM3 endpoint: `POST /segment/image` with `points` / `labels` / `bboxes` form JSON.
- Generated runtime files for `yolo-main` on port `18105`.
- Generated runtime files for `sam3-main` on port `18106`.
- Copied local runtime model files into:
  - `AIHUB_MODELS_DIR/yolo/yolo11n.pt`
  - `AIHUB_MODELS_DIR/sam3/sam3.pt`

Verified:

- `php scripts/run_tests.php` PASS.
- `/DATA/conda_vm/sam3/models/sam3.pt` loads with `ultralytics.SAM`.
- Local SAM3 point prompt smoke produced one mask and one box.

Skipped:

- Building and starting the YOLO / SAM3 Docker images.
- Async queue.
- Mask artifact export.

## PhaseP-1 Pack Build Progress

Added command job progress for Docker pack installs/builds.

Implemented:

- Added `command_jobs.progress`, `stage`, and `current_message`.
- Added `admin/job_status.php` JSON endpoint with stdout/stderr tail.
- Added service build progress UI on `admin/services.php`.
- Split Build / Start / Rebuild behavior.
- Start no longer always rebuilds Docker image.
- Added image exists check with fixed generated image tag.
- Docker build uses `docker compose build --progress=plain`.
- Added `AIHUB_AUTO_BUILD_MISSING_IMAGE=1`.

Skipped:

- prebuilt image fields.
- GHCR / local registry / registry mirror.
- WebSocket / SSE.
- real PaddleOCR / TranslateGemma changes.

## PhaseS-4 Token Auth MVP

Added API member and token authentication foundation.

Implemented:

- API members.
- token hash / prefix storage.
- token revoke.
- valid_from / valid_until.
- unlimited token support.
- Bearer token gateway authentication.
- token mode permissions.
- token IP whitelist.
- api_access_logs member_id / token_id / upload_bytes / response_bytes.
- daily usage aggregate.
- API member/token admin pages.
- Log Explorer member/token visibility.
- Authorization header preservation in .htaccess.

Skipped:

- quota.
- rate limit.
- billing.
- OAuth.
- auto ban.

## PhaseS-4.1 Token API Smoke

Added end-to-end token API smoke coverage.

Implemented:

- temporary SQLite DB.
- temporary PHP app server.
- temporary OCR mock upstream.
- API member/token setup.
- mode=ocr permission setup.
- token IP whitelist setup.
- real curl Bearer token request to api.php?mode=ocr.
- Log Explorer data verification.
- daily usage aggregate verification.

Skipped:

- browser admin form automation.
- live Docker OCR dependency.

## PhaseP-2 Service Runtime Settings

Added Pack-declared runtime settings for installed service instances.

Implemented:

- Added `service_settings` table.
- Added `services.config_dirty` and `services.restart_required`.
- Added `settings_schema` to hello, OCR, and TranslateGemma packs.
- Added service settings helpers for schema defaults, validation, updates, and `.env` regeneration.
- Added `admin/service_settings.php`.
- Added Settings link and config/restart status on services list.
- `.env` generation now uses storage/global settings, fixed service info, and schema-declared settings only.
- Legacy services backfill missing settings when opened or regenerated.
- Validation covers integer, number, boolean, select, path, text, and secret.

Skipped:

- hot reload.
- secret vault.
- arbitrary env / Docker args / volume editor.
- real PaddleOCR.
- TranslateGemma adapter changes.
- multi-host config sync.

## PhaseM-2A-L2 PP-OCRv5 dependency/import smoke

Advanced `ocr-ppocrv5` from L1 `api_mock` to L2 `deps_import`.

Implemented:

- Set `ocr-ppocrv5` runtime level to `L2-deps-import`.
- Added `paddleocr` to the OCR service requirements.
- Added Docker build-time `python3 smoke.py` verification.
- Updated `smoke.py` to import `paddleocr` and `fastapi` only.
- Added `/health` `runtime_level=L2-deps-import`.
- Kept `/ocr/image` as mock OCR JSON.

Verified:

- Docker build PASS.
- `smoke.py` import paddleocr OK.
- `ocr-main` restart PASS.
- direct `/health` PASS.
- direct `/ocr/image` PASS.
- `api.php?mode=ocr` PASS.
- `token_api_smoke.php` PASS.
- `run_tests.php` PASS.
- `self_check.php` PASS.

Skipped:

- PaddleOCR model initialization.
- PaddleOCR model download.
- real OCR inference.
- PDF OCR.
- TranslateGemma changes.

## PhaseM-2A-L3 PP-OCRv5 Storage Mount

Advanced `ocr-ppocrv5` from L2 `deps_import` to L3 `storage_mount`.

Implemented:

- runtime_level = `L3-storage-mount`.
- `OCR_MODEL_DIR` / `OCR_CACHE_DIR` / `OCR_SERVICE_DATA_DIR`.
- `XDG_CACHE_HOME` / `HOME` / `PADDLEOCR_HOME`.
- model/cache/service_data volume mounts.
- `/health` storage exists/readable/writable checks.
- `/ocr/image` remains mock JSON with runtime_level.
- `storage_smoke.py`.

Verified:

- Docker build PASS.
- PaddleOCR import smoke PASS.
- `ocr-main` restart PASS.
- `/health` PASS.
- `storage_smoke.py` PASS.
- direct `/ocr/image` PASS.
- gateway `api.php?mode=ocr` PASS.
- `token_api_smoke.php` PASS.

Skipped:

- model init.
- model download.
- real OCR inference.
- PDF OCR.

## PhaseM-2A-L4a PP-OCRv5 Model Init Smoke

Advanced `ocr-ppocrv5` from L3 `storage_mount` to L4a `model_init_smoke`.

Implemented:

- runtime_level = `L4a-model-init-smoke`.
- Added CPU `paddlepaddle` runtime dependency for PaddleOCR initialization.
- Added manual `model_smoke.py`.
- `model_smoke.py` initializes `PaddleOCR(...)` without OCR image work.
- `model_smoke.py` pins `PADDLEOCR_HOME`, `XDG_CACHE_HOME`, and `HOME` to mounted storage.
- `model_smoke.py` reports allowed model/cache/service_data writes and suspicious `/root` / `/app` writes.
- `/health` remains lightweight storage health.
- `/ocr/image` remains mock JSON with runtime_level.

Verified:

- Docker build PASS.
- PaddleOCR import smoke PASS.
- `ocr-main` restart PASS.
- `/health` PASS.
- `model_smoke.py` PASS.
- PaddleOCR model files landed under `/cache/paddleocr/home/.paddlex`.
- No new files under `/root` or `/app`.
- direct `/ocr/image` PASS.
- gateway `api.php?mode=ocr` PASS.
- `token_api_smoke.php` PASS.

Skipped:

- startup preload.
- HTTP model smoke endpoint.
- real OCR inference.
- PDF OCR.

## PhasePack-L5 Benchmark Ready / Service Contract Standard

Added L5 contract/readiness scaffolding without promoting OCR to L5.

Implemented:

- Added `target_level` and `l5_contract` to `ocr-ppocrv5`.
- Added OCR input/output/error/limits/benchmark contract.
- Added tiny OCR benchmark fixture at `packs/ocr-ppocrv5/demo/sample.png`.
- Extended `scripts/benchmark.php` with `--pack` and `--service`.
- Added l5_contract benchmark runner using existing gateway dispatch.
- Added Pack L5 readiness helper and `admin/pack_readiness.php`.
- Added Readiness link on HubPack page.
- Extended API Docs to render Pack contracts.
- Extended Benchmark admin page with Pack/Service context.

Verified:

- `php scripts/benchmark.php --pack=ocr-ppocrv5 --case=ocr_mock_image` PASS.
- `php scripts/benchmark.php --service=ocr-main --case=ocr_mock_image` PASS.
- OCR readiness reports L5 contract checks while keeping real inference pending.
- API Docs can read Pack contracts.
- Benchmark admin can show Pack/Service context.
- `api.php?mode=ocr` remains mock.
- `api.php?mode=hello` remains unchanged.

Skipped:

- real OCR inference.
- PaddleOCR predict.
- model download.
- PDF OCR.
- TranslateGemma / SAM3 / YOLO L5.

## PhaseM-2A-L4b PP-OCRv5 Real Image OCR

Advanced `ocr-ppocrv5` from L4a `model_init_smoke` to L4b `real_inference`.

Implemented:

- runtime_level = `L4b-real-inference`.
- Added `OCR_REAL_INFERENCE` setting with mock fallback default.
- `/ocr/image` keeps mock mode by default.
- `/ocr/image` runs PaddleOCR real inference when `OCR_REAL_INFERENCE=1` or form `real_inference=1`.
- Added result normalization to `text` / `blocks`.
- Added `inference_smoke.py`.
- Added real OCR fixture `packs/ocr-ppocrv5/demo/real_sample.png`.
- Added `ocr_real_image` benchmark case without replacing `ocr_mock_image`.
- L5 readiness can turn real inference benchmark green.

Skipped:

- PDF OCR.
- batch OCR.
- startup preload.
- benchmark tuning.

## PhaseM-2A-L5 PP-OCRv5 Benchmark Ready Promotion

Promoted `ocr-ppocrv5` to the first L5 benchmark-ready Pack.

Implemented:

- runtime_level = `L5-benchmark-ready`.
- Kept mock fallback and `real_inference=1` real OCR path.
- Documented `real_inference` in the OCR API contract.
- Updated README / API docs for L5 status.
- Pack Readiness can show OCR as 11/11 after `ocr_mock_image` and `ocr_real_image` pass.

Skipped:

- PDF OCR.
- batch OCR.
- UI redesign.

## PhaseM-2B-L2 YOLO Dependency Import Smoke

Advanced `yolo` from L1 `ultralytics-yolo` to L2 `deps_import`.

Implemented:

- runtime_level = `L2-deps-import`.
- target_level = `L5-benchmark-ready`.
- Added YOLO `settings_schema` for model/conf/iou/GPU/keep warm.
- Added `smoke.py` import smoke for `ultralytics` / `fastapi`.
- Docker build runs `python3 smoke.py`.
- `/health` returns `runtime_level=L2-deps-import`.
- `/detect/image` remains mock JSON with empty `detections`.

Skipped:

- YOLO model download.
- `YOLO(...)` initialization.
- real detection.
- GPU tuning.
- SAM3 / TranslateGemma.

## PhaseM-2B YOLO L5 Benchmark Ready

Promoted YOLO Pack to L5 benchmark-ready.

Implemented:

- YOLO storage mount.
- YOLO model init smoke.
- YOLO real image detection.
- YOLO mock / real benchmark cases.
- L5 readiness contract.

Verified:

- Docker build PASS.
- build smoke uses `/tmp/ultralytics` and does not pollute runtime cache.
- `yolo-main` start PASS.
- `/health` PASS with `runtime_level=L5-benchmark-ready`.
- `storage_smoke.py` PASS.
- `model_smoke.py` PASS.
- model stored at `/DATA/models/yolo/yolo11n.pt`.
- `inference_smoke.py` PASS.
- direct `/detect/image` mock PASS.
- direct `/detect/image real_inference=1` PASS.
- gateway `api.php?mode=yolo` mock PASS.
- gateway `api.php?mode=yolo real_inference=1` PASS.
- `yolo_mock_image` benchmark PASS.
- `yolo_real_image` benchmark PASS.
- readiness 11/11.

Skipped:

- GPU tuning.
- batch detection.
- video detection.
- segmentation.
- tracking.
- custom trained YOLO upload.

## PhaseAuth-1 Customer Portal / Role-based Login

Implemented role-based login for `system_admin` and `customer`.

Added:

- `users.role`
- `users.api_member_id`
- `users.display_name`
- `users.email`
- `users.company`
- `users.is_protected`
- `users.is_enabled`
- `users.last_login_at`
- `user_mode_permissions`
- `app/customer_accounts.php`
- `admin/customers.php`
- `admin/customer_edit.php`
- `admin/my_services.php`
- `admin/my_tokens.php`
- `admin/my_ip_whitelist.php`
- `admin/my_usage.php`
- `admin/my_profile.php`
- `admin/change_password.php`

Implemented:

- default `admin` is `system_admin`, `is_protected=1`, `is_enabled=1`.
- protected admin cannot be disabled or downgraded.
- system cannot be left with zero enabled `system_admin` accounts.
- customer login redirects to Customer Portal.
- system admin pages now require `hub_require_system_admin`.
- customer nav only shows own service/token/IP/usage/profile/password pages.
- customer accounts link to `api_members`.
- customer-created tokens inherit only admin-granted service modes.
- customer IP whitelist manages only own token rules.
- Playground filters customer-visible modes by `user_mode_permissions`.

Verified:

- `php scripts/run_tests.php` PASS with PhaseAuth-1 tests.

Skipped:

- self registration.
- email verification.
- forgot password.
- OAuth.
- billing / subscription.
- quota enforcement.
- organization/team hierarchy.

## PhaseAuth-1A.1 RBAC Hardening Audit

Hardened and tested the PhaseAuth-1A role boundary.

Added:

- `tests/test_phase_auth1a_hardening.php`
- `hub_count_enabled_system_admins()`
- `hub_can_modify_user_role()`
- `hub_can_disable_user()`
- `hub_can_delete_user()`

Implemented:

- Direct `system_admin` guard on legacy `admin/api_access_logs.php`.
- Customer nav label aligned to "我的用量" while keeping "用量統計" compatibility text.
- Source-level guard audit for admin-only GET pages.
- Source-level guard audit for admin-only POST action pages.
- Command job enqueue guard audit.
- Protected admin delete / disable / downgrade guard coverage.
- Disabled user login rejection and `last_login_at` update coverage.

Verified:

- `php scripts/run_tests.php` PASS with 76 tests.

Skipped:

- New customer portal features.
- Registration / forgot password / OAuth / billing / quota.

## PhaseAuth-2A Customer Portal Read-only Hardening

Hardened the customer read-only portal and profile/password boundaries.

Added:

- `tests/test_phase_auth2a.php`
- `hub_user_token_allowed_modes()`

Implemented:

- Customer services visibility test for own `user_mode_permissions`.
- Customer usage visibility test scoped to own `api_member`.
- Profile update test proving only `display_name`, `email`, and `company` change.
- Password change flow test with old-password rejection and new-password login.
- `my_services.php` sensitive field source audit.
- Playground customer filter now requires:
  - admin-granted mode permission.
  - own enabled, non-revoked token permission for that mode.
  - valid own API member.

Verified:

- `php scripts/run_tests.php` PASS with 80 tests.

Skipped:

- Token lifecycle hardening.
- IP whitelist lifecycle hardening.
- Registration / forgot password / OAuth / billing / quota.

## PhaseAuth-1A.2 Login IP Lockout

Added login brute-force protection by client IP.

Added:

- `login_attempts`
- `login_ip_locks`
- `AIHUB_LOGIN_MAX_FAILED_ATTEMPTS=3`
- `AIHUB_LOGIN_LOCK_MINUTES=5`
- `AIHUB_LOGIN_FAIL_WINDOW_MINUTES=10`
- `tests/test_phase_auth1a2_login_lockout.php`

Implemented:

- Login IP is resolved from `REMOTE_ADDR` only.
- `X-Forwarded-For`, `X-Real-IP`, and similar headers are not trusted.
- 3 failed login attempts within the configured window lock the IP.
- Locked IPs are not allowed to verify credentials.
- Expired locks clear automatically.
- Successful login resets the IP failed count.
- Disabled-user login attempts count as generic failed logins.
- Login attempts are audited with success/failure, reason, username, IP, user agent, and timestamp.

Verified:

- `php scripts/run_tests.php` PASS with 85 tests.

Follow-up fix:

- Captcha failures are audited as `captcha_failed` but do not increment IP lockout failed count.

Skipped:

- CAPTCHA changes.
- 2FA.
- email notification.
- trusted proxy parser.
- account-level lockout.
- permanent ban.

## PhaseDX-4 Client Integration Starter Kit

Added the first external client onboarding kit.

Added:

- `docs/client_quickstart.md`
- `scripts/api_smoke_client.php`
- `tests/test_phase_dx4_client_starter.php`

Implemented:

- Quickstart flow: create customer, create token, read public docs, read agent manifest, run smoke client, then integrate.
- Minimal curl / PHP / JS fetch examples.
- Mode contract summary for `hello`, `ocr`, `yolo`, `translate`, and `sam3`.
- API examples now use `<BASE_URL>` instead of hardcoded localhost.
- Smoke client supports `--base-url`, `--token`, `--modes`, `--image`, and `--real`.
- Playground and public docs examples remain current-host based.

Skipped:

- SDK package.
- Token storage in client tooling.
- Gateway/runtime changes.
- New platform features.

## PhaseTTS-1 VoxCPM2 Experimental Pack

Added the first experimental text-to-speech HubPack.

Added:

- `tts-voxcpm2` HubPack manifest.
- VoxCPM2 service skeleton:
  - `GET /health`
  - `GET /v1/models`
  - `POST /v1/voice-design`
  - `POST /v1/tts`
- Lightweight Docker runtime for install/build smoke.
- `packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json`
- Voice Profile DB foundation:
  - `voice_profiles`
  - `voice_profile_audit_logs`
  - `app/voice_profiles.php`
- Gateway rewrite for governed clone mode.
- Playground support for `mode=tts`.
- Playground TTS result now renders an authenticated WAV audio player.

Implemented:

- `mode=design` for natural-language voice design.
- `mode=clone` for controllable clone through managed Voice Profiles.
- Public API rejects direct server-side audio paths.
- Gateway maps owned `voice_profile_id` / `reference_audio_id` to container `/data/voice_profiles/...`.
- Voice Profile ownership is scoped to `api_member`.
- Customer Playground shows `tts` only when both user mode permission and owned token permission allow it.
- Generated TTS WAV files are served to logged-in users through `admin/playground_artifact.php`, scoped by service permission.
- Voice Profile create/use/delete actions are audited.
- Generated compose mounts:
  - `${AIHUB_MODELS_DIR}/voxcpm2:/models/voxcpm2`
  - `${AIHUB_CACHE_DIR}/voxcpm2:/cache/voxcpm2`
  - `${SERVICE_DATA_DIR}:/data/service`
  - `${AIHUB_UPLOADS_DIR}/voice_profiles:/data/voice_profiles:ro`
- Default runtime uses deterministic mock WAV output with `VOXCPM2_REAL_INFERENCE=0`.
- Real VoxCPM2 runtime is lazy-loaded only when `VOXCPM2_REAL_INFERENCE=1`.
- Output manifest records `ai_generated`, `model`, `seed`, `voice_profile_id`, `reference_audio_sha256`, `duration_ms`, and chunk count.
- GPU lifecycle metadata added:
  - `lifecycle=on_demand`
  - `gpu_policy=exclusive_gpu`
  - `idle_unload_seconds=900`

Verified:

- `php scripts/run_tests.php` PASS with 95 tests.
- Follow-up after install: `php scripts/run_tests.php` PASS with 96 tests.
- `python3 -m py_compile packs/tts-voxcpm2/service/*.py` PASS.
- PHP lint for modified PHP files PASS.

Skipped:

- Ultimate Clone.
- ASR transcript confirmation.
- LoRA training.
- Podcast generation.
- Streaming / WebSocket.
- vLLM-Omni.
- OpenAI-compatible API.
- Voice asset management UI.
- Public anonymous clone access.

## PhaseTTS-1 Runtime Fix

Fixed VoxCPM2 Playground output using mock audio when real inference was requested.

Root cause:

- `POST /v1/tts` accepted Playground requests but did not honor request-level `real_inference=1`.
- The runtime image did not include the official `voxcpm` package, `soundfile`, or the C/C++ compiler needed by Torch / Triton warmup.
- The first real runtime call passed `seed` into `VoxCPM.generate()`, but the official API expects deterministic behavior to be handled outside the generate kwargs.

Changed:

- `packs/tts-voxcpm2/service/requirements.txt` now pins `voxcpm==2.0.3` and includes `soundfile`.
- `packs/tts-voxcpm2/service/Dockerfile` installs `libsndfile1`, `ffmpeg`, `gcc`, and `g++`.
- `packs/tts-voxcpm2/service/app.py` now honors request-level `real_inference=1`.
- VoxCPM2 seed is applied through Python / NumPy / Torch seed setup before generation, not passed as an unsupported generate argument.
- Tests now cover the real-inference request flag and unsupported seed regression.
- Existing `3WA專用` and customer tokens were granted `tts` mode permission for local validation.

Verified:

- `docker compose -f data/services/voxcpm2-main/docker-compose.generated.yml build --progress=plain` PASS.
- `GET http://127.0.0.1:18108/health` reports `voxcpm=true` and `soundfile=true`.
- Direct `POST /v1/tts` with `real_inference=1` returns `mock=false`.
- Gateway `POST http://localhost/3waAIHub/api.php?mode=tts` returns `mock=false`.
- Generated WAV verified as 16-bit mono 48kHz PCM.
- `php scripts/run_tests.php` PASS with 98 tests.

## PhaseTTS-1 L5 Benchmark Ready

Promoted VoxCPM2 TTS from `L3-storage-mount` to `L5-benchmark-ready`.

Changed:

- `packs/tts-voxcpm2/pack.json` runtime level is now `L5-benchmark-ready`.
- `packs/tts-voxcpm2/service/app.py` reports `runtime_level=L5-benchmark-ready`.
- Added L5 benchmark cases:
  - `tts_mock_wav`
  - `tts_real_wav`
- Benchmark mock payload now supports the VoxCPM2 TTS response contract.

Verified:

- `/health` returns `runtime_level=L5-benchmark-ready`.
- `php scripts/benchmark.php --pack=tts-voxcpm2 --case=tts_mock_wav` PASS.
- `php scripts/benchmark.php --pack=tts-voxcpm2 --case=tts_real_wav` PASS.
- `hub_pack_l5_readiness(..., tts-voxcpm2)` reports `11/11`.
- `php scripts/run_tests.php` PASS with 99 tests.

## PhaseTTS-1 Playground Real Inference Fix

Fixed the TTS Playground request form so `mode=tts` sends real inference by default.

Root cause:

- The TTS form did not include the checked `real_inference` field.
- The TTS request payload builder did not forward `real_inference` into the JSON body.
- Latest generated Playground artifact was therefore `mock=true`.

Changed:

- `admin/playground.php` now includes checked `real_inference` for TTS.
- TTS Playground payload now forwards `real_inference`.
- `tests/test_tts_voxcpm2.php` checks only the TTS branch, avoiding false positives from OCR/YOLO controls.

Verified:

- Gateway TTS request returned `mock=false` and `real_inference_requested=true`.
- Generated WAV verified as 16-bit mono 48kHz PCM.
- `php scripts/run_tests.php` PASS with 99 tests.

## PhaseDoc-1B DocParser Real-Service Readiness

Validated DocParser against a real NSR maintenance manual under `/DATA/docs/`.

Fixed real-service issues found during the run:

- Increased `structure-ppstructurev3` gateway timeout from 180s to 1800s for real multi-page manuals.
- Added DocParser support for PP-StructureV3 real JSON shape:
  - `parsing_res_list`
  - `block_label`
  - `block_content`
  - `block_bbox`
  - `block_order`
- Added oversized translation block splitting before calling TranslateGemma to avoid 413 responses.
- Added worker progress updates during long DocParser translation runs.
- Made the default `technical_manual` acceptance fixture generic instead of tied to a fixed English `General Information` sample.
- Adjusted translation identity quality logic so Chinese source text kept as zh-TW is not treated as fake translation.
- Added PDF figure crop extraction from DocIR bbox via `pdftoppm` + GD.
- Linked extracted figure PNG assets from DocParser HTML / Markdown exports.
- Registered figure PNGs as task artifacts.
- Fixed local DocParser asset link quality checks for `assets/figures/*.png`.

Verified:

- Input: `/DATA/docs/NSR維修手冊.pdf`
- Successful task: `10`
- Pages: 79
- DocIR blocks: 852
- Headings: 194
- Tables: 23
- Figure assets: 144
- `source_kind=ppstructure_document_json`
- `structure_mock=false`
- `translation_block_coverage=0.997080291970803`
- `translation_identity_ratio=0.09635036496350365`
- `php scripts/docparser_acceptance.php --task-id=10` PASS.
- `php scripts/run_tests.php` PASS with 126 tests.

Not completed in this phase:

- FZR 335-page full-manual run.
- Image OCR overlay.
- Technical drawing understanding.
- VLM review.
- Manual correction UI.
