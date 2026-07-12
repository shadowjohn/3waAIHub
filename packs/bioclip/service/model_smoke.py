from __future__ import annotations

import json

from app import bioclip_model, runtime_level


def main() -> None:
    model, _preprocess, _tokenizer, device = bioclip_model()
    print(json.dumps({
        "ok": model is not None,
        "runtime_level": runtime_level(),
        "model": getattr(model, "visual", None).__class__.__name__ if model is not None else None,
        "device": device,
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
