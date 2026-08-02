#!/usr/bin/env bash
set -euo pipefail

: "${AIHUB_MODELS_DIR:?AIHUB_MODELS_DIR is required}"
case "$AIHUB_MODELS_DIR" in /*) ;; *) echo 'AIHUB_MODELS_DIR must be absolute' >&2; exit 64 ;; esac

model_dir="$AIHUB_MODELS_DIR/gpt_sovits"
mkdir -p "$model_dir"

exec docker run --rm \
  --mount "type=bind,src=$model_dir,dst=/models/gpt_sovits" \
  --env GPT_SOVITS_MODEL_DIR=/models/gpt_sovits \
  --entrypoint /app/provision-offline-assets \
  3waaihub/tts-gpt-sovits:0.1.0
