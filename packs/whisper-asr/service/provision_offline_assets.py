#!/usr/bin/env python3
"""Trusted admin-only cache preparation for the offline Whisper Pack runner."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path


def storage_root(name: str) -> Path:
    value = os.environ.get(name, "")
    path = Path(value)
    if not value or not path.is_absolute() or "\x00" in value:
        raise RuntimeError("storage_root_invalid")
    return path


def languages(value: str) -> list[str]:
    result = [language.strip().lower() for language in value.split(",") if language.strip()]
    if not result or any(not language.replace("-", "").isalnum() or len(language) > 16 for language in result):
        raise RuntimeError("languages_invalid")
    return list(dict.fromkeys(result))


def main() -> int:
    parser = argparse.ArgumentParser(description="Preprovision offline Whisper ASR assets")
    parser.add_argument("--languages", default="en", help="Comma-separated WhisperX alignment languages")
    args = parser.parse_args()
    selected_languages = languages(args.languages)
    token = os.environ.get("AIHUB_SECRET_PYANNOTE_TOKEN", "")
    if not token:
        raise RuntimeError("pyannote_token_missing")

    models_root = storage_root("AIHUB_MODELS_DIR")
    cache_root = storage_root("AIHUB_CACHE_DIR")
    asr_directory = models_root / "whisper" / "asr" / "large-v3"
    cache_directory = cache_root / "whisper" / "huggingface"
    asr_directory.mkdir(parents=True, exist_ok=True)
    cache_directory.mkdir(parents=True, exist_ok=True)
    os.environ["HF_HOME"] = str(cache_directory)
    os.environ["XDG_CACHE_HOME"] = str(cache_directory)

    from huggingface_hub import snapshot_download
    import whisperx

    snapshot_download("Systran/faster-whisper-large-v3", local_dir=str(asr_directory))
    for language in selected_languages:
        whisperx.load_align_model(language_code=language, device="cpu")
    whisperx.DiarizationPipeline(use_auth_token=token, device="cpu")
    marker = cache_directory / ".aihub-offline-ready.json"
    temporary = marker.with_suffix(".tmp")
    temporary.write_text(json.dumps({
        "schema": "aihub-whisper-offline-cache/v1",
        "alignment_languages": selected_languages,
        "pyannote_model": "pyannote/speaker-diarization-3.1",
    }, sort_keys=True) + "\n", encoding="utf-8")
    temporary.replace(marker)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
