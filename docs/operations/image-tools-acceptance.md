# Image Tools Acceptance Record — 2026-08-12

此記錄只保存可稽核的版本與輸出 metadata；不保存來源圖片、Token、task ID、Base64 或主機路徑。

## 版本與離線 snapshot

- Pack version：`0.1.0`
- Service source commit：`b9042a88c546afdd408c3ff001274e21b734334d`
- Container image：`3waaihub-image-tools:test`，`sha256:dcf59b2dff0dd85b9ce6441519f4c60471ff65af157554a427325456cbb61095`
- Real-ESRGAN source commit：`a4abfb2979a7bbff3f69f58f58ae324608821e27`

| Model alias | Staged asset | Size (bytes) | SHA-256 |
| --- | --- | ---: | --- |
| `realesrgan-x4plus` | `RealESRGAN_x4plus.pth` | 67,040,989 | `4fa0d38905f75ac06eb49a7951b426670021be3018265fd191d2125df9d682f1` |
| `realesrgan-x4plus-anime` | `RealESRGAN_x4plus_anime_6B.pth` | 17,938,799 | `f872d837d3c90ed2e05227bed711af5671a6fd1c9f7d7e91c911a61f155e99da` |
| `realesr-animevideov3-x2`, `-x3`, `-x4` | `realesr-animevideov3.pth` | 2,504,012 | `b8a8376811077954d82ca3fcf476f1ac3da3e8a68a4f4d71363008000a18b75d` |

The staged marker was verified as `L3-offline-assets` with `runtime_ready=true` before the checks below.

## CPU/CUDA results

Source dimensions were 2×3; every output was PNG at 8×12 using `realesrgan-x4plus`. Sync values are the response `X-3waAIHub-*` metadata recheck using the recorded image/snapshot; async values are the published `upscale_report.json` metadata from the Gateway task flow.

| Flow | Backend | Elapsed (ms) | Output SHA-256 |
| --- | --- | ---: | --- |
| sync | `cuda` | 8,816 | `a6e3d6e87a8fa8b68a177d85e24f427416b0acb81c9a8469aeea6e4ece38396e` |
| sync | `cpu` | 4,633 | `ebafc1306d63b9bc35ebb7b3f6e337e7919f18791e46d2901fb493eccb8207f7` |
| async | `cuda` | 3,993 | `a6e3d6e87a8fa8b68a177d85e24f427416b0acb81c9a8469aeea6e4ece38396e` |
| async | `cpu` | 2,883 | `ebafc1306d63b9bc35ebb7b3f6e337e7919f18791e46d2901fb493eccb8207f7` |

The Gateway async submit, poll, result, and artifact checks completed for both backends; each report backend, dimensions, and SHA-256 matched its PNG artifact. Temporary acceptance service/container, Gateway and worker processes, DB/queue data, tokens, image tag, and generated outputs were removed after the checks.
