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

for dir in data data/logs data/logs/jobs data/jobs data/results data/cache; do
  mkdir -p "$dir"
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
    echo "[3waAIHub] Runtime group: $web_group"
  fi
else
  echo "[3waAIHub] Non-root mode: skipped chown/chgrp."
  if getent group www-data >/dev/null; then
    echo "[3waAIHub] For Apache/PHP-FPM writes, run: sudo WEB_GROUP=www-data ./scripts/fix_permissions.sh"
  fi
fi

echo "[3waAIHub] Permissions fixed without chmod 777."
