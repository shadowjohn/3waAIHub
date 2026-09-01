#!/bin/sh
set -eu

if [ "$#" -ne 4 ]; then
    echo 'usage: voice_generate.sh WORKSPACE INPUT OUTPUT RUNNER_CONFIG' >&2
    exit 64
fi

exec python3 /app/job.py "$1" "$2" "$3" "$4"
