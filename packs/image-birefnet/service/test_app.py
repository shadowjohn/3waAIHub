from __future__ import annotations

import hashlib
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import model_runtime as app


class FakeCuda:
    def __init__(self, available: bool) -> None:
        self.available = available
        self.empty_cache_calls = 0

    def is_available(self) -> bool:
        return self.available

    def empty_cache(self) -> None:
        self.empty_cache_calls += 1


class FakeTorch:
    def __init__(self, cuda_available: bool) -> None:
        self.cuda = FakeCuda(cuda_available)


class FakeModel:
    def __init__(self, fail_device: str | None = None) -> None:
        self.fail_device = fail_device
        self.to_calls: list[str] = []
        self.eval_calls = 0
        self.half_calls = 0

    def to(self, device: str) -> "FakeModel":
        self.to_calls.append(device)
        if device == self.fail_device:
            raise RuntimeError("private /models/birefnet failure")
        return self

    def eval(self) -> "FakeModel":
        self.eval_calls += 1
        return self

    def half(self) -> "FakeModel":
        self.half_calls += 1
        return self


class RecordingFactory:
    def __init__(self, results: list[FakeModel | Exception]) -> None:
        self.results = list(results)
        self.calls: list[tuple[str, dict[str, object]]] = []

    def __call__(self, snapshot: str, **kwargs: object) -> FakeModel:
        self.calls.append((snapshot, kwargs))
        result = self.results.pop(0)
        if isinstance(result, Exception):
            raise result
        return result


class ModelLoaderTest(unittest.TestCase):
    def setUp(self) -> None:
        app.reset_model()

    def tearDown(self) -> None:
        app.reset_model()

    def ready_model_root(self, temporary: str, revision: str = app.MODEL_REVISION) -> Path:
        root = Path(temporary)
        snapshot = root / "snapshot"
        snapshot.mkdir(parents=True)
        files = {
            "config.json": b"{}",
            "model.safetensors": b"weights",
            "birefnet.py": b"class BiRefNet: pass\n",
        }
        rows = []
        for name, content in files.items():
            (snapshot / name).write_bytes(content)
            rows.append({
                "path": name,
                "size": len(content),
                "sha256": hashlib.sha256(content).hexdigest(),
            })
        (root / "ready.json").write_text(json.dumps({
            "repository": app.MODEL_REPOSITORY,
            "revision": revision,
            "created_at": "2026-07-23T00:00:00Z",
            "files": sorted(rows, key=lambda row: row["path"]),
        }), encoding="utf-8")
        return root

    def load(self, root: Path, torch: FakeTorch, factory: RecordingFactory, **environment: str):
        values = {
            "BIREFNET_USE_GPU": "1",
            "BIREFNET_DEVICE": "auto",
            "BIREFNET_CPU_FALLBACK": "1",
            **environment,
        }
        return app.load_model(
            model_root=root,
            torch_module=torch,
            model_factory=factory,
            environment=values,
        )

    def test_auto_prefers_cuda_when_available(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            model = FakeModel()
            factory = RecordingFactory([model])
            loaded, device = self.load(root, FakeTorch(True), factory)
            cached, cached_device = self.load(root, FakeTorch(True), factory)

            self.assertIs(loaded, model)
            self.assertIs(cached, model)
            self.assertEqual((device, cached_device), ("cuda", "cuda"))
            self.assertEqual(model.to_calls, ["cuda"])
            self.assertEqual((model.eval_calls, model.half_calls), (1, 1))
            self.assertEqual(len(factory.calls), 1)

    def test_auto_uses_cpu_when_cuda_is_unavailable(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            model = FakeModel()
            loaded, device = self.load(root, FakeTorch(False), RecordingFactory([model]))

            self.assertIs(loaded, model)
            self.assertEqual(device, "cpu")
            self.assertEqual(model.to_calls, ["cpu"])
            self.assertEqual((model.eval_calls, model.half_calls), (1, 0))

    def test_cuda_load_failure_releases_cache_then_retries_cpu_once(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            failed = FakeModel(fail_device="cuda")
            fallback = FakeModel()
            factory = RecordingFactory([failed, fallback])
            torch = FakeTorch(True)
            with patch("model_runtime.gc.collect") as collect:
                loaded, device = self.load(root, torch, factory)

            self.assertIs(loaded, fallback)
            self.assertEqual(device, "cpu")
            self.assertEqual(len(factory.calls), 2)
            collect.assert_called_once_with()
            self.assertEqual(torch.cuda.empty_cache_calls, 1)

    def test_cpu_retry_failure_reports_model_load_failed_without_paths(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            factory = RecordingFactory([
                FakeModel(fail_device="cuda"),
                RuntimeError("secret /models/birefnet/snapshot/model.safetensors"),
            ])
            with self.assertRaises(app.ModelRuntimeError) as raised:
                self.load(root, FakeTorch(True), factory)

            self.assertEqual(raised.exception.code, "model_load_failed")
            self.assertEqual(str(raised.exception), "model_load_failed")
            self.assertEqual(len(factory.calls), 2)

    def test_ready_revision_must_match_the_pinned_revision(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary, revision="wrong")
            factory = RecordingFactory([FakeModel()])
            with self.assertRaisesRegex(app.ModelRuntimeError, "model_load_failed"):
                self.load(root, FakeTorch(True), factory)
            self.assertEqual(factory.calls, [])

    def test_loader_uses_local_files_only_and_snapshot_path(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            factory = RecordingFactory([FakeModel()])
            self.load(root, FakeTorch(False), factory)

            self.assertEqual(factory.calls, [(
                str(root / "snapshot"),
                {"trust_remote_code": True, "local_files_only": True},
            )])

    def test_checksum_failure_prevents_model_factory_call(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            (root / "snapshot" / "model.safetensors").write_bytes(b"tampered")
            factory = RecordingFactory([FakeModel()])
            with self.assertRaisesRegex(app.ModelRuntimeError, "model_load_failed"):
                self.load(root, FakeTorch(True), factory)
            self.assertEqual(factory.calls, [])

    def test_unlisted_snapshot_file_prevents_model_factory_call(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = self.ready_model_root(temporary)
            (root / "snapshot" / "unexpected.py").write_text("raise RuntimeError\n", encoding="utf-8")
            factory = RecordingFactory([FakeModel()])
            with self.assertRaisesRegex(app.ModelRuntimeError, "model_load_failed"):
                self.load(root, FakeTorch(True), factory)
            self.assertEqual(factory.calls, [])


if __name__ == "__main__":
    unittest.main()
