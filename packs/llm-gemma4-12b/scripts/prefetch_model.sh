#!/usr/bin/env bash
set -euo pipefail

MODEL="${VLLM_MODEL:-google/gemma-4-12B-it-qat-w4a16-ct}"
python3 - <<'PY' "$MODEL"
import sys
from huggingface_hub import snapshot_download

snapshot_download(sys.argv[1])
print("prefetch ok:", sys.argv[1])
PY
