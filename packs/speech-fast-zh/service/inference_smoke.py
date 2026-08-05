#!/usr/bin/env python3
"""Run the built CPU draft runner against a provisioned local Paraformer model."""

from __future__ import annotations

import argparse
import json
import tempfile
from pathlib import Path

import job


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--model-dir", required=True)
    parser.add_argument("--audio", required=True)
    args = parser.parse_args()
    model_dir = Path(args.model_dir)
    audio = Path(args.audio)
    if not audio.is_file() or audio.is_symlink():
        raise RuntimeError("audio_invalid")
    with tempfile.TemporaryDirectory(prefix="speech-fast-zh-smoke-") as temporary:
        workspace = Path(temporary)
        input_dir = workspace / "input"
        output_dir = workspace / "output"
        input_dir.mkdir()
        output_dir.mkdir()
        (input_dir / "source").write_bytes(audio.read_bytes())
        (input_dir / "request.json").write_text('{"include_draft_subtitles":true}', encoding="utf-8")
        job.run_job(workspace, input_dir, output_dir, model_dir=model_dir)
        transcript = json.loads((output_dir / "transcript.json").read_text(encoding="utf-8"))
        report = json.loads((output_dir / "transcription_report.json").read_text(encoding="utf-8"))
        segments = json.loads((output_dir / "draft_segments.json").read_text(encoding="utf-8"))
        srt = (output_dir / "draft_subtitle.srt").read_text(encoding="utf-8")
    if not transcript["raw_text"] or transcript["provider"] != "cpu" or transcript["audio_seconds"] < 0 or transcript["elapsed_seconds"] < 0 or transcript["rtf"] < 0:
        raise RuntimeError("inference_smoke_failed")
    if not report["draft_subtitles"] or not isinstance(segments.get("segments"), list) or not segments["segments"] or " --> " not in srt:
        raise RuntimeError("draft_subtitles_unavailable")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
