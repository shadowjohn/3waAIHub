#!/usr/bin/env python3
"""GPU-only Pack runner for the Hub-managed audio-cleanup workspace."""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
import time
from importlib.metadata import PackageNotFoundError, version
from pathlib import Path
from typing import Any


def read_json(path: Path) -> dict[str, Any]:
    value = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(value, dict):
        raise RuntimeError(f"invalid_json:{path.name}")
    return value


def run(argv: list[str]) -> None:
    try:
        subprocess.run(argv, check=True)
    except FileNotFoundError as error:
        raise RuntimeError(f"dependency_missing:{argv[0]}") from error
    except subprocess.CalledProcessError as error:
        raise RuntimeError(f"runner_failed:{argv[0]}:{error.returncode}") from error


def audio_properties(path: Path) -> dict[str, Any]:
    try:
        probe = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration:stream=codec_type,sample_rate,channels", "-of", "json", str(path)],
            check=True,
            capture_output=True,
            text=True,
        )
        payload = json.loads(probe.stdout)
    except (FileNotFoundError, subprocess.CalledProcessError, json.JSONDecodeError) as error:
        raise RuntimeError("audio_probe_failed") from error
    stream = next((item for item in payload.get("streams", []) if item.get("codec_type") == "audio"), None)
    if not isinstance(stream, dict):
        raise RuntimeError("audio_probe_failed")
    try:
        values = {
            "sample_rate": int(stream["sample_rate"]),
            "channels": int(stream["channels"]),
            "duration_seconds": float(payload["format"]["duration"]),
        }
    except (KeyError, TypeError, ValueError) as error:
        raise RuntimeError("audio_probe_failed") from error
    if values["sample_rate"] < 1 or values["channels"] < 1 or values["duration_seconds"] < 0:
        raise RuntimeError("audio_probe_failed")
    return values


def package_version(name: str) -> str:
    try:
        return version(name)
    except PackageNotFoundError as error:
        raise RuntimeError(f"dependency_missing:{name}") from error


def require_cuda() -> None:
    try:
        import torch
    except ImportError as error:
        raise RuntimeError("dependency_missing:torch") from error
    if not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")


def one_file(root: Path, name: str) -> Path:
    matches = list(root.rglob(name))
    if len(matches) != 1 or not matches[0].is_file():
        raise RuntimeError(f"expected_output_missing:{name}")
    return matches[0]


def demucs(source: Path, work: Path, model: dict[str, Any]) -> tuple[Path, Path, str]:
    model_name = model.get("model")
    if not isinstance(model_name, str) or not model_name:
        raise RuntimeError("runner_config_invalid")
    run([
        sys.executable,
        "-m",
        "demucs",
        "--two-stems",
        "vocals",
        "--device",
        "cuda",
        "--name",
        model_name,
        "--out",
        str(work),
        str(source),
    ])
    return one_file(work, "vocals.wav"), one_file(work, "no_vocals.wav"), f"{model_name}@{model.get('version', package_version('demucs'))}"


def deep_filter(source: Path, work: Path) -> tuple[Path, str]:
    run(["deepFilter", "--device", "cuda", "-o", str(work), str(source)])
    return one_file(work, "*.wav"), package_version("DeepFilterNet")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--workspace", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--runner-config", required=True)
    args = parser.parse_args()
    workspace = Path(args.workspace).resolve()
    input_dir = Path(args.input).resolve()
    output_dir = Path(args.output).resolve()
    if input_dir != workspace / "input" or output_dir != workspace / "output":
        raise RuntimeError("workspace_invalid")
    request = read_json(input_dir / "request.json")
    runner_config = read_json(Path(args.runner_config))
    source = input_dir / "source"
    operation = request.get("operation")
    model = runner_config.get("model")
    if operation not in {"separate", "enhance", "separate_and_enhance"} or not source.is_file() or not isinstance(model, dict):
        raise RuntimeError("request_invalid")
    require_cuda()
    output_dir.mkdir(parents=True, exist_ok=True)
    scratch = workspace / ".cleanup-work"
    if scratch.exists():
        shutil.rmtree(scratch)
    scratch.mkdir()
    started = time.monotonic()
    source_audio = audio_properties(source)
    outputs: dict[str, dict[str, Any]] = {}
    versions: dict[str, str] = {}
    chain: list[str] = []
    try:
        vocals = background = None
        if operation in {"separate", "separate_and_enhance"}:
            vocals, background, demucs_version = demucs(source, scratch / "demucs", model)
            shutil.copyfile(vocals, output_dir / "vocals.wav")
            shutil.copyfile(background, output_dir / "background.wav")
            outputs["vocals_audio"] = audio_properties(output_dir / "vocals.wav")
            outputs["background_audio"] = audio_properties(output_dir / "background.wav")
            versions["demucs"] = demucs_version
            chain.append("demucs")
        if operation in {"enhance", "separate_and_enhance"}:
            enhanced_input = vocals if vocals is not None else source
            cleaned, deepfilter_version = deep_filter(enhanced_input, scratch / "deepfilternet")
            shutil.copyfile(cleaned, output_dir / "cleaned.wav")
            outputs["cleaned_audio"] = audio_properties(output_dir / "cleaned.wav")
            versions["deepfilternet"] = deepfilter_version
            chain.append("deepfilternet")
        report = {
            "operation": operation,
            "actual_chain": chain,
            "model_versions": versions,
            "source_audio": source_audio,
            "outputs": outputs,
            "elapsed_seconds": max(0.0, time.monotonic() - started),
            "warnings": [],
        }
        (output_dir / "cleanup_report.json").write_text(json.dumps(report, ensure_ascii=False) + "\n", encoding="utf-8")
    finally:
        shutil.rmtree(scratch, ignore_errors=True)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except RuntimeError as error:
        print(f"audio_cleanup_failed:{error}", file=sys.stderr)
        raise SystemExit(1)
