from __future__ import annotations

import io
import math
import re
import warnings
from dataclasses import dataclass
from typing import Mapping

import numpy as np
from PIL import Image, ImageFilter, ImageOps, UnidentifiedImageError


@dataclass(frozen=True)
class RequestOptions:
    output: str = "cutout"
    feather_px: float = 0.0
    edge_offset_px: int = 0
    defringe: bool = True
    background: str = "transparent"
    background_color: str = ""


class PipelineError(ValueError):
    def __init__(self, code: str, message: str) -> None:
        super().__init__(f"{code}: {message}")
        self.code = code
        self.message = message


def _invalid(message: str) -> PipelineError:
    return PipelineError("invalid_parameter", message)


def _number(value: object, name: str) -> float:
    try:
        parsed = float(str(value).strip())
    except (TypeError, ValueError) as exc:
        raise _invalid(f"{name} is invalid") from exc
    if not math.isfinite(parsed):
        raise _invalid(f"{name} is invalid")
    return parsed


def _boolean(value: object, name: str) -> bool:
    if isinstance(value, bool):
        return value
    text = str(value).strip().lower()
    if text in {"1", "true", "yes", "on"}:
        return True
    if text in {"0", "false", "no", "off"}:
        return False
    raise _invalid(f"{name} is invalid")


def parse_options(form: Mapping[str, object], has_background_image: bool = False) -> RequestOptions:
    output = str(form.get("output", "cutout")).strip().lower()
    background = str(form.get("background", "transparent")).strip().lower()
    if output not in {"cutout", "mask", "composite"}:
        raise _invalid("output is invalid")
    if background not in {"transparent", "white", "color", "image"}:
        raise _invalid("background is invalid")

    feather = _number(form.get("feather_px", 0), "feather_px")
    if feather < 0 or feather > 20:
        raise _invalid("feather_px is out of range")
    edge_text = str(form.get("edge_offset_px", 0)).strip()
    if re.fullmatch(r"-?\d+", edge_text) is None:
        raise _invalid("edge_offset_px is invalid")
    edge = int(edge_text)
    if edge < -20 or edge > 20:
        raise _invalid("edge_offset_px is out of range")
    defringe = _boolean(form.get("defringe", True), "defringe")
    color = str(form.get("background_color", "")).strip()
    has_color = "background_color" in form and color != ""
    has_background_field = "background" in form

    if output == "mask" and (has_background_field or has_color or has_background_image):
        raise _invalid("mask output does not accept background fields")
    if output == "cutout" and (background != "transparent" or has_color or has_background_image):
        raise _invalid("cutout output requires a transparent background")
    if output == "composite" and background == "transparent":
        raise _invalid("composite output requires a background")
    if background == "color":
        if re.fullmatch(r"#[0-9a-fA-F]{6}", color) is None or has_background_image:
            raise _invalid("background_color is invalid")
    elif has_color:
        raise _invalid("background_color requires background=color")
    if background == "image":
        if not has_background_image:
            raise _invalid("background_image is required")
    elif has_background_image:
        raise _invalid("background_image requires background=image")

    return RequestOptions(output, feather, edge, defringe, background, color)


def decode_image(
    data: bytes,
    *,
    max_bytes: int,
    max_axis: int,
    max_pixels: int,
    allowed_formats: set[str] | None = None,
) -> Image.Image:
    if not data:
        raise PipelineError("invalid_image", "image is empty")
    if len(data) > max_bytes:
        raise PipelineError("payload_too_large", "image exceeds the byte limit")
    formats = allowed_formats or {"JPEG", "PNG", "WEBP"}
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(data)) as probe:
                image_format = (probe.format or "").upper()
                if image_format not in formats:
                    raise PipelineError("unsupported_media_type", "image format is not supported")
                width, height = probe.size
                if width < 1 or height < 1 or width > max_axis or height > max_axis or width * height > max_pixels:
                    raise PipelineError("invalid_image", "decoded image dimensions exceed the limit")
                probe.verify()
            with Image.open(io.BytesIO(data)) as opened:
                opened.load()
                image = ImageOps.exif_transpose(opened).copy()
    except PipelineError:
        raise
    except (Image.DecompressionBombError, Image.DecompressionBombWarning, UnidentifiedImageError, OSError, ValueError) as exc:
        raise PipelineError("invalid_image", "image cannot be decoded") from exc

    width, height = image.size
    if width < 1 or height < 1 or width > max_axis or height > max_axis or width * height > max_pixels:
        raise PipelineError("invalid_image", "decoded image dimensions exceed the limit")
    return image


def postprocess_mask(
    mask: Image.Image,
    *,
    size: tuple[int, int],
    feather_px: float,
    edge_offset_px: int,
) -> Image.Image:
    result = mask.convert("L")
    if result.size != size:
        result = result.resize(size, Image.Resampling.BICUBIC)
    if edge_offset_px:
        radius = abs(edge_offset_px)
        filter_type = ImageFilter.MaxFilter if edge_offset_px > 0 else ImageFilter.MinFilter
        result = result.filter(filter_type(radius * 2 + 1))
    if feather_px:
        result = result.filter(ImageFilter.GaussianBlur(radius=feather_px))
    return result


def prediction_to_mask(prediction: object, size: tuple[int, int]) -> Image.Image:
    values = np.asarray(prediction, dtype=np.float32)
    if values.ndim != 2 or values.size == 0 or not np.isfinite(values).all():
        raise PipelineError("inference_failed", "model prediction is invalid")
    pixels = np.rint(np.clip(values, 0.0, 1.0) * 255.0).astype(np.uint8)
    mask = Image.fromarray(pixels, mode="L")
    if mask.size != size:
        mask = mask.resize(size, Image.Resampling.BICUBIC)
    return mask


def defringe_rgb(rgb: Image.Image, alpha: Image.Image) -> Image.Image:
    colors = np.asarray(rgb.convert("RGB"), dtype=np.uint8).copy()
    opacity = np.asarray(alpha.convert("L"), dtype=np.uint8)
    near_black = colors.max(axis=2) <= 16
    near_white = colors.min(axis=2) >= 239
    edge = (opacity > 0) & (opacity < 255) & (near_black | near_white)
    ys, xs = np.nonzero(edge)
    if ys.size == 0:
        return Image.fromarray(colors)

    unresolved = np.ones(ys.size, dtype=bool)
    height, width = opacity.shape
    # ponytail: bounded vectorized probes; use a distance transform only if dense 8K edges profile poorly.
    offsets = sorted(
        (
            (dy * dy + dx * dx, dy, dx)
            for dy in range(-3, 4)
            for dx in range(-3, 4)
            if dy * dy + dx * dx <= 9
        ),
        key=lambda item: item,
    )
    for _distance, dy, dx in offsets:
        if not unresolved.any():
            break
        cy = ys + dy
        cx = xs + dx
        valid = unresolved & (cy >= 0) & (cy < height) & (cx >= 0) & (cx < width)
        indexes = np.flatnonzero(valid)
        if indexes.size == 0:
            continue
        foreground = opacity[cy[indexes], cx[indexes]] >= 250
        indexes = indexes[foreground]
        if indexes.size:
            colors[ys[indexes], xs[indexes]] = colors[cy[indexes], cx[indexes]]
            unresolved[indexes] = False
    return Image.fromarray(colors)


def cover_background(background: Image.Image, size: tuple[int, int]) -> Image.Image:
    oriented = ImageOps.exif_transpose(background)
    rgba = oriented.convert("RGBA")
    white = Image.new("RGBA", rgba.size, "white")
    white.alpha_composite(rgba)
    source = white.convert("RGB")
    return ImageOps.fit(source, size, method=Image.Resampling.LANCZOS, centering=(0.5, 0.5))


def render_png(
    source: Image.Image,
    alpha: Image.Image,
    options: RequestOptions,
    background: Image.Image | None = None,
) -> bytes:
    rgb = source.convert("RGB")
    mask = alpha.convert("L")
    if options.output == "mask":
        result = mask
    else:
        foreground = defringe_rgb(rgb, mask) if options.defringe else rgb
        cutout = foreground.convert("RGBA")
        cutout.putalpha(mask)
        if options.output == "cutout":
            result = cutout
        else:
            if options.background == "white":
                backdrop = Image.new("RGB", rgb.size, "white")
            elif options.background == "color":
                backdrop = Image.new("RGB", rgb.size, options.background_color)
            elif background is not None:
                backdrop = cover_background(background, rgb.size)
            else:
                raise _invalid("background_image is required")
            opaque = backdrop.convert("RGBA")
            opaque.alpha_composite(cutout)
            result = opaque.convert("RGB")

    output = io.BytesIO()
    result.save(output, format="PNG", optimize=False)
    return output.getvalue()
