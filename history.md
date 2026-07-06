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
