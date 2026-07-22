from __future__ import annotations

import argparse
import io
import json
from pathlib import Path

import numpy as np
import requests
from PIL import Image

from provision_offline_assets import MODEL_REPOSITORY, MODEL_REVISION


FIXTURES = ("person_hair", "white_product", "animal_fur")
MODEL_HEADER = f"{MODEL_REPOSITORY}@{MODEL_REVISION}"
REQUIRED_HEADERS = (
    "X-3waAIHub-Model",
    "X-3waAIHub-Device",
    "X-3waAIHub-Elapsed-Ms",
    "X-3waAIHub-Width",
    "X-3waAIHub-Height",
)


def score_masks(pred: np.ndarray, truth: np.ndarray) -> dict[str, float]:
    mae = float(np.abs(pred - truth).mean())
    pred_fg = pred >= 0.5
    truth_fg = truth >= 0.5
    tp = int(np.logical_and(pred_fg, truth_fg).sum())
    fp = int(np.logical_and(pred_fg, ~truth_fg).sum())
    fn = int(np.logical_and(~pred_fg, truth_fg).sum())
    precision = tp / max(tp + fp, 1)
    recall = tp / max(tp + fn, 1)
    f_score = 2 * precision * recall / max(precision + recall, 1e-12)
    return {"mae": mae, "precision": precision, "recall": recall, "f_score": f_score}


def run_fixture(base_url: str, fixtures: Path, name: str, expect_device: str) -> dict[str, object]:
    source_path = fixtures / f"{name}.png"
    mask_path = fixtures / f"{name}_mask.png"
    with Image.open(source_path) as source, Image.open(mask_path) as mask:
        source.load()
        mask.load()
        if source.mode != "RGB" or mask.mode != "L" or source.size != mask.size:
            raise ValueError("fixture_contract_failed")
        truth_bytes = np.asarray(mask, dtype=np.uint8)
        truth = truth_bytes.astype(np.float32) / 255.0
        truth_levels = (
            bool((truth_bytes == 0).any()),
            bool((truth_bytes == 255).any()),
            bool(((truth_bytes > 0) & (truth_bytes < 255)).any()),
        )
        expected_size = source.size

    with source_path.open("rb") as source_file:
        response = requests.post(
            base_url.rstrip("/") + "/remove-background/image",
            files={"image": (source_path.name, source_file, "image/png")},
            data={"output": "cutout", "feather_px": "0", "edge_offset_px": "0", "defringe": "0"},
            timeout=180,
        )
    header_values = {name: response.headers.get(name, "") for name in REQUIRED_HEADERS}
    elapsed = header_values["X-3waAIHub-Elapsed-Ms"]
    with Image.open(io.BytesIO(response.content)) as output:
        output.load()
        output_mode = output.mode
        output_size = output.size
        alpha_bytes = np.asarray(output.getchannel("A"), dtype=np.uint8) if output.mode == "RGBA" else np.zeros((1, 1), dtype=np.uint8)
    pred = alpha_bytes.astype(np.float32) / 255.0
    metrics = score_masks(pred, truth) if pred.shape == truth.shape else {"mae": 1.0, "precision": 0.0, "recall": 0.0, "f_score": 0.0}
    structural_ok = (
        response.status_code == 200
        and response.headers.get("Content-Type") == "image/png"
        and response.content.startswith(b"\x89PNG\r\n\x1a\n")
        and all(header_values.values())
        and header_values["X-3waAIHub-Model"] == MODEL_HEADER
        and header_values["X-3waAIHub-Device"] == expect_device
        and elapsed.isdigit() and int(elapsed) > 0
        and output_mode == "RGBA"
        and output_size == expected_size
        and header_values["X-3waAIHub-Width"] == str(expected_size[0])
        and header_values["X-3waAIHub-Height"] == str(expected_size[1])
        and all(truth_levels)
        and bool((alpha_bytes == 0).any())
        and bool((alpha_bytes == 255).any())
        and bool(((alpha_bytes > 0) & (alpha_bytes < 255)).any())
    )
    ok = structural_ok and metrics["f_score"] >= 0.80 and metrics["mae"] <= 0.10
    return {
        "id": name,
        "ok": ok,
        "status": response.status_code,
        "device": header_values["X-3waAIHub-Device"],
        "elapsed_ms": int(elapsed) if elapsed.isdigit() else 0,
        "width": output_size[0],
        "height": output_size[1],
        "mode": output_mode,
        **metrics,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-url", default="http://127.0.0.1:8000")
    parser.add_argument("--fixtures", type=Path, required=True)
    parser.add_argument("--expect-device", choices=["cuda", "cpu"], required=True)
    args = parser.parse_args()

    results = []
    for name in FIXTURES:
        try:
            results.append(run_fixture(args.base_url, args.fixtures, name, args.expect_device))
        except (OSError, ValueError, requests.RequestException) as exc:
            results.append({"id": name, "ok": False, "error": type(exc).__name__})
    ok = all(bool(result.get("ok")) for result in results)
    print(json.dumps({"ok": ok, "f_score_min": 0.80, "mae_max": 0.10, "fixtures": results}, sort_keys=True))
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
