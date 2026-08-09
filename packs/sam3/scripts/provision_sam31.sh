#!/usr/bin/env bash
set -euo pipefail

: "${HF_TOKEN:?HF_TOKEN must be supplied by the invoking environment}"

model_root="${AIHUB_MODELS_DIR:-/DATA/models}/sam3"
image="${SAM3_PROVISION_IMAGE:-3waaihub/sam3:0.2.0}"
checkpoint="sam3.1_multiplex.pt"
manifest="sam3.1-manifest.json"
upstream_commit="96914d2425f90a64f45ca977c2b5165418099543"

mkdir -p "$model_root"
stage=$(mktemp -d "$model_root/.sam31-provision.XXXXXX")
cleanup() {
    rm -rf "$stage"
}
trap cleanup EXIT

docker run --rm \
    -e HF_TOKEN \
    -e HF_HUB_DISABLE_TELEMETRY=1 \
    -v "$stage:/output" \
    "$image" \
    python3 -c '
import hashlib, json, os
from pathlib import Path
from huggingface_hub import hf_hub_download

out = Path("/output")
name = "sam3.1_multiplex.pt"
checkpoint = hf_hub_download("facebook/sam3.1", filename=name, token=os.environ["HF_TOKEN"])
target = out / name
target.write_bytes(Path(checkpoint).read_bytes())
digest = hashlib.sha256(target.read_bytes()).hexdigest()
(out / "sam3.1-manifest.json").write_text(json.dumps({
    "upstream_commit": "96914d2425f90a64f45ca977c2b5165418099543",
    "repository": "facebook/sam3.1",
    "files": {name: digest},
}, sort_keys=True) + "\n", encoding="utf-8")
'

test -s "$stage/$checkpoint"
test -s "$stage/$manifest"
mv -f "$stage/$checkpoint" "$model_root/$checkpoint"
mv -f "$stage/$manifest" "$model_root/$manifest"
