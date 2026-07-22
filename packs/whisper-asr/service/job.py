#!/usr/bin/env python3
"""GPU-only Whisper Pack runner for a Hub-managed workspace."""

from __future__ import annotations

import argparse
import json
import os
import time
from pathlib import Path
from typing import Any

from offline_paths import (
    ALIGNMENT_LANGUAGE,
    ALIGNMENT_MARKER,
    ALIGNMENT_MODEL_NAME,
    ALIGNMENT_WEIGHT,
    ASR_MODEL_DIR,
    HUGGINGFACE_CACHE_DIR,
    PYANNOTE_CONFIG,
    PYANNOTE_EMBEDDING,
    PYANNOTE_MARKER,
    PYANNOTE_SEGMENTATION,
    TORCH_CACHE_DIR,
    alignment_cache_manifest,
    pyannote_cache_manifest,
)


def configure_offline_cache() -> None:
    os.environ["HF_HOME"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["HUGGINGFACE_HUB_CACHE"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["TRANSFORMERS_CACHE"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["XDG_CACHE_HOME"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["TORCH_HOME"] = str(TORCH_CACHE_DIR)
    os.environ["HF_HUB_OFFLINE"] = "1"
    os.environ["TRANSFORMERS_OFFLINE"] = "1"
    os.environ["HF_DATASETS_OFFLINE"] = "1"
    os.environ["PYANNOTE_METRICS_ENABLED"] = "0"


def require_manifest(path: Path, expected: dict[str, object], error_code: str) -> None:
    try:
        value = read_json(path)
    except Exception as error:
        raise RuntimeError(error_code) from error
    if value != expected:
        raise RuntimeError(error_code)


def require_asr_assets() -> None:
    require_regular_files(ASR_MODEL_DIR, ("config.json", "model.bin", "tokenizer.json"), "asr_model_unavailable")


def require_alignment_assets(language: str) -> None:
    if language != ALIGNMENT_LANGUAGE:
        raise RuntimeError("alignment_cache_unavailable")
    require_manifest(ALIGNMENT_MARKER, alignment_cache_manifest(), "alignment_cache_unavailable")
    require_regular_files(TORCH_CACHE_DIR, (ALIGNMENT_WEIGHT.name,), "alignment_cache_unavailable")


def require_diarization_assets() -> None:
    require_manifest(PYANNOTE_MARKER, pyannote_cache_manifest(), "diarization_model_unavailable")
    require_regular_files(PYANNOTE_CONFIG.parent, (PYANNOTE_CONFIG.name, "models/" + PYANNOTE_SEGMENTATION.name, "models/" + PYANNOTE_EMBEDDING.name), "diarization_model_unavailable")


def require_regular_files(directory: Path, names: tuple[str, ...], error_code: str) -> None:
    for name in names:
        path = directory / name
        if not path.is_file() or path.is_symlink():
            raise RuntimeError(error_code)


def read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError("request_invalid")
    return value


def require_cuda() -> None:
    try:
        import torch
    except ImportError as error:
        raise RuntimeError("cuda_dependency_missing") from error
    if not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")


def load_asr(model: str) -> Any:
    try:
        from faster_whisper import WhisperModel
    except ImportError as error:
        raise RuntimeError("asr_dependency_missing") from error
    try:
        return WhisperModel(model, device="cuda", compute_type="float16", local_files_only=True)
    except Exception as error:
        raise RuntimeError("asr_model_unavailable") from error


def transcribe(model: Any, source: Path, language: str | None, word_timestamps: bool) -> tuple[list[dict[str, Any]], str]:
    try:
        raw_segments, info = model.transcribe(str(source), language=language, word_timestamps=word_timestamps)
        segments = []
        for segment in raw_segments:
            item: dict[str, Any] = {
                "start": float(segment.start),
                "end": float(segment.end),
                "text": str(segment.text).strip(),
            }
            if word_timestamps:
                item["words"] = [
                    {"start": float(word.start), "end": float(word.end), "word": str(word.word)}
                    for word in (segment.words or [])
                    if word.start is not None and word.end is not None
                ]
            segments.append(item)
        return segments, str(getattr(info, "language", "auto") or "auto")
    except Exception as error:
        raise RuntimeError("asr_failed") from error


def load_alignment(language: str) -> Any:
    if language != ALIGNMENT_LANGUAGE:
        raise RuntimeError("alignment_unavailable")
    try:
        import whisperx
        return whisperx.load_align_model(
            language_code=language,
            device="cuda",
            model_name=ALIGNMENT_MODEL_NAME,
            model_dir=str(TORCH_CACHE_DIR),
        )
    except Exception as error:
        raise RuntimeError("alignment_unavailable") from error


def align(loader: Any, segments: list[dict[str, Any]], source: Path, language: str) -> list[dict[str, Any]]:
    try:
        import whisperx
        model, metadata = loader
        aligned = whisperx.align(segments, model, metadata, str(source), "cuda", return_char_alignments=False)
        result = aligned.get("segments")
        if not isinstance(result, list):
            raise ValueError
        return [item for item in result if isinstance(item, dict)]
    except Exception as error:
        raise RuntimeError("alignment_failed") from error


def load_diarization() -> Any:
    try:
        import whisperx
        return whisperx.DiarizationPipeline(model_name=str(PYANNOTE_CONFIG), use_auth_token=None, device="cuda")
    except Exception as error:
        raise RuntimeError("diarization_unavailable") from error


def diarize(loader: Any, source: Path, minimum: int | None, maximum: int | None) -> list[dict[str, Any]]:
    try:
        rows = loader(str(source), min_speakers=minimum, max_speakers=maximum)
        return [
            {"start": float(row["start"]), "end": float(row["end"]), "speaker": str(row["speaker"])}
            for _, row in rows.iterrows()
        ]
    except Exception as error:
        raise RuntimeError("diarization_failed") from error


def timestamp(seconds: float, separator: str) -> str:
    milliseconds = max(0, int(round(seconds * 1000)))
    hours, milliseconds = divmod(milliseconds, 3_600_000)
    minutes, milliseconds = divmod(milliseconds, 60_000)
    seconds, milliseconds = divmod(milliseconds, 1000)
    return f"{hours:02}:{minutes:02}:{seconds:02}{separator}{milliseconds:03}"


def subtitle(segments: list[dict[str, Any]], vtt: bool) -> str:
    lines = ["WEBVTT", ""] if vtt else []
    for index, segment in enumerate(segments, 1):
        if not vtt:
            lines.append(str(index))
        lines.append(f"{timestamp(float(segment['start']), '.' if vtt else ',')} --> {timestamp(float(segment['end']), '.' if vtt else ',')}")
        lines.extend([str(segment.get("text", "")).strip(), ""])
    return "\n".join(lines)


def anonymous_speakers(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    names: dict[str, str] = {}
    result = []
    for row in rows:
        original = str(row["speaker"])
        if original not in names:
            names[original] = f"speaker_{len(names) + 1:02}"
        result.append({"start": row["start"], "end": row["end"], "speaker": names[original]})
    return result


def run_job(workspace: Path, input_dir: Path, output_dir: Path, runner_config_path: Path) -> None:
    workspace = workspace.resolve()
    input_dir = input_dir.resolve()
    output_dir = output_dir.resolve()
    if input_dir != workspace / "input" or output_dir != workspace / "output" or runner_config_path.resolve() != input_dir / "runner_config.json":
        raise RuntimeError("workspace_invalid")
    source = input_dir / "source"
    if not source.is_file() or source.is_symlink():
        raise RuntimeError("source_invalid")
    request = read_json(input_dir / "request.json")
    config = read_json(runner_config_path)
    model = config.get("model")
    if not isinstance(model, dict) or model.get("model") != str(ASR_MODEL_DIR) or model.get("label") != "large-v3":
        raise RuntimeError("runner_config_invalid")
    language = request.get("language", "auto")
    if not isinstance(language, str) or not language or len(language) > 16:
        raise RuntimeError("request_invalid")
    word_timestamps = request.get("word_timestamps", False)
    diarization = request.get("diarization", False)
    if not isinstance(word_timestamps, bool) or not isinstance(diarization, bool):
        raise RuntimeError("request_invalid")
    if word_timestamps and language == "auto":
        raise RuntimeError("request_invalid")
    minimum = request.get("min_speakers")
    maximum = request.get("max_speakers")
    if any(value is not None and (isinstance(value, bool) or not isinstance(value, int) or value < 1 or value > 100) for value in (minimum, maximum)):
        raise RuntimeError("request_invalid")
    if (not diarization and (minimum is not None or maximum is not None)) or (minimum is not None and maximum is not None and minimum > maximum):
        raise RuntimeError("request_invalid")
    output_srt = request.get("output_srt", False)
    output_vtt = request.get("output_vtt", False)
    if not isinstance(output_srt, bool) or not isinstance(output_vtt, bool):
        raise RuntimeError("request_invalid")
    subtitle_reflow = request.get("subtitle_reflow", "none")
    if not isinstance(subtitle_reflow, str) or subtitle_reflow not in {"none", "legacy_adaptive_v1"}:
        raise RuntimeError("request_invalid")
    if subtitle_reflow == "legacy_adaptive_v1" and not (output_srt or output_vtt):
        raise RuntimeError("request_invalid")

    configure_offline_cache()
    require_asr_assets()
    require_cuda()
    started = time.monotonic()
    native_word_timestamps = word_timestamps or subtitle_reflow == "legacy_adaptive_v1"
    segments, detected_language = transcribe(load_asr(model["model"]), source, None if language == "auto" else language, native_word_timestamps)
    if word_timestamps:
        require_alignment_assets(detected_language)
        segments = align(load_alignment(detected_language), segments, source, detected_language)
    subtitle_segments = segments
    subtitle_breaker = None
    subtitle_breakers: list[str] = []
    if subtitle_reflow == "legacy_adaptive_v1":
        from subtitle_reflow import reflow_legacy_segments

        subtitle_segments, diagnostic = reflow_legacy_segments(segments, detected_language)
        subtitle_breaker = diagnostic.get("subtitle_breaker")
        values = diagnostic.get("subtitle_breakers")
        if isinstance(values, list) and all(isinstance(value, str) for value in values):
            subtitle_breakers = values
        elif subtitle_breaker in {"ckip", "jieba", "fallback"}:
            subtitle_breakers = [subtitle_breaker]
    transcript_segments = subtitle_segments
    if not word_timestamps:
        transcript_segments = [{key: value for key, value in segment.items() if key != "words"} for segment in subtitle_segments]
    output_dir.mkdir(parents=True, exist_ok=True)
    transcript = {
        "text": " ".join(str(segment.get("text", "")).strip() for segment in transcript_segments).strip(),
        "segments": transcript_segments,
        "language": detected_language,
        "model": model["label"],
        "word_timestamps": word_timestamps,
    }
    (output_dir / "transcript.json").write_text(json.dumps(transcript, ensure_ascii=False) + "\n", encoding="utf-8")
    if output_srt:
        (output_dir / "subtitle.srt").write_text(subtitle(subtitle_segments, False), encoding="utf-8")
    if output_vtt:
        (output_dir / "subtitle.vtt").write_text(subtitle(subtitle_segments, True), encoding="utf-8")
    if diarization:
        require_diarization_assets()
        timeline = anonymous_speakers(diarize(load_diarization(), source, minimum, maximum))
        (output_dir / "speaker_timeline.json").write_text(json.dumps({"speakers": timeline}, ensure_ascii=False) + "\n", encoding="utf-8")
    report = {
        "model": model["label"],
        "language": detected_language,
        "word_timestamps": word_timestamps,
        "diarization": diarization,
        "segment_count": len(subtitle_segments),
        "elapsed_seconds": max(0.0, time.monotonic() - started),
        "subtitle_reflow_profile": subtitle_reflow,
        "subtitle_breaker": subtitle_breaker,
        "subtitle_breakers": subtitle_breakers,
        "timing_source": "whisperx_aligned_words" if word_timestamps else ("faster_whisper_native_words" if subtitle_reflow == "legacy_adaptive_v1" else "native_segment"),
    }
    (output_dir / "transcription_report.json").write_text(json.dumps(report, ensure_ascii=False) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--workspace", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--runner-config", required=True)
    args = parser.parse_args()
    run_job(Path(args.workspace), Path(args.input), Path(args.output), Path(args.runner_config))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except RuntimeError as error:
        print(f"speech_transcribe_failed:{error}", file=os.sys.stderr)
        raise SystemExit(1)
