#!/usr/bin/env bash
set -euo pipefail

: "${AIHUB_MODELS_DIR:?AIHUB_MODELS_DIR is required}"
case "$AIHUB_MODELS_DIR" in /*) ;; *) echo 'AIHUB_MODELS_DIR must be absolute' >&2; exit 64 ;; esac

require_safe_directory_path() {
  local path="$1" current="/" part
  local -a parts
  IFS=/ read -r -a parts <<< "${path#/}"
  for part in "${parts[@]}"; do
    [ -n "$part" ] || continue
    current="${current%/}/$part"
    if [ -L "$current" ] || { [ -e "$current" ] && [ ! -d "$current" ]; }; then
      echo 'model storage path is unsafe' >&2
      exit 64
    fi
  done
}

model_parent="$AIHUB_MODELS_DIR/speech-fast-zh"
model_dir="$model_parent/paraformer-zh-small-2024-03-09"
require_safe_directory_path "$AIHUB_MODELS_DIR"
require_safe_directory_path "$model_parent"
if [ -L "$model_dir" ] || { [ -e "$model_dir" ] && [ ! -d "$model_dir" ]; }; then
  echo 'model storage path is unsafe' >&2
  exit 64
fi
mkdir -p "$model_parent"
require_safe_directory_path "$model_parent"
mounts=(--mount "type=bind,src=$model_parent,dst=/models/speech-fast-zh")
args=(--model-dir /models/speech-fast-zh/paraformer-zh-small-2024-03-09)

if [ "$#" -gt 0 ]; then
  if [ "$#" -ne 2 ] || [ "$1" != "--archive" ]; then
    echo 'usage: provision_offline_models.sh [--archive /absolute/model.tar.bz2]' >&2
    exit 64
  fi
  case "$2" in /*) ;; *) echo 'archive must be absolute' >&2; exit 64 ;; esac
  if [ ! -f "$2" ] || [ -L "$2" ]; then
    echo 'archive must be a regular file' >&2
    exit 64
  fi
  mounts+=(--mount "type=bind,src=$2,dst=/archive/model.tar.bz2,readonly")
  args+=(--archive /archive/model.tar.bz2)
fi

exec docker run --pull=never --rm \
  "${mounts[@]}" \
  --entrypoint /app/provision-offline-assets \
  3waaihub/speech-fast-zh:0.1.0 \
  "${args[@]}"
