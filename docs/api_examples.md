# 3waAIHub API Examples

Base URL:

```text
http://localhost/3waAIHub/api.php
```

錯誤回應會包含 `request_id`。外部系統串接失敗時，請提供 `request_id`、`mode`、時間與來源 IP，方便後台 Log Explorer 查詢。

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

Status: Runtime adapter pending / L1 mock only.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=ocr" \
  -F "image=@sample.png"
```

## POST Translate

Status: L1 Ollama adapter. First run pulls `translategemma:12b-it-q4_K_M` into `AIHUB_MODELS_DIR/ollama`.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=translate" \
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

Status: L1 Ultralytics YOLO adapter.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=yolo" \
  -F "image=@sample.jpg"
```

## POST SAM3

Status: L1 Ultralytics SAM3 adapter.

```bash
curl -X POST "http://localhost/3waAIHub/api.php?mode=sam3" \
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
