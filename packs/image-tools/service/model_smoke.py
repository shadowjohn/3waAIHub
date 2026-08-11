from __future__ import annotations

import json
import os
from pathlib import Path

from model_runtime import DEFAULT_MODEL_ROOT, REAL_ESRGAN_COMMIT, verify_ready


def main() -> int:
    marker = verify_ready(Path(os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT))))
    print(json.dumps({"ok": True, "commit": marker["commit"], "commit_matches": marker["commit"] == REAL_ESRGAN_COMMIT}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
