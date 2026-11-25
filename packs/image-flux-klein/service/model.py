import json
from pathlib import Path

MODEL_ID = "black-forest-labs/FLUX.2-klein-4B"
REVISION = "e7b7dc27f91deacad38e78976d1f2b499d76a294"
MANIFEST = "flux-model.json"

WEIGHTS = {
    "text_encoder/model-00001-of-00002.safetensors": (
        4967215360, "8c0506e7f4936fa7e26183a4fd8da4e2bdbc5990ba64ae441f965d51228f36ea"
    ),
    "text_encoder/model-00002-of-00002.safetensors": (
        3077766632, "82f2bd839378541b0557bfabaf37c7d3d637071fdcb73302dedd7cf61162ce07"
    ),
    "transformer/diffusion_pytorch_model.safetensors": (
        7751109744, "9f29f9edcfdae452a653ffb51a534ca4decd389952c225724ff3b94042612a6e"
    ),
    "vae/diffusion_pytorch_model.safetensors": (
        168120878, "ca70d2202afe6415bdbcb8793ba8cd99fd159cfe6192381504d6c4d3036e0f04"
    ),
}
CONFIGS = (
    "LICENSE.md",
    "model_index.json",
    "scheduler/scheduler_config.json",
    "text_encoder/config.json",
    "text_encoder/model.safetensors.index.json",
    "tokenizer/tokenizer.json",
    "tokenizer/tokenizer_config.json",
    "transformer/config.json",
    "vae/config.json",
)


def check_snapshot(root: Path, require_manifest: bool = True) -> None:
    if require_manifest:
        marker = root / MANIFEST
        if marker.stat().st_size > 4096:
            raise ValueError("Invalid model manifest")
        if json.loads(marker.read_text(encoding="utf-8")) != {"model": MODEL_ID, "revision": REVISION}:
            raise ValueError("Model snapshot revision differs")
    for name in CONFIGS:
        if not (root / name).is_file():
            raise ValueError(f"Missing snapshot file: {name}")
    for name, (length, _) in WEIGHTS.items():
        if (root / name).stat().st_size != length:
            raise ValueError(f"Unexpected weight size: {name}")
