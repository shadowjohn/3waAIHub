from __future__ import annotations

import io
import os
import stat
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

import torch
from fastapi.testclient import TestClient
from PIL import Image

import app as service_app


class FakeModel:
    def __init__(self, error: Exception | None = None) -> None:
        self.error = error
        self.calls = 0

    def __call__(self, tensor: torch.Tensor) -> list[torch.Tensor]:
        self.calls += 1
        if self.error is not None:
            raise self.error
        logits = torch.tensor(
            [[[[-20.0, 0.0], [20.0, 1.0986123]]]],
            dtype=tensor.dtype,
            device=tensor.device,
        )
        return [logits]


def encoded(image: Image.Image, image_format: str, *, orientation: int | None = None) -> bytes:
    output = io.BytesIO()
    kwargs = {}
    if orientation is not None:
        exif = Image.Exif()
        exif[274] = orientation
        kwargs["exif"] = exif
    image.save(output, format=image_format, **kwargs)
    return output.getvalue()


class EndpointTest(unittest.TestCase):
    def setUp(self) -> None:
        self.client = TestClient(service_app.app)

    def post(self, image_bytes: bytes, filename: str = "source.png", data: dict[str, str] | None = None, **files):
        uploads = {"image": (filename, image_bytes, "application/octet-stream"), **files}
        return self.client.post("/remove-background/image", files=uploads, data=data or {})

    def test_jpeg_png_webp_orientation_modes_headers_and_private_cleanup(self) -> None:
        sources = [
            (encoded(Image.new("RGB", (2, 3), "red"), "JPEG", orientation=6), "source.jpg", (3, 2)),
            (encoded(Image.new("RGB", (4, 3), "green"), "PNG"), "source.png", (4, 3)),
            (encoded(Image.new("RGB", (5, 4), "blue"), "WEBP"), "source.webp", (5, 4)),
        ]
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {
            "BIREFNET_SERVICE_DATA_DIR": temporary,
            "BIREFNET_MAX_UPLOAD_MB": "50",
        }), patch.object(service_app, "load_model", return_value=(FakeModel(), "cpu")):
            for source, filename, expected_size in sources:
                with self.subTest(filename=filename):
                    response = self.post(source, filename)
                    self.assertEqual(response.status_code, 200)
                    self.assertTrue(response.content.startswith(b"\x89PNG\r\n\x1a\n"))
                    result = Image.open(io.BytesIO(response.content))
                    self.assertEqual((result.mode, result.size), ("RGBA", expected_size))
                    self.assertGreater(len(set(result.getchannel("A").get_flattened_data())), 1)
                    self.assertEqual(response.headers["content-type"], "image/png")
                    self.assertEqual(response.headers["x-3waaihub-model"], service_app.MODEL_HEADER)
                    self.assertEqual(response.headers["x-3waaihub-device"], "cpu")
                    self.assertGreater(int(response.headers["x-3waaihub-elapsed-ms"]), 0)
                    self.assertEqual(int(response.headers["x-3waaihub-width"]), expected_size[0])
                    self.assertEqual(int(response.headers["x-3waaihub-height"]), expected_size[1])

            mask = self.post(sources[1][0], data={"output": "mask"})
            self.assertEqual(Image.open(io.BytesIO(mask.content)).mode, "L")
            composite = self.post(sources[1][0], data={"output": "composite", "background": "white"})
            self.assertEqual(Image.open(io.BytesIO(composite.content)).mode, "RGB")
            workspace = Path(temporary) / "tmp"
            self.assertTrue(workspace.is_dir())
            self.assertEqual(stat.S_IMODE(workspace.stat().st_mode), 0o700)
            self.assertEqual(list(workspace.iterdir()), [])

    def test_invalid_options_fail_before_model_load(self) -> None:
        source = encoded(Image.new("RGB", (2, 2), "red"), "PNG")
        loader = Mock(return_value=(FakeModel(), "cpu"))
        with patch.object(service_app, "load_model", loader):
            response = self.post(source, data={"output": "mask", "background": "white"})
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json()["error"], "invalid_parameter")
        loader.assert_not_called()

    def test_malformed_unsupported_and_oversized_inputs_use_approved_errors(self) -> None:
        gif = encoded(Image.new("RGB", (2, 2), "red"), "GIF")
        with patch.object(service_app, "load_model") as loader:
            malformed = self.post(b"not an image")
            unsupported = self.post(gif, "source.gif")
            with patch.dict(os.environ, {"BIREFNET_MAX_UPLOAD_MB": "1"}):
                oversized = self.post(b"x" * (1024 * 1024 + 1))
        self.assertEqual((malformed.status_code, malformed.json()["error"]), (400, "invalid_image"))
        self.assertEqual((unsupported.status_code, unsupported.json()["error"]), (415, "unsupported_media_type"))
        self.assertEqual((oversized.status_code, oversized.json()["error"]), (413, "payload_too_large"))
        loader.assert_not_called()

    def test_cuda_out_of_memory_resets_and_retries_complete_request_on_cpu_once(self) -> None:
        source = encoded(Image.new("RGB", (2, 2), "red"), "PNG")
        load = Mock(side_effect=[(object(), "cuda"), (object(), "cpu")])
        mask = Image.new("L", (2, 2), 128)
        with patch.object(service_app, "load_model", load), \
             patch.object(service_app, "reset_model") as reset, \
             patch.object(service_app, "infer_alpha", side_effect=[torch.cuda.OutOfMemoryError("oom"), mask]):
            response = self.post(source)

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.headers["x-3waaihub-device"], "cpu")
        self.assertEqual(load.call_count, 2)
        reset.assert_called_once_with()
        retry_environment = load.call_args_list[1].kwargs["environment"]
        self.assertEqual(retry_environment["BIREFNET_USE_GPU"], "0")
        self.assertEqual(retry_environment["BIREFNET_DEVICE"], "cpu")

    def test_cpu_failure_and_timeout_return_sanitized_json(self) -> None:
        source = encoded(Image.new("RGB", (2, 2), "red"), "PNG")
        with tempfile.TemporaryDirectory() as temporary, patch.dict(os.environ, {
            "BIREFNET_SERVICE_DATA_DIR": temporary,
        }):
            with patch.object(service_app, "load_model", return_value=(FakeModel(RuntimeError("secret/source.png")), "cpu")):
                failed = self.post(source)
            self.assertEqual(failed.status_code, 500)
            self.assertEqual(failed.json()["error"], "inference_failed")
            self.assertNotIn("secret", failed.text)
            self.assertFalse(failed.content.startswith(b"\x89PNG"))
            self.assertEqual(list((Path(temporary) / "tmp").iterdir()), [])

            with patch.object(service_app, "load_model", return_value=(object(), "cpu")), \
                 patch.object(service_app, "infer_alpha", side_effect=TimeoutError("late /private/path")):
                timed_out = self.post(source)
            self.assertEqual(timed_out.status_code, 504)
            self.assertEqual(timed_out.json()["error"], "inference_timeout")
            self.assertNotIn("private", timed_out.text)
            self.assertEqual(list((Path(temporary) / "tmp").iterdir()), [])


if __name__ == "__main__":
    unittest.main()
