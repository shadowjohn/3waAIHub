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


class StreamingCommunicate:
    calls = []

    def __init__(self, text, voice, rate, volume, pitch, boundary=None):
        self.calls.append((text, voice, rate, volume, pitch, boundary))

    async def stream(self):
        yield {"type": "audio", "data": b"ID3fake-edge-tts"}
        yield {"type": "WordBoundary", "offset": 0, "duration": 5000000, "text": "Hello"}
        yield {"type": "WordBoundary", "offset": 5000000, "duration": 10000000, "text": "world."}


class InvalidStreamingCommunicate(StreamingCommunicate):
    async def stream(self):
        yield {"type": "audio", "data": b"ID3fake-edge-tts"}
        yield {"type": "WordBoundary", "offset": 0, "duration": -1, "text": "Hello"}


class SubMillisecondStreamingCommunicate(StreamingCommunicate):
    async def stream(self):
        yield {"type": "audio", "data": b"ID3fake-edge-tts"}
        yield {"type": "WordBoundary", "offset": 0, "duration": 1, "text": "A"}
        yield {"type": "WordBoundary", "offset": 1, "duration": 1, "text": "B"}


class FailingStreamingCommunicate(StreamingCommunicate):
    error = RuntimeError()

    async def stream(self):
        raise self.error
        yield {}


class SynthesizeTest(unittest.TestCase):
    def setUp(self):
        FakeCommunicate.calls = []
        StreamingCommunicate.calls = []
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
            "include_subtitles": False,
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
        self.assertFalse((self.output_dir / "subtitle.vtt").exists())

    def test_subtitle_request_writes_caption_artifacts_from_one_stream(self):
        request = {**self.request(), "include_subtitles": True}
        with patch.object(synthesize.edge_tts, "Communicate", StreamingCommunicate):
            synthesize.run_job(self.write_request(request), self.output_dir)

        self.assertEqual(StreamingCommunicate.calls, [("Taiwan Edge TTS", "zh-TW-HsiaoChenNeural", "+0%", "+0%", "+0Hz", "WordBoundary")])
        self.assertEqual(
            (self.output_dir / "subtitle.vtt").read_text(encoding="utf-8"),
            "WEBVTT\n\n00:00:00.000 --> 00:00:01.500\nHello world.\n",
        )
        self.assertEqual(
            (self.output_dir / "subtitle.srt").read_text(encoding="utf-8"),
            "1\n00:00:00,000 --> 00:00:01,500\nHello world.\n",
        )
        self.assertEqual(
            json.loads((self.output_dir / "speech_timeline.json").read_text(encoding="utf-8")),
            {
                "version": 1,
                "unit": "milliseconds",
                "duration_ms": 1500,
                "sentences": [{"text": "Hello world.", "start_ms": 0, "end_ms": 1500}],
                "words": [
                    {"text": "Hello", "start_ms": 0, "end_ms": 500},
                    {"text": "world.", "start_ms": 500, "end_ms": 1500},
                ],
            },
        )

    def test_invalid_caption_stream_leaves_no_artifacts(self):
        request = {**self.request(), "include_subtitles": True}
        with patch.object(synthesize.edge_tts, "Communicate", InvalidStreamingCommunicate):
            with self.assertRaises(synthesize.RunnerError) as raised:
                synthesize.run_job(self.write_request(request), self.output_dir)

        self.assertEqual(raised.exception.code, "edge_tts_failed")
        self.assertEqual(list(self.output_dir.iterdir()), [])

    def test_caption_stream_provider_failure_is_unavailable_and_cleans_up(self):
        request = {**self.request(), "include_subtitles": True}
        FailingStreamingCommunicate.error = synthesize.aiohttp.ClientError("offline")
        with patch.object(synthesize.edge_tts, "Communicate", FailingStreamingCommunicate):
            with self.assertRaises(synthesize.RunnerError) as raised:
                synthesize.run_job(self.write_request(request), self.output_dir)

        self.assertEqual(raised.exception.code, "upstream_unavailable")
        self.assertEqual(list(self.output_dir.iterdir()), [])

    def test_caption_stream_timeout_is_bounded_and_cleans_up(self):
        request = {**self.request(), "include_subtitles": True}
        FailingStreamingCommunicate.error = TimeoutError("timed out")
        with patch.object(synthesize.edge_tts, "Communicate", FailingStreamingCommunicate):
            with self.assertRaises(synthesize.RunnerError) as raised:
                synthesize.run_job(self.write_request(request), self.output_dir)

        self.assertEqual(raised.exception.code, "edge_tts_timeout")
        self.assertEqual(list(self.output_dir.iterdir()), [])

    def test_caption_temporary_audio_write_failure_is_an_artifact_error(self):
        request_path = self.write_request({**self.request(), "include_subtitles": True})
        temporary_audio = self.output_dir / ".generated_audio.mp3.tmp"
        path_open = Path.open

        def fail_temporary_audio(path, *args, **kwargs):
            if path == temporary_audio:
                raise OSError("disk full")
            return path_open(path, *args, **kwargs)

        with patch.object(synthesize.Path, "open", new=fail_temporary_audio), patch.object(synthesize.edge_tts, "Communicate", StreamingCommunicate):
            with self.assertRaises(synthesize.RunnerError) as raised:
                synthesize.run_job(request_path, self.output_dir)

        self.assertEqual(raised.exception.code, "artifact_write_failed")
        self.assertEqual(list(self.output_dir.iterdir()), [])

    def test_contiguous_sub_millisecond_boundaries_are_accepted(self):
        request = {**self.request(), "include_subtitles": True}
        with patch.object(synthesize.edge_tts, "Communicate", SubMillisecondStreamingCommunicate):
            synthesize.run_job(self.write_request(request), self.output_dir)

        timeline = json.loads((self.output_dir / "speech_timeline.json").read_text(encoding="utf-8"))
        self.assertEqual(timeline["sentences"], [
            {"text": "A B", "start_ms": 0, "end_ms": 1},
        ])
        self.assertEqual(timeline["words"], [
            {"text": "A", "start_ms": 0, "end_ms": 1},
            {"text": "B", "start_ms": 0, "end_ms": 1},
        ])

    def test_invalid_request_cleans_existing_known_artifacts(self):
        (self.output_dir / "subtitle.vtt").write_text("old", encoding="utf-8")
        invalid_request = {**self.request(), "include_subtitles": "true"}

        with patch.object(synthesize.edge_tts, "Communicate", FakeCommunicate):
            with self.assertRaises(synthesize.RunnerError) as raised:
                synthesize.run_job(self.write_request(invalid_request), self.output_dir)

        self.assertEqual(raised.exception.code, "edge_tts_failed")
        self.assertEqual(FakeCommunicate.calls, [])
        self.assertEqual(list(self.output_dir.iterdir()), [])

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

    def test_legacy_request_defaults_to_audio_only(self):
        request = self.request()
        request.pop("include_subtitles")
        with patch.object(synthesize.edge_tts, "Communicate", FakeCommunicate):
            synthesize.run_job(self.write_request(request), self.output_dir)

        self.assertEqual(FakeCommunicate.calls, [("Taiwan Edge TTS", "zh-TW-HsiaoChenNeural", "+0%", "+0%", "+0Hz")])
        self.assertFalse((self.output_dir / "subtitle.vtt").exists())

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
