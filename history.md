# 3waAIHub History

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
