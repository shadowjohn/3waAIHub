#!/usr/bin/env bash
set -euo pipefail

: "${AIHUB_MODELS_DIR:?AIHUB_MODELS_DIR is required}"
: "${AIHUB_CACHE_DIR:?AIHUB_CACHE_DIR is required}"

case "$AIHUB_MODELS_DIR" in /*) ;; *) echo 'AIHUB_MODELS_DIR must be absolute' >&2; exit 64 ;; esac
case "$AIHUB_CACHE_DIR" in /*) ;; *) echo 'AIHUB_CACHE_DIR must be absolute' >&2; exit 64 ;; esac

mkdir -p "$AIHUB_MODELS_DIR" "$AIHUB_CACHE_DIR"
provision_args=(--languages "${AIHUB_WHISPER_ALIGNMENT_LANGUAGES:-en}")
docker_env=()
case "${AIHUB_WHISPER_PROVISION_DIARIZATION:-0}" in
  0) ;;
  1)
    : "${AIHUB_SECRET_PYANNOTE_TOKEN:?AIHUB_SECRET_PYANNOTE_TOKEN is required when provisioning diarization}"
    provision_args+=(--with-diarization)
    docker_env+=(--env AIHUB_SECRET_PYANNOTE_TOKEN)
    ;;
  *) echo 'AIHUB_WHISPER_PROVISION_DIARIZATION must be 0 or 1' >&2; exit 64 ;;
esac

exec docker run --rm \
  --mount "type=bind,src=$AIHUB_MODELS_DIR,dst=/models" \
  --mount "type=bind,src=$AIHUB_CACHE_DIR,dst=/cache" \
  --env AIHUB_MODELS_DIR=/models \
  --env AIHUB_CACHE_DIR=/cache \
  "${docker_env[@]}" \
  --entrypoint /app/provision-offline-assets \
  3waaihub/whisper-asr:0.1.0 \
  "${provision_args[@]}"
