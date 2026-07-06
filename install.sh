#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

MODE="install"
BOOTSTRAP_HOST=0
WITH_DOCKER=0
WITH_NVIDIA=0
YES=0

usage() {
  cat <<'EOF'
Usage:
  ./install.sh
  ./install.sh --check
  ./install.sh --bootstrap-host --with-docker
  ./install.sh --bootstrap-host --with-nvidia
  ./install.sh --yes --bootstrap-host --with-docker --with-nvidia
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --check)
      MODE="check"
      ;;
    --bootstrap-host)
      BOOTSTRAP_HOST=1
      ;;
    --with-docker)
      WITH_DOCKER=1
      ;;
    --with-nvidia)
      WITH_NVIDIA=1
      ;;
    --yes|-y)
      YES=1
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "ERROR: unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
  shift
done

status_line() {
  local label="$1"
  local out
  local err
  shift
  out="$(mktemp)"
  err="$(mktemp)"
  if "$@" >"$out" 2>"$err"; then
    echo "$label: OK ($(tr '\n' ' ' <"$out" | sed 's/[[:space:]]*$//'))"
  else
    echo "$label: MISSING"
  fi
  rm -f "$out" "$err"
}

print_check() {
  echo "Mode: check"
  status_line "PHP" php -v
  if php -m | grep -qi '^pdo_sqlite$' && php -m | grep -qi '^sqlite3$'; then
    echo "SQLite extension: OK"
  else
    echo "SQLite extension: MISSING"
  fi
  status_line "Docker" docker --version
  status_line "Docker Compose" docker compose version
  status_line "nvidia-smi" nvidia-smi --query-gpu=name --format=csv,noheader
  status_line "nvidia-ctk" nvidia-ctk --version
}

check_app_dependencies() {
  command -v php >/dev/null || { echo "PHP not found"; exit 1; }
  php -m | grep -qi '^pdo_sqlite$' || { echo "PHP pdo_sqlite extension not found"; exit 1; }
  php -m | grep -qi '^sqlite3$' || { echo "PHP sqlite3 extension not found"; exit 1; }

  if command -v docker >/dev/null; then
    docker --version || true
    docker compose version >/dev/null || echo "[3waAIHub] WARNING: Docker compose not found"
  else
    echo "[3waAIHub] WARNING: Docker not found; Docker service actions will fail until installed."
  fi
}

fix_runtime_permissions() {
  echo "[3waAIHub] Fixing runtime permissions..."
  ./scripts/fix_permissions.sh
}

confirm_bootstrap() {
  [ "$YES" = "1" ] && return 0
  echo "[3waAIHub] Host bootstrap will install or verify system packages."
  echo "[3waAIHub] Selected: docker=$WITH_DOCKER nvidia=$WITH_NVIDIA"
  printf "Type YES to continue: "
  read -r answer
  [ "$answer" = "YES" ] || { echo "Cancelled."; exit 1; }
}

run_bootstrap() {
  [ "$(id -u)" = "0" ] || { echo "ERROR: --bootstrap-host requires root."; exit 1; }
  [ "$WITH_DOCKER" = "1" ] || [ "$WITH_NVIDIA" = "1" ] || { echo "ERROR: choose --with-docker and/or --with-nvidia."; exit 1; }

  confirm_bootstrap
  fix_runtime_permissions
  if [ "$WITH_DOCKER" = "1" ]; then
    ./scripts/install_docker_ubuntu.sh
  fi
  if [ "$WITH_NVIDIA" = "1" ]; then
    ./scripts/install_nvidia_container_toolkit.sh
  fi
}

if [ "$MODE" = "check" ]; then
  print_check
  exit 0
fi

if [ "$BOOTSTRAP_HOST" = "1" ]; then
  run_bootstrap
  exit 0
fi

mkdir -p data/logs/jobs data/logs/install data/jobs data/results data/cache
fix_runtime_permissions

echo "[3waAIHub] Checking environment..."
check_app_dependencies

if [ "$(id -u)" = "0" ]; then
  echo "[3waAIHub] Running app setup as root."
  echo "[3waAIHub] WARNING: users in the docker group effectively have root-equivalent control of the host."
else
  echo "[3waAIHub] Running as non-root local setup."
fi

[ -w data ] || { echo "data/ is not writable"; exit 1; }

echo "[3waAIHub] Initializing SQLite..."
php scripts/init_db.php
fix_runtime_permissions

echo "[3waAIHub] Done."
echo "Login URL: http://localhost/3waAIHub/login.php"
echo "Admin URL: http://localhost/3waAIHub/admin/"
echo "Default login: admin / admin123"
echo "Crontab worker: * * * * * php $(pwd)/scripts/command_worker.php --limit=5 >> $(pwd)/data/logs/command_worker.log 2>&1"
