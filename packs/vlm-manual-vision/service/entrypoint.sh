#!/bin/sh
set -eu

cache_dir=${MANUAL_VISION_CACHE_DIR:-/cache/manual-vision}
data_dir=${MANUAL_VISION_SERVICE_DATA_DIR:-/data/service}
model_dir=${MANUAL_VISION_MODEL_DIR:-/models/manual-vision}

for directory in "$cache_dir" "$data_dir"; do
    [ ! -L "$directory" ] || { echo "writable mount is a symlink: $directory" >&2; exit 1; }
    mkdir -p "$directory"
    chown 10001:10001 "$directory"
    chmod u+rwx "$directory"
done

[ ! -L "$model_dir" ] || { echo "model mount is a symlink" >&2; exit 1; }
[ ! -e "$model_dir" ] || [ ! -w "$model_dir" ] || { echo "model mount must be read-only" >&2; exit 1; }

exec setpriv --reuid=10001 --regid=10001 --clear-groups \
    --bounding-set=-all --ambient-caps=-all -- "$@"
