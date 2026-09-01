from __future__ import annotations

import io
import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR))
import app as vision  # noqa: E402


def png_bytes() -> bytes:
    output = io.BytesIO()
    Image.new("RGB", (2, 2), "white").save(output, format="PNG")
    return output.getvalue()


class FakeInputs(dict):
    def to(self, device: str) -> "FakeInputs":
        self["device"] = device
        return self


class FakeProcessor:
    def __init__(self, decoded: str = "  42 liters  ") -> None:
        self.decoded = decoded
        self.calls: list[dict[str, object]] = []

    def __call__(self, **kwargs: object) -> FakeInputs:
        self.calls.append(kwargs)
        return FakeInputs()

    def decode(self, _tokens: object, **_kwargs: object) -> str:
        return self.decoded


class FakeModel:
    def __init__(self, error: Exception | None = None) -> None:
        self.error = error
        self.calls: list[dict[str, object]] = []

    def generate(self, **kwargs: object) -> list[list[int]]:
        self.calls.append(kwargs)
        if self.error is not None:
            raise self.error
        return [[1, 2, 3]]


class FakeCuda:
    def __init__(self, available: bool) -> None:
        self.available = available

    def is_available(self) -> bool:
        return self.available


class FakeTorch:
    def __init__(self, available: bool = True) -> None:
        self.cuda = FakeCuda(available)


class ManualVisionTests(unittest.TestCase):
    def test_parse_request_trims_question_and_formats_exact_paligemma1_prompt(self) -> None:
        request = vision.parse_request({"operation": "docvqa", "question": "  What is the rated capacity?  "})
        self.assertEqual(request.question, "What is the rated capacity?")
        self.assertEqual("answer en What is the rated capacity?", vision.format_docvqa_prompt(request.question))

    def test_request_validation_rejects_contract_escapes(self) -> None:
        bad_questions = ["", "  ", "na\u00efve", "12345", "a" * 401]
        for question in bad_questions:
            with self.subTest(question=question):
                with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                    vision.parse_request({"operation": "docvqa", "question": question})

        with self.assertRaisesRegex(vision.ServiceError, "unsupported_operation"):
            vision.parse_request({"operation": "caption", "question": "Describe this"})

        forbidden = ["file", "image2", "prompt", "model", "revision", "max_tokens", "temperature", "device", "path", "url", "unknown"]
        for field in forbidden:
            with self.subTest(field=field):
                with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                    vision.validate_form_keys({"operation", "image", "question", field})

        with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
            vision.validate_form_keys(["operation", "image", "image", "question"])

    def test_generation_uses_server_owned_limit_and_exact_processor_text(self) -> None:
        processor = FakeProcessor()
        model = FakeModel()
        answer = vision.run_docvqa(
            Image.open(io.BytesIO(png_bytes())),
            "What is the rated capacity?",
            processor=processor,
            model=model,
            torch_module=FakeTorch(),
        )
        self.assertEqual("42 liters", answer)
        self.assertEqual([{"text": "answer en What is the rated capacity?", "images": unittest.mock.ANY, "return_tensors": "pt"}], processor.calls)
        self.assertEqual(64, model.calls[0]["max_new_tokens"])
        with patch.dict(os.environ, {"MANUAL_VISION_MAX_NEW_TOKENS": "129"}, clear=False):
            with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                vision.configured_max_new_tokens()

    def test_success_payload_exposes_only_the_public_contract(self) -> None:
        payload = vision.success_response("answer", 12, "req_test")
        self.assertEqual(
            {"ok", "mode", "operation", "answer", "answer_language", "contract_revision", "elapsed_ms", "request_id"},
            set(payload),
        )
        self.assertEqual("en", payload["answer_language"])
        self.assertEqual(1, payload["contract_revision"])

    def test_decoded_prompt_is_not_exposed_as_the_answer(self) -> None:
        answer = vision.run_docvqa(
            Image.open(io.BytesIO(png_bytes())),
            "What is this?",
            processor=FakeProcessor("answer en What is this?  a valve  "),
            model=FakeModel(),
            torch_module=FakeTorch(),
        )
        self.assertEqual("a valve", answer)

    def test_runtime_guards_and_decode_failure_use_approved_errors(self) -> None:
        with patch.object(vision, "verified_snapshot", return_value=None):
            with self.assertRaisesRegex(vision.ServiceError, "model_not_provisioned"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "verified_snapshot", return_value=Path("/models/manual-vision/snapshot")), \
             patch.object(vision, "runtime_accepted", return_value=False):
            with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "verified_snapshot", return_value=Path("/models/manual-vision/snapshot")), \
             patch.object(vision, "runtime_accepted", return_value=True):
            with self.assertRaisesRegex(vision.ServiceError, "gpu_unavailable"):
                vision.load_runtime(torch_module=FakeTorch(False))
        with self.assertRaisesRegex(vision.ServiceError, "inference_failed"):
            vision.run_docvqa(Image.open(io.BytesIO(png_bytes())), "What is this?", processor=FakeProcessor(), model=FakeModel(RuntimeError("decode failed")), torch_module=FakeTorch())

    def test_image_requires_png_or_jpeg_signature_and_decode(self) -> None:
        self.assertEqual((2, 2), vision.decode_image(png_bytes()).size)
        for data in (b"not an image", b"GIF89a"):
            with self.subTest(data=data):
                with self.assertRaisesRegex(vision.ServiceError, "bad_image"):
                    vision.decode_image(data)


if __name__ == "__main__":
    unittest.main()
