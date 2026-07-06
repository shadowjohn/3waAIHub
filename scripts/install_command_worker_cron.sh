#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "$(id -u)" != "0" ]; then
  echo "ERROR: root required to install command worker cron."
  exit 1
fi

WORKER_USER="${WORKER_USER:-root}"
WORKER_LIMIT="${WORKER_LIMIT:-5}"
WORKER_TICKS="${WORKER_TICKS:-6}"
WORKER_SLEEP="${WORKER_SLEEP:-10}"
CRON_FILE="${CRON_FILE:-/etc/cron.d/3waaihub-command-worker}"
HUB_ROOT="$(pwd)"
LOOP_SCRIPT="$HUB_ROOT/crontab/1min.sh"
LOG_PATH="$HUB_ROOT/data/logs/command_worker_1min.log"

id "$WORKER_USER" >/dev/null 2>&1 || {
  echo "ERROR: worker user not found: $WORKER_USER"
  exit 1
}

mkdir -p data/jobs data/logs
chmod +x "$LOOP_SCRIPT"
touch "$LOG_PATH"
chmod 664 "$LOG_PATH"

tmp="$(mktemp)"
cat >"$tmp" <<EOF
# 3waAIHub command worker
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * $WORKER_USER cd $HUB_ROOT && WORKER_LIMIT=$WORKER_LIMIT WORKER_TICKS=$WORKER_TICKS WORKER_SLEEP=$WORKER_SLEEP $LOOP_SCRIPT >> $LOG_PATH 2>&1
EOF

install -m 0644 "$tmp" "$CRON_FILE"
rm -f "$tmp"

echo "[3waAIHub] Installed command worker cron: $CRON_FILE"
echo "[3waAIHub] Worker user: $WORKER_USER"
echo "[3waAIHub] Loop script: $LOOP_SCRIPT"
echo "[3waAIHub] Log: $LOG_PATH"
