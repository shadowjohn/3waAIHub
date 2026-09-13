# 影像工具操作手冊

`image-tools` 是可擴充的本機影像工具 Pack；`upscale` 與 `upscale_task` 使用離線、可驗證的 Real-ESRGAN snapshot，為 `L5-benchmark-ready` 且 `runtime_ready=true`。`colorize` 使用固定 DDColor ModelScope snapshot；它同樣必須離線 staging，但尚未宣告 DDColor benchmark。既有驗收的版本、checksum、輸出 metadata 與 cleanup 結論見 [image-tools-acceptance.md](image-tools-acceptance.md)。

## 離線模型 staging

1. 在受控網路環境執行 provisioner，將官方 allowlist 權重先下載到 staging；不要在 API request 或 worker 中下載。
2. provisioner 會計算每個檔案的 SHA-256、大小與來源，並原子建立 `/DATA/models/image-tools/realesrgan/ready.json`。
3. 將整個 snapshot 以唯讀方式掛入 container。啟動前確認 marker 的 repository commit 是 `a4abfb2979a7bbff3f69f58f58ae324608821e27`，所有 manifest SHA-256 都與實檔相同，且沒有 symlink 或未列檔案。

模型與輸入圖片不應提交到 Git、寫入 README，或放進 log。任何 staging 失敗都保留舊 snapshot，清理 partial download 後再重試。

### DDColor 黑白變彩色 staging

DDColor source 固定為 `piddnad/DDColor@2adb63f2656ac41cbdf7b894cddd94121a3faf13`，權重固定為 `piddnad/DDColor-models@e9e7b527709c8aeb2f5e1bf701e72fd468a13baa` 的 `ddcolor_modelscope.pth`。先建置 0.1.2 image，再以可寫入的 model mount 執行一次 provision；正式服務仍只讀掛載模型。

```bash
docker compose -f data/services/image-tools-main/docker-compose.generated.yml build
docker run --rm --user 0 \
  -v /DATA/models/image-tools:/models/image-tools \
  3waaihub-image-tools-main:0.1.2 \
  python3 /app/provision_colorize_assets.py
```

provisioner 只接受 HTTPS allowlist redirect，下載後驗證 911,950,059 bytes 與 SHA-256，再原子建立 `/DATA/models/image-tools/ddcolor/ready.json`。不要在 API request、worker 或正式服務容器中下載權重。

## 安裝與 preflight

預設是 GPU-first 安裝：保留 `IMAGE_TOOLS_USE_GPU=1`、Docker NVIDIA runtime 與 CUDA driver，並確認 Docker 可看見 GPU。Hub preflight 會檢查可用 VRAM 與 `AIHUB_GPU_VRAM_SAFETY_MARGIN_MB`。GPU 啟用的 `resident_dual` 在容量不足時拒絕啟動（`resident_vram_insufficient`）；resident ingress 的 `backend=auto` 若遇到 worker 未 ready，會回 `runtime_not_ready`。`on_demand` 或 GPU-disabled 路徑才保留 `backend=auto` 依 GPU 可用性／Hub preflight 改選 CPU 的既有行為。

明確 CPU 安裝可設 `IMAGE_TOOLS_USE_GPU=0`，重新產生 Compose 後使用單容器 `on_demand` mode／role，且不應含 `gpus: all`。client 可送 `backend=cpu`；明確 `backend=cuda` 在 GPU／VRAM 不可用時必須回 `backend_unavailable`，不允許無聲 fallback。

完成 staging 後，以非 secret 的管理 token 跑 health、Docker/CUDA preflight 與同步 smoke。確認 `/health` 仍正確表示 runtime 狀態；不要把 Docker build 或 mock test 當作真實模型 acceptance。

## Resident dual workers rollout and verification

`IMAGE_TOOLS_EXECUTION_MODE=resident_dual` 是預設值：`image-tools` 是唯一公開 loopback ingress，`image-tools-upscale` 與 `image-tools-colorize` 是不公開的 GPU worker selectors。開始前 Hub 必須保留 3072 MiB（upscale）+ 1024 MiB（colorize）= 4096 MiB，以及全域 GPU safety margin。空間不足時會以 `resident_vram_insufficient` 拒絕並保持服務停止；不會逐出 BreezyVoice、Whisper 或其他 resident service。

在 Service Settings 儲存 resident 設定以重新產生 Compose，然後 Start/Restart `image-tools-main`。先建置整個 Compose project，CUDA model smoke 要在 upscale worker 執行：

```bash
docker compose -f data/services/image-tools-main/docker-compose.generated.yml build
docker compose -f data/services/image-tools-main/docker-compose.generated.yml up -d
docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools-upscale python3 /app/model_smoke.py --backend cuda
curl --fail --silent http://127.0.0.1:18113/health
docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools-upscale python3 -c 'from urllib.request import urlopen; print(urlopen("http://127.0.0.1:8000/health").read().decode())'
docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools-colorize python3 -c 'from urllib.request import urlopen; print(urlopen("http://127.0.0.1:8000/health").read().decode())'
nvidia-smi
```

確認 ingress /health 與兩個 worker health 都 ready 後，第一筆請求已是 warm；在 `upscale`、`colorize` 間切換不得 reload 任一模型。3072 MiB/1024 MiB 是 provisional reservation，不是效能保證；只有完成 measured warm-start 與 4 MP benchmark 後才能調整。

若需 rollback，先保留原本的 resident 設定與三服務 Compose，在 Hub 執行 Stop `image-tools-main`，確認 ingress 與兩個 GPU worker 都已停止；再將 `IMAGE_TOOLS_EXECUTION_MODE=on_demand` 儲存以重新產生 Compose，最後 Start `image-tools-main`。必須先停止舊 project，因為重新產生的單容器 Compose 不包含舊 workers，直接 Restart 不會移除 orphan workers，仍會佔用 VRAM。`on_demand` 是 VRAM 不足主機的明確 rollback path。

## L4a model initialization smoke

影像工具目前已完成 `L4a-model-init-smoke`：此階段只證明 marker 與三個 Real-ESRGAN model family 可在已安裝 runtime 中初始化，並不宣稱 HTTP 實際推論或 benchmark 品質。

```bash
docker compose -f data/services/image-tools-main/docker-compose.generated.yml exec -T image-tools-upscale python3 /app/model_smoke.py --backend cuda
```

命令必須只成功回傳 compact JSON；可稽核的安全 evidence 欄位為 date、image tag、exit result、backend、commit、loaded family IDs、aliases、elapsed time。完整 redacted record 見 [image-tools-acceptance.md](image-tools-acceptance.md)，其中不保存來源圖片或推論輸出。

## L5 benchmark-ready

影像工具目前為 `L5-benchmark-ready`：以固定 `smoke.png` 驗證 `realesrgan-x4plus` 的 CUDA 與 CPU 真實 HTTP 放大；每筆都必須回傳 8×12 `image/png`、五個公開 response headers 與 backend 對應的固定 SHA-256。這是品質 gate，不是延遲 SLA；L4a 初始化 smoke 保留為歷史驗證。

```bash
php scripts/benchmark.php --service=image-tools-main --case=image_tools_cuda_upscale_golden
php scripts/benchmark.php --service=image-tools-main --case=image_tools_cpu_upscale_golden
```

兩筆 benchmark 都必須最新為 `pass`，Pack readiness 的 `real_inference_benchmark_passed` 才會成立。可稽核的 L4b 直接 HTTP 與 L5 gateway evidence 見 [image-tools-acceptance.md](image-tools-acceptance.md)；只保存日期、image tag、model、尺寸、hash、headers 結論與 elapsed time。

## API 操作

同步 CUDA 或 CPU smoke 使用 `mode=image-tools&operation=upscale`，將 `operation=upscale` 同時放 query 與 multipart form。上傳 `image` 或 `base64_string`，但不可兩者同時傳。成功只接受 `image/png`，並記錄 `X-3waAIHub-Model`、`X-3waAIHub-Backend`、`X-3waAIHub-Elapsed-Ms`、`X-3waAIHub-Width`、`X-3waAIHub-Height`。

`outscale` 僅用於同步 `upscale`，只接受 `2`、`3` 或 `4`；省略時維持所選 model 的原生輸出倍率。非同步 `upscale_task` 不接受 `outscale`。

同步 `colorize` 固定使用 `ddcolor-modelscope`，接受 `image` 或 `base64_string` 與 `backend`，不接受 `model` 或 `outscale`。它會保留原圖尺寸並產出 PNG；顏色是模型推測，不應視為歷史事實。

```bash
curl -X POST -H "Authorization: Bearer <TOKEN>" -H "Accept: image/png" \
  -F 'operation=colorize' -F 'image=@black-and-white.png' -F 'backend=auto' \
  '<HUB_BASE_URL>/api.php?mode=image-tools&operation=colorize' \
  --output colorized-image.png
```

非同步 CUDA 或 CPU flow 使用 `mode=image-tools&operation=upscale_task`。Hub 在 submit 時固定 queue/backend；以既有 `task_status` 輪詢、`task_result` 取 artifact ID，透過 `artifact` 下載 `upscaled_image.png` 與 `upscale_report.json`。下載後比對 report 的 SHA-256、model、backend、尺寸與 image SHA-256；不將原始 Base64 寫入 task input、report 或 log。

請在實機 acceptance evidence 僅記錄 elapsed time、dimensions、selected backend、model alias、output SHA-256、sanitized health/VRAM facts 與版本資訊；evidence 不記錄 image、Token、Base64 或 private path。

## 取消（cancellation）、清理與 rollback

- 以既有 `task_cancel` 取消 queued/running `upscale_task`。確認 worker 釋放 CPU/GPU lease 並刪除 private workspace；取消後不可發布半成品 artifact。
- 每次同步 request 與 async workspace 都應 cleanup 暫存 source 與輸出，只保留已發布、受 retention policy 管理的 artifacts。
- 若新 snapshot 或 image 驗證失敗，停止服務，保留目前可用的 `ready.json` snapshot，將 mount 指回前一個已驗證版本後重啟並再次 preflight。不要手改 marker 或覆寫檔案。
- artifact 與 task metadata 受既有 retention / member scope 控制。到期後由既有 cleanup 流程移除；不要延長 retention 來保存測試來源或模型檔案。
