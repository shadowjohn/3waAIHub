# Local Job Contract v0.1

Local Job 是「啟動、執行、產出結果、結束」的一次性工作。

## 呼叫方式

```bash
cd /DATA/3waAIHub

bin/aihub-run yolo_predict \
  --pack yolo \
  --run-id natureweb-yolo-001 \
  --caller natureweb \
  --workspace /DATA/jobs/yolo/001
```

YOLO 目前宣告三個本地 job：

```bash
bin/aihub-run yolo_predict --pack yolo --workspace /DATA/jobs/yolo/predict-001
bin/aihub-run yolo_train --pack yolo --workspace /DATA/jobs/yolo/train-001 --gpu 0
bin/aihub-run yolo_export_onnx --pack yolo --workspace /DATA/jobs/yolo/export-001
```

`yolo_predict` 已接真實 Ultralytics batch predict runner。呼叫端需準備：

```text
workspace/
├─ request.json
└─ input/
```

`request.json` 最小範例：

```json
{
  "images": ["input/sample.jpg"],
  "model": "yolo11n.pt",
  "conf": 0.25,
  "iou": 0.7
}
```

輸出固定落在：

```text
workspace/runs/predict/output/predictions.json
workspace/runs/predict/output/labels/
workspace/progress.ndjson
workspace/result.json
```

`yolo_train` 已接真實 Ultralytics training runner。呼叫端需準備：

```text
workspace/
├─ data.yaml
├─ train_config.json
└─ datasets/
```

`train_config.json` 最小範例：

```json
{
  "model": "yolo11n.pt",
  "epochs": 50,
  "imgsz": 640,
  "batch": 8,
  "workers": 2
}
```

輸出固定落在：

```text
workspace/runs/train/output/results.csv
workspace/runs/train/output/weights/best.pt
workspace/runs/detect/val/predictions.json
workspace/progress.ndjson
workspace/result.json
```

NatureWeb 這類既有專案可把 job root 指到自己的 project root，只要 workspace 在 root 底下即可：

```bash
AIHUB_LOCAL_JOB_ROOT=/path/to/train_project \
bin/aihub-run yolo_train \
  --pack yolo \
  --run-id natureweb-train-47 \
  --caller natureweb \
  --workspace /path/to/train_project/47 \
  --gpu 0
```

預設 Docker image 是 `3waaihub-yolo-main:0.1.0`。若部署 image tag 不同，設定 `AIHUB_YOLO_IMAGE=...`。

模型來源可用 workspace-relative path 或 `/DATA/models/yolo` 下的模型檔名。`AIHUB_YOLO_MODELS_DIR` 可覆寫模型目錄，container 內固定掛到 `/models/yolo`。呼叫端不可傳 host 絕對路徑。

已準備的訓練底模型：

```text
/DATA/models/yolo/yolo11n.pt
/DATA/models/yolo/yolo26n.pt
/DATA/models/yolo/yolo26s.pt
/DATA/models/yolo/yolo26m.pt
```

`yolo_export_onnx` 已接真實 Ultralytics ONNX export runner。呼叫端需準備：

```text
workspace/
├─ request.json
└─ input/best.pt
```

`request.json` 最小範例：

```json
{
  "model": "input/best.pt",
  "format": "onnx",
  "imgsz": 640
}
```

輸出固定落在：

```text
workspace/runs/export/output/model.onnx
workspace/progress.ndjson
workspace/result.json
```

## Workspace

```text
workspace/
├─ input/
├─ output/
├─ logs/
├─ runtime/
│  ├─ run.json
│  ├─ resource.ndjson
│  └─ events.ndjson
├─ request.json
├─ status.json
├─ progress.ndjson
└─ result.json
```

`request.json` 是呼叫端輸入。`result.json` 是機器可讀結果。`progress.ndjson` 是業務進度，例如 epoch、phase、mAP。`runtime/resource.ndjson` 是 Hub 外部觀測資源，例如 RAM / GPU / IO。

## 安全限制

- Workspace 必須在 `AIHUB_LOCAL_JOB_ROOT` 之下，預設 `/DATA/jobs`。
- 不接受任意 host path mount。
- 不接受 client 在 `request.json` 指定 Docker socket、service URL 或內部 port。
- Pack 只能執行 manifest 宣告的 `local_jobs[*].entrypoint`。

## Exit Code

- `0`：成功，`status.json.status=succeeded`
- 非 `0`：失敗，`status.json.status=failed`

即使 job 失敗，`runtime_runs` 仍會留下紀錄，方便追查。
