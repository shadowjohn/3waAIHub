from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from model_runtime import (
    MODEL_FILES,
    REAL_ESRGAN_COMMIT,
    REAL_ESRGAN_REPOSITORY,
    ModelRuntimeError,
    prepare_model,
    verify_ready,
)


class FakeModel:
    def __init__(self) -> None:
        self.calls: list[str] = []

    def to(self, backend: str):
        self.calls.append("to:" + backend)
        return self

    def eval(self):
        self.calls.append("eval")
        return self

    def half(self):
        self.calls.append("half")
        return self


def write_ready(root: Path) -> dict:
    files = []
    for index, name in enumerate(MODEL_FILES):
        data = f"model-{index}".encode("ascii")
        (root / name).write_bytes(data)
        files.append({
            "path": name,
            "size": len(data),
            "sha256": hashlib.sha256(data).hexdigest(),
            "url": f"https://github.com/xinntao/Real-ESRGAN/releases/download/v0.2.5.0/{name}",
        })
    marker = {"repository": REAL_ESRGAN_REPOSITORY, "commit": REAL_ESRGAN_COMMIT, "files": files}
    (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
    return marker


class ModelRuntimeTest(unittest.TestCase):
    def assert_code(self, code: str, callback) -> None:
        with self.assertRaisesRegex(ModelRuntimeError, f"^{code}$"):
            callback()

    def test_ready_marker_requires_exact_pinned_snapshot(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            marker = write_ready(root)
            self.assertEqual(verify_ready(root), marker)
            self.assertEqual(marker["commit"], "a4abfb2979a7bbff3f69f58f58ae324608821e27")
            self.assertEqual(set(row["path"] for row in marker["files"]), set(MODEL_FILES))

    def test_ready_marker_rejects_missing_or_tampered_assets_before_load(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            self.assert_code("model_not_present", lambda: verify_ready(root))
            marker = write_ready(root)
            cases = [
                ("repository", "other"),
                ("commit", "0" * 40),
            ]
            for key, value in cases:
                with self.subTest(key=key):
                    changed = dict(marker)
                    changed[key] = value
                    (root / "ready.json").write_text(json.dumps(changed), encoding="utf-8")
                    self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
            (root / MODEL_FILES[0]).write_bytes(b"altered")
            self.assert_code("model_load_failed", lambda: verify_ready(root))

    def test_ready_marker_rejects_wrong_name_size_checksum_symlink_and_unlisted_file(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            marker = write_ready(root)
            for field, value in (("path", "wrong.pth"), ("size", 999), ("sha256", "0" * 64), ("url", "http://example.invalid/model.pth")):
                with self.subTest(field=field):
                    changed = json.loads(json.dumps(marker))
                    changed["files"][0][field] = value
                    (root / "ready.json").write_text(json.dumps(changed), encoding="utf-8")
                    self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "ready.json").write_text(json.dumps(marker), encoding="utf-8")
            (root / "extra.pth").write_bytes(b"extra")
            self.assert_code("model_load_failed", lambda: verify_ready(root))
            (root / "extra.pth").unlink()
            target = root / MODEL_FILES[0]
            payload = target.read_bytes()
            target.unlink()
            (root / "outside.pth").write_bytes(payload)
            target.symlink_to(root / "outside.pth")
            self.assert_code("model_load_failed", lambda: verify_ready(root))

    def test_only_cuda_uses_half_precision(self) -> None:
        cpu = FakeModel()
        self.assertIs(prepare_model(cpu, "cpu"), cpu)
        self.assertEqual(cpu.calls, ["to:cpu", "eval"])
        cuda = FakeModel()
        self.assertIs(prepare_model(cuda, "cuda"), cuda)
        self.assertEqual(cuda.calls, ["to:cuda", "eval", "half"])
        self.assert_code("invalid_backend", lambda: prepare_model(FakeModel(), "auto"))


if __name__ == "__main__":
    unittest.main()
