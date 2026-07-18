# 3waAIHub Service Platform Vision v0.1

AI、GIS 與通用容器服務的統一發佈平台。

## 1. 平台定位

3waAIHub 不只管理 AI 模型，也提供容器化服務與本機工作的統一安裝、執行、授權、觀測及 API 發佈入口。

適合服務：

- OCR、翻譯、Vision、TTS
- YOLO 訓練與推論
- 地址清洗
- 路徑規劃
- 座標轉換
- 地籍與圖資加工
- 文件解析
- 3D 重建

## 2. 目前狀態

| 能力 | 狀態 |
| --- | --- |
| Docker Service lifecycle | 已完成 |
| API Gateway / Token / IP whitelist | 已完成 |
| Local Job Contract v0.1 | 已完成 |
| aihub-run | 已完成 |
| Run history | 已完成 |
| Resource samples | 已完成 |
| YOLO smoke wrappers | 已完成 |
| YOLO 真實 predict/train/export | 已完成 |
| YOLO Model Registry / CPU Serving | Preview／薄版 |
| External DB / Volume binding | 規劃中 |
| Generic Service Publish Contract | 規劃中 |

## 3. 兩種 Runtime

Service Runtime：長期運行，提供即時 API。例如路徑規劃、地址正規化、OCR 推論。

Job Runtime：單次或批次執行，產生結果與 Artifact。例如 YOLO 訓練、批次洗地址、3D 重建。

## 4. 執行生命週期

```text
Pack
  -> Runtime Contract
  -> aihub-run / Service Worker
  -> Workspace
  -> status.json
  -> result.json
  -> Resource Samples
  -> Run History
```

## 5. 安全邊界

- Web process 不直接取得 Docker root 權限。
- Host 操作由 worker 或 CLI runner 執行。
- Workspace 不可任意跳脫。
- 不向 API Client 暴露 host path。
- Secret 不寫入 Pack manifest。
- 外部資料庫及 Volume 未來由 Resource Profile 注入。

## 6. Roadmap

| Phase | 名稱 | 狀態 |
| --- | --- | --- |
| Runtime-1A | Local Job Runner | 完成 |
| Runtime-1B | Runtime UI 與平台定位 | 完成 |
| Runtime-1C | YOLO 真實 Job Adapter | 完成 |
| YoloServe-1A | YOLO Model Registry / CPU Serving | Preview／薄版 |
| Runtime-2A | Generic Service Contract | 規劃中 |
| Runtime-2B | Volume / Database Resource Profile | 規劃中 |
| Runtime-2C | GIS Vertical Slice | 規劃中 |
