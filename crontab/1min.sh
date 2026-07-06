#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HUB_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$HUB_ROOT"

LOCK_FILE="${WORKER_LOCK_FILE:-data/jobs/command_worker_1min.lock}"
WORKER_LIMIT="${WORKER_LIMIT:-5}"
WORKER_TICKS="${WORKER_TICKS:-6}"
WORKER_SLEEP="${WORKER_SLEEP:-10}"

mkdir -p data/jobs data/logs

if ! command -v flock >/dev/null 2>&1; then
  echo "[3waAIHub] ERROR: flock not found. Install util-linux first."
  exit 1
fi

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
  echo "[3waAIHub] command worker already running; skip this minute."
  exit 0
fi

tick=1
while [ "$tick" -le "$WORKER_TICKS" ]; do
  php scripts/command_worker.php --limit="$WORKER_LIMIT"
  if [ "$tick" -lt "$WORKER_TICKS" ]; then
    sleep "$WORKER_SLEEP"
  fi
  tick=$((tick + 1))
done
