# 3waAIHub API Examples

Base URL:

```text
<BASE_URL>
```

錯誤回應會包含 `request_id`。外部系統串接失敗時，請提供 `request_id`、`mode`、時間與來源 IP，方便後台 Log Explorer 查詢。

外部 IP 預設需要 Bearer token。先在後台建立 API Member / Token，授權對應 mode，必要時再設定 token IP whitelist。

PowerShell：

```powershell
curl.exe "<BASE_URL>?mode=hello" `
  -H "Authorization: Bearer <TOKEN>"
```

Bash：

```bash
curl "<BASE_URL>?mode=hello" \
  -H "Authorization: Bearer <TOKEN>"
```

以下詳細範例以 Bash 顯示；PowerShell 請使用 `curl.exe`，並將行尾 `\` 改為反引號 `` ` ``。JSON body 內容不需更動。

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
- Input: `image` file, max `50 MB`; `file` is accepted as a legacy upload alias
- Real inference: set `OCR_REAL_INFERENCE=1` on the service or submit `real_inference=1`
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

## POST Chat

Status: PhaseL-1A L5 benchmark ready. `llm-gemma4-12b` 以 Hub `/chat` adapter 包住內部 vLLM sidecar。第一版只支援文字、非串流 JSON；不要直接送 OpenAI-compatible `messages` / `stream` payload 給 Gateway。

Mock / contract smoke:

```bash
curl -X POST "<BASE_URL>?mode=chat" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "請用一句正體中文介紹 3waAIHub。",
    "real_inference": false
  }'
```

Real Q4 inference:

```bash
curl -X POST "<BASE_URL>?mode=chat" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "請用正體中文解釋 RAG 中 embedding 與 reranking 的差異。",
    "system_prompt": "你是 3waAIHub 本地 AI 助手，請簡潔回答。",
    "real_inference": true,
    "enable_thinking": false,
    "max_tokens": 512
  }'
```

Contract:

- Method: `POST`
- Content-Type: `application/json`
- Input: `text`, optional `system_prompt`, `temperature`, `max_tokens`, `enable_thinking`, `real_inference`
- Required output keys: `ok`, `mock`, `runtime_level`, `model`, `text`, `usage`, `elapsed_ms`
- Errors: `bad_request`, `input_too_long`, `vllm_unavailable`, `model_not_present`, `vllm_timeout`, `vllm_bad_response`, `chat_failed`

Benchmark:

```bash
php scripts/benchmark.php --pack=llm-gemma4-12b --case=gemma4_mock_chat
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_chat
```

## Photo Vision

Upload once:

```bash
curl -X POST "<BASE_URL>?mode=photo_upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@example.jpg"
```

Ask many times:

```bash
curl -X POST "<BASE_URL>?mode=photo" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"image_id":"img_...","text":"這張圖裡有什麼？","max_tokens":256,"real_inference":true}'
```

No session is stored. Send prior context in `text` when needed.

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

## YOLO Model Registry / GPU Warm Pool

Status: Phase 1B. 只支援 YOLO detect `.pt` 匯入、CPU serving，以及固定 `yolo-gpu0` slot 1 / 2 warm pool。先不要把 segment / pose / ONNX serving、TensorRT、多 GPU、production alias 或自動換槽視為已支援能力。

Register allowlisted host model:

```bash
curl -X POST "<BASE_URL>?mode=yolo_model_register" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "source_system=natureweb" \
  -F "external_model_key=training_result_47" \
  -F "display_name=NatureWeb training result 47" \
  -F "artifact_path=<ALLOWLISTED_HOST_PATH>/best.pt" \
  -F "artifact_sha256=<SHA256>" \
  -F "task_type=detect"
```

Idempotency:

- Same `external_model_key + sha256` returns the same `model_ref` / `version_id`.
- Different sha256 under the same key creates the next version.

Status:

```bash
curl "<BASE_URL>?mode=yolo_model_status&model_ref=yolo:natureweb:training-result-47:v1" \
  -H "Authorization: Bearer <TOKEN>"
```

GPU readiness should use `gpu.service_available=true && warm_state=hot`. If a DB slot is still marked hot but `yolo-gpu0` is stopped, status keeps `gpu.actual_state=hot` for traceability but returns top-level `warm_state=cold` with `gpu.blocked_reason=gpu_service_unavailable`.

Assign GPU slot:

```bash
curl -X POST "<BASE_URL>?mode=yolo_model_assign_gpu" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "model_ref=yolo:natureweb:training-result-47:v1" \
  -F "slot_no=1"
```

Unassign GPU slot:

```bash
curl -X POST "<BASE_URL>?mode=yolo_model_unassign_gpu" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "model_ref=yolo:natureweb:training-result-47:v1"
```

Predict with registered model:

```bash
curl -X POST "<BASE_URL>?mode=yolo_predict" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@sample.jpg" \
  -F "model_ref=yolo:natureweb:training-result-47:v1" \
  -F "execution_policy=auto"
```

Predict response includes:

- `model_ref`
- `version_id` / `model_version_id`
- `device_used`
- `fallback_reason`
- `detections`

`execution_policy`:

- `auto`: prefer hot GPU slot, fallback to CPU when GPU is not ready.
- `cpu_only`: force CPU.
- `gpu_only`: require hot GPU slot or return `gpu_not_ready`.

Client must not send host paths, server artifact paths, `slot_no`, or `device` to `yolo_predict`; only `model_ref` selects the model.

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

Status: L5 benchmark ready. 預設仍回 mock JSON；表單加 `real_inference=1` 時執行單張圖片 real segmentation smoke。mask metadata 會回 `bbox`、`score`、`confidence`、`label_name`；`output_format=metadata|polygon|rle|both` 可選 legacy 多 contour `polygon`、前端友善 `polygons[].outer/holes` 或 raw uncompressed row-major RLE。

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

Repair missing translations:

```bash
curl -X POST "<BASE_URL>?mode=task_submit" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "task_type=docparser_repair_translation" \
  -F "task_id=11" \
  -F "block_ids=p12-b4,p14-b8"
```

Use block IDs from `quality_report.missing_translation_blocks` or `missing_translation_block_ids_by_type`. Repair only retranslates selected DocIR blocks, rewrites the original task artifacts, and skips blocks that already have valid translations.

## Async Audio Pack Tasks

All audio jobs use `POST multipart/form-data` and a Bearer token with the requested mode. `audio_cleanup` and `speech_transcribe` require exactly one source: one upload or one owned `source_artifact_id`. `voice_generate` is text-only and rejects both source forms. The server resolves Pack/job/version; clients never send Pack paths, Docker controls, or callback URLs. A submission returns `task_id`, `status_url`, `result_url`, `log_url`, `cancel_url`, and `artifact_url_template`.

### `mode=audio_cleanup`

```bash
curl -X POST "<BASE_URL>?mode=audio_cleanup" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "source=@sample.wav" \
  -F "operation=separate_and_enhance" \
  -F "demucs_model=balanced" \
  -F "callback_target=myai"
```

### `mode=speech_transcribe`

```bash
curl -X POST "<BASE_URL>?mode=speech_transcribe" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "source=@sample.wav" \
  -F "model=large_v3" \
  -F "language=auto" \
  -F "output_srt=1" \
  -F "callback_target=myai"
```

To chain an owned cleanup result instead of uploading it again, omit `source` and send the artifact ID. The source must be an allowed, unexpired artifact owned by the same API member.

```bash
curl -X POST "<BASE_URL>?mode=speech_transcribe" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "source_artifact_id=123" \
  -F "language=auto"
```

The client must send a normal `Content-Length` header for artifact-only requests; curl supplies it for this multipart request.

### `mode=voice_generate`

```bash
curl -X POST "<BASE_URL>?mode=voice_generate" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "text=請檢查 RC Valve 間隙。" \
  -F "mode=design" \
  -F "voice_prompt=沉穩的台灣男性技師" \
  -F "seed=42" \
  -F "callback_target=myai"
```

`mode=clone` replaces the design prompt with one owned managed `voice_profile_id`; it never accepts an uploaded reference file or `source_artifact_id`.

`mode=clone` uses one managed `voice_profile_id`; it does not accept a server path. Upload byte limits come from the resolved Pack. Use the async modes for work larger than a sync diagnostic sample.

### Callback, polling, download, and ACK

An operator registers the HTTPS callback target and secret out of band; a submitter passes only its pre-registered `callback_target` alias. Terminal events are `task.completed` and `task.failed`. Verify the exact raw JSON body with the target secret:

```text
X-AIHub-Event: task.completed
X-AIHub-Delivery: <delivery_id>
X-AIHub-Timestamp: <unix timestamp>
X-AIHub-Signature: sha256=<hex HMAC-SHA256(raw_body, callback_secret)>
```

Deduplicate `X-AIHub-Delivery` and return HTTP 2xx for a repeat. Callbacks retry; polling is the fallback and diagnostic path:

```bash
curl -H "Authorization: Bearer <TOKEN>" "<BASE_URL>?mode=task_status&task_id=<TASK_ID>"
curl -H "Authorization: Bearer <TOKEN>" "<BASE_URL>?mode=task_result&task_id=<TASK_ID>"
curl -H "Authorization: Bearer <TOKEN>" -OJ "<BASE_URL>?mode=artifact&artifact_id=<ARTIFACT_ID>"
curl -X POST "<BASE_URL>?mode=task_artifacts_ack" -H "Authorization: Bearer <TOKEN>" -F "task_id=<TASK_ID>" -F "artifact_id=<ARTIFACT_ID>"
```

ACK records receipt and can shorten retention, but never deletes a file immediately. Defaults are one hour for failed partial uploads, 24 hours for workspace/temporary files, seven days for source media, 30 days for result artifacts, and 180 days for audit metadata. A purged artifact returns `410 Gone`.

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
