# BreezyVoice 台灣國語角色語音 Pack 設計

**日期：** 2026-09-01
**狀態：** approved for implementation planning

## 目標

新增 `tts-breezyvoice` Pack，讓 3waAIHub 在已授權的參考音檔基礎上，以
BreezyVoice 產生台灣國語／台味中文語音。它支援台灣國語讀音與注音校正，
並使用既有的 Voice Profile、Managed Voice Preset、非同步 task、artifact 與
Cluster Router 契約。

本設計不把 `seed` 或文字描述當成聲音身分。角色身分來自受管理、已授權的
Voice Profile；`seed` 僅為同一角色、同一 engine revision 下的候選產聲重現輔助。

## 已確認的模型邊界

官方 BreezyVoice 的單筆推論需要：要念的內容、speaker prompt audio，並強烈
建議提供該音檔的逐字稿。它可自動或手動處理注音，且公開 README 指定 Python
3.10 與 GPU `onnxruntime-gpu` 安裝路徑。因此第一版是「台灣國語語音仿聲」，
不是純文字角色設計，也不是台語／Hokkien TTS。

## 相容策略：保留既有角色，新增私有 Engine Binding

既有 `voice_profiles`、`voice_presets`、scene anchor、owner、consent、
reference-audio hash、prompt transcript 與 Router opaque handle 全部保留。

1. 未設定 engine binding 的既有 preset 一律維持 `tts-voxcpm2`，不能因安裝新
   Pack 而改變聲音或路由。
2. 管理者可在控制平面將一個已確認的 preset 明確綁定到 `tts-breezyvoice`。
   這會建立 audit log、遞增 `preset_revision`，且引用同一個受管理 Voice Profile；
   不重上傳、不公開其檔案路徑。
3. 每個 Breezy binding 都要通過相容性檢查：private profile、明確 consent、已確認
   transcript、允許的音訊格式與語言 `zh-TW`／未指定。未通過時預設保持 VoxCPM2，
   不靜默 fallback 到另一個聲音。
4. 建議操作順序是先以新 preset ID（如 `mechanic_dad_breezy`）進行實機試聽；確認
   聲線後，才由 owner 將原角色 preset 切換。如此既有 MyAI 角色可繼續使用原 ID，
   但沒有未經確認的聲線替換。

`voice_preset` 仍是對外的角色 ID，並不暴露 Pack、模型、Profile ID、WAV 路徑、
reference hash 或 engine strategy。

## 公開 API 與 seed 規則

對外維持既有 `mode=voice_generate`、`operation=preset_synthesize`：

```json
{
  "voice_preset": "mechanic_dad_breezy",
  "purpose": "service_reply",
  "scene": "default",
  "candidate_count": 1,
  "text": "少年欸，這粒螺絲先鎖緊，再慢慢調。",
  "seed": 12345
}
```

- `voice_preset` 是聲音身分；其 private binding 解析出 `tts-breezyvoice` 路由。
- `text` 是唯一要念出的文字。第一版交給 BreezyVoice 自動處理台灣國語讀音；
  手動注音覆寫留待有結構化、可驗證的欄位後再加入，不能把自由 prompt 拼入 spoken
  text 或 shell command。
- `seed` 是可選整數。只有 upstream runtime 實證能套用時才送給推論；否則任務回傳
  server-derived seed 與 `reproducibility=best_effort`，不宣稱不存在的 deterministic
  控制。
- 同一 preset revision、Breezy model revision、reference-audio SHA-256 與 runtime
  image 下，seed 用於候選重試／比較；跨 revision、CUDA、ONNX Runtime 或模型版本
  不承諾 byte-identical 音檔。
- `generic_synthesize` 保持 VoxCPM2 專用。本 Pack 不以文字 role note 假裝能穩定設計
  一個全新角色聲。

Router 必須在安全解析 `voice_preset` 後選擇其 private engine binding。客戶端絕不傳
`pack_id`、模型名稱、clone mode、Profile ID、宿主路徑、container 控制或 reference
audio。回應仍是標準非同步 task/status/result/artifact/ACK 連結。

## Profile、同意與資料保護

只有現有 `profile_prepare` → ASR/transcript review → `profile_confirm` 完成的 Profile
可被綁定。Breezy Pack 掛載單一、受驗證的參考檔為 read-only；絕不接受呼叫端路徑。
Profile 及其 reference WAV 保持 owner-private，Cluster manifest、公開 preset catalog、
task logs 與 artifact metadata 不得暴露原始檔案路徑、逐字稿全文、聲音身分或 consent
內容。

真人仿聲需沿用既有 consent_type、usage_scope 與 audit log。原創角色可使用已取得
製作授權的演員／自有錄音，但仍以 Voice Profile 表示，不能用 seed 產生或偽裝為真人。

## Windows 1080 / WSL Runtime

此 Windows Station 的唯一實際執行 target 是 `windows-wsl2-linux-docker`；Windows
control plane 仍不放行 direct `linux-docker`。Pack 只在固定 `Ubuntu-24.04`、
`/DATA/3waAIHub-runtime` 與 WSL ext4 workspace 執行。

第一版建立 `pascal-cu118` image/profile，採 Python 3.10、實際相容的 CUDA 11.8 /
cuDNN / ONNX Runtime 組合，並鎖定上游 Git revision、Python lockfile 與模型檔 SHA-256。
權重與 Docker layer 不進 Git；下載與模型 cache 必須記錄 SHA-256、來源與 revision。

GTX 1080 的 8 GB 不能與目前接近滿載的 PaliGemma resident service 並跑。Breezy job
必須取得既有 GPU lease、concurrency=1，並在 lease 取得前保持 queued；不能因為新 Pack
而強制停止別的服務。第一版是 on-demand/isolated 非同步 WAV 產生，非 resident HTTP
streaming；Streaming 另待首包延遲、取消、背壓與 artifact 完整性實測後規劃。

## Pack 與交付契約

`tts-breezyvoice` 提供單一 GPU job `synthesize`：

1. 由 Hub 建立受控 workspace、解析 preset binding 與安全的 profile mount。
2. WSL runner 取得 GPU lease 後執行 Docker/ONNX 推論。
3. 產出 `generated_audio.wav`、`synthesis_metadata.json`；若 candidate_count 大於一，
   依既有候選命名輸出 `candidate-02.wav`、`candidate-03.wav`。
4. Hub 以 ffprobe 驗證可播放的 WAV，完成後才計算與寫入每個 artifact 的 size、SHA-256、
   MIME type 與 task result；不允許文字讀寫或 relay 導致 metadata 與 bytes 不一致。

完成結果沿用既有 candidate 結構，並在 private task snapshot 記錄 engine、model revision、
runtime image digest、reference SHA-256、preset revision、seed policy 與 pronunciation
normalization revision。公開結果僅有 task/candidate/artifact 的 opaque references、seed、
preset revision 及必要的 runtime status。

## 安裝、資料庫與 UI

Marketplace 可安裝、build、start、stop、restart、health-check `tts-breezyvoice`，但不會
自動下載權重或自動切換任何角色。WSL readiness 仍只驗前置條件；真正可用性只由一次實際
Breezy inference acceptance 宣告。

資料庫採最小 additive migration：以 preset 對 engine 的 private binding 儲存 selected
pack/revision 與 compatibility state；保留沒有 binding 的 `tts-voxcpm2` 預設語意。管理 UI
顯示「目前引擎、相容性、試聽／切換、revision」，而一般 API/Cluster client 只看既有
角色 catalog。

## 驗收與測試

Control-plane tests：

- 舊 preset 無 binding 仍解析到 VoxCPM2，既有 API payload/response 不變。
- Breezy binding 僅接受 owner 已確認、具同意與安全音檔的 Profile。
- preset engine 變更會 audit、revision +1；無效 binding 不得 fallback。
- Router 對同一 preset 維持 engine/station pinning，且不洩漏 private 欄位。
- seed、candidate、artifact metadata 與不支援 Windows direct-linux target 的契約維持。

WSL real acceptance：

- 以已授權且不進 Git 的 reference WAV 產出一段台灣國語 WAV，`mock=false`。
- `ffprobe` 驗證 duration、sample rate、channels，並驗證 artifact bytes/size/SHA-256。
- `nvidia-smi` 證明 GTX 1080 實際參與推論；GPU lease 無法取得時工作維持 queued，不誤標
  running 或借用 PaliGemma 的 VRAM。
- 經 Cluster Router 提交、輪詢、下載與 ACK 一次；Router artifact 的 SHA-256 必須與 child
  byte-for-byte 一致。

## 非目標

- 不支援未經授權的第三方真人仿聲。
- 不宣稱為台語／Hokkien 合成。
- 不讓請求端選模型、容器、CUDA、路徑或 clone mode。
- 不在第一版加入 resident streaming、任意文字 prompt 造聲、或新的人物資料庫。
