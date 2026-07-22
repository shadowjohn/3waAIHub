# BiRefNet Acceptance Fixtures

These fixed fixtures were created on 2026-07-23 specifically for the 3waAIHub BiRefNet acceptance suite. OpenAI built-in image generation produced each original chroma-key subject. No third-party source image or BiRefNet prediction was used to create the reference masks, and the generated assets may be redistributed with this repository.

Each final source is a 768 x 768 RGB PNG. Its paired reference is a same-size L-mode PNG containing fully transparent, fully opaque, and partial-alpha pixels. The generated subjects were converted with the bundled `remove_chroma_key.py` helper using `--auto-key border --soft-matte --transparent-threshold 12 --opaque-threshold 220 --despill`, then composited over deterministic photographic backgrounds. Background seeds are `1307` for `person_hair`, `2718` for `white_product`, and `4099` for `animal_fur`.

The one-off compositing script was executed from standard input and intentionally not retained. Its SHA-256 was:

```text
64b4c21a09d928ae36cf85c19619be965585fa4c98fc15ae595676e915a2fcfc
```

## Person Hair

```text
Use case: background-extraction
Asset type: segmentation acceptance-test subject
Primary request: Create an original photorealistic shoulder-up portrait of one fictional adult person with long layered hair, combining deep near-black sections and pale silver-blonde sections. Many individual wispy strands and small locks must extend outward across the background on both sides and above the shoulders, creating a challenging but natural hair silhouette.
Scene/backdrop: perfectly flat solid #00ff00 chroma-key background for background removal; one uniform color with no shadows, gradients, texture, reflections, floor plane, or lighting variation
Subject: one fictional adult, head and shoulders fully visible, neutral dark charcoal crew-neck top, no jewelry, no accessories
Style/medium: realistic editorial photography, natural skin and detailed hair texture
Composition/framing: centered shoulder portrait, square frame, generous padding around all flyaway hair, nothing cropped
Lighting/mood: soft neutral frontal studio light contained to the subject
Constraints: subject fully separated from background; crisp detailed edges; do not use #00ff00 anywhere in the subject; no cast shadow, contact shadow, reflection, text, logo, watermark, border, or extra person
Avoid: green clothing, green eyeshadow, green tint, background variation, hair cropped by frame
```

## White Product

```text
Use case: background-extraction
Asset type: segmentation acceptance-test subject
Primary request: Create an original unbranded white consumer product: a compact sculptural desktop air purifier with a rounded rectangular body, one large genuine oval through-hole near the upper center and three smaller genuine circular ventilation through-holes below it. The flat chroma background must be clearly visible through every hole. Give the matte white shell subtle pale silver reflective edge trim and fine realistic material detail.
Scene/backdrop: perfectly flat solid #ff00ff chroma-key background for background removal; one uniform color with no shadows, gradients, texture, reflections, floor plane, or lighting variation
Subject: a single fictional consumer product, no brand and no controls with text
Style/medium: high-end photorealistic catalog product photography
Composition/framing: centered three-quarter view, entire product fully visible, square frame, generous padding, no cropping
Lighting/mood: soft neutral studio illumination contained to the product, delicate highlights along pale reflective outer edges
Materials/textures: matte white polymer body, restrained pale metallic edge trim, dark neutral inner wall thickness around holes; no transparency except open holes
Constraints: background visible cleanly through all open holes; product fully separated from background; do not use #ff00ff anywhere in product; no cast shadow, contact shadow, floor, pedestal, reflection, text, logo, watermark, cable, packaging, border, or extra object
Avoid: magenta tint or spill, sealed or dark-filled holes, glossy mirror body, blown white edges, cropped object
```

## Animal Fur

```text
Use case: background-extraction
Asset type: segmentation acceptance-test subject
Primary request: Create an original photorealistic full-body long-haired animal, a fictional shaggy cream-and-charcoal lurcher dog standing in a natural alert pose. Strong warm rim backlight catches hundreds of fine translucent outer hairs, uneven tufts, whiskers, ear fringes, tail wisps, and leg feathering, producing a highly irregular organic silhouette.
Scene/backdrop: perfectly flat solid #00ff00 chroma-key background for background removal; one uniform color with no shadows, gradients, texture, reflections, floor plane, or lighting variation
Subject: one fictional long-haired dog with cream, charcoal, and muted warm-gray fur; all four paws and the full tail visible
Style/medium: detailed photorealistic animal photography
Composition/framing: three-quarter side view, full animal centered, square frame, generous padding around every fur wisp, ear, paw, and tail; nothing cropped
Lighting/mood: pronounced warm backlight and subtle neutral fill applied only to animal, visible luminous rim through long fur
Constraints: animal fully separated from background; retain fine isolated hairs; do not use #00ff00 anywhere in subject; no collar, harness, cast shadow, contact shadow, floor, reflection, text, logo, watermark, border, or extra animal
Avoid: green eyes or green spill, smooth clipped coat, regular geometric outline, cropped fur, background lighting variation
```
