# FLUX.2 klein 4B HubPack

本機文字生圖。輸入 prompt、尺寸與選填 seed，回傳一張 PNG。模型為 Black Forest Labs 的 Apache 2.0 權重，使用 BF16、四步生成及 CPU 權重卸載。推論需 CUDA GPU。映像固定使用 PyTorch 2.8.0 + CUDA 12.9，適用 Linux Docker 與 Windows 的 WSL2 Linux Docker。

## 1. 準備模型

在 Hub 的模型根目錄下準備 `flux-klein-4b`，模型本體約 16 GB。腳本從公開來源下載固定 revision，核對四個權重檔案的 SHA-256，再寫入可用標記。WSL Runtime 使用該設定檔 `models_root` 下的同名目錄，模型資料夾以唯讀方式掛進服務。

公司 Windows 主機範例，從 `D:\DATA\3waAIHub` 執行。此步需要 Python 3.12 與 uv。

```powershell
uv run --python 3.12 --with huggingface-hub==0.36.0 python packs/image-flux-klein/service/provision.py D:/DATA/models/flux-klein-4b
```

Linux 原生 Hub 或 WSL Runtime 可將最後一個參數改為實際的 Linux 模型路徑，例如 `/DATA/models/flux-klein-4b`。模型會由準備腳本取得，執行中的生成服務只讀本機檔案。

## 2. 加入 Hub

依現有 Hub 方式安裝 Marketplace 的「FLUX.2 klein 4B 文字生圖」。Windows 使用已設定的 WSL Runtime，將新版 Pack 原始碼同步到該 Runtime 後建置並啟動。服務端口只綁定本機。Hub API 沿用既有 Bearer token 和 mode 權限，mode 為 `image_generate_flux`。

獨立驗證可以直接跑 Compose。Docker Desktop 的虛擬磁碟可能在 C 槽，即使模型在 D 槽，建置與解包的暫存仍會使用 Docker 虛擬磁碟所在的磁碟空間。

Windows Docker Desktop 範例如下。

```powershell
$env:AIHUB_MODELS_DIR = 'D:/DATA/models'
docker compose -f packs/image-flux-klein/docker-compose.yml up -d --build
curl.exe -sS http://127.0.0.1:18123/health
```

`/health` 檢查模型快照及 CUDA 12.9 的 BF16 運算。模型在第一筆生圖載入，第一次呼叫會較慢。Hub 的 Pack 逾時設定為 900 秒。每個服務實例同時處理一筆，已有生圖進行時回傳 `429 busy`。

## 3. 驗證生圖

透過 Hub 對 `api.php?mode=image_generate_flux` 送出 JSON POST。獨立 Compose 測試可向 `http://127.0.0.1:18123/generate` 送出同樣的 JSON。

```json
{
  "prompt": "A miniature yellow chrysanthemum in a blue ceramic vase, watercolor illustration",
  "width": 512,
  "height": 512,
  "seed": 20261125
}
```

- 尺寸預設 768 × 768。每邊支援 256 至 1024、須為 16 的倍數。
- 回應 `image/png` 包含模型、裝置、耗時與尺寸標頭。可用固定 seed 重複測試。
- API 行為測試位於 `service/tests/test_service.py`。權重、虛擬環境及驗收圖片放在本機模型目錄或 Hub 的 `data/cache`。

官方參考資料

- [FLUX.2 klein 4B 模型卡與授權](https://huggingface.co/black-forest-labs/FLUX.2-klein-4B)
- [Diffusers FLUX.2 Pipeline](https://huggingface.co/docs/diffusers/en/api/pipelines/flux2)
- [PyTorch CUDA 12.9 套件](https://pytorch.org/get-started/previous-versions/)
