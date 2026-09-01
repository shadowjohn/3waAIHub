# PaliGemma 2 Multimodal VLM Service

`vlm-paligemma2` 是 3waAIHub 的多模態視覺 Pack。它目前是**受控的
真實 inference acceptance 階段**：Docker image 與 GPU smoke 已可驗證，但 Pack
尚未宣告成可路由服務，直到本機真實模型與單圖 CUDA acceptance 有紀錄為止。

## 目前契約

- Pack ID：`vlm-paligemma2`
- 預設 service key：`vlm-paligemma2-main`
- 預設 mode：`paligemma2`
- Runtime level：`L2-deps-import`
- Runtime ready：`false`
- GPU：必要；不提供 CPU fallback
- 最大上傳：50 MiB

因此 Marketplace 可以安裝、建置、設定 token 與執行明確的模型 provision，但 API
`/health` 仍會回報 `acceptance_pending`、manifest `runtime_ready=false`。不可因為
Docker image 建置成功或模型下載完成就將它宣告為 L5 或加入可路由的正式 Vision 能力。

第一個真實合約只承諾 `caption` / `general` 單圖推論。OCR、DocVQA、偵測、翻譯都需
對應 fine-tuned model 與獨立 acceptance，不能把 PT checkpoint 的 import 成功誤當成功能承諾。

## Windows + WSL2（GTX 1080）

Windows Control Plane 只經由 `windows-wsl2-linux-docker` target 執行。GTX
1080（Pascal，compute capability 6.1）會選取：

- Dockerfile：`service/Dockerfile.pascal-cu118`
- image：`3waaihub/vlm-paligemma2:0.1.0-pascal-cu118`
- CUDA：11.8
- PyTorch：2.6.0，僅從官方 `cu118` wheel index 安裝
- dtype：`float16`

模型、cache 與 service data 固定留在 WSL ext4 的 `/DATA`，不可放到
`/mnt/d/...`。Windows 本機的 `linux-docker` target 仍維持 unsupported；Docker
CLI 存在不代表可直接執行 Linux Pack。

一般 RTX profile 同樣釘選 PyTorch 2.6.0，但由 `cu126` wheel index 安裝；Pack
requirements 不得以寬鬆的 `torch>=...` 覆寫已選定的 CUDA profile。

## 安全設定檔

Runtime 只使用 `runtime-settings.conf`。它會由 Hub 以 SHA-256 驗證後寫入
WSL service 目錄並設為 0600；本 Pack 不使用、也不得新增 `.env`。

可從 [`runtime-settings.example.conf`](runtime-settings.example.conf) 取得無密碼
的範例。預設 `PALIGEMMA2_REAL_INFERENCE=0`，避免未驗收的模型被偷偷下載或
佔用 8 GiB VRAM。

### PaliGemma 2 3B 模型 Provision

固定來源為 `google/paligemma2-3b-pt-224`，revision
`96eeb174da13ca1a2b247e4d0867436296c36420`。它是 gated model；請先以 Hugging
Face 帳號接受 Gemma 授權，再於 Marketplace 的「設定」輸入具 read 權限的
`HF_TOKEN`，儲存後按「準備 PaliGemma 2 模型」。

Provision 工作只會在指定 runtime 內執行：Windows 為 WSL2 Linux Docker，Linux 為
native Linux Docker。模型下載到 `/DATA/models/paligemma2/snapshot`，建立
`provision-manifest.json`，逐一記錄每個檔案的大小與 SHA-256。推論 API 僅以
`local_files_only=True` 載入此快照；模型不存在、revision 不符、內容被竄改或碰到
symlink 時，會拒絕執行，不會線上補抓。

要執行真實請求，必須同時滿足：

1. `PALIGEMMA2_REAL_INFERENCE=1` 已在受控 runtime 設定。
2. request 明確帶 `real_inference=1`。
3. `task=caption` 或 `task=general`。
4. CUDA GPU 存在且 local snapshot 完整。

完成後可在容器內跑 `/app/acceptance.py --image /fixture/sample.png`。它必須輸出
`ok=true`、`mock=false`、非空文字、GPU 名稱與 peak VRAM；該紀錄才是把 Pack 升為
runtime-ready 的依據。固定 acceptance fixture 為 `demo/sample.png`（1280×720 RGB，
SHA-256：`53170e6afeba5c703e1e858c126a582e4494d137fb9592c0b1372c49f4e91f8c`）；不可用
1×1 placeholder 取代，否則 PaliGemma image processor 無法可靠判斷 channel 維度。

## 進入 L5 前的必要驗收

1. 以指定且可追溯的模型來源、revision 與 SHA-256 完成下載／授權策略。
2. Pascal CUDA 11.8 image build、`torch.cuda.is_available()` 與 GPU smoke 均通過。
3. 對固定圖片完成真實推論，驗證 JSON schema、artifact 與錯誤契約。
4. 量測 GTX 1080 8 GiB 的 VRAM、延遲與 OOM 邊界，確認和 Whisper／OCR 的駐留排程。
5. 全部通過後，才可將 manifest 的 `runtime_ready` 改為 `true` 並開放 Cluster
   manifest 宣告。

原則：模型適配 Hub 的生命週期、設定檔與 artifact 契約；不能由未驗收的模型
反過來放寬 Hub 的路由與 readiness 語意。
