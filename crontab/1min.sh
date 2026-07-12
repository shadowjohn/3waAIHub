#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUB_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$HUB_ROOT"

COMMAND_LOCK_FILE="${COMMAND_WORKER_LOCK_FILE:-data/jobs/command_worker_1min_command.lock}"
TASK_LOCK_FILE="${TASK_WORKER_LOCK_FILE:-data/jobs/command_worker_1min.lock}"
WORKER_LIMIT="${WORKER_LIMIT:-5}"
TASK_WORKER_LIMIT="${TASK_WORKER_LIMIT:-5}"
WORKER_TICKS="${WORKER_TICKS:-6}"
WORKER_SLEEP="${WORKER_SLEEP:-10}"

mkdir -p data/jobs data/logs

detect_runtime_group() {
  if [ -n "${WEB_GROUP:-}" ]; then
    echo "$WEB_GROUP"
    return 0
  fi
  local candidate
  for candidate in www-data apache nginx http; do
    if getent group "$candidate" >/dev/null; then
      echo "$candidate"
      return 0
    fi
  done
}

RUNTIME_GROUP="$(detect_runtime_group || true)"

wrong_runtime_group() {
  [ -n "$RUNTIME_GROUP" ] && [ "$(stat -c '%G' "$1" 2>/dev/null || true)" != "$RUNTIME_GROUP" ]
}

needs_permission_fix() {
  local dir
  for dir in data data/cache data/uploads data/results data/logs data/logs/jobs data/logs/tasks data/jobs data/services; do
    if [ ! -d "$dir" ] || wrong_runtime_group "$dir" || find "$dir" -maxdepth 0 \( ! -perm -020 -o ! -perm -2000 \) -print -quit | grep -q .; then
      return 0
    fi
  done

  local file
  for file in data/3waaihub.sqlite data/3waaihub.sqlite-wal data/3waaihub.sqlite-shm; do
    if [ -e "$file" ] && { wrong_runtime_group "$file" || find "$file" -maxdepth 0 ! -perm -020 -print -quit | grep -q .; }; then
      return 0
    fi
  done

  return 1
}

if ! command -v flock >/dev/null 2>&1; then
  echo "[3waAIHub] ERROR: flock not found. Install util-linux first."
  exit 1
fi

tick=1
exec 9>"$COMMAND_LOCK_FILE"
if flock -n 9; then
  if needs_permission_fix; then
    echo "[3waAIHub] runtime permissions need repair."
    bash scripts/fix_permissions.sh || echo "[3waAIHub] runtime permissions repair failed."
  fi

  if ! php scripts/collect_host_metrics.php; then
    echo "[3waAIHub] host metrics collection failed."
  fi

  while [ "$tick" -le "$WORKER_TICKS" ]; do
    php scripts/command_worker.php --limit="$WORKER_LIMIT"
    if [ "$tick" -lt "$WORKER_TICKS" ]; then
      sleep "$WORKER_SLEEP"
    fi
    tick=$((tick + 1))
  done
else
  echo "[3waAIHub] command worker already running; skip command jobs."
fi

exec 8>"$TASK_LOCK_FILE"
if ! flock -n 8; then
  echo "[3waAIHub] task worker already running; skip task jobs."
  exit 0
fi

tick=1
while [ "$tick" -le "$WORKER_TICKS" ]; do
  php scripts/task_worker.php --limit="$TASK_WORKER_LIMIT"
  if [ "$tick" -lt "$WORKER_TICKS" ]; then
    sleep "$WORKER_SLEEP"
  fi
  tick=$((tick + 1))
done
