#!/usr/bin/env python3
"""Trusted admin-only preparation for the fixed offline Whisper Pack assets."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path

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
    PYANNOTE_MODEL_DIR,
    PYANNOTE_SEGMENTATION,
    TORCH_CACHE_DIR,
    alignment_cache_manifest,
    pyannote_cache_manifest,
    pyannote_config_text,
)


def storage_root(name: str, expected: Path) -> Path:
    value = os.environ.get(name, "")
    path = Path(value)
    if not value or not path.is_absolute() or "\x00" in value or path != expected:
        raise RuntimeError("storage_root_invalid")
    return path


def languages(value: str) -> list[str]:
    result = [language.strip().lower() for language in value.split(",") if language.strip()]
    if result != [ALIGNMENT_LANGUAGE]:
        raise RuntimeError("alignment_language_unsupported")
    return result


def configure_download_cache() -> None:
    for name in ("HF_HUB_OFFLINE", "TRANSFORMERS_OFFLINE", "HF_DATASETS_OFFLINE"):
        os.environ.pop(name, None)
    os.environ["HF_HOME"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["HUGGINGFACE_HUB_CACHE"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["TRANSFORMERS_CACHE"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["XDG_CACHE_HOME"] = str(HUGGINGFACE_CACHE_DIR)
    os.environ["TORCH_HOME"] = str(TORCH_CACHE_DIR)
    os.environ["PYANNOTE_METRICS_ENABLED"] = "0"


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


def require_regular_file(path: Path, error_code: str) -> None:
    if not path.is_file() or path.is_symlink():
        raise RuntimeError(error_code)


def write_atomic(path: Path, content: bytes) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".tmp")
    temporary.write_bytes(content)
    temporary.replace(path)


def copy_regular_file(source: Path, destination: Path) -> None:
    require_regular_file(source, "pyannote_snapshot_unavailable")
    write_atomic(destination, source.read_bytes())
    require_regular_file(destination, "pyannote_snapshot_unavailable")


def precache_alignment(language: str) -> None:
    if language != ALIGNMENT_LANGUAGE:
        raise RuntimeError("alignment_language_unsupported")
    import whisperx

    whisperx.load_align_model(
        language_code=language,
        device="cpu",
        model_name=ALIGNMENT_MODEL_NAME,
        model_dir=str(TORCH_CACHE_DIR),
    )


def validate_local_diarization() -> None:
    import whisperx

    whisperx.DiarizationPipeline(model_name=str(PYANNOTE_CONFIG), use_auth_token=None, device="cpu")


def prepare_pyannote_snapshot(snapshot_download: object, token: str) -> None:
    downloader = snapshot_download
    if not callable(downloader):
        raise RuntimeError("pyannote_snapshot_unavailable")
    pipeline_source = PYANNOTE_MODEL_DIR / "snapshot" / "pipeline"
    segmentation_source = PYANNOTE_MODEL_DIR / "snapshot" / "segmentation-3.0"
    embedding_source = PYANNOTE_MODEL_DIR / "snapshot" / "wespeaker-voxceleb-resnet34-LM"
    downloader("pyannote/speaker-diarization-3.1", local_dir=str(pipeline_source), allow_patterns=["config.yaml"], token=token)
    downloader("pyannote/segmentation-3.0", local_dir=str(segmentation_source), allow_patterns=["pytorch_model.bin"], token=token)
    downloader("pyannote/wespeaker-voxceleb-resnet34-LM", local_dir=str(embedding_source), allow_patterns=["pytorch_model.bin"], token=token)
    require_regular_file(pipeline_source / "config.yaml", "pyannote_snapshot_unavailable")
    copy_regular_file(segmentation_source / "pytorch_model.bin", PYANNOTE_SEGMENTATION)
    copy_regular_file(embedding_source / "pytorch_model.bin", PYANNOTE_EMBEDDING)
    write_atomic(PYANNOTE_CONFIG, pyannote_config_text().encode("utf-8"))
    require_regular_file(PYANNOTE_CONFIG, "pyannote_snapshot_unavailable")


def main() -> int:
    parser = argparse.ArgumentParser(description="Preprovision offline Whisper ASR assets")
    parser.add_argument("--languages", default=ALIGNMENT_LANGUAGE, help="Fixed WhisperX alignment language (en)")
    parser.add_argument("--with-diarization", action="store_true", help="Provision the local pyannote assets using the trusted token")
    args = parser.parse_args()
    selected_languages = languages(args.languages)
    token = os.environ.get("AIHUB_SECRET_PYANNOTE_TOKEN", "") if args.with_diarization else ""
    if args.with_diarization and not token:
        raise RuntimeError("pyannote_token_missing")

    storage_root("AIHUB_MODELS_DIR", Path("/models"))
    storage_root("AIHUB_CACHE_DIR", Path("/cache"))
    ASR_MODEL_DIR.mkdir(parents=True, exist_ok=True)
    HUGGINGFACE_CACHE_DIR.mkdir(parents=True, exist_ok=True)
    TORCH_CACHE_DIR.mkdir(parents=True, exist_ok=True)
    configure_download_cache()

    from huggingface_hub import snapshot_download

    snapshot_download("Systran/faster-whisper-large-v3", local_dir=str(ASR_MODEL_DIR))
    for language in selected_languages:
        precache_alignment(language)
    require_regular_file(ALIGNMENT_WEIGHT, "alignment_cache_unavailable")
    configure_offline_cache()
    write_atomic(ALIGNMENT_MARKER, (json.dumps(alignment_cache_manifest(), sort_keys=True) + "\n").encode("utf-8"))
    if args.with_diarization:
        PYANNOTE_MODEL_DIR.mkdir(parents=True, exist_ok=True)
        prepare_pyannote_snapshot(snapshot_download, token)
        validate_local_diarization()
        write_atomic(PYANNOTE_MARKER, (json.dumps(pyannote_cache_manifest(), sort_keys=True) + "\n").encode("utf-8"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
