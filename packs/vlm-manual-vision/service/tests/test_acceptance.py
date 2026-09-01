from __future__ import annotations

import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import Image


SERVICE_DIR = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SERVICE_DIR))
import acceptance  # noqa: E402


class FakeCuda:
    def __init__(self, *, free: int = 2 * 1024**3, peak: int = 1024**3) -> None:
        self.free = free
        self.peak = peak

    def reset_peak_memory_stats(self) -> None:
        pass

    def max_memory_allocated(self) -> int:
        return self.peak

    def mem_get_info(self) -> tuple[int, int]:
        return self.free, 8 * 1024**3


class AcceptanceTests(unittest.TestCase):
    def setUp(self) -> None:
        self.cases_path = SERVICE_DIR.parent / "demo" / "acceptance_cases.json"
        self.fixtures_dir = self.cases_path.parent

    def test_committed_cases_are_exact_and_normalization_only_changes_ascii_whitespace(self) -> None:
        cases = acceptance.load_cases(self.cases_path)
        self.assertEqual([
            ("manual-text", "What is the shutdown temperature?", "85 °C"),
            ("spec-table", "What is the rated capacity?", "1.2 L"),
            ("labelled-diagram", "What component is marked A?", "Fuse"),
        ], [(case["id"], case["question"], case["answer"]) for case in cases])
        self.assertEqual("A B", acceptance.normalize_answer(" \tA\r\nB \f"))
        self.assertEqual("Fuse!", acceptance.normalize_answer("Fuse!"))
        self.assertEqual("85 °C", acceptance.normalize_answer("85 °C"))

    def test_success_binds_manifest_and_records_each_fixed_fixture_without_secret(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            data_root = Path(temporary)
            expected = {case["question"]: case["answer"] for case in acceptance.load_cases(self.cases_path)}
            accepted = acceptance.run_acceptance(
                infer=lambda _image, question: expected[question],
                manifest_sha256="b" * 64,
                model_revision="a" * 40,
                dtype="float16",
                data_root=data_root,
                cases_path=self.cases_path,
                fixtures_dir=self.fixtures_dir,
                cuda=FakeCuda(),
                timestamp="2026-09-02T00:00:00Z",
            )
            self.assertTrue(accepted)
            record = json.loads((data_root / acceptance.RECORD_NAME).read_text(encoding="utf-8"))
            self.assertTrue(record["accepted"])
            self.assertEqual("b" * 64, record["manifest_sha256"])
            self.assertEqual("a" * 40, record["model_revision"])
            self.assertEqual("float16", record["dtype"])
            self.assertEqual("2026-09-02T00:00:00Z", record["timestamp"])
            self.assertNotIn("HF_TOKEN", json.dumps(record))
            self.assertEqual(3, len(record["cases"]))
            for row in record["cases"]:
                self.assertEqual(64, len(row["fixture_sha256"]))
                self.assertIn("answer", row)
                self.assertIsInstance(row["cold_elapsed_ms"], int)
                self.assertIsInstance(row["warm_elapsed_ms"], int)
                self.assertGreaterEqual(row["remaining_vram_bytes"], acceptance.MIN_REMAINING_VRAM_BYTES)

    def test_bad_answers_empty_answers_oom_and_low_vram_replace_success_with_failure(self) -> None:
        cases = acceptance.load_cases(self.cases_path)
        for name, infer, cuda in (
            ("wrong", lambda _image, _question: "wrong", FakeCuda()),
            ("empty", lambda _image, _question: "  \t", FakeCuda()),
            ("oom", lambda _image, _question: (_ for _ in ()).throw(RuntimeError("CUDA out of memory")), FakeCuda()),
            ("low-vram", lambda _image, question: next(case["answer"] for case in cases if case["question"] == question), FakeCuda(free=511 * 1024**2)),
        ):
            with self.subTest(name=name), tempfile.TemporaryDirectory() as temporary:
                data_root = Path(temporary)
                (data_root / acceptance.RECORD_NAME).write_text(json.dumps({"accepted": True, "manifest_sha256": "stale"}), encoding="utf-8")
                accepted = acceptance.run_acceptance(
                    infer=infer,
                    manifest_sha256="c" * 64,
                    model_revision="a" * 40,
                    dtype="float16",
                    data_root=data_root,
                    cases_path=self.cases_path,
                    fixtures_dir=self.fixtures_dir,
                    cuda=cuda,
                )
                self.assertFalse(accepted)
                self.assertFalse(json.loads((data_root / acceptance.RECORD_NAME).read_text(encoding="utf-8"))["accepted"])


if __name__ == "__main__":
    unittest.main()
