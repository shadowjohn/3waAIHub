from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

from image_contract import select_model
from model_runtime import DEFAULT_MODEL_ROOT, MODEL_SMOKE_FAMILIES, ModelRuntimeError, build_upsampler, verify_ready


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--backend", choices=["cpu", "cuda"], default="cpu")
    parser.add_argument("--model-dir", default=os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT)))
    args = parser.parse_args(argv)
    try:
        model_root = Path(args.model_dir)
        marker = verify_ready(model_root)
        families = []
        for family_id, canonical_alias, aliases in MODEL_SMOKE_FAMILIES:
            build_upsampler(canonical_alias, args.backend, model_root / select_model(canonical_alias).filename)
            families.append({"id": family_id, "aliases": list(aliases)})
        print(json.dumps({"ok": True, "backend": args.backend, "commit": marker["commit"], "families": families}, separators=(",", ":"), sort_keys=True))
        return 0
    except ModelRuntimeError as exc:
        print(json.dumps({"ok": False, "error": exc.code}, separators=(",", ":")))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
