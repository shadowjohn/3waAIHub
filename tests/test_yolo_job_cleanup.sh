#!/usr/bin/env bash
set -euo pipefail

jobs=(
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_predict.sh"
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_train.sh"
  "/DATA/3waAIHub/packs/yolo/jobs/yolo_export_onnx.sh"
)

for job in "${jobs[@]}"; do
  grep -Fq 'container_name="aihub-${AIHUB_RUN_ID:-${AIHUB_JOB_KEY:-yolo}-$$}"' "$job"
  grep -Fq 'trap '\''docker rm -f "$container_name" >/dev/null 2>&1 || true'\'' TERM INT EXIT' "$job"
  grep -Fq 'docker_args+=(--name "$container_name")' "$job"
done

echo "yolo job cleanup source OK"
