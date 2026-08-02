from __future__ import annotations

import argparse
import audioop
import hashlib
import json
import os
import re
import subprocess
import sys
import time
import wave
from pathlib import Path
from typing import Any, Callable


ALLOWED_REQUEST = {
    "text",
    "mode",
    "voice_profile_id",
    "model",
    "language",
    "waveform_preview",
    "voice_context",
    "prompt_text",
}
CONTAINER_REFERENCE = "/data/voice_profiles/reference.wav"
MODEL_ROOT = Path(os.getenv("GPT_SOVITS_MODEL_DIR", "/models/gpt_sovits"))
UPSTREAM_ROOT = Path("/opt/gpt-sovits")
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


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for block in iter(lambda: source.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def sha256_text(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def wav_seconds(path: Path) -> float:
    try:
        with wave.open(str(path), "rb") as source:
            rate = source.getframerate()
            if rate <= 0:
                raise RuntimeError("reference_audio_invalid")
            return source.getnframes() / rate
    except (OSError, wave.Error) as error:
        raise RuntimeError("reference_audio_invalid") from error


def trim_seconds_at_boundary(path: Path) -> float:
    duration = wav_seconds(path)
    if duration < 3.0:
        raise RuntimeError("reference_audio_too_short")
    if duration <= 10.0:
        return duration

    try:
        with wave.open(str(path), "rb") as source:
            if source.getsampwidth() != 2 or source.getnchannels() < 1:
                return 5.0
            rate = source.getframerate()
            frame_width = source.getsampwidth() * source.getnchannels()
            candidates: list[tuple[int, int]] = []
            for milliseconds in range(3000, 7001, 100):
                frame = int(milliseconds * rate / 1000)
                source.setpos(max(0, frame - int(rate * 0.075)))
                payload = source.readframes(int(rate * 0.15))
                if len(payload) < frame_width:
                    continue
                candidates.append((milliseconds, audioop.rms(payload, source.getsampwidth())))
    except (OSError, wave.Error, audioop.error):
        return 5.0

    quiet = [candidate for candidate in candidates if candidate[1] <= 700]
    if quiet:
        return min(quiet, key=lambda candidate: (abs(candidate[0] - 5000), candidate[1]))[0] / 1000.0
    return 5.0


def prompt_for_excerpt(prompt_text: str | None, source_seconds: float, staged_seconds: float) -> str | None:
    if prompt_text is None:
        return None
    prompt = prompt_text.strip()
    if not prompt:
        raise RuntimeError("ultimate_clone_prompt_text_required")
    if staged_seconds >= source_seconds:
        return prompt

    limit = max(1, int(len(prompt) * staged_seconds / source_seconds))
    candidate = prompt[:limit]
    boundary = max(candidate.rfind(mark) for mark in "。！？；，,.!?")
    return candidate[: boundary + 1].strip() if boundary >= len(candidate) // 2 else candidate.strip()


def normalize_reference(source: Path, prompt_text: str | None, stage_dir: Path) -> tuple[Path, str | None]:
    if not regular(source):
        raise RuntimeError("voice_profile_unavailable")
    source_seconds = wav_seconds(source)
    seconds = trim_seconds_at_boundary(source)
    stage_dir.mkdir(parents=True, exist_ok=True)
    staged = stage_dir / "reference.wav"
    command = [
        "ffmpeg",
        "-nostdin",
        "-loglevel",
        "error",
        "-y",
        "-i",
        str(source),
        "-ac",
        "1",
        "-ar",
        "32000",
        "-t",
        f"{seconds:.3f}",
        str(staged),
    ]
    try:
        completed = subprocess.run(command, check=False, capture_output=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired) as error:
        raise RuntimeError("reference_normalize_failed") from error
    if completed.returncode != 0 or not regular(staged):
        raise RuntimeError("reference_normalize_failed")
    staged_seconds = wav_seconds(staged)
    if not 3.0 <= staged_seconds <= 10.05:
        raise RuntimeError("reference_normalize_failed")
    return staged, prompt_for_excerpt(prompt_text, source_seconds, staged_seconds)


def validate_request(value: dict[str, Any]) -> dict[str, Any]:
    if set(value) - ALLOWED_REQUEST:
        raise RuntimeError("request_invalid")
    text = value.get("text")
    mode = value.get("mode", "clone")
    profile_id = value.get("voice_profile_id")
    model = value.get("model", "gpt_sovits_v2")
    language = value.get("language", "zh_tw")
    preview = value.get("waveform_preview", False)
    context = value.get("voice_context")
    if not isinstance(text, str) or not text.strip() or len(text) > 4096:
        raise RuntimeError("request_invalid")
    if mode not in {"clone", "ultimate_clone"} or isinstance(profile_id, bool) or not isinstance(profile_id, int) or profile_id < 1:
        raise RuntimeError("request_invalid")
    if model != "gpt_sovits_v2" or language not in {"zh", "zh_tw", "auto"} or not isinstance(preview, bool):
        raise RuntimeError("request_invalid")
    expected = {
        "mode": mode,
        "voice_profile_id": profile_id,
        "reference_audio_sha256": "",
        "container_path": CONTAINER_REFERENCE,
    }
    if mode == "ultimate_clone":
        prompt = value.get("prompt_text")
        if not isinstance(prompt, str) or not prompt.strip() or len(prompt) > 20000:
            raise RuntimeError("ultimate_clone_prompt_text_required")
        expected |= {
            "prompt_text_sha256": sha256_text(prompt),
            "prompt_text_confirmed_at": "",
        }
    elif "prompt_text" in value:
        raise RuntimeError("request_invalid")
    if not isinstance(context, dict) or set(context) != set(expected):
        raise RuntimeError("voice_context_invalid")
    if context.get("mode") != expected["mode"] or context.get("voice_profile_id") != profile_id or context.get("container_path") != CONTAINER_REFERENCE:
        raise RuntimeError("voice_context_invalid")
    if not isinstance(context.get("reference_audio_sha256"), str) or not re.fullmatch(r"[a-f0-9]{64}", context["reference_audio_sha256"]):
        raise RuntimeError("voice_context_invalid")
    if mode == "ultimate_clone":
        if context.get("prompt_text_sha256") != expected["prompt_text_sha256"] or not isinstance(context.get("prompt_text_confirmed_at"), str) or not context["prompt_text_confirmed_at"].strip():
            raise RuntimeError("voice_context_invalid")
    return value | {"mode": mode, "model": model, "language": language, "waveform_preview": preview}


def local_model_paths() -> dict[str, Path]:
    paths = {
        "gpt": MODEL_ROOT / "checkpoints/gpt_v2.ckpt",
        "sovits": MODEL_ROOT / "checkpoints/sovits_v2.pth",
        "hubert": MODEL_ROOT / "pretrained_models/chinese-hubert-base",
        "roberta": MODEL_ROOT / "pretrained_models/chinese-roberta-wwm-ext-large",
        "g2pw": MODEL_ROOT / "g2pw",
        "nltk": MODEL_ROOT / "nltk_data",
    }
    if (
        not regular(paths["gpt"])
        or not regular(paths["sovits"])
        or not regular(paths["hubert"] / "config.json")
        or not regular(paths["hubert"] / "pytorch_model.bin")
        or not regular(paths["hubert"] / "preprocessor_config.json")
        or not regular(paths["roberta"] / "config.json")
        or not regular(paths["roberta"] / "pytorch_model.bin")
        or not regular(paths["roberta"] / "tokenizer.json")
        or not regular(paths["g2pw"] / "config.py")
        or not regular(paths["g2pw"] / "g2pW.onnx")
        or not regular(paths["g2pw"] / "POLYPHONIC_CHARS.txt")
        or not regular(paths["nltk"] / "corpora/cmudict/cmudict")
        or not regular(paths["nltk"] / "corpora/cmudict.zip")
        or not regular(paths["nltk"] / "taggers/averaged_perceptron_tagger/averaged_perceptron_tagger.pickle")
        or not regular(paths["nltk"] / "taggers/averaged_perceptron_tagger.zip")
        or not regular(paths["nltk"] / "taggers/averaged_perceptron_tagger_eng/averaged_perceptron_tagger_eng.weights.json")
        or not regular(paths["nltk"] / "taggers/averaged_perceptron_tagger_eng.zip")
    ):
        raise RuntimeError("model_assets_unavailable")
    return paths


def configure_offline_language_detector() -> None:
    try:
        import fast_langdetect

        fast_langdetect.infer._default_detector.config.model = "lite"
        original_detect = fast_langdetect.detect
        if getattr(original_detect, "_hub_offline_lite", False):
            return

        def detect_lite(text: str, **kwargs: Any) -> Any:
            kwargs["model"] = "lite"
            return original_detect(text, **kwargs)

        detect_lite._hub_offline_lite = True
        fast_langdetect.detect = detect_lite
    except (ImportError, AttributeError) as error:
        raise RuntimeError("runtime_dependency_missing") from error


def configure_local_model_environment(paths: dict[str, Path]) -> None:
    os.environ["bert_path"] = str(paths["roberta"])
    os.environ["NLTK_DATA"] = str(paths["nltk"])


def load_runtime() -> Any:
    try:
        import torch
    except ImportError as error:
        raise RuntimeError("runtime_dependency_missing") from error
    if not torch.cuda.is_available():
        raise RuntimeError("gpu_unavailable")
    paths = local_model_paths()
    if not UPSTREAM_ROOT.is_dir():
        raise RuntimeError("runtime_dependency_missing")
    os.environ.update({"HF_HUB_OFFLINE": "1", "TRANSFORMERS_OFFLINE": "1", "HF_DATASETS_OFFLINE": "1"})
    configure_local_model_environment(paths)
    sys.path.insert(0, str(UPSTREAM_ROOT))
    try:
        from GPT_SoVITS.TTS_infer_pack.TTS import TTS, TTS_Config
        configure_offline_language_detector()
        config = TTS_Config({"custom": {
            "device": "cuda",
            "is_half": True,
            "version": "v2",
            "t2s_weights_path": str(paths["gpt"]),
            "vits_weights_path": str(paths["sovits"]),
            "bert_base_path": str(paths["roberta"]),
            "cnhuhbert_base_path": str(paths["hubert"]),
        }})
        return TTS(config)
    except RuntimeError:
        raise
    except Exception as error:
        raise RuntimeError("model_load_failed") from error


def normalize_text(value: str) -> str:
    try:
        from opencc import OpenCC
        return OpenCC("tw2s").convert(value)
    except ImportError:
        return value


def synthesize(runtime: Any, request: dict[str, Any], reference: Path, prompt_text: str | None, output: Path) -> None:
    try:
        import numpy
        import soundfile
    except ImportError as error:
        raise RuntimeError("runtime_dependency_missing") from error
    language = "zh" if request["language"] in {"zh", "zh_tw"} else "auto"
    inputs = {
        "text": normalize_text(request["text"]),
        "text_lang": language,
        "ref_audio_path": str(reference),
        "prompt_text": normalize_text(prompt_text) if prompt_text is not None else "",
        "prompt_lang": language,
        "text_split_method": "cut5",
        "batch_size": 1,
        "split_bucket": True,
        "parallel_infer": True,
        "return_fragment": False,
        "streaming_mode": False,
    }
    try:
        chunks = list(runtime.run(inputs))
        if not chunks:
            raise RuntimeError("tts_failed")
        sample_rate = int(chunks[0][0])
        audio = numpy.concatenate([numpy.asarray(chunk, dtype="float32") for _, chunk in chunks])
        if sample_rate <= 0 or audio.size == 0:
            raise RuntimeError("tts_failed")
        output.parent.mkdir(parents=True, exist_ok=True)
        soundfile.write(output, audio, sample_rate, format="WAV")
    except RuntimeError:
        raise
    except Exception as error:
        raise RuntimeError("tts_failed") from error


def run_job(
    workspace: Path,
    input_dir: Path,
    output_dir: Path,
    runtime_loader: Callable[[], Any] = load_runtime,
    managed_reference_path: Path | None = None,
    cancelled: Callable[[], bool] | None = None,
) -> dict[str, Any]:
    request = validate_request(read_json(input_dir / "request.json"))
    context = request["voice_context"]
    reference = managed_reference_path or Path(context["container_path"])
    if not regular(reference) or sha256_file(reference) != context["reference_audio_sha256"]:
        raise RuntimeError("voice_profile_unavailable")
    if cancelled is not None and cancelled():
        raise RuntimeError("job_cancelled")
    started = time.monotonic()
    staged, prompt = normalize_reference(reference, request.get("prompt_text"), workspace / "checkpoints")
    synthesize(runtime_loader(), request, staged, prompt, output_dir / "generated_audio.wav")
    if cancelled is not None and cancelled():
        raise RuntimeError("job_cancelled")
    metadata = {
        "mode": request["mode"],
        "model": "GPT-SoVITS V2",
        "reference_seconds": round(wav_seconds(staged), 3),
        "text_chars": len(request["text"]),
        "device": "cuda",
        "elapsed_ms": int((time.monotonic() - started) * 1000),
    }
    (output_dir / "synthesis_metadata.json").write_text(json.dumps(metadata, ensure_ascii=False, separators=(",", ":")) + "\n", encoding="utf-8")
    return metadata


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--workspace", required=True)
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args(argv)
    try:
        run_job(Path(args.workspace), Path(args.input), Path(args.output))
        return 0
    except RuntimeError as error:
        code = str(error)
        code = code if STABLE_ERROR_CODE.fullmatch(code) else "tts_failed"
        print(f"AIHUB_ERROR_CODE={code}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
