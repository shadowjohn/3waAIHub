from __future__ import annotations

import importlib
import json


def main() -> None:
    modules = {name: importlib.import_module(name) for name in [
        "fastapi", "PIL", "numpy", "torch", "torchvision", "transformers", "timm", "kornia", "einops",
    ]}
    print(json.dumps({
        "ok": True,
        "runtime_level": "L2-deps-import",
        "versions": {name: getattr(module, "__version__", "unknown") for name, module in modules.items()},
        "cuda_available": bool(modules["torch"].cuda.is_available()),
    }, sort_keys=True))


if __name__ == "__main__":
    main()
