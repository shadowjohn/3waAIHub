from __future__ import annotations

import asyncio
import hashlib
import json
import os
import re
import stat
import time
import uuid
import warnings
from threading import Lock
from contextlib import nullcontext
from dataclasses import dataclass
from io import BytesIO
from pathlib import Path
from typing import Any, Iterable, Mapping

from PIL import Image


ALLOWED_FIELDS = {"operation", "image", "question"}
MAX_IMAGE_WIDTH = 10_000
MAX_IMAGE_HEIGHT = 10_000
MAX_DECODED_PIXELS = 40_000_000
MAX_MULTIPART_OVERHEAD = 1024 * 1024


class ServiceError(RuntimeError):
    def __init__(self, code: str) -> None:
        self.code = code
        super().__init__(code)


@dataclass(frozen=True)
class DocVQARequest:
    question: str


@dataclass(frozen=True)
class VerifiedSnapshot:
    path: Path
    manifest_sha256: str


def parse_request(values: Mapping[str, Any]) -> DocVQARequest:
    if set(values) != {"operation", "question"}:
        raise ServiceError("bad_request")
    if not isinstance(values["operation"], str):
        raise ServiceError("bad_request")
    if values["operation"] != "docvqa":
        raise ServiceError("unsupported_operation")
    question = values["question"]
    if not isinstance(question, str):
        raise ServiceError("bad_request")
    question = question.strip(" \t\r\n\f\v")
    encoded = question.encode("ascii", "ignore")
    if (
        encoded.decode("ascii") != question
        or not 1 <= len(encoded) <= 400
        or re.fullmatch(r"[ -~]+", question) is None
        or re.search(r"[A-Za-z]", question) is None
    ):
        raise ServiceError("bad_request")
    return DocVQARequest(question)


def validate_form_keys(keys: Iterable[str]) -> None:
    names = list(keys)
    if len(names) != 3 or set(names) != ALLOWED_FIELDS or any(names.count(name) != 1 for name in ALLOWED_FIELDS):
        raise ServiceError("bad_request")


def format_docvqa_prompt(question: str) -> str:
    return f"answer en {question}"


def configured_max_new_tokens(environment: Mapping[str, str] | None = None) -> int:
    value = (os.environ if environment is None else environment).get("MANUAL_VISION_MAX_NEW_TOKENS", "64")
    try:
        configured = int(value)
    except (TypeError, ValueError) as exc:
        raise ServiceError("bad_request") from exc
    if not 1 <= configured <= 128:
        raise ServiceError("bad_request")
    return configured


def max_upload_bytes(environment: Mapping[str, str] | None = None) -> int:
    value = (os.environ if environment is None else environment).get("MANUAL_VISION_MAX_UPLOAD_MB", "50")
    try:
        configured = int(value)
    except (TypeError, ValueError) as exc:
        raise ServiceError("bad_request") from exc
    if not 1 <= configured <= 50:
        raise ServiceError("bad_request")
    return configured * 1024 * 1024


def decode_image(data: bytes) -> Image.Image:
    if not (data.startswith(b"\x89PNG\r\n\x1a\n") or data.startswith(b"\xff\xd8\xff")):
        raise ServiceError("bad_image")
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            source = Image.open(BytesIO(data))
        with source:
            if source.format not in {"PNG", "JPEG"}:
                raise ServiceError("bad_image")
            if source.width > MAX_IMAGE_WIDTH or source.height > MAX_IMAGE_HEIGHT or source.width * source.height > MAX_DECODED_PIXELS:
                raise ServiceError("bad_image")
            source.load()
            return source.convert("RGB")
    except ServiceError:
        raise
    except Exception as exc:
        raise ServiceError("bad_image") from exc


def model_root() -> Path:
    return Path(os.getenv("MANUAL_VISION_MODEL_DIR", "/models/manual-vision"))


def service_data_root() -> Path:
    return Path(os.getenv("MANUAL_VISION_SERVICE_DATA_DIR", "/data/service"))


def _hash_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _snapshot_files(snapshot: Path) -> set[str]:
    try:
        from provision import _snapshot_files as complete_snapshot_files

        return complete_snapshot_files(snapshot)
    except (ImportError, OSError, ValueError) as exc:
        raise ServiceError("model_manifest_invalid") from exc


def _snapshot_paths() -> tuple[Path, bytes]:
    root = model_root()
    manifest_path = root / "verified-snapshot.json"
    if root.is_symlink() or manifest_path.is_symlink():
        raise ServiceError("model_manifest_invalid")
    if not root.is_dir() or not manifest_path.is_file():
        raise ServiceError("model_not_provisioned")
    try:
        raw = manifest_path.read_bytes()
        marker = json.loads(raw)
        relative = marker["snapshot"]
        if not isinstance(relative, str) or re.fullmatch(r"revisions/[a-f0-9]{40}/snapshot", relative) is None:
            raise ValueError("invalid snapshot")
        snapshot = root / relative
        if any(path.is_symlink() for path in (root / "revisions", snapshot.parent, snapshot)):
            raise ValueError("snapshot symlink")
        if not snapshot.is_dir():
            raise FileNotFoundError("snapshot missing")
    except FileNotFoundError as exc:
        raise ServiceError("model_not_provisioned") from exc
    except (OSError, ValueError, TypeError, KeyError, json.JSONDecodeError) as exc:
        raise ServiceError("model_manifest_invalid") from exc
    return snapshot, raw


def _read_snapshot_manifest() -> tuple[VerifiedSnapshot, list[tuple[str, str]]]:
    snapshot, raw = _snapshot_paths()
    try:
        payload = json.loads(raw)
        if (
            not isinstance(payload, dict)
            or set(payload) != {"snapshot", "files"}
            or not isinstance(payload["snapshot"], str)
            or re.fullmatch(r"revisions/[a-f0-9]{40}/snapshot", payload["snapshot"]) is None
            or not isinstance(payload["files"], list)
        ):
            raise ValueError("invalid manifest")
        listed: set[str] = set()
        rows: list[tuple[str, str]] = []
        for row in payload["files"]:
            if not isinstance(row, dict) or set(row) != {"path", "sha256"}:
                raise ValueError("invalid manifest file")
            relative, checksum = row["path"], row["sha256"]
            if (
                not isinstance(relative, str)
                or not relative
                or relative.startswith("/")
                or "\\" in relative
                or ".." in Path(relative).parts
                or relative in listed
                or not isinstance(checksum, str)
                or re.fullmatch(r"[a-f0-9]{64}", checksum) is None
            ):
                raise ValueError("invalid manifest file")
            listed.add(relative)
            rows.append((relative, checksum))
        return VerifiedSnapshot(snapshot, hashlib.sha256(raw).hexdigest()), rows
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ServiceError("model_manifest_invalid") from exc


def published_snapshot_identity() -> VerifiedSnapshot:
    return _read_snapshot_manifest()[0]


def snapshot_revision(snapshot: VerifiedSnapshot) -> str:
    try:
        relative = snapshot.path.relative_to(model_root()).as_posix()
    except ValueError as exc:
        raise ServiceError("model_manifest_invalid") from exc
    match = re.fullmatch(r"revisions/([a-f0-9]{40})/snapshot", relative)
    if match is None:
        raise ServiceError("model_manifest_invalid")
    return match.group(1)


def verified_snapshot() -> VerifiedSnapshot:
    """Small fail-closed verifier; Task 3 may strengthen this manifest format."""
    identity, rows = _read_snapshot_manifest()
    snapshot = identity.path
    try:
        snapshot_root = snapshot.resolve(strict=True)
        listed: set[str] = set()
        for relative, checksum in rows:
            candidate = snapshot / relative
            resolved = candidate.resolve(strict=True)
            resolved.relative_to(snapshot_root)
            if not resolved.is_file() or _hash_file(resolved) != checksum:
                raise ValueError("manifest mismatch")
            listed.add(relative)
        if not listed or "config.json" not in listed or not any(name.endswith(".safetensors") for name in listed) or listed != _snapshot_files(snapshot):
            raise ValueError("incomplete manifest")
        return identity
    except ServiceError:
        raise
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ServiceError("model_manifest_invalid") from exc


def runtime_accepted(snapshot: VerifiedSnapshot) -> bool:
    """Task 3 may replace this bound acceptance record with a signed verifier."""
    try:
        root = service_data_root()
        record = root / "manual-vision-acceptance.json"
        if root.is_symlink() or record.is_symlink():
            raise ServiceError("runtime_not_ready")
        payload = json.loads(record.read_text(encoding="utf-8"))
    except ServiceError:
        raise
    except (OSError, ValueError, TypeError):
        return False
    configured_revision = os.getenv("MANUAL_VISION_MODEL_REVISION", "")
    if re.fullmatch(r"[a-f0-9]{40}", configured_revision) is None:
        return False
    return (
        isinstance(payload, dict)
        and payload.get("accepted") is True
        and payload.get("manifest_sha256") == snapshot.manifest_sha256
        and payload.get("model_revision") == snapshot_revision(snapshot)
        and payload.get("model_revision") == configured_revision
    )


_RUNTIME: tuple[str, Any, Any] | None = None
_VERIFIED_IDENTITY: str | None = None
_TRUSTED_FILES: tuple[tuple[str, tuple[int, int, int, int, int]], ...] = ()
_VERIFICATION_LOCK = Lock()


def _trusted_file_metadata(snapshot: Path, relative: str) -> tuple[int, int, int, int, int]:
    try:
        snapshot_root = snapshot.resolve(strict=True)
        candidate = snapshot / relative
        current = candidate
        while True:
            if current.is_symlink():
                raise ServiceError("model_manifest_invalid")
            if current == snapshot:
                break
            current = current.parent
        resolved = candidate.resolve(strict=True)
        resolved.relative_to(snapshot_root)
        info = candidate.stat(follow_symlinks=False)
        if not stat.S_ISREG(info.st_mode):
            raise ServiceError("model_manifest_invalid")
        return (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns, info.st_ctime_ns)
    except ServiceError:
        raise
    except (OSError, ValueError) as exc:
        raise ServiceError("model_manifest_invalid") from exc


def process_verified_snapshot() -> VerifiedSnapshot:
    global _VERIFIED_IDENTITY, _TRUSTED_FILES
    with _VERIFICATION_LOCK:
        identity = published_snapshot_identity()
        if _VERIFIED_IDENTITY is None:
            verified = verified_snapshot()
            current, rows = _read_snapshot_manifest()
            if current.manifest_sha256 != verified.manifest_sha256:
                raise ServiceError("runtime_not_ready")
            _TRUSTED_FILES = tuple((relative, _trusted_file_metadata(verified.path, relative)) for relative, _checksum in rows)
            _VERIFIED_IDENTITY = verified.manifest_sha256
            return verified
        if identity.manifest_sha256 != _VERIFIED_IDENTITY:
            raise ServiceError("runtime_not_ready")
        # ponytail: metadata catches normal replacement/deletion; immutable read-only snapshots are the trust boundary.
        for relative, trusted in _TRUSTED_FILES:
            if _trusted_file_metadata(identity.path, relative) != trusted:
                raise ServiceError("model_manifest_invalid")
        return identity


def load_runtime(
    *,
    torch_module: Any | None = None,
    require_acceptance: bool = True,
    return_identity: bool = False,
) -> tuple[Any, ...]:
    global _RUNTIME
    configured_max_new_tokens()
    snapshot = process_verified_snapshot()
    if require_acceptance and not runtime_accepted(snapshot):
        raise ServiceError("runtime_not_ready")
    if _RUNTIME is not None:
        if _RUNTIME[0] != snapshot.manifest_sha256:
            raise ServiceError("runtime_not_ready")
    if torch_module is None:
        import torch as torch_module
    if not bool(torch_module.cuda.is_available()):
        raise ServiceError("gpu_unavailable")
    if _RUNTIME is not None:
        verified = process_verified_snapshot()
        if verified.manifest_sha256 != snapshot.manifest_sha256:
            raise ServiceError("runtime_not_ready")
        return (*_RUNTIME[1:], verified) if return_identity else _RUNTIME[1:]
    try:
        from transformers import PaliGemmaForConditionalGeneration, PaliGemmaProcessor

        processor = PaliGemmaProcessor.from_pretrained(str(snapshot.path), local_files_only=True)
        model = PaliGemmaForConditionalGeneration.from_pretrained(
            str(snapshot.path), torch_dtype=torch_module.float16, local_files_only=True,
        ).to("cuda")
        model.eval()
        verified = process_verified_snapshot()
        if verified.manifest_sha256 != snapshot.manifest_sha256:
            raise ServiceError("runtime_not_ready")
        _RUNTIME = (verified.manifest_sha256, processor, model)
        return (processor, model, verified) if return_identity else (processor, model)
    except ServiceError:
        raise
    except Exception as exc:
        raise ServiceError("inference_failed") from exc


def run_docvqa(
    image: Image.Image,
    question: str,
    *,
    processor: Any,
    model: Any,
    torch_module: Any | None = None,
) -> str:
    configured_max_new_tokens()
    try:
        prompt = format_docvqa_prompt(question)
        inputs = processor(text=prompt, images=image, return_tensors="pt")
        for name, value in inputs.items():
            move = getattr(value, "to", None)
            if callable(move):
                kwargs: dict[str, Any] = {"device": "cuda"}
                is_floating = getattr(value, "is_floating_point", None)
                if callable(is_floating) and is_floating():
                    kwargs["dtype"] = model.dtype
                inputs[name] = move(**kwargs)
        if torch_module is None:
            import torch as torch_module
        no_grad = getattr(torch_module, "no_grad", None)
        with no_grad() if callable(no_grad) else nullcontext():
            generated = model.generate(**inputs, max_new_tokens=configured_max_new_tokens())
        continuation = generated[:, inputs["input_ids"].shape[-1]:]
        answer = processor.decode(continuation[0], skip_special_tokens=True).strip()
        if not answer:
            raise ValueError("empty answer")
        return answer
    except Exception as exc:
        raise ServiceError("inference_failed") from exc


def success_response(answer: str, elapsed_ms: int, request_id: str) -> dict[str, Any]:
    return {
        "ok": True,
        "mode": "manual_vision",
        "operation": "docvqa",
        "answer": answer,
        "answer_language": "en",
        "contract_revision": 1,
        "elapsed_ms": elapsed_ms,
        "request_id": request_id,
    }


def create_app() -> Any | None:
    try:
        from fastapi import FastAPI, Request
        from fastapi.responses import JSONResponse
        from starlette.datastructures import UploadFile
    except ImportError:
        return None

    globals()["Request"] = Request
    app = FastAPI(title="3waAIHub Manual Vision", docs_url=None, redoc_url=None, openapi_url=None)
    inference_lock = asyncio.Lock()
    app.state.inference_lock = inference_lock

    def error_response(error: ServiceError) -> JSONResponse:
        status = {
            "bad_request": 400,
            "unsupported_operation": 400,
            "bad_image": 400,
            "file_too_large": 413,
            "gpu_unavailable": 503,
            "model_not_provisioned": 503,
            "model_manifest_invalid": 500,
            "runtime_not_ready": 503,
            "inference_failed": 502,
        }[error.code]
        return JSONResponse(status_code=status, content={"ok": False, "error": error.code})

    @app.get("/health")
    async def health() -> dict[str, bool]:
        try:
            snapshot = process_verified_snapshot()
            ready = runtime_accepted(snapshot)
        except ServiceError:
            ready = False
        return {"ok": ready, "ready": ready}

    @app.post("/vision/docvqa")
    async def docvqa(request: Request) -> JSONResponse:
        started = time.perf_counter()
        request_id = f"req_{uuid.uuid4().hex}"
        form: Any | None = None
        try:
            limit = max_upload_bytes()
            try:
                content_length = int(request.headers.get("content-length", "0"))
            except ValueError as exc:
                raise ServiceError("bad_request") from exc
            if content_length > limit + MAX_MULTIPART_OVERHEAD:
                raise ServiceError("file_too_large")
            try:
                try:
                    form = await request.form(max_files=1, max_fields=2, max_part_size=limit)
                except TypeError:
                    form = await request.form(max_files=1, max_fields=2)
            except Exception as exc:
                raise ServiceError("bad_request") from exc
            items = list(form.multi_items())
            validate_form_keys(key for key, _value in items)
            values = {key: value for key, value in items}
            image_upload = values["image"]
            if not isinstance(image_upload, UploadFile) or image_upload.content_type not in {"image/png", "image/jpeg"}:
                raise ServiceError("bad_image")
            parsed = parse_request({"operation": values["operation"], "question": values["question"]})
            try:
                data = await image_upload.read(limit + 1)
            except Exception as exc:
                raise ServiceError("bad_image") from exc
            if len(data) > limit:
                raise ServiceError("file_too_large")
            image = decode_image(data)
            async with app.state.inference_lock:
                processor, model = load_runtime()
                answer = run_docvqa(image, parsed.question, processor=processor, model=model)
            elapsed_ms = int((time.perf_counter() - started) * 1000)
            return JSONResponse(content=success_response(answer, elapsed_ms, request_id))
        except ServiceError as exc:
            return error_response(exc)
        finally:
            if form is not None:
                try:
                    await form.close()
                except Exception:
                    pass

    return app


app = create_app()
