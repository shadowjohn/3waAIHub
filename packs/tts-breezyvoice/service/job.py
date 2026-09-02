#!/usr/bin/env python3
"""Isolated runner for a transcript-confirmed BreezyVoice profile snapshot."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
import tempfile
import uuid
import wave
from pathlib import Path, PurePosixPath
from typing import Any


CONTAINER_REFERENCE = "/data/voice_profiles/reference.wav"
UPSTREAM_ROOT = "/opt/breezyvoice"
IMMUTABLE_REVISION = re.compile(r"^[0-9a-f]{40}$")
SHA256 = re.compile(r"^[0-9a-f]{64}$")
MAX_SEED = 2_147_483_647
DEFAULT_TIMEOUT_SECONDS = 7200


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def same_path(left: Path, right: Path) -> bool:
    return os.path.normcase(str(left)) == os.path.normcase(str(right))


def resolved_directory(path: Path, code: str) -> Path:
    if not path.is_absolute():
        raise RuntimeError(code)
    lexical = Path(os.path.abspath(path))
    if not lexical.exists() or lexical.is_symlink() or not lexical.is_dir():
        raise RuntimeError(code)
    try:
        resolved = lexical.resolve(strict=True)
    except OSError as error:
        raise RuntimeError(code) from error
    if not same_path(lexical, resolved):
        raise RuntimeError(code)
    return lexical


def workspace_child(workspace: Path, candidate: Path, name: str, *, create: bool = False) -> Path:
    if not candidate.is_absolute():
        raise RuntimeError("workspace_path_invalid")
    lexical = Path(os.path.abspath(candidate))
    expected = workspace / name
    if not same_path(lexical, expected):
        raise RuntimeError("workspace_path_invalid")
    if lexical.exists():
        return resolved_directory(lexical, "workspace_path_invalid")
    if not create:
        raise RuntimeError("workspace_path_invalid")
    try:
        lexical.mkdir(mode=0o700)
    except OSError as error:
        raise RuntimeError("workspace_path_invalid") from error
    return resolved_directory(lexical, "workspace_path_invalid")


def workspace_regular(workspace: Path, path: Path, code: str) -> Path:
    if not same_path(path.parent, workspace / "input") or path.name not in {"request.json", "runner_config.json"}:
        raise RuntimeError(code)
    if path.is_symlink() or not path.is_file():
        raise RuntimeError(code)
    try:
        resolved = path.resolve(strict=True)
    except OSError as error:
        raise RuntimeError(code) from error
    if not same_path(resolved, path) or not same_path(resolved.parent, workspace / "input"):
        raise RuntimeError(code)
    return path


def read_json(path: Path, code: str) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        raise RuntimeError(code) from error
    if not isinstance(value, dict):
        raise RuntimeError(code)
    return value


def require_lower_hash(value: Any, code: str) -> str:
    if not isinstance(value, str) or not SHA256.fullmatch(value):
        raise RuntimeError(code)
    return value


def require_immutable_revision(value: Any, code: str) -> str:
    if not isinstance(value, str) or value in {"", "main", "latest"} or not IMMUTABLE_REVISION.fullmatch(value):
        raise RuntimeError(code)
    return value


def validate_runner_config(value: dict[str, Any]) -> dict[str, Any]:
    if set(value) != {
        "schema_version", "model", "model_revision", "upstream_revision", "model_dir", "voice_profile_id",
        "reference_audio_sha256", "transcript_sha256", "prompt_text_confirmed_at", "prompt_transcript_confirmed",
        "seed", "seed_applied", "reproducibility", "device", "sample_rate", "channels", "sample_format", "max_input_chars",
    }:
        raise RuntimeError("runner_config_invalid")
    model = value.get("model")
    if not isinstance(model, str) or not model.strip() or "\x00" in model:
        raise RuntimeError("runner_config_invalid")
    model_dir_value = value.get("model_dir")
    if not isinstance(model_dir_value, str) or "\x00" in model_dir_value:
        raise RuntimeError("runner_config_invalid")
    model_dir = resolved_directory(Path(model_dir_value), "runner_config_invalid")
    max_input_chars = value.get("max_input_chars")
    if (
        isinstance(max_input_chars, bool) or not isinstance(max_input_chars, int) or not 1 <= max_input_chars <= 2000
        or value.get("schema_version") != "breezyvoice_runner_config_v1"
        or isinstance(value.get("voice_profile_id"), bool) or not isinstance(value.get("voice_profile_id"), int) or value["voice_profile_id"] < 1
        or not isinstance(value.get("prompt_text_confirmed_at"), str) or not re.fullmatch(r"\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}", value["prompt_text_confirmed_at"])
        or value.get("prompt_transcript_confirmed") is not True
        or (value.get("seed") is not None and (isinstance(value.get("seed"), bool) or not isinstance(value.get("seed"), int) or not 0 <= value["seed"] <= MAX_SEED))
        or value.get("seed_applied") is not False or value.get("reproducibility") != "best_effort"
        or value.get("device") != "cuda" or value.get("sample_rate") != 22050
        or value.get("channels") != 1 or value.get("sample_format") != "pcm_s16le"
    ):
        raise RuntimeError("runner_config_invalid")
    return {
        "model": model,
        "model_revision": require_immutable_revision(value.get("model_revision"), "runner_config_invalid"),
        "upstream_revision": require_immutable_revision(value.get("upstream_revision"), "runner_config_invalid"),
        "model_dir": model_dir,
        "voice_profile_id": value["voice_profile_id"],
        "reference_audio_sha256": require_lower_hash(value.get("reference_audio_sha256"), "runner_config_invalid"),
        "transcript_sha256": require_lower_hash(value.get("transcript_sha256"), "runner_config_invalid"),
        "prompt_text_confirmed_at": value["prompt_text_confirmed_at"],
        "prompt_transcript_confirmed": True,
        "seed": value["seed"],
        "seed_applied": False,
        "reproducibility": "best_effort",
        "max_input_chars": max_input_chars,
        "timeout_seconds": DEFAULT_TIMEOUT_SECONDS,
        "device": "cuda",
        "sample_rate": 22050,
        "channels": 1,
        "sample_format": "pcm_s16le",
    }


def validate_request(value: dict[str, Any], config: dict[str, Any]) -> dict[str, Any]:
    if set(value) - {"text", "mode", "seed", "seed_policy", "voice_context", "voice_profile_id", "voice_profile_task_id", "prompt_text"}:
        raise RuntimeError("request_invalid")
    text = value.get("text")
    if not isinstance(text, str) or not text.strip() or "\x00" in text or len(text) > config["max_input_chars"]:
        raise RuntimeError("request_invalid")
    if value.get("mode") != "ultimate_clone":
        raise RuntimeError("mode_invalid")
    seed = value.get("seed")
    if seed is not None and (isinstance(seed, bool) or not isinstance(seed, int) or not 0 <= seed <= MAX_SEED):
        raise RuntimeError("request_invalid")
    if value.get("seed_policy", "best_effort") != "best_effort":
        raise RuntimeError("request_invalid")
    prompt_text = value.get("prompt_text")
    if not isinstance(prompt_text, str) or not prompt_text.strip() or "\x00" in prompt_text:
        raise RuntimeError("transcript_missing")
    context = value.get("voice_context")
    if not isinstance(context, dict) or set(context) != {
        "mode", "voice_profile_id", "reference_audio_sha256", "prompt_text_sha256", "prompt_text_confirmed_at", "container_path",
    }:
        raise RuntimeError("voice_context_invalid")
    if (
        context.get("mode") != "ultimate_clone"
        or context.get("voice_profile_id") != config["voice_profile_id"]
        or require_lower_hash(context.get("reference_audio_sha256"), "voice_context_invalid") != config["reference_audio_sha256"]
        or require_lower_hash(context.get("prompt_text_sha256"), "voice_context_invalid") != config["transcript_sha256"]
        or context.get("prompt_text_confirmed_at") != config["prompt_text_confirmed_at"]
        or context.get("container_path") != CONTAINER_REFERENCE
        or sha256_text(prompt_text) != config["transcript_sha256"]
    ):
        raise RuntimeError("voice_context_invalid")
    return {"text": text, "seed": seed, "transcript": prompt_text}


def collect_regular_files(root: Path, code: str) -> list[Path]:
    files: list[Path] = []

    def walk(current: Path) -> None:
        try:
            entries = list(os.scandir(current))
        except OSError as error:
            raise RuntimeError(code) from error
        for entry in entries:
            path = Path(entry.path)
            if entry.is_symlink():
                raise RuntimeError(code)
            if entry.is_dir(follow_symlinks=False):
                walk(path)
            elif entry.is_file(follow_symlinks=False):
                files.append(path)
            else:
                raise RuntimeError(code)

    walk(root)
    return sorted(files, key=lambda path: path.relative_to(root).as_posix())


def manifest_relative_path(value: Any) -> PurePosixPath:
    if not isinstance(value, str) or not value or "\\" in value or "\x00" in value:
        raise RuntimeError("model_manifest_invalid")
    path = PurePosixPath(value)
    if path.is_absolute() or any(part in {"", ".", ".."} for part in path.parts):
        raise RuntimeError("model_manifest_invalid")
    return path


def validate_model_manifest(config: dict[str, Any]) -> None:
    model_dir = config["model_dir"]
    manifest_path = model_dir / "model-manifest.json"
    if manifest_path.is_symlink() or not manifest_path.is_file():
        raise RuntimeError("model_manifest_invalid")
    try:
        resolved_manifest = manifest_path.resolve(strict=True)
    except OSError as error:
        raise RuntimeError("model_manifest_invalid") from error
    if not same_path(resolved_manifest, manifest_path):
        raise RuntimeError("model_manifest_invalid")
    manifest = read_json(manifest_path, "model_manifest_invalid")
    if set(manifest) != {"model_id", "revision", "files"} or manifest.get("model_id") != config["model"]:
        raise RuntimeError("model_manifest_invalid")
    if manifest.get("revision") != config["model_revision"] or not isinstance(manifest.get("files"), list) or not manifest["files"]:
        raise RuntimeError("model_manifest_invalid")

    manifest_paths: list[str] = []
    for item in manifest["files"]:
        if not isinstance(item, dict) or set(item) != {"path", "sha256", "size_bytes"}:
            raise RuntimeError("model_manifest_invalid")
        relative = manifest_relative_path(item.get("path"))
        relative_name = relative.as_posix()
        if relative_name == "model-manifest.json":
            raise RuntimeError("model_manifest_invalid")
        expected_hash = require_lower_hash(item.get("sha256"), "model_manifest_invalid")
        expected_size = item.get("size_bytes")
        if isinstance(expected_size, bool) or not isinstance(expected_size, int) or expected_size < 0:
            raise RuntimeError("model_manifest_invalid")
        candidate = model_dir.joinpath(*relative.parts)
        if candidate.is_symlink() or not candidate.is_file():
            raise RuntimeError("model_manifest_invalid")
        try:
            resolved = candidate.resolve(strict=True)
        except OSError as error:
            raise RuntimeError("model_manifest_invalid") from error
        if not same_path(resolved, candidate) or not same_path(resolved.parent, candidate.parent):
            raise RuntimeError("model_manifest_invalid")
        if candidate.stat(follow_symlinks=False).st_size != expected_size or sha256_file(candidate) != expected_hash:
            raise RuntimeError("model_manifest_invalid")
        manifest_paths.append(relative_name)

    if manifest_paths != sorted(set(manifest_paths)):
        raise RuntimeError("model_manifest_invalid")
    actual_paths = [
        path.relative_to(model_dir).as_posix()
        for path in collect_regular_files(model_dir, "model_manifest_invalid")
        if path.relative_to(model_dir).as_posix() != "model-manifest.json"
    ]
    if actual_paths != manifest_paths:
        raise RuntimeError("model_manifest_invalid")


def require_reference(path: Path, expected_hash: str) -> None:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("reference_invalid")
    try:
        resolved = path.resolve(strict=True)
    except OSError as error:
        raise RuntimeError("reference_invalid") from error
    if not same_path(resolved, path) or sha256_file(path) != expected_hash:
        raise RuntimeError("reference_invalid")


def verify_pcm16_wav(path: Path) -> None:
    if path.is_symlink() or not path.is_file():
        raise RuntimeError("generated_audio_invalid")
    try:
        with wave.open(str(path), "rb") as source:
            valid = (
                source.getcomptype() == "NONE"
                and source.getnchannels() == 1
                and source.getframerate() == 22050
                and source.getsampwidth() == 2
                and source.getnframes() > 0
            )
    except (OSError, wave.Error) as error:
        raise RuntimeError("generated_audio_invalid") from error
    if not valid:
        raise RuntimeError("generated_audio_invalid")


def atomic_write_json(path: Path, value: dict[str, Any]) -> None:
    temporary = path.parent / f".{path.name}.{uuid.uuid4().hex}.tmp"
    payload = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"
    try:
        with temporary.open("x", encoding="utf-8", newline="\n") as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    except OSError as error:
        temporary.unlink(missing_ok=True)
        raise RuntimeError("metadata_write_failed") from error


def run_job(
    workspace: Path,
    input_dir: Path,
    output_dir: Path,
    runner_config: Path,
    *,
    reference_path: Path = Path(CONTAINER_REFERENCE),
) -> dict[str, Any]:
    safe_workspace = resolved_directory(workspace, "workspace_path_invalid")
    safe_input = workspace_child(safe_workspace, input_dir, "input")
    safe_output = workspace_child(safe_workspace, output_dir, "output", create=True)
    safe_request = workspace_regular(safe_workspace, safe_input / "request.json", "request_invalid")
    safe_config = workspace_regular(safe_workspace, runner_config, "runner_config_invalid")
    if not same_path(safe_config, safe_input / "runner_config.json"):
        raise RuntimeError("runner_config_invalid")
    if (safe_output / "generated_audio.wav").exists() or (safe_output / "synthesis_metadata.json").exists():
        raise RuntimeError("output_invalid")

    config = validate_runner_config(read_json(safe_config, "runner_config_invalid"))
    request = validate_request(read_json(safe_request, "request_invalid"), config)
    require_reference(reference_path, config["reference_audio_sha256"])
    validate_model_manifest(config)

    generated_audio = safe_output / "generated_audio.wav"
    command = [
        sys.executable,
        "/opt/breezyvoice/single_inference.py",
        "--content_to_synthesize",
        request["text"],
        "--speaker_prompt_audio_path",
        "/data/voice_profiles/reference.wav",
        "--speaker_prompt_text_transcription",
        request["transcript"],
        "--output_path",
        str(generated_audio),
    ]
    try:
        subprocess.run(command, cwd="/opt/breezyvoice", check=True, timeout=config["timeout_seconds"], shell=False)
    except subprocess.TimeoutExpired as error:
        raise RuntimeError("inference_timeout") from error
    except (OSError, subprocess.CalledProcessError) as error:
        raise RuntimeError("inference_failed") from error
    verify_pcm16_wav(generated_audio)
    audio_sha256 = sha256_file(generated_audio)
    audio_size_bytes = generated_audio.stat().st_size

    metadata = {
        "model": config["model"],
        "model_revision": config["model_revision"],
        "upstream_revision": config["upstream_revision"],
        "reference_audio_sha256": config["reference_audio_sha256"],
        "transcript_sha256": config["transcript_sha256"],
        "audio_sha256": audio_sha256,
        "audio_size_bytes": audio_size_bytes,
        "seed": request["seed"],
        "seed_applied": False,
        "reproducibility": "best_effort",
        "device": config["device"],
        "final_format": {
            "mime_type": "audio/wav",
            "sample_rate": config["sample_rate"],
            "channels": config["channels"],
            "sample_format": config["sample_format"],
        },
    }
    atomic_write_json(safe_output / "synthesis_metadata.json", metadata)
    return metadata


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Run one isolated BreezyVoice synthesis job")
    parser.add_argument("workspace")
    parser.add_argument("input_dir")
    parser.add_argument("output_dir")
    parser.add_argument("runner_config")
    args = parser.parse_args(argv)
    try:
        run_job(Path(args.workspace), Path(args.input_dir), Path(args.output_dir), Path(args.runner_config))
        return 0
    except RuntimeError as error:
        print(f"AIHUB_ERROR_CODE={error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
