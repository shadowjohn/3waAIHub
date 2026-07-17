#!/usr/bin/env bash
set -euo pipefail

mkdir -p "$AIHUB_WORKSPACE/output" "$AIHUB_WORKSPACE/logs" "$AIHUB_WORKSPACE/runs/predict/output/labels"

progress() {
  printf '{"ts":"%s","status":"%s","message":"%s"}\n' "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "$1" "$2" >> "$AIHUB_PROGRESS_NDJSON"
}

write_status() {
  cat > "$AIHUB_STATUS_JSON" <<JSON
{"status":"$1","pack_id":"yolo","job_key":"yolo_predict","finished_at":"$(date -u +"%Y-%m-%dT%H:%M:%SZ")","exit_code":$2}
JSON
}

fail_job() {
  local code="$1"
  local error="$2"
  local message="$3"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":false,"mock":false,"pack_id":"yolo","job_key":"yolo_predict","runtime_contract":"0.1","error":"$error","message":"$message","artifacts":[]}
JSON
  write_status failed "$code"
  progress failed "$message"
  exit "$code"
}

if [[ ! -f "$AIHUB_REQUEST_JSON" ]]; then
  fail_job 11 missing_request_json "workspace/request.json is required."
fi

validate_input() {
  python3 - "$AIHUB_WORKSPACE" "$AIHUB_REQUEST_JSON" <<'PY'
import json
import sys
from pathlib import Path

workspace = Path(sys.argv[1]).resolve()
request = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8") or "{}")
images = request.get("images") or request.get("image")
if isinstance(images, str):
    images = [images]
if not isinstance(images, list) or not images:
    print("missing_images")
    sys.exit(2)
for item in images:
    rel = str(item)
    path = (workspace / rel).resolve()
    if path != workspace and workspace not in path.parents:
        print("input_image_escape")
        sys.exit(3)
    if not path.is_file():
        print("input_image_missing")
        sys.exit(4)
print("ok")
PY
}

validation="$(validate_input || true)"
case "$validation" in
  ok) ;;
  missing_images) fail_job 12 missing_images "request.json must contain image or images." ;;
  input_image_escape) fail_job 13 input_image_escape "input image must stay inside workspace." ;;
  input_image_missing) fail_job 14 input_image_missing "input image does not exist." ;;
  *) fail_job 15 invalid_request "invalid request.json." ;;
esac

progress running "YOLO predict starting"

if [[ "${AIHUB_YOLO_PREDICT_DRY_RUN:-0}" == "1" ]]; then
  printf '{"ok":true,"dry_run":true,"predictions":[]}\n' > "$AIHUB_WORKSPACE/runs/predict/output/predictions.json"
  printf '0 0.5 0.5 1.0 1.0 0.99\n' > "$AIHUB_WORKSPACE/runs/predict/output/labels/sample.txt"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":true,"mock":false,"dry_run":true,"pack_id":"yolo","job_key":"yolo_predict","runtime_contract":"0.1","artifacts":[{"path":"runs/predict/output/predictions.json","type":"predictions_json"},{"path":"runs/predict/output/labels","type":"labels_dir"}]}
JSON
  write_status success 0
  progress success "YOLO predict dry-run completed"
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  fail_job 16 docker_unavailable "docker command is required for YOLO predict."
fi

image="${AIHUB_YOLO_IMAGE:-3waaihub-yolo-main:0.1.0}"
if ! docker image inspect "$image" >/dev/null 2>&1; then
  fail_job 17 yolo_image_missing "YOLO Docker image is missing: $image. Build yolo-main first or set AIHUB_YOLO_IMAGE."
fi

models_dir="${AIHUB_YOLO_MODELS_DIR:-/DATA/models/yolo}"
mkdir -p "$AIHUB_WORKSPACE/.cache/ultralytics" "$AIHUB_WORKSPACE/.cache/home"
docker_args=(--rm -i --user "$(id -u):$(id -g)" -v "$AIHUB_WORKSPACE:/workspace" -w /workspace
  -e HOME=/workspace/.cache/home
  -e YOLO_CONFIG_DIR=/workspace/.cache/ultralytics
  -e ULTRALYTICS_SETTINGS_DIR=/workspace/.cache/ultralytics
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
import json
import os
import sys
from pathlib import Path

workspace = Path("/workspace")
request = json.loads((workspace / "request.json").read_text(encoding="utf-8") or "{}")
output = workspace / "runs/predict/output"
labels_dir = output / "labels"
pred_path = output / "predictions.json"
progress_path = workspace / "progress.ndjson"
result_path = workspace / "result.json"
status_path = workspace / "status.json"

def emit(status, message):
    from datetime import datetime, timezone
    progress_path.open("a", encoding="utf-8").write(json.dumps({"ts": datetime.now(timezone.utc).isoformat(), "status": status, "message": message}, ensure_ascii=False, separators=(",", ":")) + "\n")

def fail(code, error, message):
    result_path.write_text(json.dumps({"ok": False, "mock": False, "pack_id": "yolo", "job_key": "yolo_predict", "runtime_contract": "0.1", "error": error, "message": message, "artifacts": []}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    status_path.write_text(json.dumps({"status": "failed", "pack_id": "yolo", "job_key": "yolo_predict", "exit_code": code}, separators=(",", ":")) + "\n", encoding="utf-8")
    emit("failed", message)
    sys.exit(code)

try:
    from ultralytics import YOLO
except Exception as exc:
    fail(21, "ultralytics_unavailable", f"Ultralytics import failed: {exc}")

images = request.get("images") or request.get("image")
if isinstance(images, str):
    images = [images]
model_name = str(request.get("model") or "yolo11n.pt")
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
device = str(request.get("device") or ("0" if os.environ.get("AIHUB_GPU_INDEXES") else "cpu"))
conf = float(request.get("conf", 0.25))
iou = float(request.get("iou", 0.7))

output.mkdir(parents=True, exist_ok=True)
labels_dir.mkdir(parents=True, exist_ok=True)
try:
    model = YOLO(model_source)
    results = model.predict(source=[str(workspace / str(p)) for p in images], conf=conf, iou=iou, device=device, verbose=False)
except Exception as exc:
    fail(23, "predict_failed", str(exc))

predictions = []
for index, result in enumerate(results):
    names = getattr(result, "names", {}) or {}
    image_name = Path(str(images[index] if index < len(images) else index)).name
    label_lines = []
    boxes = getattr(result, "boxes", None)
    if boxes is not None:
        for box in boxes:
            class_id = int(box.cls.item()) if hasattr(box.cls, "item") else int(box.cls)
            confidence = float(box.conf.item()) if hasattr(box.conf, "item") else float(box.conf)
            xyxy = box.xyxy[0].tolist() if hasattr(box.xyxy[0], "tolist") else list(box.xyxy[0])
            predictions.append({"image": image_name, "class_id": class_id, "label": str(names.get(class_id, class_id)) if isinstance(names, dict) else str(class_id), "confidence": confidence, "bbox": [float(v) for v in xyxy]})
            label_lines.append(f"{class_id} {' '.join(str(float(v)) for v in xyxy)} {confidence}")
    (labels_dir / (Path(image_name).stem + ".txt")).write_text("\n".join(label_lines) + ("\n" if label_lines else ""), encoding="utf-8")

pred_path.write_text(json.dumps({"ok": True, "predictions": predictions}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
result_path.write_text(json.dumps({"ok": True, "mock": False, "pack_id": "yolo", "job_key": "yolo_predict", "runtime_contract": "0.1", "model": model_name, "device": device, "prediction_count": len(predictions), "artifacts": [{"path": "runs/predict/output/predictions.json", "type": "predictions_json"}, {"path": "runs/predict/output/labels", "type": "labels_dir"}]}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
status_path.write_text(json.dumps({"status": "success", "pack_id": "yolo", "job_key": "yolo_predict", "exit_code": 0}, separators=(",", ":")) + "\n", encoding="utf-8")
emit("success", "Ultralytics predict completed")
PY
then
  if [[ -s "$AIHUB_RESULT_JSON" ]] && grep -q '"ok":false' "$AIHUB_RESULT_JSON"; then
    exit 20
  fi
  fail_job 20 yolo_predict_failed "YOLO predict failed. See logs/stderr.log and result.json."
fi

exit 0
