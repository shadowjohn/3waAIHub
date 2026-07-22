from __future__ import annotations

import json

from model_runtime import MODEL_REVISION, load_model, verify_ready


def main() -> None:
    ready = verify_ready()
    model, device = load_model()
    print(json.dumps({
        "ok": True,
        "runtime_level": "L4a-model-init-smoke",
        "model": model.__class__.__name__,
        "device": device,
        "revision": ready["revision"],
        "revision_matches": ready["revision"] == MODEL_REVISION,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
