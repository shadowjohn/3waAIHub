from __future__ import annotations

import hashlib
import json
import os
import shutil
import tempfile
import urllib.parse
import urllib.request
import uuid
from pathlib import Path

from colorize_runtime import DDCOLOR_MODEL_ASSET, DEFAULT_COLORIZE_MODEL_ROOT, ready_marker


MAX_ASSET_BYTES = 1024 * 1024 * 1024
_ALLOWED_HOSTS = {"huggingface.co", "cdn-lfs.huggingface.co", "cas-bridge.xethub.hf.co", "us.aws.cdn.hf.co"}


class ProvisionError(RuntimeError):
    pass


class _HttpsRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        parsed = urllib.parse.urlsplit(newurl)
        if parsed.scheme != "https" or parsed.hostname not in _ALLOWED_HOSTS:
            raise ProvisionError("unexpected_redirect")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _download(destination: Path) -> None:
    asset = DDCOLOR_MODEL_ASSET
    opener = urllib.request.build_opener(_HttpsRedirect())
    try:
        with opener.open(str(asset["url"]), timeout=60) as response:
            final = urllib.parse.urlsplit(response.geturl())
            if final.scheme != "https" or final.hostname not in _ALLOWED_HOSTS:
                raise ProvisionError("unexpected_redirect")
            total = 0
            digest = hashlib.sha256()
            with destination.open("xb") as output:
                while block := response.read(1024 * 1024):
                    total += len(block)
                    if total > MAX_ASSET_BYTES or total > int(asset["size"]):
                        raise ProvisionError("asset_size_mismatch")
                    digest.update(block)
                    output.write(block)
                output.flush()
                os.fsync(output.fileno())
        if total != asset["size"] or digest.hexdigest() != asset["sha256"]:
            raise ProvisionError("asset_hash_mismatch")
    except ProvisionError:
        destination.unlink(missing_ok=True)
        raise
    except OSError as exc:
        destination.unlink(missing_ok=True)
        raise ProvisionError("download_failed") from exc


def _fsync_dir(path: Path) -> None:
    descriptor = os.open(path, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def provision(model_root: Path = DEFAULT_COLORIZE_MODEL_ROOT) -> dict[str, object]:
    target = Path(model_root)
    parent = target.parent
    parent.mkdir(parents=True, exist_ok=True)
    if parent.is_symlink():
        raise ProvisionError("invalid_model_parent")
    stage = Path(tempfile.mkdtemp(prefix=target.name + ".stage-", dir=parent))
    stage.chmod(0o755)
    backup = target.with_name(target.name + ".previous-" + uuid.uuid4().hex)
    moved = False
    try:
        asset_path = stage / str(DDCOLOR_MODEL_ASSET["path"])
        _download(asset_path)
        asset_path.chmod(0o644)
        marker = ready_marker()
        marker_path = stage / "ready.json"
        marker_path.write_text(json.dumps(marker, sort_keys=True, separators=(",", ":")) + "\n", encoding="utf-8")
        marker_path.chmod(0o644)
        _fsync_dir(stage)
        if target.exists() or target.is_symlink():
            if target.is_symlink() or not target.is_dir():
                raise ProvisionError("invalid_existing_snapshot")
            os.replace(target, backup)
            moved = True
        os.replace(stage, target)
        _fsync_dir(parent)
        if moved:
            shutil.rmtree(backup)
        return marker
    except Exception as exc:
        shutil.rmtree(stage, ignore_errors=True)
        if moved and not target.exists() and backup.exists():
            os.replace(backup, target)
        if isinstance(exc, ProvisionError):
            raise
        raise ProvisionError("provision_failed") from exc


def main() -> None:
    marker = provision(Path(os.getenv("IMAGE_TOOLS_COLORIZE_MODEL_DIR", str(DEFAULT_COLORIZE_MODEL_ROOT))))
    print(json.dumps({"ok": True, "model": "ddcolor-modelscope", "commit": marker["commit"]}, sort_keys=True))


if __name__ == "__main__":
    main()
