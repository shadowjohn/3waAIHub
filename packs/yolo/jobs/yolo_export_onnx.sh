#!/usr/bin/env bash
set -euo pipefail

mkdir -p "$AIHUB_WORKSPACE/output" "$AIHUB_WORKSPACE/logs" "$AIHUB_WORKSPACE/runs/export/output"

progress() {
  printf '{"ts":"%s","status":"%s","message":"%s"}\n' "$(date -u +"%Y-%m-%dT%H:%M:%SZ")" "$1" "$2" >> "$AIHUB_PROGRESS_NDJSON"
}

write_status() {
  cat > "$AIHUB_STATUS_JSON" <<JSON
{"status":"$1","pack_id":"yolo","job_key":"yolo_export_onnx","finished_at":"$(date -u +"%Y-%m-%dT%H:%M:%SZ")","exit_code":$2}
JSON
}

fail_job() {
  local code="$1"
  local error="$2"
  local message="$3"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":false,"mock":false,"pack_id":"yolo","job_key":"yolo_export_onnx","runtime_contract":"0.1","error":"$error","message":"$message","artifacts":[]}
JSON
  write_status failed "$code"
  progress failed "$message"
  exit "$code"
}

if [[ ! -f "$AIHUB_REQUEST_JSON" ]]; then
  fail_job 11 missing_request_json "workspace/request.json is required."
fi

set +e
validation="$(python3 - "$AIHUB_WORKSPACE" "$AIHUB_REQUEST_JSON" "${AIHUB_YOLO_MODELS_DIR:-/DATA/models/yolo}" <<'PY'
import json
import sys
from pathlib import Path

workspace = Path(sys.argv[1]).resolve()
request = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8") or "{}")
models_dir = Path(sys.argv[3]).resolve()
model = str(request.get("model") or "")
if not model:
    print("missing_model")
    sys.exit(2)
if model.startswith("/") or ".." in Path(model).parts:
    print("model_path_escape")
    sys.exit(3)
path = (workspace / model).resolve()
if path != workspace and workspace not in path.parents:
    print("model_path_escape")
    sys.exit(4)
models_path = (models_dir / model).resolve()
models_ok = models_path == models_dir or models_dir in models_path.parents
if not path.is_file() and not (models_ok and models_path.is_file()):
    print("model_missing")
    sys.exit(5)
print("ok")
PY
)"
set -e
case "$validation" in
  ok) ;;
  missing_model) fail_job 12 missing_model "request.json model is required." ;;
  model_path_escape) fail_job 13 model_path_escape "model must stay inside workspace." ;;
  model_missing) fail_job 14 model_missing "model file does not exist." ;;
  *) fail_job 15 invalid_request "invalid request.json." ;;
esac

progress running "YOLO export ONNX starting"

if [[ "${AIHUB_YOLO_EXPORT_DRY_RUN:-0}" == "1" ]]; then
  printf 'dry-run onnx\n' > "$AIHUB_WORKSPACE/runs/export/output/model.onnx"
  cat > "$AIHUB_RESULT_JSON" <<JSON
{"ok":true,"mock":false,"dry_run":true,"pack_id":"yolo","job_key":"yolo_export_onnx","runtime_contract":"0.1","artifacts":[{"path":"runs/export/output/model.onnx","type":"onnx_model"}]}
JSON
  write_status success 0
  progress success "YOLO export ONNX dry-run completed"
  exit 0
fi

if ! command -v docker >/dev/null 2>&1; then
  fail_job 16 docker_unavailable "docker command is required for YOLO export."
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

if ! docker run "${docker_args[@]}" "$image" python3 - <<'PY'
import json
import os
import shutil
import sys
from pathlib import Path

workspace = Path("/workspace")
request = json.loads((workspace / "request.json").read_text(encoding="utf-8") or "{}")
output = workspace / "runs/export/output"
progress_path = workspace / "progress.ndjson"
result_path = workspace / "result.json"
status_path = workspace / "status.json"

def emit(status, message):
    from datetime import datetime, timezone
    progress_path.open("a", encoding="utf-8").write(json.dumps({"ts": datetime.now(timezone.utc).isoformat(), "status": status, "message": message}, ensure_ascii=False, separators=(",", ":")) + "\n")

def fail(code, error, message):
    result_path.write_text(json.dumps({"ok": False, "mock": False, "pack_id": "yolo", "job_key": "yolo_export_onnx", "runtime_contract": "0.1", "error": error, "message": message, "artifacts": []}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    status_path.write_text(json.dumps({"status": "failed", "pack_id": "yolo", "job_key": "yolo_export_onnx", "exit_code": code}, separators=(",", ":")) + "\n", encoding="utf-8")
    emit("failed", message)
    sys.exit(code)

try:
    from ultralytics import YOLO
except Exception as exc:
    fail(21, "ultralytics_unavailable", f"Ultralytics import failed: {exc}")

model_rel = str(request.get("model") or "")
workspace_model = workspace / model_rel
models_model = Path("/models/yolo") / model_rel
model_path = workspace_model if workspace_model.is_file() else models_model
if not workspace_model.is_file():
    local_model_dir = workspace / ".cache/export_model"
    local_model_dir.mkdir(parents=True, exist_ok=True)
    local_model = local_model_dir / models_model.name
    shutil.copy2(models_model, local_model)
    model_path = local_model
imgsz = int(request.get("imgsz", 640))
device = str(request.get("device") or ("0" if os.environ.get("AIHUB_GPU_INDEXES") else "cpu"))
output.mkdir(parents=True, exist_ok=True)
try:
    exported = YOLO(str(model_path)).export(format="onnx", imgsz=imgsz, device=device)
except Exception as exc:
    fail(22, "export_failed", str(exc))

exported_path = Path(str(exported))
target = output / "model.onnx"
if exported_path.is_file():
    shutil.copy2(exported_path, target)
elif model_path.with_suffix(".onnx").is_file():
    shutil.copy2(model_path.with_suffix(".onnx"), target)
else:
    fail(23, "onnx_missing", "Ultralytics export completed but ONNX file was not produced.")

result_path.write_text(json.dumps({"ok": True, "mock": False, "pack_id": "yolo", "job_key": "yolo_export_onnx", "runtime_contract": "0.1", "model": model_rel, "device": device, "artifacts": [{"path": "runs/export/output/model.onnx", "type": "onnx_model"}]}, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
status_path.write_text(json.dumps({"status": "success", "pack_id": "yolo", "job_key": "yolo_export_onnx", "exit_code": 0}, separators=(",", ":")) + "\n", encoding="utf-8")
emit("success", "Ultralytics export ONNX completed")
PY
then
  if [[ -s "$AIHUB_RESULT_JSON" ]] && grep -q '"ok":false' "$AIHUB_RESULT_JSON"; then
    exit 20
  fi
  fail_job 20 yolo_export_failed "YOLO export ONNX failed. See logs/stderr.log and result.json."
fi

exit 0
