import argparse
import hashlib
import json
import os
from pathlib import Path
from tempfile import NamedTemporaryFile

from model import MANIFEST, MODEL_ID, REVISION, WEIGHTS, check_snapshot


def provision(output: Path) -> None:
    from huggingface_hub import snapshot_download

    snapshot_download(
        repo_id=MODEL_ID,
        revision=REVISION,
        token=False,
        local_dir=output,
        max_workers=3,
        allow_patterns=[
            "LICENSE.md", "model_index.json", "scheduler/*.json", "tokenizer/*",
            "text_encoder/*.json", "transformer/*.json", "vae/*.json", *WEIGHTS,
        ],
    )
    check_snapshot(output, require_manifest=False)
    for name, (_, expected) in WEIGHTS.items():
        print(f"Verifying {name}", flush=True)
        with (output / name).open("rb") as file:
            if hashlib.file_digest(file, "sha256").hexdigest() != expected:
                raise ValueError(f"Weight SHA-256 differs: {name}")
    temporary = None
    try:
        with NamedTemporaryFile(mode="w", encoding="utf-8", dir=output, prefix=".flux-", delete=False) as file:
            temporary = Path(file.name)
            json.dump({"model": MODEL_ID, "revision": REVISION}, file)
            file.write("\n")
        os.replace(temporary, output / MANIFEST)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Prepare the pinned public FLUX.2 klein 4B Diffusers snapshot")
    parser.add_argument("output", type=Path, help="Pack model storage directory")
    provision(parser.parse_args().output)
