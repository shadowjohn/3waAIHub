# 3waAIHub History

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
