# 3waAIHub

Current: `v0.2.x` / Local Catalog + Token Auth MVP.

3waAIHub Local 是一個本機 AI 服務管理入口。目標是讓一台新主機安裝後，可以用 SQLite 管理服務、用後台排程啟停 Docker 服務，並透過 `api.php` 對外提供 API。

目前已完成 Local HubPack Catalog、多 Service Instance、service-level IP whitelist、API trace、Bearer token auth、SQLite retention guard、Dashboard metrics、Pack hardware preflight、`hello` L5 reference Pack、`ocr-ppocrv5` / `yolo` / `sam3` / `translate-gemma12b` / `tts-voxcpm2` / `structure-ppstructurev3` / `docparser` L5 benchmark-ready Pack，以及 `whisper-asr` experimental Pack。

## 功能

- 本機管理後台登入
- Role-based login：`system_admin` / `customer`
- Customer Portal：我的服務、我的 Token、Token IP 白名單、用量統計、帳號資料與變更密碼
- SQLite metadata
- `hello-service` Docker 啟動 / 停用 / 重啟
- 背景 command worker 執行 host 操作
- 服務 log 檢視
- `api.php?mode=hello` sync API gateway
- API Members / Bearer token / mode permission / usage tracking
- 後台 API 測試場，可用本機 gateway server-side 測 API，並產生目前 host 的 curl / PHP / JS fetch 範例
- API 文件頁會依目前後台 host 產生 curl 範例，避免公開站仍顯示 localhost
- 未登入公開 API 文件與 Agent Manifest，可用 settings 控制是否啟用與是否 local-only
- 根目錄首頁與後台 dashboard 都提供 Public API Docs / Agent Manifest 入口
- SAM3 real inference 支援 `output_format=metadata|polygon|rle|both`
- SQLite-backed demo task queue
- HubPack registry 與 hello pack 安裝
- Storage settings / model directory
- 後台 shell 中文化、站台標題設定、設定頁分頁
- Marketplace 與模型倉庫卡片式後台 UI
- 服務管理卡片式 UI、狀態摘要與舊版白名單退場提示
- 服務狀態拆分：啟用 / 容器 / 健康 / 設定 / 最後工作
- Dashboard 總覽中控台、服務健康摘要、API 24h 統計、L5 Pack 數、Pack readiness、模型/Docker 空間、Public API Docs / Agent Manifest 狀態與 quick links
- Log Explorer 記錄中心：API 記錄、背景工作、服務記錄、系統記錄分頁
- 環境診斷與修正建議
- Service IP whitelist 與 API access logs
- `.htaccess` 阻擋直接下載 runtime/internal 檔案
- Marketplace Pack preflight，依最新 host metrics 判斷 Docker / GPU / VRAM / compute capability / storage

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

登入後請到「設定」修改密碼。預設 `admin` 會標記為 `role=system_admin` 與 `is_protected=1`，不可停用、不可降級，也不可把系統改到沒有任何啟用中的 `system_admin`。

## 角色與客戶 Portal

3waAIHub 目前有兩種登入角色：

- `system_admin`：可管理服務、HubPack、模型、客戶、API Member / Token、設定、Log 與 Benchmark。
- `customer`：只能看自己的可用服務、自己的 Token、自己的 Token IP 白名單、自己的用量與帳號資料。

客戶帳號由系統管理員建立，不開放自助註冊。管理入口：

```text
admin/customers.php
admin/customer_edit.php?action=create
```

建立 customer 時會連結一筆 `api_members`，之後 customer 建立的 Token 會掛在自己的 API Member 下。管理員指派 customer 可用的 `mode` 後，customer 建立 Token 時只會取得那些 mode permission。
Customer 的 API 測試場只顯示「管理員授權 mode」且「自己的有效 Token 已授權」的交集，其他客戶的 Token permission 不會影響可見 mode。

Customer Portal 入口：

```text
admin/my_services.php
admin/my_tokens.php
admin/my_ip_whitelist.php
admin/my_usage.php
admin/my_profile.php
admin/change_password.php
```

Customer 頁面不顯示 `local_port`、Docker compose 路徑、host model path、SQLite path、worker path 或其他客戶資料。舊版 service-level whitelist 仍保留給 `system_admin` 相容使用，customer 只能管理自己的 token IP whitelist。

## Login Protection

登入頁有 IP lockout 保護：

- 同一 `REMOTE_ADDR` 在 10 分鐘內帳密驗證失敗 3 次，鎖定 5 分鐘。
- 鎖定期間不驗證帳密。
- 成功登入會重置該 IP 的失敗次數。
- 第一版只信任 `REMOTE_ADDR`，不使用 `X-Forwarded-For` / `X-Real-IP` / `CF-Connecting-IP`。

預設設定：

```text
AIHUB_LOGIN_MAX_FAILED_ATTEMPTS=3
AIHUB_LOGIN_LOCK_MINUTES=5
AIHUB_LOGIN_FAIL_WINDOW_MINUTES=10
```

登入稽核資料寫入 `login_attempts`，目前不在後台 UI 展示；之後可接 Login Audit UI。

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
服務頁會每 2 秒輪詢 `admin/job_status.php`，顯示 queued / running job 的 progress、stage、current message 與 stdout/stderr tail。
完整背景工作歷史集中在 `admin/log_explorer.php?tab=jobs`，服務頁只保留目前狀態、操作入口與最後一筆工作摘要。服務卡片會分開顯示啟用狀態、容器狀態、健康狀態、設定狀態與最後工作；容器 `running` 不代表 health 一定正常。

Docker 操作已拆分：

- `Build`：只執行 `docker compose build --progress=plain`
- `Start`：image 已存在時只執行 `docker compose up -d`，不會自動加 `--build`
- `Rebuild`：明確重新 build image，目前不加 `--no-cache`
- `Restart`：只執行 `docker compose restart`
- `Stop`：沿用 compose down 策略

generated compose 會使用固定 image tag：

```text
3waaihub-{service_key}:{pack_version}
```

例如：

```text
3waaihub-ocr-main:0.1.0
```

`Start` 會用 `docker image inspect <image>` 檢查 image 是否存在。若 image 不存在，`AIHUB_AUTO_BUILD_MISSING_IMAGE=1` 會在同一個 start job 先 Build 再 Start；設為 `0` 時，start job 會提示先 Build。

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

系統 cron 會每分鐘呼叫專案內的 `crontab/1min.sh`。這支 script 自己使用 `flock` 防重入，先檢查 runtime 權限、必要時執行 `scripts/fix_permissions.sh`，再收集一次 host metrics snapshot，最後於同一分鐘內用短 delay loop 執行 `scripts/command_worker.php --limit=5` 與 `scripts/task_worker.php --limit=5`。

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

若沒有安裝內建 worker cron，也可另用 cron 每分鐘收集一次：

```cron
* * * * * php /DATA/3waAIHub/scripts/collect_host_metrics.php >/dev/null 2>&1
```

Dashboard 圖表使用 ECharts CDN；離線環境可能只能看到文字卡片，圖表不會載入。

## API Access Control

`api.php?mode=xxx` 對外預設需要 Bearer token。localhost 可由 `AIHUB_LOCALHOST_BYPASS_TOKEN=1` 略過 token，方便本機 smoke test 與維運。

最小流程：

```text
admin/api_members.php      建立 API Member
admin/api_tokens.php       建立 token，明文只顯示一次
admin/api_token_permissions.php  授權可用 mode
admin/api_token_whitelist.php    選配 token IP / CIDR whitelist
admin/api_usage.php        每日 usage aggregate
```

外部呼叫：

```bash
curl "<BASE_URL>?mode=hello" \
  -H "Authorization: Bearer <TOKEN>"
```

第一次介接建議流程：

1. 到 `admin/api_members.php` 建立 API member / token。
2. 到 `admin/api_token_permissions.php` 授權可用 `mode`。
3. 開 `admin/playground.php`，選 service mode 並貼上 token。
4. 執行測試。Playground 會先檢查服務是否啟用、容器是否執行、health 是否可用，再顯示 response 與 `request_id`。
5. 複製 curl / PHP / JS fetch 範例到外部系統；API 測試場與 API 文件的範例網址會使用目前後台頁面的 host。後台實測本身會走本機 `127.0.0.1` gateway，避免主機打自己的公開網域時遇到 hairpin timeout。

未登入介接文件：

- `public_api_docs.php`：公開 API 文件，預設允許未登入讀取。
- `api_manifest.json.php`：給 AI agent / Codex / MCP 讀取的 machine-readable contract，預設允許未登入讀取。
- `docs/client_quickstart.md`：Client Integration Starter Kit，整理交付流程、最小 curl / PHP / JS fetch 範例與 smoke client。

公開文件與 manifest 只提供外部介接資訊：`mode`、`pack_id`、`method`、`content-type`、request fields、response keys、error codes 與 `<TOKEN>` 範例。它們不顯示 admin links、local_port、Docker compose path、host model path、log path、SQLite path 或 token 明文。

相關設定位於「系統設定 / API 與安全」：

```text
AIHUB_PUBLIC_API_DOCS=1
AIHUB_PUBLIC_API_MANIFEST=1
AIHUB_PUBLIC_API_LOCAL_ONLY=0
```

Token 儲存只保留 `sha256` hash 與 prefix，不保存明文。可設定 `valid_from`、`valid_until`、revoke、停用，並以 mode permission 控制可呼叫的服務。

Token IP whitelist 沒有規則時允許任意來源；有規則時必須符合其中一條。通過 token 後，若 service-level whitelist 有規則，仍會依 `AIHUB_ALLOW_LEGACY_SERVICE_IP_WHITELIST=1` 套用既有 service whitelist。

`api.php?mode=xxx` 也會依 service instance 做 IP whitelist 檢查，舊 service whitelist 預設只允許 localhost：

- `127.0.0.1`
- `::1`

外部 IP 優先用 API token whitelist 管理。舊版 service-level whitelist 仍保留相容用途，可從服務管理的進階操作進入。支援單一 IP 與 CIDR，例如：

```text
192.168.1.10
192.168.1.0/24
::1
```

目前不信任 `X-Forwarded-For`，避免來源 IP 被偽造。若未來要接 reverse proxy，再另外加 trusted proxy setting。

後台入口：

```text
admin/services.php          服務狀態、啟停操作、最後工作摘要
admin/api_members.php       API member / token 管理
admin/api_usage.php         Token daily usage
admin/log_explorer.php      記錄中心：API Trace / 背景工作 / 服務記錄 / 系統記錄
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
PhaseS-4 起 access log 會記錄 `member_id`、`token_id`、upload bytes、response bytes；每日彙總寫入 `api_token_usage_daily`。

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
php /DATA/3waAIHub/scripts/benchmark.php --pack=hello --case=hello_api
php /DATA/3waAIHub/scripts/benchmark.php --pack=ocr-ppocrv5 --case=ocr_mock_image
php /DATA/3waAIHub/scripts/benchmark.php --service=ocr-main --case=ocr_real_image
php /DATA/3waAIHub/scripts/benchmark.php --pack=yolo --case=yolo_mock_image
php /DATA/3waAIHub/scripts/benchmark.php --service=yolo-main --case=yolo_real_image
php /DATA/3waAIHub/scripts/benchmark.php --pack=sam3 --case=sam3_mock_image
php /DATA/3waAIHub/scripts/benchmark.php --service=sam3-main --case=sam3_real_image
php /DATA/3waAIHub/scripts/benchmark.php --service=sam3-main --case=sam3_real_polygon_image
php /DATA/3waAIHub/scripts/benchmark.php --service=structure-main --case=structure_page_pdf
php /DATA/3waAIHub/scripts/benchmark.php --service=structure-main --case=structure_10page_pdf
php /DATA/3waAIHub/scripts/benchmark.php --pack=docparser --case=docparser_submit_pdf
php /DATA/3waAIHub/scripts/benchmark.php --pack=docparser --case=docparser_submit_10page_pdf
```

後台頁：

```text
http://localhost/3waAIHub/admin/benchmarks.php
http://localhost/3waAIHub/admin/api_docs.php
http://localhost/3waAIHub/admin/pack_readiness.php?pack_id=hello
http://localhost/3waAIHub/admin/pack_readiness.php?pack_id=ocr-ppocrv5
http://localhost/3waAIHub/admin/pack_readiness.php?pack_id=yolo
http://localhost/3waAIHub/admin/pack_readiness.php?pack_id=sam3
```

API 範例文件：

```text
docs/api_examples.md
```

PhaseS-4.1 token API smoke：

```bash
php /DATA/3waAIHub/scripts/token_api_smoke.php
```

這支 smoke 會用臨時 SQLite DB、臨時 PHP app server 與 OCR mock server，建立 member/token、授權 `mode=ocr`、設定 token IP whitelist，然後用 `curl` 帶 Bearer token 呼叫 `api.php?mode=ocr`，最後檢查 Log Explorer 查詢資料與 daily usage aggregate。

外部 client smoke：

```bash
php /DATA/3waAIHub/scripts/api_smoke_client.php \
  --base-url=https://nature.focusit.tw/3waAIHub/api.php \
  --token=<TOKEN>
```

這支 smoke 使用既有服務與真實 Bearer token 呼叫 `hello,ocr,yolo,translate,sam3`，預設走 mock / lightweight path；需要真推論再加 `--real`。

## Service Runtime Settings

PhaseP-2 起，Pack 可用 `settings_schema` 宣告可調 runtime/model 設定；安裝後的 service instance 會把實際值存在 `service_settings`，後台 `admin/service_settings.php?service_id=ID` 可編輯並重新產生 `.env`。

目前支援型別：

- `text`
- `integer`
- `number`
- `boolean`
- `select`
- `path`
- `secret`

`.env` 只輸出 storage/global settings、service fixed info，以及 Pack schema 宣告過的 settings，不提供任意 env key editor。若異動欄位標記 `restart_required=true`，服務列表會顯示需 Restart。

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
- `yolo`
- `bioclip`
- `sam3`
- `whisper-asr`
- `tts-voxcpm2`
- `structure-ppstructurev3`

### hello Reference Pack

`hello` 目前是 L5 `benchmark-ready` reference Pack：

- `runtime_level=L5-benchmark-ready`
- `target_level=L5-benchmark-ready`
- Pack manifest 宣告 `l5_contract`
- `hello_api` benchmark 可驗最小 sync API contract
- Pack Readiness 在 `hello_api` PASS 後可顯示 11/11

### ocr-ppocrv5 Runtime Level

`ocr-ppocrv5` 目前已達 L5 `benchmark_ready`：

- Docker image 可 build
- container 可啟動
- `GET /health` 回 ok，並帶 `runtime_level=L5-benchmark-ready` 與 storage 狀態
- `POST /ocr/image` 預設回 mock OCR JSON，`OCR_REAL_INFERENCE=1` 或表單 `real_inference=1` 時執行 PaddleOCR 圖片推論
- `api.php?mode=ocr` 可透過 gateway proxy 到 service
- image 使用 NVIDIA CUDA 12.9 runtime base
- Docker build 階段安裝 FastAPI runtime、PaddleOCR / PaddlePaddle dependency，並執行 `pip check`
- Docker build 階段執行 `python3 smoke.py`，只驗證 `paddleocr` / `fastapi` import 成功
- runtime 掛載 `${AIHUB_MODELS_DIR}/paddleocr:/models/paddleocr`
- runtime 掛載 `${AIHUB_CACHE_DIR}/paddleocr:/cache/paddleocr`
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`
- `storage_smoke.py` 可在 container 內檢查三個目錄是否存在、可讀、可寫
- `model_smoke.py` 可手動初始化 PaddleOCR，檢查模型/cache 是否落在掛載目錄，並偵測 `/root` / `/app` 可疑寫入
- `inference_smoke.py` 可手動驗證單張圖片真 OCR 推論
- `gpu_smoke.py` 可手動檢查 Paddle CUDA compile/device 狀態，不下載模型、不跑推論
- Pack manifest 已宣告 `target_level=L5-benchmark-ready` 與 `l5_contract`
- `ocr_mock_image` benchmark 可驗 API contract required keys
- `ocr_real_image` benchmark 可驗單張圖片真 OCR 與 blocks contract
- Pack Readiness 可在兩個 benchmark 都 PASS 後顯示 11/11
- 一般 `ocr-main` 不強制 GPU；`ocr-gpu` 這類 service key 才會在 generated compose 加入 `gpus: all`
- `/health` 會回報 GPU requested / available / effective device；目前預設 `paddlepaddle` 為 CPU build，GPU 不可用時會依 `OCR_GPU_FALLBACK_TO_CPU=1` fallback 到 CPU

### translate-gemma12b Runtime Level

`translate-gemma12b` 目前已達 L5 `benchmark-ready`：

- generated compose 拆成 `ollama` sidecar 與 `translator-api`
- Ollama 模型主倉掛載 `${AIHUB_MODELS_DIR}/ollama:/root/.ollama`
- adapter 掛載 `${AIHUB_CACHE_DIR}/translate:/cache/translate`
- adapter 掛載 `${SERVICE_DATA_DIR}:/data/service`
- Docker build 階段執行 `python3 smoke.py`，只驗證 `fastapi` / `requests` import
- `scripts/ollama_model_pull.php` 可手動拉取 service 設定的 Ollama model
- `model_smoke.py` 可在 `translator-api` container 內檢查 `OLLAMA_MODEL` 是否存在
- `inference_smoke.py` 可在 `translator-api` container 內驗證單句真翻譯
- `GET /health` 檢查 Ollama `/api/tags`、model present 與 adapter storage 狀態
- `POST /translate` 預設回 mock translation JSON
- `real_inference=1` 或 `TRANSLATE_REAL_INFERENCE=1` 會呼叫 Ollama `/api/generate`
- `translate_mock_text` / `translate_real_text` benchmark 可驗 JSON API contract
- Pack Readiness 可在兩個 benchmark 都 PASS 後顯示 11/11

本階段只做 non-streaming 單次翻譯，不做 streaming、batch、文件翻譯、chat 或 glossary。

手動拉模型：

```bash
php scripts/ollama_model_pull.php --service=translate-main
php scripts/ollama_model_pull.php --service=translate-main --model=translategemma:12b-it-q4_K_M
```

模型 smoke：

```bash
docker compose -f data/services/translate-main/docker-compose.generated.yml exec translator-api python3 /app/model_smoke.py
```

真翻譯 smoke：

```bash
docker compose -f data/services/translate-main/docker-compose.generated.yml exec translator-api python3 /app/inference_smoke.py
```

Benchmark：

```bash
php scripts/benchmark.php --pack=translate-gemma12b --case=translate_mock_text
php scripts/benchmark.php --service=translate-main --case=translate_real_text
```

測試：

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Content-Type: application/json" \
  -d '{"text":"Hello, this is a local translation test.","source_lang":"en","target_lang":"zh-TW"}'

curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Content-Type: application/json" \
  -d '{"text":"That was a wonderful time.","source_lang":"en","target_lang":"zh-TW","real_inference":1}'
```

### yolo Runtime Level

`yolo` 目前已達 L5 `benchmark_ready`：

- `POST /detect/image` 支援 multipart 圖片上傳
- Docker build 階段安裝 FastAPI runtime 與 `ultralytics`
- Docker build 階段執行 `python3 smoke.py`，只驗證 `ultralytics` / `fastapi` import 成功
- `GET /health` 回 `runtime_level=L5-benchmark-ready` 與 storage 狀態
- `POST /detect/image` 預設仍回 mock JSON 與空 `detections`
- `YOLO_REAL_INFERENCE=1` 或表單 `real_inference=1` 時執行單張圖片真 detection
- runtime 掛載 `${AIHUB_MODELS_DIR}/yolo:/models/yolo`
- runtime 掛載 `${AIHUB_CACHE_DIR}/yolo:/cache/yolo`
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`
- `storage_smoke.py` 可在 container 內檢查三個目錄是否存在、可讀、可寫
- `model_smoke.py` 可手動初始化 YOLO，確認模型/cache 不落在 image layer
- `inference_smoke.py` 可手動驗證單張圖片 detection 呼叫
- `yolo_mock_image` / `yolo_real_image` benchmark 可驗 contract
- Pack Readiness 可在兩個 benchmark 都 PASS 後顯示 11/11
- 預設 `YOLO_USE_GPU=0`，先以 CPU 跑通真 detection

### sam3 Runtime Level

`sam3` 目前是 L5 `benchmark-ready`：

- `POST /segment/image` 支援 multipart 圖片上傳
- prompt 使用 `prompt_type` / `points_json` / `boxes_json` / `text` form 欄位
- 預設回 mock segmentation JSON，`real_inference=1` 會執行單張圖片真 inference smoke
- mask metadata 會回 `bbox`、`score`、`confidence`、`label_name`；`score` 與 `confidence` 目前同值，保留兩者方便不同 client 介接
- `output_format=metadata|polygon|rle|both` 可選 mask metadata、legacy 多 contour `polygon=[[[x,y]...]]`、前端友善 `polygons=[{"outer":[[x,y]],"holes":[[[x,y]]]}]` 或 raw uncompressed RLE
- `prompt_type=points` 需提供 `points_json`，例如 `{"points":[[320,240]],"labels":[1]}`
- `prompt_type=text` 需提供 `text`，例如 `mammal/insect/plant`；這會走 SAM3 semantic predictor，適合語意概念分割
- `GET /health` 回 `runtime_level=L5-benchmark-ready`、storage 狀態、model present 狀態與 runtime dependency 狀態
- runtime 掛載 `${AIHUB_MODELS_DIR}/sam3:/models/sam3`
- runtime 掛載 `${AIHUB_CACHE_DIR}/sam3:/cache/sam3`
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`
- `storage_smoke.py` 可在 container 內檢查 models/cache/service_data 與 HuggingFace/Torch cache 目錄
- `model_smoke.py` 可在 container 內檢查 `/models/sam3` checkpoint 是否存在且尺寸不像 smoke fake file
- `inference_smoke.py` 可在 container 內驗證 `real_inference=1` 的單張圖片 smoke
- `sam3_mock_image` / `sam3_real_image` / `sam3_real_polygon_image` benchmark 可驗 contract
- `SAM3_CHECKPOINT` 可從 `/DATA/models/sam3/*.pt` / `*.pth` / `*.safetensors` / `*.ckpt` 選用
- generated compose 會加入 `gpus: all`

L5 缺 checkpoint 時 `/health` 會 `ready=false` 並回 `model_not_present`；本階段不下載 checkpoint、不產生 mask artifact，也不做批次或影片 segmentation。

### bioclip Runtime Level

`bioclip` 目前是 L5 `benchmark-ready` 物種辨識評估 Pack：

- `POST /classify/image` 支援 multipart 圖片上傳。
- 預設 `BIOCLIP_REAL_INFERENCE=1`，使用 OpenCLIP 載入 `hf-hub:imageomics/bioclip` 跑 zero-shot classification。
- `candidate_labels` 可傳逗號分隔文字或 JSON array。
- `GET /health` 回 `runtime_level=L5-benchmark-ready`、dependency、CUDA 與 storage 狀態。
- runtime 掛載 `${AIHUB_MODELS_DIR}/bioclip:/models/bioclip`。
- runtime 掛載 `${AIHUB_CACHE_DIR}/bioclip:/cache/bioclip`。
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`。
- `smoke.py` 驗證 FastAPI / Pillow / NumPy / Torch / OpenCLIP import 成功，不下載模型。
- `storage_smoke.py` 可在 container 內檢查 models/cache/service_data 與 HuggingFace cache 目錄。
- `model_smoke.py` 會下載/預熱模型到 `/models/bioclip/huggingface`。
- `inference_smoke.py` 會跑一張 synthetic image 的真推論 smoke。
- `bioclip_mock_image` / `bioclip_real_image` benchmark 可驗 contract。
- Pack Readiness 可在 real benchmark PASS 後顯示 11/11。

本階段不接 NatureID 既有 BioCLIP server、不做 taxonomy DB、不做批次分類。

### whisper-asr Runtime Level

`whisper-asr` 目前是 L3 `storage-mount`：

- `POST /asr/audio` 支援 multipart 音訊上傳
- 預設回 mock transcription JSON，`real_inference=1` 會回 `runtime_not_ready`
- `GET /health` 回 `runtime_level=L3-storage-mount` 與 storage 狀態
- runtime 掛載 `${AIHUB_MODELS_DIR}/whisper:/models/whisper`
- runtime 掛載 `${AIHUB_CACHE_DIR}/whisper:/cache/whisper`
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`
- `smoke.py` 只驗證 `fastapi` / `faster_whisper` import 成功，不載模型、不下載、不推論
- `storage_smoke.py` 可在 container 內檢查 models/cache/service_data 與 HuggingFace cache 目錄

本階段不做真 transcription、不下載模型、不做 VAD、diarization、subtitle 或 streaming。

### structure-ppstructurev3 Runtime Level

`structure-ppstructurev3` 目前是 L5 `benchmark-ready` document parsing Pack：

- `POST /v1/parse` 支援 PDF 或文件圖片上傳。
- `real_inference=1` 會以 PP-StructureV3 解析並回傳 Markdown / JSON。
- PaddleOCR runtime pin：
  - `paddleocr[doc-parser]==3.7.0`
  - `paddlepaddle-gpu==3.3.1` CUDA 12.9 wheel for RTX 5090 / SM120 support
  - `numpy>=1.24,<2.4` for PaddleX compatibility
- 目前 5090 主機上的 `structure-main` 已切到 `STRUCTURE_DEVICE=gpu`，generated compose 已加 `gpus: all`。
- 新安裝的 `structure-ppstructurev3` instance 預設仍保留 `STRUCTURE_DEVICE=cpu`，需要 GPU 時請在服務設定切換並重建 / 重啟。
- 大型 PDF 解析需要保留顯存餘裕。若同時常駐 SAM3 / VoxCPM2 / 其他外部 GPU 服務，PP-StructureV3 仍可能因 VRAM 不足回 `parse_failed` / Paddle `ResourceExhaustedError`。
- 現有 `structure-main` upload limit 已調整為 `STRUCTURE_MAX_UPLOAD_MB=512`，可處理較肥的維修手冊；更大的 PDF 仍建議先拆批或提高服務設定後重啟。
- 文件批次翻譯若 GPU 顯存緊張，可在 `translate-main` 設定 `OLLAMA_NUM_GPU=0`，讓 TranslateGemma 走 CPU fallback；速度較慢但可避免 Ollama CUDA OOM。
- `GET /health` 回 `runtime_level=L5-benchmark-ready`、dependency 與 storage 狀態。
- runtime 掛載 `${AIHUB_MODELS_DIR}/ppstructurev3:/models/ppstructurev3`。
- runtime 掛載 `${AIHUB_CACHE_DIR}/ppstructurev3:/cache/ppstructurev3`。
- runtime 掛載 `${SERVICE_DATA_DIR}:/data/service`。
- API 測試場支援 `mode=structure`。
- 大型 PDF 建議走 `task_submit` 的 `task_type=structure_parse`，worker 會將 Markdown / JSON 寫入 `data/results/task_{task_id}/` 並登錄 `task_artifacts`。
- L5 benchmark cases：
  - `php scripts/benchmark.php --service=structure-main --case=structure_page_pdf`
  - `php scripts/benchmark.php --service=structure-main --case=structure_10page_pdf`

本階段不做 viewer、不做批次管理 UI。

### llm-gemma4-12b Chat Pack

`llm-gemma4-12b` 是 3waAIHub 的第一個通用 LLM Pack。

- Default mode: `chat`
- Gateway contract: `POST api.php?mode=chat`
- Pack boundary: Hub `/chat` adapter，內部才轉呼叫 vLLM sidecar
- First slice: text-only、non-streaming JSON、Q4 real inference
- Deferred: streaming、vision、tool calling、OpenAI-compatible Gateway

Example:

```bash
curl -X POST "https://nature.focusit.tw/3waAIHub/api.php?mode=chat" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。",
    "real_inference": true,
    "max_tokens": 512
  }'
```

原則是模型適配 Hub，不讓 Hub Core 反過來遷就模型。

### docparser Runtime Level

`docparser` is PhaseDoc-1C `L5-benchmark-ready`.

It accepts technical manual PDFs through `mode=docparser` multipart upload, backed by `task_type=docparser_parse`.
It calls `structure-main` for document structure and `translate-main` for block translation, then writes DocIR, HTML, Markdown, TOC, RAG chunks, quality report, manifest and figure assets under `data/results/task_{task_id}/docparser/`.

Submit response includes task follow-up links:

- `status_url`
- `result_url`
- `log_url`
- `cancel_url`
- `artifact_url_template`
- figure crops are listed at `artifact_summary.figure_assets.items[]` with per-image `artifact_id`

DocParser submit has a lightweight artifact cache. When the uploaded PDF SHA-256 and conversion rules match a fresh completed task, and the required artifacts still exist, the gateway returns the existing task instead of enqueueing a new conversion:

- default TTL: `AIHUB_DOCPARSER_CACHE_TTL_DAYS=7`
- cache version: `AIHUB_DOCPARSER_CACHE_VERSION=docparser-v0.1`
- cache hit response includes `cached=true`, `cache_hit_task_id`, and the usual `status_url` / `result_url` / `log_url`
- cache key includes PDF hash, profile, target language, translation flag, structure mode, translate mode and cache version

DocParser supports cooperative cancel. Queued tasks are cancelled immediately; running `docparser_parse` tasks set `cancel_requested=1` and stop at the next worker checkpoint, such as after structure parsing or before the next translation block. The worker does not hard-kill PHP, Docker containers or backend HTTP requests.

DocParser translation calls retry up to 3 attempts per block/chunk before failing the task.

DocParser also supports a slim translation repair task for partial retry. Use it when `quality_report.missing_translation_blocks` lists missing block translations:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=task_submit" \
  -H "Authorization: Bearer <TOKEN>" \
  -F task_type=docparser_repair_translation \
  -F task_id=<SOURCE_TASK_ID> \
  -F block_ids=p12-b4,p14-b8
```

`docparser_repair_translation` only reloads the registered DocIR artifact, translates the selected blocks, rewrites the original DocParser artifacts, and recomputes the quality report. It does not rerun OCR, PP-StructureV3 layout parsing, figure extraction, or image crops. Already translated blocks are skipped.

L5 readiness covers:

- PP-StructureV3 `parsing_res_list` normalization into DocIR blocks.
- Figure metadata with page, bbox, caption and final `block_id` provenance.
- Figure crops under `assets/figures/*.png`, linked from HTML / Markdown exports.
- Oversized translation block splitting before calling TranslateGemma.
- Translation progress updates while worker processes long manuals.
- Generic technical-manual quality gate that does not require a fixed English sample title.
- Chinese-source identity handling so already-Chinese text is not misclassified as fake translation.
- Table blocks are translated and quality reports include `translation_coverage_by_type.table`, `missing_translation_block_ids_by_type.table`, and page/block details for missing table translations.
- Async submit benchmark cases:
  - `php scripts/benchmark.php --pack=docparser --case=docparser_submit_pdf`
  - `php scripts/benchmark.php --pack=docparser --case=docparser_submit_10page_pdf`
- Task result contract includes `artifact_summary.figure_assets.items[].artifact_id` for visual RAG crop download.
- Submit benchmarks verify API contract and cancel the queued demo task after validation. Use `docparser_acceptance.php --task-id=<SUCCESS_TASK_ID>` to verify completed document quality.

Verified real document:

- `/DATA/docs/NSR維修手冊.pdf`
- Task `10`
- 79 pages
- 852 DocIR blocks
- 194 headings
- 23 tables
- 144 figure assets
- `docparser_acceptance.php --task-id=10` PASS

Example:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=task_submit" \
  -H "Authorization: Bearer <TOKEN>" \
  -F task_type=docparser_parse \
  -F file=@manual.pdf \
  -F target_language=zh-TW \
  -F translation_required=1

php scripts/task_worker.php --limit=1
php scripts/docparser_acceptance.php --task-id=<TASK_ID>
```

PhaseDoc-1B does not do image OCR overlay, technical drawing understanding, VLM review or manual correction UI.

### tts-voxcpm2 Experimental Pack

`tts-voxcpm2` 是 VoxCPM2 experimental TTS Pack，目前已提升到 `L5-benchmark-ready`，用於驗證受管控的語音合成服務骨架與真實 VoxCPM2 推論 smoke：

- `GET /health`
- `GET /v1/models`
- `POST /v1/voice-design`
- `POST /v1/tts`
- `mode=design`：文字 + 自然語言 voice prompt 產生 WAV。
- `mode=clone`：透過 Hub 管理的 Voice Profile 做 controllable clone。
- API 測試場支援 `mode=tts`，customer 需同時具備 user mode permission 與自己的 token permission 才會看到。
- L5 benchmark cases：
  - `tts_mock_wav`
  - `tts_real_wav`
- 預設 `VOXCPM2_REAL_INFERENCE=0`，未要求真實推論時會產生固定 seed 的 deterministic mock WAV，方便快速 smoke test。
- API / Playground 傳入 `real_inference=1`，或設定 `VOXCPM2_REAL_INFERENCE=1`，會 lazy import 官方 `voxcpm==2.0.3` runtime 並輸出真實 VoxCPM2 WAV。
- Runtime image 需要 `soundfile`、`libsndfile1` 與 `gcc/g++`，後者供 Torch / Triton 第一次 warmup 編譯使用。
- 輸出 WAV sample rate 預設 `48000`。
- 長文會依 `VOXCPM2_CHUNK_CHARS` 自動切段。
- 每次輸出會寫 manifest，標記 `ai_generated=true`、`model`、`seed`、`voice_profile_id`、`reference_audio_sha256`、`duration_ms` 與 chunk count。

VoxCPM2 clone 受 Voice Profile 管制：

- 外部 API 不接受任意伺服器檔案路徑。
- Public API 只能送 `voice_profile_id` 或 `reference_audio_id`。
- Gateway 會檢查 Bearer token 對應的 `api_member` 是否擁有該 Voice Profile。
- 通過後才把參考音檔改寫成 container 內部 `/data/voice_profiles/...` 路徑。
- Voice Profile 建立、使用、刪除都寫入 `voice_profile_audit_logs`。
- 參考音檔只允許放在 `data/uploads/voice_profiles/`，compose 以 read-only 方式掛入 container。

第一版不做：

- Ultimate Clone
- ASR transcript confirmation
- LoRA 訓練
- 多人聲 Podcast 編排
- Streaming / WebSocket
- vLLM-Omni
- OpenAI-compatible API
- 語音素材管理後台
- 公開匿名 clone

測試案例放在：

```text
packs/tts-voxcpm2/acceptance/zh_tw_tts_cases.json
```

內容涵蓋繁中維修語境、數字單位、NSR / RC Valve / PGM-III、中英混合、零件編號、長文切段與固定 seed 驗收。

### Pack Preflight

Marketplace 會從最新 `host_metric_snapshots` 讀取 Station Hardware Profile，不在 Web request 直接執行 `nvidia-smi` 或 `docker info`。

先收集一次：

```bash
php scripts/collect_host_metrics.php --force
```

第一版檢查：

- Docker / Docker Compose
- `nvidia-smi`
- NVIDIA Container Toolkit
- VRAM
- GPU compute capability
- storage writable

compute capability 目前使用簡單 map，已涵蓋 RTX 5090 / RTX 5060 Ti / RTX 40/30 系與 GTX 1080 Ti；之後需要更準再接 deviceQuery。

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
- `preflight`

安裝 service instance 會產生：

```text
data/services/{service_key}/.env
data/services/{service_key}/docker-compose.generated.yml
```

generated compose 只 bind `127.0.0.1`，local port 使用 `18100-18999`。

## Storage Settings

大型模型、cache、upload、result 不放 `packs/`，也不包進 Docker image。模型是主機級大型資產，預設放在 repo 外的 `/DATA/models`，不進 git，也不跟 `3waAIHub` repo runtime 綁死；repo 搬家或重裝時可保留模型倉。

預設路徑：

```text
AIHUB_MODELS_DIR=/DATA/models
AIHUB_CACHE_DIR=/DATA/3waAIHub/data/cache
AIHUB_UPLOADS_DIR=/DATA/3waAIHub/data/uploads
AIHUB_RESULTS_DIR=/DATA/3waAIHub/data/results
AIHUB_LOGS_DIR=/DATA/3waAIHub/data/logs
```

常用模型子目錄：

```text
/DATA/models/paddleocr
/DATA/models/yolo
/DATA/models/ollama
/DATA/models/sam3
```

後台「系統設定」分成基本設定、介面顯示、儲存與模型、API 與安全、Docker 與背景工作、維護與保留、帳號密碼。可調整 `AIHUB_SITE_TITLE` / `AIHUB_SITE_SUBTITLE`、Models / Cache / Uploads / Results / Logs 目錄，以及 Docker local port 範圍。後台 `模型倉庫` 可掃描 `AIHUB_MODELS_DIR` 底下的模型資產，顯示常見子目錄、檔案大小、修改時間與 symlink skip 狀態。

Service settings 支援 Pack 宣告的 model selector。第一版已支援：

- `yolo`：`YOLO_MODEL` 可從 `/DATA/models/yolo/*.pt` / `*.onnx` 選用，仍保留文字輸入。
- `translate-gemma12b`：`OLLAMA_MODEL` 維持 Ollama tag 文字設定，並顯示 `/DATA/models/ollama` 與 Ollama manifest present/missing 狀態。
- `sam3`：`SAM3_CHECKPOINT` 可從 `/DATA/models/sam3/*.pt` / `*.pth` / `*.safetensors` / `*.ckpt` 選用，L5 缺 checkpoint 會在 health 顯示 `model_not_present`。

第一版不做模型 upload / download / delete / move，也不提供任意 host path picker。

若設定成不存在的 root-level 目錄，Web UI 不會自動建立；請用 CLI 建目錄並修權限。Docker data-root 只做偵測與警告，不由 Web 搬移。

既有安裝若仍使用 `/DATA/3waAIHub/data/models`，不會被自動覆蓋，也不會自動搬模型。建議人工搬移：

```bash
sudo mkdir -p /DATA/models
sudo rsync -aHAX /DATA/3waAIHub/data/models/ /DATA/models/
```

搬完後到後台「設定」改成：

```text
AIHUB_MODELS_DIR=/DATA/models
```

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

- 部署原始碼：目錄可進入、檔案可讀，避免 restrictive umask / archive extraction 造成 PHP bootstrap 空白 500
- 目錄：`775` / 需要 web group 時使用 setgid
- 檔案：`664`

不會使用 `chmod 777`。

若已安裝 `/etc/cron.d/3waaihub-command-worker`，root worker cron 會在每分鐘 loop 開頭檢查 `data/` runtime 目錄與 SQLite/WAL 主要檔案；只有發現 group write / setgid 權限異常時才自動執行修復。

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
curl -X POST 'http://localhost/3waAIHub/api.php?mode=task_cancel&task_id=1'
```

## Runtime 檔案

所有 runtime 檔案都放在 `data/`，不得上版：

- `data/3waaihub.sqlite`
- `data/logs/`
- `data/jobs/`
- `data/results/`
- `data/uploads/`
- `data/cache/`
- `data/services/`

模型主倉預設在 repo 外：

- `/DATA/models/`

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
