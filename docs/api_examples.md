# 3waAIHub API Examples

Base URL:

```text
<BASE_URL>
```

錯誤回應會包含 `request_id`。外部系統串接失敗時，請提供 `request_id`、`mode`、時間與來源 IP，方便後台 Log Explorer 查詢。

外部 IP 預設需要 Bearer token。先在後台建立 API Member / Token，授權對應 mode，必要時再設定 token IP whitelist。

```bash
curl "<BASE_URL>?mode=hello" \
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
curl "<BASE_URL>?mode=hello"
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
curl -X POST "<BASE_URL>?mode=ocr" \
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
curl -X POST "<BASE_URL>?mode=translate" \
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
curl -X POST "<BASE_URL>?mode=translate" \
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
curl -X POST "<BASE_URL>?mode=yolo" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png"
```

```bash
curl -X POST "<BASE_URL>?mode=yolo" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png" \
  -F "real_inference=1"
```

## POST BioCLIP

Status: L5 benchmark ready. `bioclip` 用 OpenCLIP / BioCLIP 做圖片候選標籤分類；預設可先跑 mock contract，表單加 `real_inference=1` 時執行真實推論。

Mock / contract smoke:

```bash
curl -X POST "<BASE_URL>?mode=bioclip" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png" \
  -F "candidate_labels=plant,insect,bird,mammal"
```

Real inference:

```bash
curl -X POST "<BASE_URL>?mode=bioclip" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/yolo/demo/camera_cat.png" \
  -F "candidate_labels=plant,insect,bird,mammal" \
  -F "real_inference=1"
```

Contract:

- Method: `POST`
- Content-Type: `multipart/form-data`
- Input: `image` file, `candidate_labels` comma-separated labels, optional `real_inference`
- Required output keys: `ok`, `labels`
- Label keys: `label`, `score`
- Errors: `bad_request`, `file_too_large`, `bad_image`, `gpu_unavailable`, `runtime_dependency_missing`, `model_load_failed`, `inference_failed`, `gateway_timeout`

Benchmark:

```bash
php scripts/benchmark.php --pack=bioclip --case=bioclip_mock_image
php scripts/benchmark.php --service=bioclip-main --case=bioclip_real_image
```

## POST SAM3

Status: L5 benchmark ready. 預設仍回 mock JSON；表單加 `real_inference=1` 時執行單張圖片 real segmentation smoke。`output_format=metadata|polygon|rle|both` 可選 mask geometry；RLE 第一版是 raw uncompressed row-major counts。

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=auto"
```

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=auto" \
  -F "real_inference=1" \
  -F "output_format=polygon"
```

Points prompt:

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=points" \
  -F 'points_json={"points":[[320,240]],"labels":[1]}' \
  -F "real_inference=1" \
  -F "output_format=both"
```

Semantic text prompt:

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=text" \
  -F "text=mammal/insect/plant" \
  -F "real_inference=1" \
  -F "output_format=polygon"
```

Benchmark:

```bash
php scripts/benchmark.php --pack=sam3 --case=sam3_mock_image
php scripts/benchmark.php --service=sam3-main --case=sam3_real_image
php scripts/benchmark.php --service=sam3-main --case=sam3_real_polygon_image
```

## POST Structure

Status: L5 benchmark ready. `structure` 直接呼叫 PP-StructureV3 解析 PDF / 文件圖片；大型文件建議改走 `task_submit` 的 `structure_parse` 佇列。

```bash
curl -X POST "<BASE_URL>?mode=structure" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@sample.pdf" \
  -F "output_format=both" \
  -F "real_inference=1"
```

Contract:

- Method: `POST`
- Content-Type: `multipart/form-data`
- Input: `file` PDF / image, max `100 MB`
- Required output keys: `ok`, `mock`, `runtime_level`, `output_format`, `result_count`, `model`, `engine`, `device`, `elapsed_ms`
- Optional output keys: `markdown`, `document_json`
- Errors: `bad_request`, `file_too_large`, `invalid_output_format`, `runtime_dependency_missing`, `model_load_failed`, `parse_failed`, `gateway_timeout`

Benchmark:

```bash
php scripts/benchmark.php --service=structure-main --case=structure_page_pdf
php scripts/benchmark.php --service=structure-main --case=structure_10page_pdf
```

## POST DocParser Async

Status: L5 benchmark ready. `docparser` 是非同步文件交付流程，會產出 reader HTML、雙語 HTML、Markdown、DocIR、TOC、RAG chunks、quality report 與 manifest artifacts。

Submit:

```bash
curl -X POST "<BASE_URL>?mode=docparser" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "file=@manual.pdf" \
  -F "target_language=zh-TW" \
  -F "translation_required=1"
```

Submit response:

```json
{
  "ok": true,
  "task_id": 11,
  "status": "queued",
  "status_url": "<BASE_URL>?mode=task_status&task_id=11",
  "result_url": "<BASE_URL>?mode=task_result&task_id=11",
  "log_url": "<BASE_URL>?mode=task_log&task_id=11",
  "cancel_url": "<BASE_URL>?mode=task_cancel&task_id=11",
  "artifact_url_template": "<BASE_URL>?mode=artifact&artifact_id={artifact_id}"
}
```

Poll / result:

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=task_status&task_id=11"

curl -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=task_result&task_id=11"
```

Cancel:

```bash
curl -X POST -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=task_cancel&task_id=11"
```

Queued tasks become `cancelled` immediately. Running `docparser_parse` tasks use cooperative cancel: the worker records `cancel_requested` and stops at the next DocParser checkpoint. Other running task types are not hard-killed.

Figure crop download:

```text
artifact_summary.figure_assets.items[].artifact_id
<BASE_URL>?mode=artifact&artifact_id=<FIGURE_ARTIFACT_ID>
```

Benchmark:

```bash
php scripts/benchmark.php --pack=docparser --case=docparser_submit_pdf
php scripts/benchmark.php --pack=docparser --case=docparser_submit_10page_pdf
php scripts/docparser_acceptance.php --task-id=<SUCCESS_TASK_ID>
```

## Unknown Mode

unknown mode 代表 `mode` 尚未註冊到任何 service instance。

```bash
curl "<BASE_URL>?mode=unknown"
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
