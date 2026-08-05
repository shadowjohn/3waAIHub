#!/usr/bin/env python3
"""CPU-only offline Paraformer draft transcription runner."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import pwd
import re
import shutil
import subprocess
import tempfile
import time
import wave
from pathlib import Path
from typing import Any, Callable

import numpy as np


MODEL_DIR = Path("/models/paraformer")
MARKER_PATH = MODEL_DIR / ".aihub-speech-fast-zh-ready.json"
MODEL = "sherpa-onnx-paraformer-zh-small-2024-03-09"
ENGINE = "sherpa-onnx"
PROVIDER = "cpu"
LANGUAGE = "zh-TW"
MODEL_HASHES = {
    "model.int8.onnx": "3ef6c19369b912f7caf3cef8e545c5ccd1a33d9d7ec792a46668dc41c4b229ec",
    "tokens.txt": "4b2d964e18b9cf139b473003b6698fb2ed9a2a5ec55b93daa677b28f578897aa",
}
PAUSE_SECONDS = 0.75
MAX_SEGMENT_SECONDS = 4.5
MAX_CJK_CHARS = 18
MIN_CUE_SECONDS = 0.02
FINAL_TOKEN_TAIL_SECONDS = 0.2
RUNNER_USER = "runner"
CHILD_ENVIRONMENT_FLAG = "SPEECH_FAST_ZH_RUNNER_CHILD"
OUTPUT_FILENAMES = (
    "transcript.json",
    "transcription_report.json",
    "draft_subtitle.srt",
    "draft_segments.json",
)
_CJK = re.compile(r"[\u4e00-\u9fff]")
_ASCII_WORD = re.compile(r"[A-Za-z0-9]+$")
_SINGLE_LETTER_RUN = re.compile(r"(?<![A-Za-z])([A-Za-z](?:[ \t]+[A-Za-z]){1,})(?![A-Za-z])")
_FULL_WIDTH_ASCII = str.maketrans({
    **{chr(code): chr(code - 0xFEE0) for code in range(0xFF01, 0xFF5F)},
    "\u3000": " ",
})


def read_json(path: Path, error_code: str) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError(error_code) from error
    if not isinstance(value, dict):
        raise RuntimeError(error_code)
    return value


def parse_request(value: dict[str, Any]) -> bool:
    include_draft_subtitles = value.get("include_draft_subtitles", False)
    if not isinstance(include_draft_subtitles, bool):
        raise RuntimeError("request_invalid")
    return include_draft_subtitles


def require_workspace_paths(workspace: Path, input_dir: Path, output_dir: Path) -> tuple[Path, Path, Path]:
    workspace = workspace.resolve()
    input_dir = input_dir.resolve()
    output_dir = output_dir.resolve()
    if input_dir != workspace / "input" or output_dir != workspace / "output":
        raise RuntimeError("workspace_invalid")
    return workspace, input_dir, output_dir


def require_regular_file(path: Path, error_code: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(error_code)


def file_hash(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def ready_marker() -> dict[str, str]:
    return {
        "schema": "aihub-speech-fast-zh/v1",
        "model": MODEL,
        "model_sha256": MODEL_HASHES["model.int8.onnx"],
        "tokens_sha256": MODEL_HASHES["tokens.txt"],
    }


def require_model_assets(model_dir: Path = MODEL_DIR) -> None:
    for name, expected_hash in MODEL_HASHES.items():
        path = model_dir / name
        require_regular_file(path, "model_unavailable")
        if file_hash(path) != expected_hash:
            raise RuntimeError("model_unavailable")
    marker = model_dir / MARKER_PATH.name
    marker_data = read_json(marker, "model_unavailable")
    if marker_data != ready_marker():
        raise RuntimeError("model_unavailable")


def normalize_audio(source: Path, destination: Path) -> None:
    result = subprocess.run(
        [
            "ffmpeg", "-nostdin", "-v", "error", "-y", "-i", str(source),
            "-ac", "1", "-ar", "16000", "-c:a", "pcm_s16le", str(destination),
        ],
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0 or not destination.is_file() or destination.is_symlink():
        raise RuntimeError("audio_normalization_failed")


def read_pcm16_wav(path: Path) -> tuple[np.ndarray, int]:
    try:
        with wave.open(str(path), "rb") as handle:
            if handle.getcomptype() != "NONE" or handle.getnchannels() != 1 or handle.getsampwidth() != 2 or handle.getframerate() != 16000:
                raise RuntimeError("audio_normalization_failed")
            samples = np.frombuffer(handle.readframes(handle.getnframes()), dtype=np.int16).astype(np.float32) / 32768.0
    except (OSError, EOFError, wave.Error) as error:
        raise RuntimeError("audio_normalization_failed") from error
    return samples, 16000


def create_recognizer(model_dir: Path = MODEL_DIR) -> Any:
    try:
        import sherpa_onnx
    except ImportError as error:
        raise RuntimeError("asr_dependency_missing") from error
    try:
        return sherpa_onnx.OfflineRecognizer.from_paraformer(
            paraformer=str(model_dir / "model.int8.onnx"),
            tokens=str(model_dir / "tokens.txt"),
            provider=PROVIDER,
            num_threads=1,
            sample_rate=16000,
            feature_dim=80,
            decoding_method="greedy_search",
        )
    except Exception as error:
        raise RuntimeError("model_unavailable") from error


def decode(recognizer: Any, sample_rate: int, samples: Any) -> Any:
    try:
        stream = recognizer.create_stream()
        stream.accept_waveform(sample_rate, samples)
        recognizer.decode_stream(stream)
        return stream.result
    except Exception as error:
        raise RuntimeError("asr_failed") from error


def normalize_draft_text(text: str, *, converter_factory: Callable[[str], Any] | None = None) -> str:
    if converter_factory is None:
        try:
            from opencc import OpenCC
        except ImportError as error:
            raise RuntimeError("normalizer_dependency_missing") from error
        converter_factory = OpenCC
    try:
        converter = converter_factory("s2twp")
    except Exception:
        try:
            converter = converter_factory("s2t")
        except Exception as error:
            raise RuntimeError("normalizer_unavailable") from error
    value = str(converter.convert(text)).translate(_FULL_WIDTH_ASCII)
    value = _SINGLE_LETTER_RUN.sub(lambda match: match.group(1).replace(" ", "").replace("\t", ""), value)
    value = value.replace("賬", "帳")
    value = re.sub(r"(?<![可音])樂色(?![彩素])", "垃圾", value)
    return re.sub(r"(?<!勾)勒色(?![彩素])", "垃圾", value)


def join_tokens(tokens: list[Any]) -> str:
    result = ""
    previous = ""
    previous_continues = False
    for token in tokens:
        value = str(token).strip()
        if not value:
            continue
        continues = value.endswith("@@")
        if continues:
            value = value[:-2]
        starts_word = value.startswith("▁")
        value = value.lstrip("▁")
        if not value:
            previous_continues = continues
            continue
        ascii_boundary = bool(_ASCII_WORD.fullmatch(value)) != bool(_ASCII_WORD.fullmatch(previous))
        if result and not previous_continues and (starts_word or ascii_boundary or (_ASCII_WORD.fullmatch(value) and _ASCII_WORD.fullmatch(previous))):
            result += " "
        result += value
        previous = value
        previous_continues = continues
    return result


def token_timestamps(tokens: Any, timestamps: Any) -> list[tuple[str, float]]:
    if not isinstance(tokens, (list, tuple)) or not isinstance(timestamps, (list, tuple)) or len(tokens) != len(timestamps):
        return []
    result = []
    last = -1.0
    for token, timestamp in zip(tokens, timestamps):
        if isinstance(timestamp, bool):
            return []
        try:
            value = float(timestamp)
        except (TypeError, ValueError):
            return []
        if value < 0 or value < last:
            return []
        result.append((str(token), value))
        last = value
    return result


def cjk_length(tokens: list[str]) -> int:
    return sum(1 for character in join_tokens(tokens) if _CJK.fullmatch(character))


def build_draft_segments(
    tokens: Any,
    timestamps: Any,
    *,
    normalizer: Callable[[str], str] | None = None,
    max_cjk_chars: int = MAX_CJK_CHARS,
) -> list[dict[str, float | str]]:
    timed_tokens = token_timestamps(tokens, timestamps)
    if not timed_tokens:
        return []
    normalizer = normalizer or normalize_draft_text
    segments: list[dict[str, float | str]] = []
    current: list[str] = []
    segment_start = 0.0
    previous_time = 0.0

    def publish(next_start: float | None) -> None:
        text = normalizer(join_tokens(current))
        if text:
            if next_start is None:
                end = round(max(segment_start + MIN_CUE_SECONDS, previous_time + FINAL_TOKEN_TAIL_SECONDS), 3)
            else:
                end = min(next_start, round(max(segment_start + MIN_CUE_SECONDS, previous_time + MIN_CUE_SECONDS), 3))
            segments.append({"start": segment_start, "end": end, "text": text})

    for token, timestamp in timed_tokens:
        if current and timestamp - previous_time >= MIN_CUE_SECONDS and (
            timestamp - previous_time >= PAUSE_SECONDS
            or timestamp - segment_start >= MAX_SEGMENT_SECONDS
            or cjk_length(current + [token]) > max_cjk_chars
        ):
            publish(timestamp)
            current = []
        if not current:
            segment_start = timestamp
        current.append(token)
        previous_time = timestamp
    if current:
        publish(None)
    return segments


def srt_timestamp(seconds: float) -> str:
    milliseconds = max(0, int(round(seconds * 1000)))
    hours, milliseconds = divmod(milliseconds, 3_600_000)
    minutes, milliseconds = divmod(milliseconds, 60_000)
    seconds, milliseconds = divmod(milliseconds, 1000)
    return f"{hours:02}:{minutes:02}:{seconds:02},{milliseconds:03}"


def render_srt(segments: list[dict[str, float | str]]) -> str:
    lines: list[str] = []
    for index, segment in enumerate(segments, 1):
        lines.extend([
            str(index),
            f"{srt_timestamp(float(segment['start']))} --> {srt_timestamp(float(segment['end']))}",
            str(segment["text"]),
        ])
    return "\n".join(lines) + ("\n" if lines else "")


def write_atomic(path: Path, content: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(descriptor, 0o644)
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
        Path(temporary).replace(path)
    except BaseException:
        Path(temporary).unlink(missing_ok=True)
        raise


def write_json_atomic(path: Path, value: dict[str, Any]) -> None:
    write_atomic(path, (json.dumps(value, ensure_ascii=False) + "\n").encode("utf-8"))


def stage_private_workspace(
    workspace: Path,
    input_dir: Path,
    output_dir: Path,
    private_root: Path,
    runner_uid: int,
    runner_gid: int,
) -> Path:
    workspace, input_dir, output_dir = require_workspace_paths(workspace, input_dir, output_dir)
    if private_root.is_symlink() or (private_root.exists() and not private_root.is_dir()):
        raise RuntimeError("workspace_invalid")
    private_root.mkdir(mode=0o700, exist_ok=True)
    private_workspace = private_root / "workspace"
    private_input = private_workspace / "input"
    private_output = private_workspace / "output"
    for directory in (private_root, private_workspace, private_input, private_output):
        directory.mkdir(mode=0o700, exist_ok=True)
        os.chown(directory, runner_uid, runner_gid)
        os.chmod(directory, 0o700)
    for name, error_code in (("source", "source_invalid"), ("request.json", "request_invalid")):
        source = input_dir / name
        require_regular_file(source, error_code)
        destination = private_input / name
        shutil.copyfile(source, destination)
        os.chown(destination, runner_uid, runner_gid)
        os.chmod(destination, 0o600)
    return private_workspace


def untrusted_runner_command(private_workspace: Path) -> list[str]:
    return [
        "setpriv", f"--reuid={RUNNER_USER}", f"--regid={RUNNER_USER}", "--clear-groups",
        "--bounding-set=-all", "--ambient-caps=-all", "--", os.sys.executable, str(Path(__file__).resolve()),
        "--workspace", str(private_workspace), "--input", str(private_workspace / "input"),
        "--output", str(private_workspace / "output"),
    ]


def copy_atomic_file(source: Path, destination: Path) -> None:
    require_regular_file(source, "child_output_invalid")
    descriptor, temporary = tempfile.mkstemp(prefix=f".{destination.name}.", dir=destination.parent)
    try:
        os.fchmod(descriptor, 0o644)
        with os.fdopen(descriptor, "wb") as target, source.open("rb") as stream:
            shutil.copyfileobj(stream, target)
        Path(temporary).replace(destination)
    except BaseException:
        Path(temporary).unlink(missing_ok=True)
        raise


def publish_private_outputs(private_output: Path, output_dir: Path) -> None:
    for name in OUTPUT_FILENAMES:
        source = private_output / name
        if name in OUTPUT_FILENAMES[:2] or source.exists() or source.is_symlink():
            copy_atomic_file(source, output_dir / name)


def dispatch_untrusted_job(workspace: Path, input_dir: Path, output_dir: Path) -> int:
    identity = pwd.getpwnam(RUNNER_USER)
    with tempfile.TemporaryDirectory(prefix="speech-fast-zh-", dir="/tmp") as temporary:
        private_workspace = stage_private_workspace(
            workspace,
            input_dir,
            output_dir,
            Path(temporary),
            identity.pw_uid,
            identity.pw_gid,
        )
        environment = dict(os.environ)
        environment[CHILD_ENVIRONMENT_FLAG] = "1"
        result = subprocess.run(untrusted_runner_command(private_workspace), check=False, env=environment)
        if result.returncode != 0:
            return result.returncode
        publish_private_outputs(private_workspace / "output", output_dir)
    return 0


def run_job(
    workspace: Path,
    input_dir: Path,
    output_dir: Path,
    *,
    recognizer_loader: Callable[[], Any] | None = None,
    model_dir: Path = MODEL_DIR,
) -> None:
    workspace, input_dir, output_dir = require_workspace_paths(workspace, input_dir, output_dir)
    source = input_dir / "source"
    require_regular_file(source, "source_invalid")
    include_draft_subtitles = parse_request(read_json(input_dir / "request.json", "request_invalid"))
    require_model_assets(model_dir)
    started = time.monotonic()
    with tempfile.TemporaryDirectory(prefix=".speech-fast-zh-", dir=workspace) as scratch:
        normalized = Path(scratch) / "normalized.wav"
        normalize_audio(source, normalized)
        samples, sample_rate = read_pcm16_wav(normalized)
        result = decode((recognizer_loader or (lambda: create_recognizer(model_dir)))(), sample_rate, samples)
    elapsed_seconds = max(0.0, time.monotonic() - started)
    raw_text = str(getattr(result, "text", "") or "")
    text = normalize_draft_text(raw_text)
    audio_seconds = len(samples) / sample_rate
    rtf = elapsed_seconds / audio_seconds if audio_seconds else 0.0
    output_dir.mkdir(parents=True, exist_ok=True)
    transcript = {
        "raw_text": raw_text,
        "text": text,
        "language": LANGUAGE,
        "engine": ENGINE,
        "provider": PROVIDER,
        "model": MODEL,
        "audio_seconds": audio_seconds,
        "elapsed_seconds": elapsed_seconds,
        "rtf": rtf,
    }
    warnings = []
    if not raw_text:
        warnings.append("empty_transcript")
    segments = build_draft_segments(getattr(result, "tokens", []), getattr(result, "timestamps", [])) if include_draft_subtitles else []
    if include_draft_subtitles and not segments:
        warnings.append("token_timestamps_unavailable")
        if text and audio_seconds:
            segments = [{"start": 0.0, "end": audio_seconds, "text": text}]
    report = {
        "engine": ENGINE,
        "provider": PROVIDER,
        "model": MODEL,
        "audio_seconds": audio_seconds,
        "elapsed_seconds": elapsed_seconds,
        "rtf": rtf,
        "draft_subtitles": include_draft_subtitles,
        "warnings": warnings,
    }
    write_json_atomic(output_dir / "transcript.json", transcript)
    write_json_atomic(output_dir / "transcription_report.json", report)
    if include_draft_subtitles:
        write_json_atomic(output_dir / "draft_segments.json", {"segments": segments})
        write_atomic(output_dir / "draft_subtitle.srt", render_srt(segments).encode("utf-8"))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--workspace", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    workspace = Path(args.workspace)
    input_dir = Path(args.input)
    output_dir = Path(args.output)
    if os.geteuid() == 0 and os.environ.get(CHILD_ENVIRONMENT_FLAG) != "1":
        return dispatch_untrusted_job(workspace, input_dir, output_dir)
    run_job(workspace, input_dir, output_dir)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except RuntimeError as error:
        print(f"speech_fast_zh_failed:{error}", file=os.sys.stderr)
        raise SystemExit(1)
