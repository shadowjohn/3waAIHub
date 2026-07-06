from __future__ import annotations

import inspect
import json
import os
from pathlib import Path
from typing import Any


DEFAULTS = {
    "OCR_MODEL_DIR": "/models/paddleocr",
    "OCR_CACHE_DIR": "/cache/paddleocr",
    "OCR_SERVICE_DATA_DIR": "/data/service",
}
FORBIDDEN_ROOTS = [Path("/root"), Path("/app")]


def configure_env() -> dict[str, str]:
    for key, value in DEFAULTS.items():
        os.environ.setdefault(key, value)

    model_dir = os.environ["OCR_MODEL_DIR"]
    cache_dir = os.environ["OCR_CACHE_DIR"]
    env = {
        "PADDLEOCR_HOME": model_dir,
        "XDG_CACHE_HOME": f"{cache_dir}/xdg",
        "HOME": f"{cache_dir}/home",
    }
    os.environ.update(env)

    for path in [
        model_dir,
        cache_dir,
        os.environ["OCR_SERVICE_DATA_DIR"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)

    return {
        "model_dir": model_dir,
        "cache_dir": cache_dir,
        "service_data_dir": os.environ["OCR_SERVICE_DATA_DIR"],
        **env,
    }


def snapshot(root: Path) -> set[str]:
    if not root.exists():
        return set()

    found = set()
    for path in root.rglob("*"):
        try:
            found.add(str(path.relative_to(root)))
        except ValueError:
            continue
    return found


def diff_snapshot(root: Path, before: set[str]) -> list[str]:
    return sorted(snapshot(root) - before)[:200]


def init_kwargs(cls: type[Any]) -> dict[str, Any]:
    params = inspect.signature(cls).parameters
    kwargs: dict[str, Any] = {}
    if "lang" in params:
        kwargs["lang"] = os.getenv("OCR_LANG", "ch")
    for key in [
        "use_doc_orientation_classify",
        "use_doc_unwarping",
        "use_textline_orientation",
        "use_angle_cls",
        "use_gpu",
    ]:
        if key in params:
            kwargs[key] = False
    return kwargs


def main() -> None:
    env = configure_env()
    allowed_roots = {
        "models": Path(env["model_dir"]),
        "cache": Path(env["cache_dir"]),
        "service_data": Path(env["service_data_dir"]),
    }
    before_allowed = {name: snapshot(path) for name, path in allowed_roots.items()}
    before_forbidden = {str(path): snapshot(path) for path in FORBIDDEN_ROOTS}

    from paddleocr import PaddleOCR

    kwargs = init_kwargs(PaddleOCR)
    PaddleOCR(**kwargs)

    allowed_changed = {
        name: diff_snapshot(path, before_allowed[name])
        for name, path in allowed_roots.items()
    }
    forbidden_changed = {
        str(path): diff_snapshot(path, before_forbidden[str(path)])
        for path in FORBIDDEN_ROOTS
    }
    suspicious = {path: items for path, items in forbidden_changed.items() if items}

    result = {
        "ok": not suspicious,
        "runtime_level": "L4b-real-inference",
        "init_kwargs": kwargs,
        "env": env,
        "allowed_changed": allowed_changed,
        "forbidden_changed": forbidden_changed,
        "suspicious": suspicious,
    }
    print(json.dumps(result, ensure_ascii=False))
    if suspicious:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
