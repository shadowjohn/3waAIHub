#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

[ "$(id -u)" = "0" ] || { echo "ERROR: root required."; exit 1; }

mkdir -p data/logs/install
LOG_FILE="data/logs/install/docker_install_$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1

[ -r /etc/os-release ] || { echo "ERROR: /etc/os-release not found."; exit 1; }
command -v apt-get >/dev/null || { echo "ERROR: apt-get not found. Only Ubuntu/Debian apt systems are supported."; exit 1; }

. /etc/os-release
case "${ID:-}" in
  ubuntu|debian) ;;
  *)
    echo "ERROR: only Ubuntu/Debian apt systems are supported for now. Detected: ${ID:-unknown}"
    echo "Log: $LOG_FILE"
    exit 1
    ;;
esac

echo "[3waAIHub] Docker bootstrap log: $LOG_FILE"

if command -v docker >/dev/null; then
  echo "[3waAIHub] Docker already exists; verifying only."
else
  echo "[3waAIHub] Installing Docker from official apt repository."
  apt-get update
  apt-get install -y ca-certificates curl gnupg
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" > /etc/apt/sources.list.d/docker.list
  apt-get update
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

if command -v systemctl >/dev/null; then
  systemctl enable docker
  systemctl start docker
else
  service docker start
fi

docker --version
docker compose version
docker run --rm hello-world

echo "[3waAIHub] Docker bootstrap completed."
echo "Log: $LOG_FILE"
