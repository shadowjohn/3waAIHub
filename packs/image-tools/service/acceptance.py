from __future__ import annotations

import argparse
import base64
import hashlib
import io
import json
import time
import urllib.error
import urllib.parse
import urllib.request
from collections.abc import Iterable, Mapping
from pathlib import Path
from typing import Any

from PIL import Image, UnidentifiedImageError


PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
MODEL_SCALES = {
    "realesrgan-x4plus": 4,
    "realesrgan-x4plus-anime": 4,
    "realesr-animevideov3-x2": 2,
    "realesr-animevideov3-x3": 3,
    "realesr-animevideov3-x4": 4,
}


class AcceptanceUnavailable(RuntimeError):
    pass


def _headers(values: Mapping[str, str] | Iterable[tuple[str, str]]) -> dict[str, str]:
    items = values.items() if isinstance(values, Mapping) else values
    result: dict[str, str] = {}
    for key, value in items:
        normalized = str(key).lower()
        if normalized in result and result[normalized] != str(value):
            raise AssertionError(f"conflicting response header: {normalized}")
        result[normalized] = str(value)
    return result


def assert_health(payload: object) -> None:
    if not isinstance(payload, dict) or payload.get("ok") is not True or payload.get("service") != "image-tools":
        raise AcceptanceUnavailable("invalid image-tools health response")
    if (payload.get("ready") is not True
            or payload.get("runtime_level") != "L4a-model-init-smoke"
            or payload.get("runtime_ready") is not True):
        raise AcceptanceUnavailable("image-tools verified L4a model initialization is not ready")


def _validated_png(payload: bytes, dimensions: tuple[int, int]) -> None:
    if not isinstance(payload, bytes) or not payload.startswith(PNG_SIGNATURE):
        raise AssertionError("response is not a PNG signature")
    try:
        with Image.open(io.BytesIO(payload)) as probe:
            if probe.format != "PNG":
                raise AssertionError("response is not PNG")
            probe.verify()
        with Image.open(io.BytesIO(payload)) as image:
            image.load()
            if image.size != dimensions:
                raise AssertionError("unexpected PNG dimensions")
    except (UnidentifiedImageError, OSError, ValueError) as exc:
        raise AssertionError("invalid PNG payload") from exc


def _metadata(values: Mapping[str, str] | Iterable[tuple[str, str]], *, backend: str, model: str, dimensions: tuple[int, int]) -> None:
    headers = _headers(values)
    if headers.get("content-type", "").split(";", 1)[0].strip().lower() != "image/png":
        raise AssertionError("unexpected content type")
    required = {
        "x-3waaihub-model": model,
        "x-3waaihub-backend": backend,
        "x-3waaihub-width": str(dimensions[0]),
        "x-3waaihub-height": str(dimensions[1]),
    }
    for key, expected in required.items():
        if headers.get(key) != expected:
            raise AssertionError(f"unexpected {key}")
    elapsed = headers.get("x-3waaihub-elapsed-ms", "")
    if not elapsed.isascii() or not elapsed.isdecimal() or int(elapsed) < 1:
        raise AssertionError("invalid elapsed metadata")


def validate_sync_response(status: int, headers: Mapping[str, str] | Iterable[tuple[str, str]], payload: bytes, *, backend: str, model: str, dimensions: tuple[int, int]) -> dict[str, object]:
    if status != 200:
        raise AssertionError(f"unexpected sync status: {status}")
    _metadata(headers, backend=backend, model=model, dimensions=dimensions)
    _validated_png(payload, dimensions)
    return {"backend": backend, "model": model, "width": dimensions[0], "height": dimensions[1], "output_sha256": hashlib.sha256(payload).hexdigest()}


def validate_async_artifacts(image_payload: bytes, report_payload: bytes, *, backend: str, model: str, dimensions: tuple[int, int]) -> dict[str, object]:
    _validated_png(image_payload, dimensions)
    try:
        report = json.loads(report_payload)
    except (TypeError, ValueError, json.JSONDecodeError) as exc:
        raise AssertionError("invalid artifact report") from exc
    required = {"model", "backend", "source_width", "source_height", "width", "height", "elapsed_ms", "output_sha256"}
    if not isinstance(report, dict) or set(report) != required:
        raise AssertionError("unexpected artifact report")
    if report["model"] != model or report["backend"] != backend or (report["width"], report["height"]) != dimensions:
        raise AssertionError("artifact metadata mismatch")
    if model not in MODEL_SCALES or any(not isinstance(report[key], int) or isinstance(report[key], bool) or report[key] < 1 for key in ("source_width", "source_height", "width", "height", "elapsed_ms")):
        raise AssertionError("invalid artifact dimensions")
    scale = MODEL_SCALES[model]
    if (report["source_width"] * scale, report["source_height"] * scale) != dimensions:
        raise AssertionError("artifact dimensions do not match model scale")
    digest = hashlib.sha256(image_payload).hexdigest()
    if report["output_sha256"] != digest:
        raise AssertionError("artifact hash mismatch")
    return {"backend": backend, "model": model, "width": dimensions[0], "height": dimensions[1], "output_sha256": digest, "elapsed_ms": report["elapsed_ms"]}


def assert_no_raw_base64(payload: bytes, raw_base64: str) -> None:
    if not isinstance(payload, bytes) or not isinstance(raw_base64, str) or not raw_base64:
        raise AssertionError("invalid persistence check")
    if raw_base64.encode("ascii") in payload:
        raise AssertionError("raw Base64 was persisted")


def _request(url: str, *, method: str = "GET", body: bytes | None = None, headers: Mapping[str, str] | None = None, timeout: int = 900) -> tuple[int, list[tuple[str, str]], bytes]:
    request = urllib.request.Request(url, data=body, method=method, headers=dict(headers or {}))
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, list(response.headers.items()), response.read()
    except urllib.error.HTTPError as exc:
        return exc.code, list(exc.headers.items()), exc.read()
    except (urllib.error.URLError, TimeoutError, OSError) as exc:
        raise AcceptanceUnavailable("HTTP endpoint is unavailable") from exc


def _json_response(url: str, *, headers: Mapping[str, str]) -> tuple[dict[str, Any], bytes]:
    status, response_headers, body = _request(url, headers=headers)
    if status != 200 or _headers(response_headers).get("content-type", "").split(";", 1)[0].lower() != "application/json":
        raise AssertionError("unexpected JSON response")
    try:
        payload = json.loads(body)
    except (TypeError, ValueError, json.JSONDecodeError) as exc:
        raise AssertionError("invalid JSON response") from exc
    if not isinstance(payload, dict):
        raise AssertionError("JSON response is not an object")
    return payload, body


def _multipart(fields: Mapping[str, str], *, source: bytes | None = None) -> tuple[bytes, str]:
    boundary = "3waaihub-image-tools-acceptance"
    pieces: list[bytes] = []
    for name, value in fields.items():
        pieces.extend((f"--{boundary}\r\n".encode(), f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode(), value.encode("ascii"), b"\r\n"))
    if source is not None:
        pieces.extend((f"--{boundary}\r\n".encode(), b'Content-Disposition: form-data; name="image"; filename="smoke.png"\r\nContent-Type: image/png\r\n\r\n', source, b"\r\n"))
    pieces.append(f"--{boundary}--\r\n".encode())
    return b"".join(pieces), f"multipart/form-data; boundary={boundary}"


def _api_url(base_url: str, operation: str) -> str:
    separator = "&" if "?" in base_url else "?"
    return f"{base_url}{separator}mode=image-tools&operation={urllib.parse.quote(operation)}"


def run_sync(*, endpoint: str, fixture: Path, backend: str, model: str, gateway: bool, token: str | None = None) -> dict[str, object]:
    source = fixture.read_bytes()
    with Image.open(io.BytesIO(source)) as image:
        image.load()
        expected = (image.width * MODEL_SCALES[model], image.height * MODEL_SCALES[model])
    body, content_type = _multipart({"operation": "upscale", "backend": backend, "model": model}, source=source)
    headers = {"Content-Type": content_type}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    status, response_headers, output = _request(_api_url(endpoint, "upscale") if gateway else endpoint.rstrip("/") + "/process/image", method="POST", body=body, headers=headers)
    return validate_sync_response(status, response_headers, output, backend=backend, model=model, dimensions=expected)


def _artifact_urls(result: Mapping[str, Any], base_url: str) -> dict[str, str]:
    artifacts = result.get("result", result).get("artifacts") if isinstance(result.get("result", result), dict) else None
    if not isinstance(artifacts, list):
        raise AssertionError("missing task artifacts")
    urls: dict[str, str] = {}
    template = result.get("artifact_url_template")
    expected_names = {
        "upscaled_image": "upscaled_image.png",
        "upscale_report": "upscale_report.json",
    }
    for artifact in artifacts:
        if not isinstance(artifact, dict):
            continue
        artifact_id = artifact.get("id")
        name = artifact.get("name") or artifact.get("path") or expected_names.get(artifact.get("type"))
        url = artifact.get("url")
        if not isinstance(artifact_id, int) or isinstance(artifact_id, bool) or artifact_id < 1:
            continue
        if not isinstance(url, str) and isinstance(template, str):
            url = template.replace("{artifact_id}", str(artifact_id))
        if not isinstance(url, str):
            separator = "&" if "?" in base_url else "?"
            url = f"{base_url}{separator}mode=artifact&artifact_id={artifact_id}"
        if isinstance(name, str) and isinstance(url, str):
            urls[name] = urllib.parse.urljoin(base_url, url)
    if set(urls) != {"upscaled_image.png", "upscale_report.json"}:
        raise AssertionError("unexpected task artifacts")
    return urls


def run_async(*, gateway_url: str, fixture: Path, backend: str, model: str, token: str) -> dict[str, object]:
    source = fixture.read_bytes()
    with Image.open(io.BytesIO(source)) as image:
        image.load()
        expected = (image.width * MODEL_SCALES[model], image.height * MODEL_SCALES[model])
    raw_base64 = base64.b64encode(source).decode("ascii")
    body, content_type = _multipart({"operation": "upscale_task", "backend": backend, "model": model, "base64_string": raw_base64})
    headers = {"Content-Type": content_type, "Authorization": f"Bearer {token}"}
    status, _, submitted_bytes = _request(_api_url(gateway_url, "upscale_task"), method="POST", body=body, headers=headers)
    if status not in {200, 201, 202}:
        raise AssertionError(f"unexpected task submission status: {status}")
    try:
        submitted = json.loads(submitted_bytes)
    except (TypeError, ValueError, json.JSONDecodeError) as exc:
        raise AssertionError("invalid task submission") from exc
    if not isinstance(submitted, dict) or not isinstance(submitted.get("status_url"), str) or not isinstance(submitted.get("result_url"), str):
        raise AssertionError("missing task URLs")
    assert_no_raw_base64(submitted_bytes, raw_base64)
    status_url = urllib.parse.urljoin(gateway_url, submitted["status_url"])
    deadline = time.monotonic() + 900
    status_payload: dict[str, Any] = {}
    while time.monotonic() < deadline:
        status_payload, status_bytes = _json_response(status_url, headers={"Authorization": f"Bearer {token}"})
        assert_no_raw_base64(status_bytes, raw_base64)
        state = status_payload.get("status", status_payload.get("task_status"))
        if state in {"completed", "succeeded", "success"}:
            break
        if state in {"failed", "cancelled", "canceled"}:
            raise AssertionError(f"async task ended as {state}")
        time.sleep(1)
    else:
        raise AssertionError("async task timed out")
    result_url = urllib.parse.urljoin(gateway_url, submitted["result_url"])
    result, result_bytes = _json_response(result_url, headers={"Authorization": f"Bearer {token}"})
    assert_no_raw_base64(result_bytes, raw_base64)
    artifacts = _artifact_urls(result, gateway_url)
    _, _, image_payload = _request(artifacts["upscaled_image.png"], headers={"Authorization": f"Bearer {token}"})
    _, _, report_payload = _request(artifacts["upscale_report.json"], headers={"Authorization": f"Bearer {token}"})
    assert_no_raw_base64(report_payload, raw_base64)
    return validate_async_artifacts(image_payload, report_payload, backend=backend, model=model, dimensions=expected)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--service-url", required=True)
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--gateway-url")
    parser.add_argument("--token")
    parser.add_argument("--model", choices=sorted(MODEL_SCALES), default="realesrgan-x4plus")
    parser.add_argument("--direct-sync", action="store_true")
    args = parser.parse_args()
    fixture = Path(args.fixture)
    try:
        health, _ = _json_response(args.service_url.rstrip("/") + "/health", headers={})
        assert_health(health)
        records: list[dict[str, object]] = []
        if args.direct_sync:
            for backend in ("cuda", "cpu"):
                records.append(run_sync(endpoint=args.service_url, fixture=fixture, backend=backend, model=args.model, gateway=False))
        if args.gateway_url and args.token:
            for backend in ("cuda", "cpu"):
                records.append(run_sync(endpoint=args.gateway_url, fixture=fixture, backend=backend, model=args.model, gateway=True, token=args.token))
                records.append(run_async(gateway_url=args.gateway_url, fixture=fixture, backend=backend, model=args.model, token=args.token))
        elif not args.direct_sync:
            raise AcceptanceUnavailable("gateway URL and token are required for Hub sync/async acceptance")
        print(json.dumps({"ok": True, "cases": records}, sort_keys=True))
        return 0
    except AcceptanceUnavailable as exc:
        print(json.dumps({"ok": False, "available": False, "reason": str(exc)}, sort_keys=True))
        return 2
    except (AssertionError, OSError, ValueError) as exc:
        print(json.dumps({"ok": False, "available": True, "reason": str(exc)}, sort_keys=True))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
