"""Fixed, Hub-controlled paths for the offline Whisper Pack job image."""

from __future__ import annotations

from pathlib import Path

ASR_MODEL_DIR = Path("/models/whisper/asr/large-v3")
HUGGINGFACE_CACHE_DIR = Path("/cache/whisper/huggingface")
TORCH_CACHE_DIR = Path("/cache/whisper/torch")
ALIGNMENT_LANGUAGE = "en"
ALIGNMENT_MODEL_NAME = "WAV2VEC2_ASR_BASE_960H"
ALIGNMENT_WEIGHT = TORCH_CACHE_DIR / "wav2vec2_fairseq_base_ls960_asr_ls960.pth"

PYANNOTE_MODEL_DIR = Path("/cache/whisper/pyannote/speaker-diarization-3.1")
PYANNOTE_CONFIG = PYANNOTE_MODEL_DIR / "config.yaml"
PYANNOTE_SEGMENTATION = PYANNOTE_MODEL_DIR / "models" / "pyannote_segmentation-3.0.bin"
PYANNOTE_EMBEDDING = PYANNOTE_MODEL_DIR / "models" / "pyannote_model_wespeaker-voxceleb-resnet34-LM.bin"
OFFLINE_CACHE_MARKER = HUGGINGFACE_CACHE_DIR / ".aihub-offline-ready.json"


def offline_cache_manifest() -> dict[str, object]:
    """Return only fixed container paths; request input never controls model locations."""
    return {
        "schema": "aihub-whisper-offline-assets/v2",
        "alignment": {
            "language": ALIGNMENT_LANGUAGE,
            "model_name": ALIGNMENT_MODEL_NAME,
            "model_dir": str(TORCH_CACHE_DIR),
            "weight_path": str(ALIGNMENT_WEIGHT),
        },
        "pyannote": {
            "config_path": str(PYANNOTE_CONFIG),
            "segmentation_path": str(PYANNOTE_SEGMENTATION),
            "embedding_path": str(PYANNOTE_EMBEDDING),
        },
    }


def pyannote_config_text() -> str:
    """Legacy pyannote 3.1 config with no Hugging Face model references."""
    return f"""version: 3.1.0
pipeline:
  name: pyannote.audio.pipelines.SpeakerDiarization
  params:
    clustering: AgglomerativeClustering
    embedding: {PYANNOTE_EMBEDDING}
    embedding_batch_size: 32
    embedding_exclude_overlap: true
    segmentation: {PYANNOTE_SEGMENTATION}
    segmentation_batch_size: 32
params:
  clustering:
    method: centroid
    min_cluster_size: 12
    threshold: 0.7045654963945799
  segmentation:
    min_duration_off: 0.0
"""
