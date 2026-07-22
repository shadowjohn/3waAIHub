#!/usr/bin/env python3
"""Trusted admin-only preparation for the fixed offline Whisper Pack assets."""

from __future__ import annotations

import argparse
import json
import os
import tempfile
from pathlib import Path

from offline_paths import (
    ALIGNMENT_LANGUAGE,
    ALIGNMENT_MARKER,
    ALIGNMENT_MODEL_NAME,
    ALIGNMENT_WEIGHT,
    ASR_MODEL_DIR,
    CKIP_MARKER,
    CKIP_MODEL_DIR,
    CKIP_MODEL_REPOSITORY,
    HUGGINGFACE_CACHE_DIR,
    PYANNOTE_CONFIG,
    PYANNOTE_EMBEDDING,
    PYANNOTE_MARKER,
    PYANNOTE_MODEL_DIR,
    PYANNOTE_SEGMENTATION,
    TORCH_CACHE_DIR,
    alignment_cache_manifest,
    ckip_cache_manifest,
    pyannote_cache_manifest,
    pyannote_config_text,
)

CKIP_CACHE_ROOT = Path("/cache")


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
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        os.fchmod(descriptor, 0o644)
        with os.fdopen(descriptor, "wb") as stream:
            stream.write(content)
        Path(temporary).replace(path)
    except BaseException:
        Path(temporary).unlink(missing_ok=True)
        raise


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


def validate_local_ckip() -> None:
    from ckip_transformers.nlp import CkipWordSegmenter

    CkipWordSegmenter(model_name=str(CKIP_MODEL_DIR), device=-1)


def require_ckip_model_directory() -> None:
    try:
        parts = CKIP_MODEL_DIR.relative_to(CKIP_CACHE_ROOT).parts
    except ValueError as error:
        raise RuntimeError("ckip_directory_invalid") from error
    current = CKIP_CACHE_ROOT
    if current.is_symlink() or not current.is_dir():
        raise RuntimeError("ckip_directory_invalid")
    for part in parts:
        current /= part
        if current.is_symlink():
            raise RuntimeError("ckip_directory_invalid")
        if current.exists():
            if not current.is_dir():
                raise RuntimeError("ckip_directory_invalid")
        else:
            current.mkdir()
            if current.is_symlink() or not current.is_dir():
                raise RuntimeError("ckip_directory_invalid")


def invalidate_ckip_marker() -> None:
    if CKIP_MARKER.is_symlink():
        CKIP_MARKER.unlink()
    elif CKIP_MARKER.exists():
        if not CKIP_MARKER.is_file():
            raise RuntimeError("ckip_marker_invalid")
        CKIP_MARKER.unlink()


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
    parser.add_argument("--with-ckip", action="store_true", help="Provision the local CKIP word-segmentation model")
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
    if args.with_ckip:
        require_ckip_model_directory()
        invalidate_ckip_marker()
        snapshot_download(CKIP_MODEL_REPOSITORY, local_dir=str(CKIP_MODEL_DIR))
        require_ckip_model_directory()
        for name in ("config.json", "pytorch_model.bin", "vocab.txt"):
            require_regular_file(CKIP_MODEL_DIR / name, "ckip_snapshot_unavailable")
    for language in selected_languages:
        precache_alignment(language)
    require_regular_file(ALIGNMENT_WEIGHT, "alignment_cache_unavailable")
    configure_offline_cache()
    write_atomic(ALIGNMENT_MARKER, (json.dumps(alignment_cache_manifest(), sort_keys=True) + "\n").encode("utf-8"))
    if args.with_ckip:
        validate_local_ckip()
        require_ckip_model_directory()
        write_atomic(CKIP_MARKER, (json.dumps(ckip_cache_manifest(), sort_keys=True) + "\n").encode("utf-8"))
    if args.with_diarization:
        PYANNOTE_MODEL_DIR.mkdir(parents=True, exist_ok=True)
        prepare_pyannote_snapshot(snapshot_download, token)
        validate_local_diarization()
        write_atomic(PYANNOTE_MARKER, (json.dumps(pyannote_cache_manifest(), sort_keys=True) + "\n").encode("utf-8"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
