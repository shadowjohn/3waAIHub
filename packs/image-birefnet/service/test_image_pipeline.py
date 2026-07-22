from __future__ import annotations

import io
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image

from image_pipeline import (
    PipelineError,
    cover_background,
    decode_image,
    defringe_rgb,
    parse_options,
    postprocess_mask,
    render_png,
)


def png_bytes(image: Image.Image) -> bytes:
    output = io.BytesIO()
    image.save(output, format="PNG")
    return output.getvalue()


class ImagePipelineTest(unittest.TestCase):
    def test_parse_options_enforces_ranges_and_output_background_matrix(self) -> None:
        valid = [
            ({}, False, ("cutout", "transparent")),
            ({"output": "mask"}, False, ("mask", "transparent")),
            ({"output": "composite", "background": "white"}, False, ("composite", "white")),
            ({"output": "composite", "background": "color", "background_color": "#10aBcF"}, False, ("composite", "color")),
            ({"output": "composite", "background": "image"}, True, ("composite", "image")),
        ]
        for form, has_background_image, expected in valid:
            with self.subTest(form=form):
                options = parse_options(form, has_background_image)
                self.assertEqual((options.output, options.background), expected)

        invalid = [
            ({"output": "mask", "background": "white"}, False),
            ({"output": "cutout", "background": "color", "background_color": "#ffffff"}, False),
            ({"output": "composite", "background": "transparent"}, False),
            ({"output": "composite", "background": "color", "background_color": "white"}, False),
            ({"output": "composite", "background": "image"}, False),
            ({"output": "mask", "background_color": "#ffffff"}, False),
            ({"feather_px": "20.1"}, False),
            ({"edge_offset_px": "1.5"}, False),
            ({"defringe": "maybe"}, False),
        ]
        for form, has_background_image in invalid:
            with self.subTest(form=form), self.assertRaisesRegex(PipelineError, "invalid_parameter"):
                parse_options(form, has_background_image)

    def test_decode_image_checks_bytes_type_orientation_and_dimensions(self) -> None:
        source = Image.new("RGB", (2, 3), "red")
        jpeg = io.BytesIO()
        exif = Image.Exif()
        exif[274] = 6
        source.save(jpeg, format="JPEG", exif=exif)
        decoded = decode_image(jpeg.getvalue(), max_bytes=1024 * 1024, max_axis=3, max_pixels=6)
        self.assertEqual(decoded.size, (3, 2))
        self.assertEqual(decoded.mode, "RGB")

        with self.assertRaisesRegex(PipelineError, "payload_too_large"):
            decode_image(jpeg.getvalue(), max_bytes=4, max_axis=3, max_pixels=6)
        with self.assertRaisesRegex(PipelineError, "invalid_image"):
            decode_image(b"not an image", max_bytes=100, max_axis=3, max_pixels=6)
        with self.assertRaisesRegex(PipelineError, "unsupported_media_type"):
            decode_image(png_bytes(Image.new("P", (2, 2))), max_bytes=1000, max_axis=3, max_pixels=6, allowed_formats={"JPEG"})
        oversized = png_bytes(Image.new("RGB", (4, 2)))
        with patch("PIL.PngImagePlugin.PngImageFile.load", side_effect=AssertionError("must reject before decode")):
            with self.assertRaisesRegex(PipelineError, "invalid_image"):
                decode_image(oversized, max_bytes=1000, max_axis=3, max_pixels=8)
        with self.assertRaisesRegex(PipelineError, "invalid_image"):
            decode_image(png_bytes(Image.new("RGB", (3, 3))), max_bytes=1000, max_axis=3, max_pixels=8)

    def test_postprocess_mask_dilates_erodes_and_feathers(self) -> None:
        mask = Image.new("L", (5, 5), 0)
        mask.putpixel((2, 2), 255)
        dilated = postprocess_mask(mask, size=(5, 5), feather_px=0, edge_offset_px=1)
        self.assertEqual(int((np.asarray(dilated) == 255).sum()), 9)
        eroded = postprocess_mask(dilated, size=(5, 5), feather_px=0, edge_offset_px=-1)
        self.assertEqual(int((np.asarray(eroded) == 255).sum()), 1)
        feathered = postprocess_mask(mask, size=(5, 5), feather_px=1, edge_offset_px=0)
        values = np.asarray(feathered)
        self.assertTrue(bool(((values > 0) & (values < 255)).any()))

    def test_defringe_repairs_only_qualified_partial_edges(self) -> None:
        rgb = Image.new("RGB", (6, 1), (100, 50, 25))
        rgb.putpixel((0, 0), (0, 0, 0))
        rgb.putpixel((2, 0), (255, 255, 255))
        rgb.putpixel((3, 0), (17, 17, 17))
        rgb.putpixel((5, 0), (12, 12, 12))
        alpha = Image.new("L", (6, 1), 128)
        alpha.putpixel((1, 0), 250)

        repaired = defringe_rgb(rgb, alpha)
        self.assertEqual(repaired.getpixel((0, 0)), (100, 50, 25))
        self.assertEqual(repaired.getpixel((2, 0)), (100, 50, 25))
        self.assertEqual(repaired.getpixel((3, 0)), (17, 17, 17))
        self.assertEqual(repaired.getpixel((5, 0)), (12, 12, 12))

        diagonal = Image.new("RGB", (4, 4), (100, 50, 25))
        diagonal.putpixel((0, 0), (0, 0, 0))
        diagonal_alpha = Image.new("L", (4, 4), 128)
        diagonal_alpha.putpixel((3, 3), 250)
        repaired_diagonal = defringe_rgb(diagonal, diagonal_alpha)
        self.assertEqual(repaired_diagonal.getpixel((0, 0)), (0, 0, 0))

    def test_render_png_has_exact_modes_and_centered_cover_crop(self) -> None:
        source = Image.new("RGB", (2, 2), "red")
        alpha = Image.new("L", (2, 2), 128)

        cutout = Image.open(io.BytesIO(render_png(source, alpha, parse_options({}, False))))
        self.assertEqual((cutout.mode, cutout.size), ("RGBA", (2, 2)))
        mask = Image.open(io.BytesIO(render_png(source, alpha, parse_options({"output": "mask"}, False))))
        self.assertEqual(mask.mode, "L")
        composite = Image.open(io.BytesIO(render_png(
            source,
            alpha,
            parse_options({"output": "composite", "background": "white"}, False),
        )))
        self.assertEqual(composite.mode, "RGB")

        background = Image.new("RGBA", (4, 2), (0, 0, 0, 0))
        background.paste((0, 255, 0, 255), (1, 0, 3, 2))
        covered = cover_background(background, (2, 2))
        self.assertEqual(covered.mode, "RGB")
        self.assertEqual(covered.getpixel((0, 0)), (0, 255, 0))
        self.assertEqual(covered.getpixel((1, 0)), (0, 255, 0))


if __name__ == "__main__":
    unittest.main()
