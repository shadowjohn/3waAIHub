# 3waAIHub Client Quickstart

Public Docs 是說明書，Bearer Token 才是鑰匙。

建議交付流程：

1. 系統管理員建立客戶。
2. 建立或交付 API token。
3. 開啟 `public_api_docs.php` 查看人類可讀文件。
4. 開啟 `api_manifest.json.php` 給 agent / client generator 讀 contract。
5. 執行 `scripts/api_smoke_client.php` 驗證 token、mode、gateway、runtime 都可用。
6. 外部系統複製 curl / PHP / JS fetch 範例開始介接。

非同步文件任務流程：

1. `POST multipart/form-data` 上傳 PDF，取得 `task_id`。
2. 輪詢 `status_url`。
3. 成功後讀 `result_url`。
4. 從 result 裡的 `artifact_summary.*.artifact_id` 組 `artifact_url_template` 下載 HTML / Markdown / DocIR / RAG chunks。
5. 圖片 crop 從 `artifact_summary.figure_assets.items[].artifact_id` 逐張下載。

查詢端點：

```text
<BASE_URL>?mode=task_status&task_id=<TASK_ID>
<BASE_URL>?mode=task_result&task_id=<TASK_ID>
<BASE_URL>?mode=task_log&task_id=<TASK_ID>
<BASE_URL>?mode=artifact&artifact_id=<ARTIFACT_ID>
```

## Base URL

公開文件與 Playground 會依目前網頁 host 產生 URL，例如：

```text
https://nature.focusit.tw/3waAIHub/api.php
```

文件裡用：

```text
<BASE_URL>
```

代表你的實際 API endpoint。

## Auth

所有外部 API request 使用 Bearer Token：

```text
Authorization: Bearer <TOKEN>
```

不要把真 token 寫進文件、前端 repo 或 log。

## Smoke Client

先用 mock / lightweight path 驗證介接：

```bash
php scripts/api_smoke_client.php \
  --base-url=https://nature.focusit.tw/3waAIHub/api.php \
  --token=<TOKEN>
```

指定 mode：

```bash
php scripts/api_smoke_client.php \
  --base-url=https://nature.focusit.tw/3waAIHub/api.php \
  --token=<TOKEN> \
  --modes=hello,ocr,yolo,translate,sam3,chat,audio
```

`photo` 需要先 `photo_upload` 取得 `image_id`，再呼叫 `photo`；請用下方 curl 兩步驟範例驗證。

預設 `real_inference=false`。要測真推論時再加：

```bash
php scripts/api_smoke_client.php \
  --base-url=https://nature.focusit.tw/3waAIHub/api.php \
  --token=<TOKEN> \
  --modes=ocr,yolo,sam3,translate,chat,audio \
  --real
```

## Minimal Examples

### curl

```bash
curl -H "Authorization: Bearer <TOKEN>" \
  "<BASE_URL>?mode=hello"
```

### PHP

```php
$ch = curl_init('<BASE_URL>?mode=hello');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer <TOKEN>'],
]);
echo curl_exec($ch);
```

### JS fetch

```js
const res = await fetch('<BASE_URL>?mode=hello', {
  headers: { Authorization: 'Bearer <TOKEN>' }
});
console.log(await res.json());
```

## Mode Contracts

### `mode=hello`

- request contract: `GET`
- response contract: JSON with `ok`, `service`, `message`
- error contract: `missing_token`, `invalid_token`, `token_mode_denied`, `unknown_mode`

### `mode=ocr`

- request contract: `POST multipart/form-data`, field `image` or legacy alias `file`, optional `real_inference`
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `text`, `blocks`
- error contract: `bad_request`, `file_too_large`, `runtime_not_ready`, `inference_failed`, `gateway_timeout`

### `mode=yolo`

- request contract: `POST multipart/form-data`, field `image`, optional `real_inference`
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `detections`, `elapsed_ms`
- error contract: `bad_request`, `file_too_large`, `runtime_not_ready`, `inference_failed`, `gateway_timeout`

### `mode=translate`

- request contract: `POST application/json`, fields `source_lang`, `target_lang`, `text`, optional `real_inference`
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `text`, `source_lang`, `target_lang`, `elapsed_ms`
- error contract: `bad_request`, `runtime_not_ready`, `inference_failed`, `gateway_timeout`

### `mode=chat`

- request contract: `POST application/json`, fields `text`, optional `system_prompt`, `temperature`, `max_tokens`, `enable_thinking`, `real_inference`
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `text`, `usage`, `elapsed_ms`
- error contract: `bad_request`, `input_too_long`, `vllm_unavailable`, `model_not_present`, `vllm_timeout`, `vllm_bad_response`, `chat_failed`
- first slice: text-only, non-streaming JSON. Vision / streaming / tool calling are future phases.

### `mode=photo_upload` + `mode=photo`

先上傳一次圖片取得 `image_id`：

```bash
curl -X POST "<BASE_URL>?mode=photo_upload" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "image=@example.jpg"
```

再用 `image_id` 重複提問：

```bash
curl -X POST "<BASE_URL>?mode=photo" \
  -H "Authorization: Bearer <TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"image_id":"img_...","text":"這張圖裡有什麼？","max_tokens":256,"real_inference":true}'
```

- request contract: `photo_upload` 使用 `POST multipart/form-data` 欄位 `image`；`photo` 使用 `POST application/json` 欄位 `image_id`, `text`, optional `max_tokens`, `real_inference`
- response contract: `photo_upload` 回 JSON `ok`, `image_id`；`photo` 回 JSON `ok`, `mock`, `runtime_level`, `model`, `image_id`, `answer`, `caption`, `tags`, `usage`, `elapsed_ms`
- error contract: `image_id_required`, `text_required`, `photo_forbidden`, `model_not_ready`, `vision_timeout`, `vision_bad_response`, `vision_failed`
- no server-side session；需要前文時請放在 `text`

### `mode=audio_upload` + `mode=audio`

Gemma4 audio input 適合短音訊理解、摘要或輔助轉錄；可直接送 WAV，也可先上傳一次取得 `audio_id` 後反覆追問。長音訊 ASR 請使用 Whisper ASR Pack。

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

- upload contract: `POST multipart/form-data`, field `audio`, returns `audio_id`
- request contract: `POST multipart/form-data`, field `audio` or `audio_id`; optional `operation=understand|transcribe|summarize`, `text`, `max_tokens`, `real_inference`
- audio limits: WAV only, 16kHz mono, <= 30 seconds, <= 16MB
- audio asset TTL: 7 days
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `operation`, `answer`, `transcript`, `summary`, `tags`, `audio`, `usage`, `elapsed_ms`
- error contract: `file_required`, `payload_too_large`, `invalid_audio`, `unsupported_audio_format`, `audio_too_long`, `audio_not_found`, `model_not_ready`, `audio_failed`

### `mode=sam3`

- request contract: `POST multipart/form-data`, field `image`, optional `prompt_type`, `points_json`, `text`, `output_format`, `real_inference`
- response contract: JSON with `ok`, `mock`, `runtime_level`, `model`, `masks`, `prompt_type`, `elapsed_ms`; each mask includes `bbox`, `score`, `confidence`, `label_name`, and optional legacy `polygon` plus `polygons[].outer/holes`
- error contract: `bad_request`, `model_not_present`, `invalid_prompt`, `inference_failed`, `inference_timeout`

### `mode=docparser`

- status: L5 benchmark ready
- request contract: `POST multipart/form-data`, field `file`, PDF only
- response contract: JSON with `ok`, `task_id`, `status`, `status_url`, `result_url`, `log_url`, `cancel_url`, `artifact_url_template`
- result contract: `task_result` returns artifact summary for `reader_html`, `bilingual_html`, `markdown`, `docir`, `toc`, `rag_chunks`, `quality_report`, `manifest`
- figure contract: `artifact_summary.figure_assets.items[]` returns `figure_id`, `block_id`, `page`, `bbox`, `caption`, `asset_path`, `artifact_id`, `bytes`
- error contract: `file_required`, `unsupported_file_type`, `invalid_pdf_file`, `missing_token`, `token_mode_not_allowed`
- cancel contract: `POST task_cancel` cancels queued tasks immediately; running `docparser_parse` tasks stop cooperatively at the next worker checkpoint.
- repair contract: `POST task_submit` with `task_type=docparser_repair_translation`, `task_id`, and comma-separated `block_ids` from `quality_report.missing_translation_blocks`.

Repair missing translations:

```bash
curl -X POST "<BASE_URL>?mode=task_submit" \
  -H "Authorization: Bearer <TOKEN>" \
  -F "task_type=docparser_repair_translation" \
  -F "task_id=11" \
  -F "block_ids=p12-b4,p14-b8"
```

`docparser_repair_translation` rewrites the original task artifacts after repair. It only retranslates selected DocIR blocks and does not rerun OCR, layout parsing, image crops, or figure extraction. Already translated blocks are skipped.

## Async Audio Delivery

Use `audio_cleanup`, `speech_transcribe`, and `voice_generate` for production audio. Cleanup and transcription submit exactly one `source=@file` or an owned `source_artifact_id`. Voice generation submits text plus allowlisted design controls or one managed `voice_profile_id`; it rejects uploads and `source_artifact_id`. Add `callback_target=<registered-alias>` only when a trusted operator has registered that alias. The full curl examples and field allowlists are in [API examples](api_examples.md#async-audio-pack-tasks).

The initial response is asynchronous. Treat callbacks as an optimization, not the only completion path: use `task_status` and `task_result` as the polling fallback, then download every listed `artifact_id` through `artifact`. A received artifact can be acknowledged with `task_artifacts_ack`; ACK does not delete it immediately.

Verify callback HMAC against the exact raw request body before JSON parsing. `X-AIHub-Signature` is `sha256=` plus HMAC-SHA256 of the raw body using the registered target secret. Reject invalid signatures, deduplicate `X-AIHub-Delivery`, and return 2xx for an already processed delivery.

`asr` and `tts` are diagnostic sync modes only: 30 seconds maximum, Pack upload limit, no callback, no artifact chaining, and one actual GPU inference at a time. `async_required` names `speech_transcribe` or `voice_generate`; `sync_busy` means the shared slot is leased.

## Debug

API 錯誤回應通常會有 `request_id`。回報問題時帶：

- `request_id`
- `mode`
- HTTP status
- 呼叫時間
- 來源 IP

管理者可到後台 Log Explorer 追 API 記錄。
