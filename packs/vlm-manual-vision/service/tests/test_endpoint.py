from __future__ import annotations

import io
import sys
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


if __name__ == "__main__":
    unittest.main()
