from __future__ import annotations

import hashlib
import io
import json
import os
import sys
import tempfile
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


class FakeTensor:
    def __init__(self, floating: bool) -> None:
        self.floating = floating
        self.calls: list[dict[str, object]] = []

    def is_floating_point(self) -> bool:
        return self.floating

    def to(self, **kwargs: object) -> "FakeTensor":
        self.calls.append(kwargs)
        return self


class FakeInputIds:
    shape = (1, 4)


class FakeGenerated:
    def __init__(self) -> None:
        self.slices: list[object] = []

    def __getitem__(self, key: object) -> list[list[str]]:
        self.slices.append(key)
        return [["continuation"]]


class FakeProcessor:
    def __init__(self, decoded: str = "  42 liters  ") -> None:
        self.decoded = decoded
        self.calls: list[dict[str, object]] = []
        self.decode_calls: list[object] = []
        self.pixel_values = FakeTensor(True)
        self.attention_mask = FakeTensor(False)

    def __call__(self, **kwargs: object) -> dict[str, object]:
        self.calls.append(kwargs)
        return {
            "input_ids": FakeInputIds(),
            "pixel_values": self.pixel_values,
            "attention_mask": self.attention_mask,
        }

    def decode(self, tokens: object, **_kwargs: object) -> str:
        self.decode_calls.append(tokens)
        return self.decoded


class FakeModel:
    dtype = "float16"

    def __init__(self, error: Exception | None = None) -> None:
        self.error = error
        self.calls: list[dict[str, object]] = []
        self.generated = FakeGenerated()

    def generate(self, **kwargs: object) -> FakeGenerated:
        self.calls.append(kwargs)
        if self.error is not None:
            raise self.error
        return self.generated


class FakeCuda:
    def __init__(self, available: bool) -> None:
        self.available = available

    def is_available(self) -> bool:
        return self.available


class FakeTorch:
    def __init__(self, available: bool = True) -> None:
        self.cuda = FakeCuda(available)


def write_verified_snapshot(root: Path) -> vision.VerifiedSnapshot:
    snapshot = root / "snapshot"
    snapshot.mkdir(parents=True, exist_ok=True)
    files = {"config.json": b"{}", "model.safetensors": b"weights"}
    for name, data in files.items():
        (snapshot / name).write_bytes(data)
    manifest = {
        "snapshot": "snapshot",
        "files": [
            {"path": name, "sha256": hashlib.sha256(data).hexdigest()}
            for name, data in sorted(files.items())
        ],
    }
    manifest_path = root / "verified-snapshot.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
    return vision.VerifiedSnapshot(snapshot, hashlib.sha256(manifest_path.read_bytes()).hexdigest())


class ManualVisionTests(unittest.TestCase):
    def test_parse_request_trims_ascii_whitespace_and_formats_exact_paligemma1_prompt(self) -> None:
        request = vision.parse_request({"operation": "docvqa", "question": " \tWhat is the rated capacity?\r\n"})
        self.assertEqual(request.question, "What is the rated capacity?")
        self.assertEqual("answer en What is the rated capacity?", vision.format_docvqa_prompt(request.question))

    def test_request_validation_rejects_contract_escapes(self) -> None:
        for question in ("", "  ", "na\u00efve", "\u00a0Question", "12345", "a" * 401):
            with self.subTest(question=question):
                with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                    vision.parse_request({"operation": "docvqa", "question": question})
        for operation in ("caption", None, 1, object()):
            with self.subTest(operation=operation):
                with self.assertRaisesRegex(vision.ServiceError, "bad_request" if not isinstance(operation, str) else "unsupported_operation"):
                    vision.parse_request({"operation": operation, "question": "Describe this"})

        forbidden = ["file", "image2", "prompt", "model", "revision", "max_tokens", "temperature", "device", "path", "url", "unknown"]
        for field in forbidden:
            with self.subTest(field=field):
                with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                    vision.validate_form_keys({"operation", "image", "question", field})
        with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
            vision.validate_form_keys(["operation", "image", "image", "question"])

    def test_generation_uses_configured_limit_moves_only_floats_to_model_dtype_and_decodes_continuation(self) -> None:
        processor = FakeProcessor()
        model = FakeModel()
        with patch.dict(os.environ, {"MANUAL_VISION_MAX_NEW_TOKENS": "17"}, clear=False):
            answer = vision.run_docvqa(
                Image.open(io.BytesIO(png_bytes())),
                "What is the rated capacity?",
                processor=processor,
                model=model,
                torch_module=FakeTorch(),
            )
        self.assertEqual("42 liters", answer)
        self.assertEqual([{"text": "answer en What is the rated capacity?", "images": unittest.mock.ANY, "return_tensors": "pt"}], processor.calls)
        self.assertEqual(17, model.calls[0]["max_new_tokens"])
        self.assertEqual({"device": "cuda", "dtype": "float16"}, processor.pixel_values.calls[0])
        self.assertEqual({"device": "cuda"}, processor.attention_mask.calls[0])
        self.assertEqual((slice(None), slice(4, None)), model.generated.slices[0])
        self.assertEqual(["continuation"], processor.decode_calls[0])
        with patch.dict(os.environ, {"MANUAL_VISION_MAX_NEW_TOKENS": "129"}, clear=False):
            with self.assertRaisesRegex(vision.ServiceError, "bad_request"):
                vision.configured_max_new_tokens()

    def test_continuation_decode_cannot_leak_a_normalized_prompt(self) -> None:
        processor = FakeProcessor("answer en WHAT IS THIS? a valve")
        model = FakeModel()
        answer = vision.run_docvqa(Image.open(io.BytesIO(png_bytes())), "What is this?", processor=processor, model=model)
        self.assertEqual("answer en WHAT IS THIS? a valve", answer)
        self.assertEqual((slice(None), slice(4, None)), model.generated.slices[0])

    def test_success_payload_exposes_only_the_public_contract(self) -> None:
        payload = vision.success_response("answer", 12, "req_test")
        self.assertEqual(
            {"ok", "mode", "operation", "answer", "answer_language", "contract_revision", "elapsed_ms", "request_id"},
            set(payload),
        )
        self.assertEqual("en", payload["answer_language"])
        self.assertEqual(1, payload["contract_revision"])

    def test_verified_snapshot_and_acceptance_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_not_provisioned"):
                    vision.verified_snapshot()
                (root / "snapshot").mkdir(parents=True)
                with self.assertRaisesRegex(vision.ServiceError, "model_not_provisioned"):
                    vision.verified_snapshot()
                (root / "verified-snapshot.json").write_text("{}", encoding="utf-8")
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()
                snapshot = write_verified_snapshot(root)
                self.assertEqual(snapshot, vision.verified_snapshot())
                (root / "acceptance.json").write_text('{"accepted": true}', encoding="utf-8")
                self.assertFalse(vision.runtime_accepted(snapshot))
                (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": False, "manifest_sha256": snapshot.manifest_sha256}), encoding="utf-8")
                self.assertFalse(vision.runtime_accepted(snapshot))
                (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": snapshot.manifest_sha256}), encoding="utf-8")
                self.assertTrue(vision.runtime_accepted(snapshot))

    def test_runtime_guards_and_decode_failure_use_approved_errors(self) -> None:
        snapshot = vision.VerifiedSnapshot(Path("/models/manual-vision/snapshot"), "a" * 64)
        with patch.object(vision, "verified_snapshot", side_effect=vision.ServiceError("model_not_provisioned")):
            with self.assertRaisesRegex(vision.ServiceError, "model_not_provisioned"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "verified_snapshot", return_value=snapshot), patch.object(vision, "runtime_accepted", return_value=False):
            with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "verified_snapshot", return_value=snapshot), patch.object(vision, "runtime_accepted", return_value=True):
            with self.assertRaisesRegex(vision.ServiceError, "gpu_unavailable"):
                vision.load_runtime(torch_module=FakeTorch(False))
        with self.assertRaisesRegex(vision.ServiceError, "inference_failed"):
            vision.run_docvqa(Image.open(io.BytesIO(png_bytes())), "What is this?", processor=FakeProcessor(), model=FakeModel(RuntimeError("decode failed")))

    def test_image_requires_png_or_jpeg_signature_and_decode(self) -> None:
        self.assertEqual((2, 2), vision.decode_image(png_bytes()).size)
        for data in (b"not an image", b"GIF89a"):
            with self.subTest(data=data):
                with self.assertRaisesRegex(vision.ServiceError, "bad_image"):
                    vision.decode_image(data)


if __name__ == "__main__":
    unittest.main()
