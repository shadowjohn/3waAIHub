from __future__ import annotations

import hashlib
import json
import os
import re
import shutil
import tempfile
import urllib.parse
import urllib.request
import uuid
from pathlib import Path
from typing import Callable

from model_runtime import DEFAULT_MODEL_ROOT, MODEL_URLS, REAL_ESRGAN_COMMIT, REAL_ESRGAN_REPOSITORY


ASSETS = MODEL_URLS


class ProvisionError(RuntimeError):
    pass


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


class _HttpsRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed = urllib.parse.urlsplit(newurl)
        if parsed.scheme != "https" or parsed.hostname not in {"github.com", "objects.githubusercontent.com", "release-assets.githubusercontent.com"}:
            raise ProvisionError("unexpected redirect")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _download(url: str, destination: Path) -> None:
    parsed = urllib.parse.urlsplit(url)
    if url not in ASSETS.values() or parsed.scheme != "https" or parsed.hostname != "github.com" or Path(parsed.path).name not in ASSETS:
        raise ProvisionError("unexpected asset")
    opener = urllib.request.build_opener(_HttpsRedirect())
    try:
        with opener.open(url, timeout=60) as response, destination.open("xb") as output:
            final = urllib.parse.urlsplit(response.geturl())
            name = re.search(r'filename="?([^";]+)', response.headers.get("Content-Disposition", ""))
            if final.scheme != "https" or final.hostname not in {"github.com", "objects.githubusercontent.com", "release-assets.githubusercontent.com"} or name is None or name.group(1) != destination.name:
                raise ProvisionError("unexpected redirect")
            shutil.copyfileobj(response, output)
            output.flush()
            os.fsync(output.fileno())
    except ProvisionError:
        raise
    except OSError as exc:
        raise ProvisionError("download_failed") from exc


def _write_marker(stage: Path, files: list[dict[str, object]]) -> dict[str, object]:
    payload: dict[str, object] = {"repository": REAL_ESRGAN_REPOSITORY, "commit": REAL_ESRGAN_COMMIT, "files": files}
    marker = stage / "ready.json"
    with marker.open("x", encoding="utf-8") as handle:
        json.dump(payload, handle, sort_keys=True, separators=(",", ":"))
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())
    marker.chmod(0o644)
    return payload


def _fsync_dir(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def _activate(stage: Path, target: Path) -> None:
    backup = target.with_name(target.name + ".previous-" + uuid.uuid4().hex)
    moved = False
    try:
        if target.exists() or target.is_symlink():
            if target.is_symlink() or not target.is_dir():
                raise ProvisionError("invalid existing snapshot")
            os.replace(target, backup)
            moved = True
        os.replace(stage, target)
        _fsync_dir(target.parent)
    except Exception:
        if moved and not target.exists() and backup.exists():
            os.replace(backup, target)
        raise
    else:
        if moved:
            shutil.rmtree(backup)


def provision(*, model_root: Path = DEFAULT_MODEL_ROOT, fetcher: Callable[[str, Path], None] | None = None) -> dict[str, object]:
    target = Path(model_root)
    parent = target.parent
    parent.mkdir(parents=True, exist_ok=True)
    if parent.is_symlink():
        raise ProvisionError("invalid model parent")
    stage = Path(tempfile.mkdtemp(prefix=target.name + ".stage-", dir=parent))
    stage.chmod(0o755)
    fetch = fetcher or _download
    try:
        files: list[dict[str, object]] = []
        for name, url in ASSETS.items():
            destination = stage / name
            fetch(url, destination)
            if destination.is_symlink() or not destination.is_file() or destination.name != name or destination.stat().st_size < 1:
                raise ProvisionError("invalid downloaded asset")
            destination.chmod(0o644)
            files.append({"path": name, "size": destination.stat().st_size, "sha256": _sha256(destination), "url": url})
        payload = _write_marker(stage, files)
        _fsync_dir(stage)
        _activate(stage, target)
        return payload
    except Exception as exc:
        shutil.rmtree(stage, ignore_errors=True)
        if isinstance(exc, ProvisionError):
            raise
        raise ProvisionError("provision_failed") from exc


def main() -> None:
    payload = provision(model_root=Path(os.getenv("IMAGE_TOOLS_MODEL_DIR", str(DEFAULT_MODEL_ROOT))))
    print(json.dumps({"ok": True, "files": len(payload["files"]), "commit": payload["commit"]}, sort_keys=True))


if __name__ == "__main__":
    main()
