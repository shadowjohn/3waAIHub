#!/bin/sh
set -eu

if [ "$#" -ne 3 ]; then
    echo 'usage: provision_models.sh MODEL_DIR MODEL_ID REVISION' >&2
    exit 64
fi

exec python3 /app/provision.py --model-dir "$1" --model-id "$2" --revision "$3"
