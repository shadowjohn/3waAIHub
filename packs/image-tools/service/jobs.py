from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import warnings
from pathlib import Path
from typing import Callable

from PIL import Image, UnidentifiedImageError

from image_contract import MAX_AXIS, MAX_OUTPUT_PIXELS, ImageToolsError, build_upscale_argv, decode_image, private_job_directory, select_model, validate_output_pixels
from model_runtime import DEFAULT_MODEL_ROOT


MAX_REPORT_BYTES = 64 * 1024
MAX_OUTPUT_BYTES = 64 * 1024 * 1024
_CLI_ERRORS = frozenset({"backend_unavailable", "model_not_present", "model_load_failed", "inference_failed"})


class JobError(RuntimeError):
    pass


def _private_file(path: Path) -> Path:
    try:
        resolved = path.resolve(strict=True)
        stat = resolved.stat()
    except OSError as exc:
        raise JobError("invalid_request") from exc
    if path.is_symlink() or not resolved.is_file() or stat.st_nlink != 1:
        raise JobError("invalid_request")
    return resolved


def _read_request(path: Path, backend: str) -> tuple[str, str]:
    request_path = _private_file(path)
    if request_path.name != "request.json" or request_path.stat().st_size > MAX_REPORT_BYTES:
        raise JobError("invalid_request")
    try:
        request = json.loads(request_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise JobError("invalid_request") from exc
    if not isinstance(request, dict) or set(request) != {"model", "backend"}:
        raise JobError("invalid_request")
    model = request.get("model")
    stored_backend = request.get("backend")
    if not isinstance(model, str) or not isinstance(stored_backend, str) or backend not in {"cpu", "cuda"} or stored_backend != backend:
        raise JobError("invalid_request")
    try:
        select_model(model)
    except ImageToolsError as exc:
        raise JobError(exc.code) from exc
    return model, stored_backend


def _source(path: Path) -> tuple[bytes, Image.Image]:
    source = _private_file(path)
    if source.name != "source":
        raise JobError("invalid_request")
    try:
        payload = source.read_bytes()
        return payload, decode_image(payload, operation="upscale_task")
    except (OSError, ImageToolsError) as exc:
        raise JobError(getattr(exc, "code", "invalid_image")) from exc


def _invoke(argv: list[str]) -> str:
    try:
        result = subprocess.run(argv, shell=False, check=False, capture_output=True, text=True, timeout=900)
    except (OSError, subprocess.TimeoutExpired) as exc:
        raise JobError("inference_failed") from exc
    if result.returncode == 0:
        return result.stdout
    try:
        error = json.loads(result.stdout)
        code = error.get("error") if isinstance(error, dict) and set(error) == {"error"} else None
    except (TypeError, ValueError, json.JSONDecodeError):
        code = None
    raise JobError(code if isinstance(code, str) and code in _CLI_ERRORS else "inference_failed")


def _validated_output(path: Path, *, source: Image.Image, model: str, backend: str, stdout: str) -> tuple[bytes, dict[str, object]]:
    try:
        report = json.loads(stdout)
        if not isinstance(report, dict) or set(report) != {"model", "backend", "width", "height", "elapsed_ms"}:
            raise ValueError
        if report["model"] != model or report["backend"] != backend or any(not isinstance(report[key], int) or isinstance(report[key], bool) or report[key] < 1 for key in ("width", "height", "elapsed_ms")):
            raise ValueError
        payload = path.read_bytes()
        if not payload or len(payload) > MAX_OUTPUT_BYTES:
            raise ValueError
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(path) as probe:
                if probe.format != "PNG":
                    raise ValueError
                probe.verify()
            with Image.open(path) as rendered:
                rendered.load()
                width, height = rendered.size
        selection = select_model(model)
        expected = (source.width * selection.scale, source.height * selection.scale)
        if (width, height) != expected or (report["width"], report["height"]) != expected or width > MAX_AXIS or height > MAX_AXIS or width * height > MAX_OUTPUT_PIXELS:
            raise ValueError
    except (OSError, ValueError, TypeError, json.JSONDecodeError, UnidentifiedImageError, Image.DecompressionBombError, Image.DecompressionBombWarning) as exc:
        raise JobError("inference_failed") from exc
    return payload, report


def _atomic_write(output_dir: Path, name: str, payload: bytes) -> None:
    descriptor, temporary = tempfile.mkstemp(prefix=".image-tools-", dir=output_dir)
    temporary_path = Path(temporary)
    try:
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary_path, 0o600)
        os.replace(temporary_path, output_dir / name)
    finally:
        temporary_path.unlink(missing_ok=True)


def _output_dir(path: Path, source_path: Path, request_path: Path) -> Path:
    try:
        output = path.resolve(strict=True)
        source = source_path.resolve(strict=True)
        request = request_path.resolve(strict=True)
        workspace = source.parent.parent
        nonempty = any(output.iterdir())
    except OSError as exc:
        raise JobError("invalid_request") from exc
    if path.is_symlink() or not output.is_dir() or source.parent != request.parent or output != workspace / "output" or nonempty:
        raise JobError("invalid_request")
    return output


def run_job(*, request_path: Path, source_path: Path, output_dir: Path, backend: str, model_dir: Path = DEFAULT_MODEL_ROOT, invoke: Callable[[list[str]], str] = _invoke) -> None:
    output = _output_dir(output_dir, source_path, request_path)
    model, stored_backend = _read_request(request_path, backend)
    source_bytes, source = _source(source_path)
    validate_output_pixels(source.width * source.height, select_model(model).scale)
    published = [output / "upscaled_image.png", output / "upscale_report.json"]
    try:
        with private_job_directory() as workspace:
            staged_source = workspace / "source"
            staged_output = workspace / "output.png"
            staged_source.write_bytes(source_bytes)
            staged_source.chmod(0o600)
            argv = build_upscale_argv(workspace=workspace, source=staged_source, output=staged_output, model=model, backend=stored_backend, model_dir=model_dir)
            if stored_backend == "cpu":
                argv.append("--fp32")
            stdout = invoke(argv)
            if sorted(path.name for path in workspace.iterdir()) != ["output.png", "source"]:
                raise JobError("inference_failed")
            output_bytes, cli_report = _validated_output(_private_file(staged_output), source=source, model=model, backend=stored_backend, stdout=stdout)
        report = {
            "model": model,
            "backend": stored_backend,
            "source_width": source.width,
            "source_height": source.height,
            "width": cli_report["width"],
            "height": cli_report["height"],
            "elapsed_ms": cli_report["elapsed_ms"],
            "output_sha256": hashlib.sha256(output_bytes).hexdigest(),
        }
        report_bytes = (json.dumps(report, separators=(",", ":"), sort_keys=True) + "\n").encode("utf-8")
        if len(report_bytes) > MAX_REPORT_BYTES:
            raise JobError("inference_failed")
        _atomic_write(output, "upscaled_image.png", output_bytes)
        _atomic_write(output, "upscale_report.json", report_bytes)
    except (ImageToolsError, JobError) as exc:
        for artifact in published:
            artifact.unlink(missing_ok=True)
        raise JobError(getattr(exc, "code", str(exc))) from exc
    except Exception as exc:
        for artifact in published:
            artifact.unlink(missing_ok=True)
        raise JobError("inference_failed") from exc


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--request", required=True)
    parser.add_argument("--source", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--backend", choices=["cpu", "cuda"], required=True)
    parser.add_argument("--model-dir", default=str(DEFAULT_MODEL_ROOT))
    args = parser.parse_args(argv)
    try:
        run_job(request_path=Path(args.request), source_path=Path(args.source), output_dir=Path(args.output_dir), backend=args.backend, model_dir=Path(args.model_dir))
        return 0
    except JobError as exc:
        print(f"image_tools_job_failed:{exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
