from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import re
import sys
from pathlib import Path
from typing import Any, Callable

from long_form import BOUNDARY_ACTIONS, assemble, canonical_json, fake_synthesize, global_loudness_pass, make_plan, peak_guard, read_pcm, sha256_text, write_pcm

ALLOWED_REQUEST = {"text", "mode", "voice_prompt", "control", "seed", "seed_policy", "model", "voice_profile_id", "prompt_text", "waveform_preview", "voice_context"}
DEFAULTS = {"mode": "design", "seed": 42, "seed_policy": "derived_per_chunk", "model": "voxcpm2", "waveform_preview": False}
DEFAULT_DESIGN_PROMPT = "沉穩的台灣男性技師，語速稍慢，清楚自然"
NON_RETRYABLE_SYNTHESIS_ERRORS = {"gpu_unavailable", "model_load_failed", "runtime_dependency_missing", "sample_rate_mismatch", "job_cancelled"}
STABLE_ERROR_CODE = re.compile(r"^[a-z0-9_]{1,120}$")


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
    if not isinstance(text, str) or not text.strip() or len(text) > 50000 or mode not in {"design", "clone", "ultimate_clone"}:
        raise RuntimeError("request_invalid")
    if isinstance(seed, bool) or not isinstance(seed, int) or seed < 0 or seed > 2_147_483_647 or policy not in {"fixed", "derived_per_chunk"} or model != "voxcpm2" or not isinstance(preview, bool):
        raise RuntimeError("request_invalid")
    for field in ("voice_prompt", "control"):
        if field in value and (not isinstance(value[field], str) or not value[field].strip() or len(value[field]) > 1024):
            raise RuntimeError("request_invalid")
    profile = value.get("voice_profile_id")
    if mode == "design":
        if profile is not None or "prompt_text" in value:
            raise RuntimeError("voice_profile_forbidden")
        if not isinstance(value.get("voice_prompt"), str):
            raise RuntimeError("voice_prompt_required")
    else:
        if isinstance(profile, bool) or not isinstance(profile, int) or profile < 1:
            raise RuntimeError("voice_profile_required")
        if "voice_prompt" in value:
            raise RuntimeError("voice_profile_forbidden")
        prompt_text = value.get("prompt_text")
        if mode == "ultimate_clone":
            if not isinstance(prompt_text, str) or not prompt_text.strip() or len(prompt_text) > 20000:
                raise RuntimeError("ultimate_clone_prompt_text_required")
        elif prompt_text is not None:
            raise RuntimeError("voice_profile_forbidden")
    return value


def model_snapshot(config: dict[str, Any]) -> dict[str, Any]:
    model = config.get("model")
    expected = {"model", "label", "version", "sample_rate"}
    generic_config = set(config) == {"allowlist", "alias", "model"} and config.get("allowlist") == "voxcpm2" and config.get("alias") == "voxcpm2"
    if not (set(config) == {"model"} or generic_config) or not isinstance(model, dict) or set(model) != expected:
        raise RuntimeError("runner_config_invalid")
    if model.get("model") != "/models/voxcpm2/model" or model.get("label") != "VoxCPM2" or model.get("version") != "2.0.3" or model.get("sample_rate") != 48000:
        raise RuntimeError("runner_config_invalid")
    return model


def voice_context(request: dict[str, Any], managed_reference_path: Path | None = None) -> dict[str, Any]:
    trusted = request.get("voice_context")
    if request["mode"] == "design":
        expected = {"mode": "design", "container_path": "/data/voice_profiles/reference.wav"}
        if trusted is not None and trusted != expected:
            raise RuntimeError("voice_context_invalid")
        context = {"mode": "design", "control": request.get("control", "")}
    elif request["mode"] == "clone":
        expected = {
            "mode": "clone",
            "voice_profile_id": request["voice_profile_id"],
            "reference_audio_sha256": "",
            "container_path": "/data/voice_profiles/reference.wav",
        }
        if not isinstance(trusted, dict) or set(trusted) != set(expected) or trusted.get("mode") != expected["mode"] or trusted.get("voice_profile_id") != expected["voice_profile_id"] or trusted.get("container_path") != expected["container_path"] or not isinstance(trusted.get("reference_audio_sha256"), str) or len(trusted["reference_audio_sha256"]) != 64:
            raise RuntimeError("voice_context_invalid")
        reference = managed_reference_path or Path(trusted["container_path"])
        if not regular(reference) or hashlib.sha256(reference.read_bytes()).hexdigest() != trusted["reference_audio_sha256"]:
            raise RuntimeError("voice_profile_unavailable")
        context = {"mode": "clone", "control": request.get("control", ""), "reference_audio_sha256": trusted["reference_audio_sha256"], "container_path": trusted["container_path"]}
    else:
        expected = {
            "mode": "ultimate_clone",
            "voice_profile_id": request["voice_profile_id"],
            "reference_audio_sha256": "",
            "prompt_text_sha256": "",
            "prompt_text_confirmed_at": "",
            "container_path": "/data/voice_profiles/reference.wav",
        }
        if (not isinstance(trusted, dict) or set(trusted) != set(expected)
            or trusted.get("mode") != expected["mode"]
            or trusted.get("voice_profile_id") != expected["voice_profile_id"]
            or trusted.get("container_path") != expected["container_path"]
            or not isinstance(trusted.get("reference_audio_sha256"), str)
            or not isinstance(trusted.get("prompt_text_sha256"), str)
            or not isinstance(trusted.get("prompt_text_confirmed_at"), str)
            or not trusted["prompt_text_confirmed_at"]
            or len(trusted["reference_audio_sha256"]) != 64
            or len(trusted["prompt_text_sha256"]) != 64
            or hashlib.sha256(request["prompt_text"].encode("utf-8")).hexdigest() != trusted["prompt_text_sha256"]):
            raise RuntimeError("voice_context_invalid")
        reference = managed_reference_path or Path(trusted["container_path"])
        if not regular(reference) or hashlib.sha256(reference.read_bytes()).hexdigest() != trusted["reference_audio_sha256"]:
            raise RuntimeError("voice_profile_unavailable")
        context = {
            "mode": "ultimate_clone",
            "control": request.get("control", ""),
            "reference_audio_sha256": trusted["reference_audio_sha256"],
            "prompt_text_sha256": trusted["prompt_text_sha256"],
            "container_path": trusted["container_path"],
        }
    public_context = {key: value for key, value in context.items() if key != "container_path"}
    return context | {"sha256": sha256_text(canonical_json(public_context))}


def fake_enabled() -> bool:
    return os.getenv("VOXCPM2_JOB_FAKE_SYNTHESIS", "").lower() in {"1", "true", "yes", "on"}


def ensure_cuda_model(tts_app: Any, model_path: str, torch_module: Any) -> Any:
    if not torch_module.cuda.is_available():
        raise RuntimeError("gpu_unavailable")
    loaded = getattr(tts_app, "_MODEL", None)
    if loaded is None:
        try:
            from voxcpm import VoxCPM
        except ImportError as error:
            raise RuntimeError("runtime_dependency_missing") from error
        try:
            loaded = VoxCPM.from_pretrained(
                model_path,
                load_denoiser=False,
                optimize=os.getenv("VOXCPM2_TORCH_COMPILE", "").lower() in {"1", "true", "yes", "on"},
                device="cuda",
            )
        except Exception as error:
            if not torch_module.cuda.is_available():
                raise RuntimeError("gpu_unavailable") from error
            raise RuntimeError("model_load_failed") from error
        if not str(getattr(getattr(loaded, "tts_model", None), "device", "")).lower().startswith("cuda"):
            raise RuntimeError("gpu_unavailable")
        tts_app._MODEL = loaded
    if not torch_module.cuda.is_available() or not str(getattr(getattr(loaded, "tts_model", None), "device", "")).lower().startswith("cuda"):
        raise RuntimeError("gpu_unavailable")
    return loaded


def synthesize_chunk(chunk: dict[str, Any], voice: dict[str, Any], source: Path, model: dict[str, Any], checkpoints: Path, prompt_text: str | None = None, voice_prompt: str | None = None, cancelled: Callable[[], bool] | None = None) -> list[int]:
    if fake_enabled():
        private_voice = voice["sha256"] if voice_prompt is None else sha256_text(canonical_json({"voice_context_sha256": voice["sha256"], "voice_prompt": voice_prompt}))
        samples = fake_synthesize(chunk["text"], chunk["seed"], private_voice, model["sample_rate"])
        if cancelled and cancelled():
            raise RuntimeError("job_cancelled")
        return samples
    try:
        import torch
        import app as tts_app
    except ImportError as error:
        raise RuntimeError("runtime_dependency_missing") from error
    os.environ.update({"VOXCPM2_MODEL_ID": model["model"], "HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1"})
    ensure_cuda_model(tts_app, model["model"], torch)
    values = dict(
        text=chunk["text"],
        mode=voice["mode"],
        voice_prompt=voice_prompt,
        control=voice.get("control"),
        reference_wav_path=str(source) if voice["mode"] in {"clone", "ultimate_clone"} else None,
    )
    if voice["mode"] == "ultimate_clone":
        values |= {"prompt_wav_path": str(source), "prompt_text": prompt_text}
    request = tts_app.TtsRequest(**values)
    temporary = checkpoints / (chunk["id"] + ".model.wav")
    try:
        tts_app.write_real_wav(temporary, request, chunk["seed"], trusted_reference_paths=True)
        if cancelled and cancelled():
            raise RuntimeError("job_cancelled")
        ensure_cuda_model(tts_app, model["model"], torch)
        sample_rate, samples = read_pcm(temporary)
    finally:
        temporary.unlink(missing_ok=True)
    if sample_rate != model["sample_rate"]:
        raise RuntimeError("sample_rate_mismatch")
    return samples


def checkpoint_context(plan: dict[str, Any], model: dict[str, Any], voice: dict[str, Any], voice_prompt: str | None = None) -> dict[str, str]:
    context = {
        "plan_sha256": str(plan["plan_sha256"]),
        "text_sha256": sha256_text(str(plan["normalized_input"])),
        "voice_sha256": str(voice["sha256"]),
        "model_sha256": sha256_text(canonical_json(model)),
        "device": "fake" if fake_enabled() else "cuda",
    }
    if voice_prompt is not None:
        context["voice_prompt_sha256"] = sha256_text(voice_prompt)
    return context


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


def create_chunk(chunk: dict[str, Any], checkpoints: Path, context: dict[str, str], voice: dict[str, Any], source: Path, model: dict[str, Any], prompt_text: str | None = None, voice_prompt: str | None = None, cancelled: Callable[[], bool] | None = None) -> dict[str, Any]:
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
    checkpoints.mkdir(parents=True, exist_ok=True)
    error: Exception | None = None
    for attempt in range(1, 4):
        try:
            if cancelled and cancelled():
                raise RuntimeError("job_cancelled")
            samples, gain = peak_guard(synthesize_chunk(chunk, voice, source, model, checkpoints, prompt_text=prompt_text, voice_prompt=voice_prompt, cancelled=cancelled))
            expected |= {"duration_frames": len(samples), "attempts": attempt, "peak_gain": gain}
            checkpoints.mkdir(parents=True, exist_ok=True)
            write_pcm(wav_path, sample_rate, samples)
            write_json(metadata_path, expected)
            return chunk | {"samples": samples, "attempts": attempt, "peak_gain": gain, "reused": False}
        except RuntimeError as caught:
            if str(caught) in NON_RETRYABLE_SYNTHESIS_ERRORS:
                raise
            error = caught
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


def run_job(workspace: Path, input_dir: Path, output: Path, runner_config_path: Path, cancelled: Callable[[], bool] | None = None, managed_reference_path: Path | None = None) -> None:
    workspace = workspace.resolve()
    input_dir = input_dir.resolve()
    output = output.resolve()
    if input_dir != workspace / "input" or output != workspace / "output" or runner_config_path.resolve() != input_dir / "runner_config.json":
        raise RuntimeError("workspace_invalid")
    if managed_reference_path is not None:
        if managed_reference_path.is_symlink() or not managed_reference_path.is_file():
            raise RuntimeError("voice_profile_unavailable")
        managed_reference_path = managed_reference_path.resolve(strict=True)
        if workspace not in managed_reference_path.parents:
            raise RuntimeError("voice_profile_forbidden")
    request = validate_request(read_json(input_dir / "request.json"))
    model = model_snapshot(read_json(runner_config_path))
    source = input_dir / "source"
    voice = voice_context(request, managed_reference_path)
    if request["mode"] in {"clone", "ultimate_clone"}:
        source = managed_reference_path or Path(voice["container_path"])
    plan = make_plan(request["text"], request["seed"], request["seed_policy"], 240)
    plan_path = workspace / "checkpoints" / "plan" / "chunks.json"
    write_immutable_json(plan_path, plan, "checkpoint_plan_mismatch")
    context = checkpoint_context(plan, model, voice, request.get("voice_prompt"))
    chunks = []
    for chunk in plan["chunks"]:
        if cancelled and cancelled():
            raise RuntimeError("job_cancelled")
        chunks.append(create_chunk(chunk, workspace / "checkpoints" / "chunks", context, voice, source, model, prompt_text=request.get("prompt_text"), voice_prompt=request.get("voice_prompt"), cancelled=cancelled))
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
        "normalized_input": plan["normalized_input"], "plan": plan, "model": {key: value for key, value in model.items() if key != "model"}, "voice_context": {key: value for key, value in voice.items() if key != "container_path"}, "controls": {"mode": request["mode"], "seed_policy": request["seed_policy"], "task_seed": request["seed"]}, "chunks": chunk_metadata, "final_format": {"mime_type": "audio/wav", "sample_rate": model["sample_rate"], "channels": 1, "frames": len(final)}, "loudness": loudness, "timeline": timeline, "device": {"type": "fake", "real_inference": False} if fake_enabled() else {"type": "cuda", "real_inference": True},
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


def cli() -> int:
    try:
        result = main()
        if result == 0:
            return 0
        error_code = "runtime_execution_failed"
    except SystemExit as error:
        if error.code in (None, 0):
            return 0
        error_code = "request_invalid"
    except Exception as error:
        value = str(error)
        error_code = value if isinstance(error, RuntimeError) and STABLE_ERROR_CODE.fullmatch(value) else "runtime_execution_failed"
    print(f"AIHUB_ERROR_CODE={error_code}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(cli())
