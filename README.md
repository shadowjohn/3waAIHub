# 3waAIHub

3waAIHub Local 是一個本機 AI 服務管理入口。目標是讓一台新主機安裝後，可以用 SQLite 管理服務、用後台排程啟停 Docker 服務，並透過 `api.php` 對外提供 API。

目前 MVP 只內建 `hello-service`，不包含 SAM3 / OCR / 翻譯 / OpenMVS / 3DGS。

## 功能

- 本機管理後台登入
- SQLite metadata
- `hello-service` Docker 啟動 / 停用 / 重啟
- 背景 command worker 執行 host 操作
- 服務 log 檢視
- `api.php?mode=hello` sync API gateway
- SQLite-backed demo task queue
- HubPack registry 與 hello pack 安裝
- Storage settings / model directory
- 環境診斷與修正建議
- Service IP whitelist 與 API access logs
- `.htaccess` 阻擋直接下載 runtime/internal 檔案

## 安裝

```bash
cd /DATA/3waAIHub
./install.sh
```

預設安裝只做 app 初始化：

- 檢查 PHP
- 檢查 SQLite extension
- 檢查 Docker / Docker Compose 是否可用
- 建立 `data/` runtime 目錄
- 初始化 SQLite
- 整理 runtime 權限

預設不會安裝 Docker 或 NVIDIA 套件。

只檢查環境，不初始化、不修改主機：

```bash
./install.sh --check
```

## 預設帳號

- 帳號：`admin`
- 密碼：`admin123`

登入後請到「設定」修改密碼。

## 入口

首頁：

```text
http://localhost/3waAIHub/
```

登入頁：

```text
http://localhost/3waAIHub/login.php
```

後台：

```text
http://localhost/3waAIHub/admin/
```

## 啟動 hello-service

`hello-service` 由 `packs/hello/pack.json` 安裝成 service instance，runtime 檔案產生在：

```text
data/services/hello-main/
```

後台按鈕只會排入 `command_jobs`，真正 Docker 指令由 CLI worker 執行。

1. 進入後台服務頁：

```text
http://localhost/3waAIHub/admin/services.php
```

2. 按「啟動」。

3. 在主機執行 worker：

```bash
sudo php /DATA/3waAIHub/scripts/command_worker.php --limit=5
```

4. 測試 API：

```bash
curl http://localhost/3waAIHub/api.php?mode=hello
```

預期回應：

```json
{"ok": true, "service": "hello", "message": "3waAIHub service is running"}
```

## Worker 長期執行建議

不要把 `www-data` 加進 docker 群組。Docker group 等同 root 權限。

`./install.sh` 若以 root 執行，會自動掛載 command worker cron。若以非 root 執行，請手動掛載：

```bash
cd /DATA/3waAIHub
sudo ./scripts/install_command_worker_cron.sh
```

系統 cron 會每分鐘呼叫專案內的 `crontab/1min.sh`。這支 script 自己使用 `flock` 防重入，並在同一分鐘內用短 delay loop 執行 `scripts/command_worker.php --limit=5`。

預設 cron 使用 `root` 執行，最穩定。若要改用可信任本機帳號，例如 `john`：

```bash
sudo usermod -aG docker john
sudo -iu john docker info
sudo WORKER_USER=john ./scripts/install_command_worker_cron.sh
```

安裝後會產生：

```text
/etc/cron.d/3waaihub-command-worker
```

worker log 會寫到 `data/logs/command_worker_1min.log`。

## Dashboard Metrics

後台首頁會讀取 SQLite 最新 host metrics snapshot，顯示 GPU、VRAM、RAM、Disk、服務統計與待處理項。Web request 不會直接執行 `nvidia-smi` 或 `docker info`。

RAM 判斷使用 `/proc/meminfo` 的 `MemAvailable`，不是 `MemFree`。Dashboard 會分開顯示 Used / BuffCache / Available / SwapUsed，記憶體告警以 MemAvailable 百分比與 `vmstat si/so` 為準。

手動收集：

```bash
php /DATA/3waAIHub/scripts/collect_host_metrics.php
```

預設 30 秒內不重複寫入 snapshot；需要強制收集時：

```bash
php /DATA/3waAIHub/scripts/collect_host_metrics.php --force
```

可用 cron 每分鐘收集一次：

```cron
* * * * * php /DATA/3waAIHub/scripts/collect_host_metrics.php >/dev/null 2>&1
```

Dashboard 圖表使用 ECharts CDN；離線環境可能只能看到文字卡片，圖表不會載入。

## API Access Control

`api.php?mode=xxx` 會依 service instance 做 IP whitelist 檢查，預設只允許 localhost：

- `127.0.0.1`
- `::1`

外部 IP 需要到服務列的 `Whitelist` 明確新增。支援單一 IP 與 CIDR，例如：

```text
192.168.1.10
192.168.1.0/24
::1
```

目前不信任 `X-Forwarded-For`，避免來源 IP 被偽造。若未來要接 reverse proxy，再另外加 trusted proxy setting。

後台入口：

```text
admin/services.php          每列 Whitelist / Access Logs
admin/log_explorer.php      Log Explorer / API Trace
admin/ip_profile.php        單一 IP 行為摘要
```

GET URL 裡不裸放 IP / CIDR。後台產生的 IP filter link 會使用 base64url：

```text
admin/log_explorer.php?client_ip_b64=MTkyLjE2OC4xLjEw
admin/ip_profile.php?ip_b64=MjAwMTpkYjg6OjE
```

錯誤會分開記錄，例如：

- `unknown_mode`
- `service_disabled`
- `ip_not_allowed`
- `method_not_allowed`
- `runtime_not_ready`
- `service_unavailable`
- `gateway_timeout`
- `proxy_error`

access log 不記 request body，不記 token，只記 metadata。保留天數由 `AIHUB_API_ACCESS_LOG_RETENTION_DAYS=30` 控制，並由 `scripts/prune_db.php --apply` 清理。

每次 gateway request 會產生 `request_id`，回應 header 會帶：

```text
X-3waAIHub-Request-Id: req_...
```

錯誤 JSON 也會包含 `request_id`，方便回報後直接到 Log Explorer 查。

## SQLite Safety / Retention

SQLite 只用來存本機狀態、索引與摘要，不當大量 log sink。大型 task result 會寫到 `data/results/task_{task_id}/`，大量 task log 會寫到 `data/logs/tasks/task_{task_id}.log`，DB 只保留 artifact path 或最後摘要。

DB 連線預設使用：

- `PRAGMA journal_mode=WAL`
- `PRAGMA busy_timeout=5000`
- `PRAGMA foreign_keys=ON`
- `PRAGMA synchronous=NORMAL`

預設保留設定：

- `AIHUB_DB_MAX_SIZE_MB=1024`
- `AIHUB_LOG_RETENTION_DAYS=14`
- `AIHUB_METRIC_RETENTION_DAYS=14`
- `AIHUB_TASK_RETENTION_DAYS=30`
- `AIHUB_MAX_TASK_LOG_ROWS=1000`
- `AIHUB_MAX_RESULT_JSON_BYTES=262144`

查看 DB / WAL 狀態：

```bash
php /DATA/3waAIHub/scripts/db_maintenance.php --status
```

若 CLI 或 Web 寫入出現 `readonly database`，先修 runtime 權限：

```bash
sudo WEB_GROUP=www-data /DATA/3waAIHub/scripts/fix_permissions.sh
```

手動 WAL checkpoint：

```bash
php /DATA/3waAIHub/scripts/db_maintenance.php --checkpoint
```

先看 prune 會清什麼：

```bash
php /DATA/3waAIHub/scripts/prune_db.php --dry-run
```

正式清理並執行 `wal_checkpoint(TRUNCATE)`：

```bash
php /DATA/3waAIHub/scripts/prune_db.php --apply
```

建議加入 cron，每天跑一次：

```cron
17 3 * * * php /DATA/3waAIHub/scripts/prune_db.php --apply >/dev/null 2>&1
```

## Tests / Benchmark / API Examples

PhaseM-1.1 先測 HubPack Kit 本身，不測真實 OCR / TranslateGemma 推論。

執行 CLI tests：

```bash
php /DATA/3waAIHub/scripts/run_tests.php
```

可指定測試 DB，避免污染正式 SQLite：

```bash
AIHUB_TEST_DB=/tmp/3waaihub_test.sqlite php /DATA/3waAIHub/scripts/run_tests.php
```

Benchmark skeleton：

```bash
php /DATA/3waAIHub/scripts/benchmark.php --case=host_smoke
php /DATA/3waAIHub/scripts/benchmark.php --case=pack_catalog_scan
php /DATA/3waAIHub/scripts/benchmark.php --case=hello_api
```

後台頁：

```text
http://localhost/3waAIHub/admin/benchmarks.php
http://localhost/3waAIHub/admin/api_docs.php
```

API 範例文件：

```text
docs/api_examples.md
```

## Local HubPack Catalog

HubPack 是模板，HubService 是安裝後的 service instance。同一個 pack 可以安裝多次，每次使用不同的 `service_key` / `mode` / `local_port`。

Local Catalog 會掃描：

```text
packs/catalog.json
packs/*/pack.json
```

後台入口：

```text
http://localhost/3waAIHub/admin/marketplace.php
http://localhost/3waAIHub/admin/packs.php
```

第一批 catalog：

- `ocr-ppocrv5`
- `translate-gemma12b`

### ocr-ppocrv5 Runtime Level

`ocr-ppocrv5` 目前停在 L1 `api_mock`：

- Docker image 可 build
- container 可啟動
- `GET /health` 回 ok
- `POST /ocr/image` 支援圖片上傳並回 mock OCR JSON
- `api.php?mode=ocr` 可透過 gateway proxy 到 service

這一版尚未安裝 PaddleOCR、尚未下載模型、尚未做真實 OCR 推論。

`pack.json` schema v0.1 必要欄位：

- `schema_version`
- `id`
- `name`
- `version`
- `category`
- `type`
- `execution_type`
- `default_mode`
- `description`
- `runtime`
- `gateway`
- `hardware`
- `queue`
- `storage`
- `env`

安裝 service instance 會產生：

```text
data/services/{service_key}/.env
data/services/{service_key}/docker-compose.generated.yml
```

generated compose 只 bind `127.0.0.1`，local port 使用 `18100-18999`。

## Storage Settings

大型模型、cache、upload、result 不放 `packs/`，也不包進 Docker image。預設路徑：

```text
AIHUB_MODELS_DIR=/DATA/3waAIHub/data/models
AIHUB_CACHE_DIR=/DATA/3waAIHub/data/cache
AIHUB_UPLOADS_DIR=/DATA/3waAIHub/data/uploads
AIHUB_RESULTS_DIR=/DATA/3waAIHub/data/results
AIHUB_LOGS_DIR=/DATA/3waAIHub/data/logs
```

後台「設定」可改 Models / Cache / Uploads / Results / Logs 目錄，以及 Docker local port 範圍。大型模型建議放大硬碟，例如：

```text
AIHUB_MODELS_DIR=/DATA/aihub_models
AIHUB_CACHE_DIR=/DATA/aihub_cache
```

若設定成不存在的 root-level 目錄，Web UI 不會自動建立；請用 CLI 建目錄並修權限。Docker data-root 只做偵測與警告，不由 Web 搬移。

範例設定檔：

```text
.env.example
```

## 權限修復

若 PHP / Apache 以 `www-data` 執行，請用：

```bash
sudo WEB_GROUP=www-data ./scripts/fix_permissions.sh
```

腳本會使用：

- 目錄：`775` / 需要 web group 時使用 setgid
- 檔案：`664`

不會使用 `chmod 777`。

## Host Bootstrap

需要在新主機安裝 Docker：

```bash
sudo ./install.sh --bootstrap-host --with-docker
```

需要安裝 NVIDIA Container Toolkit：

```bash
sudo ./install.sh --bootstrap-host --with-nvidia
```

一次做 Docker + NVIDIA：

```bash
sudo ./install.sh --yes --bootstrap-host --with-docker --with-nvidia
```

注意：

- Docker 使用 Docker official apt repository
- 不使用 snap
- NVIDIA bootstrap 需要主機已經有 `nvidia-smi`
- 不會自動安裝 NVIDIA driver
- bootstrap logs 會寫到 `data/logs/install/`

## Demo Task Queue

提交 demo task：

```bash
curl -X POST -d 'task_type=demo_task&name=FeatherMountain' \
  http://localhost/3waAIHub/api.php?mode=task_submit
```

執行 task worker：

```bash
php scripts/task_worker.php --limit=1
```

查詢：

```bash
curl 'http://localhost/3waAIHub/api.php?mode=task_status&task_id=1'
curl 'http://localhost/3waAIHub/api.php?mode=task_result&task_id=1'
curl 'http://localhost/3waAIHub/api.php?mode=task_log&task_id=1'
```

## Runtime 檔案

所有 runtime 檔案都放在 `data/`，不得上版：

- `data/3waaihub.sqlite`
- `data/logs/`
- `data/jobs/`
- `data/models/`
- `data/results/`
- `data/uploads/`
- `data/cache/`
- `data/services/`

此 repo 是公開 GitHub repo，請勿 commit：

- SQLite DB
- logs
- `.env`
- API key
- 任何 host 私有資訊

## Apache 防護

專案附帶 `.htaccess`，會阻擋直接 HTTP 存取：

- `data/`
- `app/`
- `scripts/`
- `packs/`
- `.git/`
- SQLite / log / shell script / local docs

Apache 需允許此專案目錄使用：

```apache
AllowOverride FileInfo AuthConfig Limit
```

## 驗證

```bash
find . -path './data' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php -d assert.exception=1 scripts/self_check.php
bash -n install.sh scripts/*.sh crontab/*.sh
git diff --check
```
