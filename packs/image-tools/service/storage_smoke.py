from __future__ import annotations

import json
import os
import tempfile
from pathlib import Path

from model_runtime import DEFAULT_MODEL_ROOT


def main() -> int:
    models = Path(os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT)))
    try:
        with tempfile.NamedTemporaryFile(prefix=".image-tools-", delete=True) as handle:
            handle.write(b"ok")
        ok = models.is_dir() and os.access(models, os.R_OK)
    except OSError:
        ok = False
    print(json.dumps({"ok": ok, "models_readable": models.is_dir() and os.access(models, os.R_OK)}, sort_keys=True))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
