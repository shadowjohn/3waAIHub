"""Regenerate the compact, non-sensitive fixed acceptance PNGs."""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).parent
FONT = ImageFont.load_default()


def page(lines: list[str], name: str) -> None:
    image = Image.new("RGB", (448, 320), "white")
    draw = ImageDraw.Draw(image)
    draw.rectangle((12, 12, 436, 308), outline="black", width=2)
    for index, line in enumerate(lines):
        draw.text((32, 44 + index * 42), line, fill="black", font=FONT)
    image.save(ROOT / name, format="PNG", optimize=True)


def diagram() -> None:
    image = Image.new("RGB", (448, 320), "white")
    draw = ImageDraw.Draw(image)
    draw.rectangle((72, 92, 376, 228), outline="black", width=3)
    draw.line((96, 160, 352, 160), fill="black", width=3)
    draw.rectangle((186, 140, 258, 180), outline="black", width=2)
    draw.text((198, 151), "Fuse", fill="black", font=FONT)
    draw.line((222, 112, 222, 140), fill="black", width=2)
    draw.text((216, 76), "A", fill="black", font=FONT)
    draw.text((112, 256), "Power protection diagram", fill="black", font=FONT)
    image.save(ROOT / "manual_labelled_diagram.png", format="PNG", optimize=True)


if __name__ == "__main__":
    page(["SERVICE MANUAL", "Safety shutdown", "Shutdown temperature: 85 °C"], "manual_text_page.png")
    page(["SPECIFICATIONS", "Parameter              Value", "Rated capacity         1.2 L"], "manual_specs_table.png")
    diagram()
