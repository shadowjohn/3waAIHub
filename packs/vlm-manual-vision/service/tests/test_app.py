from __future__ import annotations

import hashlib
import io
import json
import os
import sys
import tempfile
import types
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


class FakeInputIds(FakeTensor):
    shape = (1, 4)

    def __init__(self) -> None:
        super().__init__(False)


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
        self.input_ids = FakeInputIds()
        self.attention_mask = FakeTensor(False)

    def __call__(self, **kwargs: object) -> dict[str, object]:
        self.calls.append(kwargs)
        return {
            "input_ids": self.input_ids,
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


def write_verified_snapshot(root: Path, weight: bytes = b"weights") -> vision.VerifiedSnapshot:
    revision = "a" * 40
    snapshot = root / "revisions" / revision / "snapshot"
    snapshot.mkdir(parents=True, exist_ok=True)
    files = {
        "added_tokens.json": b"{}", "config.json": b"{}", "generation_config.json": b"{}",
        "model-00001-of-00002.safetensors": weight, "model-00002-of-00002.safetensors": b"weights-two",
        "model.safetensors.index.json": json.dumps({"weight_map": {"one": "model-00001-of-00002.safetensors", "two": "model-00002-of-00002.safetensors"}}).encode(),
        "preprocessor_config.json": b"{}", "special_tokens_map.json": b"{}", "tokenizer.json": b"{}",
        "tokenizer.model": b"tokenizer", "tokenizer_config.json": b"{}",
    }
    for name, data in files.items():
        (snapshot / name).write_bytes(data)
    manifest = {
        "snapshot": f"revisions/{revision}/snapshot",
        "files": [
            {"path": name, "sha256": hashlib.sha256(data).hexdigest()}
            for name, data in sorted(files.items())
        ],
    }
    manifest_path = root / "verified-snapshot.json"
    manifest_path.write_text(json.dumps(manifest), encoding="utf-8")
    return vision.VerifiedSnapshot(snapshot, hashlib.sha256(manifest_path.read_bytes()).hexdigest())


class ManualVisionTests(unittest.TestCase):
    def setUp(self) -> None:
        vision._RUNTIME = None
        vision._VERIFIED_IDENTITY = None
        vision._TRUSTED_FILES = ()

    def tearDown(self) -> None:
        vision._RUNTIME = None
        vision._VERIFIED_IDENTITY = None
        vision._TRUSTED_FILES = ()

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
        self.assertEqual({"device": "cuda"}, processor.input_ids.calls[0])
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
                (root / "revisions" / ("a" * 40) / "snapshot").mkdir(parents=True)
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

    def test_reader_rejects_nonrevision_marker_paths_and_incomplete_runtime_files(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            snapshot = write_verified_snapshot(root)
            marker = root / "verified-snapshot.json"
            payload = json.loads(marker.read_text(encoding="utf-8"))
            payload["snapshot"] = "revisions/../escape/snapshot"
            marker.write_text(json.dumps(payload), encoding="utf-8")
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()
            snapshot = write_verified_snapshot(root)
            snapshot.path.joinpath("tokenizer.model").unlink()
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()

    def test_marker_identity_and_snapshot_path_come_from_the_same_atomic_marker_read(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            first = write_verified_snapshot(root)
            marker = root / "verified-snapshot.json"
            first_raw = marker.read_bytes()
            second_revision = "b" * 40
            second_snapshot = root / "revisions" / second_revision / "snapshot"
            second_snapshot.mkdir(parents=True)
            for source in first.path.iterdir():
                (second_snapshot / source.name).write_bytes(source.read_bytes())
            second_manifest = json.loads(first_raw)
            second_manifest["snapshot"] = f"revisions/{second_revision}/snapshot"
            replacement = root / ".replacement-marker"
            replacement.write_text(json.dumps(second_manifest), encoding="utf-8")
            second_raw = replacement.read_bytes()
            original_read_bytes = Path.read_bytes

            def replace_before_marker_read(path: Path, *args: object, **kwargs: object) -> bytes:
                if path == marker:
                    os.replace(replacement, marker)
                return original_read_bytes(path, *args, **kwargs)

            with patch.object(Path, "read_bytes", replace_before_marker_read), \
                 patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                identity = vision.verified_snapshot()
            self.assertEqual(second_snapshot, identity.path)
            self.assertEqual(hashlib.sha256(second_raw).hexdigest(), identity.manifest_sha256)

    def test_direct_model_and_acceptance_symlinks_fail_closed(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            target = Path(temporary) / "target"
            target.mkdir()
            try:
                root.symlink_to(target, target_is_directory=True)
            except OSError as exc:
                self.skipTest(f"symlinks unavailable: {exc}")
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()

            root.unlink()
            snapshot = write_verified_snapshot(root)
            actual_snapshot = root / "revisions" / ("a" * 40) / "snapshot"
            actual_snapshot.rename(root / "revisions" / ("a" * 40) / "snapshot-real")
            actual_snapshot.symlink_to(root / "revisions" / ("a" * 40) / "snapshot-real", target_is_directory=True)
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()
            actual_snapshot.unlink()
            (root / "revisions" / ("a" * 40) / "snapshot-real").rename(actual_snapshot)
            manifest = root / "verified-snapshot.json"
            manifest.rename(root / "manifest-real.json")
            manifest.symlink_to(root / "manifest-real.json")
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()
            manifest.unlink()
            (root / "manifest-real.json").rename(manifest)
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                snapshot = vision.verified_snapshot()
            acceptance_target = Path(temporary) / "acceptance.json"
            acceptance_target.write_text(json.dumps({"accepted": True, "manifest_sha256": snapshot.manifest_sha256}), encoding="utf-8")
            (data / "manual-vision-acceptance.json").symlink_to(acceptance_target)
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                    vision.runtime_accepted(snapshot)
            data.rename(Path(temporary) / "service-real")
            data.symlink_to(Path(temporary) / "service-real", target_is_directory=True)
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                    vision.runtime_accepted(snapshot)

    def test_cached_runtime_uses_manifest_identity_without_rehashing_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            snapshot = write_verified_snapshot(root)
            (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": snapshot.manifest_sha256}), encoding="utf-8")
            vision._RUNTIME = (snapshot.manifest_sha256, object(), object())
            vision._VERIFIED_IDENTITY = snapshot.manifest_sha256
            try:
                with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False), \
                     patch.object(vision, "_hash_file", side_effect=AssertionError("cache must not rehash")):
                    self.assertEqual(vision._RUNTIME[1:], vision.load_runtime(torch_module=FakeTorch()))
            finally:
                vision._RUNTIME = None
                vision._VERIFIED_IDENTITY = None

    def test_acceptance_loader_can_use_a_verified_snapshot_before_acceptance_exists(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            snapshot = write_verified_snapshot(root)
            vision._RUNTIME = (snapshot.manifest_sha256, object(), object())
            vision._VERIFIED_IDENTITY = snapshot.manifest_sha256
            try:
                with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                    with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                        vision.load_runtime(torch_module=FakeTorch())
                    self.assertEqual(vision._RUNTIME[1:], vision.load_runtime(torch_module=FakeTorch(), require_acceptance=False))
            finally:
                vision._RUNTIME = None
                vision._VERIFIED_IDENTITY = None

    def test_loader_rechecks_snapshot_identity_after_loading_before_acceptance_can_bind_it(self) -> None:
        class LoaderTorch(FakeTorch):
            float16 = "float16"

        class LoaderModel:
            def to(self, _device: str) -> "LoaderModel":
                return self

            def eval(self) -> None:
                pass

        class LoaderProcessor:
            pass

        first = vision.VerifiedSnapshot(Path("/snapshot-a"), "a" * 64)
        second = vision.VerifiedSnapshot(Path("/snapshot-b"), "b" * 64)
        transformers = types.SimpleNamespace(
            PaliGemmaForConditionalGeneration=types.SimpleNamespace(from_pretrained=lambda *_args, **_kwargs: LoaderModel()),
            PaliGemmaProcessor=types.SimpleNamespace(from_pretrained=lambda *_args, **_kwargs: LoaderProcessor()),
        )
        with patch.object(vision, "process_verified_snapshot", side_effect=[first, second]), \
             patch.dict(sys.modules, {"transformers": transformers}):
            with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                vision.load_runtime(torch_module=LoaderTorch(), require_acceptance=False, return_identity=True)

    def test_process_verification_hashes_once_and_rejects_unverified_or_changed_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            snapshot = write_verified_snapshot(root)
            (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": snapshot.manifest_sha256}), encoding="utf-8")
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False):
                vision._VERIFIED_IDENTITY = None
                try:
                    (root / "revisions" / ("a" * 40) / "snapshot" / "model-00001-of-00002.safetensors").write_bytes(b"tampered")
                    with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                        vision.process_verified_snapshot()
                    write_verified_snapshot(root)
                    calls = 0
                    original_hash = vision._hash_file

                    def counted(path: Path) -> str:
                        nonlocal calls
                        calls += 1
                        return original_hash(path)

                    with patch.object(vision, "_hash_file", side_effect=counted):
                        first = vision.process_verified_snapshot()
                        first_calls = calls
                        self.assertEqual(first, vision.process_verified_snapshot())
                    self.assertGreater(first_calls, 0)
                    self.assertEqual(first_calls, calls)
                    (root / "revisions" / ("a" * 40) / "snapshot" / "model-00001-of-00002.safetensors").unlink()
                    with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                        vision.process_verified_snapshot()
                    vision._VERIFIED_IDENTITY = None
                    vision._TRUSTED_FILES = ()
                    write_verified_snapshot(root)
                    vision.process_verified_snapshot()
                    (root / "revisions" / ("a" * 40) / "snapshot" / "model-00001-of-00002.safetensors").write_bytes(b"replaced")
                    with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                        vision.process_verified_snapshot()
                    write_verified_snapshot(root, b"changed")
                    with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                        vision.process_verified_snapshot()
                finally:
                    vision._VERIFIED_IDENTITY = None

    def test_snapshot_verifier_rejects_symlinked_directories(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            write_verified_snapshot(root)
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root)}, clear=False):
                try:
                    outside = Path(temporary) / "outside"
                    outside.mkdir()
                    (root / "revisions" / ("a" * 40) / "snapshot" / "linked").symlink_to(outside, target_is_directory=True)
                except OSError as exc:
                    self.skipTest(f"symlinks unavailable: {exc}")
                with self.assertRaisesRegex(vision.ServiceError, "model_manifest_invalid"):
                    vision.verified_snapshot()

    def test_new_verified_manifest_identity_requires_restart_without_second_loader(self) -> None:
        class LoadedModel:
            dtype = "float16"

            def __init__(self) -> None:
                self.device = ""
                self.evaluated = False

            def to(self, _device: str) -> "LoadedModel":
                self.device = _device
                return self

            def eval(self) -> "LoadedModel":
                self.evaluated = True
                return self

        class Loader:
            calls: list[tuple[str, dict[str, object]]] = []
            model: LoadedModel | None = None

            @classmethod
            def from_pretrained(cls, path: str, **_kwargs: object) -> object:
                cls.calls.append((path, _kwargs))
                if len(cls.calls) % 2:
                    return object()
                cls.model = LoadedModel()
                return cls.model

        torch = FakeTorch()
        torch.float16 = "float16"
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary) / "models"
            data = Path(temporary) / "service"
            data.mkdir()
            with patch.dict(os.environ, {"MANUAL_VISION_MODEL_DIR": str(root), "MANUAL_VISION_SERVICE_DATA_DIR": str(data)}, clear=False), \
                 patch.dict(sys.modules, {"transformers": types.SimpleNamespace(PaliGemmaProcessor=Loader, PaliGemmaForConditionalGeneration=Loader)}):
                first_snapshot = write_verified_snapshot(root, b"first")
                (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": first_snapshot.manifest_sha256}), encoding="utf-8")
                vision._RUNTIME = None
                try:
                    first = vision.load_runtime(torch_module=torch)
                    second_snapshot = write_verified_snapshot(root, b"second")
                    (data / "manual-vision-acceptance.json").write_text(json.dumps({"accepted": True, "manifest_sha256": second_snapshot.manifest_sha256}), encoding="utf-8")
                    with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                        vision.load_runtime(torch_module=torch)
                finally:
                    vision._RUNTIME = None
        self.assertEqual(2, len(Loader.calls))
        self.assertEqual({"local_files_only": True}, Loader.calls[0][1])
        self.assertEqual({"torch_dtype": "float16", "local_files_only": True}, Loader.calls[1][1])
        self.assertEqual(("cuda", True), (Loader.model.device, Loader.model.evaluated))

    def test_runtime_guards_and_decode_failure_use_approved_errors(self) -> None:
        snapshot = vision.VerifiedSnapshot(Path("/models/manual-vision/snapshot"), "a" * 64)
        with patch.object(vision, "process_verified_snapshot", side_effect=vision.ServiceError("model_not_provisioned")):
            with self.assertRaisesRegex(vision.ServiceError, "model_not_provisioned"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "process_verified_snapshot", return_value=snapshot), patch.object(vision, "runtime_accepted", return_value=False):
            with self.assertRaisesRegex(vision.ServiceError, "runtime_not_ready"):
                vision.load_runtime(torch_module=FakeTorch())
        with patch.object(vision, "process_verified_snapshot", return_value=snapshot), patch.object(vision, "runtime_accepted", return_value=True):
            with self.assertRaisesRegex(vision.ServiceError, "gpu_unavailable"):
                vision.load_runtime(torch_module=FakeTorch(False))
        with self.assertRaisesRegex(vision.ServiceError, "inference_failed"):
            vision.run_docvqa(Image.open(io.BytesIO(png_bytes())), "What is this?", processor=FakeProcessor(), model=FakeModel(RuntimeError("decode failed")))

    def test_cached_runtime_still_requires_cuda(self) -> None:
        snapshot = vision.VerifiedSnapshot(Path("/models/manual-vision/snapshot"), "b" * 64)
        vision._RUNTIME = (snapshot.manifest_sha256, object(), object())
        try:
            with patch.object(vision, "process_verified_snapshot", return_value=snapshot), patch.object(vision, "runtime_accepted", return_value=True):
                with self.assertRaisesRegex(vision.ServiceError, "gpu_unavailable"):
                    vision.load_runtime(torch_module=FakeTorch(False))
        finally:
            vision._RUNTIME = None

    def test_image_requires_png_or_jpeg_signature_and_decode(self) -> None:
        self.assertEqual((2, 2), vision.decode_image(png_bytes()).size)
        with patch.object(vision, "MAX_DECODED_PIXELS", 3):
            with self.assertRaisesRegex(vision.ServiceError, "bad_image"):
                vision.decode_image(png_bytes())
        for data in (b"not an image", b"GIF89a"):
            with self.subTest(data=data):
                with self.assertRaisesRegex(vision.ServiceError, "bad_image"):
                    vision.decode_image(data)


if __name__ == "__main__":
    unittest.main()
