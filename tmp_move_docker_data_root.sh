#!/usr/bin/env bash
set -Eeuo pipefail

cd "$(dirname "$0")"

if [ "$(id -u)" != "0" ]; then
  echo "ERROR: root required."
  exit 1
fi

ts="$(date +%Y%m%d_%H%M%S)"
log_dir="/DATA/3waAIHub/data/logs/install"
mkdir -p "$log_dir"
log_file="$log_dir/move_docker_data_root_${ts}.log"
exec > >(tee -a "$log_file") 2>&1

echo "[3waAIHub] Moving Docker data-root to /DATA/docker"
echo "[3waAIHub] Log: $log_file"

before_root="$(docker info 2>/dev/null | awk -F': ' 'tolower($1) ~ /docker root dir/ {print $2}' || true)"
echo "Docker Root Dir before: ${before_root:-unknown}"
echo
docker ps -a || true
echo
docker images || true
echo
df -h / /DATA /var/lib/docker 2>/dev/null || df -h / /DATA

mkdir -p /DATA/docker

echo "[3waAIHub] Stopping Docker..."
systemctl stop docker || true
systemctl stop docker.socket || true
systemctl stop containerd || true
systemctl is-active docker || true
systemctl is-active containerd || true

if [ -d /var/lib/docker ] && [ "$(find /var/lib/docker -mindepth 1 -maxdepth 1 2>/dev/null | head -n 1)" != "" ]; then
  echo "[3waAIHub] Syncing /var/lib/docker -> /DATA/docker ..."
  rsync -aHAX --numeric-ids /var/lib/docker/ /DATA/docker/
else
  echo "[3waAIHub] /var/lib/docker missing or empty; continuing."
fi

mkdir -p /etc/docker
daemon_backup=""
if [ -f /etc/docker/daemon.json ]; then
  daemon_backup="/etc/docker/daemon.json.bak.${ts}"
  cp /etc/docker/daemon.json "$daemon_backup"
  echo "daemon.json backup path: $daemon_backup"
else
  echo "daemon.json backup path: none"
fi

python3 - <<'PY'
import json
from pathlib import Path

path = Path("/etc/docker/daemon.json")
data = {}
if path.exists() and path.read_text().strip():
    data = json.loads(path.read_text())
data["data-root"] = "/DATA/docker"
tmp = path.with_suffix(".json.tmp")
tmp.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")
tmp.replace(path)
print(path.read_text())
PY

echo "[3waAIHub] Starting Docker..."
systemctl start containerd
systemctl start docker
systemctl status docker --no-pager || true

after_root="$(docker info | awk -F': ' 'tolower($1) ~ /docker root dir/ {print $2}')"
echo "Docker Root Dir after: $after_root"
if [ "$after_root" != "/DATA/docker" ]; then
  echo "ERROR: Docker Root Dir is not /DATA/docker."
  exit 1
fi

echo "[3waAIHub] Smoke test: hello-world"
docker run --rm hello-world
hello_status="PASS"

echo "[3waAIHub] Smoke test: NVIDIA CUDA 12.9"
gpu_status="FAIL"
if docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi; then
  gpu_status="PASS"
else
  echo "[3waAIHub] CUDA 12.9 failed; trying 12.6.3 fallback."
  if docker run --rm --gpus all nvidia/cuda:12.6.3-base-ubuntu22.04 nvidia-smi; then
    gpu_status="PASS (fallback 12.6.3)"
  fi
fi

if [ "$gpu_status" = "FAIL" ]; then
  docker info | sed -n '/Runtimes:/,/Default Runtime:/p' || true
  echo "ERROR: GPU Docker smoke test failed."
  exit 1
fi

old_backup=""
if [ -d /var/lib/docker ]; then
  old_backup="/var/lib/docker.bak.${ts}"
  mv /var/lib/docker "$old_backup"
  echo "old /var/lib/docker backup path: $old_backup"
else
  echo "old /var/lib/docker backup path: none"
fi

systemctl restart docker
after_restart_root="$(docker info | awk -F': ' 'tolower($1) ~ /docker root dir/ {print $2}')"
echo "Docker Root Dir after old-dir backup: $after_restart_root"
docker run --rm hello-world
docker run --rm --gpus all nvidia/cuda:12.9.0-base-ubuntu22.04 nvidia-smi

echo
echo "SUMMARY"
echo "Docker Root Dir before: ${before_root:-unknown}"
echo "Docker Root Dir after: $after_restart_root"
echo "daemon.json backup path: ${daemon_backup:-none}"
echo "old /var/lib/docker backup path: ${old_backup:-none}"
echo "docker hello-world: $hello_status"
echo "docker gpu nvidia-smi: $gpu_status"
echo "df -h summary:"
df -h / /DATA /DATA/docker "${old_backup:-/var/lib/docker}" 2>/dev/null || df -h / /DATA /DATA/docker
