#!/usr/bin/env python3
import contextlib
import hashlib
import importlib.util
import io
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SERVICE_DIR = Path(__file__).resolve().parent
SYNTH_SPEC = importlib.util.spec_from_file_location("synthesize", SERVICE_DIR / "synthesize.py")
assert SYNTH_SPEC is not None and SYNTH_SPEC.loader is not None
synthesize = importlib.util.module_from_spec(SYNTH_SPEC)
sys.modules["synthesize"] = synthesize
SYNTH_SPEC.loader.exec_module(synthesize)
GENERATOR_SPEC = importlib.util.spec_from_file_location("edge_tts_generate_demos", SERVICE_DIR / "generate_demos.py")
assert GENERATOR_SPEC is not None and GENERATOR_SPEC.loader is not None
generate_demos = importlib.util.module_from_spec(GENERATOR_SPEC)
GENERATOR_SPEC.loader.exec_module(generate_demos)


class FakeCommunicate:
    calls = []
    failing_voices = set()

    def __init__(self, text, voice, rate, volume, pitch):
        self.calls.append((text, voice, rate, volume, pitch))
        self.voice = voice

    def save_sync(self, path):
        if self.voice in self.failing_voices:
            raise RuntimeError("provider failure")
        Path(path).write_bytes(b"ID3fake-edge-tts-" + self.voice.encode("ascii"))


class GenerateDemosTest(unittest.TestCase):
    def setUp(self):
        FakeCommunicate.calls = []
        FakeCommunicate.failing_voices = set()
        self.tempdir = tempfile.TemporaryDirectory()
        self.output_dir = Path(self.tempdir.name) / "output"
        self.output_dir.mkdir()

    def tearDown(self):
        self.tempdir.cleanup()

    def test_partial_failure_publishes_only_verified_successes(self):
        profiles = synthesize.load_voice_catalog()
        FakeCommunicate.failing_voices = {profiles[1]["id"]}
        with patch.object(generate_demos.edge_tts, "Communicate", FakeCommunicate):
            succeeded, failed = generate_demos.run_demos(self.output_dir)

        self.assertEqual((succeeded, failed), (13, 1))
        self.assertEqual(
            FakeCommunicate.calls,
            [(profile["demo_text"], profile["id"], "+0%", "+0%", "+0Hz") for profile in profiles],
        )
        availability = json.loads((self.output_dir / "available.json").read_text(encoding="utf-8"))
        self.assertEqual(set(availability), {"version", "voices"})
        self.assertEqual(availability["version"], 1)
        self.assertEqual([entry["id"] for entry in availability["voices"]], [
            profile["id"] for profile in profiles if profile["id"] not in FakeCommunicate.failing_voices
        ])
        self.assertFalse((self.output_dir / profiles[1]["demo_file"]).exists())
        for entry in availability["voices"]:
            self.assertEqual(set(entry), {"id", "file", "bytes", "sha256"})
            audio = self.output_dir / entry["file"]
            self.assertTrue(audio.is_file() and not audio.is_symlink())
            self.assertEqual(entry["bytes"], audio.stat().st_size)
            self.assertEqual(entry["sha256"], hashlib.sha256(audio.read_bytes()).hexdigest())

    def test_all_failures_return_only_the_bounded_initialization_error(self):
        profiles = synthesize.load_voice_catalog()
        FakeCommunicate.failing_voices = {profile["id"] for profile in profiles}

        def runner_path(value):
            if str(value) == "/workspace/output":
                return self.output_dir
            return Path(value)

        with patch.object(generate_demos, "Path", side_effect=runner_path), patch.object(generate_demos.edge_tts, "Communicate", FakeCommunicate):
            stderr = io.StringIO()
            with contextlib.redirect_stderr(stderr):
                self.assertEqual(generate_demos.main(), 1)
        self.assertEqual(stderr.getvalue(), "AIHUB_ERROR_CODE=edge_tts_demo_initialization_failed\n")
        self.assertFalse((self.output_dir / "available.json").exists())


if __name__ == "__main__":
    unittest.main()
