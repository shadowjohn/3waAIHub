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

Cluster Router 回傳的 `task_id` 是 opaque string，必須原樣保存，禁止轉成整數。Native `api.php` task IDs are numeric；兩者屬於不同命名空間，不可混用。非同步音訊服務 `speech_transcribe`、`audio_cleanup`、`voice_generate` 的流程固定為 submit → status → result → artifact download → ACK；實際可用 mode 仍以 Router 即時 manifest 的 `services` 為準。

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

## POST Taiwan Address

Status: trusted-upstream adapter. Install the `taiwan-address` Pack, set its service setting `TWADDR_UPSTREAM_URL` to the existing trusted `api.php`, then restart the service. Clients select a fixed `operation`; they cannot provide an upstream URL.

```bash
curl -X POST "<BASE_URL>?mode=taiwan_address" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "operation": "getAddress_XY",
    "address": "台中市南區新和街1號"
  }'
```

Alias and POI lookup uses the same endpoint:

```json
{
  "operation": "searchAlias",
  "q": "國網中心",
  "limit": 1
}
```

Do not convert aliases, POI, fallback, or approximate results into official addresses. Preserve `result_label`, `quality_flag`, `include_in_coverage`, `geo_check_status`, and `geo_warning_code` from returned items.

Contract:

- Method: `POST`
- Content-Type: `application/json`
- Operations: `cities`, `autocomplete`, `searchAddress`, `searchAlias`, `searchAll`, `getAddress_XY`, `nearestAddress`, `bboxAddress`, `searchPoi`, and the OpenData search operations
- Errors: `operation_not_allowed`, `upstream_not_configured`, `upstream_unavailable`, `upstream_invalid_response`, `gateway_timeout`
- Windows Core can manage this Pack, but its Docker adapter must run through WSL/Linux runtime.

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

## POST PaliGemma 2 Vision

Status: GPU-only, synchronous Vision inference. `paligemma2` only appears in a
station or Router's **live manifest** after that host has completed the pinned
CUDA acceptance. Do not cache the static Pack manifest as proof that a Router
can currently route this mode.

Local station:

```bash
curl -X POST "<BASE_URL>?mode=paligemma2" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@sample.png" \
  -F "prompt=Describe this image in Traditional Chinese." \
  -F "task=caption" \
  -F "real_inference=1"
```

Router entry (use this endpoint for customer traffic, not a child URL):

```bash
curl -X POST "<CLUSTER_ROUTER_URL>/cluster_api.php?mode=paligemma2" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@sample.png" \
  -F "prompt=What is visible in this image?" \
  -F "task=general" \
  -F "real_inference=1"
```

Contract:

- Method: `POST`; Content-Type: `multipart/form-data`
- Input: `image` file, maximum `50 MiB`; legacy `file` is accepted as an upload alias
- Tasks: `task=caption` or `task=general` only
- Real inference: the host setting `PALIGEMMA2_REAL_INFERENCE=1` **and** request `real_inference=1` are both required
- Optional input: `prompt`, `temperature`, `max_tokens` (`16`–`128`)
- Response: direct JSON with `ok`, `mock=false`, `runtime_level`, `model`, `text`, `elapsed_ms`; this is synchronous and has no Router `task_id`, artifact, or ACK workflow
- Errors: `bad_request`, `bad_image`, `file_too_large`, `unsupported_task`, `gpu_unavailable`, `model_not_provisioned`, `model_manifest_invalid`, `inference_failed`, `runtime_not_ready`, `gateway_timeout`

Discover availability from `cluster_manifest.json.php` before choosing the
mode. A missing `paligemma2` entry means no eligible accepted station is
currently published; it does not reveal which node is unavailable.

## Manual Vision DocVQA

`manual_vision` 是 English DocVQA 問答能力，不是 literal OCR。它只接受 `operation`、`image`、`question` 三個 multipart 欄位；圖片必須是 PNG/JPEG、最大 50 MiB，問題必須是 1–400 bytes 的 printable ASCII，且至少含一個英文字母。呼叫端不能指定模型、revision、Profile、路徑、prompt、裝置或生成參數。Hub 會在內部固定使用 `answer en {question}`，答案上限由伺服器管理（目前 64 tokens）。需要逐字文字、表格數字與座標時仍用 PP-OCRv5；PaliGemma2 與 `pdf2html` 是獨立能力。

Native Hub：

```bash
curl -fsS -X POST "<HUB_BASE_URL>/api.php?mode=manual_vision" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "operation=docvqa" \
  -F "image=@manual-page.png;type=image/png" \
  -F "question=What is the shutdown temperature?"
```

成功回應只有八個欄位：

```json
{
  "ok": true,
  "mode": "manual_vision",
  "operation": "docvqa",
  "answer": "85 °C",
  "answer_language": "en",
  "contract_revision": 1,
  "elapsed_ms": 412,
  "request_id": "req_0123456789abcdef0123456789abcdef"
}
```

`request_id` 是服務回應識別碼；HTTP `X-3waAIHub-Request-Id` 是這次 Native Gateway 請求的識別碼，兩者應分開保存。穩定錯誤碼為 `bad_request`、`unsupported_operation`、`bad_image`、`file_too_large`、`missing_token`、`token_mode_not_allowed`、`gpu_unavailable`、`model_not_provisioned`、`model_manifest_invalid`、`runtime_not_ready`、`inference_failed`、`gateway_timeout`。

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

## Gemma 4 Audio Input

Status: PhaseL-1E audio asset reuse. `mode=audio` 可直接送短 WAV，也可先 `audio_upload` 取得 `audio_id` 後反覆追問；不建立 session。Gemma4 Audio 是實驗性音訊理解，非正式 ASR；逐字稿或長音訊請使用 Whisper ASR Pack。

```bash
curl -X POST "<BASE_URL>?mode=audio_upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "audio=@sample.wav"

curl -X POST "<BASE_URL>?mode=audio" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "audio_id=aud_..." \
  -F "operation=understand" \
  -F "text=這段錄音的重點是什麼？" \
  -F "max_tokens=512" \
  -F "real_inference=1"
```

Contract:

- Method: `POST`
- Content-Type: `multipart/form-data`
- Upload input: `mode=audio_upload` field `audio` WAV file
- Ask input: `mode=audio` field `audio` WAV file or `audio_id`, optional `operation=understand|transcribe|summarize`, `text`, `max_tokens`, `real_inference`
- Limits: WAV only, 16kHz mono, <= 30 seconds, <= 16MB
- Asset TTL: 7 days
- Upload output keys: `ok`, `audio_id`, `mime`, `size`, `duration_ms`, `sample_rate`, `channels`, `expires_at`
- Required output keys: `ok`, `mock`, `runtime_level`, `model`, `operation`, `answer`, `transcript`, `summary`, `tags`, `warnings`, `audio`, `usage`, `elapsed_ms`
- Errors: `file_required`, `payload_too_large`, `invalid_audio`, `unsupported_audio_format`, `audio_too_long`, `audio_not_found`, `model_not_ready`, `audio_failed`

Benchmark:

```bash
php scripts/benchmark.php --pack=llm-gemma4-12b --case=gemma4_mock_audio
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_audio_transcribe_zh
php scripts/benchmark.php --service=gemma4-main --case=gemma4_real_audio_understand
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
  -F "execution_policy=auto" \
  -F "imgsz=800" \
  -F "max_det=300"
```

Predict response includes:

- `model_ref`
- `version_id` / `model_version_id`
- `device_used`
- `fallback_reason`
- `detections`

Optional predict controls:

- `conf`
- `iou`
- `imgsz`: defaults to registry metadata when available, otherwise `640`
- `max_det`: defaults to `300`

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

Status: L5 benchmark ready. 預設仍回 mock JSON；表單加 `real_inference=1` 時執行單張圖片 real segmentation smoke。mask metadata 會回 `bbox`、`score`、`confidence`、`label_name`；`output_format=metadata|polygon|rle|both|png` 可選 legacy 多 contour `polygon`、前端友善 `polygons[].outer/holes`、raw uncompressed row-major RLE，或直接回白色不透明／透明背景的 `image/png`。

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

Points labels: `1` = 選取目標，`0` = 排除目標；至少需要一個 `1`，可傳多個正負點。

PNG mask output:

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "prompt_type=points" \
  -F 'points_json={"points":[[320,240]],"labels":[1]}' \
  -F "real_inference=1" \
  -F "output_format=png" \
  -o mask.png
```

Guidance mask prompt:

`guidance_mask` 必須是與原圖同尺寸的 PNG；非透明像素代表要選取的目標，透明像素為中立，不代表負提示。

```bash
curl -X POST "<BASE_URL>?mode=sam3" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@packs/sam3/demo/camera_cat.png" \
  -F "guidance_mask=@guidance.png" \
  -F "prompt_type=guidance_mask" \
  -F "real_inference=1" \
  -F "output_format=png" \
  -o mask.png
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
  -F "translation_required=1" \
  -F "translation_policy=auto"
```

`translation_policy=auto` is the default. When `target_language=zh-TW`, DocParser skips blocks that already look like target-language Chinese and only calls TranslateGemma for non-Chinese blocks. Use `translation_policy=always` only when every translatable block must be machine-translated; use `translation_policy=never` to disable translation.

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

## Edge TTS 聲線清單與非同步合成

狀態：`edge-tts` 是 CPU-only 的實驗性 L5 Pack。管理員須先安裝、啟用並使
服務為 `running`，再把 `edge_tts` mode 授權給 token。Hub 的公開路徑是
`GET /api.php?mode=edge_tts` 與 `POST /api.php?mode=edge_tts`；Cluster 使用相同
介面合約的 `cluster_api.php`。以下 `<BASE_URL>` 是完整端點，例如
`https://hub.example/3waAIHub/api.php`；token 一律使用範例值。

取得認證後可用的聲線清單與試聽 URL：

```bash
curl --fail --silent --show-error \
  -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=edge_tts"
```

回應的每個聲線項目都有 `id`、`display_name`、`locale`、`gender`、`memo`、
`demo_text`、`demo_url`。`demo_url` 是相對於 `<BASE_URL>` 的受認證 MP3 串流 URL；
只能在該聲線完成重新生成與驗證後出現。完整 14 個台灣、中國與香港聲線，
以及每個 `memo`，見
[`packs/edge-tts/service/voice_catalog.json`](../packs/edge-tts/service/voice_catalog.json)
與 [Pack 說明](../packs/edge-tts/README.md#聲線清單與試聽)。

只可把 list 回傳的相對 `demo_url` 接到同一個 `<BASE_URL>`，不能替換為任意 URL；
試聽仍使用同一個 token：

```bash
# 範例為 list 回傳的相對 demo_url：?mode=edge_tts&voice=zh-TW-HsiaoChenNeural
curl --fail --silent --show-error \
  -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=edge_tts&voice=zh-TW-HsiaoChenNeural"
```

demo 回應為 `audio/mpeg`，並帶 `Cache-Control: private, no-store`。`memo` 是靜態
catalogue 的描述性中繼資料，不是 Edge 上游的聲線風格或韻律控制。

提交非同步合成時以 `application/x-www-form-urlencoded`，不要送伺服器路徑或上游 URL：

```bash
curl --fail --silent --show-error --request POST \
  -H "Authorization: Bearer <TOKEN>" \
  --data-urlencode 'text=這是非機密的短句。' \
  --data-urlencode 'voice=zh-TW-HsiaoChenNeural' \
  --data 'include_subtitles=true' \
  "<BASE_URL>?mode=edge_tts"
```

這是非同步工作：以 `task_status` 輪詢，成功後讀 `task_result`，優先使用每個 artifact 的
`artifact_url` 取得產物；只有舊版回應才以 `artifact_url_template` 與 `artifact_id` 組 URL。下載仍需
`Authorization: Bearer <TOKEN>`，不可使用回應以外的主機檔案路徑。完成後以 `task_artifacts_ack` ACK。當
`include_subtitles=true`，task result 的五個產物是 `generated_audio`
（`audio/mpeg`）、`synthesis_metadata`（`application/json`）、`subtitle_vtt`
（標準 `text/vtt`；相容 `text/plain`）、`subtitle_srt`（標準
`application/x-subrip`；相容 `text/plain`、`text/x-subrip`、`text/srt`）、
`speech_timeline`（`application/json`）；字幕與 timeline 會包含提交文字。

L5 營運準備條件除 service `installed/enabled/running` 外，還要求
`edge_tts_async_complete` 真實外部驗收紀錄為 PASS。
`admin/pack_readiness.php` 只檢查 `L5 contract` 與已保存的 `benchmark`；它不會證明
service 已 `installed/enabled/running`，須另在 `admin/packs.php` 確認。一般
benchmark 對 `external_acceptance` 會以 `external_acceptance_requires_script` 在
任何網路請求或 task 建立前拒絕；管理員必須刻意依
[真實驗收操作說明](operations/edge-tts-real-smoke.md) 使用 CLI。

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
  -F "model=small" \
  -F "language=auto" \
  -F "output_srt=1" \
  -F "priority=50" \
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

`priority` is optional and ranges from 0 through 100. Higher-priority work is claimed first within the same queue; retries preserve it. It never authorizes Hub to stop a resident service or an external GPU process.

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

`task_status` always returns `status`, integer `progress`, and a displayable `message`. While waiting for GPU capacity it may also return `waiting_reason`, `required_vram_mb`, `free_vram_mb`, `retry_after_seconds`, and a bounded `gpu_processes` list. `retry_after_seconds` estimates the next scheduler check, not completion time. See [Whisper ASR resident operation](operations/whisper-asr-resident.md) for the `wait`, resident CPU, and Cluster routing policies.

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
