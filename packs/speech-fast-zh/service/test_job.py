from __future__ import annotations

import importlib.util
import io
import json
import os
import subprocess
import sys
import tempfile
import types
import unittest
import wave
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import patch


SERVICE_DIR = Path(__file__).resolve().parent
JOB_FILE = SERVICE_DIR / "job.py"


def load_job():
    spec = importlib.util.spec_from_file_location("speech_fast_zh_job", JOB_FILE)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class SpeechFastZhJobTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.workspace = Path(self.temporary.name)
        self.input_dir = self.workspace / "input"
        self.output_dir = self.workspace / "output"
        self.input_dir.mkdir()
        self.output_dir.mkdir()
        (self.input_dir / "source").write_bytes(b"audio")

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def job(self):
        if not JOB_FILE.exists():
            self.skipTest("job runner has not been implemented")
        return load_job()

    def write_request(self, request: dict[str, object]) -> None:
        (self.input_dir / "request.json").write_text(json.dumps(request), encoding="utf-8")

    def test_cli_exposes_required_workspace_paths(self) -> None:
        result = subprocess.run(
            [sys.executable, str(JOB_FILE), "--help"],
            check=False,
            capture_output=True,
            text=True,
        )

        self.assertEqual(result.returncode, 0, result.stderr)
        for argument in ("--workspace", "--input", "--output"):
            self.assertIn(argument, result.stdout)
        self.assertNotIn("--runner-config", result.stdout)

    def test_parse_request_defaults_draft_subtitles_to_false(self) -> None:
        job = self.job()

        self.assertEqual(job.parse_request({}), False)
        with self.assertRaisesRegex(RuntimeError, "^request_invalid$"):
            job.parse_request({"include_draft_subtitles": "true"})

    def test_workspace_paths_must_be_the_managed_children(self) -> None:
        job = self.job()
        self.write_request({})

        with self.assertRaisesRegex(RuntimeError, "^workspace_invalid$"):
            job.run_job(
                self.workspace,
                self.input_dir.parent,
                self.output_dir,
            )

    def test_fixed_model_job_does_not_require_a_runner_config_file(self) -> None:
        job = self.job()
        self.write_request({})

        self.assertFalse((self.input_dir / "runner_config.json").exists())
        self.assertEqual(job.parse_request(job.read_json(self.input_dir / "request.json", "request_invalid")), False)

    def test_normalize_draft_preserves_words_except_the_narrow_safe_corrections(self) -> None:
        job = self.job()
        calls: list[str] = []

        class Converter:
            def convert(self, value: str) -> str:
                return value.replace("账号", "帳號").replace("乐", "樂")

        def converter_factory(config: str) -> Converter:
            calls.append(config)
            return Converter()

        raw = "账号　Ａ B C！＃？１２ 賬 乐色 可乐色素 音乐色彩 勾勒色彩 嗯 嗯"
        text = job.normalize_draft_text(raw, converter_factory=converter_factory)

        self.assertEqual(calls, ["s2twp"])
        self.assertEqual(raw, "账号　Ａ B C！＃？１２ 賬 乐色 可乐色素 音乐色彩 勾勒色彩 嗯 嗯")
        self.assertEqual(text, "帳號 ABC!#?12 帳 垃圾 可樂色素 音樂色彩 勾勒色彩 嗯 嗯")

    def test_normalize_draft_falls_back_to_s2t(self) -> None:
        job = self.job()
        calls: list[str] = []

        class Converter:
            def convert(self, value: str) -> str:
                return value

        def converter_factory(config: str) -> Converter:
            calls.append(config)
            if config == "s2twp":
                raise ValueError("missing profile")
            return Converter()

        self.assertEqual(job.normalize_draft_text("賬", converter_factory=converter_factory), "帳")
        self.assertEqual(calls, ["s2twp", "s2t"])

    def test_join_tokens_and_break_draft_segments_by_pause_duration_and_cjk_length(self) -> None:
        job = self.job()

        self.assertEqual(job.join_tokens(["你", "好", "▁A", "▁B"]), "你好 A B")
        self.assertEqual(job.join_tokens(["▁spea@@", "king", "▁A@@", "I", "你"]), "speaking AI 你")
        segments = job.build_draft_segments(
            ["一", "二", "三", "四", "五", "六", "七"],
            [0.0, 0.1, 0.2, 1.2, 1.3, 4.8, 4.9],
            normalizer=lambda value: value,
            max_cjk_chars=3,
        )

        self.assertEqual(segments, [
            {"start": 0.0, "end": 0.22, "text": "一二三"},
            {"start": 1.2, "end": 1.32, "text": "四五"},
            {"start": 4.8, "end": 5.1, "text": "六七"},
        ])
        self.assertEqual(job.build_draft_segments(["單"], [0.0], normalizer=lambda value: value), [
            {"start": 0.0, "end": 0.2, "text": "單"},
        ])
        for current, following in zip(segments, segments[1:]):
            self.assertLess(float(current["start"]), float(current["end"]))
            self.assertLessEqual(float(current["end"]), float(following["start"]))
        self.assertLess(float(segments[-1]["start"]), float(segments[-1]["end"]))

    def test_srt_formatting_uses_segment_text(self) -> None:
        job = self.job()

        self.assertEqual(
            job.render_srt([{"start": 0.0, "end": 1.25, "text": "草稿文字"}]),
            "1\n00:00:00,000 --> 00:00:01,250\n草稿文字\n",
        )

    def test_ffmpeg_normalization_uses_a_local_pcm_wav_command(self) -> None:
        job = self.job()
        target = self.workspace / "normal.wav"
        calls: list[list[str]] = []

        def fake_run(command: list[str], **kwargs: object) -> types.SimpleNamespace:
            calls.append(command)
            target.write_bytes(b"wav")
            return types.SimpleNamespace(returncode=0, stderr="")

        with patch.object(job.subprocess, "run", side_effect=fake_run):
            job.normalize_audio(self.input_dir / "source", target)

        self.assertEqual(calls, [[
            "ffmpeg", "-nostdin", "-v", "error", "-y", "-i", str(self.input_dir / "source"),
            "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", str(target),
        ]])

    def test_recognizer_uses_the_fixed_cpu_paraformer_options_and_stream_result(self) -> None:
        job = self.job()
        calls: list[dict[str, object]] = []

        class Stream:
            def __init__(self) -> None:
                self.result = types.SimpleNamespace(text="raw transcript", tokens=[], timestamps=[])

            def accept_waveform(self, sample_rate: int, samples: object) -> None:
                self.sample_rate = sample_rate
                self.samples = samples

        class Recognizer:
            @classmethod
            def from_paraformer(cls, **kwargs: object) -> "Recognizer":
                calls.append(kwargs)
                return cls()

            def create_stream(self) -> Stream:
                self.stream = Stream()
                return self.stream

            def decode_stream(self, stream: Stream) -> None:
                self.decoded = stream is self.stream

        fake_sherpa = types.SimpleNamespace(OfflineRecognizer=Recognizer)
        with patch.dict(sys.modules, {"sherpa_onnx": fake_sherpa}):
            recognizer = job.create_recognizer()
        result = job.decode(recognizer, 16000, [0.0])

        self.assertEqual(calls, [{
            "paraformer": "/models/paraformer/model.int8.onnx",
            "tokens": "/models/paraformer/tokens.txt",
            "provider": "cpu",
            "num_threads": 1,
            "sample_rate": 16000,
            "feature_dim": 80,
            "decoding_method": "greedy_search",
        }])
        self.assertEqual(result.text, "raw transcript")
        self.assertTrue(recognizer.decoded)

    def test_job_writes_exact_artifact_shapes_and_keeps_raw_text_unchanged(self) -> None:
        job = self.job()
        self.write_request({"include_draft_subtitles": True})
        scratch: list[Path] = []

        class Recognizer:
            def create_stream(self) -> object:
                return types.SimpleNamespace(
                    result=types.SimpleNamespace(
                        text="账号 Ａ B C 乐色 音乐色彩 勾勒色彩",
                        tokens=["账号", "▁Ａ", "▁B", "▁C", "乐色", "▁音乐色彩", "▁勾勒色彩"],
                        timestamps=[0.0, 0.1, 0.2, 0.3, 0.4, 0.5, 0.6],
                    ),
                    accept_waveform=lambda sample_rate, samples: None,
                )

            def decode_stream(self, stream: object) -> None:
                return None

        def fake_normalize_audio(source: Path, destination: Path) -> None:
            scratch.append(destination)
            destination.write_bytes(b"pcm")

        normalized_values: list[str] = []

        def normalizer(value: str) -> str:
            normalized_values.append(value)
            value = value.replace("账号", "帳號").replace("Ａ", "A").replace("乐", "樂").replace("A B C", "ABC")
            return __import__("re").sub(r"(?<!音)樂色(?!彩)", "垃圾", value)

        with patch.object(job, "require_model_assets"), patch.object(job, "normalize_audio", side_effect=fake_normalize_audio), patch.object(job, "read_pcm16_wav", return_value=([0.0] * 16000, 16000)), patch.object(job, "normalize_draft_text", side_effect=normalizer):
            job.run_job(
                self.workspace,
                self.input_dir,
                self.output_dir,
                recognizer_loader=Recognizer,
            )

        transcript = json.loads((self.output_dir / "transcript.json").read_text(encoding="utf-8"))
        report = json.loads((self.output_dir / "transcription_report.json").read_text(encoding="utf-8"))
        drafts = json.loads((self.output_dir / "draft_segments.json").read_text(encoding="utf-8"))
        self.assertEqual(set(transcript), {"raw_text", "text", "language", "engine", "provider", "model", "audio_seconds", "elapsed_seconds", "rtf"})
        self.assertEqual(set(report), {"engine", "provider", "model", "audio_seconds", "elapsed_seconds", "rtf", "draft_subtitles", "warnings"})
        self.assertEqual(transcript["raw_text"], "账号 Ａ B C 乐色 音乐色彩 勾勒色彩")
        self.assertEqual(transcript["text"], "帳號 ABC 垃圾 音樂色彩 勾勒色彩")
        self.assertEqual(transcript["language"], "zh-TW")
        self.assertEqual(transcript["provider"], "cpu")
        self.assertEqual(drafts, {"segments": [{"start": 0.0, "end": 0.8, "text": "帳號 ABC 垃圾 音樂色彩 勾勒色彩"}]})
        self.assertEqual(normalized_values, ["账号 Ａ B C 乐色 音乐色彩 勾勒色彩"] * 2)
        self.assertEqual(
            (self.output_dir / "draft_subtitle.srt").read_text(encoding="utf-8"),
            "1\n00:00:00,000 --> 00:00:00,800\n帳號 ABC 垃圾 音樂色彩 勾勒色彩\n",
        )
        self.assertTrue(all(not path.exists() for path in scratch))

    def test_empty_result_writes_empty_requested_draft_artifacts(self) -> None:
        job = self.job()
        self.write_request({"include_draft_subtitles": True})

        class Recognizer:
            def create_stream(self) -> object:
                return types.SimpleNamespace(
                    result=types.SimpleNamespace(text="", tokens=[], timestamps=[]),
                    accept_waveform=lambda sample_rate, samples: None,
                )

            def decode_stream(self, stream: object) -> None:
                return None

        def fake_normalize_audio(source: Path, destination: Path) -> None:
            destination.write_bytes(b"pcm")

        with patch.object(job, "require_model_assets"), patch.object(job, "normalize_audio", side_effect=fake_normalize_audio), patch.object(job, "read_pcm16_wav", return_value=([0.0], 16000)):
            job.run_job(self.workspace, self.input_dir, self.output_dir, recognizer_loader=Recognizer)

        self.assertEqual(json.loads((self.output_dir / "draft_segments.json").read_text(encoding="utf-8")), {"segments": []})
        self.assertEqual((self.output_dir / "draft_subtitle.srt").read_text(encoding="utf-8"), "")
        report = json.loads((self.output_dir / "transcription_report.json").read_text(encoding="utf-8"))
        self.assertEqual(report["warnings"], ["empty_transcript", "token_timestamps_unavailable"])

    def test_nonempty_result_without_timestamps_writes_one_coarse_draft_segment(self) -> None:
        job = self.job()
        self.write_request({"include_draft_subtitles": True})

        class Recognizer:
            def create_stream(self) -> object:
                return types.SimpleNamespace(
                    result=types.SimpleNamespace(text="粗字幕", tokens=[], timestamps=[]),
                    accept_waveform=lambda sample_rate, samples: None,
                )

            def decode_stream(self, stream: object) -> None:
                return None

        def fake_normalize_audio(source: Path, destination: Path) -> None:
            destination.write_bytes(b"pcm")

        with patch.object(job, "require_model_assets"), patch.object(job, "normalize_audio", side_effect=fake_normalize_audio), patch.object(job, "read_pcm16_wav", return_value=([0.0] * 16000, 16000)):
            job.run_job(self.workspace, self.input_dir, self.output_dir, recognizer_loader=Recognizer)

        self.assertEqual(
            json.loads((self.output_dir / "draft_segments.json").read_text(encoding="utf-8")),
            {"segments": [{"start": 0.0, "end": 1.0, "text": "粗字幕"}]},
        )
        self.assertEqual(
            (self.output_dir / "draft_subtitle.srt").read_text(encoding="utf-8"),
            "1\n00:00:00,000 --> 00:00:01,000\n粗字幕\n",
        )
        report = json.loads((self.output_dir / "transcription_report.json").read_text(encoding="utf-8"))
        self.assertEqual(report["warnings"], ["token_timestamps_unavailable"])

    def test_private_runner_workspace_is_owned_by_the_untrusted_runner(self) -> None:
        job = self.job()
        self.write_request({"include_draft_subtitles": True})
        private_root = self.workspace / "private"
        private_root.mkdir(mode=0o700)

        private_workspace = job.stage_private_workspace(
            self.workspace,
            self.input_dir,
            self.output_dir,
            private_root,
            os.getuid(),
            os.getgid(),
        )

        self.assertEqual((private_workspace / "input" / "source").read_bytes(), b"audio")
        self.assertEqual(
            json.loads((private_workspace / "input" / "request.json").read_text(encoding="utf-8")),
            {"include_draft_subtitles": True},
        )
        self.assertEqual(
            job.untrusted_runner_command(private_workspace),
            [
                "setpriv", "--reuid=runner", "--regid=runner", "--clear-groups",
                "--bounding-set=-all", "--ambient-caps=-all", "--", sys.executable, str(JOB_FILE),
                "--workspace", str(private_workspace), "--input", str(private_workspace / "input"),
                "--output", str(private_workspace / "output"),
            ],
        )

    def test_dockerfile_uses_a_root_dispatcher_and_non_root_runner(self) -> None:
        dockerfile = SERVICE_DIR / "Dockerfile"
        self.assertTrue(dockerfile.is_file())
        if not dockerfile.is_file():
            return
        text = dockerfile.read_text(encoding="utf-8")
        self.assertIn("FROM python:3.11-slim", text)
        self.assertIn("ffmpeg", text)
        self.assertIn("util-linux", text)
        self.assertIn("useradd", text)
        self.assertIn("USER root", text)
        self.assertIn("/app/speech-fast-zh", text)
        job_text = JOB_FILE.read_text(encoding="utf-8")
        self.assertIn('RUNNER_USER = "runner"', job_text)
        self.assertIn("--reuid={RUNNER_USER}", job_text)
        self.assertIn("stage_private_workspace", job_text)

    def test_requirements_keep_the_small_offline_runtime_pinned(self) -> None:
        requirements = SERVICE_DIR / "requirements.txt"
        self.assertTrue(requirements.is_file())
        if not requirements.is_file():
            return
        self.assertEqual(requirements.read_text(encoding="utf-8").splitlines(), [
            "sherpa-onnx==1.13.4",
            "numpy==2.2.6",
            "opencc-python-reimplemented==0.1.7",
        ])


if __name__ == "__main__":
    unittest.main()
