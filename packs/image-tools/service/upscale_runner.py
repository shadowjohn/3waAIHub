from __future__ import annotations

import argparse
import json
import time
from pathlib import Path
from typing import Any

import numpy as np
from PIL import Image

from image_contract import ImageToolsError, decode_image, resolve_backend, select_model, validate_output_pixels
from model_runtime import DEFAULT_MODEL_ROOT, ModelRuntimeError, model_path_for_alias


def _upsampler(alias: str, backend: str, model_path: Path) -> Any:
    from basicsr.archs.rrdbnet_arch import RRDBNet
    from realesrgan import RealESRGANer

    selection = select_model(alias)
    if alias == "realesrgan-x4plus":
        model = RRDBNet(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=23, num_grow_ch=32, scale=4)
    elif alias == "realesrgan-x4plus-anime":
        model = RRDBNet(num_in_ch=3, num_out_ch=3, num_feat=64, num_block=6, num_grow_ch=32, scale=4)
    else:
        from realesrgan.archs.srvgg_arch import SRVGGNetCompact
        model = SRVGGNetCompact(num_in_ch=3, num_out_ch=3, num_feat=64, num_conv=16, upscale=4, act_type="prelu")
    return RealESRGANer(scale=selection.scale, model_path=str(model_path), model=model, tile=0, tile_pad=10, pre_pad=0, half=backend == "cuda", device=backend)


def _cuda_available() -> bool:
    try:
        import torch
        return bool(torch.cuda.is_available())
    except Exception:
        return False


def run_upscale(*, source: Path, output: Path, alias: str, backend: str, model_dir: Path) -> dict[str, object]:
    try:
        workspace = source.parent.resolve(strict=True)
        if output.parent.resolve(strict=True) != workspace:
            raise ValueError
    except (OSError, ValueError) as exc:
        raise ImageToolsError("invalid_request") from exc
    if source.is_symlink() or not source.is_file() or output.name != "output.png" or output.suffix != ".png" or output.is_symlink() or output.parent.is_symlink():
        raise ImageToolsError("invalid_request")
    selection = select_model(alias)
    effective = resolve_backend(backend, cuda_available=_cuda_available())
    source_image = decode_image(source.read_bytes(), operation="upscale")
    validate_output_pixels(source_image.width * source_image.height, selection.scale)
    model_path = model_path_for_alias(alias, model_dir)
    started = time.perf_counter()
    try:
        upsampler = _upsampler(alias, effective, model_path)
    except Exception as exc:
        raise ModelRuntimeError("model_load_failed") from exc
    try:
        output_bgr, _ = upsampler.enhance(np.asarray(source_image)[:, :, ::-1].copy(), outscale=selection.scale)
        rendered = Image.fromarray(np.asarray(output_bgr)[:, :, ::-1]).convert("RGB")
        expected = (source_image.width * selection.scale, source_image.height * selection.scale)
        if rendered.size != expected:
            raise RuntimeError("unexpected output dimensions")
        output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        rendered.save(output, format="PNG")
        output.chmod(0o600)
    except (ImageToolsError, ModelRuntimeError):
        raise
    except Exception as exc:
        raise RuntimeError("inference_failed") from exc
    return {"model": alias, "backend": effective, "width": rendered.width, "height": rendered.height, "elapsed_ms": max(1, int(round((time.perf_counter() - started) * 1000)))}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", required=True)
    parser.add_argument("--backend", choices=["cpu", "cuda"], required=True)
    parser.add_argument("--model-dir", default=str(DEFAULT_MODEL_ROOT))
    parser.add_argument("--fp32", action="store_true")
    args = parser.parse_args(argv)
    try:
        if args.fp32 and args.backend != "cpu":
            raise ImageToolsError("invalid_request")
        report = run_upscale(source=Path(args.input), output=Path(args.output), alias=args.model, backend=args.backend, model_dir=Path(args.model_dir))
        print(json.dumps(report, separators=(",", ":"), sort_keys=True))
        return 0
    except (ImageToolsError, ModelRuntimeError) as exc:
        print(json.dumps({"error": exc.code}, separators=(",", ":")))
    except Exception:
        print(json.dumps({"error": "inference_failed"}, separators=(",", ":")))
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
