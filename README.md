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
- 環境診斷與修正建議
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

建議用可信任的本機帳號執行 `command_worker.php`。若使用 `john`：

```bash
sudo usermod -aG docker john
sudo -iu john docker info
sudo -iu john php /DATA/3waAIHub/scripts/command_worker.php --limit=5
```

cron 範例：

```cron
* * * * * php /DATA/3waAIHub/scripts/command_worker.php --limit=5 >> /DATA/3waAIHub/data/logs/command_worker.log 2>&1
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
- `data/results/`
- `data/cache/`

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
bash -n install.sh scripts/fix_permissions.sh scripts/bootstrap_self_check.sh
git diff --check
```
