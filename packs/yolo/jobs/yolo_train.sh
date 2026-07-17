#!/usr/bin/env bash
set -euo pipefail

mkdir -p "$AIHUB_WORKSPACE/output" "$AIHUB_WORKSPACE/logs" "$AIHUB_WORKSPACE/runs/train/output/weights" "$AIHUB_WORKSPACE/runs/detect/val"

progress() {
  printf '{"ts":"%s","status":"%s","message":"%s"}\n' "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "$1" "$2" >> "$AIHUB_PROGRESS_NDJSON"
}

write_status() {
  cat > "$AIHUB_STATUS_JSON" <<JSON
{"status":"$1","pack_id":"yolo","job_key":"yolo_train","finished_at":"$(date -u +"%Y-%m-%dT%H:%M:%SZ")","exit_code":$2}
JSON
}

fail_job() {
  local code="$1"
  local error="$2"
  local message="$3"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":false,"mock":false,"pack_id":"yolo","job_key":"yolo_train","runtime_contract":"0.1","error":"$error","message":"$message","artifacts":[]}
JSON
  write_status failed "$code"
  progress failed "$message"
  exit "$code"
}

if [[ ! -f "$AIHUB_WORKSPACE/data.yaml" ]]; then
  fail_job 11 missing_data_yaml "workspace/data.yaml is required."
fi
if [[ ! -f "$AIHUB_WORKSPACE/train_config.json" ]]; then
  fail_job 12 missing_train_config "workspace/train_config.json is required."
fi
if [[ ! -d "$AIHUB_WORKSPACE/datasets" ]]; then
  fail_job 13 missing_datasets_dir "workspace/datasets directory is required."
fi

progress running "YOLO train starting"

if [[ "${AIHUB_YOLO_TRAIN_DRY_RUN:-0}" == "1" ]]; then
  printf 'epoch,train/box_loss,metrics/mAP50(B)\n0,0,0\n' > "$AIHUB_WORKSPACE/runs/train/output/results.csv"
  printf 'dry-run best weights\n' > "$AIHUB_WORKSPACE/runs/train/output/weights/best.pt"
  printf '{"ok":true,"dry_run":true,"predictions":[]}\n' > "$AIHUB_WORKSPACE/runs/detect/val/predictions.json"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":true,"mock":false,"dry_run":true,"pack_id":"yolo","job_key":"yolo_train","runtime_contract":"0.1","artifacts":[{"path":"runs/train/output/results.csv","type":"metrics_csv"},{"path":"runs/train/output/weights/best.pt","type":"model_weights"},{"path":"runs/detect/val/predictions.json","type":"validation_predictions"}]}
JSON
  write_status success 0
  progress success "YOLO train dry-run completed"
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  fail_job 14 docker_unavailable "docker command is required for YOLO training."
fi

image="${AIHUB_YOLO_IMAGE:-3waaihub-yolo-main:0.1.0}"
if ! docker image inspect "$image" >/dev/null 2>&1; then
  fail_job 15 yolo_image_missing "YOLO Docker image is missing: $image. Build yolo-main first or set AIHUB_YOLO_IMAGE."
fi

models_dir="${AIHUB_YOLO_MODELS_DIR:-/DATA/models/yolo}"
mkdir -p "$AIHUB_WORKSPACE/.cache/ultralytics" "$AIHUB_WORKSPACE/.cache/home" "$AIHUB_WORKSPACE/.cache/matplotlib"
docker_args=(--rm -i --shm-size "${AIHUB_YOLO_SHM_SIZE:-8g}" --user "$(id -u):$(id -g)" -v "$AIHUB_WORKSPACE:/workspace" -w /workspace
  -e HOME=/workspace/.cache/home
  -e YOLO_CONFIG_DIR=/workspace/.cache/ultralytics
  -e ULTRALYTICS_SETTINGS_DIR=/workspace/.cache/ultralytics
  -e MPLCONFIGDIR=/workspace/.cache/matplotlib
  -e AIHUB_GPU_INDEXES="${AIHUB_GPU_INDEXES:-}")
if [[ -d "$models_dir" ]]; then
  docker_args+=(-v "$models_dir:/models/yolo:ro")
fi
if [[ -n "${AIHUB_GPU_INDEXES:-}" ]]; then
  docker_args+=(--gpus "device=${AIHUB_GPU_INDEXES}")
fi
container_name="aihub-${AIHUB_RUN_ID:-${AIHUB_JOB_KEY:-yolo}-$$}"
trap 'docker rm -f "$container_name" >/dev/null 2>&1 || true' TERM INT EXIT
docker_args+=(--name "$container_name")

if ! docker run "${docker_args[@]}" "$image" python3 - <<'PY'
import csv
import json
import os
import sys
from pathlib import Path

workspace = Path("/workspace")
progress_path = workspace / "progress.ndjson"
result_path = workspace / "result.json"
status_path = workspace / "status.json"
train_dir = workspace / "runs/train/output"
pred_path = workspace / "runs/detect/val/predictions.json"

def emit(status, message):
    from datetime import datetime, timezone
    progress_path.open("a", encoding="utf-8").write(json.dumps({
        "ts": datetime.now(timezone.utc).isoformat(),
        "status": status,
        "message": message,
    }, ensure_ascii=False, separators=(",", ":")) + "\n")

def fail(code, error, message):
    result_path.write_text(json.dumps({
        "ok": False,
        "mock": False,
        "pack_id": "yolo",
        "job_key": "yolo_train",
        "runtime_contract": "0.1",
        "error": error,
        "message": message,
        "artifacts": [],
    }, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    status_path.write_text(json.dumps({"status": "failed", "pack_id": "yolo", "job_key": "yolo_train", "exit_code": code}, separators=(",", ":")) + "\n", encoding="utf-8")
    emit("failed", message)
    sys.exit(code)

try:
    from ultralytics import YOLO
except Exception as exc:
    fail(21, "ultralytics_unavailable", f"Ultralytics import failed: {exc}")

config = json.loads((workspace / "train_config.json").read_text(encoding="utf-8") or "{}")
model_name = str(config.get("model") or "yolo11n.pt")
if model_name.startswith("/") or ".." in Path(model_name).parts:
    fail(22, "invalid_model", "model must be a YOLO model name or a workspace-relative path.")
workspace_model = workspace / model_name
models_model = Path("/models/yolo") / model_name
if workspace_model.is_file():
    model_source = str(workspace_model)
elif models_model.is_file():
    model_source = str(models_model)
else:
    model_source = model_name

device = str(config.get("device") or ("0" if os.environ.get("AIHUB_GPU_INDEXES") else "cpu"))
kwargs = {
    "data": str(workspace / "data.yaml"),
    "project": str(workspace / "runs/train"),
    "name": "output",
    "exist_ok": True,
    "epochs": int(config.get("epochs", 1)),
    "imgsz": int(config.get("imgsz", 640)),
    "batch": int(config.get("batch", 8)),
    "workers": int(config.get("workers", 2)),
    "device": device,
}
for key in ("patience", "seed"):
    if key in config:
        kwargs[key] = int(config[key])

emit("running", f"Ultralytics train start model={model_name}")
try:
    model = YOLO(model_source)
    model.train(**kwargs)
except Exception as exc:
    fail(23, "train_failed", str(exc))

best = train_dir / "weights/best.pt"
results_csv = train_dir / "results.csv"
metrics = {}
if results_csv.is_file():
    with results_csv.open("r", encoding="utf-8", newline="") as fh:
        rows = list(csv.DictReader(fh))
    metrics = rows[-1] if rows else {}

if not best.is_file():
    fail(24, "missing_best_weights", "Ultralytics completed but weights/best.pt was not produced.")

pred_path.parent.mkdir(parents=True, exist_ok=True)
val_images = workspace / "datasets/my_dataset/images/val"
predictions = []
if val_images.is_dir():
    try:
        best_model = YOLO(str(best))
        for result in best_model.predict(source=str(val_images), imgsz=kwargs["imgsz"], device=device, save=False, verbose=False, stream=True):
            image_id = Path(result.path).stem
            boxes = getattr(result, "boxes", None)
            if boxes is None:
                continue
            for box in boxes:
                predictions.append({
                    "image_id": image_id,
                    "category_id": int(box.cls[0]),
                    "bbox": [round(float(v), 3) for v in box.xyxy[0].tolist()],
                    "score": round(float(box.conf[0]), 5),
                })
    except Exception as exc:
        fail(25, "validation_predict_failed", str(exc))
pred_path.write_text(json.dumps(predictions, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")

result_path.write_text(json.dumps({
    "ok": True,
    "mock": False,
    "pack_id": "yolo",
    "job_key": "yolo_train",
    "runtime_contract": "0.1",
    "model": model_name,
    "device": device,
    "metrics": metrics,
    "artifacts": [
        {"path": "runs/train/output/results.csv", "type": "metrics_csv"},
        {"path": "runs/train/output/weights/best.pt", "type": "model_weights"},
        {"path": "runs/detect/val/predictions.json", "type": "validation_predictions"},
    ],
}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
status_path.write_text(json.dumps({"status": "success", "pack_id": "yolo", "job_key": "yolo_train", "exit_code": 0}, separators=(",", ":")) + "\n", encoding="utf-8")
emit("success", "Ultralytics train completed")
PY
then
  if [[ -s "$AIHUB_RESULT_JSON" ]] && grep -q '"ok":false' "$AIHUB_RESULT_JSON"; then
    exit 20
  fi
  fail_job 20 yolo_train_failed "YOLO training failed. See logs/stderr.log and result.json."
fi

exit 0
