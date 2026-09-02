#!/usr/bin/env bash

# 三支 YOLO Local Job 共用 Docker 事前檢查，避免把 socket 權限問題誤判成 image 遺失。
yolo_docker_probe_image() {
  local image="$1"
  local inspect_output
  local inspect_lower

  YOLO_DOCKER_PROBE_ERROR=""
  YOLO_DOCKER_PROBE_MESSAGE=""
  YOLO_DOCKER_PROBE_STDERR=""
  YOLO_DOCKER_PROBE_STAGE=""

  if ! command -v docker >/dev/null 2>&1; then
    YOLO_DOCKER_PROBE_ERROR="docker_unavailable"
    YOLO_DOCKER_PROBE_MESSAGE="docker command is required for YOLO jobs."
    YOLO_DOCKER_PROBE_STDERR="docker command was not found on PATH."
    YOLO_DOCKER_PROBE_STAGE="command"
    printf '%s\n' "$YOLO_DOCKER_PROBE_STDERR" >&2
    return 1
  fi

  if inspect_output="$(docker image inspect --format '{{.Id}}' "$image" 2>&1)"; then
    return 0
  fi

  YOLO_DOCKER_PROBE_STDERR="$inspect_output"
  YOLO_DOCKER_PROBE_STAGE="inspect"
  inspect_lower="$(printf '%s' "$inspect_output" | tr '[:upper:]' '[:lower:]')"
  case "$inspect_lower" in
    *"permission denied"*|*"access is denied"*)
      YOLO_DOCKER_PROBE_ERROR="docker_permission_denied"
      YOLO_DOCKER_PROBE_MESSAGE="Docker socket permission was denied while inspecting YOLO image: $image."
      ;;
    *"no such image"*|*"no such object"*)
      YOLO_DOCKER_PROBE_ERROR="yolo_image_missing"
      YOLO_DOCKER_PROBE_MESSAGE="YOLO Docker image is missing: $image. Build yolo-main first or set AIHUB_YOLO_IMAGE."
      ;;
    *)
      YOLO_DOCKER_PROBE_ERROR="docker_unavailable"
      YOLO_DOCKER_PROBE_MESSAGE="Docker is unavailable while inspecting YOLO image: $image."
      ;;
  esac

  printf '%s\n' "$YOLO_DOCKER_PROBE_STDERR" >&2
  return 1
}

yolo_write_failure_result() {
  local result_path="$1"
  local job_key="$2"
  local error="$3"
  local message="$4"
  local stderr_detail="${5:-}"

  python3 - "$result_path" "$job_key" "$error" "$message" "$stderr_detail" <<'PY'
import json
import sys
from pathlib import Path

result_path, job_key, error, message, stderr_detail = sys.argv[1:]
payload = {
    "ok": False,
    "mock": False,
    "pack_id": "yolo",
    "job_key": job_key,
    "runtime_contract": "0.1",
    "error": error,
    "message": message,
    "artifacts": [],
}
if stderr_detail:
    payload["stderr"] = stderr_detail
Path(result_path).write_text(
    json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n",
    encoding="utf-8",
)
PY
}
