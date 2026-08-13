#!/usr/bin/env bash
set -euo pipefail

: "${AIHUB_MODELS_DIR:?AIHUB_MODELS_DIR is required}"
: "${AIHUB_CACHE_DIR:?AIHUB_CACHE_DIR is required}"

case "$AIHUB_MODELS_DIR" in /*) ;; *) echo 'AIHUB_MODELS_DIR must be absolute' >&2; exit 64 ;; esac
case "$AIHUB_CACHE_DIR" in /*) ;; *) echo 'AIHUB_CACHE_DIR must be absolute' >&2; exit 64 ;; esac

mkdir -p "$AIHUB_MODELS_DIR" "$AIHUB_CACHE_DIR"
provision_args=(--languages "${AIHUB_WHISPER_ALIGNMENT_LANGUAGES:-en}")
docker_env=()
runtime_profile="${AIHUB_WHISPER_RUNTIME_PROFILE:-default}"
image=''
case "${AIHUB_WHISPER_PROVISION_DIARIZATION:-0}" in
  0) ;;
  1)
    : "${AIHUB_SECRET_PYANNOTE_TOKEN:?AIHUB_SECRET_PYANNOTE_TOKEN is required when provisioning diarization}"
    provision_args+=(--with-diarization)
    docker_env+=(--env AIHUB_SECRET_PYANNOTE_TOKEN)
    ;;
  *) echo 'AIHUB_WHISPER_PROVISION_DIARIZATION must be 0 or 1' >&2; exit 64 ;;
esac
case "${AIHUB_WHISPER_PROVISION_CKIP:-0}" in
  0) ;;
  1) provision_args+=(--with-ckip) ;;
  *) echo 'AIHUB_WHISPER_PROVISION_CKIP must be 0 or 1' >&2; exit 64 ;;
esac

case "$runtime_profile" in
  default)
    image='3waaihub/whisper-asr:0.1.2'
    ;;
  pascal-cu118)
    if [ "${AIHUB_WHISPER_PROVISION_DIARIZATION:-0}" != '0' ]; then
      echo 'Pascal CUDA 11.8 provisioning does not support diarization' >&2
      exit 64
    fi
    if [ "${AIHUB_WHISPER_PROVISION_CKIP:-0}" != '1' ]; then
      echo 'Pascal CUDA 11.8 provisioning requires AIHUB_WHISPER_PROVISION_CKIP=1' >&2
      exit 64
    fi
    image='3waaihub/whisper-asr:0.1.2-pascal-cu118'
    provision_args+=(--ckip-only)
    ;;
  *)
    echo 'AIHUB_WHISPER_RUNTIME_PROFILE must be default or pascal-cu118' >&2
    exit 64
    ;;
esac

exec docker run --rm \
  --mount "type=bind,src=$AIHUB_MODELS_DIR,dst=/models" \
  --mount "type=bind,src=$AIHUB_CACHE_DIR,dst=/cache" \
  --env AIHUB_MODELS_DIR=/models \
  --env AIHUB_CACHE_DIR=/cache \
  "${docker_env[@]}" \
  --entrypoint /app/provision-offline-assets \
  "$image" \
  "${provision_args[@]}"
