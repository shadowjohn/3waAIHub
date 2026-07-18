#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

APP_USER="${APP_USER:-}"
APP_GROUP="${APP_GROUP:-}"
WEB_GROUP="${WEB_GROUP:-}"

if [ "$(id -u)" = "0" ] && [ -z "$APP_USER" ] && [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
  APP_USER="$SUDO_USER"
fi

detect_web_group() {
  if [ -n "$WEB_GROUP" ]; then
    echo "$WEB_GROUP"
    return 0
  fi

  for candidate in www-data apache nginx http; do
    if getent group "$candidate" >/dev/null; then
      echo "$candidate"
      return 0
    fi
  done
}

for dir in data data/cache data/uploads data/results data/logs data/logs/jobs data/logs/tasks data/logs/install data/jobs data/services; do
  mkdir -p "$dir"
done

# Git tracks executable bits but not read bits. A restrictive umask, archive
# extraction, or root-side sync can leave PHP source as 600/700 and make Apache
# fail during bootstrap before the app can log the error.
find . \( -path './.git' -o -path './data' \) -prune -o -type d -exec chmod u+rwx,go+rx {} +
find . \( -path './.git' -o -path './data' \) -prune -o -type f -exec chmod u+rw,go+r {} +
find . \( -path './.git' -o -path './data' \) -prune -o -type f -perm -0100 -exec chmod go+rx {} +

for dir in /DATA/models /DATA/models/paddleocr /DATA/models/yolo /DATA/models/yolo/registry /DATA/models/ollama /DATA/models/sam3; do
  mkdir -p "$dir" 2>/dev/null || true
  chmod u+rwx,g+rwx,o+rx "$dir" 2>/dev/null || true
done

if [ "$(id -u)" = "0" ]; then
  find data -type d -exec chmod u+rwx,g+rwx,o+rx {} +
else
  find data -type d ! -perm -2000 -exec chmod u+rwx,g+rwx,o+rx {} +
fi
find data -type f -exec chmod u+rw,g+rw,o+r {} +

if [ "$(id -u)" = "0" ]; then
  if [ -n "$APP_USER" ] || [ -n "$APP_GROUP" ]; then
    owner="${APP_USER:-}"
    group="${APP_GROUP:-}"
    chown -R "${owner}${group:+:$group}" data
  fi

  web_group="$(detect_web_group || true)"
  if [ -n "$web_group" ]; then
    chgrp -R "$web_group" data
    find data -type d -exec chmod 2775 {} +
    if [ -d /DATA/models/yolo/registry ]; then
      chgrp -R "$web_group" /DATA/models/yolo/registry 2>/dev/null || true
      find /DATA/models/yolo/registry -type d -exec chmod 2775 {} + 2>/dev/null || true
      find /DATA/models/yolo/registry -type f -exec chmod u+rw,g+rw,o+r {} + 2>/dev/null || true
      if command -v setfacl >/dev/null 2>&1; then
        setfacl -R -m "g:${web_group}:rwx" -m "d:g:${web_group}:rwx" /DATA/models/yolo/registry 2>/dev/null || true
      fi
    fi
    echo "[3waAIHub] Runtime group: $web_group"
  fi
else
  echo "[3waAIHub] Non-root mode: skipped chown/chgrp."
  if getent group www-data >/dev/null; then
    echo "[3waAIHub] For Apache/PHP-FPM writes, run: sudo WEB_GROUP=www-data ./scripts/fix_permissions.sh"
    echo "[3waAIHub] YOLO registry writes need: /DATA/models/yolo/registry writable by www-data."
  fi
fi

echo "[3waAIHub] Permissions fixed without chmod 777."
