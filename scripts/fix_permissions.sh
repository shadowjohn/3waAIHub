#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

SOURCE_ONLY=0
if [ "${1:-}" = "--source-only" ]; then
  SOURCE_ONLY=1
elif [ "$#" -gt 0 ]; then
  echo "Usage: $0 [--source-only]" >&2
  exit 2
fi

APP_USER="${APP_USER:-}"
APP_GROUP="${APP_GROUP:-}"
WEB_GROUP="${WEB_GROUP:-}"
WEB_USER="${WEB_USER:-}"
FACEBOOK_PROFILE_PARENT="data/facebook-crawler"
FACEBOOK_PROFILE_ROOT="data/facebook-crawler/profiles"

if [ "$(id -u)" = "0" ] && [ -z "$APP_USER" ] && [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
  APP_USER="$SUDO_USER"
fi

detect_web_group() {
  if [ -n "$WEB_GROUP" ]; then
    if getent group "$WEB_GROUP" >/dev/null; then
      echo "$WEB_GROUP"
      return 0
    fi
    return 1
  fi

  for candidate in www-data apache nginx http; do
    if getent group "$candidate" >/dev/null; then
      echo "$candidate"
      return 0
    fi
  done
}

validate_runtime_account() {
  local kind="$1"
  local value="$2"
  [ -z "$value" ] && return 0
  if [ "$kind" = "user" ]; then
    getent passwd "$value" >/dev/null
  else
    getent group "$value" >/dev/null
  fi
}

detect_web_user() {
  local web_group="$1"
  if [ -n "$WEB_USER" ]; then
    if getent passwd "$WEB_USER" >/dev/null && [ "$(id -u "$WEB_USER")" != "0" ]; then
      echo "$WEB_USER"
      return 0
    fi
    return 1
  fi

  if [ -n "$web_group" ] && getent passwd "$web_group" >/dev/null && [ "$(id -u "$web_group")" != "0" ]; then
    echo "$web_group"
    return 0
  fi
  return 1
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

if [ "$SOURCE_ONLY" = "1" ]; then
  echo "[3waAIHub] Web-source permissions fixed."
  exit 0
fi

if [ -L "$FACEBOOK_PROFILE_PARENT" ] || [ -L "$FACEBOOK_PROFILE_ROOT" ] \
  || { [ -e "$FACEBOOK_PROFILE_PARENT" ] && [ ! -d "$FACEBOOK_PROFILE_PARENT" ]; } \
  || { [ -e "$FACEBOOK_PROFILE_ROOT" ] && [ ! -d "$FACEBOOK_PROFILE_ROOT" ]; }; then
  echo "[3waAIHub] ERROR: Facebook profile storage path is not a private directory." >&2
  exit 1
fi

web_group="$(detect_web_group || true)"
web_user=""
private_group=""
if [ "$(id -u)" = "0" ]; then
  web_user="$(detect_web_user "$web_group" || true)"
  if [ -z "$web_user" ]; then
    echo "[3waAIHub] ERROR: Cannot determine a usable web runtime owner. Set WEB_USER." >&2
    exit 1
  fi
  if [ -n "$WEB_GROUP" ]; then
    private_group="$WEB_GROUP"
  elif [ -n "$WEB_USER" ]; then
    private_group="$(id -gn "$web_user")"
  else
    private_group="$web_group"
  fi
  if [ -z "$private_group" ] || ! getent group "$private_group" >/dev/null; then
    echo "[3waAIHub] ERROR: Cannot determine a usable web runtime group." >&2
    exit 1
  fi
  if ! validate_runtime_account user "$APP_USER" || ! validate_runtime_account group "$APP_GROUP"; then
    echo "[3waAIHub] ERROR: APP_USER or APP_GROUP is not a local account." >&2
    exit 1
  fi
else
  web_user="$(id -un)"
  private_group="$(id -gn)"
fi

mkdir -p "$FACEBOOK_PROFILE_ROOT"
if [ -L "$FACEBOOK_PROFILE_PARENT" ] || [ -L "$FACEBOOK_PROFILE_ROOT" ]; then
  echo "[3waAIHub] ERROR: Facebook profile storage path became a symlink." >&2
  exit 1
fi
data_real="$(realpath data)"
parent_real="$(realpath "$FACEBOOK_PROFILE_PARENT")"
root_real="$(realpath "$FACEBOOK_PROFILE_ROOT")"
if [ "$(dirname "$parent_real")" != "$data_real" ] || [ "$(dirname "$root_real")" != "$parent_real" ]; then
  echo "[3waAIHub] ERROR: Facebook profile storage escaped the Hub data root." >&2
  exit 1
fi
(
  cd -P -- "$FACEBOOK_PROFILE_ROOT"
  if find . -type l -print -quit | grep -q . \
    || find . ! -type d ! -type f -print -quit | grep -q .; then
    echo "[3waAIHub] ERROR: Facebook profile storage contains an unsupported entry." >&2
    exit 1
  fi
  if find . -type f ! -links 1 -print -quit | grep -q .; then
    echo "[3waAIHub] ERROR: Facebook profile storage contains a multiply linked file." >&2
    exit 1
  fi
  if find . -type f \( ! -user "$web_user" -o ! -group "$private_group" -o ! -perm 0600 \) -print -quit | grep -q .; then
    echo "[3waAIHub] ERROR: Existing Facebook profile state requires manual ownership repair." >&2
    exit 1
  fi
  if [ "$(id -u)" = "0" ]; then
    find . -type d -exec chown -- "$web_user:$private_group" {} +
  fi
  find . -type d -exec chmod 0700 {} +
)

for dir in /DATA/models /DATA/models/paddleocr /DATA/models/yolo /DATA/models/yolo/registry /DATA/models/ollama /DATA/models/sam3 /DATA/models/birefnet; do
  mkdir -p "$dir" 2>/dev/null || true
  chmod u+rwx,g+rwx,o+rx "$dir" 2>/dev/null || true
done

if [ "$(id -u)" = "0" ]; then
  find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -type d -exec chmod u+rwx,g+rwx,o+rx {} +
else
  find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -type d ! -perm -2000 -exec chmod u+rwx,g+rwx,o+rx {} +
fi
find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -type f -exec chmod u+rw,g+rw,o+r {} +

if [ "$(id -u)" = "0" ]; then
  if [ -n "$APP_USER" ] || [ -n "$APP_GROUP" ]; then
    owner="${APP_USER:-}"
    group="${APP_GROUP:-}"
    find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -exec chown -- "${owner}${group:+:$group}" {} +
  fi

  if [ -n "$web_group" ]; then
    find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -exec chgrp -- "$web_group" {} +
    find data -path "$FACEBOOK_PROFILE_PARENT" -prune -o -type d -exec chmod 2775 {} +
    if [ -d /DATA/models/yolo/registry ]; then
      chgrp -R -- "$web_group" /DATA/models/yolo/registry 2>/dev/null || true
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
