from __future__ import annotations

import asyncio
import hashlib
import json
import os
import re
import time
import uuid
from contextlib import nullcontext
from dataclasses import dataclass
from io import BytesIO
from pathlib import Path
from typing import Any, Iterable, Mapping

from PIL import Image


ALLOWED_FIELDS = {"operation", "image", "question"}


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
        with Image.open(BytesIO(data)) as source:
            if source.format not in {"PNG", "JPEG"}:
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
    files: set[str] = set()
    for current, directories, names in os.walk(snapshot):
        directories.sort()
        for name in sorted(names):
            candidate = Path(current) / name
            if candidate.is_symlink() or not candidate.is_file():
                raise ServiceError("model_manifest_invalid")
            files.add(candidate.relative_to(snapshot).as_posix())
    return files


def verified_snapshot() -> VerifiedSnapshot:
    """Small fail-closed verifier; Task 3 may strengthen this manifest format."""
    root = model_root()
    snapshot = root / "snapshot"
    manifest_path = root / "verified-snapshot.json"
    if not snapshot.is_dir() or not manifest_path.is_file():
        raise ServiceError("model_not_provisioned")
    try:
        raw = manifest_path.read_bytes()
        payload = json.loads(raw)
        if not isinstance(payload, dict) or set(payload) != {"snapshot", "files"} or payload["snapshot"] != "snapshot" or not isinstance(payload["files"], list):
            raise ValueError("invalid manifest")
        snapshot_root = snapshot.resolve(strict=True)
        listed: set[str] = set()
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
            candidate = snapshot / relative
            resolved = candidate.resolve(strict=True)
            resolved.relative_to(snapshot_root)
            if not resolved.is_file() or _hash_file(resolved) != checksum:
                raise ValueError("manifest mismatch")
            listed.add(relative)
        if not listed or "config.json" not in listed or not any(name.endswith(".safetensors") for name in listed) or listed != _snapshot_files(snapshot):
            raise ValueError("incomplete manifest")
        return VerifiedSnapshot(snapshot, hashlib.sha256(raw).hexdigest())
    except ServiceError:
        raise
    except (OSError, ValueError, TypeError, json.JSONDecodeError) as exc:
        raise ServiceError("model_manifest_invalid") from exc


def runtime_accepted(snapshot: VerifiedSnapshot) -> bool:
    """Task 3 may replace this bound acceptance record with a signed verifier."""
    try:
        payload = json.loads((service_data_root() / "manual-vision-acceptance.json").read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError):
        return False
    return payload == {"accepted": True, "manifest_sha256": snapshot.manifest_sha256}


_RUNTIME: tuple[Any, Any] | None = None


def load_runtime(*, torch_module: Any | None = None) -> tuple[Any, Any]:
    global _RUNTIME
    configured_max_new_tokens()
    snapshot = verified_snapshot()
    if not runtime_accepted(snapshot):
        raise ServiceError("runtime_not_ready")
    if torch_module is None:
        import torch as torch_module
    if not bool(torch_module.cuda.is_available()):
        raise ServiceError("gpu_unavailable")
    if _RUNTIME is not None:
        return _RUNTIME
    try:
        from transformers import PaliGemmaForConditionalGeneration, PaliGemmaProcessor

        processor = PaliGemmaProcessor.from_pretrained(str(snapshot.path), local_files_only=True)
        model = PaliGemmaForConditionalGeneration.from_pretrained(
            str(snapshot.path), torch_dtype=torch_module.float16, local_files_only=True,
        ).to("cuda")
        model.eval()
        _RUNTIME = (processor, model)
        return _RUNTIME
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
            snapshot = verified_snapshot()
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
            try:
                form = await request.form()
            except Exception as exc:
                raise ServiceError("bad_request") from exc
            items = list(form.multi_items())
            validate_form_keys(key for key, _value in items)
            values = {key: value for key, value in items}
            image_upload = values["image"]
            if not isinstance(image_upload, UploadFile) or image_upload.content_type not in {"image/png", "image/jpeg"}:
                raise ServiceError("bad_image")
            parsed = parse_request({"operation": values["operation"], "question": values["question"]})
            limit = max_upload_bytes()
            data = await image_upload.read(limit + 1)
            if len(data) > limit:
                raise ServiceError("file_too_large")
            image = decode_image(data)
            async with inference_lock:
                processor, model = load_runtime()
                answer = run_docvqa(image, parsed.question, processor=processor, model=model)
            elapsed_ms = int((time.perf_counter() - started) * 1000)
            return JSONResponse(content=success_response(answer, elapsed_ms, request_id))
        except ServiceError as exc:
            return error_response(exc)
        finally:
            if form is not None:
                await form.close()

    return app


app = create_app()
