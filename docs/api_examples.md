# 3waAIHub API Examples

Base URL:

```text
http://localhost/3waAIHub/api.php
```

錯誤回應會包含 `request_id`。外部系統串接失敗時，請提供 `request_id`、`mode`、時間與來源 IP，方便後台 Log Explorer 查詢。

外部 IP 預設需要 Bearer token。先在後台建立 API Member / Token，授權對應 mode，必要時再設定 token IP whitelist。

```bash
curl "http://localhost/3waAIHub/api.php?mode=hello" \
  -H "Authorization: Bearer 3wa_live_xxx"
```

可先跑 token API smoke，確認建立 token、授權 OCR、curl 呼叫、Log Explorer 查詢與 usage aggregate 都正常：

```bash
php scripts/token_api_smoke.php
```

## GET hello

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

## POST OCR

Status: L5 benchmark ready. 預設仍回 mock JSON；設定 `OCR_REAL_INFERENCE=1` 或表單加 `real_inference=1` 時執行 PaddleOCR。

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -H "Authorization: Bearer 3wa_live_xxx" \
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

Status: L1 Ollama adapter. First run pulls `translategemma:12b-it-q4_K_M` into `AIHUB_MODELS_DIR/ollama`.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
  -H "Authorization: Bearer 3wa_live_xxx" \
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
  "text": "那是一段美好的時光。",
  "model": "translategemma:12b-it-q4_K_M",
  "source_lang": "en",
  "target_lang": "zh-TW"
}
```

## POST YOLO

Status: L2 dependency/import smoke. The endpoint still returns mock JSON; real detection is not enabled yet.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=yolo" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -F "image=@sample.jpg"
```

## POST SAM3

Status: L1 Ultralytics SAM3 adapter.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=sam3" \
  -H "Authorization: Bearer 3wa_live_xxx" \
  -F "image=@sample.jpg" \
  -F 'points=[[150,120]]' \
  -F 'labels=[1]'
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
