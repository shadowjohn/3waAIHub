#!/usr/bin/env python3
import contextlib
import importlib.util
import io
import json
import ssl
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


SERVICE_DIR = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location("edge_tts_synthesize", SERVICE_DIR / "synthesize.py")
assert SPEC is not None and SPEC.loader is not None
synthesize = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(synthesize)


class FakeCommunicate:
    calls = []

    def __init__(self, text, voice, rate, volume, pitch):
        self.calls.append((text, voice, rate, volume, pitch))

    def save_sync(self, path):
        Path(path).write_bytes(b"ID3fake-edge-tts")


class FailingCommunicate:
    error = RuntimeError()

    def __init__(self, *args, **kwargs):
        pass

    def save_sync(self, path):
        raise self.error


class SynthesizeTest(unittest.TestCase):
    def setUp(self):
        FakeCommunicate.calls = []
        self.tempdir = tempfile.TemporaryDirectory()
        self.workspace = Path(self.tempdir.name)
        self.input_dir = self.workspace / "input"
        self.output_dir = self.workspace / "output"
        self.input_dir.mkdir()
        self.output_dir.mkdir()

    def tearDown(self):
        self.tempdir.cleanup()

    def write_request(self, value):
        path = self.input_dir / "request.json"
        path.write_text(json.dumps(value), encoding="utf-8")
        return path

    def request(self, text="Taiwan Edge TTS"):
        return {
            "text": text,
            "voice": "zh-TW-HsiaoChenNeural",
            "rate": "+0%",
            "volume": "+0%",
            "pitch": "+0Hz",
        }

    def test_normalized_request_writes_audio_and_exact_metadata(self):
        with patch.object(synthesize.edge_tts, "Communicate", FakeCommunicate):
            synthesize.run_job(self.write_request(self.request()), self.output_dir)

        audio = self.output_dir / "generated_audio.mp3"
        metadata = json.loads((self.output_dir / "synthesis_metadata.json").read_text(encoding="utf-8"))
        self.assertEqual(audio.read_bytes(), b"ID3fake-edge-tts")
        self.assertEqual(FakeCommunicate.calls, [("Taiwan Edge TTS", "zh-TW-HsiaoChenNeural", "+0%", "+0%", "+0Hz")])
        self.assertEqual(set(metadata), {
            "provider", "client_version", "voice", "rate", "volume", "pitch", "format", "audio_bytes", "elapsed_seconds", "warnings",
        })
        self.assertEqual(metadata["voice"], "zh-TW-HsiaoChenNeural")
        self.assertEqual(metadata["rate"], "+0%")
        self.assertEqual(metadata["volume"], "+0%")
        self.assertEqual(metadata["pitch"], "+0Hz")
        self.assertEqual(metadata["format"], "mp3")
        self.assertEqual(metadata["audio_bytes"], len(b"ID3fake-edge-tts"))
        self.assertIsInstance(metadata["elapsed_seconds"], float)
        self.assertEqual(metadata["warnings"], [])
        self.assertNotIn("Taiwan Edge TTS", json.dumps(metadata))

    def test_invalid_request_is_rejected_without_a_client_call(self):
        cases = [
            {**self.request(), "unknown": "value"},
            self.request("a" * 4097),
            {**self.request(), "pitch": "+10Hz"},
            {key: value for key, value in self.request().items() if key != "volume"},
        ]
        with patch.object(synthesize.edge_tts, "Communicate", FakeCommunicate):
            for value in cases:
                with self.subTest(value=value):
                    with self.assertRaises(synthesize.RunnerError) as raised:
                        synthesize.run_job(self.write_request(value), self.output_dir)
                    self.assertEqual(raised.exception.code, "edge_tts_failed")
        self.assertEqual(FakeCommunicate.calls, [])

    def test_main_maps_client_failures_to_one_bounded_sentinel_without_artifacts(self):
        request_path = self.write_request(self.request())

        def runner_path(value):
            if str(value) == "/workspace/input/request.json":
                return request_path
            if str(value) == "/workspace/output":
                return self.output_dir
            return Path(value)

        cases = [
            (ConnectionError("Taiwan Edge TTS connection"), "upstream_unavailable"),
            (ssl.SSLError("Taiwan Edge TTS TLS"), "upstream_unavailable"),
            (synthesize.edge_exceptions.WebSocketError("Taiwan Edge TTS websocket"), "upstream_unavailable"),
            (synthesize.edge_exceptions.NoAudioReceived("Taiwan Edge TTS no audio"), "upstream_unavailable"),
            (TimeoutError("Taiwan Edge TTS timeout"), "edge_tts_timeout"),
            (RuntimeError("Taiwan Edge TTS internal"), "edge_tts_failed"),
        ]
        for error, code in cases:
            with self.subTest(code=code, error=type(error).__name__), patch.object(synthesize, "Path", side_effect=runner_path), patch.object(synthesize.edge_tts, "Communicate", FailingCommunicate):
                FailingCommunicate.error = error
                stderr = io.StringIO()
                with contextlib.redirect_stderr(stderr):
                    self.assertEqual(synthesize.main(), 1)
                self.assertEqual(stderr.getvalue(), f"AIHUB_ERROR_CODE={code}\n")
                self.assertEqual(list(self.output_dir.iterdir()), [])


if __name__ == "__main__":
    unittest.main()
