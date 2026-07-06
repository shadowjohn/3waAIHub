from __future__ import annotations

import json
import os
from pathlib import Path


DEFAULTS = {
    "YOLO_MODEL_DIR": "/models/yolo",
    "YOLO_CACHE_DIR": "/cache/yolo",
    "YOLO_SERVICE_DATA_DIR": "/data/service",
    "YOLO_MODEL": "yolo11n.pt",
}
FORBIDDEN_ROOTS = [Path("/root"), Path("/app")]


def configure_env() -> dict[str, str]:
    for key, value in DEFAULTS.items():
        os.environ.setdefault(key, value)

    model_dir = os.environ["YOLO_MODEL_DIR"]
    cache_dir = os.environ["YOLO_CACHE_DIR"]
    env = {
        "XDG_CACHE_HOME": os.getenv("XDG_CACHE_HOME", f"{cache_dir}/xdg"),
        "HOME": os.getenv("HOME", f"{cache_dir}/home"),
        "ULTRALYTICS_SETTINGS_DIR": os.getenv("ULTRALYTICS_SETTINGS_DIR", f"{cache_dir}/ultralytics"),
        "YOLO_CONFIG_DIR": os.getenv("YOLO_CONFIG_DIR", f"{cache_dir}/ultralytics"),
    }
    os.environ.update(env)

    for path in [
        model_dir,
        cache_dir,
        os.environ["YOLO_SERVICE_DATA_DIR"],
        env["XDG_CACHE_HOME"],
        env["HOME"],
        env["ULTRALYTICS_SETTINGS_DIR"],
    ]:
        Path(path).mkdir(parents=True, exist_ok=True)

    return {
        "model": os.environ["YOLO_MODEL"],
        "model_dir": model_dir,
        "cache_dir": cache_dir,
        "service_data_dir": os.environ["YOLO_SERVICE_DATA_DIR"],
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


def main() -> None:
    env = configure_env()
    model_dir = Path(env["model_dir"])
    cache_dir = Path(env["cache_dir"])
    before_allowed = {
        "models": snapshot(model_dir),
        "cache": snapshot(cache_dir),
    }
    before_forbidden = {str(path): snapshot(path) for path in FORBIDDEN_ROOTS}

    from ultralytics import YOLO

    model_path = Path(env["model"])
    old_cwd = Path.cwd()
    try:
        os.chdir(model_dir if not model_path.is_absolute() else model_path.parent)
        YOLO(str(model_path if model_path.is_absolute() else env["model"]))
    finally:
        os.chdir(old_cwd)

    allowed_changed = {
        "models": diff_snapshot(model_dir, before_allowed["models"]),
        "cache": diff_snapshot(cache_dir, before_allowed["cache"]),
    }
    forbidden_changed = {
        str(path): diff_snapshot(path, before_forbidden[str(path)])
        for path in FORBIDDEN_ROOTS
    }
    suspicious = {path: items for path, items in forbidden_changed.items() if items}

    result = {
        "ok": not suspicious,
        "runtime_level": "L4a-model-init-smoke",
        "model": env["model"],
        "model_dir": env["model_dir"],
        "cache_dir": env["cache_dir"],
        "allowed_changed": allowed_changed,
        "forbidden_changed": forbidden_changed,
        "suspicious": suspicious,
    }
    print(json.dumps(result, ensure_ascii=False))
    if suspicious:
        raise SystemExit(1)


if __name__ == "__main__":
    main()
