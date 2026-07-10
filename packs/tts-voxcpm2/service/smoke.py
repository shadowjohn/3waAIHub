from __future__ import annotations

import importlib.util

import fastapi
import pydantic


def main() -> None:
    assert fastapi is not None
    assert pydantic is not None
    assert importlib.util.find_spec("soundfile") is not None
    assert importlib.util.find_spec("voxcpm") is not None
    print({
        "ok": True,
        "service": "tts-voxcpm2",
        "voxcpm_available": importlib.util.find_spec("voxcpm") is not None,
    })


if __name__ == "__main__":
    main()
