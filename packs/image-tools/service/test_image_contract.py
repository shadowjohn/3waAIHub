from __future__ import annotations

import base64
import io
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from PIL import Image

sys.path.insert(0, str(Path(__file__).parent))

from image_contract import (
    ImageToolsError,
    MAX_ASYNC_PIXELS,
    MAX_SOURCE_BYTES,
    MAX_OUTPUT_PIXELS,
    MAX_SYNC_PIXELS,
    decode_base64,
    decode_image,
    resolve_backend,
    resolve_outscale,
    select_model,
    validate_output_pixels,
)


def image_bytes(image: Image.Image, image_format: str) -> bytes:
    output = io.BytesIO()
    image.save(output, format=image_format)
    return output.getvalue()


class ImageContractTest(unittest.TestCase):
    def assert_code(self, code: str, callback) -> None:
        with self.assertRaisesRegex(ImageToolsError, f"^{code}$"):
            callback()

    def test_base64_matches_gateway_contract(self) -> None:
        payload = image_bytes(Image.new("RGB", (2, 2), "red"), "PNG")
        encoded = base64.b64encode(payload).decode("ascii")
        self.assertEqual(decode_base64(encoded), payload)
        self.assertEqual(decode_base64("data:image/png;base64," + encoded), payload)
        self.assertEqual(decode_base64("\n" + encoded[:8] + " \t" + encoded[8:]), payload)
        for invalid in ("", "data:image/gif;base64," + encoded, "data:image/png;base64,abc!", "abc", "===="):
            with self.subTest(invalid=invalid):
                self.assert_code("invalid_base64", lambda: decode_base64(invalid))
        with patch("image_contract.MAX_SOURCE_BYTES", 4):
            self.assert_code("invalid_base64", lambda: decode_base64(base64.b64encode(b"12345").decode("ascii")))

    def test_decode_image_accepts_only_verified_raster_formats_and_transposes(self) -> None:
        for image_format in ("JPEG", "PNG", "WEBP", "BMP"):
            with self.subTest(image_format=image_format):
                decoded = decode_image(image_bytes(Image.new("RGB", (3, 2), "red"), image_format), operation="upscale")
                self.assertEqual((decoded.size, decoded.mode), ((3, 2), "RGB"))

        source = Image.new("RGB", (2, 3), "red")
        output = io.BytesIO()
        exif = Image.Exif()
        exif[274] = 6
        source.save(output, format="JPEG", exif=exif)
        self.assertEqual(decode_image(output.getvalue(), operation="upscale").size, (3, 2))

    def test_decode_image_rejects_bad_or_non_image_bytes(self) -> None:
        unsupported = [
            image_bytes(Image.new("P", (2, 2)), "GIF"),
            image_bytes(Image.new("RGB", (2, 2)), "TIFF"),
        ]
        for payload in unsupported:
            with self.subTest(payload=payload[:10]):
                self.assert_code("unsupported_media_type", lambda: decode_image(payload, operation="upscale"))
        rejected = [
            b"<svg xmlns='http://www.w3.org/2000/svg'/>",
            b"%PDF-1.7\n",
            b"ftypheic",
            b"plain text",
            image_bytes(Image.new("RGB", (2, 2)), "PNG")[:-8],
        ]
        for payload in rejected:
            with self.subTest(payload=payload[:10]):
                self.assert_code("invalid_image", lambda: decode_image(payload, operation="upscale"))

    def test_decode_image_enforces_source_limits_and_decompression_bombs(self) -> None:
        sync = image_bytes(Image.new("RGB", (2_000, 2_000)), "PNG")
        self.assertEqual(decode_image(sync, operation="upscale").size, (2_000, 2_000))
        self.assertEqual(MAX_SOURCE_BYTES, 50 * 1024 * 1024)
        with patch("image_contract.MAX_SOURCE_BYTES", 4):
            self.assert_code("payload_too_large", lambda: decode_image(sync, operation="upscale"))
        self.assert_code("invalid_image", lambda: decode_image(image_bytes(Image.new("RGB", (2_001, 2_000)), "PNG"), operation="upscale"))
        self.assertEqual(decode_image(image_bytes(Image.new("RGB", (2_500, 2_000)), "PNG"), operation="upscale_task").size, (2_500, 2_000))
        self.assert_code("invalid_image", lambda: decode_image(image_bytes(Image.new("RGB", (2_501, 4_000)), "PNG"), operation="upscale_task"))
        self.assert_code("invalid_image", lambda: decode_image(image_bytes(Image.new("RGB", (8_193, 1)), "PNG"), operation="upscale_task"))
        with patch("image_contract.Image.MAX_IMAGE_PIXELS", 1):
            self.assert_code("invalid_image", lambda: decode_image(image_bytes(Image.new("RGB", (2, 2)), "PNG"), operation="upscale"))

    def test_model_backend_and_output_limits_are_exact(self) -> None:
        self.assertEqual(MAX_SYNC_PIXELS, 4_000_000)
        self.assertEqual(MAX_ASYNC_PIXELS, 10_000_000)
        self.assertEqual(MAX_OUTPUT_PIXELS, 64_000_000)
        self.assertEqual(select_model("realesrgan-x4plus").scale, 4)
        self.assertEqual(select_model("realesrgan-x4plus-anime").scale, 4)
        self.assertEqual(select_model("realesr-animevideov3-x2").scale, 2)
        self.assertEqual(select_model("realesr-animevideov3-x3").scale, 3)
        self.assertEqual(select_model("realesr-animevideov3-x4").scale, 4)
        self.assert_code("invalid_model", lambda: select_model("other"))
        self.assertEqual(resolve_backend("auto", cuda_available=True), "cuda")
        self.assertEqual(resolve_backend("auto", cuda_available=False), "cpu")
        self.assertEqual(resolve_backend("cpu", cuda_available=True), "cpu")
        self.assert_code("backend_unavailable", lambda: resolve_backend("cuda", cuda_available=False))
        self.assert_code("invalid_backend", lambda: resolve_backend("vulkan", cuda_available=True))
        validate_output_pixels(4_000_000, 4)
        self.assert_code("invalid_image", lambda: validate_output_pixels(4_000_001, 4))
        validate_output_pixels(16_000_000, 2)
        self.assert_code("invalid_image", lambda: validate_output_pixels(16_000_001, 2))
        validate_output_pixels(64_000_000 // 9, 3)
        self.assert_code("invalid_image", lambda: validate_output_pixels(64_000_000 // 9 + 1, 3))

    def test_outscale_uses_model_native_defaults_and_accepts_only_exact_values(self) -> None:
        self.assertEqual(resolve_outscale(None, model="realesrgan-x4plus"), 4)
        self.assertEqual(resolve_outscale("2", model="realesrgan-x4plus"), 2)
        self.assertEqual(resolve_outscale(3, model="realesrgan-x4plus"), 3)
        self.assertEqual(resolve_outscale("4", model="realesrgan-x4plus"), 4)
        self.assertEqual(resolve_outscale(None, model="realesr-animevideov3-x2"), 2)
        for invalid in (True, 1, 5, "02", "2.0", "auto"):
            with self.subTest(invalid=invalid):
                self.assert_code("invalid_request", lambda invalid=invalid: resolve_outscale(invalid, model="realesrgan-x4plus"))


if __name__ == "__main__":
    unittest.main()
