from __future__ import annotations

import asyncio
import hashlib
import io
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from fastapi.testclient import TestClient
from PIL import Image
from starlette.datastructures import FormData


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR))
import app as vision  # noqa: E402


def png_bytes() -> bytes:
    output = io.BytesIO()
    Image.new("RGB", (2, 2), "white").save(output, format="PNG")
    return output.getvalue()


def write_snapshot(root: Path, weight: bytes = b"weights") -> str:
    snapshot = root / "snapshot"
    snapshot.mkdir(parents=True, exist_ok=True)
    files = {"config.json": b"{}", "model.safetensors": weight}
    for name, content in files.items():
        (snapshot / name).write_bytes(content)
    raw = json.dumps({"snapshot": "snapshot", "files": [{"path": name, "sha256": hashlib.sha256(content).hexdigest()} for name, content in sorted(files.items())]}).encode()
    (root / "verified-snapshot.json").write_bytes(raw)
    return hashlib.sha256(raw).hexdigest()


class Tensor:
    def __init__(self, floating: bool) -> None:
        self.floating = floating

    def is_floating_point(self) -> bool:
        return self.floating

    def to(self, **_kwargs: object) -> "Tensor":
        return self


class InputIds:
    shape = (1, 1)


class Generated:
    def __getitem__(self, _key: object) -> list[list[int]]:
        return [[1]]


class Processor:
    def __call__(self, **_kwargs: object) -> dict[str, object]:
        return {"input_ids": InputIds(), "pixel_values": Tensor(True), "attention_mask": Tensor(False)}

    def decode(self, _tokens: object, **_kwargs: object) -> str:
        return "answer"


class Model:
    dtype = "float16"

    def generate(self, **_kwargs: object) -> Generated:
        return Generated()


class EndpointTests(unittest.TestCase):
    def setUp(self) -> None:
        assert vision.app is not None
        self.client = TestClient(vision.app)

    def test_only_public_routes_are_exposed(self) -> None:
        self.assertEqual({"/health", "/vision/docvqa"}, {route.path for route in vision.app.routes})

    def test_real_starlette_upload_is_accepted_has_prefixed_id_and_form_is_closed(self) -> None:
        closed: list[bool] = []
        original_close = FormData.close

        async def tracked_close(form: FormData) -> None:
            closed.append(True)
            await original_close(form)

        with patch.object(vision, "load_runtime", return_value=(Processor(), Model())), patch.object(FormData, "close", tracked_close):
            response = self.client.post(
                "/vision/docvqa",
                data={"operation": "docvqa", "question": "What is this?"},
                files={"image": ("manual.png", png_bytes(), "image/png")},
            )
        self.assertEqual(200, response.status_code)
        payload = response.json()
        self.assertEqual("answer", payload["answer"])
        self.assertTrue(payload["request_id"].startswith("req_"))
        self.assertTrue(closed)

    def test_malformed_multipart_is_stable_bad_request(self) -> None:
        response = self.client.post(
            "/vision/docvqa",
            content=b"--broken\r\nContent-Disposition: form-data; name=\"question\"\r\n\r\nWhat is this?",
            headers={"content-type": "multipart/form-data; boundary=not-the-body-boundary"},
        )
        self.assertEqual(400, response.status_code)
        self.assertEqual({"ok": False, "error": "bad_request"}, response.json())

    def test_strict_form_mime_and_content_length_rejections(self) -> None:
        with patch.object(vision, "max_upload_bytes", return_value=10):
            oversized = self.client.post(
                "/vision/docvqa",
                data={"operation": "docvqa", "question": "What is this?"},
                files={"image": ("manual.png", png_bytes(), "image/png")},
            )
        wrong_mime = self.client.post(
            "/vision/docvqa",
            data={"operation": "docvqa", "question": "What is this?"},
            files={"image": ("manual.png", png_bytes(), "text/plain")},
        )
        unknown = self.client.post(
            "/vision/docvqa",
            data={"operation": "docvqa", "question": "What is this?", "extra": "no"},
            files={"image": ("manual.png", png_bytes(), "image/png")},
        )
        duplicate = self.client.post(
            "/vision/docvqa",
            files=[
                ("operation", (None, "docvqa")),
                ("operation", (None, "docvqa")),
                ("question", (None, "What is this?")),
                ("image", ("manual.png", png_bytes(), "image/png")),
            ],
        )
        self.assertEqual((413, "file_too_large"), (oversized.status_code, oversized.json()["error"]))
        self.assertEqual((400, "bad_image"), (wrong_mime.status_code, wrong_mime.json()["error"]))
        self.assertEqual((400, "bad_request"), (unknown.status_code, unknown.json()["error"]))
        self.assertEqual((400, "bad_request"), (duplicate.status_code, duplicate.json()["error"]))

    def test_form_close_failure_does_not_replace_success(self) -> None:
        async def broken_close(_form: FormData) -> None:
            raise OSError("close failed")

        with patch.object(vision, "load_runtime", return_value=(Processor(), Model())), patch.object(FormData, "close", broken_close):
            response = self.client.post(
                "/vision/docvqa",
                data={"operation": "docvqa", "question": "What is this?"},
                files={"image": ("manual.png", png_bytes(), "image/png")},
            )
        self.assertEqual(200, response.status_code)

    def test_inference_lock_serializes_concurrent_fake_model_work(self) -> None:
        class TrackingLock:
            entered = 0

            async def __aenter__(self) -> None:
                self.entered += 1

            async def __aexit__(self, *_args: object) -> None:
                return None

        lock = TrackingLock()
        with patch.object(vision, "load_runtime", return_value=(Processor(), Model())), patch.object(vision.app.state, "inference_lock", lock):
            response = self.client.post(
                "/vision/docvqa",
                data={"operation": "docvqa", "question": "What is this?"},
                files={"image": ("manual.png", png_bytes(), "image/png")},
            )
        self.assertEqual(200, response.status_code)
        self.assertEqual(1, lock.entered)

    def test_chunked_style_oversize_rejects_after_form_parse(self) -> None:
        with patch.object(vision, "max_upload_bytes", return_value=10):
            request = self.client.build_request(
                "POST",
                "/vision/docvqa",
                data={"operation": "docvqa", "question": "What is this?"},
                files={"image": ("manual.png", png_bytes(), "image/png")},
            )
            del request.headers["content-length"]
            response = self.client.send(request)
        self.assertEqual((413, "file_too_large"), (response.status_code, response.json()["error"]))

    def test_health_full_verifies_once_then_fails_closed_on_identity_change(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            digest = write_snapshot(root)
            (root / "snapshot" / "model.safetensors").write_bytes(b"tampered")
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                vision._VERIFIED_IDENTITY = None
                vision._TRUSTED_FILES = ()
                try:
                    self.assertFalse(self.client.get("/health").json()["ready"])
                    digest = write_snapshot(root)
                    (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": digest}), encoding="utf-8")
                    self.assertTrue(self.client.get("/health").json()["ready"])
                    with patch.object(vision, "_hash_file", side_effect=AssertionError("health must not rehash")):
                        self.assertTrue(self.client.get("/health").json()["ready"])
                    (root / "snapshot" / "model.safetensors").unlink()
                    self.assertFalse(self.client.get("/health").json()["ready"])
                    write_snapshot(root)
                    digest = write_snapshot(root, b"changed")
                    (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": digest}), encoding="utf-8")
                    self.assertFalse(self.client.get("/health").json()["ready"])
                finally:
                    vision._VERIFIED_IDENTITY = None
                    vision._TRUSTED_FILES = ()


if __name__ == "__main__":
    unittest.main()
