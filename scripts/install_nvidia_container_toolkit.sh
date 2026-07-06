#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

[ "$(id -u)" = "0" ] || { echo "ERROR: root required."; exit 1; }

mkdir -p data/logs/install
LOG_FILE="data/logs/install/nvidia_toolkit_install_$(date +%Y%m%d_%H%M%S).log"
GPU_LOG_FILE="data/logs/install/gpu_smoke_$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1

[ -r /etc/os-release ] || { echo "ERROR: /etc/os-release not found."; exit 1; }
command -v apt-get >/dev/null || { echo "ERROR: apt-get not found. Only Ubuntu/Debian apt systems are supported."; exit 1; }
command -v nvidia-smi >/dev/null || {
  echo "ERROR: nvidia-smi not found. Install and verify the NVIDIA driver first; this script does not install drivers."
  exit 1
}
command -v docker >/dev/null || {
  echo "ERROR: Docker not found. Run --bootstrap-host --with-docker first or include --with-docker."
  exit 1
}

. /etc/os-release
case "${ID:-}" in
  ubuntu|debian) ;;
  *)
    echo "ERROR: only Ubuntu/Debian apt systems are supported for now. Detected: ${ID:-unknown}"
    echo "Log: $LOG_FILE"
    exit 1
    ;;
esac

echo "[3waAIHub] NVIDIA Container Toolkit bootstrap log: $LOG_FILE"
nvidia-smi

if command -v nvidia-ctk >/dev/null; then
  echo "[3waAIHub] NVIDIA Container Toolkit already exists; skipping package installation."
else
  echo "[3waAIHub] Installing NVIDIA Container Toolkit from NVIDIA repository."
  apt-get update
  apt-get install -y curl gnupg ca-certificates
  curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey \
    | gpg --dearmor -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
  curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
    | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
    > /etc/apt/sources.list.d/nvidia-container-toolkit.list
  apt-get update
  apt-get install -y nvidia-container-toolkit
fi

mkdir -p /etc/docker
if [ -f /etc/docker/daemon.json ]; then
  BACKUP="/etc/docker/daemon.json.3waAIHub.$(date +%Y%m%d_%H%M%S).bak"
  cp -a /etc/docker/daemon.json "$BACKUP"
  echo "[3waAIHub] Backed up Docker daemon config: $BACKUP"
else
  echo "[3waAIHub] No existing /etc/docker/daemon.json to backup."
fi

nvidia-ctk runtime configure --runtime=docker
if command -v systemctl >/dev/null; then
  systemctl restart docker
else
  service docker restart
fi

echo "[3waAIHub] GPU container smoke log: $GPU_LOG_FILE"
if docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi >"$GPU_LOG_FILE" 2>&1; then
  cat "$GPU_LOG_FILE"
  echo "[3waAIHub] GPU smoke passed with CUDA 12.9.0 image."
else
  echo "[3waAIHub] CUDA 12.9.0 smoke failed; falling back to CUDA 12.6.3 image." | tee -a "$GPU_LOG_FILE"
  if docker run --rm --gpus all nvidia/cuda:12.6.3-base-ubuntu22.04 nvidia-smi >>"$GPU_LOG_FILE" 2>&1; then
    cat "$GPU_LOG_FILE"
    echo "[3waAIHub] GPU smoke passed with CUDA 12.6.3 fallback image."
  else
    cat "$GPU_LOG_FILE"
    echo "ERROR: GPU container smoke failed. See: $GPU_LOG_FILE"
    exit 1
  fi
fi

nvidia-ctk --version
echo "[3waAIHub] NVIDIA Container Toolkit bootstrap completed."
echo "Log: $LOG_FILE"
echo "GPU smoke log: $GPU_LOG_FILE"
