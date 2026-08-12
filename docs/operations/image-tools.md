# 影像工具操作手冊

`image-tools` 是可擴充的本機影像工具 Pack；目前的 `upscale` 與 `upscale_task` operation 使用離線、可驗證的 Real-ESRGAN snapshot，為 `L3-offline-assets` 且 `runtime_ready=true`。本次驗收的版本、checksum、輸出 metadata 與 cleanup 結論見 [image-tools-acceptance.md](image-tools-acceptance.md)。

## 離線模型 staging

1. 在受控網路環境執行 provisioner，將官方 allowlist 權重先下載到 staging；不要在 API request 或 worker 中下載。
2. provisioner 會計算每個檔案的 SHA-256、大小與來源，並原子建立 `/DATA/models/image-tools/realesrgan/ready.json`。
3. 將整個 snapshot 以唯讀方式掛入 container。啟動前確認 marker 的 repository commit 是 `a4abfb2979a7bbff3f69f58f58ae324608821e27`，所有 manifest SHA-256 都與實檔相同，且沒有 symlink 或未列檔案。

模型與輸入圖片不應提交到 Git、寫入 README，或放進 log。任何 staging 失敗都保留舊 snapshot，清理 partial download 後再重試。

## 安裝與 preflight

預設是 GPU-first 安裝：保留 `IMAGE_TOOLS_USE_GPU=1`、Docker NVIDIA runtime 與 CUDA driver，並確認 Docker 可看見 GPU。Hub preflight 會檢查可用 VRAM 加上 `AIHUB_GPU_VRAM_SAFETY_MARGIN_MB`；`backend=auto` 才會在不符合時改選 CPU。

明確 CPU 安裝可設 `IMAGE_TOOLS_USE_GPU=0`，重新產生 Compose 後不應含 `gpus: all`。client 可送 `backend=cpu`；明確 `backend=cuda` 在 GPU／VRAM 不可用時必須回 `backend_unavailable`，不允許無聲 fallback。

完成 staging 後，以非 secret 的管理 token 跑 health、Docker/CUDA preflight 與同步 smoke。確認 `/health` 仍正確表示 runtime 狀態；不要把 Docker build 或 mock test 當作真實模型 acceptance。

## API 操作

同步 CUDA 或 CPU smoke 使用 `mode=image-tools&operation=upscale`，將 `operation=upscale` 同時放 query 與 multipart form。上傳 `image` 或 `base64_string`，但不可兩者同時傳。成功只接受 `image/png`，並記錄 `X-3waAIHub-Model`、`X-3waAIHub-Backend`、`X-3waAIHub-Elapsed-Ms`、`X-3waAIHub-Width`、`X-3waAIHub-Height`。

非同步 CUDA 或 CPU flow 使用 `mode=image-tools&operation=upscale_task`。Hub 在 submit 時固定 queue/backend；以既有 `task_status` 輪詢、`task_result` 取 artifact ID，透過 `artifact` 下載 `upscaled_image.png` 與 `upscale_report.json`。下載後比對 report 的 SHA-256、model、backend、尺寸與 image SHA-256；不將原始 Base64 寫入 task input、report 或 log。

請在實機 acceptance log 僅記錄 elapsed time、dimensions、selected backend、model alias、output SHA-256 與版本資訊；不記錄 Token、來源圖片、Base64、路徑或模型二進位。

## 取消（cancellation）、清理與 rollback

- 以既有 `task_cancel` 取消 queued/running `upscale_task`。確認 worker 釋放 CPU/GPU lease 並刪除 private workspace；取消後不可發布半成品 artifact。
- 每次同步 request 與 async workspace 都應 cleanup 暫存 source 與輸出，只保留已發布、受 retention policy 管理的 artifacts。
- 若新 snapshot 或 image 驗證失敗，停止服務，保留目前可用的 `ready.json` snapshot，將 mount 指回前一個已驗證版本後重啟並再次 preflight。不要手改 marker 或覆寫檔案。
- artifact 與 task metadata 受既有 retention / member scope 控制。到期後由既有 cleanup 流程移除；不要延長 retention 來保存測試來源或模型檔案。
