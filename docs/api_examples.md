# 3waAIHub API Examples

Base URL:

```text
http://localhost/3waAIHub/api.php
```

錯誤回應會包含 `request_id`。外部系統串接失敗時，請提供 `request_id`、`mode`、時間與來源 IP，方便後台 Log Explorer 查詢。

外部 IP 預設需要 Bearer token。先在後台建立 API Member / Token，授權對應 mode，必要時再設定 token IP whitelist。

```bash
curl "http://localhost/3waAIHub/api.php?mode=hello" \
  -H "Authorization: Bearer <TOKEN>"
```

第一次介接流程：

1. 建立 API token。
2. 開啟後台 `API 測試場`。
3. 選 service mode 並執行測試。
4. 用 `request_id` 查 API 記錄。
5. 複製 curl / PHP / JS fetch 範例到外部系統。

可先跑 token API smoke，確認建立 token、授權 OCR、curl 呼叫、Log Explorer 查詢與 usage aggregate 都正常：

```bash
php scripts/token_api_smoke.php
```

## GET hello

Status: Hello L5 Reference Pack. 這是最小 sync API contract 範本。

```bash
curl "http://localhost/3waAIHub/api.php?mode=hello"
```

Response:

```json
{
  "ok": true,
  "service": "hello",
  "message": "3waAIHub service is running"
}
```

Benchmark:

```bash
php scripts/benchmark.php --pack=hello --case=hello_api
```

## POST OCR

Status: L5 benchmark ready. 預設仍回 mock JSON；設定 `OCR_REAL_INFERENCE=1` 或表單加 `real_inference=1` 時執行 PaddleOCR。

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@sample.png"
```

Contract:

- Method: `POST`
- Content-Type: `multipart/form-data`
- Input: `image` file, max `50 MB`
- Required output keys: `ok`, `text`, `blocks`
- Block keys: `text`, `bbox`, `confidence`
- Errors: `bad_request`, `file_too_large`, `runtime_not_ready`, `inference_failed`, `gateway_timeout`

Benchmark:

```bash
php scripts/benchmark.php --pack=ocr-ppocrv5 --case=ocr_mock_image
php scripts/benchmark.php --service=ocr-main --case=ocr_real_image
```

## POST Translate

Status: L5 benchmark ready. The adapter uses an internal Ollama sidecar, returns mock translation by default, and runs real translation when `real_inference=1`.

Mock mode:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time."
  }'
```

Response:

```json
{
  "ok": true,
  "mock": true,
  "runtime_level": "L5-benchmark-ready",
  "text": "mock translation",
  "model": "translategemma:12b-it-q4_K_M",
  "source_lang": "en",
  "target_lang": "zh-TW",
  "elapsed_ms": 0
}
```

Real inference mode:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "source_lang": "en",
    "target_lang": "zh-TW",
    "text": "That was a wonderful time.",
    "real_inference": true
  }'
```

Response:

```json
{
  "ok": true,
  "mock": false,
  "runtime_level": "L5-benchmark-ready",
  "model": "translategemma:12b-it-q4_K_M",
  "source_lang": "en",
  "target_lang": "zh-TW",
  "text": "那真是一個美好的時光。",
  "elapsed_ms": 27000
}
```

Benchmark:

```bash
php scripts/benchmark.php --pack=translate-gemma12b --case=translate_mock_text
php scripts/benchmark.php --service=translate-main --case=translate_real_text
```

## POST YOLO

Status: L5 benchmark ready. 預設仍回 mock JSON；設定 `YOLO_REAL_INFERENCE=1` 或表單加 `real_inference=1` 時執行單張圖片 detection。

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=yolo" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png"
```

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=yolo" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png" \
  -F "real_inference=1"
```

## POST SAM3

Status: L5 benchmark ready. 預設仍回 mock JSON；表單加 `real_inference=1` 時執行單張圖片 real segmentation smoke。`output_format=metadata|polygon|rle|both` 可選 mask geometry；RLE 第一版是 raw uncompressed row-major counts。

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=auto"
```

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=auto" \
  -F "real_inference=1" \
  -F "output_format=polygon"
```

Points prompt:

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=points" \
  -F 'points_json={"points":[[320,240]],"labels":[1]}' \
  -F "real_inference=1" \
  -F "output_format=both"
```

Benchmark:

```bash
php scripts/benchmark.php --pack=sam3 --case=sam3_mock_image
php scripts/benchmark.php --service=sam3-main --case=sam3_real_image
php scripts/benchmark.php --service=sam3-main --case=sam3_real_polygon_image
```

## Unknown Mode

unknown mode 代表 `mode` 尚未註冊到任何 service instance。

```bash
curl "http://localhost/3waAIHub/api.php?mode=unknown"
```

Response:

```json
{
  "ok": false,
  "error": "unknown_mode",
  "message": "mode is not registered",
  "request_id": "req_20260706171853_abc123"
}
```
