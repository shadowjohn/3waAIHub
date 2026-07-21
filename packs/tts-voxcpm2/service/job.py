from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
from typing import Any

from long_form import BOUNDARY_ACTIONS, assemble, canonical_json, fake_synthesize, global_loudness_pass, make_plan, peak_guard, read_pcm, sha256_text, write_pcm

ALLOWED_REQUEST = {"text", "mode", "voice_prompt", "control", "seed", "seed_policy", "model", "voice_profile_id", "waveform_preview", "voice_context"}
DEFAULTS = {"mode": "design", "seed": 42, "seed_policy": "derived_per_chunk", "model": "voxcpm2", "waveform_preview": False}
DEFAULT_DESIGN_PROMPT = "沉穩的台灣男性技師，語速稍慢，清楚自然"


def read_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        raise RuntimeError("request_invalid") from error
    if not isinstance(value, dict):
        raise RuntimeError("request_invalid")
    return value


def regular(path: Path) -> bool:
    return path.is_file() and not path.is_symlink()


def write_json(path: Path, value: dict[str, Any]) -> None:
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_text(canonical_json(value) + "\n", encoding="utf-8")
    temporary.replace(path)


def write_immutable_json(path: Path, value: dict[str, Any], error_code: str) -> None:
    encoded = canonical_json(value) + "\n"
    if path.exists():
        if path.is_symlink() or path.read_text(encoding="utf-8") != encoded:
            raise RuntimeError(error_code)
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    write_json(path, value)


def validate_request(value: dict[str, Any]) -> dict[str, Any]:
    if set(value) - ALLOWED_REQUEST:
        raise RuntimeError("request_invalid")
    value = DEFAULTS | value
    if value["mode"] == "design" and "voice_prompt" not in value:
        value["voice_prompt"] = DEFAULT_DESIGN_PROMPT
    text = value.get("text")
    mode = value.get("mode")
    seed = value.get("seed")
    policy = value.get("seed_policy")
    model = value.get("model")
    preview = value.get("waveform_preview")
    if not isinstance(text, str) or not text.strip() or len(text) > 50000 or mode not in {"design", "clone"}:
        raise RuntimeError("request_invalid")
    if isinstance(seed, bool) or not isinstance(seed, int) or seed < 0 or seed > 2_147_483_647 or policy not in {"fixed", "derived_per_chunk"} or model != "voxcpm2" or not isinstance(preview, bool):
        raise RuntimeError("request_invalid")
    for field in ("voice_prompt", "control"):
        if field in value and (not isinstance(value[field], str) or not value[field].strip() or len(value[field]) > 1024):
            raise RuntimeError("request_invalid")
    profile = value.get("voice_profile_id")
    if mode == "design":
        if profile is not None:
            raise RuntimeError("voice_profile_forbidden")
        if not isinstance(value.get("voice_prompt"), str):
            raise RuntimeError("voice_prompt_required")
    else:
        if isinstance(profile, bool) or not isinstance(profile, int) or profile < 1:
            raise RuntimeError("voice_profile_required")
        if "voice_prompt" in value:
            raise RuntimeError("voice_profile_forbidden")
    return value


def model_snapshot(config: dict[str, Any]) -> dict[str, Any]:
    model = config.get("model")
    expected = {"model", "label", "version", "sample_rate"}
    if set(config) != {"model"} or not isinstance(model, dict) or set(model) != expected:
        raise RuntimeError("runner_config_invalid")
    if model.get("model") != "/models/voxcpm2/model" or model.get("label") != "VoxCPM2" or model.get("version") != "2.0.3" or model.get("sample_rate") != 48000:
        raise RuntimeError("runner_config_invalid")
    return model


def voice_context(request: dict[str, Any]) -> dict[str, Any]:
    trusted = request.get("voice_context")
    if request["mode"] == "design":
        expected = {"mode": "design", "container_path": "/data/voice_profiles/reference.wav"}
        if trusted is not None and trusted != expected:
            raise RuntimeError("voice_context_invalid")
        context = {"mode": "design", "voice_prompt": request["voice_prompt"], "control": request.get("control", "")}
    else:
        expected = {
            "mode": "clone",
            "voice_profile_id": request["voice_profile_id"],
            "reference_audio_sha256": "",
            "container_path": "/data/voice_profiles/reference.wav",
        }
        if not isinstance(trusted, dict) or set(trusted) != set(expected) or trusted.get("mode") != expected["mode"] or trusted.get("voice_profile_id") != expected["voice_profile_id"] or trusted.get("container_path") != expected["container_path"] or not isinstance(trusted.get("reference_audio_sha256"), str) or len(trusted["reference_audio_sha256"]) != 64:
            raise RuntimeError("voice_context_invalid")
        reference = Path(trusted["container_path"])
        if not regular(reference) or hashlib.sha256(reference.read_bytes()).hexdigest() != trusted["reference_audio_sha256"]:
            raise RuntimeError("voice_profile_unavailable")
        context = {"mode": "clone", "voice_profile_id": request["voice_profile_id"], "control": request.get("control", ""), "reference_audio_sha256": trusted["reference_audio_sha256"], "container_path": trusted["container_path"]}
    return context | {"sha256": sha256_text(canonical_json(context))}


def fake_enabled() -> bool:
    return os.getenv("VOXCPM2_JOB_FAKE_SYNTHESIS", "").lower() in {"1", "true", "yes", "on"}


def synthesize_chunk(chunk: dict[str, Any], voice: dict[str, Any], source: Path, model: dict[str, Any], checkpoints: Path) -> list[int]:
    if fake_enabled():
        return fake_synthesize(chunk["text"], chunk["seed"], voice["sha256"], model["sample_rate"])
    try:
        import torch
        from app import TtsRequest, write_real_wav
    except ImportError as error:
        raise RuntimeError("runtime_dependency_missing") from error
    if not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")
    os.environ.update({"VOXCPM2_MODEL_ID": model["model"], "HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1"})
    request = TtsRequest(
        text=chunk["text"],
        mode=voice["mode"],
        voice_prompt=voice.get("voice_prompt"),
        control=voice.get("control"),
        reference_wav_path=str(source) if voice["mode"] == "clone" else None,
    )
    temporary = checkpoints / (chunk["id"] + ".model.wav")
    try:
        write_real_wav(temporary, request, chunk["seed"])
        sample_rate, samples = read_pcm(temporary)
    finally:
        temporary.unlink(missing_ok=True)
    if sample_rate != model["sample_rate"]:
        raise RuntimeError("sample_rate_mismatch")
    return samples


def checkpoint_context(plan: dict[str, Any], model: dict[str, Any], voice: dict[str, Any]) -> dict[str, str]:
    return {
        "plan_sha256": str(plan["plan_sha256"]),
        "text_sha256": sha256_text(str(plan["normalized_input"])),
        "voice_sha256": str(voice["sha256"]),
        "model_sha256": sha256_text(canonical_json(model)),
    }


def cached_chunk(path: Path, metadata_path: Path, expected: dict[str, Any], sample_rate: int) -> tuple[list[int], dict[str, Any]] | None:
    if not path.exists() and not metadata_path.exists():
        return None
    if not regular(path) or not regular(metadata_path):
        return None
    try:
        metadata = read_json(metadata_path)
        rate, samples = read_pcm(path)
    except RuntimeError:
        return None
    immutable = {key: expected[key] for key in ("chunk_id", "text_sha256", "seed", "seed_sha256", "context")}
    if rate != sample_rate or any(metadata.get(key) != value for key, value in immutable.items()) or not isinstance(metadata.get("attempts"), int) or not 1 <= metadata["attempts"] <= 3 or not isinstance(metadata.get("peak_gain"), (int, float)) or len(samples) != metadata.get("duration_frames"):
        return None
    return samples, metadata


def create_chunk(chunk: dict[str, Any], checkpoints: Path, context: dict[str, str], voice: dict[str, Any], source: Path, model: dict[str, Any]) -> dict[str, Any]:
    sample_rate = model["sample_rate"]
    wav_path = checkpoints / (chunk["id"] + ".wav")
    metadata_path = checkpoints / (chunk["id"] + ".json")
    expected = {
        "chunk_id": chunk["id"],
        "text_sha256": chunk["text_sha256"],
        "seed": chunk["seed"],
        "seed_sha256": chunk["seed_sha256"],
        "context": context,
        "duration_frames": 0,
        "attempts": 0,
        "peak_gain": 0.0,
    }
    cached = cached_chunk(wav_path, metadata_path, expected, sample_rate)
    if cached is not None:
        samples, metadata = cached
        return chunk | {"samples": samples, "attempts": metadata["attempts"], "peak_gain": metadata["peak_gain"], "reused": True}
    if wav_path.exists() or metadata_path.exists():
        wav_path.unlink(missing_ok=True)
        metadata_path.unlink(missing_ok=True)
    error: Exception | None = None
    for attempt in range(1, 4):
        try:
            samples, gain = peak_guard(synthesize_chunk(chunk, voice, source, model, checkpoints))
            expected |= {"duration_frames": len(samples), "attempts": attempt, "peak_gain": gain}
            checkpoints.mkdir(parents=True, exist_ok=True)
            write_pcm(wav_path, sample_rate, samples)
            write_json(metadata_path, expected)
            return chunk | {"samples": samples, "attempts": attempt, "peak_gain": gain, "reused": False}
        except Exception as caught:
            error = caught
    raise RuntimeError("chunk_synthesis_failed") from error


def waveform(samples: list[int], sample_rate: int) -> dict[str, Any]:
    count = min(256, max(1, len(samples)))
    step = max(1, math.ceil(len(samples) / count))
    return {"sample_rate": sample_rate, "duration_seconds": len(samples) / sample_rate, "peaks": [max((abs(sample) for sample in samples[index:index + step]), default=0) / 32767 for index in range(0, len(samples), step)]}


def clean_output(output: Path, preview: bool) -> None:
    output.mkdir(parents=True, exist_ok=True)
    for name in ("generated_audio.wav", "synthesis_metadata.json", "waveform_preview.json"):
        path = output / name
        if path.exists() and (path.is_symlink() or not path.is_file()):
            raise RuntimeError("output_invalid")
        if path.exists() and (name != "waveform_preview.json" or not preview):
            path.unlink()


def run_job(workspace: Path, input_dir: Path, output: Path, runner_config_path: Path) -> None:
    workspace = workspace.resolve()
    input_dir = input_dir.resolve()
    output = output.resolve()
    if input_dir != workspace / "input" or output != workspace / "output" or runner_config_path.resolve() != input_dir / "runner_config.json":
        raise RuntimeError("workspace_invalid")
    request = validate_request(read_json(input_dir / "request.json"))
    model = model_snapshot(read_json(runner_config_path))
    source = input_dir / "source"
    voice = voice_context(request)
    source = Path(voice["container_path"]) if request["mode"] == "clone" else input_dir / "source"
    plan = make_plan(request["text"], request["seed"], request["seed_policy"], 240)
    plan_path = workspace / "checkpoints" / "plan" / "chunks.json"
    write_immutable_json(plan_path, plan, "checkpoint_plan_mismatch")
    context = checkpoint_context(plan, model, voice)
    chunks = [create_chunk(chunk, workspace / "checkpoints" / "chunks", context, voice, source, model) for chunk in plan["chunks"]]
    final, timeline = assemble(chunks, model["sample_rate"])
    final, loudness = global_loudness_pass(final)
    clean_output(output, request["waveform_preview"])
    write_pcm(output / "generated_audio.wav", model["sample_rate"], final)
    chunk_metadata = []
    for chunk in chunks:
        boundary = chunk["boundary"]
        if boundary["action"] not in BOUNDARY_ACTIONS:
            raise RuntimeError("boundary_action_invalid")
        chunk_metadata.append({
            "id": chunk["id"], "seed": chunk["seed"], "seed_sha256": chunk["seed_sha256"], "attempts": chunk["attempts"], "duration_frames": len(chunk["samples"]), "duration_seconds": len(chunk["samples"]) / model["sample_rate"], "peak_gain": chunk["peak_gain"], "reused_checkpoint": chunk["reused"], "action": boundary["action"], "trim_frames": boundary["trim_frames"], "pause_frames": boundary["pause_frames"], "crossfade_frames": boundary["crossfade_frames"],
        })
    metadata = {
        "normalized_input": plan["normalized_input"], "plan": plan, "model": model, "voice_context": voice, "controls": {"mode": request["mode"], "seed_policy": request["seed_policy"], "task_seed": request["seed"]}, "chunks": chunk_metadata, "final_format": {"mime_type": "audio/wav", "sample_rate": model["sample_rate"], "channels": 1, "frames": len(final)}, "loudness": loudness, "timeline": timeline,
    }
    write_json(output / "synthesis_metadata.json", metadata)
    if request["waveform_preview"]:
        write_json(output / "waveform_preview.json", waveform(final, model["sample_rate"]))


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
        print(f"voice_generate_failed:{error}", file=os.sys.stderr)
        raise SystemExit(1)
