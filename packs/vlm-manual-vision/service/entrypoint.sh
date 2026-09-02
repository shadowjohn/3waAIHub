#!/bin/sh
set -eu

cache_dir=/cache/manual-vision
data_dir=/data/service
model_dir=/models/manual-vision

for directory in "$cache_dir" "$data_dir"; do
    [ ! -L "$directory" ] || { echo "writable mount is a symlink: $directory" >&2; exit 1; }
    mkdir -p "$directory"
    chown 10001:10001 "$directory"
    chmod u+rwx "$directory"
done

[ ! -L "$model_dir" ] || { echo "model mount is a symlink" >&2; exit 1; }

exec setpriv --reuid=10001 --regid=10001 --clear-groups \
    --bounding-set=-all --ambient-caps=-all -- "$@"
