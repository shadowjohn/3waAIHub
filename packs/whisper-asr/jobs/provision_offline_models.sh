#!/usr/bin/env bash
set -euo pipefail

: "${AIHUB_MODELS_DIR:?AIHUB_MODELS_DIR is required}"
: "${AIHUB_CACHE_DIR:?AIHUB_CACHE_DIR is required}"
: "${AIHUB_SECRET_PYANNOTE_TOKEN:?AIHUB_SECRET_PYANNOTE_TOKEN is required}"

case "$AIHUB_MODELS_DIR" in /*) ;; *) echo 'AIHUB_MODELS_DIR must be absolute' >&2; exit 64 ;; esac
case "$AIHUB_CACHE_DIR" in /*) ;; *) echo 'AIHUB_CACHE_DIR must be absolute' >&2; exit 64 ;; esac

mkdir -p "$AIHUB_MODELS_DIR" "$AIHUB_CACHE_DIR"
exec docker run --rm \
  --mount "type=bind,src=$AIHUB_MODELS_DIR,dst=/hub/models" \
  --mount "type=bind,src=$AIHUB_CACHE_DIR,dst=/hub/cache" \
  --env AIHUB_MODELS_DIR=/hub/models \
  --env AIHUB_CACHE_DIR=/hub/cache \
  --env AIHUB_SECRET_PYANNOTE_TOKEN \
  --entrypoint /app/provision-offline-assets \
  3waaihub/whisper-asr:0.1.0 \
  --languages "${AIHUB_WHISPER_ALIGNMENT_LANGUAGES:-en}"
